-- Seed structured, callable revenue-operations decision-support knowledge.
-- Generic methods are globally reusable; the Moke Yuexiang figures remain an explicit case_reference.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @revops_unit_name := '收益运营诊断与建议知识底座';
SET @revops_source := 'revenue_operations_decision_support';
SET @revops_description := '从用户提供的墨客悦享经营规划、运营要点与教学解读中提炼的结构化收益运营知识。通用方法可用于诊断和建议结构，案例数值必须显式指定 case_key 才可读取，不替代当前门店真实数据，不触发自动 OTA 写入。';

INSERT INTO `knowledge_units` (`hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`, `created_at`, `updated_at`)
SELECT
  0,
  @revops_unit_name,
  @revops_source,
  'done',
  @revops_description,
  JSON_ARRAY('收益运营', '经营诊断', '收入桥接', '渠道收益', '房型角色', 'OTB', 'Pickup', '建议卡', 'structured_knowledge', 'user_provided_unverified', 'manual_review_only'),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @revops_unit_name AND `source` = @revops_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @revops_description,
  `tags` = JSON_ARRAY('收益运营', '经营诊断', '收入桥接', '渠道收益', '房型角色', 'OTB', 'Pickup', '建议卡', 'structured_knowledge', 'user_provided_unverified', 'manual_review_only'),
  `updated_at` = NOW()
WHERE `name` = @revops_unit_name AND `source` = @revops_source;

