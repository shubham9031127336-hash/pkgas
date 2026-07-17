<?php
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ai/security/permission-gate.php';

aiRequireAccess();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

if (ob_get_level()) ob_end_clean();
ob_implicit_flush(true);

function sseSend($event, $data) {
    echo "event: $event\n";
    echo "data: $data\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

$user_message = trim($_GET['message'] ?? '');
$session_id = $_GET['session_id'] ?? session_id();
$language = trim($_GET['language'] ?? '');

if (empty($user_message)) {
    sseSend('error', json_encode(['error' => 'Message is required.']));
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['user_role'] ?? '';

try {
    require_once __DIR__ . '/ai/orchestrator.php';
    require_once __DIR__ . '/ai-helper.php';

    require_once __DIR__ . '/ai/planning/conversation-manager.php';
    require_once __DIR__ . '/ai/planning/intent-classifier.php';
    require_once __DIR__ . '/ai/planning/response-builder.php';
    require_once __DIR__ . '/ai/memory/memory-store.php';
    require_once __DIR__ . '/ai/memory/memory-retriever.php';
    require_once __DIR__ . '/ai/schema/schema-explorer.php';

    $result = processUserMessageStream($pdo, $user_message, $user_id, $role, $session_id, $language);

    sseSend('complete', json_encode([
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
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    error_log("ai-stream error: " . $e->getMessage());
    sseSend('error', json_encode(['error' => $e->getMessage()]));
}
