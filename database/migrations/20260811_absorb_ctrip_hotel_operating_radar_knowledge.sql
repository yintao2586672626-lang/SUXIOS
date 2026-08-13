-- Absorb the user-provided Ctrip hotel operating radar article and three branded images.
-- The material describes a planned, gradual platform mechanism. It is not a current-hotel
-- score, a verified ranking formula, an OTA write authorization, or proof that the radar is
-- itself a directly mandated item in Ctrip's antitrust rectification.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_radar_version := '2026-08-11.1';
SET @ctrip_radar_reviewed_at := '2026-08-11 00:00:00';
SET @ctrip_radar_review_due_at := '2026-09-30 00:00:00';
SET @ctrip_radar_seed_owner := 'suxios.ctrip_hotel_operating_radar_knowledge';
SET @ctrip_radar_unit_name := '携程酒店经营雷达图（规划期）五维知识合同';
SET @ctrip_radar_source := 'revenue_operations_decision_support';
SET @ctrip_radar_document_sha256 := 'E2A4FC333E47BC8D6F1B8E572ED44857E2A37872E1EA5DFC65F997DBCA6E3D4F';
SET @ctrip_radar_image_1_sha256 := 'D09793D1C72F785E289EEDE37F265ACAB89F59A6050AD2A48D8AE8BD098D937C';
SET @ctrip_radar_image_2_sha256 := 'A0970684ABA0154389CDA502230586D1523C544C4AD74B6409B41CCEAFF05025';
SET @ctrip_radar_image_3_sha256 := '0835567A1C2C5052054FCEE5F806736A9F5468C6DF15B7512842DE2FCF204EAB';
SET @ctrip_radar_samr_url := 'https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html';
SET @ctrip_radar_description := '沉淀用户提供的携程酒店经营雷达图正文与三张图片：信息分、友好度、品质度、欢迎度、平台技术服务费五维及其用户链路、平台关注项和规划期边界。该知识可检索但不能生成酒店得分、推断排序权重、替代实时eBooking页面或授权任何OTA/PMS写入。反垄断处罚事实由市场监管总局来源核验；雷达图与处罚整改之间的直接因果仍未证实。';
SET @ctrip_radar_source_manifest := JSON_OBJECT(
  'material_type', 'user_provided_article_and_three_branded_images',
  'normalized_transcript_path', 'docs/ctrip_hotel_operating_radar_knowledge.md',
  'normalized_transcript_sha256', @ctrip_radar_document_sha256,
  'image_sha256', JSON_ARRAY(
    @ctrip_radar_image_1_sha256,
    @ctrip_radar_image_2_sha256,
    @ctrip_radar_image_3_sha256
  ),
  'observed_at', '2026-08-11',
  'visible_branding', JSON_ARRAY('Trip.com Group', 'HB 酒店增长营'),
  'original_article_url_status', 'not_provided',
  'original_publish_date_status', 'not_provided',
  'officiality_status', 'branding_visible_origin_not_independently_verified',
  'user_context', 'post_antitrust_penalty_change_expected_gradual_rollout',
  'rollout_status', 'planned_gradual_rollout',
  'preview_timing_status', 'source_says_expected_in_september_year_unknown',
  'regulatory_source', @ctrip_radar_samr_url,
  'regulatory_fact_status', 'official_source_verified',
  'radar_penalty_causal_link_status', 'causal_link_unverified'
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @ctrip_radar_unit_name,
  @ctrip_radar_source,
  'done',
  @ctrip_radar_description,
  JSON_ARRAY('携程', '酒店经营雷达图', '信息分', '友好度', '品质度', '欢迎度', '平台技术服务费', '规划期', 'global_reference'),
  0,
  'active',
  'user_provided_ctrip_radar_material_absorbed_as_planned_rollout_reference',
  @ctrip_radar_reviewed_at,
  @ctrip_radar_review_due_at,
  JSON_ARRAY(
    '用户提供的正文和图片均描述信息分、友好度、品质度、欢迎度、平台技术服务费五个维度。',
    '材料把信息浏览、预订决策、到店入住和长期价值分别关联到信息分、友好度、品质度和欢迎度。',
    '材料明确称单一维度不决定最终结果，并把产品、服务、体验和长期口碑作为价值导向。',
    '材料称eBooking商家后台预计于9月开放雷达图预览版，但没有提供年份。',
    '市场监管总局已于2026-07-25公布对携程滥用市场支配地位行为的行政处罚并责令全面整改。'
  ),
  JSON_ARRAY(
    '原始文章链接、作者、发布日期和9月所属年份未提供。',
    '雷达图当前是否已向任一具体酒店开放、开放范围和分批节奏未核验。',
    '五维权重、阈值、公式、刷新频率、排序影响和流量效果未提供。',
    '材料没有提供任何具体酒店的五维得分或可用于计算得分的完整字段。',
    '处罚发生在雷达图材料之前不等于雷达图已被证明为处罚决定直接要求的整改项目。'
  ),
  @ctrip_radar_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @ctrip_radar_unit_name AND `source` = @ctrip_radar_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @ctrip_radar_description,
  `tags` = JSON_ARRAY('携程', '酒店经营雷达图', '信息分', '友好度', '品质度', '欢迎度', '平台技术服务费', '规划期', 'global_reference'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_ctrip_radar_material_absorbed_as_planned_rollout_reference',
  `reviewed_at` = @ctrip_radar_reviewed_at,
  `review_due_at` = @ctrip_radar_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '用户提供的正文和图片均描述信息分、友好度、品质度、欢迎度、平台技术服务费五个维度。',
    '材料把信息浏览、预订决策、到店入住和长期价值分别关联到信息分、友好度、品质度和欢迎度。',
    '材料明确称单一维度不决定最终结果，并把产品、服务、体验和长期口碑作为价值导向。',
    '材料称eBooking商家后台预计于9月开放雷达图预览版，但没有提供年份。',
    '市场监管总局已于2026-07-25公布对携程滥用市场支配地位行为的行政处罚并责令全面整改。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '原始文章链接、作者、发布日期和9月所属年份未提供。',
    '雷达图当前是否已向任一具体酒店开放、开放范围和分批节奏未核验。',
    '五维权重、阈值、公式、刷新频率、排序影响和流量效果未提供。',
    '材料没有提供任何具体酒店的五维得分或可用于计算得分的完整字段。',
    '处罚发生在雷达图材料之前不等于雷达图已被证明为处罚决定直接要求的整改项目。'
  ),
  `truth_profile_version` = @ctrip_radar_version,
  `updated_at` = NOW()
WHERE `name` = @ctrip_radar_unit_name AND `source` = @ctrip_radar_source;

SET @ctrip_radar_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ctrip_radar_unit_name AND `source` = @ctrip_radar_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_ctrip_radar_chunks`;
CREATE TEMPORARY TABLE `tmp_ctrip_radar_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_ctrip_radar_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_ctrip_radar_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_unit_id, 'ctrip_radar_source_scope_and_rollout_reference', JSON_OBJECT(
  'scope', 'ctrip_hotel_operating_radar_source_scope',
  'evidence_level', 'user_provided_branded_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('repo-doc://docs/ctrip_hotel_operating_radar_knowledge.md#sha256=', @ctrip_radar_document_sha256),
    CONCAT('user-image://radar-dimension-table#sha256=', @ctrip_radar_image_1_sha256),
    CONCAT('user-image://radar-five-dimension-model#sha256=', @ctrip_radar_image_2_sha256),
    CONCAT('user-image://radar-value-direction#sha256=', @ctrip_radar_image_3_sha256)
  ),
  'visible_branding', JSON_ARRAY('Trip.com Group', 'HB 酒店增长营'),
  'source_status', 'user_provided_branded_reference',
  'rollout_status', 'planned_gradual_rollout',
  'preview_timing', 'expected_in_september_year_unknown',
  'current_availability_status', 'not_verified_for_any_specific_hotel',
  'source_origin_status', 'original_url_author_publish_date_not_provided',
  'temporary_image_retention', 'hash_and_verified_visible_text_preserved_temp_paths_not_persisted'
), 0, NOW()
WHERE @ctrip_radar_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_unit_id, 'ctrip_antitrust_regulatory_context_fact', JSON_OBJECT(
  'scope', 'ctrip_antitrust_regulatory_context',
  'evidence_level', 'official_current_penalty_decision',
  'evidence_grade', 'A',
  'source_refs', JSON_ARRAY(@ctrip_radar_samr_url),
  'decision_date', '2026-07-25',
  'decision_number', '国市监处罚〔2026〕29号',
  'authority', '国家市场监督管理总局',
  'penalty_total_cny', 5179000000,
  'verified_conduct', JSON_ARRAY(
    '以流量分配机制为核心要求部分酒店独家合作',
    '强制部分酒店给予全网最低价并允许平台直接调价',
    '通过流量限制、摘牌和扣除订单储备金等措施保障实施'
  ),
  'rectification_requirement', '停止违法行为并全面整改',
  'radar_causal_link_status', 'not_established_by_official_penalty_source_or_user_material',
  'contains_regulatory_fact', true
), 0, NOW()
WHERE @ctrip_radar_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_unit_id, 'ctrip_radar_model_principles_reference', JSON_OBJECT(
  'scope', 'ctrip_hotel_operating_radar_model_principles',
  'evidence_level', 'user_provided_branded_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('repo-doc://docs/ctrip_hotel_operating_radar_knowledge.md#sha256=', @ctrip_radar_document_sha256),
    CONCAT('user-image://radar-value-direction#sha256=', @ctrip_radar_image_3_sha256)
  ),
  'source_stated_basis', '用户真实需求与需求匹配',
  'source_stated_user_lifecycle', JSON_ARRAY('认知', '吸引', '预订', '体验', '复购'),
  'source_stated_goal', JSON_ARRAY('破除低价内卷', '践行价值导向', '助力酒店可持续增长'),
  'source_stated_model_rule', '五大维度相互独立、均衡构建，单一维度不决定最终结果',
  'interpretation_guard', '相互独立与均衡只保存为来源表述，不推导统计独立、等权重、阈值或固定评分公式'
), 0, NOW()
WHERE @ctrip_radar_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_unit_id, 'ctrip_radar_five_dimension_semantics_reference', JSON_OBJECT(
  'scope', 'ctrip_hotel_operating_radar_dimension_semantics',
  'evidence_level', 'user_provided_branded_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-image://radar-dimension-table#sha256=', @ctrip_radar_image_1_sha256),
    CONCAT('user-image://radar-five-dimension-model#sha256=', @ctrip_radar_image_2_sha256)
  ),
  'dimensions', JSON_ARRAY(
    JSON_OBJECT('key', 'information_score', 'label', '信息分', 'source_stated_effect', '决定用户点击的首要吸引力'),
    JSON_OBJECT('key', 'friendliness', 'label', '友好度', 'source_stated_effect', '提升用户的转化意愿'),
    JSON_OBJECT('key', 'quality', 'label', '品质度', 'source_stated_effect', '保障用户预订体验与信任感'),
    JSON_OBJECT('key', 'welcome', 'label', '欢迎度', 'source_stated_effect', '用户实际选择的呈现'),
    JSON_OBJECT('key', 'platform_technical_service_fee', 'label', '平台技术服务费', 'aliases', JSON_ARRAY('服务费'), 'source_stated_effect', '平台技术服务费')
  ),
  'channel_scope', 'ctrip_platform_operating_reference_only',
  'score_formula_status', 'not_provided',
  'weight_status', 'not_provided',
  'threshold_status', 'not_provided'
), 0, NOW()
WHERE @ctrip_radar_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_unit_id, 'ctrip_radar_user_journey_and_platform_focus_reference', JSON_OBJECT(
  'scope', 'ctrip_hotel_operating_radar_user_journey_mapping',
  'evidence_level', 'user_provided_branded_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-image://radar-dimension-table#sha256=', @ctrip_radar_image_1_sha256)),
  'journey_rows', JSON_ARRAY(
    JSON_OBJECT(
      'stage', '信息浏览',
      'user_questions', JSON_ARRAY('这家看起来怎么样？', '带宠物能住吗？'),
      'dimension_key', 'information_score',
      'platform_focus', JSON_ARRAY('图片/视频质量', '设施描述完整', '酒店政策准确', '信息真实')
    ),
    JSON_OBJECT(
      'stage', '预订决策',
      'user_questions', JSON_ARRAY('预订是否省心？', '退改是否灵活？'),
      'dimension_key', 'friendliness',
      'platform_focus', JSON_ARRAY('价格合理', '房态准确/充足', '取消政策灵活')
    ),
    JSON_OBJECT(
      'stage', '到店入住',
      'user_questions', JSON_ARRAY('服务怎么样？', '入住体验舒心吗？'),
      'dimension_key', 'quality',
      'platform_focus', JSON_ARRAY('订单即时确认', '用户投诉', '点评分', '用户权益', '六大类服务缺陷')
    ),
    JSON_OBJECT(
      'stage', '长期价值',
      'user_questions', JSON_ARRAY('是否认可？'),
      'dimension_key', 'welcome',
      'platform_focus', JSON_ARRAY('历史订单与销售额', '历史成交率', '避免虚假交易和恶意刷单')
    ),
    JSON_OBJECT(
      'stage', '平台合作',
      'user_questions', JSON_ARRAY(),
      'dimension_key', 'platform_technical_service_fee',
      'platform_focus', JSON_ARRAY('合理的技术服务费', '无逾期账单')
    )
  ),
  'metric_scope_guard', '历史订单销售额成交率仍是携程渠道口径，不自动扩大为全酒店经营事实'
), 0, NOW()
WHERE @ctrip_radar_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_unit_id, 'ctrip_radar_usage_and_rollout_guard', JSON_OBJECT(
  'scope', 'ctrip_hotel_operating_radar_usage_guard',
  'evidence_level', 'user_provided_branded_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('repo-doc://docs/ctrip_hotel_operating_radar_knowledge.md#sha256=', @ctrip_radar_document_sha256)),
  'allowed_interpretation', JSON_ARRAY(
    '携程运营知识检索与商家培训参考',
    '未来真实eBooking字段的身份和语义校验',
    '基于另行取得的当前酒店事实形成人工复核检查项'
  ),
  'prohibited_inference', JSON_ARRAY(
    '从示意图计算具体酒店得分或总分',
    '推断五维权重阈值公式排序增益或收入效果',
    '把价格合理解释为全网最低价或自动降价授权',
    '把平台技术服务费自动等同于佣金营销费订单储备金或其他费用',
    '把9月解释为已知年份或当前全量上线',
    '把雷达图直接归因为处罚决定要求的整改项目'
  ),
  'rollout_guard', 'reference_only_and_planned_gradual_rollout_until_live_ebooking_and_official_source_verification',
  'upgrade_triggers', JSON_ARRAY('携程原始发布页', 'eBooking真实雷达页', '字段帮助文档', '门店级五维数据', '明确生效日期与覆盖范围', '排序或评分口径', '正式整改措施与雷达图直接关联说明')
), 0, NOW()
WHERE @ctrip_radar_unit_id IS NOT NULL;

