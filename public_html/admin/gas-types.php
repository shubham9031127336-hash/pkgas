<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('gas_types.title');
$active_menu = "gas_types";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
runProductMigrations($pdo);
runProductCategoryMigrations($pdo);
addProductNewColumns($pdo);
runRefillCostMigrations($pdo);

// Safe automatic migration for sizes column if not yet registered in database
try {
    $pdo->exec("ALTER TABLE gas_types ADD COLUMN sizes VARCHAR(255) DEFAULT '10L,40L,47L'");
} catch (PDOException $e) {
    // Column already exists, ignore safely
}

$message = '';
$error = '';

// Handle rate & size updating
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_rate') {
    $gas_id = intval($_POST['gas_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    // Parse size and price inputs
    $size_names = $_POST['size_names'] ?? [];
    $size_rates = $_POST['size_rates'] ?? [];
    $size_refill_costs_input = $_POST['size_refill_costs'] ?? [];
    $size_prices_map = [];
    $size_refill_costs_map = [];
    $sizes_arr = [];
    for ($i = 0; $i < count($size_names); $i++) {
        $name_val = trim($size_names[$i]);
        $rate_val = floatval($size_rates[$i] ?? 0);
        $refill_val = floatval($size_refill_costs_input[$i] ?? 0);
        if ($name_val !== '') {
            $size_prices_map[$name_val] = $rate_val;
            $size_refill_costs_map[$name_val] = $refill_val;
            $sizes_arr[] = $name_val;
        }
    }
    $size_refill_costs = json_encode($size_refill_costs_map);
    
    // Set default price to the first variant's price or fallback
    $default_rate = !empty($size_rates) ? floatval($size_rates[0]) : floatval($_POST['default_price_per_kg'] ?? 0);
    $default_refill_cost = !empty($size_refill_costs_input) ? floatval($size_refill_costs_input[0]) : 0.00;
    
    if ($gas_id > 0 && !empty($sizes_arr)) {
        try {
            $hsn_code = trim($_POST['hsn_code'] ?? '');
            $stmt = $pdo->prepare("UPDATE gas_types SET default_price_per_kg = ?, refill_cost = ?, description = ?, size_refill_costs = ?, hsn_code = ? WHERE id = ?");
            $stmt->execute([$default_rate, $default_refill_cost, $description, $size_refill_costs, $hsn_code ?: null, $gas_id]);

            $del = $pdo->prepare("DELETE FROM gas_sizes WHERE gas_type_id = ?");
            $del->execute([$gas_id]);
            $ins = $pdo->prepare("INSERT INTO gas_sizes (gas_type_id, size_capacity, price, sort_order) VALUES (?, ?, ?, ?)");
            foreach ($sizes_arr as $i => $sz) {
                $ins->execute([$gas_id, $sz, $size_prices_map[$sz] ?? null, $i]);
            }

            $message = __('gas_types.updated');
        } catch (PDOException $e) {
            $error = __('gas_types.update_failed') . ': ' . $e->getMessage();
        }
    } else {
        $error = __('gas_types.error_variant');
    }
}

// Handle new gas creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_gas') {
    $name = trim($_POST['name'] ?? '');
    $chemical_formula = trim($_POST['chemical_formula'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Parse size and price inputs
    $size_names = $_POST['size_names'] ?? [];
    $size_rates = $_POST['size_rates'] ?? [];
    $size_refill_costs_input = $_POST['size_refill_costs'] ?? [];
    $size_prices_map = [];
    $size_refill_costs_map = [];
    $sizes_arr = [];
    for ($i = 0; $i < count($size_names); $i++) {
        $name_val = trim($size_names[$i]);
        $rate_val = floatval($size_rates[$i] ?? 0);
        $refill_val = floatval($size_refill_costs_input[$i] ?? 0);
        if ($name_val !== '') {
            $size_prices_map[$name_val] = $rate_val;
            $size_refill_costs_map[$name_val] = $refill_val;
            $sizes_arr[] = $name_val;
        }
    }
    $size_refill_costs = json_encode($size_refill_costs_map);
    
    $default_rate = !empty($size_rates) ? floatval($size_rates[0]) : floatval($_POST['default_price_per_kg'] ?? 0);
    $default_refill_cost = !empty($size_refill_costs_input) ? floatval($size_refill_costs_input[0]) : 0.00;
    
    if (empty($name)) {
        $error = __('gas_types.error_name');
    } elseif (empty($sizes_arr)) {
        $error = __('gas_types.error_variant_config');
    } else {
        try {
            $hsn_code = trim($_POST['hsn_code'] ?? '');
            $stmt = $pdo->prepare("INSERT INTO gas_types (name, chemical_formula, default_price_per_kg, refill_cost, description, size_refill_costs, hsn_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $chemical_formula ?: null, $default_rate, $default_refill_cost, $description, $size_refill_costs, $hsn_code ?: null]);
            $new_id = $pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO gas_sizes (gas_type_id, size_capacity, price, sort_order) VALUES (?, ?, ?, ?)");
            foreach ($sizes_arr as $i => $sz) {
                $ins->execute([$new_id, $sz, $size_prices_map[$sz] ?? null, $i]);
            }
            $message = __('gas_types.created');
        } catch (PDOException $e) {
            $error = __('gas_types.create_failed') . ': ' . $e->getMessage() . ' (Verify name duplicates)';
        }
    }
}

// Handle Delete Gas Type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_gas') {
    $gas_id = intval($_POST['gas_id'] ?? 0);
    $confirm_name = trim($_POST['confirm_name'] ?? '');
    
    try {
        // Fetch Gas name to double check
        $stmt = $pdo->prepare("SELECT name FROM gas_types WHERE id = ?");
        $stmt->execute([$gas_id]);
        $gas = $stmt->fetch();
        
        if ($gas && $gas['name'] === $confirm_name) {
            $pdo->prepare("DELETE FROM gas_sizes WHERE gas_type_id = ?")->execute([$gas_id]);
            $del_stmt = $pdo->prepare("DELETE FROM gas_types WHERE id = ?");
            $del_stmt->execute([$gas_id]);
            $message = __('gas_types.deleted') . ' "' . htmlspecialchars($confirm_name) . '"';
        } else {
            $error = __('gas_types.delete_confirm_failed');
        }
    } catch (PDOException $e) {
        $error = __('gas_types.cannot_delete');
    }
}

// Handle Create Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_product') {
    $name = trim($_POST['prod_name'] ?? '');
    $unit = trim($_POST['prod_unit'] ?? 'piece');
    $description = trim($_POST['prod_description'] ?? '');
    $sku = trim($_POST['prod_sku'] ?? '');
    $category_id = intval($_POST['prod_category_id'] ?? 0);
    $brand = trim($_POST['prod_brand'] ?? '');
    $gst_rate = $_POST['prod_gst_rate'] !== '' ? floatval($_POST['prod_gst_rate']) : null;

    if (empty($name)) {
        $error = "Product name is required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO products (name, unit, description, sku, category_id, brand, gst_rate) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $unit, $description, $sku ?: null, $category_id ?: null, $brand ?: null, $gst_rate]);
            $message = "Product '".htmlspecialchars($name)."' created successfully.";
        } catch (PDOException $e) {
            $error = "Failed to create product: " . $e->getMessage();
        }
    }
}

