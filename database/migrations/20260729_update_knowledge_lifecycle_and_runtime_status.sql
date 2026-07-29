-- Knowledge lifecycle correction and traceability backfill.
-- Historical generated artifacts are retained for audit, but quarantined from
-- decision-time retrieval. No knowledge rows are deleted.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `knowledge_units`
  ADD COLUMN IF NOT EXISTS `lifecycle_status`
    enum('active','stale','quarantined') NOT NULL DEFAULT 'active'
    COMMENT 'Decision retrieval lifecycle: active, stale, quarantined'
    AFTER `status`,
  ADD COLUMN IF NOT EXISTS `lifecycle_reason`
    varchar(255) DEFAULT NULL
    COMMENT 'Why this unit is stale or quarantined'
    AFTER `lifecycle_status`,
  ADD COLUMN IF NOT EXISTS `reviewed_at`
    datetime DEFAULT NULL
    COMMENT 'Latest explicit knowledge review time'
    AFTER `lifecycle_reason`;

-- Retain the six 2026-05-20 generated research snapshots, but stop treating
-- research-generation completion as current decision readiness.
UPDATE `knowledge_units` AS `ku`
JOIN `knowledge_chunks` AS `kc` ON `kc`.`unit_id` = `ku`.`unit_id`
SET
  `ku`.`lifecycle_status` = 'quarantined',
  `ku`.`lifecycle_reason` = '旧版收益研究快照缺少现行readiness、decision_ready与执行门禁，等待按当前合同重新生成',
  `ku`.`reviewed_at` = '2026-07-29 00:00:00'
WHERE `ku`.`source` = 'revenue_research'
  AND JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.generated_at')) LIKE '2026-05-20%'
  AND JSON_EXTRACT(`kc`.`content`, '$.readiness') IS NULL;

UPDATE `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.lifecycle_status', 'quarantined',
  '$.lifecycle_reason', 'legacy_revenue_research_snapshot_missing_current_readiness_contract'
)
WHERE `ku`.`lifecycle_status` = 'quarantined'
  AND `ku`.`source` = 'revenue_research'
  AND JSON_VALID(`kc`.`content`);

-- A global synthetic one-epoch distillation run is execution history, not
-- reusable hotel operating knowledge. Its row remains available for audit.
UPDATE `knowledge_units` AS `ku`
JOIN `knowledge_chunks` AS `kc` ON `kc`.`unit_id` = `ku`.`unit_id`
SET
  `ku`.`lifecycle_status` = 'quarantined',
  `ku`.`lifecycle_reason` = 'synthetic训练产物且运行时checkpoint已失效，不作为经营知识',
  `ku`.`reviewed_at` = '2026-07-29 00:00:00'
WHERE `ku`.`source` = 'ml_distillation'
  AND `ku`.`hotel_id` = 0
  AND `ku`.`created_by` = 0
  AND JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.data.type')) = 'synthetic';

UPDATE `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.lifecycle_status', 'quarantined',
  '$.lifecycle_reason', 'global_synthetic_distillation_artifact_not_operating_knowledge'
)
WHERE `ku`.`lifecycle_status` = 'quarantined'
  AND `ku`.`source` = 'ml_distillation'
  AND JSON_VALID(`kc`.`content`);

