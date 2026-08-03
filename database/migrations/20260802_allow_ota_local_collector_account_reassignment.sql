-- Keep inactive account-hotel mappings as historical ownership records while
-- allowing exactly one active account to own a hotel/platform identity.
-- Existing facts remain linked to their original local_collector data source.

ALTER TABLE `ota_local_collector_account_hotels`
  DROP INDEX IF EXISTS `uq_ota_local_hotel_platform`,
  DROP INDEX IF EXISTS `uq_ota_local_platform_hotel_identity`,
  ADD COLUMN IF NOT EXISTS `active_system_hotel_id` BIGINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN `status` = 'active' THEN `system_hotel_id` ELSE NULL END
    ) STORED,
  ADD COLUMN IF NOT EXISTS `active_platform_hotel_id` VARCHAR(120)
    GENERATED ALWAYS AS (
      CASE WHEN `status` = 'active' THEN `platform_hotel_id` ELSE NULL END
    ) STORED,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_ota_local_active_hotel_platform`
    (`tenant_id`,`active_system_hotel_id`,`platform`),
  ADD UNIQUE INDEX IF NOT EXISTS `uq_ota_local_active_platform_hotel_identity`
    (`tenant_id`,`platform`,`active_platform_hotel_id`);

-- 回滚前必须先确认没有同酒店/平台或同平台门店标识的历史解绑记录；
-- 否则恢复旧唯一索引会失败。仅在明确需要时人工执行：
-- ALTER TABLE `ota_local_collector_account_hotels`
--   DROP INDEX IF EXISTS `uq_ota_local_active_hotel_platform`,
--   DROP INDEX IF EXISTS `uq_ota_local_active_platform_hotel_identity`,
--   DROP COLUMN IF EXISTS `active_system_hotel_id`,
--   DROP COLUMN IF EXISTS `active_platform_hotel_id`,
--   ADD UNIQUE INDEX `uq_ota_local_hotel_platform` (`tenant_id`,`system_hotel_id`,`platform`),
--   ADD UNIQUE INDEX `uq_ota_local_platform_hotel_identity` (`tenant_id`,`platform`,`platform_hotel_id`);
