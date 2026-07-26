-- Seed the reviewed operating method learned from a user-provided historical
-- Ctrip/Meituan daily workbook. The workbook structure is reviewed, while its
-- historical values remain unverified. This migration is safe to rerun and
-- preserves operator-authored chunks and chunks from older seed versions.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_daily_ledger_version := '2026-07-26.1';
SET @ota_daily_ledger_reviewed_at := '2026-07-26';
SET @ota_daily_ledger_seed_owner := 'suxios.ota_daily_operations_ledger_knowledge';
SET @ota_daily_ledger_unit_name := 'OTA每日经营台账与晨报闭环';
SET @ota_daily_ledger_source := 'ota_daily_operations_ledger_reference';
SET @ota_daily_ledger_description := '从用户提供的携程/美团历史日台账中提炼记录结构、漏斗口径、晨报节奏、质量守卫和数据到动作的复盘闭环。表格结构已复核，历史数值与平台来源未核验；仅用于OTA渠道运营参考。';

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`,
  `created_by`, `created_at`, `updated_at`
)
SELECT
  0,
  @ota_daily_ledger_unit_name,
  @ota_daily_ledger_source,
  'done',
  @ota_daily_ledger_description,
  JSON_ARRAY(
    'OTA日台账',
    '晨报闭环',
    '携程',
    '美团',
    'ota_channel',
    'reference_template',
    'historical_workbook_structure_reviewed',
    'historical_values_unverified'
  ),
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units`
  WHERE `name` = @ota_daily_ledger_unit_name
    AND `source` = @ota_daily_ledger_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @ota_daily_ledger_description,
  `tags` = JSON_ARRAY(
    'OTA日台账',
    '晨报闭环',
    '携程',
    '美团',
    'ota_channel',
    'reference_template',
    'historical_workbook_structure_reviewed',
    'historical_values_unverified'
  ),
  `updated_at` = NOW()
WHERE `name` = @ota_daily_ledger_unit_name
  AND `source` = @ota_daily_ledger_source;

SET @ota_daily_ledger_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @ota_daily_ledger_unit_name
    AND `source` = @ota_daily_ledger_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_ota_daily_ledger_seed_chunks`;
CREATE TEMPORARY TABLE `tmp_ota_daily_ledger_seed_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_ota_daily_ledger_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_ota_daily_ledger_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_daily_ledger_unit_id,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'ota_channel_daily_operations',
    'knowledge_status', 'reference_template',
    'evidence_level', 'historical_user_workbook_structure_reviewed_values_unverified',
    'source_file_name', '2026平台数据统计.xlsx新新.xlsx',
    'source_sha256', '9379BAC806CE041375CC56D89B119ECE17225A5105B8CC19C6D4A5F8522C70D5',
    'worksheet_count', 14,
    'reviewed_at', @ota_daily_ledger_reviewed_at,
    'source_refs', JSON_ARRAY(
      '.agents/skills/suxi-ota-ops/references/ota-daily-operations-ledger.md'
    ),
    'reviewed_claims', JSON_ARRAY(
      '一日一行、携程与美团分平台记录',
      '晨班记录前一业务日数据并生成晨报或截图',
      '本店与商圈或同行平均并排比较',
      '原始事实、派生指标、平台分值和人工备注需要分层'
    ),
    'unverified_claims', JSON_ARRAY(
      '历史数值真实性',
      '平台后台来源',
      '系统酒店与平台酒店身份',
      '业务日期与采集日期',
      '保存回读结果',
      '原表公式计算结果'
    ),
    'rules', JSON_ARRAY(
      '不保存原文件临时目录或用户标识路径',
      '本知识不是当前门店事实',
      'OTA渠道证据不得扩大为全酒店经营结论'
    )
  ),
  0,
  NOW()
