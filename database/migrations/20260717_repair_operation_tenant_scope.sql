-- Repair historical operation rows that used hotel_id as tenant_id.
-- This migration is idempotent and never deletes business data.

UPDATE `operation_alerts` row_data
INNER JOIN `hotels` hotel ON hotel.`id` = row_data.`hotel_id`
SET row_data.`tenant_id` = hotel.`tenant_id`
WHERE hotel.`tenant_id` IS NOT NULL
  AND hotel.`tenant_id` > 0
  AND (row_data.`tenant_id` IS NULL OR row_data.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_action_tracks` row_data
INNER JOIN `hotels` hotel ON hotel.`id` = row_data.`hotel_id`
SET row_data.`tenant_id` = hotel.`tenant_id`
WHERE hotel.`tenant_id` IS NOT NULL
  AND hotel.`tenant_id` > 0
  AND (row_data.`tenant_id` IS NULL OR row_data.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_execution_intents` row_data
INNER JOIN `hotels` hotel ON hotel.`id` = row_data.`hotel_id`
SET row_data.`tenant_id` = hotel.`tenant_id`
WHERE hotel.`tenant_id` IS NOT NULL
  AND hotel.`tenant_id` > 0
  AND (row_data.`tenant_id` IS NULL OR row_data.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_execution_tasks` row_data
INNER JOIN `hotels` hotel ON hotel.`id` = row_data.`hotel_id`
SET row_data.`tenant_id` = hotel.`tenant_id`
WHERE hotel.`tenant_id` IS NOT NULL
  AND hotel.`tenant_id` > 0
  AND (row_data.`tenant_id` IS NULL OR row_data.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_execution_evidence` evidence
INNER JOIN `operation_execution_tasks` task ON task.`id` = evidence.`task_id`
SET evidence.`tenant_id` = task.`tenant_id`
WHERE task.`tenant_id` IS NOT NULL
  AND task.`tenant_id` > 0
  AND (evidence.`tenant_id` IS NULL OR evidence.`tenant_id` <> task.`tenant_id`);
