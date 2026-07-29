-- Refresh the hotel revenue success-practices knowledge with 2025-2026
-- evidence. Historical rows are retained for audit but removed from active
-- retrieval. The already-applied 20260730 seed migration remains immutable.
--
-- Safe rerun contract:
-- - preserve operator-authored chunks and every historical seed row;
-- - mark only the prior owned source set stale;
-- - update only the exact current owner + key + version rows;
-- - never execute OTA/PMS writes or promote external numbers to hotel facts.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @recent_version := '2026-07-30.2';
SET @recent_reviewed_at := '2026-07-30';
SET @recent_seed_owner := 'suxios.hotel_revenue_success_practices_recent_sources';
SET @prior_seed_owner := 'suxios.hotel_revenue_success_practices_extension';
SET @recent_unit_name := '酒店收益成功实践延伸知识';
SET @recent_source := 'revenue_operations_decision_support';
SET @recent_description := '以2025—2026年一手行业、监管、聚合数据和受限供应商案例刷新酒店收益实践：补强OTA与PMS对账、跨系统标准化、异常到行动、渠道总价值、集团建议与单店自主权。旧案例保留审计但退出活跃检索；外部数字仍只作显式case_reference。';

SET @recent_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @recent_unit_name
    AND `source` = @recent_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

-- Retain the old source set for audit while ensuring it can no longer be
-- returned by RevenueOperationsKnowledgeService.
UPDATE `knowledge_chunks` AS `historical`
SET `historical`.`content` = JSON_SET(
  CASE
    WHEN JSON_VALID(`historical`.`content`) = 1 THEN `historical`.`content`
    ELSE JSON_OBJECT()
  END,
  '$.lifecycle_status', 'stale',
  '$.evidence_state', 'historical_superseded',
  '$.superseded_at', CONCAT(@recent_reviewed_at, ' 00:00:00'),
  '$.superseded_by_seed_owner', @recent_seed_owner,
  '$.superseded_by_version', @recent_version,
  '$.superseded_reason', '2021—2024 and undated source set replaced by reviewed 2025—2026 evidence',
  '$.replacement_case_keys', JSON_ARRAY(
    'shiji_shenzhen_mgm_ota_reconciliation_2025',
    'shiji_poly_business_finance_data_2026',
    'tripcom_resorts_world_genting_api_2025',
    'meituan_hms_current_capability_2025',
    'siteminder_booking_trends_2025',
    'cloudbeds_independent_hotels_2026',
    'china_hotel_hci_2025_12',
    'duetto_jannah_2025',
    'mews_terrace_bay_2025'
  )
)
WHERE `historical`.`unit_id` = @recent_unit_id
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE
      WHEN JSON_VALID(`historical`.`content`) = 1 THEN `historical`.`content`
      ELSE JSON_OBJECT()
    END,
    '$.seed_owner'
  )) = @prior_seed_owner;

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @recent_description,
  `tags` = JSON_ARRAY(
    '收益管理',
    'OTA对账',
    'PMS',
    '业财融合',
    '预订曲线',
    '渠道价值',
    '异常预警',
    '定价自主权',
    '2025',
    '2026',
    'structured_knowledge',
    'manual_review_only'
  ),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = '2025_2026_source_refresh_with_historical_evidence_quarantined',
  `reviewed_at` = CONCAT(@recent_reviewed_at, ' 00:00:00'),
  `known_knowns` = JSON_ARRAY(
    '2025年深圳美高梅试运营携程、美团等OTA与银行自动对账，并把PMS、POS、成本采购和OA数据接入BI审计看板。',
    '跨系统经营分析必须先统一门店、房型、产品、科目、订单状态和经营日规则，再验证采集完整性与准确性。',
    '行业聚合数据可用于发现预订窗口、取消、渠道价值和入住时长的观察方向，但不能作为当前门店阈值。',
    '集团或AI建议不能替代单店判断；系统、数据标准、人员训练、权限和回读必须共同闭环。',
    '独家换流量、全网最低价和未授权平台自动降价不得作为宿析推荐的成功经验。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '当前目标酒店携程、美团与PMS订单、金额、结算、入住和取消是否已按同一经营日完成对账。',
    '美团酒店管理系统产品页所述能力在当前门店的真实字段覆盖、同步准确率、异常率和经营增量。',
    'Trip.com与云顶世界API直连对入住、核销、净收入和利润的实际影响。',
    '供应商客户案例的完整成本、匹配对照、独立审计和可归因增量。',
    '当前酒店是否拥有任何OTA或PMS价格、库存与产品写入权限；验证前仍视为只读建议。'
  ),
  `truth_profile_version` = @recent_version,
  `updated_at` = NOW()
