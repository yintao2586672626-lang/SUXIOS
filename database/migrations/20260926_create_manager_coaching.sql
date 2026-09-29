-- Case-scoped manual coaching. No employee scoring or automatic operating action.
CREATE TABLE IF NOT EXISTS `manager_coaching_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `manager_user_id` BIGINT UNSIGNED NOT NULL,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `input_digest` CHAR(64) NOT NULL,
  `revision` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` VARCHAR(32) NOT NULL,
  `plan_json` JSON NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `due_on` DATE NOT NULL,
  `review_on` DATE NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coaching_request` (`tenant_id`,`hotel_id`,`created_by`,`idempotency_key`),
  KEY `idx_coaching_scope` (`tenant_id`,`hotel_id`,`manager_user_id`,`case_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manager_coaching_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `revision` INT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `input_digest` CHAR(64) NOT NULL,
  `payload_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coaching_event` (`plan_id`,`actor_id`,`idempotency_key`),
  KEY `idx_coaching_event_scope` (`tenant_id`,`hotel_id`,`plan_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
