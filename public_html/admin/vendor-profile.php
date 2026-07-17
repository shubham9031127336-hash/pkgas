<?php
$page_title = 'Vendor Profile';
$active_menu = 'vendors';
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/gst/json/schema.php';

runPartnerMigrations($pdo);
runRefillCostMigrations($pdo);
runVendorRefillBatchItemsMigration($pdo);
runVendorInvoiceMigrations($pdo);
runVendorAccountingMigrations($pdo);
runVendorActivityLogMigration($pdo);

$vendor_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($vendor_id <= 0) { echo "<script>window.location.href='vendors.php';</script>"; exit(); }

$stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->execute([$vendor_id]);
$vendor_data = $stmt->fetch();
if (!$vendor_data) { echo "<script>window.location.href='vendors.php';</script>"; }

$message = trim($_GET['msg'] ?? '');
$error = trim($_GET['err'] ?? '');

// ── UPDATE VENDOR DETAILS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_vendor_details') {
    validateCsrfToken();
    $name = trim($_POST['name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gst_number = trim($_POST['gst_number'] ?? '');
    $gst_registration_type = $_POST['gst_registration_type'] ?? 'regular';
    $pan = trim($_POST['pan'] ?? '');
    $tan = trim($_POST['tan'] ?? '');
    $state_code = intval($_POST['state_code'] ?? 0);
    $city = trim($_POST['city'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $bank_account_holder = trim($_POST['bank_account_holder'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $bank_ifsc = trim($_POST['bank_ifsc'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_branch = trim($_POST['bank_branch'] ?? '');
    $payment_terms = intval($_POST['payment_terms'] ?? 30);
    $notes = trim($_POST['notes'] ?? '');
    $states = gstnStateCodes();
    $state_name = $state_code ? ($states[$state_code] ?? null) : null;
    if (empty($name) || empty($mobile)) {
        $error = 'Name and mobile are required.';
    } else {
        try {
            $upd = $pdo->prepare("UPDATE vendors SET name=?, contact_person=?, mobile=?, email=?, address=?, gst_number=?, gst_registration_type=?, pan=?, tan=?, state_code=?, state_name=?, city=?, pincode=?, bank_account_holder=?, bank_account_number=?, bank_ifsc=?, bank_name=?, bank_branch=?, payment_terms=?, notes=? WHERE id=?");
            $upd->execute([$name, $contact_person, $mobile, $email ?: null, $address, $gst_number ?: null, $gst_registration_type, $pan ?: null, $tan ?: null, $state_code ?: null, $state_name, $city ?: null, $pincode ?: null, $bank_account_holder ?: null, $bank_account_number ?: null, $bank_ifsc ?: null, $bank_name ?: null, $bank_branch ?: null, $payment_terms, $notes ?: null, $vendor_id]);
            $vendor_data = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
            $vendor_data->execute([$vendor_id]);
            $vendor_data = $vendor_data->fetch();
            $message = 'Vendor details updated successfully.';
        } catch (PDOException $e) {
            $error = 'Failed to update vendor: ' . $e->getMessage();
        }
    }
}

// ── PAY REFILL BATCH (disabled — use lot-based settlement) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_refill_batch') {
    $error = 'Batch payment is disabled. Use Vendor Settlement page for lot-based payments.';
}

// ── PAYMENT / ADVANCE SETTLEMENT (inline) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'vendor_settle') {
    validateCsrfToken();
    $direction      = trim($_POST['direction'] ?? 'pay');
    $amount_p       = floatval($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $payment_date   = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $reference      = trim($_POST['reference'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');
    $created_by     = $_SESSION['user_name'] ?? 'system';
    $valid_methods  = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'NEFT', 'RTGS', 'Online Transfer', 'Adjustment'];

    if ($amount_p <= 0) {
        $error = $direction === 'receive' ? 'Amount received must be greater than zero.' : 'Payment amount must be greater than zero.';
    } elseif (!in_array($payment_method, $valid_methods)) {
        $error = 'Please select a valid payment method.';
    } elseif (!strtotime($payment_date)) {
        $error = 'Please enter a valid date.';
    } else {
        try {
            $pdo->beginTransaction();
            $result = processVendorPartnerPayment($pdo, 'vendor', $vendor_id, $amount_p, $payment_method, $created_by, [
                'payment_date' => $payment_date,
                'reference'    => $reference,
                'notes'        => $notes,
            ], $direction);
            $pdo->commit();
            if ($direction === 'receive') {
                $message = 'Advance settlement of ₹' . number_format($amount_p, 2) . ' recorded for ' . htmlspecialchars($vendor_data['name']) . '.';
                logVendorActivity($pdo, $vendor_id, 'advance_settled', "Received ₹" . number_format($amount_p, 2) . " back from advance — {$vendor_data['name']}", "Method: {$payment_method}" . ($reference ? " | Ref: {$reference}" : ""), [
                    'payment_method' => $payment_method,
                    'reference' => $reference,
                    'notes' => $notes,
                    'direction' => 'receive',
                    'vendor_name' => $vendor_data['name'],
                ], [
                    'amount' => $amount_p,
                    'payment_method' => $payment_method,
                    'created_by' => $created_by,
                ]);
            } else {
                $message = 'Payment of ₹' . number_format($amount_p, 2) . ' recorded successfully for ' . htmlspecialchars($vendor_data['name']) . '.';
                $result = $result ?? [];
                $due_cleared = floatval($result['due_cleared'] ?? 0);
                $advance_created = floatval($result['advance_created'] ?? 0);
                logVendorActivity($pdo, $vendor_id, 'payment_made', "Paid ₹" . number_format($amount_p, 2) . " to {$vendor_data['name']}", "Method: {$payment_method}" . ($due_cleared > 0 ? " | Due cleared: ₹" . number_format($due_cleared, 2) : "") . ($advance_created > 0 ? " | Prepaid: ₹" . number_format($advance_created, 2) : "") . ($reference ? " | Ref: {$reference}" : ""), [
                    'payment_method' => $payment_method,
                    'reference' => $reference,
                    'notes' => $notes,
                    'direction' => 'pay',
                    'due_cleared' => $due_cleared,
                    'advance_created' => $advance_created,
                    'vendor_name' => $vendor_data['name'],
                ], [
                    'amount' => $amount_p,
                    'payment_method' => $payment_method,
                    'created_by' => $created_by,
                ]);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// ── LOT PAY (inline, updates lot + ledger) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'lot_pay') {
    validateCsrfToken();
    $lot_id     = intval($_POST['lot_id'] ?? 0);
    $amount_p   = floatval($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $payment_date   = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $reference      = trim($_POST['reference'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');
    $created_by     = $_SESSION['user_name'] ?? 'system';
    $valid_methods  = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'NEFT', 'RTGS', 'Online Transfer', 'Adjustment'];

    if ($lot_id <= 0) { $error = 'Invalid lot.'; }
    elseif ($amount_p <= 0) { $error = 'Amount must be greater than zero.'; }
    elseif (!in_array($payment_method, $valid_methods)) { $error = 'Select a valid payment method.'; }
    elseif (!strtotime($payment_date)) { $error = 'Enter a valid date.'; }
    else {
        $stmt = $pdo->prepare("SELECT id, lot_number, vendor_id, remaining_balance FROM dispatch_lots WHERE id = ? AND vendor_id = ?");
        $stmt->execute([$lot_id, $vendor_id]);
        $lot = $stmt->fetch();
        if (!$lot) { $error = 'Lot not found for this vendor.'; }
        elseif ($amount_p > floatval($lot['remaining_balance'])) { $error = 'Amount (₹' . number_format($amount_p, 2) . ') exceeds remaining balance (₹' . number_format($lot['remaining_balance'], 2) . ').'; }
        else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO payments (vendor_id, lot_id, amount, payment_method, payment_type, payment_subtype, reference, notes, payment_date, created_by) VALUES (?, ?, ?, ?, 'vendor_refill_payment', 'settlement', ?, ?, ?, ?)");
                $stmt->execute([$vendor_id, $lot_id, $amount_p, $payment_method, $reference ?: null, $notes ?: 'Payment for ' . $lot['lot_number'], $payment_date, $created_by]);
                addVendorRefillLedgerEntry($pdo, $vendor_id, $amount_p, 'payment', $lot_id, 'Payment for ' . $lot['lot_number'] . ' (' . $payment_method . ')', $created_by, 'dispatch_lot');
                recalcLotFinancials($pdo, $lot_id);
                // Sync vendor invoice payment status
                if (function_exists('updateVendorInvoicePaymentStatus')) {
                    $inv_st = $pdo->prepare("SELECT id FROM vendor_invoices WHERE lot_id = ?");
                    $inv_st->execute([$lot_id]);
                    while ($inv_r = $inv_st->fetch()) {
                        updateVendorInvoicePaymentStatus($pdo, $inv_r['id']);
                    }
                }
                $pdo->commit();
                $message = 'Payment of ₹' . number_format($amount_p, 2) . ' recorded for ' . htmlspecialchars($lot['lot_number']) . '.';
                logVendorActivity($pdo, $vendor_id, 'payment_made', "Paid ₹" . number_format($amount_p, 2) . " for {$lot['lot_number']}", "Method: {$payment_method}" . ($reference ? " | Ref: {$reference}" : ""), [
                    'payment_method' => $payment_method,
                    'reference' => $reference,
                    'notes' => $notes,
                    'lot_number' => $lot['lot_number'],
                    'vendor_name' => $vendor_data['name'],
                ], [
                    'reference_type' => 'dispatch_lot',
                    'reference_id' => $lot_id,
                    'amount' => $amount_p,
                    'payment_method' => $payment_method,
                    'lot_number' => $lot['lot_number'],
                    'created_by' => $created_by,
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

// ── DATA ──
$borrowed_cylinders = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, g.name AS gas_name
        FROM cylinders c LEFT JOIN gas_types g ON c.gas_type_id = g.id
        WHERE c.current_vendor_id = ? AND c.status = 'borrowed_from_vendor'
        ORDER BY c.borrow_date DESC, c.serial_number ASC
    ");
    $stmt->execute([$vendor_id]);
    $borrowed_cylinders = $stmt->fetchAll();
} catch (PDOException $e) {}

$dispatched_cylinders = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, g.name AS gas_name
        FROM cylinders c LEFT JOIN gas_types g ON c.gas_type_id = g.id
        WHERE c.current_vendor_id = ? AND c.status = 'sent_to_vendor'
        ORDER BY c.serial_number ASC
    ");
    $stmt->execute([$vendor_id]);
    $dispatched_cylinders = $stmt->fetchAll();
} catch (PDOException $e) {}
$dispatched_count = count($dispatched_cylinders);

// Dispatch lots
$lots = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE vendor_id = ? ORDER BY dispatch_date DESC LIMIT 20");
    $stmt->execute([$vendor_id]);
    $lots = $stmt->fetchAll();
} catch (PDOException $e) {}

// Unpaid lot total
$unpaid_lot_total = 0;
$unpaid_lot_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(final_total - total_paid), 0), COUNT(*) FROM dispatch_lots WHERE vendor_id = ? AND payment_status != 'paid'");
    $stmt->execute([$vendor_id]);
    $row = $stmt->fetch();
    $unpaid_lot_total = floatval($row[0] ?? 0);
    $unpaid_lot_count = intval($row[1] ?? 0);
} catch (PDOException $e) {}

// Fetch lot items
$lot_items_map = [];
$lot_item_counts = [];
if (!empty($lots)) {
    $lot_ids = array_column($lots, 'id');
    $ph = implode(',', array_fill(0, count($lot_ids), '?'));
    // Count received per lot
    try {
        $stmt = $pdo->prepare("SELECT lot_id, dispatch_status, COUNT(*) AS cnt, SUM(refill_cost) AS total_cost FROM dispatch_lot_items WHERE lot_id IN ($ph) GROUP BY lot_id, dispatch_status");
        $stmt->execute($lot_ids);
        while ($r = $stmt->fetch()) {
            $lid = intval($r['lot_id']);
            $st = $r['dispatch_status'];
            if (!isset($lot_item_counts[$lid])) $lot_item_counts[$lid] = ['dispatched' => 0, 'received' => 0, 'total_cost' => 0];
            $lot_item_counts[$lid][$st] = intval($r['cnt']);
            $lot_item_counts[$lid]['total_cost'] += floatval($r['total_cost'] ?? 0);
        }
    } catch (PDOException $e) {}
    // Fetch items for expandable detail
    try {
        $stmt = $pdo->prepare("SELECT dli.*, g.name AS gas_name FROM dispatch_lot_items dli JOIN gas_types g ON dli.gas_type_id = g.id WHERE dli.lot_id IN ($ph) ORDER BY dli.lot_id, dli.serial_number");
        $stmt->execute($lot_ids);
        while ($r = $stmt->fetch()) {
            $lid = intval($r['lot_id']);
            $lot_items_map[$lid][] = $r;
        }
    } catch (PDOException $e) {}
}

// ── Auto-sync active_refill_count to fix drift ──
syncVendorActiveCount($pdo, $vendor_id);
$stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->execute([$vendor_id]);
$vendor_data = $stmt->fetch();

// Ledger balances
$ledger = getVendorPartnerBalances($pdo, 'vendor', $vendor_id);

// Input GST summary
$gst_input_total = 0;
$gst_input_count = 0;
try {
    $gst_q = $pdo->prepare("SELECT COALESCE(SUM(gst_amount), 0) AS total_gst, COUNT(*) AS cnt FROM gst_ledger WHERE entity_type = 'vendor' AND entity_id = ? AND input_output_type = 'input'");
    $gst_q->execute([$vendor_id]);
    $gst_r = $gst_q->fetch();
    $gst_input_total = floatval($gst_r['total_gst'] ?? 0);
    $gst_input_count = intval($gst_r['cnt'] ?? 0);
} catch (\Exception $e) {}

// Vendor invoices
$vendor_invoices = [];
$vendor_invoice_totals = ['count' => 0, 'total' => 0, 'gst' => 0, 'paid' => 0, 'balance' => 0];
try {
    $stmt = $pdo->prepare("SELECT vi.* FROM vendor_invoices vi WHERE vi.vendor_id = ? ORDER BY vi.invoice_date DESC LIMIT 10");
    $stmt->execute([$vendor_id]);
    $vendor_invoices = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(grand_total), 0), COALESCE(SUM(gst_amount), 0), COALESCE(SUM(paid_amount), 0), COALESCE(SUM(balance), 0) FROM vendor_invoices WHERE vendor_id = ?");
    $stmt->execute([$vendor_id]);
    $r = $stmt->fetch();
    $vendor_invoice_totals = [
        'count' => intval($r[0] ?? 0),
        'total' => floatval($r[1] ?? 0),
        'gst' => floatval($r[2] ?? 0),
        'paid' => floatval($r[3] ?? 0),
        'balance' => floatval($r[4] ?? 0),
    ];
} catch (PDOException $e) {}

// ── Pending / total lot counts ──
$pending_lot_count = 0;
$total_lot_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatch_lots WHERE vendor_id = ? AND lot_status IN ('open','partial_return')");
    $stmt->execute([$vendor_id]);
    $pending_lot_count = intval($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatch_lots WHERE vendor_id = ?");
    $stmt->execute([$vendor_id]);
    $total_lot_count = intval($stmt->fetchColumn());
} catch (PDOException $e) {}

// ── Unpaid lots for quick-pay list ──
$unpaid_lots_quick = [];
try {
    $stmt = $pdo->prepare("SELECT id, lot_number, dispatch_date, final_total, total_paid, (final_total - total_paid) AS remaining, payment_status FROM dispatch_lots WHERE vendor_id = ? AND payment_status != 'paid' ORDER BY dispatch_date ASC");
    $stmt->execute([$vendor_id]);
    $unpaid_lots_quick = $stmt->fetchAll();
} catch (PDOException $e) {}

// ── Recent vendor activity log ──
$activity_data = getVendorActivityLog($pdo, $vendor_id, 20, 0, $_GET['act_filter'] ?? 'all');

// ── Advance origin (where prepaid credit came from) ──
$advance_origin = [];
try {
    $stmt = $pdo->prepare("SELECT id, transaction_date, credit, remarks, reference_type, reference_id, created_by FROM vendor_partner_ledger WHERE entity_type='vendor' AND entity_id=? AND transaction_type='advance' ORDER BY id DESC LIMIT 5");
    $stmt->execute([$vendor_id]);
    $advance_origin = $stmt->fetchAll();
} catch (PDOException $e) {}

// ── Advance utilization (where prepaid credit was consumed) ──
$advance_used = [];
try {
    $stmt = $pdo->prepare("SELECT vpl.id, vpl.transaction_date, vpl.debit, vpl.remarks, vpl.reference_id, COALESCE(dl.lot_number, '') AS lot_number FROM vendor_partner_ledger vpl LEFT JOIN dispatch_lots dl ON vpl.reference_id = dl.id WHERE vpl.entity_type='vendor' AND vpl.entity_id=? AND vpl.transaction_type='advance_utilized' ORDER BY vpl.id DESC LIMIT 5");
    $stmt->execute([$vendor_id]);
    $advance_used = $stmt->fetchAll();
} catch (PDOException $e) {}
?>
<link rel="stylesheet" href="vendor.css">

<div class="vendor-profile-header">
    <div class="page-header-no-mb">
        <div class="vendor-profile-info" style="flex:1;">
            <h2>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                <?php echo htmlspecialchars($vendor_data['name']); ?>
            </h2>
            <p><?php echo htmlspecialchars($vendor_data['contact_person'] ?: ''); ?> &middot; <?php echo htmlspecialchars($vendor_data['mobile']); ?><?php if (!empty($vendor_data['email'])): ?> &middot; <?php echo htmlspecialchars($vendor_data['email']); ?><?php endif; ?><?php if (!empty($vendor_data['gst_number'])): ?> &middot; GST: <?php echo htmlspecialchars($vendor_data['gst_number']); ?><?php endif; ?> &middot; <?php echo htmlspecialchars($vendor_data['address'] ?: ''); ?></p>
        </div>
        <div class="vendor-profile-actions">
            <a href="partner-transaction-create.php?mode=return&vendor_id=<?php echo $vendor_id; ?>&redirect_to=<?= urlencode('vendor-profile.php?id=' . $vendor_id) ?>" class="btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 17 20 12 15 7"/><path d="M4 7v6a4 4 0 0 0 4 4h12"/></svg>
                Return Cylinders
            </a>
            <a href="partner-transaction-create.php?mode=borrow&vendor_id=<?php echo $vendor_id; ?>&redirect_to=<?= urlencode('vendor-profile.php?id=' . $vendor_id) ?>" class="btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 17 4 12 9 7"/><path d="M20 17v-6a4 4 0 0 0-4-4H4"/></svg>
                Borrow More
            </a>
            <a href="vendors.php" class="btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner"><span><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></span><button class="modal-close" onclick="this.parentElement.remove()"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner bg-danger-soft text-danger border-danger"><span><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></span><button class="modal-close" onclick="this.parentElement.remove()"></button></div>
<?php endif; ?>

<div class="kpi-bar">
    <div class="kpi-bar-item">
        <span class="kpi-bar-dot dot-amber"></span>
        <span class="kpi-bar-value text-amber"><?php echo $vendor_data['active_refill_count']; ?></span>
        <span class="kpi-bar-label">At Vendor</span>
        <span class="kpi-bar-sub"><?php echo $dispatched_count; ?> dispatched</span>
    </div>
    <div class="kpi-bar-divider"></div>
    <div class="kpi-bar-item">
        <span class="kpi-bar-dot dot-blue"></span>
        <span class="kpi-bar-value text-blue"><?php echo $pending_lot_count; ?></span>
        <span class="kpi-bar-label">Pending Lots</span>
        <span class="kpi-bar-sub">awaiting</span>
    </div>
    <div class="kpi-bar-divider"></div>
    <div class="kpi-bar-item">
        <span class="kpi-bar-dot dot-purple"></span>
        <span class="kpi-bar-value text-purple"><?php echo count($borrowed_cylinders); ?></span>
        <span class="kpi-bar-label">Borrowed</span>
        <span class="kpi-bar-sub">cylinders</span>
    </div>
    <div class="kpi-bar-divider"></div>
    <div class="kpi-bar-item">
        <span class="kpi-bar-dot dot-green"></span>
        <span class="kpi-bar-value text-green"><?php echo $total_lot_count; ?></span>
        <span class="kpi-bar-label">Total Lots</span>
        <span class="kpi-bar-sub">all time</span>
    </div>
    <div class="kpi-bar-divider"></div>
    <div class="kpi-bar-item kpi-bar-gst">
        <span class="kpi-bar-dot dot-slate"></span>
        <span class="kpi-bar-value text-slate">₹<?= number_format($gst_input_total, 0) ?></span>
        <span class="kpi-bar-label">ITC Claimed</span>
        <span class="kpi-bar-sub"><?= $gst_input_count ?> entries</span>
    </div>
</div>

<!-- ═══ Tab Navigation ═══ -->
<div class="profile-tabs" role="tablist">
    <button class="profile-tab active" data-tab="finance" onclick="switchProfileTab('finance')" role="tab" aria-selected="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        <span class="tab-label-full">Finance &amp; Payments</span>
        <span class="tab-label-short">Finance</span>
    </button>
    <button class="profile-tab" data-tab="cylinders" onclick="switchProfileTab('cylinders')" role="tab" aria-selected="false">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
        <span class="tab-label-full">Cylinder Movement</span>
        <span class="tab-label-short">Cylinders</span>
    </button>
    <button class="profile-tab" data-tab="details" onclick="switchProfileTab('details')" role="tab" aria-selected="false">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        <span class="tab-label-full">Details &amp; Settings</span>
        <span class="tab-label-short">Details</span>
    </button>
    <button class="profile-tab" data-tab="ledger" onclick="switchProfileTab('ledger')" role="tab" aria-selected="false">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        <span class="tab-label-full">Activity Log</span>
        <span class="tab-label-short">Activity</span>
    </button>
    <button class="profile-tab" data-tab="invoices" onclick="switchProfileTab('invoices')" role="tab" aria-selected="false">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <span class="tab-label-full">Invoices</span>
        <span class="tab-label-short">Invoices</span>
        <span class="tab-badge"><?= $vendor_invoice_totals['count'] ?></span>
    </button>
    <div class="profile-tab-indicator" id="tabIndicator"></div>
</div>

<!-- ═══ Tab 1: Finance & Payments ═══ -->
<div id="tab-finance" class="profile-tab-content active">
<div class="admin-card fin-dashboard">
    <div class="fin-dash-header">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        Finance Overview
        <span class="fin-gst-chip">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            ITC: ₹<?= number_format($gst_input_total, 0) ?> (<?= $gst_input_count ?>)
        </span>
    </div>

    <?php
    $due_val  = floatval($ledger['due_balance'] ?? 0);
    $adv_val  = floatval($ledger['advance_balance'] ?? 0);
    $net      = $due_val - $adv_val;
    $adv_total_credit = 0;
    $adv_total_debit  = 0;
    foreach ($advance_origin as $ao) { $adv_total_credit += floatval($ao['credit']); }
    foreach ($advance_used as $au)   { $adv_total_debit  += floatval($au['debit']); }
    ?>

    <!-- ═══ Compact Traceable Balances ═══ -->
    <div class="fin-trace-grid">
        <div class="fin-trace-card">
            <div class="fin-trace-head">
                <span class="fin-trace-dot dot-red"></span>
                <span class="fin-trace-label">We Owe Vendor</span>
            </div>
            <span class="fin-trace-value text-danger">₹<?= number_format($due_val, 0) ?></span>
            <?php if ($unpaid_lot_count > 0): ?>
            <div class="fin-trace-lines">
                <?php foreach ($unpaid_lots_quick as $ul):
                    $ul_remaining = floatval($ul['remaining'] ?? 0);
                    if ($ul_remaining <= 0) continue;
                ?>
                <span class="fin-trace-line">
                    <?= htmlspecialchars($ul['lot_number']) ?>
                    <span class="fin-trace-amt">₹<?= number_format($ul_remaining, 0) ?></span>
                    <button class="fin-trace-pay" onclick="showLotPayForm(<?= $ul['id'] ?>, <?= $ul_remaining ?>)">Pay</button>
                </span>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="fin-trace-lines"><span class="fin-trace-line muted">No dues</span></div>
            <?php endif; ?>
        </div>

        <div class="fin-trace-card">
            <div class="fin-trace-head">
                <span class="fin-trace-dot dot-amber"></span>
                <span class="fin-trace-label">Already Paid</span>
            </div>
            <span class="fin-trace-value text-amber">₹<?= number_format($adv_val, 0) ?></span>
            <div class="fin-trace-lines">
                <?php if ($adv_total_credit > 0): ?>
                <span class="fin-trace-line up">↑ ₹<?= number_format($adv_total_credit, 0) ?> paid extra (<?= count($advance_origin) ?> time<?= count($advance_origin) !== 1 ? 's' : '' ?>)</span>
                <?php endif; ?>
                <?php if ($adv_total_debit > 0): ?>
                <span class="fin-trace-line down">↓ ₹<?= number_format($adv_total_debit, 0) ?> used for refills</span>
                <?php endif; ?>
                <span class="fin-trace-line strong">= ₹<?= number_format($adv_val, 0) ?> — will auto-adjust from next bill</span>
            </div>
        </div>

        <div class="fin-trace-card <?= $net > 0 ? 'card-danger' : ($net < 0 ? 'card-success' : '') ?>">
            <div class="fin-trace-head">
                <span class="fin-trace-dot <?= $net > 0 ? 'dot-red' : ($net < 0 ? 'dot-green' : 'dot-slate') ?>"></span>
                <span class="fin-trace-label">Balance to Pay</span>
            </div>
            <span class="fin-trace-value <?= $net > 0 ? 'text-danger' : ($net < 0 ? 'text-success' : 'text-muted') ?>">
                ₹<?= number_format(abs($net), 0) ?>
            </span>
            <div class="fin-trace-lines">
                <span class="fin-trace-line strong"><?= $net > 0 ? 'We owe vendor after adjusting already paid' : ($net < 0 ? 'Vendor has our extra payment' : 'All settled') ?></span>
            </div>
        </div>
    </div>

    <!-- ═══ Inline Payment Form (hidden by default) ═══ -->
    <div class="inline-pay-wrap" id="inlinePayWrap" style="display:none;">
        <form method="POST" action="vendor-profile.php?id=<?= $vendor_id ?>" onsubmit="return validateInlinePayForm()">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="inlinePayAction" value="vendor_settle">
            <input type="hidden" name="direction" id="inlinePayDirection" value="pay">
            <input type="hidden" name="lot_id" id="inlinePayLotId" value="0">
            <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">

            <div class="inline-pay-header">
                <span id="inlinePayTitle">Record Payment</span>
                <button type="button" class="modal-close-x" onclick="hideInlinePayForm()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="inline-pay-body">
                <div class="inline-pay-summary" id="inlinePaySummary">
                    <span>Vendor: <strong><?= htmlspecialchars($vendor_data['name']) ?></strong></span>
                    <span id="inlinePayContext"></span>
                </div>

                <div class="inline-pay-fields">
                    <div class="ip-field">
                        <label>Amount (₹) <span class="required-star">*</span></label>
                        <input type="number" name="amount" id="ipAmount" class="settle-form-input" step="0.01" min="1" required placeholder="Enter amount">
                    </div>
                    <div class="ip-field">
                        <label>Method <span class="required-star">*</span></label>
                        <select name="payment_method" id="ipMethod" class="settle-form-select" required>
                            <option value="">— Select —</option>
                            <option value="Cash">Cash</option>
                            <option value="UPI">UPI</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="NEFT">NEFT</option>
                            <option value="RTGS">RTGS</option>
                            <option value="Online Transfer">Online Transfer</option>
                            <option value="Adjustment">Adjustment</option>
                        </select>
                    </div>
                    <div class="ip-field">
                        <label>Date <span class="required-star">*</span></label>
                        <input type="date" name="payment_date" class="settle-form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="ip-field ip-actions">
                        <button type="submit" class="btn-primary" id="inlinePaySubmit" style="display:inline-flex;align-items:center;gap:0.4rem;border:none;cursor:pointer;padding:0.55rem 1.25rem;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <span id="inlinePaySubmitText">Record Payment</span>
                        </button>
                        <button type="button" class="btn-secondary" onclick="hideInlinePayForm()" style="padding:0.55rem 1rem;">Cancel</button>
                    </div>
                </div>

                <div class="inline-pay-extra" id="inlinePayExtra" style="display:none;">
                    <div class="inline-pay-toggle" onclick="togglePayExtra()">+ Reference &amp; Notes</div>
                    <div class="inline-pay-extra-fields" style="display:none;margin-top:0.5rem;">
                        <div class="ip-field">
                            <label>Reference / Cheque No.</label>
                            <input type="text" name="reference" id="ipRef" class="settle-form-input" placeholder="UPI ref / cheque no.">
                        </div>
                        <div class="ip-field">
                            <label>Remarks</label>
                            <textarea name="notes" id="ipNotes" class="settle-form-input" rows="2" placeholder="Optional..." style="resize:vertical;min-height:50px;"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- ═══ Action Buttons ═══ -->
    <div class="fin-action-bar">
        <button onclick="showPaymentForm('pay')" class="fin-action-btn primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 17 4 12 9 7"/><path d="M20 17v-6a4 4 0 0 0-4-4H4"/></svg>
            Pay to Vendor
        </button>
        <?php if ($adv_val > 0): ?>
        <button onclick="showPaymentForm('receive')" class="fin-action-btn secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 17 20 12 15 7"/><path d="M4 17v-6a4 4 0 0 1 4-4h12"/></svg>
            Take Back from Advance
        </button>
        <?php endif; ?>
        <a href="vendor-settlement.php?vendor_id=<?= $vendor_id ?>" class="fin-action-btn tertiary">
            ⚙ Full Settlement &rarr;
        </a>
    </div>
</div>
</div>

<!-- ═══ Tab 2: Cylinder Movement ═══ -->
<div id="tab-cylinders" class="profile-tab-content">
<div class="admin-card card-no-pad">
    <div class="section-divider">
        <div class="section-divider-accent"></div>
        <h4>Borrowed Cylinders (<?= count($borrowed_cylinders) ?>)</h4>
        <a href="partner-transaction-create.php?mode=return&vendor_id=<?php echo $vendor_id; ?>&redirect_to=<?= urlencode('vendor-profile.php?id=' . $vendor_id) ?>" class="btn-secondary" style="padding:0.4rem 0.85rem;font-size:0.8rem;border-radius:8px;">Return All</a>
    </div>
    <?php if (empty($borrowed_cylinders)): ?>
    <div class="empty-state-modern">
        <div class="empty-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></div>
        <h4>No borrowed cylinders</h4>
        <p>Use "Borrow More" to record borrowed cylinders from this vendor.</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper table-no-border">
        <table class="admin-table">
            <thead>
                <tr><th>Serial</th><th>Gas</th><th>Size</th><th>Borrow Date</th><th>Rate</th><th>Free Days</th><th>Days Held</th><th>Live Rent</th></tr>
            </thead>
            <tbody>
                <?php $today_dt = new DateTime(); $total_rent = 0; foreach ($borrowed_cylinders as $bc):
                    $bdate_val = $bc['borrow_date'];
                    $bdate = $bdate_val ? new DateTime($bdate_val) : $today_dt;
                    $days_held = max(0, (int)$today_dt->diff($bdate)->days);
                    $free = (int)($bc['free_days'] ?? 0);
                    $chargeable = max(0, $days_held - $free);
                    $rate = (float)($bc['daily_rent_rate'] ?? 0);
                    $live_rent = round($chargeable * $rate, 2);
                    $total_rent += $live_rent;
                ?>
                <tr class="borrowed-row">
                    <td class="col-serial" data-label="Serial"><?php echo htmlspecialchars($bc['serial_number']); ?> <span class="tag-ven">VEN</span></td>
                    <td class="col-gas" data-label="Gas"><?php echo htmlspecialchars($bc['gas_name']); ?></td>
                    <td data-label="Size"><?php echo htmlspecialchars($bc['size_capacity']); ?></td>
                    <td class="borrow-date-cell" data-label="Borrow Date"><?php echo $bdate_val ? date('d-M-Y', strtotime($bdate_val)) : 'N/A'; ?></td>
                    <td class="col-rate" data-label="Rate">₹<?php echo number_format($rate, 2); ?>/day</td>
                    <td class="free-days-cell" data-label="Free Days"><?= $free > 0 ? '<span class="rent-free">' . $free . 'd</span>' : '—' ?></td>
                    <td class="col-days-held" data-label="Days Held">
                        <span class="days-held-cell"><?= $days_held ?> <?= $chargeable > 0 ? '<span class="rent-chargeable">(' . $chargeable . ' chg)</span>' : '<span class="rent-free">(free)</span>' ?></span>
                    </td>
                    <td class="col-live-rent" data-label="Live Rent"><?= $live_rent > 0 ? '₹' . number_format($live_rent, 2) : '<span class="rent-zero">₹0.00</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($total_rent > 0): ?>
            <tfoot>
                <tr class="borrowed-total-row">
                    <td colspan="6"></td>
                    <td style="font-weight:700;font-size:0.78rem;color:var(--admin-muted);text-align:right;">Total Monthly Rent:</td>
                    <td style="font-weight:800;color:#b45309;">₹<?= number_format($total_rent, 2) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="admin-card card-no-pad" style="margin-top:2rem;">
    <div class="section-divider">
        <div class="section-divider-accent" style="background:linear-gradient(180deg,#3b82f6,#2563eb);"></div>
        <h4>Dispatch Lots</h4>
        <div style="display:flex;gap:1rem;font-size:0.78rem;font-weight:600;color:var(--admin-muted);">
            <span><strong style="color:var(--admin-accent);"><?php echo $dispatched_count; ?></strong> at vendor</span>
            <span><strong style="color:#10b981;"><?php echo count($lots); ?></strong> lots</span>
            <a href="lot-dashboard.php?vendor_id=<?php echo $vendor_id; ?>" class="btn-secondary" style="padding:0.25rem 0.75rem;font-size:0.72rem;">View All Lots</a>
        </div>
    </div>
    <?php if (empty($lots)): ?>
    <div class="empty-state-modern">
        <div class="empty-icon"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg></div>
        <h4>No dispatch lots yet</h4>
        <p>Use Send Cylinders to create a dispatch lot. Lot records appear here once created.</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper table-no-border">
        <table class="admin-table">
            <thead>
                <tr><th style="width:28px;"></th><th>Lot #</th><th>Date</th><th>Status</th><th>Progress</th><th style="text-align:right;">Refill Cost</th><th style="text-align:right;">GST</th><th style="text-align:right;">Total</th><th style="text-align:right;">Paid</th><th>Payment</th></tr>
            </thead>
            <tbody>
                <?php foreach ($lots as $lot):
                    $lid = intval($lot['id']);
                    $lot_total = floatval($lot['final_total'] ?? 0);
                    $lot_refill = floatval($lot['final_refill_amount'] ?? 0);
                    $lot_gst = floatval($lot['final_gst_amount'] ?? 0);
                    $lot_paid = floatval($lot['total_paid'] ?? 0);
                    $lot_cyl_count = intval($lot['cylinder_count']);
                    $lot_ret_count = intval($lot['returned_count']);
                    $pct = $lot_cyl_count > 0 ? round($lot_ret_count / $lot_cyl_count * 100) : 0;
                    $ps = $lot['payment_status'];
                    $ls = $lot['lot_status'];
                    $ls_class = $ls === 'completed' ? 'badge-filled' : ($ls === 'partial_return' ? 'badge-rental' : 'badge-empty');
                    $ps_class = $ps === 'paid' ? 'badge-filled' : ($ps === 'partial' ? 'badge-rental' : 'badge-empty');
                ?>
                <tr>
                    <td><button class="batch-expand-btn" onclick="toggleLotDetails(<?= $lid ?>, this)" type="button"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></button></td>
                    <td style="font-weight:700;font-size:0.82rem;"><a href="lot-dashboard.php?search=<?= urlencode($lot['lot_number']) ?>" style="color:var(--admin-accent);"><?= htmlspecialchars($lot['lot_number']) ?></a></td>
                    <td style="white-space:nowrap;font-size:0.82rem;"><?= date('d-M-Y', strtotime($lot['dispatch_date'])) ?></td>
                    <td><span class="badge <?= $ls_class ?>"><?= str_replace('_', ' ', $ls) ?></span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;min-width:60px;">
                                <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 100 ? '#10b981' : ($pct > 0 ? '#f59e0b' : '#94a3b8') ?>;border-radius:3px;transition:width 0.3s;"></div>
                            </div>
                            <span style="font-size:0.72rem;font-weight:600;"><?= $lot_ret_count ?>/<?= $lot_cyl_count ?></span>
                        </div>
                    </td>
                    <td style="text-align:right;font-weight:600;">₹<?= number_format($lot_refill, 2) ?></td>
                    <td style="text-align:right;font-size:0.82rem;">₹<?= number_format($lot_gst, 2) ?></td>
                    <td style="text-align:right;font-weight:700;">₹<?= number_format($lot_total, 2) ?></td>
                    <td style="text-align:right;font-size:0.82rem;">₹<?= number_format($lot_paid, 2) ?></td>
                    <td><span class="badge <?= $ps_class ?>"><?= $ps ?></span></td>
                </tr>
                <tr class="batch-detail-row" id="lotDetail_<?= $lid ?>" style="display:none;">
                    <td colspan="10" style="padding:0;border:none;background:transparent;">
                        <div class="batch-detail-inner"></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ═══ Tab 3: Details & Settings ═══ -->
<div id="tab-details" class="profile-tab-content">
<div class="admin-card">
    <div class="card-header">
        <h3>Vendor Details</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="vpf-form">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="update_vendor_details">

            <div class="vpf-section">
                <div class="vpf-section-head">
                    <div class="vpf-section-accent"></div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>Company Details</span>
                </div>
                <div class="vpf-grid-2">
                    <div class="vpf-field">
                        <label>Company Name <span class="required-star">*</span></label>
                        <input type="text" name="name" required value="<?php echo htmlspecialchars($vendor_data['name']); ?>">
                    </div>
                    <div class="vpf-field">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" value="<?php echo htmlspecialchars($vendor_data['contact_person'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="vpf-section">
                <div class="vpf-section-head">
                    <div class="vpf-section-accent"></div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <span>Contact Information</span>
                </div>
                <div class="vpf-grid-2">
                    <div class="vpf-field">
                        <label>Mobile <span class="required-star">*</span></label>
                        <input type="tel" name="mobile" required value="<?php echo htmlspecialchars($vendor_data['mobile']); ?>">
                    </div>
                    <div class="vpf-field">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($vendor_data['email'] ?? ''); ?>">
                    </div>
                </div>
                <div class="vpf-field">
                    <label>Address</label>
                    <textarea name="address" rows="2"><?php echo htmlspecialchars($vendor_data['address'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="vpf-section">
                <div class="vpf-section-head">
                    <div class="vpf-section-accent"></div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    <span>GST / Tax Information</span>
                </div>
                <div class="vpf-grid-2">
                    <div class="vpf-field">
                        <label>GST Number</label>
                        <input type="text" name="gst_number" value="<?php echo htmlspecialchars($vendor_data['gst_number'] ?? ''); ?>" placeholder="27AAAAA0000A1Z5" maxlength="15" data-format="gst">
                        <span class="vpf-hint">15 characters — state code + PAN + checksum</span>
                    </div>
                    <div class="vpf-field">
                        <label>Registration Type</label>
                        <select name="gst_registration_type">
                            <option value="regular" <?= ($vendor_data['gst_registration_type'] ?? 'regular') === 'regular' ? 'selected' : '' ?>>Regular</option>
                            <option value="composition" <?= ($vendor_data['gst_registration_type'] ?? '') === 'composition' ? 'selected' : '' ?>>Composition</option>
                            <option value="unregistered" <?= ($vendor_data['gst_registration_type'] ?? '') === 'unregistered' ? 'selected' : '' ?>>Unregistered</option>
                        </select>
                    </div>
                    <div class="vpf-field">
                        <label>PAN</label>
                        <input type="text" name="pan" value="<?php echo htmlspecialchars($vendor_data['pan'] ?? ''); ?>" placeholder="AAAAA0000A" maxlength="10" data-format="pan">
                        <span class="vpf-hint">10 characters — 5 letters + 4 digits + 1 letter</span>
                    </div>
                    <div class="vpf-field">
                        <label>TAN</label>
                        <input type="text" name="tan" value="<?php echo htmlspecialchars($vendor_data['tan'] ?? ''); ?>" placeholder="AAAA12345A" maxlength="10" data-format="tan">
                        <span class="vpf-hint">10 characters — 4 letters + 5 digits + 1 letter</span>
                    </div>
                </div>
            </div>

            <div class="vpf-section">
                <div class="vpf-section-head">
                    <div class="vpf-section-accent"></div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span>Location</span>
                </div>
                <div class="vpf-grid-3">
                    <div class="vpf-field">
                        <label>State</label>
                        <select name="state_code">
                            <option value="">— Select State —</option>
                            <?php foreach (gstnStateCodes() as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($vendor_data['state_code'] ?? 0) == $code ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(strtolower($name))) ?> (<?= $code ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vpf-field">
                        <label>City</label>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($vendor_data['city'] ?? ''); ?>" placeholder="Mumbai">
                    </div>
                    <div class="vpf-field">
                        <label>Pincode</label>
                        <input type="text" name="pincode" value="<?php echo htmlspecialchars($vendor_data['pincode'] ?? ''); ?>" placeholder="400001" maxlength="6" data-format="pincode">
                        <span class="vpf-hint">6-digit pincode</span>
                    </div>
                </div>
            </div>

            <div class="vpf-section">
                <div class="vpf-section-head">
                    <div class="vpf-section-accent"></div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    <span>Bank Details</span>
                </div>
                <div class="vpf-grid-2">
                    <div class="vpf-field">
                        <label>Account Holder Name</label>
                        <input type="text" name="bank_account_holder" value="<?php echo htmlspecialchars($vendor_data['bank_account_holder'] ?? ''); ?>">
                    </div>
                    <div class="vpf-field">
                        <label>Account Number</label>
                        <input type="text" name="bank_account_number" value="<?php echo htmlspecialchars($vendor_data['bank_account_number'] ?? ''); ?>">
                    </div>
                    <div class="vpf-field">
                        <label>IFSC Code</label>
                        <input type="text" name="bank_ifsc" value="<?php echo htmlspecialchars($vendor_data['bank_ifsc'] ?? ''); ?>" placeholder="SBIN0123456" maxlength="11" data-format="ifsc">
                        <span class="vpf-hint">11 characters — 4 letters + 7 digits</span>
                    </div>
                    <div class="vpf-field">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" value="<?php echo htmlspecialchars($vendor_data['bank_name'] ?? ''); ?>">
                    </div>
                    <div class="vpf-field">
                        <label>Branch</label>
                        <input type="text" name="bank_branch" value="<?php echo htmlspecialchars($vendor_data['bank_branch'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="vpf-section">
                <div class="vpf-section-head">
                    <div class="vpf-section-accent"></div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <span>Settings</span>
                </div>
                <div class="vpf-grid-2">
                    <div class="vpf-field">
                        <label>Payment Terms (days)</label>
                        <input type="number" name="payment_terms" value="<?php echo intval($vendor_data['payment_terms'] ?? 30); ?>" min="0" max="365">
                        <span class="vpf-hint">Net days before payment is due</span>
                    </div>
                </div>
                <div class="vpf-field">
                    <label>Notes</label>
                    <textarea name="notes" rows="3"><?php echo htmlspecialchars($vendor_data['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn-primary vpf-submit">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Changes
            </button>
        </form>
    </div>
</div>
</div>

<!-- ═══ Tab 4: Activity Log ═══ -->
<div id="tab-ledger" class="profile-tab-content">
<div class="admin-card al-card">
    <div class="al-header">
        <div class="al-header-left">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Activity Log
        </div>
        <div class="al-header-actions">
            <a href="vendor-ledger.php?vendor_id=<?= $vendor_id ?>" class="al-btn al-btn-outline">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Ledger
            </a>
            <?php if ($activity_data['total'] > 20): ?>
            <a href="vendor-activity.php?vendor_id=<?= $vendor_id ?>" class="al-btn al-btn-outline">
                All Activity
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </a>
    <?php endif; ?>
    <div class="al-footer">
        <a href="vendor-activity.php?vendor_id=<?= $vendor_id ?>" class="al-btn al-btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            View All Activity (<?= $activity_data['total'] ?>)
        </a>
        <a href="vendor-ledger.php?vendor_id=<?= $vendor_id ?>" class="al-btn al-btn-ghost">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Financial Ledger
        </a>
    </div>
</div>
</div>

    <div class="al-filters">
        <?php
        $filter_defs = [
            'all' => ['label' => 'All', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'],
            'operations' => ['label' => 'Operations', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>'],
            'financial' => ['label' => 'Payments', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
            'borrow_lend' => ['label' => 'Borrow/Lend', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>'],
        ];
        $current_filter = $_GET['act_filter'] ?? 'all';
        foreach ($filter_defs as $fkey => $fdef):
            $url = 'vendor-profile.php?id=' . $vendor_id . ($fkey !== 'all' ? '&act_filter=' . $fkey : '');
            $is_active = $current_filter === $fkey;
        ?>
        <a href="<?= $url ?>" class="al-filter-chip <?= $is_active ? 'active' : '' ?>">
            <?= $fdef['svg'] ?> <?= $fdef['label'] ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($activity_data['rows'])): ?>
    <div class="al-empty">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        <p>No activity recorded for this filter yet.</p>
    </div>
    <?php else: ?>
    <div class="al-list">
    <?php
    $act_svgs = [
        'dispatch' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>',
        'receive' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 15 21 19 3 19 3 15"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'payment_made' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'advance_settled' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 8 8 12 12 16"/><line x1="16" y1="12" x2="8" y2="12"/></svg>',
        'advance_paid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        'borrow' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20 12v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/></svg>',
        'lend' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 7 12 3 8 7"/><line x1="12" y1="3" x2="12" y2="12"/><path d="M20 12v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/></svg>',
        'return' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
        'adjustment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
        'invoice_created' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'invoice_paid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    ];
    $act_colors = [
        'dispatch' => '#3b82f6', 'receive' => '#10b981', 'payment_made' => '#059669',
        'advance_settled' => '#d97706', 'borrow' => '#7c3aed', 'return' => '#6b7280',
    ];
    $amt_colors = ['payment_made' => '#059669', 'advance_paid' => '#059669', 'advance_settled' => '#d97706'];
    foreach ($activity_data['rows'] as $act):
        $at = $act['activity_type'];
        $svg = $act_svgs[$at] ?? $act_svgs['adjustment'];
        $accent = $act_colors[$at] ?? 'var(--admin-accent)';
        $title = htmlspecialchars($act['title'] ?? '');
        $desc = htmlspecialchars($act['description'] ?? '');
        $amt = floatval($act['amount']);
        $pmt_method = htmlspecialchars($act['payment_method'] ?? '');
        $cyl_count = intval($act['cylinder_count']);
        $lot_no = htmlspecialchars($act['lot_number'] ?? '');
        $inv_no = htmlspecialchars($act['invoice_number'] ?? '');
        $created_by = htmlspecialchars($act['created_by'] ?? '');
        $act_date = date('d-M h:i A', strtotime($act['activity_date']));
    ?>
    <div class="al-item">
        <div class="al-item-icon" style="background:<?= $accent ?>14; color:<?= $accent ?>;"><?= $svg ?></div>
        <div class="al-item-body">
            <div class="al-item-top">
                <div>
                    <span class="al-item-title" style="color:<?= $accent ?>;"><?= $title ?></span>
                    <span class="al-item-date"><?= $act_date ?></span>
                </div>
                <?php if ($amt > 0): ?>
                <span class="al-item-amt" style="color:<?= $amt_colors[$at] ?? 'var(--admin-text)' ?>;">₹<?= number_format($amt, 0) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($desc): ?>
            <p class="al-item-desc"><?= $desc ?></p>
            <?php endif; ?>
            <div class="al-item-tags">
                <?php if ($cyl_count > 0): ?>
                <span class="al-tag al-tag-cyl"><?= $cyl_count ?> cyl</span>
                <?php endif; ?>
                <?php if ($pmt_method): ?>
                <span class="al-tag al-tag-pmt"><?= $pmt_method ?></span>
                <?php endif; ?>
                <?php if ($lot_no): ?>
                <a href="lot-dashboard.php?lot_number=<?= urlencode($lot_no) ?>" class="al-tag al-tag-lot"><?= $lot_no ?></a>
                <?php endif; ?>
                <?php if ($inv_no): ?>
                <span class="al-tag al-tag-inv"><?= $inv_no ?></span>
                <?php endif; ?>
                <?php if ($created_by && $created_by !== 'system'): ?>
                <span class="al-tag al-tag-user"><?= $created_by ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ═══ Tab 4: Vendor Invoices ═══ -->
<div id="tab-invoices" class="profile-tab-content">
<div class="admin-card fin-dashboard">
    <div class="fin-dash-header">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Purchase Invoices
        <a href="vendor-invoices.php?vendor_id=<?= $vendor_id ?>" class="btn-secondary link-btn-sm">All Invoices <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>
        <a href="vendor-invoice-create.php?lot_id=0" class="btn-secondary link-btn-sm" style="margin-left:0.5rem;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New
        </a>
    </div>

    <div class="vpf-invoice-grid">
        <div class="fin-sm-card"><span class="fin-sm-num"><?= $vendor_invoice_totals['count'] ?></span><span class="fin-sm-lbl">Invoices</span></div>
        <div class="fin-sm-card"><span class="fin-sm-num">₹<?= number_format($vendor_invoice_totals['total'], 0) ?></span><span class="fin-sm-lbl">Total Amount</span></div>
        <div class="fin-sm-card"><span class="fin-sm-num">₹<?= number_format($vendor_invoice_totals['gst'], 0) ?></span><span class="fin-sm-lbl">GST (ITC)</span></div>
        <div class="fin-sm-card"><span class="fin-sm-num" style="color:<?= $vendor_invoice_totals['balance'] > 0 ? '#dc2626' : '#059669' ?>;">₹<?= number_format($vendor_invoice_totals['balance'], 0) ?></span><span class="fin-sm-lbl">Balance Due</span></div>
    </div>

    <?php if (empty($vendor_invoices)): ?>
    <div class="fin-unpaid-empty" style="padding:2rem;text-align:center;color:var(--admin-muted);">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.35;margin-bottom:0.75rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <p style="font-size:0.88rem;">No purchase invoices yet.</p>
        <a href="vendor-invoice-create.php" style="display:inline-flex;align-items:center;gap:0.35rem;margin-top:0.75rem;padding:0.4rem 1rem;border-radius:8px;background:var(--admin-accent);color:#fff;text-decoration:none;font-size:0.82rem;font-weight:700;">Generate Invoice</a>
    </div>
    <?php else: ?>
    <div style="padding:0 1.25rem 1rem;">
    <table class="admin-table" style="font-size:0.82rem;">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th style="text-align:right;">Amount</th>
                <th style="text-align:right;">GST</th>
                <th style="text-align:right;">Total</th>
                <th style="text-align:right;">Paid</th>
                <th style="text-align:right;">Balance</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vendor_invoices as $vi): 
                $vs = $vi['payment_status'];
                $vbadge = $vs === 'paid' ? 'badge-filled' : ($vs === 'partial' ? 'badge-partial' : 'badge-empty');
                $vbadgeLabel = $vs === 'paid' ? 'Paid' : ($vs === 'partial' ? 'Partial' : 'Unpaid');
            ?>
            <tr>
                <td><a href="vendor-invoice.php?id=<?= $vi['id'] ?>" style="font-weight:700;color:var(--admin-accent);text-decoration:none;font-family:monospace;"><?= htmlspecialchars($vi['invoice_number']) ?></a></td>
                <td style="font-size:0.78rem;color:var(--admin-muted);"><?= date('d-M-Y', strtotime($vi['invoice_date'])) ?></td>
                <td style="text-align:right;">₹<?= number_format($vi['subtotal'], 0) ?></td>
                <td style="text-align:right;">₹<?= number_format($vi['gst_amount'], 0) ?></td>
                <td style="text-align:right;font-weight:700;">₹<?= number_format($vi['grand_total'], 0) ?></td>
                <td style="text-align:right;color:#059669;">₹<?= number_format($vi['paid_amount'], 0) ?></td>
                <td style="text-align:right;font-weight:700;color:<?= $vi['balance'] > 0 ? '#dc2626' : '#059669' ?>;">₹<?= number_format($vi['balance'], 0) ?></td>
                <td><span class="badge <?= $vbadge ?>"><?= $vbadgeLabel ?></span></td>
                <td><a href="vendor-invoice.php?id=<?= $vi['id'] ?>" class="btn-secondary" style="padding:0.2rem 0.55rem;font-size:0.7rem;border-radius:5px;text-decoration:none;">View</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<style>
.fin-sm-card { background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:10px;padding:0.75rem 1rem;text-align:center;transition:var(--vpf-transition); }
.fin-sm-card:hover { border-color:var(--admin-accent);box-shadow:var(--vpf-shadow-sm); }
.fin-sm-num { font-size:1.2rem;font-weight:800;display:block;line-height:1.15; }
.fin-sm-lbl { font-size:0.62rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:0.04em;margin-top:0.15rem;display:block; }
.tab-badge { display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:var(--admin-accent);color:#fff;font-size:0.6rem;font-weight:800;margin-left:0.35rem; }
</style>

<script>
var profileAdvanceBalance = <?= json_encode($ledger['advance_balance'] ?? 0) ?>;
var profileDueBalance = <?= json_encode($ledger['due_balance'] ?? 0) ?>;

function showPaymentForm(mode) {
    var wrap = document.getElementById('inlinePayWrap');
    if (!wrap) return;
    wrap.style.display = 'block';
    document.getElementById('inlinePayAction').value = 'vendor_settle';
    document.getElementById('inlinePayDirection').value = mode || 'pay';
    document.getElementById('inlinePayLotId').value = '0';

    var title = document.getElementById('inlinePayTitle');
    var ctx = document.getElementById('inlinePayContext');
    var sub = document.getElementById('inlinePaySubmitText');

    if (mode === 'receive') {
        title.textContent = 'Take Back from Advance';
        ctx.textContent = 'Already Paid ₹' + profileAdvanceBalance.toFixed(2) + ' — vendor will return this';
        ctx.style.color = '#059669';
        sub.textContent = 'Take Back';
        if (profileAdvanceBalance > 0) document.getElementById('ipAmount').value = profileAdvanceBalance.toFixed(2);
    } else {
        title.textContent = 'Pay to Vendor';
        ctx.textContent = 'We Owe ₹' + profileDueBalance.toFixed(2) + ' — paying vendor account';
        ctx.style.color = '#dc2626';
        sub.textContent = 'Pay Now';
        if (profileDueBalance > 0) document.getElementById('ipAmount').value = profileDueBalance.toFixed(2);
    }
    document.getElementById('ipAmount').focus();
    wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function showLotPayForm(lotId, amount) {
    var wrap = document.getElementById('inlinePayWrap');
    if (!wrap) return;
    wrap.style.display = 'block';
    document.getElementById('inlinePayAction').value = 'lot_pay';
    document.getElementById('inlinePayDirection').value = 'pay';
    document.getElementById('inlinePayLotId').value = lotId;

    document.getElementById('inlinePayTitle').textContent = 'Pay Lot #' + lotId;
    document.getElementById('inlinePayContext').textContent = 'Lot payment — remaining: ₹' + amount.toFixed(2);
    document.getElementById('inlinePayContext').style.color = '#b45309';
    document.getElementById('inlinePaySubmitText').textContent = 'Pay Lot';
    document.getElementById('ipAmount').value = amount.toFixed(2);
    document.getElementById('ipAmount').focus();
    wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideInlinePayForm() {
    var wrap = document.getElementById('inlinePayWrap');
    if (wrap) wrap.style.display = 'none';
}

function togglePayExtra() {
    var cont = document.querySelector('.inline-pay-extra-fields');
    var toggle = document.querySelector('.inline-pay-toggle');
    if (!cont) return;
    var vis = cont.style.display !== 'none';
    cont.style.display = vis ? 'none' : 'block';
    if (toggle) toggle.textContent = vis ? '+ Reference & Notes' : '− Hide Reference & Notes';
}

function switchProfileTab(tab) {
    document.querySelectorAll('.profile-tab').forEach(function(t) {
        var isActive = t.dataset.tab === tab;
        t.classList.toggle('active', isActive);
        t.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    document.querySelectorAll('.profile-tab-content').forEach(function(c) {
        c.classList.toggle('active', c.id === 'tab-' + tab);
    });
    var indicator = document.getElementById('tabIndicator');
    var activeTab = document.querySelector('.profile-tab.active');
    if (indicator && activeTab) {
        var tabRect = activeTab.getBoundingClientRect();
        var parentRect = activeTab.parentElement.getBoundingClientRect();
        indicator.style.width = tabRect.width + 'px';
        indicator.style.transform = 'translateX(' + (tabRect.left - parentRect.left) + 'px)';
    }
    try { localStorage.setItem('vendorProfileTab', tab); } catch(e) {}
}

document.addEventListener('DOMContentLoaded', function() {
    var saved = null;
    try { saved = localStorage.getItem('vendorProfileTab'); } catch(e) {}
    if (saved && document.querySelector('.profile-tab[data-tab="' + saved + '"]')) {
        switchProfileTab(saved);
    }
    document.querySelectorAll('.alert-banner').forEach(function(al) {
        setTimeout(function() {
            al.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            al.style.opacity = '0';
            al.style.transform = 'translateY(-8px)';
            setTimeout(function() { if (al.parentElement) al.remove(); }, 400);
        }, 6000);
    });
    var activeTab = document.querySelector('.profile-tab.active');
    var indicator = document.getElementById('tabIndicator');
    if (indicator && activeTab) {
        var tabRect = activeTab.getBoundingClientRect();
        var parentRect = activeTab.parentElement.getBoundingClientRect();
        indicator.style.width = tabRect.width + 'px';
        indicator.style.transform = 'translateX(' + (tabRect.left - parentRect.left) + 'px)';
    }
});

function validateInlinePayForm() {
    var amt = parseFloat(document.getElementById('ipAmount').value);
    if (!amt || amt <= 0) {
        alert('Please enter a valid amount greater than zero.');
        document.getElementById('ipAmount').focus();
        return false;
    }
    var method = document.getElementById('ipMethod').value;
    if (!method) {
        alert('Please select a payment method.');
        document.getElementById('ipMethod').focus();
        return false;
    }
    return true;
}

// ═══ Lot Detail Expand ═══
const lotItemsData = <?= json_encode($lot_items_map) ?>;

function toggleLotDetails(lotId, btn) {
    var row = document.getElementById('lotDetail_' + lotId);
    if (!row) return;
    var isOpen = row.classList.contains('expanded');
    var svg = btn ? btn.querySelector('svg polyline') : null;

    if (isOpen) {
        row.classList.remove('expanded');
        var inner = row.querySelector('.batch-detail-inner');
        if (inner) { inner.style.maxHeight = '0'; inner.style.opacity = '0'; }
        setTimeout(function() { row.style.display = 'none'; }, 200);
        if (svg) svg.setAttribute('points', '6 9 12 15 18 9');
        if (btn) btn.classList.remove('is-expanded');
    } else {
        var inner = row.querySelector('.batch-detail-inner');
        if (inner && !inner.hasChildNodes()) {
            var items = lotItemsData[lotId] || [];
            var html = '';
            if (items.length > 0) {
                html += '<table class="admin-table batch-cyl-table"><thead><tr>' +
                    '<th>Serial #</th><th>Gas</th><th>Size</th><th>Status</th><th>Refill Cost</th><th>GST</th><th>Received</th>' +
                    '</tr></thead><tbody>';
                items.forEach(function(item) {
                    var st = item.dispatch_status === 'received' ? 'Received' : 'Dispatched';
                    var badgeClass = item.dispatch_status === 'received' ? 'badge-filled' : 'badge-empty';
                    var cost = parseFloat(item.refill_cost || 0).toFixed(2);
                    var gst = parseFloat(item.gst_amount || 0).toFixed(2);
                    var rcvDate = item.receive_date ? new Date(item.receive_date).toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'}) : '—';
                    html += '<tr>' +
                        '<td class="col-serial">' + (item.serial_number || '') + '</td>' +
                        '<td>' + (item.gas_name || '') + '</td>' +
                        '<td>' + (item.size_capacity || '') + '</td>' +
                        '<td><span class="badge ' + badgeClass + '">' + st + '</span></td>' +
                        '<td>₹' + cost + '</td>' +
                        '<td>₹' + gst + '</td>' +
                        '<td>' + rcvDate + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table>';
            } else {
                html = '<div class="batch-cyl-empty">No cylinder records for this lot.</div>';
            }
            inner.innerHTML = html;
        }
        row.style.display = 'table-row';
        requestAnimationFrame(function() {
            row.classList.add('expanded');
            if (inner) { inner.style.maxHeight = inner.scrollHeight + 'px'; inner.style.opacity = '1'; }
        });
        if (svg) svg.setAttribute('points', '18 15 12 9 6 15');
        if (btn) btn.classList.add('is-expanded');
    }
}
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
