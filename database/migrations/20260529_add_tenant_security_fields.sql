ALTER TABLE `hotels`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，默认等于系统酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_hotels_tenant` (`tenant_id`, `status`);

UPDATE `hotels`
SET `tenant_id` = `id`
WHERE `tenant_id` = 0;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认跟随主酒店' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_users_tenant` (`tenant_id`, `status`);

UPDATE `users`
SET `tenant_id` = `hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `hotel_id` IS NOT NULL AND `hotel_id` > 0;

ALTER TABLE `user_hotel_permissions`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于授权酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_user_hotel_permissions_tenant` (`tenant_id`, `user_id`);

UPDATE `user_hotel_permissions`
SET `tenant_id` = `hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `hotel_id` IS NOT NULL AND `hotel_id` > 0;

ALTER TABLE `daily_reports`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于日报酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_daily_reports_tenant_date` (`tenant_id`, `report_date`);

UPDATE `daily_reports`
SET `tenant_id` = `hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `hotel_id` IS NOT NULL AND `hotel_id` > 0;

ALTER TABLE `monthly_tasks`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于月任务酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_monthly_tasks_tenant_month` (`tenant_id`, `year`, `month`);

UPDATE `monthly_tasks`
SET `tenant_id` = `hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `hotel_id` IS NOT NULL AND `hotel_id` > 0;

ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于系统酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_online_daily_tenant_date` (`tenant_id`, `data_date`),
  ADD INDEX IF NOT EXISTS `idx_online_daily_tenant_source` (`tenant_id`, `source`, `data_type`);

UPDATE `online_daily_data`
SET `tenant_id` = `system_hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `system_hotel_id` IS NOT NULL AND `system_hotel_id` > 0;

ALTER TABLE `operation_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于操作酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_operation_logs_tenant_time` (`tenant_id`, `create_time`);

UPDATE `operation_logs`
SET `tenant_id` = `hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `hotel_id` IS NOT NULL AND `hotel_id` > 0;

ALTER TABLE `platform_data_sources`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于系统酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_platform_data_sources_tenant` (`tenant_id`, `platform`, `data_type`);

UPDATE `platform_data_sources`
SET `tenant_id` = `system_hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `system_hotel_id` IS NOT NULL AND `system_hotel_id` > 0;

ALTER TABLE `platform_data_sync_tasks`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于系统酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_platform_sync_tasks_tenant` (`tenant_id`, `status`);

UPDATE `platform_data_sync_tasks`
SET `tenant_id` = `system_hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `system_hotel_id` IS NOT NULL AND `system_hotel_id` > 0;

ALTER TABLE `platform_data_raw_records`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于系统酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_platform_raw_records_tenant` (`tenant_id`, `platform`, `data_type`);

UPDATE `platform_data_raw_records`
SET `tenant_id` = `system_hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `system_hotel_id` IS NOT NULL AND `system_hotel_id` > 0;

ALTER TABLE `platform_data_sync_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于系统酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_platform_sync_logs_tenant` (`tenant_id`, `level`);

UPDATE `platform_data_sync_logs`
SET `tenant_id` = `system_hotel_id`
WHERE (`tenant_id` IS NULL OR `tenant_id` = 0) AND `system_hotel_id` IS NOT NULL AND `system_hotel_id` > 0;

ALTER TABLE `agent_configs`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_agent_configs_tenant` (`tenant_id`, `hotel_id`);

UPDATE `agent_configs`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `agent_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_agent_logs_tenant` (`tenant_id`, `hotel_id`);

UPDATE `agent_logs`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `agent_tasks`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_agent_tasks_tenant` (`tenant_id`, `hotel_id`);

UPDATE `agent_tasks`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `knowledge_categories`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID，0为系统通用' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_knowledge_categories_tenant` (`tenant_id`, `hotel_id`);

UPDATE `knowledge_categories`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `knowledge_base`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID，0为系统通用' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_knowledge_base_tenant` (`tenant_id`, `hotel_id`);

UPDATE `knowledge_base`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `room_types`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_room_types_tenant` (`tenant_id`, `hotel_id`);

UPDATE `room_types`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `price_suggestions`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_price_suggestions_tenant` (`tenant_id`, `hotel_id`);

UPDATE `price_suggestions`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `devices`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_devices_tenant` (`tenant_id`, `hotel_id`);

UPDATE `devices`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `energy_consumption`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_energy_consumption_tenant` (`tenant_id`, `hotel_id`);

UPDATE `energy_consumption`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `demand_forecasts`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_demand_forecasts_tenant` (`tenant_id`, `hotel_id`);

UPDATE `demand_forecasts`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `competitor_analysis`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于本酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_competitor_analysis_tenant` (`tenant_id`, `hotel_id`);

UPDATE `competitor_analysis`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `agent_work_orders`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_agent_work_orders_tenant` (`tenant_id`, `hotel_id`);

UPDATE `agent_work_orders`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `agent_conversations`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_agent_conversations_tenant` (`tenant_id`, `hotel_id`);

UPDATE `agent_conversations`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `energy_benchmarks`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_energy_benchmarks_tenant` (`tenant_id`, `hotel_id`);

UPDATE `energy_benchmarks`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `energy_saving_suggestions`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_energy_saving_tenant` (`tenant_id`, `hotel_id`);

