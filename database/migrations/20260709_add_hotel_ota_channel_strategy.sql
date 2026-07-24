ALTER TABLE `hotels`
  ADD COLUMN IF NOT EXISTS `ota_channel_strategy` varchar(20) NOT NULL DEFAULT 'none' COMMENT 'OTA channel strategy: none/ctrip_only/dual/meituan_only' AFTER `description`;

ALTER TABLE `hotels`
  MODIFY COLUMN `ota_channel_strategy` varchar(20) NOT NULL DEFAULT 'none' COMMENT 'OTA channel strategy: none/ctrip_only/dual/meituan_only';

UPDATE `hotels`
SET `ota_channel_strategy` = 'none'
WHERE `ota_channel_strategy` IS NULL
   OR `ota_channel_strategy` = ''
   OR `ota_channel_strategy` NOT IN ('none', 'ctrip_only', 'dual', 'meituan_only');
