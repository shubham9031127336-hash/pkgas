<?php
require_once __DIR__ . '/lang_init.php';

// ── AJAX: Analyze Bulk Receive (must run before layout.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_receive') {
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
        $lot_id = intval($_POST['lot_id'] ?? 0);
        $vendor_id = intval($_POST['vendor_id'] ?? 0);
        $context = ['action' => 'receive', 'vendor_id' => $vendor_id, 'lot_id' => $lot_id];
        $report = generateFullImpactReport($pdo, [$lot_id], 'receive', $context);
        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$page_title = "Receive Cylinders";
$active_menu = "cylinders_receive";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/bulk_operation_engine.php';

runGSTMigrations($pdo);
runVendorInvoiceMigrations($pdo);
runVendorPartnerLedgerMigrations($pdo);
runLotSettlementRedesignMigration($pdo);
runVendorActivityLogMigration($pdo);

$message = '';
$error = '';

// ── POST: Receive Cylinders (Multi-Lot Support) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_lot') {
    $selected_cyls = $_POST['cylinder_ids'] ?? [];
    $refill_costs = $_POST['refill_costs'] ?? [];
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $received_date_raw = $_POST['received_date'] ?? date('Y-m-d\TH:i');
    $received_date = str_replace('T', ' ', $received_date_raw) . ':00';
    $notes = trim($_POST['notes'] ?? '');
    $deduction = floatval($_POST['deduction_amount'] ?? 0);
    $deduction_notes = trim($_POST['deduction_notes'] ?? '');
    $addition = floatval($_POST['addition_amount'] ?? 0);
    $addition_notes = trim($_POST['addition_notes'] ?? '');
    $receive_transport = floatval($_POST['receive_transport_cost'] ?? 0);
    $payment_rows = $_POST['payment_rows'] ?? [];

    if (empty($selected_cyls)) {
        $error = "Please select at least one cylinder to receive.";
    } else {
        try {
            // ── Group selected cylinders by their dispatch lot ──
            $lot_groups = [];
            $vendor_of_lot = [];
            $ph = implode(',', array_fill(0, count($selected_cyls), '?'));
            $cyl_ids_int = array_map('intval', $selected_cyls);
            $grp_q = $pdo->prepare("SELECT cylinder_id, lot_id FROM dispatch_lot_items WHERE cylinder_id IN ($ph) AND dispatch_status = 'dispatched'");
            $grp_q->execute($cyl_ids_int);
            while ($grp_row = $grp_q->fetch()) {
                $lid = intval($grp_row['lot_id']);
                $cid = intval($grp_row['cylinder_id']);
                $lot_groups[$lid][] = $cid;
                if (!isset($vendor_of_lot[$lid])) {
                    $lv = $pdo->prepare("SELECT vendor_id FROM dispatch_lots WHERE id = ?");
                    $lv->execute([$lid]);
                    $lv_row = $lv->fetch();
                    $vendor_of_lot[$lid] = $lv_row ? intval($lv_row['vendor_id']) : 0;
                }
            }

            if (empty($lot_groups)) {
                throw new Exception("No valid cylinders found. Verify they were dispatched.");
            }

            // One-time global GST rate (applied to lots without locked GST)
            $global_gst_rate = floatval($_POST['gst_rate'] ?? 0);

            // ── Validate payment rows ──
            foreach ($payment_rows as $pr_idx => $pr) {
                $pr_amount = floatval($pr['amount'] ?? 0);
                if ($pr_amount > 0 && empty(trim($pr['method'] ?? ''))) {
                    throw new Exception("Payment row #" . ($pr_idx + 1) . ": Payment method is required when amount is entered.");
                }
            }

            $pdo->beginTransaction();
            $total_cylinders_selected = count($selected_cyls);
            $overall_received = 0;
            $overall_sum_refill = 0;
            $overall_paid_from_rows = 0;
            $overall_max_payable_total = 0;
            $all_lot_numbers = [];

            foreach ($lot_groups as $lot_id => $cyl_ids) {
                $cyl_ids = array_map('intval', $cyl_ids);

                // ── Fetch lot ──
                $lot_stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id = ?");
                $lot_stmt->execute([$lot_id]);
                $lot = $lot_stmt->fetch();
                if (!$lot) continue;
                if ($lot['lot_status'] === 'completed') {
                    $all_lot_numbers[] = $lot['lot_number'] . ' (already completed)';
                    continue;
                }
                $vendor_id = intval($vendor_of_lot[$lot_id] ?? $lot['vendor_id']);
                $lot_cyl_count = count($cyl_ids);
                $proportion = $lot_cyl_count / $total_cylinders_selected;

                // ── Per-lot allocations ──
                $lot_deduction = round($deduction * $proportion, 2);
                $lot_addition = round($addition * $proportion, 2);
                $lot_receive_transport = round($receive_transport * $proportion, 2);

                // ── Check payment/lock status for this lot ──
                $existing_payment_count = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE lot_id = ?");
                    $stmt->execute([$lot_id]);
                    $existing_payment_count = intval($stmt->fetchColumn());
                } catch (PDOException $e) {}
                $lot_has_advance = floatval($lot['advance_amount']) > 0;
                $gst_is_locked = ($lot_has_advance || $existing_payment_count > 0 || intval($lot['gst_locked']) > 0);

                if ($gst_is_locked) {
                    $lot_gst_rate = floatval($lot['gst_rate']);
                } else {
                    $lot_gst_rate = $global_gst_rate;
                }

                // ── Process cylinders for this lot ──
                $received = 0;
                $sum_refill = 0;
                $gas_type_counts = [];

                foreach ($cyl_ids as $cyl_id) {
                    $cyl_fetch = $pdo->prepare("SELECT serial_number, gas_type_id, size_capacity FROM cylinders WHERE id = ?");
                    $cyl_fetch->execute([$cyl_id]);
                    $cyl_data = $cyl_fetch->fetch();
                    if (!$cyl_data) continue;

                    $upd = $pdo->prepare("UPDATE cylinders SET status = 'filled', current_vendor_id = NULL WHERE id = ? AND status = 'sent_to_vendor' AND current_vendor_id = ?");
                    $upd->execute([$cyl_id, $vendor_id]);

                    if ($upd->rowCount() > 0) {
                        $cyl_cost = floatval($refill_costs[$cyl_id] ?? 0);
                        if ($cyl_cost <= 0) {
                            throw new Exception("Refill cost is required for cylinder {$cyl_data['serial_number']} (ID: $cyl_id). Please enter a cost greater than zero.");
                        }
                        $sum_refill += $cyl_cost;

                        $pdo->prepare("UPDATE vendors SET active_refill_count = GREATEST(0, active_refill_count - 1) WHERE id = ?")->execute([$vendor_id]);
                        logCylinderTransaction($pdo, $cyl_id, null, $vendor_id, 'return_from_customer', "Lot {$lot['lot_number']}: Received back filled from vendor. Notes: $notes");

                        $pdo->prepare("UPDATE dispatch_lot_items SET dispatch_status = 'received', receive_date = NOW(), refill_cost = ?, receive_transport_cost = ? WHERE lot_id = ? AND cylinder_id = ?")
                            ->execute([$cyl_cost, $lot_receive_transport > 0 ? $lot_receive_transport / $lot_cyl_count : 0, $lot_id, $cyl_id]);

                        $upd_svc = $pdo->prepare("UPDATE customer_refill_services SET status = 'returned_to_warehouse', filled_from_vendor_at = NOW(), returned_to_warehouse_at = NOW() WHERE cylinder_id = ? AND status = 'sent_to_vendor'");
                        $upd_svc->execute([$cyl_id]);

                        $gt = intval($cyl_data['gas_type_id']);
                        $sz = $cyl_data['size_capacity'];
                        $key = $gt . '|' . $sz;
                        $gas_type_counts[$key] = ($gas_type_counts[$key] ?? 0) + 1;
                        $received++;
                    }
                }

                if ($received === 0) continue;

                // ── Update lot returned count ──
                $total_in_lot = intval($lot['cylinder_count']);
                $new_returned = intval($lot['returned_count']) + $received;
                $pdo->prepare("UPDATE dispatch_lots SET returned_count = ?, receive_date = NOW(), receive_transport_total = COALESCE(receive_transport_total, 0) + ? WHERE id = ?")
                    ->execute([$new_returned, $lot_receive_transport, $lot_id]);

                // ── Calculate final amounts for this lot ──
                $gst_info = calculateGST($sum_refill, $lot_gst_rate);
                $final_gst_amount = $gst_info['gst_amount'];
                $final_total = $sum_refill + $final_gst_amount;
                $net_total = $final_total - $lot_deduction + $lot_addition;

                // ── Accumulate existing values ──
                $existing_refill = floatval($lot['final_refill_amount'] ?? 0);
                $existing_final_gst = floatval($lot['final_gst_amount'] ?? 0);
                $existing_final_total = floatval($lot['final_total'] ?? 0);
                $existing_deduction = floatval($lot['deduction_amount'] ?? 0);
                $existing_addition = floatval($lot['addition_amount'] ?? 0);
                $cumulative_refill = $existing_refill + $sum_refill;
                $cumulative_gst = $existing_final_gst + $final_gst_amount;
                $cumulative_total = $existing_final_total + $final_total;
                $cumulative_deduction = $existing_deduction + $lot_deduction;
                $cumulative_addition = $existing_addition + $lot_addition;
                $cumulative_net = $cumulative_total - $cumulative_deduction + $cumulative_addition;

                // ── Vendor advance balance ──
                $advance_balance = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(advance_balance, 0) as ab FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$vendor_id]);
                    $ab_res = $stmt->fetch();
                    $advance_balance = floatval($ab_res['ab'] ?? 0);
                } catch (PDOException $e) {}

                // ── Advance utilization ──
                $lot_advance = floatval($lot['advance_amount']);
                $advance_used_this = 0;
                if ($lot_advance > 0 && $advance_balance > 0 && $total_in_lot > 0) {
                    $already_utilized = 0;
                    try {
                        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE lot_id = ? AND payment_subtype = 'advance_utilized'");
                        $stmt->execute([$lot_id]);
                        $already_utilized = floatval($stmt->fetchColumn());
                    } catch (PDOException $e) {}
                    $remaining_lot_advance = max(0, $lot_advance - $already_utilized);
                    $advance_proportion = $received / $total_in_lot;
                    $prorated_advance = $lot_advance * $advance_proportion;
                    $advance_used_this = min($prorated_advance, $advance_balance, $remaining_lot_advance, $net_total);
                }

                // ── Update lot items with GST info ──
                foreach ($cyl_ids as $cyl_id2) {
                    $cyl_cost = floatval($refill_costs[$cyl_id2] ?? 0);
                    $gst_info_item = calculateGST($cyl_cost, $lot_gst_rate);
                    $pdo->prepare("UPDATE dispatch_lot_items SET gst_rate = ?, taxable_amount = ?, gst_amount = ?, cgst = ?, sgst = ? WHERE lot_id = ? AND cylinder_id = ?")
                        ->execute([$lot_gst_rate, $gst_info_item['taxable'], $gst_info_item['gst_amount'], $gst_info_item['cgst'], $gst_info_item['sgst'], $lot_id, $cyl_id2]);

                    $pdo->prepare("UPDATE cylinders SET current_refill_cost = ?, last_refill_vendor_id = ?, last_refill_lot_id = ? WHERE id = ?")
                        ->execute([$cyl_cost, $vendor_id, $lot_id, $cyl_id2]);

                    if ($cyl_cost > 0) {
                        $pdo->prepare("UPDATE refill_order_items roi JOIN customer_refill_services crs ON roi.refill_order_id = crs.refill_order_id AND roi.gas_type_id = crs.gas_type_id SET roi.refill_cost = ? WHERE crs.cylinder_id = ? AND crs.status IN ('returned_to_warehouse','filled_from_vendor') AND roi.refill_cost = 0")
                            ->execute([$cyl_cost, $cyl_id2]);
                        $pdo->prepare("UPDATE refill_order_items SET refill_cost = ? WHERE returned_cylinder_id = ? AND refill_cost = 0")
                            ->execute([$cyl_cost, $cyl_id2]);
                    }
                }

                // ── Record lot-level deductions/additions ──
                $pdo->prepare("UPDATE dispatch_lots SET deduction_amount = ?, deduction_notes = ?, addition_amount = ?, addition_notes = ? WHERE id = ?")
                    ->execute([$cumulative_deduction, $deduction_notes ?: null, $cumulative_addition, $addition_notes ?: null, $lot_id]);

                // ── Existing payments total for this lot (exclude advance_utilized book entries) ──
                $existing_payments_total_lot = 0;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE lot_id = ? AND (payment_subtype IS NULL OR payment_subtype != 'advance_utilized')");
                    $stmt->execute([$lot_id]);
                    $existing_payments_total_lot = floatval($stmt->fetchColumn());
                } catch (PDOException $e) {}

                // ── Due created ledger entry ──
                $log_deduction_part = $lot_deduction > 0 ? " (Ded: ₹$lot_deduction" : "";
                $log_addition_part = $lot_addition > 0 ? ($lot_deduction > 0 ? ", " : " (") . "Add: ₹$lot_addition" : "";
                $log_close = ($lot_deduction > 0 || $lot_addition > 0) ? ")" : "";
                $due_remarks = "Refill cost Lot {$lot['lot_number']} - {$received} cylinders" . ($invoice_number ? " (Inv: $invoice_number)" : "") . $log_deduction_part . $log_addition_part . $log_close;
                addVendorRefillLedgerEntry($pdo, $vendor_id, $net_total, 'due_created', $lot_id, $due_remarks, $_SESSION['username'] ?? 'admin', 'dispatch_lot');

                // ── Advance utilization ──
                if ($advance_used_this > 0) {
                    $stmt = $pdo->prepare("SELECT COALESCE(running_balance, 0) as rb, COALESCE(advance_balance, 0) as ab, COALESCE(due_balance, 0) as db FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$vendor_id]);
                    $bal = $stmt->fetch();
                    $new_advance = max(0, floatval($bal['ab'] ?? 0) - $advance_used_this);
                    $new_due = max(0, floatval($bal['db'] ?? 0) - $advance_used_this);
                    $stmt2 = $pdo->prepare("INSERT INTO vendor_partner_ledger (entity_type, entity_id, transaction_date, transaction_type, debit, credit, running_balance, advance_balance, due_balance, settlement_status, reference_type, reference_id, remarks, created_by) VALUES (?, ?, NOW(), 'advance_utilized', ?, 0, ?, ?, ?, 'partial', 'dispatch_lot', ?, ?, ?)");
                    $stmt2->execute(['vendor', $vendor_id, $advance_used_this, floatval($bal['rb'] ?? 0), $new_advance, $new_due, $lot_id, "Advance utilized for Lot {$lot['lot_number']} — ₹" . number_format($advance_used_this, 2), $_SESSION['username'] ?? 'admin']);

                    $stmt = $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, payment_subtype, notes, payment_date) VALUES (?, ?, ?, 'Advance', 'vendor_refill_payment', 'advance_utilized', ?, ?)");
                    $stmt->execute([$lot_id, $vendor_id, $advance_used_this, "Advance utilized for Lot {$lot['lot_number']}", $received_date]);
                }

                // ── Process payment rows (per lot proportionally) ──
                $total_paid_from_rows_lot = 0;
                foreach ($payment_rows as $pr) {
                    $pr_amount = floatval($pr['amount'] ?? 0);
                    if ($pr_amount <= 0) continue;
                    $lot_pr_amount = round($pr_amount * $proportion, 2);
                    if ($lot_pr_amount <= 0) continue;
                    $pr_method = trim($pr['method'] ?? '');
                    $pr_date_raw = trim($pr['date'] ?? $received_date_raw);
                    $pr_date = str_replace('T', ' ', $pr_date_raw) . ':00';
                    $pr_reference = trim($pr['reference'] ?? '');
                    $pr_method_display = $pr_method ?: 'Pending';

                    $stmt = $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, payment_subtype, notes, payment_date) VALUES (?, ?, ?, ?, 'vendor_refill_payment', 'settlement', ?, ?)");
                    $stmt->execute([$lot_id, $vendor_id, $lot_pr_amount, $pr_method_display, "Settlement payment for Lot {$lot['lot_number']}" . ($pr_reference ? " | Ref: $pr_reference" : ""), $pr_date]);

                    addVendorRefillLedgerEntry($pdo, $vendor_id, $lot_pr_amount, 'payment', $lot_id, "Settlement payment for Lot {$lot['lot_number']} ($pr_method_display" . ($pr_reference ? " - $pr_reference" : "") . ")", $_SESSION['username'] ?? 'admin', 'dispatch_lot');
                    $total_paid_from_rows_lot += $lot_pr_amount;
                }

                // ── Compute lot payment status (advance_used_this is a book entry, not new money — original advance payment is already in existing_payments_total_lot) ──
                $total_collected = $existing_payments_total_lot + $total_paid_from_rows_lot;
                $remaining_after = max(0, $cumulative_net - $total_collected);

                // ── Calculate remaining_balance for the FULL lot ──
                $estimated_pending_total = 0;
                $full_lot_remaining = $remaining_after;
                if ($new_returned < $total_in_lot && $total_in_lot > 0) {
                    $estimated_total_lot = floatval($lot['estimated_total'] ?? 0);
                    if ($estimated_total_lot > 0) {
                        $per_cylinder_est = $estimated_total_lot / $total_in_lot;
                        $pending_count = $total_in_lot - $new_returned;
                        $estimated_pending_cost = $per_cylinder_est * $pending_count;
                        if ($lot_gst_rate > 0) {
                            $pending_gst_info = calculateGST($estimated_pending_cost, $lot_gst_rate);
                            $estimated_pending_total = $estimated_pending_cost + $pending_gst_info['gst_amount'];
                        } else {
                            $estimated_pending_total = $estimated_pending_cost;
                        }
                        $full_lot_remaining = $remaining_after + $estimated_pending_total;
                    }
                }

                // ── Track max payable per lot for aggregate validation ──
                // Include estimated pending cylinders so payments covering full invoice don't fail on partial receives
                $max_payable_lot = max(0, $cumulative_net - $existing_payments_total_lot + $estimated_pending_total);
                $overall_max_payable_total += $max_payable_lot;

                // ── Per-lot payment validation ──
                if ($total_paid_from_rows_lot > $max_payable_lot) {
                    throw new Exception("For lot {$lot['lot_number']}, payment allocation (₹" . number_format($total_paid_from_rows_lot, 2) . ") exceeds maximum payable (₹" . number_format($max_payable_lot, 2) . "). Reduce payment amount or increase advance utilization.");
                }

                $pay_status = 'unpaid';
                if ($total_collected > 0 || $advance_used_this > 0) {
                    $pay_status = 'partial';
                }
                if ($new_returned >= $total_in_lot && $total_in_lot > 0 && $remaining_after <= 0) {
                    $pay_status = 'paid';
                }

                $new_lot_status = 'open';
                if ($new_returned >= $total_in_lot && $total_in_lot > 0) {
                    $new_lot_status = ($remaining_after <= 0) ? 'completed' : 'partial_return';
                } elseif ($new_returned > 0) {
                    $new_lot_status = 'partial_return';
                }

                // ── Update lot ──
                $lot_update_sql = "UPDATE dispatch_lots SET 
                    final_refill_amount = ?, final_gst_amount = ?, final_total = ?,
                    total_paid = ?, remaining_balance = ?, additional_payments = ?,
                    payment_status = ?, lot_status = ?";
                $lot_update_params = [$cumulative_refill, $cumulative_gst, $cumulative_total,
                    $total_collected, $full_lot_remaining, $total_paid_from_rows_lot,
                    $pay_status, $new_lot_status];

                if (!$gst_is_locked && $lot_gst_rate > 0) {
                    $lot_update_sql .= ", gst_rate = ?, gst_applicable = 1, gst_locked = 1, gst_type = 'CGST/SGST'";
                    $lot_update_params[] = $lot_gst_rate;
                } elseif (!$gst_is_locked && $lot_gst_rate <= 0) {
                    $lot_update_sql .= ", gst_applicable = 0, gst_locked = 1, gst_rate = 0";
                }

                $lot_update_sql .= " WHERE id = ?";
                $lot_update_params[] = $lot_id;
                $pdo->prepare($lot_update_sql)->execute($lot_update_params);

                // ── Auto-create vendor invoice from lot (if first receive) ──
                if (function_exists('createVendorInvoiceFromLot')) {
                    try {
                        $existing = $pdo->prepare("SELECT COUNT(*) FROM vendor_invoices WHERE lot_id = ?");
                        $existing->execute([$lot_id]);
                        if (intval($existing->fetchColumn()) === 0) {
                            createVendorInvoiceFromLot($pdo, $lot_id, [
                                'vendor_invoice_number' => $invoice_number ?: $lot['lot_number'],
                                'invoice_date' => date('Y-m-d', strtotime($received_date)),
                                'notes' => $notes,
                                'created_by' => $_SESSION['username'] ?? 'admin',
                            ]);
                        }
                    } catch (Exception $e) {
                        error_log("Vendor invoice auto-create failed for lot #$lot_id: " . $e->getMessage());
                    }
                }

                // ── Auto-create receive transport expense per lot ──
                if ($lot_receive_transport > 0) {
                    try {
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE reference_type = 'receive_transport' AND reference_id = ? AND is_deleted = 0");
                        $chk->execute([$lot_id]);
                        if (intval($chk->fetchColumn()) === 0) {
                            $v_row = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                            $v_row->execute([$vendor_id]);
                            $v_name = $v_row->fetchColumn() ?: '';
                            $transport_per_cyl = $lot_receive_transport / $received;
                            $transport_notes = "Receive transport — {$received} cylinders for Lot {$lot['lot_number']} @ ₹" . number_format($transport_per_cyl, 2) . "/cyl";
                            if (function_exists('autoCreateExpense') && function_exists('resolveSystemCategory')) {
                                $cat_id = resolveSystemCategory($pdo, 'Transport Charges');
                                if ($cat_id > 0) {
                                    autoCreateExpense($pdo, [
                                        'category_id' => $cat_id,
                                        'vendor_id' => $vendor_id,
                                        'vendor_name' => $v_name,
                                        'expense_date' => date('Y-m-d', strtotime($received_date)),
                                        'amount' => $lot_receive_transport,
                                        'gst_type' => 'exclusive',
                                        'gst_rate' => 0,
                                        'payment_method' => 'Bank Transfer',
                                        'payment_status' => 'paid',
                                        'business_key' => getBrandConfig()['business_key'],
                                        'notes' => $transport_notes,
                                        'reference_type' => 'receive_transport',
                                        'reference_id' => $lot_id,
                                        'reference_number' => $lot['lot_number'],
                                        'created_by' => $_SESSION['username'] ?? 'admin',
                                        'created_by_name' => $_SESSION['username'] ?? 'admin',
                                    ]);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Receive transport expense auto-create failed for lot #$lot_id: " . $e->getMessage());
                    }
                }

                $v_name_row = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                $v_name_row->execute([$vendor_id]);
                $v_name = $v_name_row->fetchColumn() ?: 'Vendor';
                $gas_parts = [];
                foreach ($gas_type_counts as $key => $cnt) {
                    list($gt, $sz) = explode('|', $key);
                    $gn = $pdo->prepare("SELECT name FROM gas_types WHERE id = ?");
                    $gn->execute([$gt]);
                    $gname = $gn->fetchColumn() ?: "Gas#$gt";
                    $gas_parts[] = "{$cnt}× {$gname} ({$sz})";
                }
                $pay_methods = [];
                foreach ($payment_rows as $pr) {
                    if (floatval($pr['amount'] ?? 0) > 0) {
                        $pay_methods[] = trim($pr['method'] ?? 'Unknown');
                    }
                }
                logVendorActivity($pdo, $vendor_id, 'receive', "Received {$received} filled cylinders from {$v_name}", "Lot {$lot['lot_number']}: " . implode(', ', $gas_parts) . ". Refill: ₹" . number_format($sum_refill, 2) . ($final_gst_amount > 0 ? " (GST: ₹" . number_format($final_gst_amount, 2) . ")" : "") . ($lot_deduction > 0 ? " | Deduction: ₹" . number_format($lot_deduction, 2) : "") . ($lot_addition > 0 ? " | Addition: ₹" . number_format($lot_addition, 2) : "") . (!empty($pay_methods) ? " | Paid: ₹" . number_format($total_paid_from_rows_lot, 2) . " via " . implode(', ', $pay_methods) : "") . ($advance_used_this > 0 ? " | Advance adjusted: ₹" . number_format($advance_used_this, 2) : "") . " | Remaining: ₹" . number_format($remaining_after, 2), [
                    'vendor_name' => $v_name,
                    'lot_number' => $lot['lot_number'],
                    'gas_breakdown' => $gas_parts,
                    'refill_total' => $sum_refill,
                    'gst_amount' => $final_gst_amount,
                    'gst_rate' => $lot_gst_rate,
                    'deduction' => $lot_deduction,
                    'addition' => $lot_addition,
                    'payments' => $pay_methods,
                    'payment_total' => $total_paid_from_rows_lot,
                    'advance_utilized' => $advance_used_this,
                    'remaining_balance' => $remaining_after,
                    'receive_transport' => $lot_receive_transport,
                    'net_total' => $cumulative_net,
                    'payment_status' => $pay_status,
                ], [
                    'reference_type' => 'dispatch_lot',
                    'reference_id' => $lot_id,
                    'cylinder_count' => $received,
                    'amount' => $total_paid_from_rows_lot + $advance_used_this,
                    'lot_number' => $lot['lot_number'],
                    'balance_after' => $remaining_after,
                    'created_by' => $_SESSION['username'] ?? 'admin',
                ]);

                $overall_received += $received;
                $overall_sum_refill += $sum_refill;
                $overall_paid_from_rows += $total_paid_from_rows_lot;
                $all_lot_numbers[] = $lot['lot_number'];
            }

            if ($overall_received === 0) {
                throw new Exception("No cylinders could be received. Verify they were properly dispatched.");
            }

            // ── Aggregate payment validation across all lots ──
            if ($overall_paid_from_rows > $overall_max_payable_total) {
                throw new Exception("Total payment across all lots (₹" . number_format($overall_paid_from_rows, 2) . ") exceeds total amount due (₹" . number_format($overall_max_payable_total, 2) . "). Reduce payment amount.");
            }

            $pdo->commit();
            syncInventory($pdo);

            // (cache removed)

            $lot_list_str = implode(', ', $all_lot_numbers);
            $parts = [];
            $parts[] = "Lots {$lot_list_str}: Successfully received {$overall_received} refilled cylinders back!";
            if ($overall_sum_refill > 0) {
                $parts[] = "Total refill: ₹" . number_format($overall_sum_refill, 2);
                if ($overall_paid_from_rows > 0) $parts[] = "Payments: ₹" . number_format($overall_paid_from_rows, 2);
                if ($receive_transport > 0) $parts[] = "Transport: ₹" . number_format($receive_transport, 2);
            }
            $_SESSION['success_flash'] = implode(' | ', $parts);
            echo "<script>window.location.href='lot-dashboard.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Receive transaction failed: " . $e->getMessage();
        }
    }
}

