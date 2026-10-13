# Website and search readiness

Owner checklist implemented locally on 2026-10-12. This supplements `05` section 17; it does not approve a gate or change D1–D26. The frozen `design/` baseline remains untouched. DNS, hosting and production publication are deferred.

## Checklist and acceptance evidence

| Owner requirement | Local implementation / check | Remaining launch work |
| --- | --- | --- |
| Breadcrumbs | Visible, localized ordered navigation on product and privacy pages, with matching `BreadcrumbList` JSON-LD. | Review against final information architecture if more page types are added. |
| Custom domain | `BuildConfig` requires distinct language origins; indexable builds reject HTTP, localhost, IPs, credentials and path/query/fragment origins. | Confirm the actual `.ir` Persian and `.com` English domains; connect DNS, HTTPS and agreed canonical host redirects when hosting is selected. No DNS was changed. |
| robots.txt | Generated per language. Development/staging disallow crawling; production names its sitemap. HTML independently declares noindex where required. | Verify HTTP delivery, status and production directives after routing deployment. |
| Structured data | Existing eligible `ProductGroup` markup retained; breadcrumb and homepage business markup added. JSON syntax/context checked before publish. | Test actual public URLs in Google Rich Results Test; do not invent offers, ratings, stock or technical relationships. |
| Canonical tags | One absolute canonical matching the language host and clean page path. No canonical on 404 pages. Reciprocal fa/en/x-default checked. | Verify real domains and redirects together in Step 13. |
| Meta descriptions | Localized descriptions for each page type; product descriptions identify the code and listed items without promising unavailable drawings or sourced values. | Editorial review with real catalogue content. |
| Custom 404 | Existing bilingual template now has a real main heading, noindex and no canonical. | Confirm genuine HTTP 404 for unknown URLs under final Apache routing. |
| Local business schema | Homepage `LocalBusiness` uses the existing visible name, address and landline; both languages share a business identity on the English origin. | Owner confirmation of final business details; no opening weekdays, coordinates, postal code or reviews were guessed. |
| Sitemap XML | Separate language sitemaps. Only eligible verified nonsample product pages are listed in indexable builds; development lists no URLs. | Verify deployed endpoints and submit actual domain sitemaps after launch. |
| Favicon | Content-hashed SVG based on the existing bearing mark, replacing the empty data URI. | Revisit with the final owner-supplied logo if different from the current site mark. |
| Internal links | Published HTML checked for missing internal pages/anchors, empty placeholder hrefs and unsafe schemes. WhatsApp placeholders now have working fallback destinations. | External destinations are not crawled. Future document/media links need resource-aware validation when Step 11 introduces them. |
| Unique page titles | Nonempty, exactly one title per document; duplicates within a language block publication. Product titles include recorded listed variants. | Review the final catalogue for editorial quality. No character-count gate. |
| Unique headings | Exactly one nonempty H1 per page; duplicate H1 text within a language blocks publication. | Subheadings may legitimately repeat across product pages. |
| Avoid huge JS bundles | Existing framework-free modules preserved; Product Workspace remains dynamically imported. No dependencies or public JS added by this change. | Continue measuring actual pages/devices; D14/D17 prohibit arbitrary byte/request/speed gates. |
| llms.txt | Optional discovery file generated per language, with home/privacy and eligible production product URLs. Preview/sample products are excluded. | Optional aid only; not a substitute for HTML, sitemap, robots or search eligibility. Assumes the owner's `Ilms.txt` meant `llms.txt`. |
| Image alt text | Missing `alt` attributes on rendered HTML images block publication. Empty alt remains valid for genuinely decorative images. | Step 11 must enforce meaningful localized alt for informative media. The checker cannot judge alt quality or inspect image meaning. |
| Remove placeholder text | Visible “Phase 5” reference labels replaced with descriptive reference content; common unfinished-text markers rejected before publish. | Keep SAMPLE/pending/development labels until verified real content replaces fixtures. Technical data is never fabricated to fill gaps. |

## Validation and boundaries

- Verified on 2026-10-12: 288 PHP unit tests / 4,908 assertions; 171 JavaScript tests; PHPStan maximum level; PHP style checks; `git diff --check`. All passed.
- Isolated Docker preview: both language home/product/privacy/robots/sitemap/llms endpoints returned 200; unknown paths returned 404. Chrome inspection confirmed localized breadcrumbs and contained CTA buttons at 320px in English and Persian. These are targeted checks, not a fresh full Gate 2 regression run.
- PHP unit suite covers development/production template rendering, favicon output, localized breadcrumbs, escaped schema, 404 metadata, robots, sitemap XML, origin validation and deliberate metadata/link/alt failures. No database is needed by these new tests.
- A separate sample preview was built from a read-only database transaction; all 32 generated HTML pages passed the output audit. Assets and output were written only to a new ignored `storage/seo-preview-*` directory. No active release switch, pruning, migration or reset was performed.
- Existing integration tests were updated for breadcrumb JSON-LD on nonindexable pages, but the database-resetting integration setup and `dev:e2e` were not run in this task.
- The existing current release is unchanged: these source edits require a later deliberate build/publish after the publish-engine repairs.
- Small-screen product CTA wrapping is corrected in production CSS; the frozen design stylesheet is unchanged.

## Next Phase 3 work

The SEO additions are part of public-site/publish readiness, not completion of Phase 3.

Update of 2026-10-12, later the same day:
- **Browser suite:** the public-site suite (`tests/e2e/site.spec.js`, 32/32) passed against these changes on a disposable stack (its own database and docroot, port 8090).
- **Step 8:** the publish/recovery, release-path exposure and storage-containment issues are repaired and verified locally (`nova/docs/release-routing.md`).
- **Step 9:** the Admin and sign-in are built and verified locally (`nova/docs/admin.md`).
- **Not done:** none of this is committed, and the live preview release has not been rebuilt. Steps 10–16 have not started. Step 13 owns final domain routing, redirects, HTTP 404 behavior and production headers. Real catalogue content, media, inquiry handling and the local dry run remain separate acceptance work.

Reference guidance: [Google breadcrumb markup](https://developers.google.com/search/docs/appearance/structured-data/breadcrumb), [Local business markup](https://developers.google.com/search/docs/appearance/structured-data/local-business), [AI search optimization guidance](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide).
