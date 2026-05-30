-- Seed OTA standard metrics and formulas into the project knowledge systems.
-- This is content-only: no business tables or OTA fields are added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_metric_unit_name := 'OTA标准指标与推荐公式清单';
SET @ota_metric_source := 'ota';
SET @ota_metric_description := '按 2026-05-20 输入资料整理 OTA 流量、转化、订单、收益、酒店经营、用户和营销指标的推荐公式、关键原始字段、建议粒度和口径备注。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ota_metric_unit_name,
  @ota_metric_source,
  'done',
  @ota_metric_description,
  JSON_ARRAY('OTA', '标准指标', '指标公式', '流量漏斗', '收益管理', '转化率', '知识库'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_metric_unit_name AND `source` = @ota_metric_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ota_metric_description,
  `tags` = JSON_ARRAY('OTA', '标准指标', '指标公式', '流量漏斗', '收益管理', '转化率', '知识库'),
  `updated_at` = NOW()
WHERE `name` = @ota_metric_unit_name AND `source` = @ota_metric_source;

SET @ota_metric_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_metric_unit_name AND `source` = @ota_metric_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ota_metric_unit_id
  AND `type` IN ('使用边界', '流量漏斗指标', '交易收益指标', '酒店收益管理指标', '用户与营销指标', '落地规则');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_metric_unit_id,
  '使用边界',
  JSON_OBJECT(
    'title', @ota_metric_unit_name,
    'scope', '本知识单元只沉淀用户提供的标准指标、公式、字段和粒度口径；不代表本次已联网复核，也不新增数据库结构。',
    'naming_rule', JSON_ARRAY(
      '平台只有 OTA 局部数据时，指标名称必须带渠道限定，例如 OTA 售卖率、渠道 RevPAR。',
      '缺少广告成本时，不严格命名为 ROI，可命名为站内活动回报或归因收入。',
      '缺少全店可售房量时，不计算全店入住率，只计算 OTA 售卖率或渠道售卖率。'
    ),
    'raw_field_rule', '关键原始字段先进入 raw_data 或既有 online_daily_data 字段；只有明确被页面、接口、报表、预警或 AI 分析读取时才新增结构化字段。',
    'risk_note', JSON_ARRAY(
      '不得把平台未提供字段写成已确认字段。',
      '用户、设备、会话和订单字段必须匿名化或脱敏。',
      '公式所需分母为 0 或缺失时必须显式标记无法计算，不写假值。'
    )
  ),
  NOW()
WHERE @ota_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_metric_unit_id,
  '流量漏斗指标',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT(
        'metric', '曝光',
        'formula', 'impression_count',
        'raw_fields', '曝光日志、排名位次、渠道、设备',
        'granularity', '小时、日、周、月',
        'note', '携程数据中心更可能直接提供；美团公开 TMC 文档未直接给曝光计数，若无商家报表需补埋点或额外导出。补数需求来自平台能力差异。'
      ),
      JSON_OBJECT(
        'metric', 'UV',
        'formula', 'count(distinct user_id/device_id/session_user_key)',
        'raw_fields', '访问日志、用户/设备匿名键',
        'granularity', '小时、日、周、月',
        'note', 'Google Analytics 将 users 定义为在指定时间内触发事件的唯一用户；若无平台用户 ID，建议退化为设备或会话匿名键。'
      ),
      JSON_OBJECT(
        'metric', 'PV',
        'formula', 'page_view_count',
        'raw_fields', '页面浏览日志、详情页浏览日志',
        'granularity', '小时、日、周、月',
        'note', 'GA 将 pageview 定义为被追踪页面的查看次数，重复查看会重复计数。'
      ),
      JSON_OBJECT(
        'metric', 'CTR',
        'formula', 'clicks / impressions',
        'raw_fields', '点击数、曝光数',
        'granularity', '小时、日、周、月',
        'note', 'Google Ads 的 CTR 定义就是点击 ÷ 展现。'
      ),
      JSON_OBJECT(
        'metric', '详情进入率',
        'formula', 'detail_uv / list_uv 或 detail_clicks / list_impressions',
        'raw_fields', '列表访问、详情访问',
        'granularity', '小时、日、周、月',
        'note', '用于替代无法直接拿到 CTR 的场景。属于内部推荐口径。'
      ),
      JSON_OBJECT(
        'metric', '下单转化率',
        'formula', 'created_orders / clicks 或 created_orders / detail_uv',
        'raw_fields', '点击或详情 UV、创建订单数',
        'granularity', '日、周、月',
        'note', '建议同时保留“点击口径”和“UV 口径”，便于区分页面问题与交易问题。'
      ),
      JSON_OBJECT(
        'metric', '支付转化率',
        'formula', 'paid_orders / created_orders',
        'raw_fields', '创建订单、支付成功订单',
        'granularity', '小时、日、周、月',
        'note', '适合用来监测价格变更、库存不足、支付失败与确认失败。'
      )
    )
  ),
  NOW()
