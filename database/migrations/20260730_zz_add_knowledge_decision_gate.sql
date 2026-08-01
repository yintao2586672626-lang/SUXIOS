-- Add a forward-only knowledge decision gate contract.
--
-- The migration keeps every historical row. It adds an explicit review due
-- date at unit level and materializes evidence/freshness metadata inside each
-- active JSON chunk so retrieval can distinguish current decision support,
-- reference-only knowledge, known unknowns, and blocked knowledge.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `knowledge_units`
  ADD COLUMN IF NOT EXISTS `review_due_at`
    datetime DEFAULT NULL
    COMMENT 'Date when the source, scope and semantic contract must be reviewed again'
    AFTER `reviewed_at`;

-- Default reusable methods to a six-month review cycle.
UPDATE `knowledge_units`
SET `review_due_at` = DATE_ADD(`reviewed_at`, INTERVAL 180 DAY)
WHERE `lifecycle_status` = 'active'
  AND `reviewed_at` IS NOT NULL
  AND `review_due_at` IS NULL;

-- Frequently changing domestic public monitoring is reviewed monthly.
UPDATE `knowledge_units`
SET `review_due_at` = DATE_ADD(`reviewed_at`, INTERVAL 30 DAY)
WHERE `lifecycle_status` = 'active'
  AND `reviewed_at` IS NOT NULL
  AND `source` = 'domestic_public_monitor';

-- Current OTA/PMS product and platform-rule contracts are reviewed quarterly.
UPDATE `knowledge_units`
SET `review_due_at` = DATE_ADD(`reviewed_at`, INTERVAL 90 DAY)
WHERE `lifecycle_status` = 'active'
  AND `reviewed_at` IS NOT NULL
  AND `name` IN (
    '携程点评与数据中心官方帮助语义合同',
    '美团酒店评价与经营规则官方语义合同',
    '国内PMS经营日、订单状态与对账官方语义合同',
    '携程订单履约与结算官方语义合同',
    '大众点评独立评价规则官方语义合同',
    '订单来了PMS当前版本官方语义合同'
  );

-- Materialize reviewed/due dates and evidence grade without overwriting any
-- explicitly maintained value.
UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.reviewed_at', COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.reviewed_at')),
    DATE_FORMAT(`ku`.`reviewed_at`, '%Y-%m-%d %H:%i:%s')
  ),
  '$.review_due_at', COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.review_due_at')),
    DATE_FORMAT(`ku`.`review_due_at`, '%Y-%m-%d %H:%i:%s')
  ),
  '$.evidence_grade', COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_grade')),
    CASE
      WHEN LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_level')), ''))
        REGEXP 'unverified|synthetic|inferred|unknown|conflict|not_runtime|not_operational_fact|collection_unverified|not_assumed_current|live_recheck_required'
        THEN 'D'
      WHEN LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_level')), ''))
        REGEXP 'official_current|official_versioned|official_legal|official_public_statistics|official_vendor|official_public_help|official_public_course'
        THEN 'A'
      WHEN LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_level')), ''))
        REGEXP 'verified|runtime|source_code_reviewed|repository_state_reviewed|repository_integration_contract|integrated|reviewed_correction'
        THEN 'B'
      WHEN LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_level')), ''))
        REGEXP 'reviewed|derived|adapted|distilled|reference|contract|template|association|vendor|user_provided|decision_guardrail|fact_contract'
        THEN 'C'
      ELSE 'U'
    END
  ),
  '$.freshness_policy', COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.freshness_policy')),
    'review_due_reference_only'
  )
)
WHERE `ku`.`lifecycle_status` = 'active'
  AND JSON_VALID(`kc`.`content`);

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.review_interval_days', COALESCE(
    JSON_EXTRACT(`kc`.`content`, '$.review_interval_days'),
    CASE JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_grade'))
      WHEN 'A' THEN 90
      WHEN 'B' THEN 90
      WHEN 'C' THEN 180
      WHEN 'D' THEN 30
      ELSE 0
    END
  ),
  '$.decision_policy', COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.decision_policy')),
    CASE
      WHEN JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.scope')) IN (
        'version_conflict',
        'conflict',
        'known_unknown'
      ) THEN 'known_unknown_only'
      WHEN JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_grade')) IN ('A', 'B')
        THEN 'decision_support_scope_bound'
      WHEN JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.evidence_grade')) = 'C'
        THEN 'reference_only_human_review'
      ELSE 'unverified_or_unknown_only'
    END
  )
)
WHERE `ku`.`lifecycle_status` = 'active'
  AND JSON_VALID(`kc`.`content`);

-- Existing version-conflict artifacts are preserved as explicit known unknowns,
-- never as a silently selected factual version.
UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.conflict_status', COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.conflict_status')),
    'unresolved'
  ),
  '$.decision_policy', 'known_unknown_only'
)
WHERE JSON_VALID(`content`)
  AND (
    JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.scope')) = 'version_conflict'
    OR JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.decision_status')) LIKE 'unresolved%'
  );

-- Record the exact conflicts corrected by the preceding absorption migration.
UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.conflict_key', 'room_revenue_source_basis',
  '$.claim_value', 'direct_room_revenue_field_only',
  '$.resolution_status', 'resolved',
  '$.resolved_at', '2026-07-30',
  '$.superseded_claims', JSON_ARRAY('paid_amount_as_room_revenue_fallback')
)
WHERE `ku`.`name` = 'OTA标准指标与推荐公式清单'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` = '交易收益指标'
  AND JSON_VALID(`kc`.`content`);

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.conflict_key', 'ota_browser_profile_ownership',
  '$.claim_value', 'account_device_profile_with_verified_store_switch',
  '$.resolution_status', 'resolved',
  '$.resolved_at', '2026-07-30',
  '$.superseded_claims', JSON_ARRAY('one_profile_per_store')
)
WHERE JSON_VALID(`kc`.`content`)
  AND (
    (
      `ku`.`name` = '美团 eBooking 浏览器自动化采集方法'
      AND `ku`.`source` = 'meituan'
      AND `kc`.`type` = '采集方法'
    )
    OR (
      `ku`.`name` = 'OTA手动与自动获取策略'
      AND `ku`.`source` = 'ota'
      AND `kc`.`type` = '自动获取'
    )
  );

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.conflict_key', CASE `kc`.`type`
    WHEN '携程差异' THEN 'ctrip_review_collection_default_mode'
    ELSE 'meituan_review_collection_default_mode'
  END,
  '$.claim_value', 'explicit_manual_or_bounded_authorized_capture_only',
  '$.resolution_status', 'resolved',
  '$.resolved_at', '2026-07-30',
  '$.superseded_claims', JSON_ARRAY('reviews_as_standard_automatic_etl_priority')
)
WHERE `ku`.`name` = 'OTA手动与自动获取策略'
  AND `ku`.`source` = 'ota'
  AND `kc`.`type` IN ('携程差异', '美团差异')
  AND JSON_VALID(`kc`.`content`);
