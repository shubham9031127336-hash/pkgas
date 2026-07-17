<?php
$page_title = "My Orders";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';

$customer_id = get_customer_id();

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['from']) ? $_GET['from'] : '';
$date_to = isset($_GET['to']) ? $_GET['to'] : '';

$sql = "SELECT o.* FROM refill_orders o WHERE o.customer_id = ? AND o.gst_rate > 0";
$params = [$customer_id];

if ($status_filter === 'paid' || $status_filter === 'pending' || $status_filter === 'partial') {
    $sql .= " AND o.payment_status = ?";
    $params[] = $status_filter;
}
if (!empty($date_from)) {
    $sql .= " AND DATE(o.order_date) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $sql .= " AND DATE(o.order_date) <= ?";
    $params[] = $date_to;
}
$sql .= " ORDER BY o.order_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();
?>
<div class="page-header">
    <h1>My Orders</h1>
</div>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <select name="status">
                    <option value="">All Status</option>
                    <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                </select>
                <input type="date" name="from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From">
                <input type="date" name="to" value="<?php echo htmlspecialchars($date_to); ?>" placeholder="To">
                <button type="submit" class="btn-filter">Filter</button>
                <a href="orders.php" class="btn-filter btn-clear">Clear</a>
            </div>
        </form>
    </div>
</div>

<?php if (empty($orders)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem;">
        <p style="color:var(--muted);font-size:1rem;">No orders found.</p>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Invoice</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $item_stmt = $pdo->prepare("SELECT COUNT(*) FROM refill_order_items WHERE refill_order_id = ?");
                        $item_stmt->execute([$order['id']]);
                        $item_count = $item_stmt->fetchColumn();
                    ?>
                    <tr>
                        <td>#<?php echo $order['id']; ?></td>
                        <td><?php echo date('d M Y', strtotime($order['order_date'])); ?></td>
                        <td><?php echo $item_count; ?></td>
                        <td>₹<?php echo number_format($order['grand_total'], 2); ?></td>
                        <td><span class="badge badge-<?php echo $order['payment_status']; ?>"><?php echo ucfirst($order['payment_status']); ?></span></td>
                        <td><?php echo $order['invoice_number'] ? htmlspecialchars($order['invoice_number']) : '—'; ?></td>
                        <td>
                            <a href="order-detail.php?id=<?php echo $order['id']; ?>" class="table-link">View</a>
                            <?php if ($order['invoice_number']): ?>
                                | <a href="invoice.php?order_id=<?php echo $order['id']; ?>" class="table-link" target="_blank">Invoice</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
