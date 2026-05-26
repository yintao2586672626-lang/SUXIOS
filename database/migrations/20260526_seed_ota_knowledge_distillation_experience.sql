-- Seed OTA knowledge-distillation experience into the project knowledge systems.
-- This is content-only: no crawler, business table, interface, or OTA field is added here.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_experience_unit_name := 'OTA知识沉淀经验总结';
SET @ota_experience_source := 'ota';
SET @ota_experience_description := '总结 2026-05-26 将 OTA 思维导图、已有指标口径和联网检索资料沉淀为宿析OS知识库的经验，形成可复用的资料抽取、口径融合、落库和验证方法论。';

INSERT INTO `knowledge_units` (`name`, `source`, `status`, `description`, `tags`, `created_at`, `updated_at`)
SELECT
  @ota_experience_unit_name,
  @ota_experience_source,
  'done',
  @ota_experience_description,
  JSON_ARRAY('OTA', '知识沉淀', '经验总结', '方法论', '指标口径', '知识库', '验证'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ota_experience_unit_name AND `source` = @ota_experience_source
);

UPDATE `knowledge_units`
SET
  `status` = 'done',
  `description` = @ota_experience_description,
  `tags` = JSON_ARRAY('OTA', '知识沉淀', '经验总结', '方法论', '指标口径', '知识库', '验证'),
  `updated_at` = NOW()
WHERE `name` = @ota_experience_unit_name AND `source` = @ota_experience_source;

SET @ota_experience_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ota_experience_unit_name AND `source` = @ota_experience_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @ota_experience_unit_id
  AND `type` IN ('适用场景', '核心经验', '推荐流程', '知识库结构建议', '质量守卫', '可复用清单', '本次成果');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_experience_unit_id,
  '适用场景',
  JSON_OBJECT(
    'title', @ota_experience_unit_name,
    'scenario', '当资料来自图片、旧版培训材料、平台后台截图、Excel 导出、人工经验或联网资料时，宿析OS应先把信息沉淀为可追溯、可计算、可诊断、可执行的知识，再决定是否进入业务字段、报表或自动化流程。',
    'source_case', '2026-05-26 对 E:\\杂项\\OTA运营思维导图2.0版 的处理，以及后续将 OTA 指标联网校验并引入宿析OS知识库的过程。'
  ),
  NOW()
WHERE @ota_experience_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_experience_unit_id,
  '核心经验',
  JSON_OBJECT(
    'rows', JSON_ARRAY(
      JSON_OBJECT('experience', '先分清资料类型', 'explanation', '图片思维导图、平台规则、行业标准、项目既有口径不能混为一类。', 'suxios_action', '图片先 OCR，旧资料标注时效，行业指标找公开公式，项目口径保留兼容。'),
      JSON_OBJECT('experience', '先沉淀知识，不急着改表', 'explanation', '大量运营知识适合进入知识库，不一定适合立即结构化成业务表。', 'suxios_action', '优先写入 knowledge_units、knowledge_chunks、knowledge_base。'),
      JSON_OBJECT('experience', '指标必须有口径', 'explanation', '同一个指标在全店、OTA渠道、平台、房型、广告活动中的含义不同。', 'suxios_action', '每条指标必须标注 metric_scope 和 calculation_basis。'),
      JSON_OBJECT('experience', '标准指标用权威公式', 'explanation', 'ADR、入住率、RevPAR、CTR、转化率等应使用行业或平台公开口径。', 'suxios_action', '公式写入知识库，计算时分母缺失返回不可计算。'),
      JSON_OBJECT('experience', '平台私有分值不反推', 'explanation', '携程服务质量分、美团 HOS、飞猪 MCI 等可能有私有权重。', 'suxios_action', '保存后台值、含义、影响因素和行动建议，不写死公式。'),
      JSON_OBJECT('experience', '旧资料与新资料要合并别名', 'explanation', '旧图中的 MIC 与公开常见 MCI 可能指向同类飞猪服务指标。', 'suxios_action', '系统统一字段 fliggy_mci，保留 MIC 作为历史别名。'),
      JSON_OBJECT('experience', '运营建议必须能落到动作', 'explanation', '提升流量太泛，必须拆成排名、标签、首图、库存、价格、平台分等动作。', 'suxios_action', '知识块中保留现象-判断-动作诊断模板。'),
      JSON_OBJECT('experience', '验证要覆盖幂等和初始化', 'explanation', '知识 seed 必须可重复执行，且 init_full.sql 不引用缺失文件。', 'suxios_action', '临时库执行两次，验证 unit/chunk/category/base 不重复。')
    )
  ),
  NOW()
