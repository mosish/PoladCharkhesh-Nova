# PoladCharkhesh-Nova — Phase 2: design system + visual prototypes (V1)

> **Superseded in part by V2 (2026-10-04): see claude/02-phase2-v2-visual-refinement.md.** Mosi answered Q1–Q5 (dark mode approved, automatic; phone numbers in LATIN digits in both languages; logo stays placeholder; admin wireframes delivered before Gate 2; copy stays draft) and asked for a visual refinement round. V2 changes the palette roles (navy fields, ice, steel, brass), hero, explorer (catalogue table), workspace (readout, 3 tabs), Exploded Assembly (six stages), digit rule for phones, and the performance approach. V1 files remain unchanged in `design/phase2/`. Gate 2 was approved on 2026-10-06, for V2 as corrected.

Delivered 2026-10-04. Review hub (artifact, now showing V2 with V1 before/after): https://claude.ai/artifact/HTMqnj38bZshFxzvHpvXi7
Local copy: C:\Users\Shahi\Desktop\PoladCharkhesh-Nova\design\phase2\ (site/, src/, qa/, README.md). Module scripts need a local server (`python3 -m http.server` in site/).

## What existed in V1
- fa.html (RTL) and en.html (LTR): full one-page prototypes rendered from ONE Jinja template + strings + sample catalogue (src/build.py mirrors the future Publish Engine).
- system.html: design system v0.2 (tokens parsed from nova.css, measured contrast, type, grid, drawing language, components, RTL rules, motion, performance ledger).
- assets/nova.css (tokens + components), nova.js (core, 3.6 KB gz), ws.js (workspace, loaded on first product open), subset fonts, data/p/*.json.
- Superdesign / frontend-design were NOT available in the session; exploration was done directly in code. Tested in Chromium only (Firefox fallback simulated).

## V1 design system decisions (proposed; see V2 for current state)
- Visual language: Drawing Sheet — paper + navy ink, ISO 128-style line types, sections with hatching, dimension lines, balloons, title blocks; ten numbered sheets (sheet heads); one blueprint "band" for Anatomy.
- Colour tokens: Whiteprint (light default), Blueprint (dark, from device setting), Band. Pending = amber dashed + hatched; verified = green solid. axe-core: 0 WCAG 2.1 AA violations (fa light, en light, fa dark).
- Type: Vazirmatn 400/700 subset per language, JetBrains Mono 400/600 subset without ligatures. font-display swap. Fluid scale ratio ≈1.25. fa line-height 1.85/1.45, en 1.6/1.15.
- Digits/dates (V1): codes, dimensions, units Latin + mono + <bdi dir=ltr>; Persian digits for phones on fa — CHANGED in V2 (phones Latin, per Q2). Solar Hijri dates on fa.
- Grid: 4 px base, 12 columns; breakpoints 1100 / 900 / 700 / 600. Radius 2 px; no shadows.
- RTL rules: mirror layout/navigation/directional icons; never mirror drawings, codes, units, phones, logo, decoder order; logical CSS properties only; search normalises Persian/Arabic digits, ي/ك, separators, ×.
- Explorer: product rows in static HTML (crawlable), JS only filters, exact match opens workspace, no-result → WhatsApp + inquiry with the typed code.
- Workspace: desktop sticky docked panel; mobile bottom sheet (inert background, Esc/scrim/drag/close, focus return).
- Motion: Drawing Set draw-on and Exploded Assembly via CSS scroll timelines, ~1 KB JS fallback; reduced motion = static.

## V1 measured (Chromium, 390 px, 4× CPU, cache off, gzip)
fa: 73.6 KB / 7 requests; LCP 0.85 s (Slow 4G) / 1.78 s (3G-class); CLS ≤0.001. en: 62.3 KB / 8 requests; LCP 0.76 / 1.67 s. First product open loads 6.3 KB.

## Sample data note
13 sample products; boundary dimensions checked against public distributor listings, load ratings deliberately empty; UCP 208 and TC 25×42×7 marked pending. Not CMS data.