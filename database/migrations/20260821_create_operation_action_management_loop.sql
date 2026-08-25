-- 运营行动从建议、审批、任务、证据到效果复盘的追加式管理账本。
--
-- 既有 operation_execution_* 表继续承担兼容投影；本迁移不回填、不改写任何
-- 历史意图、任务或证据。新合同只通过追加事件和追加复盘保存版本历史。
CREATE TABLE IF NOT EXISTS `operation_action_lifecycle_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `intent_id` BIGINT UNSIGNED NOT NULL COMMENT '执行意图ID',
  `task_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联运营任务ID，尚未生成时为0',
  `sequence_no` INT UNSIGNED NOT NULL COMMENT '同一行动内从1递增的事件序号',
  `event_type` VARCHAR(48) NOT NULL COMMENT 'drafted/submitted/approved/started/completed/evidence_attached/reviewed/cancelled/blocked',
  `from_status` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '事件前统一状态',
  `to_status` VARCHAR(30) NOT NULL COMMENT 'draft/pending_approval/approved/in_progress/completed/reviewed/cancelled',
  `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '触发事件的用户ID；系统只读回读为0',
  `event_payload_json` JSON NOT NULL COMMENT '行动卡或状态变化的不可变证据快照',
  `previous_digest` CHAR(64) NOT NULL DEFAULT '' COMMENT '上一事件摘要；首事件为空',
  `content_digest` CHAR(64) NOT NULL COMMENT '当前事件完整内容SHA-256摘要',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operation_action_event_sequence` (`tenant_id`, `hotel_id`, `intent_id`, `sequence_no`),
  UNIQUE KEY `uniq_operation_action_event_digest` (`tenant_id`, `hotel_id`, `intent_id`, `content_digest`),
  KEY `idx_operation_action_event_task` (`tenant_id`, `hotel_id`, `task_id`, `id`),
  KEY `idx_operation_action_event_status` (`tenant_id`, `hotel_id`, `to_status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='运营行动统一生命周期追加事件账本';

CREATE TABLE IF NOT EXISTS `operation_action_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `intent_id` BIGINT UNSIGNED NOT NULL COMMENT '执行意图ID',
  `task_id` BIGINT UNSIGNED NOT NULL COMMENT '运营任务ID',
  `effect_review_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '证据充分时关联的严格效果复盘ID',
  `contract_version` VARCHAR(64) NOT NULL COMMENT '复盘合同版本',
  `metric_key` VARCHAR(80) NOT NULL COMMENT '复盘指标键',
  `metric_unit` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '审批冻结的指标单位',
  `baseline_window_json` JSON NOT NULL COMMENT '执行前指标窗口及事实引用',
  `followup_window_json` JSON NOT NULL COMMENT '执行后指标窗口及事实引用',
  `before_value` DECIMAL(20,6) DEFAULT NULL COMMENT '严格回读的执行前值；证据不足时为空',
  `after_value` DECIMAL(20,6) DEFAULT NULL COMMENT '严格回读的执行后值；证据不足时为空',
  `delta_value` DECIMAL(20,6) DEFAULT NULL COMMENT 'after-before；证据不足时为空',
  `metric_change_status` VARCHAR(24) NOT NULL COMMENT 'increased/decreased/unchanged/unknown',
  `evidence_sufficiency` VARCHAR(24) NOT NULL COMMENT 'sufficient/insufficient/mismatched',
  `evidence_refs_json` JSON NOT NULL COMMENT '执行证据、来源回读及严格复盘引用',
  `non_attribution_reasons_json` JSON NOT NULL COMMENT '不能归因或证据不足原因',
  `recommendation` VARCHAR(20) NOT NULL COMMENT 'continue/adjust/stop',
  `result_status` VARCHAR(30) NOT NULL COMMENT 'observing/success/near_success/failed',
  `result_summary` VARCHAR(1000) NOT NULL COMMENT '人工复盘说明',
  `causality_claimed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '固定为0，不宣称因果',
  `reviewed_by` BIGINT UNSIGNED NOT NULL COMMENT '复盘人用户ID',
  `reviewed_at` DATETIME NOT NULL COMMENT '复盘时间',
  `previous_review_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '上一复盘版本ID',
  `previous_digest` CHAR(64) NOT NULL DEFAULT '' COMMENT '上一复盘版本摘要',
  `content_digest` CHAR(64) NOT NULL COMMENT '完整复盘内容SHA-256摘要',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operation_action_review_digest` (`tenant_id`, `hotel_id`, `task_id`, `content_digest`),
  KEY `idx_operation_action_review_trace` (`tenant_id`, `hotel_id`, `intent_id`, `task_id`, `id`),
  KEY `idx_operation_action_review_evidence` (`tenant_id`, `evidence_sufficiency`, `recommendation`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='运营行动效果复盘追加版本账本';

CREATE TRIGGER IF NOT EXISTS `trg_operation_action_event_no_update`
BEFORE UPDATE ON `operation_action_lifecycle_events`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'operation action lifecycle event is append-only';

CREATE TRIGGER IF NOT EXISTS `trg_operation_action_event_no_delete`
BEFORE DELETE ON `operation_action_lifecycle_events`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'operation action lifecycle event is append-only';

CREATE TRIGGER IF NOT EXISTS `trg_operation_action_review_no_update`
BEFORE UPDATE ON `operation_action_reviews`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'operation action review is append-only';

CREATE TRIGGER IF NOT EXISTS `trg_operation_action_review_no_delete`
BEFORE DELETE ON `operation_action_reviews`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'operation action review is append-only';
