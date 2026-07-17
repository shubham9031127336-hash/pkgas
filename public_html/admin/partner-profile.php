<?php
$page_title = 'Partner Profile';
$active_menu = 'partners';
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';
runPartnerMigrations($pdo);

$partner_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($partner_id <= 0) { echo "<script>window.location.href='partners.php';</script>"; exit(); }

$stmt = $pdo->prepare("SELECT * FROM partners WHERE id = ?");
$stmt->execute([$partner_id]);
$view_partner = $stmt->fetch();
if (!$view_partner) { echo "<script>window.location.href='partners.php';</script>"; exit(); }

$message = $_GET['msg'] ?? '';
$error = $_GET['err'] ?? '';

// ── Outstanding cylinders ──
$stmt = $pdo->prepare("
    SELECT c.*, g.name as gas_name,
           DATEDIFF(CURDATE(), c.borrow_date) as days_held,
           ROUND(DATEDIFF(CURDATE(), c.borrow_date) * c.daily_rent_rate, 2) as live_rent
    FROM cylinders c
    JOIN gas_types g ON c.gas_type_id = g.id
    WHERE c.current_partner_id = ? AND c.status != 'returned_to_partner'
    ORDER BY c.borrow_date ASC, c.serial_number ASC
");
$stmt->execute([$partner_id]);
$outstanding_cylinders = $stmt->fetchAll();

$rent_stats = ['live_accruing' => 0, 'total_accrued' => 0, 'total_paid' => 0, 'total_damage' => 0];
foreach ($outstanding_cylinders as $oc) {
    if ($oc['ownership_type'] === 'partner_owned') {
        $rent_stats['live_accruing'] += floatval($oc['live_rent'] ?? 0);
    }
}

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(pti.rent_accrued), 0) as total_accrued,
           COALESCE(SUM(pti.rent_paid), 0) as total_paid,
           COALESCE(SUM(pti.damage_amount), 0) as total_damage
    FROM partner_transaction_items pti
    JOIN partner_transactions pt ON pti.transaction_id = pt.id
    WHERE pt.partner_id = ? AND pt.transaction_type = 'returned_to_partner'
");
$stmt->execute([$partner_id]);
$fin = $stmt->fetch();
$rent_stats['total_accrued'] = floatval($fin['total_accrued'] ?? 0);
$rent_stats['total_paid']    = floatval($fin['total_paid'] ?? 0);
$rent_stats['total_damage']  = floatval($fin['total_damage'] ?? 0);

// ── Recent transactions ──
$stmt = $pdo->prepare("
    SELECT pt.*, COUNT(pti.id) as cylinder_count,
           COALESCE(SUM(pti.rent_accrued), 0) as tx_rent_accrued,
           COALESCE(SUM(pti.rent_paid), 0) as tx_rent_paid,
           COALESCE(SUM(pti.damage_amount), 0) as tx_damage
    FROM partner_transactions pt
    LEFT JOIN partner_transaction_items pti ON pt.id = pti.transaction_id
    WHERE pt.partner_id = ?
    GROUP BY pt.id
    ORDER BY pt.transaction_date DESC, pt.id DESC
    LIMIT 10
");
$stmt->execute([$partner_id]);
$partner_transactions = $stmt->fetchAll();

// ── Payment handling ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'make_payment') {
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $created_by = $_SESSION['user_name'] ?? 'system';

    if ($amount > 0) {
        try {
            $pdo->beginTransaction();
            require_once __DIR__ . '/inventory-utils.php';
            runVendorPartnerLedgerMigrations($pdo);
            $result = processVendorPartnerPayment($pdo, 'partner', $partner_id, $amount, $payment_method, $created_by, [
                'payment_date' => $payment_date,
                'reference' => $reference,
                'notes' => $notes,
            ]);
            $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$created_by, 'Record Payment - Partner', "Payment of ₹{$amount} for {$view_partner['company_name']}"]);
            $pdo->commit();
            $msg = urlencode("Payment of ₹" . number_format($amount, 2) . " recorded.");
            echo "<script>window.location.href='partner-profile.php?id={$partner_id}&msg={$msg}';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Payment failed: " . $e->getMessage();
        }
    } else {
        $error = "Amount must be greater than 0.";
    }
}

