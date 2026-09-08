-- Dedicated immutable planning/observation records; no financial ledger changes.
CREATE TABLE IF NOT EXISTS `promotion_experiment_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `system_hotel_id` BIGINT UNSIGNED NOT NULL,
  `platform` VARCHAR(16) NOT NULL,
  `platform_store_id` VARCHAR(128) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `experiment_key` VARCHAR(64) NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `request_digest` CHAR(64) NOT NULL,
  `payload_digest` CHAR(64) NOT NULL,
  `payload_json` MEDIUMTEXT NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_promotion_version` (`tenant_id`, `system_hotel_id`, `experiment_key`, `version_no`),
  UNIQUE KEY `uniq_promotion_request` (`tenant_id`, `system_hotel_id`, `idempotency_key`),
  KEY `idx_promotion_history` (`tenant_id`, `system_hotel_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
