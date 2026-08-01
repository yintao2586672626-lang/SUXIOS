-- Immutable migration ledger. New schema changes are discovered from
-- database/migrations and registered only after successful execution.

CREATE TABLE IF NOT EXISTS `schema_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) NOT NULL,
  `version` varchar(191) NOT NULL,
  `executed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_schema_versions_migration` (`migration`),
  UNIQUE KEY `uk_schema_versions_version` (`version`),
  KEY `idx_schema_versions_executed_at` (`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Applied database migrations';
