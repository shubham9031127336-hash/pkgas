<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? '';

if ($action === 'lendable_cylinders') {
    $partner_id = intval($_GET['partner_id'] ?? 0);
    if ($partner_id <= 0) { echo '<p style="color:var(--admin-muted);text-align:center;">Invalid partner.</p>'; exit; }

    $cylinders = $pdo->prepare("
        SELECT c.id, c.serial_number, g.name as gas_name, c.size_capacity, c.status
        FROM cylinders c
        JOIN gas_types g ON c.gas_type_id = g.id
        WHERE c.ownership_type = 'owned' AND c.status IN ('empty', 'filled', 'in_maintenance') AND c.current_partner_id IS NULL
        ORDER BY g.name, c.serial_number
    ");
    $cylinders->execute();
    $rows = $cylinders->fetchAll();

    if (empty($rows)) {
        echo '<p style="color:var(--admin-muted);text-align:center;padding:2rem;">No available cylinders to lend.</p>';
        exit;
    }

    echo '<div style="display:grid;gap:0.5rem;">';
    foreach ($rows as $c) {
        $status_class = $c['status'] === 'filled' ? 'badge-filled' : ($c['status'] === 'empty' ? 'badge-empty' : 'badge-under-maintenance');
        echo '<label style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem;border:1px solid var(--admin-border);border-radius:8px;cursor:pointer;">';
        echo '<input type="checkbox" name="cylinder_ids[]" value="' . $c['id'] . '">';
        echo '<span style="font-weight:600;font-size:0.85rem;">' . htmlspecialchars($c['serial_number']) . '</span>';
        echo '<span style="color:var(--admin-muted);font-size:0.8rem;">' . htmlspecialchars($c['gas_name']) . ' (' . htmlspecialchars($c['size_capacity']) . ')</span>';
        echo '<span class="badge ' . $status_class . '" style="margin-left:auto;">' . htmlspecialchars($c['status']) . '</span>';
        echo '</label>';
    }
    echo '</div>';
    exit;
}

if ($action === 'lendable_cylinders_vendor') {
    $vendor_id = intval($_GET['vendor_id'] ?? 0);
    if ($vendor_id <= 0) { echo '<p style="color:var(--admin-muted);text-align:center;">Invalid vendor.</p>'; exit; }

    $cylinders = $pdo->prepare("
        SELECT c.id, c.serial_number, g.name as gas_name, c.size_capacity, c.status
        FROM cylinders c
        JOIN gas_types g ON c.gas_type_id = g.id
        WHERE c.ownership_type = 'owned' AND c.status IN ('empty', 'filled', 'in_maintenance') AND c.current_vendor_id IS NULL
        ORDER BY g.name, c.serial_number
    ");
    $cylinders->execute();
    $rows = $cylinders->fetchAll();

    if (empty($rows)) {
        echo '<p style="color:var(--admin-muted);text-align:center;padding:2rem;">No available cylinders to lend.</p>';
        exit;
    }

    echo '<div style="display:grid;gap:0.5rem;">';
    foreach ($rows as $c) {
        $status_class = $c['status'] === 'filled' ? 'badge-filled' : ($c['status'] === 'empty' ? 'badge-empty' : 'badge-under-maintenance');
        echo '<label style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem;border:1px solid var(--admin-border);border-radius:8px;cursor:pointer;">';
        echo '<input type="checkbox" name="cylinder_ids[]" value="' . $c['id'] . '">';
        echo '<span style="font-weight:600;font-size:0.85rem;">' . htmlspecialchars($c['serial_number']) . '</span>';
        echo '<span style="color:var(--admin-muted);font-size:0.8rem;">' . htmlspecialchars($c['gas_name']) . ' (' . htmlspecialchars($c['size_capacity']) . ')</span>';
        echo '<span class="badge ' . $status_class . '" style="margin-left:auto;">' . htmlspecialchars($c['status']) . '</span>';
        echo '</label>';
    }
    echo '</div>';
    exit;
}
