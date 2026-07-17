<?php

if (!function_exists('aiUserAllowed')) {
    function aiUserAllowed($role = null) {
        if ($role === null) {
            $role = $_SESSION['user_role'] ?? '';
        }

        $allowed_roles = ['super_admin', 'billing_clerk', 'warehouse_supervisor'];

        return in_array($role, $allowed_roles);
    }
}

if (!function_exists('aiRateLimitCheck')) {
    function aiRateLimitCheck($max_calls = 30, $window_seconds = 60) {
        $user_id = $_SESSION['user_id'] ?? 0;
        if ($user_id === 0) return false;

        $key = "ai_rate_limit_$user_id";
        $now = time();

        $history = isset($_SESSION[$key]) ? $_SESSION[$key] : [];

        $history = array_filter($history, function($ts) use ($now, $window_seconds) {
            return ($now - $ts) < $window_seconds;
        });

        if (count($history) >= $max_calls) {
            return false;
        }

        $history[] = $now;
        $_SESSION[$key] = array_values($history);

        return true;
    }
}

if (!function_exists('aiRateLimitRemaining')) {
    function aiRateLimitRemaining($max_calls = 30, $window_seconds = 60) {
        $user_id = $_SESSION['user_id'] ?? 0;
        if ($user_id === 0) return 0;

        $key = "ai_rate_limit_$user_id";
        $now = time();

        $history = isset($_SESSION[$key]) ? $_SESSION[$key] : [];
        $history = array_filter($history, function($ts) use ($now, $window_seconds) {
            return ($now - $ts) < $window_seconds;
        });

        return max(0, $max_calls - count($history));
    }
}

if (!function_exists('aiRequireAccess')) {
    function aiRequireAccess() {
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Authentication required.']);
            exit;
        }

        if (!aiUserAllowed()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Your role does not have permission to use AI features.']);
            exit;
        }

        if (!aiRateLimitCheck()) {
            header('Content-Type: application/json');
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Please wait before sending another request.']);
            exit;
        }
    }
}
