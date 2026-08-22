-- 店长能力评分优化：结构化证据、追加式纠错/作废、人工评分复核。
-- 原始三问、原始评分和复查事件均保持不变；所有修正只追加事件。
-- 本模块只用于当前租户/酒店内的管理复盘，不触发跨店排名、处罚或外部运营动作。

ALTER TABLE `manager_capability_cases`
  ADD COLUMN IF NOT EXISTS `evidence_type` VARCHAR(32) DEFAULT NULL COMMENT 'onsite_observation/signed_checklist/system_record/guest_feedback/photo_record/other' AFTER `followup_due_date`;

ALTER TABLE `manager_capability_cases`
  ADD COLUMN IF NOT EXISTS `evidence_reference` VARCHAR(500) DEFAULT NULL COMMENT '台账、记录或附件引用；不存凭证' AFTER `evidence_type`;

ALTER TABLE `manager_capability_cases`
  ADD COLUMN IF NOT EXISTS `evidence_date` DATE DEFAULT NULL COMMENT '证据业务日期，Asia/Shanghai' AFTER `evidence_reference`;

ALTER TABLE `manager_capability_cases`
  ADD COLUMN IF NOT EXISTS `evidence_confidence` VARCHAR(24) NOT NULL DEFAULT 'unverified' COMMENT 'high/medium/unverified，与能力分分开' AFTER `evidence_date`;

ALTER TABLE `manager_capability_case_followups`
  ADD COLUMN IF NOT EXISTS `evidence_type` VARCHAR(32) DEFAULT NULL COMMENT '结构化证据类型' AFTER `sample_count`;

ALTER TABLE `manager_capability_case_followups`
  ADD COLUMN IF NOT EXISTS `evidence_date` DATE DEFAULT NULL COMMENT '证据业务日期，Asia/Shanghai' AFTER `evidence_reference`;

ALTER TABLE `manager_capability_case_followups`
  ADD COLUMN IF NOT EXISTS `evidence_confidence` VARCHAR(24) NOT NULL DEFAULT 'unverified' COMMENT 'high/medium/unverified，与能力分分开' AFTER `evidence_date`;

CREATE TABLE IF NOT EXISTS `manager_capability_case_adjustments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL COMMENT '被修正的原始案例ID',
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `manager_user_id` BIGINT UNSIGNED NOT NULL COMMENT '被评估店长/负责人用户ID',
  `adjustment_type` VARCHAR(24) NOT NULL COMMENT 'corrected/voided/restored',
  `reason` VARCHAR(500) NOT NULL COMMENT '人工修正、作废或恢复原因',
  `effective_payload_json` JSON NOT NULL COMMENT '事件发生后完整有效案例投影；原案例不覆盖',
  `is_voided` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '事件发生后是否作废',
  `scoring_version` VARCHAR(64) NOT NULL COMMENT '事件发生后的评分公式版本',
  `source_reference_key` VARCHAR(96) NOT NULL COMMENT '吸纳来源引用键',
  `source_fingerprint` CHAR(64) NOT NULL COMMENT '用户附件包SHA-256',
  `dimensions_json` JSON NOT NULL COMMENT '事件发生后的六维评分快照',
  `case_score` DECIMAL(6,2) DEFAULT NULL COMMENT '事件发生后的案例均分',
  `scored_dimension_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '有分值维度数',
  `score_status` VARCHAR(32) NOT NULL COMMENT 'scored/pending_verification/data_insufficient/voided',
  `source_kind` VARCHAR(48) NOT NULL DEFAULT 'manual_manager_capability_adjustment' COMMENT '人工追加修正事件',
  `source_quality_status` VARCHAR(32) NOT NULL DEFAULT 'manual_declared' COMMENT 'manual_declared',
  `idempotency_key` VARCHAR(120) NOT NULL COMMENT '客户端重试幂等键',
  `input_digest` CHAR(64) NOT NULL COMMENT '范围与修正输入SHA-256摘要',
  `evidence_digest` CHAR(64) NOT NULL COMMENT '有效投影与评分快照SHA-256',
  `created_by` BIGINT UNSIGNED NOT NULL COMMENT '提交用户ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manager_capability_adjustment_idempotency` (`tenant_id`, `created_by`, `idempotency_key`),
  KEY `idx_manager_capability_adjustment_case` (`tenant_id`, `hotel_id`, `manager_user_id`, `case_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='店长能力案例追加修正与作废事件';

CREATE TABLE IF NOT EXISTS `manager_capability_score_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL COMMENT '被人工复核的案例ID',
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `manager_user_id` BIGINT UNSIGNED NOT NULL COMMENT '被评估店长/负责人用户ID',
  `review_outcome` VARCHAR(24) NOT NULL COMMENT 'confirmed/adjusted',
  `reason` VARCHAR(500) NOT NULL COMMENT '人工复核依据与原因',
  `dimension_overrides_json` JSON NOT NULL COMMENT '人工调整的维度分；confirmed时为空对象',
  `reviewed_dimensions_json` JSON NOT NULL COMMENT '人工复核后完整六维评分快照',
  `reviewed_case_score` DECIMAL(6,2) DEFAULT NULL COMMENT '人工复核后的案例均分',
  `scored_dimension_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '人工复核后有分值维度数',
  `score_status` VARCHAR(32) NOT NULL COMMENT 'scored/pending_verification/data_insufficient',
  `source_score_digest` CHAR(64) NOT NULL COMMENT '复核时所见有效评分快照摘要，防止漂移',
  `source_kind` VARCHAR(48) NOT NULL DEFAULT 'manual_manager_capability_score_review' COMMENT '人工评分复核',
  `source_quality_status` VARCHAR(32) NOT NULL DEFAULT 'manual_declared' COMMENT 'manual_declared',
  `idempotency_key` VARCHAR(120) NOT NULL COMMENT '客户端重试幂等键',
  `input_digest` CHAR(64) NOT NULL COMMENT '范围与复核输入SHA-256摘要',
  `evidence_digest` CHAR(64) NOT NULL COMMENT '人工复核结果SHA-256摘要',
  `created_by` BIGINT UNSIGNED NOT NULL COMMENT '复核用户ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manager_capability_score_review_idempotency` (`tenant_id`, `created_by`, `idempotency_key`),
  KEY `idx_manager_capability_score_review_case` (`tenant_id`, `hotel_id`, `manager_user_id`, `case_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='店长能力评分人工追加复核事件';
