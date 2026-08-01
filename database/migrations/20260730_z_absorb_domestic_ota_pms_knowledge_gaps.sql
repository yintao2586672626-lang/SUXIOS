-- Close the reviewed domestic OTA/PMS knowledge gaps discovered on 2026-07-30.
--
-- This forward migration:
-- 1. corrects only exact legacy statements that conflict with current semantic
--    contracts;
-- 2. makes domestic public-source snapshots retrievable and mirrors them into
--    the employee-facing knowledge index;
-- 3. adds current, versioned official contracts for Ctrip fulfilment,
--    Dingdandao PMS workflows, and Dianping review governance.
--
-- It contains no current-hotel facts, guest PII, login material, platform
-- secrets, or external write authority. Safe reruns preserve operator-authored
-- rows and merge only the exact seed owner + key + version.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @gap_sem_version := '2026-07-30.3';
SET @gap_sem_reviewed_at := '2026-07-30';
SET @gap_sem_source := 'revenue_operations_decision_support';
SET @gap_sem_seed_owner := 'suxios.domestic_ota_pms_knowledge_gap_absorption';

-- ---------------------------------------------------------------------------
-- Exact conflict correction: revenue means room revenue only when calculating
-- room-revenue metrics. Missing room-revenue mapping is not paid_amount.
-- ---------------------------------------------------------------------------

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.rows[2].formula', 'sum(room_revenue)',
  '$.rows[2].raw_fields', '已映射的房费收入、币种、税费口径、营业日',
  '$.rows[2].note', '订单GMV、支付金额、结算金额和房费收入必须分开；room_revenue缺失时返回not_calculable并补字段映射，不以paid_amount替代。',
  '$.semantic_correction', JSON_OBJECT(
    'status', 'paid_amount_room_revenue_fallback_removed',
    'reviewed_at', @gap_sem_reviewed_at,
    'replacement_rule', 'room_revenue_missing_means_not_calculable',
    'superseded_by', '国内PMS经营日、订单状态与对账官方语义合同',
    'source_refs', JSON_ARRAY(
      'database/migrations/20260730_y_write_domestic_pms_semantic_contract.sql'
    )
  ),
  '$.seed_owner', @gap_sem_seed_owner,
  '$.seed_key', 'legacy:ota_standard_metrics:transaction_revenue:paid_amount_fallback',
  '$.seed_version', @gap_sem_version
)
WHERE `ku`.`name` = 'OTA标准指标与推荐公式清单'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '交易收益指标'
  AND JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.rows[2].formula')) LIKE '%paid_amount%';

UPDATE `knowledge_base`
SET
  `content` = REPLACE(
    REPLACE(
      `content`,
      '`sum(room_revenue)`；若暂无房费收入则 `sum(paid_amount)`',
      '`sum(room_revenue)`；缺少房费收入映射时返回 `not_calculable`，不得用支付金额替代'
    ),
    '携程数据中心更可能直接提供；美团公开 TMC 文档未直接给曝光计数，若无商家报表需补埋点或额外导出。补数需求来自平台能力差异。',
    '通用 impression_count 仅适用于来源明确的展示次数；携程数据中心已核验的“列表页曝光”为去重浏览人数，应使用携程专用UV语义键。美团曝光口径仍需以当前商家端字段为准。'
  ),
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = 'OTA标准指标与推荐公式清单';

-- ---------------------------------------------------------------------------
-- Exact conflict correction: local browser profiles are account/device
-- sessions, not one profile per store. Reviews are explicit-only, not a
-- standard automatic ETL priority.
-- ---------------------------------------------------------------------------

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.summary', '真实浏览器登录美团eBooking，复用已授权账号级本地Profile，在账号内按已核验门店身份切换；标准自动ETL采集流量和订单，点评仅在人工明确触发且复核平台规则后采集。',
  '$.profile_rule', '同一已授权账号在同一受控设备上复用一个本地Profile；账号内按已核验门店身份切换。不同账号不得共享Profile，Profile和Cookie不得进入Git。',
  '$.page_order', JSON_ARRAY(
    '数据中心或流量页面',
    '订单/入住管理页',
    '明确需要时的广告页',
    '人工明确触发时的点评管理页'
  ),
  '$.review_collection_boundary', JSON_OBJECT(
    'standard_automatic_etl', 'disabled',
    'allowed_mode', 'explicit_manual_or_bounded_authorized_capture',
    'reason', 'review governance and evidence requirements must be checked before collection or action'
  ),
  '$.semantic_correction', JSON_OBJECT(
    'status', 'per_store_profile_and_default_review_collection_replaced',
    'reviewed_at', @gap_sem_reviewed_at,
    'replacement_rule', 'account_level_profile_with_verified_store_switch_and_explicit_review_mode'
  ),
  '$.seed_owner', @gap_sem_seed_owner,
  '$.seed_key', 'legacy:meituan_browser_capture:profile_and_review_mode',
  '$.seed_version', @gap_sem_version
)
WHERE `ku`.`name` = '美团 eBooking 浏览器自动化采集方法'
  AND `ku`.`source` = 'meituan'
  AND `kc`.`type` = '采集方法';

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.profile_rule', JSON_OBJECT(
    'ctrip', '同一已授权携程账号在同一受控设备上复用一个本地Profile，并在账号内按已核验酒店身份切换',
    'meituan', '同一已授权美团账号在同一受控设备上复用一个本地Profile，并在账号内按已核验门店身份切换',
    'cross_account_boundary', '不同账号不得共享Profile；Profile、Cookie和认证材料不得进入Git或知识库'
  ),
  '$.semantic_correction', JSON_OBJECT(
    'status', 'per_store_profile_replaced',
    'reviewed_at', @gap_sem_reviewed_at,
    'replacement_rule', 'account_level_profile_with_verified_store_switch'
  ),
  '$.seed_owner', @gap_sem_seed_owner,
  '$.seed_key', 'legacy:ota_collection_strategy:profile_scope',
  '$.seed_version', @gap_sem_version
)
WHERE `ku`.`name` = 'OTA手动与自动获取策略'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '自动获取';

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.automatic_priority', JSON_ARRAY(
    '经营概况',
    '流量',
    '订单',
    '房态房价/ARI'
  ),
  '$.explicit_only_modules', JSON_ARRAY(
    '点评及点评证据',
    '点评回复或申诉'
  ),
  '$.review_collection_boundary', '点评默认不进入标准自动ETL；仅在人工明确触发、账号授权和当前平台规则复核后做有界采集。',
  '$.seed_owner', @gap_sem_seed_owner,
  '$.seed_key', 'legacy:ota_collection_strategy:ctrip_review_mode',
  '$.seed_version', @gap_sem_version
)
WHERE `ku`.`name` = 'OTA手动与自动获取策略'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '携程差异';

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.automatic_priority', JSON_ARRAY(
    '数据中心/流量',
    '订单/入住管理',
    '价格库存/直连产品'
  ),
  '$.explicit_only_modules', JSON_ARRAY(
    '点评及点评证据',
    '点评回复或申诉'
  ),
  '$.review_collection_boundary', '点评默认不进入标准自动ETL；仅在人工明确触发、账号授权和当前平台规则复核后做有界采集。',
  '$.seed_owner', @gap_sem_seed_owner,
  '$.seed_key', 'legacy:ota_collection_strategy:meituan_review_mode',
  '$.seed_version', @gap_sem_version
)
WHERE `ku`.`name` = 'OTA手动与自动获取策略'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '美团差异';

