# PoladCharkhesh-Nova: Phase 3 Foundation Plan (revision 3, local-first)

Date: 2026-10-06 (revision 3 of the same day).

Status: **APPROVED (E-1, 2026-10-06); implementation in progress, local-first.**
- E-1, E-2, E-7 and E-8 are approved. Development is local-first in Docker (D25), and the host probe is deferred until hosting is purchased (D26).
- **Step 1: done and verified (2026-10-06).** Repository, AGENTS.md/CLAUDE.md, Docker dev stack and toolchain. The step 2 local baseline is recorded in `nova/docs/local-baseline.md`.
- Not yet built: schema, Admin, Publish Engine, public-site port, catalogue import.
- Gate 2 is approved, closed and frozen (`00 genesis summary.md`).
- A fresh session starts from `06 phase3 handoff checkpoint.md`.

## Revision 3: local-first checkpoint (owner, 2026-10-06; overrides revision 2 where they conflict)

- **Approved:**
  - **E-2:** the D20 model (§9, §25), refined. Uncertain manufacturer/product-code semantics are not frozen, and V-1–V-5 stay source-verification issues. Internal names such as "base-series match" may be refined if verified evidence requires it. Relationships are never inferred, and values never copied between designations, without evidence.
  - **E-7:** the SVG policy (§26).
  - **E-8:** the content model (§27).
- **Local-first (D25):**
  - All Phase 3+ development and QA run locally in Docker. Services: PHP 8.4 with Apache (so `.htaccess` behaves as in production), MariaDB 11.4 LTS and a local mail catcher. Composer and PHP tooling run in containers; Node 24 runs on the workstation for asset build and Playwright.
  - Nothing server-side is installed natively on Windows. The revision 2 Laragon/XAMPP fallback (§21) is withdrawn.
  - The first task records the exact image versions. Code targets that baseline, and the SQL stays within D12's conservative subset, which also runs on MariaDB 10.11 LTS.
  - Hosting will be bought later to meet these requirements; the architecture is not weakened for an unknown host (D19).
- **Probe deferred (D26):** it is not a precondition for any local work. After hosting is purchased and before production deployment, it is regenerated with a new key and run; its results freeze the production minimums and the release-switch mechanism (S or D).
- **Revision 2 text read in this light:**
  - §2/§3 and every "[probe]" marker apply to production only. The local baseline is fixed above.
  - §21: Docker uses the local baseline, not probed versions.
  - §23, criterion 1: becomes "local baseline recorded"; the production freeze becomes a pre-deployment criterion.
  - §23, criterion 2: the static checks run on the local baseline.
  - §23, criterion 13: the deployment dry run is local, against the production-like Apache container. The real-host dry run is deferred.
  - §24, step 0: only the start authorization remains.
  - §24, step 2: record the local baseline (in place of production minimums).
  - §24, step 8: both switch strategies are built and crash-tested locally; the choice between them is deferred.
  - §24, step 13: the `.htaccess` template supports both strategies.
  - §30–§32 and §34 are replaced below.
  - The host-dependent risks in §33 become pre-deployment items.
- **D14/D17 reconfirmed:** no byte, file or request budgets; no numeric CWV/performance gates; measurements for diagnosis and regression only; no arbitrary micro-optimisation.

## What changed in revision 2 (owner review, 2026-10-06)

- **Versions (D19).** PHP 8.3+ and MariaDB 10.11+ are preferred targets, not hard blockers until the probe results exist. The final minimums are recommended after the probe. The host adapts to the architecture: the owner will upgrade the plan or change host where there is a technical reason.
- **Product model (D20, Q-DM1 revised):**
  - The Product is the stable family/designation entity, with an explicit `base_listed`.
  - No variant ever stands for the whole Product. `/p/<product>` presents zero, one or many listed variants.
  - Each variant keeps its own designation, listing state, values, provenance, documents and inquiry identity.
  - Nothing is copied between designations, and no shared values are assumed.
  - There are seven match categories, and relationships that are not yet verified stay unresolved.
- **SVG (D21).** No blanket ban. A secure policy instead: Owner-only ingestion through an allow-list sanitizer, a trusted code-asset workflow, display through `<img>` only, and sandboxed serving (section 26).
- **Content (D22).** Three separated layers: structured technical data, constrained structured rich content for editorial text, and media/document references. No raw HTML and no page builder (section 27).
- **Backups (D23).** The scheduler comes first; a first-Admin-visit trigger is only a fallback. Six kinds of copy are distinguished, and restore must be proven (section 18).
- **Performance (D14/D17 reconfirmed).** No budgets of any kind and no numeric performance gates (section 28).
- **Tooling (D24).** Superdesign, Chrome DevTools, Playwright, security guidance and document tools are used where they materially improve the result (section 29).
- **Host probe.** Regenerated with a new access key that is stored in no file; the package is temporary (section 34).
- **Approval matrix.** E-1–E-22 are reclassified (section 31).

D15 governs every trade-off: correctness and data integrity first, then product and engineering usefulness, visual design and UX, discovery and conversion, Admin/CMS completeness, maintainability, security and reliability, accessibility, and practical performance last.

Markers used below:
- **[probe]** = settled by the host probe;
- **[verify]** = a bearing-domain fact that needs a source before it is coded (section 33);
- **E-n** = an approval item (section 31).

## 1. Final proposed production architecture

D1 stands unchanged. Visitors receive pre-built static files; the only PHP a visitor can reach is the inquiry endpoint; MariaDB is the single source of truth; a PHP Publish Engine compiles releases that switch atomically.

```
                 Visitors  (.ir → Persian, .com → English)
                              │  static files only: zero PHP, zero database queries
                              ▼
 public_html/   .htaccess ─ host routing · release switch · security and cache headers
   ├─ _site/  releases or slots / <release> / fa | en / …    pre-built HTML and JSON (Publish output)
   ├─ assets/   content-hashed CSS, JS, fonts                  shared by all releases
   ├─ media/    content-addressed uploads, PHP disabled        shared by all releases
   ├─ api/inquiry.php ─┐  the only PHP a visitor can reach (POST)
   └─ admin/index.php ─┤  Admin, on the .ir host only (D11)
                       ▼
 ~/app-releases/<version>/  PHP application: Admin, Publish Engine, inquiry intake, media pipeline,
                            backup/restore, importer, command line     ──►  MariaDB (source of truth)
 ~/app-current.php          pointer to the active app version
 ~/config/config.php        secrets (database, SMTP); never exported
 ~/storage/                 backups · archived releases · logs · sessions · temp · publish journal
```

**Application stack (E-3).** Plain PHP with no full-stack framework, plus a small set of vetted Composer libraries:
- **Runtime:** Twig 3 (templates for the Admin and the published pages, with auto-escaping) and PHPMailer (SMTP). The SVG sanitizer library proposed in section 26 joins them if E-7 is approved.
- **Development only:** PHPUnit, PHPStan, PHP-CS-Fixer, Playwright and axe-core.
- **Project code:** everything else: router, database layer, migrations runner, CSRF, sessions and sign-in, validation, rich-content renderer, publish pipeline.

The engineering tools (Phase 5) stay client-side modules loaded on demand, with no server part.

## 2. PHP/runtime compatibility strategy (D19)