// ── POST: Individual receive redirected — must use lots ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_cylinders') {
    $error = "Individual receive is disabled. Please use the lot-based receive flow.";
}

// ── Fetch Data ──
$vendors = $pdo->query("SELECT * FROM vendors ORDER BY name ASC")->fetchAll();
$gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();

$gst_rates = [];
try {
    $gst_rates = $pdo->query("SELECT * FROM gst_rates WHERE is_active = 1 ORDER BY rate_percent")->fetchAll();
} catch (PDOException $e) {}

// Fetch vendor advance balances
$vendor_advance_balances = [];
try {
    $res = $pdo->query("SELECT vpl.entity_id, COALESCE(vpl.advance_balance, 0) as advance_balance FROM vendor_partner_ledger vpl WHERE vpl.entity_type = 'vendor' AND vpl.id IN (SELECT MAX(id) FROM vendor_partner_ledger WHERE entity_type = 'vendor' GROUP BY entity_id)");
    while ($row = $res->fetch()) {
        $vendor_advance_balances[intval($row['entity_id'])] = floatval($row['advance_balance']);
    }
} catch (PDOException $e) {}

// ── Fetch dispatch lots for vendor (for lot mode) ──
$vendor_lots = [];
try {
    $lots_q = $pdo->query("
        SELECT dl.*, v.name as vendor_name,
               COALESCE((SELECT SUM(amount) FROM payments WHERE lot_id = dl.id AND payment_subtype = 'advance_utilized'), 0) AS advance_utilized
        FROM dispatch_lots dl
        JOIN vendors v ON dl.vendor_id = v.id
        WHERE dl.lot_status IN ('open','partial_return')
        ORDER BY dl.dispatch_date DESC
    ");
    $vendor_lots = $lots_q->fetchAll();
} catch (PDOException $e) {}

// Group lots by vendor
$lots_by_vendor = [];
foreach ($vendor_lots as $lot) {
    $vid = intval($lot['vendor_id']);
    if (!isset($lots_by_vendor[$vid])) $lots_by_vendor[$vid] = [];
    $lots_by_vendor[$vid][] = $lot;
}

// Handle GET lot_id pre-selection
$preselect_lot_id = intval($_GET['lot_id'] ?? 0);
$preselect_vendor_id = 0;
if ($preselect_lot_id > 0) {
    $pl_q = $pdo->prepare("SELECT vendor_id FROM dispatch_lots WHERE id = ?");
    $pl_q->execute([$preselect_lot_id]);
    $pl = $pl_q->fetch();
    if ($pl) $preselect_vendor_id = intval($pl['vendor_id']);
}
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

.receive-layout { max-width: 960px; margin: 0 auto; }

/* ── Page Header ── */
.rc-header { margin-bottom: 1.75rem; }
.rc-title { font-size: 1.65rem; font-weight: 800; letter-spacing: -0.02em; color: var(--rc-fg); margin: 0; }
.rc-subtitle { color: var(--rc-muted); font-size: 0.9rem; margin-top: 0.25rem; }

/* ── Collapsible Section Toggle ── */
.rc-collapsible { margin-bottom: 0.5rem; }
.rc-collapse-toggle { display: flex; align-items: center; gap: 0.65rem; width: 100%; padding: 0.85rem 1.15rem; background: var(--admin-surface); border: 1px solid var(--rc-border); border-radius: var(--rc-radius); cursor: pointer; transition: all 0.2s; font-family: inherit; font-size: 0.95rem; text-align: left; color: var(--rc-fg); font-weight: 700; box-shadow: var(--rc-shadow); }
.rc-collapse-toggle:hover { border-color: var(--rc-accent); background: var(--rc-accent-soft); }
.rc-collapse-toggle .rc-arrow { margin-left: auto; transition: transform 0.25s; font-size: 0.75rem; color: var(--rc-muted); }
.rc-collapse-toggle.open .rc-arrow { transform: rotate(180deg); }
.rc-collapse-toggle .rc-status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; background: var(--rc-border); }
.rc-collapse-toggle.open .rc-status-dot { background: var(--rc-accent); }
.rc-collapse-body { overflow: hidden; max-height: 0; transition: max-height 0.35s ease, opacity 0.25s ease, margin 0.25s ease; opacity: 0; margin-top: 0; }
.rc-collapse-body.open { max-height: 2000px; opacity: 1; margin-top: 0.5rem; }
.rc-collapse-body-inner { padding: 1.25rem; background: var(--admin-surface); border: 1px solid var(--rc-border); border-radius: var(--rc-radius); box-shadow: var(--rc-shadow); border-top-left-radius: 0; }

