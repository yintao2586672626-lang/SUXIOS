-- Materialize a reviewed Meituan hotel rule and product semantic contract.
-- The package contains public official OTA-channel semantics, versioned rules,
-- legacy conflicts and protected unknowns. It never imports current-hotel data
-- and never executes Meituan/PMS writes.
--
-- Safe rerun contract:
-- - preserve operator-authored chunks and older seed versions;
-- - update only this exact seed owner + key + version;
-- - correct exact legacy knowledge paths through a forward migration;
-- - never delete hotel facts or broad knowledge ranges.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @meituan_sem_version := '2026-07-30.1';
SET @meituan_sem_reviewed_at := '2026-07-30';
SET @meituan_sem_seed_owner := 'suxios.meituan_official_rules_semantic_contract';
SET @meituan_sem_unit_name := '美团酒店评价与经营规则官方语义合同';
SET @meituan_sem_source := 'revenue_operations_decision_support';
SET @meituan_sem_description := '将美团2025评价总则/细则和酒店投诉指引、2024酒店诚信细则、2023服务与经营细则、当前HMS公开能力及旧直连FAQ冲突转成版本化语义合同；拆分美团与大众点评，撤销利益换评、通用半年申诉、旧HOS固定算法和拒单量/创建订单量等旧口径，不包含当前酒店事实或任何外部写权限。';

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
  @meituan_sem_unit_name,
  @meituan_sem_source,
  'done',
  @meituan_sem_description,
  JSON_ARRAY(
    '美团',
    '酒店',
    '评价',
    '投诉',
    '诚信履约',
    '价格规则',
    'HMS',
    '版本冲突',
    'structured_knowledge',
    'manual_review_only'
  ),
  0,
  'active',
  'reviewed_public_official_rules_with_legacy_conflicts_quarantined',
  CONCAT(@meituan_sem_reviewed_at, ' 00:00:00'),
  JSON_ARRAY(
    '2025版美团评价总则明确排除大众点评，美团与大众点评必须拆分。',
    '美团星级和评分是动态加权指标，候选因素包括用户专业度、评价质量、评价时间、诚信度和评价数量，精确权重未知。',
    '利益换评、商户或关联方写评、代替用户写评、模板诱导和使用AIGC虚构评价均属于禁止或处理范围。',
    '酒店违规评价指引列出八类场景，每条评价限申诉1次，页面声明3个工作日内处理。',
    '2024酒店诚信细则要求门店、地址、房型、设施、政策与实际一致，并禁止虚假交易、虚假评价、逃单和提前点入住。',
    '2023服务细则将拒单率定义为拒单订单量/支付订单量、推翻率定义为推翻订单量/支付订单量，但只能绑定该版规则。',
    '当前HMS官网公开PMS、渠道、财务、日审、收益早报、同行动态和智能定价等产品能力，但不证明当前门店效果。'
  ),
  JSON_ARRAY(
    '当前酒店点评开放和截止窗口、追加评价及酒店品类展示规则。',
    '当前星级、评分、HOS、排名、冠级、同行和流量算法的精确公式、周期、权重与样本。',
    '当前数据中心曝光、访客、转化、取消、收入、税费、刷新延迟和回补口径。',
    '当前账户的评价投诉入口、权限、受理状态和最终结果。',
    '当前服务/经营规则的公式、阈值、价差容忍、处罚和申诉窗口。',
    '当前HMS租户模块、业务日、夜审设置、同步/对账准确率及经营结果。'
  ),
  @meituan_sem_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units`
  WHERE `name` = @meituan_sem_unit_name
    AND `source` = @meituan_sem_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @meituan_sem_description,
  `tags` = JSON_ARRAY(
    '美团',
    '酒店',
    '评价',
    '投诉',
    '诚信履约',
    '价格规则',
    'HMS',
    '版本冲突',
    'structured_knowledge',
    'manual_review_only'
  ),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'reviewed_public_official_rules_with_legacy_conflicts_quarantined',
  `reviewed_at` = CONCAT(@meituan_sem_reviewed_at, ' 00:00:00'),
  `known_knowns` = JSON_ARRAY(
    '2025版美团评价总则明确排除大众点评，美团与大众点评必须拆分。',
    '美团星级和评分是动态加权指标，候选因素包括用户专业度、评价质量、评价时间、诚信度和评价数量，精确权重未知。',
    '利益换评、商户或关联方写评、代替用户写评、模板诱导和使用AIGC虚构评价均属于禁止或处理范围。',
    '酒店违规评价指引列出八类场景，每条评价限申诉1次，页面声明3个工作日内处理。',
    '2024酒店诚信细则要求门店、地址、房型、设施、政策与实际一致，并禁止虚假交易、虚假评价、逃单和提前点入住。',
    '2023服务细则将拒单率定义为拒单订单量/支付订单量、推翻率定义为推翻订单量/支付订单量，但只能绑定该版规则。',
    '当前HMS官网公开PMS、渠道、财务、日审、收益早报、同行动态和智能定价等产品能力，但不证明当前门店效果。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '当前酒店点评开放和截止窗口、追加评价及酒店品类展示规则。',
    '当前星级、评分、HOS、排名、冠级、同行和流量算法的精确公式、周期、权重与样本。',
    '当前数据中心曝光、访客、转化、取消、收入、税费、刷新延迟和回补口径。',
    '当前账户的评价投诉入口、权限、受理状态和最终结果。',
    '当前服务/经营规则的公式、阈值、价差容忍、处罚和申诉窗口。',
    '当前HMS租户模块、业务日、夜审设置、同步/对账准确率及经营结果。'
  ),
  `truth_profile_version` = @meituan_sem_version,
  `updated_at` = NOW()
WHERE `name` = @meituan_sem_unit_name
  AND `source` = @meituan_sem_source;

SET @meituan_sem_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @meituan_sem_unit_name
    AND `source` = @meituan_sem_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_meituan_official_semantic_chunks`;
