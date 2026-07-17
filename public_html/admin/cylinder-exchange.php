<?php
$page_title = "Cylinder Exchange Settlement";
$active_menu = "exchange";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'inventory-utils.php';
runConsumerCylinderMigrations($pdo);

$message = '';
$error = '';

// Handle Exchange Settlement Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_exchange') {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $return_serials = array_filter(array_map('trim', $_POST['return_serials'] ?? []));
    $return_damage_amounts = $_POST['return_damage_amount'] ?? [];
    $return_damage_descs = $_POST['return_damage_desc'] ?? [];
    $give_back_serials = $_POST['give_back_serials'] ?? [];
    $notes = trim($_POST['notes'] ?? '');
    $tx_date_raw = $_POST['tx_date'] ?? date('Y-m-d\TH:i');
    $tx_date = str_replace('T', ' ', $tx_date_raw) . ':00';

    if ($customer_id <= 0) {
        $error = "Please select a valid customer.";
    } elseif (empty($return_serials) && empty($give_back_serials)) {
        $error = "Please enter at least one cylinder serial to return or give back.";
    } else {
        try {
            $ledger_group_id = generateLedgerGroupId();
            $pdo->beginTransaction();

            $cust_stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $cust_stmt->execute([$customer_id]);
            $customer = $cust_stmt->fetch();
            if (!$customer) {
                throw new Exception("Customer not found.");
            }

            $return_count = 0;
            $giveback_count = 0;
            $settled_count = 0;
            $total_damage = 0.00;
            $return_serials_list = [];
            $giveback_serials_list = [];
            $settled_serials_list = [];

            // ── Customer returns our cylinders ──
            $idx = 0;
            foreach ($return_serials as $serial) {
                if (empty($serial)) continue;
                $damage_amt = isset($return_damage_amounts[$idx]) ? floatval($return_damage_amounts[$idx]) : 0.00;
                $damage_desc = isset($return_damage_descs[$idx]) ? trim($return_damage_descs[$idx]) : '';

                $chk = $pdo->prepare("SELECT id, status, current_customer_id, ownership_type, original_owner_customer_id, gas_type_id, size_capacity, current_partner_id FROM cylinders WHERE serial_number = ?");
                $chk->execute([$serial]);
                $cyl = $chk->fetch();

                if ($cyl) {
                    $cyl_id = intval($cyl['id']);

                    if ($cyl['ownership_type'] === 'consumer_owned' && $cyl['original_owner_customer_id'] == $customer_id) {
                        // This is actually the customer's own cylinder — treat as give-back instead (remove from inventory)
                        $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?")->execute([$cyl_id]);
                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'consumer_give_back', "Exchange settlement: customer returned own cylinder (auto-settled). $notes", $ledger_group_id);
                        $settled_count++;
                        $settled_serials_list[] = $serial;
                    } elseif ($cyl['ownership_type'] === 'consumer_owned' && $cyl['original_owner_customer_id'] != $customer_id) {
                        // Different customer's cylinder — now this customer holds it
                        $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$customer_id, $cyl_id]);
                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'consumer_return', "Exchange settlement: consumer-owned cylinder transferred to customer. $notes", $ledger_group_id);
                        $giveback_count++;
                        $giveback_serials_list[] = $serial;
                    } else {
                        // Company-owned or partner-owned cylinder returned by customer
                        $damage_note = '';
                        if ($damage_amt > 0) {
                            $damage_note = " [Damage: ₹$damage_amt" . ($damage_desc ? " - $damage_desc" : "") . "]";
                            $total_damage += $damage_amt;
                        }
                        $pdo->prepare("UPDATE cylinders SET status = 'empty', current_customer_id = NULL WHERE id = ?")->execute([$cyl_id]);
                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'return_from_customer', "Exchange settlement: returned empty cylinder serial $serial.$damage_note $notes", $ledger_group_id);
                        $return_count++;
                        $return_serials_list[] = $serial . ($damage_amt > 0 ? " (damage:₹$damage_amt)" : "");
                        
                        // If BR cylinder has damage, sync to partner
                        if ($cyl['ownership_type'] === 'partner_owned' && $damage_amt > 0) {
                            $partner_id = $cyl['current_partner_id'] ?: null;
                            if ($partner_id) {
                                $pnote = "Damage: " . ($damage_desc ?: "Cylinder damaged") . " (from exchange settlement)";
                                $chk_tx = $pdo->prepare("SELECT id FROM partner_transactions WHERE partner_id = ? AND transaction_type = 'damage_from_exchange' AND DATE(transaction_date) = ? LIMIT 1");
                                $chk_tx->execute([$partner_id, $tx_date]);
                                $etx = $chk_tx->fetch();
                                $tx_id = $etx ? $etx['id'] : null;
                                if (!$tx_id) {
                                    $ins_tx = $pdo->prepare("INSERT INTO partner_transactions (partner_id, transaction_type, transaction_date, notes) VALUES (?, 'damage_from_exchange', ?, 'Auto-generated damage from cylinder exchange settlement')");
                                    $ins_tx->execute([$partner_id, $tx_date]);
                                    $tx_id = $pdo->lastInsertId();
                                }
                                $ins_item = $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after, damage_amount, payment_status, notes) VALUES (?, ?, ?, ?, ?, 'with_customer', 'empty', ?, 'pending', ?)");
                                $ins_item->execute([$tx_id, $cyl_id, $serial, $cyl['gas_type_id'], $cyl['size_capacity'], $damage_amt, $pnote]);
                            }
                        }
                    }

                    // Decrement active count if it was held by this customer
                    if ($cyl['current_customer_id'] == $customer_id) {
                        $pdo->prepare("UPDATE customers SET active_cylinders_count = GREATEST(0, active_cylinders_count - 1) WHERE id = ?")->execute([$customer_id]);
                    }
                } else {
                    // New cylinder — register as consumer-owned empty
                    $ins = $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, ownership_type, original_owner_customer_id) VALUES (?, 0, '', 'empty', 'consumer_owned', ?)");
                    $ins->execute([$serial, $customer_id]);
                    $new_id = $pdo->lastInsertId();
                    logCylinderTransaction($pdo, $new_id, $customer_id, null, 'consumer_return', "Exchange settlement: new consumer-owned cylinder registered. $notes", $ledger_group_id);
                    $return_count++;
                    $return_serials_list[] = $serial . " (new CON)";
                }
                $idx++;
            }

            // ── We give back customer's cylinders ──
            foreach ($give_back_serials as $serial) {
                if (empty($serial)) continue;

                $chk = $pdo->prepare("SELECT id, status, current_customer_id, ownership_type, original_owner_customer_id FROM cylinders WHERE serial_number = ?");
                $chk->execute([$serial]);
                $cyl = $chk->fetch();

                if ($cyl) {
                    $cyl_id = intval($cyl['id']);

                    if ($cyl['ownership_type'] === 'consumer_owned' && $cyl['original_owner_customer_id'] == $customer_id) {
                        // Customer gets their own cylinder back — SETTLED (remove from inventory)
                        $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?")->execute([$cyl_id]);
                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'consumer_give_back', "Exchange settlement: customer received own cylinder back. Exchange CLOSED. $notes", $ledger_group_id);
                        $settled_count++;
                        $settled_serials_list[] = $serial;
                    } elseif ($cyl['ownership_type'] === 'consumer_owned' && $cyl['original_owner_customer_id'] != $customer_id) {
                        // Different customer's cylinder — transfer to this customer
                        $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$customer_id, $cyl_id]);
                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'consumer_return', "Exchange settlement: consumer-owned cylinder transferred to customer. $notes", $ledger_group_id);
                        $giveback_count++;
                        $giveback_serials_list[] = $serial;
                        $pdo->prepare("UPDATE customers SET active_cylinders_count = active_cylinders_count + 1 WHERE id = ?")->execute([$customer_id]);
                    } else {
                        // Company-owned cylinder given to customer
                        $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$customer_id, $cyl_id]);
                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'issue_to_customer', "Exchange settlement: company cylinder issued to customer. $notes", $ledger_group_id);
                        $giveback_count++;
                        $giveback_serials_list[] = $serial;
                        $pdo->prepare("UPDATE customers SET active_cylinders_count = active_cylinders_count + 1 WHERE id = ?")->execute([$customer_id]);
                    }
                }
            }

            // Resync customer active count
            syncCustomerActiveCylinderCounts($pdo, $customer_id);

            // Build ledger group title
            $title_parts = [];
            if ($return_count > 0) {
                $title_parts[] = "Returned: " . implode(', ', array_slice($return_serials_list, 0, 5)) . ($return_count > 5 ? " (+" . ($return_count - 5) . " more)" : "");
            }
            if ($giveback_count > 0) {
                $title_parts[] = "Given: " . implode(', ', array_slice($giveback_serials_list, 0, 5)) . ($giveback_count > 5 ? " (+" . ($giveback_count - 5) . " more)" : "");
            }
            if ($settled_count > 0) {
                $title_parts[] = "Settled: " . implode(', ', array_slice($settled_serials_list, 0, 5)) . ($settled_count > 5 ? " (+" . ($settled_count - 5) . " more)" : "");
            }
            if ($total_damage > 0) {
                $title_parts[] = "Damage: ₹" . number_format($total_damage, 2);
            }
            $group_title = "Exchange Settlement — " . implode(' | ', $title_parts);

            $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'exchange_settlement', ?, ?, ?)")
                ->execute([$ledger_group_id, $customer_id, $group_title, $total_damage, $tx_date]);

            $pdo->commit();
            syncInventory($pdo);

            $summary = "Settled: $settled_count | Returned: $return_count | Given back: $giveback_count";
            $message = "Exchange settlement processed successfully! ($summary)";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Exchange failed: " . $e->getMessage();
        }
    }
}

