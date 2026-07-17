<?php
$page_title = "Expense Categories";
$active_menu = "expense_categories";
require_once __DIR__ . '/layout.php';
require_role(['super_admin']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/expense-utils.php';
runExpenseMigrations($pdo);

$message = '';
$error = '';

// ─── POST: Add/Edit Group ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    $action = $_POST['action'];

    if ($action === 'add_group') {
        $name = trim($_POST['name'] ?? '');
        $order = intval($_POST['display_order'] ?? 0);
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO expense_category_groups (name, display_order) VALUES (?, ?)");
            $stmt->execute([$name, $order]);
            $_SESSION['success_flash'] = "Group '$name' created.";
        } else {
            $error = "Group name is required.";
        }
    } elseif ($action === 'edit_group') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $order = intval($_POST['display_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $name) {
            $stmt = $pdo->prepare("UPDATE expense_category_groups SET name=?, display_order=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $order, $active, $id]);
            $_SESSION['success_flash'] = "Group updated.";
        }
    } elseif ($action === 'add_category') {
        $group_id = intval($_POST['group_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $gst_app = isset($_POST['gst_applicable']) ? 1 : 0;
        $gst_rate = floatval($_POST['default_gst_rate'] ?? 0);
        $hsn = trim($_POST['hsn_code'] ?? '');
        if ($group_id && $name) {
            $stmt = $pdo->prepare("INSERT INTO expense_categories (group_id, name, description, gst_applicable, default_gst_rate, hsn_code) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$group_id, $name, $desc, $gst_app, $gst_rate ?: null, $hsn ?: null]);
            $_SESSION['success_flash'] = "Category '$name' created.";
        } else {
            $error = "Group and Category name are required.";
        }
    } elseif ($action === 'edit_category') {
        $id = intval($_POST['id'] ?? 0);
        $group_id = intval($_POST['group_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $gst_app = isset($_POST['gst_applicable']) ? 1 : 0;
        $gst_rate = floatval($_POST['default_gst_rate'] ?? 0);
        $hsn = trim($_POST['hsn_code'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $group_id && $name) {
            $stmt = $pdo->prepare("UPDATE expense_categories SET group_id=?, name=?, description=?, gst_applicable=?, default_gst_rate=?, hsn_code=?, is_active=? WHERE id=? AND is_system=0");
            $stmt->execute([$group_id, $name, $desc, $gst_app, $gst_rate ?: null, $hsn ?: null, $active, $id]);
            $_SESSION['success_flash'] = "Category updated.";
        }
    }
    echo "<script>window.location.href='expense-categories.php';</script>";
    exit();
}

$groups = $pdo->query("SELECT * FROM expense_category_groups ORDER BY display_order ASC, name ASC")->fetchAll();
$categories = $pdo->query("SELECT c.*, g.name AS group_name FROM expense_categories c JOIN expense_category_groups g ON c.group_id = g.id ORDER BY g.display_order ASC, c.name ASC")->fetchAll();
?>
<link rel="stylesheet" href="expense.css">

<div class="page-header">
    <div class="page-header-title">
        <h2>Expense Categories</h2>
        <p>Manage expense category groups and categories</p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <button class="btn-primary" onclick="openModal('addGroupModal')" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">+ New Group</button>
        <button class="btn-primary" onclick="openModal('addCategoryModal')" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">+ New Category</button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner"><strong>Success:</strong> <?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner" style="background:var(--danger-soft);color:var(--danger);border-color:#fca5a5;"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="admin-card">
    <div class="tab-bar" style="display:flex;gap:0;border-bottom:2px solid var(--admin-border);margin-bottom:1.5rem;">
        <button class="tab-btn active" data-tab="groups" onclick="switchTab('groups')" style="padding:0.75rem 1.25rem;border:none;background:none;font-weight:700;font-size:0.85rem;cursor:pointer;color:var(--admin-accent);border-bottom:2px solid var(--admin-accent);margin-bottom:-2px;">Category Groups</button>
        <button class="tab-btn" data-tab="categories" onclick="switchTab('categories')" style="padding:0.75rem 1.25rem;border:none;background:none;font-weight:600;font-size:0.85rem;cursor:pointer;color:var(--admin-muted);">Expense Categories</button>
    </div>

    <div id="tab-groups" class="tab-content">
        <table class="admin-table">
            <thead><tr><th>ID</th><th>Group Name</th><th>Display Order</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($groups as $g): ?>
                <tr>
                    <td><?= $g['id'] ?></td>
                    <td><strong><?= htmlspecialchars($g['name']) ?></strong></td>
                    <td><?= $g['display_order'] ?></td>
                    <td><span class="status-badge" style="background:<?= $g['is_active'] ? '#d1fae5' : '#fef2f2' ?>;color:<?= $g['is_active'] ? '#059669' : '#dc2626' ?>;"><?= $g['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><button class="btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.75rem;border-radius:6px;" onclick="editGroup(<?= $g['id'] ?>, <?= json_encode($g['name']) ?>, <?= $g['display_order'] ?>, <?= $g['is_active'] ?>)">Edit</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="tab-categories" class="tab-content" style="display:none;">
        <div class="table-wrapper">
            <table class="admin-table">
                <thead><tr><th>ID</th><th>Group</th><th>Name</th><th>GST</th><th>HSN</th><th>System</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($categories as $c): ?>
                    <tr>
                        <td><?= $c['id'] ?></td>
                        <td><span class="status-badge" style="background:#e0e7ff;color:#4338ca;"><?= htmlspecialchars($c['group_name']) ?></span></td>
                        <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                        <td><?= $c['gst_applicable'] ? ($c['default_gst_rate'] ? $c['default_gst_rate'] . '%' : 'Yes') : 'No' ?></td>
                        <td><?= htmlspecialchars($c['hsn_code'] ?: '-') ?></td>
                        <td><?= $c['is_system'] ? '<span class="status-badge" style="background:#dbeafe;color:#2563eb;">System</span>' : '<span class="status-badge" style="background:#f3f4f6;color:#6b7280;">User</span>' ?></td>
                        <td><span class="status-badge" style="background:<?= $c['is_active'] ? '#d1fae5' : '#fef2f2' ?>;color:<?= $c['is_active'] ? '#059669' : '#dc2626' ?>;"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <?php if (!$c['is_system']): ?>
                            <button class="btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.75rem;border-radius:6px;" onclick="editCategory(<?= $c['id'] ?>, <?= $c['group_id'] ?>, <?= json_encode($c['name']) ?>, <?= json_encode($c['description']) ?>, <?= $c['gst_applicable'] ?>, <?= floatval($c['default_gst_rate']) ?>, <?= json_encode($c['hsn_code']) ?>, <?= $c['is_active'] ?>)">Edit</button>
                            <?php else: ?>
                            <span style="color:var(--admin-muted);font-size:0.78rem;">System</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Group Modal -->
<div class="modal" id="addGroupModal">
    <div class="modal-content" style="max-width:450px;">
        <div class="modal-header"><h3>Add Category Group</h3><button class="modal-close" onclick="closeModal('addGroupModal')">&times;</button></div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="add_group">
            <div class="form-group"><label class="form-label">Group Name *</label><input type="text" name="name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Display Order</label><input type="number" name="display_order" class="form-control" value="0" min="0"></div>
            <button type="submit" class="btn-primary w-full justify-center mt-05">Create Group</button>
        </form>
    </div>
</div>

<!-- Edit Group Modal -->
<div class="modal" id="editGroupModal">
    <div class="modal-content" style="max-width:450px;">
        <div class="modal-header"><h3>Edit Category Group</h3><button class="modal-close" onclick="closeModal('editGroupModal')">&times;</button></div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_group">
            <input type="hidden" name="id" id="eg_id">
            <div class="form-group"><label class="form-label">Group Name *</label><input type="text" name="name" id="eg_name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Display Order</label><input type="number" name="display_order" id="eg_order" class="form-control" min="0"></div>
            <div class="form-group"><label class="form-checkbox"><input type="checkbox" name="is_active" id="eg_active" checked> Active</label></div>
            <button type="submit" class="btn-primary w-full justify-center mt-05">Update Group</button>
        </form>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal" id="addCategoryModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header"><h3>Add Expense Category</h3><button class="modal-close" onclick="closeModal('addCategoryModal')">&times;</button></div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="add_category">
            <div class="form-group"><label class="form-label">Group *</label>
                <select name="group_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($groups as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Category Name *</label><input type="text" name="name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
            <div class="form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div><label class="form-checkbox"><input type="checkbox" name="gst_applicable" onchange="document.getElementById('gst_rate_field').style.display=this.checked?'block':'none'"> GST Applicable</label></div>
                <div id="gst_rate_field" style="display:none;"><label class="form-label">Default GST Rate (%)</label><input type="number" name="default_gst_rate" class="form-control" step="0.01" min="0"></div>
            </div>
            <div class="form-group"><label class="form-label">HSN Code</label><input type="text" name="hsn_code" class="form-control" maxlength="8"></div>
            <button type="submit" class="btn-primary w-full justify-center mt-05">Create Category</button>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal" id="editCategoryModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header"><h3>Edit Expense Category</h3><button class="modal-close" onclick="closeModal('editCategoryModal')">&times;</button></div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="id" id="ec_id">
            <div class="form-group"><label class="form-label">Group *</label>
                <select name="group_id" id="ec_group_id" class="form-control" required>
                    <?php foreach ($groups as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Category Name *</label><input type="text" name="name" id="ec_name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="ec_desc" class="form-control" rows="2"></textarea></div>
            <div class="form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div><label class="form-checkbox"><input type="checkbox" name="gst_applicable" id="ec_gst" onchange="document.getElementById('egst_rate_field').style.display=this.checked?'block':'none'"> GST Applicable</label></div>
                <div id="egst_rate_field"><label class="form-label">Default GST Rate (%)</label><input type="number" name="default_gst_rate" id="ec_gst_rate" class="form-control" step="0.01" min="0"></div>
            </div>
            <div class="form-group"><label class="form-label">HSN Code</label><input type="text" name="hsn_code" id="ec_hsn" class="form-control" maxlength="8"></div>
            <div class="form-group"><label class="form-checkbox"><input type="checkbox" name="is_active" id="ec_active" checked> Active</label></div>
            <button type="submit" class="btn-primary w-full justify-center mt-05">Update Category</button>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => { b.style.color = 'var(--admin-muted)'; b.style.borderBottomColor = 'transparent'; b.style.fontWeight = '600'; });
    document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
    document.querySelector(`.tab-btn[data-tab="${tab}"]`).style.color = 'var(--admin-accent)';
    document.querySelector(`.tab-btn[data-tab="${tab}"]`).style.borderBottomColor = 'var(--admin-accent)';
    document.querySelector(`.tab-btn[data-tab="${tab}"]`).style.fontWeight = '700';
    document.getElementById('tab-' + tab).style.display = 'block';
}
function editGroup(id, name, order, active) {
    document.getElementById('eg_id').value = id;
    document.getElementById('eg_name').value = name;
    document.getElementById('eg_order').value = order;
    document.getElementById('eg_active').checked = !!active;
    openModal('editGroupModal');
}
function editCategory(id, groupId, name, desc, gst, gstRate, hsn, active) {
    document.getElementById('ec_id').value = id;
    document.getElementById('ec_group_id').value = groupId;
    document.getElementById('ec_name').value = name;
    document.getElementById('ec_desc').value = desc || '';
    document.getElementById('ec_gst').checked = !!gst;
    document.getElementById('ec_gst_rate').value = gstRate || '';
    document.getElementById('ec_hsn').value = hsn || '';
    document.getElementById('ec_active').checked = !!active;
    document.getElementById('egst_rate_field').style.display = gst ? 'block' : 'none';
    openModal('editCategoryModal');
}
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
