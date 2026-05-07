-- ============================================
-- 酒店管理后台系统 MySQL 数据库
-- 生成时间: 2026-02-27 04:59:46
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 表结构 for users
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL COMMENT '用户名',
  `password` VARCHAR(255) NOT NULL COMMENT '密码',
  `realname` VARCHAR(50) COMMENT '真实姓名',
  `email` VARCHAR(100) COMMENT '邮箱',
  `phone` VARCHAR(20) COMMENT '手机号',
  `role_id` INT UNSIGNED DEFAULT NULL COMMENT '角色ID',
  `status` TINYINT DEFAULT 1 COMMENT '状态: 1启用 0禁用',
  `hotel_id` INT UNSIGNED DEFAULT NULL COMMENT '关联酒店ID',
  `last_login_time` DATETIME COMMENT '最后登录时间',
  `last_login_ip` VARCHAR(50) COMMENT '最后登录IP',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role` (`role_id`),
  KEY `idx_hotel` (`hotel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

INSERT INTO `users` VALUES (1, 'admin', '$2y$10$BODZAPJzu.NDfYj2RYrmIeum7acfghE54HOtasVv2dohuUYcL6INS', '超级管理员', NULL, NULL, 1, 1, NULL, '2026-02-27 12:54:52', '127.0.0.1', '2026-02-24 09:15:46', '2026-02-27 12:54:52.645979');
INSERT INTO `users` VALUES (2, 'manager1', '$2y$10$Ooi6fGyBi07EgMfCRSp7veZ8uaaProuB5Hlu6foCz2bIZXbvy6tHC', '门店经理张三', NULL, NULL, 2, 1, 1, '2026-02-26 18:06:34', '127.0.0.1', '2026-02-24 09:15:46', '2026-02-26 18:06:34.975211');
INSERT INTO `users` VALUES (3, 'staff1', '$2y$10$Zj7ZXEeL.lsS4vKpQPlaNeoWf1TcY2Ni0tZitmsZgb3hV1oYGKYHu', '店员李四', NULL, NULL, 3, 1, 1, '2026-02-26 17:01:47', '127.0.0.1', '2026-02-24 09:15:46', '2026-02-26 17:01:47.709756');
INSERT INTO `users` VALUES (4, 'mcc2', '$2y$10$o42FT0RyxJiqb/LzuvsBme9veeU.NILv36.BD5Hna1GNVorE9zP7y', '栖悦里', '', '', 2, 1, NULL, '2026-02-26 17:01:27', '127.0.0.1', '2026-02-26 16:18:24.711014', '2026-02-26 17:01:27.527995');


-- ----------------------------
-- 表结构 for roles
-- ----------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL COMMENT '角色标识',
  `display_name` VARCHAR(100) COMMENT '显示名称',
  `description` VARCHAR(255) COMMENT '描述',
  `level` INT DEFAULT 0 COMMENT '等级',
  `permissions` TEXT COMMENT '权限列表JSON',
  `status` TINYINT DEFAULT 1 COMMENT '状态',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色表';

