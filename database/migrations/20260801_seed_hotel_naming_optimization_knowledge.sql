-- Seed reusable hotel/store and room-type naming knowledge from the user-provided workbook.
-- This is a global, unverified reference: it contains no current-hotel facts and grants no write authority.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @hotel_naming_version := '2026-08-01.1';
SET @hotel_naming_reviewed_at := '2026-08-01 00:00:00';
SET @hotel_naming_review_due_at := '2027-01-28 00:00:00';
SET @hotel_naming_seed_owner := 'suxios.hotel_naming_knowledge';
SET @hotel_naming_unit_name := '酒店门店与房型命名优化知识';
SET @hotel_naming_source := 'hotel_naming_optimization';
SET @hotel_naming_sha256 := '459F49569DE5AD1154631BF35B2C91EB4D7095BC67E2E744A430EAF61AF981DA';
SET @hotel_naming_description := '基于用户提供的房型命名模板沉淀酒店、民宿、门店与房型命名方法。该知识仅用于生成和人工评审候选名称，不代表当前酒店事实，不证明具体转化提升，也不授权自动修改OTA或PMS。';
SET @hotel_naming_source_manifest := JSON_OBJECT(
  'material_type', 'user_provided_xlsx',
  'file_name', '房型梳理.xlsx',
  'sha256', @hotel_naming_sha256,
  'reviewed_at', '2026-08-01',
  'sheet_names', JSON_ARRAY('房型梳理SOP', '房型名称'),
  'extraction_status', 'static_text_extraction_succeeded',
  'business_verification_status', 'not_verified_against_current_hotel_or_ota',
  'conversion_experiment_status', 'not_provided'
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @hotel_naming_unit_name,
  @hotel_naming_source,
  'done',
  @hotel_naming_description,
  JSON_ARRAY('酒店命名', '门店命名', '民宿命名', '房型命名', '截图优化', '人工复核', 'global_reference'),
  0,
  'active',
  'user_provided_naming_reference_with_explicit_fact_and_conversion_boundaries',
  @hotel_naming_reviewed_at,
  @hotel_naming_review_due_at,
  JSON_ARRAY(
    '来源工作簿包含房型分类、命名词汇和示例结构，共两个可见工作表。',
    '房型名称需要保留可识别的标准房型，并优先表达一个真实且影响选择的卖点。',
    '门店命名与房型命名解决不同问题，必须分别设计和评估。',
    '命名只是影响选择的信息变量，不能替代图片、价格、点评、权益、库存和取消政策。'
  ),
  JSON_ARRAY(
    '当前没有绑定任何酒店、门店、平台、业务日期或OTA房型ID。',
    '没有提供命名前后的受控转化实验结果。',
    '没有核验任何平台的当前命名规则、商标、工商名称或重名情况。',
    '未来截图中的具体名称、设施和可见事实尚未知。'
  ),
  @hotel_naming_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @hotel_naming_unit_name AND `source` = @hotel_naming_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @hotel_naming_description,
  `tags` = JSON_ARRAY('酒店命名', '门店命名', '民宿命名', '房型命名', '截图优化', '人工复核', 'global_reference'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_naming_reference_with_explicit_fact_and_conversion_boundaries',
  `reviewed_at` = @hotel_naming_reviewed_at,
  `review_due_at` = @hotel_naming_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '来源工作簿包含房型分类、命名词汇和示例结构，共两个可见工作表。',
    '房型名称需要保留可识别的标准房型，并优先表达一个真实且影响选择的卖点。',
    '门店命名与房型命名解决不同问题，必须分别设计和评估。',
    '命名只是影响选择的信息变量，不能替代图片、价格、点评、权益、库存和取消政策。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '当前没有绑定任何酒店、门店、平台、业务日期或OTA房型ID。',
    '没有提供命名前后的受控转化实验结果。',
    '没有核验任何平台的当前命名规则、商标、工商名称或重名情况。',
    '未来截图中的具体名称、设施和可见事实尚未知。'
  ),
  `truth_profile_version` = @hotel_naming_version,
  `updated_at` = NOW()
WHERE `name` = @hotel_naming_unit_name AND `source` = @hotel_naming_source;

SET @hotel_naming_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @hotel_naming_unit_name AND `source` = @hotel_naming_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_hotel_naming_chunks`;
CREATE TEMPORARY TABLE `tmp_hotel_naming_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_hotel_naming_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_hotel_naming_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hotel_naming_unit_id, 'hotel_naming_source_scope_reference', JSON_OBJECT(
  'scope', 'global_industry_reference_unverified',
  'evidence_level', 'user_provided_unverified_reference',
  'evidence_grade', 'D',
  'source_refs', JSON_ARRAY(CONCAT('user-file://房型梳理.xlsx#sha256=', @hotel_naming_sha256)),
  'observed_facts', JSON_ARRAY(
    '工作簿有房型梳理SOP和房型名称两个可见工作表。',
    '内容覆盖房型结构、床型、景观、设施、风格、面积、位置、业态、等级形容词和常用命名结构。'
  ),
  'source_limitations', JSON_ARRAY(
    '没有酒店身份、平台、业务日期、房量、OTA房型ID和审批状态。',
    '确定房型列没有形成可直接执行的真实酒店映射。',
    '没有转化率实验或线上结果。'
  ),
  'non_claims', JSON_ARRAY(
    '不把模板内容写成当前酒店事实。',
    '不声称工作簿词汇必然提升转化。',
    '不授权把候选名自动写入OTA或PMS。'
  )
), 0, NOW()
WHERE @hotel_naming_unit_id IS NOT NULL;

INSERT INTO `tmp_hotel_naming_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hotel_naming_unit_id, 'room_type_naming_taxonomy', JSON_OBJECT(
  'scope', 'global_industry_reference_unverified',
  'evidence_level', 'user_provided_unverified_reference',
  'evidence_grade', 'D',
  'source_refs', JSON_ARRAY(CONCAT('user-file://房型梳理.xlsx#sha256=', @hotel_naming_sha256)),
  'recommended_formula', 'one_verified_high_intent_selling_point_plus_standard_room_type',
  'standard_room_types', JSON_ARRAY('大床房', '双床房', '家庭房', '亲子房', '套房', '一居室', '二居室', '复式', 'Loft', '榻榻米房'),
  'fact_dimensions', JSON_ARRAY('结构', '床型', '景观', '设施', '风格', '面积', '位置', '楼层', '业态'),
  'high_intent_terms_when_verified', JSON_ARRAY('私汤', '泡池', '投影', '观影', '智能', '电竞', '棋牌', '亲子', '露台', '阳台', '庭院', '花园', '观山', '海景', '湖景', '舒睡'),
  'low_information_terms', JSON_ARRAY('雅致', '悦享', '精选', '臻选', '轻奢', '尊享'),
  'rules', JSON_ARRAY(
    '诗意前缀之后仍保留标准房型。',
    '同店等级词必须对应可解释的实际权益差异。',
    '名称只承载一到两个最强卖点，其余进入详情描述。',
    '未确认的景观、设施、面积和权益不得进入名称。'
  )
), 0, NOW()
WHERE @hotel_naming_unit_id IS NOT NULL;

INSERT INTO `tmp_hotel_naming_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hotel_naming_unit_id, 'hotel_store_naming_contract', JSON_OBJECT(
  'scope', 'global_industry_reference_unverified',
  'evidence_level', 'user_provided_unverified_reference',
  'evidence_grade', 'D',
  'source_refs', JSON_ARRAY('derived://suxios-hotel-naming-method#2026-08-01'),
  'task_boundary', 'hotel_store_naming_is_separate_from_room_type_naming',
  'required_inputs', JSON_ARRAY('品牌锚点', '真实位置或商圈', '业态', '核心客群', '可兑现卖点', '既有品牌资产'),
  'recommended_formulas', JSON_ARRAY(
    '品牌锚点+已确认地点或商圈+品类',
    '记忆词+已确认场景或景观+品类',
    '品牌锚点+核心定位+品类'
  ),
  'review_rules', JSON_ARRAY(
    '优先确保品类清晰、易读易记和位置不误导。',
    '未查询时把商标、工商、平台重名和禁限词状态保持为待核验。',
    '不复制竞品品牌，也不借用未经证实的地点或卖点。'
  )
), 0, NOW()
WHERE @hotel_naming_unit_id IS NOT NULL;

INSERT INTO `tmp_hotel_naming_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hotel_naming_unit_id, 'screenshot_naming_optimization_contract', JSON_OBJECT(
  'scope', 'global_industry_reference_unverified',
  'evidence_level', 'user_provided_unverified_reference',
  'evidence_grade', 'D',
  'source_refs', JSON_ARRAY('workflow://future-user-screenshot'),
  'workflow', JSON_ARRAY(
    '识别对象是门店名还是房型名。',
    '逐项记录截图中清晰可见的原名、品类、设施、景观、位置和层级。',
    '把无法辨认和未展示的信息标记为未知。',
    '分别给出保守优化版和同风格相似版。',
    '说明每个推荐词对应的可见证据或用户确认事实。'
  ),
  'output_contract', JSON_ARRAY('一个首选名称', '三到五个备选', '命名依据', '评分', '待确认项', '验证建议'),
  'similarity_rule', 'reuse_structure_rhythm_and_tone_only_never_unverified_competitor_claims'
), 0, NOW()
WHERE @hotel_naming_unit_id IS NOT NULL;

INSERT INTO `tmp_hotel_naming_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @hotel_naming_unit_id, 'naming_conversion_evaluation_contract', JSON_OBJECT(
  'scope', 'global_industry_reference_unverified',
  'evidence_level', 'user_provided_unverified_reference',
  'evidence_grade', 'D',
  'source_refs', JSON_ARRAY('derived://suxios-hotel-naming-evaluation#2026-08-01'),
  'score_weights', JSON_OBJECT('clarity', 25, 'fact_support', 25, 'intent_match', 20, 'differentiation', 15, 'brevity', 10, 'hierarchy_consistency', 5),
  'penalties', JSON_ARRAY('编造事实', '纯诗意且无标准品类', '同义词堆叠', '无基础房型', '等级与权益不一致'),
  'experiment_metrics', JSON_ARRAY('曝光到详情点击率', '详情到预订转化率', '房型售卖占比', '取消率', '每千次曝光收入'),
  'experiment_controls', JSON_ARRAY('同房型', '同价位', '同流量来源', '相近日期', '图片权益库存等主要变量尽量不变'),
  'causality_rule', 'do_not_claim_conversion_uplift_without_matched_or_controlled_experiment'
), 0, NOW()
WHERE @hotel_naming_unit_id IS NOT NULL;

UPDATE `tmp_hotel_naming_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('hotel_naming:', `type`),
  '$.content_type', 'naming_reference_contract',
  '$.module_id', 'hotel_naming',
  '$.platforms', JSON_ARRAY('suxios_internal'),
  '$.roles', JSON_ARRAY('owner', 'operator', 'revenue_manager'),
  '$.scenes', JSON_ARRAY('hotel_store_naming', 'room_type_naming', 'screenshot_naming_review', 'conversion_experiment_design'),
  '$.source_manifest', JSON_EXTRACT(@hotel_naming_source_manifest, '$'),
  '$.reviewed_at', @hotel_naming_reviewed_at,
  '$.review_due_at', @hotel_naming_review_due_at,
  '$.review_interval_days', 180,
  '$.freshness_policy', 'review_due_reference_only',
  '$.allowed_uses', JSON_ARRAY('candidate_name_generation', 'manual_name_review', 'screenshot_based_name_analysis', 'controlled_test_design'),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'operation_task_creation', 'operation_execution', 'automatic_ota_write', 'automatic_pms_write', 'conversion_uplift_claim'),
  '$.seed_owner', @hotel_naming_seed_owner,
  '$.seed_key', CONCAT('hotel_naming:', `type`),
  '$.seed_version', @hotel_naming_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_hotel_naming_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_hotel_naming_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_hotel_naming_chunks`;

SET @hotel_naming_staff_content := CONCAT(
  '# 酒店门店与房型命名优化知识', '\n\n',
  '## 使用边界', '\n',
  '这是基于用户提供模板沉淀的系统级通用参考，不是当前酒店事实，也不证明某个名称必然提升转化。', '\n\n',
  '## 门店命名', '\n',
  '门店名优先表达品牌锚点、真实位置或场景和品类；商标、重名、平台规则与工商状态必须另行核验。', '\n\n',
  '## 房型命名', '\n',
  '优先使用一个真实高意图卖点加标准房型。诗意前缀不能代替大床房、双床房、家庭房、套房等标准后缀。', '\n\n',
  '## 截图优化', '\n',
  '只使用截图中清晰可见或用户确认的信息；模糊、遮挡和未展示字段保持未知，并给保守优化版与同风格相似版。', '\n\n',
  '## 转化验证', '\n',
  '在同房型、同价位、同流量来源和相近日期下进行受控测试，观察点击率、预订转化率、售卖占比、取消率和每千次曝光收入。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @hotel_naming_unit_name, @hotel_naming_staff_content,
  '酒店命名,门店命名,民宿命名,房型命名,房型名称,截图优化,相似名称,转化测试',
  JSON_ARRAY('酒店命名', '门店命名', '房型命名', '截图优化', 'manual_review_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @hotel_naming_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @hotel_naming_staff_content,
  `keywords` = '酒店命名,门店命名,民宿命名,房型命名,房型名称,截图优化,相似名称,转化测试',
  `tags` = JSON_ARRAY('酒店命名', '门店命名', '房型命名', '截图优化', 'manual_review_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @hotel_naming_unit_name;