/* ── Alert Banner ── */
.rc-alert { padding: 0.85rem 1.15rem; border-radius: var(--rc-radius); margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 0.65rem; font-size: 0.88rem; line-height: 1.5; border-left: 4px solid; }
.rc-alert-error { background: var(--rc-danger-soft); color: var(--rc-danger); border-color: var(--rc-danger); }
.rc-alert-icon { flex-shrink: 0; margin-top: 1px; }

/* ── Step Cards ── */
.rc-card { background: var(--admin-surface); border: 1px solid var(--rc-border); border-radius: var(--rc-radius); padding: 1.5rem; margin-bottom: 1.25rem; box-shadow: var(--rc-shadow); transition: box-shadow 0.2s; }
.rc-card:hover { box-shadow: var(--rc-shadow-lg); }
.rc-card-header { display: flex; align-items: center; margin-bottom: 1.15rem; gap: 0.65rem; }
.rc-card-num { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--rc-accent); color: #fff; font-weight: 700; font-size: 0.82rem; flex-shrink: 0; }
.rc-card-title { font-weight: 700; font-size: 1rem; color: var(--rc-fg); margin: 0; }
.rc-card-sub { font-size: 0.75rem; color: var(--rc-muted); font-weight: 400; margin-left: 0.25rem; }
.rc-helper { font-size: 0.78rem; color: var(--rc-muted); line-height: 1.5; margin-bottom: 0.85rem; }

