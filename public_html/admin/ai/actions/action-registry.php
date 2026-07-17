<?php
/**
 * AI Action Registry
 * Defines all write operations the AI can perform, with role permissions and PHP handler functions.
 * Each handler mirrors the existing form POST logic from the admin panel.
 */

require_once __DIR__ . '/../../inventory-utils.php';

if (!function_exists('getActionDefinitions')) {
    function getActionDefinitions() {
        return [
            // ===== CUSTOMER ACTIONS =====
            'create_customer' => [
                'description' => 'Create a new customer',
                'required_params' => ['name', 'mobile'],
                'optional_params' => ['address', 'gst_number', 'customer_type'],
                'roles' => ['super_admin', 'billing_clerk'],
                'handler' => 'actionCreateCustomer',
            ],
            'update_customer' => [
                'description' => 'Update an existing customer',
                'required_params' => ['id'],
                'optional_params' => ['name', 'mobile', 'address', 'gst_number', 'customer_type'],
                'roles' => ['super_admin', 'billing_clerk'],
                'handler' => 'actionUpdateCustomer',
            ],
            'delete_customer' => [
                'description' => 'Delete a customer and all related records',
                'required_params' => ['id'],
                'optional_params' => [],
                'roles' => ['super_admin'],
                'handler' => 'actionDeleteCustomer',
            ],

            // ===== ORDER ACTIONS =====
            'create_order' => [
                'description' => 'Create a new refill order with items, payment, and invoice',
                'required_params' => ['customer_id', 'items'],
                'optional_params' => ['payment_method', 'discount', 'notes', 'business_name'],
                'roles' => ['super_admin', 'billing_clerk'],
                'handler' => 'actionCreateOrder',
            ],

            // ===== CYLINDER ACTIONS =====
            'register_cylinder' => [
                'description' => 'Register a new cylinder',
                'required_params' => ['serial_number', 'gas_type_id', 'size_capacity'],
                'optional_params' => ['purchase_date', 'expiry_date', 'status'],
                'roles' => ['super_admin', 'warehouse_supervisor'],
                'handler' => 'actionRegisterCylinder',
            ],
            'dispatch_to_vendor' => [
                'description' => 'Dispatch cylinders to a vendor for refilling',
                'required_params' => ['vendor_id', 'cylinder_ids'],
                'optional_params' => [],
                'roles' => ['super_admin', 'warehouse_supervisor'],
                'handler' => 'actionDispatchToVendor',
            ],
            'receive_from_vendor' => [
                'description' => 'Receive filled cylinders from a vendor',
                'required_params' => ['vendor_id', 'cylinder_ids'],
                'optional_params' => [],
                'roles' => ['super_admin', 'warehouse_supervisor'],
                'handler' => 'actionReceiveFromVendor',
            ],
            'issue_to_customer' => [
                'description' => 'Issue a cylinder to a customer',
                'required_params' => ['customer_id', 'cylinder_id', 'gas_type_id'],
                'optional_params' => ['rent_per_day', 'free_days', 'size_capacity'],
                'roles' => ['super_admin', 'billing_clerk'],
                'handler' => 'actionIssueToCustomer',
            ],
            'return_from_customer' => [
                'description' => 'Return a cylinder from a customer',
                'required_params' => ['cylinder_id', 'customer_id'],
                'optional_params' => ['condition_note'],
                'roles' => ['super_admin', 'billing_clerk', 'warehouse_supervisor'],
                'handler' => 'actionReturnFromCustomer',
            ],
            'update_cylinder_status' => [
                'description' => 'Update a cylinder status',
                'required_params' => ['cylinder_id', 'status'],
                'optional_params' => ['vendor_id', 'customer_id', 'notes'],
                'roles' => ['super_admin', 'warehouse_supervisor'],
                'handler' => 'actionUpdateCylinderStatus',
            ],

            // ===== CYLINDER EXCHANGE =====
            'exchange_settlement' => [
                'description' => 'Process cylinder exchange settlement',
                'required_params' => ['customer_id'],
                'optional_params' => ['new_serial', 'new_gas_type_id', 'new_size_capacity', 'returned_cylinder_id', 'payment_method', 'charge_amount'],
                'roles' => ['super_admin', 'billing_clerk'],
                'handler' => 'actionExchangeSettlement',
            ],

            // ===== PAYMENT ACTIONS =====
            'record_payment' => [
                'description' => 'Record a payment from a customer',
                'required_params' => ['customer_id', 'amount', 'payment_method'],
                'optional_params' => ['order_id', 'notes', 'payment_type'],
                'roles' => ['super_admin', 'billing_clerk'],
                'handler' => 'actionRecordPayment',
            ],
            'adjust_deposit' => [
                'description' => 'Add or refund customer deposit',
                'required_params' => ['customer_id', 'amount', 'type'],
                'optional_params' => ['notes'],
                'roles' => ['super_admin', 'billing_clerk'],
                'handler' => 'actionAdjustDeposit',
            ],

            // ===== VENDOR ACTIONS =====
            'create_vendor' => [
                'description' => 'Create a new vendor',
                'required_params' => ['name', 'mobile'],
                'optional_params' => ['contact_person', 'address'],
                'roles' => ['super_admin'],
                'handler' => 'actionCreateVendor',
            ],
            'update_vendor' => [
                'description' => 'Update a vendor',
                'required_params' => ['id'],
                'optional_params' => ['name', 'contact_person', 'mobile', 'address'],
                'roles' => ['super_admin'],
                'handler' => 'actionUpdateVendor',
            ],
            'delete_vendor' => [
                'description' => 'Delete a vendor',
                'required_params' => ['id'],
                'optional_params' => [],
                'roles' => ['super_admin'],
                'handler' => 'actionDeleteVendor',
            ],

            // ===== PARTNER ACTIONS =====
            'create_partner' => [
                'description' => 'Create a new channel partner',
                'required_params' => ['company_name', 'contact_person', 'mobile'],
                'optional_params' => ['email', 'gst_number', 'address', 'notes'],
                'roles' => ['super_admin'],
                'handler' => 'actionCreatePartner',
            ],
            'update_partner' => [
                'description' => 'Update a channel partner',
                'required_params' => ['id'],
                'optional_params' => ['company_name', 'contact_person', 'mobile', 'email', 'gst_number', 'address', 'notes', 'status'],
                'roles' => ['super_admin'],
                'handler' => 'actionUpdatePartner',
            ],
            'delete_partner' => [
                'description' => 'Delete a channel partner',
                'required_params' => ['id'],
                'optional_params' => [],
                'roles' => ['super_admin'],
                'handler' => 'actionDeletePartner',
            ],
            'borrow_from_partner' => [
                'description' => 'Borrow cylinders from a partner',
                'required_params' => ['partner_id', 'cylinder_ids'],
                'optional_params' => ['notes'],
                'roles' => ['super_admin', 'warehouse_supervisor'],
                'handler' => 'actionBorrowFromPartner',
            ],
            'lend_to_partner' => [
                'description' => 'Lend cylinders to a partner',
                'required_params' => ['partner_id', 'cylinder_ids'],
                'optional_params' => ['notes'],
                'roles' => ['super_admin', 'warehouse_supervisor'],
                'handler' => 'actionLendToPartner',
            ],

            // ===== GAS TYPE ACTIONS =====
            'create_gas_type' => [
                'description' => 'Create a new gas type',
                'required_params' => ['name'],
                'optional_params' => ['chemical_formula', 'default_price_per_kg', 'description', 'sizes', 'size_prices'],
                'roles' => ['super_admin'],
                'handler' => 'actionCreateGasType',
            ],
            'update_gas_type' => [
                'description' => 'Update a gas type',
                'required_params' => ['id'],
                'optional_params' => ['name', 'chemical_formula', 'default_price_per_kg', 'description', 'sizes', 'size_prices'],
                'roles' => ['super_admin'],
                'handler' => 'actionUpdateGasType',
            ],
        ];
    }
}

