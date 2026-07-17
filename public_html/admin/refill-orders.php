<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    if (!empty($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once 'business_helper.php';
require_once 'inventory-utils.php';

$active_tab = isset($_GET['tab']) ? trim($_GET['tab']) : '';

// ── Dispatch Tab: AJAX Analyze ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_customer_settle') {
    require_once __DIR__ . '/auth.php';
    require_login();
    validateCsrfToken();
    require_once __DIR__ . '/bulk_operation_engine.php';
    ob_clean();
    header('Content-Type: application/json');
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        $order_id = intval($_POST['order_id'] ?? 0);
        $paid_amount = floatval($_POST['paid_amount'] ?? 0);
        $advance_used = floatval($_POST['advance_used'] ?? 0);
        $deposit_used = floatval($_POST['deposit_used'] ?? 0);
        $order_total = floatval($_POST['order_total'] ?? 0);
        $context = [
            'action' => 'customer_settle', 'order_id' => $order_id,
            'paid_amount' => $paid_amount, 'advance_used' => $advance_used,
            'deposit_used' => $deposit_used, 'order_total' => $order_total,
        ];
        $report = generateFullImpactReport($pdo, $ids, 'customer_settle', $context);
        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Dispatch Tab: POST Settle Dispatch ──
if ($active_tab === 'dispatch' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_dispatch') {
    require_once __DIR__ . '/auth.php';
    require_login();
    validateCsrfToken();
    require_once __DIR__ . '/bulk_operation_engine.php';
    $order_id = intval($_POST['order_id'] ?? 0);
    $selected_cco_ids = isset($_POST['selected_cco_ids']) ? array_map('intval', (array)$_POST['selected_cco_ids']) : [];
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);
    $payment_date_raw = $_POST['payment_date'] ?? date('Y-m-d\TH:i');
    $payment_date = str_replace('T', ' ', $payment_date_raw) . ':00';
    $advance_used = floatval($_POST['advance_used'] ?? 0);
    $deposit_used = floatval($_POST['deposit_used'] ?? 0);
    $payment_notes = trim($_POST['payment_notes'] ?? '');

    if ($order_id <= 0 || empty($selected_cco_ids)) {
        $error = "Please select an order and at least one cylinder to dispatch.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch();
            if (!$order) throw new Exception("Order not found.");

            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$order['customer_id']]);
            $customer = $stmt->fetch();
            if (!$customer) throw new Exception("Customer not found.");

            $dispatched_count = 0;
            foreach ($selected_cco_ids as $cco_id) {
                $result = setCustomerCylinderOrderStatusDirect($pdo, $cco_id, 'delivered');
                if (!$result['success']) {
                    throw new Exception("Failed to dispatch CCO #$cco_id: " . ($result['error'] ?? 'Unknown error'));
                }
                $dispatched_count++;
            }
            if ($dispatched_count === 0) throw new Exception("No cylinders were dispatched.");

            if ($advance_used > 0) {
                $stmt = $pdo->prepare("UPDATE customers SET advance_balance = GREATEST(0, advance_balance - ?) WHERE id = ?");
                $stmt->execute([$advance_used, $order['customer_id']]);
            }

            if ($deposit_used > 0) {
                $stmt = $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?");
                $stmt->execute([$deposit_used, $order['customer_id']]);
            }

            if ($paid_amount > 0 || $advance_used > 0) {
                $ledger_group_id = generateLedgerGroupId();
                $ledger_title = "Dispatch settlement - Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);
                if ($paid_amount > 0) {
                    $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, ?, 'refill_payment', ?, ?, ?)")
                        ->execute([$order['customer_id'], $order_id, $paid_amount, $payment_method, $payment_notes ?: "Payment at dispatch - Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT), $payment_date, $ledger_group_id]);
                }
                if ($advance_used > 0) {
                    $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, ?, 'refill_payment', ?, ?, ?)")
                        ->execute([$order['customer_id'], $order_id, $advance_used, 'Advance', "Advance adjusted at dispatch - Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT), $payment_date, $ledger_group_id]);
                }
                if ($deposit_used > 0) {
                    $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, ?, 'deposit_refunded', ?, ?, ?)")
                        ->execute([$order['customer_id'], $order_id, -$deposit_used, $payment_method, "Deposit applied at dispatch - Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT), $payment_date, $ledger_group_id]);
                }
                $total_collected = $paid_amount + $advance_used + $deposit_used;
                $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'payment_received', ?, ?, ?)")
                    ->execute([$ledger_group_id, $order['customer_id'], $ledger_title, $total_collected, $payment_date]);
            }

            $new_paid = floatval($order['paid_amount']) + $paid_amount + $advance_used + $deposit_used;
            $new_due = max(0, floatval($order['grand_total']) - $new_paid);
            $new_payment_status = ($new_due <= 0) ? 'paid' : (($new_paid > 0) ? 'partial' : 'pending');
            $pdo->prepare("UPDATE refill_orders SET paid_amount = ?, due_amount = ?, payment_status = ? WHERE id = ?")
                ->execute([$new_paid, $new_due, $new_payment_status, $order_id]);

            // ── Deduct from credit_used for credit orders ──
            $newly_collected = $paid_amount + $advance_used + $deposit_used;
            if ($newly_collected > 0 && intval($order['is_credit_order']) === 1) {
                if (!isset($ledger_group_id)) {
                    $ledger_group_id = generateLedgerGroupId();
                }
                $pdo->prepare("UPDATE customers SET credit_used = GREATEST(0, credit_used - ?) WHERE id = ?")
                    ->execute([$newly_collected, $order['customer_id']]);
                $stmt_cr = $pdo->prepare("SELECT credit_used FROM customers WHERE id = ?");
                $stmt_cr->execute([$order['customer_id']]);
                $new_credit_used = floatval($stmt_cr->fetchColumn());
                $pdo->prepare("INSERT INTO credit_transactions (customer_id, refill_order_id, transaction_type, amount, balance_after, description, ledger_group_id) VALUES (?, ?, 'payment', ?, ?, ?, ?)")
                    ->execute([$order['customer_id'], $order_id, $newly_collected, $new_credit_used, 'Payment via dispatch settlement', $ledger_group_id]);
            }

            $pdo->commit();

            if (function_exists('logBulkOperationAudit')) {
                $audit_ctx = ['action' => 'customer_settle', 'order_id' => $order_id, 'paid_amount' => $paid_amount, 'advance_used' => $advance_used];
                $audit_report = generateFullImpactReport($pdo, $selected_cco_ids, 'customer_settle', $audit_ctx);
                logBulkOperationAudit($pdo, $audit_report, ['processed' => $dispatched_count, 'skipped' => 0, 'details' => []], null, 'success', null, $_SESSION['username'] ?? 'admin');
            }
            syncInventory($pdo);

            $msg = "$dispatched_count cylinder(s) dispatched successfully!";
            if ($paid_amount > 0) $msg .= " Payment of ₹" . number_format($paid_amount, 2) . " received.";
            if ($advance_used > 0) $msg .= " Advance ₹" . number_format($advance_used, 2) . " applied.";
            if ($deposit_used > 0) $msg .= " Deposit ₹" . number_format($deposit_used, 2) . " applied.";
            $_SESSION['success_flash'] = $msg . ' <a href="invoice.php?order_id=' . $order_id . '" style="font-weight:700;text-decoration:underline;">View Invoice</a>';
            echo "<script>window.location.href='refill-orders.php?tab=dispatch';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error_flash'] = $e->getMessage();
            echo "<script>window.location.href='refill-orders.php?tab=dispatch';</script>";
            exit();
        }
    }
}

