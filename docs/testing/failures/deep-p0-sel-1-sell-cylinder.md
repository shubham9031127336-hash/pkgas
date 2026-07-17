# Failure Report: O-SEL-1 — Sell Cylinder

**Date:** 2026-07-14  
**Test File:** `tests/phase1-deep.spec.js`  
**Priority:** P0  

## Failure

**Expected:** After submitting sell-cylinder form, redirect to `invoice.php`.  
**Actual:** Stayed on `order-create.php`. No invoice generated.

## Root Cause

Inventory stock race condition under parallel execution (10 workers).  
Multiple tests (O-CASH-1, O-CR-1, O-REN-1, O-SEL-1) each consume one filled cylinder.  
By the time O-SEL-1's form reached server-side processing, the filled cylinder it selected had already been allocated to another parallel test (O-CASH-1 on Oxygen 47L).  

The order-create form's PHP handler checks `filled_stock` before allocating — when stock hits 0, it rejects the submission and redirects back to `order-create.php` without error message.

## Affected

- Sell cylinder flow (is_rental=2) — cannot sell a cylinder that no longer exists
- Severity: Low under real conditions (sales are sequential, not parallel)

## Resolution

This is a test isolation issue, not an app bug. Fix options:
1. Run stock-sensitive tests serially
2. Use dedicated, unique stock per test via test fixtures
3. Accept skip behavior when stock is unavailable under parallel load

## Fix Applied

### 2026-07-14 — Fix: Atomic cylinder allocation
**File:** `public_html/admin/order-create.php:411` and `public_html/admin/order-create.php:396`  
**Change:** Added `FOR UPDATE` to both cylinder allocation SELECT queries. The auto-select path (line 411) and user-chosen cylinder path (line 396) now lock the selected row, preventing concurrent requests from allocating the same cylinder. Under parallel tests, different workers will see different available cylinders (or get `Insufficient stock` error gracefully rather than silently failing).
