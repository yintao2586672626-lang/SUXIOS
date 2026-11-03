-- Absorb the reusable decision-framing pattern from the user-provided
-- 165-perspective Skill bundle. The bundled personas remain untrusted,
-- uninstalled reference material. This seed does not authenticate historical
-- quotations, create current hotel/OTA facts, or authorize external actions.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @master_lens_version := '2026-08-20.1';
SET @master_lens_reviewed_at := '2026-08-20 00:00:00';
SET @master_lens_review_due_at := '2027-02-16 00:00:00';
SET @master_lens_seed_owner := 'suxios.master_perspectives_multi_lens_knowledge';
SET @master_lens_unit_name := '酒店经营多视角审视与反证方法';
SET @master_lens_source := 'revenue_operations_decision_support';
SET @master_lens_source_sha256 := '32C06DE45983119EFD6F7CFA9B1E8CA5CE59F8A4E5339267DC383A5FC0EE3970';
SET @master_lens_manifest_sha256 := '88170B85CE451483C14267E7DEFECEED2DE70CF0FCBF2D9ED024A938CF15D616';
SET @master_lens_method_pack_sha256 := '0C0D00629C53ECE2177A67FB3A77CFE5CBD30744833873246EE60F368B115178';
SET @master_lens_model_sha256 := '4C86CD421BA900FCF5109EA354709B4378C91862BF2EFDF6BE579E6CA213B64F';
SET @master_lens_description := '从用户提供的165位大师视角Skill包中提炼的酒店经营多视角审视方法。它只用有限视角提出证据问题、反证、分歧和小步验证，不安装人物角色、不模仿口吻、不把人物权威或未核实名言当作事实，也不授权自动改价、库存、OTA/PMS写入、外发或发布。';
SET @master_lens_source_manifest := JSON_OBJECT(
  'material_type', 'user_provided_nested_skill_zip',
  'file_name', '165位大师视角Skill(1).zip',
  'sha256', @master_lens_source_sha256,
  'source_manifest_sha256', @master_lens_manifest_sha256,
  'method_pack_sha256', @master_lens_method_pack_sha256,
  'integrated_model_sha256', @master_lens_model_sha256,
  'observed_at', '2026-08-20',
  'nested_skill_count', 165,
  'category_count', 18,
  'license', 'MIT',
  'script_file_count', 0,
  'requested_tool_permission_count', 0,
  'static_preview_counts', JSON_OBJECT('previewed', 150, 'review_required', 10, 'invalid', 5),
  'live_repository_refresh', 'not_performed',
  'historical_authenticity', 'not_verified_against_primary_sources',
  'reuse_mode', 'paraphrased_reference_lenses_only',
  'installation_status', 'none_installed'
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @master_lens_unit_name,
  @master_lens_source,
  'done',
  @master_lens_description,
  JSON_ARRAY('经营诊断', '多视角', '反证', '客户价值', '战略', '执行', '风险', 'reference_only', 'global_reference'),
  0,
  'active',
  'user_provided_skill_bundle_adapted_as_reference_only_multi_lens_method',
  @master_lens_reviewed_at,
  @master_lens_review_due_at,
  JSON_ARRAY(
    '来源包含165个子Skill、18个分类，子包均带MIT许可证和嵌入式Git来源信息。',
    '静态审查未发现运行脚本、工具预授权、路径穿越或符号链接。',
    '来源材料的共同可复用模式是从不同问题框架提出互补追问、反证和小步验证。',
    '宿析只保留有限领域视角卡，不安装165个人物角色或自动触发器。',
    '经营审视必须先锁定酒店、平台、日期、指标、来源、质量和保存回读状态。'
  ),
  JSON_ARRAY(
    '来源包中的人物归因、名言和历史解释未逐条对照一手来源。',
    '嵌入式Git提交已记录，但未联网刷新远端仓库当前状态。',
    '5个子Skill的frontmatter不符合可移植Skill命名规范。',
    '该多视角方法对酒店经营结果的增益尚无现场、生产或A/B证据。',
    '任何具体酒店、OTA或PMS事实仍需独立的同店同日期采集、保存和回读。'
  ),
  @master_lens_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @master_lens_unit_name AND `source` = @master_lens_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @master_lens_description,
  `tags` = JSON_ARRAY('经营诊断', '多视角', '反证', '客户价值', '战略', '执行', '风险', 'reference_only', 'global_reference'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_skill_bundle_adapted_as_reference_only_multi_lens_method',
  `reviewed_at` = @master_lens_reviewed_at,
  `review_due_at` = @master_lens_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '来源包含165个子Skill、18个分类，子包均带MIT许可证和嵌入式Git来源信息。',
    '静态审查未发现运行脚本、工具预授权、路径穿越或符号链接。',
    '来源材料的共同可复用模式是从不同问题框架提出互补追问、反证和小步验证。',
    '宿析只保留有限领域视角卡，不安装165个人物角色或自动触发器。',
    '经营审视必须先锁定酒店、平台、日期、指标、来源、质量和保存回读状态。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '来源包中的人物归因、名言和历史解释未逐条对照一手来源。',
    '嵌入式Git提交已记录，但未联网刷新远端仓库当前状态。',
    '5个子Skill的frontmatter不符合可移植Skill命名规范。',
    '该多视角方法对酒店经营结果的增益尚无现场、生产或A/B证据。',
    '任何具体酒店、OTA或PMS事实仍需独立的同店同日期采集、保存和回读。'
  ),
  `truth_profile_version` = @master_lens_version,
  `updated_at` = NOW()
WHERE `name` = @master_lens_unit_name AND `source` = @master_lens_source;

SET @master_lens_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @master_lens_unit_name AND `source` = @master_lens_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_master_lens_chunks`;
CREATE TEMPORARY TABLE `tmp_master_lens_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_master_lens_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'master_perspective_source_scope_reference', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'user_provided_skill_bundle_reviewed_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-file://165位大师视角Skill(1).zip#sha256=', @master_lens_source_sha256),
    CONCAT('repo-doc://docs/knowledge/master-perspectives/source-manifest.json#sha256=', @master_lens_manifest_sha256),
    CONCAT('repo-doc://docs/knowledge/master-perspectives/method-pack.json#sha256=', @master_lens_method_pack_sha256),
    CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)
  ),
  'search_terms', JSON_ARRAY('大师视角', '多视角', '经营诊断', '反证', '知识吸纳', '酒店运营'),
  'observed_source_facts', JSON_ARRAY(
    '外层ZIP包含165个可读取子Skill和18个分类。',
    '165个子包均无运行脚本和工具预授权，均携带MIT许可证。',
    '150个子包通过结构预览，10个需人工复核且已确认是词义或大图片命中，5个frontmatter不兼容。'
  ),
  'adaptation_boundaries', JSON_ARRAY(
    '不安装子Skill、不加载人物触发器、不复制Git历史或大图片。',
    '人物观点、引语和历史归因只作为来源包的未验证综合，不冒充本人真实观点。',
    '全量165条保存在来源索引；运行时只选有限领域视角卡。'
  )
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_evidence_and_uncertainty', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('携程', '美团', '曝光', '流量', '转化', '订单', '收益', '诊断', '噪声', '样本', '反证'),
  'lens_label', '证据与不确定性',
  'business_question', '我们真正测到了什么，哪些只是噪声、偏差或未经反证的解释？',
  'adapted_probes', JSON_ARRAY(
    '用可观察字段、分母和简单语言重述结论。',
    '识别重复、缺失或被采集噪声污染的信号。',
    '检查基准率、样本量、选择偏差和事后解释。',
    '列出最可能推翻当前假设的反证。'
  ),
  'source_lens_labels', JSON_ARRAY('费曼', '香农', '卡尼曼', '达尔文')
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_customer_and_value', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('客人', '客户', '价值', '预订', '转化', '点评', '体验', '房型', '产品', '需求'),
  'lens_label', '客户与价值',
  'business_question', '这个变化对应哪类客人的哪一步真实体验与选择？',
  'adapted_probes', JSON_ARRAY(
    '确认目标客人认可的价值以及指标是否真正测到它。',
    '从客人最终结果倒推最早的阻塞步骤。',
    '把消费心理判断写成可验证假设。',
    '减少一步操作、一个认知负担或一次无效打扰。'
  ),
  'source_lens_labels', JSON_ARRAY('德鲁克', '贝佐斯', '铃木敏文', '张小龙')
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_competition_and_strategy', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('竞争', '竞品', '排名', '价格', '渠道', '战略', '资源', '市场', '携程', '美团'),
  'lens_label', '竞争与战略',
  'business_question', '在信息、资源和约束下，哪里是最值得投入且可验证的突破口？',
  'adapted_probes', JSON_ARRAY(
    '行动前检查胜负条件和缺失信息。',
    '同时核对本店、客人和竞争环境。',
    '区分日常波动与战略转折。',
    '用非主流解释和长期影响反查数据结论。'
  ),
  'source_lens_labels', JSON_ARRAY('孙子', '大前研一', '安迪·格鲁夫', '梁建章')
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_operations_and_execution', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('运营', '执行', 'SOP', '任务', '负责人', '复核', '回读', '停止条件', '回滚'),
  'lens_label', '运营与执行',
  'business_question', '怎样把认知变成最小动作、负责人、停止条件和回读证据？',
  'adapted_probes', JSON_ARRAY(
    '明确当前最小知行闭环与回读时间。',
    '把复杂方案改成稳定重复的日课和检查点。',
    '选择最高管理杠杆并锁定负责人和复核节奏。',
    '通过一次真实操作产生下一轮学习证据。'
  ),
  'source_lens_labels', JSON_ARRAY('王阳明', '曾国藩', '安迪·格鲁夫', '杜威')
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_risk_and_resilience', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('风险', '不确定性', '价格', '库存', '投资', '尾部', '安全边际', '回滚', '止损'),
  'lens_label', '风险与韧性',
  'business_question', '下行风险、不可逆性和模型失效点在哪里，怎样先保留选择权？',
  'adapted_probes', JSON_ARRAY(
    '识别会让方案失效的尾部事件并限制暴露。',
    '区分真实改善与承担更多风险后出现的好结果。',
    '检查安全边际以及是否超出可解释和可控制范围。',
    '显式记录决策原则、错误和复盘触发条件。'
  ),
  'source_lens_labels', JSON_ARRAY('塔勒布', '霍华德·马克斯', '巴菲特', '达利欧')
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_communication_and_alignment', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('沟通', '团队', '客诉', '协同', '会议', '表达', '异议', '责任', '概念'),
  'lens_label', '沟通与协同',
  'business_question', '哪些概念、利益、异议或未说出口的信息阻碍了协同？',
  'adapted_probes', JSON_ARRAY(
    '连续追问关键概念和前提。',
    '先听清事实、约束和异议，再形成方案。',
    '删除空话、模糊词和无证据因果。',
    '明确角色、责任和相互尊重。'
  ),
  'source_lens_labels', JSON_ARRAY('苏格拉底', '查理·罗斯', '奥威尔', '孔子')
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_ethics_and_fairness', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('公平', '员工', '客人', '隐私', '权限', '跨酒店', '歧视', '评价', '制度'),
  'lens_label', '伦理与公平',
  'business_question', '方案是否把人当作目的，是否存在跨酒店、歧视、操纵或不公平归因？',
  'adapted_probes', JSON_ARRAY(
    '一致、公平地衡量资源与影响。',
    '检查规则能否普遍适用且不把人仅当作手段。',
    '在冲突中保留尊严、制度修复与长期协作。',
    '制度按真实行为风险设计，不依赖理想化假设。'
  ),
  'source_lens_labels', JSON_ARRAY('墨子', '康德', '曼德拉', '荀子')
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_selection_and_disagreement_contract', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('多视角', '选择', '分歧', '共识', '证据', '反证', '经营问题'),
  'selection_contract', JSON_OBJECT(
    'minimum_lenses', 2,
    'maximum_lenses', 5,
    'required_operating_domains', JSON_ARRAY('evidence_and_uncertainty', 'customer_and_value'),
    'require_counter_evidence_or_risk_lens', true,
    'selection_basis', 'business_question_and_evidence_gap_not_person_fame'
  ),
  'disagreement_contract', JSON_ARRAY(
    '先记录一致项和分歧项。',
    '缺少同口径证据时不投票、不按人物数量或名气强行共识。',
    '每条假设必须带支持证据、冲突证据、未知和可证伪条件。'
  )
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