SET @revops_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @revops_unit_name AND `source` = @revops_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @revops_unit_id
  AND `type` IN (
    '使用边界',
    '收入变化诊断',
    '渠道收益诊断',
    '房型角色方法',
    '周期目标校验',
    'OTB与Pickup规则',
    '建议卡契约',
    '证据缺口',
    '墨客悦享案例',
    '禁止事项'
  );

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '使用边界',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'distilled_method_from_user_material',
    'source_refs', JSON_ARRAY('moke_2026_h2_plan', 'moke_100_points', 'moke_teaching_transcript'),
    'source_manifest', JSON_OBJECT(
      'moke_2026_h2_plan', JSON_OBJECT('title', '0706墨客悦享_2026下半年经营规划报告', 'kind', 'user_provided_docx', 'verification_status', 'user_provided_unverified'),
      'moke_100_points', JSON_OBJECT('title', '墨客悦享_酒店经营运营金句与核心要点100条', 'kind', 'user_provided_docx', 'verification_status', 'user_provided_unverified'),
      'moke_teaching_transcript', JSON_OBJECT('title', '墨客悦享_2026下半年经营规划_教学解读与逐字稿', 'kind', 'user_provided_docx', 'verification_status', 'user_provided_unverified')
    ),
    'verification_status', 'user_provided_unverified',
    'rules', JSON_ARRAY(
      '知识用于解释指标、组织诊断、设计补数要求和建议卡结构，不替代当前门店真实经营数据。',
      '只有 OTA 数据时只能输出 OTA 渠道经营判断，不得扩大为全酒店经营事实。',
      '案例数值只在显式 case_key 下返回，不作为其他门店默认目标、阈值或当前事实。',
      '经验规则必须标记适用门店、周期、前置条件和复盘结果。',
      '知识内容只产生人工复核输入，不直接触发 OTA 改价、关房、开房或库存写回。'
    )
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '收入变化诊断',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'derived_metric_method',
    'source_refs', JSON_ARRAY('moke_2026_h2_plan', 'moke_teaching_transcript'),
    'formula', JSON_OBJECT(
      'volume_effect', '(current_room_nights - comparison_room_nights) * comparison_adr',
      'price_effect', 'current_room_nights * (current_adr - comparison_adr)',
      'reconciliation', 'revenue_change approximately equals volume_effect plus price_effect'
    ),
    'required_inputs', JSON_ARRAY('current_revenue', 'comparison_revenue', 'current_room_nights', 'comparison_room_nights', 'current_adr', 'comparison_adr', 'same_scope', 'same_date_basis'),
    'interpretation', JSON_ARRAY(
      '桥接用于判断收入变化更偏向销量还是价格结构，不是因果识别。',
      '展示值经过四舍五入时保留尾差说明。',
      '要继续判断渠道或房型原因，必须补充渠道 ADR、房型结构、佣金后净收入和取消修正。'
    ),
    'blocked_when', JSON_ARRAY('scope_mismatch', 'date_basis_mismatch', 'room_nights_missing', 'adr_missing')
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '渠道收益诊断',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'distilled_method_from_user_material',
    'source_refs', JSON_ARRAY('moke_2026_h2_plan', 'moke_100_points', 'moke_teaching_transcript'),
    'required_inputs', JSON_ARRAY('room_nights', 'room_revenue', 'gross_adr', 'commission', 'net_adr', 'cancellation_rate', 'net_pickup', 'booking_window', 'length_of_stay', 'room_type_mix'),
    'rules', JSON_ARRAY(
      '渠道占比只回答量从哪里来，不能独立判断渠道效率。',
      '渠道结构变化与 ADR 变化同时发生时只能列为归因假设。',
      '没有净 ADR、佣金、取消、提前期和房型证据时不得给渠道定责。',
      '旺日低价库存占用与低峰补量必须分日期观察。',
      '渠道角色应由当前门店数据校准，不把携程价格锚点或美团临期补量写成跨店固定事实。'
    ),
    'output_contract', JSON_ARRAY('facts', 'derived_channel_shares', 'hypotheses', 'missing_evidence', 'candidate_actions', 'guardrails')
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '房型角色方法',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'distilled_method_from_user_material',
    'source_refs', JSON_ARRAY('moke_2026_h2_plan', 'moke_100_points', 'moke_teaching_transcript'),
    'roles', JSON_ARRAY(
      JSON_OBJECT('role', '引流房', 'signals', JSON_ARRAY('需求广', '价格敏感', '承担入口流量'), 'task', '低峰限量补量', 'guardrail', '旺日不得无边界外溢低价'),
      JSON_OBJECT('role', '收益发动机', 'signals', JSON_ARRAY('间夜贡献高', '收入贡献高', '需求稳定'), 'task', '保持转化并修复价格', 'guardrail', '观察提价后的转化和取消'),
      JSON_OBJECT('role', '升级承接房', 'signals', JSON_ARRAY('与基础房差异清晰', '具备额外支付理由'), 'task', '设计价差与升级链路', 'guardrail', '不能只有名称差异'),
      JSON_OBJECT('role', '价格保护房', 'signals', JSON_ARRAY('高需求日仍能维持较高 ADR', '库存相对稀缺'), 'task', '守价并保护库存', 'guardrail', '不用低价房销量逻辑考核'),
      JSON_OBJECT('role', '场景溢价房', 'signals', JSON_ARRAY('影音', '家庭', '茶室', '麻将或其他明确场景'), 'task', '用场景、权益和时段组合溢价', 'guardrail', '复核权益成本和机会成本')
    ),
    'object_boundary', 'room_type, rate_plan, package and channel_inventory must remain separate execution objects'
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '周期目标校验',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'distilled_method_with_validation_boundary',
    'source_refs', JSON_ARRAY('moke_2026_h2_plan', 'moke_teaching_transcript'),
    'rules', JSON_ARRAY(
      'RevPAR 可作为周期判断主指标，ADR 与出租率作为辅助指标。',
      '历史均值上下浮动固定比例只能作为启发式规则，不能替代月份同期、星期结构、节假日、事件和 Pickup。',
      '月度目标应区分保底、预算、冲刺，并能回算到房量、ADR、出租率和收入。',
      '渠道占比区间必须联合校验，最终分配合计为100%，不能分别机械兑现各渠道上下限。',
      '特殊时期极端值保留事件标签，可从常态基线剥离，但不得删除原始事实。'
    ),
    'required_checks', JSON_ARRAY('available_room_count', 'days_in_period', 'revenue_reconciliation', 'channel_share_sum', 'weekday_weekend_split', 'holiday_event_tag')
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  'OTB与Pickup规则',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'experimental_rule_template',
    'source_refs', JSON_ARRAY('moke_2026_h2_plan', 'moke_100_points', 'moke_teaching_transcript'),
    'observation_windows', JSON_ARRAY(7, 14, 30),
    'required_inputs', JSON_ARRAY('stay_date', 'snapshot_date', 'otb_room_nights', 'historical_pickup_curve', 'remaining_inventory', 'current_price', 'competitor_price', 'cancellation_adjustment'),
    'rule_template', JSON_OBJECT(
      'trigger', 'window plus OTB plus pickup percentile plus remaining inventory',
      'candidate_actions', JSON_ARRAY('close_lowest_rate_plan', 'reduce_low_price_inventory', 'step_up_price', 'hold_price', 'reopen_inventory'),
      'guardrails', JSON_ARRAY('min_price', 'inventory_floor', 'conversion_drop_limit', 'cancellation_risk', 'manual_review'),
      'review_metrics', JSON_ARRAY('net_revpar', 'adr', 'conversion_rate', 'cancellation_rate', 'net_pickup', 'remaining_inventory')
    ),
    'rules', JSON_ARRAY(
      '没有历史 Pickup 曲线时阈值标记为 experimental_rule。',
      '达到阈值只生成待人工复核动作，不自动执行。',
      '优先区分价格计划和低价库存，再判断是否调整房型基础价。',
      '每次动作必须记录执行前后证据、停止条件和复盘时间。'
    )
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '建议卡契约',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'decision_support_contract',
    'source_refs', JSON_ARRAY('moke_100_points', 'moke_teaching_transcript'),
    'required_fields', JSON_ARRAY(
      'scope',
      'business_date',
      'date_basis',
      'facts',
      'derived_metrics',
      'hypotheses',
      'missing_evidence',
      'target_object',
      'trigger',
      'action',
      'guardrails',
      'owner',
      'review_at',
      'expected_metric'
    ),
    'target_object_types', JSON_ARRAY('channel', 'room_type', 'rate_plan', 'package', 'channel_inventory'),
    'readiness_rule', 'facts and prerequisites missing means return data gaps only; do not generate executable advice',
    'execution_boundary', 'manual_review_then_operation_execution_intent_then_effect_review'
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '证据缺口',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'source_declared_data_gap',
    'source_refs', JSON_ARRAY('moke_teaching_transcript'),
    'missing_inputs', JSON_ARRAY(
      JSON_OBJECT('code', 'channel_net_yield_missing', 'fields', JSON_ARRAY('commission', 'net_adr', 'net_revenue', 'cancellation_rate', 'booking_window', 'length_of_stay')),
      JSON_OBJECT('code', 'pickup_curve_missing', 'fields', JSON_ARRAY('stay_date', 'snapshot_date', 'otb_room_nights', 'net_pickup')),
      JSON_OBJECT('code', 'whole_hotel_scope_missing', 'fields', JSON_ARRAY('available_room_nights', 'pms_room_nights', 'direct_and_walk_in_revenue')),
      JSON_OBJECT('code', 'competitor_demand_missing', 'fields', JSON_ARRAY('competitor_price', 'market_demand', 'event_calendar')),
      JSON_OBJECT('code', 'room_mapping_missing', 'fields', JSON_ARRAY('room_type_mapping', 'active_status', 'merged_status', 'rate_plan_mapping')),
      JSON_OBJECT('code', 'profit_scope_missing', 'fields', JSON_ARRAY('commission', 'variable_cost', 'rights_cost', 'gop_or_contribution_margin'))
    ),
    'rule', 'missing inputs remain visible and block unsupported conclusions; never replace them with zero, defaults or old data'
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

