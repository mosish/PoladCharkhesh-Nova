-- 0005 Admin sign-in (Phase 3 step 9, 05 §14, E-10).
-- app_user already has role, status, failed_logins and locked_until (0001).
-- - session_epoch: bumped when a password or TOTP is reset or the account is disabled; a session
--   remembers the epoch it was signed in under and ends when it no longer matches.
-- - totp_secret: the Owner's RFC 6238 shared secret (base32). It is needed in clear to check codes;
--   it lives only in the database and its backups, never in files or logs. totp_last_step stops a
--   code from being used twice.
-- - login_throttle: failed sign-ins per client address, keyed by a SHA-256 of the address (never the
--   address itself), for back-off across accounts. Rows are short-lived and purged on success.

ALTER TABLE app_user
  ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 1 AFTER locked_until,
  ADD COLUMN totp_secret VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER session_epoch,
  ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret,
  ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_enabled_at,
  ADD COLUMN last_login_at DATETIME NULL AFTER totp_last_step,
  ADD CONSTRAINT ck_app_user_totp CHECK (totp_enabled_at IS NULL OR totp_secret IS NOT NULL);

CREATE TABLE login_throttle (
  subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  KEY ix_login_throttle_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
