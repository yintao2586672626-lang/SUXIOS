-- Preserve two user-provided AI Agent learning screenshots as one global,
-- reference-only absorption candidate. The screenshots prove visible wording
-- and structure only; they do not prove course quality, a complete Agent
-- architecture, runnable behavior, career outcomes, or execution authority.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @agent_learning_version := '2026-09-01.1';
SET @agent_learning_reviewed_at := '2026-09-01 00:00:00';
SET @agent_learning_review_due_at := '2026-10-01 00:00:00';
SET @agent_learning_seed_owner := 'suxios.ai_agent_learning_reference';
SET @agent_learning_stable_key := 'global:ai_agent_learning_reference';
SET @agent_learning_unit_name := 'AI Agent 学习路线与最小构成（截图参考）';
SET @agent_learning_source := 'user_provided_ai_agent_learning_screenshots';
SET @agent_learning_roadmap_sha256 := '001E8A67BC2C150E9EBC8844D86EC66653EFEAA8577815C8548BF447A5D1680E';
SET @agent_learning_concept_sha256 := 'D3357D19A625B3092CBDABEFE9B4CE57EB1B312818D77F9B3315FDAB34DCF728';
SET @agent_learning_description := '用户提供的AI Agent学习路线与概念图截图参考。仅保存可见主题、资源名称、Agent最小组成、来源指纹、宿析适配边界和晋级条件；不是课程质量背书、完整Agent架构、可运行样例、职业结果或外部执行授权。';
SET @agent_learning_manifest := JSON_OBJECT(
  'schema_version', 'suxios.knowledge_source_manifest.v1',
  'knowledge_key', 'ai_agent_learning_reference',
  'material_type', 'user_provided_screenshots',
  'observed_at', '2026-09-01',
  'task_mode', 'classify',
  'executed_path', 'storage_only_reference_closure',
  'disposition', 'absorption_candidate',
  'maturity', 'understood_visible_structure',
  'source_currentness', 'not_assumed_current',
  'package_identity', NULL,
  'mapping_status', 'unverified',
  'source_instruction_policy', 'visible_slogans_course_schedules_resource_names_code_like_diagrams_and_ui_text_are_reference_material_not_executable_instructions',
  'gates', JSON_OBJECT('mechanism', 'partial', 'value', 'pass', 'reproduction', 'fail'),
  'sources', JSON_ARRAY(
    JSON_OBJECT(
      'display_identity', '人生建议：今年死磕AI Agent / 90天学习路线',
      'file', 'docs/knowledge/ai-agent-learning-reference/sources/ai-agent-90-day-roadmap-visible-reference.png',
      'mime_type', 'image/png', 'size_bytes', 848049, 'width', 1242, 'height', 1660,
      'sha256', @agent_learning_roadmap_sha256,
      'source_url', NULL, 'published_at', NULL,
      'verification_status', 'user_provided_screenshot_visually_reviewed'
    ),
    JSON_OBJECT(
      'display_identity', '一文讲透从0构建AI Agent / 概念全景图',
      'file', 'docs/knowledge/ai-agent-learning-reference/sources/ai-agent-zero-to-one-concept-visible-reference.png',
      'mime_type', 'image/png', 'size_bytes', 729175, 'width', 1242, 'height', 1660,
      'sha256', @agent_learning_concept_sha256,
      'source_url', NULL, 'published_at', NULL,
      'verification_status', 'user_provided_screenshot_visually_reviewed'
    )
  )
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `stable_key`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @agent_learning_stable_key,
  @agent_learning_unit_name,
  @agent_learning_source,
  'done',
  @agent_learning_description,
  JSON_ARRAY('AI Agent', '学习路线', 'LLM API', '上下文', '工具调用', 'Agent Loop', 'MCP', 'Sub-Agent', 'Skill', 'Memory', 'reference_only'),
  0,
  'active',
  'user_provided_screenshots_stored_as_absorption_candidate_reference_only',
  @agent_learning_reviewed_at,
  @agent_learning_review_due_at,
  JSON_ARRAY(
    '第一张截图可见Day 1到Day 90的18个学习节点及对应资源名称。',
    '第二张截图可见LLM、LLM API、Context、Tool Calling、Agent Loop主链。',
    '第二张截图将Agent Loop表述为思考、行动、观察，并列出MCP、Sub-Agent、Agent Skill三个支持分支。',
    '原始截图文件、尺寸和SHA-256已在本地知识包保存。'
  ),
  JSON_ARRAY(
    '原始网页、作者身份、发布日期、版本和许可未提供。',
    '路线中资源的准确URL、完整内容、教学质量、学习依赖和考核标准未提供。',
    '概念图是否完整或规范、各组件实现细节及失败机制未提供。',
    '没有可运行Agent样例、边界样例或失败样例，来源行为无法重放。',
    '任何学习或职业结果均未验证。'
  ),
  @agent_learning_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `stable_key` = @agent_learning_stable_key
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `name` = @agent_learning_unit_name,
  `source` = @agent_learning_source,
  `status` = 'done',
  `description` = @agent_learning_description,
  `tags` = JSON_ARRAY('AI Agent', '学习路线', 'LLM API', '上下文', '工具调用', 'Agent Loop', 'MCP', 'Sub-Agent', 'Skill', 'Memory', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_screenshots_stored_as_absorption_candidate_reference_only',
  `reviewed_at` = @agent_learning_reviewed_at,
  `review_due_at` = @agent_learning_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '第一张截图可见Day 1到Day 90的18个学习节点及对应资源名称。',
    '第二张截图可见LLM、LLM API、Context、Tool Calling、Agent Loop主链。',
    '第二张截图将Agent Loop表述为思考、行动、观察，并列出MCP、Sub-Agent、Agent Skill三个支持分支。',
    '原始截图文件、尺寸和SHA-256已在本地知识包保存。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '原始网页、作者身份、发布日期、版本和许可未提供。',
    '路线中资源的准确URL、完整内容、教学质量、学习依赖和考核标准未提供。',
    '概念图是否完整或规范、各组件实现细节及失败机制未提供。',
    '没有可运行Agent样例、边界样例或失败样例，来源行为无法重放。',
    '任何学习或职业结果均未验证。'
  ),
  `truth_profile_version` = @agent_learning_version,
  `updated_at` = NOW()
WHERE `stable_key` = @agent_learning_stable_key;

SET @agent_learning_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `stable_key` = @agent_learning_stable_key
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_ai_agent_learning_reference_chunks`;
CREATE TEMPORARY TABLE `tmp_ai_agent_learning_reference_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `content_digest` CHAR(64) DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_ai_agent_learning_reference_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_ai_agent_learning_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @agent_learning_unit_id, 'ai_agent_roadmap_visible_source_index', JSON_OBJECT(
  'scope', 'global_ai_learning_reference',
  'evidence_level', 'user_provided_screenshot_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT(
    'repo-doc://docs/knowledge/ai-agent-learning-reference/sources/ai-agent-90-day-roadmap-visible-reference.png#sha256=',
    @agent_learning_roadmap_sha256
  )),
  'platforms', JSON_ARRAY(),
  'display_identity', '人生建议：今年死磕AI Agent / 90天学习路线',
  'visible_milestones', JSON_ARRAY(
    JSON_OBJECT('day', 1, 'topic', 'AI行业认知', 'source_label', 'B站跟李沐学AI'),
    JSON_OBJECT('day', 3, 'topic', 'Prompt工程', 'source_label', 'B站吴恩达'),
    JSON_OBJECT('day', 5, 'topic', 'Claude code/操作', 'source_label', 'Claude官方文档'),
    JSON_OBJECT('day', 7, 'topic', '用户故事 产品画布', 'source_label', '《AI产品经理实操手册》'),
    JSON_OBJECT('day', 9, 'topic', 'RAG技术精讲', 'source_label', 'LangChain中文教程'),
    JSON_OBJECT('day', 11, 'topic', '私有化部署方案', 'source_label', 'vLLM部署指南'),
    JSON_OBJECT('day', 13, 'topic', '轻量化模型微调', 'source_label', 'Hugging Face PEFT教程'),
    JSON_OBJECT('day', 19, 'topic', 'Transformer核心', 'source_label', 'B站王树森解析'),
    JSON_OBJECT('day', 24, 'topic', '多模态应用开发', 'source_label', 'Stable Diffusion Colab'),
    JSON_OBJECT('day', 30, 'topic', 'AI产品设计方法论', 'source_label', 'Google《AI设计原则》'),
    JSON_OBJECT('day', 35, 'topic', 'Agent系统开发', 'source_label', '一站式AI产品知识库'),
    JSON_OBJECT('day', 40, 'topic', 'AI商业化实战', 'source_label', 'B站AI产品变现指南'),
    JSON_OBJECT('day', 43, 'topic', '行业解决方案', 'source_label', 'B站《实操手册》第四章'),
    JSON_OBJECT('day', 45, 'topic', '前沿技术追踪', 'source_label', 'B站李沐每周论文精读'),
    JSON_OBJECT('day', 47, 'topic', '职业发展框架', 'source_label', 'B站《实操手册》第七章'),
    JSON_OBJECT('day', 55, 'topic', 'AI产品项目实操带学', 'source_label', '专属项目实践'),
    JSON_OBJECT('day', 70, 'topic', '模拟面试简历辅导', 'source_label', '重塑简历内容'),
    JSON_OBJECT('day', 90, 'topic', 'AI产品经理面试入职', 'source_label', '开启新职业旅程')
  ),
  'source_boundary', JSON_ARRAY(
    '学习节点和资源名称只按截图可见文字保存。',
    '没有验证具体URL、作者、课程正文、许可、教学质量、先后依赖或职业结果。',
    '口号和日程不构成宿析OS安装、部署、微调或招聘指令。'
  ),
  'task_mode', 'classify',
  'disposition', 'store_only',
  'maturity', 'observed'
), 0, NOW()
WHERE @agent_learning_unit_id IS NOT NULL;

