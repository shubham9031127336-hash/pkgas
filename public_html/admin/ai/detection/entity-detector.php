<?php

if (!function_exists('detectEntities')) {
    function detectEntities($pdo, $message) {
        $entities = [];
        $lower = mb_strtolower(trim($message));

        $patterns = [
            'cylinder_serial' => [
                '/\b([A-Za-z]{1,5}[-\s]?\d{2,6})\b/',
                '/\b([A-Za-z]\d{3,5})\b/',
            ],
            'invoice_number' => [
                '/\b(INV[-\s]?\d{4}[-\s]?\d{4,6})\b/i',
                '/\binvoice\s*(?:no|#|number|:)?\s*[:\s]*([A-Za-z0-9\-]+)/i',
            ],
            'customer_mobile' => [
                '/\b(\d{10})\b/',
                '/\b(\d{5}\s?\d{5})\b/',
            ],
            'amount' => [
                '/[₹Rs\.]*\s*(\d{2,8}(?:\.\d{1,2})?)\s*(?:rupees|rs|रुपये)?/i',
            ],
            'gas_type' => [],
            'date_expression' => [
                '/\b(today|aaj|आज)\b/i',
                '/\b(yesterday|kal|कल)\b/i',
                '/\b(this\s+month|is\s+mahine|इस\s+महीने)\b/i',
                '/\b(last\s+month|pichle\s+mahine|पिछले\s+महीने)\b/i',
                '/\b(this\s+week|is\s+hafte|इस\s+हफ्ते)\b/i',
                '/\b(last\s+week|pichle\s+hafte|पिछले\s+हफ्ते)\b/i',
            ],
        ];

        foreach ($patterns['cylinder_serial'] as $re) {
            if (preg_match_all($re, $message, $m)) {
                foreach ($m[1] as $match) {
                    $clean = str_replace(['-', ' '], '', $match);
                    if (strlen($clean) >= 3 && strlen($clean) <= 10) {
                        $entities[] = [
                            'type' => 'cylinder_serial',
                            'value' => trim($match),
                            'confidence' => 0.9,
                        ];
                    }
                }
            }
        }

        foreach ($patterns['invoice_number'] as $re) {
            if (preg_match_all($re, $message, $m)) {
                foreach ($m[1] as $match) {
                    $entities[] = [
                        'type' => 'invoice_number',
                        'value' => trim($match),
                        'confidence' => 0.92,
                    ];
                }
            }
        }

        foreach ($patterns['customer_mobile'] as $re) {
            if (preg_match_all($re, $message, $m)) {
                foreach ($m[1] as $match) {
                    $digits = preg_replace('/\s+/', '', $match);
                    if (strlen($digits) === 10 && !isEntityPresent($entities, 'cylinder_serial')) {
                        $entities[] = [
                            'type' => 'customer_mobile',
                            'value' => $digits,
                            'confidence' => 0.95,
                        ];
                    }
                }
            }
        }

        $dateExpr = null;
        foreach ($patterns['date_expression'] as $re) {
            if (preg_match($re, $lower, $m)) {
                $raw = mb_strtolower(trim($m[1] ?? $m[0]));
                $dateExpr = mapDateExpression($raw);
                break;
            }
        }
        if ($dateExpr) {
            $entities[] = [
                'type' => 'date_expression',
                'value' => $dateExpr,
                'confidence' => 0.85,
            ];
        }

        try {
            $gasStmt = $pdo->query("SELECT LOWER(name) AS name FROM gas_types");
            $gasTypes = $gasStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($gasTypes as $gas) {
                if (mb_strpos($lower, $gas) !== false) {
                    $entities[] = [
                        'type' => 'gas_type',
                        'value' => $gas,
                        'confidence' => 0.85,
                    ];
                }
            }
        } catch (Exception $e) {}

        $entities = array_filter($entities, function($e) {
            return $e['confidence'] >= 0.5;
        });

        $entities = deduplicateEntities(array_values($entities));

        $contextualEntities = detectContextualEntities($lower);
        foreach ($contextualEntities as $ce) {
            if (!isEntityPresent($entities, $ce['type'], $ce['value'])) {
                $entities[] = $ce;
            }
        }

        usort($entities, function($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });

        return $entities;
    }
}

if (!function_exists('detectContextualEntities')) {
    function detectContextualEntities($lower) {
        $entities = [];

        $statusMap = [
            'filled' => 'filled', 'full' => 'filled', 'भरा' => 'filled',
            'empty' => 'empty', 'खाली' => 'empty',
            'maintenance' => 'under_maintenance', 'repair' => 'under_maintenance', 'मरम्मत' => 'under_maintenance',
            'with customer' => 'with_customer', 'dispatched' => 'with_customer', 'ग्राहक के पास' => 'with_customer',
            'returned' => 'empty', 'वापस' => 'empty',
        ];

        foreach ($statusMap as $keyword => $status) {
            if (mb_strpos($lower, $keyword) !== false) {
                $entities[] = [
                    'type' => 'cylinder_status',
                    'value' => $status,
                    'confidence' => 0.75,
                ];
                break;
            }
        }

        if (preg_match('/(?:find|search|show|lookup|खोजें|ढूंढें)\s+(?:customer|client|ग्राहक\s+)?([A-Za-z\s]{3,30})$/i', $lower, $m)) {
            $name = trim($m[1]);
            if (strlen($name) >= 2) {
                $entities[] = [
                    'type' => 'customer_name',
                    'value' => $name,
                    'confidence' => 0.7,
                ];
            }
        }

        if (preg_match('/\bhistory\b/i', $lower) || preg_match('/\btransaction\b/i', $lower) || preg_match('/\blog\b/i', $lower)) {
            $entities[] = [
                'type' => 'request_type',
                'value' => 'history',
                'confidence' => 0.7,
            ];
        }

        return $entities;
    }
}

if (!function_exists('isEntityPresent')) {
    function isEntityPresent($entities, $type, $value = null) {
        foreach ($entities as $e) {
            if ($e['type'] === $type && ($value === null || $e['value'] === $value)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('deduplicateEntities')) {
    function deduplicateEntities($entities) {
        $seen = [];
        $result = [];
        foreach ($entities as $e) {
            $key = $e['type'] . ':' . $e['value'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $e;
            }
        }
        return $result;
    }
}

if (!function_exists('mapDateExpression')) {
    function mapDateExpression($raw) {
        $map = [
            'today' => 'today', 'aaj' => 'today', 'आज' => 'today',
            'yesterday' => 'yesterday', 'kal' => 'yesterday', 'कल' => 'yesterday',
            'this month' => 'this_month', 'is mahine' => 'this_month', 'इस महीने' => 'this_month',
            'last month' => 'last_month', 'pichle mahine' => 'last_month', 'पिछले महीने' => 'last_month',
            'this week' => 'this_week', 'is hafte' => 'this_week', 'इस हफ्ते' => 'this_week',
            'last week' => 'last_week', 'pichle hafte' => 'last_week', 'पिछले हफ्ते' => 'last_week',
        ];
        return $map[$raw] ?? null;
    }
}
