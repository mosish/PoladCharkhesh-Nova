# PoladCharkhesh-Nova: Phase 2 V2 targeted correction round (before Gate 2)

Date: 2026-10-04.

Status: correction items 1–12 are done and re-verified. T-1, found at the takeover audit, was fixed before Gate 2 on 2026-10-04 (see "T-1 fix before Gate 2" below). **Gate 2 was approved on 2026-10-06; this round, with T-1, is part of the frozen production baseline. Phase 3 is in planning (no implementation).** No MariaDB schema, Admin implementation or Publish Engine exists, and no catalogue data was migrated. The visual direction, hero, explorer, workspace, Exploded Assembly concept and Admin IA are unchanged; this was not a redesign.

- **Review hub:** the current hub is the local `design/phase2-v2-corrections/site/index.html` (includes T-1). Its `#corrections` section holds the report, before/after images, QA, axe and the performance delta. The hosted copy (https://claude.ai/artifact/HTMqnj38bZshFxzvHpvXi7) is a historical snapshot from before T-1 (owner decision D18).
- **Files:**
  - Corrected V2: `design/phase2-v2-corrections/` in the user folder, now including the T-1 fix. `phase2-v2-corrections.zip` is the correction round as delivered, before T-1.
  - V2 as first delivered: `design/phase2-v2/`.
  - V1: `design/phase2/`.
- **Input:** the owner's correction brief (items 1–12), which matches the external review in the user folder (`Gate-2-review.md`).

## Corrections (summary)
1. **Selection/request consistency.** One active selection: {slug, requested designation, match type, token}.
   - It drives the row highlight, workspace, URL hash, copy/link, WhatsApp and inquiry.
   - A query or filter that hides the open product closes the workspace.
   - Each new selection aborts the previous fetch through an AbortController, and stale responses are dropped by token.
   - Loading shows only the new product's identity and CTAs.
2. **Exact search vs filters.**
   - Hero search and example chips search globally: they clear conflicting filters and announce it in a live region.
   - The explorer separates "not in catalogue" (checked against the whole catalogue) from "hidden by filters" (reveal action, or count + reset).
3. **Variants.**
   - Spellings and variants are separate keys. The requested designation is kept verbatim end to end.
   - Hash format: `#p-<slug>:<requested>`.
   - Variants and unlisted suffixes show "Base series match — requested variant requires confirmation".
   - Codes inside Persian text are bidi-isolated (LRI…PDI).
4. **Mobile CTAs.**
   - The sheet footer has two rows at all widths: WhatsApp, then Call + Inquiry form.
   - Inquiry closes the sheet, scrolls to the form, focuses the first empty required field and prefills the full designation plus a match note.
5. **Workspace shell.**
   - Static shell: `role=region` on desktop; modal dialog on mobile with the rest of the page inert.
   - States: empty / loading / ready / err-data / err-mod. Close is available in every state.
   - Retry re-fetches the data, or re-imports `ws.js?retry=n` (failed imports are cached per spec).
   - Focus goes to the title once the sheet is visible, Tab is trapped in the sheet, and focus is restored to the invoker.
   - Escape: the mobile sheet always closes; the desktop panel closes when focus is inside it.
   - A breakpoint change swaps panel and sheet and keeps focus.
6. **Alt rule.**
   - ALT-INF (block): an informative image in a language release needs alt text in that language; otherwise Publish blocks the affected content. Three repairs: write alt / mark decorative (empty alt) / remove from that release.
   - ALT-UNUSED: information only, never blocks.
   - The rule appears identically in A1, A4 and A7.
7. **13 px labels.** Meaningful labels are 13 px (public readout, Admin headers, badges, helpers, chips). Workspace and mobile Exploded Assembly drawing labels are larger. No enlarged drawing view was needed.
8. **English header at 320 px.** It fits: below 380 px the gap is 12 px and the mark 28 px; no text was shrunk. Checked with `documentElement.clientWidth`.
9. **Admin scope.** A1–A8 are labelled "Desktop / tablet-first · 1024 px+"; A9 is the mobile workflow.
10. **Per-value provenance.**
    - Each value has `st` (ok/pending) and `src` (source key); each source has label, url and checked date.
    - 6206 demonstrates it: Source A (d/D/B) and Source B (min chamfer r1,2 = 1 mm, read from the cited page on 2026-10-04); ratings are pending.
11. **Migration plan.** See below.
12. **Re-verification.** See below.

## Data-model policy for designations (recommended, not built)
Option B: **Product + ProductVariant**, with **Spelling** as a separate table.
- **Product** = the base designation, carrying the shared values.
- **ProductVariant** = a child row: designation, suffix kind (seal/shield/clearance/cage…), status, optional variant-only values. SpecValue gets a nullable `variant_id`.
- **Spelling** = an equivalent writing of the same designation. Its normalised key is unique across products, variants and spellings; it is never a suffix.
- **Match precedence** (one normaliser shared by site, Publish Engine and Admin): exact (designation/spelling) › variant › series or related › none.
  - series: a listed designation plus an unlisted suffix (6205-2RSH → 6205); the listed one is the base series.
  - related (added by the T-1 fix): the plain designation of a listed suffixed one (6305 → 6305-2RS, 22220 → 22220 E). The listed one is a variant of the request, never its base. The prototype's suffix regex will be replaced by a per-family suffix list managed in the Admin.
- **Indexing:** only base URLs are indexed. A variant request canonicalises to the base until the variant has verified variant-specific data.
- **Inquiry record:** requested_raw, requested_norm, match_type, product_id, variant_id. Admin matching reads these fields and never re-guesses.
- **Rejected / deferred:**
  - A, one Product per designation: rejected (duplication and drift).
  - C, a full designation grammar: post-launch only.

## Migration plan (Phase 3; nothing migrated yet)
1. Audit the current source of truth: field origins and trust.
2. Build a repeatable, validated importer: dry run, then idempotent runs keyed on the normalised designation. It accepts CSV or the old system's export and applies the editor's validation (spec keys, units, D > d, unique keys, spellings ≠ variants).
3. Keep provenance only where it is citable. Verified information is not blanket-reset.
4. Safe defaults: unsourced values become pending; products become drafts; nothing is auto-verified for indexing.
5. A reconciliation report after every run (created, updated, skipped, conflicts, unsourced values, suffix decisions, duplicates). The Owner reviews it before publishing.

## Gate 2 decisions from Mosi (applied)
- **A. Latin islands: approved.** JetBrains Mono for technical and utility Latin on Persian pages, kept narrow. Longer English text on a Persian page uses `.en-prose` (system sans, no download). Documented at `system.html#bidi`.
- **B. Hero bearing: approved.**
- **C. Admin.** Persian-only Admin UI and Owner-only "Verified for indexing" are approved. Catalogue import/migration is approved in principle, under the rules above.
- **D. Inquiry notification email: TBD.** It is an Admin setting, never hardcoded; SMTP is to be confirmed by the host probe.
- **E. Remaining inputs stay open:** domains, host probe, logo SVG, exact hours, brands/sources, map coordinates.

## Verification (Chromium/Playwright)
- **Scripted checks:** `qa/scenarios.js`, 95/95 pass in fa and en. Covers:
  - identity and filters;
  - variants (8 inputs);
  - mobile CTAs at 320 and 390 px;
  - cached reopen ×5 on mobile;
  - failed JSON and failed module import, with Retry;
  - keyboard/focus/Escape;
  - breakpoint change;
  - layout at 320/390 px with the layout viewport equal to the requested width;
  - Admin alt-rule parity.
- **axe:** clean on every public state (including the mobile dialog, the error state and dark mode), the design system and the Admin wireframes. A clean wireframe scan is not a WCAG claim for the future Admin.
- **Performance:** median of 3 runs, before (V2 as delivered, JS minified the same way) → after.
  - fa: 67.7 → 72.6 KB; FCP = LCP 692 → 692 ms (Slow 4G), 856 → 844 ms (3G-class).
  - en: 64.9 → 69.5 KB; FCP = LCP 528 → 508 ms / 728 → 736 ms.
  - Requests are unchanged (7 / 8). CLS is 0.
  - First product open: 5.4 → 4.5 KB.
  - The LCP element is the hero H1, so FCP equals LCP.
  - Note, 2026-10-04 (takeover audit): this round's tables label FCP ≤ 1.8 s and LCP ≤ 2.5 s, while D5 said LCP ≤ 1.8 s; no owner decision changed it. Both are moot because owner decisions D14 and D17 superseded the hard budget and all numeric targets. The measurements remain valid as diagnostics.
- **Interaction proxy** (lab, not field INP): first open 40–48 ms; re-open 88–128 → 32–40 ms; worst key 32 → 40 ms.
- **Build:** `build.py` now minifies `src/js` with terser (workstation only; no Node in production) and renders public pages with StrictUndefined.
- **Not covered yet:** real Firefox/Safari, a screen-reader session, field INP.

## T-1 fix before Gate 2 (2026-10-04)

**Problem (found at the takeover audit).** When the plain designation was asked and only a suffixed one was listed, the prototype reversed the roles. For `6305` (only 6305-2RS listed) it said "“6305” is listed as base series 6305-2RS". The workspace note, WhatsApp text and inquiry context also called 6305-2RS the base series and 6305 a variant. `22220` → 22220 E did the same, and so did a half-typed `6305-2`. The requested code itself was always kept.

**Fix (smallest change; no redesign, no new data).**
- `src/js/nova.js`: a separate `related` match type for this direction. It counts only when the request is the whole base part of the listed designation, so a half-typed code matches nothing. Each match type picks its own wording in the explorer panel, workspace note, WhatsApp text and inquiry context.
- `src/strings.py` (fa and en):
  - New `related_*` strings: "Related match — requested designation requires confirmation"; "“6305” is not listed on its own. The catalogue lists 6305-2RS, a suffixed variant of 6305…".
  - New `variant_p` for a listed variant ("…is a listed variant of base series 6205…").
  - `series_p` corrected, so an unlisted suffix is no longer said to be listed ("…is not listed; its base series 6205 is").
- `admin.html`: the match-type list (A6) and the designation policy now include `related`.
- Unchanged: the requested designation (kept exact everywhere), exact matches (`6305-2RS` and `22220 E` stay exact), catalogue data, layout. The status badge in the workspace header still belongs to the listed designation, as for every non-exact match.

**Verification.** Only the designation/variant checks were re-run, as instructed: `ONLY=designation node scenarios.js` (Playwright 1.63, installed Chrome).
- Fixed build: **48/48 pass** in fa and en (`qa/scenarios-designation.json`).
- The new check `3t` compares the explorer panel, workspace note, WhatsApp text and inquiry context with the expected wording for each match type. It fails if a related match mentions "base series".
- New cases:
  - `6305-2RS` and `22220 E` (exact);
  - `3d`: the deep link `#p-6305-2rs:6305`;
  - `3e`: half-typed `6305-2` makes no claim.
- The same checks on the build before the fix: 18 of 48 fail, all on T-1 cases (`qa/scenarios-designation-before-t1.json`).
- The other checks in `scenarios.js` were not re-run. The full-suite record (`scenarios.json`, 95/95) predates T-1.
- The rebuild is reproducible: rebuilding the unchanged sources gave a byte-identical site. After the fix, only `fa.html`, `en.html` (their string tables) and `assets/nova.js` changed among the public files.
- Before/after: `site/shots-v2c/t1-before.webp`, `t1-after.webp`.

**Alignment with owner decisions D14/D17 (same build).** A7 no longer shows a page-budget check. The design-system ledger and the hub label the old budget as superseded. G2-12 no longer claims "all Gate 1 budgets met".

## Phase 3 carry-overs noted
- Content-hashed asset names.
- A CSP hash for the inline critical CSS (needed only if that split is kept; it is optional under D14).
- Housing/unit core-dimension definitions, needed before the verification validator and drawing templates.
- The per-family suffix list.
- Admin colour and semantic-control requirements from the wireframe scan.
- Q-DM1 (from the takeover audit): under option B, what a base Product row stands for when only a suffixed designation is supplied. The prototype keeps 6305-2RS and 22220 E as listed designations and treats a plain request as a related match. T-1 itself is fixed (above).
