-- Seed Ctrip browser capture method into the project knowledge systems.
-- This is content-only: no business tables or OTA fields are added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_capture_unit_name := '携程商家后台浏览器自动化采集方法';
SET @ctrip_capture_source := 'ctrip';
SET @ctrip_capture_description := '在合法授权账号下，通过独立浏览器 Profile 登录携程商家后台，监听页面 XHR/fetch JSON 响应，按宿析OS现有 online_daily_data 字段清洗、去重、入库。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ctrip_capture_unit_name,
  @ctrip_capture_source,
  'done',
  @ctrip_capture_description,
  JSON_ARRAY('OTA', '携程', '浏览器自动化', '数据采集', 'online_daily_data'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ctrip_capture_unit_name AND `source` = @ctrip_capture_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ctrip_capture_description,
  `tags` = JSON_ARRAY('OTA', '携程', '浏览器自动化', '数据采集', 'online_daily_data'),
  `updated_at` = NOW()
WHERE `name` = @ctrip_capture_unit_name AND `source` = @ctrip_capture_source;

SET @ctrip_capture_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ctrip_capture_unit_name AND `source` = @ctrip_capture_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ctrip_capture_unit_id
  AND `type` IN ('采集方法', '字段映射', '稳定性与合规');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ctrip_capture_unit_id,
  '采集方法',
  JSON_OBJECT(
    'title', @ctrip_capture_unit_name,
    'summary', '真实浏览器登录携程商家后台，复用门店独立 Profile，按页面触发接口，监听 XHR/fetch JSON 响应并解析入库。',
    'profile_rule', '每个门店使用独立 storage/ctrip_profile_{store_id}，避免 Cookie 串用；Profile 和 Cookie 不进入 Git。',
    'login_flow', JSON_ARRAY('复用本地 Profile Cookie', '打开携程商家后台检查登录态', '登录失效时自动登录', '短信、滑块、人机验证交给人工确认', '登录成功后继续采集并保留 Profile'),
    'response_filter', JSON_ARRAY('资源类型为 XHR 或 fetch', 'HTTP 状态码为 200', '返回体可解析为 JSON', 'URL 命中明确业务规则'),
    'match_keywords', JSON_ARRAY('getCommentList', 'queryScanFlowDetailsV2', 'fetchMarketOverViewV2', 'getDayReportServerQuantity', 'queryOrderList', 'promotion', 'pyramidad')
  ),
  NOW()
WHERE @ctrip_capture_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ctrip_capture_unit_id,
  '字段映射',
  JSON_OBJECT(
    'target_table', 'online_daily_data',
    'no_new_tables', JSON_ARRAY('reviews', 'orders', 'traffic_data'),
    'overview', 'amount=成交金额，quantity=间夜数，book_order_num=订单数，comment_score=携程评分，排名和 PSI 进入 raw_data。',
    'review', 'data_type=review，评分进入 comment_score/data_value，点评 ID、内容、回复、房型、入住日期、评价时间进入 raw_data。',
    'traffic', 'data_type=traffic，使用 list_exposure、detail_exposure、flow_rate、order_filling_num、order_submit_num，排名和细节进入 raw_data。',
    'order', 'data_type=order，金额进入 amount，间夜进入 quantity，订单数进入 book_order_num，平均房价进入 data_value，订单明细进入 raw_data。',
    'advertising', 'data_type=advertising，曝光、点击、转化优先使用现有流量字段，费用、计划、ROI 进入 raw_data。',
    'new_field_rule', '只有现有字段无法支持保存、回显、编辑、分析或去重，并且有明确功能读取时，才新增结构化字段。'
  ),
  NOW()
WHERE @ctrip_capture_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ctrip_capture_unit_id,
  '稳定性与合规',
  JSON_OBJECT(
    'json_first', '接口 JSON 优先，DOM 只用于补充排名、摘要或页面展示指标。',
    'raw_retention', '关键接口保留脱敏 raw_data，便于排查字段变化、对账和数据修复。',
    'dedupe', '点评按评价 ID 去重；订单按订单号去重；汇总按酒店、来源、类型、维度和日期更新。',
    'sensitive_data', 'Cookie、spidertoken、Profile、账号密码、手机号明文不写入文档、日志或 Git。',
    'delay_policy', '日报优先采集昨日完整数据；平台延迟时标记暂缺或异常，不写错误数据。',
    'verification', JSON_ARRAY('确认采集行能被 OTA 列表读取', '确认 AI 诊断和收益分析仍可读取', '确认空数据和登录失效有可解释状态')
  ),
  NOW()
WHERE @ctrip_capture_unit_id IS NOT NULL;

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

SET @staff_knowledge_title := @ctrip_capture_unit_name;
SET @staff_knowledge_content := CONCAT(
  '# 携程商家后台浏览器自动化采集方法', '\n\n',
  '## 核心原则', '\n',
  '- 只采集酒店自身合法授权账号下可见数据。', '\n',
  '- 使用真实浏览器登录和门店独立 Profile，优先监听页面自动返回的 XHR/fetch JSON。', '\n',
  '- 字段以宿析OS现有项目为准，统一优先落 `online_daily_data`，不新增 `reviews`、`orders`、`traffic_data` 表。', '\n',
  '- Cookie、spidertoken、Profile、账号密码和手机号明文不得进入文档、日志或 Git。', '\n\n',
  '## 推荐流程', '\n',
  '1. 为每个门店建立 `storage/ctrip_profile_{store_id}`。', '\n',
  '2. 复用登录态，失效时登录，短信、滑块、人机验证由人工确认。', '\n',
  '3. 依次打开点评、流量、订单、广告、昨日概况等业务页面。', '\n',
  '4. 只处理 XHR/fetch、HTTP 200、可解析 JSON、URL 命中规则的响应。', '\n',
  '5. JSON 优先，DOM 只补排名或页面摘要，关键原始结构脱敏后进入 `raw_data`。', '\n\n',
  '## 字段映射', '\n',
  '- 经营概况：`amount`、`quantity`、`book_order_num`、`comment_score`、`raw_data`。', '\n',
  '- 点评：`data_type=review`，评分进 `comment_score/data_value`，点评详情进 `raw_data`。', '\n',
  '- 流量：`data_type=traffic`，使用 `list_exposure/detail_exposure/flow_rate/order_filling_num/order_submit_num`。', '\n',
  '- 订单：`data_type=order`，金额进 `amount`，间夜进 `quantity`，订单数进 `book_order_num`。', '\n',
  '- 广告：`data_type=advertising`，曝光、点击、转化用现有流量字段，费用和 ROI 进 `raw_data`。', '\n\n',
  '## 新增字段条件', '\n',
  '只有现有字段和 `raw_data` 无法支持保存、回显、编辑、分析或去重，并且有明确功能读取时，才新增结构化字段，同时补迁移、旧数据兼容和空值兜底。'
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
  'OTA,携程,浏览器自动化,接口监听,online_daily_data',
  JSON_ARRAY('OTA', '携程', '浏览器自动化', '数据采集', 'online_daily_data'),
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
  `keywords` = 'OTA,携程,浏览器自动化,接口监听,online_daily_data',
  `tags` = JSON_ARRAY('OTA', '携程', '浏览器自动化', '数据采集', 'online_daily_data'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title;
