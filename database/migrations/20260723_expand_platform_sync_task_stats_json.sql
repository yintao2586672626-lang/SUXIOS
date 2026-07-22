-- Cloud OTA export receipts may contain up to 5,000 exact source row IDs.
-- MEDIUMTEXT prevents a valid receipt from being truncated by MySQL TEXT's
-- 64 KiB limit while preserving all existing task history.
ALTER TABLE `platform_data_sync_tasks`
  MODIFY COLUMN `stats_json` MEDIUMTEXT NULL;
