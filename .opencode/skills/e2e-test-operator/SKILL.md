---
name: e2e-test-operator
description: Executes E2E browser tests from the test plan, records structured failure reports in docs/testing/failures/, and later helps fix bugs by reading those reports. Two modes: TEST EXECUTION (run tests, record failures) and BUG FIX (read reports, understand codebase, fix bugs).
metadata:
  version: "1.1.0"
  requires:
    - playwright
    - php
    - mysql
  test_plan: "docs/testing/COMPREHENSIVE_TEST_PLAN.md"
  failures_dir: "docs/testing/failures/"
  results_file: "docs/testing/results-summary.json"
---

# E2E Test Operator Skill

A dual-mode skill for running browser-based E2E tests from the comprehensive test plan and later fixing identified bugs.

---

## Progress Tracking (Test Plan Auto-Marking)

Every test run **updates** `docs/testing/COMPREHENSIVE_TEST_PLAN.md` with completion markers. Future AI sessions read these markers to know what's already been tested.

### Marker Format

A `<!-- test-progress -->` comment block is maintained near the top of the plan file (after the Priority & Execution Strategy table). It looks like:

```markdown
<!-- test-progress
{
  "last_updated": "2026-07-14",
  "phases": {
    "phase1": {"label": "Core Business Flows (P0)", "status": "complete",  "tests": 25, "passed": 22, "failed": 3, "skipped": 0, "last_run": "2026-07-14"},
    "phase2": {"label": "Transaction & Settlement (P1)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null},
    "phase3": {"label": "Customer & Portal (P2)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null},
    "phase4": {"label": "Platform Admin (P3)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null},
    "phase5": {"label": "AI Assistant (P3)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null},
    "phase6": {"label": "Public Site (P3)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null},
    "phase7": {"label": "Cross-Cutting (P4)", "status": "incomplete", "tests": 0, "passed": 0, "failed": 0, "skipped": 0, "last_run": null}
  }
-->
```

### Entry Pre-check

**Before** running any tests, the AI MUST:

1. Read `docs/testing/COMPREHENSIVE_TEST_PLAN.md`
2. Extract the `<!-- test-progress -->` block (search with regex: `<!-- test-progress\s*({.*?})\s*-->`)
3. Parse the JSON to see which phases are already `"complete"`
4. **Skip any phase whose status is `"complete"`** — do not re-run it
5. Only execute the requested phases that are `"incomplete"` or `"in_progress"`

### Post-Run Update

**After** finishing all tests in a phase, the AI MUST:

1. Count: total tests executed, passed, failed, skipped
2. Parse the existing `<!-- test-progress -->` block
3. Update the phase entry with:
   - `status`: `"complete"` (if all ran), `"in_progress"` (if partial)
   - `tests`, `passed`, `failed`, `skipped`: actual counts
   - `last_run`: today's date
4. Write the updated block back into the plan file (use the `edit` tool to replace the old `<!-- test-progress -->` block)
5. Also update the markdown "Progress Summary" table just below the block (if one exists) for human readability

### Human-Readable Progress Table

The AI also maintains a markdown table immediately after the JSON block for human readers:

```markdown
## Test Progress

| Phase | Status | Tests | Passed | Failed | Last Run |
|---|---|---|---|---|---|
| Phase 1: Core Business Flows (P0) | ✅ Complete | 25 | 22 | 3 | 2026-07-14 |
| Phase 2: Transaction & Settlement (P1) | ⬜ Not Started | 0 | 0 | 0 | - |
```

### Edge Cases

| Scenario | Behavior |
|---|---|
| No `<!-- test-progress -->` block exists in plan | Create one at the top of the file (after the Priority & Execution Strategy table) with all phases marked `"incomplete"` |
| JSON parsing fails | Treat as if no progress exists — run all phases and create fresh block |
| Phase marked `"complete"` but user explicitly asks for it | Re-run it anyway (user override). Then update the block. |
| Phase marked `"incomplete"` with partial counts (e.g. 10/25 tests done) | Resume from where it left off — only run the remaining test cases. Check `results-summary.json` for which test IDs passed. |
| All phases complete | Report to user: "All phases complete. Use `test:all` to re-run everything." |

---

## When to Use

