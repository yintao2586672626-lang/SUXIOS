-- Strategy simulation groups permitted hotels by their explicit city.
-- Existing rows remain compatible without inferring a city from free-text addresses.

ALTER TABLE `hotels`
  ADD COLUMN IF NOT EXISTS `city` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '酒店所在城市' AFTER `address`;
