-- Seed owner negotiation QA playbook into the project knowledge systems.
-- This is communication guidance only: it is not OTA/PMS operating data and must not be used as execution evidence.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @owner_qa_unit_name := '业主谈判 QA 话术库';
SET @owner_qa_source := 'owner_negotiation_qa_playbook';
SET @owner_qa_description := '沉淀业主谈判、酒店托管和收益管理沟通口径，供日报解读、AI回复和知识库检索参考。该资料为用户提供的沟通参考，不替代当前已验证 OTA/PMS/经营数据。';

INSERT INTO `knowledge_units` (`hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`, `created_at`, `updated_at`)
SELECT
  0,
  @owner_qa_unit_name,
  @owner_qa_source,
  'done',
  @owner_qa_description,
  JSON_ARRAY('业主谈判', '托管沟通', '收益管理', '日报话术', 'user_provided_unverified', 'communication_reference'),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @owner_qa_unit_name AND `source` = @owner_qa_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @owner_qa_description,
  `tags` = JSON_ARRAY('业主谈判', '托管沟通', '收益管理', '日报话术', 'user_provided_unverified', 'communication_reference'),
  `updated_at` = NOW()
WHERE `name` = @owner_qa_unit_name AND `source` = @owner_qa_source;

SET @owner_qa_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @owner_qa_unit_name AND `source` = @owner_qa_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DELETE FROM `knowledge_chunks`
WHERE `unit_id` = @owner_qa_unit_id
  AND `type` IN ('使用边界', '日报引用规则', '核心话术', '业主异议回复', '复盘节奏', '禁止事项');

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @owner_qa_unit_id,
  '使用边界',
  JSON_OBJECT(
    'source_file', 'docs/owner_negotiation_qa_playbook.md',
    'verification_status', 'user_provided_unverified_reference',
    'scope', 'communication_reference_only_not_operating_data',
    'rules', JSON_ARRAY(
      '只能用于业主沟通表达、AI回复措辞和日报解读辅助。',
      '经营事实必须来自日报、OTA、PMS或其他已验证来源。',
      'OTA渠道数据不得包装成全酒店经营事实。',
      '不得承诺确定入住率、收入、利润、ROI或回本周期。',
      '采集失败、字段缺失、登录失败和口径不明必须直接说明。'
    )
  ),
  0,
  NOW()
WHERE @owner_qa_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @owner_qa_unit_id,
  '日报引用规则',
  JSON_OBJECT(
    'daily_report_use', JSON_ARRAY(
      '日报先展示昨日事实，再给业主可理解的经营判断。',
      '有数据缺口时，先说边界和修复动作，不做收益判断。',
      '话术只作为表达层，不能进入 recommended_actions 的执行工单链。',
      '业主沟通应绑定日期、房型、渠道、库存、价格动作和复盘指标。'
    ),
    'product_chain', 'verified OTA/PMS data -> revenue analysis -> AI decision -> operation execution -> investment or hosted-operation decision'
  ),
  0,
  NOW()
WHERE @owner_qa_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @owner_qa_unit_id,
  '核心话术',
  JSON_OBJECT(
    'phrases', JSON_ARRAY(
      '先止损，后保本，再增长。',
      '低价不是问题，无规则低价才是问题。',
      '我们不靠口头保证收益，而是靠数据、动作和复盘把经营拉回正轨。',
      '7天看动作是否到位，30天看关键指标改善，60天看结构变化，90天看托管或合作价值。'
    ),
    'tone', 'professional_restrained_owner_safe'
  ),
  0,
  NOW()
WHERE @owner_qa_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @owner_qa_unit_id,
  '业主异议回复',
  JSON_OBJECT(
    'qa_patterns', JSON_ARRAY(
      JSON_OBJECT('concern', '为什么要降价', 'reply', '先区分无规则低价和有边界的收益管理动作，必须限定日期、房型、渠道、库存和复盘指标。'),
      JSON_OBJECT('concern', '多久能看到效果', 'reply', '不承诺固定收益，用7/30/60/90天复盘动作、指标、结构和合作价值。'),
      JSON_OBJECT('concern', '是不是托管后就能保证赚钱', 'reply', '托管只能提高经营动作质量和复盘密度，不能替代市场、位置、产品和成本约束。'),
      JSON_OBJECT('concern', '数据不完整还能判断吗', 'reply', '数据不完整时先补采集和口径，不能把缺口包装成结论。')
    )
  ),
  0,
  NOW()
