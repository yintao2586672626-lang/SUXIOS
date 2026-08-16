-- Absorb the user-provided 18-page "Ctrip new traffic rules" PDF as a low-confidence
-- third-party conflict reference. The deck is signed/watermarked by Shuke, has no official
-- publisher or effective date, and cannot establish a current ranking, commission, or rollout rule.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_flow_pdf_version := '2026-08-11.4';
SET @ctrip_flow_pdf_reviewed_at := '2026-08-11 00:00:00';
SET @ctrip_flow_pdf_review_due_at := '2026-08-18 00:00:00';
SET @ctrip_flow_pdf_seed_owner := 'suxios.ctrip_flow_rules_pdf_20260811';
SET @ctrip_flow_pdf_unit_name := '携程酒店经营雷达图（规划期）五维知识合同';
SET @ctrip_flow_pdf_source := 'revenue_operations_decision_support';
SET @ctrip_flow_pdf_document_path := 'docs/ctrip_hotel_flow_new_rules_pdf_20260811.md';
SET @ctrip_flow_pdf_document_sha256 := 'A8056DB215C068C5223346729408A2544E21E1CB229B435D17346C1E97CC55FC';
SET @ctrip_flow_pdf_source_filename := '携程流量新规则2026.8.pdf';
SET @ctrip_flow_pdf_source_sha256 := '6FFA5FB517F418F11E78C6AD221493C83DD94AC0D90B7AC07D25173683F69A7D';
SET @ctrip_flow_pdf_samr_url := 'https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html';
SET @ctrip_flow_pdf_rectification_url := 'https://jingji.cctv.com/2026/07/25/ARTI43yXusLYVp6aGHhJUNAS260725.shtml';
SET @ctrip_flow_pdf_doc_ref := CONCAT(
  'repo-doc://', @ctrip_flow_pdf_document_path, '#sha256=', @ctrip_flow_pdf_document_sha256
);
SET @ctrip_flow_pdf_source_ref := CONCAT(
  'user-file://', @ctrip_flow_pdf_source_filename, '#sha256=', @ctrip_flow_pdf_source_sha256
);
SET @ctrip_flow_pdf_manifest := JSON_OBJECT(
  'material_type', 'user_provided_unverified_third_party_training_deck',
  'normalized_document_path', @ctrip_flow_pdf_document_path,
  'normalized_document_sha256', @ctrip_flow_pdf_document_sha256,
  'source_filename', @ctrip_flow_pdf_source_filename,
  'source_sha256', @ctrip_flow_pdf_source_sha256,
  'source_size_bytes', 2153103,
  'page_count', 18,
  'pdf_created_at', '2026-08-08 16:20:16+08:00',
  'pdf_modified_at', '2026-08-08 16:20:22+08:00',
  'pdf_creator', 'WPS 演示',
  'visible_signature', '舒克',
  'visible_watermark', 'shuke',
  'official_publisher_status', 'not_established',
  'original_url_status', 'not_provided',
  'official_publish_date_status', 'not_provided',
  'effective_date_status', 'not_provided',
  'filename_date_guard', 'filename_2026_8_and_pdf_metadata_do_not_establish_official_publish_or_effective_date',
  'visual_review_status', 'all_18_pages_rendered_and_inspected',
  'text_review_status', 'extractable_text_reviewed_on_all_18_pages',
  'public_exact_claim_search_status', 'no_primary_source_found_for_pdf_unique_exact_claims',
  'related_modules', JSON_ARRAY('ctrip_hotel_operating_radar', 'ctrip_commission_reform_watch'),
  'official_cross_check_sources', JSON_ARRAY(@ctrip_flow_pdf_samr_url, @ctrip_flow_pdf_rectification_url)
);

