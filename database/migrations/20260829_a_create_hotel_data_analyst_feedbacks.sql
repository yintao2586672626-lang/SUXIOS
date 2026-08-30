CREATE TABLE IF NOT EXISTS `hotel_data_analyst_feedbacks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_version` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `source_scope_json` JSON NOT NULL,
  `source_scope_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quality_receipt_contract_version` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quality_receipt_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `feedback_kind` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `correction_json` JSON NOT NULL,
  `correction_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usage_policy` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'eval_candidate_only_no_training',
  `evaluation_projection_json` JSON DEFAULT NULL,
  `idempotency_key` VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hda_feedback_idempotency` (`tenant_id`, `hotel_id`, `created_by`, `idempotency_key`),
  KEY `idx_hda_feedback_question` (`tenant_id`, `hotel_id`, `question_id`, `id`),
  KEY `idx_hda_feedback_kind` (`feedback_kind`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only human feedback for hotel data analyst answers';

CREATE TRIGGER IF NOT EXISTS `trg_hda_feedback_no_update`
BEFORE UPDATE ON `hotel_data_analyst_feedbacks`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hotel data analyst feedback is append-only';

CREATE TRIGGER IF NOT EXISTS `trg_hda_feedback_no_delete`
BEFORE DELETE ON `hotel_data_analyst_feedbacks`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hotel data analyst feedback is append-only';

-- Append-only contract: application routes intentionally expose no UPDATE or DELETE.
-- Human opinion never overwrites hotel_operating_questions and never auto-promotes
-- into ai_evaluation_cases or triggers model training/external actions.

-- Manual rollback only:
-- DROP TABLE IF EXISTS `hotel_data_analyst_feedbacks`;
