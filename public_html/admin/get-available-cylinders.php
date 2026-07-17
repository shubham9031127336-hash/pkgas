<?php
/**
 * AJAX endpoint to fetch available filled cylinders for sale
 * Returns cylinders that are currently in stock and available for sale
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

try {
    $gas_type_id = isset($_GET['gas_type_id']) ? intval($_GET['gas_type_id']) : 0;
    $size = isset($_GET['size']) ? trim($_GET['size']) : '';
    
    if ($gas_type_id <= 0 || empty($size)) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    
    // Fetch available filled cylinders of the specified type and size
    $stmt = $pdo->prepare("
        SELECT c.id, c.serial_number, c.size_capacity, g.name as gas_name
        FROM cylinders c
        JOIN gas_types g ON c.gas_type_id = g.id
        WHERE c.gas_type_id = ? AND c.size_capacity = ? AND c.status = 'filled'
        ORDER BY c.serial_number
    ");
    $stmt->execute([$gas_type_id, $size]);
    $cylinders = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'cylinders' => $cylinders]);
    
} catch (Exception $e) {
    error_log('get-available-cylinders: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to fetch cylinders']);
}
