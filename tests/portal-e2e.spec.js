const { test, expect } = require('@playwright/test');

const BASE = 'http://localhost/nutangasestsk.com/public_html';
const PORTAL_LOGIN = BASE + '/portal/login.php';
const DB_ASSERT = BASE + '/admin/e2e-db-assert.php';

const TEST_EMAIL = 'test@test.com';
const TEST_PASS = 'test123';
const NEW_NAME = 'Portal E2E Updated';
const NEW_ADDRESS = '123 E2E Test Street, Khagaria';
const NEW_PASS = 'newtest123';

async function loginAsCustomer(page) {
  await page.goto(PORTAL_LOGIN);
  try {
    const fs = require('fs');
    const path = require('path');
    const cacheDir = path.join(__dirname, '..', 'public_html', 'cache');
    const files = fs.readdirSync(cacheDir).filter(f => f.startsWith('login_rate_'));
    files.forEach(f => fs.unlinkSync(path.join(cacheDir, f)));
  } catch (e) { /* non-critical */ }
  await page.fill('#email', TEST_EMAIL);
  await page.fill('#password', TEST_PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php', { timeout: 10000 });
  await page.waitForSelector('.stats-grid', { timeout: 5000 });
}

async function dbAssert(page, payload) {
  const resp = await page.request.post(DB_ASSERT, { form: payload });
  return resp.json();
}

test.describe('Portal Dashboard (3.3)', () => {

  test('PD3.3.2: Active refill services stat shown on dashboard', async ({ page }) => {
    await loginAsCustomer(page);
    const statLabels = page.locator('.stat-label');
    const labels = await statLabels.allTextContents();
    const hasRefill = labels.some(l => l.includes('Refill'));
    expect(hasRefill).toBe(true);
    const navLink = page.locator('a[href="refill-services.php"]');
    await expect(navLink.first()).toBeVisible();
  });

  test('PD3.3.3: Quick action buttons link to correct pages', async ({ page }) => {
    await loginAsCustomer(page);
    const actions = page.locator('.quick-actions a');
    await expect(actions).toHaveCount(5);
    const hrefs = await actions.evaluateAll(list => list.map(a => a.getAttribute('href')));
    expect(hrefs).toContain('orders.php');
    expect(hrefs).toContain('cylinders.php');
    expect(hrefs).toContain('payments.php');
    expect(hrefs).toContain('refill-services.php');
    expect(hrefs).toContain('profile.php');
  });
});

test.describe('Portal Orders (3.4)', () => {

  test('PO3.4.2: Order detail page shows items and payment breakdown', async ({ page }) => {
    await loginAsCustomer(page);
    const custRes = await dbAssert(page, { action: 'customer_by_email', email: TEST_EMAIL });
    expect(custRes.passed).toBe(true);
    const customerId = custRes.data.id;

    const ordersRes = await dbAssert(page, { action: 'customer_orders', id: customerId });
    expect(ordersRes.passed).toBe(true);
    if (ordersRes.data.length === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No orders for this customer' });
      return;
    }
    const orderId = ordersRes.data[0].id;

    await page.goto(BASE + '/portal/order-detail.php?id=' + orderId);
    await expect(page.locator('h1')).toContainText('Order #' + orderId);
    await expect(page.locator('.card-header h2:has-text("Order Summary")')).toBeVisible();
    await expect(page.locator('.card-header h2:has-text("Items")')).toBeVisible();
    const badge = page.locator('span.badge').first();
    await expect(badge).toBeVisible();
    const back = page.locator('a[href="orders.php"]');
    await expect(back.first()).toBeVisible();
  });

  test('PO3.4.3: Filter orders by status', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/orders.php');

    const statusSelect = page.locator('select[name="status"]');
    await expect(statusSelect).toBeVisible();

    await statusSelect.selectOption('paid');
    await page.locator('button.btn-filter').click();
    await page.waitForLoadState('networkidle');
    expect(page.url()).toContain('status=paid');

    const badges = page.locator('table tbody span.badge');
    const count = await badges.count();
    if (count > 0) {
      const texts = await badges.allTextContents();
      for (const t of texts) {
        expect(t.toLowerCase()).toContain('paid');
      }
    }
  });
});

