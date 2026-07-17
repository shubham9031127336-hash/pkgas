<?php
$page_title = 'Partner Exchange Reports';
$active_menu = 'partners';
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
runPartnerMigrations($pdo);

// ── CSV EXPORTS (before any output) ──
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $partner_id  = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : 0;
    if (ob_get_level()) ob_end_clean();

    if ($export_type === 'summary') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=partner_summary_' . date('Ymd') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Partner ID','Company Name','Contact Person','Mobile','Borrowed','Lent','Live Rent','Past Rent Accrued','Past Rent Paid','Damage','Net Balance']);
        $rows = $pdo->query("SELECT p.*,
            SUM(CASE WHEN c.ownership_type='partner_owned' AND c.status!='returned_to_partner' THEN 1 ELSE 0 END) bc,
            SUM(CASE WHEN c.ownership_type='owned' AND c.status!='returned_to_partner' AND c.current_partner_id IS NOT NULL THEN 1 ELSE 0 END) lc,
            ROUND(SUM(CASE WHEN c.ownership_type='partner_owned' AND c.status!='returned_to_partner' THEN DATEDIFF(CURDATE(),c.borrow_date)*c.daily_rent_rate ELSE 0 END),2) lr
            FROM partners p LEFT JOIN cylinders c ON p.id=c.current_partner_id GROUP BY p.id ORDER BY p.company_name ASC")->fetchAll();
        foreach ($rows as $r) {
            $f = $pdo->prepare("SELECT COALESCE(SUM(rent_accrued),0) ta,COALESCE(SUM(rent_paid),0) tp,COALESCE(SUM(damage_amount),0) td FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id=pt.id WHERE pt.partner_id=? AND pt.transaction_type='returned_to_partner'");
            $f->execute([$r['id']]); $d = $f->fetch();
            $net = intval($r['bc']) - intval($r['lc']);
            fputcsv($out, ['PTN-'.str_pad($r['id'],3,'0',STR_PAD_LEFT),$r['company_name'],$r['contact_person']?:'N/A',$r['mobile'],$r['bc'],$r['lc'],number_format($r['lr'],2),number_format($d['ta'],2),number_format($d['tp'],2),number_format($d['td'],2),$net>0?"+$net Borrowed":($net<0?"$net Lent":'Balanced')]);
        }
        fclose($out); exit();
    }

    if ($export_type === 'outstanding') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=partner_outstanding_' . date('Ymd') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Serial','Gas','Size','Partner','Status','Borrow Date','Days Held','Rate','Live Rent','Overdue?']);
        $rows = $pdo->query("SELECT c.*,g.name gas,p.company_name pn,DATEDIFF(CURDATE(),c.borrow_date) dh,ROUND(DATEDIFF(CURDATE(),c.borrow_date)*c.daily_rent_rate,2) lr FROM cylinders c JOIN gas_types g ON c.gas_type_id=g.id JOIN partners p ON c.current_partner_id=p.id WHERE ((c.ownership_type='partner_owned' AND c.status!='returned_to_partner') OR (c.ownership_type='owned' AND c.current_partner_id IS NOT NULL AND c.status!='returned_to_partner')) ORDER BY p.company_name ASC, dh DESC")->fetchAll();
        foreach ($rows as $r) fputcsv($out, [$r['serial_number'],$r['gas'],$r['size_capacity'],$r['pn'],str_replace('_',' ',$r['status']),$r['borrow_date'],$r['dh'],$r['daily_rent_rate'],number_format($r['lr'],2),$r['dh']>30?'Yes':'No']);
        fclose($out); exit();
    }

    if ($export_type === 'history') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=partner_history_' . date('Ymd') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['TX ID','Date','Type','Partner','Serial','Gas','Size','Days Held','Rate','Rent Accrued','Rent Paid','Damage','Payment Status']);
        $where = $partner_id > 0 ? "WHERE pt.partner_id=$partner_id" : '';
        $rows = $pdo->query("SELECT pt.id tid,pt.transaction_date,pt.transaction_type,p.company_name pn,pti.serial_number,g.name gas,pti.size_capacity,pti.days_held,pti.daily_rent_rate,pti.rent_accrued,pti.rent_paid,pti.damage_amount,pti.payment_status FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id=pt.id JOIN gas_types g ON pti.gas_type_id=g.id LEFT JOIN partners p ON pt.partner_id=p.id $where ORDER BY pt.transaction_date DESC")->fetchAll();
        foreach ($rows as $r) fputcsv($out, ['PTX-'.str_pad($r['tid'],4,'0',STR_PAD_LEFT),$r['transaction_date'],str_replace('_',' ',$r['transaction_type']),$r['pn'],$r['serial_number'],$r['gas'],$r['size_capacity'],$r['days_held'],$r['daily_rent_rate'],$r['rent_accrued'],$r['rent_paid'],$r['damage_amount'],$r['payment_status']]);
        fclose($out); exit();
    }

    if ($export_type === 'monthly') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=partner_monthly_' . date('Ymd') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Year-Month','Transaction Type','Transactions','Cylinders Moved','Rent Accrued','Rent Paid','Damage']);
        $rows = $pdo->query("SELECT DATE_FORMAT(pt.transaction_date,'%Y-%m') ym,pt.transaction_type,COUNT(DISTINCT pt.id) txc,COUNT(pti.id) cylc,COALESCE(SUM(pti.rent_accrued),0) ra,COALESCE(SUM(pti.rent_paid),0) rp,COALESCE(SUM(pti.damage_amount),0) dmg FROM partner_transactions pt JOIN partner_transaction_items pti ON pt.id=pti.transaction_id GROUP BY ym,pt.transaction_type ORDER BY ym DESC,pt.transaction_type")->fetchAll();
        foreach ($rows as $r) fputcsv($out, [$r['ym'],str_replace('_',' ',$r['transaction_type']),$r['txc'],$r['cylc'],$r['ra'],$r['rp'],$r['dmg']]);
        fclose($out); exit();
    }
}

