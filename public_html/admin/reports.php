<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('reports.title');
$active_menu = "reports";
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
runRefillCostMigrations($pdo);

// Handle CSV Exports routes directly
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    // Clear output buffers
    if (ob_get_level()) ob_end_clean();
    
    if ($export_type === 'orders') {
        require_role(['super_admin', 'billing_clerk']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=refill_orders_export_' . date('Ymd') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Order ID', 'Customer Name', 'Subtotal', 'Deposit Amount', 'Tax Amount', 'Grand Total', 'Date Placed', 'Payment Method']);
        
        $stmt = $pdo->query("SELECT o.*, c.name FROM refill_orders o JOIN customers c ON o.customer_id = c.id ORDER BY o.id DESC");
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                "ORD-" . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                $row['name'],
                $row['subtotal'],
                $row['deposit_amount'],
                $row['tax_amount'],
                $row['grand_total'],
                $row['order_date'],
                $row['payment_method']
            ]);
        }
        fclose($output);
        exit();
    }
    
    if ($export_type === 'inventory') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=cylinder_stock_export_' . date('Ymd') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Cylinder Serial', 'Gas Type', 'Size', 'Status', 'Last Refill Date', 'Hydrostatic Expiry']);
        
        $stmt = $pdo->query("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id ORDER BY c.serial_number ASC");
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['serial_number'],
                $row['gas_name'],
                $row['size_capacity'],
                strtoupper($row['status']),
                $row['last_refill_date'] ?: 'Never',
                $row['expiry_date'] ?: 'N/A'
            ]);
        }
        fclose($output);
        exit();
    }
    
    if ($export_type === 'customers') {
        require_role(['super_admin', 'billing_clerk']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=customer_ledger_export_' . date('Ymd') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Customer ID', 'Full Name', 'Mobile Number', 'Customer Type', 'Outstanding Rentals', 'Deposit Balance Hold', 'GSTIN']);
        
        $stmt = $pdo->query("SELECT * FROM customers ORDER BY name ASC");
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                "CUST-" . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                $row['name'],
                $row['mobile'],
                strtoupper($row['customer_type']),
                $row['active_cylinders_count'],
                $row['deposit_balance'],
                $row['gst_number'] ?: 'N/A'
            ]);
        }
        fclose($output);
        exit();
    }
}

// Render regular page layout
require_once 'layout.php';

// Safe queries helper
function safeQueryValue($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// ── Period filter state ──
$period_mode = $_GET['period'] ?? 'month';
$period_month = $_GET['month'] ?? date('Y-m');
$period_quarter = intval($_GET['quarter'] ?? ceil(intval(date('n')) / 4));
$period_year = intval($_GET['year'] ?? date('Y'));
$period_from = $_GET['from'] ?? '';
$period_to = $_GET['to'] ?? '';

// Compute date range from period mode
if ($period_mode === 'custom' && $period_from && $period_to) {
    $query_from = $period_from;
    $query_to = $period_to;
} elseif ($period_mode === 'quarter') {
    $quarter_month = ($period_quarter - 1) * 3 + 1;
    $query_from = sprintf('%04d-%02d-01', $period_year, $quarter_month);
    $query_to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $period_year, $quarter_month + 2)));
} elseif ($period_mode === 'year') {
    $query_from = sprintf('%04d-01-01', $period_year);
    $query_to = sprintf('%04d-12-31', $period_year);
} else {
    $query_from = $period_month . '-01';
    $query_to = date('Y-m-t', strtotime($query_from));
}

// Metrics (all-time, unfiltered)
$total_revenue = safeQueryValue($pdo, "SELECT SUM(grand_total) FROM refill_orders");
$total_deposits = safeQueryValue($pdo, "SELECT SUM(deposit_balance) FROM customers");
$total_orders = safeQueryValue($pdo, "SELECT COUNT(*) FROM refill_orders");
$total_leased = safeQueryValue($pdo, "SELECT COUNT(*) FROM cylinders WHERE status = 'with_customer'");

