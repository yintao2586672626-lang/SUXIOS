ALTER TABLE `opening_tasks`
  ADD COLUMN IF NOT EXISTS `progress_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '任务进度百分比' AFTER `status`;

UPDATE `opening_tasks`
SET `progress_percent` = CASE
  WHEN `status` = 'done' THEN 100
  WHEN `status` = 'doing' THEN 50
  WHEN `status` = 'blocked' THEN 25
  ELSE 0
END
WHERE `progress_percent` = 0;
