-- 已创建 operation_effect_reviews 的环境补充审批冻结目标绑定。
-- 旧记录保持 NULL 并只能作为历史记录读取；只有新写入且与当前 intent 合同一致的记录可生效。
ALTER TABLE `operation_effect_reviews`
  ADD COLUMN IF NOT EXISTS `approval_target_digest` CHAR(64) DEFAULT NULL
  COMMENT '创建复盘时绑定的人工审批冻结目标摘要' AFTER `metric_definition_digest`;
