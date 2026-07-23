-- Postcondition guard for the immutable owner-tenant bootstrap migration.
--
-- The preceding migration is already registered in deployed databases and
-- therefore must not be edited. This forward migration fails closed unless
-- every explicitly approved historical owner account still resolves to the
-- exact user binding and owns one independent, existing tenant identity.

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_validation_targets`;
CREATE TEMPORARY TABLE `tmp_suxios_owner_tenant_validation_targets` (
  `user_id` int unsigned NOT NULL,
  `username` varchar(50) NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_tmp_owner_tenant_validation_username` (`username`)
) ENGINE=MEMORY;

INSERT INTO `tmp_suxios_owner_tenant_validation_targets` (`user_id`, `username`) VALUES
  (127, 'VIP003'),
  (162, 'VIP015'),
  (165, 'VIP018'),
  (167, 'VIP020'),
  (170, 'VIP023'),
  (172, 'VIP024'),
  (173, 'VIP025'),
  (223, 'VIP026'),
  (261, 'VIP027');

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_validate_owner_tenant_bootstrap_targets`$$

CREATE PROCEDURE `suxios_validate_owner_tenant_bootstrap_targets`()
BEGIN
  DECLARE v_target_count int unsigned DEFAULT 0;
  DECLARE v_exact_user_count int unsigned DEFAULT 0;
  DECLARE v_bound_tenant_count int unsigned DEFAULT 0;
  DECLARE v_distinct_tenant_count int unsigned DEFAULT 0;

  SELECT COUNT(*)
  INTO v_target_count
  FROM `tmp_suxios_owner_tenant_validation_targets`;

  SELECT COUNT(*)
  INTO v_exact_user_count
  FROM `tmp_suxios_owner_tenant_validation_targets` target_row
  INNER JOIN `users` user_row
    ON user_row.`id` = target_row.`user_id`
    AND user_row.`username` = target_row.`username`;

  IF v_target_count <> 9 OR v_exact_user_count <> v_target_count THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Owner tenant bootstrap target identity drift detected';
  END IF;

  SELECT COUNT(*), COUNT(DISTINCT user_row.`tenant_id`)
  INTO v_bound_tenant_count, v_distinct_tenant_count
  FROM `tmp_suxios_owner_tenant_validation_targets` target_row
  INNER JOIN `users` user_row
    ON user_row.`id` = target_row.`user_id`
    AND user_row.`username` = target_row.`username`
  INNER JOIN `tenants` tenant_row
    ON tenant_row.`id` = user_row.`tenant_id`
  WHERE user_row.`tenant_id` IS NOT NULL
    AND user_row.`tenant_id` > 0;

  IF v_bound_tenant_count <> v_target_count
    OR v_distinct_tenant_count <> v_target_count THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Owner tenant bootstrap postcondition is incomplete';
  END IF;
END$$

DELIMITER ;

CALL `suxios_validate_owner_tenant_bootstrap_targets`();

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_validate_owner_tenant_bootstrap_targets`$$

DELIMITER ;

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_validation_targets`;
