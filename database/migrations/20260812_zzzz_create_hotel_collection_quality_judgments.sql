-- One readback-verifiable quality judgment for an exact collection run scope.
-- This table stores only the public receipt projection; it never stores login,
-- Cookie, token, browser Profile or raw OTA/PMS response material.
CREATE TABLE IF NOT EXISTS `hotel_collection_quality_judgments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schema_version` tinyint unsigned NOT NULL DEFAULT 1,
  `tenant_id` int unsigned NOT NULL,
  `system_hotel_id` int unsigned NOT NULL,
  `business_date` date NOT NULL,
  `dispatcher_run_id` char(36) NOT NULL,
  `collection_run_receipt_id` bigint unsigned DEFAULT NULL,
  `source_scope_hash` char(64) NOT NULL,
  `saved_row_count` int unsigned NOT NULL DEFAULT 0,
  `readback_row_count` int unsigned NOT NULL DEFAULT 0,
  `missing_count` int unsigned NOT NULL DEFAULT 0,
  `conflict_count` int unsigned NOT NULL DEFAULT 0,
  `freshness_status` varchar(20) NOT NULL,
  `conclusion_status` varchar(20) NOT NULL,
  `evidence_digest` char(64) NOT NULL,
  `judgment_digest` char(64) NOT NULL,
  `judgment_json` json NOT NULL,
  `assessed_at` datetime NOT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hotel_collection_quality_dispatcher` (`dispatcher_run_id`),
  UNIQUE KEY `uq_hotel_collection_quality_scope`
    (`tenant_id`, `system_hotel_id`, `business_date`, `dispatcher_run_id`),
  KEY `idx_hotel_collection_quality_latest`
    (`tenant_id`, `system_hotel_id`, `business_date`, `id`),
  KEY `idx_hotel_collection_quality_status`
    (`conclusion_status`, `freshness_status`, `business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Readback-verifiable quality judgments over public collection receipts';
