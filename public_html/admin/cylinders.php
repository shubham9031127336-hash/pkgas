<?php
require_once __DIR__ . '/lang_init.php';

// ── AJAX: Analyze Bulk Status Update (must run before layout.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_status_update') {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/csrf.php';
    require_login();
    validateCsrfToken();
    require_once __DIR__ . '/db.php';
    require_once 'inventory-utils.php';
    require_once __DIR__ . '/bulk_operation_engine.php';
    ob_clean();
    header('Content-Type: application/json');
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        $target_status = trim($_POST['target_status'] ?? '');
        $vendor_id = intval($_POST['vendor_id'] ?? 0);
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $context = ['action' => 'status_update', 'target_status' => $target_status, 'vendor_id' => $vendor_id, 'payment_amount' => $payment_amount];
        $report = generateFullImpactReport($pdo, $ids, 'status_update', $context);
        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── AJAX: Analyze Bulk Delete (must run before layout.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_delete') {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/csrf.php';
    require_login();
    validateCsrfToken();
    require_once __DIR__ . '/db.php';
    require_once 'inventory-utils.php';
    require_once __DIR__ . '/bulk_operation_engine.php';
    ob_clean();
    header('Content-Type: application/json');
    try {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        $context = ['action' => 'delete'];
        $report = generateFullImpactReport($pdo, $ids, 'delete', $context);
        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$page_title = __('cylinders.title');
$active_menu = "cylinders";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once 'inventory-utils.php';
require_once __DIR__ . '/bulk_operation_engine.php';
runConsumerCylinderMigrations($pdo);
runSellCylinderMigrations($pdo);
runDeletedCylindersMigration($pdo);
runRefillCostMigrations($pdo);
runVendorBatchAdjustmentMigrations($pdo);
runVendorRefillBatchItemsMigration($pdo);
syncCustomerRefillCylinderFlag($pdo);

$message = '';
$error = '';

// Handle Single CUS-R Cylinder Dispatch to Vendor (quick-action "To Vendor" button)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dispatch_single_cus_r') {
    $cylinder_id = intval($_POST['cylinder_id'] ?? 0);
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $service_id = intval($_POST['service_id'] ?? 0);

    if ($cylinder_id > 0 && $vendor_id > 0 && $service_id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE cylinders SET status = 'sent_to_vendor', current_vendor_id = ? WHERE id = ? AND status = 'received_for_refill'");
            $stmt->execute([$vendor_id, $cylinder_id]);

            if ($stmt->rowCount() > 0) {
                $pdo->prepare("UPDATE vendors SET active_refill_count = active_refill_count + 1 WHERE id = ?")->execute([$vendor_id]);
                logCylinderTransaction($pdo, $cylinder_id, null, $vendor_id, 'send_to_vendor', "Dispatched to vendor for refilling (quick action).");
                $pdo->prepare("UPDATE customer_refill_services SET status = 'sent_to_vendor', vendor_id = ? WHERE id = ? AND status = 'received'")->execute([$vendor_id, $service_id]);
                $message = "Cylinder dispatched to vendor for refilling!";
            } else {
                $error = "Cylinder could not be dispatched — it may already be sent.";
            }

            $pdo->commit();
            syncInventory($pdo);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Dispatch failed: " . $e->getMessage();
        }
    } else {
        $error = "Invalid cylinder, vendor, or service parameters.";
    }
}

