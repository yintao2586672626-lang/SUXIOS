-- Durable idempotency receipt for OTA failure notifications sent to WeCom.
-- The row is claimed before any external request. A sending or
-- outcome_unknown row must never be retried automatically because the prior
-- external result may already have reached one or more robots.
CREATE TABLE IF NOT EXISTS `ota_failure_wecom_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED DEFAULT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `platform` VARCHAR(32) NOT NULL,
  `reason_code` VARCHAR(64) NOT NULL,
  `data_date` DATE NOT NULL,
  `collector_task_id` BIGINT UNSIGNED DEFAULT NULL,
  `dedupe_key` CHAR(64) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'sending',
  `claim_token` CHAR(64) NOT NULL,
  `robot_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `response_reference` VARCHAR(120) DEFAULT NULL,
  `result_code` VARCHAR(64) DEFAULT NULL,
  `requested_at` DATETIME NOT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `create_time` DATETIME NOT NULL,
  `update_time` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ota_failure_wecom_delivery_dedupe` (`dedupe_key`),
  KEY `idx_ota_failure_wecom_hotel_date` (`hotel_id`, `data_date`, `platform`),
  KEY `idx_ota_failure_wecom_status` (`status`, `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Secret-free durable receipt for OTA failure WeCom deliveries';

-- Rollback is intentionally manual because these rows are delivery receipts.
-- Export them before dropping the table.
-- DROP TABLE IF EXISTS `ota_failure_wecom_deliveries`;
