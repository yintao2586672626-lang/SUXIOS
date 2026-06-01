ALTER TABLE `knowledge_units`
  ADD COLUMN IF NOT EXISTS `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'system hotel id, 0=global knowledge' AFTER `unit_id`,
  ADD INDEX IF NOT EXISTS `idx_knowledge_units_hotel_status` (`hotel_id`, `status`, `unit_id`);