-- The old empty experience placeholder has no reusable fact or method. Keep it
-- in place, but make the chunk-level lifecycle explicit.
UPDATE `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.lifecycle_status', 'quarantined',
  '$.lifecycle_reason', 'empty_legacy_experience_placeholder'
)
WHERE `ku`.`source` = 'meituan'
  AND `kc`.`type` = '经验片段'
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.raw')), '') = ''
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.distilled_experience')), '') = '';

DROP TEMPORARY TABLE IF EXISTS `tmp_knowledge_traceability_backfill`;
CREATE TEMPORARY TABLE `tmp_knowledge_traceability_backfill` (
  `unit_name` varchar(255) NOT NULL,
  `unit_source` varchar(50) NOT NULL,
  `scope_value` varchar(120) NOT NULL,
  `evidence_value` varchar(160) NOT NULL,
  `source_ref` varchar(255) NOT NULL,
  PRIMARY KEY (`unit_name`, `unit_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_knowledge_traceability_backfill`
  (`unit_name`, `unit_source`, `scope_value`, `evidence_value`, `source_ref`)
VALUES
  (
    '美团 eBooking 浏览器自动化采集方法',
    'meituan',
    'authorized_ota_collection_methodology',
    'internal_authorized_browser_collection_method_reviewed',
    'database/migrations/20260519_seed_meituan_browser_capture_knowledge.sql'
  ),
  (
    'OTA平台可确认字段与假设字段清单',
    'ota',
    'generic_ota_field_inventory_reference',
    'user_provided_reference_platform_claims_require_live_recheck',
    'database/migrations/20260520_seed_ota_platform_field_inventory_knowledge.sql'
  ),
  (
    'OTA标准指标与推荐公式清单',
    'ota',
    'generic_ota_metric_methodology',
    'user_provided_metric_reference_formula_scope_reviewed',
    'database/migrations/20260520_seed_ota_standard_metrics_knowledge.sql'
  ),
  (
    'OTA数据产品矩阵',
    'ota',
    'generic_ota_product_planning_reference',
    'user_provided_product_planning_reference_not_runtime',
    'database/migrations/20260520_seed_ota_data_product_matrix_knowledge.sql'
  ),
  (
    'OTA数据分层架构与治理规则',
    'ota',
    'generic_ota_data_architecture_blueprint',
    'user_provided_architecture_blueprint_not_runtime',
    'database/migrations/20260520_seed_ota_data_architecture_governance_knowledge.sql'
  ),
  (
    'OTA手动与自动获取策略',
    'ota',
    'authorized_ota_collection_strategy',
    'internal_collection_strategy_reference_runtime_requires_current_session',
    'database/migrations/20260520_seed_ota_manual_auto_collection_strategy_knowledge.sql'
  ),
  (
    '房型经营分析报告解读话术库',
    'room_type_analysis_communication',
    'generic_room_type_analysis_communication_not_hotel_fact',
    'user_provided_unverified_communication_reference',
    'docs/room_type_operation_analysis_communication_playbook.md'
  ),
  (
    'OTA每日经营台账与晨报闭环',
    'ota_daily_operations_ledger_reference',
    'ota_channel_daily_operations',
    'historical_user_workbook_structure_reviewed_values_unverified',
    '.agents/skills/suxi-ota-ops/references/ota-daily-operations-ledger.md'
  ),
  (
    '经营目标差值检测与节奏判断',
    'operating_target_delta_detection_reference',
    'generic_operating_target_methodology',
    'user_source_code_reviewed_core_delta_integrated_remaining_features_partial',
    '.agents/skills/suxi-ota-revenue-semantic-layer/references/operating-target-delta-detection.md'
  );

UPDATE `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
JOIN `tmp_knowledge_traceability_backfill` AS `meta`
  ON `meta`.`unit_name` = `ku`.`name`
  AND `meta`.`unit_source` = `ku`.`source`
SET `kc`.`content` = JSON_SET(`kc`.`content`, '$.scope', `meta`.`scope_value`)
WHERE `ku`.`lifecycle_status` = 'active'
  AND JSON_VALID(`kc`.`content`)
  AND (
    JSON_EXTRACT(`kc`.`content`, '$.scope') IS NULL
    OR JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.scope')) = ''
  );

UPDATE `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
JOIN `tmp_knowledge_traceability_backfill` AS `meta`
  ON `meta`.`unit_name` = `ku`.`name`
  AND `meta`.`unit_source` = `ku`.`source`
SET `kc`.`content` = JSON_SET(`kc`.`content`, '$.evidence_level', `meta`.`evidence_value`)
WHERE `ku`.`lifecycle_status` = 'active'
  AND JSON_VALID(`kc`.`content`)
  AND (
    JSON_EXTRACT(`kc`.`content`, '$.evidence_level') IS NULL
    OR JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_level')) = ''
  );

UPDATE `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
JOIN `tmp_knowledge_traceability_backfill` AS `meta`
  ON `meta`.`unit_name` = `ku`.`name`
  AND `meta`.`unit_source` = `ku`.`source`
