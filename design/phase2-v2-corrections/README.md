# PoladCharkhesh-Nova: Phase 2 V2, Gate 2 correction round

Status: targeted corrections 1–12 are done and re-verified, and T-1 (found at the takeover audit) is fixed.
**Gate 2 was approved on 6 Oct 2026. This folder is frozen as the production baseline**: it is the reference
for behaviour and appearance, not production code, and it is not edited further. The review hub's status
badges show the state at review time. This is a prototype, not the production application. No PHP application,
MariaDB schema, Admin implementation or Publish Engine exists yet. No catalogue data was migrated.

The other versions are kept unchanged:

- `design/phase2/`: V1.
- `design/phase2-v2/`: V2 as first delivered, the "before" state of this round.
- `design/phase2-v2-corrections.zip`: this folder as the correction round delivered it, before the T-1 fix.

The visual direction, hero, explorer, workspace, Exploded Assembly concept and Admin IA are unchanged.

## What changed in this round

| # | Item | Where to look |
| --- | --- | --- |
| 1 | One active selection (product, requested designation, match type, token) drives the row, workspace, URL, copy/link, WhatsApp and inquiry. A hidden product closes the workspace. Stale responses are aborted or dropped. | `src/js/nova.js` §4–5 |
| 2 | Hero exact search is global and clears conflicting filters. The explorer separates "not in catalogue" from "hidden by filters" and offers a reveal or reset. | `nova.js` `explain()` / `submit()` |
| 3 | Spellings and variants are separate (`spellings` / `variants` in `catalogue.json`). The requested designation is kept verbatim end to end. "Base series match — requested variant requires confirmation". | `admin.html#variants` (data-model policy: option B) |
| 4 | Mobile sheet footer: WhatsApp, Call and Inquiry form at 320 and 390 px. Inquiry closes the sheet and prefills the full designation. | `nova.css` `.ws__foot`, `nova.js` `toInquiry()` |
| 5 | Stable workspace shell (region / modal dialog) with loading, ready, data-error and module-error states. Close in every state; real Retry; focus in, contained and restored; Escape contract; correct panel/sheet swap across the breakpoint. | `page.html.j2` `#ws`, `nova.js` §5 |
| 6 | One alt-text rule (`ALT-INF`, blocks the affected content) in A1, A4 and A7. Decorative images get empty alt. Unused media never blocks. | `admin.html#media`, `#publish` |
| 7 | 13 px for meaningful labels (public readout, Admin). Larger drawing and mobile Exploded Assembly labels. | `nova.css`, `admin-wf.css` |
| 8 | English header fits 320 px, measured with `documentElement.clientWidth`. | `nova.css` `@media (max-width:379px)` |
| 9 | A1–A8 labelled desktop / tablet-first; A9 is the mobile workflow. | `admin.html` |
| 10 | Per-value provenance: source key, status and checked date per value. 6206 shows Source A, Source B and pending values. | `fa.html#p-6206`, `ws.js` |
| 11 | Phase 3 migration plan: audit, repeatable validated importer, trustworthy provenance kept, safe pending defaults, reconciliation report. | `admin.html#migration` |
| 12 | Re-verified in fa and en (below). | `qa/` |
| T-1 | Plain designation asked, only a suffixed one listed (6305 → 6305-2RS, 22220 → 22220 E): now a `related` match. The listed one is named as a suffixed variant, never the base series, in the explorer, workspace, WhatsApp text and inquiry. A half-typed code matches nothing. Listed variants and unlisted suffixes get their own explanation lines. | `nova.js` `classify()`, `strings.py` (T-1 block), `admin.html#variants` |

Gate 2 decisions A (Latin islands, narrow rule, `.en-prose` escape), B (hero bearing) and C (Persian-only
Admin, Owner-only indexing, import in principle) are applied. D (notification email) is an Admin setting,
still TBD. E (remaining inputs) stays open.

## Files

