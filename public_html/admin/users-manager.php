<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('users.title');
$active_menu = "users";
require_once __DIR__ . '/layout.php';
require_role(['super_admin']);
require_once __DIR__ . '/db.php';

$message = '';
$error = '';

// Handle Staff User Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? 'billing_clerk';
    $status = $_POST['status'] ?? 'active';

    if (empty($username) || empty($password) || empty($name)) {
        $error = __('users.error_required');
    } elseif (strlen($password) < 6) {
        $error = __('users.error_password_length');
    } else {
        try {
            // Check if username already exists
            $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetchColumn() > 0) {
                $error = sprintf(__('users.error_username_taken'), $username);
            } else {
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $password_hash, $name, $role, $status]);
                $message = sprintf(__('users.created'), $name);
            }
        } catch (PDOException $e) {
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}

// Handle Staff User Modification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';
    $status = $_POST['status'] ?? '';

    // Prevent Super Admin from deactivating themselves or changing their own role
    if ($id === intval($_SESSION['user_id'])) {
        if ($role !== 'super_admin' || $status !== 'active') {
            $error = __('users.error_self_demote');
        }
    }

    if (!$error) {
        if ($id > 0 && !empty($name) && !empty($role) && !empty($status)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, status = ? WHERE id = ?");
                $stmt->execute([$name, $role, $status, $id]);
                $message = sprintf(__('users.updated'), $name);
            } catch (PDOException $e) {
                $error = "Failed to update account: " . $e->getMessage();
            }
        } else {
            $error = __('users.error_role_required');
        }
    }
}

// Handle Staff Password Reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $id = intval($_POST['id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';

    if ($id > 0 && !empty($new_password)) {
        if (strlen($new_password) < 6) {
            $error = __('users.error_pass_length');
        } else {
            try {
                $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$password_hash, $id]);
                $message = __('users.pass_reset');
            } catch (PDOException $e) {
                $error = __('users.pass_reset_failed') . $e->getMessage();
            }
        }
    } else {
        $error = __('users.error_invalid_params');
    }
}

