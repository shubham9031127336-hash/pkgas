<?php
require_once __DIR__ . '/lang_init.php';
$page_title = 'Partner Ledger';
$active_menu = 'partners';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';

runVendorPartnerLedgerMigrations($pdo);

$partner_id = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : 0;
if ($partner_id <= 0) { echo "<script>window.location.href='partners.php';</script>"; exit(); }

$stmt = $pdo->prepare("SELECT company_name FROM partners WHERE id = ?");
$stmt->execute([$partner_id]);
$partner = $stmt->fetch();
if (!$partner) { echo "<script>window.location.href='partners.php';</script>"; exit(); }

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv = exportVendorPartnerLedgerCSV($pdo, 'partner', $partner_id, $from, $to);
    if ($csv) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=partner_ledger_' . $partner_id . '_' . date('Ymd') . '.csv');
        echo $csv; exit();
    }
}

$fin = getVendorPartnerBalances($pdo, 'partner', $partner_id);
$filters = ['from' => $from, 'to' => $to];
if (!empty($type)) $filters['type'] = $type;
$ledger = getVendorPartnerLedgerEntries($pdo, 'partner', $partner_id, $page, $limit, $filters);

$type_labels = [
    'payment' => 'Payment', 'advance' => 'Advance', 'advance_utilized' => 'Advance Utilized',
    'due_created' => 'Due Created', 'rent_charge' => 'Rent Charge', 'current_payment' => 'Current Payment',
    'adjustment' => 'Adjustment',
];
?>
<style>
.ci-top-wrap{background:var(--admin-card-bg,#fff);border:1px solid var(--admin-border,#e2e8f0);border-radius:12px;padding:0.75rem 1rem;margin-bottom:0.75rem;}
.ci-top-row{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;}
.ci-tools{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;}
.ci-select{padding:0.4rem 0.5rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.78rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;min-width:140px;}
.ci-d{padding:0.4rem 0.5rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:8px;font-size:0.78rem;background:var(--admin-bg,#f8fafc);color:var(--admin-text,#1e293b);outline:none;width:130px;}
.ci-stats{display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;}
.ci-st-i{display:inline-flex;align-items:center;gap:0.35rem;font-size:0.78rem;color:var(--admin-muted,#64748b);}
.ci-st-i strong{font-size:0.85rem;color:var(--admin-text,#1e293b);}
.ci-dot{width:6px;height:6px;border-radius:50%;display:inline-block;flex-shrink:0;}
.ci-dot-blue{background:#3b82f6;}
.ci-dot-green{background:#10b981;}
.ci-dot-red{background:#ef4444;}
.ci-st-sep{width:1px;height:18px;background:var(--admin-border,#e2e8f0);}
@media(max-width:768px){
.ci-d{width:110px;}
.ci-top-row{flex-direction:column;}
}
</style>
<link rel="stylesheet" href="partner.css">

<div class="page-header">
    <div class="page-header-title">
        <a href="partner-profile.php?id=<?= $partner_id ?>" style="text-decoration:none;color:var(--admin-muted);display:flex;align-items:center;gap:0.5rem;font-weight:700;margin-bottom:0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Back to Profile
        </a>
        <h2 style="margin:0;font-size:1.4rem;">Ledger: <?= htmlspecialchars($partner['company_name']) ?></h2>
    </div>
    <div class="page-header-actions">
        <a href="?partner_id=<?= $partner_id ?>&export=csv&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn-primary">Export CSV</a>
    </div>
</div>

<div class="ci-top-wrap">
    <div class="ci-top-row">
        <div class="ci-stats">
            <span class="ci-st-i"><span class="ci-dot" style="background:<?= ($fin['running_balance']??0)>0?'#059669':(($fin['running_balance']??0)<0?'#ef4444':'#64748b') ?>"></span><strong style="color:<?= ($fin['running_balance']??0)>0?'#059669':(($fin['running_balance']??0)<0?'#ef4444':'#64748b') ?>">₹<?= number_format($fin['running_balance']??0,2) ?></strong> Balance</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-green"></span><strong>₹<?= number_format($fin['advance_balance']??0,2) ?></strong> Advance</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-red"></span><strong>₹<?= number_format($fin['due_balance']??0,2) ?></strong> Due</span>
            <span class="ci-st-sep"></span>
            <span class="ci-st-i"><span class="ci-dot ci-dot-blue"></span><strong><?= number_format($fin['total_transactions']??0) ?></strong> Transactions</span>
        </div>
        <div class="ci-tools">
            <span style="font-size:0.78rem;color:var(--admin-muted);font-weight:600;">From:</span>
            <input type="date" class="ci-d" value="<?= htmlspecialchars($from) ?>" onchange="window.location.href='?partner_id=<?= $partner_id ?>&from='+this.value+'&to=<?= urlencode($to) ?>&type=<?= urlencode($type) ?>'">
            <span style="font-size:0.78rem;color:var(--admin-muted);font-weight:600;">To:</span>
            <input type="date" class="ci-d" value="<?= htmlspecialchars($to) ?>" onchange="window.location.href='?partner_id=<?= $partner_id ?>&from=<?= urlencode($from) ?>&to='+this.value+'&type=<?= urlencode($type) ?>'">
            <select class="ci-select" onchange="window.location.href='?partner_id=<?= $partner_id ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&type='+this.value">
                <option value="">All Types</option>
                <?php foreach ($type_labels as $k => $l): ?>
                <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr><th>Date/Time</th><th>Type</th><th>Reference</th><th style="text-align:right;">Debit</th><th style="text-align:right;">Credit</th><th style="text-align:right;">Running</th><th style="text-align:right;">Advance</th><th style="text-align:right;">Due</th><th>Status</th><th>Remarks</th><th>User</th></tr>
            </thead>
            <tbody>
                <?php if (empty($ledger['rows'])): ?>
                <tr><td colspan="11" style="text-align:center;color:var(--admin-muted);padding:2rem;">No ledger entries found.</td></tr>
                <?php else: foreach ($ledger['rows'] as $entry): ?>
                <tr>
                    <td style="white-space:nowrap;font-size:0.85rem;"><?= date('d-M-Y h:i A', strtotime($entry['transaction_date'])) ?></td>
                    <td><span class="badge badge-<?= in_array($entry['transaction_type'],['payment','advance','current_payment'])?'filled':(in_array($entry['transaction_type'],['due_created','advance_utilized'])?'rental':'empty') ?>"><?= htmlspecialchars($type_labels[$entry['transaction_type']]??$entry['transaction_type']) ?></span></td>
                    <td style="font-size:0.85rem;">
                        <?php if ($entry['reference_type'] === 'partner_transaction' && $entry['reference_id']): ?>
                        <a href="partner-invoice.php?tx_id=<?= intval($entry['reference_id']) ?>" style="color:var(--admin-accent);">PTX-<?= str_pad(intval($entry['reference_id']),4,'0',STR_PAD_LEFT) ?></a>
                        <?php else: ?><?= htmlspecialchars($entry['reference_type'] ?? '—') ?><?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:600;"><?= floatval($entry['debit'])>0?'₹'.number_format($entry['debit'],2):'—' ?></td>
                    <td style="text-align:right;font-weight:600;color:var(--danger);"><?= floatval($entry['credit'])>0?'₹'.number_format($entry['credit'],2):'—' ?></td>
                    <td style="text-align:right;font-weight:700;">₹<?= number_format($entry['running_balance'],2) ?></td>
                    <td style="text-align:right;">₹<?= number_format($entry['advance_balance'],2) ?></td>
                    <td style="text-align:right;color:var(--danger);">₹<?= number_format($entry['due_balance'],2) ?></td>
                    <td>
                        <?php if ($entry['settlement_status']==='settled'): ?><span class="badge badge-filled">Settled</span>
                        <?php elseif ($entry['settlement_status']==='partial'): ?><span class="badge badge-rental">Partial</span>
                        <?php elseif ($entry['settlement_status']==='pending'): ?><span class="badge badge-empty">Pending</span>
                        <?php else: ?><span class="badge badge-empty">—</span><?php endif; ?>
                    </td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.85rem;color:var(--admin-muted);" title="<?= htmlspecialchars($entry['remarks']??'') ?>"><?= htmlspecialchars(mb_substr($entry['remarks']??'',0,60)) ?></td>
                    <td style="font-size:0.85rem;"><?= htmlspecialchars($entry['created_by']??'—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($ledger['pages'] > 1): ?>
<div style="display:flex;justify-content:center;gap:0.5rem;margin-top:1.5rem;">
    <?php for ($i = 1; $i <= $ledger['pages']; $i++): ?>
    <a href="?partner_id=<?= $partner_id ?>&page=<?= $i ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&type=<?= urlencode($type) ?>" style="padding:0.4rem 0.75rem;border-radius:8px;border:1px solid var(--admin-border);text-decoration:none;font-weight:<?= $i===$page?'800':'400' ?>;background:<?= $i===$page?'var(--admin-accent)':'transparent' ?>;color:<?= $i===$page?'#fff':'inherit' ?>;"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
