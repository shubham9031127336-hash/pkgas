<?php

if (!function_exists('getAllowedActions')) {
    function getAllowedActions() {
        return [
            'inventory_stock' => [
                'sql' => "SELECT gt.name AS gas_name, COALESCE(SUM(c.quantity), 0) AS total FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id GROUP BY gt.id",
                'description' => 'Current stock of all gas types',
                'roles' => ['super_admin', 'warehouse_supervisor'],
            ],
            'inventory_by_gas' => [
                'sql' => "SELECT c.*, gt.name AS gas_name FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id WHERE gt.name LIKE ?",
                'description' => 'Cylinders filtered by gas type name',
                'roles' => ['super_admin', 'warehouse_supervisor'],
            ],
            'inventory_low_stock' => [
                'sql' => "SELECT gt.name AS gas_name, SUM(c.quantity) AS total FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id GROUP BY gt.id HAVING total < ?",
                'description' => 'Gas types with stock below threshold',
                'roles' => ['super_admin', 'warehouse_supervisor'],
            ],
            'cylinder_status_count' => [
                'sql' => "SELECT status, COUNT(*) AS count FROM cylinders GROUP BY status",
                'description' => 'Count of cylinders by status',
                'roles' => ['super_admin', 'warehouse_supervisor'],
            ],
            'customer_lookup' => [
                'sql' => "SELECT id, name, mobile, email, address, city, state FROM customers WHERE mobile LIKE ? OR name LIKE ? LIMIT 20",
                'description' => 'Search customers by mobile or name',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'customer_by_id' => [
                'sql' => "SELECT * FROM customers WHERE id = ?",
                'description' => 'Get full customer details by ID',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'customer_cylinder_count' => [
                'sql' => "SELECT COUNT(*) AS cylinders_with_customer FROM cylinders WHERE customer_id = ? AND status = 'with_customer'",
                'description' => 'Count of cylinders currently with a customer',
                'roles' => ['super_admin', 'billing_clerk', 'warehouse_supervisor'],
            ],
            'customer_outstanding' => [
                'sql' => "SELECT COALESCE(SUM(oi.total), 0) AS outstanding FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE o.customer_id = ? AND o.status NOT IN ('paid', 'cancelled')",
                'description' => 'Outstanding balance for a customer',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'sales_today' => [
                'sql' => "SELECT COALESCE(SUM(oi.total), 0) AS total_sales, COUNT(DISTINCT o.id) AS order_count FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE DATE(o.created_at) = CURDATE()",
                'description' => 'Today sales summary',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'sales_weekly' => [
                'sql' => "SELECT DATE(o.created_at) AS day, COALESCE(SUM(oi.total), 0) AS daily_total, COUNT(DISTINCT o.id) AS order_count FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(o.created_at) ORDER BY day",
                'description' => 'Daily sales for the last 7 days',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'sales_monthly' => [
                'sql' => "SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS month, COALESCE(SUM(oi.total), 0) AS monthly_total, COUNT(DISTINCT o.id) AS order_count FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(o.created_at, '%Y-%m') ORDER BY month",
                'description' => 'Monthly sales for the last 12 months',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'top_customers' => [
                'sql' => "SELECT c.id, c.name, c.mobile, COALESCE(SUM(oi.total), 0) AS total_spent, COUNT(DISTINCT o.id) AS order_count FROM customers c JOIN orders o ON c.id = o.customer_id JOIN order_items oi ON o.id = oi.order_id GROUP BY c.id ORDER BY total_spent DESC LIMIT 10",
                'description' => 'Top 10 customers by total spend',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'cylinder_deposit_summary' => [
                'sql' => "SELECT c.name AS customer_name, cy.cylinder_serial, cy.quantity, d.deposit_date, d.status FROM deposits d JOIN customers c ON d.customer_id = c.id JOIN cylinders cy ON d.cylinder_id = cy.id ORDER BY d.deposit_date DESC LIMIT 50",
                'description' => 'Recent cylinder deposit records',
                'roles' => ['super_admin', 'billing_clerk', 'warehouse_supervisor'],
            ],
            'vendor_list' => [
                'sql' => "SELECT id, name, mobile, email, city, status FROM vendors ORDER BY name",
                'description' => 'All vendors list',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'partner_list' => [
                'sql' => "SELECT id, name, mobile, email, city, status FROM partners ORDER BY name",
                'description' => 'All channel partners list',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'recent_orders' => [
                'sql' => "SELECT o.id, c.name AS customer_name, o.total, o.status, o.created_at FROM orders o JOIN customers c ON o.customer_id = c.id ORDER BY o.created_at DESC LIMIT ?",
                'description' => 'Most recent orders',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
            'cylinder_exchange_recent' => [
                'sql' => "SELECT ce.*, c.name AS customer_name FROM cylinder_exchange ce JOIN customers c ON ce.customer_id = c.id ORDER BY ce.created_at DESC LIMIT ?",
                'description' => 'Recent cylinder exchanges',
                'roles' => ['super_admin', 'billing_clerk'],
            ],
        ];
    }
}

if (!function_exists('isActionAllowed')) {
    function isActionAllowed($action_name, $role = null) {
        if ($role === null) {
            $role = $_SESSION['user_role'] ?? '';
        }

        $actions = getAllowedActions();

        if (!isset($actions[$action_name])) {
            return false;
        }

        $allowed_roles = $actions[$action_name]['roles'];

        return in_array($role, $allowed_roles);
    }
}

if (!function_exists('getActionSQL')) {
    function getActionSQL($action_name) {
        $actions = getAllowedActions();
        return isset($actions[$action_name]) ? $actions[$action_name]['sql'] : null;
    }
}

if (!function_exists('executeAllowedQuery')) {
    function executeAllowedQuery($pdo, $action_name, $params = []) {
        $sql = getActionSQL($action_name);
        if ($sql === null) {
            return ['success' => false, 'error' => "Action '$action_name' is not allowed."];
        }

        if (!isActionAllowed($action_name)) {
            return ['success' => false, 'error' => "Your role does not have permission for action '$action_name'."];
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return ['success' => true, 'data' => $rows, 'count' => count($rows)];
        } catch (PDOException $e) {
            error_log("executeAllowedQuery failed for action '$action_name': " . $e->getMessage());
            return ['success' => false, 'error' => 'Database query failed.'];
        }
    }
}

if (!function_exists('getAllowedActionDescriptions')) {
    function getAllowedActionDescriptions($role = null) {
        if ($role === null) {
            $role = $_SESSION['user_role'] ?? '';
        }

        $actions = getAllowedActions();
        $result = [];

        foreach ($actions as $name => $config) {
            if (in_array($role, $config['roles'])) {
                $result[$name] = $config['description'];
            }
        }

        return $result;
    }
}
