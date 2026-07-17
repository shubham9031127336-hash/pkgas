<?php

require_once __DIR__ . '/../entity/entity-registry.php';

if (!function_exists('discoverSchema')) {
    function discoverSchema($pdo) {
        $schema = [
            'tables' => [],
            'relationships' => [],
            'searchable_columns' => [],
        ];

        $excluded = ['ai_config','ai_user_memory','ai_role_memory','ai_session_context','ai_conversations','ai_workflow_memory','ai_feedback','activity_logs','posts'];

        $stmt = $pdo->query("SELECT TABLE_NAME, TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tables as $t) {
            $name = $t['TABLE_NAME'];
            if (in_array($name, $excluded)) continue;

            $colStmt = $pdo->prepare("SELECT COLUMNS.COLUMN_NAME, COLUMNS.COLUMN_TYPE, COLUMNS.IS_NULLABLE, COLUMNS.COLUMN_KEY, COLUMNS.COLUMN_COMMENT, KEY_COLUMN_USAGE.REFERENCED_TABLE_NAME, KEY_COLUMN_USAGE.REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ON (COLUMNS.COLUMN_NAME = KEY_COLUMN_USAGE.COLUMN_NAME AND COLUMNS.TABLE_NAME = KEY_COLUMN_USAGE.TABLE_NAME AND COLUMNS.TABLE_SCHEMA = KEY_COLUMN_USAGE.TABLE_SCHEMA AND KEY_COLUMN_USAGE.REFERENCED_TABLE_NAME IS NOT NULL) WHERE COLUMNS.TABLE_SCHEMA = DATABASE() AND COLUMNS.TABLE_NAME = ? ORDER BY COLUMNS.ORDINAL_POSITION");
            $colStmt->execute([$name]);
            $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

            $parsedColumns = [];
            $searchable = [];

            foreach ($columns as $col) {
                $colName = $col['COLUMN_NAME'];
                $parsedColumns[$colName] = [
                    'type' => $col['COLUMN_TYPE'],
                    'nullable' => $col['IS_NULLABLE'] === 'YES',
                    'key' => $col['COLUMN_KEY'],
                    'comment' => $col['COLUMN_COMMENT'],
                ];

                $isSearchable = (
                    strpos($col['COLUMN_TYPE'], 'varchar') !== false ||
                    strpos($col['COLUMN_TYPE'], 'text') !== false ||
                    $col['COLUMN_KEY'] === 'UNI'
                ) && !in_array($colName, ['password_hash','api_key','notes']);

                if ($isSearchable) {
                    $searchable[] = $colName;
                }

                if (!empty($col['REFERENCED_TABLE_NAME'])) {
                    $schema['relationships'][] = [
                        'from_table' => $name,
                        'from_column' => $colName,
                        'to_table' => $col['REFERENCED_TABLE_NAME'],
                        'to_column' => $col['REFERENCED_COLUMN_NAME'],
                    ];
                }
            }

            $schema['tables'][$name] = [
                'columns' => $parsedColumns,
                'searchable_columns' => $searchable,
                'comment' => $t['TABLE_COMMENT'],
            ];
        }

        return $schema;
    }
}

if (!function_exists('getSchemaTables')) {
    function getSchemaTables($pdo) {
        $s = discoverSchema($pdo);
        return array_keys($s['tables']);
    }
}

if (!function_exists('getSchemaColumns')) {
    function getSchemaColumns($pdo, $table) {
        $s = discoverSchema($pdo);
        return $s['tables'][$table]['columns'] ?? [];
    }
}

if (!function_exists('getSearchableColumns')) {
    function getSearchableColumns($pdo, $table) {
        $s = discoverSchema($pdo);
        return $s['tables'][$table]['searchable_columns'] ?? [];
    }
}

if (!function_exists('getTableRelationships')) {
    function getTableRelationships($pdo) {
        $s = discoverSchema($pdo);
        return $s['relationships'];
    }
}

if (!function_exists('findRelatedTables')) {
    function findRelatedTables($pdo, $table, $maxDepth = 2) {
        $rels = getTableRelationships($pdo);
        $visited = [$table => true];
        $queue = [[$table, 0]];
        $related = [];

        while (!empty($queue)) {
            [$current, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) continue;

            foreach ($rels as $r) {
                $neighbor = null;
                if ($r['from_table'] === $current && !isset($visited[$r['to_table']])) {
                    $neighbor = $r['to_table'];
                } elseif ($r['to_table'] === $current && !isset($visited[$r['from_table']])) {
                    $neighbor = $r['from_table'];
                }
                if ($neighbor) {
                    $visited[$neighbor] = true;
                    $related[] = $neighbor;
                    $queue[] = [$neighbor, $depth + 1];
                }
            }
        }

        return $related;
    }
}

if (!function_exists('findTablesForEntity')) {
    function findTablesForEntity($pdo, $entityType) {
        $s = discoverSchema($pdo);
        $candidates = [];

        $entityMap = [
            'cylinder_serial' => ['cylinders'],
            'cylinder_barcode' => ['cylinders'],
            'invoice_number' => ['refill_orders'],
            'customer_name' => ['customers'],
            'customer_mobile' => ['customers'],
            'gas_type' => ['gas_types'],
            'vendor_name' => ['vendors'],
            'partner_name' => ['partners'],
            'deposit_receipt' => ['deposit_receipts'],
            'payment_id' => ['payments'],
            'order_id' => ['refill_orders'],
        ];

        if (isset($entityMap[$entityType])) {
            return $entityMap[$entityType];
        }

        foreach ($s['tables'] as $tName => $tData) {
            foreach ($tData['searchable_columns'] as $col) {
                $lower = strtolower($col);
                if (strpos($lower, str_replace('_', '', $entityType)) !== false) {
                    $candidates[] = $tName;
                    break;
                }
            }
        }

        return array_unique($candidates);
    }
}

