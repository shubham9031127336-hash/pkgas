<?php
$lang_cookie = $_COOKIE['admin_lang'] ?? 'en';
$lang = in_array($lang_cookie, ['en', 'hi']) ? $lang_cookie : 'en';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'hi'])) {
    $lang = $_GET['lang'];
    setcookie('admin_lang', $lang, time() + 86400 * 365, '/');
    $redirect_url = strtok($_SERVER['REQUEST_URI'], '?');
    $params = $_GET;
    unset($params['lang']);
    if (!empty($params)) {
        $redirect_url .= '?' . http_build_query($params);
    }
    header("Location: $redirect_url");
    exit();
}

$translations = require_once __DIR__ . '/lang/' . $lang . '.php';

function __($key, $fallback = null) {
    global $translations;
    return $translations[$key] ?? $fallback ?? $key;
}
