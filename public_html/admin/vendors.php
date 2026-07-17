<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('vendors.title');
$active_menu = 'vendors';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/nav-helpers.php';
require_once __DIR__ . '/gst/json/schema.php';
require_once __DIR__ . '/expense-utils.php';

runPartnerMigrations($pdo);
runRefillCostMigrations($pdo);
runVendorBatchAdjustmentMigrations($pdo);
runVendorRefillBatchItemsMigration($pdo);
runVendorAccountingMigrations($pdo);
runVendorActivityLogMigration($pdo);

$message = '';
$error = '';

// ── CREATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_vendor') {
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

// ── EDIT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_vendor') {
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
    if ($id <= 0 || empty($name) || empty($mobile)) {
        $error = __('vendors.error_required');
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE vendors SET name=?, contact_person=?, mobile=?, email=?, address=?, gst_number=?, gst_registration_type=?, pan=?, tan=?, state_code=?, state_name=?, city=?, pincode=?, bank_account_holder=?, bank_account_number=?, bank_ifsc=?, bank_name=?, bank_branch=?, payment_terms=?, notes=? WHERE id=?");
            $stmt->execute([$name, $contact_person, $mobile, $email ?: null, $address, $gst_number ?: null, $gst_registration_type, $pan ?: null, $tan ?: null, $state_code ?: null, $state_name, $city ?: null, $pincode ?: null, $bank_account_holder ?: null, $bank_account_number ?: null, $bank_ifsc ?: null, $bank_name ?: null, $bank_branch ?: null, $payment_terms, $notes ?: null, $id]);
            $message = __('vendors.updated');
        } catch (PDOException $e) {
            $error = __('vendors.update_failed') . $e->getMessage();
        }
    }
}

// ── DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_vendor') {
    $id = intval($_POST['vendor_id'] ?? 0);
    if ($id <= 0) {
        $error = __('vendors.delete_invalid');
    } else {
        try {
            $check = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_vendor_id = ? AND status = 'sent_to_vendor'");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                $error = __('vendors.cannot_delete');
            } else {
                $pdo->prepare("DELETE FROM vendors WHERE id=?")->execute([$id]);
                $message = __('vendors.deleted');
            }
        } catch (PDOException $e) {
            $error = __('vendors.delete_failed') . $e->getMessage();
        }
    }
}