$page_title = __('orders.title');
$active_menu = "orders";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
runCustomerRefillMigrations($pdo);
runRefillCostMigrations($pdo);

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$business_filter = isset($_GET['business']) ? trim($_GET['business']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$per_page = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 25;

if ($active_tab === 'dispatch') {
    // ── Dispatch Tab: Fetch pending orders ──
    $dispatch_search = isset($_GET['dispatch_search']) ? trim($_GET['dispatch_search']) : '';
    $dispatch_status = isset($_GET['dispatch_status']) ? trim($_GET['dispatch_status']) : '';

    $sql = "SELECT o.*, c.name as customer_name, c.mobile as customer_mobile, c.advance_balance, c.deposit_balance,
                   GROUP_CONCAT(DISTINCT CONCAT(g.name, ' ', c2.size_capacity) ORDER BY cco.id SEPARATOR ', ') as item_names,
                   GROUP_CONCAT(DISTINCT c2.serial_number ORDER BY cco.id SEPARATOR ', ') as serial_numbers,
                   GROUP_CONCAT(DISTINCT cco.id ORDER BY cco.id SEPARATOR ',') as cco_ids,
                   GROUP_CONCAT(DISTINCT cco.status ORDER BY cco.id SEPARATOR ',') as cco_statuses,
                   COUNT(DISTINCT cco.id) as cylinder_count,
                   (SELECT COUNT(*) FROM customer_cylinder_orders cco_sub WHERE cco_sub.refill_order_id = o.id AND cco_sub.status = 'delivered') AS cco_returned_count
            FROM refill_orders o
            JOIN customers c ON o.customer_id = c.id
            JOIN customer_cylinder_orders cco ON cco.refill_order_id = o.id
            JOIN cylinders c2 ON cco.cylinder_id = c2.id
            JOIN gas_types g ON c2.gas_type_id = g.id
            WHERE o.order_type = 'refill_without_exchange'
              AND cco.status NOT IN ('delivered', 'archived')";
    $params = [];
    if (!empty($dispatch_search)) {
        $sql .= " AND (c.name LIKE ? OR c.mobile LIKE ?)";
        $params[] = "%$dispatch_search%";
        $params[] = "%$dispatch_search%";
    }
    if (!empty($dispatch_status)) {
        $sql .= " AND cco.status = ?";
        $params[] = $dispatch_status;
    }
    $sql .= " GROUP BY o.id, o.customer_id, o.order_date, o.subtotal, o.deposit_amount, o.deposit_type, o.tax_amount, o.gst_rate, o.discount, o.round_off, o.grand_total, o.paid_amount, o.due_amount, o.advance_used, o.payment_status, o.payment_method, o.notes, o.created_at, o.business_name, o.order_type, o.vehicle_number, o.is_credit_order, c.name, c.mobile, c.advance_balance, c.deposit_balance
              ORDER BY o.order_date DESC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dispatch_orders = $stmt->fetchAll();
    } catch (PDOException $e) {
        $dispatch_orders = [];
        $error = __('orders.load_error') . $e->getMessage();
    }

    $cco_status_labels = [
        'pending_receipt' => ['label' => 'Pending Receipt', 'color' => '#64748b', 'bg' => '#f1f5f9'],
        'received' => ['label' => 'Received', 'color' => '#059669', 'bg' => '#d1fae5'],
        'at_vendor' => ['label' => 'At Vendor', 'color' => '#d97706', 'bg' => '#fef3c7'],
        'sent_to_vendor' => ['label' => 'Sent to Vendor', 'color' => '#d97706', 'bg' => '#fef3c7'],
        'refilled' => ['label' => 'Refilled', 'color' => '#7c3aed', 'bg' => '#ede9fe'],
        'received_from_vendor' => ['label' => 'Received from Vendor', 'color' => '#2563eb', 'bg' => '#dbeafe'],
        'ready_for_pickup' => ['label' => 'Ready for Pickup', 'color' => '#0891b2', 'bg' => '#cffafe'],
        'delivered' => ['label' => 'Delivered', 'color' => '#059669', 'bg' => '#d1fae5'],
    ];
} else {
    $sql = "SELECT o.*, c.name as customer_name, c.mobile as customer_mobile,
            (SELECT GROUP_CONCAT(DISTINCT is_rental) FROM refill_order_items WHERE refill_order_id = o.id) AS rental_modes,
            (SELECT GROUP_CONCAT(DISTINCT crs.status) FROM customer_refill_services crs WHERE crs.refill_order_id = o.id) AS svc_statuses,
            (SELECT GROUP_CONCAT(DISTINCT crs.id) FROM customer_refill_services crs WHERE crs.refill_order_id = o.id) AS svc_ids,
            (SELECT GROUP_CONCAT(DISTINCT crs.refill_source) FROM customer_refill_services crs WHERE crs.refill_order_id = o.id) AS svc_sources,
            (SELECT COALESCE(SUM(oi.qty * oi.refill_cost), 0) FROM refill_order_items oi WHERE oi.refill_order_id = o.id) AS total_cost,
            (SELECT GROUP_CONCAT(DISTINCT CONCAT(g3.name, ' ', COALESCE(oi8.size_capacity, '')) SEPARATOR ', ')
             FROM refill_order_items oi8
             JOIN gas_types g3 ON oi8.gas_type_id = g3.id
             WHERE oi8.refill_order_id = o.id AND oi8.is_rental IN (0,4)) AS items_summary,
            (SELECT COALESCE(SUM(oi9.qty), 0) FROM refill_order_items oi9 WHERE oi9.refill_order_id = o.id AND oi9.is_rental IN (0,4)) AS issued_qty,
            (SELECT COUNT(*) FROM customer_refill_services crs4 WHERE crs4.refill_order_id = o.id AND crs4.status IN ('returned_to_customer','delivered')) AS refill_returned_qty,
            (SELECT COUNT(*) FROM refill_order_items oi10 WHERE oi10.refill_order_id = o.id AND oi10.returned_cylinder_id IS NOT NULL) AS exchange_returned_qty
            FROM refill_orders o 
            JOIN customers c ON o.customer_id = c.id 
            WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (c.name LIKE ? OR c.mobile LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($business_filter) && array_key_exists($business_filter, getBusinesses())) {
        $sql .= " AND o.business_name = ?";
        $params[] = $business_filter;
    }

    if (!empty($status_filter)) {
        $sql .= " AND o.payment_status = ?";
        $params[] = $status_filter;
    }

    if (!empty($date_from)) {
        $sql .= " AND o.order_date >= ?";
        $params[] = $date_from . ' 00:00:00';
    }

    if (!empty($date_to)) {
        $sql .= " AND o.order_date <= ?";
        $params[] = $date_to . ' 23:59:59';
    }

    $sql .= " ORDER BY o.id DESC";

    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) FROM refill_orders o JOIN customers c ON o.customer_id = c.id WHERE 1=1";
    $count_params = [];

    if (!empty($search)) {
        $count_sql .= " AND (c.name LIKE ? OR c.mobile LIKE ?)";
        $count_params[] = "%$search%";
        $count_params[] = "%$search%";
    }
    if (!empty($business_filter) && array_key_exists($business_filter, getBusinesses())) {
        $count_sql .= " AND o.business_name = ?";
        $count_params[] = $business_filter;
    }
    if (!empty($status_filter)) {
        $count_sql .= " AND o.payment_status = ?";
        $count_params[] = $status_filter;
    }
    if (!empty($date_from)) {
        $count_sql .= " AND o.order_date >= ?";
        $count_params[] = $date_from . ' 00:00:00';
    }
    if (!empty($date_to)) {
        $count_sql .= " AND o.order_date <= ?";
        $count_params[] = $date_to . ' 23:59:59';
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
        $orders = $stmt->fetchAll();
    } catch (PDOException $e) {
        $orders = [];
        $error = __('orders.load_error') . $e->getMessage();
    }

    $page_revenue = 0;
    foreach ($orders as $o) {
        $page_revenue += floatval($o['grand_total']);
    }

    $has_active_filters = !empty($business_filter) || !empty($status_filter) || !empty($date_from) || !empty($date_to);
}
?>

