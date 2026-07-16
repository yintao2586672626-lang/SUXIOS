ALTER TABLE `online_daily_data`
  ADD INDEX IF NOT EXISTS `idx_online_daily_history_date_id`
    (`data_date`, `id`);
