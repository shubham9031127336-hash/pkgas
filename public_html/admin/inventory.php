<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('inventory.title');
$active_menu = "inventory";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once 'inventory-utils.php';
require_once 'notifications.php';
require_once 'gst_helper.php';
require_once __DIR__ . '/expense-utils.php';
sendLowStockAlert($pdo);
sendExpiryReminders($pdo);
runConsumerCylinderMigrations($pdo);
runProductMigrations($pdo);
runProductPurchasePriceMigration($pdo);
runCylinderPurchasePriceMigration($pdo);
runCylinderSupplierMigrations($pdo);
runSupplierTypeMigration($pdo);

$message = '';
$error = '';

// Handle newly purchased cylinders registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_cylinders') {
    $gas_type_id = intval($_POST['gas_type_id'] ?? 0);
    $size_capacity = trim($_POST['size_capacity'] ?? '');
    $status = $_POST['status'] ?? 'empty';
    $purchase_date_raw = $_POST['purchase_date'] ?? date('Y-m-d\TH:i');
    $purchase_date = str_replace('T', ' ', $purchase_date_raw) . ':00';
    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $serials_raw = trim($_POST['serials_bulk'] ?? '');

    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $gst_rate = floatval($_POST['gst_rate'] ?? 0);

    // Parse serials (one per line or comma-separated)
    $serials = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $serials_raw)))));

    if ($gas_type_id <= 0 || empty($size_capacity)) {
        $error = __('inventory.error_gas_size');
    } elseif (empty($serials)) {
        $error = __('inventory.error_serials');
    } else {
        // Pre-check: find serials that already exist in the system
        $placeholders = implode(',', array_fill(0, count($serials), '?'));
        $check = $pdo->prepare("SELECT serial_number FROM cylinders WHERE serial_number IN ($placeholders)");
        $check->execute($serials);
        $existing = $check->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($existing)) {
            $error = __('inventory.error_duplicates') . implode(', ', $existing);
        } else {
            try {
                $pdo->beginTransaction();

                $added = 0;
                $purchase_id = null;
                $expiry_date = date('Y-m-d', strtotime('+5 years', strtotime($purchase_date)));

                // If supplier is selected, create purchase record first
                if ($supplier_id > 0) {
                    $subtotal = count($serials) * $purchase_price;
                    $gst_calc = calculateGST($subtotal, $gst_rate);

                    $stmt = $pdo->prepare("INSERT INTO cylinder_purchases (supplier_id, invoice_number, invoice_date, subtotal, gst_rate, cgst, sgst, igst, grand_total, cylinder_count, payment_status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
                    $stmt->execute([
                        $supplier_id, $invoice_number ?: null, substr($purchase_date, 0, 10),
                        $gst_calc['taxable'], $gst_rate, $gst_calc['cgst'], $gst_calc['sgst'], $gst_calc['igst'],
                        $gst_calc['total'], count($serials),
                        "Registered via inventory ($status)", $_SESSION['user_name'] ?? 'system'
                    ]);
                    $purchase_id = $pdo->lastInsertId();
                }

                foreach ($serials as $serial) {
                    $stmt = $pdo->prepare("
                        INSERT INTO cylinders (serial_number, gas_type_id, size_capacity, status, purchase_date, purchase_price, expiry_date, supplier_id, purchase_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$serial, $gas_type_id, $size_capacity, $status, $purchase_date, $purchase_price, $expiry_date, $supplier_id > 0 ? $supplier_id : null, $purchase_id]);

                    $cylinder_id = $pdo->lastInsertId();

                    // Link cylinder to purchase items if purchase exists
                    if ($purchase_id) {
                        $unit_gst = $gst_rate > 0 ? round($purchase_price * $gst_rate / 100, 2) : 0;
                        $stmt2 = $pdo->prepare("INSERT INTO cylinder_purchase_items (purchase_id, cylinder_id, serial_number, gas_type_id, size_capacity, unit_price, gst_amount, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt2->execute([$purchase_id, $cylinder_id, $serial, $gas_type_id, $size_capacity, $purchase_price, $unit_gst, $purchase_price + $unit_gst]);
                    }

                    logCylinderTransaction($pdo, $cylinder_id, null, null, 'maintenance', "Registered cylinder with status $status" . ($supplier_id > 0 ? " (supplier #$supplier_id)" : ""));

                    $added++;
                }

                // If supplier is selected, create ledger and GST entries
                if ($purchase_id) {
                    $stmt = $pdo->prepare("SELECT running_balance FROM supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$supplier_id]);
                    $last_bal = floatval($stmt->fetchColumn());

                    $stmt = $pdo->prepare("SELECT grand_total FROM cylinder_purchases WHERE id = ?");
                    $stmt->execute([$purchase_id]);
                    $grand_total = floatval($stmt->fetchColumn());

                    $new_balance = $last_bal + $grand_total;
                    $stmt = $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, transaction_date, transaction_type, debit, credit, running_balance, reference_type, reference_id, remarks, created_by) VALUES (?, NOW(), 'purchase', ?, 0, ?, 'purchase', ?, ?, ?)");
                    $stmt->execute([$supplier_id, $grand_total, $new_balance, $purchase_id, "Purchase of $added cylinders" . ($invoice_number ? " (Invoice: $invoice_number)" : ""), $_SESSION['user_name'] ?? 'system']);

                    if ($gst_rate > 0) {
                        $stmt = $pdo->prepare("SELECT grand_total, subtotal, cgst, sgst, igst FROM cylinder_purchases WHERE id = ?");
                        $stmt->execute([$purchase_id]);
                        $p = $stmt->fetch();
                        recordInputGST($pdo, [
                            'entity_type' => 'supplier',
                            'entity_id' => $supplier_id,
                            'gst_rate' => $gst_rate,
                            'taxable_amount' => floatval($p['subtotal']),
                            'gst_amount' => floatval($p['cgst'] + $p['sgst'] + $p['igst']),
                            'cgst' => floatval($p['cgst']),
                            'sgst' => floatval($p['sgst']),
                            'igst' => floatval($p['igst']),
                            'reference_type' => 'cylinder_purchase',
                            'reference_id' => $purchase_id,
                            'transaction_date' => substr($purchase_date, 0, 10),
                    ]);
                        }
                    }

                    // Auto-create expense for cylinder purchase
                if ($purchase_id && function_exists('resolveSystemCategory')) {
                    try {
                        $expense_cat_id = resolveSystemCategory($pdo, 'Cylinder Purchase');
                            if ($expense_cat_id > 0) {
                                // Guard: skip if expense already exists for this purchase
                                $stmt_check = $pdo->prepare("SELECT id FROM expenses WHERE reference_type = 'cylinder_purchase' AND reference_id = ? AND is_deleted = 0 LIMIT 1");
                                $stmt_check->execute([$purchase_id]);
                                if (!$stmt_check->fetch()) {
                                $stmt_sup = $pdo->prepare("SELECT company_name FROM cylinder_suppliers WHERE id = ?");
                                $stmt_sup->execute([$supplier_id]);
                                $supplier_name = $stmt_sup->fetchColumn() ?: '';
                                autoCreateExpense($pdo, [
                                    'category_id' => $expense_cat_id,
                                    'vendor_id' => null,
                                    'vendor_name' => $supplier_name,
                                    'expense_date' => substr($purchase_date, 0, 10),
                                    'amount' => $gst_calc['taxable'],
                                    'gst_rate' => $gst_rate,
                                    'taxable_amount' => $gst_calc['taxable'],
                                    'cgst_amount' => $gst_calc['cgst'],
                                    'sgst_amount' => $gst_calc['sgst'],
                                    'igst_amount' => $gst_calc['igst'],
                                    'gst_total' => $gst_calc['cgst'] + $gst_calc['sgst'] + $gst_calc['igst'],
                                    'total_amount' => $gst_calc['total'],
                                    'payment_method' => 'Bank Transfer',
                                    'payment_status' => 'unpaid',
                                    'notes' => 'Auto-created from cylinder registration #' . $purchase_id,
                                    'reference_type' => 'cylinder_purchase',
                                    'reference_id' => $purchase_id,
                                    'reference_number' => $invoice_number ?: 'PUR-' . $purchase_id,
                                    'created_by_name' => $_SESSION['user_name'] ?? 'system',
                                ]);
                            }
                            }
                        } catch (Exception $ex) {
                            error_log("Auto-create expense for purchase #$purchase_id failed: " . $ex->getMessage());
                        }
                    }

                    $pdo->commit();

                // Auto sync aggregates
                syncInventory($pdo);

                $message = sprintf(__('inventory.registered'), $added);
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = __('inventory.register_failed') . $e->getMessage();
            }
        }
    }
}

