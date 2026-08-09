-- Absorb a user-provided Ctrip commission/ranking reform briefing after public-source cross-checking.
-- This package separates confirmed platform/regulatory changes from partially corroborated direction
-- and unverified internal-message details. It does not bind a hotel, predict ranking, change commission,
-- enroll in promotions, or authorize any Ctrip write.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_reform_version := '2026-08-09.1';
SET @ctrip_reform_reviewed_at := '2026-08-09 00:00:00';
SET @ctrip_reform_review_due_at := '2026-08-18 00:00:00';
SET @ctrip_reform_seed_owner := 'suxios.ctrip_commission_reform_watch';
SET @ctrip_reform_unit_name := '携程佣金与流量排序新规观察（2026-08）';
SET @ctrip_reform_source := 'revenue_operations_decision_support';
SET @ctrip_reform_description := '将用户提供的15条携程佣金、流量排序、点评和装修信息更新，与2026年7月监管处罚、携程十九项整改公告及携程公开规则交叉核验。仅确认有公开证据的方向；10%至15%房型佣金、30天80%间夜考核、8月17日五因子、10月新排序、点评3天申诉期等保持待eBooking实页或合同复核。';
SET @ctrip_reform_source_manifest := JSON_OBJECT(
  'material_type', 'user_provided_internal_message_summary_plus_public_web_cross_check',
  'user_material_ref', 'user-message://2026-08-09/ctrip-commission-reform-15-claims',
  'user_material_status', 'user_provided_internal_message_unverified',
  'reviewed_at', '2026-08-09',
  'public_sources', JSON_ARRAY(
    JSON_OBJECT(
      'source_key', 'samr_ctrip_antitrust_penalty_20260725',
      'publisher', '国家市场监督管理总局',
      'published_at', '2026-07-25',
      'url', 'https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html',
      'evidence_grade', 'A',
      'scope', '行政处罚与已查明垄断行为'
    ),
    JSON_OBJECT(
      'source_key', 'ctrip_19_rectification_measures_20260725',
      'publisher', '携程黑板报，经央视网全文转载',
      'published_at', '2026-07-25',
      'url', 'https://jingji.cctv.com/2026/07/25/ARTI43yXusLYVp6aGHhJUNAS260725.shtml',
      'evidence_grade', 'A',
      'scope', '特牌金牌下线、调价权限、促销自愿、新佣金方向和新流量机制'
    ),
    JSON_OBJECT(
      'source_key', 'ctrip_hotel_algorithm_disclosure_accessed_20260809',
      'publisher', '携程',
      'url', 'https://contents.ctrip.com/activitysetupapp/mkt/index/hotelalgorithm',
      'evidence_grade', 'A',
      'scope', '商户自主定价、商户优惠和平台补贴说明'
    ),
    JSON_OBJECT(
      'source_key', 'ctrip_hotel_merchant_rules_accessed_20260809',
      'publisher', '携程',
      'url', 'https://pages.ctrip.com/hotels/IBU/pages/hotelspecification.html',
      'evidence_grade', 'A',
      'scope', '虚假交易、PSI、排序降权、欠款和正向标签规则'
    ),
    JSON_OBJECT(
      'source_key', 'ctrip_privacy_policy_personalization_accessed_20260809',
      'publisher', '携程',
      'url', 'https://rulecenter.ctrip.com/statics/rule/74/latest.html',
      'evidence_grade', 'A',
      'scope', '个性化展示与推荐的一般数据使用说明'
    )
  ),
  'search_result', 'no_public_official_text_found_for_exact_commission_range_factor_weights_or_announced_launch_dates'
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @ctrip_reform_unit_name,
  @ctrip_reform_source,
  'done',
  @ctrip_reform_description,
  JSON_ARRAY('携程', '佣金', '流量排序', '平台补贴', '点评', '规则观察', 'mixed_evidence', 'manual_review_only'),
  0,
  'active',
  'mixed_evidence_policy_watch_with_claim_level_verification_status',
  @ctrip_reform_reviewed_at,
  @ctrip_reform_review_due_at,
  JSON_ARRAY(
    '携程已于2026年7月25日宣布全面下线一级委托分销特牌和二级委托分销金牌合作模式，并取消相关不合理流量安排。',
    '携程已宣布建立公平合理的新佣金模式，但公开公告没有披露10%至15%、房型层费率或30天80%间夜考核细则。',
    '携程公开承诺未经商家明确同意业务人员不得擅自调价，并取消平台调价合同条款、不强迫商家参加促销。',
    '携程公开商家规则确认虚假交易可触发清除销量评价、PSI扣分、排序置底和限制平台补贴活动。',
    '携程公开政策确认存在个性化展示与推荐，但没有公开证明用户升级偏好、价格粘性等是此次新规则的精确字段。'
  ),
  JSON_ARRAY(
    '佣金是否可在10%至15%自选、能否按房型配置、30天80%间夜量考核和自助调整时间。',
    '云梯和定向加速包是否按所述时间下线，以及平台补贴是否存在独立开关。',
    '实时返后佣金率是否参与排序及其权重。',
    '价格、信息、产量、服务、佣金五因子的精确定义、权重及2026年8月17日上线日期。',
    '携程优选12%门槛、2026年10月30日新排序规则、点评3天审核申诉期和房型级装修时间功能。'
  ),
  @ctrip_reform_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ctrip_reform_unit_name AND `source` = @ctrip_reform_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @ctrip_reform_description,
  `tags` = JSON_ARRAY('携程', '佣金', '流量排序', '平台补贴', '点评', '规则观察', 'mixed_evidence', 'manual_review_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'mixed_evidence_policy_watch_with_claim_level_verification_status',
  `reviewed_at` = @ctrip_reform_reviewed_at,
  `review_due_at` = @ctrip_reform_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '携程已于2026年7月25日宣布全面下线一级委托分销特牌和二级委托分销金牌合作模式，并取消相关不合理流量安排。',
    '携程已宣布建立公平合理的新佣金模式，但公开公告没有披露10%至15%、房型层费率或30天80%间夜考核细则。',
    '携程已公开承诺未经商家明确同意业务人员不得擅自调价，并取消平台调价合同条款、不强迫商家参加促销。',
    '携程公开商家规则确认虚假交易可触发清除销量评价、PSI扣分、排序置底和限制平台补贴活动。',
    '携程公开政策确认存在个性化展示与推荐，但没有公开证明用户升级偏好、价格粘性等是此次新规则的精确字段。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '佣金是否可在10%至15%自选、能否按房型配置、30天80%间夜量考核和自助调整时间。',
    '云梯和定向加速包是否按所述时间下线，以及平台补贴是否存在独立开关。',
    '实时返后佣金率是否参与排序及其权重。',
    '价格、信息、产量、服务、佣金五因子的精确定义、权重及2026年8月17日上线日期。',
    '携程优选12%门槛、2026年10月30日新排序规则、点评3天审核申诉期和房型级装修时间功能。'
  ),
  `truth_profile_version` = @ctrip_reform_version,
  `updated_at` = NOW()
WHERE `name` = @ctrip_reform_unit_name AND `source` = @ctrip_reform_source;

SET @ctrip_reform_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ctrip_reform_unit_name AND `source` = @ctrip_reform_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_ctrip_reform_chunks`;
CREATE TEMPORARY TABLE `tmp_ctrip_reform_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_ctrip_reform_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_ctrip_reform_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_reform_unit_id, 'ctrip_reform_source_and_evidence_boundary', JSON_OBJECT(
  'scope', 'ctrip_channel_policy_watch_global_reference',
  'evidence_level', 'mixed_official_public_sources_and_user_provided_internal_message',
  'evidence_grade', 'mixed_A_to_D_claim_level',
  'source_refs', JSON_ARRAY(
    'samr_ctrip_antitrust_penalty_20260725',
    'ctrip_19_rectification_measures_20260725',
    'ctrip_hotel_algorithm_disclosure_accessed_20260809',
    'ctrip_hotel_merchant_rules_accessed_20260809',
    'ctrip_privacy_policy_personalization_accessed_20260809',
    'user-message://2026-08-09/ctrip-commission-reform-15-claims'
  ),
  'source_priority', JSON_ARRAY('regulator_decision', 'ctrip_public_announcement', 'ctrip_public_rule', 'user_provided_internal_message'),
  'fact_boundary', 'official_support_is_required_before_any_claim_becomes_current_platform_rule',
  'hotel_binding_status', 'not_bound_to_any_hotel_or_ctrip_property',
  'effective_contract_status', 'not_verified_in_any_current_hotel_contract_or_ebooking_account'
), 0, NOW()
WHERE @ctrip_reform_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_reform_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_reform_unit_id, 'ctrip_reform_claim_assessment_01_08', JSON_OBJECT(
  'scope', 'claim_level_assessment',
  'evidence_level', 'mixed_claim_level',
  'claims', JSON_ARRAY(
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_01',
      'claim', '云梯、定向加速包确定下线。',
      'verification_status', 'unverified_exact_tools',
      'public_corroboration', '携程已确认下线AI生意助手、挂牌通和智选特惠，但公开公告未出现云梯或定向加速包名称。',
      'decision_rule', '不要把已确认下线工具替代成消息中的两个工具；以eBooking公告和工具入口实页为准。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_02',
      'claim', '佣金率可自选10%至15%，可按房型层配置，并考核30天内覆盖80%间夜量的高产房型佣金；账单不得欠费；预计9月支持商家自助调整。',
      'verification_status', 'confirmed_direction_exact_mechanics_unverified',
      'public_corroboration', '携程已确认取消原挂牌收费并建立新佣金模式；公开商家规则显示欠平台服务费会影响诚信分与权益。',
      'unknowns', JSON_ARRAY('10%至15%范围', '房型层配置', '30天窗口', '80%间夜口径', '调整频次', '9月自助入口'),
      'decision_rule', '拿到合同或eBooking费率页前不得按该区间测算真实佣金或建议调佣。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_03',
      'claim', '平台补贴可自选要或不要。',
      'verification_status', 'principle_corroborated_exact_switch_unverified',
      'public_corroboration', '携程承诺不强迫商家参加促销，公开算法页区分携程单独补贴和商户共同补贴。',
      'decision_rule', '是否存在统一开关、不同补贴是否分别选择，需以当前eBooking页面为准。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_04',
      'claim', '实时返后佣金率影响排序。',
      'verification_status', 'unverified',
      'public_corroboration', '携程确认将建立新的流量分配机制，但未公开佣金的实时口径、权重或因果公式。',
      'decision_rule', '不得承诺提高佣金必然提升排名；只做可撤销场景测算。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_05',
      'claim', '平台业务不再拥有eBooking后台改价和促销报名权限。',
      'verification_status', 'partially_corroborated',
      'public_corroboration', '携程确认未经商家明确同意业务人员不得擅自调价，并取消平台调价合同条款；尚不能从公开文本推导出业务人员绝对没有促销报名权限。',
      'decision_rule', '把规则写成必须取得商家明确同意，不扩大成绝对技术权限结论。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_06',
      'claim', '将上线携程优选标签，要求酒店品质和12%佣金，但不影响排名流量。',
      'verification_status', 'unverified_new_label',
      'public_corroboration', '携程现行公开规则存在精选等正向标签，但不能证明其等同于新携程优选，也未公开12%门槛或不影响排名。',
      'decision_rule', '不要把精选、优选、优享会等不同标签混为同一产品。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_07',
      'claim', '新流量排序以价格、信息、产量、服务、佣金五大因子为准，预计2026年8月17日上线。',
      'verification_status', 'new_mechanism_confirmed_factor_set_and_date_unverified',
      'public_corroboration', '携程确认将建立新的流量分配机制，但公开来源未确认五因子清单、相互关系、权重或8月17日日期。',
      'decision_rule', '8月18日后复核公告、账户页面和同城同口径数据，不把单日排名波动当作规则上线证据。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_08',
      'claim', '服务因子包含订单及时确认率、投诉、点评分、用户权益和PSI服务缺陷。',
      'verification_status', 'components_directionally_consistent_exact_factor_contract_unverified',
      'public_corroboration', '公开商家规则确认投诉、点评违规、PSI和权益限制会影响经营状态与排序处罚，但未公开此次服务因子的完整公式。',
      'decision_rule', '可作为服务体检清单，不得输出服务因子得分或排名贡献。'
    )
  )
), 0, NOW()
WHERE @ctrip_reform_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_reform_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_reform_unit_id, 'ctrip_reform_claim_assessment_09_15', JSON_OBJECT(
  'scope', 'claim_level_assessment',
  'evidence_level', 'mixed_claim_level',
  'claims', JSON_ARRAY(
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_09',
      'claim', '价格因子包含用户视角价优、房态良好度和取消政策灵活性。',
      'verification_status', 'unverified_exact_factor_contract',
      'public_corroboration', '携程公开说明商户自主定价、优惠和补贴，但未公开该三项为此次价格因子的完整口径。',
      'decision_rule', '可分别监控到手价、可售房态和取消政策，禁止合成未经官方定义的价格因子分。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_10',
      'claim', '产量因子是30天欢迎度，包含历史订单量、成交额和成交率；刷单数据会清理并处罚降流。',
      'verification_status', 'anti_fraud_confirmed_welcome_metric_unverified',
      'public_corroboration', '携程公开规则明确虚假交易可清除虚假销量评价、扣PSI、排序置底和限制活动；未公开30天欢迎度及三个子指标公式。',
      'decision_rule', '严禁刷单；历史订单量、成交额和成交率分别按明确分母查看，不虚构欢迎度总分。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_11',
      'claim', '信息因子包含完成度、真实性和丰富度。',
      'verification_status', 'directionally_corroborated_exact_factor_contract_unverified',
      'public_corroboration', '携程公开加盟与商家规则要求信息和图片准确真实并核验房型设施，但未公开此次信息因子公式。',
      'decision_rule', '可立即做信息缺失和真实性巡检，丰富度只补真实可兑现内容。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_12',
      'claim', '预计2026年10月30日上线新排序规则，现有特牌和金牌全部下线。',
      'verification_status', 'officially_corrected_plus_unverified_future_date',
      'public_corroboration', '携程已于2026年7月25日宣布特牌和金牌合作模式全面下线，不应继续写成等待10月30日；10月30日新排序规则未获公开确认。',
      'decision_rule', '将特牌金牌下线记为已公告事实，将10月30日只记为待验证里程碑。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_13',
      'claim', '千人千面更重视升级偏好、价格偏好、场景偏好、亮点维护、收藏和价格粘性。',
      'verification_status', 'general_personalization_confirmed_exact_features_unverified',
      'public_corroboration', '携程隐私政策确认会利用浏览、搜索、收藏、订单等信息做个性化展示与推荐，但未公开这些具体字段是此次排序更新的精确特征。',
      'decision_rule', '可以维护真实亮点并分客群观察转化，不得推断个人敏感属性或宣称掌握算法权重。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_14',
      'claim', '点评发布前有3天审核和商家申诉期，预计2026年10月上线。',
      'verification_status', 'unverified_and_not_equivalent_to_current_appeal_rules',
      'public_corroboration', '公开商家违规规则存在5个工作日申诉期，历史点评申诉说明也有不同期限；这些都不能证明新的点评先审后发3天机制。',
      'decision_rule', '保留当前点评处理流程，待eBooking点评页或正式公告出现后再改SOP。'
    ),
    JSON_OBJECT(
      'claim_id', 'ctrip_reform_claim_15',
      'claim', '支持把部分房型装修时间展示到对应装修过的房型上。',
      'verification_status', 'unverified_feature',
      'public_corroboration', '携程公开加盟规则会核验开业或装修年份和房型信息，但未公开房型级装修时间新功能。',
      'decision_rule', '未见实际字段前不创建房型装修事实；上线后必须保留房型ID、装修范围、日期和证明材料。'
    )
  )
), 0, NOW()
WHERE @ctrip_reform_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_reform_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_reform_unit_id, 'ctrip_reform_hotel_action_checklist', JSON_OBJECT(
  'scope', 'manual_operator_checklist_without_platform_write',
  'evidence_level', 'derived_from_confirmed_rules_and_guarded_unknowns',
  'immediate_actions', JSON_ARRAY(
    '核对账单、发票和欠款状态，记录结清证据，但不把欠款清零当作排名提升保证。',
    '导出当前合同佣金、房型费率、补贴和促销报名页面截图，保留账号、酒店、时间和页面版本。',
    '巡检价格、可售房态、取消政策、房型信息、图片、设施、权益和装修描述，缺失保持缺失，不编造。',
    '检查订单及时确认、投诉、点评回复、PSI缺陷和权益兑现，建立整改项。',
    '严禁刷单、诱导评价或用虚假交易制造产量。'
  ),
  'scenario_analysis_only', JSON_ARRAY(
    '按10%、12%、15%做利润敏感性测算时必须标为假设，不代表平台可选档位。',
    '按房型分析30天间夜占比时保留真实订单分母，不把80%阈值当成已生效规则。',
    '比较补贴开关前后到手价时区分平台承担、酒店承担和共同承担。'
  ),
  'stop_before', JSON_ARRAY('commission_change', 'promotion_enrollment_change', 'subsidy_opt_in_or_out', 'ota_price_change', 'ranking_claim'),
  'required_external_evidence', JSON_ARRAY('当前酒店eBooking公告', '当前电子合同或补充协议', '佣金配置页', '促销或补贴开关页', '官方生效日期和口径说明')
), 0, NOW()
WHERE @ctrip_reform_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_reform_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_reform_unit_id, 'ctrip_reform_reverification_schedule', JSON_OBJECT(
  'scope', 'time_bounded_reverification',
  'evidence_level', 'derived_verification_plan',
  'milestones', JSON_ARRAY(
    JSON_OBJECT('date', '2026-08-18', 'verify', JSON_ARRAY('五因子是否上线', '排序页面或公告版本', '云梯和定向加速包入口状态')),
    JSON_OBJECT('date', '2026-09-30', 'verify', JSON_ARRAY('商家自助佣金入口', '房型层费率', '调整频次和生效时间', '欠款限制')),
    JSON_OBJECT('date', '2026-10-31', 'verify', JSON_ARRAY('新排序公告', '携程优选标签', '点评3天审核申诉期', '房型级装修时间'))
  ),
  'acceptance_evidence', JSON_ARRAY('官方公告URL或eBooking消息ID', '酒店与账号范围', '页面采集时间', '字段原文', '生效日期', '保存与回读'),
  'rejection_evidence', JSON_ARRAY('入口仍不存在', '公告未出现', '字段只对部分灰度账号开放', '与合同或官方规则冲突'),
  'no_inference_from', JSON_ARRAY('单日排名波动', '业务口头转述', '群聊截图无来源', '其他酒店账号页面', '历史规则')
), 0, NOW()
WHERE @ctrip_reform_unit_id IS NOT NULL;

