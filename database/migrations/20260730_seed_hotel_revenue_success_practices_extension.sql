-- Extend the existing revenue-operations knowledge with reviewed hotel success
-- practices. This migration stores decision methods and protected case
-- references only. It does not import current-hotel facts or execute PMS/OTA
-- pricing, inventory, length-of-stay or advertising actions.
--
-- Safe rerun contract:
-- - preserve operator-authored chunks;
-- - preserve older seed versions for traceability;
-- - update only the exact current seed owner + key + version rows.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @success_ext_version := '2026-07-30.1';
SET @success_ext_reviewed_at := '2026-07-30';
SET @success_ext_seed_owner := 'suxios.hotel_revenue_success_practices_extension';
SET @success_ext_unit_name := '酒店收益成功实践延伸知识';
SET @success_ext_source := 'revenue_operations_decision_support';
SET @success_ext_description := '在宿析既有流量漏斗、指标语义、价格实验、房型角色、渠道收益和OTB/Pickup知识之上，补充同入住日预订曲线与预测误差学习、稀缺库存订单总价值判断、体验产品与总收益方法。外部平台和供应商案例仅作显式case_reference，不替代当前门店事实或自动执行动作。';

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
  @success_ext_unit_name,
  @success_ext_source,
  'done',
  @success_ext_description,
  JSON_ARRAY(
    '收益管理',
    '预订曲线',
    '预测误差',
    '库存价值',
    '入住时长',
    '总收益',
    '体验产品',
    '成功案例',
    'structured_knowledge',
    'manual_review_only'
  ),
  0,
  'active',
  'reviewed_extension_of_existing_suxios_revenue_operations_knowledge',
  CONCAT(@success_ext_reviewed_at, ' 00:00:00'),
  JSON_ARRAY(
    '预订节奏必须按同入住日与同提前天数比较，预测值应在入住日后与实际入住间夜和房费收入复盘。',
    '稀缺房量下应比较整笔订单净价值与可能挤出的后续净贡献，单晚价格和订单数都不足以独立决策。',
    '体验产品应联合观察房费、附加收入、直接成本、服务产能和净贡献。',
    '外部成功数字只作显式案例，不作为当前门店事实、固定阈值或单一因果证明。'
  ),
  JSON_ARRAY(
    '当前PMS是否具备可比历史快照、累计取消、未到、逐日房量、入住天数和最终入住事实。',
    '当前门店的附加收入、直接成本、增量人工、退款和体验产能是否已核验。',
    '外部平台或供应商案例在当前门店条件下的适用性与实际增量效果。',
    '宿析当前是否拥有任何入住时长或库存限制的外部执行权限；在验证前一律视为未实现。'
  ),
  @success_ext_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units`
  WHERE `name` = @success_ext_unit_name
    AND `source` = @success_ext_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @success_ext_description,
  `tags` = JSON_ARRAY(
    '收益管理',
    '预订曲线',
    '预测误差',
    '库存价值',
    '入住时长',
    '总收益',
    '体验产品',
    '成功案例',
    'structured_knowledge',
    'manual_review_only'
  ),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'reviewed_extension_of_existing_suxios_revenue_operations_knowledge',
  `reviewed_at` = CONCAT(@success_ext_reviewed_at, ' 00:00:00'),
  `known_knowns` = JSON_ARRAY(
    '预订节奏必须按同入住日与同提前天数比较，预测值应在入住日后与实际入住间夜和房费收入复盘。',
    '稀缺房量下应比较整笔订单净价值与可能挤出的后续净贡献，单晚价格和订单数都不足以独立决策。',
    '体验产品应联合观察房费、附加收入、直接成本、服务产能和净贡献。',
    '外部成功数字只作显式案例，不作为当前门店事实、固定阈值或单一因果证明。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '当前PMS是否具备可比历史快照、累计取消、未到、逐日房量、入住天数和最终入住事实。',
    '当前门店的附加收入、直接成本、增量人工、退款和体验产能是否已核验。',
    '外部平台或供应商案例在当前门店条件下的适用性与实际增量效果。',
    '宿析当前是否拥有任何入住时长或库存限制的外部执行权限；在验证前一律视为未实现。'
  ),
  `truth_profile_version` = @success_ext_version,
  `updated_at` = NOW()
WHERE `name` = @success_ext_unit_name
  AND `source` = @success_ext_source;

SET @success_ext_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @success_ext_unit_name
    AND `source` = @success_ext_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_success_ext_seed_chunks`;
