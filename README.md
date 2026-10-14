# Polad Charkhesh Nova

A bilingual Persian/English technical showroom and catalogue CMS for Polad Charkhesh, a Tehran supplier of bearings and rotating components.

Nova helps visitors find exact product designations, inspect sourced technical information, and contact the business for an inquiry. It is an inquiry showroom, not an online shop; catalogue entries do not imply stock or price availability.

**Status: Phase 3 implementation in progress. This repository is a development checkpoint, not a production release or an approval of the current Admin implementation.**

## Project at a glance

| Area | Approach |
| --- | --- |
| Public website | Generated static HTML, CSS, JavaScript and catalogue JSON; no PHP or database queries for ordinary page visits |
| CMS and publishing | PHP 8.4, Twig 3 and MariaDB; the Publish Engine creates and activates validated releases |
| Languages | Persian RTL and English LTR, with separate language hosts |
| Admin | Persian host, Owner/Editor roles, Owner TOTP, product editing, provenance and audit history |
| Local development | Docker Desktop with Linux containers; PHP/Apache, MariaDB and Mailpit run in Docker |
| Workstation tooling | Node.js 24+, npm and Playwright with installed Google Chrome |
| Production hosting | Not deployed; domain, HTTPS and host capability verification remain launch work |

This is the **Nova PHP/MariaDB project**, separate from the earlier PoladCharkhesh JavaScript applications.

## What is implemented

- A frozen bilingual design baseline, ported into production templates with Product Explorer, Product Workspace and product pages.
- Designation normalization and matching with shared PHP/JavaScript test fixtures, including Persian, Arabic and fullwidth digits.
- Catalogue migrations, products and variants, evidence-backed relationships, per-designation technical values and provenance.
- Structured rich content with constrained editor syntax and rendering.
- A Publish Engine with symlink and two-slot strategies, release validation, journal/recovery, rollback and retained release history.
- An initial Admin implementation: sign-in, Owner TOTP enrollment, sessions, roles, product editing, draft preview, publish/rollback and audit activity.
- SEO output: localized titles and descriptions, canonical/hreflang links, breadcrumbs, structured data, robots.txt, sitemap.xml, llms.txt, favicon and a custom 404 template.
- Development/sample labeling and noindex protections. Sample catalogue data is never production evidence.

## Current checkpoint and remaining work

The existing implementation history through commit `81a9829` records the foundation and Steps 3–8. The repository checkpoint also includes subsequent SEO work, Step 8 repairs and Step 9 Admin work.

| Phase 3 step | Status |
| --- | --- |
| 1 — Repository and local tooling | Implemented |
| 2 — Production minimum versions and switch strategy | Final production decision deferred until the host probe |
| 3 — Designation matching | Implemented; owner-reviewed |
| 4–7 — Schema, catalogue/provenance, rich content, public site | Implemented |
| 8 — Publish Engine | Implemented, with follow-up repairs included; acceptance remains subject to review |
| 9 — Admin and Product/variant slice | Implemented; 2026-10-13 follow-up findings fixed and verified locally; awaits owner acceptance |
| 10 — Inquiry endpoint | Not implemented |
| 11 — Media and SVG ingestion | Not implemented |
| 12 — Backup/restore and restore tests | Not implemented |
| 13 — Final Apache routing, redirects and headers | Not complete |
| 14 — Import dry run and reconciliation | Not implemented |
| 15 — Deployment dry run | Not implemented |
| 16 — Phase 3 review package | Pending |

The 2026-10-13 source review identified follow-up work in seven areas. Each finding was reproduced by a failing test, then fixed:
- atomic TOTP consumption and enrollment;
- concurrent login-failure counters;
- cross-form unsaved edits;
- unpublished-change tracking after rollbacks and during builds (now exact per release, with no look-back window);
- password-check timing for unknown accounts;
- publish/rollback error messages after recovery (an unverifiable state is reported as unverified).

The details and tests are in the [Admin runbook](nova/docs/admin.md) and the [handoff checkpoint](06%20phase3%20handoff%20checkpoint.md). These fixes still await the owner's review; no gate is approved by them.

**Next acceptance checkpoint:** Step 10 (the inquiry endpoint) is implemented and verified locally (see [inquiry.md](nova/docs/inquiry.md)); Steps 9 and 10 await the owner's review. Phase 3 completion and production deployment are separate gates.

The earlier governance/handoff documents contain dated statements such as “uncommitted” and historical test results. These describe their original sessions. A GitHub checkpoint commit does not itself approve those changes or prove that the running database and published release match the source.

## Local setup on Windows / PowerShell

Prerequisites: Git, Docker Desktop running Linux containers, Node.js 24+ and npm. Install Google Chrome for browser tests. PHP, Composer and MariaDB run in Docker; do not install a separate Windows server stack for Nova.

Clone and install dependencies:

```powershell
git clone https://github.com/mosish/PoladCharkhesh-Nova.git
Set-Location PoladCharkhesh-Nova/nova
node tools/dev-init.mjs
docker compose up -d --build --wait
docker compose exec web composer install
npm ci
```

`dev-init.mjs` generates local database passwords in the ignored `.env` and leaves an existing file unchanged. Compose stores the database in the named `db-data` volume. This Git repository does not contain that volume or any live database backup.

For a **new, empty development database only**, initialize the schema and labeled sample catalogue, then build a preview:

```powershell
docker compose exec web php app/bin/nova migrate
docker compose exec web php app/bin/nova dev:sample-catalogue
docker compose exec web php app/bin/nova assets:build
docker compose exec web php app/bin/nova publish
```

For an existing project/database, inspect its migration and release state first. Back up nonempty databases before migrations. Do not use reset commands or delete volumes/releases to troubleshoot a preview without approval.

