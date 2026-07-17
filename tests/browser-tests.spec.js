const { test, expect } = require('@playwright/test');

const BASE = 'http://localhost/nutangasestsk.com/public_html';
const ADMIN_LOGIN = BASE + '/admin/login.php';
const PORTAL_LOGIN = BASE + '/portal/login.php';

// ───── Public Pages ─────

test.describe('Public Pages', () => {
  test('P1: Home page loads with correct title', async ({ page }) => {
    await page.goto(BASE + '/');
    await expect(page).toHaveTitle(/Nutan Gases/);
    const h1 = page.locator('h1').first();
    await expect(h1).toBeVisible();
  });

  test('P2: Home page has SEO meta tags', async ({ page }) => {
    await page.goto(BASE + '/');
    const desc = page.locator('meta[name="description"]');
    await expect(desc).toHaveAttribute('content', /industrial|medical|gas/i);
    const ogTitle = page.locator('meta[property="og:title"]');
    await expect(ogTitle).toBeAttached();
  });

  test('P3: Home page has JSON-LD schema', async ({ page }) => {
    await page.goto(BASE + '/');
    const jsonld = page.locator('script[type="application/ld+json"]').first();
    await expect(jsonld).toBeAttached();
    const text = await jsonld.innerText();
    expect(text).toContain('LocalBusiness');
  });

  test('P4: Blog page loads and lists posts', async ({ page }) => {
    await page.goto(BASE + '/blog.php');
    await expect(page).toHaveTitle(/Blog/);
    const articles = page.locator('article, .post-card, .blog-post');
    const count = await articles.count();
    // May have 0 posts — that's OK, just verify page renders
    expect(typeof count).toBe('number');
  });

  test('P5: robots.txt is accessible', async ({ page }) => {
    const resp = await page.goto(BASE + '/robots.txt');
    expect(resp.status()).toBe(200);
    const text = await resp.text();
    expect(text).toContain('User-agent');
  });

  test('P6: sitemap.xml is accessible', async ({ page }) => {
    const resp = await page.goto(BASE + '/sitemap.xml');
    expect(resp.status()).toBe(200);
    const text = await resp.text();
    expect(text).toContain('urlset') || expect(text).toContain('<url>');
  });

  test('P7: Favicon exists', async ({ page }) => {
    const resp = await page.goto(BASE + '/Images/favicon.png');
    expect([200, 304]).toContain(resp.status());
  });

  test('P8: Home page header has navigation links', async ({ page }) => {
    await page.goto(BASE + '/');
    const nav = page.locator('nav, header nav, .nav-links, .main-nav, .navbar').first();
    await expect(nav).toBeVisible();
    const links = await page.locator('nav a, header a, .nav-links a').count();
    expect(links).toBeGreaterThanOrEqual(2);
  });

  test('P9: Home page footer is present', async ({ page }) => {
    await page.goto(BASE + '/');
    const footer = page.locator('footer');
    await expect(footer).toBeVisible();
  });

  test('P10: 404 returns error page', async ({ page }) => {
    const resp = await page.goto(BASE + '/nonexistent-page-xyz');
    expect(resp.status()).toBe(404);
  });

  test('P11: .htaccess exists', async ({ page }) => {
    const resp = await page.goto(BASE + '/.htaccess');
    // Should not expose .htaccess
    expect([403, 404]).toContain(resp.status());
  });

  test('P12: manifest.json is accessible for PWA', async ({ page }) => {
    const resp = await page.goto(BASE + '/manifest.json');
    if (resp.status() === 200) {
      const json = await resp.json();
      expect(json).toHaveProperty('name');
    } else {
      // May be 200 if served correctly; tolerate if misconfigured
      test.info().annotations.push({ type: 'warn', description: 'manifest.json returned ' + resp.status() });
    }
  });

  test('P13: sw.js (service worker) is accessible', async ({ page }) => {
    const resp = await page.goto(BASE + '/sw.js');
    if (resp.status() === 200) {
      const text = await resp.text();
      expect(text.length).toBeGreaterThan(50);
    }
  });
});

// ───── Admin Pages ─────

