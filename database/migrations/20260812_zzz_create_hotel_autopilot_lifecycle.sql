-- Automatic lifecycle projections for tenants and hotels.
--
-- These tables contain only safe status, hashes and business-row pointers.
-- OTA credentials, cookies, browser profiles, raw payloads and external-action
-- approvals remain in their existing protected stores.
CREATE TABLE IF NOT EXISTS `tenant_automation_lifecycles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'initialized',
  `current_stage` VARCHAR(64) NOT NULL DEFAULT 'tenant_recorded',
  `state_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `state_digest` CHAR(64) NOT NULL,
  `safe_state_json` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `create_time` DATETIME NOT NULL,
  `update_time` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_automation_lifecycle` (`tenant_id`),
  KEY `idx_tenant_automation_lifecycle_status` (`status`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Secret-free tenant automation lifecycle projection';

CREATE TABLE IF NOT EXISTS `hotel_automation_lifecycles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `system_hotel_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'awaiting_binding',
  `current_stage` VARCHAR(64) NOT NULL DEFAULT 'data_source_binding',
  `ota_channel_strategy` VARCHAR(24) NOT NULL DEFAULT 'none',
  `completed_stage_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `total_stage_count` SMALLINT UNSIGNED NOT NULL DEFAULT 6,
  `binding_status` VARCHAR(32) NOT NULL DEFAULT 'missing',
  `binding_digest` CHAR(64) DEFAULT NULL,
  `active_plan_id` BIGINT UNSIGNED DEFAULT NULL,
  `active_plan_hash` CHAR(64) DEFAULT NULL,
  `dispatcher_status` VARCHAR(32) NOT NULL DEFAULT 'not_provisioned',
  `dispatcher_task_name` VARCHAR(191) DEFAULT NULL,
  `first_dispatch_requested_at` DATETIME DEFAULT NULL,
  `first_trusted_business_date` DATE DEFAULT NULL,
  `last_business_date` DATE DEFAULT NULL,
  `last_dispatcher_run_id` CHAR(36) DEFAULT NULL,
  `last_run_status` VARCHAR(32) DEFAULT NULL,
  `analysis_status` VARCHAR(32) NOT NULL DEFAULT 'not_started',
  `analysis_digest` CHAR(64) DEFAULT NULL,
  `profile_draft_status` VARCHAR(32) NOT NULL DEFAULT 'not_started',
  `profile_draft_digest` CHAR(64) DEFAULT NULL,
  `failure_code` VARCHAR(120) DEFAULT NULL,
  `upstream_failure_code` VARCHAR(120) DEFAULT NULL,
  `retryable` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `next_retry_at` DATETIME DEFAULT NULL,
  `state_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `state_digest` CHAR(64) NOT NULL,
  `safe_state_json` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `create_time` DATETIME NOT NULL,
  `update_time` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hotel_automation_lifecycle` (`tenant_id`, `system_hotel_id`),
  KEY `idx_hotel_automation_lifecycle_due` (`status`, `next_retry_at`, `system_hotel_id`),
  KEY `idx_hotel_automation_lifecycle_plan` (`tenant_id`, `active_plan_id`),
  KEY `idx_hotel_automation_lifecycle_run` (`tenant_id`, `last_business_date`, `last_run_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Secret-free hotel collection, analysis and profile lifecycle projection';

-- Rollback is intentionally manual because these rows are the durable creation
-- and automation audit trail. Export them before dropping either table.
-- DROP TABLE IF EXISTS `hotel_automation_lifecycles`;
-- DROP TABLE IF EXISTS `tenant_automation_lifecycles`;
