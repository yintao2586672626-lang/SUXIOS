CREATE TABLE IF NOT EXISTS `platform_data_sources` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `system_hotel_id` int unsigned DEFAULT NULL COMMENT '系统酒店ID',
  `user_id` int unsigned DEFAULT NULL COMMENT '创建用户ID',
  `name` varchar(120) NOT NULL COMMENT '数据源名称',
  `platform` varchar(50) NOT NULL COMMENT '平台: ctrip/meituan/custom',
  `data_type` varchar(50) NOT NULL DEFAULT 'business' COMMENT '数据类型',
  `ingestion_method` varchar(30) NOT NULL DEFAULT 'manual' COMMENT 'api/import_json/manual/browser_profile',
  `status` varchar(30) NOT NULL DEFAULT 'waiting_config' COMMENT 'waiting_config/ready/success/failed/disabled',
  `enabled` tinyint NOT NULL DEFAULT 1,
  `config_json` longtext NULL COMMENT '非敏感配置JSON',
  `secret_json` longtext NULL COMMENT '敏感凭证JSON，接口返回必须脱敏',
  `last_sync_time` datetime DEFAULT NULL,
  `last_sync_status` varchar(30) DEFAULT NULL,
  `last_error` text NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_data_sources_hotel` (`system_hotel_id`, `platform`, `data_type`),
  KEY `idx_platform_data_sources_user` (`user_id`),
  KEY `idx_platform_data_sources_status` (`enabled`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台数据源配置表';

CREATE TABLE IF NOT EXISTS `platform_data_sync_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `data_source_id` int unsigned DEFAULT NULL,
  `system_hotel_id` int unsigned DEFAULT NULL,
  `platform` varchar(50) NOT NULL,
  `data_type` varchar(50) NOT NULL DEFAULT 'business',
  `ingestion_method` varchar(30) NOT NULL DEFAULT 'manual',
  `trigger_type` varchar(30) NOT NULL DEFAULT 'manual',
  `status` varchar(30) NOT NULL DEFAULT 'pending' COMMENT 'pending/running/success/partial_success/failed/waiting_config',
  `attempt_count` int NOT NULL DEFAULT 0,
  `max_attempts` int NOT NULL DEFAULT 3,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `next_retry_at` datetime DEFAULT NULL,
  `requested_by` int unsigned DEFAULT NULL,
  `message` text NULL,
  `stats_json` text NULL,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_sync_source` (`data_source_id`, `status`),
  KEY `idx_platform_sync_hotel` (`system_hotel_id`, `platform`, `data_type`),
  KEY `idx_platform_sync_retry` (`status`, `next_retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台数据同步任务表';

CREATE TABLE IF NOT EXISTS `platform_data_raw_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `data_source_id` int unsigned DEFAULT NULL,
  `sync_task_id` bigint unsigned DEFAULT NULL,
  `system_hotel_id` int unsigned DEFAULT NULL,
  `platform` varchar(50) NOT NULL,
  `data_type` varchar(50) NOT NULL DEFAULT 'business',
  `ingestion_method` varchar(30) NOT NULL DEFAULT 'manual',
  `payload_hash` char(64) NOT NULL,
  `raw_payload` longtext NOT NULL,
  `http_status` int DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_raw_task_hash` (`sync_task_id`, `payload_hash`),
  KEY `idx_platform_raw_source` (`data_source_id`, `received_at`),
  KEY `idx_platform_raw_hotel` (`system_hotel_id`, `platform`, `data_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台原始响应和导入数据表';

CREATE TABLE IF NOT EXISTS `platform_data_sync_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sync_task_id` bigint unsigned DEFAULT NULL,
  `data_source_id` int unsigned DEFAULT NULL,
  `system_hotel_id` int unsigned DEFAULT NULL,
  `level` varchar(20) NOT NULL DEFAULT 'info',
  `event` varchar(80) NOT NULL,
  `message` text NULL,
  `context_json` text NULL,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_sync_logs_task` (`sync_task_id`, `create_time`),
  KEY `idx_platform_sync_logs_source` (`data_source_id`, `create_time`),
  KEY `idx_platform_sync_logs_hotel` (`system_hotel_id`, `level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台同步失败和过程日志表';

ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `data_source_id` int unsigned DEFAULT NULL COMMENT '平台数据源ID' AFTER `validation_flags`,
  ADD COLUMN IF NOT EXISTS `sync_task_id` bigint unsigned DEFAULT NULL COMMENT '平台同步任务ID' AFTER `data_source_id`,
  ADD COLUMN IF NOT EXISTS `ingestion_method` varchar(30) NOT NULL DEFAULT 'legacy' COMMENT 'api/import_json/manual/browser_profile/legacy' AFTER `sync_task_id`,
  ADD COLUMN IF NOT EXISTS `source_trace_id` varchar(80) DEFAULT NULL COMMENT '数据来源追踪ID' AFTER `ingestion_method`,
  ADD INDEX IF NOT EXISTS `idx_online_daily_source_trace` (`data_source_id`, `sync_task_id`),
  ADD INDEX IF NOT EXISTS `idx_online_daily_ingestion` (`ingestion_method`, `source`, `data_type`);
