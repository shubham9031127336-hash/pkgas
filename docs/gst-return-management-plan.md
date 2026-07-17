# GST Return Management & Filing Module — Implementation Plan

> **Project:** Nutan Gases ERP  
> **Status:** Planning Phase  
> **Last Updated:** 13-Jul-2026

---

## Table of Contents

1. [Background & Architecture Rules](#1-background--architecture-rules)
2. [Database Schema Extensions](#2-database-schema-extensions)
3. [Core Library Functions](#3-core-library-functions-gst_helperphp)
4. [New Files](#4-new-files)
5. [Navigation Updates](#5-navigation-updates)
6. [Integration Points](#6-integration-points)
7. [Filing Workflow](#7-filing-workflow)
8. [Auto-Classification Engine](#8-auto-classification-engine)
9. [GST JSON Export Engine](#9-gst-json-export-engine)
10. [Excluding Internal Transactions](#10-excluding-internal-transactions)
11. [GST Return Center Dashboard](#11-gst-return-center-dashboard)
12. [Reports](#12-reports)
13. [State Machine](#13-state-machine)
14. [Implementation Order](#14-implementation-order)
15. [Non-Breaking Integration Rules](#15-non-breaking-integration-rules)

---

## 1. Background & Architecture Rules

### Current State

The ERP already has:
- `gst_ledger` — Central GST accounting table (input/output tracking)
- `gst_rates` — 5% and 18% rates
- `gst_settlements` — Monthly settlement/payment tracking
- Per-item GST columns on `refill_order_items` (rate, taxable, cgst, sgst, igst)
- Per-brand GSTIN in `business_config.gstin`
- Customer/Vendor GST numbers
- `syncGSTFromOrder()` — Auto-syncs orders to ledger
- `syncGSTFromBatch()` — Auto-syncs vendor batches to ledger
- GST Dashboard, Input/Output views, Settlement, Reports, Ledger pages

### Architecture Rules

- Follow SOLID principles
- Keep GST as an independent module
- No duplicated calculations
- Centralize tax logic
- Every GST figure must be derived from invoice data
- No hardcoded GST rates
- Support future law changes through configuration
- Keep code modular, testable, production-ready
- **Never break existing business logic**
- **Never ask users to enter GST totals manually**
- **Exclude 0% GST properly** — 0% GST is a valid tax rate, separate from "Exclude from GST Return"

---

## 2. Database Schema Extensions

All migrations follow the existing idempotent pattern in `runGSTMigrations()`.

### 2.1 New Tables

#### `gst_filing_config` — Per-brand GST filing settings

```sql
CREATE TABLE gst_filing_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_key VARCHAR(100) NOT NULL UNIQUE,
  gst_registration_type ENUM('regular','composition','unregistered','others') DEFAULT 'regular',
  filing_frequency ENUM('monthly','quarterly') DEFAULT 'monthly',
  gstin VARCHAR(15) NOT NULL DEFAULT '',
  legal_name VARCHAR(200) NOT NULL DEFAULT '',
  trade_name VARCHAR(200) NOT NULL DEFAULT '',
  state_code INT DEFAULT 0,
  default_place_of_supply VARCHAR(100) DEFAULT '',
  gst_effective_date DATE DEFAULT NULL,
  gstr1_enabled TINYINT(1) DEFAULT 1,
  gstr3b_enabled TINYINT(1) DEFAULT 1,
  gstr2b_enabled TINYINT(1) DEFAULT 1,
  gstr9_enabled TINYINT(1) DEFAULT 0,
  gstr4_enabled TINYINT(1) DEFAULT 0,
  gstr6_enabled TINYINT(1) DEFAULT 0,
  gstr7_enabled TINYINT(1) DEFAULT 0,
  gstr8_enabled TINYINT(1) DEFAULT 0,
  cmp08_enabled TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### `gst_returns` — Return versions

```sql
CREATE TABLE gst_returns (
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
);
```

#### `gst_return_items` — Line-level data per return section

```sql
CREATE TABLE gst_return_items (
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
);
```

#### `gst_validation_errors` — Validation error log

```sql
CREATE TABLE gst_validation_errors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gst_return_id INT DEFAULT NULL,
  error_type VARCHAR(50) NOT NULL,
  error_message TEXT NOT NULL,
  reference_type VARCHAR(50) DEFAULT NULL,
  reference_id INT DEFAULT NULL,
  invoice_number VARCHAR(100) DEFAULT NULL,
  field_name VARCHAR(100) DEFAULT NULL,
  field_value VARCHAR(255) DEFAULT NULL,
  severity ENUM('error','warning','info') DEFAULT 'error',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_return (gst_return_id)
);
```

#### `gst_reconciliation` — GSTR-2B purchase reconciliation

```sql
CREATE TABLE gst_reconciliation (
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
);
```

#### `gst_filing_lock` — Period filing locks

```sql
CREATE TABLE gst_filing_lock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_key VARCHAR(100) NOT NULL,
  financial_year VARCHAR(9) NOT NULL,
  gst_period VARCHAR(7) NOT NULL,
  is_locked TINYINT(1) DEFAULT 0,
  locked_at DATETIME DEFAULT NULL,
  locked_by VARCHAR(100) DEFAULT NULL,
  unlocked_at DATETIME DEFAULT NULL,
  unlocked_by VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uk_period (business_key, financial_year, gst_period)
);
```

#### `gst_amendment_log` — Amendment audit trail

```sql
CREATE TABLE gst_amendment_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gst_return_id INT DEFAULT NULL,
  reference_type VARCHAR(50) NOT NULL,
  reference_id INT NOT NULL,
  field_name VARCHAR(100) DEFAULT NULL,
  old_value TEXT DEFAULT NULL,
  new_value TEXT DEFAULT NULL,
  reason TEXT DEFAULT NULL,
  amended_by VARCHAR(100) DEFAULT NULL,
  amended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_return (gst_return_id),
  INDEX idx_reference (reference_type, reference_id)
);
```

### 2.2 Column Migrations on Existing Tables

**`refill_orders`** — Add GST return flags:
- `include_in_gst_return TINYINT(1) DEFAULT 1`
- `gst_status ENUM('draft','filed','amended') DEFAULT 'draft'`
- `invoice_type ENUM('b2b','b2c','credit_note','debit_note') DEFAULT 'b2b'`
- `place_of_supply_state_code INT DEFAULT NULL`
- `reverse_charge TINYINT(1) DEFAULT 0`

**`refill_order_items`** — Add:
- `hsn_code VARCHAR(8) DEFAULT NULL`
- `itc_eligible TINYINT(1) DEFAULT 1`

**`customers`** — Add:
- `state_code INT DEFAULT NULL`
- `state_name VARCHAR(100) DEFAULT NULL`
- `city VARCHAR(100) DEFAULT NULL`
- `pincode VARCHAR(10) DEFAULT NULL`
- `registration_type ENUM('regular','composition','unregistered','others') DEFAULT 'regular'`

**`gas_types`** — Add:
- `hsn_code VARCHAR(8) DEFAULT '280440'`

**`products`** — Add:
- `hsn_code VARCHAR(8) DEFAULT NULL`

**`vendors`** — Add:
- `state_code INT DEFAULT NULL`
- `registration_type ENUM('regular','composition','unregistered','others') DEFAULT 'regular'`

**`cylinder_suppliers`** — Add:
- `state_code INT DEFAULT NULL`
- `registration_type ENUM('regular','composition','unregistered','others') DEFAULT 'regular'`

**`partners`** — Add:
- `state_code INT DEFAULT NULL`

---

## 3. Core Library Functions (`gst_helper.php`)

### 3.1 `runGSTReturnMigrations($pdo)`
- Creates all 7 new tables + column migrations listed above
- Idempotent (safe to run on every request)

### 3.2 `getCurrentGSTPeriod()`
- Returns `['financial_year' => '2025-26', 'gst_period' => '07-2026', 'month' => 7, 'year' => 2026]`
- Helper: `getGSTPeriodForDate($date)` to derive period from any date

### 3.3 `classifyInvoice($customer_gstin)`
- B2B if GSTIN valid format and non-empty
- B2C if GSTIN empty/null
- B2CL if GSTIN valid but different state (future)
- B2CS if B2C with consolidated value (future)

### 3.4 `autoClassifyInvoice($customer)`
```php
function autoClassifyInvoice($customer) {
    $gstin = trim($customer['gst_number'] ?? '');
    if (empty($gstin)) return 'B2C';
    if (preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin)) return 'B2B';
    return 'B2C';
}
```

### 3.5 `generateGSTR1($pdo, $business_key, $period)`
- Queries eligible orders for period: `include_in_gst_return=1`, `gst_status` in draft/amended
- Classifies each order → B2B, B2CL, B2CS, CDNR
- Groups by section
- Creates `gst_returns` record
- Inserts `gst_return_items`
- Returns return ID

### 3.6 `generateGSTR3B($pdo, $business_key, $period)`
- Sources data from `gst_ledger` + `refill_orders` + purchases
- Calculates outward supplies, zero-rated, exempt, reverse charge, ITC, output tax, net liability
- Stores summary JSON in `summary_data`
- Never accepts manual input

### 3.7 `validateGSTReturn($pdo, $return_id)`
- Runs all validation rules against return items
- Stores results in `gst_validation_errors`
- Updates `gst_returns.validation_results`
- Returns `['total_errors' => N, 'total_warnings' => N, 'errors' => [...]]`

### 3.8 `exportGSTJSON($pdo, $return_id)`
- Reads return items grouped by section
- Maps to official GST portal JSON schema
- Returns JSON string
- Never stores JSON to disk

### 3.9 `lockGSTPeriod($pdo, $business_key, $period, $user)`
- Sets `is_locked = 1` in `gst_filing_lock`
- Updates all orders in period to `gst_status = 'filed'`

### 3.10 `unlockGSTPeriod($pdo, $business_key, $period, $user, $reason)`
- Creates amendment log entries
- Sets `gst_status = 'amended'` on orders
- Logs reason

---

## 4. New Files

All files follow the existing admin page pattern:

| # | File | Purpose | Access |
|---|------|---------|--------|
| 1 | `gst-return-center.php` | GST Return Center dashboard | super_admin, billing_clerk |
| 2 | `gst-return-generate.php` | Return generation (POST handler + progress) | super_admin, billing_clerk |
| 3 | `gst-return-preview.php` | Read-only return preview per section | super_admin, billing_clerk |
| 4 | `gst-return-detail.php` | Version history, section breakdown, validation errors | super_admin, billing_clerk |
| 5 | `gst-validate.php` | Validation engine page, display errors grouped | super_admin, billing_clerk |
| 6 | `gst-filing-config.php` | Per-brand filing config (registration, frequency, state) | super_admin |
| 7 | `gst-reconciliation.php` | GSTR-2B purchase reconciliation | super_admin, billing_clerk |
| 8 | `gst-reports-returns.php` | Return-specific reports (filing summary, history, validation) | super_admin, billing_clerk |
| 9 | `gst-period-lock.php` | View/manage locked/filed periods | super_admin |
| 10 | `gst-json-export.php` | JSON download endpoint (`?return_id=X`) | super_admin, billing_clerk |
| 11 | `gst-amendment.php` | Amendment workflow interface | super_admin |

### 4.1 `gst-return-center.php`

The primary dashboard. Displays only returns applicable to the brand's GST registration type.

**Regular Taxpayer shows:**
- GSTR-1
- GSTR-3B
- GSTR-2B Reconciliation
- GSTR-9 (Annual)

**Hidden unless explicitly enabled:**
- GSTR-4, GSTR-6, GSTR-7, GSTR-8, CMP-08

**Dashboard cards per return type display:**
- Current Filing Period
- Filing Frequency
- Filing Status (color-coded badge)
- Validation Status (errors/warnings count)
- Return Due Date
- Filing Progress (percentage bar)
- Last Filed Date
- Pending Errors count
- Ready For Filing indicator

**Action buttons** (state-dependent):
- Generate, Validate, Preview, Export JSON, File, Amend

### 4.2 `gst-return-generate.php`

- POST-only handler
- Accepts `?type=gstr1` or `?type=gstr3b` and `?period=MM-YYYY`
- Calls `generateGSTR1()` or `generateGSTR3B()`
- Runs post-generation validation
- Redirects to `gst-return-preview.php?return_id=X`

### 4.3 `gst-return-preview.php`

- Read-only display of generated return
- Section tabs: B2B, B2CL, B2CS, HSN Summary, Nil/Exempt, Document Summary
- Validation summary banner
- Download JSON button
- File Return / Cancel buttons

### 4.4 `gst-validate.php`

- Displays all validation errors for a return
- Grouped by: GSTIN, Duplicate Invoice, Missing HSN, Mismatch, etc.
- Each error links to the source invoice with field reference
- Re-validate button

### 4.5 `gst-filing-config.php`

- Per-brand configuration form
- Fields: Registration Type, Filing Frequency, GSTIN, Legal Name, Trade Name, State Code, Default Place of Supply, GST Effective Date
- Return type enable/disable checkboxes
- Changing frequency only affects filing schedules, never accounting

### 4.6 `gst-json-export.php`

- `GET /gst-json-export.php?return_id=X`
- Sets headers: `Content-Type: application/json`
- Outputs JSON generated by `exportGSTJSON()`
- Never saves to disk

---

## 5. Navigation Updates

Restructure the existing GST Accounting dropdown in `layout.php`:

```
GST Accounting (dropdown)
├── GST Dashboard         (existing)  gst-dashboard.php
├── Input GST             (existing)  gst-input.php
├── Output GST            (existing)  gst-output.php
├── GST Settlement        (existing)  gst-settlement.php
├── GST Reports           (existing)  gst-reports.php
├── GST Ledger            (existing)  gst-ledger.php
├── ─────────────────────────────
├── ★ GST Return Center   (NEW)      gst-return-center.php
├──   GST Filing Config   (NEW)      gst-filing-config.php
├──   GST Reconciliation  (NEW)      gst-reconciliation.php
├──   GST Return Reports  (NEW)      gst-reports-returns.php
```

New breadcrumb entries added to `$breadcrumb_map` in `layout.php`.

New `$active_menu` keys: `gst_return_center`, `gst_filing_config`, `gst_reconciliation`, `gst_return_reports`.

Permissions: `super_admin` and `billing_clerk` (except period lock which is `super_admin` only).

---

## 6. Integration Points

### 6.1 `order-create.php`

- Add `include_in_gst_return` checkbox (default checked)
- Add `invoice_type` auto-classification based on customer GSTIN
- Store `place_of_supply_state_code` from customer's state
- After `syncGSTFromOrder()`, also set `gst_status = 'draft'`

### 6.2 `customers.php` / `customer-profile.php`

- Add state code, city, pincode fields to create/edit forms
- Add registration type dropdown
- Display customer GST classification badge (B2B/B2C)

### 6.3 `invoice.php`

- Add HSN/SAC code display per line item
- Add place of supply display
- Add tax breakup by rate (already partially exists)
- Add reverse charge indicator

### 6.4 `gas-types.php`

- Add HSN code field to create/edit modal
- Default HSN for oxygen: `280440`

### 6.5 `settings-brand.php`

- Add GST state code field
- Add registration type field
- Link to `gst-filing-config.php`

### 6.6 `vendors.php`, `partners.php`, `cylinder-suppliers.php`

- Add state code field
- Add registration type dropdown

---

## 7. Filing Workflow

### 7.1 Recommended Workflow

```
1. Validate Data          → gst-validate.php
2. Fix Errors             → In original order/customer/vendor pages
3. Generate Return        → gst-return-generate.php
4. Preview Return         → gst-return-preview.php
5. Export JSON            → gst-json-export.php
6. Upload to GST Portal   → (external — user action)
7. Mark as Filed          → POST handler in gst-return-center.php
8. Lock GST Period        → gst-period-lock.php (auto on filing)
```

### 7.2 GSTR-1 Generation Pipeline

```
Invoices  →  GST Mapper (classify)  →  Section Builder  →  Validation  →  JSON
```

Steps:
1. User clicks "Generate GSTR-1"
2. System queries orders in period with `include_in_gst_return = 1`
3. Classifies each: B2B (has GSTIN), B2C (no GSTIN), etc.
4. Groups by section
5. Creates `gst_returns` record (status: draft)
6. Inserts `gst_return_items`
7. Runs validation
8. Status becomes `validated` if no errors, otherwise `draft` with errors

### 7.3 GSTR-3B Generation Pipeline

```
Output GST (gst_ledger) + Input GST (gst_ledger) + Carry Forward ITC
                              ↓
                     Summary Calculator
                              ↓
                   Outward Supplies, Zero Rated, Exempt
                   Input Tax Credit, Output Tax
                   Net Liability, Carry Forward ITC
                              ↓
                          GSTR-3B JSON
```

### 7.4 Validation Rules

| Rule | Check | Severity | Field |
|------|-------|----------|-------|
| Invalid GSTIN | Regex format check | error | customer_gstin |
| Duplicate Invoice Number | Same number in period | error | invoice_number |
| Missing HSN | Empty hsn_code on items | error | hsn_code |
| Missing Place of Supply | Empty state code | error | place_of_supply |
| Missing GST Rate | gst_rate = 0 on taxable item | error | gst_rate |
| Invalid GST Rate | Not in gst_rates table | error | gst_rate |
| Incorrect CGST/SGST Split | cgst + sgst != gst_amount | error | cgst, sgst |
| Incorrect IGST | IGST for same-state transaction | error | igst |
| Invalid Financial Year | Doesn't match order date | error | financial_year |
| Invalid GST Period | Date out of period range | error | gst_period |
| Missing Invoice Date | Null order_date | error | order_date |
| Missing Registration Type | Customer has no type | warning | customer |
| Missing State Code | No state on customer/company | warning | state_code |
| Taxable Value Mismatch | Sum(items) != header | error | taxable_amount |
| GST Amount Mismatch | Sum(items) != header | error | gst_amount |
| Total Invoice Mismatch | Stated != calculated | error | grand_total |

### 7.5 Filing Lock

After filing:
1. `gst_filing_lock.is_locked = 1`
2. All orders in period get `gst_status = 'filed'`
3. Edits blocked on locked period orders
4. Unlock requires `super_admin` + reason in amendment log

### 7.6 Amendment Workflow

1. User clicks "Amend" on a Filed return
2. System creates amendment log entry
3. New return version created (version+1)
4. Orders in period get `gst_status = 'amended'`
5. Period unlocked for editing
6. User makes changes and re-generates return

---

## 8. Auto-Classification Engine

The user must **never manually select** whether an invoice belongs to B2B or B2C.

### 8.1 Classification Rules

| Condition | Invoice Type |
|-----------|-------------|
| Customer GSTIN exists and is valid | B2B |
| Customer GSTIN is blank/null | B2C |
| B2B + different state (future) | B2CL |
| B2C consolidated (future) | B2CS |
| SEZ customer (future) | SEZ |
| Export (future) | Export |
| Reverse charge (future) | Reverse Charge |
| Nil rated items (future) | Nil Rated |
| Exempt supply (future) | Exempt |

### 8.2 Key Principle

Classification occurs **only during GST export** while preserving the original invoice. The original `refill_orders` record retains its raw data; `invoice_type` is set once but can be overridden by the classifier during GSTR-1 generation.

---

## 9. GST JSON Export Engine

### 9.1 Architecture

```
Database (gst_return_items)
    ↓
Business Rules (validate, classify)
    ↓
GST Section Builder (group by section)
    ↓
GST Validation (check data integrity)
    ↓
Official GST JSON (portal-compatible format)
    ↓
Download / API
```

### 9.2 Never Store JSON Permanently

- Generated on-the-fly from `gst_return_items` data
- `json_data` column in `gst_returns` is for caching/preview only
- Always regenerate from live accounting data
- No file-based storage of GST JSON

### 9.3 JSON Structure (GSTR-1)

```json
{
  "gstin": "27AAAAA0000A1Z5",
  "fp": "072026",
  "gt": 1500000.00,
  "cur_gt": 1500000.00,
  "b2b": [
    {
      "ctin": "27BBBBB0000B1Z5",
      "inv": [
        {
          "inum": "INV-2026-0001",
          "idt": "15-07-2026",
          "val": 5000.00,
          "pos": "27",
          "rchg": "N",
          "itms": [
            {
              "num": 1,
              "itm_det": {
                "txval": 4237.29,
                "rt": 18,
                "iamt": 0.00,
                "camt": 381.36,
                "samt": 381.36,
                "csamt": 0.00
              }
            }
          ]
        }
      ]
    }
  ],
  "b2cs": [...],
  "nil": {
    "inv": [],
    "nil_amt": 0,
    "expt_amt": 0,
    "ngsup_amt": 0
  },
  "hsn": [...],
  "doc_issue": [...]
}
```

---

## 10. Excluding Internal Transactions

### 10.1 `include_in_gst_return` Flag

- Added to `refill_orders` as a column
- Default: `1` (include)

### 10.2 Behavior When Excluded (value = 0)

**Still updated:**
- Inventory
- Ledger
- Payment
- Customer History
- Vendor History
- Profit Reports

**Excluded from:**
- GSTR-1 generation
- GST Reports
- GST Calculation
- GST Settlement
- GST JSON Export

### 10.3 Important Distinction

| Scenario | `gst_rate` | `include_in_gst_return` | Behavior |
|----------|-----------|------------------------|----------|
| 0% GST supply (e.g., exempt goods) | 0.00 | 1 | Appears in nil-rated section of GSTR-1 |
| Internal transfer (e.g., warehouse) | 18.00 | 0 | Not in any GST return at all |

---

## 11. GST Return Center Dashboard

### 11.1 Layout

```
┌──────────────────────────────────────────────────────────────────┐
│  [Brand: Nutan Gases ▾]  [FY: 2025-26 ▾]  [Period: Jul 2026 ▾] │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌── GSTR-1 ─────────────────────────────────────────────────┐  │
│  │  Period: Jul 2026    Status: ● VALIDATED  (green badge)   │  │
│  │  Frequency: Monthly  Due: 20-Aug-2026                     │  │
│  │  Validation: 0 errors     Progress: ████████░░ 80%       │  │
│  │  Last Filed: 15-Jul-2026  Ready For Filing: ✅ Yes       │  │
│  │                                                            │  │
│  │  [Generate] [Validate] [Preview] [Export] [File] [Amend]  │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                  │
│  ┌── GSTR-3B ─────────────────────────────────────────────────┐  │
│  │  Period: Jul 2026    Status: ◉ DRAFT     (gray badge)     │  │
│  │  Frequency: Monthly  Due: 20-Aug-2026                     │  │
│  │  Validation: 3 errors     Progress: ██░░░░░░ 20%         │  │
│  │  Last Filed: —            Ready For Filing: ❌ No         │  │
│  │                                                            │  │
│  │  [Generate] [Validate] [Preview]                           │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                  │
│  ┌── GSTR-2B Reconciliation ──────────────────────────────────┐  │
│  │  Period: Jul 2026    Matched: 12/15  Pending: 3           │  │
│  │  [Reconcile] [Upload Portal Data]                          │  │
│  └────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
```

### 11.2 Return Type Visibility

Only display returns applicable to the brand's GST registration type:

- **Regular:** GSTR-1, GSTR-3B, GSTR-2B, GSTR-9
- **Composition:** CMP-08, GSTR-4
- **Others:** As configured in `gst_filing_config`

---

## 12. Reports

All return-specific reports live in `gst-reports-returns.php` with tabs:

| Report | Description |
|--------|-------------|
| GSTR-1 Preview | Section-by-section preview of GSTR-1 data |
| GSTR-3B Preview | Summary figures for GSTR-3B |
| Filing Summary | All returns filed vs pending |
| Filing History | All versions of all returns |
| GST Error Report | All validation errors with invoice refs |
| GST Validation Report | Pass/fail stats per return |
| GST Reconciliation Report | GSTR-2B matching status |
| Filed vs Pending Report | Invoices by filing status |
| Amendment Register | All amendment records |
| Credit/Debit Note Register | All CDNR with references |
| Return Audit Trail | Who generated/filed/amended what |

---

## 13. State Machine

```
                  ┌──────────────┐
                  │    DRAFT     │
                  └──────┬───────┘
                         │ Generate / Re-generate
                         ▼
                  ┌──────────────┐
         ┌───────►│  VALIDATED   │◄────────┐
         │        └──────┬───────┘         │
         │               │ Export JSON     │
         │               ▼                 │
         │        ┌──────────────┐         │
         │        │READY FOR     │         │
         │        │ FILING       │         │
         │        └──────┬───────┘         │
         │               │ Mark as Filed   │
         │               ▼                 │
         │        ┌──────────────┐         │
         │        │    FILED     │         │
         │        └──────┬───────┘         │
         │               │ Amend           │
         │               ▼                 │
         │        ┌──────────────┐         │
         └────────┤   AMENDED    ├─────────┘
                  └──────────────┘
```

**Status Transitions:**
- DRAFT → VALIDATED (generate + pass validation)
- DRAFT → DRAFT (generate + fail validation)
- VALIDATED → DRAFT (re-generate)
- VALIDATED → READY FOR FILING (preview + approve)
- READY FOR FILING → FILED (mark as filed + lock period)
- FILED → AMENDED (initiate amendment)
- AMENDED → DRAFT (new version created)

---

## 14. Implementation Order

| Step | Task | Files | Dependencies |
|------|------|-------|-------------|
| 1 | Add migration functions for new tables + columns | `gst_helper.php` | — |
| 2 | Add core helper functions (period, classify) | `gst_helper.php` | Step 1 |
| 3 | Create `gst-filing-config.php` | New file | Step 1 |
| 4 | Add state/registration fields to customer CRUD | `customers.php`, `customer-profile.php` | — |
| 5 | Add HSN fields to gas types + products | `gas-types.php` | — |
| 6 | Add filing fields to order create flow | `order-create.php` | Step 2 |
| 7 | Add `include_in_gst_return` to order flow | `order-create.php`, `order-edit.php` | — |
| 8 | Create `gst-return-center.php` | New file | Step 1, 2 |
| 9 | Create `gst-return-generate.php` (GSTR-1) | New file | Step 2, 8 |
| 10 | Create `gst-return-preview.php` | New file | Step 9 |
| 11 | Create `gst-return-detail.php` | New file | Step 9 |
| 12 | Create `gst-validate.php` | New file | Step 2 |
| 13 | Create `gst-json-export.php` | New file | Step 2 |
| 14 | Implement GSTR-3B generation | `gst_helper.php` + generator | Step 2 |
| 15 | Implement filing lock system | `gst_helper.php` + `gst-period-lock.php` | Step 1 |
| 16 | Create `gst-reconciliation.php` | New file | Step 1 |
| 17 | Create `gst-reports-returns.php` | New file | Step 9 |
| 18 | Add navigation entries | `layout.php` | Steps 8-17 |
| 19 | Add amendment workflow | `gst-amendment.php` | Step 15 |
| 20 | Update invoice.php for HSN/breakup display | `invoice.php` | Step 5 |

---

## 15. Non-Breaking Integration Rules

1. **No existing data changes** — New columns have safe defaults; existing orders get `include_in_gst_return=1`, `gst_status='draft'`, `invoice_type='b2b'`
2. **No existing functionality changes** — All existing GST pages continue working unchanged
3. **No output buffering changes** — Follow JS redirect pattern (`echo "<script>window.location.href='...'</script>"`)
4. **No composer/package dependencies** — Pure PHP, no external libraries
5. **Existing GST ledger untouched** — Return filing reads from ledger but never modifies it
6. **Existing `gst_settlements` table separate** — Settlement is for payment tracking; return filing is for compliance
7. **Backward compatible** — All existing reports, dashboards, invoices, and flows remain identical
8. **All GST figures derived from invoice data** — Never accept manual GST total entry
9. **Future-ready** — Interfaces designed for extensibility without DB redesign

---

*End of Plan*
