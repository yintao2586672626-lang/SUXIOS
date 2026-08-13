-- Expand the Ctrip hotel operating radar knowledge with authoritative online research.
-- This migration records regulatory facts, Ctrip's announced rectification commitments,
-- a historical public-rule version conflict, and the live eBooking verification contract.
-- It does not claim that the radar is a mandated rectification item or currently live.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_radar_online_version := '2026-08-11.2';
SET @ctrip_radar_online_reviewed_at := '2026-08-11 00:00:00';
SET @ctrip_radar_online_review_due_at := '2026-09-30 00:00:00';
SET @ctrip_radar_online_seed_owner := 'suxios.ctrip_hotel_operating_radar_online_expansion';
SET @ctrip_radar_online_unit_name := '携程酒店经营雷达图（规划期）五维知识合同';
SET @ctrip_radar_online_source := 'revenue_operations_decision_support';
SET @ctrip_radar_online_document_sha256 := 'B5408CEF32FB096040984519122C95CB48BB541D11CBC74B6B990A8036E9415D';
SET @ctrip_radar_penalty_url := 'https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html';
SET @ctrip_radar_rectification_url := 'https://jingji.cctv.com/2026/07/25/ARTI43yXusLYVp6aGHhJUNAS260725.shtml';
SET @ctrip_radar_price_rule_url := 'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/jjjzs/art/2025/art_eef66659c9624c5091bd3acd050b1710.html';
SET @ctrip_radar_platform_rule_url := 'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/fgs/art/2026/art_85b474fc5a08494bb60ca6a280b98d7d.html';
SET @ctrip_radar_antitrust_guidance_url := 'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/fldzfys/art/2026/art_ad10c5301fcb426cb839153ca9f5a274.html';
SET @ctrip_radar_hotel_rule_url := 'https://pages.ctrip.com/hotels/IBU/pages/hotelspecification.html';
SET @ctrip_radar_online_description := '携程酒店经营雷达图规划期知识已完成联网扩充：补充2026-07-25反垄断处罚、携程五方面十九项整改承诺、平台价格/规则/算法的现行监管边界、2025公开酒店规则的历史版本冲突，以及未来eBooking实装验收清单。公开检索仍未取得雷达图原始发布页或门店实装证据，故五维模型继续仅作规划期参考，不计算得分、不预测排序、不创建任务、不授权任何OTA/PMS写入。';
SET @ctrip_radar_online_manifest := JSON_OBJECT(
  'research_document_path', 'docs/ctrip_hotel_operating_radar_online_research_20260811.md',
  'research_document_sha256', @ctrip_radar_online_document_sha256,
  'searched_at', '2026-08-11',
  'timezone', 'Asia/Shanghai',
  'exact_radar_source_search_status', 'not_found_in_public_search_index',
  'search_result_limit', 'absence_from_public_index_does_not_prove_nonexistence',
  'penalty_source', @ctrip_radar_penalty_url,
  'rectification_announcement_source', @ctrip_radar_rectification_url,
  'price_rule_source', @ctrip_radar_price_rule_url,
  'platform_rule_source', @ctrip_radar_platform_rule_url,
  'antitrust_guidance_source', @ctrip_radar_antitrust_guidance_url,
  'ctrip_historical_hotel_rule_source', @ctrip_radar_hotel_rule_url,
  'radar_penalty_relationship', 'directionally_aligned_direct_causality_unverified'
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @ctrip_radar_online_description,
  `tags` = JSON_ARRAY(
    '携程', '酒店经营雷达图', '信息分', '友好度', '品质度', '欢迎度',
    '平台技术服务费', '十九项整改', '平台规则透明', '规划期', 'global_reference'
  ),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'online_authoritative_sources_added_radar_rollout_still_unverified',
  `reviewed_at` = @ctrip_radar_online_reviewed_at,
  `review_due_at` = @ctrip_radar_online_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '用户材料描述信息分、友好度、品质度、欢迎度、平台技术服务费五个维度，且称单一维度不决定最终结果。',
    '市场监管总局于2026-07-25对携程作出反垄断行政处罚并责令停止违法行为和全面整改。',
    '携程于2026-07-25公开五方面十九项整改措施，承诺重建流量分配机制、停止最低价和平台擅自调价、建立新佣金模式并提升规则透明度。',
    '现行平台价格规则保护酒店跨平台自主定价，并禁止强制自动跟价、自动降价和以限流降权等手段限制价格。',
    '现行平台规则要求规则公示、意见征集、历史版本保存、处罚理由告知和便捷申诉。',
    '可公开访问的携程酒店商家经营规则显示最新版本为2025-11-03，早于2026-07-25处罚和整改公告。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '公开检索仍未找到与用户材料完全对应且可独立访问的携程雷达图原始发布页。',
    '雷达图是否已向任一具体酒店开放、9月所属年份、覆盖范围和分批节奏未核验。',
    '五维权重、阈值、公式、刷新频率、排序影响和流量效果未公开核验。',
    '技术服务费与新佣金模式的字段关系、计费口径和是否参与评分未核验。',
    '十九项整改属于携程公开承诺；各项措施的实际完成效果尚未独立验收。',
    '监管文件和整改公告均未直接说明酒店经营雷达图是处罚决定要求的整改项目。'
  ),
  `truth_profile_version` = @ctrip_radar_online_version,
  `updated_at` = NOW()
