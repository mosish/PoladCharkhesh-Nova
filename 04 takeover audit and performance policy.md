# PoladCharkhesh-Nova: takeover audit, Gate 2 closure and owner decisions D14–D18

Date: 2026-10-04. Claude Code (desktop app) took over from the Claude Cowork session and audited the whole workspace. Then, on the owner's instruction, it fixed T-1 to close Gate 2.

Status (updated 2026-10-06): **Gate 2 was APPROVED by the owner on 2026-10-06, and Phase 2 is complete and frozen (see `00`). Phase 3: `05 phase3 foundation plan.md` (revision 3, local-first) was approved (E-1) on 2026-10-06, and step 1 is done and verified; the host probe is deferred until hosting is purchased (D26). A fresh session starts from `06 phase3 handoff checkpoint.md`.** No production application, MariaDB schema, Admin implementation or Publish Engine exists.
- The takeover turn changed documentation only.
- The closure turn changed the corrected prototype for T-1 only (section 4), aligned its budget wording with D14/D17, and recorded D16–D18.
- Section 9 lists every change.

## 1. Owner decisions recorded in this session

### D14 Performance policy (supersedes the byte/request limits of D5)

Owner decision, 2026-10-04, in the owner's words:

- "The previous hard performance/file-size budget is permanently superseded." Website-size, transfer-size and request-count limits are removed as project constraints. "There is no fixed KB/MB budget anymore."
- "Do not spend development time trying to save a few KB or optimizing file size for its own sake." "Do not sacrifice visual quality, functionality, CMS capability, engineering usefulness, maintainability or development speed merely to reduce transfer size."
- "Performance still matters as normal engineering quality: avoid obviously wasteful assets/code, optimize media sensibly, use caching/lazy loading where useful, avoid serious user-visible performance regressions, maintain good real-world responsiveness." It is "a sanity/regression concern, NOT a size-budget objective."
- "The previous Gate 1 D5 byte/request limits must be considered superseded." The historical decision is kept, not erased.

**Superseded, kept as history:**
- D5's "≤ 120 KB / ≤ 8 requests first view" and the 150 KB ceiling.
- The per-asset and on-demand budgets in Genesis §I.
- The build check that fails when a budget is exceeded (§I Rules), and the "Lighthouse budget checks" in §N (Deploying code, step 1).
- The Core Web Vitals and Lighthouse numbers in §I as hard targets (LCP ≤ 1.8 s, CLS ≤ 0.05, INP ≤ 150 ms, Lighthouse ≥ 95). The owner confirmed this in D17.

**Not changed by D14:**
- **D1:** visitors still cost zero PHP and zero database queries. That rests on the shared host's CPU/RAM limits and on reliability, not on transfer size.
- Content-hashed assets with long caching, the §J media pipeline (responsive WebP sizes, lazy loading), and showing a PDF's size before download. These are ordinary good practice under D14.
- **Decision A** (Latin islands in JetBrains Mono) stays approved. Its original argument (−8.6 KB of font data) no longer applies, so the owner may revisit it on typographic grounds alone.
- The zero-third-party rule was outside D14's wording; D16 has since replaced it.

**Consequences for Phase 3** (nothing is built now):
- No byte or request budget gate in the build or in Publish validation. The A7 wireframe's page-budget check was removed in the closure turn.
- Performance QA looks for serious user-visible slowdowns and regressions. Measurements are diagnostics only (D17).
- Techniques adopted mainly to save bytes become optional and are judged on merit (maintainability against user-visible benefit): inline critical CSS with a deferred stylesheet (and the CSP hash it needs), per-language font subsets with unicode-range composites, `content-visibility`.

### D15 Project priority order

Owner decision, 2026-10-04:

1. Correctness and data integrity
2. Product and engineering usefulness
3. Excellent visual design and user experience
4. Product discovery and inquiry conversion
5. Admin/CMS completeness and usability
6. Maintainability and clean architecture
7. Security and reliability
8. Accessibility and responsive behavior
9. Practical real-world performance

File size itself is not a project objective. The owner accepted the takeover assessment, which reads the order this way: it settles trade-offs between goals. It does not remove baseline requirements already approved, such as the security controls in Genesis §H/§J/§K, or the keyboard/focus contract and WCAG AA intent shown in Phase 2.

### D16 Third-party dependency discipline (supersedes the absolute zero-third-party-requests rule)

