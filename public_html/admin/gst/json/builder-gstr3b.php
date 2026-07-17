<?php
/**
 * GSTR-3B JSON Builder — official GSTN specification
 * Computes tax split (CGST/SGST/IGST) from return items and summary data.
 */

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/mapper.php';

/**
 * Build official GSTR-3B JSON from return summary and items
 * @param string $gstin        15-char GSTIN
 * @param string $fp           Filing period MMYYYY
 * @param string $summary_json JSON string from gst_returns.summary_data
 * @param array  $items        Rows from gst_return_items (for rate-wise breakdown)
 * @return string              JSON string
 */
function gstnBuildGSTR3B($gstin, $fp, $summary_json, $items = []) {
    $summary = $summary_json ? json_decode($summary_json, true) : [];

    // Extract rate-wise breakdowns from items if available
    $outward_by_rate = [];
    $itc_by_rate = [];
    foreach ($items as $it) {
        $sec = $it['section'] ?? '';
        if ($sec === 'outward_by_rate') {
            $r = floatval($it['gst_rate'] ?? 0);
            $outward_by_rate[$r] = [
                'taxable' => floatval($it['taxable_value'] ?? 0),
                'gst'     => floatval($it['total_gst'] ?? 0),
                'cgst'    => floatval($it['cgst'] ?? 0),
                'sgst'    => floatval($it['sgst'] ?? 0),
                'igst'    => floatval($it['igst'] ?? 0),
            ];
        } elseif ($sec === 'itc_by_rate') {
            $r = floatval($it['gst_rate'] ?? 0);
            $itc_by_rate[$r] = [
                'taxable' => floatval($it['taxable_value'] ?? 0),
                'gst'     => floatval($it['total_gst'] ?? 0),
                'cgst'    => floatval($it['cgst'] ?? 0),
                'sgst'    => floatval($it['sgst'] ?? 0),
                'igst'    => floatval($it['igst'] ?? 0),
            ];
        }
    }

    // Compute outward tax split by rate
    $osup_camt = 0; $osup_samt = 0; $osup_iamt = 0;
    $osup_txval = floatval($summary['outward_taxable_supplies'] ?? 0);
    foreach ($outward_by_rate as $r => $d) {
        $osup_camt += $d['cgst'];
        $osup_samt += $d['sgst'];
        $osup_iamt += $d['igst'];
    }

    // Compute ITC split by rate
    $itc_camt = 0; $itc_samt = 0; $itc_iamt = 0;
    foreach ($itc_by_rate as $r => $d) {
        $itc_camt += $d['cgst'];
        $itc_samt += $d['sgst'];
        $itc_iamt += $d['igst'];
    }

    $itc_total = $itc_camt + $itc_samt + $itc_iamt;
    $nil_supplies = floatval($summary['nil_supplies'] ?? 0);
    $exempt_supplies = floatval($summary['exempt_supplies'] ?? 0);
    $reverse_charge = floatval($summary['reverse_charge'] ?? 0);
    $itc_carry = floatval($summary['itc_carry_forward_opening'] ?? 0);
    $total_itc = $itc_total + $itc_carry;

    $outward_gst = floatval($summary['outward_gst'] ?? ($osup_camt + $osup_samt + $osup_iamt));
    $net_liability = max(0, $outward_gst - $total_itc);
    $carry_forward = max(0, $total_itc - $outward_gst);

    $json = [
        'gstin' => $gstin,
        'fp'    => $fp,
        'sup_details' => [
            'osup_det' => [
                'txval' => gstnRound($osup_txval),
                'iamt'  => gstnRound($osup_iamt),
                'camt'  => gstnRound($osup_camt),
                'samt'  => gstnRound($osup_samt),
            ],
            'osup_zero' => [
                'txval' => gstnRound(floatval($summary['zero_rated_supplies'] ?? 0)),
                'iamt'  => 0,
                'camt'  => 0,
                'samt'  => 0,
            ],
            'osup_nil_exmp' => [
                'txval' => gstnRound($nil_supplies + $exempt_supplies),
                'iamt'  => 0,
                'camt'  => 0,
                'samt'  => 0,
            ],
            'isup_rev' => [
                'txval' => gstnRound($reverse_charge),
                'iamt'  => 0,
                'camt'  => 0,
                'samt'  => 0,
            ],
            'osup_ng' => [
                'txval' => 0,
                'iamt'  => 0,
                'camt'  => 0,
                'samt'  => 0,
            ],
        ],
        'itc_elg' => [
            'itc_avl' => [
                'iamt'  => gstnRound($itc_iamt),
                'camt'  => gstnRound($itc_camt),
                'samt'  => gstnRound($itc_samt),
                'csamt' => 0,
            ],
            'itc_rev' => ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0],
            'itc_net' => [
                'iamt'  => gstnRound($itc_iamt),
                'camt'  => gstnRound($itc_camt),
                'samt'  => gstnRound($itc_samt),
                'csamt' => 0,
            ],
            'itc_inelg' => ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0],
        ],
        'intr_ltfee' => [
            'intr_details'  => gstnRound(floatval($summary['interest'] ?? 0)),
            'lt_fee_details'=> gstnRound(floatval($summary['late_fee'] ?? 0)),
        ],
    ];

    return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
