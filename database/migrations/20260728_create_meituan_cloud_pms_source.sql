-- Hotel-scoped Meituan Cloud PMS binding and sanitized operating facts.
-- Cookies, tokens, request headers, guest PII and raw account responses are
-- deliberately excluded from this storage boundary.
CREATE TABLE IF NOT EXISTS `meituan_cloud_pms_integrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `provider` varchar(32) NOT NULL DEFAULT 'meituan_cloud_pms',
  `provider_hotel_id` varchar(120) DEFAULT NULL,
  `provider_hotel_name` varchar(160) DEFAULT NULL,
  `source_url` varchar(500) NOT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT 0,
  `last_capture_id` bigint unsigned DEFAULT NULL,
  `last_capture_business_date` date DEFAULT NULL,
  `last_capture_status` varchar(32) DEFAULT NULL,
  `last_readback_status` varchar(32) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_meituan_cloud_pms_hotel_provider`
    (`tenant_id`, `hotel_id`, `provider`),
  KEY `idx_meituan_cloud_pms_status` (`status`, `hotel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Independent Meituan Cloud PMS hotel identity binding';

CREATE TABLE IF NOT EXISTS `meituan_cloud_pms_captures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `provider` varchar(32) NOT NULL DEFAULT 'meituan_cloud_pms',
  `provider_hotel_id` varchar(120) DEFAULT NULL,
  `provider_hotel_name` varchar(160) DEFAULT NULL,
  `expected_hotel_name` varchar(160) NOT NULL,
  `identity_evidence_type` varchar(64) NOT NULL DEFAULT 'unverified',
  `identity_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `date_evidence_type` varchar(64) NOT NULL DEFAULT 'unverified',
  `date_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `source_url` varchar(500) NOT NULL,
  `source_scope` varchar(48) NOT NULL DEFAULT 'today_realtime_accommodation',
  `capture_method` varchar(40) NOT NULL DEFAULT 'same_origin_api',
  `business_date` date NOT NULL,
  `estimated_room_revenue` decimal(14,2) DEFAULT NULL,
  `adr` decimal(12,2) DEFAULT NULL,
  `revpar` decimal(12,2) DEFAULT NULL,
  `sold_room_nights` int unsigned DEFAULT NULL,
  `total_rooms` int unsigned DEFAULT NULL,
  `available_rooms` int unsigned DEFAULT NULL,
  `room_type_available_rooms` int unsigned DEFAULT NULL,
  `occupancy_rate_percent` decimal(7,2) DEFAULT NULL,
  `sale_order_count` int unsigned DEFAULT NULL,
  `room_type_count` int unsigned NOT NULL DEFAULT 0,
  `availability_difference` int unsigned DEFAULT NULL,
  `availability_tolerance` int unsigned DEFAULT NULL,
  `reconciliation_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `capture_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `quality_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `quality_reason` varchar(500) DEFAULT NULL,
  `gap_codes_json` json DEFAULT NULL,
  `validation_warnings_json` json DEFAULT NULL,
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
  KEY `idx_meituan_cloud_capture_scope`
    (`tenant_id`, `hotel_id`, `business_date`, `id`),
  KEY `idx_meituan_cloud_capture_quality`
    (`tenant_id`, `hotel_id`, `quality_status`, `business_date`),
  KEY `idx_meituan_cloud_capture_fingerprint`
    (`source_fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sanitized Meituan Cloud PMS realtime room facts with identity date and reconciliation gates';

CREATE TABLE IF NOT EXISTS `meituan_cloud_pms_room_type_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `capture_id` bigint unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `business_date` date NOT NULL,
  `room_type` varchar(160) NOT NULL,
  `total_rooms` int unsigned NOT NULL,
  `sold_rooms` int unsigned NOT NULL,
  `available_rooms` int unsigned NOT NULL,
  `overbooked_rooms` int unsigned NOT NULL DEFAULT 0,
  `source_row_index` int unsigned NOT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_meituan_cloud_detail_capture` (`capture_id`, `source_row_index`),
  KEY `idx_meituan_cloud_detail_scope`
    (`tenant_id`, `hotel_id`, `business_date`, `capture_id`),
  CONSTRAINT `fk_meituan_cloud_room_type_capture`
    FOREIGN KEY (`capture_id`) REFERENCES `meituan_cloud_pms_captures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Meituan Cloud PMS room-type inventory facts without guest or order PII';
