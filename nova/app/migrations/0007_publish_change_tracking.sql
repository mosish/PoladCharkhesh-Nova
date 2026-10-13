-- 0007 Exact change tracking per release (Phase 3 step 9 follow-up, 05 §7, A7).
-- Replaces the 0006 look-back window, which could miss a change whose transaction stayed open while
-- more than its window of later changes committed.
-- - publish_change: one row per content audit event, naming the first live release whose snapshot
--   contained it (append-only; audit_event itself stays append-only and unchanged). Every release
--   records the content changes its consistent snapshot sees that no earlier release recorded.
--   A change is in release R exactly when it was recorded by R or an earlier release: releases are
--   numbered in snapshot order (one publish at a time), and a committed change stays visible.
--   This holds after a rollback (a later release's changes are then unpublished again) and for
--   changes still open at a snapshot (they are recorded by the next release that sees them).
-- - publish_record.snapshot_audit_id stays: non-NULL marks a release published with tracking.
--   snapshot_audit_gaps (0006) is no longer used and is dropped.

CREATE TABLE publish_change (
  audit_event_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  publish_record_id BIGINT UNSIGNED NOT NULL,
  KEY ix_publish_change_record (publish_record_id),
  CONSTRAINT fk_publish_change_event FOREIGN KEY (audit_event_id) REFERENCES audit_event (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_publish_change_record FOREIGN KEY (publish_record_id) REFERENCES publish_record (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

ALTER TABLE publish_record DROP COLUMN snapshot_audit_gaps;
