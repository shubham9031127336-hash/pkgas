<?php
require_once __DIR__ . '/lang_init.php';

$page_title = "Return Cylinders to Customer";
$active_menu = "cylinders";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/gst_helper.php';

runGSTMigrations($pdo);

$message = '';
$error = '';

// ── POST: Bulk Return Cylinders ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_cylinders') {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $order_id = intval($_POST['order_id'] ?? 0);
    $selected_service_ids = $_POST['service_ids'] ?? [];
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $gst_rate = floatval($_POST['gst_rate'] ?? 0);
    $advance_used = floatval($_POST['advance_used'] ?? 0);
    $deposit_used = floatval($_POST['deposit_used'] ?? 0);
    $payment_date_raw = $_POST['payment_date'] ?? date('Y-m-d\TH:i');
    $payment_date = str_replace('T', ' ', $payment_date_raw) . ':00';
    $notes = trim($_POST['notes'] ?? '');
    $mark_gst = isset($_POST['mark_gst']) ? 1 : 0;

    if ($customer_id <= 0 || $order_id <= 0 || empty($selected_service_ids)) {
        $error = "Please select a customer, lot, and at least one cylinder to return.";
    } else {
        try {
            $pdo->beginTransaction();

            $order_stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
            $order_stmt->execute([$order_id]);
            $order = $order_stmt->fetch();
            if (!$order) throw new Exception("Order not found.");

            // ── Guards: prevent double data entry ──
            $isOrderFullyPaid = strtolower($order['payment_status'] ?? '') === 'paid';
            $isGstAlreadyInLedger = false;
            try {
                $gstChk = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE reference_type='refill_order' AND reference_id=? AND input_output_type='output'");
                $gstChk->execute([$order_id]);
                $isGstAlreadyInLedger = intval($gstChk->fetchColumn()) > 0;
            } catch (PDOException $e) {}

            // Check each selected service is not already returned
            $alreadyReturnedCheck = $pdo->prepare("SELECT id, status FROM customer_refill_services WHERE id=? AND status='returned_to_customer'");
            foreach ($selected_service_ids as $svc_id) {
                $svc_id_val = intval($svc_id);
                $alreadyReturnedCheck->execute([$svc_id_val]);
                if ($alreadyReturnedCheck->fetch()) {
                    throw new Exception("Service #$svc_id_val has already been returned to customer. Cannot process duplicate return.");
                }
            }

            $now = date('Y-m-d H:i:s');
            $returned_count = 0;
            $total_service_amount = 0;

            foreach ($selected_service_ids as $svc_id) {
                $svc_id = intval($svc_id);
                $svc_stmt = $pdo->prepare("SELECT * FROM customer_refill_services WHERE id = ? AND customer_id = ? AND refill_order_id = ? AND status IN ('returned_to_warehouse', 'filled_from_vendor')");
                $svc_stmt->execute([$svc_id, $customer_id, $order_id]);
                $svc = $svc_stmt->fetch();
                if (!$svc) continue;

                $cyl_id = intval($svc['cylinder_id']);
                $total_service_amount += floatval($svc['service_charge']);

                // Update cylinder
                $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ? AND status = 'filled' AND is_customer_refill_cylinder = 1")->execute([$cyl_id]);

                // Update service record
                $pdo->prepare("UPDATE customer_refill_services SET status = 'returned_to_customer', payment_status = 'paid', payment_method = ?, completed_at = ? WHERE id = ?")->execute([$payment_method, $now, $svc_id]);

                logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'returned_after_refill', "Returned to customer via Return Cylinders page. Notes: $notes");

                $returned_count++;
            }

            if ($returned_count === 0) {
                throw new Exception("No cylinders could be returned. Verify they are filled from vendor and ready for customer return.");
            }

            // ── Handle advance/deposit utilization ──
            // Guard: skip payment settlement if order is fully paid
            if ($isOrderFullyPaid) {
                $payment_amount = 0;
                $advance_used = 0;
                $deposit_used = 0;
            }

            if ($advance_used > 0) {
                $stmt = $pdo->prepare("UPDATE customers SET advance_balance = GREATEST(0, advance_balance - ?) WHERE id = ?");
                $stmt->execute([$advance_used, $customer_id]);
            }
            if ($deposit_used > 0) {
                $stmt = $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?");
                $stmt->execute([$deposit_used, $customer_id]);
            }

            // ── Record payment if amount > 0 ──
            $total_collected = $payment_amount + $advance_used + $deposit_used;
            if ($total_collected > 0 && !$isOrderFullyPaid) {
                $ledger_group_id = generateLedgerGroupId();
                $payment_notes_parts = ["Return settlement for Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT)];
                if ($advance_used > 0) $payment_notes_parts[] = "Advance used: ₹$advance_used";
                if ($deposit_used > 0) $payment_notes_parts[] = "Deposit used: ₹$deposit_used";
                if ($payment_amount > 0) $payment_notes_parts[] = "Cash collected: ₹$payment_amount";

                $ins = $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, ?, 'refill_payment', ?, ?, ?)");
                $ins->execute([$customer_id, $order_id, $total_collected, $payment_method, implode(' | ', $payment_notes_parts), $payment_date, $ledger_group_id]);

                // Create ledger group entry
                $group_title = 'Return Settlement – Order #ORD-' . str_pad($order_id, 4, '0', STR_PAD_LEFT) . ' – ₹' . number_format($total_collected, 2);
                $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'payment_received', ?, ?, ?)")
                    ->execute([$ledger_group_id, $customer_id, $group_title, $total_collected, $payment_date]);

                // ── Sync order payment status ──
                recalculateOrderPaymentStatus($pdo, $order_id);

                // ── Deduct from credit_used for credit orders ──
                if (intval($order['is_credit_order']) === 1) {
                    $pdo->prepare("UPDATE customers SET credit_used = GREATEST(0, credit_used - ?) WHERE id = ?")
                        ->execute([$total_collected, $customer_id]);
                    $stmt_cr = $pdo->prepare("SELECT credit_used FROM customers WHERE id = ?");
                    $stmt_cr->execute([$customer_id]);
                    $new_credit_used = floatval($stmt_cr->fetchColumn());
                    $pdo->prepare("INSERT INTO credit_transactions (customer_id, refill_order_id, transaction_type, amount, balance_after, description, ledger_group_id) VALUES (?, ?, 'payment', ?, ?, ?, ?)")
                        ->execute([$customer_id, $order_id, $total_collected, $new_credit_used, 'Payment via return-cylinder settlement', $ledger_group_id]);
                }
            }

            // ── Sync GST to ledger ──
            // Guard: skip if GST already logged in ledger (prevents double logging)
            if ($mark_gst && $gst_rate > 0 && !$isGstAlreadyInLedger) {
                // For credit orders that haven't had GST recorded yet, update the rate
                if (intval($order['is_credit_order']) === 1 && abs(floatval($order['gst_rate']) - $gst_rate) > 0.001) {
                    $pdo->prepare("UPDATE refill_orders SET gst_rate = ? WHERE id = ?")->execute([$gst_rate, $order_id]);
                    $pdo->prepare("UPDATE refill_order_items SET gst_rate = ? WHERE refill_order_id = ? AND gst_rate = 0")->execute([$gst_rate, $order_id]);
                }
                // Record GST on selected cylinders only (not entire order)
                if ($total_service_amount > 0) {
                    $is_intra = true;
                    try {
                        $cust_stmt = $pdo->prepare("SELECT state_code FROM customers WHERE id = ?");
                        $cust_stmt->execute([$customer_id]);
                        $cust_state = intval($cust_stmt->fetchColumn() ?: 0);
                        if ($cust_state > 0) {
                            $filing = getGSTFilingConfig($pdo, $order['business_name'] ?? '');
                            $biz_state = intval($filing['state_code'] ?? 0);
                            if ($biz_state > 0) {
                                $is_intra = isIntraState($cust_state, $biz_state);
                            }
                        }
                    } catch (PDOException $e) { $is_intra = true; }
                    $calc = calculateGST($total_service_amount, $gst_rate, $is_intra);
                    if ($calc['gst_amount'] > 0) {
                        recordOutputGST($pdo, [
                            'entity_id' => $customer_id,
                            'gst_rate' => $gst_rate,
                            'taxable_amount' => $total_service_amount,
                            'gst_amount' => $calc['gst_amount'],
                            'cgst' => $calc['cgst'],
                            'sgst' => $calc['sgst'],
                            'igst' => $calc['igst'],
                            'reference_type' => 'refill_order',
                            'reference_id' => $order_id,
                            'transaction_date' => $payment_date,
                        ]);
                    }
                }
            }

            $pdo->commit();
            syncInventory($pdo);

            $parts = [];
            $parts[] = "Successfully returned $returned_count cylinder(s) to customer.";
            if ($total_collected > 0) {
                $parts[] = "Amount collected: ₹" . number_format($total_collected, 2);
            }
            $parts[] = "Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);
            $_SESSION['success_flash'] = implode(' | ', $parts);
            echo "<script>window.location.href='return-cylinder.php';</script>";
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Return transaction failed: " . $e->getMessage();
        }
    }
}

