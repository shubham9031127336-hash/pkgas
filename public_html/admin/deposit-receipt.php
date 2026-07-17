<?php
$page_title = "Deposit Receipt";
$active_menu = "customers";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'business_helper.php';

$receipt_id = isset($_GET['receipt_id']) ? intval($_GET['receipt_id']) : 0;
$business_key = isset($_GET['business']) && array_key_exists($_GET['business'], getBusinesses()) ? $_GET['business'] : getBrandConfig()['business_key'];
$business = getBusiness($business_key);

if ($receipt_id <= 0) {
    echo "<script>window.location.href='customers.php';</script>";
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT dr.*, p.amount, p.payment_method, p.payment_type, p.notes as payment_notes, p.payment_date,
               c.name as customer_name, c.mobile as customer_mobile, c.address as customer_address, c.gst_number as customer_gst, c.deposit_balance
        FROM deposit_receipts dr
        JOIN payments p ON dr.payment_id = p.id
        JOIN customers c ON dr.customer_id = c.id
        WHERE dr.id = ?
    ");
    $stmt->execute([$receipt_id]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        echo "<script>window.location.href='customers.php';</script>";
        exit();
    }
} catch (PDOException $e) {
    error_log("deposit-receipt.php: " . $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/error-page.php';
    exit;
}

$action_label = $receipt['transaction_label'] ?? (($receipt['payment_type'] === 'deposit_added') ? 'Deposit Added' : 'Deposit Refunded');
$action_color = (strpos($action_label, 'Refund') !== false) ? '#dc2626' : '#059669';

$total_amount = floatval($receipt['total_amount'] ?? $receipt['amount']);
$credit_settled = floatval($receipt['credit_settled'] ?? 0);
$deposit_amount = floatval($receipt['deposit_amount'] ?? $total_amount);
$damage = floatval($receipt['damage_deduction'] ?? 0);
$net_refund = $deposit_amount - $damage;

$receipt_title = (strpos($action_label, 'Refund') !== false) ? 'Payment Refund Receipt' : 'Payment Receipt';
if ($action_label === 'Deposit Added') {
    $receipt_title = 'Deposit Receipt';
}

$wa_message = "Dear " . $receipt['customer_name'] . ",\n\n";
$wa_message .= "Thank you for doing business with *" . $business['label'] . "*!\n";
$wa_message .= "Your " . $action_label . " Receipt *" . $receipt['receipt_number'] . "* has been processed.\n\n";
$wa_message .= "*Details:*\n";
$wa_message .= "- Total Amount: ₹" . number_format($total_amount, 2) . "\n";
if ($credit_settled > 0) {
    $wa_message .= "- Dues Settled: ₹" . number_format($credit_settled, 2) . "\n";
}
$wa_message .= "- Deposit " . ((strpos($action_label, 'Refund') !== false) ? 'Refunded' : 'Added') . ": ₹" . number_format($deposit_amount, 2) . "\n";
if ($damage > 0) {
    $wa_message .= "- Damage Deduction: ₹" . number_format($damage, 2) . "\n";
    $wa_message .= "- Net Refund: ₹" . number_format($net_refund, 2) . "\n";
}
$wa_message .= "- Payment Method: " . $receipt['payment_method'] . "\n";
$wa_message .= "- Updated Deposit Balance: ₹" . number_format($receipt['deposit_balance'], 2) . "\n";
$wa_message .= "\nFor support, contact us at: " . $business['phone'] . ".\n*" . $business['label'] . "*";

$whatsapp_url = "https://wa.me/91" . $receipt['customer_mobile'] . "?text=" . urlencode($wa_message);
?>

<div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <a href="customer-profile.php?id=<?php echo $receipt['customer_id']; ?>" style="text-decoration: none; color: var(--admin-muted); display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Customer Profile
    </a>
    <div style="display: flex; gap: 1rem;">
        <a href="<?php echo $whatsapp_url; ?>" target="_blank" class="btn-primary" style="background: #25D366; border-radius: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.022-.08-.124-.22-.364-.34-.24-.12-1.418-.7-1.638-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-.992-.367-1.89-1.167-.698-.622-1.17-1.39-1.305-1.63-.137-.24-.015-.37.107-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.195-.47-.393-.407-.54-.415-.143-.007-.307-.007-.47-.007s-.43.06-.653.3c-.22.24-.848.83-.848 2.03s.87 2.36.99 2.53c.12.17 1.71 2.612 4.14 3.66.578.25 1.03.398 1.38.51.58.185 1.11.16 1.52.1.46-.07 1.418-.58 1.618-1.14.2-.56.2-1.04.14-1.14-.06-.1-.2-.16-.44-.28zM12 2C6.48 2 2 6.48 2 12c0 2.17.7 4.2 1.94 5.86L3 21l3.28-.96C7.8 21.3 9.8 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.93 0-3.73-.52-5.28-1.42l-.38-.22-1.95.57.58-1.9-.26-.41C3.8 15.13 3.25 13.11 3.25 11c0-4.83 3.92-8.75 8.75-8.75s8.75 3.92 8.75 8.75S16.83 20 12 20z"/></svg>
            Share on WhatsApp
        </a>
        <button onclick="window.print()" class="btn-primary" style="border-radius: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Receipt
        </button>
    </div>
</div>

<div class="receipt-page">

    <div class="receipt">
        <div class="receipt-header">
            <div class="company-name"><?php echo htmlspecialchars($business['name']); ?></div>
            <div class="company-info">
                <?php echo htmlspecialchars($business['tagline']); ?><br>
                <?php echo htmlspecialchars($business['address']); ?><br>
                GSTIN: <?php echo htmlspecialchars($business['gstin']); ?> | Phone: <?php echo htmlspecialchars($business['phone']); ?>
            </div>
            <div class="receipt-type" style="background: <?php echo $action_color; ?>;">
                <?php echo $receipt_title; ?>
            </div>
        </div>

        <div class="receipt-body">
            <div class="receipt-meta">
                <div class="meta-left">
                    <span class="meta-label">Receipt No.</span>
                    <span class="meta-value"><?php echo htmlspecialchars($receipt['receipt_number']); ?></span>
                </div>
                <div class="meta-right">
                    <span class="meta-label">Date</span>
                    <span class="meta-value"><?php echo date('d M Y, h:i A', strtotime($receipt['receipt_date'])); ?></span>
                </div>
            </div>

            <div class="customer-info">
                <div class="ci-row">
                    <span class="ci-label">Customer</span>
                    <span class="ci-value"><?php echo htmlspecialchars($receipt['customer_name']); ?></span>
                </div>
                <div class="ci-row">
                    <span class="ci-label">Mobile</span>
                    <span class="ci-value"><?php echo htmlspecialchars($receipt['customer_mobile']); ?></span>
                </div>
                <div class="ci-row">
                    <span class="ci-label">GSTIN</span>
                    <span class="ci-value"><?php echo htmlspecialchars($receipt['customer_gst'] ?: 'Consumer'); ?></span>
                </div>
            </div>

            <div class="transaction-badge" style="color: <?php echo $action_color; ?>; border-color: <?php echo $action_color; ?>;">
                <?php echo $action_label; ?>
            </div>

            <table class="receipt-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th style="text-align: right;">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($credit_settled > 0): ?>
                    <tr>
                        <td>
                            <strong>Total Payment Received</strong>
                            <?php if (!empty($receipt['payment_notes'])): ?>
                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;"><?php echo htmlspecialchars($receipt['payment_notes']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; font-weight: 800; font-size: 1.1rem; color: var(--admin-accent);">
                            +₹<?php echo number_format($total_amount, 2); ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Dues Settled</strong>
                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">Outstanding credit amount paid</div>
                        </td>
                        <td style="text-align: right; font-weight: 800; font-size: 1rem; color: var(--admin-muted);">
                            -₹<?php echo number_format($credit_settled, 2); ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Deposit Added</strong>
                        </td>
                        <td style="text-align: right; font-weight: 800; font-size: 1.1rem; color: <?php echo $action_color; ?>;">
                            +₹<?php echo number_format($deposit_amount, 2); ?>
                        </td>
                    </tr>
                    <?php elseif ($action_label === 'Deposit Added'): ?>
                    <tr>
                        <td>
                            <strong>Deposit Added</strong>
                            <?php if (!empty($receipt['payment_notes'])): ?>
                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;"><?php echo htmlspecialchars($receipt['payment_notes']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; font-weight: 800; font-size: 1.1rem; color: <?php echo $action_color; ?>;">
                            +₹<?php echo number_format($deposit_amount, 2); ?>
                        </td>
                    </tr>
                    <?php elseif ($action_label === 'Deposit Refunded'): ?>
                    <tr>
                        <td>
                            <strong>Deposit Refunded</strong>
                            <?php if (!empty($receipt['payment_notes'])): ?>
                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;"><?php echo htmlspecialchars($receipt['payment_notes']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; font-weight: 800; font-size: 1.1rem; color: <?php echo $action_color; ?>;">
                            -₹<?php echo number_format($deposit_amount, 2); ?>
                        </td>
                    </tr>
                    <?php if ($damage > 0): ?>
                    <tr>
                        <td>
                            <strong>Damage Deduction</strong>
                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">Cylinder damage charges deducted from deposit</div>
                        </td>
                        <td style="text-align: right; font-weight: 800; font-size: 1rem; color: #dc2626;">
                            -₹<?php echo number_format($damage, 2); ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Net Refund</strong>
                        </td>
                        <td style="text-align: right; font-weight: 800; font-size: 1.1rem; color: <?php echo $action_color; ?>;">
                            -₹<?php echo number_format($net_refund, 2); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="totals">
                <div class="total-row">
                    <span>Payment Method</span>
                    <strong><?php echo htmlspecialchars($receipt['payment_method']); ?></strong>
                </div>
                <div class="total-row">
                    <span>Updated Deposit Balance</span>
                    <strong style="color: var(--admin-accent);">₹<?php echo number_format($receipt['deposit_balance'], 2); ?></strong>
                </div>
            </div>

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
                    <div class="receipt-number"><?php echo htmlspecialchars($receipt['receipt_number']); ?></div>
                </div>
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p>Authorized Signee</p>
                </div>
            </div>
        </div>

        <div class="receipt-note">
            This is a computer-generated receipt and does not require a physical signature.
        </div>
    </div>

</div>

<link rel="stylesheet" href="deposit-receipt.css">

<?php
require_once 'layout_footer.php';
?>
