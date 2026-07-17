<?php

require_once __DIR__ . '/../memory/memory-retriever.php';
require_once __DIR__ . '/../analytics/data-aggregator.php';
require_once __DIR__ . '/../analytics/trend-analyzer.php';

if (!function_exists('handleInventoryQuery')) {
    function handleInventoryQuery($pdo, $user_message, $user_id, $role, $session_id) {
        $data = [];

        $result = executeAllowedQuery($pdo, 'inventory_stock');
        if ($result['success']) {
            $data['stock_summary'] = $result['data'];
        }

        $result2 = executeAllowedQuery($pdo, 'cylinder_status_count');
        if ($result2['success']) {
            $data['cylinder_status'] = $result2['data'];
        }

        $data['low_stock'] = getLowStockAlerts($pdo);
        $data['inventory_detail'] = getInventorySummary($pdo);

        try {
            $stmt = $pdo->query("SELECT gt.name AS gas_name, COUNT(c.id) AS total FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id WHERE c.status NOT IN ('deleted','inactive') GROUP BY gt.name ORDER BY total DESC");
            $data['gas_type_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt2 = $pdo->query("SELECT ownership_type, COUNT(*) AS count FROM cylinders WHERE status NOT IN ('deleted','inactive') GROUP BY ownership_type");
            $data['ownership_breakdown'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $data['depletion'] = getInventoryDepletionRate($pdo);
        } catch (PDOException $e) {
            error_log("inventory deep dive queries failed: " . $e->getMessage());
        }

        $role_memories = getRoleMemoriesByKeys($pdo, $role, ['focus', 'permission_scope']);

        $system_prompt = "You are Prem Gas Solution' inventory assistant. You have the following current inventory data:\n\n";
        if (!empty($data['stock_summary'])) {
            $system_prompt .= "Stock by gas type:\n";
            foreach ($data['stock_summary'] as $row) {
                $system_prompt .= "- {$row['gas_name']}: {$row['total']} units\n";
            }
        }
        if (!empty($data['gas_type_distribution'])) {
            $system_prompt .= "\nGas type cylinder distribution:\n";
            foreach ($data['gas_type_distribution'] as $g) {
                $system_prompt .= "- {$g['gas_name']}: {$g['total']} cylinders\n";
            }
        }
        if (!empty($data['ownership_breakdown'])) {
            $system_prompt .= "\nOwnership breakdown:\n";
            foreach ($data['ownership_breakdown'] as $o) {
                $system_prompt .= "- {$o['ownership_type']}: {$o['count']}\n";
            }
        }
        if (!empty($data['cylinder_status'])) {
            $system_prompt .= "\nCylinder status breakdown:\n";
            foreach ($data['cylinder_status'] as $row) {
                $system_prompt .= "- {$row['status']}: {$row['count']}\n";
            }
        }
        if (!empty($data['low_stock'])) {
            $system_prompt .= "\nLow stock alerts:\n";
            foreach ($data['low_stock'] as $ls) {
                $system_prompt .= "- {$ls['gas_name']}: {$ls['total_stock']} left (min: {$ls['min_alert_threshold']})\n";
            }
        }
        if (!empty($data['depletion'])) {
            $system_prompt .= "\nDepletion forecast:\n";
            foreach ($data['depletion'] as $d) {
                $system_prompt .= "- {$d['gas_name']}: {$d['days_until_empty']} days until empty\n";
            }
        }
        if (!empty($role_memories)) {
            $system_prompt .= "\nRole context:\n";
            foreach ($role_memories as $rm) {
                $system_prompt .= "- {$rm['memory_key']}: {$rm['memory_value']}\n";
            }
        }

        $system_prompt .= "\nAnswer the user's inventory question using the data above. Be concise. Use the same language as the user.";

        $ai_response = callAI($user_message, $system_prompt);

        return [
            'message' => $ai_response,
            'agent' => 'inventory',
            'confidence' => 0.85,
            'data' => $data,
        ];
    }
}
