<?php
/**
 * E2E Test Cleanup — Customer Management
 * Removes all test data created by the E2E tests.
 * Run: php tests/cleanup-customer-e2e.php
 */
require_once __DIR__ . '/../public_html/admin/db.php';
require_once __DIR__ . '/../public_html/admin/inventory-utils.php';

echo "=== Customer E2E Cleanup ===\n";

// Find all E2E test customers
$stmt = $pdo->query("SELECT id, name, mobile FROM customers WHERE mobile LIKE '9999%' AND name LIKE 'E2E%'");
$ids = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $ids[] = $row['id'];
    echo "  Found: {$row['name']} ({$row['mobile']}) ID={$row['id']}\n";
}

if (empty($ids)) {
    echo "  No E2E test customers found.\n";
} else {
    $idList = implode(',', $ids);
    try {
        $pdo->beginTransaction();

        // Release cylinders held by these customers
        $pdo->exec("UPDATE cylinders SET current_customer_id = NULL, status = 'empty' WHERE current_customer_id IN ($idList)");

        // Delete cylinder transactions
        $pdo->exec("DELETE FROM cylinder_transactions WHERE customer_id IN ($idList)");

        // Delete payments
        $pdo->exec("DELETE FROM payments WHERE customer_id IN ($idList)");

        // Delete refill order items (JOIN with orders)
        $pdo->exec("DELETE oi FROM refill_order_items oi JOIN refill_orders o ON oi.refill_order_id = o.id WHERE o.customer_id IN ($idList)");

        // Delete refill orders
        $pdo->exec("DELETE FROM refill_orders WHERE customer_id IN ($idList)");

        // Delete customers
        $pdo->exec("DELETE FROM customers WHERE id IN ($idList)");

        $pdo->commit();

        syncInventory($pdo);

        echo "  [OK] Deleted " . count($ids) . " test customer(s) and related data\n";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "  [FAIL] " . $e->getMessage() . "\n";
    }
}

// Also clean any orphan E2E records
$orphan_ids = [];
$stmt2 = $pdo->query("SELECT id FROM customers WHERE mobile LIKE '9999%' AND name LIKE 'E2E%'");
while ($row = $stmt2->fetch()) {
    $orphan_ids[] = $row['id'];
}
// Already handled above, just double-check
if (!empty($orphan_ids)) {
    echo "  [WARN] " . count($orphan_ids) . " orphan customers remain\n";
}

echo "\n=== Cleanup Complete ===\n";
