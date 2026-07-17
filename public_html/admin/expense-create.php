<?php
$page_title = "Add Expense";
$active_menu = "expense_create";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/expense-utils.php';
runExpenseMigrations($pdo);

$message = '';
$error = '';

$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$expense = null;
$attachments = [];
$is_edit = false;

if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$edit_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($expense) {
        $is_edit = true;
        $page_title = "Edit Expense #" . $expense['expense_number'];
        $att_stmt = $pdo->prepare("SELECT * FROM expense_attachments WHERE expense_id = ?");
        $att_stmt->execute([$edit_id]);
        $attachments = $att_stmt->fetchAll();

        // Context-aware flags for system-generated expenses
        $ref_type = $expense['reference_type'] ?? '';
        $is_system_generated = $ref_type !== '' && $ref_type !== 'manual' && $ref_type !== null;
        $is_cylinder_purchase = $ref_type === 'cylinder_purchase';
        $is_vendor_invoice = $ref_type === 'vendor_invoice';
        $is_transport = in_array($ref_type, ['dispatch_transport', 'receive_transport']);
    }
}

// Determine system-generated flags for template (default false for new entries)
if (!isset($is_system_generated)) $is_system_generated = false;
if (!isset($is_cylinder_purchase)) $is_cylinder_purchase = false;
if (!isset($is_vendor_invoice)) $is_vendor_invoice = false;
if (!isset($is_transport)) $is_transport = false;

