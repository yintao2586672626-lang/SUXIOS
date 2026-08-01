CREATE TABLE IF NOT EXISTS `manual_notification_schedule_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `runner_mode` varchar(16) NOT NULL,
  `dispatch_requested` tinyint(1) NOT NULL DEFAULT 0,
  `scope_hotel_id` int unsigned DEFAULT NULL,
  `observed_at` datetime NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'running',
  `candidate_count` int unsigned NOT NULL DEFAULT 0,
  `due_count` int unsigned NOT NULL DEFAULT 0,
  `sent_count` int unsigned NOT NULL DEFAULT 0,
  `failed_count` int unsigned NOT NULL DEFAULT 0,
  `blocked_count` int unsigned NOT NULL DEFAULT 0,
  `result_summary_json` json DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_manual_notification_schedule_run_status` (`status`, `observed_at`),
  KEY `idx_manual_notification_schedule_run_scope` (`scope_hotel_id`, `observed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Every manual notification scheduler invocation and its sanitized outcome';
