-- Materialize reviewed domestic PMS business-day, order-state and
-- reconciliation semantics from public official vendor help, government
-- statistics, accounting standards and lodging-registration rules.
--
-- This package contains no current-hotel facts, guest PII or external write
-- authority. Vendor examples and regional deadlines remain version-scoped.
--
-- Safe rerun contract:
-- - preserve operator-authored chunks and older seed versions;
-- - update only this exact seed owner + key + version;
-- - never delete hotel facts or broad knowledge ranges.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @pms_sem_version := '2026-07-30.1';
SET @pms_sem_reviewed_at := '2026-07-30';
SET @pms_sem_seed_owner := 'suxios.domestic_pms_semantic_contract';
SET @pms_sem_unit_name := '国内PMS经营日、订单状态与对账官方语义合同';
SET @pms_sem_source := 'revenue_operations_decision_support';
SET @pms_sem_description := '将国内公开PMS帮助中心、政府住宿业统计、财政部收入准则和住宿登记规则转为版本化语义合同；拆分订单金额、夜审过房费、实收、应收、平台账单、核销/结算和会计收入，并分别定义订单生命周期、经营日、客房指标、OTA预付、佣金、支付对账与地区合规边界；不包含当前酒店事实、住客个人信息或任何PMS/财务外部写权限。';

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
  @pms_sem_unit_name,
  @pms_sem_source,
  'done',
  @pms_sem_description,
  JSON_ARRAY(
    '国内PMS',
    '经营日',
    '夜审',
    '订单状态',
    '房费',
    '应收',
    'OTA预付对账',
    '佣金对账',
    '支付对账',
    '指标口径',
    'structured_knowledge',
    'manual_review_only'
  ),
  0,
  'active',
  'reviewed_public_official_vendor_government_accounting_and_legal_sources',
  CONCAT(@pms_sem_reviewed_at, ' 00:00:00'),
  JSON_ARRAY(
    '预订、取消、No Show、入住、在住和退房是不同订单状态，退房不等于已收款或已平账。',
    '订单金额、夜审过房费、实收、应收、平台账单、核销结算和会计收入是不同财务事实。',
    '西软XMS版本化帮助显示夜审按当前营业日检查、过房费、更新数据并生成报表，具体时间和阻断项依赖配置。',
    '北京市重点住宿业统计将平均房价定义为客房收入/实际出租间夜，将出租率定义为实际出租间夜/可出租房间天数。',
    '北京市住宿业经营统计将客房、餐饮、商品和其他收入分列，并注明其营业收入为不含增值税口径。',
    'OTA预付/AR、佣金、支付/银行对账是三条独立链，匹配也不等于会计收入确认。',
    '财政部收入准则按履约义务和控制权转移确认收入，预收、代收和可退还款可能仍是负债。',
    '预订人、联系人、付款人与实际入住人必须分开；地区住宿登记时限不得跨地区外推。'
  ),
  JSON_ARRAY(
    '目标酒店使用的PMS厂商、产品、版本、模块和权限。',
    '当前营业日、夜审切日时间、夜审状态、阻断项和回补冲账状态。',
    '各来源原始订单状态到标准状态的实际映射。',
    '订单金额、房费、税费、服务费、押金、退款、应收、核销和会计收入的实际字段。',
    '维修房、停用房、免费房、自用房、钟点房和临时锁房如何进入指标分子分母。',
    '当前OTA账单、佣金规则、支付通道、结算周期和对账差异。',
    '目标地区现行住宿登记、涉外、未成年人和个人信息规则。',
    '当前酒店、目标营业日、订单、住客、房态、收入、收款、应收和结算事实。'
  ),
  @pms_sem_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units`
  WHERE `name` = @pms_sem_unit_name
    AND `source` = @pms_sem_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @pms_sem_description,
  `tags` = JSON_ARRAY(
    '国内PMS',
    '经营日',
    '夜审',
    '订单状态',
    '房费',
    '应收',
    'OTA预付对账',
    '佣金对账',
    '支付对账',
    '指标口径',
    'structured_knowledge',
    'manual_review_only'
  ),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'reviewed_public_official_vendor_government_accounting_and_legal_sources',
  `reviewed_at` = CONCAT(@pms_sem_reviewed_at, ' 00:00:00'),
  `known_knowns` = JSON_ARRAY(
    '预订、取消、No Show、入住、在住和退房是不同订单状态，退房不等于已收款或已平账。',
    '订单金额、夜审过房费、实收、应收、平台账单、核销结算和会计收入是不同财务事实。',
    '西软XMS版本化帮助显示夜审按当前营业日检查、过房费、更新数据并生成报表，具体时间和阻断项依赖配置。',
    '北京市重点住宿业统计将平均房价定义为客房收入/实际出租间夜，将出租率定义为实际出租间夜/可出租房间天数。',
    '北京市住宿业经营统计将客房、餐饮、商品和其他收入分列，并注明其营业收入为不含增值税口径。',
    'OTA预付/AR、佣金、支付/银行对账是三条独立链，匹配也不等于会计收入确认。',
    '财政部收入准则按履约义务和控制权转移确认收入，预收、代收和可退还款可能仍是负债。',
    '预订人、联系人、付款人与实际入住人必须分开；地区住宿登记时限不得跨地区外推。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '目标酒店使用的PMS厂商、产品、版本、模块和权限。',
    '当前营业日、夜审切日时间、夜审状态、阻断项和回补冲账状态。',
    '各来源原始订单状态到标准状态的实际映射。',
    '订单金额、房费、税费、服务费、押金、退款、应收、核销和会计收入的实际字段。',
    '维修房、停用房、免费房、自用房、钟点房和临时锁房如何进入指标分子分母。',
    '当前OTA账单、佣金规则、支付通道、结算周期和对账差异。',
    '目标地区现行住宿登记、涉外、未成年人和个人信息规则。',
    '当前酒店、目标营业日、订单、住客、房态、收入、收款、应收和结算事实。'
  ),
  `truth_profile_version` = @pms_sem_version,
  `updated_at` = NOW()
WHERE `name` = @pms_sem_unit_name
  AND `source` = @pms_sem_source;

SET @pms_sem_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @pms_sem_unit_name
    AND `source` = @pms_sem_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_domestic_pms_semantic_chunks`;
