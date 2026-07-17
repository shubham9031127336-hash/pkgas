<?php
/**
 * GSTN Export Engine — main dispatcher
 * Replaces exportGSTJSON() with official schema-compliant builders.
 * Never stores JSON permanently — always regenerates from live data.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/builder-gstr1.php';
require_once __DIR__ . '/builder-gstr3b.php';

/**
 * Main export function — replaces exportGSTJSON()
 * @param PDO   $pdo
 * @param int   $return_id
 * @return string|null JSON string or null on failure
 */
function gstnExport($pdo, $return_id) {
    // Fetch return record
    $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE id = ?");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch();
    if (!$return) return null;

    // GSTIN from filing config
    $filing_cfg = gstnGetFilingConfig($pdo, $return['business_key']);
    $gstin = $filing_cfg['gstin'] ?? '';

    // Filing period
    $fp = gstnPeriod($return['gst_period']);

    // Fetch return items
    $items = $pdo->prepare("SELECT * FROM gst_return_items WHERE gst_return_id = ? ORDER BY section, id");
    $items->execute([$return_id]);
    $all_items = $items->fetchAll();

    // Delegate to schema-specific builder
    $type = $return['return_type'];
    if ($type === 'gstr1') {
        return gstnBuildGSTR1($gstin, $fp, $all_items);
    } elseif ($type === 'gstr3b') {
        return gstnBuildGSTR3B($gstin, $fp, $return['summary_data'], $all_items);
    }

    return json_encode([
        'error'   => true,
        'message' => 'Unsupported return type: ' . $type,
    ], JSON_PRETTY_PRINT);
}

/**
 * Get GST filing config for a business key (cached)
 */
function gstnGetFilingConfig($pdo, $business_key) {
    static $cache = [];
    $key = $business_key ?: 'default';
    if (!isset($cache[$key])) {
        try {
            $stmt = $pdo->prepare("SELECT bc.*, bc.business_name AS legal_name, bc.label AS trade_name FROM business_config bc WHERE bc.business_key = ?");
            $stmt->execute([$business_key]);
            $cache[$key] = $stmt->fetch() ?: [];
        } catch (PDOException $e) {
            $cache[$key] = [];
        }
    }
    return $cache[$key];
}
