-- Preserve forecast identity across its full lifecycle.
-- A forecast may later be evaluated against an actual fact, but it never
-- becomes historical actual data merely because its target date has passed.
ALTER TABLE `online_daily_data`
  MODIFY COLUMN `snapshot_bucket` varchar(20) NOT NULL DEFAULT '' COMMENT 'minute bucket, e.g. 202606061315';

START TRANSACTION;

UPDATE `online_daily_data`
SET
    `data_period` = 'next_30_days',
    `snapshot_time` = NULL,
    `snapshot_bucket` = '',
    `is_final` = 0,
    `update_time` = `update_time`
WHERE LOWER(TRIM(COALESCE(`data_type`, ''))) = 'traffic_forecast'
  AND (
      COALESCE(`data_period`, '') <> 'next_30_days'
      OR COALESCE(`is_final`, 0) <> 0
      OR `snapshot_time` IS NOT NULL
      OR COALESCE(`snapshot_bucket`, '') <> ''
  );

COMMIT;
