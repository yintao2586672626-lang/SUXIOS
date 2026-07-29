-- Seed the traffic-operations and operation-management knowledge distilled from
-- three user-provided images on 2026-07-29.
--
-- The reusable method is available to normal knowledge retrieval. All named
-- hotels, ranks, percentages, amounts, R-levels and thresholds remain explicit
-- case_reference content and require a matching case_key.
--
-- Safe rerun contract:
-- - preserve operator-authored chunks;
-- - preserve older seed versions for traceability;
-- - update only the exact current seed owner + key + version rows.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @traffic_ops_version := '2026-07-29.1';
SET @traffic_ops_seed_owner := 'suxios.traffic_operation_management_golden_sentences';
SET @traffic_ops_unit_name := '流量经营与运营管理决策金句库';
SET @traffic_ops_source := 'revenue_operations_decision_support';
SET @traffic_ops_description := '从用户提供的流量经营金句与运营管理金句截图中提炼的决策知识：覆盖曝光到利润的漏斗、指标语义、价格实验、房型角色、保护线、止损、回滚和复盘。来源中的酒店、排名、指数、金额和R级门槛仅作显式案例，不替代当前门店真实数据。';

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`,
  `created_by`, `created_at`, `updated_at`
)
SELECT
  0,
  @traffic_ops_unit_name,
  @traffic_ops_source,
  'done',
  @traffic_ops_description,
  JSON_ARRAY(
    '流量经营',
    '漏斗诊断',
    '价值兑现',
    '收益管理',
    '价格实验',
    '房型角色',
    '保护线',
    '止损',
    '回滚',
    '运营复盘',
    'structured_knowledge',
    'user_provided_image',
    'manual_review_only'
  ),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units`
  WHERE `name` = @traffic_ops_unit_name
    AND `source` = @traffic_ops_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @traffic_ops_description,
  `tags` = JSON_ARRAY(
    '流量经营',
    '漏斗诊断',
    '价值兑现',
    '收益管理',
    '价格实验',
    '房型角色',
    '保护线',
    '止损',
    '回滚',
    '运营复盘',
    'structured_knowledge',
    'user_provided_image',
    'manual_review_only'
  ),
  `updated_at` = NOW()
WHERE `name` = @traffic_ops_unit_name
  AND `source` = @traffic_ops_source;

