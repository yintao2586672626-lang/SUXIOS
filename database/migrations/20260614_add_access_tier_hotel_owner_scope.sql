ALTER TABLE `hotels`
  ADD COLUMN IF NOT EXISTS `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '当前酒店归属用户ID' AFTER `description`,
  ADD COLUMN IF NOT EXISTS `created_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人用户ID' AFTER `owner_user_id`,
  ADD INDEX IF NOT EXISTS `idx_hotels_owner_user_id` (`owner_user_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_hotels_created_by` (`created_by`, `status`);

ALTER TABLE `user_hotel_permissions`
  ADD COLUMN IF NOT EXISTS `scope_type` varchar(20) NOT NULL DEFAULT 'granted' COMMENT 'owner/granted/system' AFTER `hotel_id`,
  ADD COLUMN IF NOT EXISTS `can_view` tinyint NOT NULL DEFAULT 0 COMMENT '可查看酒店' AFTER `scope_type`,
  ADD COLUMN IF NOT EXISTS `can_report` tinyint NOT NULL DEFAULT 0 COMMENT '可查看报表' AFTER `can_view`,
  ADD COLUMN IF NOT EXISTS `can_fill` tinyint NOT NULL DEFAULT 0 COMMENT '可填报经营数据' AFTER `can_report`,
  ADD COLUMN IF NOT EXISTS `can_edit` tinyint NOT NULL DEFAULT 0 COMMENT '可编辑酒店基础信息' AFTER `can_fill`,
  ADD COLUMN IF NOT EXISTS `can_fetch_ota` tinyint NOT NULL DEFAULT 0 COMMENT '可发起OTA采集' AFTER `can_edit`,
  ADD COLUMN IF NOT EXISTS `can_delete_ota` tinyint NOT NULL DEFAULT 0 COMMENT '可删除OTA数据' AFTER `can_fetch_ota`,
  ADD COLUMN IF NOT EXISTS `can_export` tinyint NOT NULL DEFAULT 0 COMMENT '可导出数据' AFTER `can_delete_ota`,
  ADD COLUMN IF NOT EXISTS `can_ai` tinyint NOT NULL DEFAULT 0 COMMENT '可使用AI决策' AFTER `can_export`,
  ADD COLUMN IF NOT EXISTS `can_operation` tinyint NOT NULL DEFAULT 0 COMMENT '可使用运营决策' AFTER `can_ai`,
  ADD COLUMN IF NOT EXISTS `can_investment` tinyint NOT NULL DEFAULT 0 COMMENT '可使用投资模拟' AFTER `can_operation`,
  ADD COLUMN IF NOT EXISTS `expires_at` datetime NULL COMMENT '授权过期时间' AFTER `can_investment`,
  ADD COLUMN IF NOT EXISTS `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active/disabled' AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `created_by` int unsigned NOT NULL DEFAULT 0 COMMENT '授权创建人' AFTER `status`;

UPDATE `roles`
SET `name` = 'admin',
    `display_name` = '超级管理员',
    `description` = '平台方管理者，拥有全局酒店、用户、角色、系统配置、OTA、AI、导出、删除权限',
    `level` = 1,
    `permissions` = '["all"]',
    `status` = 1
WHERE `id` = 1;

UPDATE `roles`
SET `name` = 'beta_user',
    `display_name` = 'VIP 用户',
    `description` = '酒店经营者；可新增酒店，只能访问和管理自己名下酒店，不能管理平台用户、角色或系统配置',
    `level` = 2,
    `permissions` = '["dashboard.view","hotel.create","hotel.view","hotel.update","ota.view","ota.collect","report.view","report.export","ai.view","ai.execute","operation.view","operation.execute","investment.view","investment.simulate"]',
    `status` = 1
WHERE `id` = 2;

UPDATE `roles`
SET `name` = 'normal_user',
    `display_name` = '普通用户',
    `description` = '数据查看者；只能查看授权酒店，不可新增酒店、编辑酒店、发起OTA采集或执行高危动作',
    `level` = 3,
    `permissions` = '["dashboard.view","hotel.view","ota.view","report.view"]',
    `status` = 1
WHERE `id` = 3;

UPDATE `roles`
SET `status` = 0
WHERE `id` IN (4, 5, 6)
  AND `name` IN ('super_admin', 'hotel_manager', 'hotel_staff');

UPDATE `user_hotel_permissions` uhp
INNER JOIN `users` u ON u.`id` = uhp.`user_id`
SET uhp.`scope_type` = CASE WHEN u.`role_id` = 2 THEN 'owner' ELSE 'granted' END,
    uhp.`can_view` = 1,
    uhp.`can_report` = 1,
    uhp.`can_fill` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_edit` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_fetch_ota` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_delete_ota` = 0,
    uhp.`can_export` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_ai` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_operation` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_investment` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`status` = 'active',
    uhp.`can_view_report` = 1,
    uhp.`can_fill_daily_report` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_fill_monthly_task` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_edit_report` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_delete_report` = 0,
    uhp.`can_view_online_data` = 1,
    uhp.`can_fetch_online_data` = CASE WHEN u.`role_id` = 2 THEN 1 ELSE 0 END,
    uhp.`can_delete_online_data` = 0
WHERE u.`role_id` IN (2, 3);

UPDATE `hotels` h
INNER JOIN (
  SELECT uhp.`hotel_id`, MIN(uhp.`user_id`) AS `owner_user_id`, COUNT(*) AS `owner_count`
  FROM `user_hotel_permissions` uhp
  INNER JOIN `users` u ON u.`id` = uhp.`user_id`
  WHERE u.`role_id` = 2
  GROUP BY uhp.`hotel_id`
  HAVING COUNT(*) = 1
) owner_map ON owner_map.`hotel_id` = h.`id`
SET h.`owner_user_id` = owner_map.`owner_user_id`,
    h.`created_by` = CASE WHEN h.`created_by` = 0 THEN owner_map.`owner_user_id` ELSE h.`created_by` END
WHERE h.`owner_user_id` = 0;

UPDATE `hotels`
SET `owner_user_id` = `created_by`
WHERE `owner_user_id` = 0
  AND `created_by` > 0;
