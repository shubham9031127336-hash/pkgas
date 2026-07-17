<?php
/**
 * Bulk Operation Engine — Impact Analysis, Validation, Atomic Execution & Audit
 *
 * Every bulk operation follows this flow:
 *   1. validateSelectedRecords()  → blocks on critical errors
 *   2. generateFullImpactReport() → structured JSON preview
 *   3. executeBulkOperation()     → atomic transaction + rollback
 *   4. logBulkOperationAudit()    → full traceability
 */

if (!function_exists('validateSelectedCylinders')) {
    /**
     * Pre-validation for bulk cylinder operations.
     * Returns ['errors' => [...], 'warnings' => [...], 'valid_ids' => [...], 'skipped_ids' => [...]]
     */
    function validateSelectedCylinders($pdo, $ids, $context = []) {
        $errors = [];
        $warnings = [];
        $valid = [];
        $skipped = [];

        if (empty($ids)) {
            $errors[] = __('bulk_op.no_cylinders_selected');
            return ['errors' => $errors, 'warnings' => $warnings, 'valid_ids' => $valid, 'skipped_ids' => $skipped];
        }

        $action = $context['action'] ?? '';
        $target_status = $context['target_status'] ?? '';
        $vendor_id = intval($context['vendor_id'] ?? 0);
        $lot_id = intval($context['lot_id'] ?? 0);
        $warehouse_id = intval($context['warehouse_id'] ?? 0);

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT c.*, gt.name AS gas_name FROM cylinders c LEFT JOIN gas_types gt ON c.gas_type_id = gt.id WHERE c.id IN ($ph)");
        $stmt->execute(array_map('intval', $ids));
        $cylinders = [];
        while ($row = $stmt->fetch()) {
            $cylinders[$row['id']] = $row;
        }

        $seen_serials = [];
        foreach ($ids as $cid) {
            $cid = intval($cid);
            if (!isset($cylinders[$cid])) {
                $errors[] = sprintf(__('bulk_op.cylinder_not_found'), $cid);
                $skipped[] = $cid;
                continue;
            }
            $c = $cylinders[$cid];

            // Duplicate serial check
            if (in_array($c['serial_number'], $seen_serials)) {
                $errors[] = sprintf(__('bulk_op.duplicate_cylinder'), $c['serial_number']);
                $skipped[] = $cid;
                continue;
            }
            $seen_serials[] = $c['serial_number'];

            if ($action === 'dispatch') {
                if (!in_array($c['status'], ['empty', 'received_for_refill'])) {
                    $errors[] = sprintf(__('bulk_op.cylinder_not_empty'), $c['serial_number'], $c['status']);
                    $skipped[] = $cid;
                    continue;
                }
                if (!empty($c['current_vendor_id'])) {
                    $warnings[] = sprintf(__('bulk_op.already_with_vendor'), $c['serial_number']);
                }
            }

            if ($action === 'receive') {
                if ($c['status'] !== 'sent_to_vendor') {
                    $errors[] = sprintf(__('bulk_op.cylinder_not_sent'), $c['serial_number'], $c['status']);
                    $skipped[] = $cid;
                    continue;
                }
                if ($vendor_id > 0 && intval($c['current_vendor_id']) !== $vendor_id) {
                    $errors[] = sprintf(__('bulk_op.vendor_mismatch'), $c['serial_number']);
                    $skipped[] = $cid;
                    continue;
                }
            }

            if ($action === 'status_update') {
                if ($c['status'] === 'deleted') {
                    $errors[] = sprintf(__('bulk_op.cylinder_deleted'), $c['serial_number']);
                    $skipped[] = $cid;
                    continue;
                }
                if ($target_status === 'sent_to_vendor' && !empty($c['current_vendor_id'])) {
                    $warnings[] = sprintf(__('bulk_op.already_assigned_vendor'), $c['serial_number']);
                }
            }

            if ($action === 'delete') {
                if ($c['status'] === 'deleted') {
                    $errors[] = sprintf(__('bulk_op.cylinder_already_deleted'), $c['serial_number']);
                    $skipped[] = $cid;
                    continue;
                }
                if (in_array($c['status'], ['with_customer', 'sent_to_vendor'])) {
                    $warnings[] = sprintf(__('bulk_op.delete_active_warning'), $c['serial_number'], $c['status']);
                }
            }

            $valid[] = $cid;
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'valid_ids' => $valid, 'skipped_ids' => $skipped];
    }
}