INSERT INTO `tmp_master_lens_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @master_lens_unit_id, 'multi_lens_hotel_review_workflow', JSON_OBJECT(
  'scope', 'global_methodology_reference',
  'evidence_level', 'adapted_reference_method',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/knowledge/master-perspectives/integrated-model.json#sha256=', @master_lens_model_sha256)),
  'search_terms', JSON_ARRAY('酒店', '携程', '美团', '收益', '曝光', '转化', '订单', '价格', '库存', '运营诊断'),
  'required_fact_scope', JSON_ARRAY('tenant_id', 'system_hotel_id', 'platform_or_source', 'business_date_or_period', 'metric_definition', 'source_method', 'captured_or_exported_at', 'quality_status', 'persistence_and_readback_status'),
  'workflow', JSON_ARRAY(
    '锁定事实范围；缺酒店、来源、日期或指标口径时返回not_ready。',
    '按问题选择2至5个领域视角。',
    '分别列支持证据、冲突证据、未知和可证伪条件。',
    '保留一致与分歧，不强行共识。',
    '最多形成一个待人工确认的本地动作草案，带负责人、复核指标、周期、停止条件和回滚。',
    '获批执行后按同酒店、平台和日期口径保存回读；相关性不自动升级为因果。'
  ),
  'status_values', JSON_ARRAY('not_ready', 'reference_only_panel', 'reviewed_draft', 'pending_approval'),
  'failure_examples', JSON_ARRAY(
    '缺system_hotel_id或business_date时smallest_action_draft必须为空。',
    '价格降低、曝光和订单同时上升但缺竞品、活动成本与PMS净收入时保留分歧且causality_claimed=false。'
  )
), 0, NOW()
WHERE @master_lens_unit_id IS NOT NULL;

UPDATE `tmp_master_lens_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('hotel_operating_multi_lens_review:', `type`),
  '$.content_type', 'reference_method_contract',
  '$.module_id', 'hotel_operating_multi_lens_review',
  '$.platforms', JSON_ARRAY('ctrip', 'meituan', 'suxios_internal'),
  '$.roles', JSON_ARRAY('owner', 'revenue_manager', 'operator', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('operating_question', 'revenue_diagnosis', 'ota_diagnosis', 'operation_review', 'decision_preflight'),
  '$.source_manifest', JSON_EXTRACT(@master_lens_source_manifest, '$'),
  '$.reviewed_at', @master_lens_reviewed_at,
  '$.review_due_at', @master_lens_review_due_at,
  '$.review_interval_days', 180,
  '$.freshness_policy', 'reference_only_until_source_and_hotel_specific_verification',
  '$.decision_policy', 'reference_only_human_review',
  '$.allowed_uses', JSON_ARRAY('operating_question_framing', 'hypothesis_generation', 'counter_evidence_prompting', 'review_draft_preparation', 'human_discussion'),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'current_ota_fact', 'historical_quote_attribution', 'persona_impersonation', 'operation_task_creation', 'operation_execution', 'automatic_pricing', 'automatic_inventory_change', 'automatic_ota_write', 'automatic_pms_write', 'automatic_message_send', 'automatic_social_publication', 'employee_ranking', 'medical_psychological_legal_or_investment_conclusion'),
  '$.seed_owner', @master_lens_seed_owner,
  '$.seed_key', CONCAT('hotel_operating_multi_lens_review:', `type`),
  '$.seed_version', @master_lens_version,
  '$.lifecycle_status', 'active',
  '$.decision_safe', false,
  '$.task_draft_safe', false,
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_master_lens_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_master_lens_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_master_lens_chunks`;

SET @master_lens_staff_content := CONCAT(
  '# 酒店经营多视角审视与反证方法', '\n\n',
  '## 使用方式', '\n',
  '先锁定酒店、平台、日期、指标、来源和回读状态，再从证据、客户、战略、执行、风险、沟通、公平七类中选2至5个视角。', '\n\n',
  '## 输出规则', '\n',
  '每个视角只提出问题、证据、反证、未知和可证伪假设；先保留分歧，再形成最多一个待人工确认的小动作草案。', '\n\n',
  '## 边界', '\n',
  '165个来源Skill均未安装；不模仿人物、不把未核实名言当事实、不补齐酒店数据、不自动改价、改库存、写OTA/PMS、发送或发布。', '\n\n',
  '## 失败状态', '\n',
  '缺酒店、来源、日期或指标口径时返回not_ready；证据不能裁决分歧时保持reference_only_panel。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @master_lens_unit_name, @master_lens_staff_content,
  '大师视角,多视角,经营诊断,反证,客户价值,竞争战略,运营执行,风险韧性,沟通协同,公平,携程,美团',
  JSON_ARRAY('经营诊断', '多视角', '反证', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @master_lens_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @master_lens_staff_content,
  `keywords` = '大师视角,多视角,经营诊断,反证,客户价值,竞争战略,运营执行,风险韧性,沟通协同,公平,携程,美团',
  `tags` = JSON_ARRAY('经营诊断', '多视角', '反证', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @master_lens_unit_name;