UPDATE `knowledge_base`
SET
  `content` = CONCAT(
    REPLACE(
      REPLACE(
        REPLACE(
          `content`,
          '门店独立 Profile',
          '已授权账号级本地 Profile'
        ),
        '每个门店使用 `storage/meituan_profile_{store_id}`。',
        '同一已授权账号在同一受控设备上复用一个本地 Profile，并在账号内按已核验门店身份切换；不同账号不得共享 Profile。'
      ),
      '依次打开点评、流量、newhb 流量、广告、订单页面。',
      '依次打开流量、订单和明确需要的广告页面；点评仅在人工明确触发并复核当前规则后采集。'
    ),
    '\n\n## 2026-07-30边界修订\n点评历史映射可保留用于人工复核，但默认标准自动ETL关闭；Cookie、Profile和认证材料仍不得进入知识库或Git。'
  ),
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = '美团 eBooking 浏览器自动化采集方法'
  AND `content` NOT LIKE '%2026-07-30边界修订%';

UPDATE `knowledge_base`
SET
  `content` = CONCAT(
    REPLACE(
      REPLACE(
        `content`,
        '自动优先：经营概况、流量、订单、点评、房态房价/ARI。',
        '自动优先：经营概况、流量、订单、房态房价/ARI；点评仅限人工明确触发的有界采集。'
      ),
      '自动优先：点评、数据中心/流量、订单/入住管理、价格库存/直连产品。',
      '自动优先：数据中心/流量、订单/入住管理、价格库存/直连产品；点评仅限人工明确触发的有界采集。'
    ),
    '\n\n## 2026-07-30会话与点评边界\nProfile按已授权账号和受控设备复用，账号内按已核验门店切换；不同账号不得共享。点评默认不进入标准自动ETL。'
  ),
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = 'OTA手动与自动获取策略'
  AND `content` NOT LIKE '%2026-07-30会话与点评边界%';

-- ---------------------------------------------------------------------------
-- Backfill current domestic public-source snapshots to the active retrieval
-- contract without claiming that metadata-only discovery equals article facts.
-- ---------------------------------------------------------------------------

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.schema_version', '1.1',
  '$.source_refs', JSON_ARRAY(
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_url'))
  ),
  '$.source_manifest', JSON_OBJECT(
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_key')),
    JSON_OBJECT(
      'publisher', JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_name')),
      'url', JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_url')),
      'source_tier', JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_tier')),
      'verification_status', 'metadata_verified_content_not_interpreted',
      'retrieved_at', JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.retrieved_at')),
      'fingerprint_sha256', JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.fingerprint_sha256'))
    )
  ),
  '$.module_id', 'domestic_public_source_monitor',
  '$.roles', JSON_ARRAY('owner', 'revenue_manager', 'operations_manager'),
  '$.scenes', JSON_ARRAY('industry_context', 'source_review', 'regulatory_watch'),
  '$.platforms', JSON_ARRAY(),
  '$.reviewed_at', COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.retrieved_at')),
    DATE_FORMAT(`ku`.`reviewed_at`, '%Y-%m-%d %H:%i:%s')
  ),
  '$.source_version_fingerprint', JSON_UNQUOTE(
    JSON_EXTRACT(`kc`.`content`, '$.fingerprint_sha256')
  ),
  '$.seed_owner', 'suxios.domestic_public_source_monitor',
  '$.seed_key', CONCAT(
    'domestic_public_source_monitor:',
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_key'))
  ),
  '$.seed_version', CONCAT(
    'sha256:',
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.fingerprint_sha256'))
  ),
  '$.lifecycle_status', 'active'
)
WHERE `ku`.`source` = 'domestic_public_monitor'
  AND `kc`.`type` = 'domestic_public_source_snapshot'
  AND JSON_VALID(`kc`.`content`) = 1
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_url')), '') <> ''
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.fingerprint_sha256')), '') <> '';

INSERT INTO `knowledge_base` (
  `tenant_id`,
  `hotel_id`,
  `category_id`,
  `title`,
  `content`,
  `keywords`,
  `tags`,
  `sort_order`,
  `is_enabled`,
  `view_count`,
  `like_count`,
  `create_time`,
  `update_time`
)
SELECT
  0,
  0,
  8,
  `ku`.`name`,
  CONCAT(
    '# ', `ku`.`name`, '\n\n',
    '## 当前快照\n',
    '已读取公开页面元数据，条目数：',
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.item_count')), '0'),
    '；来源：',
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_url')), '未记录'),
    '\n\n## 使用边界\n',
    '只确认标题、发布日期、链接和来源指纹；正文方法与口径尚未逐篇复核。不得替代携程、美团、PMS或当前酒店的已验证事实。'
  ),
  CONCAT(
    '国内公开资料,行业背景,监管,',
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_name')), '')
  ),
  JSON_ARRAY(
    '国内公开资料',
    '行业背景',
    'metadata_only',
    'source_traceable'
  ),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
FROM `knowledge_units` AS `ku`
INNER JOIN `knowledge_chunks` AS `kc`
  ON `kc`.`unit_id` = `ku`.`unit_id`
  AND `kc`.`type` = 'domestic_public_source_snapshot'
WHERE `ku`.`source` = 'domestic_public_monitor'
  AND NOT EXISTS (
    SELECT 1
    FROM `knowledge_base` AS `kb`
    WHERE `kb`.`hotel_id` = 0
      AND `kb`.`title` = `ku`.`name`
  );

UPDATE `knowledge_base` AS `kb`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`name` = `kb`.`title`
  AND `ku`.`source` = 'domestic_public_monitor'
INNER JOIN `knowledge_chunks` AS `kc`
  ON `kc`.`unit_id` = `ku`.`unit_id`
  AND `kc`.`type` = 'domestic_public_source_snapshot'
SET
  `kb`.`tenant_id` = 0,
  `kb`.`hotel_id` = 0,
  `kb`.`category_id` = 8,
  `kb`.`content` = CONCAT(
    '# ', `ku`.`name`, '\n\n',
    '## 当前快照\n',
    '已读取公开页面元数据，条目数：',
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.item_count')), '0'),
    '；来源：',
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_url')), '未记录'),
    '\n\n## 使用边界\n',
    '只确认标题、发布日期、链接和来源指纹；正文方法与口径尚未逐篇复核。不得替代携程、美团、PMS或当前酒店的已验证事实。'
  ),
  `kb`.`keywords` = CONCAT(
    '国内公开资料,行业背景,监管,',
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.source_name')), '')
  ),
  `kb`.`tags` = JSON_ARRAY(
    '国内公开资料',
    '行业背景',
    'metadata_only',
    'source_traceable'
  ),
  `kb`.`is_enabled` = 1,
  `kb`.`update_time` = NOW()
