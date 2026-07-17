<?php
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';

runPartnerMigrations($pdo);
runVendorActivityLogMigration($pdo);

$page_title = __('ptx.title');
$active_menu = 'partners';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk']);

$message = '';
$error   = '';
$created_by = $_SESSION['user_name'] ?? 'system';
$redirect_to = $_GET['redirect_to'] ?? '';

// ── Fetch partners / vendors / gas types ──
$partners  = $pdo->query("SELECT * FROM partners WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$vendors   = $pdo->query("SELECT * FROM vendors ORDER BY name ASC")->fetchAll();
$gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();

// Build size map for gas types
$gas_size_map = [];
try {
    $gs_sizes = $pdo->query("SELECT gas_type_id, size_capacity, price FROM gas_sizes ORDER BY gas_type_id, sort_order")->fetchAll();
    foreach ($gs_sizes as $gs) {
        $gas_size_map[$gs['gas_type_id']][] = $gs['size_capacity'];
    }
} catch (PDOException $e) {}

/**
 * Helper: log cylinder transaction for partner/vendor actions.
 */
function logPartnerTx($pdo, $cylinder_id, $partner_id, $vendor_id, $type, $notes, $created_by) {
    if (!$cylinder_id) return;
    $entity_type = $partner_id ? 'partner' : 'vendor';
    $entity_label = $entity_type === 'partner' ? 'partner_id' : 'vendor_id';
    $entity_val   = $partner_id ?: $vendor_id;
    logCylinderTransaction($pdo, $cylinder_id, null, $entity_val, $type, $notes);
}

// ════════════════════════════════════════════════════════════
//  POST HANDLERS
// ════════════════════════════════════════════════════════════

// ── 1. BORROW ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_mode']) && $_POST['form_mode'] === 'borrow' && isset($_POST['entity_type'])) {
    $entity_type = $_POST['entity_type']; // 'partner' or 'vendor'
    $entity_id   = intval($_POST['entity_id'] ?? 0);
    $tx_date     = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes_inp   = trim($_POST['notes'] ?? '');
    $default_rate = floatval($_POST['default_rent_rate'] ?? 0);
    $free_days   = intval($_POST['free_days'] ?? 0);
    $gas_type_id = intval($_POST['gas_type_id'] ?? 0);
    $size_val    = trim($_POST['size_capacity'] ?? '');
    $quantity    = intval($_POST['quantity'] ?? 1);
    $serials_raw = trim($_POST['serials'] ?? '');

    if ($entity_id <= 0 || empty($serials_raw)) {
        $error = "Select entity and enter at least one serial number.";
    } else {
        try {
            $pdo->beginTransaction();

            $tx_type = $entity_type === 'partner' ? 'borrowed_from_partner' : 'borrowed_from_vendor';
            $ownership_type = $entity_type === 'partner' ? 'partner_owned' : 'vendor_owned';
            $entity_col = $entity_type === 'partner' ? 'partner_id' : 'vendor_id';
            $cyl_entity_col = $entity_type === 'partner' ? 'current_partner_id' : 'current_vendor_id';
            $log_type = $entity_type === 'partner' ? 'partner_borrow' : 'vendor_borrow';

            // Create transaction header
            $ins = $pdo->prepare("INSERT INTO partner_transactions ($entity_col, transaction_type, transaction_date, notes, created_by) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$entity_id, $tx_type, $tx_date, $notes_inp ?: null, $created_by]);
            $tx_id = $pdo->lastInsertId();

            // Process serials
            $serials = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $serials_raw)));
            $imported = 0;
            foreach ($serials as $sn) {
                if (empty($sn)) continue;

                // Check if cylinder exists
                $chk = $pdo->prepare("SELECT id, status, current_partner_id, current_vendor_id FROM cylinders WHERE serial_number = ?");
                $chk->execute([$sn]);
                $existing = $chk->fetch();

                if ($existing) {
                    // Update existing cylinder
                    $upd = $pdo->prepare("UPDATE cylinders SET status = ?, $cyl_entity_col = ?, ownership_type = ?, borrow_date = ?, daily_rent_rate = ?, free_days = ? WHERE id = ?");
                    $upd->execute([$tx_type, $entity_id, $ownership_type, $tx_date, $default_rate, $free_days, $existing['id']]);
                    $cyl_id = $existing['id'];
                } else {
                    // Create new cylinder
                    $ins_cyl = $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, $cyl_entity_col, ownership_type, borrow_date, daily_rent_rate, free_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins_cyl->execute([$sn, $gas_type_id, $size_val, $tx_type, $entity_id, $ownership_type, $tx_date, $default_rate, $free_days]);
                    $cyl_id = $pdo->lastInsertId();
                }

                // Create transaction item
                $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$tx_id, $cyl_id, $sn, $gas_type_id, $size_val, $existing ? $existing['status'] : 'new', $tx_type]);

                logPartnerTx($pdo, $cyl_id, $entity_type === 'partner' ? $entity_id : null, $entity_type === 'vendor' ? $entity_id : null, $log_type, $notes_inp, $created_by);
                $imported++;
            }

            if ($imported === 0) throw new Exception("No valid cylinders imported.");

            $pdo->commit();
            syncInventory($pdo);

            // Update transaction with actual count
            $pdo->prepare("UPDATE partner_transactions SET notes = CONCAT(COALESCE(notes,''), ' (', ?, ' cylinders)') WHERE id = ?")->execute([$imported, $tx_id]);

            $entity_name = '';
            if ($entity_type === 'partner') { $chk = $pdo->prepare("SELECT company_name FROM partners WHERE id = ?"); $chk->execute([$entity_id]); $entity_name = $chk->fetchColumn(); }
            else { $chk = $pdo->prepare("SELECT name FROM vendors WHERE id = ?"); $chk->execute([$entity_id]); $entity_name = $chk->fetchColumn(); }

            $msg = "$imported cylinders borrowed from " . htmlspecialchars($entity_name ?? "Entity #$entity_id");
            $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$created_by, 'Borrow Cylinders', $msg]);
            if ($entity_type === 'vendor') {
                logVendorActivity($pdo, $entity_id, 'borrow', "Borrowed {$imported} cylinders from {$entity_name}", "Rate: ₹{$default_rate}/day" . ($free_days > 0 ? " | Free days: {$free_days}" : "") . ($notes_inp ? " | Notes: {$notes_inp}" : ""), [
                    'gas_type_id' => $gas_type_id,
                    'size' => $size_val,
                    'daily_rate' => $default_rate,
                    'free_days' => $free_days,
                    'cylinder_count' => $imported,
                    'entity_name' => $entity_name,
                ], [
                    'cylinder_count' => $imported,
                    'created_by' => $created_by,
                ]);
            }
            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='" . ($redirect_to ?: 'partner-transactions.php') . "';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Borrow failed: " . $e->getMessage();
        }
    }
}

