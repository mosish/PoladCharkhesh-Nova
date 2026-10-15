# PoladCharkhesh-Nova: Phase 3 handoff checkpoint (for a fresh Claude Code session)

Checkpoint date: 2026-10-06.
Owner and sole decision-maker: Mostafa Shahi ("Mosi").

This file is a map, not a source of truth. It points to the authoritative documents and does not restate them. If this file and an authoritative document disagree, the authoritative document wins. Report the conflict to the owner.

## 1. Current status

- **Gate 1:** APPROVED (2026-10-04).
- **Gate 2:** APPROVED and CLOSED (2026-10-06). Phase 2 is frozen as the production baseline.
- **Phase 3 (Foundation):** IN PROGRESS, local-first. E-1 was approved on 2026-10-06.
  - **Step 1 is done and verified:** the git repository, AGENTS.md/CLAUDE.md, the Docker dev stack and the toolchain.
  - The local baseline (step 2) is recorded in `nova/docs/local-baseline.md`.
  - The host probe is deferred.
- **Step 1 is committed** as `5c7e33e`.
- **Step 3 is approved and committed** (owner review 2026-10-06; fullwidth-digit refinement; frozen choices recorded in `00`).
  - Spec: `nova/docs/designation-matching.md`.
  - Executable spec: `nova/tests/fixtures/designation/corpus.json`.
- **Steps 4–8 are implemented, verified and committed** under the owner's standing delegation. They are not separately owner-reviewed. See §14.
- **Step 8 repairs** (2026-10-12) are verified; see §14 and `nova/docs/release-routing.md`.
- **Step 9** (Admin sign-in, shell and Product/variant slice) is implemented and verified locally; see §14 and `nova/docs/admin.md`. The Step 8 repairs and Step 9, with the 2026-10-13 follow-up fixes, are committed to GitHub `main` and await the owner's review (see the end of §14).
- **Next:** step 10 (inquiry endpoint), only on the owner's go-ahead. Not yet built: inquiry endpoint, media, backup, `.htaccess` template, import, deployment dry run.

## 2. Authoritative documents and precedence (D18)

Read them in this order. The status line in `00` is authoritative.

1. `00 genesis summary.md`
   - the status line;
   - the decision register D1–D26;
   - the "Gate 2 approval and frozen production baseline" section;
   - the roadmap and open inputs.
2. `05 phase3 foundation plan.md`, revision 3. The revision 3 addendum at the top overrides older text below it where they conflict.
3. `04 takeover audit and performance policy.md`: D14–D18, the audit, the contradictions register and the turn logs (§9).
4. `03 phase2 v2 corrections.md`: Phase 2 corrections, including the T-1 fix; then `02`, `01` and `Polad Charkhesh Nova — Project Genesis (Stage 0).md`, the Gate 1 record with dated supersession notes.

The hosted claude.ai copies (Genesis doc, review hub, concept boards) and the review/concept `.md` exports are historical snapshots. Do not sync or edit them.

## 3. Frozen Gate 2 baseline

- The baseline is listed in `00`, section "Gate 2 approval and frozen production baseline".
- The reference prototype is `design/phase2-v2-corrections/`, including the T-1 fix. It is frozen.
  - `design/phase2-v2-corrections.zip` is the pre-T-1 delivery.
  - `design/phase2/` and `design/phase2-v2/` are history.
- Port the prototype to production in step 7. Do not redesign it.
- Reopen `design/` only if implementation exposes a genuine defect. Report it, make the smallest fix, and test it.

## 4. Approved decisions

- **D1–D26:** in `00`. D19–D26 were all decided on 2026-10-06.
  - D19: preferred versions; the host adapts to Nova.
  - D20: the Product/Variant model.
  - D21: SVG.
  - D22: content model.
  - D23: backups.
  - D24: tooling.
  - D25: local-first.
  - D26: probe deferred.
- **E-1–E-22:** the matrix is in `05` §31.
  - All are approved, except E-1 (start authorization pending) and E-17 (staging, deferred to hosting).
  - The host-dependent items E-4, E-5, E-11, E-16 and E-20 are approved for local work. Their production specifics are deferred to the hosting probe.

## 5. Local-first policy (D25)

