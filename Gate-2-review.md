# PoladCharkhesh Nova — Phase 2 V2 review

Reviewed 4 October 2026. Recommendation: retain the visual direction, but hold unconditional Gate 2 approval until the targeted corrections below are demonstrated. No prototype, asset, data, or project-document files were edited. No Gate was approved and Phase 3 was not started.

## Scope and evidence

The referenced chat exposes attachment names but not attachment contents. I located the complete V2 package at `C:\Users\Shahi\Desktop\PoladCharkhesh-Nova\design\phase2-v2` and reviewed its fa.html, en.html and admin.html with their actual CSS, JavaScript and JSON dependencies. This review applies to that local package; byte-for-byte identity with the former chat attachments was not independently established.

Requirements came from the local Stage 0 Genesis document, Phase 2 V2 refinement document, review hub's G2-1–G2-12 checklist, and V2 README. This is the PHP/MariaDB Nova proposal; findings from the older React/SQLite implementation were not carried across.

Verification included Chrome rendering at 1440, 768, 390 and 320 CSS pixels; Persian and English; light/dark samples; exact, unknown and Persian-digit search; existing filters; successive product requests with delayed responses; mobile opening/closing and failure; automated axe-core 4.13 checks; normal, reduced-motion and simulated no-scroll-timeline Anatomy; and an independent throttled first-load measurement. External contact actions were inspected without sending messages or making calls. Inquiry submission is a prototype and was not treated as a working backend.

## Findings requiring correction before freezing the prototype

### 1. Product identity can diverge from the selection — high priority

Two independently reproduced cases:

- Open 6205, then search 6208. Results report no matching code, but the workspace, product hash and workspace WhatsApp link still refer to 6205. Reproduced in both languages.
- Delay the 6205 JSON response, select 6205 and then 22220 E. When the delayed response arrives, the URL and highlighted row say 22220 E while the workspace shows 6205. The old response overwrites the newer selection.

This can produce an inquiry for the wrong part. Fix selection and request lifecycle together: clear or explicitly invalidate a workspace excluded by the current query; retain the intended active product ID; ignore obsolete responses using a request token or cancellation; update panel, URL and CTAs consistently. A loading state must not offer the previous product's active inquiry links.

Acceptance: changing query or quickly selecting multiple products never leaves different codes in the selected row, workspace, URL, copied link or inquiry payload. Source: S1 lines 76–115; S2 lines 20–31.

### 2. A known exact code is reported as absent when an old filter hides it — high priority

Choose the Ball family, then submit `22220 E` from the Hero. Both languages return zero rows and say the code is not in the catalogue, although that product exists. The exact-match check operates only on already-filtered rows.

Hero search should search the catalogue globally, clearing conflicting filters or explicitly revealing the exact match. Within the Explorer, distinguish “hidden by active filters” from “not in catalogue” and provide a reset action. Do not generate the unknown-code message from the filtered subset alone.

Acceptance: repeat with conflicting family, bore and verified-only filters, including UCP 208. Source: S1 lines 76–115.

### 3. Suffix-specific requests lose their identity — high priority, data/UX decision

Searching `6205-2RS` opens a workspace headed `6205`; its WhatsApp message carries only `6205`. The sample JSON and A3 editor make both `6205-2RS` and `6205-ZZ` aliases of the base product. A6 and A9 then present `6205-2RS → 6205` as a successful match. This is more than spacing/case normalization.

