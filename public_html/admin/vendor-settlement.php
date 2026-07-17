<?php
require_once __DIR__ . '/lang_init.php';

// ── AJAX: Analyze Bulk Lot Pay (must run before layout.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_pay') {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/csrf.php';
    require_login();
    validateCsrfToken();
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/inventory-utils.php';
    require_once __DIR__ . '/bulk_operation_engine.php';
    ob_clean();
    header('Content-Type: application/json');
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        $vendor_id = intval($_POST['vendor_id'] ?? 0);

        $context = ['action' => 'pay', 'vendor_id' => $vendor_id];
        $report = generateFullImpactReport($pdo, $ids, 'pay', $context);
        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$page_title = 'Vendor Settlement';
$active_menu = 'vendor_settlement';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/bulk_operation_engine.php';

runVendorPartnerLedgerMigrations($pdo);
runVendorRefillBatchItemsMigration($pdo);
runRefillCostMigrations($pdo);

$message = '';
$error = '';
$active_tab = isset($_GET['tab']) ? intval($_GET['tab']) : 1;

// ── TAB 2: MARK RECONCILED ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settlement_reconcile') {
    validateCsrfToken();
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $cylinder_ids = $_POST['cylinder_ids'] ?? [];
    $adjustment_amount = floatval($_POST['adjustment_amount'] ?? 0);
    $reconcile_notes = trim($_POST['reconcile_notes'] ?? '');

    if ($vendor_id > 0 && !empty($reconcile_notes)) {
        try {
            $pdo->beginTransaction();
            logSettlementAdjustment($pdo, $vendor_id, $adjustment_amount, "Reconciliation: $reconcile_notes" . (!empty($cylinder_ids) ? " (Cylinders: " . implode(',', $cylinder_ids) . ")" : ""), $_SESSION['username'] ?? 'system');
            if (!empty($cylinder_ids)) {
                $ph = implode(',', array_fill(0, count($cylinder_ids), '?'));
                $pdo->prepare("UPDATE cylinders SET current_vendor_id = NULL WHERE id IN ($ph) AND current_vendor_id = ?")->execute(array_merge($cylinder_ids, [$vendor_id]));
            }
            $pdo->commit();
            syncInventory($pdo);
            $message = 'Reconciliation entry recorded in vendor ledger.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Reconciliation failed: ' . $e->getMessage();
        }
    } else {
        $error = 'Please select a vendor and enter reconciliation notes.';
    }
    $active_tab = 2;
}

// ── TAB 3: PAY LOT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settlement_lot_pay') {
    validateCsrfToken();
    $lot_id = intval($_POST['lot_id'] ?? 0);
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
    $payment_date_raw = $_POST['payment_date'] ?? date('Y-m-d\TH:i');
    $payment_date = str_replace('T', ' ', $payment_date_raw) . ':00';
    $reference = trim($_POST['reference'] ?? '');

    if ($lot_id > 0 && $amount > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, payment_subtype, notes, payment_date) VALUES (?, ?, ?, ?, 'vendor_refill_payment', 'settlement', ?, ?)");
            $stmt->execute([$lot_id, $vendor_id, $amount, $payment_method, "Settlement payment for lot #$lot_id" . ($reference ? " | Ref: $reference" : ""), $payment_date]);
            addVendorRefillLedgerEntry($pdo, $vendor_id, $amount, 'payment', $lot_id, "Payment for lot #$lot_id ($payment_method" . ($reference ? " - $reference" : "") . ")", $_SESSION['username'] ?? 'admin', 'dispatch_lot');
            recalcLotFinancials($pdo, $lot_id);
            // Sync vendor invoice payment status
            if (function_exists('updateVendorInvoicePaymentStatus')) {
                $inv_st = $pdo->prepare("SELECT id FROM vendor_invoices WHERE lot_id = ?");
                $inv_st->execute([$lot_id]);
                while ($inv_r = $inv_st->fetch()) {
                    updateVendorInvoicePaymentStatus($pdo, $inv_r['id']);
                }
            }
            $pdo->commit();
            $message = "Payment of ₹" . number_format($amount, 2) . " recorded for lot #$lot_id.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Lot payment failed: ' . $e->getMessage();
        }
    } else {
        $error = 'Invalid lot or amount.';
    }
    $active_tab = 3;
}

