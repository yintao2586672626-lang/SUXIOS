-- Materialize a reviewed Ctrip help-center semantic contract.
-- The package stores OTA-channel definitions, version conflicts and protected
-- unknowns only. It never imports current-hotel facts or executes Ctrip writes.
--
-- Safe rerun contract:
-- - preserve operator-authored chunks and older seed versions;
-- - update only the exact current seed owner + key + version rows;
-- - correct one exact ambiguous legacy statement through a forward migration;
-- - never delete hotel data or broad knowledge ranges.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_sem_version := '2026-07-30.1';
SET @ctrip_sem_reviewed_at := '2026-07-30';
SET @ctrip_sem_seed_owner := 'suxios.ctrip_official_help_semantic_contract';
SET @ctrip_sem_unit_name := '携程点评与数据中心官方帮助语义合同';
SET @ctrip_sem_source := 'revenue_operations_decision_support';
SET @ctrip_sem_description := '将携程公开帮助课程、现行商家规则与用户提供的eBooking语言包转成版本化点评和数据中心语义合同；明确列表曝光UV、概览转化、APP漏斗、全平台范围及30/90天冲突，不包含当前酒店事实或任何平台写入权限。';

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
  @ctrip_sem_unit_name,
  @ctrip_sem_source,
  'done',
  @ctrip_sem_description,
  JSON_ARRAY(
    '携程',
    '点评',
    '数据中心',
    '指标口径',
    '版本冲突',
    '官方帮助',
    'structured_knowledge',
    'manual_review_only'
  ),
  0,
  'active',
  'reviewed_public_official_help_semantics_with_version_conflict_preserved',
  CONCAT(@ctrip_sem_reviewed_at, ' 00:00:00'),
  JSON_ARRAY(
    '携程自有点评达到40条时按自有点评计分，不足40条时融合第三方参考点评分形成综合点评分。',
    '点评计分范围为Ctrip与Trip订单产生的三年内有效点评，按新鲜度和可信度加权，精确权重未知。',
    '携程数据中心列表页曝光在已核验帮助文案中是去重浏览人数，不是默认的通用展示次数。',
    '概览转化、APP漏斗曝光转化、下单转化和成交转化具有不同分子、分母与渠道范围。',
    'APP漏斗订单提交人数是行为埋点，不等于订单管理有效订单或实际业绩。',
    '全平台订单、销售额和在店间夜可能包含携程、去哪儿和同程旅行，不能标成携程单渠道事实。'
  ),
  JSON_ARRAY(
    '当前账号异常点评自助反馈期限采用30天还是90天，必须以实际操作日页面提示为准。',
    '点评权重、可信度阈值、推荐排序、排名、竞争圈和PSI的精确算法。',
    '当前酒店目标日期的真实点评、回复、曝光、访客、订单、销售额和在店数据。',
    '不同酒店、账号、渠道和实验版本的实际字段权限与刷新时点。',
    '任何平台建议对当前酒店收益的真实因果效果。'
  ),
  @ctrip_sem_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units`
  WHERE `name` = @ctrip_sem_unit_name
    AND `source` = @ctrip_sem_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @ctrip_sem_description,
  `tags` = JSON_ARRAY(
    '携程',
    '点评',
    '数据中心',
    '指标口径',
    '版本冲突',
    '官方帮助',
    'structured_knowledge',
    'manual_review_only'
  ),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'reviewed_public_official_help_semantics_with_version_conflict_preserved',
  `reviewed_at` = CONCAT(@ctrip_sem_reviewed_at, ' 00:00:00'),
  `known_knowns` = JSON_ARRAY(
    '携程自有点评达到40条时按自有点评计分，不足40条时融合第三方参考点评分形成综合点评分。',
    '点评计分范围为Ctrip与Trip订单产生的三年内有效点评，按新鲜度和可信度加权，精确权重未知。',
    '携程数据中心列表页曝光在已核验帮助文案中是去重浏览人数，不是默认的通用展示次数。',
    '概览转化、APP漏斗曝光转化、下单转化和成交转化具有不同分子、分母与渠道范围。',
    'APP漏斗订单提交人数是行为埋点，不等于订单管理有效订单或实际业绩。',
    '全平台订单、销售额和在店间夜可能包含携程、去哪儿和同程旅行，不能标成携程单渠道事实。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '当前账号异常点评自助反馈期限采用30天还是90天，必须以实际操作日页面提示为准。',
    '点评权重、可信度阈值、推荐排序、排名、竞争圈和PSI的精确算法。',
    '当前酒店目标日期的真实点评、回复、曝光、访客、订单、销售额和在店数据。',
    '不同酒店、账号、渠道和实验版本的实际字段权限与刷新时点。',
    '任何平台建议对当前酒店收益的真实因果效果。'
  ),
  `truth_profile_version` = @ctrip_sem_version,
  `updated_at` = NOW()
WHERE `name` = @ctrip_sem_unit_name
  AND `source` = @ctrip_sem_source;

SET @ctrip_sem_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @ctrip_sem_unit_name
    AND `source` = @ctrip_sem_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_ctrip_official_semantic_chunks`;
