<?php

if (!function_exists('getSalesMetrics')) {
    function getSalesMetrics($pdo, $period = 'today') {
        try {
            switch ($period) {
                case 'today':
                    $where = "DATE(o.created_at) = CURDATE()";
                    break;
                case 'week':
                    $where = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    $where = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                case 'year':
                    $where = "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
                    break;
                default:
                    $where = "DATE(o.created_at) = CURDATE()";
            }

            $sql = "SELECT
                        COUNT(DISTINCT o.id) AS order_count,
                        COALESCE(SUM(o.grand_total), 0) AS total_revenue,
                        COALESCE(SUM(o.subtotal), 0) AS subtotal,
                        COALESCE(SUM(o.tax_amount), 0) AS total_tax,
                        COALESCE(AVG(o.grand_total), 0) AS avg_order_value,
                        COUNT(DISTINCT o.customer_id) AS unique_customers,
                        SUM(oi.qty) AS total_units_sold
                    FROM refill_orders o
                    LEFT JOIN refill_order_items oi ON o.id = oi.refill_order_id
                    WHERE $where AND o.payment_status IN ('paid', 'partial')";

            $stmt = $pdo->query($sql);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("getSalesMetrics failed: " . $e->getMessage());
            return [
                'order_count' => 0, 'total_revenue' => 0, 'subtotal' => 0,
                'total_tax' => 0, 'avg_order_value' => 0, 'unique_customers' => 0, 'total_units_sold' => 0,
            ];
        }
    }
}

if (!function_exists('getCylinderMetrics')) {
    function getCylinderMetrics($pdo) {
        try {
            $stmt = $pdo->query("SELECT
                COUNT(CASE WHEN status NOT IN ('returned_to_partner', 'returned_to_consumer') AND status != 'with_customer' AND NOT (ownership_type = 'consumer_owned' AND current_customer_id IS NOT NULL AND current_customer_id = original_owner_customer_id) THEN 1 END) AS total_cylinders,
                COALESCE(SUM(CASE WHEN status = 'filled' THEN 1 ELSE 0 END), 0) AS filled,
                COALESCE(SUM(CASE WHEN status = 'empty' THEN 1 ELSE 0 END), 0) AS empty,
                COALESCE(SUM(CASE WHEN status = 'with_customer' THEN 1 ELSE 0 END), 0) AS with_customer,
                COALESCE(SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END), 0) AS under_maintenance,
                COALESCE(SUM(CASE WHEN status = 'sent_to_vendor' THEN 1 ELSE 0 END), 0) AS sent_to_vendor,
                COALESCE(SUM(CASE WHEN ownership_type = 'partner_owned' THEN 1 ELSE 0 END), 0) AS partner_owned,
                COALESCE(SUM(CASE WHEN ownership_type = 'consumer_owned' THEN 1 ELSE 0 END), 0) AS consumer_owned
            FROM cylinders");
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("getCylinderMetrics failed: " . $e->getMessage());
            return [
                'total_cylinders' => 0, 'filled' => 0, 'empty' => 0,
                'with_customer' => 0, 'under_maintenance' => 0, 'sent_to_vendor' => 0,
                'partner_owned' => 0, 'consumer_owned' => 0,
            ];
        }
    }
}