CREATE TEMPORARY TABLE `tmp_meituan_official_semantic_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_meituan_sem_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'source_boundary',
  JSON_OBJECT(
    'scope', 'generic_methodology',
    'evidence_level', 'reviewed_public_official_rules_and_product_surfaces',
    'source_refs', JSON_ARRAY(
      'meituan_review_general_v4_2025',
      'meituan_review_detail_v5_2025',
      'meituan_review_complaint_guide_2025',
      'meituan_hotel_integrity_rule_2024',
      'meituan_hotel_service_rule_2023',
      'meituan_hotel_operating_rule_2023',
      'meituan_hms_current_public_product',
      'meituan_hotel_direct_connect_faq_legacy'
    ),
    'reviewed_at', @meituan_sem_reviewed_at,
    'source_manifest', JSON_OBJECT(
      'meituan_review_general_v4_2025', JSON_OBJECT(
        'publisher', '美团规则中心',
        'title', '美团评价规则（总则）',
        'version', '4.0',
        'published_at', '2025-10-31',
        'effective_at', '2025-10-31',
        'url', 'https://rules-center.meituan.com/m/detail/guize/6?activeRule=1&commonType=20',
        'source_status', 'official_current_rule'
      ),
      'meituan_review_detail_v5_2025', JSON_OBJECT(
        'publisher', '美团规则中心',
        'title', '美团评价规则（细则）',
        'version', 'V5.0',
        'published_at', '2025-10-31',
        'url', 'https://rules-center.meituan.com/m/detail/guize/185?activeRule=1',
        'source_status', 'official_current_rule'
      ),
      'meituan_review_complaint_guide_2025', JSON_OBJECT(
        'publisher', '美团规则中心',
        'title', '美团到店商家违规评价典型场景及投诉步骤说明',
        'published_at', '2025-10-31',
        'url', 'https://rules-center.meituan.com/m/detail/guize/1364?activeRule=1',
        'source_status', 'official_current_rule'
      ),
      'meituan_hotel_integrity_rule_2024', JSON_OBJECT(
        'publisher', '美团酒店',
        'title', '美团酒店商家诚信类违规细则',
        'revised_at', '2024-08-15',
        'effective_at', '2024-08-23',
        'url', 'https://ecube.meituan.com/awp/hfe/block/7ef9eb6ccbc1/295782/index.html',
        'source_status', 'official_versioned_rule'
      ),
      'meituan_hotel_service_rule_2023', JSON_OBJECT(
        'publisher', '美团酒店',
        'title', '美团酒店商家服务类违规细则',
        'revised_at', '2023-03-10',
        'effective_at', '2023-04-10',
        'url', 'https://ecube.meituan.com/awp/hfe/block/cfca90fadd1a/295833/index.html',
        'source_status', 'official_versioned_rule'
      ),
      'meituan_hotel_operating_rule_2023', JSON_OBJECT(
        'publisher', '美团酒店',
        'title', '美团酒店商家经营类违规细则',
        'effective_at', '2023-04-10',
        'url', 'https://ecube.meituan.com/awp/hfe/block/55cf5ff3a859/295828/index.html',
        'source_status', 'official_versioned_rule'
      ),
      'meituan_hms_current_public_product', JSON_OBJECT(
        'publisher', '美团酒店管理系统',
        'url', 'https://hms.meituan.com/home/products-solution/digitize',
        'content_version', 'undated_current_public_surface',
        'source_status', 'official_current_product_claim'
      ),
      'meituan_hotel_direct_connect_faq_legacy', JSON_OBJECT(
        'publisher', '美团酒店直连平台',
        'url', 'https://openplatform-hotel.meituan.com/portal/faq',
        'page_footer', '©2016 美团酒店',
        'source_status', 'quarantined_legacy_conflict',
        'conflicts', JSON_ARRAY('maximum_stay_180_nights', 'maximum_stay_29_nights')
      )
    ),
    'allowed_uses', JSON_ARRAY(
      'rule_explanation',
      'metric_semantic_boundary',
      'data_quality_validation',
      'operator_evidence_checklist',
      'version_conflict_detection'
    ),
    'blocked_uses', JSON_ARRAY(
      'current_hotel_fact_without_verified_capture',
      'dianping_rule_inference_from_meituan',
      'whole_hotel_claim_from_ota_data',
      'automatic_meituan_or_pms_write',
      'private_algorithm_inference',
      'legacy_limit_as_current_fact'
    )
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_review_scope_rating_contract',
  JSON_OBJECT(
    'scope', 'meituan_review_metric_semantics',
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY('meituan_review_general_v4_2025'),
    'platform_scope', 'meituan',
    'explicitly_excluded_platforms', JSON_ARRAY('dianping'),
    'rating_contract', JSON_OBJECT(
      'dynamic_metric', true,
      'candidate_factors', JSON_ARRAY(
        'reviewer_expertise',
        'review_quality',
        'review_time',
        'review_integrity',
        'review_count'
      ),
      'cheating_data_removed', true,
      'exact_weights', 'unknown_private_algorithm',
      'hotel_category_formula', 'unknown',
      'simple_arithmetic_average_allowed', false,
      'platform_may_adjust_logic', true
    ),
    'semantic_keys', JSON_OBJECT(
      'meituan_review_star_display_value', '当前页面或后台展示的美团商户星级原值',
      'meituan_review_score_display_value', '当前页面或后台展示的美团评分原值',
      'meituan_review_weight_model', 'unknown_private_algorithm',
      'meituan_review_integrity_filter_status', '平台返回的不展示折叠或不计分状态'
    ),
    'required_fields', JSON_ARRAY(
      'system_hotel_id',
      'platform_hotel_id',
      'platform',
      'captured_at',
      'source_url',
      'source_version',
      'raw_scale',
      'quality_status'
    ),
    'blocked_aliases', JSON_ARRAY(
      'meituan_dianping_combined_score',
      'simple_average_score',
      'fixed_review_weight',
      'review_score_as_ranking_causality'
    )
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_review_integrity_contract',
  JSON_OBJECT(
    'scope', 'meituan_review_content_integrity',
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY(
      'meituan_review_general_v4_2025',
      'meituan_review_detail_v5_2025'
    ),
    'prohibited_patterns', JSON_ARRAY(
      'merchant_or_related_party_review',
      'cash_gift_discount_lottery_or_commission_for_review',
      'merchant_writes_or_edits_review_for_user',
      'merchant_operates_user_device_or_account',
      'merchant_provided_template_or_fixed_content',
      'review_requested_before_experience_completed',
      'merchant_checks_user_review_result',
      'aigc_fabricated_untrue_review_content_or_image',
      'fake_account_or_fake_transaction_review',
      'competitor_attack_or_extortion_review'
    ),
    'platform_actions_may_include', JSON_ARRAY(
      'not_displayed',
      'folded_display',
      'excluded_from_star_calculation',
      'deleted_or_blocked',
      'reviewer_restriction'
    ),
    'suxios_allowed', JSON_ARRAY(
      'summarize_verified_service_facts',
      'draft_merchant_reply_for_human_review',
      'prepare_truthful_complaint_evidence_checklist'
    ),
    'suxios_blocked', JSON_ARRAY(
      'generate_guest_experience_as_if_real',
      'automatic_user_review',
      'incentivized_review_campaign',
      'fabricated_evidence',
      'automatic_complaint_submission'
    )
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_hotel_review_complaint_contract',
  JSON_OBJECT(
    'scope', 'meituan_hotel_review_operator_workflow',
    'evidence_level', 'official_current_rule',
    'source_refs', JSON_ARRAY('meituan_review_complaint_guide_2025'),
    'complaint_scenarios', JSON_ARRAY(
      'competitor_review',
      'employee_review',
      'review_extortion',
      'unreasonable_demand_retaliation',
      'abuse_personal_attack_or_illegal_content',
      'privacy_disclosure',
      'wrong_store',
      'content_clearly_inconsistent_with_facts'
    ),
    'hotel_app_path', JSON_ARRAY(
      '美团酒店商家端APP',
      '选择门店',
      '我的评价',
      '选择评价',
      '投诉'
    ),
    'hotel_pc_path', JSON_ARRAY(
      '评价管理',
      '选择门店',
      '选择评价',
      '头像下方举报',
      '按提示举证'
    ),
    'complaint_limit_per_review', 1,
    'stated_processing_sla', '3个工作日内',
    'sla_is_success_guarantee', false,
    'subjective_experience_auto_removal_allowed', false,
    'account_specific_entry_permission', 'unknown_until_current_account_verified',
    'required_evidence_properties', JSON_ARRAY(
      'truthful',
      'complete_context',
      'event_relevant',
      'reviewer_identity_link_when_required',
      'no_fabrication'
    ),
    'execution_mode', 'manual_review_and_manual_submit_only'
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_hotel_integrity_rule_contract',
  JSON_OBJECT(
    'scope', 'meituan_hotel_merchant_integrity',
    'evidence_level', 'official_versioned_rule',
    'source_refs', JSON_ARRAY('meituan_hotel_integrity_rule_2024'),
    'rule_version', 'effective_2024-08-23',
    'truthful_information_fields', JSON_ARRAY(
      'merchant_qualification',
      'store_identity',
      'hotel_name',
      'address',
      'star_or_grade',
      'images',
      'room_type_existence',
      'room_area',
      'bed_type',
      'window',
      'private_bathroom',
      'floor',
      'maximum_guests',
      'view',
      'facilities',
      'breakfast_policy',
      'checkin_checkout_policy',
      'foreign_guest_policy',
      'children_policy',
      'pet_policy',
      'pickup_or_transfer_service'
    ),
    'prohibited_behaviors', JSON_ARRAY(
      'fake_store',
      'duplicate_store',
      'fake_transaction',
      'fake_review',
      'traffic_or_order_diversion',
      'early_checkin_confirmation_before_actual_arrival'
    ),
    'decision_boundary', 'rule_definitions_do_not_prove_current_hotel_violation',
    'current_penalty_thresholds', 'unknown'
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_hotel_service_rule_2023_contract',
  JSON_OBJECT(
    'scope', 'meituan_hotel_service_rule_versioned',
    'evidence_level', 'official_versioned_rule_not_assumed_current',
    'source_refs', JSON_ARRAY('meituan_hotel_service_rule_2023'),
    'rule_version', 'effective_2023-04-10',
    'definitions', JSON_OBJECT(
      'rejection', '订单提交后、美团未确认预订成功前，商家拒绝确认、诱导取消或超时不确认',
      'overturn', '商家确认预订成功后推翻预订、诱导取消或不按订单约定提供设施与服务',
      'invoice_refusal', '未按法律、平台规则或约定向美团或已住店用户开具符合要求的发票'
    ),
    'versioned_metrics', JSON_ARRAY(
      JSON_OBJECT(
        'semantic_key', 'meituan_hotel_rejection_rate_rule_2023',
        'numerator', '拒单订单量',
        'denominator', '支付订单量',
        'formula', 'rejected_orders / paid_orders',
        'current_formula_status', 'unverified'
      ),
      JSON_OBJECT(
        'semantic_key', 'meituan_hotel_overturn_rate_rule_2023',
        'numerator', '推翻订单量',
        'denominator', '支付订单量',
        'formula', 'overturned_orders / paid_orders',
        'current_formula_status', 'unverified'
      )
    ),
    'current_window_threshold_penalty', 'unknown',
    'blocked_claims', JSON_ARRAY(
      'rejected_orders_divided_by_created_orders_as_meituan_official',
      '2023_formula_as_evergreen_current_formula',
      'service_rule_event_as_current_hotel_fact'
    )
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_hotel_operating_rule_2023_contract',
  JSON_OBJECT(
    'scope', 'meituan_hotel_operating_rule_versioned',
    'evidence_level', 'official_versioned_rule_not_assumed_current',
    'source_refs', JSON_ARRAY('meituan_hotel_operating_rule_2023'),
    'rule_version', 'effective_2023-04-10',
    'risk_definitions', JSON_ARRAY(
      JSON_OBJECT(
        'semantic_key', 'meituan_unagreed_extra_charge_risk',
        'meaning', '未经用户同意额外收取与美团预订服务无关的费用'
      ),
      JSON_OBJECT(
        'semantic_key', 'meituan_price_abnormality_rule_2023',
        'meaning', '美团同类房型价格较市场平均、其他渠道或自身历史同条件最高交易价出现大幅异常'
      ),
      JSON_OBJECT(
        'semantic_key', 'meituan_front_desk_price_inversion_rule_2023',
        'meaning', '前台同房型报价或促销价低于美团同房型价格'
      )
    ),
    'numeric_tolerance', 'unknown',
    'comparison_requirements', JSON_ARRAY(
      'same_room_type',
      'same_benefits',
      'same_cancellation_policy',
      'same_tax_and_fee_scope',
      'same_stay_date_and_observation_time'
    ),
    'automatic_price_write_allowed', false
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_hms_product_capability_contract',
  JSON_OBJECT(
    'scope', 'pms_public_product_capability',
    'evidence_level', 'official_current_product_claim_not_outcome_evidence',
    'source_refs', JSON_ARRAY('meituan_hms_current_public_product'),
    'advertised_capabilities', JSON_ARRAY(
      'front_desk',
      'housekeeping',
      'finance',
      'food_and_beverage',
      'asset_management',
      'daily_audit',
      'group_rate_management',
      'channel_management',
      'commission_strategy',
      'cash_and_accounts_receivable',
      'settlement_status',
      'meituan_and_fliggy_channel_connection',
      'order_inventory_price_status_management',
      'revenue_morning_report',
      'peer_dynamics',
      'rms',
      'intelligent_pricing',
      'smart_lock_and_room_control_integration'
    ),
    'unknowns', JSON_ARRAY(
      'tenant_enabled_modules',
      'field_definitions',
      'business_day',
      'night_audit_time',
      'tax_scope',
      'refresh_delay',
      'backfill_policy',
      'sync_success_rate',
      'missing_order_rate',
      'reconciliation_accuracy',
      'peer_sample_and_algorithm',
      'hos_or_ranking_relationship',
      'causal_revenue_or_profit_lift'
    ),
    'marketing_claim_as_current_hotel_fact', false,
    'required_for_operational_use', JSON_ARRAY(
      'tenant',
      'hotel',
      'module',
      'field',
      'business_date',
      'source_identity',
      'save_readback',
      'quality_status'
    )
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_legacy_direct_connect_conflict',
  JSON_OBJECT(
    'scope', 'version_conflict',
    'evidence_level', 'official_legacy_page_internal_conflict',
    'source_refs', JSON_ARRAY('meituan_hotel_direct_connect_faq_legacy'),
    'source_lifecycle', 'quarantined',
    'conflicts', JSON_ARRAY(
      JSON_OBJECT(
        'claim', 'maximum_length_of_stay',
        'value_a', '180 room nights',
        'value_b', '29 room nights',
        'decision_status', 'unresolved_do_not_use'
      ),
      JSON_OBJECT(
        'claim', 'page_currency',
        'evidence', JSON_ARRAY(
          '©2016 footer',
          'future-tense month references',
          'undated current version'
        ),
        'decision_status', 'legacy_not_current_baseline'
      )
    ),
    'historical_concepts_only', JSON_ARRAY(
      'poi',
      'room_type',
      'product',
      'inventory_and_rate_sync',
      'asynchronous_queue_requires_readback'
    ),
    'blocked_current_claims', JSON_ARRAY(
      'api_limit',
      'stay_limit',
      'endpoint',
      'permission',
      'sync_frequency',
      'status_transition',
      'settlement_rule'
    ),
    'replacement_evidence_required', JSON_ARRAY(
      'current_api_documentation',
      'current_contract',
      'current_logged_in_help',
      'current_response_and_readback'
    )
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'meituan_public_metric_unknowns',
  JSON_OBJECT(
    'scope', 'known_unknowns',
    'evidence_level', 'public_official_sources_reviewed_no_current_definition_found',
    'source_refs', JSON_ARRAY(
      'meituan_review_general_v4_2025',
      'meituan_review_detail_v5_2025',
      'meituan_hms_current_public_product'
    ),
    'unknown_metric_families', JSON_ARRAY(
      'hotel_review_open_and_deadline_window',
      'review_append_and_display_rules',
      'review_weight_formula',
      'hos_formula_and_window',
      'ranking_and_crown_algorithm',
      'exposure_pv_uv_definition',
      'detail_visitor_definition',
      'order_payment_cancel_conversion_formula',
      'gross_net_revenue_and_tax_scope',
      'data_delay_and_backfill',
      'peer_set_sample_and_algorithm',
      'current_account_permissions',
      'current_rule_thresholds_and_penalties',
      'current_hotel_facts'
    ),
    'missing_value_policy', 'preserve_unknown_or_unverified_never_zero_or_legacy_default',
    'closure_sources', JSON_ARRAY(
      'current_public_rule',
      'authorized_current_help_tooltip',
      'authorized_current_api_response',
      'current_contract',
      'manual_confirmation_with_date_and_scope'
    )
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_official_semantic_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @meituan_sem_unit_id,
  'landing_status',
  JSON_OBJECT(
    'scope', 'knowledge_landing_status',
    'evidence_level', 'repository_database_forward_migration_contract',
    'source_refs', JSON_ARRAY(
      'docs/meituan_official_rules_semantic_contract_knowledge.md',
      'database/migrations/20260730_x_write_meituan_official_rules_semantic_contract.sql',
      'tests/MeituanOfficialRulesSemanticContractKnowledgeTest.php'
    ),
    'status', 'ready_for_retrieval',
    'knowledge_types', JSON_ARRAY(
      'meituan_review_scope_rating_contract',
      'meituan_review_integrity_contract',
      'meituan_hotel_review_complaint_contract',
      'meituan_hotel_integrity_rule_contract',
      'meituan_hotel_service_rule_2023_contract',
      'meituan_hotel_operating_rule_2023_contract',
      'meituan_hms_product_capability_contract',
      'meituan_legacy_direct_connect_conflict',
      'meituan_public_metric_unknowns'
    ),
    'runtime_execution', 'no_external_action',
    'current_hotel_data', 'not_included',
    'truthful_completion_statement', 'public_official_semantics_landed_with_legacy_conflicts_and_unknowns_preserved'
  ),
  0,
  NOW()
WHERE @meituan_sem_unit_id IS NOT NULL;

UPDATE `tmp_meituan_official_semantic_chunks` AS `seed`
INNER JOIN `knowledge_units` AS `unit`
  ON `unit`.`unit_id` = `seed`.`unit_id`
SET `seed`.`content` = JSON_SET(
  COALESCE(`seed`.`content`, JSON_OBJECT()),
  '$.module_id', 'meituan_official_rules_semantic_contract',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'operations'),
  '$.scenes', JSON_ARRAY(
    'morning_meeting',
    'weekly_review',
    'data_quality_review',
    'review_management',
    'merchant_compliance_review'
  ),
  '$.platforms', JSON_ARRAY('meituan'),
  '$.seed_owner', @meituan_sem_seed_owner,
  '$.seed_key', CONCAT(`unit`.`source`, ':', `unit`.`name`, ':', `seed`.`type`),
  '$.seed_version', @meituan_sem_version,
  '$.lifecycle_status', 'active'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_meituan_official_semantic_chunks` AS `seed`
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
FROM `tmp_meituan_official_semantic_chunks` AS `seed`
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

