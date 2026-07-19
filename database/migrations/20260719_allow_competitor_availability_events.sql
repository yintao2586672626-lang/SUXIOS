-- Availability events such as sold_out/unavailable may have no public price.
-- NULL means "no quoted price"; zero must never be used as a fabricated rate.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `competitor_price_log`
  MODIFY COLUMN `price` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'OTA公开报价；售罄或不可订事件无报价时为NULL',
  ADD COLUMN IF NOT EXISTS `availability_scope_key` char(64) NOT NULL DEFAULT '' COMMENT '同平台入住离店客群币种的可售事件范围键' AFTER `availability`,
  ADD INDEX IF NOT EXISTS `idx_competitor_availability_scope` (`store_id`, `platform`, `availability_scope_key`, `collected_at`);
