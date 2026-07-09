ALTER TABLE `hotels`
  ADD COLUMN IF NOT EXISTS `ota_channel_strategy` varchar(20) NOT NULL DEFAULT 'dual' COMMENT 'OTA channel strategy: ctrip_only/dual/meituan_only' AFTER `description`;

UPDATE `hotels`
SET `ota_channel_strategy` = 'dual'
WHERE `ota_channel_strategy` IS NULL
   OR `ota_channel_strategy` = ''
   OR `ota_channel_strategy` NOT IN ('ctrip_only', 'dual', 'meituan_only');
