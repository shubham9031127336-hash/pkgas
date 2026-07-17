<?php

if (!function_exists('linearRegression')) {
    function linearRegression($data_points) {
        $n = count($data_points);
        if ($n < 2) return null;

        $sum_x = 0;
        $sum_y = 0;
        $sum_xy = 0;
        $sum_x2 = 0;

        foreach ($data_points as $i => $point) {
            $x = $i;
            $y = (float)$point;
            $sum_x += $x;
            $sum_y += $y;
            $sum_xy += $x * $y;
            $sum_x2 += $x * $x;
        }

        $slope = ($n * $sum_xy - $sum_x * $sum_y) / ($n * $sum_x2 - $sum_x * $sum_x);
        $intercept = ($sum_y - $slope * $sum_x) / $n;

        return ['slope' => $slope, 'intercept' => $intercept];
    }
}

if (!function_exists('predictNextValues')) {
    function predictNextValues($data_points, $steps_ahead = 7) {
        $regression = linearRegression($data_points);
        if ($regression === null) return [];

        $n = count($data_points);
        $predictions = [];

        for ($i = 1; $i <= $steps_ahead; $i++) {
            $x = $n - 1 + $i;
            $y = $regression['slope'] * $x + $regression['intercept'];
            $predictions[] = max(0, round($y, 2));
        }

        return $predictions;
    }
}

if (!function_exists('getSalesForecast')) {
    function getSalesForecast($pdo, $days_history = 30, $days_ahead = 7) {
        $trend = [];
        try {
            $stmt = $pdo->prepare("SELECT
                DATE(created_at) AS day,
                COALESCE(SUM(grand_total), 0) AS revenue
            FROM refill_orders
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                AND payment_status IN ('paid', 'partial')
            GROUP BY DATE(created_at)
            ORDER BY day ASC");
            $stmt->execute([$days_history]);
            $rows = $stmt->fetchAll();

            $revenue_by_date = [];
            foreach ($rows as $row) {
                $revenue_by_date[$row['day']] = (float)$row['revenue'];
            }

            $data_points = [];
            for ($i = $days_history - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', time() - ($i * 86400));
                $data_points[] = $revenue_by_date[$date] ?? 0;
            }

            $predictions = predictNextValues($data_points, $days_ahead);

            $forecast = [];
            for ($i = 1; $i <= $days_ahead; $i++) {
                $date = date('Y-m-d', time() + ($i * 86400));
                $forecast[] = [
                    'date' => $date,
                    'predicted_revenue' => $predictions[$i - 1] ?? 0,
                ];
            }

            $total_predicted = array_sum(array_column($forecast, 'predicted_revenue'));

            $avg_daily = count($data_points) > 0 ? array_sum($data_points) / count($data_points) : 0;

            return [
                'forecast' => $forecast,
                'total_predicted_revenue' => round($total_predicted, 2),
                'avg_daily_revenue' => round($avg_daily, 2),
                'confidence_note' => $days_history >= 30 ? 'moderate' : 'low',
            ];
        } catch (PDOException $e) {
            error_log("getSalesForecast failed: " . $e->getMessage());
            return [
                'forecast' => [],
                'total_predicted_revenue' => 0,
                'avg_daily_revenue' => 0,
                'confidence_note' => 'error',
            ];
        }
    }
}

if (!function_exists('getOrderVolumeForecast')) {
    function getOrderVolumeForecast($pdo, $days_history = 30, $days_ahead = 7) {
        try {
            $stmt = $pdo->prepare("SELECT
                DATE(created_at) AS day,
                COUNT(DISTINCT id) AS orders
            FROM refill_orders
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY day ASC");
            $stmt->execute([$days_history]);
            $rows = $stmt->fetchAll();

            $orders_by_date = [];
            foreach ($rows as $row) {
                $orders_by_date[$row['day']] = (int)$row['orders'];
            }

            $data_points = [];
            for ($i = $days_history - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', time() - ($i * 86400));
                $data_points[] = $orders_by_date[$date] ?? 0;
            }

            $predictions = predictNextValues($data_points, $days_ahead);

            $forecast = [];
            for ($i = 1; $i <= $days_ahead; $i++) {
                $date = date('Y-m-d', time() + ($i * 86400));
                $forecast[] = [
                    'date' => $date,
                    'predicted_orders' => max(0, round($predictions[$i - 1] ?? 0)),
                ];
            }

            return [
                'forecast' => $forecast,
                'total_predicted_orders' => array_sum(array_column($forecast, 'predicted_orders')),
            ];
        } catch (PDOException $e) {
            error_log("getOrderVolumeForecast failed: " . $e->getMessage());
            return ['forecast' => [], 'total_predicted_orders' => 0];
        }
    }
}

if (!function_exists('getInventoryDepletionRate')) {
    function getInventoryDepletionRate($pdo, $lookback_days = 30) {
        try {
            $stmt = $pdo->prepare("SELECT
                gt.name AS gas_name,
                i.total_stock AS current_stock,
                i.min_alert_threshold,
                COALESCE(SUM(oi.qty), 0) AS units_sold_period
            FROM gas_types gt
            JOIN inventory i ON gt.id = i.gas_type_id
            LEFT JOIN refill_order_items oi ON gt.id = oi.gas_type_id
            LEFT JOIN refill_orders o ON oi.refill_order_id = o.id
                AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY gt.id, gt.name, i.total_stock, i.min_alert_threshold
            ORDER BY gt.name");
            $stmt->execute([$lookback_days]);
            $rows = $stmt->fetchAll();

            $result = [];
            foreach ($rows as $row) {
                $daily_rate = $lookback_days > 0 ? $row['units_sold_period'] / $lookback_days : 0;
                $days_until_empty = $daily_rate > 0 ? floor($row['current_stock'] / $daily_rate) : null;

                $result[] = [
                    'gas_name' => $row['gas_name'],
                    'current_stock' => (int)$row['current_stock'],
                    'units_sold_last_' . $lookback_days . 'd' => (int)$row['units_sold_period'],
                    'daily_depletion_rate' => round($daily_rate, 2),
                    'days_until_empty' => $days_until_empty,
                    'low_stock_warning' => $days_until_empty !== null && $days_until_empty <= 7,
                ];
            }

            return $result;
        } catch (PDOException $e) {
            error_log("getInventoryDepletionRate failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('formatForecastForPrompt')) {
    function formatForecastForPrompt($pdo) {
        $lines = [];

        $sales_forecast = getSalesForecast($pdo);
        $lines[] = "=== FORECAST ===";
        if (!empty($sales_forecast['forecast'])) {
            $lines[] = "Next {$sales_forecast['total_predicted_revenue']} days revenue forecast: ₹{$sales_forecast['total_predicted_revenue']} (avg daily: ₹{$sales_forecast['avg_daily_revenue']})";
            $lines[] = "Confidence: {$sales_forecast['confidence_note']}";
        }

        $depletion = getInventoryDepletionRate($pdo);
        $warnings = array_filter($depletion, function($d) { return $d['low_stock_warning']; });
        if (!empty($warnings)) {
            $lines[] = "";
            $lines[] = "Stock depletion warnings (may run out within 7 days):";
            foreach ($warnings as $w) {
                $lines[] = "- {$w['gas_name']}: {$w['current_stock']} left, {$w['daily_depletion_rate']}/day (~{$w['days_until_empty']} days)";
            }
        }

        return implode("\n", $lines);
    }
}
