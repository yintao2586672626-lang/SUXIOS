-- Seed OTA data architecture and governance rules into the project knowledge systems.
-- This is content-only: no business tables or OTA fields are added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_arch_unit_name := 'OTA数据分层架构与治理规则';
SET @ota_arch_source := 'ota';
SET @ota_arch_description := '按 2026-05-20 输入资料整理 OTA 数据从平台接入、ODS、清洗标准化、DWD、DWS、特征层、模型层到 BI/QA 的分层架构，以及清洗、去重、时间对齐、归因、用户去重、质量监控和对账规则。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ota_arch_unit_name,
  @ota_arch_source,
  'done',
  @ota_arch_description,
  JSON_ARRAY('OTA', '数据架构', '数据治理', 'ODS', 'DWD', 'DWS', '质量监控', '对账'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_arch_unit_name AND `source` = @ota_arch_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ota_arch_description,
  `tags` = JSON_ARRAY('OTA', '数据架构', '数据治理', 'ODS', 'DWD', 'DWS', '质量监控', '对账'),
  `updated_at` = NOW()
WHERE `name` = @ota_arch_unit_name AND `source` = @ota_arch_source;

SET @ota_arch_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_arch_unit_name AND `source` = @ota_arch_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ota_arch_unit_id
  AND `type` IN ('架构总览', '分层职责', '治理规则', '质量监控与对账', '落地边界');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_arch_unit_id,
  '架构总览',
  JSON_OBJECT(
    'title', @ota_arch_unit_name,
    'mermaid', CONCAT(
      'flowchart LR', '\n',
      '    A[美团 TMC 与直连平台<br/>酒店 房型 价格库存 订单日志] --> ODS[ODS 原始层]', '\n',
      '    B[携程 eBooking 与 Trip Connect<br/>内容 ARI 预订 点评 分析导出] --> ODS', '\n',
      '    C[可选补充<br/>PMS CRS 广告成本 节假日表] --> ODS', '\n',
      '    ODS --> CLN[清洗标准化<br/>主键 单位 状态 时间 币种]', '\n',
      '    CLN --> DWD[明细层 DWD<br/>订单 流量 房态 价格 点评 用户]', '\n',
      '    DWD --> DWS[汇总层 DWS<br/>日报 周报 月报 漏斗 竞对]', '\n',
      '    DWD --> FEAT[特征层<br/>需求 取消 LTV 异常]', '\n',
      '    FEAT --> ML[模型层<br/>预测 评分 预警]', '\n',
      '    DWS --> BI[BI 看板 报告 API]', '\n',
      '    ML --> BI', '\n',
      '    CLN --> QA[质量监控<br/>完整性 一致性 新鲜度 SLA]', '\n',
      '    QA --> BI'
    ),
    'sources', JSON_ARRAY(
      '美团 TMC 与直连平台：酒店、房型、价格库存、订单日志。',
      '携程 eBooking 与 Trip Connect：内容、ARI、预订、点评、分析导出。',
      '可选补充：PMS、CRS、广告成本、节假日表。'
    ),
    'main_flow', JSON_ARRAY('ODS 原始层', '清洗标准化', 'DWD 明细层', 'DWS 汇总层', '特征层', '模型层', 'BI 看板/报告/API'),
    'qa_flow', '质量监控从清洗标准化后接入，输出完整性、一致性、新鲜度和 SLA 信号，再进入 BI 或告警。'
  ),
  NOW()
WHERE @ota_arch_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_arch_unit_id,
  '分层职责',
  JSON_OBJECT(
    'layers', JSON_ARRAY(
      JSON_OBJECT(
        'layer', 'ODS 原始层',
        'responsibility', '保留平台原始响应、导出文件、日志与采集上下文；不在本层强行改口径。',
        'typical_data', '美团酒店/房型/价格库存/订单日志，携程内容/ARI/预订/点评/分析导出，PMS/CRS/广告成本/节假日补充。'
      ),
      JSON_OBJECT(
        'layer', '清洗标准化',
        'responsibility', '统一主键、单位、状态、时间、币种和枚举；输出可追溯的标准字段。',
        'typical_checks', '金额非负、币种非空、日期合法、状态可映射。'
      ),
      JSON_OBJECT(
        'layer', 'DWD 明细层',
        'responsibility', '沉淀订单、流量、房态、价格、点评、用户等可复用明细事实。',
        'typical_use', '支撑日报、漏斗、订单明细、价格库存巡检、点评分析。'
      ),
      JSON_OBJECT(
        'layer', 'DWS 汇总层',
        'responsibility', '输出日报、周报、月报、漏斗、竞对和市场热度聚合口径。',
        'typical_use', 'BI 看板、经营报告、API、管理层复盘。'
      ),
      JSON_OBJECT(
        'layer', '特征层',
        'responsibility', '为需求、取消、LTV、异常检测构造可复用特征。',
        'typical_use', '预测、评分、预警模型输入。'
      ),
      JSON_OBJECT(
        'layer', '模型层',
        'responsibility', '生成预测、评分、预警结果，并输出到 BI、告警或 API。',
        'typical_use', '需求预测、取消率预测、LTV 预测、异常预警。'
      )
    )
  ),
  NOW()
WHERE @ota_arch_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_arch_unit_id,
  '治理规则',
  JSON_OBJECT(
    'rules', JSON_ARRAY(
      JSON_OBJECT(
        'theme', '数据清洗',
        'design_suggestion', '金额统一到“分”或“元”且单独保留币种；日期统一到酒店业务日；字符串枚举全部映射到标准状态表。',
        'key_checks', '金额非负、币种非空、日期合法、状态可映射'
      ),
      JSON_OBJECT(
        'theme', '去重',
        'design_suggestion', '订单以 platform + order_id 为一主键；产品以平台产品键为主，再补业务唯一键；美团直连产品可按 poiId + roomType + breakfastNum 做辅助去重。',
        'key_checks', '唯一键重复率、重复订单率、重复产品率'
      ),
      JSON_OBJECT(
        'theme', '时间对齐',
        'design_suggestion', '至少保留 event_time、create_time、pay_time、checkin_date、checkout_date、settle_date。',
        'key_checks', '事件时间早于创建时间、入住早于离店、结算月是否按离店归并'
      ),
      JSON_OBJECT(
        'theme', '订单与流量归因',
        'design_suggestion', '平台内优先按 session_id/visit_id 归因；没有会话键时，用“最后一次有效触点 + 时间窗”规则；站外广告另接成本表。',
        'key_checks', '无归因订单占比、跨日错配率、归因回补率'
      ),
      JSON_OBJECT(
        'theme', '跨平台用户去重',
        'design_suggestion', '仅在合规前提下，用手机号/证件/邮箱的加盐哈希 + 酒店 + 入住离店 + 金额窗做宽松匹配；无法合法去重时只做订单层去重，不做用户层硬合并。',
        'key_checks', '合并冲突率、误匹配抽检、匿名化执行率'
      )
    )
  ),
  NOW()
WHERE @ota_arch_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_arch_unit_id,
  '质量监控与对账',
  JSON_OBJECT(
    'rules', JSON_ARRAY(
      JSON_OBJECT(
        'theme', '数据质量监控',
        'design_suggestion', '监控新鲜度、完整率、字段空值率、金额校验、订单状态跳转合法性、接口 SLA、小时级异常。',
        'key_checks', 'Freshness、Null rate、TP95、Reconciliation 差异'
      ),
      JSON_OBJECT(
        'theme', '对账',
        'design_suggestion', '订单总价应能回算到“日历价 × 间夜 × 间数 ± 优惠/税费”；库存快照应与订单扣减逻辑一致。',
        'key_checks', '收入差异率、库存差异率、订单回算通过率'
      )
    ),
    'recommended_monitoring_dimensions', JSON_ARRAY(
      '平台：美团、携程、PMS、CRS、广告。',
      '数据域：订单、价格库存、流量、点评、用户、广告成本。',
      '时间：小时、酒店业务日、入住日、离店日、结算月。',
      '质量：新鲜度、完整率、空值率、重复率、一致性、SLA。'
    ),
    'alert_examples', JSON_ARRAY(
      '订单接口 TP95 超阈值。',
      '某平台订单量小时级突降。',
      '库存快照与订单扣减不一致。',
      '订单金额无法按价格、间夜、间数、优惠税费回算。',
      '平台状态枚举无法映射到标准状态表。'
    )
  ),
  NOW()
WHERE @ota_arch_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_arch_unit_id,
  '落地边界',
  JSON_OBJECT(
    'storage_boundary', JSON_ARRAY(
      '本知识单元不新增 ODS、DWD、DWS、feature、model 表，只定义后续数据建设蓝图。',
      '当前项目落地仍优先复用 online_daily_data、daily_reports、competitor_analysis、operation_alerts、knowledge_units 和 raw_data。',
      '只有明确产品功能需要查询、回显、编辑、权限过滤或模型训练时，才新增结构化表或字段。'
    ),
    'implementation_sequence', JSON_ARRAY(
      '先固定字段契约、状态枚举、时间口径和币种单位。',
      '再补订单、价格库存、流量、点评的明细可追溯数据。',
      '再做 DWS 汇总和 BI 看板。',
      '最后做特征层、模型层和自动预警。'
    ),
    'compliance_boundary', JSON_ARRAY(
      '跨平台用户去重必须基于合法授权和加盐哈希，不保存明文手机号、证件或邮箱用于分析。',
      '无法合法去重时，只做订单层去重，不做用户层硬合并。',
      '广告归因和用户分层必须保留归因规则、时间窗、匿名化主键和抽检记录。'
    ),
    'failure_policy', '质量校验失败、状态无法映射、金额无法回算、时间关系非法时必须暴露异常原因，不写兜底成功或假数据。'
  ),
  NOW()
WHERE @ota_arch_unit_id IS NOT NULL;

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

SET @staff_knowledge_title := @ota_arch_unit_name;
SET @staff_knowledge_content := CONCAT(
  '# OTA数据分层架构与治理规则', '\n\n',
  '> 来源说明：按 2026-05-20 输入资料整理。本条目用于数据架构、治理规则和后续建设路线图，不代表已新增 ODS/DWD/DWS 表结构。', '\n\n',
  '## 数据流架构', '\n\n',
  '```mermaid', '\n',
  'flowchart LR', '\n',
  '    A[美团 TMC 与直连平台<br/>酒店 房型 价格库存 订单日志] --> ODS[ODS 原始层]', '\n',
  '    B[携程 eBooking 与 Trip Connect<br/>内容 ARI 预订 点评 分析导出] --> ODS', '\n',
  '    C[可选补充<br/>PMS CRS 广告成本 节假日表] --> ODS', '\n',
  '    ODS --> CLN[清洗标准化<br/>主键 单位 状态 时间 币种]', '\n',
  '    CLN --> DWD[明细层 DWD<br/>订单 流量 房态 价格 点评 用户]', '\n',
  '    DWD --> DWS[汇总层 DWS<br/>日报 周报 月报 漏斗 竞对]', '\n',
  '    DWD --> FEAT[特征层<br/>需求 取消 LTV 异常]', '\n',
  '    FEAT --> ML[模型层<br/>预测 评分 预警]', '\n',
  '    DWS --> BI[BI 看板 报告 API]', '\n',
  '    ML --> BI', '\n',
  '    CLN --> QA[质量监控<br/>完整性 一致性 新鲜度 SLA]', '\n',
  '    QA --> BI', '\n',
  '```', '\n\n',
  '## 治理规则', '\n',
  '| 治理主题 | 设计建议 | 关键校验点 |', '\n',
  '| --- | --- | --- |', '\n',
  '| 数据清洗 | 金额统一到“分”或“元”且单独保留币种；日期统一到酒店业务日；字符串枚举全部映射到标准状态表 | 金额非负、币种非空、日期合法、状态可映射 |', '\n',
  '| 去重 | 订单以 `platform + order_id` 为一主键；产品以平台产品键为主，再补业务唯一键；美团直连产品可按 `poiId + roomType + breakfastNum` 做辅助去重 | 唯一键重复率、重复订单率、重复产品率 |', '\n',
  '| 时间对齐 | 至少保留 `event_time`、`create_time`、`pay_time`、`checkin_date`、`checkout_date`、`settle_date` | 事件时间早于创建时间、入住早于离店、结算月是否按离店归并 |', '\n',
  '| 订单与流量归因 | 平台内优先按 `session_id/visit_id` 归因；没有会话键时，用“最后一次有效触点 + 时间窗”规则；站外广告另接成本表 | 无归因订单占比、跨日错配率、归因回补率 |', '\n',
  '| 跨平台用户去重 | 仅在合规前提下，用手机号/证件/邮箱的加盐哈希 + 酒店 + 入住离店 + 金额窗做宽松匹配；无法合法去重时只做订单层去重，不做用户层硬合并 | 合并冲突率、误匹配抽检、匿名化执行率 |', '\n',
  '| 数据质量监控 | 监控新鲜度、完整率、字段空值率、金额校验、订单状态跳转合法性、接口 SLA、小时级异常 | Freshness、Null rate、TP95、Reconciliation 差异 |', '\n',
  '| 对账 | 订单总价应能回算到“日历价 × 间夜 × 间数 ± 优惠/税费”；库存快照应与订单扣减逻辑一致 | 收入差异率、库存差异率、订单回算通过率 |', '\n\n',
  '## 落地边界', '\n',
  '- 当前项目落地仍优先复用 `online_daily_data`、`daily_reports`、`competitor_analysis`、`operation_alerts`、`knowledge_units` 和 `raw_data`。', '\n',
  '- 只有明确产品功能需要查询、回显、编辑、权限过滤或模型训练时，才新增结构化表或字段。', '\n',
  '- 跨平台用户去重必须基于合法授权和加盐哈希；无法合法去重时，只做订单层去重。', '\n',
  '- 质量校验失败、状态无法映射、金额无法回算、时间关系非法时必须暴露异常原因，不写兜底成功或假数据。'
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
  'OTA,数据架构,数据治理,ODS,DWD,DWS,质量监控,对账,归因,去重',
  JSON_ARRAY('OTA', '数据架构', '数据治理', 'ODS', 'DWD', 'DWS', '质量监控'),
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
  `keywords` = 'OTA,数据架构,数据治理,ODS,DWD,DWS,质量监控,对账,归因,去重',
  `tags` = JSON_ARRAY('OTA', '数据架构', '数据治理', 'ODS', 'DWD', 'DWS', '质量监控'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title;
