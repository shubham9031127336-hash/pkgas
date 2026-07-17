<?php
require_once __DIR__ . '/lang_init.php';
$page_title = 'Vendor Ledger';
$active_menu = 'vendors';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';

runVendorPartnerLedgerMigrations($pdo);
runVendorActivityLogMigration($pdo);

$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : (isset($_POST['vendor_id']) ? intval($_POST['vendor_id']) : 0);
if ($vendor_id <= 0) { echo "<script>window.location.href='vendors.php';</script>"; exit(); }

$stmt = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
$stmt->execute([$vendor_id]);
$vendor = $stmt->fetch();
if (!$vendor) { echo "<script>window.location.href='vendors.php';</script>"; exit(); }

// ── Flash messages ──
$msg = trim($_GET['msg'] ?? '');
$err = trim($_GET['err'] ?? '');

// ── POST: Settlement ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle') {
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
        $err = $direction === 'receive' ? 'Amount received must be greater than zero.' : 'Payment amount must be greater than zero.';
    } elseif (!in_array($payment_method, $valid_methods)) {
        $err = 'Please select a valid payment method.';
    } elseif (!strtotime($payment_date)) {
        $err = 'Please enter a valid date.';
    } else {
        try {
            $pdo->beginTransaction();
            $result = processVendorPartnerPayment($pdo, 'vendor', $vendor_id, $amount_p, $payment_method, $created_by, [
                'payment_date' => $payment_date,
                'reference'    => $reference,
                'notes'        => $notes,
            ], $direction);
            if ($direction === 'receive') {
                $settled = $result['advance_settled'] ?? 0;
                $log = "Advance settlement of \u{20B9}{$amount_p} received from vendor {$vendor['name']}";
                if ($settled > 0) $log .= ". Advance settled: \u{20B9}{$settled}";
                try {
                    $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, 'Advance Settlement - Vendor', ?)");
                    $stmt->execute([$created_by, $log]);
                } catch (PDOException $e) {}
                logVendorActivity($pdo, $vendor_id, 'advance_settled', "Received ₹" . number_format($amount_p, 2) . " back from advance — {$vendor['name']}", "Method: {$payment_method}" . ($reference ? " | Ref: {$reference}" : ""), [
                    'payment_method' => $payment_method,
                    'reference' => $reference,
                    'notes' => $notes,
                    'direction' => 'receive',
                    'vendor_name' => $vendor['name'],
                ], [
                    'amount' => $amount_p,
                    'payment_method' => $payment_method,
                    'created_by' => $created_by,
                ]);
                $pdo->commit();
                $msg = 'Advance settlement of ₹' . number_format($amount_p, 2) . ' recorded for ' . htmlspecialchars($vendor['name']) . '.';
            } else {
                $log = "Payment of \u{20B9}{$amount_p} recorded for vendor {$vendor['name']}";
                if (($result['due_cleared'] ?? 0) > 0) $log .= ". Due cleared: \u{20B9}{$result['due_cleared']}";
                if (($result['advance_created'] ?? 0) > 0) $log .= ". Advance created: \u{20B9}{$result['advance_created']}";
                try {
                    $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
                    $stmt->execute([$created_by, 'Record Payment - Vendor', $log]);
                } catch (PDOException $e) {}
                $due_cleared = floatval($result['due_cleared'] ?? 0);
                $advance_created = floatval($result['advance_created'] ?? 0);
                logVendorActivity($pdo, $vendor_id, 'payment_made', "Paid ₹" . number_format($amount_p, 2) . " to {$vendor['name']}", "Method: {$payment_method}" . ($due_cleared > 0 ? " | Due cleared: ₹" . number_format($due_cleared, 2) : "") . ($advance_created > 0 ? " | Prepaid: ₹" . number_format($advance_created, 2) : "") . ($reference ? " | Ref: {$reference}" : ""), [
                    'payment_method' => $payment_method,
                    'reference' => $reference,
                    'notes' => $notes,
                    'direction' => 'pay',
                    'due_cleared' => $due_cleared,
                    'advance_created' => $advance_created,
                    'vendor_name' => $vendor['name'],
                ], [
                    'amount' => $amount_p,
                    'payment_method' => $payment_method,
                    'created_by' => $created_by,
                ]);
                $pdo->commit();
                $msg = 'Payment of ₹' . number_format($amount_p, 2) . ' recorded successfully for ' . htmlspecialchars($vendor['name']) . '.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Database error: ' . $e->getMessage();
        }
    }
}