INSERT INTO `roles` VALUES (1, '超级管理员', '超级管理员', '拥有系统所有权限', 1, '{\"all\":true}', 1, '2026-02-24 09:15:46', '2026-02-24 09:15:46');
INSERT INTO `roles` VALUES (2, '门店管理员', '门店管理员', '管理单个酒店', 2, '[\"can_view_report\",\"can_fill_daily_report\",\"can_fill_monthly_task\",\"can_edit_report\",\"can_view_online_data\",\"can_fetch_online_data\"]', 1, '2026-02-24 09:15:46', '2026-02-26 17:28:07.894610');
INSERT INTO `roles` VALUES (3, '店员', '店员', '普通员工', 3, '[\"can_fill_daily_report\",\"can_view_report\",\"can_edit_report\",\"can_fetch_online_data\",\"can_view_online_data\"]', 1, '2026-02-24 09:15:46', '2026-02-26 17:28:27.386559');
INSERT INTO `roles` VALUES (4, 'super_admin', '超级管理员', '拥有系统所有权限，可管理所有酒店', 1, '[\"all\"]', 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `roles` VALUES (5, 'hotel_manager', '门店管理员', '管理指定酒店，可查看、填写、编辑报表', 2, '[\"hotel_view\",\"report_view\",\"report_fill\",\"report_edit\",\"report_delete\"]', 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `roles` VALUES (6, 'hotel_staff', '店员', '只能填写指定酒店的报表', 3, '[\"report_view\",\"report_fill\"]', 1, '2026-02-26 17:45:27', NULL);


-- ----------------------------
-- 表结构 for hotels
-- ----------------------------
DROP TABLE IF EXISTS `hotels`;
CREATE TABLE `hotels` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL COMMENT '酒店名称',
  `code` VARCHAR(50) COMMENT '酒店编码',
  `address` VARCHAR(255) COMMENT '地址',
  `contact_person` VARCHAR(50) COMMENT '联系人',
  `contact_phone` VARCHAR(20) COMMENT '联系电话',
  `status` TINYINT DEFAULT 1 COMMENT '状态: 1启用 0禁用',
  `description` TEXT COMMENT '描述',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='酒店表';

INSERT INTO `hotels` VALUES (1, '南宁市银田酒店', 'YINTIAN', '', '', '', 1, '', '2026-02-24 09:15:46', '2026-02-26 18:06:49.965114');
INSERT INTO `hotels` VALUES (2, '西湖度假村', 'XH002', '杭州市西湖区', '李经理', '0571-87654321', 1, NULL, '2026-02-24 09:15:46', '2026-02-24 09:15:46');
INSERT INTO `hotels` VALUES (3, '海滨国际酒店', 'HB003', '上海市浦东新区', '王经理', '021-11112222', 1, NULL, '2026-02-24 09:15:46', '2026-02-24 09:15:46');
INSERT INTO `hotels` VALUES (4, '东方大酒店', 'DF001', '北京市朝阳区', '张经理', '010-12345678', 1, NULL, '2026-02-27 08:59:40', NULL);


-- ----------------------------
-- 表结构 for user_hotel_permissions
-- ----------------------------
DROP TABLE IF EXISTS `user_hotel_permissions`;
CREATE TABLE `user_hotel_permissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '酒店ID',
  `can_view_report` TINYINT DEFAULT 1 COMMENT '可查看报表',
  `can_fill_daily_report` TINYINT DEFAULT 1 COMMENT '可填写日报表',
  `can_fill_monthly_task` TINYINT DEFAULT 1 COMMENT '可填写月任务',
  `can_edit_report` TINYINT DEFAULT 0 COMMENT '可编辑报表',
  `can_delete_report` TINYINT DEFAULT 0 COMMENT '可删除报表',
  `is_primary` TINYINT DEFAULT 0 COMMENT '是否主要酒店',
  `can_view_online_data` TINYINT DEFAULT 1 COMMENT '可查看线上数据',
  `can_fetch_online_data` TINYINT DEFAULT 0 COMMENT '可获取线上数据',
  `can_delete_online_data` TINYINT DEFAULT 0 COMMENT '可删除线上数据',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_hotel` (`user_id`, `hotel_id`),
  KEY `idx_hotel` (`hotel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户酒店权限表';

INSERT INTO `user_hotel_permissions` VALUES (1, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-02-24 09:15:47', '2026-02-26 16:14:57.529893');
INSERT INTO `user_hotel_permissions` VALUES (2, 3, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, '2026-02-24 09:15:47', '2026-02-26 16:15:12.040001');
INSERT INTO `user_hotel_permissions` VALUES (3, 4, 2, 1, 1, 1, 0, 0, 0, 1, 0, 0, '2026-02-26 16:18:39.633201', '2026-02-26 16:18:39.633213');


-- ----------------------------
-- 表结构 for report_configs
-- ----------------------------
DROP TABLE IF EXISTS `report_configs`;
CREATE TABLE `report_configs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `report_type` VARCHAR(50) COMMENT '报表类型',
  `field_name` VARCHAR(100) COMMENT '字段名',
  `display_name` VARCHAR(100) COMMENT '显示名称',
  `field_type` VARCHAR(50) COMMENT '字段类型',
  `category` VARCHAR(50) COMMENT '分类',
  `unit` VARCHAR(20) COMMENT '单位',
  `options` TEXT COMMENT '选项JSON',
  `sort_order` INT DEFAULT 0 COMMENT '排序',
  `is_required` TINYINT DEFAULT 0 COMMENT '是否必填',
  `status` TINYINT DEFAULT 1 COMMENT '状态',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_report_type` (`report_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='报表配置表';

INSERT INTO `report_configs` VALUES (80, 'monthly', 'revenue_budget', '营业预算', 'number', '月任务数据填写', '元', NULL, 1, 1, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (81, 'monthly', 'ota_total_orders', 'OTA总单量', 'number', '月任务数据填写', '单', NULL, 2, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (82, 'monthly', 'gold_card_sales', '金卡销售月任务', 'number', '月任务数据填写', '张', NULL, 3, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (83, 'monthly', 'black_gold_sales', '黑金卡销售月任务', 'number', '月任务数据填写', '张', NULL, 4, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (84, 'monthly', 'meituan_scan_target', '美团扫码目标', 'number', '月任务数据填写', '次', NULL, 5, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (85, 'monthly', 'salable_rooms_total', '可售房间总数', 'number', '月任务数据填写', '间', NULL, 6, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (86, 'monthly', 'occupancy_rate_target', '出租率', 'number', '月任务数据填写', '%', NULL, 7, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (87, 'monthly', 'revpar', '单房收益RP', 'number', '月任务数据填写', '元', NULL, 8, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (88, 'monthly', 'new_members', '新会员数', 'number', '月任务数据填写', '人', NULL, 9, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (89, 'monthly', 'wechat_new_friends', '酒店号新加微信好友数', 'number', '月任务数据填写', '人', NULL, 10, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (90, 'monthly', 'wechat_revenue', '微信成交额', 'number', '月任务数据填写', '元', NULL, 11, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (91, 'monthly', 'new_contracts', '新签协议', 'number', '月任务数据填写', '份', NULL, 12, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (92, 'monthly', 'good_review_rate', '好评率', 'number', '月任务数据填写', '%', NULL, 13, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (93, 'monthly', 'online_revenue_target', '线上营收目标', 'number', '月任务数据填写', '元', NULL, 14, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (94, 'monthly', 'offline_revenue_target', '线下营收目标', 'number', '月任务数据填写', '元', NULL, 15, 0, 1, '2026-02-24 09:19:08', '2026-02-24 09:19:08');
INSERT INTO `report_configs` VALUES (95, 'daily', 'salable_rooms', '当天可售房数', 'number', '一、当天基础数据填写', '间', NULL, 1, 1, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (96, 'daily', 'overnight_rooms', '过夜房间数', 'number', '一、当天基础数据填写', '间', NULL, 2, 1, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (97, 'daily', 'xb_revenue', '携程房费收入', 'number', '二、线上OTA数据填写', '元', NULL, 3, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (98, 'daily', 'xb_rooms', '携程出租间夜', 'number', '二、线上OTA数据填写', '间', NULL, 4, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (99, 'daily', 'mt_revenue', '美团房费收入', 'number', '二、线上OTA数据填写', '元', NULL, 5, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (100, 'daily', 'mt_rooms', '美团出租间夜', 'number', '二、线上OTA数据填写', '间', NULL, 6, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (101, 'daily', 'fliggy_revenue', '飞猪房费收入', 'number', '二、线上OTA数据填写', '元', NULL, 7, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (102, 'daily', 'fliggy_rooms', '飞猪出租间夜', 'number', '二、线上OTA数据填写', '间', NULL, 8, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (103, 'daily', 'dy_revenue', '抖音房费收入', 'number', '二、线上OTA数据填写', '元', NULL, 9, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (104, 'daily', 'dy_rooms', '抖音出租间夜', 'number', '二、线上OTA数据填写', '间', NULL, 10, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (105, 'daily', 'tc_revenue', '同程房费收入', 'number', '二、线上OTA数据填写', '元', NULL, 11, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (106, 'daily', 'tc_rooms', '同程出租间夜', 'number', '二、线上OTA数据填写', '间', NULL, 12, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (107, 'daily', 'qn_revenue', '去哪儿房费收入', 'number', '二、线上OTA数据填写', '元', NULL, 13, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (108, 'daily', 'qn_rooms', '去哪儿出租间夜', 'number', '二、线上OTA数据填写', '间', NULL, 14, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (109, 'daily', 'zx_revenue', '智行房费收入', 'number', '二、线上OTA数据填写', '元', NULL, 15, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (110, 'daily', 'zx_rooms', '智行出租间夜', 'number', '二、线上OTA数据填写', '间', NULL, 16, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (111, 'daily', 'walkin_revenue', '散客房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 17, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (112, 'daily', 'walkin_rooms', '散客出租间夜', 'number', '三、线下渠道数据填写', '间', NULL, 18, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (113, 'daily', 'member_exp_revenue', '会员体验房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 19, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (114, 'daily', 'member_exp_rooms', '会员体验间夜', 'number', '三、线下渠道数据填写', '间', NULL, 20, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (115, 'daily', 'web_exp_revenue', '网络体验房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 21, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (116, 'daily', 'web_exp_rooms', '网络体验出租间夜', 'number', '三、线下渠道数据填写', '间', NULL, 22, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (117, 'daily', 'group_revenue', '团队房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 23, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (118, 'daily', 'group_rooms', '团队出租间夜', 'number', '三、线下渠道数据填写', '间', NULL, 24, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (119, 'daily', 'protocol_revenue', '协议客户房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 25, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (120, 'daily', 'protocol_rooms', '协议客户出租间夜', 'number', '三、线下渠道数据填写', '间', NULL, 26, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (121, 'daily', 'wechat_revenue', '微信房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 27, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (122, 'daily', 'wechat_rooms', '微信出租间夜', 'number', '三、线下渠道数据填写', '间', NULL, 28, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (123, 'daily', 'free_revenue', '免费房房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 29, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (124, 'daily', 'free_rooms', '免费房出租间夜', 'number', '三、线下渠道数据填写', '间', NULL, 30, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (125, 'daily', 'gold_card_revenue', '集团金卡房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 31, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (126, 'daily', 'gold_card_rooms', '集团金卡出租间夜', 'number', '三、线下渠道数据填写', '间', NULL, 32, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (127, 'daily', 'black_gold_revenue', '集团黑金卡房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 33, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (128, 'daily', 'black_gold_rooms', '集团黑金卡出租间夜', 'number', '三、线下渠道数据填写', '间', NULL, 34, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (129, 'daily', 'hourly_revenue', '钟点房房费收入', 'number', '三、线下渠道数据填写', '元', NULL, 35, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (130, 'daily', 'hourly_rooms', '钟点房间夜', 'number', '三、线下渠道数据填写', '间', NULL, 36, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (131, 'daily', 'parking_revenue', '停车费用收入', 'number', '四、其它收入数据填写', '元', NULL, 37, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (132, 'daily', 'dining_revenue', '餐饮费用收入', 'number', '四、其它收入数据填写', '元', NULL, 38, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (133, 'daily', 'meeting_revenue', '会议活动费用收入', 'number', '四、其它收入数据填写', '元', NULL, 39, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (134, 'daily', 'goods_revenue', '商品费用收入', 'number', '四、其它收入数据填写', '元', NULL, 40, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (135, 'daily', 'member_card_revenue', '会员卡费收入', 'number', '四、其它收入数据填写', '元', NULL, 41, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (136, 'daily', 'other_revenue', '其他费用收入', 'number', '四、其它收入数据填写', '元', NULL, 42, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (137, 'daily', 'xb_reviewable', '携程可评价订单数', 'number', '五、OTA点评数据填写', '单', NULL, 43, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (138, 'daily', 'xb_good_review', '携程好评数量', 'number', '五、OTA点评数据填写', '条', NULL, 44, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (139, 'daily', 'xb_bad_review', '携程差评数', 'number', '五、OTA点评数据填写', '条', NULL, 45, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (140, 'daily', 'mt_reviewable', '美团可评价订单数', 'number', '五、OTA点评数据填写', '单', NULL, 46, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (141, 'daily', 'mt_good_review', '美团好评数量', 'number', '五、OTA点评数据填写', '条', NULL, 47, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (142, 'daily', 'mt_bad_review', '美团差评数', 'number', '五、OTA点评数据填写', '条', NULL, 48, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (143, 'daily', 'fliggy_reviewable', '飞猪可评价订单数', 'number', '五、OTA点评数据填写', '单', NULL, 49, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (144, 'daily', 'fliggy_good_review', '飞猪好评数量', 'number', '五、OTA点评数据填写', '条', NULL, 50, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (145, 'daily', 'fliggy_bad_review', '飞猪差评数', 'number', '五、OTA点评数据填写', '条', NULL, 51, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (146, 'daily', 'xb_exposure', '携程列表页曝光量', 'number', '六、OTA流量数据填写', '次', NULL, 52, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (147, 'daily', 'xb_detail_view', '携程详情页浏览量', 'number', '六、OTA流量数据填写', '次', NULL, 53, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (148, 'daily', 'xb_order_view', '携程订单页浏览量', 'number', '六、OTA流量数据填写', '次', NULL, 54, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (149, 'daily', 'xb_order_submit', '携程订单提交量', 'number', '六、OTA流量数据填写', '次', NULL, 55, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (150, 'daily', 'mt_exposure', '美团曝光人数', 'number', '六、OTA流量数据填写', '人', NULL, 56, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (151, 'daily', 'mt_view', '美团浏览人数', 'number', '六、OTA流量数据填写', '人', NULL, 57, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (152, 'daily', 'mt_online_order', '美团线上支付单数', 'number', '六、OTA流量数据填写', '单', NULL, 58, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (153, 'daily', 'wechat_add', '微信加粉', 'number', '七、私域流量数据填写', '人', NULL, 59, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (154, 'daily', 'member_card_sold', '会员卡售卡数量', 'number', '七、私域流量数据填写', '张', NULL, 60, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (155, 'daily', 'private_revenue', '私域订单房费收入', 'number', '七、私域流量数据填写', '元', NULL, 61, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (156, 'daily', 'private_rooms', '私域订单间夜', 'number', '七、私域流量数据填写', '间', NULL, 62, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (157, 'daily', 'stored_value', '储值收入', 'number', '七、私域流量数据填写', '元', NULL, 63, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (158, 'daily', 'damei_card_count', '大美卡数量', 'number', '八、大美卡数据填写', '张', NULL, 64, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (159, 'daily', 'cash_income', '今日现金收入', 'number', '八、大美卡数据填写', '元', NULL, 65, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (160, 'daily', 'tomorrow_booking', '明日预订数量', 'number', '九、明日预订数据填写', '间', NULL, 66, 0, 1, '2026-02-24 09:34:21', '2026-02-24 09:34:21');
INSERT INTO `report_configs` VALUES (161, 'daily', 'total_rooms', '总出租间夜', 'number', '一、当天基础数据填写', '间', NULL, 0, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (162, 'daily', 'hourly_rooms', '钟点房间夜', 'number', '一、当天基础数据填写', '间', NULL, 5, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (163, 'daily', 'maintenance_rooms', '维修房数', 'number', '一、当天基础数据填写', '间', NULL, 10, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (164, 'daily', 'occ_rate', '综合出租率', 'number', '一、当天基础数据填写', '%', NULL, 15, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (165, 'daily', 'adr', '平均房价', 'number', '一、当天基础数据填写', '元', NULL, 20, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (166, 'daily', 'revpar', 'RevPAR', 'number', '一、当天基础数据填写', '元', NULL, 25, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (167, 'daily', 'room_revenue', '房费收入', 'number', '四、其它收入数据填写', '元', NULL, 100, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (168, 'daily', 'online_revenue', '线上收入合计', 'number', '二、线上OTA数据填写', '元', NULL, 200, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (169, 'daily', 'offline_revenue', '线下收入合计', 'number', '三、线下渠道数据填写', '元', NULL, 200, 0, 1, '2026-02-25 06:40:18', '2026-02-25 06:40:18');
INSERT INTO `report_configs` VALUES (170, 'daily', 'total_rooms_count', '总房数', 'number', '基础指标', '间', NULL, 1, 0, 1, '2026-02-25 07:00:32', '2026-02-25 07:00:32');
INSERT INTO `report_configs` VALUES (171, 'daily', 'overnight_occ_rate', '过夜房出租率', 'number', '基础指标', '%', NULL, 3, 0, 1, '2026-02-25 07:00:32', '2026-02-25 07:00:32');
INSERT INTO `report_configs` VALUES (172, 'daily', 'online_rooms', '线上间夜', 'number', '渠道统计', '间', NULL, 50, 0, 1, '2026-02-25 07:00:32', '2026-02-25 07:00:32');
INSERT INTO `report_configs` VALUES (173, 'daily', 'offline_rooms', '线下间夜', 'number', '渠道统计', '间', NULL, 51, 0, 1, '2026-02-25 07:00:32', '2026-02-25 07:00:32');
INSERT INTO `report_configs` VALUES (174, 'daily', 'occupancy_rate', '入住率', 'number', NULL, '%', NULL, 1, 1, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (175, 'daily', 'room_count', '房间数', 'number', NULL, '间', NULL, 2, 0, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (176, 'daily', 'guest_count', '客人数', 'number', NULL, '人', NULL, 3, 0, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (177, 'daily', 'revenue', '收入', 'number', NULL, '元', NULL, 4, 1, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (178, 'daily', 'expenses', '支出', 'number', NULL, '元', NULL, 5, 0, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (179, 'daily', 'notes', '备注', 'textarea', NULL, '', NULL, 6, 0, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (180, 'monthly', 'revenue_target', '收入目标', 'number', NULL, '元', NULL, 1, 1, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (181, 'monthly', 'occupancy_target', '入住率目标', 'number', NULL, '%', NULL, 2, 0, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (182, 'monthly', 'guest_target', '客人目标', 'number', NULL, '人', NULL, 3, 0, 1, '2026-02-26 17:45:27', NULL);
INSERT INTO `report_configs` VALUES (183, 'monthly', 'description', '备注说明', 'textarea', NULL, '', NULL, 4, 0, 1, '2026-02-26 17:45:27', NULL);


-- ----------------------------
-- 表结构 for daily_reports
-- ----------------------------
DROP TABLE IF EXISTS `daily_reports`;
CREATE TABLE `daily_reports` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '酒店ID',
  `report_date` DATE NOT NULL COMMENT '报表日期',
  `report_data` TEXT COMMENT '报表数据JSON',
  `occupancy_rate` DECIMAL(5,2) COMMENT '入住率',
  `room_count` INT COMMENT '房间数',
  `guest_count` INT COMMENT '客人数量',
  `revenue` DECIMAL(12,2) COMMENT '收入',
  `expenses` DECIMAL(12,2) COMMENT '支出',
  `notes` TEXT COMMENT '备注',
  `submitter_id` INT UNSIGNED COMMENT '提交人ID',
  `status` TINYINT DEFAULT 1 COMMENT '状态',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_hotel_date` (`hotel_id`, `report_date`),
  KEY `idx_date` (`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='日报表';



-- ----------------------------
-- 表结构 for monthly_tasks
-- ----------------------------
DROP TABLE IF EXISTS `monthly_tasks`;
CREATE TABLE `monthly_tasks` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `hotel_id` INT UNSIGNED NOT NULL COMMENT '酒店ID',
  `year` INT NOT NULL COMMENT '年份',
  `month` INT NOT NULL COMMENT '月份',
  `task_data` TEXT COMMENT '任务数据JSON',
  `revenue_target` DECIMAL(12,2) COMMENT '收入目标',
  `occupancy_target` DECIMAL(5,2) COMMENT '入住率目标',
  `guest_target` INT COMMENT '客人目标',
  `description` TEXT COMMENT '描述',
  `submitter_id` INT UNSIGNED COMMENT '提交人ID',
  `status` TINYINT DEFAULT 1 COMMENT '状态',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_hotel_year_month` (`hotel_id`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='月任务表';

INSERT INTO `monthly_tasks` VALUES (1, 1, 2026, 3, '{\"occupancy_rate_target\":85,\"ota_total_orders\":200,\"revenue_budget\":500000}', 0, 0, 0, NULL, 1, 1, '2026-02-24 09:20:41', '2026-02-24 09:20:41');
INSERT INTO `monthly_tasks` VALUES (2, 1, 2026, 2, '{\"occupancy_rate_target\":80,\"ota_total_orders\":185,\"revenue_budget\":400000,\"revenue_target\":22222}', 0, 0, 0, NULL, 1, 1, '2026-02-24 09:20:50', '2026-02-26 18:01:20.614976');


-- ----------------------------
-- 表结构 for online_daily_data
-- ----------------------------
DROP TABLE IF EXISTS `online_daily_data`;
CREATE TABLE `online_daily_data` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `hotel_id` VARCHAR(50) COMMENT '平台酒店ID',
  `hotel_name` VARCHAR(100) COMMENT '酒店名称',
  `data_date` DATE NOT NULL COMMENT '数据日期',
  `amount` DECIMAL(12,2) DEFAULT 0 COMMENT '营业额',
  `quantity` INT DEFAULT 0 COMMENT '离店间夜',
  `book_order_num` INT DEFAULT 0 COMMENT '预订数',
  `comment_score` DECIMAL(3,1) DEFAULT 0 COMMENT '点评分',
  `qunar_comment_score` DECIMAL(3,1) DEFAULT 0 COMMENT '去哪儿评分',
  `raw_data` TEXT COMMENT '原始数据JSON',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `system_hotel_id` INT UNSIGNED COMMENT '系统酒店ID',
  `data_value` DECIMAL(15,2) DEFAULT 0 COMMENT '月间夜数(美团)',
  `source` VARCHAR(50) DEFAULT 'ctrip' COMMENT '数据来源: ctrip/meituan',
  `dimension` VARCHAR(100) DEFAULT '' COMMENT '榜单类型',
  `data_type` VARCHAR(50) DEFAULT '' COMMENT '指标类型',
  KEY `idx_hotel_date` (`hotel_id`, `data_date`),
  KEY `idx_system_hotel` (`system_hotel_id`, `data_date`),
  KEY `idx_source` (`source`, `data_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='线上每日数据表';

INSERT INTO `online_daily_data` VALUES (1, '974065', '亚里酒店(南宁江南车站万达广场店)', '2026-02-25', 2821, 25, 17, 4.5, 4.9, '{\"hotelId\":974065,\"hotelName\":\"亚里酒店(南宁江南车站万达广场店)\",\"amount\":2821,\"quantity\":25,\"bookOrderNum\":17,\"commentScore\":4.5,\"totalDetailNum\":36,\"convertionRate\":2.78,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":5,\"quantityRank\":5,\"bookOrderNumRank\":6,\"commentScoreRank\":14,\"totalDetailNumRank\":2,\"convertionRateRank\":15,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (2, '988280', '红灯笼商务酒店（南宁大沙田地铁站店）', '2026-02-25', 867.59, 9, 5, 4.6, 4.9, '{\"hotelId\":988280,\"hotelName\":\"红灯笼商务酒店（南宁大沙田地铁站店）\",\"amount\":867.59,\"quantity\":9,\"bookOrderNum\":5,\"commentScore\":4.6,\"totalDetailNum\":6,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":15,\"quantityRank\":11,\"bookOrderNumRank\":16,\"commentScoreRank\":11,\"totalDetailNumRank\":18,\"convertionRateRank\":16,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (3, '1009378', '好时代酒店（南宁金象大道地铁站店）', '2026-02-25', 1877, 19, 20, 4.8, 4.9, '{\"hotelId\":1009378,\"hotelName\":\"好时代酒店（南宁金象大道地铁站店）\",\"amount\":1877,\"quantity\":19,\"bookOrderNum\":20,\"commentScore\":4.8,\"totalDetailNum\":18,\"convertionRate\":11.11,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":8,\"quantityRank\":8,\"bookOrderNumRank\":4,\"commentScoreRank\":1,\"totalDetailNumRank\":7,\"convertionRateRank\":8,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (4, '1098077', '南宁多一天精品酒店(江南客运站地铁站店）', '2026-02-25', 0, 0, 0, 4.3, 4.9, '{\"hotelId\":1098077,\"hotelName\":\"南宁多一天精品酒店(江南客运站地铁站店）\",\"amount\":0,\"quantity\":0,\"bookOrderNum\":0,\"commentScore\":4.3,\"totalDetailNum\":0,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":21,\"quantityRank\":21,\"bookOrderNumRank\":21,\"commentScoreRank\":20,\"totalDetailNumRank\":22,\"convertionRateRank\":16,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (5, '1221618', '7天连锁酒店(南宁江南客运站地铁口店)', '2026-02-25', 3424.15, 41, 27, 4.5, 4.3, '{\"hotelId\":1221618,\"hotelName\":\"7天连锁酒店(南宁江南客运站地铁口店)\",\"amount\":3424.15,\"quantity\":41,\"bookOrderNum\":27,\"commentScore\":4.5,\"totalDetailNum\":57,\"convertionRate\":8.77,\"qunarCommentScore\":4.3,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":4,\"quantityRank\":1,\"bookOrderNumRank\":1,\"commentScoreRank\":13,\"totalDetailNumRank\":1,\"convertionRateRank\":9,\"qunarCommentScoreRank\":23,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (6, '1376748', '逸喆酒店(南宁石子塘地铁站店)', '2026-02-25', 2779, 22, 12, 4.7, 4.9, '{\"hotelId\":1376748,\"hotelName\":\"逸喆酒店(南宁石子塘地铁站店)\",\"amount\":2779,\"quantity\":22,\"bookOrderNum\":12,\"commentScore\":4.7,\"totalDetailNum\":26,\"convertionRate\":7.69,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":6,\"quantityRank\":6,\"bookOrderNumRank\":8,\"commentScoreRank\":7,\"totalDetailNumRank\":6,\"convertionRateRank\":10,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (7, '1407551', '好友酒店（南宁金象大道地铁站店）', '2026-02-25', 434.05, 4, 6, 4.7, 4.9, '{\"hotelId\":1407551,\"hotelName\":\"好友酒店（南宁金象大道地铁站店）\",\"amount\":434.05,\"quantity\":4,\"bookOrderNum\":6,\"commentScore\":4.7,\"totalDetailNum\":11,\"convertionRate\":18.18,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":20,\"quantityRank\":19,\"bookOrderNumRank\":14,\"commentScoreRank\":7,\"totalDetailNumRank\":14,\"convertionRateRank\":2,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (8, '1413989', '雅斯特酒店（南宁五象新区金象大道地铁站店）', '2026-02-25', 0, 0, 0, 4.4, 4.9, '{\"hotelId\":1413989,\"hotelName\":\"雅斯特酒店（南宁五象新区金象大道地铁站店）\",\"amount\":0,\"quantity\":0,\"bookOrderNum\":0,\"commentScore\":4.4,\"totalDetailNum\":0,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":21,\"quantityRank\":21,\"bookOrderNumRank\":21,\"commentScoreRank\":17,\"totalDetailNumRank\":22,\"convertionRateRank\":16,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (9, '1630499', '枫景澜庭酒店(南宁大沙田建设路地铁站店)', '2026-02-25', 847, 9, 7, 4.5, 4.8, '{\"hotelId\":1630499,\"hotelName\":\"枫景澜庭酒店(南宁大沙田建设路地铁站店)\",\"amount\":847,\"quantity\":9,\"bookOrderNum\":7,\"commentScore\":4.5,\"totalDetailNum\":18,\"convertionRate\":5.56,\"qunarCommentScore\":4.8,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":16,\"quantityRank\":11,\"bookOrderNumRank\":12,\"commentScoreRank\":14,\"totalDetailNumRank\":7,\"convertionRateRank\":14,\"qunarCommentScoreRank\":17,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (10, '1685042', '我的酒店', '2026-02-25', 3853.8, 32, 26, 4.7, 4.9, '{\"hotelId\":1685042,\"hotelName\":\"我的酒店\",\"amount\":3853.8,\"quantity\":32,\"bookOrderNum\":26,\"commentScore\":4.7,\"totalDetailNum\":17,\"convertionRate\":11.76,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":3,\"quantityRank\":3,\"bookOrderNumRank\":2,\"commentScoreRank\":3,\"totalDetailNumRank\":9,\"convertionRateRank\":7,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (11, '1721937', '城市便捷酒店(南宁大沙田地铁站店)', '2026-02-25', 1271, 7, 4, 4.7, 4.9, '{\"hotelId\":1721937,\"hotelName\":\"城市便捷酒店(南宁大沙田地铁站店)\",\"amount\":1271,\"quantity\":7,\"bookOrderNum\":4,\"commentScore\":4.7,\"totalDetailNum\":14,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":11,\"quantityRank\":17,\"bookOrderNumRank\":17,\"commentScoreRank\":3,\"totalDetailNumRank\":12,\"convertionRateRank\":16,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (12, '2888038', '星月酒店（江南客运站大沙田地铁站店）', '2026-02-25', 1431, 12, 3, 4.4, 4.8, '{\"hotelId\":2888038,\"hotelName\":\"星月酒店（江南客运站大沙田地铁站店）\",\"amount\":1431,\"quantity\":12,\"bookOrderNum\":3,\"commentScore\":4.4,\"totalDetailNum\":10,\"convertionRate\":0,\"qunarCommentScore\":4.8,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":10,\"quantityRank\":10,\"bookOrderNumRank\":18,\"commentScoreRank\":18,\"totalDetailNumRank\":15,\"convertionRateRank\":16,\"qunarCommentScoreRank\":17,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (13, '3054798', '贝壳酒店（南宁金象大道地铁站店）', '2026-02-25', 0, 0, 0, 4, 4.8, '{\"hotelId\":3054798,\"hotelName\":\"贝壳酒店（南宁金象大道地铁站店）\",\"amount\":0,\"quantity\":0,\"bookOrderNum\":0,\"commentScore\":4,\"totalDetailNum\":4,\"convertionRate\":0,\"qunarCommentScore\":4.8,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":21,\"quantityRank\":21,\"bookOrderNumRank\":21,\"commentScoreRank\":22,\"totalDetailNumRank\":20,\"convertionRateRank\":16,\"qunarCommentScoreRank\":17,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (14, '4826364', '南宁二三角酒店（大沙田金象大道地铁站店）', '2026-02-25', 2276.56, 22, 13, 4.4, 4.9, '{\"hotelId\":4826364,\"hotelName\":\"南宁二三角酒店（大沙田金象大道地铁站店）\",\"amount\":2276.56,\"quantity\":22,\"bookOrderNum\":13,\"commentScore\":4.4,\"totalDetailNum\":5,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":7,\"quantityRank\":6,\"bookOrderNumRank\":7,\"commentScoreRank\":19,\"totalDetailNumRank\":19,\"convertionRateRank\":16,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (15, '5079562', '柏怡酒店(南宁大沙田地铁站店)', '2026-02-25', 831, 8, 6, 4.6, 4.9, '{\"hotelId\":5079562,\"hotelName\":\"柏怡酒店(南宁大沙田地铁站店)\",\"amount\":831,\"quantity\":8,\"bookOrderNum\":6,\"commentScore\":4.6,\"totalDetailNum\":28,\"convertionRate\":7.14,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":17,\"quantityRank\":16,\"bookOrderNumRank\":14,\"commentScoreRank\":10,\"totalDetailNumRank\":4,\"convertionRateRank\":12,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (16, '6783466', '聚满楼酒店（南宁五象新区金象大道地铁站店）', '2026-02-25', 1539, 15, 11, 4.1, 4.6, '{\"hotelId\":6783466,\"hotelName\":\"聚满楼酒店（南宁五象新区金象大道地铁站店）\",\"amount\":1539,\"quantity\":15,\"bookOrderNum\":11,\"commentScore\":4.1,\"totalDetailNum\":8,\"convertionRate\":12.5,\"qunarCommentScore\":4.6,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":9,\"quantityRank\":9,\"bookOrderNumRank\":9,\"commentScoreRank\":21,\"totalDetailNumRank\":17,\"convertionRateRank\":6,\"qunarCommentScoreRank\":21,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (17, '15899563', '维也纳智好酒店(体育中心大沙田地铁站店)', '2026-02-25', 4833, 26, 19, 4.7, 4.9, '{\"hotelId\":15899563,\"hotelName\":\"维也纳智好酒店(体育中心大沙田地铁站店)\",\"amount\":4833,\"quantity\":26,\"bookOrderNum\":19,\"commentScore\":4.7,\"totalDetailNum\":33,\"convertionRate\":18.18,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":2,\"quantityRank\":4,\"bookOrderNumRank\":5,\"commentScoreRank\":7,\"totalDetailNumRank\":3,\"convertionRateRank\":2,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (18, '28067759', '雅斯特酒店(南宁五象石子塘地铁站店)', '2026-02-25', 675.93, 4, 3, 4.4, 4.8, '{\"hotelId\":28067759,\"hotelName\":\"雅斯特酒店(南宁五象石子塘地铁站店)\",\"amount\":675.9300000000001,\"quantity\":4,\"bookOrderNum\":3,\"commentScore\":4.4,\"totalDetailNum\":13,\"convertionRate\":7.69,\"qunarCommentScore\":4.8,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":19,\"quantityRank\":19,\"bookOrderNumRank\":18,\"commentScoreRank\":16,\"totalDetailNumRank\":13,\"convertionRateRank\":10,\"qunarCommentScoreRank\":17,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (19, '40795187', '傲金酒店(南宁大沙田地铁站店)', '2026-02-25', 783.76, 9, 7, 3.9, 4.9, '{\"hotelId\":40795187,\"hotelName\":\"傲金酒店(南宁大沙田地铁站店)\",\"amount\":783.76,\"quantity\":9,\"bookOrderNum\":7,\"commentScore\":3.9,\"totalDetailNum\":2,\"convertionRate\":50,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":18,\"quantityRank\":11,\"bookOrderNumRank\":12,\"commentScoreRank\":23,\"totalDetailNumRank\":21,\"convertionRateRank\":1,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (20, '50468388', '逸喆酒店(南宁大沙田建设路地铁站店)', '2026-02-25', 1007.28, 9, 8, 4.7, 4.9, '{\"hotelId\":50468388,\"hotelName\":\"逸喆酒店(南宁大沙田建设路地铁站店)\",\"amount\":1007.28,\"quantity\":9,\"bookOrderNum\":8,\"commentScore\":4.7,\"totalDetailNum\":17,\"convertionRate\":5.88,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":14,\"quantityRank\":11,\"bookOrderNumRank\":11,\"commentScoreRank\":5,\"totalDetailNumRank\":9,\"convertionRateRank\":13,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (21, '73665061', '璟曼酒店（南宁江南客运站大沙田地铁站店)', '2026-02-25', 5298.06, 37, 22, 4.7, 4.9, '{\"hotelId\":73665061,\"hotelName\":\"璟曼酒店（南宁江南客运站大沙田地铁站店)\",\"amount\":5298.0599999999995,\"quantity\":37,\"bookOrderNum\":22,\"commentScore\":4.7,\"totalDetailNum\":28,\"convertionRate\":14.29,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":1,\"quantityRank\":2,\"bookOrderNumRank\":3,\"commentScoreRank\":5,\"totalDetailNumRank\":4,\"convertionRateRank\":5,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (22, '76423370', '维也纳酒店(南宁江南客运站店)', '2026-02-25', 1143, 5, 9, 4.5, 4.6, '{\"hotelId\":76423370,\"hotelName\":\"维也纳酒店(南宁江南客运站店)\",\"amount\":1143,\"quantity\":5,\"bookOrderNum\":9,\"commentScore\":4.5,\"totalDetailNum\":17,\"convertionRate\":17.65,\"qunarCommentScore\":4.6,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":12,\"quantityRank\":18,\"bookOrderNumRank\":10,\"commentScoreRank\":12,\"totalDetailNumRank\":9,\"convertionRateRank\":4,\"qunarCommentScoreRank\":21,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (23, '105011011', '南宁沙田见酒店（大沙田建设路地铁站店）', '2026-02-25', 1107.48, 9, 3, 4.8, 4.9, '{\"hotelId\":105011011,\"hotelName\":\"南宁沙田见酒店（大沙田建设路地铁站店）\",\"amount\":1107.48,\"quantity\":9,\"bookOrderNum\":3,\"commentScore\":4.8,\"totalDetailNum\":10,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":13,\"quantityRank\":11,\"bookOrderNumRank\":18,\"commentScoreRank\":2,\"totalDetailNumRank\":15,\"convertionRateRank\":16,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (24, '974065', '亚里酒店(南宁江南车站万达广场店)', '2026-02-26', 1556.92, 16, 19, 4.5, 4.9, '{\"hotelId\":974065,\"hotelName\":\"亚里酒店(南宁江南车站万达广场店)\",\"amount\":1556.92,\"quantity\":16,\"bookOrderNum\":19,\"commentScore\":4.5,\"totalDetailNum\":38,\"convertionRate\":7.89,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":18,\"qunarDetailCR\":16.67,\"amountRank\":8,\"quantityRank\":5,\"bookOrderNumRank\":4,\"commentScoreRank\":14,\"totalDetailNumRank\":3,\"convertionRateRank\":11,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":7,\"qunarDetailCRRank\":7}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (25, '988280', '红灯笼商务酒店（南宁大沙田地铁站店）', '2026-02-26', 200.46, 2, 5, 4.6, 4.9, '{\"hotelId\":988280,\"hotelName\":\"红灯笼商务酒店（南宁大沙田地铁站店）\",\"amount\":200.45999999999998,\"quantity\":2,\"bookOrderNum\":5,\"commentScore\":4.6,\"totalDetailNum\":4,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":8,\"qunarDetailCR\":25,\"amountRank\":21,\"quantityRank\":21,\"bookOrderNumRank\":15,\"commentScoreRank\":11,\"totalDetailNumRank\":19,\"convertionRateRank\":14,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":13,\"qunarDetailCRRank\":4}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (26, '1009378', '好时代酒店（南宁金象大道地铁站店）', '2026-02-26', 1588.01, 16, 10, 4.8, 4.9, '{\"hotelId\":1009378,\"hotelName\":\"好时代酒店（南宁金象大道地铁站店）\",\"amount\":1588.01,\"quantity\":16,\"bookOrderNum\":10,\"commentScore\":4.8,\"totalDetailNum\":18,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":11,\"qunarDetailCR\":9.09,\"amountRank\":7,\"quantityRank\":5,\"bookOrderNumRank\":10,\"commentScoreRank\":1,\"totalDetailNumRank\":8,\"convertionRateRank\":14,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":10,\"qunarDetailCRRank\":9}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (27, '1098077', '南宁多一天精品酒店(江南客运站地铁站店）', '2026-02-26', 0, 0, 0, 4.3, 4.9, '{\"hotelId\":1098077,\"hotelName\":\"南宁多一天精品酒店(江南客运站地铁站店）\",\"amount\":0,\"quantity\":0,\"bookOrderNum\":0,\"commentScore\":4.3,\"totalDetailNum\":0,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":1,\"qunarDetailCR\":0,\"amountRank\":22,\"quantityRank\":22,\"bookOrderNumRank\":21,\"commentScoreRank\":20,\"totalDetailNumRank\":22,\"convertionRateRank\":14,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":22,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (28, '1221618', '7天连锁酒店(南宁江南客运站地铁口店)', '2026-02-26', 2457.22, 28, 27, 4.5, 4.3, '{\"hotelId\":1221618,\"hotelName\":\"7天连锁酒店(南宁江南客运站地铁口店)\",\"amount\":2457.2200000000003,\"quantity\":28,\"bookOrderNum\":27,\"commentScore\":4.5,\"totalDetailNum\":54,\"convertionRate\":11.11,\"qunarCommentScore\":4.3,\"qunarDetailVisitors\":28,\"qunarDetailCR\":7.14,\"amountRank\":4,\"quantityRank\":2,\"bookOrderNumRank\":2,\"commentScoreRank\":13,\"totalDetailNumRank\":2,\"convertionRateRank\":6,\"qunarCommentScoreRank\":23,\"qunarDetailVisitorsRank\":3,\"qunarDetailCRRank\":12}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (29, '1376748', '逸喆酒店(南宁石子塘地铁站店)', '2026-02-26', 896, 9, 15, 4.7, 4.9, '{\"hotelId\":1376748,\"hotelName\":\"逸喆酒店(南宁石子塘地铁站店)\",\"amount\":896,\"quantity\":9,\"bookOrderNum\":15,\"commentScore\":4.7,\"totalDetailNum\":37,\"convertionRate\":10.81,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":29,\"qunarDetailCR\":17.24,\"amountRank\":12,\"quantityRank\":11,\"bookOrderNumRank\":8,\"commentScoreRank\":7,\"totalDetailNumRank\":4,\"convertionRateRank\":8,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":2,\"qunarDetailCRRank\":6}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (30, '1407551', '好友酒店（南宁金象大道地铁站店）', '2026-02-26', 1071.49, 11, 6, 4.7, 4.9, '{\"hotelId\":1407551,\"hotelName\":\"好友酒店（南宁金象大道地铁站店）\",\"amount\":1071.49,\"quantity\":11,\"bookOrderNum\":6,\"commentScore\":4.7,\"totalDetailNum\":11,\"convertionRate\":18.18,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":4,\"qunarDetailCR\":0,\"amountRank\":11,\"quantityRank\":8,\"bookOrderNumRank\":14,\"commentScoreRank\":7,\"totalDetailNumRank\":11,\"convertionRateRank\":4,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":18,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (31, '1413989', '雅斯特酒店（南宁五象新区金象大道地铁站店）', '2026-02-26', 0, 0, 0, 4.4, 4.9, '{\"hotelId\":1413989,\"hotelName\":\"雅斯特酒店（南宁五象新区金象大道地铁站店）\",\"amount\":0,\"quantity\":0,\"bookOrderNum\":0,\"commentScore\":4.4,\"totalDetailNum\":0,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":0,\"qunarDetailCR\":0,\"amountRank\":22,\"quantityRank\":22,\"bookOrderNumRank\":21,\"commentScoreRank\":17,\"totalDetailNumRank\":22,\"convertionRateRank\":14,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":23,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (32, '1630499', '枫景澜庭酒店(南宁大沙田建设路地铁站店)', '2026-02-26', 849, 9, 10, 4.5, 4.8, '{\"hotelId\":1630499,\"hotelName\":\"枫景澜庭酒店(南宁大沙田建设路地铁站店)\",\"amount\":849,\"quantity\":9,\"bookOrderNum\":10,\"commentScore\":4.5,\"totalDetailNum\":9,\"convertionRate\":0,\"qunarCommentScore\":4.8,\"qunarDetailVisitors\":22,\"qunarDetailCR\":9.09,\"amountRank\":13,\"quantityRank\":11,\"bookOrderNumRank\":10,\"commentScoreRank\":14,\"totalDetailNumRank\":16,\"convertionRateRank\":14,\"qunarCommentScoreRank\":17,\"qunarDetailVisitorsRank\":5,\"qunarDetailCRRank\":9}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (33, '1685042', '我的酒店', '2026-02-26', 3029.16, 24, 16, 4.7, 4.9, '{\"hotelId\":1685042,\"hotelName\":\"我的酒店\",\"amount\":3029.16,\"quantity\":24,\"bookOrderNum\":16,\"commentScore\":4.7,\"totalDetailNum\":21,\"convertionRate\":4.76,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":10,\"qunarDetailCR\":30,\"amountRank\":3,\"quantityRank\":3,\"bookOrderNumRank\":6,\"commentScoreRank\":4,\"totalDetailNumRank\":7,\"convertionRateRank\":13,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":12,\"qunarDetailCRRank\":3}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (34, '1721937', '城市便捷酒店(南宁大沙田地铁站店)', '2026-02-26', 487, 3, 2, 4.7, 4.9, '{\"hotelId\":1721937,\"hotelName\":\"城市便捷酒店(南宁大沙田地铁站店)\",\"amount\":487,\"quantity\":3,\"bookOrderNum\":2,\"commentScore\":4.7,\"totalDetailNum\":16,\"convertionRate\":6.25,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":14,\"qunarDetailCR\":0,\"amountRank\":17,\"quantityRank\":18,\"bookOrderNumRank\":19,\"commentScoreRank\":3,\"totalDetailNumRank\":9,\"convertionRateRank\":12,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":8,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (35, '2888038', '星月酒店（江南客运站大沙田地铁站店）', '2026-02-26', 1096, 8, 13, 4.4, 4.8, '{\"hotelId\":2888038,\"hotelName\":\"星月酒店（江南客运站大沙田地铁站店）\",\"amount\":1096,\"quantity\":8,\"bookOrderNum\":13,\"commentScore\":4.4,\"totalDetailNum\":4,\"convertionRate\":0,\"qunarCommentScore\":4.8,\"qunarDetailVisitors\":103,\"qunarDetailCR\":4.85,\"amountRank\":10,\"quantityRank\":13,\"bookOrderNumRank\":9,\"commentScoreRank\":18,\"totalDetailNumRank\":19,\"convertionRateRank\":14,\"qunarCommentScoreRank\":17,\"qunarDetailVisitorsRank\":1,\"qunarDetailCRRank\":13}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (36, '3054798', '贝壳酒店（南宁金象大道地铁站店）', '2026-02-26', 289, 3, 0, 4, 4.8, '{\"hotelId\":3054798,\"hotelName\":\"贝壳酒店（南宁金象大道地铁站店）\",\"amount\":289,\"quantity\":3,\"bookOrderNum\":0,\"commentScore\":4,\"totalDetailNum\":6,\"convertionRate\":0,\"qunarCommentScore\":4.8,\"qunarDetailVisitors\":5,\"qunarDetailCR\":0,\"amountRank\":19,\"quantityRank\":18,\"bookOrderNumRank\":21,\"commentScoreRank\":22,\"totalDetailNumRank\":17,\"convertionRateRank\":14,\"qunarCommentScoreRank\":17,\"qunarDetailVisitorsRank\":16,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (37, '4826364', '南宁二三角酒店（大沙田金象大道地铁站店）', '2026-02-26', 1676, 15, 17, 4.4, 4.9, '{\"hotelId\":4826364,\"hotelName\":\"南宁二三角酒店（大沙田金象大道地铁站店）\",\"amount\":1676,\"quantity\":15,\"bookOrderNum\":17,\"commentScore\":4.4,\"totalDetailNum\":5,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":4,\"qunarDetailCR\":0,\"amountRank\":6,\"quantityRank\":7,\"bookOrderNumRank\":5,\"commentScoreRank\":19,\"totalDetailNumRank\":18,\"convertionRateRank\":14,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":18,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (38, '5079562', '柏怡酒店(南宁大沙田地铁站店)', '2026-02-26', 617, 6, 9, 4.6, 4.9, '{\"hotelId\":5079562,\"hotelName\":\"柏怡酒店(南宁大沙田地铁站店)\",\"amount\":617,\"quantity\":6,\"bookOrderNum\":9,\"commentScore\":4.6,\"totalDetailNum\":31,\"convertionRate\":9.68,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":23,\"qunarDetailCR\":8.7,\"amountRank\":16,\"quantityRank\":14,\"bookOrderNumRank\":12,\"commentScoreRank\":10,\"totalDetailNumRank\":5,\"convertionRateRank\":9,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":4,\"qunarDetailCRRank\":11}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (39, '6783466', '聚满楼酒店（南宁五象新区金象大道地铁站店）', '2026-02-26', 1190, 11, 16, 4.1, 4.6, '{\"hotelId\":6783466,\"hotelName\":\"聚满楼酒店（南宁五象新区金象大道地铁站店）\",\"amount\":1190,\"quantity\":11,\"bookOrderNum\":16,\"commentScore\":4.1,\"totalDetailNum\":10,\"convertionRate\":30,\"qunarCommentScore\":4.6,\"qunarDetailVisitors\":5,\"qunarDetailCR\":60,\"amountRank\":9,\"quantityRank\":8,\"bookOrderNumRank\":6,\"commentScoreRank\":21,\"totalDetailNumRank\":15,\"convertionRateRank\":3,\"qunarCommentScoreRank\":21,\"qunarDetailVisitorsRank\":16,\"qunarDetailCRRank\":1}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (40, '15899563', '维也纳智好酒店(体育中心大沙田地铁站店)', '2026-02-26', 4537.47, 24, 21, 4.7, 4.9, '{\"hotelId\":15899563,\"hotelName\":\"维也纳智好酒店(体育中心大沙田地铁站店)\",\"amount\":4537.47,\"quantity\":24,\"bookOrderNum\":21,\"commentScore\":4.7,\"totalDetailNum\":55,\"convertionRate\":12.73,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":14,\"qunarDetailCR\":21.43,\"amountRank\":2,\"quantityRank\":3,\"bookOrderNumRank\":3,\"commentScoreRank\":7,\"totalDetailNumRank\":1,\"convertionRateRank\":5,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":8,\"qunarDetailCRRank\":5}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (41, '28067759', '雅斯特酒店(南宁五象石子塘地铁站店)', '2026-02-26', 256, 3, 5, 4.4, 4.8, '{\"hotelId\":28067759,\"hotelName\":\"雅斯特酒店(南宁五象石子塘地铁站店)\",\"amount\":256,\"quantity\":3,\"bookOrderNum\":5,\"commentScore\":4.4,\"totalDetailNum\":11,\"convertionRate\":0,\"qunarCommentScore\":4.8,\"qunarDetailVisitors\":8,\"qunarDetailCR\":0,\"amountRank\":20,\"quantityRank\":18,\"bookOrderNumRank\":15,\"commentScoreRank\":16,\"totalDetailNumRank\":11,\"convertionRateRank\":14,\"qunarCommentScoreRank\":17,\"qunarDetailVisitorsRank\":13,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (42, '40795187', '傲金酒店(南宁大沙田地铁站店)', '2026-02-26', 416, 5, 2, 3.9, 4.9, '{\"hotelId\":40795187,\"hotelName\":\"傲金酒店(南宁大沙田地铁站店)\",\"amount\":416,\"quantity\":5,\"bookOrderNum\":2,\"commentScore\":3.9,\"totalDetailNum\":3,\"convertionRate\":33.33,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":4,\"qunarDetailCR\":0,\"amountRank\":18,\"quantityRank\":16,\"bookOrderNumRank\":19,\"commentScoreRank\":23,\"totalDetailNumRank\":21,\"convertionRateRank\":2,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":18,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (43, '50468388', '逸喆酒店(南宁大沙田建设路地铁站店)', '2026-02-26', 806, 6, 7, 4.7, 4.9, '{\"hotelId\":50468388,\"hotelName\":\"逸喆酒店(南宁大沙田建设路地铁站店)\",\"amount\":806,\"quantity\":6,\"bookOrderNum\":7,\"commentScore\":4.7,\"totalDetailNum\":13,\"convertionRate\":0,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":6,\"qunarDetailCR\":16.67,\"amountRank\":14,\"quantityRank\":14,\"bookOrderNumRank\":13,\"commentScoreRank\":5,\"totalDetailNumRank\":10,\"convertionRateRank\":14,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":15,\"qunarDetailCRRank\":7}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (44, '73665061', '璟曼酒店（南宁江南客运站大沙田地铁站店)', '2026-02-26', 5031, 35, 34, 4.7, 4.9, '{\"hotelId\":73665061,\"hotelName\":\"璟曼酒店（南宁江南客运站大沙田地铁站店)\",\"amount\":5031,\"quantity\":35,\"bookOrderNum\":34,\"commentScore\":4.7,\"totalDetailNum\":27,\"convertionRate\":11.11,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":19,\"qunarDetailCR\":42.11,\"amountRank\":1,\"quantityRank\":1,\"bookOrderNumRank\":1,\"commentScoreRank\":5,\"totalDetailNumRank\":6,\"convertionRateRank\":6,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":6,\"qunarDetailCRRank\":2}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (45, '76423370', '维也纳酒店(南宁江南客运站店)', '2026-02-26', 1993, 10, 5, 4.5, 4.6, '{\"hotelId\":76423370,\"hotelName\":\"维也纳酒店(南宁江南客运站店)\",\"amount\":1993,\"quantity\":10,\"bookOrderNum\":5,\"commentScore\":4.5,\"totalDetailNum\":11,\"convertionRate\":9.09,\"qunarCommentScore\":4.6,\"qunarDetailVisitors\":11,\"qunarDetailCR\":0,\"amountRank\":5,\"quantityRank\":10,\"bookOrderNumRank\":15,\"commentScoreRank\":12,\"totalDetailNumRank\":11,\"convertionRateRank\":10,\"qunarCommentScoreRank\":21,\"qunarDetailVisitorsRank\":10,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (46, '105011011', '南宁沙田见酒店（大沙田建设路地铁站店）', '2026-02-26', 717, 5, 5, 4.8, 4.9, '{\"hotelId\":105011011,\"hotelName\":\"南宁沙田见酒店（大沙田建设路地铁站店）\",\"amount\":717,\"quantity\":5,\"bookOrderNum\":5,\"commentScore\":4.8,\"totalDetailNum\":11,\"convertionRate\":45.45,\"qunarCommentScore\":4.9,\"qunarDetailVisitors\":3,\"qunarDetailCR\":0,\"amountRank\":15,\"quantityRank\":16,\"bookOrderNumRank\":15,\"commentScoreRank\":2,\"totalDetailNumRank\":11,\"convertionRateRank\":1,\"qunarCommentScoreRank\":1,\"qunarDetailVisitorsRank\":21,\"qunarDetailCRRank\":14}', NULL, NULL, NULL, 0, 'ctrip', '', '');
INSERT INTO `online_daily_data` VALUES (47, '68471', '亚里酒店（江南万达广场店）', '2026-02-26', 0, 0, 0, 0, 0, '{\"rank\":1,\"poiId\":68471,\"poiName\":\"亚里酒店（江南万达广场店）\",\"dataValue\":80,\"percent\":100,\"activityList\":[\"马上来财（人群特惠）\",\" 首住折扣\",\" 节日智能生财宝\"],\"vipTag\":true,\"_dimName\":\"入住间夜榜\",\"_aiMetricName\":\"P_RZ_NIGHT_COUNT\"}', NULL, NULL, NULL, 80, 'meituan', '入住间夜榜', 'P_RZ_NIGHT_COUNT');
INSERT INTO `online_daily_data` VALUES (48, '68469', '枫景澜庭酒店（南宁大沙田建设路地铁站店）', '2026-02-26', 0, 0, 0, 0, 0, '{\"rank\":2,\"poiId\":68469,\"poiName\":\"枫景澜庭酒店（南宁大沙田建设路地铁站店）\",\"dataValue\":49,\"percent\":61.25,\"activityList\":[\"首住折扣\",\" 限时特惠\",\" 连住特惠\"],\"vipTag\":true,\"_dimName\":\"入住间夜榜\",\"_aiMetricName\":\"P_RZ_NIGHT_COUNT\"}', NULL, NULL, NULL, 49, 'meituan', '入住间夜榜', 'P_RZ_NIGHT_COUNT');
INSERT INTO `online_daily_data` VALUES (49, '908106640', '贝壳酒店（金象大道地铁站店）', '2026-02-26', 0, 0, 0, 0, 0, '{\"rank\":3,\"poiId\":908106640,\"poiName\":\"贝壳酒店（金象大道地铁站店）\",\"dataValue\":41,\"percent\":51.25,\"activityList\":[\"马上来财（人群特惠）\",\" 首住折扣\",\" 连住特惠\"],\"vipTag\":true,\"_dimName\":\"入住间夜榜\",\"_aiMetricName\":\"P_RZ_NIGHT_COUNT\"}', NULL, NULL, NULL, 41, 'meituan', '入住间夜榜', 'P_RZ_NIGHT_COUNT');
INSERT INTO `online_daily_data` VALUES (50, '42695285', '城市便捷酒店（南宁大沙田地铁站店）', '2026-02-26', 0, 0, 0, 0, 0, '{\"rank\":4,\"poiId\":42695285,\"poiName\":\"城市便捷酒店（南宁大沙田地铁站店）\",\"dataValue\":32,\"percent\":40,\"activityList\":[\"首住折扣\",\" 连住特惠\",\" 深夜安睡价\"],\"vipTag\":true,\"_dimName\":\"入住间夜榜\",\"_aiMetricName\":\"P_RZ_NIGHT_COUNT\"}', NULL, NULL, NULL, 32, 'meituan', '入住间夜榜', 'P_RZ_NIGHT_COUNT');


-- ----------------------------
-- 表结构 for operation_logs
-- ----------------------------
DROP TABLE IF EXISTS `operation_logs`;
CREATE TABLE `operation_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED COMMENT '用户ID',
  `module` VARCHAR(50) COMMENT '模块',
  `action` VARCHAR(50) COMMENT '操作',
  `description` VARCHAR(500) COMMENT '描述',
  `ip` VARCHAR(50) COMMENT 'IP地址',
  `user_agent` VARCHAR(255) COMMENT 'User Agent',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `hotel_id` INT UNSIGNED COMMENT '酒店ID',
  `error_info` TEXT COMMENT '错误信息',
  `extra_data` TEXT COMMENT '额外数据JSON',
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志表';

INSERT INTO `operation_logs` VALUES (342, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:55:48.658798', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (341, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:54:52.650667', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (340, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:42:46.829208', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (339, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:42:27.567062', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (338, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:42:25.545559', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (337, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:42:04.139749', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (336, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:39:32.101316', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (335, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:38:17.372190', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (334, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:35:25.090685', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (333, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:26:51.702104', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (332, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:25:54.694759', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (331, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:24:16.812055', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (330, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:15:44.689097', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (329, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:14:10.088506', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (328, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:13:56.120621', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (327, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:13:34.617315', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (326, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:12:30.843428', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (325, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:12:28.173180', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (324, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:06:52.074988', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (323, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:05:45.483416', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (322, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:05:45.390432', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (321, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:05:40.077409', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (320, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:04:37.204438', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (319, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:03:37.736163', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (318, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:03:37.616555', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (317, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:02:35.004182', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (316, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:02:11.368498', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (315, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 12:01:06.348174', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (314, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:00:20.715177', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (313, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 12:00:20.614448', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (312, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:59:36.644749', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (311, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:58:28.658738', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (310, 1, 'online_data', 'fetch_meituan', '获取美团线上数据', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:57:24.705305', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (309, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:57:24.601222', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (308, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:56:27.993134', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (307, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:54:51.904683', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (306, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:54:22.120069', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (305, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:54:01.676002', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (304, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:53:20.565182', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (303, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:49:37.911837', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (302, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:48:48.073931', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (301, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:47:51.164214', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (300, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:46:44.101965', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (299, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:46:00.859225', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (298, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:45:15.965311', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (297, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:44:39.090255', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (296, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:43:49.103394', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (295, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:37:01.654435', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (294, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:35:49.167051', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (293, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:19:47.415926', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (292, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:19:41.729623', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (291, 1, 'online_data', 'fetch_ctrip', '获取携程线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 11:10:57.447702', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (290, 1, 'online_data', 'fetch_ctrip', '获取携程线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 11:10:21.823438', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (289, 1, 'online_data', 'fetch_ctrip', '获取携程线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 11:09:49.194121', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (288, 1, 'online_data', 'fetch_ctrip', '获取携程线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 11:09:26.864814', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (287, 1, 'online_data', 'fetch_ctrip', '获取携程线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 11:08:48.403560', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (286, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:06:22.497242', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (285, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:06:07.268824', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (284, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:05:20.170360', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (283, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:04:48.020479', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (282, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:04:30.993393', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (281, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:04:23.124215', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (280, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:04:07.031841', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (279, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 11:03:54.187871', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (278, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 10:57:22.325351', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (277, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 10:54:36.909359', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (276, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 10:43:53.085538', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (275, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:51:49.058506', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (274, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:51:01.911145', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (273, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:50:39.065269', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (272, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:46:30.240144', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (271, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:43:14.557774', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (270, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:43:03.037488', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (269, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:42:55.309502', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (268, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:42:28.461364', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (267, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:42:23.140774', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (266, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:41:19.502199', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (265, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 09:36:11.237166', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (264, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:22:01.795755', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (263, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:21:57.854138', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (262, 1, 'online_data', 'fetch_ctrip', '获取携程线上数据', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 09:10:33.381300', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (261, 1, 'online_data', 'set_schedule', '设置自动获取时间: 09:10 (门店ID: )', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 09:09:33.142067', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (260, 1, 'online_data', 'save_cookies', '保存Cookies配置: cookie', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 09:09:17.180562', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (259, 1, 'online_data', 'set_schedule', '设置自动获取时间: 09:04 (门店ID: )', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-27 09:03:28.411301', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (258, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-27 09:02:50.171133', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (257, 1, 'hotel', 'update', '更新酒店: 南宁市银田酒店 (状态变更: 启用, 影响2个用户)', '127.0.0.1', 'curl/8.5.0', '2026-02-26 18:06:49.967572', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (256, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-26 18:06:49.926867', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (255, 2, 'auth', 'login', '用户登录: manager1', '127.0.0.1', 'curl/8.5.0', '2026-02-26 18:06:34.978679', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (254, 1, 'hotel', 'update', '更新酒店: 南宁市银田酒店 (状态变更: 禁用, 影响2个用户)', '127.0.0.1', 'curl/8.5.0', '2026-02-26 18:06:34.893564', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (253, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-26 18:06:34.860557', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (252, 2, 'monthly_task', 'update', '更新月任务: 2026年2月', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-26 18:01:20.618568', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (251, 1, 'hotel', 'update', '更新酒店: 南宁市银田酒店 (状态变更: 启用, 影响2个用户)', '127.0.0.1', 'curl/8.5.0', '2026-02-26 17:58:38.406890', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (250, 2, 'auth', 'login', '用户登录: manager1', '127.0.0.1', 'curl/8.5.0', '2026-02-26 17:58:23.358523', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (249, 2, 'auth', 'login', '用户登录: manager1', '127.0.0.1', 'curl/8.5.0', '2026-02-26 17:57:24.233375', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (248, 1, 'user', 'update', '更新用户: manager1', '127.0.0.1', 'curl/8.5.0', '2026-02-26 17:57:21.138326', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (247, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-26 17:57:11.895993', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (246, 1, 'hotel', 'update', '更新酒店: 南宁市银田酒店 (状态变更: 禁用, 影响2个用户)', '127.0.0.1', 'curl/8.5.0', '2026-02-26 17:56:47.179241', 1, NULL, NULL);
INSERT INTO `operation_logs` VALUES (245, 1, 'hotel', 'update', '更新酒店: 南宁市银田酒店', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-26 17:50:56.012274', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (244, 1, 'hotel', 'update', '更新酒店: 南宁市银田酒店', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-02-26 17:50:51.730875', NULL, NULL, NULL);
INSERT INTO `operation_logs` VALUES (243, 1, 'auth', 'login', '用户登录: admin', '127.0.0.1', 'curl/8.5.0', '2026-02-26 17:47:18.922774', NULL, NULL, NULL);


-- ----------------------------
-- 表结构 for system_config
-- ----------------------------
DROP TABLE IF EXISTS `system_config`;
CREATE TABLE `system_config` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `config_key` VARCHAR(50) NOT NULL COMMENT '配置键',
  `config_value` TEXT COMMENT '配置值',
  `description` VARCHAR(255) COMMENT '描述',
  `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

INSERT INTO `system_config` VALUES (1, 'system_name', '多酒店管理系统', '系统名称', '2026-02-25 03:25:27', '2026-02-25 03:25:27');
INSERT INTO `system_config` VALUES (2, 'logo_url', '', 'Logo URL', '2026-02-25 03:25:27', '2026-02-25 03:25:27');
INSERT INTO `system_config` VALUES (3, 'menu_daily_report_name', '日报表管理', '日报表菜单名称', '2026-02-25 03:25:27', '2026-02-25 03:25:27');
INSERT INTO `system_config` VALUES (4, 'menu_monthly_task_name', '月任务管理', '月任务菜单名称', '2026-02-25 03:25:27', '2026-02-25 03:25:27');
INSERT INTO `system_config` VALUES (5, 'menu_report_config_name', '报表配置', '报表配置菜单名称', '2026-02-25 03:25:27', '2026-02-25 03:25:27');


-- ----------------------------
-- 表结构 for field_mappings
-- ----------------------------
DROP TABLE IF EXISTS `field_mappings`;
CREATE TABLE `field_mappings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `excel_item_name` VARCHAR(100) NOT NULL COMMENT 'Excel项目名',
  `system_field` VARCHAR(50) NOT NULL COMMENT '系统字段',
  `field_type` VARCHAR(20) COMMENT '字段类型',
  `value_column` VARCHAR(5) COMMENT '值列号',
  `category` VARCHAR(50) COMMENT '分类',
  `priority` INT DEFAULT 0 COMMENT '优先级',
  `is_active` TINYINT DEFAULT 1 COMMENT '是否启用',
  `remark` VARCHAR(255) COMMENT '备注',
  `value_example` VARCHAR(100) COMMENT '示例值',
  `hotel_id` INT UNSIGNED COMMENT '酒店ID',
  `row_num` INT COMMENT '行号',
  `row_start` INT COMMENT '起始行',
  `row_end` INT COMMENT '结束行',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_hotel` (`hotel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='字段映射配置表';

INSERT INTO `field_mappings` VALUES (55, '出租率(扣维修&amp;自用)', 'occ_rate', 'number', 'E', '总营业指标', 0, 1, '', '112.86%', NULL, 10, NULL, NULL, '2026-02-26 06:52:00', '2026-02-26 06:52:00');


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 默认管理员账号
-- 用户名: admin
-- 密码: admin123
-- ============================================