UPDATE `energy_saving_suggestions`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `maintenance_plans`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_maintenance_plans_tenant` (`tenant_id`, `hotel_id`);

UPDATE `maintenance_plans`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `hotel_field_templates`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_hotel_field_templates_tenant` (`tenant_id`, `hotel_id`);

UPDATE `hotel_field_templates`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `competitor_hotel`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT 'tenant id, follows store hotel' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_competitor_hotel_tenant_store` (`tenant_id`, `store_id`, `status`);

UPDATE `competitor_hotel`
SET `tenant_id` = `store_id`
WHERE `tenant_id` IS NULL AND `store_id` IS NOT NULL AND `store_id` > 0;

ALTER TABLE `competitor_price_log`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_competitor_price_log_tenant` (`tenant_id`, `hotel_id`);

UPDATE `competitor_price_log`
SET `tenant_id` = `store_id`
WHERE `tenant_id` IS NULL AND `store_id` IS NOT NULL AND `store_id` > 0;

ALTER TABLE `opening_projects`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID，0为待归属项目' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_opening_projects_tenant` (`tenant_id`, `hotel_id`);

UPDATE `opening_projects`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `operation_alerts`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_operation_alerts_tenant` (`tenant_id`, `hotel_id`);

UPDATE `operation_alerts`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `operation_action_tracks`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_operation_actions_tenant` (`tenant_id`, `hotel_id`);

UPDATE `operation_action_tracks`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `operation_execution_intents`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_operation_exec_intents_tenant` (`tenant_id`, `hotel_id`);

UPDATE `operation_execution_intents`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `operation_execution_tasks`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_operation_exec_tasks_tenant` (`tenant_id`, `hotel_id`);

UPDATE `operation_execution_tasks`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `operation_execution_evidence`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认跟随执行任务' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_operation_exec_evidence_tenant` (`tenant_id`, `task_id`);

UPDATE `operation_execution_evidence` evidence
INNER JOIN `operation_execution_tasks` task ON task.`id` = evidence.`task_id`
SET evidence.`tenant_id` = task.`tenant_id`
WHERE evidence.`tenant_id` IS NULL AND task.`tenant_id` IS NOT NULL;

ALTER TABLE `transfer_records`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_transfer_records_tenant` (`tenant_id`, `hotel_id`);

UPDATE `transfer_records`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `complaint_rooms`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_complaint_rooms_tenant` (`tenant_id`, `hotel_id`);

UPDATE `complaint_rooms`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `complaint_feedbacks`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_complaint_feedbacks_tenant` (`tenant_id`, `hotel_id`);

UPDATE `complaint_feedbacks`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `field_mappings`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_field_mappings_tenant` (`tenant_id`, `hotel_id`);

UPDATE `field_mappings`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `ai_model_call_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认等于酒店ID' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_ai_model_call_logs_tenant` (`tenant_id`, `hotel_id`);

UPDATE `ai_model_call_logs`
SET `tenant_id` = `hotel_id`
WHERE `tenant_id` IS NULL AND `hotel_id` IS NOT NULL;

ALTER TABLE `login_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认跟随登录用户主酒店' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_login_logs_tenant` (`tenant_id`, `user_id`);

UPDATE `login_logs` log
INNER JOIN `users` user_row ON user_row.`id` = log.`user_id`
SET log.`tenant_id` = user_row.`tenant_id`
WHERE log.`tenant_id` IS NULL AND user_row.`tenant_id` IS NOT NULL;

ALTER TABLE `quant_simulation_records`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认跟随创建用户' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_quant_sim_tenant_user` (`tenant_id`, `created_by`, `id`);

UPDATE `quant_simulation_records` record
INNER JOIN `users` user_row ON user_row.`id` = record.`created_by`
SET record.`tenant_id` = user_row.`tenant_id`
WHERE record.`tenant_id` IS NULL AND user_row.`tenant_id` IS NOT NULL;

ALTER TABLE `expansion_records`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认跟随创建用户' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_expansion_records_tenant_user` (`tenant_id`, `created_by`, `id`);

UPDATE `expansion_records` record
INNER JOIN `users` user_row ON user_row.`id` = record.`created_by`
SET record.`tenant_id` = user_row.`tenant_id`
WHERE record.`tenant_id` IS NULL AND user_row.`tenant_id` IS NOT NULL;

ALTER TABLE `strategy_simulation_records`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT '租户ID，默认跟随创建用户' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_strategy_records_tenant_user` (`tenant_id`, `created_by`, `id`);

UPDATE `strategy_simulation_records` record
INNER JOIN `users` user_row ON user_row.`id` = record.`created_by`
SET record.`tenant_id` = user_row.`tenant_id`
WHERE record.`tenant_id` IS NULL AND user_row.`tenant_id` IS NOT NULL;

ALTER TABLE `feasibility_reports`
  ADD COLUMN IF NOT EXISTS `tenant_id` int unsigned DEFAULT NULL COMMENT 'tenant id, follows creator user' AFTER `id`,
  ADD INDEX IF NOT EXISTS `idx_feasibility_reports_tenant_user` (`tenant_id`, `created_by`, `id`);

UPDATE `feasibility_reports` record
INNER JOIN `users` user_row ON user_row.`id` = record.`created_by`
SET record.`tenant_id` = user_row.`tenant_id`
WHERE record.`tenant_id` IS NULL AND user_row.`tenant_id` IS NOT NULL;