UPDATE `tmp_ctrip_radar_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('ctrip_hotel_operating_radar:', `type`),
  '$.content_type', 'platform_operating_knowledge_contract',
  '$.module_id', 'ctrip_hotel_operating_radar',
  '$.platforms', JSON_ARRAY('ctrip'),
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'ota_operator', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('ctrip_knowledge_retrieval', 'merchant_training', 'future_radar_field_mapping', 'live_ebooking_comparison'),
  '$.source_manifest', JSON_EXTRACT(@ctrip_radar_source_manifest, '$'),
  '$.reviewed_at', @ctrip_radar_reviewed_at,
  '$.review_due_at', @ctrip_radar_review_due_at,
  '$.review_interval_days', 50,
  '$.freshness_policy', 'planned_rollout_requires_live_ebooking_and_original_source_recheck',
  '$.allowed_uses', JSON_ARRAY('knowledge_retrieval', 'merchant_training', 'future_field_mapping', 'manual_checklist_reference'),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'current_ota_fact', 'hotel_score_calculation', 'ranking_prediction', 'revenue_fact', 'operation_task_creation', 'operation_execution', 'automatic_pricing', 'automatic_inventory_change', 'automatic_ota_write', 'automatic_pms_write'),
  '$.seed_owner', @ctrip_radar_seed_owner,
  '$.seed_key', CONCAT('ctrip_hotel_operating_radar:', `type`),
  '$.seed_version', @ctrip_radar_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_ctrip_radar_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_ctrip_radar_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_ctrip_radar_chunks`;

