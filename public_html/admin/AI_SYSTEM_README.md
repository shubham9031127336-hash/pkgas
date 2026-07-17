# Prem Gas Solution — Full System Reference for AI

## 1. Tech Stack & Environment
| Layer | Detail |
|---|---|
| **PHP** | 8.x, no framework, flat-file structure. All pages under `public_html/` |
| **MySQL** | via PDO. `admin/db.php` sets `ERRMODE_EXCEPTION`, `FETCH_ASSOC` |
| **CSS** | `admin/admin-style.css` — custom properties (`--admin-accent`, `--admin-fg`, etc.), no framework |
| **JS** | Vanilla JS, no jQuery/framework. Inline `<script>` in each page |
| **Auth** | Session-based (`admin/auth.php`). Uses `user_id`, `user_role`, `user_name` in `$_SESSION` |
| **DB** | `u189092125_Blog` @ `localhost`, user `u189092125_admin` |

---

## 2. File Architecture & Patterns

### Admin Page Pattern (every page follows this exact structure)
```
<?php
$page_title = "...";
$active_menu = "...";            // matches nav highlighting in layout.php
require_once 'layout.php';        // outputs DOCTYPE..<main> (incl. sidebar + top bar)
require_role(['super_admin', 'billing_clerk']);  // or require_login()
require_once 'db.php';

// ... business logic (POST handlers at top, GET rendering below) ...

?>
<!-- HTML content goes here (inside <main class="content-container">) -->

<?php
require_once 'layout_footer.php'; // closes </main>, JS, </body></html>
?>
```

### Coding Conventions
- **No classes/OOP** — all procedural functions + inline code
- **`require_once` for utils** — `db.php`, `auth.php`, `inventory-utils.php`, `business_helper.php`
- **`require_role(['role1','role2'])`** — gates access at top; redirects to dashboard if unauthorized
- **POST handlers at top** of file (before any HTML), GET rendering below
- **Queries**: prepared statements with `$pdo->prepare()` + `$stmt->execute([$params])`
- **Errors**: `try {} catch (PDOException $e) { die("...") }` for critical; empty catch for non-critical
- **Redirects**: `echo "<script>window.location.href='...'</script>"; exit();` (JS redirect, not `header()`)
- **HTML**: inline styles everywhere (`style="..."`), no separate CSS classes for page-specific elements
- **Print styles**: `@media print` blocks inline in each page's `<style>` tag
- **Output escaping**: `htmlspecialchars()` on all user/DB output; `number_format()` on currency

### Common Pitfalls for AI
1. **Redirects use JS `window.location.href`** not PHP `header()` — because `layout.php` already sent HTML
2. **`exit()` always follows redirect JS** — prevents further output
3. **No `ob_start()` / output buffering** — HTML starts streaming immediately from `layout.php`
4. **Migrations are idempotent** — try a `SELECT` first, catch exception if column missing, then `ALTER TABLE`
5. **`syncInventory()` is called after any stock change** — completely rebuilds the `inventory` table from `cylinders`
6. **Money stored as `DECIMAL(10,2)`** — `floatval()` on input, `number_format()` on display
7. **Gas sizes are comma-separated strings** in `gas_types.sizes`, NOT normalized
8. **DB connection uses `ERRMODE_EXCEPTION`** — unbounded PDO errors throw exceptions

---

## 3. Complete Database Schema (16 tables)

### 3.1 `gas_types` — Gas catalogue
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
name                VARCHAR(100) NOT NULL UNIQUE          -- "Oxygen", "Nitrogen"
chemical_formula    VARCHAR(50)                           -- "O2", "N2"
description         TEXT
default_price_per_kg DECIMAL(10,2) DEFAULT 0.00
sizes               VARCHAR(255) DEFAULT '10L,40L,47L'   -- COMMA-SEPARATED: "10L,40L,47L"
size_prices         TEXT                                  -- JSON: {"10L":150,"40L":350}
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```
**Size handling**: `sizes` is a comma-separated string. JS splits it with `.split(',')`. When creating orders, the dropdown reads `gas_types.sizes`, not normalized rows. Prices per size are in `size_prices` JSON column.

### 3.2 `customers` — Consumers
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
name                VARCHAR(150) NOT NULL
mobile              VARCHAR(20) NOT NULL
address             TEXT
gst_number          VARCHAR(15)
customer_type       ENUM('refill','rental') NOT NULL DEFAULT 'refill'
deposit_balance     DECIMAL(10,2) NOT NULL DEFAULT 0.00  -- running security deposit balance
active_cylinders_count INT NOT NULL DEFAULT 0
status              ENUM('active','inactive') DEFAULT 'active'
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
INDEX (mobile), INDEX (customer_type)
```

