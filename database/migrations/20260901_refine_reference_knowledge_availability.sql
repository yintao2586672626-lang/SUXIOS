-- Curate reference-knowledge availability after a source and boundary review.
--
-- - OTA daily-ledger and hotel-naming methods have repository-backed source
--   hashes plus focused contracts, so they become retrieval-safe C-grade
--   references. They remain unsafe for decisions, task drafts, or writes.
-- - The BD/new-store and manager-interview packages retain their rows but are
--   paused because their original source files are unavailable for exact
--   reverification in this workspace.
-- - No knowledge is deleted by this migration.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @knowledge_curation_reviewed_at := '2026-09-01 00:00:00';
SET @knowledge_curation_review_due_at := '2026-12-01 00:00:00';

UPDATE `knowledge_units`
SET
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'repository_source_hash_and_boundary_contract_reviewed_reference_only_20260901',
  `reviewed_at` = @knowledge_curation_reviewed_at,
  `review_due_at` = @knowledge_curation_review_due_at,
  `updated_at` = NOW()
WHERE `unit_id` = 42
  AND `source` = 'ota_daily_operations_ledger_reference'
  AND `name` = 'OTA每日经营台账与晨报闭环';

UPDATE `knowledge_chunks` AS `chunk`
INNER JOIN `knowledge_units` AS `unit` ON `unit`.`unit_id` = `chunk`.`unit_id`
SET
  `chunk`.`content` = JSON_SET(
    CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE JSON_OBJECT() END,
    '$.evidence_level', 'user_provided_reference_structure_reviewed',
    '$.evidence_grade', 'C',
    '$.curation_review', JSON_OBJECT(
      'status', 'reviewed_reference_method_only',
      'reviewed_at', @knowledge_curation_reviewed_at,
      'review_due_at', @knowledge_curation_review_due_at,
      'basis', JSON_ARRAY(
        'source_sha256_preserved',
        'workbook_structure_reviewed',
        'focused_contract_passed',
        'historical_values_remain_unverified'
      )
    ),
    '$.reviewed_at', @knowledge_curation_reviewed_at,
    '$.review_due_at', @knowledge_curation_review_due_at,
    '$.requires_current_verification', true,
    '$.current_verification_status', 'source_structure_reviewed_not_current_hotel_fact',
    '$.decision_safe', false,
    '$.task_draft_safe', false,
    '$.contains_current_hotel_fact', false,
    '$.contains_current_ota_fact', false,
    '$.external_write_authorized', false,
    '$.lifecycle_status', 'active'
  ),
  `chunk`.`lifecycle_status` = 'active',
  `chunk`.`superseded_by_chunk_id` = NULL
WHERE `unit`.`unit_id` = 42
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = 'suxios.ota_daily_operations_ledger_knowledge'
  AND (`chunk`.`lifecycle_status` IS NULL OR `chunk`.`lifecycle_status` = 'active');

UPDATE `knowledge_chunks`
SET `content_digest` = LOWER(SHA2(CAST(`content` AS CHAR CHARACTER SET utf8mb4), 256))
WHERE `unit_id` = 42
  AND `lifecycle_status` = 'active';

UPDATE `knowledge_units`
SET
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'repository_source_hash_skill_and_eval_contract_reviewed_reference_only_20260901',
  `reviewed_at` = @knowledge_curation_reviewed_at,
  `review_due_at` = @knowledge_curation_review_due_at,
  `updated_at` = NOW()
WHERE `unit_id` = 57
  AND `source` = 'hotel_naming_optimization'
  AND `name` = '酒店门店与房型命名优化知识';

UPDATE `knowledge_chunks` AS `chunk`
INNER JOIN `knowledge_units` AS `unit` ON `unit`.`unit_id` = `chunk`.`unit_id`
SET
  `chunk`.`content` = JSON_SET(
    CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE JSON_OBJECT() END,
    '$.evidence_level', 'user_provided_reference_method_reviewed',
    '$.evidence_grade', 'C',
    '$.curation_review', JSON_OBJECT(
      'status', 'reviewed_reference_method_only',
      'reviewed_at', @knowledge_curation_reviewed_at,
      'review_due_at', @knowledge_curation_review_due_at,
      'basis', JSON_ARRAY(
        'source_sha256_preserved',
        'project_skill_and_references_present',
        'trigger_and_quality_evals_passed',
        'conversion_uplift_remains_unverified'
      )
    ),
    '$.reviewed_at', @knowledge_curation_reviewed_at,
    '$.review_due_at', @knowledge_curation_review_due_at,
    '$.requires_current_verification', true,
    '$.current_verification_status', 'naming_method_reviewed_not_conversion_proof',
    '$.decision_safe', false,
    '$.task_draft_safe', false,
    '$.contains_current_hotel_fact', false,
    '$.contains_current_ota_fact', false,
    '$.external_write_authorized', false,
    '$.lifecycle_status', 'active'
  ),
  `chunk`.`lifecycle_status` = 'active',
  `chunk`.`superseded_by_chunk_id` = NULL
WHERE `unit`.`unit_id` = 57
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = 'suxios.hotel_naming_knowledge'
  AND (`chunk`.`lifecycle_status` IS NULL OR `chunk`.`lifecycle_status` = 'active');

UPDATE `knowledge_chunks`
SET `content_digest` = LOWER(SHA2(CAST(`content` AS CHAR CHARACTER SET utf8mb4), 256))
WHERE `unit_id` = 57
  AND `lifecycle_status` = 'active';

UPDATE `knowledge_units`
SET
  `lifecycle_status` = 'stale',
  `lifecycle_reason` = 'original_training_source_file_unavailable_for_exact_reverification_20260901',
  `reviewed_at` = @knowledge_curation_reviewed_at,
  `review_due_at` = '2026-10-01 00:00:00',
  `updated_at` = NOW()
WHERE `stable_key` = 'global:user_training:hotel_bd_new_store'
  AND `unit_id` = 62;

UPDATE `knowledge_units`
SET
  `lifecycle_status` = 'stale',
  `lifecycle_reason` = 'both_original_source_files_unavailable_for_exact_reverification_20260901',
  `reviewed_at` = @knowledge_curation_reviewed_at,
  `review_due_at` = '2026-10-01 00:00:00',
  `updated_at` = NOW()
WHERE `stable_key` = 'global:user_reference:hotel_manager_interview_distillation'
  AND `unit_id` = 64;
