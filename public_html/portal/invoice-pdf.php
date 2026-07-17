<?php
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/mail-config.php';
require_once __DIR__ . '/../admin/business_helper.php';

$token = $_GET['token'] ?? '';
$order_id = validateInvoiceToken($token);
if (!$order_id) {
    http_response_code(403);
    die('Invalid or expired invoice link.');
}

try {
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name, c.mobile as customer_mobile,
               c.address as customer_address, c.gst_number as customer_gst,
               c.customer_type
        FROM refill_orders o
        JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        die('Order not found.');
    }
    if (floatval($order['gst_rate']) <= 0) {
        http_response_code(403);
        die('Invoice not available for download.');
    }
    $business = getBusiness($order['business_name'] ?: getBrandConfig()['business_key']);

    $items_stmt = $pdo->prepare("
        SELECT oi.*, g.name as gas_name, g.chemical_formula, g.hsn_code as gas_hsn,
               oi.size_capacity,
               p.name as product_name, p.unit as product_unit, p.hsn_code as product_hsn,
               cy_issued.serial_number as serial_number,
               oi.sold_cylinder_serial, oi.sell_price,
               oi.gst_rate, oi.taxable_amount, oi.gst_amount, oi.cgst, oi.sgst
        FROM refill_order_items oi
        LEFT JOIN gas_types g ON oi.gas_type_id = g.id
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN cylinders cy_issued ON oi.cylinder_id = cy_issued.id
        WHERE oi.refill_order_id = ?
    ");
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll();

} catch (PDOException $e) {
    error_log("invoice-pdf.php: " . $e->getMessage());
    http_response_code(500);
    die('Error loading invoice data.');
}

// Generate PDF
require_once __DIR__ . '/../lib/fpdf/fpdf.php';

class InvoicePDF extends FPDF {
    private $business;

    function setBusiness($biz) {
        $this->business = $biz;
    }

    function Header() {
        if ($this->PageNo() > 1) return;
        $biz = $this->business;
        $this->SetFont('Helvetica', 'B', 18);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(0, 10, $biz['name'], 0, 1, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, $biz['tagline'], 0, 1, 'L');
        $this->Cell(0, 4, $biz['address'], 0, 1, 'L');
        $this->Cell(0, 4, 'GSTIN: ' . $biz['gstin'] . ' | Phone: ' . $biz['phone'], 0, 1, 'L');
        $this->Ln(4);
        $this->SetDrawColor(37, 99, 235);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(6);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

$pdf = new InvoicePDF();
$pdf->setBusiness($business);
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$pdf->SetFont('Helvetica', 'B', 16);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(0, 10, 'TAX INVOICE', 0, 1, 'R');
$pdf->Ln(2);

// Invoice meta table (fit within 190mm printable area)
$col_w = 190;
$left_w = 40;
$mid_w = 65;
$right_w = $col_w - $left_w - $mid_w;

$pdf->SetFont('Helvetica', '', 9);
$pdf->SetFillColor(248, 250, 252);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($left_w, 7, 'Invoice No:', 0, 0, 'L', true);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell($mid_w, 7, $order['invoice_number'], 0, 0, 'L', true);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell($right_w - 30, 7, 'Date:', 0, 0, 'R', true);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(30, 7, date('d-M-Y', strtotime($order['order_date'])), 0, 1, 'L', true);

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell($left_w, 7, 'Order No:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell($mid_w, 7, '#ORD-' . $order['id'], 0, 0, 'L');
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell($right_w - 30, 7, 'Status:', 0, 0, 'R');
$status_color = $order['payment_status'] === 'paid' ? [16, 185, 129] : ($order['payment_status'] === 'partial' ? [245, 158, 11] : [239, 68, 68]);
$pdf->SetTextColor($status_color[0], $status_color[1], $status_color[2]);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(30, 7, ucfirst($order['payment_status']), 0, 1, 'L');
$pdf->Ln(4);

// Customer info
$pdf->SetFillColor(37, 99, 235);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 8, '  CUSTOMER DETAILS', 0, 1, 'L', true);
$pdf->SetFillColor(248, 250, 252);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(40, 7, '  Name:', 0, 0, 'L', true);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 7, $order['customer_name'], 0, 1, 'L', true);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(40, 7, '  Mobile:', 0, 0, 'L');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 7, $order['customer_mobile'], 0, 1, 'L');
if ($order['customer_address']) {
    if ($pdf->GetY() > 250) $pdf->AddPage();
    $pdf->Cell(40, 7, '  Address:', 0, 0, 'L');
    $pdf->SetFont('Helvetica', '', 9);
    $y_before = $pdf->GetY();
    $pdf->MultiCell(145, 7, $order['customer_address']);
    if ($pdf->GetY() == $y_before) {
        $pdf->SetY($y_before + 7);
    }
}
if ($pdf->GetY() > 255) $pdf->AddPage();
$pdf->Cell(40, 7, '  GSTIN:', 0, 0, 'L');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 7, $order['customer_gst'] ?: 'Consumer', 0, 1, 'L');
$pdf->Ln(4);

// Items table header
$pdf->SetFillColor(37, 99, 235);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 9);
$w = [72, 22, 16, 30, 20, 30];
$headers = ['Description', 'Size', 'HSN', 'Rate', 'Qty', 'Total'];
for ($i = 0; $i < 6; $i++) {
    $pdf->Cell($w[$i], 8, $headers[$i], 1, 0, 'C', true);
}
$pdf->Ln();

