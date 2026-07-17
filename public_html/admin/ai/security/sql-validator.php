<?php

if (!function_exists('isSelectOnly')) {
    function isSelectOnly($sql) {
        $trimmed = trim($sql);
        if (empty($trimmed)) return false;

        $normalized = preg_replace('/\s+/', ' ', strtolower($trimmed));

        $forbidden_keywords = [
            'insert', 'update', 'delete', 'drop', 'alter', 'truncate',
            'create', 'replace', 'rename', 'grant', 'revoke', 'lock',
            'unlock', 'kill', 'flush', 'load', 'merge', 'call',
            'exec', 'execute', 'set', 'declare', 'cursor',
        ];

        $first_word = strtok($normalized, ' ');
        if ($first_word === false) return false;

        if ($first_word !== 'select' && $first_word !== 'with') {
            return false;
        }

        $remaining = substr($normalized, strlen($first_word));

        foreach ($forbidden_keywords as $keyword) {
            $pattern = "/(?<![a-z_])" . preg_quote($keyword, '/') . "(?![a-z_])/";
            if (preg_match($pattern, $remaining)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('validateSQL')) {
    function validateSQL($sql) {
        $result = [
            'valid' => false,
            'error' => null,
        ];

        if (empty(trim($sql))) {
            $result['error'] = 'SQL query is empty.';
            return $result;
        }

        if (!isSelectOnly($sql)) {
            $result['error'] = 'Only SELECT queries are allowed. DML, DDL, and other statements are forbidden.';
            return $result;
        }

        $dangerous_patterns = [
            '/\binto\s+outfile\b/i',
            '/\binto\s+dumpfile\b/i',
            '/\binformation_schema\b/i',
            '/\bmysql\./i',
            '/\bsys\./i',
            '/\bperformance_schema\b/i',
            '/\bsleep\s*\(/i',
            '/\bbenchmark\s*\(/i',
            '/\bload_file\s*\(/i',
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                $result['error'] = 'Query contains forbidden patterns for security reasons.';
                return $result;
            }
        }

        $result['valid'] = true;
        return $result;
    }
}

if (!function_exists('sanitizeSQLIdentifier')) {
    function sanitizeSQLIdentifier($name) {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    }
}

if (!function_exists('validateAndExecuteRawSQL')) {
    function validateAndExecuteRawSQL($pdo, $sql, $params = []) {
        $validation = validateSQL($sql);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return ['success' => true, 'data' => $stmt->fetchAll()];
        } catch (PDOException $e) {
            error_log("validateAndExecuteRawSQL failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Query execution failed.'];
        }
    }
}
