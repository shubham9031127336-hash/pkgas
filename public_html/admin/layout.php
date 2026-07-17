<?php
/**
 * Prem Gas Solution - Sidebar Layout Master Template
 * Included at the top of admin pages to enforce session security and load visual framework.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_login();
validateCsrfToken();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header_remove('X-Powered-By');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ---- Output buffering to enable PHP header() redirects from included pages ----
if (ob_get_level() === 0) { ob_start(); }

// ---- Language System ----
require_once __DIR__ . '/lang_init.php';

// ---- Business Config ----
require_once __DIR__ . '/business_helper.php';
$brand_cfg = getBrandConfig();

// Fallbacks for layout metadata
if (!isset($page_title)) {
    $page_title = "Gas Refilling CMS";
}
if (!isset($active_menu)) {
    $active_menu = "dashboard";
}
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../Images/favicon.png">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars($brand_cfg['label']); ?></title>
    
    <!-- Core Admin CSS Overrides -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="stylesheet" href="admin-style.css?v=<?php echo filemtime(__DIR__.'/admin-style.css'); ?>">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;500;600;700;800&display=swap"></noscript>
</head>
    <body>
    <!-- Skip to content link for keyboard users -->
    <a href="#main-content" class="skip-link" style="position:absolute;top:-100px;left:0;z-index:9999;padding:0.75rem 1.5rem;background:var(--admin-accent);color:#fff;font-weight:700;text-decoration:none;border-radius:0 0 12px 0;transition:top 0.2s;"><?php echo __('common.skip_to_content', 'Skip to content'); ?></a>
    
    <!-- Sidebar Navigation Left Menu -->
    <aside class="sidebar" id="sidebarMenu" aria-label="<?php echo __('sidebar.label', 'Main navigation'); ?>">
        <button class="sidebar-close" id="sidebarClose" aria-label="<?php echo __('common.close'); ?>">&times;</button>
        <div class="sidebar-logo">
            <?php $logo_src = !empty($brand_cfg['logo_white_path']) ? '../' . $brand_cfg['logo_white_path'] : '../Images/logo.png'; ?>
            <img src="<?php echo $logo_src; ?>" alt="<?php echo htmlspecialchars($brand_cfg['label']); ?>" class="sidebar-logo-img">
            <h2><span class="logo-text"><?php echo htmlspecialchars($brand_cfg['label']); ?></span></h2>
        </div>
        
        <nav class="sidebar-nav" aria-label="<?php echo __('sidebar.nav_label', 'Pages'); ?>">
            <a href="dashboard.php" class="nav-item <?php echo $active_menu === 'dashboard' ? 'active' : ''; ?>" <?php echo $active_menu === 'dashboard' ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>
                </svg>
                <span><?php echo __('sidebar.dashboard'); ?></span>
            </a>
            
            <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
            <a href="customers.php" class="nav-item <?php echo $active_menu === 'customers' ? 'active' : ''; ?>" <?php echo $active_menu === 'customers' ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <span><?php echo __('sidebar.customers'); ?></span>
            </a>
            <?php endif; ?>
            
            <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
            <div class="nav-dropdown">
                <?php 
                    $is_invoice_dropdown_active = in_array($active_menu, ['customer_invoices', 'vendor_invoices_list', 'purchase_invoices_list']); 
                ?>
                <button class="dropdown-toggle <?php echo $is_invoice_dropdown_active ? 'active' : ''; ?>" onclick="toggleNavDropdown(this)" style="outline: none;" aria-expanded="<?php echo $is_invoice_dropdown_active ? 'true' : 'false'; ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="10" x2="20" y2="10"/><line x1="4" y1="14" x2="20" y2="14"/><line x1="4" y1="18" x2="20" y2="18"/>
                    </svg>
                    <span>Invoices</span>
                    <svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; margin-left: auto; transition: transform 0.2s; <?php echo $is_invoice_dropdown_active ? 'transform: rotate(180deg);' : ''; ?>">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="dropdown-menu<?php echo $is_invoice_dropdown_active ? ' menu-open' : ''; ?>" style="<?php echo $is_invoice_dropdown_active ? 'display: block;' : 'display: none;'; ?> padding-left: 1.25rem; margin-top: 4px;">
                    
                    <a href="customer-invoices.php" class="nav-item <?php echo $active_menu === 'customer_invoices' ? 'active' : ''; ?>" <?php echo $active_menu === 'customer_invoices' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        <span>Customer Invoices</span>
                    </a>
                    
                    <a href="vendor-invoices.php?from_invoice=1" class="nav-item <?php echo $active_menu === 'vendor_invoices_list' ? 'active' : ''; ?>" <?php echo $active_menu === 'vendor_invoices_list' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                        <span>Vendor Invoices</span>
                    </a>
                    
                    <a href="cylinder-purchases.php?from_invoice=1" class="nav-item <?php echo $active_menu === 'purchase_invoices_list' ? 'active' : ''; ?>" <?php echo $active_menu === 'purchase_invoices_list' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
                        </svg>
                        <span>Purchase Invoices</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (has_role(['super_admin', 'warehouse_supervisor'])): ?>
            <a href="inventory.php" class="nav-item <?php echo $active_menu === 'inventory' ? 'active' : ''; ?>" <?php echo $active_menu === 'inventory' ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="16" y1="18" x2="22" y2="18"/><line x1="8" y1="12" x2="22" y2="12"/><line x1="12" y1="6" x2="22" y2="6"/><circle cx="12" cy="18" r="3"/><circle cx="4" cy="12" r="3"/><circle cx="8" cy="6" r="3"/>
                </svg>
                <span><?php echo __('sidebar.inventory'); ?></span>
            </a>
            
            <a href="cylinders.php" class="nav-item <?php echo in_array($active_menu, ['cylinders', 'cylinders_send', 'cylinders_receive']) ? 'active' : ''; ?>" <?php echo in_array($active_menu, ['cylinders', 'cylinders_send', 'cylinders_receive']) ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>
                </svg>
                <span><?php echo __('sidebar.cylinders'); ?></span>
            </a>

            <a href="lot-dashboard.php" class="nav-item <?php echo $active_menu === 'lot_dashboard' ? 'active' : ''; ?>" <?php echo $active_menu === 'lot_dashboard' ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M10 17h4V5H4v12h1m8-5h6l3 3v2h-1a2 2 0 0 1-4 0h-4"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>
                </svg>
                <span>Dispatch Lots</span>
            </a>
            <?php endif; ?>
            
            <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
            <a href="refill-orders.php" class="nav-item <?php echo $active_menu === 'orders' ? 'active' : ''; ?>" <?php echo $active_menu === 'orders' ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                <span><?php echo __('sidebar.orders'); ?></span>
            </a>

            <a href="cylinder-exchange.php" class="nav-item <?php echo $active_menu === 'exchange' ? 'active' : ''; ?>" <?php echo $active_menu === 'exchange' ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
                <span>Customer Exchange</span>
            </a>
            <?php endif; ?>

            <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
            <a href="blog-manager.php" class="nav-item <?php echo $active_menu === 'blog' ? 'active' : ''; ?>" <?php echo $active_menu === 'blog' ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                </svg>
                <span><?php echo __('sidebar.blog'); ?></span>
            </a>
            <?php endif; ?>

            <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
            <div class="nav-dropdown">
                <?php
                    $is_expense_dropdown_active = in_array($active_menu, ['expenses', 'expense_create', 'expense_categories', 'expense_reports']);
                ?>
                <button class="dropdown-toggle <?php echo $is_expense_dropdown_active ? 'active' : ''; ?>" onclick="toggleNavDropdown(this)" style="outline: none;" aria-expanded="<?php echo $is_expense_dropdown_active ? 'true' : 'false'; ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/><path d="M12 1l3 3-3 3"/><path d="M12 17l3 3-3 3"/>
                    </svg>
                    <span><?php echo __('sidebar.expenses'); ?></span>
                    <svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; margin-left: auto; transition: transform 0.2s; <?php echo $is_expense_dropdown_active ? 'transform: rotate(180deg);' : ''; ?>">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="dropdown-menu<?php echo $is_expense_dropdown_active ? ' menu-open' : ''; ?>" style="<?php echo $is_expense_dropdown_active ? 'display: block;' : 'display: none;'; ?> padding-left: 1.25rem; margin-top: 4px;">

                    <a href="expenses.php" class="nav-item <?php echo $active_menu === 'expenses' ? 'active' : ''; ?>" <?php echo $active_menu === 'expenses' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>
                        </svg>
                        <span><?php echo __('sidebar.expenses_all'); ?></span>
                    </a>

                    <a href="expense-create.php" class="nav-item <?php echo $active_menu === 'expense_create' ? 'active' : ''; ?>" <?php echo $active_menu === 'expense_create' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        <span><?php echo __('sidebar.expenses_add'); ?></span>
                    </a>

                    <a href="expense-categories.php" class="nav-item <?php echo $active_menu === 'expense_categories' ? 'active' : ''; ?>" <?php echo $active_menu === 'expense_categories' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>
                        </svg>
                        <span><?php echo __('sidebar.expenses_categories'); ?></span>
                    </a>

                    <a href="expense-reports.php" class="nav-item <?php echo $active_menu === 'expense_reports' ? 'active' : ''; ?>" <?php echo $active_menu === 'expense_reports' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                        </svg>
                        <span><?php echo __('sidebar.expenses_reports'); ?></span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <div class="nav-dropdown">
                <?php 
                    $is_exchanges_dropdown_active = in_array($active_menu, ['partners', 'vendors', 'vendor_settlement', 'partner_transactions', 'partner_reports', 'cylinder_suppliers', 'cylinder_purchases', 'supplier_ledger']); 
                ?>
                <button class="dropdown-toggle <?php echo $is_exchanges_dropdown_active ? 'active' : ''; ?>" onclick="toggleNavDropdown(this)" style="outline: none;" aria-expanded="<?php echo $is_exchanges_dropdown_active ? 'true' : 'false'; ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/>
                    </svg>
                    <span><?php echo __('sidebar.exchanges'); ?></span>
                    <svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; margin-left: auto; transition: transform 0.2s; <?php echo $is_exchanges_dropdown_active ? 'transform: rotate(180deg);' : ''; ?>">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="dropdown-menu<?php echo $is_exchanges_dropdown_active ? ' menu-open' : ''; ?>" style="<?php echo $is_exchanges_dropdown_active ? 'display: block;' : 'display: none;'; ?> padding-left: 1.25rem; margin-top: 4px;">
                    
                    <?php if (has_role(['super_admin', 'warehouse_supervisor', 'billing_clerk'])): ?>
                    <a href="partners.php" class="nav-item <?php echo in_array($active_menu, ['partners', 'partner_transactions', 'partner_reports']) ? 'active' : ''; ?>" <?php echo in_array($active_menu, ['partners', 'partner_transactions', 'partner_reports']) ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        <span><?php echo __('sidebar.partners'); ?></span>
                    </a>
                    <?php endif; ?>
                    
                    <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
                    <a href="vendors.php" class="nav-item <?php echo $active_menu === 'vendors' ? 'active' : ''; ?>" <?php echo $active_menu === 'vendors' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M4 21V7l8-4 8 4v14"/><path d="M4 21h16"/><line x1="8" y1="9" x2="10" y2="9"/><line x1="8" y1="13" x2="10" y2="13"/><line x1="8" y1="17" x2="10" y2="17"/><line x1="14" y1="9" x2="16" y2="9"/><line x1="14" y1="13" x2="16" y2="13"/><line x1="14" y1="17" x2="16" y2="17"/>
                        </svg>
                        <span><?php echo __('sidebar.vendors'); ?></span>
                    </a>
                    <a href="vendor-settlement.php" class="nav-item <?php echo $active_menu === 'vendor_settlement' ? 'active' : ''; ?>" <?php echo $active_menu === 'vendor_settlement' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>
                        </svg>
                        <span>Settlement</span>
                    </a>
                    <?php endif; ?>

                    <?php if (has_role(['super_admin', 'warehouse_supervisor', 'billing_clerk'])): ?>
                    <hr style="border-color:var(--admin-border);margin:0.5rem 0.75rem;">
                    <span style="font-size:0.65rem;color:var(--admin-muted);padding:0.25rem 1rem;display:block;text-transform:uppercase;letter-spacing:0.05em;"><?php echo __('sidebar.suppliers_section'); ?></span>
                    <a href="cylinder-suppliers.php" class="nav-item <?php echo $active_menu === 'cylinder_suppliers' ? 'active' : ''; ?>" <?php echo $active_menu === 'cylinder_suppliers' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        <span><?php echo __('sidebar.suppliers'); ?></span>
                    </a>
                    <a href="cylinder-purchases.php" class="nav-item <?php echo $active_menu === 'cylinder_purchases' ? 'active' : ''; ?>" <?php echo $active_menu === 'cylinder_purchases' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
                        </svg>
                        <span><?php echo __('sidebar.purchases'); ?></span>
                    </a>
                    <a href="cylinder-supplier-ledger.php" class="nav-item <?php echo $active_menu === 'supplier_ledger' ? 'active' : ''; ?>" <?php echo $active_menu === 'supplier_ledger' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>
                        </svg>
                        <span><?php echo __('sidebar.supplier_ledger'); ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="nav-dropdown">
                <?php 
                    $is_dropdown_active = in_array($active_menu, ['gas_types', 'users', 'reports']); 
                ?>
                <button class="dropdown-toggle <?php echo $is_dropdown_active ? 'active' : ''; ?>" onclick="toggleNavDropdown(this)" style="outline: none;" aria-expanded="<?php echo $is_dropdown_active ? 'true' : 'false'; ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="4" y1="21" x2="4" y2="14"/>
                        <line x1="4" y1="10" x2="4" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="12"/>
                        <line x1="12" y1="8" x2="12" y2="3"/>
                        <line x1="20" y1="21" x2="20" y2="16"/>
                        <line x1="20" y1="12" x2="20" y2="3"/>
                        <line x1="1" y1="14" x2="7" y2="14"/>
                        <line x1="9" y1="8" x2="15" y2="8"/>
                        <line x1="17" y1="16" x2="23" y2="16"/>
                    </svg>
                    <span><?php echo __('sidebar.administration'); ?></span>
                    <svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; margin-left: auto; transition: transform 0.2s; <?php echo $is_dropdown_active ? 'transform: rotate(180deg);' : ''; ?>">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="dropdown-menu<?php echo $is_dropdown_active ? ' menu-open' : ''; ?>" style="<?php echo $is_dropdown_active ? 'display: block;' : 'display: none;'; ?> padding-left: 1.25rem; margin-top: 4px;">
                    
                    <?php if (has_role(['super_admin', 'warehouse_supervisor'])): ?>
                    <a href="gas-types.php" class="nav-item <?php echo $active_menu === 'gas_types' ? 'active' : ''; ?>" <?php echo $active_menu === 'gas_types' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
                        </svg>
                        <span><?php echo __('sidebar.gas_types'); ?></span>
                    </a>
                    
                    <?php endif; ?>
                    
                    <?php if (has_role('super_admin')): ?>
                    <a href="users-manager.php" class="nav-item <?php echo $active_menu === 'users' ? 'active' : ''; ?>" <?php echo $active_menu === 'users' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        <span><?php echo __('sidebar.staff'); ?></span>
                    </a>
                    <?php endif; ?>

                    <a href="reports.php" class="nav-item <?php echo $active_menu === 'reports' ? 'active' : ''; ?>" <?php echo $active_menu === 'reports' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                        </svg>
                        <span><?php echo __('sidebar.reports'); ?></span>
                    </a>
                </div>
            </div>
            
            <?php if (has_role(['super_admin', 'billing_clerk', 'warehouse_supervisor'])): ?>
            <a href="ai-assistant.php" class="nav-item <?php echo $active_menu === 'ai_assistant' ? 'active' : ''; ?>" <?php echo $active_menu === 'ai_assistant' ? 'aria-current="page"' : ''; ?>>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 8V4m0 0L9 7m3-3l3 3"/><path d="M4 12H2m2 0a10 10 0 0 0 10 10m0 0v2m0-2a10 10 0 0 0 10-10m0-2h2"/><circle cx="12" cy="12" r="3"/>
                </svg>
                <span><?php echo __('sidebar.ai_assistant'); ?></span>
            </a>
            <?php endif; ?>

            <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
            <div class="nav-dropdown">
                <?php 
                    $is_gst_dropdown_active = in_array($active_menu, ['gst_dashboard', 'gst_register', 'gst_settlement', 'gst_reports', 'gst_return_center', 'gst_filing_config', 'gst_reconciliation', 'gst_return_reports']); 
                ?>
                <button class="dropdown-toggle <?php echo $is_gst_dropdown_active ? 'active' : ''; ?>" onclick="toggleNavDropdown(this)" style="outline: none;" aria-expanded="<?php echo $is_gst_dropdown_active ? 'true' : 'false'; ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8"/><path d="M8 11h6"/><path d="M8 15h4"/>
                    </svg>
                    <span>GST Accounting</span>
                    <svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; margin-left: auto; transition: transform 0.2s; <?php echo $is_gst_dropdown_active ? 'transform: rotate(180deg);' : ''; ?>">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="dropdown-menu<?php echo $is_gst_dropdown_active ? ' menu-open' : ''; ?>" style="<?php echo $is_gst_dropdown_active ? 'display: block;' : 'display: none;'; ?> padding-left: 1.25rem; margin-top: 4px;">
                    
                    <a href="gst-dashboard.php" class="nav-item <?php echo $active_menu === 'gst_dashboard' ? 'active' : ''; ?>" <?php echo $active_menu === 'gst_dashboard' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        <span>GST Dashboard</span>
                    </a>
                    
                    <a href="gst-register.php" class="nav-item <?php echo $active_menu === 'gst_register' ? 'active' : ''; ?>" <?php echo $active_menu === 'gst_register' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>
                        </svg>
                        <span>GST Register</span>
                    </a>
                    
                    <a href="gst-return-center.php" class="nav-item <?php echo $active_menu === 'gst_return_center' ? 'active' : ''; ?>" <?php echo $active_menu === 'gst_return_center' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem; font-weight: 700;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                        </svg>
                        <span>★ GST Return Center</span>
                    </a>
                    
                    <a href="gst-reports.php" class="nav-item <?php echo $active_menu === 'gst_reports' ? 'active' : ''; ?>" <?php echo $active_menu === 'gst_reports' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                        </svg>
                        <span>GST Reports</span>
                    </a>
                    
                    <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
                    <a href="gst-settlement.php" class="nav-item <?php echo $active_menu === 'gst_settlement' ? 'active' : ''; ?>" <?php echo $active_menu === 'gst_settlement' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <span>GST Settlement</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (has_role('super_admin')): ?>
            <div class="nav-dropdown">
                <?php 
                    $is_settings_dropdown_active = in_array($active_menu, ['settings', 'settings_ai', 'settings_brand', 'bulk_audit']); 
                ?>
                <button class="dropdown-toggle <?php echo $is_settings_dropdown_active ? 'active' : ''; ?>" onclick="toggleNavDropdown(this)" style="outline: none;" aria-expanded="<?php echo $is_settings_dropdown_active ? 'true' : 'false'; ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                    <span><?php echo __('sidebar.settings'); ?></span>
                    <svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; margin-left: auto; transition: transform 0.2s; <?php echo $is_settings_dropdown_active ? 'transform: rotate(180deg);' : ''; ?>">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="dropdown-menu<?php echo $is_settings_dropdown_active ? ' menu-open' : ''; ?>" style="<?php echo $is_settings_dropdown_active ? 'display: block;' : 'display: none;'; ?> padding-left: 1.25rem; margin-top: 4px;">
                    <a href="settings.php" class="nav-item <?php echo $active_menu === 'settings' ? 'active' : ''; ?>" <?php echo $active_menu === 'settings' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>
                        </svg>
                        <span><?php echo __('sidebar.settings_general'); ?></span>
                    </a>
                    
                    <a href="settings-ai.php" class="nav-item <?php echo $active_menu === 'settings_ai' ? 'active' : ''; ?>" <?php echo $active_menu === 'settings_ai' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M12 8V4m0 0L9 7m3-3l3 3"/><path d="M4 12H2m2 0a10 10 0 0 0 10 10m0 0v2m0-2a10 10 0 0 0 10-10m0-2h2"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                        <span><?php echo __('sidebar.settings_ai'); ?></span>
                    </a>
                    
                    <a href="settings-brand.php" class="nav-item <?php echo $active_menu === 'settings_brand' ? 'active' : ''; ?>" <?php echo $active_menu === 'settings_brand' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        <span><?php echo __('sidebar.settings_brand'); ?></span>
                    </a>
                    <a href="bulk_operation_audit_view.php" class="nav-item <?php echo $active_menu === 'bulk_audit' ? 'active' : ''; ?>" <?php echo $active_menu === 'bulk_audit' ? 'aria-current="page"' : ''; ?> style="font-size: 0.85rem; padding: 0.65rem 1rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;" aria-hidden="true">
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="10" y1="11" x2="14" y2="11"/><line x1="10" y1="15" x2="14" y2="15"/><line x1="10" y1="19" x2="12" y2="19"/>
                        </svg>
                        <span>Bulk Op. Audit</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </nav>
        
        <div class="sidebar-footer">
            <div class="user-badge" style="padding: 0.5rem 0; width: 100%;">
                <div class="user-avatar" style="background: var(--admin-accent); font-weight: 700; text-transform: uppercase;">
                    <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="user-info">
                    <h4 style="font-size: 0.85rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px;">
                        <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Staff User'); ?>
                    </h4>
                    <p style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-muted);">
                        <?php echo str_replace('_', ' ', $_SESSION['user_role'] ?? 'Clerk'); ?>
                    </p>
                </div>
            </div>
        </div>
    </aside>
    
    <!-- Main Right Panel -->
    <div class="main-wrapper">
        <!-- Top Sticky Header -->
        <header class="top-bar">
            <button class="menu-toggle" id="menuToggle" aria-label="<?php echo __('topbar.toggle_nav'); ?>">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <div class="top-bar-title"><?php echo htmlspecialchars($page_title); ?></div>
            <div class="top-bar-actions">
                <!-- Language Switcher -->
                <a href="?lang=<?php echo $lang === 'hi' ? 'en' : 'hi'; ?>" style="font-size:0.8rem;font-weight:700;padding:6px 12px;border-radius:8px;border:1px solid var(--admin-border);text-decoration:none;color:var(--admin-fg);background:var(--admin-surface);display:flex;align-items:center;gap:4px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    <span class="action-label"><?php echo $lang === 'hi' ? 'English' : 'हिन्दी'; ?></span>
                </a>
                <?php if (has_role(['super_admin', 'billing_clerk'])): ?>
                <a href="order-create.php" class="btn-primary glow-ring">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span class="action-label"><?php echo __('topbar.new_refill'); ?></span>
                </a>
                <?php endif; ?>
                <a href="logout.php" style="color: #ef4444; font-weight: 700; text-decoration: none; font-size: 0.9rem; margin-left: 1rem;"><span class="action-label"><?php echo __('logout'); ?></span></a>
            </div>
        </header>
        
        <!-- Reusable Content Frame -->
        <main class="content-container" id="main-content" role="main">
            <?php if (isset($_SESSION['error_flash'])): ?>
                <div id="flashError" data-message="<?php echo htmlspecialchars($_SESSION['error_flash']); ?>" style="display:none;"></div>
                <?php unset($_SESSION['error_flash']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_flash'])): ?>
                <div id="flashSuccess" data-message="<?php echo htmlspecialchars($_SESSION['success_flash']); ?>" style="display:none;"></div>
                <?php unset($_SESSION['success_flash']); ?>
            <?php endif; ?>
            <?php
            $breadcrumb_map = [
                'dashboard'  => [['label' => __('sidebar.dashboard'), 'url' => 'dashboard.php']],
                'customers'  => [['label' => __('sidebar.customers'), 'url' => 'customers.php']],
                'inventory'  => [['label' => __('sidebar.inventory'), 'url' => 'inventory.php']],
                'cylinders'  => [['label' => __('sidebar.cylinders'), 'url' => 'cylinders.php']],
                'cylinders_send' => [['label' => __('sidebar.cylinders'), 'url' => 'cylinders.php'], ['label' => 'Send Cylinders', 'url' => 'send-cylinder.php']],
                'cylinders_receive' => [['label' => __('sidebar.cylinders'), 'url' => 'cylinders.php'], ['label' => 'Receive Cylinders', 'url' => 'receive-cylinder.php']],
                'lot_dashboard' => [['label' => 'Cylinders', 'url' => 'cylinders.php'], ['label' => 'Dispatch Lots', 'url' => 'lot-dashboard.php']],
                'orders'     => [['label' => __('sidebar.orders'), 'url' => 'refill-orders.php']],
                'exchange'   => [['label' => 'Customer Exchange', 'url' => 'cylinder-exchange.php']],
                'blog'       => [['label' => __('sidebar.blog'), 'url' => 'blog-manager.php']],
                'partners'   => [['label' => __('sidebar.partners'), 'url' => 'partners.php']],
                'partner_reports' => [['label' => __('sidebar.partners'), 'url' => 'partners.php'], ['label' => 'Partner Reports', 'url' => 'partner-reports.php']],
                'partner_transactions' => [['label' => __('sidebar.partners'), 'url' => 'partners.php'], ['label' => __('partner_tx.title'), 'url' => 'partner-transactions.php']],
                'gas-types'  => [['label' => __('administration'), 'url' => '#'], ['label' => __('sidebar.gas_types'), 'url' => 'gas-types.php']],
                'gas_types'  => [['label' => __('administration'), 'url' => '#'], ['label' => __('sidebar.gas_types'), 'url' => 'gas-types.php']],
                'users'      => [['label' => __('administration'), 'url' => '#'], ['label' => __('sidebar.staff'), 'url' => 'users-manager.php']],
                'vendors'    => [['label' => __('sidebar.vendors'), 'url' => 'vendors.php']],
                'vendor_settlement' => [['label' => __('sidebar.vendors'), 'url' => 'vendors.php'], ['label' => 'Settlement', 'url' => 'vendor-settlement.php']],
                'reports'    => [['label' => __('administration'), 'url' => '#'], ['label' => __('sidebar.reports'), 'url' => 'reports.php']],
                'ai_assistant' => [['label' => __('sidebar.ai_assistant'), 'url' => 'ai-assistant.php']],
                'settings'   => [['label' => __('sidebar.settings'), 'url' => 'settings.php'], ['label' => __('sidebar.settings_general'), 'url' => 'settings.php']],
                'settings_ai' => [['label' => __('sidebar.settings'), 'url' => 'settings.php'], ['label' => __('sidebar.settings_ai'), 'url' => 'settings-ai.php']],
                'settings_brand' => [['label' => __('sidebar.settings'), 'url' => 'settings.php'], ['label' => __('sidebar.settings_brand'), 'url' => 'settings-brand.php']],
                'gst_dashboard' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'Dashboard', 'url' => 'gst-dashboard.php']],
                'gst_register' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'GST Register', 'url' => 'gst-register.php']],
                'gst_settlement' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'Settlement', 'url' => 'gst-settlement.php']],
                'gst_reports' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'Reports', 'url' => 'gst-reports.php']],
                'gst_return_center' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'Return Center', 'url' => 'gst-return-center.php']],
                'gst_filing_config' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'GST Settings', 'url' => 'gst-filing-config.php']],
                'gst_input' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'Input GST', 'url' => 'gst-register.php?tab=input']],
                'gst_output' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'Output GST', 'url' => 'gst-register.php?tab=output']],
                'gst_ledger' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'GST Ledger', 'url' => 'gst-register.php?tab=ledger']],
                'gst_reconciliation' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'Reconciliation', 'url' => 'gst-reports.php?tab=reconciliation']],
                'gst_return_reports' => [['label' => 'GST Accounting', 'url' => 'gst-dashboard.php'], ['label' => 'Return Reports', 'url' => 'gst-return-center.php']],

                'cylinder_suppliers' => [['label' => __('sidebar.suppliers'), 'url' => 'cylinder-suppliers.php']],
                'cylinder_purchases' => [['label' => __('sidebar.suppliers'), 'url' => 'cylinder-suppliers.php'], ['label' => __('purchases.heading'), 'url' => 'cylinder-purchases.php']],
                'supplier_ledger' => [['label' => __('sidebar.suppliers'), 'url' => 'cylinder-suppliers.php'], ['label' => __('ledger.heading'), 'url' => 'cylinder-supplier-ledger.php']],
                'customer_invoices' => [['label' => 'Invoices', 'url' => 'customer-invoices.php'], ['label' => 'Customer Invoices', 'url' => 'customer-invoices.php']],
                'vendor_invoices_list' => [['label' => 'Invoices', 'url' => 'customer-invoices.php'], ['label' => 'Vendor Invoices', 'url' => 'vendor-invoices.php']],
                'purchase_invoices_list' => [['label' => 'Invoices', 'url' => 'customer-invoices.php'], ['label' => 'Purchase Invoices', 'url' => 'cylinder-purchases.php']],
                'expenses' => [['label' => __('sidebar.expenses'), 'url' => 'expenses.php']],
                'expense_create' => [['label' => __('sidebar.expenses'), 'url' => 'expenses.php'], ['label' => 'Add Expense', 'url' => 'expense-create.php']],
                'expense_categories' => [['label' => __('sidebar.expenses'), 'url' => 'expenses.php'], ['label' => 'Categories', 'url' => 'expense-categories.php']],
                'expense_reports' => [['label' => __('sidebar.expenses'), 'url' => 'expenses.php'], ['label' => 'Reports', 'url' => 'expense-reports.php']],
            ];
            if (isset($breadcrumb_map[$active_menu])):
                $trail = $breadcrumb_map[$active_menu];
            ?>
            <nav aria-label="Breadcrumb" style="margin-bottom: 1.5rem;">
                <ol style="list-style:none;display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;margin:0;padding:0;font-size:0.8rem;">
                    <li><a href="dashboard.php" style="color:var(--admin-muted);text-decoration:none;"><?php echo __('sidebar.dashboard'); ?></a><span style="margin-left:0.5rem;color:var(--admin-muted);">/</span></li>
                    <?php foreach ($trail as $i => $crumb): ?>
                    <li>
                        <?php if ($i === array_key_last($trail)): ?>
                        <span aria-current="page" style="color:var(--admin-fg);font-weight:600;"><?php echo $crumb['label']; ?></span>
                        <?php else: ?>
                        <a href="<?php echo $crumb['url']; ?>" style="color:var(--admin-muted);text-decoration:none;"><?php echo $crumb['label']; ?></a><span style="margin-left:0.5rem;color:var(--admin-muted);">/</span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <?php endif; ?>
