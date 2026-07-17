<?php
$page_title = "Vendor Invoice";
$active_menu = "vendor_invoices";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once 'db.php';
require_once 'business_helper.php';

$inv_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$business_key = isset($_GET['business']) && array_key_exists($_GET['business'], getBusinesses()) ? $_GET['business'] : getBrandConfig()['business_key'];
$business = getBusiness($business_key);

if ($inv_id <= 0) {
    echo "<script>window.location.href='vendor-invoices.php';</script>";
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT vi.*, v.name AS vendor_name, v.address AS vendor_address, v.mobile AS vendor_phone, v.gst_number AS vendor_gstin,
               dl.lot_number, dl.dispatch_date
        FROM vendor_invoices vi
        JOIN vendors v ON vi.vendor_id = v.id
        LEFT JOIN dispatch_lots dl ON vi.lot_id = dl.id
        WHERE vi.id = ?
    ");
    $stmt->execute([$inv_id]);
    $inv = $stmt->fetch();

    if (!$inv) {
        echo "<script>window.location.href='vendor-invoices.php';</script>";
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT vii.*, g.name AS gas_name
        FROM vendor_invoice_items vii
        JOIN gas_types g ON vii.gas_type_id = g.id
        WHERE vii.invoice_id = ?
        ORDER BY vii.id ASC
    ");
    $stmt->execute([$inv_id]);
    $items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("vendor-invoice.php: " . $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/error-page.php';
    exit;
}

$inv_number = $inv['invoice_number'];
$gst_rate = floatval($inv['gst_rate'] ?? 0);
$subtotal = floatval($inv['subtotal']);
$deduction = floatval($inv['deduction_amount']);
$addition = floatval($inv['addition_amount']);
$net_amount = floatval($inv['net_amount']);
$taxable = floatval($inv['taxable_amount']);
$gst_amt = floatval($inv['gst_amount']);
$cgst = floatval($inv['cgst']);
$sgst = floatval($inv['sgst']);
$grand_total = floatval($inv['grand_total']);
$paid_amount = floatval($inv['paid_amount']);
$balance = floatval($inv['balance']);

$wa_message = "Dear " . $inv['vendor_name'] . ",\n\n";
$wa_message .= "Please find your Purchase Invoice *" . $inv_number . "* dated *" . $inv['invoice_date'] . "*.\n\n";
$wa_message .= "*Refill Amount:* ₹" . number_format($subtotal, 2) . "\n";
if ($gst_rate > 0) {
    $wa_message .= "*GST ($gst_rate%):* ₹" . number_format($gst_amt, 2) . "\n";
}
$wa_message .= "*Grand Total:* ₹" . number_format($grand_total, 2) . "\n";
if ($balance > 0) {
    $wa_message .= "*Balance Due:* ₹" . number_format($balance, 2) . "\n";
} else {
    $wa_message .= "*Status:* Paid\n";
}
$wa_message .= "\nFor support, contact us at: " . $business['phone'] . ".\n*" . $business['label'] . "*";

$mobile_clean = preg_replace('/[^0-9]/', '', $inv['vendor_phone']);
if (substr($mobile_clean, 0, 1) === '0') $mobile_clean = '91' . substr($mobile_clean, 1);
if (strlen($mobile_clean) < 10) $mobile_clean = '';
$whatsapp_url = ($mobile_clean && $gst_rate > 0) ? "https://wa.me/91" . ltrim($mobile_clean, '91') . "?text=" . urlencode($wa_message) : "#";
?>
<div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <a href="vendor-invoices.php?vendor_id=<?= $inv['vendor_id'] ?>" style="text-decoration: none; color: var(--admin-muted); display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Invoices
    </a>
    <div style="display: flex; gap: 1rem;">
        <a href="<?= $whatsapp_url ?>" target="_blank" class="btn-primary" style="background: #25D366; border-radius: 10px; <?= (!$mobile_clean || $gst_rate <= 0) ? 'opacity:0.5;pointer-events:none;' : '' ?>">
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
            <div class="invoice-type">Purchase Invoice</div>
        </div>

        <div class="invoice-body">
            <div class="invoice-meta">
                <div class="meta-left">
                    <span class="meta-label">Invoice No.</span>
                    <span class="meta-value"><?= htmlspecialchars($inv_number) ?></span>
                </div>
                <div class="meta-right">
                    <span class="meta-label">Date</span>
                    <span class="meta-value"><?= date('d-M-Y', strtotime($inv['invoice_date'])) ?></span>
                </div>
            </div>

            <div class="invoice-meta" style="border-top:1px dashed #e2e8f0;border-bottom:none;padding-top:1rem;">
                <div class="meta-left">
                    <span class="meta-label">Vendor Inv. No.</span>
                    <span class="meta-value" style="font-size:0.85rem;"><?= htmlspecialchars($inv['vendor_invoice_number'] ?? '—') ?></span>
                </div>
                <?php if ($inv['due_date']): ?>
                <div class="meta-right">
                    <span class="meta-label">Due Date</span>
                    <span class="meta-value"><?= date('d-M-Y', strtotime($inv['due_date'])) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="partner-info">
                <div class="pi-row">
                    <span class="pi-label">Vendor</span>
                    <span class="pi-value"><?= htmlspecialchars($inv['vendor_name']) ?></span>
                </div>
                <?php if (!empty($inv['vendor_address'])): ?>
                <div class="pi-row">
                    <span class="pi-label">Address</span>
                    <span class="pi-value"><?= htmlspecialchars($inv['vendor_address']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($inv['vendor_gstin'])): ?>
                <div class="pi-row">
                    <span class="pi-label">GSTIN</span>
                    <span class="pi-value"><?= htmlspecialchars($inv['vendor_gstin']) ?></span>
                </div>
                <?php endif; ?>
                <div class="pi-row">
                    <span class="pi-label">Phone</span>
                    <span class="pi-value"><?= htmlspecialchars($inv['vendor_phone'] ?? '—') ?></span>
                </div>
            </div>

            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Gas Type</th>
                        <th>Size</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Rate</th>
                        <th style="text-align:right;">Amount</th>
                        <?php if ($gst_rate > 0): ?>
                        <th style="text-align:right;">Taxable</th>
                        <th style="text-align:right;">CGST</th>
                        <th style="text-align:right;">SGST</th>
                        <th style="text-align:right;">Total</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 0; foreach ($items as $item): $idx++; ?>
                    <tr>
                        <td><?= $idx ?></td>
                        <td><?= htmlspecialchars($item['gas_name']) ?></td>
                        <td><?= htmlspecialchars($item['size_capacity']) ?></td>
                        <td style="text-align:center;"><?= intval($item['quantity']) ?></td>
                        <td style="text-align:right;">₹<?= number_format($item['rate_per_unit'], 2) ?></td>
                        <td style="text-align:right;">₹<?= number_format($item['amount'], 2) ?></td>
                        <?php if ($gst_rate > 0): ?>
                        <td style="text-align:right;">₹<?= number_format($item['taxable_amount'], 2) ?></td>
                        <td style="text-align:right;">₹<?= number_format($item['cgst'], 2) ?></td>
                        <td style="text-align:right;">₹<?= number_format($item['sgst'], 2) ?></td>
                        <td style="text-align:right;">₹<?= number_format($item['total_amount'], 2) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals">
                <div class="total-row">
                    <span>Subtotal</span>
                    <strong>₹<?= number_format($subtotal, 2) ?></strong>
                </div>
                <?php if ($deduction > 0): ?>
                <div class="total-row">
                    <span>Deductions</span>
                    <strong style="color:#dc2626;">− ₹<?= number_format($deduction, 2) ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($addition > 0): ?>
                <div class="total-row">
                    <span>Adjustments (+)</span>
                    <strong style="color:#059669;">+ ₹<?= number_format($addition, 2) ?></strong>
                </div>
                <?php endif; ?>
                <div class="total-row">
                    <span>Net Amount</span>
                    <strong>₹<?= number_format($net_amount, 2) ?></strong>
                </div>
                <?php if ($gst_rate > 0): ?>
                <div class="total-row">
                    <span>Taxable Value</span>
                    <strong>₹<?= number_format($taxable, 2) ?></strong>
                </div>
                <div class="total-row">
                    <span>GST @ <?= number_format($gst_rate, 0) ?>%</span>
                    <strong>₹<?= number_format($gst_amt, 2) ?></strong>
                </div>
                <div class="total-row" style="font-size:0.78rem;color:#64748b;">
                    <span style="padding-left:1rem;">CGST @ <?= number_format($gst_rate/2, 1) ?>%</span>
                    <span>₹<?= number_format($cgst, 2) ?></span>
                </div>
                <div class="total-row" style="font-size:0.78rem;color:#64748b;">
                    <span style="padding-left:1rem;">SGST @ <?= number_format($gst_rate/2, 1) ?>%</span>
                    <span>₹<?= number_format($sgst, 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="total-row grand-total">
                    <span>Grand Total</span>
                    <strong>₹<?= number_format($grand_total, 2) ?></strong>
                </div>
                <div class="total-row">
                    <span>Amount Paid</span>
                    <strong style="color: var(--success);">₹<?= number_format($paid_amount, 2) ?></strong>
                </div>
                <div class="total-row balance-due">
                    <span>Balance Due</span>
                    <strong style="color: <?= $balance > 0 ? 'var(--danger)' : 'var(--success)' ?>;">₹<?= number_format($balance, 2) ?></strong>
                </div>
            </div>

            <?php if (!empty($inv['notes'])): ?>
            <div class="notes-section">
                <strong>Remarks:</strong> <?= htmlspecialchars($inv['notes']) ?>
            </div>
            <?php endif; ?>

            <div class="invoice-footer">
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p>Vendor's Signature</p>
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
                    <div class="invoice-number-sm"><?= htmlspecialchars($inv_number) ?></div>
                </div>
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p>Authorized Signatory</p>
                </div>
            </div>
        </div>

        <div class="invoice-note">
            This is a computer-generated invoice and does not require a physical signature.
            <?php if (!empty($business['invoice_terms'])): ?><br><?= htmlspecialchars($business['invoice_terms']) ?><?php endif; ?>
        </div>
    </div>
</div>

<link rel="stylesheet" href="vendor-invoice.css">
<?php require_once 'layout_footer.php'; ?>
