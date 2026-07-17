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
$conversation_id = intval($input['conversation_id'] ?? 0);
$rating = intval($input['rating'] ?? 0);
$feedback_text = trim($input['feedback_text'] ?? '');
$user_id = $_SESSION['user_id'] ?? 0;

if ($conversation_id <= 0 || $rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid conversation_id or rating.']);
    exit;
}

require_once __DIR__ . '/ai/memory/memory-store.php';

$ok = saveFeedback($pdo, $conversation_id, $user_id, $rating, $feedback_text ?: null);

echo json_encode(['success' => $ok]);
