import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Documents attached to a listed item (Phase 3 step 11): the Product page and the workspace's Documents tab, in both languages.
// Needs a DISPOSABLE stack where a PDF titled "Datasheet 6205" (fa «برگه داده», 1 KB) is linked to 6205 for both languages
// and published (see nova/docs/media.md). NOVA_MEDIA_E2E=1 and NOVA_SITE_PORT select it; never run it against the dev database.
const PORT = process.env.NOVA_SITE_PORT ?? '8091';
const HOST = { fa: `http://fa.nova.localhost:${PORT}`, en: `http://en.nova.localhost:${PORT}` };
const TITLE = { fa: 'برگه داده', en: 'Datasheet 6205' };
test.skip(process.env.NOVA_MEDIA_E2E !== '1', 'needs the disposable media stack: set NOVA_MEDIA_E2E=1');

for (const lang of ['fa', 'en']) {
  test.describe(`${lang} documents`, () => {
    test('the Product page lists the item’s document with its size, and is axe-clean', async ({ page }) => {
      await page.goto(HOST[lang] + '/p/6205/');
      const link = page.locator('.pp__docs a', { hasText: TITLE[lang] });
      await expect(link).toHaveCount(1);
      await expect(link).toHaveAttribute('href', /^\/media\/\d{4}\/[0-9a-f]{64}\.pdf$/);
      await expect(page.locator('.pp__docs')).toContainText('PDF · 1 KB');
      const axe = await new AxeBuilder({ page }).analyze();
      expect(axe.violations.map(v => v.id)).toEqual([]);
    });

    test('the workspace Documents tab lists it, and the file downloads as a PDF attachment', async ({ page }) => {
      const errors = [];
      page.on('pageerror', e => errors.push(e.message));
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.goto(HOST[lang] + '/');
      await page.fill('#q-hero', '6205');
      await page.locator('#q-hero').press('Enter');
      await expect(page.locator('#ws-code')).toHaveText('6205');
      await page.getByRole('tab').nth(2).click();
      const link = page.locator('.pp__docs-list a', { hasText: TITLE[lang] });
      await expect(link).toBeVisible();
      await expect(page.locator('.pp__docs-list')).toContainText('PDF · 1 KB');
      // Fetched by the page itself (Chrome resolves *.localhost; Node's resolver does not)
      const res = await page.evaluate(async href => { const r = await fetch(href); return { status: r.status, type: r.headers.get('content-type'), disposition: r.headers.get('content-disposition') }; }, await link.getAttribute('href'));
      expect(res).toEqual({ status: 200, type: 'application/pdf', disposition: 'attachment' });
      expect(errors).toEqual([]);
    });

    test('an item with no document keeps the empty state', async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.goto(HOST[lang] + '/');
      await page.fill('#q-hero', '22220 E');
      await page.locator('#q-hero').press('Enter');
      await expect(page.locator('#ws-code')).toContainText('22220');
      await page.getByRole('tab').nth(2).click();
      await expect(page.locator('.pp__docs-list')).toHaveCount(0);
    });
  });
}
