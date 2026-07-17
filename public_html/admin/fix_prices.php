<?php
require_once __DIR__ . '/db.php';

// Set default prices for gas sizes based on gas_types.default_price_per_kg
$stmt = $pdo->query("
    SELECT gs.id, gs.gas_type_id, gs.price, gt.default_price_per_kg, gt.name
    FROM gas_sizes gs
    JOIN gas_types gt ON gs.gas_type_id = gt.id
    WHERE gs.price IS NULL OR gs.price = 0
");
$count = 0;
while ($r = $stmt->fetch()) {
    $pdo->prepare("UPDATE gas_sizes SET price = ? WHERE id = ?")
        ->execute([$r['default_price_per_kg'], $r['id']]);
    echo "Fixed: {$r['name']} size price set to {$r['default_price_per_kg']}\n";
    $count++;
}
echo "Total fixed: $count\n";
?>