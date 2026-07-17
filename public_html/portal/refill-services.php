<?php
$page_title = "Refill Services";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';

$customer_id = get_customer_id();

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$sql = "SELECT crs.*, g.name AS gas_name, v.name AS vendor_name,
               o.id AS order_id, o.order_date, o.gst_rate,
               cy.serial_number
        FROM customer_refill_services crs
        JOIN cylinders cy ON crs.cylinder_id = cy.id
        JOIN gas_types g ON cy.gas_type_id = g.id
        LEFT JOIN vendors v ON crs.vendor_id = v.id
        LEFT JOIN refill_orders o ON crs.refill_order_id = o.id
        WHERE crs.customer_id = ?";
$params = [$customer_id];

if (!empty($status_filter)) {
    $sql .= " AND crs.status = ?";
    $params[] = $status_filter;
}
$sql .= " ORDER BY crs.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

$status_labels = [
    'received' => 'Received at Plant',
    'sent_to_vendor' => 'Sent to Refiller',
    'filled_from_vendor' => 'Filled - Awaiting Return',
    'returned_to_warehouse' => 'Returned to Warehouse',
    'returned_to_customer' => 'Returned to You',
    'cancelled' => 'Cancelled',
];
$status_colors = [
    'received' => 'badge-pending',
    'sent_to_vendor' => 'badge-processing',
    'filled_from_vendor' => 'badge-processing',
    'returned_to_warehouse' => 'badge-paid',
    'returned_to_customer' => 'badge-paid',
    'cancelled' => 'badge-pending',
];
?>
<div class="page-header">
    <h1>Refill Services</h1>
    <p class="text-muted">Track your cylinder refill service requests</p>
</div>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <select name="status">
                    <option value="">All Status</option>
                    <option value="received" <?php echo $status_filter === 'received' ? 'selected' : ''; ?>>Received at Plant</option>
                    <option value="sent_to_vendor" <?php echo $status_filter === 'sent_to_vendor' ? 'selected' : ''; ?>>Sent to Refiller</option>
                    <option value="filled_from_vendor" <?php echo $status_filter === 'filled_from_vendor' ? 'selected' : ''; ?>>Filled - Awaiting Return</option>
                    <option value="returned_to_warehouse" <?php echo $status_filter === 'returned_to_warehouse' ? 'selected' : ''; ?>>Returned to Warehouse</option>
                    <option value="returned_to_customer" <?php echo $status_filter === 'returned_to_customer' ? 'selected' : ''; ?>>Returned to You</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <button type="submit" class="btn-filter">Filter</button>
                <a href="refill-services.php" class="btn-filter btn-clear">Clear</a>
            </div>
        </form>
    </div>
</div>

<?php if (empty($services)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem;">
        <p style="color:var(--muted);font-size:1rem;">No refill service requests found.</p>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Service #</th>
                        <th>Cylinder</th>
                        <th>Gas</th>
                        <th>Source</th>
                        <th>Order Date</th>
                        <th>Stage</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $svc): ?>
                    <tr>
                        <td>#<?php echo $svc['id']; ?></td>
                        <td><?php echo htmlspecialchars($svc['serial_number']); ?></td>
                        <td><?php echo htmlspecialchars($svc['gas_name'] . ' (' . $svc['size_capacity'] . ')'); ?></td>
                        <td><span style="font-size:0.75rem;font-weight:600;padding:2px 6px;border-radius:4px;<?php echo $svc['refill_source'] === 'warehouse' ? 'background:#fffbeb;color:#92400e;border:1px solid #fde68a;' : 'background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;'; ?>"><?php echo $svc['refill_source'] === 'warehouse' ? 'Warehouse' : 'Direct'; ?></span></td>
                        <td><?php echo date('d M Y', strtotime($svc['created_at'])); ?></td>
                        <td><span class="badge <?php echo $status_colors[$svc['status']] ?? 'badge-pending'; ?>"><?php echo $status_labels[$svc['status']] ?? ucfirst($svc['status']); ?></span></td>
                        <td><a href="refill-service-detail.php?id=<?php echo $svc['id']; ?>" class="table-link">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
