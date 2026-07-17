<?php
$page_title = 'GST Reports';
$active_menu = 'gst_reports';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/business_helper.php';
require_once __DIR__ . '/csrf.php';

runGSTMigrations($pdo);

$tab = $_GET['tab'] ?? 'input';
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to = $_GET['to'] ?? date('Y-m-d');
$gst_rate = $_GET['gst_rate'] ?? '';
$entity_id = intval($_GET['entity_id'] ?? 0);

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gst-' . $tab . '-' . $date_from . '-to-' . $date_to . '.csv"');
    $out = fopen('php://output', 'w');
    
    $filters = ['date_from' => $date_from, 'date_to' => $date_to];
    if ($gst_rate !== '') $filters['gst_rate'] = floatval($gst_rate);
    
    if ($tab === 'input' || $tab === 'input_detail') {
        if ($entity_id) $filters['vendor_id'] = $entity_id;
        $rows = getGSTReportInput($pdo, $filters);
        fputcsv($out, ['Date', 'Vendor', 'GSTIN', 'Invoice', 'GST Rate', 'Taxable', 'CGST', 'SGST', 'Total GST']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['transaction_date'], $r['entity_name'], $r['entity_gstin'], $r['vendor_invoice'],
                $r['gst_rate'] . '%', $r['taxable_amount'], $r['cgst'], $r['sgst'], $r['gst_amount']
            ]);
        }
    } elseif ($tab === 'output' || $tab === 'output_detail') {
        if ($entity_id) $filters['customer_id'] = $entity_id;
        $rows = getGSTReportOutput($pdo, $filters);
        fputcsv($out, ['Date', 'Customer', 'GSTIN', 'Invoice', 'GST Rate', 'Taxable', 'CGST', 'SGST', 'Total GST']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['transaction_date'], $r['entity_name'], $r['entity_gstin'], $r['invoice_number'],
                $r['gst_rate'] . '%', $r['taxable_amount'], $r['cgst'], $r['sgst'], $r['gst_amount']
            ]);
        }
    } elseif ($tab === 'monthly') {
        $monthly = getMonthlyGSTSummary($pdo, 24);
        fputcsv($out, ['Month', 'Input GST', 'Output GST', 'Net Payable', 'ITC']);
        foreach ($monthly as $m) {
            fputcsv($out, [$m['month_label'], $m['input_gst'], $m['output_gst'], $m['net_payable'], $m['itc']]);
        }
    } elseif ($tab === 'vendor') {
        $rows = getVendorGSTTotals($pdo, null, $date_from, $date_to);
        fputcsv($out, ['Vendor ID', 'GST Rate', 'Taxable', 'GST', 'CGST', 'SGST', 'Transactions']);
        foreach ($rows as $r) {
            $name = getGSTEntityName($pdo, 'vendor', $r['entity_id']);
            fputcsv($out, [$name, $r['gst_rate'] . '%', $r['taxable'], $r['gst'], $r['cgst'], $r['sgst'], $r['txns']]);
        }
    } elseif ($tab === 'customer') {
        $rows = getCustomerGSTTotals($pdo, null, $date_from, $date_to);
        fputcsv($out, ['Customer ID', 'GST Rate', 'Taxable', 'GST', 'CGST', 'SGST', 'Transactions']);
        foreach ($rows as $r) {
            $name = getGSTEntityName($pdo, 'customer', $r['entity_id']);
            fputcsv($out, [$name, $r['gst_rate'] . '%', $r['taxable'], $r['gst'], $r['cgst'], $r['sgst'], $r['txns']]);
        }
    } elseif ($tab === 'liability') {
        $monthly = getMonthlyGSTSummary($pdo, 24);
        fputcsv($out, ['Month', 'Output GST', 'Input GST', 'Net Payable']);
        foreach ($monthly as $m) {
            fputcsv($out, [$m['month_label'], $m['output_gst'], $m['input_gst'], $m['net_payable']]);
        }
    }
    fclose($out);
    exit;
}

