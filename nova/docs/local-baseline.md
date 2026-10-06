# Local development baseline (D25)

Recorded 2026-10-06 in Phase 3 step 1. This file is the local form of `05` §24 step 2. The production PHP/MariaDB minimums and the release-switch strategy are frozen only after hosting is purchased and probed (D26).

## Pinned images

| Service | Image (tag pinned in repo) | Registry digest at selection |
| --- | --- | --- |
| web (base) | `php:8.4.26-apache-trixie` | `sha256:4e890332d4fc1290af01b9ee71a7e1e4753e475a3fe0e3af36ea763b43c92c07` |
| web (Composer binary) | `composer:2.10.3` | `sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac` |
| db | `mariadb:11.4.13-noble` | `sha256:1292844148b311e4ed4300022a996d39083f415a963e970cf47cad1b3b18e3a6` |
| mail | `axllent/mailpit:v1.31.4` | `sha256:b68349e3a014b90c5610bfb26b2ae36f3892d7b8cf25ee140c6c71c98d2fcf48` |

## What runs

**Web container:**
- **Base:** PHP 8.4.26 with mod_php, Apache 2.4.68, Debian 13 (trixie).
- **Libraries:** ICU 76.1, PHP time-zone database 2026.3.
- **Extensions added:** intl, gd (JPEG, PNG, WebP, FreeType), zip, pdo_mysql, exif, opcache.
- **Extensions already in the image:** mbstring, fileinfo, zlib, openssl, json, ctype, session, filter, hash, curl, sodium, dom, libxml.
- **Apache modules:** rewrite, headers, expires.
- AVIF and Imagick are deliberately absent until the media pipeline (step 11) needs them.

**Database:**
- MariaDB 11.4.13 LTS. The `nova` (dev) and `nova_test` (integration tests) databases default to utf8mb4 / utf8mb4_unicode_520_ci.
- The application sets strict sql_mode on every connection (`05` §3).
- The SQL stays within D12's subset, so it also runs on 10.11 LTS.

**Composer settings:**
- `config.platform.php` is `8.4.26`, so dependencies resolve for exactly the PHP that is tested.
- When hosting is chosen, `composer check-platform-reqs` confirms the host meets the lock file.

**PHP development tools:**

| Tool | Version |
| --- | --- |
| PHPUnit | 13.4.1 |
| PHPStan | 2.3.0 (level max) |
| PHP-CS-Fixer | 3.95.27 (PER-CS) |

There are no runtime Composer packages yet. Twig, PHPMailer and the SVG sanitizer (E-3/E-7) are added in the steps that use them.

**Workstation:**

| Tool | Version |
| --- | --- |
| Node | 24.19.0 |
| npm | 11.17.0 |
| @playwright/test | 1.63.0 |
| @axe-core/playwright | 4.13.0 |
| Google Chrome (installed; Playwright channel) | 154.0.8037.97 |
| Docker | 29.8.2 |
| Docker Compose | 5.5.1 |

## Runbook

Run all commands from `nova/`.

| Task | Command |
| --- | --- |
| First time | `node tools/dev-init.mjs` (random local passwords into the gitignored `.env`), `docker compose up -d --build --wait`, `docker compose exec web composer install`, `npm ci` |
| All PHP checks | `docker compose exec web composer check` (CS, PHPStan, PHPUnit unit + integration) |
| Browser checks | `npm run test:e2e` (stack must be up) |
| Service URLs | web http://localhost:8080 · diagnostics http://localhost:8080/_dev/ · mail UI http://localhost:8025 |
| Reset | `docker compose down -v` deletes the dev and test databases |

**Updating a version:**
1. Change the tag in `docker/php/Dockerfile` or `compose.yaml`.
2. Update this file and, for a PHP minor version, `RuntimeTest` and `config.platform.php`.
3. Rebuild with `--no-cache` and rerun both suites.
