import { defineConfig } from '@playwright/test';

// Browser tests run against the local Docker stack (`docker compose up -d`).
// PW_CHANNEL defaults to the installed Google Chrome, so no browser download
// is needed on the workstation; set PW_CHANNEL=chromium where Playwright's
// own browser is installed.
export default defineConfig({
  testDir: 'tests/e2e',
  reporter: 'list',
  use: {
    baseURL: process.env.NOVA_BASE_URL ?? 'http://localhost:8080',
    channel: process.env.PW_CHANNEL ?? 'chrome',
  },
});
