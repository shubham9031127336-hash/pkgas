<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('partners.title');
$active_menu = 'partners';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/nav-helpers.php';
runPartnerMigrations($pdo);

$message = '';
$error = '';

// ── CREATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_partner') {
    $company_name  = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $mobile        = trim($_POST['mobile'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $gst_number    = trim($_POST['gst_number'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $status        = trim($_POST['status'] ?? 'active');

    if (empty($company_name) || empty($mobile)) {
        $error = __('partners.error_required');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO partners (company_name, contact_person, mobile, email, gst_number, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_name, $contact_person, $mobile, $email, $gst_number, $address, $notes, $status]);
            $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_name'] ?? 'system', 'Create Partner', "Registered partner company: $company_name"]);
            $message = sprintf(__('partners.created'), $company_name);
        } catch (PDOException $e) {
            $error = __('partners.create_failed') . $e->getMessage();
        }
    }
}

// ── UPDATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_partner') {
    $partner_id    = intval($_POST['partner_id'] ?? 0);
    $company_name  = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $mobile        = trim($_POST['mobile'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $gst_number    = trim($_POST['gst_number'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $status        = trim($_POST['status'] ?? 'active');

    if ($partner_id <= 0 || empty($company_name) || empty($mobile)) {
        $error = 'Partner ID, Company Name, and Mobile number are required.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE partners SET company_name = ?, contact_person = ?, mobile = ?, email = ?, gst_number = ?, address = ?, notes = ?, status = ? WHERE id = ?");
            $stmt->execute([$company_name, $contact_person, $mobile, $email, $gst_number, $address, $notes, $status, $partner_id]);
            $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_name'] ?? 'system', 'Update Partner', "Updated partner: $company_name (ID: $partner_id)"]);
            $message = sprintf(__('partners.updated'), $company_name);
        } catch (PDOException $e) {
            $error = __('partners.update_failed') . $e->getMessage();
        }
    }
}

// ── DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_partner') {
    $partner_id   = intval($_POST['partner_id'] ?? 0);
    $confirm_name = trim($_POST['confirm_name'] ?? '');

    if ($partner_id > 0 && !empty($confirm_name)) {
        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_partner_id = ?");
            $chk->execute([$partner_id]);
            if ($chk->fetchColumn() > 0) {
                $error = 'Cannot delete partner with outstanding cylinders. Return all cylinders first.';
            } else {
                $chk = $pdo->prepare("SELECT company_name FROM partners WHERE id = ?");
                $chk->execute([$partner_id]);
                $partner_name = $chk->fetchColumn();
                if ($partner_name !== $confirm_name) {
                    $error = __('partners.delete_confirm_failed');
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE pti FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id = pt.id WHERE pt.partner_id = ?")->execute([$partner_id]);
                    $pdo->prepare("DELETE FROM partner_transactions WHERE partner_id = ?")->execute([$partner_id]);
                    $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$partner_id]);
                    $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
                    $stmt->execute([$_SESSION['user_name'] ?? 'system', 'Delete Partner', "Deleted partner: $partner_name"]);
                    $pdo->commit();
                    $message = sprintf(__('partners.deleted'), $partner_name);
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = __('partners.delete_failed') . $e->getMessage();
        }
    }
}

// ── BORROW ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'borrow') {
    $partner_id  = intval($_POST['partner_id'] ?? 0);
    $tx_date     = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes_inp   = trim($_POST['notes'] ?? '');
    $default_rate = floatval($_POST['default_rent_rate'] ?? 0);
    $free_days   = intval($_POST['free_days'] ?? 0);
    $gas_type_id = intval($_POST['gas_type_id'] ?? 0);
    $size_val    = trim($_POST['size_capacity'] ?? '');
    $serials_raw = trim($_POST['serials'] ?? '');
    $created_by  = $_SESSION['user_name'] ?? 'system';

    if ($partner_id <= 0 || empty($serials_raw)) {
        $error = "Select a partner and enter at least one serial number.";
    } else {
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO partner_transactions (partner_id, transaction_type, transaction_date, notes, created_by) VALUES (?, 'borrowed_from_partner', ?, ?, ?)");
            $ins->execute([$partner_id, $tx_date, $notes_inp ?: null, $created_by]);
            $tx_id = $pdo->lastInsertId();

            $serials = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $serials_raw)));
            $imported = 0;
            foreach ($serials as $sn) {
                if (empty($sn)) continue;
                $chk = $pdo->prepare("SELECT id, status, current_partner_id FROM cylinders WHERE serial_number = ?");
                $chk->execute([$sn]);
                $existing = $chk->fetch();
                if ($existing) {
                    $pdo->prepare("UPDATE cylinders SET status = 'borrowed_from_partner', current_partner_id = ?, ownership_type = 'partner_owned', borrow_date = ?, daily_rent_rate = ?, free_days = ? WHERE id = ?")
                        ->execute([$partner_id, $tx_date, $default_rate, $free_days, $existing['id']]);
                    $cyl_id = $existing['id'];
                } else {
                    $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, current_partner_id, ownership_type, borrow_date, daily_rent_rate, free_days) VALUES (?, ?, ?, 'borrowed_from_partner', ?, 'partner_owned', ?, ?, ?)")
                        ->execute([$sn, $gas_type_id, $size_val, $partner_id, $tx_date, $default_rate, $free_days]);
                    $cyl_id = $pdo->lastInsertId();
                }
                $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after) VALUES (?, ?, ?, ?, ?, ?, 'borrowed_from_partner')")
                    ->execute([$tx_id, $cyl_id, $sn, $gas_type_id, $size_val, $existing ? $existing['status'] : 'new']);
                logCylinderTransaction($pdo, $cyl_id, null, $partner_id, 'partner_borrow', $notes_inp);
                $imported++;
            }
            if ($imported === 0) throw new Exception("No valid cylinders imported.");
            $pdo->commit();
            syncInventory($pdo);
            $pdo->prepare("UPDATE partner_transactions SET notes = CONCAT(COALESCE(notes,''), ' (', ?, ' cylinders)') WHERE id = ?")->execute([$imported, $tx_id]);
            $stmt = $pdo->prepare("SELECT company_name FROM partners WHERE id = ?");
            $stmt->execute([$partner_id]);
            $entity_name = $stmt->fetchColumn();
            $msg = "$imported cylinders borrowed from " . htmlspecialchars($entity_name ?: "Partner #$partner_id");
            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='partners.php';</script>"; exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Borrow failed: " . $e->getMessage();
        }
    }
}

