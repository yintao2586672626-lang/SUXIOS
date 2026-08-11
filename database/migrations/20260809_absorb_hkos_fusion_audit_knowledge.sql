-- Absorb the reusable governance patterns from the user-provided HKOS fusion-audit prompt.
-- The source is a planning prompt, not a current-hotel fact set, approved product baseline,
-- runtime receipt, external-write authorization, or evidence that the referenced 170-item list
-- and three supporting HKOS documents were available in this SUXIOS task.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @hkos_audit_version := '2026-08-09.1';
SET @hkos_audit_reviewed_at := '2026-08-09 00:00:00';
SET @hkos_audit_review_due_at := '2027-02-05 00:00:00';
SET @hkos_audit_seed_owner := 'suxios.hkos_fusion_audit_knowledge';
SET @hkos_audit_unit_name := 'OTA三方融合审计与产品语义决策合同';
SET @hkos_audit_source := 'revenue_operations_decision_support';
SET @hkos_audit_sha256 := '2C3520DFF13517B5717D9B6D93F308E904E309441F5F1F98537CC7AE8960D276';
SET @hkos_audit_description := '从用户提供的HKOS三方融合审计提示词中提炼证据分级、候选维度治理、金额与时间语义边界、备注需求洞察和单一最小实施包决策合同。该知识是全局产品治理参考，不是当前酒店或OTA事实，不代表任何指标已批准，也不授权自动任务、Provider启用、OTA/PMS写入或发布。';
SET @hkos_audit_source_manifest := JSON_OBJECT(
  'material_type', 'user_provided_markdown_prompt',
  'file_name', 'HKOS_Codex_三方融合审计与下一阶段决策包提示词.md',
  'sha256', @hkos_audit_sha256,
  'observed_at', '2026-08-09',
  'source_system', 'HKOS_external_project_material',
  'verification_status', 'user_provided_unverified',
  'supporting_documents_status', 'not_provided_in_current_suxios_task',
  'dimension_inventory_status', '170_item_inventory_not_provided_in_current_suxios_task',
  'reuse_mode', 'governance_contract_adaptation',
  'source_prompt_executed_as_hkos_audit', false
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @hkos_audit_unit_name,
  @hkos_audit_source,
  'done',
  @hkos_audit_description,
  JSON_ARRAY('OTA审计', '产品语义', '维度治理', '备注需求洞察', '指标合同', '产品签署', 'global_reference'),
  0,
  'active',
  'user_provided_prompt_adapted_as_reference_only_governance_contract',
  @hkos_audit_reviewed_at,
  @hkos_audit_review_due_at,
  JSON_ARRAY(
    '来源提示词要求把正式基线、代码与运行事实、交接材料和候选维度按证据优先级分开。',
    '候选维度必须逐项治理，不能把维度数量直接等同于指标数量、图表数量或开发Backlog。',
    '金额、时间、姓名、因果、预测和员工绩效均需要明确语义与证据边界。',
    '客人备注、确认备注和平台提示应分源保存，多标签分类，保留原文与人工复核状态。',
    '下一阶段实施应从阻断真实数据链的最小包开始，并保留产品负责人签署门。'
  ),
  JSON_ARRAY(
    '提示词引用的三份HKOS支持材料未随本次宿析OS任务提供。',
    '提示词提到的170项维度清单原文未随本次宿析OS任务提供，因此没有逐项采纳或否决。',
    '提示词中的HKOS缺陷、样本规模、字段数量和历史结论未对宿析OS当前代码或数据库逐项复核。',
    '携程官方字段语义、促销金额承担方、取消事件时间和备注满足证据仍需来源专项核验。',
    '该方法对真实经营结果的增益尚无宿析OS现场或生产证据。'
  ),
  @hkos_audit_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @hkos_audit_unit_name AND `source` = @hkos_audit_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @hkos_audit_description,
  `tags` = JSON_ARRAY('OTA审计', '产品语义', '维度治理', '备注需求洞察', '指标合同', '产品签署', 'global_reference'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_prompt_adapted_as_reference_only_governance_contract',
  `reviewed_at` = @hkos_audit_reviewed_at,
  `review_due_at` = @hkos_audit_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '来源提示词要求把正式基线、代码与运行事实、交接材料和候选维度按证据优先级分开。',
    '候选维度必须逐项治理，不能把维度数量直接等同于指标数量、图表数量或开发Backlog。',
    '金额、时间、姓名、因果、预测和员工绩效均需要明确语义与证据边界。',
    '客人备注、确认备注和平台提示应分源保存，多标签分类，保留原文与人工复核状态。',
    '下一阶段实施应从阻断真实数据链的最小包开始，并保留产品负责人签署门。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '提示词引用的三份HKOS支持材料未随本次宿析OS任务提供。',
    '提示词提到的170项维度清单原文未随本次宿析OS任务提供，因此没有逐项采纳或否决。',
    '提示词中的HKOS缺陷、样本规模、字段数量和历史结论未对宿析OS当前代码或数据库逐项复核。',
    '携程官方字段语义、促销金额承担方、取消事件时间和备注满足证据仍需来源专项核验。',
    '该方法对真实经营结果的增益尚无宿析OS现场或生产证据。'
  ),
  `truth_profile_version` = @hkos_audit_version,
  `updated_at` = NOW()
WHERE `name` = @hkos_audit_unit_name AND `source` = @hkos_audit_source;

SET @hkos_audit_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @hkos_audit_unit_name AND `source` = @hkos_audit_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_hkos_audit_chunks`;
CREATE TEMPORARY TABLE `tmp_hkos_audit_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_hkos_audit_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_hkos_audit_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hkos_audit_unit_id, 'hkos_fusion_audit_source_scope_reference', JSON_OBJECT(
  'scope', 'global_governance_reference',
  'evidence_level', 'user_provided_governance_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://HKOS_Codex_三方融合审计与下一阶段决策包提示词.md#sha256=', @hkos_audit_sha256)),
  'observed_source_facts', JSON_ARRAY(
    '材料是一份HKOS融合审计与下一阶段决策包提示词。',
    '材料要求先核验正式基线，再处理上一轮交接事实和候选维度库。',
    '材料明确禁止在审计阶段修改代码、数据库、Provider或部署状态。'
  ),
  'adaptation_boundaries', JSON_ARRAY(
    '只吸收可复用治理合同，不继承HKOS项目身份、缺陷结论、样本数字或批准状态。',
    '未提供的三份支持材料和170项清单保持unknown。',
    '本知识不能替代携程官方字段说明、当前宿析代码事实或真实运行验证。'
  )
), 0, NOW()
WHERE @hkos_audit_unit_id IS NOT NULL;

