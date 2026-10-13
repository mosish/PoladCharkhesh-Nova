-- 0006 Where each release's snapshot stands in the audit trail (Phase 3 step 9 follow-up, 05 §7, A7).
-- "Unpublished changes" are the content changes the live release does not contain. That is decided
-- against the snapshot the release was built from, not against when it went live, so the answer stays
-- right after a rollback (went_live_at moves) and for edits saved while a release was being built.
-- - snapshot_audit_id: the newest audit_event id visible in the release's consistent snapshot.
-- - snapshot_audit_gaps: lower ids (JSON list) not visible then: transactions still open at the
--   snapshot, which may commit later. Ids that never commit simply never appear.
-- Releases published before this migration keep NULL and fall back to their finished_at time.

ALTER TABLE publish_record
  ADD COLUMN snapshot_audit_id BIGINT UNSIGNED NULL AFTER manifest_sha256,
  ADD COLUMN snapshot_audit_gaps TEXT NULL AFTER snapshot_audit_id;
