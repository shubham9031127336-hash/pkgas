<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('partner_tx.title');
$active_menu = 'partners';
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
runPartnerMigrations($pdo);

$error  = '';
$flash  = $_SESSION['success_flash'] ?? '';
unset($_SESSION['success_flash']);

$partner_filter = isset($_GET['partner_id']) ? intval($_GET['partner_id']) : 0;
$type_filter    = isset($_GET['transaction_type']) ? trim($_GET['transaction_type']) : '';
$start_date     = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date       = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$sql = "SELECT pt.*, p.company_name, v.name as vendor_name, COUNT(pti.id) as cylinder_count,
               COALESCE(SUM(pti.rent_accrued), 0) as total_rent_accrued,
               COALESCE(SUM(pti.rent_paid), 0) as total_rent_paid,
               COALESCE(SUM(pti.damage_amount), 0) as total_damage
        FROM partner_transactions pt
        LEFT JOIN partners p ON pt.partner_id = p.id
        LEFT JOIN vendors v ON pt.vendor_id = v.id
        LEFT JOIN partner_transaction_items pti ON pt.id = pti.transaction_id
        WHERE 1=1";
$params = [];

if ($partner_filter > 0) {
    $sql .= " AND (pt.partner_id = ? OR pt.vendor_id = ?)";
    $params[] = $partner_filter; $params[] = $partner_filter;
}
if (!empty($type_filter)) {
    $sql .= " AND pt.transaction_type = ?";
    $params[] = $type_filter;
}
if (!empty($start_date)) {
    $sql .= " AND pt.transaction_date >= ?";
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $sql .= " AND pt.transaction_date <= ?";
    $params[] = $end_date;
}
$sql .= " GROUP BY pt.id ORDER BY pt.transaction_date DESC, pt.id DESC";

$transactions = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching transactions: " . $e->getMessage();
}

$partners = [];
try { $partners = $pdo->query("SELECT * FROM partners ORDER BY company_name ASC")->fetchAll(); } catch (PDOException $e) {}

