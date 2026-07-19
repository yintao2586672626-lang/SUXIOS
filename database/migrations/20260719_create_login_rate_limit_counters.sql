-- Shared fixed-window login rate limiting for multi-instance deployments.
-- The table stores only hashed subjects and expires each bucket shortly after
-- its five-minute window. Application code must fail closed if this table is
-- unavailable; it must not fall back to a per-host cache.

CREATE TABLE IF NOT EXISTS `login_rate_limit_counters` (
  `scope_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `bucket_start` BIGINT UNSIGNED NOT NULL,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` BIGINT UNSIGNED NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`scope_type`, `subject_hash`, `bucket_start`),
  KEY `idx_login_rate_limit_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
