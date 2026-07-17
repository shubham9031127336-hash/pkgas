<?php
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/admin/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$email = trim($_POST['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

try {
    $stmt = $pdo->prepare("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $stmt->execute();

    $check = $pdo->prepare("SELECT id FROM newsletter_subscribers WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You are already subscribed!']);
        exit;
    }

    $insert = $pdo->prepare("INSERT INTO newsletter_subscribers (email) VALUES (?)");
    $insert->execute([$email]);

    echo json_encode(['success' => true, 'message' => 'Thank you for subscribing!']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again later.']);
    error_log("Newsletter subscribe error: " . $e->getMessage());
}
