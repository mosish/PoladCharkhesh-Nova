import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// The ported public site (Phase 3 step 7) against the dev:e2e build: the labelled Phase 2 SAMPLE plus the
// SYNTHETIC "QZ" designations (invented; they exist only to reach every match category in a browser).
// Prerequisite: `docker compose exec web php app/bin/nova dev:e2e`.
// NOVA_SITE_PORT points the suite at another stack (e.g. a disposable verification stack); default the dev stack.
const PORT = process.env.NOVA_SITE_PORT ?? '8080';
const HOST = { fa: `http://fa.nova.localhost:${PORT}`, en: `http://en.nova.localhost:${PORT}` };

async function open(page, lang, path = '/') {
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(HOST[lang] + path);
  return errors;
}

async function search(page, q) {
  await page.fill('#q-hero', q);
  await page.locator('#q-hero').press('Enter');
}

const ws = page => ({
  code: page.locator('#ws-code'),
  match: page.locator('#ws-match'),
  wa: page.locator('#ws-wa'),
  pressed: page.locator('.ws__items button[aria-pressed="true"]'),
});
const waText = async loc => decodeURIComponent((await loc.getAttribute('href')).split('text=')[1]);

for (const lang of ['fa', 'en']) {
  test.describe(`${lang} one-page site`, () => {
    test('renders without errors, in the right direction, with axe-clean markup', async ({ page }) => {
      const errors = await open(page, lang);
      await expect(page.locator('html')).toHaveAttribute('dir', lang === 'fa' ? 'rtl' : 'ltr');
      await expect(page.locator('html')).toHaveClass(/(^|\s)js(\s|$)/);
      await expect(page.locator('#results tbody tr')).toHaveCount(16);
      await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex');
      await expect(page.locator('.proto')).toBeVisible(); // development build banner: samples are labelled
      const axe = await new AxeBuilder({ page }).analyze();
      expect(axe.violations.map(v => v.id)).toEqual([]);
      expect(errors).toEqual([]);
    });

    test('exact Product: no qualifier, base item selected', async ({ page }) => {
      await open(page, lang);
      await search(page, '6205');
      const w = ws(page);
      await expect(w.code).toHaveText('6205');
      await expect(w.match).toBeHidden();
      await expect(w.pressed).toHaveText('6205');
    });

    test('exact variant: its own item is selected and named, request kept for the form', async ({ page }) => {
      await open(page, lang);
      await search(page, '6205 2rs');
      const w = ws(page);
      await expect(w.code).toHaveText('6205-2RS');
      await expect(w.pressed).toHaveText('6205-2RS');
      await expect(w.match).toBeHidden();
      expect(await waText(w.wa)).toContain('6205-2RS');
      await page.click('[data-act="inq"]');
      await expect(page.locator('#f-code')).toHaveValue('6205 2rs');
      await expect(page.locator('#f-match')).toHaveValue('exact_variant');
    });

    test('selecting another listed item updates the header and every CTA', async ({ page }) => {
      await open(page, lang);
      await search(page, '6205');
      await page.locator('.ws__items button', { hasText: '6205-ZZ' }).click();
      const w = ws(page);
      await expect(w.code).toHaveText('6205-ZZ');
      expect(await waText(w.wa)).toContain('6205-ZZ');
    });

    test('spelling: resolved to its target with "the catalogue writes it as"', async ({ page }) => {
      await open(page, lang);
      await search(page, 'qz 200 kay kay');
      const w = ws(page);
      await expect(w.code).toHaveText('QZ 200-KK');
      await expect(w.match).toBeVisible();
      await expect(w.match).toContainText('qz 200 kay kay');
      await expect(w.match).toContainText('QZ 200-KK');
      expect(await waText(w.wa)).toContain('qz 200 kay kay');
    });

    test('unlisted variant: explained, never presented as listed', async ({ page }) => {
      await open(page, lang);
      await search(page, 'QZ 200-K2');
      await expect(page.locator('#ask')).toBeVisible();
      await expect(page.locator('#ask-t')).toHaveText(lang === 'fa' ? /نوع شناخته‌شده/ : /Known variant, not currently listed/);
      await page.click('#ask-open');
      const w = ws(page);
      await expect(w.match).toContainText('QZ 200-K2');
      await expect(page.locator('.ws__items button', { hasText: 'QZ 200-K2' })).toHaveCount(0);
      expect(await waText(w.wa)).toContain('QZ 200-K2');
    });

    test('related with one listed item keeps the T-1 wording and roles', async ({ page }) => {
      await open(page, lang);
      await search(page, 'QZ 500');
      await expect(page.locator('#ask-t')).toHaveText(lang === 'fa' ? 'تطبیق مرتبط — کد درخواستی نیاز به تأیید دارد' : 'Related match — requested designation requires confirmation');
      const p = await page.locator('#ask-p').textContent();
      expect(p).toContain('QZ 500 E');
      expect(p).not.toMatch(lang === 'fa' ? /سری پایه/ : /base series/);
    });

    test('related with several and with zero listed items', async ({ page }) => {
      await open(page, lang);
      await search(page, 'QZ 200');
      const many = await page.locator('#ask-p').textContent();
      expect(many).toContain('QZ 200-K1');
      expect(many).toContain('QZ 200-KK');
      expect(many).not.toContain('QZ 200-K2');
      await search(page, 'QZ 300');
      await expect(page.locator('#ask-p')).toHaveText(lang === 'fa' ? /هیچ‌یک از نوع‌های آن/ : /none of its variants is listed/);
    });

    test('no relationship is inferred: 6305 is not related to the standalone 6305-2RS', async ({ page }) => {
      await open(page, lang);
      await search(page, '6305');
      await expect(page.locator('#ask')).toHaveClass(/ask--soft/); // partial results only
      await expect(page.locator('#ask-open')).toBeHidden();
      await expect(page.locator('#results tbody tr:not([hidden])')).toHaveCount(1);
    });

    test('unknown code: kept verbatim and handed to a specialist', async ({ page }) => {
      await open(page, lang);
      await search(page, 'ZZ-9999/X');
      await expect(page.locator('#ask-t')).toContainText('ZZ-9999/X');
      expect(await waText(page.locator('#ask-wa'))).toContain('ZZ-9999/X');
    });

    test('copy link gives the canonical Product deep link', async ({ page, context }) => {
      await context.grantPermissions(['clipboard-read', 'clipboard-write']);
      await open(page, lang);
      await search(page, '6205 2rs');
      await page.click('[data-act="link"]');
      await expect.poll(() => page.evaluate(() => navigator.clipboard.readText())).toBe(`${HOST[lang]}/p/6205/#v-6205-2rs`);
    });
  });

  test.describe(`${lang} Product page`, () => {
    test('presents each listed item distinctly with its own values; axe-clean', async ({ page }) => {
      const errors = await open(page, lang, '/p/6205/');
      await expect(page.locator('h1')).toHaveText('6205');
      await expect(page.locator('.pp__item')).toHaveCount(3);
      await expect(page.locator('#base .readout b').first()).toContainText('25');
      await expect(page.locator('#v-6205-2rs .readout')).toHaveCount(0); // nothing copied from the base
      await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${HOST[lang]}/p/6205/`);
      await expect(page.locator('link[hreflang="x-default"]')).toHaveAttribute('href', `${HOST.en}/p/6205/`);
      const axe = await new AxeBuilder({ page }).analyze();
      expect(axe.violations.map(v => v.id)).toEqual([]);
      expect(errors).toEqual([]);
    });

    test('?r= explains the request without changing the page identity', async ({ page }) => {
      await open(page, lang, '/p/qz-200/?r=' + encodeURIComponent('QZ 200-K2'));
      await expect(page.locator('#pp-match')).toBeVisible();
      await expect(page.locator('#pp-match')).toContainText('QZ 200-K2');
      await expect(page.locator('h1')).toHaveText('QZ 200');
    });

    test('zero listed items: a clear statement and a CTA', async ({ page }) => {
      await open(page, lang, '/p/qz-300/');
      await expect(page.locator('.pp__none')).toContainText('QZ 300');
      await expect(page.locator('.pp__item')).toHaveCount(0);
    });

    test('works without JavaScript', async ({ browser }) => {
      const context = await browser.newContext({ javaScriptEnabled: false });
      const page = await context.newPage();
      await page.goto(HOST[lang] + '/p/6205/');
      await expect(page.locator('.pp__item')).toHaveCount(3);
      await expect(page.locator('#base .spec tbody tr')).toHaveCount(3);
      await context.close();
    });

    test('language switch keeps the path; unknown paths get the 404 page', async ({ page }) => {
      await open(page, lang, '/p/6205/');
      const other = lang === 'fa' ? 'en' : 'fa';
      await expect(page.locator('.bar__tools .lang')).toHaveAttribute('href', `${HOST[other]}/p/6205/`);
      const response = await page.goto(HOST[lang] + '/p/does-not-exist/');
      expect(response.status()).toBe(404);
      await expect(page.locator('#nf-h')).toBeVisible();
    });
  });
}
