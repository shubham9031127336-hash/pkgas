<?php

require_once __DIR__ . '/../ai-helper.php';
require_once __DIR__ . '/memory/memory-store.php';
require_once __DIR__ . '/memory/memory-retriever.php';
require_once __DIR__ . '/security/permission-gate.php';
require_once __DIR__ . '/planning/response-builder.php';
require_once __DIR__ . '/planning/conversation-manager.php';
require_once __DIR__ . '/query/query-executor.php';

define('MAX_INVESTIGATION_ROUNDS', 2);

if (!function_exists('processUserMessage')) {
    function processUserMessage($pdo, $user_message, $user_id, $role, $session_id, $language_override = '') {
        $start_time = microtime(true);
        $message = '';
        $intent = 'general';
        $confidence_score = 0.5;
        $is_question = false;
        $confidence_level = 'insufficient_data';
        $queryResults = null;
        $queryContext = [];
        $visualBlocks = [];
        $options = [];

        try {
            $language_mode = getLanguageMode($pdo, $language_override);

            $greetings = ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'namaste', 'namaskar', 'hii', 'heyy', 'hlo', 'helo'];
            $isGreeting = in_array(strtolower(trim($user_message)), $greetings) || preg_match('/^(hi|hello|hey|good\s+(morning|afternoon|evening)|namaste|hii|heyy|hlo)\b/i', trim($user_message));
            if ($isGreeting) {
                $greetMsg = $language_mode === 'english' ? 'Hello! How can I help you with Prem Gas Solution today?' : 'Namaste! Aap Prem Gas Solution ke baare mein kya jaanna chaahenge?';
                $elapsed_ms = (int)((microtime(true) - $start_time) * 1000);
                saveConversation($pdo, $session_id, $user_id, 'user', $user_message, 'general', 0.5, null, null);
                $assistant_id = saveConversation($pdo, $session_id, $user_id, 'assistant', $greetMsg, 'general', 0.5, $elapsed_ms, null);
                return ['message' => $greetMsg, 'intent' => 'general', 'agent' => 'general', 'confidence' => 0.5, 'data' => null, 'response_time_ms' => $elapsed_ms, 'conversation_id' => $assistant_id, 'is_question' => false, 'confidence_level' => 'verified'];
            }

            $pending_state = getPendingState($pdo, $session_id);
            $current_follow_up = $pending_state ? ($pending_state['follow_up_count'] ?? 0) : 0;

            $historyMessages = getConversationMessages($pdo, $session_id, 15);

            list($phase1Result, $phase1Raw) = callPhase1($pdo, $user_message, $role, $session_id, $language_mode, $historyMessages, $pending_state);

            $intent = $phase1Result['intent'] ?? 'general';
            $entities = $phase1Result['entities'] ?? [];

            if (!empty($entities)) {
                autoSaveEntityMemory($pdo, $session_id, $user_id, $entities, $intent);
                $primaryEntity = $entities[0] ?? [];
                saveSessionFocus($pdo, $session_id, $user_id, [
                    'type' => $primaryEntity['type'] ?? '',
                    'value' => $primaryEntity['value'] ?? '',
                    'id' => $primaryEntity['id'] ?? '',
                    'intent' => $intent,
                    'entities' => $entities,
                ]);
            }

            if (!empty($phase1Result['needs_follow_up'])) {
                $fallbackQuestion = ($language_mode === 'english' || empty($language_mode)) ? 'Could you please provide more details?' : ($language_mode === 'hindi' ? 'Kya aap aur jankari de sakte hain?' : 'Kya aap aur details bata sakte hain?');
                $message = $phase1Result['follow_up_question'] ?? $fallbackQuestion;
                $is_question = true;
                $confidence_level = 'insufficient_data';

                $new_pending = [
                    'intent' => $intent,
                    'entities' => $entities,
                    'last_question' => $message,
                    'follow_up_count' => $current_follow_up + 1,
                    'phase1_result' => $phase1Result,
                ];
                savePendingState($pdo, $session_id, $user_id, $new_pending);
            } else {
                $sqlQueries = $phase1Result['sql_queries'] ?? [];
                $queryResults = executeQueryPlan($pdo, $sqlQueries, $role);
                $queryContext = [
                    'intent' => $intent,
                    'entities' => $entities,
                    'sql_count' => count($sqlQueries),
                ];

                if (!hasAnyData($queryResults)) {
                    for ($round = 1; $round <= MAX_INVESTIGATION_ROUNDS; $round++) {
                        $investigationResult = performInvestigation($pdo, $user_message, $intent, $entities, $role, $language_mode, $historyMessages, $round, $queryResults);
                        if ($investigationResult && hasAnyData($investigationResult['results'])) {
                            foreach ($investigationResult['results'] as $k => $v) {
                                if (!isset($queryResults[$k])) {
                                    $queryResults[$k] = $v;
                                }
                            }
                            $queryContext['investigated'] = true;
                            $queryContext['investigation_rounds'] = $round;
                            break;
                        }
                    }
                }

                if ($pending_state && !empty($pending_state['query_results'])) {
                    foreach ($pending_state['query_results'] as $k => $v) {
                        if (!isset($queryResults[$k])) {
                            $queryResults[$k] = $v;
                        }
                    }
                }

                $drillDownResults = performAutoDrillDown($pdo, $entities, $queryResults);
                if (!empty($drillDownResults)) {
                    foreach ($drillDownResults as $k => $v) {
                        if (!isset($queryResults[$k])) {
                            $queryResults[$k] = $v;
                            $queryContext['drill_down'][$k] = true;
                        }
                    }
                }

                list($phase2Result, $phase2Raw) = callPhase2($pdo, $user_message, $intent, $entities, $queryResults, $queryContext, $role, $language_mode, $session_id, $historyMessages, $pending_state);

                $message = $phase2Result['message'] ?? 'Sorry, I could not process your request. Please try again.';
                $is_question = !empty($phase2Result['is_question']);
                $confidence_level = $phase2Result['confidence'] ?? 'insufficient_data';

                if ($pending_state) {
                    clearPendingState($pdo, $session_id, $user_id);
                }

                $visualBlocks = $phase2Result['visual_blocks'] ?? [];
                $options = $phase2Result['options'] ?? [];

                if (!empty($phase2Result['memory_updates'])) {
                    saveMemoryUpdates($pdo, $user_id, $role, $phase2Result['memory_updates']);
                }
            }

            updateConversationSummary($pdo, $session_id, $user_id, $user_message, $intent, $entities, $message);
        } catch (Throwable $e) {
            error_log("processUserMessage error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            $err = $e->getMessage();
            if (stripos($err, 'rate limit') !== false || stripos($err, '429') !== false) {
                $message = ($language_mode === 'english' || empty($language_mode))
                    ? "The AI service rate limit has been reached. Please wait a while or switch to a different provider/model in AI Settings."
                    : "AI service ki rate limit pooch gayi hai. Settings mein provider/model change karein ya thodi der baad try karein.";
            } else {
                $message = ($language_mode === 'english' || empty($language_mode))
                    ? "I encountered an AI service error. Please check your provider settings or try again."
                    : "AI service mein error aaya hai. Settings check karein ya thodi der baad try karein.";
            }
        }

        $elapsed_ms = (int)((microtime(true) - $start_time) * 1000);

        saveConversation($pdo, $session_id, $user_id, 'user', $user_message, $intent, $confidence_score, null, null);
        $assistant_id = saveConversation($pdo, $session_id, $user_id, 'assistant', $message, $intent, $confidence_score, $elapsed_ms, null);

        return [
            'message' => $message,
            'intent' => $intent,
            'agent' => $intent === 'general' ? 'general' : $intent,
            'confidence' => $confidence_score,
            'data' => null,
            'visual_blocks' => $visualBlocks,
            'options' => $options,
            'response_time_ms' => $elapsed_ms,
            'conversation_id' => $assistant_id,
            'is_question' => $is_question,
            'confidence_level' => $confidence_level,
        ];
    }
}

