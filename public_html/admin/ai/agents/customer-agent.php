<?php

require_once __DIR__ . '/../memory/memory-retriever.php';

if (!function_exists('extractSearchTerm')) {
    function extractSearchTerm($message) {
        $patterns = [
            '/\b(\d{10})\b/',
            '/\b(\d{5,6})\b/',
            '/mobile\s*:?\s*(\S+)/i',
            '/phone\s*:?\s*(\S+)/i',
            '/number\s*:?\s*(\S+)/i',
            '/मोबाइल\s*:?\s*(\S+)/i',
            '/फ़ोन\s*:?\s*(\S+)/i',
            '/find\s+(\S+(?:\s+\S+)?)/i',
            '/search\s+(\S+(?:\s+\S+)?)/i',
            '/show\s+(\S+(?:\s+\S+)?)/i',
            '/खोजें\s+(\S+(?:\s+\S+)?)/i',
            '/ढूंढें\s+(\S+(?:\s+\S+)?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return trim($matches[1]);
            }
        }

        $words = explode(' ', trim($message));
        foreach ($words as $word) {
            $word = trim($word, '.,?!;:');
            if (strlen($word) >= 3 && !in_array(mb_strtolower($word), ['the','for','and','show','find','get','search','customer','client','details','खोजें','ढूंढें'])) {
                return $word;
            }
        }

        return null;
    }
}

if (!function_exists('handleCustomerQuery')) {
    function handleCustomerQuery($pdo, $user_message, $user_id, $role, $session_id) {
        $data = [];
        $search_term = extractSearchTerm($user_message);

        if ($search_term) {
            $search_like = "%$search_term%";

            $result = executeAllowedQuery($pdo, 'customer_lookup', [$search_like, $search_like]);
            if ($result['success']) {
                $data['customers'] = $result['data'];

                if (count($result['data']) === 1) {
                    $cust = $result['data'][0];
                    $result2 = executeAllowedQuery($pdo, 'customer_by_id', [$cust['id']]);
                    if ($result2['success'] && !empty($result2['data'])) {
                        $data['customer_detail'] = $result2['data'][0];
                    }
                    $result3 = executeAllowedQuery($pdo, 'customer_cylinder_count', [$cust['id']]);
                    if ($result3['success'] && !empty($result3['data'])) {
                        $data['cylinder_count'] = $result3['data'][0]['cylinders_with_customer'];
                    }

                    $custId = $cust['id'];
                    try {
                        $stmt = $pdo->prepare("SELECT DATE(created_at) AS day, SUM(grand_total) AS spent, COUNT(*) AS orders FROM refill_orders WHERE customer_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY DATE(created_at) ORDER BY day ASC");
                        $stmt->execute([$custId]);
                        $data['spending_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $stmt2 = $pdo->prepare("SELECT * FROM payments WHERE customer_id = ? ORDER BY payment_date DESC LIMIT 10");
                        $stmt2->execute([$custId]);
                        $data['payment_history'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                        $stmt3 = $pdo->prepare("SELECT ro.*, COUNT(roi.id) AS items_count FROM refill_orders ro LEFT JOIN refill_order_items roi ON ro.id = roi.refill_order_id WHERE ro.customer_id = ? GROUP BY ro.id ORDER BY ro.created_at DESC LIMIT 10");
                        $stmt3->execute([$custId]);
                        $data['order_history'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);

                        $stmt4 = $pdo->prepare("SELECT c.*, gt.name AS gas_name FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id WHERE c.current_customer_id = ?");
                        $stmt4->execute([$custId]);
                        $data['cylinders_assigned'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);

                        $stmt5 = $pdo->prepare("SELECT YEAR(created_at) AS yr, MONTH(created_at) AS mo, SUM(grand_total) AS spent, COUNT(*) AS orders FROM refill_orders WHERE customer_id = ? GROUP BY yr, mo ORDER BY yr DESC, mo DESC LIMIT 12");
                        $stmt5->execute([$custId]);
                        $data['monthly_spending'] = $stmt5->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        error_log("customer deep dive queries failed: " . $e->getMessage());
                    }
                }
            }
        }

        $role_memories = getRoleMemoriesByKeys($pdo, $role, ['focus', 'permission_scope']);

        $system_prompt = "You are Prem Gas Solution' customer service assistant. Here is the customer data:\n\n";

        if (!empty($data['customers'])) {
            if (count($data['customers']) === 1) {
                $c = $data['customers'][0];
                $system_prompt .= "Found customer: {$c['name']}, Mobile: {$c['mobile']}, City: {$c['city']}\n";
                if (!empty($data['customer_detail'])) {
                    $cd = $data['customer_detail'];
                    $system_prompt .= "Address: {$cd['address']}, State: {$cd['state']}, Type: {$cd['customer_type']}, Status: {$cd['status']}\n";
                    $system_prompt .= "Deposit balance: ₹{$cd['deposit_balance']}, Active cylinders: {$cd['active_cylinders_count']}\n";
                }
                if (isset($data['cylinder_count'])) {
                    $system_prompt .= "Cylinders currently with customer: {$data['cylinder_count']}\n";
                }
                if (!empty($data['order_history'])) {
                    $system_prompt .= "\nRecent orders:\n";
                    foreach ($data['order_history'] as $o) {
                        $system_prompt .= "- Order #{$o['id']} on {$o['created_at']}: ₹{$o['grand_total']}, Status: {$o['payment_status']}\n";
                    }
                }
                if (!empty($data['cylinders_assigned'])) {
                    $system_prompt .= "\nAssigned cylinders:\n";
                    foreach ($data['cylinders_assigned'] as $cyl) {
                        $system_prompt .= "- {$cyl['serial_number']} ({$cyl['gas_name']}) - {$cyl['status']}\n";
                    }
                }
                if (!empty($data['monthly_spending'])) {
                    $system_prompt .= "\nMonthly spending:\n";
                    foreach ($data['monthly_spending'] as $ms) {
                        $system_prompt .= "- {$ms['yr']}-{$ms['mo']}: ₹{$ms['spent']} ({$ms['orders']} orders)\n";
                    }
                }
            } else {
                $system_prompt .= "Found " . count($data['customers']) . " matching customers:\n";
                foreach ($data['customers'] as $c) {
                    $system_prompt .= "- {$c['name']}, {$c['mobile']}, {$c['city']}\n";
                }
            }
        } else {
            if ($search_term) {
                $system_prompt .= "No customers found matching '$search_term'.\n";
            } else {
                $system_prompt .= "No search term provided. Ask the user to provide a customer name or mobile number.\n";
            }
        }

        if (!empty($role_memories)) {
            $system_prompt .= "\nRole context:\n";
            foreach ($role_memories as $rm) {
                $system_prompt .= "- {$rm['memory_key']}: {$rm['memory_value']}\n";
            }
        }

        $system_prompt .= "\nAnswer the user's customer query using the data above. Be concise. Use the same language as the user.";

        $ai_response = callAI($user_message, $system_prompt);

        return [
            'message' => $ai_response,
            'agent' => 'customer',
            'confidence' => 0.80,
            'data' => $data,
        ];
    }
}