WHERE @ota_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_metric_unit_id,
  '交易收益指标',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT(
        'metric', '订单量',
        'formula', 'count(distinct paid_order_id)',
        'raw_fields', 'orderId/reservation_id、状态',
        'granularity', '小时、日、周、月',
        'note', '需统一“创建”“支付”“确认”“完成”“取消”状态机。'
      ),
      JSON_OBJECT(
        'metric', '间夜量',
        'formula', 'sum(room_num * datediff(checkout, checkin))',
        'raw_fields', '入住日期、离店日期、间数',
        'granularity', '日、周、月、入住月',
        'note', '最核心酒店交易量口径之一。'
      ),
      JSON_OBJECT(
        'metric', '收入',
        'formula', 'sum(room_revenue)；若暂无房费收入则 sum(paid_amount)',
        'raw_fields', '日历价、总价、支付金额、币种',
        'granularity', '日、周、月',
        'note', '建议拆成“订单 GMV”和“房费收入”，避免把餐饮/附加产品/税费混进房费。'
      ),
      JSON_OBJECT(
        'metric', '客单价 AOV',
        'formula', 'revenue / orders',
        'raw_fields', '收入、支付订单数',
        'granularity', '日、周、月',
        'note', 'Google Ads 将 average order value 定义为订单总收入 ÷ 订单数。'
      ),
      JSON_OBJECT(
        'metric', 'ARPU',
        'formula', 'revenue / active_users',
        'raw_fields', '总收入、活跃用户数',
        'granularity', '日、周、月',
        'note', 'Google Analytics 明确给出 ARPU 为平均每活跃用户收入。'
      ),
      JSON_OBJECT(
        'metric', '取消率',
        'formula', 'cancelled_orders / created_orders，或 cancelled_room_nights / booked_room_nights',
        'raw_fields', '订单状态、取消状态、间夜量',
        'granularity', '日、周、月',
        'note', '建议同时做“订单取消率”和“间夜取消率”。取消预测更应以间夜损失衡量。'
      )
    )
  ),
  NOW()
WHERE @ota_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_metric_unit_id,
  '酒店收益管理指标',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT(
        'metric', 'ADR',
        'formula', 'room_revenue / rooms_sold',
        'raw_fields', '房费收入、售出客房数',
        'granularity', '日、周、月、入住月',
        'note', 'STR/CoStar 和 Oracle 的标准公式均以 room revenue ÷ rooms sold/occupied rooms 为核心。'
      ),
      JSON_OBJECT(
        'metric', '入住率',
        'formula', 'occupied_rooms / available_rooms',
        'raw_fields', '已售客房、可售房量',
        'granularity', '日、周、月、入住月',
        'note', 'STR 明确为 occupied rooms ÷ available rooms。只有拿到全店可售房量时才能算“全店入住率”；否则建议命名为“OTA 售卖率”。'
      ),
      JSON_OBJECT(
        'metric', 'RevPAR',
        'formula', 'room_revenue / available_rooms 或 ADR * occupancy',
        'raw_fields', '房费收入、可售房量、ADR、入住率',
        'granularity', '日、周、月、入住月',
        'note', 'RevPAR 是 ADR 与入住率的组合指标。若只有 OTA 数据，请命名为“渠道 RevPAR”。'
      )
    )
  ),
  NOW()
WHERE @ota_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_metric_unit_id,
  '用户与营销指标',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT(
        'metric', '复购率',
        'formula', 'repeat_purchasers / purchasers',
        'raw_fields', '用户匿名键、订单历史',
        'granularity', '周、月、季度、Cohort 月',
        'note', '建议做 cohort 复购率而不是自然月复购率，避免季节波动误判。'
      ),
      JSON_OBJECT(
        'metric', '广告投放 ROI',
        'formula', 'attributable_revenue / ad_cost；补充 ROAS、CAC',
        'raw_fields', '广告花费、归因订单、收入',
        'granularity', '日、周、月、活动周期',
        'note', '如果没有广告成本，只能做“站内活动回报”，不能严格叫 ROI。'
      ),
      JSON_OBJECT(
        'metric', 'LTV',
        'formula', 'future_margin or future_revenue per user/cohort',
        'raw_fields', '用户订单序列、留存、间隔、收入、退款',
        'granularity', 'Cohort 周、Cohort 月',
        'note', '更适合作为预测目标而非单期 KPI；应单独区分“历史 LTV”和“预测 LTV”。'
      )
    )
  ),
  NOW()
