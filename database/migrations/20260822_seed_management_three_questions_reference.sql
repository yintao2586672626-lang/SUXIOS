-- Absorb the user-provided "management three questions" source package as a
-- guarded global reference. The package was reviewed statically and was not
-- installed or executed. Its source fixtures, demo accounts, default dates,
-- AI judgments and capability labels are not current-hotel facts, policies,
-- personnel conclusions, task authority, or external-write authorization.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @management_three_version := '2026-08-22.1';
SET @management_three_reviewed_at := '2026-08-22 00:00:00';
SET @management_three_review_due_at := '2027-02-18 00:00:00';
SET @management_three_seed_owner := 'suxios.management_three_questions_reference';
SET @management_three_unit_name := '管理层三问与复查闭环 v1.0（用户源码参考）';
SET @management_three_source := 'management_three_questions_reference';
SET @management_three_archive_sha256 := '2CF5141F480243EBEA75D0520FD299BC2EE4ACB0E8F752113D8B93DB489CEF66';
SET @management_three_tree_sha256 := '6A6D3977B5FDFF4BF64B414F675C1C54D9580079E9E32846527560EB62577CF8';
SET @management_three_share_guide_sha256 := '7D3A2E6F9875F2DE27AC2D5644E08CDAA1B547149A1B74DA43979C9D08F4F688';
SET @management_three_readme_sha256 := 'A8B51E5F89B9C48D5B0786E56F6E4039077CB23FFCBC5F2572757027905E4851';
SET @management_three_package_sha256 := '7FECA0D9C8FBF6404D040CBFBB626BD0FF2888323189CD69ACD5EC1C92E80B78';
SET @management_three_acceptance_doc_sha256 := '381FEF200B1FD1874A9122E1B8AA7CFC6DFDD8E7C8E518571842975116DFC270';
SET @management_three_contracts_sha256 := '5224DBAEDD125F66B7F301A75B9D870B9F1D9A51EF97D964816A6ABED9198E52';
SET @management_three_cases_sha256 := 'D50D388B77B12EAFC20BC6E05C47AC9D59F947ABAD9586011DB557B0092E1B84';
SET @management_three_followups_sha256 := '3BEF7E2F6320B392878926273A712CB6D8E108B836C311A49C3DF1D4484EC381';
SET @management_three_analysis_sha256 := '8BA0E9BDFFA35A5A22E471EB75DA7E062B5E2118E99DF92E3E92A1A8AD77B64C';
SET @management_three_database_sha256 := '1571B9945E85509964EC7E31040CF8DF1264187C59920865D1EB917B677C4806';
SET @management_three_form_sha256 := 'CAC46718209663E91BE14E3C78CAD79F2775E9F8316AE5D0AE6D8118367D2604';
SET @management_three_description := '从用户提供的管理层三问源码、业务合同和本地验收说明中提炼的管理复盘方法：用问题事实、已执行动作和可观察验证形成案例，保存原始回答，失败不掩盖，复查后才能闭环，复发则继续跟进。它只用于知识检索、复盘模板和缺失信息提问，不是任一酒店的当前事实、人员评价、执行任务或外部写入授权。';
SET @management_three_manifest := JSON_OBJECT(
  'material_type', 'user_provided_source_code_sop_acceptance_sample',
  'package_file_name', '管理层三问-分享版-20260822.zip',
  'package_name', 'hotel-talent-growth',
  'package_version', '1.0.0',
  'observed_at', '2026-08-22',
  'archive_sha256', @management_three_archive_sha256,
  'canonical_tree_manifest_sha256', @management_three_tree_sha256,
  'archive_entry_count', 146,
  'source_file_count', 144,
  'uncompressed_bytes', 8193785,
  'path_traversal_entry_count', 0,
  'reparse_point_count', 0,
  'license_status', 'not_provided',
  'review_mode', 'static_white_box_no_execution',
  'execution_state', 'not_installed_not_executed',
  'online_demo_state', 'not_opened_not_authenticated',
  'credential_review', 'no_real_high_entropy_secret_detected_test_placeholder_and_demo_credentials_excluded',
  'network_capability', 'optional_deepseek_and_openai_clients_present_but_not_invoked',
  'source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands',
  'reuse_mode', 'behavior_contract_adaptation_only_due_to_missing_license',
  'files', JSON_ARRAY(
    JSON_OBJECT('file_name', '分享使用说明.md', 'sha256', @management_three_share_guide_sha256),
    JSON_OBJECT('file_name', 'README.md', 'sha256', @management_three_readme_sha256),
    JSON_OBJECT('file_name', 'package.json', 'sha256', @management_three_package_sha256),
    JSON_OBJECT('file_name', 'docs/management-system-local-acceptance.md', 'sha256', @management_three_acceptance_doc_sha256),
    JSON_OBJECT('file_name', 'packages/management-contracts/src/index.ts', 'sha256', @management_three_contracts_sha256),
    JSON_OBJECT('file_name', 'apps/management-api/src/cases/service.ts', 'sha256', @management_three_cases_sha256),
    JSON_OBJECT('file_name', 'apps/management-api/src/followups/service.ts', 'sha256', @management_three_followups_sha256),
    JSON_OBJECT('file_name', 'apps/management-api/src/analysis/deterministic-provider.ts', 'sha256', @management_three_analysis_sha256),
    JSON_OBJECT('file_name', 'apps/management-api/src/db/database.ts', 'sha256', @management_three_database_sha256),
    JSON_OBJECT('file_name', 'apps/management-web/src/components/CaseForm.tsx', 'sha256', @management_three_form_sha256)
  )
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @management_three_unit_name,
  @management_three_source,
  'done',
  @management_three_description,
  JSON_ARRAY('管理层三问', '管理复盘', '复查闭环', '证据', '重复问题', 'reference_only'),
  0,
  'active',
  'user_provided_source_behavior_distilled_as_reference_only',
  @management_three_reviewed_at,
  @management_three_review_due_at,
  JSON_ARRAY(
    '源码合同要求分别保存问题事实、已执行动作和处理后的实际结果或复查安排，并保留三项原始回答。',
    '案例先持久化再分析；分析失败显示FAILED，已保存案例不因AI失败而丢失。',
    '复查只有在结果具体、状态一致且未再次发生时才进入CLOSED；再次发生会形成关联的未解决案例。',
    '写入使用幂等键和请求摘要，店长创建与复查受本人酒店和本人案例范围约束。',
    '能力证据绑定案例事实引用，验收样例明确不输出总分、排名或处罚。'
  ),
  JSON_ARRAY(
    '本任务没有安装依赖、启动分享系统或运行其测试，源码可运行性和在线体验状态未独立验证。',
    '分享包没有提供许可证，因此没有复制其源码、组件或构建产物。',
    '分享包没有提供正式业务数据、现场使用结果、管理改善、成本或收益效果证据。',
    '任一当前酒店的角色、业务日期、管理制度、复查周期、员工表现和授权范围均未绑定。',
    '确定性分析规则与外部模型输出质量未被证明适合宿析OS真实酒店或人员评价。'
  ),
  @management_three_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @management_three_unit_name AND `source` = @management_three_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @management_three_description,
  `tags` = JSON_ARRAY('管理层三问', '管理复盘', '复查闭环', '证据', '重复问题', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_source_behavior_distilled_as_reference_only',
  `reviewed_at` = @management_three_reviewed_at,
  `review_due_at` = @management_three_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '源码合同要求分别保存问题事实、已执行动作和处理后的实际结果或复查安排，并保留三项原始回答。',
    '案例先持久化再分析；分析失败显示FAILED，已保存案例不因AI失败而丢失。',
    '复查只有在结果具体、状态一致且未再次发生时才进入CLOSED；再次发生会形成关联的未解决案例。',
    '写入使用幂等键和请求摘要，店长创建与复查受本人酒店和本人案例范围约束。',
    '能力证据绑定案例事实引用，验收样例明确不输出总分、排名或处罚。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '本任务没有安装依赖、启动分享系统或运行其测试，源码可运行性和在线体验状态未独立验证。',
    '分享包没有提供许可证，因此没有复制其源码、组件或构建产物。',
    '分享包没有提供正式业务数据、现场使用结果、管理改善、成本或收益效果证据。',
    '任一当前酒店的角色、业务日期、管理制度、复查周期、员工表现和授权范围均未绑定。',
    '确定性分析规则与外部模型输出质量未被证明适合宿析OS真实酒店或人员评价。'
  ),
  `truth_profile_version` = @management_three_version,
  `updated_at` = NOW()
WHERE `name` = @management_three_unit_name AND `source` = @management_three_source;

SET @management_three_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @management_three_unit_name AND `source` = @management_three_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_management_three_question_chunks`;
CREATE TEMPORARY TABLE `tmp_management_three_question_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_management_three_question_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_management_three_question_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @management_three_unit_id, 'management_three_questions_source_audit', JSON_OBJECT(
  'scope', 'global_management_review_method_reference',
  'evidence_level', 'user_provided_reviewed_source_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-attachment://管理层三问-分享版-20260822.zip#sha256=', @management_three_archive_sha256)),
  'source_package', JSON_OBJECT(
    'file_name', '管理层三问-分享版-20260822.zip',
    'archive_sha256', @management_three_archive_sha256,
    'canonical_tree_manifest_sha256', @management_three_tree_sha256,
    'source_file_count', 144,
    'package_version', '1.0.0'
  ),
  'static_review', JSON_OBJECT(
    'path_traversal_entry_count', 0,
    'reparse_point_count', 0,
    'license_status', 'not_provided',
    'execution_state', 'not_installed_not_executed',
    'online_demo_state', 'not_opened_not_authenticated',
    'runtime_capabilities_seen', JSON_ARRAY('local_SQLite_write', 'attachment_file_write', 'optional_DeepSeek_call', 'optional_OpenAI_call'),
    'excluded_material', JSON_ARRAY('demo_accounts', 'test_passwords', 'compiled_dist', 'images', 'package_install_commands')
  ),
  'reuse_decision', '只复刻可见行为合同，不复制无许可证源码、构建产物、演示账号或外部连接配置'
), 0, NOW()
WHERE @management_three_unit_id IS NOT NULL;