| Path | What it is |
| --- | --- |
| `site/fa.html`, `site/en.html` | One-page prototypes, Persian (RTL) and English (LTR) |
| `site/admin.html` | Admin Control Center wireframes A1–A9, plus the variant policy and migration plan |
| `site/system.html` | Design system v0.3 |
| `site/index.html` | Gate 2 review hub: correction report, before/after, QA, accessibility, performance delta, checklist. It is an HTML fragment; the artifact viewer adds the page skeleton. |
| `site/assets/nova.css` | Tokens + components (source of truth). The build splits it into inline critical CSS + `nova-rest.css` |
| `site/assets/nova.js`, `site/assets/ws.js` | Minified build output of `src/js/*.js` (the core script and the workspace module, loaded on first open) |
| `site/data/p/*.json` | Per-product JSON with per-value `st` (status) and `src` (source key), as the Publish Engine will emit |
| `site/shots-v2c/` | Before/after and new-state screenshots for this round |
| `src/` | Templates, strings, catalogue, `js/` sources and `build.py` |
| `qa/` | Playwright scripts and results (see below) |

## Viewing and rebuilding

```
cd site && python3 -m http.server 8090      # module scripts do not run from file://
pip install jinja2 markupsafe
cd src && python3 build.py                  # minifies src/js with qa/node_modules/.bin/terser when present, else copies
```

Minification is a workstation build step only; production runs no Node.

The T-1 fix was rebuilt on Windows with Python 3.14 in UTF-8 mode (`-X utf8`), LF line endings and terser 5.51
run through `node` (the extensionless `.bin/terser` shim cannot start from Windows Python, and `build.py` then
silently copies unminified JS). Rebuilding the unchanged sources this way reproduced the delivered site byte for byte.

## Verification (Chromium via Playwright)

- **T-1 focused re-run (fixed build):** `ONLY=designation node scenarios.js` → `scenarios-designation.json`,
  48/48 pass in Persian and English. Only the designation and variant checks were re-run. The same checks fail
  18/48 on the build before the fix (`scenarios-designation-before-t1.json`). `PW_CHANNEL=chrome` drives an
  installed Chrome instead of Playwright's bundled Chromium.
- **Scripted checks (correction round, before T-1):** `qa/scenarios.js` → `scenarios.json`. 95/95 passed in Persian and English.
  - Coverage: identity, filters, variants, mobile CTAs, cached reopen ×5, failed data and failed module with Retry, keyboard and focus, breakpoint change.
  - Layout at 320 and 390 px.
  - Admin alt-rule parity.
- **Accessibility:** `qa/axe2.js` → `axe2.json`, against V2 as delivered in `axe2-v2a.json`. Every public state is clean, as are the design system and the Admin wireframes. The hub keeps `html-has-lang` because it is a local fragment.
  - A clean scan of static wireframes is not a WCAG claim for the future Admin.
- **Performance:** `qa/perf.js`, median of 3 runs. Results in `perf-v2a-min.json` (before) and `perf-v2c.json` (after).

| 390 px phone, 4× CPU, gzip | fa · Slow 4G | fa · 3G-class | en · Slow 4G | en · 3G-class | Gate 1 budget (superseded: D14/D17) |
| --- | --- | --- | --- | --- | --- |
| First-view transfer | 67.7 → 72.6 KB | 67.7 → 72.6 KB | 64.9 → 69.5 KB | 64.9 → 69.5 KB | ≤ 120 KB |
| Requests | 7 → 7 | 7 → 7 | 8 → 8 | 8 → 8 | ≤ 8 |
| FCP | 692 → 692 ms | 856 → 844 ms | 528 → 508 ms | 728 → 736 ms | ≤ 1.8 s |
| LCP (hero H1) | 692 → 692 ms | 856 → 844 ms | 528 → 508 ms | 728 → 736 ms | ≤ 2.5 s |
| CLS | 0 → 0 | 0 → 0 | 0 → 0 | 0 → 0 | ≤ 0.05 |
| On first product open | 5.4 → 4.5 KB | | | | ≤ 15 KB |

Interaction proxy (`qa/inp.js`, lab event duration, not field INP):

- First open: 40–48 ms, both before and after.
- Re-open: 88–128 ms before, 32–40 ms after.
- Worst keystroke: 32 ms before, 40 ms after.

Not covered: real Firefox/Safari runs, a screen-reader session, field INP.

## Sample data

13 sample products. Boundary dimensions were checked against the public listings cited in `src/catalogue.json`.
6206 has one second-source value (minimum chamfer r1,2 = 1 mm), read from the cited page, to demonstrate
per-value provenance. Load ratings stay pending. UCP 208 and TC 25×42×7 are "Technical data pending".
Admin wireframe content (inquiries, people, counts, file names, release IDs) is labelled placeholder data.