// ── DISPATCH CYLINDERS (creates dispatch_lot with transport cost) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dispatch_cylinders') {
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $selected = $_POST['cylinder_ids'] ?? [];
    $notes = trim($_POST['notes'] ?? '');
    $dispatch_date = trim($_POST['dispatch_date'] ?? date('Y-m-d H:i:s'));
    $driver_name = trim($_POST['driver_name'] ?? '');
    $vehicle_number = trim($_POST['vehicle_number'] ?? '');
    $dispatch_transport = floatval($_POST['dispatch_transport_cost'] ?? 0);
    $created_by = $_SESSION['username'] ?? 'admin';

    if ($vendor_id <= 0 || empty($selected)) {
        $error = 'Please select a vendor and at least one empty cylinder.';
    } else {
        try {
            $pdo->beginTransaction();
            $cyl_ids = array_map('intval', (array)$selected);
            $cylinder_count = count($cyl_ids);

            // Generate Lot Number
            $today = date('Ymd');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatch_lots WHERE DATE(created_at) = CURDATE()");
            $stmt->execute();
            $today_count = intval($stmt->fetchColumn()) + 1;
            $lot_number = 'LOT-' . $today . '-' . str_pad($today_count, 3, '0', STR_PAD_LEFT);

            // Create Dispatch Lot
            $dispatch_date_dt = str_replace('T', ' ', $dispatch_date) . ':00';
            $dispatch_transport_per_cyl = $cylinder_count > 0 && $dispatch_transport > 0 ? $dispatch_transport / $cylinder_count : 0;

            $lot_stmt = $pdo->prepare("INSERT INTO dispatch_lots (lot_number, vendor_id, driver_name, vehicle_number, dispatch_date, notes, estimated_total, cylinder_count, returned_count, lot_status, advance_amount, gst_rate, gst_amount, gst_applicable, gst_type, gst_locked, total_paid, remaining_balance, payment_status, dispatch_transport_total, created_by) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, 0, 'open', 0, 0, 0, 0, 'CGST/SGST', 0, 0, 0, 'unpaid', ?, ?)");
            $lot_stmt->execute([
                $lot_number, $vendor_id, $driver_name ?: null, $vehicle_number ?: null,
                $dispatch_date_dt, $notes ?: null,
                $cylinder_count, $dispatch_transport, $created_by
            ]);
            $lot_id = $pdo->lastInsertId();

            // Insert Lot Items & Update Cylinders
            $dispatched = 0;
            $item_stmt = $pdo->prepare("INSERT INTO dispatch_lot_items (lot_id, cylinder_id, serial_number, gas_type_id, size_capacity, dispatch_status, dispatch_transport_cost) VALUES (?, ?, ?, ?, ?, 'dispatched', ?)");

            foreach ($cyl_ids as $cyl_id) {
                $cyl_id = intval($cyl_id);
                $cyl_data = $pdo->prepare("SELECT serial_number, gas_type_id, size_capacity FROM cylinders WHERE id = ?");
                $cyl_data->execute([$cyl_id]);
                $cyl_row = $cyl_data->fetch();
                if (!$cyl_row) continue;

                $upd = $pdo->prepare("UPDATE cylinders SET status = 'sent_to_vendor', current_vendor_id = ? WHERE id = ? AND status IN ('empty', 'received_for_refill')");
                $upd->execute([$vendor_id, $cyl_id]);

                if ($upd->rowCount() > 0) {
                    $item_stmt->execute([$lot_id, $cyl_id, $cyl_row['serial_number'], $cyl_row['gas_type_id'], $cyl_row['size_capacity'], $dispatch_transport_per_cyl]);
                    $pdo->prepare("UPDATE vendors SET active_refill_count = active_refill_count + 1 WHERE id = ?")->execute([$vendor_id]);
                    logCylinderTransaction($pdo, $cyl_id, null, $vendor_id, 'send_to_vendor', "Lot $lot_number: Dispatched for refilling. Notes: $notes");
                    $pdo->prepare("UPDATE customer_refill_services SET status = 'sent_to_vendor', vendor_id = ? WHERE cylinder_id = ? AND status = 'received'")->execute([$vendor_id, $cyl_id]);
                    $dispatched++;
                }
            }

            // Auto-create transport expense
            if ($dispatch_transport > 0) {
                try {
                    $vendor_data = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                    $vendor_data->execute([$vendor_id]);
                    $v_row = $vendor_data->fetch();
                    $transport_notes = "Dispatch transport for Lot $lot_number — " . $cylinder_count . " cylinders @ ₹" . number_format($dispatch_transport_per_cyl, 2) . "/cyl";
                    if (function_exists('autoCreateExpense') && function_exists('resolveSystemCategory')) {
                        $cat_id = resolveSystemCategory($pdo, 'Transport Charges');
                        if ($cat_id > 0) {
                            autoCreateExpense($pdo, [
                                'category_id' => $cat_id,
                                'vendor_id' => $vendor_id,
                                'vendor_name' => $v_row['name'] ?? '',
                                'expense_date' => date('Y-m-d', strtotime($dispatch_date_dt)),
                                'amount' => $dispatch_transport,
                                'gst_type' => 'exclusive',
                                'gst_rate' => 0,
                                'payment_method' => 'Bank Transfer',
                                'payment_status' => 'paid',
                                'business_key' => getBrandConfig()['business_key'],
                                'vehicle_number' => $vehicle_number ?: null,
                                'driver_name' => $driver_name ?: null,
                                'notes' => $transport_notes,
                                'reference_type' => 'dispatch_transport',
                                'reference_id' => $lot_id,
                                'reference_number' => $lot_number,
                                'created_by' => $created_by,
                                'created_by_name' => $created_by,
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    error_log("Dispatch transport expense auto-create failed for $lot_number: " . $e->getMessage());
                }
            }

            $pdo->commit();
            syncInventory($pdo);

            $_SESSION['success_flash'] = "Lot {$lot_number}: Successfully dispatched {$dispatched} cylinders to vendor. Transport cost of ₹" . number_format($dispatch_transport, 2) . " recorded.";
            echo "<script>window.location.href='lot-dashboard.php';</script>";
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = __('vendors.dispatch_failed') . $e->getMessage();
        }
    }
}

// ── RECEIVE CYLINDERS (disabled — use dispatch lot flow) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_cylinders') {
    $error = 'Individual cylinder receive is disabled. Please use the Dispatch Lot system: Send Cylinders → Receive Cylinders via lot.';
}

// ── BORROW FROM VENDOR ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'borrow') {
    $vendor_id  = intval($_POST['vendor_id'] ?? 0);
    $tx_date     = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes_inp   = trim($_POST['notes'] ?? '');
    $default_rate = floatval($_POST['default_rent_rate'] ?? 0);
    $free_days   = intval($_POST['free_days'] ?? 0);
    $gas_type_id = intval($_POST['gas_type_id'] ?? 0);
    $size_val    = trim($_POST['size_capacity'] ?? '');
    $serials_raw = trim($_POST['serials'] ?? '');
    $created_by  = $_SESSION['user_name'] ?? 'system';

    if ($vendor_id <= 0 || empty($serials_raw)) {
        $error = "Select a vendor and enter at least one serial number.";
    } else {
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO partner_transactions (vendor_id, transaction_type, transaction_date, notes, created_by) VALUES (?, 'borrowed_from_vendor', ?, ?, ?)");
            $ins->execute([$vendor_id, $tx_date, $notes_inp ?: null, $created_by]);
            $tx_id = $pdo->lastInsertId();

            $serials = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $serials_raw)));
            $imported = 0;
            foreach ($serials as $sn) {
                if (empty($sn)) continue;
                $chk = $pdo->prepare("SELECT id, status, current_vendor_id FROM cylinders WHERE serial_number = ?");
                $chk->execute([$sn]);
                $existing = $chk->fetch();
                if ($existing) {
                    $pdo->prepare("UPDATE cylinders SET status = 'borrowed_from_vendor', current_vendor_id = ?, ownership_type = 'vendor_owned', borrow_date = ?, daily_rent_rate = ?, free_days = ? WHERE id = ?")
                        ->execute([$vendor_id, $tx_date, $default_rate, $free_days, $existing['id']]);
                    $cyl_id = $existing['id'];
                } else {
                    $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, current_vendor_id, ownership_type, borrow_date, daily_rent_rate, free_days) VALUES (?, ?, ?, 'borrowed_from_vendor', ?, 'vendor_owned', ?, ?, ?)")
                        ->execute([$sn, $gas_type_id, $size_val, $vendor_id, $tx_date, $default_rate, $free_days]);
                    $cyl_id = $pdo->lastInsertId();
                }
                $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after) VALUES (?, ?, ?, ?, ?, ?, 'borrowed_from_vendor')")
                    ->execute([$tx_id, $cyl_id, $sn, $gas_type_id, $size_val, $existing ? $existing['status'] : 'new']);
                logCylinderTransaction($pdo, $cyl_id, null, $vendor_id, 'vendor_borrow', $notes_inp);
                $imported++;
            }
            if ($imported === 0) throw new Exception("No valid cylinders imported.");
            $pdo->commit();
            syncInventory($pdo);
            $pdo->prepare("UPDATE partner_transactions SET notes = CONCAT(COALESCE(notes,''), ' (', ?, ' cylinders)') WHERE id = ?")->execute([$imported, $tx_id]);
            $msg = "$imported cylinders borrowed from vendor.";
            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='vendors.php';</script>"; exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Borrow failed: " . $e->getMessage();
        }
    }
}

