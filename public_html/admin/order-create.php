<?php
$page_title = "Create Refill Order";
$active_menu = "orders";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'inventory-utils.php';
require_once __DIR__ . '/gst_helper.php';
runConsumerCylinderMigrations($pdo);
runRefillRentalMigrations($pdo);
runSellCylinderMigrations($pdo);
runCreditMigrations($pdo);
runLedgerGroupMigrations($pdo);
runProductMigrations($pdo);
runCustomerRefillMigrations($pdo);
runRefillCostMigrations($pdo);
require_once 'business_helper.php';

$error = '';
$success = '';
$preselect_customer = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$deposit_receipt = isset($_GET['receipt_id']) ? intval($_GET['receipt_id']) : 0;
if (isset($_GET['deposit_success']) && $preselect_customer > 0) {
    $success = 'Payment recorded successfully!';
}

// Handle Record Deposit inline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_deposit') {
    $dep_customer_id = intval($_POST['dep_customer_id'] ?? 0);
    $dep_amount = floatval($_POST['dep_amount'] ?? 0);
    $dep_method = $_POST['dep_method'] ?? 'Cash';
    $dep_notes = trim($_POST['dep_notes'] ?? '');
    $dep_date_raw = $_POST['dep_date'] ?? date('Y-m-d\TH:i');
    $dep_date = str_replace('T', ' ', $dep_date_raw) . ':00';

    if ($dep_customer_id <= 0 || $dep_amount <= 0) {
        $error = "Invalid customer or amount.";
    } else {
        try {
            require_once 'inventory-utils.php';
            runDepositReceiptMigrations($pdo);
            runLedgerGroupMigrations($pdo);

            $stmt = $pdo->prepare("SELECT credit_used, deposit_balance FROM customers WHERE id = ?");
            $stmt->execute([$dep_customer_id]);
            $dep_customer = $stmt->fetch();

            if (!$dep_customer) {
                throw new Exception("Customer not found.");
            }

            $ledger_group_id = generateLedgerGroupId();
            $pdo->beginTransaction();

            $payment_id = null;

            // Use shared credit settlement helper
            $settle = processPaymentWithCreditSettlement($pdo, $dep_customer_id, $dep_amount, $dep_method, $dep_date, $dep_notes, $ledger_group_id);
            $payment_id = $settle['payment_id'];
            $amount_for_credit = $settle['amount_for_credit'];
            $amount_for_deposit = $settle['amount_for_deposit'];

            if ($payment_id) {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM deposit_receipts");
                $stmt->execute();
                $next_id = $stmt->fetchColumn();
                $receipt_number = 'DEP-' . date('Y') . '-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

                $total_amount = $dep_amount;
                $receipt_label_txt = $settle['has_credit_settlement'] ? 'Payment Received' : 'Deposit Added';
                $receipt_credit = $amount_for_credit;
                $receipt_deposit = $amount_for_deposit;

                $stmt = $pdo->prepare("INSERT INTO deposit_receipts (receipt_number, payment_id, customer_id, receipt_date, damage_deduction, transaction_label, total_amount, credit_settled, deposit_amount, ledger_group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$receipt_number, $payment_id, $dep_customer_id, $dep_date, 0, $receipt_label_txt, $total_amount, $receipt_credit, $receipt_deposit, $ledger_group_id]);
                $receipt_id = $pdo->lastInsertId();
            }

            // Create ledger group entry
            if ($settle['has_credit_settlement']) {
                $group_title = 'Payment Received – ₹' . number_format($dep_amount, 2);
                if ($amount_for_credit > 0 && $amount_for_deposit > 0) {
                    $group_title .= ' (₹' . number_format($amount_for_credit, 2) . ' dues + ₹' . number_format($amount_for_deposit, 2) . ' deposit)';
                } elseif ($amount_for_credit > 0) {
                    $group_title .= ' (Dues settled)';
                }
            } else {
                $group_title = 'Deposit Added – ₹' . number_format($dep_amount, 2);
            }
            $stmt = $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'payment_received', ?, ?, ?)");
            $stmt->execute([$ledger_group_id, $dep_customer_id, $group_title, $dep_amount, $dep_date]);

            $pdo->commit();

            // Send email notification
            require_once __DIR__ . '/../portal/email.php';
            if ($settle['has_credit_settlement']) {
                sendPaymentReceivedNotification($dep_customer_id, $dep_amount, $dep_method, $pdo, $amount_for_credit, $amount_for_deposit);
            } else {
                sendDepositNotification($dep_customer_id, $dep_amount, $dep_method, $pdo);
            }

            if (isset($_POST['ajax'])) {
                ob_clean();
                echo json_encode([
                    'success' => true,
                    'receipt_url' => ($payment_id && isset($receipt_id))
                        ? "deposit-receipt.php?receipt_id=$receipt_id&business=" . getBrandConfig()['business_key']
                        : null
                ]);
                exit();
            }

            if ($payment_id && isset($receipt_id)) {
                echo "<script>window.location.href='order-create.php?deposit_success=1&customer_id=$dep_customer_id&receipt_id=$receipt_id';</script>";
            } else {
                echo "<script>window.location.href='order-create.php?deposit_success=1&customer_id=$dep_customer_id';</script>";
            }
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (isset($_POST['ajax'])) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit();
            }
            $error = $e->getMessage();
        }
    }
}

