-- Absorb the user-provided occupancy-forecast architecture diagram as a historical H03 method reference.
-- Source-reported parameters and backtest metrics are not generalized to another hotel and grant no pricing authority.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @forecast_version := '2026-08-20.1';
SET @forecast_reviewed_at := '2026-08-20 00:00:00';
SET @forecast_review_due_at := '2026-09-19 00:00:00';
SET @forecast_seed_owner := 'suxios.occupancy_forecast_architecture_reference';
SET @forecast_unit_name := '出租率预测引擎架构 v2（H03历史回测参考）';
SET @forecast_source := 'occupancy_forecast_architecture_reference';
SET @forecast_sha256 := '10A79D06003FC10A483A6F70B2A5CD0BF6ED6C05A538CBBD88315E4D9702AFEA';
SET @forecast_description := '用户提供的收益精灵出租率预测引擎架构图：滚动回归主预测、往年相关性门控、区间与满房概率、分时决策边界以及walk-forward与漂移监控。只保留为H03历史回测方法参考；原始数据、回测脚本、当前部署和跨酒店泛化均未验证，不授权自动调价或渠道写入。';

SET @forecast_source_manifest := JSON_OBJECT(
  'material_count', 1,
  'documents', JSON_ARRAY(
    JSON_OBJECT(
      'file_name', '预测架构流程图.pdf',
      'media_type', 'application/pdf',
      'sha256', @forecast_sha256,
      'parse_status', 'two_pages_text_extracted_and_visual_render_inspected',
      'document_title', '收益精灵 AI 助手·出租率预测引擎总体架构 v2',
      'document_date', '2026-08-19'
    )
  ),
  'source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands',
  'raw_dataset_status', 'not_provided',
  'backtest_script_status', 'named_but_not_provided',
  'current_runtime_status', 'not_verified',
  'cross_hotel_validation_status', 'not_provided'
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @forecast_unit_name,
  @forecast_source,
  'done',
  @forecast_description,
  JSON_ARRAY('出租率预测', '滚动回归', '相关性门控', 'walk_forward', '漂移监控', 'H03', 'reference_only'),
  0,
  'active',
  'single_hotel_historical_backtest_architecture_without_raw_artifacts_or_current_runtime_proof',
  @forecast_reviewed_at,
  @forecast_review_due_at,
  JSON_ARRAY(
    'PDF明确给出数据层、特征层、分时预测、区间概率、决策映射和漂移监控五段架构。',
    '来源声称使用H03共424天数据并做177天样本外回测。',
    '来源明确禁止用完成度比例法做点预测，并把往年同日值限制为相关性门控的弱先验。',
    '来源要求预测日只使用预测日前数据并用walk-forward方式回测。'
  ),
  JSON_ARRAY(
    '未提供H03原始数据、酒店身份映射、backtest.py或可重放结果。',
    '未验证架构是否已进入宿析OS当前运行时。',
    '未证明H03斜率、完成度、阈值、误差和冷启动先验适用于其他酒店或当前日期。',
    '未授权依据该图自动调价、关渠道、开折扣或写入OTA/PMS。'
  ),
  @forecast_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @forecast_unit_name AND `source` = @forecast_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @forecast_description,
  `tags` = JSON_ARRAY('出租率预测', '滚动回归', '相关性门控', 'walk_forward', '漂移监控', 'H03', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'single_hotel_historical_backtest_architecture_without_raw_artifacts_or_current_runtime_proof',
  `reviewed_at` = @forecast_reviewed_at,
  `review_due_at` = @forecast_review_due_at,
  `known_knowns` = JSON_ARRAY(
    'PDF明确给出数据层、特征层、分时预测、区间概率、决策映射和漂移监控五段架构。',
    '来源声称使用H03共424天数据并做177天样本外回测。',
    '来源明确禁止用完成度比例法做点预测，并把往年同日值限制为相关性门控的弱先验。',
    '来源要求预测日只使用预测日前数据并用walk-forward方式回测。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '未提供H03原始数据、酒店身份映射、backtest.py或可重放结果。',
    '未验证架构是否已进入宿析OS当前运行时。',
    '未证明H03斜率、完成度、阈值、误差和冷启动先验适用于其他酒店或当前日期。',
    '未授权依据该图自动调价、关渠道、开折扣或写入OTA/PMS。'
  ),
  `truth_profile_version` = @forecast_version,
  `updated_at` = NOW()
WHERE `name` = @forecast_unit_name AND `source` = @forecast_source;

SET @forecast_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @forecast_unit_name AND `source` = @forecast_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_forecast_reference_chunks`;
CREATE TEMPORARY TABLE `tmp_forecast_reference_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_forecast_reference_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_forecast_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @forecast_unit_id, 'occupancy_forecast_source_audit', JSON_OBJECT(
  'scope', 'single_hotel_h03_historical_backtest_reference',
  'evidence_level', 'user_provided_h03_backtest_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-bundle://2026-08-20/预测架构流程图.pdf#sha256=', @forecast_sha256)),
  'source_manifest', JSON_EXTRACT(@forecast_source_manifest, '$'),
  'observed_facts', JSON_ARRAY(
    'PDF共2页，已完成文本提取和页面渲染检查。',
    '标题标注v2与2026-08-19，正文标注H03、177天样本外回测，页脚标注H03 424天数据。',
    'PDF引用未随附的backtest.py和收益精灵M1预测引擎设计v2。'
  ),
  'source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands'
), 0, NOW()
WHERE @forecast_unit_id IS NOT NULL;