require_once __DIR__ . '/lang_init.php';
$page_title = 'Partner Exchange Reports';
$active_menu = 'partners';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk']);

$active_tab = $_GET['tab'] ?? 'summary';
$partner_id = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : 0;
?>
<link rel="stylesheet" href="partner.css">

<div class="page-header">
    <div class="page-header-title">
        <?php echo render_breadcrumb([['title' => __('nav.partners'), 'href' => 'partners.php'], ['title' => 'Partner Reports']]); ?>
        <h2>Partner Exchange Reports</h2>
        <p>Analytics, outstanding alarms, cylinder history, and monthly totals</p>
    </div>
    <div class="page-header-actions no-print">
        <?php if ($active_tab === 'summary'): ?>
            <a href="?export=summary" class="btn-secondary">Export CSV</a>
        <?php elseif ($active_tab === 'outstanding'): ?>
            <a href="?export=outstanding" class="btn-secondary">Export CSV</a>
        <?php elseif ($active_tab === 'history'): ?>
            <a href="?export=history&partner_id=<?= $partner_id ?>" class="btn-secondary">Export CSV</a>
        <?php elseif ($active_tab === 'monthly'): ?>
            <a href="?export=monthly" class="btn-secondary">Export CSV</a>
        <?php endif; ?>
        <a href="javascript:window.print()" class="btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print
        </a>
    </div>
</div>

<div class="tab-bar no-print">
    <a href="?tab=summary" class="tab-btn <?= $active_tab === 'summary' ? 'active' : '' ?>">Balance Summary</a>
    <a href="?tab=outstanding" class="tab-btn <?= $active_tab === 'outstanding' ? 'active' : '' ?>">Outstanding Alarms</a>
    <a href="?tab=history" class="tab-btn <?= $active_tab === 'history' ? 'active' : '' ?>">Cylinder History</a>
    <a href="?tab=monthly" class="tab-btn <?= $active_tab === 'monthly' ? 'active' : '' ?>">Monthly Totals</a>
