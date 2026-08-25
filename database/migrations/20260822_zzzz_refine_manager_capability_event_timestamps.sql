-- 保留已经登记的优化迁移不变，后续把跨表事件时间提升到微秒精度。
-- 用于在同一秒内可靠区分复查、纠错和人工评分复核的先后顺序。

ALTER TABLE `manager_capability_case_adjustments`
  MODIFY COLUMN `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);

ALTER TABLE `manager_capability_score_reviews`
  MODIFY COLUMN `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);