// ── 2. RETURN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_mode']) && $_POST['form_mode'] === 'return' && isset($_POST['entity_type'])) {
    $entity_type = $_POST['entity_type'];
    $entity_id   = intval($_POST['entity_id'] ?? 0);
    $tx_date     = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes_inp   = trim($_POST['notes'] ?? '');
    $cylinders_data = $_POST['cylinders'] ?? []; // array of cylinder_id => [rent_paid, damage_amount, payment_status]

    if ($entity_id <= 0 || empty($cylinders_data)) {
        $error = "Select entity and at least one cylinder to return.";
    } else {
        try {
            $pdo->beginTransaction();

            $tx_type = $entity_type === 'partner' ? 'returned_to_partner' : 'returned_to_vendor';
            $entity_col = $entity_type === 'partner' ? 'partner_id' : 'vendor_id';
            $cyl_entity_col = $entity_type === 'partner' ? 'current_partner_id' : 'current_vendor_id';
            $log_type = $entity_type === 'partner' ? 'partner_return' : 'vendor_return';

            $ins = $pdo->prepare("INSERT INTO partner_transactions ($entity_col, transaction_type, transaction_date, notes, created_by) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$entity_id, $tx_type, $tx_date, $notes_inp ?: null, $created_by]);
            $tx_id = $pdo->lastInsertId();

            $returned = 0;
            foreach ($cylinders_data as $cyl_id => $cyld) {
                $cyl_id = intval($cyl_id);
                $rent_paid = floatval($cyld['rent_paid'] ?? 0);
                $damage_amount = floatval($cyld['damage_amount'] ?? 0);
                $payment_status = $cyld['payment_status'] ?? 'cleared';

                // Fetch cylinder details
                $fetch = $pdo->prepare("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ?");
                $fetch->execute([$cyl_id]);
                $cyl = $fetch->fetch();
                if (!$cyl) continue;

                $bdate = $cyl['borrow_date'] ? new DateTime($cyl['borrow_date']) : new DateTime($tx_date);
                $tx_dt = new DateTime($tx_date);
                $days_held = max(0, (int)$tx_dt->diff($bdate)->days);
                $free = (int)($cyl['free_days'] ?? 0);
                $chargeable = max(0, $days_held - $free);
                $rate = (float)($cyl['daily_rent_rate'] ?? 0);
                $rent_accrued = round($chargeable * $rate, 2);

                // Update cylinder
                $pdo->prepare("UPDATE cylinders SET status = ?, $cyl_entity_col = NULL, borrow_date = NULL, daily_rent_rate = 0, free_days = 0 WHERE id = ?")
                    ->execute([$tx_type, $cyl_id]);

                // Insert transaction item
                $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after, daily_rent_rate, free_days, days_held, rent_accrued, rent_paid, damage_amount, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$tx_id, $cyl_id, $cyl['serial_number'], $cyl['gas_type_id'], $cyl['size_capacity'], $cyl['status'], $tx_type, $rate, $free, $days_held, $rent_accrued, $rent_paid, $damage_amount, $payment_status]);

                logPartnerTx($pdo, $cyl_id, $entity_type === 'partner' ? $entity_id : null, $entity_type === 'vendor' ? $entity_id : null, $log_type, $notes_inp, $created_by);
                $returned++;
            }

            $pdo->commit();
            syncInventory($pdo);

            $msg = "$returned cylinders returned successfully.";
            if ($entity_type === 'vendor') {
                logVendorActivity($pdo, $entity_id, 'return', "Returned {$returned} cylinders to {$entity_name}", $notes_inp ? "Notes: {$notes_inp}" : null, [
                    'cylinder_count' => $returned,
                    'entity_name' => $entity_name,
                ], [
                    'cylinder_count' => $returned,
                    'created_by' => $created_by,
                ]);
            }
            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='" . ($redirect_to ?: 'partner-transactions.php') . "';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Return failed: " . $e->getMessage();
        }
    }
}

