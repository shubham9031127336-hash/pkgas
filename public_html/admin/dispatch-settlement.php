<?php
// ── AJAX: Analyze Customer Settlement (must run before layout.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_customer_settle') {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/csrf.php';
    require_login();
    validateCsrfToken();
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/inventory-utils.php';
    require_once __DIR__ . '/business_helper.php';
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
            'action' => 'customer_settle',
            'order_id' => $order_id,
            'paid_amount' => $paid_amount,
            'advance_used' => $advance_used,
            'deposit_used' => $deposit_used,
            'order_total' => $order_total,
        ];
        $report = generateFullImpactReport($pdo, $ids, 'customer_settle', $context);
        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$page_title = "Refill Dispatch & Settlement";
$active_menu = "orders";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/business_helper.php';
require_once __DIR__ . '/bulk_operation_engine.php';

$message = '';
$error = '';

// ── POST: Settle Dispatch ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_dispatch') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $selected_cco_ids = isset($_POST['selected_cco_ids']) ? array_map('intval', (array)$_POST['selected_cco_ids']) : [];
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);
    $payment_date_raw = $_POST['payment_date'] ?? date('Y-m-d\TH:i');
    $payment_date = str_replace('T', ' ', $payment_date_raw) . ':00';
    $advance_used = floatval($_POST['advance_used'] ?? 0);
    $payment_notes = trim($_POST['payment_notes'] ?? '');

    if ($order_id <= 0 || empty($selected_cco_ids)) {
        $error = "Please select an order and at least one cylinder to dispatch.";
    } else {
        try {
            $pdo->beginTransaction();

            // Fetch order
            $stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch();
            if (!$order) throw new Exception("Order not found.");

            // Fetch customer
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$order['customer_id']]);
            $customer = $stmt->fetch();
            if (!$customer) throw new Exception("Customer not found.");

            $dispatched_count = 0;
            $total_dispatch_amount = 0;

            foreach ($selected_cco_ids as $cco_id) {
                $result = setCustomerCylinderOrderStatusDirect($pdo, $cco_id, 'delivered');
                if (!$result['success']) {
                    throw new Exception("Failed to dispatch CCO #$cco_id: " . ($result['error'] ?? 'Unknown error'));
                }
                $dispatched_count++;
            }

            if ($dispatched_count === 0) {
                throw new Exception("No cylinders were dispatched.");
            }

            // Handle advance balance deduction
            if ($advance_used > 0) {
                $stmt = $pdo->prepare("UPDATE customers SET advance_balance = GREATEST(0, advance_balance - ?) WHERE id = ?");
                $stmt->execute([$advance_used, $order['customer_id']]);
            }

            // Handle deposit balance deduction
            $deposit_used = 0;
            if (isset($_POST['deposit_used'])) {
                $deposit_used = floatval($_POST['deposit_used']);
                if ($deposit_used > 0) {
                    $stmt = $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?");
                    $stmt->execute([$deposit_used, $order['customer_id']]);
                }
            }

            // Record payment if any
            if ($paid_amount > 0 || $advance_used > 0) {
                $ledger_group_id = generateLedgerGroupId();
                $ledger_title = "Dispatch settlement - Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);

                if ($paid_amount > 0) {
                    $pdo->prepare("
                        INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id)
                        VALUES (?, ?, ?, ?, 'refill_payment', ?, ?, ?)
                    ")->execute([$order['customer_id'], $order_id, $paid_amount, $payment_method, $payment_notes ?: "Payment at dispatch - Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT), $payment_date, $ledger_group_id]);
                }

                if ($advance_used > 0) {
                    $pdo->prepare("
                        INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id)
                        VALUES (?, ?, ?, ?, 'refill_payment', ?, ?, ?)
                    ")->execute([$order['customer_id'], $order_id, $advance_used, 'Advance', "Advance adjusted at dispatch - Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT), $payment_date, $ledger_group_id]);
                }

                if ($deposit_used > 0) {
                    $pdo->prepare("
                        INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id)
                        VALUES (?, ?, ?, ?, 'deposit_refunded', ?, ?, ?)
                    ")->execute([$order['customer_id'], $order_id, -$deposit_used, $payment_method, "Deposit applied at dispatch - Order #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT), $payment_date, $ledger_group_id]);
                }

                // Create ledger group
                $total_collected = $paid_amount + $advance_used + $deposit_used;
                $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'payment_received', ?, ?, ?)")
                    ->execute([$ledger_group_id, $order['customer_id'], $ledger_title, $total_collected, $payment_date]);
            }

            // Update order payment status (include deposit_used in paid amount)
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

            // ── Audit log settlement ──
            if (function_exists('logBulkOperationAudit')) {
                $audit_ctx = ['action' => 'customer_settle', 'order_id' => $order_id, 'paid_amount' => $paid_amount, 'advance_used' => $advance_used];
                $audit_report = generateFullImpactReport($pdo, $selected_cco_ids, 'customer_settle', $audit_ctx);
                logBulkOperationAudit($pdo, $audit_report, ['processed' => $dispatched_count, 'skipped' => 0, 'details' => []], null, 'success', null, $_SESSION['username'] ?? 'admin');
            }

            // Inventory sync
            syncInventory($pdo);

            $msg = "$dispatched_count cylinder(s) dispatched successfully!";
            if ($paid_amount > 0) {
                $msg .= " Payment of ₹" . number_format($paid_amount, 2) . " received (" . htmlspecialchars($payment_method) . ").";
            }
            if ($advance_used > 0) {
                $msg .= " Advance ₹" . number_format($advance_used, 2) . " applied.";
            }
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

