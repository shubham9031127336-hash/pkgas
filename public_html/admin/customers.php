<?php
$page_title = "Customer Management";
$active_menu = "customers";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'inventory-utils.php';
require_once __DIR__ . '/gst/json/schema.php';
runCreditMigrations($pdo);
try {
    runCustomerPortalMigrations($pdo);
} catch (PDOException $e) {
    $error = 'Migration failed: ' . $e->getMessage();
}

$message = '';
$error = '';

// Handle customer creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $name = trim($_POST['name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gst_number = trim($_POST['gst_number'] ?? '');
    $customer_type = $_POST['customer_type'] ?? 'refill';

    $email = trim($_POST['email'] ?? '');

    if (empty($name) || empty($mobile)) {
        $error = __('customers.error_required');
    } else {
        // Check for duplicate mobile number
        $check_stmt = $pdo->prepare("SELECT id FROM customers WHERE mobile = ?");
        $check_stmt->execute([$mobile]);
        if ($check_stmt->fetch()) {
            $error = __('customers.error_duplicate_mobile');
        } else {
            try {
                $pdo->query("SELECT email FROM customers LIMIT 0");
            } catch (PDOException $e) {
                try {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN email VARCHAR(100) UNIQUE DEFAULT NULL AFTER mobile");
                    $pdo->exec("ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER email");
                    $pdo->exec("ALTER TABLE customers ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
                    $pdo->exec("ALTER TABLE customers ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL AFTER login_enabled");
                    $pdo->exec("ALTER TABLE customers ADD COLUMN remember_token VARCHAR(255) DEFAULT NULL AFTER last_login");
                    $pdo->exec("ALTER TABLE customers ADD COLUMN remember_expires DATETIME DEFAULT NULL AFTER remember_token");
                } catch (PDOException $e2) {}
            }
            try {
                $state_code = intval($_POST['state_code'] ?? 0);
                $city = trim($_POST['city'] ?? '');
                $pincode = trim($_POST['pincode'] ?? '');
                $reg_type = $_POST['registration_type'] ?? 'regular';
                $states = gstnStateCodes();
                $state_name = $state_code ? ($states[$state_code] ?? null) : null;
                $stmt = $pdo->prepare("INSERT INTO customers (name, mobile, email, address, gst_number, customer_type, state_code, state_name, city, pincode, registration_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $mobile, $email ?: null, $address, $gst_number ?: null, $customer_type, $state_code ?: null, $state_name, $city ?: null, $pincode ?: null, $reg_type]);
                $message = __('customers.created');
            } catch (PDOException $e) {
                $error = __('customers.create_failed') . $e->getMessage();
            }
        }
    }
}

// Handle customer edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gst_number = trim($_POST['gst_number'] ?? '');
    $customer_type = $_POST['customer_type'] ?? 'refill';

    if ($id && !empty($name) && !empty($mobile)) {
        // Check for duplicate mobile (excluding current customer)
        $check_stmt = $pdo->prepare("SELECT id FROM customers WHERE mobile = ? AND id != ?");
        $check_stmt->execute([$mobile, $id]);
        if ($check_stmt->fetch()) {
            $error = __('customers.error_duplicate_mobile');
        } else {
            try {
                $state_code = intval($_POST['state_code'] ?? 0);
                $city = trim($_POST['city'] ?? '');
                $pincode = trim($_POST['pincode'] ?? '');
                $reg_type = $_POST['registration_type'] ?? 'regular';
                $states = gstnStateCodes();
                $state_name = $state_code ? ($states[$state_code] ?? null) : null;
                $stmt = $pdo->prepare("UPDATE customers SET name = ?, mobile = ?, email = ?, address = ?, gst_number = ?, customer_type = ?, state_code = ?, state_name = ?, city = ?, pincode = ?, registration_type = ? WHERE id = ?");
                $stmt->execute([$name, $mobile, $email ?: null, $address, $gst_number ?: null, $customer_type, $state_code ?: null, $state_name, $city ?: null, $pincode ?: null, $reg_type, $id]);
                $message = __('customers.updated');
            } catch (PDOException $e) {
                $error = __('customers.update_failed') . $e->getMessage();
            }
        }
    }
}