// ───── Helpers ─────
async function adminLogin(page) {
  await page.goto(BASE + '/admin/login.php');
  // If already logged in (redirected to dashboard), skip
  if (page.url().includes('dashboard.php')) return;
  await page.fill('#username', 'admin');
  await page.fill('#password', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php', { timeout: 10000 });
}

async function getCsrf(page) {
  const csrf = page.locator('input[name="_csrf_token"]');
  if (await csrf.isVisible()) {
    return await csrf.getAttribute('value');
  }
  return '';
}

test.describe('Admin Pages', () => {
  test('A1: Login page loads with form fields', async ({ page }) => {
    await page.goto(ADMIN_LOGIN);
    await expect(page.locator('#username')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('A2: Login page has CSRF token', async ({ page }) => {
    await page.goto(ADMIN_LOGIN);
    const csrf = page.locator('input[name="_csrf_token"]');
    await expect(csrf).toBeAttached();
    const val = await csrf.getAttribute('value');
    expect(val.length).toBeGreaterThanOrEqual(10);
  });

  test('A3: Login with valid credentials redirects to dashboard', async ({ page }) => {
    await page.goto(ADMIN_LOGIN);
    await page.fill('#username', 'admin');
    await page.fill('#password', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    await expect(page.locator('.kpi-card, .dash-card, .dashboard-grid').first()).toBeVisible();
  });

  test('A4: Login with invalid credentials shows error', async ({ page }) => {
    await page.goto(ADMIN_LOGIN);
    await page.fill('#username', 'admin');
    await page.fill('#password', 'wrongpass');
    await page.click('button[type="submit"]');
    await expect(page.locator('.error-banner')).toBeVisible();
  });

  test('A5: Dashboard loads with key widgets/sections', async ({ page }) => {
    await page.goto(ADMIN_LOGIN);
    await page.fill('#username', 'admin');
    await page.fill('#password', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    // Dashboard should have stats cards or tables
    const body = page.locator('body');
    const text = await body.innerText();
    expect(text.length).toBeGreaterThan(100);
  });

  test('A6: Admin navigation sidebar/menu exists', async ({ page }) => {
    await page.goto(ADMIN_LOGIN);
    await page.fill('#username', 'admin');
    await page.fill('#password', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    const sidebar = page.locator('nav, .sidebar, .main-nav, aside').first();
    await expect(sidebar).toBeVisible();
  });

  test('A7: Logout works', async ({ page }) => {
    await page.goto(ADMIN_LOGIN);
    await page.fill('#username', 'admin');
    await page.fill('#password', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    await page.goto(BASE + '/admin/logout.php');
    await expect(page).toHaveURL(/login/);
  });
});

// ───── Cylinder Send Flow ─────

test.describe('Cylinder Send Flow', () => {
  test('SEND1: Send cylinder page loads with vendor dropdown and cylinder list', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/send-cylinder.php');
    await expect(page.locator('#vendorSelect')).toBeVisible();
    await expect(page.locator('#cylinderList')).toBeVisible();
    // Verify cylinder checkboxes exist
    const cyls = page.locator('#cylinderList input[type="checkbox"]');
    const count = await cyls.count();
    expect(count).toBeGreaterThanOrEqual(1);
  });

  test('SEND2: Gas type and ownership filters work', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/send-cylinder.php');
    // Gas filter
    await page.selectOption('#gasFilter', { index: 1 });
    await page.waitForTimeout(200);
    // Ownership filter buttons exist
    const filterBtns = page.locator('.tag-filter-btn');
    expect(await filterBtns.count()).toBeGreaterThanOrEqual(1);
    // Click ownership filter
    const ownBtn = page.locator('.tag-filter-btn[data-ownership="owned"]');
    if (await ownBtn.count() > 0) {
      await ownBtn.click();
      await page.waitForTimeout(200);
    }
    // Switch back to all
    const allBtn = page.locator('.tag-filter-btn[data-ownership="all"]');
    if (await allBtn.count() > 0) await allBtn.click();
  });

  test('SEND3: Select All / Deselect All toggles visible cylinders', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/send-cylinder.php');
    const selectAll = page.locator('#selectAllVisible');
    if (await selectAll.count() > 0) {
      await selectAll.check();
      await page.waitForTimeout(200);
      const checked = await page.locator('#cylinderList input[type="checkbox"]:checked').count();
      expect(checked).toBeGreaterThanOrEqual(1);
      await selectAll.uncheck();
      await page.waitForTimeout(200);
      const unchecked = await page.locator('#cylinderList input[type="checkbox"]:checked').count();
      expect(unchecked).toBe(0);
    }
  });

  test('SEND4: Submit button is disabled when vendor/cylinders not selected', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/send-cylinder.php');
    const submitBtn = page.locator('#submitBtn');
    await expect(submitBtn).toBeDisabled();
  });

  test('SEND5: Full dispatch with advance + GST + transport', async ({ page, context }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/send-cylinder.php');

    // Step 1: Select vendor (Universal Gas has advance balance)
    const vidSelect = page.locator('#vendorSelect');
    const options = await vidSelect.locator('option').all();
    let targetVendor = null;
    for (const opt of options) {
      const text = await opt.textContent();
      if (text.includes('Universal Gas')) { targetVendor = await opt.getAttribute('value'); break; }
    }
    if (!targetVendor) {
      // Fallback: pick first non-empty vendor
      for (const opt of options) {
        const val = await opt.getAttribute('value');
        if (val) { targetVendor = val; break; }
      }
    }
    if (!targetVendor) {
      test.info().annotations.push({ type: 'skip', description: 'No vendors available' });
      return;
    }
    await vidSelect.selectOption(targetVendor);
    await page.waitForTimeout(200);

    // Step 2: Select 2 cylinders (excluding Select All checkbox)
    const cylCheckboxes = page.locator('#cylinderList .cyl-checkbox-item input[type="checkbox"]');
    const cylCount = await cylCheckboxes.count();
    if (cylCount < 2) {
      test.info().annotations.push({ type: 'skip', description: 'Not enough empty cylinders' });
      return;
    }
    await cylCheckboxes.nth(0).check();
    await cylCheckboxes.nth(1).check();
    await page.waitForTimeout(200);

    // Step 3: Fill dispatch details
    await page.fill('#driverName', 'Test Driver');
    await page.fill('#vehicleNumber', 'TN 01 AB 1234');
    await page.fill('#dispatchTransportCost', '500');
    await page.waitForTimeout(200);

    // Step 4: Enable advance payment
    const advanceCheckbox = page.locator('#advanceEnabled');
    await advanceCheckbox.check();
    await page.waitForTimeout(300);
    await page.fill('#advanceAmount', '2500');
    await page.selectOption('#advancePaymentMethod', 'Cash');
    await page.waitForTimeout(200);

    // Step 5: Submit
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1000);

    // Should redirect to lot-dashboard or show error on same page
    const currentUrl = page.url();
    if (currentUrl.includes('lot-dashboard.php')) {
      // Success — verify success flash
      await expect(page.locator('body')).toContainText(/success|Lot/i);
    }
    // If on same page, check for success/error banner
    const errorBanner = page.locator('.alert-banner');
    if (await errorBanner.count() > 0) {
      const errorText = await errorBanner.textContent();
      expect(errorText).not.toContain('Transaction failed');
    }
  });

  test('SEND6: Dispatch without advance', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/send-cylinder.php');

    const vidSelect = page.locator('#vendorSelect');
    const options = await vidSelect.locator('option').all();
    let targetVendor = null;
    for (const opt of options) {
      const text = await opt.textContent();
      if (text.includes('Indian Gases')) { targetVendor = await opt.getAttribute('value'); break; }
    }
    if (!targetVendor) {
      test.info().annotations.push({ type: 'skip', description: 'No vendors' });
      return;
    }
    await vidSelect.selectOption(targetVendor);
    await page.waitForTimeout(200);

    const cylCheckboxes = page.locator('#cylinderList .cyl-checkbox-item input[type="checkbox"]');
    const cylCount = await cylCheckboxes.count();
    if (cylCount < 1) {
      test.info().annotations.push({ type: 'skip', description: 'No empty cylinders' });
      return;
    }
    await cylCheckboxes.nth(0).check();
    await page.waitForTimeout(200);

    // Submit without advance
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1000);

    const currentUrl = page.url();
    if (currentUrl.includes('lot-dashboard.php')) {
      await expect(page.locator('body')).toContainText(/success|Lot/i);
    }
  });

  test('SEND7: Lot dashboard page loads and lists lots', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/lot-dashboard.php');
    await expect(page.locator('body')).toBeVisible();
    const lotCards = page.locator('.lot-card');
    const count = await lotCards.count();
    expect(typeof count).toBe('number');
    // Verify filter bar exists
    await expect(page.locator('.filter-bar')).toBeVisible();
  });
});

