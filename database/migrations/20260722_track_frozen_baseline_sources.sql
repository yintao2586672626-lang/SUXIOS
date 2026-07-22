-- Track the immutable SQL resources that form the frozen init_full baseline.
-- Legacy adoption is distinguished from a migration actually executed by the runner.

ALTER TABLE `schema_versions`
  ADD COLUMN IF NOT EXISTS `execution_kind` VARCHAR(32) NOT NULL DEFAULT 'executed' AFTER `checksum`;

CREATE TABLE IF NOT EXISTS `schema_baseline_sources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(255) NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `registered_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_schema_baseline_sources_source` (`source`),
  KEY `idx_schema_baseline_sources_registered_at` (`registered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Checksums for frozen init_full non-migration sources';
