/**
 * Deep Functional Test Assertion Helpers
 * Used by phase1-deep.spec.js, phase2-deep.spec.js, phase3-deep.spec.js
 *
 * Usage:
 *   const { deepVerify, createOrder, getCylinderState, getCustomerState } = require('./helpers/deep-assert');
 */

const BASE = 'http://localhost/nutangasestsk.com/public_html';
const DEEP_ASSERT = BASE + '/admin/e2e-deep-assert.php';

/**
 * Run multiple DB state checks in a single batched call.
 * Each check is an object: { action, params, assert: (data) => boolean }
 * Returns { passed, failed_checks: [{check, expected, actual}], data }
 */
async function deepVerify(page, checks) {
  const failures = [];
  const results = {};

  for (const check of checks) {
    try {
      const resp = await page.request.post(DEEP_ASSERT, { form: { action: check.action, ...(check.params || {}) } });
      const json = await resp.json();
      results[check.action] = json.data;

      if (json.passed && check.assert) {
        const assertResult = check.assert(json.data);
        if (assertResult !== true) {
          failures.push({
            check: check.label || check.action,
            expected: assertResult.expected,
            actual: assertResult.actual,
          });
        }
      } else if (!json.passed) {
        failures.push({
          check: check.label || check.action,
          expected: 'passed=true',
          actual: json.message,
        });
      }
    } catch (e) {
      failures.push({
        check: check.label || check.action,
        expected: 'no error',
        actual: e.message,
      });
    }
  }

  return { passed: failures.length === 0, failed_checks: failures, data: results };
}

/**
 * Create a refill order via UI and return the resulting order_id and invoice_number.
 * data: { customer_name?, gas_type?, size?, qty?, price?, method?, is_rental?, vehicle_number? }
 */
async function createOrder(page, data = {}) {
  await page.goto(BASE + '/admin/order-create.php');
  await page.waitForLoadState('networkidle');

  // Select customer via search combobox
  if (data.customer_name) {
    const searchInput = page.locator('#customerSearchInput, .customer-search input, input[name="customer_search"]').first();
    await searchInput.fill(data.customer_name);
    await page.waitForTimeout(800);
    const firstItem = page.locator('#customerDropdownList > div, .customer-dropdown-item, .ui-menu-item').first();
    if (await firstItem.count() > 0) {
      await firstItem.click();
      await page.waitForTimeout(500);
    }
  }

  // Select gas type
  if (data.gas_type) {
    const gasSelect = page.locator('select[name*="gas_type"], .gas-type-select').first();
    await gasSelect.selectOption({ label: new RegExp(data.gas_type, 'i') });
    await page.waitForTimeout(300);
  }

  // Select size
  if (data.size) {
    const sizeSelect = page.locator('select[name*="size"], .size-select').first();
    await sizeSelect.selectOption({ label: data.size });
    await page.waitForTimeout(300);
  }

  // Enter quantity
  if (data.qty) {
    const qtyInput = page.locator('input[name*="qty"], input[type="number"]').first();
    await qtyInput.fill(String(data.qty));
  }

  // Enter custom price if specified
  if (data.price) {
    const priceInput = page.locator('input[name*="price"], input[name*="rate"]').first();
    if (await priceInput.count() > 0) {
      await priceInput.fill(String(data.price));
    }
  }

  // Select payment method
  if (data.method) {
    const methodSelect = page.locator('select[name*="payment_method"], select[name*="payment"]').first();
    await methodSelect.selectOption({ label: new RegExp(data.method, 'i') });
  }

  // Vehicle number
  if (data.vehicle_number) {
    const vehicleInput = page.locator('input[name*="vehicle"]').first();
    if (await vehicleInput.count() > 0) {
      await vehicleInput.fill(data.vehicle_number);
    }
  }

  // Submit
  await page.locator('button[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');

  // Extract order_id from redirect URL
  const url = page.url();
  const match = url.match(/invoice\.php\?order_id=(\d+)/);
  if (match) {
    return { order_id: parseInt(match[1]), invoice_number: url };
  }

  return { order_id: null, invoice_number: null, url };
}

/**
 * Get full cylinder state: cylinder data + latest 5 transactions
 */
async function getCylinderState(page, serial) {
  const resp = await page.request.post(DEEP_ASSERT, { form: { action: 'cylinder_state', serial } });
  return resp.json();
}

/**
 * Get customer financial profile
 */
async function getCustomerState(page, id) {
  const resp = await page.request.post(DEEP_ASSERT, { form: { action: 'customer_state', customer_id: id } });
  return resp.json();
}

/**
 * Assert inventory integrity: compare inventory table against raw cylinder counts
 */
async function assertInventoryIntegrity(page) {
  const resp = await page.request.post(DEEP_ASSERT, { form: { action: 'inventory_integrity' } });
  const json = await resp.json();
  return json;
}

/**
 * Get portal dashboard state for a customer
 */
async function getPortalState(page, id) {
  const resp = await page.request.post(DEEP_ASSERT, { form: { action: 'portal_state', customer_id: id } });
  return resp.json();
}

module.exports = {
  deepVerify, createOrder, getCylinderState, getCustomerState,
  assertInventoryIntegrity, getPortalState, BASE, DEEP_ASSERT,
};
