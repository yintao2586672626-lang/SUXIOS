CREATE TABLE IF NOT EXISTS `hotel_room_type_on_books_snapshots` (
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
  `room_type_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `room_type_name` VARCHAR(120) NOT NULL,
  `on_books_room_nights` DECIMAL(14,4) DEFAULT NULL,
  `on_books_room_revenue` DECIMAL(18,4) DEFAULT NULL,
  `cumulative_cancel_room_nights` DECIMAL(14,4) DEFAULT NULL,
  `gross_booking_room_nights` DECIMAL(14,4) DEFAULT NULL,
  `quality_status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `readback_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `supersedes_snapshot_id` BIGINT UNSIGNED DEFAULT NULL,
  `idempotency_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_room_books_replay` (`tenant_id`, `hotel_id`, `idempotency_key`),
  KEY `idx_room_books_slots` (`tenant_id`, `hotel_id`, `platform`, `stay_date`, `room_type_id`, `captured_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only manual room-type on-books observations; room_type_id 0 is unsplit summary';

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_room_books_snapshot_no_update`
BEFORE UPDATE ON `hotel_room_type_on_books_snapshots`
FOR EACH ROW
BEGIN
  IF NOT (
    COALESCE(@suxi_cloud_hotel_id_migration, 0) = 1
    AND NEW.`hotel_id` <> OLD.`hotel_id`
    AND (NEW.`id` <=> OLD.`id`)
    AND (NEW.`contract_version` <=> OLD.`contract_version`)
    AND (NEW.`tenant_id` <=> OLD.`tenant_id`)
    AND (NEW.`source_hotel_id` <=> OLD.`source_hotel_id`)
    AND (NEW.`platform` <=> OLD.`platform`)
    AND (NEW.`fact_scope` <=> OLD.`fact_scope`)
    AND (NEW.`stay_date` <=> OLD.`stay_date`)
    AND (NEW.`captured_at` <=> OLD.`captured_at`)
    AND (NEW.`source_method` <=> OLD.`source_method`)
    AND (NEW.`source_ref_hash` <=> OLD.`source_ref_hash`)
    AND (NEW.`room_type_id` <=> OLD.`room_type_id`)
    AND (BINARY NEW.`room_type_name` <=> BINARY OLD.`room_type_name`)
    AND (NEW.`on_books_room_nights` <=> OLD.`on_books_room_nights`)
    AND (NEW.`on_books_room_revenue` <=> OLD.`on_books_room_revenue`)
    AND (NEW.`cumulative_cancel_room_nights` <=> OLD.`cumulative_cancel_room_nights`)
    AND (NEW.`gross_booking_room_nights` <=> OLD.`gross_booking_room_nights`)
    AND (NEW.`quality_status` <=> OLD.`quality_status`)
    AND (NEW.`readback_verified` <=> OLD.`readback_verified`)
    AND (NEW.`supersedes_snapshot_id` <=> OLD.`supersedes_snapshot_id`)
    AND (NEW.`idempotency_key` <=> OLD.`idempotency_key`)
    AND (NEW.`content_digest` <=> OLD.`content_digest`)
    AND (NEW.`created_by` <=> OLD.`created_by`)
    AND (NEW.`created_at` <=> OLD.`created_at`)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'room-type on-books snapshots are append-only';
  END IF;
END$$
DELIMITER ;

CREATE TRIGGER IF NOT EXISTS `trg_room_books_snapshot_no_delete`
BEFORE DELETE ON `hotel_room_type_on_books_snapshots`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'room-type on-books snapshots are append-only';