**Preferred target:** PHP 8.3 or newer, ideally 8.4 or 8.5 for longer security support. Support windows (php.net; recheck when freezing):
- PHP 8.2: security fixes end 2026-12-31;
- PHP 8.3: 2027-12-31;
- PHP 8.4: 2028-12-31;
- PHP 8.5: 2029-12-31.

This is **not** a hard blocker until the probe results exist.

**After the probe**, Claude recommends the final supported minimum, weighing:
- the host's actual capability;
- the security-support lifecycle;
- compatibility;
- maintainability;
- what Nova needs.

The host adapts to the architecture. Nova's code is not degraded or complicated to fit an obsolete environment. If the host cannot offer a security-supported PHP, the recommendation is a plan upgrade or another host, not compatibility shims. The code's language level is then set to the frozen minimum.

**Extensions** [probe]:
- **Required:** pdo_mysql, mbstring, intl, fileinfo, zlib, openssl, json, ctype, session, filter, hash, and gd (with WebP) or imagick.
- **Recommended:** zip, curl, exif, sodium, dom/libxml (needed for SVG sanitising).

**Limits** [probe]: memory, execution time and upload/post sizes large enough for media processing, publishing and backups inside one request, or raisable per request. These are host-capability needs, not performance targets.

**Folder access** [probe]:
- Plan A keeps app/, config/ and storage/ outside the document root.
- Plan B, if open_basedir prevents that, keeps them in a folder inside the document root that .htaccess denies and an index guard protects. If plan B is needed, a host that allows plan A is preferred (D19).

**OPcache** must notice changed files, or be reset after a deploy [probe].

**Nothing builds on the server.** `vendor/` and built assets ship in the deploy package; Node 24 runs only on the workstation (D1).

**Time:** stored in UTC and displayed in Asia/Tehran. Solar Hijri dates come from intl's Persian calendar [probe], otherwise from a tested pure-PHP converter. The probe also checks that the time-zone data no longer applies Iranian daylight saving.

## 3. MariaDB strategy (D19)

**Preferred target:** MariaDB 10.11 LTS or newer. Support (mariadb.org; recheck when freezing):
- 10.6: ended 2026-07-06;
- 10.11: until 2028-02-16;
- 11.4: until 2029-05-29.

The final minimum is recommended after the probe, on the same host-adapts principle.

**Tables, connections and SQL:**
- InnoDB, utf8mb4 with an explicit utf8mb4_unicode_520_ci, and strict sql_mode on each connection.
- Foreign keys: RESTRICT for shared references, CASCADE only for owned children.
- Optimistic locking with row_version; every multi-row save in a transaction; times in UTC.
- D12's conservative SQL subset stays: no RETURNING, sequences, system-versioned tables, JSON functions, stored routines, triggers or events. Generated columns are allowed.

**Migrations:** forward-only numbered SQL files with a schema_migrations table, applied only after an automatic backup, and tested on a copy first.

**Database access:** exceptions on, native prepared statements, utf8mb4 in the DSN, one lazy connection per request, no persistent connections.

**Publish** reads one consistent snapshot and holds a named lock [probe checks both].

**One dedicated database and user for Nova**, with least privilege: data access plus the DDL that migrations need. Staging, if approved, gets its own database.

**Not done now.** The physical schema is written only after implementation is authorized; section 9 gives the logical model.

## 4. Application/folder structure

**Repository (E-15).** A git repository in this workspace, with production code under `nova/`. Governance documents and the frozen Phase 2 `design/` stay at the root. Temporary tooling such as the host-probe package stays out of production history.

```
nova/
  app/
    src/          Nova\Core          kernel, router, HTTP, database, config, errors
                  Nova\Security      CSRF, sessions, sign-in, roles/policy, rate limits
                  Nova\Catalogue     Family, Product, ProductVariant, Spelling, SpecKey, SpecValue, Source, Brand
                  Nova\Designation   Normaliser, Matcher, per-family suffix rules, relationship review
                  Nova\Provenance    value status, verification rules
                  Nova\Content       sections, company settings, SEO, rich-content schema and renderer
                  Nova\Media         raster/PDF pipeline, SVG ingestion (section 26), references
                  Nova\Inquiry, Nova\Backup, Nova\Import
                  Nova\Publish       Engine, steps, Manifest, Journal, ReleaseSwitcher\Symlink, ReleaseSwitcher\TwoSlot
                  Nova\Admin         controllers, forms, views
    templates/    public/ (Twig port of the Phase 2 page) · admin/ (Persian, RTL)
    i18n/         public strings fa/en · Admin strings fa
    migrations/   0001_….sql …
    bin/nova      command line: migrate, publish, backup, restore, import --dry-run, user:create-owner
  public_html/    docroot skeleton: .htaccess, admin/index.php, api/inquiry.php, assets/, media/, _site/
  assets-src/     CSS (Phase 2 tokens and components), JS (Phase 2 nova.js / ws.js adapted), fonts, SVG code assets
  tests/          unit/ · integration/ · e2e/ · fixtures/ (Phase 2 sample, labelled as sample)
  tools/          asset build, packaging
  docs/           AGENTS.md, CLAUDE.md, decision records, runbooks
```

**On the server:** `~/app-releases/<version>/` with the `~/app-current.php` pointer, then `~/config/`, `~/storage/{backups,releases,logs,sessions,tmp}` and `~/public_html/…`.

## 5. Public/static-output architecture

**Output per release and language:**
- the one-page site;
- one page per published Product (`/p/<product-slug>/`), presenting the Product and its listed items (section 25), with the content in the HTML so it works without JavaScript;
- the privacy and 404 pages;
- per-Product JSON for the workspace;
- the explorer rows in the HTML (crawlable);
- sitemap.xml and robots.txt per host;
- a manifest listing every file with its size and hash.

**Explorer.** One row per published Product. The designation cell shows the Product designation together with its listed designations; one variant is never shown as the Product. Search keys cover the Product, every listed and known variant, and every recorded spelling.

A d/D/B cell shows a value only when that value is sourced for every listed item in the row and is identical; otherwise the cell shows "varies" or "—". That is a fact derived from the sources, never an assumption.

**Templates and scripts:**
- The Phase 2 page is ported to Twig with the same markup, tokens and behaviour (frozen baseline).
- `nova.js`/`ws.js` are adapted to the production model: listed-item selection, the seven match categories, per-value provenance, rich-content output.
- ES modules, no framework. Content-hashed assets cached for good; HTML and JSON revalidated on every visit.

**Requested designations** travel as `?r=<requested>` on the Product URL (section 17).

**No budgets (D14/D17, section 28):**
- **Stylesheet (E-6):** one hashed external stylesheet instead of the prototype's inline-critical-CSS split. It is simpler, and it is not a byte-saving measure.
- **Fonts:** self-hosted (D16). Decision A (Latin text in JetBrains Mono on Persian pages) stays.
- **Compression:** whatever the host does well [probe].

**Media and assets live outside the releases**, so nothing a retained release references is ever deleted.

## 6. Admin architecture

**Rendering and access:** server-rendered PHP with Twig; Persian RTL interface only (decision C); plain JavaScript for small interactions; no single-page app; served only at `.ir/admin` (D11).

**Screens:**
- A1–A9 as frozen (G2-11), plus the deferred screens (Families, Sources, Brands, Documents, SEO overview, Users, Audit log, Backups, System → Updates), built from the same patterns.
- A1–A8 are desktop/tablet-first; A9 is phone triage.

