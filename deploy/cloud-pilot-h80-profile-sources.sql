START TRANSACTION;

INSERT INTO `platform_data_sources` (
  `tenant_id`, `system_hotel_id`, `user_id`, `name`, `platform`, `data_type`,
  `ingestion_method`, `status`, `enabled`, `config_json`, `secret_json`,
  `last_sync_time`, `last_sync_status`, `last_error`, `created_by`, `updated_by`,
  `create_time`, `update_time`
)
SELECT
  1, 5, 1, '敦煌漠蓝新-携程-本地Profile云桥', 'ctrip', 'traffic',
  'cloud_bundle', 'ready', 1,
  JSON_OBJECT(
    'pilot', 'hotel80',
    'source_system_hotel_id', 80,
    'source_data_source_id', 25,
    'source_ingestion_method', 'browser_profile',
    'credential_boundary', 'credential_free_bundle_only'
  ),
  NULL, NULL, 'pending', NULL, 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `platform_data_sources`
  WHERE `tenant_id` = 1
    AND `system_hotel_id` = 5
    AND `platform` = 'ctrip'
    AND `ingestion_method` = 'cloud_bundle'
    AND JSON_UNQUOTE(JSON_EXTRACT(`config_json`, '$.source_system_hotel_id')) = '80'
    AND JSON_UNQUOTE(JSON_EXTRACT(`config_json`, '$.source_data_source_id')) = '25'
);

INSERT INTO `platform_data_sources` (
  `tenant_id`, `system_hotel_id`, `user_id`, `name`, `platform`, `data_type`,
  `ingestion_method`, `status`, `enabled`, `config_json`, `secret_json`,
  `last_sync_time`, `last_sync_status`, `last_error`, `created_by`, `updated_by`,
  `create_time`, `update_time`
)
SELECT
  1, 5, 1, '敦煌漠蓝新-美团-本地Profile云桥', 'meituan', 'business',
  'cloud_bundle', 'ready', 1,
  JSON_OBJECT(
    'pilot', 'hotel80',
    'source_system_hotel_id', 80,
    'source_data_source_id', 68,
    'source_ingestion_method', 'browser_profile',
    'credential_boundary', 'credential_free_bundle_only'
  ),
  NULL, NULL, 'pending', NULL, 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `platform_data_sources`
  WHERE `tenant_id` = 1
    AND `system_hotel_id` = 5
    AND `platform` = 'meituan'
    AND `ingestion_method` = 'cloud_bundle'
    AND JSON_UNQUOTE(JSON_EXTRACT(`config_json`, '$.source_system_hotel_id')) = '80'
    AND JSON_UNQUOTE(JSON_EXTRACT(`config_json`, '$.source_data_source_id')) = '68'
);

-- The first onboarding pass used manual/older sources 67 and 101. Preserve
-- their rows and audit history, but remove them from the active health scope
-- once the explicit Profile bridge replacements exist.
UPDATE `platform_data_sources` legacy_source
INNER JOIN `platform_data_sources` replacement
  ON replacement.`id` = 3
  AND replacement.`tenant_id` = 1
  AND replacement.`system_hotel_id` = 5
  AND replacement.`platform` = 'ctrip'
  AND replacement.`enabled` = 1
SET
  legacy_source.`enabled` = 0,
  legacy_source.`status` = 'disabled',
  legacy_source.`last_sync_status` = 'superseded_by_profile_bridge',
  legacy_source.`config_json` = JSON_SET(
    COALESCE(legacy_source.`config_json`, JSON_OBJECT()),
    '$.superseded_by_destination_data_source_id', 3,
    '$.superseded_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
  ),
  legacy_source.`update_time` = NOW()
WHERE legacy_source.`id` = 1
  AND legacy_source.`tenant_id` = 1
  AND legacy_source.`system_hotel_id` = 5
  AND legacy_source.`platform` = 'ctrip';

UPDATE `platform_data_sources` legacy_source
INNER JOIN `platform_data_sources` replacement
  ON replacement.`id` = 4
  AND replacement.`tenant_id` = 1
  AND replacement.`system_hotel_id` = 5
  AND replacement.`platform` = 'meituan'
  AND replacement.`enabled` = 1
SET
  legacy_source.`enabled` = 0,
  legacy_source.`status` = 'disabled',
  legacy_source.`last_sync_status` = 'superseded_by_profile_bridge',
  legacy_source.`config_json` = JSON_SET(
    COALESCE(legacy_source.`config_json`, JSON_OBJECT()),
    '$.superseded_by_destination_data_source_id', 4,
    '$.superseded_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
  ),
  legacy_source.`update_time` = NOW()
WHERE legacy_source.`id` = 2
  AND legacy_source.`tenant_id` = 1
  AND legacy_source.`system_hotel_id` = 5
  AND legacy_source.`platform` = 'meituan';

COMMIT;

SELECT
  `id`, `tenant_id`, `system_hotel_id`, `platform`, `data_type`, `ingestion_method`,
  `status`, `enabled`,
  JSON_UNQUOTE(JSON_EXTRACT(`config_json`, '$.source_data_source_id')) AS `source_data_source_id`,
  CASE WHEN `secret_json` IS NULL THEN 'absent' ELSE 'present' END AS `secret_state`
FROM `platform_data_sources`
WHERE `tenant_id` = 1
  AND `system_hotel_id` = 5
  AND `ingestion_method` = 'cloud_bundle'
ORDER BY `id`;
