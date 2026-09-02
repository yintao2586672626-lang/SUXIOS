-- User-scoped learning memory. Feedback and preference projections are
-- append-only: revocation and reset create new versions instead of rewriting
-- history. No credential, authorization grant, raw chat, or business fact is
-- intended to be stored in either table.

CREATE TABLE IF NOT EXISTS `user_learning_memory_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL COMMENT 'strict tenant scope',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'preference owner',
  `hotel_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'optional hotel scope',
  `memory_scope` VARCHAR(20) NOT NULL COMMENT 'global/hotel/session',
  `session_ref_hash` CHAR(64) DEFAULT NULL COMMENT 'one-way session scope identity; never an auth token',
  `preference_key` VARCHAR(128) NOT NULL COMMENT 'stable non-secret preference key; * for scope reset',
  `preference_identity` CHAR(64) DEFAULT NULL COMMENT 'scope and key identity; null for scope reset',
  `event_type` VARCHAR(24) NOT NULL COMMENT 'observed/confirmed/revoked/reset',
  `learning_status` VARCHAR(32) DEFAULT NULL COMMENT 'explicit_confirmed/inferred/insufficient',
  `value_json` JSON DEFAULT NULL COMMENT 'bounded preference value only',
  `value_hash` CHAR(64) DEFAULT NULL COMMENT 'canonical value digest',
  `source_type` VARCHAR(32) NOT NULL COMMENT 'explicit_user/user_correction/behavioral_signal/system_observation',
  `source_context_json` JSON DEFAULT NULL COMMENT 'allowlisted metadata only; no raw user content',
  `idempotency_hash` CHAR(64) NOT NULL COMMENT 'one-way client retry identity',
  `event_identity` CHAR(64) NOT NULL COMMENT 'tenant plus user plus idempotency identity',
  `request_digest` CHAR(64) NOT NULL COMMENT 'normalized request digest for conflict detection',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_learning_event_identity` (`event_identity`),
  KEY `idx_user_learning_event_scope` (`tenant_id`, `user_id`, `hotel_id`, `memory_scope`, `id`),
  KEY `idx_user_learning_event_preference` (`preference_identity`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='append-only user learning feedback events';

CREATE TABLE IF NOT EXISTS `user_learning_memory_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL COMMENT 'strict tenant scope',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'preference owner',
  `hotel_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'optional hotel scope',
  `memory_scope` VARCHAR(20) NOT NULL COMMENT 'global/hotel/session',
  `session_ref_hash` CHAR(64) DEFAULT NULL COMMENT 'one-way session scope identity',
  `preference_key` VARCHAR(128) NOT NULL,
  `preference_identity` CHAR(64) NOT NULL COMMENT 'scope and key identity',
  `version` INT UNSIGNED NOT NULL COMMENT 'monotonic version within preference identity',
  `event_id` BIGINT UNSIGNED NOT NULL COMMENT 'originating append-only feedback event',
  `learning_status` VARCHAR(32) NOT NULL COMMENT 'explicit_confirmed/inferred/insufficient',
  `lifecycle_status` VARCHAR(20) NOT NULL COMMENT 'active/revoked/reset',
  `value_json` JSON NOT NULL COMMENT 'canonical bounded preference value',
  `value_hash` CHAR(64) NOT NULL COMMENT 'canonical value digest',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_learning_preference_version` (`preference_identity`, `version`),
  KEY `idx_user_learning_preference_scope` (`tenant_id`, `user_id`, `hotel_id`, `memory_scope`, `preference_key`, `version`),
  KEY `idx_user_learning_preference_event` (`event_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='append-only versioned user preference projection';
