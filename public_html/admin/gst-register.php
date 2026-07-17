<?php
$page_title = 'GST Register';
$active_menu = 'gst_register';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gst_helper.php';

runGSTMigrations($pdo);

$tab = $_GET['tab'] ?? 'all';
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to = $_GET['to'] ?? date('Y-m-d');
$gst_rate_filter = $_GET['gst_rate'] ?? '';

// Fetch active GST rates from DB
$gst_rates = [];
try {
    $gr = $pdo->query("SELECT rate_percent FROM gst_rates WHERE is_active = 1 ORDER BY rate_percent");
    $gst_rates = $gr->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
if (empty($gst_rates)) $gst_rates = [5, 12, 18, 28];

// ── Aggregate totals for current filter (used in All, Input, Output tabs) ──
$stmt = $pdo->prepare("SELECT input_output_type, COUNT(*) as cnt, COALESCE(SUM(gst_amount),0) as total_gst, COALESCE(SUM(taxable_amount),0) as total_taxable, COALESCE(SUM(cgst),0) as total_cgst, COALESCE(SUM(sgst),0) as total_sgst FROM gst_ledger WHERE transaction_date >= ? AND transaction_date <= ? GROUP BY input_output_type");
$stmt->execute([$date_from, $date_to]);
$agg = ['input' => ['gst'=>0,'taxable'=>0,'cgst'=>0,'sgst'=>0,'count'=>0], 'output' => ['gst'=>0,'taxable'=>0,'cgst'=>0,'sgst'=>0,'count'=>0]];
while ($r = $stmt->fetch()) {
    $t = $r['input_output_type'];
    $agg[$t]['gst'] = floatval($r['total_gst']);
    $agg[$t]['taxable'] = floatval($r['total_taxable']);
    $agg[$t]['cgst'] = floatval($r['total_cgst']);
    $agg[$t]['sgst'] = floatval($r['total_sgst']);
    $agg[$t]['count'] = intval($r['cnt']);
}
$net_payable = max(0, $agg['output']['gst'] - $agg['input']['gst']);
$itc_available = max(0, $agg['input']['gst'] - $agg['output']['gst']);

// ── Rate-wise summary ──
$rate_summary = $pdo->prepare("SELECT gst_rate, input_output_type, COUNT(*) as count, COALESCE(SUM(gst_amount),0) as total_gst FROM gst_ledger WHERE transaction_date >= ? AND transaction_date <= ? GROUP BY gst_rate, input_output_type ORDER BY gst_rate");
$rate_summary->execute([$date_from, $date_to]);
$rate_rows = $rate_summary->fetchAll();
$rates_grouped = [];
foreach ($rate_rows as $r) {
    $rate = $r['gst_rate'];
    if (!isset($rates_grouped[$rate])) $rates_grouped[$rate] = ['input'=>0, 'output'=>0, 'input_count'=>0, 'output_count'=>0];
    $rates_grouped[$rate][$r['input_output_type']] = floatval($r['total_gst']);
    $rates_grouped[$rate][$r['input_output_type'].'_count'] = intval($r['count']);
}

// ── Tab-specific data ──
// All & Ledger tabs
$ledger_filters = [
    'date_from' => $date_from,
    'date_to' => $date_to,
    'page' => intval($_GET['page'] ?? 1),
    'limit' => 100,
];
if ($gst_rate_filter !== '') $ledger_filters['gst_rate'] = floatval($gst_rate_filter);
$ledger_result = getGSTLedger($pdo, $ledger_filters);

// Input tab data (merged from gst-input.php)
$input_entries = [];
$input_suppliers = [];
$input_vendors = [];
try {
    $input_filters = ['date_from' => $date_from, 'date_to' => $date_to];
    if ($gst_rate_filter !== '') $input_filters['gst_rate'] = floatval($gst_rate_filter);
    $input_entries = getGSTReportInput($pdo, $input_filters);
    $input_suppliers = $pdo->query("SELECT id, company_name, gst_number FROM cylinder_suppliers WHERE gst_number IS NOT NULL AND gst_number != '' ORDER BY company_name")->fetchAll();
    $input_vendors = $pdo->query("SELECT id, name, gst_number FROM vendors WHERE gst_number IS NOT NULL AND gst_number != '' ORDER BY name")->fetchAll();
} catch (PDOException $e) {}

// Output tab data (merged from gst-output.php)
$output_entries = [];
try {
    $output_filters = ['date_from' => $date_from, 'date_to' => $date_to];
    if ($gst_rate_filter !== '') $output_filters['gst_rate'] = floatval($gst_rate_filter);
    $output_entries = getGSTReportOutput($pdo, $output_filters);
} catch (PDOException $e) {}
$output_customers = $pdo->query("SELECT id, name, gst_number FROM customers WHERE gst_number IS NOT NULL AND gst_number != '' ORDER BY name")->fetchAll();

$tab_labels = ['all' => 'All', 'input' => 'Input', 'output' => 'Output', 'ledger' => 'Ledger'];
?>
<div class="page-header">
    <div class="page-header-title">
        <h2>GST Register</h2>
        <p>Unified view of all GST transactions</p>
    </div>
    <div class="page-header-actions">
        <a href="gst-dashboard.php" class="btn-secondary" style="text-decoration:none;">Dashboard</a>
        <a href="gst-reports.php" class="btn-secondary" style="text-decoration:none;">Reports</a>
    </div>
</div>

<!-- KPI Cards -->
<div class="gst-kpi-grid">
    <div class="stat-card gst-kpi-card">
        <div class="stat-info"><h4>₹<?php echo number_format($agg['input']['gst'], 2); ?></h4><p>Input GST · <?php echo $agg['input']['count']; ?> txns</p></div>
    </div>
    <div class="stat-card gst-kpi-card">
        <div class="stat-info"><h4>₹<?php echo number_format($agg['output']['gst'], 2); ?></h4><p>Output GST · <?php echo $agg['output']['count']; ?> txns</p></div>
    </div>
    <div class="stat-card gst-kpi-card accent">
        <div class="stat-info"><h4>₹<?php echo number_format($net_payable, 2); ?></h4><p>Net Payable</p></div>
    </div>
    <div class="stat-card gst-kpi-card success">
        <div class="stat-info"><h4>₹<?php echo number_format($itc_available, 2); ?></h4><p>ITC Available</p></div>
    </div>
</div>

<!-- Tab Bar -->
<div class="tab-bar">
    <?php foreach ($tab_labels as $key => $label): ?>
    <a href="?tab=<?php echo $key; ?>&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&gst_rate=<?php echo $gst_rate_filter; ?>" class="tab-btn <?php echo $tab === $key ? 'active' : ''; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
</div>

<!-- Common Filter Bar -->
<div class="admin-card gst-filter-card">
    <form method="GET" class="gst-filter-form">
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">
        <div>
            <label class="gst-filter-label">From</label>
            <input type="date" name="from" value="<?php echo $date_from; ?>" class="form-control gst-filter-input">
        </div>
        <div>
            <label class="gst-filter-label">To</label>
            <input type="date" name="to" value="<?php echo $date_to; ?>" class="form-control gst-filter-input">
        </div>
        <div>
            <label class="gst-filter-label">GST Rate</label>
            <select name="gst_rate" class="form-control gst-filter-select">
                <option value="">All</option>
                <?php foreach ($gst_rates as $r): ?>
                <option value="<?php echo $r; ?>" <?php echo $gst_rate_filter == $r ? 'selected' : ''; ?>><?php echo $r; ?>%</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-primary" style="padding:0.4rem 1rem;">Filter</button>
    </form>
</div>

<?php if ($tab === 'all'): ?>
<!-- ── TAB: ALL ── -->
<?php if (!empty($rates_grouped)): ?>
<div class="gst-rate-pills">
    <?php foreach ($rates_grouped as $rate => $vals): ?>
    <div class="gst-rate-pill">
        <strong><?php echo $rate; ?>% GST</strong>
        <span class="value">Input: <strong class="input">₹<?php echo number_format($vals['input'], 2); ?></strong><span class="sep">|</span>Output: <strong class="output">₹<?php echo number_format($vals['output'], 2); ?></strong></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="admin-card card-no-pad">
    <div class="gst-card-title">
        GST Ledger Entries
        <span class="subtitle"><?php echo $ledger_result['total']; ?> total entries</span>
    </div>
    <?php if (empty($ledger_result['rows'])): ?>
    <div class="gst-empty"><p>No GST entries match your filters.</p></div>
    <?php else: ?>
    <div class="table-wrapper gst-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Date</th><th>Type</th><th>Entity</th><th>Rate</th>
                    <th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Total GST</th><th>Ref</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ledger_result['rows'] as $e): ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($e['transaction_date'])); ?></td>
                    <td>
                        <?php if ($e['input_output_type'] === 'input'): ?>
                        <span class="badge badge-input">Input</span>
                        <?php else: ?>
                        <span class="badge badge-output">Output</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo htmlspecialchars($e['entity_name'] ?? getGSTEntityName($pdo, $e['entity_type'], $e['entity_id'])); ?></strong></td>
                    <td><span class="badge badge-rate"><?php echo $e['gst_rate']; ?>%</span></td>
                    <td class="num">₹<?php echo number_format($e['taxable_amount'], 2); ?></td>
                    <td class="num green">₹<?php echo number_format($e['cgst'], 2); ?></td>
                    <td class="num green">₹<?php echo number_format($e['sgst'], 2); ?></td>
                    <td class="num bold">₹<?php echo number_format($e['gst_amount'], 2); ?></td>
                    <td class="muted"><?php echo $e['reference_type']; ?> #<?php echo $e['reference_id']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($ledger_result['pages'] > 1): ?>
    <div class="gst-pagination">
        <?php for ($p = 1; $p <= $ledger_result['pages']; $p++): ?>
        <a href="?tab=all&page=<?php echo $p; ?>&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&gst_rate=<?php echo $gst_rate_filter; ?>" class="btn-secondary gst-page-btn <?php echo $p === $ledger_result['page'] ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'input'): ?>