WHERE @ota_metric_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_metric_unit_id,
  '落地规则',
  JSON_OBJECT(
    'field_mapping_priority', JSON_ARRAY(
      '优先复用 online_daily_data：source、data_type、data_date、amount、quantity、book_order_num、data_value、list_exposure、detail_exposure、flow_rate、order_filling_num、order_submit_num、raw_data。',
      '订单状态、支付状态、取消状态、订单金额、房费收入、间夜和日期等字段先保留在 raw_data，待有明确页面或报表读取后再结构化。',
      'UV、复购、ARPU、LTV 涉及用户或设备匿名键，必须先定义匿名化主键和权限边界。'
    ),
    'calculation_guardrails', JSON_ARRAY(
      '分母为空或为 0 时返回不可计算状态，不返回 0 冒充真实结果。',
      '所有指标必须记录口径：创建口径、支付口径、确认口径、入住口径、取消口径。',
      '全店指标和 OTA 渠道指标必须分开命名，避免渠道数据冒充全店经营数据。',
      '收入必须区分订单 GMV、房费收入、含税/不含税、附加产品收入。'
    ),
    'recommended_data_type', JSON_OBJECT(
      'traffic', '曝光、UV、PV、CTR、详情进入率、下单转化率、支付转化率',
      'order', '订单量、间夜量、收入、AOV、取消率',
      'revenue', 'ADR、入住率、RevPAR',
      'user', '复购率、ARPU、LTV',
      'advertising', 'ROI、ROAS、CAC'
    )
  ),
  NOW()
WHERE @ota_metric_unit_id IS NOT NULL;

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

