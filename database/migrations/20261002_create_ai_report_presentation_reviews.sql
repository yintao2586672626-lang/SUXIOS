CREATE TABLE IF NOT EXISTS `ai_report_presentation_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `hotel_id` BIGINT UNSIGNED NOT NULL,
  `report_id` BIGINT UNSIGNED NOT NULL,
  `presentation_spec_id` BIGINT UNSIGNED NOT NULL,
  `spec_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `review_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `review_json` JSON NOT NULL,
  `review_status` VARCHAR(32) NOT NULL,
  `request_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_presentation_review_replay` (`tenant_id`,`hotel_id`,`presentation_spec_id`,`request_key`),
  KEY `idx_presentation_review_current` (`tenant_id`,`hotel_id`,`report_id`,`presentation_spec_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only human report review bound to one immutable spec and source ledger; grants no operating approval';
