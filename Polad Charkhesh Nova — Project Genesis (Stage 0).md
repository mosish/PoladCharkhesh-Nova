# Polad Charkhesh Nova — Project Genesis (Stage 0)

Oct 4, 2026 · @Mostafa Shahi

> **Status note, 2026-10-04 (added at the Claude Code takeover):** this file is the local export of the Gate 1 Genesis record. At takeover it matched the hosted doc (rev 47); neither reflects Phase 2 V2 or the correction round. For current status, see `00 genesis summary.md` and `04 takeover audit and performance policy.md`. **Owner decisions D14 and D17 supersede D5's byte/request budget and numeric targets, and D16 replaces the zero-third-party rule with dependency discipline** (see the note at the top of section I). Under D18 this local file, not the hosted doc, is authoritative. Gate 2 was approved on 2026-10-06; Phase 3 planning is in `05 phase3 foundation plan.md`. The original text below is kept as the historical record.

## Summary

The proposal: a search-first, one-page "digital engineering showroom" in a technical-drawing visual language. It runs on PHP 8.3+ with MariaDB as the CMS database, and a Publish step serves visitors pre-built static files at about 120 KB for the first screen, with no PHP or database work per page view. Gate 1 was approved on 4 Oct 2026 with four clarifications (C1–C4), now incorporated; Phase 2 (design system and visual prototypes) is under way, and no application or database exists yet. Revised 4 Oct 2026 for the confirmed hosting: MariaDB replaces SQLite, two-domain routing is specified, and deployment and backups are in section N.

**Gate 1 clarifications:** C1 explorer discoverability is separate from search-engine indexing (sections K, D, H); C2 release switching works without symlinks (G, N); C3 runtime versions are targets until the host probe freezes them (G, N, M); C4 public activity logging is removed from v1 (G, D, H, I).

