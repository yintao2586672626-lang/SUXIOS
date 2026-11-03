-- Controlled additive migration. Do not run against a real hotel database during isolated acceptance.
CREATE TABLE IF NOT EXISTS `guest_operation_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED DEFAULT NULL,
  `kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `record_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` INT UNSIGNED NOT NULL,
  `business_date` DATE NOT NULL,
  `platform` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_json` LONGTEXT NOT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_guest_record_revision` (`tenant_id`,`hotel_id`,`kind`,`record_key`,`revision`),
  KEY `idx_guest_scope_date` (`tenant_id`,`hotel_id`,`kind`,`platform`,`business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guest_operation_heads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED DEFAULT NULL,
  `kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `record_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `record_id` BIGINT UNSIGNED NOT NULL,
  `revision` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_guest_head_scope` (`tenant_id`,`hotel_id`,`kind`,`record_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guest_operation_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED DEFAULT NULL,
  `request_key` VARCHAR(140) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `record_ids_json` LONGTEXT NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_guest_request` (`tenant_id`,`hotel_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_guest_record_no_update` BEFORE UPDATE ON `guest_operation_records`
FOR EACH ROW
BEGIN
  IF NOT (
    COALESCE(@suxi_cloud_hotel_id_migration, 0) = 1
    AND NEW.`hotel_id` <> OLD.`hotel_id`
    AND (NEW.`source_hotel_id` <=> OLD.`source_hotel_id`)
    AND NEW.`tenant_id` = OLD.`tenant_id`
    AND NEW.`id` = OLD.`id`
    AND NEW.`kind` = OLD.`kind`
    AND NEW.`record_key` = OLD.`record_key`
    AND NEW.`revision` = OLD.`revision`
    AND NEW.`business_date` = OLD.`business_date`
    AND NEW.`platform` = OLD.`platform`
    AND NEW.`content_json` = OLD.`content_json`
    AND NEW.`content_digest` = OLD.`content_digest`
    AND NEW.`created_by` = OLD.`created_by`
    AND NEW.`created_at` = OLD.`created_at`
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'guest records are append-only';
  END IF;
END$$
DELIMITER ;
CREATE TRIGGER IF NOT EXISTS `trg_guest_record_no_delete` BEFORE DELETE ON `guest_operation_records`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'guest records are append-only';
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_guest_request_no_update` BEFORE UPDATE ON `guest_operation_requests`
FOR EACH ROW
BEGIN
  IF NOT (
    COALESCE(@suxi_cloud_hotel_id_migration, 0) = 1
    AND NEW.`hotel_id` <> OLD.`hotel_id`
    AND (NEW.`source_hotel_id` <=> OLD.`source_hotel_id`)
    AND NEW.`tenant_id` = OLD.`tenant_id`
    AND NEW.`id` = OLD.`id`
    AND NEW.`request_key` = OLD.`request_key`
    AND NEW.`input_digest` = OLD.`input_digest`
    AND NEW.`record_ids_json` = OLD.`record_ids_json`
    AND NEW.`created_by` = OLD.`created_by`
    AND NEW.`created_at` = OLD.`created_at`
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'guest requests are append-only';
  END IF;
END$$
DELIMITER ;
CREATE TRIGGER IF NOT EXISTS `trg_guest_request_no_delete` BEFORE DELETE ON `guest_operation_requests`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'guest requests are append-only';
