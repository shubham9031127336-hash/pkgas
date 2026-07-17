<?php
$page_title = 'GST Returns';
$active_menu = 'gst_return_center';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gst_helper.php';
require_once __DIR__ . '/business_helper.php';

runGSTReturnMigrations($pdo);

$message = '';
$error = '';

$brand_key = $_GET['brand'] ?? getBrandConfig()['business_key'];
$selected_fy = $_GET['fy'] ?? getCurrentGSTPeriod()['financial_year'];
$selected_period = $_GET['period'] ?? getCurrentGSTPeriod()['gst_period'];
$active_bottom_tab = $_GET['btab'] ?? '';

$filing_cfg = getGSTFilingConfig($pdo, $brand_key);
$current_period = getGSTPeriodFromParam($selected_period);
$due_date_str = getGSTPeriodDueDate($current_period, $filing_cfg['filing_frequency'] ?? 'monthly');
$is_locked = isPeriodLocked($pdo, $brand_key, $current_period);

// ── Handle Generate (inline) ──
$gen_return_id = 0;
$gen_message = '';
$gen_error = '';
$generating = $_GET['generate'] ?? '';
$preview_id = intval($_GET['preview'] ?? 0);
$validate_id = intval($_GET['validate'] ?? 0);
$detail_id = intval($_GET['detail'] ?? 0);

if ($generating && !$is_locked) {
    try {
        $gen_brand = $_GET['gbrand'] ?? $brand_key;
        $gen_period = $_GET['gperiod'] ?? $selected_period;
        $gen_fy = $_GET['gfy'] ?? $selected_fy;
        $gen_period_arr = getGSTPeriodFromParam($gen_period);
        if ($generating === 'gstr1') {
            $gen_return_id = generateGSTR1($pdo, $gen_brand, $gen_period_arr, $_SESSION['username'] ?? 'admin');
        } elseif ($generating === 'gstr3b') {
            $gen_return_id = generateGSTR3B($pdo, $gen_brand, $gen_period_arr, $_SESSION['username'] ?? 'admin');
        } else {
            $gen_error = 'Unsupported return type.';
        }
        if ($gen_return_id > 0) {
            $validation = validateGSTReturn($pdo, $gen_return_id);
            $gen_message = strtoupper(str_replace('_', ' ', $generating)) . ' generated' . ($validation['total_errors'] === 0 ? ' and validated successfully.' : ' with ' . $validation['total_errors'] . ' errors.');
        }
    } catch (Exception $e) {
        $gen_error = 'Generation failed: ' . $e->getMessage();
    }
}

// ── Handle Mark as Filed ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_filed') {
    $return_id = intval($_POST['return_id'] ?? 0);
    $filing_ref = trim($_POST['filing_reference'] ?? '');
    try {
        $stmt = $pdo->prepare("UPDATE gst_returns SET status='filed', filed_date=NOW(), filed_by=?, filing_reference=? WHERE id=? AND status IN ('ready_for_filing','validated','draft')");
        $stmt->execute([$_SESSION['username'] ?? 'admin', $filing_ref, $return_id]);
        lockGSTPeriod($pdo, $brand_key, $current_period, $_SESSION['username'] ?? 'admin');
        $message = 'Return filed successfully. Period locked.';
    } catch (PDOException $e) {
        $error = 'Failed to file return: ' . $e->getMessage();
    }
}

// ── Return type config ──
$return_types = [];
if (($filing_cfg['gst_registration_type'] ?? 'regular') === 'regular') {
    $return_types = [
        ['type' => 'gstr1', 'label' => 'GSTR-1', 'desc' => 'Outward Supply Return', 'icon' => 'polyline'],
        ['type' => 'gstr3b', 'label' => 'GSTR-3B', 'desc' => 'Summary Return', 'icon' => 'file'],
        ['type' => 'gstr2b', 'label' => 'GSTR-2B Recon', 'desc' => 'Purchase Reconciliation', 'icon' => 'search'],
    ];
    if (!empty($filing_cfg['gstr9_enabled'])) $return_types[] = ['type' => 'gstr9', 'label' => 'GSTR-9', 'desc' => 'Annual Return', 'icon' => 'calendar'];
} elseif (($filing_cfg['gst_registration_type'] ?? '') === 'composition') {
    $return_types = [];
    if (!empty($filing_cfg['cmp08_enabled'])) $return_types[] = ['type' => 'cmp08', 'label' => 'CMP-08', 'desc' => 'Composition Payment', 'icon' => 'file'];
    if (!empty($filing_cfg['gstr4_enabled'])) $return_types[] = ['type' => 'gstr4', 'label' => 'GSTR-4', 'desc' => 'Composition Return', 'icon' => 'file'];
}
foreach (['gstr4','gstr6','gstr7','gstr8','cmp08','gstr9'] as $t) {
    $cfg_key = $t . '_enabled';
    if (!empty($filing_cfg[$cfg_key]) && !in_array($t, array_column($return_types, 'type'))) {
        $label_map = ['gstr4'=>'GSTR-4','gstr6'=>'GSTR-6','gstr7'=>'GSTR-7','gstr8'=>'GSTR-8','cmp08'=>'CMP-08','gstr9'=>'GSTR-9'];
        $desc_map = ['gstr4'=>'Composition','gstr6'=>'ISD','gstr7'=>'TDS','gstr8'=>'TCS','cmp08'=>'Composition Payment','gstr9'=>'Annual Return'];
        $return_types[] = ['type' => $t, 'label' => $label_map[$t], 'desc' => $desc_map[$t], 'icon' => 'file'];
    }
}

// ── Load existing returns ──
$existing_returns = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE business_key=? AND financial_year=? AND gst_period=? ORDER BY return_type, version DESC");
    $stmt->execute([$brand_key, $selected_fy, $selected_period]);
    while ($r = $stmt->fetch()) {
        $existing_returns[$r['return_type']][] = $r;
    }
} catch (PDOException $e) {}

