-- Local delivery only: apply through the existing controlled migration process.
-- No platform orders, sends, credential material or media URLs are stored here.
CREATE TABLE IF NOT EXISTS `campaign_operation_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED NOT NULL COMMENT 'Immutable original source hotel; hotel_id follows authorized renumbering',
  `kind` VARCHAR(30) NOT NULL,
  `record_key` VARCHAR(64) NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `business_date` DATE NOT NULL,
  `source_method` VARCHAR(40) NOT NULL,
  `source_label` VARCHAR(240) NOT NULL,
  `data_status` VARCHAR(30) NOT NULL,
  `payload_json` JSON NOT NULL,
  `content_sha256` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_campaign_scope_version` (`tenant_id`, `hotel_id`, `kind`, `record_key`, `version_no`),
  KEY `idx_campaign_scope_date` (`tenant_id`, `hotel_id`, `business_date`, `kind`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Versioned shift, campaign, native creative and report-reconciliation records';
