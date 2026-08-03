-- A forecast pilot has no promised uplift threshold. Keep that unknown as NULL
-- instead of persisting a misleading numeric zero in the execution loop.
ALTER TABLE `operation_execution_intents`
  MODIFY COLUMN `expected_delta` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'expected metric delta percent; NULL when not quantified';

ALTER TABLE `operation_action_tracks`
  MODIFY COLUMN `target_change_rate` DECIMAL(8,2) NULL DEFAULT NULL COMMENT 'target change rate; NULL when not quantified';