SET @staff_knowledge_title := @ota_metric_unit_name;
SET @staff_knowledge_content := CONCAT(
  '# OTA标准指标与推荐公式清单', '\n\n',
  '> 来源说明：按 2026-05-20 输入资料整理；本条目未在导入时重新联网核验。落地前必须以当前平台后台、官方文档、接口响应和项目字段为准。', '\n\n',
  '## 使用边界', '\n',
  '- 平台只有 OTA 局部数据时，指标名称必须带渠道限定，例如“OTA 售卖率”“渠道 RevPAR”。', '\n',
  '- 缺少广告成本时，不能严格命名为 ROI，可命名为“站内活动回报”。', '\n',
  '- 缺少全店可售房量时，不能计算全店入住率。', '\n',
  '- 分母为空或为 0 时返回不可计算状态，不写假值。', '\n\n',
  '## 指标清单', '\n',
  '| 标准指标 | 推荐公式 | 关键原始字段 | 建议粒度 | 口径备注 |', '\n',
  '| --- | --- | --- | --- | --- |', '\n',
  '| 曝光 | `impression_count` | 曝光日志、排名位次、渠道、设备 | 小时、日、周、月 | 携程数据中心更可能直接提供；美团公开 TMC 文档未直接给曝光计数，若无商家报表需补埋点或额外导出。补数需求来自平台能力差异。 |', '\n',
  '| UV | `count(distinct user_id/device_id/session_user_key)` | 访问日志、用户/设备匿名键 | 小时、日、周、月 | Google Analytics 将 users 定义为在指定时间内触发事件的唯一用户；若无平台用户 ID，建议退化为设备或会话匿名键。 |', '\n',
  '| PV | `page_view_count` | 页面浏览日志、详情页浏览日志 | 小时、日、周、月 | GA 将 pageview 定义为被追踪页面的查看次数，重复查看会重复计数。 |', '\n',
  '| CTR | `clicks / impressions` | 点击数、曝光数 | 小时、日、周、月 | Google Ads 的 CTR 定义就是点击 ÷ 展现。 |', '\n',
  '| 详情进入率 | `detail_uv / list_uv` 或 `detail_clicks / list_impressions` | 列表访问、详情访问 | 小时、日、周、月 | 用于替代无法直接拿到 CTR 的场景。属于内部推荐口径。 |', '\n',
  '| 下单转化率 | `created_orders / clicks` 或 `created_orders / detail_uv` | 点击或详情 UV、创建订单数 | 日、周、月 | 建议同时保留“点击口径”和“UV 口径”，便于区分页面问题与交易问题。 |', '\n',
  '| 支付转化率 | `paid_orders / created_orders` | 创建订单、支付成功订单 | 小时、日、周、月 | 适合用来监测价格变更、库存不足、支付失败与确认失败。 |', '\n',
  '| 订单量 | `count(distinct paid_order_id)` | `orderId/reservation_id`、状态 | 小时、日、周、月 | 需统一“创建”“支付”“确认”“完成”“取消”状态机。 |', '\n',
  '| 间夜量 | `sum(room_num * datediff(checkout, checkin))` | 入住日期、离店日期、间数 | 日、周、月、入住月 | 最核心酒店交易量口径之一。 |', '\n',
  '| 收入 | `sum(room_revenue)`；若暂无房费收入则 `sum(paid_amount)` | 日历价、总价、支付金额、币种 | 日、周、月 | 建议拆成“订单 GMV”和“房费收入”，避免把餐饮/附加产品/税费混进房费。 |', '\n',
  '| 客单价 AOV | `revenue / orders` | 收入、支付订单数 | 日、周、月 | Google Ads 将 average order value 定义为订单总收入 ÷ 订单数。 |', '\n',
  '| ARPU | `revenue / active_users` | 总收入、活跃用户数 | 日、周、月 | Google Analytics 明确给出 ARPU 为平均每活跃用户收入。 |', '\n',
  '| 取消率 | `cancelled_orders / created_orders`，或 `cancelled_room_nights / booked_room_nights` | 订单状态、取消状态、间夜量 | 日、周、月 | 建议同时做“订单取消率”和“间夜取消率”。取消预测更应以间夜损失衡量。 |', '\n',
  '| ADR | `room_revenue / rooms_sold` | 房费收入、售出客房数 | 日、周、月、入住月 | STR/CoStar 和 Oracle 的标准公式均以 room revenue ÷ rooms sold/occupied rooms 为核心。 |', '\n',
  '| 入住率 | `occupied_rooms / available_rooms` | 已售客房、可售房量 | 日、周、月、入住月 | STR 明确为 occupied rooms ÷ available rooms。只有拿到全店可售房量时才能算“全店入住率”；否则建议命名为“OTA 售卖率”。 |', '\n',
  '| RevPAR | `room_revenue / available_rooms` 或 `ADR * occupancy` | 房费收入、可售房量、ADR、入住率 | 日、周、月、入住月 | RevPAR 是 ADR 与入住率的组合指标。若只有 OTA 数据，请命名为“渠道 RevPAR”。 |', '\n',
  '| 复购率 | `repeat_purchasers / purchasers` | 用户匿名键、订单历史 | 周、月、季度、Cohort 月 | 建议做 cohort 复购率而不是自然月复购率，避免季节波动误判。 |', '\n',
  '| 广告投放 ROI | `attributable_revenue / ad_cost`；补充 ROAS、CAC | 广告花费、归因订单、收入 | 日、周、月、活动周期 | 如果没有广告成本，只能做“站内活动回报”，不能严格叫 ROI。 |', '\n',
  '| LTV | `future_margin or future_revenue per user/cohort` | 用户订单序列、留存、间隔、收入、退款 | Cohort 周、Cohort 月 | 更适合作为预测目标而非单期 KPI；应单独区分“历史 LTV”和“预测 LTV”。 |', '\n\n',
  '## 落地规则', '\n',
  '- 优先复用 `online_daily_data`：`source`、`data_type`、`data_date`、`amount`、`quantity`、`book_order_num`、`data_value`、`list_exposure`、`detail_exposure`、`flow_rate`、`order_filling_num`、`order_submit_num`、`raw_data`。', '\n',
  '- 订单状态、支付状态、取消状态、订单金额、房费收入、间夜和日期先保留在 `raw_data`，待明确被读取后再结构化。', '\n',
  '- UV、复购、ARPU、LTV 涉及用户或设备匿名键，必须先定义匿名化主键和权限边界。', '\n',
  '- 所有指标必须记录口径：创建口径、支付口径、确认口径、入住口径、取消口径。'
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
  'OTA,标准指标,指标公式,曝光,UV,PV,CTR,转化率,ADR,RevPAR,LTV,ROI',
  JSON_ARRAY('OTA', '标准指标', '指标公式', '流量漏斗', '收益管理', '转化率'),
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
  `keywords` = 'OTA,标准指标,指标公式,曝光,UV,PV,CTR,转化率,ADR,RevPAR,LTV,ROI',
  `tags` = JSON_ARRAY('OTA', '标准指标', '指标公式', '流量漏斗', '收益管理', '转化率'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title;
