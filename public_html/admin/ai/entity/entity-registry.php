<?php

if (!function_exists('getEntityRegistry')) {
    function getEntityRegistry() {
        return [
            'cylinders' => [
                'label' => 'Cylinder',
                'label_plural' => 'Cylinders',
                'description' => 'Master gas cylinder records with serial numbers, ownership, and current status',
                'search_columns' => ['serial_number', 'barcode', 'rfid_tag'],
                'identifier' => 'serial_number',
                'icon' => 'cylinder',
                'relationships' => [
                    ['table' => 'cylinder_transactions', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'cylinder_id', 'label' => 'Transaction History'],
                    ['table' => 'refill_order_items', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'cylinder_id', 'label' => 'Refill Orders'],
                    ['table' => 'gas_types', 'type' => 'belongs_to', 'key' => 'gas_type_id', 'foreign' => 'id', 'label' => 'Gas Type'],
                    ['table' => 'vendors', 'type' => 'belongs_to', 'key' => 'vendor_id', 'foreign' => 'id', 'label' => 'Vendor'],
                    ['table' => 'customers', 'type' => 'belongs_to', 'key' => 'current_customer_id', 'foreign' => 'id', 'label' => 'Current Customer'],
                    ['table' => 'partners', 'type' => 'belongs_to', 'key' => 'current_partner_id', 'foreign' => 'id', 'label' => 'Current Partner'],
                    ['table' => 'cylinder_audit_log', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'cylinder_id', 'label' => 'Audit Logs'],
                    ['table' => 'partner_transaction_items', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'cylinder_id', 'label' => 'Partner Transaction Items'],
                ],
                'query_templates' => [
                    'detail' => "SELECT c.*, gt.name AS gas_name, v.name AS vendor_name, cust.name AS current_customer_name, p.company_name AS current_partner_name FROM cylinders c LEFT JOIN gas_types gt ON c.gas_type_id = gt.id LEFT JOIN vendors v ON c.vendor_id = v.id LEFT JOIN customers cust ON c.current_customer_id = cust.id LEFT JOIN partners p ON c.current_partner_id = p.id WHERE c.serial_number LIKE ? OR c.barcode LIKE ? LIMIT 10",
                    'status_summary' => "SELECT status, COUNT(*) AS count FROM cylinders GROUP BY status",
                    'ownership_summary' => "SELECT ownership_type, COUNT(*) AS count FROM cylinders GROUP BY ownership_type",
                    'by_gas' => "SELECT gt.name AS gas_name, COUNT(c.id) AS total FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id GROUP BY gt.name",
                    'lifecycle' => "SELECT ct.*, c.serial_number, c.barcode FROM cylinder_transactions ct JOIN cylinders c ON ct.cylinder_id = c.id WHERE c.serial_number LIKE ? ORDER BY ct.transaction_date DESC LIMIT 50",
                    'overdue_hydro' => "SELECT c.*, gt.name AS gas_name FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id WHERE c.hydrotest_expiry IS NOT NULL AND c.hydrotest_expiry < CURDATE() AND c.status NOT IN ('deleted','inactive') ORDER BY c.hydrotest_expiry ASC",
                    'audit' => "SELECT cal.* FROM cylinder_audit_log cal JOIN cylinders c ON cal.cylinder_id = c.id WHERE c.serial_number LIKE ? ORDER BY cal.logged_at DESC LIMIT 50",
                    'partner_items' => "SELECT pti.*, p.company_name, pt.transaction_date, transaction_type FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id = pt.id JOIN partners p ON pt.partner_id = p.id WHERE pti.cylinder_id = (SELECT id FROM cylinders WHERE serial_number = ?) ORDER BY pt.transaction_date DESC LIMIT 20",
                ],
            ],
            'cylinder_transactions' => [
                'label' => 'Cylinder Transaction',
                'label_plural' => 'Cylinder Transactions',
                'description' => 'Audit log of every cylinder movement: refill, dispatch, return, maintenance, transfer',
                'search_columns' => ['reference_number', 'notes'],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'cylinders', 'type' => 'belongs_to', 'key' => 'cylinder_id', 'foreign' => 'id', 'label' => 'Cylinder'],
                    ['table' => 'customers', 'type' => 'belongs_to', 'key' => 'customer_id', 'foreign' => 'id', 'label' => 'Related Customer'],
                ],
                'query_templates' => [
                    'by_cylinder' => "SELECT ct.*, c.serial_number FROM cylinder_transactions ct JOIN cylinders c ON ct.cylinder_id = c.id WHERE c.serial_number LIKE ? ORDER BY ct.transaction_date DESC LIMIT 50",
                    'recent' => "SELECT ct.*, c.serial_number FROM cylinder_transactions ct JOIN cylinders c ON ct.cylinder_id = c.id ORDER BY ct.transaction_date DESC LIMIT 20",
                    'by_type' => "SELECT ct.*, c.serial_number FROM cylinder_transactions ct JOIN cylinders c ON ct.cylinder_id = c.id WHERE ct.transaction_type = ? ORDER BY ct.transaction_date DESC LIMIT 50",
                ],
            ],
            'customers' => [
                'label' => 'Customer',
                'label_plural' => 'Customers',
                'description' => 'Customer profiles with contact info, deposit balance, and cylinder assignment tracking',
                'search_columns' => ['name', 'mobile', 'email', 'city'],
                'identifier' => 'name',
                'icon' => 'user',
                'relationships' => [
                    ['table' => 'refill_orders', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'customer_id', 'label' => 'Orders'],
                    ['table' => 'payments', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'customer_id', 'label' => 'Payments'],
                    ['table' => 'cylinder_transactions', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'customer_id', 'label' => 'Cylinder Transactions'],
                    ['table' => 'deposit_receipts', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'customer_id', 'label' => 'Deposit Receipts'],
                    ['table' => 'rental_returns', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'customer_id', 'label' => 'Rental Returns'],
                ],
                'query_templates' => [
                    'search' => "SELECT * FROM customers WHERE name LIKE ? OR mobile LIKE ? LIMIT 20",
                    'detail' => "SELECT * FROM customers WHERE id = ?",
                    'orders' => "SELECT ro.*, COUNT(roi.id) AS items_count FROM refill_orders ro LEFT JOIN refill_order_items roi ON ro.id = roi.refill_order_id WHERE ro.customer_id = ? GROUP BY ro.id ORDER BY ro.created_at DESC LIMIT 20",
                    'cylinders_assigned' => "SELECT c.*, gt.name AS gas_name FROM cylinders c JOIN gas_types gt ON c.gas_type_id = gt.id WHERE c.current_customer_id = ?",
                    'spending_summary' => "SELECT YEAR(created_at) AS yr, MONTH(created_at) AS mo, SUM(grand_total) AS spent, COUNT(*) AS orders FROM refill_orders WHERE customer_id = ? GROUP BY yr, mo ORDER BY yr DESC, mo DESC LIMIT 12",
                    'payment_history' => "SELECT * FROM payments WHERE customer_id = ? ORDER BY payment_date DESC LIMIT 20",
                    'deposit_summary' => "SELECT * FROM deposit_receipts WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10",
                    'lifetime_value' => "SELECT c.id, c.name, c.mobile, COUNT(DISTINCT ro.id) AS total_orders, COALESCE(SUM(ro.grand_total), 0) AS lifetime_spent, MAX(ro.created_at) AS last_order_date FROM customers c LEFT JOIN refill_orders ro ON c.id = ro.customer_id WHERE c.id = ? GROUP BY c.id",
                ],
            ],
            'refill_orders' => [
                'label' => 'Refill Order',
                'label_plural' => 'Refill Orders',
                'description' => 'Sales orders for gas refills: contains line items, totals, payment tracking',
                'search_columns' => ['id', 'order_number'],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'customers', 'type' => 'belongs_to', 'key' => 'customer_id', 'foreign' => 'id', 'label' => 'Customer'],
                    ['table' => 'refill_order_items', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'refill_order_id', 'label' => 'Order Items'],
                ],
                'query_templates' => [
                    'detail' => "SELECT ro.*, c.name AS customer_name, c.mobile AS customer_mobile FROM refill_orders ro JOIN customers c ON ro.customer_id = c.id WHERE ro.id = ? OR ro.order_number = ?",
                    'with_items' => "SELECT ro.*, c.name AS customer_name, c.mobile AS customer_mobile, roi.*, gt.name AS gas_name FROM refill_orders ro JOIN customers c ON ro.customer_id = c.id JOIN refill_order_items roi ON ro.id = roi.refill_order_id JOIN gas_types gt ON roi.gas_type_id = gt.id WHERE ro.id = ? OR ro.order_number = ?",
                    'recent' => "SELECT ro.*, c.name AS customer_name FROM refill_orders ro JOIN customers c ON ro.customer_id = c.id ORDER BY ro.created_at DESC LIMIT 20",
                    'by_customer' => "SELECT ro.* FROM refill_orders ro WHERE ro.customer_id = ? ORDER BY ro.created_at DESC LIMIT 20",
                    'by_period' => "SELECT DATE(ro.created_at) AS day, COUNT(*) AS orders, SUM(ro.grand_total) AS revenue FROM refill_orders ro WHERE ro.created_at >= ? GROUP BY DATE(ro.created_at) ORDER BY day",
                ],
            ],
            'refill_order_items' => [
                'label' => 'Order Item',
                'label_plural' => 'Order Items',
                'description' => 'Line items within refill orders: gas type, quantity, cylinder assignment',
                'search_columns' => [],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'refill_orders', 'type' => 'belongs_to', 'key' => 'refill_order_id', 'foreign' => 'id', 'label' => 'Order'],
                    ['table' => 'gas_types', 'type' => 'belongs_to', 'key' => 'gas_type_id', 'foreign' => 'id', 'label' => 'Gas Type'],
                    ['table' => 'cylinders', 'type' => 'belongs_to', 'key' => 'cylinder_id', 'foreign' => 'id', 'label' => 'Cylinder'],
                ],
            ],
            'gas_types' => [
                'label' => 'Gas Type',
                'label_plural' => 'Gas Types',
                'description' => 'Gas product catalog: names, sizes, pricing per gas type',
                'search_columns' => ['name'],
                'identifier' => 'name',
                'relationships' => [
                    ['table' => 'cylinders', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'gas_type_id', 'label' => 'Cylinders'],
                    ['table' => 'inventory', 'type' => 'has_one', 'key' => 'id', 'foreign' => 'gas_type_id', 'label' => 'Inventory'],
                    ['table' => 'refill_order_items', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'gas_type_id', 'label' => 'Order Items'],
                ],
                'query_templates' => [
                    'list' => "SELECT * FROM gas_types ORDER BY name",
                    'with_stock' => "SELECT gt.*, i.total_stock, i.total_filled, i.total_empty, i.min_alert_threshold FROM gas_types gt LEFT JOIN inventory i ON gt.id = i.gas_type_id ORDER BY gt.name",
                ],
            ],
            'inventory' => [
                'label' => 'Inventory',
                'label_plural' => 'Inventory',
                'description' => 'Current stock levels per gas type: filled, empty, total, and alert thresholds',
                'search_columns' => [],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'gas_types', 'type' => 'belongs_to', 'key' => 'gas_type_id', 'foreign' => 'id', 'label' => 'Gas Type'],
                ],
                'query_templates' => [
                    'summary' => "SELECT i.*, gt.name AS gas_name FROM inventory i JOIN gas_types gt ON i.gas_type_id = gt.id ORDER BY gt.name",
                    'low_stock' => "SELECT i.*, gt.name AS gas_name, (i.min_alert_threshold - i.total_stock) AS shortage FROM inventory i JOIN gas_types gt ON i.gas_type_id = gt.id WHERE i.total_stock < i.min_alert_threshold ORDER BY shortage DESC",
                ],
            ],
            'payments' => [
                'label' => 'Payment',
                'label_plural' => 'Payments',
                'description' => 'Payment records with method and amount tracking',
                'search_columns' => ['transaction_id', 'reference_number'],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'customers', 'type' => 'belongs_to', 'key' => 'customer_id', 'foreign' => 'id', 'label' => 'Customer'],
                ],
                'query_templates' => [
                    'by_customer' => "SELECT * FROM payments WHERE customer_id = ? ORDER BY payment_date DESC LIMIT 20",
                    'by_method' => "SELECT payment_method, COUNT(*) AS count, SUM(amount) AS total FROM payments GROUP BY payment_method",
                    'recent' => "SELECT p.*, c.name AS customer_name FROM payments p JOIN customers c ON p.customer_id = c.id ORDER BY p.payment_date DESC LIMIT 20",
                ],
            ],
            'partners' => [
                'label' => 'Partner',
                'label_plural' => 'Partners',
                'description' => 'Business partners for cylinder exchange (borrow/lend) with balance tracking',
                'search_columns' => ['company_name', 'contact_person', 'mobile'],
                'identifier' => 'company_name',
                'relationships' => [
                    ['table' => 'partner_transactions', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'partner_id', 'label' => 'Transactions'],
                    ['table' => 'cylinders', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'current_partner_id', 'label' => 'Cylinders with Partner'],
                    ['table' => 'partner_transaction_items', 'type' => 'has_many_through', 'key' => 'id', 'foreign' => 'partner_id', 'via' => 'partner_transactions', 'label' => 'Transaction Line Items'],
                ],
                'query_templates' => [
                    'search' => "SELECT * FROM partners WHERE company_name LIKE ? OR contact_person LIKE ? OR mobile LIKE ? LIMIT 20",
                    'detail' => "SELECT p.*, (SELECT COUNT(*) FROM cylinders WHERE current_partner_id = p.id) AS cylinders_held FROM partners p WHERE p.id = ?",
                    'exchange_balance' => "SELECT p.id, p.company_name, COALESCE(SUM(CASE WHEN pt.transaction_type IN ('borrowed_from_partner', 'lent_to_partner') THEN pt.cylinder_count ELSE 0 END), 0) AS borrowed, COALESCE(SUM(CASE WHEN pt.transaction_type IN ('returned_to_partner', 'received_back_from_partner') THEN pt.cylinder_count ELSE 0 END), 0) AS returned, (COALESCE(SUM(CASE WHEN pt.transaction_type IN ('borrowed_from_partner', 'lent_to_partner') THEN pt.cylinder_count ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN pt.transaction_type IN ('returned_to_partner', 'received_back_from_partner') THEN pt.cylinder_count ELSE 0 END), 0)) AS net_balance FROM partners p LEFT JOIN partner_transactions pt ON p.id = pt.partner_id GROUP BY p.id, p.company_name ORDER BY net_balance DESC",
                    'transactions' => "SELECT pt.*, p.company_name FROM partner_transactions pt JOIN partners p ON pt.partner_id = p.id WHERE p.id = ? ORDER BY pt.transaction_date DESC LIMIT 50",
                    'transaction_items' => "SELECT pti.*, c.serial_number, gt.name AS gas_name, pt.transaction_date, pt.transaction_type FROM partner_transaction_items pti JOIN cylinders c ON pti.cylinder_id = c.id JOIN gas_types gt ON pti.gas_type_id = gt.id JOIN partner_transactions pt ON pti.transaction_id = pt.id WHERE pt.partner_id = ? ORDER BY pt.transaction_date DESC LIMIT 30",
                ],
            ],
            'partner_transactions' => [
                'label' => 'Partner Transaction',
                'label_plural' => 'Partner Transactions',
                'description' => 'Track cylinder exchange operations between business and partners: borrow, lend, return',
                'search_columns' => ['reference_number', 'notes'],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'partners', 'type' => 'belongs_to', 'key' => 'partner_id', 'foreign' => 'id', 'label' => 'Partner'],
                ],
                'query_templates' => [
                    'recent' => "SELECT pt.*, p.company_name FROM partner_transactions pt JOIN partners p ON pt.partner_id = p.id ORDER BY pt.transaction_date DESC LIMIT 20",
                    'by_partner' => "SELECT * FROM partner_transactions WHERE partner_id = ? ORDER BY transaction_date DESC LIMIT 50",
                ],
            ],
            'vendors' => [
                'label' => 'Vendor',
                'label_plural' => 'Vendors',
                'description' => 'Gas filling vendors that refill empty cylinders',
                'search_columns' => ['name', 'contact_person', 'mobile'],
                'identifier' => 'name',
                'relationships' => [
                    ['table' => 'cylinders', 'type' => 'has_many', 'key' => 'id', 'foreign' => 'vendor_id', 'label' => 'Cylinders Sent'],
                ],
                'query_templates' => [
                    'list' => "SELECT * FROM vendors ORDER BY name",
                    'detail' => "SELECT v.*, (SELECT COUNT(*) FROM cylinders WHERE vendor_id = v.id) AS cylinders_sent FROM vendors v WHERE v.id = ?",
                ],
            ],
            'deposit_receipts' => [
                'label' => 'Deposit Receipt',
                'label_plural' => 'Deposit Receipts',
                'description' => 'Customer deposit receipts for cylinder security deposits',
                'search_columns' => ['receipt_number'],
                'identifier' => 'receipt_number',
                'relationships' => [
                    ['table' => 'customers', 'type' => 'belongs_to', 'key' => 'customer_id', 'foreign' => 'id', 'label' => 'Customer'],
                ],
            ],
            'rental_returns' => [
                'label' => 'Rental Return',
                'label_plural' => 'Rental Returns',
                'description' => 'Rental cylinder return records with condition and rent calculation',
                'search_columns' => ['receipt_number'],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'customers', 'type' => 'belongs_to', 'key' => 'customer_id', 'foreign' => 'id', 'label' => 'Customer'],
                    ['table' => 'cylinders', 'type' => 'belongs_to', 'key' => 'cylinder_id', 'foreign' => 'id', 'label' => 'Cylinder'],
                ],
            ],
            'cylinder_audit_log' => [
                'label' => 'Cylinder Audit Log',
                'label_plural' => 'Cylinder Audit Logs',
                'description' => 'Full event history for every cylinder: status changes, exchanges, maintenance, ownership transfers, rentals',
                'search_columns' => ['serial_number', 'notes', 'counterparty_name'],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'cylinders', 'type' => 'belongs_to', 'key' => 'cylinder_id', 'foreign' => 'id', 'label' => 'Cylinder'],
                ],
                'query_templates' => [
                    'by_cylinder' => "SELECT cal.* FROM cylinder_audit_log cal WHERE cal.serial_number LIKE ? ORDER BY cal.logged_at DESC LIMIT 50",
                    'by_event' => "SELECT cal.*, c.serial_number FROM cylinder_audit_log cal JOIN cylinders c ON cal.cylinder_id = c.id WHERE cal.event_type = ? ORDER BY cal.logged_at DESC LIMIT 50",
                    'recent' => "SELECT cal.*, c.serial_number FROM cylinder_audit_log cal JOIN cylinders c ON cal.cylinder_id = c.id ORDER BY cal.logged_at DESC LIMIT 20",
                    'cylinder_timeline' => "SELECT cal.* FROM cylinder_audit_log cal WHERE cal.serial_number LIKE ? ORDER BY cal.logged_at ASC",
                ],
            ],
            'deleted_cylinders' => [
                'label' => 'Deleted Cylinder',
                'label_plural' => 'Deleted Cylinders',
                'description' => 'Soft-deleted cylinders (deleted_at IS NOT NULL) with full deletion metadata and transaction log',
                'search_columns' => ['serial_number', 'barcode'],
                'identifier' => 'serial_number',
                'relationships' => [
                    ['table' => 'cylinders', 'type' => 'same_table', 'key' => 'id', 'foreign' => 'id', 'label' => 'Cylinder (soft-deleted)'],
                ],
                'query_templates' => [
                    'search' => "SELECT * FROM cylinders WHERE deleted_at IS NOT NULL AND (serial_number LIKE ? OR barcode LIKE ?) LIMIT 20",
                    'recent' => "SELECT * FROM cylinders WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 20",
                    'by_date' => "SELECT * FROM cylinders WHERE deleted_at >= ? ORDER BY deleted_at DESC LIMIT 50",
                ],
            ],
            'partner_transaction_items' => [
                'label' => 'Partner Transaction Item',
                'label_plural' => 'Partner Transaction Items',
                'description' => 'Per-cylinder line items within partner borrow/lend transactions, with rent tracking per cylinder',
                'search_columns' => ['serial_number'],
                'identifier' => 'id',
                'relationships' => [
                    ['table' => 'partner_transactions', 'type' => 'belongs_to', 'key' => 'transaction_id', 'foreign' => 'id', 'label' => 'Parent Transaction'],
                    ['table' => 'cylinders', 'type' => 'belongs_to', 'key' => 'cylinder_id', 'foreign' => 'id', 'label' => 'Cylinder'],
                    ['table' => 'gas_types', 'type' => 'belongs_to', 'key' => 'gas_type_id', 'foreign' => 'id', 'label' => 'Gas Type'],
                ],
                'query_templates' => [
                    'by_transaction' => "SELECT pti.*, c.serial_number, gt.name AS gas_name FROM partner_transaction_items pti JOIN cylinders c ON pti.cylinder_id = c.id JOIN gas_types gt ON pti.gas_type_id = gt.id WHERE pti.transaction_id = ? ORDER BY pti.id ASC",
                    'by_cylinder' => "SELECT pti.*, pt.transaction_date, p.company_name FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id = pt.id JOIN partners p ON pt.partner_id = p.id WHERE pti.serial_number LIKE ? ORDER BY pt.transaction_date DESC LIMIT 30",
                    'rent_outstanding' => "SELECT pti.*, c.serial_number, p.company_name FROM partner_transaction_items pti JOIN cylinders c ON pti.cylinder_id = c.id JOIN partner_transactions pt ON pti.transaction_id = pt.id JOIN partners p ON pt.partner_id = p.id WHERE pti.rent_accrued > pti.rent_paid ORDER BY (pti.rent_accrued - pti.rent_paid) DESC",
                ],
            ],
        ];
    }
}