INSERT INTO `tmp_forecast_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @forecast_unit_id, 'occupancy_forecast_data_contract', JSON_OBJECT(
  'scope', 'single_hotel_h03_historical_backtest_reference',
  'evidence_level', 'user_provided_h03_backtest_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-bundle://2026-08-20/预测架构流程图.pdf#sha256=', @forecast_sha256)),
  'source_reported_inputs', JSON_ARRAY('逐时出租率快照', '远期预订', '星期节假日事件等日历特征'),
  'data_quality_checks', JSON_ARRAY('小数与百分数统一', '识别出租率倒流', '识别大于1.5的异常或超售口径', '远期列缺失时显式降级', '排除汇总行'),
  'feature_contract', JSON_ARRAY('当前时刻出租率occ_h', '星期节假日农历对齐', '近28天同星期均值', '往年同日值仅供门控检验'),
  'leakage_rule', '预测日d只允许使用d之前可获得的数据，禁止未来信息',
  'metric_scope', '来源把全日房出租率100%作为满房，并提到checkin_type=Normal；实际实现前必须对齐宿析OS房量、维修房、超售和取消口径',
  'proposed_runtime_artifact', 'Redis键rms-occ-curve-{hotel_id}与TTL24h仅为图中架构提案，未验证当前运行时存在'
), 0, NOW()
WHERE @forecast_unit_id IS NOT NULL;

INSERT INTO `tmp_forecast_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @forecast_unit_id, 'occupancy_forecast_model_contract', JSON_OBJECT(
  'scope', 'single_hotel_h03_historical_backtest_reference',
  'evidence_level', 'user_provided_h03_backtest_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-bundle://2026-08-20/预测架构流程图.pdf#sha256=', @forecast_sha256)),
  'source_reported_primary_model', '6至20时使用按星期和时刻分桶的近90天滚动回归：y_hat=a(h,k)+b(h,k)*occ_h，至少8个样本',
  'source_reported_weak_prior', '往年同日相关系数rho每30天重算，w=clamp(rho,0,0.3)，rho小于等于0时权重为0，再与回归预测融合',
  'source_reported_late_day_rule', '21时后直接输出当前值',
  'source_reported_fallback', '回归样本少于8时退到近28天同星期均值；冷启动再考虑行业完成度先验和同城同档兜底',
  'prohibited_model_shortcut', '完成度比例法不得作为点预测；来源声称H03在6时的比例法MAE为13.9pp',
  'source_reported_h03_parameters', JSON_OBJECT(
    'slopes', JSON_OBJECT('06:00', 0.19, '12:00', 0.26, '18:00', 0.55),
    'completion_priors', JSON_OBJECT('06:00', '60%', '12:00', '70%', '18:00', '87%'),
    'year_over_year_correlation_range', '0.08-0.20'
  ),
  'generalization_rule', 'H03参数、完成度和相关性必须在目标酒店按时间顺序回测通过后才能成为该酒店参数'
), 0, NOW()
WHERE @forecast_unit_id IS NOT NULL;

