-- Keep an exact per-plan-scope heartbeat for every scheduler run.
-- Delivery rows remain immutable send provenance and are not rewritten.

CREATE TABLE IF NOT EXISTS `manual_notification_schedule_run_scopes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_run_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `robot_id` BIGINT UNSIGNED NOT NULL,
  `runner_mode` VARCHAR(16) NOT NULL,
  `dispatch_requested` TINYINT(1) NOT NULL DEFAULT 0,
  `observed_at` DATETIME NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `candidate_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `due_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `blocked_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `create_time` DATETIME NOT NULL,
  `update_time` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_manual_notification_run_scope`
    (`schedule_run_id`, `tenant_id`, `hotel_id`, `robot_id`),
  KEY `idx_manual_notification_scope_heartbeat`
    (`tenant_id`, `hotel_id`, `robot_id`, `schedule_run_id`),
  KEY `idx_manual_notification_scope_observed_at` (`observed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Exact saved-plan scope observations for scheduler health readback';
