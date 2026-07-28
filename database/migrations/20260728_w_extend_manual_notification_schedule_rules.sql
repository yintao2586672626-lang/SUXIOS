-- Persist the minimum business rules required by the cloud notification timer.
-- Safety outcomes remain system-owned: missing facts block formal delivery,
-- missed windows are not backfilled, and unknown outcomes are never retried.

ALTER TABLE `manual_notifications`
  ADD COLUMN IF NOT EXISTS `business_date_rule` VARCHAR(24) NOT NULL DEFAULT 'today'
    COMMENT 'Resolve scheduled content against today or yesterday in Asia/Shanghai'
    AFTER `business_date`,
  ADD COLUMN IF NOT EXISTS `active_weekdays` VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7'
    COMMENT 'ISO weekday numbers eligible for dispatch'
    AFTER `planned_send_at`,
  ADD COLUMN IF NOT EXISTS `effective_from` DATE DEFAULT NULL
    COMMENT 'Optional first eligible scheduler date'
    AFTER `active_weekdays`,
  ADD COLUMN IF NOT EXISTS `effective_to` DATE DEFAULT NULL
    COMMENT 'Optional last eligible scheduler date'
    AFTER `effective_from`,
  ADD COLUMN IF NOT EXISTS `hourly_start_time` TIME NOT NULL DEFAULT '09:00:00'
    COMMENT 'First eligible hourly dispatch time'
    AFTER `effective_to`,
  ADD COLUMN IF NOT EXISTS `hourly_end_time` TIME NOT NULL DEFAULT '22:00:00'
    COMMENT 'Last eligible hourly dispatch time'
    AFTER `hourly_start_time`,
  ADD INDEX IF NOT EXISTS `idx_manual_notification_effective_schedule`
    (`enabled`, `schedule_status`, `effective_from`, `effective_to`);

UPDATE `manual_notifications`
SET `business_date_rule` = 'today'
WHERE `business_date_rule` NOT IN ('today', 'yesterday');

UPDATE `manual_notifications`
SET `active_weekdays` = '1,2,3,4,5,6,7'
WHERE `active_weekdays` IS NULL OR TRIM(`active_weekdays`) = '';

UPDATE `manual_notifications`
SET
  `hourly_start_time` = COALESCE(`hourly_start_time`, '09:00:00'),
  `hourly_end_time` = COALESCE(`hourly_end_time`, '22:00:00');