WHERE `kb`.`hotel_id` = 0;

-- ---------------------------------------------------------------------------
-- Versioned official semantic contracts.
-- ---------------------------------------------------------------------------

SET @ctrip_fulfillment_unit := '携程订单履约与结算官方语义合同';
SET @dingdandao_current_unit := '订单来了PMS当前版本官方语义合同';
SET @dianping_review_unit := '大众点评独立评价规则官方语义合同';

DROP TEMPORARY TABLE IF EXISTS `tmp_gap_semantic_units`;
CREATE TEMPORARY TABLE `tmp_gap_semantic_units` (
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `tags` JSON NOT NULL,
  `lifecycle_reason` VARCHAR(255) NOT NULL,
  `known_knowns` JSON NOT NULL,
  `known_unknowns` JSON NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_gap_semantic_units` (
  `name`,
  `description`,
  `tags`,
  `lifecycle_reason`,
  `known_knowns`,
  `known_unknowns`
)
VALUES
(
  @ctrip_fulfillment_unit,
  '将携程2025-11现行酒店商家经营规则中的订单确认、拒单、确认后推翻、无法履约、商家诱导取消/转店、发票、赔付扣款和申诉时限转为结构化状态合同；不包含当前酒店订单、实际责任、结算金额或平台写权限。',
  JSON_ARRAY(
    '携程',
    '订单履约',
    '拒单',
    '取消',
    '转店',
    '发票',
    '结算扣款',
    '申诉',
    'official_current_rule',
    'structured_knowledge'
  ),
  'reviewed_ctrip_merchant_rules_published_2025_11_03_effective_2025_11_10',
  JSON_ARRAY(
    '携程现行商家规则将未确认/拒绝、确认后推翻、预订不存在和到店无法按原单履约视为不同履约异常。',
    '商家诱导客人取消、改订其他酒店或线下交易，不能与客人自主取消混为同一原因。',
    '发票责任、履约赔付、平台先行处理和结算扣款必须分别留存状态与证据。',
    '规则页面给出的申诉期限为收到处罚通知后的5个工作日，执行时仍需保存通知时间和证据。'
  ),
  JSON_ARRAY(
    '目标酒店、目标订单和目标日期是否发生任何履约异常。',
    '当前账号实际可见的订单状态、处罚通知、赔付、扣款和申诉入口。',
    '当前订单责任方、客人真实意愿、可替代房型/酒店、差价和最终处理结果。',
    '平台内部风控、责任判定、扣款和排序算法。',
    '规则页面未来是否发布替代版本。'
  )
),
(
  @dingdandao_current_unit,
  '将订单来了官方2024夜审更新和2026订单账务更新转为当前版本PMS工作流合同；区分经营日、夜审、入账/结账、锁定报表、调账、入住/退房类型、支付方式、早餐抛账、换房拆单、AR账户、权限与操作日志，并保留产品版本和配置边界。',
  JSON_ARRAY(
    '订单来了',
    'PMS',
    '经营日',
    '夜审',
    '结账',
    '支付方式',
    '早餐抛账',
    '换房拆单',
    'AR',
    '权限审计',
    'official_versioned_product_docs',
    'structured_knowledge'
  ),
  'reviewed_dingdandao_official_versioned_docs_2024_06_and_2026_04',
  JSON_ARRAY(
    '订单来了专业版夜审会检查上一营业日订单和账务、完成入账/结账并锁定报表，夜审差错通过调整链处理。',
    '入住类型、退房类型、收银项目、支付方式和支付金额是不同维度，不能压成一个订单状态。',
    '早餐抛账可配置归属前一日或当日房费，不能写成行业常量。',
    '普通住与钟点住可以转换；换房在特定配置下可能拆成两个订单。',
    '部分产品版本提供AR账户/账龄、权限和操作日志能力。'
  ),
  JSON_ARRAY(
    '目标酒店实际使用的订单来了产品版本、套餐、模块和权限。',
    '当前营业日、夜审时点、夜审完成状态、阻断项和锁定范围。',
    '早餐、房费、税费、服务费、押金、退款和AR的真实配置与字段映射。',
    '换房是否拆单、订单号关联和历史订单回写规则。',
    'OTA自动对账的匹配公式、异常状态、核销及回写权限。',
    '当前酒店、订单、住客、账务、收款、应收和经营日事实。'
  )
),
(
  @dianping_review_unit,
  '将大众点评20260430版商户评价诚信管理总则和2026-07-08生效的违规评价管理分则转为独立平台治理合同；禁止将其混入美团评价算法，明确利益干扰、招募炒作、利益交换、模板/AIGC、证据与申诉边界。',
  JSON_ARRAY(
    '大众点评',
    '评价治理',
    '评价诚信',
    '利益干扰',
    '炒作',
    'AIGC',
    '申诉',
    'platform_separation',
    'official_current_rule',
    'structured_knowledge'
  ),
  'reviewed_dianping_integrity_rules_version_20260430_and_effective_2026_07_08',
  JSON_ARRAY(
    '大众点评具有独立于美团到店评价的适用范围和规则版本。',
    '商户本人或利益相关方评价、招募虚假好评、利益交换、模板化评价和虚构事实的AIGC内容属于治理风险。',
    '要求消费者提供好评截图或以权益换取评价会形成评价干扰风险。',
    '违规评价处理、商户处罚和申诉必须按大众点评当前规则与证据链保存，不能复用美团HOS或星级算法。'
  ),
  JSON_ARRAY(
    '目标商户账号当前可见的评价、投诉、处罚、申诉入口和时限。',
    '平台识别阈值、权重、折叠/不计分、推荐和排序算法。',
    '某条评价是否违规、责任方、证据真实性和最终处理结果。',
    '大众点评规则未来替代版本及不同业务类目的差异。',
    '任何点评动作对订单或收益的真实因果效果。'
  )
);

INSERT INTO `knowledge_units` (
  `hotel_id`,
  `name`,
  `source`,
  `status`,
  `description`,
  `tags`,
  `created_by`,
  `lifecycle_status`,
  `lifecycle_reason`,
  `reviewed_at`,
  `known_knowns`,
  `known_unknowns`,
  `truth_profile_version`,
  `created_at`,
  `updated_at`
)
SELECT
  0,
  `seed`.`name`,
  @gap_sem_source,
  'done',
  `seed`.`description`,
  `seed`.`tags`,
  0,
  'active',
  `seed`.`lifecycle_reason`,
  CONCAT(@gap_sem_reviewed_at, ' 00:00:00'),
  `seed`.`known_knowns`,
  `seed`.`known_unknowns`,
  @gap_sem_version,
  NOW(),
  NOW()
FROM `tmp_gap_semantic_units` AS `seed`
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units` AS `existing`
  WHERE `existing`.`name` = `seed`.`name`
    AND `existing`.`source` = @gap_sem_source
);

UPDATE `knowledge_units` AS `existing`
INNER JOIN `tmp_gap_semantic_units` AS `seed`
  ON `seed`.`name` = `existing`.`name`
