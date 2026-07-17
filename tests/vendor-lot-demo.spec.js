const { test, expect } = require('@playwright/test');

const BASE = 'http://localhost/nutangasestsk.com/public_html';
const ADMIN_BASE = BASE + '/admin';

test.describe('Vendor Lot Advance Demo', () => {

  test('Send lot with advance, then receive and check remaining', async ({ page }) => {
    test.setTimeout(120000);

    // ── 1. Admin Login ──
    console.log('1. Logging in as admin...');
    await page.goto(ADMIN_BASE + '/login.php');
    await page.waitForLoadState('networkidle');
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard.php', { timeout: 10000 });
    console.log('   Logged in OK');

    // ── 2. Go to Send Cylinders page ──
    console.log('2. Navigating to Send Cylinders...');
    await page.goto(ADMIN_BASE + '/send-cylinder.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    // ── 3. Select vendor ──
    console.log('3. Selecting vendor...');
    await page.selectOption('select[name="vendor_id"]', '9992');
    await page.waitForTimeout(500);

    // ── 4. Select cylinders ──
    console.log('4. Selecting all visible cylinders...');
    const selectAll = page.locator('#selectAllVisible');
    await selectAll.check();
    await page.waitForTimeout(300);

    let selectedText = await page.locator('#selectedCount').textContent();
    console.log(`   Selected: ${selectedText}`);

    // ── 5. Enter dispatch details ──
    console.log('5. Entering dispatch details...');

    // Set transport cost to 0
    const transportInput = page.locator('input[name="dispatch_transport_cost"]');
    if (await transportInput.count() > 0) {
      await transportInput.fill('0');
    }

    // Enable advance payment - click the toggle
    const advToggle = page.locator('#advanceToggle, input[name="advance_enabled"]');
    if (await advToggle.count() > 0) {
      const isCheckbox = await advToggle.getAttribute('type');
      if (isCheckbox === 'checkbox') {
        await advToggle.check();
      } else {
        await advToggle.click();
      }
      await page.waitForTimeout(300);
    }

    // Enter advance amount
    const advInput = page.locator('input[name="advance_amount"]');
    if (await advInput.count() > 0) {
      await advInput.fill('200');
      console.log('   Advance amount: ₹200');

      // Select payment method
      const payMethod = page.locator('select[name="advance_payment_method"]');
      if (await payMethod.count() > 0) {
        await payMethod.selectOption('Bank Transfer');
      }
    } else {
      console.log('   WARNING: Advance input not found');
    }

    // Set refill cost on each cylinder
    const costInputs = page.locator('input[name*="refill_cost"]');
    const costCount = await costInputs.count();
    for (let i = 0; i < costCount; i++) {
      await costInputs.nth(i).fill('333.33');
    }
    console.log(`   Set refill cost ₹333.33 on ${costCount} cylinders`);

    await page.waitForTimeout(500);

    // ── 6. Submit dispatch ──
    console.log('6. Dispatching lot...');
    const submitBtn = page.locator('button[type="submit"]').last();
    await submitBtn.click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);

    // Check current URL - should be lot-dashboard or something
    console.log(`   Current URL: ${page.url()}`);

    // ── 7. Go to Lot Dashboard to see the lot ──
    console.log('7. Checking lot dashboard...');
    await page.goto(ADMIN_BASE + '/lot-dashboard.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    // ── 8. Now go to Receive Cylinders page ──
    console.log('8. Navigating to Receive Cylinders...');
    await page.goto(ADMIN_BASE + '/receive-cylinder.php');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    // ── 9. Select vendor ──
    console.log('9. Selecting vendor on receive page...');
    await page.selectOption('select[name="vendor_id"]', '9992');
    await page.waitForTimeout(800);

    // ── 10. Select the lot ──
    console.log('10. Selecting lot...');
    const lotCheckboxes = page.locator('#lotCheckboxGroup input[type="checkbox"]');
    const lotCount = await lotCheckboxes.count();
    console.log(`    Found ${lotCount} lots`);

    if (lotCount > 0) {
      await lotCheckboxes.first().check();
      await page.waitForTimeout(1000);
    }

    // ── 11. Select all cylinders to receive ──
    console.log('11. Selecting cylinders to receive...');
    const cylCheckboxes = page.locator('#lotCylinderList input[type="checkbox"]');
    const cylCheckboxCount = await cylCheckboxes.count();
    console.log(`    Found ${cylCheckboxCount} cylinders`);

    if (cylCheckboxCount > 0) {
      // Use the select all
      const selectAllReceive = page.locator('#lotSelectAllVisibleList, #lotSelectAllVisible');
      if (await selectAllReceive.count() > 0) {
        await selectAllReceive.check();
      } else {
        for (const cb of await cylCheckboxes.all()) {
          await cb.check();
        }
      }
      await page.waitForTimeout(1000);
    }

    // ── 12. Read the summary ──
    console.log('12. Reading payment summary...');

    // Take a screenshot so user can see
    await page.screenshot({ path: 'receive-summary.png', fullPage: true });
    console.log('   Screenshot saved to receive-summary.png');

    // Read the summary values
    const sumTaxable = await page.locator('#lotSumTaxable').textContent();
    console.log(`   Refill Amount: ${sumTaxable}`);

    const sumGross = await page.locator('#lotSumGross').textContent();
    console.log(`   Total Invoice: ${sumGross}`);

    const sumAdvance = await page.locator('#lotSumAdvance').textContent();
    console.log(`   Advance Paid: ${sumAdvance}`);

    const sumNet = await page.locator('#lotSumNet').textContent();
    console.log(`   Remaining Due: ${sumNet}`);

    // ── 13. Take a full page screenshot for the user ──
    await page.screenshot({ path: 'final-state.png', fullPage: true });
    console.log('   Final screenshot saved to final-state.png');

  });

});