<!-- ── TAB: INPUT ── (merged from gst-input.php) -->
<div class="admin-card card-no-pad">
    <div class="gst-card-title">
        <span>Input GST Register <span class="subtitle"><?php echo count($input_entries); ?> entries</span></span>
        <button onclick="generatePDF('input')" class="btn-primary btn-pdf"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Selected as PDF</button>
    </div>
    <?php if (empty($input_entries)): ?>
    <div class="gst-empty"><p>No input GST entries found.</p></div>
    <?php else: ?>
    <div class="table-wrapper gst-table-wrapper">
        <table class="admin-table" id="inputTable">
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="inputSelectAll" onchange="toggleAllCheckboxes('input', this.checked)"></th>
                    <th>Date</th><th>Vendor/Supplier</th><th>GSTIN</th><th>Invoice</th><th>Rate</th>
                    <th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Total GST</th>
                </tr>
            </thead>
            <tbody>
                <?php $t = ['taxable'=>0,'gst'=>0,'cgst'=>0,'sgst'=>0]; foreach ($input_entries as $r):
                    $t['taxable'] += floatval($r['taxable_amount']);
                    $t['gst'] += floatval($r['gst_amount']);
                    $t['cgst'] += floatval($r['cgst']);
                    $t['sgst'] += floatval($r['sgst']);
                ?>
                <tr>
                    <td><input type="checkbox" name="input_ids[]" value="<?php echo $r['id']; ?>" class="input-checkbox"></td>
                    <td><?php echo date('d-M-Y', strtotime($r['transaction_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($r['entity_name']); ?></strong></td>
                    <td class="mono"><?php echo htmlspecialchars($r['entity_gstin'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['vendor_invoice'] ?: '—'); ?></td>
                    <td><span class="badge badge-rate-blue"><?php echo $r['gst_rate']; ?>%</span></td>
                    <td class="num">₹<?php echo number_format($r['taxable_amount'], 2); ?></td>
                    <td class="num green">₹<?php echo number_format($r['cgst'], 2); ?></td>
                    <td class="num green">₹<?php echo number_format($r['sgst'], 2); ?></td>
                    <td class="num bold">₹<?php echo number_format($r['gst_amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="tfoot-summary">
                <tr><td colspan="5">Totals</td><td></td>
                    <td class="num">₹<?php echo number_format($t['taxable'], 2); ?></td>
                    <td class="num">₹<?php echo number_format($t['cgst'], 2); ?></td>
                    <td class="num">₹<?php echo number_format($t['sgst'], 2); ?></td>
                    <td class="num">₹<?php echo number_format($t['gst'], 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'output'): ?>
<!-- ── TAB: OUTPUT ── (merged from gst-output.php) -->
<div class="admin-card card-no-pad">
    <div class="gst-card-title">
        <span>Output GST Register <span class="subtitle"><?php echo count($output_entries); ?> entries</span></span>
        <button onclick="generatePDF('output')" class="btn-primary btn-pdf"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Selected as PDF</button>
    </div>
    <?php if (empty($output_entries)): ?>
    <div class="gst-empty"><p>No output GST entries found.</p></div>
    <?php else: ?>
    <div class="table-wrapper gst-table-wrapper">
        <table class="admin-table" id="outputTable">
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="outputSelectAll" onchange="toggleAllCheckboxes('output', this.checked)"></th>
                    <th>Date</th><th>Customer</th><th>GSTIN</th><th>Invoice</th><th>Rate</th>
                    <th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Total GST</th>
                </tr>
            </thead>
            <tbody>
                <?php $t = ['taxable'=>0,'gst'=>0,'cgst'=>0,'sgst'=>0]; foreach ($output_entries as $r):
                    $t['taxable'] += floatval($r['taxable_amount']);
                    $t['gst'] += floatval($r['gst_amount']);
                    $t['cgst'] += floatval($r['cgst']);
                    $t['sgst'] += floatval($r['sgst']);
                ?>
                <tr>
                    <td><input type="checkbox" name="output_ids[]" value="<?php echo $r['id']; ?>" class="output-checkbox"></td>
                    <td><?php echo date('d-M-Y', strtotime($r['transaction_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($r['entity_name']); ?></strong></td>
                    <td class="mono"><?php echo htmlspecialchars($r['entity_gstin'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['invoice_number'] ?: '—'); ?></td>
                    <td><span class="badge badge-rate-amber"><?php echo $r['gst_rate']; ?>%</span></td>
                    <td class="num">₹<?php echo number_format($r['taxable_amount'], 2); ?></td>
                    <td class="num green">₹<?php echo number_format($r['cgst'], 2); ?></td>
                    <td class="num green">₹<?php echo number_format($r['sgst'], 2); ?></td>
                    <td class="num bold">₹<?php echo number_format($r['gst_amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="tfoot-summary">
                <tr><td colspan="5">Totals</td><td></td>
                    <td class="num">₹<?php echo number_format($t['taxable'], 2); ?></td>
                    <td class="num">₹<?php echo number_format($t['cgst'], 2); ?></td>
                    <td class="num">₹<?php echo number_format($t['sgst'], 2); ?></td>
                    <td class="num">₹<?php echo number_format($t['gst'], 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'ledger'): ?>
<!-- ── TAB: LEDGER ── (merged from gst-ledger.php) -->
<div class="admin-card card-no-pad">
    <div class="gst-card-title">
        Detailed GST Ledger
        <span class="subtitle"><?php echo $ledger_result['total']; ?> total entries</span>
    </div>
    <?php if (empty($ledger_result['rows'])): ?>
    <div class="gst-empty"><p>No ledger entries match your filters.</p></div>
    <?php else: ?>
    <div class="table-wrapper gst-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Date</th><th>Type</th><th>Entity</th><th>Entity Type</th><th>Rate</th>
                    <th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">IGST</th>
                    <th class="num">Total GST</th><th>Ref</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ledger_result['rows'] as $e): ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($e['transaction_date'])); ?></td>
                    <td>
                        <?php if ($e['input_output_type'] === 'input'): ?>
                        <span class="badge badge-input">Input</span>
                        <?php else: ?>
                        <span class="badge badge-output">Output</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo htmlspecialchars($e['entity_name'] ?? getGSTEntityName($pdo, $e['entity_type'], $e['entity_id'])); ?></strong></td>
                    <td class="muted" style="text-transform:capitalize;"><?php echo $e['entity_type']; ?></td>
                    <td><span class="badge badge-rate"><?php echo $e['gst_rate']; ?>%</span></td>
                    <td class="num">₹<?php echo number_format($e['taxable_amount'], 2); ?></td>
                    <td class="num green">₹<?php echo number_format($e['cgst'], 2); ?></td>
                    <td class="num green">₹<?php echo number_format($e['sgst'], 2); ?></td>
                    <td class="num blue">₹<?php echo number_format($e['igst'], 2); ?></td>
                    <td class="num bold">₹<?php echo number_format($e['gst_amount'], 2); ?></td>
                    <td class="muted"><?php echo $e['reference_type']; ?> #<?php echo $e['reference_id']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($ledger_result['pages'] > 1): ?>
    <div class="gst-pagination">
        <?php for ($p = 1; $p <= $ledger_result['pages']; $p++): ?>
        <a href="?tab=ledger&page=<?php echo $p; ?>&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&gst_rate=<?php echo $gst_rate_filter; ?>" class="btn-secondary gst-page-btn <?php echo $p === $ledger_result['page'] ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function toggleAllCheckboxes(type, checked) {
    document.querySelectorAll('.' + type + '-checkbox').forEach(cb => cb.checked = checked);
}
function generatePDF(type) {
    const checked = [];
    document.querySelectorAll('.' + type + '-checkbox:checked').forEach(cb => checked.push(cb.value));
    if (checked.length === 0) { alert('Please select at least one entry.'); return; }
    const from = '<?php echo $date_from; ?>';
    const to = '<?php echo $date_to; ?>';
    window.open('gst-pdf.php?type=' + type + '&ids=' + checked.join(',') + '&from=' + from + '&to=' + to, '_blank');
}
</script>

<style>
.card-no-pad { padding: 0; }
.card-no-pad .table-wrapper { margin-top: 0; border-radius: 0 0 20px 20px; }

/* ── GST Register Design System ── */
.gst-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:1rem; margin-bottom:1.25rem; }
.gst-kpi-card { padding:0.75rem 1rem; }
.gst-kpi-card .stat-info h4 { font-size:1.1rem; }
.gst-kpi-card .stat-info p { font-size:0.78rem; }
.gst-kpi-card.accent { border-left:4px solid var(--admin-accent); }
.gst-kpi-card.success { border-left:4px solid var(--success); }

.gst-filter-card { padding:0.75rem 1rem; margin-bottom:1.25rem; }
.gst-filter-form { display:flex; gap:0.75rem; flex-wrap:wrap; align-items:end; }
.gst-filter-field { }
.gst-filter-label { font-size:0.75rem; font-weight:600; display:block; margin-bottom:3px; color:var(--admin-muted); }
.gst-filter-input { padding:0.4rem 0.65rem; width:160px; }
.gst-filter-select { padding:0.4rem 0.65rem; width:100px; }

.gst-rate-pills { display:flex; gap:1rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.gst-rate-pill { background:#f8fafc; border:1px solid var(--admin-border); border-radius:10px; padding:0.5rem 1rem; transition:box-shadow 0.15s; }
.gst-rate-pill:hover { box-shadow:0 2px 6px rgba(0,0,0,0.06); }
.gst-rate-pill strong { color:var(--admin-fg); }
.gst-rate-pill .value { margin-left:0.75rem; color:var(--admin-muted); font-size:0.85rem; }
.gst-rate-pill .value strong.input { color:#059669; }
.gst-rate-pill .value strong.output { color:#dc2626; }
.gst-rate-pill .sep { margin:0 0.3rem; color:var(--admin-border); }

.gst-empty { padding:2rem; text-align:center; color:var(--admin-muted); }
.gst-empty p { margin:0; font-size:0.88rem; }

.gst-table-wrapper { border:none; margin-top:0; }

.gst-card-title { padding:1rem 1.25rem; margin:0; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--admin-border); }
.gst-card-title .subtitle { font-weight:400; font-size:0.8rem; color:var(--admin-muted); }

.gst-pagination { padding:1rem 1.25rem; display:flex; justify-content:center; gap:0.5rem; border-top:1px solid var(--admin-border); }
.gst-page-btn { padding:0.3rem 0.7rem; font-size:0.8rem; text-decoration:none; }
.gst-page-btn.active { background:var(--admin-accent); color:#fff; border-color:var(--admin-accent); }

.badge-input { background:#f0fdf4; color:#059669; }
.badge-output { background:#fef2f2; color:#dc2626; }
.badge-rate { background:#f1f5f9; color:#475569; }
.badge-rate-blue { background:#dbeafe; color:#1e40af; }
.badge-rate-amber { background:#fef3c7; color:#92400e; }

table.admin-table td.num { text-align:right; }
table.admin-table td.mono { font-family:monospace; font-size:0.8rem; }
table.admin-table td.green { color:#059669; }
table.admin-table td.blue { color:#2563eb; }
table.admin-table td.bold { font-weight:700; }
table.admin-table td.muted { font-size:0.8rem; color:var(--admin-muted); }

.tfoot-summary { background:#f8fafc; font-weight:700; }

.btn-pdf { font-size:0.75rem; padding:0.3rem 0.7rem; display:inline-flex; align-items:center; gap:4px; }
</style>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