INSERT INTO `tmp_management_three_question_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @management_three_unit_id, 'management_three_questions_input_contract', JSON_OBJECT(
  'scope', 'global_management_review_method_reference',
  'evidence_level', 'user_provided_reviewed_source_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-attachment://packages/management-contracts/src/index.ts#sha256=', @management_three_contracts_sha256),
    CONCAT('user-attachment://apps/management-web/src/components/CaseForm.tsx#sha256=', @management_three_form_sha256)
  ),
  'source_role_contract', JSON_OBJECT('creator', 'MANAGER_with_bound_hotel', 'reader', 'OWNER_all_hotels_or_MANAGER_own_cases'),
  'questions', JSON_ARRAY(
    JSON_OBJECT('key', 'problem_description', 'question', '今天你主动发现了什么问题？', 'writing_standard', '时间、人物或地点、发生事实和实际影响'),
    JSON_OBJECT('key', 'action_taken', 'question', '你是怎么处理的？', 'writing_standard', '已经做了什么、协调了谁、采取了什么具体动作'),
    JSON_OBJECT('key', 'verification_method', 'question', '处理后的实际结果怎么样？你是怎么确认的？', 'writing_standard', '写明可观察结果和复查方式；尚未确认时写明下一次检查安排')
  ),
  'additional_inputs', JSON_ARRAY('department', 'planned_followup_date', 'followup_note', 'idempotency_key'),
  'suxios_binding_before_real_use', JSON_ARRAY('tenant_id', 'system_hotel_id', 'actor_user_id', 'business_date', 'scope', 'fact_evidence_refs'),
  'missing_input_rule', '三项原始回答或当前酒店身份缺失时保持缺失状态，不由AI补写事实'
), 0, NOW()
WHERE @management_three_unit_id IS NOT NULL;

INSERT INTO `tmp_management_three_question_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @management_three_unit_id, 'management_three_questions_persistence_contract', JSON_OBJECT(
  'scope', 'global_management_review_method_reference',
  'evidence_level', 'user_provided_reviewed_source_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-attachment://apps/management-api/src/cases/service.ts#sha256=', @management_three_cases_sha256),
    CONCAT('user-attachment://apps/management-api/src/db/database.ts#sha256=', @management_three_database_sha256)
  ),
  'source_states', JSON_OBJECT(
    'case', JSON_ARRAY('NEW', 'FOLLOW_UP_PENDING', 'CLOSED', 'UNRESOLVED'),
    'analysis', JSON_ARRAY('PENDING', 'READY', 'FAILED')
  ),
  'persistence_rules', JSON_ARRAY(
    '先保存三项原始回答和复查安排，再执行AI分析',
    'AI失败只把analysis_status标为FAILED，不删除已保存案例',
    '补充内容追加保存，重新分析形成新版本，原始回答不被覆盖',
    '能力证据必须引用对应案例事实和分析版本',
    '同一操作者、范围和幂等键绑定请求摘要，变更内容的重放返回冲突'
  ),
  'readback_contract', JSON_ARRAY('三项原始回答', '当前基础状态', '分析状态与版本历史', '复查记录', '事实引用', '重复问题关联'),
  'failure_states', JSON_ARRAY('missing_required_answer', 'analysis_failed_but_case_saved', 'idempotency_conflict', 'forbidden_scope', 'invalid_case_status')
), 0, NOW()
WHERE @management_three_unit_id IS NOT NULL;