| User says | Mode | Action |
|---|---|---|
| "Run P0 tests" / "Execute Phase 1" / "Test order creation" | **TEST** | Execute tests from the plan by priority phase |
| "Record failure O1.3.4" / "Log this bug" | **TEST** | Record a structured failure report |
| "Generate test summary" / "Show results" | **TEST** | Build consolidated results summary |
| "Fix bug from O1.3.4" / "Fix failures in Phase 1" | **BUG FIX** | Read failure reports, understand codebase, apply fixes |
| "What's broken?" / "Show me all failures" | **BUG FIX** | Read all failure reports and give a summary |

---

## Core Reference Files

| File | Purpose |
|---|---|
| `docs/testing/COMPREHENSIVE_TEST_PLAN.md` | **Shallow test plan** — UI smoke tests, page loads, element visibility |
| `docs/testing/DEEP_FUNCTIONAL_TEST_PLAN.md` | **Deep test plan** — side-effect verification across all tables for every business operation |
| `tests/browser-tests.spec.js` | 62 existing Playwright tests (base helpers: `adminLogin`, `dbAssert`, `getCsrf`) |
| `tests/customer-e2e.spec.js` | Customer CRUD E2E tests with DB assertion pattern |
| `tests/order-creation.spec.js` | All 5 order mode tests |
| `tests/admin-e2e.spec.js` | Phase 4/5 admin tests (gas types, users, settings, blog, AI, GST, expenses) |
| `tests/phase6-7.spec.js` | Phase 6/7 public site + cross-cutting tests |
| `tests/helpers/deep-assert.js` | Deep test helpers: `deepVerify()`, `createOrder()`, state getters |
| `public_html/admin/e2e-deep-assert.php` | Deep DB state snapshot endpoint (13 actions) |
| `public_html/admin/e2e-db-assert.php` | Shallow DB assertion endpoint (for UI smoke tests) |
| `tests/run_ai_tests.php` | 167 AI subsystem PHP tests (separate domain) |
| `tests/seed_test_data.php` | PHP seed data script |
| `tests/cleanup-customer-e2e.php` | Cleanup script for test data |
| `playwright.config.js` | Playwright configuration |
| `AGENTS.md` | Codebase conventions, quirks, file layout |
| `public_html/admin/AI_SYSTEM_README.md` | Deep technical reference (schema, business flows, migrations) |

---

## MODE 1: TEST EXECUTION

### Entry Point

An AI activates this mode when the user asks to run tests. The workflow:

1. **Read the test plan** (`docs/testing/COMPREHENSIVE_TEST_PLAN.md`)
2. **Check progress markers** — extract `<!-- test-progress -->` block to see which phases are already complete (skip those)
3. **Determine scope** — user specifies phase/priority or "all", filtered against progress markers
4. **Set up test data** — run `tests/seed_test_data.php` if needed
5. **Execute tests sequentially** — for each test case in scope
6. **Record results** — pass/fail with full context
7. **Update progress markers** — write updated `<!-- test-progress -->` block and progress table into the plan file
8. **Generate summary** — write consolidated results

### Execution Priorities

| Phases | Tests | User command |
|---|---|---|
| Phase 0 | Prerequisites (infrastructure check) | `test:setup` |
| Phase 1 (P0) | Core business flows (1.1–1.5) — Login, Order, Exchange, Return | `test:phase1` or `test:p0` |
| Phase 2 (P1) | Transactions (2.1–2.8) — Send/Receive, Partner, Rental, Invoice, Settlement | `test:phase2` or `test:p1` |
| Phase 3 (P2) | Customer & Portal (3.1–3.7) — CRUD, Portal Auth/Dashboard/Orders | `test:phase3` or `test:p2` |
| Phase 4 (P3) | Admin (4.1–4.9) — Gas Types, Cylinders, Users, Settings, Blog, GST, Expenses | `test:phase4` or `test:p3` |
| Phase 5 (P3) | AI Assistant (5.1–5.2) — Chat UI, Settings | `test:phase5` |
| Phase 6 (P3) | Public Site (6.1–6.5) — Home, Blog, Lead Capture, Tracker, LPs | `test:phase6` |
| Phase 7 (P4) | Cross-Cutting (7.1–7.6) — Data Integrity, CSRF, RBAC, i18n, Error Handling | `test:phase7` |
| Deep P0 | Deep functional: refill cash/credit, rental, sell, exchange — full side-effect assertion | `test:deep:p0` |
| Deep P1 | Deep functional: vendor send/receive, partner, rental return, settlement | `test:deep:p1` |
| Deep P2 | Deep functional: customer CRUD, portal dashboard/orders/payments | `test:deep:p2` |
| All | Everything above (shallow + deep) | `test:all` |
| Single | Specific test by ID (e.g. O1.3.4) | `test:single O1.3.4` |

