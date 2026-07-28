-- Forward-only compatibility fields for the operations automation center.
-- Existing delivery idempotency and tenant/hotel/robot columns remain unchanged.

ALTER TABLE `operating_target_daily_records`
  ADD COLUMN IF NOT EXISTS `target_occupancy_rate_percent` DECIMAL(7,2) DEFAULT NULL
    COMMENT 'Nullable whole-hotel target occupancy rate percent; unknown remains NULL'
    AFTER `target_revenue`,
  ADD COLUMN IF NOT EXISTS `target_revpar` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Nullable whole-hotel target RevPAR; unknown remains NULL'
    AFTER `target_occupancy_rate_percent`;

ALTER TABLE `competitor_wechat_robot`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'Owning tenant resolved from the bound system hotel'
    AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_competitor_wechat_robot_tenant_scope`
    (`tenant_id`, `store_id`, `owner_user_id`, `notification_scope`, `status`);

UPDATE `competitor_wechat_robot` AS robot
INNER JOIN `hotels` AS hotel ON hotel.`id` = robot.`store_id`
SET robot.`tenant_id` = hotel.`tenant_id`
WHERE hotel.`tenant_id` IS NOT NULL
  AND hotel.`tenant_id` > 0
  AND (robot.`tenant_id` IS NULL OR robot.`tenant_id` <> hotel.`tenant_id`);

ALTER TABLE `account_wechat_push_policies`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'Owning tenant resolved from the policy hotel'
    AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_account_wechat_push_tenant_scope`
    (`tenant_id`, `hotel_id`, `owner_user_id`, `status`);

UPDATE `account_wechat_push_policies` AS policy
INNER JOIN `hotels` AS hotel ON hotel.`id` = policy.`hotel_id`
SET policy.`tenant_id` = hotel.`tenant_id`
WHERE hotel.`tenant_id` IS NOT NULL
  AND hotel.`tenant_id` > 0
  AND (policy.`tenant_id` IS NULL OR policy.`tenant_id` <> hotel.`tenant_id`);

ALTER TABLE `manual_notification_schedule_dispatches`
  ADD COLUMN IF NOT EXISTS `schedule_run_id` BIGINT UNSIGNED DEFAULT NULL
    COMMENT 'Optional scheduler invocation that claimed this logical delivery'
    AFTER `notification_id`,
  ADD INDEX IF NOT EXISTS `idx_manual_notification_dispatch_run` (`schedule_run_id`);
