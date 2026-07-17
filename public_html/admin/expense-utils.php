<?php
/**
 * Expense Management — Migrations, Utilities, Auto-Creation
 */

if (!function_exists('runExpenseMigrations')) {

function runExpenseMigrations($pdo) {
    // 1. expense_category_groups
    try { $pdo->query("SELECT id FROM expense_category_groups LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'expense_category_groups') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS expense_category_groups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("INSERT IGNORE INTO expense_category_groups (name, display_order) VALUES
                ('Direct Expenses', 1),
                ('Vehicle Expenses', 2),
                ('Employee Expenses', 3),
                ('Office Expenses', 4),
                ('Software & IT', 5),
                ('Professional Expenses', 6),
                ('Marketing', 7),
                ('Financial Expenses', 8),
                ('Miscellaneous', 9)");
        }
    }

    // 2. expense_categories
    try { $pdo->query("SELECT id FROM expense_categories LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'expense_categories') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                name VARCHAR(150) NOT NULL,
                description TEXT DEFAULT NULL,
                gst_applicable TINYINT(1) NOT NULL DEFAULT 0,
                default_gst_rate DECIMAL(5,2) DEFAULT NULL,
                hsn_code VARCHAR(8) DEFAULT NULL,
                auto_create TINYINT(1) NOT NULL DEFAULT 0,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                account_code VARCHAR(20) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (group_id) REFERENCES expense_category_groups(id) ON DELETE RESTRICT,
                INDEX (group_id),
                INDEX (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("INSERT IGNORE INTO expense_categories (group_id, name, description, gst_applicable, default_gst_rate, hsn_code, auto_create, is_system, account_code) VALUES
                (1, 'Gas Refilling Charges', 'Cost of gas refilling from vendors', 1, 18.00, '271019', 1, 1, '5001'),
                (1, 'Cylinder Purchase', 'New cylinder purchases from suppliers', 1, 18.00, '731100', 1, 1, '5002'),
                (1, 'Cylinder Repair', 'Cylinder repair & maintenance costs', 1, 18.00, '731100', 0, 0, '5003'),
                (1, 'Cylinder Hydro Testing', 'Hydrostatic testing of cylinders', 1, 18.00, '731100', 0, 0, '5004'),
                (1, 'Cylinder Valve Purchase', 'Valve replacement costs', 1, 18.00, '848180', 0, 0, '5005'),
                (1, 'Cylinder Accessories', 'Caps, rings, seals, etc.', 1, 18.00, '732690', 0, 0, '5006'),
                (1, 'Transport Charges', 'Transportation/freight costs', 0, 0.00, '996511', 1, 1, '5007'),
                (1, 'Loading Charges', 'Loading labour charges', 0, 0.00, '996912', 0, 0, '5008'),
                (1, 'Unloading Charges', 'Unloading labour charges', 0, 0.00, '996912', 0, 0, '5009'),
                (2, 'Diesel', 'Diesel for vehicles', 0, 0.00, NULL, 0, 0, '5010'),
                (2, 'Petrol', 'Petrol for vehicles', 0, 0.00, NULL, 0, 0, '5011'),
                (2, 'Driver Salary', 'Vehicle driver salary', 0, 0.00, NULL, 0, 0, '5012'),
                (2, 'Vehicle Repair', 'Vehicle repair & maintenance', 1, 18.00, '996711', 0, 0, '5013'),
                (2, 'Tyres', 'Vehicle tyre replacement', 1, 18.00, '401120', 0, 0, '5014'),
                (2, 'Insurance', 'Vehicle insurance premium', 0, 0.00, NULL, 0, 0, '5015'),
                (2, 'Toll Tax', 'Toll charges', 0, 0.00, NULL, 0, 0, '5016'),
                (2, 'Parking', 'Parking fees', 0, 0.00, NULL, 0, 0, '5017'),
                (2, 'Vehicle Service', 'Vehicle servicing costs', 1, 18.00, '996712', 0, 0, '5018'),
                (3, 'Salary', 'Staff salaries', 0, 0.00, NULL, 0, 0, '5019'),
                (3, 'Labour Charges', 'Daily wage labour costs', 0, 0.00, NULL, 0, 0, '5020'),
                (3, 'Overtime', 'Overtime payments', 0, 0.00, NULL, 0, 0, '5021'),
                (3, 'Bonus', 'Employee bonus payments', 0, 0.00, NULL, 0, 0, '5022'),
                (4, 'Rent', 'Office/warehouse rent', 0, 0.00, NULL, 0, 0, '5023'),
                (4, 'Electricity', 'Electricity bills', 0, 0.00, NULL, 0, 0, '5024'),
                (4, 'Water', 'Water bills', 0, 0.00, NULL, 0, 0, '5025'),
                (4, 'Internet', 'Internet connection charges', 0, 0.00, NULL, 0, 0, '5026'),
                (4, 'Mobile Bills', 'Mobile phone bills', 0, 0.00, NULL, 0, 0, '5027'),
                (4, 'Printing', 'Printing & photocopy', 0, 0.00, NULL, 0, 0, '5028'),
                (4, 'Stationery', 'Stationery items', 0, 0.00, NULL, 0, 0, '5029'),
                (4, 'Office Supplies', 'General office supplies', 0, 0.00, NULL, 0, 0, '5030'),
                (5, 'Hosting', 'Web hosting charges', 0, 0.00, NULL, 0, 0, '5031'),
                (5, 'Domain', 'Domain registration/renewal', 0, 0.00, NULL, 0, 0, '5032'),
                (5, 'SMS Charges', 'SMS gateway expenses', 0, 0.00, NULL, 0, 0, '5033'),
                (5, 'WhatsApp API', 'WhatsApp Business API charges', 0, 0.00, NULL, 0, 0, '5034'),
                (5, 'Cloud Services', 'Cloud infrastructure costs', 1, 18.00, '998431', 0, 0, '5035'),
                (5, 'Software Subscription', 'Software license fees', 1, 18.00, '998331', 0, 0, '5036'),
                (6, 'Accountant Fees', 'Professional accounting charges', 0, 0.00, NULL, 0, 0, '5037'),
                (6, 'Legal Fees', 'Legal consultation costs', 0, 0.00, NULL, 0, 0, '5038'),
                (6, 'GST Filing Charges', 'GST return filing fees', 0, 0.00, NULL, 0, 0, '5039'),
                (6, 'Audit Fees', 'Statutory audit fees', 0, 0.00, NULL, 0, 0, '5040'),
                (7, 'Advertisement', 'Advertising & promotion costs', 0, 0.00, NULL, 0, 0, '5041'),
                (7, 'Banner', 'Banner & flex printing', 0, 0.00, NULL, 0, 0, '5042'),
                (7, 'Visiting Card', 'Business card printing', 0, 0.00, NULL, 0, 0, '5043'),
                (7, 'Promotion', 'Sales promotion activities', 0, 0.00, NULL, 0, 0, '5044'),
                (8, 'Bank Charges', 'Bank account maintenance & transaction fees', 0, 0.00, NULL, 0, 0, '5045'),
                (8, 'Loan Interest', 'Interest on business loans', 0, 0.00, NULL, 0, 0, '5046'),
                (8, 'Processing Charges', 'Loan/credit processing fees', 0, 0.00, NULL, 0, 0, '5047'),
                (9, 'Misc Expense', 'Other miscellaneous expenses', 0, 0.00, NULL, 0, 0, '5048')");
        }
    }

    // 3. expenses (main table)
    try { $pdo->query("SELECT id FROM expenses LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'expenses') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                expense_number VARCHAR(50) NOT NULL UNIQUE,
                category_id INT NOT NULL,
                vendor_id INT DEFAULT NULL,
                vendor_name VARCHAR(150) DEFAULT NULL,
                expense_date DATE NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                gst_type ENUM('exclusive','inclusive') NOT NULL DEFAULT 'exclusive',
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                taxable_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                cgst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                sgst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                igst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                gst_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',
                payment_status ENUM('paid','unpaid','partial') NOT NULL DEFAULT 'paid',
                business_key VARCHAR(50) NOT NULL DEFAULT 'prem_gas_solution',
                warehouse_branch VARCHAR(100) DEFAULT NULL,
                vehicle_number VARCHAR(50) DEFAULT NULL,
                driver_name VARCHAR(100) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                reference_type VARCHAR(50) DEFAULT NULL,
                reference_id INT DEFAULT NULL,
                reference_number VARCHAR(100) DEFAULT NULL,
            created_by INT DEFAULT NULL,
                created_by_name VARCHAR(100) DEFAULT NULL,
                status ENUM('approved','rejected') NOT NULL DEFAULT 'approved',
                approved_by INT DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
                FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
                INDEX (expense_date),
                INDEX (category_id),
                INDEX (vendor_id),
                INDEX (payment_method),
                INDEX (payment_status),
                INDEX (business_key),
                INDEX (reference_type, reference_id),
                INDEX (status),
                INDEX (is_deleted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // 4. expense_attachments
    try { $pdo->query("SELECT id FROM expense_attachments LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'expense_attachments') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS expense_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                expense_id INT NOT NULL,
                filename VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                file_size INT NOT NULL DEFAULT 0,
                mime_type VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
                INDEX (expense_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    // 5. chart_of_accounts
    try { $pdo->query("SELECT id FROM chart_of_accounts LIMIT 0"); }
    catch (PDOException $e) {
        if (strpos($e->getMessage(), 'chart_of_accounts') !== false) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chart_of_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_code VARCHAR(20) NOT NULL UNIQUE,
                account_name VARCHAR(200) NOT NULL,
                account_type ENUM('asset','liability','equity','income','expense') NOT NULL,
                category VARCHAR(100) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (account_type),
                INDEX (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("INSERT IGNORE INTO chart_of_accounts (account_code, account_name, account_type, category) VALUES
                ('1001', 'Cash', 'asset', 'Current Assets'),
                ('1002', 'Bank Account', 'asset', 'Current Assets'),
                ('1003', 'UPI Account', 'asset', 'Current Assets'),
                ('1101', 'Inventory - Cylinders', 'asset', 'Current Assets'),
                ('1102', 'Inventory - Gas', 'asset', 'Current Assets'),
                ('1201', 'Trade Receivables', 'asset', 'Current Assets'),
                ('1301', 'Fixed Assets', 'asset', 'Fixed Assets'),
                ('1401', 'Input Tax Credit (ITC)', 'asset', 'Current Assets'),
                ('2001', 'Sundry Creditors', 'liability', 'Current Liabilities'),
                ('2101', 'Output GST Payable', 'liability', 'Current Liabilities'),
                ('2201', 'Loan Payable', 'liability', 'Long Term Liabilities'),
                ('3001', 'Retained Earnings', 'equity', 'Equity'),
                ('4001', 'Sales Revenue', 'income', 'Revenue'),
                ('4002', 'Rental Income', 'income', 'Revenue'),
                ('5001', 'Gas Refilling Charges', 'expense', 'Direct Expenses'),
                ('5002', 'Cylinder Purchase', 'expense', 'Direct Expenses'),
                ('5003', 'Cylinder Repair', 'expense', 'Direct Expenses'),
                ('5004', 'Cylinder Hydro Testing', 'expense', 'Direct Expenses'),
                ('5005', 'Cylinder Valve Purchase', 'expense', 'Direct Expenses'),
                ('5006', 'Cylinder Accessories', 'expense', 'Direct Expenses'),
                ('5007', 'Transport Charges', 'expense', 'Direct Expenses'),
                ('5008', 'Loading Charges', 'expense', 'Direct Expenses'),
                ('5009', 'Unloading Charges', 'expense', 'Direct Expenses'),
                ('5010', 'Diesel', 'expense', 'Vehicle Expenses'),
                ('5011', 'Petrol', 'expense', 'Vehicle Expenses'),
                ('5012', 'Driver Salary', 'expense', 'Vehicle Expenses'),
                ('5013', 'Vehicle Repair', 'expense', 'Vehicle Expenses'),
                ('5014', 'Tyres', 'expense', 'Vehicle Expenses'),
                ('5015', 'Insurance', 'expense', 'Vehicle Expenses'),
                ('5016', 'Toll Tax', 'expense', 'Vehicle Expenses'),
                ('5017', 'Parking', 'expense', 'Vehicle Expenses'),
                ('5018', 'Vehicle Service', 'expense', 'Vehicle Expenses'),
                ('5019', 'Salary', 'expense', 'Employee Expenses'),
                ('5020', 'Labour Charges', 'expense', 'Employee Expenses'),
                ('5021', 'Overtime', 'expense', 'Employee Expenses'),
                ('5022', 'Bonus', 'expense', 'Employee Expenses'),
                ('5023', 'Rent', 'expense', 'Office Expenses'),
                ('5024', 'Electricity', 'expense', 'Office Expenses'),
                ('5025', 'Water', 'expense', 'Office Expenses'),
                ('5026', 'Internet', 'expense', 'Office Expenses'),
                ('5027', 'Mobile Bills', 'expense', 'Office Expenses'),
                ('5028', 'Printing', 'expense', 'Office Expenses'),
                ('5029', 'Stationery', 'expense', 'Office Expenses'),
                ('5030', 'Office Supplies', 'expense', 'Office Expenses'),
                ('5031', 'Hosting', 'expense', 'Software & IT'),
                ('5032', 'Domain', 'expense', 'Software & IT'),
                ('5033', 'SMS Charges', 'expense', 'Software & IT'),
                ('5034', 'WhatsApp API', 'expense', 'Software & IT'),
                ('5035', 'Cloud Services', 'expense', 'Software & IT'),
                ('5036', 'Software Subscription', 'expense', 'Software & IT'),
                ('5037', 'Accountant Fees', 'expense', 'Professional Expenses'),
                ('5038', 'Legal Fees', 'expense', 'Professional Expenses'),
                ('5039', 'GST Filing Charges', 'expense', 'Professional Expenses'),
                ('5040', 'Audit Fees', 'expense', 'Professional Expenses'),
                ('5041', 'Advertisement', 'expense', 'Marketing'),
                ('5042', 'Banner', 'expense', 'Marketing'),
                ('5043', 'Visiting Card', 'expense', 'Marketing'),
                ('5044', 'Promotion', 'expense', 'Marketing'),
                ('5045', 'Bank Charges', 'expense', 'Financial Expenses'),
                ('5046', 'Loan Interest', 'expense', 'Financial Expenses'),
                ('5047', 'Processing Charges', 'expense', 'Financial Expenses'),
                ('5048', 'Misc Expense', 'expense', 'Miscellaneous')");
        }
    }

    // 6. Add 'expense' to gst_ledger entity_type ENUM
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM gst_ledger LIKE 'entity_type'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($col && strpos($col['Type'], "'expense'") === false) {
            $pdo->exec("ALTER TABLE gst_ledger MODIFY COLUMN entity_type ENUM('vendor','customer','supplier','expense') NOT NULL");
        }
    } catch (PDOException $e) {}
}

// ─── UTILITY FUNCTIONS ────────────────────────────────────────

function generateExpenseNumber($pdo, $business_key = null) {
    if (!$business_key) $business_key = getBrandConfig()['business_key'];
    $prefix = 'EXP';
    $date = date('Ymd');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE expense_number LIKE ? AND DATE(created_at) = CURDATE()");
    $stmt->execute(["$prefix-$date-%"]);
    $count = (int)$stmt->fetchColumn();
    $seq = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    return "$prefix-$date-$seq";
}

function calculateExpenseGST($amount, $rate, $gst_type = 'exclusive', $is_intra_state = true) {
    $rate = floatval($rate);
    $amount = floatval($amount);
    if ($rate <= 0 || $amount <= 0) {
        return [
            'amount' => $amount,
            'taxable_amount' => $amount,
            'gst_rate' => 0,
            'gst_total' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_amount' => $amount,
        ];
    }
    if ($gst_type === 'inclusive') {
        $taxable = round($amount * 100 / (100 + $rate), 2);
        $gst_total = round($amount - $taxable, 2);
    } else {
        $taxable = $amount;
        $gst_total = round($amount * $rate / 100, 2);
    }
    if ($is_intra_state) {
        $half = round($gst_total / 2, 2);
        $cgst = $half;
        $sgst = $gst_total - $half;
        $igst = 0.00;
    } else {
        $cgst = 0.00;
        $sgst = 0.00;
        $igst = $gst_total;
    }
    return [
        'amount' => $amount,
        'taxable_amount' => $taxable,
        'gst_rate' => $rate,
        'gst_total' => $gst_total,
        'cgst_amount' => $cgst,
        'sgst_amount' => $sgst,
        'igst_amount' => $igst,
        'total_amount' => $gst_type === 'inclusive' ? $amount : round($taxable + $gst_total, 2),
    ];
}

function autoCreateExpense($pdo, $data) {
    $defaults = [
        'category_id' => 0,
        'vendor_id' => null,
        'vendor_name' => '',
        'expense_date' => date('Y-m-d'),
        'amount' => 0,
        'gst_type' => 'exclusive',
        'gst_rate' => 0,
        'taxable_amount' => 0,
        'cgst_amount' => 0,
        'sgst_amount' => 0,
        'igst_amount' => 0,
        'gst_total' => 0,
        'total_amount' => 0,
        'payment_method' => 'Bank Transfer',
        'payment_status' => 'unpaid',
        'business_key' => getBrandConfig()['business_key'],
        'warehouse_branch' => null,
        'vehicle_number' => null,
        'driver_name' => null,
        'notes' => null,
        'reference_type' => null,
        'reference_id' => null,
        'reference_number' => null,
        'created_by' => null,
        'created_by_name' => 'system',
    ];
    $data = array_merge($defaults, $data);

    if ($data['category_id'] <= 0 || $data['amount'] <= 0) {
        return false;
    }

    if ($data['total_amount'] <= 0) {
        $calc = calculateExpenseGST($data['amount'], $data['gst_rate'], $data['gst_type']);
        $data['taxable_amount'] = $calc['taxable_amount'];
        $data['gst_total'] = $calc['gst_total'];
        $data['cgst_amount'] = $calc['cgst_amount'];
        $data['sgst_amount'] = $calc['sgst_amount'];
        $data['igst_amount'] = $calc['igst_amount'];
        $data['total_amount'] = $calc['total_amount'];
    }

    $expense_number = generateExpenseNumber($pdo, $data['business_key']);

    try {
        $stmt = $pdo->prepare("INSERT INTO expenses (expense_number, category_id, vendor_id, vendor_name, expense_date, amount, gst_type, gst_rate, taxable_amount, cgst_amount, sgst_amount, igst_amount, gst_total, total_amount, payment_method, payment_status, business_key, warehouse_branch, vehicle_number, driver_name, notes, reference_type, reference_id, reference_number, created_by, created_by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $expense_number,
            $data['category_id'],
            $data['vendor_id'],
            $data['vendor_name'],
            $data['expense_date'],
            $data['amount'],
            $data['gst_type'],
            $data['gst_rate'],
            $data['taxable_amount'],
            $data['cgst_amount'],
            $data['sgst_amount'],
            $data['igst_amount'],
            $data['gst_total'],
            $data['total_amount'],
            $data['payment_method'],
            $data['payment_status'],
            $data['business_key'],
            $data['warehouse_branch'],
            $data['vehicle_number'],
            $data['driver_name'],
            $data['notes'],
            $data['reference_type'],
            $data['reference_id'],
            $data['reference_number'],
            $data['created_by'],
            $data['created_by_name'],
        ]);
        $expense_id = (int)$pdo->lastInsertId();

        // Sync to GST ledger
        if ($data['gst_rate'] > 0 && $data['gst_total'] > 0) {
            if (function_exists('recordInputGST')) {
                recordInputGST($pdo, [
                    'entity_type' => 'vendor',
                    'entity_id' => $data['vendor_id'] ?: 0,
                    'gst_rate' => $data['gst_rate'],
                    'taxable_amount' => $data['taxable_amount'],
                    'gst_amount' => $data['gst_total'],
                    'cgst' => $data['cgst_amount'],
                    'sgst' => $data['sgst_amount'],
                    'igst' => $data['igst_amount'],
                    'reference_type' => 'expense',
                    'reference_id' => $expense_id,
                    'transaction_date' => $data['expense_date'],
                ]);
            }
        }

        return $expense_id;
    } catch (PDOException $e) {
        error_log("autoCreateExpense failed: " . $e->getMessage());
        return false;
    }
}

function softDeleteExpense($pdo, $id) {
    $stmt = $pdo->prepare("UPDATE expenses SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        // Remove from GST ledger
        try {
            if (function_exists('syncGSTFromExpense')) {
                $pdo->prepare("DELETE FROM gst_ledger WHERE reference_type = 'expense' AND reference_id = ?")->execute([$id]);
            }
        } catch (PDOException $e) {}
        return true;
    }
    return false;
}

function getExpenseStats($pdo, $business_key = null) {
    $where = "WHERE is_deleted = 0";
    $params = [];
    if ($business_key) {
        $where .= " AND business_key = ?";
        $params[] = $business_key;
    }
    $stats = [];
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN DATE(expense_date) = CURDATE() THEN total_amount ELSE 0 END), 0) AS today,
                                      COALESCE(SUM(CASE WHEN MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE()) THEN total_amount ELSE 0 END), 0) AS month,
                                      COALESCE(SUM(CASE WHEN YEAR(expense_date) = YEAR(CURDATE()) THEN total_amount ELSE 0 END), 0) AS year,
                                      COUNT(*) AS total_count,
                                      COALESCE(SUM(CASE WHEN payment_status IN ('unpaid','partial') THEN total_amount ELSE 0 END), 0) AS pending_amount,
                                      COUNT(CASE WHEN payment_status IN ('unpaid','partial') THEN 1 END) AS pending_count
                               FROM expenses $where");
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
    return $stats ?: ['today' => 0, 'month' => 0, 'year' => 0, 'total_count' => 0, 'pending_amount' => 0, 'pending_count' => 0];
}

function getMonthlyExpenseTrend($pdo, $months = 6, $business_key = null) {
    $where = "WHERE is_deleted = 0";
    $params = [];
    if ($business_key) {
        $where .= " AND business_key = ?";
        $params[] = $business_key;
    }
    $data = [];
    try {
        $sql = "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month, COALESCE(SUM(total_amount), 0) AS total
                FROM expenses $where
                AND expense_date >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)
                GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                ORDER BY month ASC";
        $stmt = $pdo->query($sql);
        $data = $stmt->fetchAll();
    } catch (PDOException $e) {}
    return $data;
}

function getTopExpenseCategories($pdo, $limit = 5, $business_key = null) {
    $where = "WHERE e.is_deleted = 0";
    $params = [];
    if ($business_key) {
        $where .= " AND e.business_key = ?";
        $params[] = $business_key;
    }
    $data = [];
    try {
        $sql = "SELECT ec.name, ec.id, COALESCE(SUM(e.total_amount), 0) AS total
                FROM expenses e
                JOIN expense_categories ec ON e.category_id = ec.id
                $where
                AND MONTH(e.expense_date) = MONTH(CURDATE())
                AND YEAR(e.expense_date) = YEAR(CURDATE())
                GROUP BY ec.id, ec.name
                ORDER BY total DESC
                LIMIT " . (int)$limit;
        $stmt = $pdo->query($sql);
        $data = $stmt->fetchAll();
    } catch (PDOException $e) {}
    return $data;
}

function getCategoryIdBySlug($pdo, $name) {
    static $cache = [];
    $key = 'cat_' . md5($name);
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $pdo->prepare("SELECT id FROM expense_categories WHERE name = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$name]);
    $id = (int)$stmt->fetchColumn();
    $cache[$key] = $id;
    return $id;
}

function ensureSystemCategory($pdo, $name) {
    $id = getCategoryIdBySlug($pdo, $name);
    if ($id > 0) return $id;

    runExpenseMigrations($pdo);

    $id = getCategoryIdBySlug($pdo, $name);
    if ($id > 0) return $id;

    $system_cats = [
        'Transport Charges' => ['group' => 'Direct Expenses', 'gst_applicable' => 0, 'default_gst_rate' => 0.00, 'hsn_code' => '996511', 'account_code' => '5007', 'auto_create' => 1],
        'Cylinder Purchase' => ['group' => 'Direct Expenses', 'gst_applicable' => 1, 'default_gst_rate' => 18.00, 'hsn_code' => '731100', 'account_code' => '5002', 'auto_create' => 1],
        'Gas Refilling Charges' => ['group' => 'Direct Expenses', 'gst_applicable' => 1, 'default_gst_rate' => 18.00, 'hsn_code' => '271019', 'account_code' => '5001', 'auto_create' => 1],
    ];

    if (!isset($system_cats[$name])) return 0;
    $cfg = $system_cats[$name];

    try {
        $stmt = $pdo->prepare("SELECT id FROM expense_category_groups WHERE name = ? LIMIT 1");
        $stmt->execute([$cfg['group']]);
        $group_id = (int)$stmt->fetchColumn();
        if ($group_id <= 0) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO expense_category_groups (name, display_order) VALUES (?, 1)");
            $stmt->execute([$cfg['group']]);
            $group_id = (int)$pdo->lastInsertId();
            if ($group_id <= 0) {
                $stmt = $pdo->prepare("SELECT id FROM expense_category_groups WHERE name = ? LIMIT 1");
                $stmt->execute([$cfg['group']]);
                $group_id = (int)$stmt->fetchColumn();
            }
        }
        if ($group_id <= 0) return 0;

        $stmt = $pdo->prepare("INSERT IGNORE INTO expense_categories (group_id, name, description, gst_applicable, default_gst_rate, hsn_code, auto_create, is_system, account_code) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)");
        $stmt->execute([$group_id, $name, ucfirst($name), $cfg['gst_applicable'], $cfg['default_gst_rate'], $cfg['hsn_code'], $cfg['account_code']]);

        $stmt = $pdo->prepare("SELECT id FROM expense_categories WHERE name = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("ensureSystemCategory($name) failed: " . $e->getMessage());
        return 0;
    }
}

function syncExpenseGST($pdo, $expense_id) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense) return false;

    try {
        $pdo->prepare("DELETE FROM gst_ledger WHERE reference_type = 'expense' AND reference_id = ?")->execute([$expense_id]);
    } catch (PDOException $e) {}

    if ($expense['gst_rate'] > 0 && $expense['gst_total'] > 0) {
        if (function_exists('recordInputGST')) {
            recordInputGST($pdo, [
                'entity_type' => 'vendor',
                'entity_id' => $expense['vendor_id'] ?: 0,
                'gst_rate' => $expense['gst_rate'],
                'taxable_amount' => $expense['taxable_amount'],
                'gst_amount' => $expense['gst_total'],
                'cgst' => $expense['cgst_amount'],
                'sgst' => $expense['sgst_amount'],
                'igst' => $expense['igst_amount'],
                'reference_type' => 'expense',
                'reference_id' => $expense_id,
                'transaction_date' => $expense['expense_date'],
            ]);
        }
    }
    return true;
}

function resolveSystemCategory($pdo, $name) {
    static $hardcoded = [
        'Transport Charges' => ['group' => 'Direct Expenses', 'gst_applicable' => 0, 'default_gst_rate' => 0.00, 'hsn_code' => '996511', 'account_code' => '5007'],
        'Cylinder Purchase' => ['group' => 'Direct Expenses', 'gst_applicable' => 1, 'default_gst_rate' => 18.00, 'hsn_code' => '731100', 'account_code' => '5002'],
        'Gas Refilling Charges' => ['group' => 'Direct Expenses', 'gst_applicable' => 1, 'default_gst_rate' => 18.00, 'hsn_code' => '271019', 'account_code' => '5001'],
    ];

    try {
        // Tier 1: cached lookup
        $id = getCategoryIdBySlug($pdo, $name);
        if ($id > 0) return $id;

        // Tier 2: normal create via migration path
        $id = ensureSystemCategory($pdo, $name);
        if ($id > 0) return $id;

        // Tier 3: hardcoded fallback — direct INSERT
        if (!isset($hardcoded[$name])) return 0;
        $cfg = $hardcoded[$name];

        $stmt = $pdo->prepare("SELECT id FROM expense_category_groups WHERE name = ? LIMIT 1");
        $stmt->execute([$cfg['group']]);
        $group_id = (int)$stmt->fetchColumn();
        if ($group_id <= 0) {
            $pdo->prepare("INSERT IGNORE INTO expense_category_groups (name, display_order) VALUES (?, 99)")->execute([$cfg['group']]);
            $stmt = $pdo->prepare("SELECT id FROM expense_category_groups WHERE name = ? LIMIT 1");
            $stmt->execute([$cfg['group']]);
            $group_id = (int)$stmt->fetchColumn();
        }
        if ($group_id <= 0) return 0;

        $pdo->prepare("INSERT IGNORE INTO expense_categories (group_id, name, description, gst_applicable, default_gst_rate, hsn_code, auto_create, is_system, account_code) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)")
            ->execute([$group_id, $name, ucfirst($name), $cfg['gst_applicable'], $cfg['default_gst_rate'], $cfg['hsn_code'], $cfg['account_code']]);

        $stmt = $pdo->prepare("SELECT id FROM expense_categories WHERE name = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("resolveSystemCategory($name) failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Propagate expense payment_status changes back to source records.
 * Called after an expense is updated via the edit form.
 */
function syncExpensePaymentStatus($pdo, $expense_id) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense) return;

    $rt = $expense['reference_type'];
    $rid = $expense['reference_id'];
    $ps  = $expense['payment_status'];

    if ($rt === 'cylinder_purchase' && $rid) {
        // Sync back to cylinder_purchases
        $stmt = $pdo->prepare("UPDATE cylinder_purchases SET payment_status = ? WHERE id = ?");
        $stmt->execute([$ps, $rid]);

        // If marked paid/partial, ensure a supplier_ledger credit entry exists
        if ($ps !== 'unpaid') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_ledger WHERE reference_type='purchase' AND reference_id=? AND transaction_type='payment'");
            $stmt->execute([$rid]);
            $has_payment = (int)$stmt->fetchColumn() > 0;

            if (!$has_payment) {
                $stmt = $pdo->prepare("SELECT supplier_id, grand_total FROM cylinder_purchases WHERE id = ?");
                $stmt->execute([$rid]);
                $purch = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($purch) {
                    $stmt = $pdo->prepare("SELECT running_balance FROM supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$purch['supplier_id']]);
                    $last_bal = floatval($stmt->fetchColumn());
                    $new_bal = $last_bal - floatval($purch['grand_total']);
                    $stmt = $pdo->prepare("INSERT INTO supplier_ledger (supplier_id, transaction_date, transaction_type, debit, credit, running_balance, reference_type, reference_id, remarks, created_by) VALUES (?, NOW(), 'payment', 0, ?, ?, 'purchase', ?, ?, ?)");
                    $stmt->execute([$purch['supplier_id'], $purch['grand_total'], $new_bal, $rid, "Auto-payment from expense #$expense_id status change", $_SESSION['user_name'] ?? 'system']);
                }
            }
        }
    }

    if ($rt === 'vendor_invoice' && $rid) {
        // Sync back to dispatch_lots referenced by the invoice
        $stmt = $pdo->prepare("SELECT lot_id FROM vendor_invoices WHERE id = ?");
        $stmt->execute([$rid]);
        $lot_id = $stmt->fetchColumn();
        if ($lot_id) {
            $stmt = $pdo->prepare("UPDATE dispatch_lots SET payment_status = ? WHERE id = ?");
            $stmt->execute([$ps, $lot_id]);
        }
    }
}

} // endif function_exists
