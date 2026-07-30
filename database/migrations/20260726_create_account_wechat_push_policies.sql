-- Account and hotel scoped cloud WeCom push policies. Robot secrets remain in
-- competitor_wechat_robot and are never copied into this policy table.
CREATE TABLE IF NOT EXISTS `account_wechat_push_policies` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL,
    `owner_user_id` INT UNSIGNED NOT NULL,
    `robot_id` INT UNSIGNED NOT NULL,
    `failure_robot_id` INT UNSIGNED NULL,
    `frequency` VARCHAR(16) NOT NULL DEFAULT 'hourly',
    `template_key` VARCHAR(40) NOT NULL DEFAULT 'hourly_monitor',
    `visual_card_enabled` TINYINT NOT NULL DEFAULT 0,
    `failure_alert_enabled` TINYINT NOT NULL DEFAULT 0,
    `status` TINYINT NOT NULL DEFAULT 1,
    `timezone` VARCHAR(40) NOT NULL DEFAULT 'Asia/Shanghai',
    `last_dispatch_window` VARCHAR(24) NULL,
    `last_delivery_status` VARCHAR(32) NULL,
    `last_failure_alert_status` VARCHAR(32) NULL,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_account_hotel_push_template` (`hotel_id`, `owner_user_id`, `template_key`),
    KEY `idx_account_wechat_push_due` (`status`, `frequency`, `hotel_id`),
    KEY `idx_account_wechat_push_robot` (`robot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Account hotel scoped cloud WeCom push scheduling policies';