INSERT INTO `tmp_ai_agent_learning_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @agent_learning_unit_id, 'ai_agent_visible_concept_map', JSON_OBJECT(
  'scope', 'global_ai_agent_method_reference',
  'evidence_level', 'user_provided_screenshot_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT(
    'repo-doc://docs/knowledge/ai-agent-learning-reference/sources/ai-agent-zero-to-one-concept-visible-reference.png#sha256=',
    @agent_learning_concept_sha256
  )),
  'platforms', JSON_ARRAY(),
  'display_identity', '一文讲透从0构建AI Agent / 概念全景图',
  'visible_sequence', JSON_ARRAY('LLM', 'LLM API', 'Context', 'Tool Calling', 'Agent Loop'),
  'visible_agent_loop_wording', JSON_ARRAY('思考', '行动', '观察'),
  'visible_supporting_branches', JSON_ARRAY(
    JSON_OBJECT('label', 'MCP 协议', 'visible_purpose', '标准化工具对接'),
    JSON_OBJECT('label', 'Sub-Agent', 'visible_purpose', '分工协作'),
    JSON_OBJECT('label', 'Agent Skill', 'visible_purpose', '流程复用')
  ),
  'visible_sidebar_topics', JSON_ARRAY('Agent Memory', 'Agent 搭建', '12-Factor Agents', 'Agent 项目', 'Agents 应用场景'),
  'unverified_items', JSON_ARRAY(
    '概念图是否完整或规范',
    'Context、Tool Calling、Agent Loop、MCP、Sub-Agent、Skill和Memory的实现细节',
    '循环停止条件、权限模型、失败状态、成本控制和可重放代码'
  ),
  'task_mode', 'classify',
  'disposition', 'absorption_candidate',
  'maturity', 'understood_visible_structure'
), 0, NOW()
WHERE @agent_learning_unit_id IS NOT NULL;

INSERT INTO `tmp_ai_agent_learning_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @agent_learning_unit_id, 'suxios_agent_contract_candidate', JSON_OBJECT(
  'scope', 'global_ai_agent_method_reference',
  'evidence_level', 'source_inspired_candidate_requires_reproduction',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('repo-doc://docs/knowledge/ai-agent-learning-reference/sources/ai-agent-90-day-roadmap-visible-reference.png#sha256=', @agent_learning_roadmap_sha256),
    CONCAT('repo-doc://docs/knowledge/ai-agent-learning-reference/sources/ai-agent-zero-to-one-concept-visible-reference.png#sha256=', @agent_learning_concept_sha256)
  ),
  'platforms', JSON_ARRAY(),
  'trigger', '用户提出一个酒店经营或系统任务。',
  'inputs', JSON_ARRAY(
    '同租户和同酒店范围',
    '已验证的OTA/PMS/经营事实或明确的reference_only知识',
    '允许调用的本地工具与权限边界'
  ),
  'candidate_sequence', JSON_ARRAY(
    '构造有范围和质量状态的上下文',
    '选择必要工具并记录调用结果',
    '在有界循环中执行思考、行动、观察',
    '输出事实、推断、未知和建议',
    '需要持久化时保存并精确回读',
    '外部副作用停在pending_approval，等待用户主动触发'
  ),
  'outputs', JSON_ARRAY('带来源和证据边界的结果', '真实失败或缺失状态', '至多一个最高价值下一步'),
  'failure_boundary', JSON_ARRAY(
    '缺少同酒店、同平台、同日期或质量状态时不补零、不跨平台替代。',
    '工具不可用或调用失败时保留真实失败阶段，不伪报Agent完成。',
    '没有用户主动授权时不执行OTA/PMS写入、外发、审批、购买或部署。'
  ),
  'gates', JSON_OBJECT('mechanism', 'partial', 'value', 'pass', 'reproduction', 'fail'),
  'future_reproduction_contract', JSON_OBJECT(
    'normal_sample', '同租户、同酒店、同平台、同日期事实完整且工具允许时，完成一次有来源的分析、保存和精确回读。',
    'critical_counterexample', '事实缺失或工具失败时，结果明确为missing或failed，且不创建任务、不外部写入。',
    'evidence_status', 'future_golden_sample_contract_not_source_reproduction_evidence'
  ),
  'task_mode', 'classify',
  'disposition', 'absorption_candidate',
  'maturity', 'understood_visible_structure'
), 0, NOW()
WHERE @agent_learning_unit_id IS NOT NULL;