// ── LEND TO VENDOR ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'lend') {
    $vendor_id  = intval($_POST['vendor_id'] ?? 0);
    $tx_date     = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes_inp   = trim($_POST['notes'] ?? '');
    $default_rate = floatval($_POST['default_rent_rate'] ?? 0);
    $cylinder_ids = isset($_POST['cylinder_ids']) ? $_POST['cylinder_ids'] : [];
    $created_by  = $_SESSION['user_name'] ?? 'system';

    if ($vendor_id <= 0 || empty($cylinder_ids)) {
        $error = "Select a vendor and at least one cylinder to lend.";
    } else {
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO partner_transactions (vendor_id, transaction_type, transaction_date, notes, created_by) VALUES (?, 'lent_to_vendor', ?, ?, ?)");
            $ins->execute([$vendor_id, $tx_date, $notes_inp ?: null, $created_by]);
            $tx_id = $pdo->lastInsertId();

            $lent = 0;
            foreach ($cylinder_ids as $cyl_id) {
                $cyl_id = intval($cyl_id);
                $fetch = $pdo->prepare("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ? AND c.ownership_type = 'owned' AND c.status IN ('empty', 'filled', 'in_maintenance') AND c.current_vendor_id IS NULL");
                $fetch->execute([$cyl_id]);
                $cyl = $fetch->fetch();
                if (!$cyl) continue;
                $pdo->prepare("UPDATE cylinders SET status = 'lent_to_vendor', current_vendor_id = ?, borrow_date = ?, daily_rent_rate = ? WHERE id = ?")
                    ->execute([$vendor_id, $tx_date, $default_rate, $cyl_id]);
                $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after, daily_rent_rate) VALUES (?, ?, ?, ?, ?, ?, 'lent_to_vendor', ?)")
                    ->execute([$tx_id, $cyl_id, $cyl['serial_number'], $cyl['gas_type_id'], $cyl['size_capacity'], $cyl['status'], $default_rate]);
                logCylinderTransaction($pdo, $cyl_id, null, $vendor_id, 'vendor_lend', $notes_inp);
                $lent++;
            }
            if ($lent === 0) throw new Exception("No cylinders were lent.");
            $pdo->commit();
            syncInventory($pdo);
            $msg = "$lent cylinders lent to vendor successfully.";
            $_SESSION['success_flash'] = $msg;
            echo "<script>window.location.href='vendors.php';</script>"; exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Lend failed: " . $e->getMessage();
        }
    }
}

// ── FETCH DATA ──
$vendors = [];