// ==================== HANDLER FUNCTIONS ====================

if (!function_exists('actionCreateCustomer')) {
    function actionCreateCustomer($pdo, $params) {
        $stmt = $pdo->prepare("INSERT INTO customers (name, mobile, address, gst_number, customer_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $params['name'],
            $params['mobile'],
            $params['address'] ?? '',
            !empty($params['gst_number']) ? $params['gst_number'] : null,
            $params['customer_type'] ?? 'refill',
        ]);
        $id = $pdo->lastInsertId();
        return ['success' => true, 'data' => ['customer_id' => (int)$id, 'name' => $params['name'], 'mobile' => $params['mobile']]];
    }
}

if (!function_exists('actionUpdateCustomer')) {
    function actionUpdateCustomer($pdo, $params) {
        $fields = [];
        $values = [];
        foreach (['name', 'mobile', 'address', 'gst_number', 'customer_type'] as $f) {
            if (isset($params[$f])) {
                $fields[] = "$f = ?";
                $values[] = $params[$f];
            }
        }
        if (empty($fields)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        $values[] = $params['id'];
        $stmt = $pdo->prepare("UPDATE customers SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        return ['success' => true, 'data' => ['customer_id' => (int)$params['id']]];
    }
}

if (!function_exists('actionDeleteCustomer')) {
    function actionDeleteCustomer($pdo, $params) {
        $customer_id = (int)$params['id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE cylinders SET current_customer_id = NULL, status = 'empty' WHERE current_customer_id = ?")->execute([$customer_id]);
            $pdo->prepare("DELETE FROM cylinder_transactions WHERE customer_id = ?")->execute([$customer_id]);
            $pdo->prepare("DELETE FROM payments WHERE customer_id = ?")->execute([$customer_id]);
            $pdo->prepare("DELETE oi FROM refill_order_items oi JOIN refill_orders o ON oi.refill_order_id = o.id WHERE o.customer_id = ?")->execute([$customer_id]);
            $pdo->prepare("DELETE FROM refill_orders WHERE customer_id = ?")->execute([$customer_id]);
            $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$customer_id]);
            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['customer_id' => $customer_id]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to delete customer: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionCreateOrder')) {
    function actionCreateOrder($pdo, $params) {
        require_once __DIR__ . '/../../db.php';
        $customer_id = (int)$params['customer_id'];
        $items = $params['items'];
        $payment_method = $params['payment_method'] ?? 'Cash';
        $discount = floatval($params['discount'] ?? 0);
        $notes = $params['notes'] ?? '';
        require_once __DIR__ . '/../../business_helper.php';
        $business_name = $params['business_name'] ?? getBrandConfig()['business_key'];
        $order_date = $params['order_date'] ?? date('Y-m-d');

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += floatval($item['price_per_unit']) * intval($item['qty'] ?? 1);
            if (intval($item['is_rental'] ?? 0) === 2) {
                $subtotal += floatval($item['sell_price'] ?? 0);
            }
        }
        $gst_rate = floatval($params['gst_rate'] ?? 18);
        $tax_amount = $gst_rate > 0 ? round($subtotal * $gst_rate / 100, 2) : 0.00;
        $is_credit = ($payment_method === 'Credit');
        $payment_status = $is_credit ? 'pending' : 'paid';
        $grand_total = $subtotal + $tax_amount - $discount;
        $vehicle_number = $params['vehicle_number'] ?? '';

        $order_type = 'refill_with_exchange';
        $has_refill_without_exchange = true;
        $has_refill = false;
        foreach ($items as $item) {
            if (intval($item['is_rental'] ?? 0) === 0) {
                $has_refill = true;
                if (!empty($item['returned_cylinder_id'])) {
                    $has_refill_without_exchange = false;
                }
            }
        }
        if ($has_refill && $has_refill_without_exchange) {
            $order_type = 'refill_without_exchange';
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO refill_orders (customer_id, order_date, subtotal, deposit_amount, tax_amount, discount, grand_total, payment_status, payment_method, notes, business_name, vehicle_number, is_credit_order, order_type) VALUES (?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$customer_id, $order_date, $subtotal, $tax_amount, $discount, $grand_total, $payment_status, $payment_method, $notes, $business_name, $vehicle_number ?: null, $is_credit ? 1 : 0, $order_type]);
            $order_id = $pdo->lastInsertId();

            foreach ($items as $item) {
                $gas_type_id = (int)$item['gas_type_id'];
                $qty = intval($item['qty'] ?? 1);
                $price = floatval($item['price_per_unit']);
                $is_rental = intval($item['is_rental'] ?? 0);
                $rent_per_day = ($is_rental === 1) ? floatval($item['rent_per_day'] ?? 0) : 0;
                $free_days = ($is_rental === 1) ? intval($item['free_days'] ?? 0) : 0;
                $size_capacity = $item['size_capacity'] ?? '';
                $cylinder_id = isset($item['cylinder_id']) ? (int)$item['cylinder_id'] : null;
                $returned_cylinder_id = ($is_rental === 0) ? (isset($item['returned_cylinder_id']) ? (int)$item['returned_cylinder_id'] : null) : null;
                $sell_price = floatval($item['sell_price'] ?? 0);
                $sold_cyl_serial = $item['sold_cylinder_serial'] ?? null;

                for ($i = 0; $i < $qty; $i++) {
                    $deposit_amount = 0.00;
                    $item_refill_cost = 0;
                    $item_taxable = $price;
                    if ($is_rental === 2) $item_taxable += floatval($sell_price);
                    $item_gst_amt = $gst_rate > 0 ? round($item_taxable * $gst_rate / 100, 2) : 0;
                    $item_cgst = $item_gst_amt / 2;
                    $item_sgst = $item_gst_amt / 2;
                    $stmt = $pdo->prepare("INSERT INTO refill_order_items (refill_order_id, gas_type_id, cylinder_id, size_capacity, qty, price_per_unit, is_rental, deposit_amount, rent_per_day, free_days, returned_cylinder_id, sell_price, sold_cylinder_serial, refill_cost, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00)");
                    $stmt->execute([$order_id, $gas_type_id, ($is_rental === 2 ? null : $cylinder_id), $size_capacity, $price, $is_rental, $deposit_amount, $rent_per_day, $free_days, $returned_cylinder_id, $sell_price, $sold_cyl_serial, $item_refill_cost, $gst_rate, $item_taxable, $item_gst_amt, $item_cgst, $item_sgst]);

                    if ($is_rental === 2 && $cylinder_id) {
                        // SELL MODE: archive and delete cylinder
                        $sell_fetch = $pdo->prepare("SELECT serial_number FROM cylinders WHERE id = ?");
                        $sell_fetch->execute([$cylinder_id]);
                        $sell_cyl = $sell_fetch->fetch();
                        if ($sell_cyl) {
                            archiveDeletedCylinder($pdo, $cylinder_id, $_SESSION['username'] ?? 'AI');
                            logCylinderTransaction($pdo, $cylinder_id, $customer_id, null, 'issue_to_customer', "SOLD cylinder {$sell_cyl['serial_number']} on Order #ORD-$order_id (via AI)");
                        }
                    } elseif ($cylinder_id && $is_rental !== 2) {
                        if ($is_rental) {
                            $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ?, daily_rent_rate = ?, free_days = ?, borrow_date = ?, last_refill_date = ? WHERE id = ?")->execute([$customer_id, $rent_per_day, $free_days, $order_date, $order_date, $cylinder_id]);
                        } else {
                            $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ?, last_refill_date = ? WHERE id = ?")->execute([$customer_id, $order_date, $cylinder_id]);
                        }
                        $pdo->prepare("UPDATE customers SET active_cylinders_count = active_cylinders_count + 1 WHERE id = ?")->execute([$customer_id]);
                        logCylinderTransaction($pdo, $cylinder_id, $customer_id, null, 'issue_to_customer', 'Issued via AI order creation');
                    }
                }
            }

            if (!$is_credit) {
                // Regular payment
                $refill_charge = ($subtotal + $tax_amount) - $discount;
                $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, 'refill_payment', ?)")->execute([$customer_id, $order_id, $refill_charge, $payment_method, $notes]);
                recalculateOrderPaymentStatus($pdo, $order_id);
            }
            // Credit orders: deposit-first logic would go here (simplified for AI - just mark pending)

            $invoice_num = "INV-" . date('Y') . "-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE refill_orders SET invoice_number = ?, invoice_date = ? WHERE id = ?")->execute([$invoice_num, $order_date, $order_id]);

            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['order_id' => (int)$order_id, 'invoice_number' => $invoice_num, 'grand_total' => $grand_total]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to create order: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionRegisterCylinder')) {
    function actionRegisterCylinder($pdo, $params) {
        $stmt = $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, purchase_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $params['serial_number'],
            (int)$params['gas_type_id'],
            $params['size_capacity'],
            $params['status'] ?? 'empty',
            $params['purchase_date'] ?? null,
            $params['expiry_date'] ?? null,
        ]);
        $id = $pdo->lastInsertId();
        syncInventory($pdo);
        return ['success' => true, 'data' => ['cylinder_id' => (int)$id, 'serial_number' => $params['serial_number']]];
    }
}

