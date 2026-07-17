<?php define('SEND_CYL_VERSION', '3');
require_once __DIR__ . '/lang_init.php';

// ── AJAX: Analyze Bulk Dispatch (must run before layout.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_dispatch') {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/csrf.php';
    require_login();
    validateCsrfToken();
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/inventory-utils.php';
    require_once __DIR__ . '/gst_helper.php';
    require_once __DIR__ . '/bulk_operation_engine.php';
    ob_clean();
    header('Content-Type: application/json');
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        $vendor_id = intval($_POST['vendor_id'] ?? 0);
        $advance_amount = floatval($_POST['advance_amount'] ?? 0);

        // Compute estimated total
        $est_total = 0;
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $est = $pdo->prepare("SELECT c.gas_type_id, c.size_capacity, g.refill_cost, g.size_refill_costs FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id IN ($ph)");
            $est->execute(array_map('intval', $ids));
            while ($erow = $est->fetch()) {
                $sizes = json_decode($erow['size_refill_costs'] ?? '{}', true) ?: [];
                $cost = floatval($sizes[$erow['size_capacity']] ?? $erow['refill_cost'] ?? 0);
                $est_total += $cost;
            }
        }

        $context = [
            'action' => 'dispatch',
            'vendor_id' => $vendor_id,
            'advance_amount' => $advance_amount,
            'estimated_total' => $est_total,
        ];

        $report = generateFullImpactReport($pdo, $ids, 'dispatch', $context);
        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$page_title = "Send Cylinders";
$active_menu = "cylinders_send";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/bulk_operation_engine.php';

runGSTMigrations($pdo);
runVendorActivityLogMigration($pdo);

$message = '';
$error = '';
$advance_recorded = null;

