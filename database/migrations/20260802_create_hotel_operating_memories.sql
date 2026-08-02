-- 宿析经营记忆最小持久化：保存分层索引、来源引用、可信状态和允许用途。
-- 底层事实继续保留在原业务表，本表不复制原始 OTA 数值，也不承担执行或外发职责。
CREATE TABLE IF NOT EXISTS `hotel_operating_memories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `memory_key` VARCHAR(191) NOT NULL COMMENT '同内容幂等键；内容变化产生新版本',
  `memory_layer` VARCHAR(40) NOT NULL COMMENT 'fact/analysis/decision/execution_review/sop',
  `title` VARCHAR(191) NOT NULL DEFAULT '' COMMENT '不可歧义的人类可读标题',
  `summary` TEXT NOT NULL COMMENT '经营摘要，不替代底层事实',
  `business_date` DATE DEFAULT NULL COMMENT '业务日期',
  `platform` VARCHAR(40) NOT NULL DEFAULT '' COMMENT '平台或经营范围',
  `source_scope` VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'ota_channel/whole_hotel/operation_execution等',
  `source_module` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '直接来源模块',
  `source_record_type` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '直接来源记录类型',
  `source_record_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '直接来源记录ID',
  `evidence_refs_json` JSON DEFAULT NULL COMMENT '只保存可回查的证据引用',
  `context_json` JSON DEFAULT NULL COMMENT '质量判定、上游来源和SOP候选等非敏感上下文',
  `quality_status` VARCHAR(40) NOT NULL DEFAULT 'unverified' COMMENT 'verified/partial/unverified/conflicted/expired',
  `usage_level` VARCHAR(40) NOT NULL DEFAULT 'archive_only' COMMENT 'archive_only/reference/decision_support/sop_template',
  `lifecycle_status` VARCHAR(30) NOT NULL DEFAULT 'active' COMMENT 'active/superseded/archived',
  `content_digest` CHAR(64) NOT NULL COMMENT '当前版本内容摘要',
  `previous_memory_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '同一来源上一版本ID',
  `recorded_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '沉淀人用户ID',
  `occurred_at` DATETIME DEFAULT NULL COMMENT '所记录经营事件发生时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operating_memory_identity` (`tenant_id`, `hotel_id`, `memory_key`),
  KEY `idx_operating_memory_scope_date` (`tenant_id`, `hotel_id`, `business_date`, `id`),
  KEY `idx_operating_memory_layer_usage` (`tenant_id`, `memory_layer`, `usage_level`, `id`),
  KEY `idx_operating_memory_source` (`tenant_id`, `hotel_id`, `source_record_type`, `source_record_id`, `id`),
  KEY `idx_operating_memory_previous` (`previous_memory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='宿析经营记忆分层索引';

-- 可回退：本表不承载原业务事实。明确回退前先导出需要保留的经营记忆，然后人工执行：
-- DROP TABLE IF EXISTS `hotel_operating_memories`;