WHERE @owner_qa_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @owner_qa_unit_id,
  '复盘节奏',
  JSON_OBJECT(
    'cadence', JSON_ARRAY(
      JSON_OBJECT('period', '7天', 'focus', '动作是否执行到位，价格、房型、渠道、页面和库存是否按计划调整。'),
      JSON_OBJECT('period', '30天', 'focus', '订单、间夜、ADR、RevPAR、曝光、访客、转化和竞对信号是否改善。'),
      JSON_OBJECT('period', '60天', 'focus', '客源结构、渠道贡献、房型结构和价格纪律是否形成稳定改善。'),
      JSON_OBJECT('period', '90天', 'focus', '判断托管合作、继续投入或策略调整是否有证据支撑。')
    )
  ),
  0,
  NOW()
WHERE @owner_qa_unit_id IS NOT NULL;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @owner_qa_unit_id,
  '禁止事项',
  JSON_OBJECT(
    'blocked_claims', JSON_ARRAY(
      '不得把话术资料当作当前经营数据。',
      '不得隐藏日报里的缺失、失败或未验证状态。',
      '不得承诺确定收益、入住率、利润、ROI或回本。',
      '不得让业主沟通话术自动进入执行意图或OTA改价动作。'
    )
  ),
  0,
  NOW()
WHERE @owner_qa_unit_id IS NOT NULL;

SET @owner_qa_category_name := '业主沟通与托管话术';
SET @owner_qa_category_description := '业主谈判、托管合作和收益管理沟通口径，仅作表达参考。';

INSERT INTO `knowledge_categories` (`hotel_id`, `parent_id`, `name`, `description`, `sort_order`, `is_enabled`, `create_time`, `update_time`)
SELECT
  0,
  0,
  @owner_qa_category_name,
  @owner_qa_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @owner_qa_category_name
);

UPDATE `knowledge_categories`
SET
  `description` = @owner_qa_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @owner_qa_category_name;

SET @owner_qa_category_id := (
  SELECT `id` FROM `knowledge_categories`
  WHERE `hotel_id` = 0 AND `parent_id` = 0 AND `name` = @owner_qa_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @owner_qa_staff_content := CONCAT(
  '# 业主谈判 QA 话术库', '\n\n',
  '## 定位', '\n',
  '这份资料用于业主沟通、托管合作说明、AI回复措辞和日报解读辅助。它不是当前经营数据，不替代 OTA、PMS 或经营日报证据。', '\n\n',
  '## 核心表达', '\n',
  '- 先止损，后保本，再增长。', '\n',
  '- 低价不是问题，无规则低价才是问题。', '\n',
  '- 不承诺固定收益，只承诺经营动作、复盘节奏和证据链。', '\n\n',
  '## 日报使用规则', '\n',
  '- 有数据：先讲事实，再讲原因，再讲动作。', '\n',
  '- 有缺口：先讲缺口和修复动作，不做收益判断。', '\n',
  '- 所有建议必须落到日期、房型、渠道、库存、价格动作和复盘指标。', '\n\n',
  '## 禁止事项', '\n',
  '- 不把 OTA 渠道数据包装成全酒店经营事实。', '\n',
  '- 不隐藏采集失败、字段缺失、登录失败或口径不明。', '\n',
  '- 不承诺收益、入住率、利润、ROI 或回本周期。'
);

INSERT INTO `knowledge_base` (
  `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  COALESCE(@owner_qa_category_id, 0),
  @owner_qa_unit_name,
  @owner_qa_staff_content,
  '业主谈判,托管沟通,收益管理,日报话术,止损保本增长,低价规则,user_provided_unverified',
  JSON_ARRAY('业主谈判', '托管沟通', '收益管理', '日报话术', 'communication_reference'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `hotel_id` = 0 AND `title` = @owner_qa_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = COALESCE(@owner_qa_category_id, `category_id`),
  `content` = @owner_qa_staff_content,
  `keywords` = '业主谈判,托管沟通,收益管理,日报话术,止损保本增长,低价规则,user_provided_unverified',
  `tags` = JSON_ARRAY('业主谈判', '托管沟通', '收益管理', '日报话术', 'communication_reference'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @owner_qa_unit_name;
