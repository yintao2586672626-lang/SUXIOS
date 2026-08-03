-- Formal knowledge-promotion workflow.
-- Runtime Phase-3 JSON remains legacy diagnostic material and is never
-- imported as a formal candidate or SOP by this migration.
CREATE TABLE IF NOT EXISTS `knowledge_candidates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `candidate_key` VARCHAR(191) NOT NULL,
  `candidate_type` VARCHAR(50) NOT NULL DEFAULT 'operating_sop',
  `source_record_type` VARCHAR(80) NOT NULL,
  `source_record_id` BIGINT UNSIGNED NOT NULL,
  `source_stage` VARCHAR(50) NOT NULL DEFAULT 'verified_execution_review',
  `current_revision_id` BIGINT UNSIGNED DEFAULT NULL,
  `current_revision_no` INT UNSIGNED NOT NULL DEFAULT 0,
  `workflow_status` VARCHAR(40) NOT NULL DEFAULT 'draft',
  `assigned_reviewer_id` BIGINT UNSIGNED DEFAULT NULL,
  `review_due_at` DATETIME DEFAULT NULL,
  `promoted_sop_version_id` BIGINT UNSIGNED DEFAULT NULL,
  `promoted_knowledge_unit_id` INT DEFAULT NULL,
  `promoted_knowledge_chunk_id` INT DEFAULT NULL,
  `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_knowledge_candidate_key` (`tenant_id`, `hotel_id`, `candidate_key`),
  UNIQUE KEY `uniq_knowledge_candidate_source` (`tenant_id`, `hotel_id`, `source_record_type`, `source_record_id`),
  KEY `idx_knowledge_candidate_workbench` (`tenant_id`, `hotel_id`, `workflow_status`, `updated_at`),
  KEY `idx_knowledge_candidate_reviewer` (`assigned_reviewer_id`, `workflow_status`, `review_due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='formal promotion candidate workbench';

CREATE TABLE IF NOT EXISTS `knowledge_candidate_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `candidate_id` BIGINT UNSIGNED NOT NULL,
  `revision_no` INT UNSIGNED NOT NULL,
  `source_sop_candidate_version_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `objective` VARCHAR(1000) NOT NULL DEFAULT '',
  `steps_json` JSON NOT NULL,
  `stop_conditions_json` JSON DEFAULT NULL,
  `applicability_json` JSON NOT NULL,
  `scope_json` JSON NOT NULL,
  `evidence_refs_json` JSON NOT NULL,
  `outcome_refs_json` JSON DEFAULT NULL,
  `conflict_refs_json` JSON DEFAULT NULL,
  `source_digest` CHAR(64) NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_by` BIGINT UNSIGNED DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_knowledge_candidate_revision` (`candidate_id`, `revision_no`),
  UNIQUE KEY `uniq_knowledge_candidate_revision_digest` (`candidate_id`, `content_digest`),
  KEY `idx_knowledge_candidate_revision_source` (`source_sop_candidate_version_id`),
  CONSTRAINT `fk_knowledge_candidate_revision_candidate`
    FOREIGN KEY (`candidate_id`) REFERENCES `knowledge_candidates` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='immutable formal candidate revisions';

CREATE TABLE IF NOT EXISTS `knowledge_promotion_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `candidate_id` BIGINT UNSIGNED NOT NULL,
  `revision_id` BIGINT UNSIGNED DEFAULT NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `from_status` VARCHAR(40) NOT NULL DEFAULT '',
  `to_status` VARCHAR(40) NOT NULL DEFAULT '',
  `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `note` VARCHAR(1000) NOT NULL DEFAULT '',
  `payload_json` JSON DEFAULT NULL,
  `idempotency_key` VARCHAR(191) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_knowledge_promotion_event_request` (`idempotency_key`),
  KEY `idx_knowledge_promotion_event_timeline` (`tenant_id`, `hotel_id`, `candidate_id`, `id`),
  CONSTRAINT `fk_knowledge_promotion_event_candidate`
    FOREIGN KEY (`candidate_id`) REFERENCES `knowledge_candidates` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='append-only promotion audit events';

ALTER TABLE `knowledge_units`
  ADD COLUMN IF NOT EXISTS `stable_key` VARCHAR(191) DEFAULT NULL COMMENT 'stable identity for versioned formal knowledge' AFTER `hotel_id`,
  ADD COLUMN IF NOT EXISTS `current_chunk_id` INT DEFAULT NULL COMMENT 'current active formal chunk' AFTER `stable_key`,
  ADD UNIQUE INDEX IF NOT EXISTS `uniq_knowledge_units_stable_key` (`stable_key`),
  ADD INDEX IF NOT EXISTS `idx_knowledge_units_current_chunk` (`current_chunk_id`);

ALTER TABLE `knowledge_chunks`
  ADD COLUMN IF NOT EXISTS `promotion_candidate_id` BIGINT UNSIGNED DEFAULT NULL AFTER `unit_id`,
  ADD COLUMN IF NOT EXISTS `operating_sop_version_id` BIGINT UNSIGNED DEFAULT NULL AFTER `promotion_candidate_id`,
  ADD COLUMN IF NOT EXISTS `version_no` INT UNSIGNED DEFAULT NULL AFTER `operating_sop_version_id`,
  ADD COLUMN IF NOT EXISTS `lifecycle_status` VARCHAR(30) DEFAULT NULL AFTER `version_no`,
  ADD COLUMN IF NOT EXISTS `content_digest` CHAR(64) DEFAULT NULL AFTER `lifecycle_status`,
  ADD COLUMN IF NOT EXISTS `superseded_by_chunk_id` INT DEFAULT NULL AFTER `content_digest`,
  ADD COLUMN IF NOT EXISTS `published_at` DATETIME DEFAULT NULL AFTER `superseded_by_chunk_id`,
  ADD COLUMN IF NOT EXISTS `retired_at` DATETIME DEFAULT NULL AFTER `published_at`,
  ADD UNIQUE INDEX IF NOT EXISTS `uniq_knowledge_chunk_operating_sop_version` (`operating_sop_version_id`),
  ADD INDEX IF NOT EXISTS `idx_knowledge_chunk_formal_lifecycle` (`unit_id`, `lifecycle_status`, `version_no`),
  ADD INDEX IF NOT EXISTS `idx_knowledge_chunk_promotion_candidate` (`promotion_candidate_id`);

ALTER TABLE `hotel_operating_sop_versions`
  ADD COLUMN IF NOT EXISTS `retired_by` BIGINT UNSIGNED DEFAULT NULL AFTER `validated_at`,
  ADD COLUMN IF NOT EXISTS `retired_at` DATETIME DEFAULT NULL AFTER `retired_by`,
  ADD COLUMN IF NOT EXISTS `replacement_version_id` BIGINT UNSIGNED DEFAULT NULL AFTER `retired_at`,
  ADD INDEX IF NOT EXISTS `idx_operating_sop_replacement` (`replacement_version_id`);