// Handle Add Product Stock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product_stock') {
    $product_id = intval($_POST['product_id'] ?? 0);
    $add_qty = intval($_POST['add_qty'] ?? 0);
    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $selling_price_input = $_POST['selling_price'] ?? '';
    $threshold_input = $_POST['alert_threshold'] ?? '';
    $prod_supplier_id = intval($_POST['prod_supplier_id'] ?? 0);
    $prod_gst_rate = floatval($_POST['prod_gst_rate'] ?? 0);
    $prod_invoice = trim($_POST['prod_invoice'] ?? '');

    if ($product_id <= 0 || $add_qty <= 0) {
        $error = "Please select a product and enter a valid quantity.";
    } else {
        try {
            $pdo->beginTransaction();

            $sets = "stock_quantity = stock_quantity + ?, purchase_price = ?";
            $params = [$add_qty, $purchase_price, $product_id];
            if ($selling_price_input !== '' && floatval($selling_price_input) >= 0) {
                $sets .= ", price = ?";
                array_splice($params, -1, 0, [floatval($selling_price_input)]);
            }
            if ($threshold_input !== '' && intval($threshold_input) >= 0) {
                $sets .= ", min_alert_threshold = ?";
                array_splice($params, -1, 0, [intval($threshold_input)]);
            }
            $stmt = $pdo->prepare("UPDATE products SET $sets WHERE id = ?");
            $stmt->execute($params);

            // If supplier is selected, record in supplier_ledger and gst_ledger
            if ($prod_supplier_id > 0) {
                $subtotal = $add_qty * $purchase_price;
                $gst_calc = calculateGST($subtotal, $prod_gst_rate);

                // Create purchase record
                $stmt = $pdo->prepare("INSERT INTO cylinder_purchases (supplier_id, invoice_number, invoice_date, subtotal, gst_rate, cgst, sgst, igst, grand_total, cylinder_count, payment_status, notes, created_by) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, 0, 'pending', ?, ?)");
                $stmt->execute([
                    $prod_supplier_id, $prod_invoice ?: null,
                    $gst_calc['taxable'], $prod_gst_rate, $gst_calc['cgst'], $gst_calc['sgst'], $gst_calc['igst'],
                    $gst_calc['total'],
                    "Product stock addition: $add_qty x product #$product_id", $_SESSION['user_name'] ?? 'system'
                ]);
                $purchase_id = $pdo->lastInsertId();

                // Supplier ledger entry
                $stmt = $pdo->prepare("SELECT running_balance FROM supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$prod_supplier_id]);
                $last_bal = floatval($stmt->fetchColumn());
                $new_balance = $last_bal + $gst_calc['total'];

                $stmt = $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, transaction_date, transaction_type, debit, credit, running_balance, reference_type, reference_id, remarks, created_by) VALUES (?, NOW(), 'purchase', ?, 0, ?, 'product_purchase', ?, ?, ?)");
                $stmt->execute([$prod_supplier_id, $gst_calc['total'], $new_balance, $purchase_id, "Product stock: $add_qty x " . ($prod_invoice ? "Invoice: $prod_invoice" : "product #$product_id"), $_SESSION['user_name'] ?? 'system']);

                // GST ledger entry
                if ($prod_gst_rate > 0) {
                    recordInputGST($pdo, [
                        'entity_type' => 'supplier',
                        'entity_id' => $prod_supplier_id,
                        'gst_rate' => $prod_gst_rate,
                        'taxable_amount' => $gst_calc['taxable'],
                        'gst_amount' => $gst_calc['gst_amount'],
                        'cgst' => $gst_calc['cgst'],
                        'sgst' => $gst_calc['sgst'],
                        'igst' => $gst_calc['igst'],
                        'reference_type' => 'product_purchase',
                        'reference_id' => $purchase_id,
                        'transaction_date' => date('Y-m-d'),
                    ]);
                }
            }

            $pdo->commit();
            $message = "Stock added successfully." . ($prod_supplier_id > 0 ? " Purchase recorded." : "");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Failed to add stock: " . $e->getMessage();
        }
    }
}