WHERE @ota_daily_ledger_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_daily_ledger_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_daily_ledger_unit_id,
  'record_contract',
  JSON_OBJECT(
    'scope', 'daily_record_contract',
    'required_identity_fields', JSON_ARRAY(
      'system_hotel_id',
      'platform',
      'platform_hotel_id',
      'data_date',
      'collected_at',
      'source_method',
      'source_ref',
      'operator_id',
      'quality_status',
      'saved_at',
      'readback_status'
    ),
    'field_layers', JSON_OBJECT(
      'raw_facts', JSON_ARRAY('exposure', 'visitors', 'orders', 'room_nights', 'sales_amount', 'average_price', 'ranking'),
      'platform_scores', JSON_ARRAY('ctrip_service_quality_score'),
      'derived_metrics', JSON_ARRAY('exposure_conversion_rate', 'order_conversion_rate', 'browse_conversion_rate', 'payment_conversion_rate'),
      'operator_notes', JSON_ARRAY('hypothesis', 'executed_action', 'exception_note')
    ),
    'recording_rules', JSON_ARRAY(
      'data_date表示指标业务日，collected_at表示采集或导入时间',
      '月份只是展示分区，不是数据主键',
      '订单数与间夜数不得混用',
      '人工备注不得作为已验证事实',
      '保存成功后必须回读，晨报只能引用已回读版本'
    )
  ),
  0,
  NOW()
WHERE @ota_daily_ledger_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_daily_ledger_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_daily_ledger_unit_id,
  'ctrip_funnel',
  JSON_OBJECT(
    'scope', 'ctrip_and_qunar_channel_funnel',
    'base_fields', JSON_ARRAY(
      'data_date',
      'weekday',
      'city_rank',
      'app_visitors',
      'service_quality_score',
      'ctrip_orders',
      'ctrip_room_nights',
      'tongcheng_orders',
      'tongcheng_room_nights',
      'qunar_orders',
      'qunar_room_nights',
      'paid_traffic',
      'operator_note'
    ),
    'ctrip_fields', JSON_ARRAY(
      'hotel_list_exposure',
      'district_list_exposure',
      'hotel_detail_visitors',
      'district_detail_visitors',
      'hotel_order_page_visitors',
      'district_order_page_visitors'
    ),
    'ctrip_formulas', JSON_OBJECT(
      'P=N/L', 'hotel_exposure_conversion=hotel_detail_visitors/hotel_list_exposure',
      'Q=O/M', 'district_exposure_conversion=district_detail_visitors/district_list_exposure',
      'T=R/N', 'hotel_order_conversion=hotel_order_page_visitors/hotel_detail_visitors',
      'U=S/O', 'district_order_conversion=district_order_page_visitors/district_detail_visitors'
    ),
    'qunar_formulas', JSON_OBJECT(
      'Z=X/V', 'hotel_exposure_conversion=hotel_detail_visitors/hotel_list_exposure',
      'AA=Y/W', 'district_exposure_conversion=district_detail_visitors/district_list_exposure',
      'AD=AB/X', 'hotel_order_conversion=hotel_order_page_visitors/hotel_detail_visitors',
      'AE=AC/Y', 'district_order_conversion=district_order_page_visitors/district_detail_visitors'
    ),
    'private_score_rule', '服务质量分只保存平台展示值与时间，不反推平台私有权重'
  ),
  0,
  NOW()
WHERE @ota_daily_ledger_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_daily_ledger_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_daily_ledger_unit_id,
  'meituan_funnel',
  JSON_OBJECT(
    'scope', 'meituan_channel_funnel',
    'paired_fields', JSON_ARRAY(
      'hotel_exposure_pv',
      'peer_exposure_pv_average',
      'hotel_browse_uv',
      'peer_browse_uv_average',
      'hotel_paid_orders',
      'peer_paid_orders_average'
    ),
    'business_fields', JSON_ARRAY(
      'sales_room_nights',
      'stay_room_nights',
      'average_room_price',
      'business_district_rank',
      'category_rank',
      'city_rank',
      'negative_review_count',
      'operator_note'
    ),
    'formulas', JSON_OBJECT(
      'J=F/D', 'hotel_browse_conversion=hotel_browse_uv/hotel_exposure_pv',
      'K=G/E', 'peer_browse_conversion=peer_browse_uv_average/peer_exposure_pv_average',
      'L=H/F', 'hotel_payment_conversion=hotel_paid_orders/hotel_browse_uv',
      'M=I/G', 'peer_payment_conversion=peer_paid_orders_average/peer_browse_uv_average'
    ),
    'comparison_rule', '本店与同行平均必须来自同平台、同业务日和同口径'
  ),
  0,
  NOW()
