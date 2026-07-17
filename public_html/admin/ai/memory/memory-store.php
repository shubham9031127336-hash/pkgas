<?php

if (!function_exists('saveConversation')) {
    function saveConversation($pdo, $session_id, $user_id, $role, $message, $intent = null, $confidence = null, $response_time_ms = null, $tokens_used = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO ai_conversations (session_id, user_id, role, message, intent, confidence, response_time_ms, tokens_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$session_id, $user_id, $role, $message, $intent, $confidence, $response_time_ms, $tokens_used]);
            return $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("saveConversation failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('autoSaveEntityMemory')) {
    function autoSaveEntityMemory($pdo, $session_id, $user_id, $entities, $intent) {
        if (empty($entities)) return;
        $contextTags = "session:$session_id,intent:$intent";
        foreach ($entities as $e) {
            $type = $e['type'] ?? '';
            $value = $e['value'] ?? '';
            if (empty($type) || empty($value)) continue;
            $key = "last_queried_$type";
            $existing = getUserMemoryByKey($pdo, $user_id, $key);
            $stack = [];
            if ($existing && !empty($existing['memory_value'])) {
                $decoded = json_decode($existing['memory_value'], true);
                if (is_array($decoded)) {
                    $stack = $decoded;
                } else {
                    $stack = [$existing['memory_value']];
                }
            }
            $stack = array_values(array_filter($stack, fn($v) => $v !== $value));
            array_unshift($stack, $value);
            $stack = array_slice($stack, 0, 5);
            saveUserMemory($pdo, $user_id, $key, json_encode($stack), 0.6, $contextTags);
            $shortType = explode('_', $type)[0];
            if ($shortType !== $type) {
                $shortKey = "last_queried_$shortType";
                $shortExisting = getUserMemoryByKey($pdo, $user_id, $shortKey);
                $shortStack = [];
                if ($shortExisting && !empty($shortExisting['memory_value'])) {
                    $shortDecoded = json_decode($shortExisting['memory_value'], true);
                    if (is_array($shortDecoded)) {
                        $shortStack = $shortDecoded;
                    } else {
                        $shortStack = [$shortExisting['memory_value']];
                    }
                }
                $shortStack = array_values(array_filter($shortStack, fn($v) => $v !== $value));
                array_unshift($shortStack, $value);
                $shortStack = array_slice($shortStack, 0, 5);
                saveUserMemory($pdo, $user_id, $shortKey, json_encode($shortStack), 0.4, $contextTags);
            }
        }
    }
}

if (!function_exists('saveUserMemory')) {
    function saveUserMemory($pdo, $user_id, $memory_key, $memory_value, $confidence = 0.30, $context_tags = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO ai_user_memory (user_id, memory_key, memory_value, confidence, context_tags) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE memory_value = VALUES(memory_value), confidence = VALUES(confidence), context_tags = VALUES(context_tags)");
            $stmt->execute([$user_id, $memory_key, $memory_value, $confidence, $context_tags]);
            return true;
        } catch (PDOException $e) {
            error_log("saveUserMemory failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('updateUserMemoryAccess')) {
    function updateUserMemoryAccess($pdo, $memory_id) {
        try {
            $stmt = $pdo->prepare("UPDATE ai_user_memory SET last_accessed = NOW() WHERE id = ?");
            $stmt->execute([$memory_id]);
        } catch (PDOException $e) {
            error_log("updateUserMemoryAccess failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('saveSessionContext')) {
    function saveSessionContext($pdo, $session_id, $user_id, $context_data, $ttl_minutes = 60) {
        try {
            $expires = date('Y-m-d H:i:s', time() + ($ttl_minutes * 60));
            $json = json_encode($context_data);
            $stmt = $pdo->prepare("INSERT INTO ai_session_context (session_id, user_id, context_data, expires_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE context_data = VALUES(context_data), expires_at = VALUES(expires_at)");
            $stmt->execute([$session_id, $user_id, $json, $expires]);
            return true;
        } catch (PDOException $e) {
            error_log("saveSessionContext failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('saveFeedback')) {
    function saveFeedback($pdo, $conversation_id, $user_id, $rating, $feedback_text = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO ai_feedback (conversation_id, user_id, rating, feedback_text) VALUES (?, ?, ?, ?)");
            $stmt->execute([$conversation_id, $user_id, $rating, $feedback_text]);
            return true;
        } catch (PDOException $e) {
            error_log("saveFeedback failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('saveWorkflowMemory')) {
    function saveWorkflowMemory($pdo, $workflow_name, $trigger_pattern, $frequency_increment = 1) {
        try {
            $stmt = $pdo->prepare("INSERT INTO ai_workflow_memory (workflow_name, trigger_pattern, frequency, last_executed) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE frequency = frequency + ?, trigger_pattern = VALUES(trigger_pattern), last_executed = NOW()");
            $stmt->execute([$workflow_name, $trigger_pattern, $frequency_increment, $frequency_increment]);
            return true;
        } catch (PDOException $e) {
            error_log("saveWorkflowMemory failed: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cleanupExpiredSessions')) {
    function cleanupExpiredSessions($pdo) {
        try {
            $stmt = $pdo->prepare("DELETE FROM ai_session_context WHERE expires_at < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("cleanupExpiredSessions failed: " . $e->getMessage());
            return 0;
        }
    }
}