UPDATE `tmp_ctrip_reform_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('ctrip_commission_reform_watch:', `type`),
  '$.content_type', 'ctrip_policy_watch_contract',
  '$.module_id', 'ctrip_commission_reform_watch',
  '$.platforms', JSON_ARRAY('ctrip'),
  '$.roles', JSON_ARRAY('owner', 'operator', 'revenue_manager', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('commission_rule_review', 'traffic_rule_review', 'promotion_subsidy_review', 'hotel_information_audit', 'review_rule_review'),
  '$.source_manifest', JSON_EXTRACT(@ctrip_reform_source_manifest, '$'),
  '$.reviewed_at', @ctrip_reform_reviewed_at,
  '$.review_due_at', @ctrip_reform_review_due_at,
  '$.freshness_policy', 'recheck_on_announced_milestones_or_new_ebooking_contract_notice',
  '$.allowed_uses', JSON_ARRAY('manual_policy_briefing', 'verification_checklist', 'information_quality_audit', 'service_quality_audit', 'hypothesis_labeled_scenario_analysis'),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'confirmed_current_contract_term', 'commission_change', 'ranking_prediction', 'operation_task_auto_creation', 'operation_execution', 'promotion_enrollment_change', 'subsidy_opt_in_or_out', 'automatic_pricing', 'automatic_marketing', 'automatic_ota_write', 'automatic_pms_write'),
  '$.seed_owner', @ctrip_reform_seed_owner,
  '$.seed_key', CONCAT('ctrip_commission_reform_watch:', `type`),
  '$.seed_version', @ctrip_reform_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_confirmed_current_contract_term', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_ctrip_reform_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_ctrip_reform_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_ctrip_reform_chunks`;

