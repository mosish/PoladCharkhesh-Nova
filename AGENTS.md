# AGENTS.md: working on PoladCharkhesh-Nova

Polad Charkhesh Nova is a bilingual (fa/en) technical showroom for a Tehran bearings supplier.
- **Architecture (D1):** PHP + MariaDB are the source of truth for a CMS whose Publish Engine writes static releases. Visitors run no PHP and no database queries. The only public PHP is the inquiry endpoint, plus the Admin on the `.ir` host.
- **Owner and sole decision-maker:** Mosi.

## 1. Read before acting (D18 precedence)

1. `00 genesis summary.md`
   - its **status line**, which is authoritative for "where are we";
   - decisions D1–D26;
   - the frozen Gate 2 baseline.
2. `05 phase3 foundation plan.md`, revision 3. The addendum at the top overrides older text below it where they conflict.
   - §24 is the implementation sequence.
   - §31 is the approval matrix.
3. `04 takeover audit and performance policy.md`, then `03`, `02`, `01` and the Genesis file.
4. `06 phase3 handoff checkpoint.md`: a map only, which defers to the files above.

The hosted claude.ai copies and the review/concept `.md` exports are historical snapshots. Never edit or sync them.

## 2. Repository layout

| Path | What it is |
| --- | --- |
| `/*.md` (00–06, Genesis) | governance documents (authoritative, D18) |
| `design/phase2-v2-corrections/` | the frozen Phase 2 reference prototype. Port it; don't redesign it. |
| `design/phase2*/`, `*.zip` | history |
| `nova/` | all production code (E-15). Planned structure: `05` §4. |
| `nova/docs/local-baseline.md` | the exact local versions (D25) and the dev runbook |
| `tools/host-probe/` | temporary, **gitignored**, deferred (D26). Never run it, deploy it or commit it. |

## 3. Commands

Run from `nova/`. The stack is Docker only; nothing server-side is installed on Windows (D25).

```bash
node tools/dev-init.mjs          # once: writes .env with random local passwords
docker compose up -d --build     # web http://localhost:8080 · mail UI http://localhost:8025
docker compose exec web composer install
docker compose exec web composer check        # cs + phpstan + phpunit (unit + integration)
docker compose exec web php app/bin/nova migrate               # apply migrations (a non-empty DB needs a backup; dev-only: --no-backup)
docker compose exec web php app/bin/nova dev:sample-catalogue  # load the labelled Phase 2 SAMPLE fixtures (development only)
docker compose exec web php app/bin/nova assets:build          # content-hashed CSS/JS/fonts into public_html/assets/
docker compose exec web php app/bin/nova publish               # validate → build → verify → switch → record → prune (dev: SAMPLE data, noindex)
docker compose exec web php app/bin/nova publish:rollback      # instant switch back; publish:recover / publish:status
                                                               # live release: http://fa.nova.localhost:8080 · http://en.nova.localhost:8080
docker compose exec web php app/bin/nova dev:e2e               # dev DB = SAMPLE + SYNTHETIC QZ fixtures, then assets + publish (run before npm run test:e2e)
docker compose exec web php app/bin/nova user:create-owner <email> <name>   # first Owner (once); password asked, never an argument; TOTP enrolled at first sign-in
                                                               # Admin: http://fa.nova.localhost:8080/admin/ (setup and recovery: nova/docs/admin.md)
docker compose exec web php app/bin/nova media:purge           # delete media/documents trashed for 30 days and files no record or retained release names (nova/docs/media.md)
docker compose exec web php app/bin/nova inquiry:notify        # retry failed inquiry emails; inquiry:purge applies the 24-month retention (nova/docs/inquiry.md)
npm ci && npm run test:js                      # JS copy of the designation matcher vs the shared corpus
npm run test:e2e                               # Playwright on the installed Chrome
docker compose down                             # add -v to wipe the dev database
```

- `/_dev/` is a development-only diagnostics page from `nova/docker/dev-www/`. It is not in the docroot and never deployed.
- Integration tests use the `nova_test` database, never `nova`. `NOVA_DB_TEST_NAME` can point a run at a new disposable database (`docker compose exec -e NOVA_DB_TEST_NAME=… web …`).
- Release storage and Step 13 routing requirements: `nova/docs/release-routing.md`. Admin security and runbook: `nova/docs/admin.md`.
- Designation matching: the spec is `nova/docs/designation-matching.md`, and the executable spec is `nova/tests/fixtures/designation/corpus.json`. Change PHP (`app/src/Designation/`) and JS (`assets-src/js/designation.js`) together, and add the vectors first.

## 4. Conventions

- **PHP:**
  - PHP 8.4 language level.
  - `declare(strict_types=1)` in every file.
  - PSR-4 `Nova\` → `nova/app/src/`.
  - PER-CS style, enforced by PHP-CS-Fixer.
  - PHPStan at level max.
- **Stack (E-3):** plain PHP, no framework, with Twig 3 and PHPMailer. The SVG sanitizer is wrapped per E-7.
- **Dependencies (D16):** add a dependency only when a step needs it, with a written justification.
- **SQL:**
  - D12's conservative subset, which also runs on MariaDB 10.11.
  - InnoDB, `utf8mb4_unicode_520_ci`.
  - A strict `sql_mode` set on every connection.
  - Times in UTC, displayed in Asia/Tehran.
  - Forward-only migrations.
- **Files:** UTF-8 and LF everywhere (`.gitattributes`). Persian text is never transliterated in code or tests.
- **Tests:**
  - PHPUnit (`tests/unit`, `tests/integration`) and Playwright (`tests/e2e`).
  - Phase 2 sample products are fixtures labelled as samples, never production data.

## 5. Non-negotiables

- **Gates:**
  - Do only the authorized step, verify it, report, and stop where the owner says to stop.
  - Never approve anything on the owner's behalf.
  - Gates 1 and 2 are closed: do not reopen the frozen baseline or decisions D1–D26.
- **Data:**
  - Never invent product or technical data.
  - Never infer designation relationships.
  - Never copy values between designations (D20/E-2).
  - Unverified rules (V-1–V-8, `05` §33) stay "unresolved".
- **Performance (D14/D17):**
  - no byte, file or request budgets;
  - no numeric performance gates;
  - measurements are for diagnosis and regression only.
- **Secrets:**
  - Never write keys or secrets into files, the repository history or notes. Files store hashes only.
  - `.env` is local and gitignored.
- **Tooling (D24, `05` §29):**
  - Superdesign only for genuinely new production UI.
  - DevTools and Playwright for verification.
  - Security review during the build. Use these tools in proportion to the risk.
