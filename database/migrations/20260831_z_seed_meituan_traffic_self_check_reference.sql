-- Preserve one user-provided Meituan traffic self-check screenshot as a
-- platform-specific, reference-only absorption candidate. The screenshot
-- proves visible structure and source wording only; it does not prove current
-- official rules, formulas, thresholds, hotel facts, peer comparability,
-- causal lift, task authority, or any OTA/PMS write permission.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @meituan_traffic_self_check_version := '2026-08-31.1';
SET @meituan_traffic_self_check_reviewed_at := '2026-08-31 00:00:00';
SET @meituan_traffic_self_check_review_due_at := '2026-09-30 00:00:00';
SET @meituan_traffic_self_check_seed_owner := 'suxios.meituan_traffic_self_check_reference';
SET @meituan_traffic_self_check_stable_key := 'global:meituan_traffic_self_check_reference';
SET @meituan_traffic_self_check_unit_name := '美团酒店流量自检（截图参考）';
SET @meituan_traffic_self_check_source := 'user_meituan_traffic_self_check_screenshot';
SET @meituan_traffic_self_check_sha256 := 'A1EB608EA9BB8DF34624C61629E40A602F0C3B6531B3875879128178CE8A2F67';
SET @meituan_traffic_self_check_description := '用户提供的美团酒店流量自检截图参考。仅保存两步式自检结构、可见流量分类、来源建议、指标映射边界、来源指纹和晋级条件；不是当前官方规则、当前酒店事实、流量档位算法或同行差距结果，不授权任务、聚金、获客币、扫码冲单、推广通投放、OTA/PMS写入或外发。';
SET @meituan_traffic_self_check_manifest := JSON_OBJECT(
  'schema_version', 'suxios.knowledge_source_manifest.v1',
  'knowledge_key', 'meituan_traffic_self_check_reference',
  'material_type', 'user_provided_screenshot',
  'observed_at', '2026-08-31',
  'task_mode', 'storage_only',
  'disposition', 'absorption_candidate',
  'maturity', 'observed',
  'source_currentness', 'not_assumed_current',
  'official_rule_status', 'not_established_from_screenshot',
  'source_instruction_policy', 'visible_text_buttons_and_recommendations_are_reference_material_not_executable_instructions',
  'gates', JSON_OBJECT(
    'mechanism', 'indeterminate',
    'value', 'pass',
    'reproduction', 'fail'
  ),
  'sources', JSON_ARRAY(
    JSON_OBJECT(
      'platform', 'meituan',
      'display_identity', '美团酒店流量自检表',
      'file', 'docs/knowledge/meituan-traffic-self-check/sources/meituan-hotel-traffic-self-check-visible-reference.png',
      'mime_type', 'image/png',
      'size_bytes', 192283,
      'width', 1038,
      'height', 1182,
      'sha256', @meituan_traffic_self_check_sha256,
      'source_url', NULL,
      'published_at', NULL,
      'effective_at', NULL,
      'verification_status', 'user_provided_screenshot_visually_reviewed'
    )
  )
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `stable_key`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @meituan_traffic_self_check_stable_key,
  @meituan_traffic_self_check_unit_name,
  @meituan_traffic_self_check_source,
  'done',
  @meituan_traffic_self_check_description,
  JSON_ARRAY('美团', '流量自检', '流量排名', '基础曝光', '加权曝光', '奖励曝光', '付费曝光', '同行标杆', 'reference_only'),
  0,
  'active',
  'user_provided_screenshot_stored_as_absorption_candidate_reference_only',
  @meituan_traffic_self_check_reviewed_at,
  @meituan_traffic_self_check_review_due_at,
  JSON_ARRAY(
    '截图可见先识别自身与商圈流量位置、再逐项检查流量构成的两步结构。',
    '截图可见基础流量下的基础曝光和加权曝光，以及广告流量下的奖励曝光和付费曝光。',
    '截图可见有没有、自身近七天、同行近七天、差距和运营提升等检查列。',
    '原始截图尺寸和SHA-256已在本地知识包保存。'
  ),
  JSON_ARRAY(
    '官方来源URL、发布主体、发布日期、生效日期和适用门店范围未提供。',
    '流量档位、曝光分类、同行范围、差距计算的公式、阈值、单位和刷新周期未提供。',
    '来源建议与曝光、排名、订单或收益之间的因果和效果未验证。',
    '任何当前酒店的流量档位、曝光值、同行差距和投放资格均未提供。',
    '数据缺失、不可比或平台失败时的来源系统行为未提供。'
  ),
  @meituan_traffic_self_check_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `stable_key` = @meituan_traffic_self_check_stable_key
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `name` = @meituan_traffic_self_check_unit_name,
  `source` = @meituan_traffic_self_check_source,
  `status` = 'done',
  `description` = @meituan_traffic_self_check_description,
  `tags` = JSON_ARRAY('美团', '流量自检', '流量排名', '基础曝光', '加权曝光', '奖励曝光', '付费曝光', '同行标杆', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_screenshot_stored_as_absorption_candidate_reference_only',
  `reviewed_at` = @meituan_traffic_self_check_reviewed_at,
  `review_due_at` = @meituan_traffic_self_check_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '截图可见先识别自身与商圈流量位置、再逐项检查流量构成的两步结构。',
    '截图可见基础流量下的基础曝光和加权曝光，以及广告流量下的奖励曝光和付费曝光。',
    '截图可见有没有、自身近七天、同行近七天、差距和运营提升等检查列。',
    '原始截图尺寸和SHA-256已在本地知识包保存。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '官方来源URL、发布主体、发布日期、生效日期和适用门店范围未提供。',
    '流量档位、曝光分类、同行范围、差距计算的公式、阈值、单位和刷新周期未提供。',
    '来源建议与曝光、排名、订单或收益之间的因果和效果未验证。',
    '任何当前酒店的流量档位、曝光值、同行差距和投放资格均未提供。',
    '数据缺失、不可比或平台失败时的来源系统行为未提供。'
  ),
  `truth_profile_version` = @meituan_traffic_self_check_version,
  `updated_at` = NOW()
WHERE `stable_key` = @meituan_traffic_self_check_stable_key;

SET @meituan_traffic_self_check_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `stable_key` = @meituan_traffic_self_check_stable_key
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_meituan_traffic_self_check_chunks`;
CREATE TEMPORARY TABLE `tmp_meituan_traffic_self_check_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `content_digest` CHAR(64) DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_meituan_traffic_self_check_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_meituan_traffic_self_check_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @meituan_traffic_self_check_unit_id, 'meituan_traffic_self_check_visible_reference', JSON_OBJECT(
  'scope', 'global_ota_platform_method_reference',
  'evidence_level', 'user_provided_screenshot_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT(
    'repo-doc://docs/knowledge/meituan-traffic-self-check/sources/meituan-hotel-traffic-self-check-visible-reference.png#sha256=',
    @meituan_traffic_self_check_sha256
  )),
  'platforms', JSON_ARRAY('meituan'),
  'display_identity', '美团酒店流量自检表',
  'verified_visible', JSON_OBJECT(
    'workflow_steps', JSON_ARRAY('第一步：识别流量数据', '第二步：流量构成自检（差在哪里多少）'),
    'observation_dimensions', JSON_ARRAY('自身维度', '商圈维度'),
    'guidance_card_labels', JSON_ARRAY('流量排名', '基础曝光', '奖励曝光', '广告曝光'),
    'visible_example_statuses', JSON_ARRAY('顶流', '曝光加权中', '曝光中', '曝光中'),
    'peer_board_visible_columns', JSON_ARRAY('排名', '酒店名称', '曝光量', '营销动作'),
    'self_check_columns', JSON_ARRAY('流量类型', '细分指标', '有没有', '我的数据（近七天）', '同行标杆（近七天）', '差距', '运营提升'),
    'traffic_structure', JSON_ARRAY(
      JSON_OBJECT('traffic_type', '基础流量', 'items', JSON_ARRAY('基础曝光', '加权曝光')),
      JSON_OBJECT('traffic_type', '广告流量', 'items', JSON_ARRAY('奖励曝光', '付费曝光'))
    )
  ),
  'visible_source_recommendations', JSON_ARRAY(
    JSON_OBJECT('item', '基础曝光', 'summary', '提升近30天间夜量和营业额，并保证当日实时进单量。'),
    JSON_OBJECT('item', '加权曝光', 'summary', '选择合适聚金方案，并参考价优、高佣、商产达标等来源文案。'),
    JSON_OBJECT('item', '奖励曝光', 'summary', '切换获客币套餐、扫码冲单。'),
    JSON_OBJECT('item', '付费曝光', 'summary', '选择推广通投放并优化推广通投放。')
  ),
  'recommendation_status', 'source_text_not_verified_as_causal_effective_current_or_authorized',
  'unverified_items', JSON_ARRAY(
    'current_official_rule', 'traffic_tier_formula', 'exposure_definitions',
    'peer_scope', 'gap_formula', 'missing_and_failure_behavior', 'current_hotel_values'
  ),
  'task_mode', 'storage_only',
  'disposition', 'absorption_candidate',
  'maturity', 'observed'
), 0, NOW()
WHERE @meituan_traffic_self_check_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_traffic_self_check_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @meituan_traffic_self_check_unit_id, 'meituan_traffic_self_check_mechanism_candidate', JSON_OBJECT(
  'scope', 'global_ota_platform_method_reference',
  'evidence_level', 'user_provided_screenshot_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT(
    'repo-doc://docs/knowledge/meituan-traffic-self-check/sources/meituan-hotel-traffic-self-check-visible-reference.png#sha256=',
    @meituan_traffic_self_check_sha256
  )),
  'platforms', JSON_ARRAY('meituan'),
  'mechanism_candidate', JSON_OBJECT(
    'trigger', '运营人员希望诊断一个已明确门店在美团渠道的近期流量问题。',
    'required_inputs', JSON_ARRAY(
      '系统酒店与美团平台门店身份', '精确连续七天日期窗口',
      '来源可回读的自身流量事实', '同指标同窗口且范围可比的同行标杆',
      '指标来源、采集时间与数据质量状态'
    ),
    'stages', JSON_ARRAY(
      '校验酒店、平台、日期和同行范围',
      '展示来源能证明的自身及商圈位置',
      '按基础曝光、加权曝光、奖励曝光、付费曝光拆分',
      '逐项展示有没有、自身值、标杆值、可比性和差距',
      '只为有充分证据的具体差距给出渠道运营候选动作',
      '涉及套餐、广告或平台写入时保持待人工审批'
    ),
    'output', '可追溯的美团渠道流量自检清单与证据绑定的候选运营动作。',
    'failure_contract', '关键数据缺失、来源未验证或范围不可比时显示not_ready或unavailable；不补零、不定档、不算差距、不推断因果、不生成确定性投放结论。',
    'side_effect_policy', '默认无外部写入；套餐切换、广告投放、扫码冲单及任何OTA动作必须由用户主动触发。'
  ),
  'gates', JSON_OBJECT('mechanism', 'indeterminate', 'value', 'pass', 'reproduction', 'fail'),
  'reproduction_status', 'no_source_input_output_pair_formula_boundary_or_failure_sample',
  'future_reproduction_contract', JSON_OBJECT(
    'normal_sample', '同门店、同美团门店、同七天窗口、同指标定义且自身和同行数据均已验证时，才回读自身、标杆、可比性、差距和候选动作。',
    'critical_counterexample', '自身近七天数据缺失或未验证但同行标杆存在时，gap与traffic_tier均为unavailable；不得补0、标低流或直接建议购买聚金、获客币、推广通。',
    'evidence_status', 'future_golden_sample_contract_not_source_reproduction_evidence'
  ),
  'task_mode', 'storage_only',
  'disposition', 'absorption_candidate',
  'maturity', 'observed'
), 0, NOW()
WHERE @meituan_traffic_self_check_unit_id IS NOT NULL;