- All Phase 3 and later development and QA run locally, in Docker. Nothing server-side is installed natively on Windows. The Laragon/XAMPP fallback is withdrawn.
- **Local baseline:** PHP 8.4 with Apache and active `.htaccess`, plus MariaDB 11.4 LTS and a mail catcher.
  - Composer and PHP tooling run in containers.
  - Node 24 runs on the workstation for asset build and Playwright.
  - Record the exact image versions in the first task.
- **SQL:** stays within D12's conservative subset, which also runs on 10.11 LTS. Generated columns are allowed.
- **Verified workstation, as reported by the owner:**
  - Windows 11 and WSL2 2.7.14;
  - Git 2.55.0;
  - Node 24.19.0 and npm 11.17.0;
  - Docker 29.8.0 and Compose 5.5.1. `docker run hello-world` works.
- **Never weaken the architecture for an unknown future host.**

## 6. Product/Variant invariants (D20 + E-2 refinement)

Full rules: `05` §9, §10, §17 and §25.

- The **Product** is the stable family/designation entity. It has an explicit `base_listed` flag, which is off by default.
- Each **ProductVariant** has its own:
  - exact designation;
  - `listed` flag;
  - sourced values with provenance;
  - documents;
  - inquiry identity.
- No variant ever represents the Product. `/p/<product>` shows 0, 1 or many listed items.
- **Never copy or assume shared values** between designations.
- **Never infer a relationship** without evidence.
  - An unverified relationship gets a standalone Product with a review flag.
  - Once it is resolved, the old URL gets a 301 redirect.
- **One `designation_key` namespace** covers Products, Variants and Owner-recorded Spellings.
- **Seven match categories**, in this precedence order. Internal names may be refined later from verified evidence (E-2).
  1. `exact_product`
  2. `exact_variant`
  3. `via=spelling`
  4. `unlisted_variant`
  5. `related` (uses the T-1 wording)
  6. `base_series`
  7. `none`
- The requested designation is kept verbatim from search through the workspace, `?r=`, the form and WhatsApp, to the server record.
- The server recomputes the match (E-21).
- "/" is significant until it is verified (E-19).
- The normaliser has one spec, implemented in both PHP and JavaScript, with shared test vectors.
- **Uncertain manufacturer/product-code semantics are NOT frozen** (V-1–V-5).

## 7. CMS / Admin / Public architecture

- **Architecture (D1):**
  - PHP + MariaDB is the source of truth.
  - The Publish Engine produces static releases, so visitors cost no PHP and no database queries.
  - The only public PHP is the inquiry endpoint.
  - See `05` §1 and §4–§8.
- **Domains and URLs:**
  - `.ir` serves Persian and `.com` serves English, from one docroot (D6).
  - Admin is reachable only at `.ir/admin` (D11).
  - `x-default` is `.com` (D10).
  - Pending products are discoverable but noindex (D9/C1).
- **Content model (E-8, `05` §27):**
  - Layer 1: structured technical data. No rich text.
  - Layer 2: constrained Nova JSON block documents, rendered by Nova's own renderer. No raw HTML.
  - Layer 3: media and documents, referenced by ID.
- **SVG (E-7, `05` §26):**
  - Upload is Owner-only and passes through an allow-list rebuild.
  - Uploaded SVGs are shown only through `<img>`, with a sandbox CSP.
  - Trusted code assets may be inlined.
- **Other areas:** provenance §11, inquiry §13, auth/Owner role §14, security baseline §15, bilingual RTL/LTR §16, backups §18 (D23), migration boundary §19.

## 8. Performance policy (D14/D17, reconfirmed 2026-10-06)

- There are no byte, file or request budgets.
- There are no numeric CWV/performance gates.
- Measurements are for diagnosis and regression only. Do not spend time on micro-optimisation.
- D16 applies: dependency discipline, with each dependency justified.
- D15 sets the priority order. Correctness comes first and performance last.
- C4 applies: no visitor tracking.
- See `05` §28.

## 9. Tooling, testing, security

- **Tooling (D24, `05` §29):** use these in proportion to risk.
  - Superdesign, only for genuinely new production UI. Never use it to reopen Gate 2.
  - Chrome DevTools MCP and claude-in-chrome.
  - Playwright for fa/en, RTL/LTR, matching, deep links, inquiry, Admin, publish and rollback.
  - Security review during the build.
