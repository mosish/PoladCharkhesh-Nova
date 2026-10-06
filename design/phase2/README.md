# PoladCharkhesh-Nova — Phase 2: design system and visual prototypes

Status: ready for Gate 2 review (4 Oct 2026). This is a prototype, not the production application:
no PHP application, database, admin or Publish Engine exists yet (Phase 3+ after Gate 2).

## What is here

| Path | What it is |
| --- | --- |
| `site/fa.html`, `site/en.html` | Full one-page prototypes, Persian (RTL) and English (LTR) |
| `site/system.html` | Design system v0.2 reference (tokens, type, grid, drawing language, components, motion, RTL rules, performance ledger) |
| `site/index.html` | Gate 2 review hub (an HTML fragment: the artifact viewer adds the page skeleton) |
| `site/assets/nova.css` | Design tokens + components. Seed for the production stylesheet |
| `site/assets/nova.js` | Core interactions (explorer filtering, sheet indicator, scroll-narrative fallback, form validation) |
| `site/assets/ws.js` | Product workspace, loaded only when a product is opened |
| `site/assets/fonts/` | Subset Vazirmatn and JetBrains Mono (both SIL OFL) |
| `site/data/p/*.json` | Per-product JSON, as the Publish Engine will emit |
| `src/` | Template (`page.html.j2`), strings (`strings.py`), sample catalogue (`catalogue.json`), build script (`build.py`) |
| `qa/` | Playwright scripts: screenshots, overflow check, axe-core accessibility run, throttled performance run, results (`perf-final.json`) |

## Viewing locally

Module scripts do not run from `file://`, so serve the folder:

```
cd site
python3 -m http.server 8090
# open http://localhost:8090/fa.html
```

## Rebuilding

```
pip install jinja2 markupsafe
cd src && python3 build.py
```

## Sample data

13 sample products. Boundary dimensions were checked against public distributor listings (sources in
`src/catalogue.json`) for layout purposes only; load ratings and speeds are deliberately empty. Two items
(UCP 208, TC 25×42×7) are marked "Technical data pending / Unverified" to show that state. None of this is
CMS data; the audited 68-product catalogue is migrated in Phase 3.

## Measured (Chromium, 390 px, 4× CPU slowdown, cache disabled, gzip)

| | fa · Slow 4G | fa · 3G-class | en · Slow 4G | en · 3G-class | Budget |
| --- | --- | --- | --- | --- | --- |
| First-view transfer | 73.6 KB | 73.6 KB | 62.3 KB | 62.3 KB | ≤ 120 KB |
| Requests | 7 | 7 | 8 | 8 | ≤ 8 |
| LCP | 0.85 s | 1.78 s | 0.76 s | 1.67 s | ≤ 1.8 s |
| CLS | 0.000 | 0.001 | 0.000 | 0.001 | ≤ 0.05 |
| Interaction latency | 48 ms | 48 ms | 48 ms | 40 ms | ≤ 150 ms |

axe-core (WCAG 2.1 AA + best practices): 0 violations on fa light, en light, fa dark, with a product open.
