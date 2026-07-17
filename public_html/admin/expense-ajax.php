<?php
/**
 * Expense Management — AJAX Handlers
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/expense-utils.php';
require_once __DIR__ . '/business_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit();
}

validateCsrfToken();

$action = $_POST['action'] ?? '';

if ($action === 'calc_gst') {
    $amount = floatval($_POST['amount'] ?? 0);
    $rate = floatval($_POST['gst_rate'] ?? 0);
    $gst_type = $_POST['gst_type'] ?? 'exclusive';
    $calc = calculateExpenseGST($amount, $rate, $gst_type);
    echo json_encode(['success' => true, 'data' => $calc]);
    exit();
}

if ($action === 'get_category_details') {
    $cat_id = intval($_POST['category_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT c.*, g.name AS group_name FROM expense_categories c JOIN expense_category_groups g ON c.group_id = g.id WHERE c.id = ?");
    $stmt->execute([$cat_id]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => (bool)$cat, 'data' => $cat]);
    exit();
}

if ($action === 'upload_attachment') {
    if (empty($_FILES['file'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        exit();
    }
    $upload_dir = __DIR__ . '/../uploads/expenses/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    $file = $_FILES['file'];
    $orig = basename($file['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xls', 'xlsx'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $ext]);
        exit();
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large (max 5MB)']);
        exit();
    }
    $new_name = time() . '_' . uniqid() . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $upload_dir . $new_name);
    $expense_id = intval($_POST['expense_id'] ?? 0);
    if ($expense_id > 0) {
        $stmt = $pdo->prepare("INSERT INTO expense_attachments (expense_id, filename, original_filename, file_size, mime_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$expense_id, $new_name, $orig, $file['size'], mime_content_type($upload_dir . $new_name) ?: 'application/octet-stream']);
        $att_id = $pdo->lastInsertId();
    }
    echo json_encode(['success' => true, 'filename' => $new_name, 'original' => $orig, 'size' => $file['size']]);
    exit();
}

if ($action === 'delete_attachment') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT filename, expense_id FROM expense_attachments WHERE id = ?");
    $stmt->execute([$id]);
    $att = $stmt->fetch();
    if ($att) {
        $filepath = __DIR__ . '/../uploads/expenses/' . $att['filename'];
        if (file_exists($filepath)) unlink($filepath);
        $pdo->prepare("DELETE FROM expense_attachments WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Attachment not found']);
    }
    exit();
}

echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