if (!function_exists('actionDispatchToVendor')) {
    function actionDispatchToVendor($pdo, $params) {
        $vendor_id = (int)$params['vendor_id'];
        $cylinder_ids = $params['cylinder_ids'];
        $results = [];
        $pdo->beginTransaction();
        try {
            foreach ($cylinder_ids as $cid) {
                $cid = (int)$cid;
                $pdo->prepare("UPDATE cylinders SET status = 'sent_to_vendor', current_vendor_id = ? WHERE id = ? AND status IN ('empty', 'borrowed_from_partner')")->execute([$vendor_id, $cid]);
                if ($pdo->rowCount() > 0) {
                    $pdo->prepare("UPDATE vendors SET active_refill_count = active_refill_count + 1 WHERE id = ?")->execute([$vendor_id]);
                    logCylinderTransaction($pdo, $cid, null, $vendor_id, 'send_to_vendor', 'Dispatched via AI');
                    $results[] = $cid;
                }
            }
            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['dispatched_count' => count($results), 'cylinder_ids' => $results]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to dispatch: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionReceiveFromVendor')) {
    function actionReceiveFromVendor($pdo, $params) {
        $vendor_id = (int)$params['vendor_id'];
        $cylinder_ids = $params['cylinder_ids'];
        $results = [];
        $pdo->beginTransaction();
        try {
            foreach ($cylinder_ids as $cid) {
                $cid = (int)$cid;
                $pdo->prepare("UPDATE cylinders SET status = 'filled', current_vendor_id = NULL WHERE id = ? AND status = 'sent_to_vendor' AND current_vendor_id = ?")->execute([$cid, $vendor_id]);
                if ($pdo->rowCount() > 0) {
                    $pdo->prepare("UPDATE vendors SET active_refill_count = GREATEST(0, active_refill_count - 1) WHERE id = ?")->execute([$vendor_id]);
                    logCylinderTransaction($pdo, $cid, null, $vendor_id, 'refill', 'Received from vendor via AI');
                    $results[] = $cid;
                }
            }
            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['received_count' => count($results), 'cylinder_ids' => $results]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to receive: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionIssueToCustomer')) {
    function actionIssueToCustomer($pdo, $params) {
        $customer_id = (int)$params['customer_id'];
        $cylinder_id = (int)$params['cylinder_id'];
        $gas_type_id = (int)$params['gas_type_id'];
        $rent_per_day = floatval($params['rent_per_day'] ?? 0);
        $free_days = intval($params['free_days'] ?? 0);
        $size_capacity = $params['size_capacity'] ?? '';
        $issue_date = $params['issue_date'] ?? date('Y-m-d');

        $pdo->beginTransaction();
        try {
            if ($rent_per_day > 0) {
                $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ?, daily_rent_rate = ?, free_days = ?, borrow_date = ?, last_refill_date = ? WHERE id = ?")->execute([$customer_id, $rent_per_day, $free_days, $issue_date, $issue_date, $cylinder_id]);
            } else {
                $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ?, last_refill_date = ? WHERE id = ?")->execute([$customer_id, $issue_date, $cylinder_id]);
            }
            $pdo->prepare("UPDATE customers SET active_cylinders_count = active_cylinders_count + 1 WHERE id = ?")->execute([$customer_id]);
            logCylinderTransaction($pdo, $cylinder_id, $customer_id, null, 'issue_to_customer', 'Issued to customer via AI');
            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['cylinder_id' => $cylinder_id, 'customer_id' => $customer_id]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to issue cylinder: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionReturnFromCustomer')) {
    function actionReturnFromCustomer($pdo, $params) {
        $cylinder_id = (int)$params['cylinder_id'];
        $customer_id = (int)$params['customer_id'];
        $note = $params['condition_note'] ?? 'Returned from customer via AI';

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE cylinders SET status = 'empty', current_customer_id = NULL WHERE id = ? AND current_customer_id = ?")->execute([$cylinder_id, $customer_id]);
            $pdo->prepare("UPDATE customers SET active_cylinders_count = GREATEST(0, active_cylinders_count - 1) WHERE id = ?")->execute([$customer_id]);
            logCylinderTransaction($pdo, $cylinder_id, $customer_id, null, 'return_from_customer', $note);
            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['cylinder_id' => $cylinder_id, 'customer_id' => $customer_id]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to return cylinder: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionUpdateCylinderStatus')) {
    function actionUpdateCylinderStatus($pdo, $params) {
        $cylinder_id = (int)$params['cylinder_id'];
        $status = $params['status'];
        $vendor_id = isset($params['vendor_id']) ? (int)$params['vendor_id'] : null;
        $customer_id = isset($params['customer_id']) ? (int)$params['customer_id'] : null;
        $notes = $params['notes'] ?? 'Status updated via AI';

        $pdo->beginTransaction();
        try {
            if ($status === 'sent_to_vendor' && $vendor_id) {
                $pdo->prepare("UPDATE cylinders SET status = 'sent_to_vendor', current_vendor_id = ?, current_customer_id = NULL WHERE id = ?")->execute([$vendor_id, $cylinder_id]);
                $pdo->prepare("UPDATE vendors SET active_refill_count = active_refill_count + 1 WHERE id = ?")->execute([$vendor_id]);
                logCylinderTransaction($pdo, $cylinder_id, null, $vendor_id, 'send_to_vendor', $notes);
            } elseif ($status === 'filled') {
                $pdo->prepare("UPDATE cylinders SET status = 'filled', current_vendor_id = NULL WHERE id = ?")->execute([$cylinder_id]);
                logCylinderTransaction($pdo, $cylinder_id, null, null, 'refill', $notes);
            } elseif ($status === 'under_maintenance') {
                $pdo->prepare("UPDATE cylinders SET status = 'under_maintenance', current_vendor_id = NULL WHERE id = ?")->execute([$cylinder_id]);
                logCylinderTransaction($pdo, $cylinder_id, null, null, 'maintenance', $notes);
            } elseif ($status === 'empty') {
                $pdo->prepare("UPDATE cylinders SET status = 'empty', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?")->execute([$cylinder_id]);
                logCylinderTransaction($pdo, $cylinder_id, null, null, 'return_from_customer', $notes);
            } else {
                $pdo->prepare("UPDATE cylinders SET status = ?, current_vendor_id = NULL WHERE id = ?")->execute([$status, $cylinder_id]);
                logCylinderTransaction($pdo, $cylinder_id, null, null, 'maintenance', $notes);
            }
            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['cylinder_id' => $cylinder_id, 'status' => $status]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to update cylinder status: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionExchangeSettlement')) {
    function actionExchangeSettlement($pdo, $params) {
        $customer_id = (int)$params['customer_id'];
        $returned_cylinder_id = isset($params['returned_cylinder_id']) ? (int)$params['returned_cylinder_id'] : null;
        $new_serial = $params['new_serial'] ?? null;
        $new_gas_type_id = isset($params['new_gas_type_id']) ? (int)$params['new_gas_type_id'] : 0;
        $new_size_capacity = $params['new_size_capacity'] ?? '';
        $payment_method = $params['payment_method'] ?? null;
        $charge_amount = floatval($params['charge_amount'] ?? 0);

        $pdo->beginTransaction();
        try {
            if ($returned_cylinder_id) {
                $stmt = $pdo->prepare("SELECT ownership_type, original_owner_customer_id FROM cylinders WHERE id = ?");
                $stmt->execute([$returned_cylinder_id]);
                $cyl = $stmt->fetch();
                if ($cyl) {
                    if ($cyl['ownership_type'] === 'owned' || $cyl['ownership_type'] === 'partner_owned') {
                        $pdo->prepare("UPDATE cylinders SET status = 'empty', current_customer_id = NULL WHERE id = ?")->execute([$returned_cylinder_id]);
                    } elseif ($cyl['ownership_type'] === 'consumer_owned' && (int)$cyl['original_owner_customer_id'] === $customer_id) {
                        $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?")->execute([$returned_cylinder_id]);
                    } else {
                        $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$customer_id, $returned_cylinder_id]);
                    }
                    $pdo->prepare("UPDATE customers SET active_cylinders_count = GREATEST(0, active_cylinders_count - 1) WHERE id = ?")->execute([$customer_id]);
                    logCylinderTransaction($pdo, $returned_cylinder_id, $customer_id, null, 'return_from_customer', 'Exchange settlement return');
                }
            }

            if ($new_serial) {
                $stmt = $pdo->prepare("SELECT id FROM cylinders WHERE serial_number = ?");
                $stmt->execute([$new_serial]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$customer_id, $existing['id']]);
                    logCylinderTransaction($pdo, $existing['id'], $customer_id, null, 'issue_to_customer', 'Exchange settlement new cylinder');
                } else {
                    $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, ownership_type, original_owner_customer_id) VALUES (?, ?, ?, 'empty', 'consumer_owned', ?)")->execute([$new_serial, $new_gas_type_id, $new_size_capacity, $customer_id]);
                    $new_id = $pdo->lastInsertId();
                    $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$customer_id, $new_id]);
                    logCylinderTransaction($pdo, $new_id, $customer_id, null, 'issue_to_customer', 'Exchange settlement new consumer cylinder');
                }
                $pdo->prepare("UPDATE customers SET active_cylinders_count = active_cylinders_count + 1 WHERE id = ?")->execute([$customer_id]);
            }

            if ($payment_method && $charge_amount > 0) {
                $pdo->prepare("INSERT INTO payments (customer_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, 'exchange_charge', ?)")->execute([$customer_id, $charge_amount, $payment_method, 'Exchange settlement charge']);
            }

            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['customer_id' => $customer_id, 'message' => 'Exchange settlement completed']];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Exchange settlement failed: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionRecordPayment')) {
    function actionRecordPayment($pdo, $params) {
        $customer_id = (int)$params['customer_id'];
        $amount = floatval($params['amount']);
        $payment_method = $params['payment_method'];
        $order_id = isset($params['order_id']) ? (int)$params['order_id'] : null;
        $notes = $params['notes'] ?? '';
        $payment_type = $params['payment_type'] ?? 'refill_payment';
        $damage_deduction = floatval($params['damage_deduction'] ?? 0);

        if ($payment_type === 'credit_payment') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, 'refill_payment', ?)")->execute([$customer_id, $order_id, $amount, $payment_method, 'Credit payment - ' . $notes]);
                $pdo->prepare("UPDATE customers SET credit_used = GREATEST(0, credit_used - ?) WHERE id = ?")->execute([$amount, $customer_id]);
                $stmt = $pdo->prepare("SELECT credit_used FROM customers WHERE id = ?");
                $stmt->execute([$customer_id]);
                $new_balance = floatval($stmt->fetchColumn());
                $pdo->prepare("INSERT INTO credit_transactions (customer_id, refill_order_id, transaction_type, amount, balance_after, description) VALUES (?, NULL, 'payment', ?, ?, ?)")->execute([$customer_id, $amount, $new_balance, 'Payment against credit via AI']);
                $pdo->commit();
                return ['success' => true, 'data' => ['customer_id' => $customer_id, 'amount' => $amount, 'type' => 'credit_payment']];
            } catch (Exception $e) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Credit payment failed: ' . $e->getMessage()];
            }
        }

        $stmt = $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_id, $order_id, $amount, $payment_method, $payment_type, $notes]);
        $payment_id = $pdo->lastInsertId();

        if ($payment_type === 'deposit_added') {
            $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance + ? WHERE id = ?")->execute([$amount, $customer_id]);
        } elseif ($payment_type === 'deposit_refunded') {
            $net_refund = $amount - $damage_deduction;
            $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?")->execute([$amount, $customer_id]);
            if ($damage_deduction > 0) {
                $pdo->prepare("INSERT INTO payments (customer_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, 'deposit_damage', ?)")->execute([$customer_id, $damage_deduction, $payment_method, 'Damage deduction on deposit refund']);
            }
        }

        return ['success' => true, 'data' => ['payment_id' => (int)$payment_id, 'customer_id' => $customer_id, 'amount' => $amount]];
    }
}