// Handle Order Checkout Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout') {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $items = $_POST['items'] ?? [];
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $notes = trim($_POST['notes'] ?? '');
    
    $gst_rate = floatval($_POST['gst_rate'] ?? 18);
    if ($gst_rate === 0 && isset($_POST['gst_rate']) && $_POST['gst_rate'] === 'custom') {
        $gst_rate = floatval($_POST['gst_custom_rate'] ?? 0);
    }
    $discount = isset($_POST['discount']) ? floatval($_POST['discount']) : 0.00;
    $order_date_raw = $_POST['order_date'] ?? date('Y-m-d\TH:i');
    $order_date = str_replace('T', ' ', $order_date_raw) . ':00';
    $vehicle_number = trim($_POST['vehicle_number'] ?? '');
    $returned_cylinder_ids = $_POST['returned_cylinder_ids'] ?? [];
    $business_name = isset($_POST['business_name']) && array_key_exists($_POST['business_name'], getBusinesses()) ? $_POST['business_name'] : getBrandConfig()['business_key'];
    
    if ($customer_id <= 0 || empty($items)) {
        $error = "Please select a valid customer and add at least one item.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Fetch Customer Details
            $cust_stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $cust_stmt->execute([$customer_id]);
            $customer = $cust_stmt->fetch();
            
            if (!$customer) {
                throw new Exception("Customer not registered.");
            }
            
            // Auto-classify invoice type from customer GSTIN
            $invoice_type = autoClassifyInvoice($customer);
            $include_in_gst_return = ($gst_rate > 0) ? 1 : 0;
            if ($include_in_gst_return === 0) $invoice_type = 'b2c'; // excluded = B2C
            $place_of_supply = intval($customer['state_code'] ?? 0);
            if ($place_of_supply <= 0) $place_of_supply = null;
            
            // ── Pre-process Return-Only Serials (customer's own CON cylinders) ──
            $return_only_serials = $_POST['return_only_serials'] ?? [];
            $return_only_ledger_id = null;
            $return_only_serials_list = [];
            foreach ($return_only_serials as $ros) {
                $parts = explode('|', $ros);
                $serial = trim($parts[0] ?? '');
                $gas_type_id = intval($parts[1] ?? 0);
                $size_capacity = trim($parts[2] ?? '');
                if (empty($serial)) continue;
                
                $chk = $pdo->prepare("SELECT id, status, current_customer_id, ownership_type, original_owner_customer_id, gas_type_id, size_capacity FROM cylinders WHERE serial_number = ?");
                $chk->execute([$serial]);
                $cyl = $chk->fetch();
                
                if (!$cyl) {
                    throw new Exception("Return cylinder '$serial' not found in system.");
                }
                if ($cyl['ownership_type'] !== 'consumer_owned') {
                    throw new Exception("Cylinder '$serial' is not consumer-owned. Cannot return to customer.");
                }
                if ($cyl['original_owner_customer_id'] != $customer_id) {
                    throw new Exception("Cylinder '$serial' belongs to a different customer. Cannot return via this order.");
                }
                
                if (!$return_only_ledger_id) {
                    $return_only_ledger_id = generateLedgerGroupId();
                }
                
                $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?")->execute([$cyl['id']]);
                logCylinderTransaction($pdo, $cyl['id'], $customer_id, null, 'consumer_give_back', "Consumer received own cylinder back via Return-to-Customer on Order", $return_only_ledger_id);
                $return_only_serials_list[] = $serial;
                
                // Also clear any matching return serial from items to avoid double processing
                foreach ($items as $idx => $item) {
                    if (isset($item['returned_cylinders'])) {
                        foreach ($item['returned_cylinders'] as $ri => $rs) {
                            if (trim($rs) === $serial) {
                                $items[$idx]['returned_cylinders'][$ri] = '';
                            }
                        }
                    }
                }
            }
            if ($return_only_ledger_id) {
                $ro_title = "Cylinder Return to Customer — " . implode(', ', $return_only_serials_list);
                $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'exchange_settlement', ?, 0.00, ?)")
                    ->execute([$return_only_ledger_id, $customer_id, $ro_title, $order_date]);
            }
            
            $subtotal = 0.00;
            
            // Validate stocks and calculate custom overridden rates
            $processed_items = [];
            $already_allocated_ids = [];
            foreach ($items as $index => $item) {
                $raw_gas_type = $item['gas_type_id'] ?? 0;
                $gas_type_id = intval($raw_gas_type);
                $size_capacity = trim($item['size_capacity'] ?? '');
                $qty = intval($item['qty'] ?? 1);
                $is_rental = isset($item['is_rental']) ? intval($item['is_rental']) : 0;

                
                // Allow custom price overrides
                $custom_price = isset($item['custom_price']) ? floatval($item['custom_price']) : -1.0;
                
                // Rent rates
                $rent_per_day = ($is_rental === 1) ? floatval($item['rent_per_day'] ?? 10.00) : 0.00;
                $free_days = ($is_rental === 1) ? intval($item['free_days'] ?? 3) : 0;
                $deposit_amount = ($is_rental === 1) ? floatval($item['deposit_amount'] ?? 0.00) : 0.00;
                
                // Sell cylinder data (array of cylinder IDs for multi-qty)
                $sell_cylinder_ids = ($is_rental === 2) ? ($item['sell_cylinder_ids'] ?? []) : [];
                $sell_price = ($is_rental === 2) ? floatval($item['sell_price'] ?? 0.00) : 0.00;
                // Product data
                $product_id = ($is_rental === 3) ? intval($item['product_id'] ?? 0) : 0;
                $product_qty = ($is_rental === 3) ? intval($item['product_qty'] ?? 1) : 0;
                $product_sell_price = ($is_rental === 3 && isset($item['product_sell_price']) && $item['product_sell_price'] !== '') ? floatval($item['product_sell_price']) : -1.0;

                // Customer Refill Service (Type 2) data
                $customer_cyl_serials = [];
                if ($is_rental === 4) {
                    $raw_cyl = $item['customer_cylinders'] ?? '';
                    if (is_array($raw_cyl)) {
                        $customer_cyl_serials = array_values(array_filter(array_map('trim', $raw_cyl), fn($v) => $v !== ''));
                    } else {
                        $raw_cyl = trim($raw_cyl);
                        $customer_cyl_serials = $raw_cyl !== '' ? array_map('trim', explode(',', $raw_cyl)) : [];
                    }
                }
                
                // Specific issued serial preferences (array of length $qty) - only for refill/rental
                $issued_cyl_choices = ($is_rental !== 2 && $is_rental !== 4) ? ($item['issued_cylinders'] ?? []) : [];
                // Returned cylinder serial inputs (array of length $qty) - only for refill mode
                $returned_cyl_serials = ($is_rental === 0) ? ($item['returned_cylinders'] ?? []) : [];
                if ($is_rental === 3) {
                    // Product mode
                    if ($product_id <= 0) {
                        throw new Exception("Please select a product to sell.");
                    }
                    $prod_stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                    $prod_stmt->execute([$product_id]);
                    $product = $prod_stmt->fetch();
                    if (!$product) {
                        throw new Exception("Selected product not found.");
                    }
                    if ($product['stock_quantity'] < $product_qty) {
                        throw new Exception("Insufficient stock for '".$product['name']."'. Available: ".$product['stock_quantity'].", requested: ".$product_qty.".");
                    }
                    $price_per_unit = floatval($product['price']);
                    if ($product_sell_price >= 0) {
                        $price_per_unit = $product_sell_price;
                    } elseif ($custom_price >= 0) {
                        $price_per_unit = $custom_price;
                    }
                    $item_total = $price_per_unit * $product_qty;
                    $subtotal += $item_total;
                } else {
                    if ($gas_type_id <= 0 || empty($size_capacity) || $qty <= 0) {
                        throw new Exception("Invalid order item parameters.");
                    }
                    
                    // Fetch Gas details
                    $gas_stmt = $pdo->prepare("SELECT * FROM gas_types WHERE id = ?");
                    $gas_stmt->execute([$gas_type_id]);
                    $gas = $gas_stmt->fetch();
                    
                    if (!$gas) {
                        throw new Exception("Gas type not recognized.");
                    }
                }
                
                // Skip gas price resolution and cylinder allocation for product mode
                $allocated_cylinders = [];
                $sold_cylinder_serial = null;
                if ($is_rental === 3) {
                    // Product mode — no cylinder allocation, handled above
                    $processed_items[] = [
                        'is_product' => true,
                        'product_id' => $product_id,
                        'product_name' => $product['name'],
                        'product_unit' => $product['unit'] ?: 'piece',
                        'qty' => $product_qty,
                        'is_rental' => 3,
                        'price_per_unit' => $price_per_unit,
                        'gst_rate' => $gst_rate,
                        'allocated' => []
                    ];
                    continue;
                }

                // Customer Cylinder Refill Service (Type 2) — no company cylinder allocated
                if ($is_rental === 4) {
                    if (empty($customer_cyl_serials)) {
                        throw new Exception("Please enter at least one customer cylinder serial number for refill service.");
                    }
                    $service_price = ($custom_price >= 0) ? $custom_price : floatval($gas['default_price_per_kg']);
                    $batch_qty = count($customer_cyl_serials);
                    $item_total = $service_price * $batch_qty;
                    $subtotal += $item_total;
                    $processed_items[] = [
                        'gas_type_id' => $gas_type_id,
                        'size_capacity' => $size_capacity,
                        'qty' => $batch_qty,
                        'is_rental' => 4,
                        'price_per_unit' => $service_price,
                        'customer_serials' => $customer_cyl_serials,
                        'gst_rate' => $gst_rate,
                        'allocated' => $allocated_cylinders,
                    ];
                    continue;
                }

                // Resolve price from gas_sizes
                $price_per_unit = floatval($gas['default_price_per_kg']);
                if ($custom_price >= 0) {
                    $price_per_unit = $custom_price;
                } else if (isset($gas_sizes_map[$gas['id']]['prices'][$size_capacity])) {
                    $sp = $gas_sizes_map[$gas['id']]['prices'][$size_capacity];
                    if ($sp > 0) { $price_per_unit = floatval($sp); }
                }
                
                $item_total = $price_per_unit * $qty;
                $subtotal += $item_total;
                
                // Allocate physical cylinders for this item row
                $allocated_cylinders = [];
                $sold_cylinder_serial = null;
                
                if ($is_rental === 2) {
                    // SELL MODE: sell cylinders to customer (remove from system)
                    $sell_serials = [];
                    for ($si = 0; $si < $qty; $si++) {
                        $sid = isset($sell_cylinder_ids[$si]) ? intval($sell_cylinder_ids[$si]) : 0;
                        if ($sid <= 0) {
                            throw new Exception("Please select a cylinder to sell for item #" . ($si + 1) . ".");
                        }
                        if (in_array($sid, $already_allocated_ids)) {
                            throw new Exception("Sell cylinder ID $sid was selected multiple times.");
                        }
                        $sell_chk = $pdo->prepare("SELECT id, serial_number FROM cylinders WHERE id = ? AND gas_type_id = ? AND size_capacity = ? AND status = 'filled' AND ownership_type = 'owned'");
                        $sell_chk->execute([$sid, $gas_type_id, $size_capacity]);
                        $sell_cyl = $sell_chk->fetch();
                        if (!$sell_cyl) {
                            throw new Exception("Selected sell cylinder is not available as 'filled' in stock.");
                        }
                        $sell_serials[] = $sell_cyl['serial_number'];
                        $already_allocated_ids[] = $sid;
                        $allocated_cylinders[] = [
                            'id' => $sid,
                            'returned_serial' => '',
                            'is_sell' => true,
                            'sell_serial' => $sell_cyl['serial_number']
                        ];
                    }
                    $subtotal += $sell_price;
                } else {
                    // REFILL or RENTAL mode: allocate filled cylinders
                    for ($i = 0; $i < $qty; $i++) {
                        $chosen_id = $issued_cyl_choices[$i] ?? 'auto';
                        $allocated_cyl_id = null;
                        
                        if ($chosen_id !== 'auto') {
                            $chosen_id = intval($chosen_id);
                            if (in_array($chosen_id, $already_allocated_ids)) {
                                throw new Exception("Cylinder ID $chosen_id was selected multiple times.");
                            }
                            
                            $chk_cyl = $pdo->prepare("SELECT id FROM cylinders WHERE id = ? AND gas_type_id = ? AND size_capacity = ? AND status = 'filled' FOR UPDATE");
                            $chk_cyl->execute([$chosen_id, $gas_type_id, $size_capacity]);
                            if ($chk_cyl->fetch()) {
                                $allocated_cyl_id = $chosen_id;
                            } else {
                                throw new Exception("Selected cylinder serial is not currently 'filled' in stock.");
                            }
                        }
                        
                        if (!$allocated_cyl_id) {
                            $exclude_clause = "";
                            if (!empty($already_allocated_ids)) {
                                $exclude_clause = " AND id NOT IN (" . implode(',', array_map('intval', $already_allocated_ids)) . ")";
                            }
                            
                            $find_stmt = $pdo->prepare("
                                SELECT id FROM cylinders 
                                WHERE gas_type_id = ? AND size_capacity = ? AND status = 'filled' 
                                $exclude_clause 
                                LIMIT 1 
                                FOR UPDATE
                            ");
                            $find_stmt->execute([$gas_type_id, $size_capacity]);
                            $found = $find_stmt->fetch();
                            if ($found) {
                                $allocated_cyl_id = intval($found['id']);
                            } else {
                                throw new Exception("Insufficient stock of filled cylinders for " . $gas['name'] . " (" . $size_capacity . ").");
                            }
                        }
                        
                        $already_allocated_ids[] = $allocated_cyl_id;
                        
                        // Track the return serial if any (refill mode only)
                        $ret_serial = ($is_rental === 0) ? (isset($returned_cyl_serials[$i]) ? trim($returned_cyl_serials[$i]) : '') : '';
                        
                        $allocated_cylinders[] = [
                            'id' => $allocated_cyl_id,
                            'returned_serial' => $ret_serial,
                            'is_sell' => false
                        ];
                    }
                }
                
                $processed_items[] = [
                    'gas_type_id' => $gas_type_id,
                    'size_capacity' => $size_capacity,
                    'qty' => $qty,
                    'is_rental' => $is_rental,
                    'price_per_unit' => $price_per_unit,
                    'sell_price' => $sell_price,
                    'rent_per_day' => $rent_per_day,
                    'free_days' => $free_days,
                    'deposit_amount' => $deposit_amount,
                    'allocated' => $allocated_cylinders,
                    'gst_rate' => $gst_rate
                ];
            }
            
            // Financial sums - configurable GST rate (deposits excluded completely)
            $tax_amount = $gst_rate > 0 ? ($subtotal * $gst_rate / 100) : 0.00;
            $grand_total = max(0.00, $subtotal + $tax_amount - $discount);
            
            // Total deposit across all items
            $total_deposit_amount = 0.00;
            foreach ($processed_items as $pi) {
                if ($pi['is_rental'] === 1) {
                    $total_deposit_amount += floatval($pi['deposit_amount'] ?? 0) * intval($pi['qty'] ?? 1);
                }
            }

            $all_refill_no_exchange = true;
            $has_any_refill = false;
            foreach ($processed_items as $pi) {
                if ($pi['is_rental'] === 0) {
                    $has_any_refill = true;
                    foreach ($pi['allocated'] as $alloc) {
                        if (!empty($alloc['returned_serial'])) {
                            $all_refill_no_exchange = false;
                        }
                    }
                }
            }
            $order_type = $has_any_refill && $all_refill_no_exchange ? 'refill_without_exchange' : 'refill_with_exchange';

            // 1. Create Refill Order
            $is_credit_order = ($payment_method === 'Credit') ? 1 : 0;
            $payment_status = $is_credit_order ? 'pending' : 'paid';
            $stmt = $pdo->prepare("
                INSERT INTO refill_orders (customer_id, order_date, subtotal, deposit_amount, tax_amount, discount, grand_total, payment_status, payment_method, notes, business_name, vehicle_number, is_credit_order, order_type, gst_rate, include_in_gst_return, invoice_type, place_of_supply_state_code) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$customer_id, $order_date, $subtotal, $total_deposit_amount, $tax_amount, $discount, $grand_total, $payment_status, $payment_method, $notes, $business_name, $vehicle_number ?: null, $is_credit_order, $order_type, $gst_rate, $include_in_gst_return, $invoice_type, $place_of_supply]);
            $order_id = $pdo->lastInsertId();
            
            // 2. Insert Order Items & lease physical cylinders & record exchanges
            foreach ($processed_items as $item) {
                // Product items — single insert, no cylinder processing
                if (!empty($item['is_product'])) {
                    $item_gst_rate = floatval($item['gst_rate'] ?? 0);
                    $item_taxable = $item['qty'] * $item['price_per_unit'];
                    $item_gst_amt = $item_gst_rate > 0 ? ($item_taxable * $item_gst_rate / 100) : 0;
                    $item_cgst = $item_gst_amt / 2;
                    $item_sgst = $item_gst_amt / 2;
                    $stmt = $pdo->prepare("
                        INSERT INTO refill_order_items (refill_order_id, gas_type_id, product_id, cylinder_id, size_capacity, qty, price_per_unit, is_rental, deposit_amount, rent_per_day, free_days, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst) 
                        VALUES (?, NULL, ?, NULL, '', ?, ?, 3, 0.00, 0.00, 0, ?, ?, ?, ?, ?, 0.00)
                    ");
                    $stmt->execute([$order_id, $item['product_id'], $item['qty'], $item['price_per_unit'], $item_gst_rate, $item_taxable, $item_gst_amt, $item_cgst, $item_sgst]);
                    // Decrement product stock
                    $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?")->execute([$item['qty'], $item['product_id']]);
                    continue;
                }
                // Customer Cylinder Refill Service (Type 2) — always received at warehouse first
                if ($item['is_rental'] === 4) {
                    $serials = $item['customer_serials'] ?? [];

                    $service_price = $item['price_per_unit'];
                    $batch_qty = count($serials);
                    $batch_service_total = $service_price * $batch_qty;
                    $item_gst_rate = floatval($item['gst_rate'] ?? 0);
                    $batch_taxable = $batch_service_total;
                    $batch_gst_amt = $item_gst_rate > 0 ? ($batch_taxable * $item_gst_rate / 100) : 0;
                    $batch_cgst = $batch_gst_amt / 2;
                    $batch_sgst = $batch_gst_amt / 2;

                    // Insert a single refill_order_items record for the batch
                    $stmt = $pdo->prepare("
                        INSERT INTO refill_order_items (refill_order_id, gas_type_id, cylinder_id, size_capacity, qty, price_per_unit, is_rental, deposit_amount, rent_per_day, free_days, returned_cylinder_id, sell_price, sold_cylinder_serial, vendor_id, vendor_cost, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst) 
                        VALUES (?, ?, NULL, ?, ?, ?, 4, 0.00, 0.00, 0, NULL, 0.00, NULL, NULL, 0.00, ?, ?, ?, ?, ?, 0.00)
                    ");
                    $stmt->execute([$order_id, $item['gas_type_id'], $item['size_capacity'], $batch_qty, $service_price, $item_gst_rate, $batch_taxable, $batch_gst_amt, $batch_cgst, $batch_sgst]);

                    // Process each serial in the batch
                    foreach ($serials as $customer_serial) {
                        if (empty($customer_serial)) continue;

                        // Register or find the customer's cylinder
                        $chk_cyl = $pdo->prepare("SELECT id FROM cylinders WHERE serial_number = ?");
                        $chk_cyl->execute([$customer_serial]);
                        $existing_cyl = $chk_cyl->fetch();

                        if ($existing_cyl) {
                            $cyl_id = intval($existing_cyl['id']);
                            $own_check = $pdo->prepare("SELECT id, ownership_type, original_owner_customer_id FROM cylinders WHERE id = ?");
                            $own_check->execute([$cyl_id]);
                            $cyl_data = $own_check->fetch();
                            if ($cyl_data && $cyl_data['ownership_type'] === 'consumer_owned' && $cyl_data['original_owner_customer_id'] != $customer_id) {
                                throw new Exception("Cylinder '$customer_serial' belongs to another customer and cannot be accepted for refill service.");
                            }
                            $pdo->prepare("UPDATE cylinders SET status = 'received_for_refill', current_customer_id = NULL, is_customer_refill_cylinder = 1 WHERE id = ?")->execute([$cyl_id]);
                        } else {
                            $ins = $pdo->prepare("INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, ownership_type, original_owner_customer_id, is_customer_refill_cylinder) VALUES (?, ?, ?, 'received_for_refill', 'consumer_owned', ?, 1)");
                            $ins->execute([$customer_serial, $item['gas_type_id'], $item['size_capacity'], $customer_id]);
                            $cyl_id = $pdo->lastInsertId();
                        }

                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'received_for_refill', "Received for customer refill service on Order #ORD-$order_id");

                        // Create customer_refill_services record
                        $svc_stmt = $pdo->prepare("
                            INSERT INTO customer_refill_services (customer_id, cylinder_id, serial_number, gas_type_id, size_capacity, vendor_id, refill_order_id, service_date, service_charge, vendor_cost, payment_status, status, notes, refill_source)
                            VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 0.00, 'pending', 'received', ?, 'warehouse')
                        ");
                        $svc_stmt->execute([$customer_id, $cyl_id, $customer_serial, $item['gas_type_id'], $item['size_capacity'], $order_id, $order_date, $service_price, "Created via Order #ORD-$order_id"]);
                    }

                    continue;
                }

                foreach ($item['allocated'] as $allocated) {
                    $cyl_id = $allocated['id'];
                    $ret_serial = $allocated['returned_serial'];
                    $is_sell = $allocated['is_sell'] ?? false;
                    
                    $ret_cyl_id = null;
                    $sold_cyl_serial = null;
                    
                    if ($is_sell) {
                        // SELL MODE: archive and remove cylinder from system
                        $sold_cyl_serial = $allocated['sell_serial'] ?? '';
                        $sell_price_val = $item['sell_price'];
                        
                        // Fetch cylinder for archiving and cost
                        $sell_fetch = $pdo->prepare("SELECT *, purchase_price AS cyl_refill_cost FROM cylinders WHERE id = ?");
                        $sell_fetch->execute([$cyl_id]);
                        $sell_cyl_data = $sell_fetch->fetch();
                        $sell_refill_cost = $sell_cyl_data ? floatval($sell_cyl_data['purchase_price'] ?? 0) : 0;
                        
                        if ($sell_cyl_data) {
                            archiveDeletedCylinder($pdo, $cyl_id, $_SESSION['username'] ?? 'admin');
                        }
                        
                        // GST calculation for this sell item (sell_price + price_per_unit)
                        $sell_gst_rate = floatval($item['gst_rate'] ?? 0);
                        $sell_taxable = floatval($sell_price_val) + (floatval($item['price_per_unit']) * max(1, intval($item['qty'])));
                        $sell_gst_amt = $sell_gst_rate > 0 ? ($sell_taxable * $sell_gst_rate / 100) : 0;
                        $sell_cgst = $sell_gst_amt / 2;
                        $sell_sgst = $sell_gst_amt / 2;

                        // Insert into refill_order_items (no cylinder_id FK, store serial in sold_cylinder_serial)
                        $stmt = $pdo->prepare("
                            INSERT INTO refill_order_items (refill_order_id, gas_type_id, cylinder_id, size_capacity, qty, price_per_unit, is_rental, deposit_amount, rent_per_day, free_days, returned_cylinder_id, sell_price, sold_cylinder_serial, refill_cost, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst) 
                            VALUES (?, ?, NULL, ?, 1, ?, ?, 0.00, 0.00, 0, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0.00)
                        ");
                        $stmt->execute([
                            $order_id,
                            $item['gas_type_id'],
                            $item['size_capacity'],
                            $item['price_per_unit'],
                            $item['is_rental'],
                            $sell_price_val,
                            $sold_cyl_serial,
                            $sell_refill_cost,
                            $sell_gst_rate,
                            $sell_taxable,
                            $sell_gst_amt,
                            $sell_cgst,
                            $sell_sgst
                        ]);
                        
                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'issue_to_customer', "SOLD cylinder $sold_cyl_serial on Order #ORD-$order_id (removed from system)");
                    } else {
                        // REFILL or RENTAL mode: process exchange returns (refill only)
                        if ($item['is_rental'] === 0 && !empty($ret_serial)) {
                            $chk_stmt = $pdo->prepare("SELECT id, status, current_customer_id, ownership_type, original_owner_customer_id, gas_type_id, size_capacity FROM cylinders WHERE serial_number = ?");
                            $chk_stmt->execute([$ret_serial]);
                            $ret_cyl = $chk_stmt->fetch();

                            if ($ret_cyl) {
                                $ret_cyl_id = intval($ret_cyl['id']);

                                if ($ret_cyl['gas_type_id'] != $item['gas_type_id']) {
                                    $ret_gas_stmt = $pdo->prepare("SELECT name FROM gas_types WHERE id = ?");
                                    $ret_gas_stmt->execute([$ret_cyl['gas_type_id']]);
                                    $ret_gas_name = $ret_gas_stmt->fetchColumn();
                                    throw new Exception("Gas type mismatch: Returned cylinder '$ret_serial' is $ret_gas_name but order item is " . $gas['name'] . ". Returned cylinder must match the gas type being issued.");
                                }

                                if ($ret_cyl['ownership_type'] === 'consumer_owned' && $ret_cyl['original_owner_customer_id'] == $customer_id) {
                                    $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?")->execute([$ret_cyl_id]);
                                    logCylinderTransaction($pdo, $ret_cyl_id, $customer_id, null, 'consumer_give_back', "Consumer received own cylinder back on Order #ORD-$order_id. Exchange SETTLED.");
                                } elseif ($ret_cyl['ownership_type'] === 'consumer_owned' && $ret_cyl['original_owner_customer_id'] != $customer_id) {
                                    $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$customer_id, $ret_cyl_id]);
                                    logCylinderTransaction($pdo, $ret_cyl_id, $customer_id, null, 'consumer_return', "Consumer-owned cylinder transferred to customer on Order #ORD-$order_id.");
                                } else {
                                    $pdo->prepare("UPDATE cylinders SET status = 'empty', current_customer_id = NULL WHERE id = ?")->execute([$ret_cyl_id]);
                                    logCylinderTransaction($pdo, $ret_cyl_id, $customer_id, null, 'return_from_customer', "Returned empty serial $ret_serial on Order #ORD-$order_id Exchange");
                                }

                                if ($ret_cyl['current_customer_id'] == $customer_id) {
                                    $up_cust = $pdo->prepare("UPDATE customers SET active_cylinders_count = GREATEST(0, active_cylinders_count - 1) WHERE id = ?");
                                    $up_cust->execute([$customer_id]);
                                }
                            } else {
                                $ins_stmt = $pdo->prepare("
                                    INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, ownership_type, original_owner_customer_id)
                                    VALUES (?, ?, ?, 'empty', 'consumer_owned', ?)
                                ");
                                $ins_stmt->execute([$ret_serial, $item['gas_type_id'], $item['size_capacity'], $customer_id]);
                                $ret_cyl_id = $pdo->lastInsertId();
                                logCylinderTransaction($pdo, $ret_cyl_id, $customer_id, null, 'consumer_return', "New consumer-owned cylinder registered on Order #ORD-$order_id");
                            }
                        }
                        
                        // Insert into refill_order_items
                        $item_deposit = floatval($item['deposit_amount'] ?? 0.00);
                        
                        // Fetch cylinder's current_refill_cost for profit tracking
                        $cost_fetch = $pdo->prepare("SELECT current_refill_cost FROM cylinders WHERE id = ?");
                        $cost_fetch->execute([$cyl_id]);
                        $cyl_refill_cost = floatval($cost_fetch->fetchColumn() ?: 0);
                        
                        // GST calculation for this refill item
                        $refill_gst_rate = floatval($item['gst_rate'] ?? 0);
                        $refill_taxable = floatval($item['price_per_unit']);
                        $refill_gst_amt = $refill_gst_rate > 0 ? ($refill_taxable * $refill_gst_rate / 100) : 0;
                        $refill_cgst = $refill_gst_amt / 2;
                        $refill_sgst = $refill_gst_amt / 2;

                        $stmt = $pdo->prepare("
                            INSERT INTO refill_order_items (refill_order_id, gas_type_id, cylinder_id, size_capacity, qty, price_per_unit, is_rental, deposit_amount, rent_per_day, free_days, returned_cylinder_id, sell_price, sold_cylinder_serial, refill_cost, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst) 
                            VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, 0.00, NULL, ?, ?, ?, ?, ?, ?, 0.00)
                        ");
                        $stmt->execute([
                            $order_id, 
                            $item['gas_type_id'], 
                            $cyl_id, 
                            $item['size_capacity'], 
                            $item['price_per_unit'],
                            $item['is_rental'],
                            $item_deposit,
                            $item['rent_per_day'],
                            $item['free_days'],
                            $ret_cyl_id,
                            $cyl_refill_cost,
                            $refill_gst_rate,
                            $refill_taxable,
                            $refill_gst_amt,
                            $refill_cgst,
                            $refill_sgst
                        ]);
                        
                        // Mark cylinder as leased to customer (setting rent rate, free days, and borrow date)
                        $stmt = $pdo->prepare("
                            UPDATE cylinders 
                            SET status = 'with_customer', 
                                current_customer_id = ?, 
                                daily_rent_rate = ?, 
                                free_days = ?, 
                                borrow_date = ?, 
                                last_refill_date = ? 
                            WHERE id = ?
                        ");
                        $stmt->execute([$customer_id, $item['rent_per_day'], $item['free_days'], $order_date, $order_date, $cyl_id]);
                        
                        // Increment customer active cylinders count
                        $stmt = $pdo->prepare("
                            UPDATE customers SET active_cylinders_count = active_cylinders_count + 1 WHERE id = ?
                        ");
                        $stmt->execute([$customer_id]);
                        
                        // Log lifecycle issue transaction
                        logCylinderTransaction($pdo, $cyl_id, $customer_id, null, 'issue_to_customer', "Issued on " . ($item['is_rental'] ? 'Rental' : 'Refill') . " Order #ORD-$order_id");
                    }
                }
            }

            // After processing all items and any exchange returns, resync this customer's active cylinder count from tracked cylinders
            syncCustomerActiveCylinderCounts($pdo, $customer_id);
            
            // 4. Post Financial Ledger payments
            if ($is_credit_order) {
                // CREDIT ORDER: apply deposit first, remainder goes on credit
                $credit_amount = $grand_total;
                $ledger_group_id = generateLedgerGroupId();
                $deposit_to_use = 0;
                
                if (floatval($customer['deposit_balance'] ?? 0) > 0) {
                    $deposit_to_use = min(floatval($customer['deposit_balance']), $grand_total);
                    if ($deposit_to_use > 0) {
                        $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance - ? WHERE id = ?")->execute([$deposit_to_use, $customer_id]);
                        $pdo->prepare("
                            INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, ledger_group_id) 
                            VALUES (?, ?, ?, ?, 'deposit_refunded', ?, ?)
                        ")->execute([$customer_id, $order_id, -$deposit_to_use, $payment_method, "Deposit applied to credit order #ORD-$order_id", $ledger_group_id]);
                        $credit_amount = $grand_total - $deposit_to_use;
                    }
                }
                
                if ($credit_amount > 0) {
                    $pdo->prepare("UPDATE customers SET credit_used = credit_used + ? WHERE id = ?")->execute([$credit_amount, $customer_id]);
                    
                    $credit_terms = intval($customer['credit_terms'] ?? 30);
                    $due_date = date('Y-m-d', strtotime("+$credit_terms days", strtotime($order_date)));
                    $current_credit_used = floatval($customer['credit_used'] ?? 0);
                    $new_credit_used = $current_credit_used + $credit_amount;
                    
                    // Build credit description with sold cylinder info
                    $credit_desc = "Credit charge for order #ORD-$order_id";
                    $sold_serials = [];
                    foreach ($processed_items as $pi) {
                        if ($pi['is_rental'] === 2) {
                            foreach ($pi['allocated'] as $pa) {
                                if (!empty($pa['sell_serial'])) {
                                    $sold_serials[] = $pa['sell_serial'];
                                }
                            }
                        }
                    }
                    if (!empty($sold_serials)) {
                        $credit_desc .= " | Sold: " . implode(', ', $sold_serials);
                    }
                    
                    $pdo->prepare("
                        INSERT INTO credit_transactions (customer_id, refill_order_id, transaction_type, amount, balance_after, description, due_date, ledger_group_id) 
                        VALUES (?, ?, 'charge', ?, ?, ?, ?, ?)
                    ")->execute([$customer_id, $order_id, $credit_amount, $new_credit_used, $credit_desc, $due_date, $ledger_group_id]);
                    
                    // Update credit status based on usage (warn only)
                    $credit_limit = floatval($customer['credit_limit'] ?? 0);
                    if ($credit_limit > 0) {
                        $usage_ratio = $new_credit_used / $credit_limit;
                        if ($usage_ratio >= 1.0) {
                            $pdo->prepare("UPDATE customers SET credit_status = 'blocked' WHERE id = ?")->execute([$customer_id]);
                        } elseif ($usage_ratio >= 0.8) {
                            $pdo->prepare("UPDATE customers SET credit_status = 'warning' WHERE id = ?")->execute([$customer_id]);
                        }
                    }
                }

                // Create ledger group for credit order
                $group_title = 'Credit Order #ORD-' . str_pad($order_id, 4, '0', STR_PAD_LEFT) . ' – ₹' . number_format($grand_total, 2);
                if ($deposit_to_use > 0) {
                    $group_title .= ' (Deposit ₹' . number_format($deposit_to_use, 2) . ' applied)';
                }
                $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'credit_order', ?, ?, ?)")
                    ->execute([$ledger_group_id, $customer_id, $group_title, $grand_total, $order_date]);
            } else {
                // REGULAR ORDER: record payment and deposits
                $refill_charge = ($subtotal + $tax_amount) - $discount;
                $pdo->prepare("
                    INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) 
                    VALUES (?, ?, ?, ?, 'refill_payment', ?)
                ")->execute([$customer_id, $order_id, $refill_charge, $payment_method, "Payment for gas refill order #ORD-$order_id" . ($discount > 0 ? " [Discount of ₹" . number_format($discount, 2) . " applied]" : "")]);
                
                // Record security deposit payment linked to this order
                if ($total_deposit_amount > 0) {
                    $pdo->prepare("
                        INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes) 
                        VALUES (?, ?, ?, ?, 'deposit_added', ?)
                    ")->execute([$customer_id, $order_id, $total_deposit_amount, $payment_method, "Security deposit for order #ORD-$order_id"]);
                    
                    $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance + ? WHERE id = ?")
                        ->execute([$total_deposit_amount, $customer_id]);
                }
            }

            if (!$is_credit_order) {
                recalculateOrderPaymentStatus($pdo, $order_id);
            }

            // 5. Generate System Invoice entry
            $invoice_num = "INV-" . date('Y') . "-" . str_pad($order_id, 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("
                UPDATE refill_orders SET invoice_number = ?, invoice_date = ? WHERE id = ?
            ");
            $stmt->execute([$invoice_num, $order_date, $order_id]);
            
            $pdo->commit();

            // Sync inventory after commit (migrations contain DDL which would implicitly commit the transaction)
            syncInventory($pdo);

            // Record Output GST in GST ledger (outside transaction)
            try {
                syncGSTFromOrder($pdo, $order_id);
            } catch (\Exception $gst_e) {
                // Log but don't block order completion
                error_log("GST sync error for order #$order_id: " . $gst_e->getMessage());
            }

            // Send order confirmation email
            require_once __DIR__ . '/../portal/email.php';
            sendOrderConfirmation($order_id, $customer_id, $pdo);
            
            // Redirect to dual receipt slip printout
            $include_cust = isset($_POST['include_customer_copy']) ? 1 : 0;
            echo "<script>window.location.href='invoice.php?order_id=$order_id&cust_copy=$include_cust';</script>";
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
            $trace = $e->getTrace();
            $error .= " | FILE: " . ($trace[0]['file'] ?? '?') . " LINE: " . ($trace[0]['line'] ?? '?');
            if (isset($trace[1]['file'])) $error .= " | CALLEDFROM: " . $trace[1]['file'] . " LINE: " . $trace[1]['line'];
        }
    }
}

