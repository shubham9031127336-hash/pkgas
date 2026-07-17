<?php
require_once __DIR__ . '/../public_html/admin/db.php';

// Test execute_sql endpoint
$vendor_sql = "INSERT IGNORE INTO vendors (id, name, mobile) VALUES (9991, 'Browser Test Vendor', '9999990091')";
$pdo->exec($vendor_sql);
echo "Vendor created: " . $pdo->lastInsertId() . "\n";

$cyl_sql = "INSERT IGNORE INTO cylinders (serial_number, gas_type_id, size_capacity, status, ownership_type) VALUES ('BRW-TST-001', 15, '40L', 'empty', 'owned'), ('BRW-TST-002', 15, '40L', 'empty', 'owned'), ('BRW-TST-003', 15, '40L', 'empty', 'owned')";
$pdo->exec($cyl_sql);
echo "Cylinders created\n";

$stmt = $pdo->query("SELECT id, serial_number, status FROM cylinders WHERE serial_number LIKE 'BRW-TST-%'");
echo "Cylinders in DB: " . $stmt->rowCount() . "\n";
while ($r = $stmt->fetch()) {
    echo $r['id'] . ' - ' . $r['serial_number'] . ' - ' . $r['status'] . "\n";
}
