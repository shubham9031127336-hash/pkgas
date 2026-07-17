<?php
require_once __DIR__ . '/business_helper.php';

function buildOrderEmailHtml($order, $items, $customer_name, $token, $business) {
    $base_url = getSiteUrl();
    $logo_rel = !empty($business['logo_white_path']) ? $business['logo_white_path'] : '../Images/logo_white.png';
    $logo_url = $base_url . '/' . preg_replace('#^\.\./#', '', $logo_rel);
    $download_url = $base_url . '/portal/invoice-pdf.php?token=' . urlencode($token);
    $orders_url = $base_url . '/portal/orders.php';

    $status = $order['payment_status'];
    $status_badge_color = $status === 'paid' ? '#10b981' : ($status === 'partial' ? '#f59e0b' : '#ef4444');
    $order_date = date('d M Y, h:i A', strtotime($order['order_date']));
    $inv_no = htmlspecialchars($order['invoice_number'] ?? 'N/A');
    $order_id = $order['id'];

    $subtotal = number_format(floatval($order['subtotal']), 2);
    $tax = number_format(floatval($order['tax_amount']), 2);
    $discount = floatval($order['discount'] ?? 0);
    $discount_fmt = number_format($discount, 2);
    $grand_total = number_format(floatval($order['grand_total']), 2);
    $deposit = floatval($order['deposit_amount'] ?? 0);
    $deposit_fmt = number_format($deposit, 2);

    $biz_name = htmlspecialchars($business['name']);
    $biz_addr = htmlspecialchars($business['address']);
    $biz_gstin = htmlspecialchars($business['gstin']);
    $biz_phone = htmlspecialchars($business['phone']);

    $items_html = '';
    foreach ($items as $item) {
        $is_product = intval($item['is_rental']) === 3;
        $is_sell = intval($item['is_rental']) === 2;
        if ($is_product) {
            $name = htmlspecialchars($item['product_name'] ?? 'Product');
            $name .= '<br><span style="color:#0369a1;font-size:12px;">Qty: ' . intval($item['qty']) . ' × ₹' . number_format($item['price_per_unit'], 2) . '</span>';
            $size = '—';
        } else {
            $name = htmlspecialchars($item['gas_name']);
            if ($is_sell) {
                $serial = htmlspecialchars($item['sold_cylinder_serial'] ?? '');
                $name .= '<br><span style="color:#dc2626;font-weight:600;font-size:12px;">SOLD Cylinder: ' . $serial . '</span>';
                $sp = floatval($item['sell_price'] ?? 0);
                if ($sp > 0) {
                    $name .= '<br><span style="color:#991b1b;font-weight:600;font-size:12px;">Cylinder Charge: ₹' . number_format($sp, 2) . '</span>';
                }
            } elseif (!empty($item['serial_number'])) {
                $name .= '<br><span style="color:#059669;font-size:12px;">S/N: ' . htmlspecialchars($item['serial_number']) . '</span>';
            }
            $size = htmlspecialchars($item['size_capacity']);
        }
        $qty = intval($item['qty']);
        $line_total = ($item['price_per_unit'] * $qty) + ($is_sell ? floatval($item['sell_price'] ?? 0) : 0);
        $total = number_format($line_total, 2);
        $items_html .= '
        <tr>
            <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;">' . $name . '</td>
            <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#64748b;text-align:center;">' . $size . '</td>
            <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#64748b;text-align:center;">' . $qty . '</td>
            <td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#0f172a;text-align:right;font-weight:600;">₹' . $total . '</td>
        </tr>';
    }

    $deposit_html = $deposit > 0 ? '
    <tr>
        <td colspan="3" style="padding:8px 12px;font-size:14px;color:#3b82f6;">Cylinder Security Deposit</td>
        <td style="padding:8px 12px;font-size:14px;color:#3b82f6;text-align:right;font-weight:600;">₹' . $deposit_fmt . '</td>
    </tr>' : '';

    $discount_html = $discount > 0 ? '
    <tr>
        <td colspan="3" style="padding:8px 12px;font-size:14px;color:#10b981;">Discount</td>
        <td style="padding:8px 12px;font-size:14px;color:#10b981;text-align:right;">-₹' . $discount_fmt . '</td>
    </tr>' : '';

    $due_html = $status !== 'paid' ? '
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;margin:20px 0;text-align:center;">
        <p style="margin:0;font-size:14px;color:#dc2626;font-weight:600;">Payment Pending — ₹' . $grand_total . ' due</p>
        <p style="margin:6px 0 0;font-size:13px;color:#64748b;">Please complete the payment at your earliest convenience.</p>
    </div>' : '';

    $status_label = ucfirst($status);

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation</title>
</head>
<body style="margin:0;padding:0;background-color:#f8fafc;font-family:\'Plus Jakarta Sans\',\'Outfit\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;padding:20px 10px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">

                    <tr>
                        <td style="background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:32px 30px;text-align:center;">
                            <img src="' . $logo_url . '" alt="' . htmlspecialchars($business['name']) . '" style="height:40px;width:auto;margin-bottom:10px;">
                            <h1 style="margin:8px 0 0;font-size:22px;color:#ffffff;font-weight:700;letter-spacing:-0.3px;">Order Confirmed!</h1>
                            <p style="margin:4px 0 0;font-size:14px;color:rgba(255,255,255,0.85);">Thank you for your order, ' . htmlspecialchars($customer_name) . '</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 30px;">

                            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;padding:16px 18px;margin-bottom:20px;">
                                <tr>
                                    <td style="font-size:13px;color:#64748b;padding:4px 0;">Order #</td>
                                    <td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">#ORD-' . $order_id . '</td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px;color:#64748b;padding:4px 0;">Invoice #</td>
                                    <td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . $inv_no . '</td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px;color:#64748b;padding:4px 0;">Date</td>
                                    <td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . $order_date . '</td>
                                </tr>
                                <tr>
                                    <td style="font-size:13px;color:#64748b;padding:4px 0;">Status</td>
                                    <td style="text-align:right;padding:4px 0;">
                                        <span style="display:inline-block;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:600;color:#ffffff;background:' . $status_badge_color . ';">' . $status_label . '</span>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <thead>
                                    <tr style="background:#f1f5f9;">
                                        <th style="padding:10px 12px;font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;text-align:left;">Item</th>
                                        <th style="padding:10px 12px;font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;text-align:center;">Size</th>
                                        <th style="padding:10px 12px;font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;text-align:center;">Qty</th>
                                        <th style="padding:10px 12px;font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;text-align:right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ' . $items_html . '
                                </tbody>
                            </table>

                            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:4px;">
                                <tr>
                                    <td style="padding:8px 12px;font-size:14px;color:#64748b;">Subtotal</td>
                                    <td style="padding:8px 12px;font-size:14px;color:#0f172a;text-align:right;">₹' . $subtotal . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 12px;font-size:14px;color:#64748b;">GST (18%)</td>
                                    <td style="padding:8px 12px;font-size:14px;color:#0f172a;text-align:right;">₹' . $tax . '</td>
                                </tr>
                                ' . $discount_html . '
                                ' . $deposit_html . '
                                <tr>
                                    <td style="padding:12px;border-top:2px solid #2563eb;font-size:16px;color:#0f172a;font-weight:700;">Grand Total</td>
                                    <td style="padding:12px;border-top:2px solid #2563eb;font-size:18px;color:#2563eb;text-align:right;font-weight:800;">₹' . $grand_total . '</td>
                                </tr>
                            </table>

                            ' . $due_html . '

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="' . $download_url . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-size:16px;font-weight:600;letter-spacing:0.3px;">📄 Download Invoice PDF</a>
                                    </td>
                                </tr>
                            </table>

                            <div style="height:1px;background:#e2e8f0;margin:20px 0;"></div>

                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="padding:4px 0;">
                                        <a href="' . $orders_url . '" style="color:#2563eb;font-size:14px;text-decoration:none;font-weight:500;">View All Orders in Portal →</a>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>

                    <tr>
                        <td style="background:#0f172a;padding:24px 30px;text-align:center;">
                            <p style="margin:0 0 4px;font-size:14px;color:#ffffff;font-weight:600;">' . $biz_name . '</p>
                            <p style="margin:0 0 2px;font-size:12px;color:#94a3b8;">' . $biz_addr . '</p>
                            <p style="margin:0 0 2px;font-size:12px;color:#94a3b8;">GSTIN: ' . $biz_gstin . ' | Phone: ' . $biz_phone . '</p>
                            <p style="margin:8px 0 0;font-size:11px;color:#64748b;">This is an automated email. Please do not reply.</p>
                        </td>
                    </tr>

                </table>
                <p style="margin:12px 0 0;font-size:11px;color:#94a3b8;text-align:center;">© ' . date('Y') . ' ' . htmlspecialchars($business['name']) . '. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>';

    return $html;
}