### 3.3 `vendors` — Gas filling suppliers
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
name                VARCHAR(150) NOT NULL
mobile              VARCHAR(20) NOT NULL
address             TEXT
gst_number          VARCHAR(15)
active_refill_count INT NOT NULL DEFAULT 0
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### 3.4 `cylinders` — Physical cylinders (core table)
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
serial_number       VARCHAR(100) NOT NULL UNIQUE          -- "OX-47L-201"
barcode             VARCHAR(100) UNIQUE
gas_type_id         INT NOT NULL           -> gas_types.id
size_capacity       VARCHAR(50) NOT NULL                  -- "47L", "10L"
status              ENUM('filled','empty','in_use','with_customer',
                     'sent_to_vendor','under_maintenance',
                     'borrowed_from_partner','lent_to_partner',
                     'returned_to_partner','with_partner',
                     'returned_to_consumer') NOT NULL DEFAULT 'empty'
last_refill_date    DATE
last_inspection_date DATE
expiry_date         DATE
purchase_date       DATE
current_customer_id INT -> customers.id
current_vendor_id   INT -> vendors.id
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-- Partner exchange columns (added by migration):
current_partner_id  INT -> partners.id
ownership_type      ENUM('owned','partner_owned','consumer_owned') DEFAULT 'owned'
original_owner_customer_id INT -> customers.id  -- for consumer_owned cylinders
borrow_date         DATE
daily_rent_rate     DECIMAL(10,2) DEFAULT 0.00
free_days           INT DEFAULT 0
INDEX (status), INDEX (barcode)
```
**Status lifecycle**: `filled` → `with_customer` (on issue) → `empty` (on return) → `sent_to_vendor` (for refill) → `filled` (on receive from vendor). Partner statuses are separate tracks.

**Ownership types**:
- `owned` — Company cylinder (default)
- `partner_owned` — Borrowed from partner (BR tag)
- `consumer_owned` — Belongs to a specific customer (CON tag). `original_owner_customer_id` identifies which customer.

**Settlement rule**: A consumer_owned cylinder is considered **settled** (removed from active tracking) when `current_customer_id = original_owner_customer_id` (owner and holder match). At that point `status='empty'` and `current_customer_id=NULL`.

### 3.5 `inventory` — Aggregated stock (auto-rebuilt by syncInventory)
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
gas_type_id         INT NOT NULL           -> gas_types.id
size_capacity       VARCHAR(50) NOT NULL
total_stock         INT NOT NULL DEFAULT 0
filled_stock        INT NOT NULL DEFAULT 0
empty_stock         INT NOT NULL DEFAULT 0
with_customer_stock INT NOT NULL DEFAULT 0
sent_to_vendor_stock INT NOT NULL DEFAULT 0
maintenance_stock   INT NOT NULL DEFAULT 0
min_alert_threshold INT NOT NULL DEFAULT 2
-- Partner columns (added by migration):
borrowed_from_partner_stock INT DEFAULT 0
lent_to_partner_stock       INT DEFAULT 0
with_partner_stock          INT DEFAULT 0
UNIQUE KEY gas_size (gas_type_id, size_capacity)
```
**This table is entirely rebuilt by `syncInventory($pdo)`** — it truncates the table, then counts cylinders by `(gas_type_id, size_capacity, status)`. Never manually edited.

