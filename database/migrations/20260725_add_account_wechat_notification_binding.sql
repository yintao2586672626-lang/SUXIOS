-- Customer-facing WeCom bindings. Existing admin-managed robots remain valid
-- and are never overwritten by a user self-service binding.
ALTER TABLE `competitor_wechat_robot`
    ADD COLUMN `owner_user_id` INT UNSIGNED NULL AFTER `store_id`,
    ADD COLUMN `notification_scope` VARCHAR(40) NULL AFTER `owner_user_id`,
    ADD COLUMN `last_tested_at` DATETIME NULL AFTER `status`,
    ADD COLUMN `last_test_status` VARCHAR(24) NULL AFTER `last_tested_at`,
    ADD INDEX `idx_account_notification_binding` (`store_id`, `owner_user_id`, `notification_scope`);
