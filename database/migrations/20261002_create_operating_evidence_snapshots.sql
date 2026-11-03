-- Local operating evidence; immutable versions, manual attestations remain manual.
CREATE TABLE IF NOT EXISTS `hotel_operating_evidence_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `hotel_id` INT UNSIGNED NOT NULL,
  `source_hotel_id` INT UNSIGNED NOT NULL COMMENT 'Immutable hotel identity bound into payload digest',
  `kind` VARCHAR(32) NOT NULL,
  `period_month` CHAR(7) NOT NULL,
  `platform` VARCHAR(32) NOT NULL,
  `payload_json` LONGTEXT NOT NULL,
  `content_digest` CHAR(64) NOT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_operating_evidence_request` (`tenant_id`,`hotel_id`,`kind`,`period_month`,`platform`,`idempotency_key`),
  KEY `idx_operating_evidence_scope` (`tenant_id`,`hotel_id`,`kind`,`period_month`,`platform`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