- **Testing:** `05` §20.
- **Acceptance:** `05` §23, as amended by the revision 3 addendum.
- **Secrets:**
  - Never write keys or secrets into docs, repository history or memory. Files store hashes only.
  - Temporary keys are given only in chat.
  - Rotate any key that has been exposed.

## 10. V-1–V-8 (source-verification issues, `05` §33)

V-1 to V-8 cover:
- V-1: suffix tokens;
- V-2: shared values;
- V-3: "/" semantics;
- V-4: brand equivalents;
- V-5: prefixes;
- V-6: core values per family;
- V-7: inch tapered sets;
- V-8: whether ratings can be published.

Rules:
- These items do **not** block starting.
- They block freezing the related suffix, slash and relationship rules.
- Until they are verified, the affected designations stay "unresolved", with no inferred claims.
- Sources: the owner, or cited manufacturer catalogues (only with owner approval).

## 11. Deferred until hosting is purchased (D26)

- **The probe:** `tools/host-probe/`, version 1.1.0. It is preserved but must not be run. Its package expires 2026-10-20.
  - Before deployment, regenerate it with a new key, run it, then delete it.
- **Frozen from the probe:** the production PHP/MariaDB minimums and the release-switch strategy (S or D).
- **Also deferred:**
  - staging (E-17);
  - SMTP/SPF/DKIM;
  - domains/AutoSSL;
  - cron and quota checks;
  - provider backups;
  - the real-host deployment dry run.
- **Owner inputs and when they are needed:** `05` §30.

## 12. Next task

**Step 1 was completed on 2026-10-06.** The text below is kept as the record of what it covered; the commands are in `AGENTS.md`.

**Step 3** (`05` §24, §10, §25) was approved and committed on 2026-10-06. Its scope was:
1. Build the designation test corpus as shared PHP/JS vectors:
   - the Phase 2 cases, including T-1 and the 6305/22220 pairs;
   - Persian/Arabic digits and letters;
   - spellings;
   - "/" kept significant (E-19).
2. Build the normaliser and the seven-category matcher in PHP and JS from one spec.
3. Mark unverified suffix/slash semantics (V-1–V-5) as explicitly "unresolved". Never infer them.

Steps 4–8 followed (see §14). The current next step is in §1 and §14.

**Step 1 (completed):**

**Phase 3, step 1** (`05` §24):

1. Create the git repository with the code under `nova/`. Exclude the probe package (`tools/host-probe/`) from it, per E-15.
2. Write `AGENTS.md` and `CLAUDE.md`. They should point to the documents in section 2 above and state the conventions and commands.
3. Write the Docker Compose dev stack at the local baseline:
   - PHP 8.4 with Apache and `.htaccess`;
   - MariaDB 11.4 LTS;
   - a mail catcher;
   - Composer.
4. Set up the PHP toolchain: PHPUnit, PHPStan and PHP-CS-Fixer.
5. Set up Node 24, Playwright and axe.
6. Record the exact image versions (step 2).
7. Check the step: `docker compose up` works, a hello-world page is served through Apache with `.htaccess` rewrites, the database is reachable from PHP, and the test runners pass on empty suites.

Then proceed to step 3 (designation corpus, normaliser and matcher) and step 4 (migrations and schema v1), and continue the §24 sequence. Stop for the Phase 3 exit review (E-22).

## 13. Prohibitions

- Do not start implementing until the owner explicitly authorizes it. Never approve a gate or plan on the owner's behalf.
- Do not reopen completed work:
  - Gate 1;
  - Gate 2 and the frozen baseline;
  - D1–D26;
  - the approved E items;
  - the T-1 fix.
- Do not reintroduce size or performance budgets.
- Do not invent technical data. Do not infer designation relationships. Do not copy values between designations.
- Never present samples as production data. The 13 Phase 2 products are labelled fixtures.
- Do not run or deploy the old probe.
- Do not install server software natively on Windows.
- Do not edit hosted snapshots.

## 14. Progress log (autonomous delivery, 2026-10-06)

Steps committed after verification: 4 `ec96a09` (migrations, schema v1), 5 `97c6487` + fix `64fc68e` (catalogue, relationship review, provenance; verify/edit race fixed), 6 `a1d4956` (rich content), 7 `0b05847` (public site port, Product pages), 8 `31237e8` (Publish Engine, switchers S and D, journal, rollback, prune). Latest totals: 420 PHPUnit, 171 JS, 36 Playwright, PHPStan max.