<!-- ── Tab Navigation ── -->
<div class="admin-tabs">
    <a href="refill-orders.php" class="tab-link <?= $active_tab !== 'dispatch' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        All Orders
    </a>
    <a href="refill-orders.php?tab=dispatch" class="tab-link <?= $active_tab === 'dispatch' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Pending Dispatch
    </a>
</div>

<?php if ($active_tab !== 'dispatch'): ?>

<div class="page-header">
    <div class="page-header-title">
        <h2><?php echo __('orders.heading'); ?></h2>
        <p><?php echo __('orders.subtitle'); ?></p>
    </div>
    <div class="page-header-actions">
        <button class="btn-secondary" id="filterToggle" onclick="toggleFilters()" style="gap:0.4rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><circle cx="4" cy="14" r="2"/><circle cx="12" cy="11" r="2"/><circle cx="20" cy="15" r="2"/></svg>
            <span id="filterToggleLabel">Filters</span>
        </button>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert-banner">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="admin-card filter-card" id="filterCard" <?= $has_active_filters ? '' : 'style="display:none;"' ?>>
    <form method="GET" class="grid-filter-bar">
        <div>
            <label class="form-label"><?php echo __('orders.search_placeholder'); ?></label>
            <input type="text" name="search" class="form-control" placeholder="<?php echo __('orders.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div>
            <label class="form-label"><?php echo __('orders.business'); ?></label>
            <select name="business" class="form-control" onchange="this.form.submit()">
                <option value=""><?php echo __('orders.all_businesses'); ?></option>
                <?php foreach (getBusinesses() as $key => $biz): ?>
                    <option value="<?php echo $key; ?>" <?php echo $business_filter === $key ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($biz['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label"><?php echo __('orders.filter_status'); ?></label>
            <select name="status" class="form-control" onchange="this.form.submit()">
                <option value=""><?php echo __('orders.all_statuses'); ?></option>
                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>><?php echo __('orders.paid'); ?></option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>><?php echo __('orders.pending'); ?></option>
                <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>><?php echo __('orders.partial'); ?></option>
            </select>
        </div>
        <div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;">Filter</button>
        </div>
    </form>
    <div class="date-filter-row">
        <div>
            <label class="form-label"><?php echo __('orders.date_from'); ?></label>
            <input type="text" name="date_from" class="form-control flatpickr-input" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div>
            <label class="form-label"><?php echo __('orders.date_to'); ?></label>
            <input type="text" name="date_to" class="form-control flatpickr-input" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <?php if ($has_active_filters): ?>
            <a href="refill-orders.php" class="btn-secondary clear-filters-btn">Clear Filters</a>
        <?php endif; ?>
    </div>
</div>

<div class="admin-card" style="padding:0;">
    <div class="table-wrapper" style="border:none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('orders.id'); ?></th>
                    <th><?php echo __('orders.customer_name'); ?></th>
                    <th><?php echo __('orders.items'); ?></th>
                    <th>Return</th>
                    <th style="text-align:right;"><?php echo __('orders.total_paid'); ?></th>
                    <th><?php echo __('orders.status'); ?></th>
                    <th>Type</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o):
                    $modes = array_unique(array_map('intval', array_filter(explode(',', $o['rental_modes'] ?? ''))));
                    $is_refill_service = in_array(4, $modes);
                    $order_type_raw = $o['order_type'] ?? 'refill_with_exchange';
                    $mode_labels = [0 => 'Refill', 1 => 'Rental', 2 => 'Sale', 3 => 'Product', 4 => 'Service'];
                    $mode_css = [0 => 'refill', 1 => 'rental', 2 => 'sale', 3 => 'product', 4 => 'service'];
                    if (count($modes) > 1) {
                        $type_label = 'Mixed';
                        $type_css = 'mixed';
                    } elseif (in_array(0, $modes)) {
                        $type_label = $order_type_raw === 'refill_without_exchange' ? 'Refill' : 'Exchange';
                        $type_css = $order_type_raw === 'refill_without_exchange' ? 'refill' : 'exchange';
                    } else {
                        $mode = reset($modes);
                        $type_label = $mode_labels[$mode] ?? 'Unknown';
                        $type_css = $mode_css[$mode] ?? 'unknown';
                    }
                    $svc_statuses_arr = array_filter(explode(',', $o['svc_statuses'] ?? ''));
                    $cost_val = floatval($o['total_cost'] ?? 0);
                    $profit = $cost_val > 0 ? floatval($o['grand_total']) - $cost_val : 0;
                    $payment_status = $o['payment_status'] ?? 'paid';

                    // Return progress
                    $issued = max(0, (int)($o['issued_qty'] ?? 0));
                    $refill_returned = max(0, (int)($o['refill_returned_qty'] ?? 0));
                    $exchange_returned = max(0, (int)($o['exchange_returned_qty'] ?? 0));
                    $returned = $refill_returned + $exchange_returned;
                    $pct = $issued > 0 ? min(100, round($returned / $issued * 100)) : 0;

                    $order_data = htmlspecialchars(json_encode([
                        'id' => $o['id'],
                        'customer_name' => $o['customer_name'],
                        'customer_mobile' => $o['customer_mobile'],
                        'customer_id' => $o['customer_id'],
                        'order_date' => date('M d, Y h:i A', strtotime($o['order_date'])),
                        'business' => htmlspecialchars(getBusinessLabel($o['business_name'] ?: getBrandConfig()['business_key'])),
                        'type' => $type_label,
                        'subtotal' => number_format($o['subtotal'], 2),
                        'grand_total' => number_format($o['grand_total'], 2),
                        'discount' => number_format($o['discount'] ?? 0, 2),
                        'cost_known' => $cost_val > 0,
                        'cost' => number_format($cost_val, 2),
                        'margin' => $cost_val > 0 ? number_format($profit, 2) : '—',
                        'method' => $o['payment_method'],
                        'status' => ucfirst($payment_status),
                        'status_raw' => $payment_status,
                        'svc_statuses' => $is_refill_service ? array_values($svc_statuses_arr) : [],
                        'items_summary' => htmlspecialchars($o['items_summary'] ?? '—'),
                        'issued' => $issued,
                        'returned' => $returned,
                    ]), ENT_QUOTES, 'UTF-8');
                ?>
                <tr class="tr-clickable" data-order='<?php echo $order_data; ?>'>
                    <td data-label="<?php echo __('orders.id'); ?>">
                        <div class="order-cell-id">#ORD-<?php echo str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?></div>
                        <div class="order-cell-date"><?php echo date('M d, Y h:i A', strtotime($o['order_date'])); ?></div>
                    </td>
                    <td data-label="<?php echo __('orders.customer_name'); ?>">
                        <a href="customer-profile.php?id=<?php echo $o['customer_id']; ?>" class="order-cell-customer" onclick="event.stopPropagation();">
                            <?php echo htmlspecialchars($o['customer_name']); ?>
                        </a>
                        <div class="order-cell-mobile"><?php echo htmlspecialchars($o['customer_mobile']); ?></div>
                    </td>
                    <td data-label="<?php echo __('orders.items'); ?>">
                        <div class="order-cell-items"><?php echo htmlspecialchars($o['items_summary'] ?? '—'); ?></div>
                        <div style="margin-top:2px;">
                            <span style="background:#f1f5f9;color:#475569;padding:1px 6px;border-radius:4px;font-size:0.7rem;font-weight:700;">
                                <?php echo htmlspecialchars(getBusinessLabel($o['business_name'] ?: getBrandConfig()['business_key'])); ?>
                            </span>
                        </div>
                    </td>
                    <td data-label="Return">
                        <div class="return-progress">
                            <div class="bar-track">
                                <div class="bar-fill <?= $pct >= 100 ? 'complete' : ($pct > 0 ? 'partial' : 'none') ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                            <span class="bar-text"><?= $returned ?>/<?= $issued ?: '—' ?></span>
                        </div>
                        <?php if ($is_refill_service && !empty($svc_statuses_arr)): ?>
                            <div style="margin-top:3px;display:flex;flex-wrap:wrap;gap:2px;">
                                <?php foreach (array_slice($svc_statuses_arr, 0, 2) as $ss): ?>
                                    <span class="svc-badge <?php echo $ss; ?>"><?php echo str_replace('_', ' ', ucfirst($ss)); ?></span>
                                <?php endforeach; ?>
                                <?php if (count($svc_statuses_arr) > 2): ?>
                                    <span class="svc-badge" style="opacity:0.6;">+<?= count($svc_statuses_arr) - 2 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;" data-label="<?php echo __('orders.total_paid'); ?>">
                        <div class="order-cell-total">₹<?php echo number_format($o['grand_total'], 2); ?></div>
                        <?php if (floatval($o['discount']) > 0): ?>
                            <div style="font-size:0.72rem;color:var(--danger);font-weight:700;">
                                -₹<?php echo number_format($o['discount'], 2); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($cost_val > 0): ?>
                            <div class="margin-value <?php echo $profit >= 0 ? 'positive' : 'negative'; ?>" style="font-size:0.72rem;">
                                Cost: ₹<?php echo number_format($cost_val, 2); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?php echo __('orders.status'); ?>">
                        <span class="status-badge <?php echo $payment_status; ?>"><?php echo ucfirst($payment_status); ?></span>
                        <div style="margin-top:3px;"><span class="order-cell-method"><?php echo htmlspecialchars($o['payment_method']); ?></span></div>
                    </td>
                    <td data-label="Type">
                        <span class="type-badge <?= $type_css ?>"><?= $type_label ?></span>
                    </td>
                    <td style="text-align:right;" data-label="Actions">
                        <div class="order-actions">
                            <a href="invoice.php?order_id=<?php echo $o['id']; ?>" class="btn-secondary btn-icon" onclick="event.stopPropagation();" title="Print Slip">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                                <?php echo __('orders.print'); ?>
                            </a>
                            <a href="customer-profile.php?id=<?php echo $o['customer_id']; ?>" class="btn-secondary btn-icon" onclick="event.stopPropagation();" title="Customer Ledger">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?php echo __('orders.ledger'); ?>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:3rem 0;color:var(--admin-muted);">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.3;margin-bottom:0.75rem;display:block;margin-left:auto;margin-right:auto;"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/><path d="M8 11h6"/></svg>
                        <div style="font-size:1.1rem;font-weight:700;margin-bottom:0.25rem;"><?php echo __('orders.no_data'); ?></div>
                        <div style="font-size:0.85rem;">Try adjusting your search or filter criteria.</div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card pagination-bar">
    <div class="pagination-inner">
        <div class="pagination-info">
            <span class="pagination-text">
                Showing <strong><?= $total_count ? (($page - 1) * $per_page + 1) : 0 ?></strong>–<strong><?= min($page * $per_page, $total_count) ?></strong> of <strong><?= number_format($total_count) ?></strong>
            </span>
            <select class="per-page-select" onchange="changePerPage(this)" aria-label="<?php echo __('orders.per_page'); ?>">
                <option value="25" <?= $per_page === 25 ? 'selected' : '' ?>>25 / page</option>
                <option value="50" <?= $per_page === 50 ? 'selected' : '' ?>>50 / page</option>
                <option value="100" <?= $per_page === 100 ? 'selected' : '' ?>>100 / page</option>
            </select>
            <?php if (!empty($orders)): ?>
            <span class="pagination-revenue">
                <?php echo __('orders.this_page_total'); ?>: <strong>₹<?= number_format($page_revenue, 2) ?></strong> <?php echo __('orders.revenue'); ?>
            </span>
            <?php endif; ?>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="pagination-pages">
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
               class="btn-secondary pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹ Prev</a>
            <?php
            $window = 2;
            $start = max(1, $page - $window);
            $end   = min($total_pages, $page + $window);
            if ($start > 1):
                $qs = http_build_query(array_merge($_GET, ['page' => 1])); ?>
                <a href="?<?= $qs ?>" class="btn-secondary pagination-btn">1</a>
                <?php if ($start > 2): ?><span class="pagination-ellipsis">…</span><?php endif;
            endif;
            for ($p = $start; $p <= $end; $p++):
                $qs = http_build_query(array_merge($_GET, ['page' => $p])); ?>
                <a href="?<?= $qs ?>"
                   class="btn-secondary pagination-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor;
            if ($end < $total_pages):
                if ($end < $total_pages - 1): ?><span class="pagination-ellipsis">…</span><?php endif;
                $qs = http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>
                <a href="?<?= $qs ?>" class="btn-secondary pagination-btn"><?= $total_pages ?></a>
            <?php endif; ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
               class="btn-secondary pagination-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">Next ›</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="orderModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3 id="modalTitle">Order #ORD-0000</h3>
            <button class="modal-close" onclick="closeModal('orderModal')"></button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.classList.add('modal-open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.classList.remove('modal-open');
}
document.getElementById('orderModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal('orderModal');
});

