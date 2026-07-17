<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$lot_id = intval($_GET['id'] ?? 0);
if ($lot_id <= 0) {
    echo json_encode(['error' => 'Invalid lot ID']);
    exit();
}

try {
    $lot_q = $pdo->prepare("
        SELECT dl.*, v.name as vendor_name
        FROM dispatch_lots dl
        JOIN vendors v ON dl.vendor_id = v.id
        WHERE dl.id = ?
    ");
    $lot_q->execute([$lot_id]);
    $lot = $lot_q->fetch();
    if (!$lot) {
        echo json_encode(['error' => 'Lot not found']);
        exit();
    }

    $cyl_q = $pdo->prepare("
        SELECT dli.*, g.name as gas_name
        FROM dispatch_lot_items dli
        JOIN gas_types g ON dli.gas_type_id = g.id
        WHERE dli.lot_id = ?
        ORDER BY dli.id ASC
    ");
    $cyl_q->execute([$lot_id]);
    $cylinders = $cyl_q->fetchAll();

    $pay_q = $pdo->prepare("
        SELECT * FROM payments
        WHERE lot_id = ?
        ORDER BY payment_date ASC
    ");
    $pay_q->execute([$lot_id]);
    $payments = $pay_q->fetchAll();

    // Transport cost summary
    $transport_q = $pdo->prepare("
        SELECT
            COALESCE(SUM(dispatch_transport_cost), 0) as dispatch_transport,
            COALESCE(SUM(receive_transport_cost), 0) as receive_transport,
            COUNT(*) as total_items,
            SUM(CASE WHEN dispatch_status = 'received' THEN 1 ELSE 0 END) as received_items
        FROM dispatch_lot_items WHERE lot_id = ?
    ");
    $transport_q->execute([$lot_id]);
    $transport = $transport_q->fetch();

    // Vendor's current advance balance (for frontend validation)
    $vendor_advance_balance = 0;
    $vab_q = $pdo->prepare("SELECT COALESCE(advance_balance, 0) as ab FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $vab_q->execute([$lot['vendor_id']]);
    $vab_res = $vab_q->fetch();
    $vendor_advance_balance = floatval($vab_res['ab'] ?? 0);

    echo json_encode([
        'lot' => $lot,
        'cylinders' => $cylinders,
        'payments' => $payments,
        'transport' => $transport,
        'vendor_advance_balance' => $vendor_advance_balance
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error']);
}
exit();
