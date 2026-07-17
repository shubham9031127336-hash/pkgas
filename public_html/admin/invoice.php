<?php
$page_title = "Invoice Receipt Slip";
$active_menu = "orders";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'business_helper.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';
runGSTMigrations($pdo);
runRefillWithoutExchangeMigrations($pdo);

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$include_cust_copy = isset($_GET['cust_copy']) ? intval($_GET['cust_copy']) : 1;

if ($order_id <= 0) {
    echo "<script>window.location.href='refill-orders.php';</script>";
    exit();
}

$email_status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_email') {
    $chk = $pdo->prepare("SELECT customer_id, gst_rate FROM refill_orders WHERE id = ?");
    $chk->execute([$order_id]);
    $row = $chk->fetch();
    if ($row) {
        if (floatval($row['gst_rate'] ?? 0) > 0) {
            require_once __DIR__ . '/../portal/email.php';
            sendOrderConfirmation($order_id, $row['customer_id'], $pdo);
            $email_status = 'sent';
        } else {
            $email_status = 'blocked';
        }
    } else {
        $email_status = 'error';
    }
}

// Fetch Order and Customer details
try {
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name, c.mobile as customer_mobile, c.address as customer_address, c.gst_number as customer_gst, c.customer_type, o.invoice_number 
        FROM refill_orders o 
        JOIN customers c ON o.customer_id = c.id 
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        echo "<script>window.location.href='refill-orders.php';</script>";
        exit();
    }
    
    $business = getBusiness($order['business_name'] ?: getBrandConfig()['business_key']);
    
    // Fetch Order Items
    $items_stmt = $pdo->prepare("
         SELECT oi.*, g.name as gas_name, g.chemical_formula, g.hsn_code as gas_hsn,
                p.name as product_name, p.unit as product_unit, p.hsn_code as product_hsn,
               cy_issued.serial_number as serial_number,
               cy_returned.serial_number as returned_serial,
               oi.sold_cylinder_serial, oi.sell_price
        FROM refill_order_items oi 
        LEFT JOIN gas_types g ON oi.gas_type_id = g.id 
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN cylinders cy_issued ON oi.cylinder_id = cy_issued.id
        LEFT JOIN cylinders cy_returned ON oi.returned_cylinder_id = cy_returned.id
        WHERE oi.refill_order_id = ?
    ");
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll();
    
    // Fetch actual deposit collected from payments for this order
    $deposit_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE refill_order_id = ? AND payment_type = 'deposit_added'");
    $deposit_stmt->execute([$order_id]);
    $actual_deposit = floatval($deposit_stmt->fetchColumn());
    
    // Fallback to refill_orders.deposit_amount if no payment recorded (credit orders, etc.)
    if ($actual_deposit <= 0) {
        $deposit_stmt2 = $pdo->prepare("SELECT deposit_amount FROM refill_orders WHERE id = ?");
        $deposit_stmt2->execute([$order_id]);
        $actual_deposit = floatval($deposit_stmt2->fetchColumn());
    }
    
    // Fetch deposit_settled (how much of this order's deposit has been refunded/settled)
    $settled_stmt = $pdo->prepare("SELECT deposit_settled FROM refill_orders WHERE id = ?");
    $settled_stmt->execute([$order_id]);
    $deposit_settled = floatval($settled_stmt->fetchColumn());
    $deposit_remaining = $actual_deposit - $deposit_settled;
} catch (PDOException $e) {
    error_log("invoice.php: " . $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/error-page.php';
    exit;
}

// Generate shareable invoice link
require_once __DIR__ . '/mail-config.php';
$invoice_token = generateInvoiceToken($order_id);
$invoice_shortlink = getSiteUrl('portal/invoice-pdf.php?token=' . urlencode($invoice_token));

// Format WhatsApp sharing text
$total_damage = 0;
foreach ($items as $item) {
    $total_damage += floatval($item['damage_amount'] ?? 0);
}
$wa_message = "Dear " . $order['customer_name'] . ",\n\n";
$wa_message .= "Thank you for doing business with *" . $business['label'] . "*!\n";
$wa_message .= "Your Refill Invoice *" . $order['invoice_number'] . "* has been processed.\n\n";
$wa_message .= "*Summary of Account:*\n";
$is_credit = ($order['payment_method'] === 'Credit') || !empty($order['is_credit_order']);
if ($is_credit) {
    $wa_message .= "- Invoice Amount: ₹" . number_format($order['grand_total'], 2) . " (Pay Later — Pending)\n";
} else {
    $wa_message .= "- Invoice Amount: ₹" . number_format($order['grand_total'], 2) . " (Paid via " . $order['payment_method'] . ")\n";
}
if ($total_damage > 0) {
    $wa_message .= "- Damage Charges included: ₹" . number_format($total_damage, 2) . "\n";
}
if ($actual_deposit > 0) {
    $wa_message .= "- Security Deposit included: ₹" . number_format($actual_deposit, 2) . "\n";
}
$wa_message .= "\n*Items Filled:*\n";
foreach ($items as $item) {
    if ($item['is_rental'] == 3) {
        $wa_message .= "- " . ($item['product_name'] ?? 'Product') . " x " . $item['qty'] . " @ ₹" . number_format($item['price_per_unit'], 2) . "\n";
    } else {
        $serial_display = $item['is_rental'] == 2 ? ($item['sold_cylinder_serial'] ?? '') : ($item['serial_number'] ?? '');
        $wa_message .= "- " . $item['gas_name'] . " (" . $item['size_capacity'] . ") x " . $item['qty'] . ($serial_display ? " [Serial: " . $serial_display . "]" : "") . (floatval($item['damage_amount'] ?? 0) > 0 ? " [Damage: ₹" . number_format($item['damage_amount'], 2) . "]" : "") . "\n";
    }
}
$wa_message .= "\nPlease keep this receipt for internal records and return deposit cylinders in good condition.\n";
$wa_message .= "\nDownload Invoice: " . $invoice_shortlink . "\n";
$wa_message .= "\nFor support, contact us at: " . $business['phone'] . ".\n*" . $business['label'] . "*";

$whatsapp_url = floatval($order['gst_rate'] ?? 0) > 0 ? "https://wa.me/91" . $order['customer_mobile'] . "?text=" . urlencode($wa_message) : '#';

function render_receipt_slip($order, $items, $business, $copy_label, $copy_color, $signee_label, $actual_deposit = 0, $deposit_settled = 0, $deposit_remaining = 0) {
    ?>
    <div class="receipt">
        <div class="receipt-header">
            <div class="company-name"><?php echo htmlspecialchars($business['name']); ?></div>
            <div class="company-info">
                <?php echo htmlspecialchars($business['tagline']); ?><br>
                <?php echo htmlspecialchars($business['address']); ?><br>
                GSTIN: <?php echo htmlspecialchars($business['gstin']); ?> | Phone: <?php echo htmlspecialchars($business['phone']); ?>
            </div>
            <div class="receipt-type" style="background: <?php echo $copy_color; ?>;">
                <?php echo $copy_label; ?>
            </div>
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
                <div class="ci-row">
                    <span class="ci-label">Customer</span>
                    <span class="ci-value"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                </div>
                <div class="ci-row">
                    <span class="ci-label">Mobile</span>
                    <span class="ci-value"><?php echo htmlspecialchars($order['customer_mobile']); ?></span>
                </div>
                <?php if ($order['customer_address']): ?>
                <div class="ci-row">
                    <span class="ci-label">Address</span>
                    <span class="ci-value"><?php echo htmlspecialchars($order['customer_address']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($order['vehicle_number'])): ?>
                <div class="ci-row">
                    <span class="ci-label">Vehicle No.</span>
                    <span class="ci-value"><?php echo htmlspecialchars($order['vehicle_number']); ?></span>
                </div>
                <?php endif; ?>
                <div class="ci-row">
                    <span class="ci-label">GSTIN</span>
                    <span class="ci-value"><?php echo htmlspecialchars($order['customer_gst'] ?: 'Consumer'); ?></span>
                </div>
                <?php if (!empty($order['place_of_supply_state_code'])): ?>
                <div class="ci-row">
                    <span class="ci-label">Place of Supply</span>
                    <span class="ci-value">State Code: <?php echo intval($order['place_of_supply_state_code']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($order['reverse_charge'])): ?>
                <div class="ci-row">
                    <span class="ci-label">Reverse Charge</span>
                    <span class="ci-value" style="color:#dc2626;font-weight:700;">Yes</span>
                </div>
                <?php endif; ?>
            </div>

            <table class="receipt-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Size</th>
                        <th>HSN/SAC</th>
                        <th style="text-align: right;">Rate</th>
                        <th style="text-align: right;">Qty</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $total_damage = 0; ?>
                    <?php foreach ($items as $item): ?>
                    <?php $total_damage += floatval($item['damage_amount'] ?? 0); ?>
                    <tr>
                        <td>
                            <?php if ($item['is_rental'] == 3): ?>
                                <strong>📦 <?php echo htmlspecialchars($item['product_name'] ?? 'Product'); ?></strong>
                                <div style="font-size: 0.72rem; margin-top: 4px; line-height: 1.5; color: #0369a1;">
                                    <?php if ($item['product_hsn']): ?>HSN: <?php echo htmlspecialchars($item['product_hsn']); ?> | <?php endif; ?>
                                    Unit: <?php echo htmlspecialchars($item['product_unit'] ?: 'piece'); ?>
                                </div>
                            <?php else: ?>
                                <strong><?php echo htmlspecialchars($item['gas_name']); ?></strong>
                                <div style="font-size: 0.72rem; margin-top: 4px; line-height: 1.5;">
                                    <?php if ($item['is_rental'] == 2): ?>
                                        <span style="color: #dc2626; font-weight: 600;">SOLD Cylinder:</span> <?php echo htmlspecialchars($item['sold_cylinder_serial'] ?? $item['serial_number']); ?>
                                        <?php if (floatval($item['sell_price'] ?? 0) > 0): ?>
                                            <br><span style="color: #991b1b; font-weight: 600;">Cylinder Charge:</span> ₹<?php echo number_format($item['sell_price'], 2); ?>
                                        <?php endif; ?>
                                    <?php elseif ($item['serial_number'] && $item['returned_serial']): ?>
                                        <span style="color: #059669; font-weight: 600;">Issued:</span> <?php echo htmlspecialchars($item['serial_number']); ?>
                                        <span style="color: #dc2626; font-weight: 600;">Returned:</span> <?php echo htmlspecialchars($item['returned_serial']); ?>
                                    <?php elseif ($item['serial_number']): ?>
                                        <span style="color: #059669; font-weight: 600;">Cylinder:</span> <?php echo htmlspecialchars($item['serial_number']); ?>
                                    <?php endif; ?>
                                    <?php if (floatval($item['damage_amount'] ?? 0) > 0): ?>
                                        <div style="color:#dc2626;font-weight:700;margin-top:2px;">⚠ Damage: ₹<?php echo number_format($item['damage_amount'], 2); ?><?php if ($item['damage_description']): ?> (<?php echo htmlspecialchars($item['damage_description']); ?>)<?php endif; ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="vertical-align: middle;"><?php echo $item['is_rental'] == 3 ? '—' : htmlspecialchars($item['size_capacity']); ?></td>
                        <td style="vertical-align: middle; font-family: monospace; font-size: 0.75rem; color: var(--admin-muted);">
                            <?php echo htmlspecialchars($item['hsn_code'] ?? $item['gas_hsn'] ?? $item['product_hsn'] ?? '—'); ?>
                        </td>
                        <td style="text-align: right; vertical-align: middle;">₹<?php echo number_format($item['price_per_unit'], 2); ?></td>
                        <td style="text-align: right; vertical-align: middle;"><?php echo intval($item['qty']); ?></td>
                        <td style="text-align: right; vertical-align: middle; font-weight: 700;">
                            ₹<?php echo number_format(($item['price_per_unit'] * $item['qty']) + ($item['is_rental'] == 2 ? floatval($item['sell_price'] ?? 0) : 0), 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals">
                <div class="total-row">
                    <span>Subtotal</span>
                    <strong>₹<?php echo number_format($order['subtotal'], 2); ?></strong>
                </div>
                <?php if ($actual_deposit > 0): ?>
                <div class="total-row">
                    <span>Security Deposit (Collected)</span>
                    <strong style="color: #3b82f6;">₹<?php echo number_format($actual_deposit, 2); ?></strong>
                </div>
                <?php if ($deposit_settled > 0): ?>
                <div class="total-row">
                    <span>Deposit Settled</span>
                    <strong style="color: #d97706;">-₹<?php echo number_format($deposit_settled, 2); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($deposit_remaining > 0): ?>
                <div class="total-row">
                    <span>Deposit Remaining</span>
                    <strong style="color: #16a34a;">₹<?php echo number_format($deposit_remaining, 2); ?></strong>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php
                // Dynamic GST breakdown by rate
                $gst_by_rate = [];
                foreach ($items as $itm) {
                    $rate = floatval($itm['gst_rate'] ?? 0);
                    $gst_amt = floatval($itm['gst_amount'] ?? 0);
                    $cgst = floatval($itm['cgst'] ?? 0);
                    $sgst = floatval($itm['sgst'] ?? 0);
                    if ($rate > 0) {
                        $taxable = floatval($itm['taxable_amount'] ?? 0);
                        if (intval($itm['is_rental'] ?? 0) === 2) {
                            $expected_taxable = floatval($itm['price_per_unit'] ?? 0) * intval($itm['qty'] ?? 1) + floatval($itm['sell_price'] ?? 0);
                            if ($taxable < $expected_taxable) {
                                $taxable = $expected_taxable;
                                $gst_amt = round($taxable * $rate / 100, 2);
                                $cgst = round($gst_amt / 2, 2);
                                $sgst = $gst_amt - $cgst;
                            }
                        }
                        if (!isset($gst_by_rate[$rate])) $gst_by_rate[$rate] = ['taxable' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0];
                        $gst_by_rate[$rate]['taxable'] += $taxable;
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
                <div class="total-row">
                    <span>GST (18%)</span>
                    <strong>₹<?php echo number_format($order['tax_amount'], 2); ?></strong>
                </div>
                <?php endif; ?>
                <?php if (floatval($order['discount']) > 0): ?>
                <div class="total-row" style="color: #dc2626;">
                    <span>Discount</span>
                    <strong>-₹<?php echo number_format($order['discount'], 2); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($total_damage > 0): ?>
                <div class="total-row" style="color: #dc2626;">
                    <span>Damage Charges</span>
                    <strong>₹<?php echo number_format($total_damage, 2); ?></strong>
                </div>
                <?php endif; ?>
                <div class="total-divider"></div>
                <div class="total-row grand-total">
                    <span>Grand Total</span>
                    <strong>₹<?php echo number_format($order['grand_total'], 2); ?></strong>
                </div>
                <div class="total-row">
                    <span>Payment Method</span>
                    <strong>
                        <?php echo htmlspecialchars($order['payment_method'] ?: '—'); ?>
                    </strong>
                </div>
                <div class="total-row">
                    <span>Payment Status</span>
                    <strong>
                        <?php
                        $ps = $order['payment_status'] ?? 'pending';
                        $ps_color = $ps === 'paid' ? '#10b981' : ($ps === 'partial' ? '#f59e0b' : '#d97706');
                        $ps_bg = $ps === 'paid' ? '#ecfdf5' : ($ps === 'partial' ? '#fffbeb' : '#fffbeb');
                        ?>
                        <span style="font-size:0.75rem;font-weight:700;color:<?php echo $ps_color; ?>;background:<?php echo $ps_bg; ?>;padding:2px 10px;border-radius:8px;display:inline-block;"><?php echo ucfirst($ps); ?></span>
                    </strong>
                </div>
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
                    <div class="barcode">
                        <svg width="120" height="30" viewBox="0 0 120 30" fill="currentColor">
                            <rect x="0" width="4" height="30"/><rect x="6" width="2" height="30"/><rect x="10" width="6" height="30"/>
                            <rect x="18" width="1" height="30"/><rect x="21" width="3" height="30"/><rect x="26" width="4" height="30"/>
                            <rect x="32" width="2" height="30"/><rect x="36" width="6" height="30"/><rect x="44" width="1" height="30"/>
                            <rect x="47" width="3" height="30"/><rect x="52" width="4" height="30"/><rect x="58" width="2" height="30"/>
                            <rect x="62" width="6" height="30"/><rect x="70" width="1" height="30"/><rect x="73" width="3" height="30"/>
                            <rect x="78" width="4" height="30"/><rect x="84" width="2" height="30"/><rect x="88" width="6" height="30"/>
                            <rect x="96" width="4" height="30"/><rect x="102" width="2" height="30"/><rect x="106" width="4" height="30"/>
                            <rect x="112" width="3" height="30"/><rect x="117" width="3" height="30"/>
                        </svg>
                    </div>
                    <div class="receipt-number"><?php echo htmlspecialchars($order['invoice_number']); ?></div>
                </div>
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p><?php echo $signee_label; ?></p>
                </div>
            </div>
        </div>

        <div class="receipt-note">
            This is a computer-generated slip and does not require a physical signature.
        </div>
    </div>
    <?php
}
?>

<?php $inv_gst_rate = floatval($order['gst_rate'] ?? 0); ?>
<?php $csrf_token = generateCsrfToken(); ?>
<?php if ($email_status === 'sent'): ?>
<div class="no-print" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px 18px;border-radius:10px;margin-bottom:1rem;display:flex;align-items:center;gap:10px;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>
    <strong>Email sent successfully!</strong> The invoice has been resent to the customer.
</div>
<?php elseif ($email_status === 'blocked'): ?>
<div class="no-print" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:12px 18px;border-radius:10px;margin-bottom:1rem;display:flex;align-items:center;gap:10px;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <strong>Cannot send email.</strong> This order has 0% GST — sharing this invoice externally is not permitted.
</div>
<?php elseif ($email_status === 'error'): ?>
<div class="no-print" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:12px 18px;border-radius:10px;margin-bottom:1rem;display:flex;align-items:center;gap:10px;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <strong>Failed to send email.</strong> Please check the customer has a valid email address.
</div>
<?php endif; ?>

<div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <a href="refill-orders.php" style="text-decoration: none; color: var(--admin-muted); display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Refill Orders
    </a>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <?php if ($inv_gst_rate > 0): ?>
        <form method="POST" style="display:inline;"><?php csrfField(); ?>
            <input type="hidden" name="action" value="resend_email">
            <button type="submit" class="btn-primary" style="border-radius: 10px; background: #2563eb;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>
                Resend Email
            </button>
        </form>
        <a href="<?php echo $whatsapp_url; ?>" target="_blank" class="btn-primary" style="background: #25D366; border-radius: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.022-.08-.124-.22-.364-.34-.24-.12-1.418-.7-1.638-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-.992-.367-1.89-1.167-.698-.622-1.17-1.39-1.305-1.63-.137-.24-.015-.37.107-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.195-.47-.393-.407-.54-.415-.143-.007-.307-.007-.47-.007s-.43.06-.653.3c-.22.24-.848.83-.848 2.03s.87 2.36.99 2.53c.12.17 1.71 2.612 4.14 3.66.578.25 1.03.398 1.38.51.58.185 1.11.16 1.52.1.46-.07 1.418-.58 1.618-1.14.2-.56.2-1.04.14-1.14-.06-.1-.2-.16-.44-.28zM12 2C6.48 2 2 6.48 2 12c0 2.17.7 4.2 1.94 5.86L3 21l3.28-.96C7.8 21.3 9.8 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.93 0-3.73-.52-5.28-1.42l-.38-.22-1.95.57.58-1.9-.26-.41C3.8 15.13 3.25 13.11 3.25 11c0-4.83 3.92-8.75 8.75-8.75s8.75 3.92 8.75 8.75S16.83 20 12 20z"/></svg>
            Share on WhatsApp
        </a>
        <a href="<?php echo $invoice_shortlink; ?>" target="_blank" class="btn-primary" style="border-radius: 10px; background: #dc2626;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download PDF
        </a>
        <button onclick="copyInvoiceLink()" class="btn-primary" style="border-radius: 10px; background: #475569;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            Copy Link
        </button>
        <?php endif; ?>
        <button onclick="window.print()" class="btn-primary" style="border-radius: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Receipt
        </button>
        <button onclick="openEditModal(<?php echo $order_id; ?>)" class="btn-primary" style="border-radius: 10px; background: #8b5cf6;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit Order
        </button>
    </div>
</div>

<div class="receipt-page">

    <!-- SHOP COPY -->
    <div class="print-slip">
        <?php render_receipt_slip($order, $items, $business, 'Shop Copy', '#1e293b', 'Authorized Signee', $actual_deposit, $deposit_settled, $deposit_remaining); ?>
        <div class="tear-line"><span>✁ &mdash;&mdash;&mdash; Tear Here &mdash;&mdash;&mdash; ✁</span></div>
    </div>

    <!-- CONSUMER COPY -->
    <div class="print-slip">
        <?php render_receipt_slip($order, $items, $business, 'Consumer Copy', '#059669', 'Delivery Person Sign', $actual_deposit, $deposit_settled, $deposit_remaining); ?>
        <div class="tear-line"><span>✁ &mdash;&mdash;&mdash; Tear Here &mdash;&mdash;&mdash; ✁</span></div>
    </div>

    <!-- POLICE COPY -->
    <div class="print-slip">
        <?php render_receipt_slip($order, $items, $business, 'Police / Transport Copy', '#dc2626', 'Authorized Signee', $actual_deposit, $deposit_settled, $deposit_remaining); ?>
    </div>

</div>

<link rel="stylesheet" href="invoice.css">

<style>
/* ── Edit Order Modal ── */
.modal-overlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,0.45); display: flex;
    align-items: center; justify-content: center;
    padding: 1.5rem;
}
.modal-box {
    background: #fff; border-radius: 16px; max-width: 780px; width: 100%;
    max-height: 90vh; display: flex; flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.1rem 1.5rem; border-bottom: 1px solid #e5e7eb;
}
.modal-header h3 { margin: 0; font-size: 1.1rem; font-weight: 700; color: #1e293b; }
.modal-header .close-btn {
    background: none; border: none; font-size: 1.4rem; cursor: pointer;
    color: #94a3b8; padding: 0; line-height: 1;
}
.modal-header .close-btn:hover { color: #475569; }
.modal-body {
    padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1;
}
.modal-footer {
    display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;
    padding: 1rem 1.5rem; border-top: 1px solid #e5e7eb;
}
.modal-footer .btn-primary { padding: 0.5rem 1.2rem; border-radius: 10px; font-weight: 600; font-size: 0.85rem; background: #2563eb; color: #fff; border: none; cursor: pointer; }
.modal-footer .btn-primary:hover { background: #1d4ed8; }
.modal-footer .btn-secondary { padding: 0.5rem 1.2rem; border-radius: 10px; font-weight: 600; font-size: 0.85rem; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; cursor: pointer; }
.modal-footer .btn-secondary:hover { background: #e2e8f0; }
.modal-footer .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.edit-spinner { text-align: center; padding: 3rem 0; color: #94a3b8; font-size: 0.9rem; }
.edit-error { background: #fef2f2; color: #dc2626; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600; }
.edit-item-card {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
    padding: 1rem; margin-bottom: 0.75rem;
}
.edit-item-card .item-title { font-weight: 700; font-size: 0.9rem; color: #1e293b; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; }
.edit-item-card .item-title .item-badge { font-size: 0.65rem; padding: 2px 8px; border-radius: 6px; font-weight: 600; }
.edit-field-row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
.edit-field-row label { font-size: 0.75rem; font-weight: 600; color: #64748b; display: flex; flex-direction: column; gap: 0.2rem; }
.edit-field-row input, .edit-field-row select {
    padding: 0.4rem 0.5rem; font-size: 0.8rem; border: 1px solid #d1d5db;
    border-radius: 8px; width: 100px; outline: none;
}
.edit-field-row input:focus, .edit-field-row select:focus { border-color: #8b5cf6; box-shadow: 0 0 0 2px rgba(139,92,246,0.15); }
.edit-field-row input[type="number"] { text-align: right; }
.edit-global-row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-start; margin-bottom: 1rem; }
.edit-global-row label { font-size: 0.8rem; font-weight: 600; color: #374151; display: flex; flex-direction: column; gap: 0.2rem; }
.edit-global-row input, .edit-global-row textarea { padding: 0.4rem 0.5rem; font-size: 0.8rem; border: 1px solid #d1d5db; border-radius: 8px; outline: none; }
.edit-global-row input:focus, .edit-global-row textarea:focus { border-color: #8b5cf6; box-shadow: 0 0 0 2px rgba(139,92,246,0.15); }
.edit-info-banner { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 0.6rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.8rem; }
.edit-success-banner { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 0.6rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600; }
</style>

<!-- ── Edit Order Modal ── -->
<div id="editOrderModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Edit Order #ORD-<?php echo str_pad($order_id, 4, '0', STR_PAD_LEFT); ?></h3>
            <button type="button" class="close-btn" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body" id="editOrderBody">
            <div class="edit-spinner">Loading order data...</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
            <button type="button" class="btn-primary" id="editSaveBtn" onclick="saveOrderEdit()" disabled>Save Changes</button>
        </div>
    </div>
</div>

<script>
var EDIT_CSRF_TOKEN = '<?php echo $csrf_token; ?>';
var EDIT_ORDER_ID = <?php echo $order_id; ?>;
var INVOICE_SHORTLINK = '<?php echo $invoice_shortlink; ?>';
var editOrderData = null;

function openEditModal(orderId) {
    document.getElementById('editOrderModal').style.display = 'flex';
    document.getElementById('editOrderBody').innerHTML = '<div class="edit-spinner">Loading order data...</div>';
    document.getElementById('editSaveBtn').disabled = true;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'order-edit-handler.php?action=load_order_data&order_id=' + orderId, true);
    xhr.onload = function() {
        if (xhr.status !== 200) {
            document.getElementById('editOrderBody').innerHTML = '<div class="edit-error">Failed to load order data (HTTP ' + xhr.status + ').</div>';
            return;
        }
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.success) {
                editOrderData = resp;
                renderEditForm(resp);
                document.getElementById('editSaveBtn').disabled = false;
            } else {
                document.getElementById('editOrderBody').innerHTML = '<div class="edit-error">' + (resp.error || 'Unknown error') + '</div>';
            }
        } catch(e) {
            document.getElementById('editOrderBody').innerHTML = '<div class="edit-error">Invalid server response.</div>';
        }
    };
    xhr.onerror = function() {
        document.getElementById('editOrderBody').innerHTML = '<div class="edit-error">Network error loading order data.</div>';
    };
    xhr.send();
}

function closeEditModal() {
    document.getElementById('editOrderModal').style.display = 'none';
    editOrderData = null;
}

function renderEditForm(data) {
    var order = data.order;
    var items = data.items;
    var isCredit = order.is_credit_order == 1;
    var gstFiled = (order.gst_status || 'draft') === 'filed';
    var html = '';

    if (gstFiled) {
        html += '<div class="edit-error">This order is included in a filed GST return. Amend the return before editing.</div>';
    }

    if (isCredit) {
        html += '<div class="edit-info-banner">Credit order — deposit changes are not supported. Use the ledger to adjust credit/deposit separately.</div>';
    }

    // Items
    html += '<div style="margin-bottom:0.75rem;font-size:0.8rem;font-weight:700;color:#475569;">ORDER ITEMS</div>';

    items.forEach(function(item) {
        var isRental = parseInt(item.is_rental);
        var label = '';
        var badgeClass = '';
        switch(isRental) {
            case 0: label = 'Refill'; badgeClass = 'badge-refill'; break;
            case 1: label = 'Rental'; badgeClass = 'badge-rental'; break;
            case 2: label = 'Sold'; badgeClass = 'badge-under-maintenance'; break;
            case 3: label = 'Product'; badgeClass = 'badge-refill'; break;
            case 4: label = 'Service'; badgeClass = 'badge-service'; break;
        }
        var itemName = item.product_name || item.gas_name || 'Item #' + item.id;
        var sizeInfo = (item.size_capacity && isRental !== 3) ? ' (' + item.size_capacity + ')' : '';

        html += '<div class="edit-item-card" data-item-id="' + item.id + '">';
        html += '<div class="item-title"><span>' + htmlEscape(itemName) + htmlEscape(sizeInfo) + '</span> <span class="item-badge ' + badgeClass + '">' + label + '</span></div>';

        // Common fields: price, GST rate
        html += '<div class="edit-field-row">';
        html += '<label>Selling Price <input type="number" step="0.01" min="0" name="items[' + item.id + '][price_per_unit]" value="' + parseFloat(item.price_per_unit).toFixed(2) + '"></label>';

        // Type-specific fields
        if (isRental === 2) {
            html += '<label>Cylinder Charge <input type="number" step="0.01" min="0" name="items[' + item.id + '][sell_price]" value="' + parseFloat(item.sell_price || 0).toFixed(2) + '"></label>';
        }
        if (isRental === 1) {
            html += '<label>Rent/Day <input type="number" step="0.01" min="0" name="items[' + item.id + '][rent_per_day]" value="' + parseFloat(item.rent_per_day || 0).toFixed(2) + '"></label>';
            html += '<label>Free Days <input type="number" min="0" name="items[' + item.id + '][free_days]" value="' + parseInt(item.free_days || 0) + '" style="width:70px;"></label>';
            html += '<label>Deposit <input type="number" step="0.01" min="0" name="items[' + item.id + '][deposit_amount]" value="' + parseFloat(item.deposit_amount || 0).toFixed(2) + '"></label>';
        }

        html += '<label>GST % <select name="items[' + item.id + '][gst_rate]">';
        [0, 5, 12, 18, 28].forEach(function(r) {
            var sel = parseFloat(item.gst_rate || 0) === r ? ' selected' : '';
            html += '<option value="' + r + '"' + sel + '>' + r + '%</option>';
        });
        html += '</select></label>';
        html += '<span style="font-size:0.7rem;color:#94a3b8;">Qty: ' + parseInt(item.qty) + '</span>';
        html += '</div></div>';
    });

    // Global fields
    html += '<div style="margin:1rem 0 0.5rem;font-size:0.8rem;font-weight:700;color:#475569;">ORDER SETTINGS</div>';

    html += '<div class="edit-global-row">';
    html += '<label>Discount (\u20b9) <input type="number" step="0.01" min="0" id="editDiscount" value="' + parseFloat(order.discount || 0).toFixed(2) + '" style="width:110px;"></label>';
    html += '<label>Total Deposit (\u20b9) <input type="number" step="0.01" min="0" id="editDeposit" value="' + parseFloat(order.deposit_amount || 0).toFixed(2) + '" style="width:120px;"></label>';
    if (isCredit) {
        html += '<span style="font-size:0.75rem;color:#d97706;font-weight:600;">(credit order \u2014 deposit locked)</span>';
    }
    html += '</div>';

    html += '<div class="edit-global-row">';
    html += '<label style="flex:1;min-width:200px;">Notes <textarea id="editNotes" rows="2" style="width:100%;resize:vertical;font-size:0.8rem;">' + htmlEscape(order.notes || '') + '</textarea></label>';
    html += '<label>Vehicle No. <input type="text" id="editVehicle" value="' + htmlEscape(order.vehicle_number || '') + '" style="width:150px;"></label>';
    html += '</div>';

    // Live summary
    html += '<div style="margin-top:0.75rem;padding:0.75rem;background:#f1f5f9;border-radius:10px;font-size:0.8rem;">';
    html += '<div id="editSummary" style="display:flex;gap:1.5rem;flex-wrap:wrap;font-weight:600;">';
    html += '<span>Subtotal: \u20b9<span id="sumSubtotal">' + parseFloat(order.subtotal).toFixed(2) + '</span></span>';
    html += '<span>Tax: \u20b9<span id="sumTax">' + parseFloat(order.tax_amount).toFixed(2) + '</span></span>';
    html += '<span>Grand Total: \u20b9<span id="sumGrand">' + parseFloat(order.grand_total).toFixed(2) + '</span></span>';
    html += '</div></div>';

    document.getElementById('editOrderBody').innerHTML = html;

    // Bind input listeners for live recalculation
    document.querySelectorAll('.edit-item-card input, .edit-item-card select, #editDiscount').forEach(function(el) {
        el.addEventListener('input', recalcSummary);
        el.addEventListener('change', recalcSummary);
    });
}

function recalcSummary() {
    var subtotal = 0;
    var tax = 0;
    document.querySelectorAll('.edit-item-card').forEach(function(card) {
        var price = parseFloat(card.querySelector('input[name*="[price_per_unit]"]').value) || 0;
        var qtySpan = card.querySelector('.edit-field-row span:last-child');
        var qty = qtySpan ? parseInt(qtySpan.textContent.replace('Qty: ', '')) || 1 : 1;
        var gstRate = parseFloat(card.querySelector('select[name*="[gst_rate]"]').value) || 0;
        var taxable = price * qty;
        subtotal += taxable;
        tax += gstRate > 0 ? taxable * gstRate / 100 : 0;
    });
    var discount = parseFloat(document.getElementById('editDiscount').value) || 0;
    var grand = Math.max(0, subtotal + tax - discount);

    document.getElementById('sumSubtotal').textContent = subtotal.toFixed(2);
    document.getElementById('sumTax').textContent = tax.toFixed(2);
    document.getElementById('sumGrand').textContent = grand.toFixed(2);
}

function saveOrderEdit() {
    var btn = document.getElementById('editSaveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    var formData = new FormData();
    formData.append('action', 'save_order_edit');
    formData.append('order_id', EDIT_ORDER_ID);
    formData.append('_csrf_token', EDIT_CSRF_TOKEN);
    formData.append('discount', document.getElementById('editDiscount').value || '0');
    formData.append('deposit_amount', document.getElementById('editDeposit').value || '0');
    formData.append('notes', document.getElementById('editNotes').value || '');
    formData.append('vehicle_number', document.getElementById('editVehicle').value || '');

    document.querySelectorAll('.edit-item-card').forEach(function(card) {
        var itemId = card.getAttribute('data-item-id');
        card.querySelectorAll('input, select').forEach(function(el) {
            var name = el.getAttribute('name');
            if (name) {
                formData.append(name, el.value);
            }
        });
    });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'order-edit-handler.php', true);
    xhr.onload = function() {
        btn.disabled = false;
        btn.textContent = 'Save Changes';
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.success) {
                document.getElementById('editOrderBody').innerHTML = '<div class="edit-success-banner">' + (resp.message || 'Order updated.') + '</div>';
                setTimeout(function() { window.location.reload(); }, 1200);
            } else {
                document.getElementById('editOrderBody').innerHTML = '<div class="edit-error">' + (resp.error || 'Save failed') + '</div><div style="margin-top:0.5rem;"><button class="btn-secondary" onclick="openEditModal(' + EDIT_ORDER_ID + ')">Try Again</button></div>';
            }
        } catch(e) {
            document.getElementById('editOrderBody').innerHTML = '<div class="edit-error">Invalid server response. Check console.</div>';
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.textContent = 'Save Changes';
        document.getElementById('editOrderBody').innerHTML = '<div class="edit-error">Network error. Please try again.</div>';
    };
    xhr.send(formData);
}

function htmlEscape(s) {
    if (!s) return '';
    return s.toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function copyInvoiceLink() {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(INVOICE_SHORTLINK).then(function() {
            var btn = event.target.closest('button');
            var orig = btn.innerHTML;
            btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Copied!';
            setTimeout(function() { btn.innerHTML = orig; }, 2000);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = INVOICE_SHORTLINK;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        alert('Invoice link copied to clipboard!');
    }
}

// Close modal on overlay click
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('editOrderModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
});
</script>

<?php
require_once 'layout_footer.php';
?>