DROP TEMPORARY TABLE `tmp_meituan_official_semantic_chunks`;

-- Correct one exact generic metric row. The 2023 Meituan hotel service rule
-- uses paid orders, not created orders, and must remain bound to its version.
UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.rows[4].metric', '美团酒店服务规则拒单率（2023版）',
  '$.rows[4].meaning', '订单提交后、美团确认预订成功前，商家拒绝确认、诱导取消或超时不确认的版本化比例。',
  '$.rows[4].formula_or_basis', '2023版美团酒店服务细则：拒单订单量 / 支付订单量。',
  '$.rows[4].suxios_use', '只作2023-04-10生效规则的履约风险口径；当前公式、窗口、阈值和处罚必须复核。',
  '$.rows[4].source_status', 'official_versioned_rule_not_assumed_current',
  '$.rows[4].semantic_key', 'meituan_hotel_rejection_rate_rule_2023',
  '$.official_semantic_override', JSON_OBJECT(
    'status', 'legacy_meituan_rejection_denominator_replaced',
    'reviewed_at', @meituan_sem_reviewed_at,
    'invalidated_formula', 'rejected_orders / created_orders',
    'versioned_formula', 'rejected_orders / paid_orders',
    'rule_version', 'effective_2023-04-10',
    'current_formula_status', 'unverified',
    'replacement_unit', @meituan_sem_unit_name
  )
)
WHERE `ku`.`name` = '酒店OTA专业指标口径知识库'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '订单与库存指标';