function modeClass(type) {
    var map = { 'Exchange': 'exchange', 'Refill': 'refill', 'Rental': 'rental', 'Sale': 'sale', 'Product': 'product', 'Service': 'service', 'Mixed': 'mixed', 'Unknown': 'unknown' };
    return map[type] || 'unknown';
}

function progressHtml(returned, issued) {
    var pct = issued > 0 ? Math.min(100, Math.round(returned / issued * 100)) : 0;
    var cls = pct >= 100 ? 'complete' : (pct > 0 ? 'partial' : 'none');
    return '<div class="return-progress"><div class="bar-track"><div class="bar-fill ' + cls + '" style="width:' + pct + '%"></div></div><span class="bar-text">' + returned + '/' + issued + '</span></div>';
}

document.querySelectorAll('.tr-clickable').forEach(function(row) {
    row.addEventListener('click', function(e) {
        if (e.target.closest('a') || e.target.closest('button') || e.target.closest('select')) return;
        var d = JSON.parse(this.dataset.order);
        document.getElementById('modalTitle').textContent = 'Order #ORD-' + String(d.id).padStart(4, '0');

        var svcHtml = '';
        if (d.svc_statuses && d.svc_statuses.length) {
            var chips = d.svc_statuses.map(function(s) {
                return '<span class="svc-badge ' + s + '">' + s.replace(/_/g, ' ').replace(/\b\w/g, function(c){return c.toUpperCase();}) + '</span>';
            }).join(' ');
            svcHtml = '<div class="order-summary-section"><h4>Service Status</h4><div style="text-align:center;">' + chips + '</div></div>';
        }

        document.getElementById('modalBody').innerHTML =
            '<div class="order-summary-section">' +
                '<h4>Customer Information</h4>' +
                '<div class="order-summary-grid">' +
                    '<span class="label">Customer</span><span class="value"><a href="customer-profile.php?id=' + d.customer_id + '" class="modal-customer-link" onclick="event.stopPropagation();closeModal(\'orderModal\')">' + d.customer_name + '</a></span>' +
                    '<span class="label">Mobile</span><span class="value">' + d.customer_mobile + '</span>' +
                    '<span class="label">Date</span><span class="value">' + d.order_date + '</span>' +
                    '<span class="label">Business</span><span class="value">' + d.business + '</span>' +
                    '<span class="label">Type</span><span class="value"><span class="type-badge ' + (modeClass(d.type)) + '">' + d.type + '</span></span>' +
                '</div>' +
            '</div>' +
            '<div class="order-summary-section">' +
                '<h4>Items & Return Progress</h4>' +
                '<div class="order-summary-grid">' +
                    '<span class="label">Items</span><span class="value" style="font-weight:600;">' + (d.items_summary || '—') + '</span>' +
                    '<span class="label">Returned</span><span class="value">' + progressHtml(d.returned || 0, d.issued || 0) + '</span>' +
                '</div>' +
            '</div>' +
            '<div class="order-summary-section">' +
                '<h4>Financial Summary</h4>' +
                '<div class="order-summary-grid">' +
                    '<span class="label">Subtotal</span><span class="value">₹' + d.subtotal + '</span>' +
                    (parseFloat(d.discount) > 0 ? '<span class="label" style="color:var(--danger);">Discount</span><span class="value" style="color:var(--danger);">-₹' + d.discount + '</span>' : '') +
                    '<span class="label">Total</span><span class="value modal-total">₹' + d.grand_total + '</span>' +
                    (d.cost_known
                        ? '<span class="label">Refill Cost</span><span class="value" style="font-weight:700;">₹' + d.cost + '</span>' +
                          '<span class="label">Margin</span><span class="value margin-value ' + (parseFloat(d.margin) >= 0 ? 'positive' : 'negative') + '">₹' + d.margin + '</span>'
                        : '<span class="label">Refill Cost</span><span class="value"><span class="cost-pending-badge">Awaiting vendor receipt</span></span>'
                    ) +
                    '<span class="label">Method</span><span class="value"><span class="order-cell-method">' + d.method + '</span></span>' +
                    '<span class="label">Status</span><span class="value"><span class="status-badge ' + d.status_raw + '">' + d.status + '</span></span>' +
                '</div>' +
            '</div>' +
            svcHtml +
            '<div class="modal-actions">' +
                '<a href="invoice.php?order_id=' + d.id + '" class="btn-primary modal-action-btn" onclick="closeModal(\'orderModal\')">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>' +
                    '<?php echo __('orders.print'); ?>' +
                '</a>' +
                '<a href="customer-profile.php?id=' + d.customer_id + '" class="btn-secondary modal-action-btn" onclick="closeModal(\'orderModal\')">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' +
                    '<?php echo __('orders.ledger'); ?>' +
                '</a>' +
            '</div>';

        openModal('orderModal');
    });
});

