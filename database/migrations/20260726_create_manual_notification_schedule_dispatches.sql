CREATE TABLE IF NOT EXISTS `manual_notification_schedule_dispatches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `notification_id` bigint unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `dispatch_window` varchar(32) NOT NULL,
  `delivery_mode` varchar(16) NOT NULL,
  `trigger_type` varchar(32) NOT NULL,
  `robot_id` int unsigned NOT NULL,
  `robot_name` varchar(120) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'claimed',
  `result_code` varchar(64) NOT NULL DEFAULT 'dispatch_claimed',
  `result_message` varchar(255) DEFAULT NULL,
  `claimed_at` datetime NOT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manual_notification_schedule_window`
    (`notification_id`, `dispatch_window`, `delivery_mode`),
  KEY `idx_manual_notification_schedule_dispatch_scope`
    (`tenant_id`, `hotel_id`, `delivery_mode`, `dispatch_window`),
  KEY `idx_manual_notification_schedule_dispatch_status`
    (`status`, `update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Idempotent manual notification schedule dispatch claims and outcomes';