SET `kc`.`content` = JSON_SET(`kc`.`content`, '$.source_refs', JSON_ARRAY(`meta`.`source_ref`))
WHERE `ku`.`lifecycle_status` = 'active'
  AND JSON_VALID(`kc`.`content`)
  AND (
    JSON_EXTRACT(`kc`.`content`, '$.source_refs') IS NULL
    OR JSON_LENGTH(JSON_EXTRACT(`kc`.`content`, '$.source_refs')) = 0
  );

UPDATE `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(`kc`.`content`, '$.lifecycle_status', 'active')
WHERE `ku`.`lifecycle_status` = 'active'
  AND JSON_VALID(`kc`.`content`)
  AND JSON_EXTRACT(`kc`.`content`, '$.lifecycle_status') IS NULL;

SET @operating_delta_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = '经营目标差值检测与节奏判断'
    AND `source` = 'operating_target_delta_detection_reference'
  ORDER BY `unit_id` DESC
  LIMIT 1
);

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.knowledge_status', 'method_adapted_core_runtime_integrated',
  '$.evidence_level', 'user_source_code_reviewed_core_delta_integrated_remaining_features_partial',
  '$.blocked_uses', JSON_ARRAY(
    'current_hotel_fact_without_verified_capture',
    'cross_hotel_threshold',
    'automatic_pricing',
    'automatic_inventory_write',
    'claim_full_runtime_feature_equivalence'
  ),
  '$.seed_version', '2026-07-29.1'
)
WHERE `unit_id` = @operating_delta_unit_id
  AND `type` = 'source_boundary'
  AND JSON_VALID(`content`);

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.current_runtime_inputs', JSON_ARRAY(
    'OperatingTargetService versioned daily snapshots',
    'PmsFactReconciliationService verified same-source adjacent-snapshot gap and delta',
    'Dingdandao verified accommodation room fee captures',
    'manual confirmed whole hotel or accommodation room fee facts'
  ),
  '$.not_yet_implemented', JSON_ARRAY(
    'same-hotel historical pace learning',
    'verified cumulative cancellation capture',
    'complete target gap narrowing and widening engine',
    'full-house and delta alert state machine'
  ),
  '$.truthful_completion_statement', 'core_adjacent_snapshot_delta_online_remaining_advanced_features_partial',
  '$.seed_version', '2026-07-29.1'
)
WHERE `unit_id` = @operating_delta_unit_id
  AND `type` = 'landing_status'
  AND JSON_VALID(`content`);

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(`content`, '$.seed_version', '2026-07-29.1')
WHERE `unit_id` = @operating_delta_unit_id
  AND JSON_VALID(`content`);

UPDATE `knowledge_units`
SET
  `description` = '从用户提供的经营目标源码包中复核并改写的差值检测方法；同源相邻快照gap+delta核心已接入PmsFactReconciliationService，历史节奏、累计取消和完整提醒状态机仍为partial。',
  `reviewed_at` = '2026-07-29 00:00:00'
WHERE `unit_id` = @operating_delta_unit_id;

UPDATE `knowledge_base`
SET
  `content` = REPLACE(
    REPLACE(
      `content`,
      '## 比宿析OS当前单点计算更强的部分',
      '## 已吸收的方法与剩余能力'
    ),
    '知识、公式、边界和检索入口已沉淀；OperatingTargetService运行时差值引擎、同店历史节奏、取消累计采集和提醒状态机尚未上线。',
    '知识、公式、边界和检索入口已沉淀；PmsFactReconciliationService已接入同源已验证相邻快照的gap+delta核心。完整目标节奏曲线、同店历史节奏、累计取消采集和提醒状态机仍未上线，当前状态为partial。'
  )
WHERE `title` = '经营目标差值检测与节奏判断'
  AND `hotel_id` = 0;

DROP TEMPORARY TABLE IF EXISTS `tmp_knowledge_traceability_backfill`;