if (!function_exists('actionAdjustDeposit')) {
    function actionAdjustDeposit($pdo, $params) {
        $customer_id = (int)$params['customer_id'];
        $amount = floatval($params['amount']);
        $type = $params['type'];
        $notes = $params['notes'] ?? '';
        $payment_method = $params['payment_method'] ?? 'Cash';
        $refill_order_id = !empty($params['refill_order_id']) ? (int)$params['refill_order_id'] : null;

        $pdo->beginTransaction();
        try {
            if ($type === 'add') {
                $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance + ? WHERE id = ?")->execute([$amount, $customer_id]);
                $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, 'deposit_added', ?)")->execute([$customer_id, $refill_order_id, $amount, $payment_method, $notes]);
            } elseif ($type === 'refund') {
                $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?")->execute([$amount, $customer_id]);
                $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, 'deposit_refunded', ?)")->execute([$customer_id, $refill_order_id, $amount, $payment_method, $notes]);
                // If linked to an order, update deposit_settled
                if ($refill_order_id) {
                    $pdo->prepare("UPDATE refill_orders SET deposit_settled = deposit_settled + ? WHERE id = ?")->execute([$amount, $refill_order_id]);
                }
            } else {
                throw new Exception("Invalid deposit type: $type");
            }
            $pdo->commit();
            return ['success' => true, 'data' => ['customer_id' => $customer_id, 'type' => $type, 'amount' => $amount]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to adjust deposit: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionCreateVendor')) {
    function actionCreateVendor($pdo, $params) {
        $states = function_exists('gstnStateCodes') ? gstnStateCodes() : [];
        $state_code = intval($params['state_code'] ?? 0);
        $state_name = $state_code ? ($states[$state_code] ?? null) : null;
        $stmt = $pdo->prepare("INSERT INTO vendors (name, contact_person, mobile, email, address, gst_number, gst_registration_type, pan, tan, state_code, state_name, city, pincode, bank_account_holder, bank_account_number, bank_ifsc, bank_name, bank_branch, payment_terms, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $params['name'],
            $params['contact_person'] ?? '',
            $params['mobile'],
            $params['email'] ?? null,
            $params['address'] ?? '',
            $params['gst_number'] ?? null,
            $params['gst_registration_type'] ?? 'regular',
            $params['pan'] ?? null,
            $params['tan'] ?? null,
            $state_code ?: null,
            $state_name,
            $params['city'] ?? null,
            $params['pincode'] ?? null,
            $params['bank_account_holder'] ?? null,
            $params['bank_account_number'] ?? null,
            $params['bank_ifsc'] ?? null,
            $params['bank_name'] ?? null,
            $params['bank_branch'] ?? null,
            intval($params['payment_terms'] ?? 30),
            $params['notes'] ?? null,
        ]);
        $id = $pdo->lastInsertId();
        return ['success' => true, 'data' => ['vendor_id' => (int)$id, 'name' => $params['name']]];
    }
}