SET @ctrip_flow_pdf_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ctrip_flow_pdf_unit_name
    AND `source` = @ctrip_flow_pdf_source
  ORDER BY `unit_id` ASC LIMIT 1
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = '携程酒店经营雷达图规划期知识已补充一份18页第三方流量新规则演示稿的逐页审计。该PDF由舒克署名并带shuke水印，无携程官方发布者、来源链接、生效日期或版本号；只用于发现待核验字段、内部冲突和eBooking现场检查项。PDF独有的4.7阈值、流量乘法公式、五项能力、双流量池、佣金档位、优选标签和工具下线状态均不得作为当前规则、排名预测或执行依据。',
  `tags` = JSON_ARRAY(
    '携程', '酒店经营雷达图', '流量新规则', '信息分', '友好度', '品质度', '欢迎度',
    '平台技术服务费', '第三方材料', '口径冲突', '规划期', 'global_reference'
  ),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'third_party_flow_rules_pdf_absorbed_as_unverified_conflict_reference',
  `reviewed_at` = @ctrip_flow_pdf_reviewed_at,
  `review_due_at` = @ctrip_flow_pdf_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '用户材料描述信息分、友好度、品质度、欢迎度、平台技术服务费五个维度，且称单一维度不决定最终结果。',
    '市场监管总局于2026-07-25对携程作出反垄断行政处罚并责令停止违法行为和全面整改。',
    '携程于2026-07-25公开五方面十九项整改措施，承诺重建流量分配机制、停止最低价和平台擅自调价、建立新佣金模式并提升规则透明度。',
    '现行平台价格规则保护酒店跨平台自主定价，并禁止强制自动跟价、自动降价和以限流降权等手段限制价格。',
    '现行平台规则要求规则公示、意见征集、历史版本保存、处罚理由告知和便捷申诉。',
    '可公开访问的携程酒店商家经营规则显示最新版本为2025-11-03，早于2026-07-25处罚和整改公告。',
    '本次补充PDF共18页，SHA-256为6FFA5FB517F418F11E78C6AD221493C83DD94AC0D90B7AC07D25173683F69A7D；可见舒克署名和shuke水印，未见携程官方发布身份或来源页。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '公开检索仍未找到与用户材料完全对应且可独立访问的携程雷达图原始发布页。',
    '雷达图是否已向任一具体酒店开放、9月所属年份、覆盖范围和分批节奏未核验。',
    '五维权重、阈值、公式、刷新频率、排序影响和流量效果未公开核验。',
    '技术服务费与新佣金模式的字段关系、计费口径和是否参与评分未核验。',
    '十九项整改属于携程公开承诺；各项措施的实际完成效果尚未独立验收。',
    '监管文件和整改公告均未直接说明酒店经营雷达图是处罚决定要求的整改项目。',
    'PDF中的4.7点评分水岭、近7或30天经营力指标、流量权重乘法公式和自然/广告双流量池均无平台原始规则支持。',
    'PDF中的10%至15%佣金、12%携程优选、实际返后佣金排序、云梯和定向加速包下线均需当前eBooking或合同复核。'
  ),
  `truth_profile_version` = @ctrip_flow_pdf_version,
  `updated_at` = NOW()
WHERE `unit_id` = @ctrip_flow_pdf_unit_id;

