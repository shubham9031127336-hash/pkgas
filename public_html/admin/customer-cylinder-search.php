<?php
require_once 'db.php';
require_once 'inventory-utils.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$gas_type_id = isset($_GET['gas_type_id']) ? intval($_GET['gas_type_id']) : 0;
$size_capacity = trim($_GET['size_capacity'] ?? '');

$results = searchCustomerCylinders(
    $pdo,
    $query ?: null,
    $customer_id ?: null,
    $gas_type_id ?: null,
    $size_capacity ?: null,
    false
);

echo json_encode($results);