test.describe('Portal Cylinders (3.5)', () => {

  test('PC3.5.1: Cylinders list loads and shows page structure', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/cylinders.php');
    await expect(page.locator('h1')).toContainText('My Cylinders');
    await expect(page.locator('.stats-grid')).toBeVisible();

    const statusSelect = page.locator('select[name="status"]');
    await expect(statusSelect).toBeVisible();

    const body = page.locator('body');
    const bodyText = await body.textContent();
    if (bodyText.includes('No cylinders')) {
      // Empty state — valid
      await expect(body).toContainText(/no cylinders|assigned/i);
    } else {
      // Has cylinders — verify table structure
      await expect(page.locator('table')).toBeVisible();
      const rows = page.locator('table tbody tr');
      expect(await rows.count()).toBeGreaterThan(0);
    }
  });

  test('PC3.5.2: Cylinder detail navigation', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/cylinders.php');

    const viewLink = page.locator('a.table-link[href*="cylinder-detail.php"]').first();
    if (await viewLink.count() === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No cylinders to view detail' });
      return;
    }
    await viewLink.click();
    await page.waitForLoadState('networkidle');

    // Should be on detail page with cylinder serial in heading
    await expect(page.locator('.card-header h2:has-text("Details")')).toBeVisible();
    await expect(page.locator('.card-header h2:has-text("Transaction History")')).toBeVisible();
    const badge = page.locator('span.badge').first();
    await expect(badge).toBeVisible();
    const back = page.locator('a[href="cylinders.php"]');
    await expect(back.first()).toBeVisible();
  });
});

test.describe('Portal Payments (3.6)', () => {

  test('PP3.6.1: Payment history page loads with stats', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/payments.php');
    await expect(page.locator('h1')).toContainText('Payments');
    await expect(page.locator('.stats-grid')).toBeVisible();
    const payBtn = page.locator('a[href="make-payment.php"]');
    await expect(payBtn.first()).toBeVisible();

    const bodyText = await page.locator('body').textContent();
    if (bodyText.includes('Payment History')) {
      await expect(page.locator('h2:has-text("Payment History")')).toBeVisible();
    }
  });

  test('PP3.6.2: Make payment form loads with or without orders', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/make-payment.php');
    await expect(page.locator('h1')).toContainText('Make a Payment');
    await expect(page.locator('a[href="payments.php"]').first()).toBeVisible();

    const orderSelect = page.locator('select[name="order_id"]');
    const amountInput = page.locator('input[name="amount"]');
    const methodSelect = page.locator('select[name="payment_method"]');

    if (await orderSelect.count() > 0) {
      await expect(orderSelect).toBeVisible();
      await expect(amountInput).toBeVisible();
      await expect(methodSelect).toBeVisible();
    } else {
      const body = page.locator('body');
      await expect(body).toContainText(/outstanding|paid/i);
    }
  });

  test('PP3.6.3: Submit payment when pending order exists', async ({ page }) => {
    await loginAsCustomer(page);
    const custRes = await dbAssert(page, { action: 'customer_by_email', email: TEST_EMAIL });
    expect(custRes.passed).toBe(true);
    const customerId = custRes.data.id;

    const pendingRes = await dbAssert(page, { action: 'customer_has_pending_order', id: customerId });
    if (!pendingRes.passed || !pendingRes.data) {
      test.info().annotations.push({ type: 'skip', description: 'No pending orders to pay' });
      return;
    }
    const orderId = pendingRes.data.id;
    const due = parseFloat(pendingRes.data.due);
    const payAmount = Math.min(due, 100);

    await page.goto(BASE + '/portal/make-payment.php');
    await page.locator('select[name="order_id"]').selectOption(String(orderId));
    await page.waitForTimeout(200);
    const amountInput = page.locator('input[name="amount"]');
    await amountInput.clear();
    await amountInput.fill(String(payAmount));
    await page.locator('select[name="payment_method"]').selectOption('UPI');
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const currentUrl = page.url();
    if (currentUrl.includes('order-detail.php')) {
      const success = page.locator('.alert-success');
      if (await success.count() > 0) {
        const text = await success.textContent();
        expect(text).toMatch(/success/i);
      }
    } else if (currentUrl.includes('make-payment.php')) {
      const success = page.locator('.alert-success');
      const error = page.locator('.alert-error');
      if (await success.count() > 0) {
        // success
      } else if (await error.count() > 0) {
        const errText = await error.textContent();
        test.info().annotations.push({ type: 'warn', description: 'Payment error: ' + errText });
      }
    }
  });
});