// Handle Manual Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $cylinder_id = intval($_POST['cylinder_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    $vendor_id = isset($_POST['vendor_id']) ? intval($_POST['vendor_id']) : 0;
    $notes = trim($_POST['notes'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $refill_cost_per_unit = floatval($_POST['refill_cost_per_unit'] ?? 0);
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $deduction_amount_single = floatval($_POST['deduction_amount'] ?? 0);
    $deduction_notes_single = trim($_POST['deduction_notes'] ?? '');
    $addition_amount_single = floatval($_POST['addition_amount'] ?? 0);
    $addition_notes_single = trim($_POST['addition_notes'] ?? '');
    $payment_option_single = trim($_POST['payment_option'] ?? 'credit');
    $paid_method_single = trim($_POST['paid_method'] ?? '');
    $paid_date_raw_single = $_POST['paid_date'] ?? date('Y-m-d\TH:i');
    $paid_reference_single = trim($_POST['paid_reference'] ?? '');
    
    if ($cylinder_id > 0 && !empty($new_status)) {
        try {
            $pdo->beginTransaction();
            
            // Get original cylinder status
            $chk = $pdo->prepare("SELECT status, serial_number, current_vendor_id, current_customer_id, is_customer_refill_cylinder, gas_type_id, size_capacity FROM cylinders WHERE id = ?");
            $chk->execute([$cylinder_id]);
            $cyl = $chk->fetch();
            
            if ($cyl) {
                $old_status = $cyl['status'];
                $old_vendor_id = $cyl['current_vendor_id'];
                $is_cus_r = !empty($cyl['is_customer_refill_cylinder']);
                
                // Only CUS-R cylinders can be returned to customer
                if ($new_status === 'returned_to_consumer' && !$is_cus_r) {
                    throw new Exception("Cylinder " . htmlspecialchars($cyl['serial_number']) . " is not a CUS-R (Customer Refill Service) cylinder and cannot be returned to customer.");
                }
                
                // If it is dispatching to a vendor
                if ($new_status === 'sent_to_vendor') {
                    if ($vendor_id <= 0) {
                        throw new Exception("Please select a vendor to dispatch this cylinder.");
                    }
                    
                    // Update cylinder status, vendor ID, and clear customer association
                    $stmt = $pdo->prepare("UPDATE cylinders SET status = 'sent_to_vendor', current_vendor_id = ?, current_customer_id = NULL WHERE id = ?");
                    $stmt->execute([$vendor_id, $cylinder_id]);
                    
                    // Increment vendor count
                    $stmt = $pdo->prepare("UPDATE vendors SET active_refill_count = active_refill_count + 1 WHERE id = ?");
                    $stmt->execute([$vendor_id]);
                    
                    // Log movement
                    logCylinderTransaction($pdo, $cylinder_id, null, $vendor_id, 'send_to_vendor', "Dispatched to vendor for refilling. Notes: $notes");
                    
                    // Sync CUS-R record
                    if ($is_cus_r) {
                        $svc_upd = $pdo->prepare("UPDATE customer_refill_services SET status = 'sent_to_vendor', vendor_id = ?, sent_to_vendor_at = NOW() WHERE cylinder_id = ? AND status = 'received'");
                        $svc_upd->execute([$vendor_id, $cylinder_id]);
                    }
                    
                    // If it was with customer, log lease breakdown before dispatching to vendor
                    if ($old_status === 'with_customer') {
                        logCylinderTransaction($pdo, $cylinder_id, $cyl['current_customer_id'], null, 'return_from_customer', "Lease forcefully broken via status change to SENT TO VENDOR. Notes: $notes");
                    }
                    
                } elseif ($new_status === 'returned_to_consumer' && $is_cus_r) {
                    // CUS-R: Return filled cylinder to customer
                    $now = date('Y-m-d H:i:s');
                    
                    // Clear vendor if previously sent
                    if ($old_status === 'sent_to_vendor' && $old_vendor_id) {
                        $stmt = $pdo->prepare("UPDATE vendors SET active_refill_count = GREATEST(0, active_refill_count - 1) WHERE id = ?");
                        $stmt->execute([$old_vendor_id]);
                        logCylinderTransaction($pdo, $cylinder_id, null, $old_vendor_id, 'return_from_customer', "Forceful receive from vendor (manual status change). Notes: $notes");
                        // Removed batch creation — use Vendor Settlement page for reconciliation
                    }
                    
                    // Update cylinder to returned_to_consumer
                    $stmt = $pdo->prepare("UPDATE cylinders SET status = 'returned_to_consumer', current_vendor_id = NULL, current_customer_id = NULL WHERE id = ?");
                    $stmt->execute([$cylinder_id]);
                    
                    // Update CUS-R record
                    $svc = $pdo->prepare("SELECT * FROM customer_refill_services WHERE cylinder_id = ? AND status IN ('filled_from_vendor','returned_to_warehouse') LIMIT 1");
                    $svc->execute([$cylinder_id]);
                    $svc_data = $svc->fetch();
                    
                    if ($svc_data) {
                        $pdo->prepare("UPDATE customer_refill_services SET status = 'returned_to_customer', payment_method = ?, payment_status = 'paid', completed_at = ? WHERE id = ?")->execute([$payment_method, $now, $svc_data['id']]);
                        
                        if ($payment_amount > 0) {
                            $stmt = $pdo->prepare("INSERT INTO payments (customer_id, customer_refill_service_id, amount, payment_method, payment_type, notes) VALUES (?, ?, ?, ?, 'refill_service_payment', ?)");
                            $stmt->execute([$svc_data['customer_id'], $svc_data['id'], $payment_amount, $payment_method, "Return to customer for Service #{$svc_data['id']} (Status Update). $notes"]);
                        }
                    }
                    
                    logCylinderTransaction($pdo, $cylinder_id, null, null, 'returned_after_refill', "Returned to customer after refill. Notes: $notes");
                    
                } else {
                    // If it was previously sent_to_vendor and is now being received/changed to filled/empty/maintenance
                    if ($old_status === 'sent_to_vendor' && $old_vendor_id) {
                        // Decrement old vendor count
                        $stmt = $pdo->prepare("UPDATE vendors SET active_refill_count = GREATEST(0, active_refill_count - 1) WHERE id = ?");
                        $stmt->execute([$old_vendor_id]);
                        
                        // Log receiving/return
                        logCylinderTransaction($pdo, $cylinder_id, null, $old_vendor_id, 'return_from_customer', "Received back from vendor (status changed to " . strtoupper($new_status) . "). Notes: $notes");
                        
                        // For CUS-R, advance to filled_from_vendor
                        if ($is_cus_r && $new_status === 'filled') {
                            $pdo->prepare("UPDATE customer_refill_services SET status = 'filled_from_vendor', filled_from_vendor_at = NOW() WHERE cylinder_id = ? AND status = 'sent_to_vendor'")->execute([$cylinder_id]);
                        }
                        
                        // Track refill cost on cylinder (no vendor batch — use settlement page)
                        if ($new_status === 'filled' && $refill_cost_per_unit > 0) {
                            $pdo->prepare("UPDATE cylinders SET current_refill_cost = ?, last_refill_vendor_id = ? WHERE id = ?")->execute([$refill_cost_per_unit, $old_vendor_id, $cylinder_id]);
                            $pdo->prepare("UPDATE refill_order_items roi JOIN customer_refill_services crs ON roi.refill_order_id = crs.refill_order_id AND roi.gas_type_id = crs.gas_type_id SET roi.refill_cost = ? WHERE crs.cylinder_id = ? AND crs.status IN ('returned_to_warehouse','filled_from_vendor') AND roi.refill_cost = 0")->execute([$refill_cost_per_unit, $cylinder_id]);
                            $pdo->prepare("UPDATE refill_order_items SET refill_cost = ? WHERE returned_cylinder_id = ? AND refill_cost = 0")->execute([$refill_cost_per_unit, $cylinder_id]);
                        }
                    }
                    
                    // If it was with customer, log lease breakdown and clear customer
                    if ($old_status === 'with_customer') {
                        logCylinderTransaction($pdo, $cylinder_id, $cyl['current_customer_id'], null, 'return_from_customer', "Lease forcefully broken via status change to " . strtoupper($new_status) . ". Notes: $notes");
                        $pdo->prepare("UPDATE customers SET active_cylinders_count = GREATEST(0, active_cylinders_count - 1) WHERE id = ?")->execute([$cyl['current_customer_id']]);
                    }
                    
                    // Update status and clear vendor/customer
                    $stmt = $pdo->prepare("UPDATE cylinders SET status = ?, current_vendor_id = NULL, current_customer_id = NULL WHERE id = ?");
                    $stmt->execute([$new_status, $cylinder_id]);
                    
                    // Log general action if not previously handled above
                    if ($old_status !== 'sent_to_vendor' && $old_status !== 'with_customer') {
                        logCylinderTransaction($pdo, $cylinder_id, null, null, $new_status === 'under_maintenance' ? 'maintenance' : 'refill', "Status updated from $old_status to $new_status. Notes: $notes");
                    }
                }
                
                $message = "Cylinder " . htmlspecialchars($cyl['serial_number']) . " updated to status: " . strtoupper($new_status);
            }
            
            $pdo->commit();
            
            // Sync Aggregated inventory counts
            syncInventory($pdo);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Failed to update cylinder status: " . $e->getMessage();
        }
    }
}

// Handle Cylinder Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_cylinder') {
    $cylinder_id = intval($_POST['cylinder_id'] ?? 0);
    $confirm_serial = trim($_POST['confirm_serial'] ?? '');
    
    if ($cylinder_id > 0 && !empty($confirm_serial)) {
        try {
            $chk = $pdo->prepare("SELECT c.serial_number, g.name as gas_name, c.size_capacity FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ?");
            $chk->execute([$cylinder_id]);
            $cyl = $chk->fetch();
            
            if ($cyl) {
                if ($cyl['serial_number'] !== $confirm_serial) {
                    $error = "Failed to delete cylinder: typed serial number does not match exactly.";
                } else {
                    $pdo->beginTransaction();
                    
                    // Archive cylinder with full history before deletion
                    $deleted_by = $_SESSION['username'] ?? $_SESSION['user'] ?? 'unknown';
                    archiveDeletedCylinder($pdo, $cylinder_id, $deleted_by);
                    
                    // Transaction logs are preserved for audit trail (FK cascade removed)
                    
                    // Unlink order items (both issued and returned cylinders)
                    $up_items = $pdo->prepare("UPDATE refill_order_items SET cylinder_id = NULL, returned_cylinder_id = NULL WHERE cylinder_id = ? OR returned_cylinder_id = ?");
                    $up_items->execute([$cylinder_id, $cylinder_id]);

                    // Delete related rental returns (FK constraint)
                    $del_rentals = $pdo->prepare("DELETE FROM rental_returns WHERE cylinder_id = ?");
                    $del_rentals->execute([$cylinder_id]);
                    
                    $pdo->commit();
                    
                    // Sync stock count aggregates
                    syncInventory($pdo);
                    
                    $message = "Cylinder " . htmlspecialchars($cyl['serial_number']) . " (" . htmlspecialchars($cyl['gas_name']) . " - " . htmlspecialchars($cyl['size_capacity']) . ") has been permanently deleted from the registry.";
                }
            } else {
                $error = __('cylinders.not_found');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Deletion transaction failed: " . $e->getMessage();
        }
    } else {
        $error = "Invalid cylinder parameters for deletion.";
    }
}

// Handle Bulk Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_update_status') {
    $selected_cyl_ids = $_POST['bulk_cylinder_ids'] ?? [];
    $new_status = $_POST['status'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $refill_cost_per_unit = floatval($_POST['refill_cost_per_unit'] ?? 0);

    if (!empty($selected_cyl_ids) && in_array($new_status, ['filled', 'empty', 'under_maintenance'])) {
        try {
            $pdo->beginTransaction();
            $updated_count = 0;

            foreach ($selected_cyl_ids as $cyl_id) {
                $cyl_id = intval($cyl_id);
                $chk = $pdo->prepare("SELECT status, serial_number, current_vendor_id, current_customer_id, is_customer_refill_cylinder, gas_type_id, size_capacity FROM cylinders WHERE id = ?");
                $chk->execute([$cyl_id]);
                $cyl = $chk->fetch();
                if (!$cyl) continue;

                $old_status = $cyl['status'];
                $old_vendor_id = $cyl['current_vendor_id'];
                $old_customer_id = $cyl['current_customer_id'];

                if ($old_status === 'sent_to_vendor' && $old_vendor_id) {
                    $stmt = $pdo->prepare("UPDATE vendors SET active_refill_count = GREATEST(0, active_refill_count - 1) WHERE id = ?");
                    $stmt->execute([$old_vendor_id]);
                    logCylinderTransaction($pdo, $cyl_id, null, $old_vendor_id, 'return_from_customer', "Received back in bulk (status to " . strtoupper($new_status) . "). Notes: $notes");
                }

                if ($old_status === 'with_customer') {
                    logCylinderTransaction($pdo, $cyl_id, $old_customer_id, null, 'return_from_customer', "Lease broken via bulk status change to " . strtoupper($new_status) . ". Notes: $notes");
                }

                $stmt = $pdo->prepare("UPDATE cylinders SET status = ?, current_vendor_id = NULL, current_customer_id = NULL WHERE id = ?");
                $stmt->execute([$new_status, $cyl_id]);

                if ($new_status === 'filled' && $refill_cost_per_unit > 0) {
                    $pdo->prepare("UPDATE cylinders SET current_refill_cost = ? WHERE id = ?")->execute([$refill_cost_per_unit, $cyl_id]);
                    $pdo->prepare("UPDATE refill_order_items roi JOIN customer_refill_services crs ON roi.refill_order_id = crs.refill_order_id AND roi.gas_type_id = crs.gas_type_id SET roi.refill_cost = ? WHERE crs.cylinder_id = ? AND crs.status IN ('returned_to_warehouse','filled_from_vendor') AND roi.refill_cost = 0")->execute([$refill_cost_per_unit, $cyl_id]);
                    $pdo->prepare("UPDATE refill_order_items SET refill_cost = ? WHERE returned_cylinder_id = ? AND refill_cost = 0")->execute([$refill_cost_per_unit, $cyl_id]);
                }

                if ($old_status !== 'sent_to_vendor' && $old_status !== 'with_customer') {
                    logCylinderTransaction($pdo, $cyl_id, null, null, $new_status === 'under_maintenance' ? 'maintenance' : 'refill', "Status updated in bulk from $old_status to $new_status. Notes: $notes");
                }

                $updated_count++;
            }

            $pdo->commit();
            syncInventory($pdo);
            $message = "Successfully updated status of $updated_count cylinders in bulk!";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Bulk update failed: " . $e->getMessage();
        }
    } else {
        $error = __('cylinders.bulk_no_selection');
    }
}

// Handle Bulk Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
    $selected_cyl_ids = $_POST['bulk_cylinder_ids'] ?? [];
    $confirm_text = trim($_POST['confirm_delete_text'] ?? '');
    
    if (!empty($selected_cyl_ids) && strtolower($confirm_text) === 'delete') {
        try {
            $pdo->beginTransaction();
            $deleted_count = 0;
            
            foreach ($selected_cyl_ids as $cyl_id) {
                $cyl_id = intval($cyl_id);
                
                // Get cylinder details
                $chk = $pdo->prepare("SELECT serial_number, status, current_customer_id FROM cylinders WHERE id = ?");
                $chk->execute([$cyl_id]);
                $cyl = $chk->fetch();
                
                if ($cyl) {
                    // Log customer lease force-stop if applicable
                    if ($cyl['status'] === 'with_customer') {
                        logCylinderTransaction($pdo, $cyl_id, $cyl['current_customer_id'], null, 'return_from_customer', "Registry deletion forced: Lease broken.");
                    }
                    
                    // Archive cylinder with full history before deletion
                    $deleted_by = $_SESSION['username'] ?? $_SESSION['user'] ?? 'unknown';
                    archiveDeletedCylinder($pdo, $cyl_id, $deleted_by);
                    
                    // Transaction logs are preserved for audit trail (FK cascade removed)
                    
                    // Unlink order items (both issued and returned cylinders)
                    $up_items = $pdo->prepare("UPDATE refill_order_items SET cylinder_id = NULL, returned_cylinder_id = NULL WHERE cylinder_id = ? OR returned_cylinder_id = ?");
                    $up_items->execute([$cyl_id, $cyl_id]);

                    // Delete related rental returns (FK constraint)
                    $del_rentals = $pdo->prepare("DELETE FROM rental_returns WHERE cylinder_id = ?");
                    $del_rentals->execute([$cyl_id]);
                    
                    $deleted_count++;
                }
            }
            
            $pdo->commit();
            syncInventory($pdo);
            $message = "Successfully deleted $deleted_count cylinders from the registry.";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Bulk deletion failed: " . $e->getMessage();
        }
    } else {
        $error = __('cylinders.bulk_delete_invalid');
    }
}

