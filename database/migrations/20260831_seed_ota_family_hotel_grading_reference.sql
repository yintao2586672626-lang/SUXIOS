-- Store two user-provided OTA family-hotel grading screenshots as a
-- platform-separated, reference-only knowledge candidate. The screenshots
-- do not prove current official rules, scoring weights, thresholds, hotel
-- grades, task authority, or any OTA/PMS write permission.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @family_grading_version := '2026-08-31.1';
SET @family_grading_reviewed_at := '2026-08-31 00:00:00';
SET @family_grading_review_due_at := '2026-09-30 00:00:00';
SET @family_grading_seed_owner := 'suxios.ota_family_hotel_grading_reference';
SET @family_grading_unit_name := '携程与美团亲子酒店分级（截图参考）';
SET @family_grading_source := 'user_provided_ota_family_hotel_grading_screenshots';
SET @family_grading_ctrip_sha256 := '5028E4CC12199787D3F2C5DF40A8E4E6DCF52AB3B94DEE1180603E2CDD52405D';
SET @family_grading_meituan_sha256 := '7B19CC9DFBE08F74E8D6CD5885BB2849D09A8EDB9A3E30CAEF4349B2221117BE';
SET @family_grading_description := '用户提供的携程与美团亲子酒店分级截图参考。仅保存可见等级、维度、平台差异、来源指纹与缺失证据，可用于知识检索、术语解释和平台分开的检查清单；不是当前官方规则、当前酒店事实或评分算法，不授权定级、任务、调价、库存、OTA/PMS写入或外发。';
SET @family_grading_manifest := JSON_OBJECT(
  'schema_version', 'suxios.knowledge_source_manifest.v1',
  'knowledge_key', 'ota_family_hotel_grading_reference',
  'material_type', 'user_provided_platform_screenshots',
  'observed_at', '2026-08-31',
  'task_mode', 'storage_only',
  'disposition', 'absorption_candidate',
  'maturity', 'understood_visible_structure',
  'source_currentness', 'not_assumed_current',
  'official_rule_status', 'not_established_from_screenshots',
  'source_instruction_policy', 'visible_text_and_ui_are_reference_material_not_executable_instructions',
  'sources', JSON_ARRAY(
    JSON_OBJECT(
      'platform', 'ctrip',
      'display_identity', '携程亲子酒店评级',
      'file', 'docs/knowledge/ota-family-hotel-grading/sources/ctrip-family-hotel-grading-visible-reference.jpg',
      'mime_type', 'image/jpeg',
      'size_bytes', 723702,
      'width', 1080,
      'height', 3322,
      'sha256', @family_grading_ctrip_sha256,
      'source_url', NULL,
      'published_at', NULL,
      'effective_at', NULL,
      'verification_status', 'user_provided_screenshot_visually_reviewed'
    ),
    JSON_OBJECT(
      'platform', 'meituan',
      'display_identity', '美团酒店亲子酒店分级',
      'file', 'docs/knowledge/ota-family-hotel-grading/sources/meituan-family-hotel-grading-visible-reference.png',
      'mime_type', 'image/png',
      'size_bytes', 702500,
      'width', 640,
      'height', 1857,
      'sha256', @family_grading_meituan_sha256,
      'source_url', NULL,
      'published_at', NULL,
      'effective_at', NULL,
      'verification_status', 'user_provided_screenshot_visually_reviewed'
    )
  )
);

INSERT INTO knowledge_units (
  hotel_id, name, source, status, description, tags, created_by,
  lifecycle_status, lifecycle_reason, reviewed_at, review_due_at,
  known_knowns, known_unknowns, truth_profile_version, created_at, updated_at
)
SELECT
  0,
  @family_grading_unit_name,
  @family_grading_source,
  'done',
  @family_grading_description,
  JSON_ARRAY('亲子酒店', '携程', '美团', '平台分级', '服务维度', '跨平台口径', 'reference_only'),
  0,
  'active',
  'user_requested_storage_only_platform_separated_screenshot_reference',
  @family_grading_reviewed_at,
  @family_grading_review_due_at,
  JSON_ARRAY(
    '携程截图可见亲子酒店、A级、A+级三种等级文字和五个服务维度。',
    '美团截图可见A级、S级两种等级文字和四个分级衡量维度。',
    '两个平台都出现亲子设施与亲子活动标签，但截图描述和输入范围不同。',
    '原始截图文件、尺寸和SHA-256已在本地知识包保存。'
  ),
  JSON_ARRAY(
    '官方来源URL、发布日期、生效日期、适用地区和酒店范围未提供。',
    '准入条件、维度权重、评分阈值、数据窗口、刷新频率和撤销条件未提供。',
    '美团A级与S级是否为完整等级目录不能由截图证明。',
    '携程亲子活动与亲子服务的正式字段定义仍需官方规则核对。',
    '任何当前酒店的实际平台等级和达标状态均未提供。'
  ),
  @family_grading_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM knowledge_units
  WHERE name = @family_grading_unit_name AND source = @family_grading_source
);

