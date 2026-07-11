-- Keep Meituan future demand forecasts out of historical-final OTA facts.
-- This migration changes period metadata only; source values and raw evidence stay untouched.
START TRANSACTION;

UPDATE `online_daily_data`
SET
    `data_period` = 'next_30_days',
    `snapshot_time` = NULL,
    `snapshot_bucket` = '',
    `is_final` = 0
WHERE LOWER(TRIM(`source`)) = 'meituan'
  AND LOWER(TRIM(`data_type`)) = 'traffic_forecast'
  AND (
      `data_period` <> 'next_30_days'
      OR `is_final` <> 0
      OR `snapshot_time` IS NOT NULL
      OR COALESCE(`snapshot_bucket`, '') <> ''
  );

COMMIT;
