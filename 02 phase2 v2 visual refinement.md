# PoladCharkhesh-Nova — Phase 2 V2: visual refinement (for Gate 2)

Date: 2026-10-04. Status: delivered for Gate 2 review, then corrected in a targeted round (items 1–12, see claude/03-phase2-v2-corrections.md). **Gate 2 was approved on 2026-10-06 (V2 as corrected, plus T-1). Phase 3 is in planning; nothing is implemented.** No production app, MariaDB schema, Admin implementation or Publish Engine exists.

Review hub (same URL as V1, now V2 corrected): https://claude.ai/artifact/HTMqnj38bZshFxzvHpvXi7 — contains fa/en prototypes, admin.html (wireframes A1–A9 + variant policy + migration plan), system.html (design system v0.3), V1→V2 before/after, correction report, performance.
Files: user folder `design/phase2-v2/` (V2 as first delivered, + `phase2-v2.zip`); corrected V2 in `design/phase2-v2-corrections/`; V1 untouched in `design/phase2/`.

## Decisions applied from Mosi (Phase 2 Q1–Q5)
- Q1 Dark mode approved: automatic via prefers-color-scheme, no toggle, no glow.
- Q2 Phone numbers in Latin digits in both languages; tel:/wa.me Latin.
- Q3 Concentric mark stays a placeholder until the real logo SVG.
- Q4 Admin wireframes before Gate 2 (done, not implemented).
- Q5 Copy remains draft.

## V2 design direction
- Drawing Sheet language kept (dimension lines, sections, drawing numbers, provenance) on paper sheets; deep-navy presentation fields for hero, Anatomy, contact, footer.
- Colour roles: brand #2F338A = ink/action on paper; ice #2196F3 = interaction/focus; #90CAF9 = action on navy; navy 950/900/800 fields; steel only for metal rendering; brass #A87C32/#D6B06A = single warm accent ("part in focus"); amber = pending data only. No glass/glow/shadows.
- Type: Vazirmatn 400/700 (display up to ~68 px); JetBrains Mono for codes, dimensions, units, drawing refs, phones (Latin digits both languages); Persian digits in prose/counts; 13 px minimum label (enforced in the correction round).
- Hero: two columns; dominant white search + 4 example codes + photo-by-WhatsApp link | split metal/line-drawn deep-groove bearing (rolling elements turn slightly with scroll, lean to fine pointer). Mobile: search in first viewport.
- Explorer: catalogue table (glyph, designation, family, d/D/B or family symbols, provenance); family rail with counts (6-column grid on desktop, sideways scroll on mobile); bore segments; verified toggle; "Ask about this code" keeps the query (WhatsApp/form) for zero and non-exact matches; "hidden by filters" is distinguished from "not in catalogue" (correction round).
- Workspace: designation + d/D/B readout + 3 tabs (Overview · Specifications · Documents); drawing before imagery; direct hit from hero search lands on results + whole panel; mobile sheet content-sized (max 88dvh), single scroll region. Correction round: stable shell with loading/error/Retry states, requested-designation identity, per-value provenance, three CTAs on mobile.
- Exploded Assembly (Anatomy only): six stages from one progress value --p: assembled → separated → dimensioned → explained (balloons + BOM) → related families → resolved. 320vh desktop / 190vh mobile (numbers-only stage strip + active name). Reduced motion: complete static dimensioned state. JS fallback when no scroll timelines.

## Performance (same harness, V1 → V2 first delivery; 390 px, 4× CPU, gzip)
> Note, 2026-10-04: these are lab measurements against the Gate 1 D5 budget, which owner decisions D14 and D17 have since superseded. The numbers remain valid as diagnostic measurements, not targets. Techniques adopted mainly to save bytes (the inline-critical-CSS split and its CSP hash, Persian subsets without Latin letters) are now optional and will be judged on merit in Phase 3. See `04 takeover audit and performance policy.md`.
- fa: 73.6 → 68.8 KB, 7 → 7 requests, LCP 0.87 → 0.66 s (Slow 4G), 1.75 → 0.83 s (3G-class), CLS 0.
- en: 62.3 → 66.0 KB (+3.7 KB: richer HTML), 8 → 8 requests, LCP 0.74 → 0.48 s / 1.63 → 0.74 s, CLS 0.
- Interactions at 4× CPU: first open 40–64 ms (V1 40–56); re-open 104–128 ms (V1 96–136); typing ≤ 32 ms.
- How: inline critical CSS (4.5 KB gz) + deferred `nova-rest.css` (D5 plan applied early; comments stripped); Persian Vazirmatn subsets without Latin letters (−8.6 KB) with Latin letters on Persian pages mapped to JetBrains Mono via unicode-range; content-visibility on lower sections.
- Correction-round delta (minified JS both sides, median of 3): fa 67.7 → 72.6 KB, en 64.9 → 69.5 KB, same requests, FCP/LCP within noise, re-open 88–128 → 32–40 ms. Details in 03.
- Phase 3 notes: inline critical CSS needs a CSP hash (Publish Engine computes it); @font-face faces in one family must share exact weight descriptors or unicode-range composites break (found and fixed in V2).

## Admin Control Center wireframes (A1–A9, Persian RTL, English notes)
Dashboard · Products · Product editor (per-value SpecValue + Source, spellings + ProductVariant rows, Owner-only "Verified for indexing" gated on sourced core dims) · Media library (reference counting, informative/decorative role, ALT-INF rule, 30-day trash) · Sections/content (fixed sections, fa/en side by side, no page builder) · Inquiries (stored first, notified second; status workflow; requested designation + match type; demand list per C4) · Publish (diff, validation gate incl. ALT-INF blocker, engine steps, 5 releases, rollback) · Company/settings (E.164 phones shown Latin, tri-state hours, empty never renders, roles, configurable notification email, backups) · Mobile triage. A1–A8 desktop/tablet-first; A9 is the mobile workflow. Entity→screen map included.

## Gate 2 questions — answered
The five open questions from the first V2 delivery were answered by Mosi as Gate 2 decisions A–E (A Latin islands approved, B hero bearing approved, C Persian-only Admin + Owner-only verification approved and import approved in principle, D notification email TBD and configurable, E remaining inputs open). See claude/03-phase2-v2-corrections.md.
