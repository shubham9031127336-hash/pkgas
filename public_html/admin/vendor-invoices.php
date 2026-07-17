<?php
$page_title = "Vendor Invoices";
$from_invoice = isset($_GET['from_invoice']);
$active_menu = $from_invoice ? 'vendor_invoices_list' : 'vendor_invoices';
$url_suffix = $from_invoice ? '&from_invoice=1' : '';
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once 'db.php';
require_once 'inventory-utils.php';
require_once 'gst_helper.php';
require_once 'csrf.php';

runVendorInvoiceMigrations($pdo);
runGSTMigrations($pdo);

// ── POST: Pay Vendor Invoice ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_vendor_invoice') {
    validateCsrfToken();
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $gst_rate = floatval($_POST['gst_rate'] ?? 0);
    $original_gst_rate = floatval($_POST['original_gst_rate'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $valid_methods = ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'NEFT', 'RTGS'];

    if ($invoice_id <= 0 || $payment_amount <= 0) {
        $error = 'Invalid request.';
    } elseif (!in_array($payment_method, $valid_methods)) {
        $error = 'Invalid payment method.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM vendor_invoices WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $inv = $stmt->fetch();
            if (!$inv) throw new Exception("Invoice not found.");
            if ($inv['payment_status'] === 'paid') throw new Exception("Invoice is already paid.");

            $new_paid = floatval($inv['paid_amount']) + $payment_amount;
            $new_balance = max(0, floatval($inv['grand_total']) - $new_paid);
            $new_status = ($new_balance <= 0) ? 'paid' : 'partial';

            $stmt = $pdo->prepare("UPDATE vendor_invoices SET paid_amount = ?, balance = ?, payment_status = ? WHERE id = ?");
            $stmt->execute([$new_paid, $new_balance, $new_status, $invoice_id]);

            // Record payment
            $stmt = $pdo->prepare("INSERT INTO payments (vendor_id, amount, payment_method, payment_type, notes, payment_date) VALUES (?, ?, ?, 'vendor_payment', ?, ?)");
            $pay_notes = "Payment for vendor invoice #{$inv['invoice_number']}" . ($notes ? " - $notes" : "");
            $stmt->execute([$inv['vendor_id'], $payment_amount, $payment_method, $pay_notes, $payment_date]);

            // Handle GST rate change
            $gst_changed = ($gst_rate > 0 && $original_gst_rate > 0 && abs($gst_rate - $original_gst_rate) > 0.01);
            if ($gst_changed) {
                $stmt = $pdo->prepare("UPDATE vendor_invoices SET gst_rate = ? WHERE id = ?");
                $stmt->execute([$gst_rate, $invoice_id]);
                syncGSTFromVendorInvoice($pdo, $invoice_id);
            }

            // Sync the associated dispatch lot if this invoice is linked to one
            if (!empty($inv['lot_id'])) {
                recalcLotFinancials($pdo, $inv['lot_id']);
            }

            $pdo->commit();
            $_SESSION['vi_flash'] = "Payment of ₹" . number_format($payment_amount, 2) . " recorded for {$inv['invoice_number']}. Balance: ₹" . number_format($new_balance, 2);
            echo "<script>window.location.href='vendor-invoices.php';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['vi_error'] = $e->getMessage();
            echo "<script>window.location.href='vendor-invoices.php';</script>";
            exit();
        }
    }
}

