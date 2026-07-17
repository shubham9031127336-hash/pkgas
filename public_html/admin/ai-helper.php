<?php
/**
 * AI Helper - Central LLM Abstraction Layer
 * Routes calls to OpenRouter (default) with fallback support.
 * Never throws, always returns a string.
 */

if (!function_exists('callAI')) {
    function callAI($messages, $system_prompt = '', $options = array()) {
        if (is_string($messages)) {
            $messages = array(array('role' => 'user', 'content' => $messages));
        }

        try {
            require_once __DIR__ . '/db.php';
            require_once __DIR__ . '/ai/ai-config.php';

            global $pdo;
            $config = getAIConfig($pdo);

            $provider = $config['provider'] ?? 'openrouter';
            $model = $options['model'] ?? $config['model'] ?? 'openai/gpt-4o-mini';
            $max_tokens = intval($options['max_tokens'] ?? $config['max_tokens'] ?? 2048);
            $temperature = floatval($options['temperature'] ?? $config['temperature'] ?? 0.70);
            $timeout = intval($options['timeout'] ?? 30);

            $providers_without_key = array('ollama');
            if (empty($config['api_key']) && !in_array($provider, $providers_without_key)) {
                return isset($options['fallback']) ? $options['fallback'] : 'AI is not configured. Please add an API key in Settings.';
            }

            $base_url = $config['base_url'] ?? '';

            switch ($provider) {
                case 'openrouter':
                    $response = callOpenRouter($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout);
                    break;
                case 'openai':
                    $response = callOpenAI($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout);
                    break;
                case 'gemini':
                    $response = callGemini($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout);
                    break;
                case 'groq':
                    $response = callGroq($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout);
                    break;
                case 'ollama':
                    $response = callOpenAI($config['api_key'] ?: '', $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $base_url ?: 'http://localhost:11434/v1');
                    break;
                case 'custom':
                    $response = callOpenAI($config['api_key'] ?: '', $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $base_url ?: '');
                    break;
                default:
                    $response = callOpenRouter($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout);
            }

            return $response;

        } catch (Throwable $e) {
            error_log("callAI failed: " . $e->getMessage());
            file_put_contents(__DIR__ . '/ai_debug_error.log', date('Y-m-d H:i:s') . " callAI EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n===END===\n\n", FILE_APPEND | LOCK_EX);
            throw $e;
        }
    }
}

if (!function_exists('callOpenRouter')) {
    function callOpenRouter($api_key, $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout) {
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $payload = array(
            'model' => $model,
            'messages' => array(),
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
        );

        if (!empty($system_prompt)) {
            $payload['messages'][] = array('role' => 'system', 'content' => $system_prompt);
        }

        foreach ($messages as $msg) {
            $payload['messages'][] = array(
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            );
        }

        $json = json_encode($payload);
        if ($json === false) {
            throw new Exception('Failed to encode request payload');
        }

        $http = array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n"
                      . "Authorization: Bearer $api_key\r\n"
                      . "HTTP-Referer: https://pkgas.com\r\n"
                      . "X-Title: Prem Gas Solution AI\r\n",
            'content' => $json,
            'timeout' => $timeout,
        );

        $context = stream_context_create(array('http' => $http));
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $last_error = error_get_last();
            $err_msg = $last_error['message'] ?? 'Unknown error';

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json',
                        "Authorization: Bearer $api_key",
                        'HTTP-Referer: https://pkgas.com',
                        'X-Title: Prem Gas Solution AI',
                    ),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                ));
                $result = curl_exec($ch);
                $curl_errno = curl_errno($ch);
                if ($curl_errno !== 0) {
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    throw new Exception("OpenRouter request failed (stream: $err_msg, curl error $curl_errno: $curl_error)");
                }
                curl_close($ch);
            } else {
                throw new Exception("OpenRouter request failed (stream error: $err_msg)");
            }
        }

        $decoded = json_decode($result, true);
        if ($decoded === null) {
            throw new Exception('OpenRouter returned invalid JSON');
        }

        if (isset($decoded['error'])) {
            $err_msg = $decoded['error']['message'] ?? json_encode($decoded['error']);
            throw new Exception("OpenRouter error: $err_msg");
        }

        $text = $decoded['choices'][0]['message']['content'] ?? '';
        if (empty($text)) {
            throw new Exception('OpenRouter returned empty response');
        }

        return trim($text);
    }
}

