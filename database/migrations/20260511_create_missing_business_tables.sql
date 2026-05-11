CREATE TABLE IF NOT EXISTS `hotel_field_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `template_name` VARCHAR(120) NOT NULL DEFAULT '',
    `is_default` TINYINT NOT NULL DEFAULT 1,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_id` (`hotel_id`),
    INDEX `idx_hotel_default` (`hotel_id`, `is_default`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Hotel field mapping templates';

CREATE TABLE IF NOT EXISTS `hotel_field_template_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `template_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `excel_item_name` VARCHAR(120) NOT NULL DEFAULT '',
    `system_field` VARCHAR(120) NOT NULL DEFAULT '',
    `field_type` VARCHAR(40) NOT NULL DEFAULT 'number',
    `row_start` INT DEFAULT NULL,
    `row_end` INT DEFAULT NULL,
    `value_column` VARCHAR(16) NOT NULL DEFAULT 'E',
    `category` VARCHAR(80) NOT NULL DEFAULT '',
    `merge_rule` VARCHAR(40) NOT NULL DEFAULT 'sum',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT NOT NULL DEFAULT 1,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_template_id` (`template_id`),
    INDEX `idx_template_active_sort` (`template_id`, `is_active`, `sort_order`),
    INDEX `idx_system_field` (`system_field`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Hotel field mapping template items';

CREATE TABLE IF NOT EXISTS `competitor_hotel` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `platform` VARCHAR(20) NOT NULL DEFAULT '',
    `city` VARCHAR(80) NOT NULL DEFAULT '',
    `hotel_name` VARCHAR(160) NOT NULL DEFAULT '',
    `hotel_code` VARCHAR(120) NOT NULL DEFAULT '',
    `status` TINYINT NOT NULL DEFAULT 1,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_platform` (`platform`),
    INDEX `idx_status` (`status`),
    INDEX `idx_city` (`city`),
    INDEX `idx_store_platform_status` (`store_id`, `platform`, `status`),
    INDEX `idx_hotel_code` (`hotel_code`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Competitor hotels';

CREATE TABLE IF NOT EXISTS `competitor_price_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `platform` VARCHAR(20) NOT NULL DEFAULT '',
    `city` VARCHAR(80) NOT NULL DEFAULT '',
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `screenshot` VARCHAR(255) NOT NULL DEFAULT '',
    `device_id` VARCHAR(120) NOT NULL DEFAULT '',
    `fetch_time` DATETIME DEFAULT NULL,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_hotel_id` (`hotel_id`),
    INDEX `idx_platform` (`platform`),
    INDEX `idx_city` (`city`),
    INDEX `idx_fetch_time` (`fetch_time`),
    INDEX `idx_store_platform_fetch` (`store_id`, `platform`, `fetch_time`),
    INDEX `idx_hotel_platform_fetch` (`hotel_id`, `platform`, `fetch_time`),
    INDEX `idx_device_id` (`device_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Competitor price logs';

CREATE TABLE IF NOT EXISTS `competitor_device` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `device_id` VARCHAR(120) NOT NULL DEFAULT '',
    `name` VARCHAR(120) NOT NULL DEFAULT '',
    `status` TINYINT NOT NULL DEFAULT 1,
    `last_time` DATETIME DEFAULT NULL,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_device_id` (`device_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_last_time` (`last_time`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Competitor collection devices';

CREATE TABLE IF NOT EXISTS `competitor_wechat_robot` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `name` VARCHAR(120) NOT NULL DEFAULT '',
    `webhook` VARCHAR(512) NOT NULL DEFAULT '',
    `status` TINYINT NOT NULL DEFAULT 1,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_store_status` (`store_id`, `status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Competitor WeCom robots';

CREATE TABLE IF NOT EXISTS `feasibility_reports` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_name` VARCHAR(120) NOT NULL,
    `input_json` LONGTEXT DEFAULT NULL,
    `snapshot_json` LONGTEXT DEFAULT NULL,
    `report_json` LONGTEXT DEFAULT NULL,
    `conclusion_grade` VARCHAR(8) DEFAULT NULL,
    `payback_months` DECIMAL(10,2) DEFAULT NULL,
    `total_investment` DECIMAL(14,2) DEFAULT 0.00,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_grade` (`conclusion_grade`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Feasibility reports';