**Product editor (D20):**
- the `base_listed` switch;
- a variants table: exact designation, listed, status, verification, values with provenance, documents;
- spellings, each with its target and source;
- a relationship-review state for designations whose base/suffix relationship is not yet verified.

**Content editors (D22):**
- structured technical fields for product data;
- a constrained structured editor for editorial text (section 27);
- media and documents chosen by reference.

**SVG (D21):** only the Owner can add SVG, through the sanitizing pipeline (section 26).

**Request flow:** session → sign-in → CSRF on every change → role policy → validation → transaction → audit event → redirect with a confirmation message.

**Data safety:**
- optimistic locking;
- confirmation before destructive or public actions;
- inline validation;
- empty and error states;
- an unsaved-change guard;
- drafts previewed through the publish templates at an Admin-only, noindex address.

**Accessibility:** Phase 2 Admin standards (13 px minimum labels, visible focus, keyboard operation, WCAG 2.2 AA contrast).

**Phase 3 builds** the Admin shell and one vertical slice: a Product with several variants, spec values with provenance, and one rich-content field. The full screens are Phase 4b, designed with Superdesign where useful (D24).

## 7. Draft → Publish architecture

**Working state vs published state.** The database holds the working state, and a release on disk is the published state. Each publish compiles the whole site from one consistent snapshot and stores a normalised JSON snapshot for the next diff (A7).

**Statuses:**
- Products and variants: draft, published or archived.
- Verification: pending or verified; set by the Owner only for indexing.
- "Show in Product Explorer": separate (D9/C1).
- `base_listed` and each variant's `listed` flag.

**Validation gate (A7, frozen, extended).** Blockers stop the run:
- ALT-INF;
- required fields per language;
- a broken media, document or internal-link reference, including references inside rich content;
- a duplicate slug or designation key;
- a sitemap URL that would be noindex;
- a verified page without sourced core values;
- a relationship claim (variant, spelling) that is not verified;
- rich content that fails its schema.

Warnings do not stop it: SEO lengths, optional translations. There is no size or request budget check (D14/D17).

**Steps:**
1. Lock.
2. Snapshot.
3. Validate.
4. Build into a fresh folder.
5. Verify: re-read the manifest; check that every referenced file exists; optionally fetch a private preview path [probe].
6. Switch (section 8).
7. Record the publish and the audit event.
8. Prune to 5 releases (D13).

A journal makes an interrupted publish finish or roll back on the next Admin request; no queue or worker is needed.

**Rollback** is an instant switch back to the previous release.

**Who:** the Owner and Editors can publish; only the Owner verifies for indexing (decision C).

## 8. Two-slot/shared-hosting-safe deployment strategy

**One `ReleaseSwitcher` interface, two strategies (C2).** Both are implemented and tested; the probe picks which one runs (E-20):
- **S, symlink swap.** `_site/current → releases/<id>`, switched by renaming a new link over `current`. Used only if PHP can create symlinks, the atomic swap works and the web server follows the links.
- **D, two slots.** `_site/slot-a|slot-b`, chosen per request by the `_site/ACTIVE_B` marker in .htaccess. Switching means creating or deleting the marker; a slot is reused only after a grace period of at least 2 minutes; the old release is archived; a journal covers crashes.

**Strategy D needs:** rewrite rules in .htaccess, file tests on every request (the probe times the switch), and the document root and storage on one file system.

**Never** rename the live folder.

**App code** deploys through versioned folders and an atomic pointer file (E-16).

**.htaccess** is generated from a tested template, tried on staging first, and the previous version kept for instant restore.

## 9. Product + ProductVariant model (D20)

This is the logical model; the SQL is written only after implementation is authorized.

| Entity | Purpose | Key fields | Integrity |
| --- | --- | --- | --- |
| Family | The 10 families | slug, names and definitions fa+en, drawing template, core spec keys | — |
| Product | The stable family/designation entity (e.g. 6305) | family, base designation, slug, `base_listed` (explicit, off by default), status, page verification, show in explorer, texts (rich content, section 27), SEO, row_version | unique slug; key in designation_key |
| ProductVariant | A distinct designation of the Product (e.g. 6305-2RS, 6305-ZZ, 6305-C3) | product, exact designation as displayed, suffix tokens, suffix kind, `listed`, status, verification, notes, row_version | created only for a verified relationship; key in designation_key |
| Spelling | An Owner-recorded alternative writing of one Product or variant | target (exactly one), spelling, source, note | key in designation_key |
| designation_key | One table for every normalised key | key (unique), owner type, owner id | no two records share a key |
| SpecKey | Controlled list of values per family | key, labels fa+en, unit, is_core, type | unique per family |
| SpecValue | One value of one designation, with provenance | product, variant (empty = the base designation itself), spec key, value, unit, source, status, checked date | unique per designation + key; "verified" needs a source and a checked date |
| Source | A citable reference | title, publisher, edition/year, URL and/or document | cannot be deleted while used |
| DocumentLink | A document attached to the Product or to one variant | document, Product or variant, language, role | — |
| Brand | Shown publicly only when verified | name, logo, verified | — |

The other entities:
- Media with reference tracking;
- Document;
- Section with rich-content fields;
- Company settings;
- Settings;
- Inquiry with its requested items;
- User;
- Audit event;
- Publish record;
- Backup record.

**Rules (D20):**
- A Product never implies that the plain item is supplied. Only `base_listed` says so.
- No variant becomes the representative identity of its Product. Pages, explorer rows, titles and structured data present the Product and its listed items as distinct designations.
- Every value belongs to exactly one designation: the base designation itself, or one variant.
- **No value is copied between Product and variant, or between variants.** Nothing assumes that two designations share dimensions, load ratings, speed ratings, lubrication requirements, clearances, tolerances, materials or anything else, unless a verified source establishes that relationship for those values.
- Each listed variant keeps its exact designation, listing state, sourced values, provenance, documents and inquiry identity.
- **Relationships stay unresolved until verified** [verify V-1, V-4]:
  - A designation becomes a variant only through an Owner-approved per-family suffix rule, or an Owner confirmation with a cited source.
  - Until then, an imported or entered designation (e.g. "22220 E") is stored as its own Product with a "relationship to be reviewed" flag. No series, related or variant claim is published for it, and the matcher infers none; partial text search still finds it.
  - Verifying the relationship later turns it into a variant of the right Product, and its old URL redirects (301) to the Product page.
  - Spellings follow the same rule; a brand equivalent also needs a source.

## 10. Designation normalisation and matching policy

**One specification, two implementations.** PHP (Admin, importer, publish index, inquiry) and JavaScript (explorer) both run one shared file of test vectors.

**Normalisation (from Phase 2):**
- Persian/Arabic digits → ASCII;
- ي/ك → ی/ک;
- invisible marks removed;
- lower case;
- × → x;
- spaces, hyphens, underscores and dots removed.

**"/" stays significant until verified [verify V-3, E-19].** Dropping it would turn bore codes such as 63/22 into 6322, a different bearing.

**Match categories** (D20), evaluated against the published catalogue and **verified relationships only**:

| Owner category | Type recorded | Condition | Public behaviour |
| --- | --- | --- | --- |
| Exact listed Product | `exact_product` | requested key = the Product's key, and `base_listed` is on | `/p/<product>` with the base item selected; no qualifier |
| Exact listed ProductVariant | `exact_variant` | requested key = a variant's key, and that variant is listed | `/p/<product>` with that variant selected; no qualifier |
| Known spelling / alias | `via = spelling`, plus the type of its target | requested key = an Owner-recorded spelling | resolved to its target; "requested X — the catalogue writes it as Y" |
| Base-series relationship | `base_series` | requested = a Product key plus suffix tokens an Owner-approved family rule recognises, with no variant record matching | "Base series match — requested variant requires confirmation"; the series' listed items are shown |
| Related match | `related` | requested key = the Product's key, and `base_listed` is off | "Related match — requested designation requires confirmation"; names the listed variants, or says none is listed now |
| Unlisted requested variant | `unlisted_variant` | requested key = a known variant whose `listed` is off | "Known variant, not currently listed — availability requires confirmation" |
| Unknown designation | `none` | none of the above, including any relationship not yet verified, or more than one candidate | "Not in catalogue"; the exact code goes to a specialist |

**Precedence:** exact (Product or variant, directly or through a spelling) › unlisted variant › related › base series › none.

The explorer's partial-text results ("closest results") remain a separate display mode, as in Phase 2.

**Suffix rules.** Suffix tokens come from per-family lists the Owner approves. Unknown tokens are never guessed [verify V-1]. Brand notations (2RS / 2RS1 / 2RSH / DDU / 2RSR / LLU; ZZ / 2Z) stay distinct designations unless the Owner records an equivalence with a source [verify V-4].

**The requested designation is preserved verbatim** (trimmed and length-limited, never rewritten) through:
- the Product Explorer;
- the Product Workspace;
- deep links (`?r=`);
- the inquiry form;
- the WhatsApp text;
- the server-side inquiry record.

**Inquiry matching (E-21).** The inquiry endpoint recomputes the match against the live release and stores:
- the requested text and its normalised key;
- the match type and whether it came through a spelling;
- the Product and the variant;
- the release.

## 11. Per-value provenance architecture

**Values:**
- Every value has a status, a source and a checked date, and it belongs to one designation.
- A value can be verified only with a citable source (URL or document) and a checked date.
- Anything else is pending: shown as pending, never hidden and never guessed.

**Sources** are reusable records. Each page numbers the sources it uses (A, B, …) at publish time, as in the frozen UX.

**Page verification for indexing** is Owner-only. It needs:
- the Product's designation and family confirmed;
- the core values of every listed item shown on the page sourced and verified [verify V-6: core values for housings, units, seals, needle and thrust bearings].

**Verification reset (E-9).** Editing a core value or its source returns the page to pending until the Owner verifies it again.

**Manufacturer-specific values** (ratings, speeds, mass) are shown only once the supplied brand is verified (frozen rule) [verify V-8].

**Audit.** Every value change is audited: who, when, before and after.

## 12. Media/document architecture

Genesis §J and A4 as frozen, with D14 applied: sensible handling, no budget targets.

**Raster images and PDFs:**
- validated by content;
- named by content hash;
- EXIF removed, orientation fixed;
- responsive WebP derivatives written, with a fallback [probe];
- dimensions recorded.

**Storage:** under `public_html/media/yyyy/`, with PHP disabled and no folder listing, outside the releases.

**Lifecycle:** references are tracked; deleting is blocked while referenced; deleted files go to a 30-day trash; replacing a file in place keeps its references.

**Alt text** in fa and en, with an informative or decorative role. ALT-INF blocks the affected content (frozen G2-15).

**Documents** attach to a Product or to one variant (DocumentLink), per language, with the size shown before download.

**Upload limits** are safety caps against abuse, configurable in settings. They are not performance budgets.

**SVG:** section 26.

## 13. Inquiry architecture

**Endpoint.** One public PHP endpoint, a same-origin POST on both hosts. It works without JavaScript and with it.

**Order of work:**
1. Validate.
2. Check for abuse: origin check, honeypot, minimum fill time, and a per-IP rate limit using a salted hash kept 24 hours.
3. Store in one transaction.
4. Recompute the designation match (section 10).
5. Notify by SMTP.
6. Respond.

A failed email never loses an inquiry: the dashboard badge and a Retry action cover it.

**Stored with each requested item:**
- the requested text verbatim and its normalised key;
- the match type and whether it came through a spelling;
- the Product and the variant (the listed item the visitor chose or that matched);
- the release.

**Inquiry identity.** A CTA started from a listed variant carries that variant's exact designation. A CTA started from a request carries the requested designation and its match context.

**Policy:**
- Inquiries are never published, and follow the status workflow new → in progress → answered → closed.
- The notification address is an Admin setting (decision D).
- No visitor tracking (C4).
- Retention follows E-12.

## 14. Authentication, authorization and Owner role

**Users:** password hashing with Argon2id where available, else bcrypt [probe]; role Owner or Editor; lockout counters.

**Roles:**
- **Owner only:**
  - verification for indexing (decision C);
  - users and roles;
  - backups and restore;
  - System → Updates;
  - security settings;
  - the notification address;
  - SVG ingestion (D21).
- **Owner and Editor:** content, company and contact details (audited), raster media, documents, inquiries, publishing.

**Sessions:** stored in Nova's own folder; cookies Secure, HttpOnly and SameSite=Strict, with strict mode; a new session ID at sign-in; idle and absolute timeouts.

**Sign-in protection:** throttling with back-off, generic errors, audited sign-in events, and two-factor sign-in (TOTP) for the Owner (E-10).

**Recovery:** emailed reset links once SMTP works, plus a documented command-line recovery. The first Owner is created once from the command line, or with a one-time install token.

## 15. Security baseline

**Code:**
- Output escaped by default (Twig).
- The rich-content renderer emits only allow-listed elements (section 27).
- No inline event handlers.
- SQL only through prepared statements.
- CSRF tokens on every Admin change, plus an origin check on the public endpoint.

**Headers** from .htaccess [probe]: CSP (own origin only, no inline script), nosniff, Referrer-Policy, Permissions-Policy, framing refused, and HSTS once HTTPS is stable on all four hostnames.

**SVG:** sanitized at ingestion, shown only through `<img>`, and served with a sandbox CSP and nosniff (section 26).

**Files:**
- secrets outside the document root;
- uploads never executable;
- internal folders and dotfiles unreachable;
- errors logged privately, with generic error pages.

**Dependencies (D16):** few, pinned, and checked with `composer audit` before each release.

**Backups** containing personal data are encrypted for off-site copies (E-11).

**Security guidance** (D24) is used while authentication, sessions, uploads, SVG, the inquiry endpoint, backups, publishing and deployment are being built, not only at the end. A review checklist runs before Gate 3 and Gate 4.

## 16. Bilingual/RTL/LTR architecture

**Content:**
- Paired fa/en fields; rich-content fields hold one document per language (section 27).
- No machine translation; missing translations are flagged.
- A page is built in a language only when its required fields exist in that language.

**Rendering:**
- One template set for both directions; `<html lang dir>` per release; logical CSS properties.
- Drawings, codes, units, phone numbers and the logo are never mirrored.
- Codes are bidi-isolated, including a designation mark inside rich content.
- Latin text in JetBrains Mono, with longer English in `.en-prose` (decision A).
- Latin digits for codes and phone numbers (Q2); Solar Hijri dates on the Persian site.

**Admin:** Persian RTL, editing both languages side by side (A5).

## 17. SEO, canonical URLs and deep links (D20)