function emailShell($title, $heading, $intro, $content_html, $business, $extra_link = '') {
    $logo_rel = !empty($business['logo_white_path']) ? $business['logo_white_path'] : '../Images/logo_white.png';
    $logo_url = getSiteUrl(ltrim($logo_rel, '../'));
    $biz_name = htmlspecialchars($business['name']);
    $biz_addr = htmlspecialchars($business['address']);
    $biz_phone = htmlspecialchars($business['phone']);
    $biz_gstin = htmlspecialchars($business['gstin']);

    return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $title . '</title></head>
<body style="margin:0;padding:0;background-color:#f8fafc;font-family:\'Plus Jakarta Sans\',\'Outfit\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;padding:20px 10px;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
<tr><td style="background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:32px 30px;text-align:center;">
<img src="' . $logo_url . '" alt="' . htmlspecialchars($business['name']) . '" style="height:40px;width:auto;margin-bottom:10px;">
<h1 style="margin:8px 0 0;font-size:22px;color:#ffffff;font-weight:700;letter-spacing:-0.3px;">' . $heading . '</h1>
<p style="margin:4px 0 0;font-size:14px;color:rgba(255,255,255,0.85);">' . $intro . '</p>
</td></tr>
<tr><td style="padding:28px 30px;">' . $content_html . '
<div style="height:1px;background:#e2e8f0;margin:20px 0;"></div>' . $extra_link . '
</td></tr>
<tr><td style="background:#0f172a;padding:24px 30px;text-align:center;">
<p style="margin:0 0 4px;font-size:14px;color:#ffffff;font-weight:600;">' . $biz_name . '</p>
<p style="margin:0 0 2px;font-size:12px;color:#94a3b8;">' . $biz_addr . '</p>
<p style="margin:0 0 2px;font-size:12px;color:#94a3b8;">GSTIN: ' . $biz_gstin . ' | Phone: ' . $biz_phone . '</p>
<p style="margin:8px 0 0;font-size:11px;color:#64748b;">This is an automated email. Please do not reply.</p>
</td></tr>
</table>
<p style="margin:12px 0 0;font-size:11px;color:#94a3b8;text-align:center;">© ' . date('Y') . ' ' . htmlspecialchars($business['name']) . '. All rights reserved.</p>
</td></tr></table>
</body>
</html>';
}

