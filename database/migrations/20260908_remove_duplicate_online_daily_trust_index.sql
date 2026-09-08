-- Both indexes were introduced with the same ordered columns in July.
-- Retain the original trust index and remove only the redundant secondary index.
-- No business rows, source evidence or uniqueness constraints are changed.
ALTER TABLE `online_daily_data`
  ADD INDEX IF NOT EXISTS `idx_online_daily_ai_trust`
    (`system_hotel_id`, `data_date`, `readback_verified`, `source`, `data_type`);

ALTER TABLE `online_daily_data`
  DROP INDEX IF EXISTS `idx_online_daily_cloud_trust`;