// Fetch lists
$customers = [];
try {
    $customers = $pdo->query("SELECT * FROM customers WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

$gas_types = [];
try {
    $gas_types = $pdo->query("SELECT gt.* FROM gas_types gt ORDER BY gt.name ASC")->fetchAll();
} catch (PDOException $e) {}

// Build gas sizes lookup from normalized table
$gas_sizes_map = [];
try {
    $gs_stmt = $pdo->query("SELECT gas_type_id, size_capacity, price FROM gas_sizes ORDER BY sort_order ASC, id ASC");
    while ($gs = $gs_stmt->fetch()) {
        $gid = $gs['gas_type_id'];
        if (!isset($gas_sizes_map[$gid])) {
            $gas_sizes_map[$gid] = ['sizes' => [], 'prices' => []];
        }
        $gas_sizes_map[$gid]['sizes'][] = $gs['size_capacity'];
        $gas_sizes_map[$gid]['prices'][$gs['size_capacity']] = $gs['price'];
    }
} catch (PDOException $e) {}

// Fetch available filled stock count per category
$stock_counts = [];
$gas_stock_totals = [];
try {
    $stmt = $pdo->query("SELECT gas_type_id, size_capacity, COUNT(*) as count FROM cylinders WHERE status = 'filled' GROUP BY gas_type_id, size_capacity");
    while ($row = $stmt->fetch()) {
        $key = $row['gas_type_id'] . "_" . $row['size_capacity'];
        $stock_counts[$key] = intval($row['count']);
        $gid = $row['gas_type_id'];
        $gas_stock_totals[$gid] = ($gas_stock_totals[$gid] ?? 0) + intval($row['count']);
    }
} catch (PDOException $e) {}

// Fetch active cylinders leased by all customers for return exchange checklists
// Also includes consumer-owned cylinders in warehouse so owners can claim them
$held_cylinders = [];
try {
    $stmt = $pdo->query("
        SELECT c.id, c.serial_number, c.current_customer_id, c.gas_type_id, c.size_capacity, g.name as gas_name, c.ownership_type, c.original_owner_customer_id,
               oc.name as original_owner_name, p.company_name as partner_name, v.name as vendor_name
        FROM cylinders c 
        JOIN gas_types g ON c.gas_type_id = g.id
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        WHERE c.status = 'with_customer'
        AND NOT (c.ownership_type = 'consumer_owned' AND c.current_customer_id IS NOT NULL AND c.current_customer_id = c.original_owner_customer_id)
    ");
    while ($row = $stmt->fetch()) {
        $held_cylinders[$row['current_customer_id']][] = $row;
    }

    $stmt2 = $pdo->query("
        SELECT c.id, c.serial_number, c.current_customer_id, c.gas_type_id, c.size_capacity, g.name as gas_name, c.ownership_type, c.original_owner_customer_id,
               oc.name as original_owner_name, p.company_name as partner_name, v.name as vendor_name
        FROM cylinders c 
        JOIN gas_types g ON c.gas_type_id = g.id
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        WHERE c.ownership_type = 'consumer_owned'
        AND c.original_owner_customer_id IS NOT NULL
        AND c.current_customer_id IS NULL
        AND c.status IN ('filled', 'empty')
    ");
    while ($row2 = $stmt2->fetch()) {
        $held_cylinders[$row2['original_owner_customer_id']][] = $row2;
    }
} catch (PDOException $e) {}

$filled_cylinders = [];
try {
    $filled_cylinders = $pdo->query("
        SELECT c.id, c.serial_number, c.gas_type_id, c.size_capacity, c.ownership_type, c.current_customer_id, c.original_owner_customer_id,
               oc.name AS original_owner_name,
               p.company_name AS partner_name,
               v.name AS vendor_name
        FROM cylinders c
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        WHERE c.status = 'filled' 
        ORDER BY c.serial_number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch products for selling with category + gst_rate
$products = [];
try {
    $products = $pdo->query("SELECT p.*, pc.name AS category_name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.is_active = 1 OR p.is_active IS NULL ORDER BY p.name ASC")->fetchAll();
} catch (PDOException $e) {}

// Fetch vendors for customer refill service
$vendors_list = [];
try {
    $vendors_list = $pdo->query("SELECT id, name, mobile FROM vendors ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}
?>

<div style="margin-bottom: 2rem;">
    <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;">Checkout Workspace</h2>
    <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;">Generate invoices, manage cylinder exchanges, apply tax checks, and adjust pricing.</p>
</div>

<?php if ($success): ?>
    <div class="alert-banner" style="background: #d1fae5; color: #065f46; border-color: #6ee7b7; margin-bottom: 2rem; display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
        <span><strong>Success:</strong> <?php echo htmlspecialchars($success); ?></span>
        <?php if ($deposit_receipt): ?>
            <a href="deposit-receipt.php?receipt_id=<?php echo $deposit_receipt; ?>&business=<?php echo getBrandConfig()['business_key']; ?>" class="btn-primary" style="padding:0.35rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;">View Receipt</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert-banner alert-warning" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5; margin-bottom: 2rem;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" id="checkoutForm" novalidate><?php csrfField(); ?>
    <input type="hidden" name="action" value="checkout">
    <datalist id="heldCylindersDatalist"></datalist>
    
    <!-- Left-Right Split Grid Layout -->
    <div class="grid-2col-lg-gap">
        
        <!-- COLUMN 1 (LEFT SIDE): CUSTOMER INFO & BILLING ACTIONS -->
        <div>
            <div class="admin-card" style="margin: 0;">
                <h3 class="card-title" style="margin-bottom: 1.5rem;">1. Customer Processing</h3>
                
                <!-- Unified Customer Search + Select -->
                <div class="form-group" style="position: relative;">
                    <label class="form-label" style="font-weight: 700; color: var(--admin-accent);">Select Consumer</label>
                    <select name="customer_id" id="customerSelect" class="form-control" style="display:none;" onchange="loadCustomerDetails()">
                        <option value="">-- Choose registered customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" data-type="<?php echo $c['customer_type']; ?>">
                                <?php echo htmlspecialchars($c['name']) . " (" . htmlspecialchars($c['mobile']) . ")"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="customerCombobox" style="position:relative;">
                        <input type="text" id="customerSearchInput" class="form-control" autocomplete="off"
                               placeholder="Type name or phone to search & select..." 
                               style="border-color: var(--admin-accent);padding-right:2.5rem;"
                               onfocus="showCustomerDropdown()" onkeyup="filterCustomerDropdown()">
                        <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--admin-muted);pointer-events:none;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                        </span>
                        <div id="customerDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--admin-border);border-radius:10px;max-height:260px;overflow-y:auto;z-index:999;box-shadow:0 10px 25px rgba(0,0,0,0.08);margin-top:4px;">
                            <div id="customerDropdownList">
                                <!-- populated by JS -->
                            </div>
                            <div id="customerDropdownEmpty" style="display:none;padding:1.5rem;text-align:center;color:var(--admin-muted);font-size:0.85rem;">No customers found</div>
                        </div>
                    </div>
                    <input type="hidden" id="customerSelectedName" value="">
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700; color: var(--admin-accent);">Billing Entity</label>
                    <select name="business_name" class="form-control" required>
                        <?php foreach (getBusinesses() as $key => $biz): ?>
                            <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($biz['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Customer Exchange Checklist (Returned empty cylinders) -->
                <div class="form-group" id="exchangePanel" style="display: none; background: #fafafb; border: 1px solid var(--admin-border); padding: 1.25rem; border-radius: 12px; margin: 1.5rem 0;">
                    <h4 style="font-size: 0.85rem; font-weight: 800; margin-bottom: 0.75rem; color: var(--admin-fg); text-transform: uppercase; letter-spacing: 0.02em;">
                        🔄 Customer Held Cylinders (Availabe to exchange)
                    </h4>
                    <p style="font-size: 0.75rem; color: var(--admin-muted); margin-bottom: 1rem; line-height: 1.4;">
                        List of cylinders currently held by this customer. Click "Use in Exchange" to auto-populate return fields.
                    </p>
                    <div id="exchangeList" style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 180px; overflow-y: auto;">
                        <!-- JS populated checklists -->
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;">Order Date & Time</label>
                    <input type="datetime-local" name="order_date" class="form-control" style="max-width: 260px;" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;">Vehicle Number</label>
                    <input type="text" name="vehicle_number" class="form-control" placeholder="Enter vehicle number..." style="max-width: 240px;">
                </div>

                <!-- Deposit shortcut bar (shown when customer selected) -->
                <div id="depositShortcutBar" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:0.75rem 1rem;margin-bottom:1rem;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
                        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;font-size:0.85rem;">
                            <div><span style="color:var(--admin-muted);font-weight:600;">Deposit:</span> <strong id="depBarDeposit" style="color:#16a34a;">₹0</strong></div>
                            <div><span style="color:var(--admin-muted);font-weight:600;">Dues:</span> <strong id="depBarDues" style="color:#dc2626;">₹0</strong></div>
                        </div>
                        <button type="button" class="btn-primary" onclick="openModal('depositModal')" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;gap:4px;">
                            + Add Deposit
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Mode</label>
                    <select name="payment_method" class="form-control" onchange="toggleCreditWarning(this)">
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI / Online</option>
                        <option value="Check">Bank Check</option>
                        <option value="Credit">Credit (Pay Later)</option>
                    </select>
                    <div id="creditWarning" style="display:none; margin-top:8px; background:#fffbeb; border:1px solid #fde68a; padding:8px 12px; border-radius:8px; font-size:0.8rem; font-weight:600; color:#92400e;">
                        ⚠️ This order will be placed on credit. Any available deposit will be applied first.
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Internal Order Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Record delivery remarks or challans..."></textarea>
                </div>
                
                
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Discount Amount (₹)</label>
                    <input type="number" step="0.01" name="discount" id="discountInput" class="form-control" placeholder="0.00" value="0.00" onchange="calculateCartTotals()" onkeyup="calculateCartTotals()">
                </div>
                
                <div class="desktop-only">
                    <hr style="border: 0; border-top: 1px solid var(--admin-border); margin: 1.5rem 0;">
                    
                    <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 1rem;">Financial Summary:</h4>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.95rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--admin-muted);">Gas Refills subtotal</span>
                            <span style="font-weight: 600;" id="sumSubtotal">₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--admin-muted);">Cylinder Security Deposits</span>
                            <span style="font-weight: 600; color: var(--info);" id="sumDeposit">₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0; border-bottom: 1px solid #e2e8f0; margin-bottom: 0.25rem;">
                            <span style="color: var(--admin-muted); font-weight: 700; font-size: 0.88rem;">GST Summary</span>
                            <span></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.88rem;">
                            <span style="color: var(--admin-muted);">Taxable Value</span>
                            <span style="font-weight: 600;" id="sumTaxable">₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem;">
                            <span style="color: var(--admin-muted);">CGST @ <span id="gstLabelHalf">2.5</span>%</span>
                            <span style="font-weight: 600;" id="sumCGST">₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem;">
                            <span style="color: var(--admin-muted);">SGST @ <span id="gstLabelHalf2">2.5</span>%</span>
                            <span style="font-weight: 600;" id="sumSGST">₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--admin-muted);">Total GST</span>
                            <span style="font-weight: 700;" id="sumTax">₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--danger); font-weight: 700;">Discount Applied</span>
                            <span style="font-weight: 800; color: var(--danger);" id="sumDiscount">-₹0.00</span>
                        </div>
                        <hr style="border: 0; border-top: 1px dashed var(--admin-border); margin: 0.5rem 0;">
                        <div style="display: flex; justify-content: space-between; font-size: 1.15rem; font-weight: 800;">
                            <span style="padding-bottom: 16px;">GRAND TOTAL</span>
                            <span style="color: var(--admin-accent);" id="sumGrandTotal">₹0.00</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="font-size: 1.05rem; font-weight: 800;">TOTAL TO COLLECT</span>
                            <span style="font-size: 1.15rem; font-weight: 900; color: var(--danger);" id="sumTotalToCollect">₹0.00</span>
                        </div>
                    </div>
                    
                    <div id="checkoutBtnWarningDesktop" style="display: none; background: #fef2f2; color: #991b1b; padding: 10px 15px; border-radius: 8px; border: 1px solid #fecaca; margin-top: 1.5rem; font-size: 0.8rem; font-weight: 700; line-height: 1.4;">
                        ⚠️ Order cannot be processed! Some cylinder lease requests exceed warehouse filled stocks. Please adjust counts.
                    </div>
                    
                    <div class="checkout-btn-wrapper" style="margin-top: 1.5rem;">
                        <button type="submit" id="checkoutBtn" class="btn-primary" style="width: 100%; justify-content: center; padding: 0.9rem 0; border-radius: 12px; font-size: 1rem;">
                            Confirm & Print Slip
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- COLUMN 2 (RIGHT SIDE): ORDER ITEM DETAILS -->
        <div class="admin-card" style="margin: 0;">
            <h3 class="card-title" style="margin-bottom: 1.5rem;">2. Order Item Details</h3>
            
            <div id="itemsContainer" style="display: flex; flex-direction: column; gap: 1.5rem; margin-bottom: 1.5rem;">
                
                <!-- Core item row template -->
                <div class="item-row" style="background: #fafafb; padding: 1.25rem; border-radius: 12px; border: 1px solid var(--admin-border);">
                    <!-- TOP ROW: Operation Mode + Price + Stock -->
                    <div class="grid-order-row-actions">
                        <div>
                            <label class="form-label" style="font-size: 0.8rem;">Operation Mode</label>
                            <select name="items[0][is_rental]" class="form-control rental-select" onchange="toggleRentalFields(this.closest('.item-row')); calculateCartTotals()">
                                <option value="0">Exchange Cylinder</option>
                                <option value="1">Cylinder Rental</option>
                                <option value="2">Sell Cylinder</option>
                                <option value="3">Sell Product</option>
                                <option value="4">Customer Cylinder Refill Service</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="form-label" style="font-size: 0.8rem;">Override Unit Price (₹)</label>
                            <input type="number" step="0.01" name="items[0][custom_price]" class="form-control rate-override" placeholder="Custom Price" onchange="calculateCartTotals()" onkeyup="calculateCartTotals()">
                        </div>
                        
                        <!-- Inline warehouse stock warning indicator -->
                        <div style="padding-bottom: 8px;">
                            <span class="stock-indicator" style="font-size: 0.75rem; font-weight: 700; color: var(--admin-muted);">
                                (Stock: 0 filled)
                            </span>
                        </div>
                        
                        <div style="text-align: right;">
                            <button type="button" class="btn-danger remove-btn" style="padding: 0.5rem; border-radius: 8px; font-weight: 800; display: none;" onclick="removeRow(this)">&times;</button>
                        </div>
                    </div>
                    
                    <!-- BOTTOM ROW: Gas Variant + Size + Qty -->
                    <div class="grid-order-row">
                        <div>
                            <label class="form-label" style="font-size: 0.8rem;">Select Gas Variant</label>
                            <select name="items[0][gas_type_id]" class="form-control gas-select" required onchange="handleGasChange(this)">
                                <?php $first_gas = true; foreach ($gas_types as $gt): ?>
                                    <?php
                                    $sizes_csv = '10L,40L,47L';
                                    $prices_json = '{}';
                                    if (isset($gas_sizes_map[$gt['id']])) {
                                        $sizes_csv = implode(',', $gas_sizes_map[$gt['id']]['sizes']);
                                        $prices_json = json_encode($gas_sizes_map[$gt['id']]['prices']);
                                    }
                                    ?>
                                    <option value="<?php echo $gt['id']; ?>" data-price="<?php echo $gt['default_price_per_kg']; ?>" data-sizes="<?php echo htmlspecialchars($sizes_csv); ?>" data-variant-prices="<?php echo htmlspecialchars($prices_json); ?>" data-stock="<?php echo intval($gas_stock_totals[$gt['id']] ?? 0); ?>"<?php if ($first_gas) { echo ' selected'; $first_gas = false; } ?>>
                                        <?php echo htmlspecialchars($gt['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="form-label" style="font-size: 0.8rem;">Capacity Size</label>
                            <select name="items[0][size_capacity]" class="form-control size-select" required onchange="updateRowPriceFromVariant(this.closest('.item-row')); calculateCartTotals(); filterHeldCylinders()">
                            </select>
                        </div>
                        
                        <div>
                            <label class="form-label" style="font-size: 0.8rem;">Quantity (Cylinders)</label>
                            <input type="number" name="items[0][qty]" class="form-control qty-input" value="1" min="1" required onchange="calculateCartTotals()" onkeyup="calculateCartTotals()">
                        </div>
                    </div>
                    
                    <!-- No-stock notice (shown when warehouse has 0 cylinders for selected gas in stock-dependent modes) -->
                    <div class="no-stock-notice" style="display: none; background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: 0.75rem 1rem; border-radius: 8px; margin-top: 0.75rem; font-size: 0.8rem; font-weight: 600;">
                        ⚠️ No cylinders available in warehouse for this gas. Select a different gas or switch to <strong>Sell Product</strong> or <strong>Customer Cylinder Refill Service</strong>.
                    </div>
                    
                    <!-- Rental Options details -->
                    <div class="rental-options-container" style="display: none; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-top: 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.75rem; border-radius: 8px;">
                        <div>
                            <label class="form-label" style="font-size: 0.75rem; color: #166534; margin-bottom: 0.25rem;">Rent Per Day (₹)</label>
                            <input type="number" step="0.01" name="items[0][rent_per_day]" class="form-control rent-rate-input" value="10.00" style="background: white; border-color: #86efac; font-size: 0.85rem; padding: 0.4rem 0.75rem; height: 36px;">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.75rem; color: #166534; margin-bottom: 0.25rem;">Free Days</label>
                            <input type="number" name="items[0][free_days]" class="form-control free-days-input" value="3" style="background: white; border-color: #86efac; font-size: 0.85rem; padding: 0.4rem 0.75rem; height: 36px;">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.75rem; color: #166534; margin-bottom: 0.25rem;">Deposit per Cyl (₹)</label>
                            <input type="number" step="0.01" name="items[0][deposit_amount]" class="form-control deposit-amount-input" value="0.00" style="background: white; border-color: #86efac; font-size: 0.85rem; padding: 0.4rem 0.75rem; height: 36px;" onchange="calculateCartTotals()" onkeyup="calculateCartTotals()">
                        </div>
                    </div>

                    <!-- Sell Cylinder details -->
                    <div class="sell-cylinder-container" style="display: none; margin-top: 1rem; background: #fef2f2; border: 1px solid #fecaca; padding: 0.75rem; border-radius: 8px;">
                        <div>
                            <label class="form-label" style="font-size: 0.75rem; color: #991b1b; margin-bottom: 0.25rem;">Sell Price (₹) — Total amount for all cylinders being sold</label>
                            <input type="number" step="0.01" name="items[0][sell_price]" class="form-control sell-price-input" value="0.00" style="background: white; border-color: #fca5a5; font-size: 0.85rem; padding: 0.4rem 0.75rem; height: 36px; max-width: 240px;" onchange="calculateCartTotals()" onkeyup="calculateCartTotals()">
                        </div>
                    </div>

                    <!-- Sell Product details -->
                    <div class="product-container" style="display: none; margin-top: 1rem; background: #f0f9ff; border: 1px solid #bae6fd; padding: 0.75rem; border-radius: 8px;">
                        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.75rem;align-items:end;">
                            <div>
                                <label class="form-label" style="font-size: 0.75rem; color: #0369a1; margin-bottom: 0.25rem;">Select Product</label>
                                <select name="items[0][product_id]" class="form-control product-select" style="background:white;border-color:#7dd3fc;font-size:0.85rem;padding:0.4rem 0.75rem;height:36px;" onchange="handleProductChange(this)">
                                    <option value="">-- Choose product --</option>
                                    <?php foreach ($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['price']; ?>" data-stock="<?php echo $p['stock_quantity']; ?>" data-unit="<?php echo htmlspecialchars($p['unit'] ?: 'piece'); ?>" data-gst-rate="<?php echo $p['gst_rate'] ?? ''; ?>" data-sku="<?php echo htmlspecialchars($p['sku'] ?? ''); ?>" data-category="<?php echo htmlspecialchars($p['category_name'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($p['name']); ?> (₹<?php echo number_format($p['price'], 2); ?>)<?php echo $p['sku'] ? ' [' . htmlspecialchars($p['sku']) . ']' : ''; ?><?php echo $p['category_name'] ? ' - ' . htmlspecialchars($p['category_name']) : ''; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.75rem; color: #0369a1; margin-bottom: 0.25rem;">Quantity</label>
                                <input type="number" name="items[0][product_qty]" class="form-control product-qty-input" value="1" min="1" style="background:white;border-color:#7dd3fc;font-size:0.85rem;padding:0.4rem 0.75rem;height:36px;" onchange="calculateCartTotals()" onkeyup="calculateCartTotals()">
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.75rem; color: #0369a1; margin-bottom: 0.25rem;">Selling Price (₹)</label>
                                <input type="number" step="0.01" name="items[0][product_sell_price]" class="form-control product-sell-price-input" value="" placeholder="Auto" style="background:white;border-color:#7dd3fc;font-size:0.85rem;padding:0.4rem 0.75rem;height:36px;" onchange="calculateCartTotals()" onkeyup="calculateCartTotals()">
                            </div>
                        </div>
                    </div>

                    <!-- Cylinder Serial Exchange details -->
                    <div class="cylinder-serials-details" style="margin-top: 1rem; border-top: 1px dashed var(--admin-border); padding-top: 1rem;">
                        <!-- Generated dynamically by JS -->
                    </div>
                    
                    <!-- Highly visible stock deficit alert text -->
                    <div class="deficit-alert" style="display: none; background: #fee2e2; color: #991b1b; padding: 6px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; margin-top: 10px; border-left: 4px solid var(--danger);">
                        Deficit error warning block placeholder
                    </div>

                </div>
                
            </div>
            
            <button type="button" class="btn-secondary" style="border-radius: 8px;" onclick="addRow()">
                ➕ Add Another Gas Type
            </button>
        </div>
    </div>
    
    <!-- Always-visible form controls (outside responsive wrappers) -->
    <div style="margin: 1.5rem 0; display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
        <label style="display: flex; align-items: center; gap: 0.6rem; font-weight: 700; color: var(--admin-fg);">
            GST Rate (%):
            <select name="gst_rate" id="gstRate" class="form-control" style="width:auto;display:inline-block;padding:0.3rem 0.5rem;font-size:0.85rem;height:auto;" onchange="toggleGstCustom(); calculateCartTotals()">
                <option value="0">0% (No GST)</option>
                <option value="5" selected>5%</option>
                <option value="10">10%</option>
                <option value="18">18%</option>
                <option value="custom">Custom</option>
            </select>
            <input type="number" name="gst_custom_rate" id="gstCustomRate" step="0.01" value="5" style="display:none;width:80px;padding:0.3rem 0.5rem;font-size:0.85rem;border:1px solid var(--admin-border);border-radius:6px;" onchange="calculateCartTotals()" onkeyup="calculateCartTotals()">
        </label>
        <label style="display: flex; align-items: center; gap: 0.6rem; font-weight: 700; color: var(--admin-fg); cursor: pointer; user-select: none;">
            <input type="checkbox" name="include_customer_copy" id="includeCustomerCopy" value="1" checked>
            Print Customer Copy alongside Store Copy (Dual Receipt)
        </label>
    </div>
    
    <!-- MOBILE CHECKOUT FOOTER (hidden on desktop, sticky at bottom on mobile) -->
    <div class="mobile-checkout-footer">
        <div class="admin-card" style="margin: 0;">
            <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 1rem;">Financial Summary:</h4>
            <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.95rem;">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--admin-muted);">Gas Refills subtotal</span>
                    <span style="font-weight: 600;" id="sumSubtotalMobile">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--admin-muted);">Cylinder Security Deposits</span>
                    <span style="font-weight: 600; color: var(--info);" id="sumDepositMobile">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0; border-bottom: 1px solid #e2e8f0; margin-bottom: 0.25rem;">
                    <span style="color: var(--admin-muted); font-weight: 700; font-size: 0.88rem;">GST Summary</span>
                    <span></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.88rem;">
                    <span style="color: var(--admin-muted);">Taxable Value</span>
                    <span style="font-weight: 600;" id="sumTaxableMobile">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.85rem;">
                    <span style="color: var(--admin-muted);">CGST @ <span id="gstLabelHalfMobile">2.5</span>%</span>
                    <span style="font-weight: 600;" id="sumCGSTMobile">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 0.85rem;">
                    <span style="color: var(--admin-muted);">SGST @ <span id="gstLabelHalfMobile2">2.5</span>%</span>
                    <span style="font-weight: 600;" id="sumSGSTMobile">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--admin-muted);">Total GST</span>
                    <span style="font-weight: 700;" id="sumTaxMobile">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--danger); font-weight: 700;">Discount Applied</span>
                    <span style="font-weight: 800; color: var(--danger);" id="sumDiscountMobile">-₹0.00</span>
                </div>
                
                <hr style="border: 0; border-top: 1px dashed var(--admin-border); margin: 0.5rem 0;">
                <div style="display: flex; justify-content: space-between; font-size: 1.15rem; font-weight: 800;">
                    <span style="padding-bottom: 16px;">GRAND TOTAL</span>
                    <span style="color: var(--admin-accent);" id="sumGrandTotalMobile">₹0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="font-size: 1.05rem; font-weight: 800;">TOTAL TO COLLECT</span>
                    <span style="font-size: 1.15rem; font-weight: 900; color: var(--danger);" id="sumTotalToCollectMobile">₹0.00</span>
                </div>
            </div>
            
            <!-- Stock warnings block below summary -->
            <div id="checkoutBtnWarning" style="display: none; background: #fef2f2; color: #991b1b; padding: 10px 15px; border-radius: 8px; border: 1px solid #fecaca; margin-top: 1.5rem; font-size: 0.8rem; font-weight: 700; line-height: 1.4;">
                ⚠️ Order cannot be processed! Some cylinder lease requests exceed warehouse filled stocks. Please adjust counts.
            </div>
            
            <div class="checkout-btn-wrapper" id="checkoutBtnWrapperMobile">
                <button type="submit" id="checkoutBtnMobile" class="btn-primary" style="width: 100%; justify-content: center; padding: 0.9rem 0; border-radius: 12px; font-size: 1rem;">
                    Confirm & Print Slip
                </button>
            </div>
        </div>
    </div>
    
<!-- Policy Violation Modal -->
<div id="policyViolationModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: white; padding: 2.25rem; border-radius: 16px; max-width: 480px; width: 90%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); text-align: center; border: 1px solid #fecaca; animation: modalAppear 0.25s ease-out;">
        <div style="background: #fee2e2; color: #dc2626; width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 1.75rem;">
            ⚠️
        </div>
        <h3 id="policyViolationTitle" style="font-size: 1.25rem; font-weight: 800; color: #1e293b; margin-bottom: 0.75rem;">Exchange Policy Warning</h3>
        <p id="policyViolationText" style="font-size: 0.88rem; color: #64748b; line-height: 1.6; margin-bottom: 1.75rem; text-align: left; background: #fafafb; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #dc2626; max-height: 200px; overflow-y: auto;"></p>
        <button type="button" class="btn-primary" style="width: 100%; justify-content: center; padding: 0.8rem 0; border-radius: 10px; font-weight: 700; background: #dc2626; border: none; color: white; cursor: pointer; font-size: 0.95rem; box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.2);" onclick="closePolicyViolationModal()">
            Got it, Adjust My Order
        </button>
    </div>
</div>

<script>
    function showPolicyViolationModal(message) {
        document.getElementById('policyViolationTitle').textContent = 'Exchange Policy Warning';
        document.getElementById('policyViolationText').innerHTML = message;
        document.getElementById('policyViolationModal').style.display = 'flex';
    }
    
    function showValidationModal(message) {
        document.getElementById('policyViolationTitle').textContent = 'Validation Error';
        document.getElementById('policyViolationText').innerHTML = message;
        document.getElementById('policyViolationModal').style.display = 'flex';
    }
    
    function closePolicyViolationModal() {
        document.getElementById('policyViolationModal').style.display = 'none';
    }
</script>

<style>
    @keyframes modalAppear {
        from {
            opacity: 0;
            transform: scale(0.95) translateY(10px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }

</style>
</form>

<script>
    // Injected lookup configurations from active DB counts
    const stockCounts = <?php echo json_encode($stock_counts); ?>;
    const allCustomers = <?php echo json_encode($customers); ?>;
    const heldCylindersLookup = <?php echo json_encode($held_cylinders); ?>;
    const filledCylinders = <?php echo json_encode($filled_cylinders); ?>;
    const allProducts = <?php echo json_encode($products); ?>;
    const allVendors = <?php echo json_encode($vendors_list); ?>;
    
    // Build serial -> gas_type_id lookup for client-side validation
    const cylinderGasMap = {};
    heldCylindersLookup && Object.values(heldCylindersLookup).forEach(arr => {
        arr.forEach(c => { cylinderGasMap[c.serial_number] = c.gas_type_id; });
    });
    filledCylinders.forEach(c => { cylinderGasMap[c.serial_number] = c.gas_type_id; });
    
    function openModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('active');
    }
    function closeModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('active');
    }

    let rowCounter = 1;
    let isAutoProvisioning = false;
    
    // Show/hide credit warning when payment method changes
    function toggleCreditWarning(select) {
        const warning = document.getElementById('creditWarning');
        if (warning) {
            warning.style.display = select.value === 'Credit' ? 'block' : 'none';
        }
    }

    // Filter sell cylinder dropdown options based on selected gas type and size (for static dropdown)
    // Note: Dynamic sell selects generated by updateCylinderSerials are already filtered by gas/size
    function filterSellCylinders(row) {
        const gasSelect = row.querySelector('.gas-select');
        const sizeSelect = row.querySelector('.size-select');
        const sellSelect = row.querySelector('.sell-cylinder-select');
        if (!sellSelect) return;
        const gasId = gasSelect.value;
        const size = sizeSelect.value;
        sellSelect.querySelectorAll('option').forEach(opt => {
            if (!opt.value) return; // keep placeholder
            const optGas = opt.getAttribute('data-gas');
            const optSize = opt.getAttribute('data-size');
            if (optGas == gasId && optSize == size) {
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
            }
        });
        if (sellSelect.selectedOptions.length > 0 && sellSelect.selectedOptions[0].style.display === 'none') {
            sellSelect.value = '';
        }
    }

    // Show/hide custom GST input and update label
    function toggleGstCustom() {
        const sel = document.getElementById('gstRate');
        const customInput = document.getElementById('gstCustomRate');
        if (customInput) {
            customInput.style.display = sel.value === 'custom' ? 'inline-block' : 'none';
        }
        updateGstLabel();
    }

    function updateGstLabel() {
        const sel = document.getElementById('gstRate');
        let rate = sel.value;
        if (rate === 'custom') {
            rate = document.getElementById('gstCustomRate').value || '0';
        }
        const halfRate = (parseFloat(rate) / 2).toFixed(1);
        var gstLabel = document.getElementById('gstLabel');
        if (gstLabel) gstLabel.textContent = rate;
        document.getElementById('gstLabelHalf').textContent = halfRate;
        document.getElementById('gstLabelHalf2').textContent = halfRate;
        var mobileLabel = document.getElementById('gstLabelMobile');
        if (mobileLabel) mobileLabel.textContent = rate;
        var mobileHalf = document.getElementById('gstLabelHalfMobile');
        if (mobileHalf) mobileHalf.textContent = halfRate;
        var mobileHalf2 = document.getElementById('gstLabelHalfMobile2');
        if (mobileHalf2) mobileHalf2.textContent = halfRate;
    }

    // Auto-fill product price and update total when product selection changes
    function handleProductChange(select) {
        const row = select.closest('.item-row');
        const opt = select.options[select.selectedIndex];
        if (opt && opt.value) {
            const price = parseFloat(opt.getAttribute('data-price')) || 0;
            const rateOverride = row.querySelector('.rate-override');
            if (rateOverride) rateOverride.value = price.toFixed(2);
            const sellPriceInput = row.querySelector('.product-sell-price-input');
            if (sellPriceInput && sellPriceInput.value === '') {
                sellPriceInput.value = price.toFixed(2);
            }
            // Auto-populate GST rate from product
            const gstRate = opt.getAttribute('data-gst-rate');
            if (gstRate) {
                const gstSelect = document.getElementById('gstRateSelect');
                if (gstSelect) {
                    const foundOpt = Array.from(gstSelect.options).find(o => parseFloat(o.value) === parseFloat(gstRate));
                    if (foundOpt) {
                        gstSelect.value = gstRate;
                    } else {
                        gstSelect.value = 'custom';
                        const customInput = document.getElementById('gstCustomRate');
                        if (customInput) customInput.value = gstRate;
                    }
                }
            }
        }
        calculateCartTotals();
    }

    // Toggle display of rental / sell / exchange fields based on operation mode
    function toggleRentalFields(row) {
        const rentalSelect = row.querySelector('.rental-select');
        const rentalContainer = row.querySelector('.rental-options-container');
        const sellContainer = row.querySelector('.sell-cylinder-container');
        const productContainer = row.querySelector('.product-container');
        const serialDetails = row.querySelector('.cylinder-serials-details');
        const gasVariantRow = row.querySelector('.grid-order-row');
        const val = rentalSelect.value;
        
        if (val === "1") {
            // Rental: show rental fields + cylinder selection, hide sell, hide returned inputs
            rentalContainer.style.display = 'grid';
            if (sellContainer) sellContainer.style.display = 'none';
            if (productContainer) productContainer.style.display = 'none';
            if (serialDetails) {
                serialDetails.style.display = 'block';
                serialDetails.querySelectorAll('.returned-cyl-group').forEach(el => el.style.display = 'none');
            }
            if (gasVariantRow) gasVariantRow.style.display = '';
        } else if (val === "2") {
            // Sell Cylinder: hide rental, show sell fields + cylinder selects
            rentalContainer.style.display = 'none';
            if (sellContainer) sellContainer.style.display = 'block';
            if (productContainer) productContainer.style.display = 'none';
            if (serialDetails) serialDetails.style.display = 'block';
            if (gasVariantRow) gasVariantRow.style.display = '';
        } else if (val === "3") {
            // Sell Product: hide rental, hide sell, hide cylinder serials, hide gas variant row, show product
            rentalContainer.style.display = 'none';
            if (sellContainer) sellContainer.style.display = 'none';
            if (productContainer) productContainer.style.display = 'block';
            if (serialDetails) serialDetails.style.display = 'none';
            if (gasVariantRow) gasVariantRow.style.display = 'none';
        } else if (val === "4") {
            // Customer Cylinder Refill Service: no rental, no sell, no product, show refill service fields
            rentalContainer.style.display = 'none';
            if (sellContainer) sellContainer.style.display = 'none';
            if (productContainer) productContainer.style.display = 'none';
            if (serialDetails) serialDetails.style.display = 'block';
            if (gasVariantRow) gasVariantRow.style.display = '';
            // Hide stock indicator (doesn't consume our stock)
            const stockDiv = row.querySelector('.stock-indicator')?.closest('div');
            if (stockDiv) stockDiv.style.display = 'none';
            const alertBox = row.querySelector('.deficit-alert');
            if (alertBox) alertBox.style.display = 'none';
            // Repopulate size dropdown — mode 4 shows all sizes regardless of stock
            if (!row.dataset.refreshingSizes) {
                handleGasChange(row.querySelector('.gas-select'));
            }
        } else {
            // Refill-Only (0): hide rental, hide sell, hide product, show full exchange
            rentalContainer.style.display = 'none';
            if (sellContainer) sellContainer.style.display = 'none';
            if (productContainer) productContainer.style.display = 'none';
            if (serialDetails) {
                serialDetails.style.display = 'block';
                serialDetails.querySelectorAll('.returned-cyl-group').forEach(el => el.style.display = '');
            }
            if (gasVariantRow) gasVariantRow.style.display = '';
        }
        updateStockDependentVisibility(row);
        filterGasOptionsByStock(row);
    }

    // Filter gas type options based on stock availability (skip for refill service mode=4)
    function filterGasOptionsByStock(row) {
        const rentalSelect = row.querySelector('.rental-select');
        const gasSelect = row.querySelector('.gas-select');
        if (!gasSelect) return;
        const modeVal = rentalSelect ? rentalSelect.value : '0';
        const isStockIndependent = modeVal === '4';
        const opts = gasSelect.options;
        for (let i = 0; i < opts.length; i++) {
            const opt = opts[i];
            const stock = parseInt(opt.getAttribute('data-stock')) || 0;
            if (isStockIndependent) {
                opt.disabled = false;
                opt.text = opt.text.replace(/\s*— Not Available$/, '');
            } else {
                if (stock === 0) {
                    opt.disabled = true;
                    if (!opt.text.endsWith('— Not Available')) {
                        opt.text = opt.text + ' — Not Available';
                    }
                } else {
                    opt.disabled = false;
                    opt.text = opt.text.replace(/\s*— Not Available$/, '');
                }
            }
        }
        // If selected option is now disabled, select first available
        if (gasSelect.options[gasSelect.selectedIndex] && gasSelect.options[gasSelect.selectedIndex].disabled) {
            for (let i = 0; i < gasSelect.options.length; i++) {
                if (!gasSelect.options[i].disabled) {
                    gasSelect.selectedIndex = i;
                    handleGasChange(gasSelect);
                    break;
                }
            }
        }

    }

    // Hide mode-dependent fields when selected gas has 0 stock (modes 0/1/2 only)
    function updateStockDependentVisibility(row) {
        const rentalSelect = row.querySelector('.rental-select');
        const gasSelect = row.querySelector('.gas-select');
        const modeVal = rentalSelect ? rentalSelect.value : '0';
        const isStockDependent = modeVal === '0' || modeVal === '1' || modeVal === '2';
        const gasOpt = gasSelect.options[gasSelect.selectedIndex];
        const stock = gasOpt ? parseInt(gasOpt.getAttribute('data-stock')) || 0 : 0;
        const notice = row.querySelector('.no-stock-notice');
        const gasVariantRow = row.querySelector('.grid-order-row');
        const serialDetails = row.querySelector('.cylinder-serials-details');
        const rentalContainer = row.querySelector('.rental-options-container');
        const sellContainer = row.querySelector('.sell-cylinder-container');

        if (isStockDependent && stock === 0) {
            // Hide all mode-dependent fields, show the notice
            if (gasVariantRow) gasVariantRow.style.display = 'none';
            if (serialDetails) serialDetails.style.display = 'none';
            if (rentalContainer) rentalContainer.style.display = 'none';
            if (sellContainer) sellContainer.style.display = 'none';
            if (notice) notice.style.display = 'block';
        } else {
            // Hide notice, let toggleRentalFields control visibility normally
            if (notice) notice.style.display = 'none';
        }
    }

    // Validate returned cylinder gas type matches the row's gas type
    function validateReturnGasType(input) {
        const row = input.closest('.item-row');
        const gasSelect = row.querySelector('.gas-select');
        const gasOpt = gasSelect.options[gasSelect.selectedIndex];
        if (!gasOpt) return;
        
        const rowGasId = parseInt(gasSelect.value);
        const serial = input.value.trim();
        const msgEl = input.parentElement.querySelector('.gas-mismatch-msg');
        
        if (!serial) {
            if (msgEl) msgEl.remove();
            input.style.borderColor = '';
            return;
        }
        
        const cylGasId = cylinderGasMap[serial];
        if (cylGasId && cylGasId !== rowGasId) {
            const returnGasName = gasSelect.querySelector(`option[value="${cylGasId}"]`)?.textContent || 'Unknown Gas';
            const expectedGasName = gasOpt.textContent;
            input.style.borderColor = 'var(--danger)';
            if (!msgEl) {
                const msg = document.createElement('div');
                msg.className = 'gas-mismatch-msg';
                msg.style.cssText = 'font-size:0.72rem;color:var(--danger);font-weight:700;margin-top:4px;';
                input.parentElement.appendChild(msg);
            }
            input.parentElement.querySelector('.gas-mismatch-msg').textContent =
                '⚠ Gas mismatch: Returned cylinder is ' + returnGasName + ' but order is for ' + expectedGasName + '.';
        } else {
            input.style.borderColor = '';
            if (msgEl) msgEl.remove();
        }
    }

    // Use cylinder in the next empty return input helper
    function useCylinderForExchange(serial) {
        const inputs = document.querySelectorAll('.returned-cyl-input');
        for (let inp of inputs) {
            if (!inp.value) {
                inp.value = serial;
                return;
            }
        }
        if (inputs.length > 0) {
            inputs[0].value = serial;
        }
    }

    // Return customer's own CON cylinder without issuing a new one
    function returnCylinderToCustomer(serial, gasTypeId, sizeCapacity) {
        // Add to hidden return-only tracking
        const container = document.getElementById('returnOnlyContainer') || (function() {
            const c = document.createElement('div');
            c.id = 'returnOnlyContainer';
            c.style.display = 'none';
            document.getElementById('checkoutForm').appendChild(c);
            return c;
        })();
        
        // Check if already added
        const existing = container.querySelectorAll('input');
        for (let inp of existing) {
            if (inp.value === serial) return;
        }
        
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'return_only_serials[]';
        hidden.value = `${serial}|${gasTypeId}|${sizeCapacity}`;
        container.appendChild(hidden);
        
        // Visual feedback
        const toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#1e40af;color:white;padding:12px 20px;border-radius:10px;font-weight:700;font-size:0.85rem;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,0.2);animation:fadeInUp 0.3s;';
        toast.innerHTML = '↩ ' + serial + ' marked for return to customer';
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.5s'; setTimeout(() => toast.remove(), 500); }, 2500);
    }

    // Dynamic generation of Cylinder Serial dropdowns/text inputs
    function updateCylinderSerials(row) {
        const rowIdAttr = row.querySelector('.qty-input').getAttribute('name');
        const match = rowIdAttr.match(/items\[(\d+)\]/);
        if (!match) return;
        const rowIndex = match[1];
        
        const gasSelect = row.querySelector('.gas-select');
        const sizeSelect = row.querySelector('.size-select');
        const qtyInput = row.querySelector('.qty-input');
        
        const gasId = gasSelect.value;
        const size = sizeSelect.value;
        const qty = parseInt(qtyInput.value) || 1;
        
        let detailsContainer = row.querySelector('.cylinder-serials-details');
        if (!detailsContainer) {
            detailsContainer = document.createElement('div');
            detailsContainer.className = 'cylinder-serials-details';
            detailsContainer.style.cssText = "margin-top: 1rem; border-top: 1px dashed var(--admin-border); padding-top: 1rem;";
            row.appendChild(detailsContainer);
        }
        
        // Filter matching filled cylinders in stock
        const matching = filledCylinders.filter(c => c.gas_type_id == gasId && c.size_capacity == size);
        
        // Customer-priority sort: cylinders belonging to selected customer come first
        const belongsToCustomer = (c) => c.current_customer_id == selectedCustomerId || c.original_owner_customer_id == selectedCustomerId;
        const ownerLabel = (c) => {
            if (c.ownership_type === 'consumer_owned' && c.original_owner_name) return '-' + c.original_owner_name;
            if (c.ownership_type === 'partner_owned' && c.partner_name) return '-' + c.partner_name;
            if (c.ownership_type === 'vendor_owned' && c.vendor_name) return '-' + c.vendor_name;
            return '';
        };
        if (selectedCustomerId) {
            matching.sort((a, b) => {
                const aOwn = belongsToCustomer(a) ? -1 : 0;
                const bOwn = belongsToCustomer(b) ? -1 : 0;
                return aOwn - bOwn;
            });
        }
        
        // Save current selections to preserve them
        const savedIssued = [];
        const savedReturned = [];
        const savedSell = [];
        detailsContainer.querySelectorAll('.issued-cyl-select').forEach(sel => savedIssued.push(sel.value));
        detailsContainer.querySelectorAll('.returned-cyl-input').forEach(inp => savedReturned.push(inp.value));
        detailsContainer.querySelectorAll('.sell-cyl-select').forEach(sel => savedSell.push(sel.value));
        
        const rentalSelect = row.querySelector('.rental-select');
        const mode = parseInt(rentalSelect.value);
        
        if (mode === 3) {
            // Product mode — no cylinder serials needed
            detailsContainer.innerHTML = '';
            return;
        }

        if (mode === 4) {
            // Customer Cylinder Refill Service — serial inputs only, always received at warehouse first
            const savedCustomerSerials = [];
            row.querySelectorAll('.customer-cyl-input').forEach(inp => savedCustomerSerials.push(inp.value));
            let serialsHtml = '';
            let filledCount = 0;
            for (let i = 0; i < qty; i++) {
                const val = savedCustomerSerials[i] || '';
                if (val) filledCount++;
                serialsHtml += `
                    <div style="display:flex;gap:6px;align-items:center;">
                        <span style="font-size:0.75rem;font-weight:600;color:var(--admin-muted);min-width:28px;">#${i+1}</span>
                        <input type="text" name="items[${rowIndex}][customer_cylinders][]" class="form-control customer-cyl-input" style="font-size:0.85rem;padding:0.4rem 0.75rem;height:36px;flex:1;" placeholder="Serial #${i+1}" value="${val}" autocomplete="off">
                    </div>
                `;
            }
            const remaining = qty - filledCount;
            detailsContainer.innerHTML = `
                <h5 style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--admin-fg);">🔄 Customer Cylinder Refill Service</h5>
                <div style="margin-top: 0.75rem;">
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Customer Cylinder Serial(s)</label>
                    <div class="refill-serials-grid" style="display:flex;flex-direction:column;gap:6px;">
                        ${serialsHtml}
                    </div>
                    <div id="refillSerialCount_${rowIndex}" style="font-size:0.8rem;font-weight:700;margin-top:6px;${remaining > 0 ? 'color:#d97706;' : 'color:#16a34a;'}">${remaining > 0 ? 'Remaining required: ' + remaining : '✓ All fields filled'}</div>
                    <div style="font-size: 0.7rem; color: var(--admin-muted); margin-top: 3px;">Paste comma-separated serials into any field to auto-distribute</div>
                </div>
                <div style="margin-top: 0.5rem; font-size: 0.75rem; padding: 0.4rem 0.6rem; border-radius: 6px; background:#fffbeb;color:#92400e;border:1px solid #fde68a;">
                    Cylinders will be received at warehouse for processing. Dispatch to vendor can be done later.
                </div>
            `;
            return;
        }
        
        let html = '';
        if (mode === 2) {
            html = '<h5 style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--admin-fg);">💰 Select Cylinders to Sell</h5>';
            for (let i = 0; i < qty; i++) {
                const valSell = savedSell[i] || '';
                let sellOpts = '<option value="">-- Select cylinder --</option>';
                function cylTag(t) {
                    return t === 'partner_owned' ? 'BR' : t === 'consumer_owned' ? 'CON' : t === 'vendor_owned' ? 'VEN' : 'OWN';
                }
                matching.filter(c => c.ownership_type === 'owned').forEach(c => {
                    const star = belongsToCustomer(c) ? ' &#9733;' : '';
                    sellOpts += `<option value="${c.id}" ${valSell == c.id ? 'selected' : ''}>${c.serial_number}${star}${ownerLabel(c)} (${cylTag(c.ownership_type)})</option>`;
                });
                html += `
                    <div class="serial-exchange-subrow" style="display: grid; grid-template-columns: 1fr; gap: 0.75rem; margin-top: 0.5rem; align-items: end;">
                        <div>
                            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Select Cylinder #${i+1} to Sell</label>
                            <select name="items[${rowIndex}][sell_cylinder_ids][]" class="form-control sell-cyl-select" style="font-size: 0.85rem; padding: 0.4rem 0.75rem; height: 36px;">
                                ${sellOpts}
                            </select>
                        </div>
                    </div>
                `;
            }
        } else if (mode === 1) {
            html = '<h5 style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--admin-fg);">📦 Allocate Cylinder</h5>';
            for (let i = 0; i < qty; i++) {
                const valIssued = savedIssued[i] || 'auto';
                let optionsHtml = `<option value="auto" ${valIssued === 'auto' ? 'selected' : ''}>-- Auto-allocate --</option>`;
                function cylTag(t) {
                    return t === 'partner_owned' ? 'BR' : t === 'consumer_owned' ? 'CON' : t === 'vendor_owned' ? 'VEN' : 'OWN';
                }
                matching.forEach(c => {
                    const star = belongsToCustomer(c) ? ' &#9733;' : '';
                    optionsHtml += `<option value="${c.id}" ${valIssued == c.id ? 'selected' : ''}>${c.serial_number}${star}${ownerLabel(c)} (${cylTag(c.ownership_type)})</option>`;
                });
                html += `
                    <div class="serial-exchange-subrow" style="display: grid; grid-template-columns: 1fr; gap: 1rem; margin-top: 0.5rem; align-items: end;">
                        <div>
                            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Select Cylinder to Issue #${i+1}</label>
                            <select name="items[${rowIndex}][issued_cylinders][]" class="form-control issued-cyl-select" style="font-size: 0.85rem; padding: 0.4rem 0.75rem; height: 36px;">
                                ${optionsHtml}
                            </select>
                        </div>
                    </div>
                `;
            }
        } else {
            html = '<h5 style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--admin-fg);">🔄 Cylinder Serial Exchange</h5>';
            for (let i = 0; i < qty; i++) {
                const valIssued = savedIssued[i] || 'auto';
                const valReturned = savedReturned[i] || '';
                let optionsHtml = `<option value="auto" ${valIssued === 'auto' ? 'selected' : ''}>-- Auto-allocate --</option>`;
                function cylTag(t) {
                    return t === 'partner_owned' ? 'BR' : t === 'consumer_owned' ? 'CON' : t === 'vendor_owned' ? 'VEN' : 'OWN';
                }
                matching.forEach(c => {
                    const star = belongsToCustomer(c) ? ' &#9733;' : '';
                    optionsHtml += `<option value="${c.id}" ${valIssued == c.id ? 'selected' : ''}>${c.serial_number}${star}${ownerLabel(c)} (${cylTag(c.ownership_type)})</option>`;
                });
                html += `
                    <div class="serial-exchange-subrow">
                        <div>
                            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Cylinder We Give (Issued) #${i+1}</label>
                            <select name="items[${rowIndex}][issued_cylinders][]" class="form-control issued-cyl-select" style="font-size: 0.85rem; padding: 0.4rem 0.75rem; height: 36px;">
                                ${optionsHtml}
                            </select>
                        </div>
                        <div class="returned-cyl-group">
                            <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Cylinder They Give (Returned) #${i+1}</label>
                            <input type="text" name="items[${rowIndex}][returned_cylinders][]" class="form-control returned-cyl-input" style="font-size: 0.85rem; padding: 0.4rem 0.75rem; height: 36px;" placeholder="Type or select serial..." list="heldCylindersDatalist" value="${valReturned}" onchange="validateReturnGasType(this)" onkeyup="validateReturnGasType(this)">
                        </div>
                    </div>
                `;
            }
        }
        detailsContainer.innerHTML = html;
    }

    // Unified customer combobox logic
    let selectedCustomerId = '';
    
    function buildCustomerDropdown(filter) {
        const list = document.getElementById('customerDropdownList');
        const empty = document.getElementById('customerDropdownEmpty');
        const q = (filter || '').toLowerCase();
        list.innerHTML = '';
        let count = 0;
        allCustomers.forEach(c => {
            const text = (c.name + " " + c.mobile).toLowerCase();
            if (!q || text.includes(q)) {
                count++;
                const div = document.createElement('div');
                div.style.cssText = 'padding:0.65rem 1rem;cursor:pointer;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f1f5f9;transition:background 0.15s;';
                div.innerHTML = '<span style="font-weight:600;font-size:0.9rem;">' + c.name + '</span><span style="font-size:0.8rem;color:var(--admin-muted);">' + c.mobile + '</span>';
                div.addEventListener('mouseenter', function(){ this.style.background = '#f8fafc'; });
                div.addEventListener('mouseleave', function(){ this.style.background = ''; });
                div.addEventListener('click', function(){ selectCustomer(c.id, c.name); });
                list.appendChild(div);
            }
        });
        empty.style.display = count === 0 ? 'block' : 'none';
    }
    
    function selectCustomer(id, name) {
        selectedCustomerId = id;
        document.getElementById('customerSearchInput').value = name + ' ✓';
        document.getElementById('customerSelectedName').value = name;
        document.getElementById('customerSelect').value = id;
        closeCustomerDropdown();
        loadCustomerDetails();
    }
    
    function showCustomerDropdown() {
        const dd = document.getElementById('customerDropdown');
        dd.style.display = 'block';
        buildCustomerDropdown(document.getElementById('customerSearchInput').value.replace(' ✓',''));
    }
    
    function closeCustomerDropdown() {
        document.getElementById('customerDropdown').style.display = 'none';
    }
    
    function filterCustomerDropdown() {
        let val = document.getElementById('customerSearchInput').value;
        // Strip trailing checkmark if re-editing
        val = val.replace(' ✓','');
        document.getElementById('customerSearchInput').value = val;
        // If previously selected, clear selection
        if (selectedCustomerId && document.getElementById('customerSelect').value) {
            document.getElementById('customerSelect').value = '';
            selectedCustomerId = '';
        }
        buildCustomerDropdown(val);
        document.getElementById('customerDropdown').style.display = 'block';
    }
    
    // Close dropdown on outside click
    document.addEventListener('click', function(e) {
        const combo = document.getElementById('customerCombobox');
        if (combo && !combo.contains(e.target)) {
            closeCustomerDropdown();
        }
    });
    
    // Load Customer returns checklist and default type presets
    function loadCustomerDetails() {
        const select = document.getElementById('customerSelect');
        const customerId = select.value;
        const selectedOpt = select.options[select.selectedIndex];
        
        // Update deposit shortcut bar
        const depBar = document.getElementById('depositShortcutBar');
        const depCustId = document.getElementById('depCustomerId');
        if (depBar && customerId && selectedOpt.value !== "") {
            var cust = allCustomers.find(function(c){ return c.id == customerId; });
            if (cust) {
                document.getElementById('depBarDeposit').textContent = '\u20b9' + parseFloat(cust.deposit_balance || 0).toFixed(2);
                document.getElementById('depBarDues').textContent = '\u20b9' + parseFloat(cust.credit_used || 0).toFixed(2);
            }
            depBar.style.display = 'block';
            if (depCustId) depCustId.value = customerId;
        } else {
            depBar.style.display = 'none';
            if (depCustId) depCustId.value = '';
        }
        
        const exchangePanel = document.getElementById('exchangePanel');
        const exchangeList = document.getElementById('exchangeList');
        const datalist = document.getElementById('heldCylindersDatalist');
        
        exchangeList.innerHTML = '';
        exchangePanel.style.display = 'none';
        datalist.innerHTML = '';
        
        if (customerId && selectedOpt.value !== "") {
            const type = selectedOpt.getAttribute('data-type');
            
            // Preset operation select based on customer persona (skip customer-refill rows)
            document.querySelectorAll('.rental-select').forEach(sel => {
                const row = sel.closest('.item-row');
                if (parseInt(sel.value) === 3 || parseInt(sel.value) === 4) return;
                sel.value = (type === 'rental') ? "1" : "0";
                toggleRentalFields(row);
            });
            
            // Load active cylinders for reference / use in exchange
            if (heldCylindersLookup[customerId] && heldCylindersLookup[customerId].length > 0) {
                const cyls = heldCylindersLookup[customerId];
                let hasHeldCylinders = false;
                let hasOwnCylinders = false;
                
                // Cylinders held by customer (company/partner/other owned — needs to be returned)
                const held = cyls.filter(c => c.current_customer_id == customerId);
                // Customer's own cylinders in our inventory (CON, not held by anyone)
                const owned = cyls.filter(c => c.ownership_type === 'consumer_owned' && c.original_owner_customer_id == customerId && !c.current_customer_id);
                
                if (held.length > 0) {
                    hasHeldCylinders = true;
                    const heading = document.createElement('div');
                    heading.style.cssText = "font-weight:800;font-size:0.8rem;color:var(--admin-accent);padding:0.5rem 0;border-bottom:1px solid #e2e8f0;margin-bottom:0.5rem;";
                    heading.textContent = '↩ Cylinders to Collect from Customer';
                    exchangeList.appendChild(heading);
                    
                    held.forEach(cyl => {
                        const opt = document.createElement('option');
                        opt.value = cyl.serial_number;
                        opt.text = `${cyl.gas_name} (${cyl.size_capacity})`;
                        datalist.appendChild(opt);
                        
                        const div = document.createElement('div');
                        div.className = 'exchange-card';
                        div.id = 'card_cyl_' + cyl.id;
                        div.dataset.gasTypeId = cyl.gas_type_id;
                        div.dataset.sizeCapacity = cyl.size_capacity;
                        div.style.cssText = "display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 0.75rem 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 0.5rem;";
                        
                        div.innerHTML = `
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="serial-span" style="color: var(--admin-accent); font-family: monospace; font-weight: 700; background: #e0f2fe; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem;">${cyl.serial_number}${cyl.ownership_type === 'partner_owned' ? '<span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(251,191,36,0.3);" title="Partner: ' + (cyl.partner_name || 'Unknown') + '">BR</span>' : cyl.ownership_type === 'consumer_owned' ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(59,130,246,0.3);" title="Belongs to: ' + (cyl.original_owner_name || 'Unknown') + '">CON</span>' : cyl.ownership_type === 'vendor_owned' ? '<span style="background:#e8d5f5;color:#6b21a8;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(147,51,234,0.3);" title="Vendor: ' + (cyl.vendor_name || 'Unknown') + '">VEN</span>' : '<span style="background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(16,185,129,0.3);">OWN</span>'}</span>
                                <span style="color: #334155; font-weight: 600; font-size: 0.85rem;">${cyl.gas_name} (${cyl.size_capacity})</span>
                            </div>
                            <button type="button" class="btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.75rem; border-radius: 6px; white-space: nowrap;" onclick="useCylinderForExchange('${cyl.serial_number}')">
                                Use in Exchange
                            </button>
                        `;
                        exchangeList.appendChild(div);
                    });
                }
                
                if (owned.length > 0) {
                    hasOwnCylinders = true;
                    const heading = document.createElement('div');
                    heading.style.cssText = "font-weight:800;font-size:0.8rem;color:#1e40af;padding:0.5rem 0;border-bottom:1px solid #e2e8f0;margin-bottom:0.5rem;margin-top:0.75rem;";
                    heading.textContent = '→ Customer\'s Own Cylinders (Return to them)';
                    exchangeList.appendChild(heading);
                    
                    owned.forEach(cyl => {
                        const opt = document.createElement('option');
                        opt.value = cyl.serial_number;
                        opt.text = `${cyl.gas_name} (${cyl.size_capacity})`;
                        datalist.appendChild(opt);
                        
                        const div = document.createElement('div');
                        div.className = 'exchange-card';
                        div.id = 'card_cyl_' + cyl.id;
                        div.dataset.gasTypeId = cyl.gas_type_id;
                        div.dataset.sizeCapacity = cyl.size_capacity;
                        div.style.cssText = "display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 0.75rem 1rem; background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 10px; margin-bottom: 0.5rem;";
                        
                        div.innerHTML = `
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="serial-span" style="color: #1e40af; font-family: monospace; font-weight: 700; background: #dbeafe; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem;">${cyl.serial_number}<span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(59,130,246,0.3);" title="Belongs to: ' + (cyl.original_owner_name || 'Unknown') + '">CON</span></span>
                                <span style="color: #334155; font-weight: 600; font-size: 0.85rem;">${cyl.gas_name} (${cyl.size_capacity})</span>
                                <span class="badge" style="font-size:0.6rem;background:#e0e7ff;color:#3730a3;">${cyl.status || '?'}</span>
                            </div>
                            <button type="button" class="btn-primary" style="padding: 0.3rem 0.75rem; font-size: 0.75rem; border-radius: 6px; white-space: nowrap; background: #2563eb;" onclick="returnCylinderToCustomer('${cyl.serial_number}', ${cyl.gas_type_id}, '${cyl.size_capacity}')">
                                Return to Customer
                            </button>
                        `;
                        exchangeList.appendChild(div);
                    });
                }
                
                if (hasHeldCylinders || hasOwnCylinders) {
                    exchangePanel.style.display = 'block';
                }
                filterHeldCylinders();
            }
        }
        
        // Trigger serial update for all rows (refill, rental, sell)
        document.querySelectorAll('.item-row').forEach(row => {
            const mode = parseInt(row.querySelector('.rental-select').value);
            if (mode === 0 || mode === 1 || mode === 2 || mode === 4) {
                updateCylinderSerials(row);
            }
        });
        
        calculateCartTotals();
    }
    
    // Filter held cylinders cards by gas variant and size selected in first item row
    function filterHeldCylinders() {
        const firstRow = document.querySelector('.item-row');
        if (!firstRow) return;
        const gasSelect = firstRow.querySelector('.gas-select');
        const sizeSelect = firstRow.querySelector('.size-select');
        if (!gasSelect || !sizeSelect) return;
        const gasId = gasSelect.value;
        const size = sizeSelect.value;
        let matchCount = 0;
        document.querySelectorAll('.exchange-card').forEach(card => {
            const cardGas = card.dataset.gasTypeId;
            const cardSize = card.dataset.sizeCapacity;
            const match = (!gasId || cardGas === gasId) && (!size || cardSize === size);
            card.style.display = match ? 'flex' : 'none';
            if (match) matchCount++;
        });
        const exchangePanel = document.getElementById('exchangePanel');
        if (exchangePanel) {
            exchangePanel.style.display = matchCount > 0 ? 'block' : 'none';
        }
    }
    
    function addRow() {
        const container = document.getElementById('itemsContainer');
        const firstRow = container.querySelector('.item-row');
        const newRow = firstRow.cloneNode(true);
        
        // Reset row overrides
        newRow.querySelector('.qty-input').value = 1;
        newRow.querySelector('.rate-override').value = '';
        newRow.querySelector('.deficit-alert').style.display = 'none';
        newRow.querySelector('.rental-select').value = "0";
        newRow.querySelector('.rental-options-container').style.display = 'none';
        newRow.querySelector('.rent-rate-input').value = "10.00";
        newRow.querySelector('.free-days-input').value = "3";
        
        const sellContainer = newRow.querySelector('.sell-cylinder-container');
        if (sellContainer) {
            sellContainer.style.display = 'none';
            const sellPrice = sellContainer.querySelector('.sell-price-input');
            if (sellPrice) sellPrice.value = '0.00';
        }
        
        const productContainer = newRow.querySelector('.product-container');
        if (productContainer) {
            productContainer.style.display = 'none';
            const productSelect = productContainer.querySelector('.product-select');
            if (productSelect) productSelect.selectedIndex = 0;
            const productQty = productContainer.querySelector('.product-qty-input');
            if (productQty) productQty.value = '1';
            const sellPriceInput = productContainer.querySelector('.product-sell-price-input');
            if (sellPriceInput) sellPriceInput.value = '';
        }
        
        // Remove hidden rental mode input if present (from cloning a product-mode row)
        var clonedHidden = newRow.querySelector('.rental-mode-hidden');
        if (clonedHidden) clonedHidden.remove();
        
        const details = newRow.querySelector('.cylinder-serials-details');
        if (details) details.innerHTML = '';
        
        // Dynamic indices renaming
        newRow.querySelectorAll('select, input').forEach(input => {
            const name = input.getAttribute('name');
            if (name) {
                const updatedName = name.replace(/\[\d+\]/, '[' + rowCounter + ']');
                input.setAttribute('name', updatedName);
            }
        });
        
        // Enable delete button
        const delBtn = newRow.querySelector('.remove-btn');
        delBtn.style.display = 'inline-block';
        
        container.appendChild(newRow);
        rowCounter++;
        
        // Sync display state for all mode-dependent fields
        toggleRentalFields(newRow);
        
        // Initial presets for default price of selected gas
        const gasSelect = newRow.querySelector('.gas-select');
        handleGasChange(gasSelect);
        updateCylinderSerials(newRow);
    }
    
    function removeRow(btn) {
        btn.closest('.item-row').remove();
        calculateCartTotals();
    }
    
    function updateRowPriceFromVariant(row) {
        const gasSelect = row.querySelector('.gas-select');
        const sizeSelect = row.querySelector('.size-select');
        const rateOverride = row.querySelector('.rate-override');
        
        const gasOpt = gasSelect.options[gasSelect.selectedIndex];
        if (!gasOpt) return;
        
        const basePrice = parseFloat(gasOpt.getAttribute('data-price')) || 0;
        const selectedSize = sizeSelect.value;
        
        let variantPrices = {};
        try {
            const variantPricesStr = gasOpt.getAttribute('data-variant-prices');
            if (variantPricesStr) {
                variantPrices = JSON.parse(variantPricesStr);
            }
        } catch (e) {
            console.error("Error parsing data-variant-prices:", e);
        }
        
        const resolvedPrice = (variantPrices[selectedSize] !== undefined) ? parseFloat(variantPrices[selectedSize]) : basePrice;
        
        rateOverride.value = resolvedPrice.toFixed(2);
    }
    
    function handleGasChange(select) {
        const row = select.closest('.item-row');
        const opt = select.options[select.selectedIndex];
        if (!opt) return;
        
        const gasId = opt.value;
        const sizeSelect = row.querySelector('.size-select');
        const qtyInput = row.querySelector('.qty-input');
        const sizeCol = sizeSelect ? sizeSelect.closest('div') : null;
        const qtyCol = qtyInput ? qtyInput.closest('div') : null;
        const sizeLabel = sizeCol ? sizeCol.querySelector('.form-label') : null;
        const stockIndicator = row.querySelector('.stock-indicator');
        const rentalSelect = row.querySelector('.rental-select');
        const rateOverride = row.querySelector('.rate-override');
        const productContainer = row.querySelector('.product-container');
        const serialDetails = row.querySelector('.cylinder-serials-details');
        const rentalContainer = row.querySelector('.rental-options-container');
        const sellContainer = row.querySelector('.sell-cylinder-container');
        
        // Normal gas mode: restore fields, populate sizes
        if (sizeCol) sizeCol.style.display = '';
        if (qtyCol) qtyCol.style.display = '';
        if (stockIndicator) stockIndicator.closest('div').style.display = '';
        if (rentalSelect) {
            rentalSelect.disabled = false;
            rentalSelect.closest('div').style.display = '';
            if (rentalSelect.value === '3') rentalSelect.value = '0';
        }
        // Remove the hidden rental mode input when switching back to gas mode
        var existingHidden = row.querySelector('.rental-mode-hidden');
        if (existingHidden) existingHidden.remove();
        if (rateOverride) rateOverride.closest('div').style.display = '';
        if (productContainer) productContainer.style.display = 'none';
        if (serialDetails) serialDetails.style.display = '';
        row.dataset.refreshingSizes = '1';
        toggleRentalFields(row);
        delete row.dataset.refreshingSizes;

        const sizesStr = opt.getAttribute('data-sizes') || '';
        const sizesArr = sizesStr.split(',').map(s => s.trim()).filter(s => s.length > 0);
        const currentSelectedSize = sizeSelect.value;
        sizeSelect.innerHTML = '';
        
        if (sizesArr.length === 0) {
            console.warn("handleGasChange: data-sizes is empty or invalid for gasId=" + gasId + " raw='" + sizesStr + "'");
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.text = sizesStr ? '[Invalid sizes: "' + sizesStr + '"]' : '[No sizes configured for this gas]';
            sizeSelect.appendChild(placeholder);
            updateRowPriceFromVariant(row);
            calculateCartTotals();
            return;
        }
        
        const basePrice = parseFloat(opt.getAttribute('data-price')) || 0;
        let variantPrices = {};
        try {
            const variantPricesStr = opt.getAttribute('data-variant-prices');
            if (variantPricesStr) {
                variantPrices = JSON.parse(variantPricesStr);
            }
        } catch (e) {
            console.error("Error parsing data-variant-prices:", e);
        }
        
        let hasAvailable = false;
        sizesArr.forEach(sz => {
            const resolvedPrice = (variantPrices[sz] !== undefined) ? parseFloat(variantPrices[sz]) : basePrice;
            const stockKey = gasId + "_" + sz;
            const stock = stockCounts[stockKey] || 0;
            
            const sizeOpt = document.createElement('option');
            sizeOpt.value = sz;
            
            // For Rental (1) and Refill Service (4), sizes are always available regardless of stock
            const modeForSizes = row.querySelector('.rental-select')?.value;
            const isStockIndependentSizes = modeForSizes === '4';
            if (stock > 0 || isStockIndependentSizes) {
                hasAvailable = true;
                sizeOpt.text = sz + " (₹" + resolvedPrice.toFixed(2) + ")" + (stock > 0 ? " [Stock: " + stock + "]" : "");
                if (sz === currentSelectedSize) {
                    sizeOpt.selected = true;
                }
            } else {
                sizeOpt.text = sz + " (₹" + resolvedPrice.toFixed(2) + ") — Not Available";
                sizeOpt.disabled = true;
            }
            sizeSelect.appendChild(sizeOpt);
        });
        
        if (!hasAvailable) {
            const disabledOpt = document.createElement('option');
            disabledOpt.value = '';
            disabledOpt.text = '-- No sizes available in stock --';
            disabledOpt.disabled = true;
            sizeSelect.appendChild(disabledOpt);
        }
        
        // Preset override input box to default rate from variant pricing
        updateRowPriceFromVariant(row);
        
        calculateCartTotals();
        filterHeldCylinders();
        updateStockDependentVisibility(row);
    }
    
    function calculateCartTotals() {
        let subtotal = 0;
        let deposits = 0;
        let hasStockError = false;
        
        // Track combined requested quantities by variant key across all cart rows
        const requestedQuantities = {};
        document.querySelectorAll('.item-row').forEach(row => {
            const rentalSelect = row.querySelector('.rental-select');
            const mode = parseInt(rentalSelect.value);
            if (mode === 3 || mode === 4) return; // skip product and refill service rows
            const gasSelect = row.querySelector('.gas-select');
            const sizeSelect = row.querySelector('.size-select');
            const qtyInput = row.querySelector('.qty-input');
            
            const gasId = gasSelect.value;
            const size = sizeSelect.value;
            const qty = parseInt(qtyInput.value) || 1;
            const stockKey = gasId + "_" + size;
            
            requestedQuantities[stockKey] = (requestedQuantities[stockKey] || 0) + qty;
        });
        
        document.querySelectorAll('.item-row').forEach(row => {
            const gasSelect = row.querySelector('.gas-select');
            const sizeSelect = row.querySelector('.size-select');
            const qtyInput = row.querySelector('.qty-input');
            const rentalSelect = row.querySelector('.rental-select');
            const rateOverride = row.querySelector('.rate-override');
            
            const gasId = gasSelect.value;
            const size = sizeSelect.value;
            const qty = parseInt(qtyInput.value) || 1;
            const mode = parseInt(rentalSelect.value);
            
            if (mode === 4) {
                // Customer Cylinder Refill Service (4) — no stock consumption, just pricing
                const localRateOverride = row.querySelector('.rate-override');
                const gasOpt = gasSelect.options[gasSelect.selectedIndex];
                const defaultPrice = parseFloat(gasOpt.getAttribute('data-price')) || 0;
                const overPrice = parseFloat(localRateOverride.value);
                const rate = (!isNaN(overPrice) && overPrice >= 0) ? overPrice : defaultPrice;
                subtotal += (rate * qty);
                updateCylinderSerials(row);
                return;
            }

            if (mode === 3) {
                // Product mode
                const productSelect = row.querySelector('.product-select');
                const productQtyInput = row.querySelector('.product-qty-input');
                const productSellPriceInput = row.querySelector('.product-sell-price-input');
                const productQty = parseInt(productQtyInput ? productQtyInput.value : 1) || 1;
                if (productSelect) {
                    const opt = productSelect.options[productSelect.selectedIndex];
                    if (opt && opt.value) {
                        const productPrice = parseFloat(opt.getAttribute('data-price')) || 0;
                        const sellPrice = (productSellPriceInput && productSellPriceInput.value !== '') ? parseFloat(productSellPriceInput.value) : NaN;
                        const unitPrice = (!isNaN(sellPrice) && sellPrice >= 0) ? sellPrice : productPrice;
                        const lineTotal = unitPrice * productQty;
                        subtotal += lineTotal;
                        // Check product stock (only when a valid product is selected)
                        const productStock = parseInt(opt.getAttribute('data-stock') || 0) || 0;
                        if (productStock < productQty) {
                            hasStockError = true;
                            const alertBox = row.querySelector('.deficit-alert');
                            if (alertBox) {
                                alertBox.innerHTML = `⚠️ Insufficient product stock! Available: <strong>${productStock}</strong>, requested: ${productQty}.`;
                                alertBox.style.display = 'block';
                            }
                        }
                    }
                }
                return;
            }
            
            // Resolve pricing - custom overrides vs defaults (including size price resolution fallback)
            const gasOpt = gasSelect.options[gasSelect.selectedIndex];
            const defaultPrice = parseFloat(gasOpt.getAttribute('data-price')) || 0;
            const overPrice = parseFloat(rateOverride.value);
            
            let variantPrices = {};
            try {
                const variantPricesStr = gasOpt.getAttribute('data-variant-prices');
                if (variantPricesStr) {
                    variantPrices = JSON.parse(variantPricesStr);
                }
            } catch (e) {}
            const resolvedDefaultPrice = (variantPrices[size] !== undefined) ? parseFloat(variantPrices[size]) : defaultPrice;
            const rate = (!isNaN(overPrice) && overPrice >= 0) ? overPrice : resolvedDefaultPrice;
            
            // Lookup Warehouse stock capacity available
            const stockKey = gasId + "_" + size;
            const availableFilled = stockCounts[stockKey] || 0;
            const totalRequested = requestedQuantities[stockKey];
            
            // Render basic available text helper
            row.querySelector('.stock-indicator').innerText = `(Stock: ${availableFilled} filled)`;
            
            const alertBox = row.querySelector('.deficit-alert');
            alertBox.style.display = 'none';
            
            // Compare combined requested qty against warehouse stock availability for both rental and refill
            if (totalRequested > availableFilled) {
                hasStockError = true;
                if (qty === totalRequested) {
                    alertBox.innerHTML = `⚠️ Deficit Error: Requesting ${qty} cylinders, but only <strong>${availableFilled}</strong> are filled in stock!`;
                } else {
                    alertBox.innerHTML = `⚠️ Deficit Error: Combined request for this variant is ${totalRequested} cylinders, but only <strong>${availableFilled}</strong> are filled in stock!`;
                }
                alertBox.style.display = 'block';
            }
            
            if (mode === 2) {
                // Sell mode: add sell_price on top of gas price
                const sellPriceInput = row.querySelector('.sell-price-input');
                const sellPrice = parseFloat(sellPriceInput ? sellPriceInput.value : 0) || 0;
                subtotal += sellPrice;
            }
            subtotal += (rate * qty);
            // Accumulate deposit for rental mode
            if (mode === 1) {
                const depositInput = row.querySelector('.deposit-amount-input');
                const depositPerCyl = parseFloat(depositInput ? depositInput.value : 0) || 0;
                deposits += depositPerCyl * qty;
            }
            // Update cylinder serials for all modes (refill, rental, sell, refill service)
            if (mode === 0 || mode === 1 || mode === 2 || mode === 4) {
                updateCylinderSerials(row);
            }
        });
        
        // Financial summary totals
        const gstSelect = document.getElementById('gstRate');
        let gstRate = parseFloat(gstSelect.value);
        if (gstSelect.value === 'custom') {
            gstRate = parseFloat(document.getElementById('gstCustomRate').value) || 0;
        }
        const discountInput = document.getElementById('discountInput');
        const discount = parseFloat(discountInput ? discountInput.value : 0) || 0;
        
        const tax = gstRate > 0 ? (subtotal * gstRate / 100) : 0.00;
        const cgst = tax / 2;
        const sgst = tax / 2;
        const grandTotal = Math.max(0.00, subtotal + tax - discount);
        
        updateGstLabel();
        
        function setText(id, val) { var el = document.getElementById(id); if (el) el.innerText = val; }
        setText('sumSubtotal', '₹' + subtotal.toFixed(2));
        setText('sumSubtotalMobile', '₹' + subtotal.toFixed(2));
        setText('sumDeposit', '₹' + deposits.toFixed(2));
        setText('sumDepositMobile', '₹' + deposits.toFixed(2));
        setText('sumTaxable', '₹' + subtotal.toFixed(2));
        setText('sumTaxableMobile', '₹' + subtotal.toFixed(2));
        setText('sumCGST', '₹' + cgst.toFixed(2));
        setText('sumCGSTMobile', '₹' + cgst.toFixed(2));
        setText('sumSGST', '₹' + sgst.toFixed(2));
        setText('sumSGSTMobile', '₹' + sgst.toFixed(2));
        setText('sumTax', '₹' + tax.toFixed(2));
        setText('sumTaxMobile', '₹' + tax.toFixed(2));
        setText('sumDiscount', '-₹' + discount.toFixed(2));
        setText('sumDiscountMobile', '-₹' + discount.toFixed(2));
        setText('sumGrandTotal', '₹' + grandTotal.toFixed(2));
        setText('sumGrandTotalMobile', '₹' + grandTotal.toFixed(2));
        const totalToCollect = grandTotal + deposits;
        setText('sumTotalToCollect', '₹' + totalToCollect.toFixed(2));
        setText('sumTotalToCollectMobile', '₹' + totalToCollect.toFixed(2));
        
        // Handle checkout block if stock deficits exist
        var checkoutBtns = [document.getElementById('checkoutBtn'), document.getElementById('checkoutBtnMobile')];
        var checkoutWarnings = [
            document.getElementById('checkoutBtnWarning'),
            document.getElementById('checkoutBtnWarningDesktop')
        ];
        
        checkoutBtns.forEach(function(btn) {
            if (btn) {
                btn.disabled = hasStockError;
                btn.style.opacity = hasStockError ? '0.5' : '1';
            }
        });
        checkoutWarnings.forEach(function(w) {
            if (w) { w.style.display = hasStockError ? 'block' : 'none'; }
        });
        
        // Auto-run exchange validation rules to align with current cart quantities
        validateExchangeRules();
    }
    
    function validateExchangeRules() {
        if (isAutoProvisioning) return;
        // 1. Gather purchased Refill-Only variant quantities from the item rows
        const refillQuantities = {};
        document.querySelectorAll('.item-row').forEach(row => {
            const gasSelect = row.querySelector('.gas-select');
            const sizeSelect = row.querySelector('.size-select');
            const qtyInput = row.querySelector('.qty-input');
            const rentalSelect = row.querySelector('.rental-select');
            
            const gasId = gasSelect.value;
            const size = sizeSelect.value;
            const qty = parseInt(qtyInput.value) || 0;
            const mode = parseInt(rentalSelect.value);
            
            if (gasId && size && mode === 0) { // ONLY count Refill-Only (exclude rental and sell)
                const key = gasId + "_" + size;
                refillQuantities[key] = (refillQuantities[key] || 0) + qty;
            }
        });
        
        // 2. Count checked return checkboxes by variant
        const returnCounts = {};
        const checkboxes = document.querySelectorAll('.return-checkbox');
        
        let violated = false;
        let violatedMessages = [];
        
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const gasId = cb.getAttribute('data-gas-id');
                const size = cb.getAttribute('data-size');
                const key = gasId + "_" + size;
                
                returnCounts[key] = (returnCounts[key] || 0) + 1;
                
                const allowedQty = refillQuantities[key] || 0;
                
                if (returnCounts[key] > allowedQty) {
                    // VIOLATION! Enforce self-healing by unchecking the box
                    cb.checked = false;
                    returnCounts[key]--; // Decrement the count since we unchecked it
                    violated = true;
                    
                    const gasName = cb.closest('label').querySelector('.gas-name-span').innerText;
                    const serial = cb.closest('label').querySelector('.serial-span').innerText;
                    violatedMessages.push(`Cylinder <strong>${serial}</strong> (${gasName}) was deselected because you don't have enough matching "Refill-Only" items in your cart.`);
                }
            }
        });
        
        if (violated && violatedMessages.length > 0) {
            showPolicyViolationModal(violatedMessages.join('<br><br>'));
        }
        
        // Update all returns checklist badges in the UI
        updateReturnChecklistStatusBadges();
    }
    
    // Interactive checklist checkbox event interceptor
    function handleReturnCheckboxChange(cb) {
        const gasId = cb.getAttribute('data-gas-id');
        const sizeCapacity = cb.getAttribute('data-size');
        
        if (cb.checked) {
            autoProvisionRefillRow(gasId, sizeCapacity);
        } else {
            autoDecommissionRefillRow(gasId, sizeCapacity);
        }
    }
    
    // Auto-fill/Provision matching Refill-Only row in cart
    function autoProvisionRefillRow(gasId, sizeCapacity) {
        isAutoProvisioning = true;
        try {
            let matched = false;
            document.querySelectorAll('.item-row').forEach(row => {
                if (matched) return;
                const gasSelect = row.querySelector('.gas-select');
                const sizeSelect = row.querySelector('.size-select');
                const rentalSelect = row.querySelector('.rental-select');
                
                if (gasSelect.value == gasId && sizeSelect.value == sizeCapacity && rentalSelect.value == "0") {
                    // Matching Refill-Only row found! Increment its quantity.
                    const qtyInput = row.querySelector('.qty-input');
                    qtyInput.value = (parseInt(qtyInput.value) || 0) + 1;
                    matched = true;
                }
            });
            
            if (!matched) {
                // Re-use default untouched first row if applicable, otherwise create a new row
                const rows = document.querySelectorAll('.item-row');
                let targetRow = null;
                if (rows.length === 1) {
                    const r = rows[0];
                    const qtyInput = r.querySelector('.qty-input');
                    const rentalSelect = r.querySelector('.rental-select');
                    const rateOverride = r.querySelector('.rate-override');
                    const gasSelect = r.querySelector('.gas-select');
                    if (qtyInput.value == "1" && rentalSelect.value == "0" && (rateOverride.value == "" || parseFloat(rateOverride.value) == parseFloat(gasSelect.options[gasSelect.selectedIndex].getAttribute('data-price')))) {
                        targetRow = r;
                    }
                }
                
                if (!targetRow) {
                    addRow();
                    const allRows = document.querySelectorAll('.item-row');
                    targetRow = allRows[allRows.length - 1];
                }
                
                // Set details
                const gasSelect = targetRow.querySelector('.gas-select');
                gasSelect.value = gasId;
                handleGasChange(gasSelect);
                
                const sizeSelect = targetRow.querySelector('.size-select');
                sizeSelect.value = sizeCapacity;
                
                const rentalSelect = targetRow.querySelector('.rental-select');
                rentalSelect.value = "0"; // Refill-Only
                
                const qtyInput = targetRow.querySelector('.qty-input');
                qtyInput.value = 1;
            }
        } finally {
            isAutoProvisioning = false;
        }
        
        calculateCartTotals();
    }
    
    // Auto-remove/Decrement matching Refill-Only row in cart on checkbox uncheck
    function autoDecommissionRefillRow(gasId, sizeCapacity) {
        let matched = false;
        document.querySelectorAll('.item-row').forEach(row => {
            if (matched) return;
            const gasSelect = row.querySelector('.gas-select');
            const sizeSelect = row.querySelector('.size-select');
            const rentalSelect = row.querySelector('.rental-select');
            const qtyInput = row.querySelector('.qty-input');
            
            if (gasSelect.value == gasId && sizeSelect.value == sizeCapacity && rentalSelect.value == "0") {
                const currentQty = parseInt(qtyInput.value) || 0;
                if (currentQty > 1) {
                    qtyInput.value = currentQty - 1;
                } else {
                    const allRows = document.querySelectorAll('.item-row');
                    if (allRows.length > 1) {
                        row.remove();
                    } else {
                        qtyInput.value = 1; // Keep 1 but default
                    }
                }
                matched = true;
            }
        });
        
        calculateCartTotals();
    }
    
    // Dynamically update visual badges and card colors in the UI
    function updateReturnChecklistStatusBadges() {
        // 1. Get Refill-Only quantities from cart
        const refillQuantities = {};
        document.querySelectorAll('.item-row').forEach(row => {
            const gasSelect = row.querySelector('.gas-select');
            const sizeSelect = row.querySelector('.size-select');
            const qtyInput = row.querySelector('.qty-input');
            const rentalSelect = row.querySelector('.rental-select');
            
            const gasId = gasSelect.value;
            const size = sizeSelect.value;
            const qty = parseInt(qtyInput.value) || 0;
            const mode = parseInt(rentalSelect.value);
            
            if (gasId && size && mode === 0) {
                const key = gasId + "_" + size;
                refillQuantities[key] = (refillQuantities[key] || 0) + qty;
            }
        });
        
        // 2. Get checked returns count
        const checkedCounts = {};
        document.querySelectorAll('.return-checkbox').forEach(cb => {
            if (cb.checked) {
                const key = cb.getAttribute('data-gas-id') + "_" + cb.getAttribute('data-size');
                checkedCounts[key] = (checkedCounts[key] || 0) + 1;
            }
        });
        
        // 3. Render appropriate status badge on each cylinder row
        document.querySelectorAll('.return-checkbox').forEach(cb => {
            const gasId = cb.getAttribute('data-gas-id');
            const size = cb.getAttribute('data-size');
            const key = gasId + "_" + size;
            const cylId = cb.value;
            const badgeContainer = document.getElementById('badge_cyl_' + cylId);
            if (!badgeContainer) return;
            
            const isChecked = cb.checked;
            const totalRefillsInCart = refillQuantities[key] || 0;
            const totalCheckedOfThisVariant = checkedCounts[key] || 0;
            
            // Visual feedback on card parent
            const card = document.getElementById('card_cyl_' + cylId);
            if (!card) return;
            
            if (isChecked) {
                card.style.background = '#f0fdf4';
                card.style.borderColor = '#bbf7d0';
                badgeContainer.innerHTML = `<span style="font-size: 0.7rem; font-weight: 800; background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 12px; display: inline-block;">✓ Exchange Selected</span>`;
            } else {
                // If there are still "available / unused" refill slots in cart for this variant
                const unusedSlots = totalRefillsInCart - totalCheckedOfThisVariant;
                if (unusedSlots > 0) {
                    card.style.background = '#fffbeb';
                    card.style.borderColor = '#fde68a';
                    badgeContainer.innerHTML = `<span style="font-size: 0.7rem; font-weight: 800; background: #fef3c7; color: #b45309; padding: 2px 8px; border-radius: 12px; display: inline-block;">Ready to Exchange</span>`;
                } else {
                    card.style.background = '#f8fafc';
                    card.style.borderColor = '#e2e8f0';
                    badgeContainer.innerHTML = `<span style="font-size: 0.7rem; font-weight: 700; background: #e2e8f0; color: #64748b; padding: 2px 8px; border-radius: 12px; display: inline-block;">Auto-fills Refill row</span>`;
                }
            }
        });
    }
    
    // Initial triggers
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.gas-select').forEach(select => {
            const row = select.closest('.item-row');
            const rateOverride = row.querySelector('.rate-override');
            if (rateOverride && rateOverride.value === '') {
                handleGasChange(select);
            }
        });
        calculateCartTotals();
        buildCustomerDropdown('');

        // Auto-select customer from URL param (returning from deposit)
        var urlParams = new URLSearchParams(window.location.search);
        var custId = urlParams.get('customer_id');
        if (custId) {
            var sel = document.getElementById('customerSelect');
            if (sel) {
                sel.value = custId;
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.value) {
                    selectCustomer(parseInt(custId), opt.text.split(' (')[0]);
                }
            }
        }

        // AJAX deposit form submit
        document.getElementById('depositForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            var form = this;
            var formData = new FormData(form);
            var btn = form.querySelector('button[type="submit"]');
            btn.textContent = 'Processing...';
            btn.disabled = true;

            fetch('order-create.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.textContent = 'Add Deposit';
                    btn.disabled = false;
                    if (data.success) {
                        closeModal('depositModal');
                        form.reset();
                        form.querySelector('input[name="dep_amount"]').value = '';
                        // Refresh deposit/dues display
                        var custSel = document.getElementById('customerSelect');
                        if (custSel.value) loadCustomerDetails();
                        // Flash bar green
                        var bar = document.getElementById('depositShortcutBar');
                        if (bar) {
                            bar.style.borderColor = '#16a34a';
                            setTimeout(function() { bar.style.borderColor = '#bbf7d0'; }, 2000);
                        }
                        if (data.receipt_url) window.open(data.receipt_url, '_blank');
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(function(err) {
                    btn.textContent = 'Add Deposit';
                    btn.disabled = false;
                    alert('Error: ' + err.message);
                });
        });

        // Client-side validation on checkout form submit — no page reload on validation errors
        document.getElementById('checkoutForm').addEventListener('submit', function(e) {
            var errors = [];
            var customerId = document.getElementById('customerSelect').value;
            if (!customerId) {
                errors.push('Please select a valid customer.');
            }

            var rows = document.querySelectorAll('.item-row');
            if (rows.length === 0) {
                errors.push('Please add at least one item.');
            }

            var allocatedSellIds = [];
            rows.forEach(function(row, idx) {
                var gasSelect = row.querySelector('.gas-select');
                var sizeSelect = row.querySelector('.size-select');
                var qtyInput = row.querySelector('.qty-input');
                var rentalSelect = row.querySelector('.rental-select');
                if (!gasSelect || !sizeSelect || !qtyInput || !rentalSelect) return;

                var gasId = gasSelect.value;
                var size = sizeSelect.value;
                var qty = parseInt(qtyInput.value) || 1;
                var mode = parseInt(rentalSelect.value);

                // Skip product mode rows
                if (mode === 3 || mode === 4) return;

                if (!gasId || !size || qty <= 0) {
                    errors.push('Row #' + (idx + 1) + ': Invalid item parameters (gas, size, or quantity).');
                }

                if (mode === 2) {
                    var sellSelects = row.querySelectorAll('.sell-cyl-select');
                    if (sellSelects.length === 0) {
                        errors.push('Row #' + (idx + 1) + ': Cylinder selection not available. Please adjust quantity or refresh.');
                    }
                    sellSelects.forEach(function(sel, si) {
                        if (!sel.value) {
                            errors.push('Row #' + (idx + 1) + ': Please select a cylinder to sell for item #' + (si + 1) + '.');
                        } else {
                            if (allocatedSellIds.indexOf(sel.value) !== -1) {
                                errors.push('Sell cylinder ID ' + sel.value + ' was selected multiple times across different rows.');
                            }
                            allocatedSellIds.push(sel.value);

                            var cyl = null;
                            for (var ci = 0; ci < filledCylinders.length; ci++) {
                                if (filledCylinders[ci].id == sel.value) {
                                    cyl = filledCylinders[ci];
                                    break;
                                }
                            }
                            if (cyl && cyl.ownership_type !== 'owned') {
                                var tag = cyl.ownership_type === 'vendor_owned' ? 'vendor-owned' : cyl.ownership_type === 'partner_owned' ? 'partner-owned' : 'consumer-owned';
                                errors.push('Row #' + (idx + 1) + ': Cylinder "' + cyl.serial_number + '" is ' + tag + ' and cannot be sold. Only business-owned cylinders can be sold.');
                            }
                        }
                    });
                }
            });

            if (errors.length > 0) {
                e.preventDefault();
                showValidationModal(errors.join('<br><br>'));
            }
        });
    });
</script>

<!-- Quick Deposit Modal -->
<div class="modal" id="depositModal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3>Add Deposit</h3>
            <button class="modal-close" onclick="closeModal('depositModal')">&times;</button>
        </div>
        <form method="POST" id="depositForm"><?php csrfField(); ?>
            <input type="hidden" name="action" value="record_deposit">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="dep_customer_id" id="depCustomerId">

            <div class="form-group">
                <label class="form-label">Amount (₹)</label>
                <input type="number" step="0.01" name="dep_amount" class="form-control" required placeholder="0.00">
            </div>

            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select name="dep_method" class="form-control">
                    <option value="Cash">Cash</option>
                    <option value="UPI / Online">UPI / Online Transfer</option>
                    <option value="Bank Check">Bank Check</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="dep_notes" class="form-control" placeholder="Optional...">
            </div>

            <div class="form-group">
                <label class="form-label">Date</label>
                <input type="datetime-local" name="dep_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
            </div>

            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;">Add Deposit</button>
        </form>
    </div>
</div>

<script>
// Progress stepper auto-advance
function updateStepper() {
    var custSel = document.getElementById('customerSelect');
    var items = document.querySelectorAll('.item-row');
    var hasItems = items.length > 0 && items[0].querySelector('.gas-select') && items[0].querySelector('.gas-select').value !== '';
    var steps = document.querySelectorAll('.progress-stepper .step');
    if (!steps.length) return;
    var active = 1;
    if (custSel && custSel.value) active = 2;
    if (hasItems) active = 3;
    var submitBtn = document.getElementById('checkoutForm') ? document.getElementById('checkoutForm').querySelector('button[type="submit"]') : null;
    if (active >= 3) active = 4;
    steps.forEach(function(s, i) {
        var num = i + 1;
        var circle = s.querySelector('.step-circle');
        var label = s.querySelector('.step-label');
        if (num <= active) {
            s.style.opacity = '1';
            circle.style.background = 'var(--admin-accent)';
            circle.style.color = '#fff';
            label.style.color = 'var(--admin-fg)';
            label.style.fontWeight = '700';
        } else {
            circle.style.background = 'var(--admin-border)';
            circle.style.color = 'var(--admin-muted)';
            label.style.color = 'var(--admin-muted)';
            label.style.fontWeight = '600';
        }
        var line = s.querySelector('.step-line');
        if (line) line.style.background = num <= active ? 'var(--admin-accent)' : 'var(--admin-border)';
    });
}
document.addEventListener('DOMContentLoaded', function() {
    var custSel = document.getElementById('customerSelect');
    if (custSel) custSel.addEventListener('change', updateStepper);
    document.addEventListener('change', function(e) {
        if (e.target.closest('.gas-select') || e.target.closest('.qty-input')) updateStepper();
    });
    updateStepper();
});

// Mode 4: update remaining count on input
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('customer-cyl-input')) {
        const row = e.target.closest('.item-row');
        if (!row) return;
        const rentalSelect = row.querySelector('.rental-select');
        if (parseInt(rentalSelect?.value) !== 4) return;
        updateRefillSerialCount(row);
    }
});

