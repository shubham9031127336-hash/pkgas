<?php
// Database configuration
$db_host = 'srv677.hstgr.io';
//$db_host = 'localhost';
$db_name = 'u189092125_pkgas';
$db_user = 'u189092125_live';
$db_pass = 'Pkgas@2020';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