UPDATE knowledge_units
SET
  hotel_id = 0,
  status = 'done',
  description = @family_grading_description,
  tags = JSON_ARRAY('亲子酒店', '携程', '美团', '平台分级', '服务维度', '跨平台口径', 'reference_only'),
  created_by = 0,
  lifecycle_status = 'active',
  lifecycle_reason = 'user_requested_storage_only_platform_separated_screenshot_reference',
  reviewed_at = @family_grading_reviewed_at,
  review_due_at = @family_grading_review_due_at,
  known_knowns = JSON_ARRAY(
    '携程截图可见亲子酒店、A级、A+级三种等级文字和五个服务维度。',
    '美团截图可见A级、S级两种等级文字和四个分级衡量维度。',
    '两个平台都出现亲子设施与亲子活动标签，但截图描述和输入范围不同。',
    '原始截图文件、尺寸和SHA-256已在本地知识包保存。'
  ),
  known_unknowns = JSON_ARRAY(
    '官方来源URL、发布日期、生效日期、适用地区和酒店范围未提供。',
    '准入条件、维度权重、评分阈值、数据窗口、刷新频率和撤销条件未提供。',
    '美团A级与S级是否为完整等级目录不能由截图证明。',
    '携程亲子活动与亲子服务的正式字段定义仍需官方规则核对。',
    '任何当前酒店的实际平台等级和达标状态均未提供。'
  ),
  truth_profile_version = @family_grading_version,
  updated_at = NOW()
WHERE name = @family_grading_unit_name AND source = @family_grading_source;

SET @family_grading_unit_id := (
  SELECT unit_id FROM knowledge_units
  WHERE name = @family_grading_unit_name AND source = @family_grading_source
  ORDER BY unit_id ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS tmp_ota_family_hotel_grading_chunks;
CREATE TEMPORARY TABLE tmp_ota_family_hotel_grading_chunks (
  unit_id INT NOT NULL,
  type VARCHAR(80) NOT NULL,
  content JSON NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tmp_ota_family_hotel_grading_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_ota_family_hotel_grading_chunks (unit_id, type, content, created_by, created_at)
SELECT @family_grading_unit_id, 'ctrip_family_hotel_grading_visible_reference', JSON_OBJECT(
  'scope', 'global_ota_platform_rule_reference',
  'evidence_level', 'user_provided_screenshot_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT(
    'repo-doc://docs/knowledge/ota-family-hotel-grading/sources/ctrip-family-hotel-grading-visible-reference.jpg#sha256=',
    @family_grading_ctrip_sha256
  )),
  'platforms', JSON_ARRAY('ctrip'),
  'display_identity', '携程亲子酒店评级',
  'visible_level_count', 3,
  'visible_levels', JSON_ARRAY('亲子酒店', 'A级', 'A+级'),
  'level_catalog_status', 'three_levels_visible_in_source_not_assumed_current',
  'visible_dimensions', JSON_ARRAY(
    JSON_OBJECT(
      'key', 'family_facilities',
      'label', '亲子设施',
      'visible_description_summary', '儿童乐园、儿童俱乐部、水上乐园、儿童泳池、海滩等设施，并结合用户对设施的评价。'
    ),
    JSON_OBJECT(
      'key', 'family_activities',
      'label', '亲子活动',
      'visible_description_summary', '截图文字提到用户对亲子托管等服务的评价分；标签与描述的精确平台定义仍待官方规则核对。'
    ),
    JSON_OBJECT(
      'key', 'family_services',
      'label', '亲子服务',
      'visible_description_summary', '动物、手工体验、运动、游戏、研学等亲子活动，并结合用户评价。'
    ),
    JSON_OBJECT(
      'key', 'family_recognition',
      'label', '亲子认可度',
      'visible_description_summary', '结合亲子订单占比和亲子用户点评分等综合计分。'
    ),
    JSON_OBJECT(
      'key', 'nearby_family_attractions_3km',
      'label', '3公里内的景点',
      'visible_description_summary', '酒店附近3公里内适合亲子游玩的景点数量和热度。'
    )
  ),
  'visual_artifact_notes', JSON_ARRAY(
    '长截图在滚动拼接处重复出现一次亲子服务卡片，按页面标题五大服务维度保存，不解释为第六维。',
    '截图未提供权重、阈值、数据周期、等级计算方法或当前生效日期。'
  ),
  'unverified_items', JSON_ARRAY(
    '官方来源URL与发布主体页面',
    '发布日期、生效日期、适用地区与酒店范围',
    '准入条件、权重、阈值、数据窗口与刷新频率',
    '亲子活动与亲子服务标签及描述的正式字段定义',
    '任一当前酒店的实际等级和达标状态'
  ),
  'scoring_status', 'not_available_from_screenshot',
  'disposition', 'absorption_candidate',
  'task_mode', 'storage_only',
  'maturity', 'understood_visible_structure'
), 0, NOW()
WHERE @family_grading_unit_id IS NOT NULL;

