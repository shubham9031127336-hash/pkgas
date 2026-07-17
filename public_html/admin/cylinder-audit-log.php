<?php
$page_title = "Cylinder Audit Log";
$active_menu = "settings";
require_once 'layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once 'db.php';
require_once 'inventory-utils.php';
runDeletedCylindersMigration($pdo);

$search_serial = isset($_GET['serial']) ? trim($_GET['serial']) : '';
$filter_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$filter_customer = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$show_deleted = isset($_GET['show_deleted']) && $_GET['show_deleted'] == '1';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

$active_sql = "SELECT ct.id, ct.cylinder_id, ct.customer_id, ct.vendor_id,
       ct.transaction_type, ct.transaction_date, ct.notes,
       c.serial_number, c.ownership_type, c.original_owner_customer_id,
       cu.name as customer_name, v.name as vendor_name,
       oc.name as original_owner_name, p.company_name as partner_name, 0 as is_deleted
FROM cylinder_transactions ct
JOIN cylinders c ON ct.cylinder_id = c.id
LEFT JOIN customers cu ON ct.customer_id = cu.id
LEFT JOIN vendors v ON ct.vendor_id = v.id
LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
LEFT JOIN partners p ON c.current_partner_id = p.id";

$deleted_sql = "SELECT ct2.id, ct2.cylinder_id, ct2.customer_id, ct2.vendor_id,
       ct2.transaction_type, ct2.transaction_date, ct2.notes,
       c.serial_number, c.ownership_type, c.original_owner_customer_id,
       cu2.name as customer_name, NULL as vendor_name,
       oc2.name as original_owner_name, NULL as partner_name, 1 as is_deleted
FROM cylinder_transactions ct2
JOIN cylinders c ON ct2.cylinder_id = c.id AND c.deleted_at IS NOT NULL
LEFT JOIN customers cu2 ON ct2.customer_id = cu2.id
LEFT JOIN customers oc2 ON c.original_owner_customer_id = oc2.id";

$where_clauses = [];
$params = [];

if (!empty($search_serial)) {
    $where_clauses[] = "serial_number LIKE ?";
    $params[] = "%$search_serial%";
}
if (!empty($filter_type)) {
    $where_clauses[] = "transaction_type = ?";
    $params[] = $filter_type;
}
if ($filter_customer > 0) {
    $where_clauses[] = "(customer_id = ? OR original_owner_customer_id = ?)";
    $params[] = $filter_customer;
    $params[] = $filter_customer;
}

if (!$show_deleted) {
    $where_clauses[] = "is_deleted = 0";
}

$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

$full_sql = "SELECT * FROM (($active_sql) UNION ALL ($deleted_sql)) combined $where_sql ORDER BY transaction_date DESC LIMIT $per_page OFFSET $offset";

$count_sql = "SELECT COUNT(*) FROM (($active_sql) UNION ALL ($deleted_sql)) combined $where_sql";

$total_count = 0;
$logs = [];
try {
    $cnt_stmt = $pdo->prepare($count_sql);
    $cnt_stmt->execute($params);
    $total_count = $cnt_stmt->fetchColumn();

    $stmt = $pdo->prepare($full_sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
}

$total_pages = ceil($total_count / $per_page);

$customers = [];
try { $customers = $pdo->query("SELECT id, name FROM customers ORDER BY name ASC LIMIT 500")->fetchAll(); } catch (PDOException $e) {}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
    <div>
        <h2 style="font-size:1.75rem;font-weight:800;">Cylinder Audit Log</h2>
        <p style="color:var(--admin-muted);font-size:0.9rem;margin-top:0.25rem;">Complete history of every cylinder movement, exchange, and settlement event.</p>
    </div>
    <a href="settings.php" class="btn-secondary">Back to Settings</a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert-banner" style="background:var(--danger-soft);color:var(--danger);border-color:#fca5a5;margin-bottom:1.5rem;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom:1.5rem;">
    <form method="GET" style="display:grid;grid-template-columns:1.5fr 1.2fr 1.2fr 1fr 100px;gap:1rem;align-items:end;">
        <div>
            <label class="form-label" style="font-weight:700;">Search by Serial Number</label>
            <input type="text" name="serial" class="form-control" placeholder="e.g. OX-47L-201" value="<?= htmlspecialchars($search_serial) ?>" autofocus>
        </div>
        <div>
            <label class="form-label">Transaction Type</label>
            <select name="type" class="form-control">
                <option value="">All Types</option>
                <option value="issue_to_customer" <?= $filter_type==='issue_to_customer'?'selected':'' ?>>Issue to Customer</option>
                <option value="return_from_customer" <?= $filter_type==='return_from_customer'?'selected':'' ?>>Return from Customer</option>
                <option value="consumer_return" <?= $filter_type==='consumer_return'?'selected':'' ?>>Consumer Return</option>
                <option value="consumer_give_back" <?= $filter_type==='consumer_give_back'?'selected':'' ?>>Consumer Give Back (Settled)</option>
                <option value="send_to_vendor" <?= $filter_type==='send_to_vendor'?'selected':'' ?>>Send to Vendor</option>
                <option value="partner_borrow" <?= $filter_type==='partner_borrow'?'selected':'' ?>>Partner Borrow</option>
                <option value="partner_return" <?= $filter_type==='partner_return'?'selected':'' ?>>Partner Return</option>
            </select>
        </div>
        <div>
            <label class="form-label">Filter by Customer</label>
            <select name="customer_id" class="form-control">
                <option value="">All Customers</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter_customer==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Deleted Cylinders</label>
            <label style="display:flex;align-items:center;gap:8px;padding:0.65rem;background:#f8fafc;border:1px solid var(--admin-border);border-radius:8px;cursor:pointer;font-size:0.85rem;">
                <input type="checkbox" name="show_deleted" value="1" <?= $show_deleted ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--danger);">
                <span style="color:var(--danger);font-weight:600;">Include deleted</span>
            </label>
        </div>
        <div><button type="submit" class="btn-primary" style="height:42px;width:100%;justify-content:center;">Search</button></div>
    </form>
