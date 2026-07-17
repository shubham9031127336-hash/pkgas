<?php
$active_menu = "customers";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'business_helper.php';
require_once 'inventory-utils.php';
runRefillRentalMigrations($pdo);
$page_title = __('invoice.rental_title');

$return_id = isset($_GET['return_id']) ? intval($_GET['return_id']) : 0;

if ($return_id <= 0) {
    echo "<script>window.location.href='customers.php';</script>";
    exit();
}

// Fetch rental return record
try {
    $stmt = $pdo->prepare("
        SELECT rr.*, c.name as customer_name, c.mobile as customer_mobile,
               c.address as customer_address, c.gst_number as customer_gst,
               cyl.serial_number, cyl.size_capacity, g.name as gas_name,
               rr.borrow_date, rr.return_date, rr.chargeable_days, rr.daily_rate,
               rr.rent_amount, rr.damage_charge, rr.damage_description,
               rr.deposit_deducted, rr.total_collected, rr.payment_method, rr.notes
        FROM rental_returns rr
        JOIN customers c ON rr.customer_id = c.id
        JOIN cylinders cyl ON rr.cylinder_id = cyl.id
        JOIN gas_types g ON cyl.gas_type_id = g.id
        WHERE rr.id = ?
    ");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch();

    if (!$return) {
        echo "<script>window.location.href='customers.php';</script>";
        exit();
    }

    $business = getDefaultBusiness();
    $invoice_number = 'RRT-' . str_pad($return_id, 5, '0', STR_PAD_LEFT);
    $free_days = 0;

    // Get free_days from cylinder
    $stmt2 = $pdo->prepare("SELECT free_days FROM cylinders WHERE id = ?");
    $stmt2->execute([$return['cylinder_id']]);
    $cyl = $stmt2->fetch();
    $free_days = intval($cyl['free_days'] ?? 0);

} catch (PDOException $e) {
    error_log("rental-invoice.php: " . $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/error-page.php';
    exit;
}

$wa_message = "Dear " . $return['customer_name'] . ",\n\n";
$wa_message .= "Thank you for returning the cylinder to *" . $business['label'] . "*!\n";
$wa_message .= "Your Rental Return Invoice *" . $invoice_number . "* has been processed.\n\n";
$wa_message .= "*Summary:*\n";
$wa_message .= "- Cylinder: " . $return['serial_number'] . " (" . $return['gas_name'] . ")\n";
$wa_message .= "- Rental Period: " . $return['borrow_date'] . " to " . $return['return_date'] . "\n";
$wa_message .= "- Days Held: " . $return['chargeable_days'] . " (chargeable)\n";
$wa_message .= "- Rent per day: ₹" . number_format($return['daily_rate'], 2) . "\n";
$wa_message .= "- Rent Subtotal: ₹" . number_format($return['rent_amount'], 2) . "\n";
if (floatval($return['damage_charge']) > 0) {
    $wa_message .= "- Damage Charges: ₹" . number_format($return['damage_charge'], 2) . "\n";
}
if (floatval($return['deposit_deducted']) > 0) {
    $wa_message .= "- Deposit Deducted: ₹" . number_format($return['deposit_deducted'], 2) . "\n";
}
$wa_message .= "- Amount Collected: ₹" . number_format($return['total_collected'], 2) . "\n";
$wa_message .= "\nPlease keep this receipt for your records.\n\nFor support, contact us at: " . $business['phone'] . ".\n*" . $business['label'] . "*";

$whatsapp_url = "https://wa.me/91" . $return['customer_mobile'] . "?text=" . urlencode($wa_message);

function render_rental_receipt($return, $business, $invoice_number, $free_days, $copy_label, $copy_color, $signee_label) {
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
                    <span class="meta-label"><?php echo __('invoice.rental_return_no'); ?></span>
                    <span class="meta-value"><?php echo htmlspecialchars($invoice_number); ?></span>
                </div>
                <div class="meta-right">
                    <span class="meta-label"><?php echo __('customer.return_date'); ?></span>
                    <span class="meta-value"><?php echo date('d-M-Y', strtotime($return['return_date'])); ?></span>
                </div>
            </div>

            <div class="customer-info">
                <div class="ci-row">
                    <span class="ci-label"><?php echo __('common.customer'); ?></span>
                    <span class="ci-value"><?php echo htmlspecialchars($return['customer_name']); ?></span>
                </div>
                <div class="ci-row">
                    <span class="ci-label"><?php echo __('common.mobile'); ?></span>
                    <span class="ci-value"><?php echo htmlspecialchars($return['customer_mobile']); ?></span>
                </div>
                <?php if ($return['customer_address']): ?>
                <div class="ci-row">
                    <span class="ci-label"><?php echo __('common.address'); ?></span>
                    <span class="ci-value"><?php echo htmlspecialchars($return['customer_address']); ?></span>
                </div>
                <?php endif; ?>
                <div class="ci-row">
                    <span class="ci-label">GSTIN</span>
                    <span class="ci-value"><?php echo htmlspecialchars($return['customer_gst'] ?: 'Consumer'); ?></span>
                </div>
            </div>

            <table class="receipt-table">
                <thead>
                    <tr>
                        <th><?php echo __('common.cylinder'); ?></th>
                        <th><?php echo __('common.size'); ?></th>
                        <th><?php echo __('customer.rental_period'); ?></th>
                        <th style="text-align: right;"><?php echo __('invoice.chargeable_days'); ?></th>
                        <th style="text-align: right;"><?php echo __('customer.rent_subtotal'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($return['serial_number']); ?></strong>
                            <div style="font-size: 0.72rem; margin-top: 4px; color: var(--admin-muted);">
                                <?php echo htmlspecialchars($return['gas_name']); ?>
                            </div>
                        </td>
                        <td style="vertical-align: middle;"><?php echo htmlspecialchars($return['size_capacity']); ?></td>
                        <td style="vertical-align: middle;">
                            <?php echo date('d-M', strtotime($return['borrow_date'])); ?> —
                            <?php echo date('d-M-Y', strtotime($return['return_date'])); ?>
                        </td>
                        <td style="text-align: right; vertical-align: middle; font-weight: 700;">
                            <?php echo $return['chargeable_days']; ?>
                        </td>
                        <td style="text-align: right; vertical-align: middle; font-weight: 700;">
                            ₹<?php echo number_format($return['rent_amount'], 2); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;padding:0.5rem 0;font-size:0.8rem;color:var(--admin-muted);border-bottom:1px dashed var(--admin-border);margin-bottom:0.75rem;">
                <div><?php echo __('invoice.days_held'); ?>: <strong><?php echo $return['chargeable_days'] + $free_days; ?></strong></div>
                <div><?php echo __('invoice.free_days'); ?>: <strong><?php echo $free_days; ?></strong></div>
                <div><?php echo __('customer.rent_per_day'); ?>: <strong>₹<?php echo number_format($return['daily_rate'], 2); ?></strong></div>
                <div><?php echo __('customer.return_date'); ?>: <strong><?php echo date('d-M-Y', strtotime($return['return_date'])); ?></strong></div>
            </div>

            <div class="totals">
                <div class="total-row">
                    <span><?php echo __('invoice.rent_charges'); ?></span>
                    <strong>₹<?php echo number_format($return['rent_amount'], 2); ?></strong>
                </div>
                <?php if (floatval($return['damage_charge']) > 0): ?>
                <div class="total-row">
                    <span><?php echo __('invoice.damage_charges'); ?></span>
                    <strong style="color:#dc2626;">₹<?php echo number_format($return['damage_charge'], 2); ?></strong>
                </div>
                    <?php if ($return['damage_description']): ?>
                    <div style="font-size:0.75rem;color:var(--admin-muted);margin-top:-0.25rem;margin-bottom:0.25rem;text-align:right;">
                        <?php echo htmlspecialchars($return['damage_description']); ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (floatval($return['deposit_deducted']) > 0): ?>
                <div class="total-row" style="color:#2563eb;">
                    <span><?php echo __('invoice.deposit_deducted'); ?></span>
                    <strong>-₹<?php echo number_format($return['deposit_deducted'], 2); ?></strong>
                </div>
                <?php endif; ?>
                <div class="total-divider"></div>
                <div class="total-row grand-total">
                    <span><?php echo __('invoice.total_collected'); ?></span>
                    <strong>₹<?php echo number_format($return['total_collected'], 2); ?></strong>
                </div>
                <div class="total-row">
                    <span><?php echo __('common.payment_method'); ?></span>
                    <strong><?php echo htmlspecialchars($return['payment_method'] ?: '—'); ?></strong>
                </div>
            </div>

            <?php if ($return['notes']): ?>
            <div style="margin-top:0.75rem;padding:0.5rem 0.75rem;background:#f8fafc;border-radius:8px;font-size:0.8rem;color:var(--admin-muted);">
                <strong style="font-weight:600;"><?php echo __('common.notes'); ?>:</strong> <?php echo htmlspecialchars($return['notes']); ?>
            </div>
            <?php endif; ?>

            <div class="receipt-footer">
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p><?php echo __('common.customer_sign'); ?></p>
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
                    <div class="receipt-number"><?php echo htmlspecialchars($invoice_number); ?></div>
                </div>
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p><?php echo $signee_label; ?></p>
                </div>
            </div>
        </div>

        <div class="receipt-note">
            <?php echo __('invoice.computer_generated'); ?>
        </div>
    </div>
<?php
}
?>

<div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <a href="customer-profile.php?id=<?php echo $return['customer_id']; ?>" style="text-decoration: none; color: var(--admin-muted); display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        <?php echo __('common.back_to_profile'); ?>
    </a>
    <div style="display: flex; gap: 1rem;">
        <a href="<?php echo $whatsapp_url; ?>" target="_blank" class="btn-primary" style="background: #25D366; border-radius: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.022-.08-.124-.22-.364-.34-.24-.12-1.418-.7-1.638-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-.992-.367-1.89-1.167-.698-.622-1.17-1.39-1.305-1.63-.137-.24-.015-.37.107-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.195-.47-.393-.407-.54-.415-.143-.007-.307-.007-.47-.007s-.43.06-.653.3c-.22.24-.848.83-.848 2.03s.87 2.36.99 2.53c.12.17 1.71 2.612 4.14 3.66.578.25 1.03.398 1.38.51.58.185 1.11.16 1.52.1.46-.07 1.418-.58 1.618-1.14.2-.56.2-1.04.14-1.14-.06-.1-.2-.16-.44-.28zM12 2C6.48 2 2 6.48 2 12c0 2.17.7 4.2 1.94 5.86L3 21l3.28-.96C7.8 21.3 9.8 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.93 0-3.73-.52-5.28-1.42l-.38-.22-1.95.57.58-1.9-.26-.41C3.8 15.13 3.25 13.11 3.25 11c0-4.83 3.92-8.75 8.75-8.75s8.75 3.92 8.75 8.75S16.83 20 12 20z"/></svg>
            <?php echo __('common.whatsapp_share'); ?>
        </a>
        <button onclick="window.print()" class="btn-primary" style="border-radius: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            <?php echo __('common.print'); ?>
        </button>
    </div>
</div>

<div class="receipt-page">
    <div class="print-slip">
        <?php render_rental_receipt($return, $business, $invoice_number, $free_days, 'Shop Copy', '#1e293b', 'Authorized Signee'); ?>
        <div class="tear-line"><span>✁ &mdash;&mdash;&mdash; Tear Here &mdash;&mdash;&mdash; ✁</span></div>
    </div>

    <div class="print-slip">
        <?php render_rental_receipt($return, $business, $invoice_number, $free_days, 'Consumer Copy', '#059669', 'Delivery Person Sign'); ?>
    </div>
</div>

<link rel="stylesheet" href="rental-invoice.css">

<?php require_once 'layout_footer.php'; ?>
