<?php
$page_title = "Customer Invoices";
$active_menu = "customer_invoices";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/csrf.php';

runGSTMigrations($pdo);
runRefillWithoutExchangeMigrations($pdo);
runRefillRentalMigrations($pdo);

$flash = $_SESSION['ci_flash'] ?? '';
$error = $_SESSION['ci_error'] ?? '';
unset($_SESSION['ci_flash'], $_SESSION['ci_error']);

// ── POST: Pay Invoice ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_invoice') {
    validateCsrfToken();
    $order_id = intval($_POST['order_id'] ?? 0);
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $gst_rate = floatval($_POST['gst_rate'] ?? 0);
    $original_gst_rate = floatval($_POST['original_gst_rate'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $valid_methods = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'NEFT', 'RTGS', 'Advance', 'Deposit'];

    if ($order_id <= 0 || $payment_amount <= 0) {
        $error = 'Invalid request.';
    } elseif (!in_array($payment_method, $valid_methods)) {
        $error = 'Invalid payment method.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch();
            if (!$order) throw new Exception("Order not found.");

            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$order['customer_id']]);
            $customer = $stmt->fetch();
            if (!$customer) throw new Exception("Customer not found.");

            $gst_changed = ($gst_rate > 0 && $original_gst_rate > 0 && abs($gst_rate - $original_gst_rate) > 0.01);
            $gst_newly_set = ($gst_rate > 0 && $original_gst_rate <= 0);

            if ($gst_changed || $gst_newly_set) {
                $stmt = $pdo->prepare("UPDATE refill_orders SET gst_rate = ? WHERE id = ?");
                $stmt->execute([$gst_rate, $order_id]);

                $stmt = $pdo->prepare("SELECT * FROM refill_order_items WHERE refill_order_id = ?");
                $stmt->execute([$order_id]);
                $items = $stmt->fetchAll();

                $new_tax_total = 0;
                foreach ($items as $item) {
                    $qty = max(1, intval($item['qty']));
                    $taxable = floatval($item['price_per_unit']) * $qty;
                    $calc = calculateGST($taxable, $gst_rate);
                    $stmt2 = $pdo->prepare("UPDATE refill_order_items SET gst_rate = ?, taxable_amount = ?, gst_amount = ?, cgst = ?, sgst = ?, igst = ? WHERE id = ?");
                    $stmt2->execute([$gst_rate, $calc['taxable'], $calc['gst_amount'], $calc['cgst'], $calc['sgst'], $calc['igst'], $item['id']]);
                    $new_tax_total += $calc['gst_amount'];
                }

                $new_grand_total = floatval($order['subtotal']) + floatval($order['deposit_amount']) + $new_tax_total - floatval($order['discount']);

                $stmt = $pdo->prepare("UPDATE refill_orders SET tax_amount = ?, grand_total = ? WHERE id = ?");
                $stmt->execute([$new_tax_total, $new_grand_total, $order_id]);
            }

            $ledger_group_id = generateLedgerGroupId();
            $ledger_title = "Invoice payment - Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);
            $pay_notes = $notes ?: "Payment received - Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);
            if (($gst_changed || $gst_newly_set) && !empty($notes)) {
                $pay_notes .= " | GST: {$original_gst_rate}% → {$gst_rate}%";
            }
            $stmt = $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, ?, 'refill_payment', ?, ?, ?)");
            $stmt->execute([$order['customer_id'], $order_id, $payment_amount, $payment_method, $pay_notes, $payment_date, $ledger_group_id]);

            recalculateOrderPaymentStatus($pdo, $order_id);

            $stmt = $pdo->prepare("SELECT paid_amount, due_amount FROM refill_orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $order_after = $stmt->fetch();
            $new_due = floatval($order_after['due_amount'] ?? 0);

            $stmt = $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'payment_received', ?, ?, ?)");
            $stmt->execute([$ledger_group_id, $order['customer_id'], $ledger_title, $payment_amount, $payment_date]);

            // Deduct from advance/deposit balance if paying via those methods
            if ($payment_method === 'Advance') {
                $stmt = $pdo->prepare("UPDATE customers SET advance_balance = GREATEST(0, advance_balance - ?) WHERE id = ?");
                $stmt->execute([$payment_amount, $order['customer_id']]);
            } elseif ($payment_method === 'Deposit') {
                $stmt = $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?");
                $stmt->execute([$payment_amount, $order['customer_id']]);
            }

            // Record credit transaction for credit orders
            if (!empty($order['is_credit_order'])) {
                $stmt = $pdo->prepare("INSERT INTO credit_transactions (customer_id, refill_order_id, transaction_type, amount, balance_after, description, ledger_group_id) VALUES (?, ?, 'payment', ?, ?, ?, ?)");
                $stmt->execute([$order['customer_id'], $order_id, $payment_amount, $new_due, "Payment from invoice page - Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT), $ledger_group_id]);
            }

            if ($gst_changed || $gst_newly_set) {
                syncGSTFromOrder($pdo, $order_id);
            }

            $pdo->commit();
            syncInventory($pdo);

            $_SESSION['ci_flash'] = "Payment of ₹" . number_format($payment_amount, 2) . " received. Outstanding: ₹" . number_format($new_due, 2);
            echo "<script>window.location.href='customer-invoices.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['ci_error'] = $e->getMessage();
            echo "<script>window.location.href='customer-invoices.php';</script>";
            exit();
        }
    }
}

// ── POST: Return Cylinders (Closed-loop per order) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_cylinders') {
    validateCsrfToken();
    $order_id = intval($_POST['order_id'] ?? 0);
    $selected_items = $_POST['selected_items'] ?? [];
    $damage_amounts = $_POST['damage_amount'] ?? [];
    $damage_descs = $_POST['damage_desc'] ?? [];

    if ($order_id <= 0 || empty($selected_items)) {
        $error = 'Please select at least one cylinder to return.';
    } else {
        try {
            $pdo->beginTransaction();
            $order_stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
            $order_stmt->execute([$order_id]);
            $order = $order_stmt->fetch();
            if (!$order) throw new Exception("Order not found.");

            $ledger_group_id = generateLedgerGroupId();
            $returned_count = 0;

            foreach ($selected_items as $item_id) {
                $item_id = intval($item_id);
                $damage_amt = floatval($damage_amounts[$item_id] ?? 0);
                $damage_desc = trim($damage_descs[$item_id] ?? '');

                $item_stmt = $pdo->prepare("SELECT oi.*, c.serial_number, c.status as cyl_status, c.ownership_type, c.original_owner_customer_id, c.current_partner_id FROM refill_order_items oi LEFT JOIN cylinders c ON oi.cylinder_id = c.id WHERE oi.id = ? AND oi.refill_order_id = ? AND oi.cylinder_id IS NOT NULL AND oi.returned_cylinder_id IS NULL");
                $item_stmt->execute([$item_id, $order_id]);
                $item = $item_stmt->fetch();
                if (!$item) continue;

                $cyl_id = intval($item['cylinder_id']);

                // Update refill_order_items with returned_cylinder_id
                $upd = $pdo->prepare("UPDATE refill_order_items SET returned_cylinder_id = cylinder_id, damage_amount = ?, damage_description = ? WHERE id = ?");
                $upd->execute([$damage_amt, $damage_desc, $item_id]);

                // Update cylinder status
                if ($item['ownership_type'] === 'consumer_owned' && $item['original_owner_customer_id'] == $order['customer_id']) {
                    $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?")->execute([$cyl_id]);
                    logCylinderTransaction($pdo, $cyl_id, $order['customer_id'], null, 'consumer_give_back', "Order #{$order_id}: consumer-owned cylinder returned (self). " . ($damage_desc ? "Damage: {$damage_desc}" : ""), $ledger_group_id);
                } elseif ($item['ownership_type'] === 'consumer_owned') {
                    $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$order['customer_id'], $cyl_id]);
                    logCylinderTransaction($pdo, $cyl_id, $order['customer_id'], null, 'consumer_return', "Order #{$order_id}: consumer-owned cylinder transferred. " . ($damage_desc ? "Damage: {$damage_desc}" : ""), $ledger_group_id);
                } else {
                    $status_after = $damage_amt > 0 ? 'under_maintenance' : 'empty';
                    $pdo->prepare("UPDATE cylinders SET status = ?, current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?")->execute([$status_after, $cyl_id]);
                    logCylinderTransaction($pdo, $cyl_id, $order['customer_id'], null, 'return_from_customer', "Order #{$order_id}: cylinder returned. " . ($damage_desc ? "Damage: {$damage_desc}" : ""), $ledger_group_id);
                }

                $returned_count++;
            }

            if ($returned_count === 0) throw new Exception("No eligible cylinders to return.");

            $ledger_title = "Cylinder Return – Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'exchange_settlement', ?, 0, NOW())");
            $stmt->execute([$ledger_group_id, $order['customer_id'], $ledger_title]);

            $pdo->commit();
            syncInventory($pdo);

            $_SESSION['ci_flash'] = "$returned_count cylinder(s) returned successfully for Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . ".";
            echo "<script>window.location.href='customer-invoices.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['ci_error'] = $e->getMessage();
            echo "<script>window.location.href='customer-invoices.php';</script>";
            exit();
        }
    }
}