WHERE `name` = @ctrip_radar_online_unit_name
  AND `source` = @ctrip_radar_online_source;

SET @ctrip_radar_online_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ctrip_radar_online_unit_name
    AND `source` = @ctrip_radar_online_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_ctrip_radar_online_chunks`;
CREATE TEMPORARY TABLE `tmp_ctrip_radar_online_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_ctrip_radar_online_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_ctrip_radar_online_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_online_unit_id, 'ctrip_radar_online_source_audit_reference', JSON_OBJECT(
  'scope', 'ctrip_hotel_operating_radar_online_source_audit',
  'evidence_level', 'bounded_public_search_audit',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('repo-doc://docs/ctrip_hotel_operating_radar_online_research_20260811.md#sha256=', @ctrip_radar_online_document_sha256)
  ),
  'searched_at', '2026-08-11',
  'queries', JSON_ARRAY(
    '酒店经营雷达图：共建可持续健康生态',
    '五大维度相互独立，均衡构建，单一维度不决定最终结果',
    '信息分、友好度、品质度、欢迎度',
    'eBooking商家后台 雷达图预览版',
    'site:mp.weixin.qq.com 酒店经营雷达图 携程'
  ),
  'result', 'exact_original_radar_publish_page_not_found_in_public_search_index',
  'search_limit', 'does_not_prove_nonexistence_or_absence_from_authenticated_ebooking',
  'unknowns', JSON_ARRAY(
    'original_article_url_author_and_publish_date',
    'september_year',
    'current_hotel_rollout_scope',
    'live_ebooking_availability'
  )
), 0, NOW()
WHERE @ctrip_radar_online_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_online_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_online_unit_id, 'ctrip_rectification_19_measures_commitment_reference', JSON_OBJECT(
  'scope', 'ctrip_antitrust_rectification_announcement',
  'evidence_level', 'platform_announced_commitment_republished_by_cctv',
  'evidence_grade', 'B',
  'source_refs', JSON_ARRAY(@ctrip_radar_rectification_url, @ctrip_radar_penalty_url),
  'announcement_date', '2026-07-25',
  'announcement_origin', '携程黑板报',
  'republication', '央视网',
  'measure_group_count', 5,
  'measure_count', 19,
  'measure_groups', JSON_ARRAY(
    JSON_OBJECT(
      'group', '酒店经营自主权',
      'items', JSON_ARRAY(
        '全面下线一级委托分销特牌合作模式',
        '调整相关合作协议并建立新的商家分级合作模式',
        '取消不合理流量安排并建立新的流量分配机制'
      )
    ),
    JSON_OBJECT(
      'group', '酒店自主定价权',
      'items', JSON_ARRAY(
        '全面下线二级委托分销金牌合作模式并停止全网最低价要求',
        '调整相关合作协议并建立新的商家分级合作模式',
        '取消不合理流量安排并建立新的流量分配机制',
        '自述2026年3月下线AI生意助手并宣布下线挂牌通调价功能',
        '未经商家明确同意业务人员不得擅自调整价格',
        '退还相关订单储备金122781078元'
      )
    ),
    JSON_OBJECT(
      'group', '商家权益和经营环境',
      'items', JSON_ARRAY(
        '取消原一二级委托分销收费安排并建立新佣金模式',
        '取消平台调整商家价格的合同条款并提升规则透明度',
        '坚持自愿选择平等协商并畅通反馈申诉机制',
        '下线智选特惠等促销类别',
        '免费开放数据中心VIP并投入AI赋能商家培训和服务品质提升'
      )
    ),
    JSON_OBJECT(
      'group', '消费者权益保护',
      'items', JSON_ARRAY(
        '提升消费体验和服务满意度',
        '强化个人信息和数据安全保护',
        '防范大数据杀熟'
      )
    ),
    JSON_OBJECT(
      'group', '长期合规机制',
      'items', JSON_ARRAY(
        '建立反垄断合规咨询审查举报和奖惩机制',
        '持续开展反垄断合规培训并融入日常经营全过程'
      )
    )
  ),
  'statement_scope', 'announcement_proves_commitment_not_independently_verified_completion',
  'radar_direct_reference_status', 'not_mentioned_in_the_19_measures_announcement',
  'radar_relationship', 'directionally_aligned_direct_causality_unverified'
), 0, NOW()
WHERE @ctrip_radar_online_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_online_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_online_unit_id, 'ctrip_radar_regulatory_operating_boundaries_fact', JSON_OBJECT(
  'scope', 'ctrip_radar_platform_regulatory_operating_boundaries',
  'evidence_level', 'official_current_regulation_and_guidance',
  'evidence_grade', 'A',
  'source_refs', JSON_ARRAY(
    @ctrip_radar_price_rule_url,
    @ctrip_radar_platform_rule_url,
    @ctrip_radar_antitrust_guidance_url
  ),
  'authorities', JSON_ARRAY('国家发展改革委', '国家市场监督管理总局', '国家互联网信息办公室'),
  'price_rule', JSON_OBJECT(
    'effective_from', '2026-04-10',
    'merchant_autonomous_cross_platform_pricing', true,
    'prohibited_platform_constraints', JSON_ARRAY(
      '以限流搜索降序算法降权等方式强制或变相强制降价',
      '要求本平台价格不得高于其他渠道',
      '强制开通自动跟价自动降价或类似系统'
    ),
    'fee_rule', '收费应公平合法合理并公示，新增或变更收费项目规则标准应公开征求意见不少于七日',
    'ranking_rule', '提供搜索排序推荐或竞价排名服务时应向参与经营者告知相关规则'
  ),
  'platform_rule_supervision', JSON_OBJECT(
    'effective_from', '2026-02-01',
    'requirements', JSON_ARRAY(
      '平台规则持续公示并便于检索下载',
      '重大修改公开征求意见提前公示并保存历史版本',
      '不利处理需告知事实理由依据并提供便捷申诉',
      '申诉人要求人工判定时不得仅由人工智能处理'
    )
  ),
  'antitrust_compliance_guidance', JSON_OBJECT(
    'published_at', '2026-02-13',
    'legal_effect', 'general_guidance_not_mandatory',
    'recommended_reviews', JSON_ARRAY('流量分配规则', '促销政策', '平台内经营者协议'),
    'recommended_algorithm_checks', JSON_ARRAY('计价算法', '推荐系统', '排序逻辑', '广告投放策略'),
    'transparency_principle', '技术手段与人工复核结合，推动算法透明可解释并留存审计记录'
  ),
  'radar_use_guard', 'regulatory_boundaries_do_not_reveal_ctrip_radar_formula_weight_or_current_score'
), 0, NOW()
WHERE @ctrip_radar_online_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_online_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_online_unit_id, 'ctrip_radar_public_rule_20251103_historical_reference', JSON_OBJECT(
  'scope', 'ctrip_hotel_public_rule_historical_reference',
  'evidence_level', 'official_historical_public_rule_requires_current_verification',
  'evidence_grade', 'B',
  'source_refs', JSON_ARRAY(@ctrip_radar_hotel_rule_url),
  'page_title', '携程酒店商家经营规则',
  'page_latest_version_published_at', '2025-11-03',
  'page_latest_version_effective_at', '2025-11-10',
  'retrieved_at', '2026-08-11',
  'precedes_penalty_and_rectification', true,
  'historical_dimension_mapping', JSON_OBJECT(
    'information_score', JSON_ARRAY('名称', '地址', '星钻', '简介', '房型', '设施服务', '图片真实性'),
    'quality', JSON_ARRAY('拒单与确认时效', '接单后推翻', '查无预订', '无法原单安排', '发票', '附加服务'),
    'platform_technical_service_fee', JSON_ARRAY('服务费欠款', '预付款', '欠票')
  ),
  'historical_terms_still_visible', JSON_ARRAY('委托分销', '挂牌', '价格保障', '排序降权', 'PSI服务质量分'),
  'version_conflict_status', 'public_page_predates_2026_rectification_and_may_be_superseded',
  'requires_current_verification', true,
  'current_verification_status', 'historical_page_only',
  'allowed_interpretation', '历史字段和问题目录参考',
  'prohibited_interpretation', '不得作为2026年7月25日整改后的现行完整规则或新雷达评分公式'
), 0, NOW()
WHERE @ctrip_radar_online_unit_id IS NOT NULL;