### 3.6 `refill_orders` — Sale/exchange orders
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
customer_id         INT NOT NULL            -> customers.id
order_date          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
subtotal            DECIMAL(10,2) NOT NULL DEFAULT 0.00   -- sum of refill charges
deposit_amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00   -- total security deposit for this order
tax_amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00   -- 18% GST on subtotal only
discount            DECIMAL(10,2) NOT NULL DEFAULT 0.00
grand_total         DECIMAL(10,2) NOT NULL DEFAULT 0.00   -- subtotal + deposit + tax - discount
payment_status      ENUM('paid','pending','partial') NOT NULL DEFAULT 'pending'
payment_method      VARCHAR(50)
notes               TEXT
business_name       VARCHAR(50) DEFAULT 'nutan_gases'     -- 'nutan_gases' | 'vd_enterprises'
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```
**Tax calculation**: `tax_amount = subtotal * 0.18` (GST is 18% hardcoded, only if `apply_gst` checkbox is on). Deposit is NOT taxed. `grand_total = subtotal + tax_amount - discount` (deposit_amount is stored but not added to grand_total in latest code).

### 3.7 `refill_order_items` — Line items per order
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
refill_order_id     INT NOT NULL            -> refill_orders.id
gas_type_id         INT NOT NULL            -> gas_types.id
cylinder_id         INT                     -> cylinders.id (the issued filled cylinder)
size_capacity       VARCHAR(50) NOT NULL
qty                 INT NOT NULL DEFAULT 1
price_per_unit      DECIMAL(10,2) NOT NULL DEFAULT 0.00
is_rental           TINYINT(1) NOT NULL DEFAULT 0
deposit_amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00
-- Columns added by migration:
returned_cylinder_id INT -> cylinders.id (the empty cylinder returned by customer)
rent_per_day        DECIMAL(10,2) NOT NULL DEFAULT 0.00
free_days           INT NOT NULL DEFAULT 0
```
**Exchange tracking**: When a customer exchanges a cylinder, `cylinder_id` = the filled cylinder they receive, `returned_cylinder_id` = the empty cylinder they give back.

### 3.8 `invoices` — Auto-generated per order
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
invoice_number      VARCHAR(100) NOT NULL UNIQUE  -- "INV-2026-NNNN" (N=order_id)
refill_order_id     INT NOT NULL            -> refill_orders.id
invoice_date        DATE NOT NULL
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 3.9 `payments` — Customer payments ledger
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
customer_id         INT                     -> customers.id
vendor_id           INT                     -> vendors.id
refill_order_id     INT                     -> refill_orders.id
amount              DECIMAL(10,2) NOT NULL
payment_date        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
payment_method      VARCHAR(50) NOT NULL
payment_type        ENUM('refill_payment','deposit_added','deposit_refunded',
                     'vendor_payment','rent_payment') NOT NULL
notes               TEXT
```
**payment_type meaning**: `refill_payment` = gas refill charges paid; `deposit_added` = security deposit paid by customer; `deposit_refunded` = deposit returned to customer; `vendor_payment` = payment to gas filling vendor; `rent_payment` = cylinder rental fee.

### 3.10 `cylinder_transactions` — Lifecycle audit log
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
cylinder_id         INT NOT NULL            -> cylinders.id
customer_id         INT                     -> customers.id
vendor_id           INT                     -> vendors.id
transaction_type    ENUM('refill','issue_to_customer','return_from_customer',
                     'send_to_vendor','receive_from_vendor','maintenance',
                     'partner_borrow','partner_return','partner_lend',
                     'partner_receive_back',
                     'consumer_return','consumer_give_back','consumer_dispatch') NOT NULL
transaction_date    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
notes               TEXT
```
**Transaction types**:
- `issue_to_customer` — Cylinder given to customer (filled or rental)
- `return_from_customer` — Customer returns company/partner cylinder
- `consumer_return` — Consumer-owned cylinder registered or transferred to a customer
- `consumer_give_back` — Consumer receives their OWN cylinder back (exchange SETTLED)
- `consumer_dispatch` — Consumer cylinder dispatched
- `partner_borrow` / `partner_return` — Partner borrow/return flow
- `send_to_vendor` / `receive_from_vendor` — Vendor refill flow

