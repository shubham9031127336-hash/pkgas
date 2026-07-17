<?php
$page_title = "Bulk Operation Audit";
$active_menu = "bulk_audit";
require_once __DIR__ . '/layout.php';
require_role(['super_admin']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/bulk_operation_engine.php';

runBulkOperationAuditMigration($pdo);
purgeOldBulkOperationAudit($pdo);

$filter_op = isset($_GET['op']) ? trim($_GET['op']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 30;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];

if (!empty($filter_op) && $filter_op !== 'all') {
    $where[] = "operation_type = ?";
    $params[] = $filter_op;
}
if (!empty($filter_status) && $filter_status !== 'all') {
    $where[] = "execution_status = ?";
    $params[] = $filter_status;
}
if (!empty($search)) {
    $where[] = "(username LIKE ? OR operation_type LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM bulk_operation_audit $where_sql");
$count_stmt->execute($params);
$total = intval($count_stmt->fetchColumn());
$total_pages = max(1, ceil($total / $per_page));

$limit = (int)$per_page;
$off = (int)$offset;
$stmt = $pdo->prepare("SELECT * FROM bulk_operation_audit $where_sql ORDER BY created_at DESC LIMIT $limit OFFSET $off");
$stmt->execute($params);
$records = $stmt->fetchAll();

// Fetch distinct operation types for filter
$op_types = $pdo->query("SELECT DISTINCT operation_type FROM bulk_operation_audit ORDER BY operation_type")->fetchAll(PDO::FETCH_COLUMN);
?>
<style>
.audit-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
.audit-table th { text-align: left; padding: 0.65rem 0.75rem; background: #f8fafc; border-bottom: 2px solid #e2e8f0; font-weight: 700; color: #475569; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
.audit-table td { padding: 0.55rem 0.75rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.audit-table tr:hover td { background: #f8fafc; }
.badge-status { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.72rem; font-weight: 700; }
.badge-success { background: #d1fae5; color: #065f46; }
.badge-failed { background: #fef2f2; color: #b91c1c; }
.filter-bar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
.filter-bar select, .filter-bar input { padding: 0.45rem 0.7rem; font-size: 0.82rem; border-radius: 8px; border: 1px solid #e2e8f0; }
.pagination { display: flex; gap: 0.35rem; justify-content: center; margin-top: 1.5rem; }
.pagination a, .pagination span { padding: 0.35rem 0.7rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.82rem; text-decoration: none; color: #475569; }
.pagination a:hover { background: #f1f5f9; }
.pagination .current { background: #2563eb; color: #fff; border-color: #2563eb; }
</style>

<div style="margin-bottom:1.5rem;">
    <h2 style="font-size:1.5rem;font-weight:800;letter-spacing:-0.02em;">Bulk Operation Audit</h2>
    <p style="color:#64748b;font-size:0.85rem;margin-top:0.25rem;">All bulk operations with impact analysis and execution status.</p>
</div>

<div class="filter-bar">
    <form method="GET" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
        <select name="op">
            <option value="">All Operations</option>
            <?php foreach ($op_types as $ot): ?>
            <option value="<?php echo htmlspecialchars($ot); ?>"<?php echo $filter_op === $ot ? ' selected' : ''; ?>><?php echo htmlspecialchars($ot); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Status</option>
            <option value="success"<?php echo $filter_status === 'success' ? ' selected' : ''; ?>>Success</option>
            <option value="failed"<?php echo $filter_status === 'failed' ? ' selected' : ''; ?>>Failed</option>
        </select>
        <input type="text" name="search" placeholder="Search user or operation..." value="<?php echo htmlspecialchars($search); ?>" style="min-width:200px;">
        <button type="submit" class="btn-primary" style="padding:0.45rem 1rem;font-size:0.82rem;">Filter</button>
        <a href="bulk_operation_audit_view.php?format=print" class="btn-secondary" style="padding:0.45rem 1rem;font-size:0.82rem;text-decoration:none;" onclick="window.print();return false;">Print</a>
    </form>
</div>

<?php if (empty($records)): ?>
<div class="empty-state" style="text-align:center;padding:3rem 1rem;color:#64748b;">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:0.75rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    <h4>No audit records found</h4>
    <p>Bulk operations will appear here after they are performed.</p>
</div>
<?php else: ?>
<table class="audit-table">
    <thead>
        <tr>
            <th>Date/Time</th>
            <th>User</th>
            <th>Operation</th>
            <th>Records</th>
            <th>Processed</th>
            <th>Skipped</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($records as $r):
            $impact = json_decode($r['impact_summary'], true) ?: [];
            $financial = $impact['financial'] ?? [];
        ?>
        <tr>
            <td style="white-space:nowrap;"><?php echo date('d M Y H:i', strtotime($r['created_at'])); ?></td>
            <td><strong><?php echo htmlspecialchars($r['username'] ?? 'N/A'); ?></strong></td>
            <td><span style="font-weight:600;"><?php echo htmlspecialchars($r['operation_type']); ?></span></td>
            <td style="text-align:center;"><?php echo intval($r['record_count']); ?></td>
            <td style="text-align:center;font-weight:600;color:#059669;"><?php echo intval($r['processed_count']); ?></td>
            <td style="text-align:center;<?php echo intval($r['skipped_count']) > 0 ? 'color:#d97706;font-weight:600;' : ''; ?>"><?php echo intval($r['skipped_count']); ?></td>
            <td><span class="badge-status badge-<?php echo $r['execution_status']; ?>"><?php echo $r['execution_status']; ?></span></td>
            <td><button class="btn-secondary" style="padding:0.3rem 0.75rem;font-size:0.75rem;" onclick="showAuditDetail(<?php echo intval($r['id']); ?>)">View Details</button></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total_pages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
    <a href="?page=<?php echo $i; ?>&op=<?php echo urlencode($filter_op); ?>&status=<?php echo urlencode($filter_status); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i === $page ? 'current' : ''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Detail Modal -->
<div class="modal" id="auditDetailModal">
    <div class="modal-content" style="max-width:700px;border-radius:12px;">
        <div class="modal-header">
            <h3 style="margin:0;font-size:1rem;">Audit Record Details</h3>
            <button class="modal-close" onclick="closeModal('auditDetailModal')">&times;</button>
        </div>
        <div id="auditDetailBody" style="padding:1.25rem;max-height:60vh;overflow-y:auto;font-size:0.82rem;"></div>
        <div style="border-top:1px solid #e2e8f0;padding:0.75rem 1.25rem;text-align:right;">
            <button class="btn-secondary" onclick="closeModal('auditDetailModal')" style="padding:0.4rem 1rem;border-radius:8px;">Close</button>
        </div>
    </div>
</div>

<script>
function showAuditDetail(id) {
    var body = document.getElementById('auditDetailBody');
    body.innerHTML = '<div style="text-align:center;padding:2rem;color:#64748b;">Loading...</div>';
    openModal('auditDetailModal');

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_audit_detail&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.record) {
            var r = data.record;
            var html = '';
            html += '<table style="width:100%;border-collapse:collapse;">';
            html += '<tr><td style="padding:0.35rem 0.5rem;font-weight:600;width:140px;">Date</td><td style="padding:0.35rem 0.5rem;">' + r.created_at + '</td></tr>';
            html += '<tr><td style="padding:0.35rem 0.5rem;font-weight:600;">User</td><td style="padding:0.35rem 0.5rem;">' + (r.username || 'N/A') + '</td></tr>';
            html += '<tr><td style="padding:0.35rem 0.5rem;font-weight:600;">Operation</td><td style="padding:0.35rem 0.5rem;">' + r.operation_type + '</td></tr>';
            html += '<tr><td style="padding:0.35rem 0.5rem;font-weight:600;">Status</td><td style="padding:0.35rem 0.5rem;"><span class="badge-status badge-' + r.execution_status + '">' + r.execution_status + '</span></td></tr>';
            html += '<tr><td style="padding:0.35rem 0.5rem;font-weight:600;">Records</td><td style="padding:0.35rem 0.5rem;">' + r.record_count + ' selected, ' + r.processed_count + ' processed, ' + r.skipped_count + ' skipped</td></tr>';
            if (r.error_message) html += '<tr><td style="padding:0.35rem 0.5rem;font-weight:600;color:#b91c1c;">Error</td><td style="padding:0.35rem 0.5rem;color:#b91c1c;">' + r.error_message + '</td></tr>';
            html += '</table>';

            // Impact summary
            if (r.impact_summary) {
                try {
                    var is = JSON.parse(r.impact_summary);
                    html += '<div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #e2e8f0;">';
                    html += '<strong style="font-size:0.9rem;">Impact Summary</strong>';
                    html += '<pre style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:0.75rem;margin-top:0.5rem;font-size:0.75rem;max-height:300px;overflow-y:auto;white-space:pre-wrap;">' + JSON.stringify(is, null, 2) + '</pre>';
                    html += '</div>';
                } catch(e) {}
            }

            body.innerHTML = html;
        } else {
            body.innerHTML = '<div style="color:#b91c1c;">Failed to load record details.</div>';
        }
    })
    .catch(function(err) {
        body.innerHTML = '<div style="color:#b91c1c;">Error: ' + err.message + '</div>';
    });
}

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
</script>

<?php
// ── AJAX: Get audit detail ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_audit_detail') {
    ob_clean();
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM bulk_operation_audit WHERE id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    if ($record) {
        echo json_encode(['success' => true, 'record' => $record]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Record not found']);
    }
    exit();
}

require_once __DIR__ . '/layout_footer.php';
?>