SET @ctrip_reform_staff_content := CONCAT(
  '# 携程佣金与流量排序新规观察（2026-08）', '\n\n',
  '## 已确认', '\n',
  '携程已于2026年7月25日公告全面下线特牌和金牌合作模式，取消相关不合理流量安排；建立新佣金模式；未经商家明确同意业务不得擅自调价；不强迫商家参加促销。', '\n\n',
  '## 部分印证', '\n',
  '平台补贴与促销应尊重商家意愿，虚假交易会清理销量评价并触发PSI、排序和活动处罚；个性化推荐确实存在。', '\n\n',
  '## 待官方确认', '\n',
  '10%至15%自选佣金、房型层费率、30天80%间夜考核、实时返后佣金排序、携程优选12%、8月17日五因子、10月新排序、点评3天申诉期、房型级装修时间。', '\n\n',
  '## 当前操作', '\n',
  '先核账、保存当前合同与eBooking页面证据，巡检价格房态取消政策、信息、服务、点评、权益与PSI；严禁刷单。未取得当前酒店合同或页面证据前，不调佣、不改补贴、不承诺排名提升。', '\n\n',
  '## 复核节点', '\n',
  '2026-08-18、2026-09-30、2026-10-31分别复核排序、佣金入口和10月功能。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @ctrip_reform_unit_name, @ctrip_reform_staff_content,
  '携程新规,携程佣金,流量排序,五大因子,平台补贴,特牌,金牌,携程优选,点评申诉,房型装修时间',
  JSON_ARRAY('携程', '佣金新规', '流量排序', '待官方确认', 'manual_review_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @ctrip_reform_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @ctrip_reform_staff_content,
  `keywords` = '携程新规,携程佣金,流量排序,五大因子,平台补贴,特牌,金牌,携程优选,点评申诉,房型装修时间',
  `tags` = JSON_ARRAY('携程', '佣金新规', '流量排序', '待官方确认', 'manual_review_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @ctrip_reform_unit_name;