if (!function_exists('validateSelectedLots')) {
    /**
     * Pre-validation for bulk lot operations.
     */
    function validateSelectedLots($pdo, $ids, $context = []) {
        $errors = [];
        $warnings = [];
        $valid = [];
        $skipped = [];

        if (empty($ids)) {
            $errors[] = __('bulk_op.no_lots_selected');
            return ['errors' => $errors, 'warnings' => $warnings, 'valid_ids' => $valid, 'skipped_ids' => $skipped];
        }

        $action = $context['action'] ?? '';
        $vendor_id = intval($context['vendor_id'] ?? 0);

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id IN ($ph)");
        $stmt->execute(array_map('intval', $ids));
        $lots = [];
        while ($row = $stmt->fetch()) {
            $lots[$row['id']] = $row;
        }

        foreach ($ids as $lid) {
            $lid = intval($lid);
            if (!isset($lots[$lid])) {
                $errors[] = sprintf(__('bulk_op.lot_not_found'), $lid);
                $skipped[] = $lid;
                continue;
            }
            $l = $lots[$lid];

            if ($action === 'receive') {
                if ($l['lot_status'] === 'completed') {
                    $errors[] = sprintf(__('bulk_op.lot_already_completed'), $l['lot_number']);
                    $skipped[] = $lid;
                    continue;
                }
                if ($vendor_id > 0 && intval($l['vendor_id']) !== $vendor_id) {
                    $errors[] = sprintf(__('bulk_op.lot_vendor_mismatch'), $l['lot_number']);
                    $skipped[] = $lid;
                    continue;
                }
            }

            if ($action === 'pay') {
                if ($l['payment_status'] === 'paid') {
                    $warnings[] = sprintf(__('bulk_op.lot_already_paid'), $l['lot_number']);
                }
                if (floatval($l['remaining_balance']) <= 0 && $l['payment_status'] === 'paid') {
                    $skipped[] = $lid;
                    continue;
                }
            }

            if ($action === 'close') {
                if ($l['lot_status'] === 'completed') {
                    $errors[] = sprintf(__('bulk_op.lot_closed'), $l['lot_number']);
                    $skipped[] = $lid;
                    continue;
                }
                if (intval($l['returned_count']) < intval($l['cylinder_count'])) {
                    $warnings[] = sprintf(__('bulk_op.lot_partial_return'), $l['lot_number'], $l['returned_count'], $l['cylinder_count']);
                }
            }

            $valid[] = $lid;
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'valid_ids' => $valid, 'skipped_ids' => $skipped];
    }
}

if (!function_exists('analyzeInventoryImpact')) {
    function analyzeInventoryImpact($pdo, $ids, $action) {
        $result = [
            'cylinders_leaving' => 0,
            'cylinders_entering' => 0,
            'warehouse_stock_deltas' => [],
            'empty_count' => 0,
            'filled_count' => 0,
        ];

        if (empty($ids)) return $result;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT c.id, c.status, c.gas_type_id, c.size_capacity, gt.name AS gas_name FROM cylinders c LEFT JOIN gas_types gt ON c.gas_type_id = gt.id WHERE c.id IN ($ph)");
        $stmt->execute(array_map('intval', $ids));
        $cylinders = $stmt->fetchAll();

        foreach ($cylinders as $c) {
            $key = $c['gas_name'] . ' - ' . $c['size_capacity'];
            if (!isset($result['warehouse_stock_deltas'][$key])) {
                $result['warehouse_stock_deltas'][$key] = ['gas' => $c['gas_name'], 'size' => $c['size_capacity'], 'delta' => 0];
            }

            if ($action === 'dispatch' || $action === 'send_to_vendor') {
                $result['cylinders_leaving']++;
                $result['warehouse_stock_deltas'][$key]['delta']--;
                if ($c['status'] === 'empty') $result['empty_count']++;
                if ($c['status'] === 'filled') $result['filled_count']++;
            } elseif ($action === 'receive' || $action === 'receive_from_vendor') {
                $result['cylinders_entering']++;
                $result['warehouse_stock_deltas'][$key]['delta']++;
                if ($c['status'] === 'sent_to_vendor') $result['empty_count']++;
            }
        }

        $result['warehouse_stock_deltas'] = array_values($result['warehouse_stock_deltas']);
        return $result;
    }
}

