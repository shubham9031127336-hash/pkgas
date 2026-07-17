<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('suppliers.title');
$active_menu = 'cylinder_suppliers';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';
runCylinderSupplierMigrations($pdo);
runSupplierTypeMigration($pdo);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'create_supplier') {
        $company_name  = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $mobile        = trim($_POST['mobile'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $address       = trim($_POST['address'] ?? '');
        $gst_number    = trim($_POST['gst_number'] ?? '');
        $supplier_type = trim($_POST['supplier_type'] ?? 'cylinder');
        $notes         = trim($_POST['notes'] ?? '');
        $status        = trim($_POST['status'] ?? 'active');

        if (empty($company_name) || empty($mobile)) {
            $error = 'Company Name and Mobile are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO cylinder_suppliers (company_name, contact_person, mobile, email, address, gst_number, supplier_type, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_name, $contact_person, $mobile, $email, $address, $gst_number, $supplier_type, $notes, $status]);
                $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, 'Create Cylinder Supplier', ?)");
                $stmt->execute([$_SESSION['user_name'] ?? 'system', "Registered cylinder supplier: $company_name"]);
                $message = __('suppliers.created');
            } catch (PDOException $e) {
                $error = __('common.error') . ': ' . $e->getMessage();
            }
        }
    }

    if ($action === 'edit_supplier') {
        $supplier_id   = intval($_POST['supplier_id'] ?? 0);
        $company_name  = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $mobile        = trim($_POST['mobile'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $address       = trim($_POST['address'] ?? '');
        $gst_number    = trim($_POST['gst_number'] ?? '');
        $supplier_type = trim($_POST['supplier_type'] ?? 'cylinder');
        $notes         = trim($_POST['notes'] ?? '');
        $status        = trim($_POST['status'] ?? 'active');

        if ($supplier_id <= 0 || empty($company_name) || empty($mobile)) {
            $error = 'Company Name and Mobile are required.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE cylinder_suppliers SET company_name = ?, contact_person = ?, mobile = ?, email = ?, address = ?, gst_number = ?, supplier_type = ?, notes = ?, status = ? WHERE id = ?");
                $stmt->execute([$company_name, $contact_person, $mobile, $email, $address, $gst_number, $supplier_type, $notes, $status, $supplier_id]);
                $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, 'Update Cylinder Supplier', ?)");
                $stmt->execute([$_SESSION['user_name'] ?? 'system', "Updated supplier: $company_name (ID: $supplier_id)"]);
                $message = __('suppliers.updated');
            } catch (PDOException $e) {
                $error = __('common.error') . ': ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_supplier') {
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $confirm_name = trim($_POST['confirm_name'] ?? '');

        if ($supplier_id > 0 && !empty($confirm_name)) {
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM cylinder_purchases WHERE supplier_id = ?");
                $chk->execute([$supplier_id]);
                if ($chk->fetchColumn() > 0) {
                    $error = __('suppliers.has_purchases');
                } else {
                    $stmt = $pdo->prepare("SELECT company_name FROM cylinder_suppliers WHERE id = ?");
                    $stmt->execute([$supplier_id]);
                    $name = $stmt->fetchColumn();
                    if ($name !== $confirm_name) {
                        $error = 'Company name does not match. Deletion cancelled.';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM cylinder_suppliers WHERE id = ?");
                        $stmt->execute([$supplier_id]);
                        $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, 'Delete Cylinder Supplier', ?)");
                        $stmt->execute([$_SESSION['user_name'] ?? 'system', "Deleted cylinder supplier: $name"]);
                        $message = __('suppliers.deleted');
                    }
                }
            } catch (PDOException $e) {
                $error = __('common.error') . ': ' . $e->getMessage();
            }
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$type_filter = isset($_GET['type']) ? trim($_GET['type']) : '';

$sql = "SELECT cs.*,
        (SELECT COUNT(*) FROM cylinder_purchases cp WHERE cp.supplier_id = cs.id) as purchase_count,
        (SELECT COALESCE(running_balance, 0) FROM supplier_ledger sl WHERE sl.supplier_id = cs.id ORDER BY sl.id DESC LIMIT 1) as balance
        FROM cylinder_suppliers cs WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (cs.company_name LIKE ? OR cs.contact_person LIKE ? OR cs.mobile LIKE ? OR cs.gst_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if (!empty($status_filter)) {
    $sql .= " AND cs.status = ?";
    $params[] = $status_filter;
}
if (!empty($type_filter)) {
    $sql .= " AND cs.supplier_type = ?";
    $params[] = $type_filter;
}
$sql .= " ORDER BY cs.company_name ASC";

$suppliers = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = __('common.error') . ': ' . $e->getMessage();
}
?>
<style>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
.page-header-title h2 { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
.page-header-title p { color: var(--admin-muted); font-size: 0.9rem; margin: 0.25rem 0 0 0; }
.search-bar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
.search-bar input, .search-bar select { padding: 0.5rem 0.75rem; border: 1px solid var(--admin-border); border-radius: 8px; font-size: 0.85rem; background: var(--admin-card); color: var(--admin-fg); }
.search-bar input { min-width: 220px; }
.stat-badge { font-size: 0.75rem; padding: 0.2rem 0.6rem; border-radius: 999px; font-weight: 600; }
.ledger-link { font-size: 0.75rem; color: var(--admin-accent); text-decoration: none; font-weight: 600; }
.ledger-link:hover { text-decoration: underline; }
</style>

<div class="page-header">
    <div class="page-header-title">
        <h2><?php echo __('suppliers.heading'); ?></h2>
        <p><?php echo __('suppliers.subtitle'); ?></p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <a href="cylinder-purchases.php" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">
            <?php echo __('purchases.heading'); ?>
        </a>
        <button class="btn-primary" onclick="openModal('addSupplierModal')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php echo __('suppliers.add'); ?>
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner"><strong><?php echo __('common.success_label'); ?>:</strong> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner" style="background:var(--danger-soft);color:var(--danger);border-color:#fca5a5;"><strong><?php echo __('common.error_label'); ?>:</strong> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="GET" class="search-bar">
    <input type="text" name="search" placeholder="Search by name, mobile or GST..." value="<?php echo htmlspecialchars($search); ?>">
    <select name="type">
        <option value="">All Types</option>
        <option value="cylinder" <?php echo $type_filter === 'cylinder' ? 'selected' : ''; ?>><?php echo __('suppliers.type_cylinder'); ?></option>
        <option value="product" <?php echo $type_filter === 'product' ? 'selected' : ''; ?>><?php echo __('suppliers.type_product'); ?></option>
        <option value="both" <?php echo $type_filter === 'both' ? 'selected' : ''; ?>><?php echo __('suppliers.type_both'); ?></option>
    </select>
    <select name="status">
        <option value="">All Status</option>
        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>><?php echo __('suppliers.active'); ?></option>
        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>><?php echo __('suppliers.inactive'); ?></option>
    </select>
    <button type="submit" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">Search</button>
    <?php if ($search || $status_filter || $type_filter): ?>
    <a href="cylinder-suppliers.php" class="btn-secondary" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;text-decoration:none;">Clear</a>
    <?php endif; ?>
</form>

<div class="admin-card" style="padding:0;">
    <div class="table-wrapper" style="border:none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('suppliers.company_name'); ?></th>
                    <th><?php echo __('suppliers.type'); ?></th>
                    <th><?php echo __('suppliers.contact_person'); ?></th>
                    <th><?php echo __('suppliers.mobile'); ?></th>
                    <th><?php echo __('suppliers.gst_number'); ?></th>
                    <th style="text-align:center;">Purchases</th>
                    <th style="text-align:right;">Balance</th>
                    <th><?php echo __('suppliers.status'); ?></th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                <tr><td colspan="9" style="text-align:center;padding:4rem 1rem;color:var(--admin-muted);"><?php echo __('suppliers.no_data'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($suppliers as $s): ?>
                <?php
                    $type_label = $s['supplier_type'] ?? 'cylinder';
                    if ($type_label === 'cylinder') $type_label_display = __('suppliers.type_cylinder');
                    elseif ($type_label === 'product') $type_label_display = __('suppliers.type_product');
                    else $type_label_display = __('suppliers.type_both');
                ?>
                <tr>
                    <td style="font-weight:700;"><?php echo htmlspecialchars($s['company_name']); ?></td>
                    <td><span class="stat-badge badge-filled" style="font-size:0.65rem;"><?php echo $type_label_display; ?></span></td>
                    <td><?php echo htmlspecialchars($s['contact_person'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($s['mobile']); ?></td>
                    <td><?php echo htmlspecialchars($s['gst_number'] ?: '-'); ?></td>
                    <td style="text-align:center;">
                        <a href="cylinder-purchases.php?supplier_id=<?php echo $s['id']; ?>" style="font-weight:700;color:var(--admin-accent);"><?php echo intval($s['purchase_count']); ?></a>
                    </td>
                    <td style="text-align:right;font-weight:700;">
                        <?php if (floatval($s['balance']) > 0): ?>
                            <span style="color:var(--danger);">₹<?php echo number_format($s['balance'], 2); ?></span>
                        <?php elseif (floatval($s['balance']) < 0): ?>
                            <span style="color:var(--success);">-₹<?php echo number_format(abs($s['balance']), 2); ?></span>
                        <?php else: ?>
                            <span style="color:var(--admin-muted);">₹0.00</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="stat-badge <?php echo $s['status'] === 'active' ? 'badge-filled' : 'badge-empty'; ?>">
                            <?php echo $s['status'] === 'active' ? __('suppliers.active') : __('suppliers.inactive'); ?>
                        </span>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex;gap:0.5rem;justify-content:flex-end;flex-wrap:wrap;">
                            <a href="cylinder-supplier-ledger.php?supplier_id=<?php echo $s['id']; ?>" class="ledger-link">Ledger</a>
                            <a href="cylinder-purchases.php?supplier_id=<?php echo $s['id']; ?>" class="ledger-link">Purchases</a>
                            <button class="btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.75rem;border-radius:6px;" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($s)); ?>)">Edit</button>
                            <button class="btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.75rem;border-radius:6px;color:var(--danger);" onclick="openDeleteModal(<?php echo htmlspecialchars(json_encode($s)); ?>)">Del</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="addSupplierModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('suppliers.add'); ?></h3>
            <button class="modal-close" onclick="closeModal('addSupplierModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="create_supplier">
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.company_name'); ?> *</label>
                <input type="text" name="company_name" class="form-control" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('suppliers.contact_person'); ?></label>
                    <input type="text" name="contact_person" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('suppliers.mobile'); ?> *</label>
                    <input type="text" name="mobile" class="form-control" required>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('suppliers.email'); ?></label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('suppliers.gst_number'); ?></label>
                    <input type="text" name="gst_number" class="form-control" placeholder="e.g. 27AAAAA0000A1Z5">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.supplier_type'); ?></label>
                <select name="supplier_type" class="form-control">
                    <option value="cylinder"><?php echo __('suppliers.type_cylinder'); ?></option>
                    <option value="product"><?php echo __('suppliers.type_product'); ?></option>
                    <option value="both"><?php echo __('suppliers.type_both'); ?></option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.address'); ?></label>
                <textarea name="address" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.notes'); ?></label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.status'); ?></label>
                <select name="status" class="form-control">
                    <option value="active"><?php echo __('suppliers.active'); ?></option>
                    <option value="inactive"><?php echo __('suppliers.inactive'); ?></option>
                </select>
            </div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;"><?php echo __('suppliers.add'); ?></button>
        </form>
    </div>
</div>

<div class="modal" id="editSupplierModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('suppliers.edit'); ?></h3>
            <button class="modal-close" onclick="closeModal('editSupplierModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_supplier">
            <input type="hidden" name="supplier_id" id="edit_supplier_id">
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.company_name'); ?> *</label>
                <input type="text" name="company_name" id="edit_company_name" class="form-control" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('suppliers.contact_person'); ?></label>
                    <input type="text" name="contact_person" id="edit_contact_person" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('suppliers.mobile'); ?> *</label>
                    <input type="text" name="mobile" id="edit_mobile" class="form-control" required>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('suppliers.email'); ?></label>
                    <input type="email" name="email" id="edit_email" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('suppliers.gst_number'); ?></label>
                    <input type="text" name="gst_number" id="edit_gst_number" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.supplier_type'); ?></label>
                <select name="supplier_type" id="edit_supplier_type" class="form-control">
                    <option value="cylinder"><?php echo __('suppliers.type_cylinder'); ?></option>
                    <option value="product"><?php echo __('suppliers.type_product'); ?></option>
                    <option value="both"><?php echo __('suppliers.type_both'); ?></option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.address'); ?></label>
                <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.notes'); ?></label>
                <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('suppliers.status'); ?></label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="active"><?php echo __('suppliers.active'); ?></option>
                    <option value="inactive"><?php echo __('suppliers.inactive'); ?></option>
                </select>
            </div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;"><?php echo __('suppliers.edit'); ?></button>
        </form>
    </div>
</div>

<div class="modal" id="deleteSupplierModal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3 style="color:var(--danger);"><?php echo __('suppliers.delete'); ?></h3>
            <button class="modal-close" onclick="closeModal('deleteSupplierModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_supplier">
            <input type="hidden" name="supplier_id" id="delete_supplier_id">
            <p style="margin-bottom:1rem;line-height:1.5;"><?php echo __('suppliers.confirm_delete'); ?></p>
            <p style="font-size:0.85rem;color:var(--admin-muted);margin-bottom:0.5rem;">Type <strong id="delete_confirm_name_display"></strong> to confirm:</p>
            <input type="text" name="confirm_name" id="delete_confirm_name" class="form-control" placeholder="Type company name to confirm" required>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;background:var(--danger);border-color:var(--danger);"><?php echo __('suppliers.delete'); ?></button>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function openEditModal(s) {
    document.getElementById('edit_supplier_id').value = s.id;
    document.getElementById('edit_company_name').value = s.company_name || '';
    document.getElementById('edit_contact_person').value = s.contact_person || '';
    document.getElementById('edit_mobile').value = s.mobile || '';
    document.getElementById('edit_email').value = s.email || '';
    document.getElementById('edit_gst_number').value = s.gst_number || '';
    document.getElementById('edit_address').value = s.address || '';
    document.getElementById('edit_notes').value = s.notes || '';
    document.getElementById('edit_supplier_type').value = s.supplier_type || 'cylinder';
    document.getElementById('edit_status').value = s.status || 'active';
    openModal('editSupplierModal');
}
function openDeleteModal(s) {
    document.getElementById('delete_supplier_id').value = s.id;
    document.getElementById('delete_confirm_name_display').textContent = s.company_name;
    document.getElementById('delete_confirm_name').value = '';
    openModal('deleteSupplierModal');
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