$success_order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

// ── Fetch pending dispatch orders ──
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

    $sql = "SELECT o.*, c.name as customer_name, c.mobile as customer_mobile, c.advance_balance, c.deposit_balance,
               GROUP_CONCAT(DISTINCT CONCAT(g.name, ' ', c2.size_capacity) ORDER BY cco.id SEPARATOR ', ') as item_names,
               GROUP_CONCAT(DISTINCT c2.serial_number ORDER BY cco.id SEPARATOR ', ') as serial_numbers,
               GROUP_CONCAT(DISTINCT cco.id ORDER BY cco.id SEPARATOR ',') as cco_ids,
               GROUP_CONCAT(DISTINCT cco.status ORDER BY cco.id SEPARATOR ',') as cco_statuses,
               COUNT(DISTINCT cco.id) as cylinder_count
        FROM refill_orders o
        JOIN customers c ON o.customer_id = c.id
        JOIN customer_cylinder_orders cco ON cco.refill_order_id = o.id
        JOIN cylinders c2 ON cco.cylinder_id = c2.id
        JOIN gas_types g ON c2.gas_type_id = g.id
        WHERE o.order_type = 'refill_without_exchange'
          AND cco.status NOT IN ('delivered', 'archived')";
$params = [];

if (!empty($search)) {
    $sql .= " AND (c.name LIKE ? OR c.mobile LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $sql .= " HAVING FIND_IN_SET(?, cco_statuses)";
    $params[] = $status_filter;
}

$sql .= " GROUP BY o.id, o.customer_id, o.order_date, o.subtotal, o.deposit_amount, o.deposit_type, o.tax_amount, o.gst_rate, o.discount, o.round_off, o.grand_total, o.paid_amount, o.due_amount, o.advance_used, o.payment_status, o.payment_method, o.notes, o.created_at, o.business_name, o.order_type, o.vehicle_number, o.is_credit_order, c.name, c.mobile, c.advance_balance, c.deposit_balance
          ORDER BY o.order_date DESC";

$orders = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Query error: ' . $e->getMessage();
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
?>

<div class="alert-banner" style="background:#fef3c7;color:#92400e;border-color:#fde68a;margin-bottom:1.5rem;font-size:0.85rem;">
    <strong>Legacy Page:</strong> Use <a href="refill-orders.php?tab=dispatch" style="font-weight:700;">Orders → Pending Dispatch</a> tab for this workflow.
</div>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;">Refill Dispatch & Settlement</h2>
        <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;">Dispatch filled cylinders to customers and collect payment for refill-without-exchange orders.</p>
    </div>
    <a href="refill-orders.php" class="btn-secondary" style="padding: 0.5rem 1.25rem;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:6px;"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        All Orders
    </a>
</div>

<?php if ($message): ?>
    <div class="alert-banner" style="background: var(--success-soft); color: var(--success); border-color: #a7f3d0; margin-bottom: 2rem;">
        <strong>Success:</strong> <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-banner alert-warning" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5; margin-bottom: 2rem;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Search & Filter -->
<div class="admin-card" style="margin-bottom: 2rem;">
    <form method="GET" style="display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 200px;">
            <input type="text" name="search" class="form-control" placeholder="Search by customer name or phone..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div style="min-width: 160px;">
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="received" <?php echo $status_filter === 'received' ? 'selected' : ''; ?>>Received</option>
                <option value="at_vendor" <?php echo $status_filter === 'at_vendor' ? 'selected' : ''; ?>>At Vendor</option>
                <option value="refilled" <?php echo $status_filter === 'refilled' ? 'selected' : ''; ?>>Refilled</option>
                <option value="received_from_vendor" <?php echo $status_filter === 'received_from_vendor' ? 'selected' : ''; ?>>Received from Vendor</option>
                <option value="ready_for_pickup" <?php echo $status_filter === 'ready_for_pickup' ? 'selected' : ''; ?>>Ready for Pickup</option>
            </select>
        </div>
        <button type="submit" class="btn-primary" style="padding: 0 1.5rem; justify-content: center;">Filter</button>
    </form>