Owner decision, 2026-10-04:

- The "zero third-party requests" rule (D5, Genesis §I) is no longer an absolute requirement. "This is no longer a zero-request rule; it is a dependency-discipline rule."
- Do not introduce unnecessary third-party dependencies. Core public functionality must stay robust and must not depend on unnecessary external services.
- A third-party service or dependency may be used when it brings meaningful product, engineering, UX, reliability, security or maintainability value. Each one is evaluated case by case.
- Do not add external fonts, trackers, analytics, CDNs or remote libraries merely for convenience when a simple local solution is better.

Unchanged by D16:
- C4 (no visitor tracking or public activity beacon in v1) is a separate approved decision.
- Genesis §I's rule that every dependency states its value against its cost fits D16 and stays.

### D17 Performance measurement (confirms and completes D14)

Owner decision, 2026-10-04:

- The previous numerical performance targets, including hard LCP, INP, request and transfer thresholds, are no longer Gate criteria or architectural constraints.
- No new numerical limits replace them.
- Core Web Vitals and other performance measurements are diagnostic signals only. Performance is judged by actual user-visible responsiveness and serious regressions.
- No development time goes into optimising metrics that are already healthy.

This withdraws the "sanity reference" thresholds suggested in the first version of this file.

### D18 Document authority

Owner decision, 2026-10-04:
- The local workspace governance documents are authoritative for continued development in Claude Code.
- Hosted copies are historical snapshots: the Genesis doc, the review hub and the concept boards. They are updated only if they are still actively used as a source of truth.

## 2. Current state

- **Stage 0 / Gate 1:** approved 2026-10-04 with clarifications C1–C4; decisions D1–D13.
- **Phase 2:** delivered: V1, the V2 visual refinement, the targeted correction round (items 1–12) and the T-1 fix. Owner answers Q1–Q5 are applied.
- **Gate 2:** approved on 2026-10-06. Owner decisions:
  - A approved;
  - B approved;
  - C approved (catalogue import in principle);
  - D (notification email) TBD;
  - E (inputs) open;
  - D14–D18.

  The owner approved G2-1–G2-16 on 2026-10-06.
- **Phase 3:** planning only (`05`); not implemented.

Evidence checked in this session:
- **Correction round** (pre-T-1 build):
  - `qa/scenarios.json`: 95/95.
  - `qa/axe2.json`: clean, apart from `html-has-lang` on the hub fragment.
  - `qa/perf-v2c.json` matches the documented figures.
  - At takeover, each zip was byte-identical to its folder.
- **T-1** (fixed build): `qa/scenarios-designation.json` 48/48 in fa and en. The same checks fail 18/48 on the build before the fix (`qa/scenarios-designation-before-t1.json`).
- **Not yet covered by anyone:** real Firefox/Safari runs, a screen-reader session, field performance data (Phase 3/6 QA).

## 3. Source of truth

| Topic | Current source | Historical |
| --- | --- | --- |
| Status, decision register, open inputs | `00 genesis summary.md` and this file | — |
| Architecture and Gate 1 decisions | `Polad Charkhesh Nova — Project Genesis (Stage 0).md` | The hosted Genesis doc (snapshot, D18). Parts are overridden by D14–D17 now, and by the Phase 2 proposals once Gate 2 is approved (section 5) |
| Latest design: prototypes, Admin wireframes, design system v0.3, review hub, QA | `design/phase2-v2-corrections/` (includes T-1; hub at `site/index.html`) | `design/phase2-v2-corrections.zip` (before T-1), `design/phase2-v2/`, `design/phase2/`, the hosted hub (snapshot before T-1) |
| Correction round and T-1 | `03 phase2 v2 corrections.md` | — |
| V2 refinement, V1 | `02 phase2 v2 visual refinement.md`, `01 phase2 design system.md` | 01 partly superseded by 02 |
| Review history | `Gate-2-review.md` (the external review behind items 1–12), `Nova Phase 2 Review.md` (V1 hub), `Nova Phase 2 V2 Review.md` (V2 hub before corrections), `Polad Charkhesh Nova Concepts.md` (Stage 0 concept boards) | Historical; not edited |

**Precedence:**
- Owner decisions override Gate 1 text where they conflict, newest first: D14–D18, then Gate 2 decisions A–E, then Q1–Q5.
- Phase 2 proposals that change Genesis details (G2-1–G2-16) take effect when Gate 2 is approved.

