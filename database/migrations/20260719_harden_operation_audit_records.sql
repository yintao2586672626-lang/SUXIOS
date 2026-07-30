-- Harden operation audit identity and lookup coverage.
-- This migration is idempotent, additive, and never deletes audit history.

ALTER TABLE `operation_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL COMMENT '可信租户ID，由系统酒店或用户范围解析' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_operation_logs_tenant_time` (`tenant_id`, `create_time`),
  ADD INDEX IF NOT EXISTS `idx_operation_logs_hotel_time` (`hotel_id`, `create_time`),
  ADD INDEX IF NOT EXISTS `idx_operation_logs_user_time` (`user_id`, `create_time`);

-- Repair rows whose system hotel is still present. Unknown rows remain unknown;
-- no tenant or hotel identity is manufactured from a default value.
UPDATE `operation_logs` audit_row
INNER JOIN `hotels` hotel ON hotel.`id` = audit_row.`hotel_id`
SET audit_row.`tenant_id` = hotel.`tenant_id`
WHERE hotel.`tenant_id` IS NOT NULL
  AND hotel.`tenant_id` > 0
  AND (audit_row.`tenant_id` IS NULL OR audit_row.`tenant_id` <> hotel.`tenant_id`);

-- Older competitor collector rows put competitor_hotel.id in hotel_id. Repair
-- only rows carrying a valid, structured store_id that resolves to a real
-- system hotel. The competitor target remains preserved in extra_data.
UPDATE `operation_logs` audit_row
INNER JOIN `hotels` store_hotel
  ON store_hotel.`id` = CAST(
    CASE
      WHEN JSON_VALID(audit_row.`extra_data`) = 1
      THEN JSON_UNQUOTE(JSON_EXTRACT(audit_row.`extra_data`, '$.store_id'))
      ELSE NULL
    END AS UNSIGNED
  )
SET audit_row.`hotel_id` = store_hotel.`id`,
    audit_row.`tenant_id` = store_hotel.`tenant_id`
WHERE audit_row.`module` = 'competitor'
  AND audit_row.`action` IN (
    'task',
    'task_denied',
    'report',
    'report_denied',
    'report_failed',
    'report_replayed'
  )
  AND JSON_VALID(audit_row.`extra_data`) = 1
  AND JSON_UNQUOTE(JSON_EXTRACT(audit_row.`extra_data`, '$.store_id')) REGEXP '^[1-9][0-9]*$'
  AND (
    audit_row.`hotel_id` IS NULL
    OR audit_row.`hotel_id` <> store_hotel.`id`
    OR audit_row.`tenant_id` IS NULL
    OR audit_row.`tenant_id` <> store_hotel.`tenant_id`
  );