INSERT INTO tmp_ota_family_hotel_grading_chunks (unit_id, type, content, created_by, created_at)
SELECT @family_grading_unit_id, 'meituan_family_hotel_grading_visible_reference', JSON_OBJECT(
  'scope', 'global_ota_platform_rule_reference',
  'evidence_level', 'user_provided_screenshot_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT(
    'repo-doc://docs/knowledge/ota-family-hotel-grading/sources/meituan-family-hotel-grading-visible-reference.png#sha256=',
    @family_grading_meituan_sha256
  )),
  'platforms', JSON_ARRAY('meituan'),
  'display_identity', '美团酒店亲子酒店分级',
  'visible_level_count', 2,
  'visible_levels', JSON_ARRAY('A级', 'S级'),
  'level_catalog_status', 'two_levels_visible_not_proven_complete_or_current',
  'visible_dimensions', JSON_ARRAY(
    JSON_OBJECT(
      'key', 'stay_experience',
      'label', '居住体验',
      'visible_description_summary', '房间空间、儿童主题装修、儿童专属用品，并结合用户点评总分、卫生分和位置分。'
    ),
    JSON_OBJECT(
      'key', 'dining_experience',
      'label', '饮食体验',
      'visible_description_summary', '酒店餐厅、专属儿童餐、儿童餐椅等配置，并结合餐饮亲子友好度。'
    ),
    JSON_OBJECT(
      'key', 'family_facilities',
      'label', '亲子设施',
      'visible_description_summary', '儿童乐园、儿童俱乐部、儿童泳池、水上乐园、水上滑梯、大型游乐场等，并结合用户真实体验评价。'
    ),
    JSON_OBJECT(
      'key', 'family_activities',
      'label', '亲子活动',
      'visible_description_summary', '飞盘、风筝、手工制作、采摘、攀岩、骑马、网球、水上运动等互动活动，并结合参与感与体验评价。'
    )
  ),
  'service_guarantees_visible_but_not_rating_dimensions', JSON_ARRAY('入住保障', '退订保障', '专业客服'),
  'visual_artifact_notes', JSON_ARRAY(
    '服务保障位于分级维度之外，不把它们自动解释为评分因子。',
    '截图未提供权重、阈值、数据周期、等级计算方法、完整等级目录或当前生效日期。'
  ),
  'unverified_items', JSON_ARRAY(
    '官方来源URL与发布主体页面',
    '发布日期、生效日期、适用地区与酒店范围',
    '准入条件、权重、阈值、数据窗口与刷新频率',
    'A级与S级是否为完整等级目录',
    '任一当前酒店的实际等级和达标状态'
  ),
  'scoring_status', 'not_available_from_screenshot',
  'disposition', 'absorption_candidate',
  'task_mode', 'storage_only',
  'maturity', 'understood_visible_structure'
), 0, NOW()
WHERE @family_grading_unit_id IS NOT NULL;

