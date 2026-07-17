<?php

if (!function_exists('getSalesTrend')) {
    function getSalesTrend($pdo, $interval = 'daily', $lookback_days = 30) {
        try {
            if ($interval === 'daily') {
                $sql = "SELECT
                            DATE(o.created_at) AS period,
                            COUNT(DISTINCT o.id) AS order_count,
                            COALESCE(SUM(o.grand_total), 0) AS revenue,
                            COUNT(DISTINCT o.customer_id) AS customer_count
                        FROM refill_orders o
                        WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                        GROUP BY DATE(o.created_at)
                        ORDER BY period ASC";
            } elseif ($interval === 'weekly') {
                $sql = "SELECT
                            DATE_SUB(DATE(o.created_at), INTERVAL WEEKDAY(o.created_at) DAY) AS period,
                            COUNT(DISTINCT o.id) AS order_count,
                            COALESCE(SUM(o.grand_total), 0) AS revenue,
                            COUNT(DISTINCT o.customer_id) AS customer_count
                        FROM refill_orders o
                        WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                        GROUP BY YEARWEEK(o.created_at)
                        ORDER BY period ASC";
            } else {
                $sql = "SELECT
                            DATE_FORMAT(o.created_at, '%Y-%m') AS period,
                            COUNT(DISTINCT o.id) AS order_count,
                            COALESCE(SUM(o.grand_total), 0) AS revenue,
                            COUNT(DISTINCT o.customer_id) AS customer_count
                        FROM refill_orders o
                        WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                        GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
                        ORDER BY period ASC";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$lookback_days]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getSalesTrend failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('compareWeekOverWeek')) {
    function compareWeekOverWeek($pdo) {
        try {
            $sql = "SELECT
                        SUM(CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN o.grand_total ELSE 0 END) AS current_week,
                        SUM(CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN o.grand_total ELSE 0 END) AS previous_week,
                        COUNT(DISTINCT CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN o.id END) AS current_orders,
                        COUNT(DISTINCT CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN o.id END) AS previous_orders
                    FROM refill_orders o
                    WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                        AND o.payment_status IN ('paid', 'partial')";

            $stmt = $pdo->query($sql);
            $row = $stmt->fetch();

            $revenue_growth = 0;
            if ($row['previous_week'] > 0) {
                $revenue_growth = (($row['current_week'] - $row['previous_week']) / $row['previous_week']) * 100;
            }

            $order_growth = 0;
            if ($row['previous_orders'] > 0) {
                $order_growth = (($row['current_orders'] - $row['previous_orders']) / $row['previous_orders']) * 100;
            }

            return [
                'current_week_revenue' => (float)$row['current_week'],
                'previous_week_revenue' => (float)$row['previous_week'],
                'revenue_growth_pct' => round($revenue_growth, 1),
                'current_week_orders' => (int)$row['current_orders'],
                'previous_week_orders' => (int)$row['previous_orders'],
                'order_growth_pct' => round($order_growth, 1),
                'direction' => $revenue_growth >= 0 ? 'up' : 'down',
            ];
        } catch (PDOException $e) {
            error_log("compareWeekOverWeek failed: " . $e->getMessage());
            return [
                'current_week_revenue' => 0, 'previous_week_revenue' => 0,
                'revenue_growth_pct' => 0, 'current_week_orders' => 0,
                'previous_week_orders' => 0, 'order_growth_pct' => 0, 'direction' => 'flat',
            ];
        }
    }
}

if (!function_exists('compareMonthOverMonth')) {
    function compareMonthOverMonth($pdo) {
        try {
            $sql = "SELECT
                        SUM(CASE WHEN DATE_FORMAT(o.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN o.grand_total ELSE 0 END) AS current_month,
                        SUM(CASE WHEN DATE_FORMAT(o.created_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m') THEN o.grand_total ELSE 0 END) AS previous_month,
                        COUNT(DISTINCT CASE WHEN DATE_FORMAT(o.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN o.id END) AS current_orders,
                        COUNT(DISTINCT CASE WHEN DATE_FORMAT(o.created_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m') THEN o.id END) AS previous_orders
                    FROM refill_orders o
                    WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                        AND o.payment_status IN ('paid', 'partial')";

            $stmt = $pdo->query($sql);
            $row = $stmt->fetch();

            $revenue_growth = 0;
            if ($row['previous_month'] > 0) {
                $revenue_growth = (($row['current_month'] - $row['previous_month']) / $row['previous_month']) * 100;
            }

            $order_growth = 0;
            if ($row['previous_orders'] > 0) {
                $order_growth = (($row['current_orders'] - $row['previous_orders']) / $row['previous_orders']) * 100;
            }

            return [
                'current_month_revenue' => (float)$row['current_month'],
                'previous_month_revenue' => (float)$row['previous_month'],
                'revenue_growth_pct' => round($revenue_growth, 1),
                'current_month_orders' => (int)$row['current_orders'],
                'previous_month_orders' => (int)$row['previous_orders'],
                'order_growth_pct' => round($order_growth, 1),
                'direction' => $revenue_growth >= 0 ? 'up' : 'down',
            ];
        } catch (PDOException $e) {
            error_log("compareMonthOverMonth failed: " . $e->getMessage());
            return [
                'current_month_revenue' => 0, 'previous_month_revenue' => 0,
                'revenue_growth_pct' => 0, 'current_month_orders' => 0,
                'previous_month_orders' => 0, 'order_growth_pct' => 0, 'direction' => 'flat',
            ];
        }
    }
}

if (!function_exists('getCylinderUtilizationTrend')) {
    function getCylinderUtilizationTrend($pdo, $lookback_days = 90) {
        try {
            $stmt = $pdo->prepare("SELECT
                DATE(transaction_date) AS date,
                transaction_type,
                COUNT(*) AS count
            FROM cylinder_transactions
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(transaction_date), transaction_type
            ORDER BY date ASC");
            $stmt->execute([$lookback_days]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getCylinderUtilizationTrend failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('compareYearOverYear')) {
    function compareYearOverYear($pdo) {
        try {
            $currentYear = date('Y');
            $prevYear = $currentYear - 1;
            $sql = "SELECT
                        SUM(CASE WHEN YEAR(o.created_at) = ? THEN o.grand_total ELSE 0 END) AS current_year,
                        SUM(CASE WHEN YEAR(o.created_at) = ? THEN o.grand_total ELSE 0 END) AS previous_year,
                        COUNT(DISTINCT CASE WHEN YEAR(o.created_at) = ? THEN o.id END) AS current_orders,
                        COUNT(DISTINCT CASE WHEN YEAR(o.created_at) = ? THEN o.id END) AS previous_orders
                    FROM refill_orders o
                    WHERE YEAR(o.created_at) IN (?, ?)
                        AND o.payment_status IN ('paid', 'partial')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$currentYear, $prevYear, $currentYear, $prevYear, $currentYear, $prevYear]);
            $row = $stmt->fetch();

            $revenue_growth = 0;
            if ($row['previous_year'] > 0) {
                $revenue_growth = (($row['current_year'] - $row['previous_year']) / $row['previous_year']) * 100;
            }
            $order_growth = 0;
            if ($row['previous_orders'] > 0) {
                $order_growth = (($row['current_orders'] - $row['previous_orders']) / $row['previous_orders']) * 100;
            }

            return [
                'current_year_revenue' => (float)$row['current_year'],
                'previous_year_revenue' => (float)$row['previous_year'],
                'revenue_growth_pct' => round($revenue_growth, 1),
                'current_year_orders' => (int)$row['current_orders'],
                'previous_year_orders' => (int)$row['previous_orders'],
                'order_growth_pct' => round($order_growth, 1),
                'direction' => $revenue_growth >= 0 ? 'up' : 'down',
                'current_year_label' => (string)$currentYear,
                'previous_year_label' => (string)$prevYear,
            ];
        } catch (PDOException $e) {
            error_log("compareYearOverYear failed: " . $e->getMessage());
            return [
                'current_year_revenue' => 0, 'previous_year_revenue' => 0,
                'revenue_growth_pct' => 0, 'direction' => 'flat',
                'current_year_orders' => 0, 'previous_year_orders' => 0, 'order_growth_pct' => 0,
                'current_year_label' => '', 'previous_year_label' => '',
            ];
        }
    }
}

if (!function_exists('comparePeriodCustom')) {
    function comparePeriodCustom($pdo, $start1, $end1, $start2, $end2) {
        try {
            $sql = "SELECT
                        SUM(CASE WHEN o.created_at >= ? AND o.created_at < ? THEN o.grand_total ELSE 0 END) AS period1_revenue,
                        SUM(CASE WHEN o.created_at >= ? AND o.created_at < ? THEN o.grand_total ELSE 0 END) AS period2_revenue,
                        COUNT(DISTINCT CASE WHEN o.created_at >= ? AND o.created_at < ? THEN o.id END) AS period1_orders,
                        COUNT(DISTINCT CASE WHEN o.created_at >= ? AND o.created_at < ? THEN o.id END) AS period2_orders
                    FROM refill_orders o
                    WHERE (o.created_at >= ? AND o.created_at < ?) OR (o.created_at >= ? AND o.created_at < ?)
                        AND o.payment_status IN ('paid', 'partial')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$start1, $end1, $start2, $end2, $start1, $end1, $start2, $end2, $start1, $end1, $start2, $end2]);
            $row = $stmt->fetch();

            $revenue_growth = 0;
            if ($row['period2_revenue'] > 0) {
                $revenue_growth = (($row['period1_revenue'] - $row['period2_revenue']) / $row['period2_revenue']) * 100;
            }

            return [
                'period1_revenue' => (float)$row['period1_revenue'],
                'period2_revenue' => (float)$row['period2_revenue'],
                'revenue_growth_pct' => round($revenue_growth, 1),
                'period1_orders' => (int)$row['period1_orders'],
                'period2_orders' => (int)$row['period2_orders'],
                'direction' => $revenue_growth >= 0 ? 'up' : 'down',
            ];
        } catch (PDOException $e) {
            error_log("comparePeriodCustom failed: " . $e->getMessage());
            return ['period1_revenue' => 0, 'period2_revenue' => 0, 'revenue_growth_pct' => 0, 'direction' => 'flat', 'period1_orders' => 0, 'period2_orders' => 0];
        }
    }
}

if (!function_exists('getCustomerAcquisitionTrend')) {
    function getCustomerAcquisitionTrend($pdo, $lookback_days = 90) {
        try {
            $stmt = $pdo->prepare("SELECT
                DATE(created_at) AS date,
                COUNT(*) AS new_customers,
                COALESCE(SUM(CASE WHEN customer_type = 'refill' THEN 1 ELSE 0 END), 0) AS refill,
                COALESCE(SUM(CASE WHEN customer_type = 'rental' THEN 1 ELSE 0 END), 0) AS rental
            FROM customers
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC");
            $stmt->execute([$lookback_days]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getCustomerAcquisitionTrend failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getTopCustomersTrend')) {
    function getTopCustomersTrend($pdo, $limit = 5) {
        try {
            $stmt = $pdo->prepare("SELECT
                c.id, c.name, c.mobile,
                COUNT(DISTINCT o.id) AS order_count,
                COALESCE(SUM(o.grand_total), 0) AS total_spent,
                MAX(o.created_at) AS last_order_date
            FROM customers c
            JOIN refill_orders o ON c.id = o.customer_id
            WHERE o.payment_status IN ('paid', 'partial')
                AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            GROUP BY c.id, c.name, c.mobile
            ORDER BY total_spent DESC
            LIMIT " . (int)$limit);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getTopCustomersTrend failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('formatTrendForPrompt')) {
    function formatTrendForPrompt($pdo) {
        $lines = [];
        $wow = compareWeekOverWeek($pdo);
        $mom = compareMonthOverMonth($pdo);
        $yoy = compareYearOverYear($pdo);

        $lines[] = "=== TRENDS ===";
        $lines[] = "Week-over-week: revenue {$wow['direction']} {$wow['revenue_growth_pct']}% (₹{$wow['current_week_revenue']} vs ₹{$wow['previous_week_revenue']}), orders {$wow['order_growth_pct']}%";
        $lines[] = "Month-over-month: revenue {$mom['direction']} {$mom['revenue_growth_pct']}% (₹{$mom['current_month_revenue']} vs ₹{$mom['previous_month_revenue']})";
        $lines[] = "Year-over-year: revenue {$yoy['direction']} {$yoy['revenue_growth_pct']}% (₹{$yoy['current_year_revenue']} in {$yoy['current_year_label']} vs ₹{$yoy['previous_year_revenue']} in {$yoy['previous_year_label']})";

        $top = getTopCustomersTrend($pdo);
        if (!empty($top)) {
            $lines[] = "";
            $lines[] = "Top customers (90 days):";
            foreach ($top as $t) {
                $lines[] = "- {$t['name']} ({$t['mobile']}): {$t['order_count']} orders, ₹{$t['total_spent']}";
            }
        }

        return implode("\n", $lines);
    }
}