INSERT INTO `tmp_management_three_question_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @management_three_unit_id, 'management_three_questions_closure_gate', JSON_OBJECT(
  'scope', 'global_management_review_method_reference',
  'evidence_level', 'user_provided_reviewed_source_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-attachment://apps/management-api/src/followups/service.ts#sha256=', @management_three_followups_sha256),
    CONCAT('user-attachment://apps/management-api/src/analysis/deterministic-provider.ts#sha256=', @management_three_analysis_sha256)
  ),
  'source_followup_inputs', JSON_ARRAY('followup_date', 'followup_result', 'solved_status', 'repeated', 'next_action', 'idempotency_key'),
  'close_requires', JSON_ARRAY(
    '复查日期不是未来经营日',
    '复查结果包含可观察的具体证据而非只有已处理或已解决',
    'solved_status与复查文字中的观察结果一致',
    '问题没有再次发生'
  ),
  'not_closed_when', JSON_ARRAY('结果笼统', '结果与SOLVED冲突', '复查尚未发生', '问题再次发生', '缺少酒店或操作者范围'),
  'source_default_note', '源码会强制每个新案例进入复查并在缺省时安排次日检查',
  'adaptation_limit', '次日复查只是来源实现默认值，不是任一宿析OS酒店的现行管理制度或统一时限',
  'closure_principle', '处理动作不等于闭环；闭环必须由保存后的同范围复查证据证明'
), 0, NOW()
WHERE @management_three_unit_id IS NOT NULL;

INSERT INTO `tmp_management_three_question_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @management_three_unit_id, 'management_three_questions_recurrence_learning', JSON_OBJECT(
  'scope', 'global_management_review_method_reference',
  'evidence_level', 'user_provided_reviewed_source_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-attachment://apps/management-api/src/followups/service.ts#sha256=', @management_three_followups_sha256),
    CONCAT('user-attachment://packages/management-contracts/src/index.ts#sha256=', @management_three_contracts_sha256)
  ),
  'recurrence_rule', '复查确认再次发生时，原案例保持未解决并创建关联的新案例，继承部门和重复组后继续复查',
  'source_capability_dimensions', JSON_ARRAY('问题发现', '原因分析', '解决能力', '带教能力', '执行能力', '闭环能力'),
  'evidence_rule', '每个能力判断都必须附reason与fact_reference；没有事实时保持UNKNOWN或数据不足',
  'personnel_boundary', JSON_ARRAY('不输出总分', '不做人员排名', '不自动处罚', 'AI判断不是人事结论', '管理者保留最终判断'),
  'review_learning_loop', JSON_ARRAY('保留原始回答', '追加补充', '形成新分析版本', '保存复查结果', '识别复发', '回看同一事实链')
), 0, NOW()
WHERE @management_three_unit_id IS NOT NULL;