if (!function_exists('getFormattedMemories')) {
    function getFormattedMemories($pdo, $user_id, $role) {
        $parts = [];
        $userMemories = getUserMemories($pdo, $user_id, 0.2, 10);
        if (!empty($userMemories)) {
            $parts[] = "USER MEMORIES:";
            foreach ($userMemories as $m) {
                $decoded = json_decode($m['memory_value'], true);
                if (is_array($decoded)) {
                    $parts[] = "- {$m['memory_key']}: " . implode(', ', $decoded);
                } else {
                    $parts[] = "- {$m['memory_key']}: {$m['memory_value']}";
                }
            }
        }
        $roleMemories = getRoleMemories($pdo, $role);
        if (!empty($roleMemories)) {
            $parts[] = "ROLE MEMORIES:";
            foreach ($roleMemories as $m) {
                $parts[] = "- {$m['memory_key']}: {$m['memory_value']}";
            }
        }
        return implode("\n", $parts);
    }
}

if (!function_exists('callPhase1')) {
    function callPhase1($pdo, $user_message, $role, $session_id, $language_mode, $historyMessages, $pending_state = null) {
        $focusContext = formatSessionFocusForPrompt($pdo, $session_id);
        $systemPrompt = buildPhase1SystemPrompt($pdo, $role, $language_mode);
        if (!empty($focusContext)) {
            $systemPrompt .= "\n" . $focusContext . "\n";
        }
        $userPrompt = buildPhase1UserPrompt($pdo, $user_message, $pending_state);

        $user_id = $_SESSION['user_id'] ?? 0;
        $memories = getFormattedMemories($pdo, $user_id, $role);

        $messages = [];
        if (!empty($memories)) {
            $messages[] = ['role' => 'system', 'content' => $memories];
        }
        foreach ($historyMessages as $msg) {
            $messages[] = $msg;
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $rawResponse = callAI($messages, $systemPrompt, [
            'timeout' => 30,
            'max_tokens' => 4096,
            'temperature' => 0.5,
        ]);

        $parsed = parseAIJson($rawResponse);
        if ($parsed === null) {
            $logSnippet = substr($rawResponse, 0, 1000);
            error_log("callPhase1: failed to parse LLM response as JSON: " . $logSnippet);
            $debugPath = __DIR__ . '/ai_debug_phase1.log';
            file_put_contents($debugPath, date('Y-m-d H:i:s') . " PHASE1 RAW:\n" . $rawResponse . "\n\n===END===\n\n", FILE_APPEND | LOCK_EX);
            $qMsg = $language_mode === 'english' ? 'I did not understand your request. Could you please rephrase it?' : 'Main aapki baat nahi samajh paya. Kya aap dobara bata sakte hain?';
            return [[
                'intent' => 'general',
                'entities' => [],
                'sql_queries' => [],
                'needs_follow_up' => true,
                'follow_up_question' => $qMsg,
            ], $rawResponse];
        }

        if (!empty($parsed['needs_follow_up'])) {
            $debugPath = __DIR__ . '/ai_debug_followup.log';
            file_put_contents($debugPath, date('Y-m-d H:i:s') . " FOLLOW-UP TRIGGERED:\nUser: {$user_message}\nIntent: {$parsed['intent']}\nEntities: " . json_encode($parsed['entities'] ?? []) . "\nLLM follow_up_question: " . ($parsed['follow_up_question'] ?? '') . "\nFull response: " . json_encode($parsed) . "\n\n===END===\n\n", FILE_APPEND | LOCK_EX);
        }

        return [$parsed, $rawResponse];
    }
}

if (!function_exists('callPhase2')) {
    function callPhase2($pdo, $user_message, $intent, $entities, $results, $context, $role, $language_mode, $session_id, $historyMessages, $pending_state = null) {
        $focusContext = formatSessionFocusForPrompt($pdo, $session_id);
        $systemPrompt = buildPhase2SystemPrompt($pdo, $role, $language_mode);
        if (!empty($focusContext)) {
            $systemPrompt .= "\n" . $focusContext . "\n";
        }
        $userPrompt = buildPhase2UserPrompt($pdo, $user_message, $intent, $entities, $results, $context, $pending_state);

        $user_id = $_SESSION['user_id'] ?? 0;
        $memories = getFormattedMemories($pdo, $user_id, $role);

        $messages = [];
        if (!empty($memories)) {
            $messages[] = ['role' => 'system', 'content' => $memories];
        }
        foreach ($historyMessages as $msg) {
            $messages[] = $msg;
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $hasData = hasAnyData($results);
        $maxTokens = 2048;
        $timeout = 45;

        $rawResponse = callAI($messages, $systemPrompt, [
            'timeout' => $timeout,
            'max_tokens' => $maxTokens,
            'temperature' => 0.5,
        ]);

        $parsed = parseAIJson($rawResponse);
        if ($parsed === null) {
            error_log("callPhase2: failed to parse LLM response as JSON: " . substr($rawResponse, 0, 500));
            file_put_contents(__DIR__ . '/ai_debug_phase2.log', date('Y-m-d H:i:s') . " PHASE2 RAW:\n" . $rawResponse . "\n\n===END===\n\n", FILE_APPEND | LOCK_EX);
            $findings = extractKeyFindings($results);
            if ($language_mode === 'english' || empty($language_mode)) {
                $fallback = "I checked the database for your query. ";
                $fallback .= !empty($findings) ? "Here are the results:\n" . implode("\n", $findings) : "No specific records found. Could you provide more details?";
            } elseif ($language_mode === 'hindi') {
                $fallback = "Ji, maine aapki query ke liye data check kiya. ";
                $fallback .= !empty($findings) ? "Yeh raha result:\n" . implode("\n", $findings) : "Lekin koi specific record nahi mila. Aap kuch aur jankari de sakte hain?";
            } else {
                $fallback = "Ji, maine aapki query ke liye data check kiya. ";
                $fallback .= !empty($findings) ? "Yeh raha result:\n" . implode("\n", $findings) : "Lekin koi specific record nahi mila. Aap kuch aur details bata sakte hain?";
            }
            return [[
                'message' => $fallback,
                'confidence' => 'insufficient_data',
                'is_question' => false,
                'memory_updates' => [],
            ], $rawResponse];
        }

        if (empty($parsed['message'])) {
            if ($language_mode === 'english' || empty($language_mode)) {
                $parsed['message'] = "I understand your request. Is there anything else you would like to know?";
            } elseif ($language_mode === 'hindi') {
                $parsed['message'] = "Ji, maine aapki baat samajh li. Kya aap kuch aur poochna chahenge?";
            } else {
                $parsed['message'] = "Ji, maine aapki baat samajh li. Kya aap kuch aur poochna chahenge?";
            }
        }

        // Execute any actions requested by the LLM
        if (!empty($parsed['actions'])) {
            require_once __DIR__ . '/actions/action-executor.php';
            $role = $_SESSION['user_role'] ?? '';
            $actionResults = [];
            foreach ($parsed['actions'] as $action) {
                $actionName = $action['name'] ?? '';
                $actionParams = $action['params'] ?? [];
                if (empty($actionName)) continue;
                $result = executeAction($pdo, $actionName, $actionParams, $role);
                $actionResults[] = [
                    'action' => $actionName,
                    'success' => $result['success'] ?? false,
                    'error' => $result['error'] ?? null,
                    'data' => $result['data'] ?? null,
                ];
                if ($result['success']) {
                    $successMsg = $action['success_message'] ?? 'Action "' . $actionName . '" completed.';
                    $parsed['message'] .= "\n\n✅ " . $successMsg;
                } else {
                    $parsed['message'] .= "\n\n⚠️ " . ($result['error'] ?? 'Action failed.');
                }
            }
            $parsed['action_results'] = $actionResults;
        }

        return [$parsed, $rawResponse];
    }
}

if (!function_exists('performInvestigation')) {
    function performInvestigation($pdo, $user_message, $intent, $entities, $role, $language_mode, $historyMessages, $round = 1, $previousResults = []) {
        $investigationPrompt = buildInvestigationPrompt($pdo, $user_message, $intent, $entities, $round, $previousResults);

        $systemPrompt = "You are an investigation assistant for Prem Gas Solution database. Your job is to find data even when direct records are missing. Generate SQL queries to search related tables.";
        $systemPrompt .= "\n\nOnce validated, return your findings. If the user wants to perform an action (add cylinder, create order, update customer, etc.), Phase 2 will execute it. Do NOT say you cannot perform writes.";
        $systemPrompt .= "\n\nInvestigate by checking:\n";
        $systemPrompt .= "- cylinder_transactions for any matching cylinder serials in notes or references\n";
        $systemPrompt .= "- activity_logs for any deletion or modification events\n";
        $systemPrompt .= "- All tables that have searchable text columns matching the query\n";
        $systemPrompt .= "- Related tables through foreign key relationships\n";
        $systemPrompt .= "\nReturn ONLY valid JSON: { \"sql_queries\": [{ \"key\": \"...\", \"label\": \"...\", \"sql\": \"SELECT ...\", \"params\": [] }] }";

        $rawResponse = callAI(
            [['role' => 'user', 'content' => $investigationPrompt]],
            $systemPrompt,
            ['timeout' => 30, 'max_tokens' => 2048, 'temperature' => 0.3, 'cache_ttl' => 0]
        );

        $parsed = parseAIJson($rawResponse);
        if ($parsed === null || empty($parsed['sql_queries'])) {
            return null;
        }

        $results = executeQueryPlan($pdo, $parsed['sql_queries'], $role);
        return [
            'results' => $results,
            'queries' => $parsed['sql_queries'],
        ];
    }
}

if (!function_exists('buildInvestigationPrompt')) {
    function buildInvestigationPrompt($pdo, $user_message, $intent, $entities, $round = 1, $previousResults = []) {
        $schema = formatSchemaForPrompt($pdo);
        $lines = [];
        $lines[] = "User query: $user_message";
        $lines[] = "Intent: $intent";
        if (!empty($entities)) {
            $lines[] = "Entities: " . json_encode($entities);
        }
        $lines[] = "Investigation round: $round of " . MAX_INVESTIGATION_ROUNDS;
        $lines[] = "";
        if ($round === 1) {
            $lines[] = "Direct database query returned no results.";
            $lines[] = "Investigate by generating SQL queries against related tables.";
            $lines[] = "Look for indirect references, historical data, deleted records, activity logs.";
        } else {
            $lines[] = "Previous investigation round(s) did not find sufficient data. Try alternative approaches:";
            $lines[] = "- Search with partial or fuzzy matches";
            $lines[] = "- Check different column combinations";
            $lines[] = "- Use LIKE patterns instead of exact matches";
            $lines[] = "- Cross-reference across tables";
        }
        $lines[] = "";
        $lines[] = "SCHEMA:";
        $lines[] = $schema;
        return implode("\n", $lines);
    }
}

if (!function_exists('performAutoDrillDown')) {
    function performAutoDrillDown($pdo, $entities, $existingResults) {
        if (empty($entities)) return [];

        require_once __DIR__ . '/entity/entity-registry.php';
        $drillDowns = [];

        foreach ($entities as $entity) {
            $type = $entity['type'] ?? '';
            $value = $entity['value'] ?? '';

            if (empty($type) || empty($value)) continue;

            $queries = getDrillDownQueries($pdo, $type, $value);
            if (empty($queries)) continue;

            foreach ($queries as $q) {
                $key = $q['key'];
                if (isset($existingResults[$key]) && $existingResults[$key]['success'] && $existingResults[$key]['count'] > 0) {
                    continue;
                }
                try {
                    $stmt = $pdo->prepare($q['sql']);
                    $stmt->execute($q['params']);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $drillDowns[$key] = [
                        'success' => true,
                        'label' => $q['label'],
                        'data' => $rows,
                        'count' => count($rows),
                    ];
                } catch (PDOException $e) {
                    error_log("drill-down error for {$q['key']}: " . $e->getMessage());
                }
            }
        }

        return $drillDowns;
    }
}

if (!function_exists('getLanguageMode')) {
    function getLanguageMode($pdo, $override = '') {
        if (!empty($override) && in_array($override, ['hinglish', 'english', 'hindi'])) {
            return $override;
        }
        try {
            $config = getAIConfig($pdo);
            return $config['language_mode'] ?? 'hinglish';
        } catch (Throwable $e) {
            return 'hinglish';
        }
    }
}

if (!function_exists('saveMemoryUpdates')) {
    function saveMemoryUpdates($pdo, $user_id, $role, $updates) {
        if (empty($updates) || !is_array($updates)) return;
        foreach ($updates as $update) {
            if (isset($update['key']) && isset($update['value'])) {
                $confidence = $update['confidence'] ?? 0.3;
                saveUserMemory($pdo, $user_id, $update['key'], $update['value'], $confidence);
            }
        }
    }
}

if (!function_exists('processUserMessageStream')) {
    function processUserMessageStream($pdo, $user_message, $user_id, $role, $session_id, $language_override = '', $token_callback = null) {
        $start_time = microtime(true);
        $message = '';
        $intent = 'general';
        $confidence_score = 0.5;
        $is_question = false;
        $confidence_level = 'insufficient_data';
        $queryResults = null;
        $queryContext = [];
        $visualBlocks = [];
        $options = [];

        try {
            $language_mode = getLanguageMode($pdo, $language_override);

            $greetings = ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'namaste', 'namaskar', 'hii', 'heyy', 'hlo', 'helo'];
            $isGreeting = in_array(strtolower(trim($user_message)), $greetings) || preg_match('/^(hi|hello|hey|good\s+(morning|afternoon|evening)|namaste|hii|heyy|hlo)\b/i', trim($user_message));
            if ($isGreeting) {
                $greetMsg = $language_mode === 'english' ? 'Hello! How can I help you with Prem Gas Solution today?' : 'Namaste! Aap Prem Gas Solution ke baare mein kya jaanna chaahenge?';
                $elapsed_ms = (int)((microtime(true) - $start_time) * 1000);
                saveConversation($pdo, $session_id, $user_id, 'user', $user_message, 'general', 0.5, null, null);
                $assistant_id = saveConversation($pdo, $session_id, $user_id, 'assistant', $greetMsg, 'general', 0.5, $elapsed_ms, null);
                return ['message' => $greetMsg, 'intent' => 'general', 'agent' => 'general', 'confidence' => 0.5, 'data' => null, 'response_time_ms' => $elapsed_ms, 'conversation_id' => $assistant_id, 'is_question' => false, 'confidence_level' => 'verified'];
            }

            $pending_state = getPendingState($pdo, $session_id);
            $current_follow_up = $pending_state ? ($pending_state['follow_up_count'] ?? 0) : 0;

            $historyMessages = getConversationMessages($pdo, $session_id, 15);

            list($phase1Result, $phase1Raw) = callPhase1($pdo, $user_message, $role, $session_id, $language_mode, $historyMessages, $pending_state);

            $intent = $phase1Result['intent'] ?? 'general';
            $entities = $phase1Result['entities'] ?? [];

            if (!empty($entities)) {
                autoSaveEntityMemory($pdo, $session_id, $user_id, $entities, $intent);
                $primaryEntity = $entities[0] ?? [];
                saveSessionFocus($pdo, $session_id, $user_id, [
                    'type' => $primaryEntity['type'] ?? '',
                    'value' => $primaryEntity['value'] ?? '',
                    'id' => $primaryEntity['id'] ?? '',
                    'intent' => $intent,
                    'entities' => $entities,
                ]);
            }

            if (!empty($phase1Result['needs_follow_up'])) {
                $fallbackQuestion = ($language_mode === 'english' || empty($language_mode)) ? 'Could you please provide more details?' : ($language_mode === 'hindi' ? 'Kya aap aur jankari de sakte hain?' : 'Kya aap aur details bata sakte hain?');
                $message = $phase1Result['follow_up_question'] ?? $fallbackQuestion;
                $is_question = true;
                $confidence_level = 'insufficient_data';
                $new_pending = [
                    'intent' => $intent,
                    'entities' => $entities,
                    'last_question' => $message,
                    'follow_up_count' => $current_follow_up + 1,
                    'phase1_result' => $phase1Result,
                ];
                savePendingState($pdo, $session_id, $user_id, $new_pending);
            } else {
                $sqlQueries = $phase1Result['sql_queries'] ?? [];
                $queryResults = executeQueryPlan($pdo, $sqlQueries, $role);
                $queryContext = [
                    'intent' => $intent,
                    'entities' => $entities,
                    'sql_count' => count($sqlQueries),
                ];

                if (!hasAnyData($queryResults)) {
                    for ($round = 1; $round <= MAX_INVESTIGATION_ROUNDS; $round++) {
                        $investigationResult = performInvestigation($pdo, $user_message, $intent, $entities, $role, $language_mode, $historyMessages, $round, $queryResults);
                        if ($investigationResult && hasAnyData($investigationResult['results'])) {
                            foreach ($investigationResult['results'] as $k => $v) {
                                if (!isset($queryResults[$k])) $queryResults[$k] = $v;
                            }
                            $queryContext['investigated'] = true;
                            $queryContext['investigation_rounds'] = $round;
                            break;
                        }
                    }
                }

                if ($pending_state && !empty($pending_state['query_results'])) {
                    foreach ($pending_state['query_results'] as $k => $v) {
                        if (!isset($queryResults[$k])) $queryResults[$k] = $v;
                    }
                }

                $drillDownResults = performAutoDrillDown($pdo, $entities, $queryResults);
                if (!empty($drillDownResults)) {
                    foreach ($drillDownResults as $k => $v) {
                        if (!isset($queryResults[$k])) {
                            $queryResults[$k] = $v;
                            $queryContext['drill_down'][$k] = true;
                        }
                    }
                }

                list($phase2Result, $phase2Raw) = callPhase2Stream($pdo, $user_message, $intent, $entities, $queryResults, $queryContext, $role, $language_mode, $session_id, $historyMessages, $pending_state, $token_callback);

                $message = $phase2Result['message'] ?? 'Sorry, I could not process your request. Please try again.';
                $is_question = !empty($phase2Result['is_question']);
                $confidence_level = $phase2Result['confidence'] ?? 'insufficient_data';

                if ($pending_state) clearPendingState($pdo, $session_id, $user_id);

                $visualBlocks = $phase2Result['visual_blocks'] ?? [];
                $options = $phase2Result['options'] ?? [];

                if (!empty($phase2Result['memory_updates'])) {
                    saveMemoryUpdates($pdo, $user_id, $role, $phase2Result['memory_updates']);
                }
            }

            updateConversationSummary($pdo, $session_id, $user_id, $user_message, $intent, $entities, $message);
        } catch (Throwable $e) {
            error_log("processUserMessageStream error: " . $e->getMessage());
            $message = ($language_mode === 'english' || empty($language_mode))
                ? "I encountered an AI service error. Please check your provider settings or try again."
                : "AI service mein error aaya hai. Settings check karein ya thodi der baad try karein.";
        }

        $elapsed_ms = (int)((microtime(true) - $start_time) * 1000);
        saveConversation($pdo, $session_id, $user_id, 'user', $user_message, $intent, $confidence_score, null, null);
        $assistant_id = saveConversation($pdo, $session_id, $user_id, 'assistant', $message, $intent, $confidence_score, $elapsed_ms, null);

        return [
            'message' => $message,
            'intent' => $intent,
            'agent' => $intent === 'general' ? 'general' : $intent,
            'confidence' => $confidence_score,
            'data' => null,
            'visual_blocks' => $visualBlocks,
            'options' => $options,
            'response_time_ms' => $elapsed_ms,
            'conversation_id' => $assistant_id,
            'is_question' => $is_question,
            'confidence_level' => $confidence_level,
        ];
    }
}

if (!function_exists('callPhase2Stream')) {
    function callPhase2Stream($pdo, $user_message, $intent, $entities, $results, $context, $role, $language_mode, $session_id, $historyMessages, $pending_state = null, $token_callback = null) {
        $focusContext = formatSessionFocusForPrompt($pdo, $session_id);
        $systemPrompt = buildPhase2SystemPrompt($pdo, $role, $language_mode);
        if (!empty($focusContext)) {
            $systemPrompt .= "\n" . $focusContext . "\n";
        }
        $userPrompt = buildPhase2UserPrompt($pdo, $user_message, $intent, $entities, $results, $context, $pending_state);

        $user_id = $_SESSION['user_id'] ?? 0;
        $memories = getFormattedMemories($pdo, $user_id, $role);

        $messages = [];
        if (!empty($memories)) $messages[] = ['role' => 'system', 'content' => $memories];
        foreach ($historyMessages as $msg) $messages[] = $msg;
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $timeout = 45;

        $fullText = callAIStream($messages, $systemPrompt, [
            'timeout' => $timeout,
            'max_tokens' => 2048,
            'temperature' => 0.5,
        ], $token_callback);

        $parsed = parseAIJson($fullText);
        if ($parsed === null) {
            error_log("callPhase2Stream: failed to parse LLM response as JSON: " . substr($fullText, 0, 500));
            $findings = extractKeyFindings($results);
            if ($language_mode === 'english' || empty($language_mode)) {
                $fallback = "I checked the database for your query. " . (!empty($findings) ? "Here are the results:\n" . implode("\n", $findings) : "No specific records found. Could you provide more details?");
            } elseif ($language_mode === 'hindi') {
                $fallback = "Ji, maine aapki query ke liye data check kiya. " . (!empty($findings) ? "Yeh raha result:\n" . implode("\n", $findings) : "Lekin koi specific record nahi mila. Aap kuch aur jankari de sakte hain?");
            } else {
                $fallback = "Ji, maine aapki query ke liye data check kiya. " . (!empty($findings) ? "Yeh raha result:\n" . implode("\n", $findings) : "Lekin koi specific record nahi mila. Aap kuch aur details bata sakte hain?");
            }
            return [['message' => $fallback, 'confidence' => 'insufficient_data', 'is_question' => false, 'memory_updates' => []], $fullText];
        }

        if (empty($parsed['message'])) {
            $parsed['message'] = ($language_mode === 'english' || empty($language_mode))
                ? "I understand your request. Is there anything else you would like to know?"
                : "Ji, maine aapki baat samajh li. Kya aap kuch aur poochna chahenge?";
        }

        if (!empty($parsed['actions'])) {
            require_once __DIR__ . '/actions/action-executor.php';
            $actionResults = [];
            foreach ($parsed['actions'] as $action) {
                $actionName = $action['name'] ?? '';
                $actionParams = $action['params'] ?? [];
                if (empty($actionName)) continue;
                $result = executeAction($pdo, $actionName, $actionParams, $role);
                $actionResults[] = [
                    'action' => $actionName,
                    'success' => $result['success'] ?? false,
                    'error' => $result['error'] ?? null,
                    'data' => $result['data'] ?? null,
                ];
                if ($result['success']) {
                    $parsed['message'] .= "\n\n✅ " . ($action['success_message'] ?? 'Action "' . $actionName . '" completed.');
                } else {
                    $parsed['message'] .= "\n\n⚠️ " . ($result['error'] ?? 'Action failed.');
                }
            }
            $parsed['action_results'] = $actionResults;
        }

        return [$parsed, $fullText];
    }
}