if (!function_exists('analyzeLotImpact')) {
    function analyzeLotImpact($pdo, $ids, $action) {
        $result = [
            'lots_affected' => 0,
            'lots_completed' => 0,
            'lots_partial' => 0,
            'lots_open' => 0,
            'lot_details' => [],
        ];

        if (empty($ids)) return $result;
        if (!in_array($action, ['receive', 'receive_from_vendor', 'close', 'pay'])) return $result;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id IN ($ph)");
        $stmt->execute(array_map('intval', $ids));
        $lots = $stmt->fetchAll();

        foreach ($lots as $l) {
            $total = intval($l['cylinder_count']);
            $returned = intval($l['returned_count']);
            $result['lots_affected']++;

            if ($action === 'receive' || $action === 'receive_from_vendor') {
                $new_count = $returned + 1;
                $remaining = floatval($l['remaining_balance'] ?? 0);
                $all_returned = ($new_count >= $total);
                $status = ($all_returned && $remaining <= 0) ? 'completed' : ($new_count > 0 ? 'partial_return' : 'open');
                $detail = [
                    'id' => $l['id'],
                    'lot_number' => $l['lot_number'],
                    'total' => $total,
                    'returned' => $new_count,
                    'new_status' => $status,
                ];
                $result['lot_details'][] = $detail;
                if ($status === 'completed') $result['lots_completed']++;
                else $result['lots_partial']++;
            } elseif ($action === 'close') {
                $result['lots_completed']++;
                $result['lot_details'][] = ['id' => $l['id'], 'lot_number' => $l['lot_number'], 'new_status' => 'completed'];
            } elseif ($action === 'pay') {
                $new_pay_status = (floatval($l['remaining_balance']) <= 0) ? 'paid' : 'partial';
                $result['lot_details'][] = ['id' => $l['id'], 'lot_number' => $l['lot_number'], 'payment_status' => $l['payment_status'], 'new_payment_status' => $new_pay_status];
                if ($new_pay_status === 'paid') $result['lots_completed']++;
            }
        }

        return $result;
    }
}