CREATE TEMPORARY TABLE `tmp_domestic_pms_semantic_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_domestic_pms_sem_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_public_official_vendor_government_accounting_and_legal_sources',
    'source_refs', JSON_ARRAY(
      'foxhis_xms_master_help',
      'foxhis_xms_night_audit_2025',
      'foxhis_xms_ota_prepaid_reconciliation_2023',
      'foxhis_xms_commission_reconciliation_2025',
      'foxhis_xms_payment_reconciliation_2025',
      'foxhis_xms_ar_and_reversal_help',
      'beijing_lodging_room_metrics_2024',
      'beijing_lodging_operating_statistics_2024',
      'mof_revenue_standard_2017',
      'national_hotel_security_rule',
      'shanghai_hotel_security_rule_2025',
      'ctha_shiji_digitalization_report_2026'
    ),
    'reviewed_at', @pms_sem_reviewed_at,
    'source_manifest', JSON_OBJECT(
      'foxhis_xms_master_help', JSON_OBJECT(
        'publisher', '杭州西软信息技术有限公司',
        'title', 'XMS在线帮助总目录',
        'url', 'https://faqonline.foxhis.com/docs/mindoc/mindoc-1bisq24ahrvgo',
        'source_status', 'official_vendor_current_help_index'
      ),
      'foxhis_xms_night_audit_2025', JSON_OBJECT(
        'publisher', '杭州西软信息技术有限公司',
        'title', '第三节 夜间稽核',
        'product_version', 'xms440+',
        'updated_at', '2025-01-10',
        'url', 'https://faqonline.foxhis.com/docs/mindoc/mindoc-1bjbjj9d3vdhc',
        'source_status', 'official_vendor_versioned_help'
      ),
      'foxhis_xms_ota_prepaid_reconciliation_2023', JSON_OBJECT(
        'publisher', '杭州西软信息技术有限公司',
        'title', 'OTA预付账务核对',
        'updated_at', '2023-08-18',
        'url', 'https://faqonline.foxhis.com/docs/mindoc/mindoc-1eief5gnlglrk',
        'source_status', 'official_vendor_versioned_help'
      ),
      'foxhis_xms_commission_reconciliation_2025', JSON_OBJECT(
        'publisher', '杭州西软信息技术有限公司',
        'title', '佣金核对及佣金规则配置',
        'product_version', 'xms440+',
        'updated_at', '2025-01-10',
        'url', 'https://faqonline.foxhis.com/docs/mindoc/mindoc-1g3uf30gogscr',
        'source_status', 'official_vendor_versioned_help'
      ),
      'foxhis_xms_payment_reconciliation_2025', JSON_OBJECT(
        'publisher', '杭州西软信息技术有限公司',
        'title', '支付对账',
        'updated_at', '2025-08-13',
        'url', 'https://faqonline.foxhis.com/docs/mindoc/mindoc-1gmqe3bd9qfl7',
        'source_status', 'official_vendor_versioned_help'
      ),
      'foxhis_xms_ar_and_reversal_help', JSON_OBJECT(
        'publisher', '杭州西软信息技术有限公司',
        'title', 'AR明细账与冲账帮助',
        'updated_at', '2025-09-09_or_versioned',
        'url', 'https://faqonline.foxhis.com/docs/mindoc/mindoc-1gosm19o79dgc',
        'source_status', 'official_vendor_versioned_help'
      ),
      'beijing_lodging_room_metrics_2024', JSON_OBJECT(
        'publisher', '北京市文化和旅游局',
        'title', '2024年重点住宿业平均房价和出租率',
        'published_at', '2025-01-23',
        'region', 'Beijing',
        'url', 'https://whlyj.beijing.gov.cn/zwgk/zxgs/tjxx/history/2024/zdzsyfj/202501/t20250123_4055611.html',
        'source_status', 'official_public_statistics_scope'
      ),
      'beijing_lodging_operating_statistics_2024', JSON_OBJECT(
        'publisher', '北京市文化和旅游局',
        'title', '2024年重点住宿业经营情况',
        'published_at', '2025-01-23',
        'region', 'Beijing',
        'url', 'https://whlyj.beijing.gov.cn/zwgk/zxgs/tjxx/history/2024/zdzsyjy/202501/t20250123_4055551.html',
        'source_status', 'official_public_statistics_scope'
      ),
      'mof_revenue_standard_2017', JSON_OBJECT(
        'publisher', '中华人民共和国财政部',
        'title', '企业会计准则第14号——收入',
        'document_no', '财会〔2017〕22号',
        'url', 'https://kjs.mof.gov.cn/zt/kjzzss/kuaijizhunzeshishi/201709/t20170907_2694006.htm',
        'source_status', 'official_accounting_standard'
      ),
      'national_hotel_security_rule', JSON_OBJECT(
        'publisher', '中华人民共和国司法部法规库',
        'title', '旅馆业治安管理办法',
        'region', 'China',
        'url', 'https://xzfg.moj.gov.cn/front/law/detail?LawID=178&Query=',
        'source_status', 'official_legal_rule'
      ),
      'shanghai_hotel_security_rule_2025', JSON_OBJECT(
        'publisher', '上海市人民政府',
        'title', '上海市旅馆业治安管理实施细则',
        'effective_at', '2025-04-28',
        'region', 'Shanghai',
        'url', 'https://www.shanghai.gov.cn/gwk/search/content/GKXX-20250429111830516--5518',
        'source_status', 'official_legal_regional_rule'
      ),
      'ctha_shiji_digitalization_report_2026', JSON_OBJECT(
        'publisher', '中国旅游饭店业协会与石基信息',
        'title', '2026中国酒店业数字化发展报告',
        'published_at', '2026-04-02',
        'sample', '577份有效样本及9位专家访谈',
        'url', 'https://www.ctha.com.cn/detail-79-111-4047.html',
        'source_status', 'industry_survey_direction'
      )
    ),
    'allowed_uses', JSON_ARRAY(
      'pms_semantic_mapping',
      'business_day_and_night_audit_explanation',
      'metric_denominator_validation',
      'reconciliation_state_design',
      'data_quality_review',
      'manual_evidence_checklist'
    ),
    'blocked_uses', JSON_ARRAY(
      'current_hotel_fact_without_verified_capture',
      'guest_pii_storage_in_knowledge',
      'automatic_pms_order_or_financial_write',
      'vendor_example_as_universal_rule',
      'regional_deadline_as_national_rule',
      'ota_fact_as_whole_hotel_fact'
    )
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_order_state_contract',
  JSON_OBJECT(
    'scope', 'pms_order_lifecycle_semantics',
    'evidence_level', 'official_vendor_help_normalized_methodology',
    'source_refs', JSON_ARRAY('foxhis_xms_master_help'),
    'canonical_states', JSON_ARRAY(
      JSON_OBJECT('state', 'reserved', 'meaning', '已建立预订，是否最终确认取决于来源合同'),
      JSON_OBJECT('state', 'confirmed', 'meaning', '平台或酒店已确认履约'),
      JSON_OBJECT('state', 'cancelled', 'meaning', '预订已取消'),
      JSON_OBJECT('state', 'no_show', 'meaning', '应到未到并按当前PMS流程处理'),
      JSON_OBJECT('state', 'checked_in', 'meaning', '已办理入住'),
      JSON_OBJECT('state', 'in_house', 'meaning', '当前在住'),
      JSON_OBJECT('state', 'checked_out', 'meaning', '已办理退房'),
      JSON_OBJECT('state', 'unmapped_source_status', 'meaning', '来源状态尚未完成可信映射')
    ),
    'independent_dimensions', JSON_ARRAY(
      'reservation_status',
      'stay_status',
      'room_status',
      'payment_status',
      'accounting_status',
      'reconciliation_status'
    ),
    'required_fields', JSON_ARRAY(
      'system_hotel_id',
      'source_system',
      'source_hotel_id',
      'source_order_key',
      'raw_status',
      'canonical_status',
      'status_occurred_at',
      'business_date',
      'captured_at',
      'mapping_version',
      'quality_status'
    ),
    'blocked_equivalences', JSON_ARRAY(
      'confirmed_equals_checked_in',
      'checked_out_equals_paid',
      'checked_out_equals_account_settled',
      'cancelled_equals_refunded',
      'unmapped_status_equals_default_status'
    )
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_financial_state_contract',
  JSON_OBJECT(
    'scope', 'pms_financial_semantics',
    'evidence_level', 'official_vendor_help_and_accounting_standard',
    'source_refs', JSON_ARRAY(
      'foxhis_xms_night_audit_2025',
      'foxhis_xms_ar_and_reversal_help',
      'mof_revenue_standard_2017'
    ),
    'semantic_keys', JSON_ARRAY(
      JSON_OBJECT('key', 'booking_order_amount', 'meaning', '预订或订单在当前来源展示的金额'),
      JSON_OBJECT('key', 'room_charge_posted_amount', 'meaning', 'PMS已入账或夜审过账的住宿房费'),
      JSON_OBJECT('key', 'payment_collected_amount', 'meaning', '已收取或支付通道确认的款项'),
      JSON_OBJECT('key', 'accounts_receivable_amount', 'meaning', '应收账发生额或余额'),
      JSON_OBJECT('key', 'external_statement_amount', 'meaning', 'OTA银行或支付机构账单金额'),
      JSON_OBJECT('key', 'settlement_writeoff_amount', 'meaning', '核对后已结算或核销的金额'),
      JSON_OBJECT('key', 'accounting_revenue_amount', 'meaning', '按适用会计政策确认的收入'),
      JSON_OBJECT('key', 'refund_amount', 'meaning', '已发生或确认的退款'),
      JSON_OBJECT('key', 'reversal_amount', 'meaning', '保留原交易后的冲账反向记录')
    ),
    'accounting_boundary', JSON_OBJECT(
      'recognition_trigger', 'performance_obligation_satisfied_and_customer_obtains_control',
      'cash_received_always_revenue', false,
      'refundable_or_third_party_collection_may_be_liability', true,
      'pms_room_charge_posting_always_bank_settlement', false
    ),
    'required_dimensions', JSON_ARRAY(
      'currency',
      'tax_basis',
      'gross_or_net',
      'business_date',
      'posting_date',
      'service_period',
      'source_system',
      'source_transaction_key',
      'quality_status'
    ),
    'blocked_aliases', JSON_ARRAY(
      'order_amount_as_revenue',
      'payment_as_accounting_revenue',
      'room_charge_as_bank_settlement',
      'accounts_receivable_as_cash',
      'writeoff_as_original_order_amount',
      'silent_overwrite_instead_of_reversal'
    )
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_business_day_night_audit_contract',
  JSON_OBJECT(
    'scope', 'pms_business_day_and_night_audit',
    'evidence_level', 'official_vendor_versioned_help',
    'source_refs', JSON_ARRAY('foxhis_xms_night_audit_2025'),
    'vendor', 'Foxhis',
    'product_version', 'xms440+',
    'source_updated_at', '2025-01-10',
    'verified_workflow', JSON_ARRAY(
      'aggregate_and_review_current_business_day',
      'check_blocking_items',
      'room_charge_pre_audit',
      'post_room_charge_for_in_house_rooms_after_review',
      'statistics_and_data_update',
      'generate_daily_revenue_room_charge_and_trial_balance_reports'
    ),
    'source_specific_blocker_example', '退房未平账列表应为零且红色项目可阻断当版流程',
    'required_fields', JSON_ARRAY(
      'calendar_date',
      'business_date',
      'captured_at',
      'night_audit_status',
      'night_audit_completed_at',
      'vendor',
      'product_version',
      'configuration_version',
      'quality_status'
    ),
    'unknown_until_tenant_verified', JSON_ARRAY(
      'night_audit_cutover_time',
      'automatic_posting_scope',
      'blocking_checks',
      'cross_midnight_attribution',
      'late_posting_and_backfill_policy',
      'reversal_policy'
    ),
    'blocked_defaults', JSON_ARRAY(
      'midnight_cutover',
      '02_00_cutover',
      'all_hotels_share_same_night_audit_checks',
      'calendar_date_equals_business_date'
    )
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_rooms_metrics_contract',
  JSON_OBJECT(
    'scope', 'whole_hotel_room_metric_methodology',
    'evidence_level', 'official_public_statistics_scope_plus_guarded_derivation',
    'source_refs', JSON_ARRAY('beijing_lodging_room_metrics_2024'),
    'official_beijing_definitions', JSON_ARRAY(
      JSON_OBJECT(
        'metric', 'average_room_price',
        'formula', 'room_revenue / actual_rented_room_nights',
        'report_scope', 'Beijing_key_lodging_entities_2024'
      ),
      JSON_OBJECT(
        'metric', 'average_occupancy_rate',
        'formula', 'actual_rented_room_nights / available_room_days * 100',
        'report_scope', 'Beijing_key_lodging_entities_2024'
      )
    ),
    'suxios_guarded_metrics', JSON_ARRAY(
      JSON_OBJECT('metric', 'adr', 'formula', 'aligned_room_revenue / actual_rented_room_nights'),
      JSON_OBJECT('metric', 'occ', 'formula', 'actual_rented_room_nights / available_room_nights * 100'),
      JSON_OBJECT('metric', 'revpar', 'formula', 'aligned_room_revenue / available_room_nights')
    ),
    'required_alignment', JSON_ARRAY(
      'same_system_hotel_id',
      'same_business_date_or_period',
      'same_accommodation_scope',
      'same_currency',
      'same_tax_and_service_charge_basis',
      'same_room_inventory_policy',
      'night_audit_or_finality_status_known'
    ),
    'required_policy_fields', JSON_ARRAY(
      'out_of_order_room_policy',
      'out_of_service_room_policy',
      'complimentary_room_policy',
      'house_use_room_policy',
      'hourly_room_policy',
      'cancelled_order_policy',
      'no_show_policy'
    ),
    'missing_denominator_behavior', 'null_with_data_gap',
    'ota_substitution_allowed', false
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_revenue_scope_contract',
  JSON_OBJECT(
    'scope', 'whole_hotel_revenue_scope_methodology',
    'evidence_level', 'official_public_statistics_scope_and_accounting_standard',
    'source_refs', JSON_ARRAY(
      'beijing_lodging_operating_statistics_2024',
      'mof_revenue_standard_2017'
    ),
    'separate_revenue_categories', JSON_ARRAY(
      'room_revenue',
      'food_and_beverage_revenue',
      'goods_revenue',
      'other_revenue'
    ),
    'beijing_report_tax_basis', 'excluding_value_added_tax',
    'required_revenue_dimensions', JSON_ARRAY(
      'hotel_scope',
      'service_category',
      'business_date_or_period',
      'currency',
      'tax_basis',
      'gross_or_net',
      'recognition_basis',
      'source_system',
      'finality_status'
    ),
    'blocked_expansions', JSON_ARRAY(
      'room_pms_amount_as_total_hotel_revenue',
      'ota_channel_amount_as_total_hotel_revenue',
      'payment_collection_as_accounting_revenue',
      'gross_amount_as_net_revenue',
      'tax_inclusive_amount_as_tax_exclusive_revenue'
    )
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_ota_prepaid_reconciliation_contract',
  JSON_OBJECT(
    'scope', 'pms_ota_prepaid_ar_reconciliation',
    'evidence_level', 'official_vendor_versioned_help',
    'source_refs', JSON_ARRAY('foxhis_xms_ota_prepaid_reconciliation_2023'),
    'vendor', 'Foxhis',
    'source_updated_at', '2023-08-18',
    'workflow', JSON_ARRAY(
      'import_external_ota_prepaid_statement',
      'compare_with_pms_accounts_receivable',
      'review_order_and_amount_difference',
      'writeoff_only_after_match_and_authorized_review'
    ),
    'canonical_states', JSON_ARRAY(
      'external_only',
      'pms_only',
      'amount_mismatch',
      'matched_unwritten',
      'partial_writeoff',
      'written_off',
      'manual_reviewed'
    ),
    'vendor_example_statement_cycle', 'every_7_days_in_described_scenario',
    'universal_statement_cycle', 'unknown',
    'writeoff_equals_payment_received', false,
    'writeoff_equals_accounting_revenue', false,
    'required_fields', JSON_ARRAY(
      'system_hotel_id',
      'business_date_or_service_period',
      'source_order_key',
      'external_statement_key',
      'pms_ar_key',
      'external_amount',
      'pms_ar_amount',
      'difference_amount',
      'reconciliation_state',
      'reviewed_by',
      'reviewed_at',
      'source_version'
    )
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_commission_reconciliation_contract',
  JSON_OBJECT(
    'scope', 'pms_ota_commission_reconciliation',
    'evidence_level', 'official_vendor_versioned_help',
    'source_refs', JSON_ARRAY('foxhis_xms_commission_reconciliation_2025'),
    'vendor', 'Foxhis',
    'product_version', 'xms440+',
    'source_updated_at', '2025-01-10',
    'required_alignment', JSON_ARRAY(
      'channel_order',
      'eligible_room_amount',
      'commission_rule',
      'commission_basis',
      'tax_basis',
      'cancellation_or_refund_state',
      'service_period'
    ),
    'supported_rule_shapes_may_include', JSON_ARRAY(
      'percentage',
      'fixed_amount',
      'base_price',
      'net_room_price'
    ),
    'canonical_states', JSON_ARRAY(
      'matched',
      'amount_mismatch',
      'external_only',
      'pms_only',
      'manual_review_required',
      'manual_reviewed'
    ),
    'ui_color_is_semantic_state', false,
    'simple_order_amount_times_rate_is_universal', false,
    'current_hotel_commission_rule', 'unknown'
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_payment_reconciliation_contract',
  JSON_OBJECT(
    'scope', 'pms_payment_and_bank_reconciliation',
    'evidence_level', 'official_vendor_versioned_help',
    'source_refs', JSON_ARRAY('foxhis_xms_payment_reconciliation_2025'),
    'vendor', 'Foxhis',
    'source_updated_at', '2025-08-13',
    'vendor_feature_cycle', 'T_plus_2_automatic_payment_flow_reconciliation',
    'universal_settlement_cycle', 'unknown',
    'canonical_states', JSON_ARRAY(
      'matched',
      'pms_only',
      'external_only',
      'amount_mismatch',
      'date_mismatch',
      'history_incomplete',
      'manual_review_required',
      'manual_reviewed'
    ),
    'audit_actions_may_include', JSON_ARRAY(
      'manual_review',
      'approve_review',
      'revoke_review',
      'import',
      'void',
      'date_correction'
    ),
    'required_fields', JSON_ARRAY(
      'pms_payment_flow_key',
      'external_payment_flow_key',
      'payment_channel',
      'amount',
      'currency',
      'payment_date',
      'settlement_date',
      'reconciliation_state',
      'review_audit_trail',
      'source_version'
    ),
    'match_equals_commission_reconciled', false,
    'match_equals_accounting_revenue', false
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_reversal_audit_contract',
  JSON_OBJECT(
    'scope', 'pms_financial_reversal_audit',
    'evidence_level', 'official_vendor_versioned_help',
    'source_refs', JSON_ARRAY('foxhis_xms_ar_and_reversal_help'),
    'principle', 'reverse_incorrect_charge_then_repost_correct_charge_while_preserving_original',
    'required_links', JSON_ARRAY(
      'original_transaction_key',
      'reversal_transaction_key',
      'reposted_transaction_key'
    ),
    'required_audit_fields', JSON_ARRAY(
      'reason',
      'operator',
      'operated_at',
      'business_date',
      'source_system',
      'source_version',
      'save_readback_status'
    ),
    'silent_overwrite_allowed', false,
    'partial_writeoff_transfer_split_may_exist', true,
    'current_hotel_transaction', 'unknown'
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'guest_identity_registration_contract',
  JSON_OBJECT(
    'scope', 'hotel_guest_identity_and_regional_registration_boundary',
    'evidence_level', 'official_legal_national_and_regional_rules',
    'source_refs', JSON_ARRAY(
      'national_hotel_security_rule',
      'shanghai_hotel_security_rule_2025'
    ),
    'separate_roles', JSON_ARRAY(
      'booker',
      'contact_person',
      'payer',
      'actual_staying_guest'
    ),
    'national_principle', 'verify_and_truthfully_register_actual_staying_guest_identity',
    'foreign_guest_reporting', 'apply_current_applicable_rule',
    'shanghai_local_rule', JSON_OBJECT(
      'effective_at', '2025-04-28',
      'region', 'Shanghai',
      'verified_upload_window', 'within_2_hours_under_current_local_rule'
    ),
    'regional_rule_may_be_generalized_nationally', false,
    'knowledge_store_may_contain_guest_pii', false,
    'blocked_data', JSON_ARRAY(
      'guest_name',
      'identity_document_number',
      'phone_number',
      'payment_account',
      'raw_order_detail'
    ),
    'current_target_region', 'unknown'
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_standardization_direction_contract',
  JSON_OBJECT(
    'scope', 'industry_standardization_direction',
    'evidence_level', 'industry_survey_direction_not_operational_fact',
    'source_refs', JSON_ARRAY('ctha_shiji_digitalization_report_2026'),
    'published_at', '2026-04-02',
    'sample', JSON_OBJECT(
      'valid_responses', 577,
      'expert_interviews', 9
    ),
    'reported_industry_gaps', JSON_ARRAY(
      'data_accuracy',
      'inconsistent_definitions',
      'insufficient_data_reuse'
    ),
    'recommended_layers', JSON_ARRAY(
      'code_standardization',
      'process_standardization',
      'metric_definition_standardization'
    ),
    'suxios_use', 'prioritize_source_codes_state_mappings_business_process_and_metric_contracts',
    'not_evidence_of', JSON_ARRAY(
      'current_hotel_data_quality',
      'vendor_market_share',
      'metric_formula',
      'causal_revenue_lift'
    )
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'pms_known_unknowns',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'explicit_unknowns_after_public_source_review',
    'source_refs', JSON_ARRAY(
      'foxhis_xms_master_help',
      'beijing_lodging_room_metrics_2024',
      'mof_revenue_standard_2017',
      'national_hotel_security_rule',
      'shanghai_hotel_security_rule_2025'
    ),
    'unknowns', JSON_ARRAY(
      'target_pms_vendor_product_version_modules_permissions',
      'current_business_date_night_audit_cutover_status_and_blockers',
      'source_order_status_mapping',
      'actual_financial_field_mapping_and_tax_basis',
      'room_inventory_denominator_policy',
      'current_ota_commission_payment_contract_and_differences',
      'current_applicable_regional_registration_rules',
      'current_hotel_and_target_business_date_facts'
    ),
    'missing_value_policy', 'preserve_unknown_null_partial_or_blocked',
    'forbidden_fallbacks', JSON_ARRAY(
      'zero',
      'vendor_example',
      'old_version',
      'default_cycle',
      'government_aggregate',
      'ota_channel_fact'
    ),
    'next_evidence_required', JSON_ARRAY(
      'authorized_current_tenant_help_or_configuration',
      'same_hotel_same_business_date_source_capture',
      'field_mapping_and_save_readback',
      'current_contract_or_statement',
      'applicable_regional_rule'
    )
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_domestic_pms_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @pms_sem_unit_id,
  'landing_status',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'local_materialization_status',
    'source_refs', JSON_ARRAY(
      'domestic_pms_semantic_contract_document',
      'domestic_pms_semantic_contract_migration',
      'domestic_pms_semantic_contract_test'
    ),
    'document_path', 'docs/domestic_pms_business_day_order_reconciliation_semantic_contract_knowledge.md',
    'migration_path', 'database/migrations/20260730_y_write_domestic_pms_semantic_contract.sql',
    'test_path', 'tests/DomesticPmsSemanticContractKnowledgeTest.php',
    'knowledge_source', @pms_sem_source,
    'contains_current_hotel_fact', false,
    'contains_guest_pii', false,
    'external_write_executed', false,
    'automatic_pms_write_authorized', false,
    'truth_profile_version', @pms_sem_version
  ),
  0,
  NOW()
WHERE @pms_sem_unit_id IS NOT NULL;

UPDATE `tmp_domestic_pms_semantic_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.module_id', 'domestic_pms_semantic_contract',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'finance', 'front_office'),
  '$.scenes', JSON_ARRAY(
    'morning_meeting',
    'night_audit_review',
    'data_quality_review',
    'ota_reconciliation',
    'finance_reconciliation',
    'weekly_review'
  ),
  '$.platforms', JSON_ARRAY('pms', 'ota', 'payment', 'finance'),
  '$.seed_owner', @pms_sem_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `unit`.`name`, ':', `seed`.`type`),
  '$.seed_version', @pms_sem_version,
  '$.lifecycle_status', 'active'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_domestic_pms_semantic_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
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
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  `seed`.`unit_id`,
  `seed`.`type`,
  `seed`.`content`,
  `seed`.`created_by`,
  `seed`.`created_at`
