<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('purchases.title');
$from_invoice = isset($_GET['from_invoice']);
$active_menu = $from_invoice ? 'purchase_invoices_list' : 'cylinder_purchases';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/expense-utils.php';
runCylinderSupplierMigrations($pdo);
runSupplierTypeMigration($pdo);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'mark_paid') {
        $purchase_id = intval($_POST['purchase_id'] ?? 0);
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $reference = trim($_POST['reference'] ?? '');
        $gst_rate = floatval($_POST['gst_rate'] ?? 0);
        $original_gst_rate = floatval($_POST['original_gst_rate'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $valid_methods = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'NEFT', 'RTGS', 'Online Transfer', 'Adjustment'];

        if ($purchase_id <= 0 || $payment_amount <= 0) {
            $error = 'Invalid request.';
        } elseif (!in_array($payment_method, $valid_methods)) {
            $error = 'Invalid payment method.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT cp.*, cs.company_name FROM cylinder_purchases cp JOIN cylinder_suppliers cs ON cp.supplier_id = cs.id WHERE cp.id = ?");
                $stmt->execute([$purchase_id]);
                $purchase = $stmt->fetch();

                if (!$purchase) {
                    throw new Exception('Purchase record not found.');
                }
                if ($purchase['payment_status'] === 'paid') {
                    throw new Exception('Purchase is already marked as paid.');
                }

                // cylinder_purchases doesn't have paid_amount column, track through supplier_ledger
                $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(credit), 0) FROM supplier_ledger WHERE reference_type='purchase' AND reference_id=? AND transaction_type='payment'");
                $stmt2->execute([$purchase_id]);
                $prev_paid = floatval($stmt2->fetchColumn());
                $total_paid = $prev_paid + $payment_amount;
                $grand_total = floatval($purchase['grand_total']);
                $new_status = ($total_paid >= $grand_total) ? 'paid' : 'partial';

                $stmt = $pdo->prepare("UPDATE cylinder_purchases SET payment_status = ?, notes = CONCAT(COALESCE(notes,''), '\n', ?) WHERE id = ?");
                $remark = "Paid ₹" . number_format($payment_amount, 2) . " via $payment_method" . ($reference ? " (Ref: $reference)" : "") . " on $payment_date";
                $stmt->execute([$new_status, $remark, $purchase_id]);

                $stmt = $pdo->prepare("SELECT running_balance FROM supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$purchase['supplier_id']]);
                $last_bal = floatval($stmt->fetchColumn());
                $new_bal = $last_bal - $payment_amount;

                $stmt = $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, transaction_date, transaction_type, debit, credit, running_balance, reference_type, reference_id, remarks, created_by) VALUES (?, NOW(), 'payment', 0, ?, ?, 'purchase', ?, ?, ?)");
                $stmt->execute([$purchase['supplier_id'], $payment_amount, $new_bal, $purchase_id, "Payment of ₹$payment_amount for purchase #$purchase_id ($payment_method" . ($reference ? " - $reference" : "") . ")", $_SESSION['user_name'] ?? 'system']);

                // Handle GST rate change
                $gst_changed = ($gst_rate > 0 && $original_gst_rate > 0 && abs($gst_rate - $original_gst_rate) > 0.01);
                $gst_newly_set = ($gst_rate > 0 && $original_gst_rate <= 0);
                if ($gst_changed || $gst_newly_set) {
                    $stmt = $pdo->prepare("UPDATE cylinder_purchases SET gst_rate = ? WHERE id = ?");
                    $stmt->execute([$gst_rate, $purchase_id]);
                }

                $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, 'Cylinder Purchase Payment', ?)");
                $stmt->execute([$_SESSION['user_name'] ?? 'system', "Payment of ₹$payment_amount for purchase #$purchase_id from {$purchase['company_name']}"]);

                // Auto-create/update expense for cylinder purchase
                try {
                    $expense_cat_id = function_exists('resolveSystemCategory') ? resolveSystemCategory($pdo, 'Cylinder Purchase') : 0;
                    if ($expense_cat_id > 0) {
                        $stmt_ex = $pdo->prepare("SELECT id FROM expenses WHERE reference_type = 'cylinder_purchase' AND reference_id = ? AND is_deleted = 0 LIMIT 1");
                        $stmt_ex->execute([$purchase_id]);
                        $existing_expense = $stmt_ex->fetch(PDO::FETCH_ASSOC);

                        if ($existing_expense) {
                            $stmt_up = $pdo->prepare("UPDATE expenses SET payment_status = ?, payment_method = ?, notes = CONCAT(COALESCE(notes,''), '\n', ?) WHERE id = ?");
                            $stmt_up->execute([$new_status, $payment_method, $remark, $existing_expense['id']]);
                        } else {
                            autoCreateExpense($pdo, [
                                'category_id' => $expense_cat_id,
                                'vendor_id' => null,
                                'vendor_name' => $purchase['company_name'] ?? '',
                                'expense_date' => $payment_date ?: $purchase['invoice_date'],
                                'amount' => floatval($purchase['subtotal']),
                                'gst_rate' => floatval($purchase['gst_rate']),
                                'taxable_amount' => floatval($purchase['subtotal']),
                                'cgst_amount' => floatval($purchase['cgst'] ?? 0),
                                'sgst_amount' => floatval($purchase['sgst'] ?? 0),
                                'igst_amount' => floatval($purchase['igst'] ?? 0),
                                'gst_total' => floatval(($purchase['cgst'] ?? 0) + ($purchase['sgst'] ?? 0) + ($purchase['igst'] ?? 0)),
                                'total_amount' => floatval($purchase['grand_total']),
                                'payment_method' => $payment_method,
                                'payment_status' => $new_status,
                                'notes' => 'Auto-created from cylinder purchase #' . $purchase_id,
                                'reference_type' => 'cylinder_purchase',
                                'reference_id' => $purchase_id,
                                'reference_number' => $purchase['invoice_number'] ?: 'PUR-' . $purchase_id,
                                'created_by_name' => $_SESSION['user_name'] ?? 'system',
                            ]);
                        }
                    }
                } catch (Exception $ex) {
                    error_log("Auto-create expense for purchase #$purchase_id failed: " . $ex->getMessage());
                }

                $pdo->commit();
                $message = "Payment of ₹" . number_format($payment_amount, 2) . " recorded for purchase #$purchase_id.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = __('common.error') . ': ' . $e->getMessage();
            }
        }
    }
}

$supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_f = $_GET['status'] ?? '';

$sql = "SELECT cp.*, cs.company_name as supplier_name FROM cylinder_purchases cp JOIN cylinder_suppliers cs ON cp.supplier_id = cs.id WHERE 1=1";
$params = [];

if ($supplier_id > 0) {
    $sql .= " AND cp.supplier_id = ?";
    $params[] = $supplier_id;
}
if (!empty($date_from)) {
    $sql .= " AND cp.invoice_date >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $sql .= " AND cp.invoice_date <= ?";
    $params[] = $date_to;
}
if (!empty($status_f)) {
    $sql .= " AND cp.payment_status = ?";
    $params[] = $status_f;
}
$sql .= " ORDER BY cp.created_at DESC";

$purchases = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $purchases = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = __('common.error') . ': ' . $e->getMessage();
}

$suppliers = $pdo->query("SELECT id, company_name FROM cylinder_suppliers WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
?>
<style>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
.page-header-title h2 { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
.page-header-title p { color: var(--admin-muted); font-size: 0.9rem; margin: 0.25rem 0 0 0; }
.filter-bar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
.filter-bar input, .filter-bar select { padding: 0.5rem 0.75rem; border: 1px solid var(--admin-border); border-radius: 8px; font-size: 0.85rem; background: var(--admin-card); color: var(--admin-fg); }
.status-badge { font-size: 0.75rem; padding: 0.25rem 0.75rem; border-radius: 999px; font-weight: 600; }
</style>

<div class="page-header">
    <div class="page-header-title">
        <h2><?php echo __('purchases.heading'); ?></h2>
        <p><?php echo __('purchases.subtitle'); ?></p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <a href="cylinder-suppliers.php" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">
            <?php echo __('suppliers.heading'); ?>
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
    <select name="supplier_id">
        <option value=""><?php echo __('purchases.supplier'); ?> — All</option>
        <?php foreach ($suppliers as $s): ?>
        <option value="<?php echo $s['id']; ?>" <?php echo $supplier_id === intval($s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['company_name']); ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From">
    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" placeholder="To">
    <select name="status">
        <option value="">Payment — All</option>
        <option value="pending" <?php echo $status_f === 'pending' ? 'selected' : ''; ?>><?php echo __('purchases.pending'); ?></option>
        <option value="paid" <?php echo $status_f === 'paid' ? 'selected' : ''; ?>><?php echo __('purchases.paid'); ?></option>
        <option value="partial" <?php echo $status_f === 'partial' ? 'selected' : ''; ?>><?php echo __('purchases.partial'); ?></option>
    </select>
    <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">Filter</button>
    <?php if ($supplier_id || $date_from || $date_to || $status_f): ?>
    <a href="cylinder-purchases.php" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">Clear</a>
    <?php endif; ?>
</form>

<div class="admin-card" style="padding:0;">
    <div class="table-wrapper" style="border:none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?php echo __('purchases.invoice'); ?></th>
                    <th><?php echo __('purchases.supplier'); ?></th>
                    <th><?php echo __('purchases.date'); ?></th>
                    <th style="text-align:center;"><?php echo __('purchases.cylinders'); ?></th>
                    <th style="text-align:right;"><?php echo __('purchases.subtotal'); ?></th>
                    <th style="text-align:right;">GST</th>
                    <th style="text-align:right;"><?php echo __('purchases.total'); ?></th>
                    <th style="text-align:center;"><?php echo __('purchases.payment_status'); ?></th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                <tr><td colspan="10" style="text-align:center;padding:4rem 1rem;color:var(--admin-muted);"><?php echo __('purchases.no_data'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($purchases as $p): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo $p['id']; ?></td>
                    <td><?php echo htmlspecialchars($p['invoice_number'] ?: '-'); ?></td>
                    <td>
                        <a href="cylinder-supplier-ledger.php?supplier_id=<?php echo $p['supplier_id']; ?>" style="font-weight:600;color:var(--admin-accent);text-decoration:none;">
                            <?php echo htmlspecialchars($p['supplier_name']); ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars($p['invoice_date']); ?></td>
                    <td style="text-align:center;font-weight:700;"><?php echo intval($p['cylinder_count']); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($p['subtotal'], 2); ?></td>
                    <td style="text-align:right;">
                        <?php if (floatval($p['gst_rate']) > 0): ?>
                            ₹<?php echo number_format($p['cgst'] + $p['sgst'] + $p['igst'], 2); ?>
                            <span style="font-size:0.7rem;color:var(--admin-muted);">(<?php echo $p['gst_rate']; ?>%)</span>
                        <?php else: ?>
                            <span style="color:var(--admin-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:700;">₹<?php echo number_format($p['grand_total'], 2); ?></td>
                    <td style="text-align:center;">
                        <?php if ($p['payment_status'] === 'paid'): ?>
                            <span class="status-badge" style="background:var(--success-soft, #d1fae5);color:var(--success, #059669);"><?php echo __('purchases.paid'); ?></span>
                        <?php elseif ($p['payment_status'] === 'partial'): ?>
                            <span class="status-badge" style="background:#fef3c7;color:#d97706;"><?php echo __('purchases.partial'); ?></span>
                        <?php else: ?>
                            <span class="status-badge" style="background:#fef2f2;color:var(--danger);"><?php echo __('purchases.pending'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex;gap:0.5rem;justify-content:flex-end;flex-wrap:wrap;">
                            <?php if ($p['payment_status'] !== 'paid'): ?>
                            <button class="btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.75rem;border-radius:6px;background:var(--admin-accent);color:#fff;border-color:var(--admin-accent);" onclick="openPaidModal(<?php echo $p['id']; ?>, <?php echo $p['grand_total']; ?>, <?php echo floatval($p['gst_rate']); ?>)"><?php echo __('purchases.mark_paid'); ?></button>
                            <?php endif; ?>
                            <a href="cylinder-supplier-ledger.php?supplier_id=<?php echo $p['supplier_id']; ?>" class="btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.75rem;border-radius:6px;text-decoration:none;">Ledger</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="markPaidModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3><?php echo __('purchases.mark_paid'); ?></h3>
            <button class="modal-close" onclick="closeModal('markPaidModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="purchase_id" id="paid_purchase_id">
            <input type="hidden" name="original_gst_rate" id="paid_original_gst_rate">

            <div style="background:var(--admin-card-bg, #f8fafc);border-radius:8px;padding:0.75rem 1rem;margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
                    <span style="color:var(--admin-muted);">Amount Due</span>
                    <span id="paid_amount_display" style="font-size:1.25rem;font-weight:800;color:var(--admin-accent);">₹0.00</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Payment Amount *</label>
                <input type="number" name="payment_amount" id="paid_payment_amount" class="form-control" step="0.01" min="0.01" required>
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
                <label class="form-label">GST Rate (%)</label>
                <input type="number" name="gst_rate" id="paid_gst_rate" class="form-control" step="0.01" min="0" value="0" onchange="checkPurchaseGSTChange()">
                <div id="paid_gst_warning" style="display:none;margin-top:0.5rem;padding:0.5rem 0.75rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:0.78rem;color:#92400e;">
                    ⚠️ This purchase was created on <strong><span id="paid_gst_original_label">0</span>% GST</strong>.
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('purchases.notes'); ?></label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
            </div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;"><?php echo __('purchases.mark_paid'); ?></button>
        </form>
    </div>
</div>

<script>
var purchaseOriginalGst = 0;

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function openPaidModal(id, amount, gstRate) {
    document.getElementById('paid_purchase_id').value = id;
    document.getElementById('paid_original_gst_rate').value = gstRate;
    document.getElementById('paid_amount_display').textContent = '₹' + parseFloat(amount).toFixed(2);
    document.getElementById('paid_payment_amount').value = parseFloat(amount).toFixed(2);
    document.getElementById('paid_gst_rate').value = gstRate > 0 ? gstRate : '';
    document.getElementById('paid_gst_warning').style.display = 'none';
    purchaseOriginalGst = gstRate;
    openModal('markPaidModal');
}

function checkPurchaseGSTChange() {
    var newRate = parseFloat(document.getElementById('paid_gst_rate').value) || 0;
    var warn = document.getElementById('paid_gst_warning');
    var label = document.getElementById('paid_gst_original_label');
    if (purchaseOriginalGst > 0 && newRate > 0 && Math.abs(newRate - purchaseOriginalGst) > 0.01) {
        label.textContent = purchaseOriginalGst;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
