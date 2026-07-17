<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('rent_cylinders.title');
$active_menu = "rent_cylinders";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/gst/json/schema.php';
runPartnerMigrations($pdo);
runVendorAccountingMigrations($pdo);

$message = '';
$error = '';
// (tab system removed in P4 — now uses filter system)

// ── PARTNER POST HANDLERS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_partner') {
        $company_name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        if (empty($company_name) || empty($mobile)) {
            $error = __('partners.error_required');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO partners (company_name, contact_person, mobile, email, gst_number, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_name, $contact_person, $mobile, $email, $gst_number, $address, $notes, $status]);
                $log_stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
                $log_stmt->execute([$_SESSION['user_name'] ?? 'system', 'Create Partner', "Registered partner company: $company_name"]);
                $message = sprintf(__('partners.created'), $company_name);
            } catch (PDOException $e) {
                $error = __('partners.create_failed') . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'edit_partner') {
        $partner_id = intval($_POST['partner_id'] ?? 0);
        $company_name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        if ($partner_id > 0 && !empty($company_name) && !empty($mobile)) {
            try {
                $stmt = $pdo->prepare("UPDATE partners SET company_name=?, contact_person=?, mobile=?, email=?, gst_number=?, address=?, notes=?, status=? WHERE id=?");
                $stmt->execute([$company_name, $contact_person, $mobile, $email, $gst_number, $address, $notes, $status, $partner_id]);
                $log_stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
                $log_stmt->execute([$_SESSION['user_name'] ?? 'system', 'Update Partner', "Updated partner company details: $company_name (ID: $partner_id)"]);
                $message = sprintf(__('partners.updated'), $company_name);
            } catch (PDOException $e) {
                $error = __('partners.update_failed') . $e->getMessage();
            }
        } else {
            $error = __('partners.error_required');
        }
    }

    if ($_POST['action'] === 'delete_partner') {
        $partner_id = intval($_POST['partner_id'] ?? 0);
        $confirm_name = trim($_POST['confirm_name'] ?? '');
        if ($partner_id > 0 && !empty($confirm_name)) {
            try {
                $chk_cyls = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_partner_id = ?");
                $chk_cyls->execute([$partner_id]);
                if ($chk_cyls->fetchColumn() > 0) {
                    $error = sprintf(__('partners.cannot_delete'), $chk_cyls->fetchColumn());
                } else {
                    $chk_partner = $pdo->prepare("SELECT company_name FROM partners WHERE id = ?");
                    $chk_partner->execute([$partner_id]);
                    $partner_name = $chk_partner->fetchColumn();
                    if ($partner_name === $confirm_name) {
                        $pdo->beginTransaction();
                        $pdo->prepare("DELETE pti FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id = pt.id WHERE pt.partner_id = ?")->execute([$partner_id]);
                        $pdo->prepare("DELETE FROM partner_transactions WHERE partner_id = ?")->execute([$partner_id]);
                        $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$partner_id]);
                        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, ?, ?)");
                        $log_stmt->execute([$_SESSION['user_name'] ?? 'system', 'Delete Partner', "Permanently deleted partner: $partner_name"]);
                        $pdo->commit();
                        $message = sprintf(__('partners.deleted'), $partner_name);
                    } else {
                        $error = __('partners.delete_confirm_failed');
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = __('partners.delete_failed') . $e->getMessage();
            }
        }
    }

    // ── VENDOR POST HANDLERS ──
    if ($_POST['action'] === 'create_vendor') {
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $gst_registration_type = $_POST['gst_registration_type'] ?? 'regular';
        $pan = trim($_POST['pan'] ?? '');
        $tan = trim($_POST['tan'] ?? '');
        $state_code = intval($_POST['state_code'] ?? 0);
        $city = trim($_POST['city'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');
        $bank_account_holder = trim($_POST['bank_account_holder'] ?? '');
        $bank_account_number = trim($_POST['bank_account_number'] ?? '');
        $bank_ifsc = trim($_POST['bank_ifsc'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_branch = trim($_POST['bank_branch'] ?? '');
        $payment_terms = intval($_POST['payment_terms'] ?? 30);
        $notes = trim($_POST['notes'] ?? '');
        $states = gstnStateCodes();
        $state_name = $state_code ? ($states[$state_code] ?? null) : null;
        if (empty($name) || empty($mobile)) {
            $error = __('vendors.error_required');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO vendors (name, contact_person, mobile, email, address, gst_number, gst_registration_type, pan, tan, state_code, state_name, city, pincode, bank_account_holder, bank_account_number, bank_ifsc, bank_name, bank_branch, payment_terms, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $contact_person, $mobile, $email ?: null, $address, $gst_number ?: null, $gst_registration_type, $pan ?: null, $tan ?: null, $state_code ?: null, $state_name, $city ?: null, $pincode ?: null, $bank_account_holder ?: null, $bank_account_number ?: null, $bank_ifsc ?: null, $bank_name ?: null, $bank_branch ?: null, $payment_terms, $notes ?: null]);
                $message = __('vendors.created');
            } catch (PDOException $e) {
                $error = __('vendors.create_failed') . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'edit_vendor') {
        $id = intval($_POST['vendor_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $gst_registration_type = $_POST['gst_registration_type'] ?? 'regular';
        $pan = trim($_POST['pan'] ?? '');
        $tan = trim($_POST['tan'] ?? '');
        $state_code = intval($_POST['state_code'] ?? 0);
        $city = trim($_POST['city'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');
        $bank_account_holder = trim($_POST['bank_account_holder'] ?? '');
        $bank_account_number = trim($_POST['bank_account_number'] ?? '');
        $bank_ifsc = trim($_POST['bank_ifsc'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_branch = trim($_POST['bank_branch'] ?? '');
        $payment_terms = intval($_POST['payment_terms'] ?? 30);
        $notes = trim($_POST['notes'] ?? '');
        $states = gstnStateCodes();
        $state_name = $state_code ? ($states[$state_code] ?? null) : null;
        if ($id > 0 && !empty($name) && !empty($mobile)) {
            try {
                $stmt = $pdo->prepare("UPDATE vendors SET name=?, contact_person=?, mobile=?, email=?, address=?, gst_number=?, gst_registration_type=?, pan=?, tan=?, state_code=?, state_name=?, city=?, pincode=?, bank_account_holder=?, bank_account_number=?, bank_ifsc=?, bank_name=?, bank_branch=?, payment_terms=?, notes=? WHERE id=?");
                $stmt->execute([$name, $contact_person, $mobile, $email ?: null, $address, $gst_number ?: null, $gst_registration_type, $pan ?: null, $tan ?: null, $state_code ?: null, $state_name, $city ?: null, $pincode ?: null, $bank_account_holder ?: null, $bank_account_number ?: null, $bank_ifsc ?: null, $bank_name ?: null, $bank_branch ?: null, $payment_terms, $notes ?: null, $id]);
                $message = __('vendors.updated');
            } catch (PDOException $e) {
                $error = __('vendors.update_failed') . $e->getMessage();
            }
        } else {
            $error = __('vendors.error_required');
        }
    }

    if ($_POST['action'] === 'delete_vendor') {
        $id = intval($_POST['vendor_id'] ?? 0);
        if ($id > 0) {
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_vendor_id = ? AND status = 'sent_to_vendor'");
                $check->execute([$id]);
                if ($check->fetchColumn() > 0) {
                    $error = sprintf(__('vendors.cannot_delete'), $check->fetchColumn());
                } else {
                    $pdo->prepare("DELETE FROM vendors WHERE id=?")->execute([$id]);
                    $message = __('vendors.deleted');
                }
            } catch (PDOException $e) {
                $error = __('vendors.delete_failed') . $e->getMessage();
            }
        }
    }
}

// ── DATA FETCHING ──
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

$sql = "SELECT p.*,
        SUM(CASE WHEN c.ownership_type = 'partner_owned' AND c.status != 'returned_to_partner' THEN 1 ELSE 0 END) as borrowed_count,
        SUM(CASE WHEN c.status IN ('lent_to_partner', 'with_partner') THEN 1 ELSE 0 END) as lent_count
        FROM partners p
        LEFT JOIN cylinders c ON p.id = c.current_partner_id
        WHERE 1=1";
$params = [];
if (!empty($search_query)) {
    $sql .= " AND (p.company_name LIKE ? OR p.contact_person LIKE ? OR p.mobile LIKE ?)";
    $like_val = "%" . $search_query . "%";
    $params[] = $like_val; $params[] = $like_val; $params[] = $like_val;
}
if (!empty($status_filter)) {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter;
}
$sql .= " GROUP BY p.id ORDER BY p.company_name ASC";
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $partners = $stmt->fetchAll();
} catch (PDOException $e) {
    $partners = [];
    $error = __('partners.load_failed') . $e->getMessage();
}

$v_sql = "SELECT v.*,
        SUM(CASE WHEN c.ownership_type = 'vendor_owned' AND c.status != 'returned_to_vendor' THEN 1 ELSE 0 END) as borrowed_count
        FROM vendors v
        LEFT JOIN cylinders c ON v.id = c.current_vendor_id
        WHERE 1=1";
$v_params = [];
if (!empty($search_query)) {
    $v_sql .= " AND (v.name LIKE ? OR v.contact_person LIKE ? OR v.mobile LIKE ?)";
    $like_val = "%" . $search_query . "%";
    $v_params[] = $like_val; $v_params[] = $like_val; $v_params[] = $like_val;
}
$v_sql .= " GROUP BY v.id ORDER BY v.name ASC";
$vendors = [];
try {
    $v_stmt = $pdo->prepare($v_sql);
    $v_stmt->execute($v_params);
    $vendors = $v_stmt->fetchAll();
} catch (PDOException $e) {}

$partner_count = count($partners);
$vendor_count = count($vendors);

$vendor_total_borrowed = 0;
foreach ($vendors as $v) { $vendor_total_borrowed += intval($v['borrowed_count']); }
$partner_total_borrowed = 0;
foreach ($partners as $p) { $partner_total_borrowed += intval($p['borrowed_count']); }
$partner_total_lent = 0;
foreach ($partners as $p) { $partner_total_lent += intval($p['lent_count']); }
$total_borrowed = $partner_total_borrowed + $vendor_total_borrowed;

// ── UNIFIED LISTING ──
$active_filter = isset($_GET['filter']) && in_array($_GET['filter'], ['partners', 'vendors']) ? $_GET['filter'] : 'all';

$unified_list = [];
foreach ($partners as $p) {
    $p['entity_type'] = 'partners';
    $p['display_name'] = $p['company_name'];
    $unified_list[] = $p;
}
foreach ($vendors as $v) {
    $v['entity_type'] = 'vendors';
    $v['display_name'] = $v['name'];
    $v['status'] = 'active'; // vendors always active
    $unified_list[] = $v;
}
usort($unified_list, function($a, $b) {
    return strcasecmp($a['display_name'], $b['display_name']);
});
?>

<div class="page-header">
    <div class="page-header-title">
        <h2><?php echo __('rent_cylinders.heading'); ?></h2>
        <p><?php echo __('rent_cylinders.subtitle'); ?></p>
    </div>
    <div class="page-header-actions">
        <a href="partner-transaction-create.php" class="btn-primary" aria-label="New Borrow">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <?php echo __('partner_tx.new_borrow'); ?>
        </a>
        <a href="partner-transactions.php" class="btn-secondary" aria-label="<?php echo __('rent_cylinders.transactions'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <?php echo __('rent_cylinders.transactions'); ?>
        </a>
        <a href="partner-reports.php" class="btn-accent" aria-label="<?php echo __('rent_cylinders.reports'); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <?php echo __('rent_cylinders.reports'); ?>
        </a>
        <div class="action-dropdown">
            <button class="btn-primary" onclick="toggleDropdown(this)" aria-label="<?php echo __('common.add_user'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php echo __('common.add_user'); ?>
            </button>
            <div class="dropdown-menu">
                <button class="dropdown-item" onclick="openModal('addPartnerModal')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <?php echo __('common.partner'); ?>
                </button>
                <button class="dropdown-item" onclick="openModal('addVendorModal')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    <?php echo __('common.vendor'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="stat-grid">
    <a href="?filter=partners" class="stat-card-link" aria-label="<?php echo __('rent_cylinders.total_partners'); ?>: <?php echo $partner_count; ?>">
        <div class="stat-card animate-in animate-in-d1">
            <div class="icon-glow-blue">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="stat-info">
                <h4><?php echo $partner_count; ?></h4>
                <p><?php echo __('rent_cylinders.total_partners'); ?></p>
            </div>
        </div>
    </a>
    <a href="?filter=vendors" class="stat-card-link" aria-label="<?php echo __('rent_cylinders.total_vendors'); ?>: <?php echo $vendor_count; ?>">
        <div class="stat-card animate-in animate-in-d3">
            <div class="icon-glow-green">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="stat-info">
                <h4><?php echo $vendor_count; ?></h4>
                <p><?php echo __('rent_cylinders.total_vendors'); ?></p>
            </div>
        </div>
    </a>
    <a href="?filter=all" class="stat-card-link" aria-label="<?php echo __('rent_cylinders.total_borrowed'); ?>: <?php echo $total_borrowed; ?>">
        <div class="stat-card animate-in animate-in-d4">
            <div class="icon-glow-purple">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            </div>
            <div class="stat-info">
                <h4><?php echo $total_borrowed; ?></h4>
                <p><?php echo __('rent_cylinders.total_borrowed'); ?></p>
            </div>
        </div>
    </a>
</div>

<!-- Filter pills -->
<div class="filter-pills" role="group" aria-label="Filter listing">
    <a href="?filter=all" class="pill <?php echo $active_filter === 'all' ? 'active' : ''; ?>" data-filter="all"<?php echo $active_filter === 'all' ? ' aria-current="page"' : ''; ?>>
        <?php echo __('common.all'); ?>
        <span class="pill-count">(<?php echo $partner_count + $vendor_count; ?>)</span>
    </a>
    <a href="?filter=partners" class="pill <?php echo $active_filter === 'partners' ? 'active' : ''; ?>" data-filter="partners"<?php echo $active_filter === 'partners' ? ' aria-current="page"' : ''; ?>>
        <svg class="pill-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <?php echo __('rent_cylinders.tab_partners'); ?>
        <span class="pill-count">(<?php echo $partner_count; ?>)</span>
    </a>
    <a href="?filter=vendors" class="pill <?php echo $active_filter === 'vendors' ? 'active' : ''; ?>" data-filter="vendors"<?php echo $active_filter === 'vendors' ? ' aria-current="page"' : ''; ?>>
        <svg class="pill-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
        <?php echo __('rent_cylinders.tab_vendors'); ?>
        <span class="pill-count">(<?php echo $vendor_count; ?>)</span>
    </a>
</div>

<?php if ($message): ?>
<div class="alert-banner" id="successAlert">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span><strong><?php echo __('common.success_label'); ?>:</strong> <?php echo htmlspecialchars($message); ?></span>
    <button class="modal-close" style="margin-left:auto;flex-shrink:0;" onclick="this.parentElement.remove()" aria-label="<?php echo __('common.close'); ?>"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert-banner" style="background:var(--danger-soft);color:var(--danger);border-color:#fca5a5;" id="errorAlert">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <span><strong><?php echo __('common.error_label'); ?>:</strong> <?php echo htmlspecialchars($error); ?></span>
    <button class="modal-close" style="margin-left:auto;flex-shrink:0;" onclick="this.parentElement.remove()" aria-label="<?php echo __('common.close'); ?>"></button>
</div>
<?php endif; ?>

<!-- Unified listing -->
<section aria-label="Unified partner and vendor listing">
<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table" aria-label="Entity listing">
            <thead>
                <tr>
                    <th><?php echo __('common.type'); ?></th>
                    <th><?php echo __('partners.name'); ?></th>
                    <th><?php echo __('partners.contact_person'); ?></th>
                    <th><?php echo __('partners.mobile'); ?></th>
                    <th class="text-center"><?php echo __('dashboard.borrowed'); ?></th>
                    <th><?php echo __('common.status'); ?></th>
                    <th class="text-right"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unified_list as $item):
                    $is_partner = $item['entity_type'] === 'partners';
                    // Apply filter
                    if ($active_filter !== 'all' && $item['entity_type'] !== $active_filter) { continue; }
                    $profile_url = $is_partner ? 'partner-profile.php?id=' . $item['id'] : 'vendor-profile.php?id=' . $item['id'];
                    $name = $item['display_name'];
                    $contact = htmlspecialchars($item['contact_person'] ?? $item['contact'] ?? 'N/A');
                    $mobile = htmlspecialchars($item['mobile']);
                    $borrowed = intval($item['borrowed_count']);
                ?>
                <tr class="unified-row" data-type="<?php echo $item['entity_type']; ?>">
                    <td data-label="Type">
                        <span class="badge <?php echo $is_partner ? 'badge-filled' : 'badge-under-maintenance'; ?>" style="font-size:0.75rem;">
                            <?php echo $is_partner ? __('partners.title') : __('vendors.title'); ?>
                        </span>
                    </td>
                    <td data-label="<?php echo __('partners.name'); ?>">
                        <a href="<?php echo $profile_url; ?>" class="<?php echo $is_partner ? 'partner-name-link' : 'vendor-name-link'; ?>">
                            <?php if ($is_partner): ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <?php else: ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($name); ?>
                        </a>
                    </td>
                    <td data-label="<?php echo __('partners.contact_person'); ?>"><?php echo $contact; ?></td>
                    <td data-label="<?php echo __('partners.mobile'); ?>">
                        <a href="tel:<?php echo $mobile; ?>" class="phone-link">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <?php echo $mobile; ?>
                        </a>
                    </td>
                    <td data-label="<?php echo __('dashboard.borrowed'); ?>" class="text-center">
                        <span class="badge <?php echo $borrowed > 0 ? 'badge-empty' : 'badge-filled'; ?>"><?php echo $borrowed . ' ' . __('dashboard.borrowed'); ?></span>
                    </td>
                    <td data-label="<?php echo __('common.status'); ?>">
                        <?php if ($is_partner): ?>
                        <span class="status-indicator <?php echo $item['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"></span>
                        <span class="badge <?php echo $item['status'] === 'active' ? 'badge-filled' : 'badge-under-maintenance'; ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                        <?php else: ?>
                        <span class="badge badge-filled"><?php echo __('common.active'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions" class="text-right">
                        <?php if ($is_partner): ?>
                        <div class="action-dropdown">
                            <button class="btn-icon" onclick="toggleDropdown(this)" aria-label="<?php echo __('common.actions'); ?>">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                            </button>
                            <div class="dropdown-menu">
                                <a href="partner-transaction-create.php?partner_id=<?php echo $item['id']; ?>" class="dropdown-item">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <?php echo __('partner_tx.borrow_more'); ?>
                                </a>
                                <?php if ($borrowed > 0): ?>
                                <a href="partner-transaction-create.php?partner_id=<?php echo $item['id']; ?>&mode=return" class="dropdown-item">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    <?php echo __('partner_tx.return_cylinders'); ?>
                                </a>
                                <?php endif; ?>
                                <?php if (intval($item['lent_count']) > 0): ?>
                                <a href="partner-transaction-create.php?partner_id=<?php echo $item['id']; ?>&mode=receive_back" class="dropdown-item">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <?php echo __('partner_tx.receive_back_cylinders'); ?>
                                </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a href="partner-profile.php?id=<?php echo $item['id']; ?>" class="dropdown-item">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?php echo __('partners.profile'); ?>
                                </a>
                                <button class="dropdown-item" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    <?php echo __('partners.edit'); ?>
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item dropdown-item-danger" onclick="openDeleteModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    <?php echo __('partners.delete'); ?>
                                </button>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="action-dropdown">
                            <button class="btn-icon" onclick="toggleDropdown(this)" aria-label="<?php echo __('common.actions'); ?>">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                            </button>
                            <div class="dropdown-menu">
                                <a href="partner-transaction-create.php?vendor_id=<?php echo $item['id']; ?>" class="dropdown-item">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <?php echo __('partner_tx.borrow_more'); ?>
                                </a>
                                <?php if (intval($item['active_refill_count'] ?? 0) > 0): ?>
                                <a href="vendor-profile.php?id=<?php echo $item['id']; ?>" class="dropdown-item">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    <?php echo __('cylinders.receive_filled'); ?>
                                </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a href="vendor-profile.php?id=<?php echo $item['id']; ?>" class="dropdown-item">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?php echo __('partners.profile'); ?>
                                </a>
                                <button class="dropdown-item" onclick='editVendor(<?php echo json_encode([
                                    'id' => $item['id'],
                                    'name' => $item['name'] ?? '',
                                    'contact_person' => $item['contact_person'] ?? '',
                                    'mobile' => $item['mobile'] ?? '',
                                    'email' => $item['email'] ?? '',
                                    'address' => $item['address'] ?? '',
                                    'gst_number' => $item['gst_number'] ?? '',
                                    'gst_registration_type' => $item['gst_registration_type'] ?? 'regular',
                                    'pan' => $item['pan'] ?? '',
                                    'tan' => $item['tan'] ?? '',
                                    'state_code' => $item['state_code'] ?? '',
                                    'city' => $item['city'] ?? '',
                                    'pincode' => $item['pincode'] ?? '',
                                    'bank_account_holder' => $item['bank_account_holder'] ?? '',
                                    'bank_account_number' => $item['bank_account_number'] ?? '',
                                    'bank_ifsc' => $item['bank_ifsc'] ?? '',
                                    'bank_name' => $item['bank_name'] ?? '',
                                    'bank_branch' => $item['bank_branch'] ?? '',
                                    'payment_terms' => $item['payment_terms'] ?? 30,
                                    'notes' => $item['notes'] ?? '',
                                ]); ?>)'>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    <?php echo __('partners.edit'); ?>
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item dropdown-item-danger" onclick="deleteVendor(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    <?php echo __('partners.delete'); ?>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($unified_list)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <h4><?php echo __('partners.no_data'); ?></h4>
                            <p><?php echo __('rent_cylinders.subtitle'); ?></p>
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
</section>

<!-- ══════════════════════════════════ -->
<!--  PARTNER MODALS                    -->
<!-- ══════════════════════════════════ -->

<div class="modal" id="addPartnerModal" role="dialog" aria-modal="true" aria-labelledby="addPartnerTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="addPartnerTitle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <?php echo __('partners.modal_add_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('addPartnerModal')" aria-label="<?php echo __('common.close'); ?>"></button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="create_partner">
            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php echo __('partners.section_basic'); ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_company_name"><?php echo __('partners.company_name_label'); ?></label>
                    <input type="text" name="company_name" id="add_company_name" class="form-control" required placeholder="<?php echo __('partners.company_placeholder'); ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_contact_person"><?php echo __('partners.contact_person_label'); ?></label>
                        <input type="text" name="contact_person" id="add_contact_person" class="form-control" placeholder="<?php echo __('partners.contact_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_mobile"><?php echo __('partners.mobile_label'); ?></label>
                        <input type="tel" name="mobile" id="add_mobile" class="form-control" required placeholder="<?php echo __('partners.mobile_placeholder'); ?>">
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
                        <label class="form-label" for="add_email"><?php echo __('partners.email_label'); ?></label>
                        <input type="email" name="email" id="add_email" class="form-control" placeholder="<?php echo __('partners.email_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_gst_number"><?php echo __('partners.gst_label'); ?></label>
                        <input type="text" name="gst_number" id="add_gst_number" class="form-control" placeholder="<?php echo __('partners.gst_placeholder'); ?>">
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
                    <label class="form-label" for="add_address"><?php echo __('partners.address_label'); ?></label>
                    <textarea name="address" id="add_address" class="form-control" rows="2" placeholder="<?php echo __('partners.address_placeholder'); ?>"></textarea>
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
                        <label class="form-label" for="add_status"><?php echo __('partners.status_label'); ?></label>
                        <select name="status" id="add_status" class="form-control">
                            <option value="active"><?php echo __('partners.status_active'); ?></option>
                            <option value="inactive"><?php echo __('partners.status_inactive'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_notes"><?php echo __('partners.notes_label'); ?></label>
                    <textarea name="notes" id="add_notes" class="form-control" rows="2" placeholder="<?php echo __('partners.notes_placeholder'); ?>"></textarea>
                </div>
            </div>
            <button type="submit" class="btn-primary modal-submit-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?php echo __('partners.modal_register_btn'); ?>
            </button>
        </form>
    </div>
</div>

<div class="modal" id="editPartnerModal" role="dialog" aria-modal="true" aria-labelledby="editPartnerTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="editPartnerTitle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <?php echo __('partners.modal_edit_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('editPartnerModal')" aria-label="<?php echo __('common.close'); ?>"></button>
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
                    <label class="form-label" for="edit_company_name"><?php echo __('partners.company_name_label'); ?></label>
                    <input type="text" name="company_name" id="edit_company_name" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="edit_contact_person"><?php echo __('partners.contact_person_label'); ?></label>
                        <input type="text" name="contact_person" id="edit_contact_person" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_mobile"><?php echo __('partners.mobile_label'); ?></label>
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
                        <label class="form-label" for="edit_email"><?php echo __('partners.email_label'); ?></label>
                        <input type="email" name="email" id="edit_email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_gst_number"><?php echo __('partners.gst_label'); ?></label>
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
                    <label class="form-label" for="edit_address"><?php echo __('partners.address_label'); ?></label>
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
                        <label class="form-label" for="edit_status"><?php echo __('partners.status_label'); ?></label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active"><?php echo __('partners.status_active'); ?></option>
                            <option value="inactive"><?php echo __('partners.status_inactive'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_notes"><?php echo __('partners.notes_label'); ?></label>
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

<div class="modal" id="deletePartnerModal" role="dialog" aria-modal="true" aria-labelledby="deletePartnerTitle">
    <div class="modal-content modal-content-danger">
        <div class="modal-header">
            <h3 id="deletePartnerTitle" class="danger-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php echo __('partners.modal_delete_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('deletePartnerModal')" aria-label="<?php echo __('common.close'); ?>"></button>
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

<!-- ══════════════════════════════════ -->
<!--  VENDOR MODALS                     -->
<!-- ══════════════════════════════════ -->

<div class="modal" id="addVendorModal" role="dialog" aria-modal="true" aria-labelledby="addVendorTitle">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3 id="addVendorTitle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                <?php echo __('vendors.modal.add_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('addVendorModal')" aria-label="<?php echo __('common.close'); ?>"></button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="create_vendor">
            <div class="form-section">
                <div class="form-section-title">Company Details</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="vendor_name"><?php echo __('vendors.modal.company_name'); ?> <span class="required-star">*</span></label>
                        <input type="text" name="name" id="vendor_name" class="form-control" required placeholder="<?php echo __('vendors.modal.company_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_contact"><?php echo __('vendors.modal.contact_person'); ?></label>
                        <input type="text" name="contact_person" id="vendor_contact" class="form-control" placeholder="<?php echo __('vendors.modal.contact_placeholder'); ?>">
                    </div>
                </div>

                <div class="form-section-title">Contact Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="vendor_mobile"><?php echo __('vendors.modal.mobile_label'); ?> <span class="required-star">*</span></label>
                        <input type="tel" name="mobile" id="vendor_mobile" class="form-control" required placeholder="<?php echo __('vendors.modal.mobile_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_email">Email</label>
                        <input type="email" name="email" id="vendor_email" class="form-control" placeholder="vendor@company.com">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="vendor_address"><?php echo __('vendors.modal.address_label'); ?></label>
                    <textarea name="address" id="vendor_address" class="form-control" rows="2" placeholder="<?php echo __('vendors.modal.address_placeholder'); ?>"></textarea>
                </div>

                <div class="form-section-title">GST / Tax Information</div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="vendor_gst">GST Number</label>
                        <input type="text" name="gst_number" id="vendor_gst" class="form-control" placeholder="27AAAAA0000A1Z5">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_gst_reg_type">Registration Type</label>
                        <select name="gst_registration_type" id="vendor_gst_reg_type" class="form-control">
                            <option value="regular">Regular</option>
                            <option value="composition">Composition</option>
                            <option value="unregistered">Unregistered</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_pan">PAN</label>
                        <input type="text" name="pan" id="vendor_pan" class="form-control" placeholder="AAAAA0000A">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_tan">TAN</label>
                        <input type="text" name="tan" id="vendor_tan" class="form-control" placeholder="AAAA12345A">
                    </div>
                </div>

                <div class="form-section-title">Location</div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="vendor_state">State</label>
                        <select name="state_code" id="vendor_state" class="form-control">
                            <option value="">-- Select State --</option>
                            <?php foreach (gstnStateCodes() as $code => $name): ?>
                            <option value="<?= $code ?>"><?= htmlspecialchars(ucwords(strtolower($name))) ?> (<?= $code ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_city">City</label>
                        <input type="text" name="city" id="vendor_city" class="form-control" placeholder="Mumbai">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_pincode">Pincode</label>
                        <input type="text" name="pincode" id="vendor_pincode" class="form-control" placeholder="400001">
                    </div>
                </div>

                <div class="form-section-title">Bank Details</div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="vendor_bank_holder">Account Holder Name</label>
                        <input type="text" name="bank_account_holder" id="vendor_bank_holder" class="form-control" placeholder="e.g. ABC Refills Pvt Ltd">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_bank_acno">Account Number</label>
                        <input type="text" name="bank_account_number" id="vendor_bank_acno" class="form-control" placeholder="XXXXXXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_bank_ifsc">IFSC Code</label>
                        <input type="text" name="bank_ifsc" id="vendor_bank_ifsc" class="form-control" placeholder="SBIN0001234">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_bank_name">Bank Name</label>
                        <input type="text" name="bank_name" id="vendor_bank_name" class="form-control" placeholder="State Bank of India">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="vendor_bank_branch">Branch</label>
                        <input type="text" name="bank_branch" id="vendor_bank_branch" class="form-control" placeholder="Mumbai Main">
                    </div>
                </div>

                <div class="form-section-title">Settings</div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="vendor_payment_terms">Payment Terms (days)</label>
                        <input type="number" name="payment_terms" id="vendor_payment_terms" class="form-control" value="30" min="0" max="365">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="vendor_notes">Notes</label>
                    <textarea name="notes" id="vendor_notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <button type="submit" class="btn-primary modal-submit-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?php echo __('vendors.modal.register_btn'); ?>
            </button>
        </form>
    </div>
</div>

<div class="modal" id="editVendorModal" role="dialog" aria-modal="true" aria-labelledby="editVendorTitle">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3 id="editVendorTitle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <?php echo __('vendors.modal.edit_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('editVendorModal')" aria-label="<?php echo __('common.close'); ?>"></button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_vendor">
            <input type="hidden" name="vendor_id" id="editVendorId2" value="">
            <div class="form-section">
                <div class="form-section-title">Company Details</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="editVendorName2"><?php echo __('vendors.modal.company_name'); ?> <span class="required-star">*</span></label>
                        <input type="text" name="name" id="editVendorName2" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorContact2"><?php echo __('vendors.modal.contact_person'); ?></label>
                        <input type="text" name="contact_person" id="editVendorContact2" class="form-control">
                    </div>
                </div>

                <div class="form-section-title">Contact Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="editVendorMobile2"><?php echo __('vendors.modal.mobile_label'); ?> <span class="required-star">*</span></label>
                        <input type="tel" name="mobile" id="editVendorMobile2" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorEmail2">Email</label>
                        <input type="email" name="email" id="editVendorEmail2" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editVendorAddress2"><?php echo __('vendors.modal.address_label'); ?></label>
                    <textarea name="address" id="editVendorAddress2" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-section-title">GST / Tax Information</div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="editVendorGst2">GST Number</label>
                        <input type="text" name="gst_number" id="editVendorGst2" class="form-control" placeholder="27AAAAA0000A1Z5">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorGstRegType2">Registration Type</label>
                        <select name="gst_registration_type" id="editVendorGstRegType2" class="form-control">
                            <option value="regular">Regular</option>
                            <option value="composition">Composition</option>
                            <option value="unregistered">Unregistered</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorPan2">PAN</label>
                        <input type="text" name="pan" id="editVendorPan2" class="form-control" placeholder="AAAAA0000A">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorTan2">TAN</label>
                        <input type="text" name="tan" id="editVendorTan2" class="form-control" placeholder="AAAA12345A">
                    </div>
                </div>

                <div class="form-section-title">Location</div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="editVendorStateCode2">State</label>
                        <select name="state_code" id="editVendorStateCode2" class="form-control">
                            <option value="">-- Select State --</option>
                            <?php foreach (gstnStateCodes() as $code => $name): ?>
                            <option value="<?= $code ?>"><?= htmlspecialchars(ucwords(strtolower($name))) ?> (<?= $code ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorCity2">City</label>
                        <input type="text" name="city" id="editVendorCity2" class="form-control" placeholder="Mumbai">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorPincode2">Pincode</label>
                        <input type="text" name="pincode" id="editVendorPincode2" class="form-control" placeholder="400001">
                    </div>
                </div>

                <div class="form-section-title">Bank Details</div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="editVendorBankHolder2">Account Holder Name</label>
                        <input type="text" name="bank_account_holder" id="editVendorBankHolder2" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorBankAcno2">Account Number</label>
                        <input type="text" name="bank_account_number" id="editVendorBankAcno2" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorBankIfsc2">IFSC Code</label>
                        <input type="text" name="bank_ifsc" id="editVendorBankIfsc2" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorBankName2">Bank Name</label>
                        <input type="text" name="bank_name" id="editVendorBankName2" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editVendorBankBranch2">Branch</label>
                        <input type="text" name="bank_branch" id="editVendorBankBranch2" class="form-control">
                    </div>
                </div>

                <div class="form-section-title">Settings</div>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="editVendorPaymentTerms2">Payment Terms (days)</label>
                        <input type="number" name="payment_terms" id="editVendorPaymentTerms2" class="form-control" min="0" max="365">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editVendorNotes2">Notes</label>
                    <textarea name="notes" id="editVendorNotes2" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <button type="submit" class="btn-primary modal-submit-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?php echo __('vendors.modal.save_btn'); ?>
            </button>
        </form>
    </div>
</div>

<div class="modal" id="deleteVendorModal" role="dialog" aria-modal="true" aria-labelledby="deleteVendorTitle">
    <div class="modal-content modal-content-danger">
        <div class="modal-header">
            <h3 id="deleteVendorTitle" class="danger-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php echo __('vendors.modal.delete_title'); ?>
            </h3>
            <button class="modal-close" onclick="closeModal('deleteVendorModal')" aria-label="<?php echo __('common.close'); ?>"></button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_vendor">
            <input type="hidden" name="vendor_id" id="deleteVendorId" value="">
            <div class="delete-warning">
                <div class="delete-warning-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div class="delete-warning-text">
                    <?php echo __('vendors.delete_confirm_message'); ?> <strong id="deleteVendorName"></strong>. <?php echo __('vendors.delete_active_dispatches_warning'); ?>
                </div>
            </div>
            <div class="form-group delete-confirm-group">
                <label class="form-label"><?php echo __('vendors.delete_confirm_label'); ?></label>
                <input type="text" name="confirm_name" id="deleteVendorConfirmName" class="form-control" autocomplete="off" placeholder="<?php echo __('vendors.delete_confirm_placeholder'); ?>" required oninput="validateVendorDeleteConfirm()">
            </div>
            <button type="submit" id="deleteVendorSubmitBtn" class="btn-danger modal-submit-btn" disabled>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                <?php echo __('vendors.modal.delete_btn'); ?>
            </button>
        </form>
    </div>
</div>

<style>
.btn-accent {
    background: var(--admin-accent-soft, #eff6ff);
    color: var(--admin-accent, #2563eb);
    padding: 0.65rem 1.25rem;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9rem;
    border: 1px solid transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: var(--transition-smooth, 0.2s);
}
.btn-accent:hover { background: #dbeafe; }
.search-input-group { position: relative; }
.search-input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--admin-muted); pointer-events: none; z-index: 1; }
.search-input-with-icon { padding-left: 40px !important; }
.partner-name-link, .vendor-name-link {
    display: inline-flex; align-items: center; gap: 0.5rem;
    font-weight: 700; color: var(--admin-accent); text-decoration: none;
    transition: color 0.15s;
}
.partner-name-link:hover, .vendor-name-link:hover { color: #1d4ed8; }
.phone-link {
    display: inline-flex; align-items: center; gap: 0.4rem;
    text-decoration: none; color: inherit; font-weight: 600;
    transition: color 0.15s;
}
.phone-link:hover { color: var(--admin-accent); }
.gst-code {
    font-weight: 700; background: #f1f5f9; padding: 2px 8px;
    border-radius: 6px; font-size: 0.8rem; font-family: monospace;
}
.na-text { color: var(--admin-muted); font-style: italic; }
.text-center { text-align: center; }
.text-right { text-align: right; }
.status-indicator {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%;
    margin-right: 6px; vertical-align: middle;
}
.status-active { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.2); }
.status-inactive { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.2); }
.action-dropdown { position: relative; display: inline-block; }
.btn-icon {
    background: #f1f5f9; border: 1px solid var(--admin-border); cursor: pointer;
    width: 36px; height: 36px; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--admin-muted); transition: all 0.15s;
}
.btn-icon:hover { background: #e2e8f0; color: var(--admin-fg); }
.dropdown-menu {
    position: absolute; right: 0; top: calc(100% + 4px);
    background: #fff; border-radius: 12px; border: 1px solid var(--admin-border);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1); min-width: 180px;
    z-index: 100; display: none; overflow: hidden;
}
.dropdown-menu.open { display: block; }
.dropdown-item {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.65rem 1rem; font-size: 0.85rem; font-weight: 600;
    color: var(--admin-fg); text-decoration: none;
    border: none; background: none; cursor: pointer;
    width: 100%; text-align: left; transition: background 0.1s;
    box-sizing: border-box;
}
.dropdown-item:hover { background: #f8fafc; }
.dropdown-item-danger { color: var(--danger); }
.dropdown-item-danger:hover { background: #fef2f2; }
.dropdown-divider { height: 1px; background: var(--admin-border); margin: 4px 0; }
.empty-state {
    text-align: center; padding: 3rem 1rem;
}
.empty-state-icon {
    display: flex; align-items: center; justify-content: center;
    width: 72px; height: 72px; border-radius: 50%;
    background: #f1f5f9; margin: 0 auto 1rem;
    color: var(--admin-muted);
}
.empty-state h4 {
    font-size: 1rem; font-weight: 700; color: var(--admin-fg);
    margin: 0 0 0.25rem;
}
.empty-state p {
    font-size: 0.85rem; color: var(--admin-muted);
    margin: 0 0 1.25rem;
}
.form-section { margin-bottom: 0.25rem; }
.form-section-title {
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 0.8rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--admin-muted);
    margin-bottom: 0.75rem;
}
.form-row {
    display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
}
@media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }
.form-divider {
    height: 1px; background: var(--admin-border);
    margin: 1rem 0;
}
.modal-submit-btn {
    width: 100%; justify-content: center; margin-top: 1rem;
}
.modal-content-danger .modal-header {
    border-bottom: 1px solid #fecaca;
    margin-bottom: 1.25rem;
    padding-bottom: 1rem;
}
.danger-title {
    display: flex; align-items: center; gap: 0.5rem;
    color: var(--danger) !important;
}
.delete-warning {
    display: flex; align-items: flex-start; gap: 0.75rem;
    background: #fef2f2; border: 1px solid #fecaca;
    padding: 1rem; border-radius: 12px; margin-bottom: 1.25rem;
}
.delete-warning-icon {
    flex-shrink: 0; color: var(--danger);
}
.delete-warning-text {
    font-size: 0.85rem; line-height: 1.5; color: var(--admin-fg);
}
.delete-confirm-group {
    background: rgba(239,68,68,0.04);
    border: 1px solid rgba(239,68,68,0.12);
    padding: 1rem; border-radius: 12px; margin-bottom: 1rem;
}
.delete-confirm-group .form-control { border-color: var(--danger); }
.vendor-id { font-weight: 700; color: var(--admin-muted); font-size: 0.85rem; }

/* === P6: Filter pill classes === */
.filter-pills { display: flex; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.pill {
    padding: 0.5rem 1.25rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
    text-decoration: none; transition: all 0.15s; cursor: pointer;
    background: var(--admin-surface); color: var(--admin-fg); border: 1px solid var(--admin-border);
}
.pill:hover { background: var(--admin-hover-bg, #e8e8e8); border-color: var(--admin-accent); }
.pill.active, .pill[aria-current="page"] {
    background: var(--admin-accent); color: #fff; border-color: var(--admin-accent);
}
.pill-icon { vertical-align: middle; margin-right: 4px; }
.pill-count { margin-left: 4px; }
/* === P6: Stat card links === */
.stat-card-link {
    text-decoration: none; color: inherit; display: block; border-radius: 16px;
    transition: transform 0.15s, box-shadow 0.15s;
}
.stat-card-link:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
.stat-card-link:focus-visible { outline: 2px solid var(--admin-accent); outline-offset: 2px; }
/* === P6: Keyboard hint === */
.kbd-hint {
    display: inline-block; font-size: 0.7rem; font-weight: 600; color: var(--admin-muted);
    background: var(--admin-surface); border: 1px solid var(--admin-border);
    border-radius: 4px; padding: 1px 6px; margin-left: 6px; vertical-align: middle;
    font-family: inherit; line-height: 1.4;
}
@media (max-width: 768px) {
    .btn-accent { width: 100%; justify-content: center; }
    .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
    .stat-card { padding: 1rem; }
    .stat-card .stat-info h4 { font-size: 1.25rem; }
}
</style>

<script>
    let deletePartnerObj = null;
    let deleteVendorObj = null;

    document.addEventListener('click', function(e) {
        document.querySelectorAll('.dropdown-menu.open').forEach(function(m) {
            if (!m.parentElement.contains(e.target)) {
                m.classList.remove('open');
            }
        });
    });

    function openModal(id) {
        const el = document.getElementById(id);
        el.classList.add('active');
        document.body.style.overflow = 'hidden';
        const firstInput = el.querySelector('input, button, textarea, select');
        if (firstInput) firstInput.focus();
        document.addEventListener('keydown', trapFocus);
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', trapFocus);
    }

    function trapFocus(e) {
        if (e.key === 'Escape') {
            const activeModal = document.querySelector('.modal.active');
            if (activeModal) closeModal(activeModal.id);
        }
    }

    function toggleDropdown(btn) {
        const menu = btn.nextElementSibling;
        document.querySelectorAll('.dropdown-menu.open').forEach(function(m) {
            if (m !== menu) m.classList.remove('open');
        });
        menu.classList.toggle('open');
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
        const btn = document.getElementById('delete_submit_btn');
        btn.disabled = true;
        openModal('deletePartnerModal');
    }

    function validateDeleteConfirm() {
        const typed = document.getElementById('delete_confirm_name').value;
        const btn = document.getElementById('delete_submit_btn');
        if (deletePartnerObj && typed === deletePartnerObj.company_name) {
            btn.disabled = false;
        } else {
            btn.disabled = true;
        }
    }

    function editVendor(data) {
        document.getElementById('editVendorId2').value = data.id;
        document.getElementById('editVendorName2').value = data.name;
        document.getElementById('editVendorContact2').value = data.contact_person || '';
        document.getElementById('editVendorMobile2').value = data.mobile;
        document.getElementById('editVendorEmail2').value = data.email || '';
        document.getElementById('editVendorAddress2').value = data.address || '';
        document.getElementById('editVendorGst2').value = data.gst_number || '';
        document.getElementById('editVendorGstRegType2').value = data.gst_registration_type || 'regular';
        document.getElementById('editVendorPan2').value = data.pan || '';
        document.getElementById('editVendorTan2').value = data.tan || '';
        document.getElementById('editVendorStateCode2').value = data.state_code || '';
        document.getElementById('editVendorCity2').value = data.city || '';
        document.getElementById('editVendorPincode2').value = data.pincode || '';
        document.getElementById('editVendorBankHolder2').value = data.bank_account_holder || '';
        document.getElementById('editVendorBankAcno2').value = data.bank_account_number || '';
        document.getElementById('editVendorBankIfsc2').value = data.bank_ifsc || '';
        document.getElementById('editVendorBankName2').value = data.bank_name || '';
        document.getElementById('editVendorBankBranch2').value = data.bank_branch || '';
        document.getElementById('editVendorPaymentTerms2').value = data.payment_terms || 30;
        document.getElementById('editVendorNotes2').value = data.notes || '';
        openModal('editVendorModal');
    }

    function deleteVendor(id, name) {
        deleteVendorObj = { id: id, name: name };
        document.getElementById('deleteVendorId').value = id;
        document.getElementById('deleteVendorName').textContent = name;
        document.getElementById('deleteVendorConfirmName').value = '';
        const btn = document.getElementById('deleteVendorSubmitBtn');
        btn.disabled = true;
        openModal('deleteVendorModal');
    }

    function validateVendorDeleteConfirm() {
        const typed = document.getElementById('deleteVendorConfirmName').value;
        const btn = document.getElementById('deleteVendorSubmitBtn');
        if (deleteVendorObj && typed === deleteVendorObj.name) {
            btn.disabled = false;
        } else {
            btn.disabled = true;
        }
    }

    // Auto-dismiss alerts after 6 seconds
    document.addEventListener('DOMContentLoaded', function() {
        var alerts = document.querySelectorAll('.alert-banner');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                if (alert.parentElement) {
                    alert.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-8px)';
                    setTimeout(function() { if (alert.parentElement) alert.remove(); }, 400);
                }
            }, 6000);
        });
    });

</script>

<?php
require_once 'layout_footer.php';
?>