CREATE TEMPORARY TABLE `tmp_success_ext_seed_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_success_ext_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_external_method_and_case_sources',
    'source_refs', JSON_ARRAY(
      'tripcom_partner_summit_2022',
      'tripcom_wyndham_super_brand_day_2021',
      'meituan_luoyang_hanfu_hotel_2024',
      'meituan_hms_single_hotel_solution',
      'cornell_hotel_revenue_management_intro',
      'ideas_stayntouch_lrv_2026',
      'duetto_nh_hotel_group_case',
      'duetto_nira_caledonia_case',
      'duetto_ovolo_hotels_case'
    ),
    'reviewed_at', @success_ext_reviewed_at,
    'source_manifest', JSON_OBJECT(
      'tripcom_partner_summit_2022', JSON_OBJECT(
        'publisher', 'Trip.com Group',
        'published_at', '2022-12-21',
        'url', 'https://www.trip.com/newsroom/cooperating-with-partners-to-ensure-the-continual-evolution-of-travel/',
        'kind', 'platform_reported_aggregate_results',
        'transfer_limit', 'aggregated platform results do not prove an individual hotel outcome'
      ),
      'tripcom_wyndham_super_brand_day_2021', JSON_OBJECT(
        'publisher', 'Trip.com Group',
        'published_at', '2021-11-18',
        'url', 'https://www.trip.com/newsroom/trip-com-group-and-wyndham-hotels-resorts-sign-strategic-global-agreement/',
        'kind', 'platform_reported_single_campaign_case',
        'transfer_limit', 'sales value is not stayed revenue net revenue or profit'
      ),
      'meituan_luoyang_hanfu_hotel_2024', JSON_OBJECT(
        'publisher', 'Meituan',
        'published_at', '2024-04-26',
        'url', 'https://www.meituan.com/zh-HK/news/NN240426050008430',
        'kind', 'platform_and_merchant_reported_case',
        'transfer_limit', 'cost channel mix cancellations and control group are not disclosed'
      ),
      'meituan_hms_single_hotel_solution', JSON_OBJECT(
        'publisher', 'Meituan Hotel Management System',
        'accessed_at', @success_ext_reviewed_at,
        'url', 'https://hms.meituan.com/home/industry-solution/individual',
        'kind', 'official_product_capability_page',
        'transfer_limit', 'capability description is not outcome evidence'
      ),
      'cornell_hotel_revenue_management_intro', JSON_OBJECT(
        'publisher', 'Cornell University',
        'accessed_at', @success_ext_reviewed_at,
        'url', 'https://ecommons.cornell.edu/bitstreams/e15688e4-4b0e-437f-9c39-aa507f9a0b1f/download',
        'kind', 'academic_educational_method',
        'transfer_limit', 'methodology requires current hotel calibration'
      ),
      'ideas_stayntouch_lrv_2026', JSON_OBJECT(
        'publisher', 'IDeaS',
        'published_at', '2026-05-05',
        'url', 'https://ideas.com/news/ideas-expands-integration-with-stayntouch-pms-lrv/',
        'kind', 'vendor_product_method_announcement',
        'transfer_limit', 'method description has no independently verified outcome in this source'
      ),
      'duetto_nh_hotel_group_case', JSON_OBJECT(
        'publisher', 'Duetto',
        'url', 'https://www.duettocloud.com/hubfs/case-study-NH-Hotel-2023.pdf',
        'kind', 'vendor_published_customer_case',
        'transfer_limit', 'before_after outcome does not isolate single causality'
      ),
      'duetto_nira_caledonia_case', JSON_OBJECT(
        'publisher', 'Duetto',
        'url', 'https://www.duettocloud.com/hubfs/case-study-Nira-Caledonia.pdf',
        'kind', 'vendor_published_customer_case',
        'transfer_limit', 'single hotel comparison does not provide a matched control'
      ),
      'duetto_ovolo_hotels_case', JSON_OBJECT(
        'publisher', 'Duetto',
        'url', 'https://www.duettocloud.com/hubfs/case-study-ovolo-hotels.pdf?hsLang=en-us',
        'kind', 'vendor_published_customer_case',
        'transfer_limit', 'used as supporting method evidence not a universal benchmark'
      )
    ),
    'rules', JSON_ARRAY(
      '外部方法只补充宿析尚未结构化的经营判断，不重复现有漏斗、定价实验、渠道净收益或OTB规则。',
      '案例数字默认排除，只有完全匹配case_key时返回。',
      '平台汇总、商家自述和供应商前后对比均不自动证明单一因果。',
      '知识只生成待人工复核建议，不执行PMS、OTA、库存、入住时长或投流写入。'
    )
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'booking_curve_forecast_learning',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'academic_method_plus_reviewed_industry_cases',
    'source_refs', JSON_ARRAY(
      'cornell_hotel_revenue_management_intro',
      'duetto_nira_caledonia_case',
      'duetto_ovolo_hotels_case'
    ),
    'extends_existing', JSON_ARRAY(
      'OTB与Pickup规则',
      'comparison_contract',
      'delta_metric_contract'
    ),
    'required_identity', JSON_ARRAY(
      'tenant_id',
      'system_hotel_id',
      'stay_date',
      'snapshot_date',
      'days_before_arrival',
      'fact_scope',
      'metric_semantic_version',
      'source_method',
      'quality_status'
    ),
    'required_inputs', JSON_ARRAY(
      'otb_room_nights',
      'otb_room_revenue',
      'net_pickup',
      'cumulative_cancellations_if_available',
      'remaining_sellable_rooms',
      'room_type',
      'market_segment',
      'channel',
      'demand_date_type',
      'holiday_event_tag',
      'actual_stayed_room_nights_after_stay',
      'actual_room_revenue_after_stay'
    ),
    'comparison_rule', 'compare the same stay date at the same days_before_arrival or a calibrated comparable demand-date cohort; do not compare snapshot calendar dates alone',
    'derived_metrics', JSON_OBJECT(
      'pace_gap_room_nights', 'current_otb_room_nights-comparable_curve_otb_room_nights',
      'forecast_error_room_nights', 'actual_stayed_room_nights-forecast_room_nights_as_of',
      'forecast_error_revenue', 'actual_room_revenue-forecast_room_revenue_as_of',
      'forecast_ape', 'abs(actual-forecast)/actual when actual is verified and greater than zero else null'
    ),
    'rules', JSON_ARRAY(
      '同入住日、同提前天数、同范围、同口径和同质量是自动比较前提。',
      '星期、节假日、事件、房量或销售范围变化时必须重建可比组或标记不可比。',
      '累计取消缺失时保留净Pickup，不能改名为毛新增预订。',
      '入住日结束后必须保存预测值与实际入住间夜、实际房费收入的误差。',
      '样本不足时标记experimental_rule，只输出补数或人工实验建议。'
    ),
    'blocked_when', JSON_ARRAY(
      'stay_date_missing',
      'days_before_arrival_missing',
      'snapshot_history_missing',
      'scope_or_semantics_mismatch',
      'quality_unverified',
      'comparable_sample_missing'
    ),
    'candidate_outputs', JSON_ARRAY(
      'hold',
      'collect_missing_evidence',
      'inspect_room_type_or_channel_mix',
      'prepare_bounded_price_or_product_experiment',
      'schedule_manual_review'
    ),
    'automatic_external_action', false
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'constrained_inventory_value',
  JSON_OBJECT(
    'scope', 'generic_decision_support_only',
    'evidence_level', 'industry_method_vendor_documented_not_suxios_runtime',
    'source_refs', JSON_ARRAY(
      'ideas_stayntouch_lrv_2026',
      'duetto_nh_hotel_group_case',
      'cornell_hotel_revenue_management_intro'
    ),
    'extends_existing', JSON_ARRAY(
      '房型角色方法',
      'price_experiment_room_roles',
      '渠道收益诊断'
    ),
    'decision_question', 'when inventory is scarce does the whole booking net value exceed the net contribution likely displaced by accepting it',
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
      'expected_higher_value_demand',
      'execution_permission'
    ),
    'derived_concepts', JSON_OBJECT(
      'net_booking_value', 'expected_room_revenue+verified_ancillary_revenue-commission-variable_cost-expected_cancellation_or_no_show_cost',
      'displacement_cost', 'expected_net_contribution_of_future_demand_displaced_by_accepting_the_booking',
      'decision_margin', 'net_booking_value-displacement_cost'
    ),
    'rules', JSON_ARRAY(
      '先检查整段入住日期是否占用稀缺旺日，再看整笔订单净价值，不能只看首晚价格。',
      '附加收入、取消概率、未到概率或机会成本未经核验时保持null。',
      '最后一间房价值、最短入住和到店限制只形成待人工复核建议。',
      '限制必须按入住日、房型和需求状态逐段判断，并同时检查肩部日期损失。',
      '没有逐日房量、取消未到风险或外部执行权限时状态为blocked。'
    ),
    'candidate_actions', JSON_ARRAY(
      'protect_peak_inventory_for_manual_review',
      'review_minimum_length_of_stay',
      'review_closed_to_arrival',
      'release_protection_after_demand_weakens',
      'collect_missing_pms_facts'
    ),
    'blocked_when', JSON_ARRAY(
      'pms_daily_inventory_missing',
      'length_of_stay_missing',
      'net_value_inputs_missing',
      'cancellation_or_no_show_evidence_missing',
      'forecast_or_comparable_history_missing',
      'external_execution_permission_unverified'
    ),
    'suxios_runtime_status', 'knowledge_only_not_pms_crs_inventory_control',
    'automatic_external_action', false
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'total_revenue_experience_product',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'platform_reported_case_plus_total_revenue_method',
    'source_refs', JSON_ARRAY(
      'meituan_luoyang_hanfu_hotel_2024',
      'tripcom_partner_summit_2022',
      'duetto_nh_hotel_group_case'
    ),
    'extends_existing', JSON_ARRAY(
      'traffic_funnel_contract',
      'price_experiment_room_roles',
      '渠道收益诊断',
      'TRevPAR metric definition'
    ),
    'required_inputs', JSON_ARRAY(
      'product_or_package_id',
      'stay_date',
      'ota_exposure',
      'detail_views',
      'bookings',
      'stayed_bookings',
      'room_revenue',
      'ancillary_revenue',
      'direct_product_cost',
      'incremental_labor_cost',
      'commission',
      'refunds',
      'capacity',
      'guest_feedback',
      'comparison_baseline'
    ),
    'source_ownership', JSON_OBJECT(
      'ota', JSON_ARRAY('exposure', 'detail_views', 'channel_orders', 'channel_campaign'),
      'pms', JSON_ARRAY('stayed_bookings', 'room_revenue', 'room_type', 'stay_date'),
      'pos_store_or_verified_manual_ledger', JSON_ARRAY('ancillary_revenue', 'direct_product_cost', 'refunds')
    ),
    'derived_metrics', JSON_OBJECT(
      'ancillary_attach_rate', 'ancillary_purchasing_stayed_bookings/stayed_bookings when denominator is greater than zero else null',
      'total_revenue', 'room_revenue+verified_ancillary_revenue',
      'net_total_revenue', 'total_revenue-commission-refunds',
      'incremental_contribution', 'incremental_total_revenue-incremental_direct_product_cost-incremental_labor_cost'
    ),
    'rules', JSON_ARRAY(
      '体验产品必须与目的地需求、客群和房型承接一致，不把普通赠品包装成差异化。',
      'OTA漏斗、PMS入住房费和附加收入成本必须分源记录，不能互相替代。',
      '先做限定日期、限定房型和限定产能的小实验。',
      '同时观察附加购买率、总收入、净贡献、取消、差评和服务产能。',
      '没有直接成本和可比基线时只能写收入增加，不能写利润提升或单一因果。'
    ),
    'blocked_when', JSON_ARRAY(
      'stayed_booking_scope_missing',
      'ancillary_source_unverified',
      'direct_cost_missing_for_profit_claim',
      'comparison_baseline_missing_for_incrementality',
      'capacity_or_service_quality_missing'
    ),
    'automatic_external_action', false
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'external_case_transfer_policy',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_transfer_and_rejection_policy',
    'source_refs', JSON_ARRAY(
      'tripcom_partner_summit_2022',
      'tripcom_wyndham_super_brand_day_2021',
      'meituan_luoyang_hanfu_hotel_2024',
      'meituan_hms_single_hotel_solution',
      'cornell_hotel_revenue_management_intro',
      'ideas_stayntouch_lrv_2026',
      'duetto_nh_hotel_group_case',
      'duetto_nira_caledonia_case',
      'duetto_ovolo_hotels_case'
    ),
    'merged_into_existing', JSON_ARRAY(
      JSON_OBJECT(
        'external_practice', 'content lists live streaming and campaign traffic',
        'existing_knowledge', 'traffic_funnel_contract',
        'reason', 'SUXIOS already requires exposure through contribution-margin attribution'
      ),
      JSON_OBJECT(
        'external_practice', 'independent pricing by property room segment and stay date',
        'existing_knowledge', 'price_experiment_room_roles and 房型角色方法',
        'reason', 'SUXIOS already requires one object one date one variable and room-role boundaries'
      ),
      JSON_OBJECT(
        'external_practice', 'business mix and channel mix optimization',
        'existing_knowledge', '渠道收益诊断',
        'reason', 'SUXIOS already requires net ADR commission cancellation lead time and room type mix'
      ),
      JSON_OBJECT(
        'external_practice', 'room confirmation efficiency',
        'existing_knowledge', 'traffic_funnel_contract and payment confirmation conversion',
        'reason', 'SUXIOS already separates process efficiency from stayed revenue and profit'
      )
    ),
    'new_knowledge', JSON_ARRAY(
      'same_stay_date_booking_curve_and_forecast_error_learning',
      'whole_booking_value_and_displacement_under_scarce_inventory',
      'experience_product_total_revenue_and_incremental_contribution'
    ),
    'rejected_rules', JSON_ARRAY(
      'joining_one_platform_guarantees_profitability',
      'exposure_rank_live_sales_or_campaign_gmv_equals_hotel_profit',
      'external_case_adr_revpar_occupancy_or_sales_value_is_a_cross_hotel_threshold',
      'vendor_before_after_result_proves_single_causality',
      'automatic_length_of_stay_or_inventory_control_without_verified_pms_inputs_and_permission',
      'ancillary_revenue_can_ignore_cost_capacity_refunds_or_guest_feedback'
    ),
    'default_case_retrieval', 'excluded_without_exact_case_key'
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'meituan_hanfu_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'meituan_luoyang_hanfu_hotel_2024',
    'requires_explicit_case_key', true,
    'evidence_level', 'platform_and_merchant_reported_case_not_independently_audited',
    'source_refs', JSON_ARRAY('meituan_luoyang_hanfu_hotel_2024'),
    'published_at', '2024-04-26',
    'reported_facts', JSON_OBJECT(
      'hotel', '洛见·汉服·观影民宿酒店',
      'approx_room_count', 30,
      'opened_at', '2023-10',
      'after_six_months_trade_area_traffic_rank', 'top_5',
      'reported_average_occupancy_percent', 80,
      'reported_2024_feb_total_revenue_cny', 'greater_than_100000',
      'reported_2024_feb_hanfu_ancillary_revenue_cny', 'greater_than_20000'
    ),
    'transferable_pattern', JSON_ARRAY(
      'destination_culture_demand',
      'distinctive_room_and_experience_product',
      'free_core_experience_plus_paid_service',
      'room_revenue_plus_ancillary_revenue'
    ),
    'unknowns', JSON_ARRAY(
      'full_direct_and_labor_cost',
      'channel_mix',
      'cancellation_and_refund',
      'comparison_control',
      'audited_profit',
      'long_term_repeatability'
    ),
    'blocked_uses', JSON_ARRAY(
      'cross_hotel_occupancy_target',
      'cross_hotel_revenue_target',
      'profit_causality',
      'automatic_product_launch'
    )
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'tripcom_wyndham_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'tripcom_wyndham_919_campaign_2021',
    'requires_explicit_case_key', true,
    'evidence_level', 'platform_reported_single_campaign_case_not_independently_audited',
    'source_refs', JSON_ARRAY('tripcom_wyndham_super_brand_day_2021'),
    'published_at', '2021-11-18',
    'reported_facts', JSON_OBJECT(
      'hotel', 'Wyndham Grand Plaza Royale Villas Jinlin Plaza Lijiang',
      'campaign', 'Ctrip 919 Super Brand Day',
      'reported_single_store_sales_cny', 12000000,
      'reported_result', 'highest sales volume in the single-store category'
    ),
    'transferable_pattern', JSON_ARRAY(
      'campaign_specific_inventory_and_product',
      'brand_event',
      'live_stream_distribution',
      'clear_campaign_measurement_window'
    ),
    'unknowns', JSON_ARRAY(
      'booked_room_nights',
      'stayed_room_nights',
      'cancellations',
      'commission_and_campaign_cost',
      'net_revenue',
      'profit',
      'demand_shift_from_other_dates_or_channels'
    ),
    'blocked_uses', JSON_ARRAY(
      'cross_hotel_sales_target',
      'sales_equals_stayed_revenue',
      'sales_equals_profit',
      'campaign_result_as_steady_state'
    )
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'duetto_nh_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'duetto_nh_hotel_group_2017',
    'requires_explicit_case_key', true,
    'evidence_level', 'vendor_published_customer_case_before_after_not_independently_audited',
    'source_refs', JSON_ARRAY('duetto_nh_hotel_group_case'),
    'reported_comparison', '2017_vs_2016',
    'reported_facts', JSON_OBJECT(
      'portfolio_size_hotels', 400,
      'revpar_change_percent', 8.5,
      'adr_change_percent', 4.9,
      'occupancy_change_points_or_percent_as_reported', 3.4,
      'revenue_change_percent', 6.5,
      'reported_revenue_eur', 1570000000
    ),
    'reported_methods', JSON_ARRAY(
      'pms_crs_rms_technology_change',
      'segmentation',
      'brand_repositioning',
      'release_low_value_room_nights',
      'focus_from_revpar_to_trevpar_to_net_trevpar',
      'company_wide_revenue_training'
    ),
    'unknowns', JSON_ARRAY(
      'matched_control',
      'market_and_macro_effect',
      'individual_method_contribution',
      'implementation_cost',
      'property_level_variance'
    ),
    'blocked_uses', JSON_ARRAY(
      'cross_hotel_growth_threshold',
      'single_causality_claim',
      'automatic_business_mix_change'
    )
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'duetto_nira_case',
  JSON_OBJECT(
    'scope', 'case_reference',
    'case_key', 'duetto_nira_caledonia_2017',
    'requires_explicit_case_key', true,
    'evidence_level', 'vendor_published_customer_case_before_after_not_independently_audited',
    'source_refs', JSON_ARRAY('duetto_nira_caledonia_case'),
    'reported_comparison', '2017_06_vs_2016_06',
    'reported_facts', JSON_OBJECT(
      'room_count', 28,
      'revpar_change_percent', 24.6,
      'adr_change_percent', 18.4,
      'revpar_index_change_percent', 9.8,
      'revenue_meeting_minutes_before', 120,
      'revenue_meeting_minutes_after', 20
    ),
    'reported_methods', JSON_ARRAY(
      'open_pricing_all_segments',
      'business_mix_change',
      'forecast_and_trend_analysis',
      'focus_on_rate_and_revpar_not_occupancy_only',
      'pms_connected_rate_change',
      'guest_facing_app'
    ),
    'unknowns', JSON_ARRAY(
      'matched_control',
      'market_and_event_effect',
      'individual_method_contribution',
      'implementation_cost',
      'cancellation_and_profit_detail'
    ),
    'blocked_uses', JSON_ARRAY(
      'cross_hotel_growth_threshold',
      'single_causality_claim',
      'automatic_pricing'
    )
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

INSERT INTO `tmp_success_ext_seed_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @success_ext_unit_id,
  'landing_status',
  JSON_OBJECT(
    'scope', 'knowledge_landing_status',
    'evidence_level', 'repository_and_database_seed_contract',
    'source_refs', JSON_ARRAY(
      'docs/hotel_revenue_success_practices_extension_knowledge.md',
      'database/migrations/20260730_seed_hotel_revenue_success_practices_extension.sql',
      'tests/HotelRevenueSuccessPracticesExtensionKnowledgeTest.php'
    ),
    'status', 'knowledge_seed_ready',
    'added_generic_methods', JSON_ARRAY(
      'booking_curve_forecast_learning',
      'constrained_inventory_value',
      'total_revenue_experience_product',
      'external_case_transfer_policy'
    ),
    'protected_case_keys', JSON_ARRAY(
      'meituan_luoyang_hanfu_hotel_2024',
      'tripcom_wyndham_919_campaign_2021',
      'duetto_nh_hotel_group_2017',
      'duetto_nira_caledonia_2017'
    ),
    'default_case_retrieval', 'excluded',
    'runtime_execution', 'no_external_action',
    'truthful_completion_statement', 'existing_suxios_knowledge_extended_without_promoting_external_case_numbers_to_current_hotel_facts'
  ),
  0,
  NOW()
