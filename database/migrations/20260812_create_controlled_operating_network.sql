-- Controlled operating-network profiles and immutable replication reviews.
-- These tables never authorize OTA writes, external messages, or automatic execution.

CREATE TABLE IF NOT EXISTS `hotel_operating_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `previous_version_id` BIGINT UNSIGNED DEFAULT NULL,
  `profile_json` JSON NOT NULL,
  `quality_status` VARCHAR(30) NOT NULL DEFAULT 'unverified',
  `effective_date` DATE NOT NULL,
  `evidence_valid_until` DATE DEFAULT NULL,
  `evidence_refs_json` JSON NOT NULL,
  `source_method` VARCHAR(80) NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `is_current` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hotel_operating_profile_version` (`tenant_id`, `hotel_id`, `version_no`),
  KEY `idx_hotel_operating_profile_current` (`tenant_id`, `hotel_id`, `is_current`, `id`),
  KEY `idx_hotel_operating_profile_validity` (`tenant_id`, `quality_status`, `evidence_valid_until`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Versioned hotel applicability profiles for controlled operating replication';

CREATE TABLE IF NOT EXISTS `hotel_operating_sop_replication_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `replication_id` BIGINT UNSIGNED NOT NULL,
  `review_no` INT UNSIGNED NOT NULL,
  `source_sop_version_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` INT UNSIGNED NOT NULL,
  `target_hotel_id` INT UNSIGNED NOT NULL,
  `outcome` VARCHAR(30) NOT NULL,
  `review_json` JSON NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operating_replication_review_no` (`tenant_id`, `replication_id`, `review_no`),
  KEY `idx_operating_replication_review_sop` (`tenant_id`, `source_sop_version_id`, `outcome`, `id`),
  KEY `idx_operating_replication_review_target` (`tenant_id`, `target_hotel_id`, `outcome`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only success failure and stop evidence for controlled replication';

-- Manual rollback, in reverse dependency order:
-- DROP TABLE IF EXISTS `hotel_operating_sop_replication_reviews`;
-- DROP TABLE IF EXISTS `hotel_operating_profiles`;
