import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createHmac } from 'node:crypto';

// The Admin (Phase 3 step 9) in a real browser through Apache, with native PHP sessions.
// It runs only against a DISPOSABLE stack (its own database, docroot and storage), never the dev
// database: the run needs NOVA_ADMIN_URL plus the throwaway accounts created for that stack:
//   NOVA_E2E_OWNER_EMAIL / NOVA_E2E_OWNER_PASSWORD, NOVA_E2E_EDITOR_EMAIL / NOVA_E2E_EDITOR_PASSWORD.
// Credentials come from the environment of the run only; they are never written to files.
const BASE = process.env.NOVA_ADMIN_URL;
const OWNER = { email: process.env.NOVA_E2E_OWNER_EMAIL, password: process.env.NOVA_E2E_OWNER_PASSWORD };
const EDITOR = { email: process.env.NOVA_E2E_EDITOR_EMAIL, password: process.env.NOVA_E2E_EDITOR_PASSWORD };

test.skip(!BASE || !OWNER.password || !EDITOR.password, 'Admin e2e needs a disposable stack (see the comment at the top).');

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s), as the Owner's authenticator app computes it. */
function totp(base32, time = Date.now()) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of base32.replace(/\s+/g, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(time / 1000 / 30)));
  const hmac = createHmac('sha1', key).update(counter).digest();
  const offset = hmac[19] & 0x0f;
  const binary = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3];
  return String(binary % 1e6).padStart(6, '0');
}

function watchErrors(page) {
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  return errors;
}

