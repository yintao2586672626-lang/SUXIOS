-- Seed OTA platform field inventory into the project knowledge systems.
-- This is content-only: no business tables or OTA fields are added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_field_unit_name := 'OTA平台可确认字段与假设字段清单';
SET @ota_field_source := 'ota';
SET @ota_field_description := '按 2026-05-20 输入资料整理美团、携程 OTA 可确认字段、未指定/假设字段、用途和依据；用于字段口径、采集优先级、知识检索和后续对接核验。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ota_field_unit_name,
  @ota_field_source,
  'done',
  @ota_field_description,
  JSON_ARRAY('OTA', '字段清单', '美团', '携程', '收益分析', '数据采集', '知识库'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_field_unit_name AND `source` = @ota_field_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ota_field_description,
  `tags` = JSON_ARRAY('OTA', '字段清单', '美团', '携程', '收益分析', '数据采集', '知识库'),
  `updated_at` = NOW()
WHERE `name` = @ota_field_unit_name AND `source` = @ota_field_source;

SET @ota_field_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_field_unit_name AND `source` = @ota_field_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ota_field_unit_id
  AND `type` IN ('使用边界', '美团字段清单', '携程字段清单', '落地规则');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_field_unit_id,
  '使用边界',
  JSON_OBJECT(
    'title', @ota_field_unit_name,
    'scope', '本知识单元只沉淀用户提供的字段清单、依据和落地边界，不代表本次已联网复核，也不新增数据库结构。',
    'classification_rule', JSON_OBJECT(
      'official_confirmed_fields', '按输入资料中“官方可确认字段”列保留，作为优先核验和映射对象。',
      'unspecified_or_assumed_fields', '按输入资料中“未指定/假设字段”列保留，默认只进入需求池或 raw_data，不直接视为平台必然可导出字段。',
      'basis', '保留原依据名称；落地前仍需以当前后台实勘、官方接口文档或接口响应为准。'
    ),
    'risk_note', JSON_ARRAY(
      '不得把未指定字段写成已确认事实。',
      '订单、客人、设备、位置、画像等字段涉及合规边界，默认最小化、脱敏或匿名化。',
      '采集失败、字段缺失或口径未知时必须显式暴露，不写兜底假数据。'
    )
  ),
  NOW()
WHERE @ota_field_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_field_unit_id,
  '美团字段清单',
  JSON_OBJECT(
    'platform', '美团',
    'rows', JSON_ARRAY(
      JSON_OBJECT(
        'module', '酒店搜索列表',
        'official_confirmed_fields', 'historySaleCount、frontImg、addr、poiId、areaName、name、lat、lng、lowestPrice、hotelStar、posdescr、brandId、poiLastOrderTime、scoreIntro、priceType、hotelAppointmentExtType、tmcHotelType、memberLevel、lowerLimit；示例中还出现 originalPrice、isCooperated、commentsCountDesc',
        'unspecified_or_assumed_fields', '曝光位、搜索排名、展示端位次、搜索来源、设备端、酒店列表曝光次数',
        'main_usage', '酒店基础维度、列表价格、基础声量与列表竞争态势',
        'basis', '美团 TMC API 文档'
      ),
      JSON_OBJECT(
        'module', '酒店详情',
        'official_confirmed_fields', 'name、address、areaName、fullAreaName、avgScore、hotelStar、frontImg、phone、phoneInfo、poiAttrTagList、introduction、hotelIntroInfo、serviceIconsInfo、imageInfo',
        'unspecified_or_assumed_fields', '详情页访问量、停留时长、标签点击、图册浏览',
        'main_usage', '酒店属性画像、详情页质量评分、静态特征建模',
        'basis', '美团 TMC API 文档'
      ),
      JSON_OBJECT(
        'module', '房型与产品详情',
        'official_confirmed_fields', 'roomId、roomName、roomBaseInfos、roomPhotos、goodsId、goodsName、goodsPrice 日历、goodsStatus、breakfastInfo、notNeedInvoice、isAgreementGoods、cancelRule、needIdentityCard、示例中的 UsageRuleDes、roomStatus',
        'unspecified_or_assumed_fields', '价型标签、促销类型、售卖渠道、排序分、是否参与活动',
        'main_usage', '房型价型、取消规则、早餐、日历价、房态分析',
        'basis', '美团 TMC API 文档'
      ),
      JSON_OBJECT(
        'module', '预订前校验',
        'official_confirmed_fields', 'checkinTime、checkoutTime、poiId、goodsId、roomNum、totalPrice；返回 code、desc、priceModels、remainRoomNum',
        'unspecified_or_assumed_fields', '校验失败原因细分类、失败归因标签、终端来源',
        'main_usage', '下单前实时校验、价格变化预警、库存不足预警',
        'basis', '美团 TMC API 文档'
      ),
      JSON_OBJECT(
        'module', '生单、支付、取消',
        'official_confirmed_fields', 'thirdOrderId、bookInfoList、personInfos（cardNum、cardType、uniqueNo、name）、contactName、contactPhone、invoiceTitle；返回 orderId、sqtBizOrderId、payTradeNo、支付结果码、取消结果码',
        'unspecified_or_assumed_fields', '支付渠道、退款状态、确认时长、客诉标记',
        'main_usage', '订单事实表、支付漏斗、取消分析、客人信息合规建模',
        'basis', '美团 TMC API 文档'
      ),
      JSON_OBJECT(
        'module', '直连产品查询',
        'official_confirmed_fields', '公开页面列出“供应商、活动、产品名称、创建时间、底价、卖价、佣金、库存、早餐、产品开关”',
        'unspecified_or_assumed_fields', 'rate plan ID、上下线原因、改价批次号',
        'main_usage', '价格库存巡检、佣金分析、活动生效核对',
        'basis', '直连数据平台页面'
      ),
      JSON_OBJECT(
        'module', '订单日志与订单监控',
        'official_confirmed_fields', '公开页面列出“美团订单号、步骤、结果、时间、请求耗时”；监控页列出“校验订单成功/失败/异常、创建订单成功/失败/异常、取消订单成功/失败/异常、异常订单、Tp50/Tp95/Tp99”等小时级指标',
        'unspecified_or_assumed_fields', '失败码映射、链路 traceId、供应商错误码',
        'main_usage', '实时监控、SLA、链路异常、失败归因',
        'basis', '直连数据平台页面'
      ),
      JSON_OBJECT(
        'module', '平台同步与一致性规则',
        'official_confirmed_fields', '公开资料确认支持 POI 同步、房型管理、房态同步、预订与取消、规则变更、价格变更、库存变更；FAQ 还确认产品唯一性依赖 poiId + roomType + breakfastNum，并建议全量/增量配合',
        'unspecified_or_assumed_fields', '供应商房型唯一键、同步批次、数据版本',
        'main_usage', 'ETL 主键设计、产品去重、增量同步策略',
        'basis', '直连平台、FAQ、技术文章'
      )
    )
  ),
  NOW()
WHERE @ota_field_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_field_unit_id,
  '携程字段清单',
  JSON_OBJECT(
    'platform', '携程',
    'rows', JSON_ARRAY(
      JSON_OBJECT(
        'module', 'eBooking 商家后台基础能力',
        'official_confirmed_fields', '官方公开确认“收益管理、在线实时管控房态房价、处理订单、参加营销活动、点评管理、售卖酒店附加产品、查看业务分析”',
        'unspecified_or_assumed_fields', '酒店 ID、房型 ID、价型 ID、价格、库存、订单创建/支付/取消时间、营销活动 ID、附加产品 ID',
        'main_usage', '商家后台总入口；经营、订单、营销和点评的一体化底座',
        'basis', 'eBooking 官方登录页'
      ),
      JSON_OBJECT(
        'module', 'Trip.com Connect Content',
        'official_confirmed_fields', '官方公开确认 Content 能力覆盖 Property、Roomtype、Rateplan、Product management、Images、Guest review and status inquiry',
        'unspecified_or_assumed_fields', 'property_id、roomtype_id、rateplan_id、product_id、酒店静态信息、房型图片、床型、可住人数、早餐、取消规则、政策标签',
        'main_usage', '统一酒店/房型/价型维表与内容质量分析',
        'basis', 'Trip.com Connect 官方页面摘要'
      ),
      JSON_OBJECT(
        'module', 'Trip.com Connect Rates & Availability',
        'official_confirmed_fields', '官方公开确认可“Create or update rates, inventories and restrictions”，并支持 “Availability Check” 实时检查 ARI 状态',
        'unspecified_or_assumed_fields', '日历价、币种、剩余库存、关房、连住限制、最晚取消时间、渠道限制',
        'main_usage', '房态、价格、限制条件、收益管理与可售率分析',
        'basis', 'Trip.com Connect 官方页面摘要'
      ),
      JSON_OBJECT(
        'module', 'Trip.com Connect Reservation',
        'official_confirmed_fields', '官方公开确认支持“Booking confirmation, cancellation, modification, reservation status inquiry, reservation information”',
        'unspecified_or_assumed_fields', 'reservation_id、创建时间、确认时间、取消时间、改签时间、入住离店日期、房型/价型、间数、人数、金额、状态',
        'main_usage', '订单事实、取消率、确认时长、修改率分析',
        'basis', 'Trip.com Connect 官方页面摘要'
      ),
      JSON_OBJECT(
        'module', '业务分析与官方报告能力',
        'official_confirmed_fields', '官方登录页确认“查看业务分析”；官方报告提到可形成“商户画像、商圈竞争力报告、ebooking 看板、生意周报”',
        'unspecified_or_assumed_fields', '曝光、访问 UV、详情 PV、转化率、间夜、GMV、客源地、客群标签、竞对圈、市场热度、服务质量',
        'main_usage', '经营看板、客群画像、竞争分析、周报与月报',
        'basis', 'eBooking 官方页与携程官方报告'
      ),
      JSON_OBJECT(
        'module', '数据中心界面观察',
        'official_confirmed_fields', '第三方中文资料展示的数据中心分区包括“收益概览、流量概览、服务概览、用户分析、竞争圈概览、流失分析、竞争圈榜单、每日热度、市场热度趋势、游客出行趋势”，并提到可按 APP、小程序、网页版、H5 以及携程、去哪儿、同程等维度筛选',
        'unspecified_or_assumed_fields', '排名、曝光量、曝光转化率、渠道分布、设备分布、流失酒店、竞对调价、热度指数',
        'main_usage', '流量、竞对、市场、漏斗与分流诊断；字段级定义须以后台实勘为准',
        'basis', '第三方界面观察，需落地时复核'
      ),
      JSON_OBJECT(
        'module', '订单与用户数据合规边界',
        'official_confirmed_fields', '隐私政策确认 eBooking 会处理订单、账号、设备、位置、点评照片/评论、市场调查以及订单数据分析形成画像，并生成内部报告；这说明平台侧存在更丰富行为字段，但不等于商家侧一定可导出',
        'unspecified_or_assumed_fields', '用户设备、城市、行为日志、问卷、营销接触、内部画像标签',
        'main_usage', '合规设计、权限分层、匿名化主键、后续与平台对接字段申请',
        'basis', 'eBooking 隐私政策'
      )
    )
  ),
  NOW()
WHERE @ota_field_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_field_unit_id,
  '落地规则',
  JSON_OBJECT(
    'target_priority', JSON_ARRAY(
      '优先复用 online_daily_data 的 source、data_type、data_date、amount、quantity、book_order_num、comment_score、data_value、list_exposure、detail_exposure、flow_rate、raw_data。',
      '酒店、房型、价型、产品、订单等稳定主键字段先进入原始 JSON 和映射清单；只有被页面、接口、预警、报表或 AI 分析明确读取时再新增结构化字段。',
      '未指定/假设字段默认进入需求池，不直接写入正式指标口径。'
    ),
    'data_type_suggestion', JSON_OBJECT(
      'hotel_profile', '酒店静态信息、图片、地址、星级、评分、标签。',
      'room_product', '房型、价型、早餐、取消规则、库存、日历价。',
      'availability_price', '房态、价格、限制、ARI 校验。',
      'order', '订单创建、确认、支付、取消、修改、金额和状态。',
      'traffic', '曝光、浏览、访问、转化、排名、竞对圈和市场热度。',
      'review', '评分、点评、回复、服务质量和标签。',
      'monitoring', '接口日志、失败码、耗时、异常和 SLA。'
    ),
    'verification_before_use', JSON_ARRAY(
      '用当前官方文档、后台页面或接口响应二次核验字段是否仍存在。',
      '确认字段可由酒店自身授权账号访问，且可用于商家侧经营分析。',
      '确认保存、回显、编辑、旧数据兼容、权限过滤和脱敏策略。',
      '确认指标公式和来源口径，不把平台内部画像字段当作商家可导出字段。'
    )
  ),
  NOW()
WHERE @ota_field_unit_id IS NOT NULL;

SET @ota_category_name := 'OTA运营';
SET @ota_category_description := 'OTA数据采集、渠道运营、点评、订单、流量和广告方法';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @ota_category_name,
  @ota_category_description,
  20,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_category_name
);

