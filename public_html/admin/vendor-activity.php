<?php
$page_title = 'Vendor Activity Log';
$active_menu = 'vendors';
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/csrf.php';

runVendorActivityLogMigration($pdo);

$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;
if ($vendor_id <= 0) { echo "<script>window.location.href='vendors.php';</script>"; exit(); }

$v_row = $pdo->prepare("SELECT name, mobile, gst_number FROM vendors WHERE id = ?");
$v_row->execute([$vendor_id]);
$vendor = $v_row->fetch();
if (!$vendor) { echo "<script>window.location.href='vendors.php';</script>"; exit(); }

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 30;
$offset = ($page - 1) * $limit;
$type_filter = $_GET['type'] ?? 'all';

$activity_data = getVendorActivityLog($pdo, $vendor_id, $limit, $offset, $type_filter);
$total_pages = max(1, ceil($activity_data['total'] / $limit));
?>
<link rel="stylesheet" href="vendor.css">
<div class="page-header">
    <div class="page-header-title">
        <h2>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Activity Log: <?= htmlspecialchars($vendor['name']) ?>
        </h2>
        <p>Complete audit trail of all vendor interactions</p>
    </div>
    <div class="page-header-actions">
        <a href="vendor-profile.php?id=<?= $vendor_id ?>" class="btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Profile
        </a>
        <a href="vendor-ledger.php?vendor_id=<?= $vendor_id ?>" class="btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Ledger
        </a>
    </div>
</div>