**Domains:** host routing, canonical URLs per host, hreflang pairs with x-default on .com, 301 redirects (www → apex, http → https), noindex on the server IP and temporary cPanel addresses, and the /en/ fallback (D6, D10).

**Product pages:**
- `/p/<product-slug>/` is the only canonical page for a Product. It presents the base (if listed) and all listed variants as distinct items.
- Its title and description name the Product and its listed designations (e.g. "6305 — 6305-2RS, 6305-ZZ"), so a search for a variant's code finds it. No single variant is presented as the Product.

**Requested designations:** `/p/<product-slug>/?r=<requested>` carries the request verbatim:
- an exact listed variant is selected;
- otherwise the match note from section 10 is shown;
- the canonical URL stays the Product page, and the query never creates an indexable URL.

**In-page anchors** (`#base`, `#v-<variant-slug>`) let the explorer link straight to a listed item without creating new URLs.

**Variant pages (part of E-2).** None in v1. Each listed variant already has its own values, documents and identity on the Product page. Separate variant pages would multiply near-duplicate URLs, so they are revisited after launch, using search data.

**Indexing rules:**
- Pending pages stay discoverable but noindex (D9/C1).
- A Product with zero listed items may be published for reference, but is noindex by default (part of E-2).
- Unlisted and unknown requests never get pages; spellings never create URLs.

**Structured data** (verified facts only): Organization/LocalBusiness; for products, a ProductGroup with `hasVariant` for listed variants. No Offer, ever.

**Archived pages** return 410, or 301 to a named successor (E-13); old-site URLs get a 301 map.

## 18. Backup/restore strategy (D23)

The layered strategy of Genesis §N and D13 is preserved, with each kind of copy kept distinct:

| Kind | What it contains | Where | When | What it restores |
| --- | --- | --- | --- | --- |
| Release rollback copies | The last 5 published releases (static HTML/JSON) | `_site` / `storage/releases` | every publish | The public site only, instantly; not CMS data |
| Database backup | PHP-written logical dump from a consistent snapshot: schema and data, gzip, checksum, schema version | `storage/backups` | daily by scheduler; before every migration and restore; on demand | All CMS data: products, variants, values, provenance, content, inquiries, users |
| Media and document backup | Uploaded files (content-addressed) plus a manifest | `storage/backups` | incremental with each database backup; full archive weekly or monthly | Media library and documents |
| Application (code) backup | Versioned deploy packages, git history, the last app versions on the server | workstation and repository; `~/app-releases` | every deploy | Code, by pointer switch or redeploy |
| Configuration | Secrets are never in backups; the Owner keeps them in a password manager | — | on change | A fresh `config.php` on restore |
| Hosting-provider backup | Account snapshots | provider | provider's schedule | Disaster recovery through the provider [input: retention, download, restore procedure] |
| Off-site copy | The latest database backup and media archive, encrypted (E-11) | Owner's storage | weekly (database), monthly (media), with dashboard reminders | Recovery on any host |

**Scheduling:**
- A scheduler (cron) is preferred and is used whenever the host provides one: daily backup, log rotation and reminders.
- The first-Admin-request-of-the-day trigger exists only as a fallback, when no reliable scheduler is available. Backups never depend solely on someone signing in when a scheduler exists.
- The dashboard always shows the last successful backup, and warns when one is overdue.

**Restore must be proven.** A backup that has never been restored is not considered working.
- **Phase 3:** automated tests that restore a database backup into an empty database and compare the data, and that restore media from a manifest.
- **Phase 7:** a full restore drill on staging (database, media, configuration, republish) as an exit criterion.
- **After launch:** a periodic test restore from an off-site copy (recommended quarterly).

## 19. Existing catalogue migration strategy (boundary reconfirmed)

Nothing is migrated now. The old project (github.com/mosish/polad-charkhesh) or a verified export becomes an input later.

**Before any import, the audit identifies:**
- canonical product records;
- Product versus ProductVariant relationships, proposed only and left unresolved until verified;
- trustworthy provenance to keep;
- missing provenance;
- conflicting values;
- duplicates and near-duplicates;
- aliases and spellings;
- unverified technical data.

**Rules:**
- Imported technical data is never blanket-marked as verified.
- Missing values are never invented.
- Ambiguous codes are never normalised on assumptions.
- Products land as drafts, and unsourced values as pending.

**The importer must support:**
- a dry run;
- a reconciliation report: created, updated, skipped, conflicts, unsourced values, relationships to review, duplicates, images needing alt text;
- repeatability (idempotent, keyed on normalised designations);
- rollback and safe retry (each run in a transaction or batch with a pre-run backup);
- source traceability (every imported value records its origin field and file).

**Timing (E-18):** dry runs once implementation is authorized; the production import late in Phase 4, after the Admin review screens exist, followed by the Owner's review.

## 20. Testing strategy

There are no performance gates (D17). Measurements are diagnostics; regressions are judged by what users see.

| Layer | What | Tool |
| --- | --- | --- |
| Unit | Normaliser and the seven match categories (vectors shared with JS); relationship-review rules; provenance and verification rules; rich-content schema and renderer (escaping, allow-list); SVG sanitizer against a corpus of attack samples; publish steps; both switch strategies, with a crash injected at every journal step; backup round-trip | PHPUnit |
| Integration | Migrations from empty; constraints; Publish end to end against the frozen MariaDB version | PHPUnit + MariaDB |
| Static analysis | Strict PHPStan; lint on the minimum and maximum PHP versions | PHPStan, PHP CLI |
| JavaScript | Client normaliser and matcher against the same vectors | node:test |
| End to end (D24) | Progressive coverage: Persian/English, RTL/LTR, normalisation, search, Product and variant matching, exact designation preservation, workspace with 0/1/many listed items, deep links, inquiry and WhatsApp context, sign-in, roles, Product and variant editing, Media Library, content editing, Draft → Publish, failed publish, rollback, responsive, loading, failure, validation and confirmation states | Playwright |
| Runtime inspection (D24) | Console and runtime errors, network behaviour, layout and overflow, states, forms, runtime accessibility, user-visible regressions | Chrome DevTools MCP; claude-in-chrome where interactive inspection helps |
| Accessibility | axe; manual keyboard pass; one screen-reader session before Gate 3 | axe-core, NVDA/VoiceOver |
| Output checks | Manifest, sitemap/robots/noindex consistency, hreflang, structured data, internal links | Publish checks + tests |
| Security | Role matrix, CSRF, headers, upload and SVG rejection, rate limits | PHPUnit + Playwright |
| Migration | Importer idempotency, reconciliation snapshots, safe retry | PHPUnit |
| Real devices | Android Chrome, iPhone Safari, desktop browsers before Gate 3 | manual |
| Performance (diagnostic) | Traces per release compared with the previous one; no thresholds | Playwright / Lighthouse |

Passing automated tests does not prove the visual experience is right, and visual inspection does not replace regression tests. Both are used.

## 21. Development environment/tooling

**Environment (E-14).** Docker Desktop (installed, not running) with containers at the PHP and MariaDB versions frozen after the probe, behind Apache with .htaccess. It is started only after implementation is authorized. Fallback: Laragon or XAMPP.

**Tools:** Composer, PHP CLI (minimum and maximum versions), PHPUnit, PHPStan, PHP-CS-Fixer, Node 24 (asset build, Playwright, axe), git. Design and inspection tools follow D24 (section 29).