Bearing suffixes can encode construction differences, including shields and contact seals; see the manufacturer's explanation in [SKF's sealing reference](https://evolution.skf.com/new-sealing-solutions-for-deep-groove-ball-bearings/). Matching a base family must not silently discard the requested variant.

Fix: preserve the complete original designation in the workspace context and every inquiry. Separate equivalent spelling aliases from variants or related products. If exact variant data is absent, show “base series match; requested variant requires confirmation,” not an unqualified exact/verified match. Update the admin examples accordingly. Whether variants become separate products or children is an architecture choice to settle before implementation.

Acceptance: `6205`, `6205-2RS` and `6205-ZZ` remain distinguishable through search, copying, inquiry and admin matching. Source: S3; S2 lines 36–80 and 100–113; S4 lines 361, 644 and 862.

### 4. Mobile removes the product-specific inquiry-form route — medium priority

Below 900px, `.ws__foot .btn--inq` is explicitly hidden. Both languages show WhatsApp and Call, but no Inquiry form action. This contradicts the review hub's statement that the workspace footer always offers all three. Users who need the site form must close the sheet and lose the direct product-prefill route.

Fix: retain an accessible form link, for example on a second footer row. It should close the sheet, preserve the full requested designation and take focus to the inquiry form. The unknown-code form route already correctly preserved `6208` in testing.

Acceptance: all three actions are reachable at 320/390px without clipping or nested page scrolling. Source: S5 lines 359–373.

### 5. Modal focus and error recovery are incomplete — medium priority

- Reopening a cached mobile product left focus on BODY in four out of five tested openings. The initial focus call can occur before the double-animation-frame code makes the sheet visible.
- Aborting the product JSON request leaves a modal containing only “Couldn't load. Try again.” It contains neither Retry nor Close, while main content remains inert. Scrim/Escape can still dismiss it, so this is not a complete touch lockout, but it has no explicit, focusable recovery path.
- axe-core reports `aria-allowed-role` when the `aside#ws` is assigned `role="dialog"` on mobile.
- Escape did not close the desktop workspace, despite the Genesis keyboard requirement.

Fix: keep a stable, appropriately semantic dialog shell with Close available during loading, success and failure; add a real Retry control; focus after visibility is established; verify Tab/Shift+Tab containment and focus restoration. Handle failed dynamic module imports as well as failed JSON. Extend Escape behavior to the desktop region if keeping the stated interaction contract.

Acceptance: keyboard-only first open, cached reopen, failed load, retry, close and breakpoint change all remain usable. Source: S2 lines 25–31 and 126–150; S1 lines 136–140; S6/S7 workspace `aside`.

### 6. Admin Publish rules contradict the media requirements — medium priority, policy/design correction

A4 explicitly requires Persian and English alt text before an image may appear publicly. A7 instead shows a missing English alt as an amber warning that does not block Publish.

Fix: establish one rule and show it consistently. An informative image used in the English release must have appropriate English alt text, or be withheld from that release. Decorative images should have an explicit decorative state and empty alt. Missing alt on unused library media need not prevent unrelated publishing.

Acceptance: A4 and A7 depict the same public-release rule, including the actual blocking state and repair action. Source: S4 lines 524 and 715.

## Additional improvements and evidence gaps

**Typography and diagram legibility (G2-3).** The promised 13px minimum is not consistently implemented: public readout labels use 12px, and admin table headers, badges, helper text and chips frequently use 11.5–12.5px. The workspace SVG uses 12-unit labels in a 440×260 viewBox rendered with a 150px mobile height cap, making drawing annotations roughly 7px in the shown mobile layout. The separate readout remains legible, which reduces the impact. Raise meaningful labels to the agreed minimum and provide an enlarged drawing view or larger diagram labels. Source: S5 lines 344, 354 and 368; S2 drawing function; S8 typography rules.

**320px English overflow.** The English header expands the document to 324px on a 320px viewport. The language control ends around x=323.5. Comparing scrollWidth to innerWidth alone missed it because innerWidth also expanded to 324. Compare document scrollWidth to clientWidth and the requested viewport; shrink/reflow the header's brand/tools. The tested 390px pages did not show this page-level issue.

**Admin mobile scope needs honest wording (G2-11).** A1–A8 are wide static canvases in local horizontal scrollers: roughly 1268px inside a 356px-wide region at 390px. This is a reasonable wireframe review presentation, but does not demonstrate the note's promised single-column mobile reflow. A9 separately draws mobile inquiry triage. Either explicitly mark the other screens desktop-only at this stage or add representative narrow-screen sketches before committing to mobile edit/publish support. Source: S4 A9 notes; S8 frame-scroll and adm sizing.

**Admin accessibility tokens.** The automated scan found eight contrast failures, including placeholder/help styling `#8a90ad` on white at about 3.14:1. It also found ARIA on decorative span controls, empty table headers and repeated landmarks. Static simulated controls are expected in a wireframe and do not mean the production admin is broken; however, carry corrected colors and semantic-control requirements into its implementation. Do not describe the admin wireframes as already proving WCAG compliance.

**Per-value provenance needs a mixed-data example.** A3 correctly models a source/date/status per SpecValue, but the public sample schema and renderer show one product-wide source and check date. All dimensions share that single provenance line. Add a prototype product with dimensions from different sources and a mixture of verified/pending values, then show how source/status attaches to each value or clearly scoped group. This is an unresolved public UX contract, not evidence that the unimplemented CMS has lost data. Source: S4 lines 425–429; S2 lines 41–49.

**Pending family coverage.** UCP 208 is discoverable, visibly pending and has a bore-only readout rather than fabricated D/B values; this is good. Housing/unit core dimension definitions are explicitly deferred. Resolve those before building the verification validator and drawing templates. Do not require a database now simply to approve the wireframes.

**Migration.** CSV is a useful input, but the current notes say imported rows become drafts/pending. The Phase 3 plan should distinguish safe defaults for unsourced data from preservation of existing supported provenance. Audit the old source and define a repeatable, validated import with a reconciliation report. Do not blanket-reset verified information.

## What works and should be retained

- Strong, coherent navy/technical-drawing direction; the Hero's search remains dominant. Persian search is in the first viewport at 320 and 390px. No redesign of the overall visual language is warranted.
- Correct root language/direction for both public pages. Codes, phone numbers and dimensions stay Latin and drawing geometry is not mirrored. The Persian `۶۲۰۵` query successfully finds 6205 in both languages.
- Dense catalogue rows, meaningful family counts and a clearly visible pending/verified distinction. An unknown code has WhatsApp and form routes with its value preserved.
- Clean-search `22220 E` opens the workspace with desktop actions visible. Mobile keeps fixed actions and a scrollable body rather than nesting the entire page in the panel.
- The six Anatomy stages are driven by one progress value. Reduced motion produces a complete static technical scene with an auto-height track. The simulated JavaScript fallback also advances it. This is not a substitute for real Firefox/Safari testing.
- Admin information architecture covers A1–A9 and maps entities to screens. Separating Draft/Publish, Explorer visibility/indexing, Owner verification, stored inquiry/email notification, safe media deletion, release rollback and tri-state opening hours is sound.
- Placeholder branding, draft copy, empty optional channels, missing real photography, nonfunctional admin controls and the absence of a production backend are expected at this phase.

## Accessibility and performance interpretation

The fresh axe scans found no violations on the tested public desktop Persian/English light pages with UCP 208 open. Mobile scans found the dialog-role issue above. Admin results must be understood as wireframe findings, not a production application audit. Manual focus and failed-network tests found issues that the earlier desktop-only zero-violation claim did not cover. No screen-reader session or real Safari/Firefox run was performed.

The supplied performance harness separately observes FCP and LCP; its stored V2 runs happen to report equal values. Therefore the earlier chat's conversion of “first paint” to LCP was unsupported by that wording alone, but the actual local raw results do substantiate the reported LCP measurements. FCP and LCP should still be labelled separately.

One independent Persian run at 390px, 400kbps/400ms latency, 4× CPU and disabled cache measured FCP/LCP ≈1.372s, CLS 0 and about 69.3KiB transfer across HTML plus six resources. This supports the lightweight approach and does not exactly reproduce 0.832s; machine/browser differences and run variance matter. It is one laboratory run, not a field percentile or proof of production readiness.

Remaining performance risks are the 13-product sample versus the planned 68-product catalogue, future media/tools, lack of demonstrated field INP, and browser coverage. The existing event-duration scripts are interaction proxies; they do not by themselves establish field INP ≤150ms. The unversioned immutable asset names in the QA server are acceptable for a local prototype; production must implement the already-specified content-hashed assets.

## Gate recommendation

Retain G2-1/2/4's visual baseline and the broad admin IA. Request a targeted V2 correction round for findings 1–6; settle the provenance display and suffix/variant policy before freezing the public/data interaction contract. Resolve the typography and narrow-screen issues without reopening the entire visual direction.

After corrections, rerun the focused cases in both languages: conflicting-filter exact search; old/new and out-of-order selection; full-suffix inquiry; mobile form CTA; cached/failure keyboard flows; consistent media Publish validation. Then seek the owner's visual approval and present the Phase 3 Foundation plan separately. Hosting, real logo, final hours and notification address remain later inputs, not reasons to demand implementation before Gate 2.

## Source register

Line references above are one-based in the reviewed local files.

- S1: [Core interactions](C:/Users/Shahi/Desktop/PoladCharkhesh-Nova/design/phase2-v2/site/assets/nova.js)
- S2: [Workspace interactions and rendering](C:/Users/Shahi/Desktop/PoladCharkhesh-Nova/design/phase2-v2/site/assets/ws.js)
- S3: [6205 sample data](C:/Users/Shahi/Desktop/PoladCharkhesh-Nova/design/phase2-v2/site/data/p/6205.json)
- S4: [Admin wireframes](C:/Users/Shahi/Desktop/PoladCharkhesh-Nova/design/phase2-v2/site/admin.html)
- S5: [Public styles](C:/Users/Shahi/Desktop/PoladCharkhesh-Nova/design/phase2-v2/site/assets/nova.css)
- S6: [Persian prototype](C:/Users/Shahi/Desktop/PoladCharkhesh-Nova/design/phase2-v2/site/fa.html)
- S7: [English prototype](C:/Users/Shahi/Desktop/PoladCharkhesh-Nova/design/phase2-v2/site/en.html)
- S8: [Admin styles](C:/Users/Shahi/Desktop/PoladCharkhesh-Nova/design/phase2-v2/site/assets/admin-wf.css)

Browser evidence is supplied alongside this report: stale-product.png, mobile-workspace.png, mobile-load-error.png, anatomy-reduced-motion.png, and verification-results.json.
