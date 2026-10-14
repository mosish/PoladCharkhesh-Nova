# Inquiry endpoint (Phase 3 step 10)

Built and verified locally on 2026-10-14. It approves nothing and changes no decision (D1–D26, E-1–E-22). The frozen contact sheet and A6/A1/A8 wireframes were used as they are; the form gained no new fields.

## What exists

- **The only public PHP** (D1): `public_html/api/inquiry.php`, a same-origin POST on both language hosts (`/api/inquiry.php`). Its own `.htaccess` allows only that file. Code: `app/src/Inquiry/`.
- **Order of work** (05 §13):
  1. The host decides the language. The form's `lang` field is ignored; any other host gets 404.
  2. Same origin: `Sec-Fetch-Site` must be `same-origin`, or, when the browser sends none, `Origin` must be this host's exact origin. `Origin: null` or no header at all is refused.
  3. Abuse limits: a per-address rate limit, then the honeypot, then the minimum fill time.
  4. Validation (`InquiryForm`).
  5. The designation match is recomputed against the live release (E-21).
  6. The inquiry, its items, the match and the release are stored in **one transaction**.
  7. Notification by SMTP.
  8. The response: JSON for the page's script (`Accept: application/json`), otherwise a 303 to a "received" page, or the form again with reasons.
- **Validation:** name and phone are required (phone: 7–15 digits in any script, with the usual separators); a part code or a description of the application is required (the form's own hint); limits 120 / 40 / 64 code points / 20 / 2,000; control characters and invalid UTF-8 are refused. Values are trimmed and nothing else: the code is never rewritten.
- **Designations are preserved verbatim** (05 §10): `requested_text` keeps the visitor's text (`QZ 1-2RS`, `6205-2rs/C3`), and `requested_key` is the normalised key the matcher used. A suffix is never collapsed into its base. Brand notations stay distinct; "/" stays significant; no suffix rule is approved, so `base_series` cannot occur yet (V-1).
- **Server-side matching (E-21):** `Matcher` against the live release's own designation index. The page's `product`, `item` and `match` fields are only a claim about which listed item the visitor looked at:
  - an exact match names itself, whatever the page said;
  - for `related` and `base_series` a chosen item is kept only if it is one of the items the server's own match lists;
  - `context_differs` records that the page claimed something else, and the Admin shows it.
- **A consistent live release:** `LiveIndex` reads the live release id once, then reads that release's own `designation-index.json` (written by every publish, with the release id inside) from that release's directory, never through the `current` pointer a publish may move meanwhile. The file must match the release `manifest.json` read from the same directory. A release built before step 10 is read from the index embedded in its `fa/index.html`, verified the same way. When no release is live, or it cannot be verified, the inquiry is still stored verbatim with `match_state` `no_release` / `unavailable` and no match, never a guessed one.
- **Abuse protection:**
  - **Minimum fill time:** pages are static, so the endpoint signs the start time (`FillToken`: `v1.<time>.<HMAC>`, bound to the language, valid for 24 h). With JavaScript the form fetches a token the first time it is used and, when a fast fill is answered with "wait", resends the same values automatically. Without JavaScript the first submission is answered with the form refilled and a token to confirm, so a visitor without scripts presses "Send" twice (this extra step is a deliberate consequence of static pages; owner may review).
  - **Honeypot:** the hidden `website` field.
  - **Rate limit:** 10 per hour and 30 per 24 hours per address (safety caps in config, not performance budgets). Only an HMAC of the address under the inquiry key is stored (never the address, never a bare hash), and rows are deleted after 24 hours. The limit is race-safe in the conservative direction.
  - **Fail-closed on origin:** refused before anything is written.
- **Notification is never required for an inquiry to exist:** the inquiry is committed first. A failed or missing send leaves `notify_status = failed` with the reason; the visitor still sees "received". Failure is visible in the Admin (dashboard banner, nav badge, a badge per inquiry, the reason on the detail) and is retried from the Admin (**Retry**, one or all) or `app/bin/nova inquiry:notify`. A send is claimed with a conditional UPDATE so two retries never send the same inquiry twice; a claim left by a crashed request expires after 10 minutes.
- **The email carries no personal data:** reference (IQ-n), language, time, release, the requested designations with their match, and an Admin link. Name, phone and message are shown only in the Admin, so mailbox copies cannot outlive retention or deletion on request.
- **Recipient (decision D, A8):** an Owner-only Admin setting (`inquiry.notify_to`, Admin → Inquiries → notification address), audited, validated (one address, no list, no header injection). It is never in code. While it is empty every notification fails visibly and stays retryable. **The real address and the production SMTP mailbox are launch inputs.**
- **Retention (E-12) and the privacy text:** inquiries and their items are deleted 24 months after receipt (`app/bin/nova inquiry:purge`, and opportunistically after each stored inquiry; the step 12 scheduler will run it too). The Owner can delete one inquiry on request (confirmation required). The audit trail records only the reference, language, statuses and counts, never visitor content, so it does not outlive the data. No tracking (C4); inquiries are never published and are not "unpublished changes".
- **Admin (A6, A1, A8):** the inbox with status tabs (new → in progress → answered → closed), search by name, phone or code, the detail with the message, the requested codes beside the match the server recorded (never re-matched), WhatsApp (wa.me built from the stored number) and call buttons, an internal note, the notification state, and the dashboard tiles: new inquiries and the codes asked for that matched nothing (from what visitors wrote, not tracking). Owner and Editor work the inbox; only the Owner deletes or sets the recipient.
- **Without JavaScript and after a failure** the endpoint answers with the contact sheet in the visitor's language and direction, the form refilled exactly as entered, the reasons beside each field (`aria-invalid`, `aria-describedby`), and WhatsApp and phone still shown. With JavaScript the form is never cleared on failure.

## Configuration

| Key | Meaning |
| --- | --- |
| `inquiry.key` | Secret (at least 32 characters) for fill tokens and the address HMAC. Required outside development; development derives one from the database password. Never committed. |
| `inquiry.limits.hour` / `.day`, `inquiry.min_fill_seconds`, `inquiry.max_token_age` | Safety caps. |
| `smtp.host`, `port`, `user`, `password`, `encryption` (`''`, `tls`, `ssl`) | The SMTP mailbox. The dev stack uses the local mail catcher (Mailpit, `http://localhost:8025`); nothing is sent outside. |
| `mail.from` | The sender address (default `no-reply@nova.localhost`). |

## Step 13 routing requirement

`/api/inquiry.php` must be reachable on both language hosts and must not be rewritten into the release. The generated `.htaccess` has to keep `api/` outside the release switch and keep PHP disabled everywhere else. The dev vhosts already do this (`Alias /api`).

## How it was verified (2026-10-14)

- **PHPUnit:** `InquiryEndpointTest` (18 tests: success, verbatim suffixes and spellings, tampered context, release changes and rollback, no release / unverifiable release, a pre-step-10 release, invalid input, no-JavaScript pages, origin, honeypot, fill time, rate limit and hash storage, SMTP failure and retry, claimed sends, recipient rules, storage failure, retention, routing) plus three `AdminAppTest` tests for the inbox. Disposable databases only.
- **Playwright (`tests/e2e/inquiry.spec.js`),** on a disposable stack (its own database, docroot, storage; port 8090): fa and en, with JavaScript (client and server errors keep the values, then a send), without JavaScript (refilled form, confirmation, received page), axe on the error and received pages, a cross-site POST and a honeypot fill. It writes inquiries, so it runs only with `NOVA_INQUIRY_E2E=1`.
- **Mailpit:** a stored inquiry whose first notification failed (no address set) was retried from the command line and arrived with the expected content and no personal data.
- Two defects were found and fixed by this run: the default sender address was rejected by PHPMailer, and the form's error text had 2.5:1 contrast on the navy sheet.

## Not in step 10

- A PHP upload or attachment on the form (none was specified).
- The Company/settings screen (A8) beyond the notification address: Phase 4b.
- Production SMTP, SPF/DKIM and the real notification address: launch inputs.
