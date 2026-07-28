-- A dispatch row is the idempotent logical delivery. Attempts are append-only
-- evidence for explicit retries; a sent or ambiguous logical delivery is never
-- silently sent again.
ALTER TABLE `manual_notification_schedule_dispatches`
  ADD COLUMN IF NOT EXISTS `request_kind` varchar(32) NOT NULL DEFAULT 'scheduled' AFTER `trigger_type`,
  ADD COLUMN IF NOT EXISTS `business_date` date DEFAULT NULL AFTER `request_kind`,
  ADD COLUMN IF NOT EXISTS `payload_fingerprint` char(64) DEFAULT NULL AFTER `business_date`,
  ADD COLUMN IF NOT EXISTS `operating_target_record_id` bigint unsigned DEFAULT NULL AFTER `payload_fingerprint`,
  ADD COLUMN IF NOT EXISTS `snapshot_revision_no` int unsigned DEFAULT NULL AFTER `operating_target_record_id`,
  ADD COLUMN IF NOT EXISTS `render_contract_version` varchar(48) DEFAULT NULL AFTER `snapshot_revision_no`,
  ADD COLUMN IF NOT EXISTS `payload_snapshot_json` json DEFAULT NULL AFTER `render_contract_version`,
  ADD COLUMN IF NOT EXISTS `attempt_count` int unsigned NOT NULL DEFAULT 0 AFTER `payload_snapshot_json`,
  ADD COLUMN IF NOT EXISTS `max_attempts` int unsigned NOT NULL DEFAULT 3 AFTER `attempt_count`,
  ADD COLUMN IF NOT EXISTS `next_retry_at` datetime DEFAULT NULL AFTER `max_attempts`,
  ADD COLUMN IF NOT EXISTS `last_attempt_at` datetime DEFAULT NULL AFTER `next_retry_at`,
  ADD COLUMN IF NOT EXISTS `response_reference` varchar(120) DEFAULT NULL AFTER `last_attempt_at`;

CREATE TABLE IF NOT EXISTS `manual_notification_dispatch_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dispatch_id` bigint unsigned NOT NULL,
  `notification_id` bigint unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `attempt_no` int unsigned NOT NULL,
  `request_kind` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL,
  `result_code` varchar(64) NOT NULL,
  `result_message` varchar(255) DEFAULT NULL,
  `payload_fingerprint` char(64) DEFAULT NULL,
  `response_reference` varchar(120) DEFAULT NULL,
  `attempted_at` datetime NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manual_notification_dispatch_attempt` (`dispatch_id`, `attempt_no`),
  KEY `idx_manual_notification_attempt_scope`
    (`tenant_id`, `hotel_id`, `notification_id`, `attempted_at`),
  CONSTRAINT `fk_manual_notification_attempt_dispatch`
    FOREIGN KEY (`dispatch_id`) REFERENCES `manual_notification_schedule_dispatches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only delivery attempts without credentials or raw provider responses';