// ── Filters & pagination ──
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? '';
$page_idx = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv = exportVendorPartnerLedgerCSV($pdo, 'vendor', $vendor_id, $from, $to);
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=vendor_ledger_' . $vendor_id . '_' . date('Ymd') . '.csv');
        echo $csv; exit();
    }
}

$fin = getVendorPartnerBalances($pdo, 'vendor', $vendor_id);
$filters = ['from' => $from, 'to' => $to];
if (!empty($type)) $filters['type'] = $type;
$ledger = getVendorPartnerLedgerEntries($pdo, 'vendor', $vendor_id, $page_idx, $limit, $filters);

// Collect batch IDs from visible ledger entries
$ledger_batch_ids = [];
foreach (($ledger['rows'] ?? []) as $e) {
    if (($e['reference_type'] ?? '') === 'vendor_refill_batch' && ($e['reference_id'] ?? 0) > 0) {
        $ledger_batch_ids[(int)$e['reference_id']] = (int)$e['reference_id'];
    }
}
$ledgerBatchCylinders = [];
$ledgerBatchMeta = [];
if (!empty($ledger_batch_ids)) {
    $ids = array_values($ledger_batch_ids);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    // Pre-fill metadata from vendor_refill_batches for fallback
    try {
        $stmt = $pdo->prepare("SELECT vrb.id, vrb.gas_type_id, vrb.size_capacity, vrb.quantity, vrb.cost_per_unit, vrb.total_cost, vrb.net_amount, vrb.received_date, vrb.invoice_number, vrb.gst_rate, vrb.taxable_amount, vrb.gst_amount, vrb.cgst, vrb.sgst, g.name AS gas_name FROM vendor_refill_batches vrb JOIN gas_types g ON vrb.gas_type_id = g.id WHERE vrb.id IN ($ph)");
        $stmt->execute($ids);
        while ($r = $stmt->fetch()) {
            $bid = (int)$r['id'];
            $ledgerBatchMeta[$bid] = [
                'gas_name' => $r['gas_name'] ?? '',
                'quantity' => (int)$r['quantity'],
                'size_capacity' => $r['size_capacity'],
                'cost_per_unit' => $r['cost_per_unit'],
                'total_cost' => $r['total_cost'],
                'net_amount' => $r['net_amount'] ?? $r['total_cost'],
                'received_date' => $r['received_date'],
                'invoice_number' => $r['invoice_number'],
                'gst_rate' => floatval($r['gst_rate'] ?? 0),
                'taxable_amount' => floatval($r['taxable_amount'] ?? 0),
                'gst_amount' => floatval($r['gst_amount'] ?? 0),
                'cgst' => floatval($r['cgst'] ?? 0),
                'sgst' => floatval($r['sgst'] ?? 0),
            ];
        }
    } catch (PDOException $e) {}
    // 1. Try batch_items table first
    try {
        $stmt = $pdo->prepare("SELECT bi.*, g.name AS gas_name FROM vendor_refill_batch_items bi JOIN gas_types g ON bi.gas_type_id = g.id WHERE bi.batch_id IN ($ph) ORDER BY bi.batch_id, bi.serial_number");
        $stmt->execute($ids);
        $has_items = [];
        while ($r = $stmt->fetch()) {
            $bid = (int)$r['batch_id'];
            $has_items[$bid] = true;
            $ledgerBatchCylinders[$bid][] = $r;
        }
    } catch (PDOException $e) {}
    // 2. Fallback: query cylinders.last_refill_batch_id
    try {
        $stmt = $pdo->prepare("SELECT c.*, g.name AS gas_name, c.last_refill_batch_id AS batch_id FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.last_refill_batch_id IN ($ph) ORDER BY c.last_refill_batch_id, c.serial_number");
        $stmt->execute($ids);
        while ($r = $stmt->fetch()) {
            $bid = (int)$r['batch_id'];
            if (!isset($has_items[$bid])) {
                $ledgerBatchCylinders[$bid][] = $r;
            }
        }
    } catch (PDOException $e) {}
}

