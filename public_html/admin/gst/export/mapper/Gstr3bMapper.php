<?php

require_once __DIR__ . '/MapperInterface.php';
require_once __DIR__ . '/../dto/Gstr3bDto.php';
require_once __DIR__ . '/../dto/Gstr3bOutwardSupply.php';
require_once __DIR__ . '/../dto/Gstr3bItcSummary.php';
require_once __DIR__ . '/../dto/Gstr3bPayment.php';

class Gstr3bMapper implements MapperInterface {

    public function map(PDO $pdo, string $businessKey, array $period): object {
        $monthStart = sprintf('%04d-%02d-01', $period['year'], $period['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $filingCfg = $this->getFilingConfig($pdo, $businessKey);
        $gstin = $filingCfg['gstin'] ?? '';

        $dto = new Gstr3bDto();
        $dto->gstin = $gstin;
        $dto->fp = sprintf('%02d%04d', $period['month'], $period['year']);

        // --- Outward supplies from gst_ledger ---
        $outputEntries = $this->fetchOutputEntries($pdo, $monthStart, $monthEnd);

        $outwardTaxable = 0;
        $nilTaxable = 0;
        $outwardIamt = 0; $outwardCamt = 0; $outwardSamt = 0;

        foreach ($outputEntries as $e) {
            $rate = floatval($e['gst_rate']);
            $taxable = floatval($e['taxable_amount']);
            $iamt = floatval($e['igst']);
            $camt = floatval($e['cgst']);
            $samt = floatval($e['sgst']);

            if ($rate == 0) {
                $nilTaxable += $taxable;
                continue;
            }

            $outwardTaxable += $taxable;
            $outwardIamt += $iamt;
            $outwardCamt += $camt;
            $outwardSamt += $samt;
        }

        $dto->sup_details->osup_det->txval = round($outwardTaxable, 2);
        $dto->sup_details->osup_det->iamt = round($outwardIamt, 2);
        $dto->sup_details->osup_det->camt = round($outwardCamt, 2);
        $dto->sup_details->osup_det->samt = round($outwardSamt, 2);
        $dto->sup_details->osup_nil_exmp->txval = round($nilTaxable, 2);

        // --- ITC from gst_ledger input ---
        $inputEntries = $this->fetchInputEntries($pdo, $monthStart, $monthEnd);

        $itcIamt = 0; $itcCamt = 0; $itcSamt = 0;

        foreach ($inputEntries as $e) {
            $rate = floatval($e['gst_rate']);
            if ($rate <= 0) continue;
            $itcIamt += floatval($e['igst']);
            $itcCamt += floatval($e['cgst']);
            $itcSamt += floatval($e['sgst']);
        }

        // ITC carry-forward from previous settlement
        $itcCarry = 0;
        try {
            $stmt = $pdo->prepare("SELECT itc_closing FROM gst_settlements WHERE business_key=? AND settlement_month < ? ORDER BY settlement_month DESC LIMIT 1");
            $stmt->execute([$businessKey, $monthStart]);
            $itcCarry = floatval($stmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {}

        $totalItcIamt = $itcIamt;
        $totalItcCamt = $itcCamt + round($itcCarry / 2, 2);
        $totalItcSamt = $itcSamt + ($itcCarry - round($itcCarry / 2, 2));

        $dto->itc_elg->itc_avl->iamt = round($totalItcIamt, 2);
        $dto->itc_elg->itc_avl->camt = round($totalItcCamt, 2);
        $dto->itc_elg->itc_avl->samt = round($totalItcSamt, 2);

        $dto->itc_elg->itc_net->iamt = round($totalItcIamt, 2);
        $dto->itc_elg->itc_net->camt = round($totalItcCamt, 2);
        $dto->itc_elg->itc_net->samt = round($totalItcSamt, 2);

        return $dto;
    }

    private function fetchOutputEntries(PDO $pdo, string $monthStart, string $monthEnd): array {
        $stmt = $pdo->prepare("
            SELECT gl.*
            FROM gst_ledger gl
            WHERE gl.input_output_type='output'
              AND gl.transaction_date >= ? AND gl.transaction_date <= ?
        ");
        $stmt->execute([$monthStart, $monthEnd]);
        return $stmt->fetchAll();
    }

    private function fetchInputEntries(PDO $pdo, string $monthStart, string $monthEnd): array {
        $stmt = $pdo->prepare("
            SELECT gl.*
            FROM gst_ledger gl
            WHERE gl.input_output_type='input'
              AND gl.transaction_date >= ? AND gl.transaction_date <= ?
        ");
        $stmt->execute([$monthStart, $monthEnd]);
        return $stmt->fetchAll();
    }

    private function getFilingConfig(PDO $pdo, string $businessKey): array {
        try {
            $stmt = $pdo->prepare("SELECT bc.*, bc.business_name AS legal_name, bc.label AS trade_name FROM business_config bc WHERE bc.business_key = ?");
            $stmt->execute([$businessKey]);
            return $stmt->fetch() ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }
}
