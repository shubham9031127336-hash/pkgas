<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('dashboard.title', 'Executive Dashboard');
$active_menu = "dashboard";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'warehouse_supervisor', 'billing_clerk', 'viewer']);
require_once __DIR__ . '/db.php';

require_once 'inventory-utils.php';
require_once 'notifications.php';

// Defer email alerts to after page is rendered (SMTP can be slow)
register_shutdown_function(function() use ($pdo) {
    try { sendLowStockAlert($pdo); } catch (Exception $e) {}
    try { sendExpiryReminders($pdo); } catch (Exception $e) {}
});

runPartnerMigrations($pdo);
runConsumerCylinderMigrations($pdo);
runProductMigrations($pdo);
runProductPurchasePriceMigration($pdo);
runCylinderPurchasePriceMigration($pdo);

$ajaxUrl = "dashboard-ajax.php";
$userRole = $_SESSION['user_role'] ?? 'viewer';
?>
<link rel="stylesheet" href="dashboard.css?v=<?php echo filemtime(__DIR__ . '/dashboard.css'); ?>">

<!-- i18n strings for JS -->
<script id="dashLangData" type="application/json">
<?php echo json_encode([
    'dash.monthly_revenue' => __('dash.monthly_revenue', 'Monthly Revenue'),
    'dash.net_profit' => __('dash.net_profit', 'Net Profit (MTD)'),
    'dash.cash_bank' => __('dash.cash_bank', 'Cash + Bank Today'),
    'dash.net_receivables' => __('dash.net_receivables', 'Net Receivables'),
    'dash.annual_revenue' => __('dash.annual_revenue', 'Annual Revenue'),
    'dash.vs_last_month' => __('dash.vs_last_month', 'vs last mo'),
    'dash.vs_last_year' => __('dash.vs_last_year', 'vs last yr'),
    'dash.cash' => __('dash.cash', 'cash'),
    'dash.bank' => __('dash.bank', 'bank'),
    'dash.due' => __('dash.due', 'due'),
    'dash.owed' => __('dash.owed', 'owed'),
    'dash.revenue' => __('dash.revenue', 'Revenue'),
    'dash.orders' => __('dash.orders', 'Orders'),
    'dash.total' => __('dash.total', 'Total'),
    'dash.filled' => __('dash.filled', 'Filled'),
    'dash.empty' => __('dash.empty', 'Empty'),
    'dash.with_customer' => __('dash.with_customer', 'With Customer'),
    'dash.in_transit' => __('dash.in_transit', 'In Transit'),
    'dash.expired' => __('dash.expired', 'Expired'),
    'dash.maintenance' => __('dash.maintenance', 'Maintenance'),
    'dash.orders_title' => __('dash.orders_title', 'Orders'),
    'dash.today' => __('dash.today', 'Today'),
    'dash.pending' => __('dash.pending', 'Pending'),
    'dash.completed' => __('dash.completed', 'Completed'),
    'dash.deliveries' => __('dash.deliveries', 'Deliveries'),
    'dash.customers_title' => __('dash.customers_title', 'Customers'),
    'dash.active' => __('dash.active', 'Active'),
    'dash.new_month' => __('dash.new_month', 'New This Month'),
    'dash.inactive' => __('dash.inactive', 'Inactive (90d)'),
    'dash.vendors_title' => __('dash.vendors_title', 'Vendors'),
    'dash.pending_lots' => __('dash.pending_lots', 'Pending Lots'),
    'dash.outstanding' => __('dash.outstanding', 'Outstanding'),
    'dash.no_products' => __('dash.no_products', 'No product data for this month'),
    'dash.no_activity' => __('dash.no_activity', 'No activity yet'),
    'dash.system' => __('dash.system', 'System'),
    'dash.no_inventory' => __('dash.no_inventory', 'No inventory data'),
    'dash.low_stock_alerts' => __('dash.low_stock_alerts', 'Low Stock Alerts'),
    'dash.stock' => __('dash.stock', 'Stock'),
    'dash.incoming' => __('dash.incoming', 'Incoming Today'),
    'dash.outgoing' => __('dash.outgoing', 'Outgoing Today'),
    'dash.all_clear' => __('dash.all_clear', 'All clear'),
    'dash.no_alerts' => __('dash.no_alerts', 'no active alerts'),
    'dash.new_customer' => __('dash.new_customer', 'New Customer'),
    'dash.create_order' => __('dash.create_order', 'Create Order'),
    'dash.refill' => __('dash.refill', 'Refill'),
    'dash.rent' => __('dash.rent', 'Rent'),
    'dash.exchange' => __('dash.exchange', 'Exchange'),
    'dash.receive_payment' => __('dash.receive_payment', 'Receive Payment'),
    'dash.add_expense' => __('dash.add_expense', 'Add Expense'),
    'dash.send_vendor' => __('dash.send_vendor', 'Send Vendor'),
    'dash.receive_vendor' => __('dash.receive_vendor', 'Receive Vendor'),
    'dash.reports' => __('dash.reports', 'Reports'),
    'dash.updated' => __('dash.updated', 'Updated'),
    'dash.just_now' => __('dash.just_now', 'just now'),
    'dash.load_error' => __('dash.load_error', 'Could not load dashboard data. Check your connection.'),
    'dash.inflow' => __('dash.inflow', 'Inflow'),
    'dash.outflow' => __('dash.outflow', 'Outflow'),
    'dash.own' => __('dash.own', 'OWN'),
    'dash.borrowed' => __('dash.borrowed', 'BR'),
    'dash.lent' => __('dash.lent', 'Lent'),
    'dash.consumer' => __('dash.consumer', 'CON'),
    'dash.vendor' => __('dash.vendor', 'VEN'),
    'dash.overdue' => __('dash.overdue', 'Overdue'),
    'dash.rents' => __('dash.rents', 'Rent'),
    'dash.distribution' => __('dash.distribution', 'Distribution'),
    'dash.lifecycle' => __('dash.lifecycle', 'Cycle Lifecycle'),
    'dash.partner_exchange' => __('dash.partner_exchange', 'Partner Exchange'),
    'dash.recent_orders' => __('dash.recent_orders', 'Recent Orders'),
    'dash.view_all' => __('dash.view_all', 'View All'),
    'dash.deposit' => __('dash.deposit', 'Deposit'),
    'dash.no_orders' => __('dash.no_orders', 'No orders yet'),
    'dash.no_recent' => __('dash.no_recent', 'No recent activity'),
    'dash.ai_title' => __('dash.ai_title', 'AI Business Insights'),
    'dash.ai_no_config' => __('dash.ai_no_config', 'AI Assistant not configured. Go to Settings > AI Config.'),
    'dash.ai_open' => __('dash.ai_open', 'Open Assistant'),
    'dash.ai_refresh' => __('dash.ai_refresh', 'Refresh'),
    'dash.quick_actions' => __('dash.quick_actions', 'Quick Actions'),
    'dash.cylinders' => __('dash.cylinders', 'cylinders'),
    'dash.revenue_trend' => __('dash.revenue_trend', 'Revenue Trend (30 Days)'),
    'dash.cylinder_fleet' => __('dash.cylinder_fleet', 'Cylinder Fleet'),
    'dash.operations' => __('dash.operations', 'Operations'),
    'dash.top_products' => __('dash.top_products', 'Top Products'),
    'dash.recent_activity' => __('dash.recent_activity', 'Recent Activity'),
    'dash.inventory_levels' => __('dash.inventory_levels', 'Inventory Levels'),
    'dash.expense_breakdown' => __('dash.expense_breakdown', 'Expense Breakdown'),
    'dash.cash_flow' => __('dash.cash_flow', 'Cash Flow (12 Months)'),
    'dash.alerts_section' => __('dash.alerts_section', 'Alerts'),
    'dash.enquiries' => __('dash.enquiries', 'Enquiries'),
    'dash.refill_services' => __('dash.refill_services', 'Refill Services'),
    'dash.warehouse_refills' => __('dash.warehouse_refills', 'Warehouse Refills'),
    'dash.pending_dispatch' => __('dash.pending_dispatch', 'Pending Dispatch'),
    'dash.active_customers' => __('dash.active_customers', 'Active Customers'),
    'dash.filled_available' => __('dash.filled_available', 'Filled Available'),
    'dash.empty_cylinders' => __('dash.empty_cylinders', 'Empty Cylinders'),
    'dash.total_cylinders' => __('dash.total_cylinders', 'Total Cylinders'),
    'dash.with_customers' => __('dash.with_customers', 'With Customers'),
    // New finance summary strings
    'fin.total_revenue' => __('fin.total_revenue', 'Total Revenue'),
    'fin.cogs' => __('fin.cogs', 'COGS (Refill Costs)'),
    'fin.gross_profit' => __('fin.gross_profit', 'Gross Profit'),
    'fin.gross_margin' => __('fin.gross_margin', 'Gross Margin'),
    'fin.opex' => __('fin.opex', 'Operating Expenses'),
    'fin.net_profit' => __('fin.net_profit', 'Net Profit'),
    'fin.gst_net' => __('fin.gst_net', 'GST Net Payable'),
    'fin.profit_after_gst' => __('fin.profit_after_gst', 'Profit After GST'),
    'fin.cash_balance' => __('fin.cash_balance', 'Cash Balance'),
    'fin.period' => __('fin.period', 'Period'),
    'fin.vs_prev' => __('fin.vs_prev', 'vs prev'),
    'fin.today' => __('fin.today', 'Today'),
    'fin.this_week' => __('fin.this_week', 'This Week'),
    'fin.this_month' => __('fin.this_month', 'This Month'),
    'fin.this_year' => __('fin.this_year', 'This Year'),
    'fin.custom' => __('fin.custom', 'Custom'),
    'fin.from' => __('fin.from', 'From'),
    'fin.to' => __('fin.to', 'To'),
    'fin.apply' => __('fin.apply', 'Apply'),
], JSON_UNESCAPED_UNICODE); ?>
</script>

