-- One approved execution intent owns at most one persisted execution task.
-- Legacy intent_id=0 rows remain outside the constraint; any real duplicate
-- fails visibly and must be investigated instead of being silently deleted.

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_validate_operation_task_uniqueness`$$

CREATE PROCEDURE `suxios_validate_operation_task_uniqueness`()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM `operation_execution_tasks`
    WHERE `intent_id` > 0
    GROUP BY `intent_id`
    HAVING COUNT(*) > 1
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Cannot enforce one operation task per intent: duplicate intent_id rows exist';
  END IF;
END$$

CALL `suxios_validate_operation_task_uniqueness`()$$
DROP PROCEDURE IF EXISTS `suxios_validate_operation_task_uniqueness`$$

DELIMITER ;

ALTER TABLE `operation_execution_tasks`
  ADD COLUMN IF NOT EXISTS `unique_intent_id` BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN `intent_id` > 0 THEN `intent_id` ELSE NULL END) STORED
    COMMENT 'database-enforced single task identity for a persisted intent'
    AFTER `intent_id`,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_operation_execution_tasks_intent_once` (`unique_intent_id`);
