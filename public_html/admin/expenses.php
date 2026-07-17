<?php
$page_title = "All Expenses";
$active_menu = "expenses";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/expense-utils.php';
runExpenseMigrations($pdo);

$message = $_SESSION['expense_flash'] ?? '';
$error = $_SESSION['expense_error'] ?? '';
unset($_SESSION['expense_flash'], $_SESSION['expense_error']);

// ─── POST Handlers ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'delete_expense') {
        $id = intval($_POST['id'] ?? 0);
        if ($id && softDeleteExpense($pdo, $id)) {
            $_SESSION['success_flash'] = "Expense deleted successfully.";
        } else {
            $_SESSION['error_flash'] = "Failed to delete expense.";
        }
        echo "<script>window.location.href='expenses.php';</script>";
        exit();
    }

    if ($action === 'quick_status') {
        $id = intval($_POST['expense_id'] ?? 0);
        $new_status = $_POST['payment_status'] ?? '';
        $new_method = trim($_POST['payment_method'] ?? '');
        $valid_statuses = ['paid', 'unpaid', 'partial'];
        if ($id && in_array($new_status, $valid_statuses)) {
            $stmt = $pdo->prepare("UPDATE expenses SET payment_status = ?, payment_method = COALESCE(NULLIF(?, ''), payment_method) WHERE id = ? AND is_deleted = 0");
            $stmt->execute([$new_status, $new_method, $id]);
            if ($stmt->rowCount()) {
                syncExpenseGST($pdo, $id);
                syncExpensePaymentStatus($pdo, $id);
                $_SESSION['success_flash'] = "Payment status updated.";
            } else {
                $_SESSION['error_flash'] = "Expense not found.";
            }
        } else {
            $_SESSION['error_flash'] = "Invalid request.";
        }
        echo "<script>window.location.href='expenses.php';</script>";
        exit();
    }

    if ($action === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="expenses_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Expense#', 'Date', 'Category', 'Group', 'Vendor', 'Amount', 'GST Rate', 'GST Total', 'Total Amount', 'Payment Method', 'Payment Status', 'Warehouse', 'Notes']);
        $where = buildFilterWhere();
        $stmt = $pdo->query("SELECT e.*, ec.name AS cat_name, ecg.name AS group_name FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id JOIN expense_category_groups ecg ON ec.group_id = ecg.id $where ORDER BY e.expense_date DESC, e.id DESC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [$row['expense_number'], $row['expense_date'], $row['cat_name'], $row['group_name'], $row['vendor_name'] ?: 'N/A', $row['amount'], $row['gst_rate'] . '%', $row['gst_total'], $row['total_amount'], $row['payment_method'], $row['payment_status'], $row['warehouse_branch'] ?: '', $row['notes'] ? strip_tags($row['notes']) : '']);
        }
        fclose($output);
        exit();
    }
}

// ─── Filters ───
$cat_f = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$vendor_f = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_f = $_GET['status'] ?? '';
$method_f = $_GET['method'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

function buildFilterWhere() {
    global $cat_f, $vendor_f, $date_from, $date_to, $status_f, $method_f, $search, $pdo;
    $where = "WHERE e.is_deleted = 0";
    if ($cat_f > 0) $where .= " AND e.category_id = " . intval($cat_f);
    if ($vendor_f > 0) $where .= " AND e.vendor_id = " . intval($vendor_f);
    if ($date_from) $where .= " AND e.expense_date >= " . $pdo->quote($date_from);
    if ($date_to) $where .= " AND e.expense_date <= " . $pdo->quote($date_to);
    if ($status_f) $where .= " AND e.payment_status = " . $pdo->quote($status_f);
    if ($method_f) $where .= " AND e.payment_method = " . $pdo->quote($method_f);
    if ($search) $where .= " AND (e.expense_number LIKE " . $pdo->quote('%' . $search . '%') . " OR e.vendor_name LIKE " . $pdo->quote('%' . $search . '%') . " OR e.notes LIKE " . $pdo->quote('%' . $search . '%') . ")";
    return $where;
}

// Stats
$stats = getExpenseStats($pdo);
$categories_list = $pdo->query("SELECT c.id, c.name, g.name AS group_name FROM expense_categories c JOIN expense_category_groups g ON c.group_id = g.id WHERE c.is_active = 1 ORDER BY g.display_order ASC, c.name ASC")->fetchAll();
$vendors_list = $pdo->query("SELECT id, name FROM vendors ORDER BY name ASC")->fetchAll();

// Main query
$where = buildFilterWhere();
$count_stmt = $pdo->query("SELECT COUNT(*) FROM expenses e $where");
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_rows / $per_page));

