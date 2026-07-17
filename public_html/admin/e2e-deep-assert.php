<?php
/**
 * E2E Deep Functional Test DB Assertion Endpoint
 * Returns full state snapshots of database records for deep integration testing.
 * Called by Playwright tests via page.request.post().
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
function jsonRes($passed, $data = null, $msg = '') {
    echo json_encode(['passed' => $passed, 'data' => $data, 'message' => $msg]);
    exit;
}

try {
    switch ($action) {
        case 'order_state':
            $oid = intval($_POST['order_id'] ?? 0);
            $order = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
            $order->execute([$oid]);
            $o = $order->fetch(PDO::FETCH_ASSOC);
            if (!$o) jsonRes(false, null, "Order $oid not found");

            $items = $pdo->prepare("SELECT * FROM refill_order_items WHERE refill_order_id = ?");
            $items->execute([$oid]);

            $payments = $pdo->prepare("SELECT * FROM payments WHERE refill_order_id = ?");
            $payments->execute([$oid]);

            $gst = $pdo->prepare("SELECT * FROM gst_ledger WHERE reference_type = 'refill_order' AND reference_id = ?");
            $gst->execute([$oid]);

            jsonRes(true, [
                'order' => $o,
                'items' => $items->fetchAll(PDO::FETCH_ASSOC),
                'payments' => $payments->fetchAll(PDO::FETCH_ASSOC),
                'gst_entries' => $gst->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'cylinder_state':
            $serial = $_POST['serial'] ?? '';
            $stmt = $pdo->prepare("SELECT c.*, g.name AS gas_name FROM cylinders c LEFT JOIN gas_types g ON c.gas_type_id = g.id WHERE c.serial_number = ?");
            $stmt->execute([$serial]);
            $cyl = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cyl) jsonRes(false, null, "Cylinder $serial not found");

            $txns = $pdo->prepare("SELECT * FROM cylinder_transactions WHERE cylinder_id = ? ORDER BY transaction_date DESC LIMIT 5");
            $txns->execute([$cyl['id']]);

            jsonRes(true, [
                'cylinder' => $cyl,
                'transactions' => $txns->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'cylinder_state_by_id':
            $cid = intval($_POST['cylinder_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT c.*, g.name AS gas_name FROM cylinders c LEFT JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ?");
            $stmt->execute([$cid]);
            $cyl = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cyl) jsonRes(false, null, "Cylinder $cid not found");

            $txns = $pdo->prepare("SELECT * FROM cylinder_transactions WHERE cylinder_id = ? ORDER BY transaction_date DESC LIMIT 5");
            $txns->execute([$cid]);

            jsonRes(true, [
                'cylinder' => $cyl,
                'transactions' => $txns->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'customer_state':
            $cid = intval($_POST['customer_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, name, mobile, email, customer_type, gst_number, state_code, city, pincode, deposit_balance, active_cylinders_count, credit_used, credit_limit, credit_status, login_enabled, status FROM customers WHERE id = ?");
            $stmt->execute([$cid]);
            $cust = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cust) jsonRes(false, null, "Customer $cid not found");

            try {
                $credits = $pdo->prepare("SELECT * FROM credit_transactions WHERE customer_id = ? ORDER BY id DESC LIMIT 10");
                $credits->execute([$cid]);
                $creditRows = $credits->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $creditRows = [];
            }

            jsonRes(true, [
                'customer' => $cust,
                'recent_credit_txns' => $credits->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'inventory_state':
            $gid = intval($_POST['gas_type_id'] ?? 0);
            $size = $_POST['size'] ?? '';
            if ($gid && $size) {
                $stmt = $pdo->prepare("SELECT * FROM inventory WHERE gas_type_id = ? AND size_capacity = ?");
                $stmt->execute([$gid, $size]);
            } else {
                $stmt = $pdo->query("SELECT i.*, g.name AS gas_name FROM inventory i JOIN gas_types g ON i.gas_type_id = g.id ORDER BY g.name, i.size_capacity");
            }
            jsonRes(true, ['inventory' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        case 'vendor_lot_state':
            $lid = intval($_POST['lot_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id = ?");
            $stmt->execute([$lid]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lot) jsonRes(false, null, "Lot $lid not found");

            $items = $pdo->prepare("SELECT * FROM dispatch_lot_items WHERE lot_id = ?");
            $items->execute([$lid]);

            $payments = $pdo->prepare("SELECT * FROM payments WHERE lot_id = ?");
            $payments->execute([$lid]);

            $ledger = $pdo->prepare("SELECT * FROM vendor_partner_ledger WHERE reference_type = 'dispatch_lot' AND reference_id = ? ORDER BY id");
            $ledger->execute([$lid]);

            jsonRes(true, [
                'lot' => $lot,
                'items' => $items->fetchAll(PDO::FETCH_ASSOC),
                'payments' => $payments->fetchAll(PDO::FETCH_ASSOC),
                'ledger_entries' => $ledger->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'partner_state':
            $pid = intval($_POST['partner_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM partner_transactions WHERE partner_id = ? ORDER BY transaction_date DESC LIMIT 5");
            $stmt->execute([$pid]);
            $headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $ids = array_column($headers, 'id');
            $items = [];
            if ($ids) {
                $ph = implode(',', $ids);
                $it = $pdo->query("SELECT * FROM partner_transaction_items WHERE transaction_id IN ($ph)");
                $items = $it->fetchAll(PDO::FETCH_ASSOC);
            }
            jsonRes(true, ['transactions' => $headers, 'items' => $items]);

        case 'exchange_state':
            $cid = intval($_POST['customer_id'] ?? 0);
            $lg = $pdo->prepare("SELECT * FROM ledger_groups WHERE customer_id = ? AND group_type = 'exchange_settlement' ORDER BY entry_date DESC LIMIT 3");
            $lg->execute([$cid]);
            $groups = $lg->fetchAll(PDO::FETCH_ASSOC);

            $txns = [];
            if ($groups) {
                $gids = array_column($groups, 'id');
                $ph = implode("','", $gids);
                $t = $pdo->query("SELECT * FROM cylinder_transactions WHERE ledger_group_id IN ('$ph') ORDER BY transaction_date");
                $txns = $t->fetchAll(PDO::FETCH_ASSOC);
            }
            jsonRes(true, ['ledger_groups' => $groups, 'cylinder_transactions' => $txns]);

        case 'gst_state':
            $rtype = $_POST['reference_type'] ?? '';
            $rid = intval($_POST['reference_id'] ?? 0);
            if ($rtype && $rid) {
                $stmt = $pdo->prepare("SELECT * FROM gst_ledger WHERE reference_type = ? AND reference_id = ?");
                $stmt->execute([$rtype, $rid]);
            } else {
                $stmt = $pdo->query("SELECT * FROM gst_ledger ORDER BY id DESC LIMIT 20");
            }
            jsonRes(true, ['gst_entries' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        case 'inventory_integrity':
            $inv = $pdo->query("SELECT DISTINCT gas_type_id, size_capacity FROM cylinders");
            $pairs = $inv->fetchAll(PDO::FETCH_ASSOC);
            $mismatches = [];
            foreach ($pairs as $p) {
                $g = $p['gas_type_id'];
                $s = $p['size_capacity'];
                $st = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM cylinders WHERE gas_type_id = ? AND size_capacity = ? GROUP BY status");
                $st->execute([$g, $s]);
                $raw = $st->fetchAll(PDO::FETCH_KEY_PAIR);

                $statusMap = [
                    'filled' => 'filled_stock', 'empty' => 'empty_stock',
                    'with_customer' => 'with_customer_stock', 'sent_to_vendor' => 'sent_to_vendor_stock',
                    'under_maintenance' => 'maintenance_stock', 'borrowed_from_partner' => 'borrowed_from_partner_stock',
                    'lent_to_partner' => 'lent_to_partner_stock',
                ];
                $iv = $pdo->prepare("SELECT * FROM inventory WHERE gas_type_id = ? AND size_capacity = ?");
                $iv->execute([$g, $s]);
                $invRow = $iv->fetch(PDO::FETCH_ASSOC);
                if (!$invRow) { $mismatches[] = "Missing inventory row for gas=$g size=$s"; continue; }

                foreach ($statusMap as $status => $col) {
                    $expected = intval($raw[$status] ?? 0);
                    $actual = intval($invRow[$col] ?? 0);
                    if ($expected !== $actual) {
                        $mismatches[] = "gas=$g size=$s col=$col expected=$expected actual=$actual";
                    }
                }
            }
            jsonRes(empty($mismatches), ['mismatches' => $mismatches], empty($mismatches) ? 'All match' : 'Mismatches found');

        case 'portal_state':
            $cid = intval($_POST['customer_id'] ?? 0);
            $cyl = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_customer_id = ?");
            $cyl->execute([$cid]);

            $orders = $pdo->prepare("SELECT id, grand_total, payment_status, order_date FROM refill_orders WHERE customer_id = ? ORDER BY order_date DESC LIMIT 5");
            $orders->execute([$cid]);

            $outstanding = $pdo->prepare("SELECT COALESCE(SUM(ro.grand_total - COALESCE(p.paid, 0)), 0) AS outstanding FROM refill_orders ro LEFT JOIN (SELECT refill_order_id, SUM(amount) AS paid FROM payments GROUP BY refill_order_id) p ON ro.id = p.refill_order_id WHERE ro.customer_id = ? AND ro.payment_status IN ('pending','partial')");
            $outstanding->execute([$cid]);

            jsonRes(true, [
                'active_cylinders' => (int)$cyl->fetchColumn(),
                'recent_orders' => $orders->fetchAll(PDO::FETCH_ASSOC),
                'outstanding_balance' => $outstanding->fetch(PDO::FETCH_ASSOC),
            ]);

        case 'rental_return_state':
            $cyl_id = intval($_POST['cylinder_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM rental_returns WHERE cylinder_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$cyl_id]);
            $rr = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rr) jsonRes(false, null, "No rental return for cylinder $cyl_id");

            $payments = $pdo->prepare("SELECT * FROM payments WHERE customer_id = ? AND payment_type = 'rent_payment' ORDER BY payment_date DESC LIMIT 5");
            $payments->execute([$rr['customer_id']]);

            jsonRes(true, [
                'rental_return' => $rr,
                'payments' => $payments->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'vendor_invoice_state':
            $lot_id = intval($_POST['lot_id'] ?? 0);
            $inv = $pdo->prepare("SELECT * FROM vendor_invoices WHERE lot_id = ?");
            $inv->execute([$lot_id]);
            $vi = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$vi) jsonRes(false, null, "No vendor invoice for lot $lot_id");

            $items = $pdo->prepare("SELECT * FROM vendor_invoice_items WHERE invoice_id = ?");
            $items->execute([$vi['id']]);

            jsonRes(true, [
                'invoice' => $vi,
                'items' => $items->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'supplier_state':
            $sid = intval($_POST['supplier_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM cylinder_suppliers WHERE id = ?");
            $stmt->execute([$sid]);
            $sup = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sup) jsonRes(false, null, "Supplier $sid not found");

            $purchases = $pdo->prepare("SELECT * FROM cylinder_purchases WHERE supplier_id = ? ORDER BY purchase_date DESC LIMIT 10");
            $purchases->execute([$sid]);

            $ledger = $pdo->prepare("SELECT * FROM cylinder_supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 20");
            $ledger->execute([$sid]);

            jsonRes(true, [
                'supplier' => $sup,
                'purchases' => $purchases->fetchAll(PDO::FETCH_ASSOC),
                'ledger_entries' => $ledger->fetchAll(PDO::FETCH_ASSOC),
            ]);

        case 'expense_state':
            $eid = intval($_POST['expense_id'] ?? 0);
            if ($eid) {
                $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
                $stmt->execute([$eid]);
            } else {
                $stmt = $pdo->query("SELECT * FROM expenses ORDER BY id DESC LIMIT 10");
            }
            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonRes(true, ['expenses' => $expenses]);

        case 'user_state':
            $uid = intval($_POST['user_id'] ?? 0);
            if ($uid) {
                $stmt = $pdo->prepare("SELECT id, username, name, role, status, created_at FROM users WHERE id = ?");
                $stmt->execute([$uid]);
            } else {
                $stmt = $pdo->query("SELECT id, username, name, role, status, created_at FROM users ORDER BY id");
            }
            jsonRes(true, ['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        case 'bulk_operation_audit':
            $stmt = $pdo->query("SELECT * FROM bulk_operation_audit ORDER BY id DESC LIMIT 10");
            jsonRes(true, ['audit_log' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        case 'vendor_state':
            $vid = intval($_POST['vendor_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, name, contact_person, mobile, address, gst_number, active_refill_count, status FROM vendors WHERE id = ?");
            $stmt->execute([$vid]);
            $v = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$v) jsonRes(false, null, "Vendor $vid not found");
            jsonRes(true, ['vendor' => $v]);

        case 'latest_lot':
            $vid = intval($_POST['vendor_id'] ?? 0);
            $status_filter = $_POST['status'] ?? '';
            $sql = "SELECT * FROM dispatch_lots WHERE vendor_id = ?";
            $params = [$vid];
            if ($status_filter) {
                $sql .= " AND lot_status = ?";
                $params[] = $status_filter;
            }
            $stmt = $pdo->prepare($sql . " ORDER BY id DESC LIMIT 1");
            $stmt->execute($params);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lot) jsonRes(false, null, "No lot found for vendor $vid");
            $items = $pdo->prepare("SELECT * FROM dispatch_lot_items WHERE lot_id = ?");
            $items->execute([$lot['id']]);
            $lot['lot_items'] = $items->fetchAll(PDO::FETCH_ASSOC);

            $payments = $pdo->prepare("SELECT * FROM payments WHERE lot_id = ?");
            $payments->execute([$lot['id']]);
            $lot['payments'] = $payments->fetchAll(PDO::FETCH_ASSOC);

            $ledger = $pdo->prepare("SELECT * FROM vendor_partner_ledger WHERE reference_type = 'dispatch_lot' AND reference_id = ? ORDER BY id");
            $ledger->execute([$lot['id']]);
            $lot['ledger_entries'] = $ledger->fetchAll(PDO::FETCH_ASSOC);

            $expenses = $pdo->prepare("SELECT * FROM expenses WHERE reference_type = 'dispatch_lot' AND reference_id = ?");
            $expenses->execute([$lot['id']]);
            $lot['expenses'] = $expenses->fetchAll(PDO::FETCH_ASSOC);

            jsonRes(true, ['lot' => $lot]);

        case 'cylinders_by_ids':
            $ids = isset($_POST['ids']) ? array_map('intval', explode(',', $_POST['ids'])) : [];
            if (empty($ids)) jsonRes(false, null, "No IDs provided");
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT c.*, g.name AS gas_name FROM cylinders c LEFT JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id IN ($ph)");
            $stmt->execute($ids);
            $cyls = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $txns = [];
            foreach ($cyls as $c) {
                $t = $pdo->prepare("SELECT * FROM cylinder_transactions WHERE cylinder_id = ? ORDER BY transaction_date DESC LIMIT 3");
                $t->execute([$c['id']]);
                $txns[$c['id']] = $t->fetchAll(PDO::FETCH_ASSOC);
            }
            jsonRes(true, ['cylinders' => $cyls, 'transactions' => $txns]);

        case 'execute_sql':
            $sql = $_POST['sql'] ?? '';
            if (empty($sql)) jsonRes(false, null, 'No SQL provided');
            $stmt = $pdo->exec($sql);
            jsonRes(true, ['affected' => $stmt]);

        case 'seed_test_cylinders':
            // Reset TEST-* cylinders to 'empty' state for repeatable testing
            $pdo->exec("UPDATE cylinders SET status = 'empty', current_vendor_id = NULL, current_customer_id = NULL, current_partner_id = NULL, borrow_date = NULL, daily_rent_rate = 0, free_days = 0 WHERE serial_number LIKE 'TEST-%'");
            // Mark 6 as 'filled' for order testing
            $pdo->exec("UPDATE cylinders SET status = 'filled' WHERE serial_number IN ('TEST-OX-001','TEST-OX-002','TEST-OX-003','TEST-NG-001','TEST-AR-001','TEST-CO2-001')");
            require_once __DIR__ . '/inventory-utils.php';
            syncInventory($pdo);
            jsonRes(true, ['message' => 'Test cylinders reset']);

        default:
            jsonRes(false, null, "Unknown action: $action");
    }
} catch (PDOException $e) {
    jsonRes(false, null, 'DB error: ' . $e->getMessage());
}
