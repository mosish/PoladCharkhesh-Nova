-- 0001 Schema v1: catalogue core (05 §9, D20/E-2; schema review and owner decisions 2026-10-06).
-- Conservative MariaDB subset (D12): tables, keys, CHECK constraints and stored generated
-- columns only. Normalised keys and slugs compare exactly (utf8mb4_nopad_bin), like the
-- PHP/JS normaliser. "Exactly one of" ownership uses one nullable FK per target plus a CHECK,
-- never a polymorphic owner_type/owner_id pair. Times are UTC (every connection sets +00:00).

CREATE TABLE app_user (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  role ENUM('owner','editor') NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app_user_email (email),
  CONSTRAINT ck_app_user_email CHECK (email LIKE '_%@_%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE family (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  name_fa VARCHAR(120) NOT NULL,
  name_en VARCHAR(120) NOT NULL,
  short_fa VARCHAR(60) NOT NULL,
  short_en VARCHAR(60) NOT NULL,
  definition_fa TEXT NOT NULL,
  definition_en TEXT NOT NULL,
  glyph VARCHAR(64) NULL,
  drawing_template VARCHAR(64) NULL,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_family_slug (slug),
  CONSTRAINT ck_family_slug CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE document (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL,
  title_fa VARCHAR(200) NULL,
  title_en VARCHAR(200) NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_document_sha256 (sha256),
  CONSTRAINT ck_document_sha256 CHECK (sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE source (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  publisher VARCHAR(160) NULL,
  edition VARCHAR(60) NULL,
  url VARCHAR(2048) NULL,
  document_id BIGINT UNSIGNED NULL,
  note TEXT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_source_document (document_id),
  CONSTRAINT fk_source_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_source_citable CHECK (url IS NOT NULL OR document_id IS NOT NULL),
  CONSTRAINT ck_source_url CHECK (url IS NULL OR url REGEXP '^https?://')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE brand (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_brand_slug (slug),
  CONSTRAINT ck_brand_slug CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'),
  CONSTRAINT ck_brand_verified CHECK (verified IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- The stable family/designation entity (D20). base_listed alone says whether the plain
-- designation is a listed item. No column ever designates a "primary" variant.
CREATE TABLE product (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  family_id BIGINT UNSIGNED NOT NULL,
  designation VARCHAR(64) NOT NULL,
  slug VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  base_listed TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  verification ENUM('pending','verified') NOT NULL DEFAULT 'pending',
  verified_at DATETIME NULL,
  verified_by BIGINT UNSIGNED NULL,
  show_in_explorer TINYINT(1) NOT NULL DEFAULT 1,
  relationship_review TINYINT(1) NOT NULL DEFAULT 0,
  review_note TEXT NULL,
  is_sample TINYINT(1) NOT NULL DEFAULT 0,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_slug (slug),
  KEY ix_product_family (family_id),
  KEY ix_product_verified_by (verified_by),
  CONSTRAINT fk_product_family FOREIGN KEY (family_id) REFERENCES family (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_product_verified_by FOREIGN KEY (verified_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_product_flags CHECK (base_listed IN (0, 1) AND show_in_explorer IN (0, 1) AND relationship_review IN (0, 1) AND is_sample IN (0, 1)),
  CONSTRAINT ck_product_verified CHECK (verification = 'pending' OR (verified_at IS NOT NULL AND verified_by IS NOT NULL)),
  CONSTRAINT ck_product_slug CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'),
  CONSTRAINT ck_product_designation CHECK (CHAR_LENGTH(TRIM(designation)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- A distinct designation of one Product, with its own listing, values, documents and
-- inquiry identity. Created only for a verified relationship (05 §9).
CREATE TABLE product_variant (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  designation VARCHAR(64) NOT NULL,
  slug VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  listed TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  verification ENUM('pending','verified') NOT NULL DEFAULT 'pending',
  verified_at DATETIME NULL,
  verified_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_variant_slug (slug),
  KEY ix_product_variant_product (product_id, sort),
  KEY ix_product_variant_verified_by (verified_by),
  CONSTRAINT fk_product_variant_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_product_variant_verified_by FOREIGN KEY (verified_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_product_variant_listed CHECK (listed IN (0, 1)),
  CONSTRAINT ck_product_variant_verified CHECK (verification = 'pending' OR (verified_at IS NOT NULL AND verified_by IS NOT NULL)),
  CONSTRAINT ck_product_variant_slug CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'),
  CONSTRAINT ck_product_variant_designation CHECK (CHAR_LENGTH(TRIM(designation)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- An Owner-recorded alternative writing of exactly one Product or variant.
CREATE TABLE spelling (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NULL,
  variant_id BIGINT UNSIGNED NULL,
  spelling VARCHAR(64) NOT NULL,
  equivalence ENUM('writing','brand') NOT NULL DEFAULT 'writing',
  source_id BIGINT UNSIGNED NULL,
  note TEXT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_spelling_product (product_id),
  KEY ix_spelling_variant (variant_id),
  KEY ix_spelling_source (source_id),
  CONSTRAINT fk_spelling_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_spelling_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_spelling_source FOREIGN KEY (source_id) REFERENCES source (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_spelling_one_target CHECK ((product_id IS NOT NULL) + (variant_id IS NOT NULL) = 1),
  CONSTRAINT ck_spelling_brand_sourced CHECK (equivalence <> 'brand' OR source_id IS NOT NULL),
  CONSTRAINT ck_spelling_text CHECK (CHAR_LENGTH(TRIM(spelling)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- One namespace of normalised designation keys across Products, variants and spellings.
-- A UNIQUE index cannot span tables, so every key lives here; each record owns exactly one row.
CREATE TABLE designation_key (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  norm_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  variant_id BIGINT UNSIGNED NULL,
  spelling_id BIGINT UNSIGNED NULL,
  owner_ref VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin
    AS (COALESCE(CONCAT('p:', product_id), CONCAT('v:', variant_id), CONCAT('s:', spelling_id))) STORED,
  UNIQUE KEY uq_designation_key_norm (norm_key),
  UNIQUE KEY uq_designation_key_owner (owner_ref),
  KEY ix_designation_key_product (product_id),
  KEY ix_designation_key_variant (variant_id),
  KEY ix_designation_key_spelling (spelling_id),
  CONSTRAINT fk_designation_key_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_designation_key_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_designation_key_spelling FOREIGN KEY (spelling_id) REFERENCES spelling (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT ck_designation_key_one_owner CHECK ((product_id IS NOT NULL) + (variant_id IS NOT NULL) + (spelling_id IS NOT NULL) = 1),
  CONSTRAINT ck_designation_key_not_empty CHECK (CHAR_LENGTH(norm_key) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE spec_key (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  family_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label_fa VARCHAR(120) NOT NULL,
  label_en VARCHAR(120) NOT NULL,
  unit VARCHAR(16) NULL,
  value_type ENUM('number','text') NOT NULL,
  is_core TINYINT(1) NOT NULL DEFAULT 0,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_spec_key_family_code (family_id, code),
  CONSTRAINT fk_spec_key_family FOREIGN KEY (family_id) REFERENCES family (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_spec_key_code CHECK (code REGEXP '^[A-Za-z][A-Za-z0-9_]*$'),
  CONSTRAINT ck_spec_key_core CHECK (is_core IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- One value of exactly one designation: the base designation itself (product_id) or one
-- variant (variant_id). Nothing is inherited or shared between them (D20).
CREATE TABLE spec_value (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NULL,
  variant_id BIGINT UNSIGNED NULL,
  designation_ref VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin
    AS (COALESCE(CONCAT('p:', product_id), CONCAT('v:', variant_id))) STORED,
  spec_key_id BIGINT UNSIGNED NOT NULL,
  value_text VARCHAR(255) NOT NULL,
  value_num DECIMAL(18,6) NULL,
  unit VARCHAR(16) NULL,
  status ENUM('pending','verified') NOT NULL DEFAULT 'pending',
  source_id BIGINT UNSIGNED NULL,
  source_locator VARCHAR(255) NULL,
  checked_on DATE NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_spec_value_designation_key (designation_ref, spec_key_id),
  KEY ix_spec_value_product (product_id),
  KEY ix_spec_value_variant (variant_id),
  KEY ix_spec_value_key (spec_key_id),
  KEY ix_spec_value_source (source_id),
  KEY ix_spec_value_updated_by (updated_by),
  CONSTRAINT fk_spec_value_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_spec_value_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_spec_value_key FOREIGN KEY (spec_key_id) REFERENCES spec_key (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_spec_value_source FOREIGN KEY (source_id) REFERENCES source (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_spec_value_updated_by FOREIGN KEY (updated_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_spec_value_one_designation CHECK ((product_id IS NOT NULL) + (variant_id IS NOT NULL) = 1),
  CONSTRAINT ck_spec_value_verified_sourced CHECK (status = 'pending' OR (source_id IS NOT NULL AND checked_on IS NOT NULL)),
  CONSTRAINT ck_spec_value_text CHECK (CHAR_LENGTH(TRIM(value_text)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE document_link (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  variant_id BIGINT UNSIGNED NULL,
  designation_ref VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin
    AS (COALESCE(CONCAT('p:', product_id), CONCAT('v:', variant_id))) STORED,
  language ENUM('fa','en','both') NOT NULL,
  role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'document',
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_document_link (document_id, designation_ref, language),
  KEY ix_document_link_product (product_id),
  KEY ix_document_link_variant (variant_id),
  CONSTRAINT fk_document_link_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_document_link_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_document_link_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT ck_document_link_one_designation CHECK ((product_id IS NOT NULL) + (variant_id IS NOT NULL) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Append-only history. Deliberately no FK to the audited entity: history must outlive deletions.
CREATE TABLE audit_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  entity_label VARCHAR(255) NULL,
  before_text LONGTEXT NULL,
  after_text LONGTEXT NULL,
  KEY ix_audit_event_entity (entity_type, entity_id),
  KEY ix_audit_event_time (occurred_at),
  KEY ix_audit_event_user (user_id),
  CONSTRAINT fk_audit_event_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Admin-editable settings. Never secrets: those stay in config.php.
CREATE TABLE setting (
  setting_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  value_text TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  KEY ix_setting_updated_by (updated_by),
  CONSTRAINT fk_setting_updated_by FOREIGN KEY (updated_by) REFERENCES app_user (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
