-- Seed professional hotel OTA metric meanings into the project knowledge systems.
-- This combines existing Suxi OS OTA metric knowledge with external public industry/platform references.
-- Content-only: no crawler, business table, interface, or OTA field is added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @hotel_metric_unit_name := '酒店OTA专业指标口径知识库';
SET @hotel_metric_source := 'ota';
SET @hotel_metric_description := '融合项目既有 OTA 指标、OTA运营思维导图2.0 与联网检索到的酒店行业和平台公开口径，沉淀 ADR、入住率、RevPAR、流量转化、订单库存、平台私有分值和宿析OS计算边界。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @hotel_metric_unit_name,
  @hotel_metric_source,
  'done',
  @hotel_metric_description,
  JSON_ARRAY('OTA', '酒店指标', '专业口径', 'ADR', 'RevPAR', '转化率', '平台评分', '收益管理'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @hotel_metric_unit_name AND `source` = @hotel_metric_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @hotel_metric_description,
  `tags` = JSON_ARRAY('OTA', '酒店指标', '专业口径', 'ADR', 'RevPAR', '转化率', '平台评分', '收益管理'),
  `updated_at` = NOW()
WHERE `name` = @hotel_metric_unit_name AND `source` = @hotel_metric_source;

SET @hotel_metric_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @hotel_metric_unit_name AND `source` = @hotel_metric_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @hotel_metric_unit_id
  AND `type` IN (
    '融合原则',
    '外部参考',
    '指标分层',
    '核心经营指标',
    'OTA流量与转化指标',
    '订单与库存指标',
    '平台私有指标',
    '诊断模板',
    '宿析OS落地规则'
  );

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  '融合原则',
  JSON_OBJECT(
    'title', @hotel_metric_unit_name,
    'combine_rule', JSON_ARRAY(
      '行业通用指标采用酒店行业或平台公开公式优先，例如 ADR、入住率、RevPAR、CTR、转化率。',
      'OTA 平台私有指标保留运营含义，不编造未公开权重，例如携程服务质量分、美团 HOS、飞猪 MCI。',
      '旧资料中写作 MIC 的飞猪指标，与公开资料常见写法 MCI 合并为 fliggy_mci，保留 MIC 作为历史别名。',
      '平台规则、排名、广告位、扣分项、权益和私有评分算法会变化，系统只沉淀解释与诊断口径，执行前必须以当前后台为准。',
      '不因本次知识深化新增业务表，计算字段仍复用 online_daily_data 和 raw_data。'
    ),
    'internal_sources', JSON_ARRAY(
      'database/migrations/20260520_seed_ota_standard_metrics_knowledge.sql',
      'docs/ota_operation_mindmap_knowledge.md',
      'database/migrations/20260526_seed_ota_operation_mindmap_knowledge.sql'
    )
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  '外部参考',
  JSON_OBJECT(
    'references', JSON_ARRAY(
      JSON_OBJECT('name', 'Oracle OPERA Cloud Inventory and Rate Availability', 'url', 'https://docs.oracle.com/en/industries/hospitality/opera-cloud/26.2/ocsuh/t_viewing_inventory_and_rate_availability.htm', 'use_for', 'ADR、入住率、RevPAR、Rooms Sold、Room Available 的酒店 PMS 口径。'),
      JSON_OBJECT('name', 'Google Ads CTR Definition', 'url', 'https://support.google.com/google-ads/answer/2615875', 'use_for', 'CTR = clicks / impressions。'),
      JSON_OBJECT('name', 'Google Ads Conversion Rate Definition', 'url', 'https://support.google.com/google-ads/answer/2684489', 'use_for', 'Conversion rate = conversions / interactions。'),
      JSON_OBJECT('name', 'Google Hotel Center booking link reports', 'url', 'https://support.google.com/hotelprices/answer/10474165', 'use_for', 'Hotel Center 的 impressions、clicks、CTR、price bucket、price difference percent、booking window、length of stay。'),
      JSON_OBJECT('name', 'Booking.com Connectivity Reservations API', 'url', 'https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview', 'use_for', 'reservation 表示一个或多个 room nights，可处理创建、修改、取消。'),
      JSON_OBJECT('name', 'Trip.com eBooking', 'url', 'https://ebooking.trip.com/', 'use_for', '携程酒店后台可管理订单、房态、房价、收益、点评、营销活动和附加产品。'),
      JSON_OBJECT('name', 'Meituan HOS article', 'url', 'https://dxw.sankuai.com/cms/content/article/87p67771p0508l52M9u262NiC786xqXY', 'use_for', 'HOS 含义、满分、月度多项指标和预留房解释。'),
      JSON_OBJECT('name', 'Meituan hotel merchant integrity rules', 'url', 'https://mss.sankuai.com/v1/mss_fbdc6b45e3b7404180cd81898eeab567/CONTRACT_CLOUD/agreement_template_d14dc2dd-4630-4a.pdf', 'use_for', '服务和诚信违规对结算、赔付、资源和 HOS 的影响。'),
      JSON_OBJECT('name', 'Fliggy service center MCI', 'url', 'https://service.alitrip.com/HelpCenterDetail.htm?id=1061086317', 'use_for', '飞猪商家服务 MCI 综合咨询体验、预订体验、售后体验等。'),
      JSON_OBJECT('name', 'Hotel dynamic pricing price elasticity paper', 'url', 'https://arxiv.org/abs/2208.03135', 'use_for', '在线酒店需求、入住率和价格弹性对动态定价的影响。')
    ),
    'source_rule', '外部链接只作为公开口径来源；平台后台实时规则和接口响应仍优先。'
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  '指标分层',
  JSON_OBJECT(
    'layers', JSON_ARRAY(
      JSON_OBJECT('layer', '经营收益', 'metrics', JSON_ARRAY('ADR', '入住率', 'RevPAR', 'TRevPAR', 'GOPPAR', '渠道 RevPAR'), 'question', '酒店是否卖得贵、卖得满、每间可售房是否产出足够收入。'),
      JSON_OBJECT('layer', 'OTA流量', 'metrics', JSON_ARRAY('曝光', '点击', 'CTR', '详情进入率', '搜索流量', '内容流量', '付费流量'), 'question', '用户是否看得到、愿不愿点进来。'),
      JSON_OBJECT('layer', '交易转化', 'metrics', JSON_ARRAY('预订转化率', '支付转化率', '订单量', '间夜量', '取消率', '拒单率'), 'question', '用户是否下单、支付、入住，订单是否稳定履约。'),
      JSON_OBJECT('layer', '价格竞争', 'metrics', JSON_ARRAY('价格差', '价格桶', '价格准确率', '价差率', '价格敏感度'), 'question', '价格是否有竞争力，是否因价格劣势损失流量或转化。'),
      JSON_OBJECT('layer', '库存房态', 'metrics', JSON_ARRAY('可售房', '已售房', '保留房/预留房', '有房率', '满房率', '关房率'), 'question', '是否有库存承接流量，是否因库存导致拒单或低排名。'),
      JSON_OBJECT('layer', '口碑服务', 'metrics', JSON_ARRAY('点评分', '点评量', '差评率', '回复率', '投诉/违规', '服务质量分', 'HOS', 'MCI'), 'question', '服务质量是否影响排序、转化和平台权益。'),
      JSON_OBJECT('layer', '用户行为', 'metrics', JSON_ARRAY('提前预订天数', '入住天数', '客群', '性别', '年龄', '复购率', 'LTV'), 'question', '用户是谁、什么时候订、住多久、长期价值如何。'),
      JSON_OBJECT('layer', '投放活动', 'metrics', JSON_ARRAY('CPC', '消耗', 'ROAS', 'ROI', '活动回报', '归因收入'), 'question', '活动或广告是否带来有效收益。')
    )
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  '核心经营指标',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT('metric', 'ADR', 'meaning', '平均已售房价，衡量售出客房的价格质量。', 'formula', 'room_revenue / rooms_sold', 'suxios_scope', '优先用房费收入和售出间夜；若只有 OTA 支付金额，命名为 ota_adr。', 'not_calculable_when', '缺房费收入或售出间夜为 0。'),
      JSON_OBJECT('metric', '入住率 OCC', 'meaning', '已售房占可售房比例，衡量库存消化。', 'formula', 'occupied_rooms / available_rooms', 'suxios_scope', '只有全店可售房量时叫入住率；只有 OTA 数据时叫 OTA 售卖率。', 'not_calculable_when', '缺可售房量或可售房量为 0。'),
      JSON_OBJECT('metric', 'RevPAR', 'meaning', '每间可售房收入，合并价格和出租效率。', 'formula', 'room_revenue / available_rooms 或 ADR * occupancy', 'suxios_scope', '全店数据叫 RevPAR；渠道局部数据叫渠道 RevPAR。', 'not_calculable_when', '缺可售房量，或 ADR/OCC 任一不可计算。'),
      JSON_OBJECT('metric', 'TRevPAR', 'meaning', '每间可售房总收入，包含房费外收入。', 'formula', 'total_revenue / available_rooms', 'suxios_scope', '适合餐饮、SPA、商城等附加产品完善后启用。', 'not_calculable_when', '缺附加收入或可售房量。'),
      JSON_OBJECT('metric', 'GOPPAR', 'meaning', '每间可售房经营毛利，关注利润而非收入。', 'formula', 'gross_operating_profit / available_rooms', 'suxios_scope', '投资决策、转让决策和门店利润诊断使用。', 'not_calculable_when', '缺成本费用或经营毛利。'),
      JSON_OBJECT('metric', '渠道 RevPAR', 'meaning', '某 OTA 渠道贡献到可售房的收入效率。', 'formula', 'channel_room_revenue / available_rooms', 'suxios_scope', '用于比较携程、美团、飞猪贡献；不能冒充全店 RevPAR。', 'not_calculable_when', '缺渠道收入或全店可售房量。')
    )
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  'OTA流量与转化指标',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT('metric', '曝光', 'meaning', '酒店在搜索、列表、广告或内容入口被展示的次数。', 'formula', 'impression_count', 'suxios_use', '识别看不到的问题；关联排名、标签、广告和库存。'),
      JSON_OBJECT('metric', '点击', 'meaning', '用户点击酒店、房型、广告或预订链接的次数。', 'formula', 'click_count', 'suxios_use', '与曝光一起判断首图、价格和标题吸引力。'),
      JSON_OBJECT('metric', 'CTR', 'meaning', '点击率，衡量曝光后的吸引力。', 'formula', 'clicks / impressions', 'suxios_use', '低 CTR 优先查首图、起价、标签、评分、竞对价。'),
      JSON_OBJECT('metric', '详情进入率', 'meaning', '从列表/搜索进入详情页的比例。', 'formula', 'detail_uv / list_uv 或 detail_clicks / list_impressions', 'suxios_use', '当平台不给 CTR 时作为内部替代口径。'),
      JSON_OBJECT('metric', '预订转化率', 'meaning', '从详情、点击或访问到创建订单的比例。', 'formula', 'created_orders / detail_uv 或 created_orders / clicks', 'suxios_use', '低值查房型、图片、退改、价格、点评和问答。'),
      JSON_OBJECT('metric', '支付转化率', 'meaning', '创建订单后完成支付或有效确认的比例。', 'formula', 'paid_or_confirmed_orders / created_orders', 'suxios_use', '低值查支付方式、库存、价差、确认失败、取消政策。'),
      JSON_OBJECT('metric', 'Look-to-book', 'meaning', '浏览/搜索到最终订单的漏斗效率。', 'formula', 'bookings / searches 或 bookings / page_views', 'suxios_use', '可作为 OTA 全漏斗指标，必须声明分母口径。'),
      JSON_OBJECT('metric', '取消率', 'meaning', '已创建或已确认订单中取消的比例。', 'formula', 'cancelled_orders / created_orders，补充 cancelled_room_nights / booked_room_nights', 'suxios_use', '同时保留订单取消率和间夜取消率，避免低价长住取消被低估。')
    )
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  '订单与库存指标',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT('metric', '订单量', 'meaning', '指定口径下的有效订单数。', 'formula_or_basis', 'count(distinct order_id)，必须标注创建、支付、确认、入住或完成口径。', 'suxios_use', '日报和平台贡献度使用，不同口径不能混算。'),
      JSON_OBJECT('metric', '间夜量', 'meaning', '住宿交易量，核心房量指标。', 'formula_or_basis', 'sum(room_count * nights)', 'suxios_use', '收益、出租率、ADR 和渠道贡献基础。'),
      JSON_OBJECT('metric', '有房率', 'meaning', '平台或房型在可售日期中的有房比例。', 'formula_or_basis', 'available_dates / queried_dates 或 available_room_nights / total_room_nights', 'suxios_use', '判断是否因缺库存损失排名或转化。'),
      JSON_OBJECT('metric', '保留房/预留房消费', 'meaning', '平台保障库存被使用的间夜或订单。', 'formula_or_basis', '平台后台口径优先；系统记录库存、消费和违规。', 'suxios_use', '用于携程服务分、美团 HOS、飞猪 MCI 类诊断。'),
      JSON_OBJECT('metric', '拒单率', 'meaning', '有订单需求但商家拒绝或无法履约的比例。', 'formula_or_basis', 'rejected_orders / created_orders', 'suxios_use', '高风险违规指标，关联平台扣分、赔付和排序。'),
      JSON_OBJECT('metric', '到店无房', 'meaning', '用户到店后无法入住原订单的履约失败。', 'formula_or_basis', '事件型指标，不建议只用比例掩盖严重性。', 'suxios_use', 'P0 级风险，触发赔付、差评、平台处罚。'),
      JSON_OBJECT('metric', '提前预订天数', 'meaning', '预订日到入住日之间的天数。', 'formula_or_basis', 'checkin_date - booking_date', 'suxios_use', '区分当晚急订、提前订、节假日蓄水。'),
      JSON_OBJECT('metric', '入住天数 LOS', 'meaning', '单笔订单住宿晚数。', 'formula_or_basis', 'checkout_date - checkin_date', 'suxios_use', '判断连住活动、长住优惠和库存压力。'),
      JSON_OBJECT('metric', 'Pickup', 'meaning', '某观察窗口新增的未来入住订单或间夜。', 'formula_or_basis', 'current_on_books - previous_on_books', 'suxios_use', '收益管理和节假日价格调整使用。'),
      JSON_OBJECT('metric', 'Booking pace', 'meaning', '未来入住日订单累积速度。', 'formula_or_basis', '按入住日观察 OTB 随时间变化。', 'suxios_use', '判断需求强弱，辅助调价和控房。')
    )
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  '平台私有指标',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT('metric', '携程服务质量分', 'best_explanation', '携程合作运营中的服务与履约质量信号，影响平台权益和流量判断。', 'keep_from_existing', '5 分钟确认、保留房/Freesale、无缺陷订单、确认后满房/涨价/拒单等。', 'suxios_handling', '字段建议 ctrip_service_score；公式和权重不写死，以后台值为准。'),
      JSON_OBJECT('metric', '携程挂牌/排序分', 'best_explanation', '平台合作层级、排序池和综合排序信号。', 'keep_from_existing', '特牌、金牌、银牌、排序分、订单、付费排序、服务质量分。', 'suxios_handling', '字段建议 ctrip_badge_level、ctrip_sort_score；作为诊断维度不反推排名。'),
      JSON_OBJECT('metric', '美团 HOS', 'best_explanation', 'Hotel Operation System 指数，综合评估开通预订门店经营水平，公开资料显示满分 5 分。', 'keep_from_existing', '酒店信息、服务质量、经营产能、违规违约、预留房、点评、订单。', 'suxios_handling', '字段建议 meituan_hos_score；拆解到可操作项：图片、资质、确认、预留房、点评、违规。'),
      JSON_OBJECT('metric', '美团冠级/金币', 'best_explanation', '平台权益和资源位相关信号。', 'keep_from_existing', '彩冠、皇冠、银冠、金币兑换、推广通权益。', 'suxios_handling', '字段建议 meituan_crown_level、meituan_coin_balance；只记录后台值。'),
      JSON_OBJECT('metric', '飞猪 MCI/MIC', 'best_explanation', '飞猪商家服务 MCI，综合咨询体验、预订体验、售后体验等，体现综合服务能力。', 'keep_from_existing', '旧图写 MIC，包含基础信息、资质、图片、营销、有房率、可退改、闪电确认、点评、拒单、销售。', 'suxios_handling', '字段统一 fliggy_mci，别名 MIC；不编造权重。'),
      JSON_OBJECT('metric', 'Google Hotel Center 价格准确率', 'best_explanation', 'Google 对展示价格与实际落地价格一致性的质量信号。', 'keep_from_existing', '补充旧指标中缺少的价格准确率和价格覆盖。', 'suxios_handling', '字段建议 google_price_accuracy_score、google_price_bucket、price_difference_percent。'),
      JSON_OBJECT('metric', 'Google booking link CTR', 'best_explanation', '酒店预订链接曝光后的点击效率。', 'keep_from_existing', '与曝光、点击、CTR 合并。', 'suxios_handling', '可用于官网直连或免费预订链接分析。')
    ),
    'private_metric_rule', '平台私有分值只保存后台值、可观测拆解项和行动建议，不反推未公开算法。'
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  '诊断模板',
  JSON_OBJECT(
    'diagnosis_rows', JSON_ARRAY(
      JSON_OBJECT('symptom', '曝光低', 'judge_first', JSON_ARRAY('排名', '标签', '库存', '价格竞争力', '平台分', '广告预算'), 'actions', JSON_ARRAY('补标签', '补库存', '修价格准确率', '提升平台分', '小预算测试')),
      JSON_OBJECT('symptom', 'CTR低', 'judge_first', JSON_ARRAY('首图', '起价', '评分', '点评量', '标题', '促销标签', '竞对价格'), 'actions', JSON_ARRAY('换首图', '调起价房型', '补卖点标签', '优化促销展示')),
      JSON_OBJECT('symptom', '预订转化低', 'judge_first', JSON_ARRAY('详情页内容', '房型信息', '退改', '发票', '价格梯度', '问答', '差评'), 'actions', JSON_ARRAY('补房型图', '优化房型名', '明确退改和支付', '处理差评')),
      JSON_OBJECT('symptom', '支付/确认转化低', 'judge_first', JSON_ARRAY('支付方式', '库存不足', '确认慢', '价差', '取消政策过严'), 'actions', JSON_ARRAY('开启自动确认', '维护库存', '优化支付方式', '优化退改')),
      JSON_OBJECT('symptom', 'ADR低', 'judge_first', JSON_ARRAY('低价房型占比', '促销过重', '竞对价格', '长住折扣', '房型结构'), 'actions', JSON_ARRAY('控制低价库存', '优化价格梯度', '按需求日提价')),
      JSON_OBJECT('symptom', '入住率低', 'judge_first', JSON_ARRAY('流量不足', '价格过高', '库存策略', '竞对强', '淡季需求低'), 'actions', JSON_ARRAY('提升曝光', '做定向促销', '开放库存', '调整价格')),
      JSON_OBJECT('symptom', 'RevPAR低', 'judge_first', JSON_ARRAY('ADR拖累', '入住率拖累'), 'actions', JSON_ARRAY('拆成 ADR 和 OCC 两条线分别诊断')),
      JSON_OBJECT('symptom', '取消率高', 'judge_first', JSON_ARRAY('免费取消占比', '价格倒挂', '用户犹豫', '竞对更低价', '确认慢'), 'actions', JSON_ARRAY('分渠道分析取消原因', '优化价差', '提升确认效率', '优化取消政策')),
      JSON_OBJECT('symptom', '平台分低', 'judge_first', JSON_ARRAY('服务', '库存', '订单确认', '资质图片', '点评', '违规'), 'actions', JSON_ARRAY('补资质', '补图', '保留房', '提升确认率', '减少拒单'))
    )
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @hotel_metric_unit_id,
  '宿析OS落地规则',
  JSON_OBJECT(
    'field_mapping', JSON_OBJECT(
      'source', '平台，如 ctrip、meituan、fliggy、google_hotel。',
      'data_type', 'traffic、order、revenue、review、advertising、platform_score。',
      'dimension', 'hotel、room_type、rate_plan、channel、device、keyword、campaign。',
      'data_date', '指标归属日期。',
      'amount', '收入、GMV、广告消耗等金额类字段。',
      'quantity', '间夜、房量、库存等数量类字段。',
      'book_order_num', '订单量。',
      'data_value', '单值指标，例如 ADR、评分、转化率。',
      'traffic_fields', 'list_exposure、detail_exposure、flow_rate、order_filling_num、order_submit_num。',
      'raw_data', '平台原始字段、后台截图转录、维度明细、计算口径和来源。'
    ),
    'calculation_guards', JSON_ARRAY(
      '所有指标必须有 metric_scope：全店、OTA渠道、平台、房型、活动、广告。',
      '所有指标必须有 calculation_basis：创建、支付、确认、入住、离店、取消。',
      '分母为 0 或缺失时返回不可计算，不返回 0。',
      '平台私有分值只保存后台值和拆解建议，不写死计算公式。',
      '用户、设备、订单和点评明细必须脱敏后进入 raw_data。'
    ),
    'ai_can_answer', JSON_ARRAY(
      '指标是什么意思。',
      '当前指标低可能说明什么。',
      '需要补哪些字段才能计算。',
      '下一步应检查哪些后台模块。',
      '如何把指标转成运营任务。'
    ),
    'ai_must_not_answer', JSON_ARRAY(
      '未联网复核的当前平台权重。',
      '未入库数据的精确排名原因。',
      '没有成本数据时的 ROI。',
      '只有 OTA 局部数据时的全店入住率。',
      '平台私有分数的反向工程公式。'
    )
  ),
  NOW()
WHERE @hotel_metric_unit_id IS NOT NULL;

SET @hotel_metric_category_name := 'OTA数据知识';
SET @hotel_metric_category_description := 'OTA 指标、酒店收益、平台评分、流量转化和专业计算口径。';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @hotel_metric_category_name,
  @hotel_metric_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @hotel_metric_category_name
);