<div class="dash" id="dashApp" aria-live="polite">
    <!-- Skeleton Loading -->
    <div id="dashSkeleton" class="dash-grid" aria-hidden="true">
        <div class="skeleton-card"><div class="skeleton-row"><div class="skeleton-icon"></div><div><div class="skeleton-text-lg"></div><div class="skeleton-text-sm"></div></div></div></div>
        <div class="skeleton-card"><div class="skeleton-row"><div class="skeleton-icon"></div><div><div class="skeleton-text-lg"></div><div class="skeleton-text-sm"></div></div></div></div>
        <div class="skeleton-card"><div class="skeleton-row"><div class="skeleton-icon"></div><div><div class="skeleton-text-lg"></div><div class="skeleton-text-sm"></div></div></div></div>
        <div class="skeleton-card"><div class="skeleton-row"><div class="skeleton-icon"></div><div><div class="skeleton-text-lg"></div><div class="skeleton-text-sm"></div></div></div></div>
        <div class="skeleton-card dash-span-2"><div class="skeleton-row"><div class="skeleton-icon"></div><div class="skeleton-flex"><div class="skeleton-text-lg"></div><div class="skeleton-text-sm"></div></div></div></div>
        <div class="skeleton-card"><div class="skeleton-row"><div class="skeleton-icon"></div><div><div class="skeleton-text-lg"></div><div class="skeleton-text-sm"></div></div></div></div>
        <div class="skeleton-card dash-span-3 skeleton-card-tall"></div>
    </div>

    <!-- Bentley Grid Content (hidden until JS renders) -->
    <div id="dashContent" class="dash-grid" style="display:none;">

        <!-- ═══ Top Bar ═══ -->
        <div class="dash-span-4 topbar-wrap">
            <div class="d-topbar">
                <div class="d-topbar-left">
                    <h1><?php echo __('dashboard.title', 'Executive Dashboard'); ?></h1>
                </div>
                <div class="d-topbar-right">
                    <span class="d-topbar-meta" id="dashLastUpdated"></span>
                    <button class="d-btn-icon" id="dashAutoRefreshBtn">
                        <span class="d-auto-dot" id="dashAutoDot"></span>
                        Auto
                    </button>
                    <button class="d-btn-icon" id="dashRefreshBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        <?php echo __('dash.refresh', 'Refresh'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══ Date Filter Bar ═══ -->
        <div class="dash-span-4 animate-in animate-in-d1">
            <div class="d-fin-filter">
                <div class="d-fin-filter-tabs" id="finFilterTabs">
                    <button class="d-fin-pill" data-range="today"><?php echo __('fin.today', 'Today'); ?></button>
                    <button class="d-fin-pill" data-range="week"><?php echo __('fin.this_week', 'This Week'); ?></button>
                    <button class="d-fin-pill active" data-range="month"><?php echo __('fin.this_month', 'This Month'); ?></button>
                    <button class="d-fin-pill" data-range="year"><?php echo __('fin.this_year', 'This Year'); ?></button>
                    <button class="d-fin-pill" data-range="custom"><?php echo __('fin.custom', 'Custom'); ?></button>
                </div>
                <div class="d-fin-period" id="finPeriodLabel"></div>
                <div class="d-fin-custom-dates" id="finCustomDates" style="display:none;">
                    <input type="date" id="finDateFrom" class="d-fin-date-input">
                    <span class="d-fin-date-sep">—</span>
                    <input type="date" id="finDateTo" class="d-fin-date-input">
                    <button class="d-fin-pill d-fin-pill-primary" id="finApplyCustom"><?php echo __('fin.apply', 'Apply'); ?></button>
                </div>
            </div>
        </div>

        <!-- ═══ P&L Summary (Row 1: Revenue → COGS → Gross Profit → OpEx) ═══ -->
        <div class="fin-card animate-in animate-in-d1" data-section="fin1">
            <div class="fin-card-bar blue"></div>
            <div class="fin-card-body">
                <div class="fin-card-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div>
                <div class="fin-card-content">
                    <div class="fin-card-value" id="finRevenue"></div>
                    <div class="fin-card-label"><?php echo __('fin.total_revenue', 'Total Revenue'); ?></div>
                    <div class="fin-card-change" id="finRevenueChange"></div>
                </div>
            </div>
        </div>

        <div class="fin-card animate-in animate-in-d2" data-section="fin2">
            <div class="fin-card-bar red"></div>
            <div class="fin-card-body">
                <div class="fin-card-icon red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
                <div class="fin-card-content">
                    <div class="fin-card-value" id="finCOGS"></div>
                    <div class="fin-card-label"><?php echo __('fin.cogs', 'COGS (Refill Costs)'); ?></div>
                    <div class="fin-card-change" id="finCOGSChange"></div>
                </div>
            </div>
        </div>

        <div class="fin-card animate-in animate-in-d3" data-section="fin3">
            <div class="fin-card-bar green"></div>
            <div class="fin-card-body">
                <div class="fin-card-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div class="fin-card-content">
                    <div class="fin-card-value" id="finGrossProfit"></div>
                    <div class="fin-card-label"><?php echo __('fin.gross_profit', 'Gross Profit'); ?></div>
                    <div class="fin-card-change" id="finGrossProfitChange"></div>
                    <div class="fin-card-sub" id="finMarginLabel"></div>
                </div>
            </div>
        </div>

        <div class="fin-card animate-in animate-in-d4" data-section="fin4">
            <div class="fin-card-bar amber"></div>
            <div class="fin-card-body">
                <div class="fin-card-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
                <div class="fin-card-content">
                    <div class="fin-card-value" id="finOpEx"></div>
                    <div class="fin-card-label"><?php echo __('fin.opex', 'Operating Expenses'); ?></div>
                    <div class="fin-card-change" id="finOpExChange"></div>
                </div>
            </div>
        </div>

        <!-- ═══ Bottom Line (Row 2: Net Profit → GST → Profit After GST → Cash) ═══ -->
        <div class="fin-card animate-in animate-in-d2" data-section="fin5">
            <div class="fin-card-bar purple"></div>
            <div class="fin-card-body">
                <div class="fin-card-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <div class="fin-card-content">
                    <div class="fin-card-value" id="finNetProfit"></div>
                    <div class="fin-card-label"><?php echo __('fin.net_profit', 'Net Profit'); ?></div>
                    <div class="fin-card-change" id="finNetProfitChange"></div>
                </div>
            </div>
        </div>

        <div class="fin-card animate-in animate-in-d2" data-section="fin6">
            <div class="fin-card-bar amber"></div>
            <div class="fin-card-body">
                <div class="fin-card-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <div class="fin-card-content">
                    <div class="fin-card-value" id="finGstNet"></div>
                    <div class="fin-card-label"><?php echo __('fin.gst_net', 'GST Net Payable'); ?></div>
                    <div class="fin-card-change" id="finGstNetChange"></div>
                </div>
            </div>
        </div>

        <div class="fin-card animate-in animate-in-d3" data-section="fin7">
            <div class="fin-card-bar cyan"></div>
            <div class="fin-card-body">
                <div class="fin-card-icon cyan"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <div class="fin-card-content">
                    <div class="fin-card-value" id="finProfitAfterGst"></div>
                    <div class="fin-card-label"><?php echo __('fin.profit_after_gst', 'Profit After GST'); ?></div>
                    <div class="fin-card-change" id="finProfitAfterGstChange"></div>
                </div>
            </div>
        </div>

        <div class="fin-card animate-in animate-in-d3" data-section="fin8">
            <div class="fin-card-body">
                <div class="fin-card-icon teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg></div>
                <div class="fin-card-content">
                    <div class="fin-card-value" id="finCashBalance"></div>
                    <div class="fin-card-label"><?php echo __('fin.cash_balance', 'Cash Balance'); ?></div>
                </div>
            </div>
        </div>

        <!-- ═══ Quick Actions ═══ -->
        <div class="dash-span-4 animate-in animate-in-d2" data-section="quickActions">
            <div class="dash-card dash-actions-card">
                <div class="dash-section-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    <?php echo __('dash.quick_actions', 'Quick Actions'); ?>
                </div>
                <div class="d-actions" id="dashQuickActions"></div>
            </div>
        </div>

        <!-- ═══ Row 3: Cycle Lifecycle + Partner Exchange ═══ -->
        <div class="dash-span-4 animate-in animate-in-d4 dash-flex-row">
            <div class="dash-card dash-flex-1" data-section="lifecycleBar">
                <div class="dash-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?php echo __('dash.lifecycle', 'Cycle Lifecycle'); ?>
                    <span class="dash-stats-hint" id="lifecycleLegendHint"></span>
                </div>
                <div class="dash-chart-fill">
                    <canvas id="lifecycleChart"></canvas>
                </div>
            </div>
            <div class="dash-card dash-partner-card" data-section="partnerExchange">
                <div class="dash-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <?php echo __('dash.partner_exchange', 'Partner Exchange'); ?>
                </div>
                <div class="partner-mini-grid" id="partnerExchangeGrid"></div>
            </div>
        </div>

        <!-- ═══ Row 4b: Timeline ═══ -->
        <div class="dash-card dash-span-2 animate-in animate-in-d4 dash-minh-380" data-section="timeline">
            <div class="dash-section-title dash-title-between">
                <span><?php echo __('dash.recent_activity', 'Recent Activity'); ?></span>
                <a href="activity-logs.php" class="dash-stats-hint dash-link-accent">View All</a>
            </div>
            <div class="dash-timeline" id="dashTimelineContainer"></div>
        </div>

        <!-- ═══ Row 5: Revenue Trend (full width) ═══ -->
        <div class="dash-card dash-span-2 animate-in animate-in-d3 dash-minh-380" data-section="revenueTrend">
            <div class="dash-section-title dash-title-between">
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg> <?php echo __('dash.revenue_trend', 'Revenue Trend (30 Days)'); ?></span>
                <span class="dash-stats-hint" id="revenueMetaLine"></span>
            </div>
            <div class="dash-chart-wrap"><canvas id="revenueChart"></canvas></div>
        </div>

        <!-- ═══ Row 6: Top Products + Inventory + Expense Donut (flex row) ═══ -->
        <div class="dash-span-4 animate-in animate-in-d3 dash-flex-row">
            <div class="dash-card dash-flex-1" data-section="topProducts">
                <div class="dash-section-title"><span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> <?php echo __('dash.top_products', 'Top Products'); ?></span></div>
                <div id="dashProductRanking"></div>
            </div>

            <div class="dash-card dash-flex-1" data-section="inventory">
                <div class="dash-section-title"><span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg> <?php echo __('dash.inventory_levels', 'Inventory Levels'); ?></span></div>
                <div id="dashInvContainer"></div>
            </div>

            <div class="dash-card dash-flex-1" data-section="expenseDonut">
                <div class="dash-section-title"><span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> <?php echo __('dash.expense_breakdown', 'Expense Breakdown'); ?></span></div>
                <div class="dash-chart-wrap-lg"><canvas id="expenseDonutChart"></canvas></div>
            </div>
        </div>

        <!-- ═══ Row 8: Alerts ═══ -->
        <div class="dash-card animate-in animate-in-d3 dash-span-4" data-section="alerts">
            <div class="dash-section-title"><span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?php echo __('dash.alerts_section', 'Alerts'); ?></span></div>
            <div><div class="d-alert-bar" id="dashAlertBar"></div></div>
        </div>

        <!-- ═══ Row 9: Cash Flow + Operations + AI Insights ═══ -->
        <div class="dash-span-4 animate-in animate-in-d3 dash-flex-row">
            <div class="dash-card dash-flex-1" data-section="cashFlow">
                <div class="dash-section-title"><span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg> <?php echo __('dash.cash_flow', 'Cash Flow (12 Months)'); ?></span></div>
                <div class="dash-chart-wrap-lg"><canvas id="cashFlowChart"></canvas></div>
            </div>
            <div class="dash-card" data-section="operations">
                <div class="dash-section-title"><span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> <?php echo __('dash.operations', 'Operations'); ?></span></div>
                <div class="d-ops-grid" id="dashOpsGrid"></div>
            </div>
            <div class="dash-card glass dash-flex-1" data-section="aiInsights">
                <div class="dash-ai-header">
                    <div class="dash-ai-header-title">
                        <span class="dash-ai-icon">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        </span>
                        <?php echo __('dash.ai_title', 'AI Business Insights'); ?>
                    </div>
                    <div class="dash-ai-actions">
                        <button class="dash-ai-btn dash-ai-btn-ghost" onclick="location.reload()"><?php echo __('dash.ai_refresh', 'Refresh'); ?></button>
                        <a href="ai-assistant.php" class="dash-ai-btn dash-ai-btn-primary"><?php echo __('dash.ai_open', 'Open Assistant'); ?></a>
                    </div>
                </div>
                <div id="aiInsightsContent" class="dash-ai-grid"></div>
            </div>
        </div>

    </div>
</div>

<script id="dashUserRole" type="text/plain"><?php echo htmlspecialchars($userRole); ?></script>
<script>var DASH_AJAX_URL = "<?php echo $ajaxUrl; ?>";</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script src="dashboard.js?v=<?php echo filemtime(__DIR__ . '/dashboard.js'); ?>"></script>

<?php require_once 'layout_footer.php'; ?>
