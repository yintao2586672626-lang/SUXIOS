-- Forward-only repair for one checklist evidence label.
-- The previous label contained the word "unknowns", which correctly triggered
-- the conservative evidence gate even though the checklist itself contains only
-- guarded manual checks. Claim-level unknowns remain unchanged in their chunks.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `knowledge_chunks`
SET `content` = JSON_SET(
  CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
  '$.evidence_level', 'reviewed_policy_watch_operator_checklist',
  '$.evidence_grade', 'C',
  '$.decision_safe', false,
  '$.task_draft_safe', false,
  '$.external_write_authorized', false
)
WHERE `type` = 'ctrip_reform_hotel_action_checklist'
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.seed_owner')) = 'suxios.ctrip_commission_reform_watch'
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.seed_version')) = '2026-08-09.1'
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.module_id')) = 'ctrip_commission_reform_watch';
