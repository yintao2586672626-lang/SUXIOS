-- Keep an active collection plan running while a newer draft is prepared.
-- One hotel may have many immutable versions but at most one active slot.
ALTER TABLE `hotel_collection_plans`
  DROP INDEX `uq_hotel_collection_plan_scope`,
  ADD COLUMN `active_slot` tinyint unsigned DEFAULT NULL AFTER `enabled`,
  ADD UNIQUE KEY `uq_hotel_collection_plan_version`
    (`tenant_id`, `system_hotel_id`, `plan_version`),
  ADD UNIQUE KEY `uq_hotel_collection_plan_active`
    (`tenant_id`, `system_hotel_id`, `active_slot`);

UPDATE `hotel_collection_plans`
SET `active_slot` = CASE
  WHEN `enabled` = 1 AND `plan_status` = 'active' THEN 1
  ELSE NULL
END;
