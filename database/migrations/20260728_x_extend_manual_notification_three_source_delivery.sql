-- Make each persisted notification plan independently bind one trusted source
-- scope, an allowlisted content selection, and an optional minute interval.
-- Existing operating-daily plans remain combined and keep their previous full
-- content when content_sections is empty.

ALTER TABLE `manual_notifications`
  ADD COLUMN IF NOT EXISTS `source_scope` VARCHAR(32) NOT NULL DEFAULT 'combined'
    COMMENT 'combined, ctrip, meituan or dingdandao_pms'
    AFTER `template_type`,
  ADD COLUMN IF NOT EXISTS `content_sections` VARCHAR(512) NOT NULL DEFAULT ''
    COMMENT 'Comma-separated allowlisted sections rendered for this source plan'
    AFTER `source_scope`,
  ADD COLUMN IF NOT EXISTS `interval_minutes` SMALLINT UNSIGNED DEFAULT NULL
    COMMENT 'Minute cadence for interval_minutes trigger, 5 through 1440'
    AFTER `trigger_type`,
  ADD INDEX IF NOT EXISTS `idx_manual_notification_source_schedule`
    (`tenant_id`, `hotel_id`, `source_scope`, `enabled`, `schedule_status`);

UPDATE `manual_notifications`
SET `source_scope` = 'combined'
WHERE `source_scope` NOT IN ('combined', 'ctrip', 'meituan', 'dingdandao_pms');

UPDATE `manual_notifications`
SET `interval_minutes` = NULL
WHERE `trigger_type` <> 'interval_minutes'
   OR `interval_minutes` < 5
   OR `interval_minutes` > 1440;
