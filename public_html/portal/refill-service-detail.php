<?php
$page_title = "Refill Service Detail";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';

$customer_id = get_customer_id();
$svc_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($svc_id <= 0) {
    echo "<script>window.location.href='refill-services.php'</script>";
    exit();
}

$stmt = $pdo->prepare("SELECT crs.*, g.name AS gas_name, v.name AS vendor_name,
                              o.id AS order_id, o.order_date, o.grand_total, o.payment_status, o.gst_rate,
                              cy.serial_number
                       FROM customer_refill_services crs
                       JOIN cylinders cy ON crs.cylinder_id = cy.id
                       JOIN gas_types g ON cy.gas_type_id = g.id
                       LEFT JOIN vendors v ON crs.vendor_id = v.id
                       LEFT JOIN refill_orders o ON crs.refill_order_id = o.id
                       WHERE crs.id = ? AND crs.customer_id = ?");
$stmt->execute([$svc_id, $customer_id]);
$svc = $stmt->fetch();

if (!$svc) {
    echo "<script>window.location.href='refill-services.php'</script>";
    exit();
}

$pay_stmt = $pdo->prepare("SELECT * FROM payments WHERE customer_refill_service_id = ? ORDER BY payment_date DESC");
$pay_stmt->execute([$svc_id]);
$payments = $pay_stmt->fetchAll();

$status_labels = [
    'received' => 'Received at Plant',
    'sent_to_vendor' => 'Sent to Refiller',
    'filled_from_vendor' => 'Filled - Awaiting Return',
    'returned_to_warehouse' => 'Returned to Warehouse',
    'returned_to_customer' => 'Returned to You',
    'cancelled' => 'Cancelled',
];
if ($svc['refill_source'] === 'warehouse') {
    $stage_flow = ['received', 'sent_to_vendor', 'filled_from_vendor', 'returned_to_warehouse', 'returned_to_customer'];
} else {
    $stage_flow = ['received', 'sent_to_vendor', 'filled_from_vendor', 'returned_to_customer'];
}
$current_stage_idx = array_search($svc['status'], $stage_flow);
if ($current_stage_idx === false) $current_stage_idx = -1;
?>
<div class="page-header">
    <h1>Refill Service #<?php echo $svc['id']; ?></h1>
    <a href="refill-services.php" class="card-link" style="font-size:0.85rem;">&larr; Back to Services</a>
</div>

<div class="card-grid" style="margin-bottom:1.5rem;">
    <div class="card">
        <div class="card-header"><h2>Service Details</h2></div>
        <div class="card-body">
            <table class="detail-table">
                <tr><td class="detail-label">Cylinder</td><td><?php echo htmlspecialchars($svc['serial_number']); ?></td></tr>
                <tr><td class="detail-label">Gas Type</td><td><?php echo htmlspecialchars($svc['gas_name'] . ' (' . $svc['size_capacity'] . ')'); ?></td></tr>
                <tr><td class="detail-label">Refill Source</td><td><span class="badge <?php echo $svc['refill_source'] === 'warehouse' ? 'badge-pending' : 'badge-processing'; ?>"><?php echo $svc['refill_source'] === 'warehouse' ? 'Our Warehouse' : 'Direct Vendor'; ?></span></td></tr>
                <tr><td class="detail-label">Current Stage</td><td><span class="badge badge-processing"><?php echo $status_labels[$svc['status']] ?? ucfirst($svc['status']); ?></span></td></tr>
                <tr><td class="detail-label">Received at Plant</td><td><?php echo date('d M Y h:i A', strtotime($svc['created_at'])); ?></td></tr>
                <?php if ($svc['vendor_name']): ?>
                <tr><td class="detail-label">Refiller</td><td><?php echo htmlspecialchars($svc['vendor_name']); ?></td></tr>
                <?php endif; ?>
                <?php if ($svc['sent_to_vendor_at']): ?>
                <tr><td class="detail-label">Sent to Refiller</td><td><?php echo date('d M Y h:i A', strtotime($svc['sent_to_vendor_at'])); ?></td></tr>
                <?php endif; ?>
                <?php if ($svc['filled_from_vendor_at']): ?>
                <tr><td class="detail-label">Received from Refiller</td><td><?php echo date('d M Y h:i A', strtotime($svc['filled_from_vendor_at'])); ?></td></tr>
                <?php endif; ?>
                <?php if ($svc['returned_to_customer_at']): ?>
                <tr><td class="detail-label">Returned to You</td><td><?php echo date('d M Y h:i A', strtotime($svc['returned_to_customer_at'])); ?></td></tr>
                <?php endif; ?>
                <?php if ($svc['order_id'] && floatval($svc['gst_rate'] ?? 0) > 0): ?>
                <tr><td class="detail-label">Related Order</td><td><a href="order-detail.php?id=<?php echo $svc['order_id']; ?>" class="table-link">#<?php echo $svc['order_id']; ?></a> (<?php echo date('d M Y', strtotime($svc['order_date'])); ?>)</td></tr>
                <?php elseif ($svc['order_id']): ?>
                <tr><td class="detail-label">Related Order</td><td>#<?php echo $svc['order_id']; ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Progress Tracker</h2></div>
        <div class="card-body">
            <div class="tracker-steps">
                <?php foreach ($stage_flow as $idx => $stage):
                    $completed = $idx <= $current_stage_idx;
                    $active = $idx === $current_stage_idx;
                ?>
                <div class="tracker-step <?php echo $completed ? 'completed' : ''; ?> <?php echo $active ? 'active' : ''; ?>">
                    <div class="tracker-dot">
                        <?php if ($completed): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php else: ?>
                        <span class="tracker-number"><?php echo $idx + 1; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="tracker-text">
                        <div class="tracker-title"><?php echo $status_labels[$stage]; ?></div>
                        <?php
                        $date_field = '';
                        if ($stage === 'received') $date_field = 'created_at';
                        elseif ($stage === 'sent_to_vendor') $date_field = 'sent_to_vendor_at';
                        elseif ($stage === 'filled_from_vendor') $date_field = 'filled_from_vendor_at';
                        elseif ($stage === 'returned_to_warehouse') $date_field = 'returned_to_warehouse_at';
                        elseif ($stage === 'returned_to_customer') $date_field = 'returned_to_customer_at';
                        if ($svc[$date_field]): ?>
                        <div class="tracker-date"><?php echo date('d M Y', strtotime($svc[$date_field])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($payments)): ?>
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header"><h2>Payment History</h2></div>
    <div class="card-body p-0">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td><?php echo date('d M Y', strtotime($pay['payment_date'])); ?></td>
                        <td>₹<?php echo number_format($pay['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($pay['payment_method'])); ?></td>
                        <td><?php echo htmlspecialchars($pay['reference_number'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