$due_balance = floatval($fin['due_balance'] ?? 0);
$advance_balance = floatval($fin['advance_balance'] ?? 0);
$running_balance = floatval($fin['running_balance'] ?? 0);
$total_tx = intval($fin['total_transactions'] ?? 0);

// Count due-created entries within the current view
$pending_cnt = 0;
foreach (($ledger['rows'] ?? []) as $entry) {
    if ($entry['transaction_type'] === 'due_created' || $entry['transaction_type'] === 'rent_charge') {
        $pending_cnt++;
    }
}

$type_labels = [
    'payment' => 'Payment', 'advance' => 'Advance',
    'due_created' => 'Due Created', 'rent_charge' => 'Rent Charge',
    'current_payment' => 'Payment', 'advance_utilized' => 'Advance Settled',
    'adjustment' => 'Adjustment',
];

// Build filter query string for pagination links
$filter_qs = 'vendor_id=' . $vendor_id . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&type=' . urlencode($type);
?>
<link rel="stylesheet" href="vendor.css">

<?php if ($msg || $err): ?>
<div class="flash-msg <?= $err ? 'flash-error' : 'flash-success' ?>" id="flashMsg">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <?php if ($err): ?><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        <?php else: ?><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        <?php endif; ?>
    </svg>
    <span><?= htmlspecialchars($err ?: $msg) ?></span>
    <button class="flash-close" onclick="document.getElementById('flashMsg').remove()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
</div>
<?php endif; ?>

<!-- ═══ Page Header ═══ -->
<div class="page-header">
    <div class="page-header-title">
        <a href="vendor-profile.php?id=<?= $vendor_id ?>" style="text-decoration:none;color:var(--admin-muted);display:flex;align-items:center;gap:0.5rem;font-weight:700;margin-bottom:0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Back to Profile
        </a>
        <h2 style="margin:0;font-size:1.4rem;">Ledger: <?= htmlspecialchars($vendor['name']) ?></h2>
    </div>
    <div class="page-header-actions">
        <?php if ($due_balance > 0): ?>
        <button class="btn-primary" onclick="openSettleModal(<?= $due_balance ?>, 'pay')" style="display:inline-flex;align-items:center;gap:0.4rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a11 11 0 1 0 0 22 11 11 0 0 0 0-22z"/><polyline points="12 6 12 12 16 14"/></svg>
            Pay ₹<?= number_format($due_balance, 0) ?>
        </button>
        <?php endif; ?>
        <?php if ($advance_balance > 0): ?>
        <button class="btn-secondary" onclick="openSettleModal(<?= $advance_balance ?>, 'receive')" style="display:inline-flex;align-items:center;gap:0.4rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 17 20 12 15 7"/><path d="M4 17v-6a4 4 0 0 1 4-4h12"/></svg>
            Settle Advance: ₹<?= number_format($advance_balance, 0) ?>
        </button>
        <?php endif; ?>
        <a href="vendor-profile.php?id=<?= $vendor_id ?>" class="btn-secondary" style="display:inline-flex;align-items:center;gap:0.4rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Vendor Profile
        </a>
        <a href="?<?= $filter_qs ?>&export=csv" class="btn-secondary" style="display:inline-flex;align-items:center;gap:0.4rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
    </div>
</div>

<!-- ═══ KPI Cards (simplified) ═══ -->
<div class="ledger-kpi-grid">
    <div class="ledger-kpi-card">
        <div class="ledger-kpi-icon red">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
        </div>
        <div>
            <div class="ledger-kpi-value text-red">₹<?= number_format($due_balance, 2) ?></div>
            <div class="ledger-kpi-label">Payable (what we owe)</div>
        </div>
    </div>
    <div class="ledger-kpi-card">
        <div class="ledger-kpi-icon green">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div>
            <div class="ledger-kpi-value text-green">₹<?= number_format($advance_balance, 2) ?></div>
            <div class="ledger-kpi-label">Prepaid (advance given)</div>
        </div>
    </div>
    <div class="ledger-kpi-card">
        <?php $net_bal = $due_balance - $advance_balance; ?>
        <div class="ledger-kpi-icon <?= $net_bal > 0 ? 'amber' : ($net_bal < 0 ? 'blue' : 'gray') ?>">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
        <div>
            <div class="ledger-kpi-value <?= $net_bal > 0 ? 'text-red' : ($net_bal < 0 ? 'text-green' : '') ?>">₹<?= number_format($net_bal, 2) ?></div>
            <div class="ledger-kpi-label">Net Position (payable − prepaid)</div>
        </div>
    </div>
