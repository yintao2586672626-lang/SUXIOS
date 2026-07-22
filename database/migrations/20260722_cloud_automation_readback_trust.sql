ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `readback_verified` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '1 only after persisted identity and observed metrics are read back successfully'
    AFTER `validation_flags`,
  ADD COLUMN IF NOT EXISTS `readback_verified_at` DATETIME DEFAULT NULL
    COMMENT 'latest successful database readback verification time'
    AFTER `readback_verified`,
  ADD INDEX IF NOT EXISTS `idx_online_daily_cloud_trust`
    (`system_hotel_id`, `data_date`, `readback_verified`, `source`, `data_type`);