// Filters variables
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$gas_filter = isset($_GET['gas_id']) ? intval($_GET['gas_id']) : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] === '1';

// Build Query
$sql = "SELECT c.*, g.name as gas_name, cu.name as customer_name, v.name as vendor_name, p.company_name AS partner_name, oc.name as original_owner_name
        FROM cylinders c 
        JOIN gas_types g ON c.gas_type_id = g.id 
        LEFT JOIN customers cu ON c.current_customer_id = cu.id
        LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
        LEFT JOIN vendors v ON c.current_vendor_id = v.id
        LEFT JOIN partners p ON c.current_partner_id = p.id
        WHERE " . ($show_completed ? "1=1" : "c.lifecycle_status != 'archived'
        AND c.status NOT IN ('returned_to_partner', 'returned_to_consumer')
        AND NOT (c.ownership_type = 'consumer_owned' AND c.current_customer_id IS NOT NULL AND c.current_customer_id = c.original_owner_customer_id)") . "
        ";
$params = [];

if (!empty($search_query)) {
    $sql .= " AND (c.serial_number LIKE ? OR cu.name LIKE ? OR p.company_name LIKE ?)";
    $like_val = "%" . $search_query . "%";
    $params[] = $like_val;
    $params[] = $like_val;
    $params[] = $like_val;
}

if (!empty($status_filter)) {
    if ($status_filter === 'empty') {
        $sql .= " AND c.status IN ('empty', 'borrowed_from_partner')";
    } else {
        $sql .= " AND c.status = ?";
        $params[] = $status_filter;
    }
}
if ($gas_filter > 0) {
    $sql .= " AND c.gas_type_id = ?";
    $params[] = $gas_filter;
}

$sql .= " ORDER BY c.serial_number ASC";

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Count query
$count_sql = "SELECT COUNT(*) FROM cylinders c 
              JOIN gas_types g ON c.gas_type_id = g.id 
              LEFT JOIN customers cu ON c.current_customer_id = cu.id
              LEFT JOIN customers oc ON c.original_owner_customer_id = oc.id
              LEFT JOIN vendors v ON c.current_vendor_id = v.id
              LEFT JOIN partners p ON c.current_partner_id = p.id
                WHERE " . ($show_completed ? "1=1" : "c.lifecycle_status != 'archived'
                AND c.status NOT IN ('returned_to_partner', 'returned_to_consumer')
                AND NOT (c.ownership_type = 'consumer_owned' AND c.current_customer_id IS NOT NULL AND c.current_customer_id = c.original_owner_customer_id)") . "";
$count_params = [];

if (!empty($search_query)) {
    $count_sql .= " AND (c.serial_number LIKE ? OR cu.name LIKE ? OR p.company_name LIKE ?)";
    $like_val = "%" . $search_query . "%";
    $count_params[] = $like_val;
    $count_params[] = $like_val;
    $count_params[] = $like_val;
}
if (!empty($status_filter)) {
    if ($status_filter === 'empty') {
        $count_sql .= " AND c.status IN ('empty', 'borrowed_from_partner')";
    } else {
        $count_sql .= " AND c.status = ?";
        $count_params[] = $status_filter;
    }
}
if ($gas_filter > 0) {
    $count_sql .= " AND c.gas_type_id = ?";
    $count_params[] = $gas_filter;
}

try {
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_count = (int) $count_stmt->fetchColumn();
} catch (PDOException $e) {
    $total_count = 0;
}

