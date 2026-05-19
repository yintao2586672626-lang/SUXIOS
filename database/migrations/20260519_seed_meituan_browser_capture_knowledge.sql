-- Seed Meituan browser capture method into the project knowledge systems.
-- This is content-only: no business tables or OTA fields are added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @meituan_capture_unit_name := '美团 eBooking 浏览器自动化采集方法';
SET @meituan_capture_source := 'meituan';
SET @meituan_capture_description := '在合法授权账号下，通过独立浏览器 Profile 登录美团 eBooking，监听页面 XHR/fetch JSON 响应，按宿析OS现有 online_daily_data 字段清洗、去重、入库。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @meituan_capture_unit_name,
  @meituan_capture_source,
  'done',
  @meituan_capture_description,
  JSON_ARRAY('OTA', '美团', 'eBooking', '浏览器自动化', '数据采集', 'online_daily_data'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @meituan_capture_unit_name AND `source` = @meituan_capture_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @meituan_capture_description,
  `tags` = JSON_ARRAY('OTA', '美团', 'eBooking', '浏览器自动化', '数据采集', 'online_daily_data'),
  `updated_at` = NOW()
WHERE `name` = @meituan_capture_unit_name AND `source` = @meituan_capture_source;

SET @meituan_capture_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @meituan_capture_unit_name AND `source` = @meituan_capture_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @meituan_capture_unit_id
  AND `type` IN ('采集方法', '字段映射', '稳定性与合规');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @meituan_capture_unit_id,
  '采集方法',
  JSON_OBJECT(
    'title', @meituan_capture_unit_name,
    'summary', '真实浏览器登录美团 eBooking，复用门店独立 Profile，按点评、流量、广告、订单页面触发接口，监听 XHR/fetch JSON 响应并解析入库。',
    'profile_rule', '每个门店使用独立 storage/meituan_profile_{store_id}，Profile 和 Cookie 不进入 Git。',
    'login_flow', JSON_ARRAY('复用本地 Profile Cookie', '打开美团 eBooking 登录入口检查登录态', '登录失效时弹出浏览器等待人工登录', '登录成功后自动继续采集', '登录态保留供下次复用'),
    'page_order', JSON_ARRAY('点评管理页', '数据中心 iframe', 'newhb 流量 SPA', '可选广告页', '订单/入住管理页'),
    'response_filter', JSON_ARRAY('XHR 或 fetch 响应', 'HTTP 200', '返回体可解析为 JSON', 'URL 命中明确业务规则'),
    'match_keywords', JSON_OBJECT(
      'reviews', JSON_ARRAY('queryGeneralCommentInfo', 'commentsInfo', 'comments/statistics'),
      'traffic', JSON_ARRAY('businessData', 'traffic', 'peerTrends'),
      'ads', JSON_ARRAY('cureShops'),
      'orders', JSON_ARRAY('/orders/list', '/order/unhandled/count')
    )
  ),
  NOW()
WHERE @meituan_capture_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @meituan_capture_unit_id,
  '字段映射',
  JSON_OBJECT(
    'target_table', 'online_daily_data',
    'no_new_tables', JSON_ARRAY('reviews', 'orders', 'traffic_data'),
    'review', 'data_type=review，comment_score/data_value 保存评分，点评 ID、内容、回复、是否差评、点评人、房型、入住时间、标签进入 raw_data。',
    'traffic', 'data_type=traffic，list_exposure 保存曝光，detail_exposure 保存浏览或点击，flow_rate 保存转化率，搜索/品类/关键词排名进入 raw_data。',
    'advertising', 'data_type=advertising，曝光、点击、转化沿用流量字段，广告计划、关键词、ROI 进入 raw_data。',
    'order', 'data_type=order，amount 保存订单金额，quantity 保存 room_count*nights，book_order_num 保存订单数，data_value 保存平均房价，订单详情进入 raw_data。',
    'new_field_rule', '只有现有字段和 raw_data 无法支持保存、回显、编辑、分析或去重，并且有明确功能读取时，才新增结构化字段。'
  ),
  NOW()
WHERE @meituan_capture_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @meituan_capture_unit_id,
  '稳定性与合规',
  JSON_OBJECT(
    'json_first', '接口 JSON 优先，DOM/HTML 只用于页面展示指标、摘要或排名兜底。',
    'raw_retention', '关键接口保留脱敏 raw_data，便于字段变化排查、对账和修复。',
    'dedupe', '点评按评价 ID 去重，订单按订单号去重，流量和广告按酒店、来源、类型、维度和日期更新。',
    'sensitive_data', 'Cookie、Profile、账号密码、手机号明文、截图和含敏感数据的输出不进入 Git。',
    'failure_policy', '登录超时、接口未命中、空数据必须显式返回，不写假数据冒充真实经营结果。',
    'verification', JSON_ARRAY('确认 capture-meituan-browser 可启动脚本', '确认首次登录和已登录 Profile 均可执行', '确认保存行可被 OTA 历史、AI 诊断、收益分析和经营预警读取')
  ),
  NOW()
WHERE @meituan_capture_unit_id IS NOT NULL;

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

SET @staff_knowledge_title := @meituan_capture_unit_name;
SET @staff_knowledge_content := CONCAT(
  '# 美团 eBooking 浏览器自动化采集方法', '\n\n',
  '## 核心原则', '\n',
  '- 只采集酒店自身合法授权账号下可见数据。', '\n',
  '- 使用真实浏览器登录和门店独立 Profile，优先监听页面自动返回的 XHR/fetch JSON。', '\n',
  '- 字段以宿析OS现有项目为准，统一优先落 `online_daily_data`，不新增 `reviews`、`orders`、`traffic_data` 表。', '\n',
  '- Cookie、Profile、账号密码、手机号明文和截图不得进入文档、日志或 Git。', '\n\n',
  '## 推荐流程', '\n',
  '1. 每个门店使用 `storage/meituan_profile_{store_id}`。', '\n',
  '2. 在“美团ebooking数据获取 -> 浏览器抓取”点击“开始抓取并入库”。', '\n',
  '3. 首次弹出美团窗口时完成人工登录，脚本自动等待登录态并继续采集。', '\n',
  '4. 依次打开点评、流量、newhb 流量、广告、订单页面。', '\n',
  '5. 只处理命中业务规则的 JSON 响应；DOM/HTML 只做兜底。', '\n\n',
  '## 字段映射', '\n',
  '- 点评：`data_type=review`，评分进入 `comment_score/data_value`，点评详情进入 `raw_data`。', '\n',
  '- 流量：`data_type=traffic`，使用 `list_exposure/detail_exposure/flow_rate`，排名和关键词进入 `raw_data`。', '\n',
  '- 广告：`data_type=advertising`，曝光、点击、转化沿用流量字段，计划和 ROI 进入 `raw_data`。', '\n',
  '- 订单：`data_type=order`，金额进入 `amount`，间夜进入 `quantity`，订单数进入 `book_order_num`，均价进入 `data_value`。', '\n\n',
  '## 失败处理', '\n',
  '登录超时、接口未命中、空数据必须明确返回；不得把错误页面、菜单文本或空指标写成真实经营数据。'
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
  'OTA,美团,eBooking,浏览器自动化,接口监听,online_daily_data',
  JSON_ARRAY('OTA', '美团', 'eBooking', '浏览器自动化', '数据采集', 'online_daily_data'),
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
  `keywords` = 'OTA,美团,eBooking,浏览器自动化,接口监听,online_daily_data',
  `tags` = JSON_ARRAY('OTA', '美团', 'eBooking', '浏览器自动化', '数据采集', 'online_daily_data'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title;