if (!function_exists('analyzeFinancialImpact')) {
    function analyzeFinancialImpact($pdo, $ids, $action, $context = []) {
        $result = [
            'total_invoice_amount' => 0,
            'advance_already_paid' => 0,
            'remaining_balance' => 0,
            'new_payments' => 0,
            'refunds' => 0,
            'outstanding_after' => 0,
            'currency' => 'INR',
        ];

        if (empty($ids)) return $result;

        if ($action === 'receive' || $action === 'receive_from_vendor') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id IN ($ph)");
            $stmt->execute(array_map('intval', $ids));
            $lots = $stmt->fetchAll();

            // Look up per-cylinder cost estimates
            $all_cyl_ids = [];
            foreach ($lots as $l) {
                $stmt2 = $pdo->prepare("SELECT cylinder_id, refill_cost FROM dispatch_lot_items WHERE lot_id = ? AND dispatch_status = 'dispatched'");
                $stmt2->execute([$l['id']]);
                while ($item = $stmt2->fetch()) {
                    $all_cyl_ids[] = $item['cylinder_id'];
                }
            }

            if (!empty($all_cyl_ids)) {
                $cost_ph = implode(',', array_fill(0, count($all_cyl_ids), '?'));
                $gas_lookup = $pdo->prepare("SELECT c.id, c.gas_type_id, c.size_capacity, g.refill_cost, g.size_refill_costs FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id IN ($cost_ph)");
                $gas_lookup->execute(array_map('intval', $all_cyl_ids));
                $cyl_costs = [];
                while ($cr = $gas_lookup->fetch()) {
                    $sizes = json_decode($cr['size_refill_costs'] ?? '{}', true) ?: [];
                    $cost = floatval($sizes[$cr['size_capacity']] ?? $cr['refill_cost'] ?? 0);
                    $cyl_costs[$cr['id']] = $cost;
                }
                $result['total_invoice_amount'] = array_sum($cyl_costs);
            }

            foreach ($lots as $l) {
                $result['advance_already_paid'] += floatval($l['advance_amount']);
                $result['remaining_balance'] += floatval($l['remaining_balance']);
            }
            $result['new_payments'] = $result['total_invoice_amount'] - $result['advance_already_paid'];
            if ($result['new_payments'] < 0) $result['new_payments'] = 0;
            $result['outstanding_after'] = $result['remaining_balance'] - $result['new_payments'];
            if ($result['outstanding_after'] < 0) $result['outstanding_after'] = 0;
        }

        if ($action === 'pay') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, remaining_balance, lot_number FROM dispatch_lots WHERE id IN ($ph) AND payment_status != 'paid'");
            $stmt->execute(array_map('intval', $ids));
            while ($l = $stmt->fetch()) {
                $bal = floatval($l['remaining_balance']);
                $result['new_payments'] += $bal;
                $result['total_invoice_amount'] += $bal;
            }
            $result['outstanding_after'] = 0;
        }

        if ($action === 'dispatch' || $action === 'send_to_vendor') {
            $vendor_id = intval($context['vendor_id'] ?? 0);
            $advance_amount = floatval($context['advance_amount'] ?? 0);
            $result['advance_already_paid'] = 0;
            $result['new_payments'] = $advance_amount;
            $result['total_invoice_amount'] = floatval($context['estimated_total'] ?? 0);
            $result['remaining_balance'] = $result['total_invoice_amount'] - $advance_amount;
            if ($result['remaining_balance'] < 0) $result['remaining_balance'] = 0;
            $result['outstanding_after'] = $result['remaining_balance'];
        }

        if ($action === 'customer_settle') {
            $advance_used = floatval($context['advance_used'] ?? 0);
            $deposit_used = floatval($context['deposit_used'] ?? 0);
            $paid_amount = floatval($context['paid_amount'] ?? 0);
            $result['new_payments'] = $paid_amount;
            $result['advance_already_paid'] = $advance_used;
            $result['refunds'] = $deposit_used;
            $result['total_invoice_amount'] = floatval($context['order_total'] ?? 0);
        }

        return $result;
    }
}

if (!function_exists('analyzeGSTImpact')) {
    function analyzeGSTImpact($pdo, $ids, $action) {
        $result = [
            'gst_applicable' => false,
            'gst_rate' => 0,
            'gst_amount' => 0,
            'validation_status' => 'ok',
            'gst_locked' => false,
            'mismatch_warning' => false,
            'mismatch_details' => [],
        ];

        if (empty($ids)) return $result;

        if ($action === 'receive' || $action === 'receive_from_vendor' || $action === 'pay') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT lot_number, gst_rate, gst_applicable, gst_locked, gst_amount FROM dispatch_lots WHERE id IN ($ph)");
            $stmt->execute(array_map('intval', $ids));
            $lots = $stmt->fetchAll();

            $rates = [];
            foreach ($lots as $l) {
                $rate = floatval($l['gst_rate']);
                $rates[] = $rate;
                $result['gst_applicable'] = $result['gst_applicable'] || intval($l['gst_applicable']) > 0;
                $result['gst_locked'] = $result['gst_locked'] || intval($l['gst_locked']) > 0;
                $result['gst_amount'] += floatval($l['gst_amount']);

                if (count($rates) > 1 && abs($rate - $rates[0]) > 0.001) {
                    $result['mismatch_warning'] = true;
                    $result['mismatch_details'][] = sprintf(__('bulk_op.gst_rate_mismatch'), $l['lot_number'], $rate . '%', $rates[0] . '%');
                }
            }
            $result['gst_rate'] = !empty($rates) ? $rates[0] : 0;
            if ($result['mismatch_warning']) {
                $result['validation_status'] = 'warning';
            }
        }

        return $result;
    }
}

