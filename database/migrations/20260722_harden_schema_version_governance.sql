-- Add immutable migration evidence and durable failure diagnostics without
-- changing the frozen init_full.sql baseline.

ALTER TABLE `schema_versions`
  ADD COLUMN IF NOT EXISTS `checksum` CHAR(64) DEFAULT NULL AFTER `version`;

CREATE TABLE IF NOT EXISTS `schema_migration_failures` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(191) NOT NULL,
  `version` VARCHAR(191) NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `error_message` TEXT NOT NULL,
  `failed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `resolved_at` DATETIME(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_schema_migration_failure_open` (`migration`, `resolved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Failed database migration attempts';