// Profit metrics (all-time, unfiltered)
$total_cost = safeQueryValue($pdo, "SELECT COALESCE(SUM(oi.qty * oi.refill_cost), 0) FROM refill_order_items oi JOIN refill_orders o ON oi.refill_order_id = o.id");
$gross_profit = floatval($total_revenue) - floatval($total_cost);
$profit_margin = floatval($total_revenue) > 0 ? ($gross_profit / floatval($total_revenue)) * 100 : 0;

$total_revenue_fmt = $total_revenue ? number_format($total_revenue, 2) : "0.00";
$total_deposits_fmt = $total_deposits ? number_format($total_deposits, 2) : "0.00";

// ── P&L Queries (period-filtered) ──
$total_revenue_excl = safeQueryValue($pdo,
    "SELECT COALESCE(SUM(grand_total - COALESCE(tax_amount, 0)), 0) FROM refill_orders
     WHERE DATE(order_date) >= ? AND DATE(order_date) <= ?",
    [$query_from, $query_to]);

$direct_refill_cost = safeQueryValue($pdo,
    "SELECT COALESCE(SUM(oi.qty * oi.refill_cost), 0)
     FROM refill_order_items oi
     JOIN refill_orders o ON oi.refill_order_id = o.id
     WHERE DATE(o.order_date) >= ? AND DATE(o.order_date) <= ?",
    [$query_from, $query_to]);

$total_expenses = safeQueryValue($pdo,
    "SELECT COALESCE(SUM(taxable_amount), 0) FROM expenses
     WHERE is_deleted = 0 AND status = 'approved'
     AND expense_date >= ? AND expense_date <= ?",
    [$query_from, $query_to]);

