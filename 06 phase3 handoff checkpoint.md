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
- **Not yet built:** schema, Admin, Publish Engine, public-site port, import.
- **Step 1 is committed** as `5c7e33e`.
- **Step 3 is approved and committed** (owner review 2026-10-06; fullwidth-digit refinement; frozen choices recorded in `00`).
  - Spec: `nova/docs/designation-matching.md`.
  - Executable spec: `nova/tests/fixtures/designation/corpus.json`.
- **Next:** step 4 (migrations runner, schema v1, fixtures), only on the owner's go-ahead.

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

Next, after the owner's go-ahead: step 4 (migrations runner, schema v1, fixtures).

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
