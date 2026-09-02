CREATE TABLE IF NOT EXISTS `wecom_inbound_sender_bindings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_version` VARCHAR(60) NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `source_binding_id` BIGINT UNSIGNED NOT NULL,
  `sender_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'verified',
  `verified_by` BIGINT UNSIGNED NOT NULL,
  `proof_type` VARCHAR(40) NOT NULL,
  `proof_ref` VARCHAR(191) NOT NULL,
  `proof_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wecom_sender_binding_identity` (`source_binding_id`, `sender_id_hash`),
  KEY `idx_wecom_sender_binding_scope` (`tenant_id`, `hotel_id`, `actor_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Explicit SUXIOS user mapping for hashed verified WeCom inbound senders';

CREATE TABLE IF NOT EXISTS `wecom_inbound_sender_binding_challenges` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_version` VARCHAR(60) NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `code_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code_mask` VARCHAR(16) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `expires_at` DATETIME(6) NOT NULL,
  `used_at` DATETIME(6) DEFAULT NULL,
  `source_event_id` BIGINT UNSIGNED DEFAULT NULL,
  `source_binding_id` BIGINT UNSIGNED DEFAULT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wecom_sender_binding_challenge_hash` (`code_hash`),
  KEY `idx_wecom_sender_binding_challenge_scope` (`tenant_id`, `hotel_id`, `actor_id`, `status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Single-use proof that a SUXIOS user controls one hashed WeCom sender';