</div>

<!-- ═══ Quick Filter Chips ═══ -->
<div class="quick-filter-chips">
    <a href="?vendor_id=<?= $vendor_id ?>" class="chip <?= $type==='' ? 'chip-active' : '' ?>">All</a>
    <a href="?vendor_id=<?= $vendor_id ?>&type=due_created" class="chip <?= $type==='due_created' ? 'chip-active' : '' ?>">Charges (Dues)</a>
    <a href="?vendor_id=<?= $vendor_id ?>&type=payment" class="chip <?= $type==='payment' ? 'chip-active' : '' ?>">Payments</a>
    <a href="?vendor_id=<?= $vendor_id ?>&type=advance" class="chip <?= $type==='advance' ? 'chip-active' : '' ?>">Advances</a>
    <a href="?vendor_id=<?= $vendor_id ?>&type=adjustment" class="chip <?= $type==='adjustment' ? 'chip-active' : '' ?>">Adjustments</a>
</div>

<!-- ═══ Pending Payable Banner ═══ -->
<?php if ($due_balance > 0): ?>
<div class="pending-banner">
    <div class="pending-banner-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <div class="pending-banner-text">
        <div class="pending-banner-title">₹<?= number_format($due_balance, 2) ?> outstanding payable</div>
        <div class="pending-banner-sub">Amount owed to vendor for refills received — <?= number_format($pending_cnt) ?> charge entries this period</div>
    </div>
    <button class="btn-primary" onclick="openSettleModal(<?= $due_balance ?>, 'pay')">Make Payment</button>
</div>
<?php endif; ?>

<!-- ═══ Filter Bar ═══ -->
<div class="filter-bar">
    <div class="filter-group">
        <label>From</label>
        <input type="date" value="<?= htmlspecialchars($from) ?>" onchange="window.location.href='?vendor_id=<?= $vendor_id ?>&from='+this.value+'&to=<?= urlencode($to) ?>&type=<?= urlencode($type) ?>'">
    </div>
    <div class="filter-group">
        <label>To</label>
        <input type="date" value="<?= htmlspecialchars($to) ?>" onchange="window.location.href='?vendor_id=<?= $vendor_id ?>&from=<?= urlencode($from) ?>&to='+this.value+'&type=<?= urlencode($type) ?>'">
    </div>
    <div class="filter-group">
        <label>Type</label>
        <select onchange="window.location.href='?vendor_id=<?= $vendor_id ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&type='+this.value">
            <option value="">All Transactions</option>
            <option value="payment" <?= $type==='payment'?'selected':'' ?>>Payments</option>
            <option value="due_created" <?= $type==='due_created'?'selected':'' ?>>Charges (Dues)</option>
            <option value="rent_charge" <?= $type==='rent_charge'?'selected':'' ?>>Rent Charges</option>
            <option value="advance" <?= $type==='advance'?'selected':'' ?>>Advance Payments</option>
            <option value="adjustment" <?= $type==='adjustment'?'selected':'' ?>>Adjustments</option>
        </select>
    </div>
</div>

