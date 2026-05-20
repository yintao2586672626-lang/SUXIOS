-- Seed OTA data product matrix into the project knowledge systems.
-- This is content-only: no business tables or OTA fields are added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_product_unit_name := 'OTA数据产品矩阵';
SET @ota_product_source := 'ota';
SET @ota_product_description := '按 2026-05-20 输入资料整理 OTA 数据产品的复杂度、目标用户、核心输入字段、核心输出指标、实现难度、预期价值和交付形式。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ota_product_unit_name,
  @ota_product_source,
  'done',
  @ota_product_description,
  JSON_ARRAY('OTA', '数据产品', '经营日报', '驾驶舱', '收益管理', '预测', '知识库'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_product_unit_name AND `source` = @ota_product_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ota_product_description,
  `tags` = JSON_ARRAY('OTA', '数据产品', '经营日报', '驾驶舱', '收益管理', '预测', '知识库'),
  `updated_at` = NOW()
WHERE `name` = @ota_product_unit_name AND `source` = @ota_product_source;

SET @ota_product_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_product_unit_name AND `source` = @ota_product_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ota_product_unit_id
  AND `type` IN ('使用边界', '基础与实时产品', '运营分析产品', '决策与进阶产品', '智能预测产品', '落地优先级');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_product_unit_id,
  '使用边界',
  JSON_OBJECT(
    'title', @ota_product_unit_name,
    'scope', '本知识单元只沉淀用户提供的数据产品矩阵，用于产品规划、字段优先级、报表路线图和 AI Agent 能力拆解；不代表本次新增功能、接口、表结构或采集任务。',
    'relation_to_existing_knowledge', JSON_ARRAY(
      '字段来源可关联“OTA平台可确认字段与假设字段清单”。',
      '指标口径可关联“OTA标准指标与推荐公式清单”。',
      '采集落库仍优先遵循 suxi-ota-ops：复用 online_daily_data 和 raw_data，不默认新增 reviews、orders、traffic_data 表。'
    ),
    'guardrails', JSON_ARRAY(
      '产品矩阵只定义目标，不代表所有输入字段当前已可采集。',
      '涉及用户画像、复购、LTV、渠道归因时必须先处理匿名化、权限和合规边界。',
      '高价值但高难度产品应先做可解释 MVP，不写不可验证的 AI 结论。'
    )
  ),
  NOW()
WHERE @ota_product_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_product_unit_id,
  '基础与实时产品',
  JSON_OBJECT(
    'products', JSON_ARRAY(
      JSON_OBJECT(
        'name', 'OTA 经营日报',
        'complexity', '基础',
        'target_users', '店总、收益经理、运营主管',
        'core_inputs', '订单、支付、取消、日历价、库存、点评',
        'core_outputs', '订单量、间夜、GMV、房费收入、ADR、取消率、点评分',
        'implementation_difficulty', '低',
        'expected_value', '高',
        'delivery', 'BI 表格、日报邮件、企业微信机器人'
      ),
      JSON_OBJECT(
        'name', '房态价量日报',
        'complexity', '基础',
        'target_users', '收益经理、值班经理',
        'core_inputs', '房型、价型、卖价、底价、库存、早餐、限制条件',
        'core_outputs', '日历价、库存水位、关房率、断房率、佣金率、价差异常',
        'implementation_difficulty', '低',
        'expected_value', '高',
        'delivery', '热力图、日历图、巡检报表'
      ),
      JSON_OBJECT(
        'name', '实时运营驾驶舱',
        'complexity', '实时',
        'target_users', '店总、运营、技术支持',
        'core_inputs', '订单日志、校验结果、创建/取消结果、请求耗时、库存',
        'core_outputs', '实时订单、失败率、异常单、TP95、库存告急、变价告警',
        'implementation_difficulty', '中',
        'expected_value', '高',
        'delivery', '大屏、实时仪表盘、告警 API'
      )
    )
  ),
  NOW()
WHERE @ota_product_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_product_unit_id,
  '运营分析产品',
  JSON_OBJECT(
    'products', JSON_ARRAY(
      JSON_OBJECT(
        'name', '周月运营看板',
        'complexity', '运营',
        'target_users', '店总、区域经理、品牌总部',
        'core_inputs', '流量、订单、点评、竞对、市场热度',
        'core_outputs', '曝光、UV、PV、CTR、支付转化率、间夜、ADR、RevPAR、口碑趋势',
        'implementation_difficulty', '中',
        'expected_value', '高',
        'delivery', 'Looker/Power BI、月报 PDF'
      ),
      JSON_OBJECT(
        'name', '流量漏斗诊断',
        'complexity', '运营',
        'target_users', 'OTA 运营、电商负责人',
        'core_inputs', '曝光、访问、详情、下单、支付、设备、渠道、排名',
        'core_outputs', '各漏斗转化率、流失段原因、端与渠道差异',
        'implementation_difficulty', '中',
        'expected_value', '高',
        'delivery', '漏斗图、维度钻取报表'
      ),
      JSON_OBJECT(
        'name', '点评与服务质量看板',
        'complexity', '运营',
        'target_users', '店总、前厅、客服',
        'core_inputs', '点评分、点评数、回复时效、差评主题、服务分',
        'core_outputs', '点评均分、差评率、回复 SLA、主题热词、服务缺口',
        'implementation_difficulty', '中',
        'expected_value', '中高',
        'delivery', 'BI 看板、周报、客服任务列表'
      ),
      JSON_OBJECT(
        'name', '竞争圈与市场热度看板',
        'complexity', '分析',
        'target_users', '收益经理、区域经营',
        'core_inputs', '竞对价格变化、排名、流失分析、热度趋势、游客出行趋势',
        'core_outputs', '市场热度、竞争圈均价、价差、分流率、流失对手名单',
        'implementation_difficulty', '中高',
        'expected_value', '高',
        'delivery', '对标看板、竞争日报'
      )
    )
  ),
  NOW()
WHERE @ota_product_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_product_unit_id,
  '决策与进阶产品',
  JSON_OBJECT(
    'products', JSON_ARRAY(
      JSON_OBJECT(
        'name', '渠道归因分析',
        'complexity', '决策',
        'target_users', '市场、电商、品牌总部',
        'core_inputs', '渠道、活动、设备、流量、订单、广告成本',
        'core_outputs', '渠道贡献、活动 ROI、CAC、ROAS、增量转化',
        'implementation_difficulty', '中高',
        'expected_value', '高',
        'delivery', '分析报告、归因 API'
      ),
      JSON_OBJECT(
        'name', '客群细分与复购分层',
        'complexity', '决策',
        'target_users', 'CRM、会员运营、店总',
        'core_inputs', '城市、设备、出行时间、价型、用户画像、订单历史、点评',
        'core_outputs', '客群规模、复购率、ARPU、价格敏感度、客群 LTV',
        'implementation_difficulty', '中高',
        'expected_value', '高',
        'delivery', '标签表、分群报表、触达名单'
      ),
      JSON_OBJECT(
        'name', '价格弹性与收益管理',
        'complexity', '进阶',
        'target_users', '收益经理、总部 RM',
        'core_inputs', '日历价、限制条件、库存、竞对调价、市场热度、预订提前期',
        'core_outputs', '弹性系数、最优价格带、预期间夜、预期 ADR/RevPAR',
        'implementation_difficulty', '高',
        'expected_value', '很高',
        'delivery', '推荐看板、调价 API、日报建议'
      ),
      JSON_OBJECT(
        'name', '广告投放 ROI',
        'complexity', '进阶',
        'target_users', '增长团队、市场部',
        'core_inputs', '广告成本、活动曝光点击、订单收入、客单价',
        'core_outputs', 'ROI、ROAS、增量订单、平均获客成本、活动回收周期',
        'implementation_difficulty', '高',
        'expected_value', '中高',
        'delivery', '投放看板、活动复盘报告'
      ),
      JSON_OBJECT(
        'name', '异常检测与自动预警',
        'complexity', '进阶',
        'target_users', '店总、技术、运营',
        'core_inputs', '订单、库存、价格、点评、接口耗时、市场热度',
        'core_outputs', '突降/突升预警、疑似系统故障、异常清单、排障优先级',
        'implementation_difficulty', '中高',
        'expected_value', '高',
        'delivery', '告警中心、机器人推送'
      )
    )
  ),
  NOW()
WHERE @ota_product_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_product_unit_id,
  '智能预测产品',
  JSON_OBJECT(
    'products', JSON_ARRAY(
      JSON_OBJECT(
        'name', '需求预测',
        'complexity', '智能',
        'target_users', '收益经理、总部 RM',
        'core_inputs', '历史订单、预订节奏、价格、节假日、竞对、热度、取消',
        'core_outputs', '未来间夜、未来房费收入、未来入住率、预测区间',
        'implementation_difficulty', '高',
        'expected_value', '很高',
        'delivery', '预测 API、看板、日报'
      ),
      JSON_OBJECT(
        'name', '取消率预测',
        'complexity', '智能',
        'target_users', '收益、客服、前厅',
        'core_inputs', '订单、取消规则、价型、支付状态、提前期、节假日、历史行为',
        'core_outputs', '订单取消概率、净需求修正、候补建议',
        'implementation_difficulty', '高',
        'expected_value', '很高',
        'delivery', '风险分层、预警清单、接口评分'
      ),
      JSON_OBJECT(
        'name', 'LTV 预测',
        'complexity', '智能',
        'target_users', 'CRM、品牌总部',
        'core_inputs', '用户历史订单、留存、客单价、间隔、退改、点评、来源',
        'core_outputs', '用户未来价值、客群价值、获客上限、复购触达优先级',
        'implementation_difficulty', '高',
        'expected_value', '高',
        'delivery', '用户评分表、营销人群 API'
      )
    )
  ),
  NOW()
WHERE @ota_product_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_product_unit_id,
  '落地优先级',
  JSON_OBJECT(
    'p0_mvp', JSON_ARRAY(
      'OTA 经营日报：低难度、高价值，直接承接订单、支付、取消、点评与收益指标。',
      '房态价量日报：低难度、高价值，优先解决价格、库存、断房、关房和价差巡检。',
      '周月运营看板：中等难度、高价值，适合作为管理层固定经营复盘入口。'
    ),
    'p1_operations', JSON_ARRAY(
      '实时运营驾驶舱：需要订单日志和接口耗时数据稳定后上线。',
      '流量漏斗诊断：依赖曝光、UV、PV、点击、详情、下单、支付链路字段完整性。',
      '点评与服务质量看板：可与客服任务列表联动，先做主题热词和回复 SLA。',
      '竞争圈与市场热度看板：先以可确认竞对价格、排名、热度趋势为 MVP。'
    ),
    'p2_decision_and_ai', JSON_ARRAY(
      '渠道归因、客群分层、广告 ROI、LTV 必须先明确用户匿名键、活动归因和广告成本口径。',
      '价格弹性、需求预测、取消率预测适合作为收益管理智能化二期能力。',
      '异常检测可先做规则预警，再逐步加入统计阈值和模型评分。'
    ),
    'storage_boundary', '优先复用现有 OTA、日报、点评、竞对、告警和知识库结构；产品化前先补字段契约、口径说明和验证样例。'
  ),
  NOW()
WHERE @ota_product_unit_id IS NOT NULL;

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

SET @staff_knowledge_title := @ota_product_unit_name;
SET @staff_knowledge_content := CONCAT(
  '# OTA数据产品矩阵', '\n\n',
  '> 来源说明：按 2026-05-20 输入资料整理。本条目用于产品规划、字段优先级和交付路线图，不代表已新增功能、接口或表结构。', '\n\n',
  '## 产品矩阵', '\n',
  '| 数据产品 | 复杂度 | 目标用户 | 核心输入字段 | 核心输出指标 | 实现难度 | 预期价值 | 交付形式 |', '\n',
  '| --- | --- | --- | --- | --- | --- | --- | --- |', '\n',
  '| OTA 经营日报 | 基础 | 店总、收益经理、运营主管 | 订单、支付、取消、日历价、库存、点评 | 订单量、间夜、GMV、房费收入、ADR、取消率、点评分 | 低 | 高 | BI 表格、日报邮件、企业微信机器人 |', '\n',
  '| 房态价量日报 | 基础 | 收益经理、值班经理 | 房型、价型、卖价、底价、库存、早餐、限制条件 | 日历价、库存水位、关房率、断房率、佣金率、价差异常 | 低 | 高 | 热力图、日历图、巡检报表 |', '\n',
  '| 实时运营驾驶舱 | 实时 | 店总、运营、技术支持 | 订单日志、校验结果、创建/取消结果、请求耗时、库存 | 实时订单、失败率、异常单、TP95、库存告急、变价告警 | 中 | 高 | 大屏、实时仪表盘、告警 API |', '\n',
  '| 周月运营看板 | 运营 | 店总、区域经理、品牌总部 | 流量、订单、点评、竞对、市场热度 | 曝光、UV、PV、CTR、支付转化率、间夜、ADR、RevPAR、口碑趋势 | 中 | 高 | Looker/Power BI、月报 PDF |', '\n',
  '| 流量漏斗诊断 | 运营 | OTA 运营、电商负责人 | 曝光、访问、详情、下单、支付、设备、渠道、排名 | 各漏斗转化率、流失段原因、端与渠道差异 | 中 | 高 | 漏斗图、维度钻取报表 |', '\n',
  '| 点评与服务质量看板 | 运营 | 店总、前厅、客服 | 点评分、点评数、回复时效、差评主题、服务分 | 点评均分、差评率、回复 SLA、主题热词、服务缺口 | 中 | 中高 | BI 看板、周报、客服任务列表 |', '\n',
  '| 竞争圈与市场热度看板 | 分析 | 收益经理、区域经营 | 竞对价格变化、排名、流失分析、热度趋势、游客出行趋势 | 市场热度、竞争圈均价、价差、分流率、流失对手名单 | 中高 | 高 | 对标看板、竞争日报 |', '\n',
  '| 渠道归因分析 | 决策 | 市场、电商、品牌总部 | 渠道、活动、设备、流量、订单、广告成本 | 渠道贡献、活动 ROI、CAC、ROAS、增量转化 | 中高 | 高 | 分析报告、归因 API |', '\n',
  '| 客群细分与复购分层 | 决策 | CRM、会员运营、店总 | 城市、设备、出行时间、价型、用户画像、订单历史、点评 | 客群规模、复购率、ARPU、价格敏感度、客群 LTV | 中高 | 高 | 标签表、分群报表、触达名单 |', '\n',
  '| 价格弹性与收益管理 | 进阶 | 收益经理、总部 RM | 日历价、限制条件、库存、竞对调价、市场热度、预订提前期 | 弹性系数、最优价格带、预期间夜、预期 ADR/RevPAR | 高 | 很高 | 推荐看板、调价 API、日报建议 |', '\n',
  '| 广告投放 ROI | 进阶 | 增长团队、市场部 | 广告成本、活动曝光点击、订单收入、客单价 | ROI、ROAS、增量订单、平均获客成本、活动回收周期 | 高 | 中高 | 投放看板、活动复盘报告 |', '\n',
  '| 异常检测与自动预警 | 进阶 | 店总、技术、运营 | 订单、库存、价格、点评、接口耗时、市场热度 | 突降/突升预警、疑似系统故障、异常清单、排障优先级 | 中高 | 高 | 告警中心、机器人推送 |', '\n',
  '| 需求预测 | 智能 | 收益经理、总部 RM | 历史订单、预订节奏、价格、节假日、竞对、热度、取消 | 未来间夜、未来房费收入、未来入住率、预测区间 | 高 | 很高 | 预测 API、看板、日报 |', '\n',
  '| 取消率预测 | 智能 | 收益、客服、前厅 | 订单、取消规则、价型、支付状态、提前期、节假日、历史行为 | 订单取消概率、净需求修正、候补建议 | 高 | 很高 | 风险分层、预警清单、接口评分 |', '\n',
  '| LTV 预测 | 智能 | CRM、品牌总部 | 用户历史订单、留存、客单价、间隔、退改、点评、来源 | 用户未来价值、客群价值、获客上限、复购触达优先级 | 高 | 高 | 用户评分表、营销人群 API |', '\n\n',
  '## 落地优先级', '\n',
  '- P0：OTA 经营日报、房态价量日报、周月运营看板。低到中等难度，价值高，适合作为首批产品化交付。', '\n',
  '- P1：实时运营驾驶舱、流量漏斗诊断、点评服务质量、竞争圈市场热度。依赖日志、流量、点评和竞对字段稳定。', '\n',
  '- P2：渠道归因、客群分层、价格弹性、广告 ROI、异常检测、需求预测、取消率预测、LTV 预测。先定义用户匿名键、归因口径、预测标签和评估样例。', '\n\n',
  '## 实施边界', '\n',
  '- 本矩阵只定义产品目标，不代表所有输入字段当前已可采集。', '\n',
  '- 采集与落库优先复用 `online_daily_data`、日报、点评、竞对、告警和知识库现有结构。', '\n',
  '- 涉及用户画像、复购、LTV、渠道归因时必须先处理匿名化、权限和合规边界。'
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
  'OTA,数据产品,经营日报,房态价量,驾驶舱,漏斗诊断,收益管理,需求预测,LTV',
  JSON_ARRAY('OTA', '数据产品', '经营日报', '驾驶舱', '收益管理', '预测'),
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
  `keywords` = 'OTA,数据产品,经营日报,房态价量,驾驶舱,漏斗诊断,收益管理,需求预测,LTV',
  `tags` = JSON_ARRAY('OTA', '数据产品', '经营日报', '驾驶舱', '收益管理', '预测'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @staff_knowledge_title;
