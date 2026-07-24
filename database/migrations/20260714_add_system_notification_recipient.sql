ALTER TABLE `system_notifications`
  ADD COLUMN IF NOT EXISTS `recipient_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Target recipient user; NULL keeps legacy broadcast semantics' AFTER `user_id`,
  ADD INDEX IF NOT EXISTS `idx_system_notifications_recipient` (`recipient_user_id`, `is_cleared`, `is_read`, `update_time`);