// ── Order & vendor counts ──
$order_counts = ['total' => 0, 'excluded' => 0, 'filed' => 0];
$vendor_counts = ['batches' => 0, 'invoices' => 0, 'purchases' => 0];
try {
    $month_start = sprintf('%04d-%02d-01', $current_period['year'], $current_period['month']);
    $month_end = date('Y-m-t', strtotime($month_start));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM refill_orders WHERE business_name=? AND DATE(order_date)>=? AND DATE(order_date)<=?");
    $stmt->execute([$brand_key, $month_start, $month_end]);
    $order_counts['total'] = intval($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM refill_orders WHERE business_name=? AND DATE(order_date)>=? AND DATE(order_date)<=? AND (include_in_gst_return=0 OR include_in_gst_return IS NULL)");
    $stmt->execute([$brand_key, $month_start, $month_end]);
    $order_counts['excluded'] = intval($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM refill_orders WHERE business_name=? AND DATE(order_date)>=? AND DATE(order_date)<=? AND gst_status='filed'");
    $stmt->execute([$brand_key, $month_start, $month_end]);
    $order_counts['filed'] = intval($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_refill_batches WHERE DATE(received_date)>=? AND DATE(received_date)<=?");
    $stmt->execute([$month_start, $month_end]);
    $vendor_counts['batches'] = intval($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_invoices WHERE DATE(invoice_date)>=? AND DATE(invoice_date)<=?");
    $stmt->execute([$month_start, $month_end]);
    $vendor_counts['invoices'] = intval($stmt->fetchColumn());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinder_purchases WHERE DATE(purchase_date)>=? AND DATE(purchase_date)<=?");
    $stmt->execute([$month_start, $month_end]);
    $vendor_counts['purchases'] = intval($stmt->fetchColumn());
} catch (PDOException $e) {}

$brands = loadAllBusinessConfigs();
$brand_label = '—';
foreach (($brands ?: []) as $b) { if ($b['business_key'] === $brand_key) $brand_label = $b['label']; }

// ── Preview data (if requested) ──
$preview_return = null;
$preview_summary = [];
$preview_sections = [];
$preview_section_totals = [];
if ($preview_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE id = ?");
    $stmt->execute([$preview_id]);
    $preview_return = $stmt->fetch();
    if ($preview_return) {
        $preview_summary = $preview_return['summary_data'] ? json_decode($preview_return['summary_data'], true) : [];
        $preview_sections = getReturnSections($pdo, $preview_id);
        foreach ($preview_sections as $sec) {
            $items = getReturnItemsBySection($pdo, $preview_id, $sec);
            $taxable = array_sum(array_map(fn($i) => floatval($i['taxable_value']), $items));
            $gst = array_sum(array_map(fn($i) => floatval($i['total_gst']), $items));
            $count = count($items);
            $preview_section_totals[$sec] = ['items' => $items, 'taxable' => $taxable, 'gst' => $gst, 'count' => $count];
        }
    }
}

// ── Validation data (if requested) ──
$validation_result = null;
$validation_errors = [];
$validation_error_types = [];
if ($validate_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE id = ?");
    $stmt->execute([$validate_id]);
    $v_return = $stmt->fetch();
    if ($v_return) {
        $validation_result = validateGSTReturn($pdo, $validate_id);
        $validation_errors = $validation_result['errors'] ?? [];
        foreach ($validation_errors as $e) {
            $validation_error_types[$e['type']][] = $e;
        }
    }
}

// ── Detail data (if requested) ──
$detail_return = null;
$detail_versions = [];
$detail_amendments = [];
if ($detail_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE id = ?");
    $stmt->execute([$detail_id]);
    $detail_return = $stmt->fetch();
    if ($detail_return) {
        $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE business_key=? AND return_type=? AND financial_year=? AND gst_period=? ORDER BY version DESC");
        $stmt->execute([$detail_return['business_key'], $detail_return['return_type'], $detail_return['financial_year'], $detail_return['gst_period']]);
        $detail_versions = $stmt->fetchAll();
        try {
            $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE reference_id = ? ORDER BY amended_at DESC LIMIT 50");
            $stmt->execute([$detail_id]);
            $detail_amendments = $stmt->fetchAll();
        } catch (PDOException $e) {}
    }
}

// ── Filing History data (bottom section) ──
$filing_history_returns = [];
$filing_history_vr = [];
$filed_vs_pending = [];
$cdnr_items = [];
if ($active_bottom_tab) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE business_key=? AND financial_year=? ORDER BY created_at DESC");
        $stmt->execute([$brand_key, $selected_fy]);
        $filing_history_returns = $stmt->fetchAll();
        // Validation report — flatten errors
        foreach ($filing_history_returns as $fr) {
            if ($fr['validation_results']) {
                $vr = json_decode($fr['validation_results'], true);
                if (!empty($vr['errors'])) {
                    foreach ($vr['errors'] as $ve) {
                        $ve['_return_number'] = $fr['return_number'];
                        $ve['_return_type'] = $fr['return_type'];
                        $filing_history_vr[] = $ve;
                    }
                }
            }
        }
        // Filed vs Pending
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(order_date, '%Y-%m') as ym, COUNT(*) as total, SUM(CASE WHEN gst_status='filed' THEN 1 ELSE 0 END) as filed_count FROM refill_orders WHERE business_name=? AND financial_year=? GROUP BY ym ORDER BY ym");
        $stmt->execute([$brand_key, $selected_fy]);
        $filed_vs_pending = $stmt->fetchAll();
        // CDNR
        $stmt = $pdo->prepare("SELECT ri.*, r.return_type, r.return_number FROM gst_return_items ri JOIN gst_returns r ON ri.gst_return_id = r.id WHERE ri.section='cdnr' AND r.business_key=? AND r.financial_year=? ORDER BY ri.created_at DESC");
        $stmt->execute([$brand_key, $selected_fy]);
        $cdnr_items = $stmt->fetchAll();
    } catch (PDOException $e) {}
}
?>
<div class="page-header">
    <div class="page-header-title">
        <h2>GST Returns</h2>
        <p><?php echo htmlspecialchars($brand_label); ?> — <?php echo date('M Y', strtotime($current_period['year'] . '-' . $current_period['month'] . '-01')); ?></p>
    </div>
    <div class="page-header-actions">
        <form method="GET" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <select name="brand" class="form-control" style="width:auto;padding:0.4rem 0.75rem;" onchange="this.form.submit()">
                <?php foreach (($brands ?: []) as $b): ?>
                <option value="<?php echo $b['business_key']; ?>" <?php echo $brand_key === $b['business_key'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="fy" class="form-control" style="width:auto;padding:0.4rem 0.75rem;" onchange="this.form.submit()">
                <?php for ($y = intval(date('Y')); $y >= 2022; $y--): $fy = substr($y,2) . '-' . substr($y+1,2); ?>
                <option value="<?php echo $fy; ?>" <?php echo $selected_fy === $fy ? 'selected' : ''; ?>><?php echo $fy; ?></option>
                <?php endfor; ?>
            </select>
            <select name="period" class="form-control" style="width:auto;padding:0.4rem 0.75rem;" onchange="this.form.submit()">
                <?php foreach (getGSTPeriodsInRange(date('Y-04-01'), date('Y-m-d')) as $p): ?>
                <option value="<?php echo $p['gst_period']; ?>" <?php echo $selected_period === $p['gst_period'] ? 'selected' : ''; ?>><?php echo $p['label']; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($gen_message): ?>
<div class="gst-alert gst-alert-success">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    <div><strong>Success:</strong> <?php echo htmlspecialchars($gen_message); ?>
    <?php if ($gen_return_id > 0): ?>
    <div class="gst-alert-actions">
        <a href="?preview=<?php echo $gen_return_id; ?>&brand=<?php echo urlencode($brand_key); ?>&period=<?php echo $selected_period; ?>&fy=<?php echo $selected_fy; ?>" class="btn-primary">Preview</a>
        <a href="?validate=<?php echo $gen_return_id; ?>&brand=<?php echo urlencode($brand_key); ?>&period=<?php echo $selected_period; ?>&fy=<?php echo $selected_fy; ?>" class="btn-secondary">View Validation</a>
    </div>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php if ($gen_error): ?>
<div class="gst-alert gst-alert-error">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <div><strong>Error:</strong> <?php echo htmlspecialchars($gen_error); ?>
        <div style="margin-top:6px;font-size:0.74rem;color:var(--admin-muted);">
            This may be because: (a) the GST ledger is out of sync — try creating/saving an order first,
            (b) vendor purchase records are missing GST values, or (c) no eligible orders exist for this period.
            <a href="gst-settlement.php?brand=<?php echo urlencode($brand_key); ?>" style="color:var(--admin-accent);font-weight:600;">Check settlement page</a>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ($message): ?>
<div class="gst-alert gst-alert-success">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    <div><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></div>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="gst-alert gst-alert-error">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <div><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div>
</div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="gst-kpi-grid">
    <div class="stat-card">
        <div class="icon-glow-amber"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="stat-info"><h4 style="text-transform:capitalize;"><?php echo $filing_cfg['filing_frequency'] ?? 'monthly'; ?></h4><p>Filing Frequency</p></div>
    </div>
    <div class="stat-card">
        <div class="icon-glow-blue"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        <div class="stat-info"><h4><?php echo date('d-M-Y', strtotime($due_date_str)); ?></h4><p>Return Due Date</p></div>
    </div>
    <div class="stat-card">
        <div class="icon-glow-<?php echo $is_locked ? 'purple' : 'green'; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><?php echo $is_locked ? '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>' : '<polyline points="20 6 9 17 4 12"/>'; ?></svg></div>
        <div class="stat-info"><h4><?php echo $is_locked ? 'Locked' : 'Open'; ?></h4><p>Period Status</p></div>
    </div>
    <div class="stat-card">
        <div class="icon-glow-blue"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <div class="stat-info"><h4><?php echo $order_counts['total']; ?> (<?php echo $order_counts['filed']; ?> filed)</h4><p>Invoices</p></div>
    </div>
</div>

<!-- Return Type Cards -->
<?php if (empty($return_types)): ?>
<div class="gst-empty-state" style="border:1px solid var(--admin-border);border-radius:20px;background:var(--admin-surface);">
    <p>No GST return types enabled for this registration type.</p>
    <a href="gst-filing-config.php" class="btn-primary" style="display:inline-flex;margin-top:1rem;">Configure Filing Settings</a>
</div>
<?php else: ?>
<?php foreach ($return_types as $rt):
    $type = $rt['type'];
    $returns = $existing_returns[$type] ?? [];
    $latest = $returns[0] ?? null;
    $status = $latest ? $latest['status'] : 'draft';
    $version = $latest ? $latest['version'] : 0;
    $gen_date = $latest ? $latest['generation_date'] : null;
    $filed_date = $latest ? $latest['filed_date'] : null;
    $return_id = $latest ? $latest['id'] : 0;

    $v_errors = 0; $v_warnings = 0;
    if ($latest && $latest['validation_results']) {
        $vr = json_decode($latest['validation_results'], true);
        $v_errors = intval($vr['total_errors'] ?? 0);
        $v_warnings = intval($vr['total_warnings'] ?? 0);
    }
    $ready = ($status === 'validated' || $status === 'ready_for_filing') && $v_errors === 0;
    $progress_pct = $status === 'draft' ? 10 : ($status === 'validated' ? 40 : ($status === 'ready_for_filing' ? 60 : ($status === 'filed' ? 100 : ($status === 'amended' ? 5 : 0))));
    $status_color = 'var(--admin-muted)';
    $status_bg = '#f1f5f9';
    if ($status === 'validated') { $status_color = '#059669'; $status_bg = '#d1fae5'; }
    elseif ($status === 'ready_for_filing') { $status_color = '#2563eb'; $status_bg = '#dbeafe'; }
    elseif ($status === 'filed') { $status_color = '#1e293b'; $status_bg = '#e2e8f0'; }
    elseif ($status === 'amended') { $status_color = '#d97706'; $status_bg = '#fef3c7'; }
    elseif ($status === 'rejected') { $status_color = '#dc2626'; $status_bg = '#fee2e2'; }

    $is_previewing = ($preview_id > 0 && $preview_return && $preview_return['id'] == $return_id);
    $is_validating = ($validate_id > 0 && $validation_result && isset($v_return) && $v_return && $v_return['id'] == $return_id);
    $is_detailing = ($detail_id > 0 && $detail_return && $detail_return['id'] == $return_id);
?>
<div class="gst-return-card" style="border-left:4px solid <?php echo $status_color; ?>;">
    <div class="gst-return-card-body">
        <div class="gst-return-card-header">
            <div>
                <div class="gst-return-title"><?php echo $rt['label']; ?>
                    <span class="gst-return-title-desc"><?php echo $rt['desc']; ?></span>
                </div>
                <div class="gst-return-meta">
                    <span>Period: <strong><?php echo date('M Y', strtotime($current_period['year'] . '-' . $current_period['month'] . '-01')); ?></strong></span>
                    <span>Due: <strong><?php echo date('d-M-Y', strtotime($due_date_str)); ?></strong></span>
                    <span>Version: <strong><?php echo $version; ?></strong></span>
                    <?php if ($filed_date): ?><span>Filed: <strong><?php echo date('d-M-Y', strtotime($filed_date)); ?></strong></span><?php endif; ?>
                </div>
                <div class="gst-return-status-row">
                    <span class="badge-status badge-status-<?php echo $status; ?>">● <?php echo str_replace('_', ' ', $status); ?></span>
                    <?php if ($v_errors > 0): ?><span style="color:#dc2626;font-weight:700;font-size:0.72rem;display:flex;align-items:center;gap:3px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php echo $v_errors; ?> errors</span><?php elseif ($v_warnings > 0): ?><span style="color:#d97706;font-weight:600;font-size:0.72rem;display:flex;align-items:center;gap:3px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><?php echo $v_warnings; ?> warnings</span><?php endif; ?>
                    <span style="font-size:0.72rem;color:<?php echo $ready ? '#059669' : 'var(--admin-muted)'; ?>;font-weight:600;display:flex;align-items:center;gap:3px;"><?php if ($ready): ?><svg viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Ready for Filing<?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Not Ready<?php endif; ?></span>
                </div>
            </div>
            <div class="gst-return-actions">
                <div class="btn-row">
                    <?php if (!$is_locked && $type !== 'gstr2b'): ?>
                    <a href="?generate=<?php echo $type; ?>&gbrand=<?php echo urlencode($brand_key); ?>&gperiod=<?php echo $selected_period; ?>&gfy=<?php echo $selected_fy; ?>&brand=<?php echo urlencode($brand_key); ?>&period=<?php echo $selected_period; ?>&fy=<?php echo $selected_fy; ?>" class="btn-secondary" onclick="return confirm('Generate <?php echo $rt['label']; ?> from all eligible invoices? Existing drafts will be replaced.')">Generate</a>
                    <?php endif; ?>
                    <?php if ($return_id > 0): ?>
                    <a href="?validate=<?php echo $return_id; ?>&brand=<?php echo urlencode($brand_key); ?>&period=<?php echo $selected_period; ?>&fy=<?php echo $selected_fy; ?>" class="btn-secondary">Validate</a>
                    <a href="?preview=<?php echo $return_id; ?>&brand=<?php echo urlencode($brand_key); ?>&period=<?php echo $selected_period; ?>&fy=<?php echo $selected_fy; ?>" class="btn-secondary">Preview</a>
                    <a href="gst-json-export.php?return_id=<?php echo $return_id; ?>" class="btn-secondary" download>JSON</a>
                    <a href="?detail=<?php echo $return_id; ?>&brand=<?php echo urlencode($brand_key); ?>&period=<?php echo $selected_period; ?>&fy=<?php echo $selected_fy; ?>" class="btn-secondary">Details</a>
                    <?php if (!$is_locked && $ready): ?>
                    <form method="POST" onsubmit="return confirm('Mark this return as filed? Period will be locked.')"><?php csrfField(); ?>
                        <input type="hidden" name="action" value="mark_filed">
                        <input type="hidden" name="return_id" value="<?php echo $return_id; ?>">
                        <input type="text" name="filing_reference" placeholder="ARN/Ref">
                        <button type="submit" class="btn-primary">File</button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="gst-progress">
                    <div class="gst-progress-labels"><span>Progress</span><span><?php echo $progress_pct; ?>%</span></div>
                    <div class="gst-progress-track"><div class="gst-progress-fill" style="width:<?php echo $progress_pct; ?>%;background:<?php echo $status_color; ?>;"></div></div>
                </div>
            </div>
        </div>
        <?php if ($latest && $latest['summary_data']): $summary = json_decode($latest['summary_data'], true); if ($summary): ?>
        <div class="gst-summary-row">
            <?php if (isset($summary['total_invoices'])): ?><span>Total Invoices: <strong><?php echo $summary['total_invoices']; ?></strong></span><?php endif; ?>
            <?php if (isset($summary['total_taxable'])): ?><span>Taxable Value: <strong>₹<?php echo number_format($summary['total_taxable'], 2); ?></strong></span><?php endif; ?>
            <?php if (isset($summary['total_gst'])): ?><span>Total GST: <strong>₹<?php echo number_format($summary['total_gst'], 2); ?></strong></span><?php endif; ?>
            <?php if (isset($summary['total_b2b'])): ?><span>B2B: <strong><?php echo $summary['total_b2b']; ?></strong></span><?php endif; ?>
            <?php if (isset($summary['total_b2c'])): ?><span>B2C: <strong><?php echo $summary['total_b2c']; ?></strong></span><?php endif; ?>
            <?php if (isset($summary['net_gst_liability'])): ?><span>Net Liability: <strong>₹<?php echo number_format($summary['net_gst_liability'], 2); ?></strong></span><?php endif; ?>
            <?php if (isset($summary['carry_forward_itc'])): ?><span>Carry Forward ITC: <strong>₹<?php echo number_format($summary['carry_forward_itc'], 2); ?></strong></span><?php endif; ?>
        </div>
        <?php endif; endif; ?>

        <!-- ── INLINE PREVIEW SECTION ── -->
        <?php if ($is_previewing && $preview_return): ?>
        <div class="gst-inline-section">
            <div class="gst-inline-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Preview: <?php echo strtoupper(str_replace('_', ' ', $preview_return['return_type'])); ?> #<?php echo htmlspecialchars($preview_return['return_number']); ?>
            </div>
            <?php if ($preview_summary): ?>
            <div class="gst-kpi-grid" style="margin-bottom:1rem;">
                <?php if ($preview_return['return_type'] === 'gstr1'): ?>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;"><?php echo $preview_summary['total_b2b'] ?? 0; ?></h4><p>B2B</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;"><?php echo $preview_summary['total_b2c'] ?? 0; ?></h4><p>B2C</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;">₹<?php echo number_format($preview_summary['total_taxable'] ?? 0, 2); ?></h4><p>Taxable</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;">₹<?php echo number_format($preview_summary['total_gst'] ?? 0, 2); ?></h4><p>Total GST</p></div></div>
                <?php elseif ($preview_return['return_type'] === 'gstr3b'): ?>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;">₹<?php echo number_format($preview_summary['outward_taxable_supplies'] ?? 0, 2); ?></h4><p>Outward</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;">₹<?php echo number_format($preview_summary['itc_eligible'] ?? 0, 2); ?></h4><p>ITC Eligible</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;color:<?php echo ($preview_summary['net_gst_liability'] ?? 0) > 0 ? '#dc2626' : '#059669'; ?>;">₹<?php echo number_format($preview_summary['net_gst_liability'] ?? 0, 2); ?></h4><p>Net Liability</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;">₹<?php echo number_format($preview_summary['carry_forward_itc'] ?? 0, 2); ?></h4><p>Carry Fwd ITC</p></div></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="gst-section-tabs">
                <?php $active_sec = $_GET['section'] ?? ($preview_sections[0] ?? ''); ?>
                <?php foreach ($preview_sections as $sec): $tot = $preview_section_totals[$sec] ?? []; ?>
                <a href="?preview=<?php echo $preview_id; ?>&section=<?php echo $sec; ?>&brand=<?php echo urlencode($brand_key); ?>&period=<?php echo $selected_period; ?>&fy=<?php echo $selected_fy; ?>" class="gst-section-tab <?php echo $active_sec === $sec ? 'active' : ''; ?>"><?php echo strtoupper($sec); ?> <span class="count">(<?php echo $tot['count'] ?? 0; ?>)</span></a>
                <?php endforeach; ?>
            </div>
            <?php if ($active_sec && isset($preview_section_totals[$active_sec])): $sec_data = $preview_section_totals[$active_sec]; ?>
            <div class="table-wrapper" style="max-height:400px;overflow-y:auto;">
                <?php if (in_array($active_sec, ['outward_by_rate','itc_by_rate'])): ?>
                <table class="admin-table">
                    <thead><tr><th>Rate</th><th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">IGST</th><th class="num">Total GST</th><th class="num">Total</th></tr></thead>
                    <tbody><?php foreach ($sec_data['items'] as $it): ?><tr><td><?php echo floatval($it['gst_rate']); ?>%</td><td class="num">₹<?php echo number_format($it['taxable_value'], 2); ?></td><td class="num cgst">₹<?php echo number_format($it['cgst'], 2); ?></td><td class="num sgst">₹<?php echo number_format($it['sgst'], 2); ?></td><td class="num igst">₹<?php echo number_format($it['igst'], 2); ?></td><td class="num total-gst">₹<?php echo number_format($it['total_gst'], 2); ?></td><td class="num">₹<?php echo number_format($it['total_value'], 2); ?></td></tr><?php endforeach; ?></tbody>
                </table>
                <?php else: ?>
                <table class="admin-table">
                    <thead><tr><th>#</th><th>Invoice</th><th>Customer</th><th>GSTIN</th><th>HSN</th><th class="num">Taxable</th><th class="num">Rate</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">IGST</th><th class="num">Total GST</th></tr></thead>
                    <tbody><?php $i=0; foreach ($sec_data['items'] as $it): $i++; ?><tr><td><?php echo $i; ?></td><td><?php echo htmlspecialchars($it['invoice_number'] ?: '#' . $it['reference_id']); ?></td><td><?php echo htmlspecialchars($it['customer_name'] ?: '—'); ?></td><td class="mono"><?php echo htmlspecialchars($it['customer_gstin'] ?: '—'); ?></td><td class="mono"><?php echo htmlspecialchars($it['hsn_code'] ?: '—'); ?></td><td class="num">₹<?php echo number_format($it['taxable_value'], 2); ?></td><td class="num"><?php echo floatval($it['gst_rate']); ?>%</td><td class="num cgst">₹<?php echo number_format($it['cgst'], 2); ?></td><td class="num sgst">₹<?php echo number_format($it['sgst'], 2); ?></td><td class="num igst">₹<?php echo number_format($it['igst'], 2); ?></td><td class="num total-gst">₹<?php echo number_format($it['total_gst'], 2); ?></td></tr><?php endforeach; ?></tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── INLINE VALIDATION SECTION ── -->
        <?php if ($is_validating && $validation_result !== null): ?>
        <div class="gst-inline-section">
            <div class="gst-inline-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Validation: <?php echo strtoupper(str_replace('_', ' ', $v_return['return_type'])); ?> #<?php echo htmlspecialchars($v_return['return_number']); ?>
            </div>
            <div class="gst-kpi-grid" style="margin-bottom:1rem;">
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;color:<?php echo $validation_result['total_errors'] > 0 ? '#dc2626' : '#059669'; ?>;"><?php echo $validation_result['total_errors']; ?></h4><p>Errors</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;"><?php echo $validation_result['total_warnings']; ?></h4><p>Warnings</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;"><?php echo count($validation_errors); ?></h4><p>Total Issues</p></div></div>
                <div class="stat-card" style="padding:0.5rem;"><div class="stat-info"><h4 style="font-size:0.9rem;text-transform:capitalize;"><?php echo $v_return['status']; ?></h4><p>Status</p></div></div>
            </div>
            <?php if ($validation_result['total_errors'] === 0 && $validation_result['total_warnings'] === 0): ?>
            <div class="gst-alert gst-alert-success" style="margin-bottom:0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <div><strong>Validation Passed</strong> — No errors or warnings.</div>
            </div>
            <?php else: ?>
            <?php foreach ($validation_error_types as $vtype => $vtype_errors): $is_err = $vtype_errors[0]['severity'] === 'error'; ?>
            <div class="gst-validation-group <?php echo $is_err ? 'error' : 'warning'; ?>">
                <div class="gst-validation-group-title" style="color:<?php echo $is_err ? '#dc2626' : '#d97706'; ?>;"><?php echo strtoupper(str_replace('_', ' ', $vtype)); ?> (<?php echo count($vtype_errors); ?>)</div>
                <?php foreach ($vtype_errors as $ve): ?>
                <div class="gst-validation-item"><span><strong><?php echo htmlspecialchars($ve['msg']); ?></strong></span><?php if (!empty($ve['inv'])): ?><span class="ref">Inv: <?php echo htmlspecialchars($ve['inv']); ?></span><?php endif; ?></div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── INLINE DETAILS SECTION ── -->
        <?php if ($is_detailing && $detail_return): ?>
        <div class="gst-inline-section">
            <div class="gst-inline-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 16 12 12 12 8"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Details: <?php echo strtoupper(str_replace('_', ' ', $detail_return['return_type'])); ?> V<?php echo $detail_return['version']; ?>
            </div>
            <div class="grid-2col">
                <div class="gst-sub-panel">
                    <h5>Return Metadata</h5>
                    <table class="gst-meta-table"><tbody>
                        <tr><td><strong>Return #</strong></td><td><?php echo htmlspecialchars($detail_return['return_number']); ?></td></tr>
                        <tr><td><strong>Type</strong></td><td><?php echo strtoupper($detail_return['return_type']); ?></td></tr>
                        <tr><td><strong>FY</strong></td><td><?php echo $detail_return['financial_year']; ?></td></tr>
                        <tr><td><strong>Period</strong></td><td><?php echo $detail_return['gst_period']; ?></td></tr>
                        <tr><td><strong>Version</strong></td><td><?php echo $detail_return['version']; ?></td></tr>
                        <tr><td><strong>Status</strong></td><td><span class="badge-status badge-status-<?php echo $detail_return['status']; ?>"><?php echo str_replace('_', ' ', $detail_return['status']); ?></span></td></tr>
                        <tr><td><strong>Generated</strong></td><td><?php echo $detail_return['generation_date'] ? date('d-M-Y H:i', strtotime($detail_return['generation_date'])) : '—'; ?></td></tr>
                        <?php if ($detail_return['filed_date']): ?><tr><td><strong>Filed</strong></td><td><?php echo date('d-M-Y H:i', strtotime($detail_return['filed_date'])); ?></td></tr><?php endif; ?>
                        <?php if ($detail_return['filing_reference']): ?><tr><td><strong>Ref</strong></td><td class="mono"><?php echo htmlspecialchars($detail_return['filing_reference']); ?></td></tr><?php endif; ?>
                    </tbody></table>
                </div>
                <div class="gst-sub-panel">
                    <h5>Version History</h5>
                    <?php if (empty($detail_versions)): ?><p style="font-size:0.76rem;color:var(--admin-muted);">No version history.</p><?php else: ?>
                    <?php foreach ($detail_versions as $dv): ?>
                    <div class="gst-version-item <?php echo $dv['id'] == $detail_id ? 'active' : ''; ?>">
                        <span><strong>V<?php echo $dv['version']; ?></strong> <span class="date"><?php echo date('d-M-Y', strtotime($dv['generation_date'] ?: $dv['created_at'])); ?></span></span>
                        <span><span class="badge-status badge-status-<?php echo $dv['status']; ?>" style="font-size:0.58rem;"><?php echo $dv['status']; ?></span></span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($detail_amendments)): ?>
            <div class="gst-sub-panel" style="margin-top:1rem;">
                <h5>Amendment Log</h5>
                <div class="table-wrapper" style="max-height:200px;overflow-y:auto;">
                    <table style="width:100%;font-size:0.74rem;">
                        <thead><tr><th>Date</th><th>Field</th><th>Old</th><th>New</th><th>By</th></tr></thead>
                        <tbody><?php foreach ($detail_amendments as $a): ?><tr><td style="padding:4px;"><?php echo date('d-M-Y', strtotime($a['amended_at'])); ?></td><td style="padding:4px;"><?php echo htmlspecialchars($a['field_name'] ?: '—'); ?></td><td style="padding:4px;color:#dc2626;font-family:monospace;"><?php echo htmlspecialchars($a['old_value'] ?: '—'); ?></td><td style="padding:4px;color:#059669;font-family:monospace;"><?php echo htmlspecialchars($a['new_value'] ?: '—'); ?></td><td style="padding:4px;"><?php echo htmlspecialchars($a['amended_by'] ?: '—'); ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ── BOTTOM SECTIONS: Filing History, CDNR, etc. ── -->
<div style="margin-top:2rem;">
    <div class="gst-bottom-tabs">
        <a href="?btab=filing_history&brand=<?php echo urlencode($brand_key); ?>&fy=<?php echo $selected_fy; ?>&period=<?php echo $selected_period; ?>" class="gst-bottom-tab <?php echo $active_bottom_tab === 'filing_history' ? 'active' : ''; ?>">Filing History</a>
        <a href="?btab=validation_report&brand=<?php echo urlencode($brand_key); ?>&fy=<?php echo $selected_fy; ?>&period=<?php echo $selected_period; ?>" class="gst-bottom-tab <?php echo $active_bottom_tab === 'validation_report' ? 'active' : ''; ?>">Validation Report</a>
        <a href="?btab=filed_vs_pending&brand=<?php echo urlencode($brand_key); ?>&fy=<?php echo $selected_fy; ?>&period=<?php echo $selected_period; ?>" class="gst-bottom-tab <?php echo $active_bottom_tab === 'filed_vs_pending' ? 'active' : ''; ?>">Filed vs Pending</a>
        <a href="?btab=cdnr&brand=<?php echo urlencode($brand_key); ?>&fy=<?php echo $selected_fy; ?>&period=<?php echo $selected_period; ?>" class="gst-bottom-tab <?php echo $active_bottom_tab === 'cdnr' ? 'active' : ''; ?>">CDNR Register</a>
    </div>

    <?php if ($active_bottom_tab === 'filing_history'): ?>
    <div class="admin-card card-no-pad">
        <div class="card-title" style="padding:1rem 1.25rem;margin:0;">Filing History — <?php echo $selected_fy; ?></div>
        <?php if (empty($filing_history_returns)): ?>
        <div class="gst-empty-state"><p>No returns filed yet.</p></div>
        <?php else: ?>
        <div class="table-wrapper"><table class="admin-table"><thead><tr><th>Return</th><th>Period</th><th>Version</th><th>Status</th><th>Generated</th><th>Filed</th><th>Ref</th></tr></thead>
            <tbody><?php foreach ($filing_history_returns as $fh): ?><tr><td><strong><?php echo strtoupper($fh['return_type']); ?></strong></td><td><?php echo $fh['gst_period']; ?></td><td>V<?php echo $fh['version']; ?></td><td><span class="badge-status badge-status-<?php echo $fh['status']; ?>" style="font-size:0.6rem;"><?php echo $fh['status']; ?></span></td><td><?php echo $fh['generation_date'] ? date('d-M-Y', strtotime($fh['generation_date'])) : '—'; ?></td><td><?php echo $fh['filed_date'] ? date('d-M-Y', strtotime($fh['filed_date'])) : '—'; ?></td><td class="mono" style="font-size:0.72rem;"><?php echo htmlspecialchars($fh['filing_reference'] ?: '—'); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

    <?php elseif ($active_bottom_tab === 'validation_report'): ?>
    <div class="admin-card card-no-pad">
        <div class="card-title" style="padding:1rem 1.25rem;margin:0;">Validation Report — <?php echo $selected_fy; ?></div>
        <?php if (empty($filing_history_vr)): ?>
        <div class="gst-empty-state"><p>No validation errors across any returns.</p></div>
        <?php else: ?>
        <div class="table-wrapper"><table class="admin-table"><thead><tr><th>Return</th><th>Type</th><th>Message</th><th>Severity</th><th>Invoice</th></tr></thead>
            <tbody><?php foreach ($filing_history_vr as $fv): ?><tr><td><?php echo $fv['_return_number'] ?? '—'; ?></td><td><?php echo strtoupper($fv['_return_type'] ?? ''); ?></td><td><?php echo htmlspecialchars($fv['msg'] ?? ''); ?></td><td><span class="badge-status badge-status-<?php echo ($fv['severity'] ?? '') === 'error' ? 'rejected' : 'amended'; ?>" style="font-size:0.6rem;"><?php echo $fv['severity'] ?? ''; ?></span></td><td><?php echo htmlspecialchars($fv['inv'] ?? '—'); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

    <?php elseif ($active_bottom_tab === 'filed_vs_pending'): ?>
    <div class="admin-card card-no-pad">
        <div class="card-title" style="padding:1rem 1.25rem;margin:0;">Filed vs Pending — <?php echo $selected_fy; ?></div>
        <?php if (empty($filed_vs_pending)): ?>
        <div class="gst-empty-state"><p>No orders found for this period.</p></div>
        <?php else: ?>
        <div class="table-wrapper"><table class="admin-table"><thead><tr><th>Month</th><th class="num">Total</th><th class="num">Filed</th><th class="num">Pending</th></tr></thead>
            <tbody><?php foreach ($filed_vs_pending as $fp): $pending = $fp['total'] - $fp['filed_count']; ?><tr><td><strong><?php echo $fp['ym']; ?></strong></td><td class="num"><?php echo $fp['total']; ?></td><td class="num" style="color:#059669;"><?php echo $fp['filed_count']; ?></td><td class="num" style="color:<?php echo $pending > 0 ? '#dc2626' : 'var(--admin-muted)'; ?>;"><?php echo $pending; ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

    <?php elseif ($active_bottom_tab === 'cdnr'): ?>
    <div class="admin-card card-no-pad">
        <div class="card-title" style="padding:1rem 1.25rem;margin:0;">CDNR Register (Credit/Debit Notes) — <?php echo $selected_fy; ?></div>
        <?php if (empty($cdnr_items)): ?>
        <div class="gst-empty-state"><p>No credit/debit note entries found.</p></div>
        <?php else: ?>
        <div class="table-wrapper"><table class="admin-table"><thead><tr><th>Return</th><th>Invoice</th><th>Customer</th><th>GSTIN</th><th class="num">Taxable</th><th class="num">GST</th></tr></thead>
            <tbody><?php foreach ($cdnr_items as $ci): ?><tr><td><?php echo strtoupper($ci['return_type']); ?> #<?php echo $ci['return_number']; ?></td><td><?php echo htmlspecialchars($ci['invoice_number'] ?: '—'); ?></td><td><?php echo htmlspecialchars($ci['customer_name'] ?: '—'); ?></td><td class="mono"><?php echo htmlspecialchars($ci['customer_gstin'] ?: '—'); ?></td><td class="num">₹<?php echo number_format($ci['taxable_value'], 2); ?></td><td class="num total-gst">₹<?php echo number_format($ci['total_gst'], 2); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="grid-2col" style="margin-top:1.5rem;">
    <div class="admin-card">
        <h3 class="card-title">Quick Links</h3>
        <div class="gst-quick-links">
            <a href="gst-filing-config.php" class="btn-secondary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>GST Filing Configuration</a>
            <a href="gst-reports.php?tab=reconciliation" class="btn-secondary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>GST Reconciliation (GSTR-2B)</a>
            <?php if (has_role('super_admin')): ?>
            <a href="gst-filing-config.php#period-locks" class="btn-primary" style="background:#d97706;border-color:#d97706;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Manage Period Locks</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="admin-card">
        <h3 class="card-title">Return Type Visibility</h3>
        <p style="font-size:0.82rem;color:var(--admin-muted);margin-bottom:0.75rem;">Registration: <strong><?php echo $filing_cfg['gst_registration_type'] ?? 'regular'; ?></strong></p>
        <table class="gst-visibility-table" style="width:100%;">
            <thead><tr><th>Return</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($return_types as $rt):
                    $enabled = !empty($filing_cfg[$rt['type'] . '_enabled']) || in_array($rt['type'], ['gstr1','gstr3b','gstr2b']);
                ?>
                <tr><td><?php echo $rt['label']; ?></td><td><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.card-no-pad { padding: 0; }
.card-no-pad .table-wrapper { margin-top: 0; border-radius: 0 0 20px 20px; }

/* ── GST Return Center Design System ── */
.gst-alert { display:flex; align-items:flex-start; gap:10px; padding:12px 16px; border-radius:12px; font-size:0.82rem; line-height:1.5; margin-bottom:1rem; border:1px solid; }
.gst-alert svg { flex-shrink:0; margin-top:2px; width:18px; height:18px; }
.gst-alert strong { font-weight:700; }
.gst-alert-success { background:#f0fdf4; border-color:#a7f3d0; color:#166534; }
.gst-alert-error { background:#fef2f2; border-color:#fca5a5; color:#dc2626; }
.gst-alert-actions { margin-top:6px; display:flex; gap:8px; flex-wrap:wrap; }

.gst-kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.5rem; }
@media (max-width:992px) { .gst-kpi-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:576px) { .gst-kpi-grid { grid-template-columns:1fr; } }

.gst-return-card { margin-bottom:1.25rem; border-radius:20px; border:1px solid var(--admin-border); background:var(--admin-surface); box-shadow:0 1px 3px rgba(0,0,0,0.02); transition:box-shadow 0.2s, transform 0.2s; overflow:hidden; }
.gst-return-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.06); }
.gst-return-card-body { padding:1.5rem; }
.gst-return-card-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; }
.gst-return-title { font-size:1.05rem; font-weight:800; color:var(--admin-fg); }
.gst-return-title-desc { font-size:0.7rem; font-weight:600; color:var(--admin-muted); margin-left:0.5rem; }
.gst-return-meta { display:flex; gap:1.25rem; flex-wrap:wrap; margin-top:6px; font-size:0.78rem; color:var(--admin-muted); }
.gst-return-meta strong { color:var(--admin-fg); font-weight:700; }
.gst-return-status-row { margin-top:6px; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.gst-return-actions { display:flex; flex-direction:column; align-items:flex-end; gap:0.5rem; }
.gst-return-actions .btn-row { display:flex; gap:6px; flex-wrap:wrap; }
.gst-return-actions .btn-row .btn-primary,.gst-return-actions .btn-row .btn-secondary { font-size:0.72rem; padding:0.3rem 0.7rem; text-decoration:none; white-space:nowrap; }
.gst-return-actions .btn-row form { display:inline; }
.gst-return-actions .btn-row input[type="text"] { width:100px; font-size:0.72rem; padding:0.25rem 0.4rem; border:1px solid var(--admin-border); border-radius:6px; }
@media (max-width:768px) { .gst-return-card-header { flex-direction:column; } .gst-return-actions { align-items:stretch; width:100%; } .gst-return-actions .btn-row { justify-content:flex-end; } }

.gst-progress { width:100%; max-width:250px; }
.gst-progress-labels { display:flex; justify-content:space-between; font-size:0.62rem; color:var(--admin-muted); margin-bottom:3px; }
.gst-progress-track { height:5px; background:#e2e8f0; border-radius:4px; overflow:hidden; }
.gst-progress-fill { height:100%; border-radius:4px; transition:width 0.4s ease; min-width:0; }

.gst-summary-row { margin-top:12px; padding-top:12px; border-top:1px solid var(--admin-border); display:flex; gap:1.25rem; flex-wrap:wrap; font-size:0.78rem; }
.gst-summary-row span { color:var(--admin-muted); }
.gst-summary-row strong { color:var(--admin-fg); }

.badge-status { display:inline-block; font-weight:700; text-transform:uppercase; font-size:0.62rem; padding:3px 10px; border-radius:20px; }
.badge-status-draft { background:#f1f5f9; color:var(--admin-muted); }
.badge-status-validated { background:#d1fae5; color:#059669; }
.badge-status-ready_for_filing { background:#dbeafe; color:#2563eb; }
.badge-status-filed { background:#e2e8f0; color:#1e293b; }
.badge-status-amended { background:#fef3c7; color:#d97706; }
.badge-status-rejected { background:#fee2e2; color:#dc2626; }

.gst-inline-section { margin-top:16px; padding-top:16px; border-top:2px solid var(--admin-accent); }
.gst-inline-section-title { font-size:0.85rem; font-weight:800; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
.gst-inline-section-title svg { width:16px; height:16px; flex-shrink:0; }

.gst-section-tabs { display:flex; gap:0; border-bottom:2px solid var(--admin-border); overflow-x:auto; margin-bottom:12px; }
.gst-section-tab { padding:8px 14px; font-weight:700; font-size:0.75rem; text-decoration:none; border-bottom:3px solid transparent; color:var(--admin-muted); white-space:nowrap; transition:all 0.15s; }
.gst-section-tab:hover { color:var(--admin-fg); }
.gst-section-tab.active { color:var(--admin-fg); border-bottom-color:var(--admin-accent); }
.gst-section-tab .count { font-weight:400; color:var(--admin-muted); }

.gst-sub-panel { background:#f8fafc; border-radius:12px; padding:1rem; }
.gst-sub-panel h5 { font-size:0.78rem; font-weight:700; margin-bottom:8px; color:var(--admin-fg); }
.gst-meta-table { width:100%; font-size:0.76rem; }
.gst-meta-table td { padding:4px 0; }
.gst-meta-table td:last-child { text-align:right; }

.gst-validation-group { margin-bottom:12px; border-left:4px solid; border-radius:8px; padding:12px; }
.gst-validation-group.error { border-color:#fca5a5; background:#fef2f2; }
.gst-validation-group.warning { border-color:#fcd34d; background:#fffbeb; }
.gst-validation-group-title { font-size:0.78rem; font-weight:700; margin-bottom:6px; }
.gst-validation-item { padding:4px 0; font-size:0.76rem; display:flex; justify-content:space-between; flex-wrap:wrap; }
.gst-validation-item strong { font-weight:600; }
.gst-validation-item .ref { color:var(--admin-muted); font-size:0.72rem; }

.gst-version-item { display:flex; justify-content:space-between; align-items:center; padding:6px 8px; background:#fff; border-radius:8px; margin-bottom:4px; font-size:0.76rem; }
.gst-version-item.active { border:2px solid var(--admin-accent); }
.gst-version-item .date { color:var(--admin-muted); }

.gst-bottom-tabs { margin-bottom:1rem; display:flex; gap:0; border-bottom:2px solid var(--admin-border); overflow-x:auto; }
.gst-bottom-tab { padding:10px 16px; font-weight:700; font-size:0.78rem; text-decoration:none; border-bottom:3px solid transparent; color:var(--admin-muted); white-space:nowrap; transition:all 0.15s; cursor:pointer; }
.gst-bottom-tab:hover { color:var(--admin-fg); }
.gst-bottom-tab.active { color:var(--admin-fg); border-bottom-color:var(--admin-accent); }

.gst-empty-state { padding:2.5rem; text-align:center; color:var(--admin-muted); }
.gst-empty-state p { margin:0; font-size:0.88rem; }

.gst-quick-links { display:flex; flex-direction:column; gap:10px; }
.gst-quick-links a { justify-content:center; }
.gst-quick-links a svg { width:16px; height:16px; }

.gst-visibility-table { font-size:0.78rem; }
.gst-visibility-table td { padding:6px 0; }
.gst-visibility-table td:last-child { text-align:right; }

@media (max-width:768px) {
    .gst-kpi-grid { grid-template-columns:repeat(2,1fr); }
    .gst-inline-section .grid-2col { grid-template-columns:1fr; }
}
</style>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
