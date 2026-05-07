-- 登录日志表
-- 用于记录用户登录、登出等操作日志

CREATE TABLE IF NOT EXISTS `login_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT '用户ID',
    `username` VARCHAR(50) NOT NULL COMMENT '用户名',
    `action` VARCHAR(20) NOT NULL COMMENT '操作类型: login/logout/refresh',
    `status` VARCHAR(20) NOT NULL COMMENT '状态: success/failed',
    `message` VARCHAR(255) DEFAULT NULL COMMENT '消息或失败原因',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP地址',
    `user_agent` VARCHAR(500) DEFAULT NULL COMMENT '用户代理',
    `client_info` JSON DEFAULT NULL COMMENT '客户端信息(浏览器、操作系统等)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_username` (`username`),
    INDEX `idx_action` (`action`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录日志表';

-- 用户表添加登录计数字段（如果不存在）
SET @exist := (SELECT COUNT(*) FROM information_schema.columns 
    WHERE table_name = 'users' AND column_name = 'login_count' AND table_schema = DATABASE());
SET @sql := IF(@exist = 0, 'ALTER TABLE users ADD COLUMN login_count INT UNSIGNED DEFAULT 0 COMMENT "登录次数" AFTER last_login_ip', 'SELECT "Column already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
