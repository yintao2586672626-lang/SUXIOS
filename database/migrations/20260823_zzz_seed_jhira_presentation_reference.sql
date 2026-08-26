-- Guarded absorption of the public JHIRA-YUSHENG-PPT repository as a global
-- presentation-method reference. No source code, package, brand asset, or
-- installation command is copied or executed by this seed.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @jhira_ref_version := '2026-08-23.1';
SET @jhira_ref_reviewed_at := '2026-08-23 00:00:00';
SET @jhira_ref_review_due_at := '2027-02-19 00:00:00';
SET @jhira_ref_seed_owner := 'suxios.jhira_presentation_repository_reference';
SET @jhira_ref_unit_name := '经营报告双格式交付方法 v1.0（JHIRA仓库参考）';
SET @jhira_ref_source := 'jhira_presentation_reference';
SET @jhira_ref_url := 'https://github.com/moyusheng0916-eng/JHIRA-YUSHENG-PPT';
SET @jhira_ref_commit := '4dc9898c86ef3c4589c903e69ad12f6e398dcf28';
SET @jhira_ref_tree_sha256 := '8bfc490509e9fb46a44a81dc0f753355ce3b6c5c9b4e9737e929136431334fdd';
SET @jhira_ref_skill_sha256 := 'cee95b70b70ccd899a058f31fb918a4e9a45b6da50c4ef318368cd07e10f2497';

SET @jhira_ref_description := '对公开JHIRA-YUSHENG-PPT仓库固定提交进行静态审计和有限测试复验后形成的方法参考。可学习单一Deck Spec、跨格式语义一致、页脚与调色板适配、分层QA等思路；仓库未提供开源许可证，依赖未锁定，构建脚本未真正消费同一Deck Spec，且部分QA PASS为静态写入，因此仓库整体保持reference_only，禁止安装、复制源码、继承JHIRA品牌或据此声称正式PPT已通过。宿析OS另行原生实现规格保存与精确回读。';

