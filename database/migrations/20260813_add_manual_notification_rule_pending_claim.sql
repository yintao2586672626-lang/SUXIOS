-- Keep the already-applied business-rule migration immutable. These claim
-- fields were introduced later and therefore belong to a forward migration.

ALTER TABLE `manual_notification_rule_states`
  ADD COLUMN IF NOT EXISTS `pending_trigger_bucket` DECIMAL(9,4) DEFAULT NULL
    AFTER `highest_triggered_bucket`,
  ADD COLUMN IF NOT EXISTS `pending_dispatch_id` BIGINT UNSIGNED DEFAULT NULL
    AFTER `pending_trigger_bucket`,
  ADD COLUMN IF NOT EXISTS `pending_claimed_at` DATETIME DEFAULT NULL
    AFTER `pending_dispatch_id`;
