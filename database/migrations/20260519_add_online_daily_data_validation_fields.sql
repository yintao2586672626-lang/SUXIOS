ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `validation_status` varchar(20) NOT NULL DEFAULT 'normal' COMMENT 'validation status: normal/warning/abnormal' AFTER `data_type`,
  ADD COLUMN IF NOT EXISTS `validation_flags` text NULL COMMENT 'validation warning/error flags' AFTER `validation_status`,
  ADD INDEX IF NOT EXISTS `idx_online_daily_validation` (`validation_status`, `source`, `data_date`);
