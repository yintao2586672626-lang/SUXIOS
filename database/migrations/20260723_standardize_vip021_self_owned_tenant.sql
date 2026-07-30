-- VIP021 owns three verified hotels, but hotel 131 is disabled. The preceding
-- portfolio repair intentionally skipped it because 131 had been selected as
-- the primary hotel. Use active hotel 133 as the canonical primary and tenant,
-- while retaining disabled hotel 131 under the same owner portfolio.
--
-- The exact hotel set and every ownership edge are re-checked before any row
-- is changed. If the live scope has drifted, the migration is a no-op.

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_vip021_tenant_target`;
CREATE TEMPORARY TABLE `tmp_suxios_vip021_tenant_target` (
  `owner_user_id` int unsigned NOT NULL,
  `primary_hotel_id` int unsigned NOT NULL,
  `target_tenant_id` int unsigned NOT NULL,
  PRIMARY KEY (`owner_user_id`)
) ENGINE=MEMORY;

INSERT INTO `tmp_suxios_vip021_tenant_target` (
  `owner_user_id`,
  `primary_hotel_id`,
  `target_tenant_id`
)
SELECT
  user_row.`id`,
  primary_hotel.`id`,
  primary_hotel.`tenant_id`
FROM `users` user_row
INNER JOIN `hotels` primary_hotel
  ON primary_hotel.`id` = 133
INNER JOIN `tenants` target_tenant
  ON target_tenant.`id` = primary_hotel.`tenant_id`
WHERE user_row.`id` = 168
  AND user_row.`username` = 'VIP021'
  AND user_row.`status` = 1
  AND (user_row.`tenant_id` IS NULL OR user_row.`tenant_id` = 0)
  AND (user_row.`hotel_id` IS NULL OR user_row.`hotel_id` = 0)
  AND primary_hotel.`tenant_id` = 133
  AND primary_hotel.`owner_user_id` = user_row.`id`
  AND primary_hotel.`created_by` = user_row.`id`
  AND primary_hotel.`status` = 1
  AND target_tenant.`status` = 1
  AND (
    SELECT COUNT(*)
    FROM `hotels` owned_hotel
    WHERE owned_hotel.`owner_user_id` = user_row.`id`
      AND owned_hotel.`created_by` = user_row.`id`
      AND owned_hotel.`id` IN (131, 133, 182)
  ) = 3
  AND NOT EXISTS (
    SELECT 1
    FROM `hotels` unexpected_owned_hotel
    WHERE unexpected_owned_hotel.`owner_user_id` = user_row.`id`
      AND unexpected_owned_hotel.`created_by` = user_row.`id`
      AND unexpected_owned_hotel.`id` NOT IN (131, 133, 182)
  )
  AND EXISTS (
    SELECT 1
    FROM `hotels` source_hotel
    WHERE source_hotel.`id` = 131
      AND source_hotel.`tenant_id` = 131
      AND source_hotel.`owner_user_id` = user_row.`id`
      AND source_hotel.`created_by` = user_row.`id`
  )
  AND EXISTS (
    SELECT 1
    FROM `hotels` source_hotel
    WHERE source_hotel.`id` = 182
      AND source_hotel.`tenant_id` = 182
      AND source_hotel.`owner_user_id` = user_row.`id`
      AND source_hotel.`created_by` = user_row.`id`
  )
  AND (
    SELECT COUNT(*)
    FROM `user_hotel_permissions` owner_permission
    WHERE owner_permission.`user_id` = user_row.`id`
      AND owner_permission.`hotel_id` IN (131, 133, 182)
      AND owner_permission.`scope_type` = 'owner'
      AND owner_permission.`created_by` = user_row.`id`
      AND owner_permission.`status` IN ('active', '1')
      AND (
        owner_permission.`expires_at` IS NULL
        OR owner_permission.`expires_at` > NOW()
      )
  ) = 3
  AND NOT EXISTS (
    SELECT 1
    FROM `user_hotel_permissions` unexpected_permission
    WHERE unexpected_permission.`user_id` = user_row.`id`
      AND unexpected_permission.`status` IN ('active', '1')
      AND (
        unexpected_permission.`expires_at` IS NULL
        OR unexpected_permission.`expires_at` > NOW()
      )
      AND (
        unexpected_permission.`hotel_id` NOT IN (131, 133, 182)
        OR unexpected_permission.`scope_type` <> 'owner'
        OR unexpected_permission.`created_by` <> user_row.`id`
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `hotels` scoped_hotel
    INNER JOIN `hotels` foreign_hotel
      ON foreign_hotel.`tenant_id` = scoped_hotel.`tenant_id`
    WHERE scoped_hotel.`id` IN (131, 133, 182)
      AND (
        foreign_hotel.`owner_user_id` <> user_row.`id`
        OR foreign_hotel.`created_by` <> user_row.`id`
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `user_hotel_permissions` foreign_permission
    WHERE foreign_permission.`hotel_id` IN (131, 133, 182)
      AND foreign_permission.`user_id` <> user_row.`id`
      AND foreign_permission.`status` IN ('active', '1')
      AND (
        foreign_permission.`expires_at` IS NULL
        OR foreign_permission.`expires_at` > NOW()
      )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `users` foreign_user
    WHERE foreign_user.`tenant_id` IN (131, 133, 182)
      AND foreign_user.`id` <> user_row.`id`
      AND foreign_user.`status` = 1
  );

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_vip021_tenant_sources`;
CREATE TEMPORARY TABLE `tmp_suxios_vip021_tenant_sources` (
  `source_tenant_id` int unsigned NOT NULL,
  `target_tenant_id` int unsigned NOT NULL,
  PRIMARY KEY (`source_tenant_id`)
) ENGINE=MEMORY;

