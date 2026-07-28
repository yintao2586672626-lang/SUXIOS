-- Seed the reviewed and adapted operating-target delta-detection method learned
-- from the user-provided 2026-07-25 source package. This migration stores method
-- knowledge only: it does not import hotel facts, thresholds, credentials,
-- historical snapshots or executable source code from the package.
--
-- Safe rerun contract:
-- - preserve operator-authored chunks;
-- - preserve older seed versions for traceability;
-- - update only the exact current seed owner + key + version rows.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @operating_delta_version := '2026-07-27.1';
SET @operating_delta_reviewed_at := '2026-07-27';
SET @operating_delta_seed_owner := 'suxios.operating_target_delta_detection_knowledge';
SET @operating_delta_unit_name := '经营目标差值检测与节奏判断';
SET @operating_delta_source := 'operating_target_delta_detection_reference';
SET @operating_delta_description := '从用户提供的经营目标源码包中复核并改写的宿析OS差值检测方法：同时判断目标差距与相邻快照变化，保留同租户、同门店、同日期、同范围、质量和保存回读门禁。只存方法与边界，不导入源项目固定旺季阈值或酒店事实。';

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`,
  `created_by`, `created_at`, `updated_at`
)
SELECT
  0,
  @operating_delta_unit_name,
  @operating_delta_source,
  'done',
  @operating_delta_description,
  JSON_ARRAY(
    '经营目标',
    '差值检测',
    '目标差距',
    '相邻快照',
    '净拾取',
    '节奏判断',
    '满房质量',
    '收益管理',
    'structured_knowledge',
    'user_source_code_reviewed',
    'manual_review_only'
  ),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units`
  WHERE `name` = @operating_delta_unit_name
    AND `source` = @operating_delta_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @operating_delta_description,
  `tags` = JSON_ARRAY(
    '经营目标',
    '差值检测',
    '目标差距',
    '相邻快照',
    '净拾取',
    '节奏判断',
    '满房质量',
    '收益管理',
    'structured_knowledge',
    'user_source_code_reviewed',
    'manual_review_only'
  ),
  `updated_at` = NOW()
WHERE `name` = @operating_delta_unit_name
  AND `source` = @operating_delta_source;