</div>

<!-- Orders List -->
<div class="admin-card" style="padding: 0;">
    <div class="table-wrapper" style="border: none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Items (Batch)</th>
                    <th>Pipeline Status</th>
                    <th style="text-align: right;">Total</th>
                    <th style="text-align: right;">Paid</th>
                    <th>Payment</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 4rem 0; color: var(--admin-muted);">
                        No pending refill-without-exchange orders found.
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($orders as $o):
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
                ?>
                <tr>
                    <td style="font-weight: 700; color: var(--admin-muted);">#ORD-<?php echo str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?></td>
                    <td>
                        <a href="customer-profile.php?id=<?php echo $o['customer_id']; ?>" style="font-weight: 700; color: var(--admin-fg); text-decoration: none;">
                            <?php echo htmlspecialchars($o['customer_name']); ?>
                        </a>
                        <div style="font-size: 0.78rem; color: var(--admin-muted); font-weight: 600;"><?php echo htmlspecialchars($o['customer_mobile']); ?></div>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.85rem;">
                            <?php echo htmlspecialchars($o['item_names']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--admin-muted); margin-top: 2px;">
                            Serials: <?php echo htmlspecialchars($o['serial_numbers']); ?>
                        </div>
                    </td>
                    <td>
                        <?php if (isset($cco_status_labels[$worst_status])): ?>
                        <span style="font-weight: 700; font-size: 0.72rem; background: <?php echo $cco_status_labels[$worst_status]['bg']; ?>; color: <?php echo $cco_status_labels[$worst_status]['color']; ?>; padding: 2px 8px; border-radius: 4px; white-space: nowrap;">
                            <?php echo $cco_status_labels[$worst_status]['label']; ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right; font-weight: 700;">₹<?php echo number_format($o['grand_total'], 2); ?></td>
                    <td style="text-align: right; font-weight: 700; color: var(--success);">₹<?php echo number_format($o['paid_amount'], 2); ?></td>
                    <td>
                        <span style="font-weight: 700; font-size: 0.75rem; background: <?php echo $ps_color; ?>15; color: <?php echo $ps_color; ?>; padding: 3px 10px; border-radius: 12px; border: 1px solid <?php echo $ps_color; ?>40; display: inline-block;">
                            <?php echo ucfirst($ps); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($ps === 'paid'): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:0.35rem 0.8rem;background:#d1fae5;color:#065f46;border-radius:6px;font-weight:700;font-size:0.78rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Paid
                        </span>
                        <?php else: ?>
                        <button type="button" class="btn-primary" style="padding: 0.4rem 1rem; font-size: 0.8rem; border-radius: 8px; white-space: nowrap;" onclick="openSettleModal(<?php echo $o['id']; ?>, '<?php echo htmlspecialchars($o['customer_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($o['customer_mobile'], ENT_QUOTES); ?>', <?php echo $o['grand_total']; ?>, <?php echo $o['paid_amount']; ?>, <?php echo $o['due_amount']; ?>, <?php echo $o['advance_balance'] ?? 0; ?>, <?php echo $o['deposit_balance'] ?? 0; ?>, '<?php echo htmlspecialchars($o['item_names'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($o['serial_numbers'], ENT_QUOTES); ?>', '<?php echo $o['cco_ids']; ?>', <?php echo $o['cylinder_count']; ?>, <?php echo floatval($o['gst_percentage'] ?? 0); ?>, <?php echo floatval($o['tax_amount'] ?? 0); ?>)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right: 4px;"><polyline points="20 6 9 17 4 12"/></svg>
                            Settle & Dispatch
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
    <div class="modal-content" style="max-width: 640px;">
        <div class="modal-header">
            <h3>Settle & Dispatch</h3>
            <button class="modal-close" onclick="closeModal('settleModal')">&times;</button>
        </div>
        <form method="POST" id="settleForm" onsubmit="return settleAnalyze(event)"><?php csrfField(); ?>
            <input type="hidden" name="action" value="settle_dispatch">
            <input type="hidden" name="order_id" id="settleOrderId">

            <!-- Customer Info -->
            <div class="admin-card" style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: #f8fafc;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong id="settleCustomerName" style="font-size: 1rem;"></strong>
                        <span id="settleCustomerMobile" style="color: var(--admin-muted); font-size: 0.85rem; margin-left: 0.5rem;"></span>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 0.78rem; color: var(--admin-muted);">Order Total</div>
                        <div id="settleGrandTotal" style="font-weight: 800; font-size: 1.1rem;"></div>
                    </div>
                </div>
            </div>

            <!-- Items Summary -->
            <div class="admin-card" style="margin-bottom: 1rem; padding: 0.75rem 1rem;">
                <label class="form-label" style="font-weight: 700; font-size: 0.8rem; margin-bottom: 0.5rem;">
                    Select Cylinders to Dispatch
                </label>
                <div id="settleCylinderList" style="max-height: 180px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.35rem;"></div>
                <input type="hidden" name="selected_cco_ids" id="selectedCcoIds">
            </div>

            <!-- Payment Summary -->
            <div class="admin-card" style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: #fffbeb; border-color: #fde68a;">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label class="form-label" style="font-size: 0.8rem;">Already Paid</label>
                        <div id="settleAlreadyPaid" style="font-weight: 800; font-size: 1.1rem; color: var(--success);"></div>
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8rem;">Pending Amount</label>
                        <div id="settleDueAmount" style="font-weight: 800; font-size: 1.1rem; color: var(--danger);"></div>
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8rem;">GST (<span id="settleGstPercent">0</span>%)</label>
                        <div id="settleGstAmount" style="font-weight: 800; font-size: 1.1rem; color: #92400e;">₹0.00</div>
                    </div>
                </div>
            </div>

            <!-- Payment Form -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
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

            <!-- Remaining Balance after payment -->
            <div class="admin-card" style="margin-top: 1rem; padding: 0.75rem 1rem; background: #f0fdf4; border-color: #bbf7d0;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 700;">Balance After Settlement:</span>
                    <span id="settleRemaining" style="font-weight: 800; font-size: 1.1rem;">₹0.00</span>
                </div>
            </div>

            <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" class="btn-secondary" style="padding: 0.6rem 1.5rem;" onclick="closeModal('settleModal')">Cancel</button>
                <button type="submit" class="btn-primary" style="padding: 0.6rem 2rem; font-size: 1rem;" id="settleSubmitBtn">
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
    // GST display
    document.getElementById('settleGstPercent').textContent = gstPct || 0;
    document.getElementById('settleGstAmount').textContent = '₹' + (taxAmount || 0).toFixed(2);

    // Build cylinder checklist
    var list = document.getElementById('settleCylinderList');
    list.innerHTML = '';
    var allChecked = true;
    settleData.ccoIds.forEach(function(ccoId, idx) {
        var serial = settleData.serials[idx] || 'Cylinder ' + (idx + 1);
        var label = document.createElement('label');
        label.style.cssText = 'display:flex;align-items:center;gap:8px;padding:0.4rem 0.6rem;background:#f8fafc;border:1px solid var(--admin-border);border-radius:6px;cursor:pointer;font-size:0.85rem;';
        label.innerHTML = '<input type="checkbox" class="cco-checkbox" value="' + ccoId + '" checked onchange="updateSettleCylinderSelection()">' +
            '<span style="font-weight:700;color:var(--admin-accent);font-family:monospace;">' + htmlspecialchars(serial) + '</span>';
        list.appendChild(label);
    });

    // Select all / deselect all toggle
    if (settleData.ccoIds.length > 1) {
        var toggleRow = document.createElement('div');
        toggleRow.style.cssText = 'display:flex;align-items:center;gap:8px;padding:0.4rem 0.6rem;margin-bottom:4px;';
        toggleRow.innerHTML = '<label style="display:flex;align-items:center;gap:6px;font-size:0.82rem;font-weight:600;cursor:pointer;"><input type="checkbox" id="settleSelectAll" checked onchange="toggleAllCylinders(this)"> Select All (' + cylinderCount + ')</label>';
        list.prepend(toggleRow);
    }

    // Advance & Deposit
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
    openModal('settleModal');
}