function buildDepositEmailHtml($customer_name, $amount, $new_balance, $payment_method, $business, $extra_note = '') {
    $amount_fmt = number_format(floatval($amount), 2);
    $bal_fmt = number_format(floatval($new_balance), 2);
    $now = date('d M Y, h:i A');

    $content = '
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border-radius:10px;padding:20px 18px;margin-bottom:20px;border:1px solid #bbf7d0;">
<tr><td style="text-align:center;">
<div style="font-size:36px;margin-bottom:8px;">✅</div>
<h2 style="margin:0 0 4px;font-size:20px;color:#166534;font-weight:700;">Deposit of ₹' . $amount_fmt . ' Added</h2>
<p style="margin:0;font-size:14px;color:#15803d;">' . $extra_note . '</p>
</td></tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;padding:16px 18px;">
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Amount Added</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">₹' . $amount_fmt . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Payment Method</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . htmlspecialchars($payment_method) . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">New Deposit Balance</td>
<td style="font-size:14px;color:#2563eb;font-weight:700;text-align:right;padding:4px 0;">₹' . $bal_fmt . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Date</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . $now . '</td></tr>
</table>';

    return emailShell('Deposit Added - ' . htmlspecialchars($business['name']), 'Deposit Added!', 'Dear ' . htmlspecialchars($customer_name) . ', your security deposit has been updated.', $content, $business);
}