// Ledger balances
$ledger = getVendorPartnerBalances($pdo, 'partner', $partner_id);
?>
<link rel="stylesheet" href="partner.css">

<div class="profile-banner">
    <div class="profile-banner-content">
        <div class="profile-banner-info">
            <div class="badge-banner-big <?php echo $view_partner['status']; ?>">
                <span class="badge-dot-big <?php echo $view_partner['status'] . '-dot'; ?>"></span>
                <?php echo htmlspecialchars($view_partner['status']); ?>
            </div>
            <h1><?php echo htmlspecialchars($view_partner['company_name']); ?></h1>
            <p><?php echo htmlspecialchars($view_partner['notes'] ?: 'No notes added.'); ?></p>
        </div>
        <div class="profile-banner-actions">
            <a href="partner-transaction-create.php?partner_id=<?php echo $partner_id; ?>&redirect_to=<?= urlencode('partner-profile.php?id=' . $partner_id) ?>" class="btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Borrow
            </a>
            <a href="partner-transaction-create.php?mode=return&partner_id=<?php echo $partner_id; ?>&redirect_to=<?= urlencode('partner-profile.php?id=' . $partner_id) ?>" class="btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Return
            </a>
            <a href="partner-transaction-create.php?mode=lend&partner_id=<?php echo $partner_id; ?>&redirect_to=<?= urlencode('partner-profile.php?id=' . $partner_id) ?>" class="btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Lend
            </a>
            <a href="partner-transaction-create.php?mode=receive_back&partner_id=<?php echo $partner_id; ?>&redirect_to=<?= urlencode('partner-profile.php?id=' . $partner_id) ?>" class="btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Receive Back
            </a>
            <a href="partners.php" class="btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner" id="successAlert">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></span>
    <button class="modal-close" onclick="this.parentElement.remove()"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner alert-error">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <span><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></span>
    <button class="modal-close" onclick="this.parentElement.remove()"></button>
</div>
<?php endif; ?>

<?php
$stat_borrowed = 0; $stat_lent = 0;
foreach ($outstanding_cylinders as $oc) {
    if ($oc['ownership_type'] === 'partner_owned') $stat_borrowed++;
    else $stat_lent++;
}
?>
<div class="stat-cards-row">
    <div class="stat-card-item">
        <div class="icon-glow-amber">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </div>
        <div>
            <h4><?= $stat_borrowed ?></h4>
            <p>Borrowed From</p>
        </div>
    </div>
    <div class="stat-card-item">
        <div class="icon-glow-blue">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        </div>
        <div>
            <h4><?= $stat_lent ?></h4>
            <p>Lent To</p>
        </div>
    </div>
    <div class="stat-card-item">
        <div class="icon-glow-amber">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
        <div>
            <h4>&#8377;<?= number_format($rent_stats['live_accruing'], 2) ?></h4>
            <p>Accruing Now</p>
        </div>
    </div>
    <div class="stat-card-item">
        <div class="icon-glow-green">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
            <h4>&#8377;<?= number_format($rent_stats['total_paid'], 2) ?></h4>
            <p>Total Paid</p>
        </div>
    </div>
</div>

