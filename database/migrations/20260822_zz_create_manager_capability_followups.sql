-- 店长能力评分复查闭环：原始三问答案保持不变，复查结果以事件追加保存。
-- 复查仍属于当前租户/酒店内的人工声明证据，不触发排名、处罚、审批或外部操作。

ALTER TABLE `manager_capability_cases`
  ADD COLUMN IF NOT EXISTS `parent_case_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '复发案例关联的原案例ID' AFTER `id`;

ALTER TABLE `manager_capability_cases`
  ADD COLUMN IF NOT EXISTS `origin_followup_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '生成本复发案例的复查事件ID' AFTER `parent_case_id`;

ALTER TABLE `manager_capability_cases`
  ADD INDEX IF NOT EXISTS `idx_manager_capability_case_parent` (`tenant_id`, `hotel_id`, `parent_case_id`, `id`);

CREATE TABLE IF NOT EXISTS `manager_capability_case_followups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL COMMENT '被复查的原三问案例ID',
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `manager_user_id` BIGINT UNSIGNED NOT NULL COMMENT '被评估店长/负责人用户ID',
  `followup_date` DATE NOT NULL COMMENT '复查业务日期，Asia/Shanghai',
  `followup_outcome` VARCHAR(24) NOT NULL COMMENT 'resolved/still_open/recurred',
  `verification_text` TEXT NOT NULL COMMENT '本次复查观察结果或继续复查计划',
  `sample_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本次声明核对的样本数',
  `evidence_reference` VARCHAR(500) DEFAULT NULL COMMENT '可选的记录、台账或附件引用，不存凭证',
  `next_followup_date` DATE DEFAULT NULL COMMENT '仍待观察或复发案例的下次复查日期',
  `recurrence_problem_facts` TEXT DEFAULT NULL COMMENT '复发时的新问题事实',
  `recurrence_action_taken` TEXT DEFAULT NULL COMMENT '复发后采取的新动作',
  `recurrence_verification_plan` TEXT DEFAULT NULL COMMENT '复发案例的新验证计划',
  `linked_recurrence_case_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '复发时自动生成的关联新案例ID',
  `scoring_version` VARCHAR(64) NOT NULL COMMENT '本次复查评分公式版本',
  `source_reference_key` VARCHAR(96) NOT NULL COMMENT '吸纳来源引用键',
  `source_fingerprint` CHAR(64) NOT NULL COMMENT '用户附件包SHA-256',
  `dimensions_json` JSON NOT NULL COMMENT '本次复查后六维有效评分快照',
  `case_score` DECIMAL(6,2) DEFAULT NULL COMMENT '本次复查后的案例均分，证据不足时为空',
  `scored_dimension_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本次复查后有分值维度数',
  `score_status` VARCHAR(32) NOT NULL COMMENT 'scored/pending_verification/data_insufficient',
  `source_kind` VARCHAR(48) NOT NULL DEFAULT 'manual_manager_capability_followup' COMMENT '人工复查声明',
  `source_quality_status` VARCHAR(32) NOT NULL DEFAULT 'manual_declared' COMMENT 'manual_declared',
  `idempotency_key` VARCHAR(120) NOT NULL COMMENT '客户端重试幂等键',
  `input_digest` CHAR(64) NOT NULL COMMENT '范围与复查输入SHA-256摘要',
  `evidence_digest` CHAR(64) NOT NULL COMMENT '复查评分证据快照SHA-256',
  `created_by` BIGINT UNSIGNED NOT NULL COMMENT '提交用户ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manager_capability_followup_idempotency` (`tenant_id`, `created_by`, `idempotency_key`),
  KEY `idx_manager_capability_followup_case` (`tenant_id`, `hotel_id`, `manager_user_id`, `case_id`, `followup_date`, `id`),
  KEY `idx_manager_capability_followup_linked_case` (`tenant_id`, `hotel_id`, `linked_recurrence_case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='店长能力案例追加复查事件';
