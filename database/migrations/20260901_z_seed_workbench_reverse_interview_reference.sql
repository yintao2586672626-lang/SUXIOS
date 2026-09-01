-- User-provided WorkBuddy article: preserve the source claims as global
-- reference knowledge and record the bounded SUXIOS reverse-interview
-- adaptation. This seed contains no current hotel facts or write authority.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @workbench_interview_version := '2026-09-01.1';
SET @workbench_interview_reviewed_at := '2026-09-01 00:00:00';
SET @workbench_interview_review_due_at := '2027-02-28 00:00:00';
SET @workbench_interview_seed_owner := 'suxios.workbench_reverse_interview_reference';
SET @workbench_interview_unit_name := '工作台反向采访与可信入口组合 v1.0（用户文章参考）';
SET @workbench_interview_source := 'user_article_workbuddy_workbench_reference';
SET @workbench_interview_source_ref := 'user-message://2026-09-01/workbuddy-workbench-awards';
SET @workbench_interview_source_extract_sha256 := '32a9e5649786b4587768d2bb9545a55f14ab74dc8f0abb1b8bcd10790d064132';

SET @workbench_interview_description := '用户提供的WorkBuddy工作台文章包含一条可重放的方法：面对模糊工作台需求，先反向采访角色、高频任务和首屏结果，再组合已有入口。宿析OS已在智能使用助手中按项目合同适配为每轮一个决定性问题、最多三轮，并保持服务端可信目录和零业务写入。文章中的效率数字、积分、模板、移动端部署和案例均未独立验证，只作reference_only来源声称。';