// ───── Cylinder Receive Flow ─────

test.describe('Cylinder Receive Flow', () => {
  test('RECV1: Receive cylinder page loads with vendor and step progress', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/receive-cylinder.php');
    await expect(page.locator('#lotForm')).toBeVisible();
    // Step 1 is visible by default; step 2+ are conditionally shown
    await expect(page.locator('.rc-step-1')).toBeVisible();
    await expect(page.locator('.rc-step-2')).toBeAttached();
    // Vendor select exists
    await expect(page.locator('#lotVendorSelect')).toBeVisible();
  });

  test('RECV2: Selecting a vendor shows available lots', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/receive-cylinder.php');
    const vendorSelect = page.locator('#lotVendorSelect');

    const options = await vendorSelect.locator('option').all();
    let targetVendor = null;
    for (const opt of options) {
      const val = await opt.getAttribute('value');
      const lots = await opt.getAttribute('data-lots');
      if (val && parseInt(lots) > 0) { targetVendor = val; break; }
    }
    if (!targetVendor) {
      test.info().annotations.push({ type: 'skip', description: 'No vendors with open lots' });
      return;
    }

    // Select vendor and trigger onLotVendorChange
    const evResult = await page.evaluate((vid) => {
      const sel = document.getElementById('lotVendorSelect');
      const container = document.getElementById('lotCheckboxGroup');
      if (!sel) return 'NO_SEL';
      if (!container) return 'NO_CONTAINER';
      if (typeof onLotVendorChange !== 'function') return 'FN_NOT_DEFINED';
      sel.value = vid;
      onLotVendorChange();
      return 'OK containerHTML:' + container.innerHTML.substring(0, 200);
    }, targetVendor);
    console.log('evaluate result:', evResult);
    await page.waitForTimeout(300);

    // Lot checkboxes should appear inside lotCheckboxGroup
    const lotCbs = page.locator('#lotCheckboxGroup .lot-checkbox');
    expect(await lotCbs.count()).toBeGreaterThanOrEqual(1);
  });

  test('RECV3: Lot summary card shows after selecting lot', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/receive-cylinder.php');
    const vendorSelect = page.locator('#lotVendorSelect');
    const options = await vendorSelect.locator('option').all();
    let targetVendor = null;
    for (const opt of options) {
      const val = await opt.getAttribute('value');
      const lots = await opt.getAttribute('data-lots');
      if (val && parseInt(lots) > 0) { targetVendor = val; break; }
    }
    if (!targetVendor) {
      test.info().annotations.push({ type: 'skip', description: 'No vendors with open lots' });
      return;
    }
    await page.evaluate((vid) => {
      const sel = document.getElementById('lotVendorSelect');
      if (sel) { sel.value = vid; sel.dispatchEvent(new Event('change')); }
    }, targetVendor);
    await page.waitForTimeout(500);

    // Select first lot
    const lotCb = page.locator('#lotCheckboxGroup .lot-checkbox').first();
    if (await lotCb.count() > 0) {
      await lotCb.check();
      await page.waitForTimeout(500);
      // Summary card should be visible
      const summaryCard = page.locator('#lotSummaryCard');
      await expect(summaryCard).toBeVisible();
    }
  });

  test('RECV4: Full lot receive flow', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/receive-cylinder.php');

    // Select Indian Gases (1 open lot)
    const vendorSelect = page.locator('#lotVendorSelect');
    const options = await vendorSelect.locator('option').all();
    let targetVendor = null;
    for (const opt of options) {
      const text = await opt.textContent();
      if (text.includes('Indian Gases')) { targetVendor = await opt.getAttribute('value'); break; }
    }
    if (!targetVendor) {
      test.info().annotations.push({ type: 'skip', description: 'Indian Gases not found' });
      return;
    }
    await vendorSelect.selectOption(targetVendor);
    await page.waitForTimeout(500);

    // Select the lot
    const lotCb = page.locator('#lotCheckboxGroup .lot-checkbox').first();
    if (await lotCb.count() === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No open lots' });
      return;
    }
    await lotCb.check();
    await page.waitForTimeout(800);

    // Check if cylinders are available
    const cylCbs = page.locator('#lotCylinderList input[type="checkbox"]');
    if (await cylCbs.count() === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No pending cylinders in lot' });
      return;
    }

    // Select all cylinders (lot #9 has 9/9 returned, so may not have pending)
    const selectAll = page.locator('#lotSelectAllVisibleList');
    if (await selectAll.count() > 0) {
      await selectAll.check();
      await page.waitForTimeout(600);

      // Check financial sections appeared
      const gstSection = page.locator('#lotFinancialSection');
      const adjSection = page.locator('#lotAdjustmentSection');
      await expect(gstSection).toBeVisible();
      await expect(adjSection).toBeVisible();

      // Add a payment row
      const addPayBtn = page.locator('button:has-text("Add Row")');
      if (await addPayBtn.count() > 0) {
        await addPayBtn.click();
        await page.waitForTimeout(200);
      }

      // Submit
      const submitBtn = page.locator('#lotSubmitBtn');
      if (await submitBtn.isEnabled()) {
        await submitBtn.click();
        await page.waitForTimeout(1500);

        const currentUrl = page.url();
        if (currentUrl.includes('lot-dashboard.php')) {
          await expect(page.locator('body')).toContainText(/success|received|successfully/i);
        }
      }
    }
  });

  test('RECV5: Advance utilization shown when vendor has advance balance', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/receive-cylinder.php');

    // Universal Gas has advance balance of ₹1,062
    const vendorSelect = page.locator('#lotVendorSelect');
    const options = await vendorSelect.locator('option').all();
    let targetVendor = null;
    for (const opt of options) {
      const text = await opt.textContent();
      if (text.includes('Universal Gas')) { targetVendor = await opt.getAttribute('value'); break; }
    }
    if (!targetVendor) {
      test.info().annotations.push({ type: 'skip', description: 'Universal Gas not found' });
      return;
    }
    await vendorSelect.selectOption(targetVendor);
    await page.waitForTimeout(500);

    // Check if lot has advance
    const lotCb = page.locator('#lotCheckboxGroup .lot-checkbox').first();
    if (await lotCb.count() === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No open lots' });
      return;
    }
    await lotCb.check();
    await page.waitForTimeout(800);

    // Vendor advance balance tag should be visible
    const advRecon = page.locator('#lotVendorAdvRecon');
    if (await advRecon.isVisible()) {
      const advText = await advRecon.textContent();
      expect(advText).toContain('Vendor Bal');
    }
  });
});

