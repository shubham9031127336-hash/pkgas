<?php
$page_title = "Make a Payment";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/csrf.php';
require_once __DIR__ . '/../admin/inventory-utils.php';

$customer_id = get_customer_id();
$pre_selected_order = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$success = '';
$error = '';

// Get outstanding orders
$orders_stmt = $pdo->prepare("
    SELECT o.id, o.order_date, o.grand_total, o.payment_status,
           COALESCE((SELECT SUM(amount) FROM payments WHERE refill_order_id = o.id AND COALESCE(payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')), 0) as paid_amount
    FROM refill_orders o
    WHERE o.customer_id = ? AND COALESCE(o.gst_rate, 0) > 0
    HAVING grand_total > paid_amount
    ORDER BY o.order_date DESC
");
$orders_stmt->execute([$customer_id]);
$outstanding_orders = $orders_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($order_id <= 0 || $amount <= 0 || empty($payment_method)) {
        $error = 'Please select an order, enter amount, and choose a payment method.';
    } else {
        try {
            $verify = $pdo->prepare("SELECT id, customer_id, grand_total FROM refill_orders WHERE id = ? AND customer_id = ?");
            $verify->execute([$order_id, $customer_id]);
            $order = $verify->fetch();

            if (!$order) {
                $error = 'Invalid order selected.';
            } else {
                $paid = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE refill_order_id = ?");
                $paid->execute([$order_id]);
                $already_paid = floatval($paid->fetchColumn());
                $new_paid = $already_paid + $amount;
                $grand_total = floatval($order['grand_total']);

                $new_status = 'partial';
                if ($new_paid >= $grand_total) {
                    $new_status = 'paid';
                }

                $pdo->beginTransaction();

                $ins = $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, 'refill_payment', ?)");
                $ins->execute([$customer_id, $order_id, $amount, $payment_method, $notes]);

                recalculateOrderPaymentStatus($pdo, $order_id);

                $pdo->commit();

                $gst_chk = $pdo->prepare("SELECT gst_rate FROM refill_orders WHERE id = ?");
                $gst_chk->execute([$order_id]);
                if (floatval($gst_chk->fetchColumn() ?: 0) > 0) {
                    require_once __DIR__ . '/email.php';
                    sendPaymentReceivedNotification($customer_id, $amount, $payment_method, $pdo);
                }

                $_SESSION['success_flash'] = "Payment of ₹" . number_format($amount, 2) . " recorded successfully.";
                header("Location: order-detail.php?id=$order_id");
                exit();
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Payment failed: ' . $e->getMessage();
        }
    }
}
?>
<div class="page-header">
    <a href="payments.php" class="card-link" style="display:inline-flex;align-items:center;gap:0.4rem;margin-bottom:1rem;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Payments
    </a>
    <h1>Make a Payment</h1>
</div>

<?php if ($error): ?>
<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card" style="max-width:600px;">
    <div class="card-header"><h2>Payment Details</h2></div>
    <div class="card-body">
        <?php if (empty($outstanding_orders)): ?>
            <p style="color:var(--muted);text-align:center;padding:1rem;">No outstanding orders. All your orders are paid.</p>
        <?php else: ?>
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="form-group" style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Select Order</label>
                <select name="order_id" required style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                    <option value="">— Select Order —</option>
                    <?php foreach ($outstanding_orders as $o):
                        $due = floatval($o['grand_total']) - floatval($o['paid_amount']);
                    ?>
                    <option value="<?php echo $o['id']; ?>" <?php echo $pre_selected_order === intval($o['id']) ? 'selected' : ''; ?>>
                        #<?php echo $o['id']; ?> — ₹<?php echo number_format($due, 2); ?> due (<?php echo date('d M Y', strtotime($o['order_date'])); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Amount (₹)</label>
                <input type="number" step="0.01" min="1" name="amount" required style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;" placeholder="0.00">
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Payment Method</label>
                <select name="payment_method" required style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                    <option value="">— Select —</option>
                    <option value="Cash">Cash</option>
                    <option value="UPI">UPI</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                    <option value="Card">Card</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Notes (optional)</label>
                <textarea name="notes" style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;resize:vertical;" rows="2" placeholder="Any notes about this payment"></textarea>
            </div>

            <button type="submit" class="btn-filter" style="width:100%;padding:0.85rem;font-size:1rem;">Record Payment</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