if (!function_exists('analyzeLedgerImpact')) {
    function analyzeLedgerImpact($pdo, $ids, $action, $context = []) {
        $result = [
            'customer_entries' => 0,
            'vendor_entries' => 0,
            'cash_impact' => 0,
            'bank_impact' => 0,
            'advance_adjustments' => 0,
            'settlement_entries' => 0,
        ];

        if (empty($ids)) return $result;

        if ($action === 'receive' || $action === 'receive_from_vendor') {
            $result['vendor_entries'] = count($ids) * 2; // due_created + advance_utilized
            $result['advance_adjustments'] = count($ids);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(remaining_balance), 0) FROM dispatch_lots WHERE id IN ($ph)");
            $stmt->execute(array_map('intval', $ids));
            $result['cash_impact'] = floatval($stmt->fetchColumn());
        }

        if ($action === 'pay') {
            $result['vendor_entries'] = count($ids);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(remaining_balance), 0) FROM dispatch_lots WHERE id IN ($ph) AND payment_status != 'paid'");
            $stmt->execute(array_map('intval', $ids));
            $result['cash_impact'] = floatval($stmt->fetchColumn());
            if ($result['cash_impact'] > 0) $result['settlement_entries'] = count($ids);
        }

        if ($action === 'dispatch' || $action === 'send_to_vendor') {
            $advance_amount = floatval($context['advance_amount'] ?? 0);
            if ($advance_amount > 0) {
                $result['vendor_entries'] = 1;
                $result['cash_impact'] = $advance_amount;
                $result['advance_adjustments'] = 1;
            }
        }

        if ($action === 'customer_settle') {
            $result['customer_entries'] = 1;
            $paid = floatval($context['paid_amount'] ?? 0);
            $advance = floatval($context['advance_used'] ?? 0);
            $result['cash_impact'] = $paid + $advance;
            $result['advance_adjustments'] = $advance > 0 ? 1 : 0;
        }

        if ($action === 'status_update') {
            $target = $context['target_status'] ?? '';
            if (in_array($target, ['returned_to_consumer', 'sent_to_vendor'])) {
                $result['vendor_entries'] = count($ids);
                $result['cash_impact'] = floatval($context['payment_amount'] ?? 0);
            }
        }

        return $result;
    }
}

if (!function_exists('analyzePaymentImpact')) {
    function analyzePaymentImpact($pdo, $ids, $action) {
        $result = [
            'lots_becoming_paid' => 0,
            'lots_remaining_partial' => 0,
            'lots_remaining_unpaid' => 0,
            'payments_created' => 0,
            'payments_adjusted' => 0,
        ];

        if (empty($ids)) return $result;

        if ($action === 'pay') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, remaining_balance, payment_status FROM dispatch_lots WHERE id IN ($ph)");
            $stmt->execute(array_map('intval', $ids));
            $lots = $stmt->fetchAll();

            foreach ($lots as $l) {
                $bal = floatval($l['remaining_balance']);
                if ($l['payment_status'] === 'paid') continue;
                $result['payments_created']++;
                if ($bal <= 0 || $l['payment_status'] === 'partial') {
                    $result['lots_becoming_paid']++;
                } elseif ($l['payment_status'] === 'unpaid') {
                    $result['lots_becoming_paid']++;
                }
            }
        }

        if ($action === 'receive' || $action === 'receive_from_vendor') {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, payment_status, advance_amount, remaining_balance FROM dispatch_lots WHERE id IN ($ph)");
            $stmt->execute(array_map('intval', $ids));
            $lots = $stmt->fetchAll();

            foreach ($lots as $l) {
                $result['payments_created'] += (floatval($l['advance_amount']) > 0) ? 2 : 1;
                if ($l['payment_status'] === 'unpaid' && floatval($l['remaining_balance']) <= 0) {
                    $result['lots_becoming_paid']++;
                } elseif ($l['payment_status'] === 'unpaid') {
                    $result['lots_remaining_partial']++;
                } elseif ($l['payment_status'] === 'partial') {
                    $result['lots_remaining_partial']++;
                }
            }
        }

        return $result;
    }
}

