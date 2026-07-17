<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/db.php';

// ── Date Range Parsing ──
$range = $_GET['range'] ?? 'month';
$customFrom = trim($_GET['from'] ?? '');
$customTo = trim($_GET['to'] ?? '');

$today = date('Y-m-d');
switch ($range) {
    case 'today':
        $dateFrom = $today;
        $dateTo = $today;
        $prevDateFrom = date('Y-m-d', strtotime('-1 day'));
        $prevDateTo = date('Y-m-d', strtotime('-1 day'));
        $periodLabel = date('d M Y');
        break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('monday this week'));
        $dateTo = $today;
        $prevDateFrom = date('Y-m-d', strtotime('monday this week -7 days'));
        $prevDateTo = date('Y-m-d', strtotime('sunday this week -7 days'));
        $periodLabel = date('d M', strtotime($dateFrom)) . ' \u2014 ' . date('d M Y');
        break;
    case 'year':
        $dateFrom = date('Y-01-01');
        $dateTo = date('Y-12-31');
        $prevDateFrom = date('Y-01-01', strtotime('-1 year'));
        $prevDateTo = date('Y-12-31', strtotime('-1 year'));
        $periodLabel = date('Y');
        break;
    case 'custom':
        $dateFrom = $customFrom ?: $today;
        $dateTo = $customTo ?: $today;
        $periodDays = max(1, (int)((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1);
        $prevDateFrom = date('Y-m-d', strtotime($dateFrom . ' -' . $periodDays . ' days'));
        $prevDateTo = date('Y-m-d', strtotime($dateFrom . ' -1 day'));
        $periodLabel = date('d M Y', strtotime($dateFrom)) . ' \u2014 ' . date('d M Y', strtotime($dateTo));
        break;
    default: // month
        $range = 'month';
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
        $prevDateFrom = date('Y-m-01', strtotime('-1 month'));
        $prevDateTo = date('Y-m-t', strtotime('-1 month'));
        $periodLabel = date('M Y');
}

// ── Clean up old monolithic cache ──
$oldCache = __DIR__ . '/../cache/dashboard_ajax.json';
if (file_exists($oldCache)) { @unlink($oldCache); }

// ── Stale-while-revalidate cache (keyed by date range) ──
$dashRange = $range;
if ($range === 'custom') $dashRange .= '_' . md5($customFrom . $customTo);

$forceFresh = !empty($_GET['fresh']);
$swrServed = false;
$swrCache = __DIR__ . "/../cache/dashboard_swr_{$dashRange}.json";
$swrTTL = 15;

// ── Skip SWR cache when fresh=1 is sent (page load, manual refresh) ──
if (!$forceFresh && file_exists($swrCache)) {
    $swrAge = time() - filemtime($swrCache);
    $swrJson = file_get_contents($swrCache);

    if ($swrAge < $swrTTL) {
        echo $swrJson;
        exit;
    }

    $swrLock = __DIR__ . '/../cache/dashboard_swr.lock';
    $swrFp = @fopen($swrLock, 'c');
    $swrCanRegen = $swrFp && flock($swrFp, LOCK_EX | LOCK_NB);

    if (!$swrFp) { echo $swrJson; exit; }

    header('Content-Length: ' . strlen($swrJson));
    echo $swrJson;
    $swrServed = true;

    while (ob_get_level()) ob_end_flush();
    flush();

    if ($swrCanRegen) {
        flock($swrFp, LOCK_UN); fclose($swrFp); @unlink($swrLock);
        ignore_user_abort(true);
        set_time_limit(120);
        foreach (['kpis','cylinders','revenue','financial','orders','customers','vendors','inventory','activity','alerts'] as $sk) {
            foreach (['month', 'today', 'week', 'year'] as $rk) {
                $sf = __DIR__ . "/../cache/dash_{$sk}_{$rk}.json";
                if (file_exists($sf)) @unlink($sf);
            }
            $sf = __DIR__ . "/../cache/dash_{$sk}.json";
            if (file_exists($sf)) @unlink($sf);
        }
        ob_start();
    } else {
        fclose($swrFp);
        exit;
    }
}

// ── Partial cache helpers (range-aware) ──
$cacheDir = __DIR__ . '/../cache/';
function secFresh($key, $ttl) {
    global $cacheDir, $dashRange, $forceFresh;
    if ($forceFresh) return false;
    $f = $cacheDir . 'dash_' . $key . '_' . $dashRange . '.json';
    return file_exists($f) && (time() - filemtime($f)) < $ttl;
}
function secLoad($key) {
    global $cacheDir, $dashRange;
    $f = $cacheDir . 'dash_' . $key . '_' . $dashRange . '.json';
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}
function secSave($key, $data) {
    global $cacheDir, $dashRange;
    file_put_contents($cacheDir . 'dash_' . $key . '_' . $dashRange . '.json', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ── Per-section TTLs ──
define('TTL_KPIS', 30);
define('TTL_CYLINDERS', 60);
define('TTL_REVENUE', 30);
define('TTL_FINANCIAL', 60);
define('TTL_ORDERS', 30);
define('TTL_CUSTOMERS', 60);
define('TTL_VENDORS', 60);
define('TTL_INVENTORY', 60);
define('TTL_ALERTS', 60);
define('TTL_ACTIVITY', 30);

function qv($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetch(PDO::FETCH_ASSOC); }
    catch (PDOException $e) { return false; }
}
function qva($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
    catch (PDOException $e) { return []; }
}
function qc($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn(); }
    catch (PDOException $e) { return 0; }
}
function pctChange($curr, $prev) {
    if ($prev > 0) return round((($curr - $prev) / $prev) * 100, 1);
    return $curr > 0 ? 100 : 0;
}

try {
    $monthStart = date('Y-m-01');
    $yearStart = date('Y-01-01');
    $prevMonthStart = date('Y-m-01', strtotime('-1 month'));
    $prevMonthEnd = date('Y-m-t', strtotime('-1 month'));

    // ════════════════════════════════════════════════════════
    // FINANCE SUMMARY — 8 KPIs (date-filtered, uncached)
    // ════════════════════════════════════════════════════════

    $totalRevenue = qc($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM refill_orders WHERE DATE(order_date) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
    $prevTotalRevenue = qc($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM refill_orders WHERE DATE(order_date) BETWEEN ? AND ?", [$prevDateFrom, $prevDateTo]);

    $generalExpenses = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ? AND is_deleted = 0 AND payment_status IN ('paid','unpaid','partial')", [$dateFrom, $dateTo]);
    $prevGeneralExpenses = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ? AND is_deleted = 0 AND payment_status IN ('paid','unpaid','partial')", [$prevDateFrom, $prevDateTo]);

    $refillCosts = qc($pdo, "SELECT COALESCE(SUM(x.c),0) FROM (
        SELECT COALESCE(SUM(final_refill_amount),0) AS c FROM dispatch_lots WHERE DATE(receive_date) BETWEEN ? AND ? AND lot_status IN ('completed','partial_return')
        UNION ALL
        SELECT COALESCE(SUM(total_cost),0) FROM vendor_refill_batches WHERE DATE(received_date) BETWEEN ? AND ? AND lot_id IS NULL
    ) x", [$dateFrom, $dateTo, $dateFrom, $dateTo]);
    $prevRefillCosts = qc($pdo, "SELECT COALESCE(SUM(x.c),0) FROM (
        SELECT COALESCE(SUM(final_refill_amount),0) AS c FROM dispatch_lots WHERE DATE(receive_date) BETWEEN ? AND ? AND lot_status IN ('completed','partial_return')
        UNION ALL
        SELECT COALESCE(SUM(total_cost),0) FROM vendor_refill_batches WHERE DATE(received_date) BETWEEN ? AND ? AND lot_id IS NULL
    ) x", [$prevDateFrom, $prevDateTo, $prevDateFrom, $prevDateTo]);

    // Proper P&L chain
    $grossProfit = $totalRevenue - $refillCosts;
    $prevGrossProfit = $prevTotalRevenue - $prevRefillCosts;

    $netProfit = $grossProfit - $generalExpenses;
    $prevNetProfit = $prevGrossProfit - $prevGeneralExpenses;

    $gstFinance = qv($pdo, "SELECT
        COALESCE(SUM(CASE WHEN input_output_type = 'output' THEN gst_amount ELSE 0 END),0) AS output_gst,
        COALESCE(SUM(CASE WHEN input_output_type = 'input' THEN gst_amount ELSE 0 END),0) AS input_gst
        FROM gst_ledger WHERE DATE(transaction_date) BETWEEN ? AND ?", [$dateFrom, $dateTo]) ?: ['output_gst' => 0, 'input_gst' => 0];
    $prevGstFinance = qv($pdo, "SELECT
        COALESCE(SUM(CASE WHEN input_output_type = 'output' THEN gst_amount ELSE 0 END),0) AS output_gst,
        COALESCE(SUM(CASE WHEN input_output_type = 'input' THEN gst_amount ELSE 0 END),0) AS input_gst
        FROM gst_ledger WHERE DATE(transaction_date) BETWEEN ? AND ?", [$prevDateFrom, $prevDateTo]) ?: ['output_gst' => 0, 'input_gst' => 0];

    $gstNetPayable = (float)$gstFinance['output_gst'] - (float)$gstFinance['input_gst'];
    $prevGstNetPayable = (float)$prevGstFinance['output_gst'] - (float)$prevGstFinance['input_gst'];

    $profitAfterGst = $netProfit - $gstNetPayable;
    $prevProfitAfterGst = $prevNetProfit - $prevGstNetPayable;

    $receivables = qc($pdo, "SELECT COALESCE(SUM(grand_total - COALESCE((SELECT SUM(amount) FROM payments WHERE refill_order_id = ro.id), 0)),0) FROM refill_orders ro WHERE payment_status IN ('pending','partial')");

    $vendorPayables = qc($pdo, "SELECT COALESCE(SUM(remaining_balance),0) FROM dispatch_lots WHERE payment_status IN ('unpaid','partial')");
    $expensePayables = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE payment_status IN ('unpaid','partial') AND is_deleted = 0");
    $payables = $vendorPayables + $expensePayables;

    $cashIn = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_method = 'cash' AND payment_type IN ('refill_payment','deposit_added')");
    $cashOut = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_method = 'cash' AND payment_type IN ('vendor_payment','deposit_refunded')");
    $cashExp = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE payment_method = 'cash' AND is_deleted = 0");
    $cashBalance = $cashIn - $cashOut - $cashExp;

    $orderCount = qc($pdo, "SELECT COUNT(*) FROM refill_orders WHERE DATE(order_date) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
    $prevOrderCount = qc($pdo, "SELECT COUNT(*) FROM refill_orders WHERE DATE(order_date) BETWEEN ? AND ?", [$prevDateFrom, $prevDateTo]);
    $avgOrderValue = $orderCount > 0 ? round($totalRevenue / $orderCount, 2) : 0;
    $prevAvgOrderValue = $prevOrderCount > 0 ? round($prevTotalRevenue / $prevOrderCount, 2) : 0;
    $grossMargin = $totalRevenue > 0 ? round(($grossProfit / $totalRevenue) * 100, 1) : 0;

    $financeSummary = [
        'period' => ['from' => $dateFrom, 'to' => $dateTo, 'label' => $periodLabel, 'range' => $range],
        'prev_period' => ['from' => $prevDateFrom, 'to' => $prevDateTo],
        'total_revenue'      => ['value' => $totalRevenue, 'prev' => $prevTotalRevenue, 'change' => pctChange($totalRevenue, $prevTotalRevenue)],
        'cogs'               => ['value' => $refillCosts, 'prev' => $prevRefillCosts, 'change' => pctChange($refillCosts, $prevRefillCosts)],
        'gross_profit'       => ['value' => $grossProfit, 'prev' => $prevGrossProfit, 'change' => pctChange($grossProfit, $prevGrossProfit)],
        'operating_expenses' => ['value' => $generalExpenses, 'prev' => $prevGeneralExpenses, 'change' => pctChange($generalExpenses, $prevGeneralExpenses)],
        'net_profit'         => ['value' => $netProfit, 'prev' => $prevNetProfit, 'change' => pctChange($netProfit, $prevNetProfit)],
        'gst_net'            => ['value' => $gstNetPayable, 'prev' => $prevGstNetPayable, 'change' => pctChange($gstNetPayable, $prevGstNetPayable)],
        'profit_after_gst'   => ['value' => $profitAfterGst, 'prev' => $prevProfitAfterGst, 'change' => pctChange($profitAfterGst, $prevProfitAfterGst)],
        'cash_balance'       => ['value' => $cashBalance, 'prev' => 0, 'change' => 0],
        'receivables'        => ['value' => $receivables, 'prev' => 0, 'change' => 0],
        'payables'           => ['value' => $payables, 'prev' => 0, 'change' => 0],
        'orders_count'       => ['value' => $orderCount, 'prev' => $prevOrderCount, 'change' => pctChange($orderCount, $prevOrderCount)],
        'avg_order_value'    => ['value' => $avgOrderValue, 'prev' => $prevAvgOrderValue, 'change' => pctChange($avgOrderValue, $prevAvgOrderValue)],
        'gross_margin'       => ['value' => $grossMargin],
    ];

    // ════════════════════════════════════════════════════════
    // SECTION 1: KPI — Legacy Business Health (cached, date-aware)
    // ════════════════════════════════════════════════════════
    if (secFresh('kpis', TTL_KPIS)) {
        $kpisData = secLoad('kpis');
        $todayRevenue      = $kpisData['today_revenue']['value'] ?? 0;
        $prevTodayRevenue  = $kpisData['today_revenue']['prev'] ?? 0;
        $monthRevenue      = $kpisData['monthly_revenue']['value'] ?? 0;
        $prevMonthRevenue  = $kpisData['monthly_revenue']['prev'] ?? 0;
        $yearRevenue       = $kpisData['annual_revenue']['value'] ?? 0;
        $prevYearRevenue   = $kpisData['annual_revenue']['prev'] ?? 0;
        $grossProfitLegacy = $kpisData['gross_profit']['value'] ?? 0;
        $netProfit         = $kpisData['net_profit']['value'] ?? 0;
        $profitMarginLegacy= $kpisData['profit_margin']['value'] ?? 0;
        $pendingReceivables = $kpisData['pending_receivables']['value'] ?? 0;
        $pendingPayablesTotal = $kpisData['pending_payables']['value'] ?? 0;
        $cashInHand        = $kpisData['cash_in_hand']['value'] ?? 0;
        $bankToday         = $kpisData['bank_balance']['value'] ?? 0;
        $outstandingDues   = $kpisData['outstanding_dues']['value'] ?? 0;
        $expenseTotal      = $kpisData['expenses_mtd']['value'] ?? 0;
    } else {
        $todayRevenue = qc($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM refill_orders WHERE DATE(order_date) BETWEEN ? AND ?", [$dateFrom, $dateTo]);
        $prevTodayRevenue = qc($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM refill_orders WHERE DATE(order_date) BETWEEN ? AND ?", [$prevDateFrom, $prevDateTo]);

        $monthRevenue = $totalRevenue;
        $prevMonthRevenue = $prevTotalRevenue;

        $yearRevenue = qc($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM refill_orders WHERE order_date >= ?", [$yearStart]);
        $prevYearRevenue = qc($pdo, "SELECT COALESCE(SUM(grand_total),0) FROM refill_orders WHERE order_date >= DATE_SUB(?, INTERVAL 1 YEAR) AND order_date < ?", [$yearStart, $yearStart]);

        $refillCostsLegacy = $refillCosts;
        $expenseTotal = $generalExpenses;

        $grossProfitLegacy = $monthRevenue - $refillCostsLegacy;
        $netProfit = $grossProfitLegacy - $expenseTotal;

        $prevRefillCostsLegacy = $prevRefillCosts;
        $prevExpenseTotal = $prevGeneralExpenses;
        $prevGrossProfitLegacy = $prevMonthRevenue - $prevRefillCostsLegacy;
        $prevNetProfit = $prevGrossProfitLegacy - $prevExpenseTotal;

        $pendingReceivables = qc($pdo, "SELECT COALESCE(SUM(grand_total - COALESCE((SELECT SUM(amount) FROM payments WHERE refill_order_id = ro.id), 0)), 0) FROM refill_orders ro WHERE payment_status IN ('pending','partial')");

        $pendingPayables = qc($pdo, "SELECT COALESCE(SUM(remaining_balance),0) FROM dispatch_lots WHERE payment_status IN ('unpaid','partial')");
        $pendingExpenses = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE payment_status IN ('unpaid','partial') AND is_deleted = 0");
        $pendingPayablesTotal = $pendingPayables + $pendingExpenses;

        $cashBalance2 = $cashIn - $cashOut - $cashExp;

        $bankIn = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_method IN ('bank','neft','upi','rtgs','cheque') AND payment_type IN ('refill_payment','deposit_added')");
        $bankOut = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_method IN ('bank','neft','upi','rtgs','cheque') AND payment_type IN ('vendor_payment','deposit_refunded')");
        $bankExp = qc($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE payment_method IN ('bank','neft','upi','rtgs','cheque') AND is_deleted = 0");
        $bankBalanceActual = $bankIn - $bankOut - $bankExp;

        $outstandingDues = qc($pdo, "SELECT COALESCE(SUM(deposit_balance + credit_used),0) FROM customers WHERE status = 'active'");

        $profitMarginLegacy = $monthRevenue > 0 ? round(($netProfit / $monthRevenue) * 100, 1) : 0;

        $kpisData = [
            'today_revenue' => ['value' => $todayRevenue, 'prev' => $prevTodayRevenue, 'change' => $prevTodayRevenue > 0 ? round((($todayRevenue - $prevTodayRevenue) / $prevTodayRevenue) * 100, 1) : 0],
            'monthly_revenue' => ['value' => $monthRevenue, 'prev' => $prevMonthRevenue, 'change' => $prevMonthRevenue > 0 ? round((($monthRevenue - $prevMonthRevenue) / $prevMonthRevenue) * 100, 1) : 0],
            'annual_revenue' => ['value' => $yearRevenue, 'prev' => $prevYearRevenue, 'change' => $prevYearRevenue > 0 ? round((($yearRevenue - $prevYearRevenue) / $prevYearRevenue) * 100, 1) : 0],
            'gross_profit' => ['value' => $grossProfitLegacy, 'prev' => $prevGrossProfitLegacy, 'change' => $prevGrossProfitLegacy > 0 ? round((($grossProfitLegacy - $prevGrossProfitLegacy) / $prevGrossProfitLegacy) * 100, 1) : 0],
            'net_profit' => ['value' => $netProfit, 'prev' => $prevNetProfit, 'change' => $prevNetProfit > 0 ? round((($netProfit - $prevNetProfit) / $prevNetProfit) * 100, 1) : 0],
            'profit_margin' => ['value' => $profitMarginLegacy],
            'pending_receivables' => ['value' => $pendingReceivables],
            'pending_payables' => ['value' => $pendingPayablesTotal],
            'outstanding_dues' => ['value' => $outstandingDues],
            'cash_balance' => ['value' => $cashBalance2],
            'bank_balance_actual' => ['value' => $bankBalanceActual],
            'expenses_mtd' => ['value' => $expenseTotal],
        ];
        secSave('kpis', $kpisData);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 2: Cylinder Operations (TTL: 120s)
    // ════════════════════════════════════════════════════════
    if (secFresh('cylinders', TTL_CYLINDERS)) {
        $cylData = secLoad('cylinders');
        $cylStats = $cylData['stats'] ?? [];
        $dueForTesting = $cylData['due_for_testing'] ?? 0;
        $cylByType = $cylData['by_type'] ?? [];
        $partnerRentPending = $cylData['partner_rent_pending'] ?? 0;
    } else {
        $cylStats = qv($pdo, "SELECT
            COUNT(*) AS total,
            SUM(status = 'filled') AS filled,
            SUM(status = 'empty') AS empty,
            SUM(status = 'with_customer') AS with_customer,
            SUM(status = 'under_maintenance') AS under_maintenance,
            SUM(status IN ('sent_to_vendor','received_for_refill')) AS in_transit,
            SUM(ownership_type = 'consumer_owned') AS consumer_owned,
            SUM(ownership_type = 'owned' AND (current_partner_id IS NOT NULL OR status IN ('lent_to_partner','with_partner'))) AS lent_out,
            SUM(status IN ('borrowed_from_partner','borrowed_from_vendor')) AS borrowed,
            SUM(ownership_type = 'partner_owned' AND current_partner_id IS NOT NULL) AS partner_owned,
            SUM(ownership_type = 'vendor_owned' AND current_vendor_id IS NOT NULL AND status != 'returned_to_vendor') AS vendor_owned,
            SUM(expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()) AS expiring_30d,
            SUM(expiry_date IS NOT NULL AND expiry_date < CURDATE()) AS expired,
            SUM(ownership_type = 'owned' AND (current_partner_id IS NULL OR current_partner_id = 0) AND status NOT IN ('returned_to_partner','returned_to_consumer','received_for_refill','with_customer') ) AS own_count,
            SUM(ownership_type = 'partner_owned' AND current_partner_id IS NOT NULL) AS borrowed_partner_count,
            SUM(status IN ('lent_to_partner','with_partner')) AS lent_partner_count,
            SUM(ownership_type = 'vendor_owned' AND current_vendor_id IS NOT NULL AND status != 'returned_to_vendor') AS vendor_borrowed_count,
            SUM(ownership_type = 'consumer_owned') AS consumer_owned_count,
            SUM(status = 'with_customer' AND borrow_date IS NOT NULL AND daily_rent_rate > 0 AND DATEDIFF(CURDATE(), borrow_date) > COALESCE(free_days, 0)) AS overdue_rentals,
            SUM(status IN ('lent_to_partner','borrowed_from_partner') AND borrow_date IS NOT NULL AND DATEDIFF(CURDATE(), borrow_date) > 30) AS overdue_partner_cylinders
        FROM cylinders") ?: [];

        $dueForTesting = qc($pdo, "SELECT COUNT(*) FROM cylinders WHERE last_inspection_date IS NULL OR last_inspection_date <= DATE_SUB(CURDATE(), INTERVAL 5 YEAR)");

        $cylByType = qva($pdo, "SELECT g.name,
            SUM(c.status = 'filled') AS filled,
            SUM(c.status = 'empty') AS empty,
            SUM(c.status = 'with_customer') AS with_customer,
            SUM(c.status = 'under_maintenance') AS maintenance,
            SUM(c.status IN ('sent_to_vendor','received_for_refill')) AS in_transit,
            COUNT(*) AS total
            FROM cylinders c JOIN gas_types g ON c.gas_type_id = g.id
            GROUP BY g.name ORDER BY total DESC LIMIT 10");

        $partnerRentPending = qc($pdo, "SELECT COUNT(*) FROM partner_transaction_items pti JOIN partner_transactions pt ON pti.transaction_id = pt.id WHERE pti.payment_status = 'pending' AND pt.transaction_type IN ('received_back_from_partner', 'returned_to_partner')");

        $cylData = [
            'stats' => $cylStats,
            'due_for_testing' => $dueForTesting,
            'by_type' => $cylByType,
            'partner_rent_pending' => $partnerRentPending,
        ];
        secSave('cylinders', $cylData);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 3: Revenue Analytics (TTL: 60s)
    // ════════════════════════════════════════════════════════
    if (secFresh('revenue', TTL_REVENUE)) {
        $revData = secLoad('revenue');
        $revDays = $revData['trend_days'] ?? [];
        $revValues = $revData['trend_values'] ?? [];
        $revOrders = $revData['trend_orders'] ?? [];
        $weeklyRevenue = $revData['weekly'] ?? [];
        $productRevenue = $revData['by_product'] ?? [];
        $customerRevenue = $revData['by_customer'] ?? [];
        $todayOrderCount = $revData['today_order_count'] ?? 0;
    } else {
        $revenueTrend = qva($pdo, "SELECT DATE(order_date) AS day, COALESCE(SUM(grand_total),0) AS revenue, COUNT(*) AS orders
            FROM refill_orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(order_date) ORDER BY day ASC");
        $revDayMap = [];
        foreach ($revenueTrend as $r) { $revDayMap[$r['day']] = ['revenue' => (float)$r['revenue'], 'orders' => (int)$r['orders']]; }
        $revDays = []; $revValues = []; $revOrders = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $revDays[] = date('d M', strtotime($d));
            $revValues[] = $revDayMap[$d]['revenue'] ?? 0;
            $revOrders[] = $revDayMap[$d]['orders'] ?? 0;
        }

        $weeklyRevenue = qva($pdo, "SELECT YEARWEEK(order_date) AS wk, MIN(DATE(order_date)) AS week_start, COALESCE(SUM(grand_total),0) AS revenue
            FROM refill_orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
            GROUP BY YEARWEEK(order_date) ORDER BY wk ASC");

        $productRevenue = qva($pdo, "SELECT g.name, COALESCE(SUM(roi.qty * roi.price_per_unit),0) AS revenue
            FROM refill_order_items roi JOIN gas_types g ON roi.gas_type_id = g.id
            JOIN refill_orders ro ON roi.refill_order_id = ro.id
            WHERE ro.order_date >= ?
            GROUP BY g.name ORDER BY revenue DESC LIMIT 10", [$dateFrom]);

        $customerRevenue = qva($pdo, "SELECT c.name, c.mobile, COALESCE(SUM(ro.grand_total),0) AS total_spent
            FROM customers c JOIN refill_orders ro ON c.id = ro.customer_id
            WHERE ro.order_date >= ?
            GROUP BY c.id ORDER BY total_spent DESC LIMIT 10", [$dateFrom]);

        $todayOrderCount = qc($pdo, "SELECT COUNT(*) FROM refill_orders WHERE DATE(order_date) BETWEEN ? AND ?", [$dateFrom, $dateTo]);

        $revData = [
            'trend_days' => $revDays,
            'trend_values' => $revValues,
            'trend_orders' => $revOrders,
            'weekly' => $weeklyRevenue,
            'by_product' => $productRevenue,
            'by_customer' => $customerRevenue,
            'today_order_count' => $todayOrderCount,
        ];
        secSave('revenue', $revData);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 4: Financial Overview (TTL: 60s)
    // ════════════════════════════════════════════════════════
    if (secFresh('financial', TTL_FINANCIAL)) {
        $finData = secLoad('financial');
        $monthExpenses = $finData['expenses'] ?? 0;
        $gstSummary = $finData['gst'] ?? [];
        $expenseByCategory = $finData['expense_by_category'] ?? [];
        $expenseTrend = $finData['expense_trend'] ?? [];
        $cashFlow = $finData['cash_flow'] ?? [];
    } else {
        $monthExpenses = $generalExpenses;

        $gstSummary = qv($pdo, "SELECT
            COALESCE(SUM(CASE WHEN input_output_type = 'input' THEN gst_amount ELSE 0 END),0) AS input_gst,
            COALESCE(SUM(CASE WHEN input_output_type = 'output' THEN gst_amount ELSE 0 END),0) AS output_gst
            FROM gst_ledger WHERE DATE(transaction_date) BETWEEN ? AND ?", [$dateFrom, $dateTo]) ?: [];

        $expenseByCategory = qva($pdo, "SELECT ec.name, COALESCE(SUM(e.amount),0) AS total
            FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id
            WHERE DATE(e.expense_date) BETWEEN ? AND ? AND e.is_deleted = 0
            GROUP BY ec.name ORDER BY total DESC LIMIT 8", [$dateFrom, $dateTo]);

        $expenseTrend = qva($pdo, "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month, COALESCE(SUM(amount),0) AS total
            FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND is_deleted = 0
            GROUP BY DATE_FORMAT(expense_date, '%Y-%m') ORDER BY month ASC");

        $cashFlow = qva($pdo, "SELECT
            DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
            COALESCE(SUM(CASE WHEN p.customer_id IS NOT NULL THEN p.amount ELSE 0 END),0) AS inflow,
            COALESCE(SUM(CASE WHEN p.vendor_id IS NOT NULL THEN p.amount ELSE 0 END),0) AS outflow
            FROM payments p WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m') ORDER BY month ASC");

        $finData = [
            'expenses' => $monthExpenses,
            'gst' => $gstSummary,
            'expense_by_category' => $expenseByCategory,
            'expense_trend' => $expenseTrend,
            'cash_flow' => $cashFlow,
        ];
        secSave('financial', $finData);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 5: Orders & Operations (TTL: 60s)
    // ════════════════════════════════════════════════════════
    if (secFresh('orders', TTL_ORDERS)) {
        $ordData = secLoad('orders');
        $orderStats = $ordData['stats'] ?? [];
        $deliveriesToday = $ordData['deliveries_today'] ?? 0;
        $returnsToday = $ordData['returns_today'] ?? 0;
    } else {
        $orderStats = qv($pdo, "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN DATE(order_date) BETWEEN ? AND ? THEN 1 ELSE 0 END),0) AS today,
            COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END),0) AS pending,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END),0) AS completed,
            COALESCE(SUM(CASE WHEN DATE(order_date) BETWEEN ? AND ? AND order_type LIKE '%refill%' THEN 1 ELSE 0 END),0) AS refills_today,
            COALESCE(SUM(CASE WHEN DATE(order_date) BETWEEN ? AND ? AND order_type LIKE '%exchange%' THEN 1 ELSE 0 END),0) AS exchanges_today,
            COALESCE(SUM(CASE WHEN DATE(order_date) BETWEEN ? AND ? AND order_type LIKE '%rent%' THEN 1 ELSE 0 END),0) AS rentals_today
        FROM refill_orders", [$dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo]) ?: [];

        $deliveriesToday = qc($pdo, "SELECT COUNT(*) FROM refill_orders WHERE DATE(order_date) BETWEEN ? AND ? AND vehicle_number IS NOT NULL AND vehicle_number != ''", [$dateFrom, $dateTo]);
        $returnsToday = qc($pdo, "SELECT COUNT(*) FROM cylinder_transactions WHERE DATE(transaction_date) BETWEEN ? AND ? AND transaction_type IN ('return_from_customer','receive_from_vendor','returned_after_refill')", [$dateFrom, $dateTo]);

        $ordData = [
            'stats' => $orderStats,
            'deliveries_today' => $deliveriesToday,
            'returns_today' => $returnsToday,
        ];
        secSave('orders', $ordData);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 6: Customer Insights (TTL: 300s)
    // ════════════════════════════════════════════════════════
    if (secFresh('customers', TTL_CUSTOMERS)) {
        $custData = secLoad('customers');
        $custMetrics = $custData['metrics'] ?? [];
        $frequentCustomers = $custData['frequent'] ?? [];
        $inactiveCustomers = $custData['inactive_count'] ?? 0;
    } else {
        $custMetrics = qv($pdo, "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END),0) AS active,
            COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END),0) AS new_this_month,
            COALESCE(SUM(CASE WHEN deposit_balance > 0 OR credit_used > 0 THEN 1 ELSE 0 END),0) AS has_outstanding
        FROM customers", [$dateFrom]) ?: [];

        $frequentCustomers = qva($pdo, "SELECT c.id, c.name, c.mobile, COUNT(ro.id) AS order_count, COALESCE(SUM(ro.grand_total),0) AS total_spent
            FROM customers c JOIN refill_orders ro ON c.id = ro.customer_id
            WHERE ro.order_date >= ?
            GROUP BY c.id ORDER BY order_count DESC LIMIT 5", [$dateFrom]);

        $inactiveCustomers = qc($pdo, "SELECT COUNT(*) FROM customers WHERE id NOT IN (
            SELECT DISTINCT customer_id FROM refill_orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ) AND status = 'active'");

        $custData = [
            'metrics' => $custMetrics,
            'frequent' => $frequentCustomers,
            'inactive_count' => $inactiveCustomers,
        ];
        secSave('customers', $custData);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 7: Vendor & Gas Plant (TTL: 300s)
    // ════════════════════════════════════════════════════════
    if (secFresh('vendors', TTL_VENDORS)) {
        $venData = secLoad('vendors');
        $vendorMetrics = $venData['metrics'] ?? [];
        $pendingGasPurchases = $venData['pending_purchases'] ?? 0;
        $pendingGasAmount = $venData['pending_amount'] ?? 0;
        $vendorOutstanding = $venData['outstanding'] ?? 0;
        $recentVendorPurchases = $venData['recent'] ?? [];
    } else {
        $vendorMetrics = qv($pdo, "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END),0) AS active
        FROM vendors") ?: [];

        $pendingGasPurchases = qc($pdo, "SELECT COALESCE(COUNT(*),0) FROM dispatch_lots WHERE lot_status IN ('open','partial_return')");
        $pendingGasAmount = qc($pdo, "SELECT COALESCE(SUM(remaining_balance),0) FROM dispatch_lots WHERE payment_status IN ('unpaid','partial')");
        $vendorOutstanding = qc($pdo, "SELECT COALESCE(SUM(vpl.due_balance),0) FROM vendor_partner_ledger vpl WHERE vpl.entity_type = 'vendor' AND vpl.id IN (SELECT MAX(id) FROM vendor_partner_ledger WHERE entity_type = 'vendor' GROUP BY entity_id) AND vpl.due_balance > 0");

        $recentVendorPurchases = qva($pdo, "SELECT dl.*, v.name AS vendor_name, GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') AS gas_name
            FROM dispatch_lots dl
            JOIN vendors v ON dl.vendor_id = v.id
            LEFT JOIN dispatch_lot_items dli ON dl.id = dli.lot_id
            LEFT JOIN gas_types g ON dli.gas_type_id = g.id
            WHERE dl.lot_status IN ('completed','partial_return')
            GROUP BY dl.id
            ORDER BY dl.receive_date DESC LIMIT 5");

        $venData = [
            'metrics' => $vendorMetrics,
            'pending_purchases' => $pendingGasPurchases,
            'pending_amount' => $pendingGasAmount,
            'outstanding' => $vendorOutstanding,
            'recent' => $recentVendorPurchases,
        ];
        secSave('vendors', $venData);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 8: Warehouse / Inventory (TTL: 120s)
    // ════════════════════════════════════════════════════════
    if (secFresh('inventory', TTL_INVENTORY)) {
        $invData = secLoad('inventory');
        $invStats = $invData['items'] ?? [];
        $lowStockItems = $invData['low_stock'] ?? [];
        $lowProductItems = $invData['low_products'] ?? [];
        $incomingStock = $invData['incoming_today'] ?? 0;
        $outgoingStock = $invData['outgoing_today'] ?? 0;
        $activeRefillServices = $invData['refill_active'] ?? 0;
        $warehouseRefillCount = $invData['refill_warehouse'] ?? 0;
        $pendingWarehouseDispatch = $invData['refill_pending_dispatch'] ?? 0;
        $warehouseRefillStock = $invData['refill_warehouse_stock'] ?? 0;
        $enquiriesTotal = $invData['enquiries'] ?? 0;
    } else {
        $invStats = qva($pdo, "SELECT i.*, g.name AS gas_name FROM inventory i JOIN gas_types g ON i.gas_type_id = g.id ORDER BY g.name");

        $lowStockItems = qva($pdo, "SELECT i.*, g.name AS gas_name FROM inventory i JOIN gas_types g ON i.gas_type_id = g.id WHERE i.filled_stock <= i.min_alert_threshold");
        $lowProductItems = qva($pdo, "SELECT name, stock_quantity, min_alert_threshold FROM products WHERE stock_quantity <= min_alert_threshold");

        $incomingStock = qc($pdo, "SELECT COALESCE(COUNT(*),0) FROM cylinder_transactions WHERE DATE(transaction_date) = CURDATE() AND transaction_type IN ('receive_from_vendor','returned_after_refill','return_from_customer','received_for_refill')");
        $outgoingStock = qc($pdo, "SELECT COALESCE(COUNT(*),0) FROM cylinder_transactions WHERE DATE(transaction_date) = CURDATE() AND transaction_type IN ('issue_to_customer','send_to_vendor','refill')");

        $activeRefillServices = qc($pdo, "SELECT COUNT(*) FROM customer_refill_services WHERE status NOT IN ('returned_to_customer','cancelled')");
        $warehouseRefillCount = qc($pdo, "SELECT COUNT(*) FROM customer_refill_services WHERE refill_source = 'warehouse' AND status NOT IN ('returned_to_customer','cancelled')");
        $pendingWarehouseDispatch = qc($pdo, "SELECT COUNT(*) FROM customer_refill_services WHERE refill_source = 'warehouse' AND status = 'received'");
        $warehouseRefillStock = qc($pdo, "SELECT COALESCE(SUM(received_for_refill_stock), 0) FROM inventory");

        $enquiriesTotal = qc($pdo, "SELECT COUNT(*) FROM enquiries");

        $invData = [
            'items' => $invStats,
            'low_stock' => $lowStockItems,
            'low_products' => $lowProductItems,
            'incoming_today' => $incomingStock,
            'outgoing_today' => $outgoingStock,
            'refill_active' => $activeRefillServices,
            'refill_warehouse' => $warehouseRefillCount,
            'refill_pending_dispatch' => $pendingWarehouseDispatch,
            'refill_warehouse_stock' => $warehouseRefillStock,
            'enquiries' => $enquiriesTotal,
        ];
        secSave('inventory', $invData);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 9: Activity Timeline (TTL: 60s)
    // ════════════════════════════════════════════════════════
    if (secFresh('activity', TTL_ACTIVITY)) {
        $recentActivity = secLoad('activity');
    } else {
        $recentActivity = qva($pdo, "(SELECT 'order' AS source, ro.id, ro.order_date AS ts, CONCAT('Order #', ro.id, ' - ', c.name) AS description, ro.payment_status AS status, 'shopping-cart' AS icon, NULL AS user_name FROM refill_orders ro JOIN customers c ON ro.customer_id = c.id)
            UNION ALL (SELECT 'payment' AS source, p.id, p.payment_date AS ts, CONCAT(p.payment_method, ' payment of ', p.amount, IF(p.customer_id IS NOT NULL, CONCAT(' from ', c.name), CONCAT(' to ', v.name))) AS description, p.payment_type AS status, 'dollar-sign' AS icon, NULL AS user_name FROM payments p LEFT JOIN customers c ON p.customer_id = c.id LEFT JOIN vendors v ON p.vendor_id = v.id)
            UNION ALL (SELECT 'cylinder' AS source, ct.id, ct.transaction_date AS ts, CONCAT(ct.transaction_type, ' - ', cy.serial_number) AS description, ct.transaction_type AS status, 'cylinder' AS icon, NULL AS user_name FROM cylinder_transactions ct JOIN cylinders cy ON ct.cylinder_id = cy.id)
            UNION ALL (SELECT 'customer' AS source, c.id, c.created_at AS ts, CONCAT('New: ', c.name) AS description, 'new' AS status, 'user-plus' AS icon, NULL AS user_name FROM customers c)
            UNION ALL (SELECT 'expense' AS source, e.id, e.created_at AS ts, CONCAT('Expense: ', e.amount, ' - ', ec.name) AS description, e.payment_status AS status, 'file-text' AS icon, NULL AS user_name FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id)
            ORDER BY ts DESC LIMIT 20");
        secSave('activity', $recentActivity);
    }

    // ════════════════════════════════════════════════════════
    // SECTION 10: Alerts (TTL: 120s)
    // ════════════════════════════════════════════════════════
    if (secFresh('alerts', TTL_ALERTS)) {
        $alerts = secLoad('alerts');
    } else {
        $overdueRentals = qc($pdo, "SELECT COUNT(*) FROM cylinders WHERE status = 'with_customer' AND borrow_date IS NOT NULL AND daily_rent_rate > 0 AND DATEDIFF(CURDATE(), borrow_date) > COALESCE(free_days, 0)");
        $overduePartnerReturns = qc($pdo, "SELECT COUNT(*) FROM cylinders WHERE status IN ('lent_to_partner','borrowed_from_partner') AND borrow_date IS NOT NULL AND DATEDIFF(CURDATE(), borrow_date) > 30");
        $cylindersExpiring = qc($pdo, "SELECT COUNT(*) FROM cylinders WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()");
        $cylindersExpired = qc($pdo, "SELECT COUNT(*) FROM cylinders WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()");
        $unpaidInvoices = qc($pdo, "SELECT COUNT(*) FROM refill_orders WHERE payment_status = 'pending'");
        $partialInvoices = qc($pdo, "SELECT COUNT(*) FROM refill_orders WHERE payment_status = 'partial'");

        $alerts = [
            ['type' => 'critical', 'label' => 'Overdue Rentals', 'count' => $overdueRentals, 'link' => 'customers.php'],
            ['type' => 'critical', 'label' => 'Overdue Partner Returns', 'count' => $overduePartnerReturns, 'link' => 'partner-reports.php'],
            ['type' => 'warning', 'label' => 'Low Stock Items', 'count' => count($lowStockItems), 'link' => 'inventory.php'],
            ['type' => 'warning', 'label' => 'Low Product Stock', 'count' => count($lowProductItems), 'link' => 'products.php'],
            ['type' => 'warning', 'label' => 'Cylinders Expiring', 'count' => $cylindersExpiring, 'link' => 'cylinders.php'],
            ['type' => 'danger', 'label' => 'Expired Cylinders', 'count' => $cylindersExpired, 'link' => 'cylinders.php'],
            ['type' => 'danger', 'label' => 'Due for Testing', 'count' => $dueForTesting, 'link' => 'cylinders.php'],
            ['type' => 'info', 'label' => 'Unpaid Orders', 'count' => $unpaidInvoices, 'link' => 'refill-orders.php'],
            ['type' => 'info', 'label' => 'Partial Payments', 'count' => $partialInvoices, 'link' => 'refill-orders.php'],
            ['type' => 'info', 'label' => 'Pending Gas Lots', 'count' => $pendingGasPurchases, 'link' => 'vendors.php'],
        ];
        secSave('alerts', $alerts);
    }

    // ════════════════════════════════════════════════════════
    // AI Business Insights (uncached)
    // ════════════════════════════════════════════════════════
    $aiConfigured = false;
    $aiSnapshot = null;
    if (file_exists(__DIR__ . '/ai/ai-config.php')) {
        require_once __DIR__ . '/ai/ai-config.php';
        runAIConfigMigration($pdo);
        $aiConfig = getAIConfig($pdo);
        $aiConfigured = !empty($aiConfig['api_key']);
        if ($aiConfigured && file_exists(__DIR__ . '/ai/analytics/data-aggregator.php')) {
            require_once __DIR__ . '/ai/analytics/data-aggregator.php';
            $aiSnapshot = getBusinessSnapshot($pdo);
        }
    }

    // ════════════════════════════════════════════════════════
    // Growth Calculations
    // ════════════════════════════════════════════════════════
    $momGrowth = $prevTotalRevenue > 0 ? round((($totalRevenue - $prevTotalRevenue) / $prevTotalRevenue) * 100, 1) : 0;
    $yoyGrowth = $prevYearRevenue > 0 ? round((($yearRevenue - $prevYearRevenue) / $prevYearRevenue) * 100, 1) : 0;

    // ════════════════════════════════════════════════════════
    // Build Response
    // ════════════════════════════════════════════════════════
    $response = [
        'success' => true,
        'timestamp' => time(),
        'finance_summary' => $financeSummary,
        'kpis' => $kpisData,
        'cylinders' => [
            'total' => (int)($cylStats['total'] ?? 0),
            'filled' => (int)($cylStats['filled'] ?? 0),
            'empty' => (int)($cylStats['empty'] ?? 0),
            'with_customer' => (int)($cylStats['with_customer'] ?? 0),
            'under_maintenance' => (int)($cylStats['under_maintenance'] ?? 0),
            'in_transit' => (int)($cylStats['in_transit'] ?? 0),
            'consumer_owned' => (int)($cylStats['consumer_owned'] ?? 0),
            'lent_out' => (int)($cylStats['lent_out'] ?? 0),
            'borrowed' => (int)($cylStats['borrowed'] ?? 0),
            'partner_owned' => (int)($cylStats['partner_owned'] ?? 0),
            'vendor_owned' => (int)($cylStats['vendor_owned'] ?? 0),
            'expiring_30d' => (int)($cylStats['expiring_30d'] ?? 0),
            'expired' => (int)($cylStats['expired'] ?? 0),
            'due_for_testing' => $dueForTesting,
            'by_type' => $cylByType,
            'own_count' => (int)($cylStats['own_count'] ?? 0),
            'borrowed_partner_count' => (int)($cylStats['borrowed_partner_count'] ?? 0),
            'lent_partner_count' => (int)($cylStats['lent_partner_count'] ?? 0),
            'vendor_borrowed_count' => (int)($cylStats['vendor_borrowed_count'] ?? 0),
            'consumer_owned_count' => (int)($cylStats['consumer_owned_count'] ?? 0),
            'overdue_partner_cylinders' => (int)($cylStats['overdue_partner_cylinders'] ?? 0),
            'overdue_rentals' => (int)($cylStats['overdue_rentals'] ?? 0),
            'partner_rent_pending' => $partnerRentPending,
        ],
        'revenue' => [
            'trend_days' => $revDays,
            'trend_values' => $revValues,
            'trend_orders' => $revOrders,
            'weekly' => $weeklyRevenue,
            'by_product' => $productRevenue,
            'by_customer' => $customerRevenue,
            'avg_order_value' => $avgOrderValue,
        ],
        'financial' => [
            'income' => $monthRevenue,
            'expenses' => $monthExpenses,
            'profit' => $netProfit,
            'gross_profit' => $grossProfit,
            'gst_input' => (float)($gstSummary['input_gst'] ?? 0),
            'gst_output' => (float)($gstSummary['output_gst'] ?? 0),
            'gst_net' => (float)(($gstSummary['output_gst'] ?? 0) - ($gstSummary['input_gst'] ?? 0)),
            'expense_by_category' => $expenseByCategory,
            'expense_trend' => $expenseTrend,
            'cash_flow' => $cashFlow,
        ],
        'orders' => [
            'total' => (int)($orderStats['total'] ?? 0),
            'today' => (int)($orderStats['today'] ?? 0),
            'pending' => (int)($orderStats['pending'] ?? 0),
            'completed' => (int)($orderStats['completed'] ?? 0),
            'refills_today' => (int)($orderStats['refills_today'] ?? 0),
            'exchanges_today' => (int)($orderStats['exchanges_today'] ?? 0),
            'rentals_today' => (int)($orderStats['rentals_today'] ?? 0),
            'deliveries_today' => $deliveriesToday,
            'returns_today' => $returnsToday,
        ],
        'customers' => [
            'total' => (int)($custMetrics['total'] ?? 0),
            'active' => (int)($custMetrics['active'] ?? 0),
            'new_this_month' => (int)($custMetrics['new_this_month'] ?? 0),
            'has_outstanding' => (int)($custMetrics['has_outstanding'] ?? 0),
            'frequent' => $frequentCustomers,
            'inactive_count' => $inactiveCustomers,
            'top_by_revenue' => $customerRevenue,
        ],
        'vendors' => [
            'total' => (int)($vendorMetrics['total'] ?? 0),
            'active' => (int)($vendorMetrics['active'] ?? 0),
            'pending_purchases' => $pendingGasPurchases,
            'pending_amount' => $pendingGasAmount,
            'outstanding' => $vendorOutstanding,
            'recent' => $recentVendorPurchases,
        ],
        'inventory' => [
            'items' => $invStats,
            'low_stock' => $lowStockItems,
            'low_products' => $lowProductItems,
            'incoming_today' => $incomingStock,
            'outgoing_today' => $outgoingStock,
        ],
        'alerts' => $alerts,
        'activity' => $recentActivity,
        'enquiries' => $enquiriesTotal,
        'refill_services' => [
            'active' => $activeRefillServices,
            'warehouse' => $warehouseRefillCount,
            'pending_dispatch' => $pendingWarehouseDispatch,
            'warehouse_stock' => $warehouseRefillStock,
        ],
        'ai' => [
            'configured' => $aiConfigured,
            'snapshot' => $aiSnapshot,
        ],
        'meta' => [
            'month_start' => $monthStart,
            'today' => $today,
            'range' => $range,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'period_label' => $periodLabel,
        ],
    ];

    $json = json_encode($response, JSON_UNESCAPED_UNICODE);

    // ── Save to stale-while-revalidate cache ──
    file_put_contents($swrCache, $json, LOCK_EX);

    if ($swrServed) {
        ob_end_clean();
    } else {
        echo $json;
    }

} catch (Exception $e) {
    if ($swrServed) {
        if (ob_get_level()) ob_end_clean();
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
