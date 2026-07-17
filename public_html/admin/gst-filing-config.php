<?php
$page_title = 'GST Filing Configuration';
$active_menu = 'gst_filing_config';
require_once __DIR__ . '/layout.php';
require_role('super_admin');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/business_helper.php';
require_once __DIR__ . '/gst_helper.php';

runGSTReturnMigrations($pdo);

$message = '';
$error = '';

// Handle lock/unlock period
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'lock_period') {
        $key = trim($_POST['business_key'] ?? '');
        $period = trim($_POST['gst_period'] ?? '');
        if ($key && $period) {
            $period_arr = getGSTPeriodFromParam($period);
            if ($period_arr) {
                try {
                    lockGSTPeriod($pdo, $key, $period_arr, $_SESSION['username'] ?? 'admin');
                    $message = 'Period ' . $period . ' locked successfully.';
                } catch (Exception $e) { $error = 'Lock failed: ' . $e->getMessage(); }
            } else { $error = 'Invalid period format. Use MM-YYYY.'; }
        } else { $error = 'Missing business key or period.'; }
    }
    if ($_POST['action'] === 'unlock_period') {
        $key = trim($_POST['business_key'] ?? '');
        $period = trim($_POST['gst_period'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        if ($key && $period && $reason) {
            $period_arr = getGSTPeriodFromParam($period);
            if ($period_arr) {
                try {
                    unlockGSTPeriod($pdo, $key, $period_arr, $_SESSION['username'] ?? 'admin', $reason);
                    $message = 'Period ' . $period . ' unlocked.';
                } catch (Exception $e) { $error = 'Unlock failed: ' . $e->getMessage(); }
            } else { $error = 'Invalid period format.'; }
        } else { $error = 'Business key, period, and reason are required.'; }
    }
}

// Save filing config
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_filing_config') {
    $business_key = trim($_POST['business_key'] ?? '');
    if ($business_key) {
        try {
            // Update business_config with GST filing fields
            $stmt = $pdo->prepare("UPDATE business_config SET
                gstin = ?,
                gst_registration_type = ?,
                filing_frequency = ?,
                state_code = ?,
                default_place_of_supply = ?,
                gst_effective_date = ?,
                gstr1_enabled = ?,
                gstr3b_enabled = ?,
                gstr2b_enabled = ?,
                gstr9_enabled = ?,
                gstr4_enabled = ?,
                gstr6_enabled = ?,
                gstr7_enabled = ?,
                gstr8_enabled = ?,
                cmp08_enabled = ?
            WHERE business_key = ?");
            $stmt->execute([
                trim($_POST['gstin'] ?? ''),
                $_POST['gst_registration_type'] ?? 'regular',
                $_POST['filing_frequency'] ?? 'monthly',
                intval($_POST['state_code'] ?? 0),
                trim($_POST['default_place_of_supply'] ?? ''),
                $_POST['gst_effective_date'] ?: null,
                isset($_POST['gstr1_enabled']) ? 1 : 0,
                isset($_POST['gstr3b_enabled']) ? 1 : 0,
                isset($_POST['gstr2b_enabled']) ? 1 : 0,
                isset($_POST['gstr9_enabled']) ? 1 : 0,
                isset($_POST['gstr4_enabled']) ? 1 : 0,
                isset($_POST['gstr6_enabled']) ? 1 : 0,
                isset($_POST['gstr7_enabled']) ? 1 : 0,
                isset($_POST['gstr8_enabled']) ? 1 : 0,
                isset($_POST['cmp08_enabled']) ? 1 : 0,
                $business_key,
            ]);
            $message = 'GST Filing config saved for "' . htmlspecialchars($business_key) . '".';
        } catch (PDOException $e) {
            $error = 'Save failed: ' . $e->getMessage();
        }
    }
}

$brand_rows = loadAllBusinessConfigs();
$filing_configs = [];
foreach ($brand_rows as $br) {
    $filing_configs[$br['business_key']] = getGSTFilingConfig($pdo, $br['business_key']);
}

$editing_key = $_GET['edit'] ?? ($brand_rows[0]['business_key'] ?? '');
$edit_cfg = $filing_configs[$editing_key] ?? getGSTFilingConfig($pdo, $editing_key);
?>
<div class="page-header">
    <div class="page-header-title">
        <h2>GST Filing Configuration</h2>
        <p>Configure GST return types, registration details, and filing frequency per brand.</p>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner" style="background:#f0fdf4;border-color:#a7f3d0;color:#166534;">
    <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert-banner" style="background:#fef2f2;border-color:#fca5a5;color:#dc2626;">
    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($brand_rows && count($brand_rows) > 1): ?>
<div style="margin-bottom:1.5rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
    <?php foreach ($brand_rows as $br): ?>
    <a href="?edit=<?php echo urlencode($br['business_key']); ?>" class="btn-secondary" style="text-decoration:none;padding:0.5rem 1rem;<?php echo $editing_key === $br['business_key'] ? 'background:var(--admin-accent);color:#fff;border-color:var(--admin-accent);' : ''; ?>">
        <?php echo htmlspecialchars($br['label']); ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="admin-card">
    <h3 class="card-title">Filing Settings: <?php echo htmlspecialchars($editing_key); ?></h3>
    <form method="POST"><?php csrfField(); ?>
        <input type="hidden" name="action" value="save_filing_config">
        <input type="hidden" name="business_key" value="<?php echo htmlspecialchars($editing_key); ?>">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div class="form-group">
                <label class="form-label">GST Registration Type</label>
                <select name="gst_registration_type" class="form-control">
                    <option value="regular" <?php echo ($edit_cfg['gst_registration_type'] ?? 'regular') === 'regular' ? 'selected' : ''; ?>>Regular Taxpayer</option>
                    <option value="composition" <?php echo ($edit_cfg['gst_registration_type'] ?? '') === 'composition' ? 'selected' : ''; ?>>Composition Scheme</option>
                    <option value="unregistered" <?php echo ($edit_cfg['gst_registration_type'] ?? '') === 'unregistered' ? 'selected' : ''; ?>>Unregistered</option>
                    <option value="others" <?php echo ($edit_cfg['gst_registration_type'] ?? '') === 'others' ? 'selected' : ''; ?>>Others</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Filing Frequency</label>
                <select name="filing_frequency" class="form-control">
                    <option value="monthly" <?php echo ($edit_cfg['filing_frequency'] ?? 'monthly') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="quarterly" <?php echo ($edit_cfg['filing_frequency'] ?? '') === 'quarterly' ? 'selected' : ''; ?>>Quarterly (QRMP)</option>
                </select>
                <span style="font-size:0.7rem;color:var(--admin-muted);">Changing frequency affects only filing schedules, never accounting calculations.</span>
            </div>
            <div class="form-group">
                <label class="form-label">GSTIN</label>
                <input type="text" name="gstin" class="form-control" value="<?php echo htmlspecialchars($edit_cfg['gstin'] ?? ''); ?>" placeholder="27AAAAA0000A1Z5">
            </div>
            <div class="form-group">
                <label class="form-label">State Code</label>
                <input type="number" name="state_code" class="form-control" value="<?php echo intval($edit_cfg['state_code'] ?? 0); ?>" placeholder="27 (Maharashtra)">
            </div>
            <div class="form-group">
                <label class="form-label">Legal Name</label>
                <input type="text" name="legal_name" class="form-control" value="<?php echo htmlspecialchars($edit_cfg['legal_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Trade Name</label>
                <input type="text" name="trade_name" class="form-control" value="<?php echo htmlspecialchars($edit_cfg['trade_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Default Place of Supply</label>
                <input type="text" name="default_place_of_supply" class="form-control" value="<?php echo htmlspecialchars($edit_cfg['default_place_of_supply'] ?? ''); ?>" placeholder="Maharashtra">
            </div>
            <div class="form-group">
                <label class="form-label">GST Effective Date</label>
                <input type="date" name="gst_effective_date" class="form-control" value="<?php echo htmlspecialchars($edit_cfg['gst_effective_date'] ?? ''); ?>">
            </div>
        </div>

        <hr style="border:0;border-top:1px solid var(--admin-border);margin:1.5rem 0;">

        <h4 style="font-weight:700;font-size:0.9rem;margin-bottom:1rem;">Enable / Disable GST Return Types</h4>
        <p style="font-size:0.8rem;color:var(--admin-muted);margin-bottom:1rem;">Only applicable returns are shown in the GST Return Center based on registration type.</p>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="gstr1_enabled" value="1" <?php echo ($edit_cfg['gstr1_enabled'] ?? 1) ? 'checked' : ''; ?>>
                <strong>GSTR-1</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">Outward Supply</span>
            </label>
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="gstr3b_enabled" value="1" <?php echo ($edit_cfg['gstr3b_enabled'] ?? 1) ? 'checked' : ''; ?>>
                <strong>GSTR-3B</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">Summary Return</span>
            </label>
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="gstr2b_enabled" value="1" <?php echo ($edit_cfg['gstr2b_enabled'] ?? 1) ? 'checked' : ''; ?>>
                <strong>GSTR-2B</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">Purchase Reconciliation</span>
            </label>
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="gstr9_enabled" value="1" <?php echo ($edit_cfg['gstr9_enabled'] ?? 0) ? 'checked' : ''; ?>>
                <strong>GSTR-9</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">Annual Return</span>
            </label>
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="gstr4_enabled" value="1" <?php echo ($edit_cfg['gstr4_enabled'] ?? 0) ? 'checked' : ''; ?>>
                <strong>GSTR-4</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">Composition</span>
            </label>
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="gstr6_enabled" value="1" <?php echo ($edit_cfg['gstr6_enabled'] ?? 0) ? 'checked' : ''; ?>>
                <strong>GSTR-6</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">ISD</span>
            </label>
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="gstr7_enabled" value="1" <?php echo ($edit_cfg['gstr7_enabled'] ?? 0) ? 'checked' : ''; ?>>
                <strong>GSTR-7</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">TDS</span>
            </label>
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="gstr8_enabled" value="1" <?php echo ($edit_cfg['gstr8_enabled'] ?? 0) ? 'checked' : ''; ?>>
                <strong>GSTR-8</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">TCS (E-com)</span>
            </label>
            <label class="form-checkbox" style="display:flex;align-items:center;gap:0.5rem;padding:0.75rem;background:#f8fafc;border-radius:8px;cursor:pointer;">
                <input type="checkbox" name="cmp08_enabled" value="1" <?php echo ($edit_cfg['cmp08_enabled'] ?? 0) ? 'checked' : ''; ?>>
                <strong>CMP-08</strong>
                <span style="font-size:0.75rem;color:var(--admin-muted);margin-left:auto;">Composition Payment</span>
            </label>
        </div>

        <button type="submit" class="btn-primary" style="justify-content:center;margin-top:1.5rem;">
            Save Filing Configuration
        </button>
    </form>
</div>

<div class="admin-card" style="margin-top:1rem;">
    <h3 class="card-title">Return Type Visibility Rules</h3>
    <p style="font-size:0.85rem;color:var(--admin-muted);margin-bottom:0.75rem;">The GST Return Center automatically hides returns not applicable to your registration type unless explicitly enabled above.</p>
    <table class="admin-table">
        <thead><tr><th>Registration Type</th><th>Shown By Default</th><th>Hidden Unless Enabled</th></tr></thead>
        <tbody>
            <tr><td><strong>Regular</strong></td><td>GSTR-1, GSTR-3B, GSTR-2B, GSTR-9</td><td>GSTR-4, GSTR-6, GSTR-7, GSTR-8, CMP-08</td></tr>
            <tr><td><strong>Composition</strong></td><td>CMP-08, GSTR-4</td><td>GSTR-1, GSTR-3B, GSTR-2B, GSTR-9</td></tr>
            <tr><td><strong>Unregistered</strong></td><td>None</td><td>All (enable as needed)</td></tr>
        </tbody>
    </table>
</div>

<!-- ─── Period Lock Management ─── -->
<div class="admin-card" style="margin-top:1rem;">
    <h3 class="card-title">Period Lock Management</h3>
    <p style="font-size:0.85rem;color:var(--admin-muted);margin-bottom:1rem;">Lock a period after filing to prevent edits. Unlock only with a reason (tracked in amendment log).</p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
        <div style="padding:1rem;background:#f0fdf4;border-radius:10px;">
            <h4 style="font-size:0.85rem;font-weight:700;margin-bottom:0.5rem;">Lock Period</h4>
            <form method="POST" style="display:flex;gap:0.5rem;flex-wrap:wrap;"><?php csrfField(); ?>
                <input type="hidden" name="action" value="lock_period">
                <input type="hidden" name="business_key" value="<?php echo htmlspecialchars($editing_key); ?>">
                <input type="text" name="gst_period" placeholder="MM-YYYY" pattern="\d{2}-\d{4}" required style="flex:1;min-width:100px;padding:0.4rem 0.65rem;border:1px solid var(--admin-border);border-radius:8px;font-size:0.85rem;">
                <button type="submit" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;background:#059669;">Lock</button>
            </form>
        </div>
        <div style="padding:1rem;background:#fef2f2;border-radius:10px;">
            <h4 style="font-size:0.85rem;font-weight:700;margin-bottom:0.5rem;">Unlock Period</h4>
            <form method="POST" style="display:flex;flex-direction:column;gap:0.5rem;" onsubmit="return confirm('Unlocking allows editing filed invoices. Continue?')"><?php csrfField(); ?>
                <input type="hidden" name="action" value="unlock_period">
                <input type="hidden" name="business_key" value="<?php echo htmlspecialchars($editing_key); ?>">
                <div style="display:flex;gap:0.5rem;">
                    <input type="text" name="gst_period" placeholder="MM-YYYY" pattern="\d{2}-\d{4}" required style="flex:1;min-width:100px;padding:0.4rem 0.65rem;border:1px solid var(--admin-border);border-radius:8px;font-size:0.85rem;">
                    <button type="submit" class="btn-danger" style="padding:0.4rem 1rem;font-size:0.8rem;">Unlock</button>
                </div>
                <textarea name="reason" rows="2" required placeholder="Reason for unlock (required)..." style="padding:0.4rem 0.65rem;border:1px solid var(--admin-border);border-radius:8px;font-size:0.8rem;"></textarea>
            </form>
        </div>
    </div>

    <?php
    $locks = [];
    try {
        $stmt = $pdo->prepare("SELECT *, DATE_FORMAT(settlement_month, '%m-%Y') AS gst_period, CONCAT(YEAR(settlement_month), '-', YEAR(settlement_month)+1) AS financial_year FROM gst_settlements WHERE business_key = ? AND is_locked IS NOT NULL ORDER BY settlement_month DESC LIMIT 10");
        $stmt->execute([$editing_key]);
        $locks = $stmt->fetchAll();
    } catch (PDOException $e) {}
    ?>
    <?php if (!empty($locks)): ?>
    <div class="table-wrapper">
        <table class="admin-table" style="font-size:0.8rem;">
            <thead><tr><th>Period</th><th style="text-align:center;">Status</th><th>Locked At</th><th>Locked By</th><th>Unlocked At</th></tr></thead>
            <tbody>
                <?php foreach ($locks as $l): ?>
                <tr>
                    <td><strong><?php echo $l['gst_period']; ?></strong> (FY <?php echo $l['financial_year']; ?>)</td>
                    <td style="text-align:center;"><?php echo $l['is_locked'] ? '<span class="badge badge-filled" style="background:#dc2626;color:#fff;">Locked</span>' : '<span class="badge badge-empty">Unlocked</span>'; ?></td>
                    <td><?php echo $l['locked_at'] ? date('d-M-Y H:i', strtotime($l['locked_at'])) : '—'; ?></td>
                    <td><?php echo htmlspecialchars($l['locked_by'] ?: '—'); ?></td>
                    <td><?php echo $l['unlocked_at'] ? date('d-M-Y H:i', strtotime($l['unlocked_at'])) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color:var(--admin-muted);font-size:0.85rem;">No period locks recorded for this brand.</p>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