if (!function_exists('callOpenRouterStream')) {
    function callOpenRouterStream($api_key, $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $callback) {
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $payload = array(
            'model' => $model,
            'messages' => array(),
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            'stream' => true,
        );
        if (!empty($system_prompt)) {
            $payload['messages'][] = array('role' => 'system', 'content' => $system_prompt);
        }
        foreach ($messages as $msg) {
            $payload['messages'][] = array('role' => $msg['role'] ?? 'user', 'content' => $msg['content'] ?? '');
        }
        $json = json_encode($payload);
        if ($json === false) {
            throw new Exception('Failed to encode stream request payload');
        }
        $full_text = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                "Authorization: Bearer $api_key",
                'HTTP-Referer: https://pkgas.com',
                'X-Title: Prem Gas Solution AI',
            ),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ));

        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$full_text, &$buffer, $callback) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);
                if (empty($line)) continue;
                if ($line === 'data: [DONE]') return strlen($data);
                if (strpos($line, 'data: ') === 0) {
                    $json_str = substr($line, 6);
                    $chunk = json_decode($json_str, true);
                    if ($chunk && isset($chunk['choices'][0]['delta']['content'])) {
                        $content = $chunk['choices'][0]['delta']['content'];
                        $full_text .= $content;
                        if ($callback) {
                            call_user_func($callback, $content);
                        }
                    }
                }
            }
            return strlen($data);
        });

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception("OpenRouter stream request failed (curl error $errno: " . curl_error($ch) . ")");
        }
        return $full_text;
    }
}

if (!function_exists('callAIStream')) {
    function callAIStream($messages, $system_prompt = '', $options = array(), $callback = null) {
        try {
            require_once __DIR__ . '/db.php';
            require_once __DIR__ . '/ai/ai-config.php';
            global $pdo;
            $config = getAIConfig($pdo);
            $provider = $config['provider'] ?? 'openrouter';
            $model = $options['model'] ?? $config['model'] ?? 'openai/gpt-4o-mini';
            $max_tokens = intval($options['max_tokens'] ?? $config['max_tokens'] ?? 2048);
            $temperature = floatval($options['temperature'] ?? $config['temperature'] ?? 0.70);
            $timeout = intval($options['timeout'] ?? 45);

            if (is_string($messages)) {
                $messages = array(array('role' => 'user', 'content' => $messages));
            }

            if ($provider === 'openrouter') {
                return callOpenRouterStream($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $callback);
            }

            if ($provider === 'openai') {
                $base_url = $config['base_url'] ?? '';
                return callOpenAIStream($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $base_url, $callback);
            }

            if ($provider === 'groq') {
                return callGroqStream($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $callback);
            }

            return callOpenRouterStream($config['api_key'], $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $callback);
        } catch (Throwable $e) {
            error_log("callAIStream failed: " . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('callOpenAIStream')) {
    function callOpenAIStream($api_key, $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $base_url, $callback) {
        $url = $base_url ? rtrim($base_url, '/') . '/chat/completions' : 'https://api.openai.com/v1/chat/completions';
        $payload = array(
            'model' => $model ?: 'gpt-4o-mini',
            'messages' => array(),
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            'stream' => true,
        );
        if (!empty($system_prompt)) {
            $payload['messages'][] = array('role' => 'system', 'content' => $system_prompt);
        }
        foreach ($messages as $msg) {
            $payload['messages'][] = array('role' => $msg['role'] ?? 'user', 'content' => $msg['content'] ?? '');
        }
        $json = json_encode($payload);
        $headers = array('Content-Type: application/json');
        if (!empty($api_key)) {
            $headers[] = "Authorization: Bearer $api_key";
        }
        $full_text = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$full_text, &$buffer, $callback) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);
                if (empty($line)) continue;
                if ($line === 'data: [DONE]') return strlen($data);
                if (strpos($line, 'data: ') === 0) {
                    $json_str = substr($line, 6);
                    $chunk = json_decode($json_str, true);
                    if ($chunk && isset($chunk['choices'][0]['delta']['content'])) {
                        $content = $chunk['choices'][0]['delta']['content'];
                        $full_text .= $content;
                        if ($callback) call_user_func($callback, $content);
                    }
                }
            }
            return strlen($data);
        });
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) throw new Exception("Stream request failed (curl error $errno)");
        return $full_text;
    }
}

