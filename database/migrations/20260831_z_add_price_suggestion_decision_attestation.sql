-- Freeze the provenance of every newly generated pricing recommendation.
-- Existing rows intentionally remain NULL and are replayed as
-- legacy_reconstructed; this migration must never fabricate attestation.
ALTER TABLE `price_suggestions`
  ADD COLUMN IF NOT EXISTS `platform` VARCHAR(40) DEFAULT NULL
    COMMENT 'OTA channel for the frozen advisory decision; NULL means legacy unattested row'
    AFTER `demand_forecast_id`,
  ADD COLUMN IF NOT EXISTS `decision_as_of_time` DATETIME DEFAULT NULL
    COMMENT 'server time after all decision inputs were read and before insert'
    AFTER `platform`,
  ADD COLUMN IF NOT EXISTS `model_version` VARCHAR(64) DEFAULT NULL
    COMMENT 'pricing model version used for this saved recommendation'
    AFTER `decision_as_of_time`,
  ADD COLUMN IF NOT EXISTS `decision_input_digest` CHAR(64) DEFAULT NULL
    COMMENT 'sha256 of canonical frozen pricing inputs and source identities'
    AFTER `model_version`,
  ADD COLUMN IF NOT EXISTS `decision_source_refs` JSON DEFAULT NULL
    COMMENT 'sorted source identities used by the saved recommendation; no credentials'
    AFTER `decision_input_digest`;
