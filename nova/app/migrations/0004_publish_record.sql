-- 0004 Publish records (Phase 3 step 8, 05 §7/§8, D13).
-- One row per publish attempt: the release it built, how it ended, and what it reported.
-- The file journal (storage/publish/journal.json) covers crashes; this table is the history.

CREATE TABLE publish_record (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  release_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('building','failed','live','superseded','rolled_back','pruned') NOT NULL DEFAULT 'building',
  strategy ENUM('symlink','slots') NOT NULL,
  environment ENUM('development','staging','production') NOT NULL,
  started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  finished_at DATETIME(6) NULL,
  went_live_at DATETIME(6) NULL,
  published_by BIGINT UNSIGNED NULL,
  previous_release_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  summary_text LONGTEXT NULL,
  UNIQUE KEY uq_publish_record_release (release_id),
  KEY ix_publish_record_status (status),
  KEY ix_publish_record_by (published_by),
  CONSTRAINT fk_publish_record_by FOREIGN KEY (published_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_publish_record_release CHECK (release_id REGEXP '^R-[0-9]{8}-[0-9]{6}-[0-9a-f]{4}$'),
  CONSTRAINT ck_publish_record_live CHECK (status NOT IN ('live','superseded','rolled_back') OR went_live_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