// ── POST: Settle Deposit ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_deposit') {
    validateCsrfToken();
    $order_id = intval($_POST['order_id'] ?? 0);
    $refund_amount = floatval($_POST['refund_amount'] ?? 0);
    $damage_deduction = floatval($_POST['damage_deduction'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $notes = trim($_POST['notes'] ?? '');

    if ($order_id <= 0 || $refund_amount <= 0) {
        $error = 'Invalid request.';
    } else {
        try {
            $pdo->beginTransaction();
            $order_stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
            $order_stmt->execute([$order_id]);
            $order = $order_stmt->fetch();
            if (!$order) throw new Exception("Order not found.");

            $actual_deposit = floatval($order['deposit_amount']);
            $already_settled = floatval($order['deposit_settled'] ?? 0);
            $remaining = $actual_deposit - $already_settled;
            if ($refund_amount + $damage_deduction > $remaining) throw new Exception("Total exceeds remaining deposit (₹" . number_format($remaining, 2) . ").");

            $ledger_group_id = generateLedgerGroupId();

            if ($damage_deduction > 0) {
                $stmt = $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, ?, 'deposit_damage', ?, ?, ?)");
                $stmt->execute([$order['customer_id'], $order_id, $damage_deduction, $payment_method, "Damage deduction – Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . ($notes ? " - $notes" : ""), $payment_date, $ledger_group_id]);
            }

            if ($refund_amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, ?, 'deposit_refunded', ?, ?, ?)");
                $stmt->execute([$order['customer_id'], $order_id, $refund_amount, $payment_method, "Deposit refund – Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . ($notes ? " - $notes" : ""), $payment_date, $ledger_group_id]);
            }

            $new_settled = $already_settled + $refund_amount + $damage_deduction;
            $upd = $pdo->prepare("UPDATE refill_orders SET deposit_settled = ? WHERE id = ?");
            $upd->execute([$new_settled, $order_id]);

            $total_deduction = $refund_amount + $damage_deduction;
            $upd2 = $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?");
            $upd2->execute([$total_deduction, $order['customer_id']]);

            $group_title = "Deposit Settlement – Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);
            if ($damage_deduction > 0) $group_title .= " (Refund: ₹$refund_amount, Damage: ₹$damage_deduction)";
            else $group_title .= " (Refund: ₹$refund_amount)";
            $stmt = $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'payment_refunded', ?, ?, ?)");
            $stmt->execute([$ledger_group_id, $order['customer_id'], $group_title, $total_deduction, $payment_date]);

            $pdo->commit();
            syncInventory($pdo);

            $_SESSION['ci_flash'] = "Deposit settled for Order #INV-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . ". Refund: ₹" . number_format($refund_amount, 2) . ", Damage: ₹" . number_format($damage_deduction, 2);
            echo "<script>window.location.href='customer-invoices.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['ci_error'] = $e->getMessage();
            echo "<script>window.location.href='customer-invoices.php';</script>";
            exit();
        }
    }
}

// ── POST: Bulk Rental Return ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_rental_return') {
    validateCsrfToken();
    $order_id = intval($_POST['order_id'] ?? 0);
    $selected_items = $_POST['selected_rental_items'] ?? [];
    $cylinder_id_map = $_POST['cylinder_id'] ?? [];
    $conditions = $_POST['condition'] ?? [];
    $damage_charges = $_POST['damage_charge'] ?? [];
    $deposit_deducts = $_POST['deposit_deduct'] ?? [];
    $return_date_raw = trim($_POST['return_date'] ?? date('Y-m-d'));
    $return_date = $return_date_raw . ' 00:00:00';
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');

    if ($order_id <= 0 || empty($selected_items)) {
        $error = 'No cylinders selected for return.';
    } else {
        try {
            $pdo->beginTransaction();
            $order_stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
            $order_stmt->execute([$order_id]);
            $order = $order_stmt->fetch();
            if (!$order) throw new Exception("Order not found.");
            $customer_id = intval($order['customer_id']);

            $cust_stmt = $pdo->prepare("SELECT deposit_balance FROM customers WHERE id = ?");
            $cust_stmt->execute([$customer_id]);
            $customer = $cust_stmt->fetch();
            if (!$customer) throw new Exception("Customer not found.");
            $deposit_balance = floatval($customer['deposit_balance']);

            $ledger_group_id = generateLedgerGroupId();
            $processed = [];
            $total_rent = 0;
            $total_damage = 0;
            $total_deducted = 0;
            $total_collected = 0;

            foreach ($selected_items as $item_id) {
                $item_id = intval($item_id);
                $cylinder_id = intval($cylinder_id_map[$item_id] ?? 0);
                if ($cylinder_id <= 0) continue;
                $condition = $conditions[$item_id] ?? 'empty';
                $damage_charge = floatval($damage_charges[$item_id] ?? 0);
                $deduct_amt = floatval($deposit_deducts[$item_id] ?? 0);

                $cyl_stmt = $pdo->prepare("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ? AND c.status = 'with_customer' AND c.current_customer_id = ?");
                $cyl_stmt->execute([$cylinder_id, $customer_id]);
                $cylinder = $cyl_stmt->fetch();
                if (!$cylinder) continue;
                if (!$cylinder['borrow_date']) continue;

                $borrow_ts = strtotime($cylinder['borrow_date']);
                $return_ts = strtotime($return_date);
                if ($return_ts < $borrow_ts) continue;

                $daily_rate = floatval($cylinder['daily_rent_rate'] ?? 0);
                $free_days = intval($cylinder['free_days'] ?? 0);
                $days_held = floor(($return_ts - $borrow_ts) / 86400);
                $chargeable_days = max(0, $days_held - $free_days);
                $rent_amount = $chargeable_days * $daily_rate;
                $total_charges = $rent_amount + $damage_charge;
                $deposit_deducted = min($deduct_amt, $total_charges, $deposit_balance);
                $collected = $total_charges - $deposit_deducted;

                // Update refill_order_items (closed-loop)
                $pdo->prepare("UPDATE refill_order_items SET returned_cylinder_id = cylinder_id, damage_amount = ? WHERE id = ?")
                    ->execute([$damage_charge, $item_id]);

                // Insert rental_returns
                $stmt = $pdo->prepare("INSERT INTO rental_returns (customer_id, cylinder_id, refill_order_item_id, borrow_date, return_date, chargeable_days, daily_rate, rent_amount, damage_charge, damage_description, deposit_deducted, total_collected, payment_method, notes, ledger_group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$customer_id, $cylinder_id, $item_id, $cylinder['borrow_date'], $return_date, $chargeable_days, $daily_rate, $rent_amount, $damage_charge, null, $deposit_deducted, $collected, $payment_method, null, $ledger_group_id]);
                $return_id = $pdo->lastInsertId();

                // Create rent payment
                if ($collected > 0) {
                    $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, ledger_group_id) VALUES (?, NULL, ?, ?, 'rent_payment', ?, ?)")
                        ->execute([$customer_id, $collected, $payment_method, null, $ledger_group_id]);
                }

                // Handle deposit deduction
                if ($deposit_deducted > 0) {
                    $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?")
                        ->execute([$deposit_deducted, $customer_id]);
                    $deposit_balance -= $deposit_deducted;
                    $pdo->prepare("UPDATE refill_orders SET deposit_settled = COALESCE(deposit_settled,0) + ? WHERE id = ?")
                        ->execute([$deposit_deducted, $order_id]);
                    $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, ledger_group_id) VALUES (?, ?, ?, ?, 'deposit_refunded', ?, ?)")
                        ->execute([$customer_id, $order_id, -$deposit_deducted, $payment_method, 'Deducted from deposit for rental return', $ledger_group_id]);
                }

                // Update cylinder status
                $new_status = ($condition === 'filled') ? 'filled' : ($damage_charge > 0 ? 'under_maintenance' : 'empty');
                $pdo->prepare("UPDATE cylinders SET status = ?, current_customer_id = NULL, borrow_date = NULL WHERE id = ?")
                    ->execute([$new_status, $cylinder_id]);

                logCylinderTransaction($pdo, $cylinder_id, $customer_id, null, 'return_from_customer', "Rental return via bulk order #$order_id. " . ($damage_desc ? "Damage: $damage_desc" : ""));

                $total_rent += $rent_amount;
                $total_damage += $damage_charge;
                $total_deducted += $deposit_deducted;
                $total_collected += $collected;
                $processed[] = $cylinder_id;
            }

            if (empty($processed)) throw new Exception("No valid rental cylinders processed.");

            // Ledger group
            $serial_list = '';
            $cyl_labels = $pdo->prepare("SELECT serial_number FROM cylinders WHERE id IN (" . implode(',', array_fill(0, count($processed), '?')) . ")");
            $cyl_labels->execute($processed);
            $serials = $cyl_labels->fetchAll(\PDO::FETCH_COLUMN);
            $serial_list = implode(', ', $serials);
            $group_title = "Rental Return – $serial_list (₹" . number_format($total_collected, 2) . " collected" . ($total_deducted > 0 ? ", ₹" . number_format($total_deducted, 2) . " deducted" : "") . ")";
            $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'rental_return', ?, ?, ?)")
                ->execute([$ledger_group_id, $customer_id, $group_title, $total_collected, $return_date]);

            // Sync
            syncCustomerActiveCylinderCounts($pdo, $customer_id);

            $pdo->commit();
            syncInventory($pdo);

            // Send email
            require_once __DIR__ . '/../portal/email.php';
            sendRentalSettlementNotification($customer_id, $serial_list, '', '', $total_rent, $total_damage, $total_deducted, $total_collected, $payment_method, $pdo);

            echo "<script>window.location.href='bulk-rental-invoice.php?group_id=$ledger_group_id';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['ci_error'] = $e->getMessage();
            echo "<script>window.location.href='customer-invoices.php';</script>";
            exit();
        }
    }
}

