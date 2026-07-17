<?php
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}
if (!function_exists('csrfField')) {
    function csrfField() {
        $token = generateCsrfToken();
        echo '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }
}
if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }
        $token = $_POST['_csrf_token'] ?? '';
        $expected = $_SESSION['_csrf_token'] ?? '';
        if (empty($expected) || !hash_equals($expected, $token)) {
            error_log("CSRF validation failed for " . ($_SERVER['SCRIPT_NAME'] ?? 'unknown') . " from IP " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            http_response_code(403);
            $_SESSION['error_flash'] = 'Invalid or expired form token. Please try again.';
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
            exit;
        }
        return true;
    }
}
