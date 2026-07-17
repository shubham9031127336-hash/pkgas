<?php
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) { http_response_code(403); die('Forbidden'); }

if (!function_exists('sendLowStockAlert')) {
    function sendLowStockAlert($pdo) {
        try {
            $stmt = $pdo->query("SELECT i.*, g.name AS gas_name FROM inventory i JOIN gas_types g ON i.gas_type_id = g.id WHERE i.filled_stock <= i.min_alert_threshold");
            $low = $stmt->fetchAll();
            $prod_stmt = $pdo->query("SELECT name, stock_quantity, min_alert_threshold FROM products WHERE stock_quantity <= min_alert_threshold");
            $low_prods = $prod_stmt->fetchAll();
            if (empty($low) && empty($low_prods)) {
                return;
            }
            $lines = [];
            $lines[] = "Low Stock Alert - Prem Gas Solution";
            $lines[] = "================================";
            if (!empty($low)) {
                $lines[] = "Cylinder Stock:";
                foreach ($low as $item) {
                    $lines[] = "  - {$item['gas_name']} ({$item['size_capacity']}): {$item['filled_stock']} filled (threshold: {$item['min_alert_threshold']})";
                }
                $lines[] = "";
            }
            if (!empty($low_prods)) {
                $lines[] = "Product Stock:";
                foreach ($low_prods as $p) {
                    $lines[] = "  - {$p['name']}: {$p['stock_quantity']} left (threshold: {$p['min_alert_threshold']})";
                }
                $lines[] = "";
            }
            $lines[] = "Please restock at the earliest.";
            $body = implode("\n", $lines);
            try {
                require_once __DIR__ . '/mail-config.php';
                $mail = getMailer();
                $mail->addAddress(getenv('ADMIN_EMAIL') ?: 'info@pkgas.com');
                $mail->Subject = "Low Stock Alert - Prem Gas Solution";
                $mail->Body = $body;
                $mail->send();
            } catch (Exception $e) {
                error_log("Low stock alert email failed: " . $e->getMessage());
            }
        } catch (PDOException $e) {
            error_log("Low stock alert check failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('sendExpiryReminders')) {
    function sendExpiryReminders($pdo) {
        try {
            $thirty_days = date('Y-m-d', strtotime('+30 days'));
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT c.id, c.serial_number, c.size_capacity, c.status, c.expiry_date, 
                       g.name AS gas_name, cust.name AS customer_name 
                FROM cylinders c 
                JOIN gas_types g ON c.gas_type_id = g.id 
                LEFT JOIN customers cust ON c.current_customer_id = cust.id 
                WHERE c.expiry_date IS NOT NULL 
                  AND c.expiry_date <= ? 
                  AND c.status NOT IN ('deleted', 'inactive', 'returned_to_partner', 'returned_to_consumer', 'returned_to_vendor')
                ORDER BY c.expiry_date ASC
            ");
            $stmt->execute([$thirty_days]);
            $cylinders = $stmt->fetchAll();
            if (empty($cylinders)) {
                return;
            }
            $overdue = array_filter($cylinders, fn($c) => $c['expiry_date'] <= $today);
            $upcoming = array_filter($cylinders, fn($c) => $c['expiry_date'] > $today);
            $lines = [];
            $lines[] = "Cylinder Hydrostatic Expiry Reminder - Prem Gas Solution";
            $lines[] = "==================================================";
            $lines[] = "";
            if (!empty($overdue)) {
                $lines[] = "=== OVERDUE (" . count($overdue) . " cylinders) ===";
                foreach ($overdue as $c) {
                    $cust = $c['customer_name'] ? " (with: {$c['customer_name']})" : '';
                    $lines[] = "  - #{$c['serial_number']}: {$c['gas_name']} {$c['size_capacity']}, expired {$c['expiry_date']}, status: {$c['status']}$cust";
                }
                $lines[] = "";
            }
            if (!empty($upcoming)) {
                $lines[] = "=== DUE WITHIN 30 DAYS (" . count($upcoming) . " cylinders) ===";
                foreach ($upcoming as $c) {
                    $cust = $c['customer_name'] ? " (with: {$c['customer_name']})" : '';
                    $lines[] = "  - #{$c['serial_number']}: {$c['gas_name']} {$c['size_capacity']}, due {$c['expiry_date']}, status: {$c['status']}$cust";
                }
                $lines[] = "";
            }
            $lines[] = "Please schedule hydrostatic testing for these cylinders.";
            $body = implode("\n", $lines);
            try {
                require_once __DIR__ . '/mail-config.php';
                $mail = getMailer();
                $mail->addAddress(getenv('ADMIN_EMAIL') ?: 'info@pkgas.com');
                $mail->Subject = "Cylinder Expiry Reminder - Prem Gas Solution";
                $mail->Body = $body;
                $mail->send();
            } catch (Exception $e) {
                error_log("Expiry reminder email failed: " . $e->getMessage());
            }
        } catch (PDOException $e) {
            error_log("Expiry reminder check failed: " . $e->getMessage());
        }
    }
}
