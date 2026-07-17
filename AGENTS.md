# AGENTS.md — Nutan Gases

PHP 8.1+ flat-file procedural app (no framework, no OOP, no composer) with three sub-applications sharing one MySQL database.

## Layout

| Directory | Purpose |
|---|---|
| `public_html/` | Public marketing site + SEO landing pages |
| `public_html/admin/` | Admin panel: inventory, orders, customers, AI assistant |
| `public_html/admin/ai/` | AI assistant subsystem (agents, planning, memory, query, security, TTS) |
| `public_html/admin/lang/` | i18n language files (`en.php`, `hi.php`) |
| `public_html/portal/` | Customer self-service portal (17 PHP files) |
| `docs/` | Formal documentation (BRD, SRS, architecture, test reports) |
| `scratch/` | Development scratch/snapshot files (not documentation) |
| `Backup_working/` | Frozen snapshot of older `public_html/` — do not edit |

## Key config files

- `.env` — DB credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`)
- `public_html/admin/db.php` — Second set of hardcoded DB credentials (used at runtime). **Keep in sync with `.env`.**
- `public_html/admin/lang_init.php` — i18n (`en`/`hi`) via `__()` function
- `public_html/admin/csrf.php` — CSRF helpers (`csrfField()`, `validateCsrfToken()`)
- `public_html/admin/business_helper.php` — Multi-business config (`nutan_gases`, `vd_enterprises`)
- `public_html/admin/AI_SYSTEM_README.md` — Full system reference (schema, patterns, pitfalls) — **the single most detailed technical doc**
- `public_html/admin/mail-config.php` — SMTP credentials (gitignored; create from scratch)

## Admin page pattern

Every admin page follows this exact structure:
```php
$page_title = "...";
$active_menu = "...";           // matches nav item key in layout.php
require_once __DIR__ . '/layout.php';   // outputs <html>..<main>
require_role(['super_admin', 'warehouse_supervisor']);  // RBAC
require_once __DIR__ . '/db.php';
// POST handlers at top, GET rendering below
?>
<!-- HTML inside <main class="content-container"> -->
<?php require_once __DIR__ . '/layout_footer.php'; ?>
```

## Portal page pattern

Every portal page follows this structure:
```php
$page_title = "...";
require_once __DIR__ . '/header.php';   // includes auth.php → require_customer_login(), outputs <html>/<nav>
require_once __DIR__ . '/../admin/db.php';
$customer_id = get_customer_id();
// business logic
?>
<!-- HTML inside <main class="portal-main"> -->
<?php require_once __DIR__ . '/footer.php'; ?>
```

`public_html/portal/auth.php` provides session management, "Remember Me" cookie auto-login, and these helpers:
- `is_customer_logged_in()` → bool
- `require_customer_login()` → redirect to `login.php` if not authenticated
- `get_customer_id()` → int
- `get_customer_name()` → string
- `get_customer_email()` → string

Session timeout: 30 minutes (`$session_timeout = 1800`).

## Public site structure

Key files in `public_html/`:

| File | Purpose |
|---|---|
| `index.php` / `index.html` | Home page |
| `header.php` / `footer.php` | Shared layout partials |
| `header-meta.php` | Meta tags, OG tags, JSON-LD schema |
| `blog.php` / `post.php` / `archive.php` | Blog subsystem |
| `tracker.php` | Visit tracking |
| `chat-api.php` / `lead-capture.php` / `newsletter-subscribe.php` | Conversion endpoints |
| `translations.php` | `__()` i18n for public site |
| `sitemap.php` / `sitemap.xml` / `robots.txt` / `.htaccess` / `manifest.json` / `sw.js` | SEO + PWA |
| `*.php` (LPs) | SEO landing pages (acetylene, argon, CO2, oxygen, etc.) |

## Admin sub-apps

| Subsystem | Location | Purpose |
|---|---|---|
| **AI Assistant** | `admin/ai/` | 5 agents, planning, memory, query, security, TTS |
| **Blog** | `admin/blog-manager.php`, `admin/add-post.php` | Blog CRUD |
| **Cylinder Mgmt** | `admin/cylinders.php`, `admin/rent-cylinders.php`, `admin/cylinder-exchange.php`, `admin/customer-cylinders.php`, `admin/track-cylinder.php` | Full cylinder lifecycle |
| **Orders** | `admin/refill-orders.php`, `admin/order-create.php` | Order processing |
| **Customers** | `admin/customers.php`, `admin/customer-profile.php`, `admin/customer-cylinder-search.php` | Customer management |
| **Inventory** | `admin/inventory.php`, `admin/inventory-utils.php`, `admin/gas-types.php` | Inventory tracking |
| **Partners** | `admin/partners.php`, `admin/partner-transactions.php`, `admin/partner-invoice.php`, `admin/partner-ledger.php`, `admin/partner-reports.php` | Partner/vendor management |
| **Vendors** | `admin/vendors.php`, `admin/vendor-ledger.php`, `admin/vendor-payment.php`, `admin/vendor-profile.php`, `admin/vendor-activity.php` | Vendor payments, profile, activity log |
| **Payments** | `admin/dispatch-settlement.php`, `admin/deposit-receipt.php`, `admin/partner-transaction-create.php` | Payment processing |
| **Reports** | `admin/reports.php`, `admin/dispatch-settlement.php` | Reporting |
| **Users** | `admin/users-manager.php` | Admin user RBAC management |
| **Settings (General)** | `admin/settings.php` | DB status, sync, backup, role simulation |
| **Settings (AI Config)** | `admin/settings-ai.php` | AI provider, API keys, model, TTS |
| **Settings (Brand Mgmt)** | `admin/settings-brand.php` | Multi-brand CRUD, logos, invoice, SMTP |

### AI Assistant sub-architecture (`admin/ai/`)

| Directory | Files | Purpose |
|---|---|---|
| `agents/` | `general-agent.php`, `customer-agent.php`, `inventory-agent.php`, `sales-agent.php`, `analytics-agent.php` | Domain-specific AI agents |
| `actions/` | `action-registry.php`, `action-executor.php` | Executable actions registry |
| `planning/` | `intent-classifier.php`, `data-planner.php`, `conversation-manager.php`, `response-builder.php` | Intent detection & response planning |
| `memory/` | `memory-store.php`, `memory-retriever.php`, `memory-compressor.php` | Short/long-term conversation memory |
| `query/` | `query-builder.php`, `query-executor.php` | Dynamic SQL generation |
| `security/` | `action-validator.php`, `permission-gate.php`, `sql-validator.php` | Guard layer |
| `analytics/` | `data-aggregator.php`, `forecaster.php`, `trend-analyzer.php` | Business analytics |
| `schema/` | `schema-explorer.php` | Schema introspection |
| `entity/` | `entity-registry.php` | Entity detection |
| `detection/` | `entity-detector.php` | Entity extraction |
| `tts/` | `azure-tts.php` | Text-to-speech (Azure) |

## Critical quirks (will likely miss)

- **Redirects use JS** `echo "<script>window.location.href='...'</script>"; exit();` — NOT PHP `header()` — because `layout.php` already flushed HTML.
- **No output buffering** — `layout.php` streams immediately. No `ob_start()` wrapper.
- **`syncInventory()`** must be called after any stock change (rebuilds `inventory` from `cylinders`).
- **Migrations run idempotently** on every dashboard load (`runConsumerCylinderMigrations()`, etc.) — they `SELECT` first, catch exception if column missing, then `ALTER TABLE`.
- **Multi-brand**: `business_config` table stores one row per brand. `loadAllBusinessConfigs()` fetches all rows (cached 300s). `getDefaultBusiness()` returns the brand with `is_default=1`. `getBrandConfig($key)` returns full config (including SMTP) for a given key. `saveBrandConfig($pdo, $data)` is a MySQL `INSERT ... ON DUPLICATE KEY UPDATE` (upsert). `deleteBrand($pdo, $key)` refuses deletion if orders reference the brand.
- **Brand logos** stored as `Images/logos/{business_key}.{ext}` and `Images/logos/{business_key}_white.{ext}`. Uploaded per-brand in the edit form.
- **Per-brand SMTP** stored in `business_config` columns `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_encryption`, `email_from_name`, `email_from_address`. `mail-config.php::getMailer($business_key)` reads these for the given key and falls back to hardcoded defaults.
- **Business key fallback pattern**: always use `getBrandConfig()['business_key']` instead of hardcoded `'nutan_gases'` string. The `?:` operator (not `??`) is preferred so empty strings also fall through.
- **Gas sizes** are comma-separated strings in `gas_types.sizes`, NOT normalized rows. JS splits with `.split(',')`.
- **Money**: `DECIMAL(10,2)`, `floatval()` on input, `number_format()` on display.
- **Cache**: file-based JSON in `public_html/cache/` with 300s TTL.
- **Auth**: admin uses `require_role(['super_admin','billing_clerk',...])`; portal uses `require_customer_login()`.
- **No build tools, no package.json** — skip any test/lint/typecheck commands. (Playwright for browser tests, PHP test scripts in `tests/`)
- **`mail-config.php` is gitignored** (contains SMTP creds). Create it from scratch.
- **Playwright shallow tests** live in `tests/browser-tests.spec.js` with config at `playwright.config.js`. Run with `npx playwright test tests/browser-tests.spec.js`. 62 tests covering public pages, admin, portal, API, SEO.
- **Deep functional integration tests** defined in `docs/testing/DEEP_FUNCTIONAL_TEST_PLAN.md` v2.0 (expanded). Implementation lives in `tests/phase1-deep.spec.js`, `tests/phase2-deep.spec.js`, `tests/phase3-deep.spec.js` with helpers at `tests/helpers/deep-assert.js`. Run with `npx playwright test tests/phase1-deep.spec.js`. 200+ planned tests covering order modes, vendor flows, customer CRUD, GST compliance, suppliers, expenses, users, settings, bulk ops, notifications, audit, reports, email — all with full side-effect verification across 10+ tables per test.
- **Deep DB assertion endpoint** at `public_html/admin/e2e-deep-assert.php` (17 actions returning full state snapshots).
- **AI tests** live in `tests/run_ai_tests.php`. Run with `php tests/run_ai_tests.php`. 167 tests covering migrations, config, permissions, SQL validation, entity detection, memory, actions, schema, analytics, forecasting, trends.
- **Known bug fixed**: `portal/dashboard.php` used `g.size_capacity` but `gas_types` column is `sizes`. Fixed with `g.sizes AS size_capacity`.
- **Known AI bugs fixed**: `saveWorkflowMemory` missing 4th bound param; `LIMIT ?` params in 5 files changed to inline `(int)$limit` (MariaDB doesn't support bound LIMIT params).
- **Portal login** uses file-based rate limiting at `cache/login_rate_<ip_md5>`. Playwright tests order successful logins before wrong-password test to avoid blocking.
- **Portal session** uses 30-min inactivity timeout, "Remember Me" cookie with `password_verify()`.
- **Admin nav** defined in `layout.php` via an associative array keyed by `$active_menu`.
- **Admin RBAC** roles: `super_admin`, `warehouse_supervisor`, `billing_clerk`, `delivery_driver`, `viewer` (defined in `users-manager.php`).
- **Vendor Activity Log** (`vendor_activity_log` table): Rich audit trail replacing the old "Ledger Activity" tab on vendor profile. Logs dispatch, receive, payments, advance settlements, borrow/lend with full context (cylinder counts, payment methods, lot numbers, gas breakdown). Logged via `logVendorActivity($pdo, $vendor_id, $type, $title, $desc, $details_arr, $extra_arr)`. Fetched via `getVendorActivityLog($pdo, $vendor_id, $limit, $offset, $filter)`. Migration: `runVendorActivityLogMigration($pdo)`. Activity types: `dispatch`, `receive`, `payment_made`, `advance_settled`, `advance_paid`, `borrow`, `lend`, `return`, `adjustment`, `invoice_created`, `invoice_paid`. Instrumented in: `send-cylinder.php`, `receive-cylinder.php`, `vendor-profile.php`, `vendor-ledger.php`, `vendor-payment.php`, `partner-transaction-create.php`, `vendors.php`. Full-page view at `vendor-activity.php` with pagination and type filters. The old financial `vendor_partner_ledger` remains intact for accounting.

## Database

Two SQL files, run both against the same DB:
1. `public_html/db_setup.sql` — blog `posts` table
2. `public_html/admin/db_expand.sql` — 16 business tables (`gas_types`, `customers`, `cylinders`, `refill_orders`, `payments`, etc.)

DB name: `u189092125_Blog` (production), `u189092125_test` (in db.php — verify which is active).

## Documentation inventory

| File | Size | Purpose |
|---|---|---|
| `docs/software-requirements-specification.md` | 37 KB | Full SRS (585 lines) |
| `docs/software-architecture.md` | 13 KB | Architecture documentation |
| `docs/business-requirements-document.md` | 7 KB | BRD |
| `docs/testing/SUMMARY.md` | 4 KB | Test summary (all passes) |
| `docs/testing/BUGS.md` | 3 KB | Bug tracking (all fixed) |
| `docs/testing/DEEP_FUNCTIONAL_TEST_PLAN.md` | ~620 lines | Deep functional integration test plan v2.0 — side-effect verification matrices for every business + platform admin operation: orders, cylinders, inventory, customers, payments, GST, suppliers, expenses, users, settings, bulk ops, notifications, audit, reports, email, portal; organized by P0/P1/P2/P3/P4 priority |
| `docs/testing/COMPREHENSIVE_TEST_PLAN.md` | ~1014 lines | Full E2E browser test plan v2.0 — all phases P0-P4 covering core flows, transactions, portal, platform admin, AI, public site, cross-cutting (133 tests across 7 phases) |
| `admin/AI_SYSTEM_README.md` | 30 KB | **Primary technical reference** — schema, patterns, flows, postmortems |
| `admin/software_overview.md` | 9 KB | Admin software overview |
| `admin/extended_overview.md` | 13 KB | Extended admin documentation |
| `admin/UX-EXECUTION-PLAN.md` | 9 KB | AI Assistant UX plan |
| `public_html/COMPRESSION_GUIDE.md` | 3 KB | Image/video compression guide |
| `public_html/SEO_AUDIT_REPORT.md` | 9 KB | SEO audit |
| `public_html/SEO_IMPROVEMENTS_COMPLETED.md` | 5 KB | SEO changelog |
| `AGENTS.md` | this | AI coding agent instructions (this file) |
| `UX-EXECUTION-PLAN.md` | 15 KB | Rent Cylinder Manager UX plan |

**For deep technical reference, always consult `admin/AI_SYSTEM_README.md` first** — it contains the full schema, all business flows, migration patterns, and postmortems.

## Executive Dashboard Architecture (rewritten Jul 2026)

`admin/dashboard.php` is a **full-page shell** — AJAX + JS architecture with 11 sections:

| Component | Purpose |
|---|---|
| `dashboard.php` | PHP shell: layout, inline CSS (400+ lines), HTML skeleton for 11 sections, Chart.js CDN + `dashboard.js` |
| `dashboard-ajax.php` | **Data backend**: runs 15+ optimized queries across all business tables, caches JSON for 120s, returns 11 data sections. Auth: session check only. |
| `dashboard.js` | **JS rendering engine** (664 lines): fetches AJAX data, renders 11 sections, 5 Chart.js charts, auto-polls every 30s |
| `cache/dashboard_ajax.json` | 120s file cache for AJAX endpoint |

### How it works
1. **Page load**: `dashboard.php` renders empty HTML shells for all sections (no DB queries)
2. **AJAX fetch**: `dashboard.js` calls `dashboard-ajax.php` → returns all data as JSON
3. **JS renders**: All 11 sections are populated from JSON data
4. **Auto-refresh**: Polls every 30s, manual refresh button available
5. **Cache**: AJAX response cached 120s to reduce DB load

### 11 Dashboard Sections
| # | Section | Data Source |
|---|---|---|
| 1 | **Business Health KPIs** | 11 KPI cards: Revenue (today/month/year), Gross/Net Profit, Margin %, Receivables, Payables, Cash, Bank, Outstanding |
| 2 | **Cylinder Operations** | 14 stats (total/filled/empty/with_customer/in_transit/etc.) + stacked bar chart by gas type |
| 3 | **Revenue Trend** | 30-day line chart with order count overlay; product & customer rankings |
| 4 | **Financial Overview** | Income/Expense/Profit stats, GST Net Payable, expense donut chart, cash flow bar chart |
| 5 | **Orders & Operations** | Today/Pending/Completed counts, Refills/Exchanges/Rentals/Deliveries/Returns today |
| 6 | **Customer Insights** | Total/Active/New/Outstanding/Inactive stats, frequent customer list |
| 7 | **Vendor & Gas Plant** | Total/Active/Pending Lots/Outstanding Amount, recent purchases list |
| 8 | **Warehouse/Inventory** | Stock levels with fill bars per gas type, incoming/outgoing today, low stock alerts |
| 9 | **Alerts** | Color-coded alert chips: overdue rentals/returns, low stock, expiring/expired cylinders, unpaid orders |
| 10 | **Activity Timeline** | Unified feed of orders, payments, cylinder transactions, new customers, expenses (last 20) |
| 11 | **Quick Actions** | 10 action buttons: New Customer, Create Order, Refill, Rent, Exchange, Receive Payment, Add Expense, Send/Receive from Vendor, Reports |

### Charts (5)
1. **Revenue Area Chart** — 30-day line with gradient + order count overlay (dual Y-axis)
2. **Cylinder Type Stacked Bar** — Horizontal by gas type (filled/empty/with_customer/maintenance/in_transit)
3. **Product Donut** — Top 10 products by revenue
4. **Expense Category Donut** — Top 8 categories with legend
5. **Cash Flow Waterfall** — 12-month inflow (green) vs outflow (red) bars

### CSS
- All dashboard CSS is **inline** in `dashboard.php` (no external file dependency)
- Uses `dash-*` prefixed classes isolated from `admin-style.css`
- No old dashboard CSS remains in `admin-style.css`

### Works on all hosting (including shared)
- Pure PHP + JS, no Node.js required
- Standard AJAX polling over HTTP/HTTPS, no WebSocket needed
- 30s auto-refresh, 120s server-side cache
- Responsive: desktop (12-col grid), tablet (stack), mobile (2-col KPI cards)
