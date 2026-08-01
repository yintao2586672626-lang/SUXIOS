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

-- 自助注册已永久关闭，删除历史开关以免后台产生误导
DELETE FROM `system_config` WHERE `config_key` = 'enable_registration';

-- 登录页只公开此单一联系方式字段
INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'login_support_contact', '请联系贵司宿析OS系统管理员', '登录页管理员联系方式', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'login_support_contact');

UPDATE `system_config`
SET `config_value` = '请联系贵司宿析OS系统管理员', `update_time` = NOW()
WHERE `config_key` = 'login_support_contact'
  AND `config_value` = '微信：殷涛 | 归鹿🦌宿里';

-- 检查并添加密码策略配置

INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'password_min_length', '6', '密码最小长度', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'password_min_length');

UPDATE `system_config`
SET `config_value` = '6', `update_time` = NOW()
WHERE `config_key` = 'password_min_length'
  AND (TRIM(COALESCE(`config_value`, '')) = '' OR CAST(`config_value` AS UNSIGNED) < 6);

INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'password_require_special', '0', '密码要求特殊字符', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'password_require_special');

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

-- 初始化脚本不输出全量配置，避免打印 Cookie、Token、API Key 等敏感值。