function buildRefundEmailHtml($customer_name, $refund_amount, $damage_amount, $net_refund, $new_balance, $payment_method, $business) {
    $refund_fmt = number_format(floatval($refund_amount), 2);
    $damage_fmt = number_format(floatval($damage_amount), 2);
    $net_fmt = number_format(floatval($net_refund), 2);
    $bal_fmt = number_format(floatval($new_balance), 2);
    $now = date('d M Y, h:i A');

    $damage_html = $damage_amount > 0 ? '
<tr><td style="font-size:13px;color:#dc2626;padding:4px 0;">Damage Deduction</td>
<td style="font-size:14px;color:#dc2626;text-align:right;padding:4px 0;">-₹' . $damage_fmt . '</td></tr>' : '';

    $content = '
<table width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border-radius:10px;padding:20px 18px;margin-bottom:20px;border:1px solid #fecaca;">
<tr><td style="text-align:center;">
<div style="font-size:36px;margin-bottom:8px;">↩️</div>
<h2 style="margin:0 0 4px;font-size:20px;color:#991b1b;font-weight:700;">Deposit Refund Processed</h2>
<p style="margin:0;font-size:14px;color:#b91c1c;">Net refund: ₹' . $net_fmt . '</p>
</td></tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;padding:16px 18px;">
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Refund Amount</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">₹' . $refund_fmt . '</td></tr>
' . $damage_html . '
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Payment Method</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . htmlspecialchars($payment_method) . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Net Refund</td>
<td style="font-size:14px;color:#059669;font-weight:700;text-align:right;padding:4px 0;">₹' . $net_fmt . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Remaining Balance</td>
<td style="font-size:14px;color:#2563eb;font-weight:700;text-align:right;padding:4px 0;">₹' . $bal_fmt . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Date</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . $now . '</td></tr>
</table>';

    return emailShell('Deposit Refund - ' . htmlspecialchars($business['name']), 'Deposit Refunded', 'Dear ' . htmlspecialchars($customer_name) . ', a deposit refund has been processed for your account.', $content, $business);
}

function buildPaymentReceivedEmailHtml($customer_name, $amount, $credit_settled, $deposit_added, $new_credit, $payment_method, $business) {
    $amount_fmt = number_format(floatval($amount), 2);
    $credit_fmt = number_format(floatval($credit_settled), 2);
    $deposit_fmt = number_format(floatval($deposit_added), 2);
    $new_credit_fmt = number_format(floatval($new_credit), 2);
    $now = date('d M Y, h:i A');

    $credit_html = $credit_settled > 0 ? '
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Credit Settled</td>
<td style="font-size:14px;color:#10b981;font-weight:600;text-align:right;padding:4px 0;">₹' . $credit_fmt . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Remaining Credit</td>
<td style="font-size:14px;color:#f59e0b;font-weight:600;text-align:right;padding:4px 0;">₹' . $new_credit_fmt . '</td></tr>' : '';

    $deposit_html = $deposit_added > 0 ? '
<tr><td style="font-size:13px;color:#2563eb;padding:4px 0;">Added to Deposit</td>
<td style="font-size:14px;color:#2563eb;font-weight:600;text-align:right;padding:4px 0;">₹' . $deposit_fmt . '</td></tr>' : '';

    $content = '
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border-radius:10px;padding:20px 18px;margin-bottom:20px;border:1px solid #bbf7d0;">
<tr><td style="text-align:center;">
<div style="font-size:36px;margin-bottom:8px;">💰</div>
<h2 style="margin:0 0 4px;font-size:20px;color:#166534;font-weight:700;">Payment of ₹' . $amount_fmt . ' Received</h2>
<p style="margin:0;font-size:14px;color:#15803d;">via ' . htmlspecialchars($payment_method) . '</p>
</td></tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;padding:16px 18px;">
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Total Amount</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">₹' . $amount_fmt . '</td></tr>
' . $credit_html . '
' . $deposit_html . '
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Payment Method</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . htmlspecialchars($payment_method) . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Date</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . $now . '</td></tr>
</table>';

    $portal_link = '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:4px 0;"><a href="' . getSiteUrl('portal/') . '" style="color:#2563eb;font-size:14px;text-decoration:none;font-weight:500;">View Account in Portal →</a></td></tr></table>';

    return emailShell('Payment Received - ' . htmlspecialchars($business['name']), 'Payment Received!', 'Dear ' . htmlspecialchars($customer_name) . ', a payment has been recorded on your account.', $content, $business, $portal_link);
}

