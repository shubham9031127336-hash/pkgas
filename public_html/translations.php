<?php
$public_translations = require __DIR__ . '/lang/en.php';

function __p($key) {
    global $public_translations;
    return $public_translations[$key] ?? $key;
}
