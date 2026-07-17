<?php
require_once __DIR__ . '/db.php';
$stmt = $pdo->query("SELECT id, name, price, stock_quantity FROM products");
while ($r = $stmt->fetch()) {
    echo "ID: {$r['id']}, Name: {$r['name']}, Price: {$r['price']}, Stock: {$r['stock_quantity']}\n";
}
?>