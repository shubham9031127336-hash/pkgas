<?php

if (!function_exists('classifyIntent')) {
    function classifyIntent($message, $entities) {
        $entityTypes = array_map(function($e) { return $e['type']; }, $entities);
        $lower = mb_strtolower(trim($message));

        if (in_array('cylinder_serial', $entityTypes) && in_array('request_type', $entityTypes)) {
            return [
                'intent' => 'cylinder_tracking',
                'subtype' => 'history',
                'confidence' => 0.9,
            ];
        }

        if (in_array('cylinder_serial', $entityTypes)) {
            return [
                'intent' => 'cylinder_tracking',
                'subtype' => 'location',
                'confidence' => 0.88,
            ];
        }

        if (in_array('invoice_number', $entityTypes)) {
            return [
                'intent' => 'invoice_lookup',
                'subtype' => 'details',
                'confidence' => 0.9,
            ];
        }

        if (in_array('customer_mobile', $entityTypes) || in_array('customer_name', $entityTypes)) {
            return [
                'intent' => 'customer_lookup',
                'subtype' => 'profile',
                'confidence' => 0.85,
            ];
        }

        if (in_array('cylinder_status', $entityTypes) && !in_array('cylinder_serial', $entityTypes)) {
            return [
                'intent' => 'cylinder_inventory',
                'subtype' => 'status',
                'confidence' => 0.8,
            ];
        }

        if (in_array('gas_type', $entityTypes)) {
            $hasStatus = in_array('cylinder_status', $entityTypes);
            return [
                'intent' => $hasStatus ? 'cylinder_inventory' : 'stock_inquiry',
                'subtype' => 'gas',
                'confidence' => 0.78,
            ];
        }

        $keywordScores = [];
        $keywordScores['cylinder_tracking'] = scoreKeywords($lower, [
            'cylinder','serial','track','where','location','find','search','barcode',
            'सिलेंडर','कहां','ट्रैक','सीरियल',
        ]);
        $keywordScores['stock_inquiry'] = scoreKeywords($lower, [
            'stock','available','how many','inventory','count','left',
            'स्टॉक','उपलब्ध','कितने',
        ]);
        $keywordScores['cylinder_inventory'] = scoreKeywords($lower, [
            'filled','empty','maintenance','status','cylinder','gas',
            'भरा','खाली','मरम्मत','स्थिति',
        ]);
        $keywordScores['sales_analytics'] = scoreKeywords($lower, [
            'sale','revenue','income','earning','order','total','today',
            'payment','invoice','bill','this month','this week','yesterday',
            'बिक्री','आय','कमाई','भुगतान','आज','कुल',
        ]);
        $keywordScores['customer_lookup'] = scoreKeywords($lower, [
            'customer','client','who','find','search','lookup','contact',
            'ग्राहक','खोजें','कौन',
        ]);
        $keywordScores['invoice_lookup'] = scoreKeywords($lower, [
            'invoice','receipt','bill','चालान','रसीद',
        ]);

        arsort($keywordScores);
        $topIntent = key($keywordScores);
        $topScore = reset($keywordScores);

        if ($topScore === 0) {
            return [
                'intent' => 'general',
                'subtype' => 'conversation',
                'confidence' => 0.5,
            ];
        }

        return [
            'intent' => $topIntent,
            'subtype' => 'auto',
            'confidence' => min(0.5 + ($topScore * 0.1), 0.9),
        ];
    }
}

if (!function_exists('scoreKeywords')) {
    function scoreKeywords($text, $keywords) {
        $score = 0;
        foreach ($keywords as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $score++;
            }
        }
        return $score;
    }
}
