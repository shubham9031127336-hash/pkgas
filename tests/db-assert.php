<?php
/**
 * E2E Database Assertion Helper
 * Called by Playwright tests via page.request.post() to verify database state.
 * Returns JSON { passed: bool, data: mixed, message: string }
 */
require_once __DIR__ . '/../public_html/admin/db.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$mobile = $_POST['mobile'] ?? '';
$id = intval($_POST['id'] ?? 0);

function jsonResponse($passed, $data = null, $message = '') {
    echo json_encode(['passed' => $passed, 'data' => $data, 'message' => $message]);
    exit;
}

try {
    switch ($action) {
        case 'customer_exists':
            $stmt = $pdo->prepare("SELECT id, name, mobile, email, customer_type, gst_number, state_code, city, pincode, registration_type, address, deposit_balance, active_cylinders_count, credit_used, login_enabled, status FROM customers WHERE mobile = ?");
            $stmt->execute([$mobile]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'Customer found');
            } else {
                jsonResponse(false, null, 'Customer not found');
            }
            break;

        case 'customer_count_by_mobile':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE mobile = ?");
            $stmt->execute([$mobile]);
            $count = (int)$stmt->fetchColumn();
            jsonResponse(true, ['count' => $count], "$count customer(s) with mobile $mobile");
            break;

        case 'customer_values':
            $stmt = $pdo->prepare("SELECT id, name, mobile, email, customer_type, gst_number, state_code, city, pincode, registration_type, address FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'Customer values retrieved');
            } else {
                jsonResponse(false, null, "Customer id=$id not found");
            }
            break;

        case 'delete_cascade':
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $customer_gone = !$stmt->fetch();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_customer_id = ?");
            $stmt->execute([$id]);
            $cylinders_assigned = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinder_transactions WHERE customer_id = ?");
            $stmt->execute([$id]);
            $txns_remaining = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE customer_id = ?");
            $stmt->execute([$id]);
            $payments_remaining = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM refill_orders WHERE customer_id = ?");
            $stmt->execute([$id]);
            $orders_remaining = (int)$stmt->fetchColumn();

            $all_clean = $customer_gone && $cylinders_assigned === 0 && $txns_remaining === 0 && $payments_remaining === 0 && $orders_remaining === 0;

            jsonResponse($all_clean, [
                'customer_gone' => $customer_gone,
                'cylinders_assigned' => $cylinders_assigned,
                'txns_remaining' => $txns_remaining,
                'payments_remaining' => $payments_remaining,
                'orders_remaining' => $orders_remaining,
            ], $all_clean ? 'All cascade clean' : 'Cascade incomplete');
            break;

        case 'customer_orders':
            $stmt = $pdo->prepare("SELECT id, order_number, grand_total, payment_status, order_date, paid FROM refill_orders WHERE customer_id = ? ORDER BY order_date DESC");
            $stmt->execute([$id]);
            jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Orders retrieved');
            break;

        case 'customer_cylinders':
            $stmt = $pdo->prepare("SELECT id, serial_number, gas_type_id, size_capacity, status, next_test_due, daily_rent_rate FROM cylinders WHERE current_customer_id = ? ORDER BY id");
            $stmt->execute([$id]);
            jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Cylinders retrieved');
            break;

        case 'customer_payments':
            $stmt = $pdo->prepare("SELECT id, amount, payment_date, payment_method, payment_type, notes FROM payments WHERE customer_id = ? ORDER BY payment_date DESC");
            $stmt->execute([$id]);
            jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Payments retrieved');
            break;

        case 'customer_by_email':
            $email = $_POST['email'] ?? '';
            $stmt = $pdo->prepare("SELECT id, name, mobile, email, address, gst_number FROM customers WHERE email = ?");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'Customer found by email');
            } else {
                jsonResponse(false, null, 'Customer not found by email');
            }
            break;

        case 'customer_password_valid':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $stmt = $pdo->prepare("SELECT password_hash FROM customers WHERE email = ?");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && password_verify($password, $row['password_hash'])) {
                jsonResponse(true, ['valid' => true], 'Password valid');
            } else {
                jsonResponse(true, ['valid' => false], 'Password invalid');
            }
            break;

        case 'customer_outstanding':
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total - paid), 0) AS outstanding FROM refill_orders WHERE customer_id = ? AND payment_status IN ('pending','partial')");
            $stmt->execute([$id]);
            jsonResponse(true, $stmt->fetch(PDO::FETCH_ASSOC), 'Outstanding retrieved');
            break;

        case 'customer_has_pending_order':
            $stmt = $pdo->prepare("SELECT id, order_number, grand_total, (grand_total - paid) AS due FROM refill_orders WHERE customer_id = ? AND payment_status IN ('pending','partial') LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'Pending order found');
            } else {
                jsonResponse(false, null, 'No pending orders');
            }
            break;

        case 'customer_refill_services':
            $stmt = $pdo->prepare("SELECT id, cylinder_serial, gas_type, status, created_at FROM customer_refill_services WHERE customer_id = ? AND status NOT IN ('returned_to_customer','cancelled') ORDER BY created_at DESC");
            $stmt->execute([$id]);
            jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Refill services retrieved');
            break;

        default:
            jsonResponse(false, null, "Unknown action: $action");
    }
} catch (PDOException $e) {
    jsonResponse(false, null, 'DB error: ' . $e->getMessage());
}
