# Admin (Phase 3 step 9): sign-in, shell and the Product/variant slice

Built and verified locally on 2026-10-12. It approves nothing and changes no decision (D1–D26, E-1–E-22). The frozen Admin wireframes (`design/phase2-v2-corrections/src/admin.html.j2`, `site/assets/admin-wf.css`) were ported; they were not redesigned.

## What exists

- **Where:** `/admin` on the Persian host only (D11). Any other host gets a plain 404 before the database or the session is touched.
  - Front controller: `public_html/admin/index.php`; its own `.htaccess` sends everything to it, and no other PHP runs there.
  - Code: `app/src/Admin/`, `app/src/Security/`, `app/src/Http/`, `app/templates/admin/`, `assets-src/admin/`.
- **Screens (A1, A2, A3, A7):**
  - **A1 Dashboard:** unpublished changes, data pending verification, catalogue counts, data quality, recent activity from the audit trail. Tiles for features that do not exist yet (inquiries, backups) are left out rather than faked.
  - **A2 Products:** search uses the public normaliser (Persian digits, spacing). Columns: dimensions, source coverage, data state, status, explorer, translation, last edit.
  - **A3 Product editor:**
    - identity and `base_listed`;
    - variants: listed and status per variant; recording a new relationship is Owner-only and needs a cited source;
    - spellings (Owner-only);
    - one value table per designation, each value with its own source, locator, checked date and status. Values are never copied between designations;
    - the Product description (the slice's one rich-content field), in a constrained paragraph/list syntax (`BlockSyntax`) that never accepts HTML;
    - status and explorer controls; Owner-only "verified for indexing" with its open conditions listed; the resulting robots/sitemap/schema outcome;
    - a quick Source form;
    - draft preview through the publish templates (noindex, labelled, written nowhere).
  - **A7 Publish:** live release, changes since it went live (audit trail, with who made them), a validation dry run, the publish button, retained history and rollback.
- **Navigation:** shows the full frozen structure; screens built in later steps are listed as "به‌زودی" and are not links.
- **Several forms on one page:** the Product editor has many forms, and saving one reloads the page. If other forms hold unsaved edits, the save first asks («در N بخش دیگر این صفحه تغییرات ذخیره‌نشده دارید…»). Nothing is discarded silently, and leaving the page with edits still asks too.
- **Unpublished changes** (top bar, dashboard, A7) are exactly the content changes the live release does not contain, with no look-back window (migration 0007, `ChangeTracking`).
  - Each live release records, as append-only `publish_change` rows, the content changes its consistent snapshot contained that no earlier release recorded.
  - A change is in release R if and only if R or an earlier release recorded it. Releases are numbered in snapshot order, one publish at a time, and a committed change stays visible.
  - It stays right after a rollback: changes recorded by later releases count as unpublished again.
  - A change saved while a release was being built counts as unpublished. So does a change whose transaction was still open at the snapshot, however many later changes committed in between (tested with 1,200).
  - Recovery records the journalled list, so a recovered release is tracked like any other.
  - A live release published before tracking existed falls back, approximately, to the time it was first recorded.
- **After a failed publish or rollback,** the message says only what is verified.
  - The state counts as verified only when the engine's own recovery succeeded, no interrupted operation is left waiting in the journal, and the live release could be read before and after.
  - Then the message names the release that is live now, saying whether recovery finished a switch or nothing changed. "No release is live yet" appears only when that absence was read.
  - If recovery failed (`RecoveryFailed`, which carries both errors) or the live release cannot be read, the message says the current state could not be verified and points to `publish:status` and `publish:recover`. It never says "unchanged" or "recovered".

## Security properties (05 §6, §14, §15; E-10)

- **Passwords:** Argon2id (bcrypt where the PHP build lacks it), at least 12 characters, at most 256 bytes. A weaker hash is upgraded at the next sign-in. No account exists until an operator creates one; there are no default credentials.
- **Owner TOTP (E-10):** RFC 6238 (SHA-1, 6 digits, 30 s, ±1 step).
  - An Owner without TOTP must enrol before reaching anything. The key is shown once for manual entry, and enrolment needs a first valid code.
  - A code is accepted only once (replay protection).
  - The secret is stored only in the database.
- **Failed sign-ins:** one generic message for every cause; the audit trail records the real reason.
  - An unknown account costs the same password work as a known one.
  - A password typed into the email field is never logged.
- **Lockout:** after 5 consecutive failures (password or TOTP) the account locks for 60 s, doubling per further failure up to 1 h. A lock that begins mid-sign-in also stops the code step.
  - Per client address (stored as a SHA-256 of the address): more than 20 failures within 15 min block that address for 15 min.
  - Trade-off: anyone can lock an account by failing on purpose. `user:reset-password` clears a lock.
- **Concurrency (2026-10-13):** a sign-in completes in one transaction that holds the account row.
  - It re-checks status, session epoch and lockout, so a lock reached while a password or code was being checked stops that sign-in. Such a sign-in also no longer clears the lock.
  - It claims the credential with a conditional UPDATE that must change exactly one row. The TOTP step is spent only if it is newer than the last one, and an enrolment is stored only while none exists.
  - It resets the counters and writes the success audit in the same transaction. The session is created only after the commit.
  - So of concurrent requests with the same code, or concurrent enrolments, exactly one signs in. A losing enrolment records `enrolment_superseded`, not a success.
  - Failure counters are incremented under the account row lock, and the address throttle is one atomic upsert, so no failure is lost.
  - Tests: `tests/integration/Security/AdminAuthConcurrencyTest.php`. It uses independent connections and sessions with a deterministic interleaving point, plus real parallel processes.
- **Equal cost for unknown accounts (2026-10-13):** an unknown account is verified once against a fixed dummy hash at the current Argon2id/bcrypt parameters. Before, it hashed on the spot first, which cost about twice as long (measured 426 ms vs 230 ms) and revealed that the account did not exist. `PasswordTimingTest` checks the parameters and measures in fresh processes.
- **Sessions:** PHP file sessions in Nova's own `storage/sessions` (`0700`), outside the docroot.
  - Strict mode, cookies only. The cookie `nova_admin` is HttpOnly, Secure and SameSite=Strict, with Path `/admin`.
  - A new session id at the password step and again when fully signed in.
  - Sessions end after 30 min idle or 12 h after sign-in. They also end when the account's `session_epoch` changes (password or TOTP reset, account disabled).
  - Role and status are re-read on every request.
- **Every POST:** needs the per-session CSRF token, and a cross-site browser request is refused even with a token (Sec-Fetch-Site / Origin). The token rotates at sign-in.
- **Roles:**
  - **Owner only:** verification, variant relationships, spellings, the standalone decision.
  - **Owner and Editor:** content, values, sources and publishing.
  - Roles are enforced both in the Admin and in the domain services.
- **Headers on every Admin response:**
  - a CSP with only this origin and no inline script or style;
  - `X-Frame-Options: DENY`, nosniff, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`;
  - errors go to the server log only, whatever `php.ini` says.
  - The draft preview carries its own CSP: the public templates need inline style attributes.
- **Audit trail:** every sign-in outcome, enrolment, sign-out, session end, account change and content change. Publishes and rollbacks record `by` (admin user, CLI with OS user, or recovery).

## Accounts (command line only)

```bash
docker compose exec web php app/bin/nova user:create-owner <email> <display name>   # once; asks for the password twice
docker compose exec web php app/bin/nova user:create-editor <email> <display name>
docker compose exec web php app/bin/nova user:reset-password <email>                 # ends that account's sessions, clears a lockout
docker compose exec web php app/bin/nova user:reset-totp <email>                     # the Owner re-enrols at the next sign-in
docker compose exec web php app/bin/nova user:list
```

Passwords are read from the terminal with echo off, or from standard input. They are never accepted as arguments, which would leave them in shell history and the process list.

## Using it in the local dev stack (needs the owner's go-ahead)

The dev stack is not switched on for the Admin yet. Doing so changes the owner's development database and restarts the web container, so it was not done in this session:

1. Recreate the web container so the image picks up the new `/admin` alias on `fa.nova.localhost` and `NOVA_HTTP_PORT`:

   ```bash
   docker compose up -d --build web
   ```

2. Apply migration 0005 to the development database. It holds data, so this uses the visible, logged dev-only bypass:

   ```bash
   docker compose exec web php app/bin/nova migrate --no-backup
   ```

3. Build the assets, which now include `public_html/admin/assets/`:

   ```bash
   docker compose exec web php app/bin/nova assets:build
   ```

4. Create the Owner:

   ```bash
   docker compose exec web php app/bin/nova user:create-owner <email> <name>
   ```

5. Open `http://fa.nova.localhost:8080/admin/` and enrol an authenticator app.

## How it was verified (2026-10-12)

- **PHPUnit:** unit tests (TOTP RFC vectors, hashing, CSRF, block syntax, headers) and integration tests. The integration tests ran on a new disposable database created for this task, never `nova` or `nova_test`. They cover:
  - sign-in, lockout, throttling, TOTP, session lifetime, epoch, role re-read;
  - the CLI account commands;
  - the whole Admin in process: host rule, signed-out refusals, CSRF and origin refusals, Editor refusals, stale edits, per-value provenance, variant isolation, description, verification gate, preview, publish, rollback and a blocked publish.
- **Playwright (`tests/e2e/admin.spec.js`, 11 tests):** run on Apache with native sessions against a disposable stack (its own database, docroot and storage, port 8090). It covers:
  - Owner TOTP enrolment;
  - cookie flags, headers, axe-clean pages;
  - a provenance save, the unsaved-change guard, preview, CSRF refusal, publish;
  - narrow-screen reflow, sign-out;
  - Editor refusals.
  - The same stack ran the public-site suite (`site.spec.js`, 32 tests) with `NOVA_SITE_PORT=8090`.
  - The run needs `NOVA_ADMIN_URL` and throwaway accounts in the environment (see the spec header). The spec is skipped otherwise.

## Not in step 9 (later work)

- The other screens (A4 media, A5 sections, A6/A9 inquiries, A8 company, users, audit log, backups) come in steps 10–12 and Phase 4b.
- No QR code for TOTP enrolment: it would need a dependency (D16), so the key is entered by hand or via the shown `otpauth` URI.
- A draft Product's interactive workspace data (`/data/p/<slug>.json`) exists only for published releases. The preview shows the page's HTML content.
- Email password reset waits for SMTP (step 10); the command line is the recovery path.
- **Production wiring:**
  - the config-file location under Apache;
  - the app-version pointer (`~/app-current.php`);
  - HTTPS on the .ir host (cookies are Secure);
  - the `.htaccess` template.

  These belong to steps 13 and 15.
