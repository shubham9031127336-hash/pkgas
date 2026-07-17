<?php

require_once __DIR__ . '/../memory/memory-retriever.php';

if (!function_exists('handleSalesQuery')) {
    function handleSalesQuery($pdo, $user_message, $user_id, $role, $session_id) {
        $data = [];

        $sales_today = executeAllowedQuery($pdo, 'sales_today');
        if ($sales_today['success']) {
            $data['today'] = $sales_today['data'][0] ?? [];
        }

        $sales_weekly = executeAllowedQuery($pdo, 'sales_weekly');
        if ($sales_weekly['success']) {
            $data['weekly'] = $sales_weekly['data'];
        }

        $sales_monthly = executeAllowedQuery($pdo, 'sales_monthly');
        if ($sales_monthly['success']) {
            $data['monthly'] = $sales_monthly['data'];
        }

        $top_customers = executeAllowedQuery($pdo, 'top_customers');
        if ($top_customers['success']) {
            $data['top_customers'] = $top_customers['data'];
        }

        $role_memories = getRoleMemoriesByKeys($pdo, $role, ['focus', 'permission_scope']);

        $system_prompt = "You are Prem Gas Solution' sales assistant. Here is the current sales data:\n\n";
        if (!empty($data['today'])) {
            $t = $data['today'];
            $system_prompt .= "Today: ₹{$t['total_sales']} revenue from {$t['order_count']} orders.\n";
        }
        if (!empty($data['weekly'])) {
            $system_prompt .= "\nLast 7 days:\n";
            foreach ($data['weekly'] as $row) {
                $system_prompt .= "- {$row['day']}: ₹{$row['daily_total']} ({$row['order_count']} orders)\n";
            }
        }
        if (!empty($data['monthly'])) {
            $system_prompt .= "\nMonthly trend (last 12 months):\n";
            foreach ($data['monthly'] as $row) {
                $system_prompt .= "- {$row['month']}: ₹{$row['monthly_total']} ({$row['order_count']} orders)\n";
            }
        }
        if (!empty($role_memories)) {
            $system_prompt .= "\nRole context:\n";
            foreach ($role_memories as $rm) {
                $system_prompt .= "- {$rm['memory_key']}: {$rm['memory_value']}\n";
            }
        }

        $system_prompt .= "\nAnswer the user's sales question using the data above. Be concise. Use the same language as the user.";

        $ai_response = callAI($user_message, $system_prompt);

        return [
            'message' => $ai_response,
            'agent' => 'sales',
            'confidence' => 0.85,
            'data' => $data,
        ];
    }
}
