<?php
/**
 * Standalone one-time migration script.
 * Run this once after deployment, then remove all run*Migration() calls from page loads.
 * Usage:   php run-migrations.php
 * Or via browser: https://yourdomain.com/admin/run-migrations.php
 *
 * Safe to re-run — all operations are idempotent.
 */

$start = microtime(true);
$log = [];

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/ai/ai-config.php';
require_once __DIR__ . '/ai-migration.php';

$log[] = 'Connected to database.';

// Run ALL migrations in dependency-safe order
runPartnerMigrations($pdo);
$log[] = 'runPartnerMigrations OK.';

runRefillRentalMigrations($pdo);
$log[] = 'runRefillRentalMigrations OK.';

runConsumerCylinderMigrations($pdo);
$log[] = 'runConsumerCylinderMigrations OK.';

runDepositReceiptMigrations($pdo);
$log[] = 'runDepositReceiptMigrations OK.';

runSellCylinderMigrations($pdo);
$log[] = 'runSellCylinderMigrations OK.';

runCreditMigrations($pdo);
$log[] = 'runCreditMigrations OK.';

runGasSizesMigration($pdo);
$log[] = 'runGasSizesMigration OK.';

runCustomerPortalMigrations($pdo);
$log[] = 'runCustomerPortalMigrations OK.';

runDatabaseIndexes($pdo);
$log[] = 'runDatabaseIndexes OK.';

runCustomerCylinderMergeMigration($pdo);
$log[] = 'runCustomerCylinderMergeMigration OK.';

runRefillWithoutExchangeMigrations($pdo);
$log[] = 'runRefillWithoutExchangeMigrations OK.';

runLedgerGroupMigrations($pdo);
$log[] = 'runLedgerGroupMigrations OK.';

runDeletedCylindersMigration($pdo);
$log[] = 'runDeletedCylindersMigration OK.';

runVendorPartnerLedgerMigrations($pdo);
$log[] = 'runVendorPartnerLedgerMigrations OK.';

runAIConfigMigration($pdo);
$log[] = 'runAIConfigMigration OK.';

runAIMigrations($pdo);
$log[] = 'runAIMigrations OK.';

runProductCategoryMigrations($pdo);
$log[] = 'runProductCategoryMigrations OK.';

addProductNewColumns($pdo);
$log[] = 'addProductNewColumns OK.';

runRefillOrderProductColumns($pdo);
$log[] = 'runRefillOrderProductColumns OK.';

runDropPODueDateMigration($pdo);
$log[] = 'runDropPODueDateMigration OK.';

// Vendors active_refill_count column
try {
    $pdo->exec("ALTER TABLE vendors ADD COLUMN active_refill_count INT NOT NULL DEFAULT 0");
} catch (PDOException $e) {}
$log[] = 'vendors.active_refill_count OK.';

// ── Additional performance indexes (not yet in runDatabaseIndexes) ──
$extraIndexes = [
    "CREATE INDEX IF NOT EXISTS idx_cylinders_gas_type_size_status ON cylinders (gas_type_id, size_capacity, status)",
    "CREATE INDEX IF NOT EXISTS idx_cylinders_current_customer_status ON cylinders (current_customer_id, status)",
    "CREATE INDEX IF NOT EXISTS idx_refill_orders_customer_date ON refill_orders (customer_id, order_date)",
    "CREATE INDEX IF NOT EXISTS idx_cylinder_transactions_cyl_date ON cylinder_transactions (cylinder_id, transaction_date)",
];
foreach ($extraIndexes as $sql) {
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        try {
            if (preg_match('/INDEX\s+(\S+)/', $sql, $m)) {
                $index_name = $m[1];
                $table = preg_replace('/^ALTER TABLE (\S+).*$/', '$1', $sql);
                $check = $pdo->query("SHOW INDEXES FROM `$table` WHERE Key_name = '$index_name'");
                if (!$check->fetch()) {
                    $sql_fixed = preg_replace('/ADD INDEX IF NOT EXISTS/', 'ADD INDEX', $sql);
                    $pdo->exec($sql_fixed);
                }
            }
        } catch (PDOException $e2) {}
    }
}
$log[] = 'Extra performance indexes OK.';

// Update inventory after all schema changes
syncInventory($pdo);
$log[] = 'Inventory synced.';

$elapsed = round(microtime(true) - $start, 3);

// CLI output
if (php_sapi_name() === 'cli') {
    echo "Migrations completed in {$elapsed}s\n";
    foreach ($log as $l) echo "  - $l\n";
    exit(0);
}

// Browser output
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Migrations</title>
<style>body{font-family:sans-serif;padding:2rem;max-width:600px;margin:auto;background:#f8fafc;}
h1{font-size:1.2rem;color:#1e293b;}ul{background:#fff;border-radius:8px;padding:1rem 1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.1);}
li{padding:4px 0;font-size:.9rem;color:#334155;}.ok{color:#16a34a;font-weight:700;}.time{color:#64748b;font-size:.8rem;margin-top:1rem;}</style>
</head><body>
<h1>Migration Results</h1>
<ul><?php foreach($log as $l): ?><li><span class="ok">&#10003;</span> <?=htmlspecialchars($l)?></li><?php endforeach; ?></ul>
<p class="time">Completed in <?=$elapsed?> seconds.</p>
<p style="font-size:.8rem;color:#94a3b8;">Now remove all <code>run*Migration()</code> calls from individual page files.</p>
</body></html>
