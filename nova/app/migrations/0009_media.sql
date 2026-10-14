-- 0009 Media core (Phase 3 step 11, 05 §12, §26; E-7).
-- - media: raster images and sanitised SVG, named by the SHA-256 of the stored (cleaned) bytes.
--   Alt text in both languages with an informative or decorative role (ALT-INF, frozen G2-15).
--   A replaced file keeps its row, so every reference (by id) survives; trashed_at starts the 30-day trash.
-- - document (0001) gets the same trash column; PDFs are documents and are linked to a Product or one
--   variant through document_link.
-- References are not stored: they are read from the rich content and document_link when needed, so they
-- can never disagree with the content.

CREATE TABLE media (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('raster','svg') NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ext VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mime VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  width INT UNSIGNED NOT NULL,
  height INT UNSIGNED NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  derivatives TEXT NULL,
  alt_fa VARCHAR(300) NULL,
  alt_en VARCHAR(300) NULL,
  role ENUM('informative','decorative') NOT NULL DEFAULT 'informative',
  uploaded_by BIGINT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  trashed_at DATETIME NULL,
  UNIQUE KEY uq_media_sha256 (sha256),
  KEY ix_media_kind (kind, trashed_at),
  KEY ix_media_uploaded_by (uploaded_by),
  CONSTRAINT fk_media_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_media_sha256 CHECK (sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT ck_media_ext CHECK (ext IN ('jpg','png','webp','svg'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

ALTER TABLE document
  ADD COLUMN year SMALLINT UNSIGNED NULL AFTER byte_size,
  ADD COLUMN uploaded_by BIGINT UNSIGNED NULL AFTER year,
  ADD COLUMN trashed_at DATETIME NULL AFTER updated_at,
  ADD KEY ix_document_uploaded_by (uploaded_by),
  ADD CONSTRAINT fk_document_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT;