INSERT INTO `tmp_management_three_question_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @management_three_unit_id, 'management_three_questions_source_golden_sample', JSON_OBJECT(
  'scope', 'global_management_review_method_reference',
  'evidence_level', 'source_test_fixture_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-attachment://apps/management-api/test/acceptance.test.ts#sha256=not_individually_persisted_archive_bound_', @management_three_archive_sha256)),
  'sample_kind', 'source_acceptance_fixture_not_business_fact',
  'input', JSON_OBJECT(
    'problem', '今天上午发现两笔前台交接记录缺少复核签字',
    'action', '安排主管现场演示并逐项完成交接清单',
    'verification_plan', '计划次日抽查三笔记录并核对完成时间'
  ),
  'expected_after_create', JSON_OBJECT('case_status', 'FOLLOW_UP_PENDING', 'analysis_status', 'READY', 'raw_answers_preserved', true),
  'success_followup', JSON_OBJECT(
    'result', '抽查三笔记录，全部签字完整且完成时间符合要求',
    'solved_status', 'SOLVED',
    'repeated', false,
    'expected_case_status', 'CLOSED'
  ),
  'recurrence_followup', JSON_OBJECT(
    'result', '抽查三笔记录时其中一笔再次缺少交接签字',
    'solved_status', 'UNRESOLVED',
    'repeated', true,
    'expected_case_status', 'UNRESOLVED',
    'expected_linked_case', true
  ),
  'failure_samples', JSON_ARRAY(
    'AI提供者失败时案例保留且analysis_status为FAILED',
    'SOLVED与非正向观察冲突时拒绝或保持未解决',
    '复查日期晚于当前上海经营日时拒绝写入'
  )
), 0, NOW()
WHERE @management_three_unit_id IS NOT NULL;

INSERT INTO `tmp_management_three_question_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @management_three_unit_id, 'management_three_questions_suxios_adaptation', JSON_OBJECT(
  'scope', 'global_management_review_method_reference',
  'evidence_level', 'adapted_behavior_contract_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-attachment://管理层三问-分享版-20260822.zip#sha256=', @management_three_archive_sha256),
    'suxios://operating-question-knowledge-retrieval'
  ),
  'target_entry', JSON_ARRAY('知识中枢', '经营问题只读知识检索'),
  'mapping', JSON_ARRAY(
    JSON_OBJECT('source', '问题描述', 'suxios', '绑定酒店、经营日、范围和来源证据的问题事实'),
    JSON_OBJECT('source', '处理动作', 'suxios', '区分已经执行且有证据的动作与尚待审批的动作草案'),
    JSON_OBJECT('source', '验证方法或结果', 'suxios', '同范围观察证据、保存回读和是否再次发生'),
    JSON_OBJECT('source', 'AI能力判断', 'suxios', '只作为解释或缺口提示，不能替代事实和人工判断')
  ),
  'minimum_real_use_gate', JSON_ARRAY('tenant_id', 'system_hotel_id', 'actor', 'business_date', 'fact_source', 'saved_case_or_action_ref', 'readback_evidence'),
  'recommended_prompt', '请按管理层三问整理：发生了什么可核验事实、已经执行了什么动作、如何用同范围证据确认结果；缺失项直接列出，不补造。',
  'stop_rule', '身份、日期、事实来源、动作证据或复查结果缺失时保持待补或未验证，不自动创建任务、执行动作、评价员工或对外发送'
), 0, NOW()
WHERE @management_three_unit_id IS NOT NULL;