if (!function_exists('getEntityDefinition')) {
    function getEntityDefinition($entityKey) {
        $registry = getEntityRegistry();
        return $registry[$entityKey] ?? null;
    }
}

if (!function_exists('getRelatedForEntity')) {
    function getRelatedForEntity($entityKey) {
        $def = getEntityDefinition($entityKey);
        return $def ? ($def['relationships'] ?? []) : [];
    }
}

if (!function_exists('getQueryTemplate')) {
    function getQueryTemplate($entityKey, $templateKey) {
        $def = getEntityDefinition($entityKey);
        if (!$def || empty($def['query_templates'][$templateKey])) return null;
        return $def['query_templates'][$templateKey];
    }
}

if (!function_exists('searchEntitiesRegistry')) {
    function searchEntitiesRegistry($searchTerm) {
        $registry = getEntityRegistry();
        $results = [];
        foreach ($registry as $key => $def) {
            foreach ($def['search_columns'] ?? [] as $col) {
                if (stripos($col, $searchTerm) !== false || stripos($key, $searchTerm) !== false) {
                    $results[$key] = $def;
                    break;
                }
            }
        }
        return $results;
    }
}

if (!function_exists('formatRegistrySchemaForPrompt')) {
    function formatRegistrySchemaForPrompt() {
        $registry = getEntityRegistry();
        $lines = [];
        $lines[] = "=== ENTITY REGISTRY (Business Context) ===";

        foreach ($registry as $key => $def) {
            $lines[] = "";
            $lines[] = strtoupper($key) . " - {$def['label']}";
            $lines[] = "  Description: {$def['description']}";
            $lines[] = "  Search by: " . implode(', ', $def['search_columns']);

            if (!empty($def['relationships'])) {
                $lines[] = "  Relationships:";
                foreach ($def['relationships'] as $rel) {
                    $lines[] = "    - {$rel['type']} {$rel['table']} ({$rel['label']}) via {$rel['key']} -> {$rel['foreign']}";
                }
            }

            $lines[] = "  JOIN patterns:";
            switch ($key) {
                case 'cylinders':
                    $lines[] = "    - cylinders + gas_types: cylinders.gas_type_id = gas_types.id";
                    $lines[] = "    - cylinders + customers: cylinders.current_customer_id = customers.id";
                    $lines[] = "    - cylinders + cylinder_transactions: cylinders.id = cylinder_transactions.cylinder_id";
                    $lines[] = "    - cylinders + refill_order_items: cylinders.id = refill_order_items.cylinder_id";
                    break;
                case 'customers':
                    $lines[] = "    - customers + refill_orders: customers.id = refill_orders.customer_id";
                    $lines[] = "    - customers + cylinder_transactions: customers.id = cylinder_transactions.customer_id";
                    $lines[] = "    - customers + payments: customers.id = payments.customer_id";
                    break;
                case 'refill_orders':
                    $lines[] = "    - refill_orders + refill_order_items: refill_orders.id = refill_order_items.refill_order_id";
                    $lines[] = "    - refill_orders + customers: refill_orders.customer_id = customers.id";
                    break;
                case 'refill_order_items':
                    $lines[] = "    - refill_order_items + gas_types: refill_order_items.gas_type_id = gas_types.id";
                    $lines[] = "    - refill_order_items + cylinders: refill_order_items.cylinder_id = cylinders.id";
                    break;
                case 'partners':
                    $lines[] = "    - partners + partner_transactions: partners.id = partner_transactions.partner_id";
                    $lines[] = "    - partners + cylinders: partners.id = cylinders.current_partner_id";
                    break;
                case 'cylinder_audit_log':
                    $lines[] = "    - cylinder_audit_log + cylinders: cylinder_audit_log.cylinder_id = cylinders.id";
                    break;
                case 'deleted_cylinders':
                    $lines[] = "    - deleted_cylinders: same as cylinders WHERE deleted_at IS NOT NULL (soft-delete)";
                    break;
                case 'partner_transaction_items':
                    $lines[] = "    - partner_transaction_items + cylinders: partner_transaction_items.cylinder_id = cylinders.id";
                    $lines[] = "    - partner_transaction_items + partner_transactions: partner_transaction_items.transaction_id = partner_transactions.id";
                    $lines[] = "    - partner_transaction_items + gas_types: partner_transaction_items.gas_type_id = gas_types.id";
                    $lines[] = "    - partner_transaction_items -> partners via partner_transactions: partner_transaction_items.transaction_id = partner_transactions.id AND partner_transactions.partner_id = partners.id";
                    break;
                case 'gas_types':
                    $lines[] = "    - gas_types + cylinders: gas_types.id = cylinders.gas_type_id";
                    $lines[] = "    - gas_types + inventory: gas_types.id = inventory.gas_type_id";
                    break;
            }
        }

        $lines[] = "";
        $lines[] = "=== MULTI-TABLE QUERY PATTERNS ===";
        $lines[] = "Cylinder Lifecycle: cylinders -> cylinder_transactions -> customers -> refill_orders";
        $lines[] = "  SELECT c.serial_number, ct.transaction_type, ct.transaction_date, cust.name AS customer_name FROM cylinders c LEFT JOIN cylinder_transactions ct ON c.id = ct.cylinder_id LEFT JOIN customers cust ON ct.customer_id = cust.id WHERE c.serial_number = ? ORDER BY ct.transaction_date DESC";
        $lines[] = "";
        $lines[] = "Sales with Customer: refill_orders -> customers -> refill_order_items -> gas_types";
        $lines[] = "  SELECT ro.id, c.name AS customer_name, c.mobile, roi.qty, gt.name AS gas_type, ro.grand_total, ro.created_at FROM refill_orders ro JOIN customers c ON ro.customer_id = c.id JOIN refill_order_items roi ON ro.id = roi.refill_order_id JOIN gas_types gt ON roi.gas_type_id = gt.id WHERE ro.created_at >= ? ORDER BY ro.created_at DESC";
        $lines[] = "";
        $lines[] = "Customer Lifetime: customers -> refill_orders -> payments";
        $lines[] = "  SELECT c.name, c.mobile, COUNT(DISTINCT ro.id) AS orders, SUM(ro.grand_total) AS total_spent, MAX(ro.created_at) AS last_order FROM customers c LEFT JOIN refill_orders ro ON c.id = ro.customer_id WHERE c.id = ? GROUP BY c.id";
        $lines[] = "";
        $lines[] = "Partner Exchange Balance: partners -> partner_transactions -> cylinders";
        $lines[] = "  SELECT p.company_name, COUNT(pt.id) AS transactions, SUM(CASE WHEN pt.transaction_type LIKE '%borrow%' THEN pt.cylinder_count ELSE 0 END) AS total_in, SUM(CASE WHEN pt.transaction_type LIKE '%return%' THEN pt.cylinder_count ELSE 0 END) AS total_out FROM partners p JOIN partner_transactions pt ON p.id = pt.partner_id GROUP BY p.id";
        $lines[] = "";
        $lines[] = "Stock with Gas Info: inventory -> gas_types";
        $lines[] = "  SELECT gt.name, i.total_stock, i.total_filled, i.total_empty, i.min_alert_threshold FROM inventory i JOIN gas_types gt ON i.gas_type_id = gt.id ORDER BY gt.name";
        $lines[] = "";
        $lines[] = "Payment Method Breakdown: payments";
        $lines[] = "  SELECT p.payment_method, COUNT(*) AS tx_count, SUM(p.amount) AS total_amount FROM payments p GROUP BY p.payment_method ORDER BY total_amount DESC";
        $lines[] = "";
        $lines[] = "Partner Transaction Items: partners -> partner_transactions -> partner_transaction_items -> cylinders";
        $lines[] = "  SELECT p.company_name, pt.transaction_date, pt.transaction_type, pti.serial_number, pti.status, pti.rent_accrued, pti.rent_paid, gt.name AS gas_name FROM partners p JOIN partner_transactions pt ON p.id = pt.partner_id JOIN partner_transaction_items pti ON pt.id = pti.transaction_id JOIN cylinders c ON pti.cylinder_id = c.id JOIN gas_types gt ON pti.gas_type_id = gt.id WHERE p.company_name LIKE ? ORDER BY pt.transaction_date DESC";

        return implode("\n", $lines);
    }
}

