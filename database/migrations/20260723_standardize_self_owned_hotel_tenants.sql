-- Consolidate the three verified legacy owner portfolios that were split into
-- one tenant per hotel by the 20260722 tenant foundation migration.
--
-- This is deliberately fail-closed. A portfolio is eligible only when every
-- owned hotel has an active self-created owner grant, no hotel tenant contains
-- another owner's hotel, and no other active user or grant shares the scope.
-- Source tenant rows are retained but disabled after their data is moved.

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_requests`;
CREATE TEMPORARY TABLE `tmp_suxios_owner_tenant_requests` (
  `username` varchar(50) NOT NULL,
  `primary_hotel_id` int unsigned NOT NULL,
  PRIMARY KEY (`username`)
) ENGINE=MEMORY;

INSERT INTO `tmp_suxios_owner_tenant_requests` (`username`, `primary_hotel_id`) VALUES
  ('VIP016', 137),
  ('VIP019', 132),
  ('VIP021', 131);

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_targets`;
CREATE TEMPORARY TABLE `tmp_suxios_owner_tenant_targets` (
  `owner_user_id` int unsigned NOT NULL,
  `username` varchar(50) NOT NULL,
  `primary_hotel_id` int unsigned NOT NULL,
  `target_tenant_id` int unsigned NOT NULL,
  `owned_hotel_count` int unsigned NOT NULL,
  PRIMARY KEY (`owner_user_id`),
  UNIQUE KEY `uk_tmp_owner_tenant_username` (`username`)
) ENGINE=MEMORY;

INSERT INTO `tmp_suxios_owner_tenant_targets` (
  `owner_user_id`,
  `username`,
  `primary_hotel_id`,
  `target_tenant_id`,
  `owned_hotel_count`
)
SELECT
  user_row.`id`,
  user_row.`username`,
  request_row.`primary_hotel_id`,
  primary_hotel.`tenant_id`,
  COUNT(DISTINCT owned_hotel.`id`)
FROM `tmp_suxios_owner_tenant_requests` request_row
INNER JOIN `users` user_row
  ON user_row.`username` = request_row.`username`
INNER JOIN `hotels` primary_hotel
  ON primary_hotel.`id` = request_row.`primary_hotel_id`
INNER JOIN `tenants` target_tenant
  ON target_tenant.`id` = primary_hotel.`tenant_id`
INNER JOIN `hotels` owned_hotel
  ON owned_hotel.`owner_user_id` = user_row.`id`
  AND owned_hotel.`created_by` = user_row.`id`
