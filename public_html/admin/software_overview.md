# Prem Gas Solution Admin Software – Overview

## Purpose
The admin portal (`e:\nutangasestsk.com\public_html\admin`) is a web‑based management system for **Prem Gas Solution**, a cylinder‑based gas distribution business.  It enables staff to:
- Register and manage customers.
- Track inventory of gas cylinders (filled, empty, rented, with‑customer).
- Create **refill orders** (selling gas and optionally renting cylinders).
- Process cylinder exchanges (customers return empty cylinders and receive filled ones).
- Perform **standalone cylinder exchange settlements** (mutual empty cylinder returns without any refill order).
- Apply taxes (GST), discounts, and security deposits.
- Generate invoices, deposit receipts, and payment records.
- View dashboards and partner reports.
- Search complete cylinder audit logs.

## Core Entities
| Entity | Database Table | Key Fields | Business Role |
|--------|----------------|-----------|---------------|
| **Customer** | `customers` | `id`, `name`, `mobile`, `status`, `active_cylinders_count`, `deposit_balance` | End‑user who receives gas cylinders. |
| **Gas Type** | `gas_types` | `id`, `name`, `default_price_per_kg`, `size_prices` (JSON) | Different gases (e.g., Oxygen, LPG) and pricing per size. |
| **Cylinder** | `cylinders` | `id`, `serial_number`, `gas_type_id`, `size_capacity`, `status` (filled/empty/with_customer), `current_customer_id`, `ownership_type` (owned/partner_owned/consumer_owned), `original_owner_customer_id`, `daily_rent_rate`, `free_days`, `borrow_date` | Physical asset tracked through its lifecycle. |
| **Refill Order** | `refill_orders` | `id`, `customer_id`, `subtotal`, `tax_amount`, `discount`, `grand_total`, `payment_status`, `payment_method`, `notes`, `business_name` | Transaction for selling gas (and possibly renting cylinders). |
| **Refill Order Item** | `refill_order_items` | `refill_order_id`, `gas_type_id`, `cylinder_id`, `size_capacity`, `qty`, `price_per_unit`, `is_rental`, `rent_per_day`, `free_days`, `returned_cylinder_id` | Individual line items within an order. |
| **Payment** | `payments` | `customer_id`, `refill_order_id`, `amount`, `payment_method`, `payment_type`, `notes` | Financial record of money received. |
| **Invoice** | `invoices` | `invoice_number`, `refill_order_id`, `invoice_date` | Printable invoice for the order. |
| **Deposit Receipt** | `deposit_receipts` | `receipt_number`, `payment_id`, `customer_id`, `receipt_date` | Printable receipt for security deposits. |
| **Partner** | `partners` | `id`, `company_name`, `contact_person`, `mobile`, `status` | External entities (suppliers, distributors) the business interacts with. |
| **Cylinder Transaction** | `cylinder_transactions` | `id`, `cylinder_id`, `customer_id`, `vendor_id`, `transaction_type`, `transaction_date`, `notes` | Permanent audit log of every cylinder movement. |

## Main Workflows

### Creating a Refill Order
1. **Select Customer** – Staff picks a customer via a searchable combo box.
2. **Add Items** – For each gas variant:
   - Choose gas type, size, quantity.
   - Optionally enable **rental** mode (adds daily rent & free‑day allowance).
   - Optionally override unit price.
   - The UI shows real‑time stock levels (filled cylinders) pulled from `cylinders` where `status='filled'`.
3. **Cylinder Allocation**
   - For each quantity, the system either uses a **selected serial** (auto‑allocate or specific) or automatically finds the next available filled cylinder.
   - Allocated cylinder IDs are stored in `refill_order_items`.
4. **Exchange (Return) Handling**
   - Customers may return empty cylinders. Staff enters serial numbers; the system:
     - Detects ownership type and applies correct settlement logic.
     - If customer returns their own cylinder → **settled** (removed from active tracking).
     - If customer returns company cylinder → marked empty, cleared customer association.
     - If unknown serial → registered as new consumer-owned cylinder.
     - Logs the exchange via `logCylinderTransaction`.
5. **Financial Calculations**
   - Sub‑total = Σ (price_per_unit × qty).
   - GST (18 %) applied if the *Apply GST* checkbox is selected.
   - Discount (₹) applied if entered.
   - Grand total = subtotal + tax – discount.
   - Security deposit is collected for rental cylinders and stored in the customer's `deposit_balance`.
