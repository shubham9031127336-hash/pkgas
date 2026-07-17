<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';

echo "<pre>";
echo __('db_diag.running') . "\n";
try {
    runPartnerMigrations($pdo);
    echo __('db_diag.executed') . "\n";
} catch (Exception $e) {
    echo __('db_diag.outer_exception') . " " . $e->getMessage() . "\n";
}

echo "\n" . __('db_diag.inspecting') . " cylinders " . __('db_diag.table') . "\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM cylinders");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo __('db_diag.field') . " {$row['Field']} " . __('db_diag.type') . " {$row['Type']} " . __('db_diag.null') . " {$row['Null']} " . __('db_diag.default') . " {$row['Default']}\n";
    }
} catch (Exception $e) {
    echo __('db_diag.error') . " cylinders: " . $e->getMessage() . "\n";
}

echo "\n" . __('db_diag.inspecting') . " cylinder_transactions " . __('db_diag.table') . "\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM cylinder_transactions");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo __('db_diag.field') . " {$row['Field']} " . __('db_diag.type') . " {$row['Type']} " . __('db_diag.null') . " {$row['Null']} " . __('db_diag.default') . " {$row['Default']}\n";
    }
} catch (Exception $e) {
    echo __('db_diag.error') . " cylinder_transactions: " . $e->getMessage() . "\n";
}

echo "\n" . __('db_diag.inspecting') . " partner_transaction_items " . __('db_diag.table') . "\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM partner_transaction_items");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo __('db_diag.field') . " {$row['Field']} " . __('db_diag.type') . " {$row['Type']} " . __('db_diag.null') . " {$row['Null']} " . __('db_diag.default') . " {$row['Default']}\n";
    }
} catch (Exception $e) {
    echo __('db_diag.error') . " partner_transaction_items: " . $e->getMessage() . "\n";
}

echo "\n" . __('db_diag.testing') . "\n";
try {
    $migrations = [];
    
    $col_info = $pdo->query("SHOW COLUMNS FROM cylinders WHERE Field = 'status'")->fetch();
    echo __('db_diag.current_status') . " {$col_info['Type']}\n";
    
    $col_info_tx = $pdo->query("SHOW COLUMNS FROM cylinder_transactions WHERE Field = 'transaction_type'")->fetch();
    echo __('db_diag.current_type') . " {$col_info_tx['Type']}\n";
    
} catch (Exception $e) {
    echo __('db_diag.test_failed') . " " . $e->getMessage() . "\n";
}
echo "</pre>";