SET
  `existing`.`hotel_id` = 0,
  `existing`.`source` = @gap_sem_source,
  `existing`.`status` = 'done',
  `existing`.`description` = `seed`.`description`,
  `existing`.`tags` = `seed`.`tags`,
  `existing`.`created_by` = 0,
  `existing`.`lifecycle_status` = 'active',
  `existing`.`lifecycle_reason` = `seed`.`lifecycle_reason`,
  `existing`.`reviewed_at` = CONCAT(@gap_sem_reviewed_at, ' 00:00:00'),
  `existing`.`known_knowns` = `seed`.`known_knowns`,
  `existing`.`known_unknowns` = `seed`.`known_unknowns`,
  `existing`.`truth_profile_version` = @gap_sem_version,
  `existing`.`updated_at` = NOW()
WHERE `existing`.`source` = @gap_sem_source;

SET @ctrip_fulfillment_manifest := JSON_OBJECT(
  'ctrip_hotel_merchant_rules_2025_11', JSON_OBJECT(
    'publisher', '携程',
    'published_at', '2025-11-03',
    'effective_at', '2025-11-10',
    'url', 'https://pages.ctrip.com/hotels/IBU/pages/hotelspecification.html',
    'used_sections', JSON_ARRAY(
      '订单确认与履约',
      '商家诱导取消或转店',
      '发票责任',
      '赔付与结算扣款',
      '处罚申诉'
    ),
    'accessed_at', @gap_sem_reviewed_at,
    'verification_status', 'official_current_rule_visible'
  )
);

SET @dingdandao_current_manifest := JSON_OBJECT(
  'dingdandao_night_audit_2024_06', JSON_OBJECT(
    'publisher', '订单来了',
    'published_at', '2024-06-04',
    'url', 'https://www.dingdandao.com/document/665ed779e4b0f1435af185d0',
    'used_for', 'night_audit_business_day_reports_and_checkout_dimensions',
    'accessed_at', @gap_sem_reviewed_at,
    'verification_status', 'official_versioned_product_doc_visible'
  ),
  'dingdandao_order_accounting_2026_04', JSON_OBJECT(
    'publisher', '订单来了',
    'published_at', '2026-04-10',
    'url', 'https://www.dingdandao.com/document/69d8d6f8e4b06f74acef1d5c',
    'used_for', 'posting_configuration_stay_conversion_order_split_ar_permissions_and_audit',
    'accessed_at', @gap_sem_reviewed_at,
    'verification_status', 'official_versioned_product_doc_visible'
  )
);

SET @dianping_review_manifest := JSON_OBJECT(
  'dianping_integrity_general_20260430', JSON_OBJECT(
    'publisher', '大众点评规则中心',
    'rule_version', '20260430',
    'published_at', '2026-05-15',
    'url', 'https://rules-center.meituan.com/m/detail/guize/191?commonType=20',
    'used_for', 'merchant_review_integrity_and_interference_boundary',
    'accessed_at', @gap_sem_reviewed_at,
    'verification_status', 'official_current_rule_visible'
  ),
  'dianping_violation_rules_20260708', JSON_OBJECT(
    'publisher', '大众点评规则中心',
    'effective_at', '2026-07-08',
    'url', 'https://rules-center.meituan.com/m/detail/guize/324000',
    'used_for', 'invalid_review_types_and_platform_separation',
    'accessed_at', @gap_sem_reviewed_at,
    'verification_status', 'official_current_rule_visible'
  )
);