// Handle delete customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_customer') {
    $id = intval($_POST['customer_id'] ?? 0);
    $confirm_name = trim($_POST['confirm_name'] ?? '');
    
    // Fetch customer to check name
    $chk = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
    $chk->execute([$id]);
    $customer_name = $chk->fetchColumn();
    
    if (!$customer_name) {
        $error = __('customers.not_found');
    } elseif (strcasecmp($confirm_name, $customer_name) !== 0) {
        $error = __('customers.delete_confirm_failed');
    } else {
        try {
            $pdo->beginTransaction();
            
            // 1. Release any cylinders currently held by this customer back to warehouse empty stock
            $stmt = $pdo->prepare("UPDATE cylinders SET current_customer_id = NULL, status = 'empty' WHERE current_customer_id = ?");
            $stmt->execute([$id]);
            
            // 2. Delete any cylinder transactions logged under this customer
            $stmt = $pdo->prepare("DELETE FROM cylinder_transactions WHERE customer_id = ?");
            $stmt->execute([$id]);
            
            // 3. Delete any payments logged under this customer
            $stmt = $pdo->prepare("DELETE FROM payments WHERE customer_id = ?");
            $stmt->execute([$id]);
            
            // 4. Delete any refill orders (manually purge dependent items to handle non-cascading FK constraints)
            $stmt = $pdo->prepare("
                DELETE oi FROM refill_order_items oi 
                JOIN refill_orders o ON oi.refill_order_id = o.id 
                WHERE o.customer_id = ?
            ");
            $stmt->execute([$id]);
            
            $stmt = $pdo->prepare("DELETE FROM refill_orders WHERE customer_id = ?");
            $stmt->execute([$id]);
            
            // 5. Finally, delete the customer record
            $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            
            $pdo->commit();
            
            // Auto Sync stock since cylinder statuses changed from with_customer to empty!
            syncInventory($pdo);
            
            $message = __('customers.deleted');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = __('customers.delete_failed') . $e->getMessage();
        }
    }
}

// Search and Filter variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? trim($_GET['type']) : '';

// Build fetch query
$sql = "SELECT * FROM customers WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (name LIKE ? OR mobile LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($type_filter)) {
    $sql .= " AND customer_type = ?";
    $params[] = $type_filter;
}

$sql .= " ORDER BY id DESC";

// Keep active rental counts aligned with actual cylinder tracking before listing customers.
syncCustomerActiveCylinderCounts($pdo);

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Count total records for pagination
$count_sql = "SELECT COUNT(*) FROM customers WHERE 1=1";
$count_params = [];

if (!empty($search)) {
    $count_sql .= " AND (name LIKE ? OR mobile LIKE ? OR email LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
}

if (!empty($type_filter)) {
    $count_sql .= " AND customer_type = ?";
    $count_params[] = $type_filter;
}

try {
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_count = (int) $count_stmt->fetchColumn();
} catch (PDOException $e) {
    $total_count = 0;
}