<!-- ═══ Ledger Table (simplified) ═══ -->
<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table ledger-table-simple">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th style="text-align:right;">Amount (₹)</th>
                    <th style="text-align:right;">Balance</th>
                    <th>Status</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledger['rows'])): ?>
                <tr>
                    <td colspan="7" style="text-align:center;color:var(--admin-muted);padding:2.5rem 1rem;">
                        <div style="margin-bottom:0.5rem;opacity:0.4;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </div>
                        No ledger entries found for the selected period.
                    </td>
                </tr>
                <?php else: foreach ($ledger['rows'] as $entry):
                    $is_due = in_array($entry['transaction_type'], ['due_created', 'rent_charge']);
                    $is_credit = $entry['credit'] > 0;
                    $amount = $is_credit ? floatval($entry['credit']) : floatval($entry['debit']);
                    $amount_class = $is_credit ? 'amount-credit' : 'amount-debit';
                    $amount_sign = $is_credit ? '+' : '−';
                    // Build description
                    $desc_parts = [];
                    if ($entry['reference_type'] === 'vendor_refill_batch' && $entry['reference_id']) {
                        $desc_parts[] = 'Batch #' . intval($entry['reference_id']);
                    } elseif ($entry['reference_type'] === 'partner_transaction' && $entry['reference_id']) {
                        $desc_parts[] = 'PTX-' . str_pad(intval($entry['reference_id']),4,'0',STR_PAD_LEFT);
                    } elseif ($entry['reference_type'] === 'vendor_invoice' && $entry['reference_id']) {
                        $inv_stmt = $pdo->prepare("SELECT invoice_number FROM vendor_invoices WHERE id = ?");
                        $inv_stmt->execute([$entry['reference_id']]);
                        $inv_num = $inv_stmt->fetchColumn();
                        $desc_parts[] = 'Inv: ' . ($inv_num ?: '#' . $entry['reference_id']);
                    } elseif ($entry['reference_type']) {
                        $desc_parts[] = $entry['reference_type'];
                    }
                    $remarks_snippet = mb_substr($entry['remarks'] ?? '', 0, 50);
                    if ($remarks_snippet) $desc_parts[] = $remarks_snippet;
                ?>
                <tr class="<?= $is_due && $due_balance > 0 ? 'row-due-created' : '' ?>">
                    <td style="white-space:nowrap;font-size:0.82rem;"><?= date('d-M-Y', strtotime($entry['transaction_date'])) ?></td>
                    <td><span class="badge badge-<?= $is_due ? 'rental' : ($is_credit ? 'filled' : 'empty') ?>"><?= htmlspecialchars($type_labels[$entry['transaction_type']] ?? $entry['transaction_type']) ?></span></td>
                    <td style="font-size:0.82rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($entry['remarks'] ?? '') ?>">
                        <?php if ($entry['reference_type'] === 'vendor_refill_batch' && $entry['reference_id']): ?>
                        <button class="batch-toggle-btn" onclick="toggleLedgerBatch(<?= (int)$entry['reference_id'] ?>, this)" type="button"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></button>
                        <?php elseif ($entry['reference_type'] === 'vendor_invoice' && $entry['reference_id']): ?>
                        <a href="vendor-invoice.php?id=<?= (int)$entry['reference_id'] ?>" style="display:inline-flex;align-items:center;gap:0.25rem;color:var(--admin-accent);text-decoration:none;font-weight:700;font-family:monospace;font-size:0.85rem;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            <?= htmlspecialchars($desc_parts[count($desc_parts)-2] ?? 'Invoice') ?>
                        </a>
                        <?php endif; ?>
                        <span class="desc-text"><?= htmlspecialchars(implode(' — ', $desc_parts) ?: '—') ?></span>
                    </td>
                    <td style="text-align:right;font-weight:700;" class="<?= $amount_class ?>"><?= $amount_sign ?>₹<?= number_format($amount, 2) ?></td>
                    <td style="text-align:right;font-weight:700;">₹<?= number_format($entry['running_balance'], 2) ?></td>
                    <td>
                        <?php $st = $entry['settlement_status'] ?? ''; ?>
                        <?php if ($st === 'settled'): ?><span class="badge badge-filled">Settled</span>
                        <?php elseif ($st === 'partial'): ?><span class="badge badge-rental">Partial</span>
                        <?php elseif ($st === 'pending'): ?><span class="badge badge-empty">Pending</span>
                        <?php else: ?><span class="badge badge-empty">—</span><?php endif; ?>
                        <?php if ($is_due && $due_balance > 0): ?>
                        <button class="settle-btn" onclick="openSettleModal(<?= floatval($entry['credit']) ?>, 'pay')" style="margin-left:4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pay</button>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.8rem;color:var(--admin-muted);"><?= htmlspecialchars($entry['created_by'] ?? '—') ?></td>
                </tr>
                <?php if ($entry['reference_type'] === 'vendor_refill_batch' && $entry['reference_id']): ?>
                <tr class="batch-detail-row" id="ledgerBatchDetail_<?= (int)$entry['reference_id'] ?>" style="display:none;">
                    <td colspan="7" style="padding:0;border:none;background:transparent;">
                        <div class="batch-detail-inner"></div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ Pagination ═══ -->
