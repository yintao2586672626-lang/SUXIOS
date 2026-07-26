-- Sanitized, hotel-scoped Dingdandao PMS facts for operating targets.
-- Browser cookies, request headers, webhook values and guest PII are never
-- stored in these tables.
CREATE TABLE IF NOT EXISTS `dingdandao_operating_target_captures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `provider` varchar(32) NOT NULL DEFAULT 'dingdandao_pms',
  `provider_hotel_id` varchar(120) DEFAULT NULL,
  `provider_hotel_name` varchar(160) DEFAULT NULL,
  `expected_hotel_name` varchar(160) NOT NULL,
  `identity_evidence_type` varchar(48) NOT NULL DEFAULT 'unverified',
  `identity_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `source_url` varchar(500) NOT NULL,
  `source_api_path` varchar(255) DEFAULT NULL,
  `source_scope` varchar(32) NOT NULL DEFAULT 'today_only',
  `capture_method` varchar(40) NOT NULL DEFAULT 'browser_assist_dom',
  `business_date` date NOT NULL,
  `total_room_fee` decimal(14,2) DEFAULT NULL,
  `adr` decimal(12,2) DEFAULT NULL,
  `occupancy_rate_percent` decimal(7,2) DEFAULT NULL,
  `revpar` decimal(12,2) DEFAULT NULL,
  `sold_room_nights` int unsigned DEFAULT NULL,
  `average_daily_room_nights` decimal(10,2) DEFAULT NULL,
  `derived_sellable_room_nights` int unsigned DEFAULT NULL,
  `detail_room_fee_total` decimal(14,2) DEFAULT NULL,
  `detail_row_count` int unsigned NOT NULL DEFAULT 0,
  `reconciliation_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `capture_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `quality_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `quality_reason` varchar(500) DEFAULT NULL,
  `gap_codes_json` json DEFAULT NULL,
  `trend_json` json DEFAULT NULL,
  `field_trace_json` json DEFAULT NULL,
  `snapshot_json` json NOT NULL,
  `source_fingerprint` char(64) NOT NULL,
  `captured_at` datetime NOT NULL,
  `captured_by` int unsigned DEFAULT NULL,
  `readback_status` varchar(32) NOT NULL DEFAULT 'pending',
  `readback_verified_at` datetime DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dingdandao_capture_scope`
    (`tenant_id`, `hotel_id`, `business_date`, `id`),
  KEY `idx_dingdandao_capture_quality`
    (`tenant_id`, `hotel_id`, `quality_status`, `business_date`),
  KEY `idx_dingdandao_capture_fingerprint`
    (`source_fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sanitized Dingdandao accommodation facts with identity/date/reconciliation gates';

CREATE TABLE IF NOT EXISTS `dingdandao_room_fee_capture_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `capture_id` bigint unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `business_date` date NOT NULL,
  `row_kind` varchar(32) NOT NULL DEFAULT 'room',
  `room_type` varchar(160) DEFAULT NULL,
  `room_number` varchar(80) DEFAULT NULL,
  `room_fee` decimal(14,2) NOT NULL,
  `source_row_index` int unsigned NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dingdandao_detail_capture` (`capture_id`, `source_row_index`),
  KEY `idx_dingdandao_detail_scope`
    (`tenant_id`, `hotel_id`, `business_date`, `capture_id`),
  CONSTRAINT `fk_dingdandao_room_fee_capture`
    FOREIGN KEY (`capture_id`) REFERENCES `dingdandao_operating_target_captures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Room-type and room-level fee facts; zero is retained only when explicitly observed';
