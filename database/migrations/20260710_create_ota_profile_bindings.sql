CREATE TABLE IF NOT EXISTS `ota_profile_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `system_hotel_id` int unsigned NOT NULL,
  `platform` varchar(20) NOT NULL,
  `profile_key_hash` char(64) NOT NULL,
  `binding_status` varchar(20) NOT NULL DEFAULT 'active',
  `bound_by` int unsigned DEFAULT NULL,
  `revoked_by` int unsigned DEFAULT NULL,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ota_profile_binding_key` (`platform`, `profile_key_hash`),
  KEY `idx_ota_profile_binding_scope` (`tenant_id`, `system_hotel_id`, `platform`, `binding_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OTA browser Profile tenant/hotel ownership binding; key stored as SHA-256 only';

INSERT IGNORE INTO `ota_profile_bindings`
  (`tenant_id`, `system_hotel_id`, `platform`, `profile_key_hash`, `binding_status`, `create_time`, `update_time`)
SELECT
  MIN(`scoped`.`tenant_id`),
  MIN(`scoped`.`system_hotel_id`),
  `scoped`.`platform`,
  SHA2(`scoped`.`profile_key`, 256),
  'active',
  NOW(),
  NOW()
FROM (
  SELECT
    `h`.`tenant_id`,
    `p`.`system_hotel_id`,
    LOWER(TRIM(`p`.`platform`)) AS `platform`,
    CASE
      WHEN LOWER(TRIM(`p`.`platform`)) = 'ctrip' THEN COALESCE(
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.profile_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.profileId')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.browser_profile_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.browserProfileId')), '')
      )
      WHEN LOWER(TRIM(`p`.`platform`)) = 'meituan' THEN COALESCE(
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.store_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.storeId')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.poi_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.poiId')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.profile_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`p`.`config_json`, '$.profileId')), '')
      )
      ELSE NULL
    END AS `profile_key`
  FROM `platform_data_sources` AS `p`
  INNER JOIN `hotels` AS `h` ON `h`.`id` = `p`.`system_hotel_id`
  WHERE LOWER(TRIM(`p`.`platform`)) IN ('ctrip', 'meituan')
    AND LOWER(TRIM(`p`.`ingestion_method`)) IN ('browser_profile', 'profile_browser')
    AND `p`.`enabled` = 1
    AND LOWER(TRIM(`p`.`status`)) <> 'disabled'
    AND JSON_VALID(`p`.`config_json`)
    AND `h`.`tenant_id` > 0
) AS `scoped`
WHERE `scoped`.`profile_key` REGEXP '^[A-Za-z0-9_-]{1,80}$'
GROUP BY `scoped`.`platform`, `scoped`.`profile_key`
HAVING COUNT(DISTINCT CONCAT(`scoped`.`tenant_id`, ':', `scoped`.`system_hotel_id`)) = 1;

UPDATE `platform_data_sources` AS `p`
LEFT JOIN `hotels` AS `h` ON `h`.`id` = `p`.`system_hotel_id`
SET
  `p`.`enabled` = 0,
  `p`.`status` = 'disabled',
  `p`.`last_sync_status` = 'blocked',
  `p`.`last_error` = 'orphan_system_hotel_scope',
  `p`.`update_time` = NOW()
WHERE LOWER(TRIM(`p`.`platform`)) IN ('ctrip', 'meituan')
  AND LOWER(TRIM(`p`.`ingestion_method`)) IN ('browser_profile', 'profile_browser')
  AND `h`.`id` IS NULL;