INSERT INTO `tmp_hkos_audit_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hkos_audit_unit_id, 'evidence_status_and_dimension_governance_contract', JSON_OBJECT(
  'scope', 'global_governance_reference',
  'evidence_level', 'user_provided_governance_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://HKOS_Codex_三方融合审计与下一阶段决策包提示词.md#sha256=', @hkos_audit_sha256)),
  'evidence_statuses', JSON_ARRAY('LOCKED_DECISION', 'DOCUMENTED_DECISION', 'CURRENT_REQUIREMENT', 'CODE_FACT', 'RUNTIME_VERIFIED', 'INPUT_EVIDENCE', 'PROPOSED', 'PENDING', 'DEFERRED', 'REJECTED'),
  'source_priority', JSON_ARRAY('approved_special_baseline', 'decision_registry_formal_source', 'current_governance_document', 'accepted_ADR', 'current_code_and_runtime', 'prior_handoff_aggregate', 'candidate_dimension_idea'),
  'dimension_states', JSON_ARRAY('ALREADY_COVERED', 'ACCEPT_NOW', 'ACCEPT_NEXT', 'CONDITIONAL', 'DEFERRED', 'REJECTED'),
  'required_dimension_fields', JSON_ARRAY('original_id', 'original_name', 'business_question', 'required_fields', 'grain', 'date_role', 'numerator', 'denominator', 'unit', 'include_exclude', 'double_count_risk', 'semantic_maturity', 'privacy_fairness_risk', 'existing_metric_relationship', 'recommended_state', 'target_version_or_module', 'rejection_reason'),
  'governance_rule', 'candidate_dimension_count_never_equals_metric_chart_or_backlog_count'
), 0, NOW()
WHERE @hkos_audit_unit_id IS NOT NULL;

INSERT INTO `tmp_hkos_audit_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hkos_audit_unit_id, 'semantic_privacy_and_causality_guard', JSON_OBJECT(
  'scope', 'global_governance_reference',
  'evidence_level', 'user_provided_governance_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://HKOS_Codex_三方融合审计与下一阶段决策包提示词.md#sha256=', @hkos_audit_sha256)),
  'amount_guards', JSON_ARRAY('base_amount_is_uploaded_base_price_field_only', 'do_not_infer_revenue_adr_profit_or_cancellation_loss', 'do_not_infer_commission_from_sale_minus_base', 'promotion_cny_is_not_hotel_discount_cost_without_scope_evidence', 'zero_base_amount_is_not_closed_room'),
  'time_guards', JSON_ARRAY('booking_created_at_separate_from_status_notification_at', 'notification_minus_booking_is_not_employee_sla', 'cancellation_time_requires_event_semantics', 'display_hour_order_must_not_change_natural_day_lead_time'),
  'rejected_identity_inferences', JSON_ARRAY('gender_from_name', 'nationality_from_english_letters', 'profile_from_surname_or_name_length', 'same_name_equals_same_person_or_member'),
  'causality_and_prediction_default', JSON_OBJECT('promotion_roi', 'DEFERRED', 'price_elasticity', 'DEFERRED', 'promotion_caused_orders_or_cancellations', 'REJECTED_without_comparable_evidence', 'automatic_pricing_or_marketing', 'REJECTED_without_explicit_authorization'),
  'employee_fairness_rule', 'no_personal_speed_quality_or_cancellation_ranking_without_event_attribution_schedule_context_permission_and_fairness_review'
), 0, NOW()
WHERE @hkos_audit_unit_id IS NOT NULL;

