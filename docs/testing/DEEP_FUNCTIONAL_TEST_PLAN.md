# Deep Functional Integration Test Plan — Nutan Gases

**Version:** 2.0  
**Date:** 14 July 2026  
**Purpose:** Verify every business operation by asserting all database side effects — orders, cylinders, inventory, customers, payments, invoices, ledger, GST, portal, expenses, suppliers, users, settings.  
**Scope:** Active business + platform admin flows. Does not cover static pages, AI, blog, or landing pages (those are in `COMPREHENSIVE_TEST_PLAN.md`).

<!-- test-progress
{
  "last_updated": "2026-07-14",
  "phases": {
    "deep_p0": {"label": "Core Money Flows (P0)", "status": "complete", "tests": 9, "passed": 8, "failed": 0, "skipped": 1, "last_run": "2026-07-14"},
    "deep_p1": {"label": "Supporting Transactions (P1)", "status": "in_progress", "tests": 7, "passed": 5, "failed": 0, "skipped": 2, "last_run": "2026-07-14"},
    "deep_p2": {"label": "Customer & Portal (P2)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null}
  }
}
-->

---

## How to Use

Each test specifies:
1. **Entry criteria** — data state required before running
2. **Execution steps** — actions to perform in the browser
3. **Side-effect verification matrix** — every table/column that must change, with expected values
4. **Integrity checks** — cross-feature consistency (e.g., inventory matches raw cylinder count)

Use `tests/helpers/deep-assert.js` helper functions:
- `deepVerify(page, checks)` — runs all assertions in one batched DB call
- `createOrder(page, data)` — creates order, returns `{order_id, invoice_number}`
- `getCylinderState(page, serial)` — returns full cylinder + transaction history
- `getCustomerState(page, id)` — returns financial profile

---

## Priority P0 — Core Money Flows

### O-CASH: Refill Order — Cash Payment

#### O-CASH-1: Single cylinder, ₹500, Cash
| # | Check | SQL/Expected |
|---|-------|-------------|
| EC | Filled oxygen 47L exists | `SELECT COUNT(*) FROM cylinders WHERE gas_type_id=(SELECT id FROM gas_types WHERE name LIKE '%Oxygen%') AND size_capacity='47L' AND status='filled'` > 0 |
| EC | Customer X has `active_cylinders_count=N` | `SELECT active_cylinders_count FROM customers WHERE id=?` |
| 1 | Order created | `SELECT id, grand_total, payment_status, payment_method, subtotal, tax_amount, discount, invoice_number, invoice_date, business_name, vehicle_number, is_credit_order, gst_rate FROM refill_orders WHERE customer_id=? ORDER BY id DESC LIMIT 1` → `grand_total=500, payment_status='paid', payment_method='Cash', is_credit_order=0` |
| 2 | Invoice number format | `invoice_number` matches `/^INV-2026-\d{4}$/` |
| 3 | Order items | `SELECT gas_type_id, cylinder_id, size_capacity, qty, price_per_unit, is_rental, gst_rate, taxable_amount, gst_amount, cgst, sgst FROM refill_order_items WHERE refill_order_id=?` → `qty=1, price_per_unit=500.00, is_rental=0` |
| 4 | Issued cylinder status | `SELECT id, serial_number, status, current_customer_id, daily_rent_rate, free_days, borrow_date, last_refill_date FROM cylinders WHERE id=?` → `status='with_customer', current_customer_id=X, borrow_date IS NOT NULL` |
| 5 | Cylinder transaction | `SELECT cylinder_id, customer_id, vendor_id, transaction_type FROM cylinder_transactions WHERE cylinder_id=? AND transaction_type='issue_to_customer'` → `customer_id=X` |
| 6 | Payment recorded | `SELECT id, amount, payment_method, payment_type FROM payments WHERE refill_order_id=? AND customer_id=?` → `amount=500.00, payment_method='Cash', payment_type='refill_payment'` |
| 7 | Customer active cylinders | `SELECT active_cylinders_count FROM customers WHERE id=?` → `N+1` |
| 8 | Inventory: filled decreased | `SELECT filled_stock FROM inventory WHERE gas_type_id=? AND size_capacity='47L'` → decreased by 1 vs baseline |
| 9 | Inventory: with_customer increased | `SELECT with_customer_stock FROM inventory WHERE gas_type_id=? AND size_capacity='47L'` → increased by 1 vs baseline |
| 10 | Inventory integrity | Run `syncInventory($pdo)` then compare `inventory.*_stock` columns with `SELECT COUNT(*) FROM cylinders WHERE status=? AND gas_type_id=? AND size_capacity=?` for each status → all match |
| 11 | GST output entry | `SELECT id, gst_rate, taxable_amount, gst_amount, cgst, sgst, input_output_type, reference_type, reference_id FROM gst_ledger WHERE reference_type='refill_order' AND reference_id=?` → `input_output_type='output'` |
| 12 | Portal: dashboard reflects | Login as customer → Active cylinders count matches DB, Recent orders includes this order |

#### O-CASH-2: Multi-cylinder (₹1200, 2× Nitrogen 20L)
Same as O-CASH-1 but verify:
- 2 `refill_order_items` rows, each `qty=1`
- 2 cylinders updated to `with_customer`
- 2 `cylinder_transactions` rows
- `grand_total = 1200`
- `active_cylinders_count` += 2

#### O-CASH-3: Custom price override (₹550 instead of ₹500)
- `refill_order_items.price_per_unit = 550.00`
- `refill_orders.grand_total` = 550 + tax

#### O-CASH-4: With cylinder exchange return
| # | Check | Expected |
|---|-------|----------|
| EC | Customer holds 1 company cylinder | `SELECT id, serial_number, status, current_customer_id FROM cylinders WHERE current_customer_id=X AND status='with_customer' AND ownership_type IN ('owned','partner_owned')` ≥ 1 |
| 1 | Returned cylinder: status=empty, customer_id=NULL | `SELECT status, current_customer_id FROM cylinders WHERE id=?` → `status='empty', current_customer_id=NULL` |
| 2 | Return transaction | `SELECT transaction_type FROM cylinder_transactions WHERE cylinder_id=? AND transaction_type='return_from_customer'` → row exists |
| 3 | Issued cylinder: with_customer | Same as O-CASH-1#4 |
| 4 | Issue transaction | Same as O-CASH-1#5 |
| 5 | Customer active cylinders | Net change: 0 (1 returned - 1 issued). `SELECT active_cylinders_count FROM customers WHERE id=?` → unchanged |
| 6 | Inventory: filled-- | Same as O-CASH-1#8 |
| 7 | Inventory: empty++ | `empty_stock` increased by 1 for returned cylinder's gas/size |
| 8 | Inventory: with_customer unchanged | Net: 1 issued - 1 returned = net 0 change |

#### O-CASH-5: With consumer-owned cylinder return
| # | Check | Expected |
|---|-------|----------|
| EC | Customer has CON cylinder in our inventory | `SELECT id, serial_number FROM cylinders WHERE original_owner_customer_id=X AND ownership_type='consumer_owned' AND status NOT IN ('returned_to_consumer')` ≥ 1 |
| 1 | CON cylinder: `returned_to_consumer`, customer_id=NULL | `SELECT status, current_customer_id, current_vendor_id FROM cylinders WHERE id=?` → `status='returned_to_consumer', current_customer_id=NULL, current_vendor_id=NULL` |
| 2 | Transaction: `consumer_give_back` | `SELECT transaction_type, customer_id, notes FROM cylinder_transactions WHERE cylinder_id=? AND transaction_type='consumer_give_back'` → row exists |
| 3 | No `active_cylinders_count` change (CON give-back doesn't count) | Net: 1 issued - 0 returned (CON cylinders not counted). `active_cylinders_count` += 1 |

#### O-CASH-6: B2B GST at 18%
| # | Check | Expected |
|---|-------|----------|
| EC | Customer has valid GSTIN | `SELECT gst_number FROM customers WHERE id=?` → not empty |
| 1 | Order GST rate | `refill_orders.gst_rate = 18.00` |
| 2 | Tax calculation | `tax_amount = round(subtotal × 18 / 118, 2)` |
| 3 | CGST/SGST split | `cgst = tax_amount/2`, `sgst = tax_amount/2` |
| 4 | GST ledger | `gst_ledger.gst_rate = 18, cgst = sgst = half, input_output_type='output'` |

#### O-CASH-7: With driver/vehicle info
| # | Check | Expected |
|---|-------|----------|
| 1 | Vehicle number stored | `refill_orders.vehicle_number = 'WB-1234'` (or whatever entered) |

---

### O-CR: Refill Order — Credit Payment

#### O-CR-1: ₹8000 credit, customer has ₹10000 limit
| # | Check | Expected |
|---|-------|----------|
| EC | Customer credit_limit ≥ 8000 | `SELECT credit_limit, credit_used, credit_status FROM customers WHERE id=?` → `credit_limit >= 8000` |
| 1 | Order payment_status = 'pending' | `SELECT payment_status, is_credit_order FROM refill_orders WHERE id=?` → `'pending', 1` |
| 2 | No cash payment row | `SELECT COUNT(*) FROM payments WHERE refill_order_id=? AND payment_type='refill_payment'` → 0 |
| 3 | Customer credit_used incremented | `SELECT credit_used FROM customers WHERE id=?` → previous + 8000 |
| 4 | Credit transaction | `SELECT id, customer_id, amount, transaction_type, reference_type, reference_id FROM credit_transactions WHERE customer_id=? ORDER BY id DESC LIMIT 1` → `amount=8000, transaction_type='charge'` |
| 5 | Credit status = 'good' | Since 8000/10000 = 80% (exactly at threshold). `SELECT credit_status FROM customers WHERE id=?` → 'good' or 'warning' depending on threshold logic |
| 6 | Cylinders issued normally | Same cylinder lifecycle as cash: status='with_customer', issue_to_customer txn, active_cylinders_count++ |

#### O-CR-2: Credit at 85% → warning
| # | Check | Expected |
|---|-------|----------|
| 1 | Customer credit_status = 'warning' | `SELECT credit_status FROM customers WHERE id=?` → 'warning' |

#### O-CR-3: Credit at 100% → blocked
| # | Check | Expected |
|---|-------|----------|
| 1 | Customer credit_status = 'blocked' | `SELECT credit_status FROM customers WHERE id=?` → 'blocked' |

#### O-CR-4: Credit with deposit deduction
| # | Check | Expected |
|---|-------|----------|
| EC | Customer has deposit_balance > 0 | `SELECT deposit_balance FROM customers WHERE id=?` > 0 |
| 1 | Deposit balance decreased | `deposit_balance` = previous - min(deposit, grand_total) |
| 2 | Payment row for deposit | `SELECT amount, payment_type FROM payments WHERE refill_order_id=? AND payment_type='deposit_refunded'` → amount = deposit used |
| 3 | Remaining goes to credit | `credit_used` incremented by `grand_total - deposit_used` |

---

### O-REN: Rental Order

#### O-REN-1: ₹15/day, 3 free days
| # | Check | Expected |
|---|-------|----------|
| 1 | Order item | `SELECT is_rental, rent_per_day, free_days, deposit_amount FROM refill_order_items WHERE refill_order_id=? AND gas_type_id=?` → `is_rental=1, rent_per_day=15.00, free_days=3` |
| 2 | Cylinder rental fields | `SELECT daily_rent_rate, free_days, borrow_date FROM cylinders WHERE id=?` → `daily_rent_rate=15.00, free_days=3, borrow_date IS NOT NULL` |
| 3 | Invoice shows rental | Invoice page has "Rent Per Day: ₹15.00", "Free Days: 3" visible |

#### O-REN-2: Multiple rental cylinders
Each cylinder gets its own `daily_rent_rate`, same `borrow_date`. Verify all separately.

#### O-REN-3: Zero free days
`free_days=0` → all days chargeable.

#### O-REN-4: With deposit collected
`deposit_balance` increased by deposit amount. `payments` row with `payment_type='deposit_added'`.

---

### O-SEL: Sell Cylinder

#### O-SEL-1: Sell at ₹2000
| # | Check | Expected |
|---|-------|----------|
| 1 | Cylinder status | `SELECT status FROM cylinders WHERE id=?` → 'sold' or cylinder removed/archived |
| 2 | Order item | `SELECT sell_price, sold_cylinder_serial FROM refill_order_items WHERE refill_order_id=?` → `sell_price=2000.00, sold_cylinder_serial` = chosen serial |
| 3 | Inventory: cylinder removed | `filled_stock` decreased, cylinder no longer in any status-based count |
| 4 | Sold cylinder not re-usable | Cannot be selected in future order-create dropdown |

---

### O-PRO: Sell Product

#### O-PRO-1: Sell 2 regulators at ₹300
| # | Check | Expected |
|---|-------|----------|
| 1 | Order item has product | `SELECT product_id, product_qty FROM refill_order_items WHERE refill_order_id=? AND product_id IS NOT NULL` → `product_qty=2` |
| 2 | Product stock decremented | `SELECT stock_quantity FROM products WHERE id=?` → previous - 2 |

---

### O-CRS: Customer Refill Service

#### O-CRS-1: Customer brings own cylinder
| # | Check | Expected |
|---|-------|----------|
| 1 | Refill service record | `SELECT id, customer_id, cylinder_serial, gas_type, status FROM customer_refill_services WHERE customer_id=? ORDER BY id DESC LIMIT 1` → `status='received'` |
| 2 | New cylinder if new serial | `SELECT id, serial_number, ownership_type, original_owner_customer_id, status FROM cylinders WHERE serial_number=?` → `ownership_type='consumer_owned', original_owner_customer_id=X, status='empty'` |

---

### EX: Cylinder Exchange Settlement

#### EX-1: Return 2 company cylinders
| # | Check | Expected |
|---|-------|----------|
| EC | Customer has ≥2 company cylinders | `SELECT COUNT(*) FROM cylinders WHERE current_customer_id=X AND status='with_customer' AND ownership_type='owned'` ≥ 2 |
| 1 | Both cylinders: status=empty, customer_id=NULL | `SELECT status, current_customer_id FROM cylinders WHERE id IN (?,?)` → both `'empty'`, both `NULL` |
| 2 | Return transactions | `SELECT COUNT(*) FROM cylinder_transactions WHERE cylinder_id IN (?,?) AND transaction_type='return_from_customer'` → 2 |
| 3 | Customer active_cylinders_count -= 2 | `SELECT active_cylinders_count FROM customers WHERE id=?` → previous - 2 |
| 4 | Ledger group created | `SELECT id, customer_id, group_type, total_amount FROM ledger_groups WHERE customer_id=? AND group_type='exchange_settlement' ORDER BY entry_date DESC LIMIT 1` → `total_amount=0.00` |

#### EX-2: Give back 1 consumer-owned cylinder
| # | Check | Expected |
|---|-------|----------|
| 1 | CON cylinder: `returned_to_consumer` | `SELECT status, current_customer_id FROM cylinders WHERE id=?` → `'returned_to_consumer', NULL` |
| 2 | Transaction: `consumer_give_back` | `SELECT transaction_type FROM cylinder_transactions WHERE cylinder_id=? AND transaction_type='consumer_give_back'` |

#### EX-3: Return with ₹500 damage
| # | Check | Expected |
|---|-------|----------|
| 1 | Damage in transaction notes | `SELECT notes FROM cylinder_transactions WHERE cylinder_id=? AND transaction_type='return_from_customer' ORDER BY id DESC LIMIT 1` → contains `[Damage: ₹500` |
| 2 | Ledger group has damage | `SELECT total_amount FROM ledger_groups WHERE customer_id=? AND group_type='exchange_settlement' ORDER BY entry_date DESC LIMIT 1` → `500.00` |

#### EX-5: Empty serial validation
Submit with no serials → error message displayed, no new `ledger_groups` rows.

---

## Priority P1 — Supporting Transactions

### SV: Send to Vendor

#### SV-1: Dispatch 2 empty cylinders, no advance
| # | Check | Expected |
|---|-------|----------|
| EC | 2 empty cylinders exist | `SELECT COUNT(*) FROM cylinders WHERE status='empty'` ≥ 2 |
| EC | Vendor exists | `SELECT id, name FROM vendors WHERE id=?` |
| 1 | Cylinders: status='sent_to_vendor', vendor_id set | `SELECT status, current_vendor_id FROM cylinders WHERE id IN (?,?)` → both `'sent_to_vendor'`, both `current_vendor_id=V` |
| 2 | Dispatch lot created | `SELECT id, lot_number, vendor_id, driver_name, vehicle_number, dispatch_date, lot_status, cylinder_count, returned_count, estimated_total, payment_status FROM dispatch_lots WHERE vendor_id=? ORDER BY id DESC LIMIT 1` → `lot_status='open', cylinder_count=2, returned_count=0, payment_status='unpaid'` |
| 3 | Lot number format | `lot_number` matches `/^LOT-\d{8}-\d{3}$/` |
| 4 | Lot items | `SELECT COUNT(*), dispatch_status FROM dispatch_lot_items WHERE lot_id=?` → 2 rows, both `dispatch_status='dispatched'` |
| 5 | Cylinder transactions | `SELECT COUNT(*), transaction_type FROM cylinder_transactions WHERE cylinder_id IN (?,?) AND transaction_type='send_to_vendor'` → 2 rows |
| 6 | Vendor active_refill_count incremented | `SELECT active_refill_count FROM vendors WHERE id=?` → previous + 2 |

#### SV-2: Dispatch with ₹2500 advance + ₹500 transport
Includes all SV-1 checks plus:
| # | Check | Expected |
|---|-------|----------|
| 1 | Payment for advance | `SELECT id, amount, payment_method, payment_type FROM payments WHERE lot_id=?` → `amount=2500.00, payment_type='vendor_payment'` |
| 2 | Vendor ledger: advance entry | `SELECT transaction_type, credit, advance_balance, running_balance FROM vendor_partner_ledger WHERE entity_type='vendor' AND entity_id=? AND transaction_type='advance'` → `credit=2500, advance_balance=2500` |
| 3 | Transport expense | `SELECT id, amount, reference_type, reference_id, payment_status FROM expenses WHERE reference_type='dispatch_lot' AND reference_id=?` → `amount=500.00, payment_status='paid'` |
| 4 | Lot payment_status = 'partial' | `SELECT payment_status FROM dispatch_lots WHERE id=?` → 'partial' |

#### SV-3: Transport cost per-cylinder
Each `dispatch_lot_item.dispatch_transport_cost` = 250.00 (500/2).

---

### RV: Receive from Vendor

#### RV-1: Full receive 2 cylinders, no transport
| # | Check | Expected |
|---|-------|----------|
| EC | Vendor has open lot with 2 dispatched cylinders | `SELECT dl.id, dli.cylinder_id FROM dispatch_lots dl JOIN dispatch_lot_items dli ON dl.id=dli.lot_id WHERE dl.vendor_id=? AND dl.lot_status='open' AND dli.dispatch_status='dispatched'` ≥ 2 |
| 1 | Cylinders: status='filled', vendor_id=NULL, refill cost set | `SELECT status, current_vendor_id, current_refill_cost, last_refill_vendor_id, last_refill_lot_id FROM cylinders WHERE id IN (?,?)` → `status='filled', current_vendor_id=NULL, current_refill_cost>0, last_refill_vendor_id=V, last_refill_lot_id=L` |
| 2 | Lot items: status='received' | `SELECT dispatch_status, receive_date, refill_cost FROM dispatch_lot_items WHERE lot_id=? AND cylinder_id IN (?,?)` → `dispatch_status='received', receive_date IS NOT NULL, refill_cost>0` |
| 3 | Lot: returned_count updated | `SELECT returned_count, receive_date, receive_transport_total FROM dispatch_lots WHERE id=?` → `returned_count=2, receive_date IS NOT NULL` |
| 4 | Due ledger entry | `SELECT transaction_type, credit, due_balance, running_balance, settlement_status FROM vendor_partner_ledger WHERE entity_type='vendor' AND entity_id=? AND transaction_type='due_created'` → `credit = refill_total, due_balance = refill_total` |
| 5 | Vendor invoice auto-created | `SELECT id, invoice_number, vendor_id, lot_id, grand_total, payment_status FROM vendor_invoices WHERE lot_id=?` → `payment_status='unpaid'` |
| 6 | Vendor invoice items | `SELECT COUNT(*) FROM vendor_invoice_items WHERE invoice_id=?` → ≥ 1 |
| 7 | GST input entry | `SELECT input_output_type FROM gst_ledger WHERE reference_type='vendor_invoice' AND reference_id=?` → `'input'` |
| 8 | Lot payment_status = 'unpaid' or 'partial' | `SELECT payment_status FROM dispatch_lots WHERE id=?` → 'unpaid' (no payment made yet) |

#### RV-2: With transport ₹300 + GST
Adds transport expense check (same pattern as SV-2#3) and `receive_transport_total=300` on lot.

#### RV-3: Advance utilization
| # | Check | Expected |
|---|-------|----------|
| 1 | Advance utilized ledger | `SELECT transaction_type, debit, advance_balance, due_balance FROM vendor_partner_ledger WHERE entity_type='vendor' AND entity_id=? AND transaction_type='advance_utilized'` → `debit = utilized_amount, advance_balance decreased, due_balance decreased` |
| 2 | Payment for advance use | `SELECT amount, payment_method, payment_subtype FROM payments WHERE lot_id=? AND payment_subtype='advance_utilized'` → `method='Advance'` |

#### RV-4: Payment settlement
| # | Check | Expected |
|---|-------|----------|
| 1 | Settlement payment | `SELECT amount, payment_method, payment_type, payment_subtype FROM payments WHERE lot_id=? AND payment_subtype='settlement'` |
| 2 | Payment ledger | `SELECT transaction_type, credit, due_balance, settlement_status FROM vendor_partner_ledger WHERE entity_type='vendor' AND entity_id=? AND transaction_type='payment'` → `settlement_status='settled'` |
| 3 | Lot payment_status = 'paid' | `SELECT payment_status FROM dispatch_lots WHERE id=?` → 'paid' |

#### RV-6: Partial receive (1 of 2 cylinders)
| # | Check | Expected |
|---|-------|----------|
| 1 | Lot status = 'partial_return' | `SELECT lot_status FROM dispatch_lots WHERE id=?` → 'partial_return' |
| 2 | Only 1 cylinder status changed | 1 cylinder = 'filled', 1 still = 'sent_to_vendor' |

---

### PB: Partner Borrow/Return

#### PB-1: Borrow 3 cylinders from partner
| # | Check | Expected |
|---|-------|----------|
| 1 | Cylinders: `borrowed_from_partner`, `current_partner_id` set | `SELECT status, current_partner_id, ownership_type FROM cylinders WHERE id IN (?,?,?)` → `status='borrowed_from_partner', current_partner_id=P, ownership_type='partner_owned'` |
| 2 | Partner transaction header | `SELECT id, partner_id, transaction_type FROM partner_transactions WHERE partner_id=? ORDER BY id DESC LIMIT 1` → `transaction_type='borrowed_from_partner'` |
| 3 | Partner transaction items | `SELECT COUNT(*), damage_amount, payment_status FROM partner_transaction_items WHERE transaction_id=?` → 3 rows, `payment_status='pending'` |

#### PB-2: Return all 3 after 10 days
| # | Check | Expected |
|---|-------|----------|
| 1 | Cylinders: `returned_to_partner`, partner_id=NULL | `SELECT status, current_partner_id FROM cylinders WHERE id IN (?,?,?)` → `'returned_to_partner', NULL` |
| 2 | Header: `returned_to_partner` | New partner_transactions row with `transaction_type='returned_to_partner'` |
| 3 | Rent calculated | Rent = (days × rate) for each cylinder |

---

### RR: Rental Return

#### RR-1: 10 days held, 3 free, ₹15/day → ₹105
| # | Check | Expected |
|---|-------|----------|
| EC | Customer has rented cylinder with borrow_date=10 days ago, rate=15, free=3 | `SELECT id, daily_rent_rate, free_days, borrow_date FROM cylinders WHERE id=?` → `borrow_date = date - 10` |
| 1 | Rental return record | `SELECT id, cylinder_id, customer_id, borrow_date, return_date, chargeable_days, free_days, daily_rate, rent_amount, condition_status, damage_charge, deposit_deducted, total_collected FROM rental_returns WHERE cylinder_id=?` → `chargeable_days=7, free_days=3, daily_rate=15.00, rent_amount=105.00, condition_status='empty', damage_charge=0.00, deposit_deducted=0.00, total_collected=105.00` |
| 2 | Cylinder status = 'empty' | `SELECT status FROM cylinders WHERE id=?` → 'empty' |
| 3 | Rent payment | `SELECT id, amount, payment_type FROM payments WHERE payment_type='rent_payment' AND refill_order_id=?` → `amount=105.00` |

#### RR-2: With ₹200 damage
`damage_charge=200.00, total_collected=305.00`

#### RR-3: With ₹100 deposit deduction
`deposit_deducted=100.00, total_collected=205.00`. Two payment records: 105 rent + (205-105) balance.

---

### DS: Dispatch Settlement

#### DS-1: Full cash settlement ₹5000
| # | Check | Expected |
|---|-------|----------|
| EC | Order exists with payment_status='pending' or 'partial' | `SELECT id, grand_total, paid, payment_status FROM refill_orders WHERE customer_id=? AND payment_status IN ('pending','partial') LIMIT 1` |
| 1 | Payment recorded | `SELECT SUM(amount) FROM payments WHERE refill_order_id=? AND payment_type='refill_payment'` = grand_total |
| 2 | Order status = 'paid' | `SELECT payment_status FROM refill_orders WHERE id=?` → 'paid' |

#### DS-2: ₹4000 cash + ₹1000 advance
2 payments. Customer `advance_balance` decreased by 1000.

#### DS-3: ₹3000 cash + ₹2000 deposit
2 payments. Customer `deposit_balance` decreased by 2000.

---

## Priority P2 — Customer & Portal

### CR: Customer CRUD

#### CR-1: Create customer
| # | Check | Expected |
|---|-------|----------|
| 1 | All fields match | `SELECT name, mobile, email, customer_type, gst_number, state_code, city, pincode, registration_type, address, deposit_balance, active_cylinders_count, credit_used FROM customers WHERE mobile=?` → all fields match input, `deposit=0, active_cylinders=0, credit_used=0` |

#### CR-4: Delete — cascade check
| # | Check | Expected |
|---|-------|----------|
| 1 | Customer deleted | `SELECT COUNT(*) FROM customers WHERE id=?` → 0 |
| 2 | Cylinders released | `SELECT COUNT(*) FROM cylinders WHERE current_customer_id=?` → 0 |
| 3 | Old orders remain | `SELECT COUNT(*) FROM refill_orders WHERE customer_id=?` → 0 (or customer_id=NULL) |
| 4 | Old payments remain | `SELECT COUNT(*) FROM payments WHERE customer_id=?` → 0 (or customer_id=NULL) |

#### CR-6: Customer profile tabs
Each tab: Ledger, Cylinders, Orders, Payments, Deposit — return filtered data for that customer only.

---

### PL: Portal Auth & Sessions

#### PL-1: Valid login
| # | Check | Expected |
|---|-------|----------|
| 1 | Session created | `$_SESSION['customer_id']` = customer id |
| 2 | Redirect to dashboard | URL contains `/portal/dashboard.php` |

#### PL-3: Remember Me cookie
| # | Check | Expected |
|---|-------|----------|
| 1 | Cookie set | `customer_remember` cookie exists |
| 2 | Token in DB | `SELECT remember_token FROM customers WHERE email='test@test.com'` → not empty |
| 3 | Cookie token verifies | `password_verify(cookie_value, db_hash)` = true |

---

### PD: Portal Dashboard

#### PD-1: Active cylinders match
UI count = `SELECT COUNT(*) FROM cylinders WHERE current_customer_id=?`

#### PD-2: Recent orders match
UI list = top 5 `SELECT id, order_number, grand_total, payment_status, order_date FROM refill_orders WHERE customer_id=? ORDER BY order_date DESC LIMIT 5`

#### PD-3: Outstanding balance
UI amount = `SELECT COALESCE(SUM(grand_total - paid), 0) FROM refill_orders WHERE customer_id=? AND payment_status IN ('pending','partial')`

---

### PO: Portal Orders
Verify order list = all customer orders. Detail page items match `refill_order_items`. Status filter applies correct SQL `WHERE` clause.

### PC: Portal Cylinders
List = only `current_customer_id=?`. Detail timeline = `cylinder_transactions ORDER BY transaction_date`.

### PP: Portal Payments
Payment history = `SELECT * FROM payments WHERE customer_id=?`. Full payment sets `payment_status='paid'`. Partial sets `'partial'`.

### PF: Portal Profile
Profile fields match DB. Update persists. Password change creates valid `password_verify()`.

---

### PL-RS: Portal Refill Services

#### PL-RS-1: Refill services list
| # | Check | Expected |
|---|-------|----------|
| EC | Customer has refill services with various statuses | `SELECT COUNT(*) FROM customer_refill_services WHERE customer_id=?` ≥ 2 |
| 1 | List loads with status labels | `SELECT id, cylinder_serial, gas_type, status, created_at FROM customer_refill_services WHERE customer_id=? ORDER BY created_at DESC` → UI shows status badges matching: 'received', 'at_plant', 'sent_to_refiller', 'filled', 'returned_to_warehouse', 'returned_to_customer', 'cancelled' |
| 2 | Status filter works | Selecting "At Plant" filter → `WHERE status='at_plant'` matches UI rows |

#### PL-RS-2: Refill service detail
| # | Check | Expected |
|---|-------|----------|
| 1 | Status timeline rendered | `SELECT id, cylinder_serial, gas_type, status, status_history, cylinder_id, vendor_id FROM customer_refill_services WHERE id=?` → `status_history` JSON (or stage log) shows each transition with dates |
| 2 | Cylinder info | `SELECT serial_number, status, current_vendor_id FROM cylinders WHERE id=?` → vendor name resolved if sent_to_vendor |
| 3 | Invoice reference | `SELECT refill_order_id FROM customer_refill_services WHERE id=?` → order exists, invoice link present |

---

### PL-INV: Portal Invoice & PDF

#### PL-INV-1: Portal invoice view
| # | Check | Expected |
|---|-------|----------|
| EC | Customer has paid order | `SELECT id, invoice_number FROM refill_orders WHERE customer_id=? AND payment_status='paid' LIMIT 1` |
| 1 | Invoice loads | Navigate to `/portal/invoice.php?order_id=X` → order belongs to this customer | `SELECT id, grand_total, subtotal, tax_amount, discount, gst_rate, business_name FROM refill_orders WHERE id=?` → matches UI |
| 2 | Items table | `SELECT gas_type_id, size_capacity, qty, price_per_unit, taxable_amount, gst_amount FROM refill_order_items WHERE refill_order_id=?` → all items shown |
| 3 | GST split shown | `cgst`, `sgst` displayed correctly as `tax_amount/2` |

#### PL-INV-2: Invoice PDF download
| # | Check | Expected |
|---|-------|----------|
| 1 | PDF token access | Navigate to `/portal/invoice-pdf.php?order_id=X&token=Y` → valid token allows download, invalid token returns error |
| 2 | PDF contains invoice data | Downloaded PDF (can check `Content-Type: application/pdf` header, file size > 1KB) |

---

### PL-EMAIL: Portal Email

#### PL-EMAIL-1: Order confirmation email sent
| # | Check | Expected |
|---|-------|----------|
| 1 | Email sent on order | After creating an order, verify `mail()` or PHPMailer was invoked (check `email_log` table if exists, or SMTP log) |
| 2 | Email contains order details | Email body contains invoice number, grand total, order items |

---

## Priority P3 — Platform Admin & GST

### GST: Full GST Compliance System

#### GST-1: GST Dashboard loads with correct summaries
| # | Check | Expected |
|---|-------|----------|
| EC | GST ledger has entries | `SELECT COUNT(*) FROM gst_ledger` > 0 |
| 1 | Dashboard KPI: Output GST | `SELECT COALESCE(SUM(gst_amount),0) FROM gst_ledger WHERE input_output_type='output' AND period=?` → UI matches |
| 2 | Dashboard KPI: Input GST | `SELECT COALESCE(SUM(gst_amount),0) FROM gst_ledger WHERE input_output_type='input' AND period=?` → UI matches |
| 3 | Net payable | Output GST - Input GST = UI "Net Payable" |

#### GST-2: GST Input Register — purchase-side ITC
| # | Check | Expected |
|---|-------|----------|
| EC | Vendor invoice exists with GST | `SELECT id, grand_total, gst_rate FROM vendor_invoices WHERE gst_rate > 0 LIMIT 1` |
| 1 | Input register filtered by vendor | `SELECT COUNT(*) FROM gst_ledger WHERE reference_type='vendor_invoice' AND reference_id=? AND input_output_type='input'` → matches UI filtered rows |
| 2 | GST rate-wise summary | `SELECT gst_rate, SUM(taxable_amount) AS total_taxable, SUM(gst_amount) AS total_gst FROM gst_ledger WHERE input_output_type='input' AND period=? GROUP BY gst_rate` → each rate bucket matches UI |

#### GST-3: GST Output Register — sales-side
| # | Check | Expected |
|---|-------|----------|
| EC | Refill order exists with GST | `SELECT id FROM refill_orders WHERE gst_rate > 0 LIMIT 1` |
| 1 | Output register filtered by customer | `SELECT COUNT(*) FROM gst_ledger WHERE reference_type='refill_order' AND reference_id=? AND input_output_type='output'` → matches UI |
| 2 | Customer-wise breakdown | `SELECT customer_id, SUM(gst_amount) FROM gst_ledger WHERE input_output_type='output' AND period=? GROUP BY customer_id` → each row matches |

#### GST-4: Consolidated GST Register
| # | Check | Expected |
|---|-------|----------|
| 1 | Combined view | `SELECT input_output_type, COUNT(*), SUM(taxable_amount), SUM(gst_amount) FROM gst_ledger WHERE period=? GROUP BY input_output_type` → "Input" and "Output" sections match |
| 2 | Rate-wise summary | `SELECT input_output_type, gst_rate, SUM(taxable_amount), SUM(gst_amount) FROM gst_ledger WHERE period=? GROUP BY input_output_type, gst_rate` → every (type,rate) combination shown |

#### GST-5: GST Settlement — monthly net payable
| # | Check | Expected |
|---|-------|----------|
| EC | Period has both input and output entries | `SELECT COUNT(*) FROM gst_ledger WHERE period=?` > 0 |
| 1 | Net payable calculated | `net = output_gst - input_gst` → matches UI "Net Payable" |
| 2 | Settlement payment recorded | Click "Settle Month" → `SELECT id, period, output_gst, input_gst, net_payable, payment_status, paid_amount, payment_date FROM gst_settlements WHERE period=?` → `payment_status='paid', paid_amount = net_payable` |
| 3 | Payment entry | `SELECT id, amount, payment_type, reference_type, reference_id FROM payments WHERE reference_type='gst_settlement' AND reference_id=?` → `amount = net_payable` |

#### GST-6: GSTR-1 Generation
| # | Check | Expected |
|---|-------|----------|
| EC | Period has output GST entries | `SELECT COUNT(*) FROM gst_ledger WHERE input_output_type='output' AND period=?` > 0 |
| 1 | GSTR-1 created | Navigate to return-generate, select period, type='GSTR-1' → `SELECT id, return_type, period, status, total_invoices, total_taxable, total_gst FROM gst_returns WHERE return_type='GSTR-1' AND period=?` → `status='generated'` |
| 2 | B2B section populated | `SELECT COUNT(*) FROM gst_return_sections WHERE return_id=? AND section='B2B' AND total_value > 0` → ≥ 1 |
| 3 | HSN section populated | `SELECT COUNT(*) FROM gst_return_sections WHERE return_id=? AND section='HSN'` → ≥ 1 |
| 4 | Nil supplies | If any nil-rated supplies exist → `'NIL'` section populated |

#### GST-7: GSTR-3B Generation
| # | Check | Expected |
|---|-------|----------|
| 1 | GSTR-3B created | `SELECT id, return_type, period, status FROM gst_returns WHERE return_type='GSTR-3B' AND period=?` → `status='generated'` |
| 2 | Outward supply | `SELECT taxable_value, central_tax, state_tax FROM gstr3b_outward_supply WHERE return_id=?` → values match ledger aggregates |
| 3 | ITC summary | `SELECT itc_available, input_service, input_goods FROM gstr3b_itc_summary WHERE return_id=?` → matches input GST entries |

#### GST-8: GST Return Validation
| # | Check | Expected |
|---|-------|----------|
| 1 | Validate GSTR-1 | Navigate to validate, select return → `SELECT validation_status, errors_found FROM gst_return_validations WHERE return_id=? ORDER BY created_at DESC LIMIT 1` → `errors_found=0` or lists specific issues |
| 2 | Validate GSTR-3B | Same pattern → no critical errors |

#### GST-9: GST Return Preview
| # | Check | Expected |
|---|-------|----------|
| 1 | Preview sections match DB | Each section table in preview matches `SELECT section, total_invoices, total_taxable, total_tax FROM gst_return_sections WHERE return_id=?` |

#### GST-10: GST Return Filing (Mark as Filed)
| # | Check | Expected |
|---|-------|----------|
| 1 | Mark as filed | `SELECT id, status, filing_date, acknowledgement_no FROM gst_returns WHERE id=?` → `status='filed', filing_date IS NOT NULL` |
| 2 | Period locked | `SELECT is_locked, locked_by, locked_at FROM gst_period_locks WHERE period=?` → `is_locked=1` |

#### GST-11: GST Period Lock — prevents edits
| # | Check | Expected |
|---|-------|----------|
| EC | Period is locked | `SELECT is_locked FROM gst_period_locks WHERE period=?` → 1 |
| 1 | Order creation in locked period blocked | Attempt to create order dated in locked period → error "Period is locked for GST" |
| 2 | Unlock requires reason | `UPDATE gst_period_locks SET is_locked=0, unlock_reason=?` → audit trail: `SELECT unlock_reason, unlocked_by, unlocked_at FROM gst_period_locks WHERE period=?` → rows match |

#### GST-12: GST Reconciliation (GSTR-2B matching)
| # | Check | Expected |
|---|-------|----------|
| 1 | Reconciliation entry created | `SELECT id, period, vendor_gstin, invoice_number, invoice_date, invoice_value, itc_eligibility, itc_amount, status FROM gst_reconciliation WHERE period=?` → entries exist |
| 2 | ITC eligibility | `itc_eligibility IN ('full','partial','ineligible')` → affects ITC claimable amount |

#### GST-13: GST Filing Configuration
| # | Check | Expected |
|---|-------|----------|
| 1 | Save per-brand config | `SELECT business_key, registration_type, filing_frequency, gst_state_code, enabled_return_types FROM gst_filing_config WHERE business_key=?` → matches saved values |
| 2 | Return types toggled | Enabling/disabling return types in config → `enabled_return_types` JSON reflects changes |

#### GST-14: GST JSON Export (Official GSTN format)
| # | Check | Expected |
|---|-------|----------|
| 1 | GSTR-1 JSON export | Navigate to export → downloaded JSON validates against official schema: contains `gstin`, `version`, `b2b` array, `b2cs` array, `b2cl` array, `hsn` array, `nil` array, `doc_issue` array |
| 2 | GSTR-3B JSON export | Downloaded JSON contains `gstin`, `ret_period`, `sup_details` (osup_det, osup_zero, osup_nilxb, osup_nil), `itc_elg`, `inter_sup` |
| 3 | Schema version | Exported JSON includes `"version": "GSTR1-3.1.2"` or current schema version |

---

### SUP: Cylinder Suppliers

#### SUP-1: Supplier CRUD
| # | Check | Expected |
|---|-------|----------|
| 1 | Create supplier | `SELECT id, company_name, contact_person, mobile, email, gst_number, supplier_type, status FROM cylinder_suppliers WHERE mobile=?` → all fields match |
| 2 | Edit supplier | `UPDATE SET company_name=?` → `SELECT company_name FROM cylinder_suppliers WHERE id=?` → updated |
| 3 | Deactivate supplier | `UPDATE SET status='inactive'` → `SELECT status FROM cylinder_suppliers WHERE id=?` → 'inactive' |

#### SUP-2: Cylinder Purchase from Supplier
| # | Check | Expected |
|---|-------|----------|
| EC | Supplier exists with stock | `SELECT id FROM cylinder_suppliers WHERE status='active' LIMIT 1` |
| EC | Gas types with cylinders defined | `SELECT id FROM gas_types LIMIT 1` |
| 1 | Purchase record created | `SELECT id, supplier_id, invoice_number, total_amount, payment_status, purchase_date, cylinder_count, gst_amount FROM cylinder_purchases WHERE supplier_id=? ORDER BY id DESC LIMIT 1` → `payment_status='unpaid', cylinder_count > 0` |
| 2 | New cylinders registered | `SELECT COUNT(*), status, ownership_type FROM cylinders WHERE purchase_id=? GROUP BY status, ownership_type` → `status='empty', ownership_type='owned'`, count matches purchase |
| 3 | Supplier ledger entry | `SELECT transaction_type, credit, debit, running_balance FROM cylinder_supplier_ledger WHERE supplier_id=? AND reference_type='purchase' AND reference_id=?` → `debit = total_amount` |

#### SUP-3: Purchase Payment
| # | Check | Expected |
|---|-------|----------|
| 1 | Mark purchase as paid | `UPDATE cylinder_purchases SET payment_status='paid'` → `SELECT payment_status FROM cylinder_purchases WHERE id=?` → 'paid' |
| 2 | Payment ledger | `SELECT transaction_type, credit, running_balance FROM cylinder_supplier_ledger WHERE supplier_id=? AND reference_type='payment' ORDER BY id DESC LIMIT 1` → `credit = amount` |
| 3 | Running balance correct | `SELECT running_balance FROM cylinder_supplier_ledger WHERE supplier_id=? ORDER BY id DESC LIMIT 1` → balance = previous debit - credit = 0 (fully paid) |

---

### EXP: Expense Management

#### EXP-1: Expense Categories CRUD
| # | Check | Expected |
|---|-------|----------|
| 1 | Create category group | `SELECT id, name, sort_order FROM expense_category_groups ORDER BY sort_order` → group created |
| 2 | Create expense category | `SELECT id, name, group_id, gst_applicable, hsn_code, sort_order FROM expense_categories WHERE name=?` → `gst_applicable` matches input |
| 3 | Edit/reorder categories | Reorder → `SELECT sort_order FROM expense_categories WHERE id=?` → updated |

#### EXP-2: Create Expense (without GST)
| # | Check | Expected |
|---|-------|----------|
| EC | Category exists | `SELECT id, gst_applicable FROM expense_categories WHERE gst_applicable=0 LIMIT 1` |
| 1 | Expense inserted | `SELECT id, category_id, amount, expense_date, description, payment_method, gst_type, gst_amount, reference_type, reference_id, payment_status FROM expenses ORDER BY id DESC LIMIT 1` → `amount matches, gst_amount=0.00, payment_status='paid'` |

#### EXP-3: Create Expense (with inclusive GST)
| # | Check | Expected |
|---|-------|----------|
| EC | Category with GST enabled | `SELECT id FROM expense_categories WHERE gst_applicable=1 LIMIT 1` |
| 1 | GST calculated correctly | `amount` entered as ₹1180 inclusive at 18% → `gst_amount = 180.00`, taxable part = 1000.00 |
| 2 | GST ledger entry | `SELECT id, gst_rate, taxable_amount, gst_amount, input_output_type, reference_type, reference_id FROM gst_ledger WHERE reference_type='expense' AND reference_id=?` → `input_output_type='input', gst_amount=180.00` |

#### EXP-4: Create Expense (with exclusive GST)
| # | Check | Expected |
|---|-------|----------|
| 1 | GST exclusive calc | `amount` entered as ₹1000 exclusive at 18% → `gst_amount = 180.00`, total = 1180.00 |

#### EXP-5: Expense Reports
| # | Check | Expected |
|---|-------|----------|
| 1 | Daily report | `SELECT DATE(expense_date) AS day, SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY day ORDER BY day` → matches UI |
| 2 | By-category report | `SELECT ec.name, SUM(e.amount) FROM expenses e JOIN expense_categories ec ON e.category_id=ec.id WHERE e.expense_date BETWEEN ? AND ? GROUP BY ec.id` → matches UI |
| 3 | By-vendor report | `SELECT vendor_name, SUM(amount) FROM expenses WHERE vendor_name IS NOT NULL AND expense_date BETWEEN ? AND ? GROUP BY vendor_name` → matches UI |
| 4 | CSV export | Download CSV → file contains rows matching filtered query |

---

### USR: User Management & RBAC

#### USR-1: Admin User CRUD
| # | Check | Expected |
|---|-------|----------|
| EC | Logged in as super_admin | Session role = 'super_admin' |
| 1 | User list loads | `SELECT id, username, name, role, status FROM users ORDER BY id` → matches UI table |
| 2 | Create user | `SELECT id, username, name, role, status FROM users WHERE username=?` → `role='billing_clerk', status='active'` |
| 3 | Password is bcrypt | `SELECT password_hash FROM users WHERE username=?` → hash starts with `$2y$` |
| 4 | Status toggle | `UPDATE users SET status='inactive'` → user cannot login |

#### USR-2: RBAC Enforcement
| # | Check | Expected |
|---|-------|----------|
| 1 | billing_clerk can view customers | Login as clerk, GET `/admin/customers.php` → 200, page renders |
| 2 | billing_clerk blocked from cylinders | Login as clerk, GET `/admin/cylinders.php` → redirect to dashboard or 403 |
| 3 | viewer blocked from POST | Login as viewer, POST to any handler → `SELECT COUNT(*) FROM payments WHERE created_at > NOW() - INTERVAL 1 MINUTE` → no change (action rejected) |
| 4 | delivery_driver limited scope | Login as driver, can view `dispatch-settlement.php` but not `customers.php` |

---

### SETT: Settings

#### SETT-1: General Settings
| # | Check | Expected |
|---|-------|----------|
| 1 | DB status section shows table counts | `SELECT COUNT(*) FROM cylinders`, `SELECT COUNT(*) FROM customers`, etc. → UI numbers match |
| 2 | Sync Inventory button | Click → `syncInventory($pdo)` runs → inventory table matches raw cylinder counts |
| 3 | DB backup download | Click simulated backup → file downloaded (or message shown if not real) |

#### SETT-2: AI Settings
| # | Check | Expected |
|---|-------|----------|
| 1 | Provider selector | `SELECT provider, api_key_mask, model, temperature, max_tokens, cache_ttl, language_mode, system_prompt FROM ai_config WHERE id=1` → UI shows current values |
| 2 | Save provider config | Change to Gemini, save → `SELECT provider, api_key FROM ai_config WHERE id=1` → `provider='gemini'` |
| 3 | Azure TTS config | `SELECT tts_enabled, tts_region, tts_language FROM ai_config WHERE id=1` → matches saved TTS settings |

#### SETT-3: Brand Management
| # | Check | Expected |
|---|-------|----------|
| EC | At least 1 brand exists | `SELECT COUNT(*) FROM business_config` ≥ 1 |
| 1 | Brand list loads | `SELECT business_key, business_label, business_name, is_default, status FROM business_config ORDER BY business_key` → matches UI |
| 2 | Create new brand | `INSERT INTO business_config (business_key, business_label, business_name, address, gstin, phone, email, website, is_default) VALUES (?,?,?,?,?,?,?,?,0)` → row created |
| 3 | Edit brand SMTP config | `UPDATE business_config SET smtp_host=?, smtp_port=?, smtp_username=?, smtp_encryption=?, email_from_name=?, email_from_address=? WHERE business_key=?` → `SELECT smtp_host FROM business_config WHERE business_key=?` → matches |
| 4 | Logo upload | Upload logo → file exists at `Images/logos/{business_key}.{ext}` |
| 5 | Default brand toggle | Set `is_default=1` on brand, verify other brands have `is_default=0` |
| 6 | Delete brand (with orders) blocked | Attempt to delete brand with order references → error message, `SELECT COUNT(*) FROM business_config WHERE business_key=?` → still 1 |
| 7 | Delete brand (no orders) succeeds | Brand with no order references → `SELECT COUNT(*) FROM business_config WHERE business_key=?` → 0 |

---

### BULK: Bulk Operations Engine

#### BULK-1: Bulk Cylinder Status Update
| # | Check | Expected |
|---|-------|----------|
| EC | Multiple cylinders in 'filled' status | `SELECT COUNT(*) FROM cylinders WHERE status='filled'` ≥ 3 |
| 1 | Impact analysis loads | Select 3 cylinders, choose "Set to Maintenance" → modal shows impact: 3 cylinders affected, inventory counts will change |
| 2 | Execute bulk operation | Confirm → `SELECT id, serial_number, status, updated_at FROM cylinders WHERE id IN (?,?,?)` → all `status='maintenance'` |
| 3 | Audit log entry | `SELECT id, operation_type, cylinder_count, status_before, status_after, performed_by, performed_at FROM bulk_operation_audit ORDER BY id DESC LIMIT 1` → `operation_type='status_update', cylinder_count=3, status_before='filled', status_after='maintenance'` |
| 4 | Inventory recalculated | Run `syncInventory()` → `SELECT filled_stock FROM inventory` decreased by 3, `SELECT maintenance_stock FROM inventory` increased by 3 |

#### BULK-2: Bulk Delete Cylinders
| # | Check | Expected |
|---|-------|----------|
| 1 | Impact analysis warns | Selecting cylinders with active references → warning shown that orders reference these cylinders |
| 2 | Execute delete | Confirm → `SELECT COUNT(*) FROM cylinders WHERE id IN (?,?,?)` → 0 |
| 3 | Cannot delete cylinders with active status | Cylinders with `status='with_customer'` → error: "Cannot delete cylinders currently with customers" |

---

### NOTIF: Notifications

#### NOTIF-1: Low Stock Alert
| # | Check | Expected |
|---|-------|----------|
| EC | Inventory has a gas_type+size with filled_stock < threshold | `SELECT * FROM inventory WHERE filled_stock < low_stock_threshold LIMIT 1` |
| 1 | Alert shown on dashboard | Dashboard "Low Stock Alerts" section lists this gas/size with "Only N remaining" |
| 2 | Email alert sent | `sendLowStockAlert()` called → check `mail()` was invoked or `SELECT * FROM email_log` if table exists |

#### NOTIF-2: Expiry Reminder
| # | Check | Expected |
|---|-------|----------|
| EC | Cylinder with hydrotest expiry within 30 days | `SELECT id, serial_number FROM cylinders WHERE next_hydrotest_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) LIMIT 1` |
| 1 | Dashboard shows expiry warning | "Expiring Cylinders" section lists this cylinder |
| 2 | Email sent with 24h cooldown | `sendExpiryReminders()` → first call sends email, second call within 24h does not |

---

### AUDIT: Cylinder Audit Log

#### AUDIT-1: Full Transaction History
| # | Check | Expected |
|---|-------|----------|
| EC | Cylinder has multiple transactions | `SELECT COUNT(*) FROM cylinder_transactions WHERE cylinder_id=?` ≥ 5 |
| 1 | Audit log loads | Navigate to `/admin/cylinder-audit-log.php` → all transactions for selected filter shown |
| 2 | Search by serial | Enter serial → `SELECT * FROM cylinder_transactions WHERE cylinder_id=(SELECT id FROM cylinders WHERE serial_number=?)` → matches |
| 3 | Filter by type | Select 'issue_to_customer' → `SELECT transaction_type FROM cylinder_transactions WHERE transaction_type='issue_to_customer'` → all shown rows match |
| 4 | Deleted cylinder records | If cylinder was deleted, audit log says "[Deleted Cylinder]" but still shows historical transactions |

---

### RPT: Reports

#### RPT-1: CSV Exports
| # | Check | Expected |
|---|-------|----------|
| 1 | Export orders CSV | Click "Export Orders CSV" → file downloads with headers: ID, Customer, Date, Total, Status, Payment |
| 2 | Export inventory CSV | Click "Export Inventory CSV" → file downloads with gas/size/stock breakdown |
| 3 | Export customers CSV | Headers: ID, Name, Mobile, Email, Type, Active Cylinders, Deposit, Credit Used |
| 4 | Export payments CSV | Headers: ID, Order, Customer, Amount, Method, Date |
| 5 | Export cylinders CSV | Headers: Serial, Gas, Size, Status, Customer, Vendor, Last Refill |

---

### EMAIL: Email System

#### EMAIL-1: Order Confirmation Email
| # | Check | Expected |
|---|-------|----------|
| 1 | Email sent on order create | After creating a refill order → `mail-config.php::getMailer()` invoked, check `PHPMailer::send()` returned true |
| 2 | Email has correct fields | `buildOrderEmailHtml()` output contains: invoice number link, item table, grand total, GST breakdown, business branding |
| 3 | Per-brand SMTP used | `getMailer($business_key)` reads SMTP from `business_config` for the order's business |

#### EMAIL-2: Invoice Resend from Admin
| # | Check | Expected |
|---|-------|----------|
| 1 | Resend button works | Navigate to invoice, click "Resend Email" → `SELECT COUNT(*) FROM payments WHERE refill_order_id=? AND created_at > NOW() - INTERVAL 1 MINUTE` (or email log) shows new send |

---

## Priority P4 — Cross-Cutting & Data Integrity

### DI: Data Integrity

#### DI-1: No Orphan Records
| # | SQL | Expected |
|---|-----|----------|
| 1 | `SELECT COUNT(*) FROM refill_order_items WHERE refill_order_id NOT IN (SELECT id FROM refill_orders)` | 0 |
| 2 | `SELECT COUNT(*) FROM cylinder_transactions WHERE cylinder_id NOT IN (SELECT id FROM cylinders)` | 0 |
| 3 | `SELECT COUNT(*) FROM payments WHERE customer_id IS NOT NULL AND customer_id NOT IN (SELECT id FROM customers)` | 0 |
| 4 | `SELECT COUNT(*) FROM payments WHERE refill_order_id IS NOT NULL AND refill_order_id NOT IN (SELECT id FROM refill_orders)` | 0 |
| 5 | `SELECT COUNT(*) FROM ledger_groups WHERE customer_id NOT IN (SELECT id FROM customers)` | 0 |
| 6 | `SELECT COUNT(*) FROM gst_ledger WHERE reference_type='refill_order' AND reference_id NOT IN (SELECT id FROM refill_orders)` | 0 |
| 7 | `SELECT COUNT(*) FROM gst_ledger WHERE reference_type='vendor_invoice' AND reference_id NOT IN (SELECT id FROM vendor_invoices)` | 0 |
| 8 | `SELECT COUNT(*) FROM dispatch_lot_items WHERE cylinder_id NOT IN (SELECT id FROM cylinders)` | 0 |
| 9 | `SELECT COUNT(*) FROM expenses WHERE reference_type='dispatch_lot' AND reference_id NOT IN (SELECT id FROM dispatch_lots)` | 0 |
| 10 | `SELECT COUNT(*) FROM vendor_partner_ledger WHERE entity_type='vendor' AND entity_id NOT IN (SELECT id FROM vendors)` | 0 |
| 11 | `SELECT COUNT(*) FROM partner_transaction_items WHERE transaction_id NOT IN (SELECT id FROM partner_transactions)` | 0 |

#### DI-2: Inventory-Cylinder Consistency
| # | Check | Expected |
|---|-------|----------|
| 1 | Filled stock matches | For every (gas_type_id, size_capacity): `SELECT i.filled_stock, (SELECT COUNT(*) FROM cylinders WHERE status='filled' AND gas_type_id=i.gas_type_id AND size_capacity=i.size_capacity) AS actual FROM inventory i` → all match after `syncInventory()` |
| 2 | Empty stock matches | Same pattern for `status='empty'` |
| 3 | With customer stock matches | Same pattern for `status='with_customer'` |
| 4 | Sent to vendor stock matches | Same pattern for `status='sent_to_vendor'` |
| 5 | Maintenance stock matches | Same pattern for `status='maintenance'` |

#### DI-3: Invoice Number Sequential
| # | SQL | Expected |
|---|-----|----------|
| 1 | `SELECT invoice_number FROM invoices ORDER BY id` | Format INV-YYYY-NNNN, no gaps, sequential per year |
| 2 | `SELECT lot_number FROM dispatch_lots ORDER BY id` | Format LOT-YYYYMMDD-NNN, sequential within day |

#### DI-4: GST Ledger Balance
| # | SQL | Expected |
|---|-----|----------|
| 1 | Output GST = sum of order items | `SELECT SUM(gst_amount) FROM gst_ledger WHERE input_output_type='output' AND period=?` = `SELECT SUM(gst_amount) FROM refill_order_items WHERE refill_order_id IN (SELECT id FROM refill_orders WHERE DATE_FORMAT(order_date,'%Y-%m')=?)` |
| 2 | Input GST = sum of vendor invoices | Same pattern for `input_output_type='input'` vs `vendor_invoice_items` |
| 3 | Net payable = output - input | `SELECT net_payable FROM gst_settlements WHERE period=?` = output_gst - input_gst |

#### DI-5: Customer Financial Consistency
| # | SQL | Expected |
|---|-----|----------|
| 1 | `active_cylinders_count` = actual count | `SELECT c.id, c.active_cylinders_count, (SELECT COUNT(*) FROM cylinders WHERE current_customer_id=c.id AND status='with_customer') AS actual FROM customers c WHERE c.id=?` → match |
| 2 | `deposit_balance` = sum of deposits minus refunds | `SELECT c.deposit_balance, (SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id=c.id AND payment_type='deposit_added') - (SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id=c.id AND payment_type IN ('deposit_refunded','deposit_deducted')) AS calc FROM customers c WHERE c.id=?` → match |
| 3 | `credit_used` = sum of credit charges minus payments | `SELECT c.credit_used, (SELECT COALESCE(SUM(amount),0) FROM credit_transactions WHERE customer_id=c.id AND transaction_type='charge') - (SELECT COALESCE(SUM(amount),0) FROM credit_transactions WHERE customer_id=c.id AND transaction_type='payment') AS calc FROM customers c WHERE c.id=?` → match |

---

## Helper Functions Reference

```javascript
// tests/helpers/deep-assert.js

// Run multiple DB assertions in one call, return { passed, failed_checks, data }
async function deepVerify(page, checks)

// Create an order and return its id, invoice_number
async function createOrder(page, data)
// data: { customer_id?, gas_type?, size?, qty?, price?, method?, day?, is_rental?, etc }

// Get cylinder + latest 5 transactions
async function getCylinderState(page, serial)

// Get customer financial profile
async function getCustomerState(page, id)

// Assert that inventory matches raw cylinder counts
async function assertInventoryIntegrity(page)
```

## Backend: e2e-deep-assert.php Actions

| Action | Input | Returns |
|--------|-------|---------|
| `order_state` | `order_id` | Full order + items + payment + invoice |
| `cylinder_state` | `serial` | Cylinder row + 5 latest transactions |
| `customer_state` | `id` | Customer row + credit_used + active_cylinders |
| `inventory_state` | `gas_type_id, size` | Inventory row for that gas/size |
| `vendor_lot_state` | `lot_id` | Lot + items + vendor_ledger entries |
| `partner_state` | `partner_id, type` | Partner transactions + items |
| `exchange_state` | `customer_id` | Latest ledger_groups + cylinder_transactions |
| `gst_state` | `reference_type, reference_id` | GST ledger entries |
| `inventory_integrity` | — | Compare inventory vs raw cylinder counts |
| `portal_state` | `customer_id` | Dashboard stats + recent orders + cylinders |
| `supplier_state` | `supplier_id` | Supplier info + purchases + ledger |
| `expense_state` | `expense_id` | Expense detail (or last 10 if no id) |
| `user_state` | `user_id` | User info (or all users if no id) |
| `bulk_operation_audit` | — | Last 10 bulk operation audit entries |

---

*End of Deep Functional Integration Test Plan v2.0*