INSERT INTO `tmp_forecast_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @forecast_unit_id, 'occupancy_forecast_decision_guard', JSON_OBJECT(
  'scope', 'single_hotel_h03_historical_backtest_reference',
  'evidence_level', 'user_provided_h03_backtest_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-bundle://2026-08-20/预测架构流程图.pdf#sha256=', @forecast_sha256)),
  'source_reported_interval', '按星期和时刻使用近90天回归残差标准差sigma_h，90%区间为y_hat加减1.645*sigma_h',
  'source_reported_full_probability', 'P=Phi((y_hat-1.0)/sigma_h)',
  'source_reported_action_thresholds', JSON_OBJECT(
    'full_probability', 'P>=70%时图中建议提价或关闭低价渠道',
    'negative_growth_signal', 'z<=-1.65时图中建议促销或开放折扣',
    'decision_deadline', '调价建议应在12:00前给出',
    'late_day_boundary', '18:00后只报当日定局，不再给调价建议'
  ),
  'suxios_authorization_boundary', '以上均为来源中的H03决策映射，不是宿析OS当前建议；目标酒店验证、当前数据、收益口径和人工审批缺一不可',
  'automatic_action_status', 'withheld'
), 0, NOW()
WHERE @forecast_unit_id IS NOT NULL;

INSERT INTO `tmp_forecast_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @forecast_unit_id, 'occupancy_forecast_validation_contract', JSON_OBJECT(
  'scope', 'single_hotel_h03_historical_backtest_reference',
  'evidence_level', 'user_provided_h03_backtest_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-bundle://2026-08-20/预测架构流程图.pdf#sha256=', @forecast_sha256)),
  'source_reported_h03_oos_metrics', JSON_OBJECT('06:00_mae_pp', 4.0, '12:00_mae_pp', 3.9, '18:00_mae_pp', 2.9, '22:00_current_value_mae_pp', 1.2),
  'validation_order', JSON_ARRAY('每日记录预测与实际误差', '按星期和时刻分桶', '月度walk-forward回测', '复核往年门控rho', '重估近90天曲线与残差'),
  'source_reported_drift_triggers', JSON_ARRAY('28天滚动bias大于3pp', 'P10-P90覆盖率小于70%'),
  'required_before_adoption', JSON_ARRAY(
    '取得目标酒店同口径逐时快照和最终出租率',
    '重放数据清洗并验证没有未来信息',
    '用时间顺序样本外回测与简单基线比较',
    '核对区间覆盖率、偏差、分时MAE和异常日',
    '保存模型版本、参数、数据范围和回读证据',
    '所有价格或渠道动作保持人工审批'
  ),
  'evidence_boundary', '没有原始数据和backtest.py时只能保留来源报告的数值，不能复现或宣称精度成立'
), 0, NOW()
WHERE @forecast_unit_id IS NOT NULL;

UPDATE `tmp_forecast_reference_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('occupancy_forecast:', `type`),
  '$.content_type', 'occupancy_forecast_reference_contract',
  '$.module_id', 'revenue_forecast_reference',
  '$.platforms', JSON_ARRAY('suxios_internal'),
  '$.roles', JSON_ARRAY('owner', 'revenue_manager', 'operator', 'data_analyst'),
  '$.scenes', JSON_ARRAY('occupancy_forecast_design', 'time_ordered_backtest', 'drift_monitoring', 'human_reviewed_revenue_decision'),
  '$.reviewed_at', @forecast_reviewed_at,
  '$.review_due_at', @forecast_review_due_at,
  '$.review_interval_days', 30,
  '$.freshness_policy', 'target_hotel_revalidation_required',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_verified_for_current_hotel_or_runtime',
  '$.allowed_uses', JSON_ARRAY('knowledge_retrieval', 'forecast_design_review', 'backtest_design_reference', 'data_quality_checklist'),
  '$.blocked_uses', JSON_ARRAY(
    'current_hotel_fact', 'current_forecast_fact', 'revenue_decision',
    'operation_task_creation', 'operation_execution', 'automatic_pricing',
    'automatic_channel_closure', 'automatic_discount_opening',
    'automatic_ota_write', 'automatic_pms_write', 'business_outcome_claim'
  ),
  '$.seed_owner', @forecast_seed_owner,
  '$.seed_key', CONCAT('occupancy_forecast:', `type`),
  '$.seed_version', @forecast_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_forecast_fact', false,
  '$.decision_safe', false,
  '$.external_write_authorized', false,
  '$.source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_forecast_reference_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_forecast_reference_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
);

DROP TEMPORARY TABLE `tmp_forecast_reference_chunks`;

SET @forecast_staff_content := CONCAT(
  '# 出租率预测引擎架构 v2（H03历史回测参考）', '\n\n',
  '## 来源边界', '\n',
  '来源是H03单酒店架构图；原始数据、backtest.py、当前部署和跨酒店泛化均未验证。', '\n\n',
  '## 方法主线', '\n',
  '逐时出租率与远期预订清洗 → 只用预测日前特征 → 分星期分时刻滚动回归 → 往年相关性门控弱先验 → 区间与满房概率 → walk-forward和漂移监控。', '\n\n',
  '## 关键保护', '\n',
  '禁止把完成度比例法当作点预测；H03参数不能直接用于其他酒店；没有目标酒店样本外回测时只供设计参考。', '\n\n',
  '## 动作边界', '\n',
  '图中的提价、关低价渠道、促销和折扣阈值不构成当前经营建议，所有动作继续保持人工审批且不得自动写入OTA/PMS。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @forecast_unit_name, @forecast_staff_content,
  '出租率预测,滚动回归,往年相关性门控,walk-forward,漂移监控,H03,预测区间,满房概率',
  JSON_ARRAY('出租率预测', '滚动回归', 'walk_forward', '漂移监控', 'H03', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @forecast_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @forecast_staff_content,
  `keywords` = '出租率预测,滚动回归,往年相关性门控,walk-forward,漂移监控,H03,预测区间,满房概率',
  `tags` = JSON_ARRAY('出租率预测', '滚动回归', 'walk_forward', '漂移监控', 'H03', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @forecast_unit_name;
