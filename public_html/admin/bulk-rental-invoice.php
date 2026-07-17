<?php
$page_title = "Bulk Rental Return Receipt";
$active_menu = "customers";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'business_helper.php';
require_once 'inventory-utils.php';
runRefillRentalMigrations($pdo);
?>
<link rel="stylesheet" href="rental-invoice.css">
<style>
.receipt-page { max-width:800px; }
@media print { .no-print { display: none !important; } }
.receipt-table td:last-child { padding-right: 4px; }
.receipt-table th:last-child { padding-right: 4px; }
</style>
<?php

$group_id = trim($_GET['group_id'] ?? '');
if (empty($group_id)) {
    echo "<script>window.location.href='customers.php';</script>";
    exit();
}

try {
    $grp_stmt = $pdo->prepare("SELECT * FROM ledger_groups WHERE id = ? AND group_type = 'rental_return'");
    $grp_stmt->execute([$group_id]);
    $group = $grp_stmt->fetch();
    if (!$group) {
        echo "<script>window.location.href='customers.php';</script>";
        exit();
    }
    $customer_id = intval($group['customer_id']);
    $cust_stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $cust_stmt->execute([$customer_id]);
    $customer = $cust_stmt->fetch();
    if (!$customer) {
        echo "<script>window.location.href='customers.php';</script>";
        exit();
    }

    $returns_stmt = $pdo->prepare("
        SELECT rr.*, cyl.serial_number, cyl.size_capacity, g.name as gas_name,
               cyl.free_days
        FROM rental_returns rr
        JOIN cylinders cyl ON rr.cylinder_id = cyl.id
        JOIN gas_types g ON cyl.gas_type_id = g.id
        WHERE rr.ledger_group_id = ?
        ORDER BY rr.created_at ASC
    ");
    $returns_stmt->execute([$group_id]);
    $returns = $returns_stmt->fetchAll();
    if (empty($returns)) {
        echo "<script>window.location.href='customers.php';</script>";
        exit();
    }

    $payments_stmt = $pdo->prepare("SELECT * FROM payments WHERE ledger_group_id = ? ORDER BY id ASC");
    $payments_stmt->execute([$group_id]);
    $payments = $payments_stmt->fetchAll();

    $business = getDefaultBusiness();
    $invoice_number = 'BRR-' . substr($group_id, 0, 8);
    $return_date = $returns[0]['return_date'];
    $payment_method = $returns[0]['payment_method'];

    $total_rent = 0; $total_damage = 0; $total_deducted = 0; $total_collected = 0;
    foreach ($returns as $r) {
        $total_rent += floatval($r['rent_amount']);
        $total_damage += floatval($r['damage_charge']);
        $total_deducted += floatval($r['deposit_deducted']);
        $total_collected += floatval($r['total_collected']);
    }

} catch (PDOException $e) {
    error_log("bulk-rental-invoice.php: " . $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/error-page.php';
    exit;
}

$wa_message = "Dear " . $customer['name'] . ",\n\n";
$wa_message .= "Thank you for returning cylinders to *" . $business['label'] . "*!\n";
$wa_message .= "Your Rental Return Receipt *" . $invoice_number . "* has been processed.\n\n";
$wa_message .= "*Summary:*\n";
foreach ($returns as $r) {
    $wa_message .= "- " . $r['serial_number'] . " (" . $r['gas_name'] . "): " . $r['chargeable_days'] . " days @ ₹" . number_format($r['daily_rate'], 2) . " = ₹" . number_format($r['rent_amount'], 2) . "\n";
}
$wa_message .= "\n*Totals:*\n";
$wa_message .= "- Total Rent: ₹" . number_format($total_rent, 2) . "\n";
if ($total_damage > 0) $wa_message .= "- Damage Charges: ₹" . number_format($total_damage, 2) . "\n";
if ($total_deducted > 0) $wa_message .= "- Deposit Deducted: ₹" . number_format($total_deducted, 2) . "\n";
$wa_message .= "- Amount Collected: ₹" . number_format($total_collected, 2) . "\n";
$wa_message .= "\nPlease keep this receipt for your records.\n\nFor support, contact us at: " . $business['phone'] . ".\n*" . $business['label'] . "*";

$whatsapp_url = "https://wa.me/91" . $customer['mobile'] . "?text=" . urlencode($wa_message);

function render_bulk_rental_receipt($returns, $payments, $customer, $business, $group, $invoice_number, $return_date, $payment_method, $total_rent, $total_damage, $total_deducted, $total_collected, $copy_label, $copy_color, $signee_label) {
?>
<div class="receipt-page">
    <div class="receipt">
        <div class="receipt-header">
            <?php if (!empty($business['logo_path'])): ?>
                <img src="<?= htmlspecialchars($business['logo_path']) ?>" alt="<?= htmlspecialchars($business['label']) ?>" style="max-height:52px;margin-bottom:0.5rem;">
            <?php endif; ?>
            <div class="company-name"><?= htmlspecialchars($business['label'] ?? 'Prem Gas Solution') ?></div>
            <div class="company-info">
                <?= htmlspecialchars($business['address'] ?? '') ?><br>
                GST: <?= htmlspecialchars($business['gstin'] ?? '—') ?> &nbsp;|&nbsp; Phone: <?= htmlspecialchars($business['phone'] ?? '') ?>
            </div>
            <div class="receipt-type" style="background:<?= $copy_color ?>;"><?= $copy_label ?></div>
        </div>

        <div class="receipt-body">
            <div class="receipt-meta">
                <div class="meta-left">
                    <span class="meta-label">Customer</span>
                    <span class="meta-value"><?= htmlspecialchars($customer['name']) ?></span>
                    <span style="font-size:0.78rem;color:#64748b;line-height:1.5;margin-top:4px;">
                        <?= nl2br(htmlspecialchars($customer['address'] ?? '')) ?><br>
                        Mob: <?= htmlspecialchars($customer['mobile']) ?>
                        <?php if (!empty($customer['gst_number'])): ?><br>GST: <?= htmlspecialchars($customer['gst_number']) ?><?php endif; ?>
                    </span>
                </div>
                <div class="meta-right">
                    <span class="meta-label">Receipt #</span>
                    <span class="meta-value" style="font-family:monospace;"><?= htmlspecialchars($invoice_number) ?></span>
                    <span style="font-size:0.78rem;color:#64748b;margin-top:4px;">
                        Date: <?= htmlspecialchars(date('d-M-Y', strtotime($return_date))) ?><br>
                        Payment: <?= htmlspecialchars($payment_method) ?>
                    </span>
                </div>
            </div>

            <table class="receipt-table">
                <thead>
                    <tr>
                        <th style="width:30px;text-align:center;">#</th>
                        <th style="text-align:left;">Serial</th>
                        <th style="text-align:left;">Gas / Size</th>
                        <th style="text-align:center;">Period</th>
                        <th style="text-align:center;width:42px;">Days</th>
                        <th style="text-align:right;width:70px;">Rate</th>
                        <th style="text-align:right;width:78px;">Rent</th>
                        <th style="text-align:right;width:70px;">Damage</th>
                        <th style="text-align:right;width:72px;">Deduct</th>
                        <th style="text-align:right;width:78px;">Collected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 1; foreach ($returns as $r):
                        $borrow_fmt = date('d-M', strtotime($r['borrow_date']));
                        $return_fmt = date('d-M', strtotime($r['return_date']));
                        $damage = floatval($r['damage_charge']);
                        $deduct = floatval($r['deposit_deducted']);
                        $free = intval($r['free_days']);
                    ?>
                    <tr>
                        <td style="text-align:center;color:#94a3b8;"><?= $idx++ ?></td>
                        <td style="font-family:monospace;font-weight:700;font-size:0.78rem;"><?= htmlspecialchars($r['serial_number']) ?></td>
                        <td><?= htmlspecialchars($r['gas_name'] . ' (' . $r['size_capacity'] . ')') ?></td>
                        <td style="text-align:center;font-size:0.72rem;white-space:nowrap;"><?= $borrow_fmt ?> &rarr; <?= $return_fmt ?></td>
                        <td style="text-align:center;font-weight:700;"><?= intval($r['chargeable_days']) ?></td>
                        <td style="text-align:right;">₹<?= number_format(floatval($r['daily_rate']), 2) ?><?= $free > 0 ? '<br><span style="color:#94a3b8;font-size:0.65rem;">' . $free . ' free</span>' : '' ?></td>
                        <td style="text-align:right;font-weight:600;">₹<?= number_format(floatval($r['rent_amount']), 2) ?></td>
                        <td style="text-align:right;<?= $damage > 0 ? 'color:#dc2626;font-weight:600;' : 'color:#94a3b8;' ?>"><?= $damage > 0 ? '₹' . number_format($damage, 2) : '—' ?></td>
                        <td style="text-align:right;<?= $deduct > 0 ? 'color:#7c3aed;font-weight:600;' : 'color:#94a3b8;' ?>"><?= $deduct > 0 ? '₹' . number_format($deduct, 2) : '—' ?></td>
                        <td style="text-align:right;font-weight:700;">₹<?= number_format(floatval($r['total_collected']), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals">
                <div class="total-row">
                    <span>Total Rent Charges</span>
                    <strong>₹<?= number_format($total_rent, 2) ?></strong>
                </div>
                <?php if ($total_damage > 0): ?>
                <div class="total-row" style="color:#dc2626;">
                    <span>Damage Charges</span>
                    <strong>+ ₹<?= number_format($total_damage, 2) ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($total_deducted > 0): ?>
                <div class="total-row" style="color:#7c3aed;">
                    <span>Deposit Deducted</span>
                    <strong>&minus; ₹<?= number_format($total_deducted, 2) ?></strong>
                </div>
                <?php endif; ?>
                <div class="total-divider"></div>
                <div class="total-row grand-total">
                    <span style="font-weight:800;">Net Amount Collected</span>
                    <strong style="color:#059669;font-size:1.15rem;">₹<?= number_format($total_collected, 2) ?></strong>
                </div>
            </div>

            <?php if (!empty($payments)): ?>
            <div style="margin-top:0.75rem;">
                <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;margin-bottom:6px;">Payment History</div>
                <table class="receipt-table" style="margin-bottom:0;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Description</th>
                            <th style="text-align:center;width:90px;">Type</th>
                            <th style="text-align:right;width:100px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td style="font-size:0.78rem;"><?= htmlspecialchars($p['notes'] ?? str_replace('_', ' ', $p['payment_type'])) ?></td>
                            <td style="text-align:center;"><span style="display:inline-block;padding:2px 8px;background:#f1f5f9;border-radius:10px;font-size:0.65rem;font-weight:600;color:#475569;"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $p['payment_type']))) ?></span></td>
                            <td style="text-align:right;font-weight:600;font-size:0.82rem;">₹<?= number_format(floatval($p['amount']), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="receipt-footer">
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p>Authorized Signatory</p>
                    <p style="font-size:0.68rem;color:#1e293b;font-weight:600;margin-top:2px;"><?= htmlspecialchars($signee_label) ?></p>
                </div>
                <div class="stamp-area" style="text-align:center;flex:0 0 120px;">
                    <div style="font-size:1.6rem;letter-spacing:-0.05em;color:#cbd5e1;">&#9632;&#9632;&#9632;</div>
                </div>
                <div class="signature-area">
                    <div class="signature-line"></div>
                    <p>Customer Signature</p>
                    <p style="font-size:0.68rem;color:#1e293b;font-weight:600;margin-top:2px;"><?= htmlspecialchars($customer['name']) ?></p>
                </div>
            </div>
        </div>

        <div class="receipt-note">
            This is a computer-generated receipt. &nbsp;|&nbsp; Thank you for your business!
        </div>
    </div>
</div>
<?php } // end render function ?>

<?php
render_bulk_rental_receipt($returns, $payments, $customer, $business, $group, $invoice_number, $return_date, $payment_method, $total_rent, $total_damage, $total_deducted, $total_collected, 'Admin Copy', '#2563eb', 'Admin');
render_bulk_rental_receipt($returns, $payments, $customer, $business, $group, $invoice_number, $return_date, $payment_method, $total_rent, $total_damage, $total_deducted, $total_collected, 'Customer Copy', '#059669', $customer['name']);
?>

<div class="no-print" style="max-width:800px;margin:0 auto 1.25rem;display:flex;gap:0.75rem;flex-wrap:wrap;justify-content:center;">
    <button onclick="window.print()" class="btn-primary" style="padding:0.6rem 1.25rem;border-radius:10px;display:inline-flex;align-items:center;gap:0.5rem;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
    </button>
    <a href="<?= htmlspecialchars($whatsapp_url) ?>" target="_blank" class="btn-primary" style="padding:0.6rem 1.25rem;border-radius:10px;display:inline-flex;align-items:center;gap:0.5rem;background:#25D366;color:#fff;text-decoration:none;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.52 3.48A11.93 11.93 0 0 0 12 0a11.93 11.93 0 0 0-8.52 3.48A11.93 11.93 0 0 0 0 12a11.93 11.93 0 0 0 1.23 5.25L0 24l6.85-1.23A11.93 11.93 0 0 0 12 24a11.93 11.93 0 0 0 8.52-3.48A11.93 11.93 0 0 0 24 12a11.93 11.93 0 0 0-3.48-8.52zM12 21.6c-2.28 0-4.5-.72-6.33-2.04l-.45-.3-4.08.87.87-4.08-.3-.45c-1.32-1.83-2.04-4.05-2.04-6.33A9.6 9.6 0 0 1 12 2.4a9.6 9.6 0 0 1 9.6 9.6 9.6 9.6 0 0 1-9.6 9.6zm5.22-7.14c-.3-.15-1.74-.87-2.01-.96-.27-.09-.48-.15-.69.15-.21.3-.81.96-.99 1.17-.18.21-.36.24-.66.09-.3-.15-1.26-.48-2.4-1.53-.87-.81-1.44-1.77-1.62-2.07-.18-.3-.03-.45.15-.6.15-.15.3-.36.45-.54.15-.18.18-.3.27-.45.09-.15.05-.3-.03-.45-.09-.15-.69-1.68-.96-2.31-.27-.63-.54-.54-.69-.54H8.49c-.17 0-.45.06-.69.36-.24.3-.84.84-.84 2.04s.87 2.37.99 2.55c.12.18 1.71 2.7 4.2 3.69 2.49.99 2.49.66 2.94.63.45-.03 1.44-.6 1.65-1.17.21-.57.21-1.08.15-1.17-.06-.09-.18-.15-.39-.27z"/></svg>
        Share on WhatsApp
    </a>
    <a href="customers.php" class="btn-secondary" style="padding:0.6rem 1.25rem;border-radius:10px;display:inline-flex;align-items:center;gap:0.5rem;text-decoration:none;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5m7 7l-7-7 7-7"/></svg>
        Back to Customers
    </a>
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