// ─── POST Handler ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'save_expense' || $action === 'save_and_new') {
        $category_id = intval($_POST['category_id'] ?? 0);
        $vendor_id = !empty($_POST['vendor_id']) ? intval($_POST['vendor_id']) : null;
        $vendor_name = trim($_POST['vendor_name'] ?? '');
        $expense_date = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $amount = floatval($_POST['amount'] ?? 0);
        $gst_type = $_POST['gst_type'] ?? 'exclusive';
        $gst_rate = floatval($_POST['gst_rate'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $payment_status = $_POST['payment_status'] ?? 'paid';
        $business_key = trim($_POST['business_key'] ?? getBrandConfig()['business_key']);
        $warehouse_branch = trim($_POST['warehouse_branch'] ?? '');
        $vehicle_number = trim($_POST['vehicle_number'] ?? '');
        $driver_name = trim($_POST['driver_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'approved';

        $edit_id_post = intval($_POST['edit_id'] ?? 0);

        if ($category_id <= 0) $error = "Please select an expense category.";
        elseif ($amount <= 0) $error = "Amount must be greater than zero.";
        elseif (!strtotime($expense_date)) $error = "Please enter a valid date.";

        if (!$error) {
            $calc = calculateExpenseGST($amount, $gst_rate, $gst_type);

            if ($edit_id_post > 0) {
                $stmt = $pdo->prepare("UPDATE expenses SET category_id=?, vendor_id=?, vendor_name=?, expense_date=?, amount=?, gst_type=?, gst_rate=?, taxable_amount=?, cgst_amount=?, sgst_amount=?, igst_amount=?, gst_total=?, total_amount=?, payment_method=?, payment_status=?, business_key=?, warehouse_branch=?, vehicle_number=?, driver_name=?, notes=?, status=? WHERE id=? AND is_deleted=0");
                $stmt->execute([$category_id, $vendor_id, $vendor_name, $expense_date, $calc['amount'], $gst_type, $calc['gst_rate'], $calc['taxable_amount'], $calc['cgst_amount'], $calc['sgst_amount'], $calc['igst_amount'], $calc['gst_total'], $calc['total_amount'], $payment_method, $payment_status, $business_key, $warehouse_branch ?: null, $vehicle_number ?: null, $driver_name ?: null, $notes ?: null, $status, $edit_id_post]);
                syncExpenseGST($pdo, $edit_id_post);
                syncExpensePaymentStatus($pdo, $edit_id_post);
                $_SESSION['success_flash'] = "Expense updated.";
                echo "<script>window.location.href='expenses.php';</script>";
                exit();
            } else {
                $expense_id = autoCreateExpense($pdo, [
                    'category_id' => $category_id,
                    'vendor_id' => $vendor_id,
                    'vendor_name' => $vendor_name,
                    'expense_date' => $expense_date,
                    'amount' => $calc['amount'],
                    'gst_type' => $gst_type,
                    'gst_rate' => $calc['gst_rate'],
                    'taxable_amount' => $calc['taxable_amount'],
                    'cgst_amount' => $calc['cgst_amount'],
                    'sgst_amount' => $calc['sgst_amount'],
                    'igst_amount' => $calc['igst_amount'],
                    'gst_total' => $calc['gst_total'],
                    'total_amount' => $calc['total_amount'],
                    'payment_method' => $payment_method,
                    'payment_status' => $payment_status,
                    'business_key' => $business_key,
                    'warehouse_branch' => $warehouse_branch ?: null,
                    'vehicle_number' => $vehicle_number ?: null,
                    'driver_name' => $driver_name ?: null,
                    'notes' => $notes ?: null,
                    'reference_type' => 'manual',
                    'created_by' => $_SESSION['user_id'] ?? null,
                    'created_by_name' => $_SESSION['user_name'] ?? 'Admin',
                ]);

                if ($expense_id) {
                    if (!empty($_FILES['attachment']['name'][0])) {
                        $upload_dir = __DIR__ . '/../uploads/expenses/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                        foreach ($_FILES['attachment']['tmp_name'] as $i => $tmp) {
                            if ($_FILES['attachment']['error'][$i] === UPLOAD_ERR_OK) {
                                $orig = basename($_FILES['attachment']['name'][$i]);
                                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xls', 'xlsx'])) {
                                    $new_name = time() . '_' . uniqid() . '.' . $ext;
                                    move_uploaded_file($tmp, $upload_dir . $new_name);
                                    $stmt = $pdo->prepare("INSERT INTO expense_attachments (expense_id, filename, original_filename, file_size, mime_type) VALUES (?, ?, ?, ?, ?)");
                                    $stmt->execute([$expense_id, $new_name, $orig, $_FILES['attachment']['size'][$i], mime_content_type($upload_dir . $new_name) ?: 'application/octet-stream']);
                                }
                            }
                        }
                    }
                    $_SESSION['success_flash'] = "Expense #" . $expense_id . " created successfully.";
                    if ($action === 'save_and_new') {
                        echo "<script>window.location.href='expense-create.php';</script>";
                    } else {
                        echo "<script>window.location.href='expenses.php';</script>";
                    }
                    exit();
                } else {
                    $error = "Failed to create expense. Please try again.";
                }
            }
        }
    }
}

$categories = $pdo->query("SELECT c.*, g.name AS group_name FROM expense_categories c JOIN expense_category_groups g ON c.group_id = g.id WHERE c.is_active = 1 ORDER BY g.display_order ASC, c.name ASC")->fetchAll();
$vendors = $pdo->query("SELECT id, name FROM vendors WHERE 1=1 ORDER BY name ASC")->fetchAll();
$businesses = getBusinesses();
$valid_methods = ['Cash', 'Bank', 'UPI', 'Credit', 'Cheque'];
$cat_groups = $pdo->query("SELECT id, name FROM expense_category_groups WHERE is_active = 1 ORDER BY display_order ASC")->fetchAll();
?>
<link rel="stylesheet" href="expense.css">

<div class="page-header">
    <div class="page-header-title">
        <h2><?= $is_edit ? 'Edit Expense' : 'Add Expense' ?></h2>
        <p><?= $is_edit ? 'Update expense #' . htmlspecialchars($expense['expense_number']) : 'Record a new business expense' ?></p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <a href="expenses.php" class="btn-secondary" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Back to Expenses
        </a>
    </div>