// ── 3. LEND ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_mode']) && $_POST['form_mode'] === 'lend') {
    $partner_id  = intval($_POST['partner_id'] ?? 0);
    $tx_date     = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes_inp   = trim($_POST['notes'] ?? '');
    $default_rate = floatval($_POST['default_rent_rate'] ?? 0);
    $cylinder_rates = $_POST['cylinder_rates'] ?? [];

    if ($partner_id <= 0) {
        $error = "Select a partner.";
    } else {
        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare("INSERT INTO partner_transactions (partner_id, transaction_type, transaction_date, notes, created_by) VALUES (?, 'lent_to_partner', ?, ?, ?)");
            $ins->execute([$partner_id, $tx_date, $notes_inp ?: null, $created_by]);
            $tx_id = $pdo->lastInsertId();

            $lent = 0;
            if (!empty($cylinder_rates)) {
                foreach ($cylinder_rates as $cyl_id => $rate_per_cyl) {
                    $cyl_id = intval($cyl_id);
                    $rate = floatval($rate_per_cyl ?? $default_rate);

                    $fetch = $pdo->prepare("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ? AND c.ownership_type = 'owned' AND c.status IN ('empty', 'in_maintenance') AND c.current_partner_id IS NULL");
                    $fetch->execute([$cyl_id]);
                    $cyl = $fetch->fetch();
                    if (!$cyl) continue;

                    $pdo->prepare("UPDATE cylinders SET status = 'lent_to_partner', current_partner_id = ?, borrow_date = ?, daily_rent_rate = ? WHERE id = ?")
                        ->execute([$partner_id, $tx_date, $rate, $cyl_id]);

                    $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after, daily_rent_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$tx_id, $cyl_id, $cyl['serial_number'], $cyl['gas_type_id'], $cyl['size_capacity'], $cyl['status'], 'lent_to_partner', $rate]);

                    logPartnerTx($pdo, $cyl_id, $partner_id, null, 'partner_lend', $notes_inp, $created_by);
                    $lent++;
                }
            }

            if ($lent === 0) throw new Exception("No cylinders were lent.");

            $pdo->commit();
            syncInventory($pdo);

            $msg = "$lent cylinders lent to partner successfully.";
            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='" . ($redirect_to ?: 'partner-transactions.php') . "';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Lend failed: " . $e->getMessage();
        }
    }
}

// ── 4. RECEIVE BACK ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_mode']) && $_POST['form_mode'] === 'receive_back') {
    $partner_id = intval($_POST['partner_id'] ?? 0);
    $tx_date    = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes_inp  = trim($_POST['notes'] ?? '');
    $cylinders_data = $_POST['cylinders'] ?? [];

    if ($partner_id <= 0 || empty($cylinders_data)) {
        $error = "Select a partner and at least one cylinder.";
    } else {
        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare("INSERT INTO partner_transactions (partner_id, transaction_type, transaction_date, notes, created_by) VALUES (?, 'received_back_from_partner', ?, ?, ?)");
            $ins->execute([$partner_id, $tx_date, $notes_inp ?: null, $created_by]);
            $tx_id = $pdo->lastInsertId();

            $received = 0;
            foreach ($cylinders_data as $cyl_id => $cyld) {
                $cyl_id = intval($cyl_id);
                $rent_paid = floatval($cyld['rent_paid'] ?? 0);
                $damage_amount = floatval($cyld['damage_amount'] ?? 0);
                $payment_status = $cyld['payment_status'] ?? 'cleared';

                $fetch = $pdo->prepare("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ? AND c.ownership_type = 'owned' AND c.status = 'lent_to_partner' AND c.current_partner_id = ?");
                $fetch->execute([$cyl_id, $partner_id]);
                $cyl = $fetch->fetch();
                if (!$cyl) continue;

                $bdate = $cyl['borrow_date'] ? new DateTime($cyl['borrow_date']) : new DateTime($tx_date);
                $tx_dt = new DateTime($tx_date);
                $days_held = max(0, (int)$tx_dt->diff($bdate)->days);
                $rate = (float)($cyl['daily_rent_rate'] ?? 0);
                $rent_accrued = round($days_held * $rate, 2);

                $pdo->prepare("UPDATE cylinders SET status = 'empty', current_partner_id = NULL, borrow_date = NULL, daily_rent_rate = 0 WHERE id = ?")->execute([$cyl_id]);

                $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after, daily_rent_rate, days_held, rent_accrued, rent_paid, damage_amount, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$tx_id, $cyl_id, $cyl['serial_number'], $cyl['gas_type_id'], $cyl['size_capacity'], $cyl['status'], 'empty', $rate, $days_held, $rent_accrued, $rent_paid, $damage_amount, $payment_status]);

                logPartnerTx($pdo, $cyl_id, $partner_id, null, 'partner_receive_back', $notes_inp, $created_by);
                $received++;
            }

            $pdo->commit();
            syncInventory($pdo);

            $_SESSION['receive_back_redirect'] = $redirect_to;
            $msg = "$received cylinders received back successfully.";
            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='partner-invoice.php?tx_id=$tx_id';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Receive back failed: " . $e->getMessage();
        }
    }
}

