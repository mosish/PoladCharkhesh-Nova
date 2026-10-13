# PoladCharkhesh-Nova — Stage 0 Genesis (Gate 1 and Gate 2 APPROVED)

Created 2026-10-04; revised same day for confirmed hosting. Gate 1 approved 2026-10-04 with clarifications C1–C4 (incorporated below and in the full doc). Phase 2 delivered in three rounds: V1 (claude/01-phase2-design-system.md), V2 visual refinement + admin wireframes (claude/02-phase2-v2-visual-refinement.md), and the V2 targeted correction round, items 1–12 (claude/03-phase2-v2-corrections.md). **Gate 2 was APPROVED by the owner on 2026-10-06. Phase 2 is complete and frozen as the production baseline (see "Gate 2 approval and frozen production baseline"). Phase 3 implementation is IN PROGRESS, local-first (D25): E-1 was approved and local implementation authorized on 2026-10-06. Step 1 is complete and verified: the git repository, AGENTS.md/CLAUDE.md, the Docker dev stack and the toolchain; the local baseline is recorded in `nova/docs/local-baseline.md`. Step 1 was committed as the baseline (`5c7e33e`). Step 3 (designation corpus, normaliser, matcher) was approved by the owner with one refinement (fullwidth digits) and committed. Steps 4–8 (schema v1, catalogue and provenance, rich content, public-site port, Publish Engine) were then implemented, verified and committed under the owner's standing delegation of ordinary technical decisions (`ec96a09` … `31237e8`; log in `06` §14). They are not separately owner-reviewed. The Step 8 repairs from the 2026-10-12 security review (storage containment, no direct `/_site/` access, CLI-only SAPI and audit origin, journalled rollback, snapshot-true and idempotent recovery, the strategy D grace window) are verified locally (`nova/docs/release-routing.md`). Step 9 (Admin sign-in with Owner TOTP, the Admin shell from the frozen A1–A9 wireframes, the Product/variant vertical slice, publish and rollback from the Admin, audit) is implemented and verified locally (`nova/docs/admin.md`). The Step 8 repairs and Step 9 are not committed and await the owner's review. Steps 10–16 have not started. Nothing is deployed. The host probe is deferred until hosting is purchased (D26). A fresh session starts from `06 phase3 handoff checkpoint.md`.**

Takeover and Gate 2 closure, 2026-10-04: Claude Code took over from the Cowork session and audited the workspace (see `04 takeover audit and performance policy.md`).
- Owner decisions D14–D18 were recorded the same day.
- T-1, the reversed base/variant wording found at the audit, was fixed in the corrected prototype; the focused re-run passes 48/48.
- The owner then approved Gate 2 on 2026-10-06.
- Paths: files cited as `claude/0X-….md` are the root files `0X ….md`.

Hosted copies are historical snapshots (D18); the local files are authoritative:
- Full doc: https://claude.ai/code/artifact/d2eeef57-ea9e-47d8-85d1-327219c269ed (matches the local Genesis export as of rev 47)
- Stage 0 concept boards: https://claude.ai/artifact/H9uhLDuNiNsVmy31YebUim
- Phase 2 review hub, before T-1: https://claude.ai/artifact/HTMqnj38bZshFxzvHpvXi7. The current hub is `design/phase2-v2-corrections/site/index.html`.

## Confirmed hosting (from provider, relayed by Mosi)
- cPanel shared hosting, finite resources per plan.
- MariaDB officially provided; SQLite not managed (provider: app must verify WAL/locking).
- Node.js 20.20 / 22.23 / 24.20 available.
- Built-in hosting backups.
- .ir and .com can both be parked on the same account (Park Domain).
- Note (owner, 2026-10-06; D25/D26): these were the provider's stated capabilities. Production hosting has not yet been purchased. The final host will be chosen to fit Nova, then probed before deployment; nothing here constrains local development.