if (!function_exists('getDrillDownQueries')) {
    function getDrillDownQueries($pdo, $entityType, $entityValue) {
        $registry = getEntityRegistry();
        $queries = [];

        switch ($entityType) {
            case 'cylinder_serial':
            case 'cylinder':
                $queries[] = [
                    'key' => 'cylinder_details',
                    'label' => 'Cylinder Details',
                    'sql' => "SELECT c.*, gt.name AS gas_name, v.name AS vendor_name, cust.name AS current_customer_name, p.company_name AS current_partner_name FROM cylinders c LEFT JOIN gas_types gt ON c.gas_type_id = gt.id LEFT JOIN vendors v ON c.vendor_id = v.id LEFT JOIN customers cust ON c.current_customer_id = cust.id LEFT JOIN partners p ON c.current_partner_id = p.id WHERE c.serial_number = ? OR c.barcode = ? LIMIT 1",
                    'params' => [$entityValue, $entityValue],
                ];
                $queries[] = [
                    'key' => 'cylinder_transactions',
                    'label' => 'Transaction History',
                    'sql' => "SELECT ct.*, c.serial_number FROM cylinder_transactions ct JOIN cylinders c ON ct.cylinder_id = c.id WHERE c.serial_number = ? ORDER BY ct.transaction_date DESC LIMIT 20",
                    'params' => [$entityValue],
                ];
                $queries[] = [
                    'key' => 'cylinder_audit',
                    'label' => 'Full Audit Trail',
                    'sql' => "SELECT cal.* FROM cylinder_audit_log cal WHERE cal.serial_number = ? ORDER BY cal.logged_at DESC LIMIT 50",
                    'params' => [$entityValue],
                ];
                $queries[] = [
                    'key' => 'partner_line_items',
                    'label' => 'Partner Transaction Items',
                    'sql' => "SELECT pti.*, p.company_name, pt.transaction_date, pt.transaction_type FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id = pt.id JOIN partners p ON pt.partner_id = p.id WHERE pti.cylinder_id = (SELECT id FROM cylinders WHERE serial_number = ?) ORDER BY pt.transaction_date DESC LIMIT 20",
                    'params' => [$entityValue],
                ];
                break;

            case 'customer_name':
            case 'customer_mobile':
            case 'customer':
                $queries[] = [
                    'key' => 'customer_details',
                    'label' => 'Customer Details',
                    'sql' => "SELECT * FROM customers WHERE name LIKE ? OR mobile LIKE ? LIMIT 5",
                    'params' => ["%$entityValue%", "%$entityValue%"],
                ];
                $queries[] = [
                    'key' => 'customer_orders',
                    'label' => 'Recent Orders',
                    'sql' => "SELECT ro.*, COUNT(roi.id) AS items_count FROM refill_orders ro LEFT JOIN refill_order_items roi ON ro.id = roi.refill_order_id WHERE ro.customer_id IN (SELECT id FROM customers WHERE name LIKE ? OR mobile LIKE ?) GROUP BY ro.id ORDER BY ro.created_at DESC LIMIT 10",
                    'params' => ["%$entityValue%", "%$entityValue%"],
                ];
                break;

            case 'invoice_number':
            case 'invoice':
                $queries[] = [
                    'key' => 'invoice_details',
                    'label' => 'Invoice Details',
                    'sql' => "SELECT ro.*, c.name AS customer_name, c.mobile FROM refill_orders ro JOIN customers c ON ro.customer_id = c.id WHERE ro.invoice_number LIKE ? LIMIT 5",
                    'params' => ["%$entityValue%"],
                ];
                break;

            case 'gas_type':
                $queries[] = [
                    'key' => 'gas_stock',
                    'label' => 'Gas Stock Overview',
                    'sql' => "SELECT gt.*, i.total_stock, i.total_filled, i.total_empty, i.min_alert_threshold, COUNT(c.id) AS total_cylinders FROM gas_types gt LEFT JOIN inventory i ON gt.id = i.gas_type_id LEFT JOIN cylinders c ON gt.id = c.gas_type_id WHERE gt.name LIKE ? GROUP BY gt.id LIMIT 5",
                    'params' => ["%$entityValue%"],
                ];
                break;

            case 'partner_name':
            case 'partner':
                $queries[] = [
                    'key' => 'partner_details',
                    'label' => 'Partner Details',
                    'sql' => "SELECT p.*, (SELECT COUNT(*) FROM cylinders WHERE current_partner_id = p.id) AS cylinders_held FROM partners p WHERE p.company_name LIKE ? OR p.contact_person LIKE ? LIMIT 5",
                    'params' => ["%$entityValue%", "%$entityValue%"],
                ];
                $queries[] = [
                    'key' => 'partner_transactions',
                    'label' => 'Recent Partner Transactions',
                    'sql' => "SELECT pt.*, p.company_name FROM partner_transactions pt JOIN partners p ON pt.partner_id = p.id WHERE p.company_name LIKE ? OR p.contact_person LIKE ? ORDER BY pt.transaction_date DESC LIMIT 20",
                    'params' => ["%$entityValue%", "%$entityValue%"],
                ];
                $queries[] = [
                    'key' => 'partner_transaction_items',
                    'label' => 'Transaction Line Items',
                    'sql' => "SELECT pti.*, c.serial_number, gt.name AS gas_name, pt.transaction_date, pt.transaction_type FROM partner_transaction_items pti JOIN cylinders c ON pti.cylinder_id = c.id JOIN gas_types gt ON pti.gas_type_id = gt.id JOIN partner_transactions pt ON pti.transaction_id = pt.id WHERE pt.partner_id IN (SELECT id FROM partners WHERE company_name LIKE ? OR contact_person LIKE ?) ORDER BY pt.transaction_date DESC LIMIT 30",
                    'params' => ["%$entityValue%", "%$entityValue%"],
                ];
                break;

            case 'cylinder_audit':
            case 'audit_log':
                $queries[] = [
                    'key' => 'audit_details',
                    'label' => 'Audit Log Entry Details',
                    'sql' => "SELECT cal.*, c.serial_number, c.barcode FROM cylinder_audit_log cal JOIN cylinders c ON cal.cylinder_id = c.id WHERE cal.id = ? LIMIT 1",
                    'params' => [(int)$entityValue],
                ];
                $queries[] = [
                    'key' => 'audit_cylinder_timeline',
                    'label' => 'Full Cylinder Timeline',
                    'sql' => "SELECT cal.* FROM cylinder_audit_log cal WHERE cal.serial_number = (SELECT serial_number FROM cylinder_audit_log WHERE id = ?) ORDER BY cal.logged_at ASC",
                    'params' => [(int)$entityValue],
                ];
                break;

            case 'partner_transaction_item':
            case 'transaction_item':
                $queries[] = [
                    'key' => 'item_details',
                    'label' => 'Transaction Item Details',
                    'sql' => "SELECT pti.*, c.serial_number, c.barcode, gt.name AS gas_name, p.company_name, pt.transaction_date, pt.transaction_type FROM partner_transaction_items pti JOIN cylinders c ON pti.cylinder_id = c.id JOIN gas_types gt ON pti.gas_type_id = gt.id JOIN partner_transactions pt ON pti.transaction_id = pt.id JOIN partners p ON pt.partner_id = p.id WHERE pti.id = ? LIMIT 1",
                    'params' => [(int)$entityValue],
                ];
                break;
        }

        return $queries;
    }
}