UPDATE `tmp_management_three_question_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('management_three_questions:', `type`),
  '$.content_type', 'management_three_questions_reference',
  '$.module_id', 'management_three_questions_reference',
  '$.platforms', JSON_ARRAY(),
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'department_manager', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('knowledge_search', 'management_review', 'followup_check', 'recurrence_review'),
  '$.source_manifest', JSON_EXTRACT(@management_three_manifest, '$'),
  '$.reviewed_at', @management_three_reviewed_at,
  '$.review_due_at', @management_three_review_due_at,
  '$.review_interval_days', 180,
  '$.freshness_policy', 'reference_only_until_current_hotel_identity_date_policy_and_evidence_are_verified',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_verified_for_current_hotel',
  '$.decision_policy', 'reference_only_human_review_required',
  '$.decision_safe', false,
  '$.task_draft_safe', false,
  '$.allowed_uses', JSON_ARRAY('knowledge_search', 'management_review_template', 'missing_information_questions', 'human_review_checklist', 'training_reference'),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'current_ota_fact', 'automatic_management_conclusion', 'automatic_employee_scoring', 'automatic_ranking_or_penalty', 'operation_task_creation', 'operation_execution', 'automatic_ota_write', 'automatic_pms_write', 'external_message'),
  '$.seed_owner', @management_three_seed_owner,
  '$.seed_key', CONCAT('management_three_questions:', `type`),
  '$.seed_version', @management_three_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.contains_personnel_decision', false,
  '$.source_code_installed', false,
  '$.source_code_executed', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_management_three_question_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_management_three_question_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_management_three_question_chunks`;