WHERE user_row.`status` = 1
  AND (user_row.`tenant_id` IS NULL OR user_row.`tenant_id` = 0)
  AND primary_hotel.`owner_user_id` = user_row.`id`
  AND primary_hotel.`created_by` = user_row.`id`
  AND primary_hotel.`status` = 1
  AND primary_hotel.`tenant_id` > 0
  AND target_tenant.`status` = 1
  AND EXISTS (
    SELECT 1
    FROM `user_hotel_permissions` primary_permission
    WHERE primary_permission.`user_id` = user_row.`id`
      AND primary_permission.`hotel_id` = primary_hotel.`id`
      AND primary_permission.`scope_type` = 'owner'
      AND primary_permission.`created_by` = user_row.`id`
      AND primary_permission.`status` IN ('active', '1')
      AND (
        primary_permission.`expires_at` IS NULL
        OR primary_permission.`expires_at` > NOW()
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `hotels` hotel_without_owner_grant
    LEFT JOIN `user_hotel_permissions` owner_permission
      ON owner_permission.`user_id` = user_row.`id`
      AND owner_permission.`hotel_id` = hotel_without_owner_grant.`id`
      AND owner_permission.`scope_type` = 'owner'
      AND owner_permission.`created_by` = user_row.`id`
      AND owner_permission.`status` IN ('active', '1')
      AND (
        owner_permission.`expires_at` IS NULL
        OR owner_permission.`expires_at` > NOW()
      )
    WHERE hotel_without_owner_grant.`owner_user_id` = user_row.`id`
      AND hotel_without_owner_grant.`created_by` = user_row.`id`
      AND owner_permission.`id` IS NULL
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `user_hotel_permissions` unexpected_permission
    LEFT JOIN `hotels` unexpected_hotel
      ON unexpected_hotel.`id` = unexpected_permission.`hotel_id`
    WHERE unexpected_permission.`user_id` = user_row.`id`
      AND unexpected_permission.`status` IN ('active', '1')
      AND (
        unexpected_permission.`expires_at` IS NULL
        OR unexpected_permission.`expires_at` > NOW()
      )
      AND (
        unexpected_hotel.`id` IS NULL
        OR unexpected_hotel.`owner_user_id` <> user_row.`id`
        OR unexpected_hotel.`created_by` <> user_row.`id`
        OR unexpected_permission.`scope_type` <> 'owner'
        OR unexpected_permission.`created_by` <> user_row.`id`
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `hotels` owner_hotel
    INNER JOIN `hotels` foreign_hotel
      ON foreign_hotel.`tenant_id` = owner_hotel.`tenant_id`
    WHERE owner_hotel.`owner_user_id` = user_row.`id`
      AND owner_hotel.`created_by` = user_row.`id`
      AND (
        foreign_hotel.`owner_user_id` <> user_row.`id`
        OR foreign_hotel.`created_by` <> user_row.`id`
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `hotels` owner_hotel
    INNER JOIN `user_hotel_permissions` foreign_permission
      ON foreign_permission.`hotel_id` = owner_hotel.`id`
    WHERE owner_hotel.`owner_user_id` = user_row.`id`
      AND owner_hotel.`created_by` = user_row.`id`
      AND foreign_permission.`user_id` <> user_row.`id`
      AND foreign_permission.`status` IN ('active', '1')
      AND (
        foreign_permission.`expires_at` IS NULL
        OR foreign_permission.`expires_at` > NOW()
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `hotels` owner_hotel
    INNER JOIN `users` foreign_user
      ON foreign_user.`tenant_id` = owner_hotel.`tenant_id`
    WHERE owner_hotel.`owner_user_id` = user_row.`id`
      AND owner_hotel.`created_by` = user_row.`id`
      AND foreign_user.`id` <> user_row.`id`
      AND foreign_user.`status` = 1
  )
GROUP BY
  user_row.`id`,
  user_row.`username`,
  request_row.`primary_hotel_id`,
  primary_hotel.`tenant_id`
HAVING COUNT(DISTINCT owned_hotel.`id`) >= 2
  AND COUNT(DISTINCT owned_hotel.`tenant_id`) >= 2;

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_sources`;
CREATE TEMPORARY TABLE `tmp_suxios_owner_tenant_sources` (
  `source_tenant_id` int unsigned NOT NULL,
  `target_tenant_id` int unsigned NOT NULL,
  `owner_user_id` int unsigned NOT NULL,
  PRIMARY KEY (`source_tenant_id`)
) ENGINE=MEMORY;

INSERT INTO `tmp_suxios_owner_tenant_sources` (
  `source_tenant_id`,
  `target_tenant_id`,
  `owner_user_id`
)
SELECT DISTINCT
  owned_hotel.`tenant_id`,
  target_row.`target_tenant_id`,
  target_row.`owner_user_id`
FROM `tmp_suxios_owner_tenant_targets` target_row
INNER JOIN `hotels` owned_hotel
  ON owned_hotel.`owner_user_id` = target_row.`owner_user_id`
  AND owned_hotel.`created_by` = target_row.`owner_user_id`
WHERE owned_hotel.`tenant_id` > 0
  AND owned_hotel.`tenant_id` <> target_row.`target_tenant_id`;

UPDATE `agent_configs` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `agent_conversations` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `agent_logs` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `agent_tasks` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `agent_work_orders` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ai_daily_reports` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ai_model_call_logs` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ai_report_generation_tasks` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ai_report_human_reviews` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ai_report_input_cache` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `analysis_reference_set_versions` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `competitor_analysis` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `competitor_device` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `competitor_hotel` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `competitor_price_log` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `complaint_feedbacks` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `complaint_rooms` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `daily_reports` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `demand_forecasts` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `devices` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `energy_benchmarks` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `energy_consumption` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `energy_saving_suggestions` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `expansion_records` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `feasibility_reports` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `field_mappings` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `hotels` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `hotel_field_templates` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `knowledge_base` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `knowledge_categories` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `login_logs` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `maintenance_plans` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `monthly_tasks` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `online_daily_data` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `online_data_correction_ledger` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `opening_projects` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `operation_action_tracks` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `operation_alerts` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `operation_execution_evidence` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `operation_execution_intents` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `operation_execution_tasks` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `operation_logs` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ota_credentials` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ota_credential_audit_logs` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ota_ctrip_capture_gaps` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ota_ctrip_capture_runs` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ota_ctrip_entity_snapshots` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ota_ctrip_metric_facts` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `ota_profile_bindings` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `platform_data_raw_records` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `platform_data_sources` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `platform_data_sync_logs` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `platform_data_sync_tasks` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `price_suggestions` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `quant_simulation_records` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `room_types` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `strategy_simulation_records` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `temporal_forecast_snapshots` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `transfer_records` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `users` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `user_hotel_permissions` tenant_row
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = tenant_row.`tenant_id`
SET tenant_row.`tenant_id` = source_row.`target_tenant_id`;

UPDATE `hotels` hotel_row
INNER JOIN `tmp_suxios_owner_tenant_targets` target_row
  ON target_row.`owner_user_id` = hotel_row.`owner_user_id`
  AND target_row.`owner_user_id` = hotel_row.`created_by`
SET hotel_row.`tenant_id` = target_row.`target_tenant_id`;

UPDATE `user_hotel_permissions` permission_row
INNER JOIN `tmp_suxios_owner_tenant_targets` target_row
  ON target_row.`owner_user_id` = permission_row.`user_id`
INNER JOIN `hotels` hotel_row
  ON hotel_row.`id` = permission_row.`hotel_id`
  AND hotel_row.`owner_user_id` = target_row.`owner_user_id`
  AND hotel_row.`created_by` = target_row.`owner_user_id`
SET
  permission_row.`tenant_id` = target_row.`target_tenant_id`,
  permission_row.`scope_type` = 'owner',
  permission_row.`is_primary` = CASE
    WHEN permission_row.`hotel_id` = target_row.`primary_hotel_id` THEN 1
    ELSE 0
  END;

UPDATE `users` user_row
INNER JOIN `tmp_suxios_owner_tenant_targets` target_row
  ON target_row.`owner_user_id` = user_row.`id`
SET
  user_row.`tenant_id` = target_row.`target_tenant_id`,
  user_row.`hotel_id` = target_row.`primary_hotel_id`;

UPDATE `tenants` target_tenant
INNER JOIN `tmp_suxios_owner_tenant_targets` target_row
  ON target_row.`target_tenant_id` = target_tenant.`id`
SET
  target_tenant.`status` = 1,
  target_tenant.`updated_at` = CURRENT_TIMESTAMP;

UPDATE `tenants` source_tenant
INNER JOIN `tmp_suxios_owner_tenant_sources` source_row
  ON source_row.`source_tenant_id` = source_tenant.`id`
SET
  source_tenant.`status` = 0,
  source_tenant.`updated_at` = CURRENT_TIMESTAMP;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_sources`;
DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_targets`;
DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_requests`;
