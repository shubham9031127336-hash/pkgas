<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$gas_type_id = intval($_GET['gas_type_id'] ?? 0);
if ($gas_type_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid gas type ID']);
    exit;
}

require_once __DIR__ . '/inventory-utils.php';
runGasSizesMigration($pdo);

try {
    $stmt = $pdo->prepare("SELECT size_capacity, price, sort_order FROM gas_sizes WHERE gas_type_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$gas_type_id]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        try {
            // Bootstrap gas_sizes from gas_types CSV data if columns still exist
            $gt = $pdo->prepare("SELECT sizes, size_prices FROM gas_types WHERE id = ?");
            $gt->execute([$gas_type_id]);
            $gas = $gt->fetch();
            if ($gas && !empty($gas['sizes'])) {
                $sizes = array_map('trim', explode(',', $gas['sizes']));
                $prices = [];
                if (!empty($gas['size_prices'])) {
                    $prices = json_decode($gas['size_prices'], true) ?? [];
                }
                $order = 0;
                $ins = $pdo->prepare("INSERT IGNORE INTO gas_sizes (gas_type_id, size_capacity, price, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($sizes as $s) {
                    if (empty($s)) continue;
                    $price = isset($prices[$s]) ? floatval($prices[$s]) : null;
                    $ins->execute([$gas_type_id, $s, $price, $order]);
                    $rows[] = ['size_capacity' => $s, 'price' => $price, 'sort_order' => $order];
                    $order++;
                }
            }
        } catch (PDOException $e) {
            // Legacy columns may not exist after consolidation
        }
    }

    echo json_encode(['success' => true, 'sizes' => $rows]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