SET @operating_delta_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @operating_delta_unit_name
    AND `source` = @operating_delta_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_operating_delta_seed_chunks`;
CREATE TEMPORARY TABLE `tmp_operating_delta_seed_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_operating_delta_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'generic_operating_target_methodology',
    'knowledge_status', 'source_code_reviewed_method_adapted',
    'evidence_level', 'user_source_code_reviewed_branch_tests_incomplete',
    'source_file_name', '酒店经营目标_Codex学习源码包_2026-07-25.zip',
    'source_sha256', '3997FFA6BD111136A5C3C9FE24796D92945C241BBA9862B4A7C09F92343FB765',
    'package_date', '2026-07-25',
    'package_version', '1.0.0',
    'source_policy_version', '1.4.20',
    'declared_license', 'ISC',
    'reviewed_at', @operating_delta_reviewed_at,
    'source_refs', JSON_ARRAY(
      '.agents/skills/suxi-ota-revenue-semantic-layer/references/operating-target-delta-detection.md'
    ),
    'reviewed_files', JSON_ARRAY(
      'src/yunyi_target.js',
      'tests/target_module.test.js',
      'README.md',
      'PACKAGE_AUDIT.txt',
      'src/package.json'
    ),
    'validation', JSON_OBJECT(
      'archive_path_safety', 'passed',
      'internal_sha256_manifest', '45_of_45_passed',
      'javascript_syntax', 'passed',
      'source_basic_tests', '7_of_7_passed',
      'complex_delta_branches', 'not_fully_covered_by_source_tests'
    ),
    'reuse_mode', 'adapt_method_not_copy_code',
    'blocked_uses', JSON_ARRAY(
      'current_hotel_fact',
      'cross_hotel_threshold',
      'automatic_pricing',
      'automatic_inventory_write',
      'claim_runtime_feature_already_online'
    )
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'absorbed_advantages',
  JSON_OBJECT(
    'scope', 'comparative_method_review',
    'evidence_level', 'source_code_reviewed',
    'baseline', 'SUXIOS OperatingTargetService currently calculates trusted single-snapshot target metrics but has no runtime adjacent-snapshot delta engine',
    'better_capabilities', JSON_ARRAY(
      JSON_OBJECT('id', 'gap_plus_delta_dual_axis', 'name', '目标差距与相邻快照变化双轴判断', 'absorbed_as', 'always_show_gap_and_delta_separately'),
      JSON_OBJECT('id', 'multi_metric_delta_vector', 'name', '房费、ADR、RevPAR、已售、取消和剩余目标的多指标变化', 'absorbed_as', 'nullable_delta_vector'),
      JSON_OBJECT('id', 'cancellation_aware_pickup', 'name', '取消修正后的区间毛预订', 'absorbed_as', 'only_when_verified_cumulative_cancellations_exist'),
      JSON_OBJECT('id', 'time_normalized_velocity', 'name', '按真实间隔折算每小时速度', 'absorbed_as', 'elapsed_hours_normalization'),
      JSON_OBJECT('id', 'gap_change_momentum', 'name', '差距收窄或扩大的走势判断', 'absorbed_as', 'recovering_worsening_stable'),
      JSON_OBJECT('id', 'volume_price_revenue_matrix', 'name', '量价收联合观察矩阵', 'absorbed_as', 'observational_structure_not_causality'),
      JSON_OBJECT('id', 'anomaly_first_priority', 'name', '累计值回落时异常优先', 'absorbed_as', 'reversal_gate_before_advice'),
      JSON_OBJECT('id', 'full_house_quality', 'name', '健康满房与风险满房', 'absorbed_as', 'target_and_explicit_adr_target_quality'),
      JSON_OBJECT('id', 'same_hotel_pace_learning', 'name', '同店历史与同星期节奏参考', 'absorbed_as', 'comparable_hotel_history_with_sample_count'),
      JSON_OBJECT('id', 'rule_state_and_dedup', 'name', '规则标识与事件去重', 'absorbed_as', 'rule_id_evidence_refs_state_transition_alert')
    ),
    'suxios_strengths_preserved', JSON_ARRAY(
      'tenant_hotel_date_scope_identity_gate',
      'whole_hotel_accommodation_room_fee_ota_scope_separation',
      'quality_and_readback_gate',
      'null_not_zero_missing_data',
      'manual_review_before_external_action'
    )
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'comparison_contract',
  JSON_OBJECT(
    'scope', 'same_day_adjacent_snapshot_comparison',
    'evidence_level', 'suxios_adapted_decision_guardrail',
    'required_equal_fields', JSON_ARRAY(
      'tenant_id',
      'hotel_id',
      'target_date',
      'fact_scope',
      'metric_semantic_version',
      'currency',
      'timezone',
      'business_day_cutoff',
      'target_version'
    ),
    'required_source_conditions', JSON_ARRAY(
      'same_source_provider_for_automatic_capture',
      'current_and_previous_have_independent_capture_or_trace_id',
      'current_captured_at_after_previous_captured_at',
      'current_and_previous_quality_verified_or_manual_confirmed',
      'automatic_capture_readback_verified'
    ),
    'rebaseline_when', JSON_ARRAY(
      'target_changed',
      'sellable_capacity_changed',
      'room_scope_changed',
      'source_provider_changed',
      'metric_semantics_changed',
      'business_day_boundary_changed'
    ),
    'first_snapshot_rule', JSON_OBJECT(
      'rule_id', 'OT_DIFF_BASELINE_ONLY',
      'status', 'baseline_only',
      'push', false,
      'judgment', '已建立当日基线，暂无上一条可比快照'
    ),
    'blocked_claims', JSON_ARRAY(
      'first_observation_is_07_00_opening_baseline',
      'cross_scope_delta',
      'cross_hotel_delta',
      'cross_target_version_delta'
    )
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'delta_metric_contract',
  JSON_OBJECT(
    'scope', 'accommodation_room_fee_or_whole_hotel_same_scope_only',
    'evidence_level', 'derived_metric_contract',
    'elapsed_formula', 'elapsed_hours=(current_captured_at-previous_captured_at)/3600',
    'delta_vector', JSON_OBJECT(
      'delta_revenue', 'current_actual_revenue-previous_actual_revenue',
      'delta_sold_room_nights', 'current_sold_room_nights-previous_sold_room_nights',
      'delta_adr', 'current_adr-previous_adr',
      'delta_occupancy_points', 'current_occupancy_rate_percent-previous_occupancy_rate_percent',
      'delta_revpar', 'current_revpar-previous_revpar',
      'net_pickup', 'delta_sold_room_nights',
      'net_pickup_per_hour', 'net_pickup/elapsed_hours',
      'revenue_per_hour', 'delta_revenue/elapsed_hours'
    ),
    'dingdandao_current_boundary', JSON_OBJECT(
      'fact_scope', 'accommodation_room_fee',
      'available_fields', JSON_ARRAY('total_room_fee', 'adr', 'occupancy_rate_percent', 'revpar', 'sold_room_nights', 'derived_sellable_room_nights'),
      'cancellations_total', 'missing',
      'allowed_pickup_name', 'net_pickup',
      'blocked_pickup_names', JSON_ARRAY('new_bookings', 'gross_pickup', '本时段新增预订')
    ),
    'cancellation_extension', JSON_OBJECT(
      'prerequisite', 'current_and_previous_verified_cumulative_cancellations_same_scope',
      'delta_cancellations', 'current_cancellations_total-previous_cancellations_total',
      'gross_pickup', 'net_pickup+delta_cancellations',
      'gross_pickup_per_hour', 'gross_pickup/elapsed_hours',
      'blocked_when', JSON_ARRAY('cancellation_missing', 'counter_reset', 'source_changed', 'scope_changed')
    ),
    'target_gap_formulas', JSON_OBJECT(
      'revenue_progress_gap_points', 'current_completion_rate-expected_completion_rate_at_capture',
      'selling_progress_gap_points', 'current_selling_progress-expected_selling_progress_at_capture',
      'revenue_gap_change_points', 'current_revenue_progress_gap_points-previous_revenue_progress_gap_points',
      'selling_gap_change_points', 'current_selling_progress_gap_points-previous_selling_progress_gap_points',
      'target_consumption_rate_per_hour', '(previous_remaining_revenue-current_remaining_revenue)/previous_remaining_revenue/elapsed_hours'
    ),
    'naming_guards', JSON_ARRAY(
      'delta_revenue_divided_by_net_pickup_is_not_booking_adr',
      'missing_cancellation_stays_null_not_zero',
      'no_trusted_pace_reference_means_no_fast_or_slow_label'
    )
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'pace_and_tolerance',
  JSON_OBJECT(
    'scope', 'small_hotel_cold_start_then_same_hotel_calibration',
    'evidence_level', 'configurable_cold_start_rule_not_verified_hotel_fact',
    'cold_start_tolerance', JSON_OBJECT(
      'room_tolerance', 'max(1_room,ceil(sellable_room_nights*0.05))',
      'revenue_tolerance', 'max(50_CNY,target_revenue*0.005)',
      'rate_tolerance', 'max(5_CNY,current_adr*0.02)',
      'interval_too_short_noise_risk', 'less_than_5_minutes_configurable',
      'interval_too_long_low_comparability', 'greater_than_6_hours_configurable'
    ),
    'tolerance_output_rule', 'always_return_tolerance_value_and_tolerance_source',
    'pace_reference_priority', JSON_ARRAY(
      'versioned_hotel_date_type_plan_curve',
      'same_hotel_same_scope_comparable_date_median_curve',
      'sourced_and_versioned_manual_plan_curve',
      'no_reference_no_fast_slow_judgment'
    ),
    'historical_minimum', JSON_OBJECT(
      'minimum_comparable_days', 3,
      'required_dimensions', JSON_ARRAY('same_hotel', 'same_fact_scope', 'weekday_or_date_type', 'same_metric_semantics'),
      'always_return_sample_count', true
    ),
    'gap_change_labels', JSON_OBJECT(
      'positive_outside_tolerance', 'recovering',
      'negative_outside_tolerance', 'worsening',
      'inside_tolerance', 'stable'
    ),
    'forbidden_defaults', JSON_ARRAY(
      'fixed_july_august_policy',
      'fixed_07_11_15_19_23_schedule',
      'fixed_23_00_full_house_target',
      'universal_plus_minus_5_or_10_percentage_points'
    )
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'decision_priority',
  JSON_OBJECT(
    'scope', 'observational_operating_target_judgment',
    'evidence_level', 'adapted_rule_matrix',
    'priority_order', JSON_ARRAY(
      'P0_identity_quality_comparability',
      'P1_anomaly_and_reversal',
      'P2_volume_price_revenue_observation',
      'P3_target_pressure',
      'P4_full_house_quality'
    ),
    'reversal_rule', JSON_OBJECT(
      'rule_id', 'OT_DIFF_REVERSAL_UNKNOWN',
      'trigger', 'same_day_cumulative_revenue_or_sold_room_nights_decreases',
      'check_first', JSON_ARRAY('cancellation', 'refund', 'posting_reversal', 'rate_change', 'room_move', 'manual_correction', 'source_revision'),
      'blocked_action', 'price_or_inventory_advice_until_explained'
    ),
    'delta_matrix', JSON_ARRAY(
      JSON_OBJECT('sold', 'up', 'revenue', 'up', 'adr', 'up', 'revpar', 'up', 'rule_id', 'OT_DIFF_VOLUME_RATE_UP', 'judgment', '量价同步改善'),
      JSON_OBJECT('sold', 'up', 'revenue', 'up', 'adr', 'down', 'revpar', 'up', 'rule_id', 'OT_DIFF_VOLUME_DRIVEN', 'judgment', '增量偏向以价换量，观察房价稀释'),
      JSON_OBJECT('sold', 'flat', 'revenue', 'up', 'rule_id', 'OT_DIFF_POSTING_OR_RATE_ADJUSTMENT', 'judgment', '先查补录、改价或入账时点'),
      JSON_OBJECT('sold', 'up', 'revenue', 'flat', 'rule_id', 'OT_DIFF_REVENUE_POSTING_LAG', 'judgment', '先查收入入账或范围错配'),
      JSON_OBJECT('sold', 'flat', 'revenue', 'flat', 'rule_id', 'OT_DIFF_NO_MOVEMENT', 'judgment', '未观察到超出容差的经营变化'),
      JSON_OBJECT('sold', 'down', 'rule_id', 'OT_DIFF_REVERSAL_UNKNOWN', 'judgment', '异常优先'),
      JSON_OBJECT('revenue', 'down', 'rule_id', 'OT_DIFF_REVERSAL_UNKNOWN', 'judgment', '异常优先')
    ),
    'gap_matrix', JSON_ARRAY(
      JSON_OBJECT('revenue_progress', 'ahead', 'selling_progress', 'ahead', 'judgment', '量收共同领先，继续检查ADR质量'),
      JSON_OBJECT('revenue_progress', 'ahead', 'selling_progress', 'behind', 'judgment', '价格或高价值结构贡献较强，库存消耗仍慢'),
      JSON_OBJECT('revenue_progress', 'behind', 'selling_progress', 'ahead', 'judgment', '低收益占房风险，检查低价库存和房型结构'),
      JSON_OBJECT('revenue_progress', 'behind', 'selling_progress', 'behind', 'judgment', '收入与库存消耗同时承压，只列排查假设')
    ),
    'causality_gate', JSON_OBJECT(
      'required_for_action_effect_claim', JSON_ARRAY('action_id', 'executed_at', 'affected_room_or_channel', 'sync_receipt', 'comparable_before_after_snapshots'),
      'without_gate_use_words', JSON_ARRAY('观察到', '可能', '需核对'),
      'blocked_words_without_gate', JSON_ARRAY('调价有效', '活动无效', '需求增长已证实')
    )
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'full_house_and_alert',
  JSON_OBJECT(
    'scope', 'full_house_quality_and_state_change_notification',
    'evidence_level', 'adapted_rule_contract',
    'full_house_rules', JSON_ARRAY(
      JSON_OBJECT('rule_id', 'OT_DIFF_HEALTHY_FULL_HOUSE', 'when', 'sellable_remaining_zero_and_revenue_target_met_and_explicit_target_adr_if_configured_met'),
      JSON_OBJECT('rule_id', 'OT_DIFF_RISK_FULL_HOUSE', 'when', 'sellable_remaining_zero_but_revenue_target_or_explicit_target_adr_not_met'),
      JSON_OBJECT('rule_id', 'OT_DIFF_FULL_HOUSE_PARTIAL', 'when', 'full_house_fact_verified_but_revenue_or_explicit_adr_target_missing')
    ),
    'metric_guard', 'target_revenue_divided_by_total_sellable_rooms_is_target_revpar_under_full_inventory_not_target_adr',
    'alert_rules', JSON_ARRAY(
      'baseline_saved_without_push',
      'same_blocked_state_not_repeated',
      'notify_on_rule_state_change_or_dynamic_tolerance_crossing',
      'notify_on_first_P1_anomaly',
      'full_house_dedup_by_hotel_business_date_fact_scope',
      'include_current_and_previous_capture_refs_rule_version_and_next_review_at'
    ),
    'external_action_boundary', 'manual_review_only_no_automatic_price_inventory_or_channel_write'
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'rejected_source_logic',
  JSON_OBJECT(
    'scope', 'source_logic_not_adopted',
    'evidence_level', 'source_code_reviewed_decision',
    'rejected', JSON_ARRAY(
      'fixed_july_and_august_activation',
      'fixed_07_00_11_00_13_00_15_00_17_00_19_00_21_00_22_00_23_00_pace_schedule',
      'fixed_plus_minus_5_or_10_percentage_point_thresholds_for_all_hotels',
      'current_first_observation_used_as_opening_baseline',
      'target_revenue_divided_by_total_rooms_named_target_average_rate_or_adr',
      'missing_cancellation_coerced_to_zero_for_gross_pickup',
      'source_or_capacity_change_not_rebaselined',
      'action_effect_claim_without_execution_and_sync_evidence',
      'source_7_basic_tests_treated_as_full_complex_branch_coverage'
    ),
    'reason', '这些逻辑会在小体量门店、缺取消字段、跨口径或非旺季场景产生错误确定性。',
    'replacement', 'same_hotel_same_scope_quality_gated_dynamic_tolerance_and_explicit_unknown_states'
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

INSERT INTO `tmp_operating_delta_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @operating_delta_unit_id,
  'landing_status',
  JSON_OBJECT(
    'scope', 'current_suxios_landing_boundary',
    'evidence_level', 'repository_state_reviewed_2026_07_27',
    'knowledge_absorbed', JSON_ARRAY(
      'source_fingerprint_and_license',
      'better_capability_list',
      'comparison_gate',
      'delta_formulas',
      'small_hotel_tolerance',
      'decision_priority',
      'full_house_quality',
      'alert_contract',
      'rejected_source_rules'
    ),
    'current_runtime_inputs', JSON_ARRAY(
      'OperatingTargetService versioned daily snapshots',
      'Dingdandao verified accommodation room fee captures',
      'manual confirmed whole hotel or accommodation room fee facts'
    ),
    'not_yet_implemented', JSON_ARRAY(
      'OperatingTargetService runtime adjacent-snapshot delta engine',
      'same-hotel historical pace learning',
      'verified cumulative cancellation capture',
      'delta alert state machine'
    ),
    'truthful_completion_statement', 'knowledge_absorbed_runtime_delta_feature_not_online',
    'output_contract', JSON_ARRAY(
      'rule_version',
      'rule_id',
      'status',
      'fact_scope',
      'current_capture_id',
      'previous_capture_id',
      'captured_at',
      'elapsed_hours',
      'facts',
      'delta_vector',
      'target_gap',
      'gap_change',
      'pace_reference',
      'tolerance',
      'judgment',
      'hypotheses',
      'recommended_manual_check',
      'confidence',
      'data_gaps',
      'next_review_at'
    )
  ),
  0,
  NOW()
WHERE @operating_delta_unit_id IS NOT NULL;

UPDATE `tmp_operating_delta_seed_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.module_id', 'operating_target_delta_detection',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'operations'),
  '$.scenes', JSON_ARRAY('intraday_target_monitoring', 'operating_target_review', 'post_action_observation'),
  '$.platforms', JSON_ARRAY('dingdandao_pms', 'daily_report', 'manual', 'pms'),
  '$.seed_owner', @operating_delta_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `seed`.`type`),
  '$.seed_version', @operating_delta_version
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_operating_delta_seed_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  `seed`.`unit_id`,
  `seed`.`type`,
  `seed`.`content`,
  `seed`.`created_by`,
  `seed`.`created_at`
FROM `tmp_operating_delta_seed_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_operating_delta_seed_chunks`;

SET @operating_delta_category_name := '收益管理与经营解读';
SET @operating_delta_category_description := '酒店收益、经营目标、房型、渠道、经营诊断、建议结构和复盘方法。';

INSERT INTO `knowledge_categories` (
  `tenant_id`, `hotel_id`, `parent_id`, `name`, `description`,
  `sort_order`, `is_enabled`, `create_time`, `update_time`
)
SELECT
  0,
  0,
  0,
  @operating_delta_category_name,
  @operating_delta_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_categories`
  WHERE `hotel_id` = 0
    AND `parent_id` = 0
    AND `name` = @operating_delta_category_name
);

UPDATE `knowledge_categories`
SET
  `tenant_id` = 0,
  `description` = @operating_delta_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `parent_id` = 0
  AND `name` = @operating_delta_category_name;

SET @operating_delta_category_id := (
  SELECT `id`
  FROM `knowledge_categories`
  WHERE `hotel_id` = 0
    AND `parent_id` = 0
    AND `name` = @operating_delta_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @operating_delta_staff_content := CONCAT(
  '# 经营目标差值检测与节奏判断', '\n\n',
  '## 核心思路', '\n',
  '同时看两条线：当前值与目标/时间标准的差距（gap），以及当前值与上一条可比快照的变化（delta）。宿析OS保留同租户、同门店、同经营日、同事实范围、质量和保存回读门禁。', '\n\n',
  '## 比宿析OS当前单点计算更强的部分', '\n',
  '多指标差值向量、取消修正、时间归一速度、差距收窄/扩大、量价收矩阵、异常优先、满房质量、同店节奏学习、规则标识和提醒去重。', '\n\n',
  '## 订单来了实际边界', '\n',
  '当前订单来了数据属于住宿房费口径，已有房费、ADR、出租率、RevPAR和已售间夜，但没有累计取消。当前只能计算净拾取 net_pickup，不得称新增预订或毛拾取；不得扩大为全酒店营收事实。', '\n\n',
  '## 小店判定', '\n',
  '冷启动房量容差=max(1间,可售房量的5%)，并同时设置收入与房价容差。积累至少3个同店、同口径、同日期类型样本后，改用历史中位节奏；没有可信时间标准时不判快慢。', '\n\n',
  '## 异常优先', '\n',
  '同日累计房费或已售回落时，先查取消、退款、冲账、改价、换房、补录、来源修订和容量变化，异常未解释前不建议涨价或降价。', '\n\n',
  '## 不照搬', '\n',
  '不采用固定7月/8月旺季规则、固定时点进度、统一正负5/10个百分点、固定23:00满房目标、首条观察冒充07:00基线、取消缺失按0、目标营收除以总房量冒充目标ADR。', '\n\n',
  '## 当前状态', '\n',
  '知识、公式、边界和检索入口已沉淀；OperatingTargetService运行时差值引擎、同店历史节奏、取消累计采集和提醒状态机尚未上线。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  0,
  COALESCE(@operating_delta_category_id, 0),
  @operating_delta_unit_name,
  @operating_delta_staff_content,
  '经营目标,差值检测,目标差距,相邻快照,净拾取,Pickup,节奏判断,量价收,异常反转,满房质量,订单来了,ADR,RevPAR',
  JSON_ARRAY(
    '经营目标',
    '差值检测',
    '相邻快照',
    '净拾取',
    '满房质量',
    '收益管理',
    'manual_review_only'
  ),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_base`
  WHERE `hotel_id` = 0
    AND `title` = @operating_delta_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = COALESCE(@operating_delta_category_id, `category_id`),
  `content` = @operating_delta_staff_content,
  `keywords` = '经营目标,差值检测,目标差距,相邻快照,净拾取,Pickup,节奏判断,量价收,异常反转,满房质量,订单来了,ADR,RevPAR',
  `tags` = JSON_ARRAY(
    '经营目标',
    '差值检测',
    '相邻快照',
    '净拾取',
    '满房质量',
    '收益管理',
    'manual_review_only'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @operating_delta_unit_name;
