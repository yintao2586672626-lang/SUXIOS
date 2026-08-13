-- 前向增强已经登记过的经营闭环内核迁移。
--
-- 20260812_z_create_hotel_operating_cycle_kernel.sql 已在部分环境执行，必须保持
-- 原校验和不变。本修订只允许空内核表升级：旧事件链的 command/event/projection
-- digest 不能由 SQL 默认值可信重建，存在任何行时必须停下并走专用审计转换。

DROP PROCEDURE IF EXISTS `suxios_assert_empty_operating_cycle_kernel`;

DELIMITER //
CREATE PROCEDURE `suxios_assert_empty_operating_cycle_kernel`()
BEGIN
  IF EXISTS (SELECT 1 FROM `hotel_operating_cycles` LIMIT 1)
     OR EXISTS (SELECT 1 FROM `hotel_operating_cycle_events` LIMIT 1)
     OR EXISTS (SELECT 1 FROM `hotel_operating_cycle_evidence` LIMIT 1) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'hotel_operating_cycle_kernel_has_rows_requires_audited_conversion';
  END IF;
END//
DELIMITER ;

CALL `suxios_assert_empty_operating_cycle_kernel`();
DROP PROCEDURE IF EXISTS `suxios_assert_empty_operating_cycle_kernel`;

ALTER TABLE `hotel_operating_cycle_events`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED NOT NULL AFTER `cycle_id`,
  ADD COLUMN IF NOT EXISTS `hotel_id` INT UNSIGNED NOT NULL AFTER `tenant_id`,
  ADD COLUMN IF NOT EXISTS `command_digest` CHAR(64) NOT NULL AFTER `command_key`,
  ADD COLUMN IF NOT EXISTS `from_stage` VARCHAR(80) NOT NULL DEFAULT '' AFTER `command_digest`,
  ADD COLUMN IF NOT EXISTS `to_stage` VARCHAR(80) NOT NULL AFTER `from_stage`,
  ADD COLUMN IF NOT EXISTS `from_version` INT UNSIGNED NOT NULL AFTER `to_stage`,
  ADD COLUMN IF NOT EXISTS `to_version` INT UNSIGNED NOT NULL AFTER `from_version`,
  MODIFY COLUMN `stage_key` VARCHAR(80) NOT NULL
    COMMENT '兼容投影字段，必须与 to_stage 一致';

ALTER TABLE `hotel_operating_cycle_events`
  DROP INDEX IF EXISTS `idx_hotel_operating_cycle_event_scope`,
  ADD KEY `idx_hotel_operating_cycle_event_scope`
    (`tenant_id`, `hotel_id`, `cycle_id`, `sequence_no`);

ALTER TABLE `hotel_operating_cycle_evidence`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED NOT NULL AFTER `event_id`,
  ADD COLUMN IF NOT EXISTS `hotel_id` INT UNSIGNED NOT NULL AFTER `tenant_id`,
  ADD COLUMN IF NOT EXISTS `fact_scope` VARCHAR(48) NOT NULL
    COMMENT 'identity/whole_hotel_accommodation/ota_channel/decision/execution/outcome/knowledge'
    AFTER `source_kind`,
  ADD COLUMN IF NOT EXISTS `metric_definition_digest` CHAR(64) NOT NULL AFTER `fact_scope`,
  ADD COLUMN IF NOT EXISTS `verification_status` VARCHAR(24) NOT NULL
    COMMENT 'readback_verified/partial/unverified/conflicted'
    AFTER `source_rows_digest`;

ALTER TABLE `hotel_operating_cycle_evidence`
  DROP INDEX IF EXISTS `idx_hotel_operating_cycle_evidence_cycle`,
  DROP INDEX IF EXISTS `idx_hotel_operating_cycle_evidence_scope`,
  ADD KEY `idx_hotel_operating_cycle_evidence_scope`
    (`tenant_id`, `hotel_id`, `cycle_id`, `stage_key`, `id`);

SET @suxios_drop_event_cycle_fk = IF(
  EXISTS (
    SELECT 1 FROM `information_schema`.`TABLE_CONSTRAINTS`
    WHERE `CONSTRAINT_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'hotel_operating_cycle_events'
      AND `CONSTRAINT_NAME` = 'fk_hotel_operating_cycle_event_cycle'
      AND `CONSTRAINT_TYPE` = 'FOREIGN KEY'
  ),
  'ALTER TABLE `hotel_operating_cycle_events` DROP FOREIGN KEY `fk_hotel_operating_cycle_event_cycle`',
  'SET @suxios_operating_cycle_fk_noop = 1'
);
PREPARE `suxios_stmt` FROM @suxios_drop_event_cycle_fk;
EXECUTE `suxios_stmt`;
DEALLOCATE PREPARE `suxios_stmt`;

SET @suxios_drop_evidence_cycle_fk = IF(
  EXISTS (
    SELECT 1 FROM `information_schema`.`TABLE_CONSTRAINTS`
    WHERE `CONSTRAINT_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'hotel_operating_cycle_evidence'
      AND `CONSTRAINT_NAME` = 'fk_hotel_operating_cycle_evidence_cycle'
      AND `CONSTRAINT_TYPE` = 'FOREIGN KEY'
  ),
  'ALTER TABLE `hotel_operating_cycle_evidence` DROP FOREIGN KEY `fk_hotel_operating_cycle_evidence_cycle`',
  'SET @suxios_operating_cycle_fk_noop = 1'
);
PREPARE `suxios_stmt` FROM @suxios_drop_evidence_cycle_fk;
EXECUTE `suxios_stmt`;
DEALLOCATE PREPARE `suxios_stmt`;

SET @suxios_drop_evidence_event_fk = IF(
  EXISTS (
    SELECT 1 FROM `information_schema`.`TABLE_CONSTRAINTS`
    WHERE `CONSTRAINT_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'hotel_operating_cycle_evidence'
      AND `CONSTRAINT_NAME` = 'fk_hotel_operating_cycle_evidence_event'
      AND `CONSTRAINT_TYPE` = 'FOREIGN KEY'
  ),
  'ALTER TABLE `hotel_operating_cycle_evidence` DROP FOREIGN KEY `fk_hotel_operating_cycle_evidence_event`',
  'SET @suxios_operating_cycle_fk_noop = 1'
);
PREPARE `suxios_stmt` FROM @suxios_drop_evidence_event_fk;
EXECUTE `suxios_stmt`;
DEALLOCATE PREPARE `suxios_stmt`;

ALTER TABLE `hotel_operating_cycle_events`
  ADD CONSTRAINT `fk_hotel_operating_cycle_event_cycle`
    FOREIGN KEY (`cycle_id`) REFERENCES `hotel_operating_cycles` (`id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE `hotel_operating_cycle_evidence`
  ADD CONSTRAINT `fk_hotel_operating_cycle_evidence_cycle`
    FOREIGN KEY (`cycle_id`) REFERENCES `hotel_operating_cycles` (`id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_hotel_operating_cycle_evidence_event`
    FOREIGN KEY (`event_id`) REFERENCES `hotel_operating_cycle_events` (`id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

-- 本迁移只做空表前向收口；闭环事件与证据属于不可变审计事实，不自动回填或删除。
