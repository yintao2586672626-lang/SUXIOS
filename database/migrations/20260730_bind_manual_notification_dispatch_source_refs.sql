-- Bind every notification delivery receipt to the exact saved PMS/OTA rows
-- used to render it. Only sanitized identifiers are stored; no credentials or
-- raw provider payloads enter the dispatch ledger.
ALTER TABLE `manual_notification_schedule_dispatches`
  ADD COLUMN IF NOT EXISTS `source_snapshot_refs_json` JSON DEFAULT NULL
    COMMENT 'Sanitized exact-date PMS/OTA source row references'
    AFTER `payload_fingerprint`,
  ADD COLUMN IF NOT EXISTS `source_snapshot_fingerprint` CHAR(64) DEFAULT NULL
    COMMENT 'SHA-256 of canonical source_snapshot_refs_json'
    AFTER `source_snapshot_refs_json`;