if (!function_exists('actionUpdateVendor')) {
    function actionUpdateVendor($pdo, $params) {
        $fields = [];
        $values = [];
        $allowed = ['name', 'contact_person', 'mobile', 'email', 'address', 'gst_number', 'gst_registration_type', 'pan', 'tan', 'state_code', 'state_name', 'city', 'pincode', 'bank_account_holder', 'bank_account_number', 'bank_ifsc', 'bank_name', 'bank_branch', 'payment_terms', 'notes'];
        foreach ($allowed as $f) {
            if (isset($params[$f])) {
                $fields[] = "$f = ?";
                $values[] = $params[$f];
            }
        }
        if (empty($fields)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        $values[] = $params['id'];
        $stmt = $pdo->prepare("UPDATE vendors SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        return ['success' => true, 'data' => ['vendor_id' => (int)$params['id']]];
    }
}

if (!function_exists('actionDeleteVendor')) {
    function actionDeleteVendor($pdo, $params) {
        $id = (int)$params['id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_vendor_id = ? AND status = 'sent_to_vendor'");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'error' => 'Vendor has active dispatches. Cannot delete.'];
        }
        $pdo->prepare("DELETE FROM vendors WHERE id = ?")->execute([$id]);
        return ['success' => true, 'data' => ['vendor_id' => $id]];
    }
}

if (!function_exists('actionCreatePartner')) {
    function actionCreatePartner($pdo, $params) {
        $stmt = $pdo->prepare("INSERT INTO partners (company_name, contact_person, mobile, email, gst_number, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $params['company_name'],
            $params['contact_person'],
            $params['mobile'],
            $params['email'] ?? '',
            $params['gst_number'] ?? '',
            $params['address'] ?? '',
            $params['notes'] ?? '',
        ]);
        $id = $pdo->lastInsertId();
        return ['success' => true, 'data' => ['partner_id' => (int)$id, 'company_name' => $params['company_name']]];
    }
}

if (!function_exists('actionUpdatePartner')) {
    function actionUpdatePartner($pdo, $params) {
        $fields = [];
        $values = [];
        foreach (['company_name', 'contact_person', 'mobile', 'email', 'gst_number', 'address', 'notes', 'status'] as $f) {
            if (isset($params[$f])) {
                $fields[] = "$f = ?";
                $values[] = $params[$f];
            }
        }
        if (empty($fields)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        $values[] = $params['id'];
        $stmt = $pdo->prepare("UPDATE partners SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        return ['success' => true, 'data' => ['partner_id' => (int)$params['id']]];
    }
}

if (!function_exists('actionDeletePartner')) {
    function actionDeletePartner($pdo, $params) {
        $id = (int)$params['id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM partner_transaction_items WHERE transaction_id IN (SELECT id FROM partner_transactions WHERE partner_id = ?)")->execute([$id]);
            $pdo->prepare("DELETE FROM partner_transactions WHERE partner_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$id]);
            $pdo->commit();
            return ['success' => true, 'data' => ['partner_id' => $id]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to delete partner: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionBorrowFromPartner')) {
    function actionBorrowFromPartner($pdo, $params) {
        $partner_id = (int)$params['partner_id'];
        $cylinder_ids = $params['cylinder_ids'];
        $notes = $params['notes'] ?? 'Borrowed from partner via AI';

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO partner_transactions (partner_id, transaction_type, notes, created_by) VALUES (?, 'borrowed_from_partner', ?, 'AI')");
            $stmt->execute([$partner_id, $notes]);
            $txn_id = $pdo->lastInsertId();

            foreach ($cylinder_ids as $cid) {
                $cid = (int)$cid;
                $stmt = $pdo->prepare("SELECT serial_number, gas_type_id, size_capacity, status FROM cylinders WHERE id = ?");
                $stmt->execute([$cid]);
                $cyl = $stmt->fetch();
                if ($cyl) {
                    $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after) VALUES (?, ?, ?, ?, ?, ?, 'borrowed_from_partner')")->execute([$txn_id, $cid, $cyl['serial_number'], $cyl['gas_type_id'], $cyl['size_capacity'], $cyl['status']]);
                    $pdo->prepare("UPDATE cylinders SET status = 'borrowed_from_partner', current_partner_id = ? WHERE id = ?")->execute([$partner_id, $cid]);
                    logCylinderTransaction($pdo, $cid, null, null, 'partner_borrow', "Borrowed from partner ID $partner_id");
                }
            }

            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['transaction_id' => (int)$txn_id, 'cylinder_count' => count($cylinder_ids)]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to borrow from partner: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionLendToPartner')) {
    function actionLendToPartner($pdo, $params) {
        $partner_id = (int)$params['partner_id'];
        $cylinder_ids = $params['cylinder_ids'];
        $notes = $params['notes'] ?? 'Lent to partner via AI';

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO partner_transactions (partner_id, transaction_type, notes, created_by) VALUES (?, 'lent_to_partner', ?, 'AI')");
            $stmt->execute([$partner_id, $notes]);
            $txn_id = $pdo->lastInsertId();

            foreach ($cylinder_ids as $cid) {
                $cid = (int)$cid;
                $stmt = $pdo->prepare("SELECT serial_number, gas_type_id, size_capacity, status FROM cylinders WHERE id = ?");
                $stmt->execute([$cid]);
                $cyl = $stmt->fetch();
                if ($cyl) {
                    $pdo->prepare("INSERT INTO partner_transaction_items (transaction_id, cylinder_id, serial_number, gas_type_id, size_capacity, status_before, status_after) VALUES (?, ?, ?, ?, ?, ?, 'lent_to_partner')")->execute([$txn_id, $cid, $cyl['serial_number'], $cyl['gas_type_id'], $cyl['size_capacity'], $cyl['status']]);
                    $pdo->prepare("UPDATE cylinders SET status = 'lent_to_partner', current_partner_id = ? WHERE id = ?")->execute([$partner_id, $cid]);
                    logCylinderTransaction($pdo, $cid, null, null, 'partner_lend', "Lent to partner ID $partner_id");
                }
            }

            $pdo->commit();
            syncInventory($pdo);
            return ['success' => true, 'data' => ['transaction_id' => (int)$txn_id, 'cylinder_count' => count($cylinder_ids)]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to lend to partner: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('actionCreateGasType')) {
    function actionCreateGasType($pdo, $params) {
        $stmt = $pdo->prepare("INSERT INTO gas_types (name, chemical_formula, default_price_per_kg, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $params['name'],
            $params['chemical_formula'] ?? '',
            floatval($params['default_price_per_kg'] ?? 0),
            $params['description'] ?? '',
        ]);
        $id = $pdo->lastInsertId();

        // Also populate gas_sizes if sizes provided
        $sizes = $params['sizes'] ?? '';
        if (!empty($sizes)) {
            $size_list = is_array($sizes) ? $sizes : array_map('trim', explode(',', $sizes));
            $size_prices = $params['size_prices'] ?? [];
            if (!is_array($size_prices)) { $size_prices = json_decode($size_prices, true) ?? []; }
            $ins = $pdo->prepare("INSERT INTO gas_sizes (gas_type_id, size_capacity, price, sort_order) VALUES (?, ?, ?, ?)");
            foreach ($size_list as $i => $sz) {
                if (empty($sz)) continue;
                $price = isset($size_prices[$sz]) ? floatval($size_prices[$sz]) : null;
                $ins->execute([$id, $sz, $price, $i]);
            }
        }

        return ['success' => true, 'data' => ['gas_type_id' => (int)$id, 'name' => $params['name']]];
    }
}

if (!function_exists('actionUpdateGasType')) {
    function actionUpdateGasType($pdo, $params) {
        $fields = [];
        $values = [];
        foreach (['name', 'chemical_formula', 'default_price_per_kg', 'description'] as $f) {
            if (isset($params[$f])) {
                $fields[] = "$f = ?";
                $values[] = $params[$f];
            }
        }
        if (empty($fields)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        $values[] = $params['id'];
        $stmt = $pdo->prepare("UPDATE gas_types SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);

        // Sync gas_sizes if sizes provided
        if (isset($params['sizes'])) {
            $sizes_list = is_array($params['sizes']) ? $params['sizes'] : array_map('trim', explode(',', $params['sizes']));
            $size_prices = $params['size_prices'] ?? [];
            if (!is_array($size_prices)) { $size_prices = json_decode($size_prices, true) ?? []; }
            $pdo->prepare("DELETE FROM gas_sizes WHERE gas_type_id = ?")->execute([$params['id']]);
            $ins = $pdo->prepare("INSERT INTO gas_sizes (gas_type_id, size_capacity, price, sort_order) VALUES (?, ?, ?, ?)");
            foreach ($sizes_list as $i => $sz) {
                if (empty($sz)) continue;
                $price = isset($size_prices[$sz]) ? floatval($size_prices[$sz]) : null;
                $ins->execute([$params['id'], $sz, $price, $i]);
            }
        }

        return ['success' => true, 'data' => ['gas_type_id' => (int)$params['id']]];
    }
}
