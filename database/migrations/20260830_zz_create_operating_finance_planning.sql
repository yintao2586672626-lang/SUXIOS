CREATE TABLE IF NOT EXISTS `hotel_on_books_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_version` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED NOT NULL,
  `platform` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fact_scope` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `stay_date` DATE NOT NULL,
  `captured_at` DATETIME(6) NOT NULL,
  `source_method` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_ref_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `on_books_room_nights` DECIMAL(12,2) DEFAULT NULL,
  `on_books_room_revenue` DECIMAL(16,2) DEFAULT NULL,
  `cumulative_cancel_room_nights` DECIMAL(12,2) DEFAULT NULL,
  `gross_booking_room_nights` DECIMAL(12,2) DEFAULT NULL,
  `quality_status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `readback_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `idempotency_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_on_books_idempotency` (`tenant_id`, `hotel_id`, `platform`, `stay_date`, `idempotency_key`),
  KEY `idx_on_books_compare` (`tenant_id`, `hotel_id`, `platform`, `stay_date`, `quality_status`, `captured_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only verified on-books snapshots for real pickup and cancellation pace';

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_on_books_snapshot_no_update`
BEFORE UPDATE ON `hotel_on_books_snapshots`
FOR EACH ROW
BEGIN
  IF NOT (
    COALESCE(@suxi_cloud_hotel_id_migration, 0) = 1
    AND NEW.`hotel_id` <> OLD.`hotel_id`
    AND NEW.`source_hotel_id` = OLD.`source_hotel_id`
    AND NEW.`content_digest` = OLD.`content_digest`
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hotel on-books snapshots are append-only';
  END IF;
END$$
DELIMITER ;

CREATE TRIGGER IF NOT EXISTS `trg_on_books_snapshot_no_delete`
BEFORE DELETE ON `hotel_on_books_snapshots`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hotel on-books snapshots are append-only';

CREATE TABLE IF NOT EXISTS `hotel_demand_event_facts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_version` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED NOT NULL,
  `event_name` VARCHAR(160) NOT NULL,
  `event_type` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_start_date` DATE NOT NULL,
  `event_end_date` DATE NOT NULL,
  `area_label` VARCHAR(160) NOT NULL,
  `source_method` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_ref_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `observed_at` DATETIME(6) NOT NULL,
  `reference_only` TINYINT(1) NOT NULL DEFAULT 1,
  `idempotency_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_demand_event_idempotency` (`tenant_id`, `hotel_id`, `idempotency_key`),
  KEY `idx_demand_event_window` (`tenant_id`, `hotel_id`, `event_start_date`, `event_end_date`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only hotel-local demand event references; never automatic pricing facts';

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_demand_event_no_update`
BEFORE UPDATE ON `hotel_demand_event_facts`
FOR EACH ROW
BEGIN
  IF NOT (
    COALESCE(@suxi_cloud_hotel_id_migration, 0) = 1
    AND NEW.`hotel_id` <> OLD.`hotel_id`
    AND NEW.`source_hotel_id` = OLD.`source_hotel_id`
    AND NEW.`content_digest` = OLD.`content_digest`
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hotel demand event facts are append-only';
  END IF;
END$$
DELIMITER ;

CREATE TRIGGER IF NOT EXISTS `trg_demand_event_no_delete`
BEFORE DELETE ON `hotel_demand_event_facts`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hotel demand event facts are append-only';

CREATE TABLE IF NOT EXISTS `hotel_monthly_operating_finance_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_version` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED NOT NULL,
  `period_month` CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `fact_scope` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_method` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_quality_status` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `currency` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tax_basis` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `metric_definition_version` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_refs_json` JSON NOT NULL,
  `inputs_json` JSON NOT NULL,
  `results_json` JSON NOT NULL,
  `missing_items_json` JSON NOT NULL,
  `idempotency_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_monthly_finance_version` (`tenant_id`, `hotel_id`, `period_month`, `version_no`),
  UNIQUE KEY `uniq_monthly_finance_idempotency` (`tenant_id`, `hotel_id`, `period_month`, `idempotency_key`),
  KEY `idx_monthly_finance_latest` (`tenant_id`, `hotel_id`, `period_month`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only source-scoped monthly operating finance snapshots';

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_monthly_finance_no_update`
BEFORE UPDATE ON `hotel_monthly_operating_finance_snapshots`
FOR EACH ROW
BEGIN
  IF NOT (
    COALESCE(@suxi_cloud_hotel_id_migration, 0) = 1
    AND NEW.`hotel_id` <> OLD.`hotel_id`
    AND NEW.`source_hotel_id` = OLD.`source_hotel_id`
    AND NEW.`content_digest` = OLD.`content_digest`
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hotel monthly operating finance snapshots are append-only';
  END IF;
END$$
DELIMITER ;

CREATE TRIGGER IF NOT EXISTS `trg_monthly_finance_no_delete`
BEFORE DELETE ON `hotel_monthly_operating_finance_snapshots`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hotel monthly operating finance snapshots are append-only';

-- Application routes intentionally expose no UPDATE or DELETE for these ledgers.
-- Manual rollback only:
-- DROP TABLE IF EXISTS `hotel_monthly_operating_finance_snapshots`;
-- DROP TABLE IF EXISTS `hotel_demand_event_facts`;
-- DROP TABLE IF EXISTS `hotel_on_books_snapshots`;