function changePerPage(select) {
    var url = new URL(window.location.href);
    url.searchParams.set('per_page', select.value);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    var dateInputs = document.querySelectorAll('.flatpickr-input');
    if (typeof flatpickr !== 'undefined' && dateInputs.length) {
        dateInputs.forEach(function(el) {
            flatpickr(el, { dateFormat: 'Y-m-d', allowInput: true });
        });
    }
});

function toggleFilters() {
    var card = document.getElementById('filterCard');
    var label = document.getElementById('filterToggleLabel');
    var btn = document.getElementById('filterToggle');
    if (card.style.display === 'none') {
        card.style.display = '';
        label.textContent = 'Hide Filters';
        btn.classList.add('active');
    } else {
        card.style.display = 'none';
        label.textContent = 'Filters';
        btn.classList.remove('active');
    }
}
</script>

<?php endif; ?><!-- /all-orders -->

<?php if ($active_tab === 'dispatch'): ?>

<div class="admin-card dispatch-search-card">
    <form method="GET" class="dispatch-search-form">
        <input type="hidden" name="tab" value="dispatch">
        <div class="dispatch-search-field">
            <input type="text" name="dispatch_search" class="form-control" placeholder="Search by customer name or phone..." value="<?php echo htmlspecialchars($dispatch_search ?? ''); ?>">
        </div>
        <div class="dispatch-status-field">
            <select name="dispatch_status" class="form-control">
                <option value="">All Statuses</option>
                <?php foreach ($cco_status_labels as $sk => $sv): ?>
                <option value="<?= $sk ?>" <?= ($dispatch_status ?? '') === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-primary dispatch-filter-btn">Filter</button>
    </form>
