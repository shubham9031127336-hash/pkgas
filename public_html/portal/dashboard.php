<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';

$customer_id = get_customer_id();

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

$cyl_stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_customer_id = ?");
$cyl_stmt->execute([$customer_id]);
$active_cylinders = $cyl_stmt->fetchColumn();

$cyl_stats = $pdo->prepare("SELECT SUM(status = 'filled') as filled, SUM(status = 'empty') as empty, SUM(status = 'with_customer') as in_use FROM cylinders WHERE current_customer_id = ?");
$cyl_stats->execute([$customer_id]);
$stats_row = $cyl_stats->fetch();
$filled_count = (int)($stats_row['filled'] ?? 0);
$empty_count = (int)($stats_row['empty'] ?? 0);
$in_use_count = (int)($stats_row['in_use'] ?? 0);

$order_stmt = $pdo->prepare("SELECT * FROM refill_orders WHERE customer_id = ? AND gst_rate > 0 ORDER BY order_date DESC LIMIT 5");
$order_stmt->execute([$customer_id]);
$recent_orders = $order_stmt->fetchAll();

$bal_stmt = $pdo->prepare("SELECT COALESCE(SUM(ro.grand_total - COALESCE(p.total_paid, 0)), 0) as outstanding FROM refill_orders ro LEFT JOIN (SELECT refill_order_id, SUM(amount) as total_paid FROM payments GROUP BY refill_order_id) p ON ro.id = p.refill_order_id WHERE ro.customer_id = ? AND ro.payment_status IN ('pending', 'partial') AND ro.gst_rate > 0");
$bal_stmt->execute([$customer_id]);
$outstanding = $bal_stmt->fetchColumn();

$exp_stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_customer_id = ? AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$exp_stmt->execute([$customer_id]);
$expiring_soon = $exp_stmt->fetchColumn();

$refill_stmt = $pdo->prepare("SELECT crs.id, crs.status, crs.created_at, cy.serial_number, g.name AS gas_name, cy.size_capacity AS size_capacity
                              FROM customer_refill_services crs
                              JOIN cylinders cy ON crs.cylinder_id = cy.id
                              JOIN gas_types g ON cy.gas_type_id = g.id
                              WHERE crs.customer_id = ? AND crs.status NOT IN ('returned_to_customer','cancelled')
                              ORDER BY crs.created_at DESC LIMIT 5");
$refill_stmt->execute([$customer_id]);
$active_refills = $refill_stmt->fetchAll();

$refill_count = count($active_refills);

$refill_status_labels = [
    'received' => 'Received at Plant',
    'sent_to_vendor' => 'Sent to Refiller',
    'filled_from_vendor' => 'Filled - Awaiting Return',
];
$refill_badges = [
    'received' => 'badge-pending',
    'sent_to_vendor' => 'badge-processing',
    'filled_from_vendor' => 'badge-processing',
];
?>
<div class="page-header">
    <h1>Welcome, <?php echo htmlspecialchars($customer['name']); ?></h1>
    <p class="text-muted">Your account overview at a glance</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5;color:#10b981;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?php echo $active_cylinders; ?></span>
            <span class="stat-label">Active Cylinders</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#2563eb;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?php echo count($recent_orders); ?></span>
            <span class="stat-label">Recent Orders</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#ef4444;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value">₹<?php echo number_format($outstanding, 2); ?></span>
            <span class="stat-label">Outstanding</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb;color:#f59e0b;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?php echo $expiring_soon; ?></span>
            <span class="stat-label">Expiring Soon</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff7ed;color:#f97316;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?php echo $refill_count; ?></span>
            <span class="stat-label">Active Refill Services</span>
        </div>
    </div>
</div>

<div class="card-grid">
    <div class="card">
        <div class="card-header">
            <h2>Cylinder Status</h2>
        </div>
        <div class="card-body">
            <div class="status-bar">
                <div class="status-item">
                    <span class="status-dot filled"></span>
                    <span>Filled: <?php echo $filled_count; ?></span>
                </div>
                <div class="status-item">
                    <span class="status-dot empty"></span>
                    <span>Empty: <?php echo $empty_count; ?></span>
                </div>
                <div class="status-item">
                    <span class="status-dot in-use"></span>
                    <span>With You: <?php echo $in_use_count; ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Quick Actions</h2>
        </div>
        <div class="card-body quick-actions">
            <a href="orders.php" class="action-btn">View Orders</a>
            <a href="cylinders.php" class="action-btn">Track Cylinders</a>
            <a href="payments.php" class="action-btn">Make Payment</a>
            <a href="refill-services.php" class="action-btn">Refill Services</a>
            <a href="profile.php" class="action-btn">Update Profile</a>
        </div>
    </div>
</div>

<?php if ($recent_orders): ?>
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header">
        <h2>Recent Orders</h2>
        <a href="orders.php" class="card-link">View All</a>
    </div>
    <div class="card-body p-0">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_orders as $order): ?>
                    <tr>
                        <td>#<?php echo $order['id']; ?></td>
                        <td><?php echo date('d M Y', strtotime($order['order_date'])); ?></td>
                        <td>₹<?php echo number_format($order['grand_total'], 2); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $order['payment_status']; ?>">
                                <?php echo ucfirst($order['payment_status']); ?>
                            </span>
                        </td>
                        <td><a href="order-detail.php?id=<?php echo $order['id']; ?>" class="table-link">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($refill_count > 0): ?>
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header">
        <h2>Active Refill Services</h2>
        <a href="refill-services.php" class="card-link">View All</a>
    </div>
    <div class="card-body p-0">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Service #</th>
                        <th>Cylinder</th>
                        <th>Gas</th>
                        <th>Date</th>
                        <th>Stage</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_refills as $rf): ?>
                    <tr>
                        <td>#<?php echo $rf['id']; ?></td>
                        <td><?php echo htmlspecialchars($rf['serial_number']); ?></td>
                        <td><?php echo htmlspecialchars($rf['gas_name'] . ' (' . $rf['size_capacity'] . ')'); ?></td>
                        <td><?php echo date('d M Y', strtotime($rf['created_at'])); ?></td>
                        <td><span class="badge <?php echo $refill_badges[$rf['status']] ?? 'badge-pending'; ?>"><?php echo $refill_status_labels[$rf['status']] ?? ucfirst($rf['status']); ?></span></td>
                        <td><a href="refill-service-detail.php?id=<?php echo $rf['id']; ?>" class="table-link">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
