<?php
$page_title = "My Payments";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';

$customer_id = get_customer_id();

$stmt = $pdo->prepare("
    SELECT p.*, o.grand_total, o.payment_status as order_status, o.gst_rate,
           GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as gas_names
    FROM payments p
    LEFT JOIN refill_orders o ON p.refill_order_id = o.id
    LEFT JOIN refill_order_items oi ON oi.refill_order_id = o.id
    LEFT JOIN gas_types g ON oi.gas_type_id = g.id
    WHERE p.customer_id = ?
    GROUP BY p.id
    ORDER BY p.payment_date DESC
");
$stmt->execute([$customer_id]);
$payments = $stmt->fetchAll();

$total_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE customer_id = ?");
$total_stmt->execute([$customer_id]);
$total_paid = $total_stmt->fetchColumn();

$bal_stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN payment_status IN ('pending','partial') THEN grand_total - COALESCE((SELECT SUM(amount) FROM payments WHERE refill_order_id = refill_orders.id AND customer_id = ?), 0) ELSE 0 END), 0) FROM refill_orders WHERE customer_id = ? AND gst_rate > 0");
$bal_stmt->execute([$customer_id, $customer_id]);
$outstanding = $bal_stmt->fetchColumn();
?>
<div class="page-header">
    <h1>Payments</h1>
</div>

<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5;color:#10b981;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value">₹<?php echo number_format($total_paid, 2); ?></span>
            <span class="stat-label">Total Paid</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#ef4444;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value">₹<?php echo number_format($outstanding, 2); ?></span>
            <span class="stat-label">Outstanding</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#2563eb;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?php echo count($payments); ?></span>
            <span class="stat-label">Transactions</span>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body" style="text-align:center;">
        <a href="make-payment.php" class="btn-filter" style="display:inline-block;padding:0.85rem 2rem;font-size:1rem;">Make a Payment</a>
    </div>
</div>

<?php if (empty($payments)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem;">
        <p style="color:var(--muted);font-size:1rem;">No payment records found.</p>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header"><h2>Payment History</h2></div>
    <div class="card-body p-0">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Order</th>
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
                        <td>
                            <?php if ($p['refill_order_id'] && floatval($p['gst_rate'] ?? 0) > 0): ?>
                                <a href="order-detail.php?id=<?php echo $p['refill_order_id']; ?>" class="table-link">#<?php echo $p['refill_order_id']; ?></a>
                            <?php else: ?>
                                <?php echo $p['refill_order_id'] ? '#' . $p['refill_order_id'] : '—'; ?>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;">₹<?php echo number_format($p['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($p['payment_method']); ?></td>
                        <td><span class="badge badge-paid" style="background:var(--accent-soft);color:var(--accent);"><?php echo htmlspecialchars(str_replace('_', ' ', $p['payment_type'])); ?></span></td>
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
