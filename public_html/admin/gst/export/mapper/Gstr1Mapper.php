<?php

require_once __DIR__ . '/MapperInterface.php';
require_once __DIR__ . '/../dto/Gstr1Dto.php';
require_once __DIR__ . '/../dto/Gstr1B2bEntry.php';
require_once __DIR__ . '/../dto/Gstr1B2clEntry.php';
require_once __DIR__ . '/../dto/Gstr1B2csEntry.php';
require_once __DIR__ . '/../dto/Gstr1CdnrEntry.php';
require_once __DIR__ . '/../dto/Gstr1HsnEntry.php';
require_once __DIR__ . '/../dto/Gstr1NilEntry.php';
require_once __DIR__ . '/../dto/Gstr1DocIssue.php';

class Gstr1Mapper implements MapperInterface {

    public function map(PDO $pdo, string $businessKey, array $period): object {
        $monthStart = sprintf('%04d-%02d-01', $period['year'], $period['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $filingCfg = $this->getFilingConfig($pdo, $businessKey);
        $bizState = intval($filingCfg['state_code'] ?? 0);
        $gstin = $filingCfg['gstin'] ?? '';

        // Fetch eligible orders
        $orders = $this->fetchOrders($pdo, $businessKey, $monthStart, $monthEnd);

        $dto = new Gstr1Dto();
        $dto->gstin = $gstin;
        $dto->fp = sprintf('%02d%04d', $period['month'], $period['year']);

        $ctinMap = [];
        $b2clMap = [];
        $b2csList = [];
        $nilList = [];
        $hsnMap = [];
        $totalTurnover = 0;
        $docCount = 0;
        $b2bTaxable = 0;
        $b2bGst = 0;
        $b2cTaxable = 0;
        $b2cGst = 0;

        foreach ($orders as $order) {
            $items = $this->fetchOrderItems($pdo, $order['id']);
            if (empty($items)) continue;

            $customerGstin = trim($order['customer_gst'] ?? '');
            $customerState = intval($order['customer_state'] ?? 0);
            $placeOfSupply = $customerState > 0 ? $customerState : $bizState;
            $hasGstin = !empty($customerGstin) && $this->validateGstin($customerGstin);
            $isInterState = ($customerState > 0 && $bizState > 0 && $customerState !== $bizState);
            $isB2b = $hasGstin;

            $invNum = $order['invoice_number'] ?? ('INV-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT));
            $invDate = date('d-m-Y', strtotime($order['order_date']));
            $rchg = ($order['reverse_charge'] ?? 'N') === 'Y' ? 'Y' : 'N';

            $orderVal = 0;
            $orderTaxable = 0;
            $orderGst = 0;
            $orderCgst = 0;
            $orderSgst = 0;
            $orderIgst = 0;
            $itemEntries = [];
            $isNilRated = true;

            foreach ($items as $idx => $item) {
                $rate = floatval($item['gst_rate'] ?? 0);
                $taxable = floatval($item['taxable_amount'] ?? 0);
                if ($taxable <= 0) {
                    $taxable = floatval($item['price_per_unit'] ?? 0) * intval($item['qty'] ?? 1);
                    if (intval($item['is_rental'] ?? 0) === 2) $taxable += floatval($item['sell_price'] ?? 0);
                }
                $gst = floatval($item['gst_amount'] ?? 0);
                $qty = intval($item['qty'] ?? 1);

                if ($rate > 0) $isNilRated = false;

                if ($isInterState && $rate > 0) {
                    $iamt = $gst;
                    $camt = 0.00;
                    $samt = 0.00;
                } else {
                    $iamt = 0.00;
                    $camt = $rate > 0 ? round($gst / 2, 2) : 0.00;
                    $samt = $rate > 0 ? $gst - $camt : 0.00;
                }

                $totalVal = $taxable + $gst;
                $orderVal += $totalVal;
                $orderTaxable += $taxable;
                $orderGst += $gst;
                $orderCgst += $camt;
                $orderSgst += $samt;
                $orderIgst += $iamt;

                $num = $idx + 1;
                $itemEntry = [
                    'num' => $num,
                    'itm_det' => [
                        'txval' => round($taxable, 2),
                        'rt' => round($rate, 2),
                        'iamt' => round($iamt, 2),
                        'camt' => round($camt, 2),
                        'samt' => round($samt, 2),
                        'csamt' => 0,
                    ]
                ];
                $itemEntries[] = $itemEntry;

                // HSN summary
                $hsn = $item['hsn_code'] ?? $item['gas_hsn'] ?? $item['product_hsn'] ?? '280440';
                $hsnKey = $hsn . '_' . $rate;
                if (!isset($hsnMap[$hsnKey])) {
                    $hsnMap[$hsnKey] = ['hsn' => $hsn, 'rate' => $rate, 'qty' => 0, 'taxable' => 0, 'gst' => 0, 'iamt' => 0, 'camt' => 0, 'samt' => 0, 'val' => 0];
                }
                $hsnMap[$hsnKey]['qty'] += $qty;
                $hsnMap[$hsnKey]['taxable'] += $taxable;
                $hsnMap[$hsnKey]['gst'] += $gst;
                $hsnMap[$hsnKey]['iamt'] += $iamt;
                $hsnMap[$hsnKey]['camt'] += $camt;
                $hsnMap[$hsnKey]['samt'] += $samt;
                $hsnMap[$hsnKey]['val'] += $totalVal;
            }

            $totalTurnover += $orderVal;

            if ($isNilRated) {
                $nilList[] = new Gstr1NilInvoice(['inum' => $invNum, 'idt' => $invDate, 'val' => round($orderVal, 2)]);
                $docCount++;
                continue;
            }

            if ($isB2b) {
                // B2B — group by CTIN
                if (!isset($ctinMap[$customerGstin])) {
                    $ctinMap[$customerGstin] = ['ctin' => $customerGstin, 'inv' => []];
                }
                $invEntry = [
                    'inum' => $invNum,
                    'idt' => $invDate,
                    'val' => round($orderVal, 2),
                    'pos' => $placeOfSupply,
                    'rchg' => $rchg,
                    'itms' => $itemEntries,
                ];
                $ctinMap[$customerGstin]['inv'][] = $invEntry;
                $b2bTaxable += $orderTaxable;
                $b2bGst += $orderGst;
                $docCount++;
            } elseif ($isInterState) {
                // B2CL — inter-state supply to unregistered
                $posKey = (string)$placeOfSupply;
                if (!isset($b2clMap[$posKey])) {
                    $b2clMap[$posKey] = ['pos' => $placeOfSupply, 'inv' => []];
                }
                $b2clMap[$posKey]['inv'][] = [
                    'inum' => $invNum,
                    'idt' => $invDate,
                    'val' => round($orderVal, 2),
                    'itms' => $itemEntries,
                ];
                $b2cTaxable += $orderTaxable;
                $b2cGst += $orderGst;
                $docCount++;
            } else {
                // B2CS — intra-state supply to unregistered
                $b2csList[] = new Gstr1B2csInvoice(['inum' => $invNum, 'idt' => $invDate, 'val' => round($orderVal, 2)]);
                $b2cTaxable += $orderTaxable;
                $b2cGst += $orderGst;
                $docCount++;
            }
        }

        // Populate DTO sections
        if (!empty($ctinMap)) {
            $dto->b2b = [];
            foreach ($ctinMap as $entry) {
                $dto->b2b[] = new Gstr1B2bEntry($entry);
            }
        }

        if (!empty($b2clMap)) {
            $dto->b2cl = [];
            foreach ($b2clMap as $entry) {
                $dto->b2cl[] = new Gstr1B2clEntry($entry);
            }
        }

        if (!empty($b2csList)) {
            $dto->b2cs = [];
            $entry = new Gstr1B2csEntry();
            $entry->sply_ty = $bizState > 0 ? 'INTRA' : 'INTRA';
            $entry->pos = $bizState;
            $entry->inv = $b2csList;
            $dto->b2cs[] = $entry;
        }

        if (!empty($nilList)) {
            $nilEntry = new Gstr1NilEntry();
            $nilEntry->inv = $nilList;
            $nilEntry->nil_amt = round(array_sum(array_column($nilList, 'val')), 2);
            $dto->nil = $nilEntry;
        }

        if (!empty($hsnMap)) {
            $dto->hsn = [];
            foreach ($hsnMap as $h) {
                $dto->hsn[] = new Gstr1HsnEntry([
                    'hsn_sc' => $h['hsn'],
                    'desc' => '',
                    'uqc' => 'NOS',
                    'qty' => $h['qty'],
                    'val' => round($h['val'], 2),
                    'txval' => round($h['taxable'], 2),
                    'iamt' => round($h['iamt'], 2),
                    'camt' => round($h['camt'], 2),
                    'samt' => round($h['samt'], 2),
                ]);
            }
        }

        $dto->gt = round($totalTurnover, 2);
        $dto->cur_gt = round($totalTurnover, 2);
        $dto->doc_issue = new Gstr1DocIssue();
        $dto->doc_issue->doc_num = $docCount;

        return $dto;
    }

    private function fetchOrders(PDO $pdo, string $businessKey, string $monthStart, string $monthEnd): array {
        $stmt = $pdo->prepare("
            SELECT o.*, c.name as customer_name, c.gst_number as customer_gst,
                   c.state_code as customer_state, c.registration_type as customer_reg_type
            FROM refill_orders o
            JOIN customers c ON o.customer_id = c.id
            WHERE o.business_name = ?
              AND DATE(o.order_date) >= ? AND DATE(o.order_date) <= ?
              AND (o.include_in_gst_return IS NULL OR o.include_in_gst_return = 1)
              AND (o.gst_status IS NULL OR o.gst_status != 'filed')
            ORDER BY o.order_date ASC
        ");
        $stmt->execute([$businessKey, $monthStart, $monthEnd]);
        return $stmt->fetchAll();
    }

    private function fetchOrderItems(PDO $pdo, int $orderId): array {
        $stmt = $pdo->prepare("
            SELECT oi.*, g.hsn_code as gas_hsn, p.hsn_code as product_hsn,
                   g.name as gas_name, p.name as product_name
            FROM refill_order_items oi
            LEFT JOIN gas_types g ON oi.gas_type_id = g.id
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.refill_order_id = ?
        ");
        $stmt->execute([$orderId]);
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

    private function validateGstin(string $gstin): bool {
        return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', strtoupper(trim($gstin)));
    }
}
