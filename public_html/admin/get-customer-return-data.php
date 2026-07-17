<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/gst_helper.php';

$action = trim($_GET['action'] ?? '');
$customer_id = intval($_GET['customer_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);

try {
    if ($action === 'customers') {
        $stmt = $pdo->query("
            SELECT DISTINCT c.id, c.name, c.mobile
            FROM customers c
            JOIN customer_refill_services crs ON crs.customer_id = c.id
            JOIN cylinders cyl ON cyl.id = crs.cylinder_id
            WHERE crs.status IN ('returned_to_warehouse', 'filled_from_vendor')
              AND cyl.status = 'filled'
              AND cyl.is_customer_refill_cylinder = 1
            ORDER BY c.name ASC
        ");
        echo json_encode($stmt->fetchAll());
        exit();

    } elseif ($action === 'orders' && $customer_id > 0) {
        $stmt = $pdo->prepare("
            SELECT ro.id AS order_id,
                   ro.order_date,
                   ro.gst_rate,
                   COALESCE(ro.tax_amount, 0) AS tax_amount,
                   COALESCE(ro.subtotal, 0) AS subtotal,
                   COALESCE(ro.grand_total, 0) AS grand_total,
                   ro.payment_status,
                   COALESCE(ro.payment_method, '') AS payment_method,
                   COALESCE(ro.is_credit_order, 0) AS is_credit_order,
                   COALESCE(ro.invoice_number, '') AS invoice_number,
                   COALESCE(ro.business_name, '') AS business_name,
                   COUNT(crs.id) AS returnable_count,
                   SUM(crs.service_charge) AS total_service_charge
            FROM refill_orders ro
            JOIN customer_refill_services crs ON crs.refill_order_id = ro.id
            JOIN cylinders cyl ON cyl.id = crs.cylinder_id
            WHERE crs.customer_id = ?
              AND crs.status IN ('returned_to_warehouse', 'filled_from_vendor')
              AND cyl.status = 'filled'
              AND cyl.is_customer_refill_cylinder = 1
            GROUP BY ro.id
            ORDER BY ro.order_date DESC
        ");
        $stmt->execute([$customer_id]);
        $orders = $stmt->fetchAll();

        // Attach payment details per order
        foreach ($orders as &$ord) {
            $ord_id = intval($ord['order_id']);
            $ord['payments'] = [];

            // Get payments for this order
            $pstmt = $pdo->prepare("SELECT id, amount, payment_method, payment_date, payment_type, payment_subtype, notes FROM payments WHERE refill_order_id = ? ORDER BY id ASC");
            $pstmt->execute([$ord_id]);
            $ord['payments'] = $pstmt->fetchAll();

            // Get advance/deposit utilization
            $ord['advance_available'] = 0;
            $ord['deposit_available'] = 0;
            try {
                $cust = $pdo->prepare("SELECT COALESCE(advance_balance, 0) AS advance_balance, COALESCE(deposit_balance, 0) AS deposit_balance FROM customers WHERE id = ?");
                $cust->execute([$customer_id]);
                $cdata = $cust->fetch();
                if ($cdata) {
                    $ord['advance_available'] = floatval($cdata['advance_balance']);
                    $ord['deposit_available'] = floatval($cdata['deposit_balance']);
                }
            } catch (PDOException $e) {}

            // Check if GST is already in ledger
            $ord['gst_in_ledger'] = false;
            try {
                $gstChk = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE reference_type='refill_order' AND reference_id=? AND input_output_type='output'");
                $gstChk->execute([$ord_id]);
                $ord['gst_in_ledger'] = intval($gstChk->fetchColumn()) > 0;
            } catch (PDOException $e) {}
        }
        unset($ord);

        echo json_encode($orders);
        exit();

    } elseif ($action === 'cylinders' && $order_id > 0) {
        $stmt = $pdo->prepare("
            SELECT crs.id AS service_id,
                   crs.cylinder_id,
                   crs.serial_number,
                   crs.service_charge,
                   crs.gas_type_id,
                   crs.size_capacity,
                   crs.payment_status AS service_payment_status,
                   g.name AS gas_name
            FROM customer_refill_services crs
            JOIN cylinders cyl ON cyl.id = crs.cylinder_id
            JOIN gas_types g ON g.id = crs.gas_type_id
            WHERE crs.refill_order_id = ?
              AND crs.status IN ('returned_to_warehouse', 'filled_from_vendor')
              AND cyl.status = 'filled'
              AND cyl.is_customer_refill_cylinder = 1
            ORDER BY crs.serial_number ASC
        ");
        $stmt->execute([$order_id]);
        $cylinders = $stmt->fetchAll();

        // Add default refill cost suggestions
        $gas_types = [];
        $gtRes = $pdo->query("SELECT id, refill_cost, size_refill_costs FROM gas_types");
        while ($g = $gtRes->fetch()) {
            $sizes = json_decode($g['size_refill_costs'] ?? '{}', true) ?: [];
            $gas_types[$g['id']] = [
                'refill_cost' => floatval($g['refill_cost'] ?? 0),
                'size_refill_costs' => $sizes,
            ];
        }

        foreach ($cylinders as &$cyl) {
            $gt_id = intval($cyl['gas_type_id']);
            $size = $cyl['size_capacity'];
            $default = 0;
            if (isset($gas_types[$gt_id])) {
                if (isset($gas_types[$gt_id]['size_refill_costs'][$size])) {
                    $default = floatval($gas_types[$gt_id]['size_refill_costs'][$size]);
                } else {
                    $default = $gas_types[$gt_id]['refill_cost'];
                }
            }
            $cyl['default_refill_cost'] = $default;
        }
        unset($cyl);

        echo json_encode($cylinders);
        exit();

    } elseif ($action === 'payment_details' && $order_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        if (!$order) {
            echo json_encode(['error' => 'Order not found']);
            exit();
        }

        $customer_id_val = intval($order['customer_id']);
        $cust_stmt = $pdo->prepare("SELECT id, name, mobile, deposit_balance, advance_balance, credit_used, credit_limit, credit_status FROM customers WHERE id = ?");
        $cust_stmt->execute([$customer_id_val]);
        $customer = $cust_stmt->fetch();

        $payments = [];
        $pstmt = $pdo->prepare("SELECT id, amount, payment_method, payment_date, payment_type, payment_subtype, notes FROM payments WHERE refill_order_id = ? ORDER BY id ASC");
        $pstmt->execute([$order_id]);
        $payments = $pstmt->fetchAll();

        $credit_txns = [];
        try {
            $cstmt = $pdo->prepare("SELECT id, transaction_type, amount, balance_after, description, due_date FROM credit_transactions WHERE refill_order_id = ? ORDER BY id ASC");
            $cstmt->execute([$order_id]);
            $credit_txns = $cstmt->fetchAll();
        } catch (PDOException $e) {}

        $gst_in_ledger = false;
        try {
            $gstChk = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE reference_type='refill_order' AND reference_id=?");
            $gstChk->execute([$order_id]);
            $gst_in_ledger = intval($gstChk->fetchColumn()) > 0;
        } catch (PDOException $e) {}

        echo json_encode([
            'order' => $order,
            'customer' => $customer,
            'payments' => $payments,
            'credit_transactions' => $credit_txns,
            'gst_in_ledger' => $gst_in_ledger,
        ]);
        exit();
    }

    echo json_encode([]);
} catch (PDOException $e) {
    echo json_encode([]);
}
exit();
