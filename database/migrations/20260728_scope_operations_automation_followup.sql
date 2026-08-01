-- Scope scheduler evidence to the same tenant/hotel/robot shown in the UI.
-- Correct goal comments without changing existing nullable values.

ALTER TABLE `manual_notification_schedule_runs`
  ADD COLUMN IF NOT EXISTS `scope_tenant_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'Tenant scope explicitly verified by a scoped scheduler invocation'
    AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_manual_notification_schedule_run_tenant_scope`
    (`scope_tenant_id`, `scope_hotel_id`, `scope_robot_id`, `observed_at`);

ALTER TABLE `operating_target_daily_records`
  MODIFY COLUMN `target_occupancy_rate_percent` DECIMAL(7,2) DEFAULT NULL
    COMMENT 'Nullable hotel operating target occupancy rate percent; unknown remains NULL',
  MODIFY COLUMN `target_revpar` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Nullable accommodation-room-fee target RevPAR; unknown remains NULL';
