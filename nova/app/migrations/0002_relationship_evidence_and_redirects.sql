-- 0002 Relationship evidence, publication history and redirects (Phase 3 step 5).
-- A variant or spelling is a relationship claim (05 §9): it exists publicly only once the
-- Owner has confirmed it, a variant always with a cited source. first_published_at freezes
-- public identity (slugs) after the first publish. Redirects carry the 301s created when a
-- standalone designation is resolved into a variant, and the 410/301s of archived pages (E-13).

ALTER TABLE product_variant
  ADD COLUMN relationship_source_id BIGINT UNSIGNED NULL AFTER notes,
  ADD COLUMN relationship_confirmed_by BIGINT UNSIGNED NULL AFTER relationship_source_id,
  ADD COLUMN relationship_confirmed_at DATETIME NULL AFTER relationship_confirmed_by,
  ADD COLUMN first_published_at DATETIME NULL AFTER relationship_confirmed_at,
  ADD KEY ix_product_variant_relationship_source (relationship_source_id),
  ADD KEY ix_product_variant_relationship_by (relationship_confirmed_by),
  ADD CONSTRAINT fk_product_variant_relationship_source FOREIGN KEY (relationship_source_id) REFERENCES source (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_product_variant_relationship_by FOREIGN KEY (relationship_confirmed_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT ck_product_variant_relationship CHECK (relationship_confirmed_at IS NULL OR (relationship_confirmed_by IS NOT NULL AND relationship_source_id IS NOT NULL));

ALTER TABLE product
  ADD COLUMN first_published_at DATETIME NULL AFTER is_sample;

ALTER TABLE spelling
  ADD COLUMN confirmed_by BIGINT UNSIGNED NULL AFTER note,
  ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by,
  ADD KEY ix_spelling_confirmed_by (confirmed_by),
  ADD CONSTRAINT fk_spelling_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT ck_spelling_confirmed CHECK (confirmed_at IS NULL OR confirmed_by IS NOT NULL);

CREATE TABLE redirect (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  from_path VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  to_path VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NULL,
  status SMALLINT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_redirect_from (from_path),
  KEY ix_redirect_created_by (created_by),
  CONSTRAINT fk_redirect_created_by FOREIGN KEY (created_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_redirect_status CHECK ((status = 301 AND to_path IS NOT NULL) OR (status = 410 AND to_path IS NULL)),
  CONSTRAINT ck_redirect_paths CHECK (from_path LIKE '/%' AND (to_path IS NULL OR to_path LIKE '/%'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
