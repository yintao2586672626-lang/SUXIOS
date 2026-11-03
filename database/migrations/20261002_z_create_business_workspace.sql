CREATE TABLE IF NOT EXISTS `hotel_business_workspace_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED NOT NULL,
  `owner_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `kind` VARCHAR(32) NOT NULL,
  `previous_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `payload_json` MEDIUMTEXT NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business_workspace_request` (`tenant_id`,`hotel_id`,`owner_user_id`,`kind`,`idempotency_key`),
  KEY `idx_business_workspace_scope` (`tenant_id`,`hotel_id`,`owner_user_id`,`kind`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
