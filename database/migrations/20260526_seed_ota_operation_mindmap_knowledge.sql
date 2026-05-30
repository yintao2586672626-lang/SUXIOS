-- Seed OTA operation mind-map knowledge into the project knowledge systems.
-- This is content-only: no crawler, business table, interface, or OTA field is added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_mindmap_unit_name := 'OTA运营思维导图2.0知识沉淀';
SET @ota_mindmap_source := 'ota';
SET @ota_mindmap_description := '按 2026-05-26 对 E:\\杂项\\OTA运营思维导图2.0版 的 30 张 PNG 思维导图 OCR 后整理，沉淀 OTA 平台、流量、转化、内容、点评、评分排名、房态库存和数据诊断知识；仅作运营方法论与知识库内容，平台规则执行前必须复核当前后台口径。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ota_mindmap_unit_name,
  @ota_mindmap_source,
  'done',
  @ota_mindmap_description,
  JSON_ARRAY('OTA', '运营知识', '思维导图', '流量', '转化率', '点评', '平台评分', '知识库'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_mindmap_unit_name AND `source` = @ota_mindmap_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ota_mindmap_description,
  `tags` = JSON_ARRAY('OTA', '运营知识', '思维导图', '流量', '转化率', '点评', '平台评分', '知识库'),
  `updated_at` = NOW()
WHERE `name` = @ota_mindmap_unit_name AND `source` = @ota_mindmap_source;

SET @ota_mindmap_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_mindmap_unit_name AND `source` = @ota_mindmap_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ota_mindmap_unit_id
  AND `type` IN (
    '资料边界',
    '平台与渠道定位',
    '流量体系',
    '转化漏斗',
    '内容资产',
    '点评与口碑',
    '平台评分与排名',
    '房态库存与日常维护',
    '数据诊断闭环',
    '宿析OS落地规则'
  );

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '资料边界',
  JSON_OBJECT(
    'title', @ota_mindmap_unit_name,
    'source_folder', 'E:\\杂项\\OTA运营思维导图2.0版',
    'source_type', '30 张 PNG 思维导图，本次通过本机 OCR 抽取后人工归并。',
    'distilled_at', '2026-05-26',
    'source_files', JSON_ARRAY(
      '【订单创造营】OTA差评.png',
      '【订单创造营】服务质量分.png',
      '【订单创造营】诊断优化.png',
      '【订单创造营】预定转化率.png',
      '【订单创造营出品】OTA平台.png',
      '【订单创造营出品】OTA运营.png',
      '【订单创造营出品】产品流量.png',
      '【订单创造营出品】保留房.png',
      '【订单创造营出品】内容流量.png',
      '【订单创造营出品】图片信息.png',
      '【订单创造营出品】房型信息.png',
      '【订单创造营出品】搜索流量.png',
      '【订单创造营出品】携程挂牌.png',
      '【订单创造营出品】携程排序规则.png',
      '【订单创造营出品】数据运营.png',
      '【订单创造营出品】日常运营维护.png',
      '【订单创造营出品】活动促销流量.png',
      '【订单创造营出品】流量来源.png',
      '【订单创造营出品】渠道运营.png',
      '【订单创造营出品】点评.png',
      '【订单创造营出品】点评规则.png',
      '【订单创造营出品】特色卖点展示.png',
      '【订单创造营出品】美团排名规则.png',
      '【订单创造营出品】美团推广通.png',
      '【订单创造营出品】自主访问流量.png',
      '【订单创造营出品】酒店简介.png',
      '【订单创造营出品】金字塔广告位.png',
      '【订单创造营制图】美团HOS指数.png',
      '【订单创造营制图】飞猪MIC指数详解.png',
      '【订单创造营营】标签.png'
    ),
    'use_boundary', JSON_ARRAY(
      '作为 OTA 运营方法论、字段口径、动作清单和 Agent 检索知识使用。',
      '平台规则、电话、邮箱、挂牌条件、排名规则和广告位规则执行前必须以平台后台或业务经理最新口径复核。',
      '本次不新增抓取逻辑、不新增业务表、不把历史平台规则写成当前官方规则。'
    )
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '平台与渠道定位',
  JSON_OBJECT(
    'operation_lines', JSON_ARRAY('平台运营', '产品运营', '活动运营', '数据运营'),
    'platforms', JSON_ARRAY(
      JSON_OBJECT('platform', '携程系', 'traits', '中高端、商旅、体量较大，挂牌和服务质量分影响流量。', 'actions', JSON_ARRAY('深耕挂牌', '维护保留房', '提升服务质量分', '维护分销', '评估金字塔广告位')),
      JSON_OBJECT('platform', '美团点评系', 'traits', '本地流量、年轻用户、价格敏感，HOS、冠级、金币和推广通影响流量。', 'actions', JSON_ARRAY('维护 HOS', '设置预留房', '提升点评', '投放推广通', '管理冠级和金币')),
      JSON_OBJECT('platform', '飞猪', 'traits', '年轻用户、信用住、活动和 MIC 指数明显。', 'actions', JSON_ARRAY('完善信息和图片', '参加活动', '设置宽松退改', '提升闪电确认和 MIC')),
      JSON_OBJECT('platform', '分销/短租/境外渠道', 'traits', '补充曝光或特定客群。', 'actions', JSON_ARRAY('控制价格一致性', '控制库存成本', '避免管理复杂度失控'))
    ),
    'channel_strategy', JSON_ARRAY(
      JSON_OBJECT('strategy', '全渠道运营', 'value', '最大化曝光。', 'risk', '价格和库存管理成本高。'),
      JSON_OBJECT('strategy', '精选渠道运营', 'value', '适合多数门店，选主渠道深耕，辅渠道补充。', 'risk', '需要持续比较平台贡献度。'),
      JSON_OBJECT('strategy', '独家渠道运营', 'value', '换取平台支持。', 'risk', '损失其他平台曝光，并与平台强绑定。')
    )
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '流量体系',
  JSON_OBJECT(
    'traffic_types', JSON_ARRAY(
      JSON_OBJECT('type', '搜索流量', 'entries', JSON_ARRAY('城市空搜', '筛选关键词', '个性化智能排序', '标签搜索'), 'actions', JSON_ARRAY('提升排名', '完善标签', '维护价格库存', '提升点评分', '参与活动')),
      JSON_OBJECT('type', '产品流量', 'entries', JSON_ARRAY('全日房', '钟点房', '会议房', '民宿', '套餐', '景酒', '机酒', '门票打包'), 'actions', JSON_ARRAY('扩展可售产品', '覆盖不同出行场景')),
      JSON_OBJECT('type', '活动促销流量', 'entries', JSON_ARRAY('特价', '限时', '提前订', '连住', '会员', '新客', '节假日大促'), 'actions', JSON_ARRAY('计算活动成本', '避免提价打折', '避免线下补差价')),
      JSON_OBJECT('type', '付费流量', 'entries', JSON_ARRAY('携程金字塔', '直通车', '点金手', '美团推广通', '钻石展位'), 'actions', JSON_ARRAY('设置预算', '设置出价', '分时段投放', '记录转化效果')),
      JSON_OBJECT('type', '内容流量', 'entries', JSON_ARRAY('攻略', '问答', '点评', '短笔记', '短视频', '旅拍', '旅行笔记'), 'actions', JSON_ARRAY('用真实图文承接卖点', '可嵌入酒店链接', '提高互动量')),
      JSON_OBJECT('type', '自主访问流量', 'entries', JSON_ARRAY('微信公众号', '头条', '百家号', '小红书', '知乎', '微博', '问答社区'), 'actions', JSON_ARRAY('一文多发', '带预订链接', '不放违规联系方式'))
    )
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '转化漏斗',
  JSON_OBJECT(
    'funnel', JSON_ARRAY(
      JSON_OBJECT('stage', '点击转化', 'path', '搜索页进入详情页', 'factors', JSON_ARRAY('搜索排名', '首图', '名称', '点评', '位置', '最低价', '标签', '近期成交', '竞对'), 'actions', JSON_ARRAY('调整首图和起价房型', '提升排名', '提升点评分', '增加正向标签')),
      JSON_OBJECT('stage', '预订转化', 'path', '详情页进入订单页', 'factors', JSON_ARRAY('图片', '设施', '简介', '房型', '点评', '问答', '竞对', '价格梯度', '退改政策'), 'actions', JSON_ARRAY('完善详情页', '优化房型名称', '明确床型价格退改发票和支付说明')),
      JSON_OBJECT('stage', '支付转化', 'path', '填写页进入提交/支付', 'factors', JSON_ARRAY('支付方式', '取消政策', '发票类型', '用户不确定因素'), 'actions', JSON_ARRAY('明确到店付/在线付/信用住', '明确免费取消/限时取消/不可取消'))
    ),
    'diagnosis', JSON_ARRAY(
      '点击转化低：看排名、首图、起价、点评分、标签、竞对价格。',
      '预订转化低：看房型信息、图片完整度、退改政策、问答、点评回复。',
      '支付转化低：看支付方式、发票、取消政策、订单填写风险点。'
    )
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '内容资产',
  JSON_OBJECT(
    'assets', JSON_ARRAY(
      JSON_OBJECT('asset', '图片', 'standard', JSON_ARRAY('首图接近 3:4', '类别完整', '实景优先', '可测试创意图', '周期更换并记录数据'), 'value', '影响点击转化和用户第一印象。'),
      JSON_OBJECT('asset', '房型信息', 'standard', JSON_ARRAY('名称长度合理', '包含客群、功能、卖点、床型', '价格区间和梯度清晰', '退改规则清楚'), 'value', '承接筛选、价格感知和预订转化。'),
      JSON_OBJECT('asset', '酒店简介', 'standard', JSON_ARRAY('分段式', '套餐化', '场景化', '写设计、早餐、设施、服务、周边、交通、Q&A'), 'value', '打消疑虑，强化卖点。'),
      JSON_OBJECT('asset', '特色卖点', 'standard', JSON_ARRAY('简介', '图文秀', '房型', '问答', '点评回复', '礼盒', '店内商城'), 'value', '提升差异化和内容转化。'),
      JSON_OBJECT('asset', '标签', 'standard', JSON_ARRAY('基础信息', '点评关键词', '活动', '业务', '房型', '设施', '人工规则'), 'value', '提升搜索、筛选、点击和预订转化。')
    ),
    'writing_rule', '简介可按视觉、听觉、嗅觉、触觉、味觉组织，但必须对应真实设施和服务，不写无法交付的卖点。'
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '点评与口碑',
  JSON_OBJECT(
    'good_review_value', JSON_ARRAY('提升流量', '提升排序', '提升标签曝光', '提升转化率', '提升价格承受力', '增强竞对比较中的信任资产'),
    'good_review_sources', JSON_ARRAY('高于预期的入住体验', '入住后提醒点评', '活动或礼物激励但不得诱导虚假评价', '回复中植入真实卖点、活动预告和特色内容'),
    'bad_review_factors', JSON_ARRAY('卫生', '隔音', '热水', '网络', '设施设备', '服务', '性价比', '纠纷', '特价房', '高价房', '临街房', '不可取消房型', '到店无房', '确认后满房', '确认后涨价'),
    'handling_principles', JSON_ARRAY(
      '先判断平台规则：可删除、可修改、可申诉、只可回复。',
      '申诉必须保留截图、订单、沟通记录等证据。',
      '回复结构：诚挚道歉、解释说明、后续补救、明确改进。',
      '不使用第三方违规删评，不伪造好评，不把历史删评路径写成当前可执行规则。'
    )
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '平台评分与排名',
  JSON_OBJECT(
    'scorecards', JSON_ARRAY(
      JSON_OBJECT('platform_metric', '携程服务质量分', 'dimensions', JSON_ARRAY('5 分钟确认', '保留房/Freesale', '无缺陷订单', '确认后满房/涨价/拒单扣分'), 'actions', JSON_ARRAY('自动接单', '确保库存', '避免拒单', '保留房充足', '处理无房和涨价风险')),
      JSON_OBJECT('platform_metric', '携程排序', 'dimensions', JSON_ARRAY('挂牌', '排序分', '订单', '付费排序'), 'actions', JSON_ARRAY('挂牌/复牌', '提升客户价值分', '价格感受分', '房源保障分', '信息优势分', '服务质量分')),
      JSON_OBJECT('platform_metric', '携程金字塔', 'dimensions', JSON_ARRAY('CPC', 'CPT', '服务质量分', '点评', '合作关系', '房态房价'), 'actions', JSON_ARRAY('明确日预算', '明确出价', '明确场景和排序位', '消耗完或满房时及时处理')),
      JSON_OBJECT('platform_metric', '美团 HOS', 'dimensions', JSON_ARRAY('近 28 天滚动计算', '酒店信息', '服务质量', '经营产能', '违规违约'), 'actions', JSON_ARRAY('完善资质和图片', '提升确认率', '提升点评', '提升间夜', '提升预留房消费', '减少违规')),
      JSON_OBJECT('platform_metric', '美团排名/冠级', 'dimensions', JSON_ARRAY('时间', '交易', '销量', '合作度', '库存', '点评', 'HOS', '金币'), 'actions', JSON_ARRAY('维护库存', '维护预留房', '维护点评', '管理冠级', '金币兑换', '推广通投放')),
      JSON_OBJECT('platform_metric', '飞猪 MIC', 'dimensions', JSON_ARRAY('基础信息', '资质', '图片', '营销', '有房率', '可退改', '闪电确认', '点评', '拒单', '销售'), 'actions', JSON_ARRAY('多传图', '完善信息', '参加活动', '少关房', '设置宽松取消和闪电确认'))
    ),
    'staleness_rule', '这些平台规则来自历史思维导图，执行前必须复核当前后台口径。'
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '房态库存与日常维护',
  JSON_OBJECT(
    'reserve_room_rules', JSON_ARRAY(
      '每个主要房型尽量有保留房/预留房。',
      '热销房型和高产房型设置更多库存。',
      '现付和预付都要覆盖。',
      '保留时间内不要随意关闭未售完库存。',
      '房型满时优先升级、调配其他渠道客人或安排其他店，避免拒单和到店无房。',
      '酒店满房时必须提前处理房态，不用假库存掩盖问题。'
    ),
    'daily_checklist', JSON_ARRAY(
      JSON_OBJECT('module', '订单', 'checks', JSON_ARRAY('新订单', '修改单', '取消单', '延住单', '携程/美团 5 分钟确认', '飞猪 1 分钟确认')),
      JSON_OBJECT('module', '价格', 'checks', JSON_ARRAY('外网价格体系', '远期价格', '当天价格', '节假日价格', '竞对价格', '低价房型')),
      JSON_OBJECT('module', '房态', 'checks', JSON_ARRAY('普库库存', '保留房库存', '剩余库存', '节假日库存', '线下渠道占用')),
      JSON_OBJECT('module', '点评', 'checks', JSON_ARRAY('好评回复', '差评处理', '关键词分析', '正向和负向评价点')),
      JSON_OBJECT('module', '问答', 'checks', JSON_ARRAY('日常问答回复', 'IM 及时回复', '自动回复内容')),
      JSON_OBJECT('module', '活动', 'checks', JSON_ARRAY('促销', '付费广告', '参与', '修改', '取消', '有效时段', '无效流量剔除')),
      JSON_OBJECT('module', '图片', 'checks', JSON_ARRAY('酒店首图', '房型首图', '类别补齐', '周期测试')),
      JSON_OBJECT('module', '数据', 'checks', JSON_ARRAY('流量', '转化率', '用户', '营业额', '订单量', '间夜', '违规指标'))
    )
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '数据诊断闭环',
  JSON_OBJECT(
    'questions', JSON_ARRAY(
      '哪个平台、渠道或入口贡献了订单、间夜和营业额。',
      '流量低是搜索、筛选、直搜、内容、活动还是付费入口的问题。',
      '转化低发生在点击、预订还是支付阶段。',
      '当前平台分和违规项卡在哪些维度。'
    ),
    'metrics', JSON_ARRAY(
      JSON_OBJECT('group', '销售数据', 'items', JSON_ARRAY('营业额', '间夜量', '订单量', '取消量', '平台佣金', '平台贡献度')),
      JSON_OBJECT('group', '流量数据', 'items', JSON_ARRAY('曝光', '访客', '城市搜索', '筛选关键词', '直搜', '内容流量', '流量来源结构')),
      JSON_OBJECT('group', '转化数据', 'items', JSON_ARRAY('点击转化率', '预订转化率', '支付转化率')),
      JSON_OBJECT('group', '运营指标', 'items', JSON_ARRAY('携程服务质量分', '美团 HOS', '飞猪 MIC', '挂牌/挂冠', '广告消耗')),
      JSON_OBJECT('group', '违规指标', 'items', JSON_ARRAY('到店无房', '确认后满房', '确认后涨价', '拒单', '逃单', '价格倒挂', '刷单')),
      JSON_OBJECT('group', '用户数据', 'items', JSON_ARRAY('性别', '年龄', '偏好', '浏览时间', '预订时间', '入住天数', '提前预订天数')),
      JSON_OBJECT('group', '竞对数据', 'items', JSON_ARRAY('价格', '点评', '排名', '活动', '标签', '库存', '图片', '卖点'))
    ),
    'action_matrix', JSON_ARRAY(
      JSON_OBJECT('diagnosis', '高流量高转化', 'action', '维持并扩大有效库存、活动和广告预算。'),
      JSON_OBJECT('diagnosis', '高流量低转化', 'action', '优化首图、详情页、房型、价格、退改、点评和问答。'),
      JSON_OBJECT('diagnosis', '低流量高转化', 'action', '提升排名、标签、活动、付费投放和内容分发。'),
      JSON_OBJECT('diagnosis', '低流量低转化', 'action', '先修基础信息、价格库存、点评分和平台分，再扩流量。'),
      JSON_OBJECT('diagnosis', '平台分低', 'action', '拆到确认率、保留房、无缺陷订单、信息完整度、点评、违规项逐项修复。'),
      JSON_OBJECT('diagnosis', '竞对强', 'action', '对比价格、设施、图片、标签、活动和点评，找可执行差异点。')
    )
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_mindmap_unit_id,
  '宿析OS落地规则',
  JSON_OBJECT(
    'rules', JSON_ARRAY(
      '作为知识库：沉淀到 knowledge_units、knowledge_chunks、knowledge_base，供 Agent 检索。',
      '作为指标口径：映射到已有 online_daily_data.raw_data、日报、收益分析、竞对分析，不因本资料直接新增表。',
      '作为运营任务：转成可执行任务清单时必须绑定平台、酒店、日期、指标、当前值、目标值、动作和验证方式。',
      '作为 AI 决策依据：只输出基于现有数据可验证的建议；缺少字段时返回待补数据或不可计算，不写兜底假值。',
      '作为平台规则：所有具体条件、分值、广告位、删除/申诉路径和联系方式执行前必须复核当前平台后台。'
    )
  ),
  NOW()
WHERE @ota_mindmap_unit_id IS NOT NULL;

SET @ota_mindmap_category_name := 'OTA运营知识';
SET @ota_mindmap_category_description := 'OTA 平台、渠道、流量、转化、内容、点评、评分排名、房态库存和运营诊断知识。';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @ota_mindmap_category_name,
  @ota_mindmap_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_mindmap_category_name
);

UPDATE `knowledge_categories`
SET
  `description` = @ota_mindmap_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_mindmap_category_name;

SET @ota_mindmap_category_id := (
  SELECT `id` FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_mindmap_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @ota_mindmap_staff_title := @ota_mindmap_unit_name;
SET @ota_mindmap_staff_content := CONCAT(
  '# OTA运营思维导图2.0知识沉淀', '\n\n',
  '## 使用边界', '\n',
  '- 来源：E:\\杂项\\OTA运营思维导图2.0版，30 张 PNG 思维导图，经 OCR 后归并。', '\n',
  '- 用途：作为 OTA 运营方法论、字段口径、动作清单和 Agent 检索知识。', '\n',
  '- 限制：平台规则、电话、邮箱、挂牌/排名/广告位条件具有时效性，执行前必须复核当前平台后台。', '\n',
  '- 本次不新增抓取逻辑、不新增业务表、不把历史规则写成当前官方规则。', '\n\n',
  '## 核心框架', '\n',
  '- 平台运营：携程、美团、飞猪、分销、短租、境外平台；直营、代理、独家、精选、全渠道。', '\n',
  '- 产品运营：单酒、钟点房、会议房、套餐、景酒、机酒、门票、餐饮、SPA、租车；房型、价格、退改、卖点。', '\n',
  '- 活动运营：免费促销、场景促销、付费广告、平台活动、店铺活动、节假日活动。', '\n',
  '- 数据运营：订单、间夜、营业额、取消、佣金、流量来源、转化率、平台指数、竞对、用户画像。', '\n\n',
  '## 诊断闭环', '\n',
  '- 点击转化低：看排名、首图、起价、点评分、标签、竞对价格。', '\n',
  '- 预订转化低：看房型信息、图片完整度、退改政策、问答、点评回复。', '\n',
  '- 支付转化低：看支付方式、发票、取消政策、订单填写风险点。', '\n',
  '- 平台分低：拆到确认率、保留房、无缺陷订单、信息完整度、点评、违规项逐项修复。', '\n\n',
  '## 宿析OS落地', '\n',
  '- 映射到已有 online_daily_data.raw_data、日报、收益分析、竞对分析，不因本资料直接新增表。', '\n',
  '- 转成运营任务时必须绑定平台、酒店、日期、指标、当前值、目标值、动作和验证方式。', '\n',
  '- 缺少字段时返回待补数据或不可计算，不写兜底假值。'
);

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@ota_mindmap_category_id, 0),
  @ota_mindmap_staff_title,
  @ota_mindmap_staff_content,
  'OTA,运营知识,思维导图,流量,转化率,点评,平台评分,房态库存,数据诊断',
  JSON_ARRAY('OTA', '运营知识', '思维导图', '流量', '转化率', '点评', '平台评分', '房态库存', '数据诊断'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @ota_mindmap_staff_title
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@ota_mindmap_category_id, 0),
  `content` = @ota_mindmap_staff_content,
  `keywords` = 'OTA,运营知识,思维导图,流量,转化率,点评,平台评分,房态库存,数据诊断',
  `tags` = JSON_ARRAY('OTA', '运营知识', '思维导图', '流量', '转化率', '点评', '平台评分', '房态库存', '数据诊断'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @ota_mindmap_staff_title;