if (!function_exists('detectSoftDeleteColumns')) {
    function detectSoftDeleteColumns($pdo) {
        $s = discoverSchema($pdo);
        $softDeleteCols = [];

        foreach ($s['tables'] as $name => $data) {
            foreach ($data['columns'] as $colName => $colData) {
                $lower = strtolower($colName);
                if (in_array($lower, ['deleted_at', 'is_deleted', 'is_archived', 'archived_at', 'deleted_by', 'archived_by', 'status'])) {
                    if (!isset($softDeleteCols[$name])) {
                        $softDeleteCols[$name] = [];
                    }
                    $softDeleteCols[$name][] = [
                        'column' => $colName,
                        'type' => $colData['type'],
                    ];
                }
            }
        }

        return $softDeleteCols;
    }
}

if (!function_exists('formatSchemaForPromptWithSoftDelete')) {
    function formatSchemaForPromptWithSoftDelete($pdo) {
        $s = discoverSchema($pdo);
        $softDeleteCols = detectSoftDeleteColumns($pdo);
        $registry = function_exists('getEntityRegistry') ? getEntityRegistry() : [];
        $lines = [];
        $lines[] = "=== DATABASE SCHEMA ===";

        foreach ($s['tables'] as $name => $data) {
            $comment = $data['comment'] ? " ($data[comment])" : '';
            $entityDesc = isset($registry[$name]) ? ' - ' . $registry[$name]['description'] : '';
            $cols = [];
            foreach ($data['columns'] as $colName => $colData) {
                $tag = $colData['key'] === 'PRI' ? '*' : ($colData['key'] === 'UNI' ? '+' : '');
                $cols[] = $tag ? "$colName$tag" : $colName;
            }
            $hasSoftDelete = isset($softDeleteCols[$name]);
            $line = "TABLE $name$comment$entityDesc";
            $lines[] = $line;
            $lines[] = "  Columns: " . implode(', ', $cols);
            if ($hasSoftDelete) {
                $lines[] = "  [soft-delete: " . implode(',', array_map(function($c) { return $c['column']; }, $softDeleteCols[$name])) . "]";
            }
            if (isset($registry[$name]['search_columns']) && !empty($registry[$name]['search_columns'])) {
                $lines[] = "  Search: " . implode(', ', $registry[$name]['search_columns']);
            }
        }

        if (!empty($s['relationships'])) {
            $lines[] = "";
            $lines[] = "FOREIGN KEY RELATIONSHIPS:";
            foreach ($s['relationships'] as $r) {
                $lines[] = "  $r[from_table].$r[from_column] -> $r[to_table].$r[to_column]";
            }
        }

        $lines[] = "";
        $lines[] = formatRegistrySchemaForPrompt();

        return implode("\n", $lines);
    }
}

if (!function_exists('getEntityIdentifierColumn')) {
    function getEntityIdentifierColumn($pdo, $entityType, $table) {
        $cols = getSchemaColumns($pdo, $table);

        $fieldMap = [
            'cylinder_serial' => ['serial_number', 'barcode'],
            'cylinder_barcode' => ['barcode', 'serial_number'],
            'invoice_number' => ['invoice_number'],
            'customer_name' => ['name'],
            'customer_mobile' => ['mobile'],
            'gas_type' => ['name'],
            'vendor_name' => ['name'],
            'partner_name' => ['company_name', 'name'],
            'deposit_receipt' => ['receipt_number'],
            'order_id' => ['id'],
        ];

        if (isset($fieldMap[$entityType])) {
            foreach ($fieldMap[$entityType] as $f) {
                if (isset($cols[$f])) return $f;
            }
        }

        foreach ($cols as $cName => $cData) {
            if ($cData['key'] === 'UNI' || $cData['key'] === 'PRI') {
                return $cName;
            }
        }

        return $cols['id'] ?? null;
    }
}

if (!function_exists('formatSchemaForPrompt')) {
    function formatSchemaForPrompt($pdo) {
        $s = discoverSchema($pdo);
        $lines = [];
        $lines[] = "DATABASE SCHEMA:";

        foreach ($s['tables'] as $name => $data) {
            $comment = $data['comment'] ? " ($data[comment])" : '';
            $cols = [];
            foreach ($data['columns'] as $colName => $colData) {
                $tag = $colData['key'] === 'PRI' ? '*' : ($colData['key'] === 'UNI' ? '+' : '');
                $cols[] = $tag ? "$colName$tag" : $colName;
            }
            $lines[] = "TABLE $name$comment: " . implode(', ', $cols);
        }

        if (!empty($s['relationships'])) {
            $lines[] = "RELATIONSHIPS:";
            foreach ($s['relationships'] as $r) {
                $lines[] = "  $r[from_table].$r[from_column] -> $r[to_table].$r[to_column]";
            }
        }

        return implode("\n", $lines);
    }
}