WHERE @ota_daily_ledger_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_daily_ledger_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_daily_ledger_unit_id,
  'morning_loop',
  JSON_OBJECT(
    'scope', 'daily_brief_to_action_loop',
    'workflow', JSON_ARRAY(
      '昨日OTA事实',
      '本店与商圈或同行对比',
      '近7日自身基线',
      '定位一个首要漏斗瓶颈',
      '生成一项今日动作',
      '次日检查执行',
      '7日复盘效果'
    ),
    'diagnostic_order', JSON_ARRAY(
      JSON_OBJECT(
        'signal', '曝光低于同口径基准',
        'check_first', JSON_ARRAY('排名', '可售', '内容覆盖', '付费流量'),
        'claim_status', 'hypothesis_until_verified'
      ),
      JSON_OBJECT(
        'signal', '详情或浏览转化偏低',
        'check_first', JSON_ARRAY('首图', '标题', '卖点', '展示价', '房型表达'),
        'claim_status', 'hypothesis_until_verified'
      ),
      JSON_OBJECT(
        'signal', '下单或支付转化偏低',
        'check_first', JSON_ARRAY('价格竞争力', '取消政策', '库存', '套餐', '信任要素'),
        'claim_status', 'hypothesis_until_verified'
      )
    ),
    'action_contract', JSON_ARRAY(
      'owner',
      'due_at',
      'completion_evidence',
      'review_metric',
      'review_at',
      'stop_condition',
      'result'
    ),
    'causality_rule', '没有执行前后证据和复盘结果时，只能称排查假设，不能称已证明原因或有效动作'
  ),
  0,
  NOW()
WHERE @ota_daily_ledger_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_daily_ledger_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_daily_ledger_unit_id,
  'quality_guard',
  JSON_OBJECT(
    'scope', 'data_quality_and_truth_boundary',
    'observed_negative_examples', JSON_ARRAY(
      '公式与粘贴值混用',
      '#REF!',
      '#DIV/0!',
      '#VALUE!',
      '复制公式后本店与商圈引用错位',
      '星期显示AAAA',
      '标称2026的台账存在2025日期',
      '缺系统门店、平台门店、来源、采集时间、操作人和保存回读'
    ),
    'validation_rules', JSON_ARRAY(
      '星期由data_date统一计算',
      '原始值与派生值分开保存',
      '不导入Excel公式结果作为权威值',
      '分母为空、为零或质量不合格时返回不可计算',
      '错误行隔离并显示原因',
      '缺失数据保持unknown、unverified或blocked'
    ),
    'forbidden_fallbacks', JSON_ARRAY('0', 'empty_array', 'old_record', 'default_value'),
    'blocked_claims', JSON_ARRAY(
      '不得把OTA数据称为全酒店经营事实',
      '缺PMS或全渠道事实时不得输出全酒店出租率、ADR、RevPAR、利润或投资结论',
      '不得把历史工作簿数值称为当前已核验平台数据'
    )
  ),
  0,
  NOW()
WHERE @ota_daily_ledger_unit_id IS NOT NULL;

INSERT INTO `tmp_ota_daily_ledger_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ota_daily_ledger_unit_id,
  'landing_contract',
  JSON_OBJECT(
    'scope', 'minimum_usable_vertical_slice',
    'required_capabilities', JSON_ARRAY(
      '携程和美团日台账人工录入或文件导入',
      '身份、日期、来源和数值校验',
      '保存与回读',
      '固定口径转化率及不可计算原因',
      '昨日事实、差距、首要瓶颈、今日动作卡片',
      '负责人、截止时间、完成证据和次日或7日复盘',
      '可追溯晨报或截图'
    ),
    'acceptance_checks', JSON_ARRAY(
      '用户能找到记录入口',
      '用户能录入或导入并保存',
      '用户能回读同一数据版本',
      '质量状态真实可见',
      '系统只给一项有证据边界的动作',
      '动作结果可复盘'
    ),
    'stop_boundary', JSON_ARRAY(
      '不以复制Excel页面代替经营闭环',
      '不自动写回OTA价格、库存或活动',
      '不在本知识任务中扩展全酒店经营或投资结论'
    )
  ),
  0,
  NOW()
WHERE @ota_daily_ledger_unit_id IS NOT NULL;

UPDATE `tmp_ota_daily_ledger_seed_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.seed_owner', @ota_daily_ledger_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `seed`.`type`),
  '$.seed_version', @ota_daily_ledger_version
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_ota_daily_ledger_seed_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  `seed`.`unit_id`,
  `seed`.`type`,
  `seed`.`content`,
  `seed`.`created_by`,
  `seed`.`created_at`
