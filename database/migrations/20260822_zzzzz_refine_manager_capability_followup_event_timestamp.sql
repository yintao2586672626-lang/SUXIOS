-- 已登记迁移保持不可变；单独提升复查事件时间到微秒精度。
-- 用于同一秒内可靠区分多次人工复查的先后顺序。

ALTER TABLE `manager_capability_case_followups`
  MODIFY COLUMN `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);
