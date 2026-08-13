-- Atomically deduplicate active goal-monitor alerts. Existing duplicate active
-- alerts remain as history, while the oldest row becomes the canonical target
-- for all future exact-identity updates.
ALTER TABLE `operation_alerts`
  ADD COLUMN IF NOT EXISTS `monitor_dedupe_key` CHAR(64) DEFAULT NULL
    COMMENT 'unique identity for active goal-monitor alert' AFTER `alert_type`;

UPDATE `operation_alerts` AS `alert`
INNER JOIN (
  SELECT
    MIN(`id`) AS `canonical_id`,
    SHA2(CONCAT_WS('|',
      COALESCE(`tenant_id`, 0),
      `hotel_id`,
      `alert_type`,
      `source`,
      COALESCE(DATE_FORMAT(`related_date`, '%Y-%m-%d'), '')
    ), 256) AS `dedupe_key`
  FROM `operation_alerts`
  WHERE `source` = 'goal_intervention_monitor'
    AND `deleted_at` IS NULL
  GROUP BY `tenant_id`, `hotel_id`, `alert_type`, `source`, `related_date`
) AS `canonical`
  ON `canonical`.`canonical_id` = `alert`.`id`
SET `alert`.`monitor_dedupe_key` = `canonical`.`dedupe_key`
WHERE `alert`.`monitor_dedupe_key` IS NULL;

ALTER TABLE `operation_alerts`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_operation_alerts_monitor_dedupe` (`monitor_dedupe_key`);
