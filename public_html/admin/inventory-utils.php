<?php
/**
 * Prem Gas Solution - Inventory & Cylinder Lifecycle Synchronization Utilities
 */

require_once __DIR__ . '/expense-utils.php';

if (!function_exists('syncInventory')) {
    /**
     * Differential sync — updates only changed inventory rows instead of full rebuild.
     */
    function syncInventory($pdo, $inTransaction = false) {
        try {
            runPartnerMigrations($pdo);
            runRefillRentalMigrations($pdo);
            runConsumerCylinderMigrations($pdo);
            runGasSizesMigration($pdo);
            runCustomerPortalMigrations($pdo);
            runDatabaseIndexes($pdo);
            runBusinessConfigMigration($pdo);
            runMultiBrandMigration($pdo);
            runVendorBatchAdjustmentMigrations($pdo);
            runVendorRefillBatchItemsMigration($pdo);
            runDispatchLotMigrations($pdo);
            runLotSettlementRedesignMigration($pdo);
            runTransportCostMigrations($pdo);
            runBulkOperationAuditMigration($pdo);
            runCylinderSupplierMigrations($pdo);
            runVendorInvoiceMigrations($pdo);
            runExpenseMigrations($pdo);

            $stmt = $pdo->query("SELECT DISTINCT gas_type_id, size_capacity FROM cylinders");
            $categories = $stmt->fetchAll();

            $existing = [];
            $res = $pdo->query("SELECT gas_type_id, size_capacity, total_stock, filled_stock, empty_stock, with_customer_stock, sent_to_vendor_stock, maintenance_stock, borrowed_from_partner_stock, lent_to_partner_stock, with_partner_stock, borrowed_from_vendor_stock, returned_to_vendor_stock, received_for_refill_stock FROM inventory");
            while ($row = $res->fetch()) {
                $existing[$row['gas_type_id'] . '|' . $row['size_capacity']] = $row;
            }

            if (!$inTransaction) $pdo->beginTransaction();

            $seen = [];
            $upd = $pdo->prepare("UPDATE inventory SET total_stock=?, filled_stock=?, empty_stock=?, with_customer_stock=?, sent_to_vendor_stock=?, maintenance_stock=?, borrowed_from_partner_stock=?, lent_to_partner_stock=?, with_partner_stock=?, borrowed_from_vendor_stock=?, returned_to_vendor_stock=?, received_for_refill_stock=? WHERE gas_type_id=? AND size_capacity=?");
            $ins = $pdo->prepare("INSERT INTO inventory (gas_type_id, size_capacity, total_stock, filled_stock, empty_stock, with_customer_stock, sent_to_vendor_stock, maintenance_stock, borrowed_from_partner_stock, lent_to_partner_stock, with_partner_stock, borrowed_from_vendor_stock, returned_to_vendor_stock, received_for_refill_stock) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($categories as $cat) {
                $gas_id = $cat['gas_type_id'];
                $size = $cat['size_capacity'];
                $key = $gas_id . '|' . $size;
                $seen[] = $key;

                $total = countStatus($pdo, $gas_id, $size);
                $filled = countStatus($pdo, $gas_id, $size, 'filled');
                $empty = countStatus($pdo, $gas_id, $size, 'empty');
                $with_cust = countStatus($pdo, $gas_id, $size, 'with_customer');
                $sent_vend = countStatus($pdo, $gas_id, $size, 'sent_to_vendor');
                $maint = countStatus($pdo, $gas_id, $size, 'under_maintenance');
                $borrowed = countStatus($pdo, $gas_id, $size, 'borrowed_from_partner');
                $lent = countStatus($pdo, $gas_id, $size, 'lent_to_partner');
                $with_partner = countStatus($pdo, $gas_id, $size, 'with_partner');
                $borrowed_from_vendor = countStatus($pdo, $gas_id, $size, 'borrowed_from_vendor');
                $returned_to_vendor = countStatus($pdo, $gas_id, $size, 'returned_to_vendor');
                $received_refill = countStatus($pdo, $gas_id, $size, 'received_for_refill');

                if (isset($existing[$key])) {
                    $row = $existing[$key];
                    if ((int)$row['total_stock'] !== $total ||
                        (int)$row['filled_stock'] !== $filled ||
                        (int)$row['empty_stock'] !== $empty ||
                        (int)$row['with_customer_stock'] !== $with_cust ||
                        (int)$row['sent_to_vendor_stock'] !== $sent_vend ||
                        (int)$row['maintenance_stock'] !== $maint ||
                        (int)$row['borrowed_from_partner_stock'] !== $borrowed ||
                        (int)$row['lent_to_partner_stock'] !== $lent ||
                        (int)$row['with_partner_stock'] !== $with_partner ||
                        (int)$row['borrowed_from_vendor_stock'] !== $borrowed_from_vendor ||
                        (int)$row['returned_to_vendor_stock'] !== $returned_to_vendor ||
                        (int)$row['received_for_refill_stock'] !== $received_refill) {
                        $upd->execute([$total, $filled, $empty, $with_cust, $sent_vend, $maint, $borrowed, $lent, $with_partner, $borrowed_from_vendor, $returned_to_vendor, $received_refill, $gas_id, $size]);
                    }
                } else {
                    $ins->execute([$gas_id, $size, $total, $filled, $empty, $with_cust, $sent_vend, $maint, $borrowed, $lent, $with_partner, $borrowed_from_vendor, $returned_to_vendor, $received_refill]);
                }
            }

            $del = $pdo->prepare("DELETE FROM inventory WHERE gas_type_id=? AND size_capacity=?");
            foreach ($existing as $key => $row) {
                if (!in_array($key, $seen)) {
                    $del->execute([$row['gas_type_id'], $row['size_capacity']]);
                }
            }

            if (!$inTransaction) $pdo->commit();
            return true;
        } catch (PDOException $e) {
            if (!$inTransaction && $pdo->inTransaction()) $pdo->rollBack();
            error_log("syncInventory differential failed: " . $e->getMessage());
            return false;
        }
    }
    
    // Internal Helper to count cylinders
    function countStatus($pdo, $gas_id, $size, $status = null) {
        if ($status) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE gas_type_id = ? AND size_capacity = ? AND status = ?");
            $stmt->execute([$gas_id, $size, $status]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE gas_type_id = ? AND size_capacity = ? AND status NOT IN ('returned_to_partner', 'returned_to_consumer', 'returned_to_vendor', 'received_for_refill')");
            $stmt->execute([$gas_id, $size]);
        }
        return $stmt->fetchColumn();
    }
}

if (!function_exists('logCylinderTransaction')) {
    /**
     * Records a lifecycle log entry in `cylinder_transactions` for physical tracking.
     */
    function logCylinderTransaction($pdo, $cylinder_id, $customer_id, $vendor_id, $type, $notes, $ledger_group_id = null) {
        $stmt = $pdo->prepare("
            INSERT INTO cylinder_transactions (cylinder_id, customer_id, vendor_id, transaction_type, notes, ledger_group_id) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$cylinder_id, $customer_id ?: null, $vendor_id ?: null, $type, $notes, $ledger_group_id]);
    }
}

if (!function_exists('settleCylinderExchange')) {
    function settleCylinderExchange($pdo, $cylinder_id, $notes = '') {
        try {
            $stmt = $pdo->prepare("
                SELECT id, ownership_type, status, current_customer_id, original_owner_customer_id, serial_number
                FROM cylinders WHERE id = ?
            ");
            $stmt->execute([$cylinder_id]);
            $cyl = $stmt->fetch();
            if (!$cyl) return false;

            $settled = false;

            if ($cyl['ownership_type'] === 'consumer_owned') {
                if ($cyl['current_customer_id'] !== null && $cyl['current_customer_id'] == $cyl['original_owner_customer_id']) {
                    $pdo->prepare("
                        UPDATE cylinders SET status = 'empty', current_customer_id = NULL WHERE id = ?
                    ")->execute([$cylinder_id]);
                    logCylinderTransaction($pdo, $cylinder_id, $cyl['current_customer_id'], null, 'consumer_give_back', "Exchange settled: consumer-owned cylinder returned to owner. $notes");
                    $settled = true;
                }
            }

            return $settled;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('isCylinderInActiveExchange')) {
    function isCylinderInActiveExchange($cyl) {
        if ($cyl['ownership_type'] === 'consumer_owned') {
            return ($cyl['current_customer_id'] !== null && $cyl['current_customer_id'] != $cyl['original_owner_customer_id']);
        }
        if ($cyl['ownership_type'] === 'partner_owned') {
            return ($cyl['status'] !== 'returned_to_partner' && $cyl['status'] !== 'empty');
        }
        if ($cyl['ownership_type'] === 'owned') {
            return ($cyl['status'] === 'with_customer' || $cyl['status'] === 'sent_to_vendor' || $cyl['status'] === 'lent_to_partner' || $cyl['status'] === 'with_partner');
        }
        return false;
    }
}

if (!function_exists('syncCustomerActiveCylinderCounts')) {
    /**
     * Rebuilds the `active_cylinders_count` value from actual cylinder tracking data.
     * When customer_id is omitted, this updates all customer rows.
     */
    function syncCustomerActiveCylinderCounts($pdo, $customer_id = null) {
        try {
            if ($customer_id) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cylinders WHERE current_customer_id = ? AND status = 'with_customer'");
                $stmt->execute([$customer_id]);
                $count = $stmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE customers SET active_cylinders_count = ? WHERE id = ?");
                $stmt->execute([$count, $customer_id]);
            } else {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE customers SET active_cylinders_count = 0");

                $stmt = $pdo->query("SELECT current_customer_id, COUNT(*) AS cnt FROM cylinders WHERE status = 'with_customer' GROUP BY current_customer_id");
                while ($row = $stmt->fetch()) {
                    $upd = $pdo->prepare("UPDATE customers SET active_cylinders_count = ? WHERE id = ?");
                    $upd->execute([$row['cnt'], $row['current_customer_id']]);
                }

                $pdo->commit();
            }

            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }
}

if (!function_exists('_checkMigrationCache')) {
    function _checkMigrationCache($key, $ttl = 86400) {
        static $cache = [];
        if (isset($cache[$key])) return true;
        $file = __DIR__ . '/../cache/migration_' . md5($key) . '.json';
        if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
            $data = json_decode(file_get_contents($file), true);
            if (!empty($data) && isset($data['completed'])) {
                $cache[$key] = true;
                return true;
            }
        }
        return false;
    }
    function _setMigrationCache($key) {
        static $cache = [];
        $cache[$key] = true;
        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . '/migration_' . md5($key) . '.json';
        file_put_contents($file, json_encode(['key' => $key, 'completed' => true, 'timestamp' => time()]));
    }
}

if (!function_exists('_getTableColumnNames')) {
    function _getTableColumnNames($pdo, $table) {
        static $cache = [];
        if (!isset($cache[$table])) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
                $cols = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
                $cache[$table] = $cols;
            } catch (PDOException $e) {
                return [];
            }
        }
        return $cache[$table];
    }
}

if (!function_exists('_getTableColumnType')) {
    function _getTableColumnType($pdo, $table, $column) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` WHERE Field = " . $pdo->quote($column));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['Type'] : '';
        } catch (PDOException $e) {
            return '';
        }
    }
}

if (!function_exists('runPartnerMigrations')) {
    /**
     * Safely applies database schema migrations needed for the Partner Exchange feature.
     * All operations are idempotent — safe to run multiple times.
     */
    function runPartnerMigrations($pdo) {
        if (_checkMigrationCache('partner_migrations')) return;
        $migrations = [];
        $cyl_cols = _getTableColumnNames($pdo, 'cylinders');
        $inv_cols = _getTableColumnNames($pdo, 'inventory');
        $ct_cols = _getTableColumnNames($pdo, 'cylinder_transactions');
        $pti_cols = _getTableColumnNames($pdo, 'partner_transaction_items');
        $pt_cols = _getTableColumnNames($pdo, 'partner_transactions');
        $vend_cols = _getTableColumnNames($pdo, 'vendors');

        if (!in_array('current_partner_id', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN current_partner_id INT DEFAULT NULL";
        }
        if (!in_array('ownership_type', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN ownership_type ENUM('owned', 'partner_owned') NOT NULL DEFAULT 'owned'";
        }
        if (!in_array('borrow_date', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN borrow_date DATE DEFAULT NULL";
        }
        if (!in_array('daily_rent_rate', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN daily_rent_rate DECIMAL(10,2) DEFAULT 0.00";
        }
        if (!in_array('free_days', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN free_days INT DEFAULT 0";
        }

        $cyl_status_type = _getTableColumnType($pdo, 'cylinders', 'status');
        if ($cyl_status_type && (
            strpos($cyl_status_type, 'borrowed_from_partner') === false ||
            strpos($cyl_status_type, 'returned_to_partner') === false ||
            strpos($cyl_status_type, 'lent_to_partner') === false ||
            strpos($cyl_status_type, 'with_partner') === false
        )) {
            $migrations[] = "ALTER TABLE cylinders MODIFY COLUMN status ENUM('filled', 'empty', 'in_use', 'with_customer', 'sent_to_vendor', 'under_maintenance', 'borrowed_from_partner', 'lent_to_partner', 'with_partner', 'returned_to_partner') NOT NULL DEFAULT 'empty'";
        }

        $cyl_own_type = _getTableColumnType($pdo, 'cylinders', 'ownership_type');
        if ($cyl_own_type && strpos($cyl_own_type, 'vendor_owned') === false) {
            $migrations[] = "ALTER TABLE cylinders MODIFY COLUMN ownership_type ENUM('owned', 'partner_owned', 'consumer_owned', 'vendor_owned') NOT NULL DEFAULT 'owned'";
        }
        if ($cyl_status_type && (strpos($cyl_status_type, 'borrowed_from_vendor') === false || strpos($cyl_status_type, 'returned_to_vendor') === false)) {
            $migrations[] = "ALTER TABLE cylinders MODIFY COLUMN status ENUM('filled', 'empty', 'in_use', 'with_customer', 'sent_to_vendor', 'under_maintenance', 'borrowed_from_partner', 'lent_to_partner', 'with_partner', 'returned_to_partner', 'returned_to_consumer', 'borrowed_from_vendor', 'returned_to_vendor') NOT NULL DEFAULT 'empty'";
        }

        if (!in_array('borrowed_from_partner_stock', $inv_cols)) {
            $migrations[] = "ALTER TABLE inventory ADD COLUMN borrowed_from_partner_stock INT NOT NULL DEFAULT 0";
        }
        if (!in_array('lent_to_partner_stock', $inv_cols)) {
            $migrations[] = "ALTER TABLE inventory ADD COLUMN lent_to_partner_stock INT NOT NULL DEFAULT 0";
        }
        if (!in_array('with_partner_stock', $inv_cols)) {
            $migrations[] = "ALTER TABLE inventory ADD COLUMN with_partner_stock INT NOT NULL DEFAULT 0";
        }
        if (!in_array('borrowed_from_vendor_stock', $inv_cols)) {
            $migrations[] = "ALTER TABLE inventory ADD COLUMN borrowed_from_vendor_stock INT NOT NULL DEFAULT 0 AFTER borrowed_from_partner_stock";
        }
        if (!in_array('returned_to_vendor_stock', $inv_cols)) {
            $migrations[] = "ALTER TABLE inventory ADD COLUMN returned_to_vendor_stock INT NOT NULL DEFAULT 0 AFTER borrowed_from_vendor_stock";
        }

        $ct_type = _getTableColumnType($pdo, 'cylinder_transactions', 'transaction_type');
        if ($ct_type && (
            strpos($ct_type, 'partner_borrow') === false ||
            strpos($ct_type, 'partner_return') === false ||
            strpos($ct_type, 'partner_lend') === false ||
            strpos($ct_type, 'partner_receive_back') === false
        )) {
            $migrations[] = "ALTER TABLE cylinder_transactions MODIFY COLUMN transaction_type ENUM('refill', 'issue_to_customer', 'return_from_customer', 'send_to_vendor', 'receive_from_vendor', 'maintenance', 'partner_borrow', 'partner_return', 'partner_lend', 'partner_receive_back') NOT NULL";
        }
        if ($ct_type && (strpos($ct_type, 'vendor_borrow') === false || strpos($ct_type, 'vendor_return') === false)) {
            $migrations[] = "ALTER TABLE cylinder_transactions MODIFY COLUMN transaction_type ENUM('refill', 'issue_to_customer', 'return_from_customer', 'send_to_vendor', 'receive_from_vendor', 'maintenance', 'partner_borrow', 'partner_return', 'partner_lend', 'partner_receive_back', 'vendor_borrow', 'vendor_return') NOT NULL";
        }

        $migrations[] = "CREATE TABLE IF NOT EXISTS `partners` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_name` VARCHAR(200) NOT NULL,
            `contact_person` VARCHAR(150) DEFAULT NULL,
            `mobile` VARCHAR(20) DEFAULT NULL,
            `email` VARCHAR(150) DEFAULT NULL,
            `address` TEXT DEFAULT NULL,
            `gst_number` VARCHAR(20) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`company_name`),
            INDEX (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $migrations[] = "CREATE TABLE IF NOT EXISTS `partner_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `partner_id` INT NOT NULL,
            `transaction_type` ENUM('borrowed_from_partner', 'returned_to_partner', 'lent_to_partner', 'received_back_from_partner') NOT NULL,
            `transaction_date` DATE NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_by` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE RESTRICT,
            INDEX (`transaction_type`),
            INDEX (`transaction_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $migrations[] = "CREATE TABLE IF NOT EXISTS `partner_transaction_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_id` INT NOT NULL,
            `cylinder_id` INT DEFAULT NULL,
            `serial_number` VARCHAR(100) NOT NULL,
            `gas_type_id` INT NOT NULL,
            `size_capacity` VARCHAR(50) NOT NULL,
            `status_before` VARCHAR(50) DEFAULT NULL,
            `status_after` VARCHAR(50) NOT NULL,
            FOREIGN KEY (`transaction_id`) REFERENCES `partner_transactions` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`cylinder_id`) REFERENCES `cylinders` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`gas_type_id`) REFERENCES `gas_types` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if (!in_array('daily_rent_rate', $pti_cols)) {
            $migrations[] = "ALTER TABLE partner_transaction_items ADD COLUMN daily_rent_rate DECIMAL(10,2) DEFAULT 0.00";
        }
        if (!in_array('free_days', $pti_cols)) {
            $migrations[] = "ALTER TABLE partner_transaction_items ADD COLUMN free_days INT DEFAULT 0";
        }
        if (!in_array('days_held', $pti_cols)) {
            $migrations[] = "ALTER TABLE partner_transaction_items ADD COLUMN days_held INT DEFAULT 0";
        }
        if (!in_array('rent_accrued', $pti_cols)) {
            $migrations[] = "ALTER TABLE partner_transaction_items ADD COLUMN rent_accrued DECIMAL(10,2) DEFAULT 0.00";
        }
        if (!in_array('rent_paid', $pti_cols)) {
            $migrations[] = "ALTER TABLE partner_transaction_items ADD COLUMN rent_paid DECIMAL(10,2) DEFAULT 0.00";
        }
        if (!in_array('damage_amount', $pti_cols)) {
            $migrations[] = "ALTER TABLE partner_transaction_items ADD COLUMN damage_amount DECIMAL(10,2) DEFAULT 0.00";
        }
        if (!in_array('payment_status', $pti_cols)) {
            $migrations[] = "ALTER TABLE partner_transaction_items ADD COLUMN payment_status ENUM('pending', 'cleared') DEFAULT 'cleared'";
        }

        if (!in_array('vendor_id', $pt_cols)) {
            $migrations[] = "ALTER TABLE partner_transactions ADD COLUMN vendor_id INT DEFAULT NULL AFTER partner_id";
            try { $pdo->exec("ALTER TABLE partner_transactions ADD FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT"); } catch (PDOException $e) {}
        }

        $pt_null_check = _getTableColumnType($pdo, 'partner_transactions', 'partner_id');
        if ($pt_null_check && strpos($pt_null_check, 'partner_id') === false) {
            // Can't easily check Null from Type string; use SHOW COLUMNS directly for nullable check
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM partner_transactions WHERE Field = 'partner_id'");
                $pi = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($pi && strtoupper($pi['Null']) === 'NO') {
                    $migrations[] = "ALTER TABLE partner_transactions MODIFY COLUMN partner_id INT DEFAULT NULL";
                }
            } catch (PDOException $e) {}
        }

        $pt_tt = _getTableColumnType($pdo, 'partner_transactions', 'transaction_type');
        if ($pt_tt && (strpos($pt_tt, 'borrowed_from_vendor') === false || strpos($pt_tt, 'returned_to_vendor') === false)) {
            $migrations[] = "ALTER TABLE partner_transactions MODIFY COLUMN transaction_type ENUM('borrowed_from_partner', 'returned_to_partner', 'lent_to_partner', 'received_back_from_partner', 'borrowed_from_vendor', 'returned_to_vendor') NOT NULL";
        }

        if (!in_array('contact_person', $vend_cols)) {
            $migrations[] = "ALTER TABLE vendors ADD COLUMN contact_person VARCHAR(150) DEFAULT NULL AFTER name";
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('partner_migrations');
    }
}

