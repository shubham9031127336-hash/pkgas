<?php
$page_title = "Expense Reports";
$active_menu = "expense_reports";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/expense-utils.php';
runExpenseMigrations($pdo);

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'daily';
$date_r = $_GET['date'] ?? date('Y-m-d');
$month_r = $_GET['month'] ?? date('Y-m');
$year_r = $_GET['year'] ?? date('Y');
$cat_r = isset($_GET['cat_id']) ? intval($_GET['cat_id']) : 0;
$vendor_r = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;

$categories = $pdo->query("SELECT c.id, c.name, g.name AS group_name FROM expense_categories c JOIN expense_category_groups g ON c.group_id = g.id WHERE c.is_active = 1 ORDER BY g.display_order ASC, c.name ASC")->fetchAll();
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name ASC")->fetchAll();
$groups = $pdo->query("SELECT * FROM expense_category_groups WHERE is_active = 1 ORDER BY display_order ASC")->fetchAll();

function fetchReport($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
?>
<link rel="stylesheet" href="expense.css">

<div class="page-header">
    <div class="page-header-title">
        <h2>Expense Reports</h2>
        <p>Analyze expenses across categories, vendors, and time periods</p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <a href="expenses.php" class="btn-secondary" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">← All Expenses</a>
    </div>
</div>

<div class="admin-card">
    <div class="report-tab-bar">
        <button class="report-tab-btn <?= $tab === 'daily' ? 'active' : '' ?>" onclick="window.location.href='?tab=daily'">Daily</button>
        <button class="report-tab-btn <?= $tab === 'monthly' ? 'active' : '' ?>" onclick="window.location.href='?tab=monthly'">Monthly</button>
        <button class="report-tab-btn <?= $tab === 'yearly' ? 'active' : '' ?>" onclick="window.location.href='?tab=yearly'">Yearly</button>
        <button class="report-tab-btn <?= $tab === 'by_category' ? 'active' : '' ?>" onclick="window.location.href='?tab=by_category'">By Category</button>
        <button class="report-tab-btn <?= $tab === 'by_vendor' ? 'active' : '' ?>" onclick="window.location.href='?tab=by_vendor'">By Vendor</button>
        <button class="report-tab-btn <?= $tab === 'gst' ? 'active' : '' ?>" onclick="window.location.href='?tab=gst'">GST Summary</button>
        <button class="report-tab-btn <?= $tab === 'fuel' ? 'active' : '' ?>" onclick="window.location.href='?tab=fuel'">Fuel</button>
        <button class="report-tab-btn <?= $tab === 'labour' ? 'active' : '' ?>" onclick="window.location.href='?tab=labour'">Labour</button>
        <button class="report-tab-btn <?= $tab === 'transport' ? 'active' : '' ?>" onclick="window.location.href='?tab=transport'">Transport</button>
        <button class="report-tab-btn <?= $tab === 'warehouse' ? 'active' : '' ?>" onclick="window.location.href='?tab=warehouse'">By Warehouse</button>
    </div>

<?php if ($tab === 'daily'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="daily">
        <input type="date" name="date" value="<?= htmlspecialchars($date_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $rows = fetchReport($pdo,
        "SELECT ecg.name AS group_name, ec.name AS cat_name, COUNT(*) AS cnt, COALESCE(SUM(e.amount),0) AS total_amt, COALESCE(SUM(e.gst_total),0) AS total_gst, COALESCE(SUM(e.total_amount),0) AS grand_total
         FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id JOIN expense_category_groups ecg ON ec.group_id = ecg.id
         WHERE e.is_deleted = 0 AND e.expense_date = ? GROUP BY ecg.display_order, ecg.name, ec.name ORDER BY ecg.display_order ASC, grand_total DESC",
        [$date_r]);
    $daily_total = array_sum(array_column($rows, 'grand_total'));
    ?>
    <h3 style="margin-bottom:1rem;">Daily Report — <?= htmlspecialchars($date_r) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($daily_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Category Group</th><th>Category</th><th style="text-align:center;">Count</th><th style="text-align:right;">Amount</th><th style="text-align:right;">GST</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--admin-muted);">No expenses on this date.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><?= htmlspecialchars($r['group_name']) ?></td><td><strong><?= htmlspecialchars($r['cat_name']) ?></strong></td><td style="text-align:center;"><?= $r['cnt'] ?></td><td style="text-align:right;">₹<?= number_format($r['total_amt'], 2) ?></td><td style="text-align:right;">₹<?= number_format($r['total_gst'], 2) ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['grand_total'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:800;"><td colspan="3">Total</td><td style="text-align:right;">₹<?= number_format($daily_total, 2) ?></td><td></td><td style="text-align:right;">₹<?= number_format($daily_total, 2) ?></td></tr></tfoot>
    </table>

<?php elseif ($tab === 'monthly'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="monthly">
        <input type="month" name="month" value="<?= htmlspecialchars($month_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $month_start = $month_r . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $rows = fetchReport($pdo,
        "SELECT ecg.name AS group_name, ec.name AS cat_name, COUNT(*) AS cnt, COALESCE(SUM(e.amount),0) AS total_amt, COALESCE(SUM(e.gst_total),0) AS total_gst, COALESCE(SUM(e.total_amount),0) AS grand_total
         FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id JOIN expense_category_groups ecg ON ec.group_id = ecg.id
         WHERE e.is_deleted = 0 AND e.expense_date >= ? AND e.expense_date <= ? GROUP BY ecg.display_order, ecg.name, ec.name ORDER BY ecg.display_order ASC, grand_total DESC",
        [$month_start, $month_end]);
    $monthly_total = array_sum(array_column($rows, 'grand_total'));
    ?>
    <h3 style="margin-bottom:1rem;">Monthly Report — <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($monthly_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Category Group</th><th>Category</th><th style="text-align:center;">Count</th><th style="text-align:right;">Amount</th><th style="text-align:right;">GST</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--admin-muted);">No expenses this month.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><?= htmlspecialchars($r['group_name']) ?></td><td><strong><?= htmlspecialchars($r['cat_name']) ?></strong></td><td style="text-align:center;"><?= $r['cnt'] ?></td><td style="text-align:right;">₹<?= number_format($r['total_amt'], 2) ?></td><td style="text-align:right;">₹<?= number_format($r['total_gst'], 2) ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['grand_total'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:800;"><td colspan="3">Total</td><td style="text-align:right;">₹<?= number_format($monthly_total, 2) ?></td><td style="text-align:right;">₹<?= number_format(array_sum(array_column($rows, 'total_gst')), 2) ?></td><td style="text-align:right;">₹<?= number_format($monthly_total, 2) ?></td></tr></tfoot>
    </table>

<?php elseif ($tab === 'yearly'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="yearly">
        <select name="year">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
            <option value="<?= $y ?>" <?= $year_r == $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $rows = fetchReport($pdo,
        "SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS ym, DATE_FORMAT(e.expense_date, '%b %Y') AS month_label, COUNT(*) AS cnt, COALESCE(SUM(e.total_amount),0) AS grand_total
         FROM expenses e WHERE e.is_deleted = 0 AND YEAR(e.expense_date) = ? GROUP BY ym ORDER BY ym ASC",
        [$year_r]);
    $yearly_total = array_sum(array_column($rows, 'grand_total'));
    ?>
    <h3 style="margin-bottom:1rem;">Yearly Report — <?= $year_r ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($yearly_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Month</th><th style="text-align:center;">Count</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--admin-muted);">No expenses this year.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><strong><?= htmlspecialchars($r['month_label']) ?></strong></td><td style="text-align:center;"><?= $r['cnt'] ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['grand_total'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:800;"><td>Total</td><td style="text-align:center;"><?= array_sum(array_column($rows, 'cnt')) ?></td><td style="text-align:right;">₹<?= number_format($yearly_total, 2) ?></td></tr></tfoot>
    </table>

<?php elseif ($tab === 'by_category'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="by_category">
        <select name="cat_id"><option value="">All Categories</option><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $cat_r === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
        <input type="month" name="month" value="<?= htmlspecialchars($month_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $cat_where = $cat_r > 0 ? " AND e.category_id = " . intval($cat_r) : "";
    $month_start = $month_r . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $rows = fetchReport($pdo,
        "SELECT ec.name AS cat_name, e.expense_date, e.expense_number, e.vendor_name, e.total_amount, e.payment_status, e.payment_method
         FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id
         WHERE e.is_deleted = 0 AND e.expense_date >= ? AND e.expense_date <= ? $cat_where
         ORDER BY e.expense_date DESC", [$month_start, $month_end]);
    $category_total = array_sum(array_column($rows, 'total_amount'));
    ?>
    <h3 style="margin-bottom:1rem;"><?= $cat_r > 0 ? 'Category' : 'All Categories' ?> Report — <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($category_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Date</th><th>Expense #</th><th>Category</th><th>Vendor</th><th style="text-align:right;">Total</th><th>Payment</th><th>Status</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--admin-muted);">No expenses found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><?= $r['expense_date'] ?></td><td style="font-weight:600;font-size:0.82rem;"><?= htmlspecialchars($r['expense_number']) ?></td><td><?= htmlspecialchars($r['cat_name']) ?></td><td><?= htmlspecialchars($r['vendor_name'] ?: '-') ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['total_amount'], 2) ?></td><td><?= $r['payment_method'] ?></td><td><span class="status-badge" style="background:<?= $r['payment_status'] === 'paid' ? '#d1fae5' : ($r['payment_status'] === 'partial' ? '#fef3c7' : '#fef2f2') ?>;color:<?= $r['payment_status'] === 'paid' ? '#059669' : ($r['payment_status'] === 'partial' ? '#d97706' : '#dc2626') ?>;"><?= ucfirst($r['payment_status']) ?></span></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'by_vendor'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="by_vendor">
        <select name="vendor_id"><option value="">All Vendors</option><?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>" <?= $vendor_r === $v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?></select>
        <input type="month" name="month" value="<?= htmlspecialchars($month_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $ven_where = $vendor_r > 0 ? " AND e.vendor_id = " . intval($vendor_r) : " AND e.vendor_id IS NOT NULL";
    $month_start = $month_r . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $rows = fetchReport($pdo,
        "SELECT e.vendor_name, ec.name AS cat_name, COUNT(*) AS cnt, COALESCE(SUM(e.total_amount),0) AS grand_total
         FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id
         WHERE e.is_deleted = 0 AND e.expense_date >= ? AND e.expense_date <= ? $ven_where
         GROUP BY e.vendor_name, ec.name ORDER BY grand_total DESC", [$month_start, $month_end]);
    $vendor_total = array_sum(array_column($rows, 'grand_total'));
    ?>
    <h3 style="margin-bottom:1rem;">Vendor Expense Report — <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($vendor_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Vendor</th><th>Category</th><th style="text-align:center;">Count</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--admin-muted);">No vendor expenses found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><strong><?= htmlspecialchars($r['vendor_name'] ?: 'Unknown') ?></strong></td><td><?= htmlspecialchars($r['cat_name']) ?></td><td style="text-align:center;"><?= $r['cnt'] ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['grand_total'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'gst'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="gst">
        <input type="month" name="month" value="<?= htmlspecialchars($month_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $month_start = $month_r . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $rows = fetchReport($pdo,
        "SELECT e.gst_rate, COUNT(*) AS cnt, COALESCE(SUM(e.taxable_amount),0) AS taxable, COALESCE(SUM(e.cgst_amount),0) AS cgst, COALESCE(SUM(e.sgst_amount),0) AS sgst, COALESCE(SUM(e.igst_amount),0) AS igst, COALESCE(SUM(e.gst_total),0) AS gst_total
         FROM expenses e WHERE e.is_deleted = 0 AND e.gst_rate > 0 AND e.expense_date >= ? AND e.expense_date <= ?
         GROUP BY e.gst_rate ORDER BY e.gst_rate ASC", [$month_start, $month_end]);
    $total_gst_all = array_sum(array_column($rows, 'gst_total'));
    $total_taxable = array_sum(array_column($rows, 'taxable'));
    ?>
    <h3 style="margin-bottom:1rem;">GST on Expenses — <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(ITC Available: ₹<?= number_format($total_gst_all, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>GST Rate</th><th style="text-align:center;">Transactions</th><th style="text-align:right;">Taxable Amount</th><th style="text-align:right;">CGST</th><th style="text-align:right;">SGST</th><th style="text-align:right;">IGST</th><th style="text-align:right;">Total GST</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--admin-muted);">No GST expenses this month.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><strong><?= $r['gst_rate'] ?>%</strong></td><td style="text-align:center;"><?= $r['cnt'] ?></td><td style="text-align:right;">₹<?= number_format($r['taxable'], 2) ?></td><td style="text-align:right;">₹<?= number_format($r['cgst'], 2) ?></td><td style="text-align:right;">₹<?= number_format($r['sgst'], 2) ?></td><td style="text-align:right;">₹<?= number_format($r['igst'], 2) ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['gst_total'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:800;"><td>Total</td><td style="text-align:center;"><?= array_sum(array_column($rows, 'cnt')) ?></td><td style="text-align:right;">₹<?= number_format($total_taxable, 2) ?></td><td style="text-align:right;">₹<?= number_format(array_sum(array_column($rows, 'cgst')), 2) ?></td><td style="text-align:right;">₹<?= number_format(array_sum(array_column($rows, 'sgst')), 2) ?></td><td style="text-align:right;">₹<?= number_format(array_sum(array_column($rows, 'igst')), 2) ?></td><td style="text-align:right;">₹<?= number_format($total_gst_all, 2) ?></td></tr></tfoot>
    </table>

<?php elseif ($tab === 'fuel'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="fuel">
        <input type="month" name="month" value="<?= htmlspecialchars($month_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $month_start = $month_r . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $fuel_cat = getCategoryIdBySlug($pdo, 'Diesel');
    $petrol_cat = getCategoryIdBySlug($pdo, 'Petrol');
    $fuel_ids = [$fuel_cat, $petrol_cat];
    $fuel_where = !empty($fuel_ids) ? "AND e.category_id IN (" . implode(',', array_map('intval', $fuel_ids)) . ")" : "AND 0";
    $rows = fetchReport($pdo,
        "SELECT e.expense_date, e.expense_number, ec.name AS cat_name, e.vendor_name, e.vehicle_number, e.driver_name, e.amount, e.gst_total, e.total_amount
         FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id
         WHERE e.is_deleted = 0 AND e.expense_date >= ? AND e.expense_date <= ? $fuel_where
         ORDER BY e.expense_date DESC", [$month_start, $month_end]);
    $fuel_total = array_sum(array_column($rows, 'total_amount'));
    ?>
    <h3 style="margin-bottom:1rem;">Fuel Consumption Report — <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($fuel_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Date</th><th>Expense #</th><th>Type</th><th>Vendor</th><th>Vehicle</th><th>Driver</th><th style="text-align:right;">Amount</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--admin-muted);">No fuel expenses this month.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><?= $r['expense_date'] ?></td><td style="font-size:0.82rem;"><?= htmlspecialchars($r['expense_number']) ?></td><td><strong><?= htmlspecialchars($r['cat_name']) ?></strong></td><td><?= htmlspecialchars($r['vendor_name'] ?: '-') ?></td><td><?= htmlspecialchars($r['vehicle_number'] ?: '-') ?></td><td><?= htmlspecialchars($r['driver_name'] ?: '-') ?></td><td style="text-align:right;">₹<?= number_format($r['amount'], 2) ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['total_amount'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'labour'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="labour">
        <input type="month" name="month" value="<?= htmlspecialchars($month_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $month_start = $month_r . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $labour_ids = [getCategoryIdBySlug($pdo, 'Labour Charges'), getCategoryIdBySlug($pdo, 'Overtime'), getCategoryIdBySlug($pdo, 'Salary'), getCategoryIdBySlug($pdo, 'Bonus')];
    $labour_where = !empty(array_filter($labour_ids)) ? "AND e.category_id IN (" . implode(',', array_map('intval', array_filter($labour_ids))) . ")" : "AND 0";
    $rows = fetchReport($pdo,
        "SELECT e.expense_date, e.expense_number, ec.name AS cat_name, e.vendor_name, e.amount, e.total_amount
         FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id
         WHERE e.is_deleted = 0 AND e.expense_date >= ? AND e.expense_date <= ? $labour_where
         ORDER BY e.expense_date DESC", [$month_start, $month_end]);
    $labour_total = array_sum(array_column($rows, 'total_amount'));
    ?>
    <h3 style="margin-bottom:1rem;">Labour & Salary Report — <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($labour_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Date</th><th>Expense #</th><th>Category</th><th>Employee</th><th style="text-align:right;">Amount</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--admin-muted);">No labour/salary expenses this month.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><?= $r['expense_date'] ?></td><td style="font-size:0.82rem;"><?= htmlspecialchars($r['expense_number']) ?></td><td><strong><?= htmlspecialchars($r['cat_name']) ?></strong></td><td><?= htmlspecialchars($r['vendor_name'] ?: '-') ?></td><td style="text-align:right;">₹<?= number_format($r['amount'], 2) ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['total_amount'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'transport'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="transport">
        <input type="month" name="month" value="<?= htmlspecialchars($month_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $month_start = $month_r . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $transport_cat = getCategoryIdBySlug($pdo, 'Transport Charges');
    $transport_where = $transport_cat > 0 ? "AND e.category_id = " . intval($transport_cat) : "AND 0";
    $rows = fetchReport($pdo,
        "SELECT e.expense_date, e.expense_number, e.vendor_name, e.vehicle_number, e.driver_name, e.amount, e.total_amount
         FROM expenses e WHERE e.is_deleted = 0 AND e.expense_date >= ? AND e.expense_date <= ? $transport_where
         ORDER BY e.expense_date DESC", [$month_start, $month_end]);
    $transport_total = array_sum(array_column($rows, 'total_amount'));
    ?>
    <h3 style="margin-bottom:1rem;">Transport Cost Report — <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($transport_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Date</th><th>Expense #</th><th>Vendor</th><th>Vehicle</th><th>Driver</th><th style="text-align:right;">Amount</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--admin-muted);">No transport expenses this month.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><?= $r['expense_date'] ?></td><td style="font-size:0.82rem;"><?= htmlspecialchars($r['expense_number']) ?></td><td><?= htmlspecialchars($r['vendor_name'] ?: '-') ?></td><td><?= htmlspecialchars($r['vehicle_number'] ?: '-') ?></td><td><?= htmlspecialchars($r['driver_name'] ?: '-') ?></td><td style="text-align:right;">₹<?= number_format($r['amount'], 2) ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['total_amount'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'warehouse'): ?>
    <form method="GET" class="report-filters">
        <input type="hidden" name="tab" value="warehouse">
        <input type="month" name="month" value="<?= htmlspecialchars($month_r) ?>">
        <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">View</button>
    </form>
    <?php
    $month_start = $month_r . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $rows = fetchReport($pdo,
        "SELECT COALESCE(e.warehouse_branch, 'Unassigned') AS branch, ec.name AS cat_name, COUNT(*) AS cnt, COALESCE(SUM(e.total_amount),0) AS grand_total
         FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id
         WHERE e.is_deleted = 0 AND e.expense_date >= ? AND e.expense_date <= ?
         GROUP BY e.warehouse_branch, ec.name ORDER BY branch ASC, grand_total DESC", [$month_start, $month_end]);
    $warehouse_total = array_sum(array_column($rows, 'grand_total'));
    ?>
    <h3 style="margin-bottom:1rem;">Expense by Warehouse — <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?> <span style="font-weight:400;color:var(--admin-muted);font-size:0.9rem;">(Total: ₹<?= number_format($warehouse_total, 2) ?>)</span></h3>
    <table class="admin-table">
        <thead><tr><th>Warehouse / Branch</th><th>Category</th><th style="text-align:center;">Count</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>
            <?php if (empty($rows)): ?><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--admin-muted);">No expenses found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr><td><strong><?= htmlspecialchars($r['branch']) ?></strong></td><td><?= htmlspecialchars($r['cat_name']) ?></td><td style="text-align:center;"><?= $r['cnt'] ?></td><td style="text-align:right;font-weight:700;">₹<?= number_format($r['grand_total'], 2) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
