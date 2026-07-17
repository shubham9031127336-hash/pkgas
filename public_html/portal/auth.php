<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    if (!empty($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// Session timeout (30 minutes inactivity)
$session_timeout = 1800;
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $session_timeout)) {
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION['error_flash'] = 'Session expired. Please login again.';
    header("Location: login.php");
    exit();
}
if (isset($_SESSION['customer_id'])) {
    $_SESSION['login_time'] = time();
}

// Auto-login via "Remember Me" cookie
if (!isset($_SESSION['customer_id']) && isset($_COOKIE['customer_remember'])) {
    $parts = explode(':', $_COOKIE['customer_remember']);
    if (count($parts) === 2) {
        $cid = intval($parts[0]);
        $token = $parts[1];
        require_once __DIR__ . '/../admin/db.php';
        try {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND login_enabled = 1 AND status = 'active' AND remember_expires > NOW()");
            $stmt->execute([$cid]);
            $customer = $stmt->fetch();
            if ($customer && !empty($customer['remember_token']) && password_verify($token, $customer['remember_token'])) {
                session_regenerate_id(true);
                $_SESSION['customer_id'] = $customer['id'];
                $_SESSION['customer_name'] = $customer['name'];
                $_SESSION['customer_email'] = $customer['email'];
                $_SESSION['login_time'] = time();
            }
        } catch (PDOException $e) { error_log("Portal auto-login failed: " . $e->getMessage()); }
    }
}

function is_customer_logged_in() {
    return isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);
}

function require_customer_login() {
    if (!is_customer_logged_in()) {
        header("Location: login.php");
        exit();
    }
}

function get_customer_id() {
    return $_SESSION['customer_id'] ?? 0;
}

function get_customer_name() {
    return $_SESSION['customer_name'] ?? '';
}

function get_customer_email() {
    return $_SESSION['customer_email'] ?? '';
}
?>