**AGENTS.md and CLAUDE.md** hold the frozen baseline, D1–D24, the probe results, the commands and the conventions.

**Fixtures:** the 13 Phase 2 sample products, labelled as samples; never production data.

## 22. Deployment workflow

1. **Build locally:** tests green, then a versioned package with checksums.
2. **Upload** through File Manager or SFTP, and extract to `~/app-releases/<version>/`.
3. **Switch** the pointer atomically (OPcache handled).
4. **Update:** System → Updates runs a backup, then migrations, then a publish.
5. **Smoke checks** on every hostname.
6. **Rollback:** pointer back; restore the pre-migration backup if a migration ran.

**First install:** the Owner creates the database and user, then a one-time installer runs (migrations, first Owner, settings), then a publish, then DNS and TLS checks.

**Staging (E-17):** a password-protected, noindex subdomain with its own database.

## 23. Phase 3 acceptance criteria

Phase 3 is complete when all of these are demonstrated, with evidence for an owner review before 4a/4b start (E-22):
1. **Probe and versions:** results recorded; minimum versions frozen (D19); release-switch strategy chosen on evidence; all written into AGENTS.md and CLAUDE.md.
2. **Tooling:** repository, tooling and development environment reproducible; tests and static analysis green on the minimum and maximum PHP versions.
3. **Schema:** migrations and schema v1 (section 9) applied to the frozen MariaDB version; integrity tests pass.
4. **Normaliser and matcher:** PHP and JavaScript pass the shared vectors:
   - the seven categories;
   - spellings;
   - the Phase 2 cases, including T-1 and the 6305/22220 pairs;
   - Persian digits;
   - verified slash rules;
   - designations whose relationship is unresolved (no inferred claims).
5. **Publish Engine:** runs end to end on the production-like stack, including the validation blockers, both switch strategies with crash recovery, rollback and pruning.
6. **Sign-in:** sign-in, sessions, CSRF, the Owner/Editor policy with a test for every Owner-only action, lockout, first-Owner setup.
7. **Working slice:**
   - in the Admin shell, edit one Product with zero, then one, then several listed variants, each with its own sourced values and documents, plus one rich-content field;
   - publish;
   - the static page in both languages shows each listed item distinctly, with correct provenance and correct match behaviour for every category;
   - no variant is presented as the Product.
8. **Inquiry endpoint:** stored first, matched on the server with the requested designation verbatim, rate-limited, with failure-safe notification.
9. **Backups:** database and media restore proven by automated round-trips, and the rotation logic tested.
10. **Media core:** raster pipeline and references. The SVG ingestion pipeline with its attack-sample tests, if E-7 is approved.
11. **Security baseline:** headers, .htaccess protections and error handling in place; automated checks pass.
12. **Importer:** a dry run on the old catalogue, if provided, with a reconciliation report; nothing imported.
13. **Deployment dry run** on staging or a temporary location on the host.
14. **Documentation:** AGENTS.md and CLAUDE.md, decision records, runbooks.

No size, transfer, request-count or numeric speed criterion is part of acceptance (D14/D17).

## 24. Proposed implementation sequence

| Step | Work | Depends on |
| --- | --- | --- |
| 0 | Probe run and results; remaining approvals (section 32); explicit authorization | owner |
| 1 | Repository, tooling, AGENTS.md/CLAUDE.md; start Docker at the frozen versions | 0 |
| 2 | Freeze minimum versions and the switch strategy; record them | probe results |
| 3 | Designation test corpus, then the normaliser and matcher (seven categories) | verified suffix and slash rules, or explicit "unresolved" handling |
| 4 | Migrations runner, schema v1, fixtures | 1, 2 |
| 5 | Catalogue, relationship-review and provenance domain | 3, 4 |
| 6 | Rich-content schema and renderer | 4 |
| 7 | Port the Phase 2 templates and JavaScript to the production model (listed-item presentation designed with Superdesign) | 5, 6 |
| 8 | Publish Engine, ReleaseSwitcher (S and D), journal, rollback, prune | 4, 7 |
| 9 | Sign-in, Admin shell, the Product/variant vertical slice, audit | 4, 5, 6 |
| 10 | Inquiry endpoint | 3, 4 |
| 11 | Media core and SVG ingestion (if E-7 is approved) | 4 |
| 12 | Backup/restore with scheduler and fallback entry points; restore tests | 4 |
| 13 | .htaccess template: routing, switch, headers, SVG serving, redirects | 2, 8 |
| 14 | Importer dry run and reconciliation report | 3, 5, old catalogue |
| 15 | Deployment dry run | 8–13 |
| 16 | Phase 3 review package | all |

## 25. Q-DM1: the Product / ProductVariant model (revised, D20)

**The Product is the stable family/designation entity** (e.g. 6305). It owns the canonical page `/p/6305` and groups its variants (e.g. 6305-2RS, 6305-ZZ, 6305-C3).

**`base_listed`** (explicit; off by default) is the only thing that says the plain, unsuffixed item is a listed catalogue item. A Product never implies it otherwise.

**Each ProductVariant** is a distinct designation with:
- its exact designation;
- its own listing state (`listed`);
- its own sourced values and provenance;
- its own documents;
- its own inquiry identity.

**No variant becomes the representative identity of the Product.**

**Example.** The catalogue currently contains only 6305-2RS:
- Product 6305, `base_listed` off.
- ProductVariant 6305-2RS, `listed` on, carrying the values its source gives for 6305-2RS.
- `/p/6305` presents "6305" (family and designation) with one listed item, 6305-2RS. It does not present 6305-2RS as the Product, and does not suggest the plain 6305 is supplied.
- If 6305-ZZ is listed later, it appears as a second listed item on the same page, and the URL stays the same.

**Zero, one or many listed items.** The page handles all three:
- zero: a clear statement plus an inquiry CTA for the requested designation or the Product;
- one or many: each item has its own selection, readout, provenance, documents and CTA.

**Base values.** Values sourced for the base designation itself are shown labelled as the base designation's values, and never as a variant's.

**No copying, no assumed sharing.** Values are never copied between Product and variant or between variants. Nothing about dimensions, ratings, speeds, lubrication, clearance, tolerances or materials is assumed to be shared, unless a verified source establishes it.

**Matching** follows the seven categories in section 10. The requested designation is preserved verbatim everywhere listed there.

**URLs** follow section 17:
- one canonical Product page;
- `?r=` for requests;
- anchors for listed items;
- no variant pages in v1.

**Relationships to verify.** A suffix, prefix or variant relationship that needs manufacturer or domain verification stays explicitly unresolved: the designation is stored as its own Product, flagged for review (section 9).

**For owner approval (E-2), only the details added here:**
- the precise match-type definitions and precedence (section 10);
- the zero-listed-item behaviour (published only by choice, noindex by default);
- no per-variant pages in v1;
- standalone Products for unresolved relationships, with a 301 when they are resolved later.

## 26. SVG policy (D21; proposal E-7)

**Where SVG comes from:**
1. **Trusted code assets.** Design-system symbols, family glyphs, drawing templates, and the logo once supplied. A developer reviews them and keeps them in the codebase; they are rendered inline as part of the templates.
2. **Generated drawings.** Section drawings that Publish builds from sourced dimensions. These are code-generated, contain no user markup, and are rendered inline.
3. **Uploaded SVG.** Technical drawings, engineering diagrams, dimension illustrations, brand logos and trusted industrial illustrations. **Only the Owner can upload them**, and only through the sanitizer. Editors cannot upload SVG: they upload raster images or PDFs, or ask the Owner. Untrusted SVG never enters the normal upload pipeline.