// ── Mode from query string ──
$current_mode = $_GET['mode'] ?? 'borrow';
$preselected_partner_id = intval($_GET['partner_id'] ?? 0);
$preselected_vendor_id  = intval($_GET['vendor_id'] ?? 0);
$entity_flag = $preselected_vendor_id ? 'vendor' : ($preselected_partner_id ? 'partner' : 'partner');
$gas_types_json = json_encode($gas_types);
$gas_size_map_json = json_encode($gas_size_map);
$partners_json = json_encode($partners);
$vendors_json = json_encode($vendors);
?>
<link rel="stylesheet" href="partner.css">

<div class="page-header">
    <div class="page-header-title">
        <h2><?php echo __('ptx.heading'); ?></h2>
        <p><?php echo __('ptx.subtitle'); ?></p>
    </div>
    <div class="page-header-actions">
        <a href="partner-transactions.php" class="btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <?php echo __('ptx.view_history'); ?>
        </a>
    </div>
</div>

<?php if ($error): ?>
<div class="alert-banner alert-error"><span><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></span><button class="modal-close" onclick="this.parentElement.remove()"></button></div>
<?php endif; ?>

<div class="tx-mode-switcher">
    <button class="tx-mode-btn <?= $current_mode === 'borrow' ? 'active' : '' ?>" data-mode="borrow">Borrow</button>
    <button class="tx-mode-btn <?= $current_mode === 'return' ? 'active' : '' ?>" data-mode="return">Return</button>
    <button class="tx-mode-btn <?= $current_mode === 'lend' ? 'active' : '' ?>" data-mode="lend">Lend</button>
    <button class="tx-mode-btn <?= $current_mode === 'receive_back' ? 'active' : '' ?>" data-mode="receive_back">Receive Back</button>
</div>

