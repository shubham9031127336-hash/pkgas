<?php
$page_title = 'GST Settlement';
$active_menu = 'gst_settlement';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/business_helper.php';

runGSTMigrations($pdo);

$msg = ''; $err = '';
$brand_key = $_GET['brand'] ?? getBrandConfig()['business_key'];
$month = $_GET['month'] ?? date('Y-m');
$year = $_GET['year'] ?? date('Y');
$month_start = date('Y-m-01', strtotime($month . '-01'));
$month_end = date('Y-m-t', strtotime($month . '-01'));

// ── Reverse Payment (super admin only — must be before data loading) ──
$reversed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reverse_payment') {
    validateCsrfToken();
    if (!has_role('super_admin')) { $err = 'Only super admin can reverse payments.'; }
    else {
        $rid = intval($_POST['settlement_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE gst_settlements SET payment_status='pending', payment_method=NULL, payment_reference=NULL, payment_amount=0.00, payment_date=NULL WHERE id=?")->execute([$rid]);
            $msg = 'Payment reversed. Settlement reopened.';
            $reversed = true;
        } catch (PDOException $e) { $err = 'Reverse failed: ' . $e->getMessage(); }
    }
}

// ── Create Settlement ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_settlement') {
    validateCsrfToken();
    try {
        $result = createGSTSettlement($pdo, $month, $_SESSION['username'] ?? 'admin', $brand_key);
        $msg = 'Settlement created for ' . date('M Y', strtotime($result['month']))
             . '. Net payable: ₹' . number_format($result['net_payable'], 2);
    } catch (Exception $e) {
        $err = 'Settlement failed: ' . $e->getMessage();
    }
}