// Handle Update Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_product') {
    $id = intval($_POST['prod_id'] ?? 0);
    $name = trim($_POST['prod_name'] ?? '');
    $unit = trim($_POST['prod_unit'] ?? 'piece');
    $description = trim($_POST['prod_description'] ?? '');
    $sku = trim($_POST['prod_sku'] ?? '');
    $category_id = intval($_POST['prod_category_id'] ?? 0);
    $brand = trim($_POST['prod_brand'] ?? '');
    $gst_rate = $_POST['prod_gst_rate'] !== '' ? floatval($_POST['prod_gst_rate']) : null;
    $is_active = isset($_POST['prod_is_active']) ? 1 : 0;

    if ($id <= 0 || empty($name)) {
        $error = "Invalid product data.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE products SET name=?, unit=?, description=?, sku=?, category_id=?, brand=?, gst_rate=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $unit, $description, $sku ?: null, $category_id ?: null, $brand ?: null, $gst_rate, $is_active, $id]);
            $message = "Product updated successfully.";
        } catch (PDOException $e) {
            $error = "Failed to update product: " . $e->getMessage();
        }
    }
}

// Handle Delete Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    $id = intval($_POST['prod_id'] ?? 0);
    $confirm_name = trim($_POST['confirm_name'] ?? '');

    try {
        $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $prod = $stmt->fetch();

        if ($prod && $prod['name'] === $confirm_name) {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Product "' . htmlspecialchars($confirm_name) . '" deleted.';
        } else {
            $error = "Name does not match. Deletion cancelled.";
        }
    } catch (PDOException $e) {
        $error = "Cannot delete product: " . $e->getMessage();
    }
}

