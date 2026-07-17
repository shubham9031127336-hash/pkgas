<?php
$page_title = "Order Detail";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';

$customer_id = get_customer_id();
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    header("Location: orders.php");
    exit();
}

$stmt = $pdo->prepare("SELECT o.* FROM refill_orders o WHERE o.id = ? AND o.customer_id = ? AND o.gst_rate > 0");
$stmt->execute([$order_id, $customer_id]);
$order = $stmt->fetch();

if (!$order || floatval($order['gst_rate']) <= 0) {
    header("Location: orders.php");
    exit();
}

$items_stmt = $pdo->prepare("
    SELECT oi.*, g.name as gas_name, g.chemical_formula,
           oi.size_capacity,
           p.name as product_name, p.unit as product_unit,
           cy_issued.serial_number as serial_number,
           cy_returned.serial_number as returned_serial
    FROM refill_order_items oi
    LEFT JOIN gas_types g ON oi.gas_type_id = g.id
    LEFT JOIN products p ON oi.product_id = p.id
    LEFT JOIN cylinders cy_issued ON oi.cylinder_id = cy_issued.id
    LEFT JOIN cylinders cy_returned ON oi.returned_cylinder_id = cy_returned.id
    WHERE oi.refill_order_id = ?
");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

$pay_stmt = $pdo->prepare("SELECT * FROM payments WHERE refill_order_id = ? ORDER BY payment_date DESC");
$pay_stmt->execute([$order_id]);
$payments = $pay_stmt->fetchAll();

$paid_total = 0;
foreach ($payments as $p) { $paid_total += floatval($p['amount']); }
$balance = floatval($order['grand_total']) - $paid_total;
?>
<div class="page-header">
    <a href="orders.php" class="card-link" style="display:inline-flex;align-items:center;gap:0.4rem;margin-bottom:1rem;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Orders
    </a>
    <h1>Order #<?php echo $order['id']; ?></h1>
</div>

<div class="card-grid">
    <div class="card">
        <div class="card-header"><h2>Order Summary</h2></div>
        <div class="card-body">
            <table style="width:100%;">
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Date</td><td style="padding:0.4rem 0;font-weight:600;"><?php echo date('d M Y, h:i A', strtotime($order['order_date'])); ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Subtotal</td><td style="padding:0.4rem 0;">₹<?php echo number_format($order['subtotal'], 2); ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Tax (GST)</td><td style="padding:0.4rem 0;">₹<?php echo number_format($order['tax_amount'], 2); ?></td></tr>
                <?php if (floatval($order['discount']) > 0): ?>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Discount</td><td style="padding:0.4rem 0;color:var(--danger);">-₹<?php echo number_format($order['discount'], 2); ?></td></tr>
                <?php endif; ?>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Deposit</td><td style="padding:0.4rem 0;">₹<?php echo number_format($order['deposit_amount'], 2); ?></td></tr>
                <tr style="border-top:2px solid var(--border);"><td style="padding:0.6rem 0;font-weight:700;">Grand Total</td><td style="padding:0.6rem 0;font-weight:800;">₹<?php echo number_format($order['grand_total'], 2); ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Payment Status</td><td style="padding:0.4rem 0;"><span class="badge badge-<?php echo $order['payment_status']; ?>"><?php echo ucfirst($order['payment_status']); ?></span></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Payment Method</td><td style="padding:0.4rem 0;font-weight:600;"><?php echo htmlspecialchars($order['payment_method'] ?: '—'); ?></td></tr>
                <?php if ($order['invoice_number']): ?>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Invoice #</td><td style="padding:0.4rem 0;font-weight:600;"><?php echo htmlspecialchars($order['invoice_number']); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Payment Breakdown</h2></div>
        <div class="card-body">
            <table style="width:100%;">
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Total Amount</td><td style="padding:0.4rem 0;font-weight:600;">₹<?php echo number_format($order['grand_total'], 2); ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Paid</td><td style="padding:0.4rem 0;font-weight:600;color:var(--success);">₹<?php echo number_format($paid_total, 2); ?></td></tr>
                <tr style="border-top:2px solid var(--border);"><td style="padding:0.6rem 0;font-weight:700;">Balance</td><td style="padding:0.6rem 0;font-weight:800;color:<?php echo $balance > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">₹<?php echo number_format($balance, 2); ?></td></tr>
            </table>
            <?php if ($balance > 0): ?>
                <a href="make-payment.php?order_id=<?php echo $order['id']; ?>" class="btn-filter" style="display:block;text-align:center;margin-top:1rem;">Pay Now</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:1.5rem;">
    <div class="card-header"><h2>Items (<?php echo count($items); ?>)</h2></div>
    <div class="card-body p-0">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Gas</th>
                        <th>Size</th>
                        <th>Qty</th>
                        <th>Rate</th>
                        <th>Total</th>
                        <th>Cylinder Serial</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><strong><?php echo $item['is_rental'] == 3 ? htmlspecialchars($item['product_name'] ?? 'Product') : htmlspecialchars($item['gas_name']); ?></strong></td>
                        <td><?php echo $item['is_rental'] == 3 ? '—' : htmlspecialchars($item['size_capacity']); ?></td>
                        <td><?php echo $item['qty']; ?></td>
                        <td>₹<?php echo number_format($item['price_per_unit'], 2); ?></td>
                        <td>₹<?php echo number_format($item['price_per_unit'] * $item['qty'], 2); ?></td>
                        <td><?php echo $item['is_rental'] == 3 ? '—' : htmlspecialchars($item['serial_number'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($payments): ?>
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
                        <th>Type</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?php echo date('d M Y', strtotime($p['payment_date'])); ?></td>
                        <td>₹<?php echo number_format($p['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($p['payment_method']); ?></td>
                        <td><?php echo htmlspecialchars(str_replace('_', ' ', $p['payment_type'])); ?></td>
                        <td><?php echo htmlspecialchars($p['notes'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