WHERE `unit_id` = @recent_unit_id;

DROP TEMPORARY TABLE IF EXISTS `tmp_recent_success_practice_chunks`;
CREATE TEMPORARY TABLE `tmp_recent_success_practice_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_recent_success_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_2025_2026_source_manifest',
    'source_refs', JSON_ARRAY(
      'china_hotel_digital_transformation_2026',
      'samr_ctrip_antitrust_2026',
      'china_hotel_hci_2025_12',
      'siteminder_booking_trends_2025',
      'cloudbeds_independent_hotels_2026',
      'tripcom_resorts_world_genting_api_2025',
      'meituan_hms_current_capability_2025',
      'ideas_stayntouch_lrv_2026',
      'duetto_jannah_2025',
      'mews_terrace_bay_2025'
    ),
    'reviewed_at', @recent_reviewed_at,
    'freshness_window', JSON_OBJECT(
      'primary_from', '2025-01-01',
      'primary_to', @recent_reviewed_at,
      'historical_sources', 'retained_for_audit_but_excluded_from_active_retrieval'
    ),
    'source_manifest', JSON_OBJECT(
      'china_hotel_digital_transformation_2026', JSON_OBJECT(
        'publisher', '中国旅游饭店业协会、石基信息',
        'published_at', '2026-03-31',
        'url', 'https://pdf.dfcfw.com/pdf/H3_AP202603311820910990_1.pdf?1774972249000.pdf=',
        'kind', 'association_vendor_joint_report_with_577_valid_questionnaires_and_named_hotel_practices',
        'transfer_limit', 'named practices and interviews are not independent outcome audits'
      ),
      'samr_ctrip_antitrust_2026', JSON_OBJECT(
        'publisher', '国家市场监督管理总局',
        'published_at', '2026-07-25',
        'url', 'https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html',
        'kind', 'official_regulatory_enforcement_notice',
        'transfer_limit', 'used only for platform-pricing autonomy and competition boundary'
      ),
      'china_hotel_hci_2025_12', JSON_OBJECT(
        'publisher', '中国饭店协会',
        'published_at', '2026-03-16',
        'url', 'https://www.chinahotel.org.cn/articles/17644',
        'kind', 'industry_index_with_platform_data_and_approximately_110_hotel_sample',
        'transfer_limit', 'industry movement is not a target-hotel threshold or investment basis'
      ),
      'siteminder_booking_trends_2025', JSON_OBJECT(
        'publisher', 'SiteMinder',
        'data_year', '2025',
        'url', 'https://www.siteminder.com/hotel-booking-trends/',
        'kind', 'vendor_aggregate_of_more_than_135_million_reservations_across_20_markets',
        'transfer_limit', 'international aggregates do not prove current China hotel performance'
      ),
      'cloudbeds_independent_hotels_2026', JSON_OBJECT(
        'publisher', 'Cloudbeds',
        'report_year', '2026',
        'url', 'https://www.cloudbeds.com/hospitality-industry-report/',
        'kind', 'vendor_aggregate_of_90_million_bookings_in_180_countries',
        'transfer_limit', 'global independent-hotel aggregates require target-hotel calibration'
      ),
      'tripcom_resorts_world_genting_api_2025', JSON_OBJECT(
        'publisher', 'Trip.com Group',
        'published_at', '2025-07-09',
        'url', 'https://www.trip.com/newsroom/resorts-world-genting-and-trip-com-group-strengthen-partnership-with-dual-memoranda-of-understanding-to-elevate-malaysias-inbound-tourism/',
        'kind', 'platform_reported_direct_api_integration',
        'transfer_limit', 'integration is current capability evidence without hotel outcome measures'
      ),
      'meituan_hms_current_capability_2025', JSON_OBJECT(
        'publisher', '美团酒店管理系统',
        'accessed_at', @recent_reviewed_at,
        'url', 'https://hms.meituan.com/',
        'kind', 'official_product_capability_page',
        'transfer_limit', 'vendor adoption and capability claims are not verified hotel outcomes'
      ),
      'ideas_stayntouch_lrv_2026', JSON_OBJECT(
        'publisher', 'IDeaS',
        'published_at', '2026-05-05',
        'url', 'https://ideas.com/news/ideas-expands-integration-with-stayntouch-pms-lrv/',
        'kind', 'vendor_product_method_announcement',
        'transfer_limit', 'method evidence has no independent property outcome in this source'
      ),
      'duetto_jannah_2025', JSON_OBJECT(
        'publisher', 'Duetto',
        'published_at', '2025-01-27',
        'url', 'https://www.duettocloud.com/en-us/success-stories/22-revpar-increase-for-uae-based-jannah-hotels-resorts-portfolio?hs_amp=true',
        'kind', 'vendor_published_customer_case',
        'transfer_limit', 'no independent audit matched control or complete cost disclosure'
      ),
      'mews_terrace_bay_2025', JSON_OBJECT(
        'publisher', 'Mews',
        'published_at', '2025-12-02',
        'url', 'https://www.mews.com/en/blog/hotel-revenue-optimization',
        'kind', 'vendor_published_customer_case',
        'transfer_limit', 'no independent audit complete baseline or isolated causality'
      )
    ),
    'rules', JSON_ARRAY(
      'regulatory and association evidence outranks current product pages and vendor cases',
      'capability evidence outcome evidence and aggregate benchmarks remain separate',
      'all external numbers require an exact case_key and never become current-hotel facts',
      'no knowledge chunk executes OTA PMS inventory pricing advertising or settlement writes'
    )
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'booking_curve_forecast_learning',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_method_with_2025_aggregate_context',
    'source_refs', JSON_ARRAY(
      'siteminder_booking_trends_2025',
      'cloudbeds_independent_hotels_2026',
      'china_hotel_digital_transformation_2026'
    ),
    'summary', '预订窗口与取消窗口分开建模，并按同门店、同入住日或同类需求日、同提前天数、同事实范围比较OTB与Pickup。',
    'required_inputs', JSON_ARRAY(
      'hotel_id',
      'stay_date',
      'snapshot_date',
      'days_before_arrival',
      'otb_room_nights',
      'otb_room_revenue',
      'gross_new_bookings',
      'cancellations',
      'net_pickup',
      'remaining_sellable_rooms',
      'room_type',
      'channel',
      'source_method',
      'quality_status'
    ),
    'rules', JSON_ARRAY(
      '全球聚合的32.15天或40天只能提示观察窗口，不能作为当前酒店定价阈值。',
      '取消累计缺失时净Pickup与毛新增保持分离，不用0或猜测补齐。',
      '入住日结束后用实际入住间夜和实际房费收入回测预测误差。',
      '样本不可比或不足时输出experimental_rule，不输出确定提价或降价。'
    ),
    'allowed_outputs', JSON_ARRAY(
      'maintain',
      'request_missing_data',
      'inspect_room_type_or_channel_mix',
      'small_manual_experiment',
      'adjust_manual_review_timing'
    )
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'constrained_inventory_value',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_revenue_method_with_current_vendor_method_reference',
    'source_refs', JSON_ARRAY(
      'cloudbeds_independent_hotels_2026',
      'ideas_stayntouch_lrv_2026'
    ),
    'summary', '稀缺库存判断比较整笔订单净价值与可能挤出的后续净贡献，不只看首晚价格、入住率或订单数。',
    'required_inputs', JSON_ARRAY(
      'stay_dates',
      'room_type',
      'remaining_inventory_by_date',
      'length_of_stay',
      'net_room_revenue',
      'verified_ancillary_revenue',
      'commission',
      'variable_cost',
      'cancellation_probability',
      'no_show_probability',
      'comparable_pickup_curve',
      'execution_permission'
    ),
    'rules', JSON_ARRAY(
      '7—13晚订单增长的外部聚合趋势不能替代目标酒店逐日库存和需求曲线。',
      '附加收入未核验不得计入订单净价值。',
      '没有逐日房量、取消未到、可比预测或执行权限时状态为blocked。',
      '最短入住、到店限制和最后一间房价值仅生成待人工复核建议。'
    )
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'total_revenue_experience_product',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_method_with_2025_2026_industry_and_case_context',
    'source_refs', JSON_ARRAY(
      'china_hotel_hci_2025_12',
      'siteminder_booking_trends_2025',
      'tripcom_resorts_world_genting_api_2025',
      'mews_terrace_bay_2025'
    ),
    'summary', '渠道和体验产品必须联合观察房费、已核验附加收入、佣金、退款、获客成本、直接成本、人工产能与净贡献。',
    'required_inputs', JSON_ARRAY(
      'product_or_package_id',
      'hotel_id',
      'stay_date',
      'channel',
      'room_revenue',
      'verified_ancillary_revenue',
      'commission',
      'refunds',
      'acquisition_cost',
      'direct_product_cost',
      'incremental_labor_cost',
      'capacity',
      'comparison_baseline'
    ),
    'rules', JSON_ARRAY(
      'OTA负责渠道漏斗，PMS负责入住与房费，POS商城或核验台账负责附加收入与成本，三者不能互相代替。',
      '行业入住增长或官网订单价值更高都不能单独证明利润改善。',
      '没有直接成本和可比基线时只能描述收入，不得声称利润或单一因果改善。',
      '产品权益直连必须保存产品标识、使用日期、库存、核销、退款与回读状态。'
    )
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'ota_pms_reconciliation_contract',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'named_2025_china_hotel_pilot_plus_reviewed_truth_contract',
    'source_refs', JSON_ARRAY('china_hotel_digital_transformation_2026'),
    'summary', 'OTA成交进入收益分析前先完成订单、金额、佣金、补贴、退款、结算与PMS入住事实对账。',
    'required_inputs', JSON_ARRAY(
      'platform',
      'hotel_id',
      'business_date',
      'order_id',
      'room_type_mapping',
      'gross_amount',
      'commission',
      'subsidy',
      'refund',
      'settlement_amount',
      'pms_readback_status',
      'reconciliation_status',
      'exception_reason'
    ),
    'rules', JSON_ARRAY(
      '未对账的平台成交额不得写成PMS已入住收入。',
      '订单缺失、金额差异、日期错位、房型未映射和结算未回读都进入异常清单。',
      '缺失或失败返回partial或blocked，不用0、旧记录或跨平台数据兜底。',
      '自动对账只证明流程状态，经营增量仍需单独验证。'
    ),
    'allowed_outputs', JSON_ARRAY(
      'reconciled',
      'partial',
      'blocked',
      'exception_list',
      'manual_review_required'
    )
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'data_standardization_exception_action',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'named_china_hotel_practice_and_reviewed_operating_contract',
    'source_refs', JSON_ARRAY('china_hotel_digital_transformation_2026'),
    'summary', '先统一跨系统编码与业务规则，再采集、汇总、交叉分析，并把差异转成异常清单、责任人和复核动作。',
    'standardize_before_join', JSON_ARRAY(
      'hotel',
      'room_type',
      'product_or_package',
      'revenue_account',
      'promotion_or_discount',
      'order_status',
      'business_date',
      'employee_or_operator'
    ),
    'exception_outputs', JSON_ARRAY(
      'missing_source_data',
      'unreconciled_order_or_amount',
      'abnormal_discount_or_waiver',
      'inventory_or_entitlement_mapping_error',
      'channel_net_revenue_gap',
      'manual_price_product_or_service_review'
    ),
    'rules', JSON_ARRAY(
      '源值、映射值和映射状态同时保存，不在汇总层静默修正。',
      '看板必须可追溯到来源记录并生成异常或行动，不以展示指标数量衡量成功。',
      '每条行动包含事实依据、差距、目标、保护线、停止条件、回滚和责任人。',
      '数据采集不完整或不准确时不得生成全酒店确定结论。'
    )
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'human_hotel_autonomy_guardrail',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'association_named_practice_plus_official_regulatory_boundary',
    'source_refs', JSON_ARRAY(
      'china_hotel_digital_transformation_2026',
      'samr_ctrip_antitrust_2026'
    ),
    'summary', '集团或AI提供分析参考，不替代单店判断；平台不得成为宿析默认的独家、全网最低价或无授权自动降价执行者。',
    'required_context', JSON_ARRAY(
      'target_hotel',
      'stay_date',
      'current_inventory',
      'order_mix',
      'local_demand_event',
      'data_quality',
      'human_authorizer',
      'execution_permission',
      'source_readback'
    ),
    'rules', JSON_ARRAY(
      '统一数据底座支持集团分析，但保留单店灵活性和最终判断。',
      '系统上线必须配套统一数据语言、培训与机制调整。',
      '不得推荐以流量交换跨平台独家或把全网最低价作为默认目标。',
      '没有明确授权、保护线、停止条件和回读，不允许平台或算法直接降价。',
      '宿析只生成可解释、可回滚、待人工确认的建议。'
    ),
    'execution_log_fields', JSON_ARRAY(
      'platform',
      'original_value',
      'recommended_value',
      'authorized_by',
      'executed_at',
      'readback_result',
      'rollback_result'
    )
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'external_case_transfer_policy',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_evidence_transfer_policy',
    'source_refs', JSON_ARRAY(
      'china_hotel_digital_transformation_2026',
      'samr_ctrip_antitrust_2026',
      'china_hotel_hci_2025_12',
      'siteminder_booking_trends_2025',
      'cloudbeds_independent_hotels_2026',
      'tripcom_resorts_world_genting_api_2025',
      'meituan_hms_current_capability_2025',
      'duetto_jannah_2025',
      'mews_terrace_bay_2025'
    ),
    'evidence_order', JSON_ARRAY(
      'official_regulatory_or_association_evidence',
      'aggregate_dataset_with_sample_boundary',
      'current_platform_capability',
      'vendor_customer_case',
      'historical_case'
    ),
    'rules', JSON_ARRAY(
      '当前能力、聚合基准、客户结果和因果证明分别标注，不能互换。',
      '案例与基准数字默认排除，只在完全匹配活跃case_key时返回。',
      '每个外部数字展示来源年份、证据类型、样本边界和不可迁移条件。',
      '旧case_key退出活跃检索，保留数据库审计记录。',
      '没有目标酒店匹配字段、日期、来源质量和回读，不生成落店结论。'
    ),
    'rejected_shortcuts', JSON_ARRAY(
      'product_capability_equals_hotel_success',
      'aggregate_average_equals_target_hotel_threshold',
      'vendor_before_after_equals_single_causality',
      'ota_fact_equals_whole_hotel_fact',
      'missing_outcome_equals_zero_outcome'
    )
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'shiji_shenzhen_mgm_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'shiji_shenzhen_mgm_ota_reconciliation_2025',
    'requires_explicit_case_key', true,
    'evidence_level', 'association_vendor_joint_report_named_hotel_pilot',
    'source_refs', JSON_ARRAY('china_hotel_digital_transformation_2026'),
    'published_at', '2026-03-31',
    'facts', JSON_ARRAY(
      '2025年深圳美高梅试运营OTA与银行自动对账，对接携程、美团、飞猪及境外OTA直连渠道。',
      'PMS、POS、成本采购和OA数据被接入BI财务审计看板，用于多维交叉分析和异常识别。',
      '报告要求先统一编码体系和业务规则，再保障采集完整性与准确性。'
    ),
    'transferable_method', JSON_ARRAY(
      'reconcile_before_analysis',
      'standardize_before_join',
      'convert_cross_system_differences_to_exception_actions'
    ),
    'unknowns', JSON_ARRAY(
      '人工差错率下降的具体数值',
      '项目成本和回收期',
      '匹配对照与独立审计',
      '2026年集团全面落地的完成结果'
    ),
    'transfer_limit', 'pilot and interview evidence does not prove groupwide completion or a universal financial return'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'shiji_poly_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'shiji_poly_business_finance_data_2026',
    'requires_explicit_case_key', true,
    'evidence_level', 'association_vendor_joint_report_named_group_practice',
    'source_refs', JSON_ARRAY('china_hotel_digital_transformation_2026'),
    'published_at', '2026-03-31',
    'facts', JSON_ARRAY(
      '保利商旅上线系统后让财务数据具备业务属性，并建设统一数据语言。',
      '系统建设与培训、机制调整同步推进。',
      '集团收益管理提供预测、价格和渠道结构参考，不替代单店判断。'
    ),
    'known_unknown', '2026年部分托管酒店数据直连在报告中仍是计划，不是全部完成事实',
    'transfer_limit', 'management philosophy and planned connections do not prove current target-hotel integration'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'tripcom_rwg_api_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'tripcom_resorts_world_genting_api_2025',
    'requires_explicit_case_key', true,
    'evidence_level', 'platform_reported_current_integration_practice',
    'source_refs', JSON_ARRAY('tripcom_resorts_world_genting_api_2025'),
    'published_at', '2025-07-09',
    'fact', 'Trip.com披露与云顶世界的合作包含酒店和主题乐园预订系统直接API集成。',
    'transferable_method', 'hotel plus experience inventory should share product identity service date entitlement redemption refund and readback state',
    'known_unknowns', JSON_ARRAY(
      '入住或核销结果',
      '佣金和净收入',
      '退款与异常率',
      '利润变化'
    ),
    'transfer_limit', 'current integration capability is not a hotel success outcome'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'meituan_hms_capability_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'meituan_hms_current_capability_2025',
    'requires_explicit_case_key', true,
    'evidence_level', 'official_current_product_capability_claim',
    'source_refs', JSON_ARRAY('meituan_hms_current_capability_2025'),
    'accessed_at', @recent_reviewed_at,
    'claims', JSON_ARRAY(
      '美团订单深度直连',
      '库存实时同步',
      'PMS统一修改价量态',
      '超过100000家酒店使用'
    ),
    'transferable_method', 'price inventory availability order room mapping and readback state belong to one integration contract',
    'known_unknowns', JSON_ARRAY(
      '当前酒店是否真实启用',
      '目标字段覆盖',
      '同步准确率和异常率',
      '酒店级净收入与利润增量'
    ),
    'transfer_limit', 'product-page claims do not prove current hotel capture save readback or revenue outcome'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'siteminder_booking_trends_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'siteminder_booking_trends_2025',
    'requires_explicit_case_key', true,
    'evidence_level', 'vendor_aggregate_benchmark',
    'source_refs', JSON_ARRAY('siteminder_booking_trends_2025'),
    'data_year', '2025',
    'sample', 'more than 135 million reservations across 20 established destinations',
    'reported_values', JSON_OBJECT(
      'hotel_website_average_booking_value_usd', 516,
      'ota_average_booking_value_usd', 312,
      'average_lead_time_days', 32.15,
      'average_cancellation_rate_percent', 19.15
    ),
    'transferable_method', 'compare channel booking value length of stay extras cancellations commission and acquisition cost together',
    'transfer_limit', 'international aggregate values are context only and cannot become China or target-hotel thresholds'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'cloudbeds_independent_hotels_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'cloudbeds_independent_hotels_2026',
    'requires_explicit_case_key', true,
    'evidence_level', 'vendor_aggregate_benchmark',
    'source_refs', JSON_ARRAY('cloudbeds_independent_hotels_2026'),
    'report_year', '2026',
    'sample', '90 million bookings across 180 countries and tens of thousands of independent hotels',
    'reported_values', JSON_OBJECT(
      'booking_window_2025_days', 40,
      'cancellation_window_2025_days', 38.7,
      'average_stay_nights', 2.6,
      'seven_to_thirteen_night_booking_yoy_growth_percent', 25
    ),
    'transferable_method', 'model booking and cancellation windows separately and retain the opportunity to resell cancelled inventory',
    'transfer_limit', 'global independent-hotel aggregates do not replace target-hotel comparable stay-date curves'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'china_hotel_hci_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'china_hotel_hci_2025_12',
    'requires_explicit_case_key', true,
    'evidence_level', 'association_industry_index',
    'source_refs', JSON_ARRAY('china_hotel_hci_2025_12'),
    'published_at', '2026-03-16',
    'data_period', '2025-12',
    'sample_boundary', 'platform data plus survey data from approximately 30 hotel groups and 110 properties',
    'reported_values', JSON_OBJECT(
      'hci', 90.5,
      'average_room_rate_index', 90.9,
      'occupancy_index', 86.1,
      'online_booking_index', 103.8,
      'hotel_food_beverage_revenue_index', 76.6
    ),
    'transferable_method', 'occupancy recovery must be reviewed with ADR revenue mix channel cost and profit',
    'transfer_limit', 'industry index is not a target-hotel goal and excludes some non-room revenue categories'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'duetto_jannah_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'duetto_jannah_2025',
    'requires_explicit_case_key', true,
    'evidence_level', 'vendor_published_customer_case',
    'source_refs', JSON_ARRAY('duetto_jannah_2025'),
    'published_at', '2025-01-27',
    'reported_context', 'five-property portfolio integrated with Opera PMS and TravelClick CRS and booking engine',
    'reported_values', JSON_OBJECT(
      'portfolio_revpar_growth_percent', 22,
      'portfolio_adr_growth_percent', 4.8,
      'jannah_burj_al_sarab_revpar_growth_percent', 57,
      'jannah_burj_al_sarab_adr_growth_percent', 34
    ),
    'reported_process_change', 'automated rate changes forecasts and live owner reports reduced manual work',
    'transferable_method', 'select RMS by forecast accuracy usability reporting ROI and PMS CRS compatibility and separate portfolio from property results',
    'transfer_limit', 'vendor case lacks independent audit matched control complete cost and isolated causality'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'mews_terrace_bay_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'mews_terrace_bay_2025',
    'requires_explicit_case_key', true,
    'evidence_level', 'vendor_published_customer_case',
    'source_refs', JSON_ARRAY('mews_terrace_bay_2025'),
    'published_at', '2025-12-02',
    'reported_context', '117-room hotel replaced twice-daily manual rate changes with live automation and combined direct advertising with automated guest messaging',
    'reported_values', JSON_OBJECT(
      'average_room_rate_growth_low_percent', 20,
      'average_room_rate_growth_high_percent', 25,
      'upsell_growth_value', NULL
    ),
    'reported_upsell_categories', JSON_ARRAY(
      'restaurant_seats',
      'room_upgrades',
      'late_checkout'
    ),
    'transferable_method', 'separate real-time pricing direct acquisition and ancillary messaging attribution and include commission advertising labor and service costs',
    'transfer_limit', 'vendor case omits exact upsell increment complete baseline independent audit and isolated causality'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

INSERT INTO `tmp_recent_success_practice_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @recent_unit_id,
  'landing_status',
  JSON_OBJECT(
    'scope', 'knowledge_landing_status',
    'evidence_level', 'repository_database_forward_migration_contract',
    'source_refs', JSON_ARRAY(
      'docs/hotel_revenue_success_practices_extension_knowledge.md',
      'database/migrations/20260730_update_hotel_revenue_success_practices_recent_sources.sql',
      'tests/HotelRevenueSuccessPracticesExtensionKnowledgeTest.php'
    ),
    'status', 'recent_source_refresh_ready',
    'freshness_window', '2025-01-01_to_2026-07-30',
    'active_generic_methods', JSON_ARRAY(
      'booking_curve_forecast_learning',
      'constrained_inventory_value',
      'total_revenue_experience_product',
      'ota_pms_reconciliation_contract',
      'data_standardization_exception_action',
      'human_hotel_autonomy_guardrail',
      'external_case_transfer_policy'
    ),
    'active_case_keys', JSON_ARRAY(
      'shiji_shenzhen_mgm_ota_reconciliation_2025',
      'shiji_poly_business_finance_data_2026',
      'tripcom_resorts_world_genting_api_2025',
      'meituan_hms_current_capability_2025',
      'siteminder_booking_trends_2025',
      'cloudbeds_independent_hotels_2026',
      'china_hotel_hci_2025_12',
      'duetto_jannah_2025',
      'mews_terrace_bay_2025'
    ),
    'historical_source_set', 'retained_but_lifecycle_stale',
    'default_case_retrieval', 'excluded_without_exact_active_case_key',
    'runtime_execution', 'no_external_action',
    'truthful_completion_statement', '2025_2026_sources_activated_without_promoting_capability_or_vendor_case_numbers_to_current_hotel_facts'
  ),
  0,
  NOW()