</div>

<div class="admin-card" style="padding:0;">
    <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:700;">Total Records: <strong><?= number_format($total_count) ?></strong></span>
        <span style="font-size:0.85rem;color:var(--admin-muted);">Page <?= $page ?> of <?= max(1, $total_pages) ?></span>
    </div>
    <div class="table-wrapper" style="border:none;">
        <table class="admin-table" style="font-size:0.85rem;">
            <thead>
                <tr>
                    <th>Serial</th>
                    <th>Owner</th>
                    <th>Type</th>
                    <th>Customer</th>
                    <th>Vendor</th>
                    <th>Date</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody id="audit-tbody">
                <?php foreach ($logs as $log): ?>
                <?php
                    $owner_tag = '';
                    if ($log['ownership_type'] === 'consumer_owned') {
                        $owner_tag = '<span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:0.68rem;font-weight:800;" title="Belongs to: '.htmlspecialchars($log['original_owner_name'] ?: 'Unknown').'">CON</span>';
                    } elseif ($log['ownership_type'] === 'partner_owned') {
                        $pname = htmlspecialchars($log['partner_name'] ?? 'Unknown');
                        $owner_tag = '<span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:0.68rem;font-weight:800;" title="Partner: '.$pname.'">BR</span>';
                    } elseif ($log['ownership_type'] === 'vendor_owned') {
                        $vname = htmlspecialchars($log['vendor_name'] ?? 'Unknown');
                        $owner_tag = '<span style="background:#e8d5f5;color:#6b21a8;padding:2px 6px;border-radius:4px;font-size:0.68rem;font-weight:800;" title="Vendor: '.$vname.'">VEN</span>';
                    } else {
                        $owner_tag = '<span style="background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:0.68rem;font-weight:800;">OWN</span>';
                    }

                    $type_badge = 'badge-filled';
                    if (in_array($log['transaction_type'], ['issue_to_customer'])) $type_badge = 'badge-with-customer';
                    if (in_array($log['transaction_type'], ['return_from_customer', 'consumer_return', 'consumer_give_back'])) $type_badge = 'badge-empty';
                    if ($log['transaction_type'] === 'send_to_vendor') $type_badge = 'badge-sent-to-vendor';
                    if (in_array($log['transaction_type'], ['partner_borrow', 'partner_return'])) $type_badge = 'badge-rental';

                    $row_style = $log['is_deleted'] ? 'background:#fef2f2;border-left:3px solid #ef4444;' : '';
                    $deleted_badge = $log['is_deleted'] ? '<span style="background:#ef4444;color:#fff;padding:1px 5px;border-radius:3px;font-size:0.6rem;font-weight:700;margin-left:4px;">DELETED</span>' : '';
                ?>
                <tr style="<?= $row_style ?>">
                    <td data-label="Serial" style="font-weight:700;color:var(--admin-accent);"><?= htmlspecialchars($log['serial_number']) ?> <?= $owner_tag ?> <?= $deleted_badge ?></td>
                    <td data-label="Owner"><?= $owner_tag ?></td>
                    <td data-label="Type"><span class="badge <?= $type_badge ?>" style="font-size:0.65rem;"><?= str_replace('_', ' ', $log['transaction_type']) ?></span></td>
                    <td data-label="Customer"><?= $log['customer_name'] ? htmlspecialchars($log['customer_name']) : '<span style="color:var(--admin-muted)">—</span>' ?></td>
                    <td data-label="Vendor"><?= $log['vendor_name'] ? htmlspecialchars($log['vendor_name']) : '<span style="color:var(--admin-muted)">—</span>' ?></td>
                    <td data-label="Date" style="font-size:0.8rem;white-space:nowrap;"><?= date('M d, Y H:i', strtotime($log['transaction_date'])) ?></td>
                    <td data-label="Notes" style="font-size:0.8rem;color:var(--admin-muted);max-width:300px;"><?= htmlspecialchars($log['notes'] ?: '') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <tr><td data-label="" colspan="7" style="text-align:center;padding:3rem;color:var(--admin-muted);">No audit log entries found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="audit-pagination" style="padding:1rem 1.5rem;border-top:1px solid var(--admin-border);display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:0.75rem;">
        <span style="font-size:0.85rem;color:var(--admin-muted);white-space:nowrap;">
            Showing <strong><?= $total_count ? (($page - 1) * $per_page + 1) : 0 ?></strong>–<strong><?= min($page * $per_page, $total_count) ?></strong> of <strong><?= number_format($total_count) ?></strong> records
        </span>
        <?php if ($total_pages > 1): ?>
        <div style="display:flex;gap:0.25rem;align-items:center;flex-wrap:wrap;">
            <a href="?<?= http_build_query(array_filter(['serial' => $search_serial, 'type' => $filter_type, 'customer_id' => $filter_customer ?: null, 'show_deleted' => $show_deleted ?: null, 'page' => $page - 1])) ?>"
               class="btn-secondary" style="padding:0.3rem 0.7rem;font-size:0.8rem;border-radius:6px;<?= $page <= 1 ? 'opacity:0.4;pointer-events:none;' : '' ?>">‹ Prev</a>
            <?php
            $window = 2;
            $start = max(1, $page - $window);
            $end   = min($total_pages, $page + $window);
            $build_qs = function($p) use ($search_serial, $filter_type, $filter_customer, $show_deleted) {
                return http_build_query(array_filter(['serial' => $search_serial, 'type' => $filter_type, 'customer_id' => $filter_customer ?: null, 'show_deleted' => $show_deleted ?: null, 'page' => $p]));
            };
            if ($start > 1): ?>
                <a href="?<?= $build_qs(1) ?>" class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.8rem;border-radius:6px;">1</a>
                <?php if ($start > 2): ?><span style="padding:0 0.2rem;color:var(--admin-muted);font-weight:700;">…</span><?php endif;
            endif;
            for ($p = $start; $p <= $end; $p++): ?>
                <a href="?<?= $build_qs($p) ?>"
                   class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.8rem;border-radius:6px;<?= $p === $page ? 'background:var(--admin-accent);color:#fff;border-color:var(--admin-accent);' : '' ?>"><?= $p ?></a>
            <?php endfor;
            if ($end < $total_pages):
                if ($end < $total_pages - 1): ?><span style="padding:0 0.2rem;color:var(--admin-muted);font-weight:700;">…</span><?php endif; ?>
                <a href="?<?= $build_qs($total_pages) ?>" class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.8rem;border-radius:6px;"><?= $total_pages ?></a>
            <?php endif; ?>
            <a href="?<?= http_build_query(array_filter(['serial' => $search_serial, 'type' => $filter_type, 'customer_id' => $filter_customer ?: null, 'show_deleted' => $show_deleted ?: null, 'page' => $page + 1])) ?>"
               class="btn-secondary" style="padding:0.3rem 0.7rem;font-size:0.8rem;border-radius:6px;<?= $page >= $total_pages ? 'opacity:0.4;pointer-events:none;' : '' ?>">Next ›</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Live search — auto-submit form on input with debounce
var auditForm = document.querySelector('.admin-card form');
var auditSearchTimeout;
function doAuditLiveSearch() {
    clearTimeout(auditSearchTimeout);
    auditSearchTimeout = setTimeout(function() { auditForm.submit(); }, 700);
}
var auditSerial = document.querySelector('input[name="serial"]');
var auditType = document.querySelector('select[name="type"]');
var auditCustomer = document.querySelector('select[name="customer_id"]');
var auditDeleted = document.querySelector('input[name="show_deleted"]');
if (auditSerial) {
    auditSerial.addEventListener('input', doAuditLiveSearch);
    auditSerial.setSelectionRange(auditSerial.value.length, auditSerial.value.length);
}
if (auditType) auditType.addEventListener('change', doAuditLiveSearch);
if (auditCustomer) auditCustomer.addEventListener('change', doAuditLiveSearch);
if (auditDeleted) auditDeleted.addEventListener('change', doAuditLiveSearch);
</script>

<?php require_once 'layout_footer.php'; ?>