function toggleAllCylinders(toggle) {
    var checkboxes = document.querySelectorAll('.cco-checkbox');
    checkboxes.forEach(function(cb) { cb.checked = toggle.checked; });
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

    // B6: Prorate due amount based on selected cylinder count
    var selectedCyls = document.querySelectorAll('.cco-checkbox:checked').length;
    var totalCyls = settleData.ccoIds ? settleData.ccoIds.length : 1;
    var ratio = totalCyls > 0 ? (selectedCyls / totalCyls) : 0;
    var proratedDue = (settleData.dueAmount || 0) * ratio;

    var remaining = proratedDue - paid - advance - deposit;
    document.getElementById('settleRemaining').textContent = '₹' + Math.max(0, remaining).toFixed(2);
    document.getElementById('settleRemaining').style.color = remaining <= 0 ? '#059669' : '#d97706';
}

function htmlspecialchars(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// ── Settle Impact Analysis ──
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

// Auto-submit on filter change
document.querySelector('select[name="status"]').addEventListener('change', function() {
    this.closest('form').submit();
});

// Search debounce
var searchTimeout;
document.querySelector('input[name="search"]').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function() { document.querySelector('form[method="GET"]').submit(); }, 600);
});
</script>

<?php require_once __DIR__ . '/bulk_operation_dialog.php'; ?>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
