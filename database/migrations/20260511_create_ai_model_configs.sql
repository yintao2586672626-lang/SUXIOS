CREATE TABLE IF NOT EXISTS `ai_model_configs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'display name',
    `model_key` VARCHAR(80) NOT NULL COMMENT 'frontend model key',
    `provider` VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'provider name',
    `base_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'OpenAI compatible base URL',
    `model_name` VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'provider model name',
    `api_key_encrypted` TEXT DEFAULT NULL COMMENT 'encrypted API key',
    `api_key_mask` VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'masked API key',
    `usage_scene` VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'usage scene',
    `is_default` TINYINT NOT NULL DEFAULT 0 COMMENT 'default model flag',
    `is_enabled` TINYINT NOT NULL DEFAULT 1 COMMENT 'enabled flag',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ai_model_key` (`model_key`),
    INDEX `idx_ai_model_enabled` (`is_enabled`),
    INDEX `idx_ai_model_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI model configurations';
