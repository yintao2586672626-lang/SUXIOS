-- Provision one independent SaaS tenant for each verified active owner account
-- that was historically issued before owner-tenant bootstrap existed.
--
-- These exact accounts have no tenant, primary hotel, owned/created hotel, or
-- hotel permission. Their enabled level-2 role explicitly allows hotel.create.
-- No hotel or permission is invented here: the first hotel they create will
-- inherit this tenant through the normal Hotel::create path.

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_expected`;
CREATE TEMPORARY TABLE `tmp_suxios_owner_tenant_expected` (
  `user_id` int unsigned NOT NULL,
  `username` varchar(50) NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_tmp_owner_tenant_username` (`username`)
) ENGINE=MEMORY;

INSERT INTO `tmp_suxios_owner_tenant_expected` (`user_id`, `username`) VALUES
  (127, 'VIP003'),
  (162, 'VIP015'),
  (165, 'VIP018'),
  (167, 'VIP020'),
  (170, 'VIP023'),
  (172, 'VIP024'),
  (173, 'VIP025'),
  (223, 'VIP026'),
  (261, 'VIP027');

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_candidates`;
CREATE TEMPORARY TABLE `tmp_suxios_owner_tenant_candidates` (
  `user_id` int unsigned NOT NULL,
  `username` varchar(50) NOT NULL,
  `tenant_name` varchar(120) NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=MEMORY;

INSERT INTO `tmp_suxios_owner_tenant_candidates` (`user_id`, `username`, `tenant_name`)
SELECT
  user_row.`id`,
  user_row.`username`,
  LEFT(
    CASE
      WHEN NULLIF(TRIM(user_row.`realname`), '') IS NOT NULL
        THEN CONCAT(TRIM(user_row.`realname`), '（', user_row.`username`, '）专属酒店空间')
      ELSE CONCAT(user_row.`username`, '专属酒店空间')
    END,
    120
  )
FROM `tmp_suxios_owner_tenant_expected` expected_row
INNER JOIN `users` user_row
  ON user_row.`id` = expected_row.`user_id`
  AND user_row.`username` = expected_row.`username`
INNER JOIN `roles` role_row
  ON role_row.`id` = user_row.`role_id`
WHERE user_row.`status` = 1
  AND (user_row.`tenant_id` IS NULL OR user_row.`tenant_id` = 0)
  AND (user_row.`hotel_id` IS NULL OR user_row.`hotel_id` = 0)
  AND role_row.`status` = 1
  AND role_row.`level` = 2
  AND (
    role_row.`permissions` LIKE '%"hotel.create"%'
    OR role_row.`permissions` LIKE '%"can_manage_own_hotels"%'
    OR role_row.`permissions` LIKE '%"all"%'
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `hotels` hotel_row
    WHERE hotel_row.`owner_user_id` = user_row.`id`
      OR hotel_row.`created_by` = user_row.`id`
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `user_hotel_permissions` permission_row
    WHERE permission_row.`user_id` = user_row.`id`
  );

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_bootstrap_unassigned_owner_tenants`$$

CREATE PROCEDURE `suxios_bootstrap_unassigned_owner_tenants`()
BEGIN
  DECLARE v_done tinyint unsigned DEFAULT 0;
  DECLARE v_user_id int unsigned;
  DECLARE v_username varchar(50);
  DECLARE v_tenant_name varchar(120);
  DECLARE v_eligible_count int unsigned DEFAULT 0;
  DECLARE v_tenant_id int unsigned DEFAULT 0;
  DECLARE owner_cursor CURSOR FOR
    SELECT candidate_row.`user_id`, candidate_row.`username`, candidate_row.`tenant_name`
    FROM `tmp_suxios_owner_tenant_candidates` candidate_row
    ORDER BY candidate_row.`user_id`;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  OPEN owner_cursor;
  owner_loop: LOOP
    FETCH owner_cursor INTO v_user_id, v_username, v_tenant_name;
    IF v_done = 1 THEN
      LEAVE owner_loop;
    END IF;

    SELECT COUNT(*)
    INTO v_eligible_count
    FROM `users` user_row
    INNER JOIN `roles` role_row
      ON role_row.`id` = user_row.`role_id`
    WHERE user_row.`id` = v_user_id
      AND user_row.`username` = v_username
      AND user_row.`status` = 1
      AND (user_row.`tenant_id` IS NULL OR user_row.`tenant_id` = 0)
      AND (user_row.`hotel_id` IS NULL OR user_row.`hotel_id` = 0)
      AND role_row.`status` = 1
      AND role_row.`level` = 2
      AND (
        role_row.`permissions` LIKE '%"hotel.create"%'
        OR role_row.`permissions` LIKE '%"can_manage_own_hotels"%'
        OR role_row.`permissions` LIKE '%"all"%'
      )
      AND NOT EXISTS (
        SELECT 1
        FROM `hotels` hotel_row
        WHERE hotel_row.`owner_user_id` = user_row.`id`
          OR hotel_row.`created_by` = user_row.`id`
      )
      AND NOT EXISTS (
        SELECT 1
        FROM `user_hotel_permissions` permission_row
        WHERE permission_row.`user_id` = user_row.`id`
      );

    IF v_eligible_count = 1 THEN
      INSERT INTO `tenants` (`name`, `status`, `plan_id`, `created_at`, `updated_at`)
      VALUES (v_tenant_name, 1, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
      SET v_tenant_id = LAST_INSERT_ID();

      UPDATE `users`
      SET
        `tenant_id` = v_tenant_id,
        `update_time` = CURRENT_TIMESTAMP
      WHERE `id` = v_user_id
        AND `username` = v_username
        AND (`tenant_id` IS NULL OR `tenant_id` = 0)
        AND (`hotel_id` IS NULL OR `hotel_id` = 0);

      IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Owner tenant bootstrap lost its verified user binding';
      END IF;
    END IF;
  END LOOP;
  CLOSE owner_cursor;
END$$

DELIMITER ;

START TRANSACTION;

CALL `suxios_bootstrap_unassigned_owner_tenants`();

COMMIT;

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_bootstrap_unassigned_owner_tenants`$$

DELIMITER ;

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_candidates`;
DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_owner_tenant_expected`;