<?php if (($ledger['pages'] ?? 1) > 1): ?>
<div class="pagination-wrap">
    <div class="pagination-info">
        Showing page <?= $page_idx ?> of <?= $ledger['pages'] ?>
        (<?= number_format($ledger['total']) ?> entries)
    </div>
    <div class="pagination-links">
        <?php for ($i = 1; $i <= $ledger['pages']; $i++): ?>
        <a href="?<?= $filter_qs ?>&page=<?= $i ?>" class="<?= $i === $page_idx ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══ Settlement Modal (dual-direction) ═══ -->
<div class="modal-overlay" id="settleModal">
    <div class="modal-settle">
        <form method="POST" action="vendor-ledger.php" onsubmit="return validateSettleForm()">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="settle">
            <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">
            <input type="hidden" name="direction" id="settleDirection" value="pay">

            <div class="modal-settle-header">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--admin-accent)" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <h3 id="settleModalTitle">Make Payment</h3>
                <button type="button" class="modal-close-x" onclick="closeSettleModal()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-settle-body">
                <!-- Direction indicator (no toggle, set by button) -->
                <div id="settleDirectionIndicator" class="settle-direction-indicator">
                    <span id="settleDirBadge" class="settle-dir-badge dir-badge-pay">Pay to Vendor</span>
                </div>

                <!-- Info strip -->
                <div class="settle-info">
                    <div class="settle-info-item">
                        <span class="settle-info-label">Vendor</span>
                        <span class="settle-info-value"><?= htmlspecialchars($vendor['name']) ?></span>
                    </div>
                    <div class="settle-info-item">
                        <span class="settle-info-label">Outstanding Payable</span>
                        <span class="settle-info-value text-red">₹<?= number_format($due_balance, 2) ?></span>
                    </div>
                    <?php if ($advance_balance > 0): ?>
                    <div class="settle-info-item">
                        <span class="settle-info-label">Prepaid Amount</span>
                        <span class="settle-info-value text-green" id="settleAdvanceDisplay">₹<?= number_format($advance_balance, 2) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Amount -->
                <div class="settle-form-group">
                    <label class="settle-form-label" for="settleAmount" id="settleAmountLabel">Amount to Pay (₹) <span class="required-star">*</span></label>
                    <input type="number" id="settleAmount" name="amount" class="settle-form-input" step="0.01" min="1" required placeholder="Enter amount">
                    <div class="settle-form-hint" id="settleAmountHint">Enter the amount to pay toward outstanding dues.</div>
                </div>

                <!-- Payment Method -->
                <div class="settle-form-group">
                    <label class="settle-form-label" for="settleMethod">Method <span class="required-star">*</span></label>
                    <select id="settleMethod" name="payment_method" class="settle-form-select" required>
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

                <!-- Date -->
                <div class="settle-form-group">
                    <label class="settle-form-label" for="settleDate">Date <span class="required-star">*</span></label>
                    <input type="date" id="settleDate" name="payment_date" class="settle-form-input" value="<?= date('Y-m-d') ?>" required>
                </div>

                <!-- Reference -->
                <div class="settle-form-group">
                    <label class="settle-form-label" for="settleRef" id="settleRefLabel">Reference / Cheque No.</label>
                    <input type="text" id="settleRef" name="reference" class="settle-form-input" placeholder="e.g. UPI ref, cheque number, transaction ID">
                    <div class="settle-form-hint">Optional but recommended for audit trail.</div>
                </div>

                <!-- Notes -->
                <div class="settle-form-group">
                    <label class="settle-form-label" for="settleNotes">Remarks / Notes</label>
                    <textarea id="settleNotes" name="notes" class="settle-form-input" rows="2" placeholder="Optional remarks..." style="resize:vertical;min-height:60px;"></textarea>
                </div>
            </div>

            <div class="modal-settle-footer">
                <button type="button" class="btn-secondary" onclick="closeSettleModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="settleSubmitBtn" style="display:inline-flex;align-items:center;gap:0.4rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="settleSubmitText">Record Payment</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ JavaScript ═══ -->