if (!function_exists('callGroqStream')) {
    function callGroqStream($api_key, $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $callback) {
        return callOpenAIStream($api_key, $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, 'https://api.groq.com/openai/v1', $callback);
    }
}

if (!function_exists('callOpenAI')) {
    function callOpenAI($api_key, $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout, $base_url = '') {
        $url = $base_url ? rtrim($base_url, '/') . '/chat/completions' : 'https://api.openai.com/v1/chat/completions';

        $payload = array(
            'model' => $model ?: 'gpt-4o-mini',
            'messages' => array(),
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
        );

        if (!empty($system_prompt)) {
            $payload['messages'][] = array('role' => 'system', 'content' => $system_prompt);
        }

        foreach ($messages as $msg) {
            $payload['messages'][] = array(
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            );
        }

        $json = json_encode($payload);
        $headers = "Content-Type: application/json\r\n";
        if (!empty($api_key)) {
            $headers .= "Authorization: Bearer $api_key\r\n";
        }
        $http = array(
            'method' => 'POST',
            'header' => $headers,
            'content' => $json,
            'timeout' => $timeout,
        );

        $context = stream_context_create(array('http' => $http));
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $last_error = error_get_last();
            $err_msg = $last_error['message'] ?? 'Unknown error';

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                $curl_headers = array('Content-Type: application/json');
                if (!empty($api_key)) {
                    $curl_headers[] = "Authorization: Bearer $api_key";
                }
                curl_setopt_array($ch, array(
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => $curl_headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                ));
                $result = curl_exec($ch);
                $curl_errno = curl_errno($ch);
                if ($curl_errno !== 0) {
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    throw new Exception("OpenAI/Ollama request failed (stream: $err_msg, curl error $curl_errno: $curl_error)");
                }
                curl_close($ch);
            } else {
                throw new Exception("OpenAI/Ollama request failed (stream error: $err_msg)");
            }
        }

        $decoded = json_decode($result, true);
        if (isset($decoded['error'])) {
            throw new Exception("API error: " . ($decoded['error']['message'] ?? 'unknown'));
        }

        return trim($decoded['choices'][0]['message']['content'] ?? '');
    }
}

if (!function_exists('callGemini')) {
    function callGemini($api_key, $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout) {
        $model = $model ?: 'gemini-2.0-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$api_key";

        $contents = array();
        foreach ($messages as $msg) {
            $contents[] = array(
                'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => array(array('text' => $msg['content'] ?? '')),
            );
        }

        $payload = array(
            'contents' => $contents,
            'generationConfig' => array(
                'maxOutputTokens' => $max_tokens,
                'temperature' => $temperature,
            ),
        );

        if (!empty($system_prompt)) {
            $payload['systemInstruction'] = array('parts' => array(array('text' => $system_prompt)));
        }

        $json = json_encode($payload);
        $http = array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $json,
            'timeout' => $timeout,
        );

        $context = stream_context_create(array('http' => $http));
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $last_error = error_get_last();
            $err_msg = $last_error['message'] ?? 'Unknown error';

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                $headers = array(
                    'Content-Type: application/json',
                );
                curl_setopt_array($ch, array(
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                ));
                $result = curl_exec($ch);
                $curl_errno = curl_errno($ch);
                if ($curl_errno !== 0) {
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    throw new Exception("Gemini request failed (stream: $err_msg, curl error $curl_errno: $curl_error)");
                }
                curl_close($ch);
            } else {
                throw new Exception("Gemini request failed (stream error: $err_msg)");
            }
        }

        $decoded = json_decode($result, true);
        if ($decoded === null) {
            throw new Exception('Gemini returned invalid JSON');
        }

        if (isset($decoded['error'])) {
            throw new Exception("Gemini error: " . ($decoded['error']['message'] ?? json_encode($decoded['error'])));
        }

        return trim($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
}

