-- One durable parent receipt for one exact hotel-plan attempt. Login state,
-- Cookie material and browser Profile paths stay on the operator device.
CREATE TABLE IF NOT EXISTS `hotel_collection_plan_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dispatcher_run_id` char(36) NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `system_hotel_id` int unsigned NOT NULL,
  `business_date` date NOT NULL,
  `run_mode` varchar(16) NOT NULL,
  `trigger_type` varchar(32) NOT NULL DEFAULT 'scheduler',
  `plan_id` bigint unsigned DEFAULT NULL,
  `plan_version` int unsigned NOT NULL DEFAULT 0,
  `plan_hash` char(64) NOT NULL DEFAULT '',
  `scope_hash` char(64) NOT NULL,
  `execution_owner_user_id` int unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'started',
  `failure_stage` varchar(40) NOT NULL DEFAULT '',
  `failure_code` varchar(120) NOT NULL DEFAULT '',
  `collection_anchor_contract_version` varchar(40) DEFAULT NULL,
  `collection_anchor_hash` char(64) DEFAULT NULL,
  `trust_receipt_digest` char(64) DEFAULT NULL,
  `page_status` varchar(32) NOT NULL DEFAULT 'not_evaluated',
  `page_receipt_id` bigint unsigned DEFAULT NULL,
  `page_contract_hash` char(64) DEFAULT NULL,
  `pms_status` varchar(32) NOT NULL DEFAULT 'not_run',
  `pms_provider` varchar(40) DEFAULT NULL,
  `pms_capture_id` varchar(120) DEFAULT NULL,
  `pms_readback_verified` tinyint unsigned DEFAULT NULL,
  `receipt_json` json NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hotel_collection_plan_run_dispatcher` (`dispatcher_run_id`),
  UNIQUE KEY `uq_hotel_collection_plan_run_scope`
    (`tenant_id`, `system_hotel_id`, `business_date`, `dispatcher_run_id`),
  KEY `idx_hotel_collection_plan_run_status` (`status`, `business_date`, `update_time`),
  KEY `idx_hotel_collection_plan_run_hotel`
    (`tenant_id`, `system_hotel_id`, `business_date`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Exact hotel collection-plan attempts and aggregate trust state';

-- Two OTA source rows are created with the parent before any application gate
-- returns. A task id is legitimately NULL for a gate failure or queued device.
CREATE TABLE IF NOT EXISTS `hotel_collection_plan_run_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned NOT NULL,
  `platform` varchar(20) NOT NULL,
  `data_source_id` bigint unsigned DEFAULT NULL,
  `ingestion_method` varchar(32) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'declared',
  `platform_sync_task_id` bigint unsigned DEFAULT NULL,
  `local_collector_task_id` bigint unsigned DEFAULT NULL,
  `saved_row_count` int unsigned NOT NULL DEFAULT 0,
  `readback_row_count` int unsigned NOT NULL DEFAULT 0,
  `readback_verified` tinyint unsigned NOT NULL DEFAULT 0,
  `evidence_digest` char(64) DEFAULT NULL,
  `failure_stage` varchar(40) NOT NULL DEFAULT '',
  `failure_code` varchar(120) NOT NULL DEFAULT '',
  `page_acceptance_status` varchar(32) NOT NULL DEFAULT 'not_evaluated',
  `page_acceptance_log_id` bigint unsigned DEFAULT NULL,
  `receipt_json` json NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hotel_collection_plan_run_source` (`run_id`, `platform`),
  KEY `idx_hotel_collection_plan_run_source_task`
    (`data_source_id`, `platform_sync_task_id`),
  KEY `idx_hotel_collection_plan_run_source_status` (`status`, `update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Exact Ctrip and Meituan source outcomes for one hotel-plan attempt';