<script>
var settleAdvanceBalance = <?= json_encode($advance_balance) ?>;
var settleDueBalance = <?= json_encode($due_balance) ?>;

function switchDirection(dir) {
    document.getElementById('settleDirection').value = dir;
    var badge = document.getElementById('settleDirBadge');
    var inp = document.getElementById('settleAmount');
    if (dir === 'receive') {
        document.getElementById('settleModalTitle').textContent = 'Receive from Vendor';
        document.getElementById('settleAmountLabel').textContent = 'Amount Received (₹) *';
        document.getElementById('settleAmountHint').textContent = 'Enter the amount the vendor is returning to settle advance.';
        document.getElementById('settleRefLabel').textContent = 'Receipt / Reference No.';
        document.getElementById('settleSubmitText').textContent = 'Record Receipt';
        badge.textContent = 'Receive from Vendor';
        badge.className = 'settle-dir-badge dir-badge-receive';
        if (settleAdvanceBalance > 0) inp.value = settleAdvanceBalance.toFixed(2);
    } else {
        document.getElementById('settleModalTitle').textContent = 'Make Payment';
        document.getElementById('settleAmountLabel').textContent = 'Amount to Pay (₹) *';
        document.getElementById('settleAmountHint').textContent = 'Enter the amount to pay toward outstanding dues.';
        document.getElementById('settleRefLabel').textContent = 'Reference / Cheque No.';
        document.getElementById('settleSubmitText').textContent = 'Record Payment';
        badge.textContent = 'Pay to Vendor';
        badge.className = 'settle-dir-badge dir-badge-pay';
        if (settleDueBalance > 0) inp.value = settleDueBalance.toFixed(2);
    }
    inp.focus();
}

function openSettleModal(defaultAmount, mode) {
    document.getElementById('settleModal').classList.add('open');
    switchDirection(mode || 'pay');
    var inp = document.getElementById('settleAmount');
    if (defaultAmount && defaultAmount > 0) inp.value = defaultAmount.toFixed(2);
    inp.focus();
}

function closeSettleModal() {
    document.getElementById('settleModal').classList.remove('open');
}

function validateSettleForm() {
    var amt = parseFloat(document.getElementById('settleAmount').value);
    if (!amt || amt <= 0) {
        alert('Please enter a valid amount greater than zero.');
        document.getElementById('settleAmount').focus();
        return false;
    }
    var method = document.getElementById('settleMethod').value;
    if (!method) {
        alert('Please select a payment method.');
        document.getElementById('settleMethod').focus();
        return false;
    }
    var date = document.getElementById('settleDate').value;
    if (!date) {
        alert('Please select a date.');
        document.getElementById('settleDate').focus();
        return false;
    }
    return true;
}

document.getElementById('settleModal').addEventListener('click', function(e) {
    if (e.target === this) closeSettleModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSettleModal();
});

(function() {
    var flash = document.getElementById('flashMsg');
    if (flash) {
        setTimeout(function() {
            flash.style.transition = 'opacity 0.5s';
            flash.style.opacity = '0';
            setTimeout(function() { flash.remove(); }, 600);
        }, 5000);
    }
})();

(function() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('pay') === '1') {
        var dueBal = settleDueBalance;
        openSettleModal(dueBal > 0 ? dueBal : 0, 'pay');
    } else if (params.get('receive') === '1') {
        var advBal = settleAdvanceBalance;
        openSettleModal(advBal > 0 ? advBal : 0, 'receive');
    }
})();

// ═══ Ledger Batch Cylinder Expand ═══
const ledgerBatchCylinders = <?= json_encode($ledgerBatchCylinders) ?>;
const ledgerBatchMeta = <?= json_encode($ledgerBatchMeta) ?>;

