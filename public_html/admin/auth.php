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

// Check if user is authenticated
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Gatekeeper for basic authentication
function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit();
    }
}

// Check if the current user possesses one of the allowed roles
function has_role($allowed_roles) {
    if (!is_logged_in()) {
        return false;
    }
    
    $user_role = $_SESSION['user_role'] ?? '';
    
    if (is_array($allowed_roles)) {
        return in_array($user_role, $allowed_roles);
    }
    
    return $user_role === $allowed_roles;
}

// Gatekeeper for targeted roles
function require_role($allowed_roles) {
    require_login();
    
    if (!has_role($allowed_roles)) {
        $_SESSION['error_flash'] = __('auth.access_denied');
        header("Location: dashboard.php");
        exit();
    }
}
?>
