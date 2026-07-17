<?php

require_once __DIR__ . '/ValidatorInterface.php';
require_once __DIR__ . '/ValidationResult.php';

class Gstr1Validator implements ValidatorInterface {

    public function validate(PDO $pdo, int $returnId): ValidationResult {
        $result = new ValidationResult();

        $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE id = ?");
        $stmt->execute([$returnId]);
        $return = $stmt->fetch();
        if (!$return) {
            $result->addError('return_not_found', "Return #$returnId not found");
            return $result;
        }

        // Fetch return items
        $items = $pdo->prepare("SELECT ri.*, ro.order_date, ro.reverse_charge FROM gst_return_items ri LEFT JOIN refill_orders ro ON ri.reference_type='refill_order' AND ri.reference_id=ro.id WHERE ri.gst_return_id = ? AND ri.section NOT IN ('hsn', 'outward_by_rate', 'itc_by_rate')");
        $items->execute([$returnId]);
        $rows = $items->fetchAll();

        $seen = [];

        foreach ($rows as $item) {
            $invNo = $item['invoice_number'] ?? '';
            $refKey = $item['reference_type'] . '_' . $item['reference_id'];
            $dedupKey = $invNo ?: $refKey;

            // 1. Duplicate invoice (by invoice_number, not reference_id — multi-line invoices share reference_id)
            if (!empty($invNo) && isset($seen[$dedupKey])) {
                $result->addWarning('duplicate_invoice', "Duplicate invoice number: $invNo", $item['reference_type'], $item['reference_id'], 'invoice_number', $invNo);
            }
            $seen[$dedupKey] = true;

            // 2. Invalid GSTIN
            $gstin = trim($item['customer_gstin'] ?? '');
            if (!empty($gstin) && !$this->validateGstin($gstin)) {
                $result->addWarning('invalid_gstin', "Invalid GSTIN: $gstin", $item['reference_type'], $item['reference_id'], 'customer_gstin', $gstin);
            }

            // 3. Missing HSN
            if (empty($item['hsn_code'])) {
                $result->addError('missing_hsn', "Missing HSN for invoice $invNo", $item['reference_type'], $item['reference_id'], 'hsn_code', '');
            }

            // 4. Missing Place of Supply
            $pos = intval($item['place_of_supply'] ?? 0);
            if ($pos === 0) {
                $result->addWarning('missing_pos', "Missing Place of Supply for invoice $invNo", $item['reference_type'], $item['reference_id'], 'place_of_supply', '0');
            }

            // 5. Missing GST rate
            $rate = floatval($item['gst_rate'] ?? 0);
            $taxable = floatval($item['taxable_value'] ?? 0);
            if ($rate <= 0 && $taxable > 0) {
                $result->addWarning('missing_gst_rate', "Missing GST rate for taxable invoice $invNo", $item['reference_type'], $item['reference_id'], 'gst_rate', (string)$rate);
            }

            // 6. CGST+SGST+IGST split mismatch
            $gst = floatval($item['total_gst'] ?? 0);
            $cgst = floatval($item['cgst'] ?? 0);
            $sgst = floatval($item['sgst'] ?? 0);
            $igst = floatval($item['igst'] ?? 0);
            if ($gst > 0 && abs(($cgst + $sgst + $igst) - $gst) > 0.01) {
                $result->addError('gst_split_mismatch', "CGST+SGST+IGST ({$cgst}+{$sgst}+{$igst}) != Total GST ($gst) for invoice $invNo", $item['reference_type'], $item['reference_id'], 'cgst_sgst_igst', "$cgst,$sgst,$igst");
            }

            // 7. Total value mismatch
            $totalVal = floatval($item['total_value'] ?? 0);
            if ($taxable > 0 && $totalVal > 0 && abs($totalVal - ($taxable + $gst)) > 0.01) {
                $result->addError('value_mismatch', "Total ($totalVal) != Taxable ($taxable) + GST ($gst) for invoice $invNo", $item['reference_type'], $item['reference_id'], 'total_value', (string)$totalVal);
            }
        }

        return $result;
    }

    private function validateGstin(string $gstin): bool {
        return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', strtoupper(trim($gstin)));
    }
}
