<?php
$page_title = "Generate Vendor Invoice";
$active_menu = "vendor_invoices";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'inventory-utils.php';
require_once 'csrf.php';

runVendorInvoiceMigrations($pdo);
require_once __DIR__ . '/expense-utils.php';

$flash = '';
$error = '';

// ─── POST: Generate invoice from lot ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    validateCsrfToken();
    $lot_id = intval($_POST['lot_id'] ?? 0);
    $vendor_invoice_number = trim($_POST['vendor_invoice_number'] ?? '');
    $invoice_date = trim($_POST['invoice_date'] ?? date('Y-m-d'));
    $due_date = trim($_POST['due_date'] ?? '');
    $business_key = trim($_POST['business_key'] ?? getBrandConfig()['business_key']);
    $notes = trim($_POST['notes'] ?? '');
    $created_by = $_SESSION['user_name'] ?? 'system';

    if ($lot_id <= 0) {
        $error = "Please select a dispatch lot.";
    } elseif (!strtotime($invoice_date)) {
        $error = "Please enter a valid invoice date.";
    } else {
        try {
            $extra = [
                'vendor_invoice_number' => $vendor_invoice_number,
                'invoice_date' => $invoice_date,
                'due_date' => $due_date ?: null,
                'business_key' => $business_key,
                'notes' => $notes,
                'created_by' => $created_by,
            ];
            $invoice_id = createVendorInvoiceFromLot($pdo, $lot_id, $extra);

            // Auto-create expense for gas refilling charges
            try {
                $lot_stmt = $pdo->prepare("SELECT final_refill_amount, final_gst_amount, final_total, gst_rate, vendor_id, business_key FROM dispatch_lots WHERE id = ?");
                $lot_stmt->execute([$lot_id]);
                $lot_data = $lot_stmt->fetch();
                $vend_stmt = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                $vend_stmt->execute([$lot_data['vendor_id']]);
                $vend_name = $vend_stmt->fetchColumn();

                $expense_category_id = function_exists('resolveSystemCategory') ? resolveSystemCategory($pdo, 'Gas Refilling Charges') : 0;
                if ($expense_category_id > 0 && floatval($lot_data['final_refill_amount']) > 0) {
                    // Guard: skip if expense already exists for this invoice
                    $stmt_ex = $pdo->prepare("SELECT id FROM expenses WHERE reference_type = 'vendor_invoice' AND reference_id = ? AND is_deleted = 0 LIMIT 1");
                    $stmt_ex->execute([$invoice_id]);
                    if (!$stmt_ex->fetch()) {
                    autoCreateExpense($pdo, [
                        'category_id' => $expense_category_id,
                        'vendor_id' => $lot_data['vendor_id'],
                        'vendor_name' => $vend_name ?: '',
                        'expense_date' => $invoice_date,
                        'amount' => floatval($lot_data['final_refill_amount']),
                        'gst_rate' => floatval($lot_data['gst_rate']),
                        'taxable_amount' => floatval($lot_data['final_refill_amount']),
                        'gst_total' => floatval($lot_data['final_gst_amount']),
                        'total_amount' => floatval($lot_data['final_total']),
                        'payment_method' => 'Bank Transfer',
                        'payment_status' => 'unpaid',
                        'business_key' => $business_key,
                        'notes' => 'Auto-created from vendor invoice #' . $vendor_invoice_number,
                        'reference_type' => 'vendor_invoice',
                        'reference_id' => $invoice_id,
                        'reference_number' => $vendor_invoice_number ?: 'INV-' . $invoice_id,
                        'created_by_name' => $created_by,
                    ]);
                    }
                }
            } catch (Exception $e) {
                error_log("Auto-create expense failed: " . $e->getMessage());
            }

            $_SESSION['vi_flash'] = "Invoice generated successfully.";
            echo "<script>window.location.href='vendor-invoice.php?id=$invoice_id';</script>";
            exit();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// ─── Fetch available lots and vendors ───
try {
    $lots = $pdo->query("
        SELECT dl.id, dl.lot_number, dl.vendor_id, v.name AS vendor_name,
               dl.dispatch_date, dl.cylinder_count, dl.returned_count, dl.gst_rate,
               dl.final_refill_amount, dl.final_gst_amount, dl.final_total,
               dl.lot_status, dl.payment_status
        FROM dispatch_lots dl
        JOIN vendors v ON dl.vendor_id = v.id
        WHERE dl.lot_status IN ('open','partial_return','completed')
          AND dl.returned_count > 0
          AND dl.id NOT IN (SELECT lot_id FROM vendor_invoices WHERE lot_id IS NOT NULL)
        ORDER BY dl.dispatch_date DESC
    ")->fetchAll();

    $businesses = getBusinesses();
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $lots = [];
    $businesses = [];
}
?>
<?php if ($error): ?>
<div class="alert-banner error" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:12px;padding:0.85rem 1.25rem;margin-bottom:1.25rem;font-size:0.88rem;font-weight:600;color:#dc2626;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="profile-banner">
    <div class="profile-banner-content">
        <div class="profile-banner-info">
            <h1>Generate Vendor Invoice</h1>
            <p>Create a purchase invoice from a dispatch lot. Select a lot below to auto-populate invoice items.</p>
        </div>
        <div class="profile-banner-actions">
            <a href="vendor-invoices.php" class="btn-secondary" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border-radius:10px;background:rgba(255,255,255,0.08);color:#fff;text-decoration:none;font-weight:700;border:1px solid rgba(255,255,255,0.12);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                Back to Invoices
            </a>
        </div>
    </div>
</div>

<div class="admin-card">
    <form method="post" onsubmit="return validateForm()">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="generate">

        <div class="vi-form-grid">
            <div class="vi-form-group">
                <label class="vi-form-label">Select Dispatch Lot <span style="color:#dc2626;">*</span></label>
                <select name="lot_id" id="lotSelect" class="vi-form-select" required onchange="onLotChange()">
                    <option value="">— Select Lot —</option>
                    <?php foreach ($lots as $lot): 
                        $received = intval($lot['returned_count']);
                        $total = intval($lot['cylinder_count']);
                        $gst = floatval($lot['gst_rate']);
                        $final = floatval($lot['final_total']);
                    ?>
                    <option value="<?= $lot['id'] ?>" 
                        data-vendor="<?= htmlspecialchars($lot['vendor_name']) ?>"
                        data-vendor-id="<?= $lot['vendor_id'] ?>"
                        data-gst="<?= $gst ?>"
                        data-refill="<?= floatval($lot['final_refill_amount']) ?>"
                        data-total="<?= $final ?>"
                        data-cylinders="<?= $received ?>/<?= $total ?>">
                        #<?= $lot['lot_number'] ?> — <?= htmlspecialchars($lot['vendor_name']) ?> (<?= $received ?>/<?= $total ?> cyl, GST: <?= $gst ?>%)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($lots)): ?>
                <div style="font-size:0.78rem;color:var(--admin-muted);margin-top:0.35rem;">No lots available. Lots must have received cylinders and no existing invoice.</div>
                <?php endif; ?>
            </div>

            <div class="vi-form-group">
                <label class="vi-form-label">Vendor</label>
                <div class="vi-form-input" id="vendorDisplay" style="background:var(--admin-bg);font-weight:700;color:var(--admin-fg);cursor:default;">—</div>
            </div>

            <div class="vi-form-group">
                <label class="vi-form-label">Vendor's Invoice Number</label>
                <input type="text" name="vendor_invoice_number" class="vi-form-input" placeholder="e.g. V-INV-2026-001" id="vendorInvNo">
                <div style="font-size:0.7rem;color:var(--admin-muted);margin-top:0.25rem;">The invoice number from the vendor's bill.</div>
            </div>

            <div class="vi-form-group">
                <label class="vi-form-label">Business Entity</label>
                <select name="business_key" class="vi-form-select">
                    <?php foreach ($businesses as $bk => $bv): ?>
                    <option value="<?= htmlspecialchars($bk) ?>" <?= $bk === getBrandConfig()['business_key'] ? 'selected' : '' ?>><?= htmlspecialchars($bv['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="vi-form-group">
                <label class="vi-form-label">Invoice Date <span style="color:#dc2626;">*</span></label>
                <input type="date" name="invoice_date" class="vi-form-input" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="vi-form-group">
                <label class="vi-form-label">Due Date</label>
                <input type="date" name="due_date" class="vi-form-input">
            </div>

            <div class="vi-form-group" style="grid-column:1/-1;">
                <label class="vi-form-label">Lot Preview</label>
                <div id="lotPreview" style="background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:10px;padding:1rem;font-size:0.85rem;min-height:60px;color:var(--admin-muted);">
                    Select a lot above to see details.
                </div>
            </div>

            <div class="vi-form-group" style="grid-column:1/-1;">
                <label class="vi-form-label">Notes / Remarks</label>
                <textarea name="notes" class="vi-form-textarea" rows="2" placeholder="Optional notes..."></textarea>
            </div>
        </div>

        <div style="margin-top:1.5rem;display:flex;gap:0.75rem;justify-content:flex-end;border-top:1px solid var(--admin-border);padding-top:1.25rem;">
            <a href="vendor-invoices.php" class="btn-secondary" style="padding:0.6rem 1.5rem;border-radius:10px;text-decoration:none;font-weight:700;">Cancel</a>
            <button type="submit" class="btn-primary" style="padding:0.6rem 1.5rem;border-radius:10px;font-weight:700;background:var(--admin-accent);color:#fff;border:none;cursor:pointer;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:0.35rem;"><polyline points="20 6 9 17 4 12"/></svg>
                Generate Invoice
            </button>
        </div>
    </form>
</div>

<script>
var lotData = {};
<?php foreach ($lots as $lot): ?>
lotData[<?= $lot['id'] ?>] = {
    vendor: <?= json_encode($lot['vendor_name']) ?>,
    vendor_id: <?= $lot['vendor_id'] ?>,
    lot_number: <?= json_encode($lot['lot_number']) ?>,
    gst_rate: <?= floatval($lot['gst_rate']) ?>,
    refill_amount: <?= floatval($lot['final_refill_amount']) ?>,
    final_total: <?= floatval($lot['final_total']) ?>,
    cylinders: '<?= $lot['returned_count'] ?>/<?= $lot['cylinder_count'] ?>',
    dispatch_date: '<?= $lot['dispatch_date'] ?>'
};
<?php endforeach; ?>

function onLotChange() {
    var sel = document.getElementById('lotSelect');
    var id = parseInt(sel.value);
    var data = lotData[id];
    var preview = document.getElementById('lotPreview');
    var vendorDisplay = document.getElementById('vendorDisplay');
    var invNo = document.getElementById('vendorInvNo');

    if (!data) {
        vendorDisplay.textContent = '—';
        preview.innerHTML = 'Select a lot above to see details.';
        return;
    }

    vendorDisplay.textContent = data.vendor;
    if (!invNo.value) {
        invNo.value = data.lot_number;
    }

    var gst = data.gst_rate;
    var gstDisplay = gst > 0 ? gst + '%' : 'N/A';
    var gstAmt = data.final_total - data.refill_amount;
    gstAmt = gstAmt > 0 ? gstAmt : 0;

    preview.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">' +
        '<div><strong>Lot #</strong><br>' + data.lot_number + '</div>' +
        '<div><strong>Vendor</strong><br>' + data.vendor + '</div>' +
        '<div><strong>Cylinders (Rcvd/Total)</strong><br>' + data.cylinders + '</div>' +
        '<div><strong>Dispatch Date</strong><br>' + data.dispatch_date + '</div>' +
        '<div><strong>Refill Amount</strong><br>₹' + data.refill_amount.toFixed(2) + '</div>' +
        '<div><strong>GST Rate</strong><br>' + gstDisplay + '</div>' +
        (gst > 0 ? '<div><strong>GST Amount</strong><br>₹' + gstAmt.toFixed(2) + '</div>' : '') +
        '<div style="font-weight:800;font-size:1rem;color:var(--admin-accent);"><strong>Estimated Total</strong><br>₹' + data.final_total.toFixed(2) + '</div>' +
        '</div>';
}

function validateForm() {
    var lot = document.getElementById('lotSelect').value;
    if (!lot) {
        alert('Please select a dispatch lot.');
        return false;
    }
    var date = document.querySelector('input[name="invoice_date"]').value;
    if (!date) {
        alert('Please select an invoice date.');
        return false;
    }
    return true;
}
</script>
<link rel="stylesheet" href="vendor-invoice.css">
<?php require_once 'layout_footer.php'; ?>