WHERE @recent_unit_id IS NOT NULL;

UPDATE `tmp_recent_success_practice_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.module_id', 'hotel_revenue_success_practices_recent_sources',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'operations', 'finance'),
  '$.scenes', JSON_ARRAY(
    'morning_meeting',
    'weekly_review',
    'revenue_meeting',
    'reconciliation_review',
    'owner_meeting'
  ),
  '$.platforms', JSON_ARRAY('ctrip', 'meituan', 'ota_generic', 'pms', 'pos', 'finance', 'manual_review'),
  '$.seed_owner', @recent_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `unit`.`name`, ':', `seed`.`type`),
  '$.seed_version', @recent_version,
  '$.lifecycle_status', 'active'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_recent_success_practice_chunks` AS `seed`
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
FROM `tmp_recent_success_practice_chunks` AS `seed`
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

DROP TEMPORARY TABLE `tmp_recent_success_practice_chunks`;

SET @recent_staff_content := CONCAT(
  '# 酒店收益成功实践延伸知识（2025—2026资料刷新）', '\n\n',
  '## 当前最直接的成功实践', '\n',
  '2025年深圳美高梅试运营携程、美团、飞猪及境外OTA自动对账，并把PMS、POS、成本采购和OA数据接入BI审计看板。宿析吸收的是“先统一编码、再完整采集、先对账、再诊断、最后输出异常行动”，不是照搬供应商或把试运营写成已产生确定利润。', '\n\n',
  '## 已知的已知', '\n',
  '渠道订单、金额、佣金、补贴、退款、结算和PMS入住必须按门店与经营日对账；集团分析和AI建议不能替代单店判断；全球行业均值只作背景；外部客户案例数字必须显式case_key读取。', '\n\n',
  '## 已知的未知', '\n',
  '当前门店携程、美团与PMS字段是否齐全且对账成功；美团PMS能力在当前门店的真实准确率和经营增量；Trip.com API直连的入住、核销和利润结果；供应商案例的完整成本与独立因果。缺失时返回partial或blocked。', '\n\n',
  '## 当前保护边界', '\n',
  '不把独家换流量、全网最低价或无授权平台自动降价吸收为成功经验。宿析只生成可解释、可回滚、待人工确认的建议，不直接执行OTA、PMS、库存、产品或投流写入。', '\n\n',
  '## 历史资料处理', '\n',
  '2021年携程、2017年Duetto及2024年美团旧案例保留数据库审计记录，但已退出活跃知识检索。'
);

UPDATE `knowledge_base`
SET
  `content` = @recent_staff_content,
  `keywords` = '2025,2026,携程,美团,OTA对账,PMS,POS,业财融合,经营日,房型映射,结算,异常预警,预订曲线,取消窗口,渠道价值,总收益,定价自主权,人工复核',
  `tags` = JSON_ARRAY(
    '收益管理',
    'OTA对账',
    'PMS',
    '业财融合',
    '渠道价值',
    '定价自主权',
    '2025',
    '2026',
    'manual_review_only'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @recent_unit_name;