### 3.11 `deposit_receipts` — Security deposit receipts
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
receipt_number      VARCHAR(100) NOT NULL UNIQUE  -- "DEP-YYYY-NNNN"
payment_id          INT NOT NULL            -> payments.id
customer_id         INT NOT NULL            -> customers.id
receipt_date        DATE NOT NULL
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
INDEX (receipt_number), INDEX (customer_id)
```

### 3.12 `partners` — Partner companies (exchange)
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
company_name        VARCHAR(200) NOT NULL
contact_person      VARCHAR(150)
mobile              VARCHAR(20)
email               VARCHAR(150)
address             TEXT
gst_number          VARCHAR(20)
notes               TEXT
status              ENUM('active','inactive') NOT NULL DEFAULT 'active'
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
INDEX (company_name), INDEX (status)
```

### 3.13 `partner_transactions` — Partner exchange header
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
partner_id          INT NOT NULL            -> partners.id
transaction_type    ENUM('borrowed_from_partner','returned_to_partner',
                     'lent_to_partner','received_back_from_partner') NOT NULL
transaction_date    DATE NOT NULL
notes               TEXT
created_by          VARCHAR(100)
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
INDEX (transaction_type), INDEX (transaction_date)
```
**Type meanings**: `borrowed_from_partner` = we take their cylinders (we owe); `returned_to_partner` = we give back; `lent_to_partner` = they take our cylinders (they owe); `received_back_from_partner` = they give back.

### 3.14 `partner_transaction_items` — Per-cylinder partner exchange rows
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
transaction_id      INT NOT NULL            -> partner_transactions.id
cylinder_id         INT                     -> cylinders.id
serial_number       VARCHAR(100) NOT NULL
gas_type_id         INT NOT NULL            -> gas_types.id
size_capacity       VARCHAR(50) NOT NULL
status_before       VARCHAR(50)
status_after        VARCHAR(50) NOT NULL
-- Financial columns added by migration:
daily_rent_rate     DECIMAL(10,2) DEFAULT 0.00
days_held           INT DEFAULT 0
rent_accrued        DECIMAL(10,2) DEFAULT 0.00
rent_paid           DECIMAL(10,2) DEFAULT 0.00
damage_amount       DECIMAL(10,2) DEFAULT 0.00
payment_status      ENUM('pending','cleared') DEFAULT 'cleared'
```

### 3.15 `users` — Staff accounts
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
username            VARCHAR(50) NOT NULL UNIQUE
password_hash       VARCHAR(255) NOT NULL    -- bcrypt via password_hash()
name                VARCHAR(100) NOT NULL
role                ENUM('super_admin','billing_clerk','warehouse_supervisor') NOT NULL
status              ENUM('active','inactive') NOT NULL DEFAULT 'active'
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```
**Default accounts** (created by `login.php` auto-migration):
- `admin` / `admin123` → super_admin
- `clerk` / `clerk123` → billing_clerk
- `warehouse` / `warehouse123` → warehouse_supervisor

### 3.16 `activity_logs` — Login & action audit
```sql
id                  INT AUTO_INCREMENT PRIMARY KEY
username            VARCHAR(100) NOT NULL
action              VARCHAR(255) NOT NULL
details             TEXT
created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

---

## 4. Key Code Patterns

### 4.1 Safe Migrations (idempotent ALTER TABLE)
```php
// Try SELECT first, catch if column missing
try {
    $pdo->query("SELECT business_name FROM refill_orders LIMIT 0");
} catch (PDOException $e) {
    $migrations[] = "ALTER TABLE refill_orders ADD COLUMN business_name VARCHAR(50) DEFAULT 'nutan_gases'";
}
// Then execute all gathered migrations
foreach ($migrations as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $e) { /* skip */ }
}
```

### 4.2 Transaction Handling
```php
$pdo->beginTransaction();
try {
    // ... multiple inserts/updates ...
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    $error = "Transaction failed: " . $e->getMessage();
}
```

### 4.3 Business Entity Selection
```php
require_once 'business_helper.php';
$business = getBusiness($order['business_name'] ?? 'nutan_gases');
// Then use: $business['name'], $business['gstin'], $business['address'], $business['phone']
```

### 4.4 Data Passing: URL Query String
Most pages use `$_GET` for IDs: `invoice.php?order_id=X`, `customer-profile.php?id=X`, `deposit-receipt.php?receipt_id=X&business=nutan_gases`