UPDATE `knowledge_categories`
SET
  `description` = @ota_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_category_name;

SET @ota_category_id := (
  SELECT `id` FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @staff_knowledge_title := @ota_field_unit_name;
SET @staff_knowledge_content := CONCAT(
  '# OTA平台可确认字段与假设字段清单', '\n\n',
  '> 来源说明：按 2026-05-20 输入资料整理；本条目未在导入时重新联网核验。落地前必须以当前官方文档、后台实勘或接口响应为准。', '\n\n',
  '## 使用边界', '\n',
  '- “官方可确认字段”作为优先核验和映射对象。', '\n',
  '- “未指定/假设字段”默认只进入需求池或 `raw_data`，不得直接视为平台必然可导出字段。', '\n',
  '- 客人、设备、位置、画像等字段涉及合规边界，默认最小化、脱敏或匿名化。', '\n',
  '- 字段缺失、采集失败或口径未知时必须显式暴露，不写兜底假数据。', '\n\n',
  '## 美团字段清单', '\n',
  '| 数据表或模块 | 官方可确认字段 | 未指定/假设字段 | 主要用途 | 依据 |', '\n',
  '| --- | --- | --- | --- | --- |', '\n',
  '| 酒店搜索列表 | historySaleCount、frontImg、addr、poiId、areaName、name、lat、lng、lowestPrice、hotelStar、posdescr、brandId、poiLastOrderTime、scoreIntro、priceType、hotelAppointmentExtType、tmcHotelType、memberLevel、lowerLimit；示例中还出现 originalPrice、isCooperated、commentsCountDesc | 曝光位、搜索排名、展示端位次、搜索来源、设备端、酒店列表曝光次数 | 酒店基础维度、列表价格、基础声量与列表竞争态势 | 美团 TMC API 文档 |', '\n',
  '| 酒店详情 | name、address、areaName、fullAreaName、avgScore、hotelStar、frontImg、phone、phoneInfo、poiAttrTagList、introduction、hotelIntroInfo、serviceIconsInfo、imageInfo | 详情页访问量、停留时长、标签点击、图册浏览 | 酒店属性画像、详情页质量评分、静态特征建模 | 美团 TMC API 文档 |', '\n',
  '| 房型与产品详情 | roomId、roomName、roomBaseInfos、roomPhotos、goodsId、goodsName、goodsPrice 日历、goodsStatus、breakfastInfo、notNeedInvoice、isAgreementGoods、cancelRule、needIdentityCard、示例中的 UsageRuleDes、roomStatus | 价型标签、促销类型、售卖渠道、排序分、是否参与活动 | 房型价型、取消规则、早餐、日历价、房态分析 | 美团 TMC API 文档 |', '\n',
  '| 预订前校验 | checkinTime、checkoutTime、poiId、goodsId、roomNum、totalPrice；返回 code、desc、priceModels、remainRoomNum | 校验失败原因细分类、失败归因标签、终端来源 | 下单前实时校验、价格变化预警、库存不足预警 | 美团 TMC API 文档 |', '\n',
  '| 生单、支付、取消 | thirdOrderId、bookInfoList、personInfos（cardNum、cardType、uniqueNo、name）、contactName、contactPhone、invoiceTitle；返回 orderId、sqtBizOrderId、payTradeNo、支付结果码、取消结果码 | 支付渠道、退款状态、确认时长、客诉标记 | 订单事实表、支付漏斗、取消分析、客人信息合规建模 | 美团 TMC API 文档 |', '\n',
  '| 直连产品查询 | 公开页面列出“供应商、活动、产品名称、创建时间、底价、卖价、佣金、库存、早餐、产品开关” | rate plan ID、上下线原因、改价批次号 | 价格库存巡检、佣金分析、活动生效核对 | 直连数据平台页面 |', '\n',
  '| 订单日志与订单监控 | 公开页面列出“美团订单号、步骤、结果、时间、请求耗时”；监控页列出“校验订单成功/失败/异常、创建订单成功/失败/异常、取消订单成功/失败/异常、异常订单、Tp50/Tp95/Tp99”等小时级指标 | 失败码映射、链路 traceId、供应商错误码 | 实时监控、SLA、链路异常、失败归因 | 直连数据平台页面 |', '\n',
  '| 平台同步与一致性规则 | 公开资料确认支持 POI 同步、房型管理、房态同步、预订与取消、规则变更、价格变更、库存变更；FAQ 还确认产品唯一性依赖 poiId + roomType + breakfastNum，并建议全量/增量配合 | 供应商房型唯一键、同步批次、数据版本 | ETL 主键设计、产品去重、增量同步策略 | 直连平台、FAQ、技术文章 |', '\n\n',
  '## 携程字段清单', '\n',
  '| 数据表或模块 | 官方可确认字段 | 未指定/假设字段 | 主要用途 | 依据 |', '\n',
  '| --- | --- | --- | --- | --- |', '\n',
  '| eBooking 商家后台基础能力 | 官方公开确认“收益管理、在线实时管控房态房价、处理订单、参加营销活动、点评管理、售卖酒店附加产品、查看业务分析” | 酒店 ID、房型 ID、价型 ID、价格、库存、订单创建/支付/取消时间、营销活动 ID、附加产品 ID | 商家后台总入口；经营、订单、营销和点评的一体化底座 | eBooking 官方登录页 |', '\n',
  '| Trip.com Connect Content | 官方公开确认 Content 能力覆盖 Property、Roomtype、Rateplan、Product management、Images、Guest review and status inquiry | property_id、roomtype_id、rateplan_id、product_id、酒店静态信息、房型图片、床型、可住人数、早餐、取消规则、政策标签 | 统一酒店/房型/价型维表与内容质量分析 | Trip.com Connect 官方页面摘要 |', '\n',
  '| Trip.com Connect Rates & Availability | 官方公开确认可“Create or update rates, inventories and restrictions”，并支持 “Availability Check” 实时检查 ARI 状态 | 日历价、币种、剩余库存、关房、连住限制、最晚取消时间、渠道限制 | 房态、价格、限制条件、收益管理与可售率分析 | Trip.com Connect 官方页面摘要 |', '\n',
  '| Trip.com Connect Reservation | 官方公开确认支持“Booking confirmation, cancellation, modification, reservation status inquiry, reservation information” | reservation_id、创建时间、确认时间、取消时间、改签时间、入住离店日期、房型/价型、间数、人数、金额、状态 | 订单事实、取消率、确认时长、修改率分析 | Trip.com Connect 官方页面摘要 |', '\n',
  '| 业务分析与官方报告能力 | 官方登录页确认“查看业务分析”；官方报告提到可形成“商户画像、商圈竞争力报告、ebooking 看板、生意周报” | 曝光、访问 UV、详情 PV、转化率、间夜、GMV、客源地、客群标签、竞对圈、市场热度、服务质量 | 经营看板、客群画像、竞争分析、周报与月报 | eBooking 官方页与携程官方报告 |', '\n',
  '| 数据中心界面观察 | 第三方中文资料展示的数据中心分区包括“收益概览、流量概览、服务概览、用户分析、竞争圈概览、流失分析、竞争圈榜单、每日热度、市场热度趋势、游客出行趋势”，并提到可按 APP、小程序、网页版、H5 以及携程、去哪儿、同程等维度筛选 | 排名、曝光量、曝光转化率、渠道分布、设备分布、流失酒店、竞对调价、热度指数 | 流量、竞对、市场、漏斗与分流诊断；字段级定义须以后台实勘为准 | 第三方界面观察，需落地时复核 |', '\n',
  '| 订单与用户数据合规边界 | 隐私政策确认 eBooking 会处理订单、账号、设备、位置、点评照片/评论、市场调查以及订单数据分析形成画像，并生成内部报告；这说明平台侧存在更丰富行为字段，但不等于商家侧一定可导出 | 用户设备、城市、行为日志、问卷、营销接触、内部画像标签 | 合规设计、权限分层、匿名化主键、后续与平台对接字段申请 | eBooking 隐私政策 |', '\n\n',
  '## 落地规则', '\n',
  '- 优先复用 `online_daily_data` 的 `source`、`data_type`、`data_date`、`amount`、`quantity`、`book_order_num`、`comment_score`、`data_value`、`list_exposure`、`detail_exposure`、`flow_rate`、`raw_data`。', '\n',
  '- 酒店、房型、价型、产品、订单等稳定主键字段先进入原始 JSON 和映射清单；只有被页面、接口、预警、报表或 AI 分析明确读取时再新增结构化字段。', '\n',
  '- 未指定/假设字段必须先复核来源、权限、口径和可导出性。', '\n',
  '- 新增结构化字段前必须同步考虑保存、回显、编辑、旧数据兼容、权限过滤和脱敏策略。'
);

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@ota_category_id, 0),
  @staff_knowledge_title,
  @staff_knowledge_content,
  'OTA,字段清单,美团,携程,TMC,Trip.com Connect,eBooking,收益分析,数据采集',
  JSON_ARRAY('OTA', '字段清单', '美团', '携程', '收益分析', '数据采集'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@ota_category_id, 0),
  `content` = @staff_knowledge_content,
  `keywords` = 'OTA,字段清单,美团,携程,TMC,Trip.com Connect,eBooking,收益分析,数据采集',
  `tags` = JSON_ARRAY('OTA', '字段清单', '美团', '携程', '收益分析', '数据采集'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title;