SET @traffic_ops_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @traffic_ops_unit_name
    AND `source` = @traffic_ops_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_traffic_ops_seed_chunks`;
CREATE TEMPORARY TABLE `tmp_traffic_ops_seed_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_traffic_ops_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'user_provided_images_visually_transcribed',
    'source_refs', JSON_ARRAY(
      'jiuyide_traffic_golden_sentences_image',
      'jiuyide_operation_management_appendix_e_page_1',
      'jiuyide_operation_management_appendix_e_page_2'
    ),
    'provided_at', '2026-07-29',
    'source_manifest', JSON_OBJECT(
      'jiuyide_traffic_golden_sentences_image', JSON_OBJECT(
        'visible_title', '流量经营金句',
        'file_name', 'codex-clipboard-cbbe1770-f75b-4dec-8624-3fe2ae845d9c.png',
        'sha256', '62B98AE72207605E5C0C3CC1995BE9CD7D67FE17B41F9B67708EAE60B9B35E81',
        'visible_watermark', '@九逸得',
        'visible_record_count', 25
      ),
      'jiuyide_operation_management_appendix_e_page_1', JSON_OBJECT(
        'visible_title', '附录 E｜运营管理金句库（1/2）',
        'file_name', 'codex-clipboard-2c736d06-9bc6-4482-a6e9-829ad9e873d2.png',
        'sha256', 'B44F408065E7612033ABCA5D7EA362A96B11A76A8F1F61ED9E2175FD45591725',
        'visible_watermark', '@九逸得',
        'visible_record_count', 25
      ),
      'jiuyide_operation_management_appendix_e_page_2', JSON_OBJECT(
        'visible_title', '附录 E｜运营管理金句库（2/2）',
        'file_name', 'codex-clipboard-64c801bf-2aa4-4a4f-9053-631dde4dee56.png',
        'sha256', 'B93F9711E6B5D506389EAD135EC14B5CF982B8BCF71820A0785DA79506A7D5B7',
        'visible_watermark', '@九逸得',
        'visible_record_count', 25
      )
    ),
    'verified_visible', JSON_ARRAY(
      '75条文字',
      '每条场景标签',
      '页面标题',
      '可见水印'
    ),
    'unknown', JSON_ARRAY(
      '原始报告日期与版本',
      '作者身份',
      '底层酒店与平台绑定',
      '指标公式与样本期',
      'R2、R8、R9定义',
      '动作实际执行与效果'
    ),
    'rules', JSON_ARRAY(
      '具体酒店、排名、指数、金额、比例、阈值和R级只作为case_reference保存。',
      '通用方法可用于组织当前门店诊断，但结论必须来自当前门店真实数据。',
      '知识内容不直接触发OTA改价、库存、关房、开房或投流写入。',
      '示例数值不可当通用阈值。'
    )
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'traffic_funnel_contract',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'distilled_method_from_user_images',
    'source_refs', JSON_ARRAY(
      'jiuyide_traffic_golden_sentences_image',
      'jiuyide_operation_management_appendix_e_page_2'
    ),
    'funnel', JSON_ARRAY(
      'exposure',
      'detail_view',
      'order',
      'sold_or_stayed_room_nights',
      'room_revenue',
      'net_revenue',
      'contribution_margin'
    ),
    'required_dimensions', JSON_ARRAY(
      'tenant_id',
      'system_hotel_id',
      'platform',
      'platform_hotel_or_binding',
      'business_date',
      'demand_date_type',
      'metric_definition',
      'source_method',
      'captured_at',
      'quality_status'
    ),
    'rules', JSON_ARRAY(
      '曝光、浏览和订单是过程指标，房费收入、净收入和贡献利润是结果指标。',
      '高曝光或高浏览不能自动证明高收益，必须继续检查支付、销量、ADR、佣金、取消和房型结构。',
      '浏览较强但订单或收入偏弱时，优先检查房型、权益、政策、价格和支付承接。',
      '自然流量、活动流量和付费流量分账复盘。',
      '每个新增入口记录曝光、订单、房费收入、有效订单成本和边际贡献。',
      '投流按高意向日期、剩余库存和目标价格定向配置，不平均撒向整月。',
      '同需求日复核，用于降低星期、节假日和活动干扰，但不自动证明因果。'
    ),
    'output_contract', JSON_ARRAY(
      'facts',
      'derived_metrics',
      'funnel_leak',
      'hypotheses',
      'missing_evidence',
      'candidate_actions',
      'guardrails',
      'review_at'
    )
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'metric_semantic_boundaries',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'decision_guardrail_distilled_from_user_images',
    'source_refs', JSON_ARRAY(
      'jiuyide_traffic_golden_sentences_image',
      'jiuyide_operation_management_appendix_e_page_2'
    ),
    'rules', JSON_ARRAY(
      '排名说明相对位置，份额说明市场占比，指数说明来源系统中的相对表现，三者不能互换。',
      '官方名次、展示表格行号和销售额名次必须分开。',
      '平台转化率与自行粗算转化率必须保留各自分母、时间窗和去重规则。',
      '销售、入住、取消和未来确定收入是不同事实；窗口差额只能标为待验证信号。',
      '单周期数据不能写趋势、同比或真实价格弹性。',
      '集中度判断必须保存HHI定义、样本、市场边界和周期。',
      '曝光增加不等于排名改善，更不等于贡献利润提升。',
      '订单增长不伴随ADR、净收入或贡献改善时，不能直接标记为有效增长。'
    ),
    'blocked_claims', JSON_ARRAY(
      'unknown_platform_index_formula',
      'rank_as_market_share',
      'window_gap_as_cancellation_rate',
      'single_period_as_trend',
      'gross_room_revenue_as_profit',
      'channel_fact_as_whole_hotel_fact'
    )
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'price_experiment_room_roles',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'experimental_rule_template_distilled_from_user_images',
    'source_refs', JSON_ARRAY(
      'jiuyide_operation_management_appendix_e_page_1',
      'jiuyide_operation_management_appendix_e_page_2'
    ),
    'experiment_contract', JSON_OBJECT(
      'one_change_rule', 'one_business_date_one_room_type_one_variable',
      'comparison', 'same_hotel_same_room_type_comparable_demand_date',
      'required_inputs', JSON_ARRAY(
        'baseline_price',
        'baseline_room_nights',
        'baseline_room_revenue',
        'remaining_inventory',
        'pickup',
        'commission',
        'variable_cost_per_room_night',
        'rights_cost',
        'cancellation_rate'
      ),
      'required_guardrails', JSON_ARRAY(
        'minimum_adr',
        'revenue_or_net_revenue_floor',
        'absolute_conversion_floor',
        'inventory_floor',
        'stop_loss',
        'rollback'
      )
    ),
    'room_roles', JSON_ARRAY(
      JSON_OBJECT('role', '引流房', 'task', '限量承担入口', 'guardrail', '不能让最低价覆盖全部库存'),
      JSON_OBJECT('role', '主销房', 'task', '承担规模', 'guardrail', '同时校验收益质量'),
      JSON_OBJECT('role', '升级房', 'task', '承担价值', 'guardrail', '价差必须有面积、景观、设施或权益支撑'),
      JSON_OBJECT('role', '套餐房', 'task', '承担客群', 'guardrail', '权益成本和退改条件可核验')
    ),
    'rules', JSON_ARRAY(
      '提价和降价都必须先计算可承受销量变化与收益保护线。',
      '没有每间夜变动成本、佣金和权益成本，不能声称利润改善。',
      '高峰日期按剩余库存和Pickup调整，避免低价提前售罄。',
      '房型、价格计划、套餐和渠道库存不得混写。',
      '来源中的固定价格步长、百分比保护线和R级门槛只作案例，不跨店采用。'
    )
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'management_action_contract',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'decision_support_contract_distilled_from_user_images',
    'source_refs', JSON_ARRAY(
      'jiuyide_traffic_golden_sentences_image',
      'jiuyide_operation_management_appendix_e_page_1',
      'jiuyide_operation_management_appendix_e_page_2'
    ),
    'decision_conclusion', '管理语言不能替代数据判断；所有行动仍需基线、目标、保护线、止损和回滚。',
    'required_fields', JSON_ARRAY(
      'scope',
      'business_date',
      'demand_date_type',
      'baseline',
      'target',
      'gap',
      'facts',
      'derived_metrics',
      'hypotheses',
      'missing_evidence',
      'target_object',
      'action',
      'guardrails',
      'stop_loss',
      'rollback',
      'owner',
      'review_at',
      'expected_metric'
    ),
    'readiness_rules', JSON_ARRAY(
      '数据质量先于经营判断，口径不清时先补数。',
      '保护线必须引用当前门店数据、指标定义、周期和来源。',
      '没有止损条件的行动计划不得进入可执行状态。',
      '目标必须与可用资源、库存、周期和可验证输入一致。',
      '建议不等于执行，执行不等于有效，效果必须单独复盘。'
    ),
    'external_action_boundary', 'manual_review_only_no_automatic_ota_or_paid_traffic_write'
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'review_and_governance',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'operating_review_template_distilled_from_user_images',
    'source_refs', JSON_ARRAY(
      'jiuyide_traffic_golden_sentences_image',
      'jiuyide_operation_management_appendix_e_page_2'
    ),
    'meeting_scenes', JSON_ARRAY(
      '晨会',
      '周复盘',
      '投流决策',
      '页面优化',
      '店总会',
      '收益会',
      '业主会',
      '团队复盘'
    ),
    'weekly_review_output', JSON_ARRAY('保留', '优化', '停止', '扩大'),
    'rules', JSON_ARRAY(
      '团队评价看同需求日完整漏斗，不以单日排名奖惩。',
      '复盘同时展示流量、转化、房费收入、净收入、贡献利润和数据缺口。',
      '竞品学习聚焦支付承接、规模复制和价值形成，不复制全部动作。',
      '业主价值以可持续收益和利润衡量，不以订单数最大化衡量。',
      '低价走量只有在规模与收益同时成立时才构成优势。',
      '复盘周期必须按当前门店样本成熟度配置，来源中的30/60/90天仅作案例。'
    )
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

