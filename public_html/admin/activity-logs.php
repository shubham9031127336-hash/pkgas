<?php
$page_title = 'Activity Logs';
$active_menu = 'dashboard';
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk', 'viewer']);
require_once __DIR__ . '/db.php';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;
$type_filter = $_GET['type'] ?? 'all';

$typeWhere = '';
$params = [];
if ($type_filter !== 'all') {
    switch ($type_filter) {
        case 'order': $typeWhere = "AND source = 'order'"; break;
        case 'payment': $typeWhere = "AND source = 'payment'"; break;
        case 'cylinder': $typeWhere = "AND source = 'cylinder'"; break;
        case 'customer': $typeWhere = "AND source = 'customer'"; break;
        case 'expense': $typeWhere = "AND source = 'expense'"; break;
        default: $typeWhere = ''; $type_filter = 'all';
    }
}

$countSql = "SELECT COUNT(*) FROM (
    SELECT 'order' AS source FROM refill_orders ro
    UNION ALL SELECT 'payment' FROM payments p
    UNION ALL SELECT 'cylinder' FROM cylinder_transactions ct
    UNION ALL SELECT 'customer' FROM customers c
    UNION ALL SELECT 'expense' FROM expenses e
) sub $typeWhere";
$totalRows = qc($pdo, str_replace('source', '1', $countSql));
$totalPages = max(1, ceil($totalRows / $limit));

$activity = qva($pdo, "(SELECT 'order' AS source, ro.id, ro.order_date AS ts, CONCAT('Order #', ro.id, ' - ', c.name) AS description, ro.payment_status AS status, 'shopping-cart' AS icon, NULL AS user_name FROM refill_orders ro JOIN customers c ON ro.customer_id = c.id)
    UNION ALL (SELECT 'payment' AS source, p.id, p.payment_date AS ts, CONCAT(p.payment_method, ' payment of ', p.amount, IF(p.customer_id IS NOT NULL, CONCAT(' from ', c.name), CONCAT(' to ', v.name))) AS description, p.payment_type AS status, 'dollar-sign' AS icon, NULL AS user_name FROM payments p LEFT JOIN customers c ON p.customer_id = c.id LEFT JOIN vendors v ON p.vendor_id = v.id)
    UNION ALL (SELECT 'cylinder' AS source, ct.id, ct.transaction_date AS ts, CONCAT(ct.transaction_type, ' - ', cy.serial_number) AS description, ct.transaction_type AS status, 'cylinder' AS icon, NULL AS user_name FROM cylinder_transactions ct JOIN cylinders cy ON ct.cylinder_id = cy.id)
    UNION ALL (SELECT 'customer' AS source, c.id, c.created_at AS ts, CONCAT('New: ', c.name) AS description, 'new' AS status, 'user-plus' AS icon, NULL AS user_name FROM customers c)
    UNION ALL (SELECT 'expense' AS source, e.id, e.created_at AS ts, CONCAT('Expense: ', e.amount, ' - ', ec.name) AS description, e.payment_status AS status, 'file-text' AS icon, NULL AS user_name FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id)
) main $typeWhere ORDER BY ts DESC LIMIT $limit OFFSET $offset");

