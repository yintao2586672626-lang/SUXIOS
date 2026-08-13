-- Add package-style business-condition rules to the existing notification
-- scheduler. Time windows remain the polling trigger; the rule state advances
-- only after an exact successful delivery receipt.

ALTER TABLE `manual_notifications`
  ADD COLUMN IF NOT EXISTS `condition_type` VARCHAR(32) NOT NULL DEFAULT 'always'
    COMMENT 'always, occupancy_ladder or full_house'
    AFTER `hourly_end_time`,
  ADD COLUMN IF NOT EXISTS `condition_threshold` DECIMAL(7,2) DEFAULT NULL
    COMMENT 'First occupancy bucket for occupancy_ladder'
    AFTER `condition_type`,
  ADD COLUMN IF NOT EXISTS `condition_step` DECIMAL(7,2) DEFAULT NULL
    COMMENT 'Next occupancy bucket step for occupancy_ladder'
    AFTER `condition_threshold`;

ALTER TABLE `manual_notification_schedule_dispatches`
  ADD COLUMN IF NOT EXISTS `condition_rule_fingerprint` CHAR(64) DEFAULT NULL
    COMMENT 'Rule identity used to rebuild success-committed dedup state'
    AFTER `tested_plan_fingerprint`,
  ADD COLUMN IF NOT EXISTS `condition_trigger_bucket` DECIMAL(9,4) DEFAULT NULL
    COMMENT 'Prepared business-rule level for this exact delivery'
    AFTER `condition_rule_fingerprint`,
  ADD COLUMN IF NOT EXISTS `condition_observed_value` DECIMAL(12,4) DEFAULT NULL
    COMMENT 'Verified metric observed before this delivery'
    AFTER `condition_trigger_bucket`,
  ADD INDEX IF NOT EXISTS `idx_manual_notification_rule_receipt`
    (`notification_id`, `business_date`, `condition_rule_fingerprint`, `status`);

UPDATE `manual_notifications`
SET
  `condition_type` = 'always',
  `condition_threshold` = NULL,
  `condition_step` = NULL
WHERE `condition_type` NOT IN ('always', 'occupancy_ladder', 'full_house');

CREATE TABLE IF NOT EXISTS `manual_notification_rule_states` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `business_date` DATE NOT NULL,
  `condition_type` VARCHAR(32) NOT NULL,
  `rule_fingerprint` CHAR(64) NOT NULL,
  `highest_triggered_bucket` DECIMAL(9,4) DEFAULT NULL,
  `last_observed_value` DECIMAL(12,4) DEFAULT NULL,
  `last_observed_at` DATETIME DEFAULT NULL,
  `last_triggered_at` DATETIME DEFAULT NULL,
  `last_dispatch_id` BIGINT UNSIGNED DEFAULT NULL,
  `create_time` DATETIME NOT NULL,
  `update_time` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manual_notification_rule_day`
    (`notification_id`, `business_date`, `rule_fingerprint`),
  KEY `idx_manual_notification_rule_scope`
    (`tenant_id`, `hotel_id`, `business_date`, `condition_type`),
  KEY `idx_manual_notification_rule_dispatch` (`last_dispatch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-plan business-rule observations and success-committed dedup buckets';