SET @management_three_staff_content := CONCAT(
  '# 管理层三问与复查闭环 v1.0（用户源码参考）', '\n\n',
  '## 三问', '\n',
  '1. 今天主动发现了什么可核验问题？写清时间、人物或地点、事实和影响。', '\n',
  '2. 已经怎么处理？写清已执行动作、协同对象和责任。', '\n',
  '3. 实际结果怎么样、如何确认？没有结果时写明下一次检查安排。', '\n\n',
  '## 闭环条件', '\n',
  '处理不等于闭环。只有同一酒店、同一问题范围的具体复查结果与状态一致，且未再次发生，才可标记闭环；复发则继续形成关联问题。', '\n\n',
  '## 使用边界', '\n',
  '这是未运行、无许可证分享包的行为合同参考，不是本店事实、制度、员工评价或任务授权。缺少酒店、经营日、事实来源、动作证据或回读时保持待补；不得自动创建任务、执行动作、评分排名、处罚、写OTA/PMS或外发。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @management_three_unit_name, @management_three_staff_content,
  '管理层三问,管理复盘,问题事实,处理动作,复查证据,闭环,重复问题,店长能力,幂等,失败状态',
  JSON_ARRAY('管理层三问', '管理复盘', '复查闭环', '证据', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @management_three_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @management_three_staff_content,
  `keywords` = '管理层三问,管理复盘,问题事实,处理动作,复查证据,闭环,重复问题,店长能力,幂等,失败状态',
  `tags` = JSON_ARRAY('管理层三问', '管理复盘', '复查闭环', '证据', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @management_three_unit_name;