$total_pages = max(1, ceil($total_count / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$sql .= " LIMIT $per_page OFFSET $offset";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
} catch (PDOException $e) {
    $customers = [];
    $error = __('customers.load_failed') . $e->getMessage();
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('customers.heading'); ?></h2>
        <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('customers.subtitle'); ?></p>
    </div>
    <button class="btn-primary" onclick="openModal('addCustomerModal')">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <?php echo __('customers.register'); ?>
    </button>
</div>

<?php if ($message): ?>
    <div class="alert-banner" style="background: var(--success-soft); color: var(--success); border-color: #a7f3d0;">
        <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-banner" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Search, Filter & Controls -->
<div class="admin-card" style="margin-bottom: 2rem;">
    <form method="GET" class="grid-search-form">
        <div>
            <label class="form-label" style="font-size: 0.8rem;"><?php echo __('customers.search'); ?></label>
            <input type="text" name="search" class="form-control" placeholder="Search by name or mobile..." value="<?php echo htmlspecialchars($search); ?>" autofocus>
        </div>
        <div>
            <label class="form-label" style="font-size: 0.8rem;"><?php echo __('customers.type'); ?></label>
            <select name="type" class="form-control">
                <option value=""><?php echo __('customers.all_types'); ?></option>
                <option value="refill" <?php echo $type_filter === 'refill' ? 'selected' : ''; ?>><?php echo __('customers.refill_only'); ?></option>
                <option value="rental" <?php echo $type_filter === 'rental' ? 'selected' : ''; ?>><?php echo __('customers.rental'); ?></option>
            </select>
        </div>
        <div>
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; height: 42px;">Filter</button>
        </div>
    </form>
</div>

<!-- Customer Grid Table -->
<div class="admin-card" style="padding: 0;">
    <div class="table-wrapper" style="border: none;">
            <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('customers.id'); ?></th>
                    <th><?php echo __('customers.name'); ?></th>
                    <th><?php echo __('customers.mobile'); ?></th>
                    <th>Email</th>
                    <th>Portal</th>
                    <th><?php echo __('customers.gst'); ?></th>
                    <th><?php echo __('customers.active_rentals'); ?></th>
                    <th><?php echo __('customers.deposit_balance'); ?></th>
                    <th>Dues</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="customers-tbody">
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td style="font-weight: 700; color: var(--admin-muted);" data-label="<?php echo __('customers.id'); ?>">#CUST-<?php echo str_pad($c['id'], 4, '0', STR_PAD_LEFT); ?></td>
                    <td data-label="<?php echo __('customers.name'); ?>">
                        <a href="customer-profile.php?id=<?php echo $c['id']; ?>" style="font-weight: 700; color: var(--admin-fg); text-decoration: none;">
                            <?php echo htmlspecialchars($c['name']); ?>
                        </a>
                    </td>
                    <td style="font-weight: 600; color: var(--admin-fg);" data-label="<?php echo __('customers.mobile'); ?>"><?php echo htmlspecialchars($c['mobile']); ?></td>
                    <td data-label="<?php echo __('customers.email'); ?>" style="font-size:0.85rem;color:var(--admin-muted);"><?php echo htmlspecialchars($c['email'] ?? '—'); ?></td>
                    <td data-label="<?php echo __('customers.portal'); ?>"><?php if (!empty($c['login_enabled'])): ?><span style="background:#d1fae5;color:#065f46;padding:2px 7px;border-radius:4px;font-size:0.65rem;font-weight:700;">ON</span><?php else: ?><span style="background:#fee2e2;color:#b91c1c;padding:2px 7px;border-radius:4px;font-size:0.65rem;font-weight:700;">OFF</span><?php endif; ?></td>
                    <td data-label="<?php echo __('customers.gst'); ?>">
                        <code style="font-size: 0.85rem; font-weight: 700; color: #475569;"><?php echo htmlspecialchars($c['gst_number'] ?: 'N/A'); ?></code>
                    </td>
                    <td data-label="<?php echo __('customers.active_rentals'); ?>">
                        <span style="font-weight:700;color:<?php echo $c['active_cylinders_count'] > 0 ? 'var(--info)' : 'var(--admin-muted)'; ?>;">
                            <?php echo $c['active_cylinders_count']; ?> <?php echo __('customers.cylinders'); ?>
                        </span>
                    </td>
                    <td style="font-weight: 800; color: <?php echo $c['deposit_balance'] > 0 ? 'var(--success)' : 'var(--admin-fg)'; ?>;" data-label="<?php echo __('customers.deposit_balance'); ?>">
                        ₹<?php echo number_format($c['deposit_balance'], 2); ?>
                    </td>
                    <td style="font-weight: 800; color: <?php echo ($c['credit_used'] ?? 0) > 0 ? 'var(--danger)' : 'var(--admin-muted)'; ?>;" data-label="<?php echo __('customers.dues'); ?>">
                        ₹<?php echo number_format($c['credit_used'] ?? 0, 2); ?>
                    </td>
                    <td style="text-align: right;" data-label="<?php echo __('customers.actions'); ?>">
                        <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                            <a href="customer-profile.php?id=<?php echo $c['id']; ?>" class="btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px;">Ledger</a>
                            <button class="btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px;"
                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode($c)); ?>)">Edit</button>
                            <button class="btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px;"
                                onclick="openDeleteModal(<?php echo htmlspecialchars(json_encode($c)); ?>)">Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="11" style="text-align: center; padding: 4rem 0; color: var(--admin-muted);">
                        <?php echo __('customers.no_results'); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card" id="customers-pagination" style="margin-top: 1.5rem; padding: 0.75rem 1.25rem;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.75rem;">
        <span style="font-size: 0.85rem; color: var(--admin-muted); white-space: nowrap;">
            Showing <strong><?= $total_count ? (($page - 1) * $per_page + 1) : 0 ?></strong>–<strong><?= min($page * $per_page, $total_count) ?></strong> of <strong><?= number_format($total_count) ?></strong> records
        </span>
        <?php if ($total_pages > 1): ?>
        <div style="display: flex; gap: 0.25rem; align-items: center; flex-wrap: wrap;">
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
               class="btn-secondary"
               style="padding: 0.3rem 0.7rem; font-size: 0.8rem; border-radius: 6px; <?= $page <= 1 ? 'opacity: 0.4; pointer-events: none;' : '' ?>">‹ Prev</a>
            <?php
            $window = 2;
            $start = max(1, $page - $window);
            $end   = min($total_pages, $page + $window);
            if ($start > 1):
                $qs = http_build_query(array_merge($_GET, ['page' => 1])); ?>
                <a href="?<?= $qs ?>" class="btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; border-radius: 6px;">1</a>
                <?php if ($start > 2): ?><span style="padding: 0 0.2rem; color: var(--admin-muted); font-weight: 700;">…</span><?php endif;
            endif;
            for ($p = $start; $p <= $end; $p++):
                $qs = http_build_query(array_merge($_GET, ['page' => $p])); ?>
                <a href="?<?= $qs ?>"
                   class="btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; border-radius: 6px; <?= $p === $page ? 'background: var(--admin-accent); color: #fff; border-color: var(--admin-accent);' : '' ?>"><?= $p ?></a>
            <?php endfor;
            if ($end < $total_pages):
                if ($end < $total_pages - 1): ?><span style="padding: 0 0.2rem; color: var(--admin-muted); font-weight: 700;">…</span><?php endif;
                $qs = http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>
                <a href="?<?= $qs ?>" class="btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; border-radius: 6px;"><?= $total_pages ?></a>
            <?php endif; ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
               class="btn-secondary" style="padding: 0.3rem 0.7rem; font-size: 0.8rem; border-radius: 6px; <?= $page >= $total_pages ? 'opacity: 0.4; pointer-events: none;' : '' ?>">Next ›</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Add Customer -->