function qc($pdo, $sql) {
    try { return (int)$pdo->query($sql)->fetchColumn(); } catch (PDOException $e) { return 0; }
}
function qva($pdo, $sql) {
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { return []; }
}
?>
<style>
.al-container { max-width: 1000px; margin: 0 auto; padding: 24px; }
.al-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.al-header h1 { font-size: 20px; font-weight: 700; margin: 0; color: #0f172a; }
.al-filters { display: flex; gap: 6px; flex-wrap: wrap; }
.al-filter { padding: 6px 14px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; font-size: 12px; font-weight: 600; color: #64748b; cursor: pointer; text-decoration: none; transition: all 0.15s; }
.al-filter:hover { border-color: #2563eb; color: #2563eb; }
.al-filter.active { background: #2563eb; color: #fff; border-color: #2563eb; }
.al-list { display: flex; flex-direction: column; gap: 1px; background: #f1f5f9; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; }
.al-item { display: flex; align-items: center; gap: 14px; padding: 14px 18px; background: #fff; transition: background 0.12s; }
.al-item:hover { background: #f8fafc; }
.al-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.al-content { flex: 1; min-width: 0; }
.al-title { font-size: 13px; font-weight: 600; color: #0f172a; }
.al-meta { font-size: 11px; color: #64748b; margin-top: 2px; }
.al-date { font-size: 11px; color: #94a3b8; white-space: nowrap; }
.al-status { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.03em; }
.al-status.paid { background: #ecfdf5; color: #065f46; }
.al-status.pending { background: #fffbeb; color: #92400e; }
.al-status.new { background: #eff6ff; color: #1e40af; }
.al-pagination { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 20px; }
.al-page { padding: 6px 12px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; font-size: 12px; font-weight: 600; color: #64748b; text-decoration: none; }
.al-page:hover { border-color: #2563eb; color: #2563eb; }
.al-page.active { background: #2563eb; color: #fff; border-color: #2563eb; }
.al-empty { text-align: center; padding: 48px 20px; color: #94a3b8; font-size: 14px; font-weight: 500; }
</style>

<div class="al-container">
    <div class="al-header">
        <h1>Activity Logs</h1>
        <div class="al-filters">
            <a href="activity-logs.php" class="al-filter <?php echo $type_filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="activity-logs.php?type=order" class="al-filter <?php echo $type_filter === 'order' ? 'active' : ''; ?>">Orders</a>
            <a href="activity-logs.php?type=payment" class="al-filter <?php echo $type_filter === 'payment' ? 'active' : ''; ?>">Payments</a>
            <a href="activity-logs.php?type=cylinder" class="al-filter <?php echo $type_filter === 'cylinder' ? 'active' : ''; ?>">Cylinders</a>
            <a href="activity-logs.php?type=customer" class="al-filter <?php echo $type_filter === 'customer' ? 'active' : ''; ?>">Customers</a>
            <a href="activity-logs.php?type=expense" class="al-filter <?php echo $type_filter === 'expense' ? 'active' : ''; ?>">Expenses</a>
        </div>
    </div>

    <div class="al-list">
        <?php if (empty($activity)): ?>
            <div class="al-empty">No activity records found.</div>
        <?php else: ?>
            <?php foreach ($activity as $a):
                $dotColor = '#64748b';
                if ($a['source'] === 'order') $dotColor = '#3b82f6';
                else if ($a['source'] === 'payment') $dotColor = '#10b981';
                else if ($a['source'] === 'cylinder') $dotColor = '#8b5cf6';
                else if ($a['source'] === 'customer') $dotColor = '#f59e0b';
                else if ($a['source'] === 'expense') $dotColor = '#ef4444';
            ?>
            <div class="al-item">
                <span class="al-dot" style="background:<?php echo $dotColor; ?>"></span>
                <div class="al-content">
                    <div class="al-title"><?php echo htmlspecialchars($a['description'] ?? ''); ?></div>
                    <div class="al-meta"><?php echo htmlspecialchars($a['user_name'] ?? 'System'); ?> &middot; <?php echo htmlspecialchars($a['status'] ?? ''); ?></div>
                </div>
                <span class="al-date"><?php echo $a['ts'] ? date('d M Y, h:i A', strtotime($a['ts'])) : ''; ?></span>
                <span class="al-status <?php echo $a['status'] === 'paid' || $a['status'] === 'new' ? $a['status'] : (in_array($a['status'], ['pending','partial']) ? 'pending' : ''); ?>"><?php echo htmlspecialchars($a['status'] ?? ''); ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="al-pagination">
        <?php if ($page > 1): ?>
            <a href="activity-logs.php?page=<?php echo $page - 1; ?>&type=<?php echo $type_filter; ?>" class="al-page">&laquo; Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="activity-logs.php?page=<?php echo $i; ?>&type=<?php echo $type_filter; ?>" class="al-page <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="activity-logs.php?page=<?php echo $page + 1; ?>&type=<?php echo $type_filter; ?>" class="al-page">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
