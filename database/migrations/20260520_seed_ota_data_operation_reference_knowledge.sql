-- Seed OTA data-operation reference knowledge into the project knowledge systems.
-- This is content-only: no crawler, business table, interface, or OTA field is added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_data_ops_unit_name := 'OTA数据操作与开源参考知识库';
SET @ota_data_ops_source := 'ota';
SET @ota_data_ops_description := '按 2026-05-20 GitHub 检索结果整理酒店 OTA 数据处理、指标分析、可视化和知识库沉淀参考；明确排除点评抓取和评论内容采集，只操作已有、授权或导入的结构化经营数据。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ota_data_ops_unit_name,
  @ota_data_ops_source,
  'done',
  @ota_data_ops_description,
  JSON_ARRAY('OTA', '数据操作', '开源参考', '知识库', '收益分析', '可视化', '不抓点评'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_data_ops_unit_name AND `source` = @ota_data_ops_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ota_data_ops_description,
  `tags` = JSON_ARRAY('OTA', '数据操作', '开源参考', '知识库', '收益分析', '可视化', '不抓点评'),
  `updated_at` = NOW()
WHERE `name` = @ota_data_ops_unit_name AND `source` = @ota_data_ops_source;

SET @ota_data_ops_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_data_ops_unit_name AND `source` = @ota_data_ops_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ota_data_ops_unit_id
  AND `type` IN ('使用边界', '开源参考分类', '宿析OS可整合部分', '落地优先级', '验证规则');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_data_ops_unit_id,
  '使用边界',
  JSON_OBJECT(
    'title', @ota_data_ops_unit_name,
    'scope', '只处理已有、授权或人工导入的 OTA 经营数据，沉淀字段口径、指标公式、清洗规则、分析模板、可视化样式和 Agent 可检索知识。',
    'excluded', JSON_ARRAY(
      '不建设点评抓取链路。',
      '不抓取大众点评、携程点评、美团点评等评论内容。',
      '不把开源爬虫直接集成到宿析OS。',
      '不新增业务表、接口、页面或抓取脚本。',
      '不根据仓库 README 编造未验证字段或效果。'
    ),
    'allowed_inputs', JSON_ARRAY(
      'OTA 后台导出文件。',
      '已入库的 online_daily_data、daily_reports、competitor_analysis、operation_alerts。',
      '授权接口返回或人工校验后的 JSON/CSV/Excel。',
      '项目已有知识库 knowledge_units、knowledge_chunks、knowledge_base。'
    ),
    'target_outputs', JSON_ARRAY(
      '字段映射清单。',
      '清洗与校验规则。',
      '经营指标公式。',
      '收益分析与预测特征。',
      'BI/日报/驾驶舱展示口径。',
      'AI Agent 可检索知识片段。'
    )
  ),
  NOW()
WHERE @ota_data_ops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_data_ops_unit_id,
  '开源参考分类',
  JSON_OBJECT(
    'repositories', JSON_ARRAY(
      JSON_OBJECT(
        'name', 'GlitzOfStenz/HOTEL-BUSINESS-UNDERSTAND-AND-IMPROVE-REVENUE',
        'url', 'https://github.com/GlitzOfStenz/HOTEL-BUSINESS-UNDERSTAND-AND-IMPROVE-REVENUE',
        'quality', '65 stars，Python，近两年更新',
        'category', '收益分析与经营看板',
        'reference_files', JSON_ARRAY('forecasting.py', 'hotel_dashboard.py', 'HOTEL REVENUE POWERBI(2,3,5).pbix', 'Hotel Business Understand and Improve Revenue (Report).pdf'),
        'use_for_suxios', '参考 ADR、RevPAR、入住率、订单来源、预测和 Power BI 看板组织方式。',
        'do_not_use_for', '不作为 OTA 数据获取来源。'
      ),
      JSON_OBJECT(
        'name', '0xAllenChen/scenery_spider_web',
        'url', 'https://github.com/0xAllenChen/scenery_spider_web',
        'quality', '131 stars / 24 forks，Python，2024 更新',
        'category', 'Django + PyEcharts 可视化大屏',
        'reference_files', JSON_ARRAY('mainapp/models.py', 'mainapp/views.py', 'mainapp/static/data/', 'mainapp/static/css/'),
        'use_for_suxios', '只参考已清洗 Excel/结构化数据进入大屏后的视图、模型和图表组织方式。',
        'do_not_use_for', '不复用去哪儿抓取模块。'
      ),
      JSON_OBJECT(
        'name', 'fankcoder/findtrip',
        'url', 'https://github.com/fankcoder/findtrip',
        'quality', '480 stars / 248 forks，Python，2026 更新',
        'category', 'OTA 数据清洗与任务组织',
        'reference_files', JSON_ARRAY('findtrip/findtrip/spiders/washctrip.py', 'findtrip/findtrip/commands/crawlall.py'),
        'use_for_suxios', '仅参考批处理、平台数据清洗和任务分层命名方式。',
        'do_not_use_for', '不复用携程/去哪儿机票采集逻辑。'
      )
    ),
    'selection_rule', '优先选数据清洗、指标分析、可视化、报表、预测相关模块；抓取模块只作为目录组织参考，不作为宿析OS实现。'
  ),
  NOW()
WHERE @ota_data_ops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_data_ops_unit_id,
  '宿析OS可整合部分',
  JSON_OBJECT(
    'data_layer', JSON_ARRAY(
      '复用 online_daily_data.raw_data 保存平台原始结构化数据。',
      '复用 daily_reports 承接经营日报口径。',
      '复用 competitor_analysis 承接竞对价格、评分、排名等结构化指标。',
      '复用 knowledge_units / knowledge_chunks 沉淀字段、公式、规则、来源和验证记录。'
    ),
    'cleaning_rules', JSON_ARRAY(
      '统一 platform、hotel_id、store_id、data_date、data_type、dimension。',
      '金额、佣金、曝光、访问、订单、间夜、取消统一数值类型和单位。',
      '不完整记录进入质量问题清单，不用空数据默认值伪造成有效经营结果。',
      '所有平台字段映射必须能回溯到 raw_data 或导入文件列名。'
    ),
    'analysis_modules', JSON_ARRAY(
      'OTA 经营日报：订单、间夜、GMV、ADR、RevPAR、取消率。',
      '流量漏斗：曝光、访问、浏览、下单、支付转化。',
      '收益分析：房价、入住率、佣金率、渠道占比、价格异常。',
      '预测特征：节假日、星期、价格、库存、历史转化、竞对差价。'
    ),
    'knowledge_outputs', JSON_ARRAY(
      '字段字典。',
      '指标公式。',
      '数据质量规则。',
      '看板口径。',
      'Agent 回答边界。',
      '开源项目参考索引。'
    )
  ),
  NOW()
WHERE @ota_data_ops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_data_ops_unit_id,
  '落地优先级',
  JSON_OBJECT(
    'p0', JSON_ARRAY(
      '把本知识单元导入 knowledge_units / knowledge_chunks / knowledge_base。',
      '梳理已有 online_daily_data 与 daily_reports 可直接支撑的指标。',
      '为每个指标补充来源字段、公式、粒度、缺失处理、验证方式。'
    ),
    'p1', JSON_ARRAY(
      '建立 OTA 导出文件字段映射模板。',
      '建立数据质量校验清单：日期、酒店、平台、金额、订单、间夜、转化率。',
      '把经营日报和收益分析输出沉淀为可检索知识片段。'
    ),
    'p2', JSON_ARRAY(
      '沉淀预测特征模板和异常规则。',
      '按真实数据验证 ADR、RevPAR、取消率、转化率、渠道占比。',
      '再决定是否需要新增结构化字段或表。'
    )
  ),
  NOW()
WHERE @ota_data_ops_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_data_ops_unit_id,
  '验证规则',
  JSON_OBJECT(
    'import_validation', JSON_ARRAY(
      'SQL seed 可重复执行，不产生重复 knowledge_units 或 knowledge_base 条目。',
      'chunk 更新采用先删除指定 type 再插入，避免历史重复片段。',
      'init_full.sql 必须显式 SOURCE 本迁移，保证全量初始化可恢复。'
    ),
    'business_validation', JSON_ARRAY(
      '所有经营结论必须标注来源字段和数据日期。',
      '缺失字段必须返回待补充或不可计算，不写兜底假值。',
      '涉及开源仓库的路径只作为参考索引，不代表已集成或已验证可运行。'
    )
  ),
  NOW()
WHERE @ota_data_ops_unit_id IS NOT NULL;

SET @ota_category_name := 'OTA数据知识';
SET @ota_category_description := 'OTA 数据字段、指标、清洗、经营分析、收益管理和知识库沉淀。';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @ota_category_name,
  @ota_category_description,
  0,
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

SET @staff_knowledge_title := @ota_data_ops_unit_name;
SET @staff_knowledge_content := CONCAT(
  '# OTA数据操作与开源参考知识库', '\n\n',
  '## 使用边界', '\n',
  '- 只操作已有、授权或人工导入的结构化经营数据。', '\n',
  '- 不建设点评抓取链路，不抓取大众点评、携程点评、美团点评等评论内容。', '\n',
  '- 开源仓库只作为数据清洗、指标分析、可视化和知识组织参考，不直接集成爬虫。', '\n\n',
  '## 可参考方向', '\n',
  '| 方向 | 仓库 | 可参考内容 | 不使用内容 |', '\n',
  '| --- | --- | --- | --- |', '\n',
  '| 收益分析 | GlitzOfStenz/HOTEL-BUSINESS-UNDERSTAND-AND-IMPROVE-REVENUE | ADR、RevPAR、入住率、预测、Power BI 看板 | 不作为数据来源 |', '\n',
  '| 可视化大屏 | 0xAllenChen/scenery_spider_web | Django、PyEcharts、结构化 Excel 入图表 | 不复用去哪儿抓取 |', '\n',
  '| 清洗组织 | fankcoder/findtrip | 批处理、清洗脚本、任务分层 | 不复用携程/去哪儿采集 |', '\n\n',
  '## 宿析OS落地', '\n',
  '- 复用 `online_daily_data.raw_data`、`daily_reports`、`competitor_analysis`、`operation_alerts`。', '\n',
  '- 沉淀字段字典、指标公式、清洗规则、质量校验、看板口径和 Agent 回答边界。', '\n',
  '- 经营结论必须能回溯到数据日期、平台、酒店和来源字段。', '\n',
  '- 缺失字段返回待补充或不可计算，不写兜底假值。'
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
  'OTA,数据操作,开源参考,知识库,收益分析,可视化,不抓点评',
  JSON_ARRAY('OTA', '数据操作', '开源参考', '知识库', '收益分析', '不抓点评'),
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
  `keywords` = 'OTA,数据操作,开源参考,知识库,收益分析,可视化,不抓点评',
  `tags` = JSON_ARRAY('OTA', '数据操作', '开源参考', '知识库', '收益分析', '不抓点评'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title;
