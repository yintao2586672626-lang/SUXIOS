ALTER TABLE `hotels`
  ADD COLUMN IF NOT EXISTS `archived_at` DATETIME NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `archived_by` INT UNSIGNED NULL AFTER `archived_at`,
  ADD INDEX IF NOT EXISTS `idx_hotels_archived_at` (`archived_at`);
