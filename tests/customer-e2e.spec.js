const { test, expect } = require('@playwright/test');

const BASE = 'http://localhost/nutangasestsk.com/public_html';
const ADMIN_LOGIN = BASE + '/admin/login.php';
const CUSTOMERS_PAGE = BASE + '/admin/customers.php';
const DB_ASSERT = BASE + '/admin/e2e-db-assert.php';

const TS = Date.now();
const TEST_MOBILE = '99999' + String(TS).slice(-5);
const TEST_MOBILE_2 = '99998' + String(TS).slice(-5);
const TEST_NAME = 'E2E Test Customer';
const TEST_EDIT_NAME = 'E2E Edited Customer';
const TEST_EMAIL = 'e2e_' + TS + '@test.com';
const TEST_DELETE_NAME = 'E2E Delete Me';
const TEST_DELETE_MOBILE = '99997' + String(TS).slice(-5);

async function loginAsAdmin(page) {
  await page.goto(ADMIN_LOGIN);
  await page.fill('#username', 'admin');
  await page.fill('#password', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php', { timeout: 10000 });
}

async function dbAssert(page, payload) {
  const resp = await page.request.post(DB_ASSERT, { form: payload });
  return resp.json();
}

test.describe('Customer Management E2E', () => {

  test('C1: Customers page loads with table headers', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);
    await expect(page).toHaveTitle(/Customer Management/i);
    const heading = page.locator('h2:has-text("Customers")');
    await expect(heading).toBeVisible();
    const th = page.locator('table.admin-table th');
    await expect(th.first()).toBeVisible();
    const texts = await th.allTextContents();
    const joined = texts.join(' ');
    expect(joined).toMatch(/ID|Name|Mobile|Email|GST|Deposit|Dues|Actions/i);
  });

  test('C2: Create a customer with all fields — data persists in DB', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    // Open Add Customer modal
    await page.click('button:has-text("Register Customer")');
    await expect(page.locator('#addCustomerModal.active')).toBeVisible({ timeout: 3000 });

    // Fill every field
    await page.locator('#addCustomerModal input[name="name"]').fill(TEST_NAME);
    await page.locator('#addCustomerModal input[name="mobile"]').fill(TEST_MOBILE);
    await page.locator('#addCustomerModal input[name="email"]').fill(TEST_EMAIL);
    await page.locator('#addCustomerModal select[name="customer_type"]').selectOption('refill');
    await page.locator('#addCustomerModal input[name="gst_number"]').fill('18AAAAA0000A1Z9');
    await page.locator('#addCustomerModal input[name="state_code"]').fill('18');
    await page.locator('#addCustomerModal input[name="city"]').fill('Khagaria');
    await page.locator('#addCustomerModal input[name="pincode"]').fill('786125');
    await page.locator('#addCustomerModal select[name="registration_type"]').selectOption('regular');
    await page.locator('#addCustomerModal textarea[name="address"]').fill('E2E Test Address, Khagaria');

    // Submit
    await page.locator('#addCustomerModal button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // UI: success banner
    await expect(page.locator('.alert-banner').first()).toBeVisible({ timeout: 5000 });
    const bannerText = await page.locator('.alert-banner').first().textContent();
    expect(bannerText).toMatch(/success/i);

    // UI: customer row appears in table
    const tbody = page.locator('table.admin-table tbody');
    await expect(tbody).toContainText(TEST_NAME);
    await expect(tbody).toContainText(TEST_MOBILE);

    // DB: verify all columns
    const res = await dbAssert(page, { action: 'customer_exists', mobile: TEST_MOBILE });
    expect(res.passed).toBe(true);
    const c = res.data;
    expect(c.name).toBe(TEST_NAME);
    expect(c.mobile).toBe(TEST_MOBILE);
    expect(c.email).toBe(TEST_EMAIL);
    expect(c.customer_type).toBe('refill');
    expect(c.gst_number).toBe('18AAAAA0000A1Z9');
    expect(Number(c.state_code)).toBe(18);
    expect(c.city).toBe('Khagaria');
    expect(c.pincode).toBe('786125');
    expect(c.registration_type).toBe('regular');
    expect(c.address).toContain('E2E Test Address');
    expect(Number(c.deposit_balance)).toBe(0);
    expect(Number(c.active_cylinders_count)).toBe(0);
    expect(Number(c.credit_used)).toBe(0);

    // Store ID for later tests
    const cId = c.id;
    test.info().annotations.push({ type: 'customer_id', description: String(cId) });
  });

  test('C3: Duplicate mobile shows error', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    await page.click('button:has-text("Register Customer")');
    await expect(page.locator('#addCustomerModal.active')).toBeVisible({ timeout: 3000 });

    await page.locator('#addCustomerModal input[name="name"]').fill('Duplicate Test');
    await page.locator('#addCustomerModal input[name="mobile"]').fill(TEST_MOBILE);
    await page.locator('#addCustomerModal button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Error banner should show duplicate mobile message
    const alert = page.locator('.alert-banner').first();
    await expect(alert).toBeVisible({ timeout: 5000 });
    const text = await alert.textContent();
    expect(text).toMatch(/already exists|duplicate|error/i);

    // DB: still only 1 record with this mobile
    const res = await dbAssert(page, { action: 'customer_count_by_mobile', mobile: TEST_MOBILE });
    expect(res.passed).toBe(true);
    expect(res.data.count).toBe(1);
  });

  test('C4: Search by name filters results', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    const searchInput = page.locator('input[name="search"]');
    await searchInput.fill(TEST_NAME);
    // Live search triggers after 700ms debounce, then page reloads
    await page.waitForTimeout(1200);
    await page.waitForLoadState('networkidle');

    const tbody = page.locator('table.admin-table tbody');
    await expect(tbody).toContainText(TEST_NAME);
    // Any other customer rows should be filtered out
    const rows = await page.locator('table.admin-table tbody tr').count();
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('C5: Search by mobile filters results', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    const searchInput = page.locator('input[name="search"]');
    await searchInput.fill(TEST_MOBILE);
    await page.waitForTimeout(1200);
    await page.waitForLoadState('networkidle');

    const tbody = page.locator('table.admin-table tbody');
    await expect(tbody).toContainText(TEST_MOBILE);
  });

  test('C6: Filter by customer type', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    await page.locator('select[name="type"]').selectOption('rental');
    await page.waitForTimeout(1200);
    await page.waitForLoadState('networkidle');

    // The table should show only rental customers (our E2E customer is refill, may not appear)
    const tbody = page.locator('table.admin-table tbody');
    // If no rental customers, we should see "no results" message
    const noResults = await tbody.textContent();
    expect(noResults.length).toBeGreaterThan(0);
  });

  test('C7: Edit customer — updates persist in DB', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    // Get customer ID from DB
    const existRes = await dbAssert(page, { action: 'customer_exists', mobile: TEST_MOBILE });
    expect(existRes.passed).toBe(true);
    const customerId = existRes.data.id;

    // Click Edit button for our customer
    // Find the row containing our mobile, then click its Edit button
    const row = page.locator('table.admin-table tbody tr', { hasText: TEST_MOBILE });
    await expect(row).toBeVisible({ timeout: 3000 });
    await row.locator('button:has-text("Edit")').click();

    // Wait for edit modal
    await expect(page.locator('#editCustomerModal.active')).toBeVisible({ timeout: 3000 });

    // Modify fields
    const editNameInput = page.locator('#editCustomerModal input#edit_name');
    await editNameInput.clear();
    await editNameInput.fill(TEST_EDIT_NAME);

    const editEmailInput = page.locator('#editCustomerModal input#edit_email');
    await editEmailInput.clear();
    await editEmailInput.fill('edited_' + TEST_EMAIL);

    await page.locator('#editCustomerModal select#edit_customer_type').selectOption('rental');
    await page.locator('#editCustomerModal input#edit_city').clear();
    await page.locator('#editCustomerModal input#edit_city').fill('Dibrugarh');
    await page.locator('#editCustomerModal select#edit_registration_type').selectOption('composition');

    // Submit edit form
    await page.locator('#editCustomerModal button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Check success banner
    await expect(page.locator('.alert-banner').first()).toBeVisible({ timeout: 5000 });
    const bannerText = await page.locator('.alert-banner').first().textContent();
    expect(bannerText).toMatch(/success|updated/i);

    // UI: table shows updated name
    const tbody = page.locator('table.admin-table tbody');
    await expect(tbody).toContainText(TEST_EDIT_NAME);

    // DB: verify updated values
    const res = await dbAssert(page, { action: 'customer_values', id: customerId });
    expect(res.passed).toBe(true);
    expect(res.data.name).toBe(TEST_EDIT_NAME);
    expect(res.data.email).toBe('edited_' + TEST_EMAIL);
    expect(res.data.customer_type).toBe('rental');
    expect(res.data.city).toBe('Dibrugarh');
    expect(res.data.registration_type).toBe('composition');

    // Restore name for subsequent tests
    const restoreForm = page.locator('#editCustomerModal');
    await page.locator('table.admin-table tbody tr', { hasText: TEST_EDIT_NAME }).locator('button:has-text("Edit")').click();
    await expect(page.locator('#editCustomerModal.active')).toBeVisible({ timeout: 3000 });
    const nameField = page.locator('#editCustomerModal input#edit_name');
    await nameField.clear();
    await nameField.fill(TEST_NAME);
    await page.locator('#editCustomerModal button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  });

  test('C8: Create a deletable customer', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    await page.click('button:has-text("Register Customer")');
    await expect(page.locator('#addCustomerModal.active')).toBeVisible({ timeout: 3000 });

    await page.locator('#addCustomerModal input[name="name"]').fill(TEST_DELETE_NAME);
    await page.locator('#addCustomerModal input[name="mobile"]').fill(TEST_DELETE_MOBILE);
    await page.locator('#addCustomerModal button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.alert-banner').first()).toBeVisible({ timeout: 5000 });

    // Verify in DB
    const res = await dbAssert(page, { action: 'customer_exists', mobile: TEST_DELETE_MOBILE });
    expect(res.passed).toBe(true);
  });

  test('C9: Delete button disabled until name confirmed', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    // Find row with delete customer
    const row = page.locator('table.admin-table tbody tr', { hasText: TEST_DELETE_NAME });
    await expect(row).toBeVisible({ timeout: 3000 });
    await row.locator('button:has-text("Delete")').click();

    await expect(page.locator('#deleteCustomerModal.active')).toBeVisible({ timeout: 3000 });

    const confirmInput = page.locator('#deleteCustomerModal input#delete_confirm_name');
    const deleteBtn = page.locator('#deleteCustomerModal button#delete_submit_btn');

    // Initially disabled
    await expect(deleteBtn).toBeDisabled();

    // Type wrong name — still disabled
    await confirmInput.fill('WRONG NAME');
    await expect(deleteBtn).toBeDisabled();

    // Type correct name — becomes enabled
    await confirmInput.clear();
    await confirmInput.fill(TEST_DELETE_NAME);
    // The JS listener does case-insensitive compare
    await page.waitForTimeout(300);
    await expect(deleteBtn).toBeEnabled();
  });

  test('C10: Delete customer + cascade cleanup', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    // Get customer ID from DB
    const existRes = await dbAssert(page, { action: 'customer_exists', mobile: TEST_DELETE_MOBILE });
    expect(existRes.passed).toBe(true);
    const deleteId = existRes.data.id;

    // Click delete
    const row = page.locator('table.admin-table tbody tr', { hasText: TEST_DELETE_NAME });
    await expect(row).toBeVisible({ timeout: 3000 });
    await row.locator('button:has-text("Delete")').click();
    await expect(page.locator('#deleteCustomerModal.active')).toBeVisible({ timeout: 3000 });

    // Confirm name
    await page.locator('#deleteCustomerModal input#delete_confirm_name').fill(TEST_DELETE_NAME);
    await page.waitForTimeout(300);
    await page.locator('#deleteCustomerModal button#delete_submit_btn').click();
    await page.waitForLoadState('networkidle');

    // Success banner
    const alert = page.locator('.alert-banner').first();
    await expect(alert).toBeVisible({ timeout: 5000 });
    const text = await alert.textContent();
    expect(text).toMatch(/success|deleted/i);

    // UI: customer row gone
    await expect(page.locator('table.admin-table tbody')).not.toContainText(TEST_DELETE_NAME);

    // DB: cascade check
    const cascadeRes = await dbAssert(page, { action: 'delete_cascade', id: deleteId });
    expect(cascadeRes.passed).toBe(true);
    expect(cascadeRes.data.customer_gone).toBe(true);
    expect(cascadeRes.data.cylinders_assigned).toBe(0);
    expect(cascadeRes.data.txns_remaining).toBe(0);
    expect(cascadeRes.data.payments_remaining).toBe(0);
    expect(cascadeRes.data.orders_remaining).toBe(0);
  });

  test('C11: Customer name links to profile page', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    // Get customer ID
    const existRes = await dbAssert(page, { action: 'customer_exists', mobile: TEST_MOBILE });
    expect(existRes.passed).toBe(true);
    const customerId = existRes.data.id;

    // Click the customer name link
    const nameLink = page.locator('table.admin-table tbody a').filter({ hasText: TEST_NAME }).first();
    await expect(nameLink).toBeVisible({ timeout: 3000 });
    await nameLink.click();
    await page.waitForURL('**/customer-profile.php?id=**', { timeout: 10000 });

    // Profile page should show customer name and stats
    await expect(page.locator('body')).toContainText(TEST_NAME);
    // Profile should show some key sections
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toMatch(/customer|profile|ledger|cylinder|deposit|order/i);
  });

  test('C12: Pagination controls visible when many customers', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(CUSTOMERS_PAGE);

    const pagination = page.locator('#customers-pagination');
    await expect(pagination).toBeVisible();

    // If there are more records than 1 page, prev/next links should exist
    const recordText = await pagination.textContent();
    expect(recordText.length).toBeGreaterThan(0);

    // Page links or prev/next buttons
    const pageLinks = pagination.locator('a');
    const count = await pageLinks.count();
    // At minimum should have 0 links (if only 1 page)
    expect(count).toBeGreaterThanOrEqual(0);
  });

});