**Phase 2 (awaiting Gate 2):** the design system and working Persian/English prototypes are in the [Phase 2 review hub](https://claude.ai/artifact/HTMqnj38bZshFxzvHpvXi7); source files are in the project folder under design/phase2.

- **Architecture (G):** PHP + MariaDB source of truth; an atomic, versioned Publish Engine writes static HTML/JSON; Node only for local tooling.
- **Domains (K):** .ir serves Persian and .com serves English from one docroot, with host-aware rewrites, shared slugs and hreflang.
- **Creative direction (E):** "Drawing Sheet" system, editorial type for rhythm, concentric hero mark; brand palette retained.
- **Scroll narrative (F):** sections as sheets of one drawing set, plus one Exploded Assembly set-piece in Anatomy.
- **Information architecture (C):** search by code first, explorer high, trust content honest and late.
- **Performance (I):** ≤ 120 KB / ≤ 8 requests first view, LCP ≤ 1.8 s, zero third-party requests. *(Superseded 2026-10-04: no byte/request budget or numeric targets (D14, D17); dependency discipline replaces zero third-party requests (D16). See section I.)*
- **Recovery (N):** release rollback, daily application backups, weekly off-site copy, hosting snapshots.

## A. Business interpretation

Polad Charkhesh is a Tehran-based B2B supplier of rolling bearings and adjacent rotating-equipment consumables, selling by consultation rather than by catalogue checkout. The website's job is to turn a part number or a problem into a qualified phone call, WhatsApp chat or written inquiry.

**The company.** A trade supplier in Narmak, Tehran (No. 433, Dardasht St), reachable by two mobiles (primary + WhatsApp 0912-719-5313), two landlines and 08:00–16:00 working hours. Everything else — email, socials, years trading, certifications, manufacturer relationships — is unknown and stays empty and configurable until you verify it.

**The customers.** Two personas carry almost all traffic:

| Persona | Arrives with | Needs within 30 seconds | Converts by |
| --- | --- | --- | --- |
| The code-holder (buyer, storekeeper, procurement) | A designation off a failed part: "6205-2RS", "22220 E", "UCP 208" | "Do you handle this? Who do I call?" | WhatsApp with the code, phone |
| The engineer (maintenance, mechanical, machine builder) | A duty: shaft size, load, speed, temperature, sealing | Dimensions, ratings, variants, interchange logic, datasheet | Inquiry form with specs, consultation call |

Secondary readers: workshop owners comparing suppliers, and existing customers who just want the phone number fast.

**The products.** Ten families in three groups: rolling bearings (ball, tapered roller, spherical roller, cylindrical roller, needle, thrust), mounted solutions (bearing units, housings), and protection/maintenance (oil seals, industrial lubricants). The audited baseline is 68 canonical products. Seals and lubricants are the cross-sell: every bearing failure conversation touches sealing and lubrication.

**The business model.** Price, stock, alternatives and terms are negotiated person-to-person; the site never shows them. Trust is earned through technical accuracy, not marketing claims — so every spec needs a source, and placeholders must look like placeholders.

**The website's objective.** Be the most useful 60 seconds a Tehran maintenance buyer spends before calling a supplier: recognise the code, show credible engineering data, and make the call or WhatsApp effortless. Success measures for v1: inquiry submissions and the codes they name, including codes not yet in the catalogue (stored in MariaDB), and product-workspace opens from server statistics; no visitor-tracking script (C4).

**Context from the previous build** (from your notes): Persian-first with English secondary; brand palette #2F338A / #2196F3 / #90CAF9 and a supplied logo; Iranian cPanel shared hosting; the 2026 rebuild in github.com/mosish/polad-charkhesh used plain PHP with JSON and no database. This genesis treats those as inputs, not constraints — section G replaces the no-database choice with MariaDB, now that the host has confirmed it.

## B. Competitor / industry research

The gap is clear: global manufacturers have deep engineering data but heavy, multi-level sites built for their own catalogues; Iranian distributors have speed-to-contact but present bearings as retail commodities with prices and carts. Nobody in the local market offers a fast, code-first, engineering-credible consultation experience — that is Polad Charkhesh's opening.

| Site | What works | What feels dated or wrong for us |
| --- | --- | --- |
| [SKF](https://www.skf.com/group/products/rolling-bearings/ball-bearings/deep-groove-ball-bearings/productid-6205) | One URL per designation; a dimension drawing beside a tight spec table; clear split between dimensions, calculation data and mounting data | JS-rendered shell (our fetch returned an empty page — crawlers and slow phones see the same risk); deep menus |
| [NSK](https://www.nsk.com/) | Navigation built around the engineer's tasks: Products, Industries, Catalogs & CAD, Tools & Resources | Five-level menus; tools sit off-site; the homepage is a corporate brochure |
| [Schaeffler](https://www.schaeffler.de/en/) | Separates the brand site from the engineering tools (medias catalogue, BEARINX-online) | The split forces users across domains; heavy hero imagery |
| [Timken](https://www.timken.com/) | Three discovery paths — product, market, brand; engineering tools and CAD as secondary CTAs | Investor and corporate content crowd out the buyer; redundant navigation |
| [IKO](https://www.ikont.com/) | Short, restrained homepage: 4 categories, industries, contact form | Leans on big-number marketing claims we cannot and should not copy |
| McMaster-Carr (industry benchmark, from prior knowledge; fetch blocked) | The gold standard for discovery: filter-as-you-go tables, a line drawing per family, instant results, server-rendered and very fast | Pure ecommerce; we borrow the explorer pattern only, never the ordering |
| Iranian distributors (khalijbearings.com, tehran-ballbearings.ir, bearingsmart.ir — [search results](https://khalijbearings.com/); sites timed out from our region) | Titles lead with "price + buy" and wholesale; WhatsApp-first contact is normal | Retail framing, price-led SEO, WooCommerce-style templates, weak technical data |

**Patterns to adopt**

- Designation is the primary key: a search box that accepts "6205 2rs", "۶۲۰۵" (Persian digits) and spacing/suffix variants is the single most valuable feature.
- Drawing beside data: a dimensioned line drawing next to the spec table is how every serious manufacturer earns trust — and SVG makes it nearly free.
- Data provenance as a visible feature: source and "verified on" date per value set. No competitor in Iran does this.
- Server-rendered product text: SKF's JS-only shell is a warning for SEO and weak devices.

**Patterns to avoid**

- Corporate big-number claims (years, countries, product counts) — unverifiable for us.
- Multi-level mega-menus — our catalogue is 68 products in 10 families; one explorer replaces the whole tree.
- Video heroes and carousels — cost bandwidth, add nothing for a code-holder.

Research limits: several manufacturer pages render client-side and Iranian sites timed out from the research environment, so parts of this table draw on prior knowledge. Phase 1 should include a hands-on review in a real browser from Iran (latency, rendering) before design freeze.

## C. One-page information architecture

The page is ordered by user intent, not by company org chart: the code-holder is served in the first screen, the engineer in the middle, and the trust-seeker at the end. Company "About" content moves down, because nobody searching "22220" wants it first.

**Persistent shell** (always visible): a slim top bar with logo, the explorer search field (collapses to an icon on mobile), language switch (فا / EN), and a call button; on mobile, a bottom action bar with Call · WhatsApp · Search.

| # | Section (anchor) | Purpose | Loads |
| --- | --- | --- | --- |
| 1 | Hero — "Find by code" (#top) | One sentence on who we are + a large designation search with example chips (6205, 22220, UCP 208). Bearing line-art enters here. | Critical HTML/CSS, inline SVG |
| 2 | Product families (#families) | Ten families as a technical index: line drawing, one-line definition, "what it's for", product count. Each opens the explorer pre-filtered. | Inline in HTML |
| 3 | Product Explorer (#explorer) | Filterable list of all published products + the product workspace (section D). | Shell in HTML; data JSON on approach |
| 4 | Anatomy / how bearings work (#anatomy) | The scroll narrative's main stage: rings, rolling elements, cage, seal, lubricant — each part links to the family that addresses it (seals, lubricants). Educational, and the bridge to cross-sell. | Inline SVG, small JS |
| 5 | Engineering desk (#engineering) | 2–3 defensible tools: designation decoder, basic rating life (L10) calculator, unit/fit reference. Clearly labelled indicative. | JS module on click |
| 6 | Applications (#applications) | Industries/machines where each family is typically used — framed as engineering guidance, not customer claims. | Inline in HTML |
| 7 | Documents (#documents) | Datasheets and technical references grouped by family, with source and date. | List in HTML; PDFs on click |
| 8 | How we work (#process) | The consultation process in 4 steps: send code or duty → we confirm the match and alternatives → availability and terms by phone → supply. Replaces "Why us" claims with a verifiable process. | Inline |
| 9 | Company (#company) | Verified facts only: name, address, hours, what we supply. Empty fields simply don't render. | Inline |
| 10 | Consultation & contact (#contact) | Inquiry form (code, quantity optional, application, contact), WhatsApp with prefilled message, phones, address, hours, static map image if coordinates are verified. | Form JS deferred |
| 11 | Footer | Contact repeat, family links, language, legal, "data provenance" note. | Inline |

**Why this order**

- Search first (1) because the majority arrive with a code; families (2) catch those who arrive with only a type.
- Explorer (3) sits high so the core task is never more than one scroll away.
- Anatomy (4) follows the explorer as the emotional/brand centrepiece — it rewards browsing without blocking task completion.
- Trust content (8–9) comes after value has been shown, and is honest by construction.

**Navigation:** anchor links with scroll-spy in the top bar on desktop (Products · Engineering · Documents · Contact); a compact sheet menu on mobile. Every section is reachable by URL hash and works without JS.

**Separate URLs that are not "pages"**: product deep links and a few legal/utility routes (privacy, 404). They render the same single-page shell with the relevant workspace open — see sections D and K.

## D. Product Explorer concept

Recommendation: a dense "technical index" list on the left and a **Product Workspace** that docks beside it on desktop and rises as a full-height sheet on mobile — no page navigation, but every product has a real, server-rendered URL.

**1. Finding**

- **One search field, designation-aware.** Normalises case, spaces, hyphens, slashes and Persian/Arabic digits (۶۲۰۵ → 6205); matches code, aliases (2RS / 2RSH / DDU are stored as admin-managed aliases, not guessed), family names in both languages. Exact-code hits jump to the top and auto-open the workspace.
- **Facets, not menus:** Family (10) · Brand (only if verified) · Bore d range · Seal/shield type · Row count — facets appear only when enough products carry that attribute, so empty filters never show.
- **Result rows, not cards:** each row = small family glyph, designation in mono, family name, d × D × B, a status mark (verified / technical data pending). 68 products fit in \~5 KB of index JSON; filtering is instant and client-side.
- **No-result path is a conversion path:** "We don't list 6205-ZZ/C3 yet — send it to a specialist" with the typed code prefilled into WhatsApp/inquiry. Searches run entirely in the browser and are not logged; demand for unlisted codes is captured when the visitor sends that code by inquiry (stored in MariaDB) or WhatsApp.

**2. Inspecting — the Product Workspace**

Tabs inside the workspace, in this order:

1. **Overview** — designation, family, brand (if verified), image or line drawing, short description, key dims, applications.
2. **Drawing & dimensions** — SVG section drawing generated from the family template + the product's d/D/B values, beside the dimension table.
3. **Engineering data** — C, C0, speed ratings, mass, etc. Each value set carries its source ("SKF catalogue, verified 2026-xx") or a visible "Technical data pending / Unverified" badge.
4. **Documents** — datasheets and references, with file size shown before download (matters on slow links).
5. **Ask about this product** — always-visible sticky footer: Check availability (WhatsApp, prefilled "استعلام موجودی: 6205-2RS") · Call · Send inquiry (form opens with the product attached).

Also: Compare (pin up to 3 products for a side-by-side table) — classified IMPORTANT, not CORE.

**3. Deep linking**

- Canonical product URL: `/p/6205-2rs` (fa default) and `/en/p/6205-2rs`. The server renders the full one-page shell with the workspace already open and the product's text in the HTML — shareable, bookmarkable, crawlable, and it works with JS off.
- With JS on, opening a product from the list uses `history.pushState` to the same URL and fetches a small product JSON (\~2–4 KB); Back closes the workspace. No full page reload.
- Filter state lives in the query string (`?f=spherical&d=40-60`) for shareable searches, but those are `noindex`.

**4. Mobile behaviour**

The list becomes full width; the workspace is a bottom sheet that snaps to 90% height with its own scroll, a drag handle and a close button; tabs become a horizontal segmented control; the WhatsApp/Call bar stays pinned. Tables of specs reflow into label–value pairs rather than scrolling sideways.

**5. Accessibility**

The workspace is a labelled region (desktop) or a modal dialog with focus trap (mobile); Esc closes; results list is a proper listbox-like structure with live result count announced; all of it works by keyboard.

## E. Three creative directions

Recommendation: **Direction 1, "Drawing Sheet", as the system**, borrowing Direction 2's typographic scale for section openers. It is the most credible for engineers, the cheapest to render, the most readable on a sunlit workshop phone, and it turns the honest-data rule into a visual signature. All three respect the supplied brand palette (#2F338A primary, #2196F3 secondary, #90CAF9 light) and logo. A visual comparison of the three is linked at the end of this section.

### Direction 1 — Drawing Sheet (precision blueprint)

- **Philosophy:** the website is a set of engineering drawings. Credibility through drafting conventions everyone in the trade reads instantly.
- **Visual language:** warm paper-white ground, 1 px navy hairlines, dimension lines with arrowheads, centre lines (dash-dot), section hatching, a title block (جدول مشخصات) used as the component frame for product info and contact.
- **Typography:** Vazirmatn (OFL, variable) for Persian and Latin UI; a monospace (JetBrains Mono or IBM Plex Mono, subset) for designations, dimensions and annotations. Two weights each.
- **Colour:** 90% paper and ink; #2F338A as drawing ink; #2196F3 reserved for interactive state; a single signal colour (amber) for "technical data pending" badges.
- **Grid:** visible 8 px drafting grid at low contrast; 12-column layout with deliberate margin "zones" like a sheet border (A, B, C / 1, 2, 3).
- **Products:** every product rendered as a line drawing from family templates — consistent even before photos exist.
- **Technical data:** tables styled as drawing tables — ruled, mono numerals, units in headers, tolerance-style footnotes for sources.
- **Bearing motif:** section views and exploded orthographic views.
- **Motion:** lines that draw themselves, dimension lines extending, measured and slow (300–600 ms, ease-out).
- **Imagery:** optional; photos sit inside "viewport" frames with crop marks.
- **Mobile:** the sheet border collapses to a top/bottom rule; drawings simplify to essential dimensions.
- **Performance:** excellent — almost entirely SVG and CSS.
- **Risk:** can feel cold or "too technical" for buyers — mitigated by generous Persian typography and clear CTAs.

### Direction 2 — Industrial Editorial

- **Philosophy:** a trade journal about rotation. Authority through confident typography and pacing.
- **Visual language:** alternating deep-navy (#14163F, derived from the primary) and off-white bands; very large Persian headlines; huge mono numerals as structural elements (section numbers, designations at 120 px+).
- **Typography:** a display Persian face for headlines (Vazirmatn Black or a licensed face such as Peyda/Estedad — licence to confirm) + Vazirmatn text + mono.
- **Colour:** dark-led, with #2196F3 as a single bright accent line.
- **Grid:** asymmetric editorial grid, wide margins, pull-quote-style engineering notes.
- **Products:** large single-product "features" for families; the explorer itself stays dense.
- **Technical data:** set like magazine sidebars — elegant but less dense.
- **Bearing motif:** large cropped ring arcs bleeding off-canvas as section dividers.
- **Motion:** typographic — headlines slide and reveal by line, numbers count.
- **Imagery:** depends on real, high-quality product/warehouse photography — which we don't yet have.
- **Mobile:** strong; editorial type scales well.
- **Performance:** good, but display fonts add 40–80 KB and photography adds weight.
- **Risk:** without real photography it looks empty; dark bands are harder to read outdoors.

### Direction 3 — Concentric System (orbit)

- **Philosophy:** the layout itself is a bearing — content organised in rings around a centre.
- **Visual language:** concentric circles and arcs as layout guides; product families arranged around a ring; section transitions rotate.
- **Typography:** geometric sans for Latin (e.g. a subset of Space Grotesk) + Vazirmatn; mono labels set on arcs.
- **Colour:** brand blue fields (#2F338A → #2196F3 → #90CAF9) as ring layers.
- **Grid:** polar on desktop hero/families; falls back to standard columns elsewhere.
- **Products:** families as "rolling elements" around the ring; hover/tap rotates the ring.
- **Technical data:** radial gauges for speed/load — attractive but less precise than tables.
- **Bearing motif:** the whole page geometry.
- **Motion:** continuous rotation, the most kinetic of the three.
- **Mobile:** weakest — polar layouts don't fit 360 px; must become a list.
- **Performance:** moderate — more JS for layout and rotation state.
- **Risk:** gimmick risk; radial text is hard in RTL; gauges misrepresent data precision.

### Comparison

| Criterion | 1 Drawing Sheet | 2 Industrial Editorial | 3 Concentric |
| --- | --- | --- | --- |
| Engineering credibility | Highest | Medium | Low–medium |
| Code-holder speed | High | Medium | Low |
| Distinctiveness in Iran | High | Medium–high | High |
| Works without photography | Yes | No | Yes |
| RTL/LTR quality | High | High | Low (radial text) |
| Mobile | High | High | Low |
| Weight | Lowest | Medium | Medium |

**Recommended hybrid:** Direction 1 as the full system; Direction 2's large type and numbered section openers for rhythm (one dark band, at the Anatomy/scroll stage, not alternating); Direction 3 only as the concentric hero mark, never as layout.

Note: Superdesign and frontend-design are not available in this session, so the [concept boards](https://claude.ai/artifact/H9uhLDuNiNsVmy31YebUim) were built directly as an HTML page: Persian first-screen specimens of all three directions plus a working Exploded Assembly scroll prototype. If you want, Superdesign explorations can be run in your local Claude Code before the design-system phase.

## F. Three bearing-inspired scroll narratives

Recommendation: **Concept 3 ("Drawing Set") as the page-wide narrative, with Concept 1 ("Exploded Assembly") as the single sticky set-piece inside the Anatomy section.** Concept 2 is held in reserve as a minimal progress indicator. Together they cost under 15 KB and degrade to static drawings.

Shared technique for all three: CSS scroll-driven animations (`animation-timeline: view()` / `scroll()`) where supported — Chrome/Edge 115+ and [Safari 26+](https://webkit.org/blog/17101/a-guide-to-scroll-driven-animations-with-just-css/); Firefox does not ship it by default as far as we know, so a \~1.5 KB IntersectionObserver fallback toggles end states instead of scrubbing. Under `prefers-reduced-motion`, everything renders in its final, readable state.

### Concept 1 — Exploded Assembly

- **Concept:** a sticky SVG bearing (deep groove, sealed) that disassembles as you scroll: seal lifts away, outer ring slides out, cage and balls separate, inner ring remains on a shaft centre line. Each part, when isolated, gets a callout with its name, function and a link to the related family (seal → Oil seals, grease → Lubricants, ring geometry → Ball bearings). At the end it reassembles and rotates once.
- **Engineering relevance:** teaches real anatomy; connects failure modes to products (contamination → seals, wear → lubrication). The strongest cross-sell device.
- **Desktop:** stage pinned for \~250vh; parts translate along the shaft axis (isometric-ish orthographic, not 3D).
- **Mobile:** stage pinned for a shorter run; callouts become captions below the drawing rather than leader lines.
- **Accessibility:** callout text is real HTML in reading order; the SVG is decorative (`aria-hidden`), so screen readers get a clear list of parts.
- **Technical approach:** one inline SVG (\~8–12 KB, gzip \~3 KB), 5 groups translated with CSS transforms on a view timeline; no canvas.
- **Performance cost:** low (transform/opacity only, compositor-friendly).
- **Complexity:** medium — the illustration is the main effort.

### Concept 2 — Raceway Progress

- **Concept:** the page edge is a raceway. A single ball travels down a thin vertical track in the margin (right in LTR, left in RTL); each section is a "station" tick with its number. Section headings sit on the raceway like dimension callouts.
- **Engineering relevance:** modest — it's wayfinding with a mechanical metaphor.
- **Desktop:** visible track with clickable stations (doubles as section navigation).
- **Mobile:** becomes a 2 px progress line under the top bar with a small ball.
- **Accessibility:** decorative; the real navigation is the anchor menu.
- **Technical approach:** one `scroll()` timeline on a single element; fallback is a static track.
- **Performance cost:** negligible (<1 KB).
- **Complexity:** low.

### Concept 3 — Drawing Set (sections as sheets)

- **Concept:** each section is a sheet in one drawing set ("Sheet 03 of 11 — Product Explorer"). As a sheet enters, its line work draws itself: border, centre lines, then dimension lines extending to their values. The hero opens on the assembled bearing in elevation; mid-page it's cut in section with hatching; the contact section closes on a completed title block holding the real address, phones and hours — the "signature" of the set.
- **Engineering relevance:** high — it is literally how engineers read; and drawings are generated from real product dimensions in the explorer.
- **Desktop:** line drawing per section in the margin zones; no pinning, so no scroll-jacking.
- **Mobile:** drawings scale into section headers; dimension draw-on retained (cheap).
- **Accessibility:** decorative drawings hidden from AT; content unaffected; reduced-motion shows completed drawings.
- **Technical approach:** SVG `stroke-dasharray`/`dashoffset` driven by `view()` timelines; shared symbol library (`<symbol>`/`<use>`) to avoid duplicate path data.
- **Performance cost:** low (\~4–6 KB of SVG total, gzip).
- **Complexity:** medium.

### Comparison

|  | 1 Exploded Assembly | 2 Raceway Progress | 3 Drawing Set |
| --- | --- | --- | --- |
| Signature value | Highest | Low | High |
| Engineering meaning | High | Low | High |
| Scroll-jacking risk | Medium (pinned) | None | None |
| Weight (gzip) | \~3 KB + 1.5 KB JS | <1 KB | \~4–6 KB |
| Mobile quality | Good (shorter pin) | Good | Good |
| Build effort | Medium | Low | Medium |

The combination gives one memorable moment (Exploded Assembly) inside a quietly consistent system (Drawing Set), without pinning the whole page.

## G. Architecture (revised for confirmed hosting)

Recommendation (D1, approved): **a PHP 8 application (target 8.3+) with MariaDB as the authoritative CMS database, and a PHP Publish Engine that compiles static HTML and JSON into versioned releases served directly by Apache.** A page view costs zero PHP and zero database queries. SQLite is withdrawn, because the provider does not manage it and asked us to verify WAL and locking ourselves; MariaDB is officially provided and covered by their backups. Node.js stays out of production. Release switching does not depend on symlinks, and runtime minimums are frozen only after the host probe (C2, C3 below).

### What the confirmed hosting changes

| Confirmed fact | Effect on the architecture |
| --- | --- |
| MariaDB officially provided and backed up | Becomes the single source of truth (was SQLite) |
| SQLite unmanaged; WAL behaviour unverified | Removed from production; no dual-engine support |
| Node.js 20.20 / 22.23 / 24.20 available | Development and build tooling only, never on the request path |
| .ir and .com parked on one account | One docroot; host-aware rewrite routing (section K) |
| Built-in hosting backups | Kept as the disaster layer; application backups still required (section N) |
| Finite shared CPU and RAM | Hard constraint: browsing must cost nothing at runtime |

### Options re-compared

| Option | Cost per page view | CMS strength | Fit with this host | Verdict |
| --- | --- | --- | --- | --- |
| A. PHP + MariaDB + static Publish | No PHP, 0 queries | Strong | Provider's supported stack | **Recommended** |
| B. PHP + SQLite + static Publish | No PHP, 0 queries | Strong | Unmanaged engine; locking on the host's filesystem unverified | Rejected for production |
| C. PHP + MariaDB rendering every request | 1 bootstrap + 5–20 queries (fewer with a page cache) | Strong | Works, but spends shared CPU and RAM on every visit | Rejected |
| D. Always-running Node app + MariaDB | Resident process holding RAM | Strong | Counts against plan limits; another runtime to operate | Rejected |
| E. Node static-site build triggered on the server | No PHP, 0 queries | Strong | Build spikes CPU/RAM; two runtimes to maintain | Rejected: a PHP Publish handles \~70 products × 2 languages in seconds |

### Runtime cost by request type

| Request | PHP | DB queries | Notes |
| --- | --- | --- | --- |
| Page view, product deep link, JSON, media, assets | None | 0 | Apache serves a file with cache headers |
| Search and filtering in the Product Explorer | None | 0 | Runs in the browser over the published product index |
| Inquiry submission | 1 small script | \~3 (rate check, insert, audit) | Plus one notification email |
| Admin screen | 1 | \~5–20 | Owners and editors only |
| Publish | 1 job | One consistent snapshot read | Writes a few hundred files in seconds, only when an admin clicks Publish |

The public site needs no cron or background process; cron is used only for scheduled backups and log rotation.

### Public activity logging (C4): removed from v1

Stage 0 proposed a public `api/beacon.php` that appended visitor events to a log file. On review it isn't needed for v1, so it is removed: the public site keeps exactly one dynamic endpoint, the inquiry form.

| Proposed event | What it would have recorded | Why v1 doesn't need it |
| --- | --- | --- |
| `search_miss` | The normalised code searched for with no result, plus page language | The no-result screen hands the typed code to WhatsApp or the inquiry form; inquiries carrying unlisted codes are stored in MariaDB and are a stronger demand signal (real buyers, with contact details) |
| `workspace_open` | Which product's workspace was opened | Already visible in the Apache access log and cPanel statistics as requests for `/data/p/<slug>.json` |
| `contact_tap` | Taps on the WhatsApp or phone links, with the product if any | Outbound taps can't be counted without a beacon, but the outcome that matters, the conversation or call, is seen directly |

Why a file rather than MariaDB was proposed, and why even that is dropped:

- A file append kept anonymous traffic away from MariaDB. Shared hosts cap concurrent database connections per account, and one append costs less than a connection, a query and a commit.
- Batching in the browser (one `sendBeacon` per session) would have cut requests further. Server-side buffered ingestion (a queue plus a cron importer) adds moving parts for the same result, so it was never the better option.
- Any version still means a PHP execution per visitor action, an unauthenticated write endpoint that needs rate limits and size caps against abuse, and visitor data that needs a retention policy. For v1 the value doesn't justify that.

POST-LAUNCH, only if demand data is wanted: one batched `sendBeacon` per session on `pagehide` carrying at most 10 normalised codes, with no IP address or free text stored, appended under a file lock to a size-capped daily file outside the docroot, and imported into MariaDB only when an admin opens the report.

### MariaDB data architecture

- **Storage:** InnoDB for every table; `utf8mb4` with `utf8mb4_unicode_520_ci`. Persian search uses separate normalised, indexed columns computed in PHP (Arabic ي/ك → Persian ی/ک, Persian and Arabic digits → ASCII, ZWNJ and spacing cleaned) instead of relying on collation behaviour.
- **Strictness:** `sql_mode` set per connection to strict (`STRICT_ALL_TABLES`, no zero dates).
- **Integrity:** foreign keys everywhere; `ON DELETE RESTRICT` for shared references (media in use cannot disappear), `CASCADE` only for owned children (a product's spec values).
- **Indexes:** unique slug and unique normalised designation; (family, status, sort order) for the explorer; (status, created) for inquiries; every foreign-key column.
- **Bilingual fields:** paired columns (`title_fa`, `title_en`) for the fixed content model — simple, fast, and missing translations are easy to find.
- **Writes:** every multi-row save inside a transaction; a row-version column gives optimistic locking so two editors cannot silently overwrite each other.
- **Migrations:** numbered SQL files plus a `schema_migrations` table, applied by a PHP migrator from System → Updates, always after an automatic backup; forward-only and tested on a copy first.
- **PDO access:** exceptions on, native prepared statements (emulation off), `utf8mb4` in the DSN, one lazy connection per request, no persistent connections (shared hosts cap connections).
- **Portability:** standard SQL with no stored procedures, triggers or events. MariaDB is the only supported engine, and tests run against it.

### Publish Engine

1. **Lock:** `GET_LOCK('pc_publish', 0)` plus a lock file; a second Publish is refused with a message, not queued.
2. **Snapshot:** `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`, so everything comes from one moment even if an editor saves mid-publish.
3. **Validate:** required fields per language, broken media references, duplicate slugs, pending products kept out of the sitemap and set to `noindex`, SEO length warnings. Blocking errors stop the run with a readable report.
4. **Build** into a fresh `_site/releases/<id>/`: Persian and English HTML, product deep-link HTML, product index JSON, per-product JSON, per-host sitemap and robots, SEO and OpenGraph metadata, `.gz` siblings (`.br` only if the brotli extension exists), and a `manifest.json` listing every file with size and hash.
5. **Verify:** re-read the manifest from disk and check counts and hashes; confirm every referenced `/assets/` and `/media/` file exists; fetch the release through a private preview path and check status 200 and the right `<html lang>`.
6. **Switch:** make the new release live in one atomic filesystem operation, using the strategy the host probe selected: a symlink swap where symlinks are allowed, otherwise the two-slot directory switch described below. The previous release always stays intact.
7. **Record and prune:** a `publishes` row (id, user, time, manifest hash, file counts, duration, status) and an audit entry; keep the last 5 releases (in place for the symlink strategy, archived under `storage/releases/` for the slot strategy) and delete older ones.

A failure before the switch deletes the half-built directory, releases the lock and leaves the live site untouched. Rolling back to the previous release is instant under both strategies; an older retained release is first restored into place (seconds), with no rebuild and no database involvement. Content-hashed `/assets/` and content-addressed `/media/` live outside releases, so each release is only a few MB of HTML/JSON, and nothing a retained release references is ever deleted.

### Release switching without depending on symlinks (C2)

Publish makes a release live through one `ReleaseSwitcher` interface with two implementations; the host probe picks one and stores it in config. Every other Publish step is identical, and both strategies are tested locally before launch.

|  | Strategy S: symlink swap (preferred) | Strategy D: two-slot directory switch (fallback) |
| --- | --- | --- |
| Needs | `symlink()` allowed in PHP; Apache `FollowSymLinks` or `SymLinksIfOwnerMatch` | Only `rename()` and an `.htaccess` file test |
| Served path | `_site/current` → `releases/<id>` | `_site/slot-a/` or `_site/slot-b/`, chosen per request by whether `_site/ACTIVE_B` exists |
| Switch | Create a temporary symlink and `rename()` it over `current` | Create or delete the `ACTIVE_B` marker: one `rename()` or `unlink()` |
| Visitors see | The old or the new release, never a mix | The old or the new release, never a mix; the served slot is never written or renamed |
| Instant rollback | Any of the last 5 releases | The previous release, still in the other slot |
| Older rollback | Same as above | Restore an archived release into the inactive slot (seconds), verify, switch |
| Extra runtime cost | None | One file-existence test per request in Apache |

**Strategy D, step by step**

1. Check the last switch was at least 2 minutes ago, so requests still reading the previous slot have finished; otherwise Publish asks you to retry shortly.
2. Build and verify the new release in `_site/build-<id>/` (Publish steps 4–5).
3. Rename the inactive slot, which holds the previous release, to `storage/releases/<previous-id>/`. The archive is outside the docroot but on the same filesystem, so the rename is instant.
4. Rename `_site/build-<id>/` to the inactive slot's name and re-check its manifest.
5. Flip the marker. That single operation is the moment the new release goes live.
6. Keep the last 5 archived releases and prune older ones.

**Crash safety:** each step is a single rename, and a small journal (`_site/publish.journal`) plus the `publishes` row record which step is in progress. If PHP is killed mid-publish, the next admin request reads the journal and completes or undoes the unfinished step. The live slot is never touched before step 5, and step 5 is atomic. The marker on disk is the authority on what is live; the database is reconciled to it.

**Rejected: renaming the live directory.** A plain swap (rename `live` to `old`, then `new` to `live`) leaves a moment with no live directory, so visitors get 404s, and leaves no live site at all if the process dies between the two renames. Strategy D never renames or writes a directory while it is being served.

### Node.js

Node.js is available on the host but stays out of the production runtime. Node 20 lost security support on 30 April 2026, so local tooling uses Node 24 (security support to 30 April 2028): CSS/JS minification, font subsetting, SVG optimisation, and Playwright and Lighthouse runs. Its outputs ship as committed build artifacts; neither Publish nor any visitor request needs Node.

### Runtime compatibility policy (C3)

PHP 8.3+ and MariaDB 10.11+ are targets, not yet requirements. The code stays inside a conservative baseline so it runs on whatever the host actually offers, and the real minimums are frozen once the probe has run.

|  | Target until the probe | Baseline the code is written to | After the probe |
| --- | --- | --- | --- |
| PHP | 8.3 or newer | PHP 8.1 language level: no readonly classes or DNF types (8.2), no typed class constants or `json_validate()` (8.3), no property hooks or new array functions (8.4) | Freeze the minimum at the probed version if it still gets security fixes (8.2 until 31 Dec 2026, 8.3 until 31 Dec 2027). If it doesn't, upgrading PHP in cPanel is a launch blocker, not something the code works around |
| MariaDB | 10.11 LTS or newer | SQL common to MariaDB 10.5+ and MySQL 8.0: InnoDB, utf8mb4 with the collation set explicitly (`utf8mb4_unicode_520_ci`, since newer MariaDB releases changed the server default), foreign keys. No `RETURNING`, sequences, system-versioned tables, JSON functions, `ALTER … IF NOT EXISTS`, stored routines, triggers or events | Freeze the minimum at the probed version; migrations are tested against exactly that version |
| Node.js | 24, local tooling only | Not used on the server | Unchanged |

PHP lint and static analysis run against both the 8.1 baseline and the newest available version. The probe results and the frozen minimums are recorded in the README and AGENTS.md at the start of Phase 3.

## H. CMS / Admin Control Center

The Control Center edits a structured content model in MariaDB; nothing the public sees is hand-coded except layout. A visible **Draft → Publish** model separates editing from the live site, and Publish compiles the static output atomically.

### Content model (core entities)

| Entity | Key fields (all text fields have fa + en columns) | Notes |
| --- | --- | --- |
| Product | slug, designation, aliases, family, brand?, status (draft/published/archived), verification (pending/verified), show in explorer, featured, summary, applications, notes | Designation and aliases drive search |
| Spec value | product, spec key (from a controlled list per family), value, unit, source, verified date, is\_placeholder | One row per value: provenance is per value, not per product |
| Family | slug, name, definition, drawing template, order | 10 rows, editable copy |
| Brand | name, logo, verified flag | Only verified brands render publicly |
| Source | title, publisher, edition/year, URL or file | Reusable references (e.g. a manufacturer catalogue) |
| Media | file, type (image/pdf/svg), alt text, caption, dimensions, size, variants | Reference-counted |
| Document | media, title, family/product links, language, version | Datasheets |
| Section | key (hero, families, anatomy…), heading, intro, CTA text, visibility, order | Fixed set of sections with fixed fields — no page builder |
| Company setting | name, address, phones\[\], WhatsApp, hours, optional channels | Empty values never render |
| SEO | per section/product: title, description, OG image, index flag | Defaults auto-generated, overridable |
| Inquiry | contact, message, product refs, language, status (new → in progress → answered → closed), notes | Never published |
| User / Role | email, password hash (Argon2id or bcrypt), role | Roles: Owner, Editor |
| Audit event | user, action, entity, before/after summary, time, IP | Append-only |

### Screens

1. **Dashboard** — unpublished changes, new inquiries, inquiries mentioning codes not in the catalogue, products with technical data pending, last backup.
2. **Products** — table with filters; editor with tabs mirroring the public workspace (Overview · Dimensions & data · Media · Documents · SEO), live preview of the generated drawing, per-value source picker.
3. **Media library** — upload, alt text (fa/en), "used by" list, safe delete (blocked while referenced), replace-in-place.
4. **Sections** — one structured form per page section, fa and en side by side.
5. **Company & contact** — settings form with validation (phone normalisation, wa.me link built automatically).
6. **SEO** — overview of all titles/descriptions with length meters and missing-field warnings.
7. **Inquiries** — inbox with status workflow, search, filters, notes; email notification on new inquiry.
8. **Publish** — diff of what will change, Publish button, history of publishes with one-click rollback to the previous build.
9. **System** — users, roles, audit log, backup (download/scheduled), restore (with pre-restore automatic backup), settings.

### Rules

- Persian and English values are separate fields, never auto-translated; a missing translation is flagged on the dashboard and falls back visibly only in admin preview.
- Admin UX standards: validation inline, save feedback, unsaved-change guard, confirmation dialogs for destructive actions, empty and error states for every list.
- Admin assets live under `/admin` and are never referenced by public pages.
- Roles kept to two (Owner: everything incl. users/backup/restore; Editor: content, media, inquiries, publish). More only if a real need appears.

The architecture diagram below shows how an edit becomes a visitor's page.

&#91;embedded content: content flow · edit, store, publish, serve\]

Edits land in MariaDB; Publish compiles them into static files that Apache serves directly, and the inquiry endpoint is the only PHP a visitor ever reaches.

## I. Performance strategy

> **Superseded in part, 2026-10-04: owner decisions D14, D16 and D17.** These are no longer project constraints:
> - the byte and request budgets in this section (120 KB target, 150 KB ceiling, ≤ 8 requests, the per-asset and on-demand budgets);
> - the Core Web Vitals and Lighthouse numbers as hard targets;
> - the "Budgets are enforced" build check below, and the "Lighthouse budget checks" in section N.
>
> Performance remains normal engineering quality: no obvious waste, sensible media, caching and lazy loading where useful, no serious user-visible regressions, good real-world responsiveness. It is a sanity/regression concern, not a size objective. Measurements, Core Web Vitals included, are diagnostic signals only, with no replacement thresholds (D17). The zero-third-party-requests rule is replaced by dependency discipline (D16): no unnecessary third-party dependencies, each one evaluated case by case on its value, and no external fonts, trackers, analytics, CDNs or remote libraries merely for convenience. The caching and compression practices stay. The original Gate 1 text follows unchanged. Details: `04 takeover audit and performance policy.md`.

Target: **the first screen is usable at about 120 KB transferred and ≤ 8 requests**, with a hard ceiling of 150 KB, so a phone on a congested 3G-class link (≈400 kbps, 400 ms RTT) sees the search box in roughly 3 seconds and a 4G phone in under 1.5.

### Budgets (compressed transfer, Persian homepage, first view)

| Asset | Budget | How |
| --- | --- | --- |
| HTML | ≤ 30 KB | Pre-built static file; critical CSS + hero/family SVG inline; below-fold section text included (it's cheap and good for SEO) |
| CSS total | ≤ 14 KB | \~6 KB critical inline, rest one deferred file; tokens, no framework |
| JS initial | ≤ 12 KB | One ES module: nav, search shell, scroll fallback, WhatsApp/phone helpers. `type=module`, deferred |
| Fonts | ≤ 60 KB | Vazirmatn subset (Persian + basic Latin + digits), 2 weights, woff2, regular preloaded; mono subset (\~10 KB) loaded on demand, `ui-monospace` fallback |
| Images | ≤ 15 KB | Logo as SVG; no raster in the first screen |
| Total first view | ≤ 120 KB target, 150 KB ceiling | Everything else on demand |
| Requests | ≤ 8 | HTML, CSS, JS, 1–2 fonts, favicon |

**On-demand modules**

| Module | Trigger | Budget |
| --- | --- | --- |
| Explorer logic + product index JSON (68 products) | Explorer nears viewport, or search focus | ≤ 15 KB |
| Single product JSON | Workspace opens | ≤ 4 KB |
| Product image | Workspace Overview tab | ≤ 40 KB AVIF/WebP at 2× |
| Each engineering tool | Tool opened | ≤ 10 KB |
| Inquiry form validation | Form focus | ≤ 4 KB |
| PDFs | Explicit click, size shown | — |
| Admin | Never on public pages | — |

### Core Web Vitals targets (field, 75th percentile mobile)

| Metric | Target | Google "good" threshold |
| --- | --- | --- |
| LCP | ≤ 1.8 s | ≤ 2.5 s |
| CLS | ≤ 0.05 | ≤ 0.1 |
| INP | ≤ 150 ms | ≤ 200 ms |
| Lighthouse Performance (mobile) | ≥ 95 | — |

### Rules

- **Zero third-party requests** on public pages: no Google Fonts, Maps, Analytics or CDN scripts — they are slow or unreliable from Iran and leak visitor data. Maps become a static image + "Open in Neshan / Google Maps" links (only if coordinates are verified). No analytics script or beacon in v1: cPanel's server-side statistics cover traffic, and inquiries in MariaDB cover demand (section G, C4).
- **Caching:** hashed filenames for CSS/JS/fonts/media with `Cache-Control: max-age=31536000, immutable`; HTML and JSON with `no-cache` + ETag so Publish is instant.
- **Compression:** pre-compressed `.br` and `.gz` siblings generated at Publish and served via `.htaccess` when the host allows; otherwise `mod_deflate`.
- **Every dependency** must state its value vs. cost in the decision log; the default answer is no.
- **Budgets are enforced:** a CI-style check script fails the build if any budget is exceeded; Lighthouse and WebPageTest runs on throttled profiles are part of QA.

## J. Media strategy

Uploads are validated and converted once, at upload time, in PHP; visitors only ever receive pre-sized, cache-forever files from the local disk. No cloud media service.

**Upload pipeline**

1. Accept: JPEG, PNG, WebP, (AVIF if the host's GD/Imagick decodes it), SVG, PDF. Limits: images ≤ 10 MB, PDFs ≤ 20 MB (configurable, also capped by the host's `upload_max_filesize`).
2. Validate by content, not name: `finfo` MIME sniffing + magic bytes + re-decode images; reject anything that fails. PDFs checked for header/structure; SVGs sanitised with a strict allow-list (no scripts, no external refs, no event attributes) or rejected.
3. Rename: content hash + safe slug (`6205-2rs-a1b2c3d4.webp`); original filenames kept only as metadata.
4. Process images: strip EXIF/metadata, auto-orient, generate widths 320 / 640 / 1024 / 1600 in WebP (AVIF too where supported) plus a JPEG fallback at 640; record dimensions for `width`/`height` attributes (zero CLS).
5. Store under `/media/yyyy/` in the public tree; PHP execution disabled there via `.htaccess`; directory listing off.
6. Register in the Media table with alt text (fa/en), caption, sizes and the list of entities referencing it.

**Delivery:** `<picture>` with `srcset`/`sizes`, `loading=lazy` and `decoding=async` everywhere except an LCP image (we have none above the fold by design). PDFs are linked with their size and page count shown; no in-page PDF viewer.

**Deletion:** blocked while referenced (the editor shows where); unreferenced files go to a 30-day trash before physical deletion; replace-in-place keeps references intact while busting the cache via the new hash.

**Backup:** media is included in the application backup (a manifest plus any files not already backed up); the full four-layer strategy is in section N.

**Fallback if the host lacks WebP/AVIF in GD/Imagick:** keep optimised JPEG/PNG, and optionally pre-convert on the admin's computer — decided after the host probe.

## K. One-page SEO strategy

The compromise: **one page for people, \~70 real documents for crawlers.** The homepage is the hub; every published product has its own server-rendered URL that is the same one-page shell with that product's workspace open. That is about 70 indexable product URLs per language — not "dozens of pages", but the minimum that lets "6205 2RS" searches land on the right content.

| URL | Indexed | Content in HTML | Purpose |
| --- | --- | --- | --- |
| `/` (fa) and `/en/` | Yes | All sections, family summaries, contact | Brand, family and "bearing supplier Tehran" queries |
| `/p/<slug>` and `/en/p/<slug>` | Only when Verified; pending products are noindex and excluded from the sitemap (see below) | Full product text, specs, sources, drawing | Designation queries |
| `/`?f=… filter views | No (query/hash) | — | Shareable views only |
| `/privacy`, 404 | privacy yes, 404 no | — | Utility |

**Mechanics**

- Real `<a href>` links to every product inside the explorer list (rendered in the static HTML), so crawlers find them without JS.
- Unique `<title>`/meta description per product and language, generated from structured data, overridable in admin.
- `hreflang` pairs fa ↔ en plus `x-default`; self-referencing canonicals; one canonical per product (aliases never create URLs).
- Structured data where legitimate: `Organization` / `LocalBusiness` with verified address and phones only; `Product` with name, category, brand (only if verified), description — **no `Offer`/price**, which also avoids misleading rich results; `BreadcrumbList` (Home → Family → Product); `FAQPage` only if real FAQs are written.
- Products with technical data pending are `noindex` and excluded from the XML sitemap until verified, though still findable in the explorer (rules below); thin or unverified technical pages hurt trust and rankings.
- XML sitemap (with `xhtml:link` alternates) and `robots.txt` generated at Publish.
- Semantic outline: one `h1` (fa/en brand + what we do), `h2` per section, `h3` per family; product pages promote the designation to `h1`.
- `.ir` vs `.com`: recommended to make one domain canonical per language (e.g. `.ir` serves Persian, `.com` serves English, cross-linked with hreflang) instead of duplicating both languages on both domains. Now confirmed: both domains park on one hosting account; the delivery rules follow below.

### Two-domain delivery (D6, revised)

Both domains park on one docroot, and `.htaccess` rewrite rules read the requested host to serve that domain's language from the current release. Park Domain only aliases the docroot; the language routing is ours. Paths below show the symlink strategy; under the two-slot strategy, current means the active slot. Domain names below assume poladcharkhesh.ir and poladcharkhesh.com, to be confirmed.

|  | poladcharkhesh.ir | poladcharkhesh.com |
| --- | --- | --- |
| Default language | Persian (RTL) | English (LTR) |
| `/` serves | `_site/current/fa/index.html` | `_site/current/en/index.html` |
| `/p/<slug>` serves | `_site/current/fa/p/<slug>/index.html` | `_site/current/en/p/<slug>/index.html` |
| Canonical | `https://poladcharkhesh.ir/…` | `https://poladcharkhesh.com/…` |
| `/robots.txt`, `/sitemap.xml` | Persian set | English set |
| Language switch goes to | Same path on .com | Same path on .ir |

**Rules**

- **Same slug in both languages** (ASCII, from the designation), so the language switch is the other host plus the same path, with no lookup.
- **hreflang** on every page: `fa` → .ir URL, `en` → .com URL, `x-default` → .com (recommended as the cross-border fallback; see D10). Emitted only when both language versions are published.
- **No duplicates:** internal folders (`/_site/`, `/fa/`, `/en/`) are never reachable directly; `www` → apex and http → https with 301s; an English path requested on .ir (or the reverse) 301s to the right domain; the server IP or the temporary cPanel URL get an `X-Robots-Tag: noindex` header and a 301 to .ir.
- **No automatic redirect by browser language or IP:** it misroutes crawlers and VPN users. At most, a dismissible "English version" hint appears on .ir when the browser prefers English.
- **Missing translation:** that language's product page is not built; the switch link goes to that language's explorer with the code prefilled.
- **Admin on one host only** (`poladcharkhesh.ir/admin` recommended, see D11); the other host 301s `/admin`. Session cookie `Secure; HttpOnly; SameSite=Strict`.
- **Inquiry endpoint** `/api/inquiry` answers on both hosts as a same-origin POST, so no CORS.
- **SSL:** AutoSSL must cover both domains and both `www` variants; parked domains sometimes need this requested.
- **Fallback mode:** if only one domain is live at launch, a config switch serves English under `/en/` on the same domain with the same hreflang logic, so launch is not blocked.
- **Search engines:** register both domains as separate Search Console and Bing properties and submit each sitemap.

### Verification status, Product Explorer and indexing (D9, clarified as C1)

Finding a product inside the site and having search engines index it are two separate switches. A product whose technical data is still pending can be useful in the Product Explorer while staying `noindex` and out of the XML sitemap.

| Status (set in admin) | Product Explorer | Product URL | Robots | XML sitemap | Structured data | hreflang |
| --- | --- | --- | --- | --- | --- | --- |
| Draft | Hidden | Not built | — | Excluded | — | — |
| Published · Technical data pending / Unverified | Shown with the badge "Technical data pending / Unverified" (fa: «اطلاعات فنی در انتظار تأیید») | Built and shareable | `noindex, follow` | Excluded | None | Omitted |
| Published · Verified | Shown | Built | `index, follow` | Included | `Product` (no `Offer`) + `BreadcrumbList` | fa ↔ en when both are verified |
| Archived | Hidden | 410 Gone, or 301 to the family view (decided in Phase 3) | — | Excluded | — | — |

- **Sufficiently verified (proposed rule):** designation and family confirmed, and every core boundary dimension for the family (for radial bearings d, D, B) has a cited source; an Owner then sets "Verified for indexing". Individual values without a source still show as pending, even on a verified product.
- **Explorer visibility** is its own admin flag ("Show in Product Explorer", on by default for published products). The badge appears in the result row, the workspace header and the Data tab.
- **One source of truth:** the `robots` meta tag and the sitemap generator read the same status, and Publish fails validation if any sitemap URL would be `noindex`.
- Links from the explorer to pending products stay ordinary followed links, so verifying a product later changes nothing but its status.
- Pending pages keep a self-referencing canonical; hreflang is omitted until both language versions are verified, to avoid mixed signals.
- Internal search is client-side over the published product index, which includes pending products; search-engine indexing is decided per URL and is unaffected by it.

## N. Deployment, hosting prerequisites and backups

One cPanel account hosts everything: PHP code and secrets sit outside the docroot, both parked domains share `public_html`, and the public site is the `current` release folder. Recovery has four independent layers, so neither a bad publish, a bad migration nor the loss of the hosting account is fatal.

### Server layout

```text
/home/<account>/
├── app/                  PHP source: admin, publish engine, migrations (not web-reachable)
├── config/config.php     DB and SMTP credentials (not web-reachable, never exported)
├── storage/
│   ├── backups/          application backups, rotated
│   ├── releases/<id>/    archived releases (two-slot strategy)
│   ├── logs/             application and error logs, rotated
│   └── tmp/              upload staging, build scratch
└── public_html/          shared docroot of both parked domains
    ├── .htaccess         host + release routing, HTTPS/www redirects, security and cache headers
    ├── admin/index.php   thin front controller into ../app
    ├── api/inquiry.php   inquiry endpoint, the only public PHP
    ├── assets/           content-hashed CSS, JS, fonts (immutable)
    ├── media/            uploads; PHP execution disabled
    └── _site/            never requested directly
        ├── symlink strategy:  releases/<id>/  and  current -> releases/<id>
        └── two-slot strategy: slot-a/, slot-b/, ACTIVE_B marker, build-<id>/
```

### Deploying code

1. Build locally with Node 24: minify, subset fonts, optimise SVG, run Playwright and Lighthouse budget checks.
2. Package a versioned zip of PHP plus built assets (no `node_modules`, no secrets).
3. Upload with cPanel Git Version Control if GitHub is reliably reachable, otherwise File Manager or SFTP; extract to `app_new/` and swap it with `app/` by rename.
4. In Admin → System → Updates: automatic backup, then pending migrations, then a Publish.
5. Staging (IMPORTANT, not CORE): a subdomain with its own database, password protection and `noindex`, for trying an update before production.

### Hosting prerequisites

The Phase 1 probe script checks every "to verify" row and prints a report.

| Requirement | Target (actual minimum frozen after the probe) | Status |
| --- | --- | --- |
| MariaDB | 10.11 LTS or newer; [10.6 reached end of life on 6 Jul 2026](https://mariadb.org/mariadb-server-10-6-reaches-end-of-life-on-july-6th/) | Provided; version to verify |
| PHP | 8.3 or newer; code baseline is the 8.1 language level; [8.2 security support ends 31 Dec 2026](https://endoflife.date/php) | To verify |
| PHP extensions | pdo\_mysql, mbstring, intl, fileinfo, gd with WebP (AVIF optional), zlib, openssl, zip | To verify |
| PHP functions and limits | `rename()` enabled; `symlink()` optional (it selects the release-switch strategy); `exec()` not needed; memory\_limit ≥ 128M; max\_execution\_time ≥ 60 s; upload/post size ≥ 20M | To verify |
| Apache | mod\_rewrite, `.htaccess` overrides, mod\_headers, mod\_expires or mod\_deflate; FollowSymLinks or SymLinksIfOwnerMatch only for the symlink strategy | To verify |
| Domains | .ir and .com parked on one docroot | Confirmed |
| SSL | AutoSSL / Let's Encrypt covering both domains and both `www` variants | To verify |
| Email | SMTP mailbox for inquiry notifications, with SPF and DKIM | To verify |
| Cron | At least daily, for backups and log rotation | To verify |
| Quotas | ≥ 2 GB free disk; inode allowance for about 7 releases of a few hundred files each | To verify |
| Node.js | 20.20 / 22.23 / 24.20 available; not required in production ([Node 20 security support ended 30 Apr 2026](https://endoflife.date/nodejs)) | Confirmed |
| Hosting backups | Provider snapshots | Confirmed; ask retention period and restore procedure |

### Backups and recovery

| Layer | What | When | Restores |
| --- | --- | --- | --- |
| 1. Release history | Last 5 published releases | Every Publish | Public site in one click, no database involved |
| 2. Application backup | Logical MariaDB dump written by PHP from a consistent snapshot (gzip) + media manifest and new media files + export of safe settings | Daily by cron; automatically before every migration and restore; on demand | Full CMS state, from the admin |
| 3. Off-site copy | Owner downloads the latest application backup | Weekly, with a dashboard reminder | Survives loss of the hosting account |
| 4. Hosting backups | Provider's account snapshots | Provider's schedule | Disaster recovery; not relied on for routine restores |

- Backups live outside the docroot in `storage/backups/`, rotated 7 daily / 4 weekly / 3 monthly, each with a checksum and the schema version it was taken at.
- The dump is written by PHP, so it does not depend on `mysqldump` or `exec()`, which shared hosts often disable.
- Restore checks the checksum and schema version, takes a safety backup, restores while the admin is in maintenance mode (the static public site keeps serving), then republishes.
- Secrets (database and SMTP passwords) are never in an export; a restore on a new host takes a fresh `config.php`.
- A full restore drill on staging is a Phase 7 exit criterion.

## L. Project phases

Seven phases with four approval gates; 4a (public) and 4b (admin) run in parallel on one data model, so the CMS is real from the first public screen. Work is labelled CORE / IMPORTANT / POST-LAUNCH, and after Gate 4 only bugs are accepted.

&#91;embedded content: roadmap · 7 phases, 4 gates\]

Each gate is a hard stop for your review; nothing is called complete until its workflow is implemented, persistent and tested.

## M. Risks & decisions requiring approval

**Decisions for you**

| # | Decision | Recommendation | Why it matters |
| --- | --- | --- | --- |
| D1 | Architecture (revised) | PHP 8.3+ + MariaDB as source of truth + PHP Publish Engine with atomic, versioned releases; Apache serves static output | Zero PHP and zero queries per page view on a shared plan |
| D2 | Creative direction | Drawing Sheet system + editorial type scale + concentric hero mark | Sets the design system |
| D3 | Scroll narrative | Drawing Set page-wide + Exploded Assembly in Anatomy | Main build effort on the public side |
| D4 | Information architecture | Search-first order in section C | Section order and navigation |
| D5 | Performance budget | 120 KB target / 150 KB ceiling, zero third-party requests *(superseded on 2026-10-04: byte/request limits by D14/D17, zero third-party by D16; see section I)* | Rules out Google Fonts/Maps/Analytics |
| D6 | Domains (revised) | .ir → Persian, .com → English on one docroot; host-aware rewrites; same slugs; hreflang pairs | Routing, canonicals and duplicate prevention |
| D7 | Persian typeface | Vazirmatn (free, OFL) unless you want to license a commercial face | Cost and look |
| D8 | Engineering tools in v1 | Designation decoder + basic L10 life calculator + unit/fit reference | Scope; anything more is post-launch |
| D9 | Pending products | Discoverable in the Product Explorer with a "Technical data pending / Unverified" badge; `noindex` and excluded from the XML sitemap until verified (section K) | Honesty vs. catalogue completeness |
| D10 | hreflang x-default | poladcharkhesh.com | Where non-Persian, non-English visitors and crawlers land |
| D11 | Admin host | poladcharkhesh.ir/admin only | One cookie domain, smaller attack surface |
| D12 | Platform minimums | Targets PHP 8.3+ and MariaDB 10.11+; code kept to a conservative baseline; actual minimums frozen after the host probe; Node 24 for local tooling only (section G, C3) | Security support through 2027 and beyond |
| D13 | Retention | 5 releases; backups 7 daily / 4 weekly / 3 monthly; weekly off-site download | Disk quota vs. how far back you can recover |

**Risks**

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Host's PHP or MariaDB older than the targets | Security exposure, or features unavailable | Code written to a conservative baseline (section G, C3); Phase 1 probe; ask the provider or cPanel MultiPHP for a supported PHP |
| Symlinks not allowed | Symlink release switch unavailable | Two-slot directory switch (section G, C2) needs only `rename()` |
| SSL not issued for a parked domain or its `www` | Browser warnings, lost trust | Verify AutoSSL for all four hostnames before launch |
| Shared-plan CPU/RAM limits hit | Throttled admin or failed Publish | Publish runs only on demand and takes seconds; no resident processes; browsing costs nothing |
| Verified technical data is scarce for many of the 68 products | Thin product pages, weak SEO | Provenance per value; pending badge; `noindex` and out of the sitemap until verified; prioritise the products customers ask about, using inquiry data |
| No real product photography | Weaker visuals | Direction 1 doesn't depend on photos; drawings generated from dimensions |
| Brand names used without authorisation | Legal/trust exposure | Brands shown only when you mark them verified; no "authorised distributor" language unless proven |
| Engineering tool results mistaken for certified calculations | Liability | Clear "indicative, per ISO 281 basic formula" labelling, inputs and assumptions shown, CTA to a specialist |
| Shared-host email deliverability for inquiry notifications | Missed leads | Every inquiry stored in MariaDB first; email is only a notification; dashboard badge |
| Scroll set-piece overruns effort | Schedule | Time-box; static drawing is an acceptable shipped state |
| Scope creep | Endless project | CORE / IMPORTANT / POST-LAUNCH labels in the backlog, freeze after QA |

**Information I need from you** (none of it will be invented): the old catalogue export or repo access for migration, logo files (SVG preferred), which domains and host plan you have, any verified brands/sources, email address and social links if they should appear, Thursday/Friday hours, and map coordinates if you want a map link.

### Final approval checklist (Gate 1 approved 4 Oct 2026)

Tick each item, or comment on it with a change. Phase 2 (design system) starts when Gate 1 is fully ticked; the hosting checks can finish during Phase 2, but must pass before Phase 3 (Foundation).

**Gate 1 — decisions**

- [x] D1 Architecture: PHP 8.3+ + MariaDB + static Publish with atomic releases (section G)
- [x] D2 Creative direction: Drawing Sheet + editorial type scale + concentric hero mark (section E)
- [x] D3 Scroll narrative: Drawing Set + Exploded Assembly (section F)
- [x] D4 Information architecture: search-first section order (section C)
- [x] D5 Performance budget: ≤ 120 KB / ≤ 8 requests first view, zero third-party requests (section I) *(approved at Gate 1; superseded on 2026-10-04 by owner decisions D14/D17 for the budget and D16 for third-party requests)*
- [x] D6 Domains: .ir Persian, .com English, host-aware routing on one docroot (section K)
- [x] D7 Typeface: Vazirmatn + JetBrains Mono
- [x] D8 v1 engineering tools: decoder, indicative L10, unit/fit reference
- [x] D9 Pending products discoverable in the explorer with a "Technical data pending / Unverified" badge; `noindex` and excluded from the sitemap until verified
- [x] D10 x-default = .com
- [x] D11 Admin only at poladcharkhesh.ir/admin
- [x] D12 Runtime: targets PHP 8.3+ and MariaDB 10.11+, conservative code baseline, minimums frozen after the probe; Node 24 local tooling only
- [x] D13 Retention: 5 releases; 7/4/3 backups; weekly off-site download

**Before Phase 3 — hosting and inputs**

- [ ] Exact domain names confirmed (assumed poladcharkhesh.ir and poladcharkhesh.com)
- [ ] Probe script run on the host; every "to verify" row in section N passes
- [ ] Provider's backup retention and restore procedure known
- [ ] Old catalogue export or repository access for migrating the 68 products
- [ ] Logo files (SVG preferred)
- [ ] Verified brands and data sources, if any
- [ ] Optional contact channels (email, socials), Thursday/Friday hours, map coordinates — or confirmation they stay empty

## Sources

- [SKF — 6205 deep groove ball bearing](https://www.skf.com/group/products/rolling-bearings/ball-bearings/deep-groove-ball-bearings/productid-6205)
- [NSK global site](https://www.nsk.com/)
- [Schaeffler Germany](https://www.schaeffler.de/en/)
- [Timken](https://www.timken.com/)
- [IKO International](https://www.ikont.com/)
- [WebKit — A guide to scroll-driven animations with just CSS](https://webkit.org/blog/17101/a-guide-to-scroll-driven-animations-with-just-css/)
- Iranian distributor sites ([khalijbearings.com](https://khalijbearings.com/), [tehran-ballbearings.ir](https://tehran-ballbearings.ir/), [bearingsmart.ir](https://bearingsmart.ir/ball-bearings/)) — search listings only; pages timed out from the research environment
- [Concept boards (this project)](https://claude.ai/artifact/H9uhLDuNiNsVmy31YebUim)
- [endoflife.date — MariaDB](https://endoflife.date/mariadb) and [MariaDB.org — 10.6 end of life](https://mariadb.org/mariadb-server-10-6-reaches-end-of-life-on-july-6th/)
- [endoflife.date — PHP](https://endoflife.date/php)
- [endoflife.date — Node.js](https://endoflife.date/nodejs)
- Hosting facts (MariaDB, Node versions, Park Domain, backups, SQLite advice): provider confirmation relayed by Mosi, 4 Oct 2026
