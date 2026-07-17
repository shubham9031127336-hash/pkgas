<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
$page_title = "Dispatch Lots";
$active_menu = "lot_dashboard";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';

runDispatchLotMigrations($pdo);
runLotSettlementRedesignMigration($pdo);
runTransportCostMigrations($pdo);
runVendorActivityLogMigration($pdo);

$filter_vendor = intval($_GET['vendor_id'] ?? 0);
$filter_status = trim($_GET['status'] ?? '');
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['p'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];

if ($filter_vendor > 0) {
    $where[] = "dl.vendor_id = ?";
    $params[] = $filter_vendor;
}
if ($filter_status) {
    if ($filter_status === 'paid') {
        $where[] = "dl.payment_status = 'paid'";
    } elseif ($filter_status === 'unpaid') {
        $where[] = "dl.payment_status IN ('unpaid','partial')";
    } elseif ($filter_status === 'open') {
        $where[] = "dl.lot_status = 'open'";
    } elseif ($filter_status === 'partial_return') {
        $where[] = "dl.lot_status = 'partial_return'";
    } elseif ($filter_status === 'completed') {
        $where[] = "dl.lot_status = 'completed'";
    }
}
if ($filter_date_from) {
    $where[] = "DATE(dl.dispatch_date) >= ?";
    $params[] = $filter_date_from;
}
if ($filter_date_to) {
    $where[] = "DATE(dl.dispatch_date) <= ?";
    $params[] = $filter_date_to;
}
if ($search) {
    $where[] = "(dl.lot_number LIKE ? OR v.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$count_q = $pdo->prepare("SELECT COUNT(*) FROM dispatch_lots dl JOIN vendors v ON dl.vendor_id = v.id $where_clause");
$count_q->execute($params);
$total_lots = intval($count_q->fetchColumn());
$total_pages = max(1, ceil($total_lots / $per_page));

$lots_q = $pdo->prepare("
    SELECT dl.*, v.name as vendor_name, v.gst_number
    FROM dispatch_lots dl
    JOIN vendors v ON dl.vendor_id = v.id
    $where_clause
    ORDER BY dl.id DESC
    LIMIT $per_page OFFSET $offset
");
$lots_q->execute($params);
$lots = $lots_q->fetchAll();

$vendors = $pdo->query("SELECT * FROM vendors ORDER BY name ASC")->fetchAll();

// ── POST: Settle Lot (record payment) ──
require_once __DIR__ . '/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_lot') {
    validateCsrfToken();
    $lot_id = intval($_POST['lot_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
    $payment_date_raw = $_POST['payment_date'] ?? date('Y-m-d\TH:i');
    $payment_date = str_replace('T', ' ', $payment_date_raw) . ':00';
    $reference = trim($_POST['reference'] ?? '');
    $error = '';

    if ($lot_id <= 0 || $amount <= 0) {
        $error = 'Invalid lot or amount.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id = ?");
            $stmt->execute([$lot_id]);
            $lot = $stmt->fetch();
            if (!$lot) throw new Exception("Lot not found.");
            $vendor_id = intval($lot['vendor_id']);
            $balance = floatval($lot['remaining_balance'] ?? 0);

            if ($amount > $balance) {
                $amount = $balance;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO payments (lot_id, vendor_id, amount, payment_method, payment_type, payment_subtype, notes, payment_date) VALUES (?, ?, ?, ?, 'vendor_refill_payment', 'settlement', ?, ?)");
            $stmt->execute([$lot_id, $vendor_id, $amount, $payment_method, "Settlement payment for {$lot['lot_number']}" . ($reference ? " | Ref: $reference" : ""), $payment_date]);

            addVendorRefillLedgerEntry($pdo, $vendor_id, $amount, 'payment', $lot_id, "Lot settlement {$lot['lot_number']} ($payment_method" . ($reference ? " - $reference" : "") . ")", $_SESSION['username'] ?? 'admin', 'dispatch_lot');

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

            // (cache removed)

            $_SESSION['success_flash'] = "Payment of \u20B9" . number_format($amount, 2) . " recorded for {$lot['lot_number']}.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Settlement failed: ' . $e->getMessage();
        }
    }
    if ($error) {
        $_SESSION['error_flash'] = $error;
    }
    echo "<script>window.location.href='lot-dashboard.php';</script>";
    exit();
}
?>
<style>
.lot-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; margin-bottom:0.85rem; padding:1.1rem 1.35rem; transition:all 0.2s; position:relative; }
.lot-card:hover { box-shadow:0 4px 16px rgba(0,0,0,0.07); border-color:#cbd5e1; }
.lot-card-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.55rem; flex-wrap:wrap; gap:0.5rem; }
.lot-card-title { font-size:1.05rem; font-weight:800; color:#2563eb; text-decoration:none; }
.lot-card-title:hover { text-decoration:underline; }
.lot-card-vendor { font-size:0.85rem; font-weight:600; color:#475569; margin-left:0.4rem; }
.badge-row { display:flex; gap:0.35rem; flex-wrap:wrap; }
.badge { display:inline-flex; align-items:center; gap:0.25rem; padding:0.2rem 0.7rem; border-radius:20px; font-size:0.72rem; font-weight:700; }
.badge-green { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
.badge-amber { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.badge-red { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.badge-blue { background:#dbeafe; color:#1e40af; border:1px solid #bfdbfe; }
.badge-purple { background:#f3e8ff; color:#6b21a8; border:1px solid #d8b4fe; }
.lot-meta { display:flex; gap:1.25rem; flex-wrap:wrap; font-size:0.8rem; color:#64748b; margin-bottom:0.65rem; padding-bottom:0.6rem; border-bottom:1px solid #f1f5f9; }
.lot-meta span { display:inline-flex; align-items:center; gap:0.3rem; }
.lot-meta-dot { width:5px; height:5px; border-radius:50%; background:#cbd5e1; display:inline-block; }
.lot-progress-wrap { margin-bottom:0.75rem; }
.lot-progress-header { display:flex; justify-content:space-between; font-size:0.78rem; color:#64748b; margin-bottom:0.25rem; }
.lot-progress-bar { height:6px; background:#f1f5f9; border-radius:99px; overflow:hidden; }
.lot-progress-fill { height:100%; border-radius:99px; transition:width 0.4s ease; }
.lot-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(90px, 1fr)); gap:0.65rem; margin-bottom:0.7rem; }
.lot-stat { text-align:center; padding:0.4rem 0.25rem; background:#f8fafc; border-radius:10px; border:1px solid #f1f5f9; }
.lot-stat-val { font-size:1rem; font-weight:800; line-height:1.3; }
.lot-stat-lbl { font-size:0.65rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; }
.lot-actions { display:flex; gap:0.5rem; flex-wrap:wrap; padding-top:0.6rem; border-top:1px solid #f1f5f9; }
.filter-bar { display:flex; gap:0.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1.25rem; padding:1rem; background:#f8fafc; border-radius:12px; border:1px solid #e2e8f0; }
.filter-bar .form-group { margin-bottom:0; }
.filter-bar label { font-size:0.75rem; font-weight:600; color:#64748b; margin-bottom:0.2rem; display:block; }
.pagination { display:flex; gap:0.35rem; justify-content:center; margin-top:1.5rem; }
.pagination a, .pagination span { padding:0.4rem 0.75rem; border-radius:6px; font-size:0.82rem; font-weight:600; text-decoration:none; border:1px solid #e2e8f0; color:#1e293b; }
.pagination a:hover { background:#f1f5f9; }
.pagination .active { background:#2563eb; color:#fff; border-color:#2563eb; }
</style>

<div class="content-container">
    <div style="margin-bottom:1.5rem;">
        <h2 style="font-size:1.75rem;font-weight:800;letter-spacing:-0.02em;">Dispatch Lots</h2>
        <p style="color:var(--admin-muted);font-size:0.9rem;margin-top:0.25rem;">
            Manage all cylinder dispatch lots. Track returns, payments, and settlements per lot.
            <a href="send-cylinder.php" style="font-weight:600;color:var(--admin-accent);">+ New Dispatch</a>
        </p>
    </div>

    <?php
    $flash_msg = $_SESSION['success_flash'] ?? '';
    $flash_err = $_SESSION['error_flash'] ?? '';
    unset($_SESSION['success_flash'], $_SESSION['error_flash']);
    ?>
    <?php if ($flash_msg): ?>
    <div class="alert-banner" style="margin-bottom:1rem;"><span><strong>Success:</strong> <?php echo htmlspecialchars($flash_msg); ?></span><button class="modal-close" onclick="this.parentElement.remove()"></button></div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
    <div class="alert-banner bg-danger-soft text-danger border-danger" style="margin-bottom:1rem;"><span><strong>Error:</strong> <?php echo htmlspecialchars($flash_err); ?></span><button class="modal-close" onclick="this.parentElement.remove()"></button></div>
    <?php endif; ?>

    <form method="GET" class="filter-bar">
        <div class="form-group">
            <label>Vendor</label>
            <select name="vendor_id" class="form-control" style="min-width:180px;">
                <option value="">All Vendors</option>
                <?php foreach ($vendors as $v): ?>
                <option value="<?php echo $v['id']; ?>"<?php echo $filter_vendor === intval($v['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status" class="form-control" style="min-width:130px;">
                <option value="">All Statuses</option>
                <option value="open"<?php echo $filter_status === 'open' ? ' selected' : ''; ?>>Open</option>
                <option value="partial_return"<?php echo $filter_status === 'partial_return' ? ' selected' : ''; ?>>Partial Return</option>
                <option value="completed"<?php echo $filter_status === 'completed' ? ' selected' : ''; ?>>Completed</option>
                <option value="paid"<?php echo $filter_status === 'paid' ? ' selected' : ''; ?>>Paid</option>
                <option value="unpaid"<?php echo $filter_status === 'unpaid' ? ' selected' : ''; ?>>Unpaid</option>
            </select>
        </div>
        <div class="form-group">
            <label>From</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
        </div>
        <div class="form-group">
            <label>To</label>
            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
        </div>
        <div class="form-group">
            <label>Search</label>
            <input type="text" name="search" class="form-control" placeholder="Lot # or vendor..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="form-group">
            <button type="submit" class="btn-primary" style="padding:0.45rem 1rem;font-size:0.85rem;">Filter</button>
            <a href="lot-dashboard.php" class="btn-sm" style="background:#e2e8f0;color:#475569;padding:0.45rem 1rem;font-size:0.85rem;text-decoration:none;">Clear</a>
        </div>
    </form>

    <?php if (count($lots) === 0): ?>
    <div style="text-align:center;padding:3rem 0;color:var(--admin-muted);">
        <p style="font-size:1.1rem;font-weight:600;">No dispatch lots found</p>
        <p style="font-size:0.9rem;">Create a new dispatch to start tracking lots.</p>
        <a href="send-cylinder.php" class="btn-primary" style="display:inline-flex;margin-top:1rem;">+ New Dispatch</a>
    </div>
    <?php else: ?>
    <p style="font-size:0.85rem;color:var(--admin-muted);margin-bottom:0.75rem;">
        Showing <?php echo count($lots); ?> of <?php echo $total_lots; ?> lot(s)
    </p>

    <?php foreach ($lots as $lot):
        $pending = intval($lot['cylinder_count']) - intval($lot['returned_count']);
        $advance = floatval($lot['advance_amount']);
        $total_paid = floatval($lot['total_paid']);
        $remaining = floatval($lot['remaining_balance']);
        $lot_status = $lot['lot_status'];
        $pay_status = $lot['payment_status'];
        $final_refill = floatval($lot['final_refill_amount'] ?? 0);
        $final_gst = floatval($lot['final_gst_amount'] ?? 0);
        $final_total_val = floatval($lot['final_total'] ?? 0);
        $has_final = $final_total_val > 0;
        $dispatch_transport = floatval($lot['dispatch_transport_total'] ?? 0);
        $receive_transport = floatval($lot['receive_transport_total'] ?? 0);
        $total_transport = $dispatch_transport + $receive_transport;
        $has_transport = $total_transport > 0;
        $transport_settled = $total_transport <= $total_paid;
        $pct = intval($lot['cylinder_count']) > 0 ? round(intval($lot['returned_count']) / intval($lot['cylinder_count']) * 100) : 0;

        $lot_badge = 'badge-blue';
        $lot_label = 'Open';
        $lot_icon = '○';
        if ($lot_status === 'partial_return') { $lot_badge = 'badge-amber'; $lot_label = 'Partial Return'; $lot_icon = '◐'; }
        elseif ($lot_status === 'completed') { $lot_badge = 'badge-green'; $lot_label = 'Completed'; $lot_icon = '●'; }

        $pay_badge = 'badge-red';
        $pay_label = 'Unpaid';
        $pay_icon = '✕';
        if ($pay_status === 'paid') { $pay_badge = 'badge-green'; $pay_label = 'Paid'; $pay_icon = '✓'; }
        elseif ($pay_status === 'partial') { $pay_badge = 'badge-amber'; $pay_label = 'Partially Paid'; $pay_icon = '◐'; }

        $prog_color = $pct >= 100 ? '#16a34a' : ($pct > 0 ? '#f59e0b' : '#3b82f6');
    ?>
    <div class="lot-card">
        <div class="lot-card-header">
            <div>
                <a href="javascript:void(0)" onclick="openLotDetail(<?php echo $lot['id']; ?>)" class="lot-card-title"><?php echo htmlspecialchars($lot['lot_number']); ?></a>
                <span class="lot-card-vendor"><?php echo htmlspecialchars($lot['vendor_name']); ?></span>
            </div>
            <div class="badge-row">
                <span class="badge <?php echo $lot_badge; ?>"><?php echo $lot_icon; ?> <?php echo $lot_label; ?></span>
                <span class="badge <?php echo $pay_badge; ?>"><?php echo $pay_icon; ?> <?php echo $pay_label; ?></span>
            </div>
        </div>

        <div class="lot-meta">
            <span>📅 <?php echo date('d M Y', strtotime($lot['dispatch_date'])); ?></span>
            <?php if ($lot['receive_date']): ?><span class="lot-meta-dot"></span><span>✅ <?php echo date('d M Y', strtotime($lot['receive_date'])); ?></span><?php endif; ?>
            <?php if ($lot['expected_return_date']): ?><span class="lot-meta-dot"></span><span>📌 <?php echo date('d M Y', strtotime($lot['expected_return_date'])); ?></span><?php endif; ?>
            <?php if ($lot['driver_name']): ?><span class="lot-meta-dot"></span><span>🚚 <?php echo htmlspecialchars($lot['driver_name']); ?><?php echo $lot['vehicle_number'] ? ' / ' . htmlspecialchars($lot['vehicle_number']) : ''; ?></span><?php endif; ?>
            <?php if ($lot['created_by']): ?><span class="lot-meta-dot"></span><span>👤 <?php echo htmlspecialchars($lot['created_by']); ?></span><?php endif; ?>
        </div>

        <div class="lot-progress-wrap">
            <div class="lot-progress-header">
                <span>Returns: <?php echo intval($lot['returned_count']); ?> / <?php echo intval($lot['cylinder_count']); ?> cylinders</span>
                <span style="font-weight:700;color:<?php echo $prog_color; ?>"><?php echo $pct; ?>%</span>
            </div>
            <div class="lot-progress-bar">
                <div class="lot-progress-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $prog_color; ?>;"></div>
            </div>
        </div>

        <?php
        $invoice_label = '';
        if ($has_final) {
            if ($final_gst > 0) {
                $invoice_label = '₹' . number_format($final_total_val, 0) . ' (₹' . number_format($final_refill, 0) . ' + ₹' . number_format($final_gst, 0) . ' GST)';
            } else {
                $invoice_label = '₹' . number_format($final_total_val, 0);
            }
        } elseif ($advance > 0) {
            $invoice_label = 'Adv: ₹' . number_format($advance, 0);
        }
        ?>
        <div class="lot-stats">
            <div class="lot-stat">
                <div class="lot-stat-val" style="color:#1e40af;"><?php echo intval($lot['cylinder_count']); ?></div>
                <div class="lot-stat-lbl">Total</div>
            </div>
            <div class="lot-stat">
                <div class="lot-stat-val" style="color:#16a34a;"><?php echo intval($lot['returned_count']); ?></div>
                <div class="lot-stat-lbl">Returned</div>
            </div>
            <div class="lot-stat">
                <div class="lot-stat-val" style="color:#dc2626;"><?php echo $pending; ?></div>
                <div class="lot-stat-lbl">Pending</div>
            </div>
            <?php if ($invoice_label): ?>
            <div class="lot-stat">
                <div class="lot-stat-val" style="color:#075985;font-size:0.9rem;"><?php echo $invoice_label; ?></div>
                <div class="lot-stat-lbl"><?php echo $has_final ? 'Invoice' : 'Advance'; ?></div>
            </div>
            <?php endif; ?>
            <?php $stat_settled = $remaining <= 0 && $pending <= 0; ?>
            <div class="lot-stat" style="background:<?php echo $stat_settled ? '#f0fdf4' : '#fef2f2'; ?>;border-color:<?php echo $stat_settled ? '#bbf7d0' : '#fecaca'; ?>;">
                <div class="lot-stat-val" style="color:<?php echo $stat_settled ? '#16a34a' : '#dc2626'; ?>;">₹<?php echo number_format($remaining, 0); ?></div>
                <div class="lot-stat-lbl"><?php echo $stat_settled ? 'Settled' : 'Due'; ?></div>
            </div>
            <?php if ($total_transport > 0 || $dispatch_transport > 0): ?>
            <div class="lot-stat" style="background:<?php echo $transport_settled ? '#f0fdf4' : '#fefce8'; ?>;border-color:<?php echo $transport_settled ? '#bbf7d0' : '#fde68a'; ?>;cursor:pointer;" onclick="openTransportModal(<?php echo $lot['id']; ?>)" title="<?php echo $transport_settled ? 'Transport cost — final' : 'Transport cost — may vary (lot not fully settled)'; ?>">
                <div class="lot-stat-val" style="font-size:0.85rem;color:<?php echo $transport_settled ? '#16a34a' : '#92400e'; ?>;">
                    <?php echo $transport_settled ? '✓' : '⏳'; ?> ₹<?php echo number_format($total_transport, 0); ?>
                </div>
                <div class="lot-stat-lbl">Transport</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="lot-actions">
            <button class="btn-sm" onclick="openLotDetail(<?php echo $lot['id']; ?>)" style="font-size:0.78rem;padding:0.35rem 0.8rem;">View Details</button>
            <?php if ($lot_status !== 'completed'): ?>
            <a href="receive-cylinder.php?lot_id=<?php echo $lot['id']; ?>" class="btn-sm" style="font-size:0.78rem;padding:0.35rem 0.8rem;text-decoration:none;">Receive Cylinders</a>
            <?php endif; ?>
            <?php if ($remaining > 0): ?>
            <button class="btn-sm" onclick="openSettleModal(<?php echo $lot['id']; ?>, <?php echo intval($lot['vendor_id']); ?>, '<?php echo htmlspecialchars($lot['lot_number'], ENT_QUOTES); ?>', <?php echo $remaining; ?>)" style="font-size:0.78rem;padding:0.35rem 0.8rem;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer;" title="Outstanding balance (refill<?php echo !empty($lot['gst_applicable']) ? ' + GST' : ''; ?>)">
                Pay ₹<?php echo number_format($remaining, 0); ?><?php echo !empty($lot['gst_applicable']) ? ' (incl. GST)' : ''; ?>
            </button>
            <?php elseif ($pending > 0): ?>
            <span style="font-size:0.7rem;color:#92400e;background:#fef3c7;padding:0.2rem 0.5rem;border-radius:4px;font-weight:600;">⚠ Received portion settled — awaiting remaining cylinders</span>
            <?php endif; ?>
            <?php if (intval($lot['returned_count']) === 0): ?>
            <span style="font-size:0.7rem;color:#92400e;background:#fef3c7;padding:0.2rem 0.5rem;border-radius:4px;font-weight:600;">⚠ No cylinders received yet</span>
            <?php endif; ?>
            <?php if ($has_transport): ?>
            <button class="btn-sm" onclick="openTransportModal(<?php echo $lot['id']; ?>)" style="font-size:0.78rem;padding:0.35rem 0.8rem;text-decoration:none;background:#fef3c7;color:#92400e;border:none;border-radius:6px;cursor:pointer;">
                🚚 Transport ₹<?php echo number_format($total_transport, 0); ?>
            </button>
            <?php endif; ?>
            <a href="dispatch-lot-print.php?id=<?php echo $lot['id']; ?>" class="btn-sm" style="font-size:0.78rem;padding:0.35rem 0.8rem;text-decoration:none;background:#f1f5f9;color:#475569;" target="_blank">Print</a>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?p=<?php echo ($page-1); ?>&vendor_id=<?php echo $filter_vendor; ?>&status=<?php echo urlencode($filter_status); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&search=<?php echo urlencode($search); ?>">« Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?p=<?php echo $i; ?>&vendor_id=<?php echo $filter_vendor; ?>&status=<?php echo urlencode($filter_status); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?p=<?php echo ($page+1); ?>&vendor_id=<?php echo $filter_vendor; ?>&status=<?php echo urlencode($filter_status); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&search=<?php echo urlencode($search); ?>">Next »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Detail Modal -->
<div class="modal" id="lotDetailModal">
    <div class="modal-content" style="max-width:820px;border-radius:16px;">
        <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:1rem 1.5rem;">
            <h3 id="modalLotTitle" style="font-size:1.15rem;font-weight:800;color:#1e293b;">Lot Details</h3>
            <button class="modal-close" onclick="closeModal('lotDetailModal')" style="width:32px;height:32px;border-radius:8px;border:none;background:#f1f5f9;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;">&times;</button>
        </div>
        <div id="modalLotBody" style="padding:1.25rem 1.5rem;">
            <p style="text-align:center;padding:2rem 0;color:#64748b;font-size:0.9rem;">Loading lot details...</p>
        </div>
    </div>
</div>

<!-- Settle Lot Modal -->
<div class="modal" id="settleLotModal">
    <div class="modal-content" style="max-width:480px;border-radius:16px;">
        <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:1rem 1.5rem;">
            <h3 style="font-size:1.1rem;font-weight:800;color:#1e293b;">Settle Lot Payment</h3>
            <button class="modal-close" onclick="closeModal('settleLotModal')" style="width:32px;height:32px;border-radius:8px;border:none;background:#f1f5f9;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;">&times;</button>
        </div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="settle_lot">
            <input type="hidden" name="lot_id" id="settleLotId">
            <div style="padding:1.25rem 1.5rem;display:grid;gap:0.85rem;">
                <div style="background:#f8fafc;padding:0.75rem 1rem;border-radius:8px;border:1px solid #e2e8f0;">
                    <div style="font-size:0.75rem;color:var(--admin-muted);font-weight:600;">Lot</div>
                    <div style="font-weight:800;color:#2563eb;font-size:1rem;" id="settleLotNumber"></div>
                </div>
                <div style="background:#fef2f2;padding:0.75rem 1rem;border-radius:8px;border:1px solid #fecaca;">
                    <div style="font-size:0.75rem;color:#991b1b;font-weight:600;">Outstanding Balance</div>
                    <div style="font-weight:800;font-size:1.3rem;color:#dc2626;" id="settleBalanceDisplay"></div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem;font-weight:600;color:#475569;display:block;margin-bottom:0.25rem;">Amount (₹) <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="amount" id="settleAmount" class="form-control" step="0.01" min="1" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem;font-weight:600;color:#475569;display:block;margin-bottom:0.25rem;">Payment Method <span style="color:#dc2626;">*</span></label>
                    <select name="payment_method" class="form-control" required>
                        <option value="">-- Select --</option>
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
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem;font-weight:600;color:#475569;display:block;margin-bottom:0.25rem;">Payment Date <span style="color:#dc2626;">*</span></label>
                    <input type="datetime-local" name="payment_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem;font-weight:600;color:#475569;display:block;margin-bottom:0.25rem;">Reference</label>
                    <input type="text" name="reference" class="form-control" placeholder="Optional ref / transaction ID">
                </div>
            </div>
            <div style="padding:1rem 1.5rem;border-top:1px solid #f1f5f9;">
                <button type="submit" class="btn-primary" style="width:100%;justify-content:center;padding:0.6rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:6px;"><polyline points="20 6 9 17 4 12"/></svg>
                    Record Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transport Cost Modal -->
<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openSettleModal(lotId, vendorId, lotNumber, balance) {
    document.getElementById('settleLotId').value = lotId;
    document.getElementById('settleLotNumber').textContent = lotNumber;
    document.getElementById('settleBalanceDisplay').textContent = '\u20B9' + balance.toFixed(2);
    document.getElementById('settleAmount').value = balance.toFixed(2);
    openModal('settleLotModal');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal('settleLotModal');
});

function openLotDetail(lotId) {
    const modal = document.getElementById('lotDetailModal');
    const body = document.getElementById('modalLotBody');
    body.innerHTML = '<p style="text-align:center;color:var(--admin-muted);">Loading...</p>';
    openModal('lotDetailModal');

    fetch('get-lot-detail.php?id=' + lotId)
        .then(r => r.json())
        .then(data => {
            if (!data || data.error) {
                body.innerHTML = '<p style="color:#dc2626;">Error loading lot details.</p>';
                return;
            }
            renderLotDetail(data);
        })
        .catch(() => {
            body.innerHTML = '<p style="color:#dc2626;">Error loading lot details.</p>';
        });
}

function renderLotDetail(data) {
    const lot = data.lot;
    const cylinders = data.cylinders || [];
    const payments = data.payments || [];

    document.getElementById('modalLotTitle').textContent = 'Lot ' + lot.lot_number;

    const finalRefill = parseFloat(lot.final_refill_amount || 0);
    const finalGst = parseFloat(lot.final_gst_amount || 0);
    const finalTotal = parseFloat(lot.final_total || 0);
    const estRefill = parseFloat(lot.estimated_total || 0);
    const estGst = parseFloat(lot.gst_amount || 0);
    const totalPaid = parseFloat(lot.total_paid || 0);
    const remaining = parseFloat(lot.remaining_balance || 0);
    const hasFinal = finalTotal > 0;

    // ── Lot Info Grid ──
    let html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;margin-bottom:1rem;padding:0.85rem;background:#f8fafc;border-radius:10px;font-size:0.85rem;border:1px solid #f1f5f9;">' +
        '<div><span style="color:#64748b;font-size:0.72rem;font-weight:600;display:block;">VENDOR</span><span style="font-weight:700;">' + escHtml(lot.vendor_name) + '</span></div>' +
        '<div><span style="color:#64748b;font-size:0.72rem;font-weight:600;display:block;">LOT</span><span style="font-weight:700;color:#2563eb;">' + escHtml(lot.lot_number) + '</span></div>' +
        '<div><span style="color:#64748b;font-size:0.72rem;font-weight:600;display:block;">DISPATCH</span><span>' + (lot.dispatch_date || '—') + '</span></div>' +
        '<div><span style="color:#64748b;font-size:0.72rem;font-weight:600;display:block;">RECEIVED</span><span>' + (lot.receive_date || '—') + '</span></div>' +
        '<div><span style="font-weight:700;">' + lot.cylinder_count + ' Total</span></div>' +
        '<div><span style="font-weight:700;color:#16a34a;">' + lot.returned_count + ' Returned</span></div>' +
    '</div>';

    // ── Financial Summary Card ──
    const gstRate = parseFloat(lot.gst_rate || 0);
    const invLabel = hasFinal ? 'Invoice' : 'Est. Total';
    const invAmount = hasFinal ? finalTotal : (estRefill + estGst);
    const invGst = hasFinal ? finalGst : estGst;
    const dispTrans = parseFloat(lot.dispatch_transport_total || 0);
    const recvTrans = parseFloat(lot.receive_transport_total || 0);
    const totalTrans = dispTrans + recvTrans;

    html += '<div style="background:#fefce8;border:1px solid #fde68a;border-radius:12px;padding:0.85rem;margin-bottom:1rem;">';
    html += '<div style="font-size:0.8rem;font-weight:700;margin-bottom:0.6rem;color:#92400e;">Financial Summary</div>';
    html += '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.5rem;font-size:0.82rem;">' +
        '<div style="text-align:center;background:#fff;border-radius:8px;padding:0.5rem 0.3rem;border:1px solid #fde68a;"><div style="color:#92400e;font-weight:600;font-size:0.65rem;text-transform:uppercase;">' + invLabel + '</div><div style="font-weight:800;font-size:1.05rem;">₹' + invAmount.toFixed(0) + '</div></div>' +
        '<div style="text-align:center;background:#fff;border-radius:8px;padding:0.5rem 0.3rem;border:1px solid #fde68a;"><div style="color:#92400e;font-weight:600;font-size:0.65rem;text-transform:uppercase;">GST (' + gstRate + '%)</div><div style="font-weight:800;font-size:1.05rem;">₹' + invGst.toFixed(0) + '</div></div>' +
        '<div style="text-align:center;background:#eff6ff;border-radius:8px;padding:0.5rem 0.3rem;border:1px solid #bfdbfe;"><div style="color:#1e40af;font-weight:600;font-size:0.65rem;text-transform:uppercase;">Paid</div><div style="font-weight:800;color:#92400e;font-size:1.05rem;">₹' + totalPaid.toFixed(0) + '</div></div>' +
        '<div style="text-align:center;background:' + (remaining <= 0 ? '#f0fdf4' : '#fef2f2') + ';border-radius:8px;padding:0.5rem 0.3rem;border:1px solid ' + (remaining <= 0 ? '#bbf7d0' : '#fecaca') + ';"><div style="font-weight:600;font-size:0.65rem;text-transform:uppercase;color:' + (remaining <= 0 ? '#065f46' : '#991b1b') + ';">' + (remaining <= 0 ? 'Settled' : 'Due') + '</div><div style="font-weight:800;font-size:1.05rem;color:' + (remaining <= 0 ? '#16a34a' : '#dc2626') + ';">₹' + remaining.toFixed(0) + '</div></div>' +
        '<div style="text-align:center;background:' + (totalTrans > 0 ? '#fefce8' : '#f8fafc') + ';border-radius:8px;padding:0.5rem 0.3rem;border:1px solid ' + (totalTrans > 0 ? '#fde68a' : '#e2e8f0') + ';cursor:' + (totalTrans > 0 ? 'pointer' : 'default') + ';" onclick="' + (totalTrans > 0 ? "openTransportModal(" + lot.id + ")" : "") + '"><div style="font-weight:600;font-size:0.65rem;text-transform:uppercase;color:' + (totalTrans > 0 ? '#92400e' : '#64748b') + ';">Transport</div><div style="font-weight:800;font-size:1.05rem;color:' + (totalTrans > 0 ? '#92400e' : '#64748b') + ';">' + (totalTrans > 0 ? '₹' + totalTrans.toFixed(0) : '—') + '</div></div>' +
    '</div>';
    html += '</div>';

    // ── Cylinders table ──
    html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">' +
        '<h4 style="font-size:0.9rem;font-weight:700;margin:0;">Cylinders</h4>' +
        '<span style="font-size:0.8rem;color:#64748b;font-weight:600;">' + cylinders.length + ' total</span></div>';
    if (cylinders.length === 0) {
        html += '<p style="font-size:0.85rem;color:#64748b;padding:1rem 0;text-align:center;">No cylinders found.</p>';
    } else {
        html += '<div style="max-height:220px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:1rem;">';
        html += '<table style="width:100%;font-size:0.82rem;border-collapse:collapse;">';
        html += '<thead><tr style="background:#f8fafc;position:sticky;top:0;"><th style="padding:0.45rem 0.6rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Serial</th><th style="padding:0.45rem 0.6rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Gas</th><th style="padding:0.45rem 0.6rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Size</th><th style="padding:0.45rem 0.6rem;text-align:center;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Status</th><th style="padding:0.45rem 0.6rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Received</th></tr></thead>';
        html += '<tbody>';
        cylinders.forEach(c => {
            const statusBadge = c.dispatch_status === 'received'
                ? '<span style="display:inline-block;padding:0.1rem 0.5rem;border-radius:99px;font-size:0.7rem;font-weight:700;background:#d1fae5;color:#065f46;">Received</span>'
                : '<span style="display:inline-block;padding:0.1rem 0.5rem;border-radius:99px;font-size:0.7rem;font-weight:700;background:#fef3c7;color:#92400e;">Sent</span>';
            html += '<tr><td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;font-weight:600;font-size:0.82rem;">' + escHtml(c.serial_number) + '</td>' +
                '<td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;">' + escHtml(c.gas_name) + '</td>' +
                '<td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;color:#64748b;">' + escHtml(c.size_capacity) + '</td>' +
                '<td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;text-align:center;">' + statusBadge + '</td>' +
                '<td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:0.8rem;">' + (c.receive_date || '—') + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }

    // ── Payment Timeline ──
    if (payments.length > 0) {
        html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">' +
            '<h4 style="font-size:0.9rem;font-weight:700;margin:0;">Payment Timeline</h4>' +
            '<span style="font-size:0.8rem;color:#64748b;font-weight:600;">' + payments.length + ' entries</span></div>';
        html += '<div style="max-height:200px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:1rem;">';
        html += '<table style="width:100%;font-size:0.82rem;border-collapse:collapse;">';
        html += '<thead><tr style="background:#f8fafc;position:sticky;top:0;"><th style="padding:0.45rem 0.6rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Date</th><th style="padding:0.45rem 0.6rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Type</th><th style="padding:0.45rem 0.6rem;text-align:right;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Amount</th><th style="padding:0.45rem 0.6rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:0.7rem;text-transform:uppercase;color:#64748b;">Method</th></tr></thead>';
        html += '<tbody>';
        payments.forEach((p) => {
            const subtype = p.payment_subtype || p.payment_type || '';
            const typeLabel = subtype === 'advance_utilized' ? 'Adv. Utilized' : (subtype === 'settlement' ? 'Settlement' : (p.payment_type === 'vendor_payment' ? 'Advance' : escHtml(p.payment_type)));
            html += '<tr>' +
                '<td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;font-size:0.8rem;color:#64748b;">' + (p.payment_date || '—') + '</td>' +
                '<td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;font-weight:600;color:' + (typeLabel === 'Advance' ? '#92400e' : (typeLabel === 'Settlement' ? '#16a34a' : '#64748b')) + ';">' + escHtml(typeLabel) + '</td>' +
                '<td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;">₹' + parseFloat(p.amount).toFixed(0) + '</td>' +
                '<td style="padding:0.4rem 0.6rem;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:0.8rem;">' + escHtml(p.payment_method) + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }

    html += '<div style="margin-top:1rem;display:flex;gap:0.65rem;">' +
        '<a href="receive-cylinder.php?lot_id=' + lot.id + '" class="btn-primary" style="font-size:0.85rem;padding:0.55rem 1.1rem;text-decoration:none;border-radius:10px;">⬅ Receive Cylinders</a>' +
        '<a href="dispatch-lot-print.php?id=' + lot.id + '" class="btn-sm" style="font-size:0.85rem;padding:0.55rem 1.1rem;text-decoration:none;background:#f1f5f9;color:#475569;border-radius:10px;" target="_blank">🖨 Print Challan</a>' +
    '</div>';

    document.getElementById('modalLotBody').innerHTML = html;
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
</script>

<!-- Transport Cost Detail Modal -->
<div class="modal" id="transportCostModal">
    <div class="modal-content" style="max-width:520px;border-radius:16px;">
        <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:1rem 1.5rem;">
            <h3 style="font-size:1.1rem;font-weight:800;color:#1e293b;">Transportation Cost Breakdown</h3>
            <button class="modal-close" onclick="closeModal('transportCostModal')" style="width:32px;height:32px;border-radius:8px;border:none;background:#f1f5f9;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;">&times;</button>
        </div>
        <div id="transportCostBody" style="padding:1.25rem 1.5rem;">
            <p style="text-align:center;padding:2rem 0;color:#64748b;">Loading...</p>
        </div>
    </div>
</div>

<script>
function openTransportModal(lotId) {
    const modal = document.getElementById('transportCostModal');
    const body = document.getElementById('transportCostBody');
    body.innerHTML = '<p style="text-align:center;padding:2rem 0;color:var(--admin-muted);">Loading...</p>';
    openModal('transportCostModal');

    fetch('get-lot-detail.php?id=' + lotId)
        .then(r => r.json())
        .then(data => {
            if (!data || data.error) {
                body.innerHTML = '<p style="color:#dc2626;">Error loading transport details.</p>';
                return;
            }
            renderTransportBreakdown(data);
        })
        .catch(() => {
            body.innerHTML = '<p style="color:#dc2626;">Error loading transport details.</p>';
        });
}

function renderTransportBreakdown(data) {
    const lot = data.lot;
    const cylinders = data.cylinders || [];

    const dispatchTransport = parseFloat(lot.dispatch_transport_total || 0);
    const receiveTransport = parseFloat(lot.receive_transport_total || 0);
    const totalTransport = dispatchTransport + receiveTransport;

    // Per-cylinder breakdown
    let dispatchCyls = 0, receiveCyls = 0;
    let dispatchSum = 0, receiveSum = 0;
    cylinders.forEach(c => {
        const dt = parseFloat(c.dispatch_transport_cost || 0);
        const rt = parseFloat(c.receive_transport_cost || 0);
        if (dt > 0) { dispatchCyls++; dispatchSum += dt; }
        if (rt > 0) { receiveCyls++; receiveSum += rt; }
    });

    const dispatchPerCyl = dispatchCyls > 0 ? dispatchSum / dispatchCyls : 0;
    const receivePerCyl = receiveCyls > 0 ? receiveSum / receiveCyls : 0;

    let html = '<div style="display:grid;gap:1rem;">';

    // Dispatch Transport Card
    html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:1rem;">' +
        '<div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;color:#1e40af;margin-bottom:0.5rem;">Dispatch Transport</div>' +
        '<div style="display:flex;justify-content:space-between;font-size:0.9rem;padding:0.25rem 0;">' +
        '<span>Total Paid (when sent):</span><span style="font-weight:800;">₹' + dispatchTransport.toFixed(2) + '</span></div>' +
        (dispatchCyls > 0 ? '<div style="display:flex;justify-content:space-between;font-size:0.82rem;color:#64748b;padding:0.15rem 0;">' +
        '<span>Per cylinder:</span><span>₹' + dispatchPerCyl.toFixed(2) + ' × ' + dispatchCyls + ' cyl</span></div>' : '') +
        '</div>';

    // Receive Transport Card
    html += '<div style="background:#fefce8;border:1px solid #fde68a;border-radius:12px;padding:1rem;">' +
        '<div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;color:#92400e;margin-bottom:0.5rem;">Receive Transport</div>' +
        (receiveTransport > 0
            ? '<div style="display:flex;justify-content:space-between;font-size:0.9rem;padding:0.25rem 0;">' +
            '<span>Total Paid (when received):</span><span style="font-weight:800;">₹' + receiveTransport.toFixed(2) + '</span></div>' +
            (receiveCyls > 0 ? '<div style="display:flex;justify-content:space-between;font-size:0.82rem;color:#64748b;padding:0.15rem 0;">' +
            '<span>Per cylinder:</span><span>₹' + receivePerCyl.toFixed(2) + ' × ' + receiveCyls + ' cyl</span></div>' : '')
            : '<div style="font-size:0.85rem;color:#92400e;padding:0.25rem 0;">No receiving transport recorded yet.</div>'
        ) + '</div>';

    // Total
    html += '<div style="background:#f0fdf4;border:2px solid #bbf7d0;border-radius:12px;padding:1rem;text-align:center;">' +
        '<div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;color:#065f46;margin-bottom:0.35rem;">Total Transportation Cost</div>' +
        '<div style="font-size:1.5rem;font-weight:800;color:#065f46;">₹' + totalTransport.toFixed(2) + '</div>' +
        (dispatchTransport > 0 && receiveTransport > 0
            ? '<div style="font-size:0.78rem;color:#065f46;margin-top:0.25rem;">Dispatch: ₹' + dispatchTransport.toFixed(2) + ' + Receive: ₹' + receiveTransport.toFixed(2) + '</div>'
            : '') +
        '</div>';

    // Per-cylinder table
    const receivedCyls = cylinders.filter(c => c.dispatch_status === 'received');
    if (receivedCyls.length > 0 && (dispatchTransport > 0 || receiveTransport > 0)) {
        html += '<div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">';
        html += '<div style="background:#f8fafc;padding:0.5rem 0.75rem;font-size:0.72rem;font-weight:700;text-transform:uppercase;color:#64748b;border-bottom:1px solid #e2e8f0;">Per-Cylinder Details (' + receivedCyls.length + ' received)</div>';
        html += '<div style="max-height:180px;overflow-y:auto;">';
        receivedCyls.forEach(c => {
            const dt = parseFloat(c.dispatch_transport_cost || 0);
            const rt = parseFloat(c.receive_transport_cost || 0);
            const tt = dt + rt;
            html += '<div style="display:flex;justify-content:space-between;padding:0.3rem 0.75rem;font-size:0.8rem;border-bottom:1px solid #f1f5f9;">' +
                '<span style="font-weight:600;">' + escHtml(c.serial_number) + '</span>' +
                '<span>' +
                (dt > 0 ? 'D: ₹' + dt.toFixed(2) + ' ' : '') +
                (rt > 0 ? 'R: ₹' + rt.toFixed(2) + ' ' : '') +
                '<strong style="color:#065f46;">T: ₹' + tt.toFixed(2) + '</strong>' +
                '</span></div>';
        });
        html += '</div></div>';
    }

    // Expense link note
    html += '<div style="font-size:0.75rem;color:#64748b;text-align:center;padding:0.5rem 0 0;border-top:1px solid #e2e8f0;">' +
        'Transport expenses are auto-created in <a href="expenses.php?ref_type=dispatch_lot&ref_id=' + lot.id + '" style="color:var(--admin-accent);">Expenses → Transport Charges</a>' +
        '</div>';

    html += '</div>';
    document.getElementById('transportCostBody').innerHTML = html;
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
