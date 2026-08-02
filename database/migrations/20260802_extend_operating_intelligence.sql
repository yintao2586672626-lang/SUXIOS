-- Stage 3-6 operating-intelligence workflow.
-- These tables store only scoped questions, immutable SOP versions, and
-- replication drafts. They do not authorize OTA writes or message delivery.

CREATE TABLE IF NOT EXISTS `hotel_operating_questions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `request_key` VARCHAR(191) NOT NULL,
  `question_text` VARCHAR(1000) NOT NULL,
  `platform` VARCHAR(40) NOT NULL DEFAULT '',
  `date_start` DATE NOT NULL,
  `date_end` DATE NOT NULL,
  `answer_status` VARCHAR(50) NOT NULL DEFAULT 'blocked_by_missing_facts',
  `answer_summary` TEXT NOT NULL,
  `answer_json` JSON DEFAULT NULL,
  `fact_refs_json` JSON DEFAULT NULL,
  `memory_refs_json` JSON DEFAULT NULL,
  `knowledge_refs_json` JSON DEFAULT NULL,
  `execution_refs_json` JSON DEFAULT NULL,
  `data_gaps_json` JSON DEFAULT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operating_question_request` (`tenant_id`, `hotel_id`, `request_key`),
  KEY `idx_operating_question_scope` (`tenant_id`, `hotel_id`, `date_end`, `id`),
  KEY `idx_operating_question_status` (`tenant_id`, `answer_status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Scoped Agent operating questions backed by saved evidence';

CREATE TABLE IF NOT EXISTS `hotel_operating_sop_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `sop_key` VARCHAR(191) NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `previous_version_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(191) NOT NULL,
  `objective` VARCHAR(1000) NOT NULL DEFAULT '',
  `steps_json` JSON NOT NULL,
  `stop_conditions_json` JSON DEFAULT NULL,
  `scope_json` JSON NOT NULL,
  `source_memory_ids_json` JSON NOT NULL,
  `evidence_refs_json` JSON NOT NULL,
  `validation_status` VARCHAR(30) NOT NULL DEFAULT 'candidate',
  `validation_note` VARCHAR(1000) NOT NULL DEFAULT '',
  `content_digest` CHAR(64) NOT NULL,
  `lifecycle_status` VARCHAR(30) NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `validated_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `validated_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operating_sop_version` (`tenant_id`, `hotel_id`, `sop_key`, `version_no`),
  KEY `idx_operating_sop_status` (`tenant_id`, `hotel_id`, `validation_status`, `id`),
  KEY `idx_operating_sop_previous` (`previous_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Immutable and human-validated operating SOP versions';

CREATE TABLE IF NOT EXISTS `hotel_operating_sop_replications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `source_sop_version_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` INT UNSIGNED NOT NULL,
  `target_hotel_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'draft_pending_target_validation',
  `target_validation_status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `draft_json` JSON NOT NULL,
  `target_fact_refs_json` JSON DEFAULT NULL,
  `data_gaps_json` JSON DEFAULT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operating_sop_replication` (`tenant_id`, `source_sop_version_id`, `target_hotel_id`),
  KEY `idx_operating_sop_replication_target` (`tenant_id`, `target_hotel_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cross-hotel SOP drafts that always require target-hotel validation';

-- Manual rollback, in reverse dependency order:
-- DROP TABLE IF EXISTS `hotel_operating_sop_replications`;
-- DROP TABLE IF EXISTS `hotel_operating_sop_versions`;
-- DROP TABLE IF EXISTS `hotel_operating_questions`;