-- Downgrade the old HOS fixed score/window narrative to a historical lead.
UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.rows[2].best_explanation', '美团后台展示的酒店经营综合信号；当前公开资料未披露现行完整公式。',
  '$.rows[2].keep_from_existing', '历史资料中的满分、周期和维度仅作待核验线索，不作为当前事实。',
  '$.rows[2].suxios_handling', '保存meituan_hos_score原始展示值、门店、来源和日期；不写死满分5分、近28天、维度或排名影响。',
  '$.rows[2].source_status', 'historical_reference_current_algorithm_unknown',
  '$.official_semantic_override', JSON_OBJECT(
    'status', 'legacy_meituan_hos_formula_quarantined',
    'reviewed_at', @meituan_sem_reviewed_at,
    'unknowns', JSON_ARRAY('scale', 'window', 'dimensions', 'weights', 'ranking_effect'),
    'replacement_unit', @meituan_sem_unit_name
  )
)
WHERE `ku`.`name` = '酒店OTA专业指标口径知识库'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '平台私有指标';

-- Remove the generic incentive-to-review advice and quarantine historical HOS
-- dimensions in the old mind-map knowledge without deleting other useful rows.
UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.good_review_sources[2]', '真实住客可在自然服务流程中自愿评价；不得以礼物、折扣、抽奖、佣金或模板换取评价。',
  '$.official_semantic_override', JSON_OBJECT(
    'status', 'legacy_incentivized_review_advice_removed',
    'reviewed_at', @meituan_sem_reviewed_at,
    'source_refs', JSON_ARRAY('meituan_review_detail_v5_2025'),
    'replacement_unit', @meituan_sem_unit_name
  )
)
WHERE `ku`.`name` = 'OTA运营思维导图2.0知识沉淀'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '点评与口碑';

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  CASE WHEN JSON_VALID(`kc`.`content`) = 1 THEN `kc`.`content` ELSE JSON_OBJECT() END,
  '$.scorecards[3].platform_metric', '美团 HOS（历史参考）',
  '$.scorecards[3].dimensions', JSON_ARRAY('当前周期未知', '当前分制未知', '当前维度未知', '当前权重未知'),
  '$.scorecards[3].actions', JSON_ARRAY('保存当前后台原始值', '记录门店来源和日期', '不反推排名', '按真实信息库存履约改进'),
  '$.scorecards[3].source_status', 'historical_reference_current_algorithm_unknown',
  '$.scorecards[4].platform_metric', '美团排名/冠级（算法未知）',
  '$.scorecards[4].dimensions', JSON_ARRAY('当前因素未知', '当前权重未知', '当前样本未知'),
  '$.scorecards[4].actions', JSON_ARRAY('维护真实信息', '维护库存与履约', '按当前后台证据复盘'),
  '$.scorecards[4].source_status', 'historical_reference_current_algorithm_unknown',
  '$.official_semantic_override', JSON_OBJECT(
    'status', 'legacy_meituan_private_metric_formula_quarantined',
    'reviewed_at', @meituan_sem_reviewed_at,
    'replacement_unit', @meituan_sem_unit_name
  )
)
WHERE `ku`.`name` = 'OTA运营思维导图2.0知识沉淀'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '平台评分与排名';