**Ingestion pipeline (Owner upload):**
1. **Check the file.**
   - The extension must be `.svg` and the content must be XML with an `<svg>` root in the SVG namespace. Nova assigns the type `image/svg+xml` itself. Compressed `.svgz` is refused.
   - A configurable size and complexity cap applies. It is a safety limit, not a performance budget.
2. **Parse safely.**
   - Any DOCTYPE or ENTITY declaration is refused (this blocks external entities and entity expansion).
   - The parser has no network access, and depth and node counts are limited.
3. **Rebuild from an allow-list.**
   - Only allowed SVG elements and presentation attributes are kept.
   - Removed: script, foreignObject, iframe/embed/object, animation elements (`animate`, `set`), every `on*` handler, `<style>` blocks, `<image>`, comments and processing instructions.
   - `href`/`xlink:href` may only point to fragments inside the same file (`#id`). Every external URL, `javascript:` or `data:` reference, and `url()` to anything but `#id`, is removed.
   - The file is re-serialised from the cleaned tree; the original bytes are never published.
4. **Report and store.**
   - The Owner sees what was removed and fixes the source file if the drawing changed.
   - The cleaned file is stored content-addressed in the media library, with references and alt text (ALT-INF applies).

**Library.** A maintained allow-list SVG sanitizer (candidate: enshrined/svg-sanitize), wrapped in Nova's stricter rules above and tested against a corpus of attack samples. Security-critical parsing is better done by a widely used library than from scratch (D16 justification).

**Serving:** `media/*.svg` is served with:
- `Content-Type: image/svg+xml`;
- `X-Content-Type-Options: nosniff`;
- `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`, so the file cannot run anything, even when opened directly.

The probe checks the server's SVG type and whether .htaccess can attach this policy.

**Rendering:**
- Uploaded SVG appears only through `<img src>` (or as a CSS background), where browsers never run scripts or load external resources.
- It is never inlined into HTML, and never used through `<object>`, `<embed>` or `<iframe>`.
- Admin previews use `<img>` too.

**Transformation:** not required. Optional raster copies (e.g. for social previews) only if the host has an SVG renderer [probe: Imagick SVG], and not in v1.

**Why this level of effort.** It is the simplest arrangement that keeps SVG's value: Owner-only intake, one well-tested sanitizer, `<img>`-only display and a sandbox header. It needs no custom SVG processing system.

## 27. CMS content model (D22; proposal E-8)

Three layers, kept separate:

**1. Structured technical and domain data.** Typed fields only: designations, families, spec values with units and provenance, sources, brands, and company facts (address, phone numbers, hours) as structured settings. No rich text here.

An editorial table must never be used to publish product specifications. Specifications come only from SpecValues with provenance.

**2. Constrained structured rich content.** Used for About and company sections, engineering explanations, applications and industry content, technical educational content, selected section introductions, and Product descriptions and application notes.

- **Format.** A Nova JSON block document per field per language, validated against a schema on every save and rendered by Nova's own renderer at publish.
  - **Blocks:**
    - paragraph;
    - heading (at most two levels, below the section heading);
    - ordered list and unordered list (one nesting level);
    - table (caption, header row, plain cells), for editorial and educational tables only;
    - figure (a media reference with per-language alt text and caption; ALT-INF applies);
    - document link (a document reference).
  - **Inline marks:**
    - emphasis;
    - strong emphasis;
    - link: an internal reference to a Product, section or document, resolved per language and host at publish, or an external https URL;
    - designation/code: rendered as an isolated left-to-right code in JetBrains Mono, which applies decision A automatically.
- **Profiles.** Each field allows one subset:
  - *inline:* marks only;
  - *basic:* paragraphs, lists and marks;
  - *article:* adds headings, tables, figures and document links.
- **Never allowed:** raw HTML (any HTML typed in is shown as text), inline styles, colours, fonts, scripts, embeds and iframes, or page-builder layouts.
- **Editor (Admin).**
  - A structured block editor: blocks are added, moved and removed as form rows.
  - Text blocks are textareas (right-to-left for Persian) using a tiny inline syntax (`**strong**`, `*emphasis*`, `[text](link)`, `` `code` ``), with toolbar buttons that insert it.
  - A live preview runs through the same renderer, with Persian and English side by side (A5).
  - If editors later need a richer feel, a schema-enforcing editor (for example Tiptap/ProseMirror) can replace the textareas without changing the stored format or the renderer, because both belong to Nova.
- **Rendering.**
  - Text is escaped and only allow-listed elements are output, with design-system classes.
  - External links get `rel="noopener noreferrer"`.
  - Heading levels follow from the context.
  - Tables get a caption and header scope.
  - Direction comes from the release.
  - A broken internal reference blocks publishing.

**3. Media and document references.** By id only (figure blocks, document links, Product and variant documents), never raw upload URLs. Usage is tracked, and ALT-INF applies.

**Why this model.** It is the simplest structure that gives editors headings, lists, links, emphasis and tables, while keeping the output predictable, accessible, bilingual and safe.

## 28. Performance policy (D14/D17, reconfirmed 2026-10-06)

**There is none of the following:**
- a file-size budget;
- a transfer-size budget;
- a request-count budget;
- a JavaScript, CSS or image KB budget;
- an LCP, INP or CLS hard target;
- any replacement numeric performance gate.

No substitute numeric limits are introduced. No Phase 3 time goes into micro-optimising file size. Visual quality, engineering usefulness, functionality, CMS capability, maintainability, development speed and architecture quality are never traded to save KB or requests.

**Performance remains practical hygiene and regression monitoring.** Avoid obvious waste:
- huge unoptimised media;
- accidental duplicate dependencies;
- pathological queries;
- blocking operations;
- serious runtime regressions;
- unnecessary third-party dependencies (D16);
- clearly poor responsiveness.

Measurements are diagnostic signals, compared release to release, and never pass/fail gates.

## 29. Tooling policy (D24, for Phase 3 and later)

**General rule.** Tools are used because they materially improve correctness, UX quality, security, reliability, maintainability or verification, never just because they are installed. No approved Gate is reopened because a tool proposes a different solution.

- **Design / UI:** Superdesign for genuinely new production surfaces:
  - Admin dashboard, Product editor, ProductVariant interfaces, Media Library, section/content editor, inquiry manager, Publish interface, settings;
  - major new public components and complex interactive components.

  It is used for exploration, alternatives, comparison, critique and refinement. It is not used to reopen the frozen Gate 2 visual direction without a concrete production reason; the Gate 2 design system remains the baseline.
- **Browser review:** Chrome DevTools MCP, used proactively during implementation for console and runtime errors, network, layout and overflow, loading and error states, forms, runtime accessibility and user-visible regressions. claude-in-chrome where interactive inspection helps. Never rely on source inspection alone when a browser can show actual runtime behaviour.
- **Automated QA:** Playwright, building up regression coverage of the flows listed in section 20.
- **Security:** security guidance (the security-review / Claude Security skills or an installed security-guidance plugin), used while security-sensitive parts are designed and built (section 15).
- **Documents and data:** PDF, XLSX and document tools only for real work: manufacturer datasheets, catalogue migration, product spreadsheets, reconciliation reports, verified source extraction.
- **Production UI quality loop, applied in proportion to risk:**
  1. plan/design;
  2. Superdesign where useful;
  3. implementation;
  4. Chrome DevTools inspection;
  5. Playwright regression;
  6. visual critique;
  7. responsive, RTL/LTR and accessibility review;
  8. security review where applicable;
  9. refinement;
  10. owner review.

