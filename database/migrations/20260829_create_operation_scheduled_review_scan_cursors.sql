CREATE TABLE IF NOT EXISTS `operation_scheduled_review_scan_cursors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `last_task_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation_review_scan_hotel` (`hotel_id`),
  KEY `idx_operation_review_scan_scope` (`tenant_id`, `hotel_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bounded rotating cursor for scheduled operation effect review scans';
