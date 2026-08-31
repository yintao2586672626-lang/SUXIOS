CREATE TABLE IF NOT EXISTS `ota_settlement_import_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_version` VARCHAR(80) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED NOT NULL,
  `platform` VARCHAR(30) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `file_sha256` CHAR(64) NOT NULL,
  `source_evidence_sha256` CHAR(64) DEFAULT NULL,
  `source_method` VARCHAR(40) NOT NULL,
  `source_quality_status` VARCHAR(40) NOT NULL,
  `parser_version` VARCHAR(80) NOT NULL,
  `supersedes_batch_id` BIGINT UNSIGNED DEFAULT NULL,
  `supersession_reason` VARCHAR(64) DEFAULT NULL,
  `batch_fingerprint` CHAR(64) NOT NULL,
  `batch_status` VARCHAR(30) NOT NULL,
  `line_count` INT UNSIGNED NOT NULL,
  `available_line_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `partial_line_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `invalid_line_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `gross_amount_total` DECIMAL(16,2) DEFAULT NULL,
  `gross_amount_total_basis` VARCHAR(50) NOT NULL,
  `commission_amount_total` DECIMAL(16,2) DEFAULT NULL,
  `commission_amount_total_basis` VARCHAR(50) NOT NULL,
  `subsidy_amount_total` DECIMAL(16,2) DEFAULT NULL,
  `subsidy_amount_total_basis` VARCHAR(50) NOT NULL,
  `refund_amount_total` DECIMAL(16,2) DEFAULT NULL,
  `refund_amount_total_basis` VARCHAR(50) NOT NULL,
  `settlement_amount_total` DECIMAL(16,2) DEFAULT NULL,
  `settlement_amount_total_basis` VARCHAR(50) NOT NULL,
  `net_revenue_total` DECIMAL(16,2) DEFAULT NULL,
  `net_revenue_total_basis` VARCHAR(50) NOT NULL,
  `external_write_authorized` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `imported_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `imported_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ota_settlement_scope_file_version` (`tenant_id`, `hotel_id`, `platform`, `period_start`, `period_end`, `file_sha256`, `parser_version`, `source_quality_status`),
  KEY `idx_ota_settlement_supersedes` (`supersedes_batch_id`),
  KEY `idx_ota_settlement_scope_period` (`tenant_id`, `hotel_id`, `platform`, `period_end`, `id`),
  KEY `idx_ota_settlement_batch_status` (`tenant_id`, `hotel_id`, `batch_status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PII-free immutable OTA settlement import batches';

CREATE TABLE IF NOT EXISTS `ota_settlement_line_facts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `source_line_no` INT UNSIGNED NOT NULL,
  `source_line_sha256` CHAR(64) NOT NULL,
  `line_fingerprint` CHAR(64) NOT NULL,
  `business_date` DATE DEFAULT NULL,
  `amount_scope` VARCHAR(30) DEFAULT NULL,
  `ota_order_ref_sha256` CHAR(64) DEFAULT NULL,
  `pms_stay_ref_sha256` CHAR(64) DEFAULT NULL,
  `gross_amount` DECIMAL(16,2) DEFAULT NULL,
  `gross_amount_basis` VARCHAR(50) NOT NULL,
  `commission_amount` DECIMAL(16,2) DEFAULT NULL,
  `commission_amount_basis` VARCHAR(50) NOT NULL,
  `subsidy_amount` DECIMAL(16,2) DEFAULT NULL,
  `subsidy_amount_basis` VARCHAR(50) NOT NULL,
  `refund_amount` DECIMAL(16,2) DEFAULT NULL,
  `refund_amount_basis` VARCHAR(50) NOT NULL,
  `settlement_amount` DECIMAL(16,2) DEFAULT NULL,
  `settlement_amount_basis` VARCHAR(50) NOT NULL,
  `net_revenue` DECIMAL(16,2) DEFAULT NULL,
  `net_revenue_basis` VARCHAR(80) NOT NULL,
  `net_revenue_formula` VARCHAR(80) DEFAULT NULL,
  `match_status` VARCHAR(40) NOT NULL,
  `ota_comparison_amount` DECIMAL(16,2) DEFAULT NULL,
  `pms_comparison_amount` DECIMAL(16,2) DEFAULT NULL,
  `comparison_basis` VARCHAR(40) DEFAULT NULL,
  `discrepancy_amount` DECIMAL(16,2) DEFAULT NULL,
  `discrepancy_basis` VARCHAR(100) NOT NULL,
  `quality_status` VARCHAR(30) NOT NULL,
  `gap_codes_json` JSON NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ota_settlement_batch_line` (`batch_id`, `source_line_no`),
  UNIQUE KEY `uk_ota_settlement_batch_source_hash` (`batch_id`, `source_line_sha256`),
  KEY `idx_ota_settlement_discrepancy` (`batch_id`, `discrepancy_amount`, `source_line_no`),
  KEY `idx_ota_settlement_match_status` (`batch_id`, `match_status`, `quality_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PII-free OTA settlement and OTA-PMS reconciliation line facts';

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_ota_settlement_batch_no_update`
BEFORE UPDATE ON `ota_settlement_import_batches`
FOR EACH ROW
BEGIN
  IF NOT (
    COALESCE(@suxi_cloud_hotel_id_migration, 0) = 1
    AND NEW.`hotel_id` <> OLD.`hotel_id`
    AND NEW.`source_hotel_id` = OLD.`source_hotel_id`
    AND NEW.`batch_fingerprint` = OLD.`batch_fingerprint`
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OTA settlement batches are append-only';
  END IF;
END$$
DELIMITER ;

CREATE TRIGGER IF NOT EXISTS `trg_ota_settlement_batch_no_delete`
BEFORE DELETE ON `ota_settlement_import_batches`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OTA settlement batches are append-only';

CREATE TRIGGER IF NOT EXISTS `trg_ota_settlement_line_no_update`
BEFORE UPDATE ON `ota_settlement_line_facts`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OTA settlement line facts are append-only';

CREATE TRIGGER IF NOT EXISTS `trg_ota_settlement_line_no_delete`
BEFORE DELETE ON `ota_settlement_line_facts`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OTA settlement line facts are append-only';

-- Manual rollback only:
-- DROP TABLE IF EXISTS `ota_settlement_line_facts`;
-- DROP TABLE IF EXISTS `ota_settlement_import_batches`;
