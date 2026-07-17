<?php
$page_title = "Customer Ledger & Profile";
$active_menu = "customers";
require_once 'layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once 'db.php';
require_once 'business_helper.php';
require_once 'inventory-utils.php';
runConsumerCylinderMigrations($pdo);
runPartnerMigrations($pdo);
runRefillRentalMigrations($pdo);
runSellCylinderMigrations($pdo);
runCreditMigrations($pdo);
runCustomerPortalMigrations($pdo);
runLedgerGroupMigrations($pdo);

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo "<script>window.location.href='customers.php';</script>";
    exit();
}

// Fetch Customer Profile
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        echo "<script>window.location.href='customers.php';</script>";
        exit();
    }
} catch (PDOException $e) {
    error_log("customer-profile.php: " . $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/error-page.php';
    exit;
}

// Handle Manual Payment/Deposit Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ledger_adjustment') {
    $amount = floatval($_POST['amount'] ?? 0);
    $damage_deduction = floatval($_POST['damage_deduction'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $payment_type = $_POST['payment_type'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $payment_date_raw = $_POST['payment_date'] ?? date('Y-m-d\TH:i');
    $payment_date = str_replace('T', ' ', $payment_date_raw) . ':00';

    if ($amount <= 0 || empty($payment_type)) {
        echo "<script>window.location.href='customer-profile.php?id=$id&error=1';</script>";
        exit();
    }
    if ($payment_type === 'payment_refunded' && $damage_deduction > $amount) {
        echo "<script>window.location.href='customer-profile.php?id=$id&error=1';</script>";
        exit();
    } else {
        try {
            require_once 'inventory-utils.php';
            runDepositReceiptMigrations($pdo);

            $ledger_group_id = generateLedgerGroupId();
            $pdo->beginTransaction();

            $group_title = '';
            $group_type = '';
            $receipt_label = '';
            $receipt_id = null;
            $payment_id = null;

            if ($payment_type === 'payment_received') {
                $group_type = 'payment_received';
                $settle = processPaymentWithCreditSettlement($pdo, $id, $amount, $payment_method, $payment_date, $notes, $ledger_group_id);
                $payment_id = $settle['payment_id'];
                $amount_for_credit = $settle['amount_for_credit'];
                $amount_for_deposit = $settle['amount_for_deposit'];

                if ($settle['has_credit_settlement']) {
                    if ($amount_for_deposit > 0) {
                        $receipt_label = 'Payment Received';
                    } else {
                        $receipt_label = 'Dues Settled';
                    }
                    $group_title = 'Payment Received – ₹' . number_format($amount, 2);
                    if ($amount_for_deposit > 0) {
                        $group_title .= ' (₹' . number_format($amount_for_credit, 2) . ' dues + ₹' . number_format($amount_for_deposit, 2) . ' deposit)';
                    } else {
                        $group_title .= ' (Dues settled)';
                    }
                } else {
                    $receipt_label = 'Deposit Added';
                    $group_title = 'Deposit Added – ₹' . number_format($amount, 2);
                }

                $receipt_total = $amount;
                $receipt_credit = $amount_for_credit;
                $receipt_deposit = $amount_for_deposit;
            } elseif ($payment_type === 'payment_refunded') {
                $group_type = 'payment_refunded';

                if ($damage_deduction > 0) {
                    $damage_notes = 'Damage deduction on refund' . ($notes ? ' - ' . $notes : '');
                    $stmt = $pdo->prepare("INSERT INTO payments (customer_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, 'deposit_damage', ?, ?, ?)");
                    $stmt->execute([$id, $damage_deduction, $payment_method, $damage_notes, $payment_date, $ledger_group_id]);

                    $net_refund = $amount - $damage_deduction;
                    if ($net_refund > 0) {
                        $stmt = $pdo->prepare("INSERT INTO payments (customer_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, 'deposit_refunded', ?, ?, ?)");
                        $stmt->execute([$id, $net_refund, $payment_method, $notes, $payment_date, $ledger_group_id]);
                        $payment_id = $pdo->lastInsertId();
                    } else {
                        $payment_id = null;
                    }

                    $stmt = $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance - ? WHERE id = ?");
                    $stmt->execute([$amount, $id]);

                    $receipt_label = 'Deposit Refunded';
                    $receipt_total = $amount;
                    $receipt_credit = 0;
                    $receipt_deposit = $net_refund > 0 ? $net_refund : 0;
                    $group_title = 'Deposit Refund – ₹' . number_format($amount, 2);
                    if ($damage_deduction > 0) {
                        $group_title .= ' (Damage ₹' . number_format($damage_deduction, 2) . ')';
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO payments (customer_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, 'deposit_refunded', ?, ?, ?)");
                    $stmt->execute([$id, $amount, $payment_method, $notes, $payment_date, $ledger_group_id]);
                    $payment_id = $pdo->lastInsertId();

                    $stmt = $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance - ? WHERE id = ?");
                    $stmt->execute([$amount, $id]);

                    $receipt_label = 'Deposit Refunded';
                    $receipt_total = $amount;
                    $receipt_credit = 0;
                    $receipt_deposit = $amount;
                    $group_title = 'Deposit Refund – ₹' . number_format($amount, 2);
                }
            }
            
            // Generate deposit receipt for deposit payments
            $is_deposit = ($payment_type === 'payment_received' || $payment_type === 'payment_refunded');
            if ($is_deposit && $payment_id) {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM deposit_receipts");
                $stmt->execute();
                $next_id = $stmt->fetchColumn();
                $receipt_number = 'DEP-' . date('Y') . '-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);
                
                $stmt = $pdo->prepare("INSERT INTO deposit_receipts (receipt_number, payment_id, customer_id, receipt_date, damage_deduction, transaction_label, total_amount, credit_settled, deposit_amount, ledger_group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$receipt_number, $payment_id, $id, $payment_date, $damage_deduction, $receipt_label ?? null, $receipt_total ?? $amount, $receipt_credit ?? 0, $receipt_deposit ?? $amount, $ledger_group_id]);
                $receipt_id = $pdo->lastInsertId();
            }

            // Create ledger group entry
            if ($group_type && $group_title) {
                $entry_date = $payment_date;
                $stmt = $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$ledger_group_id, $id, $group_type, $group_title, $amount, $entry_date]);
            }
            
            $pdo->commit();

            // Send email notification
            require_once __DIR__ . '/../portal/email.php';
            if ($payment_type === 'payment_received') {
                $credit_amt = isset($amount_for_credit) ? floatval($amount_for_credit) : 0;
                $dep_amt = isset($amount_for_deposit) ? floatval($amount_for_deposit) : 0;
                sendPaymentReceivedNotification($id, $amount, $payment_method, $pdo, $credit_amt, $dep_amt);
            } elseif ($payment_type === 'payment_refunded') {
                sendRefundNotification($id, $amount, $damage_deduction, $payment_method, $pdo);
            }

            if ($is_deposit && $receipt_id) {
                echo "<script>window.location.href='deposit-receipt.php?receipt_id=$receipt_id&business=" . getBrandConfig()['business_key'] . "';</script>";
            } else {
                echo "<script>window.location.href='customer-profile.php?id=$id&success=1';</script>";
            }
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "<script>window.location.href='customer-profile.php?id=$id&error=" . urlencode($e->getMessage()) . "';</script>";
            exit();
        }
    }
}

// Fetch active cylinders currently with customer
$active_cylinders = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, g.name as gas_name, oc.name as owner_name, p.company_name as partner_name, v.name as vendor_name, (SELECT id FROM refill_order_items WHERE cylinder_id = c.id AND is_rental = 1 ORDER BY id DESC LIMIT 1) AS refill_order_item_id FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id LEFT JOIN partners p ON c.current_partner_id = p.id LEFT JOIN vendors v ON c.current_vendor_id = v.id WHERE c.current_customer_id = ? AND c.status = 'with_customer' AND NOT (c.ownership_type = 'consumer_owned' AND c.current_customer_id = c.original_owner_customer_id)");
    $stmt->execute([$id]);
    $active_cylinders = $stmt->fetchAll();
    $customer['active_cylinders_count'] = count($active_cylinders);
} catch (PDOException $e) {}

// Fetch this customer's own cylinders currently in our inventory (consumer-owned, empty/filled)
$customer_owned_cylinders = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, g.name as gas_name, oc.name as owner_name, p.company_name as partner_name, v.name as vendor_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id LEFT JOIN partners p ON c.current_partner_id = p.id LEFT JOIN vendors v ON c.current_vendor_id = v.id WHERE c.original_owner_customer_id = ? AND c.ownership_type = 'consumer_owned' AND c.status NOT IN ('returned_to_consumer', 'under_maintenance', 'empty') AND (c.current_customer_id IS NULL OR c.current_customer_id != c.original_owner_customer_id) ORDER BY c.status ASC, c.serial_number ASC");
    $stmt->execute([$id]);
    $customer_owned_cylinders = $stmt->fetchAll();
} catch (PDOException $e) {}

// Fetch consumer-owned cylinders held by the original owner themselves
$consumer_self_held = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, g.name as gas_name, oc.name as owner_name, p.company_name as partner_name, v.name as vendor_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id LEFT JOIN partners p ON c.current_partner_id = p.id LEFT JOIN vendors v ON c.current_vendor_id = v.id WHERE c.original_owner_customer_id = ? AND c.ownership_type = 'consumer_owned' AND c.current_customer_id = c.original_owner_customer_id AND c.status = 'with_customer' ORDER BY c.serial_number ASC");
    $stmt->execute([$id]);
    $consumer_self_held = $stmt->fetchAll();
} catch (PDOException $e) {}

// Handle Return Cylinder Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_cylinder') {
    $ret_cylinder_id = intval($_POST['cylinder_id'] ?? 0);
    $return_type = $_POST['return_type'] ?? '';
    $return_notes = trim($_POST['return_notes'] ?? '');

    if ($ret_cylinder_id > 0 && in_array($return_type, ['consumer_gave_empty', 'return_to_consumer'])) {
        try {
            $pdo->beginTransaction();

            // Verify cylinder exists and is consumer-owned
            $stmt = $pdo->prepare("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ? AND c.ownership_type = 'consumer_owned'");
            $stmt->execute([$ret_cylinder_id]);
            $ret_cyl = $stmt->fetch();

            if ($ret_cyl) {
                if ($return_type === 'consumer_gave_empty') {
                    // Customer gave us their empty cylinder — add to our empty stock, keep consumer tag
                    $stmt = $pdo->prepare("UPDATE cylinders SET status = 'empty', current_customer_id = NULL WHERE id = ?");
                    $stmt->execute([$ret_cylinder_id]);
                    logCylinderTransaction($pdo, $ret_cylinder_id, $id, null, 'consumer_return', "Consumer gave empty cylinder. $return_notes");
                } elseif ($return_type === 'return_to_consumer') {
                    // We are returning this cylinder to the consumer — mark as returned
                    $stmt = $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_customer_id = NULL, current_vendor_id = NULL WHERE id = ?");
                    $stmt->execute([$ret_cylinder_id]);
                    logCylinderTransaction($pdo, $ret_cylinder_id, $id, null, 'consumer_give_back', "Returned to consumer. $return_notes");
                }

                // Rebuild the tracked active cylinder count for this customer after a return
                syncCustomerActiveCylinderCounts($pdo, $id);
            }

            $pdo->commit();
            echo "<script>window.location.href='customer-profile.php?id=$id&success=1';</script>";
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "<script>window.location.href='customer-profile.php?id=$id&error=1';</script>";
            exit();
        }
    } else {
        echo "<script>window.location.href='customer-profile.php?id=$id&error=1';</script>";
        exit();
    }
}

// Handle Rental Return & Settle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rental_return') {
    require_once 'inventory-utils.php';
    try {
        $result = processRentalReturn($pdo, [
            'cylinder_id' => $_POST['cylinder_id'],
            'customer_id' => $id,
            'return_date' => str_replace('T', ' ', ($_POST['return_date'] ?? date('Y-m-d\TH:i'))) . ':00',
            'condition' => $_POST['condition'] ?? 'empty',
            'damage_charge' => $_POST['damage_charge'] ?? 0,
            'damage_description' => $_POST['damage_description'] ?? '',
            'deduct_from_deposit' => $_POST['deduct_from_deposit'] ?? 0,
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'notes' => $_POST['notes'] ?? '',
            'refill_order_item_id' => $_POST['refill_order_item_id'] ?? '',
        ]);
        $return_id = is_array($result) ? $result['return_id'] : $result;
        echo "<script>window.location.href='rental-invoice.php?return_id=$return_id';</script>";
        exit();
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/rental_return_error.log', date('Y-m-d H:i:s') . ' | ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString() . PHP_EOL, FILE_APPEND);
        echo "<script>window.location.href='customer-profile.php?id=$id&error=1';</script>";
        exit();
    }
}

// Build Unified Grouped Ledger (grouped transactions with expandable sub-entries)
$ledger = [];

