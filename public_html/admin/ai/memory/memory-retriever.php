<?php

if (!function_exists('getConversationHistory')) {
    function getConversationHistory($pdo, $session_id, $limit = 50) {
        try {
            $limit = (int)$limit;
            $stmt = $pdo->prepare("SELECT id, role, message, intent, confidence, created_at FROM ai_conversations WHERE session_id = ? ORDER BY created_at DESC LIMIT $limit");
            $stmt->execute([$session_id]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getConversationHistory failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getUserMemories')) {
    function getUserMemories($pdo, $user_id, $min_confidence = 0.0, $limit = 20) {
        try {
            $limit = (int)$limit;
            $stmt = $pdo->prepare("SELECT id, memory_key, memory_value, confidence, context_tags, last_accessed FROM ai_user_memory WHERE user_id = ? AND confidence >= ? ORDER BY confidence DESC, last_accessed DESC LIMIT $limit");
            $stmt->execute([$user_id, $min_confidence]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getUserMemories failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getRoleMemories')) {
    function getRoleMemories($pdo, $role) {
        try {
            $stmt = $pdo->prepare("SELECT memory_key, memory_value, priority FROM ai_role_memory WHERE role = ? ORDER BY priority DESC");
            $stmt->execute([$role]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getRoleMemories failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getRoleMemoriesByKeys')) {
    function getRoleMemoriesByKeys($pdo, $role, $keys) {
        if (empty($keys)) return [];
        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $params = array_merge([$role], $keys);
            $stmt = $pdo->prepare("SELECT memory_key, memory_value, priority FROM ai_role_memory WHERE role = ? AND memory_key IN ($placeholders) ORDER BY priority DESC");
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getRoleMemoriesByKeys failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getSessionContext')) {
    function getSessionContext($pdo, $session_id) {
        try {
            $stmt = $pdo->prepare("SELECT context_data, expires_at FROM ai_session_context WHERE session_id = ? AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$session_id]);
            $row = $stmt->fetch();
            if ($row) {
                return json_decode($row['context_data'], true) ?? [];
            }
            return [];
        } catch (PDOException $e) {
            error_log("getSessionContext failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getUserMemoryByKey')) {
    function getUserMemoryByKey($pdo, $user_id, $memory_key) {
        try {
            $stmt = $pdo->prepare("SELECT id, memory_key, memory_value, confidence, context_tags FROM ai_user_memory WHERE user_id = ? AND memory_key = ? LIMIT 1");
            $stmt->execute([$user_id, $memory_key]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("getUserMemoryByKey failed: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('getWorkflowMemories')) {
    function getWorkflowMemories($pdo, $min_frequency = 1, $limit = 20) {
        try {
            $limit = (int)$limit;
            $stmt = $pdo->prepare("SELECT workflow_name, trigger_pattern, frequency, last_executed FROM ai_workflow_memory WHERE frequency >= ? ORDER BY frequency DESC, last_executed DESC LIMIT $limit");
            $stmt->execute([$min_frequency]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getWorkflowMemories failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getWorkflowMemoryByName')) {
    function getWorkflowMemoryByName($pdo, $workflow_name) {
        try {
            $stmt = $pdo->prepare("SELECT workflow_name, trigger_pattern, frequency, last_executed FROM ai_workflow_memory WHERE workflow_name = ? LIMIT 1");
            $stmt->execute([$workflow_name]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("getWorkflowMemoryByName failed: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('getConversationStats')) {
    function getConversationStats($pdo, $user_id = null) {
        try {
            $sql = "SELECT COUNT(*) AS total_messages, COUNT(DISTINCT session_id) AS total_sessions, AVG(response_time_ms) AS avg_response_ms FROM ai_conversations";
            $params = [];
            if ($user_id) {
                $sql .= " WHERE user_id = ?";
                $params[] = $user_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("getConversationStats failed: " . $e->getMessage());
            return ['total_messages' => 0, 'total_sessions' => 0, 'avg_response_ms' => null];
        }
    }
}