WHERE @ota_experience_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_experience_unit_id,
  '推荐流程',
  JSON_OBJECT(
    'steps', JSON_ARRAY(
      JSON_OBJECT('step', '资料盘点', 'checks', JSON_ARRAY('列出文件来源、文件类型、数量和日期。', '对旧资料标注执行前需复核当前平台后台。', '不根据文件名或模糊截图直接编结论。')),
      JSON_OBJECT('step', '原文抽取', 'checks', JSON_ARRAY('图片资料先 OCR，并保存原始抽取结果。', '对 OCR 错字只做可验证修正，不凭空补内容。', '抽取结果先按主题归类，再总结。')),
      JSON_OBJECT('step', '业务归类', 'checks', JSON_ARRAY('OTA 运营知识按平台、流量、转化、内容、点评、房态、库存、评分、数据诊断分类。', '指标知识按经营收益、OTA流量、交易转化、价格竞争、库存房态、口碑服务、用户行为、投放活动分类。')),
      JSON_OBJECT('step', '联网校验', 'checks', JSON_ARRAY('行业通用指标优先找酒店行业、PMS、广告平台、OTA/分销平台公开文档。', '对平台私有分值，只确认公开含义和影响范围，不推导未公开权重。', '把来源链接写入知识块，方便后续复核。')),
      JSON_OBJECT('step', '项目融合', 'checks', JSON_ARRAY('不覆盖既有项目口径，先比较差异。', '行业标准公式优先；项目字段边界优先；旧资料作为运营经验保留。', '对别名、历史叫法、平台差异做兼容说明。')),
      JSON_OBJECT('step', '知识落库', 'checks', JSON_ARRAY('人读版本写入 docs。', '系统检索版本写入 migration seed。', '同时写 knowledge_units、knowledge_chunks 和 knowledge_base。', 'chunk 要结构化，knowledge_base 要简短。')),
      JSON_OBJECT('step', '验证闭环', 'checks', JSON_ARRAY('init_full.sql 必须 SOURCE 新 seed。', '运行 DatabaseBuildScriptTest。', '用临时 MySQL 库执行 seed 两次，验证幂等。', '检查新增文件无行尾空格，确认没有敏感信息进入 Git。'))
    )
  ),
  NOW()
WHERE @ota_experience_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_experience_unit_id,
  '知识库结构建议',
  JSON_OBJECT(
    'layers', JSON_ARRAY(
      JSON_OBJECT('layer', 'knowledge_units', 'content', '一个完整知识主题。', 'fields', JSON_ARRAY('名称', '来源', '状态', '描述', '标签')),
      JSON_OBJECT('layer', 'knowledge_chunks', 'content', '可检索的结构化片段。', 'fields', JSON_ARRAY('使用边界', '指标清单', '诊断模板', '落地规则', '来源')),
      JSON_OBJECT('layer', 'knowledge_base', 'content', '员工可读摘要。', 'fields', JSON_ARRAY('Markdown 摘要', '关键词', '标签')),
      JSON_OBJECT('layer', 'docs', 'content', '长文档、方法论、完整解释。', 'fields', JSON_ARRAY('资料边界', '详细表格', '验证说明'))
    )
  ),
  NOW()
WHERE @ota_experience_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_experience_unit_id,
  '质量守卫',
  JSON_OBJECT(
    'guardrails', JSON_ARRAY(
      '不把 OTA 渠道数据冒充全店经营数据。',
      '不把旧平台规则写成当前官方规则。',
      '不把缺分母指标写成 0。',
      '不在没有广告成本时输出 ROI。',
      '不反向工程平台私有分数。',
      '不把 Cookie、token、账号、手机号、订单明细、点评明细等敏感信息写入普通文档或 seed。',
      '不为了完整新增业务表；只有页面、报表、预警或 AI 分析明确需要结构化读取时才新增字段。'
    )
  ),
  NOW()