<div class="admin-card" style="margin-bottom:1.5rem;">
    <div style="display:flex;gap:0.4rem;padding:0.8rem 1.25rem;flex-wrap:wrap;border-bottom:1px solid var(--admin-border);">
        <?php
        $filter_defs = [
            'all' => ['label' => 'All', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'],
            'dispatch' => ['label' => 'Dispatch', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/></svg>'],
            'receive' => ['label' => 'Receive', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 15 21 19 3 19 3 15"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'],
            'payment_made' => ['label' => 'Payment', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
            'advance_settled' => ['label' => 'Advance Settled', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 8 8 12 12 16"/><line x1="16" y1="12" x2="8" y2="12"/></svg>'],
            'borrow' => ['label' => 'Borrow', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20 12v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/></svg>'],
            'return' => ['label' => 'Return', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>'],
            'lend' => ['label' => 'Lend', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 7 12 3 8 7"/><line x1="12" y1="3" x2="12" y2="12"/><path d="M20 12v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/></svg>'],
        ];
        foreach ($filter_defs as $fkey => $fdef):
            $url = 'vendor-activity.php?vendor_id=' . $vendor_id . '&type=' . $fkey;
        ?>
        <a href="<?= $url ?>" class="filter-chip" style="background:<?= $type_filter === $fkey ? 'var(--admin-accent)' : 'var(--admin-bg-secondary)' ?>;color:<?= $type_filter === $fkey ? '#fff' : 'var(--admin-text)' ?>;border:1px solid <?= $type_filter === $fkey ? 'var(--admin-accent)' : 'var(--admin-border)' ?>;">
            <?= $fdef['svg'] ?> <?= $fdef['label'] ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($activity_data['rows'])): ?>
    <div style="padding:3rem;text-align:center;color:var(--admin-muted);">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.2;margin-bottom:0.75rem;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        <p style="font-size:0.9rem;">No activity records found for this filter.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="admin-table act-table">
        <thead>
            <tr>
                <th class="act-icon-cell"></th>
                <th>Date &amp; Time</th>
                <th>Activity</th>
                <th>Details</th>
                <th style="text-align:right;">Amount</th>
                <th style="text-align:center;">Cyl</th>
                <th>Reference</th>
                <th>By</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $act_svgs = [
            'dispatch' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/></svg>',
            'receive' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 15 21 19 3 19 3 15"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
            'payment_made' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            'advance_settled' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 8 8 12 12 16"/><line x1="16" y1="12" x2="8" y2="12"/></svg>',
            'advance_paid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
            'borrow' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20 12v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/></svg>',
            'lend' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 7 12 3 8 7"/><line x1="12" y1="3" x2="12" y2="12"/><path d="M20 12v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/></svg>',
            'return' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
            'adjustment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
            'invoice_created' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
            'invoice_paid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        ];
        $act_colors = [
            'dispatch' => ['bg' => '#eef2ff', 'fg' => '#3b82f6'],
            'receive' => ['bg' => '#ecfdf5', 'fg' => '#10b981'],
            'payment_made' => ['bg' => '#ecfdf5', 'fg' => '#059669'],
            'advance_settled' => ['bg' => '#fffbeb', 'fg' => '#d97706'],
            'borrow' => ['bg' => '#f5f3ff', 'fg' => '#7c3aed'],
            'return' => ['bg' => '#f3f4f6', 'fg' => '#6b7280'],
        ];
        $amt_colors = ['payment_made' => '#059669', 'advance_paid' => '#059669', 'advance_settled' => '#d97706'];
        foreach ($activity_data['rows'] as $act):
            $at = $act['activity_type'];
            $svg = $act_svgs[$at] ?? $act_svgs['adjustment'];
            $cl = $act_colors[$at] ?? ['bg' => '#f3f4f6', 'fg' => '#6b7280'];
            $title = htmlspecialchars($act['title'] ?? '');
            $desc = htmlspecialchars($act['description'] ?? '');
            $amt = floatval($act['amount']);
            $cyl = intval($act['cylinder_count']);
            $lot = htmlspecialchars($act['lot_number'] ?? '');
            $inv = htmlspecialchars($act['invoice_number'] ?? '');
            $by = htmlspecialchars($act['created_by'] ?? '');
            $dt = date('d-M h:i A', strtotime($act['activity_date']));
        ?>
            <tr>
                <td class="act-icon-cell">
                    <span class="icon-wrap" style="background:<?= $cl['bg'] ?>;color:<?= $cl['fg'] ?>;"><?= $svg ?></span>
                </td>
                <td style="font-size:0.72rem;color:var(--admin-muted);white-space:nowrap;"><?= $dt ?></td>
                <td><span style="font-weight:600;color:<?= $cl['fg'] ?>;"><?= $title ?></span></td>
                <td style="font-size:0.72rem;color:var(--admin-muted);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= $desc ?>"><?= $desc ?: '—' ?></td>
                <td style="text-align:right;font-weight:700;font-family:'Courier New',monospace;white-space:nowrap;color:<?= $amt > 0 ? ($amt_colors[$at] ?? 'var(--admin-text)') : 'var(--admin-muted)' ?>;"><?= $amt > 0 ? '₹' . number_format($amt, 0) : '—' ?></td>
                <td style="text-align:center;"><?= $cyl > 0 ? '<span class="act-badge-sm" style="background:#f3f4f6;color:#374151;">' . $cyl . '</span>' : '—' ?></td>
                <td style="font-size:0.75rem;">
                    <?php if ($lot): ?>
                    <a href="lot-dashboard.php?lot_number=<?= urlencode($lot) ?>" class="act-badge-sm" style="background:#dbeafe;color:#1e40af;text-decoration:none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/></svg>
                        <?= $lot ?>
                    </a>
                    <?php elseif ($inv): ?>
                    <span class="act-badge-sm" style="background:#fef3c7;color:#92400e;"><?= $inv ?></span>
                    <?php else: ?>
                    <span style="color:var(--admin-muted);">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.72rem;color:var(--admin-muted);"><?= $by ?: '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="display:flex;justify-content:center;align-items:center;gap:0.3rem;padding:0.85rem 1.25rem;border-top:1px solid var(--admin-border);">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="vendor-activity.php?vendor_id=<?= $vendor_id ?>&type=<?= urlencode($type_filter) ?>&page=<?= $i ?>" class="pg-btn" style="background:<?= $i === $page ? 'var(--admin-accent)' : 'var(--admin-bg-secondary)' ?>;color:<?= $i === $page ? '#fff' : 'var(--admin-text)' ?>;border:1px solid <?= $i === $page ? 'var(--admin-accent)' : 'var(--admin-border)' ?>;"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <div style="padding:0.6rem 1.25rem;border-top:1px solid var(--admin-border);font-size:0.72rem;color:var(--admin-muted);display:flex;justify-content:space-between;align-items:center;">
        <span><?= count($activity_data['rows']) ?> of <?= $activity_data['total'] ?> records</span>
        <span style="font-size:0.68rem;opacity:0.7;">Full audit trail since implementation</span>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