$total_pages = max(1, ceil($total_count / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$sql .= " LIMIT $per_page OFFSET $offset";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cylinders = $stmt->fetchAll();
} catch (PDOException $e) {
    $cylinders = [];
    $error = "Error fetching cylinders: " . $e->getMessage();
}

// Build refill service map (cylinder_id → service data) for CUS-R action buttons
$refill_svc_map = [];
try {
    $svc_map_stmt = $pdo->query("SELECT id, cylinder_id, status, refill_source, customer_id, vendor_id FROM customer_refill_services WHERE status NOT IN ('returned_to_customer', 'cancelled')");
    while ($r = $svc_map_stmt->fetch()) {
        $refill_svc_map[$r['cylinder_id']] = $r;
    }
} catch (PDOException $e) {}

// Fetch lists for filter inputs
$gas_types = [];
try {
    $gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

$vendors = [];
try {
    $vendors = $pdo->query("SELECT * FROM vendors ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('cylinders.heading'); ?></h2>
        <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('cylinders.subtitle'); ?></p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="send-cylinder.php" class="btn-secondary" style="border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.5rem 1rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
            <?php echo __('cylinders.dispatch_empty'); ?>
        </a>
        <a href="receive-cylinder.php" class="btn-secondary" style="border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.5rem 1rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
            <?php echo __('cylinders.receive_filled'); ?>
        </a>
        <a href="return-cylinder.php" class="btn-secondary" style="border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.5rem 1rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Return Refill
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert-banner" style="background: var(--success-soft); color: var(--success); border-color: #a7f3d0;">
        <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-banner" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Compact Search & Filter Header -->
<div class="admin-card" style="padding: 0.85rem 1.25rem;">
    <div class="grid-filter-bar" style="grid-template-columns: 1.8fr 1fr 1fr auto auto; gap: 0.75rem; align-items: center;">
        <form method="GET" id="searchForm" style="position: relative; margin: 0;">
            <input type="text" name="search" id="searchInput" class="form-control"
                   placeholder="🔍 <?php echo __('cylinders.search_placeholder'); ?>"
                   value="<?php echo htmlspecialchars($search_query); ?>"
                   style="padding: 0.5rem 1rem 0.5rem 2.3rem; border-color: var(--admin-accent);" autofocus>
            <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 17px; height: 17px; color: #94a3b8; pointer-events: none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0z"/></svg>
        </form>

        <div>
            <select id="filterGas" class="form-control" style="padding: 0.5rem 0.75rem;">
                <option value="0"><?php echo __('cylinders.all_gases'); ?></option>
                <?php foreach ($gas_types as $gt): ?>
                    <option value="<?php echo $gt['id']; ?>" <?php echo $gas_filter === intval($gt['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($gt['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;align-items:center;gap:4px;">
            <select id="filterStatus" class="form-control" style="padding: 0.5rem 0.75rem;">
                <option value=""><?php echo __('cylinders.all_statuses'); ?></option>
                <option value="filled" <?php echo $status_filter === 'filled' ? 'selected' : ''; ?>><?php echo __('cylinders.filled'); ?></option>
                <option value="empty" <?php echo $status_filter === 'empty' ? 'selected' : ''; ?>><?php echo __('cylinders.empty'); ?></option>
                <option value="with_customer" <?php echo $status_filter === 'with_customer' ? 'selected' : ''; ?>><?php echo __('cylinders.with_customer'); ?></option>
                <option value="sent_to_vendor" <?php echo $status_filter === 'sent_to_vendor' ? 'selected' : ''; ?>><?php echo __('cylinders.sent_to_vendor'); ?></option>
                <option value="under_maintenance" <?php echo $status_filter === 'under_maintenance' ? 'selected' : ''; ?>><?php echo __('cylinders.under_maintenance'); ?></option>
                <option value="received_for_refill" <?php echo $status_filter === 'received_for_refill' ? 'selected' : ''; ?>><?php echo __('cylinders.received_for_refill'); ?></option>
                <option value="returned_to_consumer" <?php echo $status_filter === 'returned_to_consumer' ? 'selected' : ''; ?>>Returned to Consumer</option>
            </select>
            <span style="flex-shrink:0;cursor:pointer;display:flex;" onclick="toggleStatusLegend(event)" title="Status legend">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            </span>
        </div>

        <form method="GET" style="display:inline;margin:0;">
            <label style="display:flex;align-items:center;gap:4px;font-size:0.77rem;font-weight:600;cursor:pointer;white-space:nowrap;padding:0.35rem 0.6rem;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:8px;color:var(--admin-muted);user-select:none;transition:all 0.15s;">
                <input type="checkbox" name="show_completed" value="1" <?php echo $show_completed ? 'checked' : ''; ?> onchange="this.form.submit()" style="margin:0;accent-color:var(--admin-accent);">
                <?php echo $show_completed ? 'Show deleted' : 'Show completed'; ?>
            </label>
        </form>

        <button class="btn-secondary" style="padding: 0.5rem 1rem; height: auto; font-size: 0.82rem; border-radius: 8px;" onclick="resetFilters()">↺ Reset</button>
    </div>
</div>

<div id="bulkActionsPanel" class="bulk-panel">
    <div class="bulk-panel-header">
        <div class="bulk-panel-header-inner">
            <span class="bulk-panel-title"><?php echo __('cylinders.bulk_ops'); ?></span>
            <span class="bulk-panel-sub">— Selected <strong id="bulkSelectedCount">0</strong> cylinders</span>
        </div>
        <button type="button" class="bulk-panel-close" onclick="clearBulkSelection()" title="<?php echo __('cylinders.cancel_selection'); ?>">&times;</button>
    </div>
    <div class="bulk-panel-body">
        <select id="bulkStatusSelect" class="form-control" onchange="onBulkStatusChange(this.value)">
            <option value=""><?php echo __('cylinders.choose_status'); ?></option>
            <option value="filled"><?php echo __('cylinders.filled_in_warehouse'); ?></option>
            <option value="empty"><?php echo __('cylinders.empty_in_warehouse'); ?></option>
            <option value="under_maintenance"><?php echo __('cylinders.under_maintenance'); ?></option>
        </select>

        <div id="bulkFilledDetails" class="bulk-panel-section" style="display:none;">
            <div class="bulk-panel-filled-hint">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:4px;"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Refill Cost
            </div>
            <div class="bulk-panel-filled-row">
                <input type="number" step="0.01" id="bulkRefillCostPerUnit" class="form-control" placeholder="Cost per cylinder (₹)">
                <span class="bulk-panel-filled-note">per cylinder — only if vendor charged for refill</span>
            </div>
        </div>

        <div class="bulk-panel-row">
            <input type="text" id="bulkNotesInput" class="form-control" placeholder="<?php echo __('cylinders.bulk_notes_placeholder'); ?>">
            <button type="button" class="btn-primary" onclick="submitBulkStatusUpdate()"><?php echo __('cylinders.apply_changes'); ?></button>
            <button type="button" class="btn-bulk-delete" onclick="openBulkDeleteModal()"><?php echo __('cylinders.delete_batch'); ?></button>
        </div>
    </div>
</div>

<!-- Cylinders List Table -->
<div class="admin-card" style="padding: 0;">
    <div class="table-wrapper" style="border: none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 48px; text-align: left; padding-left: 1.5rem;">
                        <input type="checkbox" id="selectAllCylinders" onchange="toggleSelectAllCylinders(this)" style="width: 18px; height: 18px; cursor: pointer; accent-color: var(--admin-accent);">
                    </th>
                    <th><?php echo __('cylinders.serial_no'); ?></th>
                    <th><?php echo __('cylinders.gas_details'); ?></th>
                    <th>Status</th>
                    <th><?php echo __('cylinders.hydro_expiry'); ?></th>
                    <th><?php echo __('cylinders.held_by'); ?></th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cylinders as $c): ?>
                <?php 
                    // Warning check if hydrostatic test expiry is close (within 3 months or passed)
                    $is_expired = false;
                    if ($c['expiry_date']) {
                        $expiry_stamp = strtotime($c['expiry_date']);
                        $warning_limit = strtotime('+3 months');
                        if ($expiry_stamp <= time()) {
                            $is_expired = 'expired';
                        } elseif ($expiry_stamp <= $warning_limit) {
                            $is_expired = 'warning';
                        }
                    }
                ?>
                <tr<?php if ($c['lifecycle_status'] === 'archived') echo ' style="opacity:0.45;"'; ?>>
                    <td style="padding-left: 1.5rem;" data-label="<?php echo __('cylinders.select'); ?>">
                        <input type="checkbox" class="cylinder-select-checkbox" value="<?php echo $c['id']; ?>" data-is-assigned="<?php echo $c['status'] === 'with_customer' ? '1' : '0'; ?>" data-serial="<?php echo htmlspecialchars($c['serial_number']); ?>" data-gas-name="<?php echo htmlspecialchars($c['gas_name']); ?>" data-size-capacity="<?php echo htmlspecialchars($c['size_capacity']); ?>" onchange="onCylinderCheckboxSelectionChange()" style="width: 18px; height: 18px; cursor: pointer; accent-color: var(--admin-accent); transform: scale(1.1);">
                    </td>
                    <td style="font-weight: 700; color: var(--admin-accent);" data-label="<?php echo __('cylinders.serial_no'); ?>">
                        <?php echo htmlspecialchars($c['serial_number']); ?>
                        <?php
                        $tag_html = '';
                        if ($c['ownership_type'] === 'partner_owned' && $c['status'] !== 'returned_to_partner') {
                            $pname = htmlspecialchars($c['partner_name'] ?: 'Unknown');
                            $tag_html = '<span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(251,191,36,0.3);cursor:help;" title="Partner: ' . $pname . '">BR</span>';
                        } elseif ($c['ownership_type'] === 'consumer_owned' && $c['status'] !== 'returned_to_consumer') {
                            $oname = htmlspecialchars($c['original_owner_name'] ?: 'Unknown');
                            $tag_html = '<span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(59,130,246,0.3);cursor:help;" title="Belongs to: ' . $oname . '">CON</span>';
                        } elseif ($c['ownership_type'] === 'vendor_owned') {
                            $vname = htmlspecialchars($c['vendor_name'] ?: 'Unknown');
                            $tag_html = '<span style="background:#e8d5f5;color:#6b21a8;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(147,51,234,0.3);cursor:help;" title="Vendor: ' . $vname . '">VEN</span>';
                        } elseif ($c['ownership_type'] === 'owned') {
                            $tag_html = '<span style="background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(16,185,129,0.3);">OWN</span>';
                        }
                        if (!empty($c['is_customer_refill_cylinder'])) {
                            $tag_html .= ' <span style="background:#fff7ed;color:#c2410c;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(234,88,12,0.3);cursor:help;" title="Customer Refill Service">CUS-R</span>';
                        }
                        echo $tag_html;
                        ?>
                    </td>

                    <td style="font-weight: 600;" data-label="<?php echo __('cylinders.gas_details'); ?>">
                        <?php echo htmlspecialchars($c['gas_name']) . " (" . htmlspecialchars($c['size_capacity']) . ")"; ?>
                    </td>
                    <td data-label="Status">
                        <?php
                        $badge_class = 'badge-filled';
                        $status_text = $c['status'];
                        if ($c['status'] === 'empty') $badge_class = 'badge-empty';
                        if ($c['status'] === 'with_customer') $badge_class = 'badge-with-customer';
                        if ($c['status'] === 'sent_to_vendor') $badge_class = 'badge-sent-to-vendor';
                        if ($c['status'] === 'under_maintenance') $badge_class = 'badge-under-maintenance';
                        if ($c['status'] === 'borrowed_from_partner') {
                            $badge_class = 'badge-empty';
                            $status_text = 'empty';
                        }
                        ?>
                        <span class="badge <?php echo $badge_class; ?>">
                            <?php echo str_replace('_', ' ', $status_text); ?>
                        </span>
                    </td>
                    <td data-label="<?php echo __('cylinders.hydro_expiry'); ?>">
                        <?php if ($c['expiry_date']): ?>
                            <span style="font-weight: 700; color: <?php echo $is_expired === 'expired' ? 'var(--danger)' : ($is_expired === 'warning' ? 'var(--warning)' : 'inherit'); ?>;">
                                <?php echo date('M d, Y', strtotime($c['expiry_date'])); ?>
                                <?php if ($is_expired === 'expired'): ?> <?php echo __('cylinders.expired'); ?><?php endif; ?>
                                <?php if ($is_expired === 'warning'): ?> <?php echo __('cylinders.due_soon'); ?><?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span style="color: var(--admin-muted);">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.9rem; font-weight: 600;" data-label="<?php echo __('cylinders.held_by'); ?>">
                        <?php 
                        if ($c['status'] === 'with_customer') {
                            echo "👤 " . htmlspecialchars($c['customer_name'] ?: __('cylinders.unknown_customer'));
                        } elseif ($c['status'] === 'sent_to_vendor') {
                            echo "🔧 " . htmlspecialchars($c['vendor_name'] ?: __('cylinders.unknown_vendor'));
                        } elseif (in_array($c['status'], ['lent_to_partner', 'borrowed_from_partner', 'with_partner']) && !empty($c['partner_name'])) {
                            echo "🤝 " . htmlspecialchars($c['partner_name']);
                        } else {
                            echo "<span style='color: var(--admin-muted);'>" . ($show_completed ? '-' : __('cylinders.warehouse')) . "</span>";
                        }
                        ?>
                    </td>
                    <td style="text-align: right;" data-label="Action">
                        <div style="display: flex; justify-content: flex-end; gap: 0.35rem; flex-wrap: wrap;">
                            <?php
                            $is_cus_r = !empty($c['is_customer_refill_cylinder']);
                            $svc_data = $refill_svc_map[$c['id']] ?? null;
                            ?>
                            <?php if ($is_cus_r && $svc_data): ?>
                                <?php if ($svc_data['status'] === 'received' && $c['status'] === 'received_for_refill'): ?>
                                    <form method="POST" style="display:inline"><?php csrfField(); ?>
                                        <input type="hidden" name="action" value="dispatch_single_cus_r">
                                        <input type="hidden" name="cylinder_id" value="<?php echo $c['id']; ?>">
                                        <input type="hidden" name="vendor_id" value="<?php echo $svc_data['vendor_id']; ?>">
                                        <input type="hidden" name="service_id" value="<?php echo $svc_data['id']; ?>">
                                        <button type="submit" class="btn-secondary" style="padding:0.25rem 0.55rem;font-size:0.72rem;border-radius:6px;" title="Dispatch from warehouse to vendor">To Vendor</button>
                                    </form>
                                <?php elseif ($svc_data['status'] === 'sent_to_vendor'): ?>
                                    <span style="font-size:0.68rem;color:#92400e;font-weight:700;padding:2px 0;">At Vendor</span>
                                <?php elseif ($svc_data['status'] === 'filled_from_vendor'): ?>
                                    <span style="font-size:0.68rem;color:#6b21a8;font-weight:700;padding:2px 0;">Filled</span>
                                <?php elseif ($svc_data['status'] === 'returned_to_customer'): ?>
                                    <span style="font-size:0.68rem;color:#3730a3;font-weight:700;padding:2px 0;">✓ Done</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <button class="btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; border-radius: 6px;"
                                    onclick="openCylinderTrackModal(<?php echo htmlspecialchars(json_encode($c)); ?>)">
                                Track
                            </button>
                            <button class="btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; border-radius: 6px;"
                                    onclick="openUpdateModal(<?php echo htmlspecialchars(json_encode($c)); ?>)">
                                <?php echo __('cylinders.modify_status'); ?>
                            </button>
                            <button class="btn-danger" style="padding: 0.3rem 0.65rem; font-size: 0.76rem; border-radius: 6px; background: var(--danger); border: 1px solid var(--danger); color: white;"
                                    onclick="openDeleteModal(<?php echo htmlspecialchars(json_encode($c)); ?>)">
                                <?php echo __('cylinders.delete'); ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($cylinders)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 4rem 0; color: var(--admin-muted);">
                        <?php echo __('cylinders.no_data'); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Advanced Pagination -->
<div class="admin-card" id="cylinders-pagination" style="margin-top: 1.5rem; padding: 0.75rem 1.25rem;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.75rem;">
        <span style="font-size: 0.85rem; color: var(--admin-muted); white-space: nowrap;">
            Showing <strong><?= $total_count ? (($page - 1) * $per_page + 1) : 0 ?></strong>–<strong><?= min($page * $per_page, $total_count) ?></strong> of <strong><?= number_format($total_count) ?></strong> records
        </span>
        <?php if ($total_pages > 1): ?>
        <div style="display: flex; gap: 0.25rem; align-items: center; flex-wrap: wrap;">
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
               class="btn-secondary" style="padding: 0.3rem 0.7rem; font-size: 0.8rem; border-radius: 6px; <?= $page <= 1 ? 'opacity: 0.4; pointer-events: none;' : '' ?>">‹ Prev</a>
            <?php
            $window = 2;
            $start = max(1, $page - $window);
            $end   = min($total_pages, $page + $window);
            if ($start > 1):
                $qs = http_build_query(array_merge($_GET, ['page' => 1])); ?>
                <a href="?<?= $qs ?>" class="btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; border-radius: 6px;">1</a>
                <?php if ($start > 2): ?><span style="padding: 0 0.2rem; color: var(--admin-muted); font-weight: 700;">…</span><?php endif;
            endif;
            for ($p = $start; $p <= $end; $p++):
                $qs = http_build_query(array_merge($_GET, ['page' => $p])); ?>
                <a href="?<?= $qs ?>"
                   class="btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; border-radius: 6px; <?= $p === $page ? 'background: var(--admin-accent); color: #fff; border-color: var(--admin-accent);' : '' ?>"><?= $p ?></a>
            <?php endfor;
            if ($end < $total_pages):
                if ($end < $total_pages - 1): ?><span style="padding: 0 0.2rem; color: var(--admin-muted); font-weight: 700;">…</span><?php endif;
                $qs = http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>
                <a href="?<?= $qs ?>" class="btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; border-radius: 6px;"><?= $total_pages ?></a>
            <?php endif; ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
               class="btn-secondary" style="padding: 0.3rem 0.7rem; font-size: 0.8rem; border-radius: 6px; <?= $page >= $total_pages ? 'opacity: 0.4; pointer-events: none;' : '' ?>">Next ›</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Update Status -->
<div class="modal" id="updateStatusModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('cylinders.modal.status_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="cylinder_id" id="status_cylinder_id">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('cylinders.modal.serial'); ?></label>
                <input type="text" id="status_serial" class="form-control" disabled style="font-weight: 700; color: var(--admin-accent);">
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('cylinders.modal.update_to'); ?></label>
                <select name="status" id="status_select" class="form-control" required onchange="toggleVendorSelect(this.value);togglePaymentSection(this.value);toggleRefillCostSection(this.value)">
                    <option value="empty"><?php echo __('cylinders.empty_in_warehouse'); ?></option>
                    <option value="filled"><?php echo __('cylinders.filled_in_warehouse'); ?></option>
                    <option value="sent_to_vendor"><?php echo __('cylinders.sent_for_refilling'); ?></option>
                    <option value="under_maintenance"><?php echo __('cylinders.under_maintenance'); ?></option>
                </select>
            </div>
            
            <div class="form-group" id="vendor_select_group" style="display: none;">
                <label class="form-label"><?php echo __('cylinders.modal.select_vendor'); ?></label>
                <select name="vendor_id" id="vendor_select" class="form-control">
                    <option value=""><?php echo __('cylinders.modal.choose_vendor'); ?></option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?> (Dispatched: <?php echo $v['active_refill_count']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="payment_select_group" style="display: none;padding:0.75rem;background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0;margin-bottom:0.75rem;">
                <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.5rem;color:#166534;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    Customer Settlement — Service Charge
                </div>
                <div class="form-group" style="margin-bottom:0.5rem;">
                    <label class="form-label">Service Charge from Customer (₹)</label>
                    <input type="number" step="0.01" name="payment_amount" class="form-control" value="0.00" placeholder="0.00">
                    <p style="font-size:0.75rem;color:#64748b;margin-top:4px;">Amount <strong>collected from the customer</strong> for this refill service.</p>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI / Online</option>
                        <option value="Bank Check">Bank Check</option>
                    </select>
                </div>
            </div>

            <div id="refill_cost_section" style="display:none;padding:0.75rem;background:#fff7ed;border-radius:10px;border:1px solid #fed7aa;margin-bottom:0.75rem;">
                <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.5rem;color:#9a3412;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline;vertical-align:middle;margin-right:6px;"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Vendor Refill Settlement
                </div>
                <p style="font-size:0.72rem;color:#92400e;margin-bottom:0.5rem;">Amount <strong>owed to the vendor</strong> for refilling. Required when returning directly from vendor to customer.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="font-size:0.78rem;">Vendor Refill Cost (₹/cyl)</label>
                        <input type="number" name="refill_cost_per_unit" id="single_refill_cost_per_unit" class="form-control" style="width:100%;" value="0.00" min="0" step="0.01" placeholder="Cost per cylinder" oninput="updateSingleRefillSummary()">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="font-size:0.78rem;">Vendor Invoice (optional)</label>
                        <input type="text" name="invoice_number" class="form-control" style="width:100%;" placeholder="e.g. AAP/2026/078">
                    </div>
                </div>
                <div id="singleRefillSummaryBar" style="display:none;margin:0.5rem 0;padding:0.4rem 0.6rem;background:#fff;border-radius:6px;border:1px solid #e5e7eb;font-size:0.8rem;">
                    <div style="display:flex;justify-content:space-between;padding:0.15rem 0;"><span>Gross (Vendor Cost):</span><span id="singleSummaryGross" style="font-weight:600;">₹0.00</span></div>
                    <div style="display:flex;justify-content:space-between;padding:0.15rem 0;"><span>Deduction:</span><span id="singleSummaryDeductions" style="font-weight:600;color:#dc2626;">−₹0.00</span></div>
                    <div style="display:flex;justify-content:space-between;padding:0.15rem 0;"><span>Addition:</span><span id="singleSummaryAdditions" style="font-weight:600;color:#16a34a;">+₹0.00</span></div>
                    <div style="display:flex;justify-content:space-between;padding:0.15rem 0;border-top:1px solid #e5e7eb;margin-top:0.15rem;font-weight:700;"><span>Net (Pay Vendor):</span><span id="singleSummaryNet">₹0.00</span></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem;margin-bottom:0.4rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="font-size:0.78rem;">Deduction (₹)</label>
                        <input type="number" name="deduction_amount" class="form-control" value="0.00" min="0" step="0.01" placeholder="0" oninput="updateSingleRefillSummary()" style="font-size:0.82rem;">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="font-size:0.78rem;">Addition (₹)</label>
                        <input type="number" name="addition_amount" class="form-control" value="0.00" min="0" step="0.01" placeholder="0" oninput="updateSingleRefillSummary()" style="font-size:0.82rem;">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem;margin-bottom:0.5rem;">
                    <input type="text" name="deduction_notes" class="form-control" placeholder="Damage, shortage reason..." style="font-size:0.78rem;padding:0.35rem 0.5rem;">
                    <input type="text" name="addition_notes" class="form-control" placeholder="Transport, extra charges..." style="font-size:0.78rem;padding:0.35rem 0.5rem;">
                </div>
                <div style="font-weight:600;font-size:0.8rem;margin-bottom:0.35rem;color:#9a3412;">Settlement with Vendor</div>
                <div style="display:flex;gap:0.5rem;margin-bottom:0.5rem;">
                    <label style="flex:1;display:flex;align-items:center;gap:0.35rem;padding:0.35rem 0.5rem;background:#fff;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;font-size:0.8rem;transition:all 0.15s;">
                        <input type="radio" name="payment_option" value="credit" checked onchange="updateSingleRefillSummary()" style="accent-color:#f97316;">
                        <span style="font-weight:600;">On Credit</span>
                        <span style="font-size:0.7rem;color:#64748b;">Owe vendor later</span>
                    </label>
                    <label style="flex:1;display:flex;align-items:center;gap:0.35rem;padding:0.35rem 0.5rem;background:#fff;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;font-size:0.8rem;transition:all 0.15s;">
                        <input type="radio" name="payment_option" value="paid" onchange="toggleSingleSettlementFields()" style="accent-color:#f97316;">
                        <span style="font-weight:600;">Mark as Paid</span>
                        <span style="font-size:0.7rem;color:#64748b;">Pay now</span>
                    </label>
                </div>
                <div id="singlePaidFields" style="display:none;padding:0.5rem;background:#fff;border-radius:6px;border:1px solid #e5e7eb;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" style="font-size:0.78rem;">Payment Method <span style="color:#dc2626;">*</span></label>
                            <select name="paid_method" class="form-control" style="font-size:0.82rem;">
                                <option value="">-- Select --</option>
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer" selected>Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="NEFT">NEFT</option>
                                <option value="RTGS">RTGS</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" style="font-size:0.78rem;">Payment Date <span style="color:#dc2626;">*</span></label>
                            <input type="datetime-local" name="paid_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" style="font-size:0.82rem;">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;margin-top:0.35rem;">
                        <label class="form-label" style="font-size:0.78rem;">Reference / Tx ID</label>
                        <input type="text" name="paid_reference" class="form-control" placeholder="e.g. UPI ref, cheque no, bank tx ID" style="font-size:0.82rem;">
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Internal Status Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="<?php echo __('cylinders.modal.status_notes_placeholder'); ?>"></textarea>
            </div>
            
            <p style="font-size: 0.75rem; color: var(--admin-muted); margin-bottom: 1.5rem;">
                <?php echo __('cylinders.modal.rental_note'); ?>
            </p>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center;"><?php echo __('cylinders.modal.post_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Modal: Delete Cylinder -->
<div class="modal" id="deleteCylinderModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="color: var(--danger);"><?php echo __('cylinders.modal.delete_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('deleteCylinderModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_cylinder">
            <input type="hidden" name="cylinder_id" id="delete_cylinder_id">
            
            <p style="font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem; color: var(--admin-text);">
                You are about to permanently delete cylinder <strong id="delete_serial_label" style="color: var(--admin-accent);"></strong> (<span id="delete_gas_label"></span>) from the Prem Gas Solution system registry. This will purge related lifecycle dispatches, but historical refill invoices will remain intact.
            </p>
            
            <div class="form-group" style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem;">
                <label class="form-label" style="color: var(--danger); font-weight: 700;"><?php echo __('cylinders.modal.delete_confirm_label'); ?></label>
                <input type="text" name="confirm_serial" id="delete_confirm_serial" class="form-control" autocomplete="off" placeholder="<?php echo __('cylinders.modal.delete_placeholder'); ?>" required style="border-color: var(--danger);">
            </div>
            
            <button type="submit" id="delete_submit_btn" class="btn-danger" style="width: 100%; justify-content: center; opacity: 0.5;" disabled>
                <?php echo __('cylinders.modal.delete_btn'); ?>
            </button>
        </form>
    </div>
</div>



<script>
    const gasTypesData = <?php echo json_encode($pdo->query("SELECT id, name, refill_cost, size_refill_costs FROM gas_types")->fetchAll() ?: []); ?>;
    const gasTypesMap = {};
    gasTypesData.forEach(g => { gasTypesMap[g.id] = g; });
    let deleteTargetSerial = '';
    
    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }
    
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    
    function openDeleteModal(c) {
        document.getElementById('delete_cylinder_id').value = c.id;
        document.getElementById('delete_serial_label').textContent = c.serial_number;
        document.getElementById('delete_gas_label').textContent = (c.gas_name || 'Unknown') + ' (' + (c.size_capacity || '') + ')';
        deleteTargetSerial = c.serial_number;
        
        const input = document.getElementById('delete_confirm_serial');
        input.value = '';
        
        const btn = document.getElementById('delete_submit_btn');
        btn.disabled = true;
        btn.style.opacity = '0.5';
        
        openModal('deleteCylinderModal');
    }
    
    document.getElementById('delete_confirm_serial').addEventListener('input', function() {
        const btn = document.getElementById('delete_submit_btn');
        if (this.value.trim() === deleteTargetSerial) {
            btn.disabled = false;
            btn.style.opacity = '1';
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.5';
        }
    });
    
    function toggleVendorSelect(val) {
        const group = document.getElementById('vendor_select_group');
        const select = document.getElementById('vendor_select');
        if (val === 'sent_to_vendor') {
            group.style.display = 'block';
            select.required = true;
        } else {
            group.style.display = 'none';
            select.required = false;
            select.value = '';
        }
    }
    
    function togglePaymentSection(val) {
        const group = document.getElementById('payment_select_group');
        if (val === 'returned_to_consumer') {
            group.style.display = 'block';
        } else {
            group.style.display = 'none';
        }
    }
    
    function toggleRefillCostSection(val) {
        const group = document.getElementById('refill_cost_section');
        if (val === 'filled' || val === 'returned_to_consumer') {
            group.style.display = 'block';
            updateSingleRefillSummary();
        } else {
            group.style.display = 'none';
        }
    }
    
    function toggleSingleSettlementFields() {
        const isPaid = document.querySelector('input[name="payment_option"]:checked').value === 'paid';
        document.getElementById('singlePaidFields').style.display = isPaid ? 'block' : 'none';
    }
    
    function updateSingleRefillSummary() {
        const cost = parseFloat(document.getElementById('single_refill_cost_per_unit').value) || 0;
        const ded = parseFloat(document.querySelector('input[name="deduction_amount"]').value) || 0;
        const add = parseFloat(document.querySelector('input[name="addition_amount"]').value) || 0;
        const net = cost - ded + add;
        const bar = document.getElementById('singleRefillSummaryBar');
        if (cost > 0) {
            bar.style.display = 'block';
        } else {
            bar.style.display = 'none';
        }
        document.getElementById('singleSummaryGross').textContent = '\u20B9' + cost.toFixed(2);
        document.getElementById('singleSummaryDeductions').textContent = '−\u20B9' + ded.toFixed(2);
        document.getElementById('singleSummaryAdditions').textContent = '+\u20B9' + add.toFixed(2);
        document.getElementById('singleSummaryNet').textContent = '\u20B9' + net.toFixed(2);
    }

    function openUpdateModal(c) {
        document.getElementById('status_cylinder_id').value = c.id;
        document.getElementById('status_serial').value = c.serial_number;
        
        // Hide payment section initially; show refill cost section if cylinder is coming from vendor
        togglePaymentSection('');
        if (c.status === 'sent_to_vendor' && (c.is_customer_refill_cylinder == 1 || c.is_customer_refill_cylinder === '1')) {
            toggleRefillCostSection('returned_to_consumer');
        } else {
            toggleRefillCostSection('');
        }
        
        // Reset refill cost and pre-fill from gas type if cylinder is sent_to_vendor
        document.getElementById('single_refill_cost_per_unit').value = '0.00';
        if (c.status === 'sent_to_vendor' && gasTypesMap[c.gas_type_id]) {
            const gt = gasTypesMap[c.gas_type_id];
            let defaultCost = parseFloat(gt.refill_cost) || 0;
            if (gt.size_refill_costs && c.size_capacity) {
                try {
                    const refillMap = JSON.parse(gt.size_refill_costs);
                    if (refillMap[c.size_capacity] !== undefined) {
                        defaultCost = parseFloat(refillMap[c.size_capacity]) || 0;
                    }
                } catch(e) {}
            }
            document.getElementById('single_refill_cost_per_unit').value = defaultCost.toFixed(2);
        }
        
        // Rebuild options logically based on current status
        const select = document.getElementById('status_select');
        select.innerHTML = '';
        
        const optEmpty = new Option('Empty (In Warehouse)', 'empty');
        const optFilled = new Option('Filled (In Warehouse)', 'filled');
        const optSent = new Option('Sent to Vendor (For Refilling)', 'sent_to_vendor');
        const optMaint = new Option('Under Maintenance', 'under_maintenance');
        
        select.add(optEmpty);
        select.add(optFilled);
        select.add(optSent);
        select.add(optMaint);
        
        if (c.status === 'with_customer') {
            const optCurrent = new Option('WITH CUSTOMER (Restricted Manual Override)', 'with_customer');
            select.add(optCurrent);
            select.value = 'with_customer';
        } else if (c.status === 'borrowed_from_partner') {
            select.value = 'empty';
        } else {
            select.value = c.status;
        }
        
        // Handle vendor select pre-fill if already sent_to_vendor
        const vendorSelect = document.getElementById('vendor_select');
        if (c.status === 'sent_to_vendor') {
            vendorSelect.value = c.current_vendor_id || '';
            toggleVendorSelect('sent_to_vendor');
        } else {
            vendorSelect.value = '';
            toggleVendorSelect(c.status);
        }
        
        openModal('updateStatusModal');
    }
    
    function applyFilters() {
        const gasId = document.getElementById('filterGas').value;
        const status = document.getElementById('filterStatus').value;
        const search = document.getElementById('searchInput').value.trim();
        
        let url = 'cylinders.php?';
        if (search !== '') url += 'search=' + encodeURIComponent(search) + '&';
        if (parseInt(gasId) > 0) url += 'gas_id=' + gasId + '&';
        if (status !== '') url += 'status=' + status + '&';
        
        window.location.href = url.endsWith('&') ? url.slice(0, -1) : (url.endsWith('?') ? 'cylinders.php' : url);
    }
    
    function resetFilters() {
        window.location.href = 'cylinders.php';
    }
    
    // Auto-search with debounce
    var cylSearchTimeout;
    function doCylSearch() {
        clearTimeout(cylSearchTimeout);
        cylSearchTimeout = setTimeout(function() { applyFilters(); }, 700);
    }

    var cylSearchInput = document.getElementById('searchInput');
    var cylGasFilter = document.getElementById('filterGas');
    var cylStatusFilter = document.getElementById('filterStatus');

    if (cylSearchInput) {
        cylSearchInput.addEventListener('input', doCylSearch);
        cylSearchInput.setSelectionRange(cylSearchInput.value.length, cylSearchInput.value.length);
    }
    if (cylGasFilter) cylGasFilter.addEventListener('change', doCylSearch);
    if (cylStatusFilter) cylStatusFilter.addEventListener('change', doCylSearch);

    // Intercept search form submit
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        e.preventDefault();
        applyFilters();
    });

    // Dynamic Bulk Operations Javascript Logic
    function updateBulkRefillSummary() {
        // No-op — reserved for future refill cost estimation in the bulk panel
    }

    function toggleSelectAllCylinders(masterCb) {
        const checkboxes = document.querySelectorAll('.cylinder-select-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = masterCb.checked;
        });
        onCylinderCheckboxSelectionChange();
    }
    
    function onCylinderCheckboxSelectionChange() {
        const checkboxes = document.querySelectorAll('.cylinder-select-checkbox');
        const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        
        const panel = document.getElementById('bulkActionsPanel');
        const countSpan = document.getElementById('bulkSelectedCount');
        
        if (checkedCount > 0) {
            countSpan.innerText = checkedCount;
            countSpan.classList.remove('bulk-count-pulse');
            void countSpan.offsetWidth; // force reflow
            countSpan.classList.add('bulk-count-pulse');
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
            const masterSelect = document.getElementById('selectAllCylinders');
            if (masterSelect) masterSelect.checked = false;
        }
        updateBulkRefillSummary();
    }
    
    function clearBulkSelection() {
        const checkboxes = document.querySelectorAll('.cylinder-select-checkbox');
        checkboxes.forEach(cb => cb.checked = false);
        const masterSelect = document.getElementById('selectAllCylinders');
        if (masterSelect) masterSelect.checked = false;
        onCylinderCheckboxSelectionChange();
    }
    
    function toggleBulkSection(id, show) {
        document.getElementById(id).classList.toggle('visible', show);
    }
    
    function onBulkStatusChange(status) {
        toggleBulkSection('bulkFilledDetails', status === 'filled');
    }
    
    function prepareBulkFormCylinderIds() {
        const container = document.getElementById('bulkFormCylinderContainer');
        container.innerHTML = '';
        const checkboxes = document.querySelectorAll('.cylinder-select-checkbox');
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'bulk_cylinder_ids[]';
                hiddenInput.value = cb.value;
                container.appendChild(hiddenInput);
            }
        });
    }
    
    function submitBulkStatusUpdate() {
        const status = document.getElementById('bulkStatusSelect').value;
        
        if (!status) {
            alert('Please select a target status to apply.');
            return;
        }
        
        const checkboxes = document.querySelectorAll('.cylinder-select-checkbox');
        const cylIds = Array.from(checkboxes).filter(cb => cb.checked).map(cb => parseInt(cb.value));
        
        if (cylIds.length === 0) {
            alert('Please select at least one cylinder.');
            return;
        }
        
        const ctx = { target_status: status, vendor_id: 0, payment_amount: 0 };
        
        bulkOp.analyze(cylIds, 'status_update', ctx, window.location.href);
        bulkOp.confirmCallback = function(report, context) {
            prepareBulkFormCylinderIds();
            document.getElementById('bulkFormAction').value = 'bulk_update_status';
            document.getElementById('bulkFormStatus').value = status;
            document.getElementById('bulkFormNotes').value = document.getElementById('bulkNotesInput').value;
            document.getElementById('bulkFormRefillCost').value = document.getElementById('bulkRefillCostPerUnit').value;
            document.getElementById('bulkActionForm').submit();
        };
    }
    
    function openBulkDeleteModal() {
        const checkboxes = document.querySelectorAll('.cylinder-select-checkbox');
        const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        
        document.getElementById('bulkDeleteLabelCount').innerText = checkedCount;
        document.getElementById('bulk_delete_confirm_text').value = '';
        
        const btn = document.getElementById('bulk_delete_submit_btn');
        btn.disabled = true;
        btn.style.opacity = '0.5';
        
        openModal('bulkDeleteModal');
    }
    
    function onBulkDeleteConfirmInput(input) {
        const btn = document.getElementById('bulk_delete_submit_btn');
        if (input.value.trim() === 'DELETE') {
            btn.disabled = false;
            btn.style.opacity = '1';
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.5';
        }
    }
    
    // Action trigger for delete
    function submitBulkDeleteAction() {
        prepareBulkFormCylinderIds();
        document.getElementById('bulkFormAction').value = 'bulk_delete';
        document.getElementById('bulkFormDeleteConfirm').value = document.getElementById('bulk_delete_confirm_text').value;
        document.getElementById('bulkActionForm').submit();
    }
    
    function submitBulkDelete() {
        closeModal('bulkDeleteModal');
        const checkboxes = document.querySelectorAll('.cylinder-select-checkbox');
        const cylIds = Array.from(checkboxes).filter(cb => cb.checked).map(cb => parseInt(cb.value));
        
        if (cylIds.length === 0) {
            alert('Please select at least one cylinder.');
            return;
        }
        
        bulkOp.analyze(cylIds, 'delete', {}, window.location.href);
        bulkOp.confirmCallback = function(report, context) {
            submitBulkDeleteAction();
        };
    }
    
