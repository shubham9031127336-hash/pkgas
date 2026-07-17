<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$vendor_id = intval($_GET['vendor_id'] ?? 0);
$lot_ids_str = trim($_GET['lot_ids'] ?? '');

try {
    if ($vendor_id > 0) {
        // Multi-lot mode: return all pending cylinders for this vendor, grouped by lot
        $stmt = $pdo->prepare("
            SELECT dli.cylinder_id AS id, dli.serial_number, dli.gas_type_id, dli.size_capacity,
                   c.ownership_type, c.is_customer_refill_cylinder,
                   c.current_vendor_id, dli.lot_id,
                   dl.lot_number, dl.gst_rate, dl.gst_locked, dl.gst_applicable,
                   dl.advance_amount, dl.lot_status, dl.cylinder_count, dl.returned_count
            FROM dispatch_lot_items dli
            JOIN cylinders c ON dli.cylinder_id = c.id
            JOIN dispatch_lots dl ON dli.lot_id = dl.id
            WHERE dl.vendor_id = ? AND dli.dispatch_status = 'dispatched' AND dl.lot_status IN ('open','partial_return')
            ORDER BY dl.id DESC, dli.id ASC
        ");
        $stmt->execute([$vendor_id]);
    } elseif ($lot_ids_str !== '') {
        // Multi-lot mode: comma-separated lot IDs
        $lot_ids = array_map('intval', explode(',', $lot_ids_str));
        $lot_ids = array_filter($lot_ids);
        if (empty($lot_ids)) {
            echo json_encode([]);
            exit();
        }
        $ph = implode(',', array_fill(0, count($lot_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT dli.cylinder_id AS id, dli.serial_number, dli.gas_type_id, dli.size_capacity,
                   c.ownership_type, c.is_customer_refill_cylinder,
                   c.current_vendor_id, dli.lot_id,
                   dl.lot_number, dl.gst_rate, dl.gst_locked, dl.gst_applicable,
                   dl.advance_amount, dl.lot_status, dl.cylinder_count, dl.returned_count
            FROM dispatch_lot_items dli
            JOIN cylinders c ON dli.cylinder_id = c.id
            JOIN dispatch_lots dl ON dli.lot_id = dl.id
            WHERE dli.lot_id IN ($ph) AND dli.dispatch_status = 'dispatched'
            ORDER BY dl.id DESC, dli.id ASC
        ");
        $stmt->execute(array_values($lot_ids));
    } else {
        echo json_encode([]);
        exit();
    }

    $cylinders = $stmt->fetchAll();

    // Include vendor's current advance balance in response
    $vendor_advance_balance = 0;
    if ($vendor_id > 0) {
        $vab_q = $pdo->prepare("SELECT COALESCE(advance_balance, 0) as ab FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $vab_q->execute([$vendor_id]);
        $vab_res = $vab_q->fetch();
        $vendor_advance_balance = floatval($vab_res['ab'] ?? 0);
    }

    echo json_encode([
        'cylinders' => $cylinders,
        'vendor_advance_balance' => $vendor_advance_balance
    ]);
} catch (PDOException $e) {
    echo json_encode([]);
}
exit();
