-- Immutable AI-suggestion learning evidence. These tables only record frozen
-- suggestion snapshots, append-only user feedback, non-causal observations,
-- and offline/shadow strategy comparisons. They never authorize an external
-- model call, an OTA/PMS write, or automatic strategy activation.

CREATE TABLE IF NOT EXISTS `ai_suggestion_calibration_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `suggestion_key` VARCHAR(120) NOT NULL,
  `scenario` VARCHAR(120) NOT NULL,
  `source_key` VARCHAR(120) NOT NULL,
  `source_version` VARCHAR(120) NOT NULL,
  `evidence_digest` CHAR(64) NOT NULL,
  `identity_digest` CHAR(64) NOT NULL,
  `suggestion_payload_json` JSON NOT NULL,
  `confidence` DECIMAL(6,5) DEFAULT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `idempotency_hash` CHAR(64) NOT NULL COMMENT 'one-way client retry identity',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_suggestion_scope_key`
    (`tenant_id`, `user_id`, `hotel_id`, `suggestion_key`),
  UNIQUE KEY `uniq_ai_suggestion_scope_identity`
    (`tenant_id`, `user_id`, `hotel_id`, `identity_digest`),
  UNIQUE KEY `uniq_ai_suggestion_scope_idempotency`
    (`tenant_id`, `user_id`, `hotel_id`, `idempotency_hash`),
  KEY `idx_ai_suggestion_summary_scope`
    (`tenant_id`, `user_id`, `hotel_id`, `scenario`, `source_key`, `source_version`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='immutable identity and evidence snapshot for one AI suggestion';

CREATE TABLE IF NOT EXISTS `ai_suggestion_calibration_feedback_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `suggestion_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `suggestion_identity_digest` CHAR(64) NOT NULL,
  `idempotency_hash` CHAR(64) NOT NULL COMMENT 'one-way client retry identity',
  `feedback_status` VARCHAR(30) NOT NULL,
  `reason_code` VARCHAR(100) NOT NULL DEFAULT '',
  `reason_note` VARCHAR(1000) NOT NULL DEFAULT '',
  `feedback_payload_json` JSON NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_suggestion_feedback_idempotency`
    (`tenant_id`, `user_id`, `hotel_id`, `suggestion_id`, `idempotency_hash`),
  KEY `idx_ai_suggestion_feedback_latest`
    (`tenant_id`, `user_id`, `hotel_id`, `suggestion_id`, `id`),
  KEY `idx_ai_suggestion_feedback_status`
    (`tenant_id`, `user_id`, `hotel_id`, `feedback_status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='append-only accepted modified rejected deferred or evidence feedback';

CREATE TABLE IF NOT EXISTS `ai_suggestion_calibration_observation_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `suggestion_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `suggestion_identity_digest` CHAR(64) NOT NULL,
  `idempotency_hash` CHAR(64) NOT NULL COMMENT 'one-way client retry identity',
  `execution_status` VARCHAR(30) DEFAULT NULL,
  `review_result` VARCHAR(30) DEFAULT NULL,
  `observed_at` DATETIME NOT NULL,
  `evidence_digest` CHAR(64) DEFAULT NULL,
  `evidence_payload_json` JSON NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `causal_claim` VARCHAR(20) NOT NULL DEFAULT 'none',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_suggestion_observation_idempotency`
    (`tenant_id`, `user_id`, `hotel_id`, `suggestion_id`, `idempotency_hash`),
  KEY `idx_ai_suggestion_observation_latest`
    (`tenant_id`, `user_id`, `hotel_id`, `suggestion_id`, `id`),
  KEY `idx_ai_suggestion_observation_review`
    (`tenant_id`, `user_id`, `hotel_id`, `review_result`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='append-only execution and review observations without causal claims';

CREATE TABLE IF NOT EXISTS `ai_suggestion_strategy_comparisons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `comparison_key` VARCHAR(120) NOT NULL,
  `idempotency_hash` CHAR(64) NOT NULL COMMENT 'one-way client retry identity',
  `mode` VARCHAR(20) NOT NULL,
  `scenario` VARCHAR(120) NOT NULL,
  `evaluation_set` VARCHAR(120) NOT NULL,
  `baseline_version` VARCHAR(120) NOT NULL,
  `candidate_version` VARCHAR(120) NOT NULL,
  `evaluation_snapshot_digest` CHAR(64) NOT NULL,
  `comparison_json` JSON NOT NULL,
  `rollback_metadata_json` JSON NOT NULL,
  `activation_status` VARCHAR(30) NOT NULL DEFAULT 'not_activated',
  `decision_effect` VARCHAR(20) NOT NULL DEFAULT 'none',
  `external_call_status` VARCHAR(20) NOT NULL DEFAULT 'not_called',
  `business_write_status` VARCHAR(20) NOT NULL DEFAULT 'none',
  `causal_claim` VARCHAR(20) NOT NULL DEFAULT 'none',
  `content_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ai_suggestion_comparison_key`
    (`tenant_id`, `user_id`, `hotel_id`, `comparison_key`),
  UNIQUE KEY `uniq_ai_suggestion_comparison_idempotency`
    (`tenant_id`, `user_id`, `hotel_id`, `idempotency_hash`),
  KEY `idx_ai_suggestion_comparison_scope`
    (`tenant_id`, `user_id`, `hotel_id`, `scenario`, `mode`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='offline or shadow-only candidate strategy comparison and rollback metadata';

-- Manual rollback only, in reverse dependency order:
-- DROP TABLE `ai_suggestion_strategy_comparisons`;
-- DROP TABLE `ai_suggestion_calibration_observation_events`;
-- DROP TABLE `ai_suggestion_calibration_feedback_events`;
-- DROP TABLE `ai_suggestion_calibration_snapshots`;
