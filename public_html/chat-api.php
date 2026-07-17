<?php
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_key = 'chat_rate_' . md5($ip);
$rate_limit = 20;
$rate_window = 60;

if (isset($_SESSION[$rate_key])) {
    $window = $_SESSION[$rate_key];
    if (time() - $window['start'] > $rate_window) {
        $_SESSION[$rate_key] = ['start' => time(), 'count' => 1];
    } elseif ($window['count'] >= $rate_limit) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Please wait before sending another message.']);
        exit;
    } else {
        $_SESSION[$rate_key]['count']++;
    }
} else {
    $_SESSION[$rate_key] = ['start' => time(), 'count' => 1];
}

require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/ai/orchestrator.php';

$input = json_decode(file_get_contents('php://input'), true);
$user_message = trim($input['message'] ?? '');
$session_id = $input['session_id'] ?? session_id();
$language = trim($input['language'] ?? '');

if (empty($user_message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message is required.']);
    exit;
}

ob_start();

try {
    $result = processUserMessage($pdo, $user_message, 0, '', $session_id, $language);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'intent' => $result['intent'],
        'response_time_ms' => $result['response_time_ms'],
        'conversation_id' => $result['conversation_id'],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log("chat-api error: " . $e->getMessage());
    @ob_end_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Sorry, I encountered an error. Please try again.',
    ]);
}
