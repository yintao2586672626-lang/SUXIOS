ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `readback_verified` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '1 only after persisted identity and observed metrics are read back successfully',
  ADD COLUMN IF NOT EXISTS `readback_verified_at` DATETIME DEFAULT NULL
    COMMENT 'latest successful database readback verification time',
  ADD INDEX IF NOT EXISTS `idx_online_daily_ai_trust`
    (`system_hotel_id`, `data_date`, `readback_verified`, `source`, `data_type`);

ALTER TABLE `ai_daily_reports`
  ADD COLUMN IF NOT EXISTS `input_fingerprint` CHAR(64) NOT NULL DEFAULT ''
    COMMENT 'sha256 of canonical trusted report input',
  ADD COLUMN IF NOT EXISTS `prompt_version` VARCHAR(60) NOT NULL DEFAULT ''
    COMMENT 'report prompt and merge contract version',
  ADD COLUMN IF NOT EXISTS `cache_hit_count` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'number of identical trusted-input cache hits',
  ADD INDEX IF NOT EXISTS `idx_ai_daily_reports_fingerprint`
    (`hotel_id`, `report_date`, `input_fingerprint`);

CREATE TABLE IF NOT EXISTS `ai_report_generation_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` VARCHAR(96) NOT NULL COMMENT 'public opaque task id',
  `tenant_id` INT UNSIGNED DEFAULT NULL COMMENT 'tenant id aligned to hotel scope',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT 'single permitted hotel id',
  `report_date` DATE NOT NULL COMMENT 'report business date',
  `requested_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'requesting user id',
  `model_key` VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'requested model key',
  `use_llm` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'whether model enhancement was requested',
  `prompt_version` VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'prompt and merge contract used by this task',
  `trusted_input_version` VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'trusted input selection contract used by this task',
  `trusted_input_revision` CHAR(64) NOT NULL DEFAULT '' COMMENT 'verified source revision used for active deduplication',
  `active_dedupe_key` CHAR(64) DEFAULT NULL COMMENT 'unique only while task is queued or running',
  `lease_expires_at` DATETIME DEFAULT NULL COMMENT 'worker lease; expired active tasks are recoverable',
  `input_fingerprint` CHAR(64) NOT NULL DEFAULT '' COMMENT 'resolved trusted input fingerprint',
  `model_status` VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'ok/not_requested/blocked_by_data_quality/failed/invalid_output',
  `status` VARCHAR(40) NOT NULL DEFAULT 'queued' COMMENT 'queued/running/succeeded/partial/blocked/failed',
  `stage` VARCHAR(40) NOT NULL DEFAULT 'queued' COMMENT 'current public execution stage',
  `progress_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 through 100',
  `result_report_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'generated ai_daily_reports id',
  `cache_hit` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'whether report generation reused a valid result',
  `error_code` VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'stable failure code',
  `error_message` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'safe failure message',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` DATETIME DEFAULT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ai_report_generation_task_id` (`task_id`),
  UNIQUE KEY `uk_ai_report_generation_active_dedupe` (`active_dedupe_key`),
  KEY `idx_ai_report_generation_hotel_status` (`hotel_id`, `status`, `created_at`),
  KEY `idx_ai_report_generation_user_status` (`requested_by`, `status`, `created_at`),
  KEY `idx_ai_report_generation_cleanup` (`status`, `updated_at`, `id`),
  KEY `idx_ai_report_generation_dedupe`
    (`hotel_id`, `report_date`, `model_key`, `use_llm`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='asynchronous AI daily report generation tasks';

ALTER TABLE `ai_report_generation_tasks`
  ADD COLUMN IF NOT EXISTS `prompt_version` VARCHAR(60) NOT NULL DEFAULT ''
    COMMENT 'prompt and merge contract used by this task' AFTER `use_llm`,
  ADD COLUMN IF NOT EXISTS `trusted_input_version` VARCHAR(60) NOT NULL DEFAULT ''
    COMMENT 'trusted input selection contract used by this task' AFTER `prompt_version`,
  ADD COLUMN IF NOT EXISTS `trusted_input_revision` CHAR(64) NOT NULL DEFAULT ''
    COMMENT 'verified source revision used for active deduplication' AFTER `trusted_input_version`,
  ADD COLUMN IF NOT EXISTS `lease_expires_at` DATETIME DEFAULT NULL
    COMMENT 'worker lease; expired active tasks are recoverable' AFTER `active_dedupe_key`,
  ADD COLUMN IF NOT EXISTS `model_status` VARCHAR(40) NOT NULL DEFAULT ''
    COMMENT 'truthful terminal model outcome' AFTER `input_fingerprint`,
  ADD INDEX IF NOT EXISTS `idx_ai_report_generation_cleanup`
    (`status`, `updated_at`, `id`);

CREATE TABLE IF NOT EXISTS `ai_report_input_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `input_fingerprint` CHAR(64) NOT NULL COMMENT 'canonical trusted input identity',
  `tenant_id` INT UNSIGNED DEFAULT NULL COMMENT 'tenant id resolved from hotels.tenant_id',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT 'hotel scope',
  `report_date` DATE NOT NULL COMMENT 'business date',
  `model_key` VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'model variant in fingerprint',
  `use_llm` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'generation mode in fingerprint',
  `prompt_version` VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'prompt contract version',
  `ai_explanation` TEXT DEFAULT NULL COMMENT 'bounded validated model explanation only',
  `ai_interpretation_json` JSON DEFAULT NULL COMMENT 'complete validated interpretation; legacy rows may be null',
  `model_status` VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'only ok or not_requested are cacheable',
  `hit_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ai_report_input_cache_fingerprint` (`input_fingerprint`),
  KEY `idx_ai_report_input_cache_cleanup` (`updated_at`, `id`),
  KEY `idx_ai_report_input_cache_scope`
    (`hotel_id`, `report_date`, `model_key`, `use_llm`, `prompt_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='valid trusted AI report input cache independent from report display slot';

ALTER TABLE `ai_report_input_cache`
  ADD COLUMN IF NOT EXISTS `ai_interpretation_json` JSON DEFAULT NULL
    COMMENT 'complete validated interpretation; legacy rows may be null' AFTER `ai_explanation`,
  ADD INDEX IF NOT EXISTS `idx_ai_report_input_cache_cleanup` (`updated_at`, `id`);
