# Backup and restore (Phase 3 step 12)

Built and verified locally on 2026-10-15. It approves nothing and changes no decision (D1–D26, E-1–E-22). It implements the layered strategy of `05` §18 (D13, D23, E-11). A backup that has never been restored is not considered working, so every layer below was restored in the tests.

## The layers

| Layer | What it is here | When | What it restores |
| --- | --- | --- | --- |
| Release rollback copies | The retained releases under `_site` (the Publish Engine's job). A backup never copies, prunes or moves them; its manifest only lists their ids and checksums. | every publish | the public site, instantly |
| Database backup | PHP-written logical dump of one consistent snapshot (`nova-dump/1`, gzip, per-table row counts and content checksums) | `backup:run` daily; before every migration (`pre_migration`) and before replacing a live database (`pre_restore`); on demand | all CMS data: catalogue, provenance, content, inquiries, users, audit |
| Media and document backup | A content-addressed store shared by all sets (only new files are copied) plus a manifest of every file the database records; optional full ZIP archive | with every backup; `backup:archive` weekly or monthly | the media library and documents |
| Application (code) | Git history and deploy packages; not in a backup | every deploy | code, by redeploy |
| Configuration | **Never in a backup.** The Owner keeps `config.php` values in a password manager; `backup:config-checklist` prints the key names to rebuild it | on change | a fresh `config.php` |
| Hosting-provider backup | Account snapshots (retention and restore procedure are launch inputs) | provider | disaster recovery through the provider |
| Off-site copy | `backup:export`: one encrypted file (libsodium, passphrase) holding a verified set | weekly (database), monthly (media) | recovery on any host |

### What a backup holds and never holds

- **Holds:** every table's schema and rows (one snapshot), the media and documents the database records, and the retained-release list.
- **Rows deliberately not backed up:** `login_throttle` and `inquiry_attempt` (short-lived rows keyed by hashed visitor addresses). Their structure is in the dump, so a restored database is complete and the application runs at once; they restore empty.
- **Never:** `config.php`, `.env`, SMTP/database passwords, `inquiry.key`, PHP session files, code, vendor, generated assets and releases.
- **Sensitive by nature:** the database dump contains password hashes, Owner TOTP secrets and visitors' inquiries. So the folder is private (outside the document root, mode 0700, files 0600; outside development a `backup.dir` inside the docroot is refused) and a set leaves the server only encrypted.

## Commands

All run from `nova/` as `docker compose exec web php app/bin/nova …` (on a server: `php app/bin/nova …` from the deployed app folder).

| Command | Does |
| --- | --- |
| `backup:run [--trigger=scheduler\|manual\|fallback] [--no-prune]` | one set (database + media), then retention. Exit 2 on refusal. |
| `backup:list` / `backup:status` | the sets; the last good one. `backup:status` exits 3 when no backup is newer than 26 h or the last attempt failed (usable by monitoring). |
| `backup:verify <id>` | proves a set is whole without touching any database: manifest checksum, dump checksum and statement count, every media file's hash. |
| `backup:prune` | applies retention and sweeps store files no remaining set names. |
| `backup:archive <id>` | a full media ZIP archive of that set. |
| `backup:export <id> <file>` / `backup:import <file>` | the encrypted off-site copy; the passphrase is asked (never an argument, never stored). |
| `backup:config-checklist` | what a backup holds and never holds, and the configuration key names (no values). |
| `restore:db <id> --target=<db> [--replace-live --confirm=<db>]` | restores a database; see "Restoring". |
| `restore:verify <id> --target=<db>` | compares a database with a set, table by table. |
| `restore:cleanup --target=<db>` | empties a database whose restore was interrupted. |
| `restore:media <id> --target-dir=<folder> [--archive=<zip>]` | restores media from the store or from a full archive. |

### Scheduling, and when there is no scheduler

1. **Preferred: cron** runs the backup daily, for example:

   ```
   15 3 * * *  cd /path/to/app && php app/bin/nova backup:run --trigger=scheduler >> storage/backups/cron.log 2>&1
   0  4 * * 0  cd /path/to/app && php app/bin/nova backup:archive "$(php app/bin/nova backup:list | head -1 | cut -d' ' -f1)"
   ```

   Also run `media:purge` and `inquiry:purge` daily (they apply the 30-day trash and the 24-month inquiry retention).
2. **Fallback, only when no reliable scheduler exists:** set `backup.fallback` to `true`. The first signed-in Admin request of the day then makes the backup, but only when no successful backup is newer than 24 hours (a working scheduler is never duplicated), and a failed fallback is not retried for an hour, so a broken backup cannot slow every request. The fallback runs the dump inside that request.
3. **Always available:** the Owner's "back up now" on the Admin Backups screen, and the command line.

The Admin dashboard and the Backups screen always show the last successful backup and warn when it is overdue (`backup.overdue_hours`, default 26) or when the last attempt failed.

### Retention (D13)

7 daily, 4 weekly and 3 monthly: the newest set of each of the last 7 days, of the last 4 ISO weeks and of the last 3 months, counted back from the newest scheduled or manual set (the tiers overlap, so a long gap does not keep old sets alive; several sets on one day keep only the newest). The newest successful set is always kept. Safety copies (`pre_migration`, `pre_restore`) are kept separately, the newest 5. Pruning deletes a set's files and then any store file no remaining set names. Configure with `backup.keep` (`daily`, `weekly`, `monthly`, `safety`).

### Pre-migration backup

`migrate` on a non-empty database first writes and **verifies** a `pre_migration` set and records its id with every migration of the run (replacing the earlier "no mechanism, refuse" guard). If the backup cannot be made or does not verify, the migration is refused and nothing changes. `--no-backup` remains a development-only, visible, audited bypass. A normal backup waits for a running migration (the named migration lock) so it never sees a half-changed schema.

## Restoring

A restore never creates databases and never guesses a target: the database is named explicitly and must already exist.

1. **Check the set:** `backup:verify <id>` (the restore does this too and refuses a set that fails).
2. **Into an empty database** (the normal case, and the way to rehearse):

   ```
   php app/bin/nova restore:db <id> --target=<empty database>
   php app/bin/nova restore:verify <id> --target=<empty database>
   ```

   While it runs a marker table `_nova_restore` says "running"; at the end every table is compared with the manifest (row count and content checksum) before the marker becomes "done". The marker table is harmless and stays as provenance (backups skip `_nova_*` tables).
3. **A new host after a disaster:** create an empty database, put it in the new `config.php` (rebuilt from the checklist), `backup:import` the encrypted file, then `restore:db <id> --target=<that database>`. An empty configured database needs no extra flag: there is nothing to lose. Then `restore:media <id> --target-dir=<public>/media`, `assets:build`, create nothing by hand (accounts came with the database), and **publish** to regenerate the site. Releases are not in a backup.
4. **Replacing the live database that holds data** is refused unless both `--replace-live` and `--confirm=<the live database name>` are given. A `pre_restore` safety copy is then made first and must succeed (so the replacement can itself be undone), and the tables are replaced.
5. **Interrupted restore:** a failure inside the process empties the target again by itself. If the process was killed, the marker makes the next restore refuse (`interrupted`) until `restore:cleanup --target=<db>` has emptied it; cleanup refuses any database that does not carry the marker.
6. **Repeating** a restore of the same set into a database that holds it unchanged reports `already_restored` after verifying it; a changed database holding an earlier restore is left alone.
7. **Media:** `restore:media` copies the files the manifest names into a folder that is absent, empty, or holds only media (a folder holding anything else, a symlink, the backup folder or a parent/child of it, a top-level folder and `/` are all refused). It checks every hash before writing, never overwrites a different file (`conflict`), is idempotent and removes leftovers of an interrupted copy.

A set from a **newer schema than the running code** is refused (restore it with the newer release). A set from an **older schema** restores as it was; then run `migrate`, which makes its own `pre_migration` copy first.

Two consequences of restoring a live database that are correct but worth knowing: the Owner's TOTP **replay protection travels with the database** (a code used just before the backup is refused for its 30-second step), and the restore is audited in the restored database's own audit trail, after the restored rows (the audit table is append-only, so verification covers the rows up to the backup point).

### Off-site copy (E-11)

`backup:export <id> <file>` writes one file holding the manifest, the dump and the media files, encrypted with XChaCha20-Poly1305 under an Argon2id key from a passphrase of at least 12 characters. Any change, reordering or truncation is detected; a wrong passphrase fails at the first chunk; crafted key-derivation settings in a file are refused. Nothing in the file is readable, not even the set id. `backup:import` accepts only the three kinds of entry a set consists of, by exact name shape (a `..` path, an absolute path or an unknown file refuses the whole import), and removes the set again if it does not verify. **Losing the passphrase loses the copy; there is no recovery by design.** Keep it in the Owner's password manager, never in a file or in the repository.

## Audit

Every outcome is audited: `backup.run`, `backup.failed`, `backup.prune`, `backup.archive`, `backup.export`, `backup.import`, `restore.db`, `restore.media`, `restore.cleanup`, `restore.refused` (a rejected target, set or folder) and `restore.failed`. Entries carry ids, counts, targets and reasons: never row contents, passphrases or secrets. The dashboard shows a failure until a newer success.

## How it was verified (2026-10-15)

- **PHPUnit** (disposable databases and temporary folders only): `BackupRestoreTest`, `MediaRestoreTest`, `OffsiteTest`, `PreMigrationGuardTest`, `BackupCommandsTest`, `RetentionTest` and Admin tests cover: creation, privacy and secret exclusion, incremental store, retention and store sweeping, restore into an empty database with the source compared table by table (awkward data: quotes, backslashes, newlines, Persian text, emoji, NULLs, generated columns), idempotence, wrong and non-empty targets, invalid names, set-id traversal, replacing a live database with a safety copy, an empty configured database, corrupt/truncated/missing/edited/newer-schema sets, interrupted restores (in-process and crashed), cleanup refusals, retained releases byte-identical after backup, prune and restore, media restore (traversal paths, foreign/linked/overlapping targets, damaged or missing files, conflicts, interrupted copies, archives), the encrypted copy (round trip, wrong passphrase, flipped, truncated and extended files, unsafe settings, hostile bundles), the pre-migration guard (a database at schema 4 backed up, migrated and the set restored to schema 4; a failed backup refuses the migration), the fallback trigger and the overdue status.
- **Real stacks:** two disposable Apache stacks. Host A made the backup through the command line and exported it encrypted; separate host B imported it (damaged and wrong-passphrase attempts refused), restored the database and media, repeated the restore idempotently, signed the Owner in with the restored account and TOTP secret, published from the restored database and served the restored PDF byte-identical to the original (22 checks). `admin.spec.js` covers the Backups screen.

## Limits and risks

- **Not a point-in-time recovery:** a restore returns to the moment of a backup. Anything after it (inquiries, edits) is lost unless a later backup exists; run the backup at least daily.
- **Logical dump, bounded by the host:** the dump streams rows but a very large database takes time and must finish within the host's PHP limits; the fallback runs inside an Admin request. Cron on the command line has no web time limit. The size at which this stops being comfortable is a hosting-probe question (step 15).
- **The database and the media are copied one after the other,** not atomically. A file uploaded between the two is simply in the next set; a file the database names but the disk lacks is listed as `missing` and shown as a warning, never hidden.
- **The manifest checksum guards against damage, not against an attacker with write access** to the backup folder (who could rewrite a dump and its manifest together). Protection against that is the private folder, and the authenticated encryption of off-site copies.
- **No partial or per-table restore,** and no migration between schema versions inside a restore.
- **A restore proven here is a local proof.** The restore drill on staging (database, media, configuration, republish) is a Phase 7 exit criterion; a periodic test restore from an off-site copy is recommended quarterly after launch.
- **Hosting-provider backup** retention, download and restore procedures, and cron/PHP-CLI availability on the real host are launch inputs.
