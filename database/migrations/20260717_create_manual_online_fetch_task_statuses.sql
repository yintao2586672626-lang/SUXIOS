CREATE TABLE IF NOT EXISTS `manual_online_fetch_task_statuses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` VARCHAR(96) NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `platform` VARCHAR(20) NOT NULL,
  `task_kind` VARCHAR(60) NOT NULL,
  `status` VARCHAR(40) NOT NULL,
  `stage` VARCHAR(60) NOT NULL,
  `status_json` LONGTEXT NOT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manual_fetch_task_id` (`task_id`),
  KEY `idx_manual_fetch_hotel_created` (`hotel_id`, `created_at`),
  KEY `idx_manual_fetch_status_updated` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='共享的手动 OTA 采集任务状态；不保存 Cookie、Token、Profile 或请求正文';