function buildRentalSettlementEmailHtml($customer_name, $cylinder_serial, $gas_name, $size, $rent_amount, $damage_amount, $deposit_deducted, $total_collected, $payment_method, $business) {
    $rent_fmt = number_format(floatval($rent_amount), 2);
    $damage_fmt = number_format(floatval($damage_amount), 2);
    $deposit_fmt = number_format(floatval($deposit_deducted), 2);
    $total_fmt = number_format(floatval($total_collected), 2);
    $now = date('d M Y, h:i A');

    $damage_html = $damage_amount > 0 ? '
<tr><td style="font-size:13px;color:#dc2626;padding:4px 0;">Damage Charge</td>
<td style="font-size:14px;color:#dc2626;text-align:right;padding:4px 0;">₹' . $damage_fmt . '</td></tr>' : '';

    $content = '
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;padding:20px 18px;margin-bottom:20px;border:1px solid #e2e8f0;">
<tr><td style="text-align:center;">
<div style="font-size:36px;margin-bottom:8px;">📋</div>
<h2 style="margin:0 0 4px;font-size:20px;color:#0f172a;font-weight:700;">Rental Cylinder Settlement</h2>
<p style="margin:0;font-size:14px;color:#64748b;">Settlement completed for cylinder #' . htmlspecialchars($cylinder_serial) . '</p>
</td></tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;padding:16px 18px;">
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Cylinder</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . htmlspecialchars($gas_name) . ' (' . htmlspecialchars($size) . ')</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Serial #</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . htmlspecialchars($cylinder_serial) . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Rent Charged</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">₹' . $rent_fmt . '</td></tr>
' . $damage_html . '
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Deposit Deducted</td>
<td style="font-size:14px;color:#2563eb;font-weight:600;text-align:right;padding:4px 0;">₹' . $deposit_fmt . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Payment Method</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . htmlspecialchars($payment_method) . '</td></tr>
<tr><td style="padding:12px 0 0;border-top:2px solid #2563eb;font-size:16px;color:#0f172a;font-weight:700;">Total Collected</td>
<td style="padding:12px 0 0;border-top:2px solid #2563eb;font-size:18px;color:#2563eb;text-align:right;font-weight:800;">₹' . $total_fmt . '</td></tr>
<tr><td style="font-size:13px;color:#64748b;padding:4px 0;">Date</td>
<td style="font-size:14px;color:#0f172a;font-weight:600;text-align:right;padding:4px 0;">' . $now . '</td></tr>
</table>';

    $portal_link = '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:4px 0;"><a href="' . getSiteUrl('portal/cylinders.php') . '" style="color:#2563eb;font-size:14px;text-decoration:none;font-weight:500;">View Cylinders in Portal →</a></td></tr></table>';

    return emailShell('Rental Settlement - ' . htmlspecialchars($business['name']), 'Rental Cylinder Settled!', 'Dear ' . htmlspecialchars($customer_name) . ', your rental cylinder settlement has been processed.', $content, $business, $portal_link);
}
