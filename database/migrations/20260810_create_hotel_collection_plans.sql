-- One durable, hotel-scoped collection plan. Login state and browser Profile
-- material remain on the operator-owned execution device and are never stored
-- in this table. Only non-secret source and binding digests are persisted.
CREATE TABLE IF NOT EXISTS `hotel_collection_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `system_hotel_id` int unsigned NOT NULL,
  `plan_version` int unsigned NOT NULL DEFAULT 1,
  `plan_status` varchar(24) NOT NULL DEFAULT 'draft',
  `enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `business_date_policy` varchar(40) NOT NULL DEFAULT 'previous_business_day',
  `timezone` varchar(40) NOT NULL DEFAULT 'Asia/Shanghai',
  `schedule_time` char(5) NOT NULL DEFAULT '08:30',
  `retry_interval_minutes` smallint unsigned NOT NULL DEFAULT 14,
  `max_attempts` smallint unsigned NOT NULL DEFAULT 7,
  `execution_owner_user_id` int unsigned DEFAULT NULL,
  `binding_digest` char(64) NOT NULL,
  `plan_hash` char(64) NOT NULL,
  `source_plan_json` json NOT NULL,
  `validation_status` varchar(32) NOT NULL DEFAULT 'blocked',
  `validation_reasons_json` json NOT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `updated_by` int unsigned NOT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hotel_collection_plan_scope` (`tenant_id`, `system_hotel_id`),
  KEY `idx_hotel_collection_plan_enabled` (`enabled`, `plan_status`, `schedule_time`),
  KEY `idx_hotel_collection_plan_owner` (`tenant_id`, `execution_owner_user_id`, `plan_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hotel-scoped collection plan with secret-free binding and validation receipts';