### 4.5 Inventory Sync Pattern
```php
function syncInventory($pdo) {
    runPartnerMigrations($pdo);
    runRefillRentalMigrations($pdo);
    runConsumerCylinderMigrations($pdo);
    $pdo->exec("TRUNCATE TABLE inventory");
    // Re-insert by counting cylinders GROUP BY gas_type_id, size_capacity, status
    // ...
}
```

### 4.6 Cylinder Exchange Settlement Pattern
```php
// When processing a returned cylinder serial:
$chk = $pdo->prepare("SELECT id, ownership_type, current_customer_id, original_owner_customer_id FROM cylinders WHERE serial_number = ?");
$chk->execute([$serial]);
$cyl = $chk->fetch();

if ($cyl['ownership_type'] === 'consumer_owned' && $cyl['original_owner_customer_id'] == $customer_id) {
    // SETTLED: customer gets their own cylinder back
    $pdo->prepare("UPDATE cylinders SET status = 'empty', current_customer_id = NULL WHERE id = ?")->execute([$cyl['id']]);
    logCylinderTransaction($pdo, $cyl['id'], $customer_id, null, 'consumer_give_back', "Exchange SETTLED");
} elseif ($cyl['ownership_type'] === 'consumer_owned') {
    // Transfer to this customer (different owner)
    $pdo->prepare("UPDATE cylinders SET status = 'with_customer', current_customer_id = ? WHERE id = ?")->execute([$customer_id, $cyl['id']]);
    logCylinderTransaction($pdo, $cyl['id'], $customer_id, null, 'consumer_return', "Transferred");
} else {
    // Company/partner cylinder returned
    $pdo->prepare("UPDATE cylinders SET status = 'empty', current_customer_id = NULL WHERE id = ?")->execute([$cyl['id']]);
    logCylinderTransaction($pdo, $cyl['id'], $customer_id, null, 'return_from_customer', "Returned");
}
```

### 4.7 Active Exchange Filter Pattern
To exclude settled cylinders from any query:
```sql
AND NOT (c.ownership_type = 'consumer_owned' AND c.current_customer_id IS NOT NULL AND c.current_customer_id = c.original_owner_customer_id)
```

---

## 5. Detailed Business Flows

### 5.1 Create Refill Order (`order-create.php`)
1. Form collects: customer, business entity, gas type+size, price, rental flag, issued cylinder serial (auto-allocated or manual), returned cylinder serial, payment method, discount, GST toggle
2. POST handler:
   - Validates customer exists, items not empty
   - For each item row: allocates a filled cylinder from stock (auto-selects first `filled` cylinder of matching gas+size, or lets user pick from available)
   - Handles returned cylinder exchange: marks returned cylinder as `empty`, clears its `current_customer_id`
   - Calculates `subtotal = sum(price_per_unit)` , `tax = subtotal * 0.18` (if GST on), `grand_total = subtotal + tax - discount`
   - Inserts `refill_orders` row
   - Inserts `refill_order_items` rows (one per cylinder)
   - Updates cylinder statuses: issued → `with_customer`, returned → `empty`
   - Inserts `payments` row: `refill_payment` for the gas charges + `deposit_added` if deposit > 0
   - Generates invoice number `INV-YYYY-{order_id}` and inserts into `invoices`
   - Updates customer `deposit_balance` if deposit was charged
   - Calls `syncInventory($pdo)`
   - Redirects to `invoice.php?order_id=X`

### 5.2 Manual Ledger Payment (`customer-profile.php`)
1. Modal form: amount, action type (`refill_payment` | `deposit_added` | `deposit_refunded`), payment method, business entity, notes
2. POST handler:
   - Inserts `payments` row
   - For deposit types: updates customer's `deposit_balance` (+ for added, - for refunded)
   - For deposit types: generates `deposit_receipts` with `DEP-YYYY-NNNN`, redirects to `deposit-receipt.php`
   - For refill_payment: just shows success message on same page