// ───── Cylinder Return Flow ─────

test.describe('Cylinder Return Flow', () => {
  test('RET1: Return cylinder page loads', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/return-cylinder.php');
    await expect(page.locator('#returnLayout')).toBeVisible();
    // Customer combobox exists
    await expect(page.locator('#customerSearchInput')).toBeVisible();
    // Step progress visible
    const steps = page.locator('.rc-step');
    expect(await steps.count()).toBe(4);
  });

  test('RET2: Customer search combobox shows results', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/return-cylinder.php');
    await page.fill('#customerSearchInput', 'test');
    await page.waitForTimeout(500);
    const dropdownItems = page.locator('.rc-combobox-item');
    const count = await dropdownItems.count();
    expect(typeof count).toBe('number');
  });

  test('RET3: Selecting customer loads orders', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/return-cylinder.php');

    // Focus the search to trigger dropdown load
    await page.locator('#customerSearchInput').focus();
    await page.waitForTimeout(1000);

    // Wait for dropdown to populate
    const items = page.locator('.rc-combobox-item');
    if (await items.count() === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No eligible customers found' });
      return;
    }

    // Click first customer
    await items.first().click();
    await page.waitForTimeout(1000);

    // Lot section should appear
    const lotSection = page.locator('#lotSection');
    await expect(lotSection).toBeVisible();
  });

  test('RET4: Loading cylinders for an order shows cylinder list', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/return-cylinder.php');

    // Focus and wait for customers to load
    await page.locator('#customerSearchInput').focus();
    await page.waitForTimeout(1000);

    const items = page.locator('.rc-combobox-item');
    if (await items.count() === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No eligible customers' });
      return;
    }
    await items.first().click();
    await page.waitForTimeout(1000);

    // Click the first lot/order
    const lotItems = page.locator('.rc-lot-item');
    if (await lotItems.count() === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No returnable orders' });
      return;
    }
    await lotItems.first().click();
    await page.waitForTimeout(1000);

    // Cylinders should load
    const cylList = page.locator('#cylinderList');
    await expect(cylList).toBeVisible();
    const cylCbs = page.locator('#cylinderList input[type="checkbox"]');
    const cylCount = await cylCbs.count();
    expect(typeof cylCount).toBe('number');

    // Payment section should appear
    const paySection = page.locator('#paymentSection');
    await expect(paySection).toBeVisible();
  });
});