// ── Filters ──
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$from_date = trim($_GET['from'] ?? '');
$to_date = trim($_GET['to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (c.name LIKE ? OR c.mobile LIKE ? OR o.id LIKE ?)";
    $s = "%{$search}%";
    $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($status_filter !== '') {
    $where .= " AND o.payment_status = ?";
    $params[] = $status_filter;
}
if ($from_date) {
    $where .= " AND o.order_date >= ?";
    $params[] = $from_date . ' 00:00:00';
}
if ($to_date) {
    $where .= " AND o.order_date <= ?";
    $params[] = $to_date . ' 23:59:59';
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM refill_orders o JOIN customers c ON o.customer_id = c.id $where");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    $pages = max(1, ceil($total / $per_page));
    $offset = ($page - 1) * $per_page;

    $stmt = $pdo->prepare("
        SELECT o.*, c.name AS customer_name, c.mobile, c.gst_number,
            (SELECT GROUP_CONCAT(CONCAT(COALESCE(gt.name,'Gas'), ' (', oi.size_capacity, ') x', oi.qty) SEPARATOR ', ')
             FROM refill_order_items oi
             LEFT JOIN gas_types gt ON oi.gas_type_id = gt.id
             WHERE oi.refill_order_id = o.id) AS items_summary,
            (SELECT GROUP_CONCAT(DISTINCT oi.is_rental ORDER BY oi.is_rental SEPARATOR ',')
             FROM refill_order_items oi WHERE oi.refill_order_id = o.id) AS type_flags,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.refill_order_id = o.id AND COALESCE(p.payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')), 0) AS paid_amount,
            GREATEST(0, COALESCE(o.grand_total,0) - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.refill_order_id = o.id AND COALESCE(p.payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')), 0)) AS due_amount,
            CASE
                WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.refill_order_id = o.id AND COALESCE(p.payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')), 0) >= COALESCE(o.grand_total,0) THEN 'paid'
                WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.refill_order_id = o.id AND COALESCE(p.payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')), 0) > 0 THEN 'partial'
                ELSE 'pending'
            END AS payment_status
        FROM refill_orders o
        JOIN customers c ON o.customer_id = c.id
        $where
        ORDER BY o.order_date DESC, o.id DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    // Stats (dynamically computed from payments table)
    $total_count = $pdo->query("SELECT COUNT(*) FROM refill_orders")->fetchColumn();
    $total_amount = $pdo->query("SELECT COALESCE(SUM(grand_total), 0) FROM refill_orders")->fetchColumn();
    $total_paid = $pdo->query("SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.refill_order_id IS NOT NULL AND COALESCE(p.payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')")->fetchColumn();
    $total_due = max(0, floatval($total_amount) - floatval($total_paid));
    $status_counts = $pdo->query("SELECT SUM(CASE WHEN paid >= grand_total THEN 1 ELSE 0 END) AS paid_count, SUM(CASE WHEN paid > 0 AND paid < grand_total THEN 1 ELSE 0 END) AS partial_count, SUM(CASE WHEN paid <= 0 THEN 1 ELSE 0 END) AS pending_count FROM (SELECT o.id, o.grand_total, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.refill_order_id = o.id AND COALESCE(p.payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')), 0) AS paid FROM refill_orders o) sub")->fetch();
    $paid_count = $status_counts['paid_count'];
    $partial_count = $status_counts['partial_count'];
    $pending_count = $status_counts['pending_count'];

    // Fetch cylinder status per order
    $order_statuses = [];
    if (!empty($orders)) {
        $ids = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt2 = $pdo->prepare("
            SELECT cco.refill_order_id, cco.status, COUNT(*) as cnt
            FROM customer_cylinder_orders cco
            WHERE cco.refill_order_id IN ($placeholders)
            GROUP BY cco.refill_order_id, cco.status
        ");
        $stmt2->execute($ids);
        $rows = $stmt2->fetchAll();
        foreach ($rows as $r) {
            $oid = $r['refill_order_id'];
            if (!isset($order_statuses[$oid])) $order_statuses[$oid] = ['total' => 0, 'delivered' => 0, 'archived' => 0];
            $order_statuses[$oid]['total'] += intval($r['cnt']);
            if (in_array($r['status'], ['delivered', 'archived'])) {
                $order_statuses[$oid]['delivered'] += intval($r['cnt']);
            }
        }

        // Get issued counts from refill_order_items (exclude product items is_rental=3)
        $stmt3 = $pdo->prepare("
            SELECT oi.refill_order_id, SUM(oi.qty) as issued
            FROM refill_order_items oi
            WHERE oi.refill_order_id IN ($placeholders) AND oi.is_rental != 3
            GROUP BY oi.refill_order_id
        ");
        $stmt3->execute($ids);
        $issued_rows = $stmt3->fetchAll();
        foreach ($issued_rows as $ir) {
            $oid = $ir['refill_order_id'];
            if (!isset($order_statuses[$oid])) $order_statuses[$oid] = ['total' => 0, 'delivered' => 0];
            $order_statuses[$oid]['issued'] = intval($ir['issued']);
        }

        // Unreturned cylinder counts per order (for Return button)
        $unreturned_counts = [];
        $rental_counts = [];
        $stmt4 = $pdo->prepare("
            SELECT oi.refill_order_id, COUNT(*) as unreturned,
                   SUM(CASE WHEN oi.is_rental = 1 THEN 1 ELSE 0 END) as rental_unreturned
            FROM refill_order_items oi
            WHERE oi.refill_order_id IN ($placeholders) AND oi.cylinder_id IS NOT NULL AND oi.returned_cylinder_id IS NULL
            GROUP BY oi.refill_order_id
        ");
        $stmt4->execute($ids);
        foreach ($stmt4->fetchAll() as $ur) {
            $unreturned_counts[$ur['refill_order_id']] = intval($ur['unreturned']);
            $rental_counts[$ur['refill_order_id']] = intval($ur['rental_unreturned']);
        }

        // Rental items data for bulk return modal
        $rental_items_data = [];
        if (!empty($ids)) {
            $stmt_rental = $pdo->prepare("
                SELECT oi.id as item_id, oi.refill_order_id, oi.cylinder_id, oi.size_capacity, oi.is_rental,
                       c.serial_number, c.borrow_date, c.daily_rent_rate, c.free_days,
                       c.ownership_type, c.original_owner_customer_id, c.current_partner_id,
                       g.name as gas_name, ro.customer_id
                FROM refill_order_items oi
                JOIN refill_orders ro ON oi.refill_order_id = ro.id
                JOIN cylinders c ON oi.cylinder_id = c.id
                LEFT JOIN gas_types g ON oi.gas_type_id = g.id
                WHERE oi.refill_order_id IN ($placeholders)
                  AND oi.cylinder_id IS NOT NULL AND oi.returned_cylinder_id IS NULL
                  AND oi.is_rental = 1
                  AND c.borrow_date IS NOT NULL
                ORDER BY oi.refill_order_id, oi.id
            ");
            $stmt_rental->execute($ids);
            foreach ($stmt_rental->fetchAll() as $ri) {
                $oid = $ri['refill_order_id'];
                if (!isset($rental_items_data[$oid])) $rental_items_data[$oid] = [];
                $rental_items_data[$oid][] = [
                    'item_id' => intval($ri['item_id']),
                    'cylinder_id' => intval($ri['cylinder_id']),
                    'customer_id' => intval($ri['customer_id'] ?? 0),
                    'serial' => $ri['serial_number'] ?? '—',
                    'gas' => ($ri['gas_name'] ?? 'Gas') . ' (' . ($ri['size_capacity'] ?? '') . ')',
                    'borrow_date' => $ri['borrow_date'],
                    'daily_rate' => floatval($ri['daily_rent_rate'] ?? 0),
                    'free_days' => intval($ri['free_days'] ?? 0),
                    'ownership' => $ri['ownership_type'] ?? 'owned',
                    'original_owner' => intval($ri['original_owner_customer_id'] ?? 0),
                    'partner_name' => '',
                ];
            }
        }
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $orders = [];
    $total_count = 0; $total_amount = 0; $total_paid = 0; $total_due = 0;
    $paid_count = 0; $partial_count = 0; $pending_count = 0;
}
?>
<?php if ($flash): ?>
<div class="ci-alert ci-success"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="ci-alert ci-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="ci-top-wrap">
    <div class="ci-top-row">
        <h1 class="ci-h1">Customer Invoices</h1>
        <div class="ci-tools">
            <div class="ci-srch"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="ciSearch" placeholder="Search customer or invoice..." onkeyup="filterRows()"></div>
            <input type="date" id="fromDate" value="<?= $from_date ?>" onchange="applyFilter()" class="ci-d">
            <input type="date" id="toDate" value="<?= $to_date ?>" onchange="applyFilter()" class="ci-d">
            <a href="refill-orders.php" class="ci-btn-new"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> New Order</a>
        </div>
    </div>
    <div class="ci-mid-row">
        <div class="ci-stats">
            <span class="ci-st-i"><span class="ci-dot ci-dot-blue"></span><strong><?= $total_count ?></strong> Invoices</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-amber"></span><strong>₹<?= number_format($total_amount, 0) ?></strong> Total</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-green"></span><strong>₹<?= number_format($total_paid, 0) ?></strong> Paid</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot <?= $total_due > 0 ? 'ci-dot-red' : 'ci-dot-green' ?>"></span><strong>₹<?= number_format($total_due, 0) ?></strong> Due</span>
        </div>
        <div class="ci-chips">
            <a href="?" class="ci-ch <?= $status_filter === '' ? 'on' : '' ?>">All <span><?= $total_count ?></span></a>
            <a href="?status=pending<?= $search ? '&search=' . urlencode($search) : '' ?>" class="ci-ch <?= $status_filter === 'pending' ? 'on' : '' ?>">Pending <span><?= $pending_count ?></span></a>
            <a href="?status=partial<?= $search ? '&search=' . urlencode($search) : '' ?>" class="ci-ch <?= $status_filter === 'partial' ? 'on' : '' ?>">Partial <span><?= $partial_count ?></span></a>
            <a href="?status=paid<?= $search ? '&search=' . urlencode($search) : '' ?>" class="ci-ch <?= $status_filter === 'paid' ? 'on' : '' ?>">Paid <span><?= $paid_count ?></span></a>
        </div>
    </div>
</div>

<div class="admin-card" style="padding:0;overflow:auto;">
    <?php if (empty($orders)): ?>
    <div style="text-align:center;padding:3rem 1rem;color:var(--admin-muted);">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.35;margin-bottom:1rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <p style="font-size:0.95rem;font-weight:600;">No customer invoices found</p>
        <p style="font-size:0.82rem;margin-top:0.35rem;">Create a new order to generate an invoice.</p>
    </div>
    <?php else: ?>
    <table class="admin-table" id="ciTable">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Type</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Items</th>
                <th style="text-align:right;">Total</th>
                <th style="text-align:right;">Paid</th>
                <th style="text-align:right;">Due</th>
                <th style="text-align:right;">Deposit</th>
                <th>GST</th>
                <th>Order Status</th>
                <th>Payment</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $o):
                $inv_no = $o['invoice_number'] ?: 'INV-' . date('Y', strtotime($o['order_date'])) . '-' . str_pad($o['id'], 4, '0', STR_PAD_LEFT);
                $due = floatval($o['due_amount']);
                $paid = floatval($o['paid_amount']);
                $total_gt = floatval($o['grand_total']);
                $gst_rate_val = floatval($o['gst_rate'] ?? 0);
                $status_class = $o['payment_status'] === 'paid' ? 'badge-paid' : ($o['payment_status'] === 'partial' ? 'badge-partial' : 'badge-unpaid');

                // Order type from type_flags
                $flags = array_unique(array_filter(array_map('intval', explode(',', $o['type_flags'] ?? ''))));
                $has_rental = in_array(1, $flags);
                $has_sell = in_array(2, $flags);
                $has_refill = in_array(0, $flags);
                $has_product = in_array(3, $flags);
                $has_service = in_array(4, $flags);
                $type_count = count($flags);
                if ($type_count > 1) { $ot_label = 'Mixed'; $ot_badge = 'badge-in-use'; }
                elseif ($has_sell) { $ot_label = 'Sale'; $ot_badge = 'badge-under-maintenance'; }
                elseif ($has_rental) { $ot_label = 'Rental'; $ot_badge = 'badge-rental'; }
                elseif ($has_product) { $ot_label = 'Product'; $ot_badge = 'badge-refill'; }
                elseif ($has_service) { $ot_label = 'Service'; $ot_badge = 'badge-service'; }
                elseif ($has_refill) { $ot_label = 'Refill'; $ot_badge = 'badge-refill'; }
                else { $ot_label = 'Order'; $ot_badge = 'badge-refill'; }

                // Order cylinder status
                $osd = $order_statuses[$o['id']] ?? null;
                $issued = $osd['issued'] ?? 0;
                $delivered = $osd['delivered'] ?? 0;
                if ($type_count === 1 && $has_product) {
                    $cyl_status = '<span style="color:#059669;">✓ Sold</span>';
                } elseif ($issued > 0) {
                    if ($delivered >= $issued) $cyl_status = '<span style="color:#059669;">✓ Delivered</span>';
                    else $cyl_status = $delivered . '/' . $issued . ' delivered';
                } else {
                    $cyl_status = '<span style="color:var(--admin-muted);">—</span>';
                }

                // Determine which return button to show
                $has_rental_unreturned = ($rental_counts[$o['id']] ?? 0) > 0;
                $has_non_rental_unreturned = (($unreturned_counts[$o['id']] ?? 0) - ($rental_counts[$o['id']] ?? 0)) > 0;
                $deposit_amt = floatval($o['deposit_amount']);
                $deposit_settled = floatval($o['deposit_settled'] ?? 0);
            ?>
            <tr class="ci-row" data-search="<?= strtolower($inv_no . ' ' . $o['customer_name'] . ' ' . $o['mobile']) ?>">
                <td><a href="invoice.php?order_id=<?= $o['id'] ?>" class="inv-ref-link"><?= htmlspecialchars($inv_no) ?></a></td>
                <td><span class="badge <?= $ot_badge ?>" style="font-size:0.6rem;padding:2px 6px;"><?= $ot_label ?></span></td>
                <td>
                    <strong><?= htmlspecialchars($o['customer_name']) ?></strong>
                    <div style="font-size:0.72rem;color:var(--admin-muted);"><?= htmlspecialchars($o['mobile']) ?></div>
                </td>
                <td style="font-size:0.82rem;color:var(--admin-muted);"><?= date('d-M-Y', strtotime($o['order_date'])) ?></td>
                <td style="font-size:0.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($o['items_summary'] ?? '') ?>">
                    <?= htmlspecialchars($o['items_summary'] ?? '—') ?>
                </td>
                <td style="text-align:right;font-weight:700;">₹<?= number_format($total_gt, 0) ?></td>
                <td style="text-align:right;color:#059669;">₹<?= number_format($paid, 0) ?></td>
                <td style="text-align:right;font-weight:700;color:<?= $due > 0 ? '#dc2626' : '#059669' ?>;">₹<?= number_format($due, 0) ?></td>
                <td style="text-align:right;font-size:0.78rem;">
                    <?php if ($deposit_amt > 0): ?>
                        <div style="font-weight:700;">₹<?= number_format($deposit_amt, 0) ?></div>
                        <div style="font-size:0.65rem;color:var(--admin-muted);">
                            <?= $deposit_settled > 0 ? 'Settled: ₹' . number_format($deposit_settled, 0) : 'Unsettled' ?>
                            <?php if ($deposit_amt - $deposit_settled > 0): ?>
                                <br><span style="color:#7c3aed;">Remaining: ₹<?= number_format($deposit_amt - $deposit_settled, 0) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--admin-muted);">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $gst_rate_val > 0 ? $gst_rate_val . '%' : '<span style="color:var(--admin-muted);">—</span>' ?></td>
                <td style="font-size:0.78rem;"><?= $cyl_status ?></td>
                <td><span class="badge <?= $status_class ?>"><?= ucfirst($o['payment_status']) ?></span></td>
                <td>
                    <div class="inv-actions" style="gap:0.35rem;">
                        <?php if ($due > 0): ?>
                        <button class="btn-primary" style="padding:0.3rem 0.65rem;font-size:0.72rem;border-radius:6px;border:none;cursor:pointer;" onclick="openPayModal(<?= $o['id'] ?>, '<?= htmlspecialchars($inv_no) ?>', '<?= htmlspecialchars($o['customer_name']) ?>', <?= $total_gt ?>, <?= $paid ?>, <?= $due ?>, <?= $gst_rate_val ?>)">Pay</button>
                        <?php endif; ?>
                        <a href="invoice.php?order_id=<?= $o['id'] ?>" class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.72rem;border-radius:6px;text-decoration:none;">Invoice</a>
                        <?php if ($has_rental_unreturned): ?>
                        <button class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.72rem;border-radius:6px;border:none;cursor:pointer;" onclick="openRentalReturnModal(<?= $o['id'] ?>, '<?= htmlspecialchars($inv_no) ?>', '<?= htmlspecialchars($o['customer_name']) ?>')">Return</button>
                        <?php elseif ($has_non_rental_unreturned): ?>
                        <button class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.72rem;border-radius:6px;border:none;cursor:pointer;" onclick="openReturnModal(<?= $o['id'] ?>, '<?= htmlspecialchars($inv_no) ?>', '<?= htmlspecialchars($o['customer_name']) ?>')">Return</button>
                        <?php endif; ?>
                        <?php if ($deposit_amt > 0 && $deposit_settled < $deposit_amt):
                            $dep_rem = $deposit_amt - $deposit_settled;
                        ?>
                        <button class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.72rem;border-radius:6px;border:none;cursor:pointer;" onclick="openDepositModal(<?= $o['id'] ?>, '<?= htmlspecialchars($inv_no) ?>', <?= $deposit_amt ?>, <?= $deposit_settled ?>)">Settle (₹<?= number_format($dep_rem, 0) ?>)</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div style="display:flex;justify-content:center;gap:0.5rem;padding:1rem;border-top:1px solid var(--admin-border);">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>" class="btn-secondary" style="padding:0.35rem 0.75rem;font-size:0.8rem;border-radius:6px;text-decoration:none;<?= $i === $page ? 'background:var(--admin-accent);color:#fff;border-color:var(--admin-accent);' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Pay Modal -->
<div class="modal" id="payModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3>Pay Invoice</h3>
            <button class="modal-close" onclick="closeModal('payModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="pay_invoice">
            <input type="hidden" name="order_id" id="pay_order_id">
            <input type="hidden" name="original_gst_rate" id="pay_original_gst_rate">

            <div style="background:var(--admin-card-bg, #f8fafc);border-radius:8px;padding:0.75rem 1rem;margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.35rem;">
                    <span style="color:var(--admin-muted);">Invoice</span>
                    <span id="pay_inv_display" style="font-weight:700;">—</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.35rem;">
                    <span style="color:var(--admin-muted);">Customer</span>
                    <span id="pay_customer_display" style="font-weight:600;">—</span>
                </div>
                <hr style="border-color:var(--admin-border);margin:0.5rem 0;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.25rem;">
                    <span>Grand Total</span>
                    <span id="pay_total_display" style="font-weight:700;">₹0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.25rem;">
                    <span style="color:#059669;">Already Paid</span>
                    <span id="pay_paid_display" style="font-weight:600;color:#059669;">₹0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:1.1rem;">
                    <span style="font-weight:800;">Amount Due</span>
                    <span id="pay_due_display" style="font-weight:800;color:#dc2626;">₹0</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Payment Amount *</label>
                <input type="number" name="payment_amount" id="pay_amount" class="form-control" step="0.01" min="0.01" required oninput="updateDuePreview()">
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method *</label>
                <select name="payment_method" class="form-control" required>
                    <option value="Cash">Cash</option>
                    <option value="UPI">UPI</option>
                    <option value="Bank Transfer" selected>Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                    <option value="NEFT">NEFT</option>
                    <option value="RTGS">RTGS</option>
                    <option value="Advance">Advance</option>
                    <option value="Deposit">Deposit</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Date *</label>
                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">GST Rate (%)</label>
                <input type="number" name="gst_rate" id="pay_gst_rate" class="form-control" step="0.01" min="0" value="0" onchange="checkGSTChange()">
                <div id="gst_warning" style="display:none;margin-top:0.5rem;padding:0.5rem 0.75rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:0.78rem;color:#92400e;">
                    ⚠️ This order was created on <strong><span id="gst_original_label">0</span>% GST</strong>. Changing it will recalculate item-level tax amounts and update the GST ledger.
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;margin-top:0.25rem;">
                <button type="button" class="btn-secondary" style="flex:1;padding:0.6rem;border-radius:8px;font-size:0.85rem;cursor:pointer;" onclick="closeModal('payModal')">Cancel</button>
                <button type="submit" class="btn-primary" style="flex:2;padding:0.6rem;border-radius:8px;font-size:0.85rem;justify-content:center;">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Cylinder Return Modal -->
<div class="modal" id="returnModal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3>Return Cylinders</h3>
            <button class="modal-close" onclick="closeModal('returnModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="return_cylinders">
            <input type="hidden" name="order_id" id="return_order_id">
            <div id="return_modal_body">
                <div style="text-align:center;padding:2rem;color:var(--admin-muted);">Loading...</div>
            </div>
            <div style="display:flex;gap:0.75rem;margin-top:1rem;border-top:1px solid var(--admin-border);padding-top:1rem;">
                <button type="button" class="btn-secondary" style="flex:1;padding:0.6rem;border-radius:8px;font-size:0.85rem;cursor:pointer;" onclick="closeModal('returnModal')">Cancel</button>
                <button type="submit" class="btn-primary" style="flex:2;padding:0.6rem;border-radius:8px;font-size:0.85rem;justify-content:center;">Confirm Return</button>
            </div>
        </form>
    </div>
</div>

<!-- Deposit Settlement Modal -->
<div class="modal" id="depositModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3>Settle Deposit</h3>
            <button class="modal-close" onclick="closeModal('depositModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="settle_deposit">
            <input type="hidden" name="order_id" id="dep_order_id">

            <div style="background:var(--admin-card-bg, #f8fafc);border-radius:8px;padding:0.75rem 1rem;margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.35rem;">
                    <span style="color:var(--admin-muted);">Invoice</span>
                    <span id="dep_inv_display" style="font-weight:700;">—</span>
                </div>
                <hr style="border-color:var(--admin-border);margin:0.5rem 0;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.25rem;">
                    <span>Deposit Collected</span>
                    <span id="dep_collected_display" style="font-weight:700;">₹0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.25rem;">
                    <span style="color:var(--admin-muted);">Already Settled</span>
                    <span id="dep_settled_display" style="font-weight:600;color:var(--admin-muted);">₹0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:1.1rem;">
                    <span style="font-weight:800;">Remaining</span>
                    <span id="dep_remaining_display" style="font-weight:800;color:#059669;">₹0</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Refund Amount *</label>
                <input type="number" name="refund_amount" id="dep_refund" class="form-control" step="0.01" min="0" required oninput="updateDepositPreview()">
            </div>
            <div class="form-group">
                <label class="form-label">Damage Deduction</label>
                <input type="number" name="damage_deduction" id="dep_damage" class="form-control" step="0.01" min="0" value="0" oninput="updateDepositPreview()">
                <small style="color:var(--admin-muted);font-size:0.72rem;">Deducted from deposit before refund</small>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method *</label>
                <select name="payment_method" class="form-control" required>
                    <option value="Cash">Cash</option>
                    <option value="UPI">UPI</option>
                    <option value="Bank Transfer" selected>Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Date *</label>
                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;margin-top:0.25rem;">
                <button type="button" class="btn-secondary" style="flex:1;padding:0.6rem;border-radius:8px;font-size:0.85rem;cursor:pointer;" onclick="closeModal('depositModal')">Cancel</button>
                <button type="submit" class="btn-primary" style="flex:2;padding:0.6rem;border-radius:8px;font-size:0.85rem;justify-content:center;">Confirm Settlement</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Rental Return Modal -->
<div class="modal" id="rentalReturnModal">
    <div class="modal-content" style="max-width:860px;">
        <div class="modal-header">
            <h3>Bulk Rental Return</h3>
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <span id="rental_customer_label" style="font-size:0.82rem;color:var(--admin-muted);font-weight:600;display:none;"></span>
            </div>
            <button class="modal-close" onclick="closeModal('rentalReturnModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="bulk_rental_return">
            <input type="hidden" name="order_id" id="rental_order_id">
            <div id="rental_modal_body">
                <div style="text-align:center;padding:2rem;color:var(--admin-muted);">Loading...</div>
            </div>
            <div class="modal-actions" style="margin-top:1rem;">
                <button type="button" class="btn-secondary modal-action-btn" onclick="closeModal('rentalReturnModal')">Cancel</button>
                <button type="submit" class="btn-primary modal-action-btn" style="flex:2;">Process Bulk Return</button>
            </div>
        </form>
    </div>
</div>

<?php
// Build unreturned items data for orders with unreturned cylinders
$unreturned_items_data = [];
if (!empty($orders) && !empty($unreturned_counts)) {
    $ids_with_unreturned = array_keys($unreturned_counts);
    $ph2 = implode(',', array_fill(0, count($ids_with_unreturned), '?'));
    $item_data_stmt = $pdo->prepare("
        SELECT oi.refill_order_id, oi.id as item_id, oi.size_capacity, oi.qty, oi.is_rental,
               c.id as cylinder_id, c.serial_number, g.name as gas_name
        FROM refill_order_items oi
        LEFT JOIN cylinders c ON oi.cylinder_id = c.id
        LEFT JOIN gas_types g ON oi.gas_type_id = g.id
        WHERE oi.refill_order_id IN ($ph2) AND oi.cylinder_id IS NOT NULL AND oi.returned_cylinder_id IS NULL
        ORDER BY oi.refill_order_id, oi.id
    ");
    $item_data_stmt->execute($ids_with_unreturned);
    $all_item_rows = $item_data_stmt->fetchAll();
    foreach ($all_item_rows as $ir) {
        $oid = $ir['refill_order_id'];
        if (!isset($unreturned_items_data[$oid])) $unreturned_items_data[$oid] = [];
        $unreturned_items_data[$oid][] = [
            'item_id' => intval($ir['item_id']),
            'serial' => $ir['serial_number'] ?? '—',
            'gas' => ($ir['gas_name'] ?? 'Gas') . ' (' . ($ir['size_capacity'] ?? '') . ')',
            'qty' => intval($ir['qty']),
            'is_rental' => intval($ir['is_rental']),
        ];
    }
}
?>
<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

// ── Unreturned items data ──
var unreturnedData = <?= json_encode($unreturned_items_data) ?>;
var rentalItemsData = <?= json_encode($rental_items_data ?? []) ?>;

let payOriginalGst = 0;

function openPayModal(orderId, invNo, customer, total, paid, due, gstRate) {
    document.getElementById('pay_order_id').value = orderId;
    document.getElementById('pay_original_gst_rate').value = gstRate;
    document.getElementById('pay_inv_display').textContent = invNo;
    document.getElementById('pay_customer_display').textContent = customer;
    document.getElementById('pay_total_display').textContent = '₹' + parseFloat(total).toFixed(2);
    document.getElementById('pay_paid_display').textContent = '₹' + parseFloat(paid).toFixed(2);
    document.getElementById('pay_due_display').textContent = '₹' + parseFloat(due).toFixed(2);
    document.getElementById('pay_amount').value = parseFloat(due).toFixed(2);
    document.getElementById('pay_gst_rate').value = gstRate > 0 ? gstRate : '';
    document.getElementById('gst_warning').style.display = 'none';
    payOriginalGst = gstRate;
    openModal('payModal');
}

function checkGSTChange() {
    var newRate = parseFloat(document.getElementById('pay_gst_rate').value) || 0;
    var warn = document.getElementById('gst_warning');
    var label = document.getElementById('gst_original_label');
    if (payOriginalGst > 0 && newRate > 0 && Math.abs(newRate - payOriginalGst) > 0.01) {
        label.textContent = payOriginalGst;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}

function updateDuePreview() {
    // Just validate amount doesn't exceed due
    var amount = parseFloat(document.getElementById('pay_amount').value) || 0;
    var dueText = document.getElementById('pay_due_display').textContent;
    var due = parseFloat(dueText.replace(/[₹,]/g, '')) || 0;
    if (amount > due) {
        document.getElementById('pay_amount').value = due.toFixed(2);
    }
}

// ── Cylinder Return Modal ──
function openReturnModal(orderId, invNo, customerName) {
    document.getElementById('return_order_id').value = orderId;
    var items = unreturnedData[orderId] || [];
    var html = '';
    if (items.length === 0) {
        html = '<div style="text-align:center;padding:2rem;color:var(--admin-muted);font-size:0.9rem;">No cylinders to return for this order.</div>';
    } else {
        html += '<div style="margin-bottom:0.75rem;font-size:0.82rem;color:var(--admin-muted);">Order: <strong>' + invNo + '</strong> — Customer: <strong>' + customerName + '</strong></div>';
        html += '<table class="admin-table" style="font-size:0.82rem;"><thead><tr><th style="width:40px;"><input type="checkbox" id="returnSelectAll" checked onchange="toggleReturnAll()"></th><th>Serial</th><th>Gas / Size</th><th>Damage Amount</th><th>Damage Description</th></tr></thead><tbody>';
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            html += '<tr>';
            html += '<td><input type="checkbox" name="selected_items[]" value="' + it.item_id + '" checked class="return-item-cb"></td>';
            html += '<td style="font-weight:700;font-family:monospace;">' + it.serial + '</td>';
            html += '<td>' + it.gas + '</td>';
            html += '<td><input type="number" name="damage_amount[' + it.item_id + ']" class="form-control" style="width:90px;padding:0.25rem 0.4rem;font-size:0.78rem;" step="0.01" min="0" value="0"></td>';
            html += '<td><input type="text" name="damage_desc[' + it.item_id + ']" class="form-control" style="width:130px;padding:0.25rem 0.4rem;font-size:0.78rem;" placeholder="Optional"></td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
    }
    document.getElementById('return_modal_body').innerHTML = html;
    openModal('returnModal');
}

function toggleReturnAll() {
    var checked = document.getElementById('returnSelectAll').checked;
    document.querySelectorAll('.return-item-cb').forEach(function(cb) { cb.checked = checked; });
}

// ── Bulk Rental Return Modal ──
function openRentalReturnModal(orderId, invNo, customerName) {
    document.getElementById('rental_order_id').value = orderId;
    var items = rentalItemsData[orderId] || [];
    var html = '';
    if (items.length === 0) {
        html = '<div style="text-align:center;padding:2rem;color:var(--admin-muted);font-size:0.9rem;">No rental cylinders to return for this order.</div>';
    } else {
        // Show customer label in header
        var custLabel = document.getElementById('rental_customer_label');
        if (custLabel && customerName) {
            custLabel.textContent = customerName;
            custLabel.style.display = 'inline';
        }
        // Header info card
        var today = new Date().toISOString().slice(0, 10);
        html += '<div class="rental-summary-card" style="background:#f8fafc;border:1px solid var(--admin-border);border-radius:10px;padding:0.85rem 1rem;margin-bottom:1rem;display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem 1.5rem;">';
        html += '<div><span style="color:var(--admin-muted);font-size:0.78rem;display:block;">Order</span><strong style="font-size:0.9rem;">' + invNo + '</strong></div>';
        html += '<div style="width:1px;height:28px;background:var(--admin-border);"></div>';
        html += '<div><span style="color:var(--admin-muted);font-size:0.78rem;display:block;">Customer</span><strong style="font-size:0.9rem;">' + (customerName || '—') + '</strong></div>';
        html += '<div style="width:1px;height:28px;background:var(--admin-border);"></div>';
        html += '<div><span style="color:var(--admin-muted);font-size:0.78rem;display:block;">Items</span><strong style="font-size:0.9rem;">' + items.length + '</strong></div>';
        html += '<div style="flex:1;min-width:200px;display:flex;gap:0.75rem;flex-wrap:wrap;">';
        html += '<div class="form-group" style="flex:1;min-width:140px;margin:0;"><label class="form-label">Return Date</label><input type="date" name="return_date" class="form-control" value="' + today + '" required onchange="updateRentalTotals()"></div>';
        html += '<div class="form-group" style="flex:1;min-width:140px;margin:0;"><label class="form-label">Payment Method</label><select name="payment_method" class="form-control" required><option value="Cash">Cash</option><option value="UPI">UPI</option><option value="Bank Transfer" selected>Bank Transfer</option><option value="Cheque">Cheque</option></select></div>';
        html += '</div>';
        html += '</div>';
        // Rental item cards — compact 2-row design
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var daily = it.daily_rate;
            var freeDays = it.free_days;
            var borrowDate = it.borrow_date ? it.borrow_date.slice(0, 10) : '';
            var formattedBorrow = borrowDate ? new Date(borrowDate + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
            var ownershipBadge = '';
            if (it.ownership === 'partner_owned') {
                ownershipBadge = '<span class="badge" style="background:#fef3c7;color:#92400e;font-size:0.65rem;">Partner</span>';
            } else if (it.ownership === 'consumer_owned') {
                ownershipBadge = '<span class="badge" style="background:#dbeafe;color:#1e40af;font-size:0.65rem;">CON</span>';
            } else if (it.ownership === 'vendor_owned') {
                ownershipBadge = '<span class="badge" style="background:#e8d5f5;color:#6b21a8;font-size:0.65rem;">VEN</span>';
            }
            html += '<div class="rental-item-card" data-item-id="' + it.item_id + '" data-customer-id="' + it.customer_id + '" data-cylinder-id="' + it.cylinder_id + '" data-daily-rate="' + daily + '" data-free-days="' + freeDays + '" data-borrow-date="' + borrowDate + '" data-ownership="' + it.ownership + '" data-original-owner="' + it.original_owner + '" style="background:#fff;border:1px solid var(--admin-border);border-left:4px solid var(--admin-accent);border-radius:8px;padding:0.65rem 0.85rem;margin-bottom:0.5rem;">';
            html += '<div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">';
            html += '<input type="checkbox" name="selected_rental_items[]" value="' + it.item_id + '" checked class="rental-item-cb" onchange="updateRentalTotals()" style="width:17px;height:17px;flex-shrink:0;">';
            html += '<input type="hidden" name="cylinder_id[' + it.item_id + ']" value="' + it.cylinder_id + '">';
            html += '<span class="badge" style="background:#1e293b;color:#fff;font-family:monospace;font-size:0.72rem;padding:2px 8px;border-radius:4px;">' + it.serial + '</span>';
            html += '<strong style="font-size:0.85rem;flex:1;min-width:100px;">' + it.gas + '</strong>';
            html += ownershipBadge;
            html += '<select name="condition[' + it.item_id + ']" class="form-control rent-cond" style="width:auto;min-width:90px;padding:0.25rem 0.5rem;font-size:0.75rem;" onchange="calculateRentalItem(this.closest(\'.rental-item-card\'))" required><option value="filled">Filled</option><option value="empty" selected>Empty</option><option value="damaged">Damaged</option></select>';
            html += '<input type="number" name="damage_charge[' + it.item_id + ']" class="form-control rent-damage" step="0.01" min="0" value="0" placeholder="Damage ₹" style="width:80px;padding:0.25rem 0.4rem;font-size:0.75rem;" onchange="calculateRentalItem(this.closest(\'.rental-item-card\'))">';
            html += '<input type="number" name="deposit_deduct[' + it.item_id + ']" class="form-control rent-deposit-deduct" step="0.01" min="0" value="0" placeholder="Deduct ₹" style="width:80px;padding:0.25rem 0.4rem;font-size:0.75rem;" onchange="calculateRentalItem(this.closest(\'.rental-item-card\'))">';
            html += '</div>';
            html += '<div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-top:0.35rem;padding-left:1.65rem;font-size:0.75rem;color:var(--admin-muted);">';
            html += '<span>Borrowed: <strong>' + formattedBorrow + '</strong></span>';
            html += '<span class="rental-pipe" style="color:var(--admin-border);">|</span>';
            html += '<span>₹' + daily.toFixed(2) + '/day</span>';
            html += '<span class="rental-pipe" style="color:var(--admin-border);">|</span>';
            html += '<span>' + freeDays + ' day' + (freeDays !== 1 ? 's' : '') + ' free</span>';
            html += '<span class="rental-pipe" style="color:var(--admin-border);">|</span>';
            html += '<span>Rent days: <strong class="rent-days">0</strong></span>';
            html += '<span class="rental-pipe" style="color:var(--admin-border);">|</span>';
            html += '<span>Rent: <strong class="rent-charge-display" style="color:#dc2626;">₹0</strong></span>';
            html += '</div>';
            html += '</div>';
        }
        // Summary footer
        html += '<div style="background:linear-gradient(135deg,#1e293b,#334155);color:#fff;border-radius:10px;padding:0.75rem 1rem;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:0.5rem;margin-top:0.5rem;font-size:0.82rem;">';
        html += '<span><span style="color:#94a3b8;">Rent:</span> <strong id="rental_total_rent" style="color:#60a5fa;">₹0</strong></span>';
        html += '<span><span style="color:#94a3b8;">Damage:</span> <strong id="rental_total_damage" style="color:#f87171;">₹0</strong></span>';
        html += '<span><span style="color:#94a3b8;">Deposit Deduct:</span> <strong id="rental_total_deposit_deduct" style="color:#fbbf24;">₹0</strong></span>';
        html += '<span style="font-size:1rem;"><span style="color:#94a3b8;">Grand Total:</span> <strong id="rental_grand_total" style="color:#fff;font-size:1.1rem;">₹0</strong></span>';
        html += '</div>';
    }
    document.getElementById('rental_modal_body').innerHTML = html;
    updateRentalTotals();
    openModal('rentalReturnModal');
}

function calculateRentalItem(card) {
    if (!card) return;
    var daily = parseFloat(card.getAttribute('data-daily-rate')) || 0;
    var freeDays = parseInt(card.getAttribute('data-free-days')) || 0;
    var borrowDate = card.getAttribute('data-borrow-date');
    var returnDateInput = document.querySelector('input[name="return_date"]');
    var returnDate = returnDateInput ? returnDateInput.value : new Date().toISOString().slice(0, 10);
    var days = 0;
    if (borrowDate && returnDate) {
        var b = new Date(borrowDate);
        var r = new Date(returnDate);
        if (r > b) {
            days = Math.max(0, Math.ceil((r - b) / (1000 * 60 * 60 * 24)) - freeDays);
        }
    }
    card.querySelector('.rent-days').textContent = days;
    var rentCharge = days * daily;
    card.querySelector('.rent-charge-display').textContent = '₹' + rentCharge.toFixed(2);
    updateRentalTotals();
}

function updateRentalTotals() {
    var totalRent = 0, totalDamage = 0, totalDepositDeduct = 0;
    document.querySelectorAll('.rental-item-cb:checked').forEach(function(cb) {
        var card = cb.closest('.rental-item-card');
        if (!card) return;
        var daily = parseFloat(card.getAttribute('data-daily-rate')) || 0;
        var freeDays = parseInt(card.getAttribute('data-free-days')) || 0;
        var borrowDate = card.getAttribute('data-borrow-date');
        var returnDateInput = document.querySelector('input[name="return_date"]');
        var returnDate = returnDateInput ? returnDateInput.value : new Date().toISOString().slice(0, 10);
        var days = 0;
        if (borrowDate && returnDate) {
            var b = new Date(borrowDate);
            var r = new Date(returnDate);
            if (r > b) {
                days = Math.max(0, Math.ceil((r - b) / (1000 * 60 * 60 * 24)) - freeDays);
            }
        }
        var rent = days * daily;
        var damage = parseFloat(card.querySelector('.rent-damage').value) || 0;
        var depositDeduct = parseFloat(card.querySelector('.rent-deposit-deduct').value) || 0;
        totalRent += rent;
        totalDamage += damage;
        totalDepositDeduct += depositDeduct;
        card.querySelector('.rent-days').textContent = days;
        card.querySelector('.rent-charge-display').textContent = '₹' + rent.toFixed(2);
    });
    document.getElementById('rental_total_rent').textContent = '₹' + totalRent.toFixed(2);
    document.getElementById('rental_total_damage').textContent = '₹' + totalDamage.toFixed(2);
    document.getElementById('rental_total_deposit_deduct').textContent = '₹' + totalDepositDeduct.toFixed(2);
    document.getElementById('rental_grand_total').textContent = '₹' + (totalRent + totalDamage).toFixed(2);
}

// ── Deposit Settlement Modal ──
var depositRemaining = 0;

function openDepositModal(orderId, invNo, depositAmt, settled) {
    document.getElementById('dep_order_id').value = orderId;
    document.getElementById('dep_inv_display').textContent = invNo;
    document.getElementById('dep_collected_display').textContent = '₹' + parseFloat(depositAmt).toFixed(2);
    document.getElementById('dep_settled_display').textContent = '₹' + parseFloat(settled).toFixed(2);
    depositRemaining = Math.max(0, parseFloat(depositAmt) - parseFloat(settled));
    document.getElementById('dep_remaining_display').textContent = '₹' + depositRemaining.toFixed(2);
    document.getElementById('dep_refund').value = depositRemaining.toFixed(2);
    document.getElementById('dep_damage').value = '0';
    openModal('depositModal');
}

function updateDepositPreview() {
    var refund = parseFloat(document.getElementById('dep_refund').value) || 0;
    var damage = parseFloat(document.getElementById('dep_damage').value) || 0;
    if (refund + damage > depositRemaining) {
        var excess = (refund + damage) - depositRemaining;
        if (damage > 0) {
            document.getElementById('dep_damage').value = Math.max(0, (damage - excess)).toFixed(2);
        } else {
            document.getElementById('dep_refund').value = (refund - excess).toFixed(2);
        }
    }
}

function applyFilter() {
    var status = '<?= $status_filter ?>';
    var from = document.getElementById('fromDate').value;
    var to = document.getElementById('toDate').value;
    var search = document.getElementById('ciSearch').value;
    var params = [];
    if (status) params.push('status=' + status);
    if (from) params.push('from=' + from);
    if (to) params.push('to=' + to);
    if (search) params.push('search=' + encodeURIComponent(search));
    window.location.href = '?' + params.join('&');
}

function filterRows() {
    var q = document.getElementById('ciSearch').value.toLowerCase();
    document.querySelectorAll('.ci-row').forEach(function(row) {
        var search = row.getAttribute('data-search') || '';
        row.style.display = search.indexOf(q) > -1 ? '' : 'none';
    });
}
</script>
<style>
.ci-alert{padding:0.5rem 0.85rem;border-radius:8px;font-size:0.82rem;font-weight:600;margin-bottom:0.75rem;}
.ci-alert.ci-success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);color:#059669;}
.ci-alert.ci-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);color:#dc2626;}
.ci-top-wrap{background:var(--admin-card-bg,#fff);border:1px solid var(--admin-border,#e2e8f0);border-radius:12px;padding:0.75rem 1rem;margin-bottom:0.75rem;}
.ci-top-row{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;}
.ci-h1{font-size:1.1rem;font-weight:800;color:var(--admin-text,#1e293b);margin:0;white-space:nowrap;}
.ci-tools{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;}
.ci-srch{position:relative;display:flex;align-items:center;}
.ci-srch svg{position:absolute;left:10px;pointer-events:none;color:#94a3b8;flex-shrink:0;}
.ci-srch input{width:200px;padding:0.4rem 0.5rem 0.4rem 2rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.8rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;transition:border-color 0.15s;}
.ci-srch input:focus{border-color:var(--admin-accent,#3b82f6);}
.ci-d{padding:0.4rem 0.5rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.78rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;width:130px;}
.ci-btn-new{display:inline-flex;align-items:center;gap:0.35rem;padding:0.4rem 0.85rem;border-radius:8px;background:var(--admin-accent,#3b82f6);color:#fff;text-decoration:none;font-weight:700;font-size:0.8rem;white-space:nowrap;border:none;cursor:pointer;}
.ci-btn-new:hover{opacity:0.9;}
.ci-mid-row{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;margin-top:0.6rem;padding-top:0.6rem;border-top:1px solid var(--admin-border,#e2e8f0);}
.ci-stats{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;}
.ci-st-i{display:inline-flex;align-items:center;gap:0.35rem;font-size:0.78rem;color:var(--admin-muted,#64748b);}
.ci-st-i strong{font-size:0.85rem;color:var(--admin-text,#1e293b);}
.ci-dot{width:6px;height:6px;border-radius:50%;display:inline-block;flex-shrink:0;}
.ci-dot-blue{background:#3b82f6;}
.ci-dot-amber{background:#f59e0b;}
.ci-dot-green{background:#10b981;}
.ci-dot-red{background:#ef4444;}
.ci-st-sep{width:1px;height:18px;background:var(--admin-border,#e2e8f0);}
.ci-chips{display:flex;align-items:center;gap:0.25rem;}
.ci-ch{display:inline-flex;align-items:center;gap:0.25rem;padding:0.25rem 0.55rem;border-radius:6px;font-size:0.72rem;font-weight:600;color:var(--admin-muted,#64748b);text-decoration:none;transition:all 0.12s;border:1px solid transparent;}
.ci-ch:hover{background:var(--admin-bg,#f1f5f9);color:var(--admin-text,#1e293b);}
.ci-ch.on{background:var(--admin-accent,#3b82f6);color:#fff;border-color:var(--admin-accent,#3b82f6);}
.ci-ch span{font-weight:400;opacity:0.7;}
.ci-ch.on span{opacity:0.9;}
@media(max-width:768px){
.ci-srch input{width:140px;}
.ci-d{width:110px;}
.ci-mid-row{flex-direction:column;align-items:stretch;}
.ci-chips{flex-wrap:wrap;}
}
</style>
<link rel="stylesheet" href="vendor-invoice.css">
<?php require_once __DIR__ . '/layout_footer.php'; ?>