<!-- ══════════════════════════════════════════════════ -->
<!--  BORROW FORM                                      -->
<!-- ══════════════════════════════════════════════════ -->
<div class="tx-panel" id="panel-borrow" style="display:<?= $current_mode === 'borrow' ? 'block' : 'none' ?>">
    <div class="admin-card" style="padding:1.5rem;">
        <div class="profile-card-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Register Borrowed Cylinders
        </div>
        <form method="POST">
            <input type="hidden" name="form_mode" value="borrow">
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Entity Type</label>
                    <select name="entity_type" id="borrowEntityType" class="form-control" onchange="toggleBorrowEntity()">
                        <option value="partner" <?= $entity_flag === 'partner' ? 'selected' : '' ?>>Partner</option>
                        <option value="vendor" <?= $entity_flag === 'vendor' ? 'selected' : '' ?>>Vendor</option>
                    </select>
                </div>
                <div class="form-group" id="borrowPartnerGroup">
                    <label class="form-label">Partner</label>
                    <select name="entity_id" id="borrowEntityId" class="form-control">
                        <option value="">-- Select partner --</option>
                        <?php foreach ($partners as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $preselected_partner_id === intval($p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="borrowVendorGroup" style="display:none;">
                    <label class="form-label">Vendor</label>
                    <select name="entity_id" class="form-control">
                        <option value="">-- Select vendor --</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $preselected_vendor_id === intval($v['id']) ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Transaction Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Default Daily Rent Rate (₹)</label>
                    <input type="number" name="default_rent_rate" class="form-control" value="0.00" min="0" step="0.01">
                </div>
                <div class="form-group">
                    <label class="form-label">Free Days</label>
                    <input type="number" name="free_days" class="form-control" value="0" min="0">
                </div>
            </div>

            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Gas Type</label>
                    <select name="gas_type_id" id="borrowGasType" class="form-control" onchange="updateBorrowSizes()">
                        <option value="">-- Select gas type --</option>
                        <?php foreach ($gas_types as $gt): ?>
                        <option value="<?= $gt['id'] ?>"><?= htmlspecialchars($gt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Size / Capacity</label>
                    <select name="size_capacity" id="borrowSize" class="form-control">
                        <option value="">-- Select size --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity (expected)</label>
                    <input type="number" name="quantity" id="borrowQuantity" class="form-control" value="1" min="1" oninput="validateBorrowSerials()">
                </div>
            </div>

            <div class="form-section-title">Cylinder Serial Numbers</div>
            <div class="form-group">
                <textarea name="serials" id="borrowSerials" class="serial-input-area" placeholder="Enter one serial per line or comma-separated&#10;e.g.&#10;CYL-001&#10;CYL-002&#10;CYL-003" oninput="validateBorrowSerials()"></textarea>
                <div class="serial-counter">
                    <span>Expected: <strong id="borrowExpected">0</strong></span>
                    <span class="serial-count-badge" id="borrowCountBadge">0 entered</span>
                </div>
            </div>

            <div class="form-section-title">Notes</div>
            <div class="form-group">
                <input type="text" name="notes" class="form-control" placeholder="Optional notes about this borrow transaction...">
            </div>

            <button type="submit" class="btn-primary modal-submit-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Register Borrow Transaction
            </button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════ -->
<!--  RETURN FORM                                      -->
<!-- ══════════════════════════════════════════════════ -->
<div class="tx-panel" id="panel-return" style="display:<?= $current_mode === 'return' ? 'block' : 'none' ?>">
    <div class="admin-card" style="padding:1.5rem;">
        <div class="profile-card-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Return Borrowed Cylinders
        </div>
        <form method="POST">
            <input type="hidden" name="form_mode" value="return">
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Entity Type</label>
                    <select name="entity_type" id="returnEntityType" class="form-control" onchange="toggleReturnEntity()">
                        <option value="partner" <?= $entity_flag === 'partner' ? 'selected' : '' ?>>Partner</option>
                        <option value="vendor" <?= $entity_flag === 'vendor' ? 'selected' : '' ?>>Vendor</option>
                    </select>
                </div>
                <div class="form-group" id="returnPartnerGroup">
                    <label class="form-label">Partner</label>
                    <select name="entity_id" id="returnEntityId" class="form-control" onchange="loadReturnCylinders()">
                        <option value="">-- Select partner --</option>
                        <?php foreach ($partners as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $preselected_partner_id === intval($p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="returnVendorGroup" style="display:none;">
                    <label class="form-label">Vendor</label>
                    <select name="entity_id" class="form-control" onchange="loadReturnCylinders()">
                        <option value="">-- Select vendor --</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $preselected_vendor_id === intval($v['id']) ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Return Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
            </div>

            <div id="returnCylindersContainer">
                <p style="color:var(--admin-muted);text-align:center;padding:2rem;">Select a partner/vendor above to load outstanding borrowed cylinders.</p>
            </div>

            <button type="submit" class="btn-primary modal-submit-btn" id="returnSubmitBtn" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Complete Return &amp; Settlement
            </button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════ -->
<!--  LEND FORM                                        -->
<!-- ══════════════════════════════════════════════════ -->
<div class="tx-panel" id="panel-lend" style="display:<?= $current_mode === 'lend' ? 'block' : 'none' ?>">
    <div class="admin-card" style="padding:1.5rem;">
        <div class="profile-card-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            Lend Cylinders to Partner
        </div>
        <form method="POST">
            <input type="hidden" name="form_mode" value="lend">
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Partner</label>
                    <select name="partner_id" id="lendPartnerId" class="form-control" onchange="loadLendableCylinders()">
                        <option value="">-- Select partner --</option>
                        <?php foreach ($partners as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $preselected_partner_id === intval($p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Default Rate (₹/day)</label>
                    <input type="number" name="default_rent_rate" class="form-control" value="0.00" min="0" step="0.01" id="lendDefaultRate">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
            </div>

            <div id="lendCylindersContainer">
                <p style="color:var(--admin-muted);text-align:center;padding:2rem;">Select a partner above to view lendable cylinders.</p>
            </div>

            <button type="submit" class="btn-primary modal-submit-btn" id="lendSubmitBtn" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Confirm Lend Transaction
            </button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════ -->
<!--  RECEIVE BACK FORM                                -->
<!-- ══════════════════════════════════════════════════ -->
<div class="tx-panel" id="panel-receive_back" style="display:<?= $current_mode === 'receive_back' ? 'block' : 'none' ?>">
    <div class="admin-card" style="padding:1.5rem;">
        <div class="profile-card-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Receive Back Lent Cylinders
        </div>
        <form method="POST">
            <input type="hidden" name="form_mode" value="receive_back">
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Partner</label>
                    <select name="partner_id" id="receiveBackPartnerId" class="form-control" onchange="loadReceiveBackCylinders()">
                        <option value="">-- Select partner --</option>
                        <?php foreach ($partners as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $preselected_partner_id === intval($p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
            </div>

            <div id="receiveBackCylindersContainer">
                <p style="color:var(--admin-muted);text-align:center;padding:2rem;">Select a partner above to load lent cylinders.</p>
            </div>

            <button type="submit" class="btn-primary modal-submit-btn" id="receiveBackSubmitBtn" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Complete Receive Back &amp; Invoice
            </button>
        </form>
    </div>
</div>

<script>
const gasTypes = <?= $gas_types_json ?>;
const gasSizeMap = <?= $gas_size_map_json ?>;
const partnersData = <?= $partners_json ?>;
const vendorsData = <?= $vendors_json ?>;

// ── Mode switching ──
document.querySelectorAll('.tx-mode-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const mode = this.dataset.mode;
        document.querySelectorAll('.tx-mode-btn').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        document.querySelectorAll('.tx-panel').forEach(function(p) { p.style.display = 'none'; });
        document.getElementById('panel-' + mode).style.display = 'block';
        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('mode', mode);
        window.history.replaceState({}, '', url);
    });
});

// ── Gas size cascade ──
function updateBorrowSizes() {
    const gtId = document.getElementById('borrowGasType').value;
    const sizeSelect = document.getElementById('borrowSize');
    sizeSelect.innerHTML = '<option value="">-- Select size --</option>';
    if (gtId && gasSizeMap[gtId]) {
        gasSizeMap[gtId].forEach(function(s) {
            sizeSelect.innerHTML += '<option value="' + s + '">' + s + '</option>';
        });
    }
}

// ── Serial validation ──
function validateBorrowSerials() {
    const textarea = document.getElementById('borrowSerials');
    const expected = parseInt(document.getElementById('borrowQuantity').value) || 0;
    const serials = textarea.value.split(/[\n,]+/).map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });
    const count = serials.length;
    document.getElementById('borrowExpected').textContent = expected;
    const badge = document.getElementById('borrowCountBadge');
    badge.textContent = count + ' entered';
    badge.className = 'serial-count-badge';
    if (expected > 0 && count !== expected) {
        badge.classList.add('mismatch');
        textarea.classList.add('error');
        textarea.classList.remove('success');
    } else if (count > 0) {
        badge.classList.add('match');
        textarea.classList.remove('error');
        textarea.classList.add('success');
    }
}

// ── Toggle borrow entity type ──
function toggleBorrowEntity() {
    const type = document.getElementById('borrowEntityType').value;
    document.getElementById('borrowPartnerGroup').style.display = type === 'partner' ? 'block' : 'none';
    document.getElementById('borrowVendorGroup').style.display = type === 'vendor' ? 'block' : 'none';
    document.getElementById('borrowEntityId').disabled = type !== 'partner';
}

// ── Toggle return entity type ──
function toggleReturnEntity() {
    const type = document.getElementById('returnEntityType').value;
    document.getElementById('returnPartnerGroup').style.display = type === 'partner' ? 'block' : 'none';
    document.getElementById('returnVendorGroup').style.display = type === 'vendor' ? 'block' : 'none';
    loadReturnCylinders();
}

// ── Load return cylinders ──
function loadReturnCylinders() {
    const type = document.getElementById('returnEntityType').value;
    const partnerSelect = document.getElementById('returnEntityId');
    const vendorSelect = document.querySelector('#returnVendorGroup select[name="entity_id"]');
    const entityId = type === 'partner' ? partnerSelect.value : vendorSelect.value;
    const container = document.getElementById('returnCylindersContainer');

    if (!entityId) {
        container.innerHTML = '<p style="color:var(--admin-muted);text-align:center;padding:2rem;">Select an entity to load cylinders.</p>';
        document.getElementById('returnSubmitBtn').disabled = true;
        return;
    }

    const endpoint = type === 'partner' ? 'ajax_partner_borrowed' : 'ajax_vendor_borrowed';
    fetch('partner-tx-ajax.php?' + endpoint + '=' + entityId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || data.length === 0) {
                container.innerHTML = '<p style="color:var(--admin-muted);text-align:center;padding:2rem;">No outstanding borrowed cylinders found.</p>';
                document.getElementById('returnSubmitBtn').disabled = true;
                return;
            }
            let html = '<div class="form-section-title" style="margin-top:1.5rem;">Cylinders to Return &mdash; ' + data.length + ' found</div>';
            html += '<div id="returnCylindersList">';
            data.forEach(function(c) {
                html += '<div class="return-cyl-row" data-cyl-id="' + c.id + '">';
                html += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">';
                html += '<div><strong style="font-size:0.95rem;">' + c.serial_number + '</strong> <span class="tag-' + (type === 'partner' ? 'br' : 'ven') + '">' + (type === 'partner' ? 'BR' : 'VEN') + '</span></div>';
                html += '<div><span class="badge badge-filled">' + c.gas_name + '</span> <span style="color:var(--admin-muted);">' + c.size_capacity + '</span></div>';
                html += '</div>';
                html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:0.75rem;margin-top:0.5rem;">';
                html += '<div><span class="contact-label">Days Held</span><strong>' + c.days_held + '</strong> <span style="font-size:0.8rem;color:var(--admin-muted);">(free ' + c.free_days + ', chg ' + c.chargeable_days + ')</span></div>';
                html += '<div><span class="contact-label">Rate</span><strong>₹' + parseFloat(c.daily_rent_rate).toFixed(2) + '/day</strong></div>';
                html += '<div><span class="contact-label">Rent Accrued</span><strong style="color:#b45309;">₹' + c.rent_accrued.toFixed(2) + '</strong></div>';
                html += '<div><label class="contact-label">Rent Paid</label><input type="number" name="cylinders[' + c.id + '][rent_paid]" class="form-control-sm" value="' + c.rent_accrued.toFixed(2) + '" min="0" step="0.01"></div>';
                html += '<div><label class="contact-label">Damage</label><input type="number" name="cylinders[' + c.id + '][damage_amount]" class="form-control-sm" value="0.00" min="0" step="0.01"></div>';
                html += '<div><label class="contact-label">Payment</label><select name="cylinders[' + c.id + '][payment_status]" class="form-control-sm"><option value="cleared">Cleared</option><option value="pending">Pending</option></select></div>';
                html += '</div></div>';
            });
            html += '</div>';
            html += '<div class="summary-strip" id="returnSummary" style="margin-top:1rem;">';
            html += '<div class="strip-item"><div class="strip-val" id="returnStripCyls">' + data.length + '</div><div class="strip-lbl">Cylinders</div></div>';
            html += '<div class="strip-item"><div class="strip-val" id="returnStripRent">₹0.00</div><div class="strip-lbl">Total Accrued</div></div>';
            html += '<div class="strip-item"><div class="strip-val" id="returnStripDamage">₹0.00</div><div class="strip-lbl">Total Damage</div></div>';
            html += '</div>';
            container.innerHTML = html;
            document.getElementById('returnSubmitBtn').disabled = false;
            updateReturnSummary();
        })
        .catch(function() {
            container.innerHTML = '<p style="color:var(--danger);text-align:center;padding:2rem;">Error loading cylinders.</p>';
        });
}

function updateReturnSummary() {
    let totalRent = 0, totalDamage = 0;
    document.querySelectorAll('#returnCylindersList .return-cyl-row').forEach(function(row) {
        const rentInput = row.querySelector('input[name$="[rent_paid]"]');
        const damageInput = row.querySelector('input[name$="[damage_amount]"]');
        if (rentInput) totalRent += parseFloat(rentInput.value) || 0;
        if (damageInput) totalDamage += parseFloat(damageInput.value) || 0;
    });
    const el = document.getElementById('returnStripRent');
    const dmg = document.getElementById('returnStripDamage');
    if (el) el.textContent = '₹' + totalRent.toFixed(2);
    if (dmg) dmg.textContent = '₹' + totalDamage.toFixed(2);
}

// ── Load lendable cylinders ──
function loadLendableCylinders() {
    const partnerId = document.getElementById('lendPartnerId').value;
    const container = document.getElementById('lendCylindersContainer');
    if (!partnerId) {
        container.innerHTML = '<p style="color:var(--admin-muted);text-align:center;padding:2rem;">Select a partner to view lendable cylinders.</p>';
        document.getElementById('lendSubmitBtn').disabled = true;
        return;
    }
    fetch('partner-tx-ajax.php?ajax_lendable=' + partnerId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || data.length === 0) {
                container.innerHTML = '<p style="color:var(--admin-muted);text-align:center;padding:2rem;">No lendable cylinders available (empty/in_maintenance, owned).</p>';
                document.getElementById('lendSubmitBtn').disabled = true;
                return;
            }
            let html = '<div class="form-section-title" style="margin-top:1.5rem;">Lendable Cylinders &mdash; ' + data.length + ' available</div>';
            html += '<div class="summary-strip"><div class="strip-item"><div class="strip-val">' + data.length + '</div><div class="strip-lbl">Available</div></div><div class="strip-item"><div class="strip-val" id="lendDefaultDisplay">₹' + (parseFloat(document.getElementById('lendDefaultRate').value) || 0).toFixed(2) + '</div><div class="strip-lbl">Default Rate</div></div></div>';
            html += '<div id="lendCylsList">';
            data.forEach(function(c) {
                html += '<div class="return-cyl-row" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;" data-cyl-id="' + c.id + '">';
                html += '<div><input type="checkbox" name="cylinder_rates[' + c.id + ']" value="' + (parseFloat(c.daily_rent_rate) || 0).toFixed(2) + '" onchange="updateLendSummary()" checked> <strong>' + c.serial_number + '</strong></div>';
                html += '<div><span class="badge badge-filled">' + c.gas_name + '</span> <span style="color:var(--admin-muted);">' + c.size_capacity + '</span></div>';
                html += '<div><label style="font-size:0.75rem;color:var(--admin-muted);">Rate: </label><input type="number" class="form-control-sm" style="width:90px;" value="' + (parseFloat(c.daily_rent_rate) || 0).toFixed(2) + '" min="0" step="0.01" onchange="this.form.elements[\'cylinder_rates[' + c.id + ']\'].value=this.value; updateLendSummary();"></div>';
                html += '</div>';
            });
            html += '</div>';
            container.innerHTML = html;
            document.getElementById('lendSubmitBtn').disabled = false;
        })
        .catch(function() {
            container.innerHTML = '<p style="color:var(--danger);text-align:center;padding:2rem;">Error loading cylinders.</p>';
        });
}

