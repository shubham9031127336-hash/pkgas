const { test, expect } = require('@playwright/test');
const path = require('path');

const BASE = 'http://localhost/nutangasestsk.com/public_html';
const ADMIN_BASE = BASE + '/admin';

async function loginAsAdmin(page) {
  await page.goto(ADMIN_BASE + '/login.php');
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php');
}

async function selectCustomer(page, name) {
  // Type customer name into the search combobox
  await page.click('#customerSearchInput');
  await page.fill('#customerSearchInput', name);
  await page.waitForTimeout(500);
  // Wait for dropdown to appear
  await page.waitForSelector('#customerDropdown[style*="block"]', { timeout: 3000 }).catch(() => {});
  // Click the customer div in the dropdown that contains the name
  const dropdown = page.locator('#customerDropdownList');
  const customerOption = dropdown.locator('div', { hasText: name }).first();
  await customerOption.waitFor({ state: 'visible', timeout: 3000 });
  await customerOption.click();
  await page.waitForTimeout(500);
}

async function selectDropdownByName(page, selector, optionText) {
  const select = page.locator(selector);
  const option = select.locator('option', { hasText: optionText }).first();
  await option.waitFor({ state: 'attached', timeout: 3000 });
  const value = await option.getAttribute('value');
  await select.selectOption(value);
}