INSERT INTO tmp_ota_family_hotel_grading_chunks (unit_id, type, content, created_by, created_at)
SELECT @family_grading_unit_id, 'family_hotel_grading_cross_platform_boundary', JSON_OBJECT(
  'scope', 'global_ota_platform_rule_reference',
  'evidence_level', 'user_provided_screenshot_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT(
      'repo-doc://docs/knowledge/ota-family-hotel-grading/sources/ctrip-family-hotel-grading-visible-reference.jpg#sha256=',
      @family_grading_ctrip_sha256
    ),
    CONCAT(
      'repo-doc://docs/knowledge/ota-family-hotel-grading/sources/meituan-family-hotel-grading-visible-reference.png#sha256=',
      @family_grading_meituan_sha256
    )
  ),
  'platforms', JSON_ARRAY('ctrip', 'meituan'),
  'platform_identity_required', true,
  'grade_conversion_allowed', false,
  'shared_labels_are_not_shared_metrics', JSON_ARRAY('亲子设施', '亲子活动'),
  'visible_structure_comparison', JSON_OBJECT(
    'ctrip', JSON_OBJECT(
      'visible_levels', JSON_ARRAY('亲子酒店', 'A级', 'A+级'),
      'visible_dimensions', JSON_ARRAY('亲子设施', '亲子活动', '亲子服务', '亲子认可度', '3公里内的景点')
    ),
    'meituan', JSON_OBJECT(
      'visible_levels', JSON_ARRAY('A级', 'S级'),
      'visible_dimensions', JSON_ARRAY('居住体验', '饮食体验', '亲子设施', '亲子活动')
    )
  ),
  'cross_platform_rules', JSON_ARRAY(
    '携程和美团的等级字母不得直接换算或横向比较。',
    '同名维度必须保留平台、来源截图、观察日期和各自描述，不能合并成一个统一数值字段。',
    '截图只证明可见分级结构和宣传性说明，不证明隐藏评分算法、权重、阈值或当前平台规则。',
    '本参考不能证明任何当前酒店已经获得或应当获得某一等级。'
  ),
  'allowed_uses_summary', JSON_ARRAY('知识检索', '术语解释', '平台分开的差距检查清单', '待补证据问题', '来源升级复核'),
  'scoring_status', 'cross_platform_scoring_prohibited',
  'disposition', 'absorption_candidate',
  'task_mode', 'storage_only',
  'maturity', 'understood_visible_structure'
), 0, NOW()
WHERE @family_grading_unit_id IS NOT NULL;

UPDATE tmp_ota_family_hotel_grading_chunks
SET content = JSON_SET(
  content,
  '$.content_key', CONCAT('ota_family_hotel_grading_reference:', type),
  '$.content_type', 'ota_family_hotel_grading_screenshot_reference',
  '$.module_id', 'ota_family_hotel_grading_reference',
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'ota_operator', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('knowledge_search', 'platform_rule_explanation', 'platform_specific_gap_checklist_draft', 'source_upgrade_review'),
  '$.source_manifest', JSON_EXTRACT(@family_grading_manifest, '$'),
  '$.reviewed_at', @family_grading_reviewed_at,
  '$.review_due_at', @family_grading_review_due_at,
  '$.review_interval_days', 30,
  '$.freshness_policy', 'screenshot_reference_only_until_official_current_rule_and_platform_scope_verification',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_verified_as_current_official_rule',
  '$.allowed_uses', JSON_ARRAY(
    'knowledge_search',
    'platform_specific_terminology_explanation',
    'platform_specific_gap_checklist_draft',
    'missing_evidence_questions',
    'source_upgrade_review'
  ),
  '$.blocked_uses', JSON_ARRAY(
    'current_hotel_fact',
    'current_official_rule_claim',
    'automatic_grade_assignment',
    'cross_platform_grade_conversion',
    'hotel_score_calculation',
    'ranking_prediction',
    'operation_task_creation',
    'operation_execution',
    'automatic_pricing',
    'automatic_inventory_change',
    'automatic_ota_write',
    'automatic_pms_write',
    'external_message'
  ),
  '$.seed_owner', @family_grading_seed_owner,
  '$.seed_key', CONCAT('ota_family_hotel_grading_reference:', type),
  '$.seed_version', @family_grading_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.external_write_authorized', false
);