SET @jhira_ref_manifest := JSON_OBJECT(
  'material_type', 'public_github_repository',
  'repository_url', @jhira_ref_url,
  'source_version', '1.1.0',
  'branch', 'codex/compact-executive-footer',
  'commit', @jhira_ref_commit,
  'observed_at', '2026-08-23',
  'repository_file_count', 36,
  'repository_tree_sha256', @jhira_ref_tree_sha256,
  'skill_tree_sha256', @jhira_ref_skill_sha256,
  'release_zip_sha256', '0554A144FA19673D34B01E09AE51F7C0DB67E17F1F72E4CC44C4FE3CFF4BD26D',
  'sample_pptx_sha256', 'DE85C8F3F43F9DB7EB4CDFE907CE0E4FE33866EF96DD53EFF708F6486902F756',
  'sample_html_sha256', '33D0986CE8C821A50491BF527EF65F44FA9067A5FBCFCBF6B6237A652F8ABFC4',
  'deck_spec_sha256', '251AD76AF71E754A361D75858995D90F1144D1452128936A93A8D021AD275270',
  'qa_report_sha256', 'C4B7E85AA76DBFB5E11FEE7B9C5E43EFDF053D9BEF860C091EAFAB1BD1F7FF4C',
  'license_status', 'not_provided_repository_readme_limits_reuse_to_owner_authorized_internal_use',
  'dependency_status', 'unlocked_and_incomplete',
  'static_source_review', 'pass_with_material_gaps',
  'mechanism_gate', 'fail',
  'reproduction_gate', 'indeterminate',
  'direct_reuse', 'blocked',
  'package_installed', false,
  'source_code_copied', false,
  'limited_replay', JSON_OBJECT(
    'status', 'pass',
    'palette_validator_count', 5,
    'node_test_count', 12,
    'scope', JSON_ARRAY('palette_catalog', 'palette_adapter', 'footer_format', 'footer_layout'),
    'excluded', JSON_ARRAY('builders', 'self_reported_qa', 'npx_install', 'external_publish')
  )
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @jhira_ref_unit_name,
  @jhira_ref_source,
  'done',
  @jhira_ref_description,
  JSON_ARRAY('AI报告', '演示规格', 'HTML', 'PPTX', '证据分层', 'reference_only'),
  0,
  'active',
  'fixed_commit_static_audit_and_bounded_replay_reference_only',
  @jhira_ref_reviewed_at,
  @jhira_ref_review_due_at,
  JSON_ARRAY(
    '仓库公开说明的目标是从同一演示规格交付HTML和PPTX，并提供5套候选调色板与页脚规则。',
    '固定提交静态审计覆盖36个跟踪文件；Skill目录树和主要交付物均保存了SHA256指纹。',
    '限定范围内的5套调色板校验和12个页脚、布局、适配器Node测试通过。',
    '宿析OS吸收点是证据分层的单一PresentationSpec，以及保存后精确回读。'
  ),
  JSON_ARRAY(
    '没有开源许可证或权利授权，不能据此复制或分发源码和JHIRA品牌资产。',
    '依赖没有锁定，缺少package清单、锁文件、SBOM和跨环境可复现证据。',
    'HTML与PPT构建脚本分别硬编码内容，并非真实共享同一Deck Spec。',
    '部分QA PASS为脚本静态写入，未证明PowerPoint编辑保存、跨格式语义一致或生产可用。'
  ),
  @jhira_ref_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @jhira_ref_unit_name AND `source` = @jhira_ref_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @jhira_ref_description,
  `tags` = JSON_ARRAY('AI报告', '演示规格', 'HTML', 'PPTX', '证据分层', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'fixed_commit_static_audit_and_bounded_replay_reference_only',
  `reviewed_at` = @jhira_ref_reviewed_at,
  `review_due_at` = @jhira_ref_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '仓库公开说明的目标是从同一演示规格交付HTML和PPTX，并提供5套候选调色板与页脚规则。',
    '固定提交静态审计覆盖36个跟踪文件；Skill目录树和主要交付物均保存了SHA256指纹。',
    '限定范围内的5套调色板校验和12个页脚、布局、适配器Node测试通过。',
    '宿析OS吸收点是证据分层的单一PresentationSpec，以及保存后精确回读。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '没有开源许可证或权利授权，不能据此复制或分发源码和JHIRA品牌资产。',
    '依赖没有锁定，缺少package清单、锁文件、SBOM和跨环境可复现证据。',
    'HTML与PPT构建脚本分别硬编码内容，并非真实共享同一Deck Spec。',
    '部分QA PASS为脚本静态写入，未证明PowerPoint编辑保存、跨格式语义一致或生产可用。'
  ),
  `truth_profile_version` = @jhira_ref_version,
  `updated_at` = NOW()
WHERE `name` = @jhira_ref_unit_name AND `source` = @jhira_ref_source;

SET @jhira_ref_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @jhira_ref_unit_name AND `source` = @jhira_ref_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_jhira_presentation_reference`;
CREATE TEMPORARY TABLE `tmp_jhira_presentation_reference` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_jhira_reference_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_jhira_presentation_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @jhira_ref_unit_id, 'repo_source_audit', JSON_OBJECT(
  'scope', 'global_presentation_delivery_method_reference',
  'evidence_level', 'fixed_commit_static_audit_and_bounded_test_replay',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT(@jhira_ref_url, '@', @jhira_ref_commit)),
  'disposition', 'reference_only',
  'static_source_review', 'pass_with_material_gaps',
  'mechanism_gate', 'fail',
  'reproduction_gate', 'indeterminate',
  'direct_reuse', 'blocked',
  'safe_observations', JSON_ARRAY('five_candidate_palettes', 'compact_footer_contract', 'html_and_pptx_delivery_intent', 'deck_spec_documentation'),
  'material_gaps', JSON_ARRAY('missing_license', 'unlocked_dependencies', 'builders_do_not_consume_single_spec', 'unconditional_qa_pass_lines', 'platform_specific_qa_assumptions')
), 0, NOW()
WHERE @jhira_ref_unit_id IS NOT NULL;

INSERT INTO `tmp_jhira_presentation_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @jhira_ref_unit_id, 'single_spec_method', JSON_OBJECT(
  'scope', 'global_presentation_delivery_method_reference',
  'evidence_level', 'source_inspired_suxios_native_contract',
  'evidence_grade', 'B',
  'source_refs', JSON_ARRAY(CONCAT(@jhira_ref_url, '@', @jhira_ref_commit), 'suxios://ai-daily-report/presentation-spec/v1'),
  'principle', 'HTML与PPTX必须消费同一份已保存PresentationSpec，渲染阶段不重算指标。',
  'required_sections', JSON_ARRAY('source_report_identity', 'evidence_ledger', 'slides', 'visual_system', 'render_contract', 'qa', 'authorization', 'method_provenance'),
  'persistence_contract', JSON_ARRAY('canonical_json', 'sha256_fingerprint', 'append_only_or_idempotent_save', 'exact_readback_verification'),
  'cross_format_acceptance', JSON_ARRAY('same_message', 'same_values_and_units', 'same_source_and_business_date', 'same_unknown_and_approval_states'),
  'render_status_rule', '未实际渲染时HTML与PPTX必须保持not_rendered，不得从规格通过推断成文件通过。'
), 0, NOW()
WHERE @jhira_ref_unit_id IS NOT NULL;

INSERT INTO `tmp_jhira_presentation_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @jhira_ref_unit_id, 'evidence_delivery_contract', JSON_OBJECT(
  'scope', 'global_presentation_delivery_method_reference',
  'evidence_level', 'suxios_truth_and_authorization_mapping',
  'evidence_grade', 'B',
  'source_refs', JSON_ARRAY('suxios://ai-daily-report/result-contract', 'suxios://ai-daily-report/presentation-spec/v1'),
  'evidence_classes', JSON_ARRAY('VERIFIED_FACT', 'DERIVED_METRIC', 'PROFESSIONAL_JUDGMENT', 'ACTION_RECOMMENDATION', 'HUMAN_DECISION', 'UNKNOWN', 'MOCK'),
  'truth_rules', JSON_ARRAY('事实必须有同酒店同业务日期的持久化回读来源', '派生指标必须保留指标版本和来源', '未知值保持null不得补0', '判断不得写成因果', '视觉强调不得升级证据等级'),
  'action_rules', JSON_ARRAY('建议动作默认pending_approval', 'execution_authorized=false', 'external_write_authorized=false', '发布与OTA或PMS写入必须由用户主动触发'),
  'qa_sequence', JSON_ARRAY('schema_validation', 'source_readback', 'html_render_check', 'pptx_structure_and_overflow_check', 'cross_format_semantic_diff', 'human_review')
), 0, NOW()
WHERE @jhira_ref_unit_id IS NOT NULL;

INSERT INTO `tmp_jhira_presentation_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @jhira_ref_unit_id, 'suxios_absorption_boundary', JSON_OBJECT(
  'scope', 'global_presentation_delivery_method_reference',
  'evidence_level', 'implementation_boundary',
  'evidence_grade', 'B',
  'source_refs', JSON_ARRAY(CONCAT(@jhira_ref_url, '@', @jhira_ref_commit), 'suxios://app/service/AiDailyReportPresentationSpecService'),
  'absorbed_now', JSON_ARRAY('SUXIOS_native_PresentationSpec', 'evidence_ledger', 'canonical_fingerprint', 'internal_save', 'exact_readback', 'render_and_authorization_truth_states'),
  'not_absorbed', JSON_ARRAY('JHIRA_brand', 'source_code', 'skill_installation', 'source_builders', 'source_self_reported_qa', 'palette_defaults', 'external_publication'),
  'delivery_entry', JSON_OBJECT('post', '/api/ai-daily-reports/:id/presentation-spec', 'readback', '/api/ai-daily-reports/:id/presentation-spec?audience=owner'),
  'current_maturity_limit', 'spec_persistence_only_rendering_not_performed',
  'external_write_authorized', false
), 0, NOW()
WHERE @jhira_ref_unit_id IS NOT NULL;

UPDATE `tmp_jhira_presentation_reference`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('jhira_presentation_reference:', `type`),
  '$.content_type', 'jhira_presentation_repository_reference',
  '$.module_id', 'ai_daily_report_presentation_delivery',
  '$.platforms', JSON_ARRAY(),
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'report_reviewer'),
  '$.scenes', JSON_ARRAY('knowledge_search', 'ai_daily_report_delivery', 'presentation_spec_review', 'training_reference'),
  '$.source_manifest', JSON_EXTRACT(@jhira_ref_manifest, '$'),
  '$.reviewed_at', @jhira_ref_reviewed_at,
  '$.review_due_at', @jhira_ref_review_due_at,
  '$.review_interval_days', 180,
  '$.freshness_policy', 'fixed_commit_reference_refresh_before_new_source_claims',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_current_hotel_fact_and_not_render_proof',
  '$.decision_policy', 'reference_only_source_inspiration_human_review_required',
  '$.decision_safe', false,
  '$.task_draft_safe', false,
  '$.allowed_uses', JSON_ARRAY('knowledge_search', 'presentation_contract_design', 'training_reference', 'internal_spec_review'),
  '$.blocked_uses', JSON_ARRAY('source_code_reuse', 'skill_installation', 'jhira_brand_adoption', 'automatic_quality_pass', 'production_readiness_claim', 'current_hotel_fact', 'current_ota_fact', 'operation_task_creation', 'operation_execution', 'automatic_publication', 'automatic_ota_write', 'automatic_pms_write', 'external_message'),
  '$.seed_owner', @jhira_ref_seed_owner,
  '$.seed_key', CONCAT('jhira_presentation_reference:', `type`),
  '$.seed_version', @jhira_ref_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.contains_approved_publication_plan', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_jhira_presentation_reference` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_jhira_presentation_reference` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_jhira_presentation_reference`;