</script>

<!-- Modal: Bulk Delete Confirmation -->
<div class="modal" id="bulkDeleteModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="color: var(--danger);">⚠️ Bulk Cylinder Deletion</h3>
            <button class="modal-close" onclick="closeModal('bulkDeleteModal')">&times;</button>
        </div>
        <div>
            <p style="font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem; color: var(--admin-text);">
                You are about to permanently delete <strong id="bulkDeleteLabelCount" style="color: var(--danger);">0</strong> cylinders from the Prem Gas Solution system registry. 
                This action is irreversible and will delete all lifecycle tracking data associated with them.
            </p>
            
            <div class="form-group" style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem;">
                <label class="form-label" style="color: var(--danger); font-weight: 700;">To prevent accidental deletion, type "DELETE" below:</label>
                <input type="text" id="bulk_delete_confirm_text" class="form-control" autocomplete="off" placeholder='Type "DELETE"' oninput="onBulkDeleteConfirmInput(this)" style="border-color: var(--danger);">
            </div>
            
            <button type="button" id="bulk_delete_submit_btn" class="btn-danger" style="width: 100%; justify-content: center; opacity: 0.5;" disabled onclick="submitBulkDelete()">
                Permanently Delete Selected Cylinders
            </button>
        </div>
    </div>
