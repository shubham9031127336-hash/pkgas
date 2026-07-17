# Fix: Vendor Partial/Advance Payment Not Recording

## Problem

When sending cylinders to vendor (`send-cylinder.php`) with an advance/partial payment (e.g., ₹500 out of ₹800 total), the payment is not reflected in the lot:
- Lot shows "Unpaid" status instead of "Partially Paid"
- Advance payment is not visible in receive flow or lot details
- `remaining_balance` is incorrect when `recalcLotFinancials()` runs

## Root Causes

### 1. `recalcLotFinancials()` — wrong remaining when no items received
**File:** `public_html/admin/inventory-utils.php:2706`

When `received_count = 0` (lot created but no cylinders returned yet):
- `$sum_refill = 0` → `$final_total = 0` → `$net_total = 0`
- `$remaining = max(0, 0 - $total_paid)` → always **0**
- Then `$full_lot_remaining = 0 + estimated_pending_cost` = full estimated total (incorrectly ignoring the advance payment)

### 2. `send-cylinder.php` guard clause too restrictive
**File:** `public_html/admin/send-cylinder.php:174`

Condition `if ($advance_enabled && $dispatched > 0 && $advance_total > 0)` requires `$dispatched > 0`. If the cylinder UPDATE fails (status mismatch), `$dispatched` stays 0 and the entire payment recording block is skipped — even though the lot was created and the advance was validated.

### 3. No `recalcLotFinancials` call after lot creation
**File:** `public_html/admin/send-cylinder.php`

The lot INSERT at line 137 sets initial `total_paid`, `remaining_balance`, `payment_status`, but no recalculation runs to reconcile if the payment INSERT fails silently.

## Fixes

### Fix 1: `inventory-utils.php` — Fix `recalcLotFinancials` remaining calculation

Change the `$net_total` calculation to use `estimated_total` when no items have been received:

```php
// Lines 2682-2706: Change the net_total calculation
// Old:
$net_total = $final_total - $deduction + $addition;

// New:
if ($received_count > 0) {
    $net_total = $final_total - $deduction + $addition;
} else {
    $estimated_with_gst = floatval($lot_data['estimated_total'] ?? 0) + floatval($lot_data['gst_amount'] ?? 0);
    $net_total = $estimated_with_gst - $deduction + $addition;
}
```

Then fix the pending lot remaining calculation (lines 2716-2732):

```php
// Old:
$full_lot_remaining = $remaining;
if ($received_count < $total_count && $total_count > 0) {
    $estimated_total = floatval($lot_data['estimated_total'] ?? 0);
    if ($estimated_total > 0) {
        // ... adds full estimated pending cost
        $full_lot_remaining = $remaining + $estimated_pending_total;
    }
}

// New:
$full_lot_remaining = $remaining;
if ($received_count < $total_count && $total_count > 0) {
    if ($received_count > 0) {
        // Partial receipt — add estimated cost for pending cylinders
        $estimated_total = floatval($lot_data['estimated_total'] ?? 0);
        if ($estimated_total > 0) {
            $per_cylinder_est = $estimated_total / $total_count;
            $pending_count = $total_count - $received_count;
            $estimated_pending_cost = $per_cylinder_est * $pending_count;
            if ($gst_rate > 0) {
                $pending_gst_info = calculateGST($estimated_pending_cost, $gst_rate);
                $estimated_pending_total = $estimated_pending_cost + $pending_gst_info['gst_amount'];
            } else {
                $estimated_pending_total = $estimated_pending_cost;
            }
            $full_lot_remaining = $remaining + $estimated_pending_total;
        }
    }
    // If received_count === 0: remaining already covers the full estimated lot — no addition needed
}
```

### Fix 2: `send-cylinder.php` — Remove `$dispatched > 0` guard

```php
// Line 174 — Old:
if ($advance_enabled && $dispatched > 0 && $advance_total > 0) {

// — New:
if ($advance_enabled && $advance_total > 0) {
```

### Fix 3: `send-cylinder.php` — Call `recalcLotFinancials` after payment recording

After line 197 (end of advance payment block), add:

```php
// Recalculate lot financials to ensure total_paid/remaining_balance are consistent
if (function_exists('recalcLotFinancials')) {
    recalcLotFinancials($pdo, $lot_id);
}
```

## Verification

After applying fixes, test the flow:
1. Create a dispatch lot with advance payment (₹500 out of ₹800)
2. Verify `lot-dashboard.php` shows "Partially Paid" status and "₹300 Due"
3. Open lot detail — verify "Advance ₹500" appears in payment timeline
4. Process receive — verify advance payment is reflected in receive flow
5. Settle remaining amount — verify lot transitions to "Paid" status