if (!function_exists('callGroq')) {
    function callGroq($api_key, $model, $messages, $system_prompt, $max_tokens, $temperature, $timeout) {
        $url = 'https://api.groq.com/openai/v1/chat/completions';

        $payload = array(
            'model' => $model ?: 'llama-3.3-70b-versatile',
            'messages' => array(),
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
        );

        if (!empty($system_prompt)) {
            $payload['messages'][] = array('role' => 'system', 'content' => $system_prompt);
        }

        foreach ($messages as $msg) {
            $payload['messages'][] = array(
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            );
        }

        $json = json_encode($payload);
        $http = array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n"
                      . "Authorization: Bearer $api_key\r\n",
            'content' => $json,
            'timeout' => $timeout,
        );

        $context = stream_context_create(array('http' => $http));
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $last_error = error_get_last();
            $err_msg = $last_error['message'] ?? 'Unknown error';

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                $headers = array(
                    'Content-Type: application/json',
                    "Authorization: Bearer $api_key",
                );
                curl_setopt_array($ch, array(
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                ));
                $result = curl_exec($ch);
                $curl_errno = curl_errno($ch);
                if ($curl_errno !== 0) {
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    throw new Exception("Groq request failed (stream: $err_msg, curl error $curl_errno: $curl_error)");
                }
                curl_close($ch);
            } else {
                throw new Exception("Groq request failed (stream error: $err_msg)");
            }
        }

        $decoded = json_decode($result, true);
        if ($decoded === null) {
            throw new Exception('Groq returned invalid JSON');
        }

        if (isset($decoded['error'])) {
            throw new Exception("Groq error: " . ($decoded['error']['message'] ?? json_encode($decoded['error'])));
        }

        return trim($decoded['choices'][0]['message']['content'] ?? '');
    }
}

if (!function_exists('parseAIJson')) {
    function parseAIJson($text) {
        $parsed = json_decode($text, true);
        if ($parsed !== null) {
            return $parsed;
        }

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $parsed = json_decode($m[1], true);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $candidate = $m[0];
            $depth = 0;
            $start = null;
            for ($i = 0; $i < strlen($candidate); $i++) {
                if ($candidate[$i] === '{') {
                    if ($depth === 0) $start = $i;
                    $depth++;
                } elseif ($candidate[$i] === '}') {
                    $depth--;
                    if ($depth === 0 && $start !== null) {
                        $sub = substr($candidate, $start, $i - $start + 1);
                        $parsed = json_decode($sub, true);
                        if ($parsed !== null) {
                            return $parsed;
                        }
                        $start = null;
                    }
                }
            }
        }

        return null;
    }
}

// Handle test connection requests (from settings.php AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['action']) && $input['action'] === 'test') {
        header('Content-Type: application/json');
        try {
            $test_key = $input['api_key'] ?? '';
            $test_model = $input['model'] ?? 'openai/gpt-4o-mini';
            $test_provider = $input['provider'] ?? 'openrouter';
            $test_base_url = $input['base_url'] ?? '';

            switch ($test_provider) {
                case 'openai':
                    $result = callOpenAI($test_key, $test_model, array(array('role' => 'user', 'content' => 'Reply with only: OK')), '', 64, 0.5, 15);
                    break;
                case 'gemini':
                    $result = callGemini($test_key, $test_model, array(array('role' => 'user', 'content' => 'Reply with only: OK')), '', 64, 0.5, 15);
                    break;
                case 'groq':
                    $result = callGroq($test_key, $test_model, array(array('role' => 'user', 'content' => 'Reply with only: OK')), '', 64, 0.5, 15);
                    break;
                case 'ollama':
                    $result = callOpenAI($test_key, $test_model, array(array('role' => 'user', 'content' => 'Reply with only: OK')), '', 64, 0.5, 15, $test_base_url ?: 'http://localhost:11434/v1');
                    break;
                case 'custom':
                    $result = callOpenAI($test_key, $test_model, array(array('role' => 'user', 'content' => 'Reply with only: OK')), '', 64, 0.5, 15, $test_base_url ?: '');
                    break;
                default:
                    $result = callOpenRouter($test_key, $test_model, array(array('role' => 'user', 'content' => 'Reply with only: OK')), '', 64, 0.5, 15);
            }
            echo json_encode(array('success' => true, 'response' => $result));
        } catch (Throwable $e) {
            echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        }
        exit;
    }
}
