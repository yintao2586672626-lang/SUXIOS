-- 店长能力评分：保存三问原始案例、可解释六维证据分和精确回读摘要。
-- 吸纳来源附件SHA-256：2CF5141F480243EBEA75D0520FD299BC2EE4ACB0E8F752113D8B93DB489CEF66
--
-- 评分仅针对当前租户/酒店内人工提交的管理案例。它不是 OTA 或 PMS 事实，
-- 不用于跨店排名、处罚、自动审批或自动执行任何运营动作。
CREATE TABLE IF NOT EXISTS `manager_capability_cases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `manager_user_id` BIGINT UNSIGNED NOT NULL COMMENT '被评估店长/负责人用户ID',
  `manager_name_snapshot` VARCHAR(120) NOT NULL COMMENT '提交时被评估人名称快照',
  `business_date` DATE NOT NULL COMMENT '案例业务日期，Asia/Shanghai',
  `problem_facts` TEXT NOT NULL COMMENT '问题事实：何时、何地、何人、何事',
  `action_taken` TEXT NOT NULL COMMENT '已采取动作',
  `verification_status` VARCHAR(24) NOT NULL COMMENT 'observed_result/planned_verification',
  `verification_text` TEXT NOT NULL COMMENT '验证结果或明确复查计划',
  `followup_due_date` DATE DEFAULT NULL COMMENT '计划复查日期；已观察结果时为空',
  `case_status` VARCHAR(24) NOT NULL COMMENT 'closed/pending_verification/data_insufficient',
  `source_kind` VARCHAR(48) NOT NULL DEFAULT 'manual_management_three_questions' COMMENT '人工声明案例，不冒充系统事实',
  `source_quality_status` VARCHAR(32) NOT NULL DEFAULT 'manual_declared' COMMENT 'manual_declared',
  `idempotency_key` VARCHAR(120) NOT NULL COMMENT '客户端重试幂等键',
  `input_digest` CHAR(64) NOT NULL COMMENT '范围与三问输入SHA-256摘要',
  `created_by` BIGINT UNSIGNED NOT NULL COMMENT '提交用户ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manager_capability_case_idempotency` (`tenant_id`, `created_by`, `idempotency_key`),
  KEY `idx_manager_capability_case_profile` (`tenant_id`, `hotel_id`, `manager_user_id`, `business_date`, `id`),
  KEY `idx_manager_capability_case_status` (`tenant_id`, `hotel_id`, `case_status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='店长管理三问案例';

CREATE TABLE IF NOT EXISTS `manager_capability_score_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL COMMENT '三问案例ID',
  `tenant_id` INT UNSIGNED NOT NULL COMMENT '租户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '宿析酒店ID',
  `manager_user_id` BIGINT UNSIGNED NOT NULL COMMENT '被评估店长/负责人用户ID',
  `formula_version` VARCHAR(64) NOT NULL COMMENT '评分公式版本',
  `source_reference_key` VARCHAR(96) NOT NULL COMMENT '吸纳来源引用键',
  `source_fingerprint` CHAR(64) NOT NULL COMMENT '用户附件包SHA-256',
  `dimensions_json` JSON NOT NULL COMMENT '六维分数、证据引用和缺失原因',
  `case_score` DECIMAL(6,2) DEFAULT NULL COMMENT '六维均有证据时的案例均分，否则为空',
  `scored_dimension_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '有分值维度数',
  `score_status` VARCHAR(32) NOT NULL COMMENT 'scored/pending_verification/data_insufficient',
  `evidence_digest` CHAR(64) NOT NULL COMMENT '评分证据快照SHA-256',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manager_capability_case_formula` (`case_id`, `formula_version`),
  UNIQUE KEY `uniq_manager_capability_evidence_digest` (`tenant_id`, `hotel_id`, `manager_user_id`, `evidence_digest`),
  KEY `idx_manager_capability_score_profile` (`tenant_id`, `hotel_id`, `manager_user_id`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='店长六维能力证据分快照';