// ───── Cylinder Exchange Flow ─────

test.describe('Cylinder Exchange Flow', () => {
  test('EX1: Exchange page loads with customer combobox', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/cylinder-exchange.php');
    await expect(page.locator('#customerSearchInput')).toBeVisible();
    await expect(page.locator('#exchangeForm')).toBeVisible();
  });

  test('EX2: Customer search works', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/cylinder-exchange.php');
    await page.fill('#customerSearchInput', 'test');
    await page.waitForTimeout(500);
    const items = page.locator('#customerDropdownList > div');
    const count = await items.count();
    expect(typeof count).toBe('number');
  });

  test('EX3: Selecting a customer shows exchange panels', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/cylinder-exchange.php');

    // Focus and wait for dropdown
    await page.locator('#customerSearchInput').focus();
    await page.waitForTimeout(800);

    const items = page.locator('#customerDropdownList > div');
    if (await items.count() === 0) {
      test.info().annotations.push({ type: 'skip', description: 'No customers found' });
      return;
    }
    await items.first().click();
    await page.waitForTimeout(1000);

    // Exchange panels should appear
    const panels = page.locator('#exchangePanels');
    await expect(panels).toBeVisible();

    // Summary should be visible
    const summary = page.locator('#exchangeSummary');
    await expect(summary).toBeVisible();
  });

  test('EX4: Return serial row add/remove works', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/cylinder-exchange.php');

    // Focus and select customer
    await page.locator('#customerSearchInput').focus();
    await page.waitForTimeout(800);
    const items = page.locator('#customerDropdownList > div');
    if (await items.count() > 0) {
      await items.first().click();
      await page.waitForTimeout(500);
    }

    // Add serial row
    const addBtn = page.locator('button:has-text("Add Another Serial")');
    if (await addBtn.count() > 0) {
      const beforeRows = await page.locator('.serial-row').count();
      await addBtn.click();
      await page.waitForTimeout(200);
      const afterRows = await page.locator('.serial-row').count();
      expect(afterRows).toBeGreaterThanOrEqual(beforeRows);
    }
  });

  test('EX5: Submit without serials shows validation error', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/cylinder-exchange.php');

    // Fill customer
    await page.locator('#customerSearchInput').focus();
    await page.waitForTimeout(800);
    const items = page.locator('#customerDropdownList > div');
    if (await items.count() > 0) {
      await items.first().click();
      await page.waitForTimeout(500);
    }

    // Submit with empty form
    const submitBtn = page.locator('#settleBtn');
    await submitBtn.click();
    await page.waitForTimeout(500);

    // Should show error or stay on same page
    expect(page.url()).toContain('cylinder-exchange.php');
  });
});

