<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('ledger.title');
$active_menu = 'supplier_ledger';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';
runCylinderSupplierMigrations($pdo);
runSupplierTypeMigration($pdo);

$message = '';
$error = '';

$supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : (isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'record_payment') {
        $sid = intval($_POST['supplier_id'] ?? 0);
        $direction = trim($_POST['direction'] ?? 'pay');
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? '');
        $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $reference = trim($_POST['reference'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $valid_methods = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'NEFT', 'RTGS', 'Online Transfer', 'Adjustment'];
        $created_by = $_SESSION['user_name'] ?? 'system';

        if ($sid <= 0) {
            $error = __('ledger.no_supplier');
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than zero.';
        } elseif (!in_array($payment_method, $valid_methods)) {
            $error = 'Invalid payment method.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT running_balance FROM supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$sid]);
                $last_bal = floatval($stmt->fetchColumn());

                if ($direction === 'pay') {
                    $credit = $amount;
                    $debit = 0;
                    $new_balance = $last_bal - $amount;
                    $type_label = 'payment';
                } else {
                    $credit = 0;
                    $debit = $amount;
                    $new_balance = $last_bal + $amount;
                    $type_label = 'advance';
                }

                $stmt = $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, transaction_date, transaction_type, debit, credit, running_balance, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sid, $payment_date . ' ' . date('H:i:s'), $type_label, $debit, $credit, $new_balance, $remarks . ($reference ? " (Ref: $reference)" : ""), $created_by]);

                $stmt = $pdo->prepare("SELECT company_name FROM cylinder_suppliers WHERE id = ?");
                $stmt->execute([$sid]);
                $supplier_name = $stmt->fetchColumn();

                $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, 'Supplier Ledger Payment', ?)");
                $stmt->execute([$created_by, "Payment of ₹$amount ($payment_method) recorded for supplier: $supplier_name"]);

                $pdo->commit();
                $message = __('ledger.payment_recorded');
                $supplier_id = $sid;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = __('common.error') . ': ' . $e->getMessage();
            }
        }
    }
}

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$type_filter = $_GET['type'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$supplier = null;
if ($supplier_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cylinder_suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();
}

$entries = [];
$total_entries = 0;
$balance = 0;

if ($supplier) {
    $stmt = $pdo->prepare("SELECT running_balance FROM supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$supplier_id]);
    $balance = floatval($stmt->fetchColumn() ?: 0);

    $where = "sl.supplier_id = ?";
    $params = [$supplier_id];

    if (!empty($from)) { $where .= " AND sl.transaction_date >= ?"; $params[] = $from . ' 00:00:00'; }
    if (!empty($to)) { $where .= " AND sl.transaction_date <= ?"; $params[] = $to . ' 23:59:59'; }
    if (!empty($type_filter)) { $where .= " AND sl.transaction_type = ?"; $params[] = $type_filter; }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_ledger sl WHERE $where");
    $stmt->execute($params);
    $total_entries = intval($stmt->fetchColumn());

    $stmt = $pdo->prepare("SELECT sl.* FROM supplier_ledger sl WHERE $where ORDER BY sl.id DESC LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

    $total_pages = max(1, ceil($total_entries / $per_page));
}

$all_suppliers = $pdo->query("SELECT id, company_name FROM cylinder_suppliers WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
?>
<style>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
.page-header-title h2 { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
.page-header-title p { color: var(--admin-muted); font-size: 0.9rem; margin: 0.25rem 0 0 0; }
.filter-bar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
.filter-bar input, .filter-bar select { padding: 0.5rem 0.75rem; border: 1px solid var(--admin-border); border-radius: 8px; font-size: 0.85rem; background: var(--admin-card); color: var(--admin-fg); }
.pagination { display: flex; justify-content: center; gap: 0.5rem; padding: 1rem; flex-wrap: wrap; }
.pagination a, .pagination span { padding: 0.4rem 0.8rem; border-radius: 6px; font-size: 0.85rem; text-decoration: none; }
.pagination a { background: var(--admin-card); border: 1px solid var(--admin-border); color: var(--admin-accent); }
.pagination a:hover { background: var(--admin-accent); color: #fff; }
.pagination .active { background: var(--admin-accent); color: #fff; font-weight: 700; }
.supplier-card { background: var(--admin-card); border: 1px solid var(--admin-border); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
.supplier-card .label { font-size: 0.75rem; color: var(--admin-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.supplier-card .value { font-size: 1.75rem; font-weight: 800; }
</style>

<div class="page-header">
    <div class="page-header-title">
        <h2><?php echo __('ledger.heading'); ?></h2>
        <p><?php echo __('ledger.title'); ?></p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <a href="cylinder-suppliers.php" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">
            <?php echo __('ledger.back_to_suppliers'); ?>
        </a>
        <a href="cylinder-purchases.php" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">
            <?php echo __('purchases.heading'); ?>
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner"><strong><?php echo __('common.success_label'); ?>:</strong> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner" style="background:var(--danger-soft);color:var(--danger);border-color:#fca5a5;"><strong><?php echo __('common.error_label'); ?>:</strong> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="GET" class="filter-bar">
    <select name="supplier_id" onchange="this.form.submit()">
        <option value=""><?php echo __('ledger.select_supplier'); ?></option>
        <?php foreach ($all_suppliers as $s): ?>
        <option value="<?php echo $s['id']; ?>" <?php echo $supplier_id === intval($s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['company_name']); ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($supplier): ?>
    <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">
    <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>">
    <select name="type">
        <option value="">All Types</option>
        <option value="purchase" <?php echo $type_filter === 'purchase' ? 'selected' : ''; ?>><?php echo __('ledger.purchase'); ?></option>
        <option value="payment" <?php echo $type_filter === 'payment' ? 'selected' : ''; ?>><?php echo __('ledger.payment'); ?></option>
        <option value="advance" <?php echo $type_filter === 'advance' ? 'selected' : ''; ?>><?php echo __('ledger.advance'); ?></option>
        <option value="adjustment" <?php echo $type_filter === 'adjustment' ? 'selected' : ''; ?>><?php echo __('ledger.adjustment'); ?></option>
    </select>
    <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">Filter</button>
    <?php endif; ?>
</form>

<?php if ($supplier): ?>

<div class="supplier-card">
    <div>
        <div class="label"><?php echo __('suppliers.company_name'); ?></div>
        <div style="font-size:1.25rem;font-weight:700;margin-top:0.25rem;"><?php echo htmlspecialchars($supplier['company_name']); ?></div>
        <div style="font-size:0.85rem;color:var(--admin-muted);margin-top:0.25rem;">
            <?php echo htmlspecialchars($supplier['mobile']); ?>
            <?php if ($supplier['gst_number']): ?> | GST: <?php echo htmlspecialchars($supplier['gst_number']); ?><?php endif; ?>
        </div>
    </div>
    <div style="text-align:right;">
        <div class="label"><?php echo __('ledger.balance'); ?></div>
        <div class="value" style="color:<?php echo $balance > 0 ? 'var(--danger)' : ($balance < 0 ? 'var(--success)' : 'var(--admin-muted)'); ?>;">
            ₹<?php echo number_format(abs($balance), 2); ?>
            <?php if ($balance > 0): ?><span style="font-size:0.85rem;color:var(--danger);">(Due)</span><?php endif; ?>
        </div>
    </div>
</div>

<div style="margin-bottom:1.5rem;display:flex;gap:0.75rem;flex-wrap:wrap;">
    <button class="btn-primary" onclick="openModal('recordPaymentModal')">
        <?php echo __('ledger.record_payment'); ?>
    </button>
</div>

<div class="admin-card" style="padding:0;">
    <div class="table-wrapper" style="border:none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?php echo __('ledger.date'); ?></th>
                    <th><?php echo __('ledger.type'); ?></th>
                    <th style="text-align:right;"><?php echo __('ledger.debit'); ?></th>
                    <th style="text-align:right;"><?php echo __('ledger.credit'); ?></th>
                    <th style="text-align:right;"><?php echo __('ledger.running_balance'); ?></th>
                    <th><?php echo __('ledger.remarks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                <tr><td colspan="7" style="text-align:center;padding:3rem 1rem;color:var(--admin-muted);"><?php echo __('ledger.no_entries'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($entries as $e): ?>
                <tr>
                    <td style="font-weight:600;font-size:0.85rem;color:var(--admin-muted);"><?php echo $e['id']; ?></td>
                    <td><?php echo date('d-m-Y', strtotime($e['transaction_date'])); ?></td>
                    <td>
                        <?php
                        $type_class = 'badge-empty';
                        $type_label = $e['transaction_type'];
                        if ($e['transaction_type'] === 'purchase') { $type_class = 'badge-filled'; $type_label = __('ledger.purchase'); }
                        elseif ($e['transaction_type'] === 'payment') { $type_class = 'badge-empty'; $type_label = __('ledger.payment'); }
                        elseif ($e['transaction_type'] === 'advance') { $type_class = 'badge-empty'; $type_label = __('ledger.advance'); }
                        elseif ($e['transaction_type'] === 'adjustment') { $type_class = 'badge-empty'; $type_label = __('ledger.adjustment'); }
                        ?>
                        <span class="badge <?php echo $type_class; ?>" style="font-size:0.7rem;"><?php echo $type_label; ?></span>
                    </td>
                    <td style="text-align:right;font-weight:600;color:var(--danger);">
                        <?php echo $e['debit'] > 0 ? '₹' . number_format($e['debit'], 2) : '—'; ?>
                    </td>
                    <td style="text-align:right;font-weight:600;color:var(--success);">
                        <?php echo $e['credit'] > 0 ? '₹' . number_format($e['credit'], 2) : '—'; ?>
                    </td>
                    <td style="text-align:right;font-weight:700;">
                        ₹<?php echo number_format($e['running_balance'], 2); ?>
                    </td>
                    <td style="font-size:0.85rem;color:var(--admin-muted);max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo htmlspecialchars($e['remarks'] ?: '-'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($total_pages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="active"><?php echo $i; ?></span>
        <?php else: ?>
            <a href="?supplier_id=<?php echo $supplier_id; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&type=<?php echo urlencode($type_filter); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<div class="modal" id="recordPaymentModal">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header">
            <h3><?php echo __('ledger.record_payment'); ?></h3>
            <button class="modal-close" onclick="closeModal('recordPaymentModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="supplier_id" value="<?php echo $supplier_id; ?>">
            <div class="form-group">
                <label class="form-label">Direction</label>
                <select name="direction" class="form-control" id="paymentDirection" onchange="toggleDirection()">
                    <option value="pay">Pay to Supplier (Credit)</option>
                    <option value="receive">Receive from Supplier (Debit / Advance)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" id="amountLabel"><?php echo __('ledger.pay_amount'); ?> *</label>
                <input type="number" step="0.01" name="amount" class="form-control" value="0.00" min="0.01" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('ledger.payment_method'); ?> *</label>
                <select name="payment_method" class="form-control" required>
                    <option value="Cash">Cash</option>
                    <option value="UPI">UPI</option>
                    <option value="Bank Transfer" selected>Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                    <option value="NEFT">NEFT</option>
                    <option value="RTGS">RTGS</option>
                    <option value="Online Transfer">Online Transfer</option>
                    <option value="Adjustment">Adjustment</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('ledger.payment_date'); ?> *</label>
                <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('ledger.reference'); ?></label>
                <input type="text" name="reference" class="form-control" placeholder="Transaction ID / Cheque No.">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('ledger.remarks'); ?></label>
                <textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes"></textarea>
            </div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;"><?php echo __('ledger.record_payment'); ?></button>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function toggleDirection() {
    const dir = document.getElementById('paymentDirection').value;
    const label = document.getElementById('amountLabel');
    label.textContent = dir === 'pay' ? '<?php echo __('ledger.pay_amount'); ?> *' : '<?php echo __('ledger.receive_amount'); ?> *';
}
</script>

<?php else: ?>
<div style="text-align:center;padding:4rem 2rem;color:var(--admin-muted);">
    <h3 style="font-size:1.25rem;margin-bottom:0.5rem;"><?php echo __('ledger.select_supplier'); ?></h3>
    <p>Select a supplier from the dropdown above to view their ledger.</p>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
