-- Correct the scope of the ranking-rule disclosure statement in the online radar expansion.
-- Article 13 of the Internet Platform Pricing Conduct Rules identifies platform merchants
-- participating in bidding as the disclosure recipients. It does not establish that ordinary
-- recommendation algorithms or radar weights must be disclosed to every hotel.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_radar_scope_fix_version := '2026-08-11.3';
SET @ctrip_radar_scope_fix_document_sha256 := 'AB721257E58A17ECF714586571D5BAB58F8AD95A95A315D2E0993568E655763B';
SET @ctrip_radar_scope_fix_seed_owner := 'suxios.ctrip_hotel_operating_radar_online_expansion';
SET @ctrip_radar_scope_fix_unit_name := '携程酒店经营雷达图（规划期）五维知识合同';
SET @ctrip_radar_scope_fix_source := 'revenue_operations_decision_support';
SET @ctrip_radar_scope_fix_price_rule_url := 'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/jjjzs/art/2025/art_eef66659c9624c5091bd3acd050b1710.html';
SET @ctrip_radar_scope_fix_platform_rule_url := 'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/fgs/art/2026/art_85b474fc5a08494bb60ca6a280b98d7d.html';
SET @ctrip_radar_scope_fix_doc_ref := CONCAT(
  'repo-doc://docs/ctrip_hotel_operating_radar_online_research_20260811.md#sha256=',
  @ctrip_radar_scope_fix_document_sha256
);

SET @ctrip_radar_scope_fix_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @ctrip_radar_scope_fix_unit_name
    AND `source` = @ctrip_radar_scope_fix_source
  ORDER BY `unit_id` ASC LIMIT 1
);

UPDATE `knowledge_units`
SET
  `lifecycle_reason` = 'online_authoritative_sources_added_and_ranking_disclosure_scope_corrected',
  `truth_profile_version` = @ctrip_radar_scope_fix_version,
  `updated_at` = NOW()
WHERE `unit_id` = @ctrip_radar_scope_fix_unit_id;

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
  '$.seed_version', @ctrip_radar_scope_fix_version,
  '$.source_manifest.research_document_sha256', @ctrip_radar_scope_fix_document_sha256,
  '$.source_manifest.ranking_disclosure_scope_correction', 'recipient_is_platform_merchant_participating_in_bidding_not_every_hotel'
)
WHERE `unit_id` = @ctrip_radar_scope_fix_unit_id
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = @ctrip_radar_scope_fix_seed_owner;

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
  '$.source_refs', JSON_ARRAY(@ctrip_radar_scope_fix_doc_ref)
)
WHERE `unit_id` = @ctrip_radar_scope_fix_unit_id
  AND `type` = 'ctrip_radar_online_source_audit_reference'
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = @ctrip_radar_scope_fix_seed_owner;

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
  '$.source_refs', JSON_ARRAY(
    @ctrip_radar_scope_fix_doc_ref,
    @ctrip_radar_scope_fix_price_rule_url,
    @ctrip_radar_scope_fix_platform_rule_url
  )
)
WHERE `unit_id` = @ctrip_radar_scope_fix_unit_id
  AND `type` = 'ctrip_radar_live_rollout_verification_checklist'
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = @ctrip_radar_scope_fix_seed_owner;

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
  '$.price_rule.ranking_rule', '平台开展竞价排名或者提供排名推荐服务时，应向参与竞价的平台内经营者告知搜索排序、推荐和竞价排名规则',
  '$.price_rule.ranking_disclosure_scope', 'platform_merchants_participating_in_bidding',
  '$.price_rule.ranking_inference_guard', '不得据此推导普通推荐算法或雷达公式权重必须向所有酒店披露'
)
WHERE `unit_id` = @ctrip_radar_scope_fix_unit_id
  AND `type` = 'ctrip_radar_regulatory_operating_boundaries_fact'
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = @ctrip_radar_scope_fix_seed_owner;

UPDATE `knowledge_base`
SET
  `content` = REPLACE(
    `content`,
    '- 现行监管规则保护酒店自主定价，要求收费和排序规则透明、规则变更公示并提供申诉。',
    '- 现行监管规则保护酒店自主定价并要求收费规则公示；排序规则告知义务的对象是参与竞价的平台内经营者，不等于普通推荐算法或雷达权重须向所有酒店公开。'
  ),
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @ctrip_radar_scope_fix_unit_name;
