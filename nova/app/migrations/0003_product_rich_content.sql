-- 0003 Rich content on Products (Phase 3 step 6, 05 §27, D22/E-8).
-- One Nova rich-content document per language, stored as JSON text and validated by
-- Nova's schema (PHP) on every save and at publish; the database stores it opaquely
-- (no JSON functions, D12).

ALTER TABLE product
  ADD COLUMN description_fa LONGTEXT NULL AFTER review_note,
  ADD COLUMN description_en LONGTEXT NULL AFTER description_fa;
