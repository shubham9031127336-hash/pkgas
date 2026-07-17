<?php
/**
 * Test Data Seeder — Nutan Gases
 * Creates known test records in the test database for repeatable testing.
 * Run: php tests/seed_test_data.php
 */
require_once __DIR__ . '/../public_html/admin/db.php';

echo "=== Seeding Test Data ===\n";

// 1. Test Customer
$stmt = $pdo->prepare("SELECT id FROM customers WHERE mobile = '9999999900'");
$stmt->execute();
if (!$stmt->fetch()) {
    $pdo->prepare("INSERT INTO customers (name, mobile, address, gst_number, customer_type, deposit_balance, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute(['Test Customer A', '9999999900', 'Test Address, Khagaria', '18AAAAA0000A1Z5', 'refill', 5000.00, 'active']);
    echo "  [OK] Test Customer A created\n";
} else {
    echo "  [SKIP] Test Customer A already exists\n";
}

// 2. Test Customer B (for exchange testing)
$stmt = $pdo->prepare("SELECT id FROM customers WHERE mobile = '9999999901'");
$stmt->execute();
if (!$stmt->fetch()) {
    $pdo->prepare("INSERT INTO customers (name, mobile, address, gst_number, customer_type, deposit_balance, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute(['Test Customer B', '9999999901', 'Another Test Address, Khagaria', '18AAAAA0000A1Z6', 'refill', 2000.00, 'active']);
    echo "  [OK] Test Customer B created\n";
} else {
    echo "  [SKIP] Test Customer B already exists\n";
}

// 3. Test Vendor
$stmt = $pdo->prepare("SELECT id FROM vendors WHERE mobile = '9999999902'");
$stmt->execute();
if (!$stmt->fetch()) {
    $pdo->prepare("INSERT INTO vendors (name, mobile, address, gst_number) VALUES (?, ?, ?, ?)")
        ->execute(['Test Vendor Co.', '9999999902', 'Vendor Street, Khagaria', '18BBBBB0000B1Z5']);
    echo "  [OK] Test Vendor created\n";
} else {
    echo "  [SKIP] Test Vendor already exists\n";
}

// 4. Test Partner
$stmt = $pdo->prepare("SELECT id FROM partners WHERE mobile = '9999999903'");
$stmt->execute();
if (!$stmt->fetch()) {
    $pdo->prepare("INSERT INTO partners (company_name, contact_person, mobile, address, gst_number, status) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(['Test Partner Ltd.', 'Partner Person', '9999999903', 'Partner Area, Khagaria', '18CCCCC0000C1Z5', 'active']);
    echo "  [OK] Test Partner created\n";
} else {
    echo "  [SKIP] Test Partner already exists\n";
}

// 5. Register test cylinders
$gas_types = $pdo->query("SELECT id, name FROM gas_types ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$customerA = $pdo->query("SELECT id FROM customers WHERE mobile = '9999999900'")->fetch();
$customerB = $pdo->query("SELECT id FROM customers WHERE mobile = '9999999901'")->fetch();
$vendor = $pdo->query("SELECT id FROM vendors WHERE mobile = '9999999902'")->fetch();

// Map gas names to known aliases
function gasId($types, $name) {
    foreach ($types as $id => $n) {
        if (stripos($n, $name) !== false) return $id;
    }
    return null;
}

// Create 10 test cylinders (unused serials)
$existing_serials = $pdo->query("SELECT serial_number FROM cylinders WHERE serial_number LIKE 'TEST-%'")->fetchAll(PDO::FETCH_COLUMN);
$test_cylinders = [
    ['serial' => 'TEST-OX-001', 'gas' => gasId($gas_types, 'Oxygen Medical') ?: gasId($gas_types, 'Oxygen'), 'size' => '47L'],
    ['serial' => 'TEST-OX-002', 'gas' => gasId($gas_types, 'Oxygen Medical') ?: gasId($gas_types, 'Oxygen'), 'size' => '40L'],
    ['serial' => 'TEST-NG-001', 'gas' => gasId($gas_types, 'Nitrogen'), 'size' => '47L'],
    ['serial' => 'TEST-NG-002', 'gas' => gasId($gas_types, 'Nitrogen'), 'size' => '10L'],
    ['serial' => 'TEST-AR-001', 'gas' => gasId($gas_types, 'Argon'), 'size' => '47L'],
    ['serial' => 'TEST-AC-001', 'gas' => gasId($gas_types, 'Acetylene'), 'size' => '10L'],
    ['serial' => 'TEST-CO2-001', 'gas' => gasId($gas_types, 'CO2'), 'size' => '47L'],
    ['serial' => 'TEST-OX-003', 'gas' => gasId($gas_types, 'Oxygen Medical') ?: gasId($gas_types, 'Oxygen'), 'size' => '47L'],
    ['serial' => 'TEST-NG-003', 'gas' => gasId($gas_types, 'Nitrogen'), 'size' => '40L'],
    ['serial' => 'TEST-AR-002', 'gas' => gasId($gas_types, 'Argon'), 'size' => '10L'],
];
$count = 0;
foreach ($test_cylinders as $tc) {
    if (!in_array($tc['serial'], $existing_serials)) {
        $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, purchase_date)
            VALUES (?, ?, ?, 'empty', CURDATE())")
            ->execute([$tc['serial'], $tc['gas'], $tc['size']]);
        $count++;
    }
}
echo "  [OK] $count test cylinders registered\n";

// 6. Update some cylinders to 'filled' for order testing
$stmt = $pdo->prepare("UPDATE cylinders SET status = 'filled' WHERE serial_number = ? LIMIT 1");
$stmt->execute(['TEST-OX-001']);
$stmt->execute(['TEST-OX-002']);
$stmt->execute(['TEST-NG-001']);
echo "  [OK] Marked 3 test cylinders as 'filled'\n";

// 7. Assign a cylinder to customer A for exchange testing
$pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE serial_number = ?")
    ->execute([$customerA['id'], 'TEST-OX-003']);
echo "  [OK] Assigned TEST-OX-003 to Customer A\n";

// 8. Sync inventory
require_once __DIR__ . '/../public_html/admin/inventory-utils.php';
syncInventory($pdo);
echo "  [OK] Inventory synced\n";

// Create a test portal user for customer A
$pdo->prepare("UPDATE customers SET email = 'test@test.com', password_hash = ?, login_enabled = 1 WHERE id = ?")
    ->execute([password_hash('test123', PASSWORD_BCRYPT), $customerA['id']]);
echo "  [OK] Portal login created for Customer A (test@test.com / test123)\n";

echo "\n=== Seeding Complete ===\n";
echo "Customer A ID: " . ($customerA['id'] ?? 'N/A') . "\n";
echo "Customer B ID: " . ($customerB['id'] ?? 'N/A') . "\n";
echo "Vendor ID: " . ($vendor['id'] ?? 'N/A') . "\n";