if (!function_exists('generateFullImpactReport')) {
    /**
     * Generate complete impact report for bulk operations.
     * Returns a structured array that can be JSON-encoded for the dialog.
     */
    function generateFullImpactReport($pdo, $ids, $action, $context = []) {
        $report = [
            'operation' => $action,
            'timestamp' => date('Y-m-d H:i:s'),
            'records_selected' => count($ids),
            'validation' => ['errors' => [], 'warnings' => []],
            'cylinders' => [],
            'lots' => [],
            'inventory' => [],
            'financial' => [],
            'gst' => [],
            'ledger' => [],
            'payment' => [],
            'reports_affected' => [],
        ];

        // Validate based on context
        if (in_array($action, ['dispatch', 'send_to_vendor', 'receive', 'receive_from_vendor', 'status_update', 'delete'])) {
            $validation = validateSelectedCylinders($pdo, $ids, $context);
            $report['validation']['errors'] = $validation['errors'];
            $report['validation']['warnings'] = $validation['warnings'];
            $report['cylinders'] = [
                'total_selected' => count($ids),
                'valid_count' => count($validation['valid_ids']),
                'skipped_count' => count($validation['skipped_ids']),
                'invalid_count' => count($validation['errors']),
                'valid_ids' => $validation['valid_ids'],
                'skipped_ids' => $validation['skipped_ids'],
            ];
        }

        if (in_array($action, ['receive', 'receive_from_vendor', 'close', 'pay'])) {
            $lot_validation = validateSelectedLots($pdo, $ids, $context);
            if (in_array($action, ['receive', 'receive_from_vendor'])) {
                $report['validation']['errors'] = array_merge($report['validation']['errors'], $lot_validation['errors']);
                $report['validation']['warnings'] = array_merge($report['validation']['warnings'], $lot_validation['warnings']);
            } else {
                $report['validation']['errors'] = $lot_validation['errors'];
                $report['validation']['warnings'] = $lot_validation['warnings'];
            }
        }

        // Impact analyses
        $valid_ids = $report['cylinders']['valid_ids'] ?? $ids;
        $report['inventory'] = analyzeInventoryImpact($pdo, $valid_ids, $action);
        $report['lots'] = analyzeLotImpact($pdo, $ids, $action);
        $report['financial'] = analyzeFinancialImpact($pdo, $ids, $action, $context);
        $report['gst'] = analyzeGSTImpact($pdo, $ids, $action);
        $report['ledger'] = analyzeLedgerImpact($pdo, $ids, $action, $context);
        $report['payment'] = analyzePaymentImpact($pdo, $ids, $action);

        // Reports affected
        $report['reports_affected'] = analyzeReportsAffected($action);

        return $report;
    }
}

if (!function_exists('analyzeReportsAffected')) {
    function analyzeReportsAffected($action) {
        $reports = [];
        switch ($action) {
            case 'dispatch':
            case 'send_to_vendor':
                $reports = [__('bulk_op.report_stock'), __('bulk_op.report_dispatch'), __('bulk_op.report_vendor')];
                break;
            case 'receive':
            case 'receive_from_vendor':
                $reports = [__('bulk_op.report_stock'), __('bulk_op.report_receive'), __('bulk_op.report_gst'), __('bulk_op.report_ledger'), __('bulk_op.report_vendor')];
                break;
            case 'pay':
                $reports = [__('bulk_op.report_payment'), __('bulk_op.report_gst'), __('bulk_op.report_ledger'), __('bulk_op.report_vendor')];
                break;
            case 'status_update':
                $reports = [__('bulk_op.report_stock'), __('bulk_op.report_dispatch')];
                break;
            case 'delete':
                $reports = [__('bulk_op.report_stock')];
                break;
            case 'customer_settle':
                $reports = [__('bulk_op.report_stock'), __('bulk_op.report_dispatch'), __('bulk_op.report_payment'), __('bulk_op.report_ledger')];
                break;
            default:
                $reports = [__('bulk_op.report_stock')];
        }
        return $reports;
    }
}

