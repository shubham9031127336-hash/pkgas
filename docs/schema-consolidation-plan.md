# Schema Consolidation Plan — Nutan Gases

**Goal:** Reduce 58 database tables → 51 by eliminating redundancy while preserving all data and functionality.

**Status:** ✅ Complete (59→51 tables consolidated)

---

## Phases

### Phase 1 — Zero-risk removals ✅

| # | Task | Status | Files changed | Data loss? |
|---|------|--------|---------------|------------|
| 1.1 | Drop `cylinder_exchanges` (orphan table) | ✅ Done | 2 | No |
| 1.2 | Merge `invoices` into `refill_orders` | ✅ Done | 17 | No |
| 1.3 | Drop `gas_types.sizes` CSV column | ✅ Done | 12 | No |

### Phase 2 — Structural refactors

| # | Task | Status | Files changed | Data loss? |
|---|------|--------|---------------|------------|
| 2.1 | Remove `customer_cylinders`, repoint FK to `cylinders` | ✅ Done | 5 | No |
| 2.2 | Convert `deleted_cylinders` to soft-delete on `cylinders` | ✅ Done | 8 | No |

### Phase 3 — GST consolidation

| # | Task | Status | Files changed | Data loss? |
|---|------|--------|---------------|------------|
| 3.1 | Merge `gst_filing_lock` into `gst_settlements` | ✅ Done | 4 | No |
| 3.2 | Absorb `gst_filing_config` into `business_config` | ✅ Done | 7 | No |
| 3.3 | Replace `gst_validation_errors` with JSON | ✅ Done | 4 | No |
| 3.4 | Rename `gst_amendment_log` → `audit_log` | ✅ Done | 2 | No |

### Bonus — AI bug fixes

| # | Task | Status | Files changed |
|---|------|--------|---------------|
| B.1 | Add UNIQUE KEY on `ai_user_memory(user_id, memory_key)` | ✅ Done | 1 |
| B.2 | Add UNIQUE KEY on `ai_session_context(session_id)` | ✅ Done | 1 |

---

## Detail: Phase 1.1 — Drop `cylinder_exchanges`

**Why:** Orphan table — no PHP code writes to it. Only AI entity-registry reads from it (returns empty).

**Steps:**
1. `rm -rf cylinder_exchanges` from entity-registry queries
2. `DROP TABLE IF EXISTS cylinder_exchanges`

---

## Detail: Phase 1.2 — Merge `invoices` into `refill_orders`

**Why:** 1:1 relationship, no UNIQUE constraint on `refill_order_id`, but app creates exactly one invoice per order.

**Steps:**
1. Add `invoice_number VARCHAR(100)` and `invoice_date DATE` columns to `refill_orders`
2. Backfill: `UPDATE refill_orders ro JOIN invoices i ON ro.id = i.refill_order_id SET ro.invoice_number = i.invoice_number, ro.invoice_date = i.invoice_date`
3. Update ~8 files to read from `refill_orders` instead of `invoices`
4. Update `order-create.php` to write invoice number to `refill_orders` instead of `invoices`
5. Drop `invoices` table

---

## Detail: Phase 1.3 � Drop `gas_types.sizes` CSV column ?

**Why:** `gas_sizes` normalized table is the canonical source. CSV column on `gas_types` is legacy.

**Done:**
1. Updated `portal/dashboard.php`, `gas-types.php`, `order-create.php`, `inventory.php` to read from `gas_sizes` 
2. Updated AI actions in `action-registry.php` to write to `gas_sizes` instead of CSV columns
3. Updated `partner-transaction-create.php`, `partners.php`, `vendors.php` size maps to use `gas_sizes`
4. Wrapped legacy bootstrap fallbacks in try-catch in `ajax-get-sizes.php` and `inventory-utils.php`
5. Dropped `sizes` and `size_prices` columns from `gas_types`

------

## Detail: Phase 2.1 � Remove `customer_cylinders` ?

**Why:** Redundant with `cylinders.current_customer_id`. Data consolidated into `cylinders`.