function updateLendSummary() {
    const checked = document.querySelectorAll('#lendCylsList input[type="checkbox"]:checked').length;
    const s = document.getElementById('lendDefaultDisplay');
    if (s) s.textContent = checked + ' selected';
}

// Default rate change
document.addEventListener('change', function(e) {
    if (e.target.id === 'lendDefaultRate') loadLendableCylinders();
});

// ── Load receive back cylinders ──
function loadReceiveBackCylinders() {
    const partnerId = document.getElementById('receiveBackPartnerId').value;
    const container = document.getElementById('receiveBackCylindersContainer');
    if (!partnerId) {
        container.innerHTML = '<p style="color:var(--admin-muted);text-align:center;padding:2rem;">Select a partner to load lent cylinders.</p>';
        document.getElementById('receiveBackSubmitBtn').disabled = true;
        return;
    }
    fetch('partner-tx-ajax.php?ajax_partner_lent=' + partnerId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || data.length === 0) {
                container.innerHTML = '<p style="color:var(--admin-muted);text-align:center;padding:2rem;">No lent cylinders found for this partner.</p>';
                document.getElementById('receiveBackSubmitBtn').disabled = true;
                return;
            }
            let html = '<div class="form-section-title" style="margin-top:1.5rem;">Lent Cylinders &mdash; ' + data.length + ' found</div>';
            html += '<div id="receiveBackCylsList">';
            data.forEach(function(c) {
                html += '<div class="return-cyl-row" data-cyl-id="' + c.id + '">';
                html += '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">';
                html += '<div><strong>' + c.serial_number + '</strong> <span class="tag-own">OWN</span></div>';
                html += '<div><span class="badge badge-filled">' + c.gas_name + '</span> <span style="color:var(--admin-muted);">' + c.size_capacity + '</span></div>';
                html += '</div>';
                html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:0.75rem;margin-top:0.5rem;">';
                html += '<div><span class="contact-label">Days Held</span><strong>' + c.days_held + '</strong></div>';
                html += '<div><span class="contact-label">Rate</span><strong>₹' + parseFloat(c.daily_rent_rate).toFixed(2) + '/day</strong></div>';
                html += '<div><span class="contact-label">Rent Accrued</span><strong style="color:#b45309;">₹' + c.rent_accrued.toFixed(2) + '</strong></div>';
                html += '<div><label class="contact-label">Rent Paid</label><input type="number" name="cylinders[' + c.id + '][rent_paid]" class="form-control-sm" value="' + c.rent_accrued.toFixed(2) + '" min="0" step="0.01"></div>';
                html += '<div><label class="contact-label">Damage</label><input type="number" name="cylinders[' + c.id + '][damage_amount]" class="form-control-sm" value="0.00" min="0" step="0.01"></div>';
                html += '<div><label class="contact-label">Payment</label><select name="cylinders[' + c.id + '][payment_status]" class="form-control-sm"><option value="cleared">Cleared</option><option value="pending">Pending</option></select></div>';
                html += '</div></div>';
            });
            html += '</div>';
            container.innerHTML = html;
            document.getElementById('receiveBackSubmitBtn').disabled = false;
        })
        .catch(function() {
            container.innerHTML = '<p style="color:var(--danger);text-align:center;padding:2rem;">Error loading cylinders.</p>';
        });
}

// Auto-load on page load if preselected
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('borrowQuantity')) validateBorrowSerials();
    var mode = '<?= $current_mode ?>';
    if (mode === 'return') { toggleReturnEntity(); setTimeout(loadReturnCylinders, 300); }
    if (mode === 'lend' && document.getElementById('lendPartnerId').value) loadLendableCylinders();
    if (mode === 'receive_back' && document.getElementById('receiveBackPartnerId').value) loadReceiveBackCylinders();
});
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
