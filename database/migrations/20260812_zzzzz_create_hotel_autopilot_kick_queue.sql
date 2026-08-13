-- Durable, secret-free handoff from verified local OTA login to the hotel
-- autopilot coordinator. Login HTTP responses only enqueue a bounded scope;
-- the background coordinator owns plan provisioning and collection startup.
CREATE TABLE IF NOT EXISTS `hotel_autopilot_kick_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `system_hotel_id` INT UNSIGNED NOT NULL,
  `source_task_id` BIGINT UNSIGNED NOT NULL,
  `requested_by` BIGINT UNSIGNED NOT NULL,
  `trigger_type` VARCHAR(40) NOT NULL DEFAULT 'verified_login',
  `platform` VARCHAR(20) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `request_digest` CHAR(64) NOT NULL,
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME DEFAULT NULL,
  `claimed_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `lifecycle_status` VARCHAR(40) DEFAULT NULL,
  `lifecycle_failure_code` VARCHAR(120) DEFAULT NULL,
  `failure_code` VARCHAR(120) DEFAULT NULL,
  `create_time` DATETIME NOT NULL,
  `update_time` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hotel_autopilot_kick_source` (`tenant_id`, `source_task_id`),
  KEY `idx_hotel_autopilot_kick_due` (`status`, `next_attempt_at`, `id`),
  KEY `idx_hotel_autopilot_kick_scope` (`tenant_id`, `system_hotel_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Verified-login to hotel-autopilot durable handoff without credentials';

-- Rollback is intentionally manual because queue rows are operational evidence.
-- Export the table before dropping it.
-- DROP TABLE IF EXISTS `hotel_autopilot_kick_queue`;