**Done:**
1. Added `refill_count`, `total_orders`, `remarks` columns to `cylinders`
2. Added `cylinder_id INT NOT NULL` to `customer_cylinder_orders` (replacing FK to `customer_cylinders`)
3. Dropped `customer_cylinders` table; dropped `customer_cylinder_id` column from `customer_cylinder_orders`
4. Updated `inventory-utils.php` migrations, `searchCustomerCylinders`, `getCustomerCylinderHistory`
5. Updated `dispatch-settlement.php` and `refill-orders.php` to JOIN `cylinders` directly
6. Simplified `customer-cylinders.php` to redirect

---## Detail: Phase 2.2 � Convert `deleted_cylinders` to soft-delete on `cylinders` ?

**Why:** Archive table with full snapshot + transaction log JSON. Better as soft-delete columns on `cylinders`.

**Done:**
1. Added `deleted_at`, `deleted_by`, `transaction_log` columns to `cylinders`
2. Rewrote `archiveDeletedCylinder()` to UPDATE soft-delete flags on `cylinders` instead of INSERT to separate table
3. Removed physical DELETE calls from `cylinders.php` (2 places), `order-create.php`, `action-registry.php`
4. Updated `cylinder-audit-log.php` UNION query to read from `cylinders WHERE deleted_at IS NOT NULL`
5. Updated AI entity registry (`entity-registry.php`) and response builder hints
6. Removed `deleted_cylinders` table creation from `runDeletedCylindersMigration`
7. Dropped `deleted_cylinders` table

---## Detail: Phase 3.1 — Merge `gst_filing_lock` into `gst_settlements`

**Why:** Single boolean flag on the same (`business_key`, `financial_year`, `gst_period`) grain.

**Steps:**
1. ALTER TABLE `gst_settlements` ADD `is_locked`, `locked_at`, `locked_by`, `unlocked_at`, `unlocked_by`
2. Backfill from `gst_filing_lock`
3. Update `lockGSTPeriod()`, `unlockGSTPeriod()`, `isPeriodLocked()` in `gst_helper.php`
4. Update `gst-filing-config.php`, `gst-period-lock.php`, `gst-return-center.php`
5. Drop `gst_filing_lock`

---

## Detail: Phase 3.2 — Absorb `gst_filing_config` into `business_config`

**Why:** Duplicates `gstin` and `business_key` already in `business_config`.

**Steps:**
1. Add 10 columns to `business_config` (filing frequency, return type flags, etc.)
2. Backfill from `gst_filing_config`
3. Update all queries referencing `gst_filing_config` to read from `business_config`
4. Drop `gst_filing_config`

---

## Detail: Phase 3.3 — Replace `gst_validation_errors` with JSON

**Why:** Data already stored as JSON in `gst_returns.validation_results`. Separate table is redundant.

**Steps:**
1. Update `validateGSTReturn()` to skip INSERT into `gst_validation_errors`
2. Update `gst-validate.php` to parse from `validation_results` JSON using PHP `json_decode()`
3. Update `gst-reports-returns.php` to use JSON extraction
4. Drop `gst_validation_errors`

---

## Detail: Phase 3.4 — Rename `gst_amendment_log` → `audit_log`

**Why:** Generic audit schema that can serve the whole system.

**Done:**
1. Migration now creates `audit_log` table (with `module` column) instead of `gst_amendment_log`
2. Added `module VARCHAR(50) DEFAULT 'gst'` to schema
3. Updated `gst_helper.php` (2 INSERT queries) and `gst-return-detail.php` (1 SELECT) to use `audit_log`
4. Added `DROP TABLE IF EXISTS gst_amendment_log` migration cleanup
5. Added `DROP TABLE IF EXISTS gst_filing_config, gst_filing_lock` migration cleanup (consolidated)

---

## Detail: Phase 4 — Fix AI UNIQUE KEY bugs

**Status:** ✅ Done

**Why:** `ai_user_memory` and `ai_session_context` use `ON DUPLICATE KEY UPDATE` but have no UNIQUE KEY — upsert silently fails, creating duplicate rows.

**Done:**
1. Added `UNIQUE KEY uk_user_key (user_id, memory_key)` to `ai_user_memory` CREATE TABLE
2. Changed `INDEX idx_session` → `UNIQUE KEY uk_session (session_id)` on `ai_session_context` CREATE TABLE  
3. Added try-catch ALTER TABLE calls in `runAIMigrations()` for existing installations
4. Added `runAIMigrations($pdo)` call to `run-migrations.php` for comprehensive migration