-- This case chunk is deliberately inserted after generic methods. The dedicated service excludes it by default.
INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '墨客悦享案例',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'moke_yuexiang_2026_h2',
    'evidence_level', 'user_provided_unverified_case',
    'source_refs', JSON_ARRAY('moke_2026_h2_plan', 'moke_100_points', 'moke_teaching_transcript'),
    'hotel_name', '墨客悦享',
    'case_period', '2025H1_vs_2026H1_and_2026H2_plan',
    'facts', JSON_OBJECT(
      'revenue_2025_h1', 1215301,
      'revenue_2026_h1', 1099607,
      'room_nights_2025_h1', 8019,
      'room_nights_2026_h1', 7839,
      'adr_2025_h1', 151.55,
      'adr_2026_h1', 140.27,
      'display_occupancy_2025_h1', 96.7,
      'display_occupancy_2026_h1', 94.3,
      'ctrip_room_nights_2025_h1', 4752,
      'ctrip_room_nights_2026_h1', 4139,
      'meituan_room_nights_2025_h1', 2620,
      'meituan_room_nights_2026_h1', 3113,
      'special_walk_in_adr_2026_02', 700.68,
      'h2_revenue_target_low', 1234200,
      'h2_revenue_target_high', 1374700
    ),
    'derived_metrics', JSON_OBJECT(
      'revenue_delta', -115694,
      'volume_effect_approx', -27279,
      'price_effect_approx', -88424,
      'price_effect_share_percent_approx', 76.4,
      'volume_effect_share_percent_approx', 23.6,
      'ctrip_share_2025_h1_percent', 59.26,
      'ctrip_share_2026_h1_percent', 52.80,
      'ctrip_share_change_pp', -6.46,
      'meituan_share_2025_h1_percent', 32.67,
      'meituan_share_2026_h1_percent', 39.71,
      'meituan_share_change_pp', 7.04
    ),
    'quality_notes', JSON_ARRAY(
      '展示出租率96.7%降至94.3%对应下降2.4个百分点，原材料写2.3，需底层精度复核。',
      '收入桥接为结构解释，不是渠道或价格的因果识别。',
      '2026年2月线下ADR 700.68元需订单数、收入、入住日期和特殊事件证据验证。',
      '渠道变化与ADR下降同时发生，但缺少渠道净ADR、佣金、取消、提前期和房型结构，不能定责。'
    ),
    'allowed_use', JSON_ARRAY('teaching', 'calculation_example', 'rule_design', 'advice_contract_test'),
    'blocked_use', JSON_ARRAY('current_hotel_fact', 'cross_hotel_target', 'automatic_pricing', 'automatic_inventory_write')
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @revops_unit_id,
  '禁止事项',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'decision_guardrail',
    'source_refs', JSON_ARRAY('moke_2026_h2_plan', 'moke_100_points', 'moke_teaching_transcript'),
    'blocked_claims', JSON_ARRAY(
      '不得把案例数字写成当前门店事实或跨店目标。',
      '不得把 OTA 数据写成全酒店出租率、ADR、RevPAR或利润事实。',
      '不得把渠道结构变化直接写成收益变化的确定原因。',
      '不得把经验阈值写成已验证预测模型。',
      '不得删除特殊时期原始事实或把极端值无条件并入常态基线。',
      '不得混淆房型、价格计划、套餐和渠道库存。',
      '不得在缺少日期、对象、触发条件、保护条件和复盘指标时输出可执行建议。',
      '不得让知识内容直接触发 OTA 改价、关房、开房或库存写回。'
    )
  ),
  0,
  NOW()