// Fetch lists
$customers = [];
try {
    $customers = $pdo->query("SELECT * FROM customers WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

$gas_types = [];
try {
    $gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}
?>

<div style="margin-bottom: 2rem;">
    <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;">Cylinder Exchange Settlement</h2>
    <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;">Settle pending cylinder exchanges without creating a refill order. Supports partial returns, full settlement, and mutual empty cylinder returns.</p>
</div>

<?php if ($message): ?>
    <div class="alert-banner" style="background: var(--success-soft); color: var(--success); border-color: #a7f3d0; margin-bottom: 2rem;">
        <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-banner alert-warning" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5; margin-bottom: 2rem;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" id="exchangeForm"><?php csrfField(); ?>
    <input type="hidden" name="action" value="settle_exchange">

    <!-- Customer Selection -->
    <div class="admin-card" style="margin-bottom: 2rem;">
        <h3 class="card-title">1. Select Customer</h3>
        <div class="form-group" style="position: relative; max-width: 500px;">
            <select name="customer_id" id="customerSelect" class="form-control" required style="display:none;" onchange="loadExchangeData()" aria-label="Select customer">
                <option value="">-- Choose customer --</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['id']; ?>">
                        <?php echo htmlspecialchars($c['name']) . " (" . htmlspecialchars($c['mobile']) . ")"; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div id="customerCombobox" style="position:relative;">
                <input type="text" id="customerSearchInput" class="form-control" autocomplete="off"
                       placeholder="Type name or phone to search & select..."
                       aria-label="Search and select customer"
                       style="border-color: var(--admin-accent);padding-right:2.5rem;"
                       onfocus="showCustomerDropdown()" onkeyup="filterCustomerDropdown()">
                <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--admin-muted);pointer-events:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
                <div id="customerDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--admin-border);border-radius:10px;max-height:260px;overflow-y:auto;z-index:999;box-shadow:0 10px 25px rgba(0,0,0,0.08);margin-top:4px;">
                    <div id="customerDropdownList"></div>
                    <div id="customerDropdownEmpty" style="display:none;padding:1.5rem;text-align:center;color:var(--admin-muted);font-size:0.85rem;">No customers found</div>
                </div>
            </div>
            <input type="hidden" id="customerSelectedName" value="">
        </div>
    </div>

    <!-- Exchange Panels (hidden until customer selected) -->
    <div id="exchangePanels" style="display: none;">

        <!-- Exchange Balance Summary -->
        <div id="exchangeSummary" class="admin-card" style="margin-bottom: 2rem; background: #f0f9ff; border-color: #bae6fd;">
            <h3 class="card-title" style="color: #0369a1;">Exchange Balance Overview</h3>
            <div class="grid-exchange-summary">
                <div style="background: #fff; border: 1px solid #bae6fd; padding: 1rem; border-radius: 10px; text-align: center;">
                    <h5 style="font-size: 1.5rem; font-weight: 800; color: #0369a1;" id="summaryOurWithThem">0</h5>
                    <p style="font-size: 0.75rem; color: #0369a1; font-weight: 700; text-transform: uppercase; margin-top: 0.25rem;">Our Cylinders With Customer</p>
                </div>
                <div style="background: #fff; border: 1px solid #bae6fd; padding: 1rem; border-radius: 10px; text-align: center;">
                    <h5 style="font-size: 1.5rem; font-weight: 800; color: #0369a1;" id="summaryTheirWithUs">0</h5>
                    <p style="font-size: 0.75rem; color: #0369a1; font-weight: 700; text-transform: uppercase; margin-top: 0.25rem;">Their Cylinders In Our Inventory</p>
                </div>
                <div style="background: #fff; border: 1px solid #bae6fd; padding: 1rem; border-radius: 10px; text-align: center;">
                    <h5 style="font-size: 1.5rem; font-weight: 800; color: #0369a1;" id="summaryNetBalance">0</h5>
                    <p style="font-size: 0.75rem; color: #0369a1; font-weight: 700; text-transform: uppercase; margin-top: 0.25rem;">Net Exchange Balance</p>
                </div>
            </div>
        </div>

        <div class="grid-exchange-panels">

            <!-- LEFT: Customer returns our cylinders -->
            <div class="admin-card" style="margin: 0; border-top: 4px solid var(--success);">
                <h3 class="card-title" style="color: var(--success);">2. Cylinders Customer Returns to Us</h3>
                <p style="font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 1rem;">Enter serial numbers of <strong>our cylinders</strong> (or any cylinders) the customer is returning.</p>

                <!-- Quick-pick from customer's held cylinders -->
                <div id="quickPickReturn" style="display: none; margin-bottom: 1rem;">
                    <label class="form-label" style="font-weight: 700; font-size: 0.8rem;">Quick Pick — Cylinders Currently Held by This Customer:</label>
                    <div id="quickPickReturnList" style="display: flex; flex-direction: column; gap: 0.4rem; max-height: 150px; overflow-y: auto; margin-bottom: 0.75rem;"></div>
                </div>

                <div id="returnSerialsContainer" style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <div class="serial-row" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <input type="text" name="return_serials[]" class="form-control return-serial-input" placeholder="Enter cylinder serial number..." aria-label="Return cylinder serial number" style="flex:1;min-width:180px;" list="allCylindersDatalist">
                        <input type="number" name="return_damage_amount[]" class="form-control" style="width:90px;font-size:0.82rem;padding:0.35rem 0.65rem;height:36px;border-color:#fca5a5;" value="0" min="0" step="0.01" placeholder="Damge ₹" aria-label="Damage amount">
                        <input type="text" name="return_damage_desc[]" class="form-control" style="flex:0.6;min-width:120px;font-size:0.82rem;padding:0.35rem 0.65rem;height:36px;border-color:#fca5a5;" placeholder="Damage description" aria-label="Damage description">
                        <button type="button" class="btn-danger" style="padding: 0.4rem 0.7rem; font-size: 0.8rem; border-radius: 8px;flex-shrink:0;" onclick="removeSerialRow(this)" title="Remove">&times;</button>
                    </div>
                </div>
                <button type="button" class="btn-secondary" style="margin-top: 0.75rem; font-size: 0.8rem; padding: 0.4rem 1rem; border-radius: 8px;" onclick="addSerialRow('returnSerialsContainer', 'return_serials[]', 'Enter cylinder serial number...')">+ Add Another Serial</button>
            </div>

            <!-- RIGHT: We give back customer's cylinders -->
            <div class="admin-card" style="margin: 0; border-top: 4px solid var(--info);">
                <h3 class="card-title" style="color: var(--info);">3. Cylinders We Return to Customer</h3>
                <p style="font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 1rem;">Select <strong>customer-owned cylinders</strong> currently in our inventory to give back.</p>

                <div id="giveBackContainer" style="display: flex; flex-direction: column; gap: 0.4rem; max-height: 300px; overflow-y: auto;">
                    <p style="font-size: 0.85rem; color: var(--admin-muted); text-align: center; padding: 1rem 0;" id="giveBackEmpty">No customer-owned cylinders found in our inventory.</p>
                </div>
            </div>
        </div>

        <!-- Notes & Submit -->
        <div class="admin-card" style="margin-top: 2rem;">
            <div class="form-group">
                <label class="form-label">Internal Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Record reason, reference, or remarks..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Transaction Date</label>
                <input type="datetime-local" name="tx_date" class="form-control" style="max-width: 260px;" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem;">
                <button type="reset" class="btn-secondary" onclick="resetExchange()">Reset</button>
                <button type="submit" id="settleBtn" class="btn-primary" style="padding: 0.75rem 2.5rem; font-size: 1rem;">
                    Process Exchange Settlement
                </button>
            </div>
        </div>
    </div>
</form>

<datalist id="allCylindersDatalist"></datalist>

<script>
    const allCustomers = <?php echo json_encode($customers); ?>;

    // ── Customer Combobox ──
    let selectedCustomerId = '';

    function buildCustomerDropdown(filter) {
        const list = document.getElementById('customerDropdownList');
        const empty = document.getElementById('customerDropdownEmpty');
        const q = (filter || '').toLowerCase();
        list.innerHTML = '';
        let count = 0;
        allCustomers.forEach(c => {
            const text = (c.name + " " + c.mobile).toLowerCase();
            if (!q || text.includes(q)) {
                count++;
                const div = document.createElement('div');
                div.style.cssText = 'padding:0.65rem 1rem;cursor:pointer;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f1f5f9;transition:background 0.15s;';
                div.innerHTML = '<span style="font-weight:600;font-size:0.9rem;">' + c.name + '</span><span style="font-size:0.8rem;color:var(--admin-muted);">' + c.mobile + '</span>';
                div.addEventListener('mouseenter', function(){ this.style.background = '#f8fafc'; });
                div.addEventListener('mouseleave', function(){ this.style.background = ''; });
                div.addEventListener('click', function(){ selectCustomer(c.id, c.name); });
                list.appendChild(div);
            }
        });
        empty.style.display = count === 0 ? 'block' : 'none';
    }

    function selectCustomer(id, name) {
        selectedCustomerId = id;
        document.getElementById('customerSearchInput').value = name + ' \u2713';
        document.getElementById('customerSelectedName').value = name;
        document.getElementById('customerSelect').value = id;
        closeCustomerDropdown();
        loadExchangeData();
    }

    function showCustomerDropdown() {
        document.getElementById('customerDropdown').style.display = 'block';
        buildCustomerDropdown(document.getElementById('customerSearchInput').value.replace(' \u2713',''));
    }

    function closeCustomerDropdown() {
        document.getElementById('customerDropdown').style.display = 'none';
    }

    function filterCustomerDropdown() {
        let val = document.getElementById('customerSearchInput').value.replace(' \u2713','');
        document.getElementById('customerSearchInput').value = val;
        if (selectedCustomerId && document.getElementById('customerSelect').value) {
            document.getElementById('customerSelect').value = '';
            selectedCustomerId = '';
        }
        buildCustomerDropdown(val);
        document.getElementById('customerDropdown').style.display = 'block';
    }

    document.addEventListener('click', function(e) {
        const combo = document.getElementById('customerCombobox');
        if (combo && !combo.contains(e.target)) {
            closeCustomerDropdown();
        }
    });

    // ── Load Exchange Data ──
    function loadExchangeData() {
        const select = document.getElementById('customerSelect');
        const customerId = select.value;
        const panels = document.getElementById('exchangePanels');

        if (!customerId) {
            panels.style.display = 'none';
            return;
        }

        panels.style.display = 'block';

        // Fetch via AJAX for exchange data
        fetch('exchange-ajax.php?customer_id=' + customerId)
            .then(r => r.json())
            .then(data => {
                // Summary
                document.getElementById('summaryOurWithThem').textContent = data.our_with_them_count || 0;
                document.getElementById('summaryTheirWithUs').textContent = data.their_with_us_count || 0;
                const net = (data.our_with_them_count || 0) - (data.their_with_us_count || 0);
                document.getElementById('summaryNetBalance').textContent = (net > 0 ? '+' : '') + net;

                // Quick-pick return list (our cylinders with customer)
                const quickPickDiv = document.getElementById('quickPickReturn');
                const quickPickList = document.getElementById('quickPickReturnList');
                quickPickList.innerHTML = '';
                if (data.our_with_them && data.our_with_them.length > 0) {
                    quickPickDiv.style.display = 'block';
                    data.our_with_them.forEach(c => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.style.cssText = 'display:flex;align-items:center;gap:8px;padding:0.5rem 0.75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:0.8rem;cursor:pointer;text-align:left;width:100%;';
                        const tag1 = c.ownership_type === 'partner_owned' ? '<span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(251,191,36,0.3);" title="Partner: ' + (c.partner_name || 'Unknown') + '">BR</span>' : '<span style="background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(16,185,129,0.3);">OWN</span>';
                        btn.innerHTML = '<span style="font-weight:700;color:var(--admin-accent);font-family:monospace;">' + c.serial_number + tag1 + '</span><span style="color:var(--admin-muted);">' + (c.gas_name || '') + ' (' + c.size_capacity + ')</span><span style="margin-left:auto;font-size:0.7rem;color:var(--success);font-weight:700;">+ Add</span>';
                        btn.addEventListener('click', function() {
                            addSerialValue('returnSerialsContainer', 'return_serials[]', c.serial_number);
                        });
                        quickPickList.appendChild(btn);
                    });
                } else {
                    quickPickDiv.style.display = 'none';
                }

                // Give-back list (customer-owned cylinders in our inventory)
                const giveBackDiv = document.getElementById('giveBackContainer');
                giveBackDiv.innerHTML = '';
                if (data.their_with_us && data.their_with_us.length > 0) {
                    data.their_with_us.forEach(c => {
                        const label = document.createElement('label');
                        label.style.cssText = 'display:flex;align-items:center;gap:10px;padding:0.6rem 0.75rem;background:#f8fafc;border:1px solid var(--admin-border);border-radius:8px;cursor:pointer;font-size:0.85rem;';
                        label.innerHTML = '<input type="checkbox" name="give_back_serials[]" value="' + c.serial_number + '" style="width:16px;height:16px;accent-color:var(--info);">' +
                            '<span style="font-weight:700;color:var(--admin-accent);font-family:monospace;">' + c.serial_number + '<span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(59,130,246,0.3);" title="Belongs to: ' + (c.original_owner_name || 'Unknown') + '">CON</span></span>' +
                            '<span style="color:var(--admin-muted);">' + (c.gas_name || '') + ' (' + c.size_capacity + ')</span>' +
                            '<span class="badge" style="margin-left:auto;font-size:0.65rem;background:' + (c.status === 'empty' ? '#fee2e2;color:#b91c1c;' : '#d1fae5;color:#065f46;') + '">' + c.status + '</span>';
                        giveBackDiv.appendChild(label);
                    });
                } else {
                    giveBackDiv.innerHTML = '<p style="font-size:0.85rem;color:var(--admin-muted);text-align:center;padding:1rem 0;">No customer-owned cylinders found in our inventory.</p>';
                }

                // Populate datalist with all cylinder serials
                const datalist = document.getElementById('allCylindersDatalist');
                datalist.innerHTML = '';
                const allSerials = (data.our_with_them || []).concat(data.their_with_us || []);
                allSerials.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.serial_number;
                    opt.text = (c.gas_name || '') + ' (' + c.size_capacity + ')';
                    datalist.appendChild(opt);
                });
            })
            .catch(err => {
                console.error('Failed to load exchange data:', err);
            });
    }

    // ── Serial Row Management ──
    function addSerialRow(containerId, inputName, placeholder) {
        const container = document.getElementById(containerId);
        const row = document.createElement('div');
        row.className = 'serial-row';
        row.style.cssText = 'display: flex; gap: 0.5rem; align-items: center;';
        row.innerHTML = '<input type="text" name="' + inputName + '" class="form-control return-serial-input" placeholder="' + placeholder + '" style="flex:1;min-width:180px;" list="allCylindersDatalist">' +
            '<input type="number" name="return_damage_amount[]" class="form-control" style="width:90px;font-size:0.82rem;padding:0.35rem 0.65rem;height:36px;border-color:#fca5a5;" value="0" min="0" step="0.01" placeholder="Damge ₹">' +
            '<input type="text" name="return_damage_desc[]" class="form-control" style="flex:0.6;min-width:120px;font-size:0.82rem;padding:0.35rem 0.65rem;height:36px;border-color:#fca5a5;" placeholder="Damage description">' +
            '<button type="button" class="btn-danger" style="padding: 0.4rem 0.7rem; font-size: 0.8rem; border-radius: 8px;flex-shrink:0;" onclick="removeSerialRow(this)" title="Remove">&times;</button>';
        container.appendChild(row);
        row.querySelector('input').focus();
    }

    function addSerialValue(containerId, inputName, value) {
        const container = document.getElementById(containerId);
        // Check if already added
        const existing = container.querySelectorAll('input[type="text"]');
        for (let inp of existing) {
            if (!inp.value) {
                inp.value = value;
                return;
            }
        }
        // Add new row
        addSerialRow(containerId, inputName, 'Enter cylinder serial number...');
        const lastInput = container.querySelector('.serial-row:last-child input');
        if (lastInput) lastInput.value = value;
    }

    function removeSerialRow(btn) {
        const container = btn.closest('.serial-row').parentElement;
        if (container.querySelectorAll('.serial-row').length > 1) {
            btn.closest('.serial-row').remove();
        } else {
            btn.closest('.serial-row').querySelector('input').value = '';
        }
    }

    function resetExchange() {
        document.getElementById('exchangePanels').style.display = 'none';
        document.getElementById('customerSelect').value = '';
        document.getElementById('customerSearchInput').value = '';
        document.getElementById('customerSelectedName').value = '';
        selectedCustomerId = '';
        // Reset serial rows to single empty
        const container = document.getElementById('returnSerialsContainer');
        container.innerHTML = '<div class="serial-row" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">' +
            '<input type="text" name="return_serials[]" class="form-control return-serial-input" placeholder="Enter cylinder serial number..." style="flex:1;min-width:180px;" list="allCylindersDatalist">' +
            '<input type="number" name="return_damage_amount[]" class="form-control" style="width:90px;font-size:0.82rem;padding:0.35rem 0.65rem;height:36px;border-color:#fca5a5;" value="0" min="0" step="0.01" placeholder="Damge ₹">' +
            '<input type="text" name="return_damage_desc[]" class="form-control" style="flex:0.6;min-width:120px;font-size:0.82rem;padding:0.35rem 0.65rem;height:36px;border-color:#fca5a5;" placeholder="Damage description">' +
            '<button type="button" class="btn-danger" style="padding: 0.4rem 0.7rem; font-size: 0.8rem; border-radius: 8px;flex-shrink:0;" onclick="removeSerialRow(this)" title="Remove">&times;</button></div>';
    }
</script>

<?php require_once 'layout_footer.php'; ?>