// Handle Edit Product Price
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_product_price') {
    $product_id = intval($_POST['prod_id'] ?? 0);
    $purchase_price = floatval($_POST['edit_purchase_price'] ?? 0);
    $selling_price = floatval($_POST['edit_selling_price'] ?? 0);

    if ($product_id <= 0) {
        $error = "Invalid product.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE products SET purchase_price = ?, price = ? WHERE id = ?");
            $stmt->execute([$purchase_price, $selling_price, $product_id]);
            $message = "Product pricing updated successfully.";
        } catch (PDOException $e) {
            $error = "Failed to update pricing: " . $e->getMessage();
        }
    }
}

// Handle Update Cylinder Prices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_cylinder_prices') {
    $gas_type_id = intval($_POST['cyl_gas_type_id'] ?? 0);
    $size_capacity = trim($_POST['cyl_size_capacity'] ?? '');
    $cyl_price = floatval($_POST['cyl_purchase_price'] ?? 0);

    if ($gas_type_id <= 0 || empty($size_capacity)) {
        $error = "Please select gas type and size.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE cylinders SET purchase_price = ? WHERE gas_type_id = ? AND size_capacity = ?");
            $stmt->execute([$cyl_price, $gas_type_id, $size_capacity]);
            $updated = $stmt->rowCount();
            $message = "Purchase price updated for $updated cylinder(s).";
        } catch (PDOException $e) {
            $error = "Failed to update cylinder prices: " . $e->getMessage();
        }
    }
}

