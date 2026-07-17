<?php
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ai/security/permission-gate.php';

aiRequireAccess();

$session_id = $_GET['session_id'] ?? '';
$format = $_GET['format'] ?? 'csv';

if (empty($session_id)) {
    die('Missing session_id parameter.');
}

$user_id = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("SELECT id, role, message, intent, confidence, response_time_ms, created_at FROM ai_conversations WHERE session_id = ? AND user_id = ? ORDER BY created_at ASC");
$stmt->execute([$session_id, $user_id]);
$messages = $stmt->fetchAll();

if (empty($messages)) {
    http_response_code(404);
    die('No conversation found for this session.');
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ai_conversation_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Role', 'Message', 'Intent', 'Confidence', 'Response Time (ms)', 'Timestamp']);

    foreach ($messages as $row) {
        fputcsv($out, [
            $row['role'],
            $row['message'],
            $row['intent'] ?? '',
            $row['confidence'] !== null ? number_format($row['confidence'] * 100) . '%' : '',
            $row['response_time_ms'] ?? '',
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// PDF — render as styled printable HTML
$username = $_SESSION['username'] ?? 'User';
$now = date('d M Y, h:i A');
$total_messages = count($messages);
$user_msgs = 0; $assistant_msgs = 0;
foreach ($messages as $m) {
    if ($m['role'] === 'user') $user_msgs++;
    else $assistant_msgs++;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Conversation Export — Prem Gas Solution</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif; background: #f8fafc; color: #1e293b; padding: 2rem; }
    .export-header { max-width: 800px; margin: 0 auto 2rem; text-align: center; }
    .export-header h1 { font-size: 1.6rem; font-weight: 800; letter-spacing: -0.03em; color: #1e293b; margin-bottom: 0.35rem; }
    .export-header .company { font-size: 0.78rem; color: #64748b; }
    .export-header .meta { display: flex; justify-content: center; gap: 2rem; margin-top: 0.75rem; font-size: 0.82rem; color: #64748b; }
    .export-header .meta strong { color: #1e293b; }
    .conversation { max-width: 800px; margin: 0 auto; }
    .message { margin-bottom: 1.25rem; display: flex; }
    .message.user { justify-content: flex-end; }
    .message.assistant { justify-content: flex-start; }
    .msg-bubble { max-width: 75%; padding: 12px 18px; border-radius: 14px; line-height: 1.6; font-size: 14px; word-wrap: break-word; }
    .message.user .msg-bubble { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: #fff; border-bottom-right-radius: 4px; }
    .message.assistant .msg-bubble { background: #fff; color: #1e293b; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; }
    .msg-meta { font-size: 11px; color: #94a3b8; margin-top: 4px; padding: 0 4px; }
    .message.user .msg-meta { text-align: right; }
    .msg-separator { text-align: center; color: #cbd5e1; font-size: 11px; margin: 1.5rem 0; position: relative; }
    .msg-separator::after { content: ''; position: absolute; left: 0; right: 0; top: 50%; height: 1px; background: #e2e8f0; z-index: 0; }
    .msg-separator span { background: #f8fafc; padding: 0 12px; position: relative; z-index: 1; }
    .msg-intent { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6366f1; background: rgba(99,102,241,0.08); padding: 1px 8px; border-radius: 4px; }
    .export-footer { max-width: 800px; margin: 2rem auto 0; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; text-align: center; font-size: 0.72rem; color: #94a3b8; }
    .no-print { text-align: center; margin-bottom: 1.5rem; }
    .no-print button { padding: 10px 24px; background: #6366f1; color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .no-print button:hover { background: #4f46e5; }
    @media print {
        body { background: #fff; padding: 0; }
        .no-print { display: none !important; }
        .message.assistant .msg-bubble { border-color: #cbd5e1; }
        @page { margin: 12mm; }
    }
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()"><?= __('common.print', 'Print / Save as PDF') ?></button>
</div>

<div class="export-header">
    <h1>AI Conversation Export</h1>
    <div class="company">Prem Gas Solution — Business Intelligence Assistant</div>
    <div class="meta">
        <span><strong>User:</strong> <?= htmlspecialchars($username) ?></span>
        <span><strong>Messages:</strong> <?= $total_messages ?> (<?= $user_msgs ?> user, <?= $assistant_msgs ?> assistant)</span>
        <span><strong>Exported:</strong> <?= $now ?></span>
    </div>
</div>

<div class="conversation">
<?php foreach ($messages as $i => $m):
    $role_class = $m['role'] === 'user' ? 'user' : 'assistant';
    $time = date('d M Y, h:i A', strtotime($m['created_at']));
    $display_role = $m['role'] === 'user' ? 'You' : 'AI Assistant';
?>
    <?php if ($i > 0): ?>
    <div class="msg-separator"><span><?= $time ?></span></div>
    <?php endif; ?>
    <div class="message <?= $role_class ?>">
        <div class="msg-bubble">
            <?php if ($m['role'] === 'assistant' && !empty($m['intent'])): ?>
            <div class="msg-intent"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $m['intent']))) ?></div>
            <?php endif; ?>
            <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
            <div class="msg-meta">
                <?= $display_role ?>
                <?php if ($m['confidence'] !== null): ?>
                · <?= number_format($m['confidence'] * 100) ?>% confidence
                <?php endif; ?>
                <?php if ($m['response_time_ms']): ?>
                · <?= $m['response_time_ms'] ?>ms
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="export-footer">
    Generated by Prem Gas Solution Management System — <?= $now ?>
</div>

</body>
</html>
