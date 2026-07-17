<?php
require_once __DIR__ . '/db.php';

// Add missing columns to refill_orders
try {
    $pdo->exec("ALTER TABLE refill_orders ADD COLUMN invoice_number VARCHAR(100) DEFAULT NULL AFTER id");
    echo "Added invoice_number\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S22' || strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "invoice_number already exists\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

try {
    $pdo->exec("ALTER TABLE refill_orders ADD COLUMN invoice_date DATETIME DEFAULT NULL AFTER invoice_number");
    echo "Added invoice_date\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S22' || strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "invoice_date already exists\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// List all refill_orders columns
$stmt = $pdo->query("SHOW COLUMNS FROM refill_orders");
echo "\nrefill_orders columns:\n";
while ($r = $stmt->fetch()) {
    echo "  {$r['Field']} ({$r['Type']})\n";
}
?>