**Resume at step 9** (sign-in, Admin shell from the frozen A1–A9 wireframes / admin-wf.css, Product/variant vertical slice, audit). Then steps 10–16 per `05` §24.

**Open items to handle first:** a background security review of `31237e8` flagged (1) possible information disclosure in `app/src/Publish/PublishFactory.php` and (2) missing authorization on CLI publish/rollback in `app/src/Cli/Application.php`.

**Investigated 2026-10-12 (read-only, cloud session; nothing changed in code, nothing committed):**

1. **PublishFactory: confirmed, latent (nothing is deployed).**
   - The factory puts every release inside the docroot (`public_html/_site/`).
     - Strategy D: the previous release stays at a fixed public address (`/_site/slot-a/` or `/_site/slot-b/`), and `_site/ACTIVE_B` (not a dotfile) names a release id.
     - Strategy S: the four retained releases are reachable at `/_site/releases/<id>/` by anyone who knows or guesses an id.
     - Consequence: a withdrawn product or a corrected technical value stays publicly readable until pruned.
   - The current `public_html/.htaccess` is a skeleton. Nothing in `05` says step 13 must block direct `/_site/` requests (D6 only says "internal folders blocked").
   - The factory also accepts a `paths.storage` inside the docroot without complaint. Storage holds the journal and the strategy D archive now, and backups and sessions later.
   - Fix:
     - (a) Now: outside development, `PublishFactory` refuses a storage path that resolves inside `paths.public`. Add a unit test.
     - (b) Step 13 requirement: the generated `.htaccess` serves only the live release through internal rewrites. Any direct external request under `/_site/` gets a 404 (`slot-*`, `releases/*`, `ACTIVE_B`, `manifest.json`), for both S and D. Test it on the Apache container.
2. **CLI publish/rollback: not a vulnerability; two small gaps.**
   - Running `app/bin/nova` requires the hosting-account shell. That shell already holds `~/config/config.php` (the database password) and write access to the docroot, so a check inside the CLI cannot stop anyone. `05` §14 also relies on the CLI for recovery and first-Owner setup.
   - Gaps:
     - (a) `Application::run()` does not refuse a non-CLI SAPI; only `dev:reset` checks.
     - (b) CLI publishes and rollbacks are audited with a NULL actor, so they look the same as automatic crash recovery.
   - Fix:
     - (a) `run()` returns 2 unless the SAPI is `cli`. Add a test with `apache2handler`.
     - (b) Record the origin (`cli` and the OS user) in the publish and rollback audit entries.

Both fixes are ordinary technical decisions (delegated). Implement them, verify them in Docker and commit before step 9. Commit this note in the same commit.

**Stale status (reported to the owner 2026-10-12):** several places still say step 4 is next:
- the status line of `00` (authoritative per D18);
- §1 and §12 of this file;
- the header of `05`.

Git history shows steps 4–8 committed (`ec96a09` … `31237e8`, with docs in `81a9829`). Update those status lines when the fixes are committed, so a fresh session doesn't stop at step 4. *(Done 2026-10-12: `00`, `05` header, §1 and §12 now name step 9 as next.)*

**Step 8 repairs, implemented and verified locally 2026-10-12 (not committed; the owner withheld commit permission for this session):**

How the findings were confirmed:
- **Reproduced by running them first:** findings 2, 4, 5 and 6 and the repeated-recovery case. A scratch script ran on temporary directories and a new disposable database (`nova_s8_*`); the Apache test also ran against the original `.htaccess`.
- **Confirmed by reading the code** (no check existed at all): findings 1 and 3 and the foreign-link part of 7.

