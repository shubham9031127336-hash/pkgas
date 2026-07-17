<?php
require_once 'db.php';
header('Content-Type: application/json');

$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($customer_id <= 0) {
    echo json_encode(['error' => 'Invalid customer ID']);
    exit();
}

try {
    // Our cylinders currently with this customer (owned or partner_owned, status=with_customer)
    $stmt = $pdo->prepare("
        SELECT c.id, c.serial_number, c.gas_type_id, c.size_capacity, c.status, c.ownership_type,
               g.name as gas_name, p.company_name as partner_name, oc.name as original_owner_name,
               v.name as vendor_name
        FROM cylinders c
        LEFT JOIN gas_types g ON c.gas_type_id = g.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        WHERE c.current_customer_id = ?
          AND c.status = 'with_customer'
          AND c.ownership_type IN ('owned', 'partner_owned')
        ORDER BY c.serial_number ASC
    ");
    $stmt->execute([$customer_id]);
    $our_with_them = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Customer's own cylinders in our inventory (consumer_owned, original_owner = this customer, not settled)
    $stmt = $pdo->prepare("
        SELECT c.id, c.serial_number, c.gas_type_id, c.size_capacity, c.status, c.ownership_type,
               g.name as gas_name, oc.name as original_owner_name, p.company_name as partner_name,
               v.name as vendor_name
        FROM cylinders c
        LEFT JOIN gas_types g ON c.gas_type_id = g.id
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        WHERE c.original_owner_customer_id = ?
          AND c.ownership_type = 'consumer_owned'
          AND c.status NOT IN ('returned_to_consumer')
          AND (c.current_customer_id IS NULL OR c.current_customer_id != c.original_owner_customer_id)
        ORDER BY c.status ASC, c.serial_number ASC
    ");
    $stmt->execute([$customer_id]);
    $their_with_us = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'our_with_them' => $our_with_them,
        'our_with_them_count' => count($our_with_them),
        'their_with_us' => $their_with_us,
        'their_with_us_count' => count($their_with_us),
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
