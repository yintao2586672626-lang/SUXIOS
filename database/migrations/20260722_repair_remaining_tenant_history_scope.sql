-- Backfill remaining tenant-aware history tables after the tenant foundation
-- migration. Direct hotel scopes use the persisted hotel tenant; user and
-- child records follow their already-remapped authoritative parent.

UPDATE `agent_tasks` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `knowledge_categories` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `knowledge_base` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `room_types` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `devices` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `energy_consumption` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `competitor_analysis` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `agent_work_orders` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `agent_conversations` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `energy_benchmarks` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `energy_saving_suggestions` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `maintenance_plans` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `hotel_field_templates` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `competitor_hotel` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`store_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`store_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `competitor_price_log` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`store_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`store_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `opening_projects` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_alerts` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_action_tracks` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_execution_intents` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_execution_tasks` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `operation_execution_evidence` evidence
INNER JOIN `operation_execution_tasks` task ON task.`id` = evidence.`task_id`
SET evidence.`tenant_id` = task.`tenant_id`
WHERE task.`tenant_id` IS NOT NULL
  AND task.`tenant_id` > 0
  AND (evidence.`tenant_id` IS NULL OR evidence.`tenant_id` = 0 OR evidence.`tenant_id` <> task.`tenant_id`);

UPDATE `transfer_records` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `complaint_rooms` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `complaint_feedbacks` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `field_mappings` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `ai_model_call_logs` business_row
INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`hotel_id`
SET business_row.`tenant_id` = hotel.`tenant_id`
WHERE business_row.`hotel_id` > 0
  AND (business_row.`tenant_id` IS NULL OR business_row.`tenant_id` = 0 OR business_row.`tenant_id` <> hotel.`tenant_id`);

UPDATE `login_logs` history_row
INNER JOIN `users` user_row ON user_row.`id` = history_row.`user_id`
SET history_row.`tenant_id` = user_row.`tenant_id`
WHERE user_row.`tenant_id` IS NOT NULL
  AND user_row.`tenant_id` > 0
  AND (history_row.`tenant_id` IS NULL OR history_row.`tenant_id` = 0 OR history_row.`tenant_id` <> user_row.`tenant_id`);

UPDATE `quant_simulation_records` history_row
INNER JOIN `users` user_row ON user_row.`id` = history_row.`created_by`
SET history_row.`tenant_id` = user_row.`tenant_id`
WHERE user_row.`tenant_id` IS NOT NULL
  AND user_row.`tenant_id` > 0
  AND (history_row.`tenant_id` IS NULL OR history_row.`tenant_id` = 0 OR history_row.`tenant_id` <> user_row.`tenant_id`);

UPDATE `expansion_records` history_row
INNER JOIN `users` user_row ON user_row.`id` = history_row.`created_by`
SET history_row.`tenant_id` = user_row.`tenant_id`
WHERE user_row.`tenant_id` IS NOT NULL
  AND user_row.`tenant_id` > 0
  AND (history_row.`tenant_id` IS NULL OR history_row.`tenant_id` = 0 OR history_row.`tenant_id` <> user_row.`tenant_id`);

UPDATE `strategy_simulation_records` history_row
INNER JOIN `users` user_row ON user_row.`id` = history_row.`created_by`
SET history_row.`tenant_id` = user_row.`tenant_id`
WHERE user_row.`tenant_id` IS NOT NULL
  AND user_row.`tenant_id` > 0
  AND (history_row.`tenant_id` IS NULL OR history_row.`tenant_id` = 0 OR history_row.`tenant_id` <> user_row.`tenant_id`);

UPDATE `feasibility_reports` history_row
INNER JOIN `users` user_row ON user_row.`id` = history_row.`created_by`
SET history_row.`tenant_id` = user_row.`tenant_id`
WHERE user_row.`tenant_id` IS NOT NULL
  AND user_row.`tenant_id` > 0
  AND (history_row.`tenant_id` IS NULL OR history_row.`tenant_id` = 0 OR history_row.`tenant_id` <> user_row.`tenant_id`);