UPDATE knowledge_chunks AS existing
INNER JOIN tmp_ota_family_hotel_grading_chunks AS seed
  ON existing.unit_id = seed.unit_id
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(existing.content) = 1 THEN existing.content ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = JSON_UNQUOTE(JSON_EXTRACT(seed.content, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(existing.content) = 1 THEN existing.content ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(seed.content, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(existing.content) = 1 THEN existing.content ELSE JSON_OBJECT() END,
    '$.seed_version'
  )) = JSON_UNQUOTE(JSON_EXTRACT(seed.content, '$.seed_version'))
SET
  existing.type = seed.type,
  existing.content = seed.content,
  existing.created_by = seed.created_by;

INSERT INTO knowledge_chunks (unit_id, type, content, created_by, created_at)
SELECT seed.unit_id, seed.type, seed.content, seed.created_by, seed.created_at
FROM tmp_ota_family_hotel_grading_chunks AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM knowledge_chunks AS existing
  WHERE existing.unit_id = seed.unit_id
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(existing.content) = 1 THEN existing.content ELSE JSON_OBJECT() END,
      '$.seed_owner'
    )) = JSON_UNQUOTE(JSON_EXTRACT(seed.content, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(existing.content) = 1 THEN existing.content ELSE JSON_OBJECT() END,
      '$.seed_key'
    )) = JSON_UNQUOTE(JSON_EXTRACT(seed.content, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(existing.content) = 1 THEN existing.content ELSE JSON_OBJECT() END,
      '$.seed_version'
    )) = JSON_UNQUOTE(JSON_EXTRACT(seed.content, '$.seed_version'))
);

DROP TEMPORARY TABLE tmp_ota_family_hotel_grading_chunks;

SET @family_grading_staff_content := CONCAT(
  '# 携程与美团亲子酒店分级（截图参考）', '\n\n',
  '## 可见结构', '\n',
  '携程截图：亲子酒店、A级、A+级；亲子设施、亲子活动、亲子服务、亲子认可度、3公里内的景点。', '\n',
  '美团截图：A级、S级；居住体验、饮食体验、亲子设施、亲子活动。', '\n\n',
  '## 使用边界', '\n',
  '两平台等级字母不得直接换算。同名维度不等于同一指标，必须保留平台和来源。截图没有证明权重、阈值、数据周期、当前生效规则或任一酒店等级。', '\n\n',
  '## 允许', '\n',
  '知识检索、术语解释、平台分开的差距检查清单、待补证据问题与来源升级复核。', '\n\n',
  '## 禁止', '\n',
  '不得自动给酒店定级，不得生成统一分数、排名预测或经营任务，不得调价、改库存、写OTA/PMS或外发。'
);

INSERT INTO knowledge_base (
  tenant_id, hotel_id, category_id, title, content, keywords, tags,
  sort_order, is_enabled, view_count, like_count, create_time, update_time
)
SELECT
  0,
  0,
  7,
  @family_grading_unit_name,
  @family_grading_staff_content,
  '亲子酒店,携程亲子酒店评级,美团亲子酒店分级,A级,A+级,S级,亲子设施,亲子活动,亲子服务,亲子认可度,3公里景点,居住体验,饮食体验,跨平台口径',
  JSON_ARRAY('亲子酒店', '携程', '美团', '平台分级', 'reference_only'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM knowledge_base
  WHERE hotel_id = 0 AND title = @family_grading_unit_name
);

UPDATE knowledge_base
SET
  tenant_id = 0,
  category_id = 7,
  content = @family_grading_staff_content,
  keywords = '亲子酒店,携程亲子酒店评级,美团亲子酒店分级,A级,A+级,S级,亲子设施,亲子活动,亲子服务,亲子认可度,3公里景点,居住体验,饮食体验,跨平台口径',
  tags = JSON_ARRAY('亲子酒店', '携程', '美团', '平台分级', 'reference_only'),
  is_enabled = 1,
  update_time = NOW()
WHERE hotel_id = 0 AND title = @family_grading_unit_name;
