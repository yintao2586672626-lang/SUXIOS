-- 人工审批冻结的量化增量与独立效果复盘口径统一保留 6 位小数。
-- 旧的 DECIMAL(10,2) 会把 1.2345 静默变成 1.23，导致审批与复盘目标漂移。
ALTER TABLE `operation_execution_intents`
  MODIFY COLUMN `expected_delta` DECIMAL(20,6) NULL COMMENT '审批前冻结的目标变化量；NULL 表示尚未量化';
