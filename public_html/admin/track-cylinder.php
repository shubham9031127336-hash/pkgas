<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once 'db.php';

header('Content-Type: application/json');

$serial = isset($_GET['serial']) ? trim($_GET['serial']) : '';

if (empty($serial)) {
    echo json_encode(['error' => 'Serial number is required.']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT c.*, g.name as gas_name, 
               cu.name as customer_name, 
               v.name as vendor_name, 
               p.company_name as partner_name,
               oc.name as original_owner_name
        FROM cylinders c 
        JOIN gas_types g ON c.gas_type_id = g.id 
        LEFT JOIN customers cu ON c.current_customer_id = cu.id
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        WHERE c.serial_number = ?
    ");
    $stmt->execute([$serial]);
    $cylinder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cylinder) {
        echo json_encode(['error' => 'No cylinder found with serial: ' . htmlspecialchars($serial)]);
        exit();
    }

    // Get transaction history
    $hist_stmt = $pdo->prepare("
        SELECT ct.transaction_type as type, ct.transaction_date as date, ct.notes, ct.id as tx_id,
               cu.name as customer_name, v.name as vendor_name,
               CASE WHEN ct.transaction_type IN ('consumer_give_back', 'partner_return', 'returned_to_partner') THEN 1 ELSE 0 END as is_settlement
        FROM cylinder_transactions ct
        LEFT JOIN customers cu ON ct.customer_id = cu.id
        LEFT JOIN vendors v ON ct.vendor_id = v.id
        WHERE ct.cylinder_id = ?
        ORDER BY ct.transaction_date DESC
        LIMIT 50
    ");
    $hist_stmt->execute([$cylinder['id']]);
    $history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);

    $cylinder['history'] = $history;
    echo json_encode($cylinder);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
