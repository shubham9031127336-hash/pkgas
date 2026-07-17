# E2E Browser Test Plan — Nutan Gases

**Version:** 2.0  
**Date:** 14 July 2026  
**Purpose:** Guide an AI to write Playwright browser tests that verify every feature end-to-end.  
**Not for fixing** — only for checking, recording, and reporting failures.

---

## How to use this document

Each test case below is structured so an AI (or human) can:

1. **Read the feature description** — understand what the feature does
2. **Follow the URL flow** — navigate the exact pages
3. **Execute test steps** — click, fill, submit in the browser
4. **Verify UI** — check what appears on screen
5. **Verify data** — run SQL queries to confirm database state
6. **Check linked features** — confirm connected features are also correct
7. **Log failures** — record exactly what broke

**Priority system:** P0 = business-critical (test first), P4 = nice-to-have  

**Notation:** `[UI]` = check the browser page, `[DB]` = run SQL query, `[LINK]` = check connected feature

---

## Priority & Execution Strategy

| Priority | When | Why |
|----------|------|-----|
| **P0** | Phase 1 | Core money flows: order creation, cylinder exchange, payments — if these break, business stops |
| **P1** | Phase 2 | Supporting transactions: vendor dispatch/receive, partner borrow/return, rental return |
| **P2** | Phase 3 | Customer & portal: create/edit/delete customers, portal self-service, profile |
| **P3** | Phase 4 | Platform admin: RBAC, settings, GST, blog, expenses, reports |
| **P4** | Phase 5 | Nice-to-have: AI assistant, public site, SEO, edge cases |

---

