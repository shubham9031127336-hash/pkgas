<?php
/**
 * GSTN Data Mapper — maps ERP data into GSTN DTO arrays
 * Business logic never touches JSON structure directly.
 */

require_once __DIR__ . '/schema.php';

// ─── INVOICE ITEM DTO ────────────────────────────────────

/**
 * Map a single invoice item to GSTN item detail DTO
 */
function gstnMapItemDetail($taxable, $rate, $cgst, $sgst, $igst, $csamt = 0) {
    return [
        'txval' => gstnRound($taxable),
        'rt'    => gstnRound($rate),
        'iamt'  => gstnRound($igst),
        'camt'  => gstnRound($cgst),
        'samt'  => gstnRound($sgst),
        'csamt' => gstnRound($csamt),
    ];
}

// ─── INVOICE DTO ─────────────────────────────────────────

/**
 * Map an ERP invoice row to a GSTN invoice DTO
 * @param array $item - row from gst_return_items
 * @param string $invoice_number
 * @param string $invoice_date - Y-m-d
 */
function gstnMapInvoice($item, $invoice_number, $invoice_date) {
    $taxable = floatval($item['taxable_value'] ?? 0);
    $rate    = floatval($item['gst_rate'] ?? 0);
    $cgst    = floatval($item['cgst'] ?? 0);
    $sgst    = floatval($item['sgst'] ?? 0);
    $igst    = floatval($item['igst'] ?? 0);
    $total   = floatval($item['total_value'] ?? 0);

    return [
        'inum' => $invoice_number,
        'idt'  => gstnDate($invoice_date),
        'val'  => gstnRound($total ?: ($taxable + $cgst + $sgst + $igst)),
        'itms' => [[
            'num'     => 1,
            'itm_det' => gstnMapItemDetail($taxable, $rate, $cgst, $sgst, $igst),
        ]],
    ];
}

/**
 * Map an order row to invoice number
 */
function gstnInvoiceNumber($item) {
    return $item['invoice_number'] ?? ('INV-' . str_pad($item['reference_id'] ?? 0, 4, '0', STR_PAD_LEFT));
}

/**
 * Map an order row to invoice date
 */
function gstnInvoiceDate($item) {
    return $item['invoice_date'] ?? $item['transaction_date'] ?? date('Y-m-d');
}

// ─── B2B INVOICE DTO (registered customer) ───────────────

/**
 * Map a B2B return item to a B2B invoice entry
 */
function gstnMapB2BInvoice($item) {
    $inv = gstnMapInvoice(
        $item,
        gstnInvoiceNumber($item),
        gstnInvoiceDate($item)
    );
    $inv['pos']  = intval($item['place_of_supply'] ?? 0);
    $inv['rchg'] = 'N';
    $inv['etin'] = '';
    return $inv;
}

// ─── B2C INVOICE DTO (unregistered customer) ─────────────

/**
 * Map a B2C return item to a B2C invoice entry
 */
function gstnMapB2CInvoice($item, $is_intra) {
    $inv = [
        'inum' => gstnInvoiceNumber($item),
        'idt'  => gstnDate(gstnInvoiceDate($item)),
        'val'  => gstnRound(floatval($item['total_value'] ?? 0)),
    ];
    return [
        'sply_ty' => gstnSupplyType($is_intra),
        'typ'     => 'OE',
        'etin'    => '',
        'pos'     => intval($item['place_of_supply'] ?? 0),
        'inv'     => [$inv],
    ];
}

// ─── ITC BREAKDOWN DTO ───────────────────────────────────

/**
 * Map ITC by rate from stored summary items to GSTN ITC structure
 */
function gstnMapITCByRate($items, $key = 'camt') {
    $total = 0;
    foreach ($items as $it) {
        $total += floatval($it[$key] ?? 0);
    }
    return gstnRound($total);
}

// ─── HSN SUMMARY DTO ─────────────────────────────────────

/**
 * Map HSN summary items to GSTN HSN structure
 */
function gstnMapHsn($hsn_items) {
    $hsn_map = [];
    foreach ($hsn_items as $item) {
        $hsn = $item['hsn_code'] ?: '280440';
        $rate = floatval($item['gst_rate'] ?? 0);
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
        $hsn_map[$key]['val']   += floatval($item['total_value'] ?? 0);
        $hsn_map[$key]['txval'] += floatval($item['taxable_value'] ?? 0);
        $hsn_map[$key]['iamt']  += floatval($item['igst'] ?? 0);
        $hsn_map[$key]['camt']  += floatval($item['cgst'] ?? 0);
        $hsn_map[$key]['samt']  += floatval($item['sgst'] ?? 0);
    }
    return array_values($hsn_map);
}