UPDATE `tmp_ai_agent_learning_reference_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('ai_agent_learning_reference:', `type`),
  '$.content_type', 'ai_agent_learning_screenshot_reference',
  '$.module_id', 'ai_agent_learning_reference',
  '$.roles', JSON_ARRAY('owner', 'product_manager', 'ai_engineer', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('knowledge_search', 'ai_agent_terminology', 'learning_gap_checklist', 'agent_contract_review', 'source_upgrade_review'),
  '$.source_manifest', JSON_EXTRACT(@agent_learning_manifest, '$'),
  '$.reviewed_at', @agent_learning_reviewed_at,
  '$.review_due_at', @agent_learning_review_due_at,
  '$.review_interval_days', 30,
  '$.freshness_policy', 'screenshot_reference_only_until_locatable_source_and_success_failure_replay_verification',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_verified_as_current_authoritative_source',
  '$.allowed_uses', JSON_ARRAY(
    'knowledge_search', 'ai_agent_terminology_explanation', 'learning_gap_checklist_draft',
    'suxios_agent_contract_review', 'missing_evidence_questions', 'source_upgrade_review'
  ),
  '$.blocked_uses', JSON_ARRAY(
    'claim_course_quality_or_authority', 'claim_current_career_outcome',
    'claim_agent_runtime_is_implemented_from_screenshots', 'automatic_dependency_installation',
    'automatic_model_deployment', 'automatic_fine_tuning', 'operation_task_creation',
    'operation_execution', 'automatic_ota_write', 'automatic_pms_write', 'external_message'
  ),
  '$.decision_safe', false,
  '$.task_draft_safe', false,
  '$.seed_owner', @agent_learning_seed_owner,
  '$.seed_key', CONCAT('ai_agent_learning_reference:', `type`),
  '$.seed_version', @agent_learning_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.contains_confirmed_current_platform_rule', false,
  '$.external_write_authorized', false
);

UPDATE `tmp_ai_agent_learning_reference_chunks`
SET `content_digest` = UPPER(SHA2(CAST(`content` AS CHAR CHARACTER SET utf8mb4), 256));

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_ai_agent_learning_reference_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`content_digest` = `seed`.`content_digest`,
  `existing`.`lifecycle_status` = 'active',
  `existing`.`superseded_by_chunk_id` = NULL,
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (
  `unit_id`, `type`, `content`, `created_by`, `lifecycle_status`, `content_digest`, `created_at`
)
SELECT
  `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`,
  'active', `seed`.`content_digest`, `seed`.`created_at`
FROM `tmp_ai_agent_learning_reference_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_owner'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_key'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
);