// ── TAB 3: BULK LOT PAY ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settlement_lot_bulk_pay') {
    validateCsrfToken();
    $lot_ids = isset($_POST['lot_ids']) ? $_POST['lot_ids'] : [];
    $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
    $payment_date_raw = $_POST['payment_date'] ?? date('Y-m-d\TH:i');
    $payment_date = str_replace('T', ' ', $payment_date_raw) . ':00';
    $reference = trim($_POST['reference'] ?? '');
    $created_by = $_SESSION['username'] ?? 'system';
    $paid = 0;

    if (!empty($lot_ids)) {
        try {
            $pdo->beginTransaction();
            foreach ($lot_ids as $lid) {
                $lid = intval($lid);
                $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id = ? AND payment_status != 'paid'");
                $stmt->execute([$lid]);
                $lot = $stmt->fetch();
                if (!$lot) continue;
                $vendor_id = intval($lot['vendor_id']);
                $balance = floatval($lot['remaining_balance'] ?? 0);
                if ($balance <= 0) continue;

                $stmt = $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, payment_subtype, notes, payment_date) VALUES (?, ?, ?, ?, 'vendor_refill_payment', 'settlement', ?, ?)");
                $stmt->execute([$lid, $vendor_id, $balance, $payment_method, "Bulk payment for lot {$lot['lot_number']}" . ($reference ? " | Ref: $reference" : ""), $payment_date]);
                addVendorRefillLedgerEntry($pdo, $vendor_id, $balance, 'payment', $lid, "Bulk payment for lot {$lot['lot_number']} ($payment_method" . ($reference ? " - $reference" : "") . ")", $created_by, 'dispatch_lot');
                recalcLotFinancials($pdo, $lid);
                // Sync vendor invoice payment status
                if (function_exists('updateVendorInvoicePaymentStatus')) {
                    $inv_st = $pdo->prepare("SELECT id FROM vendor_invoices WHERE lot_id = ?");
                    $inv_st->execute([$lid]);
                    while ($inv_r = $inv_st->fetch()) {
                        updateVendorInvoicePaymentStatus($pdo, $inv_r['id']);
                    }
                }
                $paid++;
            }
            $pdo->commit();

            if ($paid > 0 && function_exists('logBulkOperationAudit')) {
                $audit_ctx = ['action' => 'pay', 'vendor_id' => $vendor_id];
                $audit_report = generateFullImpactReport($pdo, array_map('intval', $lot_ids), 'pay', $audit_ctx);
                logBulkOperationAudit($pdo, $audit_report, ['processed' => $paid, 'skipped' => count($lot_ids) - $paid, 'details' => []], null, 'success', null, $created_by);
            }

            $message = "Successfully paid $paid lot(s).";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Bulk lot payment failed: ' . $e->getMessage();
        }
    } else {
        $error = 'No lots selected.';
    }
    $active_tab = 3;
}

// ── TAB 4: SYNC LOT STATUS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settlement_lot_sync') {
    validateCsrfToken();
    $lot_id = intval($_POST['lot_id'] ?? 0);
    if ($lot_id > 0) {
        recalcLotFinancials($pdo, $lot_id);
        // Sync vendor invoice payment status
        if (function_exists('updateVendorInvoicePaymentStatus')) {
            $inv_st = $pdo->prepare("SELECT id FROM vendor_invoices WHERE lot_id = ?");
            $inv_st->execute([$lot_id]);
            while ($inv_r = $inv_st->fetch()) {
                updateVendorInvoicePaymentStatus($pdo, $inv_r['id']);
            }
        }
        $message = "Lot #$lot_id status and financials recalculated.";
    } else {
        $error = 'Invalid lot ID.';
    }
    $active_tab = 4;
}

// ── TAB 4: SYNC COUNTS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settlement_sync_counts') {
    validateCsrfToken();
    $vendor_id = isset($_POST['vendor_id']) ? intval($_POST['vendor_id']) : null;
    if (syncVendorActiveCount($pdo, $vendor_id)) {
        $message = $vendor_id ? "Active refill count synced for vendor." : "All vendor active refill counts synced.";
    } else {
        $error = 'Failed to sync vendor counts.';
    }
    $active_tab = 4;
}

// ── FETCH DATA ──
$pending_receipts = getVendorPendingReceipts($pdo);
$filled_no_batch = getFilledCylindersWithoutBatch($pdo);
$forceful_moves = getForcefulMovesWithoutBatch($pdo);
$count_mismatches = checkVendorCountMismatches($pdo);

