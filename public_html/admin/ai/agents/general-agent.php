<?php

require_once __DIR__ . '/../analytics/data-aggregator.php';
require_once __DIR__ . '/../analytics/trend-analyzer.php';
require_once __DIR__ . '/../memory/memory-retriever.php';

if (!function_exists('handleGeneralQuery')) {
    function handleGeneralQuery($pdo, $user_message, $user_id, $role, $session_id) {
        $data = [];

        $data['snapshot'] = getBusinessSnapshot($pdo);
        $data['wow'] = compareWeekOverWeek($pdo);

        $role_memories = getRoleMemoriesByKeys($pdo, $role, ['focus', 'permission_scope']);

        $system_prompt = "You are an AI assistant for Prem Gas Solution, an industrial gas supply business.\n";
        $system_prompt .= "You assist staff with their daily work. Current user role: $role.\n\n";

        $system_prompt .= formatMetricsForPrompt($data['snapshot']) . "\n\n";

        if (!empty($role_memories)) {
            $system_prompt .= "Role context:\n";
            foreach ($role_memories as $rm) {
                $system_prompt .= "- {$rm['memory_key']}: {$rm['memory_value']}\n";
            }
        }

        $system_prompt .= "\nGuidelines:\n";
        $system_prompt .= "- Be concise, professional, and helpful.\n";
        $system_prompt .= "- Answer in the same language the user used (English, Hindi, or Hinglish).\n";
        $system_prompt .= "- You are read-only and cannot modify any data.\n";
        $system_prompt .= "- If the user asks about specific customers, inventory, or sales, suggest they ask more specifically.\n";
        $system_prompt .= "- You can help with business questions, explain processes, summarize data, and provide general assistance.\n";
        $system_prompt .= "- Never make up data. Only use the information provided above.\n";

        $ai_response = callAI($user_message, $system_prompt);

        return [
            'message' => $ai_response,
            'agent' => 'general',
            'confidence' => 0.70,
            'data' => $data,
        ];
    }
}
