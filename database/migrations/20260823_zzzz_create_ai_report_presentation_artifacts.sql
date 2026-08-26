-- Immutable, deterministic presentation bundles rendered from one verified
-- ai_report_presentation_specs row. The ZIP contains offline HTML, editable
-- PPTX, the exact PresentationSpec and a component hash manifest. It does not
-- authorize publishing, messaging, OTA writes or PMS writes.

CREATE TABLE IF NOT EXISTS `ai_report_presentation_artifacts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL COMMENT 'tenant scope copied from the verified spec row',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT 'authorized system hotel copied from the verified spec row',
  `report_id` BIGINT UNSIGNED NOT NULL COMMENT 'persisted ai_daily_reports id',
  `presentation_spec_id` BIGINT UNSIGNED NOT NULL COMMENT 'exact immutable PresentationSpec row',
  `audience` VARCHAR(20) NOT NULL COMMENT 'owner/expert/training',
  `format` VARCHAR(20) NOT NULL DEFAULT 'bundle_zip' COMMENT 'self-contained ZIP bundle',
  `renderer_version` VARCHAR(40) NOT NULL COMMENT 'deterministic SUXIOS renderer version',
  `spec_fingerprint` CHAR(64) NOT NULL COMMENT 'verified source PresentationSpec SHA-256',
  `content_sha256` CHAR(64) NOT NULL COMMENT 'SHA-256 of exact stored artifact bytes',
  `content_bytes` INT UNSIGNED NOT NULL COMMENT 'exact stored artifact byte length',
  `mime_type` VARCHAR(120) NOT NULL DEFAULT 'application/zip',
  `artifact_filename` VARCHAR(255) NOT NULL,
  `manifest_json` JSON NOT NULL COMMENT 'component filenames, sizes, hashes and rendering boundary',
  `artifact_blob` MEDIUMBLOB NOT NULL COMMENT 'exact ZIP bytes, capped by application service',
  `render_status` VARCHAR(40) NOT NULL DEFAULT 'rendered_and_readback_verified',
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ai_report_presentation_artifact_renderer`
    (`presentation_spec_id`, `renderer_version`),
  KEY `idx_ai_report_presentation_artifact_scope`
    (`tenant_id`, `hotel_id`, `report_id`, `audience`, `id`),
  KEY `idx_ai_report_presentation_artifact_fingerprint`
    (`spec_fingerprint`, `renderer_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='immutable SUXIOS AI report presentation artifact bundles';
