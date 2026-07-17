<?php

if (!function_exists('formatDataForPrompt')) {
    function formatDataForPrompt($results, $context) {
        $lines = [];
        $hasData = false;

        foreach ($results as $key => $result) {
            if (!empty($result['error'])) {
                $lines[] = "[$key: Error - {$result['error']}]";
                continue;
            }

            if (empty($result['data'])) {
                $lines[] = "[$key: No records found]";
                continue;
            }

            $hasData = true;
            $label = $result['label'] ?: $key;
            $lines[] = "=== $label ===";

            foreach ($result['data'] as $row) {
                $parts = [];
                foreach ($row as $col => $val) {
                    if ($val !== null && $val !== '') {
                        $displayCol = str_replace('_', ' ', $col);
                        $parts[] = "$displayCol: $val";
                    }
                }
                $lines[] = "  - " . implode(" | ", $parts);
            }
            $lines[] = "  (" . count($result['data']) . " records)";
            $lines[] = "";
        }

        if (!$hasData) {
            $lines[] = "No relevant data found in the database for this query.";
            $lines[] = "";
        }

        return implode("\n", $lines);
    }
}
