-- Establish one canonical active execution intent per price suggestion without
-- deleting or rewriting legacy duplicate intents. Future creates converge on
-- the canonical row through the existing unique idempotency index.
UPDATE `operation_execution_intents` AS `target`
INNER JOIN (
  SELECT `source_record_id`, MIN(`id`) AS `canonical_id`
  FROM `operation_execution_intents`
  WHERE `source_module` = 'price_suggestion'
    AND `source_record_id` > 0
    AND `deleted_at` IS NULL
  GROUP BY `source_record_id`
) AS `canonical`
  ON `canonical`.`canonical_id` = `target`.`id`
LEFT JOIN `operation_execution_intents` AS `existing`
  ON `existing`.`idempotency_key` = CONCAT('price_suggestion:v1:', `canonical`.`source_record_id`)
SET `target`.`idempotency_key` = CONCAT('price_suggestion:v1:', `canonical`.`source_record_id`)
WHERE (`target`.`idempotency_key` IS NULL OR `target`.`idempotency_key` = '')
  AND `existing`.`id` IS NULL;