<div class="profile-card">
    <div class="profile-card-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Contact Details
    </div>
    <div class="contact-grid">
        <div class="contact-item">
            <div class="icon-glow-amber">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div>
                <span class="contact-label">Contact Person</span>
                <span class="contact-value"><?php echo htmlspecialchars($view_partner['contact_person'] ?: 'N/A'); ?></span>
            </div>
        </div>
        <div class="contact-item">
            <div class="icon-glow-blue">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </div>
            <div>
                <span class="contact-label">Mobile</span>
                <span class="contact-value"><a href="tel:<?php echo htmlspecialchars($view_partner['mobile']); ?>"><?php echo htmlspecialchars($view_partner['mobile']); ?></a></span>
            </div>
        </div>
        <div class="contact-item">
            <div class="icon-glow-green">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </div>
            <div>
                <span class="contact-label">Email</span>
                <span class="contact-value"><?php echo htmlspecialchars($view_partner['email'] ?: 'N/A'); ?></span>
            </div>
        </div>
        <div class="contact-item">
            <div class="icon-glow-purple">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
            </div>
            <div>
                <span class="contact-label">GSTIN</span>
                <span class="contact-value"><code class="gst-code"><?php echo htmlspecialchars($view_partner['gst_number'] ?: 'N/A'); ?></code></span>
            </div>
        </div>
        <div class="contact-item contact-full">
            <div class="icon-glow-blue">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            </div>
            <div>
                <span class="contact-label">Address</span>
                <span class="contact-value"><?php echo nl2br(htmlspecialchars($view_partner['address'] ?: 'N/A')); ?></span>
            </div>
        </div>
    </div>
</div>

<div class="profile-card">
    <div class="profile-card-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Exchange Balance
    </div>
    <div class="exchange-grid">
        <div class="exchange-card borrowed">
            <div class="icon-glow-amber" style="width:46px;height:46px;border-radius:12px">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </div>
            <div>
                <div class="exchange-num"><?= $stat_borrowed ?></div>
                <div class="exchange-label">Borrowed From Partner</div>
            </div>
        </div>
        <div class="exchange-card lent">
            <div class="icon-glow-blue" style="width:46px;height:46px;border-radius:12px">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            </div>
            <div>
                <div class="exchange-num"><?= $stat_lent ?></div>
                <div class="exchange-label">Lent To Partner</div>
            </div>
        </div>
    </div>
    <?php if ($rent_stats['live_accruing'] > 0 || $rent_stats['total_accrued'] > 0 || $rent_stats['total_paid'] > 0 || $rent_stats['total_damage'] > 0): ?>
    <div class="fin-grid">
        <?php if ($rent_stats['live_accruing'] > 0): ?>
        <div class="fin-card fin-card-accruing">
            <span class="fin-lbl">Accruing Now</span>
            <div class="fin-amt">&#8377;<?= number_format($rent_stats['live_accruing'], 2) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($rent_stats['total_accrued'] > 0): ?>
        <div class="fin-card fin-card-accrued">
            <span class="fin-lbl">Past Accrued</span>
            <div class="fin-amt">&#8377;<?= number_format($rent_stats['total_accrued'], 2) ?></div>
        </div>
        <?php endif; ?>
        <div class="fin-card fin-card-paid">
            <span class="fin-lbl">Total Paid</span>
            <div class="fin-amt">&#8377;<?= number_format($rent_stats['total_paid'], 2) ?></div>
        </div>
        <div class="fin-card fin-card-damage">
            <span class="fin-lbl">Damage Charged</span>
            <div class="fin-amt">&#8377;<?= number_format($rent_stats['total_damage'], 2) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Ledger Summary + Payment -->
