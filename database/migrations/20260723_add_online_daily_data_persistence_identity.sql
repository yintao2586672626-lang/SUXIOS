-- Additive-only identity key for idempotent OTA summary/event persistence.
-- Existing rows remain NULL: no historical row, user, hotel, or binding is modified.
ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `persistence_identity_hash` char(64) DEFAULT NULL COMMENT 'Stable SHA-256 persistence identity';

ALTER TABLE `online_daily_data`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_online_daily_persistence_identity` (`persistence_identity_hash`);
