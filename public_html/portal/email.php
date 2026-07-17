<?php
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) { http_response_code(403); die('Forbidden'); }
require_once __DIR__ . '/../admin/mail-config.php';
require_once __DIR__ . '/../admin/email-template.php';
require_once __DIR__ . '/../admin/business_helper.php';

if (!function_exists('sendCustomerEmail')) {
    function sendCustomerEmail($customer_id, $pdo, $subject, $body, $is_html = false, $alt_body = '', $business_key = null) {
        $stmt = $pdo->prepare("SELECT name, email FROM customers WHERE id = ? AND email IS NOT NULL AND TRIM(email) != ''");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if (!$customer) {
            return false;
        }
        try {
            $mail = getMailer($business_key);
            $mail->addAddress($customer['email'], $customer['name']);
            $mail->Subject = $subject;
            if ($is_html) {
                $mail->isHTML(true);
                $mail->Body = $body;
                if ($alt_body) {
                    $mail->AltBody = $alt_body;
                }
            } else {
                $mail->isHTML(false);
                $mail->Body = $body;
            }
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email send failed to {$customer['email']}: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sendOrderConfirmation')) {
    function sendOrderConfirmation($order_id, $customer_id, $pdo) {
        $stmt = $pdo->prepare("
            SELECT o.*, c.name as customer_name
            FROM refill_orders o
            JOIN customers c ON o.customer_id = c.id
            WHERE o.id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        if (!$order) return;

        if (floatval($order['gst_rate'] ?? 0) <= 0) return;

        $items = $pdo->prepare("
            SELECT oi.*, g.name as gas_name, oi.size_capacity, p.name as product_name
            FROM refill_order_items oi
            LEFT JOIN gas_types g ON oi.gas_type_id = g.id
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.refill_order_id = ?
        ");
        $items->execute([$order_id]);
        $item_rows = $items->fetchAll();

        $biz_key = $order['business_name'] ?: getBrandConfig()['business_key'];
        $business = getBusiness($biz_key);

        $token = generateInvoiceToken($order_id);

        $html_body = buildOrderEmailHtml($order, $item_rows, $order['customer_name'], $token, $business);

        $lines = [];
        $lines[] = "Dear " . $order['customer_name'] . ",";
        $lines[] = "";
        $lines[] = "Thank you for your order!";
        $lines[] = "";
        $lines[] = "Order #: " . $order_id;
        $lines[] = "Invoice #: " . ($order['invoice_number'] ?? 'N/A');
        $lines[] = "Date: " . date('d M Y', strtotime($order['order_date']));
        $lines[] = "";
        $lines[] = "Items:";
        foreach ($item_rows as $item) {
            if ($item['is_rental'] == 3) {
                $lines[] = "  - " . ($item['product_name'] ?? 'Product') . " x " . $item['qty'] . " @ Rs." . number_format($item['price_per_unit'], 2);
            } else {
                $lines[] = "  - " . $item['gas_name'] . " (" . $item['size_capacity'] . ") x " . $item['qty'] . " @ Rs." . number_format($item['price_per_unit'], 2);
            }
        }
        $lines[] = "";
        $lines[] = "Grand Total: Rs." . number_format($order['grand_total'], 2);
        $lines[] = "Payment Status: " . ucfirst($order['payment_status']);
        $lines[] = "";
        $lines[] = "Download your invoice: " . getSiteUrl('portal/invoice-pdf.php?token=' . urlencode($token));
        $lines[] = "";
        $lines[] = "View orders: " . getSiteUrl('portal/orders.php');
        $plain_body = implode("\n", $lines);

        sendCustomerEmail($customer_id, $pdo, "Order Confirmation #" . $order_id . " - " . $business['label'], $html_body, true, $plain_body, $biz_key);
    }
}

if (!function_exists('sendPaymentReceipt')) {
    function sendPaymentReceipt($customer_id, $order_id, $amount, $pdo) {
        if ($order_id) {
            $chk = $pdo->prepare("SELECT gst_rate FROM refill_orders WHERE id = ?");
            $chk->execute([$order_id]);
            if (floatval($chk->fetchColumn() ?: 0) <= 0) return;
        }
        $lines = [];
        $lines[] = "A payment has been recorded on your account.";
        $lines[] = "";
        $lines[] = "Amount: Rs." . number_format($amount, 2);
        if ($order_id) {
            $lines[] = "Order #: " . $order_id;
        }
        $lines[] = "Date: " . date('d M Y, h:i A');
        $lines[] = "";
        $lines[] = "Login to your portal for details: " . getSiteUrl('portal/');
        $body = implode("\n", $lines);
        $biz_label = htmlspecialchars(getDefaultBusiness()['label']);
        sendCustomerEmail($customer_id, $pdo, "Payment Receipt - " . $biz_label, $body);
    }
}

if (!function_exists('sendDepositNotification')) {
    function sendDepositNotification($customer_id, $amount, $payment_method, $pdo, $extra_note = '') {
        $stmt = $pdo->prepare("SELECT name, deposit_balance FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if (!$customer || !$customer['name']) return;
        $business = getDefaultBusiness();
        $html = buildDepositEmailHtml($customer['name'], $amount, $customer['deposit_balance'], $payment_method, $business, $extra_note);
        $plain = "Dear {$customer['name']},\n\nA deposit of Rs." . number_format($amount, 2) . " has been added to your account.\nNew balance: Rs." . number_format($customer['deposit_balance'], 2) . "\nDate: " . date('d M Y, h:i A') . "\n\n- " . $business['label'];
        sendCustomerEmail($customer_id, $pdo, "Deposit Added - " . htmlspecialchars($business['label']), $html, true, $plain, getBrandConfig()['business_key']);
    }
}

if (!function_exists('sendRefundNotification')) {
    function sendRefundNotification($customer_id, $refund_amount, $damage_amount, $payment_method, $pdo) {
        $stmt = $pdo->prepare("SELECT name, deposit_balance FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if (!$customer || !$customer['name']) return;
        $damage = floatval($damage_amount);
        $net = floatval($refund_amount) - $damage;
        $business = getDefaultBusiness();
        $html = buildRefundEmailHtml($customer['name'], $refund_amount, $damage, $net, $customer['deposit_balance'], $payment_method, $business);
        $plain = "Dear {$customer['name']},\n\nA deposit refund of Rs." . number_format($refund_amount, 2) . " has been processed.\n" . ($damage > 0 ? "Damage deduction: Rs." . number_format($damage, 2) . "\n" : "") . "Net refund: Rs." . number_format($net, 2) . "\nRemaining balance: Rs." . number_format($customer['deposit_balance'], 2) . "\nDate: " . date('d M Y, h:i A') . "\n\n- " . $business['label'];
        sendCustomerEmail($customer_id, $pdo, "Deposit Refund - " . htmlspecialchars($business['label']), $html, true, $plain, getBrandConfig()['business_key']);
    }
}

if (!function_exists('sendPaymentReceivedNotification')) {
    function sendPaymentReceivedNotification($customer_id, $amount, $payment_method, $pdo, $credit_settled = 0, $deposit_added = 0) {
        $stmt = $pdo->prepare("SELECT name, credit_used FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if (!$customer || !$customer['name']) return;
        $business = getDefaultBusiness();
        $html = buildPaymentReceivedEmailHtml($customer['name'], $amount, $credit_settled, $deposit_added, $customer['credit_used'], $payment_method, $business);
        $plain = "Dear {$customer['name']},\n\nA payment of Rs." . number_format($amount, 2) . " has been received.\nMethod: " . $payment_method . "\nDate: " . date('d M Y, h:i A') . "\n\n- " . $business['label'];
        sendCustomerEmail($customer_id, $pdo, "Payment Received - " . htmlspecialchars($business['label']), $html, true, $plain, getBrandConfig()['business_key']);
    }
}

if (!function_exists('sendRentalSettlementNotification')) {
    function sendRentalSettlementNotification($customer_id, $cylinder_serial, $gas_name, $size, $rent_amount, $damage_amount, $deposit_deducted, $total_collected, $payment_method, $pdo) {
        $stmt = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if (!$customer || !$customer['name']) return;
        $business = getDefaultBusiness();
        $html = buildRentalSettlementEmailHtml($customer['name'], $cylinder_serial, $gas_name, $size, $rent_amount, $damage_amount, $deposit_deducted, $total_collected, $payment_method, $business);
        $plain = "Dear {$customer['name']},\n\nRental cylinder #{$cylinder_serial} ({$gas_name}, {$size}) has been settled.\nRent: Rs." . number_format($rent_amount, 2) . "\n" . ($damage_amount > 0 ? "Damage: Rs." . number_format($damage_amount, 2) . "\n" : "") . "Deposit deducted: Rs." . number_format($deposit_deducted, 2) . "\nTotal collected: Rs." . number_format($total_collected, 2) . "\nDate: " . date('d M Y, h:i A') . "\n\n- " . $business['label'];
        sendCustomerEmail($customer_id, $pdo, "Rental Settlement - " . htmlspecialchars($business['label']), $html, true, $plain, getBrandConfig()['business_key']);
    }
}
