<?php

if (!function_exists('compressConversation')) {
    function compressConversation($messages) {
        if (empty($messages)) return '';

        $lines = [];
        $user_msgs = [];
        $ai_msgs = [];
        $intents = [];
        $total = count($messages);

        foreach ($messages as $msg) {
            $text = trim($msg['message'] ?? '');
            if ($text === '') continue;
            $role = $msg['role'] ?? '';

            if ($role === 'user') {
                $user_msgs[] = $text;
            } elseif ($role === 'assistant') {
                $ai_msgs[] = $text;
            }

            if (!empty($msg['intent'])) {
                $intents[$msg['intent']] = ($intents[$msg['intent']] ?? 0) + 1;
            }
        }

        $lines[] = "Conversation: $total messages";
        $lines[] = "User asked: " . implode(' | ', array_slice($user_msgs, 0, 5));
        $lines[] = "Assistant replied: " . implode(' | ', array_slice($ai_msgs, 0, 3));

        if (!empty($intents)) {
            arsort($intents);
            $lines[] = "Intents: " . implode(', ', array_map(function($k, $v) { return "$k($v)"; }, array_keys($intents), $intents));
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('storeCompressedMemory')) {
    function storeCompressedMemory($pdo, $session_id, $user_id, $workflow_name) {
        $messages = [];
        try {
            $stmt = $pdo->prepare("SELECT role, message, intent FROM ai_conversations WHERE session_id = ? ORDER BY created_at ASC");
            $stmt->execute([$session_id]);
            $messages = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("storeCompressedMemory fetch failed: " . $e->getMessage());
            return false;
        }

        if (empty($messages)) return false;

        $summary = compressConversation($messages);
        $trigger_pattern = substr($summary, 0, 255);

        try {
            $stmt = $pdo->prepare("INSERT INTO ai_workflow_memory (workflow_name, trigger_pattern, frequency, last_executed) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE trigger_pattern = VALUES(trigger_pattern), frequency = frequency + 1, last_executed = NOW()");
            $stmt->execute([$workflow_name, $trigger_pattern]);
            return true;
        } catch (PDOException $e) {
            error_log("storeCompressedMemory upsert failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mergeWorkflowPatterns')) {
    function mergeWorkflowPatterns($pdo, $workflow_name, $new_pattern) {
        try {
            $stmt = $pdo->prepare("SELECT trigger_pattern FROM ai_workflow_memory WHERE workflow_name = ? LIMIT 1");
            $stmt->execute([$workflow_name]);
            $existing = $stmt->fetchColumn();

            if ($existing) {
                $existing_patterns = explode("\n---\n", $existing);
                $existing_patterns[] = $new_pattern;
                $existing_patterns = array_unique(array_filter($existing_patterns));
                $existing_patterns = array_slice($existing_patterns, 0, 10);
                $merged = implode("\n---\n", $existing_patterns);

                $stmt = $pdo->prepare("UPDATE ai_workflow_memory SET trigger_pattern = ?, frequency = frequency + 1, last_executed = NOW() WHERE workflow_name = ?");
                $stmt->execute([$merged, $workflow_name]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO ai_workflow_memory (workflow_name, trigger_pattern, frequency, last_executed) VALUES (?, ?, 1, NOW())");
                $stmt->execute([$workflow_name, $new_pattern]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("mergeWorkflowPatterns failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('pruneOldMemories')) {
    function pruneOldMemories($pdo, $user_id = null, $max_age_days = 90) {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - ($max_age_days * 86400));

            $conversations_deleted = 0;
            if ($user_id) {
                $stmt = $pdo->prepare("DELETE FROM ai_conversations WHERE user_id = ? AND created_at < ?");
                $stmt->execute([$user_id, $cutoff]);
                $conversations_deleted = $stmt->rowCount();

                $stmt = $pdo->prepare("DELETE FROM ai_user_memory WHERE user_id = ? AND last_accessed < ?");
                $stmt->execute([$user_id, $cutoff]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM ai_conversations WHERE created_at < ?");
                $stmt->execute([$cutoff]);
                $conversations_deleted = $stmt->rowCount();

                $stmt = $pdo->prepare("DELETE FROM ai_user_memory WHERE last_accessed < ?");
                $stmt->execute([$cutoff]);
            }

            $stmt = $pdo->prepare("DELETE FROM ai_session_context WHERE expires_at < NOW()");
            $stmt->execute();
            $sessions_deleted = $stmt->rowCount();

            return [
                'conversations_deleted' => $conversations_deleted,
                'sessions_deleted' => $sessions_deleted,
            ];
        } catch (PDOException $e) {
            error_log("pruneOldMemories failed: " . $e->getMessage());
            return ['conversations_deleted' => 0, 'sessions_deleted' => 0];
        }
    }
}
