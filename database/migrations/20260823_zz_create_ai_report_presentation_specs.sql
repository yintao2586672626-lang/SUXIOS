-- Immutable, source-inspired SUXIOS presentation specifications. This table
-- stores only the report delivery contract; it does not store rendered files
-- and does not authorize publishing, OTA writes, PMS writes, or external sends.

CREATE TABLE IF NOT EXISTS `ai_report_presentation_specs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL COMMENT 'tenant scope resolved from hotels',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT 'authorized system hotel',
  `report_id` BIGINT UNSIGNED NOT NULL COMMENT 'persisted ai_daily_reports id',
  `audience` VARCHAR(20) NOT NULL DEFAULT 'owner' COMMENT 'owner/expert/training',
  `schema_version` VARCHAR(80) NOT NULL COMMENT 'SUXIOS presentation spec schema',
  `adapter_version` VARCHAR(40) NOT NULL COMMENT 'SUXIOS source-inspired adapter version',
  `source_result_version` VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'source report result contract version',
  `spec_fingerprint` CHAR(64) NOT NULL COMMENT 'sha256 of canonical spec before embedded fingerprint',
  `spec_json` JSON NOT NULL COMMENT 'exact immutable presentation specification',
  `data_status` VARCHAR(30) NOT NULL DEFAULT 'unverified',
  `render_status` VARCHAR(30) NOT NULL DEFAULT 'not_rendered',
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ai_report_presentation_spec_identity`
    (`report_id`, `audience`, `adapter_version`, `spec_fingerprint`),
  KEY `idx_ai_report_presentation_spec_scope`
    (`tenant_id`, `hotel_id`, `report_id`, `id`),
  KEY `idx_ai_report_presentation_spec_latest`
    (`report_id`, `audience`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='immutable SUXIOS AI report presentation specifications';
