-- Create legacy Ctrip configuration table without seeding sensitive OTA credentials.
-- Runtime code still reads system_configs for Ctrip config lists; production values
-- must be added through authorized configuration flows, not committed SQL dumps.

CREATE TABLE IF NOT EXISTS `system_configs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `config_key` VARCHAR(50) NOT NULL,
  `config_value` TEXT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `create_time` DATETIME DEFAULT NULL,
  `update_time` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
