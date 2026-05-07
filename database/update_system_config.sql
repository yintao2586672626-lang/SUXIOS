-- 补充缺失的系统配置项
-- 执行此SQL以添加缺失的菜单配置

-- 检查并添加 menu_hotel_name
INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'menu_hotel_name', '酒店管理', '酒店管理菜单名称', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'menu_hotel_name');

-- 检查并添加 menu_users_name
INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'menu_users_name', '用户管理', '用户管理菜单名称', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'menu_users_name');

-- 检查并添加微信小程序配置
INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'wechat_mini_appid', '', '微信小程序AppID', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'wechat_mini_appid');

INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'wechat_mini_secret', '', '微信小程序AppSecret', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'wechat_mini_secret');

-- 检查并添加吐槽码配置
INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'complaint_mini_page', 'pages/complaint/index', '吐槽码小程序页面路径', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'complaint_mini_page');

INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'complaint_mini_use_scene', '1', '吐槽码小程序使用scene', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'complaint_mini_use_scene');

-- 查看当前所有配置
SELECT * FROM `system_config` ORDER BY `id`;