// Reconciliation data
$recon_entries = [];
$recon_summary = ['matched'=>0, 'partial'=>0, 'missing'=>0, 'duplicate'=>0, 'total_itc'=>0, 'total_portal'=>0];
if ($tab === 'reconciliation') {
    $recon_period = $_GET['recon_period'] ?? getCurrentGSTPeriod()['gst_period'];
    $recon_fy = $_GET['recon_fy'] ?? getCurrentGSTPeriod()['financial_year'];
    $recon_brand = $_GET['recon_brand'] ?? getBrandConfig()['business_key'];
    $recon_period_arr = getGSTPeriodFromParam($recon_period);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_reconciliation') {
        try {
            $stmt = $pdo->prepare("INSERT INTO gst_reconciliation (business_key, financial_year, gst_period, vendor_gstin, vendor_invoice_number, vendor_invoice_date, purchase_gst_amount, purchase_taxable_value, itc_eligibility, itc_amount, match_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$recon_brand, $recon_fy, $recon_period, trim($_POST['vendor_gstin'] ?? ''), trim($_POST['vendor_invoice_number'] ?? ''), $_POST['vendor_invoice_date'] ?: null, floatval($_POST['purchase_gst_amount'] ?? 0), floatval($_POST['purchase_taxable_value'] ?? 0), $_POST['itc_eligibility'] ?? 'eligible', floatval($_POST['itc_amount'] ?? 0), $_POST['match_status'] ?? 'missing', trim($_POST['notes'] ?? '')]);
            $recon_msg = 'Reconciliation entry added.';
        } catch (PDOException $e) { $recon_err = 'Failed: ' . $e->getMessage(); }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_entry') {
        try { $pdo->prepare("DELETE FROM gst_reconciliation WHERE id = ?")->execute([intval($_POST['id'] ?? 0)]); $recon_msg = 'Entry deleted.'; }
        catch (PDOException $e) { $recon_err = 'Delete failed: ' . $e->getMessage(); }
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM gst_reconciliation WHERE business_key=? AND financial_year=? AND gst_period=? ORDER BY match_status, vendor_invoice_date DESC");
        $stmt->execute([$recon_brand, $recon_fy, $recon_period]);
        $recon_entries = $stmt->fetchAll();
        foreach ($recon_entries as $re) {
            $recon_summary[$re['match_status']] = ($recon_summary[$re['match_status']] ?? 0) + 1;
            $recon_summary['total_itc'] += floatval($re['itc_amount'] ?? 0);
            $recon_summary['total_portal'] += floatval($re['portal_gst_amount'] ?? 0);
        }
    } catch (PDOException $e) {}
}

// Data for tabs
$input_rows = [];
$output_rows = [];
if ($tab === 'input' || $tab === 'input_detail') {
    $f = ['date_from' => $date_from, 'date_to' => $date_to];
    if ($gst_rate !== '') $f['gst_rate'] = floatval($gst_rate);
    if ($entity_id) $f['vendor_id'] = $entity_id;
    $input_rows = getGSTReportInput($pdo, $f);
}
if ($tab === 'output' || $tab === 'output_detail') {
    $f = ['date_from' => $date_from, 'date_to' => $date_to];
    if ($gst_rate !== '') $f['gst_rate'] = floatval($gst_rate);
    if ($entity_id) $f['customer_id'] = $entity_id;
    $output_rows = getGSTReportOutput($pdo, $f);
}
$monthly_data = [];
if (in_array($tab, ['monthly', 'liability', 'itc'])) {
    $monthly_data = getMonthlyGSTSummary($pdo, 24);
}
$vendor_data = [];
if ($tab === 'vendor') {
    $vendor_data = getVendorGSTTotals($pdo, null, $date_from, $date_to);
}
$customer_data = [];
if ($tab === 'customer') {
    $customer_data = getCustomerGSTTotals($pdo, null, $date_from, $date_to);
}

$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll();
?>
<div class="page-header">
    <div class="page-header-title">
        <h2>GST Reports</h2>
        <p>Comprehensive GST reporting across all dimensions</p>
    </div>
    <div class="page-header-actions">
        <a href="gst-dashboard.php" class="btn-secondary">Dashboard</a>
        <a href="gst-settlement.php" class="btn-secondary">Settlement</a>
    </div>
</div>