### 5.3 Partner Borrow (`partner-transaction-create.php`)
1. Form: partner, date, gas type (triggers size dropdown via `updateSizeOptions()`), size, qty, rent rate, serial numbers (textarea, one per line)
2. POST handler:
   - Validates serial count matches qty
   - Creates `partner_transactions` with type `borrowed_from_partner`
   - For each serial: upserts cylinder (if new serial, creates new cylinder record; if existing, updates it)
   - Sets cylinder: `status='borrowed_from_partner'`, `ownership_type='partner_owned'`, `current_partner_id`, `borrow_date`, `daily_rent_rate`
   - Creates `partner_transaction_items` rows
   - Logs `cylinder_transactions` entry
   - Calls `syncInventory($pdo)`

### 5.4 Partner Return (same file, `form_mode=return`)
1. AJAX endpoint (`?ajax_partner_borrowed=ID`) returns borrowed cylinders JSON with days_held + rent_accrued
2. Form: select partner → auto-loads borrowed cylinders → check ones to return → enter rent paid + damage
3. POST handler: creates return transaction, calculates rent, updates cylinder status to `returned_to_partner`, clears partner fields, decrements customer active count if cylinder was also with a customer

### 5.5 Standalone Cylinder Exchange Settlement (`cylinder-exchange.php`)
1. Staff selects customer via search combobox
2. AJAX call to `exchange-ajax.php?customer_id=X` returns:
   - `our_with_them`: Company/partner cylinders with customer (status=with_customer)
   - `their_with_us`: Customer's consumer_owned cylinders in our inventory (not settled)
   - Counts and net balance
3. Left panel: Enter serials customer is returning (quick-pick from held cylinders)
4. Right panel: Checkboxes for customer-owned cylinders to give back
5. POST handler (`action=settle_exchange`):
   - Each return serial: detects ownership → applies correct settlement logic
   - Each give-back serial: if consumer_owned + original_owner matches → SETTLED (removed from active tracking)
   - Full audit logging in `cylinder_transactions`
   - Calls `syncCustomerActiveCylinderCounts()` and `syncInventory()`

---

## 6. Business Entity Support
- Config file: `admin/business_helper.php`
- Current businesses: `nutan_gases`, `vd_enterprises`
- Stored in `refill_orders.business_name` column (default `nutan_gases`)
- `invoice.php` reads `$order['business_name']` and renders matching header
- `deposit-receipt.php` accepts `?business=key` GET param (defaults to `nutan_gases`)
- `order-create.php` and `customer-profile.php` have business selector dropdowns

---

## 7. Print System
- **`invoice.php`**: 3 copies (Shop/Consumer/Police) stacked vertically with "✁ Tear Here" dividers, `@page { margin: 8mm }`
- **`deposit-receipt.php`**: 1 centered consumer copy
- **`admin-style.css`**: global `@media print` hides `.sidebar`, `.top-bar`, `.btn-primary`

---

## 8. Recent Fixes / Postmortem

### 8.1 `lang_init.php` / include path failure
- Problem: the public homepage and some admin pages were using relative includes like `require_once 'lang_init.php';` and `require_once 'db.php';` which failed when PHP resolved paths differently on the production server.
- Impact: website returned HTTP 500 on `index.php` and admin pages such as `admin/dashboard.php` and `admin/login.php`.
- Fix:
  - Replaced public site bootstrapping with `require_once __DIR__ . '/translations.php';` instead of `lang_init.php`.
  - Ensured all admin pages that call `__('...')` load `lang_init.php` before the first translation lookup.
  - Converted relative includes in admin pages to absolute `__DIR__` paths for `auth.php`, `db.php`, `layout.php`, `lang_init.php`, `inventory-utils.php`, and other shared includes.
- Why it matters: this prevents path-resolution failures between the current working directory and the script directory, especially under Apache/PHP setups where includes do not always resolve from the requested file's folder.

### 8.2 Lesson learned
- Always bootstrap translation/error handling before any translated string is used.
- Prefer `__DIR__` for local file includes in PHP apps with mixed root and subfolder scripts.
- Keep admin and public startup logic explicit and deterministic to avoid silent HTTP 500 failures.

