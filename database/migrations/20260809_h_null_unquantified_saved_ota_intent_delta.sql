-- A saved OTA diagnosis deliberately leaves the target delta unquantified
-- until a human approval supplies a positive absolute or delta target.
-- Repair only untouched pending intents whose evidence proves that state;
-- approved intents, task-linked intents, and genuinely quantified zeros are
-- outside this migration.
UPDATE `operation_execution_intents`
SET `expected_delta` = NULL
WHERE `source_module` = 'ota_diagnosis_saved'
  AND `status` = 'pending_approval'
  AND `deleted_at` IS NULL
  AND COALESCE(`approved_by`, 0) = 0
  AND `approved_at` IS NULL
  AND `expected_delta` = 0
  AND JSON_UNQUOTE(JSON_EXTRACT(`evidence_json`, '$.expected_delta_status')) = 'not_quantified'
  AND NOT EXISTS (
    SELECT 1
    FROM `operation_execution_tasks` AS `task`
    WHERE `task`.`intent_id` = `operation_execution_intents`.`id`
      AND `task`.`deleted_at` IS NULL
  );