### Test Execution Pattern

For EACH individual test case from the plan, follow this exact procedure:

```
STEP 1: Parse test case from plan
    - Read the test ID, feature name, priority, URL flow, test steps, UI verify, DB verify, linked features

STEP 2: Setup
    - If test needs specific data state, ensure it exists (run seed, create prerequisite records)
    - Clean up from previous test runs if needed

STEP 3: Execute browser steps
    - Use Playwright to navigate, click, fill, submit
    - Use existing helpers from browser-tests.spec.js:
        adminLogin(page)          — Login as admin
        getCsrf(page)             — Read CSRF token from page
        dbAssert(page, payload)   — Run DB assertion query

STEP 4: Verify UI
    - Assert elements are visible
    - Assert text content matches expectations
    - Assert URL changed correctly
    - Capture console errors (page.on('pageerror'), page.on('console'))

STEP 5: Verify DB
    - Run SQL queries via dbAssert() or directly via mysql_query tool
    - Compare actual DB state with expected state from test plan

STEP 6: Check linked features
    - For each linked feature listed in the test plan, do a quick check:
      - Is the data consistent? (e.g., after order creation, check inventory too)
      - Are cross-feature links intact?

STEP 7: Record result
    - PASS: Append to results-summary.json with status "passed"
    - FAIL: Create detailed failure report in docs/testing/failures/<test-id>.json
              Take screenshot → save to docs/testing/failures/<test-id>.png
```

### Results Processing & Targeted Re-run (NO full re-runs)

After each test execution, ALWAYS process results and then run ONLY failed tests.

**NEVER re-run the full test suite from zero. EVER. This wastes AI credits and time.**

```bash
# Step 1: Identify failed tests from last run
# (Read screen output — only the ❌ lines matter)

# Step 2: Re-run ONLY failed tests using grep
npx playwright test -g "Test Title 1|Test Title 2"

# Step 3: If tests pass, done. If still fail:
#   a. Read the error, examine source code
#   b. Fix the code
#   c. Re-run only the fixed test: npx playwright test -g "Test Title"

# To process results into structured JSON reports (optional):
php tests/process-results.php path/to/playwright-json-output.json
```

**Hard rules:**
1. Never run `npx playwright test` without `-g` filter unless explicitly asked
2. Always capture test output to see which specific tests failed
3. Only fix the code that corresponds to the failing test
4. Re-run only the specific fixed test to verify

### Failure Report Schema

Every failure MUST be recorded in this exact JSON structure. The fixing AI will read this schema to understand what broke.