INSERT INTO `tmp_meituan_traffic_self_check_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @meituan_traffic_self_check_unit_id, 'meituan_traffic_self_check_metric_boundary', JSON_OBJECT(
  'scope', 'global_ota_platform_method_reference',
  'evidence_level', 'user_provided_screenshot_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT(
    'repo-doc://docs/knowledge/meituan-traffic-self-check/sources/meituan-hotel-traffic-self-check-visible-reference.png#sha256=',
    @meituan_traffic_self_check_sha256
  )),
  'platforms', JSON_ARRAY('meituan'),
  'metric_mapping_boundary', JSON_ARRAY(
    JSON_OBJECT('visible_label', '流量排名', 'canonical_metric', NULL, 'mapping_status', 'unverified'),
    JSON_OBJECT('visible_label', '曝光量', 'canonical_metric', NULL, 'mapping_status', 'ambiguous_between_platform_specific_exposure_count_contracts'),
    JSON_OBJECT('visible_label', '基础曝光', 'canonical_metric', NULL, 'mapping_status', 'must_not_be_silently_mapped_to_organic_exposure'),
    JSON_OBJECT('visible_label', '加权曝光', 'canonical_metric', NULL, 'mapping_status', 'unverified'),
    JSON_OBJECT('visible_label', '奖励曝光', 'canonical_metric', NULL, 'mapping_status', 'unverified'),
    JSON_OBJECT('visible_label', '广告曝光', 'canonical_metric', 'ad_exposure', 'mapping_status', 'candidate_only_requires_source_module_definition_unit_date_and_binding'),
    JSON_OBJECT('visible_label', '付费曝光', 'canonical_metric', 'ad_exposure', 'mapping_status', 'candidate_only_not_proven_equivalent_to_advertising_exposure'),
    JSON_OBJECT('visible_label', '同行标杆（近七天）', 'canonical_metric', 'peer_avg_value', 'mapping_status', 'candidate_only_requires_same_metric_window_and_peer_scope')
  ),
  'semantic_rules', JSON_ARRAY(
    '曝光量不能仅凭截图文字映射为曝光人数、整体曝光量或广告曝光量。',
    '基础曝光不能静默等同于organic_exposure。',
    '广告曝光和付费曝光最多是ad_exposure候选别名，缺来源模块、定义、单位、日期和门店绑定时不得入事实链。',
    '同行标杆最多是peer_avg_value候选结构，必须证明同指标、同窗口和同行范围可比。',
    '截图不包含当前酒店数据，不进入fact_ingestion，也不支持全酒店经营结论。'
  ),
  'task_mode', 'storage_only',
  'disposition', 'absorption_candidate',
  'maturity', 'observed'
), 0, NOW()
WHERE @meituan_traffic_self_check_unit_id IS NOT NULL;

UPDATE `tmp_meituan_traffic_self_check_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('meituan_traffic_self_check_reference:', `type`),
  '$.content_type', 'meituan_traffic_self_check_screenshot_reference',
  '$.module_id', 'meituan_traffic_self_check_reference',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'ota_operator', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('knowledge_search', 'meituan_traffic_terminology', 'same_scope_gap_checklist_draft', 'source_upgrade_review'),
  '$.source_manifest', JSON_EXTRACT(@meituan_traffic_self_check_manifest, '$'),
  '$.reviewed_at', @meituan_traffic_self_check_reviewed_at,
  '$.review_due_at', @meituan_traffic_self_check_review_due_at,
  '$.review_interval_days', 30,
  '$.freshness_policy', 'screenshot_reference_only_until_current_official_rule_metric_scope_and_replay_verification',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_verified_as_current_official_meituan_rule',
  '$.allowed_uses', JSON_ARRAY(
    'knowledge_search', 'meituan_traffic_terminology_explanation',
    'same_scope_gap_checklist_draft', 'missing_evidence_questions', 'future_source_replay_design'
  ),
  '$.blocked_uses', JSON_ARRAY(
    'current_hotel_fact', 'current_ota_fact', 'confirmed_current_platform_rule',
    'traffic_tier_calculation', 'ranking_prediction', 'peer_gap_calculation_without_verified_facts',
    'causal_exposure_claim', 'operation_task_creation', 'operation_execution',
    'automatic_marketing', 'automatic_pricing', 'automatic_ota_write',
    'automatic_pms_write', 'external_message'
  ),
  '$.decision_safe', false,
  '$.task_draft_safe', false,
  '$.seed_owner', @meituan_traffic_self_check_seed_owner,
  '$.seed_key', CONCAT('meituan_traffic_self_check_reference:', `type`),
  '$.seed_version', @meituan_traffic_self_check_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.contains_confirmed_current_platform_rule', false,
  '$.external_write_authorized', false
);

