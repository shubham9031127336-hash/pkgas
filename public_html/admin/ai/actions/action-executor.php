<?php
/**
 * AI Action Executor
 * Validates and executes registered write actions with role-based permissions.
 */

require_once __DIR__ . '/action-registry.php';

if (!function_exists('executeAction')) {
    function executeAction($pdo, $action_name, $params, $role = null) {
        if ($role === null) {
            $role = $_SESSION['user_role'] ?? '';
        }

        $definitions = getActionDefinitions();

        if (!isset($definitions[$action_name])) {
            return ['success' => false, 'error' => "Action '$action_name' is not defined."];
        }

        $action = $definitions[$action_name];

        if (!in_array($role, $action['roles'])) {
            return ['success' => false, 'error' => "Your role ($role) does not have permission to perform '$action_name'."];
        }

        foreach ($action['required_params'] as $rp) {
            if (!isset($params[$rp]) || (is_string($params[$rp]) && trim($params[$rp]) === '')) {
                return ['success' => false, 'error' => "Missing required parameter: $rp"];
            }
        }

        if (!function_exists($action['handler'])) {
            return ['success' => false, 'error' => "Handler function '{$action['handler']}' not found."];
        }

        try {
            return call_user_func($action['handler'], $pdo, $params);
        } catch (Throwable $e) {
            error_log("executeAction: '$action_name' failed: " . $e->getMessage());
            return ['success' => false, 'error' => "Action execution failed: " . $e->getMessage()];
        }
    }
}

if (!function_exists('getActionDescriptionsForRole')) {
    function getActionDescriptionsForRole($role = null) {
        if ($role === null) {
            $role = $_SESSION['user_role'] ?? '';
        }

        $definitions = getActionDefinitions();
        $result = [];

        foreach ($definitions as $name => $config) {
            if (in_array($role, $config['roles'])) {
                $result[$name] = [
                    'description' => $config['description'],
                    'required_params' => $config['required_params'],
                    'optional_params' => $config['optional_params'],
                ];
            }
        }

        return $result;
    }
}