// ── Consume flash messages ──
if (empty($message) && isset($_SESSION['success_flash'])) {
    $message = $_SESSION['success_flash'];
    unset($_SESSION['success_flash']);
}
if (empty($error) && isset($_SESSION['error_flash'])) {
    $error = $_SESSION['error_flash'];
    unset($_SESSION['error_flash']);
}

// ── Fetch initial data ──
$gas_types = [];
try {
    $gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

$gst_rates = [];
try {
    $gst_rates = $pdo->query("SELECT * FROM gst_rates WHERE is_active = 1 ORDER BY rate_percent")->fetchAll();
} catch (PDOException $e) {}
?>
<style>
:root {
  --rc-accent: #2563eb;
  --rc-accent-soft: #eff6ff;
  --rc-accent-light: rgba(37,99,235,0.08);
  --rc-success: #16a34a;
  --rc-success-soft: #f0fdf4;
  --rc-danger: #dc2626;
  --rc-danger-soft: #fef2f2;
  --rc-warning: #d97706;
  --rc-warning-soft: #fefce8;
  --rc-muted: #64748b;
  --rc-fg: #1e293b;
  --rc-border: #e2e8f0;
  --rc-radius: 12px;
  --rc-radius-sm: 8px;
  --rc-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
  --rc-shadow-lg: 0 4px 16px rgba(0,0,0,0.08);
}

.rc-layout { max-width: 960px; margin: 0 auto; }
.rc-header { margin-bottom: 1.75rem; }
.rc-title { font-size: 1.65rem; font-weight: 800; letter-spacing: -0.02em; color: var(--rc-fg); margin: 0; }
.rc-subtitle { color: var(--rc-muted); font-size: 0.9rem; margin-top: 0.25rem; }

.rc-steps { display: flex; gap: 0; margin-bottom: 2rem; background: var(--admin-surface); border-radius: var(--rc-radius); padding: 0.75rem 1rem; border: 1px solid var(--rc-border); box-shadow: var(--rc-shadow); overflow-x: auto; }
.rc-step { display: flex; align-items: center; gap: 0.5rem; flex: 1; min-width: 0; position: relative; padding: 0.35rem 0.5rem; }
.rc-step:not(:last-child)::after { content: ''; flex: 1; height: 2px; background: var(--rc-border); margin-left: 0.75rem; min-width: 12px; }
.rc-step.active:not(:last-child)::after { background: var(--rc-accent); }
.rc-step.completed:not(:last-child)::after { background: var(--rc-success); }
.rc-step-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; flex-shrink: 0; border: 2px solid var(--rc-border); color: var(--rc-muted); background: #fff; transition: all 0.3s; }
.rc-step.active .rc-step-dot { border-color: var(--rc-accent); background: var(--rc-accent); color: #fff; box-shadow: 0 0 0 4px var(--rc-accent-light); }
.rc-step.completed .rc-step-dot { border-color: var(--rc-success); background: var(--rc-success); color: #fff; }
.rc-step-label { font-size: 0.72rem; font-weight: 600; color: var(--rc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rc-step.active .rc-step-label { color: var(--rc-accent); }
.rc-step.completed .rc-step-label { color: var(--rc-success); }

.rc-alert { padding: 0.85rem 1.15rem; border-radius: var(--rc-radius); margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 0.65rem; font-size: 0.88rem; line-height: 1.5; border-left: 4px solid; }
.rc-alert-success { background: var(--rc-success-soft); color: var(--rc-success); border-color: var(--rc-success); }
.rc-alert-error { background: var(--rc-danger-soft); color: var(--rc-danger); border-color: var(--rc-danger); }

.rc-card { background: var(--admin-surface); border: 1px solid var(--rc-border); border-radius: var(--rc-radius); padding: 1.5rem; margin-bottom: 1.25rem; box-shadow: var(--rc-shadow); transition: box-shadow 0.2s; }
.rc-card:hover { box-shadow: var(--rc-shadow-lg); }
.rc-card-header { display: flex; align-items: center; margin-bottom: 1.15rem; gap: 0.65rem; }
.rc-card-num { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--rc-accent); color: #fff; font-weight: 700; font-size: 0.82rem; flex-shrink: 0; }
.rc-card-title { font-weight: 700; font-size: 1rem; color: var(--rc-fg); margin: 0; }
.rc-card-sub { font-size: 0.75rem; color: var(--rc-muted); font-weight: 400; margin-left: 0.25rem; }
.rc-helper { font-size: 0.78rem; color: var(--rc-muted); line-height: 1.5; margin-bottom: 0.85rem; }

.rc-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.rc-field { margin-bottom: 0; }
.rc-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--rc-fg); margin-bottom: 0.35rem; }
.rc-select, .rc-input, .rc-textarea { width: 100%; padding: 0.55rem 0.75rem; font-size: 0.88rem; border: 1.5px solid var(--rc-border); border-radius: var(--rc-radius-sm); background: #fff; color: var(--rc-fg); transition: all 0.2s; font-family: inherit; }
.rc-select:focus, .rc-input:focus, .rc-textarea:focus { border-color: var(--rc-accent); outline: none; box-shadow: 0 0 0 3px var(--rc-accent-light); }
.rc-select:hover, .rc-input:hover, .rc-textarea:hover { border-color: #94a3b8; }

.rc-cyls-scroll { max-height: 320px; overflow-y: auto; border: 1.5px solid var(--rc-border); border-radius: var(--rc-radius-sm); padding: 0.4rem; scroll-behavior: smooth; }
.rc-cyls-scroll::-webkit-scrollbar { width: 6px; }
.rc-cyls-scroll::-webkit-scrollbar-track { background: transparent; }
.rc-cyls-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
.rc-cyls-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.rc-cyl-item { display: flex; align-items: center; gap: 0.65rem; padding: 0.55rem 0.75rem; border: 1px solid var(--rc-border); border-radius: var(--rc-radius-sm); margin-bottom: 0.35rem; transition: all 0.15s; cursor: pointer; background: #fff; }
.rc-cyl-item:hover { border-color: var(--rc-accent); background: var(--rc-accent-soft); }
.rc-cyl-item:has(input:checked) { border-color: var(--rc-accent); background: var(--rc-accent-soft); }
.rc-cyl-item input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--rc-accent); cursor: pointer; flex-shrink: 0; }
.rc-cyl-serial { font-weight: 700; color: var(--rc-accent); font-size: 0.88rem; }
.rc-cyl-gas { font-weight: 600; font-size: 0.8rem; color: var(--rc-fg); }

.rc-badge { display: inline-block; padding: 0.2rem 0.7rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
.rc-badge-paid { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.rc-badge-partial { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.rc-badge-unpaid { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

.rc-lot-item { display: flex; align-items: center; padding: 1rem; border: 2px solid var(--rc-border); border-radius: var(--rc-radius); margin-bottom: 0.75rem; cursor: pointer; transition: all 0.2s; background: #fff; }
.rc-lot-item:hover { border-color: var(--rc-accent); box-shadow: var(--rc-shadow); }
.rc-lot-item.selected { border-color: var(--rc-accent); background: var(--rc-accent-soft); box-shadow: 0 0 0 3px var(--rc-accent-light); }
.rc-lot-info { flex: 1; min-width: 0; }
.rc-lot-number { font-weight: 700; font-size: 0.95rem; color: var(--rc-accent); }
.rc-lot-meta { font-size: 0.78rem; color: var(--rc-muted); margin-top: 0.2rem; display: flex; gap: 1rem; flex-wrap: wrap; }
.rc-lot-stats { text-align: right; flex-shrink: 0; margin-left: 1rem; }

.rc-summary-card { background: var(--rc-success-soft); border: 2px solid #bbf7d0; border-radius: var(--rc-radius); padding: 1.15rem 1.25rem; margin-bottom: 1rem; }
.rc-summary-line { display: flex; justify-content: space-between; padding: 0.3rem 0; font-size: 0.85rem; color: var(--rc-fg); }
.rc-summary-divider { border-top: 1px solid #d1fae5; margin: 0.25rem 0; }
.rc-summary-total { font-weight: 700; font-size: 1.05rem; border-top: 2px solid #a7f3d0; padding-top: 0.5rem; margin-top: 0.25rem; }

.rc-pay-section { background: #fefce8; border: 2px solid #fde68a; border-radius: var(--rc-radius); padding: 1rem; margin-bottom: 0.75rem; }
.rc-select-all { display: flex; align-items: center; gap: 0.35rem; font-size: 0.82rem; font-weight: 600; color: var(--rc-muted); cursor: pointer; }

.rc-input.is-invalid, .rc-select.is-invalid { border-color: var(--rc-danger) !important; background-color: var(--rc-danger-soft) !important; }
.rc-field-error { color: var(--rc-danger); font-size: 0.75rem; margin-top: 0.2rem; display: block; }

/* Customer combobox */
.rc-combobox { position: relative; }
.rc-combobox input { padding-right: 2.5rem; }
.rc-combobox-dropdown { display: none; position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--rc-border); border-radius: 10px; max-height: 260px; overflow-y: auto; z-index: 999; box-shadow: 0 10px 25px rgba(0,0,0,0.08); margin-top: 4px; }
.rc-combobox-item { padding: 0.6rem 1rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: background 0.1s; }
.rc-combobox-item:hover, .rc-combobox-item.highlighted { background: var(--rc-accent-soft); }
.rc-combobox-item-name { font-weight: 600; color: var(--rc-fg); font-size: 0.88rem; }
.rc-combobox-item-mobile { color: var(--rc-muted); font-size: 0.8rem; }
.rc-combobox-empty { padding: 1.5rem; text-align: center; color: var(--rc-muted); font-size: 0.85rem; }

.cyls-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 0.5rem; margin-bottom: 1rem; }
.cyls-summary-stat { text-align: center; padding: 0.5rem; background: #f8fafc; border-radius: var(--rc-radius-sm); border: 1px solid var(--rc-border); }
.cyls-summary-val { font-size: 1.25rem; font-weight: 800; color: var(--rc-fg); }
.cyls-summary-label { font-size: 0.6rem; color: var(--rc-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

.gst-locked-badge { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 6px; padding: 0.25rem 0.75rem; font-size: 0.75rem; font-weight: 700; }

/* ── Paid notice read-only card ── */
.rc-paid-notice { background: #f0fdf4; border: 2px solid #bbf7d0; border-radius: var(--rc-radius); padding: 1rem 1.15rem; margin-bottom: 0.75rem; display: flex; align-items: flex-start; gap: 0.75rem; }
.rc-paid-notice-icon { flex-shrink: 0; width: 22px; height: 22px; color: #16a34a; }
.rc-paid-notice-content { flex: 1; }
.rc-paid-notice-title { font-weight: 700; color: #065f46; font-size: 0.9rem; }
.rc-paid-notice-detail { color: #374151; font-size: 0.82rem; margin-top: 0.15rem; }
.rc-paid-notice-badge { display: inline-block; margin-left: 0.5rem; }

/* ── Skeleton loading ── */
@keyframes rc-shimmer { 0% { background-position: -200px 0; } 100% { background-position: calc(200px + 100%) 0; } }
.rc-skeleton { background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200px 100%; animation: rc-shimmer 1.5s infinite; border-radius: 6px; }
.rc-skeleton-line { height: 14px; margin-bottom: 10px; width: 100%; }
.rc-skeleton-line.w60 { width: 60%; }
.rc-skeleton-line.w40 { width: 40%; }
.rc-skeleton-line.h32 { height: 32px; }

/* ── Confirmation modal ── */
.rc-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(2px); z-index: 9999; justify-content: center; align-items: center; padding: 1rem; }
.rc-modal-overlay.open { display: flex; }
.rc-modal { background: #fff; border-radius: 16px; width: 100%; max-width: 540px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.15); animation: rc-modal-in 0.25s ease-out; }
@keyframes rc-modal-in { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.rc-modal-header { padding: 1.25rem 1.5rem 0.75rem; border-bottom: 1px solid var(--rc-border); display: flex; justify-content: space-between; align-items: center; }
.rc-modal-title { font-size: 1.1rem; font-weight: 700; color: var(--rc-fg); margin: 0; }
.rc-modal-close { background: none; border: none; font-size: 1.25rem; color: var(--rc-muted); cursor: pointer; padding: 0.25rem; line-height: 1; }
.rc-modal-close:hover { color: var(--rc-fg); }
.rc-modal-body { padding: 1.25rem 1.5rem; }
.rc-modal-footer { padding: 0.75rem 1.5rem 1.25rem; border-top: 1px solid var(--rc-border); display: flex; gap: 0.75rem; justify-content: flex-end; }
.rc-modal-btn { padding: 0.6rem 1.25rem; border-radius: 10px; font-weight: 700; font-size: 0.88rem; cursor: pointer; border: none; transition: all 0.15s; }
.rc-modal-btn-secondary { background: #f1f5f9; color: var(--rc-fg); }
.rc-modal-btn-secondary:hover { background: #e2e8f0; }
.rc-modal-btn-primary { background: var(--rc-accent); color: #fff; }
.rc-modal-btn-primary:hover { background: #1d4ed8; }
.rc-modal-list { margin: 0; padding: 0; list-style: none; }
.rc-modal-list li { padding: 0.4rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.84rem; display: flex; justify-content: space-between; align-items: center; }
.rc-modal-list li:last-child { border-bottom: none; }
.rc-modal-line { display: flex; justify-content: space-between; padding: 0.35rem 0; font-size: 0.85rem; }
.rc-modal-divider { border-top: 1px solid var(--rc-border); margin: 0.25rem 0; }
.rc-modal-total { font-weight: 700; font-size: 0.95rem; border-top: 2px solid var(--rc-accent); padding-top: 0.5rem; margin-top: 0.25rem; }

/* ── Step clickability ── */
.rc-step { cursor: pointer; }
.rc-step:hover .rc-step-dot { box-shadow: 0 0 0 4px var(--rc-accent-light); }
.rc-step.completed:hover .rc-step-dot { box-shadow: 0 0 0 4px rgba(22,163,74,0.15); }
.rc-step.active:hover .rc-step-dot { box-shadow: 0 0 0 6px var(--rc-accent-light); }

/* ── Selected vs invoice total display ── */
.rc-invoice-ref { font-size: 0.72rem; color: var(--rc-muted); display: block; margin-top: 0.1rem; }

/* ── Live total animation ── */
.rc-total-update { transition: all 0.2s; }

@media (max-width: 640px) {
  .rc-row { grid-template-columns: 1fr; }
  .rc-steps { padding: 0.5rem; gap: 0; }
  .rc-step-label { display: none; }
  .rc-step:not(:last-child)::after { min-width: 8px; }
}
</style>

<div class="rc-layout" id="returnLayout">
    <div class="rc-header">
        <h1 class="rc-title">Return Cylinders to Customer</h1>
        <p class="rc-subtitle">Select a customer and return their refilled CUS-R cylinders. Record payment and GST.</p>
    </div>

    <div class="rc-steps" id="rcSteps">
        <div class="rc-step active" data-step="1">
            <span class="rc-step-dot">1</span>
            <span class="rc-step-label">Customer</span>
        </div>
        <div class="rc-step" data-step="2">
            <span class="rc-step-dot">2</span>
            <span class="rc-step-label">Lot</span>
        </div>
        <div class="rc-step" data-step="3">
            <span class="rc-step-dot">3</span>
            <span class="rc-step-label">Cylinders</span>
        </div>
        <div class="rc-step" data-step="4">
            <span class="rc-step-dot">4</span>
            <span class="rc-step-label">Payment &amp; Return</span>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="rc-alert rc-alert-success">
        <svg class="rc-alert-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="rc-alert rc-alert-error">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" id="returnForm">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="return_cylinders">

        <!-- Step 1: Customer Selection -->
        <div class="rc-card rc-step-1">
            <div class="rc-card-header">
                <span class="rc-card-num">1</span>
                <h3 class="rc-card-title">Select Customer</h3>
                <span class="rc-card-sub">— only customers with filled CUS-R cylinders are shown</span>
            </div>
            <div class="rc-field">
                <label class="rc-label">Customer</label>
                <div class="rc-combobox" id="customerCombobox">
                    <input type="text" id="customerSearchInput" class="rc-input" autocomplete="off"
                           placeholder="Type name or phone to search & select..."
                           onfocus="showCustomerDropdown()" onkeyup="filterCustomerDropdown()">
                    <input type="hidden" name="customer_id" id="customerIdInput" value="">
                    <div class="rc-combobox-dropdown" id="customerDropdown">
                        <div id="customerDropdownList"></div>
                        <div id="customerDropdownEmpty" class="rc-combobox-empty" style="display:none;">No eligible customers found</div>
                    </div>
                </div>
                <p class="rc-helper">Only customers with filled CUS-R (Customer Refill Service) cylinders ready for return are shown.</p>
            </div>
        </div>

        <!-- Step 2: Order/Lot Selection -->
        <div class="rc-card rc-step-2" id="lotSection" style="display:none;">
            <div class="rc-card-header">
                <span class="rc-card-num">2</span>
                <h3 class="rc-card-title">Select Lot (Order)</h3>
            </div>
            <p class="rc-helper">Select the order containing the cylinders to return. Each lot represents a previous refill order.</p>
            <div id="lotList">
                <p style="text-align:center;padding:2rem 0;color:var(--rc-muted);font-size:0.9rem;">Select a customer first to load their orders.</p>
            </div>
            <input type="hidden" name="order_id" id="selectedOrderId" value="">
        </div>

        <!-- Order Summary (shown after lot selected) -->
        <div id="orderSummaryCard" class="rc-summary-card" style="display:none;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                <h4 style="margin:0;font-weight:800;color:#1e40af;" id="orderSummaryTitle">Order #—</h4>
                <span id="orderSummaryPayBadge" class="rc-badge rc-badge-unpaid">—</span>
            </div>
            <div class="rc-summary-line"><span>Order Date</span><span id="orderSummaryDate" style="font-weight:600;">—</span></div>
            <div class="rc-summary-line"><span>Invoice</span><span id="orderSummaryInvoice" style="font-weight:600;">—</span></div>
            <div class="rc-summary-divider"></div>
            <div class="rc-summary-line"><span>Subtotal</span><span id="orderSummarySubtotal">₹0.00</span></div>
            <div class="rc-summary-line"><span>GST Rate</span><span id="orderSummaryGstRate">0%</span></div>
            <div class="rc-summary-line"><span>GST Amount</span><span id="orderSummaryGstAmt">₹0.00</span></div>
            <div class="rc-summary-line rc-summary-total"><span>Grand Total</span><span id="orderSummaryTotal">₹0.00</span></div>
            <div class="rc-summary-divider"></div>
            <div class="rc-summary-line"><span>Payment Method</span><span id="orderSummaryPayMethod" style="font-weight:600;">—</span></div>
            <div class="rc-summary-line" id="orderSummaryAdvanceLine" style="display:none;"><span style="color:#92400e;">Advance Used</span><span id="orderSummaryAdvance" style="color:#92400e;font-weight:600;">₹0.00</span></div>
        </div>

        <!-- Step 3: Cylinder Selection -->
        <div class="rc-card rc-step-3" id="cylinderSection" style="display:none;">
            <div class="rc-card-header">
                <span class="rc-card-num">3</span>
                <h3 class="rc-card-title">Select Cylinders to Return</h3>
            </div>
            <p class="rc-helper">Check the filled CUS-R cylinders to return to the customer. Supports partial returns.</p>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                <span id="cylinderSelectedCount" style="font-size:0.85rem;font-weight:600;color:var(--rc-muted);">0 cylinders selected</span>
            </div>
            <div class="rc-cyls-scroll" id="cylinderList">
                <p style="text-align:center;padding:2rem 0;color:var(--rc-muted);font-size:0.9rem;">Select a lot above to view cylinders.</p>
            </div>
        </div>

        <!-- Step 4: Payment & Return -->
        <div class="rc-card rc-step-4" id="paymentSection" style="display:none;">
            <div class="rc-card-header">
                <span class="rc-card-num">4</span>
                <h3 class="rc-card-title">Payment &amp; Return</h3>
                <span class="rc-card-sub">— record payment and GST for returned cylinders</span>
            </div>

            <!-- GST Section -->
            <div class="rc-pay-section" style="background:#f0f7ff;border-color:#bfdbfe;">
                <div style="font-weight:700;font-size:0.85rem;color:#1e40af;margin-bottom:0.75rem;">GST Recording</div>
                <div id="gstLockedDisplay" style="display:none;">
                    <div class="rc-row">
                        <div class="rc-field">
                            <span class="rc-label">GST Rate <span class="gst-locked-badge">Locked</span></span>
                            <span id="gstLockedRate" style="font-weight:700;font-size:1rem;">0%</span>
                            <p class="rc-helper">GST was already recorded for this order at creation time.</p>
                        </div>
                        <div class="rc-field">
                            <span class="rc-label">GST in Ledger</span>
                            <span id="gstLedgerStatus" style="font-weight:700;color:#16a34a;">✓ Synced</span>
                        </div>
                    </div>
                </div>
                <div id="gstEditableDisplay" style="display:none;">
                    <div class="rc-row">
                        <div class="rc-field">
                            <label class="rc-label">GST Rate (Credit Order)</label>
                            <select id="gstRateSelect" class="rc-select" onchange="updatePaymentSummary()">
                                <option value="0">No GST (0%)</option>
                                <?php foreach ($gst_rates as $gr): ?>
                                <option value="<?php echo floatval($gr['rate_percent']); ?>" <?php echo floatval($gr['rate_percent']) === 18.0 ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gr['label']); ?> (<?php echo floatval($gr['rate_percent']); ?>%)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="rc-helper">This order was on credit. Record GST at this rate when returning.</p>
                        </div>
                        <div class="rc-field">
                            <label class="rc-label">GST Amount</label>
                            <span id="gstCalculatedAmount" style="font-weight:700;font-size:1.1rem;color:var(--rc-accent);">₹0.00</span>
                            <input type="hidden" name="gst_rate" id="gstRateInput" value="0">
                        </div>
                    </div>
                    <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.5rem;font-size:0.85rem;cursor:pointer;">
                        <input type="checkbox" name="mark_gst" value="1" checked onchange="toggleGstSync(this)">
                        <strong>Record output GST in ledger</strong>
                    </label>
                </div>
                <!-- Locked ledger badge (shown when gst_in_ledger + paid) -->
                <div id="gstLedgerLockedNotice" style="display:none;background:#dbeafe;border-radius:8px;padding:0.75rem 1rem;border:1px solid #bfdbfe;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span style="font-weight:700;color:#1e40af;font-size:0.85rem;">GST already recorded in ledger</span>
                    </div>
                    <p class="rc-helper" style="margin:0.25rem 0 0;color:#1e40af;">GST entries exist for this order. No changes will be made to GST ledger.</p>
                </div>
            </div>

            <!-- Final Summary -->
            <div class="rc-summary-card" id="returnSummaryBar">
                <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:0.5rem;">
                    <div>
                        <div class="rc-summary-line" style="padding:0.15rem 0;font-size:0.82rem;">
                            <span>Service Charge</span>
                            <span id="sumServiceCharge" style="font-weight:600;">₹0.00</span>
                        </div>
                        <div class="rc-summary-line" style="padding:0.15rem 0;font-size:0.82rem;">
                            <span>GST Amount</span>
                            <span id="sumGstAmount" style="font-weight:600;">₹0.00</span>
                        </div>
                        <div class="rc-summary-line" id="sumInvoiceTotalLine" style="display:none;padding:0.15rem 0;font-size:0.82rem;">
                            <span style="color:var(--rc-muted);">Invoice Total</span>
                            <span id="sumInvoiceTotal" style="color:var(--rc-muted);font-weight:600;">₹0.00</span>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:0.72rem;color:var(--rc-muted);font-weight:600;">TOTAL DUE</div>
                        <div id="sumTotalDue" style="font-size:1.4rem;font-weight:800;color:var(--rc-fg);">₹0.00</div>
                    </div>
                </div>
                <div style="margin-top:0.6rem;padding-top:0.6rem;border-top:1px dashed #a7f3d0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.8rem;color:#92400e;">
                        <span>Advance Used</span>
                        <span id="sumAdvanceUsed" style="font-weight:700;">−₹0.00</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.8rem;color:#1e40af;margin-top:0.2rem;">
                        <span>Deposit Used</span>
                        <span id="sumDepositUsed" style="font-weight:700;">−₹0.00</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.85rem;color:#16a34a;font-weight:700;margin-top:0.35rem;padding-top:0.35rem;border-top:1px solid #a7f3d0;">
                        <span>Amount Collecting</span>
                        <span id="sumAmountCollecting">₹0.00</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.78rem;margin-top:0.25rem;padding-top:0.3rem;border-top:1px dashed #d1fae5;">
                        <span style="color:var(--rc-muted);">Remaining</span>
                        <span id="sumRemaining" style="font-weight:700;">₹0.00</span>
                    </div>
                </div>
            </div>

            <!-- Payment Section -->
            <div class="rc-pay-section" id="paymentSectionBox" style="background:#fff;border:2px solid var(--rc-border);position:relative;">
                <div style="font-weight:700;font-size:0.85rem;color:var(--rc-fg);margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M8 10l4-4 4 4"/></svg>
                    Collect Payment
                </div>

                <!-- Paid notice (shown when order is fully paid) -->
                <div id="paidNoticeCard" class="rc-paid-notice" style="display:none;">
                    <svg class="rc-paid-notice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <div class="rc-paid-notice-content">
                        <div class="rc-paid-notice-title">Payment Already Settled</div>
                        <div class="rc-paid-notice-detail">This order is fully paid via <strong id="paidNoticeMethod">—</strong> on <strong id="paidNoticeDate">—</strong>. No additional collection needed.</div>
                    </div>
                </div>

                <div id="paymentFieldsGroup">
                    <!-- Hero amount to collect -->
                    <div style="text-align:center;padding:0.75rem 0 1rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:var(--rc-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">Amount to Collect</label>
                        <div style="display:flex;align-items:center;justify-content:center;gap:0.25rem;">
                            <span style="font-size:1.6rem;font-weight:800;color:var(--rc-accent);">₹</span>
                            <input type="number" name="payment_amount" id="paymentAmountInput"
                                   style="width:200px;padding:0.5rem 0.75rem;font-size:1.6rem;font-weight:800;text-align:center;border:2px solid var(--rc-accent);border-radius:12px;background:var(--rc-accent-soft);color:var(--rc-fg);outline:none;font-family:inherit;"
                                   value="0.00" min="0" step="0.01"
                                   oninput="updatePaymentSummary('payment_amount')"
                                   onfocus="this.select()">
                        </div>
                        <div style="font-size:0.72rem;color:var(--rc-muted);margin-top:0.35rem;">
                            Auto-calculated from total due minus advance &amp; deposit
                        </div>
                    </div>

                    <div class="rc-row" style="grid-template-columns:1fr 1fr 1fr;gap:0.75rem;margin-top:0.5rem;">
                        <div class="rc-field">
                            <label class="rc-label" style="font-size:0.75rem;">Payment Method</label>
                            <select name="payment_method" class="rc-select" style="font-size:0.82rem;">
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="NEFT">NEFT</option>
                                <option value="RTGS">RTGS</option>
                            </select>
                        </div>
                        <div class="rc-field">
                            <label class="rc-label" style="font-size:0.75rem;">Payment Date</label>
                            <input type="datetime-local" name="payment_date" class="rc-input" style="font-size:0.82rem;" value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                        <div class="rc-field">
                            <label class="rc-label" style="font-size:0.75rem;">Internal Notes</label>
                            <textarea name="notes" class="rc-textarea" rows="1" style="font-size:0.82rem;resize:none;" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>

                    <!-- Advance & Deposit as deductions -->
                    <div style="margin-top:1rem;padding-top:0.75rem;border-top:1px dashed var(--rc-border);">
                        <div style="font-size:0.72rem;font-weight:600;color:var(--rc-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.6rem;">Apply Deductions</div>
                        <div class="rc-row" style="grid-template-columns:1fr 1fr;gap:0.75rem;">
                            <div class="rc-field" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:0.6rem 0.75rem;">
                                <label class="rc-label" style="display:flex;justify-content:space-between;font-size:0.75rem;color:#92400e;margin-bottom:0.35rem;">
                                    <span>Advance to Use</span>
                                    <span style="font-weight:400;color:var(--rc-muted);">Avail: <strong id="advanceAvailableLabel" style="color:#92400e;">₹0.00</strong></span>
                                </label>
                                <div style="display:flex;align-items:center;gap:0.35rem;">
                                    <span style="font-weight:700;color:#92400e;font-size:0.9rem;">−</span>
                                    <input type="number" name="advance_used" id="advanceUsedInput" style="flex:1;padding:0.4rem 0.5rem;font-size:0.9rem;font-weight:600;text-align:right;border:1.5px solid #fde68a;border-radius:8px;background:#fff;color:#92400e;outline:none;font-family:inherit;" value="0.00" min="0" step="0.01" oninput="updatePaymentSummary('advance')">
                                </div>
                            </div>
                            <div class="rc-field" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:0.6rem 0.75rem;">
                                <label class="rc-label" style="display:flex;justify-content:space-between;font-size:0.75rem;color:#1e40af;margin-bottom:0.35rem;">
                                    <span>Deposit to Use</span>
                                    <span style="font-weight:400;color:var(--rc-muted);">Avail: <strong id="depositAvailableLabel" style="color:#1e40af;">₹0.00</strong></span>
                                </label>
                                <div style="display:flex;align-items:center;gap:0.35rem;">
                                    <span style="font-weight:700;color:#1e40af;font-size:0.9rem;">−</span>
                                    <input type="number" name="deposit_used" id="depositUsedInput" style="flex:1;padding:0.4rem 0.5rem;font-size:0.9rem;font-weight:600;text-align:right;border:1.5px solid #bfdbfe;border-radius:8px;background:#fff;color:#1e40af;outline:none;font-family:inherit;" value="0.00" min="0" step="0.01" oninput="updatePaymentSummary('deposit')">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" id="returnSubmitBtn" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;padding:0.75rem;font-size:1rem;border:none;border-radius:10px;background:var(--rc-accent);color:#fff;font-weight:700;cursor:pointer;transition:all 0.2s;" disabled onclick="showReturnConfirmModal()">
                Return Cylinders to Customer
            </button>
        </div>
    </form>
</div>

<!-- Confirmation Modal -->
<div class="rc-modal-overlay" id="confirmModal">
    <div class="rc-modal">
        <div class="rc-modal-header">
            <h3 class="rc-modal-title">Confirm Cylinder Return</h3>
            <button class="rc-modal-close" onclick="hideReturnConfirmModal()">&times;</button>
        </div>
        <div class="rc-modal-body">
            <div id="confirmModalBody">
                <div class="rc-modal-line"><span>Customer</span><span id="confirmCustomer" style="font-weight:600;">—</span></div>
                <div class="rc-modal-line"><span>Order</span><span id="confirmOrder" style="font-weight:600;">—</span></div>
                <div class="rc-modal-divider"></div>
                <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.5rem;">Cylinders to Return</div>
                <ul class="rc-modal-list" id="confirmCylinderList"></ul>
                <div class="rc-modal-divider"></div>
                <div class="rc-modal-line"><span>Service Charge</span><span id="confirmServiceCharge" style="font-weight:600;">₹0.00</span></div>
                <div class="rc-modal-line"><span>GST</span><span id="confirmGst">₹0.00</span></div>
                <div class="rc-modal-total">
                    <span>Total Due</span>
                    <span id="confirmTotalDue">₹0.00</span>
                </div>
                <div class="rc-modal-divider"></div>
                <div id="confirmPaymentSection" style="display:none;">
                    <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.35rem;">Payment to Collect</div>
                    <div class="rc-modal-line"><span>Advance Used</span><span id="confirmAdvance">−₹0.00</span></div>
                    <div class="rc-modal-line"><span>Deposit Used</span><span id="confirmDeposit">−₹0.00</span></div>
                    <div class="rc-modal-line"><span>Cash/UPI to Collect</span><span id="confirmAmountCollect" style="color:#16a34a;">₹0.00</span></div>
                </div>
                <div id="confirmPaidNotice" style="display:none;background:#f0fdf4;border-radius:8px;padding:0.55rem 0.75rem;margin-top:0.5rem;">
                    <span style="font-size:0.82rem;color:#065f46;font-weight:600;">✓ Order already paid — only cylinder status will be updated</span>
                </div>
            </div>
        </div>
        <div class="rc-modal-footer">
            <button class="rc-modal-btn rc-modal-btn-secondary" onclick="hideReturnConfirmModal()">Cancel</button>
            <button class="rc-modal-btn rc-modal-btn-primary" id="confirmSubmitBtn" onclick="submitReturnForm()">Confirm Return</button>
        </div>
    </div>
</div>

<script>
const SVG_ICONS = {
    calendar: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    receipt: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1-2-1z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',
    rupee: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="3" x2="20" y2="3"/><path d="M6 8h6a4 4 0 0 1 0 8H6l6 5"/></svg>',
    cylinder: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a8 8 0 0 0-8 8v4a8 8 0 0 0 16 0v-4a8 8 0 0 0-8-8z"/><line x1="4" y1="14" x2="20" y2="14"/></svg>',
};

let customersData = [];
let selectedCustomerId = 0;
let selectedOrderData = null;
let ordersData = [];
let cylindersData = [];
let comboHighlightIndex = -1;

// ── Customer Combobox ──
function loadEligibleCustomers() {
    fetch('get-customer-return-data.php?action=customers')
        .then(r => r.json())
        .then(data => {
            customersData = data || [];
            renderCustomerDropdown();
        })
        .catch(() => { customersData = []; });
}

function renderCustomerDropdown(filterVal) {
    const list = document.getElementById('customerDropdownList');
    const empty = document.getElementById('customerDropdownEmpty');
    const input = filterVal !== undefined ? filterVal : document.getElementById('customerSearchInput').value.toLowerCase();

    const filtered = customersData.filter(c =>
        c.name.toLowerCase().includes(input) || c.mobile.includes(input)
    );

    list.innerHTML = '';
    if (filtered.length === 0) {
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';
    comboHighlightIndex = -1;
    filtered.forEach((c, i) => {
        const div = document.createElement('div');
        div.className = 'rc-combobox-item';
        div.dataset.index = i;
        div.innerHTML = `<span class="rc-combobox-item-name">${escapeHtml(c.name)}</span><span class="rc-combobox-item-mobile">${escapeHtml(c.mobile)}</span>`;
        div.onclick = function() { selectCustomer(c); };
        div.onmouseenter = function() { highlightComboItem(i); };
        list.appendChild(div);
    });
}

function highlightComboItem(idx) {
    comboHighlightIndex = idx;
    document.querySelectorAll('.rc-combobox-item').forEach((el, i) => {
        el.classList.toggle('highlighted', i === idx);
    });
}

function selectCustomer(c) {
    selectedCustomerId = parseInt(c.id);
    document.getElementById('customerSearchInput').value = c.name + ' (' + c.mobile + ')';
    document.getElementById('customerIdInput').value = c.id;
    document.getElementById('customerDropdown').style.display = 'none';
    comboHighlightIndex = -1;

    // Reset downstream sections
    document.getElementById('lotSection').style.display = 'none';
    document.getElementById('cylinderSection').style.display = 'none';
    document.getElementById('paymentSection').style.display = 'none';
    document.getElementById('orderSummaryCard').style.display = 'none';

    // Load orders for this customer
    loadOrdersForCustomer(c.id);
}

function showCustomerDropdown() {
    const dd = document.getElementById('customerDropdown');
    dd.style.display = 'block';
    renderCustomerDropdown();
}

function filterCustomerDropdown() {
    document.getElementById('customerDropdown').style.display = 'block';
    renderCustomerDropdown(document.getElementById('customerSearchInput').value.toLowerCase());
}

// Hide dropdown on click outside
document.addEventListener('click', function(e) {
    const combo = document.getElementById('customerCombobox');
    if (!combo.contains(e.target)) {
        document.getElementById('customerDropdown').style.display = 'none';
    }
});

// Keyboard navigation for combobox
document.getElementById('customerSearchInput').addEventListener('keydown', function(e) {
    const items = document.querySelectorAll('.rc-combobox-item');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        const next = comboHighlightIndex < items.length - 1 ? comboHighlightIndex + 1 : 0;
        highlightComboItem(next);
        items[next]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        const prev = comboHighlightIndex > 0 ? comboHighlightIndex - 1 : items.length - 1;
        highlightComboItem(prev);
        items[prev]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (comboHighlightIndex >= 0 && comboHighlightIndex < customersData.length) {
            const filtered = customersData.filter(c =>
                c.name.toLowerCase().includes(this.value.toLowerCase()) || c.mobile.includes(this.value)
            );
            if (filtered[comboHighlightIndex]) {
                selectCustomer(filtered[comboHighlightIndex]);
            }
        }
    } else if (e.key === 'Escape') {
        document.getElementById('customerDropdown').style.display = 'none';
    }
});

// ── Skeleton helpers ──
function showSkeleton(containerId, lines) {
    const container = document.getElementById(containerId);
    let html = '';
    for (let i = 0; i < (lines || 3); i++) {
        html += '<div class="rc-skeleton rc-skeleton-line ' + (i === 0 ? 'w60' : i === 1 ? 'w40' : '') + '"></div>';
    }
    container.innerHTML = html;
}

// ── Load Orders (Lots) ──
function loadOrdersForCustomer(customerId) {
    showSkeleton('lotList', 3);
    document.getElementById('lotSection').style.display = 'block';
    updateStepProgress();

    fetch('get-customer-return-data.php?action=orders&customer_id=' + customerId)
        .then(r => r.json())
        .then(data => {
            ordersData = data || [];
            renderLotList();
        })
        .catch(() => {
            document.getElementById('lotList').innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--rc-muted);font-size:0.9rem;">Error loading orders.</p>';
        });
}

function renderLotList() {
    const container = document.getElementById('lotList');
    if (ordersData.length === 0) {
        container.innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--rc-muted);font-size:0.9rem;">No returnable orders found for this customer.</p>';
        return;
    }

    let html = '';
    ordersData.forEach((ord, idx) => {
        const payClass = ord.payment_status === 'paid' ? 'rc-badge-paid' : (ord.payment_status === 'partial' ? 'rc-badge-partial' : 'rc-badge-unpaid');
        const payLabel = ord.payment_status.charAt(0).toUpperCase() + ord.payment_status.slice(1);
        html += `<div class="rc-lot-item" data-index="${idx}" onclick="selectLot(${idx})">
            <div class="rc-lot-info">
                <div class="rc-lot-number">#ORD-${String(ord.order_id).padStart(4, '0')}</div>
                <div class="rc-lot-meta">
                    <span>${SVG_ICONS.calendar} ${ord.order_date || '—'}</span>
                    <span>${SVG_ICONS.receipt} ${ord.invoice_number || '—'}</span>
                    <span>${SVG_ICONS.rupee} ₹${parseFloat(ord.total_service_charge || 0).toFixed(2)}</span>
                </div>
            </div>
            <div class="rc-lot-stats">
                <div style="font-size:1.25rem;font-weight:800;">${ord.returnable_count}</div>
                <div style="font-size:0.65rem;color:var(--rc-muted);">cylinders</div>
                <span class="rc-badge ${payClass}" style="margin-top:0.25rem;">${payLabel}</span>
            </div>
        </div>`;
    });
    container.innerHTML = html;
}

// ── Step clickability ──
document.querySelectorAll('.rc-step').forEach(function(step) {
    step.addEventListener('click', function() {
        const stepNum = parseInt(this.dataset.step);
        if (stepNum === 1) {
            // Scroll to top and focus customer search
            document.querySelector('.rc-layout').scrollIntoView({ behavior: 'smooth' });
            setTimeout(() => document.getElementById('customerSearchInput').focus(), 300);
        } else if (stepNum === 2 && selectedCustomerId > 0) {
            document.getElementById('lotSection').scrollIntoView({ behavior: 'smooth' });
        } else if (stepNum === 3 && selectedOrderData) {
            document.getElementById('cylinderSection').scrollIntoView({ behavior: 'smooth' });
        }
    });
});

// ── Select Lot ──
function selectLot(idx) {
    const ord = ordersData[idx];
    if (!ord) return;

    selectedOrderData = ord;
    document.getElementById('selectedOrderId').value = ord.order_id;

    // Highlight selected lot
    document.querySelectorAll('.rc-lot-item').forEach(el => el.classList.remove('selected'));
    document.querySelector(`.rc-lot-item[data-index="${idx}"]`)?.classList.add('selected');

    // Show order summary
    showOrderSummary(ord);

    // Load cylinders for this order
    loadCylindersForOrder(ord.order_id);
}

function showOrderSummary(ord) {
    const card = document.getElementById('orderSummaryCard');
    card.style.display = 'block';

    document.getElementById('orderSummaryTitle').textContent = 'Order #ORD-' + String(ord.order_id).padStart(4, '0');
    document.getElementById('orderSummaryDate').textContent = ord.order_date || '—';
    document.getElementById('orderSummaryInvoice').textContent = ord.invoice_number || '—';
    document.getElementById('orderSummarySubtotal').textContent = '₹' + parseFloat(ord.subtotal || 0).toFixed(2);
    document.getElementById('orderSummaryGstRate').textContent = parseFloat(ord.gst_rate || 0) + '%';
    document.getElementById('orderSummaryGstAmt').textContent = '₹' + parseFloat(ord.tax_amount || 0).toFixed(2);
    document.getElementById('orderSummaryTotal').textContent = '₹' + parseFloat(ord.grand_total || 0).toFixed(2);
    document.getElementById('orderSummaryPayMethod').textContent = ord.payment_method || '—';

    const advanceLine = document.getElementById('orderSummaryAdvanceLine');
    const advanceVal = parseFloat(ord.advance_used || 0);
    if (advanceVal > 0) {
        advanceLine.style.display = '';
        document.getElementById('orderSummaryAdvance').textContent = '₹' + advanceVal.toFixed(2);
    } else {
        advanceLine.style.display = 'none';
    }

    const payBadge = document.getElementById('orderSummaryPayBadge');
    if (ord.payment_status === 'paid') {
        payBadge.className = 'rc-badge rc-badge-paid';
        payBadge.textContent = 'Paid';
    } else if (ord.payment_status === 'partial') {
        payBadge.className = 'rc-badge rc-badge-partial';
        payBadge.textContent = 'Partial';
    } else {
        payBadge.className = 'rc-badge rc-badge-unpaid';
        payBadge.textContent = 'Unpaid';
    }
}

function loadCylindersForOrder(orderId) {
    showSkeleton('cylinderList', 4);
    document.getElementById('cylinderSection').style.display = 'block';

    fetch('get-customer-return-data.php?action=cylinders&order_id=' + orderId)
        .then(r => r.json())
        .then(data => {
            cylindersData = data || [];
            renderCylinderList();
            // Setup GST and payment sections
            setupGstSection(selectedOrderData);
            setupPaymentSection(selectedOrderData);
            document.getElementById('paymentSection').style.display = 'block';
            updateStepProgress();
        })
        .catch(() => {
            document.getElementById('cylinderList').innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--rc-muted);font-size:0.9rem;">Error loading cylinders.</p>';
        });
}

function renderCylinderList() {
    const container = document.getElementById('cylinderList');
    if (cylindersData.length === 0) {
        container.innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--rc-muted);font-size:0.9rem;">No returnable cylinders in this order.</p>';
        return;
    }

    let html = '<label class="rc-select-all" style="padding:0 0.5rem 0.5rem;"><input type="checkbox" id="selectAllCylinders" onchange="toggleSelectAllCylinders(this)"> Select All</label>';
    cylindersData.forEach(c => {
        html += `<label class="rc-cyl-item" data-gas="${c.gas_type_id}">
            <input type="checkbox" name="service_ids[]" value="${c.service_id}" onchange="onCylinderCheckChange()">
            <span style="flex:1;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <span class="rc-cyl-serial">${escapeHtml(c.serial_number)}</span>
                <span class="rc-cyl-gas">${escapeHtml(c.gas_name)} — ${escapeHtml(c.size_capacity)}</span>
            </span>
            <span style="font-weight:700;font-size:0.85rem;color:var(--rc-fg);">₹${parseFloat(c.service_charge || 0).toFixed(2)}</span>
        </label>`;
    });
    container.innerHTML = html;
    updateCylinderCount();
}

function toggleSelectAllCylinders(chk) {
    document.querySelectorAll('#cylinderList .rc-cyl-item input[type="checkbox"]').forEach(cb => {
        cb.checked = chk.checked;
    });
    onCylinderCheckChange();
}

function onCylinderCheckChange() {
    updateCylinderCount();
    updatePaymentSummary();
}

function updateCylinderCount() {
    const checked = document.querySelectorAll('#cylinderList .rc-cyl-item input[type="checkbox"]:checked');
    document.getElementById('cylinderSelectedCount').textContent = checked.length + ' cylinders selected';
}

function getSelectedServiceIds() {
    return Array.from(document.querySelectorAll('#cylinderList .rc-cyl-item input[type="checkbox"]:checked')).map(cb => parseInt(cb.value));
}

function getSelectedServiceCharge() {
    let total = 0;
    document.querySelectorAll('#cylinderList .rc-cyl-item input[type="checkbox"]:checked').forEach(cb => {
        const label = cb.closest('.rc-cyl-item');
        const spans = label.querySelectorAll(':scope > span');
        const priceSpan = spans[spans.length - 1];
        const priceText = priceSpan?.textContent || '0';
        total += parseFloat(priceText.replace('₹', '')) || 0;
    });
    return total;
}

// ── GST Setup ──
function setupGstSection(ord) {
    if (!ord) return;
    const isCredit = parseInt(ord.is_credit_order) === 1;
    const gstInLedger = ord.gst_in_ledger === true;
    const isPaid = ord.payment_status === 'paid';
    const gstRate = parseFloat(ord.gst_rate || 0);

    const lockedDisplay = document.getElementById('gstLockedDisplay');
    const editableDisplay = document.getElementById('gstEditableDisplay');
    const ledgerLockedNotice = document.getElementById('gstLedgerLockedNotice');

    // Show locked ledger notice when GST already in ledger AND order is paid
    if (gstInLedger && isPaid) {
        lockedDisplay.style.display = 'none';
        editableDisplay.style.display = 'none';
        ledgerLockedNotice.style.display = 'block';
        document.getElementById('gstRateInput').value = gstRate;
        updatePaymentSummary();
        return;
    }

    ledgerLockedNotice.style.display = 'none';

    if (gstInLedger || (!isCredit && gstRate > 0)) {
        // GST already recorded or order was paid with GST
        lockedDisplay.style.display = 'block';
        editableDisplay.style.display = 'none';
        document.getElementById('gstLockedRate').textContent = gstRate + '%';
        document.getElementById('gstLedgerStatus').textContent = gstInLedger ? '✓ Synced' : 'Will sync on return';
        document.getElementById('gstRateInput').value = gstRate;
    } else if (isCredit) {
        // Credit order — editable GST
        lockedDisplay.style.display = 'none';
        editableDisplay.style.display = 'block';
        const select = document.getElementById('gstRateSelect');
        for (let i = 0; i < select.options.length; i++) {
            if (Math.abs(parseFloat(select.options[i].value) - gstRate) < 0.001) {
                select.selectedIndex = i;
                break;
            }
        }
        document.getElementById('gstRateInput').value = gstRate;
    } else {
        // No GST applicable
        lockedDisplay.style.display = 'block';
        editableDisplay.style.display = 'none';
        document.getElementById('gstLockedRate').textContent = '0% (No GST)';
        document.getElementById('gstLedgerStatus').textContent = 'N/A';
        document.getElementById('gstRateInput').value = 0;
    }

    updatePaymentSummary();
}

function toggleGstSync(chk) {
    document.getElementById('gstRateSelect').disabled = !chk.checked;
}

// ── Payment Setup ──
function setupPaymentSection(ord) {
    if (!ord) return;

    const advAvail = parseFloat(ord.advance_available || 0);
    const depAvail = parseFloat(ord.deposit_available || 0);
    const isPaid = ord.payment_status === 'paid';

    document.getElementById('advanceAvailableLabel').textContent = '₹' + advAvail.toFixed(2);
    document.getElementById('depositAvailableLabel').textContent = '₹' + depAvail.toFixed(2);
    document.getElementById('advanceUsedInput').max = advAvail;
    document.getElementById('depositUsedInput').max = depAvail;

    // ── Collapse/read-only for fully paid orders ──
    const paidNoticeCard = document.getElementById('paidNoticeCard');
    const paymentFields = document.getElementById('paymentFieldsGroup');

    if (isPaid) {
        paidNoticeCard.style.display = 'flex';
        paymentFields.style.display = 'none';

        // Show paid details
        document.getElementById('paidNoticeMethod').textContent = ord.payment_method || '—';
        document.getElementById('paidNoticeDate').textContent = ord.order_date || '—';

        // Zero out all payment inputs
        document.getElementById('paymentAmountInput').value = '0';
        document.getElementById('advanceUsedInput').value = '0';
        document.getElementById('depositUsedInput').value = '0';
        document.getElementById('paymentAmountInput').disabled = true;
        document.getElementById('advanceUsedInput').disabled = true;
        document.getElementById('depositUsedInput').disabled = true;
    } else {
        paidNoticeCard.style.display = 'none';
        paymentFields.style.display = 'block';
        document.getElementById('paymentAmountInput').disabled = false;
        document.getElementById('advanceUsedInput').disabled = false;
        document.getElementById('depositUsedInput').disabled = false;
    }
}

// ── Payment Summary ──
function updatePaymentSummary(changedField) {
    const isPaid = selectedOrderData && selectedOrderData.payment_status === 'paid';
    const serviceCharge = getSelectedServiceCharge();
    const gstRate = parseFloat(document.getElementById('gstRateInput')?.value || document.getElementById('gstRateSelect')?.value || 0);
    const gstAmount = gstRate > 0 ? serviceCharge * gstRate / 100 : 0;
    // For already-paid orders, the service charge was collected in the original invoice — nothing due now
    const totalDue = isPaid ? 0 : (serviceCharge + gstAmount);

    const advanceUsed = parseFloat(document.getElementById('advanceUsedInput')?.value || 0);
    const depositUsed = parseFloat(document.getElementById('depositUsedInput')?.value || 0);

    // Auto-suggest: when cylinders selected, advance, or deposit changes — auto-fill payment amount
    let paymentAmount = parseFloat(document.getElementById('paymentAmountInput')?.value || 0);
    if (!changedField || changedField === 'advance' || changedField === 'deposit') {
        paymentAmount = Math.max(0, totalDue - advanceUsed - depositUsed);
        document.getElementById('paymentAmountInput').value = paymentAmount.toFixed(2);
    }

    const totalSettled = advanceUsed + depositUsed + paymentAmount;
    const remaining = Math.max(0, totalDue - totalSettled);

    // Show invoice total for reference
    const invTotalLine = document.getElementById('sumInvoiceTotalLine');
    if (selectedOrderData && selectedOrderData.total_service_charge) {
        invTotalLine.style.display = '';
        document.getElementById('sumInvoiceTotal').textContent = '₹' + parseFloat(selectedOrderData.total_service_charge).toFixed(2);
    } else {
        invTotalLine.style.display = 'none';
    }

    document.getElementById('sumServiceCharge').textContent = '₹' + serviceCharge.toFixed(2);
    document.getElementById('sumGstAmount').textContent = '₹' + gstAmount.toFixed(2);
    document.getElementById('gstCalculatedAmount').textContent = '₹' + gstAmount.toFixed(2);
    document.getElementById('sumTotalDue').textContent = '₹' + totalDue.toFixed(2);
    document.getElementById('sumAdvanceUsed').textContent = '−₹' + advanceUsed.toFixed(2);
    document.getElementById('sumDepositUsed').textContent = '−₹' + depositUsed.toFixed(2);
    document.getElementById('sumAmountCollecting').textContent = '₹' + paymentAmount.toFixed(2);

    const remainingEl = document.getElementById('sumRemaining');
    remainingEl.textContent = '₹' + remaining.toFixed(2);
    remainingEl.style.color = remaining <= 0 ? '#16a34a' : '#dc2626';

    // Enable submit button only if cylinders selected
    const btn = document.getElementById('returnSubmitBtn');
    const selectedCount = getSelectedServiceIds().length;
    if (selectedCount > 0) {
        btn.disabled = false;
        const isPaid = selectedOrderData && selectedOrderData.payment_status === 'paid';
        if (isPaid) {
            btn.textContent = 'Return ' + selectedCount + ' Cylinder' + (selectedCount > 1 ? 's' : '') + ' to Customer (No Payment)';
        } else {
            btn.textContent = 'Return ' + selectedCount + ' Cylinder' + (selectedCount > 1 ? 's' : '') + ' to Customer' +
                (paymentAmount > 0 ? ' (Collect ₹' + paymentAmount.toFixed(2) + ')' : '');
        }
    } else {
        btn.disabled = true;
        btn.textContent = 'Return Cylinders to Customer';
    }
}

// ── Step Progress ──
function updateStepProgress() {
    const stepEls = document.querySelectorAll('.rc-step');
    const sections = ['lotSection', 'cylinderSection', 'paymentSection'];
    let activeStep = 1;
    for (let i = 0; i < sections.length; i++) {
        const el = document.getElementById(sections[i]);
        if (el && el.style.display !== 'none') activeStep = i + 2;
    }
    stepEls.forEach((s, idx) => {
        s.classList.remove('active', 'completed');
        const stepNum = idx + 1;
        if (stepNum < activeStep) s.classList.add('completed');
        else if (stepNum === activeStep) s.classList.add('active');
    });
}

// ── Confirmation Modal ──
function showReturnConfirmModal() {
    if (!validateForm()) return;

    const ord = selectedOrderData;
    const selectedIds = getSelectedServiceIds();
    const serviceCharge = getSelectedServiceCharge();
    const advanceUsed = parseFloat(document.getElementById('advanceUsedInput')?.value || 0);
    const depositUsed = parseFloat(document.getElementById('depositUsedInput')?.value || 0);
    const paymentAmount = parseFloat(document.getElementById('paymentAmountInput')?.value || 0);
    const gstRate = parseFloat(document.getElementById('gstRateInput')?.value || 0);
    const gstAmount = gstRate > 0 ? serviceCharge * gstRate / 100 : 0;
    const isPaid = ord && ord.payment_status === 'paid';
    const totalDue = isPaid ? 0 : (serviceCharge + gstAmount);

    const custName = document.getElementById('customerSearchInput').value;
    document.getElementById('confirmCustomer').textContent = custName || '—';
    document.getElementById('confirmOrder').textContent = '#ORD-' + String(ord?.order_id || '').padStart(4, '0');

    // List cylinders
    const cylList = document.getElementById('confirmCylinderList');
    cylList.innerHTML = '';
    let html = '';
    selectedIds.forEach(id => {
        const cyl = cylindersData.find(c => c.service_id === id);
        if (cyl) {
            html += '<li><span>' + escapeHtml(cyl.serial_number) + ' — ' + escapeHtml(cyl.gas_name) + ' ' + escapeHtml(cyl.size_capacity) + '</span><span>₹' + parseFloat(cyl.service_charge || 0).toFixed(2) + '</span></li>';
        }
    });
    cylList.innerHTML = html;

    document.getElementById('confirmServiceCharge').textContent = '₹' + serviceCharge.toFixed(2);
    document.getElementById('confirmGst').textContent = '₹' + gstAmount.toFixed(2);
    document.getElementById('confirmTotalDue').textContent = '₹' + totalDue.toFixed(2);

    const paySection = document.getElementById('confirmPaymentSection');
    const paidNotice = document.getElementById('confirmPaidNotice');
    if (isPaid) {
        paySection.style.display = 'none';
        paidNotice.style.display = 'block';
    } else {
        paySection.style.display = 'block';
        paidNotice.style.display = 'none';
        document.getElementById('confirmAdvance').textContent = '−₹' + advanceUsed.toFixed(2);
        document.getElementById('confirmDeposit').textContent = '−₹' + depositUsed.toFixed(2);
        document.getElementById('confirmAmountCollect').textContent = '₹' + paymentAmount.toFixed(2);
    }

    document.getElementById('confirmModal').classList.add('open');
}

function hideReturnConfirmModal() {
    document.getElementById('confirmModal').classList.remove('open');
}

function submitReturnForm() {
    document.getElementById('confirmSubmitBtn').disabled = true;
    document.getElementById('confirmSubmitBtn').textContent = 'Processing...';
    hideReturnConfirmModal();

    const btn = document.getElementById('returnSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    document.getElementById('returnForm').submit();
}

// ── Form Validation ──
function validateForm() {
    const customerId = parseInt(document.getElementById('customerIdInput').value);
    const orderId = parseInt(document.getElementById('selectedOrderId').value);
    const selected = getSelectedServiceIds();

    let msg = '';
    if (!customerId) { msg = 'Please select a customer.'; }
    else if (!orderId) { msg = 'Please select a lot (order).'; }
    else if (selected.length === 0) { msg = 'Please select at least one cylinder to return.'; }

    // Validate advance doesn't exceed available
    const advanceInput = document.getElementById('advanceUsedInput');
    const advanceVal = parseFloat(advanceInput.value) || 0;
    const advanceMax = parseFloat(advanceInput.max) || 0;
    if (advanceVal > 0 && advanceVal > advanceMax) {
        msg = 'Advance used (₹' + advanceVal.toFixed(2) + ') exceeds available balance (₹' + advanceMax.toFixed(2) + ').';
    }

    const depositInput = document.getElementById('depositUsedInput');
    const depositVal = parseFloat(depositInput.value) || 0;
    const depositMax = parseFloat(depositInput.max) || 0;
    if (depositVal > 0 && depositVal > depositMax) {
        msg = 'Deposit used (₹' + depositVal.toFixed(2) + ') exceeds available balance (₹' + depositMax.toFixed(2) + ').';
    }

    if (msg) {
        alert(msg);
        return false;
    }

    return true;
}

// ── Utility ──
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ── Prevent Enter key from submitting form directly ──
document.getElementById('returnForm').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        const target = e.target;
        if (target.tagName !== 'TEXTAREA' && target.tagName !== 'SELECT') {
            e.preventDefault();
        }
    }
});

// ── Init ──
document.addEventListener('DOMContentLoaded', function() {
    loadEligibleCustomers();
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