```json
{
  "$schema": "e2e-test-operator/failure-v1",
  "test_id": "O1.3.4",
  "feature": "Order Creation — Refill Cash",
  "priority": "P0",
  "phase": 1,
  "status": "failed",
  "execution": {
    "steps_completed": ["Login as admin", "Navigate to order-create.php", "Select customer from combobox", "Select gas type Oxygen 47L", "Enter quantity 1"],
    "step_failed": "Submit order",
    "step_index": 5,
    "total_steps": 7
  },
  "environment": {
    "url": "http://localhost/nutangasestsk.com/public_html/admin/order-create.php",
    "redirect_url": "http://localhost/nutangasestsk.com/public_html/admin/order-create.php?error=1",
    "expected_url": "http://localhost/nutangasestsk.com/public_html/admin/invoice.php*",
    "browser": "chromium",
    "viewport": "1280x720",
    "timestamp": "2026-07-14T12:00:00Z"
  },
  "error": {
    "message": "Redirected to same page with error parameter instead of invoice.php",
    "type": "unexpected_redirect",
    "ui_evidence": "Error banner visible on order-create page after submit",
    "page_source_excerpt": "<div class=\"alert-banner alert-error\">No filled cylinders available for selected gas type and size</div>",
    "console_errors": [
      {
        "type": "type_error",
        "message": "Cannot read properties of null (reading 'value')",
        "source": "order-create.js:245"
      }
    ],
    "network_errors": []
  },
  "db_state": {
    "before": {
      "refill_orders_count": 5,
      "filled_cylinders_available": {
        "Oxygen_47L": 0,
        "Oxygen_10L": 1
      },
      "customer_active_cylinders": 3
    },
    "after": {
      "refill_orders_count": 5,
      "no_new_order": true,
      "cylinders_unchanged": true
    },
    "assertions": [
      {
        "sql": "SELECT filled_stock FROM inventory WHERE gas_type_id=1 AND size_capacity='47L'",
        "expected": "> 0",
        "actual": "0",
        "passed": false
      }
    ]
  },
  "linked_features": {
    "affected": ["Cylinders", "Inventory", "Payments", "Invoices"],
    "status": "blocked — cannot verify linked features because order was not created",
    "integrity_checks": [
      {
        "feature": "Inventory",
        "query": "SELECT filled_stock, with_customer_stock FROM inventory WHERE gas_type_id=1 AND size_capacity='47L'",
        "expected_change": "filled_stock -1, with_customer_stock +1",
        "actual": "filled_stock=0, with_customer_stock=0",
        "passed": false
      }
    ]
  },
  "screenshots": [
    "docs/testing/failures/O1.3.4-before-submit.png",
    "docs/testing/failures/O1.3.4-after-submit.png",
    "docs/testing/failures/O1.3.4-error-banner.png"
  ],
  "source_files": [
    {"file": "public_html/admin/order-create.php", "lines": "1-2961, especially 100-200 and 240-280"},
    {"file": "public_html/admin/inventory-utils.php", "lines": "syncInventory() function"},
    {"file": "public_html/admin/ajax-get-sizes.php", "lines": "all"}
  ],
  "reproduction_steps": [
    "1. Login as admin (admin/admin123)",
    "2. Navigate to /admin/order-create.php",
    "3. Select customer 'Test Customer' from combobox",
    "4. Select gas type 'Oxygen', size '47L'",
    "5. Set quantity to 1",
    "6. Select payment method 'Cash'",
    "7. Click Submit"
  ],
  "severity": "critical",
  "impact": "Cannot create orders — core business flow blocked. Also affects inventory sync, payments, invoices.",
  "suggested_fix_approach": null,
  "fix_history": []
}
```

### Consolidated Results File

After all tests in scope complete, write/update `docs/testing/results-summary.json`:

```json
{
  "generated_at": "2026-07-14T18:00:00Z",
  "scope": "Phase 1 (P0)",
  "summary": {
    "total": 25,
    "passed": 22,
    "failed": 3,
    "skipped": 0,
    "pass_rate_pct": 88.0
  },
  "by_priority": {
    "P0": {"total": 15, "passed": 13, "failed": 2},
    "P1": {"total": 10, "passed": 9, "failed": 1}
  },
  "results": [
    {"test_id": "L1.1", "status": "passed", "duration_ms": 2340},
    {"test_id": "L1.2", "status": "passed", "duration_ms": 4120},
    {"test_id": "L1.3", "status": "failed", "failure_file": "docs/testing/failures/L1.3.json"},
    {"test_id": "O1.3.1", "status": "passed", "duration_ms": 3120}
  ],
  "failures": [
    {"test_id": "L1.3", "feature": "Invalid login shows error", "severity": "major"},
    {"test_id": "O1.3.4", "feature": "Order creation submit", "severity": "critical"},
    {"test_id": "EX1.4.3", "feature": "Return company cylinder", "severity": "critical"}
  ]
}
```

### Reusable Test Helpers

These helpers are defined in `tests/browser-tests.spec.js`. Use them in every test:

```javascript
// === AUTH ===
async function adminLogin(page) {
  await page.goto(BASE + '/admin/login.php');
  if (page.url().includes('dashboard.php')) return;
  await page.fill('#username', 'admin');
  await page.fill('#password', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php', { timeout: 10000 });
}

// === CSRF ===
async function getCsrf(page) {
  const csrf = page.locator('input[name="_csrf_token"]');
  if (await csrf.isVisible()) return await csrf.getAttribute('value');
  return '';
}

// === DB ASSERT ===
async function dbAssert(page, payload) {
  const resp = await page.request.post(BASE + '/admin/e2e-db-assert.php', { form: payload });
  return resp.json();
}

// === CONSTANTS ===
const BASE = 'http://localhost/nutangasestsk.com/public_html';
const ADMIN_LOGIN = BASE + '/admin/login.php';
const PORTAL_LOGIN = BASE + '/portal/login.php';
```

### Test Execution Notes & Pitfalls

1. **Redirects use JS** — After form submit, wait for URL change with `page.waitForURL('**/invoice.php**')`, not `page.waitForNavigation()`
2. **No output buffering** — `layout.php` flushes HTML. Page may appear interactive before PHP processing is done. Wait for elements.
3. **CSRF tokens** — Every POST needs a valid `_csrf_token`. Read it with `getCsrf()` and include it in form data.
4. **Inventory sync** — After any cylinder/order change, inventory MUST be checked with `syncInventory()`. If inventory counts are wrong, the feature is broken.
5. **Migrations run on page load** — Dashboard and many pages run migrations on every load. If a migration fails, the page crashes.
6. **Rate limiting on portal** — Portal login has file-based rate limiting (`cache/login_rate_<md5>`). Clear these files between tests with:
   ```javascript
   const fs = require('fs');
   const cacheDir = 'public_html/cache';
   fs.readdirSync(cacheDir).filter(f => f.startsWith('login_rate_')).forEach(f => fs.unlinkSync(path.join(cacheDir, f)));
   ```
7. **Business keys** — Always use the default business key (`nutan_gases`). Check `getBrandConfig()['business_key']` for the correct value.
8. **Screenshots** — Always take screenshots on failure. Name them `<test-id>-<description>.png`
9. **Console errors** — Always listen for page errors: `page.on('pageerror', err => ...)` and console messages: `page.on('console', msg => ...)`

---

## DEEP TEST MODE: Side-Effect Verification

### When to Use

Deep tests go beyond UI visibility — they verify **every database row, column, and linked feature** affected by a business operation. For example, a ₹500 order creates rows in 9+ tables. Deep tests assert all of them.

### Execution Pattern

For each deep test case from `docs/testing/DEEP_FUNCTIONAL_TEST_PLAN.md`:

```
STEP 1: Read test case
    - Parse: entry criteria, steps, side-effect verification matrix, integrity checks

STEP 2: Setup prerequisites
    - Ensure test data exists (seed cylinders, create customer with known state)
    - Record baseline state for comparison

STEP 3: Execute business operation in browser
    - Login, navigate, fill form, submit
    - Use createOrder() helper where appropriate

STEP 4: Capture result IDs
    - Extract order_id, invoice_number, cylinder_ids from URL or UI

STEP 5: Run side-effect assertions via deepVerify()
    - Batched DB state checks using e2e-deep-assert.php actions:
        order_state, cylinder_state, customer_state, inventory_state,
        vendor_lot_state, partner_state, exchange_state, gst_state,
        portal_state, rental_return_state, vendor_invoice_state

STEP 6: Run integrity checks
    - assertInventoryIntegrity() — compares inventory vs raw cylinder counts
    - Cross-table consistency (e.g., payment total vs order grand_total)

STEP 7: Record result
    - PASS/FAIL with specific failed check details
    - Failure report includes which assertion failed, expected vs actual
```

### Reusable Deep Assertion Helpers

```javascript
// Defined in tests/helpers/deep-assert.js

// Run multiple DB assertions, returns { passed, failed_checks, data }
const { passed, failed_checks } = await deepVerify(page, [
  {
    label: 'Order grand total',
    action: 'order_state',
    params: { order_id: 123 },
    assert: (data) => data.order.grand_total === '500.00' ? true
      : { expected: '500.00', actual: data.order.grand_total }
  },
  {
    label: 'Cylinder status',
    action: 'cylinder_state',
    params: { serial: 'OX-47L-201' },
    assert: (data) => data.cylinder.status === 'with_customer' ? true
      : { expected: 'with_customer', actual: data.cylinder.status }
  },
]);

// Create order and return IDs
const { order_id } = await createOrder(page, {
  customer_name: 'Test Customer',
  gas_type: 'Oxygen', size: '47L', qty: 1, price: 500, method: 'Cash'
});

// Assert inventory integrity
const invResult = await assertInventoryIntegrity(page);
```

