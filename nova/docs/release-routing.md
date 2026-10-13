# Release storage and routing: Step 8 guarantees and Step 13 requirements

Recorded 2026-10-12 with the Step 8 repairs. This supplements `05` §8 and §24 step 13. It approves nothing and changes no decision (D1–D26).

## What Step 8 now guarantees

- **Storage is outside the docroot.** Outside development, `PublishFactory::locations()` refuses a `paths.storage` that resolves to `paths.public` or anywhere inside it. Both paths are resolved through the OS first, so symlinks and `..` count. Storage holds the publish journal and the strategy D archive now; backups and sessions arrive later.
- **No direct HTTP access under `/_site/`.** `public_html/.htaccess` returns 404 for any client request whose path starts with `/_site`.
  - That covers `current/`, `releases/<id>/`, `slot-a/`, `slot-b/`, `ACTIVE_B` and `manifest.json`.
  - Encoded (`%5f`), double-slash, dot-segment and upper-case spellings are blocked too.
  - Internal rewrites still work, because they set `REDIRECT_STATUS` and a client request never does.
  - The rule is deliberately not wrapped in `<IfModule>`: if `mod_rewrite` is missing, the file fails closed with a 500.
  - Evidence: `tests/integration/Publish/ApacheReleaseAccessTest.php` runs a private Apache 2.4 against the real `.htaccess` for S, D with slot a live, and D with slot b live.
- **The command line refuses web SAPIs.** `app/bin/nova` returns 2 under any SAPI except `cli`, before it reads the database.
- **Audit entries record who started the operation.** Publish and rollback audit entries carry `by`:
  - `{"origin":"admin","user_id":…}` for an Admin user;
  - `{"origin":"cli","os_user":…}` for the command line. This is the effective OS account name, kept only when it is plain `[A-Za-z0-9._-]`; never a credential.
  - `{"origin":"recovery"}` for automatic recovery, together with `started_by` naming whoever began the operation it finished.
- **Rollback is journalled.** A crash between the switch back and its database record is finished by `recover()`. A crash before the switch changes nothing.
- **Recovery records what was actually published.** The release identity (Product and variant ids, taken from the snapshot) and the summary are journalled before the switch. Recovery uses them, never a fresh read of the database.
- **Recovery is idempotent.** It skips recording when the record is already live, so a recovery that dies before clearing the journal does not record or audit twice.
- **Strategy D closes its grace-period window.** The live slot's grace timestamp is written before the marker changes, and again after. Wherever `activate()` is killed, a slot that was live within the last 120 s is never reused at once.
- **Marker, journal and timestamp writes are durable.** Each is a temporary file, `fsync`, rename, then `fsync` of the directory.
- **Strategy S refuses a link Nova did not write.** Nova always writes `_site/current` as the relative link `releases/<id>`. An absolute, dangling or non-link `current` is refused with instructions to repair it; it is never read as "nothing is live".
  - Background: the dev mount is 9p/drvfs with `symlinkroot=/mnt/host/`. Nova's link is stored as a WSL symlink with a relative target, which reads correctly from both the container and Git Bash.
  - A Windows-native tool that re-creates the link writes an absolute `/mnt/host/c/...` target, which is dangling inside the container. That is what happened on 2026-10-11.

## Requirements for the Step 13 `.htaccess` template

1. **Keep the `/_site` block as the first rewrite rule**, unchanged and not inside `<IfModule>`.
2. **Route each host to its language with internal rewrites only.** Every routing rule needs `RewriteCond %{ENV:REDIRECT_STATUS} ^$`, so that rewritten requests are not routed twice.
   - S: `.ir` → `_site/current/fa/$1`, `.com` → `_site/current/en/$1`.
   - D: if `%{DOCUMENT_ROOT}/_site/ACTIVE_B -f`, route to `_site/slot-b/<lang>/$1`; otherwise to `_site/slot-a/<lang>/$1`.
   - The test's stand-in rules show the shape. They are not the template.
3. **Handle trailing slashes in the template itself.** Verified on Apache 2.4.68: mod_dir's automatic redirect for `/p/x` (no trailing slash) answers `301 Location: /_site/current/en/p/x/`, which exposes the internal path and then 404s. Set `DirectorySlash Off` and add an explicit external redirect to the clean URL with a trailing slash.
4. **Route `/data/…`** (the published JSON) into the live release's `data/` folder. `/assets/…` is served directly from `public_html/assets/`, outside `_site`.
5. **Make unknown URLs return a real 404 status**, using an `ErrorDocument` routed to the language's `404.html`. Release-root files (`manifest.json`) sit outside every language folder, so routing never reaches them; keep it that way.
6. **Allow the symlink for S only**, with `Options +SymLinksIfOwnerMatch` (or `FollowSymLinks`). The host probe (D26) confirms which one the host allows. Nova creates the link and the release as the same account.
7. **Keep the dotfile rule.** It also denies `.release` and `.slot-*-inactive`.
8. **Ship `ApacheReleaseAccessTest` with the template.** Point it at the generated template instead of the stand-in, and run it for both strategies on the Apache container.
