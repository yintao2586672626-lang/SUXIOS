ALTER TABLE `manual_notification_schedule_runs`
  ADD COLUMN `scope_robot_id` int unsigned DEFAULT NULL AFTER `scope_hotel_id`,
  ADD KEY `idx_manual_notification_schedule_run_robot_scope`
    (`scope_hotel_id`, `scope_robot_id`, `observed_at`);