if (!function_exists('runRefillRentalMigrations')) {
    /**
     * Safely applies database migrations for refill rentals, grace periods, and exchange serials.
     */
    function runRefillRentalMigrations($pdo) {
        if (_checkMigrationCache('refill_rental_migrations')) return;
        $migrations = [];
        $roi_cols = _getTableColumnNames($pdo, 'refill_order_items');
        $cyl_cols = _getTableColumnNames($pdo, 'cylinders');

        if (!in_array('returned_cylinder_id', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN returned_cylinder_id INT DEFAULT NULL";
        }
        if (!in_array('rent_per_day', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN rent_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.00";
        }
        if (!in_array('free_days', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN free_days INT NOT NULL DEFAULT 0";
        }
        if (!in_array('damage_amount', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN damage_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER free_days";
        }
        if (!in_array('damage_description', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN damage_description VARCHAR(255) DEFAULT NULL AFTER damage_amount";
        }
        if (!in_array('sell_price', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN sell_price DECIMAL(10,2) NOT NULL DEFAULT 0.00";
        }
        if (!in_array('sold_cylinder_serial', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN sold_cylinder_serial VARCHAR(100) DEFAULT NULL";
        }

        if (!in_array('free_days', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN free_days INT DEFAULT 0";
        }

        try {
            $pdo->query("CREATE INDEX IF NOT EXISTS idx_refill_order_items_returned_cylinder ON refill_order_items (returned_cylinder_id)");
        } catch (PDOException $e) {
            try {
                $pdo->query("CREATE INDEX idx_refill_order_items_returned_cylinder ON refill_order_items (returned_cylinder_id)");
            } catch (PDOException $e2) {}
        }

        $pay_type = _getTableColumnType($pdo, 'payments', 'payment_type');
        if ($pay_type && strpos($pay_type, 'rent_payment') === false) {
            $migrations[] = "ALTER TABLE payments MODIFY COLUMN payment_type ENUM('refill_payment', 'deposit_added', 'deposit_refunded', 'vendor_payment', 'rent_payment') NOT NULL";
        }

        $ro_cols = _getTableColumnNames($pdo, 'refill_orders');
        if (!in_array('business_name', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN business_name VARCHAR(50) DEFAULT 'prem_gas_solution'";
        }
        if (!in_array('vehicle_number', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN vehicle_number VARCHAR(100) DEFAULT NULL";
        }
        if (!in_array('is_credit_order', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN is_credit_order TINYINT(1) NOT NULL DEFAULT 0";
        }

        $migrations[] = "CREATE TABLE IF NOT EXISTS `rental_returns` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT NOT NULL,
            `cylinder_id` INT NOT NULL,
            `refill_order_item_id` INT DEFAULT NULL,
            `borrow_date` DATE NOT NULL,
            `return_date` DATE NOT NULL,
            `chargeable_days` INT NOT NULL DEFAULT 0,
            `daily_rate` DECIMAL(10,2) NOT NULL,
            `rent_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `damage_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `damage_description` VARCHAR(255) DEFAULT NULL,
            `deposit_deducted` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `total_collected` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_method` VARCHAR(50) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`cylinder_id`) REFERENCES `cylinders` (`id`) ON DELETE RESTRICT,
            INDEX (`customer_id`),
            INDEX (`cylinder_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $rr_cols = _getTableColumnNames($pdo, 'rental_returns');
        if (!in_array('damage_description', $rr_cols)) {
            $migrations[] = "ALTER TABLE rental_returns ADD COLUMN damage_description VARCHAR(255) DEFAULT NULL AFTER damage_charge";
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('refill_rental_migrations');
    }
}

if (!function_exists('runConsumerCylinderMigrations')) {
    /**
     * Adds consumer cylinder tracking: consumer_owned ownership type,
     * original_owner_customer_id, returned_to_consumer status, and transaction types.
     */
    function runConsumerCylinderMigrations($pdo) {
        if (_checkMigrationCache('consumer_migrations')) return;
        $migrations = [];
        $cyl_cols = _getTableColumnNames($pdo, 'cylinders');
        $ct_cols = _getTableColumnNames($pdo, 'cylinder_transactions');

        $cyl_own_type = _getTableColumnType($pdo, 'cylinders', 'ownership_type');
        if ($cyl_own_type && strpos($cyl_own_type, 'consumer_owned') === false) {
            $migrations[] = "ALTER TABLE cylinders MODIFY COLUMN ownership_type ENUM('owned', 'partner_owned', 'consumer_owned') NOT NULL DEFAULT 'owned'";
        }

        if (!in_array('original_owner_customer_id', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN original_owner_customer_id INT DEFAULT NULL AFTER current_partner_id";
        }
        if (in_array('original_owner_customer_id', $cyl_cols)) {
            try {
                $fk_exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_NAME = 'cylinders' AND CONSTRAINT_NAME = 'fk_cylinders_original_owner' AND CONSTRAINT_SCHEMA = DATABASE()")->fetchColumn();
                if (!$fk_exists) {
                    $migrations[] = "ALTER TABLE cylinders ADD CONSTRAINT fk_cylinders_original_owner FOREIGN KEY (original_owner_customer_id) REFERENCES customers(id) ON DELETE SET NULL";
                }
            } catch (PDOException $e) {}
        }

        $cyl_status_type = _getTableColumnType($pdo, 'cylinders', 'status');
        if ($cyl_status_type && strpos($cyl_status_type, 'returned_to_consumer') === false) {
            $migrations[] = "ALTER TABLE cylinders MODIFY COLUMN status ENUM('filled', 'empty', 'in_use', 'with_customer', 'sent_to_vendor', 'under_maintenance', 'borrowed_from_partner', 'lent_to_partner', 'with_partner', 'returned_to_partner', 'returned_to_consumer') NOT NULL DEFAULT 'empty'";
        }

        // Also add received_for_refill if not present (for customer cylinder refill service)
        $cyl_status_type = _getTableColumnType($pdo, 'cylinders', 'status');
        if ($cyl_status_type && strpos($cyl_status_type, 'received_for_refill') === false) {
            $migrations[] = "ALTER TABLE cylinders MODIFY COLUMN status ENUM('filled', 'empty', 'in_use', 'with_customer', 'sent_to_vendor', 'under_maintenance', 'borrowed_from_partner', 'lent_to_partner', 'with_partner', 'returned_to_partner', 'returned_to_consumer', 'received_for_refill', 'borrowed_from_vendor', 'returned_to_vendor') NOT NULL DEFAULT 'empty'";
        }

        $ct_type = _getTableColumnType($pdo, 'cylinder_transactions', 'transaction_type');
        if ($ct_type && (strpos($ct_type, 'consumer_return') === false || strpos($ct_type, 'consumer_give_back') === false)) {
            $migrations[] = "ALTER TABLE cylinder_transactions MODIFY COLUMN transaction_type ENUM('refill', 'issue_to_customer', 'return_from_customer', 'send_to_vendor', 'receive_from_vendor', 'maintenance', 'partner_borrow', 'partner_return', 'partner_lend', 'partner_receive_back', 'consumer_return', 'consumer_give_back', 'consumer_dispatch') NOT NULL";
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('consumer_migrations');
    }
}

if (!function_exists('runDepositReceiptMigrations')) {
    function runDepositReceiptMigrations($pdo) {
        if (_checkMigrationCache('deposit_receipt_migrations')) return;
        $migrations = [];
        $migrations[] = "CREATE TABLE IF NOT EXISTS `deposit_receipts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `receipt_number` VARCHAR(100) NOT NULL UNIQUE,
            `payment_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `receipt_date` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
            INDEX (`receipt_number`),
            INDEX (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $migrations[] = "ALTER TABLE `deposit_receipts` MODIFY `receipt_date` DATETIME NOT NULL";
        $migrations[] = "ALTER TABLE `deposit_receipts` ADD COLUMN IF NOT EXISTS `damage_deduction` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `receipt_date`";
        $dr_cols = _getTableColumnNames($pdo, 'deposit_receipts');
        if (!in_array('transaction_label', $dr_cols)) {
            $migrations[] = "ALTER TABLE `deposit_receipts` ADD COLUMN `transaction_label` VARCHAR(50) DEFAULT NULL AFTER `damage_deduction`";
        }
        if (!in_array('total_amount', $dr_cols)) {
            $migrations[] = "ALTER TABLE `deposit_receipts` ADD COLUMN `total_amount` DECIMAL(10,2) DEFAULT NULL AFTER `transaction_label`";
        }
        if (!in_array('credit_settled', $dr_cols)) {
            $migrations[] = "ALTER TABLE `deposit_receipts` ADD COLUMN `credit_settled` DECIMAL(10,2) DEFAULT 0.00 AFTER `total_amount`";
        }
        if (!in_array('deposit_amount', $dr_cols)) {
            $migrations[] = "ALTER TABLE `deposit_receipts` ADD COLUMN `deposit_amount` DECIMAL(10,2) DEFAULT 0.00 AFTER `credit_settled`";
        }
        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('deposit_receipt_migrations');
    }
}

if (!function_exists('runSellCylinderMigrations')) {
    function runSellCylinderMigrations($pdo) {
        if (_checkMigrationCache('sell_cylinder_migrations')) return;
        $migrations = [];
        $ro_cols = _getTableColumnNames($pdo, 'refill_orders');
        $roi_cols = _getTableColumnNames($pdo, 'refill_order_items');
        if (!in_array('vehicle_number', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN vehicle_number VARCHAR(100) DEFAULT NULL";
        }
        if (!in_array('sell_price', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN sell_price DECIMAL(10,2) NOT NULL DEFAULT 0.00";
        }
        if (!in_array('sold_cylinder_serial', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN sold_cylinder_serial VARCHAR(100) DEFAULT NULL";
        }

        // Add 'archived' to cylinders.status ENUM (was missing — caused sold cylinders to get empty string status)
        $cyl_status_type = _getTableColumnType($pdo, 'cylinders', 'status');
        if ($cyl_status_type && strpos($cyl_status_type, 'archived') === false) {
            $migrations[] = "ALTER TABLE cylinders MODIFY COLUMN status ENUM('filled', 'empty', 'in_use', 'with_customer', 'sent_to_vendor', 'under_maintenance', 'borrowed_from_partner', 'lent_to_partner', 'with_partner', 'returned_to_partner', 'returned_to_consumer', 'received_for_refill', 'borrowed_from_vendor', 'returned_to_vendor', 'archived') NOT NULL DEFAULT 'empty'";
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }

        // Fix existing cylinders that got empty status from the missing 'archived' ENUM value
        try {
            $pdo->exec("UPDATE cylinders SET status = 'archived' WHERE status = '' AND lifecycle_status = 'archived'");
        } catch (PDOException $e) {}

        _setMigrationCache('sell_cylinder_migrations');
    }
}

if (!function_exists('runCreditMigrations')) {
    function runCreditMigrations($pdo) {
        if (_checkMigrationCache('credit_migrations')) return;
        $migrations = [];
        $cust_cols = _getTableColumnNames($pdo, 'customers');
        if (!in_array('credit_limit', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deposit_balance";
        }
        if (!in_array('credit_used', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN credit_used DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER credit_limit";
        }
        if (!in_array('credit_status', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN credit_status ENUM('good', 'warning', 'blocked') NOT NULL DEFAULT 'good' AFTER credit_used";
        }
        if (!in_array('credit_terms', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN credit_terms INT DEFAULT 30 AFTER credit_status";
        }

        $migrations[] = "CREATE TABLE IF NOT EXISTS `credit_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT NOT NULL,
            `refill_order_id` INT DEFAULT NULL,
            `transaction_type` ENUM('charge', 'payment', 'adjustment') NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `balance_after` DECIMAL(10,2) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `transaction_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `due_date` DATE DEFAULT NULL,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`refill_order_id`) REFERENCES `refill_orders` (`id`) ON DELETE SET NULL,
            INDEX (`customer_id`),
            INDEX (`transaction_date`),
            INDEX (`due_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $ro_cols = _getTableColumnNames($pdo, 'refill_orders');
        if (!in_array('is_credit_order', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN is_credit_order TINYINT(1) NOT NULL DEFAULT 0";
        }

        $pay_type = _getTableColumnType($pdo, 'payments', 'payment_type');
        if ($pay_type && (
            strpos($pay_type, 'credit_charge') === false ||
            strpos($pay_type, 'deposit_damage') === false ||
            strpos($pay_type, 'exchange_charge') === false
        )) {
            $migrations[] = "ALTER TABLE payments MODIFY COLUMN payment_type ENUM('refill_payment', 'deposit_added', 'deposit_refunded', 'vendor_payment', 'rent_payment', 'credit_charge', 'deposit_damage', 'exchange_charge') NOT NULL";
        }

        $ct_type = _getTableColumnType($pdo, 'cylinder_transactions', 'transaction_type');
        if ($ct_type && strpos($ct_type, 'consumer_dispatch') === false) {
            $migrations[] = "ALTER TABLE cylinder_transactions MODIFY COLUMN transaction_type ENUM('refill', 'issue_to_customer', 'return_from_customer', 'send_to_vendor', 'receive_from_vendor', 'maintenance', 'partner_borrow', 'partner_return', 'partner_lend', 'partner_receive_back', 'consumer_return', 'consumer_give_back', 'consumer_dispatch') NOT NULL";
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('credit_migrations');
    }
}

if (!function_exists('runDeletedCylindersMigration')) {
    function runDeletedCylindersMigration($pdo) {
        if (_checkMigrationCache('deleted_cylinders_migration')) return;
        // Fix FK on cylinder_transactions: remove ON DELETE CASCADE so logs persist after cylinder deletion
        try {
            $fk_check = $pdo->query("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'cylinder_transactions'
                  AND COLUMN_NAME = 'cylinder_id'
                  AND REFERENCED_TABLE_NAME = 'cylinders'
            ")->fetch();
            if ($fk_check) {
                $fk_name = $fk_check['CONSTRAINT_NAME'];
                if (preg_match('/^[a-zA-Z0-9_]+$/', $fk_name)) {
                    $pdo->exec("ALTER TABLE cylinder_transactions DROP FOREIGN KEY `$fk_name`");
                }
            }
        } catch (PDOException $e) {}
        _setMigrationCache('deleted_cylinders_migration');
    }
}

if (!function_exists('archiveDeletedCylinder')) {
    function archiveDeletedCylinder($pdo, $cylinder_id, $deleted_by = 'unknown') {
        $stmt = $pdo->prepare("SELECT * FROM cylinders WHERE id = ?");
        $stmt->execute([$cylinder_id]);
        $cyl = $stmt->fetch();

        if (!$cyl) {
            throw new Exception("Cannot archive: cylinder #$cylinder_id not found.");
        }

        $txn_stmt = $pdo->prepare("SELECT * FROM cylinder_transactions WHERE cylinder_id = ? ORDER BY transaction_date ASC");
        $txn_stmt->execute([$cylinder_id]);
        $transactions = $txn_stmt->fetchAll(PDO::FETCH_ASSOC);

        $transaction_log = json_encode($transactions);

        $upd = $pdo->prepare("UPDATE cylinders SET deleted_at = NOW(), deleted_by = ?, transaction_log = ?, status = 'archived', lifecycle_status = 'archived' WHERE id = ?");
        $upd->execute([$deleted_by, $transaction_log, $cylinder_id]);

        return true;
    }
}

if (!function_exists('processRentalReturn')) {
    function processRentalReturn($pdo, $data) {
        $cylinder_id = intval($data['cylinder_id']);
        $customer_id = intval($data['customer_id']);
        $return_date = $data['return_date'] ?? date('Y-m-d');
        $condition = $data['condition'] ?? 'empty';
        $damage_charge = floatval($data['damage_charge'] ?? 0);
        $damage_description = trim($data['damage_description'] ?? '');
        $deduct_from_deposit = floatval($data['deduct_from_deposit'] ?? 0);
        $payment_method = trim($data['payment_method'] ?? 'cash');
        $notes = trim($data['notes'] ?? '');
        $refill_order_item_id = !empty($data['refill_order_item_id']) ? intval($data['refill_order_item_id']) : null;
        $ledger_group_id = !empty($data['ledger_group_id']) ? $data['ledger_group_id'] : generateLedgerGroupId();

        // Fetch cylinder
        $stmt = $pdo->prepare("SELECT c.*, g.name as gas_name FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id WHERE c.id = ? AND c.status = 'with_customer' AND c.current_customer_id = ?");
        $stmt->execute([$cylinder_id, $customer_id]);
        $cylinder = $stmt->fetch();

        if (!$cylinder) {
            throw new Exception("Cylinder not found or not currently with this customer.");
        }

        if (!$cylinder['borrow_date']) {
            throw new Exception("Cylinder has no borrow date — cannot calculate rent.");
        }

        $borrow_date = $cylinder['borrow_date'];
        $daily_rate = floatval($cylinder['daily_rent_rate'] ?? 0);
        $free_days = intval($cylinder['free_days'] ?? 0);

        $borrow_ts = strtotime($borrow_date);
        $return_ts = strtotime($return_date);
        if ($return_ts < $borrow_ts) {
            throw new Exception("Return date cannot be before borrow date.");
        }

        $days_held = floor(($return_ts - $borrow_ts) / 86400);
        $chargeable_days = max(0, $days_held - $free_days);
        $rent_amount = $chargeable_days * $daily_rate;
        $total_charges = $rent_amount + $damage_charge;
        $deposit_deducted = min($deduct_from_deposit, $total_charges);
        $total_collected = $total_charges - $deposit_deducted;

        $pdo->beginTransaction();

        try {
            // 1. Insert rental_returns record
            $stmt = $pdo->prepare("
                INSERT INTO rental_returns
                    (customer_id, cylinder_id, refill_order_item_id, borrow_date, return_date,
                     chargeable_days, daily_rate, rent_amount, damage_charge, damage_description,
                     deposit_deducted, total_collected, payment_method, notes, ledger_group_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $customer_id, $cylinder_id, $refill_order_item_id, $borrow_date, $return_date,
                $chargeable_days, $daily_rate, $rent_amount, $damage_charge,
                $damage_description ?: null,
                $deposit_deducted, $total_collected, $payment_method, $notes ?: null,
                $ledger_group_id
            ]);
            $return_id = $pdo->lastInsertId();

            // 2. Create rent payment
            if ($total_collected > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, ledger_group_id)
                    VALUES (?, NULL, ?, ?, 'rent_payment', ?, ?)
                ");
                $stmt->execute([$customer_id, $total_collected, $payment_method, $notes ?: null, $ledger_group_id]);
            }

            // 3. Handle deposit deduction
            if ($deposit_deducted > 0) {
                $stmt = $pdo->prepare("UPDATE customers SET deposit_balance = GREATEST(0, deposit_balance - ?) WHERE id = ?");
                $stmt->execute([$deposit_deducted, $customer_id]);

                // Look up the original order to update deposit_settled
                $deposit_refill_order_id = null;
                if ($refill_order_item_id) {
                    $roi_stmt = $pdo->prepare("SELECT refill_order_id FROM refill_order_items WHERE id = ?");
                    $roi_stmt->execute([$refill_order_item_id]);
                    $deposit_refill_order_id = $roi_stmt->fetchColumn();
                }
                if ($deposit_refill_order_id) {
                    $pdo->prepare("UPDATE refill_orders SET deposit_settled = deposit_settled + ? WHERE id = ?")
                        ->execute([$deposit_deducted, $deposit_refill_order_id]);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, ledger_group_id)
                    VALUES (?, ?, ?, ?, 'deposit_refunded', ?, ?)
                ");
                $stmt->execute([$customer_id, $deposit_refill_order_id, -$deposit_deducted, $payment_method, 'Deducted from deposit for rental return #' . $return_id, $ledger_group_id]);
            }

            // 4. Update cylinder status
            $new_status = ($condition === 'filled') ? 'filled' : 'empty';
            $stmt = $pdo->prepare("
                UPDATE cylinders
                SET status = ?, current_customer_id = NULL, borrow_date = NULL
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $cylinder_id]);

            // 5. Log transaction
            logCylinderTransaction($pdo, $cylinder_id, $customer_id, null, 'return_from_customer', $notes ?: 'Rental return processed');

            // 6. Sync counts
            syncCustomerActiveCylinderCounts($pdo, $customer_id);

            // 7. Create ledger group entry
            $serial_label = $cylinder['serial_number'] ?? '';
            $gas_label = ($cylinder['gas_name'] ?? '') . ' (' . ($cylinder['size_capacity'] ?? '') . ')';
            $group_title = 'Rental Return – ' . $serial_label;
            $collected_label = '₹' . number_format($total_collected, 2) . ' collected';
            if ($deposit_deducted > 0) {
                $group_title .= ' (Deposit ₹' . number_format($deposit_deducted, 2) . ' deducted)';
            } else {
                $group_title .= ' (' . $collected_label . ')';
            }
            $stmt = $pdo->prepare("INSERT INTO ledger_groups (id, customer_id, group_type, title, total_amount, entry_date) VALUES (?, ?, 'rental_return', ?, ?, ?)");
            $stmt->execute([$ledger_group_id, $customer_id, $group_title, $total_collected, $return_date]);

            $pdo->commit();

            // Send rental settlement email
            require_once __DIR__ . '/../portal/email.php';
            $gas_stmt = $pdo->prepare("SELECT g.name FROM gas_types g JOIN cylinders c ON c.gas_type_id = g.id WHERE c.id = ?");
            $gas_stmt->execute([$cylinder_id]);
            $gas_name = $gas_stmt->fetchColumn() ?: 'Gas';
            $size = $cylinder['size_capacity'] ?? '';
            $serial = $cylinder['serial_number'] ?? '';
            sendRentalSettlementNotification($customer_id, $serial, $gas_name, $size, $rent_amount, $damage_charge, $deposit_deducted, $total_collected, $payment_method, $pdo);

            return ['return_id' => $return_id, 'group_id' => $ledger_group_id];

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('runGasSizesMigration')) {
    function runGasSizesMigration($pdo) {
        if (_checkMigrationCache('gas_sizes_migration')) return;
        try {
            $pdo->query("SELECT 1 FROM gas_sizes LIMIT 0");
        } catch (PDOException $e) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `gas_sizes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `gas_type_id` INT NOT NULL,
                `size_capacity` VARCHAR(50) NOT NULL,
                `price` DECIMAL(10,2) DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `gas_size` (`gas_type_id`, `size_capacity`),
                FOREIGN KEY (`gas_type_id`) REFERENCES `gas_types` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $count = $pdo->query("SELECT COUNT(*) FROM gas_sizes")->fetchColumn();
        if ($count === 0) {
            try {
                $stmt = $pdo->query("SELECT id, sizes, size_prices FROM gas_types WHERE sizes IS NOT NULL");
                while ($row = $stmt->fetch()) {
                    $sizes = array_map('trim', explode(',', $row['sizes']));
                    $prices = [];
                    if (!empty($row['size_prices'])) {
                        $prices = json_decode($row['size_prices'], true) ?? [];
                    }
                    $order = 0;
                    $ins = $pdo->prepare("INSERT IGNORE INTO gas_sizes (gas_type_id, size_capacity, price, sort_order) VALUES (?, ?, ?, ?)");
                    foreach ($sizes as $size) {
                        if (empty($size)) continue;
                        $price = isset($prices[$size]) ? floatval($prices[$size]) : null;
                        $ins->execute([$row['id'], $size, $price, $order]);
                        $order++;
                    }
                }
            } catch (PDOException $e) {
                // Legacy columns (sizes, size_prices) may not exist after consolidation
            }
        }
        _setMigrationCache('gas_sizes_migration');
    }
}

if (!function_exists('runCustomerPortalMigrations')) {
    function runCustomerPortalMigrations($pdo) {
        if (_checkMigrationCache('customer_portal_migrations')) return;
        $migrations = [];
        $cust_cols = _getTableColumnNames($pdo, 'customers');
        if (!in_array('email', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN email VARCHAR(100) UNIQUE DEFAULT NULL AFTER mobile";
        }
        if (!in_array('password_hash', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER email";
        }
        if (!in_array('login_enabled', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 0";
        }
        if (!in_array('last_login', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL";
        }
        if (!in_array('remember_token', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN remember_token VARCHAR(255) DEFAULT NULL";
        }
        if (!in_array('remember_expires', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN remember_expires DATETIME DEFAULT NULL";
        }
        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {
                error_log("runCustomerPortalMigrations: " . $e->getMessage());
            }
        }
        _setMigrationCache('customer_portal_migrations');
    }
}

if (!function_exists('runDatabaseIndexes')) {
    function runDatabaseIndexes($pdo) {
        if (_checkMigrationCache('database_indexes')) return;
        $indexes = [
            ["ALTER TABLE cylinders ADD INDEX IF NOT EXISTS idx_status (status)", "cylinders", "idx_status"],
            ["ALTER TABLE cylinders ADD INDEX IF NOT EXISTS idx_customer_id (current_customer_id)", "cylinders", "idx_customer_id"],
            ["ALTER TABLE cylinders ADD INDEX IF NOT EXISTS idx_gas_type (gas_type_id)", "cylinders", "idx_gas_type"],
            ["ALTER TABLE cylinders ADD INDEX IF NOT EXISTS idx_ownership (ownership_type)", "cylinders", "idx_ownership"],
            ["ALTER TABLE refill_orders ADD INDEX IF NOT EXISTS idx_customer_id (customer_id)", "refill_orders", "idx_customer_id"],
            ["ALTER TABLE refill_orders ADD INDEX IF NOT EXISTS idx_order_date (order_date)", "refill_orders", "idx_order_date"],
            ["ALTER TABLE refill_orders ADD INDEX IF NOT EXISTS idx_payment_status (payment_status)", "refill_orders", "idx_payment_status"],
            ["ALTER TABLE customers ADD INDEX IF NOT EXISTS idx_mobile (mobile)", "customers", "idx_mobile"],
            ["ALTER TABLE customers ADD INDEX IF NOT EXISTS idx_status (status)", "customers", "idx_status"],
            ["ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_customer_id (customer_id)", "payments", "idx_customer_id"],
            ["ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_payment_date (payment_date)", "payments", "idx_payment_date"],
            ["ALTER TABLE cylinder_transactions ADD INDEX IF NOT EXISTS idx_cylinder_id (cylinder_id)", "cylinder_transactions", "idx_cylinder_id"],
            ["ALTER TABLE cylinder_transactions ADD INDEX IF NOT EXISTS idx_customer_id (customer_id)", "cylinder_transactions", "idx_customer_id"],
            ["ALTER TABLE cylinder_transactions ADD INDEX IF NOT EXISTS idx_transaction_date (transaction_date)", "cylinder_transactions", "idx_transaction_date"],
        ];
        foreach ($indexes as $item) {
            $sql = $item[0]; $table = $item[1]; $idx = $item[2];
            try {
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `$idx` ($idx)");
            } catch (PDOException $e) {
                // Index likely exists
            }
        }
        _setMigrationCache('database_indexes');
    }
}

if (!function_exists('generateLedgerGroupId')) {
    function generateLedgerGroupId() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('runLedgerGroupMigrations')) {
    function runLedgerGroupMigrations($pdo) {
        if (_checkMigrationCache('ledger_group_migrations')) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ledger_groups` (
                `id` varchar(36) NOT NULL,
                `customer_id` int(11) NOT NULL,
                `group_type` varchar(50) NOT NULL COMMENT 'payment_received, payment_refunded, rental_return, credit_order, deposit_added',
                `title` varchar(255) DEFAULT NULL,
                `total_amount` decimal(10,2) DEFAULT 0.00,
                `entry_date` datetime DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `customer_id` (`customer_id`),
                KEY `entry_date` (`entry_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log("ledger_groups table creation: " . $e->getMessage());
        }
        $col_map = [
            'payments' => _getTableColumnNames($pdo, 'payments'),
            'credit_transactions' => _getTableColumnNames($pdo, 'credit_transactions'),
            'rental_returns' => _getTableColumnNames($pdo, 'rental_returns'),
            'deposit_receipts' => _getTableColumnNames($pdo, 'deposit_receipts'),
            'cylinder_transactions' => _getTableColumnNames($pdo, 'cylinder_transactions'),
        ];
        $checks = [
            ['payments', 'ledger_group_id'],
            ['credit_transactions', 'ledger_group_id'],
            ['rental_returns', 'ledger_group_id'],
            ['deposit_receipts', 'ledger_group_id'],
            ['cylinder_transactions', 'ledger_group_id'],
        ];
        foreach ($checks as $c) {
            if (!in_array($c[1], $col_map[$c[0]])) {
                try { $pdo->exec("ALTER TABLE `{$c[0]}` ADD COLUMN `{$c[1]}` VARCHAR(36) DEFAULT NULL"); } catch (PDOException $e) {}
            }
        }
        _setMigrationCache('ledger_group_migrations');
    }
}

if (!function_exists('runProductMigrations')) {
    function runProductMigrations($pdo) {
        if (_checkMigrationCache('product_migrations')) {
            // Verify table actually exists before relying on cache
            try {
                $pdo->query("SELECT 1 FROM products LIMIT 1");
                return;
            } catch (PDOException $e) {
                // Table missing despite cache — force re-run
                error_log("runProductMigrations: table missing despite cache, re-running");
            }
        }
        $migrations = [];
        $migrations[] = "CREATE TABLE IF NOT EXISTS `products` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `description` TEXT,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `purchase_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `stock_quantity` INT NOT NULL DEFAULT 0,
            `unit` VARCHAR(50) DEFAULT 'piece',
            `min_alert_threshold` INT NOT NULL DEFAULT 5,
            `gst_rate` DECIMAL(5,2) DEFAULT NULL,
            `sku` VARCHAR(100) DEFAULT NULL,
            `category_id` INT DEFAULT NULL,
            `brand` VARCHAR(100) DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `reorder_point` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $roi_cols = _getTableColumnNames($pdo, 'refill_order_items');
        if (!in_array('product_id', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN product_id INT DEFAULT NULL AFTER gas_type_id";
        }
        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {
                error_log("runProductMigrations: " . $e->getMessage());
            }
        }
        try {
            $pdo->exec("ALTER TABLE refill_order_items MODIFY COLUMN gas_type_id INT DEFAULT NULL");
        } catch (PDOException $e) {}
        // Verify table exists before caching migration as complete
        try {
            $pdo->query("SELECT 1 FROM products LIMIT 1");
            _setMigrationCache('product_migrations');
        } catch (PDOException $e) {
            error_log("runProductMigrations: products table still missing after migration - " . $e->getMessage());
        }
    }
}

// ── Product categories table ──
if (!function_exists('runProductCategoryMigrations')) {
    function runProductCategoryMigrations($pdo) {
        if (_checkMigrationCache('product_categories')) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `product_categories` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `description` TEXT,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log("runProductCategoryMigrations: " . $e->getMessage());
        }
        _setMigrationCache('product_categories');
    }
}

// ── Product new columns (gst_rate, sku, category_id, brand, is_active, reorder_point) ──
if (!function_exists('addProductNewColumns')) {
    function addProductNewColumns($pdo) {
        if (_checkMigrationCache('product_new_columns')) return;
        $prod_cols = _getTableColumnNames($pdo, 'products');
        foreach ([
            'gst_rate' => 'ADD COLUMN gst_rate DECIMAL(5,2) DEFAULT NULL',
            'sku' => 'ADD COLUMN sku VARCHAR(100) DEFAULT NULL',
            'category_id' => 'ADD COLUMN category_id INT DEFAULT NULL',
            'brand' => 'ADD COLUMN brand VARCHAR(100) DEFAULT NULL',
            'is_active' => 'ADD COLUMN is_active TINYINT(1) DEFAULT 1',
            'reorder_point' => 'ADD COLUMN reorder_point INT NOT NULL DEFAULT 0',
        ] as $col => $alter) {
            if (!in_array($col, $prod_cols)) {
                try { $pdo->exec("ALTER TABLE products $alter"); }
                catch (PDOException $e) { error_log("addProductNewColumns($col): " . $e->getMessage()); }
            }
        }
        _setMigrationCache('product_new_columns');
    }
}

// ── Refill orders product columns (po_number, due_date, delivery_note) ──
if (!function_exists('runRefillOrderProductColumns')) {
    function runRefillOrderProductColumns($pdo) {
        if (_checkMigrationCache('refill_order_product_cols')) return;
        $ro_cols = _getTableColumnNames($pdo, 'refill_orders');
        foreach ([
            'po_number' => 'ADD COLUMN po_number VARCHAR(100) DEFAULT NULL',
            'due_date' => 'ADD COLUMN due_date DATE DEFAULT NULL',
            'delivery_note' => 'ADD COLUMN delivery_note VARCHAR(100) DEFAULT NULL',
        ] as $col => $alter) {
            if (!in_array($col, $ro_cols)) {
                try { $pdo->exec("ALTER TABLE refill_orders $alter"); }
                catch (PDOException $e) { error_log("runRefillOrderProductColumns($col): " . $e->getMessage()); }
            }
        }
        _setMigrationCache('refill_order_product_cols');
    }
}

// Run independently — not guarded by product_migrations cache
if (!function_exists('addProductHsnCodeColumn')) {
    function addProductHsnCodeColumn($pdo) {
        $prod_cols = _getTableColumnNames($pdo, 'products');
        if (!in_array('hsn_code', $prod_cols)) {
            try {
                $pdo->exec("ALTER TABLE products ADD COLUMN hsn_code VARCHAR(8) DEFAULT NULL");
            } catch (PDOException $e) {
                error_log("addProductHsnCodeColumn: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('runProductPurchasePriceMigration')) {
    function runProductPurchasePriceMigration($pdo) {
        if (_checkMigrationCache('product_purchase_price')) return;
        $prod_cols = _getTableColumnNames($pdo, 'products');
        if (!in_array('purchase_price', $prod_cols)) {
            try {
                $pdo->exec("ALTER TABLE products ADD COLUMN purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price");
            } catch (PDOException $e) {
                error_log("runProductPurchasePriceMigration: " . $e->getMessage());
            }
        }
        _setMigrationCache('product_purchase_price');
    }
}

if (!function_exists('runCylinderPurchasePriceMigration')) {
    function runCylinderPurchasePriceMigration($pdo) {
        if (_checkMigrationCache('cylinder_purchase_price')) return;
        $cyl_cols = _getTableColumnNames($pdo, 'cylinders');
        if (!in_array('purchase_price', $cyl_cols)) {
            try {
                $pdo->exec("ALTER TABLE cylinders ADD COLUMN purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER purchase_date");
            } catch (PDOException $e) {
                error_log("runCylinderPurchasePriceMigration: " . $e->getMessage());
            }
        }
        _setMigrationCache('cylinder_purchase_price');
    }
}

if (!function_exists('runSupplierTypeMigration')) {
    function runSupplierTypeMigration($pdo) {
        $cols = _getTableColumnNames($pdo, 'cylinder_suppliers');
        if (!in_array('supplier_type', $cols)) {
            try {
                $pdo->exec("ALTER TABLE cylinder_suppliers ADD COLUMN supplier_type ENUM('cylinder','product','both') NOT NULL DEFAULT 'cylinder' AFTER gst_number");
            } catch (PDOException $e) {
                error_log("runSupplierTypeMigration: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('runCustomerRefillMigrations')) {
    function runCustomerRefillMigrations($pdo) {
        if (_checkMigrationCache('customer_refill_migrations')) return;
        $migrations = [];
        $crs_cols = _getTableColumnNames($pdo, 'customer_refill_services');
        $cyl_cols = _getTableColumnNames($pdo, 'cylinders');

        // 1. Create customer_refill_services table (initial)
        $migrations[] = "CREATE TABLE IF NOT EXISTS `customer_refill_services` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT NOT NULL,
            `cylinder_id` INT DEFAULT NULL,
            `serial_number` VARCHAR(100) NOT NULL,
            `gas_type_id` INT NOT NULL,
            `size_capacity` VARCHAR(50) NOT NULL,
            `vendor_id` INT DEFAULT NULL,
            `refill_order_id` INT DEFAULT NULL,
            `service_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `service_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `vendor_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_method` VARCHAR(50) DEFAULT 'Cash',
            `payment_status` ENUM('paid','pending','partial') DEFAULT 'pending',
            `status` ENUM('received','sent_to_vendor','filled_from_vendor','returned_to_customer','cancelled') NOT NULL DEFAULT 'received',
            `notes` TEXT,
            `completed_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`gas_type_id`) REFERENCES `gas_types` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`cylinder_id`) REFERENCES `cylinders` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`refill_order_id`) REFERENCES `refill_orders` (`id`) ON DELETE SET NULL,
            INDEX (`customer_id`),
            INDEX (`cylinder_id`),
            INDEX (`status`),
            INDEX (`refill_order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 2. Add returned_to_warehouse to customer_refill_services.status ENUM
        $crs_status_type = _getTableColumnType($pdo, 'customer_refill_services', 'status');
        if ($crs_status_type && strpos($crs_status_type, 'returned_to_warehouse') === false) {
            $migrations[] = "ALTER TABLE customer_refill_services MODIFY COLUMN status ENUM('received','sent_to_vendor','filled_from_vendor','returned_to_warehouse','returned_to_customer','cancelled') NOT NULL DEFAULT 'received'";
        }

        // 3. Add refill_source column
        if (!in_array('refill_source', $crs_cols)) {
            $migrations[] = "ALTER TABLE customer_refill_services ADD COLUMN refill_source ENUM('vendor','warehouse') NOT NULL DEFAULT 'vendor' AFTER vendor_cost";
        }

        // 4. Add timestamp columns for each stage
        if (!in_array('sent_to_vendor_at', $crs_cols)) {
            $migrations[] = "ALTER TABLE customer_refill_services ADD COLUMN sent_to_vendor_at TIMESTAMP NULL DEFAULT NULL AFTER status";
        }
        if (!in_array('filled_from_vendor_at', $crs_cols)) {
            $migrations[] = "ALTER TABLE customer_refill_services ADD COLUMN filled_from_vendor_at TIMESTAMP NULL DEFAULT NULL AFTER sent_to_vendor_at";
        }
        if (!in_array('returned_to_warehouse_at', $crs_cols)) {
            $migrations[] = "ALTER TABLE customer_refill_services ADD COLUMN returned_to_warehouse_at TIMESTAMP NULL DEFAULT NULL AFTER filled_from_vendor_at";
        }
        if (!in_array('returned_to_customer_at', $crs_cols)) {
            $migrations[] = "ALTER TABLE customer_refill_services ADD COLUMN returned_to_customer_at TIMESTAMP NULL DEFAULT NULL AFTER returned_to_warehouse_at";
        }

        // 5. Add is_customer_refill_cylinder to cylinders table
        if (!in_array('is_customer_refill_cylinder', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN is_customer_refill_cylinder TINYINT(1) NOT NULL DEFAULT 0 AFTER original_owner_customer_id";
        }

        // 6. Add returned_to_warehouse to cylinder_transactions.transaction_type ENUM
        $ct_type = _getTableColumnType($pdo, 'cylinder_transactions', 'transaction_type');
        if ($ct_type && strpos($ct_type, 'returned_to_warehouse') === false) {
            $migrations[] = "ALTER TABLE cylinder_transactions MODIFY COLUMN transaction_type ENUM('refill', 'issue_to_customer', 'return_from_customer', 'send_to_vendor', 'receive_from_vendor', 'maintenance', 'partner_borrow', 'partner_return', 'partner_lend', 'partner_receive_back', 'consumer_return', 'consumer_give_back', 'consumer_dispatch', 'received_for_refill', 'returned_after_refill', 'returned_to_warehouse') NOT NULL";
        }

        // 7. Add payment_type for refill service
        $pay_type = _getTableColumnType($pdo, 'payments', 'payment_type');
        if ($pay_type && strpos($pay_type, 'refill_service_payment') === false) {
            $migrations[] = "ALTER TABLE payments MODIFY COLUMN payment_type ENUM('refill_payment', 'deposit_added', 'deposit_refunded', 'vendor_payment', 'refill_service_payment') NOT NULL";
        }

        // 8. Add received_for_refill_stock to inventory table
        $inv_cols = _getTableColumnNames($pdo, 'inventory');
        if (!in_array('received_for_refill_stock', $inv_cols)) {
            $migrations[] = "ALTER TABLE inventory ADD COLUMN received_for_refill_stock INT NOT NULL DEFAULT 0 AFTER returned_to_vendor_stock";
        }

        // 9. Add customer_refill_service_id to payments table
        $pay_cols = _getTableColumnNames($pdo, 'payments');
        if (!in_array('customer_refill_service_id', $pay_cols)) {
            $migrations[] = "ALTER TABLE payments ADD COLUMN customer_refill_service_id INT DEFAULT NULL AFTER refill_order_id";
        }

        // 10. Add vendor_id and vendor_cost to refill_order_items for Type 2
        $roi_cols = _getTableColumnNames($pdo, 'refill_order_items');
        if (!in_array('vendor_id', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN vendor_id INT DEFAULT NULL AFTER cylinder_id";
        }
        if (!in_array('vendor_cost', $roi_cols)) {
            $migrations[] = "ALTER TABLE refill_order_items ADD COLUMN vendor_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price_per_unit";
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {
                error_log("runCustomerRefillMigrations: " . $e->getMessage());
            }
        }
        _setMigrationCache('customer_refill_migrations');
    }
}

if (!function_exists('runRefillCostMigrations')) {
    function runRefillCostMigrations($pdo) {
        if (_checkMigrationCache('refill_cost_migrations')) return;

        $all_ok = true;

        // 1. Add refill_cost and size_refill_costs to gas_types
        $gt_cols = _getTableColumnNames($pdo, 'gas_types');
        if (!in_array('refill_cost', $gt_cols)) {
            try { $pdo->exec("ALTER TABLE gas_types ADD COLUMN refill_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER default_price_per_kg"); } catch (PDOException $e) { error_log("runRefillCostMigrations gas_types.refill_cost: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('size_refill_costs', $gt_cols)) {
            try { $pdo->exec("ALTER TABLE gas_types ADD COLUMN size_refill_costs TEXT DEFAULT NULL AFTER hsn_code"); } catch (PDOException $e) { error_log("runRefillCostMigrations gas_types.size_refill_costs: " . $e->getMessage()); $all_ok = false; }
        }

        // 2. Add current_refill_cost and last_refill_vendor_id and last_refill_batch_id to cylinders
        $cyl_cols = _getTableColumnNames($pdo, 'cylinders');
        if (!in_array('current_refill_cost', $cyl_cols)) {
            if (!in_array('purchase_price', $cyl_cols)) {
                try { $pdo->exec("ALTER TABLE cylinders ADD COLUMN current_refill_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00"); } catch (PDOException $e) { error_log("runRefillCostMigrations cylinders.current_refill_cost: " . $e->getMessage()); $all_ok = false; }
            } else {
                try { $pdo->exec("ALTER TABLE cylinders ADD COLUMN current_refill_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER purchase_price"); } catch (PDOException $e) { error_log("runRefillCostMigrations cylinders.current_refill_cost: " . $e->getMessage()); $all_ok = false; }
            }
        }
        if (!in_array('last_refill_vendor_id', $cyl_cols)) {
            try { $pdo->exec("ALTER TABLE cylinders ADD COLUMN last_refill_vendor_id INT DEFAULT NULL AFTER current_refill_cost"); } catch (PDOException $e) { error_log("runRefillCostMigrations cylinders.last_refill_vendor_id: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('last_refill_batch_id', $cyl_cols)) {
            try { $pdo->exec("ALTER TABLE cylinders ADD COLUMN last_refill_batch_id INT DEFAULT NULL AFTER last_refill_vendor_id"); } catch (PDOException $e) { error_log("runRefillCostMigrations cylinders.last_refill_batch_id: " . $e->getMessage()); $all_ok = false; }
        }

        // 3. Add refill_cost to refill_order_items
        $roi_cols = _getTableColumnNames($pdo, 'refill_order_items');
        if (!in_array('refill_cost', $roi_cols)) {
            try { $pdo->exec("ALTER TABLE refill_order_items ADD COLUMN refill_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vendor_cost"); } catch (PDOException $e) { error_log("runRefillCostMigrations refill_order_items.refill_cost: " . $e->getMessage()); $all_ok = false; }
        }

        // 4. Create vendor_refill_batches table
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `vendor_refill_batches` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `vendor_id` INT NOT NULL,
                `gas_type_id` INT NOT NULL,
                `size_capacity` VARCHAR(50) NOT NULL,
                `quantity` INT NOT NULL DEFAULT 1,
                `total_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `cost_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `invoice_number` VARCHAR(100) DEFAULT NULL,
                `received_date` DATETIME NOT NULL,
                `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
                `notes` TEXT DEFAULT NULL,
                `created_by` VARCHAR(100) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE RESTRICT,
                FOREIGN KEY (`gas_type_id`) REFERENCES `gas_types`(`id`) ON DELETE RESTRICT,
                INDEX (`vendor_id`),
                INDEX (`payment_status`),
                INDEX (`received_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log("runRefillCostMigrations vendor_refill_batches: " . $e->getMessage());
            $all_ok = false;
        }

        // 5. Add vendor_batch_id to payments table + extend payment_type ENUM
        $pay_cols = _getTableColumnNames($pdo, 'payments');
        if (!in_array('vendor_batch_id', $pay_cols)) {
            try { $pdo->exec("ALTER TABLE payments ADD COLUMN vendor_batch_id INT DEFAULT NULL AFTER customer_refill_service_id"); } catch (PDOException $e) { error_log("runRefillCostMigrations payments.vendor_batch_id: " . $e->getMessage()); $all_ok = false; }
        }
        $pay_type = _getTableColumnType($pdo, 'payments', 'payment_type');
        if ($pay_type && strpos($pay_type, 'vendor_refill_payment') === false) {
            try { $pdo->exec("ALTER TABLE payments MODIFY COLUMN payment_type ENUM('refill_payment','deposit_added','deposit_refunded','vendor_payment','refill_service_payment','vendor_refill_payment') NOT NULL"); } catch (PDOException $e) { error_log("runRefillCostMigrations payments.payment_type: " . $e->getMessage()); $all_ok = false; }
        }

        if ($all_ok) {
            _setMigrationCache('refill_cost_migrations');
        }
    }
}

if (!function_exists('runVendorBatchAdjustmentMigrations')) {
    function runVendorBatchAdjustmentMigrations($pdo) {
        if (_checkMigrationCache('vendor_batch_adjustment_migrations')) return;
        $cols = _getTableColumnNames($pdo, 'vendor_refill_batches');
        $all_ok = true;
        if (!in_array('deduction_amount', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_cost"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations deduction_amount: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('deduction_notes', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN deduction_notes VARCHAR(255) DEFAULT NULL AFTER deduction_amount"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations deduction_notes: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('addition_amount', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN addition_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deduction_notes"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations addition_amount: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('addition_notes', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN addition_notes VARCHAR(255) DEFAULT NULL AFTER addition_amount"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations addition_notes: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('net_amount', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN net_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER addition_notes"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations net_amount: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('settlement_type', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN settlement_type ENUM('paid','credit') NOT NULL DEFAULT 'credit' AFTER payment_status"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations settlement_type: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('paid_method', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN paid_method VARCHAR(50) DEFAULT NULL AFTER settlement_type"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations paid_method: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('paid_date', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN paid_date DATETIME DEFAULT NULL AFTER paid_method"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations paid_date: " . $e->getMessage()); $all_ok = false; }
        }
        if (!in_array('paid_reference', $cols)) {
            try { $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN paid_reference VARCHAR(100) DEFAULT NULL AFTER paid_date"); } catch (PDOException $e) { error_log("runVendorBatchAdjustmentMigrations paid_reference: " . $e->getMessage()); $all_ok = false; }
        }
        if ($all_ok) {
            _setMigrationCache('vendor_batch_adjustment_migrations');
        }
    }

    function runVendorRefillBatchItemsMigration($pdo) {
        if (_checkMigrationCache('vendor_refill_batch_items_migration')) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `vendor_refill_batch_items` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `batch_id` INT NOT NULL,
                `cylinder_id` INT DEFAULT NULL,
                `serial_number` VARCHAR(100) NOT NULL,
                `gas_type_id` INT NOT NULL,
                `size_capacity` VARCHAR(50) NOT NULL,
                `cost_per_unit` DECIMAL(10,2) DEFAULT 0.00,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_batch_id` (`batch_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            _setMigrationCache('vendor_refill_batch_items_migration');
        } catch (PDOException $e) {
            error_log("runVendorRefillBatchItemsMigration: " . $e->getMessage());
        }
    }

    function insertVendorRefillBatchItems($pdo, $batch_id, $cylinders) {
        $stmt = $pdo->prepare("INSERT INTO vendor_refill_batch_items (batch_id, cylinder_id, serial_number, gas_type_id, size_capacity, cost_per_unit) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($cylinders as $c) {
            $stmt->execute([$batch_id, intval($c['id'] ?? $c['cylinder_id'] ?? 0), $c['serial_number'], intval($c['gas_type_id']), $c['size_capacity'], floatval($c['cost_per_unit'] ?? $c['current_refill_cost'] ?? 0)]);
        }
    }
}

if (!function_exists('runVendorAccountingMigrations')) {
    function runVendorAccountingMigrations($pdo) {
        if (_checkMigrationCache('vendor_accounting_migrations')) return;
        $cols = _getTableColumnNames($pdo, 'vendors');
        $migrations = [];
        if (!in_array('email', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER mobile"; }
        if (!in_array('gst_registration_type', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN gst_registration_type ENUM('regular','composition','unregistered') DEFAULT 'regular' AFTER gst_number"; }
        if (!in_array('pan', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN pan VARCHAR(10) DEFAULT NULL AFTER gst_registration_type"; }
        if (!in_array('tan', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN tan VARCHAR(10) DEFAULT NULL AFTER pan"; }
        if (!in_array('state_code', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN state_code INT(11) DEFAULT NULL AFTER address"; }
        if (!in_array('state_name', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN state_name VARCHAR(100) DEFAULT NULL AFTER state_code"; }
        if (!in_array('city', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN city VARCHAR(100) DEFAULT NULL AFTER state_name"; }
        if (!in_array('pincode', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN pincode VARCHAR(10) DEFAULT NULL AFTER city"; }
        if (!in_array('bank_account_holder', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN bank_account_holder VARCHAR(150) DEFAULT NULL AFTER pincode"; }
        if (!in_array('bank_account_number', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN bank_account_number VARCHAR(50) DEFAULT NULL AFTER bank_account_holder"; }
        if (!in_array('bank_ifsc', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN bank_ifsc VARCHAR(20) DEFAULT NULL AFTER bank_account_number"; }
        if (!in_array('bank_name', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN bank_name VARCHAR(200) DEFAULT NULL AFTER bank_ifsc"; }
        if (!in_array('bank_branch', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN bank_branch VARCHAR(200) DEFAULT NULL AFTER bank_name"; }
        if (!in_array('payment_terms', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN payment_terms INT(11) DEFAULT 30 AFTER bank_branch"; }
        if (!in_array('notes', $cols)) { $migrations[] = "ALTER TABLE vendors ADD COLUMN notes TEXT DEFAULT NULL AFTER payment_terms"; }
        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) { error_log("runVendorAccountingMigrations: " . $e->getMessage()); }
        }
        _setMigrationCache('vendor_accounting_migrations');
    }
}

// Sync is_customer_refill_cylinder flag on cylinders table from customer_refill_services records
// Runs on every call to keep data in sync regardless of how records were created
if (!function_exists('syncCustomerRefillCylinderFlag')) {
    function syncCustomerRefillCylinderFlag($pdo) {
        try {
            $pdo->exec("UPDATE cylinders c JOIN customer_refill_services crs ON c.id = crs.cylinder_id SET c.is_customer_refill_cylinder = 1 WHERE c.is_customer_refill_cylinder = 0");
        } catch (PDOException $e) {
            error_log("syncCustomerRefillCylinderFlag: " . $e->getMessage());
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  VENDOR / PARTNER LEDGER SUBSYSTEM
// ═══════════════════════════════════════════════════════════════

if (!function_exists('runVendorPartnerLedgerMigrations')) {
    function runVendorPartnerLedgerMigrations($pdo) {
        if (_checkMigrationCache('vendor_partner_ledger_migrations')) {
            try {
                $pdo->query("SELECT 1 FROM vendor_partner_ledger LIMIT 1");
                return;
            } catch (PDOException $e) {
                error_log("runVendorPartnerLedgerMigrations: table missing despite cache, re-running");
            }
        }
        $migrations = [];
        $migrations[] = "CREATE TABLE IF NOT EXISTS `vendor_partner_ledger` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `entity_type` ENUM('vendor','partner') NOT NULL,
            `entity_id` INT NOT NULL,
            `transaction_date` DATETIME NOT NULL,
            `transaction_type` VARCHAR(50) NOT NULL COMMENT 'payment, advance, advance_utilized, due_created, rent_charge, current_payment, adjustment',
            `debit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `credit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `running_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `advance_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `due_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `settlement_status` ENUM('settled','partial','pending','') DEFAULT '',
            `reference_type` VARCHAR(50) DEFAULT NULL,
            `reference_id` INT DEFAULT NULL,
            `remarks` TEXT DEFAULT NULL,
            `created_by` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`entity_type`, `entity_id`),
            INDEX (`transaction_date`),
            INDEX (`transaction_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $migrations[] = "ALTER TABLE `vendor_partner_ledger` ADD INDEX IF NOT EXISTS idx_vpl_entity (`entity_type`, `entity_id`)";
        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('vendor_partner_ledger_migrations');
    }
}

if (!function_exists('processVendorPartnerPayment')) {
    function processVendorPartnerPayment($pdo, $entity_type, $entity_id, $amount, $payment_method, $created_by, $extra = [], $direction = 'pay') {
        $payment_date = $extra['payment_date'] ?? date('Y-m-d H:i:s');
        $reference = $extra['reference'] ?? '';
        $notes = $extra['notes'] ?? '';
        $result = ['due_cleared' => 0, 'advance_created' => 0, 'advance_settled' => 0];

        // Get current balances
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as running,
                   COALESCE(SUM(CASE WHEN transaction_type IN ('advance','current_payment') THEN credit ELSE 0 END), 0)
                 - COALESCE(SUM(CASE WHEN transaction_type = 'advance_utilized' THEN debit ELSE 0 END), 0) as advance,
                   COALESCE(SUM(CASE WHEN transaction_type IN ('due_created','rent_charge') THEN credit ELSE 0 END), 0)
                 - COALESCE(SUM(CASE WHEN transaction_type IN ('payment','current_payment','advance_utilized') THEN credit ELSE 0 END), 0)
                 - COALESCE(SUM(CASE WHEN transaction_type = 'adjustment' THEN debit ELSE 0 END), 0) as due
            FROM vendor_partner_ledger
            WHERE entity_type = ? AND entity_id = ?
        ");
        $stmt->execute([$entity_type, $entity_id]);
        $bal = $stmt->fetch();
        $running = floatval($bal['running'] ?? 0);
        $advance = floatval($bal['advance'] ?? 0);
        $due = floatval($bal['due'] ?? 0);

        if ($direction === 'receive') {
            // Vendor gives money back — settles advance balance
            $advance_settled = min($amount, max($advance, 0));
            $new_running = $running - $amount;
            $new_advance = max(0, $advance - $advance_settled);
            $new_due = $due;

            $settlement_status = '';
            if ($advance_settled > 0 && $new_advance <= 0) {
                $settlement_status = 'settled';
            } elseif ($advance_settled > 0) {
                $settlement_status = 'partial';
            }

            $remarks = "Advance settlement — vendor returned ₹" . number_format($amount, 2);
            if ($advance_settled > 0) $remarks .= " (Advance settled: ₹" . number_format($advance_settled, 2) . ")";
            if ($notes) $remarks .= " — $notes";

            // Insert ledger entry (debit, no payment record)
            $stmt = $pdo->prepare("
                INSERT INTO vendor_partner_ledger
                    (entity_type, entity_id, transaction_date, transaction_type,
                     debit, credit, running_balance, advance_balance, due_balance,
                     settlement_status, reference_type, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $entity_type, $entity_id, $payment_date, 'advance_utilized',
                $amount, $new_running, $new_advance, $new_due,
                $settlement_status, $reference ?: null, $remarks, $created_by
            ]);

            $result['advance_settled'] = $advance_settled;
            try {
                $stmt = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, 'Advance Settlement - " . ucfirst($entity_type) . "', ?)");
                $stmt->execute([$created_by, $remarks]);
            } catch (PDOException $e) {}

        } else {
            // Pay direction — existing logic
            $due_cleared = min($amount, $due);
            $advance_created = $amount - $due_cleared;

            $new_running = $running + $amount;
            $new_advance = $advance + $advance_created;
            $new_due = $due - $due_cleared;

            $settlement_status = '';
            if ($due_cleared > 0 && $advance_created > 0) {
                $settlement_status = 'partial';
            } elseif ($due_cleared > 0 && $new_due <= 0) {
                $settlement_status = 'settled';
            }

            $tx_type = 'payment';
            if ($advance_created > 0 && $due_cleared > 0) {
                $tx_type = 'current_payment';
            } elseif ($advance_created > 0) {
                $tx_type = 'advance';
            }

            // Insert into payments table
            $stmt = $pdo->prepare("
                INSERT INTO payments (vendor_id, amount, payment_method, payment_type, notes, payment_date)
                VALUES (?, ?, ?, 'vendor_payment', ?, ?)
            ");
            $pay_entity_id = $entity_type === 'vendor' ? $entity_id : null;
            $stmt->execute([$pay_entity_id, $amount, $payment_method, $notes ?: "Payment to " . ucfirst($entity_type), $payment_date]);

            // Insert ledger entry
            $stmt = $pdo->prepare("
                INSERT INTO vendor_partner_ledger
                    (entity_type, entity_id, transaction_date, transaction_type,
                     debit, credit, running_balance, advance_balance, due_balance,
                     settlement_status, reference_type, remarks, created_by)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $remarks = "Payment of ₹" . number_format($amount, 2);
            if ($due_cleared > 0) $remarks .= " (Due cleared: ₹" . number_format($due_cleared, 2) . ")";
            if ($advance_created > 0) $remarks .= " (Prepaid: +₹" . number_format($advance_created, 2) . ")";
            if ($notes) $remarks .= " — $notes";

            $stmt->execute([
                $entity_type, $entity_id, $payment_date, $tx_type,
                $amount, $new_running, $new_advance, $new_due,
                $settlement_status, $reference ?: null, $remarks, $created_by
            ]);

            $result['due_cleared'] = $due_cleared;
            $result['advance_created'] = $advance_created;
        }

        return $result;
    }
}

if (!function_exists('getVendorPartnerBalances')) {
    function getVendorPartnerBalances($pdo, $entity_type, $entity_id) {
        $default = ['running_balance' => 0, 'advance_balance' => 0, 'due_balance' => 0, 'total_transactions' => 0];
        try {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(running_balance, 0) as running_balance,
                    COALESCE(advance_balance, 0) as advance_balance,
                    COALESCE(due_balance, 0) as due_balance
                FROM vendor_partner_ledger
                WHERE entity_type = ? AND entity_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$entity_type, $entity_id]);
            $row = $stmt->fetch();
            if (!$row) return $default;

            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM vendor_partner_ledger WHERE entity_type = ? AND entity_id = ?");
            $stmt2->execute([$entity_type, $entity_id]);
            $total = intval($stmt2->fetchColumn());

            return [
                'running_balance' => floatval($row['running_balance']),
                'advance_balance' => floatval($row['advance_balance']),
                'due_balance' => floatval($row['due_balance']),
                'total_transactions' => $total,
            ];
        } catch (PDOException $e) {
            return $default;
        }
    }
}

if (!function_exists('getVendorPartnerLedgerEntries')) {
    function getVendorPartnerLedgerEntries($pdo, $entity_type, $entity_id, $page = 1, $limit = 50, $filters = []) {
        $result = ['rows' => [], 'total' => 0, 'pages' => 1];
        try {
            $where = "WHERE entity_type = ? AND entity_id = ?";
            $params = [$entity_type, $entity_id];

            if (!empty($filters['from'])) {
                $where .= " AND transaction_date >= ?";
                $params[] = $filters['from'] . ' 00:00:00';
            }
            if (!empty($filters['to'])) {
                $where .= " AND transaction_date <= ?";
                $params[] = $filters['to'] . ' 23:59:59';
            }
            if (!empty($filters['type'])) {
                $where .= " AND transaction_type = ?";
                $params[] = $filters['type'];
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_partner_ledger $where");
            $stmt->execute($params);
            $total = intval($stmt->fetchColumn());
            $pages = max(1, ceil($total / $limit));
            $offset = ($page - 1) * $limit;

            $stmt = $pdo->prepare("
                SELECT * FROM vendor_partner_ledger $where
                ORDER BY transaction_date DESC, id DESC
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $result = ['rows' => $rows, 'total' => $total, 'pages' => $pages];
        } catch (PDOException $e) {}
        return $result;
    }
}

if (!function_exists('exportVendorPartnerLedgerCSV')) {
    function exportVendorPartnerLedgerCSV($pdo, $entity_type, $entity_id, $from, $to) {
        try {
            $stmt = $pdo->prepare("
                SELECT transaction_date, transaction_type, debit, credit, running_balance,
                       advance_balance, due_balance, settlement_status, remarks, created_by
                FROM vendor_partner_ledger
                WHERE entity_type = ? AND entity_id = ?
                  AND transaction_date >= ? AND transaction_date <= ?
                ORDER BY transaction_date ASC
            ");
            $stmt->execute([$entity_type, $entity_id, $from . ' 00:00:00', $to . ' 23:59:59']);
            $rows = $stmt->fetchAll();

            if (empty($rows)) return '';

            $type_labels = [
                'payment' => 'Payment', 'advance' => 'Advance',
                'advance_utilized' => 'Advance Utilized', 'due_created' => 'Due Created',
                'rent_charge' => 'Rent Charge', 'current_payment' => 'Current Payment',
                'adjustment' => 'Adjustment',
            ];

            $csv = "Date,Type,Debit,Credit,Running Balance,Advance Balance,Due Balance,Status,Remarks,Created By\n";
            foreach ($rows as $r) {
                $type = $type_labels[$r['transaction_type']] ?? $r['transaction_type'];
                $csv .= '"' . $r['transaction_date'] . '","' . $type . '",'
                     . number_format($r['debit'], 2) . ',' . number_format($r['credit'], 2) . ','
                     . number_format($r['running_balance'], 2) . ','
                     . number_format($r['advance_balance'], 2) . ','
                     . number_format($r['due_balance'], 2) . ','
                     . '"' . ($r['settlement_status'] ?? '') . '",'
                     . '"' . str_replace('"', '""', $r['remarks'] ?? '') . '",'
                     . '"' . ($r['created_by'] ?? '') . "\"\n";
            }
            return $csv;
        } catch (PDOException $e) {
            return '';
        }
    }
}

if (!function_exists('addVendorRefillLedgerEntry')) {
    /**
     * Inserts a vendor_partner_ledger entry for refill batch operations.
     * For 'due_created' — credit = amount, due_balance increases.
     * For 'payment' — credit = amount, due_balance decreases.
     */
    function addVendorRefillLedgerEntry($pdo, $vendor_id, $amount, $tx_type, $ref_id, $remarks, $created_by = 'system', $ref_type = 'vendor_refill_batch') {
        $stmt = $pdo->prepare("SELECT COALESCE(running_balance, 0) as running_balance, COALESCE(advance_balance, 0) as advance_balance, COALESCE(due_balance, 0) as due_balance FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$vendor_id]);
        $bal = $stmt->fetch();

        $running = floatval($bal['running_balance'] ?? 0);
        $advance = floatval($bal['advance_balance'] ?? 0);
        $due = floatval($bal['due_balance'] ?? 0);

        $new_running = $running + $amount;

        if ($tx_type === 'due_created') {
            $new_due = $due + $amount;
            $settlement_status = 'pending';
        } else {
            $new_due = max(0, $due - $amount);
            $settlement_status = $new_due <= 0 ? 'settled' : 'partial';
        }

        $stmt = $pdo->prepare("INSERT INTO vendor_partner_ledger (entity_type, entity_id, transaction_date, transaction_type, debit, credit, running_balance, advance_balance, due_balance, settlement_status, reference_type, reference_id, remarks, created_by) VALUES (?, ?, NOW(), ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['vendor', $vendor_id, $tx_type, $amount, $new_running, $advance, $new_due, $settlement_status, $ref_type, $ref_id, $remarks, $created_by]);
    }
}

// ═══════════════════════════════════════════════════════════════
//  REFILL WITHOUT EXCHANGE SUBSYSTEM (dispatch-settlement flow)
// ═══════════════════════════════════════════════════════════════

if (!function_exists('runRefillWithoutExchangeMigrations')) {
    function runRefillWithoutExchangeMigrations($pdo) {
        if (_checkMigrationCache('refill_without_exchange_migrations')) return;
        $migrations = [];

        // customer_cylinder_orders — tracks refill lifecycle per cylinder per order
        $migrations[] = "CREATE TABLE IF NOT EXISTS `customer_cylinder_orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `refill_order_id` INT NOT NULL,
            `cylinder_id` INT NOT NULL,
            `status` ENUM('pending_receipt','received','at_vendor','sent_to_vendor','refilled','received_from_vendor','ready_for_pickup','delivered','archived') NOT NULL DEFAULT 'pending_receipt',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`refill_order_id`) REFERENCES `refill_orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`cylinder_id`) REFERENCES `cylinders` (`id`) ON DELETE CASCADE,
            INDEX (`refill_order_id`),
            INDEX (`cylinder_id`),
            INDEX (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // Add order_type column to refill_orders if missing
        $ro_cols = _getTableColumnNames($pdo, 'refill_orders');
        if (!in_array('order_type', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN order_type VARCHAR(50) NOT NULL DEFAULT 'refill_with_exchange' AFTER is_credit_order";
        }
        if (!in_array('paid_amount', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER grand_total";
        }
        if (!in_array('due_amount', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN due_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER paid_amount";
        }
        if (!in_array('advance_used', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN advance_used DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER due_amount";
        }
        if (!in_array('deposit_type', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN deposit_type VARCHAR(50) DEFAULT NULL AFTER deposit_amount";
        }
        if (!in_array('deposit_settled', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN deposit_settled DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deposit_type";
        }
        if (!in_array('round_off', $ro_cols)) {
            $migrations[] = "ALTER TABLE refill_orders ADD COLUMN round_off DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount";
        }

        // Add advance_balance to customers if missing
        $cust_cols = _getTableColumnNames($pdo, 'customers');
        if (!in_array('advance_balance', $cust_cols)) {
            $migrations[] = "ALTER TABLE customers ADD COLUMN advance_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deposit_balance";
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {
                error_log("runRefillWithoutExchangeMigrations: " . $e->getMessage());
            }
        }
        _setMigrationCache('refill_without_exchange_migrations');
    }
}

if (!function_exists('setCustomerCylinderOrderStatusDirect')) {
    function setCustomerCylinderOrderStatusDirect($pdo, $cco_id, $new_status) {
        try {
            $stmt = $pdo->prepare("UPDATE customer_cylinder_orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $cco_id]);
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'Cylinder order not found.'];
            }
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('searchCustomerCylinders')) {
    function searchCustomerCylinders($pdo, $query = null, $customer_id = null, $gas_type_id = null, $size_capacity = null, $include_archived = false) {
        $results = [];
        try {
            $sql = "SELECT c.*, cu.name as customer_name, cu.mobile as customer_mobile, g.name as gas_name
                    FROM cylinders c
                    JOIN customers cu ON c.current_customer_id = cu.id
                    LEFT JOIN gas_types g ON c.gas_type_id = g.id
                    WHERE c.ownership_type = 'consumer_owned'";
            $params = [];

            if (!$include_archived) {
                $sql .= " AND c.lifecycle_status = 'active'";
            }
            if ($query) {
                $sql .= " AND (c.serial_number LIKE ? OR cu.name LIKE ? OR cu.mobile LIKE ?)";
                $params[] = "%$query%";
                $params[] = "%$query%";
                $params[] = "%$query%";
            }
            if ($customer_id) {
                $sql .= " AND c.current_customer_id = ?";
                $params[] = $customer_id;
            }
            if ($gas_type_id) {
                $sql .= " AND c.gas_type_id = ?";
                $params[] = $gas_type_id;
            }
            if ($size_capacity) {
                $sql .= " AND c.size_capacity = ?";
                $params[] = $size_capacity;
            }
            $sql .= " ORDER BY c.id DESC LIMIT 100";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("searchCustomerCylinders: " . $e->getMessage());
        }
        return $results;
    }
}

if (!function_exists('getCustomerCylinderHistory')) {
    function getCustomerCylinderHistory($pdo, $cylinder_id) {
        $history = [];
        try {
            $stmt = $pdo->prepare("
                SELECT cco.*, o.grand_total, o.order_date, v.name as vendor_name
                FROM customer_cylinder_orders cco
                JOIN refill_orders o ON cco.refill_order_id = o.id
                LEFT JOIN customer_refill_services crs ON crs.cylinder_id = cco.cylinder_id
                LEFT JOIN vendors v ON crs.vendor_id = v.id
                WHERE cco.cylinder_id = ?
                ORDER BY cco.created_at DESC
            ");
            $stmt->execute([$cylinder_id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getCustomerCylinderHistory: " . $e->getMessage());
        }
        return $history;
    }
}



// ═══════════════════════════════════════════════════════════════
//  SHARED CREDIT SETTLEMENT HELPER
// ═══════════════════════════════════════════════════════════════

if (!function_exists('processPaymentWithCreditSettlement')) {
    function processPaymentWithCreditSettlement($pdo, $customer_id, $amount, $payment_method, $payment_date, $notes, $ledger_group_id) {
        $stmt = $pdo->prepare("SELECT credit_used, deposit_balance FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if (!$customer) throw new Exception("Customer not found.");

        $current_credit_used = floatval($customer['credit_used']);
        $amount_for_credit = min($amount, $current_credit_used);
        $amount_for_deposit = $amount - $amount_for_credit;
        $payment_id = null;

        if ($amount_for_credit > 0) {
            $credit_notes = 'Auto-settled from payment' . ($notes ? ' - ' . $notes : '');

            $stmt = $pdo->prepare("UPDATE customers SET credit_used = GREATEST(0, credit_used - ?) WHERE id = ?");
            $stmt->execute([$amount_for_credit, $customer_id]);

            $stmt = $pdo->prepare("SELECT credit_used FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $new_credit_used = floatval($stmt->fetchColumn());

            $stmt = $pdo->prepare("INSERT INTO credit_transactions (customer_id, refill_order_id, transaction_type, amount, balance_after, description, ledger_group_id) VALUES (?, NULL, 'payment', ?, ?, ?, ?)");
            $stmt->execute([$customer_id, $amount_for_credit, $new_credit_used, 'Auto-settled from payment - ' . ($notes ?: 'Payment received'), $ledger_group_id]);

            $pdo->prepare("UPDATE customers SET credit_status = 'good' WHERE id = ? AND credit_used <= credit_limit * 0.7 AND credit_status != 'good'")->execute([$customer_id]);

            $orders_stmt = $pdo->prepare("SELECT id, grand_total, COALESCE((SELECT SUM(amount) FROM payments WHERE refill_order_id = ro.id AND amount > 0 AND COALESCE(payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')), 0) AS already_paid FROM refill_orders ro WHERE customer_id = ? AND payment_status != 'paid' ORDER BY order_date ASC");
            $orders_stmt->execute([$customer_id]);
            $remaining = $amount_for_credit;
            while ($order_row = $orders_stmt->fetch()) {
                if ($remaining <= 0) break;
                $due = floatval($order_row['grand_total']) - floatval($order_row['already_paid']);
                if ($due <= 0) continue;
                $allocated = min($remaining, $due);
                $ins = $pdo->prepare("INSERT INTO payments (customer_id, refill_order_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, ?, 'refill_payment', ?, ?, ?)");
                $ins->execute([$customer_id, $order_row['id'], $allocated, $payment_method, $credit_notes, $payment_date, $ledger_group_id]);
                recalculateOrderPaymentStatus($pdo, $order_row['id']);
                $remaining -= $allocated;
            }
        }

        if ($amount_for_deposit > 0) {
            $stmt = $pdo->prepare("INSERT INTO payments (customer_id, amount, payment_method, payment_type, notes, payment_date, ledger_group_id) VALUES (?, ?, ?, 'deposit_added', ?, ?, ?)");
            $stmt->execute([$customer_id, $amount_for_deposit, $payment_method, $notes, $payment_date, $ledger_group_id]);
            $payment_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("UPDATE customers SET deposit_balance = deposit_balance + ? WHERE id = ?");
            $stmt->execute([$amount_for_deposit, $customer_id]);
        }

        return [
            'payment_id' => $payment_id,
            'amount_for_credit' => $amount_for_credit,
            'amount_for_deposit' => $amount_for_deposit,
            'has_credit_settlement' => $amount_for_credit > 0,
        ];
    }
}

// ═══════════════════════════════════════════════════════════════
//  UPDATE PAID/ DUE AMOUNT ON REFILL ORDERS HELPER
// ═══════════════════════════════════════════════════════════════

if (!function_exists('recalculateOrderPaymentStatus')) {
    function recalculateOrderPaymentStatus($pdo, $order_id) {
        $stmt = $pdo->prepare("SELECT grand_total FROM refill_orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        if (!$order) return;

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE refill_order_id = ? AND amount > 0 AND COALESCE(payment_type,'') NOT IN ('deposit_added','deposit_refunded','deposit_damage','rent_payment')");
        $stmt->execute([$order_id]);
        $paid = floatval($stmt->fetchColumn());

        $grand_total = floatval($order['grand_total']);
        $due = max(0, $grand_total - $paid);
        $status = ($due <= 0) ? 'paid' : (($paid > 0) ? 'partial' : 'pending');

        $pdo->prepare("UPDATE refill_orders SET paid_amount = ?, due_amount = ?, payment_status = ? WHERE id = ?")
            ->execute([$paid, $due, $status, $order_id]);
    }
}

if (!function_exists('runBusinessConfigMigration')) {
    function runBusinessConfigMigration($pdo) {
        if (_checkMigrationCache('business_config_migration')) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `business_config` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `business_key` varchar(100) NOT NULL,
                `is_default` tinyint(1) NOT NULL DEFAULT 0,
                `label` varchar(255) DEFAULT NULL,
                `business_name` varchar(255) DEFAULT NULL,
                `tagline` varchar(255) DEFAULT NULL,
                `address` text DEFAULT NULL,
                `gstin` varchar(50) DEFAULT NULL,
                `phone` varchar(50) DEFAULT NULL,
                `email` varchar(255) DEFAULT NULL,
                `website` varchar(255) DEFAULT NULL,
                `logo_path` varchar(255) DEFAULT NULL,
                `logo_white_path` varchar(255) DEFAULT NULL,
                `bank_details` text DEFAULT NULL,
                `invoice_terms` text DEFAULT NULL,
                `smtp_host` varchar(255) DEFAULT NULL,
                `smtp_port` int(11) DEFAULT 587,
                `smtp_username` varchar(255) DEFAULT NULL,
                `smtp_password` varchar(255) DEFAULT NULL,
                `smtp_encryption` varchar(10) DEFAULT 'tls',
                `email_from_name` varchar(255) DEFAULT NULL,
                `email_from_address` varchar(255) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `business_key` (`business_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $stmt = $pdo->query("SELECT COUNT(*) FROM business_config");
            if ($stmt->fetchColumn() == 0) {
                $pdo->exec("INSERT INTO business_config (business_key, label, business_name, tagline, address, gstin, phone, email, website, bank_details, invoice_terms, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, email_from_name, email_from_address, is_default) VALUES ('prem_gas_solution', 'Prem Gas Solution', 'PREM GAS SOLUTION', 'Industrial & Medical Gas Supplier', 'A.T. Road, Khagaria, Bihar - 786125', '18ABCDE1234F1ZA', '+91-9876543210', 'info@pkgas.com', 'https://pkgas.com', 'Bank of Baroda, Khagaria Branch\nAccount Name: PREM GAS SOLUTION\nAccount No: 12345678901234\nIFSC: BARB0TINSUK\nBranch: Khagaria, Bihar', '1. Goods once sold will not be taken back.\n2. Cylinder deposit is refundable on return in good condition.\n3. All disputes subject to Khagaria jurisdiction.\n4. GST charged as applicable.', 'smtp.hostinger.com', 465, 'noreply@pkgas.com', 'YourSMTPPasswordHere', 'ssl', 'Prem Gas Solution', 'noreply@pkgas.com', 1)");

            // Seed VD Enterprises as second brand
            $pdo->exec("INSERT IGNORE INTO business_config (business_key, label, business_name, tagline, address, gstin, phone, email, website, bank_details, invoice_terms, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, email_from_name, email_from_address, is_default) VALUES ('vd_enterprises', 'VD Enterprises', 'VD ENTERPRISES', 'Industrial Gas & Cylinder Supplier', 'A.T. Road, Khagaria, Bihar - 786125', '18FGHIJ5678K2ZB', '+91-9876543210', 'info@vdenterprises.com', '', 'Bank of Baroda, Khagaria Branch\nAccount Name: VD ENTERPRISES\nAccount No: 98765432109876\nIFSC: BARB0TINSUK\nBranch: Khagaria, Bihar', '1. Goods once sold will not be taken back.\n2. Cylinder deposit is refundable on return in good condition.\n3. All disputes subject to Khagaria jurisdiction.\n4. GST charged as applicable.', 'smtp.hostinger.com', 465, 'noreply@vdenterprises.com', 'Nutangases@20', 'ssl', 'VD Enterprises', 'noreply@vdenterprises.com', 0)");
            }
            _setMigrationCache('business_config_migration');
        } catch (PDOException $e) {
            error_log("runBusinessConfigMigration: " . $e->getMessage());
        }
    }
}

if (!function_exists('runMultiBrandMigration')) {
    function runDispatchLotMigrations($pdo) {
    if (_checkMigrationCache('dispatch_lot_migrations')) return;
    $migrations = [];

    $migrations[] = "CREATE TABLE IF NOT EXISTS `dispatch_lots` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `lot_number` VARCHAR(50) NOT NULL UNIQUE,
        `vendor_id` INT NOT NULL,
        `driver_name` VARCHAR(100) DEFAULT NULL,
        `vehicle_number` VARCHAR(50) DEFAULT NULL,
        `dispatch_date` DATETIME NOT NULL,
        `expected_return_date` DATE DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `cylinder_count` INT NOT NULL DEFAULT 0,
        `returned_count` INT NOT NULL DEFAULT 0,
        `lot_status` ENUM('open','partial_return','completed') NOT NULL DEFAULT 'open',
        `advance_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `gst_rate` DECIMAL(5,2) DEFAULT 0.00,
        `gst_amount` DECIMAL(10,2) DEFAULT 0.00,
        `total_paid` DECIMAL(10,2) DEFAULT 0.00,
        `remaining_balance` DECIMAL(10,2) DEFAULT 0.00,
        `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
        `created_by` VARCHAR(100) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`vendor_id`),
        INDEX (`lot_status`),
        INDEX (`payment_status`),
        INDEX (`dispatch_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $migrations[] = "CREATE TABLE IF NOT EXISTS `dispatch_lot_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `lot_id` INT NOT NULL,
        `cylinder_id` INT NOT NULL,
        `serial_number` VARCHAR(100) NOT NULL,
        `gas_type_id` INT NOT NULL,
        `size_capacity` VARCHAR(50) NOT NULL,
        `dispatch_status` ENUM('dispatched','received') NOT NULL DEFAULT 'dispatched',
        `receive_date` DATETIME DEFAULT NULL,
        `refill_cost` DECIMAL(10,2) DEFAULT 0.00,
        INDEX (`lot_id`),
        INDEX (`cylinder_id`),
        INDEX (`dispatch_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $vb_cols = _getTableColumnNames($pdo, 'vendor_refill_batches');
    if (!in_array('lot_id', $vb_cols)) {
        $migrations[] = "ALTER TABLE vendor_refill_batches ADD COLUMN lot_id INT DEFAULT NULL AFTER id";
    }

    $pay_cols = _getTableColumnNames($pdo, 'payments');
    if (!in_array('lot_id', $pay_cols)) {
        $migrations[] = "ALTER TABLE payments ADD COLUMN lot_id INT DEFAULT NULL AFTER id";
    }

    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { error_log("runDispatchLotMigrations: " . $e->getMessage()); }
    }
    _setMigrationCache('dispatch_lot_migrations');
}

function runLotSettlementRedesignMigration($pdo) {
    if (_checkMigrationCache('lot_settlement_redesign_migration')) return;
    $migrations = [];

    $dl_cols = _getTableColumnNames($pdo, 'dispatch_lots');

    if (!in_array('estimated_total', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN estimated_total DECIMAL(10,2) DEFAULT 0.00 AFTER notes";
    }
    if (!in_array('gst_applicable', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN gst_applicable TINYINT(1) DEFAULT 0 AFTER gst_amount";
    }
    if (!in_array('gst_type', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN gst_type VARCHAR(10) DEFAULT 'CGST/SGST' AFTER gst_rate";
    }
    if (!in_array('gst_locked', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN gst_locked TINYINT(1) DEFAULT 0 AFTER gst_type";
    }
    if (!in_array('final_refill_amount', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN final_refill_amount DECIMAL(10,2) DEFAULT 0.00 AFTER gst_amount";
    }
    if (!in_array('final_gst_amount', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN final_gst_amount DECIMAL(10,2) DEFAULT 0.00 AFTER final_refill_amount";
    }
    if (!in_array('final_total', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN final_total DECIMAL(10,2) DEFAULT 0.00 AFTER final_gst_amount";
    }
    if (!in_array('additional_payments', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN additional_payments DECIMAL(10,2) DEFAULT 0.00 AFTER total_paid";
    }
    if (!in_array('receive_date', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN receive_date DATETIME DEFAULT NULL AFTER expected_return_date";
    }

    $pay_cols = _getTableColumnNames($pdo, 'payments');
    if (!in_array('payment_subtype', $pay_cols)) {
        $migrations[] = "ALTER TABLE payments ADD COLUMN payment_subtype VARCHAR(20) DEFAULT NULL AFTER payment_type";
    }

    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { error_log("runLotSettlementRedesignMigration: " . $e->getMessage()); }
    }
    _setMigrationCache('lot_settlement_redesign_migration');
}

function runTransportCostMigrations($pdo) {
    if (_checkMigrationCache('transport_cost_migrations')) return;
    $migrations = [];

    $dli_cols = _getTableColumnNames($pdo, 'dispatch_lot_items');
    if (!in_array('dispatch_transport_cost', $dli_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lot_items ADD COLUMN dispatch_transport_cost DECIMAL(10,2) DEFAULT 0.00 AFTER refill_cost";
    }
    if (!in_array('receive_transport_cost', $dli_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lot_items ADD COLUMN receive_transport_cost DECIMAL(10,2) DEFAULT 0.00 AFTER dispatch_transport_cost";
    }

    $dl_cols = _getTableColumnNames($pdo, 'dispatch_lots');
    if (!in_array('dispatch_transport_total', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN dispatch_transport_total DECIMAL(10,2) DEFAULT 0.00 AFTER total_paid";
    }
    if (!in_array('receive_transport_total', $dl_cols)) {
        $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN receive_transport_total DECIMAL(10,2) DEFAULT 0.00 AFTER dispatch_transport_total";
    }

    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { error_log("runTransportCostMigrations: " . $e->getMessage()); }
    }
    _setMigrationCache('transport_cost_migrations');
}

function runMultiBrandMigration($pdo) {
        if (_checkMigrationCache('multi_brand_migration')) return;
        $migrations = [];
        $bcols = _getTableColumnNames($pdo, 'business_config');
        if (!in_array('is_default', $bcols)) {
            $migrations[] = "ALTER TABLE business_config ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER business_key";
        }
        try {
            $pdo->query("SELECT 1 FROM business_config WHERE business_key = 'vd_enterprises' LIMIT 1");
        } catch (PDOException $e) {
            $migrations[] = "INSERT IGNORE INTO business_config (business_key, label, business_name, tagline, address, gstin, phone, email, website, bank_details, invoice_terms, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, email_from_name, email_from_address, is_default) VALUES ('vd_enterprises', 'VD Enterprises', 'VD ENTERPRISES', 'Industrial Gas & Cylinder Supplier', 'A.T. Road, Khagaria, Bihar - 786125', '18FGHIJ5678K2ZB', '+91-9876543210', 'info@vdenterprises.com', '', 'Bank of Baroda, Khagaria Branch\nAccount Name: VD ENTERPRISES\nAccount No: 98765432109876\nIFSC: BARB0TINSUK\nBranch: Khagaria, Bihar', '1. Goods once sold will not be taken back.\n2. Cylinder deposit is refundable on return in good condition.\n3. All disputes subject to Khagaria jurisdiction.\n4. GST charged as applicable.', 'smtp.hostinger.com', 465, 'noreply@vdenterprises.com', 'Nutangases@20', 'ssl', 'VD Enterprises', 'noreply@vdenterprises.com', 0)";
        }
        try {
            $pdo->exec("ALTER TABLE business_config DROP INDEX IF EXISTS uq_business_key");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("CREATE UNIQUE INDEX uq_business_key ON business_config (business_key)");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("UPDATE business_config SET is_default = 1 WHERE business_key = 'prem_gas_solution' AND is_default = 0");
        } catch (PDOException $e) {}
        // Fill SMTP/bank/invoice data for existing rows that have minimal seed
        try {
            $pdo->exec("UPDATE business_config SET smtp_host = COALESCE(NULLIF(smtp_host, ''), 'smtp.hostinger.com'), smtp_port = COALESCE(NULLIF(smtp_port, 0), 465), smtp_username = COALESCE(NULLIF(smtp_username, ''), 'noreply@pkgas.com'), smtp_password = COALESCE(NULLIF(smtp_password, ''), 'YourSMTPPasswordHere'), smtp_encryption = COALESCE(NULLIF(smtp_encryption, ''), 'ssl'), email_from_name = COALESCE(NULLIF(email_from_name, ''), 'Prem Gas Solution'), email_from_address = COALESCE(NULLIF(email_from_address, ''), 'noreply@pkgas.com'), bank_details = COALESCE(NULLIF(bank_details, ''), 'Bank of Baroda, Khagaria Branch\nAccount Name: PREM GAS SOLUTION\nAccount No: 12345678901234\nIFSC: BARB0TINSUK\nBranch: Khagaria, Bihar'), invoice_terms = COALESCE(NULLIF(invoice_terms, ''), '1. Goods once sold will not be taken back.\n2. Cylinder deposit is refundable on return in good condition.\n3. All disputes subject to Khagaria jurisdiction.\n4. GST charged as applicable.') WHERE smtp_host IS NULL OR smtp_host = ''");
        } catch (PDOException $e) {}
        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('multi_brand_migration');
    }
}

// ═══════════════════════════════════════════════════════════════
//  VENDOR SETTLEMENT & RECONCILIATION FUNCTIONS
// ═══════════════════════════════════════════════════════════════

if (!function_exists('getVendorPendingReceipts')) {
    function getVendorPendingReceipts($pdo) {
        $rows = [];
        try {
            $stmt = $pdo->query("
                SELECT v.id AS vendor_id, v.name AS vendor_name, v.active_refill_count,
                       c.id AS cylinder_id, c.serial_number, c.gas_type_id, c.size_capacity,
                       c.ownership_type, g.name AS gas_name
                FROM cylinders c
                JOIN vendors v ON c.current_vendor_id = v.id
                JOIN gas_types g ON c.gas_type_id = g.id
                WHERE c.status = 'sent_to_vendor'
                ORDER BY v.name, c.serial_number
            ");
            while ($r = $stmt->fetch()) {
                $vid = intval($r['vendor_id']);
                if (!isset($rows[$vid])) {
                    $rows[$vid] = [
                        'vendor_id' => $vid,
                        'vendor_name' => $r['vendor_name'],
                        'active_refill_count' => intval($r['active_refill_count']),
                        'cylinders' => [],
                    ];
                }
                $rows[$vid]['cylinders'][] = $r;
            }
        } catch (PDOException $e) {}
        return $rows;
    }
}

if (!function_exists('getFilledCylindersWithoutBatch')) {
    function getFilledCylindersWithoutBatch($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT c.*, g.name AS gas_name
                FROM cylinders c
                JOIN gas_types g ON c.gas_type_id = g.id
                WHERE c.status = 'filled' AND c.last_refill_batch_id IS NULL
                ORDER BY c.serial_number
            ");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('getForcefulMovesWithoutBatch')) {
    function getForcefulMovesWithoutBatch($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT DISTINCT c.*, g.name AS gas_name,
                       ct_data.last_vendor_id AS ref_vendor_id,
                       ct_data.last_send_date
                FROM cylinders c
                JOIN gas_types g ON c.gas_type_id = g.id
                JOIN (
                    SELECT ct.cylinder_id,
                           MAX(ct.transaction_date) AS last_send_date,
                           SUBSTRING_INDEX(GROUP_CONCAT(ct.vendor_id ORDER BY ct.transaction_date DESC SEPARATOR ','), ',', 1) AS last_vendor_id
                    FROM cylinder_transactions ct
                    WHERE ct.transaction_type = 'send_to_vendor'
                    GROUP BY ct.cylinder_id
                ) ct_data ON ct_data.cylinder_id = c.id
                WHERE c.status NOT IN ('sent_to_vendor', 'filled')
                  AND c.last_refill_batch_id IS NULL
                ORDER BY c.serial_number
            ");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('createSettlementBatch')) {
    function createSettlementBatch($pdo, $vendor_id, $cylinder_ids, $data) {
        $cost_per_unit = floatval($data['cost_per_unit'] ?? 0);
        $invoice_number = trim($data['invoice_number'] ?? '');
        $received_date = $data['received_date'] ?? date('Y-m-d H:i:s');
        $deduction = floatval($data['deduction_amount'] ?? 0);
        $deduction_notes = trim($data['deduction_notes'] ?? '');
        $addition = floatval($data['addition_amount'] ?? 0);
        $addition_notes = trim($data['addition_notes'] ?? '');
        $gst_rate = floatval($data['gst_rate'] ?? 0);
        $payment_option = trim($data['payment_option'] ?? 'credit');
        $paid_method = trim($data['paid_method'] ?? '');
        $paid_date_raw = $data['paid_date'] ?? date('Y-m-d\TH:i');
        $paid_date = str_replace('T', ' ', $paid_date_raw) . ':00';
        $paid_reference = trim($data['paid_reference'] ?? '');
        $notes = trim($data['notes'] ?? '');
        $created_by = $data['created_by'] ?? ($_SESSION['username'] ?? 'system');
        $new_status = $data['new_status'] ?? 'filled';

        if ($vendor_id <= 0 || empty($cylinder_ids)) {
            throw new Exception("Invalid vendor or no cylinders selected.");
        }

        $pdo->beginTransaction();
        try {
            // Group cylinders by gas_type_id + size_capacity
            $gas_type_counts = [];
            $cylinder_info = [];
            foreach ($cylinder_ids as $cyl_id) {
                $cyl_id = intval($cyl_id);
                $cyl_data = $pdo->prepare("SELECT id, serial_number, gas_type_id, size_capacity, status FROM cylinders WHERE id = ?");
                $cyl_data->execute([$cyl_id]);
                $cyl = $cyl_data->fetch();
                if (!$cyl) continue;
                $key = intval($cyl['gas_type_id']) . '|' . $cyl['size_capacity'];
                $gas_type_counts[$key] = ($gas_type_counts[$key] ?? 0) + 1;
                $cylinder_info[] = $cyl;
            }

            if (empty($gas_type_counts)) {
                throw new Exception("No valid cylinders found.");
            }

            // Update cylinder status and vendor
            $upd_status = $new_status;
            foreach ($cylinder_info as $cyl) {
                $stmt = $pdo->prepare("UPDATE cylinders SET status = ?, current_vendor_id = NULL, current_customer_id = NULL WHERE id = ? AND current_vendor_id = ?");
                $stmt->execute([$upd_status, $cyl['id'], $vendor_id]);
                if ($stmt->rowCount() === 0) {
                    // Cylinder may already have vendor cleared — force update
                    $stmt2 = $pdo->prepare("UPDATE cylinders SET status = ?, current_vendor_id = NULL, current_customer_id = NULL WHERE id = ?");
                    $stmt2->execute([$upd_status, $cyl['id']]);
                }
                logCylinderTransaction($pdo, $cyl['id'], null, $vendor_id, 'return_from_customer', "Settlement receipt back from vendor. Notes: $notes");
            }

            // Decrement vendor active count
            $pdo->prepare("UPDATE vendors SET active_refill_count = GREATEST(0, active_refill_count - ?) WHERE id = ?")->execute([count($cylinder_info), $vendor_id]);

            if ($cost_per_unit > 0) {
                $grand_total = 0;
                $batch_data = [];
                foreach ($gas_type_counts as $key => $qty) {
                    list($gt_id, $sz) = explode('|', $key);
                    $gt_id = intval($gt_id);
                    $total_cost = $cost_per_unit * $qty;
                    $grand_total += $total_cost;
                    $batch_data[$key] = ['gt_id' => $gt_id, 'sz' => $sz, 'qty' => $qty, 'total_cost' => $total_cost];
                }

                require_once __DIR__ . '/gst_helper.php';

                foreach ($batch_data as $key => $info) {
                    $ratio = $grand_total > 0 ? $info['total_cost'] / $grand_total : 1 / max(1, count($batch_data));
                    $batch_deduction = round($deduction * $ratio, 2);
                    $batch_addition = round($addition * $ratio, 2);
                    $net_amount = $info['total_cost'] - $batch_deduction + $batch_addition;

                    // Collect cylinders for this group that were just updated
                    $sel_cyl = $pdo->prepare("SELECT id, serial_number, gas_type_id, size_capacity FROM cylinders WHERE current_vendor_id IS NULL AND status = ? AND gas_type_id = ? AND size_capacity = ? ORDER BY id DESC LIMIT " . intval($info['qty']));
                    $sel_cyl->execute([$upd_status, $info['gt_id'], $info['sz']]);
                    $batch_cylinders = $sel_cyl->fetchAll();

                    // GST
                    $gst_calc = calculateGST($info['total_cost'], $gst_rate);
                    $taxable_amt = $gst_calc['taxable'];
                    $gst_amt = $gst_calc['gst_amount'];
                    $cgst_amt = $gst_calc['cgst'];
                    $sgst_amt = $gst_calc['sgst'];

                    $ins = $pdo->prepare("INSERT INTO vendor_refill_batches (vendor_id, gas_type_id, size_capacity, quantity, total_cost, deduction_amount, deduction_notes, addition_amount, addition_notes, net_amount, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst, cost_per_unit, invoice_number, received_date, payment_status, settlement_type, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$vendor_id, $info['gt_id'], $info['sz'], $info['qty'], $info['total_cost'], $batch_deduction, $deduction_notes ?: null, $batch_addition, $addition_notes ?: null, $net_amount, $gst_rate, $taxable_amt, $gst_amt, $cgst_amt, $sgst_amt, $cost_per_unit, $invoice_number ?: null, $received_date, $payment_option === 'paid' ? 'paid' : 'unpaid', $payment_option, "Settlement: " . ($notes ?: "Retroactive receipt"), $created_by]);
                    $batch_id = $pdo->lastInsertId();

                    // Record batch-item links
                    if (!empty($batch_cylinders)) {
                        foreach ($batch_cylinders as &$_c) { $_c['cost_per_unit'] = $cost_per_unit; } unset($_c);
                        insertVendorRefillBatchItems($pdo, $batch_id, $batch_cylinders);
                    }

                    $upd = $pdo->prepare("UPDATE cylinders SET current_refill_cost = ?, last_refill_vendor_id = ?, last_refill_batch_id = ? WHERE current_vendor_id IS NULL AND status = ? AND gas_type_id = ? AND size_capacity = ? ORDER BY id DESC LIMIT " . intval($info['qty']));
                    $upd->execute([$cost_per_unit, $vendor_id, $batch_id, $upd_status, $info['gt_id'], $info['sz']]);

                    // Record Input GST
                    if ($gst_rate > 0 && $gst_amt > 0) {
                        recordInputGST($pdo, [
                            'entity_id' => $vendor_id,
                            'gst_rate' => $gst_rate,
                            'taxable_amount' => $taxable_amt,
                            'gst_amount' => $gst_amt,
                            'cgst' => $cgst_amt,
                            'sgst' => $sgst_amt,
                            'igst' => 0,
                            'reference_type' => 'vendor_refill_batch',
                            'reference_id' => $batch_id,
                            'transaction_date' => date('Y-m-d', strtotime($received_date)),
                        ]);
                    }

                    if ($payment_option === 'paid') {
                        $pdo->prepare("INSERT INTO payments (vendor_id, amount, payment_method, payment_type, notes, payment_date, vendor_batch_id) VALUES (?, ?, ?, 'vendor_refill_payment', ?, ?, ?)")->execute([$vendor_id, $net_amount, $paid_method ?: 'Bank Transfer', "Settlement batch #$batch_id - {$info['qty']} x {$info['sz']}" . ($invoice_number ? " (Inv: $invoice_number)" : "") . " - Paid", $paid_date, $batch_id]);
                        addVendorRefillLedgerEntry($pdo, $vendor_id, $net_amount, 'due_created', $batch_id, "Settlement batch #$batch_id - {$info['qty']} x {$info['sz']}" . ($invoice_number ? " (Inv: $invoice_number)" : "") . " (Ded: ₹$batch_deduction, Add: ₹$batch_addition)", $created_by);
                        addVendorRefillLedgerEntry($pdo, $vendor_id, $net_amount, 'payment', $batch_id, "Payment for settlement batch #$batch_id ($paid_method" . ($paid_reference ? " - $paid_reference" : "") . ")", $created_by);
                        $pdo->prepare("UPDATE vendor_refill_batches SET paid_method = ?, paid_date = ?, paid_reference = ? WHERE id = ?")->execute([$paid_method, $paid_date, $paid_reference ?: null, $batch_id]);
                    } else {
                        $pdo->prepare("INSERT INTO payments (vendor_id, amount, payment_method, payment_type, notes, payment_date, vendor_batch_id) VALUES (?, ?, ?, 'vendor_refill_payment', ?, ?, ?)")->execute([$vendor_id, $net_amount, 'Pending', "Settlement batch #$batch_id - {$info['qty']} x {$info['sz']}" . ($invoice_number ? " (Inv: $invoice_number)" : "") . " (Ded: ₹$batch_deduction, Add: ₹$batch_addition)", $received_date, $batch_id]);
                        addVendorRefillLedgerEntry($pdo, $vendor_id, $net_amount, 'due_created', $batch_id, "Settlement batch #$batch_id - {$info['qty']} x {$info['sz']}" . ($invoice_number ? " (Inv: $invoice_number)" : "") . " (Ded: ₹$batch_deduction, Add: ₹$batch_addition)", $created_by);
                    }
                }
            }

            if (!function_exists('syncInventory')) {
                require_once __DIR__ . '/inventory-utils.php';
            }
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('logSettlementAdjustment')) {
    function logSettlementAdjustment($pdo, $vendor_id, $amount, $remarks, $created_by = 'system') {
        $stmt = $pdo->prepare("SELECT COALESCE(running_balance, 0) as r, COALESCE(advance_balance, 0) as a, COALESCE(due_balance, 0) as d FROM vendor_partner_ledger WHERE entity_type = 'vendor' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$vendor_id]);
        $bal = $stmt->fetch();
        $running = floatval($bal['r'] ?? 0);
        $advance = floatval($bal['a'] ?? 0);
        $due = floatval($bal['d'] ?? 0);

        $stmt = $pdo->prepare("INSERT INTO vendor_partner_ledger (entity_type, entity_id, transaction_date, transaction_type, debit, credit, running_balance, advance_balance, due_balance, settlement_status, remarks, created_by) VALUES (?, ?, NOW(), 'adjustment', ?, ?, ?, ?, ?, 'settled', ?, ?)");
        if ($amount >= 0) {
            $stmt->execute(['vendor', $vendor_id, 0, $amount, $running + $amount, $advance, $due, $remarks, $created_by]);
        } else {
            $stmt->execute(['vendor', $vendor_id, abs($amount), 0, $running - abs($amount), $advance, $due, $remarks, $created_by]);
        }
    }
}

if (!function_exists('syncVendorActiveCount')) {
    function syncVendorActiveCount($pdo, $vendor_id = null) {
        try {
            if ($vendor_id) {
                $pdo->prepare("UPDATE vendors v SET v.active_refill_count = (SELECT COUNT(*) FROM cylinders WHERE current_vendor_id = v.id AND status = 'sent_to_vendor') WHERE v.id = ?")->execute([$vendor_id]);
            } else {
                $pdo->exec("UPDATE vendors v SET v.active_refill_count = (SELECT COUNT(*) FROM cylinders WHERE current_vendor_id = v.id AND status = 'sent_to_vendor')");
            }
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  BATCH-TO-LOT MIGRATION — moves all accounting from vendor_refill_batches to dispatch_lots
// ═══════════════════════════════════════════════════════════════

if (!function_exists('runBatchToLotMigration')) {
    function runBatchToLotMigration($pdo) {
        if (_checkMigrationCache('batch_to_lot_migration')) return;
        $migrations = [];

        // 1. Add deduction/addition columns to dispatch_lots
        $dl_cols = _getTableColumnNames($pdo, 'dispatch_lots');
        if (!in_array('deduction_amount', $dl_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER advance_amount";
        }
        if (!in_array('deduction_notes', $dl_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN deduction_notes TEXT DEFAULT NULL AFTER deduction_amount";
        }
        if (!in_array('addition_amount', $dl_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN addition_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER deduction_notes";
        }
        if (!in_array('addition_notes', $dl_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lots ADD COLUMN addition_notes TEXT DEFAULT NULL AFTER addition_amount";
        }

        // 2. Add last_refill_lot_id to cylinders
        $cyl_cols = _getTableColumnNames($pdo, 'cylinders');
        if (!in_array('last_refill_lot_id', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN last_refill_lot_id INT DEFAULT NULL AFTER last_refill_batch_id";
        }

        // 3. Add GST columns to dispatch_lot_items
        $dli_cols = _getTableColumnNames($pdo, 'dispatch_lot_items');
        if (!in_array('gst_rate', $dli_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lot_items ADD COLUMN gst_rate DECIMAL(5,2) DEFAULT 0.00 AFTER refill_cost";
        }
        if (!in_array('taxable_amount', $dli_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lot_items ADD COLUMN taxable_amount DECIMAL(10,2) DEFAULT 0.00 AFTER gst_rate";
        }
        if (!in_array('gst_amount', $dli_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lot_items ADD COLUMN gst_amount DECIMAL(10,2) DEFAULT 0.00 AFTER taxable_amount";
        }
        if (!in_array('cgst', $dli_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lot_items ADD COLUMN cgst DECIMAL(10,2) DEFAULT 0.00 AFTER gst_amount";
        }
        if (!in_array('sgst', $dli_cols)) {
            $migrations[] = "ALTER TABLE dispatch_lot_items ADD COLUMN sgst DECIMAL(10,2) DEFAULT 0.00 AFTER cgst";
        }

        // Run DDL migrations
        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) { error_log("runBatchToLotMigration DDL: " . $e->getMessage()); }
        }

        // 4. Migrate existing batch data to lots (batches without lot_id)
        try {
            $orphan_batches = $pdo->query("SELECT vrb.*, v.name as vendor_name FROM vendor_refill_batches vrb JOIN vendors v ON vrb.vendor_id = v.id WHERE vrb.lot_id IS NULL ORDER BY vrb.id")->fetchAll();
            if (!empty($orphan_batches)) {
                $batch_lot_map = [];
                foreach ($orphan_batches as $batch) {
                    $bid = intval($batch['id']);
                    $vid = intval($batch['vendor_id']);
                    $qty = intval($batch['quantity']);
                    $total_cost = floatval($batch['total_cost']);
                    $net_amount = floatval($batch['net_amount'] ?? $total_cost);
                    $gst_rate = floatval($batch['gst_rate'] ?? 0);
                    $gst_amount = floatval($batch['gst_amount'] ?? 0);
                    $final_total = $total_cost + $gst_amount;
                    $deduction = floatval($batch['deduction_amount'] ?? 0);
                    $addition = floatval($batch['addition_amount'] ?? 0);
                    $payment_status = $batch['payment_status'] ?? 'unpaid';

                    // Create a dispatch lot for this batch
                    $lot_number = 'BATCH-MIGRATED-' . str_pad($bid, 4, '0', STR_PAD_LEFT);
                    $received_date = $batch['received_date'] ?? date('Y-m-d H:i:s');

                    $ins = $pdo->prepare("INSERT INTO dispatch_lots (lot_number, vendor_id, dispatch_date, receive_date, cylinder_count, returned_count, lot_status, advance_amount, deduction_amount, addition_amount, gst_rate, gst_amount, final_refill_amount, final_gst_amount, final_total, total_paid, remaining_balance, payment_status, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, 'completed', 0, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NOW())");
                    $ins->execute([$lot_number, $vid, $received_date, $received_date, $qty, $qty, $deduction, $addition, $gst_rate, $gst_amount, $total_cost, $gst_amount, $final_total, 0, $payment_status, "Migrated from batch #$bid - {$batch['vendor_name']} - {$batch['size_capacity']}", $_SESSION['username'] ?? 'system']);
                    $new_lot_id = $pdo->lastInsertId();
                    $batch_lot_map[$bid] = $new_lot_id;

                    // Update batch record to link to lot
                    $pdo->prepare("UPDATE vendor_refill_batches SET lot_id = ? WHERE id = ?")->execute([$new_lot_id, $bid]);

                    // Get batch items and create lot items
                    $items = $pdo->prepare("SELECT * FROM vendor_refill_batch_items WHERE batch_id = ?");
                    $items->execute([$bid]);
                    while ($item = $items->fetch()) {
                        $cyl_cost = floatval($item['cost_per_unit'] ?? 0);
                        $item_gst = 0;
                        $item_taxable = 0;
                        $item_cgst = 0;
                        $item_sgst = 0;
                        if ($gst_rate > 0 && $cyl_cost > 0) {
                            $gst_calc = calculateGST($cyl_cost, $gst_rate);
                            $item_taxable = $gst_calc['taxable'];
                            $item_gst = $gst_calc['gst_amount'];
                            $item_cgst = $gst_calc['cgst'];
                            $item_sgst = $gst_calc['sgst'];
                        }
                        $pdo->prepare("INSERT INTO dispatch_lot_items (lot_id, cylinder_id, serial_number, gas_type_id, size_capacity, dispatch_status, receive_date, refill_cost, gst_rate, taxable_amount, gst_amount, cgst, sgst) VALUES (?, ?, ?, ?, ?, 'received', ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$new_lot_id, intval($item['cylinder_id']), $item['serial_number'], intval($item['gas_type_id']), $item['size_capacity'], $received_date, $cyl_cost, $gst_rate, $item_taxable, $item_gst, $item_cgst, $item_sgst]);
                    }

                    // Update linked payments
                    $payments = $pdo->prepare("SELECT id FROM payments WHERE vendor_batch_id = ? AND lot_id IS NULL");
                    $payments->execute([$bid]);
                    while ($pay = $payments->fetch()) {
                        $pdo->prepare("UPDATE payments SET lot_id = ? WHERE id = ?")->execute([$new_lot_id, $pay['id']]);
                    }

                    // Update cylinders with last_refill_lot_id
                    if (intval($batch['gas_type_id']) > 0) {
                        $pdo->prepare("UPDATE cylinders SET last_refill_lot_id = ? WHERE last_refill_batch_id = ?")->execute([$new_lot_id, $bid]);
                    }

                    // Update vendor_partner_ledger entries for this batch to reference lot
                    $pdo->prepare("UPDATE vendor_partner_ledger SET reference_type = 'dispatch_lot', reference_id = ? WHERE reference_type = 'vendor_refill_batch' AND reference_id = ?")->execute([$new_lot_id, $bid]);
                }

                // Recalculate lot financials
                foreach ($batch_lot_map as $bid => $lid) {
                    recalcLotFinancials($pdo, $lid);
                }
            }
        } catch (PDOException $e) {
            error_log("runBatchToLotMigration data migration: " . $e->getMessage());
        }

        _setMigrationCache('batch_to_lot_migration');
    }
}

if (!function_exists('recalcLotFinancials')) {
    function recalcLotFinancials($pdo, $lot_id) {
        try {
            $lot = $pdo->prepare("SELECT * FROM dispatch_lots WHERE id = ?");
            $lot->execute([$lot_id]);
            $lot_data = $lot->fetch();
            if (!$lot_data) return;

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatch_lot_items WHERE lot_id = ? AND dispatch_status = 'received'");
            $stmt->execute([$lot_id]);
            $received_count = intval($stmt->fetchColumn());

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatch_lot_items WHERE lot_id = ?");
            $stmt->execute([$lot_id]);
            $total_count = intval($stmt->fetchColumn());

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(refill_cost), 0) FROM dispatch_lot_items WHERE lot_id = ? AND dispatch_status = 'received'");
            $stmt->execute([$lot_id]);
            $sum_refill = floatval($stmt->fetchColumn());

            $gst_rate = floatval($lot_data['gst_rate']);
            $gst_info = [];
            if (function_exists('calculateGST')) {
                $gst_info = calculateGST($sum_refill, $gst_rate);
            }
            $final_gst = floatval($gst_info['gst_amount'] ?? 0);
            $final_total = $sum_refill + $final_gst;
            $deduction = floatval($lot_data['deduction_amount'] ?? 0);
            $addition = floatval($lot_data['addition_amount'] ?? 0);

            // Use estimated_total when no cylinders received yet, otherwise use actual received costs
            if ($received_count > 0) {
                $net_total = $final_total - $deduction + $addition;
            } else {
                $estimated_with_gst = floatval($lot_data['estimated_total'] ?? 0) + floatval($lot_data['gst_amount'] ?? 0);
                $net_total = $estimated_with_gst - $deduction + $addition;
            }

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE lot_id = ? AND (payment_subtype IS NULL OR payment_subtype != 'advance_utilized')");
        $stmt->execute([$lot_id]);
        $total_paid_from_payments = floatval($stmt->fetchColumn());

        $total_lot_paid = $total_paid_from_payments;

        $advance_total = floatval($lot_data['advance_amount'] ?? 0);
        $additional_payments = max(0, $total_paid_from_payments - $advance_total);

        $remaining = max(0, $net_total - $total_lot_paid);

        // ── Transport cost totals ──
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(dispatch_transport_cost), 0) FROM dispatch_lot_items WHERE lot_id = ?");
        $stmt->execute([$lot_id]);
        $dispatch_transport = floatval($stmt->fetchColumn());
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(receive_transport_cost), 0) FROM dispatch_lot_items WHERE lot_id = ? AND dispatch_status = 'received'");
        $stmt->execute([$lot_id]);
        $receive_transport = floatval($stmt->fetchColumn());

            // ── Calculate remaining_balance for the FULL lot including pending cylinders ──
            $full_lot_remaining = $remaining;
            if ($received_count < $total_count && $total_count > 0 && $received_count > 0) {
                $estimated_total = floatval($lot_data['estimated_total'] ?? 0);
                if ($estimated_total > 0) {
                    $per_cylinder_est = $estimated_total / $total_count;
                    $pending_count = $total_count - $received_count;
                    $estimated_pending_cost = $per_cylinder_est * $pending_count;
                    if ($gst_rate > 0) {
                        $pending_gst_info = calculateGST($estimated_pending_cost, $gst_rate);
                        $estimated_pending_total = $estimated_pending_cost + $pending_gst_info['gst_amount'];
                    } else {
                        $estimated_pending_total = $estimated_pending_cost;
                    }
                    $full_lot_remaining = $remaining + $estimated_pending_total;
                }
            }
            // When no items received yet, $remaining already covers the full estimated lot — no addition needed

            $pay_status = 'unpaid';
            if ($total_lot_paid > 0) {
                $pay_status = 'partial';
            }
            // payment_status='paid' only when ALL cylinders are received AND remaining is settled
            if ($received_count >= $total_count && $total_count > 0 && $remaining <= 0) {
                $pay_status = 'paid';
            }

            // Lot status: completed only when all cylinders returned AND fully paid
            $lot_status = 'open';
            if ($received_count >= $total_count && $total_count > 0) {
                $lot_status = ($remaining <= 0) ? 'completed' : 'partial_return';
            } elseif ($received_count > 0) {
                $lot_status = 'partial_return';
            }

            $pdo->prepare("UPDATE dispatch_lots SET returned_count = ?, lot_status = ?, final_refill_amount = ?, final_gst_amount = ?, final_total = ?, total_paid = ?, remaining_balance = ?, additional_payments = ?, payment_status = ?, dispatch_transport_total = ?, receive_transport_total = ? WHERE id = ?")
                ->execute([$received_count, $lot_status, $sum_refill, $final_gst, $final_total, $total_lot_paid, $full_lot_remaining, $additional_payments, $pay_status, $dispatch_transport, $receive_transport, $lot_id]);
        } catch (PDOException $e) {
            error_log("recalcLotFinancials: " . $e->getMessage());
        }
    }
}

if (!function_exists('checkVendorCountMismatches')) {
    function checkVendorCountMismatches($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT v.id, v.name AS vendor_name, v.active_refill_count AS current_count,
                       (SELECT COUNT(*) FROM cylinders WHERE current_vendor_id = v.id AND status = 'sent_to_vendor') AS actual_count
                FROM vendors v
                HAVING current_count != actual_count
                ORDER BY v.name
            ");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('bulkPayVendorBatches')) {
    function bulkPayVendorBatches($pdo, $batch_ids, $payment_data) {
        $payment_method = trim($payment_data['payment_method'] ?? 'Bank Transfer');
        $payment_date_raw = $payment_data['payment_date'] ?? date('Y-m-d\TH:i');
        $payment_date = str_replace('T', ' ', $payment_date_raw) . ':00';
        $reference = trim($payment_data['reference'] ?? '');
        $created_by = $payment_data['created_by'] ?? ($_SESSION['username'] ?? 'system');

        if (empty($batch_ids)) {
            throw new Exception("No batches selected.");
        }

        $pdo->beginTransaction();
        try {
            $ph = implode(',', array_fill(0, count($batch_ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM vendor_refill_batches WHERE id IN ($ph) AND payment_status != 'paid'");
            $stmt->execute(array_map('intval', $batch_ids));
            $batches = $stmt->fetchAll();

            if (empty($batches)) {
                throw new Exception("No unpaid batches found for the selected IDs.");
            }

            $paid_count = 0;
            foreach ($batches as $batch) {
                $batch_id = intval($batch['id']);
                $net_amount = floatval($batch['net_amount'] ?? $batch['total_cost']);
                $pdo->prepare("UPDATE vendor_refill_batches SET payment_status = 'paid', settlement_type = 'paid', paid_method = ?, paid_date = ?, paid_reference = ? WHERE id = ?")->execute([$payment_method, $payment_date, $reference ?: null, $batch_id]);

                $chk = $pdo->prepare("SELECT id FROM payments WHERE vendor_batch_id = ? AND payment_type = 'vendor_refill_payment'");
                $chk->execute([$batch_id]);
                $existing = $chk->fetch();
                if ($existing) {
                    $pdo->prepare("UPDATE payments SET payment_method = ?, payment_date = ?, notes = CONCAT(notes, ' | Bulk paid via ', ?) WHERE id = ?")->execute([$payment_method, $payment_date, $reference ?: 'Batch Payment', $existing['id']]);
                } else {
                    $pdo->prepare("INSERT INTO payments (vendor_id, amount, payment_method, payment_type, notes, payment_date, vendor_batch_id) VALUES (?, ?, ?, 'vendor_refill_payment', ?, ?, ?)")->execute([$batch['vendor_id'], $net_amount, $payment_method, $reference ? "Bulk payment batch #$batch_id - $reference" : "Bulk payment batch #$batch_id", $payment_date, $batch_id]);
                }
                addVendorRefillLedgerEntry($pdo, $batch['vendor_id'], $net_amount, 'payment', $batch_id, "Bulk payment for batch #$batch_id (" . ($reference ?: 'Batch Payment') . ")", $created_by);
                $paid_count++;
            }

            $pdo->commit();
            return $paid_count;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('runBulkOperationAuditMigration')) {
    function runBulkOperationAuditMigration($pdo) {
        if (_checkMigrationCache('bulk_operation_audit')) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bulk_operation_audit` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(100) DEFAULT NULL,
            `operation_type` VARCHAR(50) NOT NULL,
            `action_key` VARCHAR(50) NOT NULL DEFAULT '',
            `record_count` INT NOT NULL DEFAULT 0,
            `processed_count` INT NOT NULL DEFAULT 0,
            `skipped_count` INT NOT NULL DEFAULT 0,
            `before_state` JSON DEFAULT NULL,
            `after_state` JSON DEFAULT NULL,
            `impact_summary` JSON NOT NULL,
            `payment_changes` JSON DEFAULT NULL,
            `gst_changes` JSON DEFAULT NULL,
            `inventory_changes` JSON DEFAULT NULL,
            `ledger_changes` JSON DEFAULT NULL,
            `execution_status` ENUM('success','failed') NOT NULL,
            `error_message` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`operation_type`),
            INDEX (`execution_status`),
            INDEX (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        _setMigrationCache('bulk_operation_audit');
    }
}

if (!function_exists('runCylinderSupplierMigrations')) {
    function runCylinderSupplierMigrations($pdo) {
        if (_checkMigrationCache('cylinder_supplier_migrations')) return;
        $cyl_cols = _getTableColumnNames($pdo, 'cylinders');
        $cs_cols = _getTableColumnNames($pdo, 'cylinder_suppliers');

        $migrations = [];

        // Add supplier_type to cylinder_suppliers if it doesn't exist
        if (!in_array('supplier_type', $cs_cols)) {
            $migrations[] = "ALTER TABLE cylinder_suppliers ADD COLUMN supplier_type ENUM('cylinder','product','both') NOT NULL DEFAULT 'cylinder' AFTER gst_number";
        }

        $migrations[] = "CREATE TABLE IF NOT EXISTS `cylinder_suppliers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `company_name` VARCHAR(200) NOT NULL,
            `contact_person` VARCHAR(150) DEFAULT NULL,
            `mobile` VARCHAR(20) NOT NULL,
            `email` VARCHAR(150) DEFAULT NULL,
            `address` TEXT DEFAULT NULL,
            `gst_number` VARCHAR(20) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`company_name`),
            INDEX (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $migrations[] = "CREATE TABLE IF NOT EXISTS `cylinder_purchases` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `supplier_id` INT NOT NULL,
            `invoice_number` VARCHAR(100) DEFAULT NULL,
            `invoice_date` DATE NOT NULL,
            `business_key` VARCHAR(50) DEFAULT 'prem_gas_solution',
            `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `gst_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `cgst` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `sgst` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `igst` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `grand_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `cylinder_count` INT NOT NULL DEFAULT 0,
            `payment_status` ENUM('pending','paid','partial') NOT NULL DEFAULT 'pending',
            `notes` TEXT DEFAULT NULL,
            `created_by` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`supplier_id`) REFERENCES `cylinder_suppliers` (`id`) ON DELETE RESTRICT,
            INDEX (`supplier_id`),
            INDEX (`invoice_date`),
            INDEX (`payment_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $migrations[] = "CREATE TABLE IF NOT EXISTS `cylinder_purchase_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `purchase_id` INT NOT NULL,
            `cylinder_id` INT DEFAULT NULL,
            `serial_number` VARCHAR(100) NOT NULL,
            `gas_type_id` INT NOT NULL,
            `size_capacity` VARCHAR(50) NOT NULL,
            `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `gst_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `total_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (`purchase_id`) REFERENCES `cylinder_purchases` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`cylinder_id`) REFERENCES `cylinders` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`gas_type_id`) REFERENCES `gas_types` (`id`) ON DELETE RESTRICT,
            INDEX (`purchase_id`),
            INDEX (`serial_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $migrations[] = "CREATE TABLE IF NOT EXISTS `supplier_ledger` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `supplier_id` INT NOT NULL,
            `transaction_date` DATETIME NOT NULL,
            `transaction_type` ENUM('purchase','payment','advance','adjustment') NOT NULL,
            `debit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `credit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `running_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `reference_type` VARCHAR(50) DEFAULT NULL,
            `reference_id` INT DEFAULT NULL,
            `remarks` TEXT DEFAULT NULL,
            `created_by` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`supplier_id`) REFERENCES `cylinder_suppliers` (`id`) ON DELETE RESTRICT,
            INDEX (`supplier_id`),
            INDEX (`transaction_date`),
            INDEX (`transaction_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if (!in_array('supplier_id', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN supplier_id INT DEFAULT NULL AFTER current_vendor_id";
        }
        if (!in_array('purchase_id', $cyl_cols)) {
            $migrations[] = "ALTER TABLE cylinders ADD COLUMN purchase_id INT DEFAULT NULL AFTER supplier_id";
        }

        $gl_type = _getTableColumnType($pdo, 'gst_ledger', 'entity_type');
        if ($gl_type && strpos($gl_type, 'supplier') === false) {
            $migrations[] = "ALTER TABLE gst_ledger MODIFY COLUMN entity_type ENUM('vendor','customer','supplier') NOT NULL";
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('cylinder_supplier_migrations');
    }
}

if (!function_exists('runVendorInvoiceMigrations')) {
    function runVendorInvoiceMigrations($pdo) {
        if (_checkMigrationCache('vendor_invoice_migrations')) return;
        $migrations = [];

        $migrations[] = "CREATE TABLE IF NOT EXISTS `vendor_invoices` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
            `vendor_id` INT NOT NULL,
            `vendor_invoice_number` VARCHAR(100) DEFAULT NULL,
            `lot_id` INT DEFAULT NULL,
            `invoice_date` DATE NOT NULL,
            `due_date` DATE DEFAULT NULL,
            `business_key` VARCHAR(50) DEFAULT NULL,
            `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `deduction_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `addition_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `net_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `gst_rate` DECIMAL(5,2) DEFAULT 0.00,
            `taxable_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `gst_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `cgst` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `sgst` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `igst` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `grand_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
            `notes` TEXT DEFAULT NULL,
            `created_by` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`lot_id`) REFERENCES `dispatch_lots`(`id`) ON DELETE SET NULL,
            INDEX `idx_vi_vendor` (`vendor_id`),
            INDEX `idx_vi_status` (`payment_status`),
            INDEX `idx_vi_date` (`invoice_date`),
            INDEX `idx_vi_business` (`business_key`),
            INDEX `idx_vi_lot` (`lot_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $migrations[] = "CREATE TABLE IF NOT EXISTS `vendor_invoice_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `invoice_id` INT NOT NULL,
            `batch_id` INT DEFAULT NULL,
            `gas_type_id` INT NOT NULL,
            `size_capacity` VARCHAR(50) NOT NULL,
            `quantity` INT NOT NULL DEFAULT 1,
            `rate_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `gst_rate` DECIMAL(5,2) DEFAULT 0.00,
            `taxable_amount` DECIMAL(10,2) DEFAULT 0.00,
            `gst_amount` DECIMAL(10,2) DEFAULT 0.00,
            `cgst` DECIMAL(10,2) DEFAULT 0.00,
            `sgst` DECIMAL(10,2) DEFAULT 0.00,
            `igst` DECIMAL(10,2) DEFAULT 0.00,
            `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (`invoice_id`) REFERENCES `vendor_invoices`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`batch_id`) REFERENCES `vendor_refill_batches`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`gas_type_id`) REFERENCES `gas_types`(`id`) ON DELETE RESTRICT,
            INDEX `idx_vii_invoice` (`invoice_id`),
            INDEX `idx_vii_batch` (`batch_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // Add gst_rate column if missing (for existing tables before migration fix)
        $vi_cols = _getTableColumnNames($pdo, 'vendor_invoices');
        if (!in_array('gst_rate', $vi_cols)) {
            try { $pdo->exec("ALTER TABLE vendor_invoices ADD COLUMN gst_rate DECIMAL(5,2) DEFAULT 0.00 AFTER net_amount"); } catch (PDOException $e) {}
        }

        foreach ($migrations as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
        _setMigrationCache('vendor_invoice_migrations');
    }
}

if (!function_exists('getNextVendorInvoiceNumber')) {
    function getNextVendorInvoiceNumber($pdo) {
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_invoices WHERE invoice_number LIKE ?");
        $stmt->execute(["PINV-$year-%"]);
        $count = intval($stmt->fetchColumn()) + 1;
        return "PINV-$year-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('createVendorInvoiceFromLot')) {
    function createVendorInvoiceFromLot($pdo, $lot_id, $extra = []) {
        $stmt = $pdo->prepare("SELECT dl.*, v.name AS vendor_name FROM dispatch_lots dl JOIN vendors v ON dl.vendor_id = v.id WHERE dl.id = ?");
        $stmt->execute([$lot_id]);
        $lot = $stmt->fetch();
        if (!$lot) throw new Exception("Lot not found");

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_invoices WHERE lot_id = ?");
        $stmt->execute([$lot_id]);
        if (intval($stmt->fetchColumn()) > 0) throw new Exception("Invoice already exists for this lot");

        $stmt = $pdo->prepare("SELECT dli.*, g.name AS gas_name FROM dispatch_lot_items dli JOIN gas_types g ON dli.gas_type_id = g.id WHERE dli.lot_id = ? AND dli.dispatch_status = 'received'");
        $stmt->execute([$lot_id]);
        $items = $stmt->fetchAll();
        if (empty($items)) throw new Exception("No received items in this lot");

        $stmt = $pdo->prepare("SELECT vrb.*, g.name AS gas_name FROM vendor_refill_batches vrb JOIN gas_types g ON vrb.gas_type_id = g.id WHERE vrb.lot_id = ?");
        $stmt->execute([$lot_id]);
        $batches = $stmt->fetchAll();

        $business_key = $extra['business_key'] ?? getBrandConfig()['business_key'];
        $vendor_invoice_number = $extra['vendor_invoice_number'] ?? $lot['lot_number'];
        $invoice_date = $extra['invoice_date'] ?? date('Y-m-d');
        $due_date = $extra['due_date'] ?? null;
        $notes = $extra['notes'] ?? '';
        $created_by = $extra['created_by'] ?? ($_SESSION['user_name'] ?? 'system');

        $inv_num = getNextVendorInvoiceNumber($pdo);

        $gst_rate = floatval($lot['gst_rate'] ?? 0);

        $items_by_gas = [];
        foreach ($items as $item) {
            $key = $item['gas_type_id'] . '|' . $item['size_capacity'];
            if (!isset($items_by_gas[$key])) {
                $items_by_gas[$key] = [
                    'gas_type_id' => $item['gas_type_id'],
                    'size_capacity' => $item['size_capacity'],
                    'quantity' => 0,
                    'rate_per_unit' => 0,
                    'amount' => 0,
                    'gst_rate' => $gst_rate,
                ];
            }
            $cost = floatval($item['refill_cost'] ?? 0);
            $items_by_gas[$key]['quantity']++;
            $items_by_gas[$key]['amount'] += $cost;
        }

        foreach ($items_by_gas as $key => &$gi) {
            $gi['rate_per_unit'] = $gi['quantity'] > 0 ? round($gi['amount'] / $gi['quantity'], 2) : 0;
            $gi['taxable_amount'] = $gi['amount'];
            if ($gst_rate > 0 && function_exists('calculateGST')) {
                $gst_calc = calculateGST($gi['amount'], $gst_rate);
                $gi['gst_amount'] = $gst_calc['gst_amount'];
                $gi['cgst'] = $gst_calc['cgst'];
                $gi['sgst'] = $gst_calc['sgst'];
                $gi['igst'] = $gst_calc['igst'];
                $gi['total_amount'] = $gst_calc['total'];
            } else {
                $gi['gst_amount'] = 0;
                $gi['cgst'] = 0;
                $gi['sgst'] = 0;
                $gi['igst'] = 0;
                $gi['total_amount'] = $gi['amount'];
            }
        }
        unset($gi);

        $subtotal = round(array_sum(array_column($items_by_gas, 'amount')), 2);
        $deduction = floatval($lot['deduction_amount'] ?? 0);
        $addition = floatval($lot['addition_amount'] ?? 0);
        $net_amount = $subtotal - $deduction + $addition;

        if ($gst_rate > 0 && function_exists('calculateGST')) {
            $gst_calc = calculateGST($net_amount, $gst_rate);
            $taxable = $gst_calc['taxable'];
            $gst_amt = $gst_calc['gst_amount'];
            $cgst = $gst_calc['cgst'];
            $sgst = $gst_calc['sgst'];
            $igst = $gst_calc['igst'];
            $grand_total = $gst_calc['total'];
        } else {
            $taxable = $net_amount;
            $gst_amt = 0;
            $cgst = 0;
            $sgst = 0;
            $igst = 0;
            $grand_total = $net_amount;
        }

        $paid_amount = floatval($lot['total_paid'] ?? 0);
        $balance = max(0, $grand_total - $paid_amount);
        $payment_status = $balance <= 0 ? 'paid' : ($paid_amount > 0 ? 'partial' : 'unpaid');

        $stmt = $pdo->prepare("INSERT INTO vendor_invoices (invoice_number, vendor_id, vendor_invoice_number, lot_id, invoice_date, due_date, business_key, subtotal, deduction_amount, addition_amount, net_amount, taxable_amount, gst_amount, cgst, sgst, igst, grand_total, paid_amount, balance, payment_status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$inv_num, $lot['vendor_id'], $vendor_invoice_number, $lot_id, $invoice_date, $due_date, $business_key, $subtotal, $deduction, $addition, $net_amount, $taxable, $gst_amt, $cgst, $sgst, $igst, $grand_total, $paid_amount, $balance, $payment_status, $notes, $created_by]);
        $invoice_id = intval($pdo->lastInsertId());

        $ins_item = $pdo->prepare("INSERT INTO vendor_invoice_items (invoice_id, batch_id, gas_type_id, size_capacity, quantity, rate_per_unit, amount, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst, total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($items_by_gas as $gi) {
            $ins_item->execute([$invoice_id, null, $gi['gas_type_id'], $gi['size_capacity'], $gi['quantity'], $gi['rate_per_unit'], $gi['amount'], $gi['gst_rate'], $gi['taxable_amount'], $gi['gst_amount'], $gi['cgst'], $gi['sgst'], $gi['igst'], $gi['total_amount']]);
        }

        if (function_exists('syncGSTFromVendorInvoice')) {
            syncGSTFromVendorInvoice($pdo, $invoice_id);
        }

        return $invoice_id;
    }
}

if (!function_exists('updateVendorInvoicePaymentStatus')) {
    function updateVendorInvoicePaymentStatus($pdo, $invoice_id) {
        $stmt = $pdo->prepare("SELECT * FROM vendor_invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch();
        if (!$inv) return;

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_type IN ('vendor_refill_payment','vendor_payment') AND vendor_id = ? AND notes LIKE ?");
        $ref_pattern = '%' . $inv['invoice_number'] . '%';
        $stmt->execute([$inv['vendor_id'], $ref_pattern]);
        $direct_paid = floatval($stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE lot_id = ?");
        $stmt->execute([$inv['lot_id']]);
        $lot_paid = floatval($stmt->fetchColumn());

        $paid_amount = max($direct_paid, $lot_paid);
        $grand_total = floatval($inv['grand_total']);
        $balance = max(0, $grand_total - $paid_amount);
        $payment_status = $balance <= 0 ? 'paid' : ($paid_amount > 0 ? 'partial' : 'unpaid');

        $pdo->prepare("UPDATE vendor_invoices SET paid_amount = ?, balance = ?, payment_status = ? WHERE id = ?")->execute([$paid_amount, $balance, $payment_status, $invoice_id]);
    }
}

/**
 * Purge bulk_operation_audit records older than 90 days — called once per day.
 */
if (!function_exists('purgeOldBulkOperationAudit')) {
    function purgeOldBulkOperationAudit($pdo) {
        $pdo->exec("DELETE FROM bulk_operation_audit WHERE created_at < NOW() - INTERVAL 90 DAY");
    }
}

if (!function_exists('runDropPODueDateMigration')) {
    function runDropPODueDateMigration($pdo) {
        if (_checkMigrationCache('drop_po_due_date')) return;
        $cols = _getTableColumnNames($pdo, 'refill_orders');
        foreach (['po_number', 'due_date', 'delivery_note'] as $col) {
            if (in_array($col, $cols)) {
                try { $pdo->exec("ALTER TABLE refill_orders DROP COLUMN `$col`"); } catch (PDOException $e) {}
            }
        }
        _setMigrationCache('drop_po_due_date');
    }
}

if (!function_exists('runTransportCostMigrations')) {
    function runTransportCostMigrations($pdo) {
        if (_checkMigrationCache('transport_cost_migrations')) return;
        $dl_cols = _getTableColumnNames($pdo, 'dispatch_lots');
        $dli_cols = _getTableColumnNames($pdo, 'dispatch_lot_items');

        if (!in_array('dispatch_transport', $dl_cols)) {
            try { $pdo->exec("ALTER TABLE dispatch_lots ADD COLUMN dispatch_transport DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER addition_notes"); } catch (PDOException $e) {}
        }
        if (!in_array('receive_transport', $dl_cols)) {
            try { $pdo->exec("ALTER TABLE dispatch_lots ADD COLUMN receive_transport DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER dispatch_transport"); } catch (PDOException $e) {}
        }
        if (!in_array('dispatch_transport_cost', $dli_cols)) {
            try { $pdo->exec("ALTER TABLE dispatch_lot_items ADD COLUMN dispatch_transport_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER sgst"); } catch (PDOException $e) {}
        }
        if (!in_array('receive_transport_cost', $dli_cols)) {
            try { $pdo->exec("ALTER TABLE dispatch_lot_items ADD COLUMN receive_transport_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER dispatch_transport_cost"); } catch (PDOException $e) {}
        }
        _setMigrationCache('transport_cost_migrations');
    }
}

if (!function_exists('runVendorActivityLogMigration')) {
    function runVendorActivityLogMigration($pdo) {
        if (_checkMigrationCache('vendor_activity_log_migration')) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `vendor_activity_log` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `vendor_id` INT NOT NULL,
                `activity_type` VARCHAR(50) NOT NULL,
                `activity_date` DATETIME NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `details_json` TEXT DEFAULT NULL,
                `reference_type` VARCHAR(50) DEFAULT NULL,
                `reference_id` INT DEFAULT NULL,
                `cylinder_count` INT DEFAULT 0,
                `amount` DECIMAL(10,2) DEFAULT 0.00,
                `payment_method` VARCHAR(50) DEFAULT NULL,
                `balance_after` DECIMAL(10,2) DEFAULT NULL,
                `lot_number` VARCHAR(50) DEFAULT NULL,
                `invoice_number` VARCHAR(50) DEFAULT NULL,
                `created_by` VARCHAR(100) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_val_vendor_date` (`vendor_id`, `activity_date` DESC),
                INDEX `idx_val_reference` (`reference_type`, `reference_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {}
        _setMigrationCache('vendor_activity_log_migration');
    }
}

if (!function_exists('logVendorActivity')) {
    function logVendorActivity($pdo, $vendor_id, $activity_type, $title, $description = null, $details = [], $extra = []) {
        try {
            $activity_date = $extra['activity_date'] ?? date('Y-m-d H:i:s');
            $reference_type = $extra['reference_type'] ?? null;
            $reference_id = $extra['reference_id'] ?? null;
            $cylinder_count = intval($extra['cylinder_count'] ?? 0);
            $amount = floatval($extra['amount'] ?? 0);
            $payment_method = $extra['payment_method'] ?? null;
            $balance_after = isset($extra['balance_after']) ? floatval($extra['balance_after']) : null;
            $lot_number = $extra['lot_number'] ?? null;
            $invoice_number = $extra['invoice_number'] ?? null;
            $created_by = $extra['created_by'] ?? ($_SESSION['username'] ?? 'system');
            $details_json = !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

            $stmt = $pdo->prepare("INSERT INTO vendor_activity_log (vendor_id, activity_type, activity_date, title, description, details_json, reference_type, reference_id, cylinder_count, amount, payment_method, balance_after, lot_number, invoice_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vendor_id, $activity_type, $activity_date, $title, $description, $details_json, $reference_type, $reference_id, $cylinder_count, $amount, $payment_method, $balance_after, $lot_number, $invoice_number, $created_by]);
            return intval($pdo->lastInsertId());
        } catch (PDOException $e) {
            error_log("logVendorActivity failed: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('getVendorActivityLog')) {
    function getVendorActivityLog($pdo, $vendor_id, $limit = 20, $offset = 0, $type_filter = null) {
        $result = ['rows' => [], 'total' => 0];
        try {
            $where = "WHERE vendor_id = ?";
            $params = [$vendor_id];
            if ($type_filter && $type_filter !== 'all') {
                if ($type_filter === 'financial') {
                    $where .= " AND activity_type IN ('payment_made','advance_settled','advance_paid')";
                } elseif ($type_filter === 'operations') {
                    $where .= " AND activity_type IN ('dispatch','receive')";
                } elseif ($type_filter === 'borrow_lend') {
                    $where .= " AND activity_type IN ('borrow','lend','return')";
                } else {
                    $where .= " AND activity_type = ?";
                    $params[] = $type_filter;
                }
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_activity_log $where");
            $stmt->execute($params);
            $result['total'] = intval($stmt->fetchColumn());

            $stmt = $pdo->prepare("SELECT * FROM vendor_activity_log $where ORDER BY activity_date DESC, id DESC LIMIT " . intval($limit) . " OFFSET " . intval($offset));
            $stmt->execute($params);
            $result['rows'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getVendorActivityLog failed: " . $e->getMessage());
        }
        return $result;
    }
}
