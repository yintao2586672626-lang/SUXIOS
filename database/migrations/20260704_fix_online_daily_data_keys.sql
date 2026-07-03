ALTER TABLE `online_daily_data`
  MODIFY COLUMN `dimension` varchar(512) DEFAULT '' COMMENT 'metric/business dimension key; long enough for catalog metric suffixes',
  MODIFY COLUMN `validation_status` varchar(60) NOT NULL DEFAULT 'normal' COMMENT 'validation status: normal/warning/abnormal/manual states';

UPDATE `online_daily_data`
SET `dimension` = JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.row.dimension'))
WHERE `raw_data` IS NOT NULL
  AND TRIM(`raw_data`) <> ''
  AND JSON_VALID(`raw_data`) = 1
  AND JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.row.dimension')) IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.row.dimension')) <> 'null'
  AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.row.dimension'))) > CHAR_LENGTH(COALESCE(`dimension`, ''))
  AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.row.dimension'))) <= 512;

UPDATE `online_daily_data`
SET `dimension` = CONCAT(
    'catalog:',
    COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.section')), 'null'), 'unknown'),
    ':',
    COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.endpoint_id')), 'null'), 'unknown'),
    ':',
    COALESCE(NULLIF(CONCAT_WS('+',
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.facts[0].metric_key')), 'null'),
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.facts[1].metric_key')), 'null'),
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.facts[2].metric_key')), 'null')
    ), ''), 'fact'),
    ':',
    LEFT(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.facts[0].source_path')), 'null'), 'root'), 80)
  )
WHERE `raw_data` IS NOT NULL
  AND TRIM(`raw_data`) <> ''
  AND JSON_VALID(`raw_data`) = 1
  AND JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.source')) = 'ctrip_catalog_facts'
  AND JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.facts[0].source_path')) IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(`raw_data`, '$.facts[0].source_path')) <> 'null';

CREATE TEMPORARY TABLE `tmp_online_daily_data_dedupe_keep` AS
SELECT
  MD5(CONCAT_WS('|',
    COALESCE(`source`, ''),
    COALESCE(`platform`, ''),
    COALESCE(`data_type`, ''),
    COALESCE(`dimension`, ''),
    COALESCE(`compare_type`, ''),
    COALESCE(CAST(`data_date` AS CHAR), ''),
    COALESCE(`data_period`, ''),
    COALESCE(`snapshot_bucket`, ''),
    COALESCE(CAST(`system_hotel_id` AS CHAR), '0'),
    COALESCE(NULLIF(`hotel_id`, ''), CONCAT('name:', COALESCE(`hotel_name`, '')))
  )) AS `dedupe_key`,
  MAX(`id`) AS `keep_id`
FROM `online_daily_data`
GROUP BY `dedupe_key`
HAVING COUNT(*) > 1;

DELETE `d`
FROM `online_daily_data` AS `d`
JOIN `tmp_online_daily_data_dedupe_keep` AS `k`
  ON MD5(CONCAT_WS('|',
    COALESCE(`d`.`source`, ''),
    COALESCE(`d`.`platform`, ''),
    COALESCE(`d`.`data_type`, ''),
    COALESCE(`d`.`dimension`, ''),
    COALESCE(`d`.`compare_type`, ''),
    COALESCE(CAST(`d`.`data_date` AS CHAR), ''),
    COALESCE(`d`.`data_period`, ''),
    COALESCE(`d`.`snapshot_bucket`, ''),
    COALESCE(CAST(`d`.`system_hotel_id` AS CHAR), '0'),
    COALESCE(NULLIF(`d`.`hotel_id`, ''), CONCAT('name:', COALESCE(`d`.`hotel_name`, '')))
  )) = `k`.`dedupe_key`
WHERE `d`.`id` <> `k`.`keep_id`;

DROP TEMPORARY TABLE `tmp_online_daily_data_dedupe_keep`;
