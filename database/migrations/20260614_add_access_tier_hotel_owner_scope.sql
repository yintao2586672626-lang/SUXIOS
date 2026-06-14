ALTER TABLE `hotels`
  ADD COLUMN IF NOT EXISTS `created_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人用户ID' AFTER `description`,
  ADD INDEX IF NOT EXISTS `idx_hotels_created_by` (`created_by`, `status`);

UPDATE `roles`
SET `name` = 'admin',
    `display_name` = '管理员',
    `description` = '全部权限，能看所有数据',
    `level` = 1,
    `permissions` = '["all"]',
    `status` = 1
WHERE `id` = 1;

UPDATE `roles`
SET `name` = 'beta_user',
    `display_name` = '内测用户',
    `description` = '只能看授权且自己添加的酒店，可管理自己添加的酒店，不能管理用户/角色/系统配置',
    `level` = 2,
    `permissions` = '["can_view_online_data","can_fetch_online_data","can_manage_own_hotels"]',
    `status` = 1
WHERE `id` = 2;

UPDATE `roles`
SET `name` = 'normal_user',
    `display_name` = '普通用户',
    `description` = '只有 OTA 获取，只能看授权酒店，无法添加酒店',
    `level` = 3,
    `permissions` = '["can_view_online_data","can_fetch_online_data"]',
    `status` = 1
WHERE `id` = 3;

UPDATE `roles`
SET `status` = 0
WHERE `id` IN (4, 5, 6)
  AND `name` IN ('super_admin', 'hotel_manager', 'hotel_staff');

UPDATE `user_hotel_permissions`
SET `can_view_report` = 0,
    `can_fill_daily_report` = 0,
    `can_fill_monthly_task` = 0,
    `can_edit_report` = 0,
    `can_delete_report` = 0,
    `can_view_online_data` = 1,
    `can_fetch_online_data` = 1,
    `can_delete_online_data` = 0
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role_id` IN (2, 3));

UPDATE `hotels` h
INNER JOIN (
  SELECT uhp.`hotel_id`, MIN(uhp.`user_id`) AS `owner_user_id`, COUNT(*) AS `owner_count`
  FROM `user_hotel_permissions` uhp
  INNER JOIN `users` u ON u.`id` = uhp.`user_id`
  WHERE u.`role_id` = 2
  GROUP BY uhp.`hotel_id`
  HAVING COUNT(*) = 1
) owner_map ON owner_map.`hotel_id` = h.`id`
SET h.`created_by` = owner_map.`owner_user_id`
WHERE h.`created_by` = 0;
