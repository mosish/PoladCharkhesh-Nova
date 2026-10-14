-- 0008 Inquiries (Phase 3 step 10, 05 §10, §13; E-12, E-21; Gate 2 decision D).
-- - inquiry: what the visitor sent, the live release it was matched against, the status workflow
--   (new → in_progress → answered → closed) and the notification state. Stored before any email
--   is attempted; a failed notification never removes or rolls back the inquiry. Never published.
-- - inquiry_item: each requested designation verbatim (trimmed, never rewritten) with its normalised
--   key and the match the server recomputed against that release (E-21). Product and variant are the
--   release's public ids (p:<slug>, v:<slug>) plus the designations as published, not foreign keys:
--   the record keeps what the live site said at that moment, whatever the catalogue becomes later.
-- - inquiry_attempt: abuse throttling per client address, kept as an HMAC of the address (never the
--   address itself) and purged after 24 hours (05 §13).
-- Retention (E-12): inquiries and their items are deleted 24 months after they were received.

CREATE TABLE inquiry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  lang ENUM('fa','en') NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  quantity VARCHAR(20) NULL,
  message TEXT NULL,
  release_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  match_state ENUM('matched','no_code','no_release','unavailable') NOT NULL,
  status ENUM('new','in_progress','answered','closed') NOT NULL DEFAULT 'new',
  status_changed_at DATETIME(6) NULL,
  note TEXT NULL,
  notify_status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  notify_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  notify_claimed_at DATETIME(6) NULL,
  notified_at DATETIME(6) NULL,
  notify_error VARCHAR(255) NULL,
  KEY ix_inquiry_status (status, received_at),
  KEY ix_inquiry_notify (notify_status),
  KEY ix_inquiry_received (received_at),
  CONSTRAINT ck_inquiry_release CHECK (release_id IS NULL OR release_id REGEXP '^R-[0-9]{8}-[0-9]{6}-[0-9a-f]{4}$'),
  CONSTRAINT ck_inquiry_matched CHECK (match_state <> 'matched' OR release_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE inquiry_item (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inquiry_id BIGINT UNSIGNED NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  requested_text VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  requested_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  match_type ENUM('exact_product','exact_variant','unlisted_variant','related','base_series','none') NULL,
  via_spelling TINYINT(1) NOT NULL DEFAULT 0,
  spelling VARCHAR(64) NULL,
  no_match_reason ENUM('empty','too_long','unknown','ambiguous') NULL,
  product_ref VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  product_designation VARCHAR(64) NULL,
  variant_ref VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  variant_designation VARCHAR(64) NULL,
  chosen_ref VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  chosen_designation VARCHAR(64) NULL,
  listed_text TEXT NULL,
  context_differs TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_inquiry_item_position (inquiry_id, position),
  KEY ix_inquiry_item_key (requested_key(64)),
  KEY ix_inquiry_item_type (match_type),
  CONSTRAINT fk_inquiry_item_inquiry FOREIGN KEY (inquiry_id) REFERENCES inquiry (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_inquiry_item_spelling CHECK (via_spelling = 0 OR spelling IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE inquiry_attempt (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  address_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  outcome ENUM('pending','stored','invalid','honeypot','token','throttled','failed') NOT NULL DEFAULT 'pending',
  KEY ix_inquiry_attempt_address (address_hash, attempted_at),
  KEY ix_inquiry_attempt_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