$expenses_by_group = [];
try {
    $stmt = $pdo->prepare("
        SELECT ecg.name AS group_name, ecg.display_order,
               COALESCE(SUM(e.taxable_amount), 0) AS total
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        JOIN expense_category_groups ecg ON ec.group_id = ecg.id
        WHERE e.is_deleted = 0 AND e.status = 'approved'
          AND e.expense_date >= ? AND e.expense_date <= ?
        GROUP BY ecg.id, ecg.name, ecg.display_order
        ORDER BY ecg.display_order ASC
    ");
    $stmt->execute([$query_from, $query_to]);
    $expenses_by_group = $stmt->fetchAll();
} catch (PDOException $e) {}

$output_gst = safeQueryValue($pdo,
    "SELECT COALESCE(SUM(COALESCE(tax_amount, 0)), 0) FROM refill_orders
     WHERE DATE(order_date) >= ? AND DATE(order_date) <= ?",
    [$query_from, $query_to]);

$input_gst = safeQueryValue($pdo,
    "SELECT COALESCE(SUM(gst_total), 0) FROM expenses
     WHERE is_deleted = 0 AND status = 'approved'
     AND expense_date >= ? AND expense_date <= ?",
    [$query_from, $query_to]);

$gross_profit_excl = floatval($total_revenue_excl) - floatval($direct_refill_cost);
$net_profit = floatval($total_revenue_excl) - floatval($total_expenses);
$net_margin = floatval($total_revenue_excl) > 0 ? ($net_profit / floatval($total_revenue_excl)) * 100 : 0;
$gross_margin_excl = floatval($total_revenue_excl) > 0 ? ($gross_profit_excl / floatval($total_revenue_excl)) * 100 : 0;
$net_gst_payable = floatval($output_gst) - floatval($input_gst);

// Period label for display
$period_label = date('F Y', strtotime($query_from));
if ($period_mode === 'quarter') $period_label = "Q{$period_quarter} {$period_year}";
elseif ($period_mode === 'year') $period_label = "Year {$period_year}";
elseif ($period_mode === 'custom') $period_label = date('d M Y', strtotime($query_from)) . ' - ' . date('d M Y', strtotime($query_to));

// Fetch Gas Refill volume distributions
$gas_breakdown = [];
try {
    $stmt = $pdo->query("
        SELECT g.name, COUNT(oi.id) as refill_count, SUM(oi.qty) as total_qty 
        FROM refill_order_items oi 
        JOIN gas_types g ON oi.gas_type_id = g.id 
        GROUP BY g.id 
        ORDER BY total_qty DESC
    ");
    $gas_breakdown = $stmt->fetchAll();
} catch (PDOException $e) {}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('reports.heading'); ?></h2>
        <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('reports.subtitle'); ?></p>
    </div>
</div>

<!-- ── Period Filter Bar ── -->
<div style="background:var(--admin-card-bg);border:1px solid var(--admin-border);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;">
    <span style="font-weight:600;font-size:0.9rem;color:var(--admin-muted);margin-right:0.25rem;"><?php echo __('reports.period'); ?>:</span>
    <a href="?period=month&amp;month=<?= date('Y-m') ?>" class="period-btn <?= $period_mode === 'month' ? 'active' : '' ?>" style="padding:0.35rem 0.85rem;border-radius:8px;font-size:0.8rem;text-decoration:none;<?= $period_mode === 'month' ? 'background:var(--admin-accent);color:#fff;' : 'background:var(--admin-bg);color:var(--admin-fg);border:1px solid var(--admin-border);' ?>"><?php echo __('reports.this_month'); ?></a>
    <a href="?period=quarter&amp;quarter=<?= $period_quarter ?>&amp;year=<?= $period_year ?>" class="period-btn <?= $period_mode === 'quarter' ? 'active' : '' ?>" style="padding:0.35rem 0.85rem;border-radius:8px;font-size:0.8rem;text-decoration:none;<?= $period_mode === 'quarter' ? 'background:var(--admin-accent);color:#fff;' : 'background:var(--admin-bg);color:var(--admin-fg);border:1px solid var(--admin-border);' ?>"><?php echo __('reports.quarter'); ?></a>
    <a href="?period=year&amp;year=<?= $period_year ?>" class="period-btn <?= $period_mode === 'year' ? 'active' : '' ?>" style="padding:0.35rem 0.85rem;border-radius:8px;font-size:0.8rem;text-decoration:none;<?= $period_mode === 'year' ? 'background:var(--admin-accent);color:#fff;' : 'background:var(--admin-bg);color:var(--admin-fg);border:1px solid var(--admin-border);' ?>"><?php echo __('reports.this_year'); ?></a>
    <form method="GET" style="display:inline-flex;align-items:center;gap:0.5rem;margin-left:0.5rem;">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="from" value="<?= htmlspecialchars($period_from ?: date('Y-m-01')) ?>" style="padding:0.3rem 0.5rem;border:1px solid var(--admin-border);border-radius:6px;font-size:0.8rem;">
        <span style="color:var(--admin-muted);font-size:0.8rem;"><?php echo __('reports.to'); ?></span>
        <input type="date" name="to" value="<?= htmlspecialchars($period_to ?: date('Y-m-t')) ?>" style="padding:0.3rem 0.5rem;border:1px solid var(--admin-border);border-radius:6px;font-size:0.8rem;">
        <button type="submit" class="btn-secondary" style="padding:0.35rem 0.85rem;border-radius:8px;font-size:0.8rem;"><?php echo __('reports.go'); ?></button>
    </form>
    <span style="margin-left:auto;font-size:0.85rem;font-weight:600;color:var(--admin-accent);"><?= htmlspecialchars($period_label) ?></span>
</div>

<?php if (has_role(['super_admin', 'billing_clerk'])): ?>
<!-- ── P&L KPI Row ── -->
<div class="stat-grid" style="margin-bottom: 1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;">₹</div>
        <div class="stat-info">
            <h4>₹<?= number_format($total_revenue_excl, 2) ?></h4>
            <p><?php echo __('reports.revenue_excl_gst'); ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fef2f2; color: #ef4444;">₹</div>
        <div class="stat-info">
            <h4>₹<?= number_format($total_expenses, 2) ?></h4>
            <p><?php echo __('reports.total_expenses'); ?></p>
        </div>
    </div>
    <div class="stat-card" style="border-left:4px solid <?= $gross_profit_excl >= 0 ? '#10b981' : '#ef4444' ?>;">
        <div class="stat-icon" style="background: <?= $gross_profit_excl >= 0 ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $gross_profit_excl >= 0 ? '#10b981' : '#ef4444' ?>;">₹</div>
        <div class="stat-info">
            <h4 style="color:<?= $gross_profit_excl >= 0 ? 'var(--admin-fg)' : 'var(--danger)' ?>;">₹<?= number_format($gross_profit_excl, 2) ?></h4>
            <p>Gross Profit (<?= number_format($gross_margin_excl, 1) ?>% margin)</p>
        </div>
    </div>
    <div class="stat-card" style="border-left:4px solid <?= $net_profit >= 0 ? '#8b5cf6' : '#ef4444' ?>;background:<?= $net_profit >= 0 ? 'linear-gradient(135deg, var(--admin-card-bg) 0%, #f5f3ff 100%)' : '' ?>;">
        <div class="stat-icon" style="background: <?= $net_profit >= 0 ? '#f5f3ff' : '#fef2f2' ?>; color: <?= $net_profit >= 0 ? '#8b5cf6' : '#ef4444' ?>;">₹</div>
        <div class="stat-info">
            <h4 style="color:<?= $net_profit >= 0 ? '#8b5cf6' : 'var(--danger)' ?>;font-weight:900;">₹<?= number_format($net_profit, 2) ?></h4>
            <p style="font-weight:700;">NET PROFIT (<?= number_format($net_margin, 1) ?>% margin)</p>
        </div>
    </div>
</div>

<!-- ── P&L Statement ── -->
<div class="admin-card" style="margin-bottom:1.5rem;padding:1.5rem;">
    <h3 class="card-title" style="margin-bottom:1.25rem;"><?php echo __('reports.pnl_title'); ?> <span style="font-weight:400;font-size:0.85rem;color:var(--admin-muted);">— <?= htmlspecialchars($period_label) ?></span></h3>
    <div style="max-width:600px;">
        <table style="width:100%;border-collapse:collapse;">
            <tr><td style="padding:0.5rem 0;font-weight:700;"><?php echo __('reports.revenue_excl_gst'); ?></td><td style="padding:0.5rem 0;text-align:right;font-weight:700;">₹<?= number_format($total_revenue_excl, 2) ?></td></tr>
            <tr><td style="padding:0.5rem 0;color:var(--admin-muted);"><?php echo __('reports.direct_refill_cost'); ?></td><td style="padding:0.5rem 0;text-align:right;color:var(--danger);">(₹<?= number_format($direct_refill_cost, 2) ?>)</td></tr>
            <tr style="border-top:2px solid var(--admin-border);"><td style="padding:0.75rem 0;font-weight:800;font-size:1.05rem;"><?php echo __('reports.gross_profit'); ?></td><td style="padding:0.75rem 0;text-align:right;font-weight:800;font-size:1.05rem;color:<?= $gross_profit_excl >= 0 ? '#10b981' : '#ef4444' ?>;">₹<?= number_format($gross_profit_excl, 2) ?> <span style="font-weight:400;font-size:0.85rem;">(<?= number_format($gross_margin_excl, 1) ?>%)</span></td></tr>
            <tr><td colspan="2" style="padding:0.25rem 0;"></td></tr>
            <tr><td colspan="2" style="padding:0.5rem 0;font-weight:700;color:var(--admin-muted);"><?php echo __('reports.operating_expenses'); ?></td></tr>
            <?php $total_group_expenses = 0; ?>
            <?php foreach ($expenses_by_group as $eg): $total_group_expenses += floatval($eg['total']); ?>
            <tr><td style="padding:0.3rem 0;padding-left:1rem;font-size:0.9rem;"><?= htmlspecialchars($eg['group_name']) ?></td><td style="padding:0.3rem 0;text-align:right;font-size:0.9rem;color:var(--danger);">(₹<?= number_format(floatval($eg['total']), 2) ?>)</td></tr>
            <?php endforeach; ?>
            <?php if (empty($expenses_by_group)): ?>
            <tr><td style="padding:0.3rem 0;padding-left:1rem;font-size:0.9rem;color:var(--admin-muted);"><?php echo __('reports.no_expenses'); ?></td><td style="padding:0.3rem 0;text-align:right;">—</td></tr>
            <?php endif; ?>
            <tr style="border-top:2px dashed var(--admin-border);"><td style="padding:0.5rem 0;padding-left:1rem;font-weight:600;"><?php echo __('reports.total_expenses'); ?></td><td style="padding:0.5rem 0;text-align:right;font-weight:600;color:var(--danger);">(₹<?= number_format($total_group_expenses, 2) ?>)</td></tr>
            <tr style="border-top:3px double var(--admin-border);"><td style="padding:0.75rem 0;font-weight:900;font-size:1.15rem;color:<?= $net_profit >= 0 ? '#8b5cf6' : '#ef4444' ?>;">NET PROFIT</td><td style="padding:0.75rem 0;text-align:right;font-weight:900;font-size:1.15rem;color:<?= $net_profit >= 0 ? '#8b5cf6' : '#ef4444' ?>;">₹<?= number_format($net_profit, 2) ?> <span style="font-weight:400;font-size:0.85rem;">(<?= number_format($net_margin, 1) ?>%)</span></td></tr>
        </table>
    </div>
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border);display:flex;gap:2rem;flex-wrap:wrap;font-size:0.85rem;color:var(--admin-muted);">
        <span><?php echo __('reports.output_gst'); ?>: <strong style="color:var(--admin-fg);">₹<?= number_format($output_gst, 2) ?></strong></span>
        <span><?php echo __('reports.input_itc'); ?>: <strong style="color:var(--admin-fg);">(₹<?= number_format($input_gst, 2) ?>)</strong></span>
        <span><?php echo __('reports.net_gst_payable'); ?>: <strong style="color:<?= $net_gst_payable > 0 ? '#ef4444' : '#10b981' ?>;">₹<?= number_format(max(0, $net_gst_payable), 2) ?></strong></span>
    </div>
</div>

<!-- ── Net Profit by Gas Type ── -->
<div class="admin-card" style="margin-bottom:1.5rem;padding:1.5rem;">
    <h3 class="card-title" style="margin-bottom:0.25rem;"><?php echo __('reports.net_profit_by_gas'); ?></h3>
    <p style="color:var(--admin-muted);font-size:0.85rem;margin-bottom:1.25rem;"><?php echo __('reports.net_profit_by_gas_desc'); ?></p>
    <div class="table-wrapper" style="border:none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('reports.gas_type'); ?></th>
                    <th style="text-align:right;"><?php echo __('reports.revenue_excl_gst'); ?></th>
                    <th style="text-align:right;"><?php echo __('reports.direct_cost'); ?></th>
                    <th style="text-align:right;"><?php echo __('reports.allocated_overhead'); ?></th>
                    <th style="text-align:right;"><?php echo __('reports.net_profit_short'); ?></th>
                    <th style="text-align:right;"><?php echo __('reports.margin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                try {
                    $profit_by_gas = $pdo->prepare("
                        SELECT g.name,
                               COALESCE(SUM((oi.qty * oi.price_per_unit + CASE WHEN oi.is_rental = 2 THEN COALESCE(oi.sell_price, 0) ELSE 0 END) - COALESCE(oi.gst_amount, 0)), 0) AS revenue_excl_gst,
                               COALESCE(SUM(oi.qty * COALESCE(oi.refill_cost, 0)), 0) AS direct_cost
                        FROM refill_order_items oi
                        JOIN refill_orders o ON oi.refill_order_id = o.id
                        JOIN gas_types g ON oi.gas_type_id = g.id
                        WHERE DATE(o.order_date) >= ? AND DATE(o.order_date) <= ?
                        GROUP BY g.id
                        ORDER BY revenue_excl_gst DESC
                    ");
                    $profit_by_gas->execute([$query_from, $query_to]);
                    $gas_rows = $profit_by_gas->fetchAll();

                    $total_rev = array_sum(array_column($gas_rows, 'revenue_excl_gst'));
                    $total_dc = array_sum(array_column($gas_rows, 'direct_cost'));
                    $shared_expenses = max(0, floatval($total_expenses) - $total_dc);

                    foreach ($gas_rows as $pg):
                        $pg_rev = floatval($pg['revenue_excl_gst']);
                        $pg_dc = floatval($pg['direct_cost']);
                        $ratio = $total_rev > 0 ? $pg_rev / $total_rev : 0;
                        $pg_oh = $shared_expenses * $ratio;
                        $pg_np = $pg_rev - $pg_dc - $pg_oh;
                        $pg_margin = $pg_rev > 0 ? ($pg_np / $pg_rev) * 100 : 0;
                ?>
                <tr>
                    <td style="font-weight:700;"><?= htmlspecialchars($pg['name']) ?></td>
                    <td style="text-align:right;font-weight:600;">₹<?= number_format($pg_rev, 2) ?></td>
                    <td style="text-align:right;font-weight:600;">₹<?= number_format($pg_dc, 2) ?></td>
                    <td style="text-align:right;font-weight:600;color:var(--admin-muted);">₹<?= number_format($pg_oh, 2) ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= $pg_np >= 0 ? '#8b5cf6' : '#ef4444' ?>;">₹<?= number_format($pg_np, 2) ?></td>
                    <td style="text-align:right;font-weight:700;"><?= number_format($pg_margin, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($gas_rows)): ?>
                <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--admin-muted);"><?php echo __('reports.no_pl_data'); ?></td></tr>
                <?php endif; ?>
                <?php
                } catch (PDOException $e) {
                    echo '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--danger);">Error: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
                }
                ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:800;border-top:2px solid var(--admin-border);">
                    <td><?php echo __('reports.total'); ?></td>
                    <td style="text-align:right;">₹<?= number_format($total_rev, 2) ?></td>
                    <td style="text-align:right;">₹<?= number_format($total_dc, 2) ?></td>
                    <td style="text-align:right;color:var(--admin-muted);">₹<?= number_format($total_expenses - $total_dc, 2) ?></td>
                    <td style="text-align:right;color:<?= $net_profit >= 0 ? '#8b5cf6' : '#ef4444' ?>;">₹<?= number_format($net_profit, 2) ?></td>
                    <td style="text-align:right;"><?= number_format($net_margin, 1) ?>%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── All-Time Executive Summary Cards ── -->
<div class="stat-grid" style="margin-bottom: 2rem;">
    <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
    <div class="stat-card">
        <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;">₹</div>
        <div class="stat-info">
            <h4>₹<?php echo $total_revenue_fmt; ?></h4>
            <p><?php echo __('reports.revenue'); ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #ecfdf5; color: #10b981;">₹</div>
        <div class="stat-info">
            <h4>₹<?php echo $total_deposits_fmt; ?></h4>
            <p><?php echo __('reports.deposits'); ?></p>
        </div>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-icon" style="background: #f5f3ff; color: #8b5cf6;">📦</div>
        <div class="stat-info">
            <h4><?php echo $total_orders; ?></h4>
            <p><?php echo __('reports.invoices'); ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fffbeb; color: #f59e0b;">ellipse</div>
        <div class="stat-info">
            <h4><?php echo $total_leased; ?></h4>
            <p><?php echo __('reports.leased'); ?></p>
        </div>
    </div>
    <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
    <div class="stat-card" style="border-left:4px solid <?php echo $gross_profit >= 0 ? '#10b981' : '#ef4444'; ?>;">
        <div class="stat-icon" style="background: <?php echo $gross_profit >= 0 ? '#ecfdf5' : '#fef2f2'; ?>; color: <?php echo $gross_profit >= 0 ? '#10b981' : '#ef4444'; ?>;">₹</div>
        <div class="stat-info">
            <h4 style="color: <?php echo $gross_profit >= 0 ? 'var(--admin-fg)' : 'var(--danger)'; ?>;">₹<?php echo number_format($gross_profit, 2); ?></h4>
            <p>Gross Profit (<?php echo number_format($profit_margin, 1); ?>% margin)</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="split-layout">
    <!-- CSV Exporters Widgets -->
    <div class="admin-card" style="margin: 0; padding: 1.5rem;">
        <h3 class="card-title"><?php echo __('reports.csv_exporters'); ?></h3>
        <p style="color: var(--admin-muted); font-size: 0.85rem; margin-top: 0.25rem; margin-bottom: 1.5rem;">
            <?php echo __('reports.csv_desc'); ?>
        </p>
        
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
            <div style="padding: 1rem; border: 1px solid var(--admin-border); border-radius: 12px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h5 style="font-weight: 700; font-size: 0.95rem;"><?php echo __('reports.orders_export'); ?></h5>
                    <p style="font-size: 0.8rem; color: var(--admin-muted); margin-top: 0.25rem;"><?php echo __('reports.orders_export_desc'); ?></p>
                </div>
                <a href="reports.php?export=orders" class="btn-primary" style="padding: 0.5rem 1rem; font-size: 0.8rem; border-radius: 8px;"><?php echo __('reports.download_csv'); ?></a>
            </div>
            <?php endif; ?>
            
            <div style="padding: 1rem; border: 1px solid var(--admin-border); border-radius: 12px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h5 style="font-weight: 700; font-size: 0.95rem;"><?php echo __('reports.inventory_export'); ?></h5>
                    <p style="font-size: 0.8rem; color: var(--admin-muted); margin-top: 0.25rem;"><?php echo __('reports.inventory_export_desc'); ?></p>
                </div>
                <a href="reports.php?export=inventory" class="btn-primary" style="padding: 0.5rem 1rem; font-size: 0.8rem; border-radius: 8px;"><?php echo __('reports.download_csv'); ?></a>
            </div>
            
            <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
            <div style="padding: 1rem; border: 1px solid var(--admin-border); border-radius: 12px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h5 style="font-weight: 700; font-size: 0.95rem;"><?php echo __('reports.customers_export'); ?></h5>
                    <p style="font-size: 0.8rem; color: var(--admin-muted); margin-top: 0.25rem;"><?php echo __('reports.customers_export_desc'); ?></p>
                </div>
                <a href="reports.php?export=customers" class="btn-primary" style="padding: 0.5rem 1rem; font-size: 0.8rem; border-radius: 8px;"><?php echo __('reports.download_csv'); ?></a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Gas Refills Distribution statistics -->
    <div class="admin-card" style="margin: 0; padding: 1.5rem;">
        <h3 class="card-title"><?php echo __('reports.volume_distribution'); ?></h3>
        <p style="color: var(--admin-muted); font-size: 0.85rem; margin-top: 0.25rem; margin-bottom: 1.5rem;">
            <?php echo __('reports.volume_desc'); ?>
        </p>
        
        <div class="table-wrapper" style="border: none;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th><?php echo __('reports.gas_classification'); ?></th>
                        <th style="text-align: center;"><?php echo __('reports.invoiced_counts'); ?></th>
                        <th style="text-align: right;"><?php echo __('reports.total_refilled'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gas_breakdown as $row): ?>
                    <tr>
                        <td data-label="<?php echo __('reports.gas_classification'); ?>" style="font-weight: 700; color: var(--admin-fg);"><?php echo htmlspecialchars($row['name']); ?></td>
                        <td data-label="<?php echo __('reports.invoiced_counts'); ?>" style="text-align: center; font-weight: 600;"><?php echo $row['refill_count']; ?> <?php echo __('reports.times'); ?></td>
                        <td data-label="<?php echo __('reports.total_refilled'); ?>" style="text-align: right; font-weight: 800; color: var(--admin-accent);"><?php echo number_format($row['total_qty'], 2); ?> <?php echo __('reports.unit'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($gas_breakdown)): ?>
                    <tr>
                        <td colspan="3" style="text-align: center; padding: 3rem 0; color: var(--admin-muted);"><?php echo __('reports.no_volume_data'); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<?php
require_once 'layout_footer.php';
?>