// Mode 4: paste comma-separated serials into empty fields
document.addEventListener('paste', function(e) {
    const input = e.target.closest('.customer-cyl-input');
    if (!input) return;
    const row = input.closest('.item-row');
    if (!row) return;
    const rentalSelect = row.querySelector('.rental-select');
    if (parseInt(rentalSelect?.value) !== 4) return;

    e.preventDefault();
    const pasteText = (e.clipboardData || window.clipboardData).getData('text');
    const serials = pasteText.split(/[,;\n]+/).map(s => s.trim()).filter(s => s);
    if (serials.length === 0) return;

    const inputs = row.querySelectorAll('.customer-cyl-input');
    let idx = 0;
    inputs.forEach(inp => {
        if (!inp.value.trim() && idx < serials.length) {
            inp.value = serials[idx++];
        }
    });

    // If more pasted than empty slots, extend qty to create more fields
    if (idx < serials.length) {
        const qtyInput = row.querySelector('.qty-input');
        if (qtyInput) {
            const extra = serials.length - idx;
            qtyInput.value = parseInt(qtyInput.value) + extra;
            calculateCartTotals();
        }
    }
    updateRefillSerialCount(row);
});

function updateRefillSerialCount(row) {
    const rowAttr = row.querySelector('.qty-input').getAttribute('name');
    const match = rowAttr.match(/items\[(\d+)\]/);
    const rowIndex = match ? match[1] : '0';
    const inputs = row.querySelectorAll('.customer-cyl-input');
    let filled = 0;
    inputs.forEach(inp => { if (inp.value.trim()) filled++; });
    const remaining = inputs.length - filled;
    const counter = document.getElementById('refillSerialCount_' + rowIndex);
    if (counter) {
        counter.textContent = remaining > 0 ? 'Remaining required: ' + remaining : '✓ All fields filled';
        counter.style.color = remaining > 0 ? '#d97706' : '#16a34a';
    }
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('refill-source-select')) {
        const row = e.target.closest('.item-row');
        if (!row) return;
        calculateCartTotals();
    }
});
</script>
<?php
require_once 'layout_footer.php';
?>
