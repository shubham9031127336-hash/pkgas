<?php
/**
 * GSTR-1 JSON Builder — official GSTN specification
 * All sections: b2b, b2cl, b2cs, nil, hsn, doc_issue
 * Built via mapper DTOs — never constructs JSON directly.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/mapper.php';
require_once __DIR__ . '/validator.php';

/**
 * Build official GSTR-1 JSON from return items
 * @param string $gstin  15-char GSTIN
 * @param string $fp     Filing period MMYYYY
 * @param array  $items  Rows from gst_return_items table
 * @return string        JSON string (or error JSON)
 */
function gstnBuildGSTR1($gstin, $fp, $items) {
    // Validate first
    $validation = gstnValidateExport($items, 'gstr1');
    if (!$validation['valid']) {
        return json_encode([
            'error'           => true,
            'message'         => 'Validation failed. Export blocked.',
            'validation_errors' => $validation['errors'],
        ], JSON_PRETTY_PRINT);
    }

    // Section accumulators
    $b2b     = [];
    $b2cl    = [];
    $b2cs    = [];
    $nil_inv = [];
    $hsn_map = [];
    $ctin_map = [];

    $total_turnover = 0;

    foreach ($items as $item) {
        $section  = $item['section'] ?? '';
        $taxable  = floatval($item['taxable_value'] ?? 0);
        $rate     = floatval($item['gst_rate'] ?? 0);
        $gst      = floatval($item['total_gst'] ?? 0);
        $cgst     = floatval($item['cgst'] ?? 0);
        $sgst     = floatval($item['sgst'] ?? 0);
        $igst     = floatval($item['igst'] ?? 0);
        $val      = floatval($item['total_value'] ?? ($taxable + $gst));

        $total_turnover += $val;

        // ── B2B / B2CL ──
        if ($section === 'b2b' || $section === 'b2cl') {
            $ctin  = $item['customer_gstin'] ?: 'URP';
            $pos   = intval($item['place_of_supply'] ?? 0);
            $inv_entry = gstnMapB2BInvoice($item);

            if ($section === 'b2cl') {
                $b2cl[] = $inv_entry;
            } else {
                if (!isset($ctin_map[$ctin])) {
                    $ctin_map[$ctin] = ['ctin' => $ctin, 'inv' => []];
                }
                $ctin_map[$ctin]['inv'][] = $inv_entry;
            }
        }

        // ── B2C (unregistered) ──
        elseif ($section === 'b2c') {
            $pos = intval($item['place_of_supply'] ?? 0);
            $b2cs[] = gstnMapB2CInvoice($item, true);
        }

        // ── NIL RATED ──
        elseif ($section === 'nil') {
            $nil_inv[] = [
                'inum' => gstnInvoiceNumber($item),
                'idt'  => gstnDate(gstnInvoiceDate($item)),
                'val'  => gstnRound($val),
            ];
        }

        // ── HSN SUMMARY ──
        elseif ($section === 'hsn') {
            $hsn = $item['hsn_code'] ?: '280440';
            $key = $hsn . '_' . $rate;
            if (!isset($hsn_map[$key])) {
                $hsn_map[$key] = [
                    'hsn_sc' => $hsn,
                    'desc'   => '',
                    'uqc'    => 'NOS',
                    'qty'    => 0,
                    'val'    => 0,
                    'txval'  => 0,
                    'iamt'   => 0,
                    'camt'   => 0,
                    'samt'   => 0,
                    'csamt'  => 0,
                ];
            }
            $hsn_map[$key]['qty']   += intval($item['qty'] ?? 1);
            $hsn_map[$key]['val']   += $val;
            $hsn_map[$key]['txval'] += $taxable;
            $hsn_map[$key]['iamt']  += $igst;
            $hsn_map[$key]['camt']  += $cgst;
            $hsn_map[$key]['samt']  += $sgst;
        }
    }

    // Convert CTIN map to indexed array
    foreach ($ctin_map as $entry) {
        $b2b[] = $entry;
    }

    // ── BUILD OFFICIAL JSON ──
    $json = [
        'gstin'  => $gstin,
        'fp'     => $fp,
        'gt'     => gstnRound($total_turnover),
        'cur_gt' => gstnRound($total_turnover),
    ];

    if (!empty($b2b))       $json['b2b']       = $b2b;
    if (!empty($b2cl))      $json['b2cl']      = $b2cl;
    if (!empty($b2cs))      $json['b2cs']      = $b2cs;

    if (!empty($nil_inv)) {
        $json['nil'] = [
            'inv'      => $nil_inv,
            'nil_amt'  => gstnRound(array_sum(array_column($nil_inv, 'val'))),
            'expt_amt' => 0,
            'ngsup_amt'=> 0,
        ];
    }

    if (!empty($hsn_map))   $json['hsn']        = array_values($hsn_map);
    if (!empty($b2b) || !empty($b2cl) || !empty($b2cs)) {
        $json['doc_issue'] = [
            'doc_num'  => count(array_filter($items, fn($i) => !in_array($i['section'] ?? '', ['hsn', 'outward_by_rate', 'itc_by_rate']))),
            'doc_type' => 'Invoices',
        ];
    }

    return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