</div>

<div class="admin-card" style="padding:0;">
    <div class="table-wrapper" style="border:none;">
        <table class="admin-table dispatch-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Return</th>
                    <th>Pipeline</th>
                    <th style="text-align:right;">Total</th>
                    <th>Payment</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dispatch_orders)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:3rem 0;color:var(--admin-muted);">
                        No pending refill-without-exchange orders found.
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($dispatch_orders ?? [] as $o):
                    $statuses = array_unique(array_filter(explode(',', $o['cco_statuses'] ?? '')));
                    $worst_status = 'delivered';
                    $status_rank = ['pending_receipt' => 0, 'received' => 1, 'sent_to_vendor' => 2, 'at_vendor' => 2, 'refilled' => 3, 'received_from_vendor' => 4, 'ready_for_pickup' => 5, 'delivered' => 6];
                    foreach ($statuses as $s) {
                        $s = trim($s);
                        if (isset($status_rank[$s]) && $status_rank[$s] < $status_rank[$worst_status]) {
                            $worst_status = $s;
                        }
                    }
                    $ps = $o['payment_status'] ?? 'pending';
                    $ps_colors = ['paid' => '#059669', 'pending' => '#d97706', 'partial' => '#2563eb'];
                    $ps_color = $ps_colors[$ps] ?? '#64748b';
                    $total_cyl = (int)($o['cylinder_count'] ?? 0);
                    $returned_cyl = (int)($o['cco_returned_count'] ?? 0);
                    $pct_d = $total_cyl > 0 ? min(100, round($returned_cyl / $total_cyl * 100)) : 0;
                ?>
                <tr>
                    <td><span class="order-cell-id">#ORD-<?php echo str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                    <td>
                        <a href="customer-profile.php?id=<?php echo $o['customer_id']; ?>" class="order-cell-customer">
                            <?php echo htmlspecialchars($o['customer_name']); ?>
                        </a>
                        <div class="order-cell-mobile"><?php echo htmlspecialchars($o['customer_mobile']); ?></div>
                    </td>
                    <td>
                        <div class="order-cell-items"><?php echo htmlspecialchars($o['item_names']); ?></div>
                        <?php if ($o['serial_numbers']): ?>
                        <div class="dispatch-serials">Serials: <?php echo htmlspecialchars($o['serial_numbers']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="return-progress">
                            <div class="bar-track">
                                <div class="bar-fill <?= $pct_d >= 100 ? 'complete' : ($pct_d > 0 ? 'partial' : 'none') ?>" style="width:<?= $pct_d ?>%"></div>
                            </div>
                            <span class="bar-text"><?= $returned_cyl ?>/<?= $total_cyl ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if (isset($cco_status_labels[$worst_status])): ?>
                        <span class="dispatch-pipeline-badge" style="background:<?php echo $cco_status_labels[$worst_status]['bg']; ?>;color:<?php echo $cco_status_labels[$worst_status]['color']; ?>;">
                            <?php echo $cco_status_labels[$worst_status]['label']; ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <div class="order-cell-total">₹<?php echo number_format($o['grand_total'], 2); ?></div>
                    </td>
                    <td>
                        <span class="dispatch-payment-badge" style="background:<?php echo $ps_color; ?>15;color:<?php echo $ps_color; ?>;border-color:<?php echo $ps_color; ?>40;">
                            <?php echo ucfirst($ps); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($ps === 'paid'): ?>
                        <span class="dispatch-paid-label">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Paid
                        </span>
                        <?php else: ?>
                        <button type="button" class="btn-primary dispatch-settle-btn" onclick="openSettleModal(<?php echo $o['id']; ?>, '<?php echo htmlspecialchars($o['customer_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($o['customer_mobile'], ENT_QUOTES); ?>', <?php echo $o['grand_total']; ?>, <?php echo $o['paid_amount']; ?>, <?php echo $o['due_amount']; ?>, <?php echo $o['advance_balance'] ?? 0; ?>, <?php echo $o['deposit_balance'] ?? 0; ?>, '<?php echo htmlspecialchars($o['item_names'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($o['serial_numbers'], ENT_QUOTES); ?>', '<?php echo $o['cco_ids']; ?>', <?php echo $o['cylinder_count']; ?>, <?php echo floatval($o['gst_percentage'] ?? 0); ?>, <?php echo floatval($o['tax_amount'] ?? 0); ?>)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Settle
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Settlement Modal -->
<div class="modal" id="settleModal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header">
            <h3>Settle & Dispatch</h3>
            <button class="modal-close" onclick="closeModal('settleModal')">&times;</button>
        </div>
        <form method="POST" id="settleForm" onsubmit="return settleAnalyze(event)"><?php csrfField(); ?>
            <input type="hidden" name="action" value="settle_dispatch">
            <input type="hidden" name="order_id" id="settleOrderId">

            <div class="admin-card" style="margin-bottom:1rem;padding:0.75rem 1rem;background:#f8fafc;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <strong id="settleCustomerName" style="font-size:1rem;"></strong>
                        <span id="settleCustomerMobile" style="color:var(--admin-muted);font-size:0.85rem;margin-left:0.5rem;"></span>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:0.78rem;color:var(--admin-muted);">Order Total</div>
                        <div id="settleGrandTotal" style="font-weight:800;font-size:1.1rem;"></div>
                    </div>
                </div>
            </div>

            <div class="admin-card" style="margin-bottom:1rem;padding:0.75rem 1rem;">
                <label class="form-label" style="font-weight:700;font-size:0.8rem;margin-bottom:0.5rem;">
                    Select Cylinders to Dispatch
                </label>
                <div id="settleCylinderList" style="max-height:180px;overflow-y:auto;display:flex;flex-direction:column;gap:0.35rem;"></div>
                <input type="hidden" name="selected_cco_ids" id="selectedCcoIds">
            </div>

            <div class="admin-card" style="margin-bottom:1rem;padding:0.75rem 1rem;background:#fffbeb;border-color:#fde68a;">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
                    <div>
                        <label class="form-label" style="font-size:0.8rem;">Already Paid</label>
                        <div id="settleAlreadyPaid" style="font-weight:800;font-size:1.1rem;color:var(--success);"></div>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:0.8rem;">Pending Amount</label>
                        <div id="settleDueAmount" style="font-weight:800;font-size:1.1rem;color:var(--danger);"></div>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:0.8rem;">GST (<span id="settleGstPercent">0</span>%)</label>
                        <div id="settleGstAmount" style="font-weight:800;font-size:1.1rem;color:#92400e;">₹0.00</div>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Amount Received (₹)</label>
                    <input type="number" step="0.01" name="paid_amount" id="settlePaidAmount" class="form-control" value="0" min="0" oninput="updateSettleSummary()">
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI / Online</option>
                        <option value="Check">Bank Check</option>
                        <option value="Credit">Credit (Pay Later)</option>
                    </select>
                </div>
                <div class="form-group" id="settleAdvanceGroup" style="display:none;">
                    <label class="form-label">Use Advance Balance (₹<span id="settleAdvanceBalance">0</span>)</label>
                    <input type="number" step="0.01" name="advance_used" id="settleAdvanceUsed" class="form-control" value="0" min="0" oninput="updateSettleSummary()">
                </div>
                <div class="form-group" id="settleDepositGroup" style="display:none;">
                    <label class="form-label">Use Deposit Balance (₹<span id="settleDepositBalance">0</span>)</label>
                    <input type="number" step="0.01" name="deposit_used" id="settleDepositUsed" class="form-control" value="0" min="0" oninput="updateSettleSummary()">
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="datetime-local" name="payment_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <input type="text" name="payment_notes" class="form-control" placeholder="Optional notes...">
                </div>
            </div>

            <div class="admin-card" style="margin-top:1rem;padding:0.75rem 1rem;background:#f0fdf4;border-color:#bbf7d0;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-weight:700;">Balance After Settlement:</span>
                    <span id="settleRemaining" style="font-weight:800;font-size:1.1rem;">₹0.00</span>
                </div>
            </div>

            <div style="display:flex;gap:0.75rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn-secondary" style="padding:0.6rem 1.5rem;" onclick="closeModal('settleModal')">Cancel</button>
                <button type="submit" class="btn-primary" style="padding:0.6rem 2rem;font-size:1rem;" id="settleSubmitBtn">
                    Confirm Dispatch & Settlement
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var settleData = {};

function openSettleModal(orderId, customerName, customerMobile, grandTotal, paidAmount, dueAmount, advanceBalance, depositBalance, items, serials, ccoIds, cylinderCount, gstPct, taxAmount) {
    settleData = {
        orderId: orderId,
        grandTotal: grandTotal,
        paidAmount: paidAmount,
        dueAmount: dueAmount,
        advanceBalance: advanceBalance,
        depositBalance: depositBalance,
        ccoIds: ccoIds.split(',').map(Number),
        serials: serials.split(', '),
        items: items,
        gstPercentage: gstPct || 0,
        taxAmount: taxAmount || 0
    };
    document.getElementById('settleOrderId').value = orderId;
    document.getElementById('settleCustomerName').textContent = customerName;
    document.getElementById('settleCustomerMobile').textContent = customerMobile;
    document.getElementById('settleGrandTotal').textContent = '₹' + grandTotal.toFixed(2);
    document.getElementById('settleAlreadyPaid').textContent = '₹' + paidAmount.toFixed(2);
    document.getElementById('settleDueAmount').textContent = '₹' + dueAmount.toFixed(2);
    document.getElementById('settleGstPercent').textContent = gstPct || 0;
    document.getElementById('settleGstAmount').textContent = '₹' + (taxAmount || 0).toFixed(2);
    var list = document.getElementById('settleCylinderList');
    list.innerHTML = '';
    var allChecked = true;
    settleData.ccoIds.forEach(function(ccoId, idx) {
        var serial = settleData.serials[idx] || 'Cylinder ' + (idx + 1);
        var label = document.createElement('label');
        label.style.cssText = 'display:flex;align-items:center;gap:8px;padding:0.4rem 0.6rem;background:#f8fafc;border:1px solid var(--admin-border);border-radius:6px;cursor:pointer;font-size:0.85rem;';
        label.innerHTML = '<input type="checkbox" class="cco-checkbox" value="' + ccoId + '" checked onchange="updateSettleCylinderSelection()">' +
            '<span style="font-weight:700;color:var(--admin-accent);font-family:monospace;">' + serial.replace(/[&<>"]/g, function(m) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]; }) + '</span>';
        list.appendChild(label);
    });
    if (settleData.ccoIds.length > 1) {
        var toggleRow = document.createElement('div');
        toggleRow.style.cssText = 'display:flex;align-items:center;gap:8px;padding:0.4rem 0.6rem;margin-bottom:4px;';
        toggleRow.innerHTML = '<label style="display:flex;align-items:center;gap:6px;font-size:0.82rem;font-weight:600;cursor:pointer;"><input type="checkbox" id="settleSelectAll" checked onchange="toggleAllCylinders(this)"> Select All (' + cylinderCount + ')</label>';
        list.prepend(toggleRow);
    }
    var advanceGroup = document.getElementById('settleAdvanceGroup');
    var depositGroup = document.getElementById('settleDepositGroup');
    if (advanceBalance > 0) {
        advanceGroup.style.display = 'block';
        document.getElementById('settleAdvanceBalance').textContent = advanceBalance.toFixed(2);
        document.getElementById('settleAdvanceUsed').value = '0';
    } else {
        advanceGroup.style.display = 'none';
        document.getElementById('settleAdvanceUsed').value = '0';
    }
    if (depositBalance > 0 && dueAmount > 0) {
        depositGroup.style.display = 'block';
        document.getElementById('settleDepositBalance').textContent = depositBalance.toFixed(2);
        document.getElementById('settleDepositUsed').value = '0';
    } else {
        depositGroup.style.display = 'none';
        document.getElementById('settleDepositUsed').value = '0';
    }
    updateSettleSummary();
    document.getElementById('settleModal').classList.add('active');
    document.body.classList.add('modal-open');
}

function toggleAllCylinders(toggle) {
    document.querySelectorAll('.cco-checkbox').forEach(function(cb) { cb.checked = toggle.checked; });
    updateSettleCylinderSelection();
}

function updateSettleCylinderSelection() {
    var selected = [];
    document.querySelectorAll('.cco-checkbox:checked').forEach(function(cb) {
        selected.push(cb.value);
    });
    document.getElementById('selectedCcoIds').value = selected.join(',');
    updateSettleSummary();
}

function updateSettleSummary() {
    var paid = parseFloat(document.getElementById('settlePaidAmount').value) || 0;
    var advance = parseFloat(document.getElementById('settleAdvanceUsed').value) || 0;
    var deposit = parseFloat(document.getElementById('settleDepositUsed').value) || 0;
    var selectedCyls = document.querySelectorAll('.cco-checkbox:checked').length;
    var totalCyls = settleData.ccoIds ? settleData.ccoIds.length : 1;
    var ratio = totalCyls > 0 ? (selectedCyls / totalCyls) : 0;
    var proratedDue = (settleData.dueAmount || 0) * ratio;
    var remaining = proratedDue - paid - advance - deposit;
    document.getElementById('settleRemaining').textContent = '₹' + Math.max(0, remaining).toFixed(2);
    document.getElementById('settleRemaining').style.color = remaining <= 0 ? '#059669' : '#d97706';
}

function settleAnalyze(event) {
    var ids = (document.getElementById('selectedCcoIds').value || '').split(',').filter(Boolean).map(Number);
    if (ids.length === 0) { alert('Select at least one cylinder to dispatch.'); return false; }
    var ctx = {
        order_id: document.getElementById('settleOrderId').value,
        paid_amount: parseFloat(document.getElementById('settlePaidAmount').value) || 0,
        advance_used: parseFloat(document.getElementById('settleAdvanceUsed').value) || 0,
        deposit_used: parseFloat(document.getElementById('settleDepositUsed').value) || 0,
        order_total: parseFloat(document.getElementById('settleGrandTotal').textContent.replace(/[₹,]/g, '')) || 0,
    };
    bulkOp.analyze(ids, 'customer_settle', ctx, window.location.href);
    bulkOp.confirmCallback = function(report, context) {
        document.getElementById('settleSubmitBtn').disabled = true;
        document.getElementById('settleSubmitBtn').textContent = 'Processing...';
        document.getElementById('settleForm').submit();
    };
    return false;
}

document.getElementById('settleModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.remove('active');
        document.body.classList.remove('modal-open');
    }
});
</script>

<?php require_once __DIR__ . '/bulk_operation_dialog.php'; ?>

<?php endif; ?><!-- /dispatch -->

<?php
require_once 'layout_footer.php';
?>
