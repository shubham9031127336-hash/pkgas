<?php

require_once __DIR__ . '/../analytics/trend-analyzer.php';
require_once __DIR__ . '/../analytics/forecaster.php';
require_once __DIR__ . '/../analytics/data-aggregator.php';

if (!function_exists('handleAnalyticsQuery')) {
    function handleAnalyticsQuery($pdo, $user_message, $user_id, $role, $session_id) {
        $data = [];

        $data['snapshot'] = getBusinessSnapshot($pdo);
        $data['wow'] = compareWeekOverWeek($pdo);
        $data['mom'] = compareMonthOverMonth($pdo);
        $data['yoy'] = compareYearOverYear($pdo);
        $data['top_customers'] = getTopCustomersTrend($pdo, 10);

        $sales_forecast_data = getSalesForecast($pdo);
        $data['forecast'] = $sales_forecast_data;
        $data['depletion'] = getInventoryDepletionRate($pdo);

        try {
            $stmt = $pdo->query("SELECT c.serial_number, c.hydrotest_expiry, gt.name AS gas_name FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id WHERE c.hydrotest_expiry IS NOT NULL AND c.hydrotest_expiry < CURDATE() AND c.status NOT IN ('deleted','inactive') ORDER BY c.hydrotest_expiry ASC LIMIT 20");
            $data['anomalies']['overdue_hydro'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt2 = $pdo->query("SELECT p.id, p.company_name, COUNT(cyl.id) AS cylinders_held FROM partners p JOIN cylinders cyl ON p.id = cyl.current_partner_id WHERE cyl.status NOT IN ('deleted','inactive') GROUP BY p.id HAVING cylinders_held > 10 ORDER BY cylinders_held DESC LIMIT 10");
            $data['anomalies']['high_partner_holdings'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $stmt3 = $pdo->query("SELECT c.name, c.mobile, c.deposit_balance FROM customers c WHERE c.deposit_balance > 5000 AND c.status = 'active' ORDER BY c.deposit_balance DESC LIMIT 10");
            $data['anomalies']['high_deposits'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);

            $stmt4 = $pdo->query("SELECT gt.name AS gas_name, i.total_stock, i.min_alert_threshold, (i.min_alert_threshold - i.total_stock) AS deficit FROM inventory i JOIN gas_types gt ON i.gas_type_id = gt.id WHERE i.total_stock < i.min_alert_threshold ORDER BY deficit DESC");
            $data['anomalies']['critical_stock'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("anomaly detection queries failed: " . $e->getMessage());
        }

        $role_memories = getRoleMemoriesByKeys($pdo, $role, ['focus', 'permission_scope']);

        $system_prompt = "You are Prem Gas Solution' analytics assistant. Here is the business intelligence data:\n\n";

        $system_prompt .= formatMetricsForPrompt($data['snapshot']) . "\n\n";
        $system_prompt .= formatTrendForPrompt($pdo) . "\n\n";
        $system_prompt .= formatForecastForPrompt($pdo) . "\n\n";

        if (!empty($data['anomalies']['overdue_hydro'])) {
            $system_prompt .= "OVERDUE HYDROTEST CYLINDERS:\n";
            foreach ($data['anomalies']['overdue_hydro'] as $oh) {
                $system_prompt .= "- {$oh['serial_number']} ({$oh['gas_name']}), Expired: {$oh['hydrotest_expiry']}\n";
            }
            $system_prompt .= "\n";
        }
        if (!empty($data['anomalies']['high_partner_holdings'])) {
            $system_prompt .= "HIGH PARTNER CYLINDER HOLDINGS:\n";
            foreach ($data['anomalies']['high_partner_holdings'] as $ph) {
                $system_prompt .= "- {$ph['company_name']}: {$ph['cylinders_held']} cylinders\n";
            }
            $system_prompt .= "\n";
        }
        if (!empty($data['anomalies']['high_deposits'])) {
            $system_prompt .= "HIGH CUSTOMER DEPOSITS:\n";
            foreach ($data['anomalies']['high_deposits'] as $hd) {
                $system_prompt .= "- {$hd['name']} ({$hd['mobile']}): ₹{$hd['deposit_balance']}\n";
            }
            $system_prompt .= "\n";
        }
        if (!empty($data['anomalies']['critical_stock'])) {
            $system_prompt .= "CRITICAL LOW STOCK:\n";
            foreach ($data['anomalies']['critical_stock'] as $cs) {
                $system_prompt .= "- {$cs['gas_name']}: {$cs['total_stock']} left (deficit: {$cs['deficit']})\n";
            }
            $system_prompt .= "\n";
        }

        if (!empty($role_memories)) {
            $system_prompt .= "Role context:\n";
            foreach ($role_memories as $rm) {
                $system_prompt .= "- {$rm['memory_key']}: {$rm['memory_value']}\n";
            }
        }

        $system_prompt .= "\nAnswer the user's analytical question using the data above. Provide insights, comparisons, and actionable observations. Flag any anomalies or issues found. Use the same language as the user.";

        $ai_response = callAI($user_message, $system_prompt);

        return [
            'message' => $ai_response,
            'agent' => 'analytics',
            'confidence' => 0.88,
            'data' => $data,
        ];
    }
}
