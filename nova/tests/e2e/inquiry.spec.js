import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// The inquiry endpoint and form against a DISPOSABLE stack (Phase 3 step 10). It stores inquiries, so it runs only when
// NOVA_INQUIRY_E2E=1 and NOVA_SITE_PORT points at a stack with its own database and `dev:e2e` content, with the mail
// catcher as the SMTP server. Never run it against the owner's development database.
const PORT = process.env.NOVA_SITE_PORT ?? '8090';
const HOST = { fa: `http://fa.nova.localhost:${PORT}`, en: `http://en.nova.localhost:${PORT}` };
test.skip(process.env.NOVA_INQUIRY_E2E !== '1', 'writes inquiries: set NOVA_INQUIRY_E2E=1 on a disposable stack');
test.describe.configure({ mode: 'serial' });

const fillForm = async (page, v) => {
  for (const [id, value] of Object.entries(v)) await page.fill(`#f-${id}`, value);
};

for (const lang of ['fa', 'en']) {
  test.describe(`${lang} inquiry form`, () => {
    test.beforeEach(async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(HOST[lang] + '/#contact');
    });

    test('with JavaScript: field errors keep the entered values, then the send succeeds', async ({ page }) => {
      const errors = [];
      page.on('pageerror', e => errors.push(e.message));
      await fillForm(page, { code: '6205-2RS', msg: 'x' });
      await page.locator('#inquiry button[type="submit"]').click();
      await expect(page.locator('#f-name-err')).toBeVisible();
      await expect(page.locator('#f-name')).toBeFocused();
      await expect(page.locator('#f-code')).toHaveValue('6205-2RS');

      // The server's own validation is shown beside the field too (the page's check passes, the phone is not a number).
      await fillForm(page, { name: 'Test Visitor', phone: 'abc' });
      await page.locator('#inquiry button[type="submit"]').click();
      await expect(page.locator('#f-phone-err')).toBeVisible({ timeout: 20000 }); // the fast fill waits out the minimum fill time
      await expect(page.locator('#form-err')).toBeVisible();
      await expect(page.locator('#f-code')).toHaveValue('6205-2RS');
      await expect(page.locator('#f-msg')).toHaveValue('x');

      await fillForm(page, { phone: '0912 000 0000' });
      const response = page.waitForResponse(r => r.url().includes('/api/inquiry.php') && r.request().method() === 'POST' && r.status() === 201, { timeout: 30000 });
      await page.locator('#inquiry button[type="submit"]').click();
      await response;
      await expect(page.locator('#form-ok')).toBeVisible();
      await expect(page.locator('#f-code')).toHaveValue('');
      expect(errors).toEqual([]);
    });

    test('the page axe-checks clean with the form errors shown', async ({ page }) => {
      await page.locator('#inquiry button[type="submit"]').click();
      const axe = await new AxeBuilder({ page }).include('#inquiry').analyze();
      expect(axe.violations.map(v => v.id)).toEqual([]);
    });

    test('without JavaScript: errors refill the form, the first send is confirmed, then it is received', async ({ browser }) => {
      const context = await browser.newContext({ javaScriptEnabled: false });
      const page = await context.newPage();
      await page.goto(HOST[lang] + '/#contact');
      await fillForm(page, { code: '  6205-2rs/C3 ', msg: 'no script' });
      await page.locator('#inquiry button[type="submit"]').click();

      // No token (a static page cannot hold one) and a missing name: the endpoint returns the form with everything kept.
      await expect(page).toHaveURL(/\/api\/inquiry\.php$/);
      await expect(page.locator('html')).toHaveAttribute('dir', lang === 'fa' ? 'rtl' : 'ltr');
      await expect(page.locator('#f-code')).toHaveValue('6205-2rs/C3');
      await expect(page.locator('#f-msg')).toHaveValue('no script');
      await fillForm(page, { name: 'Test Visitor', phone: '+98 912 000 0000' });
      await page.waitForTimeout(3500); // the minimum fill time counts from the token on this page
      await page.locator('#inquiry button[type="submit"]').click();
      await expect(page).toHaveURL(/inquiry\.php\?sent=1$/);
      await expect(page.locator('.form__ok')).toBeVisible();
      await context.close();
    });

    test('the received page axe-checks clean', async ({ page }) => {
      await page.goto(HOST[lang] + '/api/inquiry.php?sent=1');
      await expect(page.locator('.form__ok')).toBeVisible();
      const axe = await new AxeBuilder({ page }).analyze();
      expect(axe.violations.map(v => v.id)).toEqual([]);
    });
  });
}

test('a cross-site POST and a honeypot fill are refused and store nothing', async ({ request }) => {
  const url = HOST.fa + '/api/inquiry.php';
  const form = { name: 'X', phone: '09120000000', code: '6205', token: 'x' };
  expect((await request.post(url, { form, headers: { 'Sec-Fetch-Site': 'cross-site', Accept: 'application/json' } })).status()).toBe(403);
  expect((await request.post(url, { form: { ...form, website: 'spam' }, headers: { 'Sec-Fetch-Site': 'same-origin', Accept: 'application/json' } })).status()).toBe(400);
  expect((await request.get(url + '?token=1')).headers()['cache-control']).toContain('no-store');
});