DROP TEMPORARY TABLE IF EXISTS `tmp_gap_semantic_chunks`;
CREATE TEMPORARY TABLE `tmp_gap_semantic_chunks` (
  `unit_name` VARCHAR(255) NOT NULL,
  `type` VARCHAR(100) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_gap_sem_unit` (`unit_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_gap_semantic_chunks`
  (`unit_name`, `type`, `content`, `created_by`, `created_at`)
VALUES
(
  @ctrip_fulfillment_unit,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('ctrip'),
    'module_id', 'ctrip_order_fulfillment_settlement_contract',
    'roles', JSON_ARRAY('owner', 'front_office_manager', 'revenue_manager', 'finance'),
    'scenes', JSON_ARRAY('order_exception', 'fulfillment_review', 'settlement_review', 'appeal'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY('ctrip_hotel_merchant_rules_2025_11'),
    'source_manifest', JSON_EXTRACT(@ctrip_fulfillment_manifest, '$'),
    'effective_from', '2025-11-10',
    'reviewed_at', @gap_sem_reviewed_at,
    'source_version_fingerprint', SHA2(
      'ctrip_hotel_merchant_rules_2025_11|published=2025-11-03|effective=2025-11-10',
      256
    ),
    'allowed_uses', JSON_ARRAY(
      'order_state_mapping',
      'exception_workflow_explanation',
      'evidence_checklist',
      'settlement_risk_review'
    ),
    'blocked_uses', JSON_ARRAY(
      'current_hotel_fact_without_order_evidence',
      'automatic_cancel_or_rebook',
      'automatic_penalty_or_appeal_decision',
      'whole_hotel_conclusion',
      'private_algorithm_inference'
    )
  ),
  0,
  NOW()
),
(
  @ctrip_fulfillment_unit,
  'order_state_contract',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('ctrip'),
    'module_id', 'ctrip_order_fulfillment_settlement_contract',
    'roles', JSON_ARRAY('front_office_manager', 'operations_manager', 'owner'),
    'scenes', JSON_ARRAY('order_confirmation', 'arrival_exception', 'fulfillment_review'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY('ctrip_hotel_merchant_rules_2025_11'),
    'source_manifest', JSON_EXTRACT(@ctrip_fulfillment_manifest, '$'),
    'state_contract', JSON_OBJECT(
      'unconfirmed_or_rejected', '订单尚未被商家确认或被拒绝，需保留平台状态、拒绝原因和通知时间',
      'confirmed_then_overturned', '已确认后又声称无法履约，必须独立记录，不能回写为普通拒单',
      'reservation_missing', '客人到店时酒店查无预订，需记录订单号、核验动作和最终安排',
      'arrival_unable_to_fulfill', '到店后无法按原订单房型、日期或权益履约，需记录差异和补救结果',
      'fulfilled', '只有订单、入住和权益均有证据时才可标记履约完成'
    ),
    'minimum_evidence', JSON_ARRAY(
      'platform_order_id',
      'hotel_identity',
      'checkin_checkout_date',
      'platform_status_and_timestamps',
      'hotel_confirmation_evidence',
      'arrival_or_contact_evidence',
      'final_resolution'
    ),
    'decision_boundary', '规则定义状态和责任风险，不证明目标订单发生过异常，也不自动判定责任。',
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @ctrip_fulfillment_unit,
  'cancellation_diversion_contract',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('ctrip'),
    'module_id', 'ctrip_order_fulfillment_settlement_contract',
    'roles', JSON_ARRAY('front_office_manager', 'operations_manager', 'owner'),
    'scenes', JSON_ARRAY('cancellation_review', 'diversion_review', 'guest_resolution'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY('ctrip_hotel_merchant_rules_2025_11'),
    'source_manifest', JSON_EXTRACT(@ctrip_fulfillment_manifest, '$'),
    'cause_contract', JSON_OBJECT(
      'guest_initiated', '客人自主提出且有可核验证据',
      'merchant_induced_cancel', '商家要求或诱导客人取消平台订单',
      'merchant_diversion', '商家引导改订其他酒店、线下或其他渠道',
      'platform_or_force_majeure', '平台或不可抗力原因需另行保留证据，不能猜测'
    ),
    'required_separation', JSON_ARRAY(
      'who_initiated',
      'contact_time',
      'stated_reason',
      'offered_alternative',
      'price_or_rights_difference',
      'guest_acceptance',
      'final_platform_status'
    ),
    'blocked_inference', '不得仅凭订单取消结果推断客人意愿或商家责任。',
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @ctrip_fulfillment_unit,
  'invoice_contract',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('ctrip'),
    'module_id', 'ctrip_order_fulfillment_settlement_contract',
    'roles', JSON_ARRAY('front_office_manager', 'finance', 'owner'),
    'scenes', JSON_ARRAY('invoice_request', 'invoice_exception', 'settlement_review'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY('ctrip_hotel_merchant_rules_2025_11'),
    'source_manifest', JSON_EXTRACT(@ctrip_fulfillment_manifest, '$'),
    'invoice_states', JSON_ARRAY(
      'not_requested',
      'requested',
      'information_pending',
      'issued',
      'delivery_pending',
      'delivered',
      'rejected_or_disputed'
    ),
    'minimum_evidence', JSON_ARRAY(
      'invoice_responsible_party',
      'request_time',
      'invoice_information',
      'amount_and_tax_scope',
      'issue_time',
      'delivery_evidence',
      'exception_reason'
    ),
    'boundary', '公开规则用于责任和流程解释，不代替税务判断，也不证明当前订单由酒店或平台开票。',
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @ctrip_fulfillment_unit,
  'penalty_settlement_appeal_contract',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('ctrip'),
    'module_id', 'ctrip_order_fulfillment_settlement_contract',
    'roles', JSON_ARRAY('owner', 'finance', 'operations_manager'),
    'scenes', JSON_ARRAY('penalty_notice', 'settlement_deduction', 'appeal'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY('ctrip_hotel_merchant_rules_2025_11'),
    'source_manifest', JSON_EXTRACT(@ctrip_fulfillment_manifest, '$'),
    'state_contract', JSON_OBJECT(
      'notice_received', '保存通知原文、规则版本、订单号和接收时间',
      'liability_under_review', '事实、证据和责任尚未确认',
      'compensation_or_penalty_confirmed', '保存确认依据、金额、币种和对应订单',
      'settlement_deduction_pending', '已通知但尚未在结算单核验',
      'settlement_deduction_matched', '处罚/赔付与结算扣款逐笔匹配',
      'appeal_submitted', '保存提交时间、证据清单和申诉编号',
      'appeal_resolved', '保存平台结果与后续账务处理'
    ),
    'appeal_window', JSON_OBJECT(
      'value', 5,
      'unit', 'working_day',
      'starts_from', 'penalty_notice_received',
      'version_scope', 'rule_effective_2025_11_10',
      'execution_guard', '以当前通知和当前规则页面为准'
    ),
    'accounting_boundary', '平台通知金额、赔付、结算扣款和会计费用是不同事实，必须逐步核验。',
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
);

UPDATE `tmp_gap_semantic_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.seed_owner', @gap_sem_seed_owner,
  '$.seed_key', CONCAT(
    JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.module_id')),
    ':',
    `type`
  ),
  '$.seed_version', @gap_sem_version,
  '$.lifecycle_status', 'active'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `existing`.`unit_id`
  AND `unit`.`source` = @gap_sem_source
INNER JOIN `tmp_gap_semantic_chunks` AS `seed`
  ON `seed`.`unit_name` = `unit`.`name`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = @gap_sem_seed_owner
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
SET `existing`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
  '$.lifecycle_status', 'stale',
  '$.superseded_by_version', @gap_sem_version,
  '$.superseded_at', @gap_sem_reviewed_at
)
WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_version')), '')
  <> @gap_sem_version;

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `existing`.`unit_id`
  AND `unit`.`source` = @gap_sem_source
INNER JOIN `tmp_gap_semantic_chunks` AS `seed`
  ON `seed`.`unit_name` = `unit`.`name`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_version'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = 0;

INSERT INTO `knowledge_chunks` (
  `unit_id`,
  `type`,
  `content`,
  `created_by`,
  `created_at`
)
SELECT
  `unit`.`unit_id`,
  `seed`.`type`,
  `seed`.`content`,
  0,
  `seed`.`created_at`
FROM `tmp_gap_semantic_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`name` = `seed`.`unit_name`
  AND `unit`.`source` = @gap_sem_source
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `unit`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_owner'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_key'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_version'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

UPDATE `tmp_gap_semantic_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.seed_owner', @gap_sem_seed_owner,
  '$.seed_key', CONCAT(
    JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.module_id')),
    ':',
    `type`
  ),
  '$.seed_version', @gap_sem_version,
  '$.lifecycle_status', 'active'
);

-- Older versions owned by this exact seed are retained for audit but removed
-- from default retrieval.
UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `existing`.`unit_id`
  AND `unit`.`source` = @gap_sem_source
INNER JOIN `tmp_gap_semantic_chunks` AS `seed`
  ON `seed`.`unit_name` = `unit`.`name`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = @gap_sem_seed_owner
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
SET `existing`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
  '$.lifecycle_status', 'stale',
  '$.superseded_by_version', @gap_sem_version,
  '$.superseded_at', @gap_sem_reviewed_at
)
WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_version')), '')
  <> @gap_sem_version;

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `existing`.`unit_id`
  AND `unit`.`source` = @gap_sem_source
INNER JOIN `tmp_gap_semantic_chunks` AS `seed`
  ON `seed`.`unit_name` = `unit`.`name`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_version'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = 0;

INSERT INTO `knowledge_chunks` (
  `unit_id`,
  `type`,
  `content`,
  `created_by`,
  `created_at`
)
SELECT
  `unit`.`unit_id`,
  `seed`.`type`,
  `seed`.`content`,
  0,
  `seed`.`created_at`
FROM `tmp_gap_semantic_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`name` = `seed`.`unit_name`
  AND `unit`.`source` = @gap_sem_source
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `unit`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_owner'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_key'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_version'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_gap_semantic_chunks`;
DROP TEMPORARY TABLE `tmp_gap_semantic_units`;

-- Employee-facing mirrors. Structured chunks above remain the source of truth.

SET @ctrip_fulfillment_staff_content := CONCAT(
  '# 携程订单履约与结算官方语义合同', '\n\n',
  '## 已知的已知', '\n',
  '未确认/拒单、确认后推翻、预订不存在、到店无法履约、商家诱导取消或转店是不同状态；发票、赔付、结算扣款和申诉也必须分别保存。', '\n\n',
  '## 执行边界', '\n',
  '规则版本发布于2025-11-03、2025-11-10生效。收到处罚通知后的申诉窗口按该版本为5个工作日，但执行时仍以当前通知和当前页面为准。', '\n\n',
  '## 已知的未知', '\n',
  '当前酒店是否存在异常、责任方、扣款金额、申诉入口和平台内部算法均未知；必须回到目标订单证据核验。', '\n\n',
  '## 保护边界', '\n',
  '本知识不授权取消、改订、赔付、开票、申诉或任何平台写入，也不把携程订单外推为全酒店事实。'
);

