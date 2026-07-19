-- Bind every competitor collector credential to one user, platform and hotel.
-- Existing rows cannot be assigned safely, so they are disabled until an
-- administrator recreates or updates the binding and rotates its token.

ALTER TABLE `competitor_hotel`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_competitor_hotel_tenant_store` (`tenant_id`, `store_id`, `status`);

UPDATE `competitor_hotel`
SET `tenant_id` = `store_id`
WHERE `tenant_id` IS NULL AND `store_id` > 0;

ALTER TABLE `competitor_device`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `user_id` INT UNSIGNED DEFAULT NULL AFTER `tenant_id`,
  ADD COLUMN IF NOT EXISTS `store_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `platform` VARCHAR(20) NOT NULL DEFAULT '' AFTER `store_id`,
  ADD COLUMN IF NOT EXISTS `token_hash` VARCHAR(255) NOT NULL DEFAULT '' AFTER `platform`,
  ADD COLUMN IF NOT EXISTS `token_hint` VARCHAR(24) NOT NULL DEFAULT '' AFTER `token_hash`,
  ADD COLUMN IF NOT EXISTS `token_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `token_hint`,
  ADD COLUMN IF NOT EXISTS `revoked_at` DATETIME DEFAULT NULL AFTER `last_time`,
  ADD UNIQUE INDEX IF NOT EXISTS `uniq_competitor_device_scope` (`device_id`, `platform`, `store_id`),
  ADD INDEX IF NOT EXISTS `idx_competitor_device_tenant_store` (`tenant_id`, `store_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_competitor_device_user` (`user_id`, `status`);

UPDATE `competitor_device`
SET `status` = 0,
    `revoked_at` = COALESCE(`revoked_at`, NOW())
WHERE `tenant_id` IS NULL
   OR `user_id` IS NULL
   OR `store_id` IS NULL
   OR `platform` = ''
   OR `token_hash` = '';
