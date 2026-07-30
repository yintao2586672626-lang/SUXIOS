CREATE TABLE IF NOT EXISTS `tenants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `plan_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenants_status_plan` (`status`, `plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SaaS tenants';

ALTER TABLE `hotels`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT 'Owning SaaS tenant' AFTER `id`;

-- Existing positive legacy tenant keys are preserved so every tenant-scoped
-- table keeps its current identity. This creates an explicit tenant mapping;
-- it is not permission to infer a tenant from a hotel at runtime.
INSERT INTO `tenants` (`id`, `name`, `status`, `plan_id`, `created_at`, `updated_at`)
SELECT
  hotel.`tenant_id`,
  COALESCE(NULLIF(MIN(TRIM(hotel.`name`)), ''), CONCAT('Legacy tenant ', hotel.`tenant_id`)),
  1,
  NULL,
  COALESCE(MIN(hotel.`create_time`), CURRENT_TIMESTAMP),
  CURRENT_TIMESTAMP
FROM `hotels` hotel
WHERE hotel.`tenant_id` IS NOT NULL AND hotel.`tenant_id` > 0
GROUP BY hotel.`tenant_id`
ON DUPLICATE KEY UPDATE
  `updated_at` = VALUES(`updated_at`);

-- Hotels without a historical tenant receive new tenant identities allocated
-- above both existing tenant and hotel key ranges. The temporary map makes the
-- backfill deterministic without equating the two identifiers.
DROP TEMPORARY TABLE IF EXISTS `tmp_hotel_tenant_backfill`;
CREATE TEMPORARY TABLE `tmp_hotel_tenant_backfill` (
  `hotel_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  PRIMARY KEY (`hotel_id`),
  UNIQUE KEY `uk_tmp_hotel_tenant` (`tenant_id`)
) ENGINE=MEMORY;

SET @suxios_next_tenant_id := GREATEST(
  (SELECT COALESCE(MAX(`id`), 0) FROM `tenants`),
  (SELECT COALESCE(MAX(`id`), 0) FROM `hotels`)
);

INSERT INTO `tmp_hotel_tenant_backfill` (`hotel_id`, `tenant_id`)
SELECT hotel.`id`, (@suxios_next_tenant_id := @suxios_next_tenant_id + 1)
FROM `hotels` hotel
WHERE hotel.`tenant_id` IS NULL OR hotel.`tenant_id` = 0
ORDER BY hotel.`id`;

INSERT INTO `tenants` (`id`, `name`, `status`, `plan_id`, `created_at`, `updated_at`)
SELECT
  mapping.`tenant_id`,
  COALESCE(NULLIF(TRIM(hotel.`name`), ''), CONCAT('Legacy hotel tenant ', mapping.`hotel_id`)),
  CASE WHEN hotel.`status` = 0 THEN 0 ELSE 1 END,
  NULL,
  COALESCE(hotel.`create_time`, CURRENT_TIMESTAMP),
  CURRENT_TIMESTAMP
FROM `tmp_hotel_tenant_backfill` mapping
INNER JOIN `hotels` hotel ON hotel.`id` = mapping.`hotel_id`
ON DUPLICATE KEY UPDATE
  `updated_at` = VALUES(`updated_at`);

UPDATE `hotels` hotel
INNER JOIN `tmp_hotel_tenant_backfill` mapping ON mapping.`hotel_id` = hotel.`id`
SET hotel.`tenant_id` = mapping.`tenant_id`
WHERE hotel.`tenant_id` IS NULL OR hotel.`tenant_id` = 0;

-- Core identity rows follow the hotel's explicit tenant foreign key only as a
-- one-time compatibility repair. Runtime authorization must use TenantContext.
UPDATE `users` user_row
INNER JOIN `hotels` hotel ON hotel.`id` = user_row.`hotel_id`
SET user_row.`tenant_id` = hotel.`tenant_id`
WHERE user_row.`hotel_id` IS NOT NULL
  AND user_row.`hotel_id` > 0
  AND (user_row.`tenant_id` IS NULL OR user_row.`tenant_id` = 0 OR user_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `user_hotel_permissions` permission_row
INNER JOIN `hotels` hotel ON hotel.`id` = permission_row.`hotel_id`
SET permission_row.`tenant_id` = hotel.`tenant_id`
WHERE permission_row.`hotel_id` > 0
  AND (permission_row.`tenant_id` IS NULL OR permission_row.`tenant_id` = 0 OR permission_row.`tenant_id` <> hotel.`tenant_id`);

-- The 20260529 compatibility migration populated these core business rows
-- with hotel identifiers. Re-map only rows with an explicit hotel match to
-- the hotel's persisted tenant foreign key. INNER JOIN deliberately preserves
-- unmatched historical rows for later data repair instead of deleting or
-- assigning an invented tenant.
UPDATE `daily_reports` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `monthly_tasks` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `online_daily_data` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`system_hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`system_hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_logs` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `platform_data_sources` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`system_hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`system_hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `platform_data_sync_tasks` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`system_hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`system_hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `platform_data_raw_records` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`system_hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`system_hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `platform_data_sync_logs` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`system_hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`system_hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `agent_configs` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `agent_logs` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `demand_forecasts` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `price_suggestions` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

ALTER TABLE `hotels`
  MODIFY COLUMN `tenant_id` int unsigned NOT NULL COMMENT 'Owning SaaS tenant',
  ADD INDEX IF NOT EXISTS `idx_hotels_tenant` (`tenant_id`, `status`);

SET @suxios_hotels_tenant_fk_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`TABLE_CONSTRAINTS`
  WHERE `CONSTRAINT_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'hotels'
    AND `CONSTRAINT_NAME` = 'fk_hotels_tenant'
    AND `CONSTRAINT_TYPE` = 'FOREIGN KEY'
);
SET @suxios_hotels_tenant_fk_sql := IF(
  @suxios_hotels_tenant_fk_exists > 0,
  'SELECT 1',
  'ALTER TABLE `hotels` ADD CONSTRAINT `fk_hotels_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT'
);
PREPARE suxios_hotels_tenant_fk_stmt FROM @suxios_hotels_tenant_fk_sql;
EXECUTE suxios_hotels_tenant_fk_stmt;
DEALLOCATE PREPARE suxios_hotels_tenant_fk_stmt;

DROP TEMPORARY TABLE IF EXISTS `tmp_hotel_tenant_backfill`;
