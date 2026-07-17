<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

$serials_raw = trim($_POST['serials'] ?? '');
if ($serials_raw === '') {
    echo json_encode(['exists' => []]);
    exit();
}

$serials = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $serials_raw)))));

if (empty($serials)) {
    echo json_encode(['exists' => []]);
    exit();
}

$placeholders = implode(',', array_fill(0, count($serials), '?'));
$stmt = $pdo->prepare("SELECT serial_number FROM cylinders WHERE serial_number IN ($placeholders)");
$stmt->execute($serials);
$existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode(['exists' => $existing]);
exit();