// Tab 3: Unpaid lots (with remaining_balance > 0 and not paid)
$unpaid_lots = [];
try {
    $stmt = $pdo->query("
        SELECT dl.*, v.name AS vendor_name
        FROM dispatch_lots dl
        JOIN vendors v ON dl.vendor_id = v.id
        WHERE dl.payment_status IN ('unpaid', 'partial')
          AND dl.remaining_balance > 0
        ORDER BY v.name, dl.dispatch_date DESC
    ");
    while ($r = $stmt->fetch()) {
        $vid = intval($r['vendor_id']);
        if (!isset($unpaid_lots[$vid])) {
            $unpaid_lots[$vid] = ['vendor_name' => $r['vendor_name'], 'lots' => []];
        }
        $unpaid_lots[$vid]['lots'][] = $r;
    }
} catch (PDOException $e) {}

$vendors = [];
try {
    $vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll();
} catch (PDOException $e) {}

$gas_types = [];
try {
    $gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name")->fetchAll();
} catch (PDOException $e) {}

// Tab 4: Lot status mismatches
$lot_mismatches = [];
try {
    $stmt = $pdo->query("
        SELECT dl.id, dl.lot_number, dl.vendor_id, v.name AS vendor_name,
               dl.returned_count, dl.cylinder_count, dl.lot_status,
               (SELECT COUNT(*) FROM dispatch_lot_items WHERE lot_id = dl.id AND dispatch_status = 'received') AS actual_received
        FROM dispatch_lots dl
        JOIN vendors v ON dl.vendor_id = v.id
        WHERE dl.returned_count != (SELECT COUNT(*) FROM dispatch_lot_items WHERE lot_id = dl.id AND dispatch_status = 'received')
           OR (dl.lot_status = 'completed' AND dl.returned_count < dl.cylinder_count)
           OR (dl.lot_status = 'open' AND dl.returned_count > 0)
        ORDER BY v.name, dl.dispatch_date DESC
    ");
    $lot_mismatches = $stmt->fetchAll();
} catch (PDOException $e) {}
?>
<style>
.settlement-tabs { display:flex; gap:0; border-bottom:2px solid var(--admin-border); margin-bottom:1.5rem; }
.settlement-tab { padding:0.75rem 1.5rem; cursor:pointer; border:none; background:none; font-weight:600; font-size:0.85rem; color:var(--admin-muted); position:relative; transition:all 0.2s; }
.settlement-tab:hover { color:var(--admin-fg); }
.settlement-tab.active { color:var(--admin-accent); }
.settlement-tab.active::after { content:''; position:absolute; bottom:-2px; left:0; right:0; height:2px; background:var(--admin-accent); border-radius:2px 2px 0 0; }
.settlement-tab .tab-badge { display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 6px; border-radius:10px; font-size:0.7rem; font-weight:700; background:var(--admin-accent); color:#fff; margin-left:6px; }
.tab-content { display:none; }
.tab-content.active { display:block; }

.settle-card { background:var(--admin-card-bg); border:1px solid var(--admin-border); border-radius:12px; padding:1.25rem; margin-bottom:1.25rem; }
.settle-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; padding-bottom:0.75rem; border-bottom:1px solid var(--admin-border); }
.settle-card-header h3 { margin:0; font-size:1rem; font-weight:700; display:flex; align-items:center; gap:0.5rem; }
.settle-card-header .vendor-badge { font-size:0.72rem; background:var(--admin-accent); color:#fff; padding:2px 10px; border-radius:20px; }

.cyl-settle-list { max-height:400px; overflow-y:auto; border:1px solid var(--admin-border); border-radius:8px; padding:0.5rem; margin-bottom:1rem; }
.cyl-settle-item { display:flex; align-items:center; gap:0.75rem; padding:0.5rem 0.75rem; border-radius:6px; cursor:pointer; transition:background 0.15s; }
.cyl-settle-item:hover { background:var(--admin-hover); }
.cyl-settle-item .cyl-serial { font-family:monospace; font-weight:700; font-size:0.82rem; }

.reconcile-option-group { display:flex; gap:1rem; margin-bottom:1rem; flex-wrap:wrap; }
.reconcile-option { flex:1; min-width:280px; padding:1rem; border:2px solid var(--admin-border); border-radius:10px; cursor:pointer; transition:all 0.2s; }
.reconcile-option:hover { border-color:var(--admin-accent); }
.reconcile-option.selected { border-color:var(--admin-accent); background:var(--admin-accent)08; }
.reconcile-option h4 { margin:0 0 0.25rem; font-size:0.9rem; }
.reconcile-option p { margin:0; font-size:0.78rem; color:var(--admin-muted); }

.empty-state { text-align:center; padding:3rem 1rem; color:var(--admin-muted); }
.empty-state svg { opacity:0.3; margin-bottom:0.5rem; }
.empty-state h4 { margin:0 0 0.25rem; font-size:1rem; }
.empty-state p { margin:0; font-size:0.82rem; }

.orphan-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
.orphan-table th { text-align:left; padding:0.5rem 0.75rem; border-bottom:1px solid var(--admin-border); font-size:0.72rem; text-transform:uppercase; color:var(--admin-muted); }
.orphan-table td { padding:0.5rem 0.75rem; border-bottom:1px solid var(--admin-border); }

.settle-form-row { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.75rem; }
.settle-form-row.three-col { grid-template-columns:1fr 1fr 1fr; }
.settle-form-group { }
.settle-form-group label { display:block; font-size:0.78rem; font-weight:600; margin-bottom:0.25rem; color:var(--admin-muted); }
.settle-form-group input, .settle-form-group select, .settle-form-group textarea { width:100%; padding:0.5rem 0.75rem; border:1px solid var(--admin-border); border-radius:6px; background:var(--admin-card-bg); color:var(--admin-fg); font-size:0.82rem; box-sizing:border-box; }

.mismatch-row { display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1rem; border:1px solid var(--admin-border); border-radius:8px; margin-bottom:0.5rem; }
.mismatch-info { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.mismatch-count { font-weight:700; }
.mismatch-count.bad { color:#dc2626; }
.mismatch-count.good { color:#10b981; }

.summary-bar { display:flex; gap:1.5rem; padding:0.75rem 1rem; background:var(--admin-hover); border-radius:8px; margin-bottom:1rem; font-size:0.82rem; font-weight:600; }
.summary-bar .summary-item { }
.summary-bar .summary-item.gross { color:var(--admin-fg); }
.summary-bar .summary-item.ded { color:#dc2626; }
.summary-bar .summary-item.add { color:#10b981; }
.summary-bar .summary-item.net { color:var(--admin-accent); font-size:0.95rem; }
</style>

<div class="page-header">
    <div class="page-header-title">
        <h2>Vendor Settlement &amp; Reconciliation</h2>
        <p>Detect and fix vendor-related discrepancies — pending receipts, orphaned cylinders, unpaid batches, and count mismatches.</p>
    </div>
</div>

<?php if ($message): ?>
<div class="alert-banner"><span><strong>Success:</strong> <?php echo htmlspecialchars($message); ?></span><button class="modal-close" onclick="this.parentElement.remove()"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-banner bg-danger-soft text-danger border-danger"><span><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></span><button class="modal-close" onclick="this.parentElement.remove()"></button></div>
<?php endif; ?>

<div class="settlement-tabs">
    <button class="settlement-tab <?php echo $active_tab === 1 ? 'active' : ''; ?>" onclick="switchTab(1)">
        Pending Receipts
        <span class="tab-badge"><?php echo array_sum(array_map(function($g) { return count($g['cylinders']); }, $pending_receipts)); ?></span>
    </button>
    <button class="settlement-tab <?php echo $active_tab === 2 ? 'active' : ''; ?>" onclick="switchTab(2)">
        Orphaned Cylinders
        <span class="tab-badge"><?php echo count($filled_no_batch) + count($forceful_moves); ?></span>
    </button>
    <button class="settlement-tab <?php echo $active_tab === 3 ? 'active' : ''; ?>" onclick="switchTab(3)">
        Unpaid Lots
        <span class="tab-badge"><?php echo array_sum(array_map(function($g) { return count($g['lots']); }, $unpaid_lots)); ?></span>
    </button>
    <button class="settlement-tab <?php echo $active_tab === 4 ? 'active' : ''; ?>" onclick="switchTab(4)">
        Health Check
        <span class="tab-badge"><?php echo count($count_mismatches); ?></span>
    </button>
</div>

<?php $tab = $active_tab; ?>

<!-- ════════ TAB 1: PENDING RECEIPTS ════════ -->
<div class="tab-content <?php echo $tab === 1 ? 'active' : ''; ?>" id="tab1">
    <?php if (empty($pending_receipts)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="9 17 4 12 9 7"/><path d="M20 17v-6a4 4 0 0 0-4-4H4"/></svg>
        <h4>No Pending Receipts</h4>
        <p>All dispatched cylinders have been received back.</p>
    </div>
    <?php else: foreach ($pending_receipts as $g): ?>
    <div class="settle-card">
        <div class="settle-card-header">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                <?php echo htmlspecialchars($g['vendor_name']); ?>
                <span class="vendor-badge"><?php echo count($g['cylinders']); ?> cylinders at vendor</span>
            </h3>
        </div>
        <div style="text-align:center;padding:1.5rem;">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="1.5" style="margin-bottom:0.75rem;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <h4 style="margin:0 0 0.5rem;">Use Dispatch Lot System</h4>
            <p style="color:var(--admin-muted);margin:0 auto 1.25rem;max-width:450px;">
                Cylinders must be received through the <strong>Dispatch Lot system</strong> for proper tracking and settlement.
                Go to <strong>Receive Cylinders</strong> and select the appropriate lot.
            </p>
            <a href="receive-cylinder.php" class="btn-primary" style="display:inline-flex;align-items:center;gap:0.4rem;text-decoration:none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 17 4 12 9 7"/><path d="M20 17v-6a4 4 0 0 0-4-4H4"/></svg>
                Go to Receive Cylinders
            </a>
        </div>
        <details style="margin-top:0.5rem;font-size:0.82rem;">
            <summary style="cursor:pointer;color:var(--admin-muted);font-weight:600;">View <?php echo count($g['cylinders']); ?> cylinder(s) at vendor</summary>
            <div style="margin-top:0.5rem;display:grid;gap:0.35rem;">
                <?php foreach ($g['cylinders'] as $cyl): ?>
                <div style="padding:0.35rem 0.75rem;background:var(--admin-hover);border-radius:6px;font-family:monospace;">
                    <?php echo htmlspecialchars($cyl['serial_number']); ?>
                    <span style="color:var(--admin-muted);font-size:0.78rem;"> &middot; <?php echo htmlspecialchars($cyl['gas_name']); ?> &middot; <?php echo $cyl['size_capacity']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- ════════ TAB 2: ORPHANED CYLINDERS ════════ -->
<div class="tab-content <?php echo $tab === 2 ? 'active' : ''; ?>" id="tab2">
    <?php if (empty($filled_no_batch) && empty($forceful_moves)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg>
        <h4>No Orphaned Cylinders</h4>
        <p>All status changes have proper batch records.</p>
    </div>
    <?php endif; ?>

    <?php if (!empty($filled_no_batch)): ?>
    <div class="settle-card">
        <div class="settle-card-header">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                Filled Cylinders Without Batch Record
                <span class="vendor-badge"><?php echo count($filled_no_batch); ?> cylinders</span>
            </h3>
            <span style="font-size:0.78rem;color:var(--admin-muted);">Cylinders marked 'filled' but missing vendor batch</span>
        </div>
        <table class="orphan-table">
            <thead><tr><th></th><th>Serial</th><th>Gas</th><th>Size</th><th>Owner</th></tr></thead>
            <tbody>
                <?php foreach ($filled_no_batch as $cyl): ?>
                <tr>
                    <td><input type="checkbox" class="orphan-chk" value="<?php echo $cyl['id']; ?>" data-gas="<?php echo $cyl['gas_type_id']; ?>" data-size="<?php echo $cyl['size_capacity']; ?>"></td>
                    <td><strong><?php echo htmlspecialchars($cyl['serial_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($cyl['gas_name']); ?></td>
                    <td><?php echo $cyl['size_capacity']; ?></td>
                    <td><?php echo $cyl['ownership_type']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border);">
            <p style="font-size:0.82rem;color:var(--admin-muted);margin-bottom:0.75rem;">
                These cylinders were marked as filled or changed status without going through the dispatch lot system.
                Use <strong>Mark as Reconciled</strong> to log an adjustment entry in the vendor ledger.
            </p>
            <form method="POST" onsubmit="return confirm('Mark selected cylinders as reconciled?')">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="settlement_reconcile">
                <div id="reconcileCylinderInputs"></div>
                <div class="settle-form-row">
                    <div class="settle-form-group">
                        <label>Vendor <span class="required-star">*</span></label>
                        <select name="vendor_id" required>
                            <option value="">-- Select vendor --</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="settle-form-group">
                        <label>Adjustment Amount (₹)</label>
                        <input type="number" name="adjustment_amount" value="0" min="0" step="0.01">
                        <span style="font-size:0.7rem;color:var(--admin-muted);">Optional: record a financial adjustment</span>
                    </div>
                </div>
                <div class="settle-form-group">
                    <label>Reconciliation Notes <span class="required-star">*</span></label>
                    <textarea name="reconcile_notes" rows="2" required placeholder="e.g. Cylinder XXX was manually moved to empty. No lot record exists." style="width:100%;padding:0.5rem;border:1px solid var(--admin-border);border-radius:6px;box-sizing:border-box;"></textarea>
                </div>
                <button type="submit" class="btn-secondary">Mark as Reconciled</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($forceful_moves)): ?>
    <div class="settle-card">
        <div class="settle-card-header">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Previously at Vendor — Now Different Status
                <span class="vendor-badge"><?php echo count($forceful_moves); ?> cylinders</span>
            </h3>
            <span style="font-size:0.78rem;color:var(--admin-muted);">Cylinders that were sent to vendor but now have a different status with no lot/batch record</span>
        </div>
        <table class="orphan-table">
            <thead><tr><th></th><th>Serial</th><th>Current Status</th><th>Gas</th><th>Size</th><th>Last Vendor ID</th></tr></thead>
            <tbody>
                <?php foreach ($forceful_moves as $cyl): ?>
                <tr>
                    <td><input type="checkbox" class="force-chk" value="<?php echo $cyl['id']; ?>"></td>
                    <td><strong><?php echo htmlspecialchars($cyl['serial_number']); ?></strong></td>
                    <td><span class="badge badge-empty"><?php echo str_replace('_', ' ', $cyl['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($cyl['gas_name']); ?></td>
                    <td><?php echo $cyl['size_capacity']; ?></td>
                    <td><?php echo intval($cyl['ref_vendor_id'] ?? 0) > 0 ? '#' . intval($cyl['ref_vendor_id']) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border);">
            <p style="font-size:0.82rem;color:var(--admin-muted);margin-bottom:0.75rem;">
                These cylinders were previously sent to a vendor but now have a different status (forceful manual change).
                Use <strong>Mark as Reconciled</strong> below to log an adjustment.
            </p>
            <form method="POST" onsubmit="return confirm('Mark as reconciled?')">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="settlement_reconcile">
                <div id="forceReconcileInputs"></div>
                <div class="settle-form-row">
                    <div class="settle-form-group">
                        <label>Vendor</label>
                        <select name="vendor_id" required>
                            <option value="">-- Select vendor --</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="settle-form-group">
                        <label>Adjustment Amount (₹)</label>
                        <input type="number" name="adjustment_amount" value="0" min="0" step="0.01">
                    </div>
                </div>
                <div class="settle-form-group">
                    <label>Notes <span class="required-star">*</span></label>
                    <textarea name="reconcile_notes" rows="2" required placeholder="Explain why this cylinder was moved" style="width:100%;padding:0.5rem;border:1px solid var(--admin-border);border-radius:6px;box-sizing:border-box;"></textarea>
                </div>
                <button type="submit" class="btn-secondary">Mark as Reconciled</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ════════ TAB 3: UNPAID LOTS ════════ -->
<div class="tab-content <?php echo $tab === 3 ? 'active' : ''; ?>" id="tab3">
    <?php if (empty($unpaid_lots)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
        <h4>All Lots Paid</h4>
        <p>No unpaid dispatch lots found. All lots are settled.</p>
    </div>
    <?php else: foreach ($unpaid_lots as $vid => $g): ?>
    <div class="settle-card">
        <div class="settle-card-header">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                <?php echo htmlspecialchars($g['vendor_name']); ?>
                <span class="vendor-badge"><?php echo count($g['lots']); ?> unpaid</span>
            </h3>
            <?php
            $total_unpaid = array_sum(array_map(function($l) { return floatval($l['remaining_balance'] ?? 0); }, $g['lots']));
            ?>
            <span style="font-size:0.82rem;font-weight:700;color:#dc2626;">Total Due: ₹<?php echo number_format($total_unpaid, 2); ?></span>
        </div>
        <form method="POST" id="bulkPayForm_<?php echo $vid; ?>" onsubmit="return bulkPayAnalyze(event, this, <?php echo $vid; ?>)">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="settlement_lot_bulk_pay">
            <table class="orphan-table">
                <thead><tr><th></th><th>Lot #</th><th>Status</th><th>Progress</th><th>Refill</th><th>GST</th><th style="text-align:right;">Total</th><th style="text-align:right;">Paid</th><th style="text-align:right;">Balance</th></tr></thead>
                <tbody>
                    <?php foreach ($g['lots'] as $l): 
                        $bal = floatval($l['remaining_balance'] ?? 0);
                        $total = floatval($l['final_total'] ?? 0);
                        $paid = floatval($l['total_paid'] ?? 0);
                        $refill = floatval($l['final_refill_amount'] ?? 0);
                        $gst = floatval($l['final_gst_amount'] ?? 0);
                    ?>
                    <tr>
                        <td><input type="checkbox" name="lot_ids[]" value="<?php echo $l['id']; ?>"></td>
                        <td><strong><?php echo htmlspecialchars($l['lot_number']); ?></strong></td>
                        <td><span class="badge badge-rental"><?php echo str_replace('_', ' ', $l['lot_status']); ?></span></td>
                        <td><?php echo intval($l['returned_count']); ?>/<?php echo intval($l['cylinder_count']); ?></td>
                        <td>₹<?php echo number_format($refill, 2); ?></td>
                        <td>₹<?php echo number_format($gst, 2); ?></td>
                        <td style="text-align:right;font-weight:700;">₹<?php echo number_format($total, 2); ?></td>
                        <td style="text-align:right;">₹<?php echo number_format($paid, 2); ?></td>
                        <td style="text-align:right;font-weight:700;color:#dc2626;">₹<?php echo number_format($bal, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border);">
                <div class="settle-form-row">
                    <div class="settle-form-group">
                        <label>Payment Method <span class="required-star">*</span></label>
                        <select name="payment_method" required>
                            <option value="Bank Transfer" selected>Bank Transfer</option>
                            <option value="Cash">Cash</option>
                            <option value="UPI">UPI</option>
                            <option value="Cheque">Cheque</option>
                            <option value="NEFT">NEFT</option>
                            <option value="RTGS">RTGS</option>
                            <option value="Online Transfer">Online Transfer</option>
                            <option value="Adjustment">Adjustment</option>
                        </select>
                    </div>
                    <div class="settle-form-group">
                        <label>Payment Date <span class="required-star">*</span></label>
                        <input type="datetime-local" name="payment_date" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    <div class="settle-form-group">
                        <label>Reference</label>
                        <input type="text" name="reference" placeholder="Optional ref/transaction ID">
                    </div>
                </div>
                <button type="submit" class="btn-primary">Pay Selected Lots</button>
            </div>
        </form>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- ════════ TAB 4: HEALTH CHECK ════════ -->
<div class="tab-content <?php echo $tab === 4 ? 'active' : ''; ?>" id="tab4">
    <div class="settle-card">
        <div class="settle-card-header">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Vendor Active Refill Count Mismatches
            </h3>
            <span style="font-size:0.78rem;color:var(--admin-muted);">Expected vs actual cylinders at vendor</span>
        </div>
        <?php if (empty($count_mismatches)): ?>
        <div class="empty-state" style="padding:1.5rem;">
            <h4>All Counts Match</h4>
            <p>Every vendor's active_refill_count matches the actual number of cylinders with status 'sent_to_vendor'.</p>
        </div>
        <?php else: foreach ($count_mismatches as $m): ?>
        <div class="mismatch-row">
            <div class="mismatch-info">
                <strong><?php echo htmlspecialchars($m['vendor_name']); ?></strong>
                <span class="mismatch-count <?php echo intval($m['current_count']) !== intval($m['actual_count']) ? 'bad' : 'good'; ?>">
                    Recorded: <?php echo intval($m['current_count']); ?>
                </span>
                <span class="mismatch-count <?php echo intval($m['actual_count']) !== intval($m['current_count']) ? 'bad' : 'good'; ?>">
                    Actual: <?php echo intval($m['actual_count']); ?>
                </span>
            </div>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="settlement_sync_counts">
                <input type="hidden" name="vendor_id" value="<?php echo $m['id']; ?>">
                <button type="submit" class="btn-secondary" style="padding:0.4rem 0.75rem;font-size:0.78rem;">Recount</button>
            </form>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="settle-card">
        <div class="settle-card-header">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Bulk Actions
            </h3>
        </div>
        <form method="POST" onsubmit="return confirm('Recount ALL vendors?')" style="display:inline-block;">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="settlement_sync_counts">
            <button type="submit" class="btn-secondary">Recount All Vendors</button>
        </form>
        <span style="font-size:0.78rem;color:var(--admin-muted);margin-left:0.75rem;">Fixes all vendor active_refill_count mismatches in one click.</span>
    </div>

    <?php if (!empty($lot_mismatches)): ?>
    <div class="settle-card">
        <div class="settle-card-header">
            <h3>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Dispatch Lot Status Mismatches
            </h3>
            <span style="font-size:0.78rem;color:var(--admin-muted);">Lot returned_count vs actual received items</span>
        </div>
        <?php foreach ($lot_mismatches as $lm): ?>
        <div class="mismatch-row">
            <div class="mismatch-info">
                <strong><?php echo htmlspecialchars($lm['vendor_name']); ?></strong>
                <span><?php echo htmlspecialchars($lm['lot_number']); ?></span>
                <span class="mismatch-count bad">Recorded: <?php echo intval($lm['returned_count']); ?>/<?php echo intval($lm['cylinder_count']); ?></span>
                <span class="mismatch-count good">Actual: <?php echo intval($lm['actual_received']); ?>/<?php echo intval($lm['cylinder_count']); ?></span>
            </div>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="settlement_lot_sync">
                <input type="hidden" name="lot_id" value="<?php echo $lm['id']; ?>">
                <button type="submit" class="btn-secondary" style="padding:0.4rem 0.75rem;font-size:0.78rem;">Sync Lot</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function switchTab(n) {
    document.querySelectorAll('.tab-content').forEach(function(el) { el.classList.remove('active'); });
    document.querySelectorAll('.settlement-tab').forEach(function(el) { el.classList.remove('active'); });
    document.getElementById('tab' + n).classList.add('active');
    document.querySelectorAll('.settlement-tab')[n - 1].classList.add('active');
    var params = new URLSearchParams(window.location.search);
    params.set('tab', n);
    window.history.replaceState({}, '', '?' + params.toString());
}

function togglePaidFields(radio) {
    var card = radio.closest('.settle-card') || radio.closest('.tab-content');
    if (!card) return;
    var fields = card.querySelectorAll('.paid-fields');
    fields.forEach(function(el) { el.style.display = radio.value === 'paid' ? 'block' : 'none'; });
}

function getCheckedCount(form) {
    return form.querySelectorAll('input[name="cylinder_ids[]"]:checked').length;
}

function updateSettleSummary(chk) {
    var card = chk.closest('.settle-card');
    if (!card) return;
    updateSettleCardSummary(card);
}

function updateSettleSummaries() {
    document.querySelectorAll('.settle-card').forEach(function(card) {
        updateSettleCardSummary(card);
    });
}

function updateSettleCardSummary(card) {
    var costInput = card.querySelector('.cost-input');
    var cost = parseFloat(costInput ? costInput.value : 0) || 0;
    var checked = card.querySelectorAll('input[name="cylinder_ids[]"]:checked').length;
    var ded = parseFloat(card.querySelector('input[name="deduction_amount"]').value) || 0;
    var add = parseFloat(card.querySelector('input[name="addition_amount"]').value) || 0;
    var gross = checked * cost;
    var net = gross - ded + add;
    var summary = card.querySelector('.summary-bar');
    if (checked > 0 && cost > 0) {
        var vid = card.querySelector('input[name="vendor_id"]').value;
        document.getElementById('gross_' + vid).textContent = '₹' + gross.toFixed(2);
        document.getElementById('ded_' + vid).textContent = '−₹' + ded.toFixed(2);
        document.getElementById('add_' + vid).textContent = '+₹' + add.toFixed(2);
        document.getElementById('net_' + vid).textContent = '₹' + net.toFixed(2);
        summary.style.display = 'flex';
    } else {
        summary.style.display = 'none';
    }
}

function selectReconcileOption(el, opt) {
    var parent = el.closest('.settle-card') || el.closest('.tab-content');
    parent.querySelectorAll('.reconcile-option').forEach(function(o) { o.classList.remove('selected'); });
    el.classList.add('selected');
    document.getElementById('reconcileOptionBatch').style.display = opt === 'batch' ? 'block' : 'none';
    document.getElementById('reconcileOptionMark').style.display = opt === 'reconcile' ? 'block' : 'none';
    syncReconcileInputs();
}

function selectForceOption(el, opt) {
    var parent = el.closest('.settle-card');
    parent.querySelectorAll('.reconcile-option').forEach(function(o) { o.classList.remove('selected'); });
    el.classList.add('selected');
    document.getElementById('forceBatchArea').style.display = opt === 'batch' ? 'block' : 'none';
    document.getElementById('forceReconcileArea').style.display = opt === 'reconcile' ? 'block' : 'none';
    syncForceInputs();
}

function syncReconcileInputs() {
    var checked = document.querySelectorAll('.orphan-chk:checked');
    var batchDiv = document.getElementById('batchCylinderInputs');
    var reconDiv = document.getElementById('reconcileCylinderInputs');
    batchDiv.innerHTML = '';
    reconDiv.innerHTML = '';
    checked.forEach(function(cb) {
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'cylinder_ids[]';
        h.value = cb.value;
        batchDiv.appendChild(h.cloneNode(true));
        reconDiv.appendChild(h.cloneNode(true));
    });
}

function syncForceInputs() {
    var checked = document.querySelectorAll('.force-chk:checked');
    var batchDiv = document.getElementById('forceCylinderInputs');
    var reconDiv = document.getElementById('forceReconcileInputs');
    batchDiv.innerHTML = '';
    reconDiv.innerHTML = '';
    checked.forEach(function(cb) {
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'cylinder_ids[]';
        h.value = cb.value;
        batchDiv.appendChild(h.cloneNode(true));
        reconDiv.appendChild(h.cloneNode(true));
    });
}

document.querySelectorAll('.orphan-chk, .force-chk').forEach(function(cb) {
    cb.addEventListener('change', function() {
        syncReconcileInputs();
        syncForceInputs();
    });
});

document.querySelectorAll('input[name="payment_option"]').forEach(function(r) {
    r.addEventListener('change', function() { togglePaidFields(this); });
});

// Init
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="payment_option"]:checked').forEach(function(r) { togglePaidFields(r); });
});

// ── Bulk Pay Impact Analysis ──
function bulkPayAnalyze(event, form, vendorId) {
    event.preventDefault();
    const checkboxes = form.querySelectorAll('input[name="lot_ids[]"]:checked');
    const lotIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
    if (lotIds.length === 0) { alert('Select at least one lot to pay.'); return false; }
    bulkOp.analyze(lotIds, 'pay', { vendor_id: vendorId }, window.location.href);
    bulkOp.confirmCallback = function(report, context) { form.submit(); };
    return false;
}
</script>
<?php require_once __DIR__ . '/bulk_operation_dialog.php'; ?>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