// ── POST: Execute Dispatch Cylinders to Vendor ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'execute_dispatch') {
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $selected_cyls = $_POST['cylinder_ids'] ?? [];
    $notes = trim($_POST['notes'] ?? '');
    $dispatch_date = trim($_POST['dispatch_date'] ?? date('Y-m-d H:i:s'));
    $driver_name = trim($_POST['driver_name'] ?? '');
    $vehicle_number = trim($_POST['vehicle_number'] ?? '');
    $expected_return_date = trim($_POST['expected_return_date'] ?? '');
    $dispatch_transport = floatval($_POST['dispatch_transport_cost'] ?? 0);

                $advance_enabled = !empty($_POST['advance_enabled']);
    $advance_amount = floatval($_POST['advance_amount'] ?? 0);
    $advance_gst = !empty($_POST['advance_gst_applicable']);
    $advance_gst_rate = floatval($_POST['advance_gst_rate'] ?? 0);
    $gst_locked = $advance_gst ? 1 : 0;
    $advance_payment_method = trim($_POST['advance_payment_method'] ?? '');
    $advance_payment_date = trim($_POST['advance_payment_date'] ?? date('Y-m-d H:i:s'));
    $advance_reference = trim($_POST['advance_reference'] ?? '');
    $advance_notes = trim($_POST['advance_notes'] ?? '');
    $created_by = $_SESSION['username'] ?? 'admin';

    if ($vendor_id <= 0 || empty($selected_cyls)) {
        $error = "Please select a vendor and at least one cylinder to send.";
    } elseif ($advance_enabled && $advance_amount <= 0) {
        $error = "Advance payment amount must be greater than zero.";
    } else {
        try {
            // Build context for impact report
            $cyl_ids = array_map('intval', (array)$selected_cyls);
            $est_total = 0;
            $ph = implode(',', array_fill(0, count($cyl_ids), '?'));
            $est = $pdo->prepare("SELECT c.gas_type_id, c.size_capacity, g.refill_cost, g.size_refill_costs FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id IN ($ph)");
            $est->execute($cyl_ids);
            while ($erow = $est->fetch()) {
                $sizes = json_decode($erow['size_refill_costs'] ?? '{}', true) ?: [];
                $cost = floatval($sizes[$erow['size_capacity']] ?? $erow['refill_cost'] ?? 0);
                $est_total += $cost;
            }

            $context = ['action' => 'dispatch', 'vendor_id' => $vendor_id, 'advance_amount' => $advance_amount, 'estimated_total' => $est_total];
            $report = generateFullImpactReport($pdo, $cyl_ids, 'dispatch', $context);

            $result = executeBulkOperation($pdo, $report, $context, function($pdo, $report, $context) use ($vendor_id, $selected_cyls, $notes, $dispatch_date, $driver_name, $vehicle_number, $expected_return_date, $advance_enabled, $advance_amount, $advance_gst, $advance_gst_rate, $advance_payment_method, $advance_payment_date, $advance_reference, $advance_notes, $est_total, $created_by, $dispatch_transport) {

                // Generate Lot Number
                $today = date('Ymd');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatch_lots WHERE DATE(created_at) = CURDATE()");
                $stmt->execute();
                $today_count = intval($stmt->fetchColumn()) + 1;
                $lot_number = 'LOT-' . $today . '-' . str_pad($today_count, 3, '0', STR_PAD_LEFT);
                $cylinder_count = count($selected_cyls);

                // GST on FULL estimated total (not on advance — advance is just a payment)
                $estimated_gst_amount = 0;
                $estimated_taxable = $est_total;
                if ($advance_gst && $advance_gst_rate > 0) {
                    $gst_info = calculateGST($est_total, $advance_gst_rate);
                    $estimated_gst_amount = $gst_info['gst_amount'];
                    $estimated_taxable = $gst_info['taxable'];
                }
                $estimated_grand_total = $est_total + $estimated_gst_amount;

                if ($advance_enabled && $advance_amount > $estimated_grand_total) {
                    throw new Exception("Advance payment (₹" . number_format($advance_amount, 2) . ") cannot exceed the estimated grand total (₹" . number_format($estimated_grand_total, 2) . ").");
                }
                $advance_total = $advance_enabled ? $advance_amount : 0;
                $remaining_balance = $advance_total > 0 ? max(0, $estimated_grand_total - $advance_total) : $estimated_grand_total;

                // Create Dispatch Lot
                $dispatch_date_dt = str_replace('T', ' ', $dispatch_date) . ':00';
                $dispatch_transport_per_cyl = $cylinder_count > 0 && $dispatch_transport > 0 ? $dispatch_transport / $cylinder_count : 0;

                $lot_stmt = $pdo->prepare("INSERT INTO dispatch_lots (lot_number, vendor_id, driver_name, vehicle_number, dispatch_date, expected_return_date, notes, estimated_total, cylinder_count, returned_count, lot_status, advance_amount, gst_rate, gst_amount, gst_applicable, gst_type, gst_locked, total_paid, remaining_balance, payment_status, dispatch_transport_total, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'open', ?, ?, ?, ?, 'CGST/SGST', ?, ?, ?, ?, ?, ?)");
                $lot_stmt->execute([
                    $lot_number, $vendor_id, $driver_name ?: null, $vehicle_number ?: null,
                    $dispatch_date_dt, $expected_return_date ?: null, $notes ?: null,
                    $est_total > 0 ? $est_total : null, $cylinder_count,
                    $advance_amount, $advance_gst_rate, $estimated_gst_amount,
                    $advance_gst ? 1 : 0, $gst_locked, $advance_total, $remaining_balance,
                    $advance_enabled ? 'partial' : 'unpaid', $dispatch_transport, $created_by
                ]);
                $lot_id = $pdo->lastInsertId();

                // Insert Lot Items & Update Cylinders
                $dispatched = 0;
                $item_stmt = $pdo->prepare("INSERT INTO dispatch_lot_items (lot_id, cylinder_id, serial_number, gas_type_id, size_capacity, dispatch_status, dispatch_transport_cost) VALUES (?, ?, ?, ?, ?, 'dispatched', ?)");

                foreach ($selected_cyls as $cyl_id) {
                    $cyl_id = intval($cyl_id);
                    $cyl_data = $pdo->prepare("SELECT serial_number, gas_type_id, size_capacity FROM cylinders WHERE id = ?");
                    $cyl_data->execute([$cyl_id]);
                    $cyl_row = $cyl_data->fetch();
                    if (!$cyl_row) continue;

                    $upd = $pdo->prepare("UPDATE cylinders SET status = 'sent_to_vendor', current_vendor_id = ? WHERE id = ? AND status IN ('empty', 'received_for_refill')");
                    $upd->execute([$vendor_id, $cyl_id]);

                    if ($upd->rowCount() > 0) {
                        $item_stmt->execute([$lot_id, $cyl_id, $cyl_row['serial_number'], $cyl_row['gas_type_id'], $cyl_row['size_capacity'], $dispatch_transport_per_cyl]);
                        $pdo->prepare("UPDATE vendors SET active_refill_count = active_refill_count + 1 WHERE id = ?")->execute([$vendor_id]);
                        logCylinderTransaction($pdo, $cyl_id, null, $vendor_id, 'send_to_vendor', "Lot $lot_number: Dispatched for refilling. Notes: $notes");

                        $svc_upd = $pdo->prepare("UPDATE customer_refill_services SET status = 'sent_to_vendor', vendor_id = ? WHERE cylinder_id = ? AND status = 'received'");
                        $svc_upd->execute([$vendor_id, $cyl_id]);
                        $dispatched++;
                    }
                }

                // Record Advance Payment
                if ($advance_enabled && $advance_total > 0) {
                    $pmt_date_dt = str_replace('T', ' ', $advance_payment_date) . ':00';
                    $pmt_stmt = $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, notes, payment_date) VALUES (?, ?, ?, ?, 'vendor_payment', ?, ?)");
                    $pmt_stmt->execute([
                        $lot_id, $vendor_id, $advance_amount,
                        $advance_payment_method ?: 'Bank Transfer',
                        "Advance payment for Lot $lot_number. " . ($advance_notes ? "Notes: $advance_notes" : "") . ($advance_reference ? " | Ref: $advance_reference" : ""),
                        $pmt_date_dt
                    ]);

                    // Vendor-partner ledger
                    $stmt = $pdo->prepare("SELECT COALESCE(running_balance, 0) as rb, COALESCE(advance_balance, 0) as ab, COALESCE(due_balance, 0) as db FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$vendor_id]);
                    $bal = $stmt->fetch();
                    $running = floatval($bal['rb'] ?? 0);
                    $advance_bal = floatval($bal['ab'] ?? 0);
                    $due_bal = floatval($bal['db'] ?? 0);

                    $new_running = $running + $advance_amount;
                    $new_advance = $advance_bal + $advance_amount;
                    $remarks = "Advance payment of ₹" . number_format($advance_amount, 2) . " (Est. refill: ₹" . number_format($est_total, 2) . " | GST: ₹" . number_format($estimated_gst_amount, 2) . " | Grand total: ₹" . number_format($estimated_grand_total, 2) . ") — Lot $lot_number" . ($advance_reference ? " | Ref: $advance_reference" : "");
                    $stmt = $pdo->prepare("INSERT INTO vendor_partner_ledger (entity_type, entity_id, transaction_date, transaction_type, debit, credit, running_balance, advance_balance, due_balance, settlement_status, reference_type, remarks, created_by) VALUES (?, ?, NOW(), 'advance', 0, ?, ?, ?, ?, 'partial', 'dispatch_lot', ?, ?)");
                    $stmt->execute(['vendor', $vendor_id, $advance_amount, $new_running, $new_advance, $due_bal, $remarks, $created_by]);
                }

                // Recalculate lot financials to keep total_paid/remaining_balance consistent
                if (function_exists('recalcLotFinancials')) {
                    recalcLotFinancials($pdo, $lot_id);
                }

                // Auto-create transport expense
                if ($dispatch_transport > 0) {
                    try {
                        $vendor_data = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                        $vendor_data->execute([$vendor_id]);
                        $v_row = $vendor_data->fetch();
                        $transport_notes = "Dispatch transport for Lot $lot_number — " . $cylinder_count . " cylinders @ ₹" . number_format($dispatch_transport_per_cyl, 2) . "/cyl";
                        if (function_exists('autoCreateExpense') && function_exists('resolveSystemCategory')) {
                            $cat_id = resolveSystemCategory($pdo, 'Transport Charges');
                            if ($cat_id > 0) {
                                autoCreateExpense($pdo, [
                                    'category_id' => $cat_id,
                                    'vendor_id' => $vendor_id,
                                    'vendor_name' => $v_row['name'] ?? '',
                                    'expense_date' => date('Y-m-d', strtotime($dispatch_date_dt)),
                                    'amount' => $dispatch_transport,
                                    'gst_type' => 'exclusive',
                                    'gst_rate' => 0,
                                    'payment_method' => $advance_enabled && $advance_payment_method ? $advance_payment_method : 'Bank Transfer',
                                    'payment_status' => 'paid',
                                    'business_key' => getBrandConfig()['business_key'],
                                    'vehicle_number' => $vehicle_number ?: null,
                                    'driver_name' => $driver_name ?: null,
                                    'notes' => $transport_notes,
                                    'reference_type' => 'dispatch_transport',
                                    'reference_id' => $lot_id,
                                    'reference_number' => $lot_number,
                                    'created_by' => $created_by,
                                    'created_by_name' => $created_by,
                                ]);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Dispatch transport expense auto-create failed for $lot_number: " . $e->getMessage());
                    }
                }

                return ['processed' => $dispatched, 'skipped' => $cylinder_count - $dispatched, 'details' => ['lot_id' => $lot_id, 'lot_number' => $lot_number, 'est_total' => $est_total, 'advance_total' => $advance_total]];
            });

            $msg = "Lot {$result['details']['lot_number']}: Successfully dispatched {$result['processed']} cylinders to vendor for filling!";
            if (floatval($result['details']['advance_total'] ?? 0) > 0) {
                $msg .= " Advance payment of ₹" . number_format(floatval($result['details']['advance_total']), 2) . " recorded.";
            }

            $v_name_row = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
            $v_name_row->execute([$vendor_id]);
            $v_name = $v_name_row->fetchColumn() ?: 'Vendor';
            $est = floatval($result['details']['est_total'] ?? 0);
            $adv = floatval($result['details']['advance_total'] ?? 0);
            $gas_detail = '';
            try {
                $items = $pdo->prepare("SELECT dli.gas_type_id, dli.size_capacity, COUNT(*) as cnt, g.name as gas_name FROM dispatch_lot_items dli JOIN gas_types g ON dli.gas_type_id = g.id WHERE dli.lot_id = ? GROUP BY dli.gas_type_id, dli.size_capacity");
                $items->execute([$result['details']['lot_id']]);
                $gas_parts = [];
                while ($ig = $items->fetch()) {
                    $gas_parts[] = $ig['cnt'] . "× {$ig['gas_name']} ({$ig['size_capacity']})";
                }
                $gas_detail = implode(', ', $gas_parts);
            } catch (PDOException $e) {}
            logVendorActivity($pdo, $vendor_id, 'dispatch', "Dispatched {$result['processed']} cylinders to $v_name", "Lot {$result['details']['lot_number']}: $gas_detail" . ($est > 0 ? ". Est. refill: ₹" . number_format($est, 2) : "") . ($adv > 0 ? ". Advance: ₹" . number_format($adv, 2) : ". Driver: " . ($driver_name ?: 'N/A') . " / " . ($vehicle_number ?: 'N/A')), [
                'vendor_name' => $v_name,
                'lot_number' => $result['details']['lot_number'],
                'gas_breakdown' => $gas_parts ?? [],
                'estimated_total' => $est,
                'advance_amount' => $adv,
                'driver' => $driver_name ?: null,
                'vehicle' => $vehicle_number ?: null,
                'dispatch_transport' => floatval($dispatch_transport ?? 0),
                'cylinder_count' => $result['processed'],
            ], [
                'reference_type' => 'dispatch_lot',
                'reference_id' => $result['details']['lot_id'],
                'cylinder_count' => $result['processed'],
                'amount' => $est + ($adv > 0 ? $adv : 0),
                'payment_method' => ($adv > 0 && !empty($advance_payment_method)) ? $advance_payment_method : null,
                'lot_number' => $result['details']['lot_number'],
                'created_by' => $created_by,
            ]);

            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='lot-dashboard.php';</script>";
            exit();
        } catch (Exception $e) {
            $error = "Dispatch transaction failed: " . $e->getMessage();
        }
    }
}

// ── Fetch Data ──
$vendors = $pdo->query("SELECT * FROM vendors ORDER BY name ASC")->fetchAll();
$gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();

// Build refill cost lookup: gas_type_id → { refill_cost, size_refill_costs }
$gas_type_costs = [];
foreach ($gas_types as $gt) {
    $sizes = json_decode($gt['size_refill_costs'] ?? '{}', true) ?: [];
    $gas_type_costs[$gt['id']] = [
        'name' => $gt['name'],
        'refill_cost' => floatval($gt['refill_cost'] ?? 0),
        'size_refill_costs' => $sizes,
    ];
}

$available_cylinders = $pdo->prepare("
    SELECT c.id, c.serial_number, c.status, c.ownership_type, c.is_customer_refill_cylinder,
           g.name as gas_name, c.size_capacity, g.id as gas_type_id,
           oc.name as original_owner_name, oc.id as original_owner_customer_id,
           p.company_name as partner_name, v.name as vendor_name
    FROM cylinders c
    JOIN gas_types g ON c.gas_type_id = g.id
    LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
    LEFT JOIN partners p ON c.current_partner_id = p.id
    LEFT JOIN vendors v ON c.current_vendor_id = v.id
    WHERE c.status IN ('empty', 'received_for_refill')
    ORDER BY c.serial_number ASC
");
$available_cylinders->execute();
$all_cylinders = $available_cylinders->fetchAll();

// Count cylinders per ownership type to show only available tag buttons
$ownership_counts = ['owned' => 0, 'consumer_owned' => 0, 'partner_owned' => 0, 'vendor_owned' => 0, 'cusr' => 0];
foreach ($all_cylinders as $c) {
    $ot = $c['ownership_type'] ?? 'owned';
    if (isset($ownership_counts[$ot])) $ownership_counts[$ot]++;
    if (!empty($c['is_customer_refill_cylinder'])) $ownership_counts['cusr']++;
}

$gst_rates = [];
try {
    $gst_rates = $pdo->query("SELECT * FROM gst_rates WHERE is_active = 1 ORDER BY rate_percent")->fetchAll();
} catch (PDOException $e) {}

// Fetch advance balance for display on summary
$vendor_advance_balances = [];
try {
    $vab = $pdo->query("SELECT entity_id, COALESCE(advance_balance, 0) as advance_balance FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND id IN (SELECT MAX(id) FROM vendor_partner_ledger WHERE entity_type = 'vendor' GROUP BY entity_id)");
    while ($row = $vab->fetch()) {
        $vendor_advance_balances[intval($row['entity_id'])] = floatval($row['advance_balance']);
    }
} catch (PDOException $e) {}
?>
<style>
.dispatch-layout { max-width: 960px; margin: 0 auto; }
.step-card { background: var(--admin-card-bg, #fff); border: 1px solid var(--admin-border, #e2e8f0); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.25rem; }
.step-number { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--admin-accent, #2563eb); color: #fff; font-weight: 700; font-size: 0.85rem; margin-right: 0.65rem; flex-shrink: 0; }
.step-header { display: flex; align-items: center; margin-bottom: 1rem; font-weight: 700; font-size: 1rem; color: var(--admin-fg, #1e293b); }
.field-helper { font-size: 0.75rem; color: var(--admin-muted, #64748b); margin-top: 0.25rem; line-height: 1.4; }
.cyl-checkbox-item { display: flex; align-items: center; gap: 0.65rem; padding: 0.5rem 0.65rem; border: 1px solid var(--admin-border, #e2e8f0); border-radius: 8px; margin-bottom: 0.35rem; transition: background 0.15s; cursor: pointer; }
.cyl-checkbox-item:hover { background: #f8fafc; }
.cyl-checkbox-item input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--admin-accent, #2563eb); cursor: pointer; flex-shrink: 0; }
.cyl-serial { font-weight: 700; color: var(--admin-accent, #2563eb); font-size: 0.9rem; }
.cyl-gas { font-weight: 600; font-size: 0.82rem; color: var(--admin-fg, #1e293b); }
.cyl-owner-tag { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 800; line-height: 1.4; }
.tag-own { background: #d1fae5; color: #065f46; border: 1px solid rgba(16,185,129,0.3); }
.tag-con { background: #dbeafe; color: #1e40af; border: 1px solid rgba(59,130,246,0.3); }
.tag-br  { background: #fef3c7; color: #92400e; border: 1px solid rgba(251,191,36,0.3); }
.tag-ven { background: #e8d5f5; color: #6b21a8; border: 1px solid rgba(147,51,234,0.3); }
.tag-cusr { background: #fff7ed; color: #c2410c; border: 1px solid rgba(234,88,12,0.3); }
.cyl-list-scroll { max-height: 340px; overflow-y: auto; border: 1px solid var(--admin-border, #e2e8f0); border-radius: 8px; padding: 0.5rem; }
.filter-bar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.85rem; flex-wrap: wrap; }
.filter-bar select, .filter-bar input { padding: 0.45rem 0.7rem; font-size: 0.82rem; border-radius: 8px; border: 1px solid var(--admin-border, #e2e8f0); }
.filter-bar label { font-size: 0.82rem; font-weight: 600; color: var(--admin-muted); display: flex; align-items: center; gap: 0.35rem; cursor: pointer; }
.filter-bar input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--admin-accent, #2563eb); cursor: pointer; }
.tag-filter-group { display: flex; gap: 0.35rem; align-items: center; }
.tag-filter-btn { display: inline-block; padding: 0.3rem 0.65rem; border-radius: 6px; font-size: 0.72rem; font-weight: 700; cursor: pointer; border: 2px solid transparent; background: #f1f5f9; color: #475569; transition: all 0.15s; line-height: 1.4; }
.tag-filter-btn:hover { opacity: 0.85; }
.tag-filter-btn.active { border-color: #64748b; box-shadow: 0 0 0 1px #64748b; }
.tag-filter-btn.tag-own { background: #d1fae5; color: #065f46; }
.tag-filter-btn.tag-own.active { border-color: #065f46; }
.tag-filter-btn.tag-con { background: #dbeafe; color: #1e40af; }
.tag-filter-btn.tag-con.active { border-color: #1e40af; }
.tag-filter-btn.tag-br { background: #fef3c7; color: #92400e; }
.tag-filter-btn.tag-br.active { border-color: #92400e; }
.tag-filter-btn.tag-ven { background: #e8d5f5; color: #6b21a8; }
.tag-filter-btn.tag-ven.active { border-color: #6b21a8; }
.tag-filter-btn.tag-cusr { background: #fff7ed; color: #c2410c; }
.tag-filter-btn.tag-cusr.active { border-color: #c2410c; }
.select-all-row { padding: 0.5rem 0.65rem; border-bottom: 1px solid var(--admin-border, #e2e8f0); margin-bottom: 0.35rem; }
.select-all-label { display: flex; align-items: center; gap: 0.65rem; font-size: 0.82rem; font-weight: 600; color: var(--admin-muted); cursor: pointer; }
.select-all-label input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--admin-accent, #2563eb); cursor: pointer; flex-shrink: 0; }
.summary-bar { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 0.85rem 1rem; margin-top: 0.5rem; }
.summary-line { display: flex; justify-content: space-between; padding: 0.2rem 0; font-size: 0.85rem; }
.summary-total { font-weight: 700; font-size: 1rem; border-top: 1px solid #bbf7d0; padding-top: 0.4rem; margin-top: 0.3rem; }
.financial-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.financial-grid .form-group { margin-bottom: 0; }
.card-estimate { background: #f0fdf4; border: 1px solid #a7f3d0; border-radius: 10px; padding: 1rem; }
.card-estimate h4 { font-size: 0.9rem; font-weight: 700; margin-bottom: 0.75rem; color: #065f46; }
.btn-sm { display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; background: var(--admin-accent, #2563eb); color: #fff; font-weight: 600; border: none; border-radius: 6px; cursor: pointer; transition: opacity 0.15s; line-height: 1.4; }
.btn-sm:hover { opacity: 0.9; }
.is-invalid { border-color: #dc2626 !important; background-color: #fef2f2 !important; }
.field-error { color: #dc2626; font-size: 0.75rem; margin-top: 0.2rem; display: block; }
</style>

<div class="dispatch-layout">
    <div style="margin-bottom:1.5rem;">
        <h2 style="font-size:1.75rem;font-weight:800;letter-spacing:-0.02em;">Send Cylinders to Vendor</h2>
        <p style="color:var(--admin-muted);font-size:0.9rem;margin-top:0.25rem;">
            Dispatch empty cylinders to vendors for refilling. Use the filters below to find specific cylinders, and optionally record an advance payment.
        </p>
    </div>

    <?php if ($error): ?>
    <div class="alert-banner" style="background:var(--danger-soft);color:var(--danger);border-color:#fca5a5;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="dispatchForm" novalidate>
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="execute_dispatch">

        <!-- Step 1: Select Vendor -->
        <div class="step-card">
            <div class="step-header">
                <span class="step-number">1</span>
                Select Vendor
            </div>
            <div class="form-group">
                <label class="form-label" style="display:block;margin-bottom:0.4rem;">Select Vendor <span style="color:#dc2626;">*</span></label>
                <select name="vendor_id" id="vendorSelect" class="form-control" required>
                    <option value="">— Choose registered vendor —</option>
                    <?php
                    $sel_vid = isset($_POST['vendor_id']) ? intval($_POST['vendor_id']) : 0;
if ($sel_vid === 0 && count($vendors) === 1) {
    $sel_vid = intval($vendors[0]['id']);
}
                    foreach ($vendors as $v):
                        $adv_bal = $vendor_advance_balances[intval($v['id'])] ?? 0;
                    ?>
                    <option value="<?php echo $v['id']; ?>"<?php echo intval($v['id']) === $sel_vid ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($v['name']); ?>
                        (Active: <?php echo intval($v['active_refill_count']); ?>)
                        <?php if (!empty($v['gst_number'])): ?> | GST: <?php echo htmlspecialchars($v['gst_number']); endif; ?>
                        <?php if ($adv_bal > 0): ?> | Advance: ₹<?php echo number_format($adv_bal, 2); endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-helper">
                    Choose the vendor who will receive cylinders for refilling. Vendors with an existing <strong>advance balance</strong> are flagged.
                </p>
            </div>

        </div>

        <!-- Step 2: Select Cylinders -->
        <div class="step-card">
            <div class="step-header">
                <span class="step-number">2</span>
                Select Cylinders
            </div>
            <p class="field-helper" style="margin-bottom:0.75rem;">
                Only <strong>Empty</strong> and <strong>Received for Refill</strong> cylinders in the warehouse are shown below. Use gas type and ownership tags to filter.
            </p>

            <div class="filter-bar">
                <select id="gasFilter" onchange="applyFilters()">
                    <option value="0">All Gas Types</option>
                    <?php foreach ($gas_types as $gt): ?>
                    <option value="<?php echo $gt['id']; ?>"><?php echo htmlspecialchars($gt['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="tag-filter-group" id="ownershipFilters">
                    <button type="button" class="tag-filter-btn active" data-ownership="all" onclick="setOwnershipFilter('all')">All</button>
                    <?php if ($ownership_counts['owned'] > 0): ?>
                    <button type="button" class="tag-filter-btn tag-own" data-ownership="owned" onclick="setOwnershipFilter('owned')">OWN (<?php echo $ownership_counts['owned']; ?>)</button>
                    <?php endif; ?>
                    <?php if ($ownership_counts['consumer_owned'] > 0): ?>
                    <button type="button" class="tag-filter-btn tag-con" data-ownership="consumer_owned" onclick="setOwnershipFilter('consumer_owned')">CON (<?php echo $ownership_counts['consumer_owned']; ?>)</button>
                    <?php endif; ?>
                    <?php if ($ownership_counts['partner_owned'] > 0): ?>
                    <button type="button" class="tag-filter-btn tag-br" data-ownership="partner_owned" onclick="setOwnershipFilter('partner_owned')">BR (<?php echo $ownership_counts['partner_owned']; ?>)</button>
                    <?php endif; ?>
                    <?php if ($ownership_counts['vendor_owned'] > 0): ?>
                    <button type="button" class="tag-filter-btn tag-ven" data-ownership="vendor_owned" onclick="setOwnershipFilter('vendor_owned')">VEN (<?php echo $ownership_counts['vendor_owned']; ?>)</button>
                    <?php endif; ?>
                    <?php if ($ownership_counts['cusr'] > 0): ?>
                    <button type="button" class="tag-filter-btn tag-cusr" data-ownership="cusr" onclick="setOwnershipFilter('cusr')">CUS-R (<?php echo $ownership_counts['cusr']; ?>)</button>
                    <?php endif; ?>
                </div>
                <span id="selectedCount" style="font-size:0.85rem;font-weight:600;color:var(--admin-muted);margin-left:auto;">0 cylinders selected</span>
            </div>

            <div id="cylinderError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:0.6rem 0.85rem;margin-bottom:0.65rem;font-size:0.82rem;color:#dc2626;font-weight:600;"></div>
            <div class="cyl-list-scroll" id="cylinderList">
                <?php if (empty($all_cylinders)): ?>
                <p style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.9rem;">
                    No empty cylinders available for dispatch.
                </p>
                <?php else: ?>
                <div class="select-all-row">
                    <label class="select-all-label">
                        <input type="checkbox" id="selectAllVisible" onchange="toggleSelectAll()">
                        Select All Visible
                    </label>
                </div>
                <?php foreach ($all_cylinders as $c):
                    $owner_tag = '';
                    $tag_class = 'tag-own';
                    $tag_label = 'OWN';
                    if ($c['ownership_type'] === 'partner_owned') {
                        $tag_class = 'tag-br'; $tag_label = 'BR';
                        $owner_tag = htmlspecialchars($c['partner_name'] ?? '');
                    } elseif ($c['ownership_type'] === 'consumer_owned') {
                        $tag_class = 'tag-con'; $tag_label = 'CON';
                        $owner_tag = htmlspecialchars($c['original_owner_name'] ?? '');
                    } elseif ($c['ownership_type'] === 'vendor_owned') {
                        $tag_class = 'tag-ven'; $tag_label = 'VEN';
                        $owner_tag = htmlspecialchars($c['vendor_name'] ?? '');
                    }
                    $is_cusr = !empty($c['is_customer_refill_cylinder']);
                ?>
                <label class="cyl-checkbox-item" data-gas="<?php echo intval($c['gas_type_id']); ?>" data-serial="<?php echo htmlspecialchars($c['serial_number']); ?>" data-ownership="<?php echo htmlspecialchars($c['ownership_type']); ?>"<?php echo $is_cusr ? ' data-cusr="1"' : ''; ?>>
                    <input type="checkbox" name="cylinder_ids[]" value="<?php echo $c['id']; ?>"<?php echo ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cylinder_ids']) && in_array($c['id'], $_POST['cylinder_ids'])) ? ' checked' : ''; ?> onchange="onCylinderCheck()">
                    <span style="flex:1;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                        <span class="cyl-serial"><?php echo htmlspecialchars($c['serial_number']); ?></span>
                        <span class="cyl-gas"><?php echo htmlspecialchars($c['gas_name']); ?> — <?php echo htmlspecialchars($c['size_capacity']); ?></span>
                        <span class="cyl-owner-tag <?php echo $tag_class; ?>" title="<?php echo $owner_tag; ?>"><?php echo $tag_label; ?></span>
                        <?php if ($is_cusr): ?>
                        <span class="cyl-owner-tag tag-cusr">CUS-R</span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Step 3: Dispatch Details & Logistics -->
        <div class="step-card">
            <div class="step-header">
                <span class="step-number">3</span>
                Dispatch Details
            </div>
            <div class="financial-grid">
                <div class="form-group">
                    <label class="form-label">Driver Name</label>
                    <input type="text" name="driver_name" id="driverName" class="form-control" placeholder="e.g. Rajesh Kumar" value="<?php echo htmlspecialchars($_POST['driver_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Vehicle Number</label>
                    <input type="text" name="vehicle_number" id="vehicleNumber" class="form-control" placeholder="e.g. AS 01 AB 1234" value="<?php echo htmlspecialchars($_POST['vehicle_number'] ?? ''); ?>">
                </div>
            </div>
            <div class="financial-grid" style="margin-top:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Dispatch Date <span style="color:#dc2626;">*</span></label>
                    <input type="datetime-local" name="dispatch_date" id="dispatchDateField" class="form-control" style="max-width:240px;" value="<?php echo htmlspecialchars($_POST['dispatch_date'] ?? date('Y-m-d\TH:i')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Expected Return Date</label>
                    <input type="date" name="expected_return_date" class="form-control" style="max-width:240px;" value="<?php echo htmlspecialchars($_POST['expected_return_date'] ?? ''); ?>">
                    <p class="field-helper">When the vendor is expected to return filled cylinders.</p>
                </div>
            </div>
            <div class="form-group" style="margin-top:0.75rem;">
                <label class="form-label">Transportation Cost (₹) <span style="font-weight:400;color:var(--admin-muted);font-size:0.78rem;">— total paid to send these cylinders</span></label>
                <input type="number" name="dispatch_transport_cost" id="dispatchTransportCost" class="form-control" style="max-width:240px;" value="<?php echo htmlspecialchars($_POST['dispatch_transport_cost'] ?? '0.00'); ?>" min="0" step="0.01" placeholder="0.00" oninput="updateSummary()">
                <p class="field-helper" id="transportPerCylinder" style="color:#065f46;">Total transport cost divided equally across all cylinders in this lot: <span id="dispatchTransportPerCyl">0.00</span>/cyl.</p>
            </div>
            <div class="form-group" style="margin-top:0.75rem;">
                <label class="form-label">Dispatch Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Challan numbers, special instructions..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                <p class="field-helper">Internal notes. Will appear in the lot summary and cylinder transaction history.</p>
            </div>
        </div>

        <!-- Step 4: Advance Payment -->
        <div class="step-card" id="advanceSection">
            <div class="step-header">
                <span class="step-number">4</span>
                Advance Payment
            </div>
            <p class="field-helper" style="margin-bottom:0.85rem;">
                Optionally record an advance payment made to the vendor for this refill dispatch. This will be adjusted when cylinders are received back.
            </p>
            <label style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem;cursor:pointer;font-size:0.9rem;font-weight:600;">
                <input type="checkbox" name="advance_enabled" id="advanceEnabled" value="1" onchange="toggleAdvanceFields()" style="width:18px;height:18px;accent-color:var(--admin-accent,#2563eb);cursor:pointer;"<?php echo !empty($_POST['advance_enabled']) ? ' checked' : ''; ?>>
                ☐ Record Advance Payment
            </label>
            <div id="advanceFields" style="<?php echo !empty($_POST['advance_enabled']) ? 'display:block;' : 'display:none;' ?>">
                <!-- Refill Cost Estimate (auto-suggested) -->
                <div id="refillEstimateCard" class="card-estimate" style="display:none;margin-bottom:1rem;">
                    <h4 style="font-size:0.85rem;font-weight:700;margin-bottom:0.65rem;color:#065f46;">Refill Cost Estimate</h4>
                    <div id="estimateBreakdown" style="font-size:0.82rem;"></div>
                    <div style="border-top:1px dashed #a7f3d0;margin-top:0.4rem;padding-top:0.4rem;display:flex;justify-content:space-between;font-weight:700;font-size:0.9rem;">
                        <span>Estimated Refill Total:</span>
                        <span id="estimateTotal" style="color:#065f46;">₹0.00</span>
                    </div>
                    <div id="estimateGstLine" style="display:none;justify-content:space-between;font-size:0.85rem;margin-top:0.3rem;color:#92400e;">
                        <span>GST (<span id="estimateGstRateLabel">0</span>%):</span>
                        <span id="estimateGstAmount" style="font-weight:700;">₹0.00</span>
                    </div>
                    <div id="estimateGrandTotalLine" style="display:none;justify-content:space-between;font-weight:800;font-size:0.95rem;margin-top:0.3rem;padding-top:0.3rem;border-top:1px solid #fde68a;color:#1e293b;">
                        <span>Grand Total:</span>
                        <span id="estimateGrandTotalVal">₹0.00</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-top:0.5rem;padding-top:0.5rem;border-top:1px solid #e2e8f0;">
                        <span style="font-size:0.82rem;color:var(--admin-muted);">Suggested Advance:</span>
                        <span id="suggestedAmount" style="font-size:1rem;font-weight:800;color:var(--admin-accent,#2563eb);">₹0.00</span>
                        <button type="button" id="useSuggestedBtn" class="btn-sm" style="margin-left:auto;font-size:0.75rem;padding:0.3rem 0.65rem;" onclick="useSuggestedAdvance()">Use Suggested</button>
                    </div>
                </div>
                <div class="financial-grid">
                    <div class="form-group">
                        <label class="form-label">Advance Amount (₹) <span style="color:#dc2626;">*</span></label>
                        <input type="number" name="advance_amount" id="advanceAmount" class="form-control" value="<?php echo htmlspecialchars($_POST['advance_amount'] ?? '0.00'); ?>" min="0" step="0.01" placeholder="e.g. 5000.00" oninput="onAdvanceAmountChange()">
                        <p class="field-helper" id="advanceHelperText">Amount being paid to the vendor in advance for refilling services.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Mode <span style="color:#dc2626;">*</span></label>
                        <select name="advance_payment_method" id="advancePaymentMethod" class="form-control">
                            <option value="">— Select —</option>
                            <?php
                            $adv_pm = $_POST['advance_payment_method'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? '' : 'Cash');
                            $adv_pm_options = ['Cash','UPI','Bank Transfer','Cheque','NEFT','RTGS','Online Transfer'];
                            foreach ($adv_pm_options as $opt):
                            ?>
                            <option value="<?php echo $opt; ?>"<?php echo $adv_pm === $opt ? ' selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top:0.75rem;">
                    <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-weight:600;font-size:0.85rem;">
                        <input type="checkbox" name="advance_gst_applicable" id="advanceGstApplicable" value="1" onchange="toggleAdvanceGst()" style="width:16px;height:16px;accent-color:var(--admin-accent,#2563eb);cursor:pointer;"<?php echo !empty($_POST['advance_gst_applicable']) ? ' checked' : ''; ?>>
                        GST Applicable
                    </label>
                </div>
                <div id="advanceGstFields" style="<?php echo !empty($_POST['advance_gst_applicable']) ? 'display:block;' : 'display:none;' ?>margin-top:0.75rem;">
                    <div class="financial-grid">
                        <div class="form-group">
                            <label class="form-label">GST Rate</label>
                            <select name="advance_gst_rate" id="advanceGstRate" class="form-control" onchange="updateAdvanceCalc()">
                                <?php
                                $adv_gr = $_POST['advance_gst_rate'] ?? '18';
                                $adv_gr_opts = ['5'=>'5%','12'=>'12%','18'=>'18%','0'=>'Custom'];
                                foreach ($adv_gr_opts as $val => $label):
                                ?>
                                <option value="<?php echo $val; ?>"<?php echo $adv_gr === $val ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="advanceCustomGstGroup" style="<?php echo isset($_POST['advance_gst_rate']) && $_POST['advance_gst_rate'] === '0' ? '' : 'display:none;' ?>">
                            <label class="form-label">Custom GST %</label>
                            <input type="number" name="advance_custom_gst" id="advanceCustomGst" class="form-control" value="<?php echo htmlspecialchars($_POST['advance_custom_gst'] ?? '0'); ?>" min="0" step="0.01" placeholder="e.g. 3" oninput="updateAdvanceCalc()">
                        </div>
                    </div>
                </div>
                <div class="financial-grid" style="margin-top:0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Payment Date</label>
                        <input type="datetime-local" name="advance_payment_date" class="form-control" value="<?php echo htmlspecialchars($_POST['advance_payment_date'] ?? date('Y-m-d\TH:i')); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reference / Transaction ID</label>
                        <input type="text" name="advance_reference" class="form-control" placeholder="e.g. UPI ref, cheque no, bank tx ID" value="<?php echo htmlspecialchars($_POST['advance_reference'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group" style="margin-top:0.75rem;">
                    <label class="form-label">Notes</label>
                    <textarea name="advance_notes" class="form-control" rows="2" placeholder="Reason for advance, agreement details..."><?php echo htmlspecialchars($_POST['advance_notes'] ?? ''); ?></textarea>
                </div>
                <!-- Advance Calculation (displayed in Summary step below) -->
            </div>
        </div>

        <!-- Summary -->
        <div class="step-card" style="background:#f8fafc;">
            <div class="step-header" style="margin-bottom:0.75rem;">
                <span class="step-number" style="background:#059669;">✓</span>
                Summary
            </div>
            <div class="summary-bar" id="summaryBar">
                <div class="summary-line"><span>Lot:</span><span id="summaryLot" style="font-weight:600;color:var(--admin-accent);">LOT-YYYYMMDD-NNN</span></div>
                <div class="summary-line"><span>Vendor:</span><span id="summaryVendor" style="font-weight:600;color:var(--admin-accent);">Not selected</span></div>
                <div class="summary-line"><span>Driver / Vehicle:</span><span id="summaryDriver" style="font-weight:600;">—</span></div>
                <div class="summary-line"><span>Cylinders Selected:</span><span id="summaryCount" style="font-weight:600;">0</span></div>
                <div id="summaryGasBreakdown" style="font-size:0.82rem;margin-top:0.3rem;"></div>
                <div id="summaryEstTotalLine" class="summary-line" style="display:none;border-top:1px dashed #e2e8f0;margin-top:0.4rem;padding-top:0.4rem;">
                    <span style="font-weight:600;">Estimated Refill:</span><span id="summaryEstTotal" style="font-weight:700;">₹0.00</span>
                </div>
                <div id="summaryGstLine" class="summary-line" style="display:none;">
                    <span style="color:#075985;font-weight:600;">GST @ <span id="summaryGstRate">0</span>%:</span><span id="summaryGstAmount" style="color:#075985;font-weight:700;">₹0.00</span>
                </div>
                <div id="summaryGrandTotalLine" class="summary-line" style="display:none;border-top:1px dashed #cbd5e1;margin-top:0.3rem;padding-top:0.3rem;">
                    <span style="font-weight:700;">Grand Total:</span><span id="summaryGrandTotal" style="font-weight:800;color:var(--admin-accent);">₹0.00</span>
                </div>
                <div id="summaryTransportLine" class="summary-line" style="display:none;border-top:1px dashed #e2e8f0;margin-top:0.3rem;padding-top:0.3rem;">
                    <span style="color:#075985;font-weight:600;">Transport Cost:</span><span id="summaryTransport" style="color:#075985;font-weight:700;">₹0.00</span>
                </div>
                <div id="summaryAdvanceLine" class="summary-line" style="display:none;border-top:1px dashed #fde68a;margin-top:0.4rem;padding-top:0.4rem;">
                    <span style="color:#92400e;font-weight:600;">Advance Payment:</span><span id="summaryAdvance" style="color:#92400e;font-weight:700;">₹0.00</span>
                </div>
                <div id="summaryRemainingLine" class="summary-line" style="display:none;">
                    <span style="color:#dc2626;font-weight:600;">Remaining Due:</span><span id="summaryRemaining" style="color:#dc2626;font-weight:700;">₹0.00</span>
                </div>
                <div id="summaryPayStatusLine" class="summary-line" style="display:none;">
                    <span style="font-weight:600;">Payment Status:</span><span id="summaryPayStatus" style="font-weight:700;">Unpaid</span>
                </div>
                <div class="summary-total"><span>Action:</span><span>Create Lot &amp; Dispatch</span></div>
            </div>
            <button type="submit" id="submitBtn" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;padding:0.75rem;font-size:1rem;" disabled>
                Send Cylinders for Refilling
            </button>
        </div>
    </form>
</div>

<script>
const vendors = <?php echo json_encode($vendors); ?>;
const vendorMap = {};
vendors.forEach(v => { vendorMap[v.id] = v; });

const gasTypeCosts = <?php echo json_encode($gas_type_costs); ?>;
let activeOwnershipFilter = 'all';
let autoSuggestedAdvance = 0;
let refillTotal = 0;
let userOverrodeAdvance = false;

document.getElementById('vendorSelect').addEventListener('change', function() {
    updateSummary();
});

function setOwnershipFilter(ownership) {
    activeOwnershipFilter = ownership;
    document.querySelectorAll('.tag-filter-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.ownership === ownership);
    });
    applyFilters();
}

function applyFilters() {
    const gasId = document.getElementById('gasFilter').value;
    const items = document.querySelectorAll('.cyl-checkbox-item');

    items.forEach(item => {
        let visible = true;
        if (gasId !== '0' && item.dataset.gas !== gasId) visible = false;
        if (activeOwnershipFilter === 'cusr' && item.dataset.cusr !== '1') visible = false;
        else if (activeOwnershipFilter !== 'all' && activeOwnershipFilter !== 'cusr' && item.dataset.ownership !== activeOwnershipFilter) visible = false;

        if (visible) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });

    document.getElementById('selectAllVisible').checked = false;
    onCylinderCheck();
}

function toggleSelectAll() {
    const checked = document.getElementById('selectAllVisible').checked;
    const items = document.querySelectorAll('#cylinderList .cyl-checkbox-item');
    items.forEach(item => {
        if (item.style.display !== 'none') {
            item.querySelector('input[type="checkbox"]').checked = checked;
        }
    });
    onCylinderCheck();
}

function getCheckedCylinders() {
    return document.querySelectorAll('#cylinderList .cyl-checkbox-item input[type="checkbox"]:checked');
}

function onCylinderCheck() {
    updateCount();
    updateSummary();
    updateRefillEstimate();
    const cylErr = document.getElementById('cylinderError');
    if (cylErr) { cylErr.style.display = 'none'; cylErr.textContent = ''; }
}

function updateCount() {
    const checked = getCheckedCylinders();
    document.getElementById('selectedCount').textContent = checked.length + ' cylinders selected';
}

function toggleAdvanceFields() {
    const enabled = document.getElementById('advanceEnabled').checked;
    document.getElementById('advanceFields').style.display = enabled ? 'block' : 'none';
    if (enabled) {
        document.getElementById('advanceGstApplicable').checked = true;
        toggleAdvanceGst();
        userOverrodeAdvance = false;
        updateRefillEstimate();
        updateAdvanceCalc();
    }
    updateSummary();
}

function toggleAdvanceGst() {
    const enabled = document.getElementById('advanceGstApplicable').checked;
    document.getElementById('advanceGstFields').style.display = enabled ? 'block' : 'none';
    document.getElementById('advanceGstRate').addEventListener('change', function() {
        document.getElementById('advanceCustomGstGroup').style.display = this.value === '0' ? 'block' : 'none';
        userOverrodeAdvance = false;
        updateRefillEstimate();
    });
    userOverrodeAdvance = false;
    updateRefillEstimate();
}

function getAdvanceGstRate() {
    const sel = document.getElementById('advanceGstRate');
    if (sel.value === '0') return parseFloat(document.getElementById('advanceCustomGst').value) || 0;
    return parseFloat(sel.value) || 0;
}

function updateAdvanceCalc() {
    updateSummary();
}

function getRefillCost(gasTypeId, sizeCapacity) {
    const gt = gasTypeCosts[gasTypeId];
    if (!gt) return 0;
    if (gt.size_refill_costs && gt.size_refill_costs[sizeCapacity] !== undefined) {
        return parseFloat(gt.size_refill_costs[sizeCapacity]) || 0;
    }
    return gt.refill_cost || 0;
}

function updateRefillEstimate() {
    const checked = getCheckedCylinders();
    const enabled = document.getElementById('advanceEnabled').checked;
    const card = document.getElementById('refillEstimateCard');

    if (!enabled || checked.length === 0) {
        card.style.display = 'none';
        return;
    }

    // Group checked cylinders by gas_type_id + size_capacity
    const groups = {};
    checked.forEach(cb => {
        const item = cb.closest('.cyl-checkbox-item');
        const gasId = item.dataset.gas;
        const sizeSpan = item.querySelector('.cyl-gas');
        const sizeText = sizeSpan ? sizeSpan.textContent.trim() : '';
        // Extract gas name and size from text like "Oxygen — D"
        const parts = sizeText.split(' — ');
        const key = gasId + '|' + (parts[1] || '');
        if (!groups[key]) {
            groups[key] = { gasId: parseInt(gasId), size: parts[1] || '', gasName: parts[0] || 'Unknown', qty: 0 };
        }
        groups[key].qty++;
    });

    let total = 0;
    let lines = '';
    for (const key of Object.keys(groups)) {
        const g = groups[key];
        const cost = getRefillCost(g.gasId, g.size);
        const subtotal = cost * g.qty;
        total += subtotal;
        lines += '<div style="display:flex;justify-content:space-between;padding:0.15rem 0;font-size:0.82rem;">' +
            '<span>' + g.gasName + ' (' + g.size + ') × ' + g.qty + '</span>' +
            '<span>@ ₹' + cost.toFixed(2) + ' = <strong>₹' + subtotal.toFixed(2) + '</strong></span></div>';
    }

    // Compute GST on full estimated total if applicable
    const gstEnabled = document.getElementById('advanceGstApplicable').checked;
    const gstRate = gstEnabled ? getAdvanceGstRate() : 0;
    const gstAmount = gstRate > 0 ? total * gstRate / 100 : 0;
    const grandTotal = total + gstAmount;

    refillTotal = total;
    card.style.display = 'block';
    document.getElementById('estimateBreakdown').innerHTML = lines;
    document.getElementById('estimateTotal').textContent = '₹' + total.toFixed(2);
    const gstLine = document.getElementById('estimateGstLine');
    if (gstEnabled && gstRate > 0) {
        if (gstLine) { gstLine.style.display = 'flex'; document.getElementById('estimateGstAmount').textContent = '₹' + gstAmount.toFixed(2); document.getElementById('estimateGstRateLabel').textContent = gstRate.toFixed(0); }
    } else {
        if (gstLine) { gstLine.style.display = 'none'; }
    }
    const gtLine = document.getElementById('estimateGrandTotalLine');
    if (gtLine) {
        if (gstEnabled && gstRate > 0) { gtLine.style.display = 'flex'; document.getElementById('estimateGrandTotalVal').textContent = '₹' + grandTotal.toFixed(2); }
        else { gtLine.style.display = 'none'; }
    }
    document.getElementById('suggestedAmount').textContent = '₹' + grandTotal.toFixed(2);

    autoSuggestedAdvance = grandTotal;

    // Auto-fill if user hasn't manually overridden
    if (!userOverrodeAdvance) {
        document.getElementById('advanceAmount').value = grandTotal.toFixed(2);
        updateAdvanceCalc();
    }

    // Show helper
    const helper = document.getElementById('advanceHelperText');
    if (!userOverrodeAdvance) {
        helper.textContent = 'Auto-filled from grand total (refill + GST). You can edit this amount.';
        helper.style.color = '#065f46';
    }
}

function onAdvanceAmountChange() {
    const currentVal = parseFloat(document.getElementById('advanceAmount').value) || 0;
    if (autoSuggestedAdvance > 0 && Math.abs(currentVal - autoSuggestedAdvance) > 0.01) {
        userOverrodeAdvance = true;
        document.getElementById('advanceHelperText').textContent = 'Custom amount entered. Click "Use Suggested" to reset to grand total (₹' + autoSuggestedAdvance.toFixed(2) + ').';
        document.getElementById('advanceHelperText').style.color = '#92400e';
    }
    updateAdvanceCalc();
    updateSummary();
}

function useSuggestedAdvance() {
    if (autoSuggestedAdvance > 0) {
        userOverrodeAdvance = false;
        document.getElementById('advanceAmount').value = autoSuggestedAdvance.toFixed(2);
        document.getElementById('advanceHelperText').textContent = 'Auto-filled from grand total (refill + GST). You can edit this amount.';
        document.getElementById('advanceHelperText').style.color = '#065f46';
        updateAdvanceCalc();
        updateSummary();
    }
}

function updateSummary() {
    const checked = getCheckedCylinders();
    const vendorEl = document.getElementById('vendorSelect');
    const v = vendorMap[vendorEl.value];
    const vendorName = v ? v.name : 'Not selected';

    const driver = document.getElementById('driverName').value.trim();
    const vehicle = document.getElementById('vehicleNumber').value.trim();
    document.getElementById('summaryDriver').textContent = (driver || vehicle) ? (driver + (vehicle ? ' / ' + vehicle : '')) : '—';
    document.getElementById('summaryVendor').textContent = vendorName;
    document.getElementById('summaryCount').textContent = checked.length;

    const breakdown = {};
    checked.forEach(cb => {
        const item = cb.closest('.cyl-checkbox-item');
        const gasSpan = item.querySelector('.cyl-gas');
        const gasText = gasSpan ? gasSpan.textContent.trim() : 'Unknown';
        if (!breakdown[gasText]) breakdown[gasText] = 0;
        breakdown[gasText]++;
    });

    const bd = document.getElementById('summaryGasBreakdown');
    bd.innerHTML = '';
    for (const [gas, count] of Object.entries(breakdown)) {
        bd.innerHTML += '<div style="padding:0.15rem 0;font-size:0.82rem;color:var(--admin-muted);">' +
            '<strong>' + gas + '</strong> × ' + count + '</div>';
    }

    // Estimated total line (refill cost only, before GST)
    const estTotal = refillTotal || 0;
    const estLine = document.getElementById('summaryEstTotalLine');
    const gstLine = document.getElementById('summaryGstLine');
    const grandTotalLine = document.getElementById('summaryGrandTotalLine');
    if (estTotal > 0) {
        estLine.style.display = '';
        document.getElementById('summaryEstTotal').textContent = '₹' + estTotal.toFixed(2);
    } else {
        estLine.style.display = 'none';
        gstLine.style.display = 'none';
        grandTotalLine.style.display = 'none';
    }

    // GST and Grand Total (on full estimated total)
    let gstAmount = 0;
    let grandTotal = estTotal;
    const gstApp = document.getElementById('advanceGstApplicable').checked;
    if (estTotal > 0 && gstApp) {
        const rate = getAdvanceGstRate();
        gstAmount = rate > 0 ? estTotal * rate / 100 : 0;
        grandTotal = estTotal + gstAmount;
        gstLine.style.display = '';
        document.getElementById('summaryGstRate').textContent = rate.toFixed(0);
        document.getElementById('summaryGstAmount').textContent = '₹' + gstAmount.toFixed(2);
        grandTotalLine.style.display = '';
        document.getElementById('summaryGrandTotal').textContent = '₹' + grandTotal.toFixed(2);
    } else {
        gstLine.style.display = 'none';
        grandTotalLine.style.display = 'none';
    }

    // Transport cost line
    const transportCost = parseFloat(document.getElementById('dispatchTransportCost').value) || 0;
    const transportLine = document.getElementById('summaryTransportLine');
    const cylCount = checked.length;
    if (transportCost > 0 && cylCount > 0) {
        transportLine.style.display = '';
        const perCyl = transportCost / cylCount;
        document.getElementById('summaryTransport').textContent = '₹' + transportCost.toFixed(2) + ' (₹' + perCyl.toFixed(2) + '/cyl)';
        document.getElementById('dispatchTransportPerCyl').textContent = perCyl.toFixed(2);
        document.getElementById('transportPerCylinder').innerHTML = '₹' + transportCost.toFixed(2) + ' ÷ ' + cylCount + ' cyl = ₹' + perCyl.toFixed(2) + ' per cylinder. <span id="dispatchTransportPerCyl">' + perCyl.toFixed(2) + '</span>/cyl.';
    } else {
        transportLine.style.display = 'none';
        document.getElementById('dispatchTransportPerCyl').textContent = '0.00';
    }

    // Advance payment line + remaining + status
    const advanceLine = document.getElementById('summaryAdvanceLine');
    const remainingLine = document.getElementById('summaryRemainingLine');
    const payStatusLine = document.getElementById('summaryPayStatusLine');
    const advanceEnabled = document.getElementById('advanceEnabled').checked;
    if (advanceEnabled) {
        const advAmt = parseFloat(document.getElementById('advanceAmount').value) || 0;
        if (advAmt > 0) {
            advanceLine.style.display = '';
            document.getElementById('summaryAdvance').textContent = '₹' + advAmt.toFixed(2);
            const remaining = Math.max(0, grandTotal - advAmt);
            if (grandTotal > 0) {
                remainingLine.style.display = '';
                document.getElementById('summaryRemaining').textContent = '₹' + remaining.toFixed(2);
                payStatusLine.style.display = '';
                document.getElementById('summaryPayStatus').textContent = remaining <= 0 ? 'Paid' : 'Partially Paid';
                document.getElementById('summaryPayStatus').style.color = remaining <= 0 ? '#16a34a' : '#92400e';
            } else {
                remainingLine.style.display = 'none';
                payStatusLine.style.display = 'none';
            }
        } else {
            advanceLine.style.display = 'none';
            remainingLine.style.display = 'none';
            payStatusLine.style.display = 'none';
        }
    } else {
        advanceLine.style.display = 'none';
        remainingLine.style.display = 'none';
        payStatusLine.style.display = 'none';
    }

    const btn = document.getElementById('submitBtn');
    if (checked.length > 0 && vendorEl.value) {
        btn.disabled = false;
        btn.textContent = 'Send ' + checked.length + ' Cylinder' + (checked.length > 1 ? 's' : '') + ' to ' + vendorName;
    } else {
        btn.disabled = true;
        btn.textContent = 'Send Cylinders for Refilling';
    }
}

function clearFieldErrors() {
    document.querySelectorAll('.field-error').forEach(el => el.remove());
    document.querySelectorAll('.form-control.is-invalid, select.is-invalid').forEach(el => el.classList.remove('is-invalid'));
}

function showFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    field.classList.add('is-invalid');
    const existing = field.parentElement.querySelector('.field-error');
    if (existing) existing.remove();
    const err = document.createElement('span');
    err.className = 'field-error';
    err.style.cssText = 'color:#dc2626;font-size:0.75rem;margin-top:0.2rem;display:block;';
    err.textContent = message;
    field.parentElement.appendChild(err);
}

document.getElementById('dispatchForm').addEventListener('submit', function(e) {
    clearFieldErrors();

    const submitBtn = document.getElementById('submitBtn');

    let valid = true;

    const vendor = document.getElementById('vendorSelect').value;
    if (!vendor) {
        showFieldError('vendorSelect', 'Please select a vendor.');
        valid = false;
    }

    const cyls = getCheckedCylinders();
    if (cyls.length === 0) {
        const cylErr = document.getElementById('cylinderError');
        if (cylErr) {
            cylErr.style.display = 'block';
            cylErr.textContent = '⚠ Select at least one cylinder.';
            cylErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        valid = false;
    } else {
        const cylErr = document.getElementById('cylinderError');
        if (cylErr) { cylErr.style.display = 'none'; cylErr.textContent = ''; }
    }

    const dispatchDate = document.getElementById('dispatchDateField').value.trim();
    if (!dispatchDate) {
        showFieldError('dispatchDateField', 'Dispatch date is required.');
        valid = false;
    }

    const advEnabled = document.getElementById('advanceEnabled').checked;
    if (advEnabled) {
        const advAmt = parseFloat(document.getElementById('advanceAmount').value) || 0;
        if (advAmt <= 0) {
            showFieldError('advanceAmount', 'Advance amount must be greater than zero.');
            valid = false;
        }
        const advMethod = document.getElementById('advancePaymentMethod').value;
        if (!advMethod) {
            showFieldError('advancePaymentMethod', 'Select a payment mode for advance.');
            valid = false;
        }
    }

    if (!valid) {
        e.preventDefault();
        const firstErr = document.querySelector('.is-invalid');
        if (firstErr) {
            firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function() { firstErr.focus({ preventScroll: true }); }, 400);
        }
        return;
    }

    e.preventDefault();

    const cylIds = Array.from(getCheckedCylinders()).map(cb => parseInt(cb.value));
    const vendorId = document.getElementById('vendorSelect').value;
    const advAmt = parseFloat(document.getElementById('advanceAmount').value) || 0;

    // Build context for impact analysis
    const ctx = {
        vendor_id: vendorId,
        advance_amount: advAmt,
    };

    // Show impact analysis dialog
    bulkOp.analyze(cylIds, 'dispatch', ctx, window.location.href);
    bulkOp.confirmCallback = function(report, context) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Processing...';
        document.getElementById('dispatchForm').submit();
    };
});

document.addEventListener('DOMContentLoaded', function() {
    // Driver/vehicle fields update summary on input
    document.getElementById('driverName').addEventListener('input', updateSummary);
    document.getElementById('vehicleNumber').addEventListener('input', updateSummary);

    // Attach input listeners so validation clears on change
    document.querySelectorAll('.form-control, select').forEach(el => {
        el.addEventListener('input', function() {
            this.classList.remove('is-invalid');
            const err = this.parentElement.querySelector('.field-error');
            if (err) err.remove();
        });
        el.addEventListener('change', function() {
            this.classList.remove('is-invalid');
            const err = this.parentElement.querySelector('.field-error');
            if (err) err.remove();
        });
    });

    const params = new URLSearchParams(window.location.search);
    const gasId = params.get('gas_id');
    if (gasId) {
        document.getElementById('gasFilter').value = gasId;
        applyFilters();
    }

    // On POST error, trigger vendor change to re-initialize advance summary
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($error) && !empty($_POST['vendor_id'])): ?>
    document.getElementById('vendorSelect').value = '<?php echo intval($_POST['vendor_id']); ?>';
    document.getElementById('vendorSelect').dispatchEvent(new Event('change'));
    <?php if (!empty($_POST['advance_enabled'])): ?>
    document.getElementById('advanceEnabled').checked = true;
    toggleAdvanceFields();
    <?php if (!empty($_POST['advance_gst_applicable'])): ?>
    document.getElementById('advanceGstApplicable').checked = true;
    toggleAdvanceGst();
    <?php endif; ?>
    updateAdvanceCalc();
    <?php endif; ?>
    updateSummary();
    // Scroll to error
    const errBanner = document.querySelector('.alert-banner');
    if (errBanner) errBanner.scrollIntoView({ behavior: 'smooth', block: 'center' });
    <?php endif; ?>
});

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
</script>

<?php require_once __DIR__ . '/bulk_operation_dialog.php'; ?>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
