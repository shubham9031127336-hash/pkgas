<?php
require_once __DIR__ . '/db.php';
// Fix products: set realistic prices and stock
$pdo->exec("UPDATE products SET price = 250.00, stock_quantity = 50 WHERE id = 1");  // Gas Regulator
$pdo->exec("UPDATE products SET price = 180.00, stock_quantity = 30 WHERE id = 2");  // Safety Valve
// Remove duplicates and fix
$pdo->exec("DELETE FROM products WHERE id > 2");
echo "Products fixed.\n";
?>