test.describe('Order Creation - All 5 Types', () => {

  test('1. Refill Order (mode 0) - with cylinder exchange', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');

    // Select customer  
    await selectCustomer(page, 'ABC Welding Works');
    
    // The form defaults to refill mode (0) for non-rental customers
    // Use Oxygen 40L (7 filled) to avoid stock conflicts with other tests
    await selectDropdownByName(page, 'select[name="items[0][gas_type_id]"]', 'Oxygen');
    await page.waitForTimeout(300);
    
    // Select size - 40L
    await selectDropdownByName(page, 'select[name="items[0][size_capacity]"]', '40L');
    await page.waitForTimeout(300);

    // Set quantity
    await page.fill('input[name="items[0][qty]"]', '1');

    // Set payment method
    await page.selectOption('select[name="payment_method"]', 'Cash');

    // Wait for cylinder serials to populate
    await page.waitForTimeout(500);

    // For return: enter a customer's held cylinder - CYL-NG-00001 is Oxygen 47L with_customer for ABC Welding Works (cust 45)
    await page.waitForSelector('.returned-cyl-input', { timeout: 3000 });
    await page.fill('.returned-cyl-input', 'CYL-NG-00001');

    // Submit the form
    await page.click('button[type="submit"]');

    // Should redirect to invoice.php
    await page.waitForURL('**/invoice.php**', { timeout: 10000 });
    const url = page.url();
    expect(url).toContain('invoice.php');
  });

  test('2. Rental Order (mode 1)', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');

    // Select a rental-type customer
    await selectCustomer(page, 'Mumbai Metro Constructions');
    await page.waitForTimeout(500);

    // For rental customers, mode auto-selects to "1" (Cylinder Rental)
    // Verify mode is rental
    const modeVal = await page.locator('select[name="items[0][is_rental]"]').inputValue();
    expect(modeVal).toBe('1');

    // Select gas - Acetylene (4 filled available for 40L)
    await selectDropdownByName(page, 'select[name="items[0][gas_type_id]"]', 'Acetylene');
    await page.waitForTimeout(300);

    // Select size - 40L
    await selectDropdownByName(page, 'select[name="items[0][size_capacity]"]', '40L');
    await page.waitForTimeout(300);

    // Set rent per day
    await page.fill('input[name="items[0][rent_per_day]"]', '15.00');

    // Set payment method
    await page.selectOption('select[name="payment_method"]', 'UPI');

    await page.waitForTimeout(500);

    // Submit
    await page.click('button[type="submit"]');
    
    await page.waitForURL('**/invoice.php**', { timeout: 10000 });
    const url = page.url();
    expect(url).toContain('invoice.php');
  });

  test('3. Sell Cylinder (mode 2)', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');

    // Select customer
    await selectCustomer(page, 'ABC Welding Works');
    await page.waitForTimeout(300);

    // Switch mode to "Sell Cylinder"
    await page.selectOption('select[name="items[0][is_rental]"]', '2');
    await page.waitForTimeout(300);

    // Select gas - Nitrogen (1 filled available for 47L)
    await selectDropdownByName(page, 'select[name="items[0][gas_type_id]"]', 'Nitrogen');
    await page.waitForTimeout(300);

    // Select size - 47L
    await selectDropdownByName(page, 'select[name="items[0][size_capacity]"]', '47L');
    await page.waitForTimeout(500);

    // Set sell price
    await page.fill('input[name="items[0][sell_price]"]', '2000.00');

    // Set payment method
    await page.selectOption('select[name="payment_method"]', 'Cash');

    // Wait for sell cylinder dropdowns
    await page.waitForTimeout(500);

    // Select a cylinder to sell from the sell-cyl-select
    const sellSelect = page.locator('select[name="items[0][sell_cylinder_ids][]"]').first();
    const sellOption = await sellSelect.locator('option').all();
    // Pick the first non-empty option (index > 0)
    if (sellOption.length > 1) {
      const val = await sellOption[1].getAttribute('value');
      await sellSelect.selectOption(val);
    }

    await page.waitForTimeout(300);

    // Submit
    await page.click('button[type="submit"]');

    await page.waitForURL('**/invoice.php**', { timeout: 10000 });
    const url = page.url();
    expect(url).toContain('invoice.php');
  });

  test('4. Sell Product (mode 3)', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');

    // Select customer
    await selectCustomer(page, 'Sharma Fabrication');
    await page.waitForTimeout(300);

    // Switch mode to "Sell Product"
    await page.selectOption('select[name="items[0][is_rental]"]', '3');
    await page.waitForTimeout(500);

    // Select product
    await selectDropdownByName(page, 'select[name="items[0][product_id]"]', 'Gas Regulator');
    await page.waitForTimeout(300);

    // Set quantity
    await page.fill('input[name="items[0][product_qty]"]', '2');

    // Set payment method
    await page.selectOption('select[name="payment_method"]', 'UPI');

    await page.waitForTimeout(300);

    // Submit
    await page.click('button[type="submit"]');

    await page.waitForURL('**/invoice.php**', { timeout: 10000 });
    const url = page.url();
    expect(url).toContain('invoice.php');
  });

  test('5. Customer Cylinder Refill Service (mode 4)', async ({ page }) => {
    test.setTimeout(60000);
    await loginAsAdmin(page);
    await page.goto(ADMIN_BASE + '/order-create.php');
    await page.waitForLoadState('networkidle');

    // Select customer
    await selectCustomer(page, 'Priya Gas Agency');
    await page.waitForTimeout(300);

    // Switch mode to "Customer Cylinder Refill Service"
    await page.selectOption('select[name="items[0][is_rental]"]', '4');
    await page.waitForTimeout(500);

    // Select gas - Acetylene (stock-independent in mode 4)
    await selectDropdownByName(page, 'select[name="items[0][gas_type_id]"]', 'Acetylene');
    await page.waitForTimeout(300);

    // Select size - 40L
    await selectDropdownByName(page, 'select[name="items[0][size_capacity]"]', '40L');
    await page.waitForTimeout(500);

    // Set payment method
    await page.selectOption('select[name="payment_method"]', 'Cash');

    // Set quantity via JS (page.fill unreliable on dynamically-added inputs)
    await page.evaluate(() => {
      const inp = document.querySelector('input[name="items[0][qty]"]');
      if (inp) inp.value = '2';
    });
    await page.waitForTimeout(200);

    // Fill customer cylinder serials via JS
    await page.evaluate(() => {
      const inputs = document.querySelectorAll('.customer-cyl-input');
      if (inputs.length >= 1) inputs[0].value = 'CUST-AC-001';
      if (inputs.length >= 2) inputs[1].value = 'CUST-AC-002';
    });
    await page.waitForTimeout(300);

    // Submit
    await page.click('button[type="submit"]');

    await page.waitForURL('**/invoice.php**', { timeout: 10000 });
    const url = page.url();
    expect(url).toContain('invoice.php');
  });

});
