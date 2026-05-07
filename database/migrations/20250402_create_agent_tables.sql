-- =====================================================
-- AI Agent 模块数据库迁移脚本
-- 创建时间: 2026-04-02
-- 功能: 创建Agent配置、日志、任务、知识库、定价建议、能耗、设备等数据表
-- =====================================================

-- 1. Agent配置表
CREATE TABLE IF NOT EXISTS `agent_configs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `agent_type` TINYINT UNSIGNED NOT NULL COMMENT 'Agent类型: 1=智能员工, 2=收益管理, 3=资产运维',
    `is_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否启用: 0=禁用, 1=启用',
    `config_data` JSON NULL COMMENT '配置数据(JSON格式)',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_agent` (`hotel_id`, `agent_type`),
    INDEX `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent配置表';

-- 2. Agent日志表
CREATE TABLE IF NOT EXISTS `agent_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `agent_type` TINYINT UNSIGNED NOT NULL COMMENT 'Agent类型: 1=智能员工, 2=收益管理, 3=资产运维',
    `action` VARCHAR(100) NOT NULL COMMENT '操作类型',
    `message` TEXT NOT NULL COMMENT '日志消息',
    `log_level` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '日志级别: 1=调试, 2=信息, 3=警告, 4=错误',
    `context_data` JSON NULL COMMENT '上下文数据(JSON格式)',
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作用户ID',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_hotel_agent` (`hotel_id`, `agent_type`),
    INDEX `idx_level` (`log_level`),
    INDEX `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent日志表';

-- 3. Agent任务表
CREATE TABLE IF NOT EXISTS `agent_tasks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `agent_type` TINYINT UNSIGNED NOT NULL COMMENT 'Agent类型: 1=智能员工, 2=收益管理, 3=资产运维',
    `task_type` TINYINT UNSIGNED NOT NULL COMMENT '任务类型: 1=数据采集, 2=数据分析, 3=通知推送, 4=执行动作',
    `task_name` VARCHAR(200) NOT NULL COMMENT '任务名称',
    `params` JSON NULL COMMENT '任务参数(JSON格式)',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 1=待执行, 2=执行中, 3=已完成, 4=失败, 5=已取消',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '优先级: 1=低, 2=普通, 3=高, 4=紧急',
    `result_data` JSON NULL COMMENT '执行结果(JSON格式)',
    `execute_time` DATETIME NULL COMMENT '开始执行时间',
    `completed_time` DATETIME NULL COMMENT '完成时间',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_agent` (`hotel_id`, `agent_type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent任务表';