SET @dingdandao_current_staff_content := CONCAT(
  '# 订单来了PMS当前版本官方语义合同', '\n\n',
  '## 已知的已知', '\n',
  '夜审检查上一营业日订单与账务，完成入账/结账并锁定报表；入住类型、退房类型、收银项目、支付方式和金额需分开。早餐抛账日期可配置，换房在特定配置下可能拆单，部分版本提供AR、账龄、权限和操作日志。', '\n\n',
  '## 已知的未知', '\n',
  '目标酒店产品版本、套餐、夜审时点、早餐与换房配置、AR和OTA自动对账规则尚未通过同店同日保存回读验证。', '\n\n',
  '## 保护边界', '\n',
  '公开产品文档只证明版本能力，不证明目标租户已启用；不授权PMS、账务、结算或权限写入。'
);

SET @dianping_review_staff_content := CONCAT(
  '# 大众点评独立评价规则官方语义合同', '\n\n',
  '## 平台边界', '\n',
  '大众点评评价规则必须使用platform=dianping独立建模，不得混入美团HOS、星级或酒店评价算法。', '\n\n',
  '## 禁止与高风险行为', '\n',
  '利益相关方评价、招募虚假好评、以权益换评价、要求好评截图、代写/模板化内容、虚构事实的AIGC内容均属于治理风险。', '\n\n',
  '## 已知的未知', '\n',
  '识别阈值、权重、排序影响、当前账号申诉入口和个案处理结果均未知。', '\n\n',
  '## 保护边界', '\n',
  '本知识只用于规则解释、培训和证据清单，不生成评价，不自动投诉/申诉，也不保存住客敏感信息。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`,
  `hotel_id`,
  `category_id`,
  `title`,
  `content`,
  `keywords`,
  `tags`,
  `sort_order`,
  `is_enabled`,
  `view_count`,
  `like_count`,
  `create_time`,
  `update_time`
)
SELECT
  0,
  0,
  7,
  @ctrip_fulfillment_unit,
  @ctrip_fulfillment_staff_content,
  '携程,订单履约,拒单,确认后推翻,取消,转店,发票,赔付,结算扣款,申诉',
  JSON_ARRAY('携程', '订单履约', '结算', 'official_current_rule'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_base`
  WHERE `hotel_id` = 0
    AND `title` = @ctrip_fulfillment_unit
);

INSERT INTO `knowledge_base` (
  `tenant_id`,
  `hotel_id`,
  `category_id`,
  `title`,
  `content`,
  `keywords`,
  `tags`,
  `sort_order`,
  `is_enabled`,
  `view_count`,
  `like_count`,
  `create_time`,
  `update_time`
)
SELECT
  0,
  0,
  8,
  @dingdandao_current_unit,
  @dingdandao_current_staff_content,
  '订单来了,PMS,夜审,经营日,结账,支付方式,早餐抛账,换房拆单,AR,权限审计',
  JSON_ARRAY('订单来了', 'PMS', '夜审', '账务', 'official_versioned_product_docs'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_base`
  WHERE `hotel_id` = 0
    AND `title` = @dingdandao_current_unit
);