// ── Fetch gas types for modals ──
$gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();
$gas_size_map = [];
try {
    $gs_sizes = $pdo->query("SELECT gas_type_id, size_capacity FROM gas_sizes ORDER BY gas_type_id, sort_order")->fetchAll();
    foreach ($gs_sizes as $gs) {
        $gas_size_map[$gs['gas_type_id']][] = $gs['size_capacity'];
    }
} catch (PDOException $e) {}
try {
    $vendors = $pdo->query("SELECT * FROM vendors ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

$empty_cylinders = [];
try {
    $empty_cylinders = $pdo->query("
        SELECT c.id, c.serial_number, g.name as gas_name, c.size_capacity, c.ownership_type,
               oc.name as original_owner_name, p.company_name as partner_name, v.name as vendor_name
        FROM cylinders c
        JOIN gas_types g ON c.gas_type_id = g.id
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        WHERE c.status IN ('empty', 'received_for_refill')
        ORDER BY c.serial_number ASC
    ")->fetchAll();
} catch (PDOException $e) {}

$dispatched_by_vendor = [];
try {
    $stmt = $pdo->query("
        SELECT c.id, c.serial_number, c.gas_type_id, c.current_vendor_id, g.name as gas_name, c.size_capacity, c.ownership_type,
               oc.name as original_owner_name, p.company_name as partner_name, v.name as vendor_name
        FROM cylinders c
        JOIN gas_types g ON c.gas_type_id = g.id
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        WHERE c.status = 'sent_to_vendor'
        ORDER BY c.serial_number ASC
    ");
    while ($row = $stmt->fetch()) {
        $dispatched_by_vendor[$row['current_vendor_id']][] = $row;
    }
} catch (PDOException $e) {}

$gas_types_data = [];
try {
    $gas_types_data = $pdo->query("SELECT id, name, refill_cost, size_refill_costs FROM gas_types")->fetchAll();
} catch (PDOException $e) {}
?>
<link rel="stylesheet" href="vendor.css">

<div class="page-header">
    <div class="page-header-title">
        <?php echo render_breadcrumb([['title' => __('nav.vendors')]]); ?>
        <h2><?php echo __('vendors.heading'); ?></h2>
        <p><?php echo __('vendors.subtitle'); ?></p>
    </div>
    <div class="page-header-actions">
        <button class="btn-secondary" onclick="openModal('dispatchCylinderModal')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 17 20 12 15 7"/><path d="M4 7v6a4 4 0 0 0 4 4h12"/></svg>
            Dispatch Empty
        </button>
        <button class="btn-secondary" onclick="openModal('receiveCylinderModal')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 17v-6a4 4 0 0 0-4-4H4"/></svg>
            Receive Filled
        </button>
        <button class="btn-primary" onclick="openModal('addVendorModal')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php echo __('vendors.modal.register_btn'); ?>
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span><strong><?php echo __('common.success_label'); ?>:</strong> <?php echo htmlspecialchars($message); ?></span>
    <button class="modal-close" onclick="this.parentElement.remove()" aria-label="<?php echo __('common.close'); ?>"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner bg-danger-soft text-danger border-danger">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <span><strong><?php echo __('common.error_label'); ?>:</strong> <?php echo htmlspecialchars($error); ?></span>
    <button class="modal-close" onclick="this.parentElement.remove()" aria-label="<?php echo __('common.close'); ?>"></button>
</div>
<?php endif; ?>

<div class="admin-card card-no-pad">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('vendors.id'); ?></th>
                    <th><?php echo __('vendors.name'); ?></th>
                    <th><?php echo __('vendors.contact'); ?></th>
                    <th><?php echo __('vendors.mobile'); ?></th>
                    <th>Email</th>
                    <th>GST</th>
                    <th><?php echo __('vendors.address'); ?></th>
                    <th class="text-center"><?php echo __('vendors.dispatched'); ?></th>
                    <th class="text-center"><?php echo __('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vendors as $v):
                    $masked_bank = '';
                    if (!empty($v['bank_account_number']) && strlen($v['bank_account_number']) > 4) {
                        $masked_bank = 'XXXX' . substr($v['bank_account_number'], -4);
                    } elseif (!empty($v['bank_account_number'])) {
                        $masked_bank = 'XXXX' . $v['bank_account_number'];
                    }
                ?>
                <tr>
                    <td data-label="<?php echo __('vendors.id'); ?>">
                        <span class="vendor-id-label">#VEND-<?php echo str_pad($v['id'], 3, '0', STR_PAD_LEFT); ?></span>
                    </td>
                    <td data-label="<?php echo __('vendors.name'); ?>">
                        <a href="vendor-profile.php?id=<?php echo $v['id']; ?>" class="vendor-name-link">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                            <?php echo htmlspecialchars($v['name']); ?>
                        </a>
                    </td>
                    <td data-label="<?php echo __('vendors.contact'); ?>"><?php echo htmlspecialchars($v['contact_person'] ?: 'N/A'); ?></td>
                    <td data-label="<?php echo __('vendors.mobile'); ?>"><?php echo htmlspecialchars($v['mobile']); ?></td>
                    <td data-label="Email"><?php echo htmlspecialchars($v['email'] ?? '—'); ?></td>
                    <td data-label="GST"><code style="font-size:0.8rem;"><?php echo htmlspecialchars($v['gst_number'] ?: '—'); ?></code></td>
                    <td class="vendor-addr-cell" data-label="<?php echo __('vendors.address'); ?>"><?php echo htmlspecialchars($v['address'] ?: 'N/A'); ?></td>
                    <td data-label="<?php echo __('vendors.dispatched'); ?>" class="text-center">
                        <?php if ($v['active_refill_count'] > 0): ?>
                            <span class="badge badge-sent-to-vendor"><?php echo $v['active_refill_count'] . ' ' . __('vendors.sent_count'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-filled">Clear</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?php echo __('common.actions'); ?>" class="text-center">
                        <div class="action-dropdown">
                            <button class="action-dropdown-btn" onclick="toggleDropdown(this)">
                                Actions
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div class="action-dropdown-menu">
                                <a href="vendor-profile.php?id=<?php echo $v['id']; ?>" class="action-dropdown-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    View Profile
                                </a>
                                <button class="action-dropdown-item" onclick='editVendor(<?php echo json_encode([
                                    'id' => $v['id'],
                                    'name' => $v['name'],
                                    'contact_person' => $v['contact_person'],
                                    'mobile' => $v['mobile'],
                                    'email' => $v['email'] ?? '',
                                    'address' => $v['address'],
                                    'gst_number' => $v['gst_number'] ?? '',
                                    'gst_registration_type' => $v['gst_registration_type'] ?? 'regular',
                                    'pan' => $v['pan'] ?? '',
                                    'tan' => $v['tan'] ?? '',
                                    'state_code' => $v['state_code'] ?? '',
                                    'city' => $v['city'] ?? '',
                                    'pincode' => $v['pincode'] ?? '',
                                    'bank_account_holder' => $v['bank_account_holder'] ?? '',
                                    'bank_account_number' => $v['bank_account_number'] ?? '',
                                    'bank_ifsc' => $v['bank_ifsc'] ?? '',
                                    'bank_name' => $v['bank_name'] ?? '',
                                    'bank_branch' => $v['bank_branch'] ?? '',
                                    'payment_terms' => $v['payment_terms'] ?? 30,
                                    'notes' => $v['notes'] ?? '',
                                ]); ?>)'>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit Vendor
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="action-dropdown-item" onclick="openVendorBorrowModal(<?php echo $v['id']; ?>, '<?php echo htmlspecialchars($v['name'], ENT_QUOTES); ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Borrow
                                </button>
                                <button class="action-dropdown-item" onclick="openVendorLendModal(<?php echo $v['id']; ?>, '<?php echo htmlspecialchars($v['name'], ENT_QUOTES); ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                    Lend
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="action-dropdown-item text-danger" onclick="deleteVendor(<?php echo $v['id']; ?>, '<?php echo htmlspecialchars($v['name'], ENT_QUOTES); ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    Delete Vendor
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vendors)): ?>
                <tr>
                    <td colspan="9" class="text-center">
                        <div class="empty-state-modern">
                            <div class="empty-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                            </div>
                            <h4>No Vendors Registered</h4>
                            <p><?php echo __('vendors.no_data'); ?></p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ════════ MODALS ════════ -->

<!-- Add Vendor -->
<div class="modal" id="addVendorModal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3><?php echo __('vendors.modal.add_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('addVendorModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="create_vendor">

            <div class="form-section-title">Company Details</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('vendors.modal.company_name'); ?> <span class="required-star">*</span></label>
                    <input type="text" name="name" class="form-control" required placeholder="<?php echo __('vendors.modal.company_placeholder'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('vendors.modal.contact_person'); ?></label>
                    <input type="text" name="contact_person" class="form-control" placeholder="<?php echo __('vendors.modal.contact_placeholder'); ?>">
                </div>
            </div>

            <div class="form-section-title">Contact Information</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('vendors.modal.mobile_label'); ?> <span class="required-star">*</span></label>
                    <input type="tel" name="mobile" class="form-control" required placeholder="<?php echo __('vendors.modal.mobile_placeholder'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="vendor@company.com">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('vendors.modal.address_label'); ?></label>
                <textarea name="address" class="form-control" rows="2" placeholder="<?php echo __('vendors.modal.address_placeholder'); ?>"></textarea>
            </div>

            <div class="form-section-title">GST / Tax Information</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">GST Number</label>
                    <input type="text" name="gst_number" class="form-control" placeholder="27AAAAA0000A1Z5">
                </div>
                <div class="form-group">
                    <label class="form-label">Registration Type</label>
                    <select name="gst_registration_type" class="form-control">
                        <option value="regular">Regular</option>
                        <option value="composition">Composition</option>
                        <option value="unregistered">Unregistered</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">PAN</label>
                    <input type="text" name="pan" class="form-control" placeholder="AAAAA0000A">
                </div>
                <div class="form-group">
                    <label class="form-label">TAN</label>
                    <input type="text" name="tan" class="form-control" placeholder="AAAA12345A">
                </div>
            </div>

            <div class="form-section-title">Location</div>
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
            </div>

            <div class="form-section-title">Bank Details</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Account Holder Name</label>
                    <input type="text" name="bank_account_holder" class="form-control" placeholder="e.g. ABC Refills Pvt Ltd">
                </div>
                <div class="form-group">
                    <label class="form-label">Account Number</label>
                    <input type="text" name="bank_account_number" class="form-control" placeholder="XXXXXXXXXXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">IFSC Code</label>
                    <input type="text" name="bank_ifsc" class="form-control" placeholder="SBIN0001234">
                </div>
                <div class="form-group">
                    <label class="form-label">Bank Name</label>
                    <input type="text" name="bank_name" class="form-control" placeholder="State Bank of India">
                </div>
                <div class="form-group">
                    <label class="form-label">Branch</label>
                    <input type="text" name="bank_branch" class="form-control" placeholder="Mumbai Main">
                </div>
            </div>

            <div class="form-section-title">Settings</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Payment Terms (days)</label>
                    <input type="number" name="payment_terms" class="form-control" value="30" min="0" max="365">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
            </div>

            <button type="submit" class="btn-primary w-full justify-center mt-05"><?php echo __('vendors.modal.register_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Edit Vendor -->
<div class="modal" id="editVendorModal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3><?php echo __('vendors.modal.edit_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('editVendorModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_vendor">
            <input type="hidden" name="vendor_id" id="editVendorId" value="">

            <div class="form-section-title">Company Details</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('vendors.modal.company_name'); ?> <span class="required-star">*</span></label>
                    <input type="text" name="name" id="editVendorName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('vendors.modal.contact_person'); ?></label>
                    <input type="text" name="contact_person" id="editVendorContact" class="form-control">
                </div>
            </div>

            <div class="form-section-title">Contact Information</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('vendors.modal.mobile_label'); ?> <span class="required-star">*</span></label>
                    <input type="tel" name="mobile" id="editVendorMobile" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="editVendorEmail" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo __('vendors.modal.address_label'); ?></label>
                <textarea name="address" id="editVendorAddress" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-section-title">GST / Tax Information</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">GST Number</label>
                    <input type="text" name="gst_number" id="editVendorGst" class="form-control" placeholder="27AAAAA0000A1Z5">
                </div>
                <div class="form-group">
                    <label class="form-label">Registration Type</label>
                    <select name="gst_registration_type" id="editVendorGstRegType" class="form-control">
                        <option value="regular">Regular</option>
                        <option value="composition">Composition</option>
                        <option value="unregistered">Unregistered</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">PAN</label>
                    <input type="text" name="pan" id="editVendorPan" class="form-control" placeholder="AAAAA0000A">
                </div>
                <div class="form-group">
                    <label class="form-label">TAN</label>
                    <input type="text" name="tan" id="editVendorTan" class="form-control" placeholder="AAAA12345A">
                </div>
            </div>

            <div class="form-section-title">Location</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">State</label>
                    <select name="state_code" id="editVendorStateCode" class="form-control">
                        <option value="">-- Select State --</option>
                        <?php foreach (gstnStateCodes() as $code => $name): ?>
                        <option value="<?= $code ?>"><?= htmlspecialchars(ucwords(strtolower($name))) ?> (<?= $code ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">City</label>
                    <input type="text" name="city" id="editVendorCity" class="form-control" placeholder="Mumbai">
                </div>
                <div class="form-group">
                    <label class="form-label">Pincode</label>
                    <input type="text" name="pincode" id="editVendorPincode" class="form-control" placeholder="400001">
                </div>
            </div>

            <div class="form-section-title">Bank Details</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Account Holder Name</label>
                    <input type="text" name="bank_account_holder" id="editVendorBankHolder" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Account Number</label>
                    <input type="text" name="bank_account_number" id="editVendorBankAcno" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">IFSC Code</label>
                    <input type="text" name="bank_ifsc" id="editVendorBankIfsc" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Bank Name</label>
                    <input type="text" name="bank_name" id="editVendorBankName" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Branch</label>
                    <input type="text" name="bank_branch" id="editVendorBankBranch" class="form-control">
                </div>
            </div>

            <div class="form-section-title">Settings</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Payment Terms (days)</label>
                    <input type="number" name="payment_terms" id="editVendorPaymentTerms" class="form-control" min="0" max="365">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" id="editVendorNotes" class="form-control" rows="2"></textarea>
            </div>

            <button type="submit" class="btn-primary w-full justify-center mt-05"><?php echo __('vendors.modal.save_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Delete Vendor -->
<div class="modal" id="deleteVendorModal">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header">
            <h3 class="text-danger"><?php echo __('vendors.modal.delete_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('deleteVendorModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_vendor">
            <input type="hidden" name="vendor_id" id="deleteVendorId" value="">
            <p class="delete-warning-text">
                Permanently delete vendor <strong id="deleteVendorName" style="color:var(--admin-accent);"></strong>. All associated records will be removed. Cannot delete with active dispatches.
            </p>
            <div class="delete-confirm-box">
                <label class="form-label">Type the exact vendor name to confirm:</label>
                <input type="text" name="confirm_name" id="deleteVendorConfirmName" class="form-control" autocomplete="off" placeholder="e.g. ABC Refills" required style="border-color:#ef4444;" oninput="validateVendorDeleteConfirm()">
            </div>
            <button type="submit" id="deleteVendorSubmitBtn" class="btn-danger w-full justify-center" style="opacity:0.5;" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Permanently Delete
            </button>
        </form>
    </div>
</div>

<!-- Dispatch Cylinders -->
<div class="modal" id="dispatchCylinderModal">
    <div class="modal-content" style="max-width:680px;">
        <div class="modal-header">
            <h3>Dispatch Empty to Supplier</h3>
            <button class="modal-close" onclick="closeModal('dispatchCylinderModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="dispatch_cylinders">
            <div class="form-section-title">Destination Vendor</div>
            <div class="form-group">
                <label class="form-label">Select Vendor</label>
                <select name="vendor_id" class="form-control" required>
                    <option value="">-- Choose registered vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-section-title">Select Empty Cylinders</div>
            <div class="form-group">
                <div class="cyl-checkbox-list">
                    <?php foreach ($empty_cylinders as $ec): ?>
                        <label class="cyl-checkbox-item">
                            <input type="checkbox" name="cylinder_ids[]" value="<?php echo $ec['id']; ?>">
                            <span class="cyl-serial"><?php echo htmlspecialchars($ec['serial_number']); ?></span>
                            <?php
                            $tag = '<span class="tag-own">OWN</span>';
                            if ($ec['ownership_type'] === 'partner_owned') $tag = '<span class="tag-br" title="Partner: ' . htmlspecialchars($ec['partner_name'] ?? 'Unknown') . '">BR</span>';
                            elseif ($ec['ownership_type'] === 'consumer_owned') $tag = '<span class="tag-con" title="' . htmlspecialchars($ec['original_owner_name'] ?? '') . '">CON</span>';
                            echo $tag;
                            ?>
                            <span class="cyl-meta"><?php echo htmlspecialchars($ec['gas_name']); ?> &middot; <?php echo $ec['size_capacity']; ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (empty($empty_cylinders)): ?>
                        <p class="empty-cyl-msg">No empty cylinders in warehouse.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-section-title">Logistics</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Dispatch Date <span style="color:#dc2626;">*</span></label>
                    <input type="datetime-local" name="dispatch_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Driver Name</label>
                    <input type="text" name="driver_name" class="form-control" placeholder="e.g. Rajesh Kumar">
                </div>
                <div class="form-group">
                    <label class="form-label">Vehicle Number</label>
                    <input type="text" name="vehicle_number" class="form-control" placeholder="e.g. MH-01-AB-1234">
                </div>
                <div class="form-group">
                    <label class="form-label">Transportation Cost (₹)</label>
                    <input type="number" name="dispatch_transport_cost" class="form-control" value="0.00" min="0" step="0.01" placeholder="0.00">
                </div>
            </div>
            <div class="form-section-title">Dispatch Notes</div>
            <div class="form-group">
                <input type="text" name="notes" class="form-control" placeholder="Challan numbers, special instructions...">
            </div>
            <button type="submit" class="btn-primary w-full justify-center mt-05">Confirm Dispatch</button>
        </form>
    </div>
</div>

<!-- Receive Cylinders -->
<div class="modal" id="receiveCylinderModal">
    <div class="modal-content" style="max-width:680px;">
        <div class="modal-header">
            <h3>Receive Filled Cylinders</h3>
            <button class="modal-close" onclick="closeModal('receiveCylinderModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="receive_cylinders">
            <div class="form-section-title">Source Vendor</div>
            <div class="form-group">
                <label class="form-label">Select Vendor</label>
                <select name="vendor_id" id="receiveVendorSelect" class="form-control" required onchange="filterReceiveCylinders()">
                    <option value="">-- Choose vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-section-title">Select Cylinders to Receive</div>
            <div class="form-group">
                <div id="receiveCylindersList" class="cyl-checkbox-list">
                    <p class="empty-cyl-msg">Select a vendor above.</p>
                </div>
            </div>
            <div class="form-section-title">Refill Cost</div>
            <div id="refillCostSection" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Cost Per Cylinder (₹)</label>
                    <div style="display:flex;gap:0.5rem;align-items:center;">
                        <input type="number" name="refill_cost_per_unit" id="refillCostPerUnit" class="form-control" style="width:180px;" value="0.00" min="0" step="0.01" oninput="updateReceiveSummary()">
                        <span id="refillTotalCostDisplay" style="font-weight:700;font-size:1rem;color:var(--admin-accent);">₹0.00</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Invoice Number</label>
                    <input type="text" name="invoice_number" class="form-control" style="width:250px;" placeholder="e.g. AAP/2026/078">
                </div>
                <div class="form-group">
                    <label class="form-label">GST Rate</label>
                    <select name="gst_rate" class="form-control" style="width:150px;">
                        <option value="0">0% (No GST)</option>
                        <option value="5">5% GST</option>
                        <option value="18" selected>18% GST</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Received Date</label>
                    <input type="datetime-local" name="received_date" class="form-control" style="max-width:260px;" value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="Refill verification notes...">
                </div>

                <!-- Deductions & Additions -->
                <div class="form-section-title" style="margin-top:1rem;">Deductions &amp; Additions</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Deduction (₹)</label>
                        <input type="number" name="deduction_amount" class="form-control" value="0.00" min="0" step="0.01" oninput="updateReceiveSummary()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Addition (₹)</label>
                        <input type="number" name="addition_amount" class="form-control" value="0.00" min="0" step="0.01" oninput="updateReceiveSummary()">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.5rem;">
                    <div class="form-group">
                        <input type="text" name="deduction_notes" class="form-control" placeholder="Damage, shortage reason...">
                    </div>
                    <div class="form-group">
                        <input type="text" name="addition_notes" class="form-control" placeholder="Transport, extra charges...">
                    </div>
                </div>

                <!-- Summary bar -->
                <div id="receiveSummaryBar" class="receive-summary-bar" style="display:none;">
                    <div class="summary-line"><span>Gross Total:</span><span id="summaryGross">₹0.00</span></div>
                    <div class="summary-line"><span>Deductions:</span><span id="summaryDeductions" class="summary-neg">−₹0.00</span></div>
                    <div class="summary-line"><span>Additions:</span><span id="summaryAdditions" class="summary-pos">+₹0.00</span></div>
                    <div class="summary-line summary-total"><span>Net Amount:</span><span id="summaryNet">₹0.00</span></div>
                </div>

                <!-- Settlement -->
                <div class="form-section-title" style="margin-top:1.25rem;">Settlement</div>
                <div class="settlement-toggle-group">
                    <label class="settlement-radio">
                        <input type="radio" name="payment_option" value="credit" checked onchange="updateReceiveSummary()">
                        <span class="settlement-radio-label">On Credit</span>
                        <span class="settlement-radio-desc">Will owe vendor later</span>
                    </label>
                    <label class="settlement-radio">
                        <input type="radio" name="payment_option" value="paid" onchange="toggleSettlementFields()">
                        <span class="settlement-radio-label">Mark as Paid</span>
                        <span class="settlement-radio-desc">Record payment immediately</span>
                    </label>
                </div>

                <div id="paidFields" style="display:none;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--admin-border);">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                        <div class="form-group">
                            <label class="form-label">Payment Method <span class="required-star">*</span></label>
                            <select name="paid_method" class="form-control">
                                <option value="">-- Select --</option>
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer" selected>Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="NEFT">NEFT</option>
                                <option value="RTGS">RTGS</option>
                                <option value="Online Transfer">Online Transfer</option>
                                <option value="Adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Date <span class="required-star">*</span></label>
                            <input type="datetime-local" name="paid_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reference / Transaction ID</label>
                        <input type="text" name="paid_reference" class="form-control" placeholder="e.g. UPI ref, cheque no, bank tx ID">
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-primary w-full justify-center mt-05">Verify &amp; Receive</button>
        </form>
    </div>
</div>

<!-- ════════ BORROW MODAL ════════ -->
<div class="modal" id="borrowModal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Borrow Cylinders: <span id="borrowVendorName"></span>
            </h3>
            <button class="modal-close" onclick="closeModal('borrowModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="borrow">
            <input type="hidden" name="vendor_id" id="borrowVendorId">
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
                    <select name="gas_type_id" id="borrowGasType" class="form-control" onchange="updateVendorBorrowSizes()">
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
                    <input type="number" name="quantity" id="borrowQty" class="form-control" value="1" min="1" oninput="validateVendorBorrowSerials()">
                </div>
            </div>
            <div class="form-section-title">Cylinder Serial Numbers</div>
            <div class="form-group">
                <textarea name="serials" id="borrowSerials" class="serial-input-area" placeholder="Enter one serial per line or comma-separated" oninput="validateVendorBorrowSerials()"></textarea>
                <div class="serial-counter">
                    <span>Expected: <strong id="borrowExpected">0</strong></span>
                    <span class="serial-count-badge" id="borrowCountBadge">0 entered</span>
                </div>
            </div>
            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
            </div>
            <button type="submit" class="btn-primary w-full justify-center mt-05">Register Borrow Transaction</button>
        </form>
    </div>
</div>

<!-- ════════ LEND MODAL ════════ -->
<div class="modal" id="lendModal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Lend Cylinders: <span id="lendVendorName"></span>
            </h3>
            <button class="modal-close" onclick="closeModal('lendModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="lend">
            <input type="hidden" name="vendor_id" id="lendVendorId">
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
            <button type="submit" class="btn-primary w-full justify-center mt-05">Confirm Lend Transaction</button>
        </form>
    </div>
</div>

<!-- ════════ GAS SIZE MAP ════════ -->
<script>
const gasSizeMap = <?php echo json_encode($gas_size_map); ?>;
</script>

<script>
const dispatchedMapping = <?php echo json_encode($dispatched_by_vendor); ?>;
const gasTypesData = <?php echo json_encode($gas_types_data); ?>;
const gasTypesMap = {};
gasTypesData.forEach(function(g) { gasTypesMap[g.id] = g; });

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function editVendor(data) {
    document.getElementById('editVendorId').value = data.id;
    document.getElementById('editVendorName').value = data.name;
    document.getElementById('editVendorContact').value = data.contact_person || '';
    document.getElementById('editVendorMobile').value = data.mobile;
    document.getElementById('editVendorEmail').value = data.email || '';
    document.getElementById('editVendorAddress').value = data.address || '';
    document.getElementById('editVendorGst').value = data.gst_number || '';
    document.getElementById('editVendorGstRegType').value = data.gst_registration_type || 'regular';
    document.getElementById('editVendorPan').value = data.pan || '';
    document.getElementById('editVendorTan').value = data.tan || '';
    document.getElementById('editVendorStateCode').value = data.state_code || '';
    document.getElementById('editVendorCity').value = data.city || '';
    document.getElementById('editVendorPincode').value = data.pincode || '';
    document.getElementById('editVendorBankHolder').value = data.bank_account_holder || '';
    document.getElementById('editVendorBankAcno').value = data.bank_account_number || '';
    document.getElementById('editVendorBankIfsc').value = data.bank_ifsc || '';
    document.getElementById('editVendorBankName').value = data.bank_name || '';
    document.getElementById('editVendorBankBranch').value = data.bank_branch || '';
    document.getElementById('editVendorPaymentTerms').value = data.payment_terms || 30;
    document.getElementById('editVendorNotes').value = data.notes || '';
    openModal('editVendorModal');
}

let deleteVendorObj = null;
function deleteVendor(id, name) {
    deleteVendorObj = { id: id, name: name };
    document.getElementById('deleteVendorId').value = id;
    document.getElementById('deleteVendorName').textContent = name;
    document.getElementById('deleteVendorConfirmName').value = '';
    const btn = document.getElementById('deleteVendorSubmitBtn');
    btn.disabled = true; btn.style.opacity = '0.5';
    openModal('deleteVendorModal');
}
function validateVendorDeleteConfirm() {
    const typed = document.getElementById('deleteVendorConfirmName').value;
    const btn = document.getElementById('deleteVendorSubmitBtn');
    if (deleteVendorObj && typed === deleteVendorObj.name) {
        btn.disabled = false; btn.style.opacity = '1';
    } else {
        btn.disabled = true; btn.style.opacity = '0.5';
    }
}

function toggleSettlementFields() {
    const isPaid = document.querySelector('input[name="payment_option"]:checked').value === 'paid';
    document.getElementById('paidFields').style.display = isPaid ? 'block' : 'none';
    if (isPaid) document.querySelector('select[name="paid_method"]').required = true;
    else document.querySelector('select[name="paid_method"]').required = false;
    updateReceiveSummary();
}

function filterReceiveCylinders() {
    const vendorId = document.getElementById('receiveVendorSelect').value;
    const listDiv = document.getElementById('receiveCylindersList');
    const costSection = document.getElementById('refillCostSection');
    listDiv.innerHTML = '';

    if (vendorId && dispatchedMapping[vendorId]) {
        const list = dispatchedMapping[vendorId];
        list.forEach(function(c) {
            const label = document.createElement('label');
            label.className = 'cyl-checkbox-item';
            let tag = '<span class="tag-own">OWN</span>';
            if (c.ownership_type === 'partner_owned') tag = '<span class="tag-br">BR</span>';
            else if (c.ownership_type === 'consumer_owned') tag = '<span class="tag-con">CON</span>';
            label.innerHTML = '<input type="checkbox" name="cylinder_ids[]" value="' + c.id + '" onchange="updateReceiveSummary()"> ' +
                '<span class="cyl-serial">' + c.serial_number + ' ' + tag + '</span> ' +
                '<span class="cyl-meta">' + (c.gas_name || '') + ' &middot; ' + (c.size_capacity || '') + '</span>';
            listDiv.appendChild(label);
        });
        costSection.style.display = 'block';
        // Auto-fill cost per unit from first cylinder's gas type
        if (list.length > 0) {
            const gt = gasTypesMap[list[0].gas_type_id];
            if (gt) {
                let cost = parseFloat(gt.refill_cost) || 0;
                if (gt.size_refill_costs && list[0].size_capacity) {
                    try {
                        const map = JSON.parse(gt.size_refill_costs);
                        if (map[list[0].size_capacity] !== undefined) cost = parseFloat(map[list[0].size_capacity]) || 0;
                    } catch(e) {}
                }
                document.getElementById('refillCostPerUnit').value = cost.toFixed(2);
            }
        }
        updateReceiveSummary();
    } else {
        listDiv.innerHTML = '<p class="empty-cyl-msg">No active dispatches for this vendor.</p>';
        costSection.style.display = 'none';
    }
}

function updateReceiveSummary() {
    const costPerUnit = parseFloat(document.getElementById('refillCostPerUnit').value) || 0;
    const summaryBar = document.getElementById('receiveSummaryBar');
    const checked = document.querySelectorAll('#receiveCylindersList input[type="checkbox"]:checked');
    if (checked.length === 0 || costPerUnit <= 0) {
        document.getElementById('refillTotalCostDisplay').textContent = '₹0.00';
        summaryBar.style.display = 'none';
        return;
    }
    const totalQty = checked.length;
    const grossTotal = totalQty * costPerUnit;
    const ded = parseFloat(document.querySelector('input[name="deduction_amount"]').value) || 0;
    const add = parseFloat(document.querySelector('input[name="addition_amount"]').value) || 0;
    const net = grossTotal - ded + add;

    document.getElementById('refillTotalCostDisplay').textContent = '₹' + grossTotal.toFixed(2) + ' (' + totalQty + ' cyl × ₹' + costPerUnit.toFixed(2) + ')';
    document.getElementById('summaryGross').textContent = '₹' + grossTotal.toFixed(2);
    document.getElementById('summaryDeductions').textContent = '−₹' + ded.toFixed(2);
    document.getElementById('summaryAdditions').textContent = '+₹' + add.toFixed(2);
    document.getElementById('summaryNet').textContent = '₹' + net.toFixed(2);
    summaryBar.style.display = 'block';
}

// ── Vendor Borrow ──
function openVendorBorrowModal(id, name) {
    document.getElementById('borrowVendorId').value = id;
    document.getElementById('borrowVendorName').textContent = name;
    document.getElementById('borrowGasType').value = '';
    document.getElementById('borrowSize').innerHTML = '<option value="">-- Select gas first --</option>';
    document.getElementById('borrowQty').value = 1;
    document.getElementById('borrowSerials').value = '';
    document.getElementById('borrowExpected').textContent = '0';
    document.getElementById('borrowCountBadge').textContent = '0 entered';
    openModal('borrowModal');
}

function updateVendorBorrowSizes() {
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

function validateVendorBorrowSerials() {
    const raw = document.getElementById('borrowSerials').value;
    const expected = parseInt(document.getElementById('borrowQty').value) || 0;
    const count = raw ? raw.split(/[\r\n,]+/).filter(function(s) { return s.trim() !== ''; }).length : 0;
    document.getElementById('borrowExpected').textContent = expected;
    document.getElementById('borrowCountBadge').textContent = count + ' entered';
}

// ── Vendor Lend ──
function openVendorLendModal(id, name) {
    document.getElementById('lendVendorId').value = id;
    document.getElementById('lendVendorName').textContent = name;
    openModal('lendModal');
    loadVendorLendableCylinders(id);
}

function loadVendorLendableCylinders(vendorId) {
    const container = document.getElementById('lendCylinderList');
    container.innerHTML = '<p style="color:var(--admin-muted);text-align:center;">Loading...</p>';
    fetch('partner-ajax.php?action=lendable_cylinders_vendor&vendor_id=' + vendorId)
        .then(function(r) { return r.text(); })
        .then(function(html) { container.innerHTML = html; })
        .catch(function() { container.innerHTML = '<p style="color:var(--danger);text-align:center;">Failed to load cylinders.</p>'; });
}

function toggleDropdown(btn) {
    const menu = btn.nextElementSibling;
    document.querySelectorAll('.action-dropdown-menu.show').forEach(function(m) { m.classList.remove('show'); });
    menu.classList.toggle('show');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.action-dropdown')) {
        document.querySelectorAll('.action-dropdown-menu.show').forEach(function(m) { m.classList.remove('show'); });
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const active = document.querySelector('.modal.active');
        if (active) closeModal(active.id);
    }
});
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
