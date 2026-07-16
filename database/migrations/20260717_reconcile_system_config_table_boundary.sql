-- system_config is the canonical store for public/general application settings.
-- system_configs is the legacy OTA metadata store. Remove only duplicates whose
-- authoritative value is already present or whose obsolete side is empty.

DELETE general_copy
FROM `system_config` AS general_copy
INNER JOIN `system_configs` AS ota_copy
  ON ota_copy.`config_key` COLLATE utf8mb4_unicode_ci
    = general_copy.`config_key` COLLATE utf8mb4_unicode_ci
WHERE general_copy.`config_key` IN ('ctrip_config_list', 'meituan_config_list')
  AND (
    LOWER(TRIM(COALESCE(general_copy.`config_value`, ''))) IN ('', '[]', '{}', 'null')
    OR COALESCE(general_copy.`config_value`, '') COLLATE utf8mb4_unicode_ci
      = COALESCE(ota_copy.`config_value`, '') COLLATE utf8mb4_unicode_ci
  );

DELETE ota_copy
FROM `system_configs` AS ota_copy
INNER JOIN `system_config` AS general_copy
  ON general_copy.`config_key` COLLATE utf8mb4_unicode_ci
    = ota_copy.`config_key` COLLATE utf8mb4_unicode_ci
WHERE ota_copy.`config_key` NOT IN ('ctrip_config_list', 'meituan_config_list')
  AND ota_copy.`config_key` NOT LIKE 'ctrip\_%'
  AND ota_copy.`config_key` NOT LIKE 'meituan\_%'
  AND ota_copy.`config_key` NOT LIKE 'ota\_%'
  AND ota_copy.`config_key` NOT LIKE 'data\_config\_%'
  AND ota_copy.`config_key` NOT LIKE 'online\_data\_cookies\_%'
  AND COALESCE(ota_copy.`config_value`, '') COLLATE utf8mb4_unicode_ci
    = COALESCE(general_copy.`config_value`, '') COLLATE utf8mb4_unicode_ci;

DELETE FROM `system_config`
WHERE (
    `config_key` IN ('ctrip_config_list', 'meituan_config_list')
    OR `config_key` LIKE 'data\_config\_%'
    OR `config_key` LIKE 'online\_data\_cookies\_%'
  )
  AND LOWER(TRIM(COALESCE(`config_value`, ''))) IN ('', '[]', '{}', 'null');
