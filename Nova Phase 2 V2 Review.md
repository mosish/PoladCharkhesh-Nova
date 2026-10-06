[**PoladCharkhesh-Nova**PHASE 2 V2 · GATE 2 REVIEW](#top)

[Prototypes](#open)[V1 → V2](#changes)[Deliverables](#deliverables)[Performance](#perf)[Gate 2](#gate)

P2 · V2 · VISUAL REFINEMENT · FOR GATE 2 REVIEW

# Drawing Sheet, refined: precision engineering on a deep-navy field

V2 keeps the engineering language from V1 (dimension lines, sections, drawing numbers, provenance) and adds what the previous site did well: deep navy, icy interaction, restrained metal, large Persian type and a strong two-column hero. It drops what it did badly. Everything here is a working browser prototype built the production way. It is not the application: there is no database, admin or Publish Engine yet.

Gate 1 approved (C1–C4)Gate 2: awaiting your review, not approvedPhase 3 not started

## Open the prototypes

[![Persian prototype, hero](shots-v2/d1-fa-hero.webp)**نسخه فارسی (RTL)**Persian page, as it would ship on the .ir domain](fa.html) [![English prototype, hero](shots-v2/d10-en-hero.webp)**English (LTR)**English page, as it would ship on the .com domain](en.html) [![Admin dashboard wireframe](shots-v2/d13-dashboard.webp)**Admin wireframes**Eight core Control Center screens plus mobile triage, with notes mapped to the data model](admin.html) [![Design system v0.3](shots-v2/sys.webp)**Design system v0.3**Tokens, palette roles, type and digit rules, components, motion stages and performance ledger](system.html)

## 15 · What changed from V1 to V2, and why

Each change answers your brief: absorb the previous site's strengths without copying it, avoid its weaknesses, and evolve the Drawing Sheet instead of replacing it.

| Area | V1 | V2 | Why |
| --- | --- | --- | --- |
| **Colour** | Paper and navy ink; one blueprint band | Navy fields for hero, Anatomy, contact and footer; icy blue for interaction; steel only on metal; one brass accent for "the part in focus" | Depth and a premium feel without glass or glow; every colour has one job |
| **Hero** | Concentric mark, search in a light sheet | Two columns: large Persian statement, dominant white search, example codes and a photo-by-WhatsApp link \| half metallic, half line-drawn bearing whose rolling elements turn slightly with scroll and lean toward the pointer | Search dominates; the signature image says "bearing" while staying vector (≈1.2 KB) |
| **Explorer** | Card-like result rows; family chips | A real catalogue table (glyph, designation, family, d / D / B, provenance); family rail with counts; bore segments; verified toggle | Reads like a technical catalogue, not an e-commerce grid; d/D/B visible at once |
| **Unknown code** | No-result panel | "Ask about this code" keeps the typed code and offers WhatsApp or the form, both for zero matches and when there is no exact match | A miss becomes a conversation, never a dead end |
| **Workspace** | Docked panel / bottom sheet with 4 tabs | Designation first, a three-cell d / D / B readout, then 3 tabs (Overview · Specifications · Documents); the drawing outranks imagery; a direct search hit lands with the whole panel and its actions in view; the mobile sheet sizes to its content | Values read in a second; no nested scrolling; actions always reachable |
| **Exploded Assembly** | Explode, light the BOM, reassemble | Six named stages: assembled (metal) → separated (hatched sections) → dimensioned (Ød, ØD, B) → explained (balloons + parts list) → related families → resolved. Confined to Anatomy; desktop 320 vh, mobile 190 vh with a numbers-only stage strip | The signature interaction tells the engineering story, not just a visual effect |
| **Typography** | Persian digits for phones | Large Vazirmatn display sizes; JetBrains Mono for codes, dimensions, units, drawing references *and phone numbers* (Latin in both languages, per Q2); 13 px minimum label | Consistency and legibility; no tiny labels |
| **Contact** | Light sheet | Navy field with a large WhatsApp button and the number in Latin digits, a channels list, and a small closing bearing | The conversion moment gets the same weight as the hero |
| **Performance** | One render-blocking CSS file; full Persian font subsets | Critical CSS inline (4.5 KB) with the rest deferred (the approved D5 plan); Persian subsets without Latin letters (Latin islands set in mono); three more lower sections render lazily | Earlier first paint on both pages and fewer bytes on the Persian page (table below) |

### Before / after

![V1: Persian hero, desktop](shots-v1/fa-d-hero.webp)

**V1**Persian hero, desktop

![V2: Persian hero, desktop](shots-v2/d1-fa-hero.webp)

**V2**Persian hero, desktop

![V1: Explorer with the workspace open](shots-v1/fa-d-exp.webp)

**V1**Explorer with the workspace open

![V2: Explorer with the workspace open](shots-v2/d3-fa-workspace.webp)

**V2**Explorer with the workspace open

![V1: Exploded Assembly, mid-sequence](shots-v1/fa-d-ea.webp)

**V1**Exploded Assembly, mid-sequence

![V2: Exploded Assembly, mid-sequence](shots-v2/d4-ea-4.webp)

**V2**Exploded Assembly, mid-sequence

![V1: Contact](shots-v1/fa-d-con.webp)

**V1**Contact

![V2: Contact](shots-v2/d5-fa-contact.webp)

**V2**Contact

![V1: Dark mode, workspace](shots-v1/en-d-dark-exp.webp)

**V1**Dark mode, workspace

![V2: Dark mode, workspace](shots-v2/d11-dark-workspace.webp)

**V2**Dark mode, workspace

![V1: Reduced motion, Anatomy](shots-v1/fa-d-rm-ea.webp)

**V1**Reduced motion, Anatomy

![V2: Reduced motion, Anatomy](shots-v2/d12-rm-ea.webp)

**V2**Reduced motion, Anatomy

![V1: Mobile hero](shots-v1/fa-m-hero.webp)

**V1**Mobile hero

![V2: Mobile hero](shots-v2/d6-m-hero.webp)

**V2**Mobile hero

![V1: Mobile bottom sheet](shots-v1/fa-m-sheet.webp)

**V1**Mobile bottom sheet

![V2: Mobile bottom sheet](shots-v2/d8-m-sheet.webp)

**V2**Mobile bottom sheet

### What V2 deliberately does not copy from the previous site

- **No glass, transparency or glow**: flat navy surfaces with borders; metal gradients only on the bearing itself.
- **No giant bearing behind unrelated sections**: the bearing appears in the hero, the Anatomy stage and as a small closing mark only.
- **No overloaded hero**: one search, four example codes, one secondary link.
- **No repeated cropped family photos**: drawn glyphs and generated section drawings instead.
- **No tiny labels or random mixed-language UI**: 13 px minimum; Latin appears only for codes, units, references and the other-language name.
- **No tall image-led mobile dialogs or double scrolling**: the sheet is content-sized with one scroll region, and controls never float over mobile content (the dock hides while the sheet is open).

## Deliverables 1–13

01

### Persian hero, desktop

Navy field, two columns. Statement, dominant search, four example codes and a photo-by-WhatsApp link on one side; a deep-groove ball bearing rendered half in restrained metal, half as a line drawing (section A–A, ØD and Ød) on the other. The rolling elements turn a few degrees with scroll and lean toward a fine pointer; the facts strip shows verified address, hours and numbers.

[![Persian hero at 1440 × 900](shots-v2/d1-fa-hero.webp)](shots-v2/d1-fa-hero.webp)

Persian hero at 1440 × 900

02

### Product Explorer, desktop

A technical catalogue rather than product cards: drawing glyph, designation, family, d / D / B (or the family's own symbols), provenance. The family rail shows counts (empty families stay visible but disabled), with bore segments and a verified-only toggle. An unknown code keeps the query and offers “Ask about this code”.

[![Catalogue, rail with counts, filters](shots-v2/d2-fa-explorer.webp)](shots-v2/d2-fa-explorer.webp)

Catalogue, rail with counts, filters

[![6208 is not listed: the query is kept and handed to a person](shots-v2/d2-fa-ask.webp)](shots-v2/d2-fa-ask.webp)

6208 is not listed: the query is kept and handed to a person

03

### Workspace open, desktop

A direct hit from the hero search lands on the result with the whole panel and its actions in view. Designation first, then a three-cell d / D / B readout, three tabs, and a drawing generated from the dimensions before any imagery. The footer always holds WhatsApp, Call and Inquiry.

[![Hero search “22220 E” → result + workspace](shots-v2/d3-fa-workspace.webp)](shots-v2/d3-fa-workspace.webp)

Hero search “22220 E” → result + workspace

[![Specifications: sourced values, pending ratings](shots-v2/d3-fa-workspace-specs.webp)](shots-v2/d3-fa-workspace-specs.webp)

Specifications: sourced values, pending ratings

04

### Exploded Assembly, desktop

Confined to Anatomy. One scroll-driven value moves through six stages; the stage strip, balloons and parts list stay in step. The last image is the JavaScript fallback for browsers without scroll timelines.

[![01 Assembled (metal)](shots-v2/d4-ea-1.webp)](shots-v2/d4-ea-1.webp)

01 Assembled (metal)

[![02 Separated (sections)](shots-v2/d4-ea-2.webp)](shots-v2/d4-ea-2.webp)

02 Separated (sections)

[![03 Dimensioned (Ød, ØD, B)](shots-v2/d4-ea-3.webp)](shots-v2/d4-ea-3.webp)

03 Dimensioned (Ød, ØD, B)

[![04 Explained (balloon + row)](shots-v2/d4-ea-4.webp)](shots-v2/d4-ea-4.webp)

04 Explained (balloon + row)

[![05 Related families](shots-v2/d4-ea-5.webp)](shots-v2/d4-ea-5.webp)

05 Related families

[![06 Resolved](shots-v2/d4-ea-6.webp)](shots-v2/d4-ea-6.webp)

06 Resolved

[![Fallback driver (no scroll timelines)](shots-v2/d4-ea-fallback.webp)](shots-v2/d4-ea-fallback.webp)

Fallback driver (no scroll timelines)

05

### Contact, desktop

A navy field with a large WhatsApp button (number in Latin digits), the verified channels list and a small closing bearing, next to the inquiry form.

[![Contact sheet](shots-v2/d5-fa-contact.webp)](shots-v2/d5-fa-contact.webp)

Contact sheet

06

### Persian hero and search, mobile

The search sits inside the first viewport, above the bearing; the dock holds Call, WhatsApp and Search.

[![390 × 844](shots-v2/d6-m-hero.webp)](shots-v2/d6-m-hero.webp)

390 × 844

07

### Explorer, mobile

Rows become two-line records; the rail scrolls sideways; the bore segments fit the width; the ask panel stacks.

[![Catalogue](shots-v2/d7-m-explorer.webp)](shots-v2/d7-m-explorer.webp)

Catalogue

[![Ask about this code](shots-v2/d7-m-ask.webp)](shots-v2/d7-m-ask.webp)

Ask about this code

08

### Bottom sheet, mobile

Sized to its content (max 88% height), one scroll region, readable values first, actions in reach; Esc, scrim, drag-down or close dismiss it, and the dock hides meanwhile.

[![22220 E](shots-v2/d8-m-sheet.webp)](shots-v2/d8-m-sheet.webp)

22220 E

09

### Exploded Assembly, mobile

A shorter track (190 vh) with a numbers-only stage strip and the active stage name under it; larger dimension labels.

[![Separated](shots-v2/d9-m-ea-a.webp)](shots-v2/d9-m-ea-a.webp)

Separated

[![Explained](shots-v2/d9-m-ea-b.webp)](shots-v2/d9-m-ea-b.webp)

Explained

10

### English LTR validation

Same template and tokens, mirrored layout, drawings and codes never mirrored. Workspace shows a product with technical data pending.

[![Hero](shots-v2/d10-en-hero.webp)](shots-v2/d10-en-hero.webp)

Hero

[![UCP 208, data pending](shots-v2/d10-en-workspace.webp)](shots-v2/d10-en-workspace.webp)

UCP 208, data pending

[![Anatomy](shots-v2/d10-en-ea.webp)](shots-v2/d10-en-ea.webp)

Anatomy

[![Mobile](shots-v2/d10-en-m-hero.webp)](shots-v2/d10-en-m-hero.webp)

Mobile

11

### Dark mode

Automatic from the device setting, no toggle: sheets turn deep blue, navy fields one step deeper, no glows.

[![Hero](shots-v2/d11-dark-hero.webp)](shots-v2/d11-dark-hero.webp)

Hero

[![Workspace](shots-v2/d11-dark-workspace.webp)](shots-v2/d11-dark-workspace.webp)

Workspace

[![Anatomy](shots-v2/d11-dark-ea.webp)](shots-v2/d11-dark-ea.webp)

Anatomy

[![Mobile, English](shots-v2/d11-dark-m-explorer.webp)](shots-v2/d11-dark-m-explorer.webp)

Mobile, English

12

### Reduced motion

No sticky track and no animation: Anatomy shows the complete dimensioned technical state with every balloon and the parts list.

[![Desktop](shots-v2/d12-rm-ea.webp)](shots-v2/d12-rm-ea.webp)

Desktop

[![Mobile](shots-v2/d12-rm-m-ea.webp)](shots-v2/d12-rm-m-ea.webp)

Mobile

13

### Admin Control Center wireframes

Low/medium fidelity, Persian RTL, Nova tokens, dense and utilitarian; English notes map each element to the data model. Not implemented.

[![A1 Dashboard](shots-v2/d13-dashboard.webp)](shots-v2/d13-dashboard.webp)

A1 Dashboard

[![A2 Products](shots-v2/d13-products.webp)](shots-v2/d13-products.webp)

A2 Products

[![A3 Product editor](shots-v2/d13-editor.webp)](shots-v2/d13-editor.webp)

A3 Product editor

[![A4 Media library](shots-v2/d13-media.webp)](shots-v2/d13-media.webp)

A4 Media library

[![A5 Sections / content](shots-v2/d13-sections.webp)](shots-v2/d13-sections.webp)

A5 Sections / content

[![A6 Inquiries](shots-v2/d13-inquiries.webp)](shots-v2/d13-inquiries.webp)

A6 Inquiries

[![A7 Publish](shots-v2/d13-publish.webp)](shots-v2/d13-publish.webp)

A7 Publish

[![A8 Company / settings](shots-v2/d13-company.webp)](shots-v2/d13-company.webp)

A8 Company / settings

[![A9 Mobile triage](shots-v2/d13-mobile.webp)](shots-v2/d13-mobile.webp)

A9 Mobile triage

## 14 · Performance, V1 → V2

Same harness for both versions, measured in the same session: Chromium, 390 px phone, cache disabled, 4× CPU slowdown, gzip like shared hosting. "Slow 4G" is 1.6 Mbps at 150 ms; "3G-class" is 400 kbps at 400 ms.

| Metric | Budget | fa · Slow 4G V1 → V2 | fa · 3G-class V1 → V2 | en · Slow 4G V1 → V2 | en · 3G-class V1 → V2 |
| --- | --- | --- | --- | --- | --- |
| First-view transfer | ≤ 120 KB | 73.6 KB → 68.8 KB | 73.6 KB → 68.8 KB | 62.3 KB → 66.0 KB | 62.3 KB → 66.0 KB |
| Requests | ≤ 8 | 7 → 7 | 7 → 7 | 8 → 8 | 8 → 8 |
| HTML (incl. inline SVG, critical CSS in V2) | ≤ 30 KB | 13.4 KB → 19.7 KB | 13.4 KB → 19.7 KB | 12.4 KB → 18.5 KB | 12.4 KB → 18.5 KB |
| CSS file (V2: deferred, not render-blocking) | ≤ 14 KB | 9.1 KB → 6.3 KB | 9.1 KB → 6.3 KB | 9.1 KB → 6.3 KB | 9.1 KB → 6.3 KB |
| JS, initial | ≤ 12 KB | 3.6 KB → 4.0 KB | 3.6 KB → 4.0 KB | 3.6 KB → 4.0 KB | 3.6 KB → 4.0 KB |
| Fonts | ≤ 60 KB | 47.5 KB → 38.9 KB | 47.5 KB → 38.9 KB | 37.2 KB → 37.2 KB | 37.2 KB → 37.2 KB |
| First paint = LCP (hero headline) | ≤ 1.8 s | 0.87 s → 0.66 s | 1.75 s → 0.83 s | 0.74 s → 0.48 s | 1.63 s → 0.74 s |
| CLS | ≤ 0.05 | 0.000 → 0.000 | 0.001 → 0.000 | 0.000 → 0.000 | 0.001 → 0.000 |
| Loaded on first product open | ≤ 15 KB | 6.3 KB → 6.5 KB | 6.3 KB → 6.5 KB | 6.3 KB → 6.5 KB | 6.3 KB → 6.5 KB |

### Interaction latency (tap a product, type a code)

| Interaction, 4× CPU slowdown, phone | V1 | V2 | INP target |
| --- | --- | --- | --- |
| First product open (module loads) | 40–56 ms | 40–64 ms | ≤ 150 ms |
| Re-opening a product (sheet + render) | 96–136 ms | 104–128 ms | ≤ 150 ms |
| Typing in search (worst key) | 32 ms | 32 ms | ≤ 150 ms |

- **Persian page: fewer bytes although the page is richer** (−4.8 KB). The Persian font subsets no longer carry Latin letters (−8.6 KB); Latin islands are set in JetBrains Mono, which is already loaded.
- **English page: 3.7 KB heavier**, from the richer hero drawing and new components in the HTML, with the same request count. It still paints about a third sooner, and it uses 55% of the 120 KB budget.
- **First paint moved earlier** because the render-blocking stylesheet was replaced by 4.5 KB of inline critical CSS, with the remaining 6.3 KB deferred. This is the split planned in D5, applied early, and as part of it CSS comments are stripped as the Publish Engine will do; the V1 CSS was measured with comments. The headline paints first in the system font and swaps to Vazirmatn when it arrives; layout shift stays at 0.
- **Where V2 is slightly slower:** re-opening a product is in the same band as V1, about 15 ms slower at the median under 4× CPU slowdown, because the page has more DOM (catalogue table, metal drawing). It stays well inside the 150 ms target. Phase 3 can pre-render the panel at publish time.
- **One methodology note:** V1's "≤ 48 ms" figure covered only the first product open. V2 reports first open and re-open separately, so the comparison above is like-for-like.
- **For production (Phase 3):** the inline critical CSS needs a CSP hash, which the Publish Engine computes. Brotli and minification will reduce bytes further.

## Ten things to try

1. In the hero, type 22220 E and press Enter: the page lands on the result with the whole workspace and its actions in view.
2. Type 6208 in the explorer: "Ask about this code" keeps it and offers WhatsApp or the form.
3. Type ۶۲۰۵ with Persian digits on the Persian page: it still matches 6205.
4. Click a family in the rail: the counts tell you how much is behind each family.
5. Open UCP 208 or TC 25×42×7 to see "technical data pending" in the readout and tabs.
6. On desktop, move the pointer across the hero: the bearing's rolling elements lean slightly; scrolling turns them.
7. Scroll slowly through "Inside a bearing": six stages, with the stage name and parts list in step.
8. Narrow the window below 900 px: the search stays inside the first screen, the dock appears, and products open as a bottom sheet.
9. Switch your OS to dark mode: sheets turn deep blue and navy fields go one step deeper.
10. Turn on reduced motion: Anatomy shows the complete dimensioned drawing, statically.

## Gate 2 checklist (for your review; nothing here is approved yet)

| # | Item | V2 proposal | Where |
| --- | --- | --- | --- |
| G2-1 | **Visual direction V2** | Drawing Sheet language on paper sheets × deep-navy presentation fields × restrained metal; colour roles as listed | [fa](fa.html) · [en](en.html) |
| G2-2 | **Palette and tokens** | Brand #2F338A, ice #2196F3 / #90CAF9, navy 950/900/800, steel for metal only, one brass accent; light, dark (automatic) and navy-field contexts; measured contrast | [system#color](system.html#color) |
| G2-3 | **Typography and digits** | Vazirmatn 400/700 with display sizes up to 68 px; JetBrains Mono for codes, dimensions, units, drawing references and phones (Latin digits in both languages); Persian digits in prose and counts; 13 px minimum | [system#type](system.html#type) |
| G2-4 | **Hero** | Two columns; search dominates; split metal / line bearing with a subtle scroll and pointer response; mobile search in the first viewport | [fa](fa.html) |
| G2-5 | **Product Explorer** | Catalogue table, family rail with counts, bore segments, verified toggle, “Ask about this code” | [fa#explorer](fa.html#explorer) |
| G2-6 | **Product Workspace** | Docked panel (desktop) / content-sized bottom sheet (mobile); readout; three tabs; drawing before imagery; direct-hit landing | [fa#22220 E](fa.html#p-22220-e) |
| G2-7 | **Exploded Assembly** | Six stages in Anatomy only; 320 vh desktop / 190 vh mobile; JS fallback; static state under reduced motion | [fa#anatomy](fa.html#anatomy) |
| G2-8 | **Dark mode** | Automatic, no toggle, no glow | OS setting |
| G2-9 | **Reduced motion** | Complete static technical state; no sticky track | OS setting |
| G2-10 | **RTL / LTR rules** | Mirror layout, never drawings or codes; Latin islands on Persian pages set in mono | [system#bidi](system.html#bidi) |
| G2-11 | **Admin Control Center IA** | Eight core screens + mobile triage; Draft → Publish; two roles; entity map | [admin.html](admin.html) |
| G2-12 | **Performance approach** | Inline critical CSS + deferred rest; Persian subsets without Latin letters; lazy lower sections; all Gate 1 budgets met | [#perf](#perf) |

### Your Phase 2 decisions, as applied

| # | Decision | Applied in V2 |
| --- | --- | --- |
| Q1 | Dark mode approved: automatic, no toggle, readable, not glow-heavy | Follows the device setting; navy fields go one step deeper; no glows (deliverable 11) |
| Q2 | Phone numbers in Latin digits in both languages | Header, hero facts, contact, footer and the admin; `tel:` and wa.me values in Latin |
| Q3 | The concentric mark stays a placeholder | Unchanged; the admin has a logo slot for the real SVG |
| Q4 | Admin wireframes before Gate 2, no implementation | A1–A9 at [admin.html](admin.html) (deliverable 13) |
| Q5 | Copy remains draft | Not polished; final copy becomes CMS content |

### Open questions for you

- **Latin islands in mono on Persian pages.** English family names, the EN switch and source names are set in JetBrains Mono on the Persian page. This is what lets the Persian font drop its Latin letters (−8.6 KB). Approve, or keep Vazirmatn Latin at that cost?
- **Hero bearing.** A generic deep-groove ball bearing drawn from scratch: no photo, no brand. Is this acceptable as the signature image until real product photography exists?
- **Admin language and roles.** Persian-only admin UI for v1; only the Owner can set “Verified for indexing”; CSV import of the old catalogue (needs an export). Please confirm all three.
- **Inquiry notifications:** which email address should receive them? SMTP availability comes from the host probe.
- **Still open from Gate 1:** exact domain names, host probe results, logo SVG, Thursday/Friday hours (and whether Saturday–Wednesday is correct for 08:00–16:00), verified brands and sources, map coordinates.

## Not done (by design)

No production application, MariaDB schema, Admin implementation or Publish Engine; the form only validates. Engineering tools are previews (Phase 5). Product data is the 13-item sample with sourced boundary dimensions; load ratings stay empty. Chromium was the test engine; the Firefox path (no scroll timelines) was simulated and screenshotted (deliverable 4, last image).

## Files

The V2 source (templates, strings, catalogue, build script, QA scripts) and built pages are saved in your project folder under design/phase2-v2/. V1 stays untouched under design/phase2/.