-- Exact source transcription. It is excluded from default knowledge prompts and
-- returned only when the caller supplies the matching case_key.
INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'traffic_source_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'requires_explicit_case_key', true,
    'case_key', 'jiuyide_traffic_flow_funnel_2026_07_29',
    'evidence_level', 'user_provided_image_unverified_case',
    'source_refs', JSON_ARRAY('jiuyide_traffic_golden_sentences_image'),
    'allowed_use', JSON_ARRAY('source_traceability', 'teaching', 'method_design', 'case_review'),
    'blocked_use', JSON_ARRAY(
      'current_hotel_fact',
      'cross_hotel_threshold',
      'automatic_pricing',
      'automatic_inventory_write',
      'automatic_paid_traffic_write'
    ),
    'records', JSON_ARRAY(
      JSON_OBJECT('seq', 1, 'text', '曝光指数 104，当前不是缺流量，而是缺收益兑现。', 'scene', '晨会'),
      JSON_OBJECT('seq', 2, 'text', '浏览份额 5.69%，说明详情入口具备承接基础。', 'scene', '周复盘'),
      JSON_OBJECT('seq', 3, 'text', '订单份额回落至 5.02%，浏览到订单仍有轻微泄漏。', 'scene', '投流决策'),
      JSON_OBJECT('seq', 4, 'text', '销量份额 5.28%，证明低价走量已经形成规模。', 'scene', '页面优化'),
      JSON_OBJECT('seq', 5, 'text', '收益份额 4.54%，流量规模尚未转成等量收入。', 'scene', '晨会'),
      JSON_OBJECT('seq', 6, 'text', '流量增长必须服从收益指数回到 100 以上。', 'scene', '周复盘'),
      JSON_OBJECT('seq', 7, 'text', '支付效率 90.7，是当前流量链路的次要短板。', 'scene', '投流决策'),
      JSON_OBJECT('seq', 8, 'text', '浏览效率 110.7，首要问题并非用户不愿进入详情。', 'scene', '页面优化'),
      JSON_OBJECT('seq', 9, 'text', '绝对转化指数 99.3，整体成交效率接近商圈基准。', 'scene', '晨会'),
      JSON_OBJECT('seq', 10, 'text', '曝光变现指数 91.7，新增曝光要先过价值闸门。', 'scene', '周复盘'),
      JSON_OBJECT('seq', 11, 'text', '高浏览不等于高收益，绿树电竞就是边界反例。', 'scene', '投流决策'),
      JSON_OBJECT('seq', 12, 'text', '少流量高转化的蔓悦轻居，值得研究支付确定性。', 'scene', '页面优化'),
      JSON_OBJECT('seq', 13, 'text', '同价的凯斯云璟，用更少曝光取得近似销售额。', 'scene', '晨会'),
      JSON_OBJECT('seq', 14, 'text', '流量不是越多越好，能够承接目标价格才有价值。', 'scene', '周复盘'),
      JSON_OBJECT('seq', 15, 'text', '平台运营重心是提高转化，报告更强调价值兑现。', 'scene', '投流决策'),
      JSON_OBJECT('seq', 16, 'text', '本店浏览排名第 5，销售额却第 9，价值密度要补。', 'scene', '页面优化'),
      JSON_OBJECT('seq', 17, 'text', '订单增长若不带来 ADR 和收益改善，就不是有效增长。', 'scene', '晨会'),
      JSON_OBJECT('seq', 18, 'text', '曝光份额高于等份基准时，不应把扩流列为 P0。', 'scene', '周复盘'),
      JSON_OBJECT('seq', 19, 'text', '自然流量、活动流量和付费流量必须分账复盘。', 'scene', '投流决策'),
      JSON_OBJECT('seq', 20, 'text', '每个新增入口都要记录曝光、订单和销售额贡献。', 'scene', '页面优化'),
      JSON_OBJECT('seq', 21, 'text', '支付转化低于平台均值 1.49 个百分点，需要小步修复。', 'scene', '晨会'),
      JSON_OBJECT('seq', 22, 'text', '详情页进入较强，房型、权益和政策决定最后一跳。', 'scene', '周复盘'),
      JSON_OBJECT('seq', 23, 'text', '曝光增加不等于排名改善，更不等于贡献利润提升。', 'scene', '投流决策'),
      JSON_OBJECT('seq', 24, 'text', '流量预算应投向高意向日期，而不是平均撒向全月。', 'scene', '页面优化'),
      JSON_OBJECT('seq', 25, 'text', '同需求日复核，才能排除星期与活动干扰。', 'scene', '晨会')
    )
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'operation_source_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'requires_explicit_case_key', true,
    'case_key', 'jiuyide_operation_management_2026_07_29',
    'evidence_level', 'user_provided_image_unverified_case',
    'source_refs', JSON_ARRAY(
      'jiuyide_operation_management_appendix_e_page_1',
      'jiuyide_operation_management_appendix_e_page_2'
    ),
    'allowed_use', JSON_ARRAY('source_traceability', 'teaching', 'method_design', 'case_review'),
    'blocked_use', JSON_ARRAY(
      'current_hotel_fact',
      'cross_hotel_threshold',
      'automatic_pricing',
      'automatic_inventory_write',
      'automatic_paid_traffic_write'
    ),
    'records', JSON_ARRAY(
      JSON_OBJECT('seq', 1, 'text', 'R8 低价走量的升级，不是再降价，而是补收益。', 'scene', '店总会'),
      JSON_OBJECT('seq', 2, 'text', '需求指数 110.9，证明销量基础已经超过等份基准。', 'scene', '收益会'),
      JSON_OBJECT('seq', 3, 'text', '收益指数 95.4，进入 R9 只差约 5454 元销售额。', 'scene', '业主会'),
      JSON_OBJECT('seq', 4, 'text', '当前间夜不变时，ADR 提升到 107.46 元即可触及 R9。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 5, 'text', '当前 ADR 不变时，销售间夜约 1155 即可触及 R9。', 'scene', '店总会'),
      JSON_OBJECT('seq', 6, 'text', 'R9 是 30 天最近门槛，R2 是 90 天条件目标。', 'scene', '收益会'),
      JSON_OBJECT('seq', 7, 'text', '低价格只有和规模、收益同时成立，才构成优势。', 'scene', '业主会'),
      JSON_OBJECT('seq', 8, 'text', '销售 ADR 第 12，不应继续把低价当成唯一卖点。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 9, 'text', '销售间夜第 6，说明规模并不是最薄弱的一环。', 'scene', '店总会'),
      JSON_OBJECT('seq', 10, 'text', '销售额第 9，规模与收益排名出现明显错位。', 'scene', '收益会'),
      JSON_OBJECT('seq', 11, 'text', '价格测试从+8 元开始，比全面降价更符合当前角色。', 'scene', '业主会'),
      JSON_OBJECT('seq', 12, 'text', '提价 8 元可承受间夜下降 7.21%，先做单房型实验。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 13, 'text', '提价 10 元可承受间夜下降 8.85%，但需同需求日复核。', 'scene', '店总会'),
      JSON_OBJECT('seq', 14, 'text', '降价 8 元需间夜增长 8.42%，未达即应止损。', 'scene', '收益会'),
      JSON_OBJECT('seq', 15, 'text', '降价 10 元需间夜增长 10.75%，不能只看订单增加。', 'scene', '业主会'),
      JSON_OBJECT('seq', 16, 'text', '价格实验一次一个日期、一个房型、一个变量。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 17, 'text', '没有每间夜变动成本，就不能声称利润改善。', 'scene', '店总会'),
      JSON_OBJECT('seq', 18, 'text', '房型价差要体现面积、景观、设施和权益差异。', 'scene', '收益会'),
      JSON_OBJECT('seq', 19, 'text', '引流房只能限量，不能让最低价覆盖全部库存。', 'scene', '业主会'),
      JSON_OBJECT('seq', 20, 'text', '主销房承担规模，升级房承担价值，套餐房承担客群。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 21, 'text', '高峰日期要按剩余库存提价，不要低价提前售罄。', 'scene', '店总会'),
      JSON_OBJECT('seq', 22, 'text', '销售与入住差 201 间夜，只能视为窗口待验证信号。', 'scene', '收益会'),
      JSON_OBJECT('seq', 23, 'text', '窗口差额不能被命名为取消率或未来确定收入。', 'scene', '业主会'),
      JSON_OBJECT('seq', 24, 'text', '收益份额保护线 4.36%，低于即暂停扩量。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 25, 'text', '销售 ADR 保护线约 97.85 元，低于即复盘价盘。', 'scene', '店总会'),
      JSON_OBJECT('seq', 26, 'text', '绝对转化保护线 1.31%，不能用价格换断崖式成交。', 'scene', '店总会'),
      JSON_OBJECT('seq', 27, 'text', '曝光变现保护线 84.4，低于即停止低质入口。', 'scene', '收益会'),
      JSON_OBJECT('seq', 28, 'text', '数据质量先于经营判断，口径不清时先补数。', 'scene', '业主会'),
      JSON_OBJECT('seq', 29, 'text', '官方第 5 名、表格第 7 行、销售额第 9 名必须分开。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 30, 'text', '排名说明位置，份额说明市场占比，两者不能互换。', 'scene', '店总会'),
      JSON_OBJECT('seq', 31, 'text', '平台转化与粗算转化必须保留各自分母。', 'scene', '收益会'),
      JSON_OBJECT('seq', 32, 'text', '单周期数据不能写趋势、同比或真实价格弹性。', 'scene', '业主会'),
      JSON_OBJECT('seq', 33, 'text', '商圈 HHI 低于 1000，不应把市场描述为寡头。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 34, 'text', '收益集中略高于浏览集中，后端效率比流量更稀缺。', 'scene', '店总会'),
      JSON_OBJECT('seq', 35, 'text', '同价强转化竞品比低价走量竞品更值得学习。', 'scene', '收益会'),
      JSON_OBJECT('seq', 36, 'text', '凯斯云璟的价值在支付承接，不在复制其所有动作。', 'scene', '业主会'),
      JSON_OBJECT('seq', 37, 'text', '未来生活的价值在规模复制，不在继续压低价格。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 38, 'text', '美华证明同等间夜可以由更高价值形成更多收入。', 'scene', '店总会'),
      JSON_OBJECT('seq', 39, 'text', '绿树电竞证明高浏览不能弥补支付环节失效。', 'scene', '收益会'),
      JSON_OBJECT('seq', 40, 'text', '每周复盘必须给出保留、优化、停止和扩大清单。', 'scene', '业主会'),
      JSON_OBJECT('seq', 41, 'text', '不以单日排名奖惩团队，要看同需求日完整漏斗。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 42, 'text', '收益份额、房费收入和贡献利润是北极星。', 'scene', '店总会'),
      JSON_OBJECT('seq', 43, 'text', '曝光、浏览和订单只是过程，不是最终结果。', 'scene', '收益会'),
      JSON_OBJECT('seq', 44, 'text', '投流预算必须绑定有效订单成本和边际贡献。', 'scene', '业主会'),
      JSON_OBJECT('seq', 45, 'text', '30 天先进入 R9，避免设置脱离资源的 TOP1 目标。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 46, 'text', '60 天形成四档价盘，90 天再验证 R2 门槛。', 'scene', '店总会'),
      JSON_OBJECT('seq', 47, 'text', '目标值必须同时写基线、差距、保护线和回滚。', 'scene', '收益会'),
      JSON_OBJECT('seq', 48, 'text', '没有止损条件的行动计划，只是愿望清单。', 'scene', '业主会'),
      JSON_OBJECT('seq', 49, 'text', '业主价值不是订单最多，而是可持续的收益和利润。', 'scene', '团队复盘'),
      JSON_OBJECT('seq', 50, 'text', '低价走量要升级为可盈利走量，而不是永久低价。', 'scene', '店总会')
    )
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

INSERT INTO `tmp_traffic_ops_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @traffic_ops_unit_id,
  'landing_status',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'repository_integration_contract_2026_07_29',
    'source_refs', JSON_ARRAY(
      'docs/traffic_operation_management_golden_sentences_knowledge.md',
      'app/controller/Agent.php',
      'app/service/RevenueOperationsKnowledgeService.php'
    ),
    'knowledge_absorbed', JSON_ARRAY(
      'source_fingerprints',
      '75_source_records',
      'traffic_funnel_contract',
      'metric_semantic_boundaries',
      'price_experiment_and_room_roles',
      'management_action_contract',
      'review_and_governance'
    ),
    'runtime_entry', JSON_ARRAY(
      'knowledge_units',
      'knowledge_chunks',
      'knowledge_base',
      'RevenueOperationsKnowledgeService',
      'OTA knowledge prompt retrieval'
    ),
    'case_protection', JSON_OBJECT(
      'default_retrieval', 'excluded',
      'explicit_case_keys', JSON_ARRAY(
        'jiuyide_traffic_flow_funnel_2026_07_29',
        'jiuyide_operation_management_2026_07_29'
      )
    ),
    'truthful_completion_statement', 'knowledge_integrated_source_case_numbers_not_promoted_to_current_hotel_facts',
    'next_upgrade_trigger', 'new_source_version_user_correction_or_verified_metric_definition'
  ),
  0,
  NOW()
WHERE @traffic_ops_unit_id IS NOT NULL;

UPDATE `tmp_traffic_ops_seed_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.module_id', 'traffic_operation_management_golden_sentences',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'operations'),
  '$.scenes', JSON_ARRAY(
    'morning_meeting',
    'weekly_review',
    'traffic_allocation',
    'page_optimization',
    'store_management_meeting',
    'revenue_meeting',
    'owner_meeting',
    'team_review'
  ),
  '$.platforms', JSON_ARRAY('ota_generic', 'ctrip', 'meituan', 'manual_review'),
  '$.seed_owner', @traffic_ops_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `unit`.`name`, ':', `seed`.`type`),
  '$.seed_version', @traffic_ops_version
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_traffic_ops_seed_chunks` AS `seed`
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
FROM `tmp_traffic_ops_seed_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_traffic_ops_seed_chunks`;

SET @traffic_ops_category_name := '收益管理与经营解读';
SET @traffic_ops_category_description := '酒店流量、转化、收益、价格、房型、经营诊断、行动保护和复盘方法。';

INSERT INTO `knowledge_categories` (
  `tenant_id`, `hotel_id`, `parent_id`, `name`, `description`,
  `sort_order`, `is_enabled`, `create_time`, `update_time`
)
SELECT
  0,
  0,
  0,
  @traffic_ops_category_name,
  @traffic_ops_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_categories`
  WHERE `hotel_id` = 0
    AND `parent_id` = 0
    AND `name` = @traffic_ops_category_name
);

UPDATE `knowledge_categories`
SET
  `tenant_id` = 0,
  `description` = @traffic_ops_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `parent_id` = 0
  AND `name` = @traffic_ops_category_name;

SET @traffic_ops_category_id := (
  SELECT `id`
  FROM `knowledge_categories`
  WHERE `hotel_id` = 0
    AND `parent_id` = 0
    AND `name` = @traffic_ops_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @traffic_ops_staff_content := CONCAT(
  '# 流量经营与运营管理决策金句库', '\n\n',
  '## 核心原则', '\n',
  '管理语言不能替代数据判断；所有行动仍需基线、目标、保护线、止损和回滚。', '\n\n',
  '## 流量漏斗', '\n',
  '曝光、浏览和订单是过程指标，房费收入、净收入和贡献利润是结果指标。高曝光或高浏览不自动等于高收益；浏览较强但订单或收入偏弱时，优先检查房型、权益、政策、价格和支付承接。', '\n\n',
  '## 指标边界', '\n',
  '排名、份额和指数不能互换；平台转化与粗算转化保留各自分母；销售、入住、取消和未来确定收入分开；单周期数据不写趋势、同比或真实价格弹性。', '\n\n',
  '## 定价与房型', '\n',
  '价格实验一次一个日期、一个房型、一个变量，并以同需求日复核。引流房限量，主销房承担规模，升级房承担价值，套餐房承担客群；没有成本证据不声称利润改善。', '\n\n',
  '## 动作与复盘', '\n',
  '每周输出保留、优化、停止和扩大清单。投流绑定有效订单成本和边际贡献；团队评价看同需求日完整漏斗；业主价值以可持续收益和利润衡量。', '\n\n',
  '## 使用边界', '\n',
  '来源截图中的具体酒店、排名、指数、金额、比例、保护线和R级门槛属于显式案例，默认检索不返回，不得跨店套用，也不得直接触发OTA写入。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  0,
  COALESCE(@traffic_ops_category_id, 0),
  @traffic_ops_unit_name,
  @traffic_ops_staff_content,
  '流量经营,曝光,浏览,订单,销量,入住,房费收入,净收入,贡献利润,转化率,曝光变现,投流,有效订单成本,ADR,价格实验,房型角色,保护线,止损,回滚,运营复盘',
  JSON_ARRAY(
    '流量经营',
    '漏斗诊断',
    '收益管理',
    '价格实验',
    '运营复盘',
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
    AND `title` = @traffic_ops_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = COALESCE(@traffic_ops_category_id, `category_id`),
  `content` = @traffic_ops_staff_content,
  `keywords` = '流量经营,曝光,浏览,订单,销量,入住,房费收入,净收入,贡献利润,转化率,曝光变现,投流,有效订单成本,ADR,价格实验,房型角色,保护线,止损,回滚,运营复盘',
  `tags` = JSON_ARRAY(
    '流量经营',
    '漏斗诊断',
    '收益管理',
    '价格实验',
    '运营复盘',
    'manual_review_only'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @traffic_ops_unit_name;