### 8.3 Cylinder Exchange Settlement — Major Feature Addition
- Problem: The system only handled cylinder exchanges during refill orders. Real business requires standalone settlement (customer returns our cylinders, we return theirs, no refill needed).
- Impact: Customers visiting only to settle pending exchanges had no dedicated workflow. Settled cylinders remained in active tracking. Deposit operations caused blank screens.
- Fix:
  - Created `cylinder-exchange.php` — standalone exchange settlement page with dual-panel UI.
  - Created `exchange-ajax.php` — AJAX endpoint for loading customer exchange balance data.
  - Added `settleCylinderExchange()` and `isCylinderInActiveExchange()` helpers to `inventory-utils.php`.
  - Fixed `order-create.php` return logic to detect ownership and auto-settle.
  - Fixed `cylinders.php` query to exclude settled cylinders from active list.
  - Fixed `customer-profile.php` queries to separate active vs settled consumer cylinders.
  - Fixed `customer-profile.php` deposit handler to redirect to `deposit-receipt.php` instead of blank screen.
  - Fixed `customers.php` delete modal (missing `id="delete_name_label"` element).
  - Created `cylinder-audit-log.php` — global searchable audit log for all cylinder movements.
  - Added audit log link to `settings.php`.
  - Updated `partner-transaction-create.php` to decrement customer count on partner return.
  - Enhanced `track-cylinder.php` with settlement flag in history.
  - Added "Cylinder Exchange" sidebar link in `layout.php`.
- Why it matters: This completes the cylinder exchange lifecycle — from issue through settlement — with full audit trail and proper active/settled separation.

### 8.4 Key New Functions
- **`settleCylinderExchange($pdo, $cylinder_id, $notes)`** — Auto-settles consumer-owned cylinder if held by original owner.
- **`isCylinderInActiveExchange($cyl)`** — Returns true only if cylinder is in genuine mismatch (owner ≠ holder).
- Both are in `inventory-utils.php` and can be called from any flow that moves cylinders.

- Each page has its own `@media print` overrides in `<style>` tags
- Print buttons use `window.print()`

---

## 8. Key File Dependencies
```
auth.php  ──> login.php (redirect if not logged in)
layout.php  ──> auth.php (require_login at top)
  │
  ├── dashboard.php
  ├── customers.php ──> customer-profile.php ──> deposit-receipt.php
  ├── order-create.php ──> invoice.php
  │     │                    └── business_helper.php
  │     └── inventory-utils.php
  ├── cylinder-exchange.php ──> exchange-ajax.php (AJAX data)
  │     └── inventory-utils.php (settleCylinderExchange, isCylinderInActiveExchange)
  ├── cylinder-audit-log.php ──> inventory-utils.php
  ├── partners.php ──> partner-transaction-create.php
  │                      └── inventory-utils.php (runPartnerMigrations)
  ├── refill-orders.php ──> invoice.php
  ├── cylinders.php ──> track-cylinder.php (AJAX)
  └── gas-types.php, inventory.php, vendors.php, settings.php, etc.
```

---

## 9. Quick Reference for Common Tasks

| Task | File(s) | Key SQL/Code |
|---|---|---|
| Add business | `business_helper.php` | Add entry to `getBusinesses()` array |
| Add DB column | `inventory-utils.php` | Add try-catch + ALTER TABLE to appropriate migration function |
| Change tax rate | `order-create.php` | Line `$tax_amount = $subtotal * 0.18;` |
| Add receipt copy | `invoice.php` | Duplicate a `render_receipt_slip()` call + tear line |
| Add payment type | `inventory-utils.php` + `db_expand.sql` | Modify ENUM in migration + SQL file |
| New admin page | Create `.php` file | Follow admin page pattern (Section 2) |
| Change company info | `business_helper.php` | Edit values in the business array |
| Settle cylinder exchange | `cylinder-exchange.php` or `order-create.php` | Use `settleCylinderExchange()` or inline ownership detection |
| Filter settled cylinders | Any query on `cylinders` table | Add `AND NOT (ownership_type='consumer_owned' AND current_customer_id = original_owner_customer_id)` |
| Search cylinder history | `cylinder-audit-log.php` or `track-cylinder.php` | Query `cylinder_transactions` joined with `cylinders` |
| Record deposit payment | `customer-profile.php` | Inserts `payments` + `deposit_receipts`, redirects to `deposit-receipt.php` |