<div class="modal" id="addCustomerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('customers.modal.register_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('addCustomerModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="create">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.full_name'); ?></label>
                <input type="text" name="name" class="form-control" required placeholder="<?php echo __('customers.modal.full_name_placeholder'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.mobile'); ?></label>
                <input type="tel" name="mobile" class="form-control" required placeholder="<?php echo __('customers.modal.mobile_placeholder'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email (for portal login)</label>
                <input type="email" name="email" class="form-control" placeholder="customer@email.com">
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.type'); ?></label>
                <select name="customer_type" class="form-control">
                    <option value="refill"><?php echo __('customers.modal.refill_only'); ?></option>
                    <option value="rental"><?php echo __('customers.modal.rental'); ?></option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.gst'); ?></label>
                <input type="text" name="gst_number" class="form-control" placeholder="<?php echo __('customers.modal.gst_placeholder'); ?>">
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">State</label>
                    <select name="state_code" class="form-control">
                        <option value="">-- Select State --</option>
                        <?php foreach (gstnStateCodes() as $code => $name): ?>
                        <option value="<?= $code ?>"><?= htmlspecialchars(ucwords(strtolower($name))) ?> (<?= $code ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">City</label>
                    <input type="text" name="city" class="form-control" placeholder="Mumbai">
                </div>
                <div class="form-group">
                    <label class="form-label">Pincode</label>
                    <input type="text" name="pincode" class="form-control" placeholder="400001">
                </div>
                <div class="form-group">
                    <label class="form-label">Registration Type</label>
                    <select name="registration_type" class="form-control">
                        <option value="regular">Regular</option>
                        <option value="composition">Composition</option>
                        <option value="unregistered">Unregistered</option>
                        <option value="others">Others</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.address'); ?></label>
                <textarea name="address" class="form-control" rows="2" placeholder="<?php echo __('customers.modal.address_placeholder'); ?>"></textarea>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;"><?php echo __('customers.modal.create_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Modal: Edit Customer -->
<div class="modal" id="editCustomerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('customers.modal.edit_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('editCustomerModal')">&times;</button>
        </div>
        <form method="POST" id="editCustomerForm"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.full_name'); ?></label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.mobile'); ?></label>
                <input type="tel" name="mobile" id="edit_mobile" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" id="edit_email" class="form-control" placeholder="customer@email.com">
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.type'); ?></label>
                <select name="customer_type" id="edit_customer_type" class="form-control">
                    <option value="refill"><?php echo __('customers.modal.refill_only'); ?></option>
                    <option value="rental"><?php echo __('customers.modal.rental'); ?></option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.gst'); ?></label>
                <input type="text" name="gst_number" id="edit_gst_number" class="form-control">
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">State</label>
                    <select name="state_code" id="edit_state_code" class="form-control">
                        <option value="">-- Select State --</option>
                        <?php foreach (gstnStateCodes() as $code => $name): ?>
                        <option value="<?= $code ?>"><?= htmlspecialchars(ucwords(strtolower($name))) ?> (<?= $code ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">City</label>
                    <input type="text" name="city" id="edit_city" class="form-control" placeholder="Mumbai">
                </div>
                <div class="form-group">
                    <label class="form-label">Pincode</label>
                    <input type="text" name="pincode" id="edit_pincode" class="form-control" placeholder="400001">
                </div>
                <div class="form-group">
                    <label class="form-label">Registration Type</label>
                    <select name="registration_type" id="edit_registration_type" class="form-control">
                        <option value="regular">Regular</option>
                        <option value="composition">Composition</option>
                        <option value="unregistered">Unregistered</option>
                        <option value="others">Others</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('customers.modal.address'); ?></label>
                <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;"><?php echo __('customers.modal.update_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Modal: Delete Customer -->
<div class="modal" id="deleteCustomerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="color: var(--danger);"><?php echo __('customers.modal.delete_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('deleteCustomerModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_customer">
            <input type="hidden" name="customer_id" id="delete_customer_id">
            
            <p style="font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem; color: var(--admin-text);">
                You are about to permanently delete customer <strong id="delete_name_label" style="color: var(--admin-accent);"></strong>. This will remove all orders, payments, cylinder transactions, and release any cylinders held by this customer.
            </p>
            
            <div class="form-group" style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem;">
                <label class="form-label" style="color: var(--danger); font-weight: 700;"><?php echo __('customers.modal.delete_confirm_label'); ?></label>
                <input type="text" name="confirm_name" id="delete_confirm_name" class="form-control" autocomplete="off" placeholder="<?php echo __('customers.modal.delete_placeholder'); ?>" required style="border-color: var(--danger);">
            </div>
            
            <button type="submit" id="delete_submit_btn" class="btn-danger" style="width: 100%; justify-content: center; opacity: 0.5;" disabled>
                <?php echo __('customers.modal.delete_btn'); ?>
            </button>
        </form>
    </div>
</div>

<script>
    let deleteTargetName = '';

    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }
    
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    
    function openEditModal(customer) {
        document.getElementById('edit_id').value = customer.id;
        document.getElementById('edit_name').value = customer.name;
        document.getElementById('edit_mobile').value = customer.mobile;
        document.getElementById('edit_email').value = customer.email || '';
        document.getElementById('edit_customer_type').value = customer.customer_type;
        document.getElementById('edit_gst_number').value = customer.gst_number || '';
        document.getElementById('edit_state_code').value = customer.state_code || '';
        document.getElementById('edit_city').value = customer.city || '';
        document.getElementById('edit_pincode').value = customer.pincode || '';
        document.getElementById('edit_registration_type').value = customer.registration_type || 'regular';
        document.getElementById('edit_address').value = customer.address || '';
        openModal('editCustomerModal');
    }

    function openDeleteModal(customer) {
        deleteTargetName = customer.name.trim();
        document.getElementById('delete_customer_id').value = customer.id;
        document.getElementById('delete_name_label').innerText = customer.name;
        
        // Reset input field and disable button
        const confirmInput = document.getElementById('delete_confirm_name');
        confirmInput.value = '';
        
        const deleteBtn = document.getElementById('delete_submit_btn');
        deleteBtn.disabled = true;
        deleteBtn.style.opacity = '0.5';
        
        openModal('deleteCustomerModal');
    }
    
    document.getElementById('delete_confirm_name').addEventListener('input', function(e) {
        const val = e.target.value.trim();
        const btn = document.getElementById('delete_submit_btn');
        
        if (val.toLowerCase() === deleteTargetName.toLowerCase()) {
            btn.disabled = false;
            btn.style.opacity = '1';
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.5';
        }
    });

    // Live search — auto-submit form on input with debounce
    var searchForm = document.querySelector('.admin-card form');
    var searchTimeout;
    function doLiveSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { searchForm.submit(); }, 700);
    }
    var searchInput = document.querySelector('input[name="search"]');
    var typeSelect = document.querySelector('select[name="type"]');
    if (searchInput) {
        searchInput.addEventListener('input', doLiveSearch);
        searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
    }
    if (typeSelect) typeSelect.addEventListener('change', doLiveSearch);
</script>

<?php
require_once 'layout_footer.php';
?>
