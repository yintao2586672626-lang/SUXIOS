-- 跨会话个人经营副驾任务记忆。
-- 只保存用户已发起的系统使用路线，不保存经营事实、凭证或外部执行授权。
CREATE TABLE IF NOT EXISTS `user_guidance_journeys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户所属租户；超级管理员全局上下文为0',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '任务记忆所属用户',
  `hotel_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店作用域；0表示当前租户内全局任务',
  `journey_key` CHAR(64) NOT NULL COMMENT '稳定任务身份SHA-256',
  `version_no` INT UNSIGNED NOT NULL COMMENT '同任务追加版本',
  `goal` VARCHAR(240) NOT NULL COMMENT '用户尚未完成的目标，不表示已完成',
  `original_query_digest` CHAR(64) NOT NULL COMMENT '原始目标单向摘要；不保存原始聊天',
  `active_key` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '当前服务器目录中的功能键',
  `journey_keys_json` JSON NOT NULL COMMENT '最多四个服务器目录功能键',
  `current_step_status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending/in_progress/checking/blocked/completed',
  `blocker_code` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '可恢复阻塞代码',
  `blocker_summary` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '不含凭证的阻塞摘要',
  `lifecycle_status` VARCHAR(30) NOT NULL DEFAULT 'active' COMMENT 'active/superseded/completed/archived',
  `content_digest` CHAR(64) NOT NULL COMMENT '当前版本规范内容SHA-256',
  `previous_journey_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '上一版本记录ID',
  `recorded_by` BIGINT UNSIGNED NOT NULL COMMENT '保存人用户ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_guidance_journey_version` (`tenant_id`, `user_id`, `hotel_id`, `journey_key`, `version_no`),
  KEY `idx_user_guidance_current` (`tenant_id`, `user_id`, `hotel_id`, `lifecycle_status`, `id`),
  KEY `idx_user_guidance_previous` (`previous_journey_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='宿析OS跨会话个人经营副驾任务记忆';