<div class="tab-bar" style="margin-bottom:1rem;">
    <a href="?tab=input&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'input' ? 'active' : ''; ?>">Purchase GST</a>
    <a href="?tab=output&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'output' ? 'active' : ''; ?>">Sales GST</a>
    <a href="?tab=input_detail&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'input_detail' ? 'active' : ''; ?>">Input GST Detail</a>
    <a href="?tab=output_detail&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'output_detail' ? 'active' : ''; ?>">Output GST Detail</a>
    <a href="?tab=monthly&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'monthly' ? 'active' : ''; ?>">Monthly Summary</a>
    <a href="?tab=vendor&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'vendor' ? 'active' : ''; ?>">Vendor GST</a>
    <a href="?tab=customer&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'customer' ? 'active' : ''; ?>">Customer GST</a>
    <a href="?tab=liability&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'liability' ? 'active' : ''; ?>">GST Liability</a>
    <a href="?tab=itc&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="tab-btn <?php echo $tab === 'itc' ? 'active' : ''; ?>">ITC Report</a>
    <a href="gst-return-center.php" class="tab-btn">Return Reports</a>
    <a href="?tab=reconciliation&recon_period=<?php echo getCurrentGSTPeriod()['gst_period']; ?>&recon_fy=<?php echo getCurrentGSTPeriod()['financial_year']; ?>" class="tab-btn <?php echo $tab === 'reconciliation' ? 'active' : ''; ?>">Reconciliation</a>
</div>

