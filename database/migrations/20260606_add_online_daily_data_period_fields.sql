ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `data_period` varchar(30) NOT NULL DEFAULT 'historical_daily' COMMENT 'historical_daily/realtime_snapshot' AFTER `raw_data`,
  ADD COLUMN IF NOT EXISTS `snapshot_time` datetime DEFAULT NULL COMMENT 'capture snapshot time for realtime data' AFTER `data_period`,
  ADD COLUMN IF NOT EXISTS `snapshot_bucket` varchar(20) NOT NULL DEFAULT '' COMMENT 'hour bucket, e.g. 2026060613' AFTER `snapshot_time`,
  ADD COLUMN IF NOT EXISTS `is_final` tinyint NOT NULL DEFAULT 1 COMMENT '1 historical fixed data, 0 realtime snapshot' AFTER `snapshot_bucket`,
  ADD INDEX IF NOT EXISTS `idx_online_daily_period` (`data_period`, `snapshot_bucket`, `source`, `data_type`, `data_date`),
  ADD INDEX IF NOT EXISTS `idx_online_daily_period_hotel` (`system_hotel_id`, `hotel_id`, `data_date`, `data_period`, `snapshot_bucket`);

UPDATE `online_daily_data`
SET `data_period` = 'historical_daily',
    `snapshot_time` = NULL,
    `snapshot_bucket` = '',
    `is_final` = 1
WHERE `data_period` IS NULL OR `data_period` = '';
