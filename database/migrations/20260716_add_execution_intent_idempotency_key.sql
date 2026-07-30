ALTER TABLE `operation_execution_intents`
  ADD COLUMN IF NOT EXISTS `idempotency_key` VARCHAR(191) DEFAULT NULL
    COMMENT 'nullable request identity; currently used by expansion execution linkage'
    AFTER `id`;

-- Backfill only active expansion links. If legacy duplicates exist, the unique-index
-- statement below fails visibly so an operator can resolve them without silent deletion.
UPDATE `operation_execution_intents`
SET `idempotency_key` = CONCAT('expansion:v1:', `source_record_id`)
WHERE `idempotency_key` IS NULL
  AND `source_module` = 'expansion'
  AND `object_type` = 'expansion'
  AND `source_record_id` > 0
  AND `deleted_at` IS NULL;

ALTER TABLE `operation_execution_intents`
  ADD UNIQUE INDEX IF NOT EXISTS `uniq_operation_exec_intent_idempotency` (`idempotency_key`);
