-- 宿析经营闭环唯一权威根记录。
--
-- 原始 PMS/OTA 事实、建议、执行、回执和知识仍保留在各自业务表；本迁移只建立
-- 一店一业务日的一条权威状态投影、不可变迁移事件和精确数据库行引用。
-- 业务模块不得直接更新本表状态，必须通过 OperatingLoopKernelService 追加事件。

CREATE TABLE IF NOT EXISTS `hotel_operating_cycles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `authority_key` CHAR(64) NOT NULL COMMENT 'tenant+hotel+business_date 唯一权威键',
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `hotel_name_snapshot` VARCHAR(191) NOT NULL,
  `business_date` DATE NOT NULL,
  `metric_version` VARCHAR(80) NOT NULL,
  `metric_definition_json` JSON NOT NULL,
  `metric_definition_digest` CHAR(64) NOT NULL,
  `source_identities_json` JSON NOT NULL,
  `source_identity_digest` CHAR(64) NOT NULL,
  `last_completed_stage` VARCHAR(80) NOT NULL DEFAULT '',
  `last_completed_stage_index` SMALLINT NOT NULL DEFAULT -1,
  `next_required_stage` VARCHAR(80) NOT NULL DEFAULT 'identity_business_date_confirmed',
  `cycle_status` VARCHAR(24) NOT NULL DEFAULT 'active' COMMENT 'active/blocked/completed',
  `block_code` VARCHAR(120) NOT NULL DEFAULT '',
  `block_detail` VARCHAR(1000) NOT NULL DEFAULT '',
  `truth_summary` TEXT NOT NULL,
  `priority_issue` VARCHAR(1000) NOT NULL DEFAULT '',
  `next_action` VARCHAR(1000) NOT NULL DEFAULT '',
  `next_owner_json` JSON DEFAULT NULL,
  `review_due_at` DATETIME DEFAULT NULL,
  `outcome_status` VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/supported/refuted/indeterminate',
  `experience_status` VARCHAR(32) NOT NULL DEFAULT 'not_reviewed' COMMENT 'not_reviewed/not_reusable/candidate/promoted/rejected',
  `state_version` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_event_id` BIGINT UNSIGNED DEFAULT NULL,
  `last_event_digest` CHAR(64) NOT NULL DEFAULT '',
  `projection_digest` CHAR(64) NOT NULL DEFAULT '',
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hotel_operating_cycle_authority` (`tenant_id`, `hotel_id`, `business_date`),
  UNIQUE KEY `uniq_hotel_operating_cycle_authority_key` (`authority_key`),
  KEY `idx_hotel_operating_cycle_state` (`tenant_id`, `hotel_id`, `cycle_status`, `business_date`, `id`),
  KEY `idx_hotel_operating_cycle_review` (`tenant_id`, `review_due_at`, `outcome_status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='一店一业务日的宿析经营闭环权威状态投影';

CREATE TABLE IF NOT EXISTS `hotel_operating_cycle_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` BIGINT UNSIGNED NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `command_key` VARCHAR(191) NOT NULL,
  `stage_key` VARCHAR(80) NOT NULL,
  `stage_status` VARCHAR(24) NOT NULL COMMENT 'completed/blocked',
  `actor_kind` VARCHAR(24) NOT NULL COMMENT 'human/system',
  `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_module` VARCHAR(80) NOT NULL,
  `payload_json` JSON NOT NULL,
  `evidence_digest` CHAR(64) NOT NULL,
  `previous_event_digest` CHAR(64) NOT NULL DEFAULT '',
  `event_digest` CHAR(64) NOT NULL,
  `occurred_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hotel_operating_cycle_event_sequence` (`cycle_id`, `sequence_no`),
  UNIQUE KEY `uniq_hotel_operating_cycle_event_command` (`cycle_id`, `command_key`),
  UNIQUE KEY `uniq_hotel_operating_cycle_event_digest` (`cycle_id`, `event_digest`),
  KEY `idx_hotel_operating_cycle_event_stage` (`cycle_id`, `stage_key`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='经营闭环不可变状态迁移事件';

CREATE TABLE IF NOT EXISTS `hotel_operating_cycle_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` BIGINT UNSIGNED NOT NULL,
  `event_id` BIGINT UNSIGNED NOT NULL,
  `stage_key` VARCHAR(80) NOT NULL,
  `evidence_role` VARCHAR(48) NOT NULL,
  `source_kind` VARCHAR(24) NOT NULL COMMENT 'identity/pms/ota/decision/approval/execution/outcome/knowledge',
  `platform` VARCHAR(40) NOT NULL DEFAULT '',
  `business_date` DATE DEFAULT NULL,
  `source_table` VARCHAR(80) NOT NULL,
  `source_row_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source_row_ids_json` JSON NOT NULL,
  `source_row_count` INT UNSIGNED NOT NULL,
  `source_rows_digest` CHAR(64) NOT NULL,
  `readback_verified` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hotel_operating_cycle_evidence` (`event_id`, `evidence_role`, `source_table`, `source_rows_digest`),
  KEY `idx_hotel_operating_cycle_evidence_cycle` (`cycle_id`, `stage_key`, `id`),
  KEY `idx_hotel_operating_cycle_evidence_source` (`source_table`, `source_row_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='经营闭环事件引用的精确数据库行，不复制原始事实';

-- 回滚需先导出三表；事件和证据为不可变审计事实，不提供业务 DELETE 路径。
-- DROP TABLE IF EXISTS `hotel_operating_cycle_evidence`;
-- DROP TABLE IF EXISTS `hotel_operating_cycle_events`;
-- DROP TABLE IF EXISTS `hotel_operating_cycles`;