// Fetch all staff users
$users = [];
try {
    $users = $pdo->query("SELECT id, username, name, role, status, created_at FROM users ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {
    $error = __('users.table_not_ready');
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('users.heading'); ?></h2>
        <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('users.subtitle'); ?></p>
    </div>
    <button class="btn-primary" onclick="openModal('addUserModal')" style="border-radius: 12px; padding: 0.75rem 1.5rem;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
<?php echo __('users.register'); ?>
    </button>
</div>

<?php if ($message): ?>
    <div class="alert-banner" style="background: var(--success-soft); color: var(--success); border-color: #a7f3d0; margin-bottom: 2rem;">
        <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-banner" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5; margin-bottom: 2rem;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Users Table -->
<div class="admin-card" style="padding: 0; margin-bottom: 3rem;">
    <div class="table-wrapper" style="border: none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('users.id'); ?></th>
                    <th><?php echo __('users.full_name'); ?></th>
                    <th><?php echo __('users.username'); ?></th>
                    <th><?php echo __('users.role'); ?></th>
                    <th><?php echo __('users.status'); ?></th>
                    <th><?php echo __('users.created_date'); ?></th>
                    <th style="text-align: right;">Action Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td style="font-weight: 700; color: var(--admin-muted);" data-label="<?php echo __('users.id'); ?>">#USER-<?php echo str_pad($user['id'], 3, '0', STR_PAD_LEFT); ?></td>
                    <td style="font-weight: 700; color: var(--admin-fg);" data-label="<?php echo __('users.full_name'); ?>"><?php echo htmlspecialchars($user['name']); ?></td>
                    <td style="font-family: monospace; font-size: 0.9rem; font-weight: 700; color: #475569;" data-label="<?php echo __('users.username'); ?>"><?php echo htmlspecialchars($user['username']); ?></td>
                    <td data-label="<?php echo __('users.role'); ?>">
                        <?php
                        $badge_class = 'badge-refill';
                        if ($user['role'] === 'super_admin') $badge_class = 'badge-rental';
                        elseif ($user['role'] === 'warehouse_supervisor') $badge_class = 'badge-empty';
                        ?>
                        <span class="badge <?php echo $badge_class; ?>" style="font-size: 0.75rem; padding: 4px 10px; text-transform: uppercase;">
                            <?php echo str_replace('_', ' ', $user['role']); ?>
                        </span>
                    </td>
                    <td data-label="<?php echo __('users.status'); ?>">
                        <span class="badge <?php echo $user['status'] === 'active' ? 'badge-filled' : 'badge-under-maintenance'; ?>" style="font-size: 0.75rem; padding: 4px 10px;">
                            <?php echo strtoupper($user['status']); ?>
                        </span>
                    </td>
                    <td style="font-size: 0.85rem; color: var(--admin-muted);" data-label="<?php echo __('users.created_date'); ?>"><?php echo date('M d, Y h:i A', strtotime($user['created_at'])); ?></td>
                    <td style="text-align: right;" data-label="Actions">
                        <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                            <button class="btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px;"
                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                <?php echo __('users.modify_role'); ?>
                            </button>
                            <button class="btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px; background: #fafafb; border-color: #cbd5e1;"
                                onclick="openResetModal(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                <?php echo __('users.reset_pass'); ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 4rem 0; color: var(--admin-muted);">
                        <?php echo __('users.no_data'); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add Staff User -->
<div class="modal" id="addUserModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('users.modal.register_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="create_user">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.employee_name'); ?></label>
                <input type="text" name="name" class="form-control" required placeholder="<?php echo __('users.modal.name_placeholder'); ?>">
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.username'); ?></label>
                <input type="text" name="username" class="form-control" required placeholder="<?php echo __('users.modal.username_placeholder'); ?>" style="text-transform: lowercase;">
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.password'); ?></label>
                <input type="password" name="password" class="form-control" required placeholder="<?php echo __('users.modal.password_placeholder'); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.role_label'); ?></label>
                <select name="role" class="form-control" required>
                    <option value="billing_clerk"><?php echo __('users.modal.billing_clerk'); ?></option>
                    <option value="warehouse_supervisor"><?php echo __('users.modal.warehouse_supervisor'); ?></option>
                    <option value="super_admin"><?php echo __('users.modal.super_admin'); ?></option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.status_label'); ?></label>
                <select name="status" class="form-control" required>
                    <option value="active"><?php echo __('users.modal.active'); ?></option>
                    <option value="inactive"><?php echo __('users.modal.inactive'); ?></option>
                </select>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;"><?php echo __('users.modal.create_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Modal: Edit Staff User -->
<div class="modal" id="editUserModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('users.modal.edit_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.employee_name'); ?></label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.role_label'); ?></label>
                <select name="role" id="edit_role" class="form-control" required>
                    <option value="billing_clerk"><?php echo __('users.modal.billing_clerk'); ?></option>
                    <option value="warehouse_supervisor"><?php echo __('users.modal.warehouse_supervisor'); ?></option>
                    <option value="super_admin"><?php echo __('users.modal.super_admin'); ?></option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.status_label'); ?></label>
                <select name="status" id="edit_status" class="form-control" required>
                    <option value="active"><?php echo __('users.modal.active'); ?></option>
                    <option value="inactive"><?php echo __('users.modal.inactive'); ?></option>
                </select>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;"><?php echo __('users.modal.update_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Modal: Reset Password -->
<div class="modal" id="resetModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('users.modal.reset_pass_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="reset_id">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.staff_member'); ?></label>
                <input type="text" id="reset_name" class="form-control" disabled style="background: #f1f5f9;">
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo __('users.modal.new_password'); ?></label>
                <input type="password" name="new_password" class="form-control" required placeholder="<?php echo __('users.modal.password_placeholder'); ?>">
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem; background: var(--danger);"><?php echo __('users.modal.reset_btn'); ?></button>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }
    
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    
    function openEditModal(user) {
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_name').value = user.name;
        document.getElementById('edit_role').value = user.role;
        document.getElementById('edit_status').value = user.status;
        openModal('editUserModal');
    }

    function openResetModal(user) {
        document.getElementById('reset_id').value = user.id;
        document.getElementById('reset_name').value = user.name;
        openModal('resetModal');
    }
</script>

<?php
require_once 'layout_footer.php';
?>
