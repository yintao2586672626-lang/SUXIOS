ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `history_fetch_time` DATETIME
    GENERATED ALWAYS AS (
      CASE
        WHEN COALESCE(`update_time`, '1000-01-01 00:00:00') > COALESCE(`create_time`, '1000-01-01 00:00:00')
          THEN `update_time`
        ELSE `create_time`
      END
    ) STORED,
  ADD COLUMN IF NOT EXISTS `history_status` VARCHAR(20)
    GENERATED ALWAYS AS (
      CASE
        WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) IN ('abnormal', 'failed', 'error') THEN 'failed'
        WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) = 'unverified' THEN 'unverified'
        WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) IN ('partial', 'warning') THEN 'partial'
        WHEN COALESCE(`readback_verified`, 0) <> 1 THEN 'unverified'
        ELSE 'success'
      END
    ) STORED,
  ADD COLUMN IF NOT EXISTS `history_group_key` CHAR(64)
    GENERATED ALWAYS AS (
      SHA2(
        CONCAT(
          COALESCE(CAST(`data_date` AS CHAR), ''), '|',
          CASE
            WHEN LOWER(TRIM(COALESCE(CAST(`platform` AS CHAR), CAST(`source` AS CHAR), ''))) IN ('ctrip', '携程') THEN 'ctrip'
            WHEN LOWER(TRIM(COALESCE(CAST(`platform` AS CHAR), CAST(`source` AS CHAR), ''))) IN ('meituan', '美团') THEN 'meituan'
            WHEN LOWER(TRIM(COALESCE(CAST(`platform` AS CHAR), CAST(`source` AS CHAR), ''))) IN ('qunar', '去哪儿') THEN 'qunar'
            WHEN LOWER(TRIM(COALESCE(CAST(`platform` AS CHAR), CAST(`source` AS CHAR), ''))) <> ''
              THEN LOWER(TRIM(COALESCE(CAST(`platform` AS CHAR), CAST(`source` AS CHAR), '')))
            ELSE 'unknown'
          END, '|',
          CASE
            WHEN COALESCE(CAST(`compare_type` AS CHAR), '') = 'competitor_avg' THEN 'competitor'
            WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) = '' THEN 'business'
            WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) IN ('comment', 'comments') THEN 'review'
            WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) IN ('ad', 'ads') THEN 'advertising'
            ELSE LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), '')))
          END, '|',
          COALESCE(CAST(`system_hotel_id` AS CHAR), ''), '|',
          COALESCE(CAST(`dimension` AS CHAR), ''), '|',
          CASE
            WHEN (
              CASE
                WHEN COALESCE(CAST(`compare_type` AS CHAR), '') = 'competitor_avg' THEN 'competitor'
                WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) = '' THEN 'business'
                WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) IN ('comment', 'comments') THEN 'review'
                WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) IN ('ad', 'ads') THEN 'advertising'
                ELSE LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), '')))
              END = 'competitor'
              AND COALESCE(CAST(`dimension` AS CHAR), '') = 'competition_circle_hotel'
            ) THEN 'competition_circle'
            ELSE COALESCE(CAST(`compare_type` AS CHAR), '')
          END, '|',
          CASE
            WHEN (
              CASE
                WHEN COALESCE(CAST(`compare_type` AS CHAR), '') = 'competitor_avg' THEN 'competitor'
                WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) = '' THEN 'business'
                WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) IN ('comment', 'comments') THEN 'review'
                WHEN LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), ''))) IN ('ad', 'ads') THEN 'advertising'
                ELSE LOWER(TRIM(COALESCE(CAST(`data_type` AS CHAR), '')))
              END = 'competitor'
              AND COALESCE(CAST(`dimension` AS CHAR), '') = 'competition_circle_hotel'
            ) THEN CAST(
              CASE
                WHEN COALESCE(`update_time`, '1000-01-01 00:00:00') > COALESCE(`create_time`, '1000-01-01 00:00:00')
                  THEN `update_time`
                ELSE `create_time`
              END AS CHAR
            )
            ELSE ''
          END
        ),
        256
      )
    ) STORED,
  ADD INDEX IF NOT EXISTS `idx_online_daily_history_group_fetch`
    (`history_group_key`, `history_fetch_time`, `id`),
  ADD INDEX IF NOT EXISTS `idx_online_daily_history_fetch`
    (`history_fetch_time`, `id`),
  ADD INDEX IF NOT EXISTS `idx_online_daily_history_status_group`
    (`history_status`, `history_group_key`),
  ADD INDEX IF NOT EXISTS `idx_online_daily_history_scope_fetch`
    (`system_hotel_id`, `history_fetch_time`, `id`),
  ADD INDEX IF NOT EXISTS `idx_online_daily_history_date_group`
    (`data_date`, `history_group_key`);
