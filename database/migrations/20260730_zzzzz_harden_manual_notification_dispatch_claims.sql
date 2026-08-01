ALTER TABLE `manual_notification_schedule_dispatches`
  ADD COLUMN IF NOT EXISTS `tested_plan_fingerprint` CHAR(64) DEFAULT NULL
    AFTER `payload_fingerprint`,
  ADD INDEX IF NOT EXISTS `idx_manual_notification_preparation_retry`
    (`status`, `next_retry_at`, `attempt_count`);
