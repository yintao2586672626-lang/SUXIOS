-- Seed optimized OTA manual/automatic collection strategy into the project knowledge systems.
-- This is content-only: no business tables or OTA fields are added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_strategy_unit_name := 'OTA手动与自动获取策略';
SET @ota_strategy_source := 'ota';
SET @ota_strategy_description := '按业务实际重新整理携程和美团 OTA 数据获取策略：手动获取与自动获取是两条不同路线，携程和美团也按平台差异分别处理，非必要能力降级为可选。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ota_strategy_unit_name,
  @ota_strategy_source,
  'done',
  @ota_strategy_description,
  JSON_ARRAY('OTA', '手动获取', '自动获取', '携程', '美团', '采集策略', 'online_daily_data'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_strategy_unit_name AND `source` = @ota_strategy_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ota_strategy_description,
  `tags` = JSON_ARRAY('OTA', '手动获取', '自动获取', '携程', '美团', '采集策略', 'online_daily_data'),
  `updated_at` = NOW()
WHERE `name` = @ota_strategy_unit_name AND `source` = @ota_strategy_source;

SET @ota_strategy_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_strategy_unit_name AND `source` = @ota_strategy_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ota_strategy_unit_id
  AND `type` IN ('目标与边界', '手动获取', '自动获取', '携程差异', '美团差异', '落库与非必要项');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_strategy_unit_id,
  '目标与边界',
  JSON_OBJECT(
    'goal', '携程和美团都要支持手动获取和自动获取，但两条路径不能混用概念，也不能把两个平台写成同一种实现。',
    'manual_summary', '用户提供平台上下文、导出文件、Cookie/Payload 或必要 ID；系统校验、抓取/导入、清洗、脱敏、去重和入库。',
    'automatic_summary', '系统复用授权门店的独立浏览器 Profile，失效时等待人工登录，监听页面真实业务 JSON，并按模块保存。',
    'boundary', JSON_ARRAY('手动路径不要求系统自动登录 OTA 后台', '自动路径不要求用户每次复制 Cookie/Payload', '不绕过短信、滑块、人机验证或平台权限', '空数据和失败必须显式返回原因')
  ),
  NOW()
WHERE @ota_strategy_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_strategy_unit_id,
  '手动获取',
  JSON_OBJECT(
    'scenarios', JSON_ARRAY('临时补数', '首次接入', '平台改版排障', '自动采集失效后的补录', '用户已导出报表或已取得请求上下文'),
    'inputs', JSON_ARRAY('平台', '系统酒店', '日期范围', '数据模块', '导出文件', 'Cookie/Payload', '平台酒店或门店 ID', '必要动态参数'),
    'system_actions', JSON_ARRAY('校验必填字段', '调用现有兼容接口或导入解析', '清洗标准化', '敏感字段脱敏', '去重', '写入 online_daily_data/raw_data'),
    'not_doing', JSON_ARRAY('不启动浏览器 Profile', '不自动登录 OTA 后台', '不猜缺失 token 或动态签名', '不把样例数据当实时经营数据')
  ),
  NOW()
WHERE @ota_strategy_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_strategy_unit_id,
  '自动获取',
  JSON_OBJECT(
    'scenarios', JSON_ARRAY('日常日报', '房态价量巡检', '运营看板', '实时监控', '自动预警'),
    'profile_rule', JSON_OBJECT('ctrip', 'storage/ctrip_profile_{store_id}', 'meituan', 'storage/meituan_profile_{store_id}'),
    'system_actions', JSON_ARRAY('按门店复用已授权 Profile', '登录失效时返回 needs_login 并等待人工完成验证', '按业务模块打开页面', '监听 XHR/fetch JSON 响应', '按模块清洗入库'),
    'not_doing', JSON_ARRAY('不绕过验证码或短信', '不采集非授权门店', '不抓菜单导航文本当经营数据', '不把截图/OCR作为常规数据源')
  ),
  NOW()
WHERE @ota_strategy_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_strategy_unit_id,
  '携程差异',
  JSON_OBJECT(
    'manual_priority', JSON_ARRAY('eBooking 导出报表', 'Trip Connect/API 或导出结果', 'Cookie + spidertoken + node_id/hotel_id + Payload'),
    'automatic_priority', JSON_ARRAY('经营概况', '流量', '订单', '点评', '房态房价/ARI'),
    'optional_modules', JSON_ARRAY('广告', '分渠道评分', '更深层竞对或市场热度'),
    'notes', JSON_ARRAY('携程可结合 eBooking 和 Trip Connect，不必所有模块都走浏览器监听', 'DOM 只补页面已展示的排名或摘要', 'ARI/房态房价先以导出/API 或 raw_data 保存为主')
  ),
  NOW()
WHERE @ota_strategy_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_strategy_unit_id,
  '美团差异',
  JSON_OBJECT(
    'manual_priority', JSON_ARRAY('TMC/直连平台导出', '产品与价格库存导出', '订单日志和订单监控', 'Cookie/Session + partner_id + poi_id/store_id + Payload + 必要动态签名'),
    'automatic_priority', JSON_ARRAY('点评', '数据中心/流量', '订单/入住管理', '价格库存/直连产品'),
    'optional_modules', JSON_ARRAY('广告投放', '更深层排名诊断', 'OCR排障'),
    'notes', JSON_ARRAY('美团 iframe、SPA、签名和登录态变化更频繁，自动路径优先浏览器响应监听', '手动路径只处理用户已提供上下文，不后台代登录', '订单日志、监控和直连产品是美团业务中更实际的优先数据')
  ),
  NOW()
WHERE @ota_strategy_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_strategy_unit_id,
  '落库与非必要项',
  JSON_OBJECT(
    'storage_boundary', JSON_ARRAY('优先写入 online_daily_data', '通过 source/data_type/dimension/data_date/raw_data 区分平台和模块', '订单号、点评 ID、房型价型产品 ID、库存快照、接口耗时先进入脱敏 raw_data'),
    'structured_field_rule', '只有页面、报表、预警、AI 分析或模型训练明确读取时，才新增结构化字段或表。',
    'deprioritized', JSON_ARRAY('广告在无成本和账号权限时不作为 P0', 'LTV 和深度用户合并不作为 OTA 采集首要目标', 'OCR 只作为排障，不作为常规采集', '不默认新增 reviews/orders/traffic_data 表')
  ),
  NOW()
WHERE @ota_strategy_unit_id IS NOT NULL;

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

SET @staff_knowledge_title := @ota_strategy_unit_name;
SET @staff_knowledge_content := CONCAT(
  '# OTA手动与自动获取策略', '\n\n',
  '## 核心目标', '\n',
  '携程和美团都要支持手动获取和自动获取，但不能把两条路径混用，也不能把两个平台写成同一种方式。', '\n\n',
  '## 路线区分', '\n',
  '| 路径 | 适用场景 | 用户提供 | 系统动作 | 不做什么 |', '\n',
  '| --- | --- | --- | --- | --- |', '\n',
  '| 手动获取 | 临时补数、首次接入、平台改版排障、自动采集失效后补录 | 导出文件、Cookie/Payload、平台 ID、日期、模块 | 校验、抓取/导入、清洗、脱敏、去重、入库 | 不自动登录 OTA，不启动 Profile，不猜缺失 token |', '\n',
  '| 自动获取 | 日常日报、巡检、看板、预警 | 已授权账号、门店 Profile、系统酒店绑定 | 打开平台页面、等待必要人工登录、监听 JSON、按模块保存 | 不绕过验证，不采集非授权门店，不抓导航菜单当数据 |', '\n\n',
  '## 携程', '\n',
  '- 手动优先：eBooking 导出、Trip Connect/API、Cookie + spidertoken + node_id/hotel_id + Payload。', '\n',
  '- 自动优先：经营概况、流量、订单、点评、房态房价/ARI。', '\n',
  '- 广告、分渠道评分、深层竞对只在明确业务需要时接入。', '\n\n',
  '## 美团', '\n',
  '- 手动优先：TMC/直连平台导出、产品价格库存、订单日志、订单监控、Cookie/Session + partner_id + poi_id/store_id + Payload。', '\n',
  '- 自动优先：点评、数据中心/流量、订单/入住管理、价格库存/直连产品。', '\n',
  '- 美团 iframe、SPA 和动态签名变化更频繁，自动路径优先浏览器响应监听；手动路径不后台代登录。', '\n\n',
  '## 落库边界', '\n',
  '- 优先写入 `online_daily_data`，用 `source/data_type/dimension/data_date/raw_data` 区分平台和模块。', '\n',
  '- 订单号、点评 ID、产品 ID、库存快照、接口耗时先进入脱敏 `raw_data`。', '\n',
  '- 只有明确功能读取时，才新增结构化字段或表。', '\n',
  '- 广告、LTV、深度用户合并、OCR、宽泛新表默认不是 P0。'
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
  'OTA,手动获取,自动获取,携程,美团,采集策略,online_daily_data',
  JSON_ARRAY('OTA', '手动获取', '自动获取', '携程', '美团', '采集策略'),
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
  `keywords` = 'OTA,手动获取,自动获取,携程,美团,采集策略,online_daily_data',
  `tags` = JSON_ARRAY('OTA', '手动获取', '自动获取', '携程', '美团', '采集策略'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title;
