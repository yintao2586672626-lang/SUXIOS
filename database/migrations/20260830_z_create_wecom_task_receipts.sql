-- Privacy-minimized, append-only receipts parsed from verified WeCom inbound events.
-- A reported status or amount remains an unverified employee claim. This table
-- never approves an intent, mutates an execution task, sends a message, or writes
-- OTA/PMS data.
CREATE TABLE IF NOT EXISTS `wecom_task_receipts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_version` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `source_hotel_id` BIGINT UNSIGNED NOT NULL,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `source_event_id` BIGINT UNSIGNED NOT NULL,
  `source_binding_id` BIGINT UNSIGNED NOT NULL,
  `source_event_ref` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_binding_ref` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_ref` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sender_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reported_status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reported_amount` DECIMAL(20,2) DEFAULT NULL,
  `reported_amount_status` VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `result_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_note_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `structured_payload_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_event_payload_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_event_content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `binding_scope_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sender_scope_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_scope_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_status_at_receipt` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wecom_task_receipt_event_task` (`tenant_id`, `hotel_id`, `source_event_id`, `task_id`),
  KEY `idx_wecom_task_receipt_task` (`tenant_id`, `hotel_id`, `task_id`, `id`),
  KEY `idx_wecom_task_receipt_status` (`tenant_id`, `hotel_id`, `reported_status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only privacy-minimized WeCom employee task receipts';

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_wecom_task_receipt_no_update`
BEFORE UPDATE ON `wecom_task_receipts`
FOR EACH ROW
BEGIN
  IF NOT (
    COALESCE(@suxi_cloud_hotel_id_migration, 0) = 1
    AND NEW.`hotel_id` <> OLD.`hotel_id`
    AND NEW.`source_hotel_id` = OLD.`source_hotel_id`
    AND NEW.`content_digest` = OLD.`content_digest`
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wecom task receipt is append-only';
  END IF;
END$$
DELIMITER ;

CREATE TRIGGER IF NOT EXISTS `trg_wecom_task_receipt_no_delete`
BEFORE DELETE ON `wecom_task_receipts`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wecom task receipt is append-only';

-- The original structured text remains only in the existing verified inbound
-- archive. This table retains hashes and internal references, not message text,
-- employee names, account identifiers, attachments, or credentials.

-- Manual rollback only:
-- DROP TABLE IF EXISTS `wecom_task_receipts`;