SET @workbench_interview_manifest := JSON_OBJECT(
  'material_type', 'user_provided_article_text',
  'source_identity', @workbench_interview_source_ref,
  'observed_at', '2026-09-01',
  'original_url', 'not_provided',
  'author', 'not_verified',
  'publication_date', 'not_verified',
  'source_extract_path', 'docs/knowledge/workbuddy-workbench/source-extract.md',
  'source_extract_sha256', @workbench_interview_source_extract_sha256,
  'task_mode', 'delivery',
  'mechanism_gate', 'pass',
  'value_gate', 'pass',
  'reproduction_gate', 'pass_equivalent_golden_sample',
  'source_product_runtime_verified', false,
  'source_case_claims_verified', false,
  'external_account_used', false,
  'external_write_authorized', false
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @workbench_interview_unit_name,
  @workbench_interview_source,
  'done',
  @workbench_interview_description,
  JSON_ARRAY('工作台', '反向采访', '任务导航', '首次使用', '可信入口', 'reference_only'),
  0,
  'active',
  'user_article_method_replayed_and_suxios_adaptation_guarded',
  @workbench_interview_reviewed_at,
  @workbench_interview_review_due_at,
  JSON_ARRAY(
    '来源明确提出先说明角色、日常任务和首屏模块，想不清楚时由系统反向采访。',
    '宿析OS已有智能使用助手和今日经营工作台，不需要新建第二套工作台。',
    '宿析适配已实现每轮一个决定性问题、最多三轮，完成后只进入服务端可信目录。',
    '本地黄金样例、连续三轮、零模型调用和既有路由回归已有聚焦测试。'
  ),
  JSON_ARRAY(
    '原始网址、作者、发布日期和WorkBuddy产品版本未提供。',
    '文章中的2小时到15分钟、60多个文件到4个文件夹等效果数字未独立验证。',
    '模板、专家、积分、签到、移动端部署和公开分享均未在本次任务复验。',
    '尚未验证宿析生产部署、真实新用户采用率或经营效果。'
  ),
  @workbench_interview_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @workbench_interview_unit_name AND `source` = @workbench_interview_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @workbench_interview_description,
  `tags` = JSON_ARRAY('工作台', '反向采访', '任务导航', '首次使用', '可信入口', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_article_method_replayed_and_suxios_adaptation_guarded',
  `reviewed_at` = @workbench_interview_reviewed_at,
  `review_due_at` = @workbench_interview_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '来源明确提出先说明角色、日常任务和首屏模块，想不清楚时由系统反向采访。',
    '宿析OS已有智能使用助手和今日经营工作台，不需要新建第二套工作台。',
    '宿析适配已实现每轮一个决定性问题、最多三轮，完成后只进入服务端可信目录。',
    '本地黄金样例、连续三轮、零模型调用和既有路由回归已有聚焦测试。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '原始网址、作者、发布日期和WorkBuddy产品版本未提供。',
    '文章中的2小时到15分钟、60多个文件到4个文件夹等效果数字未独立验证。',
    '模板、专家、积分、签到、移动端部署和公开分享均未在本次任务复验。',
    '尚未验证宿析生产部署、真实新用户采用率或经营效果。'
  ),
  `truth_profile_version` = @workbench_interview_version,
  `updated_at` = NOW()
WHERE `name` = @workbench_interview_unit_name AND `source` = @workbench_interview_source;

SET @workbench_interview_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @workbench_interview_unit_name AND `source` = @workbench_interview_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_workbench_reverse_interview_reference`;
CREATE TEMPORARY TABLE `tmp_workbench_reverse_interview_reference` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_workbench_interview_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_workbench_reverse_interview_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @workbench_interview_unit_id, 'workbench_article_source_audit', JSON_OBJECT(
  'scope', 'global_product_method_reference',
  'evidence_level', 'user_provided_article_text',
  'source_refs', JSON_ARRAY(@workbench_interview_source_ref, CONCAT('repo://docs/knowledge/workbuddy-workbench/source-extract.md#sha256=', @workbench_interview_source_extract_sha256)),
  'verified_observations', JSON_ARRAY('role_daily_tasks_first_screen_prompt', 'reverse_interview_max_three_questions', 'task_chunking_and_prompt_reuse_advice'),
  'unverified_claims', JSON_ARRAY('workbuddy_templates', 'workbench_builder_expert', 'mobile_home_screen_deployment', 'points_and_daily_sign_in', 'case_efficiency_numbers'),
  'disposition', 'reference_only'
), 0, NOW()
WHERE @workbench_interview_unit_id IS NOT NULL;

INSERT INTO `tmp_workbench_reverse_interview_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @workbench_interview_unit_id, 'workbench_reverse_interview_contract', JSON_OBJECT(
  'scope', 'system_usage_guidance',
  'trigger', JSON_ARRAY('反过来采访我', '帮我搭工作台', '定制个人工作台'),
  'required_inputs', JSON_ARRAY('user_role', 'high_frequency_routine_tasks', 'first_screen_desired_results'),
  'sequence', JSON_ARRAY('ask_role', 'ask_routine_tasks', 'ask_first_screen_results', 'compose_existing_trusted_entries'),
  'source_question_limit', 'up_to_three_questions_per_round',
  'suxios_adaptation', 'one_decisive_question_per_round_up_to_three_rounds',
  'success_state', 'trusted_catalog_route_ready',
  'failure_state', 'clarification_required_without_action',
  'not_applicable', JSON_ARRAY('specific_existing_page_request', 'current_hotel_fact_query', 'external_platform_execution')
), 0, NOW()
WHERE @workbench_interview_unit_id IS NOT NULL;

INSERT INTO `tmp_workbench_reverse_interview_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @workbench_interview_unit_id, 'workbench_suxios_guarded_adaptation', JSON_OBJECT(
  'scope', 'system_usage_assistant',
  'delivery_entry', 'app/service/SystemUsageAssistantService.php::guide',
  'prompt_version', 'system_usage_assistant.zh-CN.v6',
  'golden_sample', '帮我搭个个人工作台，反过来采访我。',
  'golden_result', 'clarification_required_role_question_no_action_no_model_call',
  'boundary_sample', '今天先做什么',
  'boundary_result', 'existing_daily_workbench_route_unchanged',
  'round_limit', 3,
  'questions_per_round', 1,
  'trusted_catalog_only', true,
  'business_write_performed', false,
  'maturity', 'guarded_local'
), 0, NOW()
WHERE @workbench_interview_unit_id IS NOT NULL;

INSERT INTO `tmp_workbench_reverse_interview_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @workbench_interview_unit_id, 'workbench_article_case_claims', JSON_OBJECT(
  'scope', 'source_claims_only',
  'claims', JSON_ARRAY('pregnancy_management_workbench', 'monthly_presentation_skill', 'investor_persona_distillation', 'business_file_and_group_organization'),
  'reported_numbers', JSON_ARRAY('presentation_two_hours_to_fifteen_minutes', 'desktop_more_than_sixty_files_to_four_folders', 'customer_material_found_within_ten_seconds'),
  'verification_status', 'not_independently_verified',
  'allowed_use', 'historical_source_context_only',
  'blocked_use', JSON_ARRAY('suxios_effect_claim', 'roi_claim', 'pricing_or_operation_decision', 'task_creation')
), 0, NOW()
WHERE @workbench_interview_unit_id IS NOT NULL;

UPDATE `tmp_workbench_reverse_interview_reference`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('workbench_reverse_interview:', `type`),
  '$.content_type', 'workbench_reverse_interview_reference',
  '$.stable_key', CONCAT('global:user_reference:workbench_reverse_interview:', `type`),
  '$.module_id', 'system_usage_assistant',
  '$.platforms', JSON_ARRAY(),
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'operations_manager'),
  '$.scenes', JSON_ARRAY('knowledge_search', 'first_use_guidance', 'task_navigation', 'training_reference'),
  '$.source_manifest', JSON_EXTRACT(@workbench_interview_manifest, '$'),
  '$.reviewed_at', @workbench_interview_reviewed_at,
  '$.review_due_at', @workbench_interview_review_due_at,
  '$.freshness_policy', 'refresh_if_original_source_or_product_runtime_is_later_verified',
  '$.decision_policy', 'reference_only_and_server_catalog_navigation_only',
  '$.decision_safe', false,
  '$.task_draft_safe', false,
  '$.allowed_uses', JSON_ARRAY('knowledge_search', 'first_use_guidance_design', 'task_navigation_training'),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'current_ota_fact', 'current_pms_fact', 'automatic_task_creation', 'operation_execution', 'automatic_external_write', 'product_effect_claim'),
  '$.seed_owner', @workbench_interview_seed_owner,
  '$.seed_key', CONCAT('workbench_reverse_interview:', `type`),
  '$.seed_version', @workbench_interview_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_workbench_reverse_interview_reference` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_workbench_reverse_interview_reference` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
);

