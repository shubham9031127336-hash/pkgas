<?php
/**
 * GSTN Schema — official constants, state codes, enumerations, period helpers
 * Matches latest GSTN JSON specification for GSTR-1 / GSTR-3B
 */

// ─── INDIAN STATE CODES (GSTN official) ──────────────────

function gstnStateCodes() {
    return [
    1 => 'JAMMU AND KASHMIR',
    2 => 'HIMACHAL PRADESH',
    3 => 'PUNJAB',
    4 => 'CHANDIGARH',
    5 => 'UTTARAKHAND',
    6 => 'HARYANA',
    7 => 'DELHI',
    8 => 'RAJASTHAN',
    9 => 'UTTAR PRADESH',
    10 => 'BIHAR',
    11 => 'SIKKIM',
    12 => 'ARUNACHAL PRADESH',
    13 => 'NAGALAND',
    14 => 'MANIPUR',
    15 => 'MIZORAM',
    16 => 'TRIPURA',
    17 => 'MEGHALAYA',
    18 => 'BIHAR',
    19 => 'WEST BENGAL',
    20 => 'JHARKHAND',
    21 => 'ODISHA',
    22 => 'CHHATTISGARH',
    23 => 'MADHYA PRADESH',
    24 => 'GUJARAT',
    25 => 'DAMAN AND DIU',
    26 => 'DADRA AND NAGAR HAVELI AND DAMAN AND DIU',
    27 => 'MAHARASHTRA',
    28 => 'ANDHRA PRADESH (BEFORE DIVISION)',
    29 => 'KARNATAKA',
    30 => 'GOA',
    31 => 'LAKSHADWEEP',
    32 => 'KERALA',
    33 => 'TAMIL NADU',
    34 => 'PUDUCHERRY',
    35 => 'ANDAMAN AND NICOBAR ISLANDS',
    36 => 'TELANGANA',
    37 => 'ANDHRA PRADESH',
    38 => 'LADAKH',
    39 => 'OTHER TERRITORY',
    97 => 'OTHER COUNTRY',
    ];
}

// ─── GSTIN REGEX (official format) ───────────────────────

define('GSTN_GSTIN_REGEX', '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/');

// ─── SUPPLY TYPES ────────────────────────────────────────

function gstnSplyTypes() { return ['INTRA', 'INTER']; }

// ─── RETURN PERIOD FORMAT ───────────────────────────────

/**
 * Convert GST period (MM-YYYY) to GSTN filing period (MMYYYY)
 */
function gstnPeriod($period_raw) {
    return str_replace('-', '', $period_raw);
}

/**
 * Get current financial year string (e.g. "26-27")
 */
function gstnFinancialYear($date = null) {
    $d = $date ? strtotime($date) : time();
    $y = intval(date('y', $d));
    $m = intval(date('m', $d));
    if ($m < 4) $y--;
    return sprintf('%02d-%02d', $y, $y + 1);
}

/**
 * Get GST period (MM-YYYY) from a date
 */
function gstnPeriodFromDate($date = null) {
    $d = $date ? strtotime($date) : time();
    return date('m-Y', $d);
}

/**
 * Parse MM-YYYY period into month and year integers
 */
function gstnParsePeriod($period) {
    $parts = explode('-', $period);
    if (count($parts) !== 2) return null;
    return ['month' => intval($parts[0]), 'year' => intval($parts[1])];
}

/**
 * Get month start/end dates for a period
 */
function gstnPeriodDateRange($period) {
    $p = gstnParsePeriod($period);
    if (!$p) return null;
    $start = sprintf('%04d-%02d-01', $p['year'], $p['month']);
    $end = date('Y-m-t', strtotime($start));
    return ['start' => $start, 'end' => $end];
}

// ─── GST RATE ENUMERATION ────────────────────────────────

function gstnRates() { return [0, 0.1, 1.5, 3, 5, 6, 7.5, 12, 18, 28]; }

/**
 * Validate GST rate against official slab
 */
function gstnValidRate($rate) {
    return in_array(floatval($rate), gstnRates(), true);
}

// ─── HSN / SAC HELPERS ───────────────────────────────────

/**
 * HSN code digit count required by turnover
 */
function gstnHsnDigits($turnover) {
    return $turnover > 50000000 ? 8 : 4;
}

/**
 * UQC codes (Unit Quantity Code) as per GSTN
 */
function gstnUqcCodes() { return [
    'BAG' => 'Bags',
    'BAL' => 'Bales',
    'BND' => 'Bundles',
    'BTL' => 'Bottles',
    'BOX' => 'Box',
    'CBM' => 'Cubic Meters',
    'CDM' => 'Cubic Decimeter',
    'CMS' => 'Centimeters',
    'CTN' => 'Cartons',
    'DOZ' => 'Dozen',
    'DRM' => 'Drum',
    'GMS' => 'Grams',
    'KGS' => 'Kilograms',
    'KLR' => 'Kilolitre',
    'KME' => 'Kilometre',
    'LTR' => 'Litres',
    'MLT' => 'Millilitre',
    'MTR' => 'Meters',
    'NOS' => 'Numbers',
    'OTH' => 'Others',
    'PAC' => 'Packs',
    'PAI' => 'Pairs',
    'PCS' => 'Pieces',
    'PRS' => 'Pairs',
    'QTL' => 'Quintal',
    'ROL' => 'Rolls',
    'SQF' => 'Square Feet',
    'SQM' => 'Square Meters',
    'SQY' => 'Square Yards',
    'TON' => 'Tonnes',
    'TBS' => 'Tablets',
    'UNT' => 'Units',
    'YDS' => 'Yards',
    ];
}

// ─── GSTN JSON STRUCTURE HELPERS ─────────────────────────

/**
 * Round to 2 decimal places (GSTN requirement)
 */
function gstnRound($val) {
    return round(floatval($val), 2);
}

/**
 * Check if a value is positive (for tax calculations)
 */
function gstnIsPositive($val) {
    return floatval($val) > 0.001;
}

/**
 * Format date to d-m-Y (GSTN standard)
 */
function gstnDate($date) {
    if (!$date || $date === '0000-00-00') return '';
    return date('d-m-Y', strtotime($date));
}

/**
 * Determine if invoice is intra-state or inter-state
 */
function gstnIsIntraState($cust_state_code, $biz_state_code) {
    return intval($cust_state_code) === intval($biz_state_code);
}

/**
 * Get supply type string for GSTN
 */
function gstnSupplyType($is_intra) {
    return $is_intra ? 'INTRA' : 'INTER';
}