<div class="admin-card" style="padding:1rem;">
    <form method="GET" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">
        <div>
            <label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:3px;">From</label>
            <input type="date" name="from" value="<?php echo $date_from; ?>" class="form-control" style="padding:0.45rem 0.65rem;width:160px;">
        </div>
        <div>
            <label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:3px;">To</label>
            <input type="date" name="to" value="<?php echo $date_to; ?>" class="form-control" style="padding:0.45rem 0.65rem;width:160px;">
        </div>
        <div>
            <label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:3px;">GST Rate</label>
            <select name="gst_rate" class="form-control" style="padding:0.45rem 0.65rem;width:100px;">
                <option value="">All</option>
                <option value="5" <?php echo $gst_rate === '5' ? 'selected' : ''; ?>>5%</option>
                <option value="12" <?php echo $gst_rate === '12' ? 'selected' : ''; ?>>12%</option>
                <option value="18" <?php echo $gst_rate === '18' ? 'selected' : ''; ?>>18%</option>
                <option value="28" <?php echo $gst_rate === '28' ? 'selected' : ''; ?>>28%</option>
            </select>
        </div>
        <?php if (in_array($tab, ['input', 'input_detail'])): ?>
        <div>
            <label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:3px;">Vendor</label>
            <select name="entity_id" class="form-control" style="padding:0.45rem 0.65rem;width:180px;">
                <option value="">All Vendors</option>
                <?php foreach ($vendors as $v): ?>
                <option value="<?php echo $v['id']; ?>" <?php echo $entity_id === intval($v['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif (in_array($tab, ['output', 'output_detail'])): ?>
        <div>
            <label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:3px;">Customer</label>
            <select name="entity_id" class="form-control" style="padding:0.45rem 0.65rem;width:180px;">
                <option value="">All Customers</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $entity_id === intval($c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn-primary" style="padding:0.45rem 1rem;">Generate</button>
        <a href="?tab=<?php echo $tab; ?>&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&export=csv&gst_rate=<?php echo $gst_rate; ?><?php echo $entity_id ? '&entity_id=' . $entity_id : ''; ?>" class="btn-secondary" style="padding:0.45rem 1rem;">Export CSV</a>
    </form>
</div>

<?php if ($tab === 'input' || $tab === 'input_detail'): ?>
<div class="admin-card card-no-pad">
    <div class="card-title" style="padding:1rem 1.25rem;margin:0;">
        <?php echo $tab === 'input' ? 'Purchase GST Report' : 'Input GST Detailed Report'; ?>
        <span style="font-weight:400;font-size:0.8rem;color:var(--admin-muted);"><?php echo date('d-M-Y', strtotime($date_from)); ?> — <?php echo date('d-M-Y', strtotime($date_to)); ?></span>
    </div>
    <?php if (empty($input_rows)): ?>
    <div class="empty-state-modern" style="padding:2rem;text-align:center;color:var(--admin-muted);"><p>No records found.</p></div>
    <?php else: ?>
    <div class="table-wrapper" style="border:none;margin-top:0;">
        <table class="admin-table">
            <thead>
                <tr><th>Date</th><th>Vendor</th><th>GSTIN</th><th>Invoice</th><th>Rate</th><th style="text-align:right;">Taxable</th><th style="text-align:right;">CGST</th><th style="text-align:right;">SGST</th><th style="text-align:right;">Total GST</th><th>Batch #</th></tr>
            </thead>
            <tbody>
                <?php $t = ['taxable'=>0,'gst'=>0,'cgst'=>0,'sgst'=>0]; foreach ($input_rows as $r): 
                    $t['taxable'] += floatval($r['taxable_amount']);
                    $t['gst'] += floatval($r['gst_amount']);
                    $t['cgst'] += floatval($r['cgst']);
                    $t['sgst'] += floatval($r['sgst']);
                ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($r['transaction_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($r['entity_name']); ?></strong></td>
                    <td style="font-family:monospace;font-size:0.8rem;"><?php echo htmlspecialchars($r['entity_gstin'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['vendor_invoice'] ?: '—'); ?></td>
                    <td><span class="badge" style="background:#dbeafe;color:#1e40af;"><?php echo $r['gst_rate']; ?>%</span></td>
                    <td style="text-align:right;">₹<?php echo number_format($r['taxable_amount'], 2); ?></td>
                    <td style="text-align:right;color:#059669;">₹<?php echo number_format($r['cgst'], 2); ?></td>
                    <td style="text-align:right;color:#059669;">₹<?php echo number_format($r['sgst'], 2); ?></td>
                    <td style="text-align:right;font-weight:700;">₹<?php echo number_format($r['gst_amount'], 2); ?></td>
                    <td><a href="vendor-profile.php?id=<?php echo $r['entity_id']; ?>" style="color:var(--admin-accent);">#<?php echo $r['reference_id']; ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot style="background:#f8fafc;font-weight:700;">
                <tr><td colspan="4">Totals</td><td></td>
                    <td style="text-align:right;">₹<?php echo number_format($t['taxable'], 2); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($t['cgst'], 2); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($t['sgst'], 2); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($t['gst'], 2); ?></td><td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'output' || $tab === 'output_detail'): ?>
<div class="admin-card card-no-pad">
    <div class="card-title" style="padding:1rem 1.25rem;margin:0;">
        <?php echo $tab === 'output' ? 'Sales GST Report' : 'Output GST Detailed Report'; ?>
        <span style="font-weight:400;font-size:0.8rem;color:var(--admin-muted);"><?php echo date('d-M-Y', strtotime($date_from)); ?> — <?php echo date('d-M-Y', strtotime($date_to)); ?></span>
    </div>
    <?php if (empty($output_rows)): ?>
    <div class="empty-state-modern" style="padding:2rem;text-align:center;color:var(--admin-muted);"><p>No records found.</p></div>
    <?php else: ?>
    <div class="table-wrapper" style="border:none;margin-top:0;">
        <table class="admin-table">
            <thead>
                <tr><th>Date</th><th>Customer</th><th>GSTIN</th><th>Invoice</th><th>Rate</th><th style="text-align:right;">Taxable</th><th style="text-align:right;">CGST</th><th style="text-align:right;">SGST</th><th style="text-align:right;">Total GST</th><th>Order</th></tr>
            </thead>
            <tbody>
                <?php $t = ['taxable'=>0,'gst'=>0,'cgst'=>0,'sgst'=>0]; foreach ($output_rows as $r): 
                    $t['taxable'] += floatval($r['taxable_amount']);
                    $t['gst'] += floatval($r['gst_amount']);
                    $t['cgst'] += floatval($r['cgst']);
                    $t['sgst'] += floatval($r['sgst']);
                ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($r['transaction_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($r['entity_name']); ?></strong></td>
                    <td style="font-family:monospace;font-size:0.8rem;"><?php echo htmlspecialchars($r['entity_gstin'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['invoice_number'] ?: '—'); ?></td>
                    <td><span class="badge" style="background:#fef3c7;color:#92400e;"><?php echo $r['gst_rate']; ?>%</span></td>
                    <td style="text-align:right;">₹<?php echo number_format($r['taxable_amount'], 2); ?></td>
                    <td style="text-align:right;color:#059669;">₹<?php echo number_format($r['cgst'], 2); ?></td>
                    <td style="text-align:right;color:#059669;">₹<?php echo number_format($r['sgst'], 2); ?></td>
                    <td style="text-align:right;font-weight:700;">₹<?php echo number_format($r['gst_amount'], 2); ?></td>
                    <td><a href="invoice.php?order_id=<?php echo $r['reference_id']; ?>" style="color:var(--admin-accent);">#<?php echo $r['reference_id']; ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot style="background:#f8fafc;font-weight:700;">
                <tr><td colspan="4">Totals</td><td></td>
                    <td style="text-align:right;">₹<?php echo number_format($t['taxable'], 2); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($t['cgst'], 2); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($t['sgst'], 2); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($t['gst'], 2); ?></td><td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'monthly'): ?>
<div class="admin-card card-no-pad">
    <div class="card-title" style="padding:1rem 1.25rem;margin:0;">Monthly GST Summary</div>
    <?php if (empty($monthly_data)): ?>
    <div class="empty-state-modern" style="padding:2rem;text-align:center;color:var(--admin-muted);"><p>No data available.</p></div>
    <?php else: ?>
    <div class="table-wrapper" style="border:none;margin-top:0;">
        <table class="admin-table">
            <thead><tr><th>Month</th><th style="text-align:right;">Input GST</th><th style="text-align:right;">Output GST</th><th style="text-align:right;">Net Payable</th><th style="text-align:right;">ITC</th></tr></thead>
            <tbody>
                <?php foreach ($monthly_data as $m): ?>
                <tr>
                    <td><strong><?php echo $m['month_label']; ?></strong></td>
                    <td style="text-align:right;color:#059669;">₹<?php echo number_format($m['input_gst'], 2); ?></td>
                    <td style="text-align:right;color:#dc2626;">₹<?php echo number_format($m['output_gst'], 2); ?></td>
                    <td style="text-align:right;font-weight:700;">₹<?php echo number_format($m['net_payable'], 2); ?></td>
                    <td style="text-align:right;color:#059669;">₹<?php echo number_format($m['itc'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'vendor'): ?>
<div class="admin-card card-no-pad">
    <div class="card-title" style="padding:1rem 1.25rem;margin:0;">Vendor GST Report</div>
    <?php if (empty($vendor_data)): ?>
    <div class="empty-state-modern" style="padding:2rem;text-align:center;color:var(--admin-muted);"><p>No vendor GST records found.</p></div>
    <?php else: ?>
    <?php
        $vendors_grouped = [];
        foreach ($vendor_data as $v) {
            $vid = $v['entity_id'];
            $name = getGSTEntityName($pdo, 'vendor', $vid);
            if (!isset($vendors_grouped[$vid])) $vendors_grouped[$vid] = ['name' => $name, 'taxable' => 0, 'gst' => 0, 'rate_breakdown' => []];
            $vendors_grouped[$vid]['taxable'] += floatval($v['taxable']);
            $vendors_grouped[$vid]['gst'] += floatval($v['gst']);
            $vendors_grouped[$vid]['rate_breakdown'][] = $v;
        }
    ?>
    <div class="table-wrapper" style="border:none;margin-top:0;">
        <table class="admin-table">
            <thead><tr><th>Vendor</th><th style="text-align:right;">Taxable Value</th><th style="text-align:right;">Total Input GST</th><th>Rate Breakup</th></tr></thead>
            <tbody>
                <?php foreach ($vendors_grouped as $vg): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($vg['name']); ?></strong></td>
                    <td style="text-align:right;">₹<?php echo number_format($vg['taxable'], 2); ?></td>
                    <td style="text-align:right;font-weight:700;color:#059669;">₹<?php echo number_format($vg['gst'], 2); ?></td>
                    <td style="font-size:0.8rem;">
                        <?php foreach ($vg['rate_breakdown'] as $rb): ?>
                        <span class="badge" style="background:#dbeafe;color:#1e40af;margin-right:4px;"><?php echo $rb['gst_rate']; ?>%: ₹<?php echo number_format($rb['gst'], 2); ?></span>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'customer'): ?>
<div class="admin-card card-no-pad">
    <div class="card-title" style="padding:1rem 1.25rem;margin:0;">Customer GST Report</div>
    <?php if (empty($customer_data)): ?>
    <div class="empty-state-modern" style="padding:2rem;text-align:center;color:var(--admin-muted);"><p>No customer GST records found.</p></div>
    <?php else: ?>
    <?php
        $customers_grouped = [];
        foreach ($customer_data as $c) {
            $cid = $c['entity_id'];
            $name = getGSTEntityName($pdo, 'customer', $cid);
            if (!isset($customers_grouped[$cid])) $customers_grouped[$cid] = ['name' => $name, 'taxable' => 0, 'gst' => 0, 'rate_breakdown' => []];
            $customers_grouped[$cid]['taxable'] += floatval($c['taxable']);
            $customers_grouped[$cid]['gst'] += floatval($c['gst']);
            $customers_grouped[$cid]['rate_breakdown'][] = $c;
        }
    ?>
    <div class="table-wrapper" style="border:none;margin-top:0;">
        <table class="admin-table">
            <thead><tr><th>Customer</th><th style="text-align:right;">Taxable Value</th><th style="text-align:right;">Total Output GST</th><th>Rate Breakup</th></tr></thead>
            <tbody>
                <?php foreach ($customers_grouped as $cg): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($cg['name']); ?></strong></td>
                    <td style="text-align:right;">₹<?php echo number_format($cg['taxable'], 2); ?></td>
                    <td style="text-align:right;font-weight:700;color:#dc2626;">₹<?php echo number_format($cg['gst'], 2); ?></td>
                    <td style="font-size:0.8rem;">
                        <?php foreach ($cg['rate_breakdown'] as $rb): ?>
                        <span class="badge" style="background:#fef3c7;color:#92400e;margin-right:4px;"><?php echo $rb['gst_rate']; ?>%: ₹<?php echo number_format($rb['gst'], 2); ?></span>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'liability'): ?>
<div class="admin-card card-no-pad">
    <div class="card-title" style="padding:1rem 1.25rem;margin:0;">GST Liability Report</div>
    <?php if (empty($monthly_data)): ?>
    <div class="empty-state-modern" style="padding:2rem;text-align:center;color:var(--admin-muted);"><p>No data available.</p></div>
    <?php else: ?>
    <div class="table-wrapper" style="border:none;margin-top:0;">
        <table class="admin-table">
            <thead><tr><th>Month</th><th style="text-align:right;">Output GST</th><th style="text-align:right;">Input GST</th><th style="text-align:right;">Net Payable</th></tr></thead>
            <tbody>
                <?php foreach ($monthly_data as $m): ?>
                <tr>
                    <td><strong><?php echo $m['month_label']; ?></strong></td>
                    <td style="text-align:right;">₹<?php echo number_format($m['output_gst'], 2); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($m['input_gst'], 2); ?></td>
                    <td style="text-align:right;font-weight:700;color:<?php echo $m['net_payable'] > 0 ? '#dc2626' : '#059669'; ?>;">₹<?php echo number_format($m['net_payable'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'itc'): ?>
<div class="admin-card card-no-pad">
    <div class="card-title" style="padding:1rem 1.25rem;margin:0;">Carry Forward ITC Report</div>
    <?php if (empty($monthly_data)): ?>
    <div class="empty-state-modern" style="padding:2rem;text-align:center;color:var(--admin-muted);"><p>No data available.</p></div>
    <?php else: 
        $opening_itc = 0;
    ?>
    <div class="table-wrapper" style="border:none;margin-top:0;">
        <table class="admin-table">
            <thead><tr><th>Month</th><th style="text-align:right;">Opening ITC</th><th style="text-align:right;">Input GST</th><th style="text-align:right;">Output GST</th><th style="text-align:right;">ITC Utilized</th><th style="text-align:right;">Closing ITC</th></tr></thead>
            <tbody>
                <?php foreach ($monthly_data as $m): 
                    $opening = $opening_itc;
                    $itc_utilized = min($opening + $m['input_gst'], $m['output_gst']);
                    $closing = ($opening + $m['input_gst']) - $itc_utilized;
                    $opening_itc = $closing;
                ?>
                <tr>
                    <td><strong><?php echo $m['month_label']; ?></strong></td>
                    <td style="text-align:right;">₹<?php echo number_format($opening, 2); ?></td>
                    <td style="text-align:right;color:#059669;">₹<?php echo number_format($m['input_gst'], 2); ?></td>
                    <td style="text-align:right;color:#dc2626;">₹<?php echo number_format($m['output_gst'], 2); ?></td>
                    <td style="text-align:right;">₹<?php echo number_format($itc_utilized, 2); ?></td>
                    <td style="text-align:right;font-weight:700;">₹<?php echo number_format($closing, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'return_reports'): ?>
<div class="admin-card card-no-pad">
    <div class="card-title" style="padding:1rem 1.25rem;margin:0;">GST Return Reports</div>
    <div style="padding:1.25rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <a href="gst-return-center.php" class="btn-primary" style="justify-content:center;padding:1rem;text-decoration:none;font-size:0.9rem;">📄 Return Center</a>
            <a href="gst-return-center.php" class="btn-secondary" style="justify-content:center;padding:1rem;text-decoration:none;font-size:0.9rem;">🏠 Return Center</a>
        </div>
    </div>
</div>

<?php elseif ($tab === 'reconciliation'): ?>
<?php
$recon_period = $_GET['recon_period'] ?? getCurrentGSTPeriod()['gst_period'];
$recon_fy = $_GET['recon_fy'] ?? getCurrentGSTPeriod()['financial_year'];
$recon_brand = $_GET['recon_brand'] ?? getBrandConfig()['business_key'];
$recon_periods = getGSTPeriodsInRange(date('Y-04-01'), date('Y-m-d'));
$brands = loadAllBusinessConfigs();
?>
<?php if (!empty($recon_msg)): ?><div class="alert-banner" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;"><strong>Success:</strong> <?php echo htmlspecialchars($recon_msg); ?></div><?php endif; ?>
<?php if (!empty($recon_err)): ?><div class="alert-banner" style="background:#fef2f2;border-color:#fecaca;color:#dc2626;"><strong>Error:</strong> <?php echo htmlspecialchars($recon_err); ?></div><?php endif; ?>
<div class="admin-card" style="padding:0.75rem 1rem;margin-bottom:1.25rem;">
    <form method="GET" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="tab" value="reconciliation">
        <div><label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:3px;">Brand</label>
            <select name="recon_brand" class="form-control" style="padding:0.4rem 0.65rem;width:160px;" onchange="this.form.submit()">
                <?php foreach (($brands ?: []) as $b): ?>
                <option value="<?php echo $b['business_key']; ?>" <?php echo $recon_brand === $b['business_key'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:3px;">FY</label>
            <select name="recon_fy" class="form-control" style="padding:0.4rem 0.65rem;width:100px;" onchange="this.form.submit()">
                <?php for ($y = intval(date('Y')); $y >= 2022; $y--): $fy = substr($y,2) . '-' . substr($y+1,2); ?>
                <option value="<?php echo $fy; ?>" <?php echo $recon_fy === $fy ? 'selected' : ''; ?>><?php echo $fy; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div><label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:3px;">Period</label>
            <select name="recon_period" class="form-control" style="padding:0.4rem 0.65rem;width:120px;" onchange="this.form.submit()">
                <?php foreach ($recon_periods as $p): ?>
                <option value="<?php echo $p['gst_period']; ?>" <?php echo $recon_period === $p['gst_period'] ? 'selected' : ''; ?>><?php echo $p['label']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-primary" style="padding:0.4rem 1rem;">Go</button>
    </form>
</div>
<div class="grid-kpi" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin-bottom:1.25rem;">
    <div class="stat-card" style="padding:0.6rem;"><div class="stat-info"><h4 style="font-size:0.9rem;"><?php echo $recon_summary['matched']; ?></h4><p>Matched</p></div></div>
    <div class="stat-card" style="padding:0.6rem;"><div class="stat-info"><h4 style="font-size:0.9rem;"><?php echo $recon_summary['partial']; ?></h4><p>Partial</p></div></div>
    <div class="stat-card" style="padding:0.6rem;"><div class="stat-info"><h4 style="font-size:0.9rem;"><?php echo $recon_summary['missing']; ?></h4><p>Missing</p></div></div>
    <div class="stat-card" style="padding:0.6rem;"><div class="stat-info"><h4 style="font-size:0.9rem;">₹<?php echo number_format($recon_summary['total_itc'], 2); ?></h4><p>Total ITC</p></div></div>
</div>
<div class="grid-2col" style="gap:1.5rem;">
    <div class="admin-card">
        <h3 class="card-title">Add Entry</h3>
        <form method="POST" style="display:flex;flex-direction:column;gap:0.65rem;">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="add_reconciliation">
            <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">Vendor GSTIN</label><input type="text" name="vendor_gstin" class="form-control" style="padding:0.4rem 0.6rem;width:100%;" placeholder="15-digit GSTIN" maxlength="15"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">Invoice Number</label><input type="text" name="vendor_invoice_number" class="form-control" style="padding:0.4rem 0.6rem;width:100%;"></div>
                <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">Invoice Date</label><input type="date" name="vendor_invoice_date" class="form-control" style="padding:0.4rem 0.6rem;width:100%;"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">Taxable Value</label><input type="number" name="purchase_taxable_value" class="form-control" style="padding:0.4rem 0.6rem;width:100%;" step="0.01" min="0"></div>
                <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">GST Amount</label><input type="number" name="purchase_gst_amount" class="form-control" style="padding:0.4rem 0.6rem;width:100%;" step="0.01" min="0"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">ITC Eligibility</label><select name="itc_eligibility" class="form-control" style="padding:0.4rem 0.6rem;width:100%;"><option value="eligible">Eligible</option><option value="ineligible">Ineligible</option><option value="reversal">Reversal</option></select></div>
                <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">Match Status</label><select name="match_status" class="form-control" style="padding:0.4rem 0.6rem;width:100%;"><option value="missing">Missing</option><option value="matched">Matched</option><option value="partial">Partial</option><option value="duplicate">Duplicate</option></select></div>
            </div>
            <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">ITC Amount</label><input type="number" name="itc_amount" class="form-control" style="padding:0.4rem 0.6rem;width:100%;" step="0.01" min="0"></div>
            <div><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:2px;">Notes</label><textarea name="notes" class="form-control" rows="2" style="padding:0.4rem 0.6rem;width:100%;"></textarea></div>
            <button type="submit" class="btn-primary" style="justify-content:center;">Add Entry</button>
        </form>
    </div>
    <div class="admin-card card-no-pad">
        <div class="card-title" style="padding:1rem 1.25rem;margin:0;">Entries (<?php echo count($recon_entries); ?>)</div>
        <?php if (empty($recon_entries)): ?>
        <div style="padding:2rem;text-align:center;color:var(--admin-muted);"><p>No reconciliation entries.</p></div>
        <?php else: ?>
        <div class="table-wrapper" style="max-height:500px;overflow-y:auto;">
            <table class="admin-table" style="font-size:0.78rem;">
                <thead><tr><th>Invoice</th><th>Vendor GSTIN</th><th style="text-align:right;">Taxable</th><th style="text-align:right;">GST</th><th>Match</th><th>ITC</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($recon_entries as $re): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($re['vendor_invoice_number'] ?: '—'); ?><br><span style="font-size:0.7rem;color:var(--admin-muted);"><?php echo $re['vendor_invoice_date'] ? date('d-M-Y', strtotime($re['vendor_invoice_date'])) : ''; ?></span></td>
                        <td style="font-family:monospace;"><?php echo htmlspecialchars($re['vendor_gstin'] ?: '—'); ?></td>
                        <td style="text-align:right;">₹<?php echo number_format($re['purchase_taxable_value'], 2); ?></td>
                        <td style="text-align:right;">₹<?php echo number_format($re['purchase_gst_amount'], 2); ?></td>
                        <td><span class="badge" style="background:<?php echo $re['match_status'] === 'matched' ? '#d1fae5' : ($re['match_status'] === 'partial' ? '#fef3c7' : '#fee2e2'); ?>;color:<?php echo $re['match_status'] === 'matched' ? '#059669' : ($re['match_status'] === 'partial' ? '#d97706' : '#dc2626'); ?>;"><?php echo $re['match_status']; ?></span></td>
                        <td style="text-align:right;">₹<?php echo number_format($re['itc_amount'], 2); ?></td>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this entry?')"><?php csrfField(); ?>
                                <input type="hidden" name="action" value="delete_entry">
                                <input type="hidden" name="id" value="<?php echo $re['id']; ?>">
                                <button type="submit" class="btn-secondary" style="padding:0.15rem 0.4rem;font-size:0.7rem;">✕</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<style>
.card-no-pad { padding: 0; }
.card-no-pad .table-wrapper { margin-top: 0; border-radius: 0 0 20px 20px; }
</style>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
