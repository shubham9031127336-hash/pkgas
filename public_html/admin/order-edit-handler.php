<?php
require_once __DIR__ . '/auth.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/inventory-utils.php';

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'load_order_data') {
        handleLoadOrderData($pdo);
    } elseif ($action === 'save_order_edit') {
        handleSaveOrderEdit($pdo);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function handleLoadOrderData($pdo) {
    $order_id = intval($_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT oi.*, g.name as gas_name, g.chemical_formula, g.hsn_code as gas_hsn,
               p.name as product_name, p.unit as product_unit, p.hsn_code as product_hsn
        FROM refill_order_items oi 
        LEFT JOIN gas_types g ON oi.gas_type_id = g.id
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.refill_order_id = ?
        ORDER BY oi.id ASC
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'order' => $order,
        'items' => $items,
    ]);
}

function handleSaveOrderEdit($pdo) {
    // Manual CSRF validation for AJAX (avoid redirect in validateCsrfToken)
    $csrf_token = $_POST['_csrf_token'] ?? '';
    $csrf_expected = $_SESSION['_csrf_token'] ?? '';
    if (empty($csrf_expected) || !hash_equals($csrf_expected, $csrf_token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired session. Please refresh the page.']);
        exit;
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if ($order_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $old_order = $stmt->fetch();
    if (!$old_order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        return;
    }

    $gst_status = $old_order['gst_status'] ?? 'draft';
    if ($gst_status === 'filed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This order is included in a filed GST return. Amend the return first.']);
        return;
    }

    $discount = floatval($_POST['discount'] ?? $old_order['discount']);
    $notes = $_POST['notes'] ?? $old_order['notes'];
    $vehicle_number = $_POST['vehicle_number'] ?? $old_order['vehicle_number'];
    $new_deposit = floatval($_POST['deposit_amount'] ?? $old_order['deposit_amount']);
    $items_data = $_POST['items'] ?? [];

    if (!is_array($items_data) || empty($items_data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No items data provided']);
        return;
    }

    try {
        $pdo->beginTransaction();

        $new_subtotal = 0.00;
        $new_tax_amount = 0.00;

        foreach ($items_data as $item_id => $item_data) {
            $item_id = intval($item_id);
            if ($item_id <= 0) continue;

            $qty_stmt = $pdo->prepare("SELECT id, qty, is_rental FROM refill_order_items WHERE id = ? AND refill_order_id = ?");
            $qty_stmt->execute([$item_id, $order_id]);
            $existing = $qty_stmt->fetch();
            if (!$existing) continue;

            $qty = intval($existing['qty']);
            $is_rental = intval($existing['is_rental']);
            $item_price = floatval($item_data['price_per_unit'] ?? 0);
            $item_gst_rate = floatval($item_data['gst_rate'] ?? 0);

            $taxable = round($item_price * $qty, 2);
            $gst_amt = $item_gst_rate > 0 ? round($taxable * $item_gst_rate / 100, 2) : 0.00;
            $cgst = round($gst_amt / 2, 2);
            $sgst = $gst_amt - $cgst;

            $sell_price = $is_rental === 2 ? floatval($item_data['sell_price'] ?? 0) : 0.00;
            $item_deposit = $is_rental === 1 ? floatval($item_data['deposit_amount'] ?? 0) : 0.00;
            $rent_per_day = $is_rental === 1 ? floatval($item_data['rent_per_day'] ?? 0) : 0.00;
            $free_days = $is_rental === 1 ? intval($item_data['free_days'] ?? 0) : 0;

            $upd = $pdo->prepare("
                UPDATE refill_order_items SET 
                    price_per_unit = ?, gst_rate = ?, taxable_amount = ?,
                    gst_amount = ?, cgst = ?, sgst = ?,
                    sell_price = ?, deposit_amount = ?,
                    rent_per_day = ?, free_days = ?
                WHERE id = ? AND refill_order_id = ?
            ");
            $upd->execute([
                $item_price, $item_gst_rate, $taxable,
                $gst_amt, $cgst, $sgst,
                $sell_price, $item_deposit,
                $rent_per_day, $free_days,
                $item_id, $order_id
            ]);

            $new_subtotal += $taxable;
            $new_tax_amount += $gst_amt;
        }

        // Deposit change handling (skip for credit orders)
        $old_deposit = floatval($old_order['deposit_amount']);
        $is_credit = !empty($old_order['is_credit_order']);

        if (abs($new_deposit - $old_deposit) > 0.01) {
            if ($is_credit) {
                throw new Exception("Deposit amount cannot be changed for credit orders. Settle the credit first.");
            }
            $deposit_diff = $new_deposit - $old_deposit;
            $deposit_settled = floatval($old_order['deposit_settled'] ?? 0);

            if ($deposit_diff < 0 && abs($deposit_diff) > ($old_deposit - $deposit_settled)) {
                throw new Exception("Cannot reduce deposit below settled amount (\u20b9" . number_format($deposit_settled, 2) . ")");
            }

            if ($deposit_diff > 0) {
                $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, 'deposit_added', ?)")
                    ->execute([$old_order['customer_id'], $order_id, $deposit_diff, $old_order['payment_method'], "Additional deposit \u2014 order edit #ORD-$order_id"]);
                $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance + ? WHERE id = ?")
                    ->execute([$deposit_diff, $old_order['customer_id']]);
            } else {
                $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, 'deposit_refunded', ?)")
                    ->execute([$old_order['customer_id'], $order_id, $deposit_diff, $old_order['payment_method'], "Deposit reduction \u2014 order edit #ORD-$order_id"]);
                $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance + ? WHERE id = ?")
                    ->execute([$deposit_diff, $old_order['customer_id']]);
            }
        }

        $new_grand_total = max(0.00, $new_subtotal + $new_tax_amount - $discount);

        $pdo->prepare("
            UPDATE refill_orders SET 
                subtotal = ?, tax_amount = ?, discount = ?,
                grand_total = ?, deposit_amount = ?,
                notes = ?, vehicle_number = ?
            WHERE id = ?
        ")->execute([
            $new_subtotal, $new_tax_amount, $discount,
            $new_grand_total, $new_deposit,
            $notes, $vehicle_number,
            $order_id
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    // Post-commit side-effects
    try { syncGSTFromOrder($pdo, $order_id); } catch (Exception $e) {
        error_log("GST sync error after edit #$order_id: " . $e->getMessage());
    }

    try {
        if (empty($old_order['is_credit_order'])) {
            recalculateOrderPaymentStatus($pdo, $order_id);
        }
    } catch (Exception $e) {
        error_log("Payment recalculation error after edit #$order_id: " . $e->getMessage());
    }

    try { syncInventory($pdo); } catch (Exception $e) {
        error_log("Inventory sync error after edit #$order_id: " . $e->getMessage());
    }

    // Audit trail
    try {
        $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)")
            ->execute([
                $_SESSION['username'] ?? 'admin',
                "Edited Order #ORD-$order_id",
                json_encode([
                    'before' => [
                        'subtotal' => $old_order['subtotal'],
                        'tax' => $old_order['tax_amount'],
                        'discount' => $old_order['discount'],
                        'grand_total' => $old_order['grand_total'],
                        'deposit' => $old_order['deposit_amount'],
                    ],
                    'after' => [
                        'subtotal' => $new_subtotal,
                        'tax' => $new_tax_amount,
                        'discount' => $discount,
                        'grand_total' => $new_grand_total,
                        'deposit' => $new_deposit,
                    ],
                ])
            ]);
    } catch (Exception $e) {
        error_log("Audit log error after edit #$order_id: " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Order updated successfully. Refreshing...']);
}