## Approved decisions (D1–D26; D14–D18 added 2026-10-04, D19–D26 on 2026-10-06)
- D1 Architecture: PHP 8 app (target 8.3+) + MariaDB (InnoDB, utf8mb4, FKs, strict mode, migrations) as source of truth + PHP Publish Engine: lock → consistent snapshot → validate → build release → verify manifest → atomic switch → record; keep 5 releases. Visitors: zero PHP, zero DB queries. The ONLY public PHP is the inquiry endpoint. No Node in production; Node 24 for local tooling only (the prototype build uses terser on the workstation only).
- D2 Creative: "Drawing Sheet" + editorial type scale + concentric hero mark. Palette #2F338A / #2196F3 / #90CAF9. (Refined in Phase 2 V2: Drawing Sheet × deep-navy fields × restrained metal × one brass accent — approved at Gate 2 on 2026-10-06.)
- D3 Scroll: "Drawing Set" page-wide + "Exploded Assembly" set-piece in Anatomy.
- D4 IA: search-first one-page order.
- D5 Performance: ≤120 KB / ≤8 requests first view, LCP ≤1.8 s, zero third-party requests. (V2 prototype already applies the planned inline-critical-CSS split.) **→ SUPERSEDED by D14 (2026-10-04): the byte/request limits and hard timing targets no longer apply; this text is kept as history. The zero-third-party-requests rule is superseded by D16 (dependency discipline).**
- D6 Domains: one docroot; .htaccess routes by Host: .ir → Persian, .com → English; same ASCII slugs; canonical per domain; hreflang fa/en; www→apex, http→https; internal folders blocked; no auto-redirect by language/IP; /en/ fallback mode if one domain isn't live.
- D7 Vazirmatn + JetBrains Mono. D8 v1 tools: decoder, indicative L10, unit/fit reference.
- D9 (C1): pending/unverified products MAY be discoverable in the internal Product Explorer with a "Technical data pending / Unverified" badge, but are noindex and excluded from the XML sitemap until verified. Internal search ≠ search-engine indexing.
- D10 x-default = .com. D11 admin only at .ir/admin. D13 retention: 5 releases; backups 7 daily/4 weekly/3 monthly; weekly off-site download.
- D12 (C3): PHP 8.3+ / MariaDB 10.11+ are TARGETS until the host probe; code stays within a conservative baseline (PHP 8.1 language level; SQL common to MariaDB 10.5+/MySQL 8.0; explicit utf8mb4_unicode_520_ci); actual minimums frozen after the probe.
- D14 Performance policy (owner, 2026-10-04; supersedes D5's byte/request limits). There is no KB/MB, transfer-size or request-count budget. Don't spend time saving a few KB, and don't trade visual quality, functionality, CMS capability, engineering usefulness, maintainability or development speed for transfer size. Performance is normal engineering quality and a sanity/regression check: no obvious waste, sensible media, caching and lazy loading where useful, no serious user-visible regressions, good real-world responsiveness. D1's static delivery (zero PHP, zero queries per visitor) is unaffected. Phase 3 consequences: no budget gate in the build or in Publish (the A7 budget check has been removed); byte-saving tactics are optional and judged on merit. Details: `04 takeover audit and performance policy.md`.
- D15 Priority order (owner, 2026-10-04): 1 correctness and data integrity · 2 product and engineering usefulness · 3 excellent visual design and user experience · 4 product discovery and inquiry conversion · 5 Admin/CMS completeness and usability · 6 maintainability and clean architecture · 7 security and reliability · 8 accessibility and responsive behavior · 9 practical real-world performance. File size itself is not an objective.
- D16 Third-party dependency discipline (owner, 2026-10-04; supersedes the absolute zero-third-party-requests rule of D5/Genesis §I). It is no longer a zero-request rule. Add no unnecessary third-party dependencies. Core public functionality must stay robust without unnecessary external services. A third-party service or dependency may be used when it brings meaningful product, engineering, UX, reliability, security or maintainability value, evaluated case by case. No external fonts, trackers, analytics, CDNs or remote libraries merely for convenience when a simple local solution is better. C4 (no visitor tracking or activity beacon in v1) is unchanged.
- D17 Performance measurement (owner, 2026-10-04; confirms and completes D14). The previous numerical performance targets (hard LCP, INP, request and transfer thresholds) are no longer Gate criteria or architectural constraints, and no new numerical limits replace them. Core Web Vitals and other measurements are diagnostic signals only. Performance is judged by actual user-visible responsiveness and serious regressions. No time is spent optimising metrics that are already healthy.
- D18 Document authority (owner, 2026-10-04): the local workspace governance documents are authoritative for development in Claude Code. Hosted artifacts are historical snapshots and are not updated merely to mirror local documents.
- D14 and D17 reconfirmed (owner, 2026-10-06). There is no file-size, transfer, request-count, JavaScript/CSS/image KB budget, no LCP/INP/CLS hard target, and no replacement numeric performance gate. Performance is hygiene and regression monitoring only.
- D19 Runtime version policy (owner, 2026-10-06; revises E-4/E-5 of the Phase 3 plan):
  - PHP 8.3+ and MariaDB 10.11+ are preferred targets, not hard blockers until the host-probe results exist.
  - After the probe, Claude recommends the final minimums from capability, support lifecycle, compatibility, maintainability and Nova's needs.
  - The architecture is not degraded or complicated for an obsolete host. The owner will upgrade the plan or change host where there is a meaningful technical reason.
- D20 Product/ProductVariant model (owner, 2026-10-06; revises Q-DM1):
  - The Product is the stable family/designation entity (e.g. 6305). It never implies that the plain item is supplied unless `base_listed` is on.
  - No ProductVariant becomes the representative identity of the Product. `/p/<product>` presents zero, one or many listed variants.
  - Each listed variant keeps its exact designation, listing state, sourced values, provenance, documents and inquiry identity.
  - No values are copied between designations, and no shared values are assumed without a verified source.
  - Search distinguishes exact listed Product, exact listed variant, known spelling/alias, base-series relationship, related match, unlisted requested variant and unknown designation.
  - The exact requested designation is preserved end to end.
  - Unverified suffix/prefix/variant relationships stay explicitly unresolved.
- D21 SVG (owner, 2026-10-06; revises E-7): no blanket ban. A secure SVG policy is required, and Editors must never upload arbitrary unsanitized SVG. Proposal: `05` §26.
- D22 CMS content (owner, 2026-10-06; revises E-8):
  - Technical/product data stays strongly structured.
  - Editorial content gets constrained structured rich content (paragraphs, headings, lists, links, emphasis, justified tables).
  - No raw HTML and no page builder.
  - Structured data, editorial rich content and media/document references stay clearly separate. Proposal: `05` §27.
- D23 Backups (owner, 2026-10-06):
  - Scheduled execution is preferred; a first-Admin-visit trigger is only a fallback when no reliable scheduler exists.
  - The layered strategy is kept, with application, database, media/document, release-rollback, hosting-provider and off-site copies clearly distinguished.
  - Restore must be proven; a never-restored backup is not complete.
- D24 Tooling policy (owner, 2026-10-06):
  - Superdesign for genuinely new production UI, without reopening the Gate 2 direction.
  - Chrome DevTools MCP and claude-in-chrome for runtime inspection.
  - Playwright for regression.
  - Security guidance while security-sensitive parts are built.
  - Document/data tools only for real project work.
  - A proportional UI quality loop.
  - Tools are used only where they materially improve the result. Details: `05` §29.
- Operational rule (owner, 2026-10-06): temporary access keys (e.g. for the host probe) are given only in the run instructions in chat. They are never written into governance documents or production repository history; files store at most a hash.
- E-2, E-7, E-8 approved (owner, 2026-10-06):
  - **E-2:** the D20 model, with one refinement. Uncertain manufacturer/product-code semantics are not frozen; V-1–V-5 stay source-verification issues. Internal category names such as "base-series match" may be refined later if verified evidence requires it. Relationships are never inferred, and values are never copied between designations, without evidence.
  - **E-7:** the secure SVG policy (`05` §26).
  - **E-8:** the three-layer content model (`05` §27).
- **D25 Local-first development** (owner, 2026-10-06):
  - Phase 3 and all later development and QA run locally in Docker. PHP, MariaDB and server-side dependencies are containerised; nothing server-side is installed natively on Windows.
  - Local baseline: PHP 8.4 with Apache (`.htaccess` active) and MariaDB 11.4 LTS. The first implementation task records the exact image versions; code targets that baseline.
  - Production hosting is not yet purchased and must not block implementation. It will be chosen to fit Nova. The architecture is not weakened for an unknown host (D19).
- **D26 Host probe deferred** (owner, 2026-10-06):
  - The probe runs near Production Candidate, after hosting is purchased and before production deployment, regenerated with a new key.
  - Its results freeze the production PHP/MariaDB minimums and the release-switch mechanism.
  - The tooling is kept in `tools/host-probe/`. No probe report is needed to start or continue local work.
- Step 3 owner review (2026-10-06): designation normalisation and matching approved, with fullwidth digits ０–９ folded to ASCII like Persian/Arabic digits (character folding only, no inference).
  - Frozen: the 64-code-point requested-designation limit; English-only case folding; no folding of look-alike slashes into "/"; invisible characters kept in the stored verbatim requested designation and removed only from the matching key.
  - V-1–V-5 stay unresolved as documented.
  - Spec: `nova/docs/designation-matching.md`; executable spec: `nova/tests/fixtures/designation/corpus.json`.
- Step 4 decisions (owner, 2026-10-06):
  - Pre-migration backup is required. Until step 12 exists, an explicit `--no-backup` bypass is allowed only in local development; it is visible and logged, and impossible in staging/production.
  - Schema v1 is limited to the catalogue/core tables. Content, publish/journal, sessions/throttling, inquiries, media and backup records come in later migrations.
  - Internal keys are auto-increment integers and never business identity; public identity is slugs and designations.
  - The schema design reviewed in the same turn was accepted. From then on the owner delegated ordinary technical decisions for Phase 3 (stop only for business/UX, unresolved-semantics, irreversible public-URL, architecture, destructive/production-data or owner-input decisions).
- D14/D17 reconfirmed again (owner, 2026-10-06): no byte, file or request budgets; no numeric CWV/performance gates; measurements are for diagnosis and regression only; no time on arbitrary micro-optimisation.

## Gate 1 clarifications
- C2 Publishing: symlinks are NOT a hard dependency. Strategy S (symlink swap) where allowed; Strategy D (two-slot directory switch: _site/slot-a, _site/slot-b, chosen per request by an `ACTIVE_B` marker file; flip = one rename/unlink; archive in storage/releases; ≥2 min grace before reusing a slot; journal for crash recovery). Naive rename of the live directory rejected (404 window, no-site-on-crash).
- C4 Public activity logging: REMOVED from v1 (no beacon.php, no search-miss entity). Demand signal = inquiries naming unlisted codes (MariaDB) + server statistics. Post-launch option only: one batched sendBeacon per session to a capped file.

## Phase 2 answers from Mosi (applied in V2)
Q1 dark mode approved (automatic, no toggle, not glow-heavy). Q2 phone numbers in Latin digits in both languages. Q3 concentric mark stays a placeholder; real logo later. Q4 admin wireframes before Gate 2 (delivered, not implemented). Q5 copy stays draft.

## Gate 2 decisions from Mosi (given with the correction brief; Gate 2 approved 2026-10-06)
A Latin islands in JetBrains Mono on Persian pages: APPROVED (narrow rule; longer English prose not forced into mono). B Hero bearing: APPROVED. C Persian-only Admin UI: APPROVED; Owner-only "Verified for indexing": APPROVED; catalogue import/migration: APPROVED IN PRINCIPLE (audit, repeatable validated importer, keep trustworthy provenance, safe pending defaults, reconciliation report; CSV is not the only path). D Inquiry notification email: TBD, Admin/CMS-configurable, never hardcoded; SMTP via host probe. E Remaining inputs stay open.

## Gate 2 approval and frozen production baseline (2026-10-06)
The owner approved G2-1–G2-16 on 2026-10-06. This accepts the T-1 correction, the focused 48/48 designation/variant result and the G2-11/G2-12 updates. **Phase 2 is complete.**

Frozen as the baseline for production development:
- **Design direction and system:** Drawing Sheet × deep-navy fields × restrained metal × one brass accent. Covers tokens, type scale, digit and RTL rules, automatic dark mode and reduced motion (G2-1–G2-4, G2-8–G2-10; decisions A and B, Q1, Q2). Reference: `design/phase2-v2-corrections/site/assets/nova.css` and `site/system.html` (design system v0.3).
- **Interaction contracts:**
  - Explorer: global exact search; "not in catalogue" kept distinct from "hidden by filters".
  - Product workspace: one active selection, a stable shell with its states, the focus/Escape contract, and the mobile sheet with three CTAs.
  - Exploded Assembly.
  - (G2-5–G2-7.) Reference behaviour: `src/js/nova.js`, `src/js/ws.js`, `qa/scenarios.js`.
- **Product/Variant semantics:**
  - Product + ProductVariant (option B), with Spelling kept separate.
  - The requested designation is kept verbatim.
  - Match precedence: exact › variant › series / related › none.
  - A listed suffixed designation is never called the base (G2-13, T-1).
  - For production, D20 (2026-10-06) refines this into seven match categories and the Product-as-family model. The Gate 2 behaviour remains the reference for wording and for preserving the requested designation.
- **Provenance UX:** per-value status, source key and checked date; pending stays pending (G2-14).
- **Publishing rules:**
  - Draft → Publish with a validation gate. ALT-INF blocks the affected content; the sitemap never lists noindex URLs; there is no size or request budget check.
  - Pending products are discoverable but noindex (D9/C1).
  - "Verified for indexing" is Owner-only (G2-15; decision C).
- **Admin IA:** A1–A9 with the entity map; Persian-only UI; A1–A8 desktop/tablet-first; A9 mobile triage (G2-11; decision C).
- **Catalogue migration plan** (G2-16).
- **Owner decisions:** D1–D18, C1–C4, Q1–Q5 and Gate 2 decisions A–E. D14–D18 remain authoritative.

Change control:
- The prototype is the reference for behaviour and appearance, not production code.
- `design/` is not edited further. Phase 2 is reopened only if production implementation exposes a genuine defect.
- Changing the baseline needs an owner decision.

## Designation policy (approved at Gate 2 as G2-13; not built)
Product + ProductVariant (option B), with Spelling as a separate table. The requested designation is kept verbatim from search to inquiry to Admin, and base-series matches need confirmation. The plain designation of a listed suffixed one (6305 → 6305-2RS) is a related match, and the listed one is never called its base (T-1 fix, 2026-10-04). Details: claude/03-phase2-v2-corrections.md. Q-DM1 was revised by the owner on 2026-10-06 (D20): the Product is the stable family/designation entity with an explicit `base_listed`, and each listed variant is a distinct item. The detailed rules are in `05 phase3 foundation plan.md` §25; E-2 was approved on 2026-10-06, refined as recorded under D25–D26 above (V-1–V-5 not frozen).

## Backups (4 layers)
Release history → daily PHP-written MariaDB dump + media manifest (also before migrations/restores) → weekly off-site download by owner → provider snapshots (disaster only).

## Roadmap
1 Discovery/architecture → Gate 1 ✔ → 2 Design system & key screens (V1 + V2 + correction round delivered) → Gate 2 ✔ (approved 2026-10-06) → 3 Foundation (in progress, local-first in Docker: steps 1 and 3 done and committed 2026-10-06; step 4 next; host probe deferred to pre-deployment) → 4a Public + 4b Admin (parallel) → 5 Engineering tools → Gate 3 → 6 QA → Gate 4 → 7 Deploy & handover.

## Open inputs needed from Mosi
Exact domain names; host probe results, deferred to after hosting is purchased (D26) and not needed for local Phase 3 (PHP/MariaDB versions, extensions, symlink, AutoSSL for 4 hostnames, SMTP, cron, quotas); provider backup retention; old catalogue export / repo access; logo SVG; verified brands/sources; optional contact channels, Thu/Fri hours (and confirm Sat–Wed for 08:00–16:00), map coordinates; inquiry notification email address.

Takeover-audit questions answered on 2026-10-04:
- zero-third-party rule → D16;
- Core Web Vitals reading → D17;
- T-1 → fixed before Gate 2;
- D15 reading → accepted with the takeover assessment.

None of these blocked Gate 2. In `05 phase3 foundation plan.md` revision 3:
- the return checklist (before local implementation / during / before production deployment / before launch) is §30;
- the approval matrix is §31;
- the remaining decisions are §32;
- the risks and the bearing-domain assumptions V-1–V-8 are §33.
