-- Whole-hotel operating target records. OTA channel data must not be written
-- here as a substitute for total-hotel operating facts.
CREATE TABLE IF NOT EXISTS `operating_target_daily_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `target_date` date NOT NULL,
  `target_revenue` decimal(14,2) DEFAULT NULL,
  `actual_revenue` decimal(14,2) DEFAULT NULL,
  `sold_room_nights` int unsigned DEFAULT NULL,
  `sellable_room_nights` int unsigned DEFAULT NULL,
  `fact_scope` varchar(32) NOT NULL DEFAULT 'whole_hotel',
  `source_type` varchar(32) NOT NULL DEFAULT 'manual',
  `source_reference` varchar(255) DEFAULT NULL,
  `quality_status` varchar(32) NOT NULL DEFAULT 'unverified',
  `quality_reason` varchar(255) DEFAULT NULL,
  `fact_captured_at` datetime DEFAULT NULL,
  `calculation_status` varchar(32) NOT NULL DEFAULT 'partial',
  `gap_codes_json` json DEFAULT NULL,
  `calculation_json` json DEFAULT NULL,
  `report_status` varchar(32) NOT NULL DEFAULT 'draft',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operating_target_tenant_hotel_date` (`tenant_id`, `hotel_id`, `target_date`),
  KEY `idx_operating_target_hotel_date` (`tenant_id`, `hotel_id`, `target_date`, `update_time`),
  KEY `idx_operating_target_status` (`tenant_id`, `calculation_status`, `target_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Whole-hotel daily operating target current record with explicit fact quality';

CREATE TABLE IF NOT EXISTS `operating_target_daily_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `record_id` bigint unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `hotel_id` int unsigned NOT NULL,
  `target_date` date NOT NULL,
  `revision_no` int unsigned NOT NULL,
  `change_reason` varchar(500) DEFAULT NULL,
  `snapshot_json` json NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `create_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operating_target_snapshot_revision` (`record_id`, `revision_no`),
  KEY `idx_operating_target_snapshot_lookup` (`tenant_id`, `hotel_id`, `target_date`, `revision_no`),
  CONSTRAINT `fk_operating_target_snapshot_record`
    FOREIGN KEY (`record_id`) REFERENCES `operating_target_daily_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only revisions for whole-hotel daily operating targets';