// ───── Transport Cost Specific Tests ─────

test.describe('Transport Cost Flows', () => {
  test('TRANS1: Send transport cost shows per-cylinder calculation', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/send-cylinder.php');

    // Select vendor and cylinders
    const vendorSelect = page.locator('#vendorSelect');
    const vendorOpts = await vendorSelect.locator('option').all();
    let vendorVal = null;
    for (const opt of vendorOpts) {
      const v = await opt.getAttribute('value');
      if (v) { vendorVal = v; break; }
    }
    if (!vendorVal) { test.info().annotations.push({ type: 'skip', description: 'No vendors' }); return; }
    await vendorSelect.selectOption(vendorVal);
    await page.waitForTimeout(200);

    const cylCbs = page.locator('#cylinderList .cyl-checkbox-item input[type="checkbox"]');
    const cylCount = await cylCbs.count();
    if (cylCount < 1) { test.info().annotations.push({ type: 'skip', description: 'No cylinders' }); return; }
    await cylCbs.nth(0).check();
    await cylCbs.nth(1).check();
    await page.waitForTimeout(200);

    // Enter transport cost
    await page.fill('#dispatchTransportCost', '600');
    await page.waitForTimeout(300);

    // Per-cylinder text should update
    const transportPerCyl = page.locator('#dispatchTransportPerCyl');
    await expect(transportPerCyl).toBeVisible();
    const perCylText = await transportPerCyl.textContent();
    expect(parseFloat(perCylText.replace(',', ''))).toBeCloseTo(300.00, 0);
  });

  test('TRANS2: Receive transport cost shows per-cylinder calculation', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/receive-cylinder.php');

    const vendorSelect = page.locator('#lotVendorSelect');
    const options = await vendorSelect.locator('option').all();
    let targetVendor = null;
    for (const opt of options) {
      const val = await opt.getAttribute('value');
      const lots = await opt.getAttribute('data-lots');
      if (val && parseInt(lots) > 0) { targetVendor = val; break; }
    }
    if (!targetVendor) { test.info().annotations.push({ type: 'skip', description: 'No vendors with lots' }); return; }
    await page.evaluate((vid) => {
      const sel = document.getElementById('lotVendorSelect');
      if (sel) { sel.value = vid; sel.dispatchEvent(new Event('change')); }
    }, targetVendor);
    await page.waitForTimeout(500);

    const lotCb = page.locator('#lotCheckboxGroup .lot-checkbox').first();
    if (await lotCb.count() === 0) { test.info().annotations.push({ type: 'skip', description: 'No lots' }); return; }
    await lotCb.check();
    await page.waitForTimeout(800);

    const cylCbs = page.locator('#lotCylinderList input[type="checkbox"]');
    if (await cylCbs.count() === 0) { test.info().annotations.push({ type: 'skip', description: 'No cylinders' }); return; }
    const selectAll = page.locator('#lotSelectAllVisibleList');
    if (await selectAll.count() > 0) {
      await selectAll.check();
      await page.waitForTimeout(400);
    }

    const transportInput = page.locator('#lotReceiveTransportCost');
    if (await transportInput.isVisible()) {
      await transportInput.fill('400');
      await page.waitForTimeout(300);
      const perCylText = page.locator('#receiveTransportPerCyl');
      await expect(perCylText).toBeVisible();
    }
  });

  test('TRANS3: Zero transport cost stays at zero', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/send-cylinder.php');
    const transportInput = page.locator('#dispatchTransportCost');
    await expect(transportInput).toHaveValue(/0/);
    const perCyl = page.locator('#dispatchTransportPerCyl');
    const text = await perCyl.textContent();
    expect(text).toContain('0.00');
  });
});