if (!function_exists('getEntityLabels')) {
    function getEntityLabels() {
        $registry = getEntityRegistry();
        $labels = [];
        foreach ($registry as $key => $def) {
            $labels[$key] = $def['label'];
        }
        return $labels;
    }
}

if (!function_exists('getIntentForEntity')) {
    function getIntentForEntity($entityType) {
        $map = [
            'cylinder_serial' => 'cylinder_tracking',
            'cylinder_barcode' => 'cylinder_tracking',
            'cylinder' => 'cylinder_tracking',
            'customer_name' => 'customer_lookup',
            'customer_mobile' => 'customer_lookup',
            'customer' => 'customer_lookup',
            'invoice_number' => 'invoice_lookup',
            'invoice' => 'invoice_lookup',
            'gas_type' => 'stock_inquiry',
            'vendor_name' => 'stock_inquiry',
            'partner_name' => 'borrow_lent',
            'partner' => 'borrow_lent',
            'payment_id' => 'sales_analytics',
            'order_id' => 'sales_analytics',
            'deposit_receipt' => 'customer_lookup',
            'cylinder_audit' => 'cylinder_tracking',
            'audit_log' => 'cylinder_tracking',
            'partner_transaction_item' => 'borrow_lent',
            'transaction_item' => 'borrow_lent',
        ];
        return $map[$entityType] ?? 'general';
    }
}