INSERT INTO `tmp_hkos_audit_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hkos_audit_unit_id, 'guest_request_intelligence_contract', JSON_OBJECT(
  'scope', 'global_governance_reference',
  'evidence_level', 'user_provided_governance_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://HKOS_Codex_三方融合审计与下一阶段决策包提示词.md#sha256=', @hkos_audit_sha256)),
  'text_sources', JSON_OBJECT('guest_remark', 'guest_or_channel_request_text', 'confirmation_remark', 'hotel_system_or_platform_confirmation_text_not_fulfillment_proof', 'platform_tip', 'platform_policy_payment_guarantee_or_metadata_not_guest_remark'),
  'classification_contract', JSON_OBJECT('multi_label', true, 'original_text_preserved', true, 'classification_version_required', true, 'review_status_required', true, 'unknown_label', 'other_unrecognized_request'),
  'arrival_fields', JSON_ARRAY('expected_arrival_time_raw', 'expected_arrival_time_bucket', 'late_arrival_flag', 'after_midnight_arrival_flag', 'retain_room_requested', 'arrival_delay_reason_tag', 'follow_up_required', 'classification_version', 'review_status'),
  'metric_rule', 'guest_remark_confirmation_remark_and_platform_tip_require_separate_denominators',
  'fulfillment_rule', 'request_or_confirmation_text_never_proves_the_hotel_fulfilled_the_request_without_event_evidence',
  'privacy_rule', 'raw_free_text_stays_out_of_logs_urls_public_preview_and_external_ai',
  'action_policy', JSON_OBJECT('suggest_checklist_only', true, 'doNotExecuteAutomatically', true)
), 0, NOW()
WHERE @hkos_audit_unit_id IS NOT NULL;

INSERT INTO `tmp_hkos_audit_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hkos_audit_unit_id, 'minimal_implementation_and_signoff_contract', JSON_OBJECT(
  'scope', 'global_governance_reference',
  'evidence_level', 'user_provided_governance_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://HKOS_Codex_三方融合审计与下一阶段决策包提示词.md#sha256=', @hkos_audit_sha256)),
  'implementation_layers', JSON_OBJECT(
    'MIP-0', 'import_security_and_data_integrity',
    'MIP-1', 'source_profile_and_structured_sidecar',
    'MIP-2', 'deterministic_metrics_and_filter_context',
    'MIP-3', 'BI_findings_and_evidence'
  ),
  'selection_rule', 'recommend_exactly_one_smallest_package_that_closes_the_highest_value_data_chain_break',
  'signoff_choices', JSON_ARRAY('批准推荐方案', '批准但修改', '暂缓', '拒绝', '需要外部证据'),
  'default_recommendation', 'MIP-0_before_new_metrics_when_import_identity_deduplication_scope_or_contract_integrity_is_unclosed',
  'stop_rule', 'after_decision_package_or_selected_minimum_package_acceptance_stop_and_wait_for_the_next_explicit_objective'
), 0, NOW()
WHERE @hkos_audit_unit_id IS NOT NULL;