CREATE TEMPORARY TABLE `tmp_ctrip_official_semantic_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_ctrip_sem_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'official_public_help_plus_user_provided_official_localization_snapshot',
    'source_refs', JSON_ARRAY(
      'ctrip_review_course_143_2025',
      'ctrip_datacenter_course_2562_2024',
      'ctrip_hotel_merchant_rules_2025_11',
      'ctrip_review_locale_user_snapshot',
      'ctrip_datacenter_locale_user_snapshot'
    ),
    'reviewed_at', @ctrip_sem_reviewed_at,
    'source_manifest', JSON_OBJECT(
      'ctrip_review_course_143_2025', JSON_OBJECT(
        'publisher', '携程酒店程长营',
        'course_id', 143,
        'published_at', '2025-10-21',
        'course_url', 'https://ebooking.ctrip.com/htlcommunity/detail?id=143',
        'pdf_url', 'https://ebooking.ctrip.com/htlcommunity/getpdf/6/smarthotel/0F44412000p6ouxvb2384.pdf',
        'pdf_sha256', '21c837c5fecfa1fc2171f9b9bc1b2d2cbd92bdfee07c0746e6e285748f1e0c29',
        'verified_pages', JSON_ARRAY(8, 9, 11, 12, 13, 15, 31, 39),
        'accessed_at', @ctrip_sem_reviewed_at
      ),
      'ctrip_datacenter_course_2562_2024', JSON_OBJECT(
        'publisher', '携程酒店程长营',
        'course_id', 2562,
        'published_at', '2024-09-03',
        'course_url', 'https://ebooking.ctrip.com/htlcommunity/detail?id=2562',
        'pdf_url', 'https://ebooking.ctrip.com/htlcommunity/getpdf/6/smarthotel/0F44t12000fdbbsi21708.pdf',
        'pdf_sha256', '6fa2df91b953b5c470978c5c9ca35ae913191675f57f4d7777b0df86d6357e41',
        'verified_pages', JSON_ARRAY(7, 8, 12, 14, 20, 21, 27, 28),
        'accessed_at', @ctrip_sem_reviewed_at
      ),
      'ctrip_hotel_merchant_rules_2025_11', JSON_OBJECT(
        'publisher', '携程',
        'published_at', '2025-11-03',
        'effective_at', '2025-11-10',
        'url', 'https://pages.ctrip.com/hotels/IBU/pages/hotelspecification.html',
        'used_for', 'merchant_truthfulness_fake_review_and_service_boundary',
        'accessed_at', @ctrip_sem_reviewed_at
      ),
      'ctrip_review_locale_user_snapshot', JSON_OBJECT(
        'filename', '点评资料.txt',
        'sha256', 'a143ca0d04b23fca68e0830ed484ee798865a063b20d9bd44b185f74ea26e079',
        'kind', 'user_provided_ctrip_ebooking_localization_bundle',
        'transfer_limit', 'static_asset_not_live_account_permission_or_current_hotel_fact'
      ),
      'ctrip_datacenter_locale_user_snapshot', JSON_OBJECT(
        'filename', '资料.txt',
        'sha256', '2408ad66886c2e80726df95e2b868423c39b4b742780a82aa0670ce715e1bf17',
        'kind', 'user_provided_ctrip_ebooking_localization_bundle',
        'transfer_limit', 'static_asset_not_live_account_permission_or current_hotel_fact'
      )
    ),
    'allowed_uses', JSON_ARRAY(
      'metric_definition',
      'field_mapping',
      'data_quality_validation',
      'operator_workflow_explanation'
    ),
    'blocked_uses', JSON_ARRAY(
      'current_hotel_fact_without_verified_capture',
      'whole_hotel_claim_from_ota_data',
      'automatic_ota_write',
      'private_algorithm_inference',
      'fixed_deadline_when_version_conflict_exists'
    )
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'ctrip_review_score_contract',
  JSON_OBJECT(
    'scope', 'ctrip_review_metric_semantics',
    'evidence_level', 'official_public_course_visual_text_verified',
    'source_refs', JSON_ARRAY('ctrip_review_course_143_2025'),
    'rules', JSON_ARRAY(
      '自有点评达到40条时仅按携程自有点评计分',
      '自有点评不足40条时融合携程点评分与第三方参考点评分形成综合点评分',
      '计分范围为Ctrip与Trip订单产生的三年内有效点评',
      '每条点评按新鲜度和可信度等因素加权，不是简单算术平均',
      '第三方点评内容可展示，但不会作为一条携程自有点评直接计入总分',
      '点评分每天计算一次，凌晨计算后统一更新'
    ),
    'metric_keys', JSON_OBJECT(
      'ctrip_own_review_count', 'Ctrip与Trip订单产生的携程自有点评数量',
      'ctrip_review_score', '自有点评达到40条时的携程自有点评加权得分',
      'ctrip_composite_review_score', '自有点评不足40条时融合第三方参考点评分形成的综合得分'
    ),
    'known_unknowns', JSON_ARRAY(
      '新鲜度和可信度权重',
      '用户经验值用户诚信度内容真实度的阈值',
      '推荐排序精确算法'
    ),
    'blocked_claims', JSON_ARRAY(
      'review_score_as_arithmetic_average',
      'third_party_review_text_as_direct_score_row',
      'review_reply_as_proven_booking_causality'
    )
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'ctrip_review_workflow_contract',
  JSON_OBJECT(
    'scope', 'ctrip_review_operator_workflow',
    'evidence_level', 'official_public_course_plus_localization_snapshot',
    'source_refs', JSON_ARRAY(
      'ctrip_review_course_143_2025',
      'ctrip_review_locale_user_snapshot'
    ),
    'submission_window', JSON_OBJECT(
      'main_review', '离店当天规定时间后至离店后90天内',
      'follow_up_review', '离店日至离店后90天内，具体起算时间按Ctrip与Trip页面',
      'cancelled_order', 'not_eligible'
    ),
    'moderation', JSON_OBJECT(
      'text_typical_duration', '1_to_2_business_days',
      'image_video_typical_duration', '1_to_2_business_days',
      'text_media_reviewed_separately', true
    ),
    'reply_contract', JSON_OBJECT(
      'review_reply_max_chinese_characters', 1000,
      'hotel_qa_reply_max_chinese_characters', 2000,
      'approved_reply_frontend_update', 'within_24_hours',
      'operator_recommended_completion', 'complete_by_priority_within_24_hours',
      'semantic_boundary', 'platform_display_latency_and_operator_response_target_are_different'
    ),
    'sorting', JSON_OBJECT(
      'default_or_recommended_factors', JSON_ARRAY(
        'stay_or_review_time',
        'text_content',
        'authenticity',
        'images',
        'video'
      ),
      'exact_algorithm', 'unknown'
    ),
    'blocked_claims', JSON_ARRAY(
      'platform_display_latency_as_operator_sla',
      'operator_target_as_platform_guarantee',
      'hotel_qa_limit_as_review_reply_limit'
    )
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'ctrip_review_feedback_version_conflict',
  JSON_OBJECT(
    'scope', 'version_conflict',
    'evidence_level', 'two_official_surface_versions_conflict_live_recheck_required',
    'source_refs', JSON_ARRAY(
      'ctrip_review_course_143_2025_page_39',
      'ctrip_review_locale_user_snapshot'
    ),
    'conflict_key', 'ctrip_abnormal_review_self_service_window_days',
    'versions', JSON_ARRAY(
      JSON_OBJECT(
        'value_days', 30,
        'source', '2025官方课程第39页',
        'rule', '超过30天或驳回关闭后需联系业务发起反馈'
      ),
      JSON_OBJECT(
        'value_days', 90,
        'source', '用户提供的较新eBooking中文语言包',
        'rule', '仅可对90天内新产生的点评自助反馈，超过90天联系业务或商服'
      )
    ),
    'decision_status', 'unresolved_until_live_help_verified',
    'operational_rule', '实际操作当天读取当前账号页面帮助提示',
    'fallback_output', '反馈窗口待核验',
    'known_common_rules', JSON_ARRAY(
      '最多同时反馈2条异常点评',
      '被关闭后再次反馈需联系业务',
      '应按分类提交清晰且证据充分的材料'
    ),
    'blocked_uses', JSON_ARRAY(
      'hardcoded_30_day_deadline',
      'hardcoded_90_day_deadline',
      'automatic_appeal_submission'
    )
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'ctrip_datacenter_overview_contract',
  JSON_OBJECT(
    'scope', 'ctrip_datacenter_metric_semantics',
    'evidence_level', 'official_public_course_cross_checked_with_localization_snapshot',
    'source_refs', JSON_ARRAY(
      'ctrip_datacenter_course_2562_2024_pages_7_27_28',
      'ctrip_datacenter_locale_user_snapshot'
    ),
    'metrics', JSON_ARRAY(
      JSON_OBJECT(
        'semantic_key', 'ctrip_datacenter_list_exposure_uv',
        'definition', '统计周期内酒店列表页的去重浏览人数',
        'unit', 'unique_users',
        'dedupe_scope', 'same_user_multiple_visits_count_once_within_source_module_scope',
        'blocked_aliases', JSON_ARRAY('generic_impression_count', 'advertising_impressions')
      ),
      JSON_OBJECT(
        'semantic_key', 'ctrip_datacenter_detail_visitor_uv',
        'definition', '统计周期内所选渠道酒店详情页的访问去重人数',
        'unit', 'unique_users',
        'all_channel_rule', 'APP_H5_web_and_mini_program_channel_UVs_are_summed_not_global_deduped'
      ),
      JSON_OBJECT(
        'semantic_key', 'ctrip_datacenter_overview_booking_conversion',
        'numerator', '预订订单量',
        'denominator', '详情页访客量',
        'excluded_orders', JSON_ARRAY(
          'cancelled_orders',
          'distribution_orders',
          'business_travel_flight_hotel_vacation_orders'
        ),
        'channel_scope', 'selected_APP_mini_program_web_H5_or_all',
        'unit', 'ratio'
      )
    ),
    'required_dimensions', JSON_ARRAY(
      'tenant_id',
      'system_hotel_id',
      'platform',
      'module',
      'semantic_key',
      'channel_scope',
      'business_date',
      'captured_at',
      'source_ref',
      'quality_status'
    ),
    'blocked_claims', JSON_ARRAY(
      'list_exposure_uv_as_raw_impressions',
      'all_channel_sum_as_cross_channel_unique_users',
      'overview_conversion_as_payment_conversion'
    )
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'ctrip_app_funnel_contract',
  JSON_OBJECT(
    'scope', 'ctrip_app_behavior_funnel',
    'evidence_level', 'official_public_course_visual_text_verified',
    'source_refs', JSON_ARRAY(
      'ctrip_datacenter_course_2562_2024_pages_8_27_28'
    ),
    'channel_scope', 'ctrip_app_only',
    'funnel', JSON_ARRAY(
      JSON_OBJECT(
        'semantic_key', 'ctrip_app_funnel_exposure_conversion',
        'numerator', '来自列表页的详情页访客',
        'denominator', 'APP列表页曝光用户'
      ),
      JSON_OBJECT(
        'semantic_key', 'ctrip_app_funnel_order_page_conversion',
        'numerator', '订单页访客',
        'denominator', '详情页访客'
      ),
      JSON_OBJECT(
        'semantic_key', 'ctrip_app_funnel_submit_conversion',
        'numerator', '订单提交人数',
        'denominator', '订单页访客'
      )
    ),
    'order_submit_semantics', JSON_OBJECT(
      'source', 'page_behavior_event',
      'not_equal_to_order_management_orders', true,
      'may_include_payment_failed_or_cancelled', true,
      'performance_reporting_allowed', false
    ),
    'dedupe_warning', '同一用户可同时来自列表页和非列表页，两来源相加可能大于详情页去重访客',
    'blocked_aliases', JSON_ARRAY(
      'conversion_rate',
      'flow_rate_without_semantic_key',
      'actual_order_conversion',
      'payment_conversion',
      'hotel_performance'
    )
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'ctrip_platform_scope_contract',
  JSON_OBJECT(
    'scope', 'ctrip_datacenter_platform_scope',
    'evidence_level', 'official_public_course_cross_checked_with_localization_snapshot',
    'source_refs', JSON_ARRAY(
      'ctrip_datacenter_course_2562_2024_pages_12_14_20_21',
      'ctrip_datacenter_locale_user_snapshot'
    ),
    'scope_rules', JSON_ARRAY(
      JSON_OBJECT(
        'scope_key', 'ctrip_group_full_platform',
        'may_include', JSON_ARRAY('ctrip', 'qunar', 'tongcheng_travel'),
        'applies_to', JSON_ARRAY('booked_orders', 'booked_sales_amount', 'stayed_room_nights'),
        'rule', '保存页面实际筛选值，不能重命名为ctrip_only'
      ),
      JSON_OBJECT(
        'scope_key', 'ctrip_app_traffic',
        'includes', JSON_ARRAY('ctrip_app_behavior'),
        'applies_to', JSON_ARRAY('visitor_uv', 'app_funnel', 'app_conversion')
      )
    ),
    'refresh_rule', '实时、昨日、竞争圈和历史报表具有各自刷新时点，不设置全局固定刷新SLA',
    'required_fields', JSON_ARRAY(
      'platform_scope',
      'channel_scope',
      'metric_semantic_key',
      'business_date',
      'data_freshness_status',
      'source_tooltip_version'
    ),
    'blocked_claims', JSON_ARRAY(
      'full_platform_as_ctrip_only',
      'single_module_refresh_time_as_global_sla',
      'platform_diagnostic_score_as_ranking_factor_without_direct_evidence'
    )
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'ctrip_semantic_mapping_guardrail',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_official_semantic_override',
    'source_refs', JSON_ARRAY(
      'ctrip_datacenter_course_2562_2024',
      'ctrip_datacenter_locale_user_snapshot',
      'docs/ctrip_official_help_semantic_contract_knowledge.md'
    ),
    'mapping_rules', JSON_ARRAY(
      '无semantic_key的exposure不得自动解释为PV或UV',
      '无semantic_key的conversion_rate或flow_rate不得用于诊断',
      '当前携程数据中心列表页曝光映射为ctrip_datacenter_list_exposure_uv',
      '概览转化和APP漏斗三个转化必须分别存储',
      'APP订单提交人数不得写入实际订单或业绩字段',
      '全平台与携程单渠道必须分开'
    ),
    'unknown_state', 'unverified_semantics',
    'required_for_decision_ready', JSON_ARRAY(
      'semantic_key',
      'numerator',
      'denominator',
      'unit',
      'platform_scope',
      'channel_scope',
      'business_date',
      'source_ref',
      'quality_status'
    )
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @ctrip_sem_unit_id,
  'landing_status',
  JSON_OBJECT(
    'scope', 'knowledge_landing_status',
    'evidence_level', 'repository_database_forward_migration_contract',
    'source_refs', JSON_ARRAY(
      'docs/ctrip_official_help_semantic_contract_knowledge.md',
      'database/migrations/20260730_write_ctrip_official_help_semantic_contract.sql',
      'tests/CtripOfficialHelpSemanticContractKnowledgeTest.php'
    ),
    'status', 'ready_for_retrieval',
    'knowledge_types', JSON_ARRAY(
      'ctrip_review_score_contract',
      'ctrip_review_workflow_contract',
      'ctrip_review_feedback_version_conflict',
      'ctrip_datacenter_overview_contract',
      'ctrip_app_funnel_contract',
      'ctrip_platform_scope_contract',
      'ctrip_semantic_mapping_guardrail'
    ),
    'runtime_execution', 'no_external_action',
    'current_hotel_data', 'not_included',
    'truthful_completion_statement', 'official_help_semantics_landed_with_conflict_and_unknowns_preserved'
  ),
  0,
  NOW()
WHERE @ctrip_sem_unit_id IS NOT NULL;

UPDATE `tmp_ctrip_official_semantic_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.module_id', 'ctrip_official_help_semantic_contract',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'operations'),
  '$.scenes', JSON_ARRAY(
    'morning_meeting',
    'weekly_review',
    'data_quality_review',
    'review_management'
  ),
  '$.platforms', JSON_ARRAY('ctrip'),
  '$.seed_owner', @ctrip_sem_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `unit`.`name`, ':', `seed`.`type`),
  '$.seed_version', @ctrip_sem_version,
  '$.lifecycle_status', 'active'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_ctrip_official_semantic_chunks` AS `seed`
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
FROM `tmp_ctrip_official_semantic_chunks` AS `seed`
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

DROP TEMPORARY TABLE `tmp_ctrip_official_semantic_chunks`;

-- Remove the inaccurate Ctrip implication from one exact legacy metric row.
-- Generic impression_count remains valid only where the source truly reports
-- display events; Ctrip DataCenter list exposure now points to its reviewed UV
-- semantic key.
UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.rows[0].note', '通用impression_count只适用于来源明确的展示次数；携程数据中心当前已核验的“列表页曝光”是去重浏览人数，必须映射为ctrip_datacenter_list_exposure_uv。',
  '$.official_semantic_override', JSON_OBJECT(
    'status', 'legacy_ctrip_exposure_assumption_replaced',
    'reviewed_at', @ctrip_sem_reviewed_at,
    'replacement_unit', @ctrip_sem_unit_name,
    'semantic_key', 'ctrip_datacenter_list_exposure_uv',
    'source_refs', JSON_ARRAY(
      'ctrip_datacenter_course_2562_2024',
      'ctrip_datacenter_locale_user_snapshot'
    )
  )
)
WHERE `ku`.`name` = 'OTA标准指标与推荐公式清单'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '流量漏斗指标';

-- Keep the historical workbook formula, but make its Ctrip field semantics
-- explicit so a field name cannot silently stand in for a platform definition.
UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.official_semantic_override', JSON_OBJECT(
    'status', 'historical_formula_bound_to_reviewed_ctrip_semantics',
    'reviewed_at', @ctrip_sem_reviewed_at,
    'hotel_list_exposure_semantic_key', 'ctrip_datacenter_list_exposure_uv',
    'hotel_detail_visitor_semantic_key', 'ctrip_datacenter_detail_visitor_uv',
    'generic_conversion_rate_blocked', true,
    'replacement_unit', @ctrip_sem_unit_name
  )
)
WHERE `ku`.`name` = 'OTA每日经营台账与晨报闭环'
  AND `ku`.`source` = 'ota_daily_operations_ledger_reference'
  AND `kc`.`type` = 'ctrip_funnel';

SET @ctrip_sem_staff_content := CONCAT(
  '# 携程点评与数据中心官方帮助语义合同', '\n\n',
  '## 已知的已知', '\n',
  '列表页曝光按当前已核验帮助文案是去重浏览人数；概览转化、APP漏斗曝光转化、下单转化和成交转化必须分开；APP订单提交人数不是实际订单；全平台数据可能包含携程、去哪儿和同程旅行。', '\n\n',
  '## 点评规则', '\n',
  '自有点评达到40条与不足40条采用不同计分范围；三年内有效点评按新鲜度和可信度加权。回复和问答分别使用1000与2000字限制。', '\n\n',
  '## 版本冲突', '\n',
  '异常点评自助反馈期限存在30天与90天两版官方证据，执行前读取当前EBK页面提示，未核验时只显示“反馈窗口待核验”。', '\n\n',
  '## 已知的未知', '\n',
  '点评算法权重、排名和竞争圈算法、账号字段权限、刷新时点，以及当前酒店目标日期的真实指标仍未知。', '\n\n',
  '## 保护边界', '\n',
  '本知识只用于携程OTA指标解释和数据质量检查，不替代当前酒店事实，不扩大为全酒店结论，不授权任何价格、库存、活动、回复或申诉写入。'
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
  @ctrip_sem_unit_name,
  @ctrip_sem_staff_content,
  '携程,点评,数据中心,曝光UV,访客UV,概览转化,APP漏斗,订单提交,全平台,去哪儿,同程,30天,90天,版本冲突',
  JSON_ARRAY(
    '携程',
    '点评',
    '数据中心',
    '指标口径',
    '版本冲突',
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
    AND `title` = @ctrip_sem_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = 7,
  `content` = @ctrip_sem_staff_content,
  `keywords` = '携程,点评,数据中心,曝光UV,访客UV,概览转化,APP漏斗,订单提交,全平台,去哪儿,同程,30天,90天,版本冲突',
  `tags` = JSON_ARRAY(
    '携程',
    '点评',
    '数据中心',
    '指标口径',
    '版本冲突',
    'manual_review_only'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @ctrip_sem_unit_name;
