-- Bind every competitor collector credential to one user, platform and hotel.
-- Competitor targets inherit tenant scope only from the authoritative hotels
-- table. Orphaned targets remain unassigned and are disabled for manual repair.

ALTER TABLE `competitor_hotel`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_competitor_hotel_tenant_store` (`tenant_id`, `store_id`, `status`);

UPDATE `competitor_hotel` AS `ch`
JOIN `hotels` AS `h` ON `h`.`id` = `ch`.`store_id`
SET `ch`.`tenant_id` = `h`.`tenant_id`
WHERE `h`.`tenant_id` IS NOT NULL
  AND `h`.`tenant_id` > 0
  AND (`ch`.`tenant_id` IS NULL OR `ch`.`tenant_id` <> `h`.`tenant_id`);

UPDATE `competitor_hotel` AS `ch`
LEFT JOIN `hotels` AS `h` ON `h`.`id` = `ch`.`store_id`
SET `ch`.`tenant_id` = NULL,
    `ch`.`status` = 0
WHERE `h`.`id` IS NULL
   OR `h`.`tenant_id` IS NULL
   OR `h`.`tenant_id` = 0;

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