WHERE @revops_unit_id IS NOT NULL;

SET @revops_category_name := '收益管理与经营解读';
SET @revops_category_description := '酒店收益、房型、渠道、经营诊断、建议结构和复盘方法。';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @revops_category_name,
  @revops_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @revops_category_name
);

SET @revops_category_id := (
  SELECT `id` FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @revops_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @revops_staff_content := CONCAT(
  '# 收益运营诊断与建议知识底座', '\n\n',
  '## 用途', '\n',
  '用于收入变化桥接、渠道收益判断、房型角色、周期目标、OTB/Pickup规则和建议卡结构。只解释方法、边界和补数要求，不替代当前门店真实数据。', '\n\n',
  '## 核心方法', '\n',
  '1. 先统一门店、日期、范围和指标口径，再拆销量影响与价格影响。', '\n',
  '2. 渠道同时看间夜、收入、净ADR、佣金、取消、提前期和房型结构。', '\n',
  '3. 房型先分引流、收益发动机、升级承接、价格保护和场景溢价角色。', '\n',
  '4. OTB阈值没有历史Pickup曲线时只作为经验规则，并进入人工复核。', '\n',
  '5. 建议必须包含事实、推导、假设、缺口、对象、触发、保护、责任人和复盘指标。', '\n\n',
  '## 边界', '\n',
  '墨客悦享数值属于显式案例参考，不进入默认知识上下文；不得跨店套用，不得直接触发OTA写入。'
);

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@revops_category_id, 0),
  @revops_unit_name,
  @revops_staff_content,
  '收益运营,经营诊断,收入桥接,渠道收益,房型角色,OTB,Pickup,建议卡,数据边界,人工复核,OTA',
  JSON_ARRAY('收益运营', '经营诊断', '结构化知识', 'manual_review_only'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @revops_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@revops_category_id, `category_id`),
  `content` = @revops_staff_content,
  `keywords` = '收益运营,经营诊断,收入桥接,渠道收益,房型角色,OTB,Pickup,建议卡,数据边界,人工复核,OTA',
  `tags` = JSON_ARRAY('收益运营', '经营诊断', '结构化知识', 'manual_review_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @revops_unit_name;