INSERT INTO `knowledge_base` (
  `tenant_id`,
  `hotel_id`,
  `category_id`,
  `title`,
  `content`,
  `keywords`,
  `tags`,
  `sort_order`,
  `is_enabled`,
  `view_count`,
  `like_count`,
  `create_time`,
  `update_time`
)
SELECT
  0,
  0,
  7,
  @dianping_review_unit,
  @dianping_review_staff_content,
  '大众点评,评价规则,诚信评价,利益干扰,炒作,AIGC,申诉,平台隔离',
  JSON_ARRAY('大众点评', '评价治理', 'platform_separation', 'official_current_rule'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_base`
  WHERE `hotel_id` = 0
    AND `title` = @dianping_review_unit
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `hotel_id` = 0,
  `category_id` = 7,
  `content` = @ctrip_fulfillment_staff_content,
  `keywords` = '携程,订单履约,拒单,确认后推翻,取消,转店,发票,赔付,结算扣款,申诉',
  `tags` = JSON_ARRAY('携程', '订单履约', '结算', 'official_current_rule'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @ctrip_fulfillment_unit;

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `hotel_id` = 0,
  `category_id` = 8,
  `content` = @dingdandao_current_staff_content,
  `keywords` = '订单来了,PMS,夜审,经营日,结账,支付方式,早餐抛账,换房拆单,AR,权限审计',
  `tags` = JSON_ARRAY('订单来了', 'PMS', '夜审', '账务', 'official_versioned_product_docs'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @dingdandao_current_unit;

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `hotel_id` = 0,
  `category_id` = 7,
  `content` = @dianping_review_staff_content,
  `keywords` = '大众点评,评价规则,诚信评价,利益干扰,炒作,AIGC,申诉,平台隔离',
  `tags` = JSON_ARRAY('大众点评', '评价治理', 'platform_separation', 'official_current_rule'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @dianping_review_unit;

-- The Ctrip contract above is merged first so it can reuse the same bounded
-- temporary-table pattern. Recreate the table for the remaining two contracts.
DROP TEMPORARY TABLE IF EXISTS `tmp_gap_semantic_chunks`;
CREATE TEMPORARY TABLE `tmp_gap_semantic_chunks` (
  `unit_name` VARCHAR(255) NOT NULL,
  `type` VARCHAR(100) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_gap_sem_unit` (`unit_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_gap_semantic_chunks`
  (`unit_name`, `type`, `content`, `created_by`, `created_at`)
VALUES
(
  @dianping_review_unit,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('dianping'),
    'module_id', 'dianping_review_governance_contract',
    'roles', JSON_ARRAY('owner', 'operations_manager', 'guest_relation'),
    'scenes', JSON_ARRAY('review_governance', 'review_complaint', 'evidence_review', 'appeal'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY(
      'dianping_integrity_general_20260430',
      'dianping_violation_rules_20260708'
    ),
    'source_manifest', JSON_EXTRACT(@dianping_review_manifest, '$'),
    'reviewed_at', @gap_sem_reviewed_at,
    'source_version_fingerprint', SHA2(
      'dianping|integrity_version=20260430|violation_effective=2026-07-08',
      256
    ),
    'allowed_uses', JSON_ARRAY(
      'review_governance_explanation',
      'operator_training',
      'evidence_checklist',
      'complaint_and_appeal_workflow'
    ),
    'blocked_uses', JSON_ARRAY(
      'merge_with_meituan_rating_algorithm',
      'infer_private_score_weight',
      'automatic_review_generation',
      'automatic_complaint_or_appeal',
      'current_merchant_violation_claim_without_case_evidence'
    )
  ),
  0,
  NOW()
),
(
  @dianping_review_unit,
  'platform_separation_contract',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('dianping'),
    'module_id', 'dianping_review_governance_contract',
    'roles', JSON_ARRAY('owner', 'operations_manager', 'data_analyst'),
    'scenes', JSON_ARRAY('metric_mapping', 'review_analysis', 'rule_selection'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY(
      'dianping_integrity_general_20260430',
      'dianping_violation_rules_20260708'
    ),
    'source_manifest', JSON_EXTRACT(@dianping_review_manifest, '$'),
    'rules', JSON_ARRAY(
      'platform_id必须保存为dianping，不得因规则域名位于meituan.com而标成meituan',
      '大众点评评价、处罚和申诉状态不得写入美团HOS、星级或酒店评价字段',
      '跨平台分析只能在分别完成字段、版本和样本范围映射后做对比',
      '规则中心域名是发布载体，不改变规则适用平台'
    ),
    'required_identity', JSON_ARRAY(
      'platform_id',
      'merchant_or_poi_id',
      'rule_version',
      'effective_at',
      'review_id_if_case_specific'
    ),
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @dianping_review_unit,
  'prohibited_review_manipulation',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('dianping'),
    'module_id', 'dianping_review_governance_contract',
    'roles', JSON_ARRAY('owner', 'operations_manager', 'guest_relation', 'marketing'),
    'scenes', JSON_ARRAY('review_request', 'campaign_design', 'staff_training'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY(
      'dianping_integrity_general_20260430',
      'dianping_violation_rules_20260708'
    ),
    'source_manifest', JSON_EXTRACT(@dianping_review_manifest, '$'),
    'prohibited_or_high_risk_patterns', JSON_ARRAY(
      '商户本人、员工或其他利益相关方评价',
      '招募、购买或组织虚假好评',
      '以返现、赠品、优惠或其他利益交换指定评价',
      '要求顾客出示好评截图或以截图作为权益条件',
      '商户代写、统一模板、复制粘贴或批量生成评价',
      '使用AIGC虚构未真实发生的消费事实或体验',
      '攻击竞对、异常重复、广告导流或与实际消费无关的评价'
    ),
    'safe_operator_boundary', JSON_ARRAY(
      '可以邀请顾客基于真实体验自愿评价',
      '不得限定星级、内容、关键词或要求提供好评证明',
      '回复只陈述可核验事实并保护个人信息',
      '营销活动与评价权益必须分离'
    ),
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @dianping_review_unit,
  'evidence_complaint_appeal_contract',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('dianping'),
    'module_id', 'dianping_review_governance_contract',
    'roles', JSON_ARRAY('owner', 'operations_manager', 'guest_relation'),
    'scenes', JSON_ARRAY('review_complaint', 'evidence_review', 'appeal'),
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY(
      'dianping_integrity_general_20260430',
      'dianping_violation_rules_20260708'
    ),
    'source_manifest', JSON_EXTRACT(@dianping_review_manifest, '$'),
    'case_states', JSON_ARRAY(
      'review_visible',
      'merchant_reviewing',
      'complaint_draft',
      'complaint_submitted',
      'platform_reviewing',
      'resolved_kept',
      'resolved_processed',
      'appeal_available_or_unknown',
      'appeal_submitted',
      'appeal_resolved'
    ),
    'minimum_evidence', JSON_ARRAY(
      'platform_id',
      'poi_id',
      'review_id',
      'review_time',
      'rule_version',
      'claimed_violation_type',
      'order_or_visit_evidence_when_lawful',
      'merchant_response',
      'submission_time',
      'platform_result'
    ),
    'privacy_boundary', '证件、电话、支付信息和住客身份不得写入通用知识；个案证据只在授权业务表中最小化保存。',
    'deadline_boundary', '当前公开规则版本未被本知识固化为统一申诉时限；执行前读取当前账号页面提示。',
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @dianping_review_unit,
  'penalty_and_algorithm_boundary',
  JSON_OBJECT(
    'scope', 'platform_rule',
    'platforms', JSON_ARRAY('dianping'),
    'module_id', 'dianping_review_governance_contract',
    'roles', JSON_ARRAY('owner', 'operations_manager', 'data_analyst'),
    'scenes', JSON_ARRAY('risk_review', 'rating_analysis', 'appeal'),
    'evidence_level', 'official_current_rule_with_private_algorithm_unknown',
    'source_refs', JSON_ARRAY(
      'dianping_integrity_general_20260430',
      'dianping_violation_rules_20260708'
    ),
    'source_manifest', JSON_EXTRACT(@dianping_review_manifest, '$'),
    'known_knowns', JSON_ARRAY(
      '平台可按规则处理违规评价和商户违规行为',
      '评价是否展示、折叠或计入某指标必须以当前平台结果字段为准',
      '平台处理结果、商户处罚和申诉结果是不同状态'
    ),
    'known_unknowns', JSON_ARRAY(
      '识别模型与阈值',
      '评价权重和时间衰减',
      '推荐、搜索和排序影响',
      '酒店类目具体执行尺度',
      '个案处罚与恢复条件'
    ),
    'blocked_claims', JSON_ARRAY(
      '某类评价必然提高或降低排序',
      '删除评价必然恢复评分',
      '大众点评权重等于美团权重',
      '规则处理等于当前酒店已受处罚'
    ),
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
);

INSERT INTO `tmp_gap_semantic_chunks`
  (`unit_name`, `type`, `content`, `created_by`, `created_at`)
VALUES
(
  @dingdandao_current_unit,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'vendor_versioned_workflow',
    'platforms', JSON_ARRAY('pms', 'dingdandao'),
    'module_id', 'dingdandao_pms_business_day_accounting_contract',
    'roles', JSON_ARRAY('owner', 'front_office_manager', 'finance', 'revenue_manager'),
    'scenes', JSON_ARRAY('night_audit', 'checkout', 'posting', 'ar_review', 'permission_review'),
    'evidence_level', 'official_versioned_product_docs',
    'source_refs', JSON_ARRAY(
      'dingdandao_night_audit_2024_06',
      'dingdandao_order_accounting_2026_04'
    ),
    'source_manifest', JSON_EXTRACT(@dingdandao_current_manifest, '$'),
    'reviewed_at', @gap_sem_reviewed_at,
    'source_version_fingerprint', SHA2(
      'dingdandao|night_audit=2024-06-04|order_accounting=2026-04-10',
      256
    ),
    'allowed_uses', JSON_ARRAY(
      'pms_field_mapping',
      'business_day_explanation',
      'night_audit_status_design',
      'accounting_and_reconciliation_workflow'
    ),
    'blocked_uses', JSON_ARRAY(
      'assume_target_tenant_feature_enabled',
      'assume_fixed_night_audit_time',
      'automatic_pms_or_finance_write',
      'current_hotel_fact_without_save_readback',
      'copy_vendor_configuration_as_industry_constant'
    )
  ),
  0,
  NOW()
),
(
  @dingdandao_current_unit,
  'night_audit_business_day_contract',
  JSON_OBJECT(
    'scope', 'vendor_versioned_workflow',
    'platforms', JSON_ARRAY('pms', 'dingdandao'),
    'module_id', 'dingdandao_pms_business_day_accounting_contract',
    'roles', JSON_ARRAY('front_office_manager', 'finance', 'owner'),
    'scenes', JSON_ARRAY('night_audit', 'business_day_close', 'report_lock'),
    'evidence_level', 'official_versioned_product_doc',
    'source_refs', JSON_ARRAY('dingdandao_night_audit_2024_06'),
    'source_manifest', JSON_EXTRACT(@dingdandao_current_manifest, '$'),
    'workflow', JSON_ARRAY(
      '检查上一营业日订单与账务完整性',
      '完成应入账项目和应结账项目处理',
      '执行夜审并切换营业日',
      '锁定已生成的营业日报表',
      '发现夜审差错时通过调整或冲销链处理，不直接删除历史'
    ),
    'required_fields', JSON_ARRAY(
      'hotel_id',
      'business_date',
      'natural_timestamp',
      'night_audit_status',
      'night_audit_started_at',
      'night_audit_completed_at',
      'blocking_item_count',
      'report_lock_status',
      'adjustment_reference'
    ),
    'boundary', '经营日不自动等于自然日；夜审时点、阻断项和锁定范围由产品版本与酒店配置决定。',
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @dingdandao_current_unit,
  'checkout_settlement_dimensions',
  JSON_OBJECT(
    'scope', 'vendor_versioned_workflow',
    'platforms', JSON_ARRAY('pms', 'dingdandao'),
    'module_id', 'dingdandao_pms_business_day_accounting_contract',
    'roles', JSON_ARRAY('front_office_manager', 'finance', 'revenue_manager'),
    'scenes', JSON_ARRAY('checkout', 'cashier_review', 'channel_comparison'),
    'evidence_level', 'official_versioned_product_doc',
    'source_refs', JSON_ARRAY('dingdandao_night_audit_2024_06'),
    'source_manifest', JSON_EXTRACT(@dingdandao_current_manifest, '$'),
    'separate_dimensions', JSON_ARRAY(
      'stay_type',
      'checkout_type',
      'order_status',
      'cashier_item_type',
      'payment_method',
      'payment_amount',
      'business_date',
      'channel'
    ),
    'rules', JSON_ARRAY(
      '入住类型不能由退房类型推断',
      '退房不等于结清或会计收入确认',
      '收银项目、支付方式和支付金额必须分别保存',
      '渠道对比需绑定同一营业日、币种和状态口径'
    ),
    'known_unknowns', JSON_ARRAY(
      '当前版本枚举值',
      '当前酒店支付方式映射',
      '退房未结与挂AR的实际处理',
      '渠道比较是否含取消、No Show和钟点房'
    ),
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @dingdandao_current_unit,
  'configurable_posting_and_order_split',
  JSON_OBJECT(
    'scope', 'vendor_versioned_workflow',
    'platforms', JSON_ARRAY('pms', 'dingdandao'),
    'module_id', 'dingdandao_pms_business_day_accounting_contract',
    'roles', JSON_ARRAY('front_office_manager', 'finance', 'system_admin'),
    'scenes', JSON_ARRAY('posting_configuration', 'stay_type_conversion', 'room_change'),
    'evidence_level', 'official_versioned_product_doc',
    'source_refs', JSON_ARRAY('dingdandao_order_accounting_2026_04'),
    'source_manifest', JSON_EXTRACT(@dingdandao_current_manifest, '$'),
    'versioned_capabilities', JSON_OBJECT(
      'breakfast_posting_date', '可配置归入前一日或当日房费',
      'stay_type_conversion', '普通住与钟点住可按产品规则转换',
      'room_change_order_split', '特定配置下换房可能拆成两个关联订单'
    ),
    'required_configuration_evidence', JSON_ARRAY(
      'product_edition',
      'feature_flag_or_configuration',
      'effective_at',
      'operator_id',
      'before_value',
      'after_value',
      'linked_order_ids'
    ),
    'boundary', '这些是公开版本能力，不证明目标租户已启用；早餐归属、钟点房和换房拆单不得写成行业常量。',
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
),
(
  @dingdandao_current_unit,
  'ar_permission_audit_contract',
  JSON_OBJECT(
    'scope', 'vendor_versioned_workflow',
    'platforms', JSON_ARRAY('pms', 'dingdandao'),
    'module_id', 'dingdandao_pms_business_day_accounting_contract',
    'roles', JSON_ARRAY('owner', 'finance', 'system_admin'),
    'scenes', JSON_ARRAY('ar_account', 'aging_review', 'permission_review', 'audit_log'),
    'evidence_level', 'official_versioned_product_doc',
    'source_refs', JSON_ARRAY('dingdandao_order_accounting_2026_04'),
    'source_manifest', JSON_EXTRACT(@dingdandao_current_manifest, '$'),
    'separate_objects', JSON_ARRAY(
      'guest_or_company_order',
      'folio_or_cashier_item',
      'payment',
      'ar_account',
      'aging_bucket',
      'writeoff_or_settlement',
      'operator_permission',
      'audit_log'
    ),
    'minimum_control_fields', JSON_ARRAY(
      'account_id',
      'source_document_id',
      'amount',
      'currency',
      'business_date',
      'status',
      'operator_id',
      'operation_time',
      'permission_scope',
      'before_after_reference'
    ),
    'boundary', 'AR、账龄、权限和日志能力与产品版本相关；公开页面不证明当前酒店已购买、启用或完成正确配置。',
    'reviewed_at', @gap_sem_reviewed_at
  ),
  0,
  NOW()
);

UPDATE `tmp_gap_semantic_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.seed_owner', @gap_sem_seed_owner,
  '$.seed_key', CONCAT(
    JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.module_id')),
    ':',
    `type`
  ),
  '$.seed_version', @gap_sem_version,
  '$.lifecycle_status', 'active'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `existing`.`unit_id`
  AND `unit`.`source` = @gap_sem_source
INNER JOIN `tmp_gap_semantic_chunks` AS `seed`
  ON `seed`.`unit_name` = `unit`.`name`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = @gap_sem_seed_owner
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
SET `existing`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
  '$.lifecycle_status', 'stale',
  '$.superseded_by_version', @gap_sem_version,
  '$.superseded_at', @gap_sem_reviewed_at
)
WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`existing`.`content`, '$.seed_version')), '')
  <> @gap_sem_version;

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `existing`.`unit_id`
  AND `unit`.`source` = @gap_sem_source
INNER JOIN `tmp_gap_semantic_chunks` AS `seed`
  ON `seed`.`unit_name` = `unit`.`name`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_version'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = 0;

INSERT INTO `knowledge_chunks` (
  `unit_id`,
  `type`,
  `content`,
  `created_by`,
  `created_at`
)
SELECT
  `unit`.`unit_id`,
  `seed`.`type`,
  `seed`.`content`,
  0,
  `seed`.`created_at`
FROM `tmp_gap_semantic_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`name` = `seed`.`unit_name`
  AND `unit`.`source` = @gap_sem_source
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `unit`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_owner'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_key'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_version'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_gap_semantic_chunks`;