INSERT INTO `tmp_suxios_vip021_tenant_sources` (
  `source_tenant_id`,
  `target_tenant_id`
)
SELECT DISTINCT
  owned_hotel.`tenant_id`,
  target_row.`target_tenant_id`
FROM `tmp_suxios_vip021_tenant_target` target_row
INNER JOIN `hotels` owned_hotel
  ON owned_hotel.`owner_user_id` = target_row.`owner_user_id`
  AND owned_hotel.`created_by` = target_row.`owner_user_id`
WHERE owned_hotel.`tenant_id` IN (131, 182)
  AND owned_hotel.`tenant_id` <> target_row.`target_tenant_id`;

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_move_vip021_tenant_scope`$$

CREATE PROCEDURE `suxios_move_vip021_tenant_scope`()
BEGIN
  DECLARE v_done tinyint unsigned DEFAULT 0;
  DECLARE v_table_name varchar(64);
  DECLARE tenant_table_cursor CURSOR FOR
    SELECT column_row.`TABLE_NAME`
    FROM `information_schema`.`COLUMNS` column_row
    INNER JOIN `information_schema`.`TABLES` table_row
      ON table_row.`TABLE_SCHEMA` = column_row.`TABLE_SCHEMA`
      AND table_row.`TABLE_NAME` = column_row.`TABLE_NAME`
    WHERE column_row.`TABLE_SCHEMA` = DATABASE()
      AND column_row.`COLUMN_NAME` = 'tenant_id'
      AND table_row.`TABLE_TYPE` = 'BASE TABLE'
      AND column_row.`TABLE_NAME` REGEXP '^[0-9A-Za-z_]+$'
    ORDER BY column_row.`TABLE_NAME`;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  IF EXISTS (SELECT 1 FROM `tmp_suxios_vip021_tenant_target`) THEN
    OPEN tenant_table_cursor;
    tenant_table_loop: LOOP
      FETCH tenant_table_cursor INTO v_table_name;
      IF v_done = 1 THEN
        LEAVE tenant_table_loop;
      END IF;

      SET @suxios_vip021_tenant_sql = CONCAT(
        'UPDATE `',
        v_table_name,
        '` tenant_row ',
        'INNER JOIN `tmp_suxios_vip021_tenant_sources` source_row ',
        'ON source_row.`source_tenant_id` = tenant_row.`tenant_id` ',
        'SET tenant_row.`tenant_id` = source_row.`target_tenant_id`'
      );
      PREPARE suxios_vip021_tenant_statement FROM @suxios_vip021_tenant_sql;
      EXECUTE suxios_vip021_tenant_statement;
      DEALLOCATE PREPARE suxios_vip021_tenant_statement;
    END LOOP;
    CLOSE tenant_table_cursor;
  END IF;
END$$

DELIMITER ;

START TRANSACTION;

CALL `suxios_move_vip021_tenant_scope`();

UPDATE `hotels` hotel_row
INNER JOIN `tmp_suxios_vip021_tenant_target` target_row
  ON target_row.`owner_user_id` = hotel_row.`owner_user_id`
  AND target_row.`owner_user_id` = hotel_row.`created_by`
SET hotel_row.`tenant_id` = target_row.`target_tenant_id`
WHERE hotel_row.`id` IN (131, 133, 182);

UPDATE `user_hotel_permissions` permission_row
INNER JOIN `tmp_suxios_vip021_tenant_target` target_row
  ON target_row.`owner_user_id` = permission_row.`user_id`
SET
  permission_row.`tenant_id` = target_row.`target_tenant_id`,
  permission_row.`scope_type` = 'owner',
  permission_row.`is_primary` = CASE
    WHEN permission_row.`hotel_id` = target_row.`primary_hotel_id` THEN 1
    ELSE 0
  END
WHERE permission_row.`hotel_id` IN (131, 133, 182);

UPDATE `users` user_row
INNER JOIN `tmp_suxios_vip021_tenant_target` target_row
  ON target_row.`owner_user_id` = user_row.`id`
SET
  user_row.`tenant_id` = target_row.`target_tenant_id`,
  user_row.`hotel_id` = target_row.`primary_hotel_id`;

UPDATE `tenants` target_tenant
INNER JOIN `tmp_suxios_vip021_tenant_target` target_row
  ON target_row.`target_tenant_id` = target_tenant.`id`
SET
  target_tenant.`status` = 1,
  target_tenant.`updated_at` = CURRENT_TIMESTAMP;

UPDATE `tenants` source_tenant
INNER JOIN `tmp_suxios_vip021_tenant_sources` source_row
  ON source_row.`source_tenant_id` = source_tenant.`id`
SET
  source_tenant.`status` = 0,
  source_tenant.`updated_at` = CURRENT_TIMESTAMP;

COMMIT;

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_move_vip021_tenant_scope`$$

DELIMITER ;

DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_vip021_tenant_sources`;
DROP TEMPORARY TABLE IF EXISTS `tmp_suxios_vip021_tenant_target`;
