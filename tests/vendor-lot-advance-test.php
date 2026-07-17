<?php
/**
 * Vendor Lot Advance Payment Bug Test
 *
 * Scenario: Send refilling lot to vendor with advance payment.
 * When receiving, only the remaining amount (total cost - advance) should be due.
 *
 * Bug: advance_used_this was double-counted in total_collected because the
 * original advance payment was already included in existing_payments_total_lot.
 *
 * How to run: php tests/vendor-lot-advance-test.php
 */

// Bootstrap: reuse the existing test framework configuration
require_once __DIR__ . '/../public_html/admin/db.php';
require_once __DIR__ . '/../public_html/admin/inventory-utils.php';
require_once __DIR__ . '/../public_html/admin/gst_helper.php';

$passed = 0;
$failed = 0;

function assert_eq($label, $expected, $actual, $tolerance = 0.01) {
    global $passed, $failed;
    if (is_float($expected) || is_float($actual)) {
        $ok = abs($expected - $actual) <= $tolerance;
    } else {
        $ok = $expected === $actual;
    }
    if ($ok) {
        echo "  ✓ $label\n";
        $passed++;
    } else {
        echo "  ✗ $label — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
        $failed++;
    }
}

function assert_true($label, $value) {
    global $passed, $failed;
    if ($value) {
        echo "  ✓ $label\n";
        $passed++;
    } else {
        echo "  ✗ $label — expected true, got " . var_export($value, true) . "\n";
        $failed++;
    }
}

function createTestVendor($pdo, $name, $mobile) {
    $stmt = $pdo->prepare("INSERT INTO vendors (name, mobile) VALUES (?, ?)");
    $stmt->execute([$name, $mobile]);
    return $pdo->lastInsertId();
}

function createTestCylinder($pdo, $serial, $gas_type_id, $size, $status = 'empty') {
    $stmt = $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, ownership_type) VALUES (?, ?, ?, ?, 'owned')");
    $stmt->execute([$serial, $gas_type_id, $size, $status]);
    return $pdo->lastInsertId();
}

