<?php
require_once 'db.php';

$migrations = [
    "ALTER TABLE `customers` ADD COLUMN `credit_limit` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `deposit_balance`",
    "ALTER TABLE `customers` ADD COLUMN `credit_used` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `credit_limit`",
    "ALTER TABLE `customers` ADD COLUMN `credit_status` ENUM('good', 'warning', 'blocked') NOT NULL DEFAULT 'good' AFTER `credit_used`",
    "ALTER TABLE `customers` ADD COLUMN `credit_terms` INT DEFAULT 30 COMMENT 'Payment terms in days (Net 30, etc.)' AFTER `credit_status`"
];

try {
    foreach ($migrations as $sql) {
        $pdo->exec($sql);
        echo "Executed: $sql<br>";
    }
    echo "<br>Credit columns added successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>