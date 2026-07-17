<?php
/**
 * GST JSON Export Endpoint
 * Uses the new layered export engine (DTO → Mapper → Schema Adapter)
 * Compliant with official GSTN JSON specifications.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_login();
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gst/export/GstExportEngine.php';

while (ob_get_level()) ob_end_clean();

$return_id = intval($_GET['return_id'] ?? 0);
if ($return_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Missing return_id parameter');
}

try {
    $engine = new GstExportEngine();
    $json = $engine->export($pdo, $return_id);
} catch (RuntimeException $e) {
    http_response_code(409);
    header('Content-Type: text/plain');
    die('Export failed: ' . $e->getMessage());
}

// Fetch return metadata for filename
$stmt = $pdo->prepare("SELECT return_number, return_type FROM gst_returns WHERE id = ?");
$stmt->execute([$return_id]);
$ret = $stmt->fetch();

$filename = $ret ? ($ret['return_number'] . '.json') : ('gst_return_' . $return_id . '.json');

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $json;
exit();
