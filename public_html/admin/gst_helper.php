<?php
/**
 * GST Accounting & Settlement — Core Library
 * Migrations, calculation, ledger, settlement, reporting
 */

// ─── MIGRATIONS ───────────────────────────────────────────

function runGSTMigrations($pdo) {
    // 1. gst_rates table
    try { $pdo->query("SELECT id FROM gst_rates LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'gst_rates') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gst_rates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rate_percent DECIMAL(5,2) NOT NULL,
                label VARCHAR(100) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY (rate_percent)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("INSERT IGNORE INTO gst_rates (rate_percent, label) VALUES (5, 'GST 5%'), (18, 'GST 18%')");
        }
    }

    // 2. gst_ledger table
    try { $pdo->query("SELECT id FROM gst_ledger LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'gst_ledger') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gst_ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity_type ENUM('vendor','customer','supplier') NOT NULL,
                entity_id INT NOT NULL,
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                taxable_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                cgst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                sgst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                igst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                input_output_type ENUM('input','output') NOT NULL,
                reference_type VARCHAR(50) NOT NULL,
                reference_id INT NOT NULL,
                transaction_date DATE NOT NULL,
                gst_applicable TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_entity (entity_type, entity_id),
                INDEX idx_type (input_output_type),
                INDEX idx_ref (reference_type, reference_id),
                INDEX idx_date (transaction_date),
                INDEX idx_rate (gst_rate)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // 3. gst_settlements table
    try { $pdo->query("SELECT id FROM gst_settlements LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'gst_settlements') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gst_settlements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                business_key VARCHAR(100) NOT NULL DEFAULT 'prem_gas_solution',
                settlement_month DATE NOT NULL,
                total_input DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_output DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                net_payable DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                itc_opening DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                itc_closing DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                payment_status ENUM('pending','paid','partial') NOT NULL DEFAULT 'pending',
                payment_date DATETIME DEFAULT NULL,
                payment_method VARCHAR(50) DEFAULT NULL,
                payment_amount DECIMAL(10,2) DEFAULT 0.00,
                remarks TEXT DEFAULT NULL,
                created_by VARCHAR(100) DEFAULT NULL,
                is_locked TINYINT(1) NOT NULL DEFAULT 0,
                locked_at DATETIME DEFAULT NULL,
                locked_by VARCHAR(100) DEFAULT NULL,
                unlocked_at DATETIME DEFAULT NULL,
                unlocked_by VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_business_month (business_key, settlement_month),
                INDEX idx_status (payment_status),
                INDEX idx_month (settlement_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // 3b. business_key column on gst_settlements (multi-brand migration)
    try { $pdo->query("SELECT business_key FROM gst_settlements LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'business_key') !== false) {
            $pdo->exec("ALTER TABLE gst_settlements ADD COLUMN business_key VARCHAR(100) NOT NULL DEFAULT 'prem_gas_solution' AFTER id, ADD INDEX idx_business_key (business_key)");
            try { $pdo->exec("ALTER TABLE gst_settlements DROP INDEX uk_business_month"); } catch (PDOException $e2) {}
            try { $pdo->exec("ALTER TABLE gst_settlements DROP INDEX settlement_month"); } catch (PDOException $e2) {}
            $pdo->exec("CREATE UNIQUE INDEX uk_business_month ON gst_settlements (business_key, settlement_month)");
        }
    }

    // 4. Columns on refill_order_items
    $cols_item = ['gst_rate DECIMAL(5,2) DEFAULT 0.00', 'taxable_amount DECIMAL(10,2) DEFAULT 0.00', 'gst_amount DECIMAL(10,2) DEFAULT 0.00', 'cgst DECIMAL(10,2) DEFAULT 0.00', 'sgst DECIMAL(10,2) DEFAULT 0.00', 'igst DECIMAL(10,2) DEFAULT 0.00'];
    foreach ($cols_item as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM refill_order_items LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE refill_order_items ADD COLUMN $col_def");
            }
        }
    }

    // 5. Columns on vendor_refill_batch_items
    $cols_bi = ['gst_rate DECIMAL(5,2) DEFAULT 0.00', 'taxable_amount DECIMAL(10,2) DEFAULT 0.00', 'gst_amount DECIMAL(10,2) DEFAULT 0.00', 'cgst DECIMAL(10,2) DEFAULT 0.00', 'sgst DECIMAL(10,2) DEFAULT 0.00', 'igst DECIMAL(10,2) DEFAULT 0.00'];
    foreach ($cols_bi as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM vendor_refill_batch_items LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE vendor_refill_batch_items ADD COLUMN $col_def");
            }
        }
    }

    // 6. Columns on vendor_refill_batches
    $cols_b = ['gst_rate DECIMAL(5,2) DEFAULT 0.00', 'taxable_amount DECIMAL(10,2) DEFAULT 0.00', 'gst_amount DECIMAL(10,2) DEFAULT 0.00', 'cgst DECIMAL(10,2) DEFAULT 0.00', 'sgst DECIMAL(10,2) DEFAULT 0.00', 'igst DECIMAL(10,2) DEFAULT 0.00'];
    foreach ($cols_b as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM vendor_refill_batches LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE vendor_refill_batches ADD COLUMN $col_def");
            }
        }
    }

    // 7. gst_rate column on refill_orders
    try { $pdo->query("SELECT gst_rate FROM refill_orders LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'gst_rate') !== false) {
            $pdo->exec("ALTER TABLE refill_orders ADD COLUMN gst_rate DECIMAL(5,2) DEFAULT 0.00");
        }
    }

    // 8. GST columns on dispatch_lot_items
    $cols_dli = ['gst_rate DECIMAL(5,2) DEFAULT 0.00', 'taxable_amount DECIMAL(10,2) DEFAULT 0.00', 'gst_amount DECIMAL(10,2) DEFAULT 0.00', 'cgst DECIMAL(10,2) DEFAULT 0.00', 'sgst DECIMAL(10,2) DEFAULT 0.00'];
    foreach ($cols_dli as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM dispatch_lot_items LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE dispatch_lot_items ADD COLUMN $col_def");
            }
        }
    }

    // 9. Missing columns on cylinders (dispatch lot support)
    $cyl_cols = ['current_refill_cost DECIMAL(10,2) DEFAULT 0.00', 'last_refill_vendor_id INT DEFAULT NULL', 'last_refill_lot_id INT DEFAULT NULL'];
    foreach ($cyl_cols as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM cylinders LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE cylinders ADD COLUMN $col_def");
            }
        }
    }

    // 10. dispatch_lots deduction/addition columns
    $dl_cols = ['deduction_amount DECIMAL(10,2) DEFAULT 0.00', 'deduction_notes TEXT DEFAULT NULL', 'addition_amount DECIMAL(10,2) DEFAULT 0.00', 'addition_notes TEXT DEFAULT NULL'];
    foreach ($dl_cols as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM dispatch_lots LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE dispatch_lots ADD COLUMN $col_def");
            }
        }
    }

    // 12. Seed additional GST rates
    try {
        $pdo->exec("INSERT IGNORE INTO gst_rates (rate_percent, label) VALUES (12, 'GST 12%'), (28, 'GST 28%')");
    } catch (PDOException $e) {}

    // 13. payment_reference column on gst_settlements (UTR/Challan/Cheque ref for audit)
    try { $pdo->query("SELECT payment_reference FROM gst_settlements LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'payment_reference') !== false) {
            $pdo->exec("ALTER TABLE gst_settlements ADD COLUMN payment_reference VARCHAR(100) DEFAULT NULL AFTER payment_method");
        }
    }
}

// ─── GST RETURN MIGRATIONS (Step 1) ────────────────────