// Fetch all gas types for inputs
$gas_types = [];
try {
    $gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

// Build gas sizes lookup from normalized table
$gas_sizes_map = [];
try {
    $gs_stmt = $pdo->query("SELECT gas_type_id, size_capacity FROM gas_sizes ORDER BY sort_order ASC, id ASC");
    while ($gs = $gs_stmt->fetch()) {
        $gid = $gs['gas_type_id'];
        if (!isset($gas_sizes_map[$gid])) {
            $gas_sizes_map[$gid] = [];
        }
        $gas_sizes_map[$gid][] = $gs['size_capacity'];
    }
} catch (PDOException $e) {}

// Fetch active suppliers (cylinder + product)
$suppliers = [];
$product_suppliers = [];
try {
    $suppliers = $pdo->query("SELECT id, company_name, gst_number FROM cylinder_suppliers WHERE status = 'active' AND supplier_type IN ('cylinder','both') ORDER BY company_name ASC")->fetchAll();
    $product_suppliers = $pdo->query("SELECT id, company_name, gst_number FROM cylinder_suppliers WHERE status = 'active' AND supplier_type IN ('product','both') ORDER BY company_name ASC")->fetchAll();
} catch (PDOException $e) {}

// Fetch aggregated inventory stock
$inventory = [];
try {
    $stmt = $pdo->query("
        SELECT i.*, g.name as gas_name, g.chemical_formula 
        FROM inventory i 
        JOIN gas_types g ON i.gas_type_id = g.id 
        WHERE i.total_stock > 0
        ORDER BY g.name ASC, i.size_capacity ASC
    ");
    $inventory = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Inventory empty or tables not set up yet.";
}

// Fetch products with category
$products = [];
$product_categories = [];
try {
    $products = $pdo->query("SELECT p.*, pc.name AS category_name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id ORDER BY p.name ASC")->fetchAll();
    $product_categories = $pdo->query("SELECT * FROM product_categories ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('inventory.heading'); ?></h2>
        <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('inventory.subtitle'); ?></p>
    </div>
    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <button class="btn-secondary" onclick="openModal('updateCylinderPriceModal')" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>
            Cylinder Prices
        </button>
        <button class="btn-primary" onclick="openModal('registerCylinderModal')">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php echo __('inventory.register'); ?>
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert-banner" style="background: var(--success-soft); color: var(--success); border-color: #a7f3d0;">
        <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-banner" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5;">
        <strong>Notice:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Inventory Aggregation Grid -->
<div class="admin-card" style="padding: 0;">
    <div class="table-wrapper" style="border: none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('inventory.gas'); ?></th>
                    <th><?php echo __('inventory.size'); ?></th>
                    <th style="text-align: center;"><?php echo __('inventory.total'); ?></th>
                    <th style="text-align: center;"><?php echo __('inventory.filled'); ?></th>
                    <th style="text-align: center;"><?php echo __('inventory.empty_stock'); ?></th>
                    <th style="text-align: center;"><?php echo __('inventory.with_customer'); ?></th>
                    <th style="text-align: center;"><?php echo __('inventory.sent_to_vendor'); ?></th>
                    <th style="text-align: center;"><?php echo __('inventory.maintenance'); ?></th>
                    <th><?php echo __('inventory.alert'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventory as $inv): ?>
                <?php 
                    $is_low = $inv['filled_stock'] <= $inv['min_alert_threshold'];
                ?>
                <tr style="<?php echo $is_low ? 'background: #fffbeb;' : ''; ?>">
                    <td style="font-weight: 700; color: var(--admin-fg);" data-label="<?php echo __('inventory.gas'); ?>">
                        <?php echo htmlspecialchars($inv['gas_name']); ?> 
                        <span style="font-size: 0.75rem; color: var(--admin-muted); font-weight: 500;">(<?php echo htmlspecialchars($inv['chemical_formula']); ?>)</span>
                    </td>
                    <td style="font-weight: 600; color: var(--admin-fg);" data-label="<?php echo __('inventory.size'); ?>"><?php echo htmlspecialchars($inv['size_capacity']); ?></td>
                    <td style="text-align: center; font-weight: 700;" data-label="<?php echo __('inventory.total'); ?>"><?php echo $inv['total_stock']; ?></td>
                    <td style="text-align: center; font-weight: 800; color: <?php echo $is_low ? 'var(--warning)' : 'var(--success)'; ?>;" data-label="<?php echo __('inventory.filled'); ?>">
                        <?php echo $inv['filled_stock']; ?>
                    </td>
                    <td style="text-align: center; font-weight: 600; color: var(--admin-muted);" data-label="<?php echo __('inventory.empty_stock'); ?>"><?php echo $inv['empty_stock']; ?></td>
                    <td style="text-align: center; font-weight: 600; color: var(--info);" data-label="<?php echo __('inventory.with_customer'); ?>"><?php echo $inv['with_customer_stock']; ?></td>
                    <td style="text-align: center; font-weight: 600; color: #a855f7;" data-label="<?php echo __('inventory.sent_to_vendor'); ?>"><?php echo $inv['sent_to_vendor_stock']; ?></td>
                    <td style="text-align: center; font-weight: 600; color: var(--danger);" data-label="<?php echo __('inventory.maintenance'); ?>"><?php echo $inv['maintenance_stock']; ?></td>
                    <td data-label="<?php echo __('inventory.alert'); ?>">
                        <?php if ($is_low): ?>
                            <span class="badge badge-empty" style="font-size: 0.65rem;"><?php echo __('inventory.low_stock'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-filled" style="font-size: 0.65rem;"><?php echo __('inventory.good_stock'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($inventory)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 4rem 0; color: var(--admin-muted);">
                        <?php echo __('inventory.no_data'); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Product Stock Section -->
<div style="margin-top:3rem;margin-bottom:2rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
        <div>
            <h3 style="font-size:1.35rem;font-weight:800;">Product Stock</h3>
            <p style="color:var(--admin-muted);font-size:0.85rem;margin-top:0.15rem;">Valves, masks, keys, regulators &amp; other non-gas items.</p>
        </div>
        <div style="display:flex;gap:0.5rem;">
            <a href="gas-types.php" class="btn-secondary" style="text-decoration:none;padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">
                Manage Products
            </a>
            <button class="btn-primary" onclick="openModal('addProductStockModal')" style="padding:0.5rem 1rem;border-radius:8px;font-size:0.85rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:middle;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Stock
            </button>
        </div>
    </div>

    <div class="admin-card" style="padding:0;">
        <div class="table-wrapper" style="border:none;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>SKU</th>
                        <th>Purchase Price</th>
                        <th>Selling Price</th>
                        <th style="text-align:center;">Stock</th>
                        <th style="text-align:center;">Reorder</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <?php $is_low = intval($p['reorder_point']) > 0 ? intval($p['stock_quantity']) <= intval($p['reorder_point']) : intval($p['stock_quantity']) <= intval($p['min_alert_threshold']); ?>
                    <tr style="<?php echo $is_low ? 'background:#fffbeb;' : ''; ?>">
                        <td style="font-weight:700;"><?php echo htmlspecialchars($p['name']); ?></td>
                        <td style="font-size:0.85rem;"><?php echo htmlspecialchars($p['category_name'] ?: '—'); ?></td>
                        <td style="font-family:monospace;font-size:0.8rem;"><?php echo htmlspecialchars($p['sku'] ?: '—'); ?></td>
                        <td style="font-weight:600;">₹<?php echo number_format($p['purchase_price'] ?? 0, 2); ?>/<?php echo htmlspecialchars($p['unit'] ?: 'piece'); ?></td>
                        <td style="font-weight:600;color:var(--admin-accent);">₹<?php echo number_format($p['price'], 2); ?>/<?php echo htmlspecialchars($p['unit'] ?: 'piece'); ?></td>
                        <td style="text-align:center;font-weight:700;"><?php echo intval($p['stock_quantity']); ?></td>
                        <td style="text-align:center;font-size:0.85rem;"><?php echo intval($p['reorder_point'] ?: $p['min_alert_threshold']); ?></td>
                        <td style="text-align:center;">
                            <?php if ($is_low): ?>
                                <span class="badge badge-empty" style="font-size:0.65rem;">LOW STOCK</span>
                            <?php else: ?>
                                <span class="badge badge-filled" style="font-size:0.65rem;">IN STOCK</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <button class="btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.75rem;border-radius:6px;"
                                    onclick="openEditPriceModal(<?php echo htmlspecialchars(json_encode($p)); ?>)">Edit Price</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:3rem 1rem;color:var(--admin-muted);font-size:0.9rem;">
                            <div style="font-size:2rem;margin-bottom:0.5rem;">📦</div>
                            No products yet. <a href="gas-types.php" style="color:var(--admin-accent);font-weight:700;">Add products</a> to track inventory.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Register Stock -->
<div class="modal" id="registerCylinderModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('inventory.modal.title'); ?></h3>
            <button class="modal-close" onclick="closeModal('registerCylinderModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="register_cylinders">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('inventory.modal.gas_type'); ?></label>
                <select name="gas_type_id" id="registerGasSelect" class="form-control" required onchange="handleRegisterGasChange()">
                    <?php foreach ($gas_types as $gt): ?>
                        <?php $inv_sizes_csv = isset($gas_sizes_map[$gt['id']]) ? implode(',', $gas_sizes_map[$gt['id']]) : '10L,40L,47L'; ?>
                        <option value="<?php echo $gt['id']; ?>" data-sizes="<?php echo htmlspecialchars($inv_sizes_csv); ?>">
                            <?php echo htmlspecialchars($gt['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('inventory.modal.capacity'); ?></label>
                    <select name="size_capacity" id="registerSizeSelect" class="form-control" required>
                        <!-- Dynamically populated in JavaScript -->
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo __('inventory.modal.status'); ?></label>
                    <select name="status" class="form-control">
                        <option value="empty"><?php echo __('inventory.modal.empty'); ?></option>
                        <option value="filled"><?php echo __('inventory.modal.filled'); ?></option>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label class="form-label"><?php echo __('inventory.modal.purchase_date'); ?></label>
                    <input type="datetime-local" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Purchase Price (₹) per cylinder</label>
                    <input type="number" step="0.01" name="purchase_price" id="registerPurchasePrice" class="form-control" value="0.00" min="0" placeholder="Cost per cylinder" oninput="updateGstPreview()">
                </div>
            </div>

            <div style="background:var(--admin-card);border:1px solid var(--admin-border);border-radius:8px;padding:1rem;margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                    <span style="font-weight:700;">Supplier & GST (Optional)</span>
                    <label style="font-size:0.8rem;display:flex;align-items:center;gap:0.35rem;">
                        <input type="checkbox" id="toggleSupplierFields" onchange="toggleSupplierSection()"> Add purchase tracking
                    </label>
                </div>
                <div id="supplierFields" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" id="registerSupplierSelect" class="form-control" onchange="updateGstPreview()">
                            <option value="">-- No supplier --</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>" data-gst="<?php echo htmlspecialchars($s['gst_number']); ?>">
                                <?php echo htmlspecialchars($s['company_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                        <div class="form-group">
                            <label class="form-label">Invoice No.</label>
                            <input type="text" name="invoice_number" class="form-control" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label class="form-label">GST Rate</label>
                            <select name="gst_rate" id="registerGstRate" class="form-control" onchange="updateGstPreview()">
                                <option value="0">No GST</option>
                                <option value="5">GST 5%</option>
                                <option value="12">GST 12%</option>
                                <option value="18">GST 18%</option>
                                <option value="28">GST 28%</option>
                            </select>
                        </div>
                    </div>
                    <div id="gstPreview" style="display:none;margin-top:0.75rem;padding:0.75rem;background:#f9fafb;border-radius:6px;font-size:0.85rem;">
                        <div style="display:flex;justify-content:space-between;"><span>Taxable Amount:</span><span id="gstTaxable" style="font-weight:700;">₹0.00</span></div>
                        <div style="display:flex;justify-content:space-between;"><span>CGST (50%):</span><span id="gstCgst" style="font-weight:700;">₹0.00</span></div>
                        <div style="display:flex;justify-content:space-between;"><span>SGST (50%):</span><span id="gstSgst" style="font-weight:700;">₹0.00</span></div>
                        <hr style="margin:0.5rem 0;border-color:var(--admin-border);">
                        <div style="display:flex;justify-content:space-between;font-weight:800;"><span>Grand Total:</span><span id="gstGrandTotal" style="font-weight:800;">₹0.00</span></div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo __('inventory.modal.serials'); ?> <span style="color:var(--danger)">*</span></label>
                <p style="font-size: 0.8rem; color: var(--admin-muted); margin-bottom: 0.5rem;">
                    <?php echo __('inventory.modal.serials_help'); ?>
                </p>
                <textarea
                    name="serials_bulk"
                    id="serialsBulk"
                    class="form-control"
                    style="font-family: monospace; min-height: 180px; resize: vertical;"
                    placeholder="<?php echo __('inventory.modal.serials_placeholder'); ?>"
                    oninput="countSerials(); updateGstPreview(); scheduleDuplicateCheck()"
                    onblur="checkDuplicateSerials()"
                    rows="8"></textarea>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                    <span style="font-size: 0.8rem; color: var(--admin-muted);"><?php echo __('inventory.serial_count'); ?></span>
                    <span id="serialCount" style="font-size: 0.9rem; font-weight: 800; color: var(--admin-accent);">0</span>
                </div>
            </div>
            
            <div id="serialError" class="alert alert-error" style="display:none;margin-bottom:1rem;padding:0.75rem;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:6px;font-size:0.85rem;"></div>
            
            <p style="font-size: 0.75rem; color: var(--admin-muted); margin-bottom: 1.5rem; line-height: 1.4;">
                <?php echo __('inventory.each_5yr'); ?> <?php if (!empty($suppliers)): ?>Tick "Add purchase tracking" above to link this batch to a supplier and record GST.<?php endif; ?>
            </p>
            
            <button type="submit" id="registerCylinderBtn" class="btn-primary" style="width: 100%; justify-content: center;"><?php echo __('inventory.modal.register_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Modal: Add Product Stock -->
<div class="modal" id="addProductStockModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Product Stock</h3>
            <button class="modal-close" onclick="closeModal('addProductStockModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="add_product_stock">
            <div class="form-group">
                <label class="form-label">Product *</label>
                <select name="product_id" class="form-control" required>
                    <option value="">-- Choose product --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['unit'] ?: 'piece'); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Quantity to Add *</label>
                    <input type="number" name="add_qty" class="form-control" value="1" min="1" required oninput="updateProductGstPreview()">
                </div>
                <div class="form-group">
                    <label class="form-label">Purchase Price (₹) per unit</label>
                    <input type="number" step="0.01" name="purchase_price" id="prodPurchasePrice" class="form-control" value="0.00" min="0" oninput="updateProductGstPreview()">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Suggested Selling Price (₹)</label>
                    <input type="number" step="0.01" name="selling_price" class="form-control" value="" min="0" placeholder="Leave blank to keep current">
                </div>
                <div class="form-group">
                    <label class="form-label">Low Stock Alert Threshold</label>
                    <input type="number" name="alert_threshold" class="form-control" value="" min="0" placeholder="Leave blank to keep current">
                </div>
            </div>

            <div style="background:var(--admin-card);border:1px solid var(--admin-border);border-radius:8px;padding:1rem;margin-top:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                    <span style="font-weight:700;">Supplier & GST (Optional)</span>
                    <label style="font-size:0.8rem;display:flex;align-items:center;gap:0.35rem;">
                        <input type="checkbox" id="toggleProductSupplier" onchange="toggleProductSupplierSection()"> Track purchase
                    </label>
                </div>
                <div id="productSupplierFields" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="prod_supplier_id" id="prodSupplierSelect" class="form-control">
                            <option value="">-- No supplier --</option>
                            <?php foreach ($product_suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                        <div class="form-group">
                            <label class="form-label">Invoice No.</label>
                            <input type="text" name="prod_invoice" class="form-control" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label class="form-label">GST Rate</label>
                            <select name="prod_gst_rate" id="prodGstRate" class="form-control" onchange="updateProductGstPreview()">
                                <option value="0">No GST</option>
                                <option value="5">GST 5%</option>
                                <option value="12">GST 12%</option>
                                <option value="18">GST 18%</option>
                                <option value="28">GST 28%</option>
                            </select>
                        </div>
                    </div>
                    <div id="prodGstPreview" style="display:none;margin-top:0.75rem;padding:0.75rem;background:#f9fafb;border-radius:6px;font-size:0.85rem;">
                        <div style="display:flex;justify-content:space-between;"><span>Taxable Amount:</span><span id="prodGstTaxable" style="font-weight:700;">₹0.00</span></div>
                        <div style="display:flex;justify-content:space-between;"><span>CGST (50%):</span><span id="prodGstCgst" style="font-weight:700;">₹0.00</span></div>
                        <div style="display:flex;justify-content:space-between;"><span>SGST (50%):</span><span id="prodGstSgst" style="font-weight:700;">₹0.00</span></div>
                        <hr style="margin:0.5rem 0;border-color:var(--admin-border);">
                        <div style="display:flex;justify-content:space-between;font-weight:800;"><span>Grand Total:</span><span id="prodGstTotal" style="font-weight:800;">₹0.00</span></div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;">Add Stock</button>
        </form>
    </div>
</div>

<!-- Modal: Edit Product Price -->
<div class="modal" id="editPriceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Product Pricing</h3>
            <button class="modal-close" onclick="closeModal('editPriceModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="edit_product_price">
            <input type="hidden" name="prod_id" id="edit_price_prod_id">
            <div class="form-group">
                <label class="form-label">Product</label>
                <input type="text" id="edit_price_prod_name" class="form-control" disabled style="font-weight:700;">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Purchase Price (₹)</label>
                    <input type="number" step="0.01" name="edit_purchase_price" id="edit_price_purchase" class="form-control" value="0.00" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Selling Price (₹)</label>
                    <input type="number" step="0.01" name="edit_selling_price" id="edit_price_selling" class="form-control" value="0.00" min="0" required>
                </div>
            </div>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;">Update Pricing</button>
        </form>
    </div>
</div>

<!-- Modal: Update Cylinder Purchase Prices -->
<div class="modal" id="updateCylinderPriceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Update Cylinder Purchase Prices</h3>
            <button class="modal-close" onclick="closeModal('updateCylinderPriceModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="update_cylinder_prices">
            <div class="form-group">
                <label class="form-label">Gas Type</label>
                <select name="cyl_gas_type_id" id="cylPriceGasSelect" class="form-control" required onchange="handleCylPriceGasChange()">
                    <option value="">-- Choose gas type --</option>
                    <?php foreach ($gas_types as $gt): ?>
                        <?php $cyl_sizes_csv = isset($gas_sizes_map[$gt['id']]) ? implode(',', $gas_sizes_map[$gt['id']]) : '10L,40L,47L'; ?>
                        <option value="<?php echo $gt['id']; ?>" data-sizes="<?php echo htmlspecialchars($cyl_sizes_csv); ?>">
                            <?php echo htmlspecialchars($gt['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Cylinder Size</label>
                <select name="cyl_size_capacity" id="cylPriceSizeSelect" class="form-control" required>
                    <option value="">-- Select size --</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Purchase Price (₹) per cylinder</label>
                <input type="number" step="0.01" name="cyl_purchase_price" class="form-control" value="0.00" min="0" required>
            </div>
            <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:1rem;">
                This will update the purchase price for <strong>all</strong> existing cylinders of the selected gas type and size.
            </p>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;">Update Prices</button>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }
    
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    
    function handleRegisterGasChange() {
        const select = document.getElementById('registerGasSelect');
        const opt = select.options[select.selectedIndex];
        if (!opt) return;
        
        const sizesStr = opt.getAttribute('data-sizes') || '';
        const sizesArr = sizesStr.split(',').map(s => s.trim()).filter(s => s.length > 0);
        const sizeSelect = document.getElementById('registerSizeSelect');
        
        sizeSelect.innerHTML = '';
        if (sizesArr.length === 0) {
            const disabledOpt = document.createElement('option');
            disabledOpt.value = '';
            disabledOpt.text = '-- No sizes available --';
            disabledOpt.disabled = true;
            sizeSelect.appendChild(disabledOpt);
        } else {
            sizesArr.forEach(sz => {
                const sizeOpt = document.createElement('option');
                sizeOpt.value = sz;
                sizeOpt.text = sz;
                sizeSelect.appendChild(sizeOpt);
            });
        }
    }

    function countSerials() {
        const text = document.getElementById('serialsBulk').value;
        const serials = text.trim().split(/[\r\n,]+/).map(s => s.trim()).filter(s => s.length > 0);
        document.getElementById('serialCount').textContent = serials.length;
    }
    
    function openEditPriceModal(prod) {
        document.getElementById('edit_price_prod_id').value = prod.id;
        document.getElementById('edit_price_prod_name').value = prod.name;
        document.getElementById('edit_price_purchase').value = parseFloat(prod.purchase_price || 0).toFixed(2);
        document.getElementById('edit_price_selling').value = parseFloat(prod.price || 0).toFixed(2);
        openModal('editPriceModal');
    }

    function handleCylPriceGasChange() {
        const select = document.getElementById('cylPriceGasSelect');
        const opt = select.options[select.selectedIndex];
        if (!opt) return;
        const sizesStr = opt.getAttribute('data-sizes') || '';
        const sizesArr = sizesStr.split(',').map(s => s.trim()).filter(s => s.length > 0);
        const sizeSelect = document.getElementById('cylPriceSizeSelect');
        sizeSelect.innerHTML = '<option value="">-- Select size --</option>';
        sizesArr.forEach(sz => {
            const o = document.createElement('option');
            o.value = sz;
            o.text = sz;
            sizeSelect.appendChild(o);
        });
    }

    function toggleSupplierSection() {
        const checked = document.getElementById('toggleSupplierFields').checked;
        document.getElementById('supplierFields').style.display = checked ? 'block' : 'none';
        if (checked) updateGstPreview();
    }

    function toggleProductSupplierSection() {
        const checked = document.getElementById('toggleProductSupplier').checked;
        document.getElementById('productSupplierFields').style.display = checked ? 'block' : 'none';
        if (checked) updateProductGstPreview();
    }

    function updateProductGstPreview() {
        const checked = document.getElementById('toggleProductSupplier').checked;
        if (!checked) { document.getElementById('prodGstPreview').style.display = 'none'; return; }

        const qty = parseInt(document.querySelector('[name="add_qty"]').value) || 0;
        const price = parseFloat(document.getElementById('prodPurchasePrice').value) || 0;
        const gstRate = parseFloat(document.getElementById('prodGstRate').value) || 0;
        const subtotal = qty * price;

        if (qty === 0 || gstRate === 0) {
            document.getElementById('prodGstPreview').style.display = 'none';
            return;
        }

        const gstAmount = subtotal * gstRate / 100;
        const half = Math.round(gstAmount * 100 / 2) / 100;
        const cgst = half;
        const sgst = gstAmount - half;
        const grandTotal = subtotal + gstAmount;

        document.getElementById('prodGstTaxable').textContent = '\u20B9' + subtotal.toFixed(2);
        document.getElementById('prodGstCgst').textContent = '\u20B9' + cgst.toFixed(2);
        document.getElementById('prodGstSgst').textContent = '\u20B9' + sgst.toFixed(2);
        document.getElementById('prodGstTotal').textContent = '\u20B9' + grandTotal.toFixed(2);
        document.getElementById('prodGstPreview').style.display = 'block';
    }

    function updateGstPreview() {
        const checked = document.getElementById('toggleSupplierFields').checked;
        if (!checked) { document.getElementById('gstPreview').style.display = 'none'; return; }

        const price = parseFloat(document.getElementById('registerPurchasePrice').value) || 0;
        const gstRate = parseFloat(document.getElementById('registerGstRate').value) || 0;
        const text = document.getElementById('serialsBulk').value;
        const serials = text.trim().split(/[\r\n,]+/).map(s => s.trim()).filter(s => s.length > 0);
        const qty = serials.length;
        const subtotal = qty * price;

        if (qty === 0 || gstRate === 0) {
            document.getElementById('gstPreview').style.display = 'none';
            return;
        }

        const gstAmount = subtotal * gstRate / 100;
        const half = Math.round(gstAmount * 100 / 2) / 100;
        const cgst = half;
        const sgst = gstAmount - half;
        const grandTotal = subtotal + gstAmount;

        document.getElementById('gstTaxable').textContent = '\u20B9' + subtotal.toFixed(2);
        document.getElementById('gstCgst').textContent = '\u20B9' + cgst.toFixed(2);
        document.getElementById('gstSgst').textContent = '\u20B9' + sgst.toFixed(2);
        document.getElementById('gstGrandTotal').textContent = '\u20B9' + grandTotal.toFixed(2);
        document.getElementById('gstPreview').style.display = 'block';
    }

    let duplicateCheckTimer = null;

    function scheduleDuplicateCheck() {
        if (duplicateCheckTimer) clearTimeout(duplicateCheckTimer);
        duplicateCheckTimer = setTimeout(checkDuplicateSerials, 500);
    }

    function checkDuplicateSerials() {
        const text = document.getElementById('serialsBulk').value.trim();
        const serials = text.split(/[\r\n,]+/).map(s => s.trim()).filter(s => s.length > 0);
        const errorDiv = document.getElementById('serialError');
        const btn = document.getElementById('registerCylinderBtn');

        if (serials.length === 0) {
            errorDiv.style.display = 'none';
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
            return;
        }

        const formData = new FormData();
        formData.append('serials', text);

        fetch('check-cylinder-serial.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.exists && data.exists.length > 0) {
                const dupes = data.exists.join(', ');
                errorDiv.textContent = '<?php echo __('inventory.error_duplicates'); ?> ' + dupes;
                errorDiv.style.display = 'block';
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            } else {
                errorDiv.style.display = 'none';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            }
        })
        .catch(() => {
            // Silently fail - let server-side validation handle it
            errorDiv.style.display = 'none';
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        });
    }

    // Bind triggers on load
    document.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('registerGasSelect');
        if (select) {
            handleRegisterGasChange();
        }
    });
</script>

<?php
require_once 'layout_footer.php';
?>
