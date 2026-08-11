-- 运营效果复盘独立事实表。
-- 执行证据继续保存在 operation_execution_evidence；本表只追加保存同口径效果回读，
-- 不承担 OTA 写入，也不允许用人工描述替代来源回读。
CREATE TABLE IF NOT EXISTS `operation_effect_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `intent_id` BIGINT UNSIGNED NOT NULL COMMENT '已人工批准的执行意图ID',
  `task_id` BIGINT UNSIGNED NOT NULL COMMENT '已执行的运营任务ID',
  `platform` VARCHAR(40) NOT NULL COMMENT '效果事实所属平台',
  `baseline_business_date` DATE NOT NULL COMMENT '执行前基准经营日期',
  `review_business_date` DATE NOT NULL COMMENT '效果回读经营日期，必须晚于基准日期',
  `metric_key` VARCHAR(80) NOT NULL COMMENT '前后严格同口径指标键',
  `metric_definition_json` JSON NOT NULL COMMENT '审批前冻结的指标定义',
  `metric_definition_digest` CHAR(64) NOT NULL COMMENT '指标键和定义的SHA-256摘要',
  `before_value` DECIMAL(20,6) NOT NULL COMMENT '来源回读的基准值',
  `after_value` DECIMAL(20,6) NOT NULL COMMENT '来源回读的复盘值',
  `expected_direction` VARCHAR(20) NOT NULL COMMENT 'increase/decrease',
  `target_type` VARCHAR(20) NOT NULL COMMENT 'absolute/delta',
  `target_value` DECIMAL(20,6) DEFAULT NULL COMMENT '人工冻结的绝对目标',
  `expected_delta` DECIMAL(20,6) DEFAULT NULL COMMENT '人工冻结的目标增量',
  `expected_delta_status` VARCHAR(30) NOT NULL COMMENT '必须为manual_confirmed',
  `target_confirmed_by` BIGINT UNSIGNED NOT NULL COMMENT '冻结目标的审批人',
  `target_confirmed_at` DATETIME NOT NULL COMMENT '冻结目标的审批时间',
  `baseline_refs_json` JSON NOT NULL COMMENT '基准事实引用',
  `followup_refs_json` JSON NOT NULL COMMENT '复盘事实引用',
  `source_readback_evidence_id` BIGINT UNSIGNED NOT NULL COMMENT '来源回读证据ID',
  `outcome_status` VARCHAR(30) NOT NULL COMMENT 'met/near/missed/adverse',
  `outcome_json` JSON NOT NULL COMMENT '确定性效果判定及来源校验摘要',
  `result_status` VARCHAR(30) NOT NULL COMMENT 'success/near_success/failed',
  `result_summary` VARCHAR(1000) NOT NULL COMMENT '人工复盘结论',
  `causality_claimed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '固定为0，只记录观察效果，不宣称因果',
  `reviewed_by` BIGINT UNSIGNED NOT NULL COMMENT '复盘人用户ID',
  `reviewed_at` DATETIME NOT NULL COMMENT '复盘时间',
  `content_digest` CHAR(64) NOT NULL COMMENT '完整不可变复盘内容SHA-256摘要',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operation_effect_review_digest` (`tenant_id`, `hotel_id`, `task_id`, `content_digest`),
  KEY `idx_operation_effect_review_task` (`tenant_id`, `hotel_id`, `intent_id`, `task_id`, `id`),
  KEY `idx_operation_effect_review_scope_date` (`tenant_id`, `hotel_id`, `platform`, `review_business_date`, `id`),
  KEY `idx_operation_effect_review_metric` (`tenant_id`, `hotel_id`, `metric_key`, `review_business_date`, `id`),
  KEY `idx_operation_effect_review_source` (`tenant_id`, `source_readback_evidence_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='运营效果复盘追加事实表';

-- 本表为追加写事实账本，不提供 UPDATE/DELETE 业务路径。
-- 如需纠正复盘，应追加新记录并由读取方按 id/created_at 展示版本历史。
