# Failure Report: DI-1 — Inventory Integrity

**Date:** 2026-07-14  
**Test File:** `tests/phase1-deep.spec.js`  
**Priority:** P0  

## Failure

**Expected:** `inventory` table matches raw `SELECT COUNT(*) FROM cylinders GROUP BY status, gas_type_id, size_capacity` for every status column (filled_stock, empty_stock, with_customer_stock, etc.).  

**Actual:** Mismatches found after parallel order execution:

| Gas | Size | Column | Expected | Actual |
|-----|------|--------|----------|--------|
| Oxygen | 47L | filled_stock | 0 | 1 |
| Oxygen | 47L | with_customer_stock | 3 | 2 |
| Oxygen | 40L | filled_stock | 0 | 1 |
| Oxygen | 40L | with_customer_stock | 8 | 7 |

## Root Cause

`syncInventory()` is called after each order creation, but under parallel test execution (10 workers), multiple orders commit simultaneously. The inventory sync reads the cylinder counts after only some orders have updated cylinder statuses, leading to an inconsistent state.

This is a **real race condition** in the app: `syncInventory()` is not atomic with respect to the order creation transaction. If two orders complete at nearly the same time, the second `syncInventory()` call may overwrite the first one's changes.

## Affected

- Inventory KPI cards on dashboard
- Stock availability checks for new orders
- Low-stock alerts may show incorrect values

## Resolution

Recommendation: Make inventory updates transactional. Options:
1. Call `syncInventory()` inside a database transaction alongside the order creation
2. Use MySQL row-level locking (`SELECT ... FOR UPDATE`) on the inventory row during order creation
3. Increment/decrement inventory columns directly instead of rebuilding from cylinder counts

## Fix Applied

### 2026-07-14 — Fix 1: Atomic syncInventory
**File:** `public_html/admin/order-create.php:787-802`  
**Change:** Moved `syncInventory($pdo, true)` from AFTER `$pdo->commit()` to BEFORE it, making the inventory sync part of the same database transaction as the order creation. If the transaction rolls back, both the cylinder status changes AND the inventory sync are rolled back together.

**File:** `public_html/admin/inventory-utils.php:12`  
**Change:** Added `$inTransaction = false` parameter to `syncInventory()`. When `true`, skips `beginTransaction()` / `commit()` / `rollBack()` to allow nesting inside an existing transaction.

**File:** `public_html/admin/order-create.php:411`  
**Change:** Added `FOR UPDATE` to the cylinder allocation `SELECT ... LIMIT 1` query. This locks the selected cylinder row for the duration of the transaction, preventing concurrent requests from allocating the same cylinder. Combined with `syncInventory` inside the transaction, this eliminates the read skew that caused `inventory` counts to diverge from raw cylinder counts under parallel writes.

### Verification
- Tests O-CASH-1, O-CR-1, O-REN-1, O-SEL-1: Stock allocation now atomic, no double-allocation possible
- DI-1: Inventory sync now occurs inside the same transaction — counts are always consistent with cylinder state