</div>

<?php if ($error): ?>
<div class="alert-banner" style="background:var(--danger-soft);color:var(--danger);border-color:#fca5a5;"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($is_edit && $is_system_generated): ?>
<div class="alert-banner" style="background:#eff6ff;color:#1e40af;border-color:#93c5fd;margin-bottom:1rem;display:flex;align-items:center;gap:0.5rem;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <span>
        Auto-created from <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $expense['reference_type']))) ?></strong>
        #<?= intval($expense['reference_id']) ?>
        — some fields are locked to preserve data integrity.
    </span>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" onsubmit="return validateExpenseForm()" id="expenseForm">
    <?php csrfField(); ?>
    <input type="hidden" name="action" id="form_action" value="save_expense">
    <?php if ($is_edit): ?>
    <input type="hidden" name="edit_id" value="<?= $edit_id ?>">
    <?php endif; ?>

    <!-- ─── Section 1: Basic Information ─── -->
    <div class="form-section">
        <div class="form-section-header">
            <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            Basic Information
        </div>
        <div class="form-section-body">
            <div class="expense-form-grid">
                <div class="form-group">
                    <label class="form-label">Expense Date *</label>
                    <?php if ($is_edit && $is_system_generated): ?>
                    <div style="padding:0.6rem 0.75rem;border:1px solid var(--admin-border);border-radius:8px;background:#f8fafc;font-size:0.85rem;color:var(--admin-fg);">
                        <?= htmlspecialchars($expense['expense_date']) ?>
                    </div>
                    <input type="hidden" name="expense_date" value="<?= htmlspecialchars($expense['expense_date']) ?>">
                    <?php else: ?>
                    <input type="date" name="expense_date" class="form-control" value="<?= $is_edit ? $expense['expense_date'] : date('Y-m-d') ?>" required>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Expense Category *</label>
                    <?php if ($is_edit && $is_system_generated): ?>
                    <div style="padding:0.6rem 0.75rem;border:1px solid var(--admin-border);border-radius:8px;background:#f8fafc;font-size:0.85rem;color:var(--admin-fg);font-weight:600;">
                        <?php
                        $cat_name = 'Unknown';
                        foreach ($categories as $c) {
                            if ($c['id'] == $expense['category_id']) { $cat_name = $c['name']; break; }
                        }
                        echo htmlspecialchars($cat_name);
                        ?>
                    </div>
                    <input type="hidden" name="category_id" value="<?= intval($expense['category_id']) ?>">
                    <?php else: ?>
                    <select name="category_id" id="catSelect" class="form-control" required onchange="onCategoryChange()">
                        <option value="">— Select Category —</option>
                        <?php $current_group = ''; foreach ($categories as $c):
                            $group_name = $c['group_name'];
                            if ($group_name !== $current_group):
                                $current_group = $group_name;
                        ?>
                        <optgroup label="<?= htmlspecialchars($group_name) ?>">
                        <?php endif; ?>
                            <option value="<?= $c['id'] ?>"
                                data-group-id="<?= $c['group_id'] ?>"
                                data-gst="<?= $c['gst_applicable'] ?>"
                                data-rate="<?= floatval($c['default_gst_rate']) ?>"
                                <?= ($is_edit && $c['id'] == $expense['category_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="form-group" id="vendorFieldGroup">
                    <label class="form-label">Vendor</label>
                    <?php if ($is_edit && $is_system_generated): ?>
                    <div style="padding:0.6rem 0.75rem;border:1px solid var(--admin-border);border-radius:8px;background:#f8fafc;font-size:0.85rem;color:var(--admin-muted);">
                        <?= htmlspecialchars($expense['vendor_name'] ?: 'N/A') ?>
                        <input type="hidden" name="vendor_name" value="<?= htmlspecialchars($expense['vendor_name'] ?? '') ?>">
                        <input type="hidden" name="vendor_id" value="<?= intval($expense['vendor_id'] ?? 0) ?>">
                    </div>
                    <?php else: ?>
                    <select name="vendor_id" id="vendorSelect" class="form-control" onchange="onVendorChange()">
                        <option value="">— None (Manual Entry) —</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= $v['id'] ?>" data-name="<?= htmlspecialchars($v['name']) ?>" <?= ($is_edit && $v['id'] == $expense['vendor_id']) ? 'selected' : '' ?>><?= htmlspecialchars($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="vendor_name" id="vendorNameInput" class="form-control" style="margin-top:0.5rem;display:<?= $is_edit && !$expense['vendor_id'] ? 'block' : 'none' ?>;" placeholder="Enter vendor name" value="<?= $is_edit ? htmlspecialchars($expense['vendor_name']) : '' ?>">
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Business Entity</label>
                    <select name="business_key" class="form-control">
                        <?php foreach ($businesses as $bk => $bv): ?>
                        <option value="<?= htmlspecialchars($bk) ?>" <?= ($is_edit && $expense['business_key'] === $bk) || (!$is_edit && $bk === getBrandConfig()['business_key']) ? 'selected' : '' ?>><?= htmlspecialchars($bv['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount (₹) *</label>
                    <?php if ($is_edit && ($is_cylinder_purchase || $is_vendor_invoice)): ?>
                    <div style="padding:0.6rem 0.75rem;border:1px solid var(--admin-border);border-radius:8px;background:#f8fafc;font-size:0.85rem;color:var(--admin-fg);font-weight:600;">
                        ₹<?= number_format($expense['amount'], 2) ?>
                    </div>
                    <input type="hidden" name="amount" value="<?= $expense['amount'] ?>">
                    <?php else: ?>
                    <input type="number" name="amount" id="amountInput" class="form-control" step="0.01" min="0.01" value="<?= $is_edit ? $expense['amount'] : '' ?>" required oninput="recalcGST()" placeholder="0.00">
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Section 2: GST Details ─── -->
    <div class="form-section form-section-conditional<?= (!$is_edit) ? ' hidden' : '' ?>" id="gstSection">
        <div class="form-section-header">
            <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            GST Details
        </div>
        <div class="form-section-body">
            <div class="expense-form-grid">
                <div class="form-group">
                    <label class="form-label">GST Type</label>
                    <?php if ($is_edit && $is_system_generated): ?>
                    <div style="padding:0.6rem 0.75rem;border:1px solid var(--admin-border);border-radius:8px;background:#f8fafc;font-size:0.85rem;color:var(--admin-fg);">
                        <?= htmlspecialchars(ucfirst($expense['gst_type'] ?? 'exclusive')) ?>
                    </div>
                    <input type="hidden" name="gst_type" value="<?= htmlspecialchars($expense['gst_type'] ?? 'exclusive') ?>">
                    <?php else: ?>
                    <select name="gst_type" id="gstType" class="form-control" onchange="recalcGST()">
                        <option value="exclusive" <?= $is_edit && $expense['gst_type'] === 'exclusive' ? 'selected' : '' ?>>GST Exclusive</option>
                        <option value="inclusive" <?= $is_edit && $expense['gst_type'] === 'inclusive' ? 'selected' : '' ?>>GST Inclusive</option>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">GST Rate (%)</label>
                    <?php if ($is_edit && $is_system_generated): ?>
                    <div style="padding:0.6rem 0.75rem;border:1px solid var(--admin-border);border-radius:8px;background:#f8fafc;font-size:0.85rem;color:var(--admin-fg);font-weight:600;">
                        <?= floatval($expense['gst_rate'] ?? 0) ?>%
                    </div>
                    <input type="hidden" name="gst_rate" value="<?= floatval($expense['gst_rate'] ?? 0) ?>">
                    <?php else: ?>
                    <select name="gst_rate" id="gstRate" class="form-control" onchange="recalcGST()">
                        <option value="0">0% (No GST)</option>
                        <option value="3" <?= $is_edit && $expense['gst_rate'] == 3 ? 'selected' : '' ?>>3%</option>
                        <option value="5" <?= $is_edit && $expense['gst_rate'] == 5 ? 'selected' : '' ?>>5%</option>
                        <option value="12" <?= $is_edit && $expense['gst_rate'] == 12 ? 'selected' : '' ?>>12%</option>
                        <option value="18" <?= $is_edit && $expense['gst_rate'] == 18 ? 'selected' : '' ?>>18%</option>
                        <option value="28" <?= $is_edit && $expense['gst_rate'] == 28 ? 'selected' : '' ?>>28%</option>
                    </select>
                    <?php endif; ?>
                </div>
                <div class="full-width expense-gst-box" id="gstBox">
                    <div class="gst-item"><div class="gst-label">Taxable Amount</div><div class="gst-value" id="gstTaxable">₹0.00</div></div>
                    <div class="gst-item"><div class="gst-label">CGST</div><div class="gst-value" id="gstCGST">₹0.00</div></div>
                    <div class="gst-item"><div class="gst-label">SGST</div><div class="gst-value" id="gstSGST">₹0.00</div></div>
                    <div class="gst-item"><div class="gst-label">IGST</div><div class="gst-value" id="gstIGST">₹0.00</div></div>
                    <div class="gst-item"><div class="gst-label">GST Total</div><div class="gst-value" id="gstTotal">₹0.00</div></div>
                    <div class="gst-item gst-highlight"><div class="gst-label">Total Amount</div><div class="gst-value" id="gstGrandTotal">₹0.00</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Section 3: Payment ─── -->
    <div class="form-section">
        <div class="form-section-header">
            <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 10h20"/><rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="12" cy="14" r="2"/></svg>
            Payment
        </div>
        <div class="form-section-body">
            <div class="expense-form-grid">
                <div class="form-group">
                    <label class="form-label">Payment Method *</label>
                    <select name="payment_method" class="form-control" required>
                        <?php foreach ($valid_methods as $m): ?>
                        <option value="<?= $m ?>" <?= $is_edit && $expense['payment_method'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status" class="form-control">
                        <option value="paid" <?= $is_edit && $expense['payment_status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="unpaid" <?= $is_edit && $expense['payment_status'] === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="partial" <?= $is_edit && $expense['payment_status'] === 'partial' ? 'selected' : '' ?>>Partial</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Section 4: Operational Details ─── -->
    <div class="form-section form-section-conditional<?= $is_edit && ($is_transport || (!$is_system_generated && ($expense['warehouse_branch'] || $expense['vehicle_number'] || $expense['driver_name']))) ? '' : ' hidden' ?>" id="opsSection">
        <div class="form-section-header">
            <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M16 13H8M16 17H8M10 9H8"/></svg>
            Operational Details
        </div>
        <div class="form-section-body">
            <div class="expense-form-grid">
                <div class="form-group" id="warehouseField" style="display:<?= $is_edit && $is_transport ? 'none' : '' ?>">
                    <label class="form-label">Warehouse / Branch</label>
                    <input type="text" name="warehouse_branch" class="form-control" placeholder="e.g. Main Godown" value="<?= $is_edit ? htmlspecialchars($expense['warehouse_branch'] ?? '') : '' ?>">
                </div>
                <div class="form-group" id="vehicleField" style="display:<?= $is_edit && ($is_transport || $expense['vehicle_number']) ? '' : 'none' ?>">
                    <label class="form-label">Vehicle Number</label>
                    <input type="text" name="vehicle_number" class="form-control" placeholder="e.g. GJ-05-XX-1234" value="<?= $is_edit ? htmlspecialchars($expense['vehicle_number'] ?? '') : '' ?>">
                </div>
                <div class="form-group" id="driverField" style="display:<?= $is_edit && ($is_transport || $expense['driver_name']) ? '' : 'none' ?>">
                    <label class="form-label">Driver Name</label>
                    <input type="text" name="driver_name" class="form-control" placeholder="Driver name" value="<?= $is_edit ? htmlspecialchars($expense['driver_name'] ?? '') : '' ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Section 5: Notes & Attachments ─── -->
    <div class="form-section">
        <div class="form-section-header">
            <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Notes & Attachments
        </div>
        <div class="form-section-body">
            <div class="expense-form-grid">
                <div class="full-width form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes / remarks"><?= $is_edit ? htmlspecialchars($expense['notes'] ?? '') : '' ?></textarea>
                </div>
                <div class="full-width form-group">
                    <label class="form-label">Attachment (Invoice / Receipt)</label>
                    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click()">
                        <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <div class="upload-text">Drop files here or <strong>browse</strong></div>
                        <div class="upload-hint">PDF, JPG, PNG, GIF, WebP • Max 5MB each</div>
                    </div>
                    <input type="file" name="attachment[]" id="fileInput" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" style="display:none" onchange="onFileSelect()">

                    <?php if (!empty($attachments)): ?>
                    <div class="attachment-list">
                        <?php foreach ($attachments as $att): ?>
                        <div class="attachment-item">
                            <span class="file-icon">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            </span>
                            <span class="file-name"><?= htmlspecialchars($att['original_filename']) ?></span>
                            <span class="file-size">(<?= $att['file_size'] > 1024 ? round($att['file_size']/1024, 1) . ' KB' : $att['file_size'] . ' B' ?>)</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="attachment-list" id="filePreviewList" style="display:none"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Sticky Action Bar ─── -->
    <div class="sticky-bar">
        <span style="font-size:0.78rem;color:var(--admin-muted);margin-right:auto;">All fields marked with * are required</span>
        <a href="expenses.php" class="btn-secondary" style="padding:0.6rem 1.5rem;border-radius:10px;text-decoration:none;font-weight:700;">Cancel</a>
        <?php if (!$is_edit): ?>
        <button type="submit" class="btn-secondary" style="padding:0.6rem 1.5rem;border-radius:10px;font-weight:700;background:#f1f5f9;border:1px solid var(--admin-border);cursor:pointer;" onclick="document.getElementById('form_action').value='save_and_new'">Save & Add Another</button>
        <?php endif; ?>
        <button type="submit" class="btn-primary" style="padding:0.6rem 1.5rem;border-radius:10px;font-weight:700;background:var(--admin-accent);color:#fff;border:none;cursor:pointer;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:0.35rem;"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $is_edit ? 'Update Expense' : 'Save Expense' ?>
        </button>
    </div>

</form>

<script>
var isSystemGen = <?= $is_system_generated ? 'true' : 'false' ?>;
var isTransport = <?= $is_transport ? 'true' : 'false' ?>;
var isEditMode = <?= $is_edit ? 'true' : 'false' ?>;

function recalcGST() {
    var amount = parseFloat(document.getElementById('amountInput').value) || 0;
    var rate = parseFloat(document.getElementById('gstRate').value) || 0;
    var gstType = document.getElementById('gstType').value;
    var gstBox = document.getElementById('gstBox');

    var taxable = 0, gstTotal = 0, cgst = 0, sgst = 0, igst = 0, total = 0;

    if (rate > 0 && amount > 0) {
        if (gstType === 'inclusive') {
            taxable = Math.round(amount * 100 / (100 + rate) * 100) / 100;
            gstTotal = Math.round((amount - taxable) * 100) / 100;
        } else {
            taxable = amount;
            gstTotal = Math.round(amount * rate / 100 * 100) / 100;
        }
        cgst = Math.round(gstTotal / 2 * 100) / 100;
        sgst = gstTotal - cgst;
        total = gstType === 'inclusive' ? amount : Math.round((taxable + gstTotal) * 100) / 100;
        gstBox.classList.remove('gst-zero');
    } else {
        taxable = amount;
        total = amount;
        gstBox.classList.add('gst-zero');
    }

    document.getElementById('gstTaxable').textContent = '\u20B9' + taxable.toFixed(2);
    document.getElementById('gstCGST').textContent = '\u20B9' + cgst.toFixed(2);
    document.getElementById('gstSGST').textContent = '\u20B9' + sgst.toFixed(2);
    document.getElementById('gstIGST').textContent = '\u20B9' + igst.toFixed(2);
    document.getElementById('gstTotal').textContent = '\u20B9' + gstTotal.toFixed(2);
    document.getElementById('gstGrandTotal').textContent = '\u20B9' + total.toFixed(2);
}

function onCategoryChange() {
    // For system-generated entries, PHP has already set the correct field display
    if (isSystemGen && isEditMode) {
        recalcGST();
        return;
    }

    var sel = document.getElementById('catSelect');
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;

    var gstSection = document.getElementById('gstSection');
    var opsSection = document.getElementById('opsSection');
    var gstRate = document.getElementById('gstRate');

    // GST visibility
    var hasGst = opt.dataset.gst == 1;
    if (hasGst && opt.dataset.rate > 0) {
        gstRate.value = opt.dataset.rate;
    }
    gstSection.classList.toggle('hidden', !hasGst);

    // Operational fields visibility based on group
    var groupId = parseInt(opt.dataset.groupId) || 0;
    var warehouseField = document.getElementById('warehouseField');
    var vehicleField = document.getElementById('vehicleField');
    var driverField = document.getElementById('driverField');

    var showWarehouse = groupId === 1;
    var showVehicle = groupId === 2;

    warehouseField.style.display = showWarehouse ? '' : 'none';
    vehicleField.style.display = showVehicle ? '' : 'none';
    driverField.style.display = showVehicle ? '' : 'none';

    opsSection.classList.toggle('hidden', !showWarehouse && !showVehicle);

    recalcGST();
}

function onVendorChange() {
    var sel = document.getElementById('vendorSelect');
    var opt = sel.options[sel.selectedIndex];
    var manualInput = document.getElementById('vendorNameInput');
    if (sel.value === '') {
        manualInput.style.display = 'block';
        manualInput.focus();
    } else {
        manualInput.style.display = 'none';
        manualInput.value = opt ? opt.dataset.name : '';
    }
}

function validateExpenseForm() {
    var amtField = document.getElementById('amountInput');
    if (amtField) {
        var amt = parseFloat(amtField.value) || 0;
        if (amt <= 0) { alert('Please enter a valid amount.'); return false; }
    }
    document.querySelector('button[type="submit"]').disabled = true;
    return true;
}

function onFileSelect() {
    var input = document.getElementById('fileInput');
    var preview = document.getElementById('filePreviewList');
    var zone = document.getElementById('uploadZone');
    if (input.files.length > 0) {
        preview.style.display = 'flex';
        preview.innerHTML = '';
        for (var i = 0; i < input.files.length; i++) {
            var f = input.files[i];
            var div = document.createElement('div');
            div.className = 'attachment-item';
            div.innerHTML = '<span class="file-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>' +
                '<span class="file-name">' + f.name + '</span>' +
                '<span class="file-size">(' + (f.size > 1024 ? (f.size/1024).toFixed(1) + ' KB' : f.size + ' B') + ')</span>';
            preview.appendChild(div);
        }
        zone.querySelector('.upload-text').textContent = input.files.length + ' file(s) selected';
    } else {
        preview.style.display = 'none';
        zone.querySelector('.upload-text').innerHTML = 'Drop files here or <strong>browse</strong>';
    }
}

// Drag & drop support
(function() {
    var zone = document.getElementById('uploadZone');
    var input = document.getElementById('fileInput');
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function() { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('drag-over');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            onFileSelect();
        }
    });
})();

<?php if ($is_edit): ?>
onCategoryChange();
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