### Deep Test Commands

| Command | Action |
|---|---|
| `test:deep:p0` | Run P0 deep tests: refill cash/credit, rental, sell, exchange |
| `test:deep:p1` | Run P1 deep tests: vendor send/receive, partner, rental return, settlement |
| `test:deep:p2` | Run P2 deep tests: customer CRUD, portal dashboard/orders/payments |
| `test:deep:all` | Run all deep tests |
| `test:deep:single <id>` | Run a single deep test by ID (e.g. `test:deep:single O-CASH-1`) |

### Deep Test Files

| File | Content |
|---|---|
| `tests/phase1-deep.spec.js` | P0 deep tests (~30 tests): order modes, exchange — full side-effect matrices |
| `tests/phase2-deep.spec.js` | P1 deep tests (~20 tests): vendor, partner, rental return, settlement |
| `tests/phase3-deep.spec.js` | P2 deep tests (~15 tests): customer CRUD, portal dashboard/orders/payments |

---

## MODE 2: BUG FIX

### Entry Point

An AI activates this mode when the user asks to fix bugs based on failure reports.

### Fix Workflow

```
For EACH failure report in scope:

STEP 1: Read the failure report
    - Read docs/testing/failures/<test-id>.json
    - Understand: What feature failed? At what step? What was the error?
    - Check the DB state before/after — what data was affected?
    - Check console errors — what JS failed?
    - Review the screenshot if available

STEP 2: Read the plan for context
    - Read the corresponding test case in docs/testing/COMPREHENSIVE_TEST_PLAN.md
    - Understand the expected behavior thoroughly
    - Check linked features — what else might be affected?

STEP 3: Explore source files
    - Open the source files listed in the failure report
    - Also check: filesystem dependencies (require_once chain), DB schema (columns/constraints referenced)
    - Read the full file, not just the error line — understand context

STEP 4: Check AGENTS.md for conventions
    - Read relevant AGENTS.md sections — page patterns, quirks, known bugs
    - Check if this is a known issue or a new one

STEP 5: Diagnose root cause
    - Trace the execution path from the failed step
    - Identify: Is it a PHP error? JS error? DB schema mismatch? Data state issue? Missing migration?
    - Check if the issue is in the source code or the test itself

STEP 6: Plan the fix
    - Determine the exact change needed
    - Check for side effects — what else calls this code?
    - Verify the fix follows conventions (JS redirects, require_role(), csrfField(), __() i18n, etc.)

STEP 7: Apply the fix
    - Edit files with precise changes
    - Follow codebase conventions (no comments, existing style)

STEP 8: Re-verify
    - Rerun the failed test
    - If it passes, update the failure report:
        - Add fix entry to fix_history[]
        - Change status to "fixed"
    - If it still fails, refine the fix

STEP 9: Update fix_history in failure report
    - Append to fix_history array in the failure JSON:
    {
      "fix_history": [
        {
          "attempt": 1,
          "timestamp": "2026-07-14T14:00:00Z",
          "changes_made": [
            {"file": "public_html/admin/order-create.php", "line": 245, "change": "Fixed cylinder availability check to use correct column name"}
          ],
          "test_result": "passed",
          "verified_by": "Playwright rerun of O1.3.4"
        }
      ]
    }
```

### Fix Decision Tree

When diagnosing a failure, use this decision tree:

```
ERROR TYPE → ROOT CAUSE → FIX

1. "500 Internal Server Error" or blank page
   → PHP fatal error
   → Check PHP error log, check include paths (use __DIR__), check DB connection

2. "No filled cylinders available"
   → Inventory empty or incorrect
   → Run syncInventory(), check seed data, check gas_type_id/size mapping

3. "Cannot read properties of null (reading '...')" (JS)
   → PHP didn't output expected HTML/JSON
   → Check the PHP handler that generates the data, verify SQL query

4. "Redirected to same page" instead of expected URL
   → PHP validation failed
   → Check POST handler validation logic, CSRF token, required fields

5. "SQLSTATE[42S22]: Column not found"
   → Migration not run
   → The column is missing. Check if migration exists, run it, or add it.

6. DB state unchanged after operation
   → Transaction rolled back or condition not met
   → Check transaction logic, check WHERE clause in UPDATE, check if PDO::commit() was called

7. UI element not found / not visible
   → HTML structure different from expected
   → Read the actual HTML, update selector, or check if PHP condition hid the element

8. Linked feature data inconsistent
   → syncInventory() not called, or cascade update missing
   → Call syncInventory() after the operation, or add cascade logic

9. Console JS error
   → JavaScript expects data structure that PHP didn't provide
   → Check the AJAX endpoint, check JSON response format

10. Rate limited / blocked
    → Test left state behind (cache file, session)
    → Clean up test artifacts between runs
```

---

## Failure Report Lifecycle

```
CREATED:   Test fails → new <test-id>.json written with status "failed"
UPDATED:   Bug fix attempted → fix_history[] appended
RESOLVED:  Fix verified → status changed to "fixed"
CLOSED:    User acknowledges → moved to "closed" (or deleted)
REOPENED:  Regression detected → status back to "failed"
```

### File Naming Convention

| File | Pattern | Example |
|---|---|---|
| Failure report | `docs/testing/failures/<test-id>.json` | `docs/testing/failures/O1.3.4.json` |
| Screenshot | `docs/testing/failures/<test-id>-<description>.png` | `docs/testing/failures/O1.3.4-error-banner.png` |
| Consolidated results | `docs/testing/results-summary.json` | (single file, overwritten each run) |

---

## Integration with the Codebase

### What this skill REUSES (does not create)

| Resource | File | Used for |
|---|---|---|
| Playwright config | `playwright.config.js` | Browser setup |
| Browser helpers | `browser-tests.spec.js` | `adminLogin()`, `getCsrf()`, `dbAssert()` |
| DB assertion endpoint | `admin/e2e-db-assert.php` | Running SQL queries from tests |
| Seed data | `tests/seed_test_data.php` | Setting up test data |
| Cleanup | `tests/cleanup-customer-e2e.php` | Removing test data |
| Codebase reference | `AGENTS.md` | Conventions, quirks, patterns |
| Technical reference | `admin/AI_SYSTEM_README.md` | Schema, flows, migrations |

### What this skill CREATES

| File | Purpose |
|---|---|
| `docs/testing/failures/<test-id>.json` | Structured failure reports |
| `docs/testing/failures/<test-id>-*.png` | Screenshots of failures |
| `docs/testing/results-summary.json` | Consolidated run results |
| `tests/process-results.php` | Script to convert Playwright JSON output into failure reports |

---

## Command Reference

### Test Execution Commands

| Command | Action |
|---|---|---|
| `test:setup` | Verify infrastructure: DB connection, Playwright config, seed data exist |
| `test:phase1` or `test:p0` | Run Phase 1 (P0 shallow): login, dashboard, order creation, exchange, return |
| `test:phase2` or `test:p1` | Run Phase 2 (P1 shallow): send/receive, partner, rental, invoice, settlement |
| `test:phase3` or `test:p2` | Run Phase 3 (P2 shallow): CRUD, portal auth, dashboard, orders, payments |
| `test:phase4` or `test:p3` | Run Phase 4 (P3 admin): gas types, cylinders, users, settings, blog, GST, expenses |
| `test:phase5` | Run Phase 5 (AI assistant): chat UI, settings |
| `test:phase6` | Run Phase 6 (public site): homepage, blog, lead capture, tracker, LPs |
| `test:phase7` or `test:p4` | Run Phase 7 (cross-cutting): data integrity, CSRF, RBAC, i18n, error handling |
| `test:all` | Run all shallow phases |
| `test:single <id>` | Run a single shallow test by ID (e.g. `test:single O1.3.4`) |
| `test:rerun-failed` | Rerun only previously failed shallow tests |
| `test:summary` | Read and display results-summary.json |
| `test:deep:p0` | Run P0 deep tests: refill cash/credit, rental, sell, exchange |
| `test:deep:p1` | Run P1 deep tests: vendor send/receive, partner, rental return, settlement |
| `test:deep:p2` | Run P2 deep tests: customer CRUD, portal dashboard/orders/payments |
| `test:deep:all` | Run all deep tests |
| `test:deep:single <id>` | Run a single deep test by ID (e.g. `test:deep:single O-CASH-1`) |