if (!function_exists('getInventorySummary')) {
    function getInventorySummary($pdo) {
        try {
            $stmt = $pdo->query("SELECT
                i.*, gt.name AS gas_name
            FROM inventory i
            JOIN gas_types gt ON i.gas_type_id = gt.id
            ORDER BY i.total_stock DESC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getInventorySummary failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getLowStockAlerts')) {
    function getLowStockAlerts($pdo) {
        try {
            $stmt = $pdo->query("SELECT
                i.*, gt.name AS gas_name,
                (i.min_alert_threshold - i.total_stock) AS shortage
            FROM inventory i
            JOIN gas_types gt ON i.gas_type_id = gt.id
            WHERE i.total_stock < i.min_alert_threshold
            ORDER BY shortage DESC");
            $gas_alerts = $stmt->fetchAll();
            $prod_stmt = $pdo->query("SELECT id, name, stock_quantity, min_alert_threshold, (min_alert_threshold - stock_quantity) AS shortage FROM products WHERE stock_quantity < min_alert_threshold ORDER BY shortage DESC");
            $prod_alerts = $prod_stmt->fetchAll();
            $merged = [];
            foreach ($gas_alerts as $a) {
                $merged[] = ['type' => 'gas', 'gas_name' => $a['gas_name'], 'size_capacity' => $a['size_capacity'], 'total_stock' => $a['total_stock'], 'min_alert_threshold' => $a['min_alert_threshold'], 'shortage' => $a['shortage']];
            }
            foreach ($prod_alerts as $a) {
                $merged[] = ['type' => 'product', 'gas_name' => $a['name'], 'size_capacity' => '', 'total_stock' => $a['stock_quantity'], 'min_alert_threshold' => $a['min_alert_threshold'], 'shortage' => $a['shortage']];
            }
            return $merged;
        } catch (PDOException $e) {
            error_log("getLowStockAlerts failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getCustomerMetrics')) {
    function getCustomerMetrics($pdo) {
        try {
            $stmt = $pdo->query("SELECT
                COUNT(*) AS total_customers,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_customers,
                COALESCE(SUM(deposit_balance), 0) AS total_deposit_balance,
                COALESCE(SUM(active_cylinders_count), 0) AS total_active_cylinders_with_customers,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), created_at) <= 30 THEN 1 ELSE 0 END), 0) AS new_customers_30d
            FROM customers");
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("getCustomerMetrics failed: " . $e->getMessage());
            return [
                'total_customers' => 0, 'active_customers' => 0, 'total_deposit_balance' => 0,
                'total_active_cylinders_with_customers' => 0, 'new_customers_30d' => 0,
            ];
        }
    }
}

if (!function_exists('getTopSellingGasTypes')) {
    function getTopSellingGasTypes($pdo, $limit = 5) {
        try {
            $stmt = $pdo->prepare("SELECT
                gt.name AS gas_name,
                COUNT(DISTINCT oi.id) AS times_ordered,
                SUM(oi.qty) AS total_qty_sold,
                COALESCE(SUM(o.grand_total), 0) AS revenue_generated
            FROM refill_order_items oi
            JOIN gas_types gt ON oi.gas_type_id = gt.id
            JOIN refill_orders o ON oi.refill_order_id = o.id
            WHERE o.payment_status IN ('paid', 'partial')
            GROUP BY gt.id, gt.name
            ORDER BY total_qty_sold DESC
            LIMIT " . (int)$limit);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getTopSellingGasTypes failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getPaymentMethodBreakdown')) {
    function getPaymentMethodBreakdown($pdo, $period = 'month') {
        try {
            switch ($period) {
                case 'week':
                    $where = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    $where = "created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                default:
                    $where = "1=1";
            }

            $stmt = $pdo->query("SELECT
                COALESCE(NULLIF(payment_method, ''), 'unknown') AS method,
                COUNT(*) AS count,
                COALESCE(SUM(grand_total), 0) AS total
            FROM refill_orders
            WHERE $where
            GROUP BY method
            ORDER BY total DESC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getPaymentMethodBreakdown failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getPartnerExchangeMetrics')) {
    function getPartnerExchangeMetrics($pdo) {
        try {
            $stmt = $pdo->query("SELECT
                COUNT(*) AS total_transactions,
                COALESCE(SUM(CASE WHEN transaction_type IN ('borrowed_from_partner', 'returned_to_partner') THEN 1 ELSE 0 END), 0) AS inbound,
                COALESCE(SUM(CASE WHEN transaction_type IN ('lent_to_partner', 'received_back_from_partner') THEN 1 ELSE 0 END), 0) AS outbound
            FROM partner_transactions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("getPartnerExchangeMetrics failed: " . $e->getMessage());
            return ['total_transactions' => 0, 'inbound' => 0, 'outbound' => 0];
        }
    }
}

if (!function_exists('getBusinessSnapshot')) {
    function getBusinessSnapshot($pdo) {
        return [
            'sales' => getSalesMetrics($pdo, 'today'),
            'sales_week' => getSalesMetrics($pdo, 'week'),
            'sales_month' => getSalesMetrics($pdo, 'month'),
            'cylinders' => getCylinderMetrics($pdo),
            'inventory' => getInventorySummary($pdo),
            'low_stock' => getLowStockAlerts($pdo),
            'customers' => getCustomerMetrics($pdo),
            'top_products' => getTopSellingGasTypes($pdo),
            'payment_methods' => getPaymentMethodBreakdown($pdo, 'month'),
            'partner_exchange' => getPartnerExchangeMetrics($pdo),
        ];
    }
}

if (!function_exists('formatMetricsForPrompt')) {
    function formatMetricsForPrompt($snapshot) {
        $lines = [];
        $s = $snapshot['sales'];
        $sw = $snapshot['sales_week'];
        $sm = $snapshot['sales_month'];
        $c = $snapshot['cylinders'];
        $cs = $snapshot['customers'];

        $lines[] = "=== BUSINESS SNAPSHOT ===";
        $lines[] = "Today: {$s['order_count']} orders, ₹{$s['total_revenue']} revenue, {$s['unique_customers']} unique customers.";
        $lines[] = "Last 7 days: {$sw['order_count']} orders, ₹{$sw['total_revenue']} revenue, {$sw['unique_customers']} unique customers.";
        $lines[] = "Last 30 days: {$sm['order_count']} orders, ₹{$sm['total_revenue']} revenue, {$sm['unique_customers']} unique customers.";
        $lines[] = "Avg order value: ₹{$s['avg_order_value']}";

        $lines[] = "";
        $lines[] = "Cylinders: {$c['total_cylinders']} total ({$c['filled']} filled, {$c['empty']} empty, {$c['with_customer']} with customer, {$c['under_maintenance']} maintenance).";
        $lines[] = "Partner-owned: {$c['partner_owned']}, Consumer-owned: {$c['consumer_owned']}";

        $lines[] = "";
        $lines[] = "Customers: {$cs['total_customers']} total ({$cs['active_customers']} active), {$cs['new_customers_30d']} new in 30 days.";
        $lines[] = "Total deposit balance: ₹{$cs['total_deposit_balance']}";

        $low_stock = $snapshot['low_stock'];
        if (!empty($low_stock)) {
            $names = array_map(function($l) { return $l['gas_name'] . ' (' . $l['total_stock'] . '/' . $l['min_alert_threshold'] . ')'; }, $low_stock);
            $lines[] = "";
            $lines[] = "Low stock alerts: " . implode(', ', $names);
        }

        $top = $snapshot['top_products'];
        if (!empty($top)) {
            $lines[] = "";
            $lines[] = "Top products:";
            foreach ($top as $t) {
                $lines[] = "- {$t['gas_name']}: {$t['total_qty_sold']} units, ₹{$t['revenue_generated']}";
            }
        }

        return implode("\n", $lines);
    }
}