// Items
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('Helvetica', '', 8);
$fill = false;
foreach ($items as $item) {
    if ($pdf->GetY() > 255) $pdf->AddPage();
    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);

    if ($item['is_rental'] == 3) {
        $name = $item['product_name'] ?? 'Product';
    } else {
        $name = $item['gas_name'];
        if ($item['is_rental'] == 2) {
            $serial = $item['sold_cylinder_serial'] ?? '';
            $name .= ' [SOLD Cylinder: ' . $serial . ']';
            $sp = floatval($item['sell_price'] ?? 0);
            if ($sp > 0) {
                $name .= ' (Cyl. Chg: Rs.' . number_format($sp, 2) . ')';
            }
        } elseif ($item['serial_number']) {
            $name .= ' (S/N: ' . $item['serial_number'] . ')';
        }
    }
    $line_total = ($item['price_per_unit'] * $item['qty']) + ($item['is_rental'] == 2 ? floatval($item['sell_price'] ?? 0) : 0);
    $hsn = $item['product_hsn'] ?? $item['gas_hsn'] ?? '—';

    $x_start = $pdf->GetX();
    $y_start = $pdf->GetY();

    $pdf->SetXY($x_start, $y_start);
    $pdf->MultiCell($w[0], 7, $name, 'LR', 'L', $fill);

    $y_after_name = $pdf->GetY();
    $actual_row_h = $y_after_name - $y_start;

    $pdf->SetXY($x_start + $w[0], $y_start);
    $pdf->Cell($w[1], $actual_row_h, $item['is_rental'] == 3 ? '—' : $item['size_capacity'], 'LR', 0, 'C', $fill);
    $pdf->Cell($w[2], $actual_row_h, $hsn, 'LR', 0, 'C', $fill);
    $pdf->Cell($w[3], $actual_row_h, 'Rs. ' . number_format($item['price_per_unit'], 2), 'LR', 0, 'R', $fill);
    $pdf->Cell($w[4], $actual_row_h, $item['qty'], 'LR', 0, 'C', $fill);
    $pdf->Cell($w[5], $actual_row_h, 'Rs. ' . number_format($line_total, 2), 'LR', 0, 'R', $fill);
    $pdf->Ln();
    $fill = !$fill;
}

// Bottom line of items table
$pdf->Cell(array_sum($w), 0, '', 'T');
$pdf->Ln(6);

// Totals
if ($pdf->GetY() > 255) $pdf->AddPage();
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(100, 116, 139);
$label_w = 130;
$value_w = 60;

$pdf->Cell($label_w, 7, 'Subtotal', 'LR', 0, 'R');
$pdf->Cell($value_w, 7, 'Rs. ' . number_format($order['subtotal'], 2), 'LR', 0, 'R');
$pdf->Ln();

// Dynamic GST breakdown by rate
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
if (!empty($gst_by_rate)):
    foreach ($gst_by_rate as $rate => $g):
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($label_w, 6, 'Taxable Value (' . $rate . '%)', 'LR', 0, 'R');
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell($value_w, 6, 'Rs. ' . number_format($g['taxable'], 2), 'LR', 0, 'R');
        $pdf->Ln();
        $pdf->Cell($label_w, 6, 'CGST @ ' . ($rate/2) . '%', 'LR', 0, 'R');
        $pdf->Cell($value_w, 6, 'Rs. ' . number_format($g['cgst'], 2), 'LR', 0, 'R');
        $pdf->Ln();
        $pdf->Cell($label_w, 6, 'SGST @ ' . ($rate/2) . '%', 'LR', 0, 'R');
        $pdf->Cell($value_w, 6, 'Rs. ' . number_format($g['sgst'], 2), 'LR', 0, 'R');
        $pdf->Ln();
    endforeach;
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell($label_w, 7, 'Total GST', 'LR', 0, 'R');
    $pdf->Cell($value_w, 7, 'Rs. ' . number_format($order['tax_amount'], 2), 'LR', 0, 'R');
    $pdf->Ln();
else:
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell($label_w, 7, 'GST (' . number_format(floatval($order['gst_rate'] ?? 18), 0) . '%)', 'LR', 0, 'R');
    $pdf->Cell($value_w, 7, 'Rs. ' . number_format($order['tax_amount'], 2), 'LR', 0, 'R');
    $pdf->Ln();
endif;

if (floatval($order['discount'] ?? 0) > 0) {
    $pdf->SetTextColor(16, 185, 129);
    $pdf->Cell($label_w, 7, 'Discount', 'LR', 0, 'R');
    $pdf->Cell($value_w, 7, '-Rs. ' . number_format($order['discount'], 2), 'LR', 0, 'R');
    $pdf->Ln();
    $pdf->SetTextColor(100, 116, 139);
}

$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetTextColor(37, 99, 235);
$pdf->Cell($label_w, 9, 'Grand Total', 'T', 0, 'R');
$pdf->Cell($value_w, 9, 'Rs. ' . number_format($order['grand_total'], 2), 'T', 0, 'R');
$pdf->Ln();

// Delivery note
if (!empty($order['delivery_note'])) {
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 6, 'Delivery Note: ' . $order['delivery_note'], 0, 1, 'L');
}

// Bank details and invoice terms
if (!empty($business['bank_details']) || !empty($business['invoice_terms'])) {
    $pdf->Ln(2);
    $pdf->SetDrawColor(209, 213, 219);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(71, 85, 105);
    if (!empty($business['bank_details'])) {
        $pdf->MultiCell(0, 5, 'Bank Details: ' . $business['bank_details'], 0, 'L');
    }
    if (!empty($business['invoice_terms'])) {
        $pdf->MultiCell(0, 5, 'Terms: ' . $business['invoice_terms'], 0, 'L');
    }
}

$pdf->Output('D', 'Invoice-' . $order['invoice_number'] . '.pdf');