All fixes verified; details and the Step 13 requirements are in `nova/docs/release-routing.md`.
1. **Storage inside the docroot:** reproduced (accepted silently). `PublishFactory::locations()` now refuses it outside development, using resolved paths (symlinks and `..`). Covered by `tests/unit/Publish/PublishFactoryTest.php`.
2. **Direct `/_site/` access:** reproduced on the dev Apache. An old release and the manifests returned 200, including through `%5f` and `//` spellings. `public_html/.htaccess` now 404s every client request under `/_site`, while internal rewrites still work. Verified with a private Apache in `ApacheReleaseAccessTest` (S, D with slot a live, D with slot b live; it fails against the old file) and with curl on the dev server. The preview hosts are unaffected.
3. **CLI under a web SAPI:** `Application::run()` now returns 2 for any SAPI other than `cli`. Publish and rollback audit entries record `by` as `admin`, `cli` (with the OS user) or `recovery` (with `started_by`).
4. **Rollback crash window:** reproduced (the database kept the wrong live release, and `recover()` saw nothing). Rollback is now journalled and finished by recovery.
5. **Strategy D grace window:** reproduced (a slot that was just live could be reused after 0 s). The grace timestamp is now written before the marker changes, and the writes are durable. Crash tests cover `copied`, `graced` and `marked`.
6. **Recovery used the current database:** reproduced (it froze a product that wasn't in the live release). Identity and summary are now journalled before the switch.
7. **Strategies and recovery:**
   - Both strategies are covered by tests for failed switches (publish and rollback), repeated recovery (a duplicate audit entry was reproduced and fixed) and retention for D as well as S.
   - Strategy S now refuses a foreign, absolute or dangling `current` link (the 2026-10-11 incident).

Totals after the repairs: 493 PHPUnit / 6,216 assertions, PHPStan max, CS clean, 171 JS. Playwright and `dev:e2e` were not run (they reset the dev database).

**Step 9, implemented and verified locally 2026-10-12 (not committed; awaits the owner's review):**

Details are in `nova/docs/admin.md`.
- **Sign-in:** schema 0005; Argon2id; Owner TOTP (E-10) with enrolment at first sign-in and replay protection; generic failures; account lockout with back-off; per-address throttling; file sessions outside the docroot (strict, HttpOnly, Secure, SameSite=Strict, path `/admin`); new id at each stage; idle and absolute timeouts; session epoch.
- **Request rules:** CSRF plus same-origin checks on every POST; role policy in both the Admin and the services.
- **Accounts:** command line only (`user:create-owner`, `user:create-editor`, `user:reset-password`, `user:reset-totp`, `user:list`), with no default accounts.
- **Screens:** the Admin shell and A1/A2/A3/A7 ported from the frozen wireframes. The A3 slice covers `base_listed`, variants, spellings, per-designation values with provenance, the description field, publication and the Owner-only verification gate, plus draft preview, publish and rollback. Admin assets are served under `/admin` only.
- **Recovery:** the journal is recovered at the start of each signed-in Admin request (05 §7).
- **Security pass:** it found, and fixed, one gap (a lock beginning mid-sign-in did not stop the TOTP step) and an error-display leak on a misconfigured host (`display_errors` is now forced off). No raw output exists in the Admin templates.
- **Also fixed:** development builds now use the dev stack's real port (`NOVA_HTTP_PORT` passed into the container), so canonical and language-switch URLs no longer assume 8080.

Verification:
- **PHPUnit:** 556 tests / 6,608 assertions on a new disposable database (never `nova` or `nova_test`). PHPStan max, CS clean, `git diff --check` clean, 171 JS tests.
- **Playwright on a disposable stack** (its own database, docroot and storage; port 8090):
  - `admin.spec.js`, 11/11 on Apache with native sessions;
  - `site.spec.js`, 32/32 (this also covers Codex's uncommitted SEO changes).
- **Cleanup:** the disposable databases, container and scratch files were removed afterwards.
- **Untouched:** the dev database `nova` (still at schema 0004), `nova_test` and the live preview release `R-20261012-131225-9d1c`.

Not done, by instruction:
- no commit, publish, rollback or prune on the real preview;
- `dev:reset`, `dev:e2e` and migrations not run on `nova`;
- the web container not recreated.

To use the Admin locally, the owner must OK the steps in `nova/docs/admin.md`: rebuild the web container, `migrate --no-backup` on the dev database, `assets:build`, `user:create-owner`.

**Resume point:** owner review of the Step 8 repairs and Step 9, then commits. Step 10 (inquiry endpoint) only on the owner's go-ahead.

**Step 9 concurrency fixes, 2026-10-13 (the code reached GitHub in the checkpoint `b504bc1`; this note and the final test typing fix are in the follow-up commit; awaits the owner's review):**

Owner-reported findings in `AdminAuth.php` and the related counters were reproduced first. Seven new tests failed on the old logic; they use independent database connections and sessions on a new disposable database, interleaved at a test checkpoint, plus real parallel processes:
1. One TOTP code signed in two concurrent sessions.
2. A losing concurrent enrolment still signed in and audited `auth.totp_enrolled` and `auth.sign_in`, while the other request's secret was stored.
3. Concurrent password failures were lost (2 counted as 1). With real processes, 12 audited wrong passwords left the counter at 2.
4. The same lost update for TOTP failures.
5. A lock reached while a correct password or code was being checked did not stop that sign-in, and that success then cleared the lock.

Fix: sign-in completion is now one transaction holding the account row.
- It re-checks status, epoch and lock.
- It makes a conditional claim that must change exactly one row: the TOTP step must be newer than the last, and an enrolment is stored only while none exists.
- It resets the counters and writes the success audit in the same transaction; the session is created after the commit.
- A losing code counts as `totp_replay`; a losing enrolment records `enrolment_superseded`.
- Failure counters are incremented under the row lock.
- The address throttle is a single atomic upsert. A first transactional version deadlocked under the parallel test (shared locks from `INSERT IGNORE`); the upsert passed 10 of 10 repeated runs.

Checks: 563 PHPUnit tests / 6,684 assertions, PHPStan max, CS clean, 171 JS. The disposable database was dropped. `nova`, `nova_test` and the preview were untouched, and the Admin was not activated. Docker Desktop had stopped and was restarted; the existing containers were started as they were, not recreated.

**Resume point unchanged:** the owner's review of the Step 8 repairs and Step 9, then commits. Step 10 only on the owner's go-ahead.

**Step 9 follow-up findings, 2026-10-13 (committed and pushed to GitHub `main`; awaits the owner's review, no gate approved):**

Sync before the work: local `main` = `origin/main` = `b504bc1`, with no divergence.

- **Already fixed in `b504bc1` and re-verified:** atomic TOTP consumption, atomic enrolment, and failure counters that lose no updates (`AdminAuthConcurrencyTest`, 7 tests; stable in 5 of 5 repeats).

New findings, each reproduced by a failing test before the fix:

4. **Cross-form edits:** saving one Admin form silently discarded unsaved edits in other forms on the page. The guard cleared every dirty form on any submit. Reproduced in Chrome by substituting the old `admin.js` into a disposable stack.
   - Fix: a save asks first when other forms hold unsaved edits.
   - Test: `admin.spec.js`, "saving one form never silently discards unsaved edits in another".
5. **Unpublished changes:** reported 0 after a rollback, 0 after an edit saved during a build, and 0 for a transaction still open at the snapshot.
   - Fix: migration 0006 records each release's snapshot point (newest audit id seen, plus unseen lower ids). Unpublished changes are measured against the live release's snapshot point. Releases from before 0006 fall back to `finished_at`.
   - Tests: `UnpublishedChangesTest` (4 tests).
6. **Unknown-account timing:** an unknown account cost about twice a known one (426 ms vs 230 ms, measured in fresh processes), because each request hashed on the spot.
   - Fix: a fixed dummy hash at the current parameters.
   - Tests: `PasswordTimingTest` (2 tests, including the fresh-process timing).
7. **Error messages:** publish and rollback errors always said "live site unchanged", even when the engine's recovery had finished the switch.
   - Fix: the message reads the live release from the switch and names it.
   - Tests: 3 new `AdminAppTest` tests, using a switcher that switches and then fails (`ThrowAfterActivate`) and one that fails before switching.

Also: the scroll wrappers of the Admin tables are now focusable, labelled regions. axe reported `scrollable-region-focusable` on the Publish history table.

Verification (Docker; a new disposable database plus a disposable stack with its own database, docroot and storage on port 8090; all removed afterwards):
- PHPUnit 574 tests / 6,732 assertions; PHPStan max; CS clean; 171 JS tests.
- Playwright: `admin.spec.js` 12/12, `site.spec.js` 32/32, `stack.spec.js` 4/4 (read-only against the dev stack).
- `nova` (schema 0004), `nova_test` and the live preview `R-20261012-131225-9d1c` were untouched, and the Admin was not activated in the dev stack.

**Resume point:** the owner's review of Step 9, then the go-ahead for Step 10 (inquiry endpoint).

**Step 9 corrections, 2026-10-13, owner-requested (committed and pushed to GitHub `main`; awaits the owner's review, no gate approved):**

1. **The look-back window could miss a change.** `PublishEngine::snapshotPoint()` used a 1,000-id look-back, which missed a change whose transaction stayed open while more than 1,000 later changes committed.
   - Reproduced: one open change, then 1,200 committed changes, then the snapshot, then the open change commits. The count read 0 instead of 1.
   - Fix: exact tracking with no window (migration 0007 `publish_change`, `ChangeTracking`). Each live release records the content changes its snapshot contained that no earlier release recorded, and "in release R" means recorded by R or an earlier release.
   - Rollback and recovery are preserved: recovery records the journalled list.
   - The 0006 `snapshot_audit_gaps` column is dropped; `snapshot_audit_id` stays as the marker for a tracked release.
   - Tests: `UnpublishedChangesTest` (6 tests, including the 1,200-change case and a recovered publish followed by a rollback).
2. **Unverifiable live state reported as fact.** An unreadable live release and a failed recovery were both reported as "unchanged" and "no release live yet". Reproduced: 3 failing `AdminAppTest` tests.
   - Fix: `liveOf()` distinguishes a release, a verified absence and "cannot be read".
   - A failed recovery raises `RecoveryFailed`, carrying both errors.
   - `pendingRecovery()` reports an interrupted operation still in the journal.
   - The message claims neither "unchanged" nor "recovered" without that evidence.
   - Tests: 4 new `AdminAppTest` tests: verified absence, unreadable link (publish and rollback), failed recovery (publish and rollback, using `BrokenAfterActivate`).

Verification (Docker; new disposable databases plus a disposable stack with its own docroot and storage on port 8090; all removed):
- PHPUnit 584 tests / 6,776 assertions; PHPStan max; CS clean; 171 JS tests.
- Playwright: `admin.spec.js` 12/12, `site.spec.js` 32/32, `stack.spec.js` 4/4.
- `nova` (schema 0004), `nova_test` and the preview `R-20261012-131225-9d1c` were untouched.

**Resume point:** the owner's review of Step 9, then the go-ahead for Step 10.

**Step 10 (inquiry endpoint), 2026-10-14, owner-requested (committed and pushed to GitHub `main`; awaits the owner's review, no gate approved):**

- Implemented per `05` §10 and §13 (details, configuration and verification: `nova/docs/inquiry.md`): migration 0008 (`inquiry`, `inquiry_item`, `inquiry_attempt`), `public_html/api/inquiry.php`, `app/src/Inquiry/`, `Nova\Admin\InquiryAdmin` and the A6 inbox, dashboard tiles and the Owner-only notification address (decision D), CLI `inquiry:notify` and `inquiry:purge`, PHPMailer 7.1.1 (E-3; `composer audit` clean).
- Each publish now writes `designation-index.json` (with the release id) into the release, and the endpoint matches only against the verified live release; older releases fall back to the embedded index.
- Notification failure never loses an inquiry; it is visible and retryable. The email carries no name, phone or message (E-12).
- Deliberate consequence of static pages for review: without JavaScript the first submission returns the filled form with a token to confirm (minimum fill time).
- Two defects found by the verification and fixed: the default sender was invalid for PHPMailer; error text contrast on the navy sheet was 2.5:1.

Verification (Docker; disposable databases and a disposable stack on port 8090; removed afterwards): PHPUnit 608 tests; PHPStan max; CS clean; 171 JS tests; Playwright `inquiry.spec.js` fa and en (JS and no-JS), `site.spec.js` 32/32; Mailpit delivery checked. `nova` (schema 0004), `nova_test` and the preview `R-20261012-131225-9d1c` were untouched.

**Limits:** the real notification address and the production SMTP mailbox (with SPF/DKIM) are launch inputs; `.htaccess` routing for `/api` is Step 13.

**Resume point (superseded below):** the owner's review of Steps 9–10, then Step 11.

**Step 11 (media core and SVG ingestion), 2026-10-14, owner-requested (committed and pushed to GitHub `main`; awaits the owner's review, no gate approved):**

- Implemented per `05` §12 and §26 (details, limits and verification: `nova/docs/media.md`): migration 0009, `Nova\Media\*` (SVG sanitiser with an allow-list rebuild plus `enshrined/svg-sanitize` as a second layer, image re-encoding with EXIF removal and orientation, a PDF check, content-hash storage, library services, documents and links, a retention-aware purge), the Admin Media and Documents screens, `public_html/media/.htaccess`, `app/bin/nova media:purge`, documents on Product pages and in the workspace Documents tab, the ALT-INF publish blocker, and `ReleaseMedia` so a purge never deletes a file a live or rollback release links to.
- Dependency added with the written justification of `05` §26 (D16): `enshrined/svg-sanitize`.
- Findings from verification, fixed: the browser regression suite caught a `ws.js` reference error that stopped the workspace rendering (6 failures); the publish SEO audit flagged `/media/...` links as broken (now accepted for hash-named media paths only); the file sweep could have deleted a file only a retained rollback release linked to (now protected, with a mutation check that the tests catch it).
- Not suppressed: an ignore rule added to PHPStan for the new test file was removed; the test code was fixed instead.

Verification (Docker; one disposable database and one disposable Apache stack on port 8091; removed afterwards): PHPUnit 667 tests / 7,333 assertions; PHPStan max with no errors; CS clean; `composer audit` clean; 171 JS tests; 35 real-Apache HTTP checks; Playwright `media.spec.js` 6/6, `admin.spec.js` 13/13, `site.spec.js` 32/32. `nova` (schema 0004), `nova_test` and the preview `R-20261012-131225-9d1c` were untouched.

**Limits:** the PDF check is best-effort and does not guarantee a harmless PDF; no page can use an image until Phase 4b adds Article-profile sections, so ALT-INF is tested at service level; `MediaReferences` must also read section content then; the production `.htaccess`, PHP upload limits and the real-host headers are Step 13 / Step 15 items.

**Resume point (superseded below):** the owner's review of Steps 9–11, then Step 12.

**Step 12 (backup and restore), 2026-10-15, owner-requested (committed and pushed to GitHub `main`; awaits the owner's review, no gate approved):**

- Implemented per `05` §18 (details, commands, restore procedures, off-site copy and limits: `nova/docs/backup.md`): `Nova\Backup\*` (consistent-snapshot logical dump with per-table row counts and content checksums; content-addressed incremental media store with manifests and full ZIP archives; 7/4/3 retention; verified `pre_migration` and `pre_restore` safety copies; explicit-target restore with empty-target, live-replacement confirmation, interruption marker and cleanup; idempotent repeats; libsodium-encrypted off-site export/import; first-request-of-the-day fallback when no scheduler runs), 13 CLI commands (`backup:*`, `restore:*`), the Admin Backups screen and dashboard status, audit of every success, refusal and failure. No migration was needed: the catalogue of sets is the manifest files, which survive a lost database.
- Dependencies: none added (libsodium and ZipArchive are already in the image).
- Findings from verification, fixed: restored databases lacked the two throttle tables (now structure kept, rows not backed up); the manifest claimed throttle tables a pre-0008 database does not have; retention was "last N months that have a set" instead of calendar windows; a normal backup could have run during a migration (now waits for the migration lock); restoring a new host's empty configured database needed an absurd confirmation (now only for a non-empty live database); a restore into the live database made `restore:verify` and idempotent repeats fail on the restore's own audit row (verification now covers audit rows up to the backup point); `backup:import` on a fresh, unmigrated host crashed on audit (audit is best-effort where its table does not exist yet); `MigrateCommandTest` still assumed the old "always refuse" guard (now: backed up and verified first; refused only if the backup cannot be made).
- Not suppressed: no PHPStan ignore rules were added; test code was fixed instead.

Verification (Docker; disposable databases `nova_s12*`, `nova_rt_*`, temporary folders and two disposable Apache stacks on ports 8092/8093; all removed): PHPUnit 723 tests / 7,792 assertions; PHPStan max with no errors; CS clean; `composer audit` clean; 171 JS tests; 22 real two-host checks; `admin.spec.js` 14/14. `nova` (schema 0004), `nova_test` and the preview `R-20261012-131225-9d1c` were untouched; nothing was restored over them.

**Limits:** a local proof, not the Phase 7 staging drill; no point-in-time recovery or per-table restore; the database dump and the media copy are not atomic with each other; the manifest checksum guards against damage, not an attacker with write access to the backup folder; the real host's cron, PHP CLI limits and provider backups are launch inputs.

**Resume point:** the owner's review of Steps 9–12, then Step 13 (the `.htaccess` template: routing, switch, headers, SVG serving, redirects).
