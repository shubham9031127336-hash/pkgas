<?php
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';
validateCsrfToken();

runVendorPartnerLedgerMigrations($pdo);
runVendorActivityLogMigration($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>window.location.href='vendors.php';</script>";
    exit();
}

$entity_type   = trim($_POST['entity_type'] ?? '');
$entity_id     = intval($_POST['entity_id'] ?? 0);
$amount        = floatval($_POST['amount'] ?? 0);
$payment_method = trim($_POST['payment_method'] ?? 'Cash');
$payment_date  = trim($_POST['payment_date'] ?? date('Y-m-d'));
$reference     = trim($_POST['reference'] ?? '');
$notes         = trim($_POST['notes'] ?? '');
$created_by    = $_SESSION['user_name'] ?? 'system';

if (!in_array($entity_type, ['vendor', 'partner']) || $entity_id <= 0 || $amount <= 0) {
    $error = 'Invalid request. Entity type, ID, and positive amount required.';
}

$entity_name = '';
try {
    if ($entity_type === 'vendor') {
        $stmt = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
        $stmt->execute([$entity_id]);
        $entity_name = $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT company_name FROM partners WHERE id = ?");
        $stmt->execute([$entity_id]);
        $entity_name = $stmt->fetchColumn();
    }
} catch (PDOException $e) {}

if (empty($entity_name)) {
    $error = 'Entity not found.';
}

if (empty($error)) {
    try {
        $pdo->beginTransaction();

        $result = processVendorPartnerPayment($pdo, $entity_type, $entity_id, $amount, $payment_method, $created_by, [
            'payment_date' => $payment_date,
            'reference' => $reference,
            'notes' => $notes,
        ]);

        $log_details = "Payment of \u{20B9}{$amount} recorded for {$entity_name}";
        if ($result['due_cleared'] > 0) $log_details .= ". Due cleared: \u{20B9}{$result['due_cleared']}";
        if ($result['advance_created'] > 0) $log_details .= ". Advance created: \u{20B9}{$result['advance_created']}";

        try {
            $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$created_by, 'Record Payment - ' . ucfirst($entity_type), $log_details]);
        } catch (PDOException $e) {}

        if ($entity_type === 'vendor') {
            $due_cleared = floatval($result['due_cleared'] ?? 0);
            $advance_created = floatval($result['advance_created'] ?? 0);
            logVendorActivity($pdo, $entity_id, 'payment_made', "Paid ₹" . number_format($amount, 2) . " to {$entity_name}", "Method: {$payment_method}" . ($due_cleared > 0 ? " | Due cleared: ₹" . number_format($due_cleared, 2) : "") . ($advance_created > 0 ? " | Prepaid: ₹" . number_format($advance_created, 2) : "") . ($reference ? " | Ref: {$reference}" : ""), [
                'payment_method' => $payment_method,
                'reference' => $reference,
                'notes' => $notes,
                'direction' => 'pay',
                'due_cleared' => $due_cleared,
                'advance_created' => $advance_created,
                'vendor_name' => $entity_name,
            ], [
                'amount' => $amount,
                'payment_method' => $payment_method,
                'created_by' => $created_by,
            ]);
        }

        $pdo->commit();

        $msg = urlencode('Payment of ' . $amount . ' recorded successfully.');
        if ($entity_type === 'vendor') {
            $redirect = "vendor-profile.php?id={$entity_id}";
        } else {
            $redirect = "partner-profile.php?id={$entity_id}";
        }
        echo "<script>window.location.href='{$redirect}&msg={$msg}';</script>";
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Database error: ' . $e->getMessage();
    }
}

if (!empty($error)) {
    if ($entity_type === 'vendor') {
        $redirect = "vendor-profile.php?id={$entity_id}";
    } else {
        $redirect = "partner-profile.php?id={$entity_id}";
    }
    echo "<script>window.location.href='{$redirect}&err=" . urlencode($error) . "';</script>";
    exit();
}
