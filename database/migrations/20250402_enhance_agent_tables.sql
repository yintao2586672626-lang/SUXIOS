-- ============================================================
-- Hotel AI Agent 增强功能数据库迁移
-- 创建日期: 2026-04-02
-- 功能: 支持需求预测、竞对分析、工单管理、对话记录、能耗基准、节能建议、维护计划
-- ============================================================

-- ------------------------------------------------------------
-- 1. 需求预测表 (Demand Forecast)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `demand_forecasts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `room_type_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '房型ID',
    `forecast_date` DATE NOT NULL COMMENT '预测日期',
    `forecast_method` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '预测方法:1=ARIMA,2=LLM,3=混合,4=ML',
    `predicted_occupancy` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '预测入住率%',
    `predicted_demand` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '预测需求量(间夜)',
    `actual_occupancy` DECIMAL(5,2) DEFAULT NULL COMMENT '实际入住率(用于验证)',
    `confidence_score` DECIMAL(3,2) NOT NULL DEFAULT 0.80 COMMENT '置信度0-1',
    `is_event_driven` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否事件驱动:0=否,1=是',
    `event_type` TINYINT UNSIGNED DEFAULT 0 COMMENT '事件类型:0=无,1=节假日,2=展会,3=周末,4=天气,5=竞对活动',
    `event_factors` JSON DEFAULT NULL COMMENT '事件因子详情',
    `historical_data` JSON DEFAULT NULL COMMENT '历史数据参考',
    `remark` VARCHAR(500) DEFAULT NULL COMMENT '备注',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_date` (`hotel_id`, `forecast_date`),
    INDEX `idx_room_type` (`room_type_id`),
    INDEX `idx_method` (`forecast_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='需求预测表';

