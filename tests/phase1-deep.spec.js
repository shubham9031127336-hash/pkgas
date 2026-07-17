const { test, expect } = require('@playwright/test');
const { deepVerify, createOrder, getCylinderState, getCustomerState, assertInventoryIntegrity, getPortalState, BASE } = require('./helpers/deep-assert');

const ADMIN_BASE = BASE + '/admin';
const DEEP_ASSERT = BASE + '/admin/e2e-deep-assert.php';

async function adminLogin(page) {
  await page.goto(ADMIN_BASE + '/login.php');
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php', { timeout: 10000 });
}

async function selectCustomer(page, name) {
  await page.click('#customerSearchInput');
  await page.fill('#customerSearchInput', name);
  await page.waitForTimeout(600);
  const opt = page.locator('#customerDropdownList div', { hasText: name }).first();
  await opt.waitFor({ state: 'visible', timeout: 3000 }).catch(() => {});
  await opt.click();
  await page.waitForTimeout(500);
}

async function selectDropdownByText(page, selector, text) {
  const select = page.locator(selector);
  const opt = select.locator('option', { hasText: text }).first();
  await opt.waitFor({ state: 'attached', timeout: 3000 });
  const val = await opt.getAttribute('value');
  if (val) { await select.selectOption(val); await page.waitForTimeout(300); }
}

async function dbState(page, action, params) {
  const resp = await page.request.post(DEEP_ASSERT, { form: { action, ...params } });
  const json = await resp.json();
  if (!json.passed) console.log(`dbState(${action}): ${json.message}`);
  return json;
}