// ── LEND ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'lend') {
    $partner_id  = intval($_POST['partner_id'] ?? 0);
    $tx_date     = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes_inp   = trim($_POST['notes'] ?? '');
    $default_rate = floatval($_POST['default_rent_rate'] ?? 0);
    $cylinder_ids = isset($_POST['cylinder_ids']) ? $_POST['cylinder_ids'] : [];
    $created_by  = $_SESSION['user_name'] ?? 'system';

    if ($partner_id <= 0 || empty($cylinder_ids)) {
        $error = "Select a partner and at least one cylinder to lend.";
    } else {
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO partner_transactions (partner_id, transaction_type, transaction_date, notes, created_by) VALUES (?, 'lent_to_partner', ?, ?, ?)");
            $ins->execute([$partner_id, $tx_date, $notes_inp ?: null, $created_by]);
            $tx_id = $pdo->lastInsertId();

            $lent = 0;
            foreach ($cylinder_ids as $cyl_id) {
                $cyl_id = intval($cyl_id);
                $fetch = $pdo->prepare("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ? AND c.ownership_type = 'owned' AND c.status IN ('empty', 'filled', 'in_maintenance') AND c.current_partner_id IS NULL");
                $fetch->execute([$cyl_id]);
                $cyl = $fetch->fetch();
                if (!$cyl) continue;
                $pdo->prepare("UPDATE cylinders SET status = 'lent_to_partner', current_partner_id = ?, borrow_date = ?, daily_rent_rate = ? WHERE id = ?")
                    ->execute([$partner_id, $tx_date, $default_rate, $cyl_id]);
                $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after, daily_rent_rate) VALUES (?, ?, ?, ?, ?, ?, 'lent_to_partner', ?)")
                    ->execute([$tx_id, $cyl_id, $cyl['serial_number'], $cyl['gas_type_id'], $cyl['size_capacity'], $cyl['status'], $default_rate]);
                logCylinderTransaction($pdo, $cyl_id, null, $partner_id, 'partner_lend', $notes_inp);
                $lent++;
            }
            if ($lent === 0) throw new Exception("No cylinders were lent.");
            $pdo->commit();
            syncInventory($pdo);
            $msg = "$lent cylinders lent to partner successfully.";
            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='partners.php';</script>"; exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Lend failed: " . $e->getMessage();
        }
    }
}