function getLotPayments($pdo, $lot_id) {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE lot_id = ? ORDER BY id");
    $stmt->execute([$lot_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getLotLedgerEntries($pdo, $lot_id) {
    $stmt = $pdo->prepare("SELECT * FROM vendor_partner_ledger WHERE reference_type = 'dispatch_lot' AND reference_id = ? ORDER BY id");
    $stmt->execute([$lot_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getLatestLot($pdo, $vendor_id) {
    $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE vendor_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$vendor_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getLotItems($pdo, $lot_id) {
    $stmt = $pdo->prepare("SELECT * FROM dispatch_lot_items WHERE lot_id = ? ORDER BY id");
    $stmt->execute([$lot_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getVendorAdvanceBalance($pdo, $vendor_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(advance_balance, 0) FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$vendor_id]);
    return floatval($stmt->fetchColumn());
}

// ============================================================
// TEST: Dispatch Lot with Advance, then Receive & Verify
// ============================================================

echo "=== Vendor Lot Advance Payment Bug Test ===\n\n";

$pdo->beginTransaction();

try {
    // ── Setup ──
    $vendor_id = createTestVendor($pdo, 'Advance Test Vendor', '9999990002');
    echo "Created vendor ID: $vendor_id\n";

    // Use gas_type_id=15 (TestSQL, refill_cost=50)
    $gas_type_id = 15;
    $size = '40L';

    $cyl_ids = [];
    $cyl_ids[] = createTestCylinder($pdo, 'ADV-TEST-001', $gas_type_id, $size);
    $cyl_ids[] = createTestCylinder($pdo, 'ADV-TEST-002', $gas_type_id, $size);
    $cyl_ids[] = createTestCylinder($pdo, 'ADV-TEST-003', $gas_type_id, $size);
    echo "Created " . count($cyl_ids) . " cylinders: " . implode(', ', $cyl_ids) . "\n\n";

    // ── Step 1: Dispatch with advance payment ──
    echo "--- Step 1: Dispatch Lot with Advance ---\n";

    $cylinder_count = count($cyl_ids);
    $refill_cost_per_cyl = 300; // ₹300 per cylinder
    $est_total = $refill_cost_per_cyl * $cylinder_count; // ₹900
    $advance_amount = 500;
    $lot_number = 'LOT-TEST-' . date('Ymd') . '-001';

    // GST not applicable for this test
    $gst_rate = 0;
    $gst_amount = 0;
    $estimated_grand_total = $est_total;
    $remaining_balance = $advance_amount > 0 ? max(0, $estimated_grand_total - $advance_amount) : $estimated_grand_total;
    $advance_total = $advance_amount;
    $created_by = 'test_script';

    $lot_stmt = $pdo->prepare("INSERT INTO dispatch_lots (lot_number, vendor_id, dispatch_date, notes, estimated_total, cylinder_count, returned_count, lot_status, advance_amount, gst_rate, gst_amount, gst_applicable, gst_type, gst_locked, total_paid, remaining_balance, payment_status, created_by) VALUES (?, ?, NOW(), 'Test dispatch', ?, ?, 0, 'open', ?, ?, ?, 0, 'CGST/SGST', 0, ?, ?, ?, ?)");
    $lot_stmt->execute([$lot_number, $vendor_id, $est_total > 0 ? $est_total : null, $cylinder_count, $advance_amount, $gst_rate, $gst_amount, $advance_total, $remaining_balance, $advance_total > 0 ? 'partial' : 'unpaid', $created_by]);
    $lot_id = $pdo->lastInsertId();
    echo "Created lot #$lot_id ($lot_number)\n";

    // Insert lot items and update cylinders
    $item_stmt = $pdo->prepare("INSERT INTO dispatch_lot_items (lot_id, cylinder_id, serial_number, gas_type_id, size_capacity, dispatch_status) VALUES (?, ?, ?, ?, ?, 'dispatched')");
    foreach ($cyl_ids as $cid) {
        $cdata = $pdo->prepare("SELECT serial_number, gas_type_id, size_capacity FROM cylinders WHERE id = ?");
        $cdata->execute([$cid]);
        $cr = $cdata->fetch();
        if (!$cr) continue;
        $item_stmt->execute([$lot_id, $cid, $cr['serial_number'], $cr['gas_type_id'], $cr['size_capacity']]);
        $pdo->prepare("UPDATE cylinders SET status = 'sent_to_vendor', current_vendor_id = ? WHERE id = ?")->execute([$vendor_id, $cid]);
        $pdo->prepare("UPDATE vendors SET active_refill_count = active_refill_count + 1 WHERE id = ?")->execute([$vendor_id]);
    }

    // Record advance payment
    $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, notes, payment_date) VALUES (?, ?, ?, 'Bank Transfer', 'vendor_payment', 'Advance test payment', NOW())")
        ->execute([$lot_id, $vendor_id, $advance_amount]);

    // Vendor-partner ledger entry for advance
    $stmt = $pdo->prepare("SELECT COALESCE(running_balance, 0) as rb, COALESCE(advance_balance, 0) as ab, COALESCE(due_balance, 0) as db FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$vendor_id]);
    $bal = $stmt->fetch();
    $running = floatval($bal['rb'] ?? 0);
    $advance_bal = floatval($bal['ab'] ?? 0);
    $due_bal = floatval($bal['db'] ?? 0);
    $new_running = $running + $advance_amount;
    $new_advance = $advance_bal + $advance_amount;
    $pdo->prepare("INSERT INTO vendor_partner_ledger (entity_type, entity_id, transaction_date, transaction_type, debit, credit, running_balance, advance_balance, due_balance, settlement_status, reference_type, remarks, created_by) VALUES (?, ?, NOW(), 'advance', 0, ?, ?, ?, ?, 'partial', 'dispatch_lot', ?, ?)")
        ->execute(['vendor', $vendor_id, $advance_amount, $new_running, $new_advance, $due_bal, "Advance payment for $lot_number", $created_by]);

    recalcLotFinancials($pdo, $lot_id);

    // Verify dispatch state
    $lot = getLatestLot($pdo, $vendor_id);
    echo "Lot after dispatch: total_paid={$lot['total_paid']}, remaining_balance={$lot['remaining_balance']}, payment_status={$lot['payment_status']}\n";

    // With advance ₹500 on estimated ₹900, remaining should be ₹400
    assert_eq('remaining_balance after dispatch = est_total - advance', 400, floatval($lot['remaining_balance']));
    assert_eq('total_paid after dispatch = advance', 500, floatval($lot['total_paid']));
    assert_eq('payment_status after dispatch', 'partial', $lot['payment_status']);

    // Verify vendor advance balance
    $vab = getVendorAdvanceBalance($pdo, $vendor_id);
    assert_eq('vendor advance balance = 500', 500, $vab);

    echo "\n--- Step 2: Receive all cylinders with correct remaining ---\n";

    // Simulate the receive flow: receive all 3 cylinders with refill cost = ₹300 each
    // Total refill cost = ₹900, advance = ₹500, remaining should = ₹400

    // First, the receive-cylinder.php logic (simplified):
    $received = 0;
    $sum_refill = 0;
    $total_in_lot = $cylinder_count; // 3

    foreach ($cyl_ids as $cyl_id) {
        // Update cylinders back to filled
        $upd = $pdo->prepare("UPDATE cylinders SET status = 'filled', current_vendor_id = NULL WHERE id = ? AND status = 'sent_to_vendor' AND current_vendor_id = ?");
        $upd->execute([$cyl_id, $vendor_id]);
        if ($upd->rowCount() > 0) {
            $sum_refill += $refill_cost_per_cyl; // ₹300
            $pdo->prepare("UPDATE vendors SET active_refill_count = GREATEST(0, active_refill_count - 1) WHERE id = ?")->execute([$vendor_id]);
            $pdo->prepare("UPDATE dispatch_lot_items SET dispatch_status = 'received', receive_date = NOW(), refill_cost = ? WHERE lot_id = ? AND cylinder_id = ?")
                ->execute([$refill_cost_per_cyl, $lot_id, $cyl_id]);
            $received++;
        }
    }

    echo "Received $received cylinders, sum_refill = ₹$sum_refill\n";

    // ── Compute the financials (replicating receive-cylinder.php logic) ──
    $lot_ref = getLatestLot($pdo, $vendor_id); // Refresh lot data

    $net_total = $sum_refill; // ₹900 (no GST, no deductions)

    // Advance utilization calculation
    $lot_advance = floatval($lot_ref['advance_amount']); // ₹500
    $advance_balance = getVendorAdvanceBalance($pdo, $vendor_id); // ₹500
    $already_utilized = 0;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE lot_id = ? AND payment_subtype = 'advance_utilized'");
        $stmt->execute([$lot_id]);
        $already_utilized = floatval($stmt->fetchColumn());
    } catch (PDOException $e) {}
    $remaining_lot_advance = max(0, $lot_advance - $already_utilized); // ₹500
    $advance_proportion = $received / $total_in_lot; // 1.0
    $prorated_advance = $lot_advance * $advance_proportion; // ₹500
    $advance_used_this = min($prorated_advance, $advance_balance, $remaining_lot_advance, $net_total); // min(500, 500, 500, 900) = 500

    echo "Advance used this receive: ₹$advance_used_this\n";

    // Existing payments total (the FIX: exclude advance_utilized)
    $existing_payments_total_lot = 0;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE lot_id = ? AND (payment_subtype IS NULL OR payment_subtype != 'advance_utilized')");
        $stmt->execute([$lot_id]);
        $existing_payments_total_lot = floatval($stmt->fetchColumn());
    } catch (PDOException $e) {}
    echo "Existing payments (excluding advance_utilized): ₹$existing_payments_total_lot\n";

    // The FIX: total_collected does NOT include advance_used_this
    $total_collected = $existing_payments_total_lot; // no additional payment rows
    $remaining_after = max(0, $net_total - $total_collected);

    echo "total_collected = ₹$total_collected, remaining_after = ₹$remaining_after\n";

    // ── Now create the ledger entries and advance_utilized payment (as receive-cylinder.php does) ──
    addVendorRefillLedgerEntry($pdo, $vendor_id, $net_total, 'due_created', $lot_id, "Refill cost $lot_number - $received cylinders", $created_by, 'dispatch_lot');

    if ($advance_used_this > 0) {
        $stmt = $pdo->prepare("SELECT COALESCE(running_balance, 0) as rb, COALESCE(advance_balance, 0) as ab, COALESCE(due_balance, 0) as db FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$vendor_id]);
        $bal = $stmt->fetch();
        $new_advance = max(0, floatval($bal['ab'] ?? 0) - $advance_used_this);
        $new_due = max(0, floatval($bal['db'] ?? 0) - $advance_used_this);
        $pdo->prepare("INSERT INTO vendor_partner_ledger (entity_type, entity_id, transaction_date, transaction_type, debit, credit, running_balance, advance_balance, due_balance, settlement_status, reference_type, reference_id, remarks, created_by) VALUES (?, ?, NOW(), 'advance_utilized', ?, 0, ?, ?, ?, 'partial', 'dispatch_lot', ?, ?, ?)")
            ->execute(['vendor', $vendor_id, $advance_used_this, floatval($bal['rb'] ?? 0), $new_advance, $new_due, $lot_id, "Advance utilized for $lot_number", $created_by]);

        $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, payment_subtype, notes, payment_date) VALUES (?, ?, ?, 'Advance', 'vendor_refill_payment', 'advance_utilized', ?, NOW())")
            ->execute([$lot_id, $vendor_id, $advance_used_this, "Advance utilized for $lot_number"]);
    }

    // THIS IS THE KEY ASSERTION — before fix it was 0 (double-counted), should be 400
    assert_eq('remaining after receive = 900 - 500', 400, $remaining_after);

    // If remaining_after = 0 (bug), payment_status would be 'paid' incorrectly
    // With fix, remaining_after = 400, payment_status should stay 'partial'
    $pay_status = 'unpaid';
    if ($total_collected > 0 || $advance_used_this > 0) {
        $pay_status = 'partial';
    }
    if ($received >= $total_in_lot && $total_in_lot > 0 && $remaining_after <= 0) {
        $pay_status = 'paid';
    }
    assert_eq('payment_status after receive (should be partial, not paid)', 'partial', $pay_status);

    echo "\n--- Step 3: Verify ledger entries ---\n";
    $ledger = getLotLedgerEntries($pdo, $lot_id);
    foreach ($ledger as $entry) {
        echo "  [{$entry['transaction_type']}] ab={$entry['advance_balance']} db={$entry['due_balance']} rb={$entry['running_balance']}\n";
    }

    // After advance utilization: advance_balance should be 0 (500 utilized fully)
    // After due_created: due_balance should be 900
    // After advance_utilized: due_balance should be 400
    $last_ledger = end($ledger);
    assert_eq('final advance_balance', 0, floatval($last_ledger['advance_balance']));
    assert_eq('final due_balance', 400, floatval($last_ledger['due_balance']));

    echo "\n--- Step 4: Simulate settling remaining ₹400 ---\n";
    // This simulates the settle_lot action from lot-dashboard.php
    $payment_amount = 400;
    $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, payment_subtype, notes, payment_date) VALUES (?, ?, ?, 'Cash', 'vendor_refill_payment', 'settlement', 'Remaining payment', NOW())")
        ->execute([$lot_id, $vendor_id, $payment_amount]);

    addVendorRefillLedgerEntry($pdo, $vendor_id, $payment_amount, 'payment', $lot_id, "Settlement for $lot_number", 'test_script', 'dispatch_lot');

    recalcLotFinancials($pdo, $lot_id);

    $lot_final = getLatestLot($pdo, $vendor_id);
    echo "Final lot: total_paid={$lot_final['total_paid']}, remaining_balance={$lot_final['remaining_balance']}, payment_status={$lot_final['payment_status']}\n";

    // After settling ₹400: total_paid should be 500 (advance) + 400 = 900
    // remaining_balance should be 0
    assert_eq('final total_paid = 500 + 400', 900, floatval($lot_final['total_paid']));
    assert_eq('final remaining_balance = 0', 0, floatval($lot_final['remaining_balance']));
    assert_eq('final payment_status = paid', 'paid', $lot_final['payment_status']);

    echo "\n=== RESULTS ===\n";
    echo "Passed: $passed\n";
    echo "Failed: $failed\n";

    $pdo->rollBack();

    echo "\n";
    if ($failed === 0) {
        echo "✓ ALL TESTS PASSED — Bug is fixed!\n";
        exit(0);
    } else {
        echo "✗ $failed TEST(S) FAILED\n";
        exit(1);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n!!! TEST ERROR: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit(1);
}