UPDATE `knowledge_categories`
SET
  `description` = @hotel_metric_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @hotel_metric_category_name;

SET @hotel_metric_category_id := (
  SELECT `id` FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @hotel_metric_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @hotel_metric_staff_title := @hotel_metric_unit_name;
SET @hotel_metric_staff_content := CONCAT(
  '# 酒店OTA专业指标口径知识库', '\n\n',
  '## 融合原则', '\n',
  '- 行业通用指标采用公开公式优先：ADR、入住率、RevPAR、CTR、转化率。', '\n',
  '- OTA 平台私有指标保留运营含义，不编造未公开权重：携程服务质量分、美团 HOS、飞猪 MCI。', '\n',
  '- 旧资料中的飞猪 MIC 与公开资料 MCI 合并，宿析OS统一字段为 `fliggy_mci`，保留 MIC 作为别名。', '\n',
  '- 平台规则和权益会变化，执行前以当前后台为准。', '\n\n',
  '## 核心公式', '\n',
  '| 指标 | 最优公式 | 宿析OS口径 |', '\n',
  '| --- | --- | --- |', '\n',
  '| ADR | `room_revenue / rooms_sold` | 只有 OTA 金额时命名为 `ota_adr`。 |', '\n',
  '| 入住率 | `occupied_rooms / available_rooms` | 只有全店可售房量时叫入住率；只有 OTA 数据时叫 OTA 售卖率。 |', '\n',
  '| RevPAR | `room_revenue / available_rooms` 或 `ADR * occupancy` | 渠道局部数据叫渠道 RevPAR。 |', '\n',
  '| CTR | `clicks / impressions` | 低 CTR 查首图、起价、标签、评分、竞对价。 |', '\n',
  '| 预订转化率 | `created_orders / detail_uv` 或 `created_orders / clicks` | 低值查房型、图片、退改、价格、点评和问答。 |', '\n',
  '| 支付转化率 | `paid_or_confirmed_orders / created_orders` | 低值查支付方式、库存、价差、确认失败、取消政策。 |', '\n',
  '| 取消率 | `cancelled_orders / created_orders`，补充间夜取消率 | 取消预测更应以间夜损失衡量。 |', '\n\n',
  '## 平台分值处理', '\n',
  '- 携程服务质量分：保存后台值和可操作拆解项，不反推公式。', '\n',
  '- 美团 HOS：拆解到图片、资质、确认、预留房、点评、违规。', '\n',
  '- 飞猪 MCI/MIC：拆解到咨询体验、预订体验、售后体验、图片、活动、有房率、闪电确认、拒单。', '\n\n',
  '## 计算守卫', '\n',
  '- 所有指标必须区分全店、OTA渠道、平台、房型、活动、广告。', '\n',
  '- 所有指标必须说明创建、支付、确认、入住、离店、取消口径。', '\n',
  '- 分母为 0 或缺失时返回不可计算，不返回 0。', '\n',
  '- 缺少成本数据时不能输出 ROI。'
);

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@hotel_metric_category_id, 0),
  @hotel_metric_staff_title,
  @hotel_metric_staff_content,
  'OTA,酒店指标,专业口径,ADR,RevPAR,HOS,MCI,CTR,转化率,收益管理',
  JSON_ARRAY('OTA', '酒店指标', '专业口径', 'ADR', 'RevPAR', 'HOS', 'MCI', '转化率'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @hotel_metric_staff_title
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@hotel_metric_category_id, 0),
  `content` = @hotel_metric_staff_content,
  `keywords` = 'OTA,酒店指标,专业口径,ADR,RevPAR,HOS,MCI,CTR,转化率,收益管理',
  `tags` = JSON_ARRAY('OTA', '酒店指标', '专业口径', 'ADR', 'RevPAR', 'HOS', 'MCI', '转化率'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @hotel_metric_staff_title;
