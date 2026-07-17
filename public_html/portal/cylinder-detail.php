<?php
$page_title = "Cylinder History";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';

$customer_id = get_customer_id();
$cylinder_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($cylinder_id <= 0) {
    header("Location: cylinders.php");
    exit();
}

$stmt = $pdo->prepare("SELECT c.*, g.name as gas_name, g.chemical_formula FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ? AND c.current_customer_id = ?");
$stmt->execute([$cylinder_id, $customer_id]);
$cyl = $stmt->fetch();

if (!$cyl) {
    header("Location: cylinders.php");
    exit();
}

$txn_stmt = $pdo->prepare("SELECT * FROM cylinder_transactions WHERE cylinder_id = ? ORDER BY transaction_date DESC");
$txn_stmt->execute([$cylinder_id]);
$transactions = $txn_stmt->fetchAll();
?>
<div class="page-header">
    <a href="cylinders.php" class="card-link" style="display:inline-flex;align-items:center;gap:0.4rem;margin-bottom:1rem;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Cylinders
    </a>
    <h1>Cylinder <?php echo htmlspecialchars($cyl['serial_number']); ?></h1>
</div>

<div class="card-grid">
    <div class="card">
        <div class="card-header"><h2>Details</h2></div>
        <div class="card-body">
            <table style="width:100%;">
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Serial Number</td><td style="padding:0.4rem 0;font-weight:600;"><?php echo htmlspecialchars($cyl['serial_number']); ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Gas</td><td style="padding:0.4rem 0;"><?php echo htmlspecialchars($cyl['gas_name']); ?> (<?php echo htmlspecialchars($cyl['chemical_formula']); ?>)</td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Size</td><td style="padding:0.4rem 0;"><?php echo htmlspecialchars($cyl['size_capacity']); ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Status</td><td style="padding:0.4rem 0;"><span class="badge badge-<?php echo in_array($cyl['status'], ['filled','with_customer']) ? 'paid' : 'pending'; ?>"><?php echo ucfirst($cyl['status']); ?></span></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Last Refill</td><td style="padding:0.4rem 0;"><?php echo $cyl['last_refill_date'] ? date('d M Y', strtotime($cyl['last_refill_date'])) : '—'; ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Expiry (Hydrotest)</td><td style="padding:0.4rem 0;font-weight:<?php echo $cyl['expiry_date'] && strtotime($cyl['expiry_date']) <= strtotime('+30 days') ? '700;color:var(--danger)' : '400'; ?>;"><?php echo $cyl['expiry_date'] ? date('d M Y', strtotime($cyl['expiry_date'])) : '—'; ?><?php if ($cyl['expiry_date'] && strtotime($cyl['expiry_date']) <= strtotime('+30 days')): ?> ⚠<?php endif; ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Last Inspection</td><td style="padding:0.4rem 0;"><?php echo $cyl['last_inspection_date'] ? date('d M Y', strtotime($cyl['last_inspection_date'])) : '—'; ?></td></tr>
                <tr><td style="padding:0.4rem 0;color:var(--muted);font-size:0.85rem;">Purchase Date</td><td style="padding:0.4rem 0;"><?php echo $cyl['purchase_date'] ? date('d M Y', strtotime($cyl['purchase_date'])) : '—'; ?></td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Transaction History</h2></div>
        <div class="card-body p-0">
            <?php if (empty($transactions)): ?>
            <div class="card-body" style="text-align:center;color:var(--muted);font-size:0.9rem;">No transactions recorded.</div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($t['transaction_date'])); ?></td>
                            <td><span class="badge badge-paid" style="background:var(--accent-soft);color:var(--accent);"><?php echo htmlspecialchars(str_replace('_', ' ', $t['transaction_type'])); ?></span></td>
                            <td><?php echo htmlspecialchars($t['notes'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
