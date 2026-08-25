-- 可信收益分析的不可变决策快照。
-- 页面当前可见模型、指标口径、缺失项与严格来源身份一次性冻结；
-- 后续只能追加新快照，不能覆盖或删除历史判断。
CREATE TABLE IF NOT EXISTS `revenue_decision_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `system_hotel_id` BIGINT UNSIGNED NOT NULL,
  `platform` VARCHAR(24) NOT NULL,
  `business_date` DATE NOT NULL,
  `contract_version` VARCHAR(64) NOT NULL,
  `source_refs_json` LONGTEXT NOT NULL,
  `metric_definitions_json` LONGTEXT NOT NULL,
  `visible_model_json` LONGTEXT NOT NULL,
  `missing_items_json` LONGTEXT NOT NULL,
  `evidence_summary_json` LONGTEXT NOT NULL,
  `visible_model_digest` CHAR(64) NOT NULL,
  `evidence_digest` CHAR(64) NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `idempotency_key` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_revenue_decision_snapshot_content` (`tenant_id`, `system_hotel_id`, `created_by`, `content_digest`),
  UNIQUE KEY `uniq_revenue_decision_snapshot_idempotency` (`tenant_id`, `idempotency_key`),
  KEY `idx_revenue_decision_snapshot_scope` (`tenant_id`, `system_hotel_id`, `platform`, `business_date`, `id`),
  KEY `idx_revenue_decision_snapshot_evidence` (`tenant_id`, `evidence_digest`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收益分析与经营机会不可变决策快照';

CREATE TRIGGER IF NOT EXISTS `trg_revenue_decision_snapshot_no_update`
BEFORE UPDATE ON `revenue_decision_snapshots`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revenue decision snapshot is append-only';

CREATE TRIGGER IF NOT EXISTS `trg_revenue_decision_snapshot_no_delete`
BEFORE DELETE ON `revenue_decision_snapshots`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revenue decision snapshot is append-only';
