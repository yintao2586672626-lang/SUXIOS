-- Global immutable replay ledger for operating-question model responses.
-- The provider response ID is unique across every tenant, hotel, provider and
-- question. This migration does not authorize any external action or OTA write.

CREATE TABLE IF NOT EXISTS `hotel_operating_question_model_responses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_response_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider` VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `question_content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operating_question_provider_response` (`provider_response_id`),
  UNIQUE KEY `uniq_operating_question_response_question` (`question_id`),
  KEY `idx_operating_question_response_scope` (`tenant_id`, `hotel_id`, `question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Immutable global replay ledger for operating-question model responses';

-- Manual rollback:
-- DROP TABLE IF EXISTS `hotel_operating_question_model_responses`;
