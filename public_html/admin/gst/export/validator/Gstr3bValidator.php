<?php

require_once __DIR__ . '/ValidatorInterface.php';
require_once __DIR__ . '/ValidationResult.php';

class Gstr3bValidator implements ValidatorInterface {

    public function validate(PDO $pdo, int $returnId): ValidationResult {
        $result = new ValidationResult();

        $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE id = ?");
        $stmt->execute([$returnId]);
        $return = $stmt->fetch();
        if (!$return) {
            $result->addError('return_not_found', "Return #$returnId not found");
            return $result;
        }

        // Parse period
        $parts = explode('-', $return['gst_period']);
        if (count($parts) !== 2) {
            $result->addError('invalid_period', "Invalid period format: {$return['gst_period']}");
            return $result;
        }
        $mStart = sprintf('%04d-%02d-01', intval($parts[1]), intval($parts[0]));
        $mEnd = date('Y-m-t', strtotime($mStart));

        // Check for unsynced vendor purchases
        try {
            $batchSt = $pdo->prepare("SELECT id, invoice_number, gst_amount FROM vendor_refill_batches WHERE DATE(received_date) >= ? AND DATE(received_date) <= ? AND gst_amount > 0");
            $batchSt->execute([$mStart, $mEnd]);
            while ($b = $batchSt->fetch()) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE reference_type='vendor_refill_batch' AND reference_id=?");
                $chk->execute([$b['id']]);
                if (intval($chk->fetchColumn()) === 0) {
                    $result->addWarning('unsynced_purchase', "Vendor batch #{$b['id']} ({$b['invoice_number']}) has GST Rs{$b['gst_amount']} but not in GST ledger", 'vendor_refill_batch', $b['id'], 'reference_id', (string)$b['id']);
                }
            }
        } catch (PDOException $e) {
            error_log("GSTR3B validator batch check: " . $e->getMessage());
        }

        try {
            $viSt = $pdo->prepare("SELECT id, invoice_number, gst_amount FROM vendor_invoices WHERE DATE(invoice_date) >= ? AND DATE(invoice_date) <= ? AND gst_amount > 0");
            $viSt->execute([$mStart, $mEnd]);
            while ($vi = $viSt->fetch()) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE reference_type='vendor_invoice' AND reference_id=?");
                $chk->execute([$vi['id']]);
                if (intval($chk->fetchColumn()) === 0) {
                    $result->addWarning('unsynced_purchase', "Vendor invoice #{$vi['id']} ({$vi['invoice_number']}) has GST Rs{$vi['gst_amount']} but not in GST ledger", 'vendor_invoice', $vi['id'], 'reference_id', (string)$vi['id']);
                }
            }
        } catch (PDOException $e) {
            error_log("GSTR3B validator invoice check: " . $e->getMessage());
        }

        return $result;
    }
}
