<?php
/**
 * GST PDF Generator — Renders selected GST ledger entries as print-friendly HTML
 * Accessed via checkbox selection from gst-register.php Input/Output tabs
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/business_helper.php';
require_role(['super_admin', 'billing_clerk']);

runGSTMigrations($pdo);

$type = $_GET['type'] ?? 'input';
$ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
$ids = array_map('intval', $ids);
$ids = array_filter($ids);

$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to = $_GET['to'] ?? date('Y-m-d');

if (empty($ids)) {
    die('No transactions selected. Please go back and select at least one transaction.');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

if ($type === 'input') {
    $stmt = $pdo->prepare("SELECT gl.*, 
        CASE WHEN gl.entity_type = 'supplier' THEN cs.company_name ELSE v.name END as entity_name,
        CASE WHEN gl.entity_type = 'supplier' THEN cs.gst_number ELSE v.gst_number END as entity_gstin,
        CASE WHEN gl.entity_type = 'supplier' THEN cp.invoice_number ELSE vrb.invoice_number END as vendor_invoice
        FROM gst_ledger gl 
        LEFT JOIN vendors v ON gl.entity_type = 'vendor' AND gl.entity_id = v.id
        LEFT JOIN cylinder_suppliers cs ON gl.entity_type = 'supplier' AND gl.entity_id = cs.id
        LEFT JOIN vendor_refill_batches vrb ON gl.reference_type = 'vendor_refill_batch' AND gl.reference_id = vrb.id
        LEFT JOIN cylinder_purchases cp ON gl.reference_type IN ('cylinder_purchase','product_purchase') AND gl.reference_id = cp.id
        WHERE gl.id IN ($placeholders) ORDER BY gl.transaction_date DESC");
    $stmt->execute(array_values($ids));
} else {
    $stmt = $pdo->prepare("SELECT gl.*, c.name as entity_name, c.gst_number as entity_gstin, ro.invoice_number FROM gst_ledger gl JOIN customers c ON gl.entity_id = c.id LEFT JOIN refill_orders ro ON gl.reference_id = ro.id WHERE gl.id IN ($placeholders) ORDER BY gl.transaction_date DESC");
    $stmt->execute(array_values($ids));
}
$entries = $stmt->fetchAll();

$total_taxable = 0; $total_gst = 0; $total_cgst = 0; $total_sgst = 0;
foreach ($entries as $e) {
    $total_taxable += floatval($e['taxable_amount']);
    $total_gst += floatval($e['gst_amount']);
    $total_cgst += floatval($e['cgst']);
    $total_sgst += floatval($e['sgst']);
}

$brand_cfg = getBrandConfig();
$title = $type === 'input' ? 'Input GST Register' : 'Output GST Register';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo $title; ?> — <?php echo htmlspecialchars($brand_cfg['label']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', Arial, sans-serif; }
        body { padding: 2rem; color: #1e293b; }
        .header { text-align: center; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid #1e293b; }
        .header h1 { font-size: 1.5rem; font-weight: 800; }
        .header .sub { font-size: 0.85rem; color: #64748b; margin-top: 4px; }
        .header .meta { font-size: 0.8rem; color: #64748b; margin-top: 6px; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .summary-item { background: #f8fafc; padding: 0.75rem 1rem; border-radius: 8px; text-align: center; }
        .summary-item .label { font-size: 0.72rem; text-transform: uppercase; color: #64748b; font-weight: 600; }
        .summary-item .value { font-size: 1.2rem; font-weight: 800; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { background: #f1f5f9; padding: 0.65rem 0.75rem; text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; border-bottom: 2px solid #e2e8f0; }
        td { padding: 0.55rem 0.75rem; border-bottom: 1px solid #f1f5f9; }
        tr:nth-child(even) td { background: #fafafa; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        tfoot td { background: #f8fafc !important; font-weight: 700; border-top: 2px solid #e2e8f0; }
        .footer { margin-top: 2rem; text-align: center; font-size: 0.75rem; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 1rem; }
        @media print { body { padding: 0.5in; } .no-print { display: none; } }
        .no-print { text-align: center; margin-bottom: 1rem; }
        .no-print button { padding: 0.5rem 1.5rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 600; }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Print / Save as PDF</button>
        <button onclick="window.close()" style="background:#64748b;margin-left:8px;">Close</button>
    </div>

    <div class="header">
        <h1><?php echo htmlspecialchars($brand_cfg['business_name']); ?></h1>
        <div class="sub"><?php echo $title; ?></div>
        <div class="meta">Period: <?php echo date('d-M-Y', strtotime($date_from)); ?> to <?php echo date('d-M-Y', strtotime($date_to)); ?> | Generated: <?php echo date('d-M-Y H:i'); ?></div>
        <div class="meta">GSTIN: <?php echo htmlspecialchars($brand_cfg['gstin']); ?></div>
    </div>

    <div class="summary">
        <div class="summary-item">
            <div class="label">Transactions</div>
            <div class="value"><?php echo count($entries); ?></div>
        </div>
        <div class="summary-item">
            <div class="label">Total Taxable</div>
            <div class="value">₹<?php echo number_format($total_taxable, 2); ?></div>
        </div>
        <div class="summary-item">
            <div class="label">Total CGST</div>
            <div class="value">₹<?php echo number_format($total_cgst, 2); ?></div>
        </div>
        <div class="summary-item">
            <div class="label">Total SGST</div>
            <div class="value">₹<?php echo number_format($total_sgst, 2); ?></div>
        </div>
        <div class="summary-item">
            <div class="label">Total GST</div>
            <div class="value">₹<?php echo number_format($total_gst, 2); ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th><?php echo $type === 'input' ? 'Vendor' : 'Customer'; ?></th>
                <th>GSTIN</th>
                <?php if ($type === 'input'): ?><th>Invoice</th><?php endif; ?>
                <th class="text-center">Rate</th>
                <th class="text-right">Taxable</th>
                <th class="text-right">CGST</th>
                <th class="text-right">SGST</th>
                <th class="text-right">Total GST</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 0; foreach ($entries as $e): $i++; ?>
            <tr>
                <td><?php echo $i; ?></td>
                <td><?php echo date('d-M-Y', strtotime($e['transaction_date'])); ?></td>
                <td class="fw-bold"><?php echo htmlspecialchars($e['entity_name']); ?></td>
                <td style="font-family:monospace;font-size:0.8rem;"><?php echo htmlspecialchars($e['entity_gstin'] ?: '—'); ?></td>
                <?php if ($type === 'input'): ?><td><?php echo htmlspecialchars($e['vendor_invoice'] ?: '—'); ?></td><?php endif; ?>
                <td class="text-center"><?php echo $e['gst_rate']; ?>%</td>
                <td class="text-right">₹<?php echo number_format($e['taxable_amount'], 2); ?></td>
                <td class="text-right">₹<?php echo number_format($e['cgst'], 2); ?></td>
                <td class="text-right">₹<?php echo number_format($e['sgst'], 2); ?></td>
                <td class="text-right fw-bold">₹<?php echo number_format($e['gst_amount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="<?php echo $type === 'input' ? 6 : 5; ?>" class="text-right">Grand Total</td>
                <td class="text-right">₹<?php echo number_format($total_taxable, 2); ?></td>
                <td class="text-right">₹<?php echo number_format($total_cgst, 2); ?></td>
                <td class="text-right">₹<?php echo number_format($total_sgst, 2); ?></td>
                <td class="text-right">₹<?php echo number_format($total_gst, 2); ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        This is a computer-generated document. No signature required.<br>
        <?php echo htmlspecialchars($brand_cfg['address']); ?> | <?php echo htmlspecialchars($brand_cfg['phone']); ?>
    </div>
</body>
</html>
