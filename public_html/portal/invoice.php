<?php
$page_title = "Invoice";
require_once __DIR__ . '/auth.php';
require_customer_login();
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/csrf.php';

$customer_id = get_customer_id();
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($order_id <= 0) {
    header("Location: orders.php");
    exit();
}

// Verify order belongs to this customer
$stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, c.mobile as customer_mobile, c.address as customer_address, c.gst_number as customer_gst, c.customer_type FROM refill_orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ? AND o.customer_id = ? AND o.gst_rate > 0");
$stmt->execute([$order_id, $customer_id]);
$order = $stmt->fetch();

if (!$order || floatval($order['gst_rate']) <= 0) {
    header("Location: orders.php");
    exit();
}

require_once __DIR__ . '/../admin/business_helper.php';
$business = getBusiness($order['business_name'] ?: getBrandConfig()['business_key']);

$items_stmt = $pdo->prepare("
    SELECT oi.*, g.name as gas_name, g.chemical_formula,
           oi.size_capacity,
           p.name as product_name, p.unit as product_unit,
           cy_issued.serial_number as serial_number,
           oi.gst_rate, oi.taxable_amount, oi.gst_amount, oi.cgst, oi.sgst
    FROM refill_order_items oi
    LEFT JOIN gas_types g ON oi.gas_type_id = g.id
    LEFT JOIN products p ON oi.product_id = p.id
    LEFT JOIN cylinders cy_issued ON oi.cylinder_id = cy_issued.id
    WHERE oi.refill_order_id = ?
");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

$deposit_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE refill_order_id = ? AND payment_type = 'deposit_added'");
$deposit_stmt->execute([$order_id]);
$actual_deposit = floatval($deposit_stmt->fetchColumn());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../Images/favicon.png">
    <title>Invoice #<?php echo htmlspecialchars($order['invoice_number']); ?> - Prem Gas Solution</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../admin/invoice.css">
    <style>
        body { font-family: 'Outfit', sans-serif; background: #f1f5f9; padding: 2rem; }
        .no-print { max-width: 800px; margin: 0 auto 2rem; }
        .receipt-page { max-width: 800px; margin: 0 auto; }
        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
        <a href="order-detail.php?id=<?php echo $order_id; ?>" style="text-decoration:none;color:var(--muted);display:flex;align-items:center;gap:0.5rem;font-weight:700;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Back to Order
        </a>
        <button onclick="window.print()" style="padding:0.6rem 1.5rem;background:#2563eb;color:#fff;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:0.9rem;">Print / Download PDF</button>
    </div>

    <div class="receipt-page">
        <div class="print-slip">
            <?php
            $total_damage = 0;
            foreach ($items as $item) { $total_damage += floatval($item['damage_amount'] ?? 0); }
            ?>
            <div class="receipt">
                <div class="receipt-header">
                    <div class="company-name"><?php echo htmlspecialchars($business['name']); ?></div>
                    <div class="company-info">
                        <?php echo htmlspecialchars($business['tagline']); ?><br>
                        <?php echo htmlspecialchars($business['address']); ?><br>
                        GSTIN: <?php echo htmlspecialchars($business['gstin']); ?> | Phone: <?php echo htmlspecialchars($business['phone']); ?>
                    </div>
                    <div class="receipt-type" style="background:#059669;">Consumer Copy</div>
                </div>

                <div class="receipt-body">
                    <div class="receipt-meta">
                        <div class="meta-left">
                            <span class="meta-label">Invoice No.</span>
                            <span class="meta-value"><?php echo htmlspecialchars($order['invoice_number']); ?></span>
                        </div>
                        <div class="meta-right">
                            <span class="meta-label">Date</span>
                            <span class="meta-value"><?php echo date('d-M-Y', strtotime($order['order_date'])); ?></span>
                        </div>
                    </div>


                    <div class="customer-info">
                        <div class="ci-row"><span class="ci-label">Customer</span><span class="ci-value"><?php echo htmlspecialchars($order['customer_name']); ?></span></div>
                        <div class="ci-row"><span class="ci-label">Mobile</span><span class="ci-value"><?php echo htmlspecialchars($order['customer_mobile']); ?></span></div>
                        <?php if ($order['customer_address']): ?>
                        <div class="ci-row"><span class="ci-label">Address</span><span class="ci-value"><?php echo htmlspecialchars($order['customer_address']); ?></span></div>
                        <?php endif; ?>
                        <div class="ci-row"><span class="ci-label">GSTIN</span><span class="ci-value"><?php echo htmlspecialchars($order['customer_gst'] ?: 'Consumer'); ?></span></div>
                    </div>

                    <table class="receipt-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>HSN/SAC</th>
                                <th style="text-align:right;">Rate</th>
                                <th style="text-align:right;">Qty</th>
                                <th style="text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item['is_rental'] == 3): ?>
                                        <strong>📦 <?php echo htmlspecialchars($item['product_name'] ?? 'Product'); ?></strong>
                                    <?php else: ?>
                                        <strong><?php echo htmlspecialchars($item['gas_name']); ?> (<?php echo htmlspecialchars($item['size_capacity']); ?>)</strong>
                                        <?php if ($item['serial_number']): ?>
                                        <div style="font-size:0.72rem;margin-top:2px;color:#059669;font-weight:600;">Cylinder: <?php echo htmlspecialchars($item['serial_number']); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="vertical-align:middle;font-family:monospace;font-size:0.75rem;"><?php echo htmlspecialchars($item['hsn_code'] ?? $item['gas_hsn'] ?? '—'); ?></td>
                                <td style="text-align:right;vertical-align:middle;">₹<?php echo number_format($item['price_per_unit'], 2); ?></td>
                                <td style="text-align:right;vertical-align:middle;"><?php echo intval($item['qty']); ?></td>
                                <td style="text-align:right;vertical-align:middle;font-weight:700;">₹<?php echo number_format($item['price_per_unit'] * $item['qty'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="totals">
                        <div class="total-row"><span>Subtotal</span><strong>₹<?php echo number_format($order['subtotal'], 2); ?></strong></div>
                        <?php if ($actual_deposit > 0): ?>
                        <div class="total-row"><span>Security Deposit</span><strong style="color:#3b82f6;">₹<?php echo number_format($actual_deposit, 2); ?></strong></div>
                        <?php endif; ?>
                        <?php
                        $gst_by_rate = [];
                        foreach ($items as $itm) {
                            $rate = floatval($itm['gst_rate'] ?? 0);
                            $gst_amt = floatval($itm['gst_amount'] ?? 0);
                            $cgst = floatval($itm['cgst'] ?? 0);
                            $sgst = floatval($itm['sgst'] ?? 0);
                            if ($rate > 0) {
                                if (!isset($gst_by_rate[$rate])) $gst_by_rate[$rate] = ['taxable' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0];
                                $gst_by_rate[$rate]['taxable'] += floatval($itm['taxable_amount'] ?? 0);
                                $gst_by_rate[$rate]['gst'] += $gst_amt;
                                $gst_by_rate[$rate]['cgst'] += $cgst;
                                $gst_by_rate[$rate]['sgst'] += $sgst;
                            }
                        }
                        if (!empty($gst_by_rate)): foreach ($gst_by_rate as $rate => $g): ?>
                        <div class="total-row" style="font-size:0.85rem;">
                            <span>Taxable Value (<?= $rate ?>%)</span>
                            <strong>₹<?= number_format($g['taxable'], 2) ?></strong>
                        </div>
                        <div class="total-row" style="font-size:0.85rem;color:#555;">
                            <span>CGST @ <?= $rate/2 ?>%</span>
                            <strong>₹<?= number_format($g['cgst'], 2) ?></strong>
                        </div>
                        <div class="total-row" style="font-size:0.85rem;color:#555;">
                            <span>SGST @ <?= $rate/2 ?>%</span>
                            <strong>₹<?= number_format($g['sgst'], 2) ?></strong>
                        </div>
                        <?php endforeach; ?>
                        <div class="total-row" style="border-top:1px dashed #ddd;padding-top:6px;">
                            <span>Total GST</span>
                            <strong>₹<?= number_format($order['tax_amount'], 2) ?></strong>
                        </div>
                        <?php else: ?>
                        <div class="total-row"><span>GST (<?= number_format(floatval($order['gst_rate'] ?? 18), 0) ?>%)</span><strong>₹<?php echo number_format($order['tax_amount'], 2); ?></strong></div>
                        <?php endif; ?>
                        <?php if (floatval($order['discount']) > 0): ?>
                        <div class="total-row" style="color:#dc2626;"><span>Discount</span><strong>-₹<?php echo number_format($order['discount'], 2); ?></strong></div>
                        <?php endif; ?>
                        <?php if ($total_damage > 0): ?>
                        <div class="total-row" style="color:#dc2626;"><span>Damage Charges</span><strong>₹<?php echo number_format($total_damage, 2); ?></strong></div>
                        <?php endif; ?>
                        <div class="total-divider"></div>
                        <div class="total-row grand-total"><span>Grand Total</span><strong>₹<?php echo number_format($order['grand_total'], 2); ?></strong></div>
                        <div class="total-row"><span>Payment Status</span><strong><span style="color:<?php echo $order['payment_status'] === 'paid' ? '#10b981' : '#f59e0b'; ?>;"><?php echo ucfirst($order['payment_status']); ?></span></strong></div>
                    </div>

                    <?php if (!empty($business['invoice_terms']) || !empty($business['bank_details'])): ?>
                    <div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px dashed #d1d5db;font-size:0.78rem;line-height:1.5;color:#475569;">
                        <?php if (!empty($business['bank_details'])): ?>
                        <div><strong>Bank Details:</strong> <?php echo nl2br(htmlspecialchars($business['bank_details'])); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($business['invoice_terms'])): ?>
                        <div style="margin-top:4px;"><strong>Terms:</strong> <?php echo nl2br(htmlspecialchars($business['invoice_terms'])); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="receipt-footer">
                        <div class="signature-area">
                            <div class="signature-line"></div>
                            <p>Customer's Signature</p>
                        </div>
                        <div class="stamp-area">
                            <div class="receipt-number"><?php echo htmlspecialchars($order['invoice_number']); ?></div>
                        </div>
                        <div class="signature-area">
                            <div class="signature-line"></div>
                            <p>Authorized Signee</p>
                        </div>
                    </div>
                </div>

                <div class="receipt-note">
                    This is a computer-generated slip and does not require a physical signature.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