INSERT INTO `tmp_ctrip_radar_online_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @ctrip_radar_online_unit_id, 'ctrip_radar_live_rollout_verification_checklist', JSON_OBJECT(
  'scope', 'ctrip_radar_live_ebooking_verification_contract',
  'evidence_level', 'derived_verification_contract_from_traceable_sources',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('repo-doc://docs/ctrip_hotel_operating_radar_online_research_20260811.md#sha256=', @ctrip_radar_online_document_sha256),
    @ctrip_radar_price_rule_url,
    @ctrip_radar_platform_rule_url
  ),
  'required_checks', JSON_ARRAY(
    JSON_OBJECT('key', 'hotel_identity', 'fields', JSON_ARRAY('system_hotel_id', 'ctrip_hotel_id', 'hotel_name', 'account_owner')),
    JSON_OBJECT('key', 'page_identity', 'fields', JSON_ARRAY('entry_path', 'page_title', 'route_or_url', 'version_or_help_url')),
    JSON_OBJECT('key', 'time_identity', 'fields', JSON_ARRAY('captured_at', 'data_as_of', 'refresh_cycle', 'business_date')),
    JSON_OBJECT('key', 'dimension_identity', 'fields', JSON_ARRAY('five_labels_exact_match', 'aliases', 'added_or_removed_dimensions', 'version_change')),
    JSON_OBJECT('key', 'numeric_semantics', 'fields', JSON_ARRAY('value', 'maximum', 'range', 'unit', 'color_or_status_meaning')),
    JSON_OBJECT('key', 'formula_evidence', 'fields', JSON_ARRAY('field_help', 'formula', 'weight', 'threshold', 'advice_only_flag')),
    JSON_OBJECT('key', 'ranking_boundary', 'fields', JSON_ARRAY('explicit_search_or_recommendation_effect', 'rule_source', 'effective_date')),
    JSON_OBJECT('key', 'fee_identity', 'fields', JSON_ARRAY('technical_service_fee', 'commission', 'marketing_fee', 'deposit', 'order_reserve')),
    JSON_OBJECT('key', 'change_and_appeal', 'fields', JSON_ARRAY('effective_date', 'historical_version', 'consultation_entry', 'appeal_entry', 'human_review_entry')),
    JSON_OBJECT('key', 'save_readback', 'fields', JSON_ARRAY('raw_page_evidence', 'structured_fields', 'source_date', 'formal_save', 'exact_readback', 'page_render'))
  ),
  'acceptance_rule', 'all_identity_source_date_and_semantic_checks_must_be_preserved_before_current_hotel_claim',
  'failure_state', 'missing_or_conflicting_fields_remain_unverified_and_cannot_be_replaced_by_zero_history_or_inference'
), 0, NOW()
WHERE @ctrip_radar_online_unit_id IS NOT NULL;

UPDATE `tmp_ctrip_radar_online_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('ctrip_hotel_operating_radar:', `type`),
  '$.content_type', 'platform_operating_knowledge_contract',
  '$.module_id', 'ctrip_hotel_operating_radar',
  '$.platforms', JSON_ARRAY('ctrip'),
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'revenue_manager', 'ota_operator', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('ctrip_knowledge_retrieval', 'merchant_training', 'live_ebooking_comparison', 'radar_rollout_acceptance'),
  '$.source_manifest', JSON_EXTRACT(@ctrip_radar_online_manifest, '$'),
  '$.reviewed_at', @ctrip_radar_online_reviewed_at,
  '$.review_due_at', @ctrip_radar_online_review_due_at,
  '$.review_interval_days', 50,
  '$.freshness_policy', 'recheck_when_ctrip_publishes_radar_help_or_live_ebooking_page',
  '$.allowed_uses', JSON_ARRAY('knowledge_retrieval', 'merchant_training', 'regulatory_boundary_reference', 'live_rollout_verification'),
  '$.blocked_uses', JSON_ARRAY(
    'current_hotel_fact', 'current_ota_fact', 'hotel_score_calculation', 'ranking_prediction',
    'revenue_fact', 'operation_task_creation', 'operation_execution', 'automatic_pricing',
    'automatic_inventory_change', 'automatic_ota_write', 'automatic_pms_write'
  ),
  '$.seed_owner', @ctrip_radar_online_seed_owner,
  '$.seed_key', CONCAT('ctrip_hotel_operating_radar:', `type`),
  '$.seed_version', @ctrip_radar_online_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_ctrip_radar_online_chunks` AS `seed`
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
FROM `tmp_ctrip_radar_online_chunks` AS `seed`
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

DROP TEMPORARY TABLE `tmp_ctrip_radar_online_chunks`;

SET @ctrip_radar_online_staff_content := CONCAT(
  '# 携程酒店经营雷达图（规划期）', '\n\n',
  '## 当前状态', '\n',
  '用户材料描述信息分、友好度、品质度、欢迎度、平台技术服务费五维，并称eBooking预览版预计于9月开放；年份、当前开放范围和本店可用性仍未核验。', '\n\n',
  '## 联网确认', '\n',
  '- 市场监管总局于2026-07-25对携程作出反垄断处罚并责令全面整改。', '\n',
  '- 携程随后公开五方面十九项整改：停止独家和全网最低价、重建流量分配、取消平台调价条款、建立新佣金模式、提升规则透明度并开放数据中心VIP。', '\n',
  '- 现行监管规则保护酒店自主定价，要求收费和排序规则透明、规则变更公示并提供申诉。', '\n\n',
  '## 五维经营方向', '\n',
  '- 信息分：图片/视频、名称地址房型设施政策等信息真实完整。', '\n',
  '- 友好度：价格合理但不等于全网最低；房态准确、取消政策清晰。', '\n',
  '- 品质度：订单确认、拒单/推翻、入住、投诉、点评、发票及附加服务。', '\n',
  '- 欢迎度：携程渠道历史选择结果；新流量机制的权重和公式未公开。', '\n',
  '- 平台技术服务费：应与佣金、营销费、保证金和订单储备金分开核验。', '\n\n',
  '## 版本冲突', '\n',
  '公开携程酒店规则页面最新版本为2025-11-03，早于2026-07-25整改公告，仍含委托分销、挂牌、价格保障和PSI等历史表述，只能作历史字段目录参考。', '\n\n',
  '## 使用边界', '\n',
  '雷达图与处罚方向一致，但公开文件未证明其为处罚直接要求的整改项目。十九项措施证明平台已公告承诺，不等于全部完成已独立验收。不得据此计算本店得分、预测排序、自动调价、创建任务或写入OTA/PMS。'
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @ctrip_radar_online_staff_content,
  `keywords` = '携程,酒店经营雷达图,信息分,友好度,品质度,欢迎度,平台技术服务费,eBooking,反垄断,十九项整改,流量分配,自主定价,规则透明,规划期',
  `tags` = JSON_ARRAY('携程', '酒店经营雷达图', '五维模型', '十九项整改', '平台规则透明', '规划期', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @ctrip_radar_online_unit_name;
