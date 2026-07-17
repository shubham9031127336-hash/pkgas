<?php
require_once __DIR__ . '/db.php';
echo "DB Host: localhost\n";
echo "DB Name: u189092125_Blog\n";
echo "Server Info: " . $pdo->getAttribute(PDO::ATTR_SERVER_INFO) . "\n";
echo "Connection Status: " . ($pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)) . "\n";
$stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM cylinders");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Cylinders count: " . $row['cnt'] . "\n";
$stmt = $pdo->query("SELECT DATABASE() AS db");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Current DB: " . $row['db'] . "\n";
