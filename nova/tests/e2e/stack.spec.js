import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Step 1 smoke checks for the local stack: Apache + PHP, .htaccess handling,
// the database and the mail catcher, plus the axe toolchain.

test('Apache serves PHP and reaches the database and mail catcher', async ({ page }) => {
  const response = await page.goto('/_dev/');
  expect(response?.status()).toBe(200);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Nova local environment');
  await expect(page.getByTestId('php-status')).toHaveText('ok');
  await expect(page.getByTestId('db-status')).toHaveText('ok');
  await expect(page.getByTestId('mail-status')).toHaveText('ok');

  const axe = await new AxeBuilder({ page }).analyze();
  expect(axe.violations).toEqual([]);
});

test('.htaccess rewrite rules are applied', async ({ request }) => {
  const response = await request.get('/_dev/rewrite-check');
  expect(response.status()).toBe(200);
  expect(await response.text()).toBe('rewrite ok');
  expect(response.headers()['x-robots-tag']).toBe('noindex, nofollow');
});

test('the docroot .htaccess overrides the permissive server defaults', async ({ request }) => {
  // The vhost allows directory listing; the docroot .htaccess must switch it off.
  const root = await request.get('/');
  expect(root.status()).toBe(403);
  expect(root.headers()['x-content-type-options']).toBe('nosniff');

  expect((await request.get('/.htaccess')).status()).toBe(403);
});

test('the server does not advertise PHP or version details', async ({ request }) => {
  const response = await request.get('/_dev/rewrite-check');
  expect(response.headers()['x-powered-by']).toBeUndefined();
  expect(response.headers()['server']).toBe('Apache');
});
