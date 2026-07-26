-- Customer-facing WeCom bindings. Existing admin-managed robots remain valid
-- and are never overwritten by a user self-service binding.
ALTER TABLE `competitor_wechat_robot`
    ADD COLUMN IF NOT EXISTS `owner_user_id` INT UNSIGNED NULL AFTER `store_id`,
    ADD COLUMN IF NOT EXISTS `notification_scope` VARCHAR(40) NULL AFTER `owner_user_id`,
    ADD COLUMN IF NOT EXISTS `last_tested_at` DATETIME NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `last_test_status` VARCHAR(24) NULL AFTER `last_tested_at`,
    ADD INDEX IF NOT EXISTS `idx_account_notification_binding` (`store_id`, `owner_user_id`, `notification_scope`);
