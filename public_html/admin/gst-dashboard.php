<?php
$page_title = 'GST Dashboard';
$active_menu = 'gst_dashboard';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/business_helper.php';

runGSTMigrations($pdo);
runGSTReturnMigrations($pdo);

$date_from = $_GET['from'] ?? date('Y-01-01');
$date_to = $_GET['to'] ?? date('Y-m-d');

$summary = getGSTSummary($pdo, ['date_from' => $date_from, 'date_to' => $date_to]);
$monthly = getMonthlyGSTSummary($pdo, 12);

// Total sales and purchases (for dashboard cards)
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM refill_orders WHERE order_date >= ? AND order_date <= ? AND grand_total > 0 AND (include_in_gst_return IS NULL OR include_in_gst_return = 1)");
    $stmt->execute([$date_from, $date_to . ' 23:59:59']);
    $total_sales = floatval($stmt->fetchColumn());
} catch (PDOException $e) { $total_sales = 0; }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_amount),0) FROM vendor_refill_batches WHERE received_date >= ? AND received_date <= ?");
    $stmt->execute([$date_from, $date_to . ' 23:59:59']);
    $total_purchases = floatval($stmt->fetchColumn());
} catch (PDOException $e) { $total_purchases = 0; }

// Settlement summary
$settlements = [];
try {
    $brand_key = $brand_cfg['business_key'] ?? getBrandConfig()['business_key'];
    $stmt = $pdo->prepare("SELECT * FROM gst_settlements WHERE business_key = ? ORDER BY settlement_month DESC LIMIT 6");
    $stmt->execute([$brand_key]);
    $settlements = $stmt->fetchAll();
} catch (PDOException $e) {}

// GST rates for display
$gst_rates = [];
try {
    $stmt = $pdo->query("SELECT * FROM gst_rates WHERE is_active = 1 ORDER BY rate_percent");
    $gst_rates = $stmt->fetchAll();
} catch (PDOException $e) {}

$brand_cfg = getBrandConfig();
?>
<link rel="stylesheet" href="dashboard.css?v=<?php echo filemtime(__DIR__ . '/dashboard.css'); ?>">

