# Media core and SVG ingestion (Phase 3 step 11)

Built and verified locally on 2026-10-14. It approves nothing and changes no decision (D1–D26, E-1–E-22). It implements `05` §12 (media and documents) and §26 (SVG policy, E-7).

## What exists

- **Library** (frozen A4): Admin → Media (`/admin/media`) for images and SVG, Admin → Documents (`/admin/documents`) for PDFs. Owner and Editor work both; **SVG upload is Owner-only**.
- **Files** live in `public_html/media/<year>/<sha256>.<ext>` (responsive copies `<sha256>-<width>.webp`), outside the releases. Names are the SHA-256 of the stored bytes, so the same bytes are stored once and a name cannot be guessed or chosen. The original file name is kept only as a display label and is never used as a path.
- **Raster images (JPEG, PNG, WebP):** the content decides the type, never the name or the browser's `Content-Type`. The image is decoded and re-encoded, which removes EXIF, GPS, embedded profiles and any trailing payload; the EXIF orientation is applied to the pixels. A pixel cap is checked before decoding (decompression bombs). WebP copies at 480 / 960 / 1600 px are written for widths narrower than the original; the re-encoded original is the fallback. Dimensions are recorded.
- **SVG (Owner only), `Nova\Media\SvgSanitizer`:**
  1. Refused outright: compressed `.svgz`, invalid UTF-8, anything over the size cap, and any DOCTYPE, ENTITY, ELEMENT, ATTLIST declaration or xml-stylesheet instruction (this refuses external entities and entity expansion). The root must be `<svg>` in the SVG namespace, and a `viewBox` or pixel size is required.
  2. The file is rebuilt from an allow-list into a new document: only listed elements and attributes are copied; `script`, `style` (element and attribute), `foreignObject`, `image`, animation, `a`, every `on*` handler, comments and processing instructions never are. `href` may only name an `#id` that exists in the same file; `url()` may only name such an `#id`; `javascript:`, `data:` and character references in values are dropped. Node count and nesting depth are capped.
  3. The rebuilt file is passed through the maintained library `enshrined/svg-sanitize` (remote references removed) as a second layer, and what comes out is stored.
  4. The Owner is shown what was removed (for example `element <script>`, `attribute onload`).
  - Display is through `<img>` only, never inlined. The folder serves SVG as `image/svg+xml` with `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`, so opening the file directly runs nothing.
- **PDF documents:** see "What the PDF check does not do" below. They are served as downloads only.
- **Alt text (ALT-INF, G2-15):** each image has an alt text in Persian and in English and a role, informative or decorative. An informative image without both texts **blocks publishing** while a published page uses it. Today no published page can use an image: product descriptions allow paragraphs and lists only, and figures arrive with the Article-profile sections of Phase 4b. The check and its tests exist now so that the rule is in force from the first use.
- **Documents on the site:** a PDF is attached (DocumentLink) to **exactly one** Product or variant designation, per language (`fa`, `en`, `both`); a spelling cannot carry one, and nothing is shared between designations implicitly (D20/E-2). The Product page lists each item's documents under that item, with the file size shown before download, and the workspace Documents tab lists the same. An unattached document is not published.
- **Replace, trash, purge:**
  - **Replace** keeps the item's id, alt text and references; a replacement of another kind (SVG ↔ raster) is refused.
  - **Delete** is blocked while the item is used (in rich content or a document link). Deleting moves it to the trash for 30 days; it can be restored, and uploading the same bytes restores it too.
  - **Purge** (`app/bin/nova media:purge`, also run by the step 12 scheduler) removes records that have been in the trash for 30 days, then deletes files that **no record names and no retained release links to**, once they are 30 days old. A retained release is any not pruned (live, superseded or rolled back); the purge reads the releases' own HTML and JSON (`ReleaseMedia`) rather than trusting the database, so a file a rollback would need is never deleted, however old. If the purge is run without that knowledge it deletes no files at all.
  - A trashed or replaced file therefore stays on disk (and keeps being served) until it is both unreferenced and 30 days old. This is deliberate: the live release and the rollback targets are static pages that link to it.
