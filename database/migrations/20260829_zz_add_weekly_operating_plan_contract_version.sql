ALTER TABLE `weekly_operating_plan_snapshots`
  ADD COLUMN IF NOT EXISTS `contract_version` VARCHAR(40) NOT NULL DEFAULT 'weekly_operating_plan.v1' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_weekly_plan_contract` (`contract_version`, `hotel_id`, `week_end`);
