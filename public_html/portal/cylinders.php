<?php
$page_title = "My Cylinders";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';

$customer_id = get_customer_id();
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$sql = "SELECT c.*, g.name as gas_name, g.chemical_formula FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.current_customer_id = ?";
$params = [$customer_id];

if (!empty($status_filter)) {
    $sql .= " AND c.status = ?";
    $params[] = $status_filter;
}
$sql .= " ORDER BY c.status, c.expiry_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cylinders = $stmt->fetchAll();

$count_stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM cylinders WHERE current_customer_id = ? GROUP BY status");
$count_stmt->execute([$customer_id]);
$counts = [];
while ($row = $count_stmt->fetch()) { $counts[$row['status']] = $row['cnt']; }
?>
<div class="page-header">
    <h1>My Cylinders</h1>
    <p class="text-muted">Cylinders currently assigned to you</p>
</div>

<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5;color:#10b981;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?php echo intval($counts['filled'] ?? 0) + intval($counts['with_customer'] ?? 0); ?></span>
            <span class="stat-label">Active</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#ef4444;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?php echo intval($counts['empty'] ?? 0); ?></span>
            <span class="stat-label">Empty</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb;color:#f59e0b;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div class="stat-body">
            <span class="stat-value"><?php
                $exp = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_customer_id = ? AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
                $exp->execute([$customer_id]);
                echo $exp->fetchColumn();
            ?></span>
            <span class="stat-label">Expiring Soon</span>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <select name="status">
                    <option value="">All Status</option>
                    <option value="filled" <?php echo $status_filter === 'filled' ? 'selected' : ''; ?>>Filled</option>
                    <option value="empty" <?php echo $status_filter === 'empty' ? 'selected' : ''; ?>>Empty</option>
                    <option value="with_customer" <?php echo $status_filter === 'with_customer' ? 'selected' : ''; ?>>With You</option>
                    <option value="in_use" <?php echo $status_filter === 'in_use' ? 'selected' : ''; ?>>In Use</option>
                </select>
                <button type="submit" class="btn-filter">Filter</button>
                <a href="cylinders.php" class="btn-filter btn-clear">Clear</a>
            </div>
        </form>
    </div>
</div>

<?php if (empty($cylinders)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem;">
        <p style="color:var(--muted);font-size:1rem;">No cylinders assigned to you.</p>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Serial #</th>
                        <th>Gas</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th>Expiry</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cylinders as $cyl):
                        $is_expiring = $cyl['expiry_date'] && strtotime($cyl['expiry_date']) <= strtotime('+30 days');
                    ?>
                    <tr class="<?php echo $is_expiring ? 'row-warning' : ''; ?>">
                        <td><strong><?php echo htmlspecialchars($cyl['serial_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($cyl['gas_name']); ?></td>
                        <td><?php echo htmlspecialchars($cyl['size_capacity']); ?></td>
                        <td>
                            <?php
                            $status_labels = [
                                'filled' => ['Filled', 'badge-paid'],
                                'empty' => ['Empty', 'badge-pending'],
                                'with_customer' => ['With You', 'badge-partial'],
                                'in_use' => ['In Use', 'badge-partial'],
                            ];
                            $sl = $status_labels[$cyl['status']] ?? [ucfirst($cyl['status']), 'badge-pending'];
                            ?>
                            <span class="badge <?php echo $sl[1]; ?>"><?php echo $sl[0]; ?></span>
                        </td>
                        <td>
                            <?php if ($cyl['expiry_date']): ?>
                                <span style="<?php echo $is_expiring ? 'color:var(--danger);font-weight:700;' : ''; ?>">
                                    <?php echo date('d M Y', strtotime($cyl['expiry_date'])); ?>
                                    <?php if ($is_expiring): ?> ⚠<?php endif; ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><a href="cylinder-detail.php?id=<?php echo $cyl['id']; ?>" class="table-link">History</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