SET @ctrip_radar_staff_content := CONCAT(
  '# 携程酒店经营雷达图（规划期）', '\n\n',
  '## 当前状态', '\n',
  '用户提供材料称雷达图将逐步实施，eBooking预览版预计于9月开放，但年份、当前开放范围和具体酒店可用性尚未核验。', '\n\n',
  '## 五维模型', '\n',
  '- 信息分：图片/视频、设施描述、政策准确、信息真实。', '\n',
  '- 友好度：价格合理、房态准确/充足、取消政策灵活。', '\n',
  '- 品质度：订单确认、投诉、点评、用户权益、服务缺陷。', '\n',
  '- 欢迎度：携程渠道历史订单、销售额、成交率及反刷单。', '\n',
  '- 平台技术服务费：合理技术服务费、无逾期账单。', '\n\n',
  '## 使用边界', '\n',
  '这是规划期平台经营知识，不是本店得分、排序算法或当前经营事实。不得据此推断权重、计算评分、自动调价、创建任务或写入OTA/PMS。', '\n\n',
  '## 监管背景', '\n',
  '市场监管总局已核实2026-07-25对携程作出反垄断行政处罚并责令全面整改；雷达图是否属于处罚直接要求的整改项目仍未证实。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @ctrip_radar_unit_name, @ctrip_radar_staff_content,
  '携程,酒店经营雷达图,信息分,友好度,品质度,欢迎度,平台技术服务费,eBooking,反垄断,规划期',
  JSON_ARRAY('携程', '酒店经营雷达图', '五维模型', '规划期', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @ctrip_radar_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @ctrip_radar_staff_content,
  `keywords` = '携程,酒店经营雷达图,信息分,友好度,品质度,欢迎度,平台技术服务费,eBooking,反垄断,规划期',
  `tags` = JSON_ARRAY('携程', '酒店经营雷达图', '五维模型', '规划期', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @ctrip_radar_unit_name;