$transaction_items = [];
if (!empty($transactions)) {
    $tx_ids = array_column($transactions, 'id');
    $in_clause = implode(',', array_fill(0, count($tx_ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT pti.*, g.name as gas_name, pti.daily_rent_rate, pti.days_held, pti.rent_accrued,
                   pti.rent_paid, pti.damage_amount, pti.payment_status
            FROM partner_transaction_items pti
            JOIN gas_types g ON pti.gas_type_id = g.id
            WHERE pti.transaction_id IN ($in_clause)
            ORDER BY pti.id ASC
        ");
        $stmt->execute($tx_ids);
        while ($row = $stmt->fetch()) {
            $transaction_items[$row['transaction_id']][] = $row;
        }
    } catch (PDOException $e) {}
}
?>
<link rel="stylesheet" href="partner.css">

<?php if ($flash): ?>
<div class="alert-banner" style="background:var(--success-soft);color:var(--success);border-color:#a7f3d0;margin-bottom:1.5rem;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div class="page-header">
    <div class="page-header-title">
        <?php echo render_breadcrumb([['title' => __('nav.partners'), 'href' => 'partners.php'], ['title' => __('partner_tx.title')]]); ?>
        <h2><?php echo __('partner_tx.heading'); ?></h2>
        <p><?php echo __('partner_tx.subtitle'); ?></p>
    </div>
    <div class="page-header-actions">
        <a href="partner-transaction-create.php" class="btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Transaction
        </a>
    </div>
</div>

<?php if ($error): ?>
<div class="alert-banner alert-error"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="admin-card">
    <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr)) 120px;gap:1rem;align-items:end;">
        <div>
            <label class="form-label"><?php echo __('partner_tx.partner'); ?></label>
            <select name="partner_id" class="form-control" onchange="this.form.submit()">
                <option value="0"><?php echo __('partner_tx.all_partners'); ?></option>
                <?php foreach ($partners as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $partner_filter === intval($p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['company_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label"><?php echo __('partner_tx.movement_type'); ?></label>
            <select name="transaction_type" class="form-control" onchange="this.form.submit()">
                <option value=""><?php echo __('partner_tx.all_types'); ?></option>
                <option value="borrowed_from_partner" <?= $type_filter === 'borrowed_from_partner' ? 'selected' : '' ?>>Borrowed From Partner</option>
                <option value="returned_to_partner"   <?= $type_filter === 'returned_to_partner'   ? 'selected' : '' ?>>Returned To Partner</option>
                <option value="lent_to_partner"        <?= $type_filter === 'lent_to_partner'        ? 'selected' : '' ?>>Lent To Partner</option>
                <option value="received_back_from_partner" <?= $type_filter === 'received_back_from_partner' ? 'selected' : '' ?>>Received Back From Partner</option>
                <option value="borrowed_from_vendor"   <?= $type_filter === 'borrowed_from_vendor'   ? 'selected' : '' ?>>Vendor Borrow</option>
                <option value="returned_to_vendor"     <?= $type_filter === 'returned_to_vendor'     ? 'selected' : '' ?>>Vendor Return</option>
            </select>
        </div>
        <div>
            <label class="form-label"><?php echo __('partner_tx.start_date'); ?></label>
            <input type="datetime-local" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
        </div>
        <div>
            <label class="form-label"><?php echo __('partner_tx.end_date'); ?></label>
            <input type="datetime-local" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
        </div>
        <div style="display:flex;gap:0.5rem;">
            <button type="submit" class="btn-primary" style="height:42px;flex:1;justify-content:center;">Filter</button>
            <a href="partner-transactions.php" class="btn-secondary" style="height:42px;padding:0 10px;display:flex;align-items:center;" title="Reset">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            </a>
        </div>
    </form>
</div>

<div class="admin-card" style="padding:0;">
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('partner_tx.tx_id'); ?></th>
                    <th><?php echo __('partner_tx.partner'); ?></th>
                    <th><?php echo __('partner_tx.movement_type'); ?></th>
                    <th style="text-align:center;"><?php echo __('partner_tx.cylinders'); ?></th>
                    <th><?php echo __('partner_tx.rent_accrued'); ?></th>
                    <th><?php echo __('partner_tx.rent_paid'); ?></th>
                    <th><?php echo __('partner_tx.damage'); ?></th>
                    <th><?php echo __('partner_tx.date'); ?></th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t):
                    $type_label = 'Borrowed'; $badge_class = 'badge-filled';
                    if ($t['transaction_type'] === 'returned_to_partner')      { $badge_class = 'badge-empty'; $type_label = 'Returned'; }
                    if ($t['transaction_type'] === 'lent_to_partner')           { $badge_class = 'badge-with-customer'; $type_label = 'Lent'; }
                    if ($t['transaction_type'] === 'received_back_from_partner'){ $badge_class = 'badge-sent-to-vendor'; $type_label = 'Received Back'; }
                    if ($t['transaction_type'] === 'borrowed_from_vendor')     { $badge_class = 'badge-filled'; $type_label = 'Vendor Borrow'; }
                    if ($t['transaction_type'] === 'returned_to_vendor')       { $badge_class = 'badge-empty'; $type_label = 'Vendor Return'; }
                    $is_vendor = in_array($t['transaction_type'], ['borrowed_from_vendor', 'returned_to_vendor']);
                    $entity_name = $is_vendor ? $t['vendor_name'] : $t['company_name'];
                    $entity_link = $is_vendor ? 'vendor-profile.php?id=' . $t['vendor_id'] : 'partner-profile.php?id=' . $t['partner_id'];
                ?>
                <tr>
                    <td data-label="TX ID" style="font-weight:700;color:var(--admin-muted);">#PTX-<?= str_pad($t['id'], 4, '0', STR_PAD_LEFT) ?></td>
                    <td data-label="Entity" style="font-weight:700;">
                        <a href="<?= $entity_link ?>" style="color:var(--admin-accent);text-decoration:none;"><?= htmlspecialchars($entity_name ?: 'Unknown') ?></a>
                    </td>
                    <td data-label="Type"><span class="badge <?= $badge_class ?>" style="font-size:0.72rem;padding:3px 10px;"><?= $type_label ?></span></td>
                    <td data-label="Cylinders" style="text-align:center;font-weight:700;color:var(--admin-accent);"><?= $t['cylinder_count'] ?></td>
                    <td data-label="Rent Accrued"><?= $t['total_rent_accrued'] > 0 ? '<span class="financial-chip chip-rent">₹' . number_format($t['total_rent_accrued'], 2) . '</span>' : '<span style="color:var(--admin-muted);font-size:0.8rem;">—</span>' ?></td>
                    <td data-label="Rent Paid"><?= $t['total_rent_paid'] > 0 ? '<span class="financial-chip chip-paid">₹' . number_format($t['total_rent_paid'], 2) . '</span>' : '<span style="color:var(--admin-muted);font-size:0.8rem;">—</span>' ?></td>
                    <td data-label="Damage"><?= $t['total_damage'] > 0 ? '<span class="financial-chip chip-dmg">₹' . number_format($t['total_damage'], 2) . '</span>' : '<span style="color:var(--admin-muted);font-size:0.8rem;">—</span>' ?></td>
                    <td data-label="Date" style="font-weight:600;font-size:0.85rem;"><?= date('M d, Y', strtotime($t['transaction_date'])) ?></td>
                    <td data-label="Actions" style="text-align:right;">
                        <button class="btn-secondary" style="padding:0.4rem 0.8rem;font-size:0.8rem;border-radius:8px;"
                                onclick="openDetailsModal(<?= $t['id'] ?>, <?= htmlspecialchars(json_encode($t)) ?>)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            Details
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <tr><td colspan="9" style="text-align:center;padding:5rem 0;color:var(--admin-muted);"><?php echo __('partner_tx.no_data'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="txDetailsModal">
    <div class="modal-content" style="max-width:780px;">
        <div class="modal-header">
            <h3>Transaction Details</h3>
            <button class="modal-close" onclick="closeModal('txDetailsModal')">&times;</button>
        </div>
        <div style="background:#fafafb;border:1px solid var(--admin-border);border-radius:12px;padding:1rem;display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;font-size:0.85rem;margin-bottom:0.5rem;">
            <div><span style="color:var(--admin-muted);font-weight:600;display:block;font-size:0.7rem;text-transform:uppercase;">TX ID</span><strong id="modal_tx_id" style="color:var(--admin-fg);"></strong></div>
            <div><span style="color:var(--admin-muted);font-weight:600;display:block;font-size:0.7rem;text-transform:uppercase;">Entity</span><strong id="modal_partner" style="color:var(--admin-fg);"></strong></div>
            <div><span style="color:var(--admin-muted);font-weight:600;display:block;font-size:0.7rem;text-transform:uppercase;">Type</strong><span id="modal_type_badge" class="badge" style="font-size:0.7rem;padding:2px 8px;margin-top:2px;"></span></div>
            <div><span style="color:var(--admin-muted);font-weight:600;display:block;font-size:0.7rem;text-transform:uppercase;">Date</span><strong id="modal_date" style="color:var(--admin-fg);"></strong></div>
        </div>
        <div class="modal-summary-grid" id="modalFinSummary" style="display:none;">
            <div class="modal-sum-card" style="background:rgba(251,191,36,0.06);border-color:rgba(251,191,36,0.3);">
                <span class="modal-sum-val" id="modal_sum_accrued" style="color:#b45309;"></span>
                <span class="modal-sum-lbl">Rent Accrued</span>
            </div>
            <div class="modal-sum-card" style="background:rgba(34,197,94,0.06);border-color:rgba(34,197,94,0.25);">
                <span class="modal-sum-val" id="modal_sum_paid" style="color:var(--success);"></span>
                <span class="modal-sum-lbl">Rent Paid</span>
            </div>
            <div class="modal-sum-card" style="background:rgba(239,68,68,0.06);border-color:rgba(239,68,68,0.2);">
                <span class="modal-sum-val" id="modal_sum_dmg" style="color:var(--danger);"></span>
                <span class="modal-sum-lbl">Damage</span>
            </div>
        </div>
        <blockquote id="modal_notes" style="background:rgba(37,99,235,0.03);border-left:4px solid var(--admin-accent);padding:0.5rem 1rem;font-style:italic;color:var(--admin-fg);font-size:0.85rem;margin-bottom:1.25rem;border-radius:0 8px 8px 0;"></blockquote>
        <h4 style="font-size:0.95rem;font-weight:800;margin-bottom:0.75rem;border-bottom:1px solid var(--admin-border);padding-bottom:0.5rem;">Cylinders</h4>
        <div style="max-height:320px;overflow-y:auto;border:1px solid var(--admin-border);border-radius:12px;margin-bottom:1.5rem;">
            <table class="admin-table" style="font-size:0.82rem;margin:0;">
                <thead>
                    <tr style="position:sticky;top:0;background:#fafafb;z-index:10;">
                        <th style="padding:8px 10px;font-size:0.68rem;">Serial</th>
                        <th style="padding:8px 10px;font-size:0.68rem;">Gas</th>
                        <th style="padding:8px 10px;font-size:0.68rem;">Status</th>
                        <th style="padding:8px 10px;font-size:0.68rem;text-align:center;">Days</th>
                        <th style="padding:8px 10px;font-size:0.68rem;">Rate</th>
                        <th style="padding:8px 10px;font-size:0.68rem;">Rent Accrued</th>
                        <th style="padding:8px 10px;font-size:0.68rem;">Rent Paid</th>
                        <th style="padding:8px 10px;font-size:0.68rem;">Damage</th>
                        <th style="padding:8px 10px;font-size:0.68rem;">Payment</th>
                    </tr>
                </thead>
                <tbody id="modal_cylinders_tbody"></tbody>
            </table>
        </div>
        <div style="display:flex;gap:0.75rem;">
            <a href="partner-invoice.php?tx_id=" id="modalInvoiceLink" target="_blank" class="btn-primary" style="flex:1;justify-content:center;">View Invoice</a>
            <button class="btn-secondary" style="flex:1;justify-content:center;" onclick="closeModal('txDetailsModal')">Close</button>
        </div>
    </div>
</div>

<script>
const txItemsMapping = <?= json_encode($transaction_items) ?>;

function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { const m = document.querySelector('.modal.active'); if (m) closeModal(m.id); }
});

function openDetailsModal(txId, tx) {
    document.getElementById('modal_tx_id').innerText = '#PTX-' + String(txId).padStart(4, '0');
    const isVendor = tx.transaction_type === 'borrowed_from_vendor' || tx.transaction_type === 'returned_to_vendor';
    document.getElementById('modal_partner').innerText = isVendor ? (tx.vendor_name || 'Vendor') : tx.company_name;
    document.getElementById('modal_date').innerText = tx.transaction_date;
    document.getElementById('modal_notes').innerText = tx.notes || 'No remarks.';
    document.getElementById('modalInvoiceLink').href = 'partner-invoice.php?tx_id=' + txId;

    const badge = document.getElementById('modal_type_badge');
    badge.className = 'badge';
    const types = {
        'borrowed_from_partner':      ['badge-filled', 'Borrowed From Partner'],
        'returned_to_partner':        ['badge-empty', 'Returned To Partner'],
        'lent_to_partner':            ['badge-with-customer', 'Lent To Partner'],
        'received_back_from_partner': ['badge-sent-to-vendor', 'Received Back From Partner'],
        'borrowed_from_vendor':       ['badge-filled', 'Vendor Borrow'],
        'returned_to_vendor':         ['badge-empty', 'Vendor Return'],
    };
    const [cls, lbl] = types[tx.transaction_type] || ['badge-filled', tx.transaction_type];
    badge.classList.add(cls);
    badge.innerText = lbl;

    const accrued = parseFloat(tx.total_rent_accrued || 0);
    const paid    = parseFloat(tx.total_rent_paid    || 0);
    const damage  = parseFloat(tx.total_damage       || 0);
    const fin = document.getElementById('modalFinSummary');

    if (accrued > 0 || paid > 0 || damage > 0) {
        fin.style.display = 'grid';
        document.getElementById('modal_sum_accrued').innerText = '₹' + accrued.toFixed(2);
        document.getElementById('modal_sum_paid').innerText    = '₹' + paid.toFixed(2);
        document.getElementById('modal_sum_dmg').innerText     = '₹' + damage.toFixed(2);
    } else {
        fin.style.display = 'none';
    }

    const tbody = document.getElementById('modal_cylinders_tbody');
    tbody.innerHTML = '';
    const items = txItemsMapping[txId];
    if (items && items.length > 0) {
        items.forEach(function(item) {
            const isBorrow = tx.transaction_type === 'borrowed_from_partner';
            const isVendorBorrow = tx.transaction_type === 'borrowed_from_vendor';
            let tag = '<span style="background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;">OWN</span>';
            if (isBorrow || tx.transaction_type === 'returned_to_partner') tag = '<span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;">BR</span>';
            else if (isVendorBorrow || tx.transaction_type === 'returned_to_vendor') tag = '<span style="background:#f3e8ff;color:#6b21a8;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;">VEN</span>';

            const after = (item.status_after || '—').replace(/_/g, ' ');
            const days = item.days_held || '—';
            const rate = item.daily_rent_rate > 0 ? '₹' + parseFloat(item.daily_rent_rate).toFixed(2) : '—';
            const acc  = item.rent_accrued > 0 ? '<span style="color:#b45309;font-weight:700;">₹' + parseFloat(item.rent_accrued).toFixed(2) + '</span>' : '—';
            const rp   = item.rent_paid > 0 ? '<span style="color:#059669;font-weight:700;">₹' + parseFloat(item.rent_paid).toFixed(2) + '</span>' : '—';
            const dmg  = item.damage_amount > 0 ? '<span style="color:#dc2626;font-weight:700;">₹' + parseFloat(item.damage_amount).toFixed(2) + '</span>' : '—';
            const pay  = item.payment_status === 'cleared' ? '<span style="background:rgba(34,197,94,0.1);color:#059669;padding:2px 6px;border-radius:4px;font-size:0.68rem;font-weight:700;">Cleared</span>' :
                        (item.payment_status === 'pending' ? '<span style="background:rgba(251,191,36,0.12);color:#b45309;padding:2px 6px;border-radius:4px;font-size:0.68rem;font-weight:700;">Pending</span>' : '—');

            const tr = document.createElement('tr');
            tr.innerHTML = '<td style="padding:8px 10px;font-weight:700;color:var(--admin-accent);">' + item.serial_number + tag + '</td>' +
                '<td style="padding:8px 10px;font-weight:600;">' + item.gas_name + ' (' + item.size_capacity + ')</td>' +
                '<td style="padding:8px 10px;text-transform:uppercase;font-size:0.68rem;font-weight:700;color:#059669;">' + after + '</td>' +
                '<td style="padding:8px 10px;text-align:center;font-weight:700;">' + days + '</td>' +
                '<td style="padding:8px 10px;font-weight:600;">' + rate + '</td>' +
                '<td style="padding:8px 10px;">' + acc + '</td>' +
                '<td style="padding:8px 10px;">' + rp + '</td>' +
                '<td style="padding:8px 10px;">' + dmg + '</td>' +
                '<td style="padding:8px 10px;">' + pay + '</td>';
            tbody.appendChild(tr);
        });
    } else {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--admin-muted);padding:2rem;">No cylinder details available.</td></tr>';
    }
    openModal('txDetailsModal');
}
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
