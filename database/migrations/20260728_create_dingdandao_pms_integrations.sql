-- Hotel-scoped Dingdandao PMS binding and verified WeCom delivery ledger.
-- Webhook secrets remain in competitor_wechat_robot and are never copied here.
CREATE TABLE IF NOT EXISTS `dingdandao_pms_integrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `provider` varchar(32) NOT NULL DEFAULT 'dingdandao_pms',
  `provider_hotel_id` varchar(120) DEFAULT NULL,
  `provider_hotel_name` varchar(160) DEFAULT NULL,
  `source_url` varchar(500) NOT NULL,
  `robot_id` int unsigned DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT 0,
  `auto_push_enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `last_capture_id` bigint unsigned DEFAULT NULL,
  `last_capture_business_date` date DEFAULT NULL,
  `last_capture_status` varchar(32) DEFAULT NULL,
  `last_readback_status` varchar(32) DEFAULT NULL,
  `last_push_business_date` date DEFAULT NULL,
  `last_push_status` varchar(32) DEFAULT NULL,
  `last_push_at` datetime DEFAULT NULL,
  `last_push_error` varchar(500) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dingdandao_pms_hotel_provider`
    (`tenant_id`, `hotel_id`, `provider`),
  KEY `idx_dingdandao_pms_robot` (`hotel_id`, `robot_id`, `status`),
  KEY `idx_dingdandao_pms_push` (`auto_push_enabled`, `status`, `hotel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dingdandao PMS hotel binding and verified WeCom push policy';

CREATE TABLE IF NOT EXISTS `dingdandao_pms_push_dispatches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `integration_id` bigint unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `capture_id` bigint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `source_fingerprint` char(64) NOT NULL,
  `robot_id` int unsigned NOT NULL,
  `trigger_type` varchar(24) NOT NULL,
  `delivery_status` varchar(32) NOT NULL DEFAULT 'pending',
  `attempt_count` int unsigned NOT NULL DEFAULT 1,
  `delivery_receipt_json` json DEFAULT NULL,
  `error_summary` varchar(500) DEFAULT NULL,
  `claimed_at` datetime NOT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dingdandao_pms_capture_robot`
    (`integration_id`, `capture_id`, `robot_id`),
  KEY `idx_dingdandao_pms_dispatch_hotel`
    (`tenant_id`, `hotel_id`, `business_date`, `id`),
  KEY `idx_dingdandao_pms_dispatch_status`
    (`delivery_status`, `claimed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Idempotent Dingdandao verified fact delivery receipts';
