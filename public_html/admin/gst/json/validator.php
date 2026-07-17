<?php
/**
 * GSTN Pre-Export Validation Engine
 * Validates return items against official GSTN rules before JSON generation.
 */

require_once __DIR__ . '/schema.php';

/**
 * Validate return items for JSON export
 * Returns array of errors. Empty array = valid.
 */
function gstnValidateItems($items, $return_type) {
    $errors = [];
    $seen_invoices = [];

    foreach ($items as $i => $item) {
        $inv_no = $item['invoice_number'] ?? ('#' . $item['reference_id']);
        $ref = $item['reference_type'] . '_' . $item['reference_id'];
        $line = $i + 1;

        // Skip summary rows
        if (in_array($item['section'] ?? '', ['hsn', 'outward_by_rate', 'itc_by_rate', 'doc_issue'])) {
            continue;
        }

        // 1. Duplicate invoice check
        if (isset($seen_invoices[$ref])) {
            $errors[] = ['line' => $line, 'type' => 'duplicate_invoice', 'field' => 'reference_id', 'value' => $ref, 'msg' => "Duplicate invoice reference: $ref"];
        }
        $seen_invoices[$ref] = true;

        // 2. Invoice number
        if (empty($item['invoice_number'])) {
            $errors[] = ['line' => $line, 'type' => 'missing_invoice_number', 'field' => 'invoice_number', 'value' => '', 'msg' => "Missing invoice number for reference $ref"];
        }

        // 3. Invoice date
        if (empty($item['invoice_date']) && empty($item['transaction_date'])) {
            $errors[] = ['line' => $line, 'type' => 'missing_invoice_date', 'field' => 'invoice_date', 'value' => '', 'msg' => "Missing invoice date for $inv_no"];
        }

        // 4. GSTIN validation (B2B only)
        if ($item['section'] === 'b2b') {
            $gstin = trim($item['customer_gstin'] ?? '');
            if (empty($gstin)) {
                $errors[] = ['line' => $line, 'type' => 'missing_gstin', 'field' => 'customer_gstin', 'value' => '', 'msg' => "Missing GSTIN for B2B invoice $inv_no"];
            } elseif (!preg_match(GSTN_GSTIN_REGEX, strtoupper($gstin))) {
                $errors[] = ['line' => $line, 'type' => 'invalid_gstin', 'field' => 'customer_gstin', 'value' => $gstin, 'msg' => "Invalid GSTIN format for $inv_no: $gstin"];
            }
        }

        // 5. Missing HSN
        if (empty($item['hsn_code'])) {
            $errors[] = ['line' => $line, 'type' => 'missing_hsn', 'field' => 'hsn_code', 'value' => '', 'msg' => "Missing HSN code for $inv_no"];
        }

        // 6. Missing place of supply
        $pos = intval($item['place_of_supply'] ?? 0);
        if ($pos <= 0) {
            $errors[] = ['line' => $line, 'type' => 'missing_pos', 'field' => 'place_of_supply', 'value' => (string)$pos, 'msg' => "Missing Place of Supply for $inv_no"];
        }

        // 7. Rate validation
        $rate = floatval($item['gst_rate'] ?? 0);
        $taxable = floatval($item['taxable_value'] ?? 0);
        if ($taxable > 0 && $rate <= 0) {
            $errors[] = ['line' => $line, 'type' => 'missing_gst_rate', 'field' => 'gst_rate', 'value' => (string)$rate, 'msg' => "Missing or zero GST rate for taxable invoice $inv_no"];
        }

        // 8. CGST+SGST+IGST split
        $gst = floatval($item['total_gst'] ?? 0);
        $cgst = floatval($item['cgst'] ?? 0);
        $sgst = floatval($item['sgst'] ?? 0);
        $igst = floatval($item['igst'] ?? 0);
        if ($gst > 0 && abs(($cgst + $sgst + $igst) - $gst) > 0.02) {
            $errors[] = ['line' => $line, 'type' => 'gst_split_mismatch', 'field' => 'cgst_sgst_igst', 'value' => "$cgst,$sgst,$igst", 'msg' => "CGST+SGST+IGST ($cgst+$sgst+$igst) != Total GST ($gst) for $inv_no"];
        }

        // 9. Value mismatch
        $total_val = floatval($item['total_value'] ?? 0);
        $expected_total = $taxable + $gst;
        if ($taxable > 0 && abs($total_val - $expected_total) > 0.02) {
            $errors[] = ['line' => $line, 'type' => 'value_mismatch', 'field' => 'total_value', 'value' => (string)$total_val, 'msg' => "Total value ($total_val) != Taxable ($taxable) + GST ($gst) for $inv_no"];
        }

        // 10. Negative values
        if ($taxable < 0) {
            $errors[] = ['line' => $line, 'type' => 'negative_taxable', 'field' => 'taxable_value', 'value' => (string)$taxable, 'msg' => "Negative taxable value for $inv_no"];
        }
        if ($gst < 0) {
            $errors[] = ['line' => $line, 'type' => 'negative_gst', 'field' => 'total_gst', 'value' => (string)$gst, 'msg' => "Negative GST amount for $inv_no"];
        }
    }

    return $errors;
}

/**
 * Block export if critical errors found
 * Returns: ['valid' => bool, 'errors' => array]
 */
function gstnValidateExport($items, $return_type) {
    $errors = gstnValidateItems($items, $return_type);
    $critical_count = count($errors);
    return [
        'valid'  => $critical_count === 0,
        'count'  => $critical_count,
        'errors' => $errors,
    ];
}
