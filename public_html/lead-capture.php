<?php
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/admin/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$message = trim($_POST['message'] ?? '');
$product = trim($_POST['product'] ?? 'General Enquiry');
$redirect = trim($_POST['redirect_whatsapp'] ?? '');

$errors = [];
if (empty($name)) $errors[] = 'Name is required.';
if (strlen($name) > 100) $errors[] = 'Name is too long.';
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
if (empty($phone)) $errors[] = 'Phone is required.';
if (!preg_match('/^[0-9]{10,15}$/', preg_replace('/[^0-9]/', '', $phone))) $errors[] = 'Invalid phone number.';
if (strlen($message) > 1000) $errors[] = 'Message is too long.';
if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$phone = preg_replace('/[^0-9]/', '', $phone);
$message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
$product = htmlspecialchars($product, ENT_QUOTES, 'UTF-8');

try {
    $stmt = $pdo->prepare("CREATE TABLE IF NOT EXISTS enquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) DEFAULT '',
        phone VARCHAR(20) NOT NULL,
        message TEXT,
        product VARCHAR(255) DEFAULT 'General Enquiry',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $stmt->execute();

    $insert = $pdo->prepare("INSERT INTO enquiries (name, email, phone, message, product) VALUES (?, ?, ?, ?, ?)");
    $insert->execute([$name, $email, $phone, $message, $product]);

    $result = ['success' => true, 'message' => 'Thank you! We will get back to you shortly.'];

    if (!empty($redirect)) {
        $result['redirect'] = $redirect;
    }

    echo json_encode($result);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again later.']);
    error_log("Lead capture error: " . $e->getMessage());
}
