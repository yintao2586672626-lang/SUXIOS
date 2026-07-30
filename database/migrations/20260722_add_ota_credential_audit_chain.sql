CREATE TABLE IF NOT EXISTS `ota_credential_audit_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `credential_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `system_hotel_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `platform` VARCHAR(20) NOT NULL,
  `config_id_hash` CHAR(64) NOT NULL,
  `event_sequence` BIGINT UNSIGNED NOT NULL,
  `credential_version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `event_type` VARCHAR(40) NOT NULL,
  `outcome` VARCHAR(20) NOT NULL,
  `failure_code` VARCHAR(80) NOT NULL DEFAULT '',
  `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `payload_digest` CHAR(64) NOT NULL DEFAULT '',
  `previous_entry_hash` CHAR(64) NOT NULL DEFAULT '',
  `entry_hash` CHAR(64) NOT NULL,
  `occurred_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_ota_credential_audit_scope_sequence`
    (`tenant_id`,`system_hotel_id`,`platform`,`config_id_hash`,`event_sequence`),
  UNIQUE KEY `uq_ota_credential_audit_entry_hash` (`entry_hash`),
  KEY `idx_ota_credential_audit_credential_version`
    (`credential_id`,`credential_version`,`event_type`),
  KEY `idx_ota_credential_audit_scope_time`
    (`tenant_id`,`system_hotel_id`,`platform`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER IF NOT EXISTS `trg_ota_credential_audit_no_update`
BEFORE UPDATE ON `ota_credential_audit_logs`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OTA credential audit log is append-only';

CREATE TRIGGER IF NOT EXISTS `trg_ota_credential_audit_no_delete`
BEFORE DELETE ON `ota_credential_audit_logs`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OTA credential audit log is append-only';