DROP TEMPORARY TABLE IF EXISTS `tmp_ctrip_flow_pdf_chunks`;
CREATE TEMPORARY TABLE `tmp_ctrip_flow_pdf_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_ctrip_flow_pdf_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_ctrip_flow_pdf_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_flow_pdf_unit_id, 'ctrip_flow_rules_pdf_source_audit_reference', JSON_OBJECT(
  'scope', 'known_unknown',
  'evidence_level', 'user_provided_unverified_third_party_training_deck',
  'evidence_grade', 'D',
  'source_refs', JSON_ARRAY(@ctrip_flow_pdf_doc_ref, @ctrip_flow_pdf_source_ref),
  'document_identity', JSON_OBJECT(
    'filename', @ctrip_flow_pdf_source_filename,
    'sha256', @ctrip_flow_pdf_source_sha256,
    'size_bytes', 2153103,
    'page_count', 18,
    'created_at', '2026-08-08 16:20:16+08:00',
    'modified_at', '2026-08-08 16:20:22+08:00',
    'creator', 'WPS 演示',
    'author', NULL,
    'title', NULL,
    'company', NULL,
    'visible_signature', '舒克',
    'visible_watermark', 'shuke'
  ),
  'review_coverage', JSON_OBJECT(
    'page_count', 18,
    'rendered_page_count', 18,
    'visually_inspected_page_count', 18,
    'text_inspected_page_count', 18
  ),
  'officiality_status', 'not_established_as_ctrip_official_publication',
  'date_status', 'no_official_publish_or_effective_date_in_document',
  'quality_warnings', JSON_ARRAY(
    'no_ctrip_or_trip_com_group_official_signature',
    'no_source_url_notice_number_version_or_effective_date',
    'repeated_shuke_watermark',
    'page_15_contains_non_hotel_creator_incentive_template_language'
  ),
  'unknowns', JSON_ARRAY(
    'official_publisher', 'original_source_url', 'official_publish_date',
    'effective_date', 'covered_cities_hotels_accounts', 'current_ebooking_availability'
  ),
  'requires_current_verification', true,
  'current_verification_status', 'not_verified'
), 0, NOW()
WHERE @ctrip_flow_pdf_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_flow_pdf_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_flow_pdf_unit_id, 'ctrip_flow_rules_pdf_conflict_reference', JSON_OBJECT(
  'scope', 'conflict',
  'evidence_level', 'user_provided_unverified_third_party_claim_conflict',
  'evidence_grade', 'D',
  'source_refs', JSON_ARRAY(
    @ctrip_flow_pdf_doc_ref,
    @ctrip_flow_pdf_source_ref,
    @ctrip_flow_pdf_samr_url,
    @ctrip_flow_pdf_rectification_url,
    'repo-doc://docs/ctrip_ladder_simulate_rank_capture_method.md'
  ),
  'conflict_status', 'unresolved',
  'decision_status', 'unresolved_reference_only',
  'officially_corroborated_directions', JSON_ARRAY(
    JSON_OBJECT(
      'pdf_pages', JSON_ARRAY(3, 7),
      'direction', '特牌金牌合作模式、AI生意助手与挂牌通、订单储备金、新流量和新佣金方向',
      'support_scope', '监管事实与平台公告承诺已有独立来源',
      'completion_guard', '不得由PDF或公告推导全部酒店已完成实施'
    ),
    JSON_OBJECT(
      'pdf_page', 8,
      'direction', '需求匹配与雷达五维规划叙事',
      'support_scope', '与用户此前提供的雷达图材料基本重复',
      'completion_guard', '预计9月测试版不等于已上线且年份仍未知'
    )
  ),
  'new_unverified_claims', JSON_ARRAY(
    JSON_OBJECT('pdf_page', 5, 'claim', '点评4.7分成为流量分水岭', 'status', 'unverified_exact_threshold'),
    JSON_OBJECT('pdf_page', 8, 'claim', '平台优先给更容易成交更稳定履约更能创造价值的酒店', 'status', 'unverified_ranking_causality'),
    JSON_OBJECT('pdf_page', 9, 'claim', '信息完整度经营力品质力服务费率吸引力五项能力及近7或30天指标', 'status', 'unverified_alternate_model'),
    JSON_OBJECT('pdf_page', 10, 'claim', '曝光点击订单GMV转化再加权经营飞轮', 'status', 'unverified_causal_model'),
    JSON_OBJECT('pdf_page', 11, 'claim', '品质问题与流量下降及高品质与推荐提升因果链', 'status', 'unverified_causal_model'),
    JSON_OBJECT('pdf_page', 13, 'claim', '价格分层早餐套餐灵活取消房型组合库存稳定服务权益构成吸引力', 'status', 'unverified_factor_model'),
    JSON_OBJECT('pdf_page', 14, 'claim', '流量权重等于曝光价值乘以成交能力', 'status', 'unverified_formula_prohibited'),
    JSON_OBJECT('pdf_page', 15, 'claim', '自然流量池加广告流量池的双池模型', 'status', 'unverified_traffic_pool_model')
  ),
  'model_conflicts', JSON_ARRAY(
    JSON_OBJECT('pages', JSON_ARRAY(8), 'conflict', '同页正文称服务度而雷达图称服务费', 'resolution', '保留平台技术服务费或服务费为现有标准名并记录第三方异名'),
    JSON_OBJECT('pages', JSON_ARRAY(8, 9, 14), 'conflict', '雷达五维、五项能力和两因子乘法公式是三套不一致口径', 'resolution', '不得互相替代或拼成评分公式'),
    JSON_OBJECT('pages', JSON_ARRAY(6, 7, 8), 'conflict', '建立新机制的规划时态与目前影响排名的现行时态冲突', 'resolution', '当前上线状态保持未验证'),
    JSON_OBJECT('pages', JSON_ARRAY(12, 16), 'conflict', '不是佣金越高越好与实际返后佣金影响排名存在叙事张力', 'resolution', '不得推导佣金排名因果或权重'),
    JSON_OBJECT('pages', JSON_ARRAY(14), 'conflict', '乘法公式与现有权重公式未公开核验边界冲突', 'resolution', '公式禁止进入计算和自动决策'),
    JSON_OBJECT('pages', JSON_ARRAY(15), 'conflict', '创作者激励属于非酒店语境模板残留', 'resolution', '不吸收为酒店规则')
  ),
  'routed_existing_unit', JSON_OBJECT(
    'unit_name', '携程佣金与流量排序新规观察（2026-08）',
    'module_id', 'ctrip_commission_reform_watch',
    'pdf_pages', JSON_ARRAY(16, 17),
    'existing_claim_ids', JSON_ARRAY(
      'ctrip_reform_claim_01', 'ctrip_reform_claim_02', 'ctrip_reform_claim_03',
      'ctrip_reform_claim_04', 'ctrip_reform_claim_05', 'ctrip_reform_claim_06'
    ),
    'routing_rule', 'reuse_existing_unverified_claims_without_duplicate_or_evidence_upgrade'
  ),
  'historical_ladder_evidence', JSON_OBJECT(
    'observed_at', '2026-06-28',
    'status', 'historically_observed_before_pdf_not_current_state',
    'current_inference_guard', 'does_not_prove_current_availability_or_disprove_later_shutdown'
  ),
  'unknowns', JSON_ARRAY(
    '4_7_threshold', 'alternate_five_ability_model', 'traffic_weight_formula',
    'natural_and_ad_traffic_pool_contract', 'commission_ranking_effect',
    'ctrip_preferred_12_percent_rule', 'ladder_and_directional_acceleration_current_status'
  ),
  'requires_current_verification', true,
  'current_verification_status', 'not_verified'
), 0, NOW()
WHERE @ctrip_flow_pdf_unit_id IS NOT NULL;

UPDATE `tmp_ctrip_flow_pdf_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('ctrip_hotel_operating_radar:', `type`),
  '$.content_type', 'platform_operating_knowledge_conflict_reference',
  '$.module_id', 'ctrip_hotel_operating_radar',
  '$.platforms', JSON_ARRAY('ctrip'),
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'ota_operator', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('ctrip_knowledge_retrieval', 'merchant_training', 'live_ebooking_comparison', 'source_conflict_review'),
  '$.source_manifest', JSON_EXTRACT(@ctrip_flow_pdf_manifest, '$'),
  '$.reviewed_at', @ctrip_flow_pdf_reviewed_at,
  '$.review_due_at', @ctrip_flow_pdf_review_due_at,
  '$.review_interval_days', 7,
  '$.freshness_policy', 'recheck_on_current_ebooking_notice_contract_or_tool_page',
  '$.allowed_uses', JSON_ARRAY('knowledge_retrieval', 'merchant_training', 'source_conflict_review', 'live_ebooking_verification'),
  '$.blocked_uses', JSON_ARRAY(
    'current_hotel_fact', 'current_ota_fact', 'confirmed_current_platform_rule',
    'hotel_score_calculation', 'traffic_weight_calculation', 'ranking_prediction',
    'commission_recommendation', 'commission_change', 'operation_task_creation',
    'operation_execution', 'automatic_pricing', 'automatic_marketing',
    'automatic_inventory_change', 'automatic_ota_write', 'automatic_pms_write'
  ),
  '$.seed_owner', @ctrip_flow_pdf_seed_owner,
  '$.seed_key', CONCAT('ctrip_hotel_operating_radar:', `type`),
  '$.seed_version', @ctrip_flow_pdf_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.contains_confirmed_current_platform_rule', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_ctrip_flow_pdf_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_ctrip_flow_pdf_chunks` AS `seed`
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
);