SET @meituan_sem_staff_content := CONCAT(
  '# 美团酒店评价与经营规则官方语义合同', '\n\n',
  '## 已知的已知', '\n',
  '美团评价与大众点评必须拆分；美团星级和评分是动态加权指标，精确权重未知。利益换评、代写、模板诱导和AIGC虚构评价均不可作为运营手段。', '\n\n',
  '## 酒店评价投诉', '\n',
  '官方指引列出八类违规场景；每条评价限申诉1次，页面声明3个工作日内处理，但不承诺成功。宿析OS只整理真实证据和人工草稿，不自动提交。', '\n\n',
  '## 版本化经营规则', '\n',
  '2023版服务细则的拒单率和推翻率均以支付订单量为分母；当前公式、窗口、阈值和处罚仍待复核。2024诚信细则要求门店、房型、设施、政策与实际一致。', '\n\n',
  '## 已隔离旧知识', '\n',
  '旧直连FAQ同页出现最多180间夜与29间夜，且带©2016页脚，所有限额、权限和频率均不得作为现行事实。旧HOS满分、近28天和固定维度也只保留为历史线索。', '\n\n',
  '## 已知的未知', '\n',
  '当前酒店点评窗口、评分/HOS/排名算法、数据中心指标公式、账户权限、HMS字段与业务日、同步对账质量以及当前酒店事实仍未知。', '\n\n',
  '## 保护边界', '\n',
  '本知识只用于美团OTA规则解释、数据质量和人工运营复核，不扩大为全酒店事实，不授权价格、库存、订单、点评、申诉或PMS写入。'
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
  @meituan_sem_unit_name,
  @meituan_sem_staff_content,
  '美团,酒店,评价,大众点评拆分,投诉,诚信,拒单率,推翻率,价格异常,HOS,HMS,旧FAQ,版本冲突',
  JSON_ARRAY(
    '美团',
    '酒店',
    '评价规则',
    '诚信履约',
    'HMS',
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
    AND `title` = @meituan_sem_unit_name
);

UPDATE `knowledge_base`
SET
  `category_id` = 7,
  `content` = @meituan_sem_staff_content,
  `keywords` = '美团,酒店,评价,大众点评拆分,投诉,诚信,拒单率,推翻率,价格异常,HOS,HMS,旧FAQ,版本冲突',
  `tags` = JSON_ARRAY(
    '美团',
    '酒店',
    '评价规则',
    '诚信履约',
    'HMS',
    '版本冲突',
    'manual_review_only'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @meituan_sem_unit_name;
