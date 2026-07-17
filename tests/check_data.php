<?php
require_once __DIR__ . '/../public_html/admin/db.php';

$stmt = $pdo->query("SELECT id, serial_number, status FROM cylinders WHERE serial_number LIKE 'BRW-TST-%' OR serial_number LIKE 'TST-ADV-%'");
echo 'Cylinders found: ' . $stmt->rowCount() . "\n";
while ($r = $stmt->fetch()) {
    echo $r['id'] . ' - ' . $r['serial_number'] . ' - ' . $r['status'] . "\n";
}