<div class="dash">
<div class="dash-grid">

    <!-- Header -->
    <div class="dash-span-4" style="margin-bottom:0;">
        <div class="d-topbar" style="padding:0;">
            <div class="d-topbar-left">
                <h1>GST Dashboard</h1>
                <span class="dash-subtitle">Input Tax Credit, Output GST, and Settlement Overview</span>
            </div>
            <div class="d-topbar-right">
                <form method="GET" class="dash-filter-form">
                    <input type="date" name="from" value="<?php echo $date_from; ?>" class="dash-date-input">
                    <input type="date" name="to" value="<?php echo $date_to; ?>" class="dash-date-input">
                    <button type="submit" class="d-btn-icon dash-btn-primary">Apply</button>
                    <a href="gst-reports.php" class="d-btn-icon">Reports</a>
                </form>
            </div>
        </div>
    </div>

    <!-- KPI: Total Sales -->
    <div class="kpi-card dash-span-1">
        <div class="kpi-bar blue"></div>
        <div class="kpi-body">
            <div class="kpi-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
            <div><div class="kpi-number">₹<?php echo number_format($total_sales, 2); ?></div><div class="kpi-label">Total Sales</div></div>
        </div>
    </div>

    <!-- KPI: Total Purchases -->
    <div class="kpi-card dash-span-1">
        <div class="kpi-bar amber"></div>
        <div class="kpi-body">
            <div class="kpi-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg></div>
            <div><div class="kpi-number">₹<?php echo number_format($total_purchases, 2); ?></div><div class="kpi-label">Total Purchases</div></div>
        </div>
    </div>

    <!-- KPI: Input GST -->
    <div class="kpi-card dash-span-1">
        <div class="kpi-bar green"></div>
        <div class="kpi-body">
            <div class="kpi-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div>
            <div><div class="kpi-number">₹<?php echo number_format($summary['total_input'], 2); ?></div><div class="kpi-label">Input GST (ITC)</div></div>
        </div>
    </div>

    <!-- KPI: Output GST -->
    <div class="kpi-card dash-span-1">
        <div class="kpi-bar purple"></div>
        <div class="kpi-body">
            <div class="kpi-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg></div>
            <div><div class="kpi-number">₹<?php echo number_format($summary['total_output'], 2); ?></div><div class="kpi-label">Output GST</div></div>
        </div>
    </div>

    <!-- KPI: GST Payable (span-2) -->
    <div class="kpi-card dash-span-2">
        <div class="kpi-bar" style="background:linear-gradient(90deg,#dc2626,#ef4444);"></div>
        <div class="kpi-body">
            <div class="kpi-icon" style="background:#dc2626;"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div><div class="kpi-number" style="color:<?php echo $summary['net_payable'] > 0 ? '#dc2626' : '#10b981'; ?>">₹<?php echo number_format($summary['net_payable'], 2); ?></div><div class="kpi-label">GST Payable</div></div>
        </div>
    </div>

    <!-- KPI: Available ITC (span-2) -->
    <div class="kpi-card dash-span-2">
        <div class="kpi-bar green"></div>
        <div class="kpi-body">
            <div class="kpi-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
            <div><div class="kpi-number">₹<?php echo number_format($summary['itc_carry_forward'], 2); ?></div><div class="kpi-label">Available ITC (Carry Forward)</div></div>
        </div>
    </div>

    <!-- GST by Tax Rate (span-3) -->
    <div class="dash-card dash-span-3">
        <div class="dash-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
            GST by Tax Rate
        </div>
        <div class="gst-rate-grid">
            <?php foreach ($gst_rates as $rate): 
                $r = floatval($rate['rate_percent']);
                $inp = $summary['input_by_rate'][$r] ?? 0;
                $out = $summary['output_by_rate'][$r] ?? 0;
            ?>
            <div class="gst-metric-card">
                <div>
                    <strong class="gst-rate-pct"><?php echo $r; ?>% GST</strong>
                    <div class="gst-rate-label"><?php echo htmlspecialchars($rate['label']); ?></div>
                </div>
                <div class="gst-rate-amounts">
                    <div class="gst-rate-input">Input: ₹<?php echo number_format($inp, 2); ?></div>
                    <div class="gst-rate-output">Output: ₹<?php echo number_format($out, 2); ?></div>
                    <div class="gst-rate-net" style="color:<?php echo ($out - $inp) > 0 ? 'var(--d-danger)' : 'var(--d-success)'; ?>;">Net: ₹<?php echo number_format($out - $inp, 2); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Quick Actions (span-1) -->
    <div class="dash-card dash-span-1">
        <div class="dash-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Quick Actions
        </div>
        <div class="dash-quick-actions">
            <a href="gst-register.php?tab=input" class="dash-action-btn dash-action-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>Input GST Register</a>
            <a href="gst-register.php?tab=output" class="dash-action-btn dash-action-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/></svg>Output GST Register</a>
            <a href="gst-settlement.php" class="dash-action-btn dash-action-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>GST Settlement</a>
            <a href="gst-register.php?tab=ledger" class="dash-action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>GST Ledger</a>
            <a href="gst-reports.php" class="dash-action-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>All Reports</a>
        </div>
    </div>

    <!-- Monthly GST Summary (span-4) -->
    <div class="dash-card dash-span-4">
        <div class="dash-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
            Monthly GST Summary
            <span class="dash-stats-hint">Last 12 months</span>
        </div>
        <?php if (empty($monthly)): ?>
        <div class="dash-empty-state">
            <p>No GST transactions recorded yet. Create orders with GST &gt; 0% to see monthly summaries.</p>
        </div>
        <?php else: ?>
        <div class="table-wrapper" style="margin:0;">
            <table class="admin-table">
                <thead>
                    <tr><th>Month</th><th class="num">Input GST</th><th class="num">Output GST</th><th class="num">Net Payable</th><th class="num">ITC</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly as $m): ?>
                    <tr>
                        <td><strong><?php echo $m['month_label']; ?></strong></td>
                        <td class="num gst-input">₹<?php echo number_format($m['input_gst'], 2); ?></td>
                        <td class="num gst-output">₹<?php echo number_format($m['output_gst'], 2); ?></td>
                        <td class="num gst-net" style="color:<?php echo $m['net_payable'] > 0 ? 'var(--d-danger)' : 'var(--d-success)'; ?>;">₹<?php echo number_format($m['net_payable'], 2); ?></td>
                        <td class="num gst-itc">₹<?php echo number_format($m['itc'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent Settlements (span-4) -->
    <div class="dash-card dash-span-4">
        <div class="dash-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Recent Settlements
        </div>
        <?php if (empty($settlements)): ?>
        <div class="dash-empty-state">
            <p>No settlements created yet. <a href="gst-settlement.php">Create Settlement</a></p>
        </div>
        <?php else: ?>
        <div class="table-wrapper" style="margin:0;">
            <table class="admin-table">
                <thead>
                    <tr><th>Month</th><th class="num">Input</th><th class="num">Output</th><th class="num">Net Payable</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($settlements as $s): ?>
                    <tr>
                        <td><strong><?php echo date('M Y', strtotime($s['settlement_month'])); ?></strong></td>
                        <td class="num">₹<?php echo number_format($s['total_input'], 2); ?></td>
                        <td class="num">₹<?php echo number_format($s['total_output'], 2); ?></td>
                        <td class="num gst-net">₹<?php echo number_format($s['net_payable'], 2); ?></td>
                        <td><?php if ($s['payment_status'] === 'paid'): ?><span class="badge badge-filled">Paid</span><?php elseif ($s['payment_status'] === 'partial'): ?><span class="badge" style="background:#fef3c7;color:#92400e;">Partial</span><?php else: ?><span class="badge badge-empty">Pending</span><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>

<style>
.dash-subtitle { font-size:12px; color:var(--d-muted); font-weight:500; max-width:320px; line-height:1.4; }
.dash-filter-form { display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; }
.dash-date-input { border-radius:8px; padding:6px 10px; font-size:11px; font-weight:500; border:1px solid var(--d-border); background:var(--d-surface); color:var(--d-fg); font-family:inherit; }
.dash-btn-primary { background:var(--d-accent) !important; color:#fff !important; border-color:var(--d-accent) !important; }

.gst-rate-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; flex:1; }
.gst-metric-card { background:var(--d-bg); border:1px solid var(--d-border); border-radius:10px; padding:0.75rem 1rem; display:flex; justify-content:space-between; align-items:center; transition:background 0.2s, box-shadow 0.2s; }
.gst-metric-card:hover { background:var(--d-surface); box-shadow:var(--d-shadow); }
.gst-rate-pct { font-size:1rem; }
.gst-rate-label { font-size:0.72rem; color:var(--d-muted); margin-top:2px; }
.gst-rate-amounts { text-align:right; }
.gst-rate-input { font-size:0.78rem; color:var(--d-success); }
.gst-rate-output { font-size:0.78rem; color:var(--d-danger); }
.gst-rate-net { font-weight:700; font-size:0.85rem; margin-top:2px; }

.dash-quick-actions { display:flex; flex-direction:column; gap:0.5rem; flex:1; }
.dash-action-btn { display:flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; border:1px solid var(--d-border); background:var(--d-surface); color:var(--d-muted); font-size:11px; font-weight:600; cursor:pointer; transition:all 0.15s; text-decoration:none; justify-content:center; }
.dash-action-btn svg { width:14px; height:14px; flex-shrink:0; }
.dash-action-btn:hover { border-color:var(--d-accent); color:var(--d-accent); background:#f8fafc; }
.dash-action-blue { background:var(--d-accent) !important; color:#fff !important; border-color:var(--d-accent) !important; }
.dash-action-blue:hover { background:var(--d-accent-hover) !important; }
.dash-action-purple { background:var(--d-purple) !important; color:#fff !important; border-color:var(--d-purple) !important; }
.dash-action-purple:hover { opacity:0.9; }
.dash-action-green { background:var(--d-success) !important; color:#fff !important; border-color:var(--d-success) !important; }
.dash-action-green:hover { opacity:0.9; }

.dash-empty-state { padding:1.5rem; }
.dash-empty-state p { margin:0; font-size:13px; color:var(--d-muted); }
.dash-empty-state a { color:var(--d-accent); font-weight:600; text-decoration:underline; }

.num { text-align:right; }
.gst-input { color:var(--d-success); }
.gst-output { color:var(--d-danger); }
.gst-net { font-weight:700; }
.gst-itc { color:var(--d-success); }

table.admin-table thead th.num { text-align:right; }
</style>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