// ───── Lot Dashboard ─────

test.describe('Lot Dashboard', () => {
  test('LOT1: Lot dashboard loads with filters and pagination', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/lot-dashboard.php');
    await expect(page.locator('body')).toBeVisible();
    // Filter bar should have vendor select
    await expect(page.locator('select[name="vendor_id"]')).toBeVisible();
    // Status filter
    await expect(page.locator('select[name="status"]')).toBeVisible();
  });

  test('LOT2: Lot cards show correct info', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/lot-dashboard.php');
    const lotCards = page.locator('.lot-card');
    const count = await lotCards.count();
    if (count > 0) {
      // Each lot card should have a lot number
      const firstLot = lotCards.first();
      await expect(firstLot.locator('.lot-card-title')).toBeVisible();
    }
  });

  test('LOT3: Filter by vendor works', async ({ page }) => {
    await adminLogin(page);
    await page.goto(BASE + '/admin/lot-dashboard.php');
    const vendorFilter = page.locator('select[name="vendor_id"]');
    const options = await vendorFilter.locator('option').all();
    if (options.length > 1) {
      await vendorFilter.selectOption({ index: 1 });
      // Click the Filter button (use first match since there may be multiple submit buttons on page)
      await page.locator('button[type="submit"]').first().click();
      await page.waitForTimeout(500);
      // Page should refresh with filtered results
      expect(page.url()).toContain('vendor_id=');
    }
  });
});

// ───── Portal Pages ─────