UPDATE `tmp_hkos_audit_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('ota_product_semantics_audit:', `type`),
  '$.content_type', 'governance_contract',
  '$.module_id', 'ota_product_semantics_audit',
  '$.platforms', JSON_ARRAY('ctrip', 'meituan', 'suxios_internal'),
  '$.roles', JSON_ARRAY('owner', 'product_owner', 'revenue_manager', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('source_profile_review', 'dimension_governance', 'metric_contract_review', 'guest_request_intelligence_design', 'minimum_implementation_selection'),
  '$.source_manifest', JSON_EXTRACT(@hkos_audit_source_manifest, '$'),
  '$.reviewed_at', @hkos_audit_reviewed_at,
  '$.review_due_at', @hkos_audit_review_due_at,
  '$.review_interval_days', 180,
  '$.freshness_policy', 'reference_only_until_source_and_project_specific_verification',
  '$.allowed_uses', JSON_ARRAY('audit_planning', 'source_profile_review', 'candidate_dimension_governance', 'metric_contract_draft', 'guest_request_intelligence_design', 'product_signoff_preparation'),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'current_ota_fact', 'metric_auto_enable', 'operation_task_creation', 'operation_execution', 'provider_enable', 'automatic_pricing', 'automatic_marketing', 'automatic_ota_write', 'automatic_pms_write', 'product_approval_substitution'),
  '$.seed_owner', @hkos_audit_seed_owner,
  '$.seed_key', CONCAT('ota_product_semantics_audit:', `type`),
  '$.seed_version', @hkos_audit_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_hkos_audit_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_hkos_audit_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_hkos_audit_chunks`;

SET @hkos_audit_staff_content := CONCAT(
  '# OTA三方融合审计与产品语义决策合同', '\n\n',
  '## 使用边界', '\n',
  '这是用户提供提示词沉淀的全局治理参考，不是当前酒店或OTA事实，不代表任何指标已批准，也不授权自动执行。', '\n\n',
  '## 审计方法', '\n',
  '先按正式基线、当前代码与运行、交接证据、候选想法分层，再逐项治理维度；不能把候选维度直接变成指标、图表或Backlog。', '\n\n',
  '## 语义红线', '\n',
  '金额、时间、姓名、促销、取消、预测和员工绩效必须保留字段本义、分母、事件证据、隐私与公平边界。', '\n\n',
  '## 备注需求洞察', '\n',
  '客人备注、确认备注和平台提示分源；多标签、版本化、可复核；要求或确认文本不等于实际满足。', '\n\n',
  '## 实施选择', '\n',
  '优先选择唯一一个能关闭最高价值数据链断点的最小包；产品签署、外部证据和授权不能被系统默认替代。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @hkos_audit_unit_name, @hkos_audit_staff_content,
  'OTA审计,三方融合,证据分级,维度治理,指标合同,备注需求洞察,到店留房,产品签署,MIP',
  JSON_ARRAY('OTA审计', '维度治理', '备注需求洞察', '产品语义', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @hkos_audit_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @hkos_audit_staff_content,
  `keywords` = 'OTA审计,三方融合,证据分级,维度治理,指标合同,备注需求洞察,到店留房,产品签署,MIP',
  `tags` = JSON_ARRAY('OTA审计', '维度治理', '备注需求洞察', '产品语义', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @hkos_audit_unit_name;