if (!function_exists('executeBulkOperation')) {
    /**
     * Execute a bulk operation inside a database transaction.
     * $executor is a callable: function($pdo, $report, $context) returns ['processed'=>N, 'skipped'=>N, 'details'=>[...]]
     * On failure everything is rolled back and an audit record with 'failed' status is created.
     */
    function executeBulkOperation($pdo, $report, $context, $executor) {
        $before_state = captureBeforeState($pdo, $report);
        $username = $_SESSION['username'] ?? 'system';

        try {
            $pdo->beginTransaction();
            $result = $executor($pdo, $report, $context);
            $pdo->commit();

            if (function_exists('syncInventory')) {
                syncInventory($pdo);
            }

            $audit_id = logBulkOperationAudit($pdo, $report, $result, $before_state, 'success', null, $username);

            return [
                'success' => true,
                'processed' => $result['processed'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'details' => $result['details'] ?? [],
                'audit_id' => $audit_id,
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logBulkOperationAudit($pdo, $report, null, $before_state, 'failed', $e->getMessage(), $username);
            throw $e;
        }
    }
}

if (!function_exists('captureBeforeState')) {
    /**
     * Snapshot cylinder & lot state before mutation for audit trail.
     */
    function captureBeforeState($pdo, $report) {
        $state = ['cylinders' => [], 'lots' => []];

        $cyl_ids = $report['cylinders']['valid_ids'] ?? [];
        if (!empty($cyl_ids)) {
            $ph = implode(',', array_fill(0, count($cyl_ids), '?'));
            $stmt = $pdo->prepare("SELECT id, serial_number, status, current_customer_id, current_vendor_id, current_refill_cost FROM cylinders WHERE id IN ($ph)");
            $stmt->execute(array_map('intval', $cyl_ids));
            while ($row = $stmt->fetch()) {
                $state['cylinders'][$row['id']] = $row;
            }
        }

        $lot_ids = $report['lots']['lot_details'] ?? [];
        $lot_id_nums = array_column($lot_ids, 'id');
        if (!empty($lot_id_nums)) {
            $ph = implode(',', array_fill(0, count($lot_id_nums), '?'));
            $stmt = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id IN ($ph)");
            $stmt->execute(array_map('intval', $lot_id_nums));
            while ($row = $stmt->fetch()) {
                $state['lots'][$row['id']] = $row;
            }
        }

        return $state;
    }
}

if (!function_exists('logBulkOperationAudit')) {
    /**
     * Create an audit record for the bulk operation.
     */
    function logBulkOperationAudit($pdo, $report, $result, $before_state, $status, $error_msg, $username) {
        if (function_exists('purgeOldBulkOperationAudit')) {
            purgeOldBulkOperationAudit($pdo);
        }

        $processed = $result['processed'] ?? 0;
        $skipped = $result['skipped'] ?? ($report['cylinders']['skipped_count'] ?? 0);
        $record_count = $report['records_selected'] ?? 0;
        $action_key = $report['operation'] ?? '';

        $stmt = $pdo->prepare("INSERT INTO bulk_operation_audit (username, operation_type, action_key, record_count, processed_count, skipped_count, before_state, after_state, impact_summary, payment_changes, gst_changes, inventory_changes, ledger_changes, execution_status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $username,
            $report['operation'] ?? '',
            $action_key,
            $record_count,
            $processed,
            $skipped,
            json_encode($before_state),
            $result ? json_encode(['processed' => $processed, 'details' => $result['details'] ?? []]) : null,
            json_encode($report),
            json_encode($report['payment'] ?? []),
            json_encode($report['gst'] ?? []),
            json_encode($report['inventory'] ?? []),
            json_encode($report['ledger'] ?? []),
            $status,
            $error_msg,
        ]);

        return $pdo->lastInsertId();
    }
}

if (!function_exists('renderBulkOperationExecReport')) {
    /**
     * Render an execution report HTML string after a successful bulk operation.
     */
    function renderBulkOperationExecReport($report, $result) {
        $op = htmlspecialchars($report['operation'] ?? '');
        $processed = intval($result['processed'] ?? 0);
        $skipped = intval($result['skipped'] ?? 0);
        $total = $report['records_selected'] ?? 0;

        $html = '<div class="bulk-op-report">';
        $html .= '<div class="report-header" style="background:#059669;color:#fff;padding:1rem 1.25rem;border-radius:10px 10px 0 0;">';
        $html .= '<h3 style="margin:0;font-size:1.1rem;">' . __('bulk_op.operation_completed') . '</h3>';
        $html .= '<p style="margin:0.25rem 0 0;opacity:0.9;font-size:0.85rem;">' . $op . '</p>';
        $html .= '</div>';
        $html .= '<div style="padding:1.25rem;background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 10px 10px;">';

        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:1rem;">';
        $html .= reportStatBox(__('bulk_op.processed'), $processed, '#059669');
        $html .= reportStatBox(__('bulk_op.records_selected'), $total, '#2563eb');
        if ($skipped > 0) {
            $html .= reportStatBox(__('bulk_op.skipped'), $skipped, '#d97706');
        }
        $html .= '</div>';

        $fin = $report['financial'] ?? [];
        if (!empty($fin['new_payments'])) {
            $html .= '<div class="report-section" style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #e2e8f0;">';
            $html .= '<strong>' . __('bulk_op.payments_collected') . ':</strong> ₹' . number_format($fin['new_payments'], 2);
            $html .= '</div>';
        }

        $lots = $report['lots'] ?? [];
        if (!empty($lots['lots_completed'])) {
            $html .= '<div class="report-section" style="margin-top:0.5rem;font-size:0.85rem;">';
            $html .= '<span>' . __('bulk_op.lots_closed') . ': ' . $lots['lots_completed'] . '</span>';
            if (!empty($lots['lots_partial'])) {
                $html .= ' &middot; <span>' . __('bulk_op.lots_partial') . ': ' . $lots['lots_partial'] . '</span>';
            }
            $html .= '</div>';
        }

        $ledger = $report['ledger'] ?? [];
        if (!empty($ledger['vendor_entries'])) {
            $html .= '<div style="margin-top:0.5rem;font-size:0.85rem;">';
            $html .= __('bulk_op.ledger_entries_created') . ': ' . $ledger['vendor_entries'];
            $html .= '</div>';
        }

        if ($skipped > 0) {
            $html .= '<div style="margin-top:0.75rem;padding:0.75rem;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:0.82rem;">';
            $html .= '<strong style="color:#d97706;">⚠ ' . __('bulk_op.warnings') . ':</strong> ' . sprintf(__('bulk_op.skipped_count_msg'), $skipped);
            $html .= '</div>';
        }

        $html .= '<div style="margin-top:1rem;display:flex;gap:0.75rem;">';
        $html .= '<button onclick="window.print()" class="btn-primary" style="padding:0.5rem 1.25rem;">' . __('bulk_op.download_report') . '</button>';
        $html .= '<button onclick="closeModal(\'bulkOpExecReportModal\')" class="btn-secondary" style="padding:0.5rem 1.25rem;">' . __('bulk_op.close') . '</button>';
        $html .= '</div>';

        $html .= '</div></div>';
        return $html;
    }
}

if (!function_exists('reportStatBox')) {
    function reportStatBox($label, $value, $color) {
        return '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.75rem;text-align:center;">'
            . '<div style="font-size:1.5rem;font-weight:800;color:' . $color . ';">' . intval($value) . '</div>'
            . '<div style="font-size:0.75rem;color:#64748b;margin-top:0.15rem;">' . $label . '</div>'
            . '</div>';
    }
}