// 1. Fetch ledger groups (grouped transactions like payment+credit, rental return, etc.)
try {
    $stmt = $pdo->prepare("SELECT * FROM ledger_groups WHERE customer_id = ? ORDER BY entry_date DESC");
    $stmt->execute([$id]);
    $groups = $stmt->fetchAll();

    foreach ($groups as $group) {
        $gid = $group['id'];

        // Fetch payments in this group
        $stmt_p = $pdo->prepare("
            SELECT p.*, dr.id as deposit_receipt_id, dr.receipt_number
            FROM payments p
            LEFT JOIN deposit_receipts dr ON dr.payment_id = p.id
            WHERE p.ledger_group_id = ?
            ORDER BY p.payment_date ASC
        ");
        $stmt_p->execute([$gid]);
        $group_payments = $stmt_p->fetchAll();

        // Fetch credit transactions in this group
        $stmt_c = $pdo->prepare("SELECT * FROM credit_transactions WHERE ledger_group_id = ? ORDER BY transaction_date ASC");
        $stmt_c->execute([$gid]);
        $group_credits = $stmt_c->fetchAll();

        // Fetch rental returns in this group
        $stmt_r = $pdo->prepare("SELECT rr.*, cyl.serial_number, g.name as gas_name, cyl.size_capacity FROM rental_returns rr LEFT JOIN cylinders cyl ON rr.cylinder_id = cyl.id LEFT JOIN gas_types g ON cyl.gas_type_id = g.id WHERE rr.ledger_group_id = ? ORDER BY rr.created_at ASC");
        $stmt_r->execute([$gid]);
        $group_rentals = $stmt_r->fetchAll();

        $entry = [
            'date' => $group['entry_date'],
            'type' => 'group',
            'group_id' => $gid,
            'group_type' => $group['group_type'],
            'title' => $group['title'],
            'total_amount' => $group['total_amount'],
            'payments' => $group_payments,
            'credit_txns' => $group_credits,
            'rental_returns' => $group_rentals,
        ];

        // For credit_order groups, fetch associated order/invoice for the Invoice button
        if ($group['group_type'] === 'credit_order') {
            $order_id_for_invoice = null;
            foreach ($group_credits as $gc) {
                if (!empty($gc['refill_order_id'])) { $order_id_for_invoice = $gc['refill_order_id']; break; }
            }
            if (!$order_id_for_invoice) {
                foreach ($group_payments as $gp) {
                    if (!empty($gp['refill_order_id'])) { $order_id_for_invoice = $gp['refill_order_id']; break; }
                }
            }
            if ($order_id_for_invoice) {
                $entry['order_id'] = $order_id_for_invoice;
                $stmt_o = $pdo->prepare("SELECT invoice_number FROM refill_orders WHERE id = ? LIMIT 1");
                $stmt_o->execute([$order_id_for_invoice]);
                $inv = $stmt_o->fetch();
                $entry['invoice_number'] = $inv ? $inv['invoice_number'] : null;
            }
        } elseif ($group['group_type'] === 'rental_return') {
            // Store the first return_id for the Invoice button
            if (!empty($group_rentals)) {
                $entry['return_id'] = $group_rentals[0]['id'];
            }
        }

        $ledger[] = $entry;
    }
} catch (PDOException $e) {
    error_log("ledger groups fetch: " . $e->getMessage());
}

// 2. Get Refill orders with their items, payments, invoices, and credit transactions
try {
    $stmt = $pdo->prepare("
        SELECT o.*
        FROM refill_orders o
        WHERE o.customer_id = ?
        ORDER BY o.order_date DESC
    ");
    $stmt->execute([$id]);
    $orders = $stmt->fetchAll();

    foreach ($orders as $order) {
        $oid = $order['id'];

        $stmt_i = $pdo->prepare("
            SELECT oi.*, g.name as gas_name, p.name as product_name,
                   c_issued.serial_number as issued_serial,
                   c_issued.ownership_type as issued_ownership_type,
                   c_returned.serial_number as returned_serial,
                   c_returned.ownership_type as returned_ownership_type,
                   oc_issued.name as issued_owner_name,
                   p_issued.company_name as issued_partner_name,
                   v_issued.name as issued_vendor_name,
                   oc_returned.name as returned_owner_name,
                   p_returned.company_name as returned_partner_name,
                   v_returned.name as returned_vendor_name
             FROM refill_order_items oi
             LEFT JOIN gas_types g ON oi.gas_type_id = g.id
             LEFT JOIN products p ON oi.product_id = p.id
             LEFT JOIN cylinders c_issued ON oi.cylinder_id = c_issued.id
            LEFT JOIN cylinders c_returned ON oi.returned_cylinder_id = c_returned.id
            LEFT JOIN customers oc_issued ON c_issued.original_owner_customer_id = oc_issued.id
            LEFT JOIN partners p_issued ON c_issued.current_partner_id = p_issued.id
            LEFT JOIN vendors v_issued ON c_issued.current_vendor_id = v_issued.id
            LEFT JOIN customers oc_returned ON c_returned.original_owner_customer_id = oc_returned.id
            LEFT JOIN partners p_returned ON c_returned.current_partner_id = p_returned.id
            LEFT JOIN vendors v_returned ON c_returned.current_vendor_id = v_returned.id
            WHERE oi.refill_order_id = ?
            ORDER BY oi.id ASC
        ");
        $stmt_i->execute([$oid]);
        $items = $stmt_i->fetchAll();

        $stmt_p = $pdo->prepare("
            SELECT p.*, dr.id as deposit_receipt_id, dr.receipt_number
            FROM payments p
            LEFT JOIN deposit_receipts dr ON dr.payment_id = p.id
            WHERE p.refill_order_id = ?
            ORDER BY p.payment_date ASC
        ");
        $stmt_p->execute([$oid]);
        $payments = $stmt_p->fetchAll();

        // Fetch credit transactions linked to this order
        $stmt_c = $pdo->prepare("SELECT * FROM credit_transactions WHERE refill_order_id = ? ORDER BY transaction_date ASC");
        $stmt_c->execute([$oid]);
        $order_credits = $stmt_c->fetchAll();

        $ledger[] = [
            'date' => $order['order_date'],
            'type' => 'order',
            'order_id' => $oid,
            'grand_total' => $order['grand_total'],
            'subtotal' => $order['subtotal'],
            'deposit_amount' => $order['deposit_amount'],
            'discount' => $order['discount'],
            'tax_amount' => $order['tax_amount'],
            'payment_status' => $order['payment_status'],
            'payment_method' => $order['payment_method'],
            'notes' => $order['notes'],
            'items' => $items,
            'payments' => $payments,
            'order_credits' => $order_credits,
            'invoice_number' => $order['invoice_number'],
        ];
    }
} catch (PDOException $e) {}

// 3. Standalone payments (not linked to any order AND not part of a group)
try {
    $stmt = $pdo->prepare("
        SELECT p.*, dr.id as deposit_receipt_id, dr.receipt_number
        FROM payments p
        LEFT JOIN deposit_receipts dr ON dr.payment_id = p.id
        WHERE p.customer_id = ? AND p.refill_order_id IS NULL AND p.ledger_group_id IS NULL
        ORDER BY p.payment_date DESC
    ");
    $stmt->execute([$id]);
    $payments = $stmt->fetchAll();

    foreach ($payments as $p) {
        $ledger[] = [
            'date' => $p['payment_date'],
            'type' => 'payment',
            'amount' => $p['amount'],
            'payment_method' => $p['payment_method'],
            'payment_type' => $p['payment_type'],
            'notes' => $p['notes'],
            'deposit_receipt_id' => $p['deposit_receipt_id'],
            'receipt_number' => $p['receipt_number'],
        ];
    }
} catch (PDOException $e) {}

// 4. Standalone rental returns (not part of a group)
try {
    $rr_all = $pdo->prepare("SELECT rr.*, cyl.serial_number, g.name as gas_name, cyl.size_capacity FROM rental_returns rr LEFT JOIN cylinders cyl ON rr.cylinder_id = cyl.id LEFT JOIN gas_types g ON cyl.gas_type_id = g.id WHERE rr.customer_id = ? AND rr.ledger_group_id IS NULL ORDER BY rr.created_at DESC");
    $rr_all->execute([$id]);
    foreach ($rr_all as $r) {
        $ledger[] = [
            'date' => $r['created_at'],
            'type' => 'rental_return',
            'return_id' => $r['id'],
            'serial' => $r['serial_number'] ?? '?',
            'gas' => ($r['gas_name'] ?? '?') . ' (' . ($r['size_capacity'] ?? '?') . ')',
            'total_collected' => $r['total_collected'],
            'chargeable_days' => $r['chargeable_days'],
            'daily_rate' => $r['daily_rate'],
            'damage_charge' => $r['damage_charge'] ?? 0,
            'deposit_deducted' => $r['deposit_deducted'] ?? 0,
            'notes' => $r['notes'],
        ];
    }
} catch (Exception $e) {}

// 5. Standalone credit transactions (not linked to order AND not part of a group)
try {
    $ct_all = $pdo->prepare("SELECT * FROM credit_transactions WHERE customer_id = ? AND refill_order_id IS NULL AND ledger_group_id IS NULL ORDER BY transaction_date DESC");
    $ct_all->execute([$id]);
    foreach ($ct_all as $ct) {
        $ledger[] = [
            'date' => $ct['transaction_date'],
            'type' => 'credit_tx',
            'ct_type' => $ct['transaction_type'],
            'amount' => $ct['amount'],
            'balance_after' => $ct['balance_after'],
            'description' => $ct['description'],
            'due_date' => $ct['due_date'],
            'refill_order_id' => $ct['refill_order_id'],
        ];
    }
} catch (Exception $e) {}

// Sort ledger chronologically
usort($ledger, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});
?>

<?php
// Compute lifetime stats from ledger
$total_orders = 0;
$lifetime_sales = 0;
foreach ($ledger as $item) {
    if ($item['type'] === 'order') {
        $total_orders++;
        $lifetime_sales += $item['grand_total'];
    }
}
$total_our_cylinders = count($active_cylinders);
$total_customer_cylinders = count($consumer_self_held) + count($customer_owned_cylinders);

// Output GST summary
$gst_output_total = 0;
$gst_output_count = 0;
try {
    $ogst = $pdo->prepare("SELECT COALESCE(SUM(gst_amount), 0) AS total, COUNT(*) AS cnt FROM gst_ledger WHERE entity_type = 'customer' AND entity_id = ? AND input_output_type = 'output'");
    $ogst->execute([$id]);
    $ogst_r = $ogst->fetch();
    $gst_output_total = floatval($ogst_r['total'] ?? 0);
    $gst_output_count = intval($ogst_r['cnt'] ?? 0);
} catch (\Exception $e) {}
$credit_used = floatval($customer['credit_used'] ?? 0);
$credit_limit = floatval($customer['credit_limit'] ?? 0);
$credit_color = 'var(--admin-muted)';
if ($credit_used > 0) {
    if ($credit_limit > 0) {
        $ratio = $credit_used / $credit_limit;
        $credit_color = $ratio >= 1 ? 'var(--danger)' : ($ratio >= 0.7 ? '#d97706' : 'var(--danger)');
    } else {
        $credit_color = 'var(--danger)';
    }
}
?>

<!-- === Compact Identity Bar === -->
<div class="admin-card" style="padding:1rem 1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
            <a href="customers.php" style="color:var(--admin-muted);display:flex;align-items:center;gap:0.3rem;font-size:0.8rem;font-weight:700;text-decoration:none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                Back
            </a>
            <span style="width:1px;height:20px;background:var(--admin-border);display:inline-block;"></span>
            <span style="font-weight:800;font-size:1.1rem;color:var(--admin-muted);">#CUST-<?php echo str_pad($customer['id'], 4, '0', STR_PAD_LEFT); ?></span>
            <span class="badge <?php echo $customer['customer_type'] === 'rental' ? 'badge-rental' : 'badge-refill'; ?>" style="font-size:0.65rem;"><?php echo $customer['customer_type'] === 'rental' ? 'Rental & Deposit' : 'Refill-Only'; ?></span>
            <span style="font-weight:800;font-size:1.15rem;color:var(--admin-fg);"><?php echo htmlspecialchars($customer['name']); ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <button class="btn-secondary" onclick="openModal('trackCylinderModal')" style="display:flex;align-items:center;gap:4px;padding:0.4rem 0.8rem;font-size:0.75rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Track
            </button>
            <button class="btn-primary" onclick="openModal('ledgerActionModal')" style="padding:0.4rem 0.8rem;font-size:0.75rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Payment
            </button>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;margin-top:0.5rem;font-size:0.8rem;color:var(--admin-muted);">
        <span>📞 <?php echo htmlspecialchars($customer['mobile']); ?></span>
        <?php if ($customer['gst_number']): ?><span style="font-family:monospace;font-weight:600;">GST: <?php echo htmlspecialchars($customer['gst_number']); ?></span><?php endif; ?>
        <?php if ($customer['address']): ?><span>📍 <?php echo htmlspecialchars(mb_substr($customer['address'], 0, 60)); ?></span><?php endif; ?>
    </div>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--admin-border);">
        <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.75rem;font-weight:600;color:var(--admin-accent);">With Us: <strong style="font-size:0.95rem;"><?php echo $total_our_cylinders; ?></strong></span>
        <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.75rem;font-weight:600;color:var(--info);">Owner: <strong style="font-size:0.95rem;"><?php echo $total_customer_cylinders; ?></strong></span>
        <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.75rem;font-weight:600;color:var(--success);">Deposit: <strong style="font-size:0.95rem;">₹<?php echo number_format($customer['deposit_balance'], 2); ?></strong></span>
        <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.75rem;font-weight:600;color:<?php echo $credit_color; ?>;">Dues: <strong style="font-size:0.95rem;">₹<?php echo number_format($credit_used, 2); ?><?php if ($credit_limit > 0): ?> / ₹<?php echo number_format($credit_limit, 0); ?><?php endif; ?></strong></span>
        <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.75rem;font-weight:600;color:var(--info);">Orders: <strong style="font-size:0.95rem;"><?php echo $total_orders; ?></strong></span>
        <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.75rem;font-weight:600;color:var(--warning);">Sales: <strong style="font-size:0.95rem;">₹<?php echo number_format($lifetime_sales, 0); ?></strong></span>
        <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.75rem;font-weight:600;color:var(--admin-accent);">Output GST: <strong style="font-size:0.95rem;">₹<?php echo number_format($gst_output_total, 2); ?></strong></span>
    </div>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:0.5rem;padding-top:0.5rem;border-top:1px solid var(--admin-border);font-size:0.8rem;align-items:center;">
        <span style="display:flex;align-items:center;gap:0.4rem;">
            Email: <strong><?php echo htmlspecialchars($customer['email'] ?? '—'); ?></strong>
        </span>
        <span style="display:flex;align-items:center;gap:0.4rem;">
            Portal Access:
            <?php if ($customer['login_enabled']): ?>
                <span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:4px;font-weight:700;font-size:0.7rem;">ENABLED</span>
            <?php else: ?>
                <span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:4px;font-weight:700;font-size:0.7rem;">DISABLED</span>
            <?php endif; ?>
        </span>
        <span style="display:flex;align-items:center;gap:0.4rem;">
            Last Login: <strong><?php echo $customer['last_login'] ? date('d M Y, h:i A', strtotime($customer['last_login'])) : 'Never'; ?></strong>
        </span>
        <button class="btn-secondary" onclick="openModal('portalAccessModal')" style="padding:0.25rem 0.6rem;font-size:0.7rem;border-radius:6px;">Manage Portal Access</button>
    </div>
</div>

<!-- Modal: Portal Access Management -->
<div class="modal" id="portalAccessModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3>Portal Access</h3>
            <button class="modal-close" onclick="closeModal('portalAccessModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="portal_access">
            <input type="hidden" name="customer_id" value="<?php echo $id; ?>">

            <div class="form-group">
                <label class="form-label">Customer Email (used as login)</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>" placeholder="customer@email.com">
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label">Portal Login</label>
                <select name="login_enabled" class="form-control">
                    <option value="1" <?php echo $customer['login_enabled'] ? 'selected' : ''; ?>>Enabled</option>
                    <option value="0" <?php echo !$customer['login_enabled'] ? 'selected' : ''; ?>>Disabled</option>
                </select>
            </div>

            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label">Set New Password</label>
                <input type="password" name="new_password" class="form-control" minlength="6" placeholder="Leave blank to keep current password" autocomplete="new-password">
                <small style="color:var(--admin-muted);font-size:0.75rem;">Min 6 characters. Only fill this if you want to reset the password.</small>
            </div>

            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;">Save Portal Settings</button>
        </form>
    </div>
</div>

<?php
// Handle Portal Access form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'portal_access') {
    $cid = intval($_POST['customer_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $login_enabled = intval($_POST['login_enabled'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';

    if ($cid <= 0) {
        echo "<script>window.location.href='customer-profile.php?id=$id&error=1';</script>";
        exit();
    }

    try {
        if (!empty($new_password)) {
            $hash = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE customers SET email = ?, login_enabled = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$email ?: null, $login_enabled, $hash, $cid]);
        } else {
            $stmt = $pdo->prepare("UPDATE customers SET email = ?, login_enabled = ? WHERE id = ?");
            $stmt->execute([$email ?: null, $login_enabled, $cid]);
        }
        echo "<script>window.location.href='customer-profile.php?id=$cid&success=1';</script>";
        exit();
    } catch (PDOException $e) {
        echo "<script>window.location.href='customer-profile.php?id=$cid&error=1';</script>";
        exit();
    }
}
?>

<?php if (!empty($error)): ?>
    <div class="alert-banner" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5; margin-top:1rem;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- === Cylinders (Tabbed) === -->
<div class="admin-card" style="margin:1rem 0;">
    <div class="card-title" style="margin-bottom:0;padding-bottom:0;border:none;">
        <?php $total_all_cylinders = $total_our_cylinders + count($customer_owned_cylinders) + count($consumer_self_held); ?>
        <div style="display:flex;gap:0;font-size:0.8rem;font-weight:700;">
            <span id="tabAllBtn" class="cyl-tab active" onclick="switchCylTab('tab-all')" style="padding:0.5rem 1rem;cursor:pointer;border-bottom:2px solid var(--admin-accent);color:var(--admin-accent);">All (<?php echo $total_all_cylinders; ?>)</span>
            <span id="tabCylBtn" class="cyl-tab" onclick="switchCylTab('tab-cylinders')" style="padding:0.5rem 1rem;cursor:pointer;border-bottom:2px solid transparent;color:var(--admin-muted);">📦 With Customer (<?php echo $total_our_cylinders; ?>)</span>
            <span id="tabInvBtn" class="cyl-tab" onclick="switchCylTab('tab-inventory')" style="padding:0.5rem 1rem;cursor:pointer;border-bottom:2px solid transparent;color:var(--admin-muted);">🏭 In Inventory (<?php echo count($customer_owned_cylinders); ?>)</span>
            <span id="tabOwnBtn" class="cyl-tab" onclick="switchCylTab('tab-owned')" style="padding:0.5rem 1rem;cursor:pointer;border-bottom:2px solid transparent;color:var(--admin-muted);">👤 Owned (<?php echo count($consumer_self_held); ?>)</span>
        </div>
    </div>

    <!-- Tab: All -->
    <div id="tab-all" class="cyl-tab-content">
        <div style="max-height:300px;overflow-y:auto;border:1px solid var(--admin-border);border-radius:10px;margin-top:0.75rem;">
            <table class="admin-table" style="font-size:0.82rem;">
                <thead><tr>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">#</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Serial</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Gas / Size</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Location</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Rent / Status</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;text-align:right;">Action</th>
                </tr></thead>
                <tbody>
                    <?php
                    $all_cylinders = [];
                    foreach ($active_cylinders as $c) {
                        $c['_location'] = 'With Customer';
                        $c['_loc_color'] = '#d1fae5;#065f46';
                        $all_cylinders[] = $c;
                    }
                    foreach ($customer_owned_cylinders as $c) {
                        $c['_location'] = 'In Inventory';
                        $c['_loc_color'] = '#fee2e2;#b91c1c';
                        $all_cylinders[] = $c;
                    }
                    foreach ($consumer_self_held as $c) {
                        $c['_location'] = 'Owned (Self)';
                        $c['_loc_color'] = '#dbeafe;#1e40af';
                        $all_cylinders[] = $c;
                    }
                    $idx = 0;
                    foreach ($all_cylinders as $cylinder):
                        $idx++;
                        $is_rental = floatval($cylinder['daily_rent_rate'] ?? 0) > 0 && !empty($cylinder['borrow_date']);
                        $tag_s = ''; $tag_l = ''; $tag_t = '';
                        if ($cylinder['ownership_type'] === 'partner_owned') { $tag_s = 'background:#fef3c7;color:#92400e;border:1px solid rgba(251,191,36,0.3);'; $tag_l = 'BR'; $tag_t = ' title="Partner: '.htmlspecialchars($cylinder['partner_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'consumer_owned') { $tag_s = 'background:#dbeafe;color:#1e40af;border:1px solid rgba(59,130,246,0.3);'; $tag_l = 'CON'; $tag_t = ' title="Belongs to: '.htmlspecialchars($cylinder['owner_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'vendor_owned') { $tag_s = 'background:#e8d5f5;color:#6b21a8;border:1px solid rgba(147,51,234,0.3);'; $tag_l = 'VEN'; $tag_t = ' title="Vendor: '.htmlspecialchars($cylinder['vendor_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'owned') { $tag_s = 'background:#d1fae5;color:#065f46;border:1px solid rgba(16,185,129,0.3);'; $tag_l = 'OWN'; }
                        $loc_parts = explode(';', $cylinder['_loc_color']);
                    ?>
                    <tr>
                        <td style="padding:0.4rem 0.65rem;font-weight:700;color:var(--admin-muted);"><?php echo $idx; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-weight:700;color:var(--admin-accent);white-space:nowrap;"><?php echo htmlspecialchars($cylinder['serial_number']); if ($tag_l): ?>&nbsp;<span style="<?php echo $tag_s; ?>padding:1px 5px;border-radius:3px;font-size:0.6rem;font-weight:800;vertical-align:middle;"<?php echo $tag_t; ?>><?php echo $tag_l; ?></span><?php endif; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-size:0.8rem;"><?php echo htmlspecialchars($cylinder['gas_name']) . " (" . htmlspecialchars($cylinder['size_capacity']) . ")"; ?></td>
                        <td style="padding:0.4rem 0.65rem;"><span style="background:<?php echo $loc_parts[0]; ?>color:<?php echo $loc_parts[1]; ?>;padding:2px 7px;border-radius:4px;font-size:0.62rem;font-weight:700;"><?php echo $cylinder['_location']; ?></span></td>
                        <td style="padding:0.4rem 0.65rem;font-weight:600;"><?php if ($is_rental): ?>₹<?php echo $cylinder['daily_rent_rate']; ?>/day<?php else: ?><span style="color:var(--admin-muted);font-size:0.75rem;">—</span><?php endif; ?></td>
                        <td style="padding:0.4rem 0.65rem;text-align:right;white-space:nowrap;">
                            <?php if ($is_rental): ?>
                                <button class="btn-primary" style="padding:0.25rem 0.55rem;font-size:0.65rem;border-radius:6px;" onclick="openRentalReturnModal(<?php echo htmlspecialchars(json_encode([ 'id' => $cylinder['id'], 'serial' => $cylinder['serial_number'], 'gas' => $cylinder['gas_name'] . ' (' . $cylinder['size_capacity'] . ')', 'ownership' => $cylinder['ownership_type'], 'ownerName' => ($cylinder['owner_name'] ?? ''), 'partnerName' => ($cylinder['partner_name'] ?? ''), 'vendorName' => ($cylinder['vendor_name'] ?? ''), 'borrow_date' => $cylinder['borrow_date'], 'daily_rate' => $cylinder['daily_rent_rate'], 'free_days' => $cylinder['free_days'], 'deposit' => $customer['deposit_balance'], 'refill_order_item_id' => $cylinder['refill_order_item_id'] ])); ?>); return false;">Return & Settle</button>
                            <?php elseif ($cylinder['ownership_type'] === 'consumer_owned' && $cylinder['_location'] !== 'Owned (Self)'): ?>
                                <button class="btn-secondary" style="padding:0.25rem 0.55rem;font-size:0.65rem;border-radius:6px;" onclick="openReturnCylinderModal(<?php echo $cylinder['id']; ?>, '<?php echo htmlspecialchars($cylinder['serial_number']); ?>')">Return</button>
                            <?php else: ?>
                                <span style="color:var(--admin-muted);font-size:0.7rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($all_cylinders)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.8rem;">No cylinders found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tab: With Customer -->
    <div id="tab-cylinders" class="cyl-tab-content" style="display:none;">
        <div style="max-height:260px;overflow-y:auto;border:1px solid var(--admin-border);border-radius:10px;margin-top:0.75rem;">
            <table class="admin-table" style="font-size:0.82rem;">
                <thead><tr>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">#</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Serial</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Gas / Size</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Rent</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;text-align:right;">Action</th>
                </tr></thead>
                <tbody>
                    <?php $idx = 0; foreach ($active_cylinders as $cylinder): $idx++;
                        $is_rental = floatval($cylinder['daily_rent_rate'] ?? 0) > 0 && $cylinder['borrow_date'];
                        $tag_style = ''; $tag_label = ''; $tag_title = '';
                        if ($cylinder['ownership_type'] === 'partner_owned') { $tag_style = 'background:#fef3c7;color:#92400e;border:1px solid rgba(251,191,36,0.3);'; $tag_label = 'BR'; $tag_title = ' title="Partner: '.htmlspecialchars($cylinder['partner_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'consumer_owned') { $tag_style = 'background:#dbeafe;color:#1e40af;border:1px solid rgba(59,130,246,0.3);'; $tag_label = 'CON'; $tag_title = ' title="Belongs to: '.htmlspecialchars($cylinder['owner_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'vendor_owned') { $tag_style = 'background:#e8d5f5;color:#6b21a8;border:1px solid rgba(147,51,234,0.3);'; $tag_label = 'VEN'; $tag_title = ' title="Vendor: '.htmlspecialchars($cylinder['vendor_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'owned') { $tag_style = 'background:#d1fae5;color:#065f46;border:1px solid rgba(16,185,129,0.3);'; $tag_label = 'OWN'; }
                    ?>
                    <tr>
                        <td style="padding:0.4rem 0.65rem;font-weight:700;color:var(--admin-muted);"><?php echo $idx; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-weight:700;color:var(--admin-accent);white-space:nowrap;"><?php echo htmlspecialchars($cylinder['serial_number']); ?><?php if ($tag_label): ?>&nbsp;<span style="<?php echo $tag_style; ?>padding:1px 5px;border-radius:3px;font-size:0.6rem;font-weight:800;vertical-align:middle;"<?php echo $tag_title; ?>><?php echo $tag_label; ?></span><?php endif; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-size:0.8rem;"><?php echo htmlspecialchars($cylinder['gas_name']) . " (" . htmlspecialchars($cylinder['size_capacity']) . ")"; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-weight:600;"><?php if ($is_rental): ?>₹<?php echo $cylinder['daily_rent_rate']; ?>/day<?php else: ?><span style="color:var(--admin-muted);font-size:0.75rem;">—</span><?php endif; ?></td>
                        <td style="padding:0.4rem 0.65rem;text-align:right;white-space:nowrap;">
                            <?php if ($is_rental): ?>
                                <button class="btn-primary" style="padding:0.25rem 0.55rem;font-size:0.65rem;border-radius:6px;" onclick="openRentalReturnModal(<?php echo htmlspecialchars(json_encode([ 'id' => $cylinder['id'], 'serial' => $cylinder['serial_number'], 'gas' => $cylinder['gas_name'] . ' (' . $cylinder['size_capacity'] . ')', 'ownership' => $cylinder['ownership_type'], 'ownerName' => ($cylinder['owner_name'] ?? ''), 'partnerName' => ($cylinder['partner_name'] ?? ''), 'vendorName' => ($cylinder['vendor_name'] ?? ''), 'borrow_date' => $cylinder['borrow_date'], 'daily_rate' => $cylinder['daily_rent_rate'], 'free_days' => $cylinder['free_days'], 'deposit' => $customer['deposit_balance'], 'refill_order_item_id' => $cylinder['refill_order_item_id'] ])); ?>); return false;">Return & Settle</button>
                            <?php elseif ($cylinder['ownership_type'] === 'consumer_owned'): ?>
                                <button class="btn-secondary" style="padding:0.25rem 0.55rem;font-size:0.65rem;border-radius:6px;" onclick="openReturnCylinderModal(<?php echo $cylinder['id']; ?>, '<?php echo htmlspecialchars($cylinder['serial_number']); ?>')">Return</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($active_cylinders)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.8rem;">No cylinders with this customer.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tab: In Inventory -->
    <div id="tab-inventory" class="cyl-tab-content" style="display:none;">
        <div style="max-height:260px;overflow-y:auto;border:1px solid var(--admin-border);border-radius:10px;margin-top:0.75rem;">
            <table class="admin-table" style="font-size:0.82rem;">
                <thead><tr>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">#</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Serial</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Gas / Size</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Status</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;text-align:right;">Action</th>
                </tr></thead>
                <tbody>
                    <?php $idx = 0; foreach ($customer_owned_cylinders as $cylinder): $idx++;
                        $tag_style2 = ''; $tag_label2 = ''; $tag_title2 = '';
                        if ($cylinder['ownership_type'] === 'partner_owned') { $tag_style2 = 'background:#fef3c7;color:#92400e;border:1px solid rgba(251,191,36,0.3);'; $tag_label2 = 'BR'; $tag_title2 = ' title="Partner: '.htmlspecialchars($cylinder['partner_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'consumer_owned') { $tag_style2 = 'background:#dbeafe;color:#1e40af;border:1px solid rgba(59,130,246,0.3);'; $tag_label2 = 'CON'; $tag_title2 = ' title="Belongs to: '.htmlspecialchars($cylinder['owner_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'vendor_owned') { $tag_style2 = 'background:#e8d5f5;color:#6b21a8;border:1px solid rgba(147,51,234,0.3);'; $tag_label2 = 'VEN'; $tag_title2 = ' title="Vendor: '.htmlspecialchars($cylinder['vendor_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'owned') { $tag_style2 = 'background:#d1fae5;color:#065f46;border:1px solid rgba(16,185,129,0.3);'; $tag_label2 = 'OWN'; }
                    ?>
                    <tr>
                        <td style="padding:0.4rem 0.65rem;font-weight:700;color:var(--admin-muted);"><?php echo $idx; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-weight:700;color:var(--admin-accent);white-space:nowrap;"><?php echo htmlspecialchars($cylinder['serial_number']); ?><?php if ($tag_label2): ?>&nbsp;<span style="<?php echo $tag_style2; ?>padding:1px 5px;border-radius:3px;font-size:0.6rem;font-weight:800;vertical-align:middle;"<?php echo $tag_title2; ?>><?php echo $tag_label2; ?></span><?php endif; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-size:0.8rem;"><?php echo htmlspecialchars($cylinder['gas_name']) . " (" . htmlspecialchars($cylinder['size_capacity']) . ")"; ?></td>
                        <td style="padding:0.4rem 0.65rem;"><span style="background:<?php echo $cylinder['status'] === 'empty' ? '#fee2e2;color:#b91c1c' : '#d1fae5;color:#065f46'; ?>;padding:2px 7px;border-radius:4px;font-size:0.62rem;font-weight:700;"><?php echo $cylinder['status'] === 'empty' ? 'Empty' : 'Filled'; ?></span></td>
                        <td style="padding:0.4rem 0.65rem;text-align:right;">
                            <button class="btn-secondary" style="padding:0.25rem 0.55rem;font-size:0.65rem;border-radius:6px;" onclick="openReturnCylinderModal(<?php echo $cylinder['id']; ?>, '<?php echo htmlspecialchars($cylinder['serial_number']); ?>')">Return</button>
                        </td>
                    </tr>
                    <?php endforeach; if (empty($customer_owned_cylinders)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.8rem;">No customer cylinders in inventory.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tab: Owned by Customer (Self-Held) -->
    <div id="tab-owned" class="cyl-tab-content" style="display:none;">
        <div style="max-height:260px;overflow-y:auto;border:1px solid var(--admin-border);border-radius:10px;margin-top:0.75rem;">
            <table class="admin-table" style="font-size:0.82rem;">
                <thead><tr>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">#</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Serial</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Gas / Size</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;">Status</th>
                    <th style="padding:0.5rem 0.65rem;position:sticky;top:0;background:#fafafb;z-index:2;text-align:right;">Action</th>
                </tr></thead>
                <tbody>
                    <?php $idx = 0; foreach ($consumer_self_held as $cylinder): $idx++;
                        $tag_style3 = ''; $tag_label3 = ''; $tag_title3 = '';
                        if ($cylinder['ownership_type'] === 'partner_owned') { $tag_style3 = 'background:#fef3c7;color:#92400e;border:1px solid rgba(251,191,36,0.3);'; $tag_label3 = 'BR'; $tag_title3 = ' title="Partner: '.htmlspecialchars($cylinder['partner_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'consumer_owned') { $tag_style3 = 'background:#dbeafe;color:#1e40af;border:1px solid rgba(59,130,246,0.3);'; $tag_label3 = 'CON'; $tag_title3 = ' title="Belongs to: '.htmlspecialchars($cylinder['owner_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'vendor_owned') { $tag_style3 = 'background:#e8d5f5;color:#6b21a8;border:1px solid rgba(147,51,234,0.3);'; $tag_label3 = 'VEN'; $tag_title3 = ' title="Vendor: '.htmlspecialchars($cylinder['vendor_name'] ?: 'Unknown').'"'; }
                        elseif ($cylinder['ownership_type'] === 'owned') { $tag_style3 = 'background:#d1fae5;color:#065f46;border:1px solid rgba(16,185,129,0.3);'; $tag_label3 = 'OWN'; }
                    ?>
                    <tr>
                        <td style="padding:0.4rem 0.65rem;font-weight:700;color:var(--admin-muted);"><?php echo $idx; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-weight:700;color:var(--admin-accent);white-space:nowrap;"><?php echo htmlspecialchars($cylinder['serial_number']); ?><?php if ($tag_label3): ?>&nbsp;<span style="<?php echo $tag_style3; ?>padding:1px 5px;border-radius:3px;font-size:0.6rem;font-weight:800;vertical-align:middle;"<?php echo $tag_title3; ?>><?php echo $tag_label3; ?></span><?php endif; ?></td>
                        <td style="padding:0.4rem 0.65rem;font-size:0.8rem;"><?php echo htmlspecialchars($cylinder['gas_name']) . " (" . htmlspecialchars($cylinder['size_capacity']) . ")"; ?></td>
                        <td style="padding:0.4rem 0.65rem;"><span style="background:#d1fae5;color:#065f46;padding:2px 7px;border-radius:4px;font-size:0.62rem;font-weight:700;">Active</span></td>
                        <td style="padding:0.4rem 0.65rem;text-align:right;"><span style="color:var(--admin-muted);font-size:0.7rem;">—</span></td>
                    </tr>
                    <?php endforeach; if (empty($consumer_self_held)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:2rem 0;color:var(--admin-muted);font-size:0.8rem;">No cylinders owned by this customer.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- === Transaction Ledger === -->
<div class="admin-card" style="margin-bottom:1rem;">
    <div class="card-title" style="margin-bottom:0;">
        <span>Transaction Ledger <span style="font-weight:400;color:var(--admin-muted);font-size:0.85rem;">· <?php echo count($ledger); ?> entries</span></span>
        <span style="display:flex;align-items:center;gap:0.75rem;">
            <span style="font-size:0.75rem;font-weight:400;color:var(--admin-muted);">(click to expand)</span>
            <span id="filterToggleBtn" onclick="toggleFilter()" style="font-size:0.75rem;font-weight:700;color:var(--admin-accent);cursor:pointer;white-space:nowrap;user-select:none;">▾ Filters</span>
        </span>
    </div>

    <div id="filterPanel" style="display:none;">
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;padding:0.75rem 0;border-bottom:1px solid var(--admin-border);margin-bottom:0.5rem;">
            <input type="date" id="fDateFrom" data-picker="none" aria-label="Filter from date" style="flex:1;min-width:100px;padding:0.3rem 0.4rem;font-size:0.75rem;border:1px solid var(--admin-border);border-radius:6px;">
            <span style="color:var(--admin-muted);font-size:0.75rem;">→</span>
            <input type="date" id="fDateTo" data-picker="none" aria-label="Filter to date" style="flex:1;min-width:100px;padding:0.3rem 0.4rem;font-size:0.75rem;border:1px solid var(--admin-border);border-radius:6px;">
            <select id="fType" aria-label="Filter transaction type" style="flex:1;min-width:90px;padding:0.3rem 0.4rem;font-size:0.75rem;border:1px solid var(--admin-border);border-radius:6px;">
                <option value="">All</option>
                <option value="order">Orders</option>
                <option value="payment_deposit_added">Deposits Added</option>
                <option value="payment_deposit_refunded">Refunds</option>
                <option value="group_payment_received">Payments Received</option>
                <option value="group_credit_order">Credit Orders</option>
                <option value="group_rental_return">Rental Returns</option>
                <option value="group_exchange_settlement">Exchange Settlements</option>
                <option value="credit">Dues/Credit</option>
            </select>
            <input type="text" id="fSearch" placeholder="Search…" aria-label="Search transactions" style="flex:1;min-width:80px;padding:0.3rem 0.4rem;font-size:0.75rem;border:1px solid var(--admin-border);border-radius:6px;">
            <button type="button" onclick="resetFilters()" style="padding:0.3rem 0.6rem;font-size:0.75rem;border:1px solid var(--admin-border);border-radius:6px;background:#f8fafc;cursor:pointer;color:var(--admin-muted);">Clear</button>
        </div>
        <div id="filterChips" style="display:none;margin-bottom:0.5rem;"></div>
    </div>
    <div class="table-wrapper" style="border:none;">
        <table class="admin-table" id="ledgerTable">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th>Date & Time</th>
                    <th>Event</th>
                    <th>Details</th>
                    <th style="text-align:right;">Amount</th>
                    <th style="text-align:right;">Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ledger as $item): ?>
                <?php if ($item['type'] === 'order'): ?>
                <?php
                $cyl_count = count($item['items']);
                $pay_total = 0;
                foreach ($item['payments'] as $p) $pay_total += $p['amount'];
                $due = $item['grand_total'] - $pay_total;

                // Compute order type label dynamically from items
                $has_refill_no_return = false;
                $has_refill_with_return = false;
                $has_rental = false;
                $has_sell = false;
                $has_product = false;
                $has_service = false;
                foreach ($item['items'] as $itm) {
                    if ($itm['is_rental'] == 0) {
                        if (!empty($itm['returned_serial'])) $has_refill_with_return = true;
                        else $has_refill_no_return = true;
                    } elseif ($itm['is_rental'] == 1) $has_rental = true;
                    elseif ($itm['is_rental'] == 2) $has_sell = true;
                    elseif ($itm['is_rental'] == 3) $has_product = true;
                    elseif ($itm['is_rental'] == 4) $has_service = true;
                }
                $type_count = ($has_refill_no_return||$has_refill_with_return?1:0) + ($has_rental?1:0) + ($has_sell?1:0) + ($has_product?1:0) + ($has_service?1:0);
                if ($type_count > 1) {
                    $order_type_label = 'Mixed Order';
                    $order_type_badge = 'badge-in-use';
                } elseif ($has_sell) {
                    $order_type_label = 'Cylinder Sale'; $order_type_badge = 'badge-under-maintenance';
                } elseif ($has_rental) {
                    $order_type_label = 'Rental Issue'; $order_type_badge = 'badge-rental';
                } elseif ($has_refill_no_return && !$has_refill_with_return) {
                    $order_type_label = 'Cylinder Issue'; $order_type_badge = 'badge-refill';
                } elseif ($has_refill_with_return && !$has_refill_no_return) {
                    $order_type_label = 'Cylinder Exchange'; $order_type_badge = 'badge-refill';
                } elseif ($has_refill_no_return && $has_refill_with_return) {
                    $order_type_label = 'Mixed Refill'; $order_type_badge = 'badge-refill';
                } elseif ($has_product) {
                    $order_type_label = 'Product Sale'; $order_type_badge = 'badge-refill';
                } elseif ($has_service) {
                    $order_type_label = 'Refill Service'; $order_type_badge = 'badge-service';
                } else {
                    $order_type_label = 'Refill Order'; $order_type_badge = 'badge-refill';
                }
                ?>
                <tr class="ledger-row ledger-order" data-date="<?php echo date('Y-m-d', strtotime($item['date'])); ?>" data-datetime="<?php echo date('Y-m-d\TH:i:s', strtotime($item['date'])); ?>" data-type="order" data-details-id="details_<?php echo $item['order_id']; ?>" onclick="toggleLedgerDetails(<?php echo $item['order_id']; ?>)">
                    <td data-label=""><span class="ledger-toggle" id="toggle_<?php echo $item['order_id']; ?>">▶</span></td>
                    <td data-label="Date & Time" style="font-size:0.85rem;color:var(--admin-muted);font-weight:600;white-space:nowrap;">
                        <?php echo date('M d, Y h:i A', strtotime($item['date'])); ?>
                    </td>
                    <td data-label="Event">
                        <span class="badge <?php echo $order_type_badge; ?>" style="font-size:0.65rem;padding:3px 8px;"><?php echo $order_type_label; ?></span>
                    </td>
                    <td data-label="Details" style="font-size:0.9rem;font-weight:600;">
                        <strong>#ORD-<?php echo str_pad($item['order_id'], 4, '0', STR_PAD_LEFT); ?></strong>
                        <span style="font-weight:400;color:var(--admin-muted);"> · <?php echo $cyl_count; ?> item<?php echo $cyl_count !== 1 ? 's' : ''; ?></span>
                        <?php if ($item['deposit_amount'] > 0): ?>
                            <span style="font-weight:600;color:var(--info);font-size:0.8rem;"> · ₹<?php echo number_format($item['deposit_amount'], 2); ?> deposit</span>
                        <?php endif; ?>
                        <?php if ($due > 0): ?>
                            <span style="color:var(--danger);font-size:0.8rem;font-weight:700;"> · Due: ₹<?php echo number_format($due, 2); ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Amount" style="text-align:right;font-weight:800;font-size:0.95rem;color:<?php echo $due > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                        ₹<?php echo number_format($item['grand_total'], 2); ?>
                    </td>
                    <td data-label="Receipt" style="text-align:right;">
                        <a href="invoice.php?order_id=<?php echo $item['order_id']; ?>" class="btn-secondary" style="padding:0.35rem 0.65rem;font-size:0.7rem;border-radius:6px;" onclick="event.stopPropagation();">Invoice</a>
                    </td>
                </tr>
                <tr class="ledger-details-row" id="details_<?php echo $item['order_id']; ?>">
                    <td colspan="6" style="padding:0;">
                        <div class="ledger-details-content">
                            <!-- Order Summary Header -->
                            <div class="ld-header">
                                <div class="ld-header-left">
                                    <span class="ld-order-id">#ORD-<?php echo str_pad($item['order_id'], 4, '0', STR_PAD_LEFT); ?></span>
                                    <?php if ($item['invoice_number']): ?>
                                        <span class="ld-invoice">Invoice: <?php echo htmlspecialchars($item['invoice_number']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ld-header-right">
                                    <?php
                                        $status_class = '';
                                        $status_label = '';
                                        if ($item['payment_status'] === 'paid') { $status_class = 'ld-status-paid'; $status_label = 'Paid'; }
                                        elseif ($item['payment_status'] === 'partial') { $status_class = 'ld-status-partial'; $status_label = 'Partial'; }
                                        else { $status_class = 'ld-status-pending'; $status_label = 'Pending'; }
                                    ?>
                                    <span class="ld-status <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                                    <?php if ($item['deposit_amount'] > 0): ?>
                                        <span class="ld-deposit">Deposit: ₹<?php echo number_format($item['deposit_amount'], 2); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Body -->
                            <div class="ld-body">
                                <?php if (!empty($item['items'])): ?>
                                <div class="ld-section">
                                    <div class="ld-section-title">Cylinder Movement Narrative</div>
                                    <?php foreach ($item['items'] as $itm): ?>
                                    <div style="background:#f8fafc;border:1px solid var(--admin-border);border-radius:10px;padding:0.75rem 1rem;margin-bottom:0.5rem;">
                                        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.4rem;">
                                            <span style="font-weight:700;font-size:0.85rem;"><?php echo htmlspecialchars($itm['gas_name'] ?? $itm['product_name'] ?? 'Unknown'); ?></span>
                                            <?php if ($itm['is_rental'] != 3): ?>
                                            <span style="font-size:0.7rem;color:var(--admin-muted);"><?php echo htmlspecialchars($itm['size_capacity']); ?></span>
                                            <?php endif; ?>
                                            <span class="badge <?php echo $itm['is_rental'] == 2 ? 'badge-under-maintenance' : ($itm['is_rental'] ? 'badge-rental' : 'badge-refill'); ?>" style="font-size:0.6rem;margin-left:auto;">
                                                <?php echo $itm['is_rental'] == 2 ? 'Sold' : ($itm['is_rental'] == 1 ? 'Rental' : ($itm['is_rental'] == 3 ? 'Product' : ($itm['is_rental'] == 4 ? 'Service' : 'Refill'))); ?>
                                            </span>
                                        </div>
                                        <?php if ($itm['is_rental'] == 3): ?>
                                            <div style="font-size:0.8rem;">📦 <strong>Product:</strong> <?php echo htmlspecialchars($itm['product_name'] ?? ''); ?> × <?php echo intval($itm['qty']); ?> @ ₹<?php echo number_format($itm['price_per_unit'], 2); ?></div>
                                        <?php elseif ($itm['is_rental'] == 2): ?>
                                            <div style="font-size:0.8rem;">🔴 <strong>Sold:</strong> #<?php echo htmlspecialchars($itm['sold_cylinder_serial'] ?? ''); ?> — permanently removed from system · ₹<?php echo number_format($itm['price_per_unit'], 2); ?></div>
                                        <?php else: ?>
                                            <div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-size:0.8rem;">
                                                <div>
                                                    <?php if ($itm['issued_serial']): ?>
                                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;background:#d1fae5;border-radius:6px;font-weight:700;">
                                                        → Gave: <?php echo htmlspecialchars($itm['issued_serial']); ?>
                                                        <?php $ot = $itm['issued_ownership_type'] ?? ''; 
                                                        if ($ot === 'partner_owned'): ?><span class="ot-badge ot-br">BR</span>
                                                        <?php elseif ($ot === 'consumer_owned'): ?><span class="ot-badge ot-con">CON</span>
                                                        <?php elseif ($ot === 'vendor_owned'): ?><span class="ot-badge ot-ven">VEN</span>
                                                        <?php elseif ($ot === 'owned'): ?><span class="ot-badge ot-own">OWN</span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php else: ?>
                                                    <span style="color:var(--admin-muted);font-style:italic;">(nothing issued)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <?php if ($itm['returned_serial']): ?>
                                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;background:#fef3c7;border-radius:6px;font-weight:700;">
                                                        ← Got: <?php echo htmlspecialchars($itm['returned_serial']); ?>
                                                        <?php $ot = $itm['returned_ownership_type'] ?? ''; 
                                                        if ($ot === 'partner_owned'): ?><span class="ot-badge ot-br">BR</span>
                                                        <?php elseif ($ot === 'consumer_owned'): ?>
                                                            <span class="ot-badge ot-con" title="Belongs to: <?php echo htmlspecialchars($itm['returned_owner_name'] ?? 'Unknown'); ?>">CON</span>
                                                            <?php if ($itm['returned_owner_name'] && stripos($itm['notes'] ?? '', 'settled') !== false): ?>
                                                                <span style="font-size:0.65rem;color:#065f46;font-weight:700;">✓ SETTLED</span>
                                                            <?php endif; ?>
                                                        <?php elseif ($ot === 'vendor_owned'): ?><span class="ot-badge ot-ven">VEN</span>
                                                        <?php elseif ($ot === 'owned'): ?><span class="ot-badge ot-own">OWN</span>
                                                        <?php endif; ?>
                                                        <?php if (floatval($itm['damage_amount'] ?? 0) > 0): ?>
                                                            <span style="color:#dc2626;font-size:0.7rem;">⚠ ₹<?php echo number_format($itm['damage_amount'], 2); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php else: ?>
                                                    <span style="color:var(--admin-muted);font-style:italic;">(nothing returned)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div style="margin-top:0.3rem;font-size:0.75rem;color:var(--admin-muted);">
                                            <span>₹<?php echo number_format($itm['price_per_unit'], 2); ?>/unit</span>
                                            <?php if ($itm['is_rental'] == 1 && floatval($itm['rent_per_day'] ?? 0) > 0): ?>
                                                · Rent: ₹<?php echo number_format($itm['rent_per_day'], 2); ?>/day · <?php echo intval($itm['free_days'] ?? 0); ?> free days
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="ld-section">
                                    <div class="ld-section-title">Cylinder Exchange Details</div>
                                    <div class="ld-table-wrap">
                                        <table class="ld-table">
                                            <thead><tr><th>#</th><th>Gas / Size</th><th>Given to Customer</th><th>Taken from Customer</th><th style="text-align:right;">Rate</th><th>Type</th></tr></thead>
                                            <tbody>
                                                <?php $idx = 0; ?>
                                                <?php foreach ($item['items'] as $itm): ?>
                                                <?php $idx++; ?>
                                                <tr>
                                                    <td style="font-weight:700;color:var(--admin-muted);"><?php echo $idx; ?></td>
                                                    <td><div style="font-weight:600;"><?php echo $itm['is_rental'] == 3 ? ('📦 ' . htmlspecialchars($itm['product_name'] ?? 'Product')) : htmlspecialchars($itm['gas_name']); ?></div><div style="font-size:0.7rem;color:var(--admin-muted);"><?php echo $itm['is_rental'] == 3 ? ('Qty: ' . intval($itm['qty'])) : htmlspecialchars($itm['size_capacity']); ?></div></td>
                                                    <td><?php 
                                                        if ($itm['is_rental'] == 3):
                                                            echo '<span style="color:var(--admin-muted);">—</span>';
                                                        elseif ($itm['is_rental'] == 2):
                                                            $sold_ser = $itm['sold_cylinder_serial'] ?? '';
                                                            echo '<span style="font-weight:700;color:#dc2626;">' . htmlspecialchars($sold_ser) . ' (SOLD)</span>';
                                                        elseif ($itm['issued_serial']): 
                                                             echo '<span style="font-weight:700;">' . htmlspecialchars($itm['issued_serial']) . '</span>';
                                                             $ot = $itm['issued_ownership_type'] ?? ''; 
                                                             if ($ot === 'partner_owned'): ?><span class="ot-badge ot-br" title="Partner: <?php echo htmlspecialchars($itm['issued_partner_name'] ?? 'Unknown'); ?>">BR</span><?php 
                                                             elseif ($ot === 'consumer_owned'): ?><span class="ot-badge ot-con" title="Belongs to: <?php echo htmlspecialchars($itm['issued_owner_name'] ?? 'Unknown'); ?>">CON</span><?php 
                                                             elseif ($ot === 'vendor_owned'): ?><span class="ot-badge ot-ven" title="Vendor: <?php echo htmlspecialchars($itm['issued_vendor_name'] ?? 'Unknown'); ?>">VEN</span><?php 
                                                             elseif ($ot === 'owned'): ?><span class="ot-badge ot-own">OWN</span><?php 
                                                             endif; 
                                                        else: ?><span style="color:var(--admin-muted);">—</span><?php endif; ?></td>
                                                    <td><?php if ($itm['is_rental'] == 3): ?><span style="color:var(--admin-muted);">—</span><?php elseif ($itm['returned_serial']): ?><span style="font-weight:700;"><?php echo htmlspecialchars($itm['returned_serial']); ?></span><?php $ot = $itm['returned_ownership_type'] ?? ''; if ($ot === 'partner_owned'): ?><span class="ot-badge ot-br" title="Partner: <?php echo htmlspecialchars($itm['returned_partner_name'] ?? 'Unknown'); ?>">BR</span><?php elseif ($ot === 'consumer_owned'): ?><span class="ot-badge ot-con" title="Belongs to: <?php echo htmlspecialchars($itm['returned_owner_name'] ?? 'Unknown'); ?>">CON</span><?php elseif ($ot === 'vendor_owned'): ?><span class="ot-badge ot-ven" title="Vendor: <?php echo htmlspecialchars($itm['returned_vendor_name'] ?? 'Unknown'); ?>">VEN</span><?php elseif ($ot === 'owned'): ?><span class="ot-badge ot-own">OWN</span><?php endif; if (floatval($itm['damage_amount'] ?? 0) > 0): ?><div style="color:#dc2626;font-size:0.68rem;font-weight:700;margin-top:2px;">⚠ Damage: ₹<?php echo number_format($itm['damage_amount'], 2); ?><?php if ($itm['damage_description']): ?> (<?php echo htmlspecialchars($itm['damage_description']); ?>)<?php endif; ?></div><?php endif; ?><?php else: ?><span style="color:var(--admin-muted);">—</span><?php endif; ?></td>
                                                    <td style="text-align:right;font-weight:700;">
                                                        <?php if ($itm['is_rental'] == 3): ?>
                                                            ₹<?php echo number_format($itm['price_per_unit'] * $itm['qty'], 2); ?> <span style="font-size:0.65rem;color:var(--admin-muted);">(<?php echo $itm['qty']; ?> × ₹<?php echo number_format($itm['price_per_unit'], 2); ?>)</span>
                                                        <?php elseif ($itm['is_rental'] == 2): ?>
                                                            ₹<?php echo number_format($itm['price_per_unit'], 2); ?>
                                                            <?php if (floatval($itm['sell_price'] ?? 0) > 0): ?> + Sell ₹<?php echo number_format($itm['sell_price'], 2); ?><?php endif; ?>
                                                        <?php else: ?>
                                                            ₹<?php echo number_format($itm['price_per_unit'], 2); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="badge <?php echo $itm['is_rental'] == 2 ? 'badge-under-maintenance' : ($itm['is_rental'] ? 'badge-rental' : 'badge-refill'); ?>" style="font-size:0.6rem;"><?php echo $itm['is_rental'] == 2 ? 'Sold' : ($itm['is_rental'] ? 'Rental' : 'Refill'); ?></span></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($item['payments'])): ?>
                                <div class="ld-section">
                                    <div class="ld-section-title">Payments</div>
                                    <div class="ld-table-wrap">
                                        <table class="ld-table">
                                            <thead><tr><th>Type</th><th>Method</th><th style="text-align:right;">Amount</th><th>Note</th><th style="text-align:right;">Receipt</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($item['payments'] as $pay): ?>
                                                <tr>
                                                    <td><?php $plabel = 'Refill Payment'; $pbadge = 'badge-refill'; if ($pay['payment_type'] === 'deposit_added') { $plabel = 'Deposit Added'; $pbadge = 'badge-rental'; } elseif ($pay['payment_type'] === 'deposit_refunded') { $plabel = 'Deposit Refund'; $pbadge = 'badge-under-maintenance'; } elseif ($pay['payment_type'] === 'deposit_damage') { $plabel = 'Damage Charge'; $pbadge = 'badge-under-maintenance'; } elseif ($pay['payment_type'] === 'rent_payment') { $plabel = 'Rent Payment'; $pbadge = 'badge-rental'; } ?><span class="badge <?php echo $pbadge; ?>" style="font-size:0.6rem;"><?php echo $plabel; ?></span></td>
                                                    <td style="font-weight:600;"><?php echo htmlspecialchars($pay['payment_method']); ?></td>
                                                    <td style="text-align:right;font-weight:800;color:<?php echo ($pay['payment_type'] === 'deposit_damage') ? '#dc2626' : 'var(--success)'; ?>;"><?php echo ($pay['payment_type'] === 'deposit_damage' ? '-' : '') . '₹' . number_format($pay['amount'], 2); ?></td>
                                                    <td style="font-size:0.75rem;color:var(--admin-muted);"><?php echo htmlspecialchars($pay['notes'] ?: ''); ?></td>
                                                    <td style="text-align:right;"><?php if (!empty($pay['deposit_receipt_id'])): ?><a href="deposit-receipt.php?receipt_id=<?php echo $pay['deposit_receipt_id']; ?>" class="ld-receipt-link">Receipt <?php echo htmlspecialchars($pay['receipt_number'] ?? ''); ?></a><?php else: ?><span style="color:var(--admin-muted);font-size:0.75rem;">—</span><?php endif; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($item['order_credits'])): ?>
                                <div class="ld-section">
                                    <div class="ld-section-title">Credit / Dues</div>
                                    <div class="ld-table-wrap">
                                        <table class="ld-table">
                                            <thead><tr><th>Type</th><th style="text-align:right;">Amount</th><th>Description</th><th>Due Date</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($item['order_credits'] as $oc): ?>
                                                <tr>
                                                    <td><span class="badge <?php echo $oc['transaction_type'] === 'charge' ? 'badge-under-maintenance' : 'badge-refill'; ?>" style="font-size:0.6rem;"><?php echo $oc['transaction_type'] === 'charge' ? 'Dues Charged' : 'Payment'; ?></span></td>
                                                    <td style="text-align:right;font-weight:800;color:<?php echo $oc['transaction_type'] === 'charge' ? 'var(--danger)' : 'var(--success)'; ?>;"><?php echo $oc['transaction_type'] === 'charge' ? '' : '-'; ?>₹<?php echo number_format($oc['amount'], 2); ?></td>
                                                    <td style="font-size:0.75rem;color:var(--admin-muted);"><?php echo htmlspecialchars($oc['description'] ?? ''); ?></td>
                                                    <td style="font-weight:600;"><?php echo $oc['due_date'] ? date('d M Y', strtotime($oc['due_date'])) : '—'; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Footer -->
                            <div class="ld-footer">
                                <div class="ld-footer-left">
                                    <?php if ($item['payment_method']): ?>
                                        <span>Via <strong><?php echo htmlspecialchars($item['payment_method']); ?></strong></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ld-footer-right">
                                    <?php
                                    $ledger_damage = 0;
                                    foreach ($item['items'] as $itm) { $ledger_damage += floatval($itm['damage_amount'] ?? 0); }
                                    ?>
                                    <span>Subtotal: ₹<?php echo number_format($item['subtotal'], 2); ?></span>
                                    <?php if ($item['discount'] > 0): ?><span class="ld-ft-discount">Discount: -₹<?php echo number_format($item['discount'], 2); ?></span><?php endif; ?>
                                    <?php if ($item['tax_amount'] > 0): ?><span>Tax: ₹<?php echo number_format($item['tax_amount'], 2); ?></span><?php endif; ?>
                                    <?php if ($ledger_damage > 0): ?><span style="color:#dc2626;">Damage: ₹<?php echo number_format($ledger_damage, 2); ?></span><?php endif; ?>
                                    <span class="ld-ft-total">Grand Total: ₹<?php echo number_format($item['grand_total'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php elseif ($item['type'] === 'group'): ?>
                <?php
                // Fetch cylinder_transactions for exchange_settlement groups (must be before sub_count)
                $exchange_txns = [];
                if ($item['group_type'] === 'exchange_settlement') {
                    try {
                        $stmt_ex = $pdo->prepare("SELECT ct.*, c.serial_number, g.name as gas_name, c.size_capacity, c.ownership_type FROM cylinder_transactions ct LEFT JOIN cylinders c ON ct.cylinder_id = c.id LEFT JOIN gas_types g ON c.gas_type_id = g.id WHERE ct.ledger_group_id = ? ORDER BY ct.transaction_date ASC");
                        $stmt_ex->execute([$item['group_id']]);
                        $exchange_txns = $stmt_ex->fetchAll();
                    } catch (PDOException $e) {}
                }
                $sub_count = count($item['payments']) + count($item['credit_txns']) + count($item['rental_returns']);
                if ($item['group_type'] === 'exchange_settlement') {
                    $sub_count = count($exchange_txns);
                }
                $group_type_label = 'Transaction';
                $group_badge_class = 'badge-refill';
                if ($item['group_type'] === 'payment_received' || $item['group_type'] === 'deposit_added') {
                    $group_type_label = 'Payment Received';
                    $group_badge_class = 'badge-rental';
                } elseif ($item['group_type'] === 'payment_refunded') {
                    $group_type_label = 'Deposit Refund';
                    $group_badge_class = 'badge-under-maintenance';
                } elseif ($item['group_type'] === 'rental_return') {
                    $group_type_label = 'Rental Return';
                    $group_badge_class = 'badge-rental';
                } elseif ($item['group_type'] === 'credit_order') {
                    $group_type_label = 'Credit Order';
                    $group_badge_class = 'badge-under-maintenance';
                } elseif ($item['group_type'] === 'exchange_settlement') {
                    $group_type_label = 'Exchange Settlement';
                    $group_badge_class = 'badge-rental';
                }
                ?>
                <tr class="ledger-row ledger-group" data-date="<?php echo date('Y-m-d', strtotime($item['date'])); ?>" data-datetime="<?php echo date('Y-m-d\TH:i:s', strtotime($item['date'])); ?>" data-type="group_<?php echo $item['group_type']; ?>" data-details-id="group_details_<?php echo $item['group_id']; ?>" onclick="toggleLedgerDetails('group_<?php echo $item['group_id']; ?>')">
                    <td data-label=""><span class="ledger-toggle" id="toggle_group_<?php echo $item['group_id']; ?>">▶</span></td>
                    <td data-label="Date & Time" style="font-size:0.85rem;color:var(--admin-muted);font-weight:600;white-space:nowrap;">
                        <?php echo date('M d, Y h:i A', strtotime($item['date'])); ?>
                    </td>
                    <td data-label="Event">
                        <span class="badge <?php echo $group_badge_class; ?>" style="font-size:0.65rem;padding:3px 8px;"><?php echo $group_type_label; ?></span>
                    </td>
                    <td data-label="Details" style="font-size:0.9rem;font-weight:600;">
                        <?php echo htmlspecialchars($item['title']); ?>
                        <span style="font-weight:400;color:var(--admin-muted);font-size:0.75rem;"> · <?php echo $sub_count; ?> item<?php echo $sub_count !== 1 ? 's' : ''; ?></span>
                    </td>
                    <td data-label="Amount" style="text-align:right;font-weight:800;font-size:0.95rem;color:<?php echo ($item['group_type'] === 'payment_refunded') ? '#dc2626' : 'var(--success)'; ?>;">
                        <?php echo ($item['group_type'] === 'payment_refunded' ? '-' : '') . '₹' . number_format($item['total_amount'], 2); ?>
                    </td>
                    <td data-label="Receipt" style="text-align:right;">
                        <?php
                        if ($item['group_type'] === 'credit_order' && !empty($item['order_id'])): ?>
                            <a href="invoice.php?order_id=<?php echo $item['order_id']; ?>" class="btn-secondary" style="padding:0.35rem 0.65rem;font-size:0.7rem;border-radius:6px;" onclick="event.stopPropagation();">Invoice</a>
                        <?php
                        elseif ($item['group_type'] === 'rental_return'): ?>
                            <?php if (!empty($item['return_id'])): ?>
                            <a href="rental-invoice.php?return_id=<?php echo $item['return_id']; ?>" class="btn-secondary" style="padding:0.35rem 0.65rem;font-size:0.7rem;border-radius:6px;" onclick="event.stopPropagation();">Invoice</a>
                            <?php endif; ?>
                            <a href="bulk-rental-invoice.php?group_id=<?php echo $item['group_id']; ?>" class="btn-secondary" style="padding:0.35rem 0.65rem;font-size:0.7rem;border-radius:6px;" onclick="event.stopPropagation();">Bulk Invoice</a>
                        <?php
                        else:
                            $first_receipt = null;
                            foreach ($item['payments'] as $gp) {
                                if (!empty($gp['deposit_receipt_id'])) { $first_receipt = $gp; break; }
                            }
                            if ($first_receipt): ?>
                                <a href="deposit-receipt.php?receipt_id=<?php echo $first_receipt['deposit_receipt_id']; ?>" class="btn-secondary" style="padding:0.35rem 0.65rem;font-size:0.7rem;border-radius:6px;" onclick="event.stopPropagation();">Receipt</a>
                            <?php else: ?>
                                <span style="font-size:0.75rem;color:var(--admin-muted);font-weight:600;">—</span>
                            <?php endif;
                        endif; ?>
                    </td>
                </tr>
                <tr class="ledger-details-row" id="details_group_<?php echo $item['group_id']; ?>">
                    <td colspan="6" style="padding:0;">
                        <div class="ledger-details-content">
                            <div class="ld-body">
                                <?php if (!empty($item['payments'])): ?>
                                <div class="ld-section">
                                    <div class="ld-section-title">Payments</div>
                                    <div class="ld-table-wrap">
                                        <table class="ld-table">
                                            <thead><tr><th>Type</th><th>Method</th><th style="text-align:right;">Amount</th><th>Note</th><th style="text-align:right;">Receipt</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($item['payments'] as $gp): ?>
                                                <tr>
                                                    <td><?php $plabel = 'Payment'; $pbadge = 'badge-refill'; if ($gp['payment_type'] === 'deposit_added') { $plabel = 'Deposit Added'; $pbadge = 'badge-rental'; } elseif ($gp['payment_type'] === 'deposit_refunded') { $plabel = 'Deposit Refund'; $pbadge = 'badge-under-maintenance'; } elseif ($gp['payment_type'] === 'deposit_damage') { $plabel = 'Damage Charge'; $pbadge = 'badge-under-maintenance'; } elseif ($gp['payment_type'] === 'refill_payment') { $plabel = 'Dues Payment'; $pbadge = 'badge-refill'; } elseif ($gp['payment_type'] === 'rent_payment') { $plabel = 'Rent Payment'; $pbadge = 'badge-rental'; } ?><span class="badge <?php echo $pbadge; ?>" style="font-size:0.6rem;"><?php echo $plabel; ?></span></td>
                                                    <td style="font-weight:600;"><?php echo htmlspecialchars($gp['payment_method']); ?></td>
                                                    <td style="text-align:right;font-weight:800;color:<?php echo ($gp['payment_type'] === 'deposit_damage') ? '#dc2626' : 'var(--success)'; ?>;"><?php echo ($gp['payment_type'] === 'deposit_damage' ? '-' : '') . '₹' . number_format($gp['amount'], 2); ?></td>
                                                    <td style="font-size:0.75rem;color:var(--admin-muted);"><?php echo htmlspecialchars($gp['notes'] ?: ''); ?></td>
                                                    <td style="text-align:right;"><?php if (!empty($gp['deposit_receipt_id'])): ?><a href="deposit-receipt.php?receipt_id=<?php echo $gp['deposit_receipt_id']; ?>" class="ld-receipt-link">Receipt <?php echo htmlspecialchars($gp['receipt_number'] ?? ''); ?></a><?php else: ?><span style="color:var(--admin-muted);font-size:0.75rem;">—</span><?php endif; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($item['credit_txns'])): ?>
                                <div class="ld-section">
                                    <div class="ld-section-title">Credit / Dues</div>
                                    <div class="ld-table-wrap">
                                        <table class="ld-table">
                                            <thead><tr><th>Type</th><th style="text-align:right;">Amount</th><th>Description</th><th>Balance After</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($item['credit_txns'] as $gc): ?>
                                                <tr>
                                                    <td><span class="badge <?php echo $gc['transaction_type'] === 'charge' ? 'badge-under-maintenance' : 'badge-refill'; ?>" style="font-size:0.6rem;"><?php echo $gc['transaction_type'] === 'charge' ? 'Dues Charged' : 'Payment'; ?></span></td>
                                                    <td style="text-align:right;font-weight:800;color:<?php echo $gc['transaction_type'] === 'charge' ? 'var(--danger)' : 'var(--success)'; ?>;"><?php echo $gc['transaction_type'] === 'charge' ? '' : '-'; ?>₹<?php echo number_format($gc['amount'], 2); ?></td>
                                                    <td style="font-size:0.75rem;color:var(--admin-muted);"><?php echo htmlspecialchars($gc['description'] ?? ''); ?></td>
                                                    <td style="font-weight:600;">₹<?php echo number_format($gc['balance_after'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($item['rental_returns'])): ?>
                                <div class="ld-section">
                                    <div class="ld-section-title">Rental Details</div>
                                    <div class="ld-table-wrap">
                                        <table class="ld-table">
                                            <thead><tr><th>Serial</th><th>Gas</th><th style="text-align:right;">Rent</th><th style="text-align:right;">Damage</th><th style="text-align:right;">Deposit Deducted</th><th style="text-align:right;">Collected</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($item['rental_returns'] as $gr): ?>
                                                <tr>
                                                    <td style="font-weight:700;"><?php echo htmlspecialchars($gr['serial_number'] ?? '?'); ?></td>
                                                    <td><?php echo htmlspecialchars(($gr['gas_name'] ?? '?') . ' (' . ($gr['size_capacity'] ?? '?') . ')'); ?></td>
                                                    <td style="text-align:right;">₹<?php echo number_format($gr['rent_amount'], 2); ?></td>
                                                    <td style="text-align:right;color:#dc2626;"><?php echo $gr['damage_charge'] > 0 ? '₹' . number_format($gr['damage_charge'], 2) : '—'; ?></td>
                                                    <td style="text-align:right;color:#d97706;"><?php echo $gr['deposit_deducted'] > 0 ? '₹' . number_format($gr['deposit_deducted'], 2) : '—'; ?></td>
                                                    <td style="text-align:right;font-weight:800;">₹<?php echo number_format($gr['total_collected'], 2); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($item['group_type'] === 'exchange_settlement' && !empty($exchange_txns)): ?>
                                <div class="ld-section">
                                    <div class="ld-section-title">Cylinder Movements</div>
                                    <div class="ld-table-wrap">
                                        <table class="ld-table">
                                            <thead><tr><th>Serial</th><th>Gas / Size</th><th>Direction</th><th>Details</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($exchange_txns as $et): ?>
                                                <?php
                                                $direction = '';
                                                $dir_icon = '';
                                                $dir_color = '';
                                                if (in_array($et['transaction_type'], ['return_from_customer', 'consumer_return', 'consumer_give_back'])) {
                                                    $direction = 'Returned by Customer';
                                                    $dir_icon = '⬅';
                                                    $dir_color = '#f59e0b';
                                                } elseif (in_array($et['transaction_type'], ['issue_to_customer'])) {
                                                    $direction = 'Given to Customer';
                                                    $dir_icon = '➡';
                                                    $dir_color = '#16a34a';
                                                } else {
                                                    $direction = str_replace('_', ' ', ucfirst($et['transaction_type']));
                                                    $dir_icon = '⟳';
                                                    $dir_color = 'var(--info)';
                                                }
                                                $ot_label = '';
                                                $ot_bg = '';
                                                if (($et['ownership_type'] ?? '') === 'consumer_owned') { $ot_label = 'CON'; $ot_bg = '#dbeafe'; }
                                                elseif (($et['ownership_type'] ?? '') === 'partner_owned') { $ot_label = 'BR'; $ot_bg = '#fef3c7'; }
                                                elseif (($et['ownership_type'] ?? '') === 'owned') { $ot_label = 'OWN'; $ot_bg = '#d1fae5'; }
                                                elseif (($et['ownership_type'] ?? '') === 'vendor_owned') { $ot_label = 'VEN'; $ot_bg = '#e8d5f5'; }
                                                ?>
                                                <tr>
                                                    <td style="font-weight:700;font-family:monospace;"><?php echo htmlspecialchars($et['serial_number'] ?? '?'); ?>
                                                        <?php if ($ot_label): ?><span style="background:<?php echo $ot_bg; ?>;padding:1px 5px;border-radius:3px;font-size:0.65rem;font-weight:800;margin-left:4px;"><?php echo $ot_label; ?></span><?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(($et['gas_name'] ?? '?') . ' (' . ($et['size_capacity'] ?? '?') . ')'); ?></td>
                                                    <td><span style="color:<?php echo $dir_color; ?>;font-weight:700;"><?php echo $dir_icon . ' ' . $direction; ?></span></td>
                                                    <td style="font-size:0.75rem;color:var(--admin-muted);"><?php echo htmlspecialchars($et['notes'] ?? ''); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php elseif ($item['type'] === 'payment'): ?>
                <tr class="ledger-row" data-date="<?php echo date('Y-m-d', strtotime($item['date'])); ?>" data-datetime="<?php echo date('Y-m-d\TH:i:s', strtotime($item['date'])); ?>" data-type="payment_<?php echo $item['payment_type']; ?>">
                    <td data-label=""></td>
                    <td data-label="Date & Time" style="font-size:0.85rem;color:var(--admin-muted);font-weight:600;white-space:nowrap;"><?php echo date('M d, Y h:i A', strtotime($item['date'])); ?></td>
                    <td data-label="Event"><?php
                        $pbadge = 'badge-refill'; $plabel = 'Payment';
                        if ($item['payment_type'] === 'deposit_added') { $pbadge = 'badge-rental'; $plabel = 'Deposit Added'; }
                        elseif ($item['payment_type'] === 'deposit_refunded') { $pbadge = 'badge-under-maintenance'; $plabel = 'Deposit Refunded'; }
                        elseif ($item['payment_type'] === 'deposit_damage') { $pbadge = 'badge-under-maintenance'; $plabel = 'Damage Charge'; }
                        elseif ($item['payment_type'] === 'rent_payment') { $pbadge = 'badge-rental'; $plabel = 'Rent Payment'; }
                    ?><span class="badge <?php echo $pbadge; ?>" style="font-size:0.65rem;padding:3px 8px;"><?php echo $plabel; ?></span></td>
                    <td data-label="Details" style="font-size:0.9rem;font-weight:600;">Via <?php echo htmlspecialchars($item['payment_method']); ?><?php if ($item['notes']): ?><div style="font-size:0.75rem;color:var(--admin-muted);font-weight:400;margin-top:0.25rem;">💬 <?php echo htmlspecialchars($item['notes']); ?></div><?php endif; ?></td>
                    <td data-label="Amount" style="text-align:right;font-weight:800;font-size:0.95rem;color:<?php echo ($item['payment_type'] === 'deposit_damage') ? '#dc2626' : 'var(--success)'; ?>;"><?php echo ($item['payment_type'] === 'deposit_damage' ? '-' : '') . '₹' . number_format($item['amount'], 2); ?></td>
                    <td data-label="Receipt" style="text-align:right;"><?php if (!empty($item['deposit_receipt_id'])): ?><a href="deposit-receipt.php?receipt_id=<?php echo $item['deposit_receipt_id']; ?>" class="btn-secondary" style="padding:0.35rem 0.65rem;font-size:0.7rem;border-radius:6px;">Deposit Receipt</a><?php else: ?><span style="font-size:0.75rem;color:var(--admin-muted);font-weight:600;">—</span><?php endif; ?></td>
                </tr>
                <?php elseif ($item['type'] === 'credit_tx'): ?>
                <tr class="ledger-row" data-date="<?php echo date('Y-m-d', strtotime($item['date'])); ?>" data-datetime="<?php echo date('Y-m-d\TH:i:s', strtotime($item['date'])); ?>" data-type="credit">
                    <td data-label=""></td>
                    <td data-label="Date & Time" style="font-size:0.85rem;color:var(--admin-muted);font-weight:600;white-space:nowrap;"><?php echo date('M d, Y h:i A', strtotime($item['date'])); ?></td>
                    <td data-label="Event"><span class="badge <?php echo $item['ct_type'] === 'charge' ? 'badge-under-maintenance' : 'badge-refill'; ?>" style="font-size:0.65rem;padding:3px 8px;"><?php echo $item['ct_type'] === 'charge' ? 'Dues Charged' : 'Dues Payment'; ?></span></td>
                    <td data-label="Details" style="font-size:0.9rem;font-weight:600;"><?php echo htmlspecialchars($item['description'] ?? ''); ?><?php if ($item['due_date']): ?><div style="font-size:0.75rem;color:#d97706;font-weight:600;margin-top:0.25rem;">📅 Due: <?php echo date('d M Y', strtotime($item['due_date'])); ?> · Balance: ₹<?php echo number_format($item['balance_after'], 2); ?></div><?php endif; ?></td>
                    <td data-label="Amount" style="text-align:right;font-weight:800;font-size:0.95rem;color:<?php echo $item['ct_type'] === 'charge' ? 'var(--danger)' : 'var(--success)'; ?>;"><?php echo $item['ct_type'] === 'charge' ? '' : '-'; ?>₹<?php echo number_format($item['amount'], 2); ?></td>
                    <td data-label="Receipt" style="text-align:right;"><?php if ($item['refill_order_id']): ?><a href="invoice.php?order_id=<?php echo $item['refill_order_id']; ?>" class="btn-secondary" style="padding:0.35rem 0.65rem;font-size:0.7rem;border-radius:6px;">Invoice</a><?php else: ?><span style="font-size:0.75rem;color:var(--admin-muted);font-weight:600;">—</span><?php endif; ?></td>
                </tr>
                <?php elseif ($item['type'] === 'rental_return'): ?>
                <tr class="ledger-row" data-date="<?php echo date('Y-m-d', strtotime($item['date'])); ?>" data-datetime="<?php echo date('Y-m-d\TH:i:s', strtotime($item['date'])); ?>" data-type="rental_return">
                    <td data-label=""></td>
                    <td data-label="Date & Time" style="font-size:0.85rem;color:var(--admin-muted);font-weight:600;white-space:nowrap;"><?php echo date('M d, Y h:i A', strtotime($item['date'])); ?></td>
                    <td data-label="Event"><span class="badge badge-rental" style="font-size:0.65rem;padding:3px 8px;">Rental Return</span></td>
                    <td data-label="Details" style="font-size:0.9rem;font-weight:600;"><?php echo htmlspecialchars($item['serial']); ?><span style="font-weight:400;color:var(--admin-muted);"> · <?php echo htmlspecialchars($item['gas']); ?></span><div style="font-size:0.75rem;color:var(--admin-muted);font-weight:400;margin-top:0.25rem;"><?php echo $item['chargeable_days']; ?> days @ ₹<?php echo number_format($item['daily_rate'], 2); ?>/day<?php if ($item['damage_charge'] > 0): ?> · Damage: ₹<?php echo number_format($item['damage_charge'], 2); ?><?php endif; ?><?php if ($item['deposit_deducted'] > 0): ?> · Deposit deducted: ₹<?php echo number_format($item['deposit_deducted'], 2); ?><?php endif; ?><?php if ($item['notes']): ?> · <?php echo htmlspecialchars($item['notes']); ?><?php endif; ?></div></td>
                    <td data-label="Amount" style="text-align:right;font-weight:800;font-size:0.95rem;color:var(--success);">₹<?php echo number_format($item['total_collected'], 2); ?></td>
                    <td data-label="Receipt" style="text-align:right;"><a href="rental-invoice.php?return_id=<?php echo $item['return_id']; ?>" class="btn-secondary" style="padding:0.35rem 0.65rem;font-size:0.7rem;border-radius:6px;">Invoice</a></td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($ledger)): ?>
                <tr><td colspan="6" style="text-align:center;padding:4rem 0;color:var(--admin-muted);">No transactions captured for this customer ledger statement yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div style="text-align:center;padding:0.5rem 0;font-size:0.75rem;">
            <span id="loadMoreBtn" onclick="loadMoreRows()" style="display:none;color:var(--admin-accent);font-weight:700;cursor:pointer;text-decoration:underline;text-underline-offset:3px;">Show more</span>
            <span id="ledgerNoMore" style="display:none;color:var(--admin-muted);font-weight:600;">All transactions loaded</span>
        </div>
    </div>
</div>
</div>

<link rel="stylesheet" href="customer-profile.css">

<!-- Modal: Return Cylinder to Consumer (consumer_owned only) -->
<div class="modal" id="returnCylinderModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Return Cylinder</h3>
            <button class="modal-close" onclick="closeModal('returnCylinderModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="return_cylinder">
            <input type="hidden" name="cylinder_id" id="returnCylinderId">
            
            <div id="returnCylinderSerial" style="font-size:1.1rem;font-weight:800;color:var(--admin-accent);margin-bottom:1rem;padding:0.75rem;background:#f8fafc;border-radius:8px;text-align:center;"></div>
            
            <div class="form-group">
                <label class="form-label">Action</label>
                <select name="return_type" class="form-control" required>
                    <option value="consumer_gave_empty">Consumer gave us empty cylinder (keep in inventory)</option>
                    <option value="return_to_consumer">We are returning this cylinder to consumer (remove from system)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="return_notes" class="form-control" rows="2" placeholder="Condition, reason, reference..."></textarea>
            </div>
            
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;">Process Return</button>
        </form>
    </div>
</div>

<!-- Modal: Rental Return & Settle -->
<div class="modal" id="rentalReturnModal">
    <div class="modal-content" style="max-width:580px;">
        <div class="modal-header">
            <h3><?php echo __('customer.return_settle'); ?></h3>
            <button class="modal-close" onclick="closeModal('rentalReturnModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="rental_return">
            <input type="hidden" name="cylinder_id" id="rentalReturnCylinderId">
            <input type="hidden" name="refill_order_item_id" id="rentalReturnItemId">

            <!-- Cylinder info -->
            <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem;background:#f0f9ff;border-radius:10px;margin-bottom:1rem;">
                <div style="flex:1;">
                    <div style="font-weight:800;font-size:1.05rem;color:var(--admin-accent);" id="rentalReturnSerial"></div>
                    <div style="font-size:0.8rem;color:var(--admin-muted);font-weight:600;" id="rentalReturnGas"></div>

                </div>
                <span id="rentalReturnOwnership" style="padding:2px 8px;border-radius:4px;font-size:0.7rem;font-weight:800;"></span>
            </div>

            <!-- Rental period -->
            <div class="rental-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem;">
                <div>
                    <label class="form-label"><?php echo __('customer.return_date'); ?></label>
                    <div style="position:relative;">
                        <input type="datetime-local" name="return_date" id="rentalReturnDate" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" style="padding-right:2.8rem;" onchange="calculateRentalReturn()">
                        <button type="button" id="confirmDateBtn" onclick="calculateRentalReturn()" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:none;border-radius:6px;background:var(--admin-accent);color:#fff;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:1;">✓</button>
                    </div>
                </div>
                <div>
                    <label class="form-label"><?php echo __('customer.condition'); ?></label>
                    <select name="condition" id="rentalCondition" class="form-control" onchange="toggleDamageField(); calculateRentalReturn();">
                        <option value="empty"><?php echo __('customer.condition_empty'); ?></option>
                        <option value="filled"><?php echo __('customer.condition_filled'); ?></option>
                        <option value="damaged"><?php echo __('customer.condition_damaged'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Rent summary -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;padding:0.75rem 1rem;background:#f8fafc;border-radius:8px;margin-bottom:0.75rem;font-size:0.85rem;">
                <div>
                    <span style="color:var(--admin-muted);">Borrowed:</span>
                    <strong id="rentalBorrowDateDisplay" style="color:var(--admin-fg);">—</strong>
                    <span style="color:var(--admin-muted);"> → Return:</span>
                    <strong id="rentalReturnDateDisplay" style="color:var(--admin-fg);">—</strong>
                </div>
                <div>
                    <strong id="rentalDaysHeld">0</strong><span style="color:var(--admin-muted);"> days · </span>
                    <strong id="rentalFreeDays">0</strong><span style="color:var(--admin-muted);"> free · </span>
                    <strong id="rentalChargeableDays" style="color:#3b82f6;">0</strong><span style="color:var(--admin-muted);"> chargeable</span>
                </div>
                <div style="font-weight:800;font-size:1rem;color:var(--admin-accent);">₹<span id="rentalRentSubtotal">0.00</span> rent</div>
                <span id="rentalRate" style="display:none;"></span>
            </div>

            <!-- Damage charge (shown only when condition=damaged) -->
            <div id="damageChargeSection" style="display:none;margin-bottom:0.75rem;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;padding:0.75rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;">
                    <div>
                        <label class="form-label" style="color:#991b1b;"><?php echo __('customer.damage_charge'); ?></label>
                        <input type="number" step="0.01" name="damage_charge" id="damageCharge" class="form-control" value="0" min="0" onchange="calculateRentalReturn()" onkeyup="calculateRentalReturn()" style="border-color:#fca5a5;">
                    </div>
                    <div>
                        <label class="form-label" style="color:#991b1b;"><?php echo __('customer.damage_description'); ?></label>
                        <input type="text" name="damage_description" class="form-control" placeholder="e.g. Valve damaged, body dented" style="border-color:#fca5a5;">
                    </div>
                </div>
            </div>

            <!-- Settlement -->
            <div style="border:1px solid var(--admin-border);border-radius:10px;padding:1rem;margin-bottom:0.75rem;">
                <div style="font-weight:700;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--admin-muted);margin-bottom:0.75rem;">Settlement</div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem;">
                    <div>
                        <label class="form-label" style="font-size:0.75rem;">Deposit Balance</label>
                        <div style="padding:0.5rem 0.75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-weight:800;font-size:1rem;color:#166534;" id="rentalDepositDisplay">₹0</div>
                    </div>
                    <div>
                        <label class="form-label" style="display:flex;align-items:center;gap:6px;font-size:0.75rem;">
                            <input type="checkbox" id="deductDepositCheck" onchange="calculateRentalReturn()" style="accent-color:var(--admin-accent);width:16px;height:16px;">
                            Deduct from Deposit
                        </label>
                        <input type="number" step="0.01" name="deduct_from_deposit" id="deductDepositAmount" class="form-control" value="0" min="0" onchange="calculateRentalReturn()" onkeyup="calculateRentalReturn()" disabled aria-label="Deduct amount from deposit">
                    </div>
                </div>

                <div style="border-top:1px solid var(--admin-border);padding-top:0.75rem;display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <div>
                        <label class="form-label" style="font-size:0.75rem;">Total Charges</label>
                        <div style="padding:0.5rem 0.75rem;background:#fafafb;border:1px solid var(--admin-border);border-radius:8px;font-weight:800;font-size:1rem;" id="rentalTotalCharges">₹0</div>
                        <input type="hidden" name="total_charges_display" id="rentalTotalChargesHidden">
                    </div>
                    <div>
                        <label class="form-label" style="font-size:0.75rem;">Amount to Collect</label>
                        <div style="padding:0.5rem 0.75rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-weight:800;font-size:1.25rem;color:#1d4ed8;" id="rentalAmountToCollect">₹0</div>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:0.75rem;">Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="UPI">UPI</option>
                            <option value="Card">Card</option>
                            <option value="Waived">Waived</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:0.75rem;">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;padding:0.75rem;font-size:1rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo __('customer.process_return'); ?>
            </button>
        </form>
    </div>
</div>

<!-- Modal: Track Cylinder -->
<div class="modal" id="trackCylinderModal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3>Track Cylinder</h3>
            <button class="modal-close" onclick="closeModal('trackCylinderModal')">&times;</button>
        </div>
        <div class="form-group">
            <label class="form-label">Enter Cylinder Serial Number</label>
            <input type="text" id="trackSerialInput" class="form-control" placeholder="e.g. OX-10L-101" style="font-family:monospace;">
        </div>
        <button class="btn-primary" onclick="trackCylinder()" style="width:100%;justify-content:center;margin-top:0.5rem;">Track Now</button>
        <div id="trackResult" style="margin-top:1rem;display:none;"></div>
    </div>
</div>

<!-- Modal: Record Manual Payment -->
<div class="modal" id="ledgerActionModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Record Payment</h3>
            <button class="modal-close" onclick="closeModal('ledgerActionModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="ledger_adjustment">
            
            <div class="form-group">
                <label class="form-label" id="amountLabel">Amount (₹)</label>
                <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00">
                <small id="amountHint" style="color: var(--admin-muted); font-size: 0.75rem; display: none;">Total refund amount including any damage deduction</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">Transaction</label>
                <select name="payment_type" class="form-control" id="paymentTypeSelect">
                    <option value="payment_received">Payment Received (from customer)</option>
                    <option value="payment_refunded">Payment Refunded (to customer)</option>
                </select>
            </div>

            <div class="form-group" id="damageDeductionGroup" style="display: none;">
                <label class="form-label">Damage Charge (₹)</label>
                <input type="number" step="0.01" name="damage_deduction" class="form-control" value="0" min="0" placeholder="0.00">
                <small style="color: var(--admin-muted); font-size: 0.75rem;">Deduct for cylinder damages. Net refund = Amount − Damage Charge</small>
            </div>

            <div class="form-group" id="duesSettleInfo" style="display: none;">
                <div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:0.75rem 1rem;font-size:0.85rem;color:#92400e;">
                    <strong>⚠️ Dues Settlement Notice</strong><br>
                    This customer has <strong id="duesAmountDisplay" style="color:#dc2626;">₹<?php echo number_format($customer['credit_used'] ?? 0, 2); ?></strong> in outstanding dues.
                    The payment will first settle dues; only the remaining balance will be added to deposit.
                    <div style="margin-top:0.5rem;font-size:0.8rem;" id="settleBreakdown">
                        ₹<span id="settleToDues">0.00</span> → Dues · ₹<span id="settleToDeposit">0.00</span> → Deposit
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select name="payment_method" class="form-control">
                    <option value="Cash">Cash</option>
                    <option value="UPI / Online">UPI / Online Transfer</option>
                    <option value="Bank Check">Bank Check</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Billing Entity</label>
                <select name="business_name" class="form-control">
                    <?php foreach (getBusinesses() as $key => $biz): ?>
                        <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($biz['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Record reason, bank details, or reference..."></textarea>
            </div>
            
            <div class="form-group">
                <label class="form-label">Payment Date</label>
                <input type="datetime-local" name="payment_date" class="form-control" style="max-width: 260px;" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;">Record Payment</button>
        </form>
    </div>
</div>

<script>
    var customerCreditUsed = <?php echo floatval($customer['credit_used'] ?? 0); ?>;

    function openModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('active');
    }
    
    function closeModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('active');
    }

    function updateDuesBreakdown() {
        var amountInput = document.querySelector('input[name="amount"]');
        if (!amountInput) return;
        var amount = parseFloat(amountInput.value) || 0;
        var toDues = Math.min(amount, customerCreditUsed);
        var toDeposit = Math.max(0, amount - customerCreditUsed);
        var el1 = document.getElementById('settleToDues');
        var el2 = document.getElementById('settleToDeposit');
        if (el1) el1.textContent = toDues.toFixed(2);
        if (el2) el2.textContent = toDeposit.toFixed(2);
    }

    function toggleDamageDeductionField() {
        var sel = document.getElementById('paymentTypeSelect');
        var group = document.getElementById('damageDeductionGroup');
        var label = document.getElementById('amountLabel');
        var hint = document.getElementById('amountHint');
        var duesInfo = document.getElementById('duesSettleInfo');
        if (!sel) return;

        if (sel.value === 'payment_refunded') {
            if (group) group.style.display = 'block';
            if (label) label.textContent = 'Amount to Refund (₹)';
            if (hint) hint.style.display = '';
            if (duesInfo) duesInfo.style.display = 'none';
        } else if (sel.value === 'payment_received') {
            if (group) group.style.display = 'none';
            if (label) label.textContent = 'Amount Received (₹)';
            if (hint) hint.style.display = 'none';
            if (duesInfo) {
                duesInfo.style.display = customerCreditUsed > 0 ? 'block' : 'none';
                updateDuesBreakdown();
            }
        } else {
            if (group) group.style.display = 'none';
            if (label) label.textContent = 'Amount (₹)';
            if (hint) hint.style.display = 'none';
            if (duesInfo) duesInfo.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        try {
            var sel = document.getElementById('paymentTypeSelect');
            if (sel) sel.addEventListener('change', toggleDamageDeductionField);
            var amountInput = document.querySelector('input[name="amount"]');
            if (amountInput) amountInput.addEventListener('input', updateDuesBreakdown);
            toggleDamageDeductionField();
        } catch (e) {
            console.error('Payment modal init error:', e);
        }
    });

    function openReturnCylinderModal(cylId, serial) {
        document.getElementById('returnCylinderId').value = cylId;
        document.getElementById('returnCylinderSerial').textContent = serial;
        openModal('returnCylinderModal');
    }

    function openRentalReturnModal(c) {
        document.getElementById('rentalReturnCylinderId').value = c.id;
        document.getElementById('rentalReturnSerial').textContent = c.serial;
        document.getElementById('rentalReturnGas').textContent = c.gas;
        document.getElementById('rentalReturnItemId').value = c.refill_order_item_id || '';
        const tag = document.getElementById('rentalReturnOwnership');
        if (c.ownership === 'partner_owned') { tag.textContent = 'BR'; tag.style.cssText = 'background:#fef3c7;color:#92400e;border:1px solid rgba(251,191,36,0.3);'; tag.title = 'Partner: ' + (c.partnerName || 'Unknown'); }
        else if (c.ownership === 'consumer_owned') { tag.textContent = 'CON'; tag.style.cssText = 'background:#dbeafe;color:#1e40af;border:1px solid rgba(16,185,129,0.3);'; tag.title = 'Belongs to: ' + (c.ownerName || 'Unknown'); }
        else if (c.ownership === 'vendor_owned') { tag.textContent = 'VEN'; tag.style.cssText = 'background:#e8d5f5;color:#6b21a8;border:1px solid rgba(147,51,234,0.3);'; tag.title = 'Vendor: ' + (c.vendorName || 'Unknown'); }
        else { tag.textContent = 'OWN'; tag.style.cssText = 'background:#d1fae5;color:#065f46;border:1px solid rgba(16,185,129,0.3);'; }
        document.getElementById('rentalDepositDisplay').textContent = '\u20b9' + parseFloat(c.deposit || 0).toFixed(2);
        const rate = parseFloat(c.daily_rate) || 0;
        const freeDays = parseInt(c.free_days) || 0;
        borrowDate = c.borrow_date ? new Date(c.borrow_date) : new Date();
        dailyRate = rate;
        freeDaysVal = freeDays;
        document.getElementById('rentalRate').textContent = '\u20b9' + rate.toFixed(2);
        document.getElementById('rentalFreeDays').textContent = freeDays;
        var bd = document.getElementById('rentalBorrowDateDisplay');
        if (bd) bd.textContent = c.borrow_date ? new Date(c.borrow_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
        calculateRentalReturn();
        openModal('rentalReturnModal');
    }

    let borrowDate = null, dailyRate = 0, freeDaysVal = 0;

    function calculateRentalReturn() {
        const returnDateStr = document.getElementById('rentalReturnDate').value;
        if (!borrowDate || !returnDateStr || isNaN(borrowDate.getTime())) return;
        const returnDate = new Date(returnDateStr.substring(0, 10) + 'T23:59:59');
        if (isNaN(returnDate.getTime())) return;
        var rdd = document.getElementById('rentalReturnDateDisplay');
        if (rdd) rdd.textContent = returnDate.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
        const diffMs = returnDate - borrowDate;
        const daysHeld = Math.max(0, Math.ceil(diffMs / (1000 * 60 * 60 * 24))) || 0;

        const freeDays = freeDaysVal;
        const chargeableDays = Math.max(0, daysHeld - freeDays) || 0;
        const rentAmount = (chargeableDays * dailyRate) || 0;
        const condition = document.getElementById('rentalCondition').value;
        const damageCharge = condition === 'damaged' ? (parseFloat(document.getElementById('damageCharge').value) || 0) : 0;
        const totalCharges = (rentAmount + damageCharge) || 0;
        const deductCheck = document.getElementById('deductDepositCheck').checked;
        const deductAmt = deductCheck ? (parseFloat(document.getElementById('deductDepositAmount').value) || 0) : 0;
        const depositBalance = parseFloat(document.getElementById('rentalDepositDisplay').textContent.replace(/[^0-9.-]/g, '')) || 0;
        const maxDeduct = Math.min(deductAmt, depositBalance, totalCharges) || 0;
        const amountToCollect = Math.max(0, totalCharges - maxDeduct) || 0;

        document.getElementById('rentalDaysHeld').textContent = daysHeld;
        document.getElementById('rentalChargeableDays').textContent = chargeableDays;
        document.getElementById('rentalRentSubtotal').textContent = '\u20b9' + rentAmount.toFixed(2);
        document.getElementById('rentalTotalCharges').textContent = '\u20b9' + totalCharges.toFixed(2);
        document.getElementById('rentalAmountToCollect').textContent = '\u20b9' + amountToCollect.toFixed(2);
        if (deductCheck && deductAmt > maxDeduct) {
            document.getElementById('deductDepositAmount').value = maxDeduct.toFixed(2);
        }
    }

    function toggleDamageField() {
        const cond = document.getElementById('rentalCondition').value;
        document.getElementById('damageChargeSection').style.display = cond === 'damaged' ? 'block' : 'none';
    }

    document.addEventListener('DOMContentLoaded', function() {
        const deductCheck = document.getElementById('deductDepositCheck');
        if (deductCheck) {
            deductCheck.addEventListener('change', function() {
                document.getElementById('deductDepositAmount').disabled = !this.checked;
                if (!this.checked) document.getElementById('deductDepositAmount').value = '0';
                calculateRentalReturn();
            });
        }

    });

    function trackCylinder() {
        const serial = document.getElementById('trackSerialInput').value.trim();
        const resultDiv = document.getElementById('trackResult');
        if (!serial) { resultDiv.style.display = 'block'; resultDiv.innerHTML = '<div style="padding:1rem;background:#fee2e2;color:#b91c1c;border-radius:8px;">Please enter a serial number.</div>'; return; }
        
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--admin-muted);">Searching...</div>';
        
        fetch('track-cylinder.php?serial=' + encodeURIComponent(serial))
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    resultDiv.innerHTML = '<div style="padding:1rem;background:#fee2e2;color:#b91c1c;border-radius:8px;">' + data.error + '</div>';
                    return;
                }
                let statusColor = data.status === 'with_customer' ? '#f59e0b' : data.status === 'empty' ? '#6b7280' : data.status === 'filled' ? '#10b981' : '#3b82f6';
                let ownerLabel = data.ownership_type === 'consumer_owned' ? 'Customer-Owned' : data.ownership_type === 'partner_owned' ? 'Partner-Owned' : data.ownership_type === 'vendor_owned' ? 'Vendor-Owned' : 'Company-Owned';
                resultDiv.innerHTML = `
                    <div style="background:#f8fafc;border-radius:12px;padding:1.25rem;border:1px solid var(--admin-border);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                            <span style="font-weight:800;font-size:1.1rem;color:var(--admin-accent);">${data.serial_number}</span>
                            <span style="background:${statusColor};color:#fff;padding:4px 10px;border-radius:6px;font-weight:700;font-size:0.8rem;">${data.status}</span>
                        </div>
                        <div class="track-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;font-size:0.9rem;">
                            <div><span style="color:var(--admin-muted);font-weight:600;">Gas:</span> ${data.gas_name} (${data.size_capacity})</div>
                            <div><span style="color:var(--admin-muted);font-weight:600;">Owner:</span> ${ownerLabel}</div>
                            ${data.original_owner_name ? '<div><span style="color:var(--admin-muted);font-weight:600;">Belongs to Customer:</span> ' + data.original_owner_name + '</div>' : ''}
                            ${data.customer_name ? '<div><span style="color:var(--admin-muted);font-weight:600;">With Customer:</span> ' + data.customer_name + '</div>' : ''}
                            ${data.vendor_name ? '<div><span style="color:var(--admin-muted);font-weight:600;">At Vendor:</span> ' + data.vendor_name + '</div>' : ''}
                            ${data.partner_name ? '<div><span style="color:var(--admin-muted);font-weight:600;">With Partner:</span> ' + data.partner_name + '</div>' : ''}
                            <div><span style="color:var(--admin-muted);font-weight:600;">Expiry:</span> ${data.expiry_date || 'N/A'}</div>
                        </div>
                        <div style="margin-top:1rem;padding-top:0.75rem;border-top:1px solid var(--admin-border);">
                            <div style="font-weight:700;font-size:0.85rem;color:var(--admin-muted);margin-bottom:0.5rem;">Recent History</div>
                            ${data.history && data.history.length > 0 ? data.history.map(h => 
                                '<div style="display:flex;justify-content:space-between;font-size:0.8rem;padding:0.35rem 0;border-bottom:1px solid rgba(0,0,0,0.04);">' +
                                '<span style="color:var(--admin-muted);">' + h.date + '</span>' +
                                '<span style="font-weight:600;">' + h.type + '</span>' +
                                '<span style="color:var(--admin-muted);">' + (h.notes || '') + '</span>' +
                                '</div>'
                            ).join('') : '<div style="font-size:0.8rem;color:var(--admin-muted);text-align:center;padding:0.5rem;">No history available.</div>'}
                        </div>
                    </div>
                `;
            })
            .catch(() => {
                resultDiv.innerHTML = '<div style="padding:1rem;background:#fee2e2;color:#b91c1c;border-radius:8px;">Error fetching cylinder data.</div>';
            });
    }

    function switchCylTab(tabId) {
        document.querySelectorAll('.cyl-tab-content').forEach(function(el) { el.style.display = 'none'; });
        document.querySelectorAll('.cyl-tab').forEach(function(el) {
            el.style.borderBottomColor = 'transparent';
            el.style.color = 'var(--admin-muted)';
        });
        var tab = document.getElementById(tabId);
        if (tab) tab.style.display = '';
        var btnId = tabId === 'tab-all' ? 'tabAllBtn' : tabId === 'tab-cylinders' ? 'tabCylBtn' : tabId === 'tab-inventory' ? 'tabInvBtn' : 'tabOwnBtn';
        var btn = document.getElementById(btnId);
        if (btn) { btn.style.borderBottomColor = 'var(--admin-accent)'; btn.style.color = 'var(--admin-accent)'; }
    }

    function toggleLedgerDetails(orderId) {
        const row = document.getElementById('details_' + orderId);
        const toggle = document.getElementById('toggle_' + orderId);
        if (!row) return;
        if (row.classList.contains('show')) {
            row.classList.remove('show');
            toggle.textContent = '▶';
        } else {
            row.classList.add('show');
            toggle.textContent = '▼';
        }
    }

    // ── Ledger Filter & Pagination ──
    var ledgerPerPage = 15;

    function toggleFilter() {
        var panel = document.getElementById('filterPanel');
        var toggle = document.getElementById('filterToggleBtn');
        if (!panel || !toggle) return;
        var open = panel.style.display !== 'none';
        panel.style.display = open ? 'none' : 'block';
        toggle.textContent = open ? '▾ Filters' : '▴ Filters';
        if (!open) renderLedger();
    }

    function updateFilterChips() {
        var chips = document.getElementById('filterChips');
        if (!chips) return;
        var dateFrom = document.getElementById('fDateFrom').value;
        var dateTo = document.getElementById('fDateTo').value;
        var type = document.getElementById('fType').value;
        var search = document.getElementById('fSearch').value;
        var parts = [];
        if (dateFrom) parts.push(dateFrom + (dateTo ? ' → ' + dateTo : ' → ∞'));
        else if (dateTo) parts.push('∞ → ' + dateTo);
        var typeLabels = {'order':'Orders','payment_deposit_added':'Deposits','payment_deposit_refunded':'Refunds','payment':'Payments','credit':'Credit','rental_return':'Rental','group_payment_received':'Payments Received','group_credit_order':'Credit Orders','group_rental_return':'Rental Returns','group_exchange_settlement':'Exchange Settlements'};
        if (type) parts.push(typeLabels[type] || type);
        if (search) parts.push('"' + search + '"');
        if (parts.length) {
            chips.style.display = 'block';
            chips.innerHTML = '<span style="font-size:0.7rem;display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">' +
                parts.map(function(p) { return '<span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-weight:600;">' + p + ' <span onclick="resetFilters()" style="cursor:pointer;margin-left:2px;">✕</span></span>'; }).join('') +
                ' <span onclick="resetFilters()" style="color:var(--admin-muted);cursor:pointer;font-weight:600;text-decoration:underline;text-underline-offset:2px;">Clear all</span>' +
                '</span>';
        } else {
            chips.style.display = 'none';
            chips.innerHTML = '';
        }
    }

    function renderLedger() {
        var dateFrom = document.getElementById('fDateFrom').value;
        var dateTo = document.getElementById('fDateTo').value;
        var type = document.getElementById('fType').value;
        var search = document.getElementById('fSearch').value.toLowerCase();
        var rows = Array.from(document.querySelectorAll('#ledgerTable tbody tr.ledger-row'));
        var visibleCount = 0, shownCount = 0;

        rows.forEach(function(row) {
            var rDate = row.getAttribute('data-date') || '';
            var rType = row.getAttribute('data-type') || '';
            var match = true;

            if (dateFrom && rDate < dateFrom) match = false;
            if (dateTo && rDate > dateTo) match = false;
            if (type) {
                if (type.indexOf('payment_') === 0) {
                    if (rType !== type) match = false;
                } else if (type === 'payment') {
                    if (rType.indexOf('payment_') !== 0 && rType !== 'payment') match = false;
                } else if (type === 'group_payment_received') {
                    if (rType !== 'group_payment_received' && rType !== 'payment_deposit_added') match = false;
                } else if (type === 'group_rental_return') {
                    if (rType !== 'group_rental_return' && rType !== 'rental_return') match = false;
                } else if (type === 'group_credit_order') {
                    if (rType !== 'group_credit_order' && rType !== 'credit') match = false;
                } else if (type === 'group_exchange_settlement') {
                    if (rType !== 'group_exchange_settlement') match = false;
                } else {
                    if (rType !== type) match = false;
                }
            }
            if (search && (row.textContent || '').toLowerCase().indexOf(search) === -1) match = false;

            var did = row.getAttribute('data-details-id');
            var details = did ? document.getElementById(did) : null;

            if (!match) {
                row.style.display = 'none';
                if (details) details.style.display = 'none';
                return;
            }

            visibleCount++;
            if (visibleCount <= ledgerPerPage) {
                row.style.display = '';
                if (details && details.classList.contains('show')) details.style.display = 'table-row';
                shownCount++;
            } else {
                row.style.display = 'none';
                if (details) details.style.display = 'none';
            }
        });

        var remaining = visibleCount - shownCount;
        var loadBtn = document.getElementById('loadMoreBtn');
        var noMore = document.getElementById('ledgerNoMore');
        if (loadBtn) {
            loadBtn.style.display = remaining > 0 ? 'inline' : 'none';
            loadBtn.textContent = 'Show ' + Math.min(remaining, 15) + ' more (' + remaining + ' remaining)';
        }
        if (noMore) noMore.style.display = (visibleCount > 0 && remaining <= 0) ? 'inline' : 'none';
        updateFilterChips();
    }

    function loadMoreRows() {
        ledgerPerPage += 15;
        renderLedger();
    }

    function resetFilters() {
        document.getElementById('fDateFrom').value = '';
        document.getElementById('fDateTo').value = '';
        document.getElementById('fType').value = '';
        document.getElementById('fSearch').value = '';
        ledgerPerPage = 15;
        renderLedger();
    }

    // Toast notification on page load
    document.addEventListener('DOMContentLoaded', function () {
        const params = new URLSearchParams(window.location.search);
        if (params.has('success')) {
            showToast('Ledger transaction recorded successfully!', 'success');
            params.delete('success');
            updateUrlWithoutParam(params);
        } else if (params.has('error')) {
            showToast('Transaction failed. Please try again.', 'error');
            params.delete('error');
            updateUrlWithoutParam(params);
        }

        ['fDateFrom','fDateTo','fType'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', function() { ledgerPerPage = 15; renderLedger(); });
        });
        var fSearch = document.getElementById('fSearch');
        if (fSearch) fSearch.addEventListener('keyup', function() { ledgerPerPage = 15; renderLedger(); });
        renderLedger();
    });

    function updateUrlWithoutParam(params) {
        const qs = params.toString();
        window.history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
    }

    function showToast(msg, type) {
        const toast = document.createElement('div');
        toast.textContent = msg;
        toast.style.cssText = `
            position: fixed; bottom: 2rem; right: 2rem; z-index: 99999;
            padding: 1rem 1.5rem; border-radius: 12px; font-weight: 700;
            font-size: 0.95rem; color: #fff; box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            transform: translateY(20px); opacity: 0;
            transition: all 0.35s ease;
            background: ${type === 'success' ? '#10b981' : '#ef4444'};
        `;
        document.body.appendChild(toast);
        requestAnimationFrame(() => {
            toast.style.transform = 'translateY(0)';
            toast.style.opacity = '1';
        });
        setTimeout(() => {
            toast.style.transform = 'translateY(20px)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    }
</script>

<?php
require_once 'layout_footer.php';
?>