-- ------------------------------------------------------------
-- 2. 竞对分析表 (Competitor Analysis)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `competitor_analysis` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本酒店ID',
    `competitor_hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '竞对酒店ID',
    `room_type_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本酒店房型ID',
    `competitor_room_type_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '竞对房型ID',
    `analysis_date` DATE NOT NULL COMMENT '分析日期',
    `our_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '我方价格',
    `competitor_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '竞对价格',
    `price_difference` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '价格差(我方-竞对)',
    `price_index` DECIMAL(5,2) NOT NULL DEFAULT 100 COMMENT '价格指数',
    `ota_platform` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'OTA平台:1=携程,2=美团,3=飞猪,4=Booking,5=Expedia,6=Agoda',
    `competitor_data` JSON DEFAULT NULL COMMENT '竞对详细数据',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_date` (`hotel_id`, `analysis_date`),
    INDEX `idx_competitor` (`competitor_hotel_id`),
    INDEX `idx_platform` (`ota_platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='竞对分析表';

-- ------------------------------------------------------------
-- 3. Agent工单表 (Agent Work Orders)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_work_orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `agent_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联AgentID',
    `source_type` TINYINT UNSIGNED NOT NULL DEFAULT 4 COMMENT '来源:1=客服对话,2=语音,3=系统告警,4=人工',
    `order_type` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '类型:1=客诉,2=维修,3=服务,4=清洁,5=其他',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '优先级:1=低,2=中,3=高,4=紧急',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:1=待处理,2=处理中,3=等待反馈,4=已解决,5=已关闭,6=已升级',
    `title` VARCHAR(200) NOT NULL COMMENT '工单标题',
    `content` TEXT COMMENT '工单内容',
    `guest_name` VARCHAR(100) DEFAULT NULL COMMENT '客人姓名',
    `guest_phone` VARCHAR(20) DEFAULT NULL COMMENT '客人电话',
    `room_id` INT UNSIGNED DEFAULT 0 COMMENT '房间ID',
    `room_number` VARCHAR(20) DEFAULT NULL COMMENT '房间号',
    `emotion_score` DECIMAL(3,2) DEFAULT 0 COMMENT '情绪分数0-1',
    `tags` JSON DEFAULT NULL COMMENT '标签',
    `attachments` JSON DEFAULT NULL COMMENT '附件',
    `solution` TEXT COMMENT '解决方案',
    `escalate_reason` VARCHAR(500) DEFAULT NULL COMMENT '升级原因',
    `created_by` INT UNSIGNED DEFAULT 0 COMMENT '创建人',
    `assigned_to` INT UNSIGNED DEFAULT 0 COMMENT '分配给',
    `assigned_time` DATETIME DEFAULT NULL COMMENT '分配时间',
    `resolved_time` DATETIME DEFAULT NULL COMMENT '解决时间',
    `escalated_time` DATETIME DEFAULT NULL COMMENT '升级时间',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_status` (`hotel_id`, `status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_assigned` (`assigned_to`),
    INDEX `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Agent工单表';

-- ------------------------------------------------------------
-- 4. Agent对话记录表 (Agent Conversations)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_conversations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `session_id` VARCHAR(64) NOT NULL COMMENT '会话ID',
    `channel` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '渠道:1=微信,2=企业微信,3=iPad,4=电话,5=APP',
    `guest_id` VARCHAR(64) DEFAULT NULL COMMENT '客人ID',
    `guest_name` VARCHAR(100) DEFAULT NULL COMMENT '客人姓名',
    `room_id` INT UNSIGNED DEFAULT 0 COMMENT '房间ID',
    `room_number` VARCHAR(20) DEFAULT NULL COMMENT '房间号',
    `message_type` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '消息类型:1=文本,2=图片,3=语音,4=富文本',
    `user_message` TEXT COMMENT '用户消息',
    `ai_response` TEXT COMMENT 'AI回复',
    `intent_type` TINYINT UNSIGNED NOT NULL DEFAULT 99 COMMENT '意图:1=问候,2=咨询,3=投诉,4=预订,5=服务,6=退房,99=其他',
    `emotion_score` DECIMAL(3,2) DEFAULT 0 COMMENT '情绪分数0-1',
    `is_ai_reply` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否AI回复:0=否,1=是',
    `confidence_score` DECIMAL(3,2) DEFAULT 0 COMMENT '置信度0-1',
    `knowledge_id` INT UNSIGNED DEFAULT 0 COMMENT '关联知识库ID',
    `entities` JSON DEFAULT NULL COMMENT '提取的实体',
    `context` JSON DEFAULT NULL COMMENT '上下文',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_hotel_session` (`hotel_id`, `session_id`),
    INDEX `idx_channel` (`channel`),
    INDEX `idx_intent` (`intent_type`),
    INDEX `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Agent对话记录表';

-- ------------------------------------------------------------
-- 5. 能耗基准表 (Energy Benchmarks)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `energy_benchmarks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `energy_type` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '能耗类型:1=电,2=水,3=燃气,4=蒸汽',
    `area_id` INT UNSIGNED DEFAULT 0 COMMENT '区域ID',
    `device_id` INT UNSIGNED DEFAULT 0 COMMENT '设备ID',
    `benchmark_type` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '基准类型:1=日,2=月,3=小时',
    `benchmark_value` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '基准值',
    `alert_threshold_high` DECIMAL(5,2) NOT NULL DEFAULT 20 COMMENT '高告警阈值%',
    `alert_threshold_low` DECIMAL(5,2) NOT NULL DEFAULT 10 COMMENT '低告警阈值%',
    `season_factor` JSON DEFAULT NULL COMMENT '季节因子配置',
    `is_active` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用',
    `remark` VARCHAR(500) DEFAULT NULL COMMENT '备注',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_energy` (`hotel_id`, `energy_type`),
    INDEX `idx_device` (`device_id`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='能耗基准表';

-- ------------------------------------------------------------
-- 6. 节能建议表 (Energy Saving Suggestions)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `energy_saving_suggestions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `energy_type` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '能耗类型:1=电,2=水,3=燃气,9=综合',
    `suggestion_type` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '建议类型:1=设备优化,2=运营调整,3=行为改变,4=设备升级,5=可再生能源',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '优先级:1=低,2=中,3=高,4=紧急',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:1=待评估,2=已批准,3=实施中,4=已完成,5=已拒绝',
    `title` VARCHAR(200) NOT NULL COMMENT '建议标题',
    `description` TEXT COMMENT '建议描述',
    `implementation_steps` TEXT COMMENT '实施步骤',
    `potential_saving` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '预计每天节省量',
    `actual_saving` DECIMAL(10,2) DEFAULT 0 COMMENT '实际节省量',
    `cost_estimate` DECIMAL(10,2) DEFAULT 0 COMMENT '预估成本',
    `payback_period` INT UNSIGNED DEFAULT 0 COMMENT '回本周期(天)',
    `related_devices` JSON DEFAULT NULL COMMENT '关联设备',
    `calculation_basis` JSON DEFAULT NULL COMMENT '计算依据',
    `implemented_by` INT UNSIGNED DEFAULT 0 COMMENT '实施人',
    `implementation_start` DATETIME DEFAULT NULL COMMENT '开始实施时间',
    `implementation_end` DATETIME DEFAULT NULL COMMENT '完成时间',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_status` (`hotel_id`, `status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_type` (`suggestion_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='节能建议表';

-- ------------------------------------------------------------
-- 7. 维护计划表 (Maintenance Plans)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `maintenance_plans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
    `device_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '设备ID',
    `category_id` INT UNSIGNED DEFAULT 0 COMMENT '分类ID',
    `plan_name` VARCHAR(200) NOT NULL COMMENT '计划名称',
    `plan_type` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '类型:1=日常,2=周,3=月,4=季度,5=年度,9=自定义',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '优先级:1=低,2=中,3=高,4=紧急',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:1=启用,2=暂停,3=完成,4=取消',
    `frequency_days` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT '周期(天)',
    `description` TEXT COMMENT '维护内容描述',
    `items` JSON DEFAULT NULL COMMENT '维护项目清单',
    `materials` JSON DEFAULT NULL COMMENT '所需物料',
    `estimated_duration` INT UNSIGNED DEFAULT 60 COMMENT '预计时长(分钟)',
    `estimated_cost` DECIMAL(10,2) DEFAULT 0 COMMENT '预估成本',
    `last_maintenance_date` DATE DEFAULT NULL COMMENT '上次维护日期',
    `execution_count` INT UNSIGNED DEFAULT 0 COMMENT '执行次数',
    `total_cost` DECIMAL(10,2) DEFAULT 0 COMMENT '总成本',
    `created_by` INT UNSIGNED DEFAULT 0 COMMENT '创建人',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `update_time` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_hotel_device` (`hotel_id`, `device_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='维护计划表';

-- ------------------------------------------------------------
-- 8. 在现有表中添加新字段
-- ------------------------------------------------------------

-- 为 price_suggestions 表添加置信度字段
ALTER TABLE `price_suggestions` 
ADD COLUMN IF NOT EXISTS `confidence_score` DECIMAL(3,2) DEFAULT 0.80 COMMENT '置信度' AFTER `factors`,
ADD COLUMN IF NOT EXISTS `demand_forecast_id` INT UNSIGNED DEFAULT 0 COMMENT '关联需求预测ID' AFTER `confidence_score`;

-- 为 energy_consumption 表添加异常检测相关字段
ALTER TABLE `energy_consumption`
ADD COLUMN IF NOT EXISTS `anomaly_level` TINYINT UNSIGNED DEFAULT 0 COMMENT '异常等级:0=正常,1=轻微,2=中度,3=严重' AFTER `anomaly_score`,
ADD COLUMN IF NOT EXISTS `benchmark_id` INT UNSIGNED DEFAULT 0 COMMENT '关联基准ID' AFTER `anomaly_level`;

-- 为 devices 表添加维护相关字段
ALTER TABLE `devices`
ADD COLUMN IF NOT EXISTS `maintenance_cycle` INT UNSIGNED DEFAULT 90 COMMENT '维护周期(天)' AFTER `warranty_expire`,
ADD COLUMN IF NOT EXISTS `next_maintenance_date` DATE DEFAULT NULL COMMENT '下次维护日期' AFTER `maintenance_cycle`;

-- ------------------------------------------------------------
-- 9. 插入默认数据
-- ------------------------------------------------------------

-- 插入默认能耗基准配置示例
INSERT INTO `energy_benchmarks` (`hotel_id`, `energy_type`, `benchmark_type`, `benchmark_value`, `alert_threshold_high`, `alert_threshold_low`, `season_factor`, `remark`) VALUES
(0, 1, 1, 500.00, 20.00, 10.00, '{"spring":1.0,"summer":1.3,"autumn":1.0,"winter":1.2}', '默认日电耗基准'),
(0, 2, 1, 50.00, 20.00, 10.00, '{"spring":1.0,"summer":1.1,"autumn":1.0,"winter":0.9}', '默认日水耗基准'),
(0, 3, 1, 30.00, 20.00, 10.00, '{"spring":1.0,"summer":0.8,"autumn":1.0,"winter":1.4}', '默认日燃气基准');

-- ------------------------------------------------------------
-- 完成
-- ------------------------------------------------------------
