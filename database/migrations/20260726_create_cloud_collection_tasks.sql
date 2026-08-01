-- Cloud collection tasks are dispatch records only. They contain no browser
-- session material and are consumable only by a trusted cloud browser gateway.
CREATE TABLE IF NOT EXISTS `cloud_collection_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_public_id` varchar(64) NOT NULL,
  `profile_id` bigint unsigned NOT NULL,
  `profile_public_id` varchar(64) NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `system_hotel_id` int unsigned NOT NULL,
  `owner_user_id` int unsigned NOT NULL,
  `platform` varchar(20) NOT NULL,
  `collection_mode` varchar(32) NOT NULL,
  `target_date` date NOT NULL,
  `window_key` varchar(32) NOT NULL,
  `field_priority_json` json NOT NULL,
  `task_status` varchar(32) NOT NULL DEFAULT 'queued',
  `truth_gate_status` varchar(48) NOT NULL DEFAULT 'waiting_for_identity_date_fields_save_readback',
  `gap_codes_json` json DEFAULT NULL,
  `receipt_evidence_json` json DEFAULT NULL,
  `receipt_fingerprint` char(64) DEFAULT NULL,
  `formal_message_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `idempotency_key` char(64) NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `create_time` datetime NOT NULL,
  `update_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cloud_collection_task_public` (`task_public_id`),
  UNIQUE KEY `uq_cloud_collection_task_idempotency` (`idempotency_key`),
  KEY `idx_cloud_collection_task_due` (`tenant_id`, `system_hotel_id`, `platform`, `collection_mode`, `target_date`, `task_status`),
  CONSTRAINT `fk_cloud_collection_profile`
    FOREIGN KEY (`profile_id`) REFERENCES `cloud_browser_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cloud browser collection dispatch and truth-gate state; no session material';