</div>

<?php if ($active_tab === 'summary'): ?>
<?php
$stmt = $pdo->query("
    SELECT p.*,
        SUM(CASE WHEN c.ownership_type='partner_owned' AND c.status!='returned_to_partner' THEN 1 ELSE 0 END) bc,
        SUM(CASE WHEN c.ownership_type='owned' AND c.status!='returned_to_partner' AND c.current_partner_id IS NOT NULL THEN 1 ELSE 0 END) lc,
        ROUND(SUM(CASE WHEN c.ownership_type='partner_owned' AND c.status!='returned_to_partner' THEN DATEDIFF(CURDATE(),c.borrow_date)*c.daily_rent_rate ELSE 0 END),2) lr
    FROM partners p LEFT JOIN cylinders c ON p.id=c.current_partner_id
    GROUP BY p.id ORDER BY p.company_name ASC
");
$summary = $stmt->fetchAll();
?>
<div class="print-header" style="display:none;"><h1>Partner Exchange Balance Summary</h1><p>Generated <?= date('d-M-Y') ?></p></div>
<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr><th>Partner</th><th>Contact</th><th>Mobile</th><th style="text-align:center;">Borrowed</th><th style="text-align:center;">Lent</th><th style="text-align:center;">Net</th><th style="text-align:right;">Live Rent</th><th style="text-align:right;">Total Paid</th></tr>
            </thead>
            <tbody>
                <?php foreach ($summary as $r):
                    $f = $pdo->prepare("SELECT COALESCE(SUM(rent_accrued),0) ta,COALESCE(SUM(rent_paid),0) tp,COALESCE(SUM(damage_amount),0) td FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id=pt.id WHERE pt.partner_id=? AND pt.transaction_type='returned_to_partner'");
                    $f->execute([$r['id']]); $d = $f->fetch();
                    $net = intval($r['bc']) - intval($r['lc']);
                ?>
                <tr>
                    <td><a href="partner-profile.php?id=<?= $r['id'] ?>" class="partner-name-link"><?= htmlspecialchars($r['company_name']) ?></a></td>
                    <td><?= htmlspecialchars($r['contact_person'] ?: 'N/A') ?></td>
                    <td><?= htmlspecialchars($r['mobile']) ?></td>
                    <td style="text-align:center;"><span class="badge badge-empty"><?= intval($r['bc']) ?></span></td>
                    <td style="text-align:center;"><span class="badge badge-rental"><?= intval($r['lc']) ?></span></td>
                    <td style="text-align:center;font-weight:700;color:<?= $net > 0 ? '#b45309' : ($net < 0 ? '#2563eb' : '#64748b') ?>;"><?= $net > 0 ? "+$net" : ($net < 0 ? $net : '0') ?></td>
                    <td style="text-align:right;font-weight:700;color:#b45309;">₹<?= number_format($r['lr'], 2) ?></td>
                    <td style="text-align:right;font-weight:700;color:#059669;">₹<?= number_format($d['tp'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($summary)): ?>
                <tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--admin-muted);">No partners registered.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($active_tab === 'outstanding'): ?>
<?php
$outstanding = $pdo->query("
    SELECT c.*,g.name gas,p.company_name pn,
           DATEDIFF(CURDATE(),c.borrow_date) dh,
           ROUND(DATEDIFF(CURDATE(),c.borrow_date)*c.daily_rent_rate,2) lr
    FROM cylinders c JOIN gas_types g ON c.gas_type_id=g.id JOIN partners p ON c.current_partner_id=p.id
    WHERE ((c.ownership_type='partner_owned' AND c.status!='returned_to_partner') OR (c.ownership_type='owned' AND c.current_partner_id IS NOT NULL AND c.status!='returned_to_partner'))
    ORDER BY dh DESC
")->fetchAll();
?>
<div class="print-header" style="display:none;"><h1>Outstanding Cylinder Alarms</h1><p>Generated <?= date('d-M-Y') ?></p></div>
<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr><th>Serial</th><th>Gas</th><th>Partner</th><th>Status</th><th style="text-align:center;">Days Held</th><th style="text-align:right;">Rate</th><th style="text-align:right;">Live Rent</th><th>Flag</th></tr>
            </thead>
            <tbody>
                <?php foreach ($outstanding as $r): ?>
                <tr class="<?= $r['dh'] > 30 ? 'row-overdue' : '' ?>">
                    <td style="font-weight:700;color:var(--admin-accent);">
                        <?= htmlspecialchars($r['serial_number']) ?>
                        <?php if ($r['ownership_type'] === 'partner_owned'): ?><span class="ownership-tag br">BR</span><?php endif; ?>
                        <?php if ($r['ownership_type'] === 'owned'): ?><span class="tag-own">OWN</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($r['gas']) ?> (<?= htmlspecialchars($r['size_capacity']) ?>)</td>
                    <td><a href="partner-profile.php?id=<?= $r['current_partner_id'] ?>" style="color:var(--admin-accent);"><?= htmlspecialchars($r['pn']) ?></a></td>
                    <td><span class="badge badge-empty"><?= str_replace('_',' ',$r['status']) ?></span></td>
                    <td style="text-align:center;font-weight:700;"><?= intval($r['dh']) ?>d</td>
                    <td style="text-align:right;">₹<?= number_format($r['daily_rent_rate'],2) ?></td>
                    <td style="text-align:right;font-weight:700;color:#b45309;">₹<?= number_format($r['lr'],2) ?></td>
                    <td><?= $r['dh'] > 30 ? '<span class="overdue-flag">Overdue</span>' : '<span style="color:#059669;font-size:0.72rem;font-weight:600;">OK</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($outstanding)): ?>
                <tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--admin-muted);">No outstanding cylinders.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($active_tab === 'history'): ?>
<?php
$history_where = $partner_id > 0 ? "AND pt.partner_id=$partner_id" : '';
$history = $pdo->query("
    SELECT pti.*,pt.transaction_type,pt.transaction_date,g.name gas,p.company_name pn
    FROM partner_transaction_items pti
    JOIN partner_transactions pt ON pti.transaction_id=pt.id
    JOIN gas_types g ON pti.gas_type_id=g.id
    LEFT JOIN partners p ON pt.partner_id=p.id
    WHERE 1=1 $history_where
    ORDER BY pt.transaction_date DESC LIMIT 200
")->fetchAll();
$all_partners = $pdo->query("SELECT id,company_name FROM partners ORDER BY company_name ASC")->fetchAll();
?>
<div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;align-items:center;">
    <label style="font-size:0.85rem;font-weight:600;color:var(--admin-muted);">Filter by Partner:</label>
    <select class="form-control" style="width:auto;padding:0.4rem 0.75rem;" onchange="window.location.href='?tab=history&partner_id='+this.value">
        <option value="0">All Partners</option>
        <?php foreach ($all_partners as $ap): ?>
        <option value="<?= $ap['id'] ?>" <?= $partner_id === intval($ap['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ap['company_name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="print-header" style="display:none;"><h1>Cylinder History</h1><p>Generated <?= date('d-M-Y') ?></p></div>
<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr><th>TX ID</th><th>Date</th><th>Type</th><th>Partner</th><th>Serial</th><th>Gas</th><th>Days</th><th style="text-align:right;">Rate</th><th style="text-align:right;">Accrued</th><th style="text-align:right;">Paid</th><th style="text-align:right;">Damage</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td style="font-weight:700;color:var(--admin-muted);font-size:0.8rem;">#PTX-<?= str_pad($h['transaction_id'],4,'0',STR_PAD_LEFT) ?></td>
                    <td style="font-size:0.85rem;"><?= date('d-M-Y',strtotime($h['transaction_date'])) ?></td>
                    <td><span class="badge badge-filled" style="font-size:0.68rem;"><?= str_replace('_',' ',$h['transaction_type']) ?></span></td>
                    <td><?= htmlspecialchars($h['pn'] ?: '—') ?></td>
                    <td style="font-weight:700;color:var(--admin-accent);"><?= htmlspecialchars($h['serial_number']) ?>
                        <?php if ($h['ownership_type'] === 'partner_owned'): ?><span class="tag-br">BR</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($h['gas']) ?></td>
                    <td style="text-align:center;"><?= intval($h['days_held']) ?: '—' ?></td>
                    <td style="text-align:right;"><?= $h['daily_rent_rate'] > 0 ? '₹'.number_format($h['daily_rent_rate'],2) : '—' ?></td>
                    <td style="text-align:right;color:#b45309;font-weight:700;"><?= $h['rent_accrued'] > 0 ? '₹'.number_format($h['rent_accrued'],2) : '—' ?></td>
                    <td style="text-align:right;color:#059669;font-weight:700;"><?= $h['rent_paid'] > 0 ? '₹'.number_format($h['rent_paid'],2) : '—' ?></td>
                    <td style="text-align:right;color:#dc2626;font-weight:700;"><?= $h['damage_amount'] > 0 ? '₹'.number_format($h['damage_amount'],2) : '—' ?></td>
                    <td><?= $h['payment_status'] === 'cleared' ? '<span style="color:#059669;font-weight:700;">Paid</span>' : ($h['payment_status'] === 'pending' ? '<span style="color:#b45309;font-weight:700;">Pending</span>' : '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($history)): ?>
                <tr><td colspan="12" style="text-align:center;padding:3rem;color:var(--admin-muted);">No history found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($active_tab === 'monthly'): ?>
<?php
$monthly = $pdo->query("
    SELECT DATE_FORMAT(pt.transaction_date,'%Y-%m') ym,pt.transaction_type,
           COUNT(DISTINCT pt.id) txc,COUNT(pti.id) cylc,
           COALESCE(SUM(pti.rent_accrued),0) ra,COALESCE(SUM(pti.rent_paid),0) rp,COALESCE(SUM(pti.damage_amount),0) dmg
    FROM partner_transactions pt
    JOIN partner_transaction_items pti ON pt.id=pti.transaction_id
    GROUP BY ym,pt.transaction_type ORDER BY ym DESC,pt.transaction_type
")->fetchAll();
?>
<div class="print-header" style="display:none;"><h1>Monthly Totals</h1><p>Generated <?= date('d-M-Y') ?></p></div>
<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr><th>Month</th><th>Type</th><th style="text-align:center;">Transactions</th><th style="text-align:center;">Cylinders</th><th style="text-align:right;">Rent Accrued</th><th style="text-align:right;">Rent Paid</th><th style="text-align:right;">Damage</th></tr>
            </thead>
            <tbody>
                <?php foreach ($monthly as $m): ?>
                <tr>
                    <td style="font-weight:700;"><?= htmlspecialchars($m['ym']) ?></td>
                    <td><span class="badge badge-filled" style="font-size:0.68rem;"><?= str_replace('_',' ',$m['transaction_type']) ?></span></td>
                    <td style="text-align:center;"><?= $m['txc'] ?></td>
                    <td style="text-align:center;font-weight:700;"><?= $m['cylc'] ?></td>
                    <td style="text-align:right;color:#b45309;font-weight:700;">₹<?= number_format($m['ra'],2) ?></td>
                    <td style="text-align:right;color:#059669;font-weight:700;">₹<?= number_format($m['rp'],2) ?></td>
                    <td style="text-align:right;color:#dc2626;font-weight:700;">₹<?= number_format($m['dmg'],2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($monthly)): ?>
                <tr><td colspan="7" style="text-align:center;padding:3rem;color:var(--admin-muted);">No monthly data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
