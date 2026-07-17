<?php
/**
 * E2E Database Assertion Helper (accessible via HTTP)
 * Called by Playwright tests via page.request.post() to verify database state.
 * Returns JSON { passed: bool, data: mixed, message: string }
 */
require_once __DIR__ . '/db.php';

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
            $stmt = $pdo->prepare("SELECT id AS order_number, id, grand_total, payment_status, order_date FROM refill_orders WHERE customer_id = ? ORDER BY order_date DESC");
            $stmt->execute([$id]);
            jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Orders retrieved');
            break;

        case 'customer_cylinders':
            $stmt = $pdo->prepare("SELECT id, serial_number, gas_type_id, size_capacity, status, expiry_date AS next_test_due, daily_rent_rate FROM cylinders WHERE current_customer_id = ? ORDER BY id");
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
            // Pick the login-enabled customer with password_hash first (most relevant one)
            $stmt = $pdo->prepare("SELECT id, name, mobile, email, address, gst_number FROM customers WHERE email = ? AND login_enabled = 1 AND password_hash IS NOT NULL AND password_hash != '' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                // Fallback: any customer with that email
                $stmt = $pdo->prepare("SELECT id, name, mobile, email, address, gst_number FROM customers WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
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
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(ro.grand_total - COALESCE(p.paid, 0)), 0) AS outstanding FROM refill_orders ro LEFT JOIN (SELECT refill_order_id, SUM(amount) AS paid FROM payments GROUP BY refill_order_id) p ON ro.id = p.refill_order_id WHERE ro.customer_id = ? AND ro.payment_status IN ('pending','partial')");
            $stmt->execute([$id]);
            jsonResponse(true, $stmt->fetch(PDO::FETCH_ASSOC), 'Outstanding retrieved');
            break;

        case 'customer_has_pending_order':
            $stmt = $pdo->prepare("SELECT ro.id, ro.id AS order_number, ro.grand_total, (ro.grand_total - COALESCE(p.paid, 0)) AS due FROM refill_orders ro LEFT JOIN (SELECT refill_order_id, SUM(amount) AS paid FROM payments GROUP BY refill_order_id) p ON ro.id = p.refill_order_id WHERE ro.customer_id = ? AND ro.payment_status IN ('pending','partial') LIMIT 1");
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

        case 'gas_types_count':
            $stmt = $pdo->query("SELECT COUNT(*) FROM gas_types");
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Gas types count');
            break;

        case 'cylinders_count':
            $status = $_POST['status'] ?? '';
            if ($status) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE status = ?");
                $stmt->execute([$status]);
            } else {
                $stmt = $pdo->query("SELECT COUNT(*) FROM cylinders");
            }
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Cylinders count');
            break;

        case 'users_count':
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Users count');
            break;

        case 'user_by_username':
            $username = $_POST['username'] ?? '';
            $stmt = $pdo->prepare("SELECT id, name, username, role, status FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'User found');
            } else {
                jsonResponse(false, null, 'User not found');
            }
            break;

        case 'posts_count':
            $stmt = $pdo->query("SELECT COUNT(*) FROM posts");
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Posts count');
            break;

        case 'post_by_slug':
            $slug = $_POST['slug'] ?? '';
            $stmt = $pdo->prepare("SELECT id, title, slug, status FROM posts WHERE slug = ?");
            $stmt->execute([$slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'Post found');
            } else {
                jsonResponse(false, null, 'Post not found');
            }
            break;

        case 'products_count':
            $stmt = $pdo->query("SELECT COUNT(*) FROM products");
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Products count');
            break;

        case 'expenses_count':
            $stmt = $pdo->query("SELECT COUNT(*) FROM expenses");
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Expenses count');
            break;

        case 'expense_categories_count':
            $stmt = $pdo->query("SELECT COUNT(*) FROM expense_categories");
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Expense categories count');
            break;

        case 'expense_by_id':
            $eid = intval($_POST['expense_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, expense_number, amount, payment_method, payment_status, notes FROM expenses WHERE id = ?");
            $stmt->execute([$eid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'Expense found');
            } else {
                jsonResponse(false, null, 'Expense not found');
            }
            break;

        case 'gst_returns_count':
            $stmt = $pdo->query("SELECT COUNT(*) FROM gst_returns");
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'GST returns count');
            break;

        case 'gst_return_by_period':
            $period = $_POST['period'] ?? '';
            $stmt = $pdo->prepare("SELECT id, type, period, status, created_at FROM gst_returns WHERE period = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$period]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'GST return found');
            } else {
                jsonResponse(false, null, 'GST return not found');
            }
            break;

        case 'cylinder_transactions_count':
            $serial = $_POST['serial'] ?? '';
            $stmt = $pdo->prepare("SELECT c.id FROM cylinders c WHERE c.serial_number = ?");
            $stmt->execute([$serial]);
            $cid = $stmt->fetchColumn();
            if ($cid) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinder_transactions WHERE cylinder_id = ?");
                $stmt->execute([$cid]);
                jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Cylinder transactions count');
            } else {
                jsonResponse(false, null, 'Cylinder not found');
            }
            break;

        case 'cylinder_by_serial':
            $serial = $_POST['serial'] ?? '';
            $stmt = $pdo->prepare("SELECT id, serial_number, status, ownership_type, gas_type_id, size_capacity, current_customer_id, current_vendor_id, current_partner_id, original_owner_customer_id FROM cylinders WHERE serial_number = ?");
            $stmt->execute([$serial]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'Cylinder found');
            } else {
                jsonResponse(false, null, 'Cylinder not found');
            }
            break;

        case 'orders_today_count':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM refill_orders WHERE DATE(order_date) = CURDATE()");
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'Orders today count');
            break;

        case 'ai_config':
            $stmt = $pdo->query("SELECT * FROM ai_config WHERE id = 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse(true, $row, 'AI config found');
            } else {
                jsonResponse(false, null, 'No AI config row');
            }
            break;

        case 'ai_config_value':
            $field = $_POST['field'] ?? '';
            $stmt = $pdo->prepare("SELECT $field FROM ai_config WHERE id = 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                jsonResponse(true, ['value' => $val], 'AI config value retrieved');
            } else {
                jsonResponse(false, null, 'AI config not found');
            }
            break;

        case 'gst_register_entries':
            $from = $_POST['from'] ?? date('Y-m-01');
            $to = $_POST['to'] ?? date('Y-m-t');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?");
            $stmt->execute([$from, $to]);
            jsonResponse(true, ['count' => (int)$stmt->fetchColumn()], 'GST register entries count');
            break;

        case 'generic_sql':
            $sql = $_POST['sql'] ?? '';
            if (preg_match('/^\s*SELECT\s/i', $sql)) {
                try {
                    $stmt = $pdo->query($sql);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    // If single column, return count scalar
                    if (count($rows) === 1 && count($rows[0]) === 1) {
                        jsonResponse(true, ['count' => (int)reset($rows[0])], 'Query executed');
                    }
                    jsonResponse(true, ['rows' => $rows, 'count' => count($rows)], 'Query executed');
                } catch (Exception $e) {
                    jsonResponse(false, null, 'DB error: ' . $e->getMessage());
                }
            } else {
                jsonResponse(false, null, 'Only SELECT queries allowed');
            }
            break;

        default:
            jsonResponse(false, null, "Unknown action: $action");
    }
} catch (PDOException $e) {
    jsonResponse(false, null, 'DB error: ' . $e->getMessage());
}