6. **Database Transaction**
   - All steps run inside a single PDO transaction (`beginTransaction` … `commit`).
   - On error, the transaction is rolled back and the error message displayed.
7. **Post‑Processing**
   - Inserts a **payment** record (`payments`).
   - Generates an **invoice** (`invoices`).
   - Calls `syncInventory` to recalculate stock aggregates.
   - Redirects to a printable receipt (`invoice.php`).

### Standalone Cylinder Exchange Settlement
1. **Select Customer** – Staff picks a customer via searchable combo box.
2. **View Exchange Balance** – System shows:
   - Our cylinders currently with the customer.
   - Their consumer-owned cylinders in our inventory.
   - Net exchange balance.
3. **Process Returns** – Two independent panels:
   - **Customer Returns to Us**: Enter serial numbers (or quick-pick from held cylinders).
   - **We Return to Customer**: Select from customer-owned cylinders in inventory.
4. **Settlement** – Single transaction that:
   - Applies correct ownership-based logic for each cylinder.
   - Auto-settles when owner matches holder (removes from active tracking).
   - Logs every movement in `cylinder_transactions`.
   - Syncs inventory and customer counts.

### Manual Ledger Payment / Deposit
1. Staff clicks "Record Payment / Deposit" on customer profile.
2. Enters amount, action type (`refill_payment` / `deposit_added` / `deposit_refunded`), payment method, notes.
3. On submit:
   - Inserts `payments` row.
   - For deposit types: updates `deposit_balance`, generates `deposit_receipts`, **redirects to printable receipt** (`deposit-receipt.php`).
   - For refill_payment: redirects back to profile with success toast.

## Business Rules Implemented in Code
- **Stock Validation** – Before allocating a cylinder, the script checks that a filled cylinder of the requested gas/size exists and is not already allocated in the same order.
- **Rental Tracking** – Rental cylinders carry `daily_rent_rate`, `free_days`, and `borrow_date`. The system updates `cylinders` status to `with_customer` and increments the customer's active cylinder count.
- **GST & Discount** – GST is a flat 18 % on the subtotal; discounts are a flat amount deducted after tax.
- **Security Deposit** – Collected only for rentals, added to the customer's deposit balance and recorded as a separate payment entry.
- **Exchange Policy** – When a returned serial is supplied, the script detects ownership type and applies correct settlement logic, ensuring accurate inventory and active tracking.
- **Settlement Rule** – A cylinder is removed from active exchange tracking when `ownership_type = 'consumer_owned'` AND `current_customer_id = original_owner_customer_id` (owner and holder match).
- **Partner & Business Context** – Orders are tied to a `business_name` (e.g., `nutan_gases`) enabling multi‑entity reporting.

## UI / UX Highlights
- **Dynamic Customer Search** – Type‑ahead combobox that filters customers client‑side.
- **Live Stock Indicators** – Each item row shows "(Stock: X filled)" based on real‑time DB counts.
- **Automatic Row Management** – Add/remove item rows, toggle rental fields, auto‑populate cylinder serial dropdowns.
- **Exchange Balance Panel** – On customer selection, shows cylinders currently held for quick exchange.
- **Policy Violation Modal** – Pops up when a user tries to exceed stock or breach exchange rules.
- **Responsive Layout** – Two‑column grid separating customer info from order‑item details, with modern CSS variables for theming.
- **Cylinder Exchange Page** – Dedicated page for standalone settlement with dual-panel UI (returns vs give-backs).

## Extensibility Points
- **`logCylinderTransaction`** – Central logging function; extend to add more audit fields.
- **`settleCylinderExchange`** – Core settlement helper; can be called from any flow that moves cylinders.
- **`isCylinderInActiveExchange`** – Filtering helper for any view that should exclude settled cylinders.
- **`syncInventory`** – Recalculates aggregated stock; can be hooked into background jobs for performance.
- **Pricing Model** – `size_prices` JSON column enables per‑size overrides without code changes.
- **Partner & Customer Exchange Reports** – Additional admin pages can pull data from `partners`, `cylinder_transactions`, and `cylinders` tables.

---
**Summary**: The admin portal is a PHP‑based CRUD application that orchestrates customers, cylinders, and orders. It enforces business rules around stock, rentals, GST, discounts, security deposits, and cylinder exchange settlement, while providing a responsive UI for staff to process orders and standalone exchanges efficiently.
