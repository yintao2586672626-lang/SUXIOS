-- Seed reviewed public-reference knowledge for OTA public-page diagnosis,
-- OTA operating SOP templates, and OTA competition pulse methods.
-- This migration owns these three global reference units and is safe to rerun.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @external_knowledge_version := '2026-07-16';
SET @external_knowledge_reviewed_at := '2026-07-16';
SET @external_knowledge_seed_owner := 'suxios.ota_external_analysis_sop_knowledge';

DROP TEMPORARY TABLE IF EXISTS `tmp_ota_external_analysis_seed_chunks`;
CREATE TEMPORARY TABLE `tmp_ota_external_analysis_seed_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_ota_external_seed_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Unit 1: OTA public-page diagnosis
-- ---------------------------------------------------------------------------

SET @public_diag_unit_name := 'OTA公开页诊断方法库';
SET @public_diag_source := 'ota_public_page_diagnosis_reference';
SET @public_diag_description := '从已核验的公开OTA分析页面中提炼字段目录、证据合同、质量状态、报告结构和任务闭环。仅用于OTA渠道公开页诊断参考，不使用固定起始分，不以AI兜底掩盖证据缺失。';

INSERT INTO `knowledge_units` (`hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`, `created_at`, `updated_at`)
SELECT
  0,
  @public_diag_unit_name,
  @public_diag_source,
  'done',
  @public_diag_description,
  JSON_ARRAY('OTA公开页', '渠道诊断', '证据链', '字段质量', '任务闭环', 'external_public_reference_reviewed', 'ota_channel', 'manual_review_only'),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @public_diag_unit_name AND `source` = @public_diag_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @public_diag_description,
  `tags` = JSON_ARRAY('OTA公开页', '渠道诊断', '证据链', '字段质量', '任务闭环', 'external_public_reference_reviewed', 'ota_channel', 'manual_review_only'),
  `updated_at` = NOW()
WHERE `name` = @public_diag_unit_name AND `source` = @public_diag_source;

SET @public_diag_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @public_diag_unit_name AND `source` = @public_diag_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `tmp_ota_external_analysis_seed_chunks`
WHERE `unit_id` = @public_diag_unit_id;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @public_diag_unit_id,
  '来源与使用边界',
  JSON_OBJECT(
    'version', @external_knowledge_version,
    'reviewed_at', @external_knowledge_reviewed_at,
    'scope', 'ota_channel_public_page_reference',
    'evidence_level', 'external_public_reference_reviewed',
    'source_refs', JSON_ARRAY(
      'https://fjhoteltools.cn/ota-analysis/',
      'https://fjhoteltools.cn/ota-analysis/app-20260528a.js',
      'docs/ota_external_analysis_sop_knowledge_playbook.md'
    ),
    'source_status', JSON_OBJECT(
      'public_page', 'reviewed',
      'frontend_implementation', 'reviewed',
      'ai_generation', 'not_executed',
      'platform_data_accuracy', 'not_verified'
    ),
    'rules', JSON_ARRAY(
      '本知识只提供字段框架、证据合同、诊断表达和任务模板，不是任何门店的当前经营事实。',
      '只有OTA证据时只输出对应OTA渠道判断，不扩大为全酒店出租率、ADR、RevPAR或投资结论。',
      '不接入外站会员Key、接口、Cookie服务、AI后端或页面代码。',
      '来源变化时保留旧版本和核验日期，不静默覆盖历史。'
    )
  ),
  0,
  NOW()
WHERE @public_diag_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @public_diag_unit_id,
  '十二维诊断目录',
  JSON_OBJECT(
    'scope', 'reference_template',
    'evidence_level', 'external_public_reference_reviewed',
    'source_refs', JSON_ARRAY('https://fjhoteltools.cn/ota-analysis/app-20260528a.js'),
    'platform_examples', JSON_ARRAY('ctrip', 'meituan', 'fliggy'),
    'dimensions', JSON_ARRAY(
      JSON_OBJECT('key', 'platform_display', 'name', '平台与基础展示'),
      JSON_OBJECT('key', 'price_rate_plan', 'name', '价格与价盘'),
      JSON_OBJECT('key', 'review_structure', 'name', '点评结构'),
      JSON_OBJECT('key', 'review_reply', 'name', '点评回复'),
      JSON_OBJECT('key', 'qa_consultation', 'name', '问答与咨询'),
      JSON_OBJECT('key', 'photo_video', 'name', '图片与视频'),
      JSON_OBJECT('key', 'room_type_naming', 'name', '房型及命名'),
      JSON_OBJECT('key', 'agency_distribution', 'name', '代理与分销展示'),
      JSON_OBJECT('key', 'future_stay_price', 'name', '未来日期价格'),
      JSON_OBJECT('key', 'marketing_campaign', 'name', '营销活动'),
      JSON_OBJECT('key', 'package_content', 'name', '套餐与内容展示'),
      JSON_OBJECT('key', 'member_rights', 'name', '会员或权益表达')
    ),
    'rule', '维度是检查目录，不代表各平台字段相同；只有取得事实证据的字段才能进入诊断。'
  ),
  0,
  NOW()
WHERE @public_diag_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @public_diag_unit_id,
  '字段事实合同',
  JSON_OBJECT(
    'scope', 'ota_channel',
    'evidence_level', 'fact_contract',
    'source_refs', JSON_ARRAY('docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'required_fields', JSON_ARRAY(
      'platform',
      'system_hotel_id',
      'platform_hotel_id',
      'business_date',
      'stay_date',
      'captured_at',
      'dimension',
      'field_key',
      'observed_value',
      'source_url',
      'source_method',
      'source_locator',
      'evidence_ref',
      'quality_status',
      'confidence',
      'saved_at',
      'readback_status'
    ),
    'quality_statuses', JSON_ARRAY('verified', 'observed', 'unknown', 'blocked', 'stale', 'conflict'),
    'rules', JSON_ARRAY(
      'system_hotel_id与platform_hotel_id分别表示宿析OS门店和平台门店，不得混用。',
      '来源记录只保存最小必要结构化事实和证据引用，不保存Cookie、会员Key或敏感请求头。',
      '保存成功必须通过回读验证，接口返回成功但回读缺失不能算完成。'
    )
  ),
  0,
  NOW()
WHERE @public_diag_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @public_diag_unit_id,
  '评分与证据覆盖',
  JSON_OBJECT(
    'scope', 'diagnosis_method',
    'evidence_level', 'reviewed_correction',
    'source_refs', JSON_ARRAY('https://fjhoteltools.cn/ota-analysis/app-20260528a.js'),
    'rules', JSON_ARRAY(
      '总分只能从有证据且有明确规则的字段自下而上聚合。',
      'unknown、blocked、stale和conflict不得当作健康，不得用零、历史值或AI文本静默替代。',
      '诊断得分与证据覆盖率分开显示；覆盖不足时返回insufficient_evidence。',
      '不得设置固定起始分，不得因AI调用失败生成伪成功报告。',
      '未验证权重标记为experimental_rule，并保留平台、版本和适用条件。'
    ),
    'output_fields', JSON_ARRAY('diagnosis_score', 'evidence_coverage', 'quality_summary', 'data_gaps', 'rule_version')
  ),
  0,
  NOW()
WHERE @public_diag_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @public_diag_unit_id,
  '诊断报告结构',
  JSON_OBJECT(
    'scope', 'reference_template',
    'evidence_level', 'external_public_reference_reviewed',
    'source_refs', JSON_ARRAY('https://fjhoteltools.cn/ota-analysis/app-20260528a.js'),
    'sections', JSON_ARRAY(
      JSON_OBJECT('order', 1, 'name', '范围与证据覆盖', 'required', JSON_ARRAY('platform', 'hotel', 'date_basis', 'captured_at', 'coverage', 'quality_status')),
      JSON_OBJECT('order', 2, 'name', '事实摘要', 'rule', '只陈述已观察事实'),
      JSON_OBJECT('order', 3, 'name', '主要问题', 'rule', '每个问题引用字段证据'),
      JSON_OBJECT('order', 4, 'name', '机会假设', 'rule', '假设与事实分栏，不把相关性写成因果'),
      JSON_OBJECT('order', 5, 'name', '候选动作', 'rule', '绑定对象、负责人、截止时间、证据和停止条件'),
      JSON_OBJECT('order', 6, 'name', '三十天节奏', 'rule', '节奏是可调整模板，不是固定处方'),
      JSON_OBJECT('order', 7, 'name', '数据缺口', 'rule', '列出无法判断字段和补证方式')
    )
  ),
  0,
  NOW()
WHERE @public_diag_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @public_diag_unit_id,
  '诊断转任务合同',
  JSON_OBJECT(
    'scope', 'operation_task_template',
    'evidence_level', 'reference_template',
    'source_refs', JSON_ARRAY('docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'required_fields', JSON_ARRAY(
      'platform', 'system_hotel_id', 'target_object', 'current_evidence', 'target_state',
      'owner', 'due_at', 'approval_required', 'execution_evidence_required',
      'review_at', 'review_metrics', 'stop_or_rollback'
    ),
    'promotion_rule', '没有执行证据不得完成；没有效果证据不得晋级为validated_sop。',
    'execution_boundary', 'manual_review_then_operation_execution_intent_then_effect_review'
  ),
  0,
  NOW()
WHERE @public_diag_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @public_diag_unit_id,
  '禁止事项',
  JSON_OBJECT(
    'scope', 'guardrail',
    'evidence_level', 'reviewed_correction',
    'source_refs', JSON_ARRAY('docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'blocked_claims', JSON_ARRAY(
      '不得把外部公开页结果写成全酒店经营事实。',
      '不得让未知字段获得健康分或成功状态。',
      '不得在AI失败后用通用文案包装成成功诊断。',
      '不得把公开报价当作真实成交价、剩余库存或利润。',
      '不得由诊断知识直接触发OTA改价、开关房或库存写回。'
    )
  ),
  0,
  NOW()
WHERE @public_diag_unit_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Unit 2: OTA operating SOP reference templates
-- ---------------------------------------------------------------------------

SET @ota_sop_unit_name := 'OTA运营SOP参考模板库';
SET @ota_sop_source := 'ota_operation_sop_reference';
SET @ota_sop_description := '从已核验的公开OTA运营SOP静态资料中提炼岗位、场景、章节、卡片、字段、工作表、指标语义和任务化合同。资料只作参考模板，平台规则和经验阈值必须版本化并经门店实践验证。';

INSERT INTO `knowledge_units` (`hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`, `created_at`, `updated_at`)
SELECT
  0,
  @ota_sop_unit_name,
  @ota_sop_source,
  'done',
  @ota_sop_description,
  JSON_ARRAY('OTA运营', 'SOP', '岗位知识', '检查表', '工作表', '指标口径', 'reference_template', 'external_public_reference_reviewed', 'manual_review_only'),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_sop_unit_name AND `source` = @ota_sop_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @ota_sop_description,
  `tags` = JSON_ARRAY('OTA运营', 'SOP', '岗位知识', '检查表', '工作表', '指标口径', 'reference_template', 'external_public_reference_reviewed', 'manual_review_only'),
  `updated_at` = NOW()
WHERE `name` = @ota_sop_unit_name AND `source` = @ota_sop_source;

SET @ota_sop_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_sop_unit_name AND `source` = @ota_sop_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `tmp_ota_external_analysis_seed_chunks`
WHERE `unit_id` = @ota_sop_unit_id;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_sop_unit_id,
  '来源与使用边界',
  JSON_OBJECT(
    'version', @external_knowledge_version,
    'reviewed_at', @external_knowledge_reviewed_at,
    'scope', 'ota_operation_reference_template',
    'evidence_level', 'external_public_reference_reviewed',
    'source_refs', JSON_ARRAY(
      'https://sop.fjhoteltools.cn/data.js',
      'https://sop.fjhoteltools.cn/app.js',
      'docs/ota_external_analysis_sop_knowledge_playbook.md'
    ),
    'verified_structure', JSON_OBJECT('module_count', 15, 'section_count', 40, 'card_count', 53, 'field_count', 195, 'worksheet_count', 5),
    'implementation_status', JSON_OBJECT(
      'static_content', 'reviewed',
      'search_and_print', 'reviewed',
      'task_persistence', 'not_present_in_external_tool',
      'owner_progress_and_readback', 'not_present_in_external_tool'
    ),
    'rules', JSON_ARRAY(
      '静态资料是参考模板，不是平台官方规则，也不是已验证经营效果。',
      '不复制外站页面、会员体系或运行代码，只沉淀经审计的知识结构和通用方法。',
      'SOP可创建任务草稿，但执行仍需门店身份、平台、日期、权限、负责人和人工审批。'
    )
  ),
  0,
  NOW()
WHERE @ota_sop_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_sop_unit_id,
  '十五模块目录',
  JSON_OBJECT(
    'scope', 'reference_template',
    'evidence_level', 'external_public_reference_reviewed',
    'source_refs', JSON_ARRAY('https://sop.fjhoteltools.cn/data.js'),
    'modules', JSON_ARRAY(
      JSON_OBJECT('key', 'daily', 'name', '日常运营'),
      JSON_OBJECT('key', 'onboarding', 'name', '新店上线'),
      JSON_OBJECT('key', 'diagnosis', 'name', '经营诊断'),
      JSON_OBJECT('key', 'metrics', 'name', '指标口径'),
      JSON_OBJECT('key', 'revenue', 'name', '收益管理'),
      JSON_OBJECT('key', 'page_design', 'name', '页面设计'),
      JSON_OBJECT('key', 'pricing', 'name', '定价'),
      JSON_OBJECT('key', 'promotion', 'name', '促销'),
      JSON_OBJECT('key', 'reviews', 'name', '点评管理'),
      JSON_OBJECT('key', 'negative', 'name', '负面舆情处理'),
      JSON_OBJECT('key', 'review_cycle', 'name', '点评复盘周期'),
      JSON_OBJECT('key', 'performance', 'name', '绩效与协作'),
      JSON_OBJECT('key', 'platforms', 'name', '平台差异'),
      JSON_OBJECT('key', 'templates', 'name', '表单与模板'),
      JSON_OBJECT('key', 'terms', 'name', '术语解释')
    ),
    'navigation_facets', JSON_ARRAY('role', 'scene', 'platform', 'module', 'evidence_level', 'version')
  ),
  0,
  NOW()
WHERE @ota_sop_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_sop_unit_id,
  '标准SOP卡合同',
  JSON_OBJECT(
    'scope', 'reference_template',
    'evidence_level', 'knowledge_contract',
    'source_refs', JSON_ARRAY('docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'required_fields', JSON_ARRAY(
      'role', 'scene', 'objective', 'prerequisites', 'steps', 'evidence_required',
      'owner', 'due_at', 'review_at', 'metrics', 'stop_or_rollback',
      'platform_variants', 'source_refs', 'version', 'evidence_level'
    ),
    'content_layers', JSON_ARRAY('section', 'card', 'field', 'worksheet'),
    'interaction_targets', JSON_ARRAY('search', 'filter', 'checklist', 'print', 'copy_to_task_draft', 'save_and_readback')
  ),
  0,
  NOW()
WHERE @ota_sop_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_sop_unit_id,
  '运营节奏模板',
  JSON_OBJECT(
    'scope', 'reference_template',
    'evidence_level', 'external_public_reference_reviewed',
    'source_refs', JSON_ARRAY('https://sop.fjhoteltools.cn/data.js'),
    'cadence', JSON_ARRAY(
      JSON_OBJECT('period', 'daily', 'checks', JSON_ARRAY('渠道可售', '价盘', '订单异常', '点评与问答', '活动状态', '待办状态'), 'guard', '只报告已核验异常'),
      JSON_OBJECT('period', 'weekly', 'checks', JSON_ARRAY('流量漏斗', '房型与价格计划', '竞对变化', '点评主题', '任务完成率', '效果证据')),
      JSON_OBJECT('period', 'monthly', 'checks', JSON_ARRAY('页面内容', '图片', '房型角色', '促销净收益', '规则阈值', '失效SOP')),
      JSON_OBJECT('period', 'onboarding_or_relaunch', 'checks', JSON_ARRAY('门店身份', '房型映射', '基础信息', '图片', '价盘', '库存', '权益', '政策', '可预订链路')),
      JSON_OBJECT('period', 'incident', 'checks', JSON_ARRAY('保存证据', '时间点', '分级', '指派', '处理', '回读', '复盘'))
    )
  ),
  0,
  NOW()
WHERE @ota_sop_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_sop_unit_id,
  '指标口径修正',
  JSON_OBJECT(
    'scope', 'ota_channel_metric_semantics',
    'evidence_level', 'reviewed_correction',
    'source_refs', JSON_ARRAY('https://sop.fjhoteltools.cn/data.js', 'docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'metrics', JSON_ARRAY(
      JSON_OBJECT('name', '点击率', 'allowed_formulas', JSON_ARRAY('点击量/曝光量', '详情页访客数/曝光访客数'), 'rule', '必须在指标名或元数据中固定分子分母'),
      JSON_OBJECT('name', '订单转化率', 'formula', '订单数/详情访客数', 'rule', '不得与用户转化率混写'),
      JSON_OBJECT('name', '用户转化率', 'formula', '下单用户数/详情访客数', 'rule', '不得与订单转化率混写'),
      JSON_OBJECT('name', '支付转化率', 'formula', '按平台字段目录固定分母', 'rule', '报表之间不得切换分母'),
      JSON_OBJECT('name', '渠道销售强度', 'formula', 'OTA间夜/物理房间数', 'rule', '不是渠道份额'),
      JSON_OBJECT('name', 'OTA渠道份额', 'formula', 'OTA间夜/全渠道间夜', 'rule', '缺全渠道分母时不可计算'),
      JSON_OBJECT('name', 'OTA ADR', 'formula', '对应OTA渠道客房收入/对应OTA渠道已售间夜', 'rule', '不得替代全酒店ADR')
    ),
    'date_basis_rule', '曝光、访问、预订、支付、入住和取消保留各自日期口径，不混成一个漏斗事实。'
  ),
  0,
  NOW()
WHERE @ota_sop_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_sop_unit_id,
  '经验阈值治理',
  JSON_OBJECT(
    'scope', 'rule_governance',
    'evidence_level', 'reviewed_correction',
    'source_refs', JSON_ARRAY('https://sop.fjhoteltools.cn/data.js'),
    'candidate_examples', JSON_ARRAY('回复时限', '竞对数量', '点评主题提及率', '诊断维度权重', '经验评分'),
    'required_metadata', JSON_ARRAY('rule_status', 'source', 'platform', 'hotel_segment', 'applicable_period', 'version', 'review_at', 'effect_evidence'),
    'statuses', JSON_ARRAY('reference_threshold', 'experimental_rule', 'validated_sop', 'stale', 'rejected'),
    'rules', JSON_ARRAY(
      '静态资料中的数字不直接成为宿析OS硬规则。',
      '阈值允许按平台、门店和周期配置，并通过执行效果校准。',
      '平台版本变化、来源失效或效果恶化时必须降级为stale或rejected。'
    )
  ),
  0,
  NOW()
WHERE @ota_sop_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_sop_unit_id,
  '任务化与晋级',
  JSON_OBJECT(
    'scope', 'operation_task_template',
    'evidence_level', 'reference_template',
    'source_refs', JSON_ARRAY('docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'task_states', JSON_ARRAY('draft', 'pending_approval', 'in_progress', 'evidence_pending', 'completed', 'reviewed'),
    'task_contract', JSON_ARRAY('platform', 'system_hotel_id', 'scene', 'target_object', 'owner', 'due_at', 'approval', 'steps', 'execution_evidence', 'review_at', 'review_metrics', 'result'),
    'promotion_contract', JSON_OBJECT(
      'reference_template', '外部资料提炼后默认状态',
      'experimental_rule', '进入门店小范围测试',
      'validated_sop', '执行前后证据、效果复盘和适用边界完整后才可晋级'
    ),
    'execution_boundary', 'knowledge_to_task_draft_only_no_automatic_ota_write'
  ),
  0,
  NOW()
WHERE @ota_sop_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_sop_unit_id,
  '禁止事项',
  JSON_OBJECT(
    'scope', 'guardrail',
    'evidence_level', 'reviewed_correction',
    'source_refs', JSON_ARRAY('docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'blocked_claims', JSON_ARRAY(
      '不得把公开培训内容写成平台强制规则。',
      '不得把静态SOP勾选完成当作经营效果已验证。',
      '不得使用口径不明的转化率、渠道份额或ADR。',
      '不得让知识卡直接触发OTA改价、活动、开关房或库存写回。',
      '不得复制外站会员Key、Cookie、接口、页面或后端能力。'
    )
  ),
  0,
  NOW()
WHERE @ota_sop_unit_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Unit 3: OTA competition pulse and business-district monitoring
-- ---------------------------------------------------------------------------

SET @competition_unit_name := 'OTA商圈竞争脉冲方法库';
SET @competition_source := 'ota_competition_pulse_reference';
SET @competition_description := '从已核验的公开商圈观察介绍中提炼双时间轴、价格分布、OTA渠道可售状态、变化事件、重点竞对深钻和预警转任务方法。外部核心采集准确性未验证，知识不代表实时商圈事实。';

INSERT INTO `knowledge_units` (`hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`, `created_at`, `updated_at`)
SELECT
  0,
  @competition_unit_name,
  @competition_source,
  'done',
  @competition_description,
  JSON_ARRAY('OTA商圈', '竞争脉冲', '竞对监控', '价格分布', '渠道售罄', '事件预警', 'external_public_reference_reviewed', 'ota_channel', 'collection_unverified'),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @competition_unit_name AND `source` = @competition_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @competition_description,
  `tags` = JSON_ARRAY('OTA商圈', '竞争脉冲', '竞对监控', '价格分布', '渠道售罄', '事件预警', 'external_public_reference_reviewed', 'ota_channel', 'collection_unverified'),
  `updated_at` = NOW()
WHERE `name` = @competition_unit_name AND `source` = @competition_source;

SET @competition_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @competition_unit_name AND `source` = @competition_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `tmp_ota_external_analysis_seed_chunks`
WHERE `unit_id` = @competition_unit_id;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @competition_unit_id,
  '来源与使用边界',
  JSON_OBJECT(
    'version', @external_knowledge_version,
    'reviewed_at', @external_knowledge_reviewed_at,
    'scope', 'ota_channel_competition_reference',
    'evidence_level', 'external_public_intro_reviewed_collection_unverified',
    'source_refs', JSON_ARRAY('https://eye-intro.fjhoteltools.cn/', 'docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'source_status', JSON_OBJECT(
      'public_intro', 'reviewed',
      'collection_accuracy', 'not_verified',
      'collection_stability', 'not_verified',
      'account_risk', 'not_accepted_or_tested'
    ),
    'rules', JSON_ARRAY(
      '公开页只能支持OTA渠道报价与可售状态观察，不能推导酒店总房态、真实剩余房量、出租率、ADR、RevPAR或利润。',
      '外部介绍中的采集频率是产品设想，不作为宿析OS默认频率。',
      '不接入外站Cookie采集、会员、接口或服务端抓取体系。'
    )
  ),
  0,
  NOW()
WHERE @competition_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @competition_unit_id,
  '双时间轴快照',
  JSON_OBJECT(
    'scope', 'ota_channel_snapshot_contract',
    'evidence_level', 'reviewed_method',
    'source_refs', JSON_ARRAY('https://eye-intro.fjhoteltools.cn/', 'docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'required_dimensions', JSON_ARRAY('platform', 'business_district', 'system_hotel_id', 'competitor_hotel_id', 'stay_date', 'captured_at', 'comparable_product_key'),
    'required_facts', JSON_ARRAY('display_price', 'availability_status', 'source_url', 'evidence_ref', 'quality_status', 'saved_at', 'readback_status'),
    'availability_statuses', JSON_ARRAY('available', 'sold_out', 'unknown', 'blocked', 'stale'),
    'rule', 'captured_at和stay_date必须同时存在，才能计算变化事件、提前期和节假日售卖节奏。'
  ),
  0,
  NOW()
WHERE @competition_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @competition_unit_id,
  '商圈分布指标',
  JSON_OBJECT(
    'scope', 'ota_channel_market_snapshot',
    'evidence_level', 'derived_metric_contract',
    'source_refs', JSON_ARRAY('https://eye-intro.fjhoteltools.cn/'),
    'required_same_scope', JSON_ARRAY('platform', 'business_district', 'stay_date', 'captured_at_window', 'occupancy_and_product_basis'),
    'metrics', JSON_ARRAY(
      'valid_sample_count', 'unknown_count', 'blocked_count', 'stale_count',
      'minimum_price', 'median_price', 'mean_price', 'maximum_price', 'price_range',
      'available_hotel_count', 'sold_out_hotel_count', 'ota_sold_out_hotel_share',
      'self_price_gap_to_median', 'self_rank_with_sample_size'
    ),
    'sold_out_share_formula', 'sold_out_hotel_count/(available_hotel_count+sold_out_hotel_count)',
    'denominator_rule', 'unknown、blocked和stale不进入分母；结果名称必须是OTA渠道售罄酒店占比。',
    'display_rule', '均值与中位数、区间、样本数和质量状态一起展示。'
  ),
  0,
  NOW()
WHERE @competition_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @competition_unit_id,
  '变化事件目录',
  JSON_OBJECT(
    'scope', 'ota_channel_competition_event',
    'evidence_level', 'reference_template',
    'source_refs', JSON_ARRAY('https://eye-intro.fjhoteltools.cn/', 'docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'event_types', JSON_ARRAY(
      'price_up', 'price_down',
      'available_to_sold_out', 'sold_out_to_available',
      'new_listing', 'listing_missing',
      'material_rank_change', 'market_dispersion_jump',
      'holiday_pressure_change',
      'collection_blocked', 'collection_recovered'
    ),
    'required_fields', JSON_ARRAY('event_type', 'platform', 'competitor_hotel_id', 'stay_date', 'detected_at', 'previous_value', 'current_value', 'change_amount', 'evidence_refs', 'quality_status'),
    'rule', '连续快照生成事件流；采集受阻是数据质量事件，不是经营事件。'
  ),
  0,
  NOW()
WHERE @competition_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @competition_unit_id,
  '重点竞对深钻',
  JSON_OBJECT(
    'scope', 'ota_channel_competitor_drilldown',
    'evidence_level', 'reference_template',
    'source_refs', JSON_ARRAY('https://eye-intro.fjhoteltools.cn/'),
    'observation_tracks', JSON_ARRAY('price_trajectory', 'availability_transition', 'campaign_and_rights', 'page_content_change', 'review_topic_change'),
    'comparison_contract', JSON_ARRAY('platform', 'stay_date', 'captured_at', 'comparable_product_key', 'room_or_rate_plan_mapping_status', 'evidence_ref', 'quality_status'),
    'rules', JSON_ARRAY(
      '不可比房型或价格计划明确标记，不强行排名。',
      '页面变化与经营结果只作为关联线索，不能直接写成因果。',
      '重点竞对由用户选择或以透明规则产生，不用黑箱分数替代选择理由。'
    )
  ),
  0,
  NOW()
WHERE @competition_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @competition_unit_id,
  '预警任务闭环',
  JSON_OBJECT(
    'scope', 'operation_alert_template',
    'evidence_level', 'reference_template',
    'source_refs', JSON_ARRAY('docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'flow', JSON_ARRAY('competition_event', 'threshold_or_rule', 'manual_review', 'operation_task', 'execution_evidence', 'effect_review'),
    'rule_metadata', JSON_ARRAY('source', 'version', 'platform', 'hotel_scope', 'stay_date_scope', 'threshold', 'cooldown', 'dedup_key', 'review_at'),
    'advice_gate', '缺本店库存、Pickup、价格底线、转化和取消证据时，只提示补证或人工判断，不输出可执行调价建议。',
    'execution_boundary', 'no_automatic_ota_price_or_inventory_write'
  ),
  0,
  NOW()
WHERE @competition_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_external_analysis_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @competition_unit_id,
  '禁止事项',
  JSON_OBJECT(
    'scope', 'guardrail',
    'evidence_level', 'reviewed_correction',
    'source_refs', JSON_ARRAY('docs/ota_external_analysis_sop_knowledge_playbook.md'),
    'blocked_claims', JSON_ARRAY(
      '不得把OTA渠道售罄状态称为酒店真实满房。',
      '不得把公开展示价称为成交价或净房价。',
      '不得把unknown、blocked或stale样本计入有效分母。',
      '不得把竞对变化直接转换为自动调价或库存动作。',
      '不得把外部介绍页当作核心采集准确性和稳定性已验证的证据。'
    )
  ),
  0,
  NOW()
WHERE @competition_unit_id IS NOT NULL;

UPDATE `tmp_ota_external_analysis_seed_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit` ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.seed_owner', @external_knowledge_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `seed`.`type`),
  '$.seed_version', @external_knowledge_version
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_ota_external_analysis_seed_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
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
FROM `tmp_ota_external_analysis_seed_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_ota_external_analysis_seed_chunks`;

-- ---------------------------------------------------------------------------
-- Employee-facing knowledge-base mirrors
-- ---------------------------------------------------------------------------

SET @ota_reference_category_name := 'OTA运营与竞争分析';
SET @ota_reference_category_description := 'OTA公开页诊断、运营SOP、商圈竞争观察及任务闭环的参考方法。';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @ota_reference_category_name,
  @ota_reference_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_reference_category_name
);

UPDATE `knowledge_categories`
SET
  `description` = @ota_reference_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_reference_category_name;

SET @ota_reference_category_id := (
  SELECT `id` FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_reference_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @public_diag_staff_content := CONCAT(
  '# OTA公开页诊断方法库', '\n\n',
  '## 使用边界', '\n',
  '本条目用于OTA渠道公开页诊断，不是酒店实时经营事实。只使用有来源、有日期、有质量状态的字段；未知项保持未知。', '\n\n',
  '## 十二维目录', '\n',
  '平台与基础展示、价格与价盘、点评结构、点评回复、问答与咨询、图片与视频、房型及命名、代理与分销展示、未来日期价格、营销活动、套餐与内容展示、会员或权益表达。', '\n\n',
  '## 证据规则', '\n',
  '- 每个字段绑定平台、门店、业务日期、入住日期、采集时间、来源网址、证据引用和质量状态。', '\n',
  '- 诊断得分与证据覆盖率分开；覆盖不足时返回证据不足，不给伪健康分。', '\n',
  '- AI失败、采集受阻和保存回读失败必须如实显示。', '\n\n',
  '## 任务闭环', '\n',
  '问题转任务时绑定执行对象、负责人、截止时间、审批、完成证据、效果指标、复盘时间和停止条件；知识本身不自动写OTA。'
);

SET @ota_sop_staff_content := CONCAT(
  '# OTA运营SOP参考模板库', '\n\n',
  '## 十五模块', '\n',
  '日常运营、新店上线、经营诊断、指标口径、收益管理、页面设计、定价、促销、点评管理、负面舆情处理、点评复盘周期、绩效与协作、平台差异、表单与模板、术语解释。', '\n\n',
  '## 卡片结构', '\n',
  '岗位、场景、目标、前置条件、步骤、完成证据、负责人、截止时间、复盘时间、指标、停止/回滚条件、平台差异、来源和版本。', '\n\n',
  '## 关键口径', '\n',
  '- 点击率、订单转化率、用户转化率和支付转化率必须固定分子分母。', '\n',
  '- OTA间夜/物理房间数是渠道销售强度，不是渠道份额。', '\n',
  '- OTA ADR只代表对应OTA渠道，不代替全酒店ADR。', '\n\n',
  '## 晋级规则', '\n',
  '外部资料默认是reference_template；小范围测试是experimental_rule；只有执行前后证据、效果复盘和适用边界完整后才可晋级validated_sop。'
);

SET @competition_staff_content := CONCAT(
  '# OTA商圈竞争脉冲方法库', '\n\n',
  '## 使用边界', '\n',
  '只观察OTA渠道展示价和可售状态，不推导酒店总房态、真实剩余库存、出租率、ADR、RevPAR或利润。', '\n\n',
  '## 核心方法', '\n',
  '- 每个快照同时记录captured_at和stay_date。', '\n',
  '- 同口径展示样本数、中位数、均值、区间和质量状态。', '\n',
  '- 只称OTA渠道售罄酒店占比；unknown、blocked、stale不进入分母。', '\n',
  '- 连续快照生成价格、可售切换、排名、离散度、节假日压力和采集状态事件。', '\n\n',
  '## 提醒闭环', '\n',
  '事件经过规则和人工复核后才能转任务；缺本店库存、Pickup、价格底线、转化和取消证据时，不生成可执行调价建议。'
);

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@ota_reference_category_id, 0),
  @public_diag_unit_name,
  @public_diag_staff_content,
  'OTA公开页,OTA诊断,携程,美团,飞猪,证据覆盖,字段质量,来源网址,诊断报告,运营任务,ota_channel',
  JSON_ARRAY('OTA公开页', '渠道诊断', '证据链', 'ota_channel', 'external_public_reference_reviewed'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @public_diag_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@ota_reference_category_id, `category_id`),
  `content` = @public_diag_staff_content,
  `keywords` = 'OTA公开页,OTA诊断,携程,美团,飞猪,证据覆盖,字段质量,来源网址,诊断报告,运营任务,ota_channel',
  `tags` = JSON_ARRAY('OTA公开页', '渠道诊断', '证据链', 'ota_channel', 'external_public_reference_reviewed'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @public_diag_unit_name;

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@ota_reference_category_id, 0),
  @ota_sop_unit_name,
  @ota_sop_staff_content,
  'OTA运营,OTA SOP,日常运营,新店上线,经营诊断,收益管理,定价,促销,点评,检查表,工作表,指标口径,validated_sop',
  JSON_ARRAY('OTA运营', 'SOP', '检查表', '指标口径', 'reference_template'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @ota_sop_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@ota_reference_category_id, `category_id`),
  `content` = @ota_sop_staff_content,
  `keywords` = 'OTA运营,OTA SOP,日常运营,新店上线,经营诊断,收益管理,定价,促销,点评,检查表,工作表,指标口径,validated_sop',
  `tags` = JSON_ARRAY('OTA运营', 'SOP', '检查表', '指标口径', 'reference_template'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @ota_sop_unit_name;

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@ota_reference_category_id, 0),
  @competition_unit_name,
  @competition_staff_content,
  'OTA商圈,竞对监控,竞争脉冲,携程竞争圈,美团榜单,渠道售罄,价格分布,双时间轴,事件预警,重点竞对',
  JSON_ARRAY('OTA商圈', '竞争脉冲', '竞对监控', '渠道售罄', 'collection_unverified'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @competition_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@ota_reference_category_id, `category_id`),
  `content` = @competition_staff_content,
  `keywords` = 'OTA商圈,竞对监控,竞争脉冲,携程竞争圈,美团榜单,渠道售罄,价格分布,双时间轴,事件预警,重点竞对',
  `tags` = JSON_ARRAY('OTA商圈', '竞争脉冲', '竞对监控', '渠道售罄', 'collection_unverified'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @competition_unit_name;