$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;
$status_filter = trim($_GET['status'] ?? '');
$from_date = trim($_GET['from'] ?? '');
$to_date = trim($_GET['to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

$flash = $_SESSION['vi_flash'] ?? '';
$error = $_SESSION['vi_error'] ?? '';
unset($_SESSION['vi_flash'], $_SESSION['vi_error']);

$where = "WHERE 1=1";
$params = [];

if ($vendor_id > 0) {
    $where .= " AND vi.vendor_id = ?";
    $params[] = $vendor_id;
}
if ($status_filter !== '') {
    $where .= " AND vi.payment_status = ?";
    $params[] = $status_filter;
}
if ($from_date) {
    $where .= " AND vi.invoice_date >= ?";
    $params[] = $from_date;
}
if ($to_date) {
    $where .= " AND vi.invoice_date <= ?";
    $params[] = $to_date;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_invoices vi $where");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    $pages = max(1, ceil($total / $per_page));
    $offset = ($page - 1) * $per_page;

    $stmt = $pdo->prepare("
        SELECT vi.*, v.name AS vendor_name, v.mobile AS vendor_phone
        FROM vendor_invoices vi
        JOIN vendors v ON vi.vendor_id = v.id
        $where
        ORDER BY vi.invoice_date DESC, vi.id DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT COUNT(*) FROM vendor_invoices");
    $total_count = intval($stmt->fetchColumn());
    $stmt = $pdo->query("SELECT COALESCE(SUM(grand_total), 0) FROM vendor_invoices");
    $total_amount = floatval($stmt->fetchColumn());
    $stmt = $pdo->query("SELECT COALESCE(SUM(gst_amount), 0) FROM vendor_invoices");
    $total_gst = floatval($stmt->fetchColumn());
    $stmt = $pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM vendor_invoices");
    $total_paid = floatval($stmt->fetchColumn());
    $stmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) FROM vendor_invoices");
    $total_balance = floatval($stmt->fetchColumn());
    $stmt = $pdo->query("SELECT COUNT(*) FROM vendor_invoices WHERE payment_status = 'unpaid'");
    $unpaid_count = intval($stmt->fetchColumn());
    $stmt = $pdo->query("SELECT COUNT(*) FROM vendor_invoices WHERE payment_status = 'partial'");
    $partial_count = intval($stmt->fetchColumn());
    $stmt = $pdo->query("SELECT COUNT(*) FROM vendor_invoices WHERE payment_status = 'paid'");
    $paid_count = intval($stmt->fetchColumn());

    $vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $invoices = [];
    $total_count = 0;
    $total_amount = 0;
    $total_gst = 0;
    $total_paid = 0;
    $total_balance = 0;
    $unpaid_count = 0;
    $partial_count = 0;
    $paid_count = 0;
    $vendors = [];
}
?>
<?php if ($flash): ?>
<div class="ci-alert ci-success"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="ci-alert ci-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="ci-top-wrap">
    <div class="ci-top-row">
        <h1 class="ci-h1">Vendor Invoices</h1>
        <div class="ci-tools">
            <div class="ci-srch"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="viSearch" placeholder="Search invoice or vendor..." onkeyup="filterInvoices()"></div>
            <select id="vendorFilter" onchange="applyFilter()" class="ci-select"><option value="">All Vendors</option><?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>" <?= $vendor_id === intval($v['id']) ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?></select>
            <input type="date" id="fromDate" value="<?= $from_date ?>" onchange="applyFilter()" class="ci-d">
            <input type="date" id="toDate" value="<?= $to_date ?>" onchange="applyFilter()" class="ci-d">
            <a href="vendor-invoice-create.php" class="ci-btn-new"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Generate Invoice</a>
        </div>
    </div>
    <div class="ci-mid-row">
        <div class="ci-stats">
            <span class="ci-st-i"><span class="ci-dot ci-dot-blue"></span><strong><?= $total_count ?></strong> Invoices</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-amber"></span><strong>₹<?= number_format($total_amount, 0) ?></strong> Total</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-purple"></span><strong>₹<?= number_format($total_gst, 0) ?></strong> GST (ITC)</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot <?= $total_balance > 0 ? 'ci-dot-red' : 'ci-dot-green' ?>"></span><strong>₹<?= number_format($total_balance, 0) ?></strong> Due</span>
        </div>
        <div class="ci-chips">
            <a href="<?= $from_invoice ? '?from_invoice=1' : '?' ?>" class="ci-ch <?= $status_filter === '' ? 'on' : '' ?>">All <span><?= $total_count ?></span></a>
            <a href="?status=unpaid<?= $vendor_id > 0 ? '&vendor_id=' . $vendor_id : '' ?><?= $url_suffix ?>" class="ci-ch <?= $status_filter === 'unpaid' ? 'on' : '' ?>">Unpaid <span><?= $unpaid_count ?></span></a>
            <a href="?status=partial<?= $vendor_id > 0 ? '&vendor_id=' . $vendor_id : '' ?><?= $url_suffix ?>" class="ci-ch <?= $status_filter === 'partial' ? 'on' : '' ?>">Partial <span><?= $partial_count ?></span></a>
            <a href="?status=paid<?= $vendor_id > 0 ? '&vendor_id=' . $vendor_id : '' ?><?= $url_suffix ?>" class="ci-ch <?= $status_filter === 'paid' ? 'on' : '' ?>">Paid <span><?= $paid_count ?></span></a>
        </div>
    </div>
</div>

<div class="admin-card" style="padding:0;overflow:auto;">
    <?php if (empty($invoices)): ?>
    <div style="text-align:center;padding:3rem 1rem;color:var(--admin-muted);">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.35;margin-bottom:1rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <p style="font-size:0.95rem;font-weight:600;">No vendor invoices found</p>
        <p style="font-size:0.82rem;margin-top:0.35rem;">Generate your first invoice from a dispatch lot.</p>
        <a href="vendor-invoice-create.php" class="btn-primary" style="display:inline-flex;align-items:center;gap:0.4rem;margin-top:1rem;padding:0.55rem 1.25rem;border-radius:10px;background:var(--admin-accent);color:#fff;text-decoration:none;font-weight:700;">Generate Invoice</a>
    </div>
    <?php else: ?>
    <table class="admin-table" id="viTable">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Vendor</th>
                <th>Date</th>
                <th style="text-align:right;">Amount</th>
                <th style="text-align:right;">GST</th>
                <th style="text-align:right;">Grand Total</th>
                <th style="text-align:right;">Paid</th>
                <th style="text-align:right;">Balance</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $inv): 
                $status_class = $inv['payment_status'] === 'paid' ? 'badge-paid' : ($inv['payment_status'] === 'partial' ? 'badge-partial' : 'badge-unpaid');
            ?>
            <tr class="vi-row" data-search="<?= strtolower($inv['invoice_number'] . ' ' . $inv['vendor_name']) ?>">
                <td><a href="vendor-invoice.php?id=<?= $inv['id'] ?>" class="inv-ref-link"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
                <td><strong><?= htmlspecialchars($inv['vendor_name']) ?></strong></td>
                <td style="font-size:0.82rem;color:var(--admin-muted);"><?= date('d-M-Y', strtotime($inv['invoice_date'])) ?></td>
                <td style="text-align:right;">₹<?= number_format($inv['subtotal'], 0) ?></td>
                <td style="text-align:right;">₹<?= number_format($inv['gst_amount'], 0) ?></td>
                <td style="text-align:right;font-weight:700;">₹<?= number_format($inv['grand_total'], 0) ?></td>
                <td style="text-align:right;color:#059669;">₹<?= number_format($inv['paid_amount'], 0) ?></td>
                <td style="text-align:right;font-weight:700;color:<?= $inv['balance'] > 0 ? '#dc2626' : '#059669' ?>;">₹<?= number_format($inv['balance'], 0) ?></td>
                <td><span class="badge <?= $status_class ?>"><?= ucfirst($inv['payment_status']) ?></span></td>
                <td>
                    <div class="inv-actions" style="gap:0.35rem;">
                        <?php if ($inv['payment_status'] !== 'paid'): ?>
                        <button class="btn-primary" style="padding:0.3rem 0.65rem;font-size:0.72rem;border-radius:6px;border:none;cursor:pointer;" onclick="openVendorPayModal(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number']) ?>', '<?= htmlspecialchars($inv['vendor_name']) ?>', <?= $inv['grand_total'] ?>, <?= $inv['paid_amount'] ?>, <?= $inv['balance'] ?>, <?= floatval($inv['gst_rate']) ?>)">Pay</button>
                        <?php endif; ?>
                        <a href="vendor-invoice.php?id=<?= $inv['id'] ?>" class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.72rem;border-radius:6px;text-decoration:none;">View</a>
                        <a href="vendor-invoice.php?id=<?= $inv['id'] ?>&print=1" onclick="window.open('vendor-invoice.php?id=<?= $inv['id'] ?>','_blank');return false;" class="btn-secondary" style="padding:0.3rem 0.65rem;font-size:0.72rem;border-radius:6px;text-decoration:none;">Print</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div style="display:flex;justify-content:center;gap:0.5rem;padding:1rem;border-top:1px solid var(--admin-border);">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&vendor_id=<?= $vendor_id ?><?= $url_suffix ?>" class="btn-secondary" style="padding:0.35rem 0.75rem;font-size:0.8rem;border-radius:6px;text-decoration:none;<?= $i === $page ? 'background:var(--admin-accent);color:#fff;border-color:var(--admin-accent);' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function applyFilter() {
    var vendor = document.getElementById('vendorFilter').value;
    var status = '<?= $status_filter ?>';
    var from = document.getElementById('fromDate').value;
    var to = document.getElementById('toDate').value;
    var params = [];
    <?php if ($from_invoice): ?>params.push('from_invoice=1');<?php endif; ?>
    if (vendor) params.push('vendor_id=' + vendor);
    if (status) params.push('status=' + status);
    if (from) params.push('from=' + from);
    if (to) params.push('to=' + to);
    window.location.href = '?' + params.join('&');
}

function filterInvoices() {
    var q = document.getElementById('viSearch').value.toLowerCase();
    document.querySelectorAll('.vi-row').forEach(function(row) {
        var search = row.getAttribute('data-search') || '';
        row.style.display = search.indexOf(q) > -1 ? '' : 'none';
    });
}

// Vendor Pay Modal
let vendorPayOriginalGst = 0;

function openVendorPayModal(id, invNo, vendor, total, paid, balance, gstRate) {
    document.getElementById('vpay_invoice_id').value = id;
    document.getElementById('vpay_original_gst_rate').value = gstRate;
    document.getElementById('vpay_inv_display').textContent = invNo;
    document.getElementById('vpay_vendor_display').textContent = vendor;
    document.getElementById('vpay_total_display').textContent = '₹' + parseFloat(total).toFixed(2);
    document.getElementById('vpay_paid_display').textContent = '₹' + parseFloat(paid).toFixed(2);
    document.getElementById('vpay_balance_display').textContent = '₹' + parseFloat(balance).toFixed(2);
    document.getElementById('vpay_amount').value = parseFloat(balance).toFixed(2);
    document.getElementById('vpay_gst_rate').value = gstRate > 0 ? gstRate : '';
    document.getElementById('vpay_gst_warning').style.display = 'none';
    vendorPayOriginalGst = gstRate;
    openModal('vendorPayModal');
}

function checkVendorGSTChange() {
    var newRate = parseFloat(document.getElementById('vpay_gst_rate').value) || 0;
    var warn = document.getElementById('vpay_gst_warning');
    var label = document.getElementById('vpay_gst_original_label');
    if (vendorPayOriginalGst > 0 && newRate > 0 && Math.abs(newRate - vendorPayOriginalGst) > 0.01) {
        label.textContent = vendorPayOriginalGst;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
</script>

<!-- Vendor Pay Modal -->
<div class="modal" id="vendorPayModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3>Pay Vendor Invoice</h3>
            <button class="modal-close" onclick="closeModal('vendorPayModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="pay_vendor_invoice">
            <input type="hidden" name="invoice_id" id="vpay_invoice_id">
            <input type="hidden" name="original_gst_rate" id="vpay_original_gst_rate">

            <div style="background:var(--admin-card-bg, #f8fafc);border-radius:8px;padding:0.75rem 1rem;margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.35rem;">
                    <span style="color:var(--admin-muted);">Invoice</span>
                    <span id="vpay_inv_display" style="font-weight:700;">—</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.35rem;">
                    <span style="color:var(--admin-muted);">Vendor</span>
                    <span id="vpay_vendor_display" style="font-weight:600;">—</span>
                </div>
                <hr style="border-color:var(--admin-border);margin:0.5rem 0;">
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.25rem;">
                    <span>Grand Total</span>
                    <span id="vpay_total_display" style="font-weight:700;">₹0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.25rem;">
                    <span style="color:#059669;">Already Paid</span>
                    <span id="vpay_paid_display" style="font-weight:600;color:#059669;">₹0</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:1.1rem;">
                    <span style="font-weight:800;">Balance Due</span>
                    <span id="vpay_balance_display" style="font-weight:800;color:#dc2626;">₹0</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Payment Amount *</label>
                <input type="number" name="payment_amount" id="vpay_amount" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method *</label>
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
                <label class="form-label">Payment Date *</label>
                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">GST Rate (%)</label>
                <input type="number" name="gst_rate" id="vpay_gst_rate" class="form-control" step="0.01" min="0" value="0" onchange="checkVendorGSTChange()">
                <div id="vpay_gst_warning" style="display:none;margin-top:0.5rem;padding:0.5rem 0.75rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:0.78rem;color:#92400e;">
                    ⚠️ This invoice was created on <strong><span id="vpay_gst_original_label">0</span>% GST</strong>. Changing it will update the GST ledger.
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;margin-top:0.25rem;">
                <button type="button" class="btn-secondary" style="flex:1;padding:0.6rem;border-radius:8px;font-size:0.85rem;cursor:pointer;" onclick="closeModal('vendorPayModal')">Cancel</button>
                <button type="submit" class="btn-primary" style="flex:2;padding:0.6rem;border-radius:8px;font-size:0.85rem;justify-content:center;">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<style>
.ci-alert{padding:0.5rem 0.85rem;border-radius:8px;font-size:0.82rem;font-weight:600;margin-bottom:0.75rem;}
.ci-alert.ci-success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);color:#059669;}
.ci-alert.ci-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);color:#dc2626;}
.ci-top-wrap{background:var(--admin-card-bg,#fff);border:1px solid var(--admin-border,#e2e8f0);border-radius:12px;padding:0.75rem 1rem;margin-bottom:0.75rem;}
.ci-top-row{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;}
.ci-h1{font-size:1.1rem;font-weight:800;color:var(--admin-text,#1e293b);margin:0;white-space:nowrap;}
.ci-tools{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;}
.ci-srch{position:relative;display:flex;align-items:center;}
.ci-srch svg{position:absolute;left:10px;pointer-events:none;color:#94a3b8;flex-shrink:0;}
.ci-srch input{width:200px;padding:0.4rem 0.5rem 0.4rem 2rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.8rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;transition:border-color 0.15s;}
.ci-srch input:focus{border-color:var(--admin-accent,#3b82f6);}
.ci-select{padding:0.4rem 0.5rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.78rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;min-width:140px;}
.ci-d{padding:0.4rem 0.5rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.78rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;width:130px;}
.ci-btn-new{display:inline-flex;align-items:center;gap:0.35rem;padding:0.4rem 0.85rem;border-radius:8px;background:var(--admin-accent,#3b82f6);color:#fff;text-decoration:none;font-weight:700;font-size:0.8rem;white-space:nowrap;border:none;cursor:pointer;}
.ci-btn-new:hover{opacity:0.9;}
.ci-mid-row{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;margin-top:0.6rem;padding-top:0.6rem;border-top:1px solid var(--admin-border,#e2e8f0);}
.ci-stats{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;}
.ci-st-i{display:inline-flex;align-items:center;gap:0.35rem;font-size:0.78rem;color:var(--admin-muted,#64748b);}
.ci-st-i strong{font-size:0.85rem;color:var(--admin-text,#1e293b);}
.ci-dot{width:6px;height:6px;border-radius:50%;display:inline-block;flex-shrink:0;}
.ci-dot-blue{background:#3b82f6;}
.ci-dot-amber{background:#f59e0b;}
.ci-dot-green{background:#10b981;}
.ci-dot-red{background:#ef4444;}
.ci-dot-purple{background:#8b5cf6;}
.ci-st-sep{width:1px;height:18px;background:var(--admin-border,#e2e8f0);}
.ci-chips{display:flex;align-items:center;gap:0.25rem;}
.ci-ch{display:inline-flex;align-items:center;gap:0.25rem;padding:0.25rem 0.55rem;border-radius:6px;font-size:0.72rem;font-weight:600;color:var(--admin-muted,#64748b);text-decoration:none;transition:all 0.12s;border:1px solid transparent;}
.ci-ch:hover{background:var(--admin-bg,#f1f5f9);color:var(--admin-text,#1e293b);}
.ci-ch.on{background:var(--admin-accent,#3b82f6);color:#fff;border-color:var(--admin-accent,#3b82f6);}
.ci-ch span{font-weight:400;opacity:0.7;}
.ci-ch.on span{opacity:0.9;}
@media(max-width:768px){
.ci-srch input{width:140px;}
.ci-d{width:110px;}
.ci-mid-row{flex-direction:column;align-items:stretch;}
.ci-chips{flex-wrap:wrap;}
}
</style>
<link rel="stylesheet" href="vendor-invoice.css">
<?php require_once 'layout_footer.php'; ?>
