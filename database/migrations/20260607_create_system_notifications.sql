CREATE TABLE IF NOT EXISTS `system_notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `hotel_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `platform` VARCHAR(32) NOT NULL DEFAULT 'ota',
  `category` VARCHAR(64) NOT NULL DEFAULT 'general',
  `severity` VARCHAR(16) NOT NULL DEFAULT 'info',
  `title` VARCHAR(120) NOT NULL,
  `message` VARCHAR(500) DEFAULT NULL,
  `action_type` VARCHAR(64) DEFAULT NULL,
  `action_payload` TEXT DEFAULT NULL,
  `source_module` VARCHAR(64) NOT NULL DEFAULT 'system',
  `source_key` VARCHAR(160) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `is_cleared` TINYINT(1) NOT NULL DEFAULT 0,
  `read_time` DATETIME DEFAULT NULL,
  `clear_time` DATETIME DEFAULT NULL,
  `create_time` DATETIME DEFAULT NULL,
  `update_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_system_notifications_source_key` (`source_key`),
  KEY `idx_system_notifications_scope` (`hotel_id`, `user_id`, `is_cleared`, `is_read`, `update_time`),
  KEY `idx_system_notifications_category` (`category`, `severity`, `update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='System notification center';

CREATE TABLE IF NOT EXISTS `system_notification_user_states` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `is_cleared` TINYINT(1) NOT NULL DEFAULT 0,
  `read_time` DATETIME DEFAULT NULL,
  `clear_time` DATETIME DEFAULT NULL,
  `create_time` DATETIME DEFAULT NULL,
  `update_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_system_notification_user_state` (`notification_id`, `user_id`),
  KEY `idx_system_notification_user_states_user` (`user_id`, `is_cleared`, `is_read`, `update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Per-user system notification read and clear state';