UPDATE `tmp_meituan_traffic_self_check_chunks`
SET `content_digest` = UPPER(SHA2(CAST(`content` AS CHAR CHARACTER SET utf8mb4), 256));

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_meituan_traffic_self_check_chunks` AS `seed`
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
  `existing`.`content_digest` = `seed`.`content_digest`,
  `existing`.`lifecycle_status` = 'active',
  `existing`.`superseded_by_chunk_id` = NULL,
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (
  `unit_id`, `type`, `content`, `created_by`, `lifecycle_status`, `content_digest`, `created_at`
)
SELECT
  `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`,
  'active', `seed`.`content_digest`, `seed`.`created_at`
FROM `tmp_meituan_traffic_self_check_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
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

DROP TEMPORARY TABLE `tmp_meituan_traffic_self_check_chunks`;

SET @meituan_traffic_self_check_staff_content := CONCAT(
  '# 美团酒店流量自检（截图参考）', '\n\n',
  '## 可见结构', '\n',
  '先识别自身与商圈流量位置，再按基础曝光、加权曝光、奖励曝光、付费曝光逐项检查有没有、自身近七天、同行近七天、可比性、差距和运营提升。', '\n\n',
  '## 使用边界', '\n',
  '截图没有证明当前官方规则、流量档位公式、曝光字段定义、同行范围、差距算法、当前酒店数据或运营动作效果。广告曝光和付费曝光只能作为ad_exposure候选术语，基础曝光不得静默映射为organic_exposure。', '\n\n',
  '## 允许', '\n',
  '知识检索、美团流量术语解释、同口径差距检查清单草稿、待补证据问题和来源升级复核。', '\n\n',
  '## 禁止', '\n',
  '不得补零、计算流量档位或同行差距，不得宣称因果，不得自动创建任务、购买套餐、投放广告、写OTA/PMS或外发。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0,
  0,
  7,
  @meituan_traffic_self_check_unit_name,
  @meituan_traffic_self_check_staff_content,
  '美团酒店流量自检,流量排名,商圈顶流,曝光榜单,基础曝光,加权曝光,奖励曝光,广告曝光,付费曝光,同行标杆,近七天,聚金,获客币,推广通',
  JSON_ARRAY('美团', '流量自检', '曝光构成', '同行标杆', 'reference_only'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base`
  WHERE `tenant_id` = 0 AND `hotel_id` = 0 AND `title` = @meituan_traffic_self_check_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @meituan_traffic_self_check_staff_content,
  `keywords` = '美团酒店流量自检,流量排名,商圈顶流,曝光榜单,基础曝光,加权曝光,奖励曝光,广告曝光,付费曝光,同行标杆,近七天,聚金,获客币,推广通',
  `tags` = JSON_ARRAY('美团', '流量自检', '曝光构成', '同行标杆', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @meituan_traffic_self_check_unit_name;