function toggleLedgerBatch(batchId, btn) {
    var row = document.getElementById('ledgerBatchDetail_' + batchId);
    if (!row) return;
    var isOpen = row.style.display === 'table-row';
    var svg = btn ? btn.querySelector('svg polyline') : null;

    if (isOpen) {
        row.style.display = 'none';
        if (svg) svg.setAttribute('points', '6 9 12 15 18 9');
    } else {
        var inner = row.querySelector('.batch-detail-inner');
        if (inner && !inner.hasChildNodes()) {
            var items = ledgerBatchCylinders[batchId] || [];
            var meta = ledgerBatchMeta[batchId] || {};
            var html = '';
            if (items.length > 0) {
                html += '<table class="admin-table batch-cyl-table"><thead><tr>' +
                    '<th>Serial #</th><th>Gas</th><th>Size</th><th>Status</th><th>Owner</th><th>Last Refill</th><th>Refill Cost</th>' +
                    '</tr></thead><tbody>';
                items.forEach(function(cyl) {
                    var st = (cyl.status || '').replace(/_/g, ' ');
                    var owner = 'Company';
                    if (cyl.ownership_type === 'partner_owned') owner = 'Partner';
                    else if (cyl.ownership_type === 'consumer_owned') owner = 'Customer';
                    else if (cyl.ownership_type === 'vendor_owned') owner = 'Vendor';
                    var badgeClass = cyl.status === 'filled' ? 'badge-filled' : 'badge-empty';
                    var refillCost = cyl.cost_per_unit > 0 ? parseFloat(cyl.cost_per_unit).toFixed(2) : (cyl.current_refill_cost > 0 ? parseFloat(cyl.current_refill_cost).toFixed(2) : '0.00');
                    html += '<tr>' +
                        '<td class="col-serial">' + cyl.serial_number + '</td>' +
                        '<td>' + (cyl.gas_name || '') + '</td>' +
                        '<td>' + (cyl.size_capacity || meta.size_capacity || '') + '</td>' +
                        '<td><span class="badge ' + badgeClass + '">' + st + '</span></td>' +
                        '<td>' + owner + '</td>' +
                        '<td>' + (cyl.last_refill_date || '—') + '</td>' +
                        '<td>₹' + refillCost + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table>';
            } else if (meta.size_capacity) {
                var netAmt = parseFloat(meta.net_amount || meta.total_cost || 0);
                html = '<div class="batch-meta-fallback">' +
                    '<div class="batch-meta-header">📋 Batch #' + batchId + ' — Historical Record</div>' +
                    '<div class="batch-meta-grid">' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">Gas</span><span class="batch-meta-val">' + (meta.gas_name || '—') + '</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">Size</span><span class="batch-meta-val">' + meta.size_capacity + '</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">Quantity</span><span class="batch-meta-val">' + meta.quantity + '</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">Cost/Unit</span><span class="batch-meta-val">₹' + parseFloat(meta.cost_per_unit || 0).toFixed(2) + '</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">Total</span><span class="batch-meta-val">₹' + netAmt.toFixed(2) + '</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">Invoice</span><span class="batch-meta-val">' + (meta.invoice_number || '—') + '</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">Date</span><span class="batch-meta-val">' + (meta.received_date ? new Date(meta.received_date).toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'}) : '—') + '</span></div>' +
                    (meta.gst_rate > 0 ?
                    '<div class="batch-meta-item"><span class="batch-meta-label">GST Rate</span><span class="batch-meta-val">' + meta.gst_rate + '%</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">Taxable</span><span class="batch-meta-val">₹' + parseFloat(meta.taxable_amount || 0).toFixed(2) + '</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">CGST</span><span class="batch-meta-val">₹' + parseFloat(meta.cgst || 0).toFixed(2) + '</span></div>' +
                    '<div class="batch-meta-item"><span class="batch-meta-label">SGST</span><span class="batch-meta-val">₹' + parseFloat(meta.sgst || 0).toFixed(2) + '</span></div>' +
                    '<div class="batch-meta-item batch-meta-total"><span class="batch-meta-label">Total GST</span><span class="batch-meta-val">₹' + parseFloat(meta.gst_amount || 0).toFixed(2) + '</span></div>' : '') +
                    '</div>' +
                    '<div class="batch-meta-note">Individual cylinder serial numbers are not available for this batch. Newer batches will show full cylinder details automatically.</div>' +
                    '</div>';
            } else {
                html = '<div class="batch-cyl-empty">No records found for this batch.</div>';
            }
            inner.innerHTML = html;
        }
        row.style.display = 'table-row';
        if (svg) svg.setAttribute('points', '18 15 12 9 6 15');
    }
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
