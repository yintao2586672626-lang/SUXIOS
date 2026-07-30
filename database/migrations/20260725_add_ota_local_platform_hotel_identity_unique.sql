-- 同一租户、同一 OTA 平台的门店标识只能映射一个宿析门店。
-- 若历史数据已存在重复映射，本迁移会明确失败，不会自动删除或合并用户数据。
ALTER TABLE `ota_local_collector_account_hotels`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_ota_local_platform_hotel_identity`
  (`tenant_id`,`platform`,`platform_hotel_id`);

-- 回滚（仅在明确需要时人工执行）：
-- ALTER TABLE `ota_local_collector_account_hotels`
--   DROP INDEX IF EXISTS `uq_ota_local_platform_hotel_identity`;
