<?php
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ai/security/permission-gate.php';

aiRequireAccess();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$user_message = trim($input['message'] ?? '');
$session_id = $input['session_id'] ?? session_id();
$language = trim($input['language'] ?? '');

if (empty($user_message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message is required.']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['user_role'] ?? '';

ob_start();

try {
    require_once __DIR__ . '/ai/orchestrator.php';
    $result = processUserMessage($pdo, $user_message, $user_id, $role, $session_id, $language);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'intent' => $result['intent'],
        'agent' => $result['agent'],
        'confidence' => $result['confidence'],
        'data' => $result['data'],
        'visual_blocks' => $result['visual_blocks'] ?? [],
        'options' => $result['options'] ?? [],
        'response_time_ms' => $result['response_time_ms'],
        'conversation_id' => $result['conversation_id'],
        'is_question' => $result['is_question'] ?? false,
        'confidence_level' => $result['confidence_level'] ?? 'insufficient_data',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log("ai-chat-api error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    error_log("ai-chat-api trace: " . $e->getTraceAsString());
    @ob_end_clean();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
    ]);
}
