-- Forward-only repair for the Ctrip reform watch package.
-- The original registered seed remains immutable. This exact update adds the
-- traceability fields required by the read-only revenue knowledge gate while
-- preserving every claim-level verification status and all write boundaries.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_reform_repair_seed_owner := 'suxios.ctrip_commission_reform_watch';
SET @ctrip_reform_repair_seed_version := '2026-08-09.1';
SET @ctrip_reform_repair_module_id := 'ctrip_commission_reform_watch';
SET @ctrip_reform_repair_source_refs := JSON_ARRAY(
  'samr_ctrip_antitrust_penalty_20260725',
  'ctrip_19_rectification_measures_20260725',
  'ctrip_hotel_algorithm_disclosure_accessed_20260809',
  'ctrip_hotel_merchant_rules_accessed_20260809',
  'ctrip_privacy_policy_personalization_accessed_20260809',
  'user-message://2026-08-09/ctrip-commission-reform-15-claims'
);

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
  '$.source_refs', JSON_EXTRACT(@ctrip_reform_repair_source_refs, '$'),
  '$.evidence_grade', 'C',
  '$.retrieval_class', 'reviewed_policy_watch_reference_only',
  '$.decision_safe', false,
  '$.task_draft_safe', false,
  '$.contains_current_hotel_fact', false,
  '$.contains_confirmed_current_contract_term', false,
  '$.external_write_authorized', false
)
WHERE JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.seed_owner')) = @ctrip_reform_repair_seed_owner
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.seed_version')) = @ctrip_reform_repair_seed_version
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.module_id')) = @ctrip_reform_repair_module_id;