**Paths:** older documents cite `claude/0X-….md`. Those are the root files `0X ….md`.

## 4. Correction-round review and T-1

No blocking defect remains. Items 1–12 meet the acceptance criteria in `Gate-2-review.md`, and T-1 is fixed.

**T-1 Reverse-direction "series" wording: fixed before Gate 2** (owner instruction, 2026-10-04).
- **Before:**
  - Asking for 6305 (only 6305-2RS listed) said "“6305” is listed as base series 6305-2RS".
  - The workspace note, WhatsApp text and inquiry context also called 6305-2RS the base series and 6305 a variant.
  - `22220` → 22220 E and a half-typed `6305-2` did the same.
- **After:**
  - A separate `related` match type. For 6305 the page shows "Related match — requested designation requires confirmation".
  - In all four places, 6305-2RS is named as a suffixed variant of 6305.
  - 6305-2RS and 22220 E stay exact matches.
  - The requested designation stays exact. No data was added and the layout did not change.
- **Proof:** the focused re-run passes 48/48 on the fixed build; on the pre-fix build 18/48 fail. Details, files and screenshots: `03`, section "T-1 fix before Gate 2".

**Q-DM1 (Phase 3 data-model question; not blocking).** Under option B, what does a base Product row stand for when only a suffixed designation is supplied (for example, only 6305-2RS)? The prototype keeps the listed suffixed designation and treats the plain request as a related match.

**T-2 Budget references in the Gate 2 material: resolved in the closure turn.**
- G2-12 was reworded.
- The A7 page-budget check was removed.
- The design-system ledger and the hub's budget columns are labelled as superseded (D14/D17).

## 5. Contradictions register (older vs newer)