// ── Record Payment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    validateCsrfToken();
    $id = intval($_POST['settlement_id'] ?? 0);
    $method = trim($_POST['payment_method'] ?? '');
    $amount = floatval($_POST['payment_amount'] ?? 0);
    $date = $_POST['payment_date'] ?? date('Y-m-d H:i:s');
    $ref = trim($_POST['payment_reference'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    if ($id <= 0) { $err = 'Invalid settlement ID.'; }
    elseif ($amount <= 0) { $err = 'Payment amount must be greater than zero.'; }
    elseif (!$method) { $err = 'Select a payment method.'; }
    else {
        try {
            updateGSTSettlementPayment($pdo, $id, 'paid', $method, $amount, $date, $remarks, $ref);
            $msg = 'Payment recorded. UTR/Ref: ' . htmlspecialchars($ref ?: '—') . '. Amount: ₹' . number_format($amount, 2);
        } catch (PDOException $e) {
            $err = 'Database error: ' . $e->getMessage();
        }
    }
}

// ── Data ──
$calc = calculateGSTForPeriod($pdo, $brand_key, $month_start, $month_end);
$settlements = getGSTSettlements($pdo, ['year' => $year, 'business_key' => $brand_key]);
$current_settlement = null;
foreach ($settlements as $s) {
    if (date('Y-m', strtotime($s['settlement_month'])) === $month) {
        $current_settlement = $s; break;
    }
}

// Months with activity but no settlement (last 12 months)
$unsettled = [];
try {
    $us = $pdo->prepare("
        SELECT DISTINCT DATE_FORMAT(o.order_date, '%Y-%m-01') as m FROM refill_orders o
        WHERE o.business_name=? AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          AND (o.include_in_gst_return IS NULL OR o.include_in_gst_return = 1)
          AND DATE_FORMAT(o.order_date, '%Y-%m-01') NOT IN (
            SELECT settlement_month FROM gst_settlements WHERE business_key=?
          )
        UNION
        SELECT DISTINCT DATE_FORMAT(v.received_date, '%Y-%m-01') FROM vendor_refill_batches v
        WHERE v.received_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          AND DATE_FORMAT(v.received_date, '%Y-%m-01') NOT IN (
            SELECT settlement_month FROM gst_settlements WHERE business_key=?
          )
        ORDER BY m DESC
    ");
    $us->execute([$brand_key, $brand_key, $brand_key]);
    $unsettled = $us->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// Return filing status for period
$period_arr = getGSTPeriodForDate($month_start);
$return_status = '—';
try {
    $rs = $pdo->prepare("SELECT MAX(status) FROM gst_returns WHERE business_key=? AND financial_year=? AND gst_period=?");
    $rs->execute([$brand_key, $period_arr['financial_year'], $period_arr['gst_period']]);
    $return_status = $rs->fetchColumn() ?: 'not filed';
} catch (PDOException $e) {}

$brands = loadAllBusinessConfigs();
$brand_label = $brand_key;
foreach (($brands ?: []) as $b) {
    if ($b['business_key'] === $brand_key) { $brand_label = $b['label']; break; }
}

$has_net_payable = $calc['net_payable'] > 0;
$is_paid = $current_settlement && $current_settlement['payment_status'] === 'paid';
$is_pending = $current_settlement && !$is_paid;
?>
<div class="page-header">
    <div class="page-header-title">
        <h2>GST Settlement</h2>
        <p><?php echo htmlspecialchars($brand_label); ?> — net GST payable calculator</p>
    </div>
    <div class="page-header-actions">
        <form method="GET" style="display:flex;gap:0.5rem;align-items:center;">
            <select name="brand" class="form-control" style="width:auto;padding:0.4rem 0.75rem;" onchange="this.form.submit()">
                <?php foreach (($brands ?: []) as $b): ?>
                <option value="<?php echo $b['business_key']; ?>" <?php echo $brand_key === $b['business_key'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="gst-dashboard.php" class="btn-secondary">Dashboard</a>
        <a href="gst-return-center.php" class="btn-secondary">Return Center</a>
    </div>
</div>

<?php if ($msg): ?>
<div class="stl-alert stl-success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span><?php echo htmlspecialchars($msg); ?></span><button class="stl-alert-close" onclick="this.parentElement.remove()">✕</button></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="stl-alert stl-error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><span><?php echo htmlspecialchars($err); ?></span><button class="stl-alert-close" onclick="this.parentElement.remove()">✕</button></div>
<?php endif; ?>

<!-- ═══════ HERO LIABILITY CARD ═══════ -->
<div class="stl-hero">
    <div class="stl-hero-main">
        <div class="stl-hero-label">Net GST Payable — <strong><?php echo date('M Y', strtotime($month_start)); ?></strong></div>
        <div class="stl-hero-amount <?php echo $calc['net_payable'] > 0 ? 'stl-red' : 'stl-green'; ?>">
            ₹<?php echo number_format($calc['net_payable'], 2); ?>
            <?php if ($is_paid): ?><span class="stl-paid-badge">● PAID</span><?php endif; ?>
        </div>
        <div class="stl-hero-sub">
            Due: <strong><?php echo date('d-M-Y', strtotime(getGSTPeriodDueDate($period_arr))); ?></strong>
            &middot; Return: <strong><?php echo strtoupper($return_status); ?></strong>
        </div>
    </div>
    <div class="stl-hero-breakdown">
        <div class="stl-hero-stat">
            <div class="stl-hero-stat-label">Output GST</div>
            <div class="stl-hero-stat-value stl-red">₹<?php echo number_format($calc['outward_gst'], 2); ?></div>
        </div>
        <div class="stl-hero-divider"></div>
        <div class="stl-hero-stat">
            <div class="stl-hero-stat-label">Input GST (ITC)</div>
            <div class="stl-hero-stat-value stl-green">₹<?php echo number_format($calc['itc_total'], 2); ?></div>
        </div>
        <div class="stl-hero-divider"></div>
        <div class="stl-hero-stat">
            <div class="stl-hero-stat-label">ITC Carry Forward</div>
            <div class="stl-hero-stat-value stl-blue">₹<?php echo number_format($calc['itc_carry'], 2); ?></div>
        </div>
        <div class="stl-hero-divider"></div>
        <div class="stl-hero-stat">
            <div class="stl-hero-stat-label">Closing ITC</div>
            <div class="stl-hero-stat-value stl-blue">₹<?php echo number_format($calc['itc_closing'], 2); ?></div>
        </div>
    </div>
</div>

<!-- ═══════ PERIOD BAR ═══════ -->
<div class="stl-period-bar">
    <form method="GET" class="stl-period-form">
        <input type="hidden" name="brand" value="<?php echo htmlspecialchars($brand_key); ?>">
        <input type="month" name="month" value="<?php echo $month; ?>" class="stl-month-input" onchange="this.form.submit()">
    </form>
    <div class="stl-period-status">
        <span>Return: <strong class="<?php echo $return_status === 'filed' ? 'stl-green-text' : 'stl-muted-text'; ?>"><?php echo strtoupper($return_status); ?></strong></span>
        <span>Due: <strong><?php echo date('d-M-Y', strtotime(getGSTPeriodDueDate($period_arr))); ?></strong></span>
    </div>
    <div class="stl-period-actions">
        <a href="gst-return-center.php?brand=<?php echo urlencode($brand_key); ?>&period=<?php echo $period_arr['gst_period']; ?>&fy=<?php echo $period_arr['financial_year']; ?>" class="stl-action-link">View Returns →</a>
    </div>
</div>

<!-- ═══════ RATE-WISE BREAKDOWN TABLE ═══════ -->
<div class="stl-card">
    <div class="stl-card-title">Rate-wise GST Breakdown</div>
    <?php
    $all_rates = array_unique(array_merge(array_keys($calc['outward_by_rate']), array_keys($calc['itc_by_rate'])));
    sort($all_rates);
    if (empty($all_rates)): ?>
    <div class="stl-empty">No GST transactions found for this period. Create orders with GST &gt; 0%.</div>
    <?php else: ?>
    <div class="stl-table-wrap">
        <table class="stl-table">
            <thead><tr>
                <th>Rate</th>
                <th class="num">Outward Taxable</th>
                <th class="num">Output GST</th>
                <th class="num">Input Taxable</th>
                <th class="num">Input GST (ITC)</th>
                <th class="num">Net GST</th>
            </tr></thead>
            <tbody>
                <?php $t_out_tax = 0; $t_out_gst = 0; $t_in_tax = 0; $t_in_gst = 0; $t_net = 0; ?>
                <?php foreach ($all_rates as $r): 
                    $out = $calc['outward_by_rate'][$r] ?? ['taxable' => 0, 'gst' => 0];
                    $inp = $calc['itc_by_rate'][$r] ?? ['taxable' => 0, 'gst' => 0];
                    $net = $out['gst'] - $inp['gst'];
                    $t_out_tax += $out['taxable']; $t_out_gst += $out['gst'];
                    $t_in_tax += $inp['taxable']; $t_in_gst += $inp['gst']; $t_net += $net;
                ?>
                <tr>
                    <td><span class="stl-rate-badge"><?php echo $r; ?>%</span></td>
                    <td class="num">₹<?php echo number_format($out['taxable'], 2); ?></td>
                    <td class="num stl-red-text">₹<?php echo number_format($out['gst'], 2); ?></td>
                    <td class="num">₹<?php echo number_format($inp['taxable'], 2); ?></td>
                    <td class="num stl-green-text">₹<?php echo number_format($inp['gst'], 2); ?></td>
                    <td class="num <?php echo $net > 0 ? 'stl-red-text' : 'stl-green-text'; ?>"><strong>₹<?php echo number_format($net, 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Total</strong></td>
                    <td class="num"><strong>₹<?php echo number_format($t_out_tax, 2); ?></strong></td>
                    <td class="num stl-red-text"><strong>₹<?php echo number_format($t_out_gst, 2); ?></strong></td>
                    <td class="num"><strong>₹<?php echo number_format($t_in_tax, 2); ?></strong></td>
                    <td class="num stl-green-text"><strong>₹<?php echo number_format($t_in_gst, 2); ?></strong></td>
                    <td class="num <?php echo $t_net > 0 ? 'stl-red-text' : 'stl-green-text'; ?>"><strong>₹<?php echo number_format($t_net, 2); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════ ACTION PANEL ═══════ -->
<div class="stl-card">
    <div class="stl-card-title">
        <?php if ($is_paid): ?>
        Payment Recorded ✅
        <?php elseif ($is_pending): ?>
        Payment Pending
        <?php else: ?>
        No Settlement Yet
        <?php endif; ?>
    </div>

    <?php if (!$current_settlement): ?>
    <!-- ── Create Settlement ── -->
    <div class="stl-action-layout">
        <div class="stl-action-summary">
            <div class="stl-summary-line">Output GST: <strong>₹<?php echo number_format($calc['outward_gst'], 2); ?></strong></div>
            <div class="stl-summary-line">Input GST: <strong class="stl-green-text">− ₹<?php echo number_format($calc['itc_total'], 2); ?></strong></div>
            <div class="stl-summary-line">ITC Carry Forward: <strong class="stl-blue-text">− ₹<?php echo number_format($calc['itc_carry'], 2); ?></strong></div>
            <div class="stl-summary-total">Cash Payment Needed: <strong class="<?php echo $has_net_payable ? 'stl-red-text' : 'stl-green-text'; ?>">₹<?php echo number_format($calc['net_payable'], 2); ?></strong></div>
        </div>
        <form method="POST" class="stl-action-form">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="create_settlement">
            <input type="hidden" name="brand" value="<?php echo htmlspecialchars($brand_key); ?>">
            <button type="submit" class="stl-btn-primary" <?php echo $calc['net_payable'] <= 0 ? '' : ''; ?>>
                Create Settlement for <?php echo date('M Y', strtotime($month_start)); ?>
            </button>
            <?php if (!$has_net_payable && $calc['itc_closing'] > 0): ?>
            <p class="stl-helper">No cash payment needed. Excess ITC of ₹<?php echo number_format($calc['itc_closing'], 2); ?> will carry forward.</p>
            <?php endif; ?>
        </form>
    </div>

    <?php elseif ($is_pending): ?>
    <!-- ── Record Payment ── -->
    <div class="stl-action-layout">
        <div class="stl-action-summary">
            <div class="stl-summary-line">Net Payable: <strong class="stl-red-text">₹<?php echo number_format($current_settlement['net_payable'], 2); ?></strong></div>
            <div class="stl-summary-line">Output GST: ₹<?php echo number_format($current_settlement['total_output'], 2); ?></div>
            <div class="stl-summary-line">Input GST: ₹<?php echo number_format($current_settlement['total_input'], 2); ?></div>
            <div class="stl-summary-line">ITC Opening: ₹<?php echo number_format($current_settlement['itc_opening'], 2); ?> → Closing: ₹<?php echo number_format($current_settlement['itc_closing'], 2); ?></div>
        </div>
        <form method="POST" class="stl-payment-form" onsubmit="return confirm('Mark this settlement as paid?')">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="settlement_id" value="<?php echo $current_settlement['id']; ?>">
            <div class="stl-pay-grid">
                <div class="stl-pay-field">
                    <label class="stl-pay-label">Amount (₹) <span class="stl-req">*</span></label>
                    <input type="number" name="payment_amount" class="stl-input" step="0.01" min="1" required
                           value="<?php echo number_format($current_settlement['net_payable'], 2); ?>">
                </div>
                <div class="stl-pay-field">
                    <label class="stl-pay-label">Method <span class="stl-req">*</span></label>
                    <select name="payment_method" class="stl-input" required>
                        <option value="">— Select —</option>
                        <option value="NEFT">NEFT</option>
                        <option value="RTGS">RTGS</option>
                        <option value="UPI">UPI</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Cash">Cash</option>
                        <option value="Online Transfer">Online Transfer</option>
                    </select>
                </div>
                <div class="stl-pay-field">
                    <label class="stl-pay-label">UTR / Ref. No.</label>
                    <input type="text" name="payment_reference" class="stl-input" placeholder="e.g. HDFC123456789" maxlength="100">
                </div>
                <div class="stl-pay-field">
                    <label class="stl-pay-label">Payment Date</label>
                    <input type="datetime-local" name="payment_date" class="stl-input" value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
            </div>
            <div class="stl-pay-field" style="margin-top:0.5rem;">
                <label class="stl-pay-label">Remarks (optional)</label>
                <textarea name="remarks" class="stl-input" rows="2" placeholder="Any notes about this payment..."></textarea>
            </div>
            <button type="submit" class="stl-btn-primary stl-btn-pay">✅ Record Payment &amp; Lock Settlement</button>
        </form>
    </div>

    <?php elseif ($is_paid): ?>
    <!-- ── Paid Confirmation ── -->
    <div class="stl-paid-confirm">
        <div class="stl-paid-icon">✅</div>
        <div class="stl-paid-details">
            <div class="stl-paid-amount">₹<?php echo number_format($current_settlement['payment_amount'], 2); ?> paid</div>
            <div class="stl-paid-meta">
                Method: <strong><?php echo htmlspecialchars($current_settlement['payment_method'] ?: '—'); ?></strong>
                &middot; UTR: <strong class="stl-mono"><?php echo htmlspecialchars($current_settlement['payment_reference'] ?: '—'); ?></strong>
                &middot; Date: <strong><?php echo $current_settlement['payment_date'] ? date('d-M-Y H:i', strtotime($current_settlement['payment_date'])) : '—'; ?></strong>
            </div>
            <?php if (has_role('super_admin')): ?>
            <form method="POST" style="margin-top:0.75rem;" onsubmit="return confirm('Reverse this payment? This will reopen the settlement.')">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="reverse_payment">
                <input type="hidden" name="settlement_id" value="<?php echo $current_settlement['id']; ?>">
                <button type="submit" class="stl-btn-danger">Reverse Payment</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════ TWO-COLUMN BOTTOM SECTION ═══════ -->
<div class="stl-bottom-grid">
    <!-- LEFT: Settlement Register -->
    <div class="stl-card">
        <div class="stl-card-title">Settlement Register — <?php echo $year; ?></div>
        <div style="margin-bottom:0.75rem;">
            <form method="GET" style="display:inline-flex;gap:0.5rem;align-items:center;">
                <input type="hidden" name="brand" value="<?php echo htmlspecialchars($brand_key); ?>">
                <select name="year" class="stl-year-select" onchange="this.form.submit()">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
        <?php if (empty($settlements)): ?>
        <div class="stl-empty">No settlements recorded yet.</div>
        <?php else: ?>
        <div class="stl-table-wrap">
            <table class="stl-table stl-table-sm">
                <thead><tr>
                    <th>Month</th><th class="num">Output</th><th class="num">Input</th>
                    <th class="num">Net</th><th class="num">Paid</th><th>Method</th><th>UTR</th><th>Status</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($settlements as $s): 
                        $sp = $s['payment_status'];
                    ?>
                    <tr>
                        <td><strong><?php echo date('M Y', strtotime($s['settlement_month'])); ?></strong></td>
                        <td class="num stl-red-text">₹<?php echo number_format($s['total_output'], 2); ?></td>
                        <td class="num stl-green-text">₹<?php echo number_format($s['total_input'], 2); ?></td>
                        <td class="num"><strong>₹<?php echo number_format($s['net_payable'], 2); ?></strong></td>
                        <td class="num"><?php echo $s['payment_amount'] > 0 ? '₹' . number_format($s['payment_amount'], 2) : '—'; ?></td>
                        <td style="font-size:0.78rem;"><?php echo htmlspecialchars($s['payment_method'] ?: '—'); ?></td>
                        <td class="stl-mono"><?php echo htmlspecialchars($s['payment_reference'] ?: '—'); ?></td>
                        <td>
                            <?php if ($sp === 'paid'): ?>
                            <span class="stl-status-dot stl-status-paid"></span> Paid
                            <?php elseif ($sp === 'partial'): ?>
                            <span class="stl-status-dot stl-status-partial"></span> Partial
                            <?php else: ?>
                            <span class="stl-status-dot stl-status-pending"></span> Pending
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Unsettled Months + Timeline -->
    <div>
        <div class="stl-card" style="margin-bottom:1.25rem;">
            <div class="stl-card-title">Unsettled Months</div>
            <?php if (empty($unsettled)): ?>
            <div class="stl-empty">All months settled.</div>
            <?php else: ?>
            <div class="stl-unsettled-list">
                <?php foreach ($unsettled as $um): ?>
                <a href="?month=<?php echo date('Y-m', strtotime($um)); ?>&brand=<?php echo urlencode($brand_key); ?>" class="stl-unsettled-item">
                    <span><?php echo date('M Y', strtotime($um)); ?></span>
                    <span class="stl-unsettled-arrow">→ Settle</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="stl-card">
            <div class="stl-card-title">Payment Timeline</div>
            <?php
            $payments = array_filter($settlements, fn($s) => $s['payment_status'] === 'paid' && $s['payment_amount'] > 0);
            if (empty($payments)): ?>
            <div class="stl-empty">No payments recorded yet.</div>
            <?php else: ?>
            <div class="stl-timeline">
                <?php foreach ($payments as $p): ?>
                <div class="stl-timeline-item">
                    <div class="stl-timeline-dot"></div>
                    <div class="stl-timeline-content">
                        <div class="stl-timeline-title">
                            <strong>₹<?php echo number_format($p['payment_amount'], 2); ?></strong>
                            via <?php echo htmlspecialchars($p['payment_method'] ?: '—'); ?>
                        </div>
                        <div class="stl-timeline-meta">
                            <?php echo date('M Y', strtotime($p['settlement_month'])); ?> period
                            &middot; <?php echo $p['payment_date'] ? date('d-M-Y', strtotime($p['payment_date'])) : '—'; ?>
                            <?php if ($p['payment_reference']): ?>
                            &middot; Ref: <span class="stl-mono"><?php echo htmlspecialchars($p['payment_reference']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* ── Alert ── */
.stl-alert { display:flex; align-items:flex-start; gap:10px; padding:12px 16px; border-radius:12px; font-size:0.82rem; margin-bottom:1rem; border:1px solid; }
.stl-alert svg { width:18px; height:18px; flex-shrink:0; margin-top:1px; }
.stl-alert span { flex:1; }
.stl-alert-close { background:none; border:none; font-size:1rem; cursor:pointer; padding:0 0 0 8px; opacity:0.6; }
.stl-alert-close:hover { opacity:1; }
.stl-success { background:#f0fdf4; border-color:#bbf7d0; color:#166534; }
.stl-error { background:#fef2f2; border-color:#fecaca; color:#dc2626; }

/* ── Hero Card ── */
.stl-hero { background:linear-gradient(135deg,#1e293b,#334155); border-radius:20px; padding:1.5rem; margin-bottom:1.25rem; color:#fff; }
.stl-hero-main { text-align:center; margin-bottom:1rem; }
.stl-hero-label { font-size:0.82rem; color:#94a3b8; margin-bottom:4px; }
.stl-hero-amount { font-size:2.6rem; font-weight:900; line-height:1.1; letter-spacing:-0.02em; display:flex; align-items:center; justify-content:center; gap:1rem; }
.stl-hero-sub { font-size:0.78rem; color:#94a3b8; margin-top:6px; }
.stl-hero-sub strong { color:#e2e8f0; }
.stl-red { color:#f87171; }
.stl-green { color:#34d399; }
.stl-blue { color:#60a5fa; }
.stl-paid-badge { font-size:0.65rem; background:#059669; color:#fff; padding:2px 10px; border-radius:20px; font-weight:700; letter-spacing:0.5px; }
.stl-hero-breakdown { display:flex; justify-content:center; gap:1rem; flex-wrap:wrap; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.1); }
.stl-hero-stat { text-align:center; min-width:120px; }
.stl-hero-stat-label { font-size:0.68rem; color:#94a3b8; font-weight:600; }
.stl-hero-stat-value { font-size:1rem; font-weight:800; }
.stl-hero-divider { width:1px; background:rgba(255,255,255,0.15); }

/* ── Period Bar ── */
.stl-period-bar { display:flex; align-items:center; gap:1rem; margin-bottom:1.25rem; padding:0.75rem 1rem; background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:12px; flex-wrap:wrap; }
.stl-month-input { padding:0.4rem 0.65rem; border-radius:8px; border:1px solid var(--admin-border); font-size:0.85rem; font-family:inherit; }
.stl-period-status { display:flex; gap:1.25rem; font-size:0.82rem; color:var(--admin-muted); flex:1; flex-wrap:wrap; }
.stl-period-actions { margin-left:auto; }
.stl-action-link { font-size:0.8rem; font-weight:600; color:var(--admin-accent); text-decoration:none; }
.stl-action-link:hover { text-decoration:underline; }
.stl-green-text { color:#059669; }
.stl-red-text { color:#dc2626; }
.stl-blue-text { color:#2563eb; }
.stl-muted-text { color:var(--admin-muted); }

/* ── Cards ── */
.stl-card { background:var(--admin-surface); border:1px solid var(--admin-border); border-radius:20px; padding:1.25rem; margin-bottom:1.25rem; }
.stl-card-title { font-size:0.95rem; font-weight:800; margin-bottom:1rem; }

/* ── Table ── */
.stl-table-wrap { overflow-x:auto; }
.stl-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
.stl-table th { text-align:left; padding:0.6rem 0.75rem; font-weight:700; font-size:0.72rem; color:var(--admin-muted); border-bottom:2px solid var(--admin-border); text-transform:uppercase; letter-spacing:0.3px; }
.stl-table td { padding:0.55rem 0.75rem; border-bottom:1px solid var(--admin-border); }
.stl-table tfoot td { border-bottom:none; border-top:2px solid var(--admin-border); padding-top:0.75rem; }
.stl-table th.num, .stl-table td.num { text-align:right; }
.stl-table-sm { font-size:0.78rem; }
.stl-table-sm td, .stl-table-sm th { padding:0.4rem 0.6rem; }
.stl-rate-badge { display:inline-block; background:#dbeafe; color:#1e40af; font-weight:700; font-size:0.72rem; padding:2px 8px; border-radius:6px; }
.stl-rate-nil { background:#f1f5f9; color:#64748b; }
.stl-empty { padding:1.5rem 0; text-align:center; color:var(--admin-muted); font-size:0.85rem; }

/* ── Action Panel ── */
.stl-action-layout { display:flex; gap:1.5rem; flex-wrap:wrap; }
.stl-action-summary { flex:1; min-width:220px; }
.stl-summary-line { font-size:0.85rem; padding:3px 0; color:var(--admin-muted); }
.stl-summary-total { font-size:1.05rem; font-weight:800; padding-top:8px; margin-top:6px; border-top:1px dashed var(--admin-border); }
.stl-action-form { display:flex; flex-direction:column; gap:0.5rem; justify-content:center; min-width:180px; }
.stl-helper { font-size:0.75rem; color:var(--admin-muted); margin-top:6px; }
.stl-btn-primary { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0.65rem 1.5rem; border-radius:10px; border:none; background:var(--admin-accent); color:#fff; font-weight:700; font-size:0.85rem; cursor:pointer; transition:all 0.15s; text-decoration:none; }
.stl-btn-primary:hover { background:var(--admin-accent-hover,#1d4ed8); }
.stl-btn-pay { background:#059669; }
.stl-btn-pay:hover { background:#047857; }
.stl-btn-danger { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0.5rem 1rem; border-radius:8px; border:none; background:#dc2626; color:#fff; font-weight:600; font-size:0.78rem; cursor:pointer; }
.stl-btn-danger:hover { background:#b91c1c; }

/* ── Payment Form ── */
.stl-pay-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; }
@media (max-width:600px) { .stl-pay-grid { grid-template-columns:1fr; } }
.stl-pay-field { }
.stl-pay-label { display:block; font-size:0.75rem; font-weight:600; margin-bottom:4px; color:var(--admin-muted); }
.stl-req { color:#dc2626; }
.stl-input { width:100%; padding:0.55rem 0.75rem; border-radius:10px; border:1px solid var(--admin-border); background:var(--admin-bg); font-size:0.85rem; font-family:inherit; box-sizing:border-box; }
.stl-input:focus { outline:none; border-color:var(--admin-accent); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
.stl-payment-form { flex:1; min-width:300px; }

/* ── Paid Confirmation ── */
.stl-paid-confirm { display:flex; gap:1.25rem; align-items:center; padding:1rem; background:#f0fdf4; border-radius:16px; }
.stl-paid-icon { font-size:2.5rem; line-height:1; }
.stl-paid-amount { font-size:1.3rem; font-weight:900; color:#059669; }
.stl-paid-meta { font-size:0.82rem; color:#64748b; margin-top:4px; }
.stl-mono { font-family:monospace; font-size:0.8rem; }

/* ── Two column bottom ── */
.stl-bottom-grid { display:grid; grid-template-columns:1.6fr 1fr; gap:1.25rem; margin-top:0.25rem; }
@media (max-width:992px) { .stl-bottom-grid { grid-template-columns:1fr; } }
.stl-year-select { padding:0.35rem 0.6rem; border-radius:8px; border:1px solid var(--admin-border); font-size:0.82rem; background:var(--admin-bg); }

/* ── Status dots ── */
.stl-status-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:4px; vertical-align:middle; }
.stl-status-paid { background:#059669; }
.stl-status-partial { background:#d97706; }
.stl-status-pending { background:#94a3b8; }

/* ── Unsettled list ── */
.stl-unsettled-list { display:flex; flex-direction:column; gap:0.35rem; max-height:280px; overflow-y:auto; }
.stl-unsettled-item { display:flex; justify-content:space-between; align-items:center; padding:0.5rem 0.75rem; border-radius:8px; background:var(--admin-bg); border:1px solid var(--admin-border); text-decoration:none; color:var(--admin-fg); font-size:0.8rem; transition:all 0.15s; }
.stl-unsettled-item:hover { border-color:var(--admin-accent); background:#f8fafc; }
.stl-unsettled-arrow { color:#dc2626; font-weight:700; font-size:0.75rem; }

/* ── Timeline ── */
.stl-timeline { padding-left:0; }
.stl-timeline-item { display:flex; gap:0.75rem; padding-bottom:1rem; position:relative; }
.stl-timeline-item:last-child { padding-bottom:0; }
.stl-timeline-dot { width:10px; height:10px; border-radius:50%; background:#059669; margin-top:5px; flex-shrink:0; position:relative; }
.stl-timeline-dot::after { content:''; position:absolute; top:14px; left:4px; width:2px; height:calc(100% + 6px); background:#e2e8f0; }
.stl-timeline-item:last-child .stl-timeline-dot::after { display:none; }
.stl-timeline-content { flex:1; }
.stl-timeline-title { font-size:0.85rem; }
.stl-timeline-meta { font-size:0.72rem; color:var(--admin-muted); margin-top:2px; }
</style>

<script>
// Payment form for unsettled months — navigate to settlement
document.querySelectorAll('.stl-unsettled-item').forEach(function(el) {
    el.addEventListener('click', function(e) {
        // Default navigation works; just a UX hint
    });
});
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