## 30. What the owner returns (revision 3, local-first)

**Before local implementation starts:** the explicit start authorization (E-1), given on 2026-10-06.

**During implementation, when the related step is reached:**
1. Old catalogue: repository access or a verified export, plus the old site's URL list. Needed for the importer dry run (§24 step 14).
2. Bearing-domain sources for V-1–V-8, or approval to research them from manufacturer catalogues with every source cited. Needed before suffix and slash rules are frozen; until then those relationships stay unresolved.
3. The Owner's email and the number of Editors. Local test accounts are used meanwhile.

**Before production deployment** (after hosting is purchased):
1. Run the regenerated probe and return the JSON report plus the control-panel answers.
2. Domains and parking.
3. Staging subdomain and database.
4. SMTP mailbox, SPF/DKIM, and the notification address (decision D). Locally a mail catcher is used.
5. Provider backup details.

**Before launch:** logo SVG (through the trusted-asset workflow), hours, map coordinates, optional channels, Search Console and Bing.

## 31. Approval matrix (E-1–E-22, revision 3)

| ID | Decision | Status | Blocks local Phase 3 |
| --- | --- | --- | --- |
| E-1 | Phase 3 plan (revision 3) | APPROVED: local implementation authorized 2026-10-06 | NO |
| E-2 | Q-DM1 Product/ProductVariant model (D20, refined; V-1–V-5 not frozen) | APPROVED | NO |
| E-3 | Plain PHP + Twig + PHPMailer (+ SVG sanitizer per E-7) | APPROVED | NO |
| E-4 | PHP | APPROVED: local baseline 8.4. Production minimum DEFERRED to the hosting probe; the host must meet the baseline | NO |
| E-5 | MariaDB | APPROVED: local baseline 11.4 LTS. Production minimum DEFERRED to the hosting probe (the SQL subset also runs on 10.11 LTS) | NO |
| E-6 | One hashed stylesheet | APPROVED | NO |
| E-7 | Secure SVG policy | APPROVED | NO |
| E-8 | Three-layer content model | APPROVED | NO |
| E-9 | Verification reset on core edits | APPROVED | NO |
| E-10 | Owner TOTP | APPROVED | NO |
| E-11 | Encrypted off-site backups | APPROVED: built and tested locally with the container's ZIP/OpenSSL; production capability check DEFERRED | NO |
| E-12 | Inquiry retention 24 months | APPROVED | NO |
| E-13 | Archived URLs: 410, or 301 to a successor | APPROVED | NO |
| E-14 | Docker development environment | APPROVED: local-first (D25), no native Windows fallback | NO |
| E-15 | Git repository, code under `nova/` | APPROVED (probe package excluded) | NO |
| E-16 | Versioned app folders + atomic pointer | APPROVED design, tested locally; production OPcache/rename check DEFERRED | NO |
| E-17 | Staging subdomain | DEFERRED to hosting | NO |
| E-18 | Production import late in Phase 4 | APPROVED | NO |
| E-19 | "/" significant until verified | APPROVED | NO |
| E-20 | Release switch | APPROVED: strategies S and D both built and crash-tested locally; selection DEFERRED to the hosting probe | NO |
| E-21 | Server-side inquiry matching | APPROVED | NO |
| E-22 | Phase 3 exit review | APPROVED | NO |

## 32. Remaining owner decisions

- None before or during local Phase 3. E-1 was approved on 2026-10-06; the owner authorizes each step's continuation at the turn boundaries they set.
- **After hosting is purchased:** the production minimums and the release-switch mechanism, confirmed from the probe results (and the host upgraded or changed if it falls short, D19).

## 33. Risks and unresolved assumptions

**Host-dependent** (answered by the probe and the control-panel questions):
- PHP and MariaDB versions, and the upgrade path if they are weak (D19: the host adapts).
- open_basedir; whether the account home is writable (plan A or B); same file system.
- Symlinks and `Options` (strategy S or D); how fast the marker switch takes effect (LiteSpeed caching).
- Rewrite rules and `.htaccess` headers, including the SVG type and the sandbox policy.
- Whether PHP can request its own site; OPcache revalidation.
- Upload, memory and time limits; real upload behaviour; any web application firewall interference.
- WebP/AVIF; ZIP encryption; intl Persian calendar; current time-zone data.
- Cron availability; SMTP and SPF/DKIM; disk and inode quotas; resource limits; provider backups.

**Product-domain / source-dependent** (verify before coding the related rules; until then, unresolved):

| # | Assumption | Blocks | How to verify |
| --- | --- | --- | --- |
| V-1 | Which tokens are suffixes rather than part of the base, per family: seals/shields, clearance, design, bore, cage, precision | suffix rules, base-series and related matches, variant creation | the supplied brands' designation guides + Owner confirmation |
| V-2 | Which variant kinds share which values with their base (dimensions, ratings, speeds, lubrication, clearance, tolerances, materials) | any display that compares or groups values across designations (none is assumed in v1) | manufacturer tables per family and per value |
| V-3 | "/" as part of the bore code (62/22, 63/28, 320/32X) vs as a suffix separator | freezing the normaliser | ISO 15 / manufacturer designation systems + real examples |
| V-4 | Brand equivalents (2RS / 2RS1 / 2RSH / DDU / 2RSR / LLU; ZZ / 2Z) | spellings vs separate variants | manufacturer interchange documents; an Owner decision per pair |
| V-5 | Prefix meanings (N / NU / NJ / NUP; UC / UCP / UCF / UCFL; HK / BK; TC / TB / SC) | family templates, normaliser tests | manufacturer catalogues; DIN 3760 for seals |
| V-6 | Core values per family for housings, units, seals, needle and thrust bearings | page verification, drawing templates | catalogues / ISO, approved by the Owner |
| V-7 | Inch-series tapered designations (cone/cup sets), if supplied | slash rule, matcher | manufacturer catalogues + the old catalogue |
| V-8 | Whether the supplied brands' ratings and speeds can be published | Specifications content | the verified brands |

**Owner-input-dependent:**
- exact domains and parking;
- the old catalogue source and its quality (audit);
- the brands actually supplied;
- SMTP mailbox and notification address;
- the staging subdomain and database;
- the Owner account and Editors;
- launch content (logo, hours, map, channels).

## 34. Host probe (deferred, D26)

- The tooling is preserved in `tools/host-probe/` (version 1.1.0, hash-only key, temporary, excluded from the production repository). It is not run now, and local work does not need it.
- **When hosting is purchased, before production deployment:**
  1. Regenerate the package with a new key (hash only in files) and a new expiry. The 1.1.0 package expires on 2026-10-20.
  2. Run it, collect the report, then delete the folder and the zip from the host.
  3. Freeze the production PHP/MariaDB minimums and the release-switch mechanism, record them in AGENTS.md/CLAUDE.md, and upgrade or change hosting if it falls short of Nova's requirements (D19).
- What the probe collects and how it was tested is unchanged from version 1.1.0 (see `tools/host-probe/README.md`).
