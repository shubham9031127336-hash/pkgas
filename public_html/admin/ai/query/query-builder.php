<?php

require_once __DIR__ . '/../schema/schema-explorer.php';

if (!function_exists('getEntityValue')) {
    function getEntityValue($entities, $type) {
        foreach ($entities as $e) {
            if ($e['type'] === $type) return $e['value'];
        }
        return null;
    }
}