function runGSTReturnMigrations($pdo) {
    // 1. gst_returns table
    try { $pdo->query("SELECT id FROM gst_returns LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'gst_returns') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gst_returns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                business_key VARCHAR(100) NOT NULL,
                return_type ENUM('gstr1','gstr3b','gstr2b','gstr9','gstr4','gstr6','gstr7','gstr8','cmp08') NOT NULL,
                financial_year VARCHAR(9) NOT NULL,
                gst_period VARCHAR(7) NOT NULL,
                return_number VARCHAR(50) NOT NULL,
                version INT NOT NULL DEFAULT 1,
                status ENUM('draft','validated','ready_for_filing','filed','rejected','amended') DEFAULT 'draft',
                generation_date DATETIME DEFAULT NULL,
                generated_by VARCHAR(100) DEFAULT NULL,
                filed_date DATETIME DEFAULT NULL,
                filed_by VARCHAR(100) DEFAULT NULL,
                filing_reference VARCHAR(100) DEFAULT NULL,
                json_data LONGTEXT DEFAULT NULL,
                summary_data LONGTEXT DEFAULT NULL,
                validation_results LONGTEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_period (business_key, return_type, financial_year, gst_period),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // 3. gst_return_items table
    try { $pdo->query("SELECT id FROM gst_return_items LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'gst_return_items') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gst_return_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gst_return_id INT NOT NULL,
                section VARCHAR(50) NOT NULL,
                reference_type VARCHAR(50) NOT NULL,
                reference_id INT NOT NULL,
                invoice_number VARCHAR(100) DEFAULT NULL,
                customer_gstin VARCHAR(15) DEFAULT NULL,
                customer_name VARCHAR(200) DEFAULT NULL,
                place_of_supply INT DEFAULT NULL,
                hsn_code VARCHAR(8) DEFAULT NULL,
                taxable_value DECIMAL(10,2) DEFAULT 0.00,
                gst_rate DECIMAL(5,2) DEFAULT 0.00,
                cgst DECIMAL(10,2) DEFAULT 0.00,
                sgst DECIMAL(10,2) DEFAULT 0.00,
                igst DECIMAL(10,2) DEFAULT 0.00,
                total_gst DECIMAL(10,2) DEFAULT 0.00,
                total_value DECIMAL(10,2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_return (gst_return_id),
                INDEX idx_section (section),
                INDEX idx_reference (reference_type, reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // Drop consolidated tables
    try { $pdo->exec("DROP TABLE IF EXISTS gst_validation_errors"); }
    catch (PDOException $e) {}

    // 4. gst_reconciliation table
    try { $pdo->query("SELECT id FROM gst_reconciliation LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'gst_reconciliation') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gst_reconciliation (
                id INT AUTO_INCREMENT PRIMARY KEY,
                business_key VARCHAR(100) NOT NULL,
                financial_year VARCHAR(9) NOT NULL,
                gst_period VARCHAR(7) NOT NULL,
                vendor_gstin VARCHAR(15) NOT NULL,
                vendor_invoice_number VARCHAR(100) NOT NULL,
                vendor_invoice_date DATE DEFAULT NULL,
                purchase_gst_amount DECIMAL(10,2) DEFAULT 0.00,
                purchase_taxable_value DECIMAL(10,2) DEFAULT 0.00,
                itc_eligibility ENUM('eligible','ineligible','reversal') DEFAULT 'eligible',
                itc_amount DECIMAL(10,2) DEFAULT 0.00,
                match_status ENUM('matched','partial','missing','duplicate','blocked') DEFAULT 'missing',
                gst_difference DECIMAL(10,2) DEFAULT 0.00,
                portal_gst_amount DECIMAL(10,2) DEFAULT NULL,
                reference_type VARCHAR(50) DEFAULT NULL,
                reference_id INT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_period (business_key, financial_year, gst_period),
                INDEX idx_vendor (vendor_gstin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // 6. audit_log table (was gst_amendment_log)
    try { $pdo->query("SELECT id FROM audit_log LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'audit_log') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module VARCHAR(50) DEFAULT 'gst',
                gst_return_id INT DEFAULT NULL,
                reference_type VARCHAR(50) NOT NULL,
                reference_id INT NOT NULL,
                field_name VARCHAR(100) DEFAULT NULL,
                old_value TEXT DEFAULT NULL,
                new_value TEXT DEFAULT NULL,
                reason TEXT DEFAULT NULL,
                amended_by VARCHAR(100) DEFAULT NULL,
                amended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_module (module),
                INDEX idx_return (gst_return_id),
                INDEX idx_reference (reference_type, reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }
    try { $pdo->exec("DROP TABLE IF EXISTS gst_amendment_log"); }
    catch (PDOException $e) {}
    try { $pdo->exec("DROP TABLE IF EXISTS gst_filing_config"); }
    catch (PDOException $e) {}
    try { $pdo->exec("DROP TABLE IF EXISTS gst_filing_lock"); }
    catch (PDOException $e) {}

    // ── Column migrations on existing tables ──

    // refill_orders
    $ro_cols = [
        'include_in_gst_return TINYINT(1) DEFAULT 1',
        'gst_status ENUM("draft","filed","amended") DEFAULT "draft"',
        'invoice_type ENUM("b2b","b2c","credit_note","debit_note") DEFAULT "b2b"',
        'place_of_supply_state_code INT DEFAULT NULL',
        'reverse_charge TINYINT(1) DEFAULT 0',
    ];
    foreach ($ro_cols as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM refill_orders LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE refill_orders ADD COLUMN $col_def");
            }
        }
    }

    // refill_order_items
    $roi_cols = [
        'hsn_code VARCHAR(8) DEFAULT NULL',
        'itc_eligible TINYINT(1) DEFAULT 1',
    ];
    foreach ($roi_cols as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM refill_order_items LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE refill_order_items ADD COLUMN $col_def");
            }
        }
    }

    // customers
    $cust_cols = [
        'state_code INT DEFAULT NULL',
        'state_name VARCHAR(100) DEFAULT NULL',
        'city VARCHAR(100) DEFAULT NULL',
        'pincode VARCHAR(10) DEFAULT NULL',
        'registration_type ENUM("regular","composition","unregistered","others") DEFAULT "regular"',
    ];
    foreach ($cust_cols as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM customers LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE customers ADD COLUMN $col_def");
            }
        }
    }

    // gas_types
    try { $pdo->query("SELECT hsn_code FROM gas_types LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'hsn_code') !== false) {
            $pdo->exec("ALTER TABLE gas_types ADD COLUMN hsn_code VARCHAR(8) DEFAULT '280440'");
        }
    }

    // products
    try { $pdo->query("SELECT hsn_code FROM products LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'hsn_code') !== false) {
            $pdo->exec("ALTER TABLE products ADD COLUMN hsn_code VARCHAR(8) DEFAULT NULL");
        }
    }

    // vendors
    $vend_cols = [
        'state_code INT DEFAULT NULL',
        'registration_type ENUM("regular","composition","unregistered","others") DEFAULT "regular"',
    ];
    foreach ($vend_cols as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM vendors LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE vendors ADD COLUMN $col_def");
            }
        }
    }

    // cylinder_suppliers
    $cs_cols = [
        'state_code INT DEFAULT NULL',
        'registration_type ENUM("regular","composition","unregistered","others") DEFAULT "regular"',
    ];
    foreach ($cs_cols as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->query("SELECT $col_name FROM cylinder_suppliers LIMIT 0"); }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), $col_name) !== false) {
                $pdo->exec("ALTER TABLE cylinder_suppliers ADD COLUMN $col_def");
            }
        }
    }

    // partners
    try { $pdo->query("SELECT state_code FROM partners LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'state_code') !== false) {
            $pdo->exec("ALTER TABLE partners ADD COLUMN state_code INT DEFAULT NULL");
        }
    }
}

// ─── GST PERIOD HELPERS ─────────────────────────────────

function getGSTPeriodForDate($date) {
    $ts = strtotime($date);
    $month = intval(date('m', $ts));
    $year = intval(date('Y', $ts));
    // Financial year: Apr-Mar
    $fy_start = $month >= 4 ? $year : $year - 1;
    $fy_end = $fy_start + 1;
    $fy = substr($fy_start, 2) . '-' . substr($fy_end, 2);
    return [
        'financial_year' => $fy,
        'gst_period' => sprintf('%02d-%04d', $month, $year),
        'month' => $month,
        'year' => $year,
        'fy_start' => $fy_start,
        'fy_end' => $fy_end,
        'label' => date('M Y', $ts),
        'quarter' => ceil($month / 3),
    ];
}

function getCurrentGSTPeriod() {
    return getGSTPeriodForDate(date('Y-m-d'));
}

function getGSTPeriodFromParam($param) {
    // Accepts MM-YYYY format
    if (preg_match('/^(\d{2})-(\d{4})$/', $param, $m)) {
        $pm = intval($m[1]); $py = intval($m[2]);
        if ($pm >= 1 && $pm <= 12) {
            return getGSTPeriodForDate(sprintf('%04d-%02d-01', $py, $pm));
        }
    }
    return getCurrentGSTPeriod();
}

function getGSTPeriodDueDate($period, $frequency = 'monthly') {
    $m = intval($period['month']);
    $y = intval($period['year']);
    if ($frequency === 'quarterly') {
        $q = $period['quarter'];
        // QRMP due dates: 20th of month after quarter end
        $due_month = $q * 3 + 1;
        $due_year = $y;
        if ($due_month > 12) { $due_month -= 12; $due_year++; }
        return sprintf('%04d-%02d-20', $due_year, $due_month);
    }
    // Monthly: 20th of next month
    $due_month = $m + 1;
    $due_year = $y;
    if ($due_month > 12) { $due_month = 1; $due_year++; }
    return sprintf('%04d-%02d-20', $due_year, $due_month);
}

function getGSTPeriodsInRange($start_date, $end_date) {
    $periods = [];
    $start = strtotime($start_date);
    $end = strtotime($end_date);
    $current = strtotime(date('Y-m-01', $start));
    while ($current <= $end) {
        $periods[] = getGSTPeriodForDate(date('Y-m-d', $current));
        $current = strtotime('+1 month', $current);
    }
    return $periods;
}

function getGSTFilingConfig($pdo, $business_key) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM business_config WHERE business_key = ?");
        $stmt->execute([$business_key]);
        $row = $stmt->fetch();
        if ($row) {
            // Map business_config keys to gst_filing_config keys for backward compat
            $row['legal_name'] = $row['business_name'] ?? '';
            $row['trade_name'] = $row['label'] ?? '';
            return $row;
        }
    } catch (PDOException $e) {}
    return [
        'business_key' => $business_key,
        'gst_registration_type' => 'regular',
        'filing_frequency' => 'monthly',
        'gstin' => '',
        'legal_name' => '',
        'trade_name' => '',
        'state_code' => 0,
        'default_place_of_supply' => '',
        'gstr1_enabled' => 1,
        'gstr3b_enabled' => 1,
        'gstr2b_enabled' => 1,
        'gstr9_enabled' => 0,
        'gstr4_enabled' => 0,
        'gstr6_enabled' => 0,
        'gstr7_enabled' => 0,
        'gstr8_enabled' => 0,
        'cmp08_enabled' => 0,
    ];
}

// ─── AUTO CLASSIFICATION ────────────────────────────────

function autoClassifyInvoice($customer) {
    $gstin = trim($customer['gst_number'] ?? '');
    if (empty($gstin)) return 'b2c';
    // Basic GSTIN format validation: 2-digit state + 10-char PAN + 1 entity + Z + check
    if (preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', strtoupper($gstin))) {
        return 'b2b';
    }
    return 'b2c';
}

function validateGSTIN($gstin) {
    $gstin = strtoupper(trim($gstin));
    if (empty($gstin)) return true; // empty is valid (B2C)
    return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin);
}

function getStateCodeFromGSTIN($gstin) {
    $gstin = trim($gstin);
    if (strlen($gstin) >= 2 && is_numeric(substr($gstin, 0, 2))) {
        return intval(substr($gstin, 0, 2));
    }
    return 0;
}

function isIntraState($customer_state_code, $business_state_code) {
    return intval($customer_state_code) === intval($business_state_code);
}

// ─── GSTR-1 GENERATION ──────────────────────────────────