<div class="profile-card">
    <div class="profile-card-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        Financial Summary
        <a href="partner-ledger.php?partner_id=<?= $partner_id ?>" class="btn-secondary link-btn-sm">View Full Ledger <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>
    </div>
    <div class="fin-grid" style="margin-top:0;">
        <div class="fin-card" style="background:#f8fafc;border-color:#e2e8f0;">
            <span class="fin-lbl">Running Balance</span>
            <div class="fin-amt" style="color:<?= ($ledger['running_balance'] ?? 0) > 0 ? '#059669' : (($ledger['running_balance'] ?? 0) < 0 ? '#dc2626' : '#64748b') ?>;">&#8377;<?= number_format($ledger['running_balance'] ?? 0, 2) ?></div>
        </div>
        <div class="fin-card" style="background:#f0fdf4;border-color:#bbf7d0;">
            <span class="fin-lbl">Advance (We Owe)</span>
            <div class="fin-amt" style="color:#059669;">&#8377;<?= number_format($ledger['advance_balance'] ?? 0, 2) ?></div>
        </div>
        <div class="fin-card" style="background:#fef2f2;border-color:#fecaca;">
            <span class="fin-lbl">Due (They Owe)</span>
            <div class="fin-amt" style="color:#dc2626;">&#8377;<?= number_format($ledger['due_balance'] ?? 0, 2) ?></div>
        </div>
        <div class="fin-card" style="background:#f1f5f9;border-color:#e2e8f0;">
            <span class="fin-lbl">Transactions</span>
            <div class="fin-amt" style="color:#334155;"><?= number_format($ledger['total_transactions'] ?? 0) ?></div>
        </div>
    </div>
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border);">
        <form method="POST" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="make_payment">
            <div style="flex:1;min-width:120px;">
                <label class="form-label" style="font-size:0.75rem;">Amount (₹)</label>
                <input type="number" name="amount" class="form-control" placeholder="0.00" min="0.01" step="0.01" required>
            </div>
            <div style="flex:1;min-width:120px;">
                <label class="form-label" style="font-size:0.75rem;">Method</label>
                <select name="payment_method" class="form-control">
                    <option>Cash</option>
                    <option>Bank Transfer</option>
                    <option>Cheque</option>
                    <option>UPI</option>
                </select>
            </div>
            <div style="flex:1;min-width:120px;">
                <label class="form-label" style="font-size:0.75rem;">Date</label>
                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div style="flex:1;min-width:120px;">
                <label class="form-label" style="font-size:0.75rem;">Reference</label>
                <input type="text" name="reference" class="form-control" placeholder="Ref #">
            </div>
            <div style="flex:2;min-width:140px;">
                <label class="form-label" style="font-size:0.75rem;">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Payment notes...">
            </div>
            <button type="submit" class="btn-primary" style="height:42px;white-space:nowrap;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Record Payment
            </button>
        </form>
    </div>
</div>

