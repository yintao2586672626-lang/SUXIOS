-- Enforce one active forecast pilot per tenant and hotel without mutating the applied table-creation migration.
ALTER TABLE `temporal_forecast_trials`
  ADD COLUMN `active_slot` TINYINT GENERATED ALWAYS AS (
    CASE WHEN `status` IN ('draft', 'pending_approval', 'running') THEN 1 ELSE NULL END
  ) STORED AFTER `status`,
  ADD UNIQUE KEY `uniq_temporal_forecast_trial_active` (`tenant_id`, `system_hotel_id`, `active_slot`);