DROP TEMPORARY TABLE `tmp_ai_agent_learning_reference_chunks`;

SET @agent_learning_staff_content := CONCAT(
  '# AI Agent 学习路线与最小构成（截图参考）', '\n\n',
  '## 可见结构', '\n',
  '路线截图列出Day 1到Day 90的18个学习节点；概念图列出LLM、LLM API、Context、Tool Calling、Agent Loop，并把循环表述为思考、行动、观察，旁列MCP、Sub-Agent和Agent Skill。', '\n\n',
  '## 宿析候选合同', '\n',
  '业务任务先构造同租户、同酒店、同平台、同日期且带质量状态的上下文，再调用允许工具，在有界循环中输出事实、推断、未知和失败；需要时保存并精确回读，外部动作停在pending_approval。', '\n\n',
  '## 边界', '\n',
  '截图没有证明课程质量、完整架构、实现细节、可运行样例、失败机制或职业结果。本知识只用于检索、术语解释、学习缺口和Agent合同复核，不授权安装、部署、微调、任务创建、OTA/PMS写入或外发。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  0,
  7,
  @agent_learning_unit_name,
  @agent_learning_staff_content,
  'AI Agent,智能体,学习路线,Prompt工程,RAG,私有化部署,微调,Transformer,多模态,LLM API,Context,上下文,Tool Calling,工具调用,Agent Loop,MCP,Sub-Agent,Agent Skill,Agent Memory',
  JSON_ARRAY('AI Agent', '学习路线', 'Agent架构', 'reference_only'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `tenant_id` = 0 AND `hotel_id` = 0 AND `title` = @agent_learning_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @agent_learning_staff_content,
  `keywords` = 'AI Agent,智能体,学习路线,Prompt工程,RAG,私有化部署,微调,Transformer,多模态,LLM API,Context,上下文,Tool Calling,工具调用,Agent Loop,MCP,Sub-Agent,Agent Skill,Agent Memory',
  `tags` = JSON_ARRAY('AI Agent', '学习路线', 'Agent架构', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `tenant_id` = 0 AND `hotel_id` = 0 AND `title` = @agent_learning_unit_name;