$stmt = $pdo->query("SELECT e.*, ec.name AS cat_name, ecg.name AS group_name FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id JOIN expense_category_groups ecg ON ec.group_id = ecg.id $where ORDER BY e.expense_date DESC, e.id DESC LIMIT $per_page OFFSET $offset");
$expenses = $stmt->fetchAll();
?>
<link rel="stylesheet" href="expense.css">

<div class="page-header">
    <div class="page-header-title">
        <h2>All Expenses</h2>
        <p>Track and manage all business expenses</p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <a href="expense-create.php" class="btn-primary" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Expense
        </a>
        <a href="expense-categories.php" class="btn-secondary" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">Categories</a>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner" style="background:var(--danger-soft);color:var(--danger);border-color:#fca5a5;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="expense-stat-grid">
    <div class="expense-stat-card"><div class="stat-label">Today's Expense</div><div class="stat-value" style="color:#3b82f6;">₹<?= number_format($stats['today'], 2) ?></div></div>
    <div class="expense-stat-card"><div class="stat-label">This Month</div><div class="stat-value" style="color:#10b981;">₹<?= number_format($stats['month'], 2) ?></div></div>
    <div class="expense-stat-card"><div class="stat-label">This Year</div><div class="stat-value">₹<?= number_format($stats['year'], 2) ?></div></div>
    <div class="expense-stat-card"><div class="stat-label">Pending Bills</div><div class="stat-value" style="color:#ef4444;">₹<?= number_format($stats['pending_amount'], 2) ?> <span style="font-size:0.8rem;font-weight:600;">(<?= $stats['pending_count'] ?>)</span></div></div>
</div>