<!-- test-progress
{
  "last_updated": "2026-07-14",
  "phases": {
    "phase1": {"label": "Core Business Flows (P0)", "status": "complete", "tests": 25, "passed": 25, "failed": 0, "skipped": 0, "last_run": "2026-07-14"},
    "phase2": {"label": "Transaction & Settlement (P1)", "status": "complete", "tests": 18, "passed": 18, "failed": 0, "skipped": 0, "last_run": "2026-07-14"},
    "phase3": {"label": "Customer & Portal (P2)", "status": "complete", "tests": 32, "passed": 32, "failed": 0, "skipped": 0, "last_run": "2026-07-14"},
    "phase4": {"label": "Platform Admin (P3)", "status": "complete", "tests": 29, "passed": 29, "failed": 0, "skipped": 0, "last_run": "2026-07-14"},
    "phase5": {"label": "AI Assistant (P3)", "status": "complete", "tests": 6, "passed": 6, "failed": 0, "skipped": 0, "last_run": "2026-07-14"},
    "phase6": {"label": "Public Site (P3)", "status": "complete", "tests": 16, "passed": 16, "failed": 0, "skipped": 0, "last_run": "2026-07-14"},
    "phase7": {"label": "Cross-Cutting (P4)", "status": "complete", "tests": 7, "passed": 7, "failed": 0, "skipped": 0, "last_run": "2026-07-14"},
    "deep_p0": {"label": "Deep Functional — P0 (Order modes, Exchange)", "status": "complete", "tests": 9, "passed": 8, "failed": 0, "skipped": 1, "last_run": "2026-07-14"},
    "deep_p1": {"label": "Deep Functional — P1 (Vendor, Partner, Rental)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null},
    "deep_p2": {"label": "Deep Functional — P2 (Customer, Portal)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null}
  }
-->

## Test Progress

| Phase | Status | Tests | Passed | Failed | Last Run |
|---|---|---|---|---|---|
| Phase 1: Core Business Flows (P0) | ✅ Complete | 25 | 25 | 0 | 2026-07-14 |
| Phase 2: Transaction & Settlement (P1) | ✅ Complete | 18 | 18 | 0 | 2026-07-14 |
| Phase 3: Customer & Portal (P2) | ✅ Complete | 32 | 32 | 0 | 2026-07-14 |
| Phase 4: Platform Admin (P3) | ✅ Complete | 29 | 29 | 0 | 2026-07-14 |
| Phase 5: AI Assistant (P3) | ✅ Complete | 6 | 6 | 0 | 2026-07-14 |
| Phase 6: Public Site (P3) | ✅ Complete | 16 | 16 | 0 | 2026-07-14 |
| Phase 7: Cross-Cutting (P4) | ✅ Complete | 7 | 7 | 0 | 2026-07-14 |
| Deep P0: Order modes, Exchange | ✅ Complete | 9 | 8 | 0 | 2026-07-14 |
| Deep P1: Vendor, Partner, Rental | ⬜ Not Started | 0 | 0 | 0 | - |
| Deep P2: Customer, Portal | ⬜ Not Started | 0 | 0 | 0 | - |

---

## Feature Dependency Map

```
                    ┌──────────────┐
                    │  Gas Types   │──┐
                    └──────────────┘  │
                                      ▼
┌──────────┐    ┌────────────────┐    ┌──────────────┐    ┌──────────────────┐
│ Vendors  │───▶│ Cylinder Mgmt  │◀───│  Customers   │───▶│  Portal (self)   │
└──────────┘    └───────┬────────┘    └──────────────┘    └──────────────────┘
                        │                                      │
                        ▼                                      ▼
              ┌──────────────────┐                    ┌──────────────────┐
              │  Order Creation  │                    │  Payments (self) │
              │  (5 order modes) │                    └──────────────────┘
              └───────┬──────────┘
                      │
                      ▼
              ┌──────────────────┐
              │  Invoice/Receipt │──▶ GST Ledger
              └──────────────────┘
                      │
                      ▼
              ┌──────────────────┐
              │  Dispatch Settle │──▶ Payments, Ledger, Credit
              └──────────────────┘

┌──────────┐    ┌──────────────────┐
│ Partners │───▶│ Cylinder Exchange│
└──────────┘    └──────────────────┘

┌──────────┐    ┌──────────────────┐    ┌──────────────────┐
│  RBAC    │───▶│  Every Admin Page│───▶│   AI Assistant   │
└──────────┘    └──────────────────┘    └──────────────────┘
```

---

> **Key insight:** Every admin page that modifies cylinder/order/customer data must trigger `syncInventory()`. If the inventory numbers are wrong after any flow, that flow is broken.

---

## TABLE OF CONTENTS

1. [Phase 0: Prerequisites & Test Data](#phase-0-prerequisites--test-data)
2. [Phase 1: Core Business Flows (P0)](#phase-1-core-business-flows-p0)
3. [Phase 2: Transaction & Settlement (P1)](#phase-2-transaction--settlement-p1)
4. [Phase 3: Customer & Portal (P2)](#phase-3-customer--portal-p2)
5. [Phase 4: Platform Admin (P3)](#phase-4-platform-admin-p3)
6. [Phase 5: AI Assistant (P3)](#phase-5-ai-assistant-p3)
7. [Phase 6: Public Site (P3)](#phase-6-public-site-p3)
8. [Phase 7: Cross-Cutting Verification (P4)](#phase-7-cross-cutting-verification-p4)
9. [Appendix: Key SQL Queries](#appendix-key-sql-queries)

---

## Phase 0: Prerequisites & Test Data

### What must be ready before any test

| Requirement | Check | SQL/Command |
|---|---|---|
| DB connection works | `admin/db.php` connects | `SELECT 1` |
| Admin users exist | admin/admin123, clerk/clerk123, warehouse/warehouse123 | `SELECT * FROM users` |
| Gas types seeded | Oxygen, Nitrogen, Argon, Acetylene, CO2, etc. | `SELECT COUNT(*) FROM gas_types` |
| Test customers exist | At least 1 refill + 1 rental customer with login enabled | `SELECT id, name, email, login_enabled FROM customers WHERE login_enabled=1` |
| Test cylinders exist | Multiple filled, empty, with_customer, sent_to_vendor | `SELECT status, COUNT(*) FROM cylinders GROUP BY status` |
| Portal test account | test@test.com / test123 (or similar) with `login_enabled=1` | `SELECT * FROM customers WHERE email='test@test.com'` |
| At least 1 vendor | For send/receive flow | `SELECT COUNT(*) FROM vendors` |
| At least 1 partner | For borrow/return flow | `SELECT COUNT(*) FROM partners` |
| At least 1 refill order | For invoice, return, settlement tests | `SELECT COUNT(*) FROM refill_orders` |
| Playwright installed | `npx playwright test` runs | Check `node_modules/.bin/` |

### Seed data helper

Use `tests/seed_test_data.php` to create baseline data:
```bash
php tests/seed_test_data.php
```
This creates test customers (9999999900, 9999999901), a vendor (9999999902), a partner (9999999903), and test cylinders (TEST-OX-001 through TEST-AR-002).

### Available test credentials

| Role | Username | Password | Portal |
|------|----------|----------|--------|
| Super Admin | admin | admin123 | No |
| Billing Clerk | clerk | clerk123 | No |
| Warehouse Sup | warehouse | warehouse123 | No |
| Portal Customer | test@test.com | test123 | Yes |

---

## Phase 1: Core Business Flows (P0)

These are the money flows. If any of these break, the business stops running. **Test these first.**

### 1.1 Admin Login

**Feature:** Admin authentication with RBAC  
**Pages:** `/admin/login.php`, `/admin/dashboard.php`, `/admin/logout.php`  
**Priority:** P0  
**Connected features:** Every admin page depends on this

| # | Test | Steps | UI Verify | DB Verify | Linked Features |
|---|---|---|---|---|---|
| L1.1 | **Login page loads** | Navigate to `/admin/login.php` | Username field, password field, submit button visible. CSRF hidden input present with value length ≥ 10 | — | CSRF |
| L1.2 | **Valid login** | Fill username=`admin`, password=`admin123`, click submit | Redirects to `/admin/dashboard.php`. Sidebar nav visible. KPI cards show numbers. No JS console errors | `SELECT * FROM activity_logs WHERE action='login' ORDER BY created_at DESC LIMIT 1` → row exists with username='admin' | Dashboard, RBAC |
| L1.3 | **Invalid login** | Fill username=`admin`, password=`wrongpass`, click submit | Stays on `/admin/login.php`. Error banner visible with text like "Invalid credentials" | `SELECT COUNT(*) FROM activity_logs WHERE action='login_failed'` → count incremented | — |
| L1.4 | **Logout** | Click logout link or navigate to `/admin/logout.php` | Redirects to `/admin/login.php`. No session cookie | Session destroyed | — |
| L1.5 | **Unauthenticated access blocked** | Try navigating to `/admin/dashboard.php` directly (fresh browser, no session) | Redirects to `/admin/login.php` | — | RBAC, every page |

**Common failures:** Missing CSRF field → 500 error on submit. Layout.php not found → blank page. Session cookie not set → infinite redirect loop.

---

### 1.2 Dashboard Load

**Feature:** Admin dashboard with KPI cards, charts, recent data  
**Pages:** `/admin/dashboard.php`  
**Priority:** P0  
**Prerequisites:** Logged in as admin  

| # | Test | Steps | UI Verify | DB Verify | Linked Features |
|---|---|---|---|---|---|
| D1.2.1 | **Dashboard loads with stats** | Login, navigate to dashboard | KPI cards: Total Cylinders, Filled, Empty, With Customer, Sent to Vendor. Recent orders table (max 5 rows). Low stock alerts section. Chart canvas elements visible. No JS console errors | `SELECT COUNT(*) FROM cylinders` should match the "Total Cylinders" KPI. `SELECT COUNT(*) FROM cylinders WHERE status='filled'` should match "Filled" KPI | All features — dashboard aggregates from all tables |
| D1.2.2 | **Migrations run without error** | Reload dashboard | Page loads without 500 error. No SQL exception traces in HTML | Run each migration function: `runConsumerCylinderMigrations()`, `runRefillRentalMigrations()`, `runSellCylinderMigrations()`, etc. → no exceptions | All features — migrations add columns needed by other pages |

**Common failures:** Migration throws exception on duplicate column → 500 error. `syncInventory()` not called after data change → wrong counts. Cache file write permission → slow page.

---

### 1.3 Order Creation — Core Flow

**Feature:** Create refill order with cylinder allocation, exchange returns, payment, invoice  
**Pages:** `/admin/order-create.php`, `/admin/invoice.php?order_id=X`  
**Priority:** P0  
**Prerequisites:** At least 1 filled cylinder, 1 refill customer with login, 1 gas type with sizes  
**Connected features:** Cylinders, Inventory, Customers, Payments, Invoices, GST, Ledger

#### 1.3.1 Refill Order (is_rental=0) — Cash Payment

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| O1.3.1 | **Order page loads** | Login, navigate to `/admin/order-create.php` | Customer search combobox visible. Item row with gas type dropdown, size dropdown, quantity input. Payment method dropdown | — |
| O1.3.2 | **Select customer** | Click customer search, type partial name, select from dropdown | Customer name fills in. Exchange balance info loads (if any). Mode selector auto-populates | `[DB]` Customer record exists, `customer_type` matches |
| O1.3.3 | **Add refill item** | Select gas type=Oxygen, size=47L, qty=1, payment method=Cash | Price per unit auto-fills from gas_types. Available cylinder serials load (drop-down or auto-assign) | `[DB]` `SELECT filled_stock FROM inventory WHERE gas_type_id=? AND size_capacity=?` → should be > 0 |
| O1.3.4 | **Submit order** | Click submit | Redirects to `/admin/invoice.php?order_id=X`. Invoice number visible (format: INV-YYYY-NNNN). Items table shows correct quantities and prices. Grand total = subtotal + tax - discount | `[DB1]` `SELECT * FROM refill_orders WHERE id=?` → `payment_status='paid'`, `grand_total` matches UI. `[DB2]` `SELECT * FROM refill_order_items WHERE refill_order_id=?` → rows created. `[DB3]` `SELECT * FROM cylinders WHERE id=?` → `status='with_customer'`, `current_customer_id` set. `[DB4]` `SELECT * FROM cylinder_transactions WHERE cylinder_id=?` → `transaction_type='issue_to_customer'`. `[DB5]` `SELECT * FROM payments WHERE refill_order_id=?` → amount matches grand_total. `[DB6]` `SELECT * FROM invoices WHERE refill_order_id=?` → invoice_number matches UI |
| O1.3.5 | **Inventory updated after order** | — | — | `[DB]` `SELECT filled_stock, with_customer_stock FROM inventory WHERE gas_type_id=? AND size_capacity=?` → filled_stock decreased by 1, with_customer_stock increased by 1. Run `syncInventory($pdo)` then compare counts |

**Common failures:** No filled cylinders available → order silently fails. Cylinder allocated to wrong customer. Invoice number not sequential. Payment not recorded. `syncInventory()` not called → inventory counts wrong. JS redirect (`window.location.href`) breaks if PHP error occurs before redirect.

#### 1.3.2 Credit Order (is_rental=0, payment on credit)

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| O1.3.6 | **Credit order** | Select customer with credit_limit, add refill item, select payment method=Credit | No payment collected. Order shows "pending" status | `[DB1]` `SELECT payment_status FROM refill_orders WHERE id=?` → 'pending'. `[DB2]` `SELECT credit_used FROM customers WHERE id=?` → incremented by grand_total. `[DB3]` Check `credit_transactions` table for `transaction_type='charge'` |

**Common failures:** Credit limit not checked before creating order. `credit_used` not updated. Missing `credit_transactions` entry.

#### 1.3.3 Rental Order (is_rental=1)

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| O1.3.7 | **Rental order** | Select rental-type customer, mode auto-selects "Cylinder Rental" (is_rental=1), set rent_per_day=15, free_days=3, select gas/size, submit | Invoice shows rental-specific fields: daily rent, free days, deposit amount. Rent per day and free days fields visible | `[DB1]` Check `refill_order_items` → `is_rental=1`, `rent_per_day=15.00`, `free_days=3`. `[DB2]` `SELECT * FROM cylinders WHERE id=?` → `daily_rent_rate=15.00`, `free_days=3`, `borrow_date` set |

**Common failures:** Free days not stored. `borrow_date` missing. Rent rate not saved.

#### 1.3.4 Sell Cylinder (is_rental=2)

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| O1.3.8 | **Sell cylinder** | Select customer, switch mode to "Sell Cylinder" (is_rental=2), pick gas/size, set sell price=2000, select specific cylinder to sell, submit | Invoice shows sell price, cylinder serial sold | `[DB1]` Cylinder removed from `cylinders` table (soft-deleted or archived). `[DB2]` `refill_order_items` has `sell_price` and `sold_cylinder_serial` |

**Common failures:** Cylinder not removed from inventory. `syncInventory()` not called. Sell cylinder still appears as available in future orders.

#### 1.3.5 Sell Product (is_rental=3)

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| O1.3.6 | **Sell product** | Select customer, mode="Sell Product" (is_rental=3), pick product (e.g. Gas Regulator), qty=2, submit | Invoice shows product name, quantity, price | `[DB1]` `SELECT stock_quantity FROM products WHERE id=?` → decreased by 2. `[DB2]` `refill_order_items` has `product_id` and `product_qty` |

**Common failures:** Product stock not decremented. Product not found in dropdown.

#### 1.3.6 Customer Refill Service (is_rental=4)

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| O1.3.7 | **Refill service** | Select customer, mode="Customer Cylinder Refill Service" (is_rental=4), pick gas/size, enter customer's own cylinder serials, submit | Invoice shows service charge | `[DB1]` `SELECT * FROM customer_refill_services WHERE refill_order_id=?` → `status='received'`. `[DB2]` New cylinder created with `ownership_type='consumer_owned'` if serial was new |

**Common failures:** Customer cylinder serial not registered. Service charge not calculated. Status not set to 'received'.

---

### 1.4 Cylinder Exchange Settlement

**Feature:** Customer returns company cylinders, gets their own cylinders back  
**Pages:** `/admin/cylinder-exchange.php`  
**Priority:** P0  
**Prerequisites:** Customer has company cylinders assigned (`with_customer`)  
**Connected features:** Cylinders, Customers, Inventory, Cylinder Transactions, Ledger

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| EX1.4.1 | **Exchange page loads** | Login, navigate to `/admin/cylinder-exchange.php` | Customer search combobox visible. Exchange form with serial entry fields visible | — |
| EX1.4.2 | **Select customer loads balance** | Search and select customer who has cylinders | Two panels appear: "Customer Returns" (left) shows quick-pick buttons for customer's held cylinders. "Give Back to Customer" (right) shows consumer-owned cylinders we hold. Net balance displayed in summary | `[DB]` `SELECT COUNT(*) FROM cylinders WHERE current_customer_id=?` → count matches UI. `[DB]` `SELECT COUNT(*) FROM cylinders WHERE ownership_type='consumer_owned' AND original_owner_customer_id=? AND status='filled'` → count matches UI |
| EX1.4.3 | **Return company cylinder** | Click quick-pick on a company cylinder to add it to return panel | Serial appears in return list. Damage amount field shown per row | `[DB]` After submit: `SELECT status FROM cylinders WHERE id=?` → 'empty'. `[DB]` `SELECT current_customer_id FROM cylinders WHERE id=?` → NULL. `[DB]` Check `cylinder_transactions` for `transaction_type='return_from_customer'` |
| EX1.4.4 | **Return with damage** | Add damage amount=200 and description="Dent" on return row | Damage amount displayed in row total | `[DB]` Damage recorded. `[DB]` `total_damage` in exchange ledger entry |
| EX1.4.5 | **Give back consumer-owned cylinder** | Add consumer-owned cylinder serial to give-back panel | Serial appears in give-back list | `[DB]` After submit: `SELECT status FROM cylinders WHERE id=?` → 'returned_to_consumer' (settled). `[DB]` `current_customer_id` NULL |
| EX1.4.6 | **Submit exchange settlement** | Add returns + give-backs, click "Settle Exchange" | Success message. Redirect or stay with summary | `[DB1]` All return cylinders status='empty'. `[DB2]` All give-back cylinders status='returned_to_consumer'. `[DB3]` `SELECT * FROM cylinder_transactions WHERE transaction_type IN ('return_from_customer','consumer_give_back','consumer_return')` → rows for each operation. `[DB4]` Check `ledger_groups` for `group_type='exchange_settlement'`
| EX1.4.7 | **Empty serial shows validation** | Click "Settle Exchange" with no serials entered | Error message: "Please enter at least one cylinder serial" | No DB changes |
| EX1.4.8 | **Inventory sync after exchange** | — | — | `[DB]` `SELECT * FROM inventory` → run `syncInventory()` and compare with raw cylinder counts |

**Common failures:** Consumer-owned cylinder not settled correctly. Damage not recorded. Cylinder transferred to wrong customer. `active_cylinders_count` on customer not decremented. `syncInventory()` not called.

---

### 1.5 Cylinder Return Flow

**Feature:** Return filled cylinders from warehouse to customer (refill service pipeline completion)  
**Pages:** `/admin/return-cylinder.php`  
**Priority:** P0  
**Prerequisites:** Customer has refill service order with status='filled_from_vendor' or 'returned_to_warehouse'  
**Connected features:** Cylinders, Orders, Customer Refill Services, Payments

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| RT1.5.1 | **Return page loads** | Login, navigate to `/admin/return-cylinder.php` | 4-step progress visible. Customer search combobox visible. Layout container visible | — |
| RT1.5.2 | **Search and select customer** | Type customer name in combobox, select from dropdown | Customer name selected, lot/order section appears | `[DB]` Customer exists and has eligible orders |
| RT1.5.3 | **Select order** | Click on an order/lot that has refill services | Cylinder list loads with checkboxes. Payment section appears | `[DB]` `SELECT * FROM customer_refill_services WHERE customer_id=? AND refill_order_id=?` → records with status in ('filled_from_vendor','returned_to_warehouse') |
| RT1.5.4 | **Return cylinders** | Select cylinders to return, enter payment amount, method, click submit | Success message. Cylinders marked as returned | `[DB1]` `SELECT status FROM cylinders WHERE id=?` → 'returned_to_consumer'. `[DB2]` `SELECT status FROM customer_refill_services WHERE id=?` → 'returned_to_customer'. `[DB3]` `SELECT * FROM payments WHERE refill_order_id=?` → payment recorded. `[DB4]` `SELECT payment_status FROM refill_orders WHERE id=?` → updated |
| RT1.5.5 | **Prevent duplicate return** | Try returning the same cylinder again | Error: "already returned" | No duplicate DB changes |

**Common failures:** Duplicate return not prevented. Cylinder status not updated. Payment not recorded. `syncInventory()` not called.

---

## Phase 2: Transaction & Settlement (P1)

### 2.1 Cylinder Send to Vendor

**Feature:** Dispatch empty cylinders to vendor for refill  
**Pages:** `/admin/send-cylinder.php`, `/admin/lot-dashboard.php`  
**Priority:** P1  
**Prerequisites:** Empty cylinders exist, vendor exists  
**Connected features:** Cylinders, Vendors, Lots, Payments

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| SV2.1.1 | **Send page loads** | Login, navigate to `/admin/send-cylinder.php` | Vendor dropdown visible, cylinder list with checkboxes, gas filter, ownership filter buttons | — |
| SV2.1.2 | **Select vendor filters cylinders** | Select a vendor | Cylinder list shows only cylinders that can be sent to this vendor | `[DB]` Vendor exists in vendors table |
| SV2.1.3 | **Gas type filter** | Select gas type filter (e.g. Oxygen) | Only Oxygen cylinders shown | `[DB]` `SELECT COUNT(*) FROM cylinders WHERE gas_type_id=? AND status='empty'` → matches filtered count |
| SV2.1.4 | **Ownership filter** | Click "Owned" filter | Only company-owned cylinders shown. Click "All" → all cylinders shown | `[DB]` Ownership filter works: `WHERE ownership_type='owned'` |
| SV2.1.5 | **Select all / deselect all** | Check "Select All" checkbox | All visible cylinder checkboxes checked. Uncheck → all unchecked | — |
| SV2.1.6 | **Submit disabled without selection** | No vendor + no cylinders selected | Submit button disabled | — |
| SV2.1.7 | **Full dispatch with advance + GST + transport** | Select vendor, select 2 cylinders, fill driver name, vehicle number, transport cost=500, enable advance=2500, payment method=Cash, click submit | Redirects to lot-dashboard.php or shows success. Lot created | `[DB1]` `SELECT status FROM cylinders WHERE id=?` → 'sent_to_vendor'. `[DB2]` `SELECT current_vendor_id FROM cylinders WHERE id=?` → vendor.id set. `[DB3]` `SELECT * FROM cylinder_transactions WHERE transaction_type='send_to_vendor'` → row exists. `[DB4]` `SELECT * FROM vendor_refill_batches WHERE vendor_id=?` → lot created. `[DB5]` `SELECT * FROM payments WHERE vendor_id=? AND payment_type='vendor_payment'` → advance payment recorded |
| SV2.1.8 | **Dispatch without advance** | Dispatch 1 cylinder without advance | Lot created, no advance payment | `[DB]` No advance payment. Lot exists with `payment_status='unpaid'` |

**Common failures:** Cylinder status not updated. Vendor not set on cylinder. Transport cost not calculated per-cylinder. Advance payment not recorded. Lot not created.

---

### 2.2 Cylinder Receive from Vendor

**Feature:** Receive filled cylinders back from vendor  
**Pages:** `/admin/receive-cylinder.php`  
**Priority:** P1  
**Prerequisites:** Vendor has open lots (dispatched cylinders)  
**Connected features:** Cylinders, Vendors, Lots, Payments, GST

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| RV2.2.1 | **Receive page loads** | Login, navigate to `/admin/receive-cylinder.php` | 5-step progress bar. Vendor select with data-lots attribute. Layout container visible | — |
| RV2.2.2 | **Select vendor shows lots** | Select vendor who has open lots | Lot checkboxes appear in #lotCheckboxGroup | `[DB]` `SELECT * FROM vendor_refill_batches WHERE vendor_id=? AND status='open'` → lots exist |
| RV2.2.3 | **Select lot shows summary** | Check a lot checkbox | Summary card appears with quantity, dates. Cylinder list shows pending cylinders | `[DB]` Batch details correct |
| RV2.2.4 | **Full receive flow** | Select lot, select all cylinders, fill transport cost, GST, cost details, add payment row, click submit | Redirects to lot-dashboard or shows success | `[DB1]` `SELECT status FROM cylinders WHERE id=?` → 'filled'. `[DB2]` `current_vendor_id` → NULL. `[DB3]` `SELECT * FROM vendor_refill_batches WHERE id=?` → `payment_status` updated. `[DB4]` `SELECT * FROM payments` → payment recorded. `[DB5]` Inventory: `filled_stock` incremented |
| RV2.2.5 | **Advance utilization visible** | Select vendor with advance balance (e.g. Universal Gas) | Advance reconciliation section shows vendor balance | `[DB]` `[DB]` Check vendor advance balance query |

**Common failures:** Cylinder status not updated to 'filled'. Vendor not cleared from cylinder. Batch not marked complete. Payment not recorded. Transport cost not calculated.

---

### 2.3 Lot Dashboard

**Feature:** View and filter dispatch lots  
**Pages:** `/admin/lot-dashboard.php`  
**Priority:** P1  
**Prerequisites:** Lots exist (cylinders have been sent to vendors)  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| LD2.3.1 | **Lot dashboard loads** | Login, navigate to `/admin/lot-dashboard.php` | Filter bar with vendor select, status filter. Lot cards with lot numbers, vendor names, dates, status | `[DB]` `SELECT COUNT(*) FROM vendor_refill_batches` → matches card count |
| LD2.3.2 | **Filter by vendor** | Select vendor filter, click filter/submit | URL changes to include vendor_id. Only that vendor's lots shown | `[DB]` `SELECT * FROM vendor_refill_batches WHERE vendor_id=?` → matches |
| LD2.3.3 | **Filter by status** | Select status filter (open/closed) | Only matching status lots shown | `[DB]` Filter works |

---

### 2.4 Vendor Payment & Settlement

**Feature:** Record vendor payments, utilize advance balance  
**Pages:** `/admin/vendor-settlement.php`, `/admin/vendor-payment.php`  
**Priority:** P1  
**Prerequisites:** Vendor exists with due balance  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| VP2.4.1 | **Vendor payment** | Navigate to vendor payment page, select vendor, enter amount, method, submit | Payment recorded, success message | `[DB1]` `SELECT * FROM payments WHERE vendor_id=? AND payment_type='vendor_payment'` → amount matches. `[DB2]` Vendor ledger updated |
| VP2.4.2 | **Advance creation** | Record advance payment | Advance recorded | `[DB]` `vendor_partner_ledger` has `transaction_type='advance'`, credit entry |
| VP2.4.3 | **Advance utilization** | Settle bill using advance balance | Advance deducted from total due | `[DB]` Advance balance decreased, due cleared |

---

### 2.5 Partner Borrow/Return

**Feature:** Borrow cylinders from partner, return with damage tracking  
**Pages:** `/admin/partner-transaction-create.php`  
**Priority:** P1  
**Prerequisites:** Partner exists with active status  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| PB2.5.1 | **Borrow from partner** | Select partner, enter serials, rent rate, borrow date, submit | Transaction created, success | `[DB1]` `SELECT * FROM partner_transactions` → header row. `[DB2]` `SELECT * FROM partner_transaction_items` → line items. `[DB3]` `SELECT status, ownership_type FROM cylinders WHERE id=?` → 'borrowed_from_partner', 'partner_owned' |
| PB2.5.2 | **Return to partner** | Select partner, load borrowed cylinders, process return with calculated rent | Rent calculated, return processed | `[DB1]` Cylinder status → 'returned_to_partner'. `[DB2]` `current_partner_id` → NULL. `[DB3]` Rent payment recorded if applicable |
| PB2.5.3 | **Return with damage** | Add damage amount during return | Damage recorded | `[DB]` Damage amount in transaction items, payment adjusted |

---

### 2.6 Rental Return

**Feature:** Customer returns rented cylinder with rent calculation  
**Pages:** Customer Profile → Return Cylinder modal, or `/admin/rental-return.php`  
**Priority:** P1  
**Prerequisites:** Customer has rented cylinder with `daily_rent_rate`, `free_days`, `borrow_date` set  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| RR2.6.1 | **Basic rental return** | Navigate to customer with rented cylinder, click "Return Cylinder", enter return date (10 days after borrow), condition='empty', no damage | Rent calculation shown: chargeable_days=7, rent_amount=35. Submit processes return | `[DB1]` `SELECT * FROM rental_returns` → correct values. `[DB2]` `SELECT status FROM cylinders WHERE id=?` → 'empty'. `[DB3]` `SELECT * FROM payments WHERE payment_type='rent_payment'` → amount=35 |
| RR2.6.2 | **Rental with damage** | Same but with damage_charge=200 | Total charges = 235 | `[DB]` Payment = 235, damage recorded |
| RR2.6.3 | **Rental with deposit deduction** | Add deduct_from_deposit=100 | deposit_deducted=100, total_collected=135 | `[DB]` `deposit_balance` decreased by 100. Two payment records |
| RR2.6.4 | **Zero free days** | free_days=0, held 5 days, rate=10 | chargeable_days=5, rent=50 | `[DB]` rent_amount=50 |

---

### 2.7 Invoice & Receipt Verification

**Feature:** Generated invoices and deposit receipts have correct data  
**Pages:** `/admin/invoice.php?order_id=X`, `/admin/deposit-receipt.php`  
**Priority:** P1  
**Prerequisites:** Orders and deposits exist  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| IV2.7.1 | **Invoice loads** | After creating order, navigate to invoice.php?order_id=X | 3-copy layout (Shop/Consumer/Police). Amounts match order. Invoice number in correct format. Business name and logo shown. Print layout correct | `[DB]` `SELECT * FROM invoices WHERE refill_order_id=?` → matches |
| IV2.7.2 | **Deposit receipt loads** | Navigate to deposit-receipt.php?receipt_id=X | Receipt number in format DEP-YYYY-NNNN. Amounts match payment. Customer name correct | `[DB]` `SELECT * FROM deposit_receipts WHERE id=?` → matches |

**Common failures:** Missing WhatsApp share button. Incorrect tax calculation. Logo not showing. Date format wrong.

---

### 2.8 Dispatch Settlement

**Feature:** Settle dispatch orders with payment split (cash + advance + deposit)  
**Pages:** `/admin/dispatch-settlement.php`  
**Priority:** P1  
**Prerequisites:** Orders exist with pending/partial payment status  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| DS2.8.1 | **Settlement page loads** | Login, navigate to `/admin/dispatch-settlement.php` | Order listing with filters. Settlement modal with payment split UI | — |
| DS2.8.2 | **Full cash settlement** | Select pending order, enter paid amount = grand_total, submit | Order status → 'paid' | `[DB1]` `SELECT payment_status FROM refill_orders WHERE id=?` → 'paid'. `[DB2]` `SELECT * FROM payments WHERE refill_order_id=?` → amount matches |
| DS2.8.3 | **Settlement with advance** | Customer has advance_balance=1000, order total=5000, enter paid=4000, advance_used=1000 | Advance deducted from balance | `[DB]` Two payment records: 1000 (advance) + 4000 (cash). Customer advance_balance decreased |
| DS2.8.4 | **Settlement with deposit** | Customer has deposit=2000, order=5000, enter paid=3000, deposit_used=2000 | Deposit deducted | `[DB]` `deposit_balance` decreased by 2000. Two payment records |

---

## Phase 3: Customer & Portal (P2)

### 3.1 Customer CRUD

**Feature:** Create, read, update, delete customers  
**Pages:** `/admin/customers.php`, `/admin/customer-profile.php?id=X`  
**Priority:** P2  
**Prerequisites:** Logged in with super_admin or billing_clerk role  
**Connected features:** Orders, Cylinders, Payments, Portal login

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| CR3.1.1 | **Customers list loads** | Navigate to `/admin/customers.php` | Table with headers (ID, Name, Mobile, Email, GST, Deposit, Actions). Pagination controls. Search/filter inputs | `[DB]` `SELECT COUNT(*) FROM customers` → matches displayed count |
| CR3.1.2 | **Create customer** | Click "Register Customer", fill all fields: name, mobile, email, customer_type, gst_number, state_code, city, pincode, address. Submit | Modal closes, success banner. New customer row visible in table | `[DB1]` `SELECT * FROM customers WHERE mobile=?` → all columns match. `[DB2]` `deposit_balance=0`, `active_cylinders_count=0`, `credit_used=0` |
| CR3.1.3 | **Duplicate mobile rejected** | Try creating another customer with same mobile | Error banner: "already exists" or "duplicate" | `[DB]` `SELECT COUNT(*) FROM customers WHERE mobile=?` → 1 (no duplicate) |
| CR3.1.4 | **Search by name** | Type partial name in search field | Table filters to show matching customers | `[DB]` `SELECT * FROM customers WHERE name LIKE '%query%'` → matches |
| CR3.1.5 | **Search by mobile** | Type mobile number in search | Only that customer shown | `[DB]` Exact mobile match |
| CR3.1.6 | **Filter by type** | Select 'rental' from type dropdown | Only rental customers shown | `[DB]` `SELECT * FROM customers WHERE customer_type='rental'` → matches |
| CR3.1.7 | **Edit customer** | Click Edit on a customer row, change name, email, type, city. Submit | Success banner. Table shows updated values | `[DB]` `SELECT name, email, customer_type, city FROM customers WHERE id=?` → all updated |
| CR3.1.8 | **Customer name links to profile** | Click customer name link in table | Navigates to `/admin/customer-profile.php?id=X`. Profile shows customer details, ledger tab, cylinders tab, orders tab | `[DB]` Customer ID in URL matches profile displayed |
| CR3.1.9 | **Customer profile tabs** | Click each tab: Ledger, Cylinders, Orders, Payments, Deposit | Each tab loads data for that customer | `[DB]` `SELECT * FROM refill_orders WHERE customer_id=?` → matches orders tab. `[DB]` `SELECT * FROM cylinders WHERE current_customer_id=?` → matches cylinders tab |
| CR3.1.10 | **Delete customer** | Click Delete, type customer name to confirm, click delete | Customer removed from table. Success banner | `[DB1]` Customer deleted or marked inactive. `[DB2]` Cascade check: `SELECT COUNT(*) FROM cylinders WHERE current_customer_id=?` → 0 (released). `[DB3]` `SELECT COUNT(*) FROM refill_orders WHERE customer_id=?` → 0 |
| CR3.1.11 | **Delete requires name confirmation** | Click Delete, type wrong name, try to submit | Submit button disabled until correct name typed | — |

**Common failures:** Missing columns in customers table that the form references → SQL error. Cascade delete not fully implemented → orphan records. Validation not working.

---

### 3.2 Portal Login & Auth

**Feature:** Customer self-service portal authentication  
**Pages:** `/portal/login.php`, `/portal/logout.php`, `/portal/dashboard.php`  
**Priority:** P2  
**Prerequisites:** Customer exists with `login_enabled=1`, `status='active'`  
**Connected features:** Portal pages, Remember Me, Rate Limiting

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| PL3.2.1 | **Portal login page loads** | Navigate to `/portal/login.php` | Email field, password field, submit button, "Remember Me" checkbox. No admin sidebar (portal has own layout) | — |
| PL3.2.2 | **Valid login** | Fill email=test@test.com, password=test123, click submit | Redirects to `/portal/dashboard.php`. Customer name shown. Stats cards visible | `[DB1]` `$_SESSION['customer_id']` set. `[DB2]` Customer name matches |
| PL3.2.3 | **Wrong password** | Fill correct email, wrong password | Error banner visible. No redirect. Stay on login page | No session created |
| PL3.2.4 | **Remember Me** | Login with remember=1 checked | Cookie `customer_remember` set | `[DB]` `SELECT remember_token FROM customers WHERE email='test@test.com'` → token exists. `password_verify()` of cookie token matches |
| PL3.2.5 | **Session timeout** | Login, wait/force last_activity = 31 min ago, make request | Redirected to login.php | Session destroyed |
| PL3.2.6 | **Logout** | Click logout | Redirect to login. Session cleared. Remember cookie cleared | `[DB]` `remember_token` in DB is still valid (or cleared) |
| PL3.2.7 | **Rate limiting** | Attempt 5 wrong passwords in 60s | 6th attempt: rate limit error | `[DB]` `cache/login_rate_<md5(ip)>` file shows 5+ attempts |

**Common failures:** Remember Me cookie not working. Rate limiting blocks valid users. Session timeout too aggressive.

---

### 3.3 Portal Dashboard

**Feature:** Customer dashboard with stats, recent orders, active cylinders  
**Pages:** `/portal/dashboard.php`  
**Priority:** P2  
**Prerequisites:** Logged in as portal customer  
**Connected features:** Orders, Cylinders, Payments

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| PD3.3.1 | **Dashboard loads** | Login to portal | Stats cards: Active Cylinders count, Recent Orders (max 5), Outstanding Balance, Expiring Cylinders (30 days). Quick action buttons | `[DB1]` `SELECT COUNT(*) FROM cylinders WHERE current_customer_id=?` → matches Active Cylinders. `[DB2]` `SELECT COUNT(*) FROM refill_orders WHERE customer_id=? ORDER BY order_date DESC LIMIT 5` → matches Recent Orders count. `[DB3]` Outstanding balance calculated: `SELECT COALESCE(SUM(grand_total - paid), 0) FROM refill_orders WHERE customer_id=? AND payment_status IN ('pending','partial')` |
| PD3.3.2 | **Active refill services shown** | Login as customer with refill services | Refill pipeline stages visible with status badges | `[DB]` `SELECT * FROM customer_refill_services WHERE customer_id=? AND status NOT IN ('returned_to_customer','cancelled')` |

**Common failures:** Dashboard uses wrong column name (e.g. `g.size_capacity` instead of `g.sizes`). Stats query returns wrong count. Outstanding balance calculation wrong.

---

### 3.4 Portal Orders

**Feature:** Customer order list with filters and detail view  
**Pages:** `/portal/orders.php`, `/portal/order-detail.php?id=X`  
**Priority:** P2  
**Prerequisites:** Customer has orders  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| PO3.4.1 | **Orders list** | Navigate to `/portal/orders.php` | Order table with status badges, dates, amounts. Status filter, date range filter. Invoice links | `[DB]` `SELECT * FROM refill_orders WHERE customer_id=? ORDER BY order_date DESC` → matches list |
| PO3.4.2 | **Order detail** | Click an order | Order items table, payment summary, invoice link | `[DB]` `SELECT * FROM refill_order_items WHERE refill_order_id=?` → matches |
| PO3.4.3 | **Filter by status** | Select 'paid' filter | Only paid orders shown | `[DB]` `WHERE payment_status='paid'` |

---

### 3.5 Portal Cylinders

**Feature:** Customer views their cylinders with status, expiry info  
**Pages:** `/portal/cylinders.php`, `/portal/cylinder-detail.php?id=X`  
**Priority:** P2  
**Prerequisites:** Customer has cylinders assigned  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| PC3.5.1 | **Cylinders list** | Navigate to `/portal/cylinders.php` | Only this customer's cylinders shown. Status filter works. Expiry dates shown | `[DB]` `SELECT * FROM cylinders WHERE current_customer_id=?` → matches |
| PC3.5.2 | **Cylinder detail** | Click a cylinder | Full history timeline with status changes. Transaction log visible | `[DB]` `SELECT * FROM cylinder_transactions WHERE cylinder_id=? ORDER BY transaction_date DESC` → matches timeline |

---

### 3.6 Portal Payments

**Feature:** Customer makes payments, views history  
**Pages:** `/portal/payments.php`, `/portal/make-payment.php`  
**Priority:** P2  
**Prerequisites:** Customer has pending/partial orders  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| PP3.6.1 | **Payment history** | Navigate to `/portal/payments.php` | Payment table with amounts, dates, methods. Outstanding balance shown | `[DB]` `SELECT * FROM payments WHERE customer_id=? ORDER BY payment_date DESC` → matches |
| PP3.6.2 | **Make full payment** | Click "Make Payment", enter amount = outstanding, select method, submit | Confirmation message. Payment recorded | `[DB1]` `SELECT * FROM payments WHERE customer_id=? AND payment_type='refill_payment'` → new row. `[DB2]` `SELECT payment_status FROM refill_orders WHERE id=?` → updated |
| PP3.6.3 | **Make partial payment** | Pay less than outstanding | Partial payment recorded | `[DB]` `payment_status='partial'`, `due_amount` recalculated |

---

### 3.7 Portal Profile

**Feature:** Customer updates profile, changes password  
**Pages:** `/portal/profile.php`  
**Priority:** P2  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| PF3.7.1 | **Profile loads with data** | Navigate to `/portal/profile.php` | Name, email, mobile, address pre-filled from customer record | `[DB]` All fields match DB |
| PF3.7.2 | **Update profile** | Change name, address, submit | Success message. New values shown | `[DB]` `SELECT name, address FROM customers WHERE id=?` → updated |
| PF3.7.3 | **Change password** | Enter current password, new password, confirm, submit | Success | `[DB]` `SELECT password_hash FROM customers WHERE id=?` → new hash. `password_verify('newpassword', hash)` = true |
| PF3.7.4 | **Wrong current password** | Enter wrong current password | Error message | Password hash unchanged |

---

## Phase 4: Platform Admin (P3)

### 4.1 Gas Types CRUD

**Feature:** Admin manages gas types with sizes, prices, refill costs  
**Pages:** `/admin/gas-types.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| GT4.1.1 | **Gas types list** | Navigate to `/admin/gas-types.php` | Table of gas types with name, formula, sizes, prices. Products tab | `[DB]` `SELECT * FROM gas_types ORDER BY name` |
| GT4.1.2 | **Add gas type** | Click "Add Gas Type", fill name, formula, 3 variant rows (size, price, refill cost), submit | New gas type appears in table | `[DB]` `SELECT * FROM gas_types WHERE name=?` → matches. Check `sizes` (CSV), `size_prices` (JSON), `size_refill_costs` (JSON) |
| GT4.1.3 | **Edit gas type** | Click Edit, change price, submit | Updated values shown | `[DB]` Price updated |
| GT4.1.4 | **Add product** | Click Products tab, "Add Product", fill name, sku, unit, gst_rate, submit | Product appears | `[DB]` `SELECT * FROM products` → new product |
| GT4.1.5 | **Gas type delete** | Delete a gas type with no cylinders referencing it | Removed | `[DB]` Gas type deleted |

**Common failures:** `sizes` column stores comma-separated strings (not normalized). JS `.split(',')` fails if delimiter wrong. Prices stored as JSON string, not proper columns.

---

### 4.2 Cylinders List & Filter

**Feature:** Admin views, creates, filters cylinders  
**Pages:** `/admin/cylinders.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| CY4.2.1 | **Cylinders list loads** | Navigate to `/admin/cylinders.php` | Filterable table: status, gas type, size. Pagination. Serial numbers clickable | `[DB]` `SELECT * FROM cylinders ORDER BY id DESC` |
| CY4.2.2 | **Filter by status** | Select 'filled' | Only filled cylinders shown | `[DB]` `WHERE status='filled'` |
| CY4.2.3 | **Filter by gas type** | Select 'Oxygen' | Only Oxygen cylinders | `[DB]` `WHERE gas_type_id = (SELECT id FROM gas_types WHERE name='Oxygen')` |
| CY4.2.4 | **Add cylinder** | Click "Add Cylinder", fill serial, gas type, size, status, submit | New cylinder in list | `[DB]` `SELECT * FROM cylinders WHERE serial_number=?` → matches |

**Common failures:** Barcode duplicate not detected. Serial number not unique. `syncInventory()` not called.

---

### 4.3 Cylinder Track/Detail

**Feature:** Single cylinder tracking with timeline  
**Pages:** `/admin/track-cylinder.php?serial=X`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| TC4.3.1 | **Track cylinder** | Navigate to `/admin/track-cylinder.php?serial=OX-47L-201` | Full timeline of status changes. Current status, owner, dates shown | `[DB]` `SELECT * FROM cylinder_transactions WHERE cylinder_id=? ORDER BY transaction_date` → matches timeline. `[DB]` Current cylinder data matches |
| TC4.3.2 | **Audit log search** | Navigate to `/admin/cylinder-audit-log.php` | Search by serial, customer, vendor, date range | `[DB]` Search queries match |

---

### 4.4 Users & RBAC

**Feature:** Admin user management with role-based access  
**Pages:** `/admin/users-manager.php`  
**Priority:** P3  
**Connected features:** Every admin page

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| US4.4.1 | **Users list** | Navigate to `/admin/users-manager.php` | User table with username, name, role, status | `[DB]` `SELECT * FROM users` |
| US4.4.2 | **Create user** | Add new user with role='billing_clerk', submit | User appears | `[DB]` `SELECT * FROM users WHERE username=?` → bcrypt password hash |
| US4.4.3 | **RBAC: billing_clerk blocked from cylinders** | Login as clerk, try `/admin/cylinders.php` | Redirected or access denied | `[DB]` Role check in session fails |
| US4.4.4 | **RBAC: billing_clerk allowed customers** | Login as clerk, try `/admin/customers.php` | Page loads normally | `[DB]` Role check passes |
| US4.4.5 | **RBAC: viewer blocked from POST** | Login as viewer, try creating a customer | POST handler rejects | No DB change |
| US4.4.6 | **Deactivate user** | Set user status='inactive' | User cannot login | `[DB]` `SELECT status FROM users WHERE username=?` → 'inactive' |

---

### 4.5 Settings Page

**Feature:** General settings, DB status, sync, backup, role simulation  
**Pages:** `/admin/settings.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| ST4.5.1 | **Settings load** | Navigate to `/admin/settings.php` | DB status section, backup button, role simulation dropdown, sync button | — |
| ST4.5.2 | **Sync inventory** | Click "Sync Inventory" | Success message | `[DB]` Run `syncInventory($pdo)` then compare `inventory` table counts with raw cylinder counts → all match |

---

### 4.6 Blog CRUD

**Feature:** Admin manages blog posts  
**Pages:** `/admin/blog-manager.php`, `/admin/add-post.php`  
**Priority:** P3  
**Connected features:** Public blog listing, SEO

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| BL4.6.1 | **Blog list** | Navigate to `/admin/blog-manager.php` | Post list with title, status, date. Create/Edit/Delete buttons | `[DB]` `SELECT * FROM posts ORDER BY created_at DESC` |
| BL4.6.2 | **Create blog post** | Click "Add New Post", fill title, content, excerpt, slug, publish | Post appears in list | `[DB]` `SELECT * FROM posts WHERE slug=?` → matches |
| BL4.6.3 | **Edit blog post** | Edit title, content | Updated | `[DB]` Title updated |
| BL4.6.4 | **Delete blog post** | Delete | Removed | `[DB]` Post deleted |
| BL4.6.5 | **Public blog shows post** | Navigate to `/blog.php` | Post appears in listing | `[DB]` `SELECT * FROM posts WHERE status='published'` |

---

### 4.7 GST Module

**Feature:** GST accounting: input credit, output liability, returns, reconciliation  
**Pages:** All `/admin/gst-*.php` files (20 files)  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| GS4.7.1 | **GST dashboard** | Navigate to `/admin/gst-dashboard.php` | Dashboard with input/output summary, period selector | `[DB]` GST aggregates match SQL queries |
| GS4.7.2 | **GST register** | Navigate to `/admin/gst-register.php` | Register shows input and output entries for selected period | `[DB]` Entries match period |
| GS4.7.3 | **GST return generate** | Navigate to `/admin/gst-return-generate.php`, select period, generate | Return created with summary | `[DB]` `SELECT * FROM gst_returns WHERE period=?` |

---

### 4.8 Expenses

**Feature:** Business expense tracking  
**Pages:** `/admin/expenses.php`, `/admin/expense-create.php`, `/admin/expense-categories.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| EX4.8.1 | **Expenses list** | Navigate to `/admin/expenses.php` | Table with date, category, amount, description, actions | `[DB]` `SELECT * FROM expenses ORDER BY expense_date DESC` |
| EX4.8.2 | **Create expense** | Click "Add Expense", fill category, amount, date, description, submit | Expense appears in list | `[DB]` `SELECT * FROM expenses WHERE description=?` |
| EX4.8.3 | **Expense categories** | Navigate to `/admin/expense-categories.php` | Category list, add/edit/delete | `[DB]` `SELECT * FROM expense_categories` |

---

### 4.9 Reports

**Feature:** Business reports with charts  
**Pages:** `/admin/reports.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| RP4.9.1 | **Reports load** | Login, navigate to `/admin/reports.php` | Date range picker. Chart.js charts render. Summary stats visible. Export buttons | — |
| RP4.9.2 | **Date range filter** | Select date range, click filter | Charts and data update | `[DB]` Data matches filtered date range |

---

## Phase 5: AI Assistant (P3)

### 5.1 AI Assistant Chat

**Feature:** AI chat interface for business queries  
**Pages:** `/admin/ai-assistant.php`, `/admin/ai-chat-api.php`  
**Priority:** P3  
**Prerequisites:** AI configured with API key in settings  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| AI5.1.1 | **Chat UI loads** | Navigate to `/admin/ai-assistant.php` | Chat interface visible. Input field. Send button. Suggestion chips. No JS console errors | `[DB]` `SELECT * FROM ai_config WHERE id=1` → API key present |
| AI5.1.2 | **Send message** | Type "Show inventory", click send | Message appears in chat. Loading indicator. Response received within 30s | `[DB]` `SELECT * FROM ai_conversations ORDER BY created_at DESC` → message + response stored |
| AI5.1.3 | **Customer query** | Type "Find customer test" | Customer data returned (name, mobile, balance) | `[DB]` Query executed correctly |
| AI5.1.4 | **AI not configured warning** | Clear API key from settings, reload | Warning message: "AI Assistant is not configured" | `[DB]` `SELECT api_key FROM ai_config WHERE id=1` → empty |

**Common failures:** API key expired. Rate limiting (30 req/60s). LLM returns malformed JSON → 500 error. SQL validator blocks valid query. Conversation context lost between messages.

---

### 5.2 AI Settings

**Feature:** AI provider configuration (API key, model, language, TTS)  
**Pages:** `/admin/settings-ai.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| AI5.2.1 | **AI settings load** | Navigate to `/admin/settings-ai.php` | Provider selector, API key field (masked), model selector, language mode, TTS settings | `[DB]` `SELECT * FROM ai_config` → current values shown |
| AI5.2.2 | **Save AI config** | Change language mode to 'hi', save | Success message | `[DB]` `SELECT language_mode FROM ai_config` → 'hi' |

---

## Phase 6: Public Site (P3)

### 6.1 Homepage & SEO

**Feature:** Public marketing homepage  
**Pages:** `/index.php`, `/header.php`, `/footer.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| HM6.1.1 | **Homepage loads** | Navigate to `/` | Hero section, product showcase, testimonials, CTA buttons, footer. Nav links visible. No JS errors | — |
| HM6.1.2 | **SEO meta tags** | Check HTML head | Meta description, OG title, OG image, Twitter card, canonical URL, JSON-LD schema all present | — |
| HM6.1.3 | **robots.txt** | Navigate to `/robots.txt` | 200 response, contains "User-agent", references sitemap | — |
| HM6.1.4 | **sitemap.xml** | Navigate to `/sitemap.xml` | Valid XML with `<urlset>`, contains page URLs | `[DB]` Blog posts included in sitemap |

### 6.2 Blog (Public)

**Feature:** Public blog with post listing, single post view  
**Pages:** `/blog.php`, `/post.php?slug=X`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| BL6.2.1 | **Blog listing** | Navigate to `/blog.php` | Post cards with title, excerpt, date, image. Pagination | `[DB]` `SELECT * FROM posts WHERE status='published' ORDER BY created_at DESC` |
| BL6.2.2 | **Single post** | Click a post | Full content, sharing buttons. Related posts section | `[DB]` `SELECT * FROM posts WHERE slug=? AND status='published'` |

---

### 6.3 Lead Capture & Newsletter

**Feature:** Public conversion forms  
**Pages:** `/lead-capture.php`, `/newsletter-subscribe.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| LC6.3.1 | **Lead capture valid** | POST name, email, phone, message | JSON `{"success":true}` | `[DB]` `SELECT * FROM enquiries ORDER BY id DESC LIMIT 1` → data matches |
| LC6.3.2 | **Lead capture invalid phone** | POST phone="12" | JSON `{"success":false}`, error message | No DB insert |
| LC6.3.3 | **Lead capture WhatsApp redirect** | POST with redirect_whatsapp param | JSON has 'redirect' property | — |
| LC6.3.4 | **Newsletter subscribe** | POST email | JSON success | `[DB]` `SELECT * FROM newsletter_subscribers WHERE email=?` |
| LC6.3.5 | **Newsletter duplicate** | POST same email again | Error: "already subscribed" | Still 1 row |
| LC6.3.6 | **Newsletter invalid email** | POST "bad" | Validation error | No insert |

---

### 6.4 Public Tracker

**Feature:** Anyone can track a cylinder by serial  
**Pages:** `/tracker.php`  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| TR6.4.1 | **Tracker loads** | Navigate to `/tracker.php` | Search form with serial input | — |
| TR6.4.2 | **Valid serial** | Enter valid serial number | Cylinder info, status, timeline displayed | `[DB]` `SELECT * FROM cylinders WHERE serial_number=?` matches |
| TR6.4.3 | **Invalid serial** | Enter non-existent serial | Error message, no data leak | — |

---

### 6.5 Landing Pages (SEO)

**Feature:** 11 SEO-optimized product landing pages  
**Pages:** `/oxygen-gas-supplier-khagaria.php`, `/acetylene-gas-supplier-khagaria.php`, etc.  
**Priority:** P3  

| # | Test | Steps | UI Verify | DB Verify |
|---|---|---|---|---|
| LP6.5.1 | **Each LP loads** | Visit each of the 11 landing pages | 200 status. Product content renders. Meta tags present. Contact form visible | — |
| LP6.5.2 | **LP has schema** | Check HTML head | JSON-LD schema with product/business info | — |

---

## Phase 7: Cross-Cutting Verification (P4)

### 7.1 Data Integrity

**Purpose:** Verify database consistency across all features  
**Priority:** P4  
**Run after:** All Phase 1-3 tests complete

| # | Test | SQL Query | Expected | Linked Features |
|---|---|---|---|---|
| DI7.1.1 | **No orphan payments** | `SELECT COUNT(*) FROM payments WHERE customer_id IS NOT NULL AND customer_id NOT IN (SELECT id FROM customers)` | 0 | Payments, Customers |
| DI7.1.2 | **No orphan order items** | `SELECT COUNT(*) FROM refill_order_items WHERE refill_order_id NOT IN (SELECT id FROM refill_orders)` | 0 | Orders |
| DI7.1.3 | **No orphan cylinder transactions** | `SELECT COUNT(*) FROM cylinder_transactions WHERE cylinder_id NOT IN (SELECT id FROM cylinders)` | 0 | Cylinders |
| DI7.1.4 | **Inventory matches cylinders** | Run `syncInventory($pdo)` then compare `inventory.filled_stock` vs `SELECT COUNT(*) FROM cylinders WHERE status='filled' GROUP BY gas_type_id, size_capacity` | All match | Inventory, Cylinders |
| DI7.1.5 | **No orphan ledger groups** | `SELECT COUNT(*) FROM ledger_groups WHERE customer_id NOT IN (SELECT id FROM customers)` | 0 | Ledger, Customers |
| DI7.1.6 | **Invoice numbers sequential** | `SELECT invoice_number FROM invoices ORDER BY id` | No gaps. Format INV-YYYY-NNNN | Invoices |

---

### 7.2 CSRF Coverage

**Purpose:** Ensure every POST handler has CSRF protection  
**Priority:** P4  
**Method:** Scan admin PHP files, not browser tests

| # | Test | Verification |
|---|---|---|
| CS7.2.1 | **Every form has csrfField()** | Scan all admin PHP files, find `<form` tags, verify `csrfField()` present |
| CS7.2.2 | **Every POST handler validates** | Scan all `$_SERVER['REQUEST_METHOD'] === 'POST'` blocks, verify `validateCsrfToken()` called before business logic |
| CS7.2.3 | **Reject missing token** | Send POST without `_csrf_token` → redirect with error |
| CS7.2.4 | **Reject wrong token** | Send POST with invalid `_csrf_token` → redirect with error |

---

### 7.3 RBAC Page Coverage

**Purpose:** Verify each admin page enforces its required role  
**Priority:** P4  

| # | Test | Steps | Expected |
|---|---|---|---|
| RB7.3.1 | **Every page has `require_role()`** | Scan all admin PHP files | Every page (except login.php, logout.php) calls `require_role()` or `require_login()` |
| RB7.3.2 | **Super admin can access all** | Login as super_admin, visit all pages | All render |
| RB7.3.3 | **Billing clerk restricted** | Login as clerk, try warehouse pages | Blocked |
| RB7.3.4 | **Viewer read-only** | Login as viewer, try POST actions | Rejected |

---

### 7.4 i18n Verification

**Purpose:** Ensure translation keys exist in both languages  
**Priority:** P4  

| # | Test | Verification |
|---|---|---|
| I18N7.4.1 | **Key parity en ↔ hi** | Compare keys in `admin/lang/en.php` vs `admin/lang/hi.php` → all keys match |
| I18N7.4.2 | **Hindi rendering** | Set cookie `admin_lang=hi`, load admin page → Hindi text shown |
| I18N7.4.3 | **Fallback for missing key** | Call `__('nonexistent_key')` → returns 'nonexistent_key' |

---

### 7.5 Error Handling

**Purpose:** Verify system handles errors gracefully  
**Priority:** P4  

| # | Test | Steps | Expected |
|---|---|---|---|
| EH7.5.1 | **404 page** | Navigate to `/nonexistent-page` | Custom 404, not server default |
| EH7.5.2 | **Admin error page** | Navigate to invalid admin URL | Custom error page, not blank screen |
| EH7.5.3 | **No PHP errors displayed** | Visit all pages with `display_errors=1` (if possible) | No raw PHP errors on production-facing pages |

---

### 7.6 UI/UX Consistency

**Purpose:** Verify consistent UI patterns across the application  
**Priority:** P4  

| # | Test | Check |
|---|---|---|
| UX7.6.1 | **Flash messages** | Every POST action shows success/error banner |
| UX7.6.2 | **Date inputs** | Flatpickr date picker works on all date fields |
| UX7.6.3 | **Print styles** | Invoice, receipt pages have `@media print` CSS |
| UX7.6.4 | **Responsive** | Admin sidebar collapses on mobile viewport (768px) |

---

## Appendix: Key SQL Queries

### Inventory verification
```sql
-- Sync inventory and compare
CALL syncInventory(); -- or run syncInventory($pdo)

-- Manual comparison
SELECT gas_type_id, size_capacity, status, COUNT(*)
FROM cylinders
GROUP BY gas_type_id, size_capacity, status
ORDER BY gas_type_id, size_capacity;

-- vs inventory table
SELECT * FROM inventory ORDER BY gas_type_id, size_capacity;
```

### Customer deposit balance
```sql
SELECT id, name, deposit_balance, active_cylinders_count, credit_used, credit_limit
FROM customers
WHERE id = ?;
```

### Order status check
```sql
SELECT ro.*,
  (SELECT SUM(amount) FROM payments WHERE refill_order_id = ro.id) AS total_paid
FROM refill_orders ro
WHERE ro.id = ?;
```

### Cylinder audit trail
```sql
SELECT ct.*, c.serial_number, c.status AS current_status
FROM cylinder_transactions ct
JOIN cylinders c ON ct.cylinder_id = c.id
WHERE ct.cylinder_id = ?
ORDER BY ct.transaction_date;
```

### Payment breakdown by order
```sql
SELECT p.*, dr.receipt_number
FROM payments p
LEFT JOIN deposit_receipts dr ON dr.payment_id = p.id
WHERE p.refill_order_id = ?;
```

### RBAC role check pattern (every admin page)
```php
// Look for this pattern in every admin PHP file:
require_role(['super_admin', 'billing_clerk', 'warehouse_supervisor', 'delivery_driver', 'viewer']);
```

---

## Appendix: Template for Adding New Tests

When an AI reads this document to write actual Playwright tests, use this structure:

```javascript
test('TEST-ID: Description', async ({ page }) => {
  // 1. Setup — login, navigate
  await adminLogin(page);
  await page.goto(BASE + '/admin/some-page.php');

  // 2. UI Interact — click, fill, submit
  await page.fill('input[name="field"]', 'value');
  await page.click('button[type="submit"]');

  // 3. Verify UI
  await expect(page.locator('.success-banner')).toBeVisible();

  // 4. Verify DB (via API or direct query)
  const dbResult = await dbAssert(page, { action: 'check_data', params: {...} });
  expect(dbResult.passed).toBe(true);
  expect(dbResult.data.some_field).toBe('expected_value');
});
```

---

## Appendix: Test Results Recording Format

When a test fails, record in this format for AI to later fix:

```json
{
  "test_id": "O1.3.4",
  "feature": "Order Creation - Refill",
  "status": "FAILED",
  "steps_completed": ["Login", "Select customer", "Add item"],
  "step_failed": "Submit order",
  "error": "Redirected to /admin/order-create.php instead of /admin/invoice.php",
  "console_errors": ["Uncaught TypeError: Cannot read property 'value' of null"],
  "db_state": {
    "refill_orders": "no new row",
    "cylinders": "unchanged",
    "inventory": "unchanged"
  },
  "screenshot": "failures/order-creation-fail-001.png",
  "linked_features_affected": ["Cylinders", "Inventory", "Payments", "Invoices"]
}
```

---

*End of E2E Browser Test Plan — Nutan Gases v2.0*