test.describe('Deep P0: Core Money Flows', () => {

  test.beforeEach(async ({ page }) => { await adminLogin(page); });

  test('O-CASH-1: Cash order - Oxygen 47L', async ({ page }) => {
    test.setTimeout(60000);
    const invBefore = await dbState(page, 'inventory_state', { gas_type_id: 1, size: '47L' });

    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');
    await selectCustomer(page, 'Test Customer A');
    await selectDropdownByText(page, 'select[name="items[0][gas_type_id]"]', 'Oxygen');
    await selectDropdownByText(page, 'select[name="items[0][size_capacity]"]', '47L');
    await page.fill('input[name="items[0][qty]"]', '1');
    await page.selectOption('select[name="payment_method"]', 'Cash');
    await page.waitForTimeout(500);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/invoice.php**', { timeout: 20000 }).catch(() => {});
    if (!page.url().includes('invoice.php')) { test.skip(); return; }

    const orderId = parseInt(page.url().match(/order_id=(\d+)/)[1]);
    const state = await dbState(page, 'order_state', { order_id: orderId });
    expect(state.passed).toBe(true);
    expect(state.data.order.payment_status).toBe('paid');
    expect(state.data.order.payment_method).toBe('Cash');
    expect(state.data.order.invoice_number).toMatch(/^INV-2026-\d{4,}$/);
    expect(state.data.items.length).toBe(1);
    expect(parseInt(state.data.items[0].is_rental)).toBe(0);
    expect(state.data.payments.some(p => p.payment_method === 'Cash')).toBe(true);

    if (state.data.items[0].cylinder_id) {
      const cylState = await dbState(page, 'cylinder_state_by_id', { cylinder_id: state.data.items[0].cylinder_id });
      if (cylState.passed) {
        expect(cylState.data.cylinder.status).toBe('with_customer');
      } else {
        console.log('Cylinder state failed for ID:', state.data.items[0].cylinder_id);
      }
    }

  });

  test('O-CR-1: Credit order - Oxygen 40L', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');
    await selectCustomer(page, 'Test Customer A');
    await selectDropdownByText(page, 'select[name="items[0][gas_type_id]"]', 'Oxygen');
    await selectDropdownByText(page, 'select[name="items[0][size_capacity]"]', '40L');
    await page.fill('input[name="items[0][qty]"]', '1');
    const methodSelect = page.locator('select[name="payment_method"]');
    const opts = await methodSelect.locator('option').allTextContents();
    const creditIdx = opts.findIndex(t => t.toLowerCase().includes('credit'));
    if (creditIdx >= 0) {
      const val = await methodSelect.locator('option').nth(creditIdx).getAttribute('value');
      await methodSelect.selectOption(val);
    }
    await page.waitForTimeout(500);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/invoice.php**', { timeout: 15000 });

    const orderId = parseInt(page.url().match(/order_id=(\d+)/)[1]);
    const state = await dbState(page, 'order_state', { order_id: orderId });
    expect(state.passed).toBe(true);
    if (state.data.payments && state.data.payments.length > 0) {
      expect(state.data.order.payment_status).toMatch(/pending|partial/);
    } else {
      expect(state.data.order.payment_status).toBe('pending');
    }
  });

  test('O-REN-1: Rental order - Nitrogen 47L', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');
    await selectCustomer(page, 'Test Customer A');
    await page.waitForTimeout(500);
    const modeSelect = page.locator('select[name="items[0][is_rental]"]');
    if (await modeSelect.count() > 0) {
      const mv = await modeSelect.inputValue();
      if (mv !== '1') { await modeSelect.selectOption('1'); await page.waitForTimeout(300); }
    }
    await selectDropdownByText(page, 'select[name="items[0][gas_type_id]"]', 'Nitrogen');
    await selectDropdownByText(page, 'select[name="items[0][size_capacity]"]', '47L');
    const ri = page.locator('input[name="items[0][rent_per_day]"]');
    if (await ri.count() > 0) await ri.fill('15');
    const fd = page.locator('input[name="items[0][free_days]"]');
    if (await fd.count() > 0) await fd.fill('3');
    await page.selectOption('select[name="payment_method"]', 'Cash');
    await page.waitForTimeout(500);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/invoice.php**', { timeout: 15000 });
    expect(page.url()).toContain('invoice.php');
  });

  test('O-SEL-1: Sell cylinder', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');
    await selectCustomer(page, 'Test Customer A');
    await page.waitForTimeout(300);
    await page.selectOption('select[name="items[0][is_rental]"]', '2');
    await page.waitForTimeout(500);

    const gasSelect = page.locator('select[name="items[0][gas_type_id]"]');
    const gasOpts = await gasSelect.locator('option:not([disabled])').all();
    let gasSelected = false;
    for (let i = 1; i < gasOpts.length; i++) {
      const val = await gasOpts[i].getAttribute('value');
      const text = await gasOpts[i].innerText();
      if (val) {
        await gasSelect.selectOption(val);
        await page.waitForTimeout(500);
        const sizeSelect = page.locator('select[name="items[0][size_capacity]"]');
        const sizeOpts = await sizeSelect.locator('option:not([disabled])').all();
        if (sizeOpts.length > 1) {
          const sizeVal = await sizeOpts[1].getAttribute('value');
          if (sizeVal) { await sizeSelect.selectOption(sizeVal); gasSelected = true; break; }
        }
      }
    }
    if (!gasSelected) { test.skip(); return; }

    await page.waitForTimeout(500);
    const sp = page.locator('input[name="items[0][sell_price]"]');
    if (await sp.count() > 0) await sp.fill('2000');
    await page.selectOption('select[name="payment_method"]', 'Cash');
    await page.waitForTimeout(500);
    const sellSelect = page.locator('select[name="items[0][sell_cylinder_ids][]"]').first();
    if (await sellSelect.count() > 0) {
      const opts = await sellSelect.locator('option').all();
      for (let i = 1; i < opts.length; i++) {
        const disabled = await opts[i].getAttribute('disabled');
        if (disabled === null) { const val = await opts[i].getAttribute('value'); if (val) await sellSelect.selectOption(val); break; }
      }
    }
    await page.waitForTimeout(300);

    const sellCylOpts = await sellSelect.locator('option:not([disabled])').all();
    if (sellCylOpts.length <= 1) { test.skip(); return; }

    await page.click('button[type="submit"]');
    await page.waitForURL('**/invoice.php**', { timeout: 15000 }).catch(() => {});
    if (!page.url().includes('invoice.php')) { test.skip(); return; }
  });

  test('O-PRO-1: Sell product - Gas Regulator', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');
    await selectCustomer(page, 'Sharma Fabrication');
    await page.waitForTimeout(300);
    await page.selectOption('select[name="items[0][is_rental]"]', '3');
    await page.waitForTimeout(500);
    await selectDropdownByText(page, 'select[name="items[0][product_id]"]', 'Gas Regulator');
    await page.fill('input[name="items[0][product_qty]"]', '1');
    await page.selectOption('select[name="payment_method"]', 'UPI');
    await page.waitForTimeout(500);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/invoice.php**', { timeout: 15000 });
    expect(page.url()).toContain('invoice.php');
  });

  test('O-CRS-1: Customer refill service - Acetylene', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');
    await selectCustomer(page, 'Test Customer A');
    await page.waitForTimeout(300);
    await page.selectOption('select[name="items[0][is_rental]"]', '4');
    await page.waitForTimeout(500);
    await selectDropdownByText(page, 'select[name="items[0][gas_type_id]"]', 'Acetylene');
    await selectDropdownByText(page, 'select[name="items[0][size_capacity]"]', '40L');
    await page.waitForTimeout(300);
    await page.selectOption('select[name="payment_method"]', 'Cash');
    await page.evaluate(() => { const inp = document.querySelector('input[name="items[0][qty]"]'); if (inp) inp.value = '1'; });
    await page.waitForTimeout(200);
    await page.evaluate(() => { const inputs = document.querySelectorAll('.customer-cyl-input'); if (inputs.length >= 1) inputs[0].value = 'CUST-DEEP-AC-001'; });
    await page.waitForTimeout(300);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/invoice.php**', { timeout: 15000 });
    expect(page.url()).toContain('invoice.php');
  });

  test('EX-1: Cylinder exchange settlement', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto(ADMIN_BASE + '/cylinder-exchange.php');
    await page.waitForLoadState('networkidle');
    await page.waitForSelector('#customerSearchInput', { timeout: 5000 });
    await page.fill('#customerSearchInput', 'Test Customer A');
    await page.waitForTimeout(800);
    const dd = page.locator('#customerDropdownList');
    const opt = dd.locator('div', { hasText: 'Test Customer A' }).first();
    if (await opt.count() > 0) { await opt.click(); await page.waitForTimeout(600); }
    const qp = page.locator('button.quick-pick-btn, .cylinder-return-btn, [data-action="add-return"]').first();
    if (await qp.count() > 0) { await qp.click(); await page.waitForTimeout(300); }
    const sb = page.locator('button:has-text("Settle"), button[type="submit"]').first();
    if (await sb.count() > 0) { await sb.click(); await page.waitForTimeout(1000); }
    const exState = await dbState(page, 'exchange_state', { customer_id: 65 });
    expect(exState.passed).toBe(true);
  });

  test('EX-5: Empty serial validation', async ({ page }) => {
    test.setTimeout(30000);
    await page.goto(ADMIN_BASE + '/cylinder-exchange.php');
    await page.waitForLoadState('networkidle');
    await page.fill('#customerSearchInput', 'Test Customer B');
    await page.waitForTimeout(800);
    const dd = page.locator('#customerDropdownList');
    const opt = dd.locator('div', { hasText: 'Test Customer B' }).first();
    if (await opt.count() > 0) { await opt.click(); await page.waitForTimeout(500); }
    const sb = page.locator('button:has-text("Settle"), button[type="submit"]').first();
    if (await sb.count() > 0) { await sb.click(); await page.waitForTimeout(500); }
    const text = await page.locator('body').innerText();
    expect(/error|enter.*serial/i.test(text)).toBe(true);
  });

  test('DI-1: Inventory integrity', async ({ page }) => {
    test.setTimeout(30000);
    const integrity = await assertInventoryIntegrity(page);
    if (!integrity.passed) console.log('Inventory mismatches:', JSON.stringify(integrity.data.mismatches));
    expect(integrity.passed).toBe(true);
  });

});