async function axeClean(page) {
  const result = await new AxeBuilder({ page }).analyze();
  expect(result.violations.map(v => `${v.id}: ${v.nodes.map(n => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

async function signIn(page, user) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('#email', user.email);
  await page.fill('#password', user.password);
  await page.click('button[type="submit"]');
}

test.describe.serial('Owner: first sign-in with TOTP enrolment, then the Product slice', () => {
  let context;
  let page;
  let errors;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    page = await context.newPage();
    errors = watchErrors(page);
  });
  test.afterAll(async () => { await context.close(); });

  test('signed-out requests go to the sign-in page, which is Persian RTL, locked down and axe-clean', async () => {
    const response = await page.goto(`${BASE}/admin/`);
    expect(page.url()).toBe(`${BASE}/admin/login`);
    const headers = response.headers();
    expect(headers['content-security-policy']).toContain("script-src 'self'");
    expect(headers['content-security-policy']).not.toContain('unsafe-inline');
    expect(headers['x-frame-options']).toBe('DENY');
    expect(headers['cache-control']).toBe('no-store');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fa');
    await axeClean(page);
  });

  test('a wrong password fails generically', async () => {
    await signIn(page, { email: OWNER.email, password: 'definitely not it' });
    await expect(page.locator('[role="alert"]')).toContainText('ورود ناموفق بود');
    await expect(page.locator('#email')).toHaveValue(OWNER.email);
  });

  test('the Owner enrols an authenticator before anything else, then lands on the dashboard', async () => {
    await signIn(page, OWNER);
    await expect(page).toHaveURL(`${BASE}/admin/login/enroll`);
    const secret = (await page.locator('.secret').textContent()).trim();
    expect(secret.replace(/\s/g, '')).toMatch(/^[A-Z2-7]{32}$/);
    await axeClean(page);
    await page.fill('#code', '000000');
    await page.click('button[type="submit"]');
    await expect(page.locator('[role="alert"]')).toContainText('کد پذیرفته نشد');
    await page.fill('#code', totp(secret));
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/admin/`);
    await expect(page.locator('h1')).toHaveText('پیشخوان');
    await expect(page.locator('[role="status"]')).toContainText('ورود دومرحله‌ای فعال شد');

    const cookie = (await context.cookies()).find(c => c.name === 'nova_admin');
    expect(cookie).toBeTruthy();
    expect([cookie.httpOnly, cookie.secure, cookie.sameSite, cookie.path]).toEqual([true, true, 'Strict', '/admin']);
  });

  test('dashboard and product list render with real data, no console errors, axe-clean', async () => {
    errors.length = 0; // the deliberate 401s of the failed attempts above are logged by the browser
    await page.reload();
    await axeClean(page);
    await page.click('nav a[href="/admin/products"]');
    await expect(page.locator('h1')).toContainText('محصولات');
    await expect(page.locator('table.t tbody tr')).toHaveCount(13);
    await expect(page.locator('table.t')).toContainText('نمونه'); // fixtures stay labelled as samples
    await axeClean(page);
    await page.fill('#q', '۶۲۰۵');
    await page.click('form[role="search"] button');
    await expect(page.locator('table.t tbody tr')).toHaveCount(1);
    expect(errors).toEqual([]);
  });

  test('the editor saves a value with its own provenance and guards unsaved changes', async () => {
    await page.click('table.t a.code');
    await expect(page.locator('h1')).toContainText('6205');
    await axeClean(page);
    const row = page.locator('section', { hasText: 'ابعاد و داده‌ها' }).first().locator('tbody tr').first();
    const value = row.locator('input.val');
    const original = await value.inputValue();

    // Unsaved-change guard: leaving with an edit asks first.
    await value.fill('99');
    let asked = false;
    page.once('dialog', async d => { asked = d.type() === 'beforeunload'; await d.dismiss(); });
    await page.goto(`${BASE}/admin/products`).catch(() => {});
    expect(asked).toBe(true);

    await value.fill(original || '25');
    await row.locator('button[type="submit"]').first().click();
    await expect(page.locator('[role="status"]')).toContainText('ذخیره شد');
  });

  test('the draft preview uses the publish templates and is noindex', async () => {
    const [preview] = await Promise.all([context.waitForEvent('page'), page.click('.adm__head a[href$="/preview/fa"]')]);
    await preview.waitForLoadState();
    await expect(preview.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex');
    await expect(preview.locator('.proto')).toContainText('پیش‌نمایش پیش‌نویس');
    await preview.close();
  });

  test('a POST without the CSRF token is refused', async () => {
    const response = await page.request.post(`${BASE}/admin/products`, { form: { designation: 'QZ CSRF', family_id: '1' }, maxRedirects: 0 });
    expect(response.status()).toBe(403);
  });

  test('publishing from the Admin switches a new release live; the history offers rollback', async () => {
    await page.goto(`${BASE}/admin/publish`);
    await axeClean(page);
    page.once('dialog', d => d.accept());
    await page.click('form.pubbar button[type="submit"]');
    await expect(page.locator('[role="status"]')).toContainText('منتشر شد');
    await expect(page.locator('.flash .detail')).toHaveText(/^R-\d{8}-\d{6}-[0-9a-f]{4}$/);
    await expect(page.locator('table.t')).toContainText('زنده');
    await expect(page.locator('form[action="/admin/publish/rollback"]')).toHaveCount(1);
  });

  test('narrow screens reflow to one column without horizontal scrolling', async () => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(`${BASE}/admin/products`);
    const editor = await page.locator('table.t a.code').first().getAttribute('href');
    for (const path of ['/admin/', '/admin/products', editor, '/admin/publish']) {
      await page.goto(`${BASE}${path}`);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), path).toBe(true);
    }
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  test('signing out ends the session', async () => {
    await page.click('.adm__user button[type="submit"]');
    await expect(page).toHaveURL(`${BASE}/admin/login`);
    await page.goto(`${BASE}/admin/`);
    await expect(page).toHaveURL(`${BASE}/admin/login`);
    expect(errors).toEqual([]);
  });
});

test('an Editor signs in with a password and is refused Owner-only actions', async ({ page }) => {
  const errors = watchErrors(page);
  await signIn(page, EDITOR);
  await expect(page).toHaveURL(`${BASE}/admin/`);
  await page.goto(`${BASE}/admin/products`);
  await page.click('table.t a.code');
  await expect(page.locator('form[action$="/verify"]')).toHaveCount(0);
  await expect(page.locator('form[action$="/variants"]')).toHaveCount(0);
  const token = await page.locator('input[name="_csrf"]').first().getAttribute('value');
  const productUrl = page.url();
  const refused = await page.request.post(`${productUrl}/verify`, { form: { _csrf: token, version: '1' }, maxRedirects: 0 });
  expect(refused.status()).toBe(403);
  expect(errors).toEqual([]);
});