// ── FETCH DATA ──
$search_query  = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// ── Fetch gas types for modals ──
$gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();
$gas_size_map = [];
try {
    $gs_sizes = $pdo->query("SELECT gas_type_id, size_capacity FROM gas_sizes ORDER BY gas_type_id, sort_order")->fetchAll();
    foreach ($gs_sizes as $gs) {
        $gas_size_map[$gs['gas_type_id']][] = $gs['size_capacity'];
    }
} catch (PDOException $e) {}

$sql = "SELECT p.*,
        SUM(CASE WHEN c.ownership_type = 'partner_owned' AND c.status != 'returned_to_partner' THEN 1 ELSE 0 END) as borrowed_count,
        SUM(CASE WHEN c.status IN ('lent_to_partner', 'with_partner') THEN 1 ELSE 0 END) as lent_count
        FROM partners p
        LEFT JOIN cylinders c ON p.id = c.current_partner_id
        WHERE 1=1";
$params = [];

if (!empty($search_query)) {
    $sql .= " AND (p.company_name LIKE ? OR p.contact_person LIKE ? OR p.mobile LIKE ?)";
    $like = "%$search_query%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (!empty($status_filter)) {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter;
}
$sql .= " GROUP BY p.id ORDER BY p.company_name ASC";

$partners = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $partners = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = __('partners.load_failed') . $e->getMessage();
}
?>
<style>
.ci-top-wrap{background:var(--admin-card-bg,#fff);border:1px solid var(--admin-border,#e2e8f0);border-radius:12px;padding:0.75rem 1rem;margin-bottom:0.75rem;}
.ci-top-row{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;}
.ci-tools{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;}
.ci-srch{position:relative;display:flex;align-items:center;}
.ci-srch svg{position:absolute;left:10px;pointer-events:none;color:#94a3b8;flex-shrink:0;}
.ci-srch input{width:200px;padding:0.4rem 0.5rem 0.4rem 2rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.8rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;transition:border-color 0.15s;}
.ci-srch input:focus{border-color:var(--admin-accent,#3b82f6);}
.ci-select{padding:0.4rem 0.5rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.78rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;min-width:140px;}
.ci-btn-new{display:inline-flex;align-items:center;gap:0.35rem;padding:0.4rem 0.85rem;border-radius:8px;background:var(--admin-accent,#3b82f6);color:#fff;text-decoration:none;font-weight:700;font-size:0.8rem;white-space:nowrap;border:none;cursor:pointer;}
.ci-btn-new:hover{opacity:0.9;}
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
@media(max-width:768px){
.ci-srch input{width:140px;}
.ci-top-row{flex-direction:column;}
}
</style>
<link rel="stylesheet" href="partner.css">

<div class="page-header">
    <div class="page-header-title">
        <?php echo render_breadcrumb([['title' => __('nav.partners')]]); ?>
        <h2><?php echo __('partners.heading'); ?></h2>
        <p><?php echo __('partners.subtitle'); ?></p>
    </div>
    <div class="page-header-actions">
        <a href="partner-transactions.php" class="btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <?php echo __('partners.transaction_history'); ?>
        </a>
        <a href="partner-transaction-create.php" class="btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            <?php echo __('partners.quick_exchange'); ?>
        </a>
        <button class="btn-primary" onclick="openModal('addPartnerModal')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php echo __('partners.add'); ?>
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner" id="successAlert">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span><strong><?php echo __('common.success_label'); ?>:</strong> <?php echo htmlspecialchars($message); ?></span>
    <button class="modal-close" onclick="this.parentElement.remove()" aria-label="<?php echo __('common.close'); ?>"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner alert-error" id="errorAlert">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <span><strong><?php echo __('common.error_label'); ?>:</strong> <?php echo htmlspecialchars($error); ?></span>
    <button class="modal-close" onclick="this.parentElement.remove()" aria-label="<?php echo __('common.close'); ?>"></button>
</div>
<?php endif; ?>

<?php
$total_borrowed = 0; $total_lent = 0; $active_count = 0;
foreach ($partners as $p) {
    $total_borrowed += intval($p['borrowed_count']);
    $total_lent += intval($p['lent_count']);
    if ($p['status'] === 'active') $active_count++;
}
?>
<div class="ci-top-wrap">
    <div class="ci-top-row">
        <div class="ci-stats">
            <span class="ci-st-i"><span class="ci-dot ci-dot-blue"></span><strong><?php echo count($partners); ?></strong> Partners</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-amber"></span><strong><?php echo $total_borrowed; ?></strong> Borrowed</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-green"></span><strong><?php echo $total_lent; ?></strong> Lent</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-purple"></span><strong><?php echo $active_count; ?></strong> Active</span>
        </div>
        <div class="ci-tools">
            <form method="GET" style="display:flex;align-items:center;gap:0.5rem;">
                <div class="ci-srch"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" name="search" placeholder="<?php echo __('partners.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search_query); ?>"></div>
                <select name="status" class="ci-select" onchange="this.form.submit()">
                    <option value=""><?php echo __('partners.all_statuses'); ?></option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>><?php echo __('partners.status_active'); ?></option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>><?php echo __('partners.status_inactive'); ?></option>
                </select>
                <button type="submit" class="ci-btn-new">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <?php echo __('partners.search'); ?>
                </button>
            </form>
        </div>
    </div>
</div>

<div class="profile-card card-no-pad">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('partners.name'); ?></th>
                    <th><?php echo __('partners.contact_person'); ?></th>
                    <th><?php echo __('partners.mobile'); ?></th>
                    <th><?php echo __('partners.gst'); ?></th>
                    <th class="text-center"><?php echo __('partners.borrowed_stock'); ?></th>
                    <th class="text-center"><?php echo __('partners.lent_stock'); ?></th>
                    <th><?php echo __('partners.status'); ?></th>
                    <th class="text-right"><?php echo __('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($partners as $p): ?>
                <tr>
                    <td data-label="<?php echo __('partners.name'); ?>">
                        <a href="partner-profile.php?id=<?php echo $p['id']; ?>" class="partner-name-link">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <?php echo htmlspecialchars($p['company_name']); ?>
                        </a>
                    </td>
                    <td data-label="<?php echo __('partners.contact_person'); ?>"><?php echo htmlspecialchars($p['contact_person'] ?: 'N/A'); ?></td>
                    <td data-label="<?php echo __('partners.mobile'); ?>">
                        <a href="tel:<?php echo htmlspecialchars($p['mobile']); ?>" class="phone-link">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <?php echo htmlspecialchars($p['mobile']); ?>
                        </a>
                    </td>
                    <td data-label="<?php echo __('partners.gst'); ?>">
                        <?php if ($p['gst_number']): ?>
                            <code class="gst-code"><?php echo htmlspecialchars($p['gst_number']); ?></code>
                        <?php else: ?>
                            <span class="na-text">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?php echo __('partners.borrowed_stock'); ?>" class="text-center">
                        <span class="badge <?php echo $p['borrowed_count'] > 0 ? 'badge-empty' : 'badge-filled'; ?>"><?php echo intval($p['borrowed_count']); ?></span>
                    </td>
                    <td data-label="<?php echo __('partners.lent_stock'); ?>" class="text-center">
                        <span class="badge <?php echo $p['lent_count'] > 0 ? 'badge-rental' : 'badge-filled'; ?>"><?php echo intval($p['lent_count']); ?></span>
                    </td>
                    <td data-label="<?php echo __('partners.status'); ?>">
                        <span class="status-indicator <?php echo $p['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"></span>
                        <span class="badge <?php echo $p['status'] === 'active' ? 'badge-filled' : 'badge-under-maintenance'; ?>"><?php echo htmlspecialchars($p['status']); ?></span>
                    </td>
                    <td data-label="<?php echo __('common.actions'); ?>" class="text-right">
                        <div class="action-dropdown">
                            <button class="btn-icon" onclick="toggleDropdown(this)" aria-label="<?php echo __('common.actions'); ?>">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                            </button>
                            <div class="dropdown-menu">
                                <a href="partner-profile.php?id=<?php echo $p['id']; ?>" class="dropdown-item">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?php echo __('partners.profile'); ?>
                                </a>
                                <button class="dropdown-item" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    <?php echo __('partners.edit'); ?>
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item" onclick="openBorrowModal(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['company_name'], ENT_QUOTES); ?>')">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Borrow
                                </button>
                                <button class="dropdown-item" onclick="openLendModal(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['company_name'], ENT_QUOTES); ?>')">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                    Lend
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item dropdown-item-danger" onclick="openDeleteModal(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    <?php echo __('partners.delete'); ?>
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($partners)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state-lg">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <h4><?php echo __('partners.no_data'); ?></h4>
                            <p><?php echo __('partners.no_data_desc'); ?></p>
                            <button class="btn-primary" onclick="openModal('addPartnerModal')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php echo __('partners.add'); ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ════════ ADD MODAL ════════ -->
<div class="modal" id="addPartnerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <?php echo __('partners.modal_add_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('addPartnerModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="create_partner">
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php echo __('partners.section_basic'); ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('partners.company_name_label'); ?></label>
                    <input type="text" name="company_name" class="form-control" required placeholder="<?php echo __('partners.company_placeholder'); ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.contact_person_label'); ?></label>
                        <input type="text" name="contact_person" class="form-control" placeholder="<?php echo __('partners.contact_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.mobile_label'); ?></label>
                        <input type="tel" name="mobile" class="form-control" required placeholder="<?php echo __('partners.mobile_placeholder'); ?>">
                    </div>
                </div>
            </div>
            <div class="form-divider"></div>
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <?php echo __('partners.section_contact'); ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.email_label'); ?></label>
                        <input type="email" name="email" class="form-control" placeholder="<?php echo __('partners.email_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.gst_label'); ?></label>
                        <input type="text" name="gst_number" class="form-control" placeholder="<?php echo __('partners.gst_placeholder'); ?>">
                    </div>
                </div>
            </div>
            <div class="form-divider"></div>
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?php echo __('partners.section_address'); ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('partners.address_label'); ?></label>
                    <textarea name="address" class="form-control" rows="2" placeholder="<?php echo __('partners.address_placeholder'); ?>"></textarea>
                </div>
            </div>
            <div class="form-divider"></div>
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <?php echo __('partners.section_settings'); ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.status_label'); ?></label>
                        <select name="status" class="form-control">
                            <option value="active"><?php echo __('partners.status_active'); ?></option>
                            <option value="inactive"><?php echo __('partners.status_inactive'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('partners.notes_label'); ?></label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="<?php echo __('partners.notes_placeholder'); ?>"></textarea>
                </div>
            </div>
            <button type="submit" class="btn-primary modal-submit-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?php echo __('partners.modal_register_btn'); ?>
            </button>
        </form>
    </div>
</div>

<!-- ════════ EDIT MODAL ════════ -->
<div class="modal" id="editPartnerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <?php echo __('partners.modal_edit_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('editPartnerModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_partner">
            <input type="hidden" name="partner_id" id="edit_partner_id">
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php echo __('partners.section_basic'); ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('partners.company_name_label'); ?></label>
                    <input type="text" name="company_name" id="edit_company_name" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.contact_person_label'); ?></label>
                        <input type="text" name="contact_person" id="edit_contact_person" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.mobile_label'); ?></label>
                        <input type="tel" name="mobile" id="edit_mobile" class="form-control" required>
                    </div>
                </div>
            </div>
            <div class="form-divider"></div>
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <?php echo __('partners.section_contact'); ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.email_label'); ?></label>
                        <input type="email" name="email" id="edit_email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.gst_label'); ?></label>
                        <input type="text" name="gst_number" id="edit_gst_number" class="form-control">
                    </div>
                </div>
            </div>
            <div class="form-divider"></div>
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?php echo __('partners.section_address'); ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('partners.address_label'); ?></label>
                    <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="form-divider"></div>
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <?php echo __('partners.section_settings'); ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?php echo __('partners.status_label'); ?></label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active"><?php echo __('partners.status_active'); ?></option>
                            <option value="inactive"><?php echo __('partners.status_inactive'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('partners.notes_label'); ?></label>
                    <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <button type="submit" class="btn-primary modal-submit-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?php echo __('partners.modal_save_btn'); ?>
            </button>
        </form>
    </div>
</div>

<!-- ════════ DELETE MODAL ════════ -->
<div class="modal" id="deletePartnerModal">
    <div class="modal-content modal-content-danger">
        <div class="modal-header">
            <h3 class="danger-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php echo __('partners.modal_delete_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('deletePartnerModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_partner">
            <input type="hidden" name="partner_id" id="delete_partner_id">
            <div class="delete-warning">
                <div class="delete-warning-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div class="delete-warning-text">
                    <?php echo __('partners.delete_confirm_message'); ?> <strong id="delete_name_label"></strong>. <?php echo __('partners.delete_cylinders_warning'); ?>
                </div>
            </div>
            <div class="form-group delete-confirm-group">
                <label class="form-label"><?php echo __('partners.delete_confirm_label'); ?></label>
                <input type="text" name="confirm_name" id="delete_confirm_name" class="form-control" autocomplete="off" placeholder="<?php echo __('partners.delete_confirm_placeholder'); ?>" required oninput="validateDeleteConfirm()">
            </div>
            <button type="submit" id="delete_submit_btn" class="btn-danger modal-submit-btn" disabled>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <?php echo __('partners.modal_delete_btn'); ?>
            </button>
        </form>
    </div>
</div>

<!-- ════════ BORROW MODAL ════════ -->
<div class="modal" id="borrowModal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Borrow Cylinders: <span id="borrowPartnerName"></span>
            </h3>
            <button class="modal-close" onclick="closeModal('borrowModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="borrow">
            <input type="hidden" name="partner_id" id="borrowPartnerId">
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Transaction Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Daily Rent Rate (₹)</label>
                    <input type="number" name="default_rent_rate" class="form-control" value="0.00" min="0" step="0.01">
                </div>
                <div class="form-group">
                    <label class="form-label">Free Days</label>
                    <input type="number" name="free_days" class="form-control" value="0" min="0">
                </div>
            </div>
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Gas Type</label>
                    <select name="gas_type_id" id="borrowGasType" class="form-control" onchange="updateBorrowSizes()">
                        <option value="">-- Select gas type --</option>
                        <?php foreach ($gas_types as $gt): ?>
                        <option value="<?= $gt['id'] ?>"><?= htmlspecialchars($gt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Size / Capacity</label>
                    <select name="size_capacity" id="borrowSize" class="form-control">
                        <option value="">-- Select gas first --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity (expected)</label>
                    <input type="number" name="quantity" id="borrowQty" class="form-control" value="1" min="1" oninput="validateBorrowSerials()">
                </div>
            </div>
            <div class="form-section-title">Cylinder Serial Numbers</div>
            <div class="form-group">
                <textarea name="serials" id="borrowSerials" class="serial-input-area" placeholder="Enter one serial per line or comma-separated" oninput="validateBorrowSerials()"></textarea>
                <div class="serial-counter">
                    <span>Expected: <strong id="borrowExpected">0</strong></span>
                    <span class="serial-count-badge" id="borrowCountBadge">0 entered</span>
                </div>
            </div>
            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
            </div>
            <button type="submit" class="btn-primary modal-submit-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Register Borrow Transaction
            </button>
        </form>
    </div>
</div>

<!-- ════════ LEND MODAL ════════ -->
<div class="modal" id="lendModal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Lend Cylinders: <span id="lendPartnerName"></span>
            </h3>
            <button class="modal-close" onclick="closeModal('lendModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="lend">
            <input type="hidden" name="partner_id" id="lendPartnerId">
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label class="form-label">Transaction Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Default Rent Rate (₹/day)</label>
                    <input type="number" name="default_rent_rate" class="form-control" value="0.00" min="0" step="0.01">
                </div>
            </div>
            <div class="form-section-title">Select Cylinders to Lend</div>
            <div id="lendCylinderList" style="max-height:300px;overflow-y:auto;border:1px solid var(--admin-border);border-radius:8px;padding:0.75rem;margin-bottom:1rem;">
                <p style="color:var(--admin-muted);text-align:center;">Loading available cylinders...</p>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
            </div>
            <button type="submit" class="btn-primary modal-submit-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Confirm Lend Transaction
            </button>
        </form>
    </div>
</div>

<!-- ════════ GAS SIZE MAP ════════ -->
<script>
const gasSizeMap = <?php echo json_encode($gas_size_map); ?>;
</script>

<script>
let deletePartnerObj = null;

document.addEventListener('click', function(e) {
    document.querySelectorAll('.dropdown-menu.open').forEach(function(m) {
        if (!m.parentElement.contains(e.target)) m.classList.remove('open');
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const active = document.querySelector('.modal.active');
        if (active) closeModal(active.id);
    }
});

function openModal(id) {
    const el = document.getElementById(id);
    el.classList.add('active');
    document.body.style.overflow = 'hidden';
    const first = el.querySelector('input, button, textarea, select');
    if (first) first.focus();
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

function toggleDropdown(btn) {
    const menu = btn.nextElementSibling;
    document.querySelectorAll('.dropdown-menu.open').forEach(function(m) {
        if (m !== menu) m.classList.remove('open');
    });
    menu.classList.toggle('open');
}

// ── Borrow Modal ──
function openBorrowModal(id, name) {
    document.getElementById('borrowPartnerId').value = id;
    document.getElementById('borrowPartnerName').textContent = name;
    document.getElementById('borrowGasType').value = '';
    document.getElementById('borrowSize').innerHTML = '<option value="">-- Select gas first --</option>';
    document.getElementById('borrowQty').value = 1;
    document.getElementById('borrowSerials').value = '';
    document.getElementById('borrowExpected').textContent = '0';
    document.getElementById('borrowCountBadge').textContent = '0 entered';
    openModal('borrowModal');
}

function updateBorrowSizes() {
    const gid = document.getElementById('borrowGasType').value;
    const sel = document.getElementById('borrowSize');
    sel.innerHTML = '<option value="">-- Select size --</option>';
    if (gid && gasSizeMap[gid]) {
        gasSizeMap[gid].forEach(function(s) {
            const opt = document.createElement('option');
            opt.value = s; opt.textContent = s;
            sel.appendChild(opt);
        });
    }
}

function validateBorrowSerials() {
    const raw = document.getElementById('borrowSerials').value;
    const expected = parseInt(document.getElementById('borrowQty').value) || 0;
    const count = raw ? raw.split(/[\r\n,]+/).filter(function(s) { return s.trim() !== ''; }).length : 0;
    document.getElementById('borrowExpected').textContent = expected;
    document.getElementById('borrowCountBadge').textContent = count + ' entered';
}

// ── Lend Modal ──
function openLendModal(id, name) {
    document.getElementById('lendPartnerId').value = id;
    document.getElementById('lendPartnerName').textContent = name;
    openModal('lendModal');
    loadLendableCylinders(id);
}

function loadLendableCylinders(partnerId) {
    const container = document.getElementById('lendCylinderList');
    container.innerHTML = '<p style="color:var(--admin-muted);text-align:center;">Loading...</p>';
    fetch('partner-ajax.php?action=lendable_cylinders&partner_id=' + partnerId)
        .then(function(r) { return r.text(); })
        .then(function(html) { container.innerHTML = html; })
        .catch(function() { container.innerHTML = '<p style="color:var(--danger);text-align:center;">Failed to load cylinders.</p>'; });
}

function openEditModal(partner) {
    document.getElementById('edit_partner_id').value = partner.id;
    document.getElementById('edit_company_name').value = partner.company_name;
    document.getElementById('edit_contact_person').value = partner.contact_person || '';
    document.getElementById('edit_mobile').value = partner.mobile;
    document.getElementById('edit_email').value = partner.email || '';
    document.getElementById('edit_gst_number').value = partner.gst_number || '';
    document.getElementById('edit_address').value = partner.address || '';
    document.getElementById('edit_notes').value = partner.notes || '';
    document.getElementById('edit_status').value = partner.status;
    openModal('editPartnerModal');
}

function openDeleteModal(partner) {
    deletePartnerObj = partner;
    document.getElementById('delete_partner_id').value = partner.id;
    document.getElementById('delete_name_label').textContent = partner.company_name;
    document.getElementById('delete_confirm_name').value = '';
    document.getElementById('delete_submit_btn').disabled = true;
    openModal('deletePartnerModal');
}

function validateDeleteConfirm() {
    const typed = document.getElementById('delete_confirm_name').value;
    const btn = document.getElementById('delete_submit_btn');
    btn.disabled = !(deletePartnerObj && typed === deletePartnerObj.company_name);
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.alert-banner').forEach(function(al) {
        setTimeout(function() {
            al.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            al.style.opacity = '0';
            al.style.transform = 'translateY(-8px)';
            setTimeout(function() { if (al.parentElement) al.remove(); }, 400);
        }, 6000);
    });
});
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
