-- Durable idempotency identity for collector report retries.
-- NULL preserves all historical rows while exact new report retries converge
-- on the row committed before a process or release interruption.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `competitor_price_log`
  ADD COLUMN IF NOT EXISTS `report_fingerprint` char(64) DEFAULT NULL
    COMMENT '设备、任务范围、绑定版本和上报载荷的幂等指纹' AFTER `content_hash`,
  ADD UNIQUE INDEX IF NOT EXISTS `uniq_competitor_report_fingerprint`
    (`report_fingerprint`);