<div class="profile-card card-no-pad">
    <div class="card-head">
        <div class="profile-card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            Outstanding Cylinders (<?= count($outstanding_cylinders) ?>)
        </div>
    </div>
    <div class="table-wrapper table-section">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Serial</th>
                    <th>Gas Type</th>
                    <th>Status</th>
                    <th>Borrow Date</th>
                    <th>Days Held</th>
                    <th>Rate/Day</th>
                    <th>Live Rent</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($outstanding_cylinders as $oc):
                    $is_borrowed = $oc['ownership_type'] === 'partner_owned';
                    $days_held   = intval($oc['days_held'] ?? 0);
                    $live_rent   = floatval($oc['live_rent'] ?? 0);
                    $rate        = floatval($oc['daily_rent_rate'] ?? 0);
                    $is_overdue  = $days_held > 30;
                ?>
                <tr class="<?= $is_overdue ? 'row-overdue' : '' ?>">
                    <td data-label="Serial">
                        <span class="cylinder-sn"><?= htmlspecialchars($oc['serial_number']) ?></span>
                        <?php if ($is_borrowed): ?>
                            <span class="ownership-tag br">BR</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Gas"><?= htmlspecialchars($oc['gas_name']) ?> (<?= htmlspecialchars($oc['size_capacity']) ?>)</td>
                    <td data-label="Status">
                        <?php
                        $bc = 'badge-empty'; $st = $oc['status'];
                        if ($oc['status'] === 'lent_to_partner') { $bc = 'badge-with-customer'; $st = 'lent'; }
                        elseif ($oc['status'] === 'borrowed_from_partner') { $bc = 'badge-empty'; $st = 'empty'; }
                        elseif ($oc['status'] === 'filled') $bc = 'badge-filled';
                        elseif ($oc['status'] === 'with_customer') $bc = 'badge-with-customer';
                        ?>
                        <span class="badge <?= $bc ?>"><?= str_replace('_', ' ', $st) ?></span>
                    </td>
                    <td data-label="Borrow Date"><?= $oc['borrow_date'] ? date('M d, Y', strtotime($oc['borrow_date'])) : '<span class="na-text">—</span>' ?></td>
                    <td data-label="Days Held">
                        <?php if ($days_held > 0): ?>
                            <span class="days-badge <?= $is_overdue ? 'days-badge-overdue' : 'days-badge-normal' ?>">
                                <?= $days_held ?>d
                                <?php if ($is_overdue): ?><span class="overdue-tag">Overdue</span><?php endif; ?>
                            </span>
                        <?php else: ?><span class="na-text">—</span><?php endif; ?>
                    </td>
                    <td data-label="Rate"><?= $rate > 0 ? '&#8377;' . number_format($rate, 2) : '<span class="na-text">—</span>' ?></td>
                    <td data-label="Live Rent">
                        <?php if ($live_rent > 0): ?><span class="rent-accruing">&#8377;<?= number_format($live_rent, 2) ?></span>
                        <?php else: ?><span class="na-text">—</span><?php endif; ?>
                    </td>
                    <td data-label="Action" class="text-right">
                        <?php if ($is_borrowed): ?>
                            <a href="partner-transaction-create.php?mode=return&partner_id=<?= $partner_id ?>&redirect_to=<?= urlencode('partner-profile.php?id=' . $partner_id) ?>" class="btn-secondary action-btn">Return</a>
                        <?php else: ?>
                            <a href="partner-transaction-create.php?mode=receive_back&partner_id=<?= $partner_id ?>&redirect_to=<?= urlencode('partner-profile.php?id=' . $partner_id) ?>" class="btn-secondary action-btn">Receive Back</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($outstanding_cylinders)): ?>
                <tr><td colspan="8"><div class="empty-state-box"><p>No outstanding cylinders. Balance is clear.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="profile-card card-no-pad">
    <div class="card-head">
        <div class="profile-card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Recent Transactions
            <a href="partner-transactions.php?partner_id=<?= $partner_id ?>" class="btn-secondary link-btn-sm">View All <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>
        </div>
    </div>
    <div class="table-wrapper table-section">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>TX ID</th>
                    <th>Type</th>
                    <th class="text-center">Cylinders</th>
                    <th>Rent Accrued</th>
                    <th>Rent Paid</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($partner_transactions as $tx):
                    $bc = 'badge-filled';
                    if ($tx['transaction_type'] === 'returned_to_partner') $bc = 'badge-empty';
                    elseif ($tx['transaction_type'] === 'lent_to_partner') $bc = 'badge-with-customer';
                    elseif ($tx['transaction_type'] === 'received_back_from_partner') $bc = 'badge-sent-to-vendor';
                ?>
                <tr>
                    <td data-label="TX ID"><span class="tx-id">#PTX-<?= str_pad($tx['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
                    <td data-label="Type"><span class="badge <?= $bc ?>"><?= str_replace('_', ' ', $tx['transaction_type']) ?></span></td>
                    <td data-label="Cylinders" class="text-center"><?= $tx['cylinder_count'] ?></td>
                    <td data-label="Rent Accrued"><?= $tx['tx_rent_accrued'] > 0 ? '<span class="rent-accruing">&#8377;' . number_format($tx['tx_rent_accrued'], 2) . '</span>' : '<span class="na-text">—</span>' ?></td>
                    <td data-label="Rent Paid"><?= $tx['tx_rent_paid'] > 0 ? '<span class="rent-paid">&#8377;' . number_format($tx['tx_rent_paid'], 2) . '</span>' : '<span class="na-text">—</span>' ?></td>
                    <td data-label="Date"><?= date('M d, Y', strtotime($tx['transaction_date'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($partner_transactions)): ?>
                <tr><td colspan="6"><div class="empty-state-box"><p>No transactions recorded yet.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.alert-banner').forEach(function(al) {
        setTimeout(function() {
            al.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            al.style.opacity = '0';
            al.style.transform = 'translateY(-8px)';
            setTimeout(function() { if (al.parentElement) al.remove(); }, 400);
        }, 6000);
    });
});
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