WHERE @ota_experience_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_experience_unit_id,
  '可复用清单',
  JSON_OBJECT(
    'checklist', JSON_ARRAY(
      '资料来源是否明确。',
      '资料是否过期或可能变化。',
      '是否有原始抽取证据。',
      '是否区分行业标准、平台规则、项目口径和运营经验。',
      '是否标注公式、分母、粒度、适用范围和不可计算条件。',
      '是否能映射到现有字段或 raw_data。',
      '是否写入知识库三层结构。',
      '是否完成幂等验证。'
    )
  ),
  NOW()
WHERE @ota_experience_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_at`)
SELECT
  @ota_experience_unit_id,
  '本次成果',
  JSON_OBJECT(
    'artifacts', JSON_ARRAY(
      'docs/ota_operation_mindmap_knowledge.md',
      'database/migrations/20260526_seed_ota_operation_mindmap_knowledge.sql',
      'docs/hotel_ota_metric_professional_knowledge.md',
      'database/migrations/20260526_seed_hotel_ota_metric_professional_knowledge.sql',
      'database/init_full.sql'
    ),
    'reuse_rule', '后续继续沉淀 OTA、收益管理、投资决策或运营 SOP 时，应复用本流程。'
  ),
  NOW()
WHERE @ota_experience_unit_id IS NOT NULL;

SET @ota_experience_category_name := 'OTA知识方法论';
SET @ota_experience_category_description := 'OTA 知识抽取、指标口径融合、知识库落库和验证方法。';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @ota_experience_category_name,
  @ota_experience_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_experience_category_name
);

UPDATE `knowledge_categories`
SET
  `description` = @ota_experience_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_experience_category_name;

SET @ota_experience_category_id := (
  SELECT `id` FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @ota_experience_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @ota_experience_staff_title := @ota_experience_unit_name;
SET @ota_experience_staff_content := CONCAT(
  '# OTA知识沉淀经验总结', '\n\n',
  '## 核心经验', '\n',
  '- 先分清资料类型：图片、旧平台规则、行业标准、项目既有口径不能混为一类。', '\n',
  '- 先沉淀知识，不急着改表：运营知识优先进入 knowledge_units、knowledge_chunks、knowledge_base。', '\n',
  '- 指标必须有口径：全店、OTA渠道、平台、房型、广告活动要分开。', '\n',
  '- 标准指标用权威公式：ADR、入住率、RevPAR、CTR、转化率等采用公开口径。', '\n',
  '- 平台私有分值不反推：携程服务质量分、美团 HOS、飞猪 MCI 只保存后台值、含义和行动建议。', '\n',
  '- 旧资料与新资料要合并别名：飞猪 MIC/MCI 统一为 fliggy_mci，保留 MIC 作为别名。', '\n\n',
  '## 推荐流程', '\n',
  '资料盘点 -> 原文抽取 -> 业务归类 -> 联网校验 -> 项目融合 -> 知识落库 -> 验证闭环。', '\n\n',
  '## 质量守卫', '\n',
  '- 不把 OTA 渠道数据冒充全店经营数据。', '\n',
  '- 不把旧平台规则写成当前官方规则。', '\n',
  '- 不把缺分母指标写成 0。', '\n',
  '- 不在没有广告成本时输出 ROI。', '\n',
  '- 不反向工程平台私有分数。', '\n',
  '- 不把敏感信息写入普通文档或 seed。', '\n\n',
  '## 验证要求', '\n',
  '- init_full.sql 必须 SOURCE 新 seed。', '\n',
  '- 运行 DatabaseBuildScriptTest。', '\n',
  '- 临时 MySQL 库执行 seed 两次，验证幂等。'
);

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@ota_experience_category_id, 0),
  @ota_experience_staff_title,
  @ota_experience_staff_content,
  'OTA,知识沉淀,经验总结,方法论,指标口径,知识库,验证',
  JSON_ARRAY('OTA', '知识沉淀', '经验总结', '方法论', '指标口径', '验证'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @ota_experience_staff_title
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@ota_experience_category_id, 0),
  `content` = @ota_experience_staff_content,
  `keywords` = 'OTA,知识沉淀,经验总结,方法论,指标口径,知识库,验证',
  `tags` = JSON_ARRAY('OTA', '知识沉淀', '经验总结', '方法论', '指标口径', '验证'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @ota_experience_staff_title;