</div>

<!-- Modal: Track Cylinder Details -->
<div class="modal" id="trackCylinderModal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3>Track Cylinder</h3>
            <button class="modal-close" onclick="closeModal('trackCylinderModal')">&times;</button>
        </div>
        <div id="trackCylinderResult" style="padding:0.5rem 0;">
            <div style="text-align:center;padding:2rem;color:var(--admin-muted);">Loading...</div>
        </div>
    </div>
</div>

<script>
let trackedCylinderData = null;

function openCylinderTrackModal(cyl) {
    trackedCylinderData = cyl;
    const resultDiv = document.getElementById('trackCylinderResult');
    resultDiv.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--admin-muted);">Loading...</div>';
    openModal('trackCylinderModal');

    fetch('track-cylinder.php?serial=' + encodeURIComponent(cyl.serial_number))
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                resultDiv.innerHTML = '<div style="padding:1rem;background:#fee2e2;color:#b91c1c;border-radius:8px;">' + data.error + '</div>';
                return;
            }
            const statusColors = {with_customer:'#f59e0b', empty:'#6b7280', filled:'#10b981', sent_to_vendor:'#3b82f6', under_maintenance:'#ef4444'};
            const sc = statusColors[data.status] || '#3b82f6';
            let ownerLabel = data.ownership_type === 'consumer_owned' ? 'Customer-Owned' : data.ownership_type === 'partner_owned' ? 'Partner-Owned' : data.ownership_type === 'vendor_owned' ? 'Vendor-Owned' : 'Company-Owned';
            resultDiv.innerHTML = `
                <div style="background:#f8fafc;border-radius:12px;padding:1.25rem;border:1px solid var(--admin-border);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                        <span style="font-weight:800;font-size:1.1rem;color:var(--admin-accent);">${data.serial_number}
                            ${data.ownership_type === 'partner_owned' ? '<span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(251,191,36,0.3);" title="Partner: ' + (data.partner_name || 'Unknown') + '">BR</span>' : data.ownership_type === 'consumer_owned' ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(59,130,246,0.3);" title="Belongs to: ' + (data.original_owner_name || 'Unknown') + '">CON</span>' : data.ownership_type === 'vendor_owned' ? '<span style="background:#e8d5f5;color:#6b21a8;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(147,51,234,0.3);" title="Vendor: ' + (data.vendor_name || 'Unknown') + '">VEN</span>' : '<span style="background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(16,185,129,0.3);">OWN</span>'}
                            ${data.is_customer_refill_cylinder ? '<span style="background:#fff7ed;color:#c2410c;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:800;margin-left:5px;border:1px solid rgba(234,88,12,0.3);" title="Customer Refill Service">CUS-R</span>' : ''}
                        </span>
                        <span style="background:${sc};color:#fff;padding:4px 10px;border-radius:6px;font-weight:700;font-size:0.8rem;">${data.status}</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;font-size:0.9rem;">
                        <div><span style="color:var(--admin-muted);font-weight:600;">Gas:</span> ${data.gas_name} (${data.size_capacity})</div>
                        <div><span style="color:var(--admin-muted);font-weight:600;">Owner:</span> ${ownerLabel}</div>
                        ${data.original_owner_name ? '<div style="grid-column:span 2;"><span style="color:var(--admin-muted);font-weight:600;">Belongs to Customer:</span> ' + data.original_owner_name + '</div>' : ''}
                        ${data.customer_name ? '<div style="grid-column:span 2;"><span style="color:var(--admin-muted);font-weight:600;">Currently With Customer:</span> ' + data.customer_name + '</div>' : ''}
                        ${data.vendor_name ? '<div><span style="color:var(--admin-muted);font-weight:600;">At Vendor:</span> ' + data.vendor_name + '</div>' : ''}
                        ${data.partner_name ? '<div><span style="color:var(--admin-muted);font-weight:600;">With Partner:</span> ' + data.partner_name + '</div>' : ''}
                        <div><span style="color:var(--admin-muted);font-weight:600;">Expiry:</span> ${data.expiry_date || 'N/A'}</div>
                        <div><span style="color:var(--admin-muted);font-weight:600;">Purchase:</span> ${data.purchase_date || 'N/A'}</div>
                    </div>
                    <div style="margin-top:1rem;padding-top:0.75rem;border-top:1px solid var(--admin-border);">
                        <div style="font-weight:700;font-size:0.85rem;color:var(--admin-muted);margin-bottom:0.5rem;">Recent History</div>
                        ${data.history && data.history.length > 0 ? data.history.map(h => 
                            '<div style="display:flex;justify-content:space-between;font-size:0.8rem;padding:0.35rem 0;border-bottom:1px solid rgba(0,0,0,0.04);">' +
                            '<span style="color:var(--admin-muted);">' + (h.date || '') + '</span>' +
                            '<span style="font-weight:600;">' + (h.type || '') + '</span>' +
                            '<span style="color:var(--admin-muted);text-align:right;">' + (h.notes || '') + '</span>' +
                            '</div>'
                        ).join('') : '<div style="font-size:0.8rem;color:var(--admin-muted);text-align:center;padding:0.5rem;">No history available.</div>'}
                    </div>
                </div>
                <div style="display:flex;gap:0.75rem;margin-top:1.25rem;">
                    <button class="btn-primary" style="flex:1;justify-content:center;" onclick="closeModal('trackCylinderModal');openUpdateModal(trackedCylinderData);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Modify Status
                    </button>
                    ${data.customer_name ? '<a href="customer-profile.php?id=' + (trackedCylinderData.current_customer_id || '') + '" class="btn-secondary" style="flex:1;justify-content:center;text-decoration:none;" onclick="closeModal(\'trackCylinderModal\')">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' +
                        'Customer Profile' +
                    '</a>' : ''}
                </div>
            `;
        })
        .catch(() => {
            resultDiv.innerHTML = '<div style="padding:1rem;background:#fee2e2;color:#b91c1c;border-radius:8px;">Error fetching cylinder data.</div>';
        });
}
</script>