// Fetch all gas types
$gas_types = [];
try {
    $gas_types = $pdo->query("SELECT * FROM gas_types ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    $error = __('gas_types.load_failed') . ': ' . $e->getMessage();
}

$gas_sizes_map = [];
try {
    $all_sizes = $pdo->query("SELECT gs.gas_type_id, gs.size_capacity, gs.price FROM gas_sizes gs JOIN gas_types gt ON gs.gas_type_id = gt.id ORDER BY gs.gas_type_id, gs.sort_order")->fetchAll();
    foreach ($all_sizes as $gs) {
        $gtid = $gs['gas_type_id'];
        if (!isset($gas_sizes_map[$gtid])) $gas_sizes_map[$gtid] = ['sizes' => [], 'prices' => []];
        $gas_sizes_map[$gtid]['sizes'][] = $gs['size_capacity'];
        $gas_sizes_map[$gtid]['prices'][$gs['size_capacity']] = $gs['price'];
    }
} catch (PDOException $e) {}

// Fetch all products with category name
$products = [];
try {
    $products = $pdo->query("SELECT p.*, pc.name AS category_name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id ORDER BY p.name ASC")->fetchAll();
} catch (PDOException $e) {}

// Fetch product categories for dropdown
$product_categories = [];
try {
    $product_categories = $pdo->query("SELECT * FROM product_categories ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {}

// Handle category CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_category') {
    $cat_name = trim($_POST['cat_name'] ?? '');
    $cat_desc = trim($_POST['cat_description'] ?? '');
    if (!empty($cat_name)) {
        try {
            $pdo->prepare("INSERT INTO product_categories (name, description) VALUES (?, ?)")->execute([$cat_name, $cat_desc]);
            $message = "Category '$cat_name' created.";
        } catch (PDOException $e) { $error = $e->getMessage(); }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_category') {
    $cat_id = intval($_POST['cat_id'] ?? 0);
    if ($cat_id > 0) {
        try {
            $pdo->prepare("UPDATE products SET category_id = NULL WHERE category_id = ?")->execute([$cat_id]);
            $pdo->prepare("DELETE FROM product_categories WHERE id = ?")->execute([$cat_id]);
            $message = "Category deleted.";
        } catch (PDOException $e) { $error = $e->getMessage(); }
    }
}
?>

<!-- Tab Toggle -->
<div style="display:flex;gap:0;margin-bottom:1.5rem;border-bottom:2px solid var(--admin-border);">
    <button id="tabGasBtn" class="tab-btn" style="padding:0.65rem 1.5rem;font-weight:700;font-size:0.9rem;cursor:pointer;border:none;background:none;color:var(--admin-muted);border-bottom:3px solid transparent;transition:all 0.15s;"
            onclick="switchTab('gas')">Gas Types</button>
    <button id="tabProductBtn" class="tab-btn" style="padding:0.65rem 1.5rem;font-weight:700;font-size:0.9rem;cursor:pointer;border:none;background:none;color:var(--admin-muted);border-bottom:3px solid transparent;transition:all 0.15s;"
            onclick="switchTab('product')">Products</button>
</div>

<!-- Gas Types Section -->
<div id="gasTabContent">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('gas_types.heading'); ?></h2>
            <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('gas_types.subtitle'); ?></p>
        </div>
        <button class="btn-primary" onclick="openModal('addGasModal')">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php echo __('gas_types.add'); ?>
        </button>
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

<!-- Gas Types Table Grid -->
<div class="admin-card" style="padding: 0;">
    <div class="table-wrapper" style="border: none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('gas_types.name'); ?></th>
                    <th><?php echo __('gas_types.symbol'); ?></th>
                    <th><?php echo __('gas_types.variants'); ?></th>
                    <th style="text-align: right;"><?php echo __('gas_types.base_price'); ?></th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gas_types as $gt):
                    $map = $gas_sizes_map[$gt['id']] ?? ['sizes' => [], 'prices' => []];
                    $gt['sizes'] = implode(',', $map['sizes']) ?: '10L,40L,47L';
                    $gt['size_prices'] = json_encode($map['prices']) ?: '{}';
                ?>
                <tr>
                    <td style="font-weight: 700; color: var(--admin-fg);" data-label="<?php echo __('gas_types.name'); ?>"><?php echo htmlspecialchars($gt['name']); ?></td>
                    <td data-label="<?php echo __('gas_types.symbol'); ?>">
                        <span style="font-family: monospace; font-weight: 700; color: var(--admin-accent); font-size: 0.95rem; background: #eff6ff; padding: 2px 8px; border-radius: 6px;">
                            <?php echo htmlspecialchars($gt['chemical_formula'] ?: 'N/A'); ?>
                        </span>
                    </td>
                    <td data-label="<?php echo __('gas_types.variants'); ?>">
                        <?php 
                        $sizes_arr = isset($gas_sizes_map[$gt['id']]) ? $gas_sizes_map[$gt['id']]['sizes'] : ['10L','40L','47L'];
                        $size_prices_map = isset($gas_sizes_map[$gt['id']]) ? $gas_sizes_map[$gt['id']]['prices'] : [];
                        $size_refill_costs_map = [];
                        if (!empty($gt['size_refill_costs'])) {
                            $size_refill_costs_map = json_decode($gt['size_refill_costs'], true) ?: [];
                        }
                        foreach ($sizes_arr as $sz) {
                            $sz = trim($sz);
                            $price_str = '';
                            if (isset($size_prices_map[$sz])) {
                                $price_str = ' (₹' . number_format($size_prices_map[$sz], 2) . ')';
                            } else {
                                $price_str = ' (₹' . number_format($gt['default_price_per_kg'], 2) . ')';
                            }
                            $refill_cost_val = isset($size_refill_costs_map[$sz]) ? floatval($size_refill_costs_map[$sz]) : floatval($gt['refill_cost'] ?? 0);
                            $cost_str = $refill_cost_val > 0 ? ' cost:₹' . number_format($refill_cost_val, 2) : '';
                            echo '<span class="badge badge-filled" style="font-size:0.75rem; margin-right: 4px; padding: 3px 8px; font-weight:700; display: inline-block; margin-bottom: 4px;">' . htmlspecialchars($sz . $price_str . $cost_str) . '</span>';
                        }
                        ?>
                    </td>
                    <td style="text-align: right; font-weight: 800; color: var(--admin-accent); font-size: 1rem;" data-label="<?php echo __('gas_types.base_price'); ?>">
                        ₹<?php echo number_format($gt['default_price_per_kg'], 2); ?>
                        <?php if (floatval($gt['refill_cost'] ?? 0) > 0): ?>
                            <span style="display:block;font-size:0.7rem;color:var(--admin-muted);font-weight:600;">Cost: ₹<?php echo number_format($gt['refill_cost'], 2); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;" data-label="Actions">
                        <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                            <button class="btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px;"
                                    onclick="openEditModal(<?php echo htmlspecialchars(json_encode($gt)); ?>)">
                                <?php echo __('gas_types.edit'); ?>
                            </button>
                            <button class="btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px;"
                                    onclick="openDeleteModal(<?php echo htmlspecialchars(json_encode($gt)); ?>)">
                                <?php echo __('gas_types.delete'); ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($gas_types)): ?>
                <tr>
                    <td colspan="5" style="text-align:center;padding:3rem 1rem;color:var(--admin-muted);font-size:0.9rem;">
                        <div style="font-size:2rem;margin-bottom:0.5rem;">📭</div>
                        <?php echo __('gas_types.no_data', 'No gas types defined yet. Click "Add Gas Type" to create one.'); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add Gas Type -->
<div class="modal" id="addGasModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('gas_types.modal.add_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('addGasModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="create_gas">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('gas_types.modal.name_label'); ?></label>
                <input type="text" name="name" class="form-control" required placeholder="<?php echo __('gas_types.modal.name_placeholder'); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('gas_types.modal.symbol_label'); ?></label>
                <input type="text" name="chemical_formula" class="form-control" placeholder="<?php echo __('gas_types.modal.symbol_placeholder'); ?>">
            </div>
            
            <div class="form-group" style="display: none;">
                <!-- Retain hidden fallback default rate field for compatibility -->
                <input type="hidden" name="default_price_per_kg" value="0.00">
            </div>
            
            <div class="form-group">
                <label class="form-label" style="font-weight: 700;"><?php echo __('gas_types.modal.variants_label'); ?></label>
                <p style="font-size: 0.75rem; color: var(--admin-muted); margin-bottom: 0.5rem;"><?php echo __('gas_types.modal.variants_help'); ?></p>
                <div id="add_variants_container" style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <!-- Dynamically populated -->
                </div>
                <button type="button" class="btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px; margin-top: 0.25rem;" onclick="addVariantRowToContainer('add_variants_container')">
                    ➕ <?php echo __('gas_types.modal.add_variant'); ?>
                </button>
            </div>
            
            <div class="form-group">
                <label class="form-label">HSN Code</label>
                <input type="text" name="hsn_code" class="form-control" placeholder="280440" value="280440">
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('gas_types.modal.description_label'); ?></label>
                <textarea name="description" class="form-control" rows="2" placeholder="<?php echo __('gas_types.modal.description_placeholder'); ?>"></textarea>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;"><?php echo __('gas_types.modal.save_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Modal: Edit Gas Type Rate / Info -->
<div class="modal" id="editGasModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php echo __('gas_types.modal.edit_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('editGasModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="update_rate">
            <input type="hidden" name="gas_id" id="edit_gas_id">
            
            <div class="form-group">
                <label class="form-label"><?php echo __('gas_types.modal.name_label'); ?></label>
                <input type="text" id="edit_gas_name" class="form-control" disabled style="font-weight: 700;">
            </div>
            
            <div class="form-group" style="display: none;">
                <!-- Retain hidden fallback default rate field for compatibility -->
                <input type="hidden" name="default_price_per_kg" id="edit_gas_rate">
            </div>
            
            <div class="form-group">
                <label class="form-label" style="font-weight: 700;"><?php echo __('gas_types.modal.variants_label'); ?></label>
                <p style="font-size: 0.75rem; color: var(--admin-muted); margin-bottom: 0.5rem;"><?php echo __('gas_types.modal.variants_help'); ?></p>
                <div id="edit_variants_container" style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <!-- Dynamically populated in JS -->
                </div>
                <button type="button" class="btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: 8px; margin-top: 0.25rem;" onclick="addVariantRowToContainer('edit_variants_container')">
                    ➕ <?php echo __('gas_types.modal.add_variant'); ?>
                </button>
            </div>
            
            <div class="form-group">
                <label class="form-label">HSN Code</label>
                <input type="text" name="hsn_code" id="edit_gas_hsn" class="form-control" placeholder="280440">
            </div>
            
            <div class="form-group">
                <label class="form-label"><?php echo __('gas_types.modal.description_label'); ?></label>
                <textarea name="description" id="edit_gas_desc" class="form-control" rows="3"></textarea>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;"><?php echo __('gas_types.modal.post_btn'); ?></button>
        </form>
    </div>
</div>

<!-- Modal: Safe Delete Gas Confirmation -->
<div class="modal" id="deleteGasModal">
    <div class="modal-content" style="border-top: 6px solid var(--danger);">
        <div class="modal-header">
            <h3 style="color: var(--danger);">⚠️ <?php echo __('gas_types.modal.delete_title'); ?></h3>
            <button class="modal-close" onclick="closeModal('deleteGasModal')">&times;</button>
        </div>
        <form method="POST"><?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_gas">
            <input type="hidden" name="gas_id" id="delete_gas_id">
            <input type="hidden" name="confirm_name" id="delete_confirm_actual_name">
            
            <p style="font-size: 0.9rem; line-height: 1.5; color: var(--admin-fg); margin-bottom: 1rem;">
                <?php echo __('gas_types.modal.delete_warning'); ?> <strong id="delete_gas_display_name" style="color: var(--danger);"></strong>
            </p>
            
            <div class="form-group">
                <label class="form-label" style="font-weight: 700;"><?php echo __('gas_types.modal.delete_confirm_label'); ?></label>
                <input type="text" id="delete_confirm_input" class="form-control" placeholder="<?php echo __('gas_types.modal.delete_placeholder'); ?>" onkeyup="validateDeleteInput()" autocomplete="off">
            </div>
            
            <button type="submit" id="deleteConfirmBtn" class="btn-danger" style="width: 100%; justify-content: center; margin-top: 1rem; padding: 10px 0;" disabled>
                <?php echo __('gas_types.modal.delete_btn'); ?>
            </button>
        </form>
    </div>
</div>

</div><!-- end gasTabContent -->

<!-- Products Section -->
<div id="productTabContent" style="display:none;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div>
            <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;">Products</h2>
            <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;">Manage non-gas products like valves, masks, keys, regulators.</p>
        </div>
        <button class="btn-primary" onclick="openModal('addProductModal')">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Product
        </button>
    </div>

    <div style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap;">
        <button class="btn-secondary" style="padding:0.4rem 1rem;font-size:0.8rem;" onclick="openModal('addCategoryModal')">+ Category</button>
    </div>

    <div class="admin-card" style="padding: 0;">
        <div class="table-wrapper" style="border: none;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th>GST Rate</th>
                        <th>Stock</th>
                        <th>Brand</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr style="<?php echo $p['is_active'] ? '' : 'opacity:0.5;'; ?>">
                        <td style="font-weight:700;"><?php echo htmlspecialchars($p['name']); ?></td>
                        <td style="font-family:monospace;font-size:0.8rem;"><?php echo htmlspecialchars($p['sku'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($p['category_name'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($p['unit'] ?: 'piece'); ?></td>
                        <td><?php echo $p['gst_rate'] ? number_format($p['gst_rate'], 2).'%' : '—'; ?></td>
                        <td style="font-weight:600;"><?php echo intval($p['stock_quantity']); ?>
                            <?php if (intval($p['reorder_point']) > 0 && intval($p['stock_quantity']) <= intval($p['reorder_point'])): ?>
                            <span style="color:#dc2626;font-size:0.75rem;">⚠</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($p['brand'] ?: '—'); ?></td>
                        <td style="text-align:right;">
                            <div style="display:flex;justify-content:flex-end;gap:0.5rem;">
                                <button class="btn-secondary" style="padding:0.4rem 0.8rem;font-size:0.8rem;border-radius:8px;"
                                        onclick="openEditProductModal(<?php echo htmlspecialchars(json_encode($p)); ?>)">Edit</button>
                                <button class="btn-danger" style="padding:0.4rem 0.8rem;font-size:0.8rem;border-radius:8px;"
                                        onclick="openDeleteProductModal(<?php echo htmlspecialchars(json_encode($p)); ?>)">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:3rem 1rem;color:var(--admin-muted);font-size:0.9rem;">
                            <div style="font-size:2rem;margin-bottom:0.5rem;">📦</div>
                            No products added yet. Click "Add Product" to create one.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Add Product -->
    <div class="modal" id="addProductModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Product</h3>
                <button class="modal-close" onclick="closeModal('addProductModal')">&times;</button>
            </div>
            <form method="POST"><?php csrfField(); ?>
                <input type="hidden" name="action" value="create_product">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label class="form-label">Product Name *</label>
                        <input type="text" name="prod_name" class="form-control" required placeholder="e.g. Valve, Mask, Key, Regulator">
                    </div>
                    <div class="form-group">
                        <label class="form-label">SKU</label>
                        <input type="text" name="prod_sku" class="form-control" placeholder="e.g. VAL-001">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="prod_category_id" class="form-control">
                            <option value="">— None —</option>
                            <?php foreach ($product_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit</label>
                        <input type="text" name="prod_unit" class="form-control" value="piece" placeholder="piece, kg, meter">
                    </div>
                    <div class="form-group">
                        <label class="form-label">GST Rate (%)</label>
                        <input type="number" step="0.01" name="prod_gst_rate" class="form-control" placeholder="e.g. 18">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Brand</label>
                        <input type="text" name="prod_brand" class="form-control" placeholder="e.g. ESAB, Linde">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description (optional)</label>
                    <textarea name="prod_description" class="form-control" rows="2" placeholder="Optional product details..."></textarea>
                </div>
                <p style="font-size:0.8rem;color:var(--admin-muted);margin-top:-0.5rem;margin-bottom:0.5rem;">
                    Stock and pricing are managed from the <a href="inventory.php" style="color:var(--admin-accent);font-weight:700;">Stock Inventory</a> page.
                </p>
                <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;">Add Product</button>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Product -->
    <div class="modal" id="editProductModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Product</h3>
                <button class="modal-close" onclick="closeModal('editProductModal')">&times;</button>
            </div>
            <form method="POST"><?php csrfField(); ?>
                <input type="hidden" name="action" value="update_product">
                <input type="hidden" name="prod_id" id="edit_prod_id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div class="form-group">
                        <label class="form-label">Product Name *</label>
                        <input type="text" name="prod_name" id="edit_prod_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SKU</label>
                        <input type="text" name="prod_sku" id="edit_prod_sku" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="prod_category_id" id="edit_prod_category_id" class="form-control">
                            <option value="">— None —</option>
                            <?php foreach ($product_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit</label>
                        <input type="text" name="prod_unit" id="edit_prod_unit" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">GST Rate (%)</label>
                        <input type="number" step="0.01" name="prod_gst_rate" id="edit_prod_gst_rate" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Brand</label>
                        <input type="text" name="prod_brand" id="edit_prod_brand" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="prod_description" id="edit_prod_description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:0.5rem;">
                    <input type="checkbox" name="prod_is_active" id="edit_prod_is_active" value="1" checked>
                    <label for="edit_prod_is_active" style="font-size:0.9rem;">Active (visible in orders)</label>
                </div>
                <p style="font-size:0.8rem;color:var(--admin-muted);margin-top:-0.5rem;margin-bottom:0.5rem;">
                    Pricing and stock are managed from the <a href="inventory.php" style="color:var(--admin-accent);font-weight:700;">Stock Inventory</a> page.
                </p>
                <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;">Update Product</button>
            </form>
        </div>
    </div>

    <!-- Modal: Delete Product -->
    <div class="modal" id="deleteProductModal">
        <div class="modal-content" style="border-top:6px solid var(--danger);">
            <div class="modal-header">
                <h3 style="color:var(--danger);">⚠️ Delete Product</h3>
                <button class="modal-close" onclick="closeModal('deleteProductModal')">&times;</button>
            </div>
            <form method="POST"><?php csrfField(); ?>
                <input type="hidden" name="action" value="delete_product">
                <input type="hidden" name="prod_id" id="delete_prod_id">
                <input type="hidden" name="confirm_name" id="delete_prod_confirm_actual">
                <p style="font-size:0.9rem;line-height:1.5;margin-bottom:1rem;">
                    Type the product name <strong id="delete_prod_display_name" style="color:var(--danger);"></strong> to confirm deletion.
                </p>
                <div class="form-group">
                    <input type="text" id="delete_prod_confirm_input" class="form-control" placeholder="Type product name to confirm" onkeyup="validateDeleteProductInput()" autocomplete="off">
                </div>
                <button type="submit" id="deleteProductConfirmBtn" class="btn-danger" style="width:100%;justify-content:center;margin-top:1rem;padding:10px 0;" disabled>
                    Delete Product
                </button>
            </form>
        </div>
    </div>
    <!-- Modal: Add Category -->
    <div class="modal" id="addCategoryModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Product Category</h3>
                <button class="modal-close" onclick="closeModal('addCategoryModal')">&times;</button>
            </div>
            <form method="POST"><?php csrfField(); ?>
                <input type="hidden" name="action" value="create_category">
                <div class="form-group">
                    <label class="form-label">Category Name *</label>
                    <input type="text" name="cat_name" class="form-control" required placeholder="e.g. Welding, Safety, Plumbing">
                </div>
                <div class="form-group">
                    <label class="form-label">Description (optional)</label>
                    <textarea name="cat_description" class="form-control" rows="2" placeholder="Optional description..."></textarea>
                </div>
                <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:1rem;">Add Category</button>
            </form>
            <?php if (!empty($product_categories)): ?>
            <hr style="margin:1rem 0;">
            <h4 style="font-size:0.85rem;margin-bottom:0.5rem;">Existing Categories</h4>
            <div style="display:flex;flex-direction:column;gap:0.4rem;">
                <?php foreach ($product_categories as $cat): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0.6rem;background:#f8fafc;border-radius:6px;font-size:0.85rem;">
                    <span><strong><?php echo htmlspecialchars($cat['name']); ?></strong></span>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Delete category &#39;<?php echo htmlspecialchars($cat['name']); ?>&#39;? Products in this category will be uncategorized.');">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="cat_id" value="<?php echo $cat['id']; ?>">
                        <button type="submit" class="btn-danger" style="padding:0.25rem 0.5rem;font-size:0.75rem;border-radius:6px;">Delete</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div><!-- end productTabContent -->

<script>
    let activeDeleteGasName = '';
    let activeDeleteProductName = '';

    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }
    
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    function switchTab(tab) {
        const gasTab = document.getElementById('gasTabContent');
        const prodTab = document.getElementById('productTabContent');
        const gasBtn = document.getElementById('tabGasBtn');
        const prodBtn = document.getElementById('tabProductBtn');
        const activeStyle = 'color:#1e293b;border-bottom:3px solid var(--admin-accent);';
        const inactiveStyle = 'color:var(--admin-muted);border-bottom:3px solid transparent;';

        if (tab === 'gas') {
            gasTab.style.display = '';
            prodTab.style.display = 'none';
            gasBtn.style.cssText = gasBtn.style.cssText.replace(/color:[^;]+;/, '') + activeStyle;
            prodBtn.style.cssText = prodBtn.style.cssText.replace(/color:[^;]+;/, '') + inactiveStyle;
        } else {
            gasTab.style.display = 'none';
            prodTab.style.display = '';
            prodBtn.style.cssText = prodBtn.style.cssText.replace(/color:[^;]+;/, '') + activeStyle;
            gasBtn.style.cssText = gasBtn.style.cssText.replace(/color:[^;]+;/, '') + inactiveStyle;
        }
    }

    function openEditModal(gas) {
        document.getElementById('edit_gas_id').value = gas.id;
        document.getElementById('edit_gas_name').value = gas.name + ' (' + (gas.chemical_formula || 'N/A') + ')';
        document.getElementById('edit_gas_rate').value = gas.default_price_per_kg;
        
        const container = document.getElementById('edit_variants_container');
        container.innerHTML = '';
        
        let sizePrices = {};
        let sizeRefillCosts = {};
        try {
            if (gas.size_prices) {
                sizePrices = JSON.parse(gas.size_prices);
            }
            if (gas.size_refill_costs) {
                sizeRefillCosts = JSON.parse(gas.size_refill_costs);
            }
        } catch (e) {
            console.error("Error parsing size prices:", e);
        }
        
        const sizesArr = (gas.sizes || '10L,40L,47L').split(',');
        const defaultRate = parseFloat(gas.default_price_per_kg) || 0;
        const defaultRefillCost = parseFloat(gas.refill_cost) || 0;
        
        sizesArr.forEach(sz => {
            const cleanSz = sz.trim();
            if (cleanSz) {
                const rate = (sizePrices[cleanSz] !== undefined) ? sizePrices[cleanSz] : defaultRate;
                const refillCost = (sizeRefillCosts[cleanSz] !== undefined) ? sizeRefillCosts[cleanSz] : defaultRefillCost;
                addVariantRowToContainer('edit_variants_container', cleanSz, rate, refillCost);
            }
        });
        
        document.getElementById('edit_gas_hsn').value = gas.hsn_code || '280440';
        document.getElementById('edit_gas_desc').value = gas.description || '';
        openModal('editGasModal');
    }
    
    function openDeleteModal(gas) {
        document.getElementById('delete_gas_id').value = gas.id;
        document.getElementById('delete_confirm_actual_name').value = gas.name;
        document.getElementById('delete_gas_display_name').innerText = gas.name;
        
        activeDeleteGasName = gas.name;
        
        document.getElementById('delete_confirm_input').value = '';
        document.getElementById('deleteConfirmBtn').disabled = true;
        document.getElementById('deleteConfirmBtn').style.opacity = '0.5';
        
        openModal('deleteGasModal');
    }
    
    function validateDeleteInput() {
        const input = document.getElementById('delete_confirm_input').value.trim();
        const btn = document.getElementById('deleteConfirmBtn');
        
        if (input === activeDeleteGasName) {
            btn.disabled = false;
            btn.style.opacity = '1';
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.5';
        }
    }

    function addVariantRowToContainer(containerId, sizeName = '', price = '0.00', refillCost = '0.00') {
        const container = document.getElementById(containerId);
        const row = document.createElement('div');
        row.style.cssText = "display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;";
        row.className = "variant-row";
        
        const formattedPrice = parseFloat(price).toFixed(2);
        const formattedRefill = parseFloat(refillCost).toFixed(2);
        
        row.innerHTML = `
            <input type="text" name="size_names[]" class="form-control" style="flex: 1;" placeholder="<?php echo __('gas_types.modal.size_placeholder'); ?>" value="${escapeHtml(sizeName)}" required>
            <input type="number" step="0.01" name="size_rates[]" class="form-control" style="width: 110px;" placeholder="Sell ₹" value="${formattedPrice}" required>
            <input type="number" step="0.01" name="size_refill_costs[]" class="form-control" style="width: 110px;" placeholder="Cost ₹" value="${formattedRefill}">
            <button type="button" class="btn-danger" style="padding: 0.4rem 0.6rem; border-radius: 8px; font-weight: bold; margin-bottom: 0; display: flex; align-items: center; justify-content: center; height: 38px;" onclick="this.closest('.variant-row').remove()">&times;</button>
        `;
        container.appendChild(row);
    }
    
    function escapeHtml(str) {
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // === Product Functions ===

    function openEditProductModal(prod) {
        document.getElementById('edit_prod_id').value = prod.id;
        document.getElementById('edit_prod_name').value = prod.name;
        document.getElementById('edit_prod_sku').value = prod.sku || '';
        document.getElementById('edit_prod_category_id').value = prod.category_id || '';
        document.getElementById('edit_prod_unit').value = prod.unit || 'piece';
        document.getElementById('edit_prod_gst_rate').value = prod.gst_rate || '';
        document.getElementById('edit_prod_brand').value = prod.brand || '';
        document.getElementById('edit_prod_description').value = prod.description || '';
        document.getElementById('edit_prod_is_active').checked = prod.is_active != 0;
        openModal('editProductModal');
    }

    function openDeleteProductModal(prod) {
        document.getElementById('delete_prod_id').value = prod.id;
        document.getElementById('delete_prod_confirm_actual').value = prod.name;
        document.getElementById('delete_prod_display_name').innerText = prod.name;
        activeDeleteProductName = prod.name;
        document.getElementById('delete_prod_confirm_input').value = '';
        document.getElementById('deleteProductConfirmBtn').disabled = true;
        document.getElementById('deleteProductConfirmBtn').style.opacity = '0.5';
        openModal('deleteProductModal');
    }

    function validateDeleteProductInput() {
        const input = document.getElementById('delete_prod_confirm_input').value.trim();
        const btn = document.getElementById('deleteProductConfirmBtn');
        if (input === activeDeleteProductName) {
            btn.disabled = false;
            btn.style.opacity = '1';
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.5';
        }
    }
    
    // Bind triggers on load
    document.addEventListener('DOMContentLoaded', () => {
        // Initialize Add variants container with some standard defaults
        const addContainer = document.getElementById('add_variants_container');
        if (addContainer && addContainer.children.length === 0) {
            addVariantRowToContainer('add_variants_container', '10L', '150.00', '80.00');
            addVariantRowToContainer('add_variants_container', '40L', '350.00', '180.00');
            addVariantRowToContainer('add_variants_container', '47L', '400.00', '220.00');
        }
        // Activate gas tab by default
        document.getElementById('tabGasBtn').style.cssText += 'color:#1e293b;border-bottom:3px solid var(--admin-accent);';
    });
</script>

<?php
require_once 'layout_footer.php';
?>