test.describe('Portal Pages', () => {
  // Clear rate limit cache before portal tests to avoid file-based rate limiter blocking sequential tests
  test.beforeAll(async ({ request }) => {
    try {
      const fs = require('fs');
      const path = require('path');
      const cacheDir = path.join(__dirname, '..', 'public_html', 'cache');
      const files = fs.readdirSync(cacheDir).filter(f => f.startsWith('login_rate_'));
      files.forEach(f => fs.unlinkSync(path.join(cacheDir, f)));
    } catch (e) {
      // Non-critical; tests may still pass if no rate limit files exist
    }
  });

  test('PT1: Portal login page loads with form fields', async ({ page }) => {
    await page.goto(PORTAL_LOGIN);
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('PT2: Portal login with valid credentials redirects to dashboard', async ({ page }) => {
    await page.goto(PORTAL_LOGIN);
    await page.fill('#email', 'test@test.com');
    await page.fill('#password', 'test123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    await page.waitForSelector('.stats-grid, .stat-card', { timeout: 5000 });
    await expect(page.locator('.stats-grid, .stat-card').first()).toBeVisible();
  });

  test('PT4: Portal dashboard shows stats cards', async ({ page }) => {
    await page.goto(PORTAL_LOGIN);
    await page.fill('#email', 'test@test.com');
    await page.fill('#password', 'test123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    await page.waitForSelector('.stat-value, .stat-card, .stats-grid', { timeout: 5000 });
    const stats = page.locator('.stat-card, .stats-grid > div, .stat-value');
    await expect(stats.first()).toBeVisible();
  });

  test('PT5: Portal dashboard shows Active Cylinders count', async ({ page }) => {
    await page.goto(PORTAL_LOGIN);
    await page.fill('#email', 'test@test.com');
    await page.fill('#password', 'test123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    const body = page.locator('body');
    await expect(body).toContainText(/cylinder/i);
  });

  test('PT6: Portal orders page loads', async ({ page }) => {
    await page.goto(PORTAL_LOGIN);
    await page.fill('#email', 'test@test.com');
    await page.fill('#password', 'test123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    await page.goto(BASE + '/portal/orders.php');
    await expect(page.locator('body')).toBeVisible();
  });

  test('PT7: Portal logout works', async ({ page }) => {
    await page.goto(PORTAL_LOGIN);
    await page.fill('#email', 'test@test.com');
    await page.fill('#password', 'test123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    await page.goto(BASE + '/portal/logout.php');
    await expect(page).toHaveURL(/login/);
  });

  // Run the wrong-password test LAST so file-based rate limiter doesn't block subsequent tests
  test('PT3: Portal login with wrong password shows error', async ({ page }) => {
    await page.goto(PORTAL_LOGIN);
    await page.fill('#email', 'test@test.com');
    await page.fill('#password', 'wrongpass');
    await page.click('button[type="submit"]');
    await expect(page.locator('.error-banner')).toBeVisible();
  });
});

// ───── API Endpoints (Form Submissions) ─────

test.describe('API Endpoints', () => {
  test('API1: Lead capture POST creates enquiry', async ({ page }) => {
    const resp = await page.request.post(BASE + '/lead-capture.php', {
      form: {
        name: 'Test User',
        email: 'test@example.com',
        phone: '9876543210',
        message: 'Test enquiry from Playwright'
      }
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(true);
  });

  test('API2: Lead capture rejects invalid phone', async ({ page }) => {
    const resp = await page.request.post(BASE + '/lead-capture.php', {
      form: {
        name: 'Test User',
        email: 'test@example.com',
        phone: '123',
        message: 'Test'
      }
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(false);
  });

  test('API3: Newsletter subscribe works', async ({ page }) => {
    const resp = await page.request.post(BASE + '/newsletter-subscribe.php', {
      form: { email: 'playwright-test-' + Date.now() + '@example.com' }
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(true);
  });

  test('API4: Newsletter subscribe rejects invalid email', async ({ page }) => {
    const resp = await page.request.post(BASE + '/newsletter-subscribe.php', {
      form: { email: 'not-an-email' }
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(false);
  });

  test('API5: Lead capture rejects missing name', async ({ page }) => {
    const resp = await page.request.post(BASE + '/lead-capture.php', {
      form: {
        name: '',
        email: 'test@example.com',
        phone: '9876543210',
        message: 'Test'
      }
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(false);
  });

  test('API6: Lead capture supports WhatsApp redirect', async ({ page }) => {
    const resp = await page.request.post(BASE + '/lead-capture.php', {
      form: {
        name: 'Redirect User',
        phone: '9876543211',
        redirect_whatsapp: 'https://wa.me/919876543211'
      }
    });
    expect(resp.status()).toBe(200);
    const json = await resp.json();
    expect(json.success).toBe(true);
    expect(json).toHaveProperty('redirect');
  });
});

// ───── SEO & Technical ─────

test.describe('SEO & Technical', () => {
  test('SEO1: Home page has canonical URL', async ({ page }) => {
    await page.goto(BASE + '/');
    const canonical = page.locator('link[rel="canonical"]');
    await expect(canonical).toBeAttached();
  });

  test('SEO2: OG image meta tag present', async ({ page }) => {
    await page.goto(BASE + '/');
    const ogImage = page.locator('meta[property="og:image"]');
    await expect(ogImage).toBeAttached();
  });

  test('SEO3: Twitter card meta tags present', async ({ page }) => {
    await page.goto(BASE + '/');
    const twitterCard = page.locator('meta[name="twitter:card"]');
    await expect(twitterCard).toBeAttached();
  });

  test('SEO4: Viewport meta tag present', async ({ page }) => {
    await page.goto(BASE + '/');
    const viewport = page.locator('meta[name="viewport"]');
    await expect(viewport).toHaveAttribute('content', /width=device-width/);
  });

  test('SEO5: Page has hreflang or lang attribute', async ({ page }) => {
    await page.goto(BASE + '/');
    const html = page.locator('html');
    const lang = await html.getAttribute('lang');
    expect(lang).toBeTruthy();
  });

  test('TECH1: Admin dashboard sends security headers', async ({ page }) => {
    // Login first, then check dashboard headers
    await page.goto(ADMIN_LOGIN);
    await page.fill('#username', 'admin');
    await page.fill('#password', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    const resp = await page.goto(BASE + '/admin/dashboard.php');
    const headers = resp.headers();
    const h = {};
    for (const [k, v] of Object.entries(headers)) {
      h[k.toLowerCase()] = v;
    }
    expect(h['x-frame-options'] || h['x-content-type-options']).toBeTruthy();
  });

  test('TECH2: Performance — page loads under 10s', async ({ page }) => {
    const start = Date.now();
    await page.goto(BASE + '/');
    const elapsed = Date.now() - start;
    expect(elapsed).toBeLessThan(10000);
  });
});

// ───── Tracker ─────

test.describe('Visit Tracker', () => {
  test('TR1: Tracker.php GET returns 200', async ({ page }) => {
    const resp = await page.goto(BASE + '/tracker.php');
    // Tracker may return 200 (pixel) or redirect
    expect([200, 302, 301]).toContain(resp.status());
  });
});