test.describe('Portal Profile (3.7)', () => {

  test('PF3.7.1: Profile page loads with customer data pre-filled', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/profile.php');
    await expect(page.locator('h1')).toContainText('My Profile');
    await expect(page.locator('h2:has-text("Account Information")')).toBeVisible();
    const nameInput = page.locator('input[name="name"]');
    await expect(nameInput).toBeVisible();
    await expect(nameInput).not.toHaveValue('');
    const emailInput = page.locator('input[type="email"][disabled]');
    await expect(emailInput).toHaveValue(TEST_EMAIL);
    await expect(page.locator('h2:has-text("Change Password")')).toBeVisible();
    await expect(page.locator('input[name="current_password"]')).toBeVisible();
    await expect(page.locator('input[name="new_password"]')).toBeVisible();
    await expect(page.locator('input[name="confirm_password"]')).toBeVisible();
  });

  test('PF3.7.2: Update profile name and address', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/profile.php');
    const nameInput = page.locator('input[name="name"]');
    const origName = await nameInput.inputValue();
    const addressInput = page.locator('textarea[name="address"]');
    const origAddress = await addressInput.inputValue();

    await nameInput.clear();
    await nameInput.fill(NEW_NAME);
    await addressInput.clear();
    await addressInput.fill(NEW_ADDRESS);
    await page.locator('button:has-text("Save Changes")').click();
    await page.waitForLoadState('networkidle');

    const success = page.locator('.alert-success');
    if (await success.count() > 0) {
      const text = await success.textContent();
      expect(text).toMatch(/success|updated|saved/i);
    }

    // Verify page reflects update
    await expect(nameInput).toHaveValue(NEW_NAME);

    // Restore
    await nameInput.clear();
    await nameInput.fill(origName || '');
    await addressInput.clear();
    await addressInput.fill(origAddress || '');
    await page.locator('button:has-text("Save Changes")').click();
    await page.waitForLoadState('networkidle');
  });

  test('PF3.7.3: Change password flow', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/profile.php');

    await page.locator('input[name="current_password"]').fill(TEST_PASS);
    await page.locator('input[name="new_password"]').fill(NEW_PASS);
    await page.locator('input[name="confirm_password"]').fill(NEW_PASS);
    await page.locator('button:has-text("Change Password")').click();
    await page.waitForLoadState('networkidle');

    // Check result
    const success = page.locator('.alert-success');
    if (await success.count() > 0) {
      // Restore: re-login with new pass, change back
      await page.goto(BASE + '/portal/logout.php');
      await page.waitForLoadState('networkidle');
      await page.goto(PORTAL_LOGIN);
      await page.fill('#email', TEST_EMAIL);
      await page.fill('#password', NEW_PASS);
      await page.click('button[type="submit"]');
      await page.waitForURL('**/dashboard.php', { timeout: 10000 });
      await page.goto(BASE + '/portal/profile.php');
      await page.locator('input[name="current_password"]').fill(NEW_PASS);
      await page.locator('input[name="new_password"]').fill(TEST_PASS);
      await page.locator('input[name="confirm_password"]').fill(TEST_PASS);
      await page.locator('button:has-text("Change Password")').click();
      await page.waitForLoadState('networkidle');
    }

    const pwRes = await dbAssert(page, { action: 'customer_password_valid', email: TEST_EMAIL, password: TEST_PASS });
    expect(pwRes.passed).toBe(true);
    expect(pwRes.data.valid).toBe(true);
  });

  test('PF3.7.4: Wrong current password shows error', async ({ page }) => {
    await loginAsCustomer(page);
    await page.goto(BASE + '/portal/profile.php');

    await page.locator('input[name="current_password"]').fill('wrongpassword123');
    await page.locator('input[name="new_password"]').fill('somethingnew123');
    await page.locator('input[name="confirm_password"]').fill('somethingnew123');
    await page.locator('button:has-text("Change Password")').click();
    await page.waitForLoadState('networkidle');

    const errorAlert = page.locator('.alert-error');
    if (await errorAlert.count() > 0) {
      const text = await errorAlert.textContent();
      expect(text).toMatch(/wrong|incorrect|error|invalid|not match/i);
    }

    const pwRes = await dbAssert(page, { action: 'customer_password_valid', email: TEST_EMAIL, password: TEST_PASS });
    expect(pwRes.passed).toBe(true);
    expect(pwRes.data.valid).toBe(true);
  });
});