### Bug Fix Commands

| Command | Action |
|---|---|
| `fix:all` | Read all failure reports, fix each one, verify |
| `fix:single <id>` | Fix a single failure by test ID (e.g. `fix:single O1.3.4`) |
| `fix:report <id>` | Display the full content of a failure report |
| `fix:list` | List all unresolved failure reports |
| `fix:severity critical` | Fix only critical/major failures |
| `fix:verify <id>` | Rerun a specific test to verify a fix |

---

## Test Execution Flowchart

```
User: "Run P0 tests"
    │
    ▼
Read docs/testing/COMPREHENSIVE_TEST_PLAN.md
    │
    ▼
Check <!-- test-progress --> block — skip already-complete phases
    │
    ▼
Parse all test cases with priority=P0 (Phase 1: sections 1.1–1.5)
    │
    ▼
For each test case:
    ├── Read: feature, URL flow, steps, UI verify, DB verify, linked features
    ├── Execute: adminLogin() → navigate → interact → submit
    ├── Verify UI: assertions, console errors, URL checks
    ├── Verify DB: SQL queries via dbAssert() or mysql_query
    ├── Check linked features: verify cross-feature data consistency
    ├── Record:
    │   ├── PASS → add to results-summary.json
    │   └── FAIL →
    │       ├── Take screenshot → docs/testing/failures/<id>.png
    │       ├── Write failure JSON → docs/testing/failures/<id>.json
    │       └── Add to results-summary.json with reference
    │
    ▼
Update <!-- test-progress --> block + human-readable table in plan
    │
    ▼
Write docs/testing/results-summary.json
    │
    ▼
Report summary to user
```

---

## Bug Fix Flowchart

```
User: "Fix failures"

    ┌──────────────────────────────────────┐
    │ For EACH unresolved failure report:  │
    └──────────────────────────────────────┘
        │
        ▼
    Read docs/testing/failures/<test-id>.json
        │
        ├── Understand: what feature, what step failed, what error
        ├── Check: URL flow, console errors, DB before/after, screenshots
        │
        ▼
    Read corresponding section in test plan
        │
        ├── Understand expected behavior
        ├── Check linked features
        │
        ▼
    Read source files listed in report + AGENTS.md conventions
        │
        ├── Full file context, not just error lines
        ├── Check dependencies, schema, migrations
        │
        ▼
    Diagnose root cause (use Fix Decision Tree)
        │
        ▼
    Apply fix
        │
        ▼
    Rerun test: test:single <test-id>
        │
        ├── PASS → update report: status="fixed", append fix_history[]
        └── FAIL → refine fix
        │
        ▼
    Report to user: what was fixed, how, verification result
```

---

## Appendix: Quick Reference SQL Queries

These are frequently needed for DB verification during both testing and fixing:

```sql
-- Check inventory
SELECT gi.name, i.size_capacity, i.filled_stock, i.empty_stock, i.with_customer_stock
FROM inventory i
JOIN gas_types gi ON i.gas_type_id = gi.id
ORDER BY gi.name, i.size_capacity;

-- Check cylinder status distribution
SELECT status, COUNT(*) FROM cylinders GROUP BY status;

-- Check specific cylinder
SELECT c.*, g.name AS gas_name
FROM cylinders c
JOIN gas_types g ON c.gas_type_id = g.id
WHERE c.serial_number = ?;

-- Check order with payment
SELECT ro.*, p.total_paid
FROM refill_orders ro
LEFT JOIN (SELECT refill_order_id, SUM(amount) AS total_paid FROM payments GROUP BY refill_order_id) p
  ON ro.id = p.refill_order_id
WHERE ro.id = ?;

-- Check customer balance
SELECT id, name, deposit_balance, active_cylinders_count, credit_used, credit_limit
FROM customers WHERE id = ?;

-- Verify syncInventory() correctness
SELECT gas_type_id, size_capacity, status, COUNT(*)
FROM cylinders
GROUP BY gas_type_id, size_capacity, status;
```

---

*End of E2E Test Operator Skill v1.0*