/* ── Form Controls ── */
.rc-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.rc-field { margin-bottom: 0; }
.rc-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--rc-fg); margin-bottom: 0.35rem; }
.rc-select, .rc-input, .rc-textarea { width: 100%; padding: 0.55rem 0.75rem; font-size: 0.88rem; border: 1.5px solid var(--rc-border); border-radius: var(--rc-radius-sm); background: #fff; color: var(--rc-fg); transition: all 0.2s; font-family: inherit; }
.rc-select:focus, .rc-input:focus, .rc-textarea:focus { border-color: var(--rc-accent); outline: none; box-shadow: 0 0 0 3px var(--rc-accent-light); }
.rc-select:hover, .rc-input:hover, .rc-textarea:hover { border-color: #94a3b8; }

/* ── Cylinder List ── */
.rc-cyls-scroll { max-height: 320px; overflow-y: auto; border: 1.5px solid var(--rc-border); border-radius: var(--rc-radius-sm); padding: 0.4rem; scroll-behavior: smooth; }
.rc-cyls-scroll::-webkit-scrollbar { width: 6px; }
.rc-cyls-scroll::-webkit-scrollbar-track { background: transparent; }
.rc-cyls-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
.rc-cyls-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.rc-filter { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.85rem; flex-wrap: wrap; }
.rc-filter select { padding: 0.4rem 0.7rem; font-size: 0.82rem; border-radius: var(--rc-radius-sm); border: 1.5px solid var(--rc-border); background: #fff; color: var(--rc-fg); cursor: pointer; }
.rc-filter select:focus { border-color: var(--rc-accent); outline: none; box-shadow: 0 0 0 3px var(--rc-accent-light); }

.rc-cyl-item { display: flex; align-items: center; gap: 0.65rem; padding: 0.55rem 0.75rem; border: 1px solid var(--rc-border); border-radius: var(--rc-radius-sm); margin-bottom: 0.35rem; transition: all 0.15s; cursor: pointer; background: #fff; }
.rc-cyl-item:hover { border-color: var(--rc-accent); background: var(--rc-accent-soft); }
.rc-cyl-item:has(input:checked) { border-color: var(--rc-accent); background: var(--rc-accent-soft); }
.rc-cyl-item input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--rc-accent); cursor: pointer; flex-shrink: 0; }
.rc-cyl-serial { font-weight: 700; color: var(--rc-accent); font-size: 0.88rem; }
.rc-cyl-gas { font-weight: 600; font-size: 0.8rem; color: var(--rc-fg); }
.rc-tag { display: inline-block; padding: 1px 7px; border-radius: 4px; font-size: 0.65rem; font-weight: 800; line-height: 1.5; text-transform: uppercase; letter-spacing: 0.02em; }
.tag-own { background: #d1fae5; color: #065f46; border: 1px solid rgba(16,185,129,0.3); }
.tag-con { background: #dbeafe; color: #1e40af; border: 1px solid rgba(59,130,246,0.3); }
.tag-br  { background: #fef3c7; color: #92400e; border: 1px solid rgba(251,191,36,0.3); }
.tag-ven { background: #e8d5f5; color: #6b21a8; border: 1px solid rgba(147,51,234,0.3); }
.tag-cusr { background: #fff7ed; color: #c2410c; border: 1px solid rgba(234,88,12,0.3); }
.rc-cyl-cost { width: 82px; padding: 0.25rem 0.4rem; font-size: 0.8rem; text-align: right; border: 1.5px solid var(--rc-border); border-radius: 6px; background: #fff; color: var(--rc-fg); transition: all 0.2s; }
.rc-cyl-cost:focus { border-color: var(--rc-accent); outline: none; box-shadow: 0 0 0 3px var(--rc-accent-light); }
.rc-cyl-cost:hover { border-color: #94a3b8; }

/* ── Summary Bar ── */
.rc-summary { background: var(--rc-success-soft); border: 1px solid #bbf7d0; border-radius: var(--rc-radius); padding: 1rem 1.15rem; margin-top: 0.5rem; }
.rc-summary-line { display: flex; justify-content: space-between; padding: 0.25rem 0; font-size: 0.85rem; color: var(--rc-fg); }
.rc-summary-total { font-weight: 700; font-size: 1.05rem; border-top: 2px solid #bbf7d0; padding-top: 0.5rem; margin-top: 0.35rem; }
.rc-summary-neg { color: var(--rc-danger); }
.rc-summary-pos { color: var(--rc-success); }

/* ── Lot Summary Card (Enhanced) ── */
.rc-lot-summary { background: linear-gradient(135deg, #fefce8 0%, #fffbeb 100%); border: 2px solid #fde68a; border-radius: var(--rc-radius); padding: 1.15rem 1.25rem; margin-bottom: 1rem; }
.rc-lot-summary-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; }
.rc-lot-summary-title { font-size: 1rem; font-weight: 800; color: #1e40af; margin: 0; display: flex; align-items: center; gap: 0.4rem; }
.rc-lot-summary-badges { display: flex; gap: 0.35rem; }

/* ── Financial Grid ── */
.rc-fin-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.rc-fin-grid .rc-field { margin-bottom: 0; }
.rc-fin-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; }
.rc-fin-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }

/* ── Payment Rows ── */
.rc-pay-container { background: #fefce8; border: 2px solid #fde68a; border-radius: var(--rc-radius); padding: 1rem; margin-bottom: 0.75rem; }
.rc-pay-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; }
.rc-pay-hdr-label { font-weight: 700; font-size: 0.88rem; color: #92400e; }
.rc-pay-grid { display: grid; grid-template-columns: 30px minmax(80px,1fr) minmax(90px,1fr) minmax(130px,1fr) minmax(60px,1fr) 30px; gap: 0.4rem; align-items: center; font-size: 0.68rem; color: #92400e; font-weight: 600; padding: 0 0.4rem; margin-bottom: 0.3rem; }
.rc-pay-rows { }
.rc-pay-row { display: grid; grid-template-columns: 30px minmax(80px,1fr) minmax(90px,1fr) minmax(130px,1fr) minmax(60px,1fr) 30px; gap: 0.4rem; align-items: center; margin-bottom: 0.5rem; padding: 0.5rem; background: #fff; border: 1px solid #e2e8f0; border-radius: var(--rc-radius-sm); transition: box-shadow 0.15s; }
.rc-pay-row:hover { box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
.rc-pay-row-idx { font-size: 0.75rem; font-weight: 700; color: var(--rc-muted); }
.rc-pay-row .rc-input { padding: 0.3rem 0.5rem; font-size: 0.82rem; width: 100%; box-sizing: border-box; }
.rc-pay-row select.rc-input { padding: 0.3rem 0.5rem; font-size: 0.82rem; }
.rc-pay-remove { background: var(--rc-danger-soft); color: var(--rc-danger); border: none; border-radius: 6px; padding: 0.25rem 0.5rem; font-size: 0.85rem; cursor: pointer; transition: all 0.15s; line-height: 1; }
.rc-pay-remove:hover { background: var(--rc-danger); color: #fff; }
.rc-pay-empty { font-size: 0.82rem; color: #a16207; padding: 1rem 0; text-align: center; }

/* ── Badge ── */
.rc-badge { display: inline-block; padding: 0.2rem 0.7rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
.rc-badge-paid { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.rc-badge-partial { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.rc-badge-unpaid { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* ── Skeleton Loading ── */
@keyframes rc-shimmer { 0% { background-position: -200px 0; } 100% { background-position: calc(200px + 100%) 0; } }
.rc-skeleton { background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200px 100%; animation: rc-shimmer 1.5s infinite; border-radius: 6px; }
.rc-skeleton-line { height: 14px; margin-bottom: 10px; width: 100%; }
.rc-skeleton-line:nth-child(2) { width: 85%; }
.rc-skeleton-line:nth-child(3) { width: 70%; }
.rc-skeleton-line:last-child { width: 45%; }

/* ── Select All ── */
.rc-select-all { display: flex; align-items: center; gap: 0.35rem; font-size: 0.82rem; font-weight: 600; color: var(--rc-muted); cursor: pointer; }
.rc-select-all input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--rc-accent); cursor: pointer; }

/* ── Validation ── */
.rc-input.is-invalid, .rc-select.is-invalid, .rc-textarea.is-invalid { border-color: var(--rc-danger) !important; background-color: var(--rc-danger-soft) !important; }
.rc-field-error { color: var(--rc-danger); font-size: 0.75rem; margin-top: 0.2rem; display: block; }

/* ── Responsive ── */
@media (max-width: 640px) {
  .rc-row { grid-template-columns: 1fr; }
  .rc-fin-grid { grid-template-columns: 1fr; }
  .rc-fin-grid-4 { grid-template-columns: 1fr 1fr; }
  .rc-fin-grid-3 { grid-template-columns: 1fr 1fr; }
  .rc-pay-grid { display: none; }
  .rc-pay-row { grid-template-columns: 1fr 1fr; gap: 0.35rem; }
  .rc-pay-row-idx, .rc-pay-remove { display: none; }
  .rc-pay-row .rc-input { min-width: 0; }
}
</style>

<div class="receive-layout" id="receiveLayout">
    <!-- Page Header -->
    <div class="rc-header">
        <h1 class="rc-title">Receive Cylinders from Vendor</h1>
        <p class="rc-subtitle">
            Record cylinders returned from vendor after refilling. Enter refill costs, GST, and settle payment — including advance balance reconciliation.
        </p>
    </div>

    <?php if ($error): ?>
    <div class="rc-alert rc-alert-error">
        <svg class="rc-alert-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>
    <form method="POST" id="lotForm">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="receive_lot">

        <!-- Step 1: Select Vendor & Lot -->
        <div class="rc-card rc-step-1">
            <div class="rc-card-header">
                <span class="rc-card-num">1</span>
                <h3 class="rc-card-title">Select Vendor &amp; Dispatch Lot</h3>
            </div>
            <div class="rc-row">
                <div class="rc-field">
                    <label class="rc-label">Vendor</label>
                    <select name="vendor_id" id="lotVendorSelect" class="rc-select" onchange="onLotVendorChange()">
                        <option value="">— Choose vendor —</option>
                        <?php foreach ($vendors as $v):
                            $lot_count = isset($lots_by_vendor[intval($v['id'])]) ? count($lots_by_vendor[intval($v['id'])]) : 0;
                        ?>
                        <option value="<?php echo $v['id']; ?>" data-lots="<?php echo $lot_count; ?>">
                            <?php echo htmlspecialchars($v['name']); ?>
                            (<?php echo $lot_count; ?> open lots)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rc-field">
                    <label class="rc-label">Dispatch Lot(s) <span style="font-weight:400;color:var(--rc-muted);font-size:0.78rem;">— select one or more</span></label>
                    <div id="lotCheckboxGroup" style="max-height:200px;overflow-y:auto;border:1.5px solid var(--rc-border);border-radius:var(--rc-radius-sm);padding:0.5rem;">
                        <p style="font-size:0.82rem;color:var(--rc-muted);padding:0.5rem;margin:0;text-align:center;">Select a vendor first.</p>
                    </div>
                    <p class="rc-helper">Only open/partial return lots shown.</p>
                </div>
            </div>
        </div>

        <!-- Lot Summary Strip (compact) -->
        <div id="lotSummaryCard" class="rc-card" style="display:none;padding:0.65rem 1rem;">
            <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                <span style="font-weight:700;font-size:0.85rem;color:var(--rc-accent);" id="lotSummaryNumber">—</span>
                <span id="lotSummaryStatusBadge" class="rc-badge rc-badge-unpaid">Open</span>
                <span style="font-size:0.82rem;color:var(--rc-muted);">Total: <strong id="lotSummaryTotal">0</strong></span>
                <span style="font-size:0.82rem;color:#16a34a;">Ret: <strong id="lotSummaryReturned">0</strong></span>
                <span style="font-size:0.82rem;color:#dc2626;">Pend: <strong id="lotSummaryPending">0</strong></span>
                <span style="font-size:0.82rem;color:var(--rc-muted);">Adv: <strong id="lotSummaryAdvance" style="color:#92400e;">₹0</strong></span>
                <span style="font-size:0.82rem;color:var(--rc-muted);">Due: <strong id="lotSummaryRemaining">₹0</strong></span>
                <span id="lotVendorAdvRecon" style="display:none;font-size:0.82rem;color:var(--rc-muted);">Vendor Bal: <strong id="lotAdvanceAvailable">₹0</strong></span>
            </div>
        </div>

        <!-- Step 2: Select Cylinders from Lot -->
        <div class="rc-card rc-step-2" id="lotCylinderSection" style="display:none;">
            <div class="rc-card-header">
                <span class="rc-card-num">2</span>
                <h3 class="rc-card-title">Select Cylinders to Receive</h3>
            </div>
            <p class="rc-helper">Only cylinders not yet received are shown.</p>
            <div class="rc-filter">
                <select id="lotGasFilter" onchange="filterLotCylinders()">
                    <option value="0">All Gas Types</option>
                    <?php foreach ($gas_types as $gt): ?>
                    <option value="<?php echo $gt['id']; ?>"><?php echo htmlspecialchars($gt['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="lotSelectedCount" style="font-size:0.85rem;font-weight:600;color:var(--rc-muted);margin-left:auto;">0 cylinders selected</span>
            </div>
            <div class="cyl-list-scroll rc-cyls-scroll" id="lotCylinderList">
                <p style="text-align:center;padding:2rem 0;color:var(--rc-muted);font-size:0.9rem;">Select a lot above to view pending cylinders.</p>
            </div>
        </div>

        <!-- Step 3: GST & Invoice (collapsible) -->
        <div class="rc-collapsible" id="lotFinancialCollapsible" style="display:none;">
            <button type="button" class="rc-collapse-toggle" onclick="toggleCollapsible(this)">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:var(--rc-accent);color:#fff;font-weight:700;font-size:0.82rem;flex-shrink:0;">3</span>
                GST &amp; Invoice
                <span id="lotGstLockBadge" class="rc-badge rc-badge-unpaid" style="display:none;margin-left:0.25rem;">Locked</span>
                <span class="rc-status-dot"></span>
                <span class="rc-arrow">▼</span>
            </button>
            <div class="rc-collapse-body" id="lotFinancialSection">
                <div class="rc-collapse-body-inner">
                    <!-- Per-Lot GST Status Table (populated by JS) -->
                    <div id="lotGstPerLotContainer" style="margin-bottom:0.85rem;"></div>
                    <!-- GST Rate input for unlocked lots -->
                    <div id="lotGstEditableArea" style="display:none;background:var(--rc-success-soft);border:1px solid #bbf7d0;border-radius:var(--rc-radius);padding:0.85rem 1rem;margin-bottom:0.85rem;">
                        <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                            <div class="rc-field" style="margin-bottom:0;min-width:200px;">
                                <label class="rc-label" style="font-size:0.82rem;">GST Rate <span id="lotGstEditableLabel" style="font-weight:400;color:#64748b;">(for unlocked lots)</span></label>
                                <select id="lotGstRateSelect" class="rc-select" onchange="onGstRateChange()">
                                    <option value="0">No GST (0%)</option>
                                    <option value="5">GST @ 5%</option>
                                    <option value="12">GST @ 12%</option>
                                    <option value="18">GST @ 18%</option>
                                    <option value="custom">Custom Rate</option>
                                </select>
                            </div>
                            <div id="lotCustomGstContainer" style="display:none;">
                                <label class="rc-label" style="font-size:0.82rem;">Enter Rate (%)</label>
                                <input type="number" id="lotCustomGstInput" class="rc-input" value="" min="0" max="100" step="0.01" placeholder="e.g. 12" oninput="updateLotSummary()" style="width:100px;">
                            </div>
                        </div>
                    </div>
                    <!-- GST locked info banner -->
                    <div id="lotGstAllLockedBanner" style="display:none;background:var(--rc-accent-soft);border:1px solid #bfdbfe;border-radius:var(--rc-radius);padding:0.85rem 1rem;margin-bottom:0.85rem;">
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <span style="font-size:0.85rem;color:#1e40af;font-weight:600;">All selected lots have locked GST rates from prior transactions.</span>
                        </div>
                    </div>
                    <input type="hidden" name="gst_rate" id="lotGstRate" value="0">
                    <div class="rc-row">
                        <div class="rc-field">
                            <label class="rc-label">Vendor Invoice Number</label>
                            <input type="text" name="invoice_number" class="rc-input" placeholder="e.g. AAP/2026/078" value="<?php echo htmlspecialchars($_POST['invoice_number'] ?? ''); ?>">
                        </div>
                        <div class="rc-field">
                            <label class="rc-label">Received Date</label>
                            <input type="datetime-local" name="received_date" class="rc-input" style="max-width:240px;" value="<?php echo htmlspecialchars($_POST['received_date'] ?? date('Y-m-d\TH:i')); ?>">
                        </div>
                    </div>
                    <div class="rc-field" style="margin-top:0.85rem;">
                        <label class="rc-label">Internal Notes</label>
                        <textarea name="notes" class="rc-textarea" rows="2" placeholder="Verification notes, quality check comments..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 4: Transport & Adjustments (collapsible) -->
        <div class="rc-collapsible" id="lotAdjustmentCollapsible" style="display:none;">
            <button type="button" class="rc-collapse-toggle" onclick="toggleCollapsible(this)">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:var(--rc-accent);color:#fff;font-weight:700;font-size:0.82rem;flex-shrink:0;">4</span>
                Transport &amp; Adjustments
                <span class="rc-status-dot"></span>
                <span class="rc-arrow">▼</span>
            </button>
            <div class="rc-collapse-body" id="lotAdjustmentSection">
                <div class="rc-collapse-body-inner">
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--rc-radius);padding:1rem;margin-bottom:1rem;">
                        <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:0.5rem;">
                            <h3 style="font-size:0.9rem;font-weight:700;margin:0;color:#1e40af;">Receiving Transportation</h3>
                        </div>
                        <p class="rc-helper" style="margin-bottom:0.75rem;">Total transport cost paid to receive these cylinders. Splits proportionally across lots.</p>
                        <div class="rc-row">
                            <div class="rc-field">
                                <label class="rc-label">Transport Cost (₹)</label>
                                <input type="number" name="receive_transport_cost" id="lotReceiveTransportCost" class="rc-input" value="0.00" min="0" step="0.01" placeholder="0.00" oninput="updateLotSummary()">
                            </div>
                            <div class="rc-field" style="justify-content:flex-end;display:flex;flex-direction:column;">
                                <div id="receiveTransportPerCyl" style="font-size:0.82rem;color:#1e40af;font-weight:600;padding:0.55rem 0;">₹0.00</div>
                            </div>
                        </div>
                    </div>
                    <p class="rc-helper" style="margin-bottom:0.5rem;">Adjust vendor payment for damages, shortages, or extra charges.</p>
                    <div class="rc-row">
                        <div class="rc-field">
                            <label class="rc-label">Deduction (₹)</label>
                            <input type="number" name="deduction_amount" id="lotDeductionAmount" class="rc-input" value="0.00" min="0" step="0.01" placeholder="0.00" oninput="updateLotSummary()">
                        </div>
                        <div class="rc-field">
                            <label class="rc-label">Addition (₹)</label>
                            <input type="number" name="addition_amount" id="lotAdditionAmount" class="rc-input" value="0.00" min="0" step="0.01" placeholder="0.00" oninput="updateLotSummary()">
                        </div>
                    </div>
                    <div class="rc-row" style="margin-top:0.65rem;">
                        <div class="rc-field">
                            <input type="text" name="deduction_notes" class="rc-input" placeholder="Damage, shortage reason..." value="">
                        </div>
                        <div class="rc-field">
                            <input type="text" name="addition_notes" class="rc-input" placeholder="Extra charges notes..." value="">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 5: Summary -->
        <div class="rc-card" id="lotSummarySection" style="display:none;background:var(--admin-surface);">
            <div class="rc-card-header" style="margin-bottom:0.75rem;">
                <span class="rc-card-num">5</span>
                <h3 class="rc-card-title">Summary</h3>
            </div>
            <div class="rc-summary" id="lotSummaryBar">
                <div class="rc-summary-line"><span>Lot:</span><span id="lotSumNumber" style="font-weight:600;color:var(--rc-accent);">—</span></div>
                <div class="rc-summary-line"><span>Vendor:</span><span id="lotSumVendor" style="font-weight:600;">—</span></div>
                <div class="rc-summary-line"><span>Cylinders:</span><span id="lotSumCylCount">0</span></div>
                <div id="lotAllocationContainer" style="display:none;margin-top:0.5rem;padding-top:0.5rem;border-top:1px dashed #d1d5db;">
                    <div style="font-size:0.72rem;font-weight:600;color:var(--rc-muted);margin-bottom:0.35rem;">Allocation per Lot</div>
                    <div id="lotAllocationBody"></div>
                </div>
                <div class="rc-summary-line" style="border-top:1px solid #e2e8f0;padding-top:0.4rem;margin-top:0.3rem;">
                    <span>Refill Amount (Sum):</span><span id="lotSumTaxable">₹0.00</span>
                </div>
                <div class="rc-summary-line" id="lotSumGstLine" style="display:none;">
                    <span>GST (<span id="lotSumGstRate">0</span>%):</span><span id="lotSumGst" style="font-weight:600;">₹0.00</span>
                </div>
                <div class="rc-summary-line">
                    <span>Total Invoice:</span><span id="lotSumGross" style="font-weight:700;">₹0.00</span>
                </div>
                <div class="rc-summary-line rc-summary-neg">
                    <span>Deductions:</span><span id="lotSumDeductions">−₹0.00</span>
                </div>
                <div class="rc-summary-line rc-summary-pos">
                    <span>Additions:</span><span id="lotSumAdditions">+₹0.00</span>
                </div>
                <div id="lotSumTransportLine" class="rc-summary-line" style="display:none;">
                    <span style="color:#075985;font-weight:600;">Receive Transport:</span><span id="lotSumTransport" style="color:#075985;font-weight:600;">₹0.00</span>
                </div>
                <div class="rc-summary-line" id="lotSumAdvanceLine" style="border-top:1px dashed #bfdbfe;padding-top:0.3rem;">
                    <span style="color:#92400e;">Advance Paid:</span><span id="lotSumAdvance" style="color:#92400e;font-weight:600;">−₹0.00</span>
                </div>
                <div class="rc-summary-line" id="lotSumAdvanceBalLine" style="display:none;">
                    <span style="color:#1e40af;">Vendor Adv. Balance:</span><span id="lotSumAdvanceBal" style="color:#1e40af;font-weight:600;">₹0.00</span>
                </div>
                <div class="rc-summary-line" id="lotSumAdvUtilLine" style="display:none;">
                    <span style="color:#1e40af;">Advance Utilization:</span><span id="lotSumAdvUtil" style="color:#1e40af;font-weight:600;">−₹0.00</span>
                </div>
                <div class="rc-summary-line" id="lotSumPaymentRowsLine" style="display:none;">
                    <span style="color:#16a34a;">Payments Collected:</span><span id="lotSumPaymentRowsTotal" style="color:#16a34a;font-weight:600;">₹0.00</span>
                </div>
                <div class="rc-summary-total">
                    <span>Remaining Due:</span><span id="lotSumNet">₹0.00</span>
                </div>
            </div>
        </div>

        <!-- Step 6: Payment (collapsible) -->
        <div class="rc-collapsible" id="lotSettlementCollapsible" style="display:none;">
            <button type="button" class="rc-collapse-toggle" onclick="toggleCollapsible(this)">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:var(--rc-accent);color:#fff;font-weight:700;font-size:0.82rem;flex-shrink:0;">6</span>
                Payment
                <span class="rc-card-sub" id="lotSettlementSubtitle" style="font-weight:400;font-size:0.78rem;"></span>
                <span class="rc-status-dot"></span>
                <span class="rc-arrow">▼</span>
            </button>
            <div class="rc-collapse-body" id="lotSettlementSection">
                <div class="rc-collapse-body-inner">
                    <div id="lotSettledMessage" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--rc-radius);padding:1rem;text-align:center;margin-bottom:0.85rem;">
                        <div style="font-size:1.1rem;font-weight:800;color:#16a34a;" id="lotSettledMessageText">✓ Fully Settled</div>
                    </div>
                    <div id="lotPaymentRowsContainer" class="rc-pay-container" style="background:#fefce8;border:2px solid #fde68a;border-radius:var(--rc-radius);padding:1rem;">
                        <div class="rc-pay-hdr" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                            <span style="font-weight:700;font-size:0.88rem;color:#92400e;">Payment Entries</span>
                            <button type="button" class="btn-sm" style="font-size:0.75rem;padding:0.35rem 0.75rem;background:var(--rc-accent);color:#fff;border:none;border-radius:6px;cursor:pointer;transition:background 0.15s;" onclick="addLotPaymentRow()">+ Add Row</button>
                        </div>
                        <div class="rc-pay-grid">
                            <span>#</span><span>Amount</span><span>Method</span><span>Date</span><span>Reference</span><span></span>
                        </div>
                        <div id="lotPaymentRows" class="rc-pay-rows"></div>
                        <div id="lotNoPaymentMsg" class="rc-pay-empty" style="display:none;font-size:0.82rem;color:#a16207;padding:1rem 0;text-align:center;">
                            No payment rows. Balance tracked as due.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" id="lotSubmitBtn" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;padding:0.75rem;font-size:1rem;border:none;border-radius:10px;background:var(--rc-accent);color:#fff;font-weight:700;cursor:pointer;transition:all 0.2s;" disabled>
            Receive Cylinders &amp; Record Payment
        </button>
    </form>
</div>

<script>
const vendors = <?php echo json_encode($vendors); ?>;
const vendorAdvanceBalances = <?php echo json_encode($vendor_advance_balances); ?>;
const lotsByVendor = <?php echo json_encode($lots_by_vendor); ?>;

const preselectLotId = <?php echo $preselect_lot_id; ?>;
const preselectVendorId = <?php echo $preselect_vendor_id; ?>;

let gasTypesMap = {};
<?php foreach ($gas_types as $gt): ?>
gasTypesMap[<?php echo $gt['id']; ?>] = '<?php echo htmlspecialchars($gt['name']); ?>';
<?php endforeach; ?>

// ── LOT MODE ──
let selectedLotData = null;
let lotPaymentRowIndex = 0;

const lotPayMethods = ['Cash','UPI','Bank Transfer','Cheque','NEFT','RTGS','Online Transfer','Adjustment'];

// ── Gas cost lookup from server-side gasTypeCosts data ──
const gasTypeCosts = <?php
$gtc = [];
foreach ($gas_types as $gt) {
    $sizes = json_decode($gt['size_refill_costs'] ?? '{}', true) ?: [];
    $gtc[$gt['id']] = [
        'name' => $gt['name'],
        'refill_cost' => floatval($gt['refill_cost'] ?? 0),
        'size_refill_costs' => $sizes,
    ];
}
echo json_encode($gtc);
?>;

function getDefaultRefillCost(gasTypeId, sizeCapacity) {
    const gt = gasTypeCosts[gasTypeId];
    if (!gt) return 0;
    if (gt.size_refill_costs && gt.size_refill_costs[sizeCapacity] !== undefined) {
        return parseFloat(gt.size_refill_costs[sizeCapacity]) || 0;
    }
    return gt.refill_cost || 0;
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function getCurrentGstRate() {
    // If editable area is visible, use the dropdown value (applies to unlocked lots only)
    var editableArea = document.getElementById('lotGstEditableArea');
    if (editableArea && editableArea.style.display !== 'none') {
        var selectVal = document.getElementById('lotGstRateSelect').value;
        if (selectVal === 'custom') {
            return parseFloat(document.getElementById('lotCustomGstInput').value) || 0;
        }
        return parseFloat(selectVal) || 0;
    }
    // All lots locked — use the lot's DB-stored rate for display/advance estimation
    var lots = getSelectedLotDataArray();
    if (lots.length > 0) {
        var rate = parseFloat(lots[0].gst_rate || 0);
        var allSame = lots.every(function(l) { return Math.abs(parseFloat(l.gst_rate || 0) - rate) < 0.001; });
        if (allSame) return rate;
        var maxRate = 0;
        lots.forEach(function(l) {
            if (parseFloat(l.advance_amount || 0) > 0 || parseInt(l.gst_locked || 0) > 0) {
                maxRate = Math.max(maxRate, parseFloat(l.gst_rate || 0));
            }
        });
        return maxRate;
    }
    return 0;
}

function onGstRateChange() {
    var selectVal = document.getElementById('lotGstRateSelect').value;
    var customContainer = document.getElementById('lotCustomGstContainer');
    if (selectVal === 'custom') {
        customContainer.style.display = 'block';
    } else {
        customContainer.style.display = 'none';
    }
    updateLotSummary();
}

function toggleCollapsible(btn) {
    const body = btn.nextElementSibling;
    if (!body) return;
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open');
    btn.classList.toggle('open');
    if (!isOpen) {
        body.style.maxHeight = body.scrollHeight + 'px';
    } else {
        body.style.maxHeight = '0';
    }
}

function openCollapsible(el) {
    const btn = el.previousElementSibling;
    if (btn && btn.classList) {
        btn.classList.add('open');
    }
    el.classList.add('open');
    el.style.maxHeight = el.scrollHeight + 'px';
}

function closeCollapsible(el) {
    const btn = el.previousElementSibling;
    if (btn && btn.classList) {
        btn.classList.remove('open');
    }
    el.classList.remove('open');
    el.style.maxHeight = '0';
}

function onLotVendorChange() {
    const vid = document.getElementById('lotVendorSelect').value;
    const container = document.getElementById('lotCheckboxGroup');
    document.getElementById('lotSummaryCard').style.display = 'none';
    document.getElementById('lotCylinderSection').style.display = 'none';
    hideLotFinancialSections();
    selectedLotData = null;

    if (!vid) {
        container.innerHTML = '<p style="font-size:0.82rem;color:var(--rc-muted);padding:0.5rem;margin:0;text-align:center;">Select a vendor first.</p>';
        return;
    }

    const lots = lotsByVendor[vid] || [];
    if (lots.length === 0) {
        container.innerHTML = '<p style="font-size:0.82rem;color:var(--rc-muted);padding:0.5rem;margin:0;text-align:center;">No open lots for this vendor.</p>';
        return;
    }

    let html = '<label style="display:flex;align-items:center;gap:0.35rem;padding:0.3rem 0.5rem;margin-bottom:0.25rem;font-size:0.82rem;font-weight:600;color:var(--rc-muted);cursor:pointer;border-bottom:1px solid var(--rc-border);">' +
        '<input type="checkbox" id="lotSelectAllCheckboxes" onchange="toggleLotSelectAllCheckboxes(this)" style="width:16px;height:16px;accent-color:var(--rc-accent);cursor:pointer;"> Select All</label>';

    lots.forEach(lot => {
        const pending = parseInt(lot.cylinder_count) - parseInt(lot.returned_count);
        const isChecked = preselectLotId > 0 && parseInt(lot.id) === preselectLotId;
        html += '<label class="cyl-checkbox-item" style="padding:0.4rem 0.5rem;margin-bottom:0.2rem;" data-lot-id="' + lot.id + '">' +
            '<input type="checkbox" name="lot_ids[]" value="' + lot.id + '" class="lot-checkbox" data-json=\'' + JSON.stringify(lot).replace(/'/g, "&#39;") + '\'' + (isChecked ? ' checked' : '') + ' onchange="onLotCheckboxChange()" style="width:16px;height:16px;accent-color:var(--rc-accent);cursor:pointer;">' +
            '<span style="flex:1;font-size:0.82rem;">' +
            '<strong style="color:var(--rc-accent);">' + escapeHtml(lot.lot_number) + '</strong>' +
            ' — <span style="color:var(--rc-muted);">' + escapeHtml(lot.vendor_name) + '</span>' +
            ' <span style="color:' + (pending > 0 ? '#dc2626' : '#16a34a') + ';font-weight:600;">(' + pending + ' pending)</span>' +
            '</span></label>';
    });
    container.innerHTML = html;

    if (preselectLotId > 0) {
        onLotCheckboxChange();
    }
}

function toggleLotSelectAllCheckboxes(master) {
    document.querySelectorAll('#lotCheckboxGroup .lot-checkbox').forEach(cb => {
        cb.checked = master.checked;
    });
    onLotCheckboxChange();
}

function getSelectedLotIds() {
    const checked = document.querySelectorAll('#lotCheckboxGroup .lot-checkbox:checked');
    return Array.from(checked).map(cb => parseInt(cb.value));
}

function getSelectedLotDataArray() {
    const result = [];
    document.querySelectorAll('#lotCheckboxGroup .lot-checkbox:checked').forEach(cb => {
        try {
            const lot = JSON.parse(cb.dataset.json);
            result.push(lot);
        } catch(e) {}
    });
    return result;
}

function onLotCheckboxChange() {
    document.getElementById('lotCylinderSection').style.display = 'none';
    hideLotFinancialSections();

    const selectedLots = getSelectedLotDataArray();
    if (selectedLots.length === 0) {
        document.getElementById('lotSummaryCard').style.display = 'none';
        selectedLotData = null;
        return;
    }

    selectedLotData = selectedLots[0]; // Use first for vendor reference (financials handled per-lot in backend)
    showLotSummary(selectedLots);
    renderLotCylinders(getSelectedLotIds());
    document.getElementById('lotCylinderSection').style.display = 'block';
}

function showLotGstSummary(lots) {
    if (!Array.isArray(lots)) lots = [lots];
    const container = document.getElementById('lotGstPerLotContainer');
    const editableArea = document.getElementById('lotGstEditableArea');
    const allLockedBanner = document.getElementById('lotGstAllLockedBanner');
    const editableLabel = document.getElementById('lotGstEditableLabel');

    let hasUnlocked = false;
    let allLocked = true;
    let rowsHtml = '';

    lots.forEach(function(lot, idx) {
        const advanceAmt = parseFloat(lot.advance_amount || 0);
        const gstLocked = parseInt(lot.gst_locked || 0);
        const isLocked = advanceAmt > 0 || gstLocked > 0;
        const dbRate = parseFloat(lot.gst_rate || 0);
        const gstApplicable = parseInt(lot.gst_applicable || 0);

        if (isLocked) {
            hasUnlocked = hasUnlocked || false;
            // locked — show rate from DB
            const rateText = gstApplicable && dbRate > 0 ? dbRate + '%' : 'N/A';
            rowsHtml += '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0.6rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:0.3rem;">' +
                '<span style="font-weight:600;font-size:0.82rem;color:var(--rc-accent);min-width:90px;">' + escapeHtml(lot.lot_number) + '</span>' +
                '<span style="font-size:0.82rem;font-weight:600;color:#1e40af;">' + rateText + '</span>' +
                '<span style="font-size:0.7rem;padding:1px 6px;border-radius:4px;background:#dbeafe;color:#1e40af;font-weight:600;">LOCKED</span>' +
                '<span style="font-size:0.72rem;color:#64748b;margin-left:auto;">Adv: ₹' + advanceAmt.toFixed(2) + '</span></div>';
        } else {
            allLocked = false;
            hasUnlocked = true;
            // unlocked — show placeholder
            rowsHtml += '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0.6rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:0.3rem;">' +
                '<span style="font-weight:600;font-size:0.82rem;color:var(--rc-accent);min-width:90px;">' + escapeHtml(lot.lot_number) + '</span>' +
                '<span style="font-size:0.82rem;color:#16a34a;">—</span>' +
                '<span style="font-size:0.7rem;padding:1px 6px;border-radius:4px;background:#dcfce7;color:#16a34a;font-weight:600;">SETTABLE</span></div>';
        }
    });

    let finalHtml = '';
    if (lots.length > 0) {
        finalHtml += '<div style="font-size:0.72rem;font-weight:600;color:var(--rc-muted);margin-bottom:0.4rem;">Per-Lot GST Status</div>';
        finalHtml += rowsHtml;
    }
    container.innerHTML = finalHtml;

    // Show editable area if any lot is unlocked
    editableArea.style.display = hasUnlocked ? 'block' : 'none';
    allLockedBanner.style.display = allLocked ? 'block' : 'none';
    editableLabel.textContent = hasUnlocked ? '(applies to lots marked SETTABLE above)' : '';

    // Pre-select the first unlocked lot's rate if available
    if (hasUnlocked) {
        const select = document.getElementById('lotGstRateSelect');
        const customContainer = document.getElementById('lotCustomGstContainer');
        const customInput = document.getElementById('lotCustomGstInput');
        const firstUnlocked = lots.find(function(l) {
            const aa = parseFloat(l.advance_amount || 0);
            const gl = parseInt(l.gst_locked || 0);
            return !(aa > 0 || gl > 0);
        });
        const rateToPreselect = firstUnlocked ? parseFloat(firstUnlocked.gst_rate || 0) : 0;
        const preselectOpts = [5, 12, 18];
        const matchedOpt = preselectOpts.find(r => Math.abs(rateToPreselect - r) < 0.001);
        if (matchedOpt !== undefined) {
            select.value = String(matchedOpt);
            customContainer.style.display = 'none';
        } else if (rateToPreselect > 0) {
            select.value = 'custom';
            customInput.value = rateToPreselect;
            customContainer.style.display = 'block';
        } else {
            select.value = '0';
            customContainer.style.display = 'none';
        }
    }

    updateLotSummary();
}

function showLotSummary(lots) {
    if (!Array.isArray(lots)) lots = [lots];
    document.getElementById('lotSummaryCard').style.display = 'block';

    // Aggregate across lots
    let totalCyls = 0, totalReturned = 0, totalAdvance = 0, totalPaid = 0, totalRemaining = 0;
    let lotNumbers = '';
    const vendorId = lots.length > 0 ? lots[0].vendor_id : 0;
    const vendorName = lots.length > 0 ? lots[0].vendor_name : '';

    lots.forEach(lot => {
        totalCyls += parseInt(lot.cylinder_count || 0);
        totalReturned += parseInt(lot.returned_count || 0);
        totalAdvance += parseFloat(lot.advance_amount || 0);
        totalPaid += parseFloat(lot.total_paid || 0);
        totalRemaining += parseFloat(lot.remaining_balance || 0);
        lotNumbers += (lotNumbers ? ', ' : '') + lot.lot_number;
    });
    const pending = totalCyls - totalReturned;

    document.getElementById('lotSummaryNumber').textContent = lotNumbers;
    document.getElementById('lotSummaryTotal').textContent = totalCyls;
    document.getElementById('lotSummaryReturned').textContent = totalReturned;
    document.getElementById('lotSummaryPending').textContent = pending;
    document.getElementById('lotSummaryAdvance').textContent = '₹' + totalAdvance.toFixed(2);
    const remainingEl = document.getElementById('lotSummaryRemaining');
    remainingEl.textContent = '₹' + totalRemaining.toFixed(2);
    remainingEl.style.color = totalRemaining <= 0 ? '#16a34a' : '#dc2626';

    // ── Vendor advance balance ──
    const advBal = parseFloat(vendorAdvanceBalances[vendorId] || 0);
    const reconEl = document.getElementById('lotVendorAdvRecon');
    const advAvailableEl = document.getElementById('lotAdvanceAvailable');
    if (advBal > 0) {
        reconEl.style.display = 'inline';
        advAvailableEl.textContent = '₹' + advBal.toFixed(2);
    } else {
        reconEl.style.display = 'none';
    }

    // ── Lot status badge (aggregated) ──
    const badge = document.getElementById('lotSummaryStatusBadge');
    const allCompleted = lots.every(l => l.lot_status === 'completed');
    const anyPartial = lots.some(l => l.lot_status === 'partial_return');
    if (allCompleted) {
        badge.className = 'rc-badge rc-badge-paid';
        badge.textContent = 'Completed';
    } else if (anyPartial) {
        badge.className = 'rc-badge rc-badge-partial';
        badge.textContent = 'Partial Return';
    } else {
        badge.className = 'rc-badge rc-badge-unpaid';
        badge.textContent = 'Open';
    }

    showLotGstSummary(lots);
    updateLotSummary();
}

function renderLotCylinders(lotIds) {
    const listDiv = document.getElementById('lotCylinderList');
    if (!lotIds || lotIds.length === 0) {
        listDiv.innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.9rem;">Select at least one lot.</p>';
        return;
    }

    listDiv.innerHTML = '<div style="padding:1rem;"><div class="rc-skeleton rc-skeleton-line"></div><div class="rc-skeleton rc-skeleton-line"></div><div class="rc-skeleton rc-skeleton-line"></div><div class="rc-skeleton rc-skeleton-line"></div></div>';

    // Use vendor_id mode for efficient single API call
    const vendorId = document.getElementById('lotVendorSelect').value;
    const apiUrl = vendorId ? 'get-lot-cylinders.php?vendor_id=' + vendorId : 'get-lot-cylinders.php?lot_ids=' + lotIds.join(',');

    fetch(apiUrl)
        .then(r => r.json())
        .then(data => {
            // New format: { cylinders: [...], vendor_advance_balance: X }
            // Legacy format: direct array
            const cylinders = Array.isArray(data) ? data : (data.cylinders || []);
            if (vendorId) {
                const freshBalance = !Array.isArray(data) ? parseFloat(data.vendor_advance_balance || 0) : 0;
                if (freshBalance > 0) {
                    vendorAdvanceBalances[vendorId] = freshBalance;
                }
            }

            if (!cylinders || cylinders.length === 0) {
                listDiv.innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.9rem;">All cylinders from selected lots have been received.</p>';
                return;
            }

            // Group by lot_id from the API response
            const cylByLot = {};
            cylinders.forEach(c => {
                const lid = c.lot_id;
                if (!cylByLot[lid]) cylByLot[lid] = [];
                cylByLot[lid].push(c);
            });

            // Build lot lookup
            const lotLookup = {};
            document.querySelectorAll('#lotCheckboxGroup .lot-checkbox').forEach(cb => {
                try { const lot = JSON.parse(cb.dataset.json); lotLookup[lot.id] = lot; } catch(e) {}
            });

            let totalCyls = 0;
            let allHtml = '';

            // Render each lot group
            Object.keys(cylByLot).forEach(lid => {
                const cyls = cylByLot[lid];
                if (cyls.length === 0) return;

                const lot = lotLookup[lid];
                const lotNum = lot ? lot.lot_number : ('Lot #' + lid);
                totalCyls += cyls.length;

                // Only render if lot is still in selected lots
                if (!lotIds.includes(parseInt(lid))) return;

                // Lot group header
                allHtml += '<div style="background:#f8fafc;padding:0.5rem 0.75rem;margin:0.5rem 0 0.25rem;border-radius:8px;border:1px solid #e2e8f0;font-weight:700;font-size:0.85rem;color:#1e40af;display:flex;justify-content:space-between;align-items:center;">' +
                    '<span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align:middle;margin-right:4px;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg> ' + escapeHtml(lotNum) + '</span>' +
                    '<span style="font-weight:600;color:var(--rc-muted);font-size:0.78rem;">' + cyls.length + ' cylinder' + (cyls.length > 1 ? 's' : '') + '</span></div>';

                cyls.forEach(c => {
                    const defaultCost = getDefaultRefillCost(parseInt(c.gas_type_id), c.size_capacity);
                    let tagClass = 'tag-own';
                    let tagLabel = 'OWN';
                    if (c.ownership_type === 'partner_owned') { tagClass = 'tag-br'; tagLabel = 'BR'; }
                    else if (c.ownership_type === 'consumer_owned') { tagClass = 'tag-con'; tagLabel = 'CON'; }
                    else if (c.ownership_type === 'vendor_owned') { tagClass = 'tag-ven'; tagLabel = 'VEN'; }
                    allHtml += '<label class="cyl-checkbox-item rc-cyl-item" data-gas="' + c.gas_type_id + '" data-lot-id="' + lid + '">' +
                        '<input type="checkbox" name="cylinder_ids[]" value="' + c.id + '" onchange="onLotCylinderCheck()">' +
                        '<span style="flex:1;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">' +
                        '<span class="cyl-serial rc-cyl-serial">' + escapeHtml(c.serial_number) + '</span>' +
                        '<span class="cyl-gas rc-cyl-gas">' + (gasTypesMap[c.gas_type_id] || 'Unknown') + ' — ' + escapeHtml(c.size_capacity) + '</span>' +
                        '<span class="cyl-owner-tag rc-tag ' + tagClass + '">' + tagLabel + '</span>' +
                        '<span style="display:flex;align-items:center;gap:0.25rem;margin-left:auto;">' +
                        '<span style="font-size:0.75rem;color:var(--rc-muted);font-weight:600;">₹</span>' +
                        '<input type="number" name="refill_costs[' + c.id + ']" class="form-control cyl-cost-input rc-cyl-cost" value="' + defaultCost.toFixed(2) + '" min="0" step="0.01" onchange="onLotCylinderCheck()">' +
                        '</span></span></label>';
                });
            });

            if (totalCyls === 0) {
                listDiv.innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.9rem;">All cylinders from selected lots have been received.</p>';
                return;
            }

            allHtml = '<label class="rc-select-all" style="padding:0 0.5rem 0.5rem;"><input type="checkbox" id="lotSelectAllVisibleList" onchange="toggleLotSelectAll(this)"> Select All Visible (' + totalCyls + ' cylinders)</label>' + allHtml;
            listDiv.innerHTML = allHtml;
            document.getElementById('lotSelectedCount').textContent = '0 cylinders selected';
        })
        .catch(() => {
            listDiv.innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.9rem;">Error loading cylinders.</p>';
        });
}

function filterLotCylinders() {
    const gasId = document.getElementById('lotGasFilter').value;
    const items = document.querySelectorAll('#lotCylinderList .cyl-checkbox-item');
    items.forEach(item => {
        if (gasId === '0' || item.dataset.gas === gasId) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
            item.querySelector('input[type="checkbox"]').checked = false;
        }
    });
    updateLotCylinderCount();
    updateLotSummary();
}

function toggleLotSelectAll(chk) {
    const checked = chk.checked;
    document.querySelectorAll('#lotCylinderList .cyl-checkbox-item').forEach(item => {
        if (item.style.display !== 'none') {
            item.querySelector('input[type="checkbox"]').checked = checked;
        }
    });
    onLotCylinderCheck();
}

function onLotCylinderCheck() {
    updateLotCylinderCount();
    updateLotSummary();
}

function updateLotCylinderCount() {
    const checked = document.querySelectorAll('#lotCylinderList .cyl-checkbox-item input[type="checkbox"]:checked');
    document.getElementById('lotSelectedCount').textContent = checked.length + ' cylinders selected';
}

function getLotCheckedElements() {
    return document.querySelectorAll('#lotCylinderList .cyl-checkbox-item input[type="checkbox"]:checked');
}

function getLotCheckedCylinders() {
    return Array.from(getLotCheckedElements()).map(function(cb) { return parseInt(cb.value); });
}

function getLotCylinderCounts() {
    const counts = {};
    getLotCheckedElements().forEach(function(cb) {
        const item = cb.closest('.cyl-checkbox-item');
        const lotId = item ? parseInt(item.dataset.lotId) : null;
        if (lotId) counts[lotId] = (counts[lotId] || 0) + 1;
    });
    return counts;
}

function getLotCheckedCosts() {
    const checked = getLotCheckedElements();
    let total = 0;
    checked.forEach(cb => {
        const item = cb.closest('.cyl-checkbox-item');
        const costInput = item.querySelector('.cyl-cost-input');
        if (costInput) total += parseFloat(costInput.value) || 0;
    });
    return total;
}

function getLotCheckedCostsArray() {
    const checked = getLotCheckedElements();
    const costs = [];
    checked.forEach(cb => {
        const item = cb.closest('.cyl-checkbox-item');
        const costInput = item.querySelector('.cyl-cost-input');
        costs.push(parseFloat(costInput ? costInput.value : 0) || 0);
    });
    return costs;
}

function hideLotFinancialSections() {
    ['lotFinancialCollapsible','lotAdjustmentCollapsible','lotSettlementCollapsible','lotSummarySection'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    ['lotFinancialSection','lotAdjustmentSection','lotSettlementSection'].forEach(id => {
        const el = document.getElementById(id);
        if (el) closeCollapsible(el);
    });
}

// ── Dynamic Payment Rows ──
function addLotPaymentRow(amount) {
    lotPaymentRowIndex++;
    const idx = lotPaymentRowIndex;
    const container = document.getElementById('lotPaymentRows');
    const todayStr = new Date().toISOString().slice(0, 16);

    // Auto-fill suggested amount (match "Remaining Due" from summary)
    if (amount === undefined && selectedLotData) {
        const count = getLotCheckedCylinders().length;
        const sumRefill = getLotCheckedCosts();
        if (count > 0 && sumRefill > 0) {
            const selectedLots = getSelectedLotDataArray();
            const gstRate = getCurrentGstRate();
            const deduction = parseFloat(document.getElementById('lotDeductionAmount').value) || 0;
            const addition = parseFloat(document.getElementById('lotAdditionAmount').value) || 0;
            const gstAmt = gstRate > 0 ? sumRefill * gstRate / 100 : 0;
            const netTotal = sumRefill + gstAmt - deduction + addition;
            const lotCylCounts = getLotCylinderCounts();
            const rab = parseFloat(vendorAdvanceBalances[selectedLotData.vendor_id] || 0);
            let aggregateDue = 0;
            let adv = 0;
            selectedLots.forEach(function(lot) {
                const lotRemaining = parseFloat(lot.remaining_balance || 0);
                const tc = parseInt(lot.cylinder_count) || 1;
                const ar = parseInt(lot.returned_count || 0);
                const lotPending = Math.max(0, tc - ar);
                const lotCylsInBatch = Math.min(lotCylCounts[lot.id] || 0, lotPending);
                const prop = count > 0 ? lotCylsInBatch / count : 0;
                const lotNet = netTotal * prop;
                aggregateDue += Math.max(0, lotNet);
                const la = parseFloat(lot.advance_amount || 0);
                if (la > 0 && rab > 0 && tc > 0 && lotCylsInBatch > 0) {
                    const prorated = la * (lotCylsInBatch / tc);
                    const alreadyUtilized = parseFloat(lot.advance_utilized || 0);
                    const remainingLotAdv = Math.max(0, la - alreadyUtilized);
                    adv += Math.min(prorated, rab, remainingLotAdv, lotNet);
                }
            });
            amount = Math.max(0, aggregateDue - adv);
        }
    }

    const methodOpts = lotPayMethods.map(m => '<option value="' + m + '"' + (m === 'Cash' ? ' selected' : '') + '>' + m + '</option>').join('');

    const div = document.createElement('div');
    div.className = 'payment-row rc-pay-row';
    div.id = 'lotPayRow_' + idx;
    div.innerHTML = `
        <span class="rc-pay-row-idx">#${idx}</span>
        <input type="number" name="payment_rows[${idx}][amount]" placeholder="Amount" class="rc-input" value="${amount ? amount.toFixed(2) : ''}" min="0" step="0.01" oninput="updateLotSummary()">
        <select name="payment_rows[${idx}][method]" class="rc-input">
            <option value="">— Method —</option>
            ${methodOpts}
        </select>
        <input type="datetime-local" name="payment_rows[${idx}][date]" class="rc-input" value="${todayStr}">
        <input type="text" name="payment_rows[${idx}][reference]" placeholder="Ref." class="rc-input">
        <button type="button" class="rc-pay-remove" onclick="removeLotPaymentRow('${idx}')">&times;</button>
    `;
    container.appendChild(div);

    document.getElementById('lotNoPaymentMsg').style.display = 'none';
    updateLotSummary();
}

function removeLotPaymentRow(idx) {
    const row = document.getElementById('lotPayRow_' + idx);
    if (row) row.remove();
    const remaining = document.querySelectorAll('#lotPaymentRows .payment-row');
    if (remaining.length === 0) {
        document.getElementById('lotNoPaymentMsg').style.display = 'block';
    }
    updateLotSummary();
}

function getLotPaymentRowsTotal() {
    let total = 0;
    document.querySelectorAll('#lotPaymentRows .payment-row').forEach(row => {
        const amtInput = row.querySelector('input[name*="[amount]"]');
        if (amtInput) total += parseFloat(amtInput.value) || 0;
    });
    return total;
}

// ── Update Payment Summary Card and all financials ──
function updateLotSummary() {
    const selectedLots = getSelectedLotDataArray();
    if (!selectedLotData || selectedLots.length === 0) return;
    const checked = getLotCheckedCylinders();
    const count = checked.length;
    const sumRefill = getLotCheckedCosts();
    const gstRate = getCurrentGstRate();
    const deduction = parseFloat(document.getElementById('lotDeductionAmount').value) || 0;
    const addition = parseFloat(document.getElementById('lotAdditionAmount').value) || 0;
    const receiveTransport = parseFloat(document.getElementById('lotReceiveTransportCost').value) || 0;

    const hasCyls = count > 0 && sumRefill > 0;
    document.getElementById('lotFinancialCollapsible').style.display = hasCyls ? '' : 'none';
    document.getElementById('lotAdjustmentCollapsible').style.display = hasCyls ? '' : 'none';
    document.getElementById('lotSummarySection').style.display = hasCyls ? 'block' : 'none';
    document.getElementById('lotSettlementCollapsible').style.display = hasCyls ? '' : 'none';

    // Auto-expand collapsible sections when data is present
    if (hasCyls) {
        ['lotFinancialSection','lotAdjustmentSection','lotSettlementSection'].forEach(id => {
            const el = document.getElementById(id);
            if (el) openCollapsible(el);
        });
    }

    // ── Sync GST rate to hidden input for backend ──
    document.getElementById('lotGstRate').value = gstRate;

    // ── Compute financials ──
    const gstAmount = gstRate > 0 && sumRefill > 0 ? sumRefill * gstRate / 100 : 0;
    const gross = sumRefill + gstAmount;
    const netTotal = gross - deduction + addition;

    // ── Receive transport (display total only; splits proportionally across lots in backend) ──
    document.getElementById('receiveTransportPerCyl').textContent = receiveTransport > 0
        ? '₹' + receiveTransport.toFixed(2) + ' (splits proportionally across selected lots)'
        : '₹0.00';

    // ── Vendor advance balance ──
    const remainingAdvBalance = parseFloat(vendorAdvanceBalances[selectedLotData.vendor_id] || 0);

    // ── Payment rows total ──
    const paymentRowsTotal = getLotPaymentRowsTotal();

    // ── Per-lot selected cylinder counts ──
    const lotCylCounts = getLotCylinderCounts();

    // ── Aggregate remaining due across all lots (existing + new batch) ──
    let aggregateDue = 0;
    selectedLots.forEach(function(lot) {
        const lotRemaining = parseFloat(lot.remaining_balance || 0);
        const totalCyls = parseInt(lot.cylinder_count) || 1;
        const alreadyReturned = parseInt(lot.returned_count || 0);
        const lotPending = Math.max(0, totalCyls - alreadyReturned);
        const lotCylsInBatch = Math.min(lotCylCounts[lot.id] || 0, lotPending);
        const prop = count > 0 ? lotCylsInBatch / count : 0;
        const lotNet = netTotal * prop;
        aggregateDue += Math.max(0, lotNet);
    });

    // ── Advance utilization estimate (matches backend logic per lot) ──
    let estimatedAdvanceAdjusted = 0;
    selectedLots.forEach(function(lot) {
        const lotAdvanceAmt = parseFloat(lot.advance_amount || 0);
        const totalCyls = parseInt(lot.cylinder_count) || 1;
        const alreadyReturned = parseInt(lot.returned_count || 0);
        const lotPending = Math.max(0, totalCyls - alreadyReturned);
        const lotCylsInBatch = Math.min(lotCylCounts[lot.id] || 0, lotPending);
        if (lotAdvanceAmt > 0 && remainingAdvBalance > 0 && totalCyls > 0 && lotCylsInBatch > 0) {
            const prop = count > 0 ? lotCylsInBatch / count : 0;
            const lotNet = netTotal * prop;
            const prorated = lotAdvanceAmt * (lotCylsInBatch / totalCyls);
            const alreadyUtilized = parseFloat(lot.advance_utilized || 0);
            const remainingLotAdv = Math.max(0, lotAdvanceAmt - alreadyUtilized);
            estimatedAdvanceAdjusted += Math.min(prorated, remainingAdvBalance, remainingLotAdv, lotNet);
        }
    });

    const effectiveNet = netTotal - estimatedAdvanceAdjusted;
    const aggregateAfterAdvance = Math.max(0, aggregateDue - estimatedAdvanceAdjusted);
    const isFullyPaid = selectedLots.every((l) => l.payment_status === 'paid');
    const batchCoveredByAdvance = estimatedAdvanceAdjusted > 0 && aggregateAfterAdvance <= 0;
    const noPaymentNeeded = isFullyPaid || (batchCoveredByAdvance && paymentRowsTotal <= 0);

    // ── Remaining after payments ──
    const remainingDue = noPaymentNeeded ? 0 : Math.max(0, aggregateAfterAdvance - paymentRowsTotal);

    // ── Settlement section display ──
    const settledMsg = document.getElementById('lotSettledMessage');
    const payContainer = document.getElementById('lotPaymentRowsContainer');
    const sub = document.getElementById('lotSettlementSubtitle');
    if (isFullyPaid) {
        settledMsg.style.display = hasCyls ? 'block' : 'none';
        settledMsg.querySelector('div > div:first-child').textContent = '✓ This Lot is Fully Settled';
        payContainer.style.display = 'none';
        sub.textContent = '— fully settled';
    } else if (batchCoveredByAdvance && paymentRowsTotal <= 0) {
        settledMsg.style.display = hasCyls ? 'block' : 'none';
        settledMsg.querySelector('div > div:first-child').textContent = '✓ Covered by Vendor Advance';
        payContainer.style.display = 'none';
        sub.textContent = '— covered by advance — no payment due';
    } else {
        settledMsg.style.display = 'none';
        payContainer.style.display = 'block';
        sub.textContent = '— collect remaining amount from vendor';
    }

    // ── Per-lot allocation breakdown with GST ──
    const allocContainer = document.getElementById('lotAllocationContainer');
    const allocBody = document.getElementById('lotAllocationBody');
    if (selectedLots.length > 1) {
        allocContainer.style.display = 'block';
        let allocHtml = '';
        selectedLots.forEach(function(lot) {
            const lotTotal = parseInt(lot.cylinder_count) || 1;
            const lotReturned = parseInt(lot.returned_count) || 0;
            const lotPending = lotTotal - lotReturned;
            const lotCylInLot = Math.min(lotCylCounts[lot.id] || 0, lotPending);
            const lotProportion = count > 0 ? lotCylInLot / count : 0;
            const aa = parseFloat(lot.advance_amount || 0);
            const gl = parseInt(lot.gst_locked || 0);
            const isLocked = aa > 0 || gl > 0;
            const dbRate = parseFloat(lot.gst_rate || 0);
            const gstLabel = isLocked
                ? (dbRate > 0 ? dbRate + '%' : '0%')
                : '↻ ' + gstRate + '%';
            allocHtml += '<div style="display:flex;justify-content:space-between;font-size:0.78rem;padding:0.15rem 0;">' +
                '<span>' + escapeHtml(lot.lot_number) + ' <span style="color:' + (isLocked ? '#1e40af' : '#16a34a') + ';font-weight:600;">' + gstLabel + '</span></span>' +
                '<span>' + lotPending + ' pend · ₹' + (sumRefill * lotProportion).toFixed(2) + '</span></div>';
        });
        allocBody.innerHTML = allocHtml;
    } else {
        allocContainer.style.display = 'none';
    }

    // ── Summary bar ──
    const allLotNumbers = selectedLots.map(function(l) { return l.lot_number; }).join(', ');
    document.getElementById('lotSumNumber').textContent = allLotNumbers;
    document.getElementById('lotSumVendor').textContent = selectedLotData.vendor_name || '—';
    document.getElementById('lotSumCylCount').textContent = count + ' cyl (₹' + sumRefill.toFixed(2) + ')';
    document.getElementById('lotSumTaxable').textContent = '₹' + sumRefill.toFixed(2);

    const gstLine = document.getElementById('lotSumGstLine');
    if (gstRate > 0 && sumRefill > 0) {
        gstLine.style.display = '';
        document.getElementById('lotSumGstRate').textContent = gstRate + (selectedLots.length > 1 ? '%*' : '%');
        document.getElementById('lotSumGst').textContent = '₹' + gstAmount.toFixed(2);
    } else if (sumRefill > 0) {
        // Check if any lot has locked GST > 0
        const anyLockedGst = selectedLots.some(function(l) {
            return (parseFloat(l.advance_amount || 0) > 0 || parseInt(l.gst_locked || 0) > 0) && parseFloat(l.gst_rate || 0) > 0;
        });
        if (anyLockedGst) {
            gstLine.style.display = '';
            document.getElementById('lotSumGstRate').textContent = selectedLots.length > 1 ? 'per lot*' : 'locked';
            document.getElementById('lotSumGst').textContent = '₹' + gstAmount.toFixed(2);
        } else {
            gstLine.style.display = 'none';
        }
    } else {
        gstLine.style.display = 'none';
    }

    document.getElementById('lotSumGross').textContent = '₹' + gross.toFixed(2);
    document.getElementById('lotSumDeductions').textContent = '−₹' + deduction.toFixed(2);
    document.getElementById('lotSumAdditions').textContent = '+₹' + addition.toFixed(2);

    // ── Transport line in summary ──
    const transportLine = document.getElementById('lotSumTransportLine');
    const transportEl = document.getElementById('lotSumTransport');
    if (receiveTransport > 0) {
        transportLine.style.display = '';
        transportEl.textContent = '₹' + receiveTransport.toFixed(2);
    } else {
        transportLine.style.display = 'none';
    }

    const totalAdvanceAmt = selectedLots.reduce((sum, lot) => sum + parseFloat(lot.advance_amount || 0), 0);
    const advLine = document.getElementById('lotSumAdvanceLine');
    if (totalAdvanceAmt > 0) {
        advLine.style.display = '';
        document.getElementById('lotSumAdvance').textContent = '₹' + totalAdvanceAmt.toFixed(2);
    } else {
        advLine.style.display = 'none';
    }

    const advBalLine = document.getElementById('lotSumAdvanceBalLine');
    const vendorAdvBal = selectedLotData ? parseFloat(vendorAdvanceBalances[selectedLotData.vendor_id] || 0) : 0;
    if (vendorAdvBal > 0) {
        advBalLine.style.display = '';
        document.getElementById('lotSumAdvanceBal').textContent = '₹' + vendorAdvBal.toFixed(2);
    } else {
        advBalLine.style.display = 'none';
    }

    const advUtilLine = document.getElementById('lotSumAdvUtilLine');
    if (estimatedAdvanceAdjusted > 0) {
        advUtilLine.style.display = '';
        document.getElementById('lotSumAdvUtil').textContent = '−₹' + estimatedAdvanceAdjusted.toFixed(2);
    } else {
        advUtilLine.style.display = 'none';
    }

    const payRowsLine = document.getElementById('lotSumPaymentRowsLine');
    if (paymentRowsTotal > 0) {
        payRowsLine.style.display = '';
        document.getElementById('lotSumPaymentRowsTotal').textContent = '₹' + paymentRowsTotal.toFixed(2);
    } else {
        payRowsLine.style.display = 'none';
    }

    // ── Validate payment doesn't exceed aggregate amount due (after advance) ──
    const exceedsLimit = paymentRowsTotal > aggregateAfterAdvance;
    const netEl = document.getElementById('lotSumNet');
    if (exceedsLimit) {
        netEl.textContent = '⚠ Exceeds due by ₹' + (paymentRowsTotal - aggregateAfterAdvance).toFixed(2);
        netEl.style.color = '#dc2626';
    } else {
        netEl.textContent = '₹' + remainingDue.toFixed(2);
        netEl.style.color = remainingDue <= 0 ? '#16a34a' : '#dc2626';
    }
    const warnEl = document.getElementById('lotOverLimitWarn');
    if (exceedsLimit) {
        if (!warnEl) {
            const w = document.createElement('div');
            w.id = 'lotOverLimitWarn';
            w.className = 'rc-alert rc-alert-error';
            w.style.marginTop = '0.5rem';
            w.innerHTML = '<span><strong>Over limit:</strong> Total payment (₹' + paymentRowsTotal.toFixed(2) + ') exceeds total amount due across lots (₹' + aggregateAfterAdvance.toFixed(2) + '). Reduce payment amount.</span>';
            netEl.parentElement.appendChild(w);
        } else {
            warnEl.style.display = '';
            warnEl.querySelector('span').innerHTML = '<strong>Over limit:</strong> Total payment (₹' + paymentRowsTotal.toFixed(2) + ') exceeds total amount due across lots (₹' + aggregateAfterAdvance.toFixed(2) + '). Reduce payment amount.';
        }
    } else if (warnEl) {
        warnEl.style.display = 'none';
    }

    // ── Submit button ──
    const btn = document.getElementById('lotSubmitBtn');
    if (count > 0 && selectedLotData) {
        btn.disabled = false;
        const lotNames = selectedLots.map(function(l) { return l.lot_number; }).join(', ');
        let label = 'Receive ' + count + ' Cylinder' + (count > 1 ? 's' : '') + ' from ' + lotNames;
        if (isFullyPaid) {
            label = 'Receive Cylinders Only — Lot Already Settled';
        } else if (batchCoveredByAdvance && paymentRowsTotal <= 0) {
            label = 'Receive ' + count + ' Cylinder' + (count > 1 ? 's' : '') + ' — Covered by Advance';
        } else if (paymentRowsTotal > 0) {
            label += ' (Collect ₹' + paymentRowsTotal.toFixed(2) + ')';
        }
        btn.textContent = label;
    } else {
        btn.disabled = true;
        btn.textContent = 'Receive Cylinders & Record Payment';
    }
}

// ── Lot form validation ──
document.getElementById('lotForm').addEventListener('submit', function(e) {
    clearIndErrors();

    const submitBtn = document.getElementById('lotSubmitBtn');

    let valid = true;

    const selectedLotIds = getSelectedLotIds();
    if (selectedLotIds.length === 0) {
        showIndError('lotVendorSelect', 'Select at least one dispatch lot.');
        valid = false;
    }
    const cyls = getLotCheckedCylinders();
    if (cyls.length === 0) {
        showIndError('lotSelectedCount', 'Select at least one cylinder to receive.');
        valid = false;
    }

    // Validate payment rows: if amount is > 0, method must be provided
    document.querySelectorAll('#lotPaymentRows .payment-row').forEach(row => {
        const amtInput = row.querySelector('input[name*="[amount]"]');
        const methodSelect = row.querySelector('select[name*="[method]"]');
        if (amtInput && methodSelect) {
            const amt = parseFloat(amtInput.value) || 0;
            if (amt > 0 && !methodSelect.value) {
                if (!valid) return;
                methodSelect.classList.add('is-invalid');
                const err = document.createElement('span');
                err.className = 'field-error rc-field-error';
                err.textContent = 'Payment method required when amount > 0.';
                methodSelect.parentElement.appendChild(err);
                valid = false;
            }
        }
    });

    // ── Validate payment doesn't exceed total amount due across all lots (aggregate) ──
    const payTotal = getLotPaymentRowsTotal();
    const sumR = getLotCheckedCosts();
    const gstR = getCurrentGstRate();
    const gstAmt = gstR > 0 ? sumR * gstR / 100 : 0;
    const ded = parseFloat(document.getElementById('lotDeductionAmount').value) || 0;
    const add = parseFloat(document.getElementById('lotAdditionAmount').value) || 0;
    const batchNet = Math.max(0, sumR + gstAmt - ded + add);
    const lotCylCountsSubmit = getLotCylinderCounts();
    const selectedLotsSubmit = getSelectedLotDataArray();
    const rabSubmit = selectedLotsSubmit.length > 0 ? parseFloat(vendorAdvanceBalances[selectedLotsSubmit[0].vendor_id] || 0) : 0;
    let totalDue = 0;
    selectedLotsSubmit.forEach(lot => {
        const lotRemaining = parseFloat(lot.remaining_balance || 0);
        const la = parseFloat(lot.advance_amount || 0);
        const tc = parseInt(lot.cylinder_count) || 1;
        const ar = parseInt(lot.returned_count || 0);
        const lotPending = Math.max(0, tc - ar);
        const lotCyls = Math.min(lotCylCountsSubmit[lot.id] || 0, lotPending);
        const prop = cyls.length > 0 ? lotCyls / cyls.length : 0;
        const lotNet = batchNet * prop;
        let adv = 0;
        if (la > 0 && rabSubmit > 0 && tc > 0 && lotCyls > 0) {
            var alreadyUtilized = parseFloat(lot.advance_utilized || 0);
            adv = Math.min(la * (lotCyls / tc), rabSubmit, Math.max(0, la - alreadyUtilized), lotNet);
        }
        totalDue += Math.max(0, lotNet - adv);
    });
    if (payTotal > totalDue) {
        showIndError('lotSelectedCount', 'Total payment (₹' + payTotal.toFixed(2) + ') exceeds total amount due across lots (₹' + totalDue.toFixed(2) + '). Reduce payment amount.');
        valid = false;
    }

    if (!valid) {
        e.preventDefault();
        const firstErr = document.querySelector('#lotForm .is-invalid');
        if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    e.preventDefault();

    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing...';
    document.getElementById('lotForm').submit();
});

function clearIndErrors() {
    document.querySelectorAll('.field-error, .rc-field-error').forEach(el => el.remove());
    document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
}

function showIndError(fieldId, message) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    field.classList.add('is-invalid');
    const existing = field.parentElement.querySelector('.field-error, .rc-field-error');
    if (existing) existing.remove();
    const err = document.createElement('span');
    err.className = 'field-error rc-field-error';
    err.textContent = message;
    field.parentElement.appendChild(err);
}

// ── Modal helpers ──
function openModal(id) { var el = document.getElementById(id); if (el) el.classList.add('active'); }
function closeModal(id) { var el = document.getElementById(id); if (el) el.classList.remove('active'); }

// ── Input listeners for clearing errors ──
document.addEventListener('DOMContentLoaded', function() {
    // Pre-select lot if lot_id provided in URL
    if (preselectLotId > 0 && preselectVendorId > 0) {
        const vendorSel = document.getElementById('lotVendorSelect');
        for (let i = 0; i < vendorSel.options.length; i++) {
            if (parseInt(vendorSel.options[i].value) === preselectVendorId) {
                vendorSel.value = preselectVendorId;
                onLotVendorChange();
                setTimeout(() => {
                    document.querySelectorAll('#lotCheckboxGroup .lot-checkbox').forEach(cb => {
                        if (parseInt(cb.value) === preselectLotId) {
                            cb.checked = true;
                        }
                    });
                    onLotCheckboxChange();
                }, 100);
                break;
            }
        }
    }

    document.querySelectorAll('#lotForm .form-control, #lotForm .rc-input, #lotForm .rc-select, #lotForm select').forEach(el => {
        el.addEventListener('input', function() {
            this.classList.remove('is-invalid');
            this.classList.remove('is-invalid');
            (this.parentElement.querySelector('.field-error') || this.parentElement.querySelector('.rc-field-error'))?.remove();
        });
        el.addEventListener('change', function() {
            this.classList.remove('is-invalid');
            this.classList.remove('is-invalid');
            (this.parentElement.querySelector('.field-error') || this.parentElement.querySelector('.rc-field-error'))?.remove();
        });
    });

    // ── Auto-create first payment row when collapsible expands ──
    const lotSettlementObserver = new MutationObserver(function() {
        const section = document.getElementById('lotSettlementSection');
        if (section.classList.contains('open')) {
            const rows = document.querySelectorAll('#lotPaymentRows .payment-row');
            if (rows.length === 0) {
                addLotPaymentRow();
            }
        }
    });
    lotSettlementObserver.observe(document.getElementById('lotSettlementSection'), {
        attributes: true,
        attributeFilter: ['class']
    });

    // Clean up orphaned payment rows on form submit (strip empty)
    document.getElementById('lotForm').addEventListener('submit', function() {
        document.querySelectorAll('#lotPaymentRows .payment-row').forEach(row => {
            const amtInput = row.querySelector('input[name*="[amount]"]');
            if (amtInput) {
                const amt = parseFloat(amtInput.value) || 0;
                if (amt <= 0) {
                    // Remove the empty row before submit
                    row.remove();
                }
            }
        });
    });

});
</script>

<?php require_once __DIR__ . '/bulk_operation_dialog.php'; ?>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
