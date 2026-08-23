-- Local second-brain, evaluation readback, council shadow review and trusted
-- WeCom inbound archive. Secrets, raw callback XML and uploaded source files
-- are intentionally not persisted by these tables.

ALTER TABLE `ai_evaluation_cases`
  ADD COLUMN IF NOT EXISTS `evaluation_set` VARCHAR(120) NOT NULL DEFAULT 'general' COMMENT 'isolated evaluation collection' AFTER `case_key`,
  DROP INDEX IF EXISTS `uniq_ai_evaluation_cases_case_key`,
  ADD UNIQUE INDEX IF NOT EXISTS `uniq_ai_evaluation_cases_set_case_key` (`evaluation_set`, `case_key`),
  ADD INDEX IF NOT EXISTS `idx_ai_evaluation_cases_set_status` (`evaluation_set`, `status`, `id`);

CREATE TABLE IF NOT EXISTS `ai_evaluation_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_run_key` VARCHAR(80) NOT NULL,
  `evaluation_set` VARCHAR(120) NOT NULL,
  `model_key` VARCHAR(100) NOT NULL,
  `filters_json` JSON DEFAULT NULL,
  `dry_run` TINYINT(1) NOT NULL DEFAULT 1,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `summary_json` JSON DEFAULT NULL,
  `cases_json` JSON DEFAULT NULL,
  `result_json` JSON NOT NULL,
  `result_digest` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `readback_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_evaluation_run_client_key` (`client_run_key`),
  KEY `idx_ai_evaluation_run_set_time` (`evaluation_set`, `created_at`),
  KEY `idx_ai_evaluation_run_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='immutable AI evaluation batch readback';

CREATE TABLE IF NOT EXISTS `hotel_operating_question_council_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `request_key` VARCHAR(96) NOT NULL,
  `mode` VARCHAR(20) NOT NULL DEFAULT 'shadow',
  `status` VARCHAR(40) NOT NULL DEFAULT 'pending',
  `members_json` JSON DEFAULT NULL,
  `synthesis_json` JSON DEFAULT NULL,
  `evidence_refs_json` JSON DEFAULT NULL,
  `model_meta_json` JSON DEFAULT NULL,
  `decision_effect` VARCHAR(20) NOT NULL DEFAULT 'none',
  `content_digest` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_question_council_request` (`tenant_id`, `hotel_id`, `question_id`, `request_key`),
  KEY `idx_question_council_latest` (`tenant_id`, `hotel_id`, `question_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user-triggered multi-persona shadow review';

CREATE TABLE IF NOT EXISTS `local_media_extractions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `media_kind` VARCHAR(30) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL DEFAULT '',
  `original_name` VARCHAR(255) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_sha256` CHAR(64) NOT NULL,
  `extraction_status` VARCHAR(40) NOT NULL,
  `extraction_method` VARCHAR(40) NOT NULL DEFAULT '',
  `extractor_version` VARCHAR(100) NOT NULL DEFAULT '',
  `extracted_text` MEDIUMTEXT DEFAULT NULL,
  `structured_json` JSON DEFAULT NULL,
  `confidence` DECIMAL(6,5) DEFAULT NULL,
  `error_code` VARCHAR(100) DEFAULT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `source_retention` VARCHAR(60) NOT NULL DEFAULT 'discarded_after_extraction',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_local_media_user_source` (`tenant_id`, `hotel_id`, `created_by`, `source_sha256`),
  KEY `idx_local_media_hotel_time` (`tenant_id`, `hotel_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='local media extraction result without retained source upload';

CREATE TABLE IF NOT EXISTS `wecom_inbound_bindings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `binding_key` VARCHAR(64) NOT NULL,
  `conversation_id_hash` CHAR(64) NOT NULL,
  `label` VARCHAR(120) NOT NULL DEFAULT '',
  `transport` VARCHAR(40) NOT NULL DEFAULT 'wecom_app_callback',
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending_verification',
  `reply_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wecom_inbound_binding_key` (`binding_key`),
  UNIQUE KEY `uniq_wecom_inbound_conversation` (`conversation_id_hash`),
  KEY `idx_wecom_inbound_binding_hotel` (`tenant_id`, `hotel_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='trusted WeCom callback to hotel binding without credentials';

CREATE TABLE IF NOT EXISTS `wecom_aibot_binding_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `code_hash` CHAR(64) NOT NULL,
  `code_mask` VARCHAR(16) NOT NULL,
  `label` VARCHAR(120) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `bound_binding_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wecom_aibot_code_hash` (`code_hash`),
  KEY `idx_wecom_aibot_code_hotel` (`tenant_id`, `hotel_id`, `status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='single-use WeCom AI Bot hotel binding codes';

CREATE TABLE IF NOT EXISTS `wecom_inbound_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `binding_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `external_event_id` VARCHAR(191) NOT NULL,
  `payload_digest` CHAR(64) NOT NULL,
  `occurred_at` DATETIME DEFAULT NULL,
  `message_type` VARCHAR(40) NOT NULL DEFAULT 'text',
  `transport` VARCHAR(40) NOT NULL DEFAULT 'wecom_app_callback',
  `sender_id_hash` CHAR(64) NOT NULL,
  `content_text` TEXT DEFAULT NULL,
  `archive_status` VARCHAR(40) NOT NULL DEFAULT 'received',
  `processing_status` VARCHAR(40) NOT NULL DEFAULT 'pending',
  `block_code` VARCHAR(100) DEFAULT NULL,
  `answer_json` JSON DEFAULT NULL,
  `evidence_refs_json` JSON DEFAULT NULL,
  `delivery_status` VARCHAR(30) NOT NULL DEFAULT 'not_sent',
  `delivery_reference` VARCHAR(100) DEFAULT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wecom_inbound_external_event` (`binding_id`, `external_event_id`),
  KEY `idx_wecom_inbound_hotel_time` (`tenant_id`, `hotel_id`, `id`),
  KEY `idx_wecom_inbound_processing` (`processing_status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='deduplicated normalized WeCom inbound archive';

INSERT INTO `ai_model_configs`
  (`name`, `model_key`, `provider`, `base_url`, `model_name`, `api_key_encrypted`, `api_key_mask`, `usage_scene`, `is_default`, `is_enabled`)
VALUES
  ('本机第二大脑 Qwen3 4B', 'local_second_brain', 'ollama', 'http://127.0.0.1:11434/v1', 'qwen3:4b', NULL, '', 'local_gpu,second_brain,ota_diagnosis,report', 0, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `provider` = VALUES(`provider`),
  `base_url` = VALUES(`base_url`),
  `model_name` = VALUES(`model_name`),
  `usage_scene` = VALUES(`usage_scene`),
  `is_enabled` = 1;