DROP TEMPORARY TABLE `tmp_ctrip_flow_pdf_chunks`;

SET @ctrip_flow_pdf_staff_marker := '## PDF补充（第三方待核验）';
SET @ctrip_flow_pdf_staff_appendix := CONCAT(
  '\n\n', @ctrip_flow_pdf_staff_marker, '\n',
  '《携程流量新规则2026.8》共18页，SHA-256为6FFA5FB517F418F11E78C6AD221493C83DD94AC0D90B7AC07D25173683F69A7D。文件由舒克署名并带shuke水印，没有携程官方发布者、来源链接或生效日期，只作第三方待核验参考。', '\n',
  '- 第8页“服务度/服务费”、第9页五项能力和第14页乘法公式是冲突口径，不得拼成评分模型。', '\n',
  '- 4.7点评分水岭、流量权重公式、双流量池、10%至15%佣金、12%优选及云梯下线均未取得平台原始规则。', '\n',
  '- 第16至17页佣金和工具主张复用“携程佣金与流量排序新规观察（2026-08）”既有未验证claims，不重复、不升证据等级。', '\n',
  '不得据此计算本店得分或流量权重、预测排序、调佣、自动调价、创建任务或写入OTA/PMS。'
);

UPDATE `knowledge_base`
SET
  `content` = CONCAT(`content`, @ctrip_flow_pdf_staff_appendix),
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @ctrip_flow_pdf_unit_name
  AND `content` NOT LIKE CONCAT('%', @ctrip_flow_pdf_staff_marker, '%');

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `keywords` = '携程,酒店经营雷达图,流量新规则,信息分,友好度,品质度,欢迎度,平台技术服务费,eBooking,反垄断,十九项整改,第三方材料,口径冲突,规划期',
  `tags` = JSON_ARRAY('携程', '酒店经营雷达图', '流量新规则', '五维模型', '第三方待核验', '口径冲突', '规划期', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @ctrip_flow_pdf_unit_name;