SET @jhira_ref_staff_content := CONCAT(
  '# 经营报告双格式交付方法 v1.0（JHIRA仓库参考）', '\n\n',
  '## 可吸收方法', '\n',
  '已保存日报 → 证据分层的PresentationSpec → 保存规格与SHA256 → 精确回读 → HTML/PPTX分别渲染与验证。两种格式只允许消费同一规格，不得在渲染阶段重算。', '\n\n',
  '## 当前宿析OS落地', '\n',
  '已实现PresentationSpec生成、内部保存和精确回读；HTML/PPTX仍为not_rendered，外部发布与OTA/PMS写入均未授权。', '\n\n',
  '## 来源边界', '\n',
  '原仓库无开源许可证、依赖未锁定，构建与QA存在关键缺口；仅作reference_only，不安装、不复制源码、不采用JHIRA品牌，也不把有限测试通过写成正式交付或生产可用。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @jhira_ref_unit_name, @jhira_ref_staff_content,
  'AI报告,演示规格,PresentationSpec,HTML,PPTX,证据分层,精确回读,JHIRA参考',
  JSON_ARRAY('AI报告', '演示规格', 'HTML', 'PPTX', '证据分层', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @jhira_ref_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @jhira_ref_staff_content,
  `keywords` = 'AI报告,演示规格,PresentationSpec,HTML,PPTX,证据分层,精确回读,JHIRA参考',
  `tags` = JSON_ARRAY('AI报告', '演示规格', 'HTML', 'PPTX', '证据分层', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @jhira_ref_unit_name;