function generateGSTR1($pdo, $business_key, $period, $generated_by = 'system') {
    $fy = $period['financial_year'];
    $gp = $period['gst_period'];
    $month_start = sprintf('%04d-%02d-01', $period['year'], $period['month']);
    $month_end = date('Y-m-t', strtotime($month_start));

    // Get brand config
    $brand = getBrandConfig($business_key);
    $filing_cfg = getGSTFilingConfig($pdo, $business_key);

    // Find latest version for this period
    $version = 1;
    try {
        $stmt = $pdo->prepare("SELECT MAX(version) FROM gst_returns WHERE business_key=? AND return_type='gstr1' AND financial_year=? AND gst_period=?");
        $stmt->execute([$business_key, $fy, $gp]);
        $max_v = intval($stmt->fetchColumn());
        $version = $max_v + 1;
    } catch (PDOException $e) {}

    $return_number = sprintf('%s-GSTR1-%s-V%d', $fy, str_replace('-', '', $gp), $version);

    // Query eligible orders
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name, c.gst_number as customer_gst, c.state_code as customer_state, c.registration_type as customer_reg_type
        FROM refill_orders o
        JOIN customers c ON o.customer_id = c.id
        WHERE o.business_name = ?
          AND DATE(o.order_date) >= ? AND DATE(o.order_date) <= ?
          AND (o.include_in_gst_return IS NULL OR o.include_in_gst_return = 1)
          AND o.gst_status NOT IN ('filed')
        ORDER BY o.order_date ASC
    ");
    $stmt->execute([$business_key, $month_start, $month_end]);
    $orders = $stmt->fetchAll();

    // Create return record
    $ins = $pdo->prepare("INSERT INTO gst_returns (business_key, return_type, financial_year, gst_period, return_number, version, status, generation_date, generated_by) VALUES (?, 'gstr1', ?, ?, ?, ?, 'draft', NOW(), ?)");
    $ins->execute([$business_key, $fy, $gp, $return_number, $version, $generated_by]);
    $return_id = $pdo->lastInsertId();

    $biz_state = intval($filing_cfg['state_code'] ?? 0);

    // Process each order
    $item_ins = $pdo->prepare("INSERT INTO gst_return_items (gst_return_id, section, reference_type, reference_id, invoice_number, customer_gstin, customer_name, place_of_supply, hsn_code, taxable_value, gst_rate, cgst, sgst, igst, total_gst, total_value) VALUES (?, ?, 'refill_order', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $sections = ['b2b' => [], 'b2c' => [], 'nil' => [], 'hsn' => []];

    foreach ($orders as $order) {
        $invoice_type = autoClassifyInvoice($order);
        $customer_state = intval($order['customer_state'] ?? 0);
        $place_of_supply = $customer_state > 0 ? $customer_state : $biz_state;

        // Get order items with HSN
        $items_stmt = $pdo->prepare("
            SELECT oi.*, g.hsn_code as gas_hsn, p.hsn_code as product_hsn,
                   g.name as gas_name, p.name as product_name
            FROM refill_order_items oi 
            LEFT JOIN gas_types g ON oi.gas_type_id = g.id
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.refill_order_id = ?
        ");
        $items_stmt->execute([$order['id']]);
        $items = $items_stmt->fetchAll();

        $order_taxable = 0; $order_gst = 0;
        $order_cgst = 0; $order_sgst = 0; $order_igst = 0;

        foreach ($items as $item) {
            $hsn = $item['hsn_code'] ?? $item['gas_hsn'] ?? $item['product_hsn'] ?? '280440';
            $rate = floatval($item['gst_rate'] ?? 0);
            $taxable = floatval($item['taxable_amount'] ?? ($item['price_per_unit'] * $item['qty']));
            $gst_amt = floatval($item['gst_amount'] ?? 0);
            $cgst = floatval($item['cgst'] ?? ($gst_amt / 2));
            $sgst = floatval($item['sgst'] ?? ($gst_amt - $cgst));
            $igst = floatval($item['igst'] ?? 0);

            // Determine section and correct IGST/CGST/SGST split
            $is_inter_state = ($customer_state > 0 && $biz_state > 0 && $customer_state !== $biz_state);
            if ($rate == 0) {
                $section = 'nil';
            } elseif ($invoice_type === 'b2b') {
                $section = $is_inter_state ? 'b2cl' : 'b2b';
            } else {
                $section = 'b2c';
            }
            if ($is_inter_state && $rate > 0) {
                $igst = $gst_amt;
                $cgst = 0.00;
                $sgst = 0.00;
            } else {
                $igst = 0.00;
            }

            $total_val = $taxable + $gst_amt;
            $total_gst = $gst_amt;

            $item_ins->execute([
                $return_id, $section, $order['id'],
                $order['invoice_number'] ?? ('INV-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT)),
                $order['customer_gst'] ?? '', $order['customer_name'],
                $place_of_supply, $hsn, $taxable, $rate,
                $cgst, $sgst, $igst, $total_gst, $total_val
            ]);

            // Aggregate for HSN summary
            $rate_key = $hsn . '_' . $rate;
            if (!isset($sections['hsn'][$rate_key])) {
                $sections['hsn'][$rate_key] = [
                    'hsn' => $hsn, 'rate' => $rate,
                    'uqc' => 'NOS', 'qty' => 0,
                    'taxable' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0,
                    'total' => 0
                ];
            }
            $sections['hsn'][$rate_key]['qty'] += intval($item['qty'] ?? 1);
            $sections['hsn'][$rate_key]['taxable'] += $taxable;
            $sections['hsn'][$rate_key]['gst'] += $total_gst;
            $sections['hsn'][$rate_key]['cgst'] += $cgst;
            $sections['hsn'][$rate_key]['sgst'] += $sgst;
            $sections['hsn'][$rate_key]['igst'] += $igst;
            $sections['hsn'][$rate_key]['total'] += $total_val;

            $order_taxable += $taxable;
            $order_gst += $total_gst;
            $order_cgst += $cgst;
            $order_sgst += $sgst;
            $order_igst += $igst;
        }

        // Aggregate by section for doc summary
        $sec = $invoice_type === 'b2b' ? 'b2b' : ($invoice_type === 'b2c' ? 'b2c' : 'nil');
        if (!isset($sections[$sec])) $sections[$sec] = [];
        $sections[$sec][] = [
            'invoice_number' => $order['invoice_number'] ?? ('INV-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT)),
            'customer_gst' => $order['customer_gst'] ?? '',
            'customer_name' => $order['customer_name'],
            'taxable' => $order_taxable,
            'gst' => $order_gst,
            'total' => $order_taxable + $order_gst,
        ];
    }

    // Insert HSN summary rows
    foreach ($sections['hsn'] as $h) {
        $item_ins->execute([
            $return_id, 'hsn', 0,
            '', '', 'HSN Summary',
            0, $h['hsn'], $h['taxable'], $h['rate'],
            $h['cgst'], $h['sgst'], $h['igst'], $h['gst'], $h['total']
        ]);
    }

    // Build summary data
    $summary = [
        'total_b2b' => count($sections['b2b'] ?? []),
        'total_b2c' => count($sections['b2c'] ?? []),
        'total_nil' => count($sections['nil'] ?? []),
        'total_hsn' => count($sections['hsn']),
        'total_invoices' => count($orders),
        'total_taxable' => array_sum(array_column($sections['b2b'] ?? [], 'taxable')) + array_sum(array_column($sections['b2c'] ?? [], 'taxable')),
        'total_gst' => array_sum(array_column($sections['b2b'] ?? [], 'gst')) + array_sum(array_column($sections['b2c'] ?? [], 'gst')),
    ];

    $pdo->prepare("UPDATE gst_returns SET summary_data = ? WHERE id = ?")->execute([json_encode($summary), $return_id]);

    return $return_id;
}

// ─── GSTR-3B GENERATION ─────────────────────────────────

function generateGSTR3B($pdo, $business_key, $period, $generated_by = 'system') {
    $fy = $period['financial_year'];
    $gp = $period['gst_period'];
    $month_start = sprintf('%04d-%02d-01', $period['year'], $period['month']);
    $month_end = date('Y-m-t', strtotime($month_start));

    $filing_cfg = getGSTFilingConfig($pdo, $business_key);
    $biz_state = intval($filing_cfg['state_code'] ?? 0);

    $version = 1;
    try {
        $stmt = $pdo->prepare("SELECT MAX(version) FROM gst_returns WHERE business_key=? AND return_type='gstr3b' AND financial_year=? AND gst_period=?");
        $stmt->execute([$business_key, $fy, $gp]);
        $max_v = intval($stmt->fetchColumn());
        $version = $max_v + 1;
    } catch (PDOException $e) {}

    $return_number = sprintf('%s-GSTR3B-%s-V%d', $fy, str_replace('-', '', $gp), $version);

    $ins = $pdo->prepare("INSERT INTO gst_returns (business_key, return_type, financial_year, gst_period, return_number, version, status, generation_date, generated_by) VALUES (?, 'gstr3b', ?, ?, ?, ?, 'draft', NOW(), ?)");
    $ins->execute([$business_key, $fy, $gp, $return_number, $version, $generated_by]);
    $return_id = $pdo->lastInsertId();

    // ── Table 4: Outward Supplies (from refill_orders, same source as GSTR-1) ──
    $stmt = $pdo->prepare("
        SELECT o.*, c.state_code as customer_state, c.registration_type as customer_reg_type,
               c.gst_number as customer_gst, c.name as customer_name
        FROM refill_orders o
        JOIN customers c ON o.customer_id = c.id
        WHERE o.business_name = ?
          AND DATE(o.order_date) >= ? AND DATE(o.order_date) <= ?
          AND (o.include_in_gst_return IS NULL OR o.include_in_gst_return = 1)
          AND o.gst_status NOT IN ('filed')
        ORDER BY o.order_date ASC
    ");
    $stmt->execute([$business_key, $month_start, $month_end]);
    $orders = $stmt->fetchAll();

    $item_ins = $pdo->prepare("INSERT INTO gst_return_items (gst_return_id, section, reference_type, reference_id, invoice_number, customer_gstin, customer_name, place_of_supply, hsn_code, taxable_value, gst_rate, cgst, sgst, igst, total_gst, total_value) VALUES (?, ?, 'refill_order', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $outward_by_rate = [];
    $outward_taxable = 0;
    $outward_gst = 0;

    foreach ($orders as $order) {
        $customer_state = intval($order['customer_state'] ?? 0);
        $is_inter_state = ($customer_state > 0 && $biz_state > 0 && $customer_state !== $biz_state);
        $place_of_supply = $customer_state > 0 ? $customer_state : $biz_state;

        $items_stmt = $pdo->prepare("
            SELECT oi.*, g.hsn_code as gas_hsn, p.hsn_code as product_hsn
            FROM refill_order_items oi
            LEFT JOIN gas_types g ON oi.gas_type_id = g.id
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.refill_order_id = ?
        ");
        $items_stmt->execute([$order['id']]);
        $items = $items_stmt->fetchAll();

        $order_taxable = 0; $order_gst = 0;

        foreach ($items as $item) {
            $hsn = $item['hsn_code'] ?? $item['gas_hsn'] ?? $item['product_hsn'] ?? '280440';
            $rate = floatval($item['gst_rate'] ?? 0);
            $taxable = floatval($item['taxable_amount'] ?? ($item['price_per_unit'] * $item['qty']));
            $gst_amt = floatval($item['gst_amount'] ?? 0);
            if ($rate > 0 && $gst_amt <= 0) {
                $calc = calculateGST($taxable, $rate, !$is_inter_state);
                $gst_amt = $calc['gst_amount'];
            }

            if ($rate <= 0) continue;

            $cgst = floatval($item['cgst'] ?? 0);
            $sgst = floatval($item['sgst'] ?? 0);
            $igst = floatval($item['igst'] ?? 0);

            if ($is_inter_state && $gst_amt > 0) {
                $igst = $gst_amt; $cgst = 0; $sgst = 0;
            } elseif (!$is_inter_state && $gst_amt > 0 && $cgst + $sgst <= 0) {
                $cgst = round($gst_amt / 2, 2);
                $sgst = $gst_amt - $cgst;
            }

            $total_gst = $gst_amt;
            $total_val = $taxable + $total_gst;

            $item_ins->execute([
                $return_id, 'outward_by_rate', $order['id'],
                $order['invoice_number'] ?? ('INV-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT)),
                $order['customer_gst'] ?? '', $order['customer_name'] ?? '',
                $place_of_supply, $hsn, $taxable, $rate,
                $cgst, $sgst, $igst, $total_gst, $total_val
            ]);

            if (!isset($outward_by_rate[$rate])) $outward_by_rate[$rate] = ['taxable' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            $outward_by_rate[$rate]['taxable'] += $taxable;
            $outward_by_rate[$rate]['gst'] += $total_gst;
            $outward_by_rate[$rate]['cgst'] += $cgst;
            $outward_by_rate[$rate]['sgst'] += $sgst;
            $outward_by_rate[$rate]['igst'] += $igst;

            $outward_taxable += $taxable;
            $outward_gst += $total_gst;
        }
    }

    // ── Table 5: Input GST / ITC (from vendor transactions directly) ──
    $itc_by_rate = [];
    $itc_total = 0;

    foreach (['vendor_refill_batches' => 'received_date', 'vendor_invoices' => 'invoice_date', 'cylinder_purchases' => 'purchase_date'] as $table => $date_col) {
        $gst_col = ($table === 'cylinder_purchases') ? '(COALESCE(cgst,0)+COALESCE(sgst,0)+COALESCE(igst,0))' : 'COALESCE(gst_amount,0)';
        try {
            $vs = $pdo->prepare("SELECT COALESCE(gst_rate,0) as gst_rate, COALESCE(taxable_amount,0) as taxable_amount, $gst_col as gst_amount, COALESCE(cgst,0) as cgst, COALESCE(sgst,0) as sgst, COALESCE(igst,0) as igst FROM $table WHERE DATE($date_col) >= ? AND DATE($date_col) <= ? AND COALESCE(gst_amount,0) > 0");
            $vs->execute([$month_start, $month_end]);
            while ($v = $vs->fetch()) {
                $rate = floatval($v['gst_rate']);
                $gst = floatval($v['gst_amount']);
                $taxable = floatval($v['taxable_amount']);
                if ($gst <= 0) continue;
                // Derive rate if missing from amounts
                if ($rate <= 0 && $taxable > 0) {
                    $rate = round($gst / $taxable * 100, 2);
                }
                if ($rate <= 0) $rate = 5; // fallback
                if (!isset($itc_by_rate[$rate])) $itc_by_rate[$rate] = ['taxable' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
                $itc_by_rate[$rate]['taxable'] += $taxable;
                $itc_by_rate[$rate]['gst'] += $gst;
                $itc_by_rate[$rate]['cgst'] += floatval($v['cgst']);
                $itc_by_rate[$rate]['sgst'] += floatval($v['sgst']);
                $itc_by_rate[$rate]['igst'] += floatval($v['igst']);
                $itc_total += $gst;
            }
        } catch (PDOException $e) {
            error_log("GSTR-3B input calc failed for $table: " . $e->getMessage());
        }
    }

    // Save ITC breakdown
    foreach ($itc_by_rate as $rate => $totals) {
        $item_ins->execute([$return_id, 'itc_by_rate', 0, '', '', '', 0, '', $totals['taxable'], $rate, $totals['cgst'], $totals['sgst'], $totals['igst'], $totals['gst'], $totals['taxable'] + $totals['gst']]);
    }

    // Carry forward ITC from previous settlement
    $itc_carry = 0;
    try {
        $stmt = $pdo->prepare("SELECT itc_closing FROM gst_settlements WHERE business_key=? AND settlement_month < ? ORDER BY settlement_month DESC LIMIT 1");
        $stmt->execute([$business_key, $month_start]);
        $itc_carry = floatval($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {}

    $total_itc = $itc_total + $itc_carry;
    $net_liability = max(0, $outward_gst - $total_itc);
    $carry_forward = max(0, $total_itc - $outward_gst);

    $summary = [
        'outward_taxable_supplies' => $outward_taxable,
        'outward_gst' => $outward_gst,
        'zero_rated_supplies' => 0,
        'exempt_supplies' => 0,
        'reverse_charge' => 0,
        'itc_eligible' => $itc_total,
        'itc_carry_forward_opening' => $itc_carry,
        'total_itc_available' => $total_itc,
        'output_tax' => $outward_gst,
        'net_gst_liability' => $net_liability,
        'carry_forward_itc' => $carry_forward,
        'interest' => 0,
        'late_fee' => 0,
        'total_outward_invoices' => count($orders),
        'total_input_entries' => $itc_total > 0 ? count($itc_by_rate) : 0,
        'rate_breakdown' => ['outward' => $outward_by_rate, 'itc' => $itc_by_rate],
    ];

    $pdo->prepare("UPDATE gst_returns SET summary_data = ? WHERE id = ?")->execute([json_encode($summary), $return_id]);

    return $return_id;
}

// ─── VALIDATION ENGINE ──────────────────────────────────

function validateGSTReturn($pdo, $return_id) {
    // Fetch return
    $stmt = $pdo->prepare("SELECT * FROM gst_returns WHERE id = ?");
    $stmt->execute([$return_id]);
    $return = $stmt->fetch();
    if (!$return) return ['total_errors' => 0, 'total_warnings' => 0, 'errors' => []];

    $errors = [];

    // Fetch return items
    $itemsStmt = $pdo->prepare("SELECT * FROM gst_return_items WHERE gst_return_id = ?");
    $itemsStmt->execute([$return_id]);
    $items = $itemsStmt->fetchAll();

    $seen_invoices = [];

    foreach ($items as $item) {
        if (in_array($item['section'], ['hsn', 'outward_by_rate', 'itc_by_rate'])) continue; // Skip aggregate rows for invoice-level checks

        $ref_type = $item['reference_type'];
        $ref_id = $item['reference_id'];
        $inv_no = $item['invoice_number'];
        $inv_key = $ref_type . '_' . $ref_id;

        // 1. Duplicate invoice check
        if (isset($seen_invoices[$inv_key])) {
            $err = "Duplicate invoice reference: $ref_type #$ref_id";
            $errors[] = ['type' => 'duplicate_invoice', 'msg' => $err, 'ref' => $ref_type, 'rid' => $ref_id, 'inv' => $inv_no, 'severity' => 'error'];

        }
        $seen_invoices[$inv_key] = true;

        // 2. Invalid GSTIN
        if (!empty($item['customer_gstin']) && !validateGSTIN($item['customer_gstin'])) {
            $err = "Invalid GSTIN: {$item['customer_gstin']}";
            $errors[] = ['type' => 'invalid_gstin', 'msg' => $err, 'ref' => $ref_type, 'rid' => $ref_id, 'inv' => $inv_no, 'severity' => 'error'];

        }

        // 3. Missing HSN
        if (empty($item['hsn_code'])) {
            $err = "Missing HSN code for invoice $inv_no";
            $errors[] = ['type' => 'missing_hsn', 'msg' => $err, 'ref' => $ref_type, 'rid' => $ref_id, 'inv' => $inv_no, 'severity' => 'error'];

        }

        // 4. Missing Place of Supply
        if (empty($item['place_of_supply']) || intval($item['place_of_supply']) === 0) {
            $err = "Missing Place of Supply for invoice $inv_no";
            $errors[] = ['type' => 'missing_pos', 'msg' => $err, 'ref' => $ref_type, 'rid' => $ref_id, 'inv' => $inv_no, 'severity' => 'warning'];

        }

        // 5. Missing GST Rate
        if (floatval($item['gst_rate']) <= 0 && floatval($item['taxable_value']) > 0) {
            $err = "Missing or zero GST rate for taxable invoice $inv_no";
            $errors[] = ['type' => 'missing_gst_rate', 'msg' => $err, 'ref' => $ref_type, 'rid' => $ref_id, 'inv' => $inv_no, 'severity' => 'warning'];

        }

        // 6. Incorrect CGST/SGST split
        $gst = floatval($item['total_gst']);
        $cgst = floatval($item['cgst']);
        $sgst = floatval($item['sgst']);
        $igst = floatval($item['igst']);
        if ($gst > 0 && abs(($cgst + $sgst + $igst) - $gst) > 0.01) {
            $err = "CGST+SGST+IGST ({$cgst}+{$sgst}+{$igst}) != Total GST ($gst) for invoice $inv_no";
            $errors[] = ['type' => 'gst_split_mismatch', 'msg' => $err, 'ref' => $ref_type, 'rid' => $ref_id, 'inv' => $inv_no, 'severity' => 'error'];

        }

        // 7. Taxable Value vs Total mismatch
        $taxable = floatval($item['taxable_value']);
        $total_val = floatval($item['total_value']);
        if ($taxable > 0 && $total_val > 0 && abs($total_val - ($taxable + $gst)) > 0.01) {
            $err = "Total value ($total_val) != Taxable ($taxable) + GST ($gst) for invoice $inv_no";
            $errors[] = ['type' => 'value_mismatch', 'msg' => $err, 'ref' => $ref_type, 'rid' => $ref_id, 'inv' => $inv_no, 'severity' => 'error'];

        }
    }

    // 8. Vendor purchase sync check — flag vendor transactions not in gst_ledger
    if ($return['return_type'] === 'gstr3b' || $return['return_type'] === 'gstr1') {
        try {
            $parts = explode('-', $return['gst_period']);
            if (count($parts) === 2) {
                $vyear = intval($parts[1]);
                $vmonth = intval($parts[0]);
                $m_start = sprintf('%04d-%02d-01', $vyear, $vmonth);
                $m_end = date('Y-m-t', strtotime($m_start));

                // Batches
                $batch_s = $pdo->prepare("SELECT id, invoice_number, total_cost, gst_amount FROM vendor_refill_batches WHERE DATE(received_date)>=? AND DATE(received_date)<=? AND gst_amount > 0");
                $batch_s->execute([$m_start, $m_end]);
                while ($b = $batch_s->fetch()) {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE reference_type='vendor_refill_batch' AND reference_id=?");
                    $chk->execute([$b['id']]);
                    if (intval($chk->fetchColumn()) === 0) {
                        $err = "Vendor batch #{$b['id']} ({$b['invoice_number']}) has GST Rs{$b['gst_amount']} but not synced to GST ledger";
                        $errors[] = ['type' => 'missing_input_gst', 'msg' => $err, 'ref' => 'vendor_refill_batch', 'rid' => $b['id'], 'inv' => $b['invoice_number'] ?? '', 'severity' => 'warning'];

                    }
                }

                // Vendor invoices
                $vi_s = $pdo->prepare("SELECT id, invoice_number, gst_amount FROM vendor_invoices WHERE DATE(invoice_date)>=? AND DATE(invoice_date)<=? AND gst_amount > 0");
                $vi_s->execute([$m_start, $m_end]);
                while ($vi = $vi_s->fetch()) {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE reference_type='vendor_invoice' AND reference_id=?");
                    $chk->execute([$vi['id']]);
                    if (intval($chk->fetchColumn()) === 0) {
                        $err = "Vendor invoice #{$vi['id']} ({$vi['invoice_number']}) has GST Rs{$vi['gst_amount']} but not synced to GST ledger";
                        $errors[] = ['type' => 'missing_input_gst', 'msg' => $err, 'ref' => 'vendor_invoice', 'rid' => $vi['id'], 'inv' => $vi['invoice_number'] ?? '', 'severity' => 'warning'];

                    }
                }

                // Cylinder purchases (supplier)
                $cp_s = $pdo->prepare("SELECT id, invoice_number, grand_total, (COALESCE(cgst,0) + COALESCE(sgst,0) + COALESCE(igst,0)) AS gst_amount FROM cylinder_purchases WHERE DATE(purchase_date)>=? AND DATE(purchase_date)<=? HAVING gst_amount > 0");
                $cp_s->execute([$m_start, $m_end]);
                while ($cp = $cp_s->fetch()) {
                    $ga = floatval($cp['gst_amount'] ?? 0);
                    if ($ga <= 0) continue;
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE reference_type IN ('cylinder_purchase','product_purchase') AND reference_id=?");
                    $chk->execute([$cp['id']]);
                    if (intval($chk->fetchColumn()) === 0) {
                        $err = "Cylinder purchase #{$cp['id']} ({$cp['invoice_number']}) has GST Rs{$ga} but not synced to GST ledger";
                        $errors[] = ['type' => 'missing_input_gst', 'msg' => $err, 'ref' => 'cylinder_purchase', 'rid' => $cp['id'], 'inv' => $cp['invoice_number'] ?? '', 'severity' => 'warning'];

                    }
                }
            }
        } catch (PDOException $e) {
            error_log("GST validation vendor check error: " . $e->getMessage());
        }
    }

    // Update return status based on validation
    $total_errors = count(array_filter($errors, fn($e) => $e['severity'] === 'error'));
    $total_warnings = count(array_filter($errors, fn($e) => $e['severity'] === 'warning'));

    $new_status = $total_errors === 0 ? 'validated' : 'draft';
    $pdo->prepare("UPDATE gst_returns SET status = ?, validation_results = ? WHERE id = ?")
        ->execute([$new_status, json_encode(['total_errors' => $total_errors, 'total_warnings' => $total_warnings, 'errors' => $errors]), $return_id]);

    return ['total_errors' => $total_errors, 'total_warnings' => $total_warnings, 'errors' => $errors];
}

// ─── GST JSON EXPORT ────────────────────────────────────

function getAvailableSections($return_type) {
    $sections = [
        'gstr1' => ['b2b', 'b2cl', 'b2cs', 'cdnr', 'hsn', 'nil', 'exempt', 'doc_issue'],
        'gstr3b' => ['summary'],
    ];
    return $sections[$return_type] ?? [];
}

function getReturnSections($pdo, $return_id) {
    $stmt = $pdo->prepare("SELECT DISTINCT section FROM gst_return_items WHERE gst_return_id = ? ORDER BY FIELD(section, 'b2b','b2cl','b2cs','cdnr','hsn','nil','exempt','doc_issue')");
    $stmt->execute([$return_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getReturnItemsBySection($pdo, $return_id, $section) {
    $stmt = $pdo->prepare("SELECT * FROM gst_return_items WHERE gst_return_id = ? AND section = ? ORDER BY id ASC");
    $stmt->execute([$return_id, $section]);
    return $stmt->fetchAll();
}

function exportGSTJSON($pdo, $return_id) {
    // Delegate to the official GSTN-compliant export engine
    require_once __DIR__ . '/gst/json/export.php';
    return gstnExport($pdo, $return_id);
}

function buildGSTR1JSON($gstin, $fp, $items) {
    $b2b = []; $b2cs = []; $nil_inv = [];
    $hsn_map = []; $doc_issue = [];
    $ctin_map = []; // Group B2B by GSTIN

    $total_turnover = 0;

    foreach ($items as $item) {
        $section = $item['section'];
        $taxable = floatval($item['taxable_value']);
        $rate = floatval($item['gst_rate']);
        $gst = floatval($item['total_gst']);
        $cgst = floatval($item['cgst']);
        $sgst = floatval($item['sgst']);
        $igst = floatval($item['igst']);
        $val = floatval($item['total_value']);

        if ($section === 'b2b') {
            $ctin = $item['customer_gstin'] ?: 'URP';
            $pos = intval($item['place_of_supply']) ?: 0;
            if (!isset($ctin_map[$ctin])) $ctin_map[$ctin] = ['ctin' => $ctin, 'inv' => []];
            $inv_entry = [
                'inum' => $item['invoice_number'] ?? ('INV-' . $item['reference_id']),
                'idt' => date('d-m-Y'),
                'val' => $val,
                'pos' => $pos,
                'rchg' => 'N',
                'itms' => [[
                    'num' => 1,
                    'itm_det' => [
                        'txval' => $taxable,
                        'rt' => $rate,
                        'iamt' => $igst,
                        'camt' => $cgst,
                        'samt' => $sgst,
                        'csamt' => 0,
                    ]
                ]]
            ];
            $ctin_map[$ctin]['inv'][] = $inv_entry;
            $total_turnover += $val;
        } elseif ($section === 'b2c') {
            $b2cs[] = [
                'sply_ty' => 'INTRA',
                'typ' => 'OE',
                'etin' => '',
                'pos' => intval($item['place_of_supply']) ?: 0,
                'inv' => [[
                    'inum' => $item['invoice_number'] ?? ('INV-' . $item['reference_id']),
                    'idt' => date('d-m-Y'),
                    'val' => $val,
                ]],
            ];
            $total_turnover += $val;
        } elseif ($section === 'nil') {
            $nil_inv[] = [
                'inum' => $item['invoice_number'] ?? ('INV-' . $item['reference_id']),
                'idt' => date('d-m-Y'),
                'val' => $val,
            ];
            $total_turnover += $val;
        } elseif ($section === 'hsn') {
            $hsn_key = $item['hsn_code'] . '_' . $rate;
            if (!isset($hsn_map[$hsn_key])) {
                $hsn_map[$hsn_key] = [
                    'hsn_sc' => $item['hsn_code'] ?: '280440',
                    'desc' => '',
                    'uqc' => 'NOS',
                    'qty' => 0,
                    'val' => 0,
                    'txval' => 0,
                    'iamt' => 0,
                    'camt' => 0,
                    'samt' => 0,
                    'csamt' => 0,
                ];
            }
            $hsn_map[$hsn_key]['qty'] += 1; // rough count
            $hsn_map[$hsn_key]['txval'] += $taxable;
            $hsn_map[$hsn_key]['val'] += $val;
            $hsn_map[$hsn_key]['iamt'] += $igst;
            $hsn_map[$hsn_key]['camt'] += $cgst;
            $hsn_map[$hsn_key]['samt'] += $sgst;
        }
    }

    // Build B2B array from grouped map
    foreach ($ctin_map as $entry) {
        $b2b[] = $entry;
    }

    $json = [
        'gstin' => $gstin,
        'fp' => $fp,
        'gt' => round($total_turnover, 2),
        'cur_gt' => round($total_turnover, 2),
    ];

    if (!empty($b2b)) $json['b2b'] = $b2b;
    if (!empty($b2cs)) $json['b2cs'] = $b2cs;
    if (!empty($nil_inv)) {
        $json['nil'] = [
            'inv' => $nil_inv,
            'nil_amt' => round(array_sum(array_column($nil_inv, 'val')), 2),
            'expt_amt' => 0,
            'ngsup_amt' => 0,
        ];
    }
    if (!empty($hsn_map)) $json['hsn'] = array_values($hsn_map);
    $json['doc_issue'] = [
        'doc_num' => count($items),
        'doc_type' => 'Invoices',
    ];

    return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function buildGSTR3BJSON($gstin, $fp, $summary_json) {
    $summary = $summary_json ? json_decode($summary_json, true) : [];

    $json = [
        'gstin' => $gstin,
        'fp' => $fp,
        'sup_details' => [
            'osup_det' => [
                'txval' => round(floatval($summary['outward_taxable_supplies'] ?? 0), 2),
                'iamt' => 0,
                'camt' => 0,
                'samt' => 0,
            ],
            'osup_zero' => [
                'txval' => round(floatval($summary['zero_rated_supplies'] ?? 0), 2),
                'iamt' => 0,
                'camt' => 0,
                'samt' => 0,
            ],
            'osup_nil_exmp' => [
                'txval' => round(floatval($summary['nil_supplies'] ?? 0) + floatval($summary['exempt_supplies'] ?? 0), 2),
                'iamt' => 0,
                'camt' => 0,
                'samt' => 0,
            ],
            'isup_rev' => [
                'txval' => round(floatval($summary['reverse_charge'] ?? 0), 2),
                'iamt' => 0,
                'camt' => 0,
                'samt' => 0,
            ],
            'osup_ng' => [
                'txval' => 0,
                'iamt' => 0,
                'camt' => 0,
                'samt' => 0,
            ],
        ],
        'itc_elg' => [
            'itc_avl' => [
                'iamt' => 0,
                'camt' => round(floatval($summary['itc_eligible'] ?? 0) / 2, 2),
                'samt' => round(floatval($summary['itc_eligible'] ?? 0) / 2, 2),
                'csamt' => 0,
            ],
            'itc_rev' => ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0],
            'itc_net' => [
                'iamt' => 0,
                'camt' => round(floatval($summary['itc_eligible'] ?? 0) / 2, 2),
                'samt' => round(floatval($summary['itc_eligible'] ?? 0) / 2, 2),
                'csamt' => 0,
            ],
            'itc_inelg' => ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0],
        ],
        'intr_ltfee' => [
            'intr_details' => round(floatval($summary['interest'] ?? 0), 2),
            'lt_fee_details' => round(floatval($summary['late_fee'] ?? 0), 2),
        ],
    ];

    return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// ─── FILING LOCK ────────────────────────────────────────

function lockGSTPeriod($pdo, $business_key, $period, $user) {
    $month_start = sprintf('%04d-%02d-01', $period['year'], $period['month']);

    $stmt = $pdo->prepare("INSERT INTO gst_settlements (business_key, settlement_month, is_locked, locked_at, locked_by) VALUES (?, ?, 1, NOW(), ?) ON DUPLICATE KEY UPDATE is_locked=1, locked_at=NOW(), locked_by=?");
    $stmt->execute([$business_key, $month_start, $user, $user]);

    // Mark all orders in period as filed
    $month_end = date('Y-m-t', strtotime($month_start));
    $pdo->prepare("UPDATE refill_orders SET gst_status='filed' WHERE business_name=? AND DATE(order_date) >= ? AND DATE(order_date) <= ? AND (gst_status IS NULL OR gst_status != 'filed')")
        ->execute([$business_key, $month_start, $month_end]);

    // Mark all relevant returns as filed
    $fy = $period['financial_year'];
    $gp = $period['gst_period'];
    $pdo->prepare("UPDATE gst_returns SET status='filed', filed_date=NOW(), filed_by=? WHERE business_key=? AND financial_year=? AND gst_period=? AND status NOT IN ('filed')")
        ->execute([$user, $business_key, $fy, $gp]);

    return true;
}

function unlockGSTPeriod($pdo, $business_key, $period, $user, $reason = '') {
    $month_start = sprintf('%04d-%02d-01', $period['year'], $period['month']);

    // Log amendment
    $pdo->prepare("INSERT INTO audit_log (module, reference_type, reference_id, field_name, old_value, new_value, reason, amended_by) VALUES ('gst', 'period_lock', 0, 'is_locked', '1', '0', ?, ?)")
        ->execute([$reason, $user]);

    $stmt = $pdo->prepare("UPDATE gst_settlements SET is_locked=0, unlocked_at=NOW(), unlocked_by=? WHERE business_key=? AND settlement_month=?");
    $stmt->execute([$user, $business_key, $month_start]);

    // Mark orders as amended
    $month_end = date('Y-m-t', strtotime($month_start));
    $pdo->prepare("UPDATE refill_orders SET gst_status='amended' WHERE business_name=? AND DATE(order_date) >= ? AND DATE(order_date) <= ? AND gst_status='filed'")
        ->execute([$business_key, $month_start, $month_end]);

    // Mark returns as amended + create new draft version
    $fy = $period['financial_year'];
    $gp = $period['gst_period'];
    $returns = $pdo->prepare("SELECT * FROM gst_returns WHERE business_key=? AND financial_year=? AND gst_period=? AND status='filed'");
    $returns->execute([$business_key, $fy, $gp]);
    while ($ret = $returns->fetch()) {
        $pdo->prepare("UPDATE gst_returns SET status='amended' WHERE id=?")->execute([$ret['id']]);
    }

    return true;
}

function isPeriodLocked($pdo, $business_key, $period) {
    try {
        $month_start = sprintf('%04d-%02d-01', $period['year'], $period['month']);
        $stmt = $pdo->prepare("SELECT is_locked FROM gst_settlements WHERE business_key=? AND settlement_month=?");
        $stmt->execute([$business_key, $month_start]);
        return intval($stmt->fetchColumn() ?: 0) === 1;
    } catch (PDOException $e) {
        return false;
    }
}

// ─── GST CALCULATION ──────────────────────────────────────

function calculateGST($amount, $rate, $is_intra_state = true) {
    $rate = floatval($rate);
    $amount = floatval($amount);
    if ($rate <= 0 || $amount <= 0) {
        return ['taxable' => 0, 'gst_amount' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'total' => 0];
    }
    $gst_amount = round($amount * $rate / 100, 2);
    if ($is_intra_state) {
        $half = round($gst_amount / 2, 2);
        $cgst = $half;
        $sgst = $gst_amount - $half;
        $igst = 0.00;
    } else {
        $cgst = 0.00;
        $sgst = 0.00;
        $igst = $gst_amount;
    }
    return [
        'taxable' => $amount,
        'gst_amount' => $gst_amount,
        'cgst' => $cgst,
        'sgst' => $sgst,
        'igst' => $igst,
        'total' => $amount + $gst_amount,
    ];
}

// ─── RECORD INPUT GST (vendor purchase) ──────────────────

function recordInputGST($pdo, $data) {
    $defaults = [
        'entity_type' => 'vendor',
        'entity_id' => 0,
        'gst_rate' => 0,
        'taxable_amount' => 0,
        'gst_amount' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'igst' => 0,
        'reference_type' => 'vendor_refill_batch',
        'reference_id' => 0,
        'transaction_date' => null,
    ];
    $data = array_merge($defaults, $data);
    if ($data['gst_rate'] <= 0 || $data['gst_amount'] <= 0) return false;
    if (!$data['transaction_date']) $data['transaction_date'] = date('Y-m-d');

    $allowed_types = ['vendor', 'customer', 'supplier', 'expense'];
    $entity_type = in_array($data['entity_type'], $allowed_types) ? $data['entity_type'] : 'vendor';

    $stmt = $pdo->prepare("INSERT INTO gst_ledger (entity_type, entity_id, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst, input_output_type, reference_type, reference_id, transaction_date, gst_applicable) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'input', ?, ?, ?, 1)");
    $stmt->execute([
        $entity_type,
        $data['entity_id'],
        $data['gst_rate'],
        $data['taxable_amount'],
        $data['gst_amount'],
        $data['cgst'],
        $data['sgst'],
        $data['igst'],
        $data['reference_type'],
        $data['reference_id'],
        $data['transaction_date'],
    ]);
    return true;
}

// ─── RECORD OUTPUT GST (customer sale) ───────────────────

function recordOutputGST($pdo, $data) {
    $defaults = [
        'entity_id' => 0,
        'gst_rate' => 0,
        'taxable_amount' => 0,
        'gst_amount' => 0,
        'cgst' => 0,
        'sgst' => 0,
        'igst' => 0,
        'reference_type' => 'refill_order',
        'reference_id' => 0,
        'transaction_date' => null,
    ];
    $data = array_merge($defaults, $data);
    if ($data['gst_rate'] <= 0 || $data['gst_amount'] <= 0) return false;
    if (!$data['transaction_date']) $data['transaction_date'] = date('Y-m-d');

    $stmt = $pdo->prepare("INSERT INTO gst_ledger (entity_type, entity_id, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst, input_output_type, reference_type, reference_id, transaction_date, gst_applicable) VALUES ('customer', ?, ?, ?, ?, ?, ?, ?, 'output', ?, ?, ?, 1)");
    $stmt->execute([
        $data['entity_id'],
        $data['gst_rate'],
        $data['taxable_amount'],
        $data['gst_amount'],
        $data['cgst'],
        $data['sgst'],
        $data['igst'],
        $data['reference_type'],
        $data['reference_id'],
        $data['transaction_date'],
    ]);
    return true;
}

// ─── GST SUMMARY ──────────────────────────────────────────

function getGSTSummary($pdo, $filters = []) {
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['date_from'])) { $where[] = 'transaction_date >= ?'; $params[] = $filters['date_from']; }
    if (!empty($filters['date_to'])) { $where[] = 'transaction_date <= ?'; $params[] = $filters['date_to']; }
    if (!empty($filters['gst_rate'])) { $where[] = 'gst_rate = ?'; $params[] = $filters['gst_rate']; }
    $wh = implode(' AND ', $where);

    $input = 0; $output = 0; $input_by_rate = []; $output_by_rate = [];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(gst_amount),0) FROM gst_ledger WHERE input_output_type='input' AND $wh");
    $stmt->execute($params);
    $input = floatval($stmt->fetchColumn());

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(gst_amount),0) FROM gst_ledger WHERE input_output_type='output' AND $wh");
    $stmt->execute($params);
    $output = floatval($stmt->fetchColumn());

    $stmt = $pdo->prepare("SELECT gst_rate, COALESCE(SUM(gst_amount),0) as total FROM gst_ledger WHERE input_output_type='input' AND $wh GROUP BY gst_rate ORDER BY gst_rate");
    $stmt->execute($params);
    while ($r = $stmt->fetch()) $input_by_rate[floatval($r['gst_rate'])] = floatval($r['total']);

    $stmt = $pdo->prepare("SELECT gst_rate, COALESCE(SUM(gst_amount),0) as total FROM gst_ledger WHERE input_output_type='output' AND $wh GROUP BY gst_rate ORDER BY gst_rate");
    $stmt->execute($params);
    while ($r = $stmt->fetch()) $output_by_rate[floatval($r['gst_rate'])] = floatval($r['total']);

    // Get carry-forward ITC from latest settlement
    $itc_cf = 0;
    try {
        $stmt = $pdo->query("SELECT itc_closing FROM gst_settlements ORDER BY settlement_month DESC LIMIT 1");
        $itc_cf = floatval($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {}

    $total_itc = $input + $itc_cf;
    $net_payable = max(0, $output - $total_itc);
    $itc_balance = max(0, $total_itc - $output);

    return [
        'total_input' => $input,
        'total_output' => $output,
        'net_payable' => $net_payable,
        'itc_balance' => $itc_balance,
        'itc_carry_forward' => $itc_balance,
        'input_by_rate' => $input_by_rate,
        'output_by_rate' => $output_by_rate,
    ];
}

// ─── GST LEDGER ───────────────────────────────────────────

function getGSTLedger($pdo, $filters = []) {
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['input_output_type'])) { $where[] = 'input_output_type = ?'; $params[] = $filters['input_output_type']; }
    if (!empty($filters['entity_type'])) { $where[] = 'entity_type = ?'; $params[] = $filters['entity_type']; }
    if (!empty($filters['entity_id'])) { $where[] = 'entity_id = ?'; $params[] = $filters['entity_id']; }
    if (!empty($filters['gst_rate'])) { $where[] = 'gst_rate = ?'; $params[] = $filters['gst_rate']; }
    if (!empty($filters['date_from'])) { $where[] = 'transaction_date >= ?'; $params[] = $filters['date_from']; }
    if (!empty($filters['date_to'])) { $where[] = 'transaction_date <= ?'; $params[] = $filters['date_to']; }
    if (isset($filters['gst_applicable'])) { $where[] = 'gst_applicable = ?'; $params[] = $filters['gst_applicable']; }
    $wh = implode(' AND ', $where);

    $page = max(1, intval($filters['page'] ?? 1));
    $limit = max(10, min(200, intval($filters['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM gst_ledger WHERE $wh");
    $count_stmt->execute($params);
    $total = intval($count_stmt->fetchColumn());

    $data_stmt = $pdo->prepare("SELECT * FROM gst_ledger WHERE $wh ORDER BY transaction_date DESC, id DESC LIMIT $limit OFFSET $offset");
    $data_stmt->execute($params);
    $rows = $data_stmt->fetchAll();

    // Enrich with entity names
    $entity_cache = [];
    foreach ($rows as &$row) {
        $cache_key = $row['entity_type'] . '_' . $row['entity_id'];
        if (!isset($entity_cache[$cache_key])) {
            try {
                if ($row['entity_type'] === 'customer') {
                    $s = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
                    $s->execute([$row['entity_id']]);
                    $entity_cache[$cache_key] = $s->fetchColumn() ?: 'Unknown';
                } elseif ($row['entity_type'] === 'supplier') {
                    $s = $pdo->prepare("SELECT company_name FROM cylinder_suppliers WHERE id = ?");
                    $s->execute([$row['entity_id']]);
                    $entity_cache[$cache_key] = $s->fetchColumn() ?: 'Supplier #' . $row['entity_id'];
                } else {
                    $s = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                    $s->execute([$row['entity_id']]);
                    $entity_cache[$cache_key] = $s->fetchColumn() ?: 'Unknown';
                }
            } catch (PDOException $e) {
                $entity_cache[$cache_key] = 'Unknown';
            }
        }
        $row['entity_name'] = $entity_cache[$cache_key];
    }

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit)];
}

// ─── MONTHLY GST SUMMARY ─────────────────────────────────

function getMonthlyGSTSummary($pdo, $months = 12) {
    $months = max(1, min(60, intval($months)));
    $summary = [];

    // Input GST by month
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month, COALESCE(SUM(gst_amount),0) as total FROM gst_ledger WHERE input_output_type='input' AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL $months MONTH) GROUP BY DATE_FORMAT(transaction_date, '%Y-%m-01') ORDER BY month ASC");
    $stmt->execute();
    $input_map = [];
    while ($r = $stmt->fetch()) $input_map[$r['month']] = floatval($r['total']);

    // Output GST by month
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month, COALESCE(SUM(gst_amount),0) as total FROM gst_ledger WHERE input_output_type='output' AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL $months MONTH) GROUP BY DATE_FORMAT(transaction_date, '%Y-%m-01') ORDER BY month ASC");
    $stmt->execute();
    $output_map = [];
    while ($r = $stmt->fetch()) $output_map[$r['month']] = floatval($r['total']);

    // Key by YYYY-MM
    $all_months = array_unique(array_merge(array_keys($input_map), array_keys($output_map)));
    sort($all_months);

    foreach ($all_months as $m) {
        $inp = $input_map[$m] ?? 0;
        $out = $output_map[$m] ?? 0;
        $summary[] = [
            'month' => $m,
            'month_label' => date('M Y', strtotime($m)),
            'input_gst' => $inp,
            'output_gst' => $out,
            'net_payable' => max(0, $out - $inp),
            'itc' => max(0, $inp - $out),
        ];
    }

    return $summary;
}

// ─── GST PERIOD CALCULATION (same data source as GSTR-3B) ──

function calculateGSTForPeriod($pdo, $business_key, $month_start, $month_end) {
    $filing_cfg = getGSTFilingConfig($pdo, $business_key);
    $biz_state = intval($filing_cfg['state_code'] ?? 0);

    // === Outward supplies from orders (same as GSTR-3B) ===
    $outward_by_rate = [];
    $outward_taxable = 0;
    $outward_gst = 0;
    $total_invoices = 0;

    try {
        $stmt = $pdo->prepare("
            SELECT oi.gst_rate, oi.taxable_amount, oi.gst_amount, oi.cgst, oi.sgst, oi.igst,
                   o.customer_id, o.order_date, c.state_code as customer_state
            FROM refill_order_items oi
            JOIN refill_orders o ON oi.refill_order_id = o.id
            JOIN customers c ON o.customer_id = c.id
            WHERE o.business_name = ?
              AND DATE(o.order_date) >= ? AND DATE(o.order_date) <= ?
              AND (o.include_in_gst_return IS NULL OR o.include_in_gst_return = 1)
              AND o.gst_status NOT IN ('filed')
        ");
        $stmt->execute([$business_key, $month_start, $month_end]);
        $items = $stmt->fetchAll();

        $seen_orders = [];
        foreach ($items as $item) {
            $rate = floatval($item['gst_rate'] ?? 0);
            $taxable = floatval($item['taxable_amount'] ?? 0);
            $gst_amt = floatval($item['gst_amount'] ?? 0);
            if ($rate <= 0) continue;

            $customer_state = intval($item['customer_state'] ?? 0);
            $is_inter = ($customer_state > 0 && $biz_state > 0 && $customer_state !== $biz_state);

            if (!isset($outward_by_rate[$rate])) $outward_by_rate[$rate] = ['taxable' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            $outward_by_rate[$rate]['taxable'] += $taxable;
            $outward_by_rate[$rate]['gst'] += $gst_amt;
            $outward_by_rate[$rate]['cgst'] += floatval($item['cgst'] ?? 0);
            $outward_by_rate[$rate]['sgst'] += floatval($item['sgst'] ?? 0);
            $outward_by_rate[$rate]['igst'] += $gst_amt;

            $outward_taxable += $taxable;
            $outward_gst += $gst_amt;
            $seen_orders[$item['customer_id']] = true;
        }
        $total_invoices = count($seen_orders);
    } catch (PDOException $e) {}

    // === Input GST from vendor transactions ===
    $itc_by_rate = [];
    $itc_total = 0;

    foreach (['vendor_refill_batches' => 'received_date', 'vendor_invoices' => 'invoice_date', 'cylinder_purchases' => 'purchase_date'] as $table => $date_col) {
        $gst_col = ($table === 'cylinder_purchases') ? '(COALESCE(cgst,0)+COALESCE(sgst,0)+COALESCE(igst,0))' : 'COALESCE(gst_amount,0)';
        try {
            $vs = $pdo->prepare("SELECT COALESCE(gst_rate,0) as gst_rate, COALESCE(taxable_amount,0) as taxable_amount, $gst_col as gst_amount, COALESCE(cgst,0) as cgst, COALESCE(sgst,0) as sgst, COALESCE(igst,0) as igst FROM $table WHERE DATE($date_col) >= ? AND DATE($date_col) <= ? AND COALESCE(gst_amount,0) > 0");
            $vs->execute([$month_start, $month_end]);
            while ($v = $vs->fetch()) {
                $rate = floatval($v['gst_rate']);
                $gst = floatval($v['gst_amount']);
                $taxable = floatval($v['taxable_amount']);
                if ($gst <= 0) continue;
                // Derive rate if missing from amounts
                if ($rate <= 0 && $taxable > 0) {
                    $rate = round($gst / $taxable * 100, 2);
                }
                if ($rate <= 0) $rate = 5; // fallback
                if (!isset($itc_by_rate[$rate])) $itc_by_rate[$rate] = ['taxable' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
                $itc_by_rate[$rate]['taxable'] += $taxable;
                $itc_by_rate[$rate]['gst'] += $gst;
                $itc_by_rate[$rate]['cgst'] += floatval($v['cgst']);
                $itc_by_rate[$rate]['sgst'] += floatval($v['sgst']);
                $itc_by_rate[$rate]['igst'] += floatval($v['igst']);
                $itc_total += $gst;
            }
        } catch (PDOException $e) {
            error_log("GST input calc failed for $table: " . $e->getMessage());
        }
    }

    // ITC carry forward from previous settlement
    $itc_carry = 0;
    try {
        $stmt = $pdo->prepare("SELECT itc_closing FROM gst_settlements WHERE business_key=? AND settlement_month < ? ORDER BY settlement_month DESC LIMIT 1");
        $stmt->execute([$business_key, $month_start]);
        $itc_carry = floatval($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {}

    $total_itc = $itc_total + $itc_carry;
    $net_payable = max(0, $outward_gst - $total_itc);
    $itc_closing = max(0, $total_itc - $outward_gst);

    return [
        'outward_taxable' => $outward_taxable,
        'outward_gst' => $outward_gst,
        'outward_by_rate' => $outward_by_rate,
        'total_invoices' => $total_invoices,
        'itc_total' => $itc_total,
        'itc_by_rate' => $itc_by_rate,
        'itc_carry' => $itc_carry,
        'total_itc' => $total_itc,
        'net_payable' => $net_payable,
        'itc_closing' => $itc_closing,
    ];
}

// ─── GST SETTLEMENT ──────────────────────────────────────

function createGSTSettlement($pdo, $month, $created_by = 'system', $business_key = null) {
    if (!$business_key) {
        $brand = getDefaultBusiness();
        $business_key = $brand['business_key'] ?? getBrandConfig()['business_key'];
    }
    $month_start = date('Y-m-01', strtotime($month));
    $month_end = date('Y-m-t', strtotime($month));

    // Use the same calculation engine as GSTR-3B
    $calc = calculateGSTForPeriod($pdo, $business_key, $month_start, $month_end);

    // Upsert settlement
    $stmt = $pdo->prepare("INSERT INTO gst_settlements (business_key, settlement_month, total_input, total_output, net_payable, itc_opening, itc_closing, payment_status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?) ON DUPLICATE KEY UPDATE total_input = VALUES(total_input), total_output = VALUES(total_output), net_payable = VALUES(net_payable), itc_opening = VALUES(itc_opening), itc_closing = VALUES(itc_closing)");
    $stmt->execute([$business_key, $month_start, $calc['itc_total'], $calc['outward_gst'], $calc['net_payable'], $calc['itc_carry'], $calc['itc_closing'], $created_by]);

    return [
        'month' => $month_start,
        'total_input' => $calc['itc_total'],
        'total_output' => $calc['outward_gst'],
        'net_payable' => $calc['net_payable'],
        'itc_opening' => $calc['itc_carry'],
        'itc_closing' => $calc['itc_closing'],
    ];
}

function getGSTSettlements($pdo, $filters = []) {
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['year'])) { $where[] = 'YEAR(settlement_month) = ?'; $params[] = $filters['year']; }
    if (!empty($filters['status'])) { $where[] = 'payment_status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['business_key'])) { $where[] = 'business_key = ?'; $params[] = $filters['business_key']; }
    $wh = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT * FROM gst_settlements WHERE $wh ORDER BY settlement_month DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function updateGSTSettlementPayment($pdo, $id, $status, $method, $amount, $date, $remarks = '', $payment_reference = '') {
    $stmt = $pdo->prepare("UPDATE gst_settlements SET payment_status = ?, payment_method = ?, payment_reference = ?, payment_amount = ?, payment_date = ?, remarks = CONCAT(IFNULL(remarks,''), ?) WHERE id = ?");
    $stmt->execute([$status, $method, $payment_reference ?: null, floatval($amount), $date, $remarks ? "\n$remarks" : '', intval($id)]);
}

// ─── TOTALS BY ENTITY ────────────────────────────────────

function getVendorGSTTotals($pdo, $vendor_id = null, $date_from = null, $date_to = null) {
    $where = ["input_output_type='input'"];
    $params = [];
    if ($vendor_id) { $where[] = 'entity_id = ?'; $params[] = $vendor_id; }
    if ($date_from) { $where[] = 'transaction_date >= ?'; $params[] = $date_from; }
    if ($date_to) { $where[] = 'transaction_date <= ?'; $params[] = $date_to; }
    $wh = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT entity_id, gst_rate, COALESCE(SUM(taxable_amount),0) as taxable, COALESCE(SUM(gst_amount),0) as gst, COALESCE(SUM(cgst),0) as cgst, COALESCE(SUM(sgst),0) as sgst, COUNT(*) as txns FROM gst_ledger WHERE $wh GROUP BY entity_id, gst_rate ORDER BY entity_id");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getCustomerGSTTotals($pdo, $customer_id = null, $date_from = null, $date_to = null) {
    $where = ["input_output_type='output'"];
    $params = [];
    if ($customer_id) { $where[] = 'entity_id = ?'; $params[] = $customer_id; }
    if ($date_from) { $where[] = 'transaction_date >= ?'; $params[] = $date_from; }
    if ($date_to) { $where[] = 'transaction_date <= ?'; $params[] = $date_to; }
    $wh = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT entity_id, gst_rate, COALESCE(SUM(taxable_amount),0) as taxable, COALESCE(SUM(gst_amount),0) as gst, COALESCE(SUM(cgst),0) as cgst, COALESCE(SUM(sgst),0) as sgst, COUNT(*) as txns FROM gst_ledger WHERE $wh GROUP BY entity_id, gst_rate ORDER BY entity_id");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ─── REPORT HELPERS ──────────────────────────────────────

function getGSTReportInput($pdo, $filters = []) {
    $where = ["gl.input_output_type='input'"];
    $params = [];
    if (!empty($filters['date_from'])) { $where[] = 'gl.transaction_date >= ?'; $params[] = $filters['date_from']; }
    if (!empty($filters['date_to'])) { $where[] = 'gl.transaction_date <= ?'; $params[] = $filters['date_to']; }
    if (!empty($filters['gst_rate'])) { $where[] = 'gl.gst_rate = ?'; $params[] = $filters['gst_rate']; }
    if (!empty($filters['vendor_id'])) { $where[] = 'gl.entity_id = ?'; $params[] = $filters['vendor_id']; }
    $wh = implode(' AND ', $where);

    $sql = "SELECT gl.*, 
            CASE 
                WHEN gl.entity_type = 'supplier' THEN cs.company_name 
                ELSE v.name 
            END as entity_name,
            CASE 
                WHEN gl.entity_type = 'supplier' THEN cs.gst_number 
                ELSE v.gst_number 
            END as entity_gstin,
            CASE 
                WHEN gl.entity_type = 'supplier' THEN cp.invoice_number 
                ELSE vrb.invoice_number 
            END as vendor_invoice,
            CASE 
                WHEN gl.entity_type = 'supplier' THEN cp.invoice_date 
                ELSE vrb.received_date 
            END as batch_date,
            CASE 
                WHEN gl.entity_type = 'supplier' THEN cp.grand_total 
                ELSE vrb.total_cost 
            END as batch_total,
            CASE 
                WHEN gl.entity_type = 'supplier' THEN cp.grand_total 
                ELSE vrb.total_cost 
            END as batch_net,
            CASE 
                WHEN gl.entity_type = 'supplier' THEN 'supplier' 
                ELSE 'vendor' 
            END as ref_type
            FROM gst_ledger gl 
            LEFT JOIN vendors v ON gl.entity_type = 'vendor' AND gl.entity_id = v.id
            LEFT JOIN cylinder_suppliers cs ON gl.entity_type = 'supplier' AND gl.entity_id = cs.id
            LEFT JOIN vendor_refill_batches vrb ON gl.reference_type = 'vendor_refill_batch' AND gl.reference_id = vrb.id
            LEFT JOIN cylinder_purchases cp ON gl.reference_type IN ('cylinder_purchase', 'product_purchase') AND gl.reference_id = cp.id
            WHERE $wh 
            ORDER BY gl.transaction_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getGSTReportOutput($pdo, $filters = []) {
    $where = ["gl.input_output_type='output'"];
    $params = [];
    if (!empty($filters['date_from'])) { $where[] = 'gl.transaction_date >= ?'; $params[] = $filters['date_from']; }
    if (!empty($filters['date_to'])) { $where[] = 'gl.transaction_date <= ?'; $params[] = $filters['date_to']; }
    if (!empty($filters['gst_rate'])) { $where[] = 'gl.gst_rate = ?'; $params[] = $filters['gst_rate']; }
    if (!empty($filters['customer_id'])) { $where[] = 'gl.entity_id = ?'; $params[] = $filters['customer_id']; }
    $wh = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT gl.*, c.name as entity_name, c.gst_number as entity_gstin, ro.invoice_number, ro.order_date, ro.subtotal, ro.grand_total FROM gst_ledger gl JOIN customers c ON gl.entity_id = c.id LEFT JOIN refill_orders ro ON gl.reference_id = ro.id WHERE $wh ORDER BY gl.transaction_date DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ─── SYNC FUNCTIONS (data integrity) ─────────────────────

function syncGSTFromOrder($pdo, $order_id) {
    // Audit: capture existing entries before deletion
    $old_entries = [];
    try {
        $old = $pdo->prepare("SELECT id, gst_rate, taxable_amount, gst_amount, cgst, sgst, igst, input_output_type FROM gst_ledger WHERE reference_type='refill_order' AND reference_id=?");
        $old->execute([$order_id]);
        $old_entries = $old->fetchAll();
    } catch (PDOException $e) {}

    // Remove existing entries for this order
    $pdo->prepare("DELETE FROM gst_ledger WHERE reference_type='refill_order' AND reference_id=?")->execute([$order_id]);

    $stmt = $pdo->prepare("SELECT oi.*, o.customer_id, o.order_date, o.business_name FROM refill_order_items oi JOIN refill_orders o ON oi.refill_order_id = o.id WHERE oi.refill_order_id = ?");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll();
    if (empty($items)) return;

    // Determine intra/inter-state
    $first = $items[0];
    $order_biz = $first['business_name'] ?? '';
    $is_intra = true;
    try {
        $cust_stmt = $pdo->prepare("SELECT state_code FROM customers WHERE id = ?");
        $cust_stmt->execute([$first['customer_id']]);
        $cust_state = intval($cust_stmt->fetchColumn() ?: 0);
        $biz_state = 0;
        if ($cust_state > 0) {
            $filing = getGSTFilingConfig($pdo, $order_biz);
            $biz_state = intval($filing['state_code'] ?? 0);
        }
        if ($cust_state > 0 && $biz_state > 0) {
            $is_intra = isIntraState($cust_state, $biz_state);
        }
    } catch (PDOException $e) {
        $is_intra = true;
    }

    // Group by GST rate
    $by_rate = [];
    foreach ($items as $item) {
        $rate = floatval($item['gst_rate'] ?? 0);
        if ($rate <= 0) continue;
        $taxable = floatval($item['taxable_amount'] ?? 0);
        if ($taxable <= 0) {
            $taxable = floatval($item['price_per_unit'] ?? 0) * intval($item['qty'] ?? 1);
            if (intval($item['is_rental'] ?? 0) === 2) $taxable += floatval($item['sell_price'] ?? 0);
        }
        $gst = floatval($item['gst_amount'] ?? 0);
        if ($gst <= 0) {
            $calc = calculateGST($taxable, $rate);
            $gst = $calc['gst_amount'];
        }

        if (!isset($by_rate[$rate])) $by_rate[$rate] = ['taxable' => 0, 'gst' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
        $by_rate[$rate]['taxable'] += $taxable;
        $by_rate[$rate]['gst'] += $gst;
    }

    // Get order info
    $o = $pdo->prepare("SELECT customer_id, order_date FROM refill_orders WHERE id = ?");
    $o->execute([$order_id]);
    $order = $o->fetch();
    if (!$order) return;

    foreach ($by_rate as $rate => $totals) {
        $calc = calculateGST($totals['taxable'], $rate, $is_intra);
        recordOutputGST($pdo, [
            'entity_id' => $order['customer_id'],
            'gst_rate' => $rate,
            'taxable_amount' => $totals['taxable'],
            'gst_amount' => $totals['gst'],
            'cgst' => $calc['cgst'],
            'sgst' => $calc['sgst'],
            'igst' => $calc['igst'],
            'reference_type' => 'refill_order',
            'reference_id' => $order_id,
            'transaction_date' => $order['order_date'],
        ]);
    }

    // Audit trail
    if (!empty($old_entries)) {
        $old_summary = json_encode(['deleted' => $old_entries, 'recreated_with' => $by_rate, 'is_intra_state' => $is_intra]);
        try {
            $new_summary = json_encode(['recreated' => $by_rate, 'is_intra_state' => $is_intra]);
            $pdo->prepare("INSERT INTO audit_log (module, reference_type, reference_id, field_name, old_value, new_value, reason, amended_by) VALUES ('gst', 'refill_order_gst_sync', ?, 'input_entries', ?, ?, 'Auto-synced', 'system')")
                ->execute([$order_id, $old_summary, $new_summary]);
        } catch (PDOException $e) {
            error_log("GST audit trail error for order #$order_id: " . $e->getMessage());
        }
    }
}

function syncGSTFromBatch($pdo, $batch_id) {
    $pdo->prepare("DELETE FROM gst_ledger WHERE reference_type='vendor_refill_batch' AND reference_id=?")->execute([$batch_id]);

    $stmt = $pdo->prepare("SELECT * FROM vendor_refill_batches WHERE id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch();
    if (!$batch) return;

    $rate = floatval($batch['gst_rate'] ?? 0);
    if ($rate <= 0) return;

    $taxable = floatval($batch['taxable_amount'] ?? $batch['total_cost']);
    $gst = floatval($batch['gst_amount'] ?? 0);
    if ($gst <= 0) {
        $calc = calculateGST($taxable, $rate);
        $gst = $calc['gst_amount'];
    }
    $cgst = floatval($batch['cgst'] ?? $gst / 2);
    $sgst = floatval($batch['sgst'] ?? $gst - $cgst);

    recordInputGST($pdo, [
        'entity_id' => $batch['vendor_id'],
        'gst_rate' => $rate,
        'taxable_amount' => $taxable,
        'gst_amount' => $gst,
        'cgst' => $cgst,
        'sgst' => $sgst,
        'igst' => 0,
        'reference_type' => 'vendor_refill_batch',
        'reference_id' => $batch_id,
        'transaction_date' => date('Y-m-d', strtotime($batch['received_date'])),
    ]);
}

// ─── SYNC GST FROM VENDOR INVOICE ─────────────────────────

function syncGSTFromVendorInvoice($pdo, $invoice_id) {
    $pdo->prepare("DELETE FROM gst_ledger WHERE reference_type='vendor_invoice' AND reference_id=?")->execute([$invoice_id]);

    $stmt = $pdo->prepare("SELECT vi.*, v.name AS vendor_name FROM vendor_invoices vi JOIN vendors v ON vi.vendor_id = v.id WHERE vi.id = ?");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch();
    if (!$inv) return;

    $rate = floatval($inv['gst_rate'] ?? 0);
    if ($rate <= 0) {
        $stmt2 = $pdo->prepare("SELECT MAX(gst_rate) FROM vendor_invoice_items WHERE invoice_id = ?");
        $stmt2->execute([$invoice_id]);
        $rate = floatval($stmt2->fetchColumn() ?: 0);
    }
    if ($rate <= 0) {
        // Derive from amounts if possible
        $taxable = floatval($inv['taxable_amount'] ?? 0);
        $gst_amt = floatval($inv['gst_amount'] ?? 0);
        if ($taxable > 0 && $gst_amt > 0) {
            $rate = round($gst_amt / $taxable * 100, 2);
        }
    }
    if ($rate <= 0) return;

    recordInputGST($pdo, [
        'entity_type' => 'vendor',
        'entity_id' => $inv['vendor_id'],
        'gst_rate' => $rate,
        'taxable_amount' => floatval($inv['taxable_amount']),
        'gst_amount' => floatval($inv['gst_amount']),
        'cgst' => floatval($inv['cgst']),
        'sgst' => floatval($inv['sgst']),
        'igst' => floatval($inv['igst']),
        'reference_type' => 'vendor_invoice',
        'reference_id' => $invoice_id,
        'transaction_date' => $inv['invoice_date'],
    ]);
}

function syncGSTFromAllVendorInvoices($pdo, $vendor_id = null) {
    $where = $vendor_id ? "WHERE vi.vendor_id = " . intval($vendor_id) : "WHERE 1=1";
    $stmt = $pdo->query("SELECT vi.id FROM vendor_invoices vi $where AND vi.gst_amount > 0");
    while ($row = $stmt->fetch()) {
        syncGSTFromVendorInvoice($pdo, $row['id']);
    }
}

// ─── ENTITY NAMES ────────────────────────────────────────

function getGSTEntityName($pdo, $type, $id) {
    try {
        if ($type === 'customer') {
            $s = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
            $s->execute([$id]);
            return $s->fetchColumn() ?: "Customer #$id";
        } elseif ($type === 'supplier') {
            $s = $pdo->prepare("SELECT company_name FROM cylinder_suppliers WHERE id = ?");
            $s->execute([$id]);
            return $s->fetchColumn() ?: "Supplier #$id";
        } else {
            $s = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
            $s->execute([$id]);
            return $s->fetchColumn() ?: "Vendor #$id";
        }
    } catch (PDOException $e) {
        return "$type #$id";
    }
}
