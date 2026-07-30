-- Remove the obsolete self-registration switch and add the public login-support contact.
DELETE FROM `system_config` WHERE `config_key` = 'enable_registration';

INSERT INTO `system_config` (`config_key`, `config_value`, `description`, `create_time`, `update_time`)
SELECT 'login_support_contact', '请联系贵司宿析OS系统管理员', '登录页管理员联系方式', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `config_key` = 'login_support_contact');

UPDATE `system_config`
SET `config_value` = '请联系贵司宿析OS系统管理员', `update_time` = NOW()
WHERE `config_key` = 'login_support_contact'
  AND `config_value` = '微信：殷涛 | 归鹿🦌宿里';