After successful initialization and publication:

| Service | Local URL |
| --- | --- |
| Persian site | <http://fa.nova.localhost:8080/> |
| English site | <http://en.nova.localhost:8080/> |
| Persian sample product | <http://fa.nova.localhost:8080/p/6205/> |
| Admin, after setup | <http://fa.nova.localhost:8080/admin/> |
| Local mail viewer | <http://localhost:8025/> |

These are local addresses, not public deployments. The public inquiry form posts to `/api/inquiry.php` on both hosts and is verified on a disposable stack; delivery to the real notification address needs the production SMTP mailbox, a launch input.

Create the first Owner only after applying the Admin migration and following the [Admin runbook](nova/docs/admin.md). There are no default credentials; the command prompts for a password, and the Owner enrolls TOTP at first sign-in.

```powershell
docker compose exec web php app/bin/nova user:create-owner owner@example.com "Owner name"
```

Use your own account details. Never put a password in command arguments, documentation or Git.

## Publishing and Windows portability

Run PHP publishing commands **inside the web container**. Do not replace the live release link using a Windows absolute path: a host path may not exist inside Linux containers.

The symlink strategy uses a relative release target. The alternative two-slot strategy and recovery/routing constraints are documented in [release-routing.md](nova/docs/release-routing.md).

Publishing is an explicit operation that activates a release and can prune retained releases. Uploading source to GitHub does not publish the website.

## Validation

Run from `nova/`:

```powershell
npm run test:js
docker compose exec web composer check
```

The JavaScript suite exercises the shared designation corpus. Composer checks PHP style, PHPStan at maximum level and PHPUnit unit/integration suites. Integration fixtures use a test database and may reset its contents; verify the configured test database before running them. A separate disposable database/stack is preferred for acceptance work.

Browser testing uses `npm run test:e2e` and requires a prepared local stack. Read the prerequisites in `nova/tests/e2e/` and the Admin runbook first. The `dev:e2e` fixture command changes development data; it is not a read-only check.

Verification evidence for the Step 9 follow-up (2026-10-13), latest run after the change-tracking and live-state corrections. Everything ran in Docker on disposable databases and a disposable stack with its own docroot and storage, all removed afterwards:

- **PHPUnit:** 584 tests / 6,776 assertions passed; PHPStan at maximum level and PHP style passed.
- **JavaScript:** 171 tests passed.
- **Playwright on installed Chrome:**
  - Admin: 12/12, including the multiple-form case;
  - public site: 32/32;
  - stack smoke checks: 4/4.
- **Untouched:** the development database, its live release and its volumes.
Verification evidence for Step 10 (2026-10-14), on disposable databases and a disposable stack (own docroot and storage, port 8090), all removed afterwards:

- **PHPUnit:** 608 tests / 7,084 assertions passed; PHPStan at maximum level, PHP style and `composer audit` passed.
- **JavaScript:** 171 tests passed.
- **Playwright on installed Chrome:** `inquiry.spec.js` (fa and en, with and without JavaScript, axe-clean, cross-site and honeypot refusals) and `site.spec.js` 32/32.
- **Mailpit:** a retried notification arrived with no personal data. Real delivery needs the production SMTP mailbox and notification address (launch inputs).
- **Untouched:** the development database, its live release and volumes.

- Passing tests do not replace security review, data reconciliation, restore verification or owner acceptance.

## Repository map

```text
00–06 *.md                         Governance, decisions, plan and handoff
AGENTS.md / CLAUDE.md              Contributor and coding-agent instructions
design/phase2-v2-corrections/      Frozen Gate 2 reference prototype
design/phase2*/                   Historical design iterations and QA evidence
nova/app/src/                    CMS, domain logic, security and Publish Engine
nova/app/migrations/             Forward-only schema migrations
nova/app/templates/             Public and Admin Twig templates
nova/assets-src/                Source CSS, JavaScript, fonts and favicon
nova/public_html/               Web entry points and routing configuration
nova/tests/                     Unit, integration, JavaScript and browser tests
nova/docker/                    Local containers and server configuration
nova/docs/                      Operational runbooks and implementation evidence
```

Ignored local material includes `.env`, installed dependencies, caches, sessions/storage, generated assets, generated releases and temporary host-probe tooling. Database contents and private operational data require separate backups.

## Project rules and handoff

- Preserve the approved Gate 2 design and decisions D1–D26; do not redesign during implementation.
- Never invent engineering data, infer designation relationships or copy technical values between designations without evidence.
- Preserve bilingual RTL/LTR behavior, exact suffixes, sample/pending states and accessibility behavior.
- Performance work addresses real responsiveness and unnecessary work; no arbitrary byte/request/speed acceptance budgets are introduced.
- DNS, domains, production hosting, real catalogue reconciliation and business-detail confirmation remain explicit launch tasks.

Start with the [project summary](00%20genesis%20summary.md), then the [Phase 3 plan](05%20phase3%20foundation%20plan.md), [handoff checkpoint](06%20phase3%20handoff%20checkpoint.md) and [AGENTS.md](AGENTS.md). The governance documents define precedence; this README is an onboarding overview.

Useful runbooks:

- [Local baseline and environment](nova/docs/local-baseline.md)
- [Admin setup and recovery](nova/docs/admin.md)
- [Publish storage and routing](nova/docs/release-routing.md)
- [Inquiry endpoint](nova/docs/inquiry.md)
- [Website and SEO readiness](nova/docs/website-readiness.md)
- [Designation matching](nova/docs/designation-matching.md)

## Ownership

Project owner and decision-maker: Mosi. The application is proprietary; no open-source license is granted by this repository. Third-party assets retain their respective license notices.
