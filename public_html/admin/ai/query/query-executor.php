<?php

require_once __DIR__ . '/../security/sql-validator.php';

if (!function_exists('executeQueryPlan')) {
    function executeQueryPlan($pdo, $plan, $role = 'super_admin') {
        $results = [];

        foreach ($plan as $query) {
            $sql = $query['sql'] ?? '';
            $params = $query['params'] ?? [];
            $key = $query['key'] ?? 'unknown';
            $label = $query['label'] ?? '';

            $validation = validateSQL($sql);
            if (!$validation['valid']) {
                error_log("query-builder: SQL validation failed for $key: " . $validation['error']);
                $results[$key] = [
                    'success' => false,
                    'label' => $label,
                    'error' => 'SQL validation failed: ' . $validation['error'],
                    'data' => [],
                    'count' => 0,
                ];
                continue;
            }

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $results[$key] = [
                    'success' => true,
                    'label' => $label,
                    'data' => $rows,
                    'count' => count($rows),
                ];
            } catch (PDOException $e) {
                error_log("query-builder: execution failed for $key: " . $e->getMessage());
                $results[$key] = [
                    'success' => false,
                    'label' => $label,
                    'error' => 'Query execution failed: ' . $e->getMessage(),
                    'data' => [],
                    'count' => 0,
                ];
            }
        }

        return $results;
    }
}

if (!function_exists('hasAnyData')) {
    function hasAnyData($results) {
        foreach ($results as $r) {
            if ($r['success'] && $r['count'] > 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('mergeQueryResults')) {
    function mergeQueryResults($results) {
        $merged = [];

        foreach ($results as $key => $result) {
            if ($result['success'] && !empty($result['data'])) {
                foreach ($result['data'] as $row) {
                    $merged[$key][] = $row;
                }
            }
        }

        return $merged;
    }
}

if (!function_exists('extractKeyFindings')) {
    function extractKeyFindings($results) {
        $findings = [];

        foreach ($results as $key => $result) {
            if (!$result['success'] || empty($result['data'])) continue;

            $rows = $result['data'];
            $label = $result['label'] ?: $key;

            if (count($rows) === 1) {
                $row = $rows[0];
                $parts = [];
                foreach ($row as $col => $val) {
                    if ($val !== null && $val !== '') {
                        $parts[] = "$col: $val";
                    }
                }
                $findings[] = "$label: " . implode(" | ", $parts);
            } else {
                $first = $rows[0];
                $sampleParts = [];
                $count = 0;
                foreach ($first as $col => $val) {
                    if ($val !== null && $val !== '' && $count < 3) {
                        $sampleParts[] = "$col: $val";
                        $count++;
                    }
                }
                $findings[] = "$label: " . count($rows) . " records (sample: " . implode(" | ", $sampleParts) . ")";
            }
        }

        return $findings;
    }
}
