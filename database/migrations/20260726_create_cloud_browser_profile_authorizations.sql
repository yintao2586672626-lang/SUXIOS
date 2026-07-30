-- Cloud browser authorizations are deliberately separate from local OTA
-- Profiles.  This schema contains only opaque identifiers and state: no
-- browser profile path, Cookie, password, token, or captured payload.
CREATE TABLE IF NOT EXISTS `cloud_browser_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `system_hotel_id` int unsigned NOT NULL,
  `owner_user_id` int unsigned NOT NULL,
  `platform` varchar(20) NOT NULL,
  `profile_public_id` varchar(64) NOT NULL,
  `authorization_status` varchar(32) NOT NULL DEFAULT 'unauthorized',
  `status_reason` varchar(80) NOT NULL DEFAULT '',
  `login_verified_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `session_expires_at` datetime DEFAULT NULL,
  `last_state_change_at` datetime NOT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cloud_browser_profile_public` (`profile_public_id`),
  UNIQUE KEY `uq_cloud_browser_profile_scope` (`tenant_id`, `owner_user_id`, `system_hotel_id`, `platform`),
  KEY `idx_cloud_browser_profile_state` (`tenant_id`, `system_hotel_id`, `platform`, `authorization_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cloud browser authorization state only; session material remains outside database';

CREATE TABLE IF NOT EXISTS `cloud_browser_login_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profile_id` bigint unsigned NOT NULL,
  `session_public_id` varchar(64) NOT NULL,
  `ticket_hash` char(64) NOT NULL,
  `session_status` varchar(24) NOT NULL DEFAULT 'issued',
  `requested_by` int unsigned NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cloud_browser_login_session` (`session_public_id`),
  KEY `idx_cloud_browser_login_profile` (`profile_id`, `session_status`, `expires_at`),
  CONSTRAINT `fk_cloud_browser_login_profile`
    FOREIGN KEY (`profile_id`) REFERENCES `cloud_browser_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One-time cloud browser login entry tickets; only SHA-256 ticket hash is stored';
