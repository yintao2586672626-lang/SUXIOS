-- Forward-only correction for one mixed-trust Dianping governance chunk.
--
-- The source-backed rule statements are current official facts, while the
-- private ranking/penalty mechanics are explicit unknowns. Keeping the word
-- "unknown" in evidence_level downgraded the whole chunk to D and made the
-- Knowledge Center report a false incomplete loop. Preserve the unknowns as
-- blocked claims, but classify only the official rule boundary as evidence A.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `knowledge_chunks` AS `kc`
INNER JOIN `knowledge_units` AS `ku`
  ON `ku`.`unit_id` = `kc`.`unit_id`
SET `kc`.`content` = JSON_SET(
  `kc`.`content`,
  '$.scope', 'platform_rule_with_explicit_unknown_boundary',
  '$.evidence_level', 'official_current_rule',
  '$.evidence_grade', 'A',
  '$.decision_policy', 'decision_support_known_facts_only',
  '$.unknown_policy', 'explicit_gap_only_never_infer',
  '$.blocked_uses', JSON_ARRAY(
    'private_algorithm_inference',
    'claim_current_hotel_penalty_without_account_evidence',
    'operation_task_creation',
    'operation_execution',
    'automatic_ota_write',
    'whole_hotel_conclusion'
  ),
  '$.reviewed_at', '2026-07-30 00:00:00',
  '$.review_due_at', '2026-10-28 00:00:00'
)
WHERE `ku`.`name` = '大众点评独立评价规则官方语义合同'
  AND `ku`.`source` = 'revenue_operations_decision_support'
  AND `kc`.`type` = 'penalty_and_algorithm_boundary'
  AND JSON_VALID(`kc`.`content`);

UPDATE `knowledge_units`
SET
  `truth_profile_version` = '2026-07-30.4',
  `lifecycle_reason` = 'clarified_official_rule_facts_and_explicit_private_algorithm_unknowns',
  `updated_at` = NOW()
WHERE `name` = '大众点评独立评价规则官方语义合同'
  AND `source` = 'revenue_operations_decision_support';