WHERE @success_ext_unit_id IS NOT NULL;

UPDATE `tmp_success_ext_seed_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.module_id', 'hotel_revenue_success_practices_extension',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'operations'),
  '$.scenes', JSON_ARRAY(
    'morning_meeting',
    'weekly_review',
    'revenue_meeting',
    'inventory_review',
    'product_experiment',
    'owner_meeting'
  ),
  '$.platforms', JSON_ARRAY('ota_generic', 'ctrip', 'meituan', 'pms', 'manual_review'),
  '$.seed_owner', @success_ext_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `unit`.`name`, ':', `seed`.`type`),
  '$.seed_version', @success_ext_version,
  '$.lifecycle_status', 'active'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_success_ext_seed_chunks` AS `seed`
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
FROM `tmp_success_ext_seed_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_success_ext_seed_chunks`;

SET @success_ext_category_name := '收益管理与经营解读';
SET @success_ext_category_description := '酒店流量、转化、收益、价格、房型、预订节奏、库存价值、体验产品、行动保护和复盘方法。';

INSERT INTO `knowledge_categories` (
  `tenant_id`,
  `hotel_id`,
  `parent_id`,
  `name`,
  `description`,
  `sort_order`,
  `is_enabled`,
  `create_time`,
  `update_time`
)
SELECT
  0,
  0,
  0,
  @success_ext_category_name,
  @success_ext_category_description,
  0,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_categories`
  WHERE `hotel_id` = 0
    AND `parent_id` = 0
    AND `name` = @success_ext_category_name
);

UPDATE `knowledge_categories`
SET
  `tenant_id` = 0,
  `description` = @success_ext_category_description,
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `parent_id` = 0
  AND `name` = @success_ext_category_name;

SET @success_ext_category_id := (
  SELECT `id`
  FROM `knowledge_categories`
  WHERE `hotel_id` = 0
    AND `parent_id` = 0
    AND `name` = @success_ext_category_name
  ORDER BY `id` ASC
  LIMIT 1
);

SET @success_ext_staff_content := CONCAT(
  '# 酒店收益成功实践延伸知识', '\n\n',
  '## 新增一：预订曲线与预测误差', '\n',
  '按同入住日、同提前天数、同范围和同口径比较OTB与Pickup；入住日结束后用实际入住间夜和房费收入回测预测。取消缺失时保留净Pickup，不猜测毛新增。', '\n\n',
  '## 新增二：稀缺库存价值', '\n',
  '接近满房时比较整笔订单净价值与可能挤出的后续净贡献，不只看首晚价格或订单数。最短入住、到店限制和最后一间房保护只生成待人工复核建议；逐日库存、取消未到或权限缺失时阻断。', '\n\n',
  '## 新增三：体验产品与总收益', '\n',
  'OTA记录内容到订单漏斗，PMS记录入住与房费，POS、商城或核验台账记录附加收入和成本。没有直接成本和可比基线时只描述收入，不能声称利润或因果改善。', '\n\n',
  '## 与已有知识的关系', '\n',
  '内容、榜单、直播和活动并入现有流量漏斗；分房型与分客群定价并入现有价格实验和房型角色；业务结构并入现有渠道净收益诊断，不重复建立规则。', '\n\n',
  '## 使用边界', '\n',
  '外部平台、商家和供应商案例数字默认排除，必须显式case_key读取；不得跨店套用，不得直接触发PMS、OTA、库存、入住时长或投流写入。'
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
  COALESCE(@success_ext_category_id, 0),
  @success_ext_unit_name,
  @success_ext_staff_content,
  '预订曲线,提前预订,OTB,Pickup,预测误差,库存价值,订单总价值,挤出成本,入住时长,最短入住,总收益,TRevPAR,附加收入,体验产品,携程,美团,PMS',
  JSON_ARRAY(
    '收益管理',
    '预订曲线',
    '库存价值',
    '总收益',
    '体验产品',
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
    AND `title` = @success_ext_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = COALESCE(@success_ext_category_id, `category_id`),
  `content` = @success_ext_staff_content,
  `keywords` = '预订曲线,提前预订,OTB,Pickup,预测误差,库存价值,订单总价值,挤出成本,入住时长,最短入住,总收益,TRevPAR,附加收入,体验产品,携程,美团,PMS',
  `tags` = JSON_ARRAY(
    '收益管理',
    '预订曲线',
    '库存价值',
    '总收益',
    '体验产品',
    'manual_review_only'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @success_ext_unit_name;

