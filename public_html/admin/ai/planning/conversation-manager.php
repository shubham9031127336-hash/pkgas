<?php

require_once __DIR__ . '/../memory/memory-retriever.php';
require_once __DIR__ . '/../memory/memory-store.php';

define('MAX_PENDING_CONTEXT_SIZE', 102400);
define('MAX_FOLLOWUP_TURNS', 5);

if (!function_exists('getConversationMessages')) {
    function getConversationMessages($pdo, $session_id, $max_turns = 10) {
        $history = getConversationHistory($pdo, $session_id, $max_turns);
        $history = array_reverse($history);
        $messages = [];
        foreach ($history as $turn) {
            $messages[] = [
                'role' => $turn['role'],
                'content' => $turn['message'],
            ];
        }
        return $messages;
    }
}

if (!function_exists('getPendingState')) {
    function getPendingState($pdo, $session_id) {
        $context = getSessionContext($pdo, $session_id);
        if (!empty($context) && isset($context['pending'])) {
            return $context['pending'];
        }
        return null;
    }
}

if (!function_exists('savePendingState')) {
    function savePendingState($pdo, $session_id, $user_id, $state) {
        $context = getSessionContext($pdo, $session_id);
        if (!is_array($context)) {
            $context = [];
        }
        $context['pending'] = $state;
        saveSessionContext($pdo, $session_id, $user_id, $context, 120);
    }
}

if (!function_exists('clearPendingState')) {
    function clearPendingState($pdo, $session_id, $user_id) {
        $context = getSessionContext($pdo, $session_id);
        if (!is_array($context)) {
            return;
        }
        unset($context['pending']);
        saveSessionContext($pdo, $session_id, $user_id, $context, 120);
    }
}

if (!function_exists('saveSessionFocus')) {
    function saveSessionFocus($pdo, $session_id, $user_id, $focusData) {
        $context = getSessionContext($pdo, $session_id);
        if (!is_array($context)) $context = [];
        $context['focus'] = [
            'type' => $focusData['type'] ?? '',
            'value' => $focusData['value'] ?? '',
            'id' => $focusData['id'] ?? '',
            'intent' => $focusData['intent'] ?? '',
        ];
        if (!isset($context['entity_history'])) $context['entity_history'] = [];
        if (!empty($focusData['entities'])) {
            foreach ($focusData['entities'] as $e) {
                $context['entity_history'][] = $e;
            }
            $context['entity_history'] = array_slice($context['entity_history'], -15);
        }
        saveSessionContext($pdo, $session_id, $user_id, $context, 120);
    }
}

if (!function_exists('getSessionFocus')) {
    function getSessionFocus($pdo, $session_id) {
        $context = getSessionContext($pdo, $session_id);
        return $context['focus'] ?? null;
    }
}

if (!function_exists('updateConversationSummary')) {
    function updateConversationSummary($pdo, $session_id, $user_id, $message, $intent, $entities, $reply = '') {
        $context = getSessionContext($pdo, $session_id);
        if (!is_array($context)) $context = [];
        if (!isset($context['summary'])) $context['summary'] = [];
        $context['summary'][] = [
            'intent' => $intent,
            'entities' => $entities,
            'user_said' => mb_substr($message, 0, 120),
            'ai_replied' => mb_substr($reply, 0, 120),
            'time' => date('H:i:s'),
        ];
        $context['summary'] = array_slice($context['summary'], -6);
        saveSessionContext($pdo, $session_id, $user_id, $context, 120);
    }
}

if (!function_exists('formatSessionFocusForPrompt')) {
    function formatSessionFocusForPrompt($pdo, $session_id) {
        $context = getSessionContext($pdo, $session_id);
        $focus = $context['focus'] ?? null;
        $lines = [];
        if ($focus && !empty($focus['type'])) {
            $lines[] = "=== ACTIVE CONVERSATION CONTEXT ===";
            $lines[] = "Currently discussing: {$focus['type']} = \"{$focus['value']}\"";
            if (!empty($focus['id'])) $lines[] = "Entity database ID: {$focus['id']}";
            $lines[] = "Current intent: {$focus['intent']}";
        }
        if (!empty($context['entity_history'])) {
            $seen = [];
            $lines[] = "Entities mentioned so far in this session:";
            foreach ($context['entity_history'] as $e) {
                $key = ($e['type'] ?? '?') . ':' . ($e['value'] ?? '?');
                if (!isset($seen[$key])) {
                    $lines[] = "- {$e['type']}: {$e['value']}";
                    $seen[$key] = true;
                }
            }
        }
        if (!empty($context['summary'])) {
            $lines[] = "Recent conversation summary:";
            foreach ($context['summary'] as $s) {
                $intent = $s['intent'] ?? '?';
                $time = $s['time'] ?? '';
                $userMsg = $s['user_said'] ?? '';
                $aiReply = $s['ai_replied'] ?? '';
                $lines[] = "- [$time] ($intent) User: \"$userMsg\"";
                if (!empty($aiReply)) {
                    $lines[] = "  AI: \"$aiReply\"";
                }
            }
        }
        if (!empty($lines)) {
            $lines[] = "";
            $lines[] = "IMPORTANT: Use this context to understand references like 'he', 'she', 'it', 'they', 'that customer', 'his cylinders', etc. These refer to the entity currently being discussed.";
            $lines[] = "";
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('isAskingQuestion')) {
    function isAskingQuestion($response) {
        $trimmed = trim($response);
        if (empty($trimmed)) {
            return false;
        }

        if (strpos($trimmed, '[QUESTION]') !== false) {
            return true;
        }

        $lastChar = mb_substr($trimmed, -1);
        if ($lastChar === '?') {
            return true;
        }

        $lower = mb_strtolower($trimmed);
        $patterns = [
            '/would you like/i',
            '/can you (provide|tell|specify|give)/i',
            '/please provide/i',
            '/could you/i',
            '/do you want/i',
            '/shall i/i',
            '/please specify/i',
            '/which (one|customer|cylinder|product|invoice)/i',
            '/what is the (name|number|mobile|id)/i',
            '/how (many|much) do you/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $lower)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('buildPendingPromptSection')) {
    function buildPendingPromptSection($pending_state, $user_message) {
        if (!$pending_state) {
            return '';
        }

        $lines = [];
        $lines[] = "=== PREVIOUS CONVERSATION CONTEXT ===";
        $lines[] = "This is a follow-up to your previous response in the same conversation.";
        $lines[] = "Intent: " . ($pending_state['intent'] ?? 'unknown');
        if (!empty($pending_state['entities'])) {
            $lines[] = "Known entities: " . json_encode($pending_state['entities']);
        }
        if (!empty($pending_state['query_results'])) {
            $lines[] = "Previously retrieved data is available below in PRIOR DATA section. Use it instead of re-querying or making assumptions.";
        }
        $lines[] = 'Your previous question: "' . ($pending_state['last_question'] ?? '') . '"';
        $lines[] = "User's reply: \"$user_message\"";
        $lines[] = "";
        $lines[] = "Rules for follow-up:";
        $lines[] = "- Use the previous context and data combined with the user's reply to answer.";
        $lines[] = "- If the user gave you the information you asked for, provide the complete answer now.";
        $lines[] = "- If more information is still needed, ask another [QUESTION].";
        $lines[] = "";

        return implode("\n", $lines) . "\n";
    }
}