<div class="filter-bar-wrap">
    <form method="GET" class="filter-bar-compact">
        <input type="text" name="search" placeholder="Search expense #, vendor..." value="<?= htmlspecialchars($search) ?>">
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" placeholder="From">
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" placeholder="To">
        <select name="status">
            <option value="">All Status</option>
            <option value="paid" <?= $status_f === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="unpaid" <?= $status_f === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
            <option value="partial" <?= $status_f === 'partial' ? 'selected' : '' ?>>Partial</option>
        </select>
        <select name="category_id">
            <option value="">All Categories</option>
            <?php $cg = ''; foreach ($categories_list as $c):
                if ($c['group_name'] !== $cg): $cg = $c['group_name']; ?>
                <optgroup label="<?= htmlspecialchars($cg) ?>">
            <?php endif; ?>
                <option value="<?= $c['id'] ?>" <?= $cat_f === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="vendor_id">
            <option value="">All Vendors</option>
            <?php foreach ($vendors_list as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $vendor_f === $v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="method">
            <option value="">All Methods</option>
            <option value="Cash" <?= $method_f === 'Cash' ? 'selected' : '' ?>>Cash</option>
            <option value="Bank" <?= $method_f === 'Bank' ? 'selected' : '' ?>>Bank</option>
            <option value="UPI" <?= $method_f === 'UPI' ? 'selected' : '' ?>>UPI</option>
            <option value="Credit" <?= $method_f === 'Credit' ? 'selected' : '' ?>>Credit</option>
            <option value="Cheque" <?= $method_f === 'Cheque' ? 'selected' : '' ?>>Cheque</option>
        </select>
        <button type="submit" class="btn-sm-filter">Filter</button>
        <?php if ($cat_f || $vendor_f || $date_from || $date_to || $status_f || $method_f || $search): ?>
        <a href="expenses.php" class="btn-sm-filter">Clear</a>
        <?php endif; ?>
    </form>
    <form method="POST" class="csv-form">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="export_csv">
        <button type="submit" class="btn-sm-filter" style="color:var(--admin-accent);">CSV</button>
    </form>
</div>

<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Expense #</th>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Vendor</th>
                    <th style="text-align:right;">Amount</th>
                    <th style="text-align:right;">GST</th>
                    <th style="text-align:right;">Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                <tr><td colspan="10" style="text-align:center;padding:4rem 1rem;color:var(--admin-muted);">No expenses found. <a href="expense-create.php" style="color:var(--admin-accent);">Add your first expense</a></td></tr>
                <?php endif; ?>
                <?php foreach ($expenses as $e): ?>
                <tr class="expense-row-<?= $e['payment_status'] ?>">
                    <td style="font-weight:700;font-size:0.82rem;"><?= htmlspecialchars($e['expense_number']) ?></td>
                    <td><?= htmlspecialchars($e['expense_date']) ?></td>
                    <td>
                        <span style="font-weight:600;"><?= htmlspecialchars($e['cat_name']) ?></span>
                        <span style="font-size:0.7rem;color:var(--admin-muted);display:block;"><?= htmlspecialchars($e['group_name']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($e['vendor_name'] ?: '-') ?></td>
                    <td style="text-align:right;">₹<?= number_format($e['amount'], 2) ?></td>
                    <td style="text-align:right;">
                        <?php if ($e['gst_rate'] > 0): ?>
                            ₹<?= number_format($e['gst_total'], 2) ?>
                            <span style="font-size:0.7rem;color:var(--admin-muted);">(<?= $e['gst_rate'] ?>%)</span>
                        <?php else: ?>
                            <span style="color:var(--admin-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:700;">₹<?= number_format($e['total_amount'], 2) ?></td>
                    <td style="font-size:0.82rem;"><?= htmlspecialchars($e['payment_method']) ?></td>
                    <td>
                        <?php if ($e['payment_status'] === 'paid'): ?>
                            <span class="status-badge clickable-status" style="background:#d1fae5;color:#059669;cursor:pointer;" onclick="openStatusModal(<?= $e['id'] ?>, '<?= $e['payment_status'] ?>', '<?= htmlspecialchars($e['expense_number']) ?>', '<?= htmlspecialchars($e['payment_method']) ?>')">Paid</span>
                        <?php elseif ($e['payment_status'] === 'partial'): ?>
                            <span class="status-badge clickable-status" style="background:#fef3c7;color:#d97706;cursor:pointer;" onclick="openStatusModal(<?= $e['id'] ?>, '<?= $e['payment_status'] ?>', '<?= htmlspecialchars($e['expense_number']) ?>', '<?= htmlspecialchars($e['payment_method']) ?>')">Partial</span>
                        <?php else: ?>
                            <span class="status-badge clickable-status" style="background:#fef2f2;color:#dc2626;cursor:pointer;" onclick="openStatusModal(<?= $e['id'] ?>, '<?= $e['payment_status'] ?>', '<?= htmlspecialchars($e['expense_number']) ?>', '<?= htmlspecialchars($e['payment_method']) ?>')">Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex;gap:0.4rem;justify-content:flex-end;">
                            <a href="expense-create.php?id=<?= $e['id'] ?>" class="btn-secondary" style="padding:0.25rem 0.5rem;font-size:0.72rem;border-radius:6px;text-decoration:none;">Edit</a>
                            <?php if ($e['payment_status'] !== 'paid'): ?>
                            <button class="btn-secondary" style="padding:0.25rem 0.5rem;font-size:0.72rem;border-radius:6px;background:#059669;color:#fff;border-color:#059669;" onclick="openStatusModal(<?= $e['id'] ?>, '<?= $e['payment_status'] ?>', '<?= htmlspecialchars($e['expense_number']) ?>', '<?= htmlspecialchars($e['payment_method']) ?>')">Mark Paid</button>
                            <?php endif; ?>
                            <?php if (has_role('super_admin')): ?>
                            <button class="btn-secondary" style="padding:0.25rem 0.5rem;font-size:0.72rem;border-radius:6px;color:#dc2626;border-color:#fca5a5;" onclick="confirmDelete(<?= $e['id'] ?>, '<?= htmlspecialchars($e['expense_number']) ?>')">Delete</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($total_pages > 1): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-top:1.5rem;font-size:0.85rem;">
    <span style="color:var(--admin-muted);">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?></span>
    <div style="display:flex;gap:0.5rem;">
        <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter(['category_id' => $cat_f, 'vendor_id' => $vendor_f, 'date_from' => $date_from, 'date_to' => $date_to, 'status' => $status_f, 'method' => $method_f, 'search' => $search])) ?>" class="btn-secondary" style="padding:0.4rem 0.8rem;border-radius:6px;font-size:0.82rem;text-decoration:none;">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter(['category_id' => $cat_f, 'vendor_id' => $vendor_f, 'date_from' => $date_from, 'date_to' => $date_to, 'status' => $status_f, 'method' => $method_f, 'search' => $search])) ?>" class="btn-secondary" style="padding:0.4rem 0.8rem;border-radius:6px;font-size:0.82rem;text-decoration:none;">Next →</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Delete Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header"><h3>Delete Expense</h3><button class="modal-close" onclick="closeModal('deleteModal')">&times;</button></div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_expense">
            <input type="hidden" name="id" id="delete_id">
            <p style="margin-bottom:1rem;">Are you sure you want to delete <strong id="delete_number_display"></strong>?</p>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:#dc2626;margin-bottom:1rem;">
                This will remove this expense and its GST entries. This action cannot be undone.
            </div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;background:#dc2626;">Delete Expense</button>
        </form>
    </div>
</div>

<!-- Quick Status Modal -->
<div class="modal" id="statusModal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header"><h3>Update Payment Status</h3><button class="modal-close" onclick="closeModal('statusModal')">&times;</button></div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="quick_status">
            <input type="hidden" name="expense_id" id="status_expense_id">
            <p style="margin-bottom:1rem;font-size:0.85rem;color:var(--admin-muted);">
                Updating status for <strong id="status_number_display"></strong>
            </p>
            <div class="form-group">
                <label class="form-label">Payment Status</label>
                <select name="payment_status" id="status_payment_status" class="form-control" required>
                    <option value="paid">Paid</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="partial">Partial</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select name="payment_method" id="status_payment_method" class="form-control">
                    <option value="">— Keep Current —</option>
                    <option value="Cash">Cash</option>
                    <option value="Bank">Bank</option>
                    <option value="UPI">UPI</option>
                    <option value="Credit">Credit</option>
                    <option value="Cheque">Cheque</option>
                </select>
            </div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:0.75rem 1rem;font-size:0.82rem;color:#166534;margin-bottom:1rem;">
                This will also update related records (cylinder purchases, supplier ledger, vendor invoices).
            </div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;background:var(--admin-accent);">Update Status</button>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function confirmDelete(id, number) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_number_display').textContent = number;
    openModal('deleteModal');
}
function openStatusModal(id, currentStatus, number, method) {
    document.getElementById('status_expense_id').value = id;
    document.getElementById('status_number_display').textContent = number;
    document.getElementById('status_payment_status').value = currentStatus;
    document.getElementById('status_payment_method').value = method;
    openModal('statusModal');
}
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