FROM `tmp_ota_daily_ledger_seed_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_ota_daily_ledger_seed_chunks`;

SET @ota_daily_ledger_category_name := 'OTA运营与竞争分析';
SET @ota_daily_ledger_category_description := 'OTA日台账、晨报、渠道诊断、商圈比较和数据到动作复盘的参考知识。';

INSERT INTO `knowledge_categories` (
  `tenant_id`, `hotel_id`, `parent_id`, `name`, `description`,
  `sort_order`, `is_enabled`, `create_time`, `update_time`
)
SELECT
  0,
  0,
  0,
  @ota_daily_ledger_category_name,
  @ota_daily_ledger_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_categories`
  WHERE `hotel_id` = 0
    AND `parent_id` = 0
    AND `name` = @ota_daily_ledger_category_name
);

UPDATE `knowledge_categories`
SET
  `tenant_id` = 0,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `parent_id` = 0
  AND `name` = @ota_daily_ledger_category_name;

SET @ota_daily_ledger_category_id := (
  SELECT `id`
  FROM `knowledge_categories`
  WHERE `hotel_id` = 0
    AND `parent_id` = 0
    AND `name` = @ota_daily_ledger_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @ota_daily_ledger_staff_content := CONCAT(
  '# OTA每日经营台账与晨报闭环', '\n\n',
  '## 使用边界', '\n',
  '本知识来自用户提供的历史携程/美团Excel台账。记录结构与方法已复核，历史数值、平台来源、门店身份和保存回读未核验；只能作为OTA渠道运营参考，不能替代当前门店事实或全酒店经营事实。', '\n\n',
  '## 每日主线', '\n',
  '昨日OTA事实 → 本店与商圈/同行对比 → 漏斗瓶颈 → 今日一项动作 → 次日检查执行 → 7日复盘效果。', '\n\n',
  '## 记录要求', '\n',
  '- 每条记录绑定系统门店、平台门店、平台、业务日、采集时间、来源、操作人、质量状态和保存回读。', '\n',
  '- 原始事实、平台分值、派生指标和人工备注分开；备注先标假设。', '\n',
  '- 转化率由系统按固定分子分母计算；分母为空、为零或不合格时显示不可计算，不写0。', '\n',
  '- 携程和美团分平台保存，同日对比本店与商圈/同行，并参考近7日自身基线。', '\n\n',
  '## 质量守卫', '\n',
  '来源表存在公式错误、引用错位、星期格式异常和年份混用，不能直接导入公式结果。缺失数据保持unknown、unverified或blocked；没有PMS或全渠道事实时，不输出全酒店出租率、ADR、RevPAR、利润或投资结论。', '\n\n',
  '## 落地结果', '\n',
  '日台账应支持录入/导入、校验、保存、回读、转化计算、首要瓶颈、今日动作、完成证据和次日/7日复盘；知识本身不自动写回OTA价格、库存或活动。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  0,
  COALESCE(@ota_daily_ledger_category_id, 0),
  @ota_daily_ledger_unit_name,
  @ota_daily_ledger_staff_content,
  'OTA日台账,OTA晨报,携程流量,美团流量,曝光转化,支付转化,商圈对比,同行平均,昨日数据,今日动作,7日复盘,数据质量',
  JSON_ARRAY(
    'OTA日台账',
    '晨报闭环',
    '携程',
    '美团',
    'ota_channel',
    'reference_template',
    'historical_values_unverified'
  ),
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
    AND `title` = @ota_daily_ledger_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = COALESCE(@ota_daily_ledger_category_id, `category_id`),
  `content` = @ota_daily_ledger_staff_content,
  `keywords` = 'OTA日台账,OTA晨报,携程流量,美团流量,曝光转化,支付转化,商圈对比,同行平均,昨日数据,今日动作,7日复盘,数据质量',
  `tags` = JSON_ARRAY(
    'OTA日台账',
    '晨报闭环',
    '携程',
    '美团',
    'ota_channel',
    'reference_template',
    'historical_values_unverified'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @ota_daily_ledger_unit_name;
