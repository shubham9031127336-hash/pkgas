<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail-config.php';
require_once __DIR__ . '/../portal/email.php';
require_once __DIR__ . '/business_helper.php';
require_once __DIR__ . '/email-template.php';

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 1;

$stmt = $pdo->prepare("
    SELECT o.*, c.name as customer_name, c.email as customer_email
    FROM refill_orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    die("Order not found.");
}

header('Content-Type: text/plain');

echo "Order #{$order_id} found.\n";
echo "Customer: {$order['customer_name']} <{$order['customer_email']}>\n";
echo "GST Rate: {$order['gst_rate']}\n";
echo "Grand Total: {$order['grand_total']}\n\n";

if (floatval($order['gst_rate'] ?? 0) <= 0) {
    die("GST rate <= 0, email would not be sent (sendOrderConfirmation skips).\n");
}

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

echo "Sending email via sendCustomerEmail...\n";
echo "Using business key: {$biz_key}\n\n";

$result = sendCustomerEmail($order['customer_id'], $pdo, "Order Confirmation #" . $order_id . " - " . $business['label'], $html_body, true, $plain_body, $biz_key);

if ($result) {
    echo "SUCCESS: Invoice email sent to {$order['customer_email']}!\n";
} else {
    echo "FAILED: Could not send email. Check Apache error logs for details.\n";
}