- **Audit:** upload, update, replace, trash, restore, purge, link and unlink are recorded.
- **Limits** (safety caps, `media.*` in the configuration; not performance budgets, D14): image 20 MB and 50 megapixels, PDF 30 MB, SVG 512 KB, 5,000 SVG elements, nesting 32. PHP's `upload_max_filesize` and `post_max_size` must be at least the largest cap the host should accept.

## What the PDF check does not do

`PdfInspector` is a conservative, best-effort byte check. It requires the `%PDF-` header and an `%%EOF` end marker, enforces the size cap, and refuses a file whose bytes contain any of these names (PDF names are decoded first, because `#` plus two hex digits can spell any character, so `/J#53` counts as `/JS`): `/JavaScript`, `/JS`, `/Launch`, `/OpenAction`, `/AA`, `/EmbeddedFile`, `/RichMedia`, `/SubmitForm`, `/ImportData`, `/GoToR`, `/GoToE`.

**It does not guarantee a harmless PDF.** A PDF is a container: names can be hidden inside compressed object streams or encoded, a file can be valid and still exploit a bug in a viewer, and no scan of that kind proves otherwise. Nova does not parse, render, repair or rewrite PDFs. The protections that matter are structural: only the Owner and Editor upload; files are served from a folder where PHP is disabled, with `Content-Disposition: attachment`, `nosniff` and a sandbox CSP, so the browser downloads them instead of opening them inside the site; and a document appears on the site only when it is attached to a designation. Visitors still open a downloaded PDF in their own viewer. For a document from an untrusted source, scan it with an up-to-date tool before uploading.

## Serving and execution (`public_html/media/.htaccess`)

PHP engine off, handlers and types for `.php`, `.phtml`, `.phar` etc. removed, no listing, no `MultiViews`; every name that is not `<64 hex>[-<width>].(jpg|png|webp|svg|pdf)` is refused (403), `.htaccess` included; `nosniff` and `noindex` on everything; one-year caching, which is safe because names are content hashes. The generated production `.htaccess` of step 13 must keep this folder as it is and outside the release switch.

## Not in step 11

- Inserting images into content: Article-profile sections come with Phase 4b (A5). The library, the references and the ALT-INF rule are ready for it; `MediaReferences` reads product descriptions today and must also read section content when that exists.
- Per-document download counts or analytics (C4).
- PDF repair, rendering or thumbnails; SVG-to-raster copies (not in v1).
- Production `php.ini` limits, and the `.htaccess` capability check on the real host: step 15 / the hosting probe.

## How it was verified (2026-10-14)

- **PHPUnit:** `MediaServiceTest` (raster re-encoding and derivatives, EXIF/GPS removal and orientation, disguised types and appended payloads, pixel cap, de-duplication, alt text and stale edits, ALT-INF, references and trash, replace, 15 SVG attack samples, 9 refused SVG inputs, SVG caps, PDF acceptance and 8 refused PDFs, document links); `MediaRetentionTest` (draft, live and rollback releases against replace, trash and purge); `AdminAppTest` (uploads through the real front controller, SVG authorization, document publish).
- **Real Apache and HTTP,** on a disposable stack (own database, docroot, storage; port 8091), with real multipart uploads by throwaway Editor and Owner sessions: 35 checks, including JPEG/PDF/SVG content types and headers, EXIF and orientation, Editor SVG refusal, signed-out and tokenless refusal, disguised files, a hostile SVG, and planted `.php`, `.phtml`, `x.php.jpg` files (403) and a hash-named `.jpg` holding PHP code (served only as `image/jpeg`, never run).
- **Playwright:** `media.spec.js` (Product page and workspace Documents tab, fa and en, axe, download headers), `admin.spec.js` (the media library flow and the new screens), `site.spec.js` 32/32.
