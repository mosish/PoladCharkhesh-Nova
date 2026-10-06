[**PoladCharkhesh-Nova**Phase 2 · Gate 2 review](#top)

[Prototypes](#open)[Key screens](#screens)[Performance](#perf)[Gate 2](#gate)

P2Design system and visual prototypesGATE 2

# The Drawing Sheet system, built and measured

Working browser prototypes of the public site in Persian (RTL) and English (LTR), built with the production approach: one template, structured content, static output, hand-written CSS and two small scripts. Nothing here is the production application or database; product data is a 13-item sample for layout only.

Gate 1 approved with C1–C4Gate 2 awaiting your reviewChecked in Chromium · axe-core: 0 WCAG 2.1 AA violations · no horizontal scroll at 390 px

## Open the prototypes

[![Persian prototype, first screen](shots/fa-d-hero.webp)**نسخه فارسی (RTL)**Persian-first page as it would ship on poladcharkhesh.ir](fa.html) [![English prototype, product families](shots/en-d-fam.webp)**English version (LTR)**English-first page as it would ship on poladcharkhesh.com](en.html) [![Design system reference](shots/sys.webp)**Design system v0.2**Tokens, type, grid, drawing language, components, motion, RTL rules, performance ledger](system.html)

## Ten things to try

1. Type 6205 in the first search box and press Enter: the explorer opens the product directly.
2. On the Persian page, type ۶۲۰۵ with Persian digits: it still matches.
3. Search a code that isn't listed, e.g. 6310: the no-result panel carries it into WhatsApp.
4. Open UCP 208 or TC 25×42×7 to see the "technical data pending" treatment.
5. Switch the workspace tabs with the arrow keys; in Persian, Left moves forward.
6. Scroll slowly through "Inside a bearing": the bearing comes apart and the parts list follows.
7. Switch language from inside an open product: the other page opens on the same product.
8. Narrow the window below 900 px: action dock, menu, and the workspace as a bottom sheet.
9. Turn on reduced motion in your OS: the assembly shows exploded and static, nothing animates.
10. Switch your OS to dark mode: the Blueprint theme comes from the same tokens.

## Key screens

![Persian explorer with the 6205 workspace docked on the left](shots/fa-d-exp.webp)

Explorer + workspace, Persian desktop (workspace docks at the inline end)

![English explorer with the Data tab of 6205](shots/en-d-exp-data.webp)

Data tab: sourced dimensions, pending load ratings

![Exploded Assembly mid-scroll, Persian](shots/fa-d-ea.webp)

Exploded Assembly mid-scroll, with the parts list following

![Engineering desk with the code decoder](shots/en-d-eng.webp)

Engineering desk: code decoder (general rule) and Phase 5 tools

![Persian contact and inquiry sheet](shots/fa-d-con.webp)

Sheet 10: inquiry form, verified contact channels

![English explorer in the Blueprint dark theme](shots/en-d-dark-exp.webp)

Blueprint theme (device dark mode)

![Persian mobile first screen](shots/fa-m-hero.webp)

Mobile, Persian: first screen + action dock

![Persian mobile product sheet](shots/fa-m-sheet.webp)

Mobile: product as a bottom sheet

![English mobile explorer](shots/en-m-exp.webp)

Mobile, English: stacked result rows

![Persian mobile exploded assembly](shots/fa-m-ea.webp)

Mobile: Exploded Assembly

![Reduced-motion anatomy, static exploded](shots/fa-d-rm-ea.webp)

Reduced motion: static exploded drawing, no sticky track

![Fallback driver without scroll timelines](shots/fa-d-fallback-ea.webp)

Fallback path (no scroll timelines, e.g. Firefox): same result via a 1 KB driver

## Performance, measured against the Gate 1 budget

Chromium, 390 px phone viewport, cache disabled, 4× CPU slowdown, served with gzip like shared hosting. "Slow 4G" is 1.6 Mbps with 150 ms latency; "3G-class" is 400 kbps with 400 ms.

| Metric | Budget (Gate 1) | fa · Slow 4G | fa · 3G-class | en · Slow 4G | en · 3G-class |
| --- | --- | --- | --- | --- | --- |
| First-view transfer | ≤ 120 KB | 73.6 KB | 73.6 KB | 62.3 KB | 62.3 KB |
| Requests | ≤ 8 | 7 | 7 | 8 | 8 |
| HTML (incl. inline SVG) | ≤ 30 KB | 13.4 KB | 13.4 KB | 12.4 KB | 12.4 KB |
| CSS | ≤ 14 KB | 9.1 KB | 9.1 KB | 9.1 KB | 9.1 KB |
| JS, initial | ≤ 12 KB | 3.6 KB | 3.6 KB | 3.6 KB | 3.6 KB |
| Fonts | ≤ 60 KB | 47.5 KB | 47.5 KB | 37.2 KB | 37.2 KB |
| LCP | ≤ 1.8 s | 0.85 s | 1.78 s | 0.76 s | 1.67 s |
| CLS | ≤ 0.05 | 0.000 | 0.001 | 0.000 | 0.001 |
| Interaction latency (tap, type, tab) | INP ≤ 150 ms | 48 ms | 48 ms | 48 ms | 40 ms |
| Loaded on first product open | ≤ 15 KB | 6.3 KB | 6.3 KB | 6.3 KB | 6.3 KB |

Lab numbers from one browser engine; field numbers from real phones in Iran come after deployment. Hover-only events (pointerover) are excluded from interaction latency, as they are from INP. Per-decision costs are in the [performance ledger](system.html#perf).

## Gate 2 checklist

| # | Item | What's proposed | Where to see it |
| --- | --- | --- | --- |
| G2-1 | Visual language | Drawing Sheet: paper and navy ink, drafting conventions, ten numbered sheets, one blueprint band for Anatomy | [fa](fa.html) · [en](en.html) |
| G2-2 | Typography | Vazirmatn 400/700 + JetBrains Mono 400/600, subset; type scale; Latin digits for codes, Persian digits for prose and phones; Solar Hijri dates on fa | [system#type](system.html#type) |
| G2-3 | Colour and surfaces | Whiteprint default, Blueprint dark from the device setting, measured contrast | [system#color](system.html#color) |
| G2-4 | Spacing, grid, breakpoints | 4 px base; 12 columns, 1240 px; breakpoints 1100 / 900 / 700 / 600 | [system#space](system.html#space) |
| G2-5 | Technical drawing language | Line types, sections, dimensions, balloons, title blocks; 10 family glyphs; drawings generated from dimensions | [system#drawing](system.html#drawing) |
| G2-6 | Product Explorer | Static list + family / bore / verified filters, match highlighting, exact-match opens, no-result to WhatsApp | [en#explorer](en.html#explorer) |
| G2-7 | Product Workspace | Docked panel (desktop) / bottom sheet (mobile); Overview · Drawing · Data · Documents; sticky WhatsApp / Call / Inquiry | [fa#6205](fa.html#p-6205) |
| G2-8 | RTL / LTR rules | Mirror layout, never drawings or codes; logical properties; isolation; search normalisation | [system#bidi](system.html#bidi) |
| G2-9 | Drawing Set narrative | Sheet heads, scroll-driven draw-on, raceway on the process steps | Both pages |
| G2-10 | Exploded Assembly | One sticky stage (260 vh desktop, 200 vh mobile), 6-part bill of materials linked to families | [fa#anatomy](fa.html#anatomy) |
| G2-11 | Reduced motion | No animation, no sticky track, static exploded drawing | OS setting |
| G2-12 | Performance | All Gate 1 budgets met in lab measurements (table above) | [#perf](#perf) |

## Decisions that need your input

| # | Question | Recommendation |
| --- | --- | --- |
| Q1 | Ship the Blueprint dark theme in v1? | Yes, automatic from the device setting, no toggle. Cost ≈ 0.6 KB CSS plus one extra QA pass. |
| Q2 | Phone numbers on Persian pages in Persian or Latin digits? | Persian digits on screen, Latin digits inside `tel:` and WhatsApp links (as built). |
| Q3 | Logo | The prototype uses a placeholder concentric mark. Please supply the real logo as SVG; it replaces one `<symbol>`. |
| Q4 | Admin Control Center visuals | Not in your Phase 2 focus list, so not drawn here. Proposal: the admin reuses these tokens in a plain utilitarian layout; wireframes come at the start of Phase 4b. |
| Q5 | Copy | All prototype text is my draft for layout. Final Persian and English copy becomes CMS content; please mark anything you want changed. |

## Not done in Phase 2

No application, database, admin, Publish Engine or real inquiry handling; the form only validates. Engineering tools are previews (Phase 5). Product data is a 13-item sample whose boundary dimensions were checked against public distributor listings; load ratings are deliberately empty. Superdesign and frontend-design were not available in this session, so exploration was done directly in code; testing used Chromium only, with Firefox's fallback path simulated.

## Files

Prototype source (template, strings, sample catalogue, build script) and the built pages are saved in your project folder under design/phase2/. The CSS file is the starting point for the production stylesheet.