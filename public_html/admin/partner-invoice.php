<?php
$page_title = "Partner Invoice";
$active_menu = "partners";
require_once 'layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk']);
require_once 'db.php';
require_once 'business_helper.php';

$tx_id = isset($_GET['tx_id']) ? intval($_GET['tx_id']) : 0;
$business_key = isset($_GET['business']) && array_key_exists($_GET['business'], getBusinesses()) ? $_GET['business'] : getBrandConfig()['business_key'];
$business = getBusiness($business_key);

if ($tx_id <= 0) {
    echo "<script>window.location.href='partner-transactions.php';</script>";
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT pt.*, p.company_name, p.address AS partner_address, p.mobile AS partner_phone, p.gst_number AS partner_gstin,
               v.name AS vendor_name, v.address AS vendor_address, v.mobile AS vendor_phone, v.gst_number AS vendor_gstin,
               COUNT(pti.id) as cylinder_count,
               COALESCE(SUM(pti.rent_accrued), 0) as total_rent_accrued,
               COALESCE(SUM(pti.rent_paid), 0) as total_rent_paid,
               COALESCE(SUM(pti.damage_amount), 0) as total_damage
        FROM partner_transactions pt
        LEFT JOIN partners p ON pt.partner_id = p.id
        LEFT JOIN vendors v ON pt.vendor_id = v.id
        LEFT JOIN partner_transaction_items pti ON pt.id = pti.transaction_id
        WHERE pt.id = ?
        GROUP BY pt.id
    ");
    $stmt->execute([$tx_id]);
    $tx = $stmt->fetch();

    if (!$tx) {
        echo "<script>window.location.href='partner-transactions.php';</script>";
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT pti.*, g.name AS gas_name
        FROM partner_transaction_items pti
        JOIN gas_types g ON pti.gas_type_id = g.id
        WHERE pti.transaction_id = ?
        ORDER BY pti.id ASC
    ");
    $stmt->execute([$tx_id]);
    $items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("partner-invoice.php: " . $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/error-page.php';
    exit;
}

$tx_number = 'PTX-' . str_pad($tx['id'], 4, '0', STR_PAD_LEFT);
$is_vendor_tx = in_array($tx['transaction_type'], ['borrowed_from_vendor', 'returned_to_vendor']);
$type_labels = [
    'borrowed_from_partner'      => 'Borrowed From Partner',
    'returned_to_partner'        => 'Returned To Partner',
    'lent_to_partner'            => 'Lent To Partner',
    'received_back_from_partner' => 'Received Back From Partner',
    'borrowed_from_vendor'       => 'Borrowed From Vendor',
    'returned_to_vendor'         => 'Returned To Vendor',
];
$type_label = $type_labels[$tx['transaction_type']] ?? $tx['transaction_type'];
$is_financial = in_array($tx['transaction_type'], ['returned_to_partner', 'received_back_from_partner']);

$subtotal   = floatval($tx['total_rent_accrued']);
$damage     = floatval($tx['total_damage']);
$total_paid = floatval($tx['total_rent_paid']);
$grand_total = $subtotal + $damage;
$balance    = $grand_total - $total_paid;

$entity_name = $is_vendor_tx ? ($tx['vendor_name'] ?? 'Vendor') : $tx['company_name'];
$entity_address = $is_vendor_tx ? ($tx['vendor_address'] ?? '') : ($tx['partner_address'] ?? '');
$entity_gstin = $is_vendor_tx ? ($tx['vendor_gstin'] ?? '') : ($tx['partner_gstin'] ?? '');
$entity_phone = $is_vendor_tx ? ($tx['vendor_phone'] ?? '') : ($tx['partner_phone'] ?? '');

$wa_message = "Dear " . $entity_name . ",\n\n";
$wa_message .= "Please find your Partner Transaction Invoice *" . $tx_number . "* dated *" . $tx['transaction_date'] . "*.\n\n";
$wa_message .= "*Type:* " . $type_label . "\n";
$wa_message .= "*Cylinders:* " . $tx['cylinder_count'] . "\n";
if ($is_financial) {
    $wa_message .= "*Rent Accrued:* ₹" . number_format($subtotal, 2) . "\n";
    $wa_message .= "*Damage Charges:* ₹" . number_format($damage, 2) . "\n";
    $wa_message .= "*Amount Paid:* ₹" . number_format($total_paid, 2) . "\n";
    $wa_message .= "*Balance Due:* ₹" . number_format($balance, 2) . "\n";
}
$wa_message .= "\nFor support, contact us at: " . $business['phone'] . ".\n*" . $business['label'] . "*";

$mobile_clean = preg_replace('/[^0-9]/', '', $entity_phone);
if (substr($mobile_clean, 0, 1) === '0') $mobile_clean = '91' . substr($mobile_clean, 1);
if (strlen($mobile_clean) < 10) $mobile_clean = '';
$whatsapp_url = $mobile_clean ? "https://wa.me/91" . ltrim($mobile_clean, '91') . "?text=" . urlencode($wa_message) : "#";
?>

<?php
$back_url = 'partner-transactions.php';
$back_label = 'Back to Transactions';
if (isset($_SESSION['receive_back_redirect']) && !empty($_SESSION['receive_back_redirect'])) {
    $back_url = $_SESSION['receive_back_redirect'];
    $back_label = 'Back to Partner Profile';
    unset($_SESSION['receive_back_redirect']);
} elseif (isset($_GET['from']) && $_GET['from'] === 'profile') {
    $back_url = 'partner-profile.php?id=' . intval($tx['partner_id'] ?? 0);
    $back_label = 'Back to Partner Profile';
}
?>
<div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <a href="<?= htmlspecialchars($back_url) ?>" style="text-decoration: none; color: var(--admin-muted); display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        <?= $back_label ?>
    </a>
    <div style="display: flex; gap: 1rem;">
        <a href="<?= $whatsapp_url ?>" target="_blank" class="btn-primary" style="background: #25D366; border-radius: 10px; <?= !$mobile_clean ? 'opacity:0.5;pointer-events:none;' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.022-.08-.124-.22-.364-.34-.24-.12-1.418-.7-1.638-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-.992-.367-1.89-1.167-.698-.622-1.17-1.39-1.305-1.63-.137-.24-.015-.37.107-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.195-.47-.393-.407-.54-.415-.143-.007-.307-.007-.47-.007s-.43.06-.653.3c-.22.24-.848.83-.848 2.03s.87 2.36.99 2.53c.12.17 1.71 2.612 4.14 3.66.578.25 1.03.398 1.38.51.58.185 1.11.16 1.52.1.46-.07 1.418-.58 1.618-1.14.2-.56.2-1.04.14-1.14-.06-.1-.2-.16-.44-.28zM12 2C6.48 2 2 6.48 2 12c0 2.17.7 4.2 1.94 5.86L3 21l3.28-.96C7.8 21.3 9.8 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.93 0-3.73-.52-5.28-1.42l-.38-.22-1.95.57.58-1.9-.26-.41C3.8 15.13 3.25 13.11 3.25 11c0-4.83 3.92-8.75 8.75-8.75s8.75 3.92 8.75 8.75S16.83 20 12 20z"/></svg>
            Share on WhatsApp
        </a>
        <button onclick="window.print()" class="btn-primary" style="border-radius: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Invoice
        </button>
    </div>
</div>

<div class="invoice-page">
    <div class="invoice">
        <div class="invoice-header">
            <div class="company-name"><?= htmlspecialchars($business['name']) ?></div>
            <div class="company-info">
                <?= htmlspecialchars($business['tagline']) ?><br>
                <?= htmlspecialchars($business['address']) ?><br>
                GSTIN: <?= htmlspecialchars($business['gstin']) ?> | Phone: <?= htmlspecialchars($business['phone']) ?>
            </div>
            <div class="invoice-type">Partner Transaction Invoice</div>
        </div>

        <div class="invoice-body">
            <div class="invoice-meta">
                <div class="meta-left">
                    <span class="meta-label">Invoice No.</span>
                    <span class="meta-value"><?= htmlspecialchars($tx_number) ?></span>
                </div>
                <div class="meta-right">
                    <span class="meta-label">Date</span>
                    <span class="meta-value"><?= date('d-M-Y', strtotime($tx['transaction_date'])) ?></span>
                </div>
            </div>

            <div class="partner-info">
                <div class="pi-row">
                    <span class="pi-label">Partner</span>
                    <span class="pi-value"><?= htmlspecialchars($entity_name) ?></span>
                </div>
                <?php if (!empty($entity_address)): ?>
                <div class="ci-row">
                    <span class="pi-label">Address</span>
                    <span class="pi-value"><?= htmlspecialchars($entity_address) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($entity_gstin)): ?>
                <div class="ci-row">
                    <span class="pi-label">GSTIN</span>
                    <span class="pi-value"><?= htmlspecialchars($entity_gstin) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($tx['partner_gstin'])): ?>
                <div class="pi-row">
                    <span class="pi-label">GSTIN</span>
                    <span class="pi-value"><?= htmlspecialchars($tx['partner_gstin']) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="transaction-badge"><?= htmlspecialchars($type_label) ?></div>

            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Serial Number</th>
                        <th>Gas Type</th>
                        <th>Size</th>
                        <?php if ($is_financial): ?>
                        <th style="text-align:center;">Days</th>
                        <th style="text-align:right;">Rate/Day</th>
                        <th style="text-align:right;">Rent Accrued</th>
                        <th style="text-align:right;">Damage</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 0; foreach ($items as $item): $idx++; ?>
                    <tr>
                        <td><?= $idx ?></td>
                        <td style="font-weight:700;"><?= htmlspecialchars($item['serial_number']) ?></td>
                        <td><?= htmlspecialchars($item['gas_name']) ?></td>
                        <td><?= htmlspecialchars($item['size_capacity']) ?></td>
                        <?php if ($is_financial): ?>
                        <td style="text-align:center;"><?= $item['days_held'] ?: '—' ?></td>
                        <td style="text-align:right;"><?= $item['daily_rent_rate'] > 0 ? '₹' . number_format($item['daily_rent_rate'], 2) : '—' ?></td>
                        <td style="text-align:right;"><?= $item['rent_accrued'] > 0 ? '₹' . number_format($item['rent_accrued'], 2) : '—' ?></td>
                        <td style="text-align:right;"><?= $item['damage_amount'] > 0 ? '₹' . number_format($item['damage_amount'], 2) : '—' ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($is_financial): ?>
            <div class="totals">
                <div class="total-row">
                    <span>Total Cylinders</span>
                    <strong><?= $tx['cylinder_count'] ?></strong>
                </div>
                <div class="total-row">
                    <span>Total Rent Accrued</span>
                    <strong>₹<?= number_format($subtotal, 2) ?></strong>
                </div>
                <div class="total-row">
                    <span>Total Damage Charges</span>
                    <strong>₹<?= number_format($damage, 2) ?></strong>
                </div>
                <div class="total-row grand-total">
                    <span>Grand Total</span>
                    <strong>₹<?= number_format($grand_total, 2) ?></strong>
                </div>
                <div class="total-row">
                    <span>Amount Paid</span>
                    <strong style="color: var(--success);">₹<?= number_format($total_paid, 2) ?></strong>
                </div>
                <div class="total-row balance-due">
                    <span>Balance Due</span>
                    <strong style="color: <?= $balance > 0 ? 'var(--danger)' : 'var(--success)' ?>;">₹<?= number_format($balance, 2) ?></strong>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($tx['notes'])): ?>
            <div class="notes-section">
                <strong>Remarks:</strong> <?= htmlspecialchars($tx['notes']) ?>
            </div>
            <?php endif; ?>

            <div class="invoice-footer">
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p>Partner's Signature</p>
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
                    <div class="invoice-number"><?= htmlspecialchars($tx_number) ?></div>
                </div>
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p>Authorized Signatory</p>
                </div>
            </div>
        </div>

        <div class="invoice-note">
            This is a computer-generated invoice and does not require a physical signature.
        </div>
    </div>
</div>

<link rel="stylesheet" href="partner.css">

<?php require_once 'layout_footer.php'; ?>