FROM `tmp_domestic_pms_semantic_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
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

DROP TEMPORARY TABLE `tmp_domestic_pms_semantic_chunks`;

SET @pms_sem_staff_content := CONCAT(
  '# 国内PMS经营日、订单状态与对账官方语义合同', '\n\n',
  '## 核心边界', '\n',
  '预订订单金额、夜审过房费、实收、应收、平台账单、核销结算和会计收入是不同事实，任何一个都不能在缺少来源映射时替代另一个。', '\n\n',
  '## 订单与营业日', '\n',
  '预订、确认、取消、No Show、入住、在住和退房分别保存；退房不等于已收款或已平账。自然日、PMS营业日、采集时间和夜审完成时间必须分开。', '\n\n',
  '## 指标', '\n',
  'ADR、OCC和RevPAR必须使用同酒店、同营业日、同住宿范围、同税费和同库存政策的分子分母。缺少可出租房晚时返回不可计算，不用总房量或OTA库存补值。', '\n\n',
  '## 三条对账链', '\n',
  'OTA预付/AR、佣金、支付/银行对账分别保存匹配、金额不符、PMS单边、外部单边、部分核销、已核销和人工复核状态。七日账单和T+2只属于已核验的厂商版本示例。', '\n\n',
  '## 已知的未知', '\n',
  '目标PMS版本与配置、当前营业日与夜审、状态映射、财务字段、库存分母、合同周期、地区规则和当前酒店事实仍未知，保持unknown/null/partial/blocked。', '\n\n',
  '## 保护边界', '\n',
  '本知识不保存住客个人信息，不把OTA事实扩大成全酒店事实，不授权订单、房态、账务、支付、核销、夜审或住宿登记外部写入。'
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
  @pms_sem_unit_name,
  @pms_sem_staff_content,
  '国内PMS,经营日,营业日,夜审,订单状态,房费,实收,应收,核销,会计收入,OTA预付,佣金对账,支付对账,OCC,ADR,RevPAR',
  JSON_ARRAY(
    '国内PMS',
    '经营日',
    '订单状态',
    '财务语义',
    '对账',
    '指标口径',
    'manual_review_only'
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
    AND `title` = @pms_sem_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = 7,
  `content` = @pms_sem_staff_content,
  `keywords` = '国内PMS,经营日,营业日,夜审,订单状态,房费,实收,应收,核销,会计收入,OTA预付,佣金对账,支付对账,OCC,ADR,RevPAR',
  `tags` = JSON_ARRAY(
    '国内PMS',
    '经营日',
    '订单状态',
    '财务语义',
    '对账',
    '指标口径',
    'manual_review_only'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @pms_sem_unit_name;