| # | Older | Newer | Status |
| --- | --- | --- | --- |
| 1 | D5 budgets (Genesis §I/§M/checklist, `00`, hubs, READMEs, `system.html#perf`, A7) | D14, D17 | Superseded and recorded. A7 check removed; the prototype's budget labels marked superseded |
| 2 | D5: LCP ≤ 1.8 s | Correction-round tables label FCP ≤ 1.8 s and LCP ≤ 2.5 s, with no owner decision | Moot under D17; historical inconsistency |
| 3 | `00` and the Gate 1 checklist: ≤ 120 KB | §I and §M: 120 KB target, 150 KB ceiling | Moot under D14 |
| 4 | Zero third-party requests (D5, Genesis §I) | D16 dependency discipline | Superseded |
| 5 | Suffixes as aliases (Genesis §D "2RS / 2RSH / DDU", §H `Product.aliases`) | Spelling ≠ ProductVariant, option B (`03`, `admin.html#variants`, G2-13) | Superseded when Gate 2 is approved; the Phase 3 content model follows it |
| 6 | `series` covered both directions (correction round) | `series` and `related` split (T-1 fix) | Superseded; G2-13 updated |
| 7 | Product URL `/p/<slug>` and `/en/p/<slug>` (§D) | Same path on .ir and .com; `/en/` only in fallback mode (§K, D6 revised) | D6 governs |
| 8 | Concentric hero mark (D2, §E) | Split metal/line-drawn bearing hero; the concentric mark stays as the placeholder logo | Superseded by decision B |
| 9 | One dark band, at Anatomy only (§E hybrid) | Navy fields for hero, Anatomy, contact and footer (G2-1) | Superseded when Gate 2 is approved |
| 10 | Workspace: 4 tabs + sticky ask footer; sheet snaps to 90% (§D, V1) | d/D/B readout + 3 tabs + CTA footer; content-sized sheet ≤ 88dvh (G2-6) | Superseded when Gate 2 is approved |
| 11 | Exploded Assembly ~250vh (§F); 260/200vh (V1) | Six stages, 320/190vh (G2-7) | Superseded when Gate 2 is approved |
| 12 | Persian digits for phones on fa (V1) | Latin digits in both languages (Q2) | Superseded |
| 13 | Admin wireframes at the start of Phase 4b (V1 hub, Q4 proposal) | Before Gate 2 (owner's Q4 answer) | Done |
| 14 | §H screens include an SEO overview and a System screen | A1–A9; SEO overview, Users, Audit log and Backups "reuse the same two patterns" and are not drawn | Known gap; Phase 4b scope |
| 15 | Latin islands justified by −8.6 KB (V2) | D14 | Decision A stands; byte rationale moot |
| 16 | Inline critical CSS + CSP hash as "the D5 plan" (`02`, `03` carry-overs) | D14 | Optional; Phase 3 decides on merit |
| 17 | Build fails on budget; Lighthouse budget checks (§I, §N) | D14, D17 | Superseded |
| 18 | Genesis says Phase 2 sources are "under design/phase2"; `00` linked the hosted Genesis doc as the "Full doc" | V2 and the correction round have their own folders; local documents are authoritative (D18) | Stale pointer left in the export; hosted copies are snapshots |

## 6. Open inputs and remaining work

**For Gate 2 (owner):** the explicit final approval only. The questions from the first audit were answered on 2026-10-04:
- the zero-third-party rule (D16);
- the Core Web Vitals reading (D17);
- T-1 (fixed).

**Before Phase 3** (Genesis "Before Phase 3" checklist; all items still open):
- Exact domain names.
- Host probe: PHP and MariaDB versions, extensions, `rename()`/`symlink()`, Apache modules, AutoSSL for all four hostnames, SMTP with SPF/DKIM, cron, quotas, gzip/Brotli. No probe script exists in the workspace yet.
- Provider backup retention and restore procedure.
- Old catalogue export or repository access.
- Logo SVG.
- Verified brands and data sources.
- Optional contact channels, Thursday/Friday hours (and whether Sat–Wed 08:00–16:00 is right), map coordinates.
- Inquiry notification email (decision D).

Suggested split (owner decides):
- Hard prerequisites for Foundation: the host probe, domains and catalogue export.
- Can arrive during Phase 4: logo, hours, map, brands, channels and the notification email. These are needed before launch, not before Foundation.

**Phase 3 carry-overs:**
- Content-hashed asset names.
- A CSP hash, if inline critical CSS is kept.
- Housing/unit core-dimension definitions.
- The per-family suffix list (it also drives related matches).
- Admin colour and semantic-control requirements.
- Q-DM1.
- The production URL form for variant and related requests (`/p/6205?r=6205-2RS` in `admin.html#variants`).
- Real-browser and screen-reader QA.
- A full re-run of `qa/scenarios.js` (only the designation group was re-run for T-1).

## 7. Gate 2 closure status

**Approved by the owner on 2026-10-06.** The approval covers G2-1–G2-16, the T-1 correction, the 48/48 focused result and the G2-11/G2-12 updates. At closure, nothing blocked Gate 2. The first audit's approval notes are resolved:
- N1 by D14/D17 (G2-12 reworded, A7 check removed);
- N2 by fixing T-1;
- N3 by D16.

N4 (open the hub on your own phone) remains optional; only Chromium and Chrome have been tested.

## 8. Next step after Gate 2 approval

Done on 2026-10-06 after the approval: the Phase 3 Foundation Plan (`05 phase3 foundation plan.md`) and the read-only host probe (`tools/host-probe/`) were delivered for approval. No production code, schema or database exists. The step as planned at closure:
1. the Phase 3 Foundation plan;
2. the read-only host probe script described in Genesis §N.

The owner runs the probe and sends its results, the domain names, and the catalogue export or repository access. No production code, schema or database is created until that plan is approved.

## 9. Changes in this session

**Takeover turn (documentation only):**
- Added this file.
- `00 genesis summary.md`: status line, path note, D5 marked superseded (original text kept), D14 and D15 added.
- Genesis file: dated notes under the byline, on the Summary bullet, at the top of §I, in the §M D5 row and on the Gate 1 checklist D5 line. All original text kept.
- `02`, `03`: D14 notes on their performance sections.

**Gate 2 closure turn:**
- **Prototype** (`design/phase2-v2-corrections/`):
  - `src/js/nova.js`: T-1 matcher and wording.
  - `src/strings.py`: T-1 strings in fa and en.
  - `src/admin.html.j2`: `related` match type; A7 page-budget check removed.
  - `src/build.py`: hub closure block, ledger wording, entity map; unused budget helper removed.
  - `src/hub.html.j2`: T-1 QA table, superseded-budget labels.
  - `qa/scenarios.js`: focused mode, Chrome channel, T-1 checks.
  - New files: `qa/scenarios-designation.json`, `qa/scenarios-designation-before-t1.json`, `site/shots-v2c/t1-before.webp`, `site/shots-v2c/t1-after.webp`.
  - Rebuilt `site/`: `fa.html`, `en.html`, `assets/nova.js`, `admin.html`, `system.html`, `index.html`.
- **Documents:** `00` (D16–D18, T-1, status), this file (D16–D18, closure), `03` (T-1 section, policy), `02` (D17 note), Genesis file (D16–D18 notes), prototype `README.md`.
**Gate 2 approval turn (2026-10-06):**
- The owner's approval was recorded in `00` (status, the frozen-baseline section, roadmap), `01`–`04`, the Genesis file and the prototype README. `design/` code was not touched.
- Added `05 phase3 foundation plan.md` (for approval) and the read-only host probe in `tools/host-probe/`, tested on PHP 8.4.26 and 7.0.33.
- Nothing was implemented for Phase 3.

**Plan revision turn (2026-10-06):**
- Owner decisions D19–D24 were recorded in `00`, and D14/D17 reconfirmed. `05` was rewritten as revision 2:
  - versions as preferred targets until the probe;
  - the Product-as-family model with seven match categories;
  - the SVG policy proposal;
  - the three-layer content model;
  - backups by kind with restore proof;
  - the tooling policy;
  - the reclassified approval matrix.
- The host probe was regenerated (version 1.1.0) with a new key that is stored in no file (hash only); the earlier key is revoked. Nothing was implemented, and Docker was not started.

**Local-first checkpoint turn (2026-10-06):**
- E-2 (refined: V-1–V-5 not frozen), E-7 and E-8 approved.
- D25 (local-first, Docker) and D26 (probe deferred to pre-deployment) recorded in `00`; D14/D17 reconfirmed.
- `05` updated to revision 3 (header addendum, §30–§32, §34); probe README marked deferred; `06 phase3 handoff checkpoint.md` added.
- No code, Docker environment, repository or schema was created.

**Phase 3 step 1 turn (2026-10-06):**
- E-1 approved; local implementation authorized.
- Created the git repository (`main`, no commits yet; `tools/host-probe/` ignored), `.gitattributes` (LF) and `.gitignore`, plus root `AGENTS.md`/`CLAUDE.md`.
- Created the `nova/` Docker dev stack (PHP 8.4.26 + Apache, MariaDB 11.4.13, Mailpit), the Composer/PHPUnit/PHPStan/PHP-CS-Fixer and Playwright/axe toolchain, smoke tests, and `nova/docs/local-baseline.md`.
- Verified from a clean rebuild: CS clean, PHPStan max with no errors, PHPUnit 31/31, Playwright 4/4.
- No schema, domain code or production page was created.

**Phase 3 step 3 turn (2026-10-06):**
- Initial commit `5c7e33e` (step 1 baseline, local git identity). Step 3 is left uncommitted for review.
- Added the behavioural spec `nova/docs/designation-matching.md` and the shared corpus `nova/tests/fixtures/designation/corpus.json`:
  - 34 normaliser vectors;
  - 12 requested-text vectors;
  - 80 match cases over 3 labelled fixture catalogues;
  - 14 invalid-index cases.
- Added the PHP (`app/src/Designation/`) and JS (`assets-src/js/designation.js`) implementations.
- Verified: PHPUnit 174/174, node:test 145/145, PHPStan max clean, 17/17 JS mutants and 3/3 PHP mutants killed, PHP/JS differential fuzz 60,012 comparisons with 0 differences.
- No schema, migration, import or Gate 2 change.

**Step 3 review turn (2026-10-06):**
- The owner approved step 3 with one refinement: fullwidth digits ０–９ fold to ASCII.
- Added 16 normaliser vectors (all ten digits plus mixed/full cases, with the fullwidth solidus and letters NOT folded), 1 requested-text vector and 9 match cases.
- Same change in PHP and JS. Re-verified:
  - PHPUnit 200/200; node:test 171/171; PHPStan max and CS clean; Playwright 4/4;
  - 21/21 JS and 5/5 PHP mutants killed;
  - PHP/JS differential fuzz: 72,015 comparisons, 0 differences.
- Choices frozen in `00`. Step 3 committed; `design/` untouched; no step 4 work.

- **Not edited (all turns):**
  - the hosted documents (historical snapshots, D18);
  - `design/phase2-v2-corrections.zip` (kept as the pre-T-1 delivery);
  - `design/phase2/`, `design/phase2-v2/`;
  - the review and concept exports.