<!-- Hidden forms for bulk actions -->
<form id="bulkActionForm" method="POST" style="display: none;"><?php csrfField(); ?>
    <input type="hidden" name="action" id="bulkFormAction" value="">
    <input type="hidden" name="status" id="bulkFormStatus" value="">
    <input type="hidden" name="notes" id="bulkFormNotes" value="">
    <input type="hidden" name="refill_cost_per_unit" id="bulkFormRefillCost" value="">
    <input type="hidden" name="confirm_delete_text" id="bulkFormDeleteConfirm" value="">
    <div id="bulkFormCylinderContainer"></div>
</form>

<!-- Status Legend Tooltip -->
<div id="statusLegend" style="display:none;position:fixed;z-index:9999;background:#fff;border:1px solid var(--admin-border);border-radius:12px;padding:1.25rem;box-shadow:0 10px 30px rgba(0,0,0,0.12);max-width:280px;font-size:0.85rem;">
    <div style="font-weight:800;margin-bottom:0.75rem;font-size:0.9rem;">Cylinder Status Guide</div>
    <div style="display:flex;flex-direction:column;gap:0.5rem;">
        <div><span class="badge-filled" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;"></span><strong>Filled</strong> — Cylinder is full and available</div>
        <div><span class="badge-empty" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;"></span><strong>Empty</strong> — Cylinder is empty, awaiting refill</div>
        <div><span class="badge-with-customer" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;"></span><strong>With Customer</strong> — Cylinder currently deployed to customer</div>
        <div><span class="badge-sent-to-vendor" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;"></span><strong>Sent to Vendor</strong> — Sent to vendor for filling/maintenance</div>
        <div><span class="badge-under-maintenance" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;"></span><strong>Under Maintenance</strong> — Cylinder is being serviced</div>
        <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;background:#e0e7ff;"></span><strong>Returned to Consumer</strong> — Cylinder returned to customer (CUS-R completed)</div>
    </div>
</div>
<script>
function toggleStatusLegend(e) {
    e.stopPropagation();
    var el = document.getElementById('statusLegend');
    if (el.style.display === 'block') { el.style.display = 'none'; return; }
    var rect = e.target.getBoundingClientRect();
    el.style.top = (rect.bottom + 8) + 'px';
    el.style.left = Math.max(8, rect.left - 100) + 'px';
    el.style.display = 'block';
    document.addEventListener('click', closeStatusLegend, { once: true });
}
function closeStatusLegend() { document.getElementById('statusLegend').style.display = 'none'; }
</script>

<?php require_once __DIR__ . '/bulk_operation_dialog.php'; ?>
<?php
require_once 'layout_footer.php';
?>
