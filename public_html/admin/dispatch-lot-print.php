<?php
$page_title = "Dispatch Lot Challan";
$active_menu = "lot_dashboard";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/business_helper.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo "<p style='padding:2rem;text-align:center;color:#dc2626;'>Invalid lot ID.</p>";
    require_once __DIR__ . '/layout_footer.php';
    exit();
}

$lot_q = $pdo->prepare("
    SELECT dl.*, v.name as vendor_name, v.address as vendor_address, v.gst_number as vendor_gst, v.mobile as vendor_phone
    FROM dispatch_lots dl
    JOIN vendors v ON dl.vendor_id = v.id
    WHERE dl.id = ?
");
$lot_q->execute([$id]);
$lot = $lot_q->fetch();

if (!$lot) {
    echo "<p style='padding:2rem;text-align:center;color:#dc2626;'>Lot not found.</p>";
    require_once __DIR__ . '/layout_footer.php';
    exit();
}

$cyl_q = $pdo->prepare("
    SELECT dli.*, g.name as gas_name
    FROM dispatch_lot_items dli
    JOIN gas_types g ON dli.gas_type_id = g.id
    WHERE dli.lot_id = ?
    ORDER BY dli.id ASC
");
$cyl_q->execute([$id]);
$cylinders = $cyl_q->fetchAll();

$brand = getBrandConfig();
$lot_status = $lot['lot_status'];
$status_label = 'Open';
if ($lot_status === 'partial_return') $status_label = 'Partial Return';
elseif ($lot_status === 'completed') $status_label = 'Completed';
?>
<style>
@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
.print-container { max-width: 700px; margin: 0 auto; padding: 2rem; }
.print-header { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #1e293b; }
.print-header h1 { font-size: 1.5rem; font-weight: 800; margin: 0; }
.print-header p { font-size: 0.85rem; color: #64748b; margin: 0.25rem 0 0; }
.print-section { margin-bottom: 1.25rem; }
.print-section h3 { font-size: 0.95rem; font-weight: 700; margin-bottom: 0.5rem; padding-bottom: 0.25rem; border-bottom: 1px solid #e2e8f0; }
.print-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem; }
.print-grid .label { color: #64748b; font-weight: 600; }
.print-grid .value { font-weight: 600; }
.print-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.print-table th { background: #f1f5f9; padding: 0.5rem; text-align: left; border: 1px solid #e2e8f0; font-size: 0.78rem; }
.print-table td { padding: 0.4rem 0.5rem; border: 1px solid #e2e8f0; }
.print-summary { margin-top: 1rem; text-align: right; font-size: 0.9rem; }
.print-summary .line { display: flex; justify-content: flex-end; gap: 1rem; padding: 0.2rem 0; }
.print-summary .total { font-weight: 800; font-size: 1.05rem; border-top: 2px solid #1e293b; padding-top: 0.4rem; margin-top: 0.3rem; }
.print-actions { text-align: center; margin-top: 2rem; }
@media print { .print-actions { display: none; } }
</style>
<div class="print-container">
    <div class="print-header">
        <h1><?php echo htmlspecialchars($brand['business_name'] ?? 'Prem Gas Solution'); ?></h1>
        <p><?php echo htmlspecialchars($brand['address'] ?? ''); ?> | GST: <?php echo htmlspecialchars($brand['gstin'] ?? ''); ?></p>
        <p>Dispatch Challan</p>
    </div>

    <div class="print-section">
        <h3>Lot Information</h3>
        <div class="print-grid">
            <div><span class="label">Lot No:</span> <span class="value"><?php echo htmlspecialchars($lot['lot_number']); ?></span></div>
            <div><span class="label">Status:</span> <span class="value"><?php echo $status_label; ?></span></div>
            <div><span class="label">Dispatch Date:</span> <span class="value"><?php echo date('d-M-Y h:i A', strtotime($lot['dispatch_date'])); ?></span></div>
            <div><span class="label">Driver:</span> <span class="value"><?php echo htmlspecialchars($lot['driver_name'] ?? '—'); ?></span></div>
            <div><span class="label">Vehicle No:</span> <span class="value"><?php echo htmlspecialchars($lot['vehicle_number'] ?? '—'); ?></span></div>
            <div><span class="label">Expected Return:</span> <span class="value"><?php echo $lot['expected_return_date'] ? date('d-M-Y', strtotime($lot['expected_return_date'])) : '—'; ?></span></div>
        </div>
    </div>

    <div class="print-section">
        <h3>Vendor Details</h3>
        <div class="print-grid">
            <div><span class="label">Name:</span> <span class="value"><?php echo htmlspecialchars($lot['vendor_name']); ?></span></div>
            <div><span class="label">GSTIN:</span> <span class="value"><?php echo htmlspecialchars($lot['vendor_gst'] ?? '—'); ?></span></div>
            <div><span class="label">Address:</span> <span class="value"><?php echo htmlspecialchars($lot['vendor_address'] ?? '—'); ?></span></div>
            <div><span class="label">Phone:</span> <span class="value"><?php echo htmlspecialchars($lot['vendor_phone'] ?? '—'); ?></span></div>
        </div>
    </div>

    <div class="print-section">
        <h3>Cylinders Dispatched (<?php echo count($cylinders); ?>)</h3>
        <table class="print-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Serial Number</th>
                    <th>Gas Type</th>
                    <th>Size</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($cylinders as $c): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($c['serial_number']); ?></td>
                    <td><?php echo htmlspecialchars($c['gas_name']); ?></td>
                    <td><?php echo htmlspecialchars($c['size_capacity']); ?></td>
                    <td><?php echo $c['dispatch_status'] === 'received' ? 'Received' : 'Dispatched'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="print-section">
        <h3>Payment Summary</h3>
        <div class="print-summary">
            <?php
            $adv_print = floatval($lot['advance_amount']);
            $gst_print = floatval($lot['gst_amount']);
            $final_refill_print = floatval($lot['final_refill_amount']);
            $final_gst_print = floatval($lot['final_gst_amount']);
            $final_total_print = floatval($lot['final_total']);
            $addl_pay_print = floatval($lot['additional_payments']);
            $total_paid_print = floatval($lot['total_paid']);
            $remaining_print = floatval($lot['remaining_balance']);
            $has_final_print = $final_total_print > 0;
            ?>
            <?php if ($has_final_print): ?>
            <div class="line"><span>Final Refill Amount:</span><span>₹<?php echo number_format($final_refill_print, 2); ?></span></div>
            <?php if ($final_gst_print > 0): ?>
            <div class="line"><span>GST (<?php echo floatval($lot['gst_rate']); ?>%):</span><span>₹<?php echo number_format($final_gst_print, 2); ?></span></div>
            <?php endif; ?>
            <div class="line"><span>Total Invoice:</span><span>₹<?php echo number_format($final_total_print, 2); ?></span></div>
            <?php elseif ($gst_print > 0): ?>
            <div class="line"><span>Estimated GST (<?php echo floatval($lot['gst_rate']); ?>%):</span><span>₹<?php echo number_format($gst_print, 2); ?></span></div>
            <?php endif; ?>
            <div class="line"><span>Advance Paid:</span><span>₹<?php echo number_format($adv_print, 2); ?></span></div>
            <?php if ($addl_pay_print > 0): ?>
            <div class="line"><span>Additional Payments:</span><span>₹<?php echo number_format($addl_pay_print, 2); ?></span></div>
            <?php endif; ?>
            <div class="line"><span>Total Paid:</span><span>₹<?php echo number_format($total_paid_print, 2); ?></span></div>
            <div class="line"><span>Remaining Due:</span><span>₹<?php echo number_format($remaining_print, 2); ?></span></div>
            <div class="line total"><span>Payment Status:</span><span><?php echo ucfirst($lot['payment_status'] ?? 'Unpaid'); ?></span></div>
        </div>
    </div>

    <?php if ($lot['notes']): ?>
    <div class="print-section">
        <h3>Notes</h3>
        <p style="font-size:0.85rem;color:#475569;"><?php echo nl2br(htmlspecialchars($lot['notes'])); ?></p>
    </div>
    <?php endif; ?>

    <div class="print-section" style="margin-top:2rem;display:flex;justify-content:space-between;font-size:0.85rem;">
        <div><span class="label">Received By:</span> ____________________</div>
        <div><span class="label">Dispatch By:</span> <?php echo htmlspecialchars($lot['created_by'] ?? '—'); ?></div>
    </div>

    <div class="print-actions">
        <button onclick="window.print()" class="btn-primary" style="padding:0.6rem 2rem;font-size:1rem;">Print</button>
        <button onclick="window.close()" class="btn-sm" style="padding:0.6rem 2rem;font-size:1rem;background:#e2e8f0;color:#475569;">Close</button>
    </div>
</div>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