DROP TEMPORARY TABLE `tmp_workbench_reverse_interview_reference`;

SET @workbench_interview_staff_content := CONCAT(
  '# 工作台反向采访与可信入口组合 v1.0（用户文章参考）', '\n\n',
  '## 已吸收机制', '\n',
  '当用户只说“帮我搭工作台”或要求反向采访时，不立即跳页面；先逐轮确认角色、每天反复处理的真实工作和首屏最想看到的结果，再只组合宿析OS已登记入口。每轮只问一个决定性问题，最多三轮。', '\n\n',
  '## 当前落地', '\n',
  '智能使用助手已接入该流程；采访阶段只返回clarification_required且不产生动作，信息收齐后才进入今日经营工作台或其他可信入口。', '\n\n',
  '## 来源边界', '\n',
  '文章中的产品模板、专家、积分、移动端部署、案例和效率数字未独立验证，只作reference_only来源声称；不能成为宿析OS效果、成本、酒店事实或自动任务依据。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @workbench_interview_unit_name, @workbench_interview_staff_content,
  '工作台,反向采访,智能使用助手,今日经营工作台,任务导航,首次使用,高频任务,首屏,WorkBuddy参考',
  JSON_ARRAY('工作台', '反向采访', '任务导航', '首次使用', '可信入口', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @workbench_interview_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @workbench_interview_staff_content,
  `keywords` = '工作台,反向采访,智能使用助手,今日经营工作台,任务导航,首次使用,高频任务,首屏,WorkBuddy参考',
  `tags` = JSON_ARRAY('工作台', '反向采访', '任务导航', '首次使用', '可信入口', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @workbench_interview_unit_name;