-- 4. 知识库分类表
CREATE TABLE IF NOT EXISTS `knowledge_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID(0=系统通用)',
    `parent_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '父分类ID',
    `name` VARCHAR(100) NOT NULL COMMENT '分类名称',
    `description` VARCHAR(255) NULL COMMENT '分类描述',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
    `is_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用: 0=禁用, 1=启用',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_parent` (`hotel_id`, `parent_id`),
    INDEX `idx_enabled` (`is_enabled`),
    INDEX `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识库分类表';

-- 5. 知识库表
CREATE TABLE IF NOT EXISTS `knowledge_base` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
    `title` VARCHAR(200) NOT NULL COMMENT '标题',
    `content` TEXT NOT NULL COMMENT '内容',
    `keywords` VARCHAR(255) NULL COMMENT '关键词',
    `tags` JSON NULL COMMENT '标签(JSON数组)',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
    `is_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用: 0=禁用, 1=启用',
    `view_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览次数',
    `like_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞次数',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_category` (`hotel_id`, `category_id`),
    INDEX `idx_enabled` (`is_enabled`),
    INDEX `idx_title` (`title`),
    FULLTEXT INDEX `idx_content` (`title`, `content`, `keywords`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='知识库表';

-- 6. 房型表
CREATE TABLE IF NOT EXISTS `room_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL COMMENT '酒店ID',
    `name` VARCHAR(100) NOT NULL COMMENT '房型名称',
    `description` VARCHAR(500) NULL COMMENT '房型描述',
    `base_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '基础价格',
    `min_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '最低价格',
    `max_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '最高价格',
    `room_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '房间数量',
    `facilities` JSON NULL COMMENT '设施(JSON数组)',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
    `is_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用: 0=禁用, 1=启用',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel` (`hotel_id`),
    INDEX `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='房型表';

-- 7. 定价建议表
CREATE TABLE IF NOT EXISTS `price_suggestions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL COMMENT '酒店ID',
    `room_type_id` INT UNSIGNED NOT NULL COMMENT '房型ID',
    `suggestion_type` TINYINT UNSIGNED NOT NULL COMMENT '建议类型: 1=动态定价, 2=竞对跟价, 3=事件驱动, 4=预测驱动',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 1=待审批, 2=已批准, 3=已拒绝, 4=已应用, 5=已过期',
    `suggestion_date` DATE NOT NULL COMMENT '建议日期',
    `current_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '当前价格',
    `suggested_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '建议价格',
    `min_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '最低价格限制',
    `max_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '最高价格限制',
    `confidence_score` DECIMAL(3,2) NOT NULL DEFAULT 0 COMMENT '置信度(0-1)',
    `competitor_data` JSON NULL COMMENT '竞对数据(JSON)',
    `factors` JSON NULL COMMENT '定价因子(JSON)',
    `reason` TEXT NULL COMMENT '建议原因',
    `applied_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审批人ID',
    `applied_time` DATETIME NULL COMMENT '应用时间',
    `remark` VARCHAR(500) NULL COMMENT '备注',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_date` (`hotel_id`, `suggestion_date`),
    INDEX `idx_room_type` (`room_type_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_suggestion_type` (`suggestion_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='定价建议表';

-- 8. 设备分类表
CREATE TABLE IF NOT EXISTS `device_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '父分类ID',
    `name` VARCHAR(100) NOT NULL COMMENT '分类名称',
    `description` VARCHAR(255) NULL COMMENT '分类描述',
    `default_maintenance_cycle` INT UNSIGNED NOT NULL DEFAULT 90 COMMENT '默认维护周期(天)',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
    `is_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用: 0=禁用, 1=启用',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_parent` (`parent_id`),
    INDEX `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='设备分类表';

-- 9. 设备表
CREATE TABLE IF NOT EXISTS `devices` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL COMMENT '酒店ID',
    `category_id` INT UNSIGNED NOT NULL COMMENT '分类ID',
    `area_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '区域ID',
    `name` VARCHAR(100) NOT NULL COMMENT '设备名称',
    `model` VARCHAR(100) NULL COMMENT '设备型号',
    `serial_number` VARCHAR(100) NULL COMMENT '序列号',
    `location` VARCHAR(200) NULL COMMENT '安装位置',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 1=正常, 2=维护中, 3=故障, 4=报废',
    `install_date` DATE NULL COMMENT '安装日期',
    `warranty_expire` DATE NULL COMMENT '保修到期日',
    `last_maintenance` DATE NULL COMMENT '上次维护日期',
    `next_maintenance` DATE NULL COMMENT '下次维护日期',
    `maintenance_cycle` INT UNSIGNED NOT NULL DEFAULT 90 COMMENT '维护周期(天)',
    `purchase_cost` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '采购成本',
    `energy_consumption` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '能耗指标',
    `is_monitored` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否监控: 0=否, 1=是',
    `metadata` JSON NULL COMMENT '扩展数据(JSON)',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel` (`hotel_id`),
    INDEX `idx_category` (`category_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_next_maintenance` (`next_maintenance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='设备表';

-- 10. 设备维护记录表
CREATE TABLE IF NOT EXISTS `device_maintenance` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `device_id` INT UNSIGNED NOT NULL COMMENT '设备ID',
    `maintenance_type` TINYINT UNSIGNED NOT NULL COMMENT '维护类型: 1=预防性, 2=纠正性, 3=预测性, 4=紧急维修',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 1=已计划, 2=进行中, 3=已完成, 4=已取消',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '优先级: 1=低, 2=普通, 3=高, 4=紧急',
    `description` TEXT NULL COMMENT '维护描述',
    `scheduled_date` DATE NOT NULL COMMENT '计划日期',
    `completed_date` DATE NULL COMMENT '完成日期',
    `actual_start` DATETIME NULL COMMENT '实际开始时间',
    `actual_end` DATETIME NULL COMMENT '实际结束时间',
    `cost` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '维护成本',
    `parts_replaced` JSON NULL COMMENT '更换部件(JSON数组)',
    `operator_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
    `remark` VARCHAR(500) NULL COMMENT '备注',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_device` (`device_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled_date` (`scheduled_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='设备维护记录表';

-- 11. 能耗数据表
CREATE TABLE IF NOT EXISTS `energy_consumption` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL COMMENT '酒店ID',
    `device_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '设备ID(0=区域汇总)',
    `energy_type` TINYINT UNSIGNED NOT NULL COMMENT '能源类型: 1=电力, 2=水, 3=燃气, 4=蒸汽, 5=热水',
    `area_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '区域ID',
    `record_date` DATE NOT NULL COMMENT '记录日期',
    `record_hour` TINYINT UNSIGNED NULL COMMENT '记录小时(0-23, NULL表示日汇总)',
    `consumption_value` DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '能耗值',
    `cost_amount` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '费用金额',
    `peak_value` DECIMAL(12,3) NULL COMMENT '峰值',
    `valley_value` DECIMAL(12,3) NULL COMMENT '谷值',
    `is_anomaly` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否异常: 0=正常, 1=异常',
    `anomaly_score` DECIMAL(3,2) NULL COMMENT '异常评分(0-1)',
    `metadata` JSON NULL COMMENT '扩展数据(JSON)',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_hotel_date` (`hotel_id`, `record_date`),
    INDEX `idx_device` (`device_id`),
    INDEX `idx_energy_type` (`energy_type`),
    INDEX `idx_is_anomaly` (`is_anomaly`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='能耗数据表';

-- 插入默认设备分类
INSERT INTO `device_categories` (`id`, `name`, `description`, `default_maintenance_cycle`, `sort_order`) VALUES
(1, '空调系统', '中央空调、分体空调等', 90, 1),
(2, '电梯系统', '客梯、货梯、扶梯等', 30, 2),
(3, '供水系统', '水泵、水箱、管道等', 60, 3),
(4, '供电系统', '配电柜、发电机、UPS等', 90, 4),
(5, '消防系统', '消防栓、喷淋、报警器等', 30, 5),
(6, '暖通系统', '锅炉、换热器、管道等', 90, 6),
(7, '厨房设备', '炉灶、蒸箱、冰箱等', 30, 7),
(8, '客房设备', '电视、冰箱、灯具等', 180, 8),
(9, '安防系统', '监控、门禁、对讲等', 90, 9),
(10, '网络设备', '路由器、交换机、AP等', 180, 10)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 插入默认知识库分类
INSERT INTO `knowledge_categories` (`id`, `hotel_id`, `parent_id`, `name`, `description`, `sort_order`) VALUES
(1, 0, 0, '入住退房', '入住和退房相关流程', 1),
(2, 0, 0, '客房服务', '客房清洁、维修等服务', 2),
(3, 0, 0, '餐饮服务', '餐厅、早餐等服务', 3),
(4, 0, 0, '设施使用', '酒店设施使用说明', 4),
(5, 0, 0, '周边信息', '周边景点、交通等信息', 5),
(6, 0, 0, '投诉处理', '常见投诉处理方式', 6)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
