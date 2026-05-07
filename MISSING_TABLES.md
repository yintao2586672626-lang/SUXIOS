# 缺表清单

生成时间：2026-05-08

本清单只记录当前代码、SQL 资源与本地 `hotelx` 数据库之间的差异。未执行建表、迁移或数据导入。

## 当前数据库表

当前 `hotelx` 中存在 12 张表：

| 表名 |
| --- |
| `daily_reports` |
| `field_mappings` |
| `hotels` |
| `monthly_tasks` |
| `online_daily_data` |
| `operation_logs` |
| `report_configs` |
| `roles` |
| `system_config` |
| `system_configs` |
| `user_hotel_permissions` |
| `users` |

## 模型存在但数据库缺失

这些表由 `app/model/*.php` 引用，但当前 `hotelx` 中不存在。访问对应模块时可能出现 500。

| 模型 | 表名 | 影响模块 |
| --- | --- | --- |
| `AgentConfig` | `agent_configs` | AI Agent |
| `AgentConversation` | `agent_conversations` | AI Agent |
| `AgentLog` | `agent_logs` | AI Agent |
| `AgentTask` | `agent_tasks` | AI Agent |
| `AgentWorkOrder` | `agent_work_orders` | AI Agent |
| `CompetitorAnalysis` | `competitor_analysis` | 收益管理 Agent |
| `CompetitorDevice` | `competitor_device` | 竞对监控 |
| `CompetitorHotel` | `competitor_hotel` | 竞对监控 |
| `CompetitorPriceLog` | `competitor_price_log` | 竞对监控 |
| `DemandForecast` | `demand_forecasts` | 收益管理 Agent |
| `DeviceCategory` | `device_categories` | 资产运维 Agent |
| `DeviceMaintenance` | `device_maintenance` | 资产运维 Agent |
| `Device` | `devices` | 资产运维 Agent |
| `EnergyBenchmark` | `energy_benchmarks` | 资产运维 Agent |
| `EnergyConsumption` | `energy_consumption` | 资产运维 Agent |
| `EnergySavingSuggestion` | `energy_saving_suggestions` | 资产运维 Agent |
| `HotelFieldTemplateItem` | `hotel_field_template_items` | 酒店字段模板 |
| `HotelFieldTemplate` | `hotel_field_templates` | 酒店字段模板 |
| `KnowledgeBase` | `knowledge_base` | 智能员工 Agent |
| `KnowledgeCategory` | `knowledge_categories` | 智能员工 Agent |
| `LoginLog` | `login_logs` | 登录日志 |
| `MaintenancePlan` | `maintenance_plans` | 资产运维 Agent |
| `PriceSuggestion` | `price_suggestions` | 收益管理 Agent |
| `RoomType` | `room_types` | 收益管理 Agent |

## SQL 资源已有但未导入数据库

这些表在 `database/**/*.sql` 中已有 `CREATE TABLE`，但当前 `hotelx` 中不存在。

| 表名 | SQL 文件 |
| --- | --- |
| `agent_configs` | `database/migrations/20250402_create_agent_tables.sql` |
| `agent_conversations` | `database/migrations/20250402_enhance_agent_tables.sql` |
| `agent_logs` | `database/migrations/20250402_create_agent_tables.sql` |
| `agent_tasks` | `database/migrations/20250402_create_agent_tables.sql` |
| `agent_work_orders` | `database/migrations/20250402_enhance_agent_tables.sql` |
| `competitor_analysis` | `database/migrations/20250402_enhance_agent_tables.sql` |
| `complaint_feedbacks` | `database/complaint_tables.sql` |
| `complaint_rooms` | `database/complaint_tables.sql` |
| `demand_forecasts` | `database/migrations/20250402_enhance_agent_tables.sql` |
| `device_categories` | `database/migrations/20250402_create_agent_tables.sql` |
| `device_maintenance` | `database/migrations/20250402_create_agent_tables.sql` |
| `devices` | `database/migrations/20250402_create_agent_tables.sql` |
| `energy_benchmarks` | `database/migrations/20250402_enhance_agent_tables.sql` |
| `energy_consumption` | `database/migrations/20250402_create_agent_tables.sql` |
| `energy_saving_suggestions` | `database/migrations/20250402_enhance_agent_tables.sql` |
| `knowledge_base` | `database/migrations/20250402_create_agent_tables.sql` |
| `knowledge_categories` | `database/migrations/20250402_create_agent_tables.sql` |
| `login_logs` | `database/login_logs.sql` |
| `maintenance_plans` | `database/migrations/20250402_enhance_agent_tables.sql` |
| `price_suggestions` | `database/migrations/20250402_create_agent_tables.sql` |
| `room_types` | `database/migrations/20250402_create_agent_tables.sql` |

## 模型存在但当前 SQL 资源也缺失

这些表既被模型引用，也没有在当前 `hotelx_dump.sql` 或 `database/**/*.sql` 中找到建表语句。

| 模型 | 表名 | 影响模块 |
| --- | --- | --- |
| `CompetitorDevice` | `competitor_device` | 竞对监控 |
| `CompetitorHotel` | `competitor_hotel` | 竞对监控 |
| `CompetitorPriceLog` | `competitor_price_log` | 竞对监控 |
| `HotelFieldTemplateItem` | `hotel_field_template_items` | 酒店字段模板 |
| `HotelFieldTemplate` | `hotel_field_templates` | 酒店字段模板 |

## 运行中已观察到的缺表报错

| 接口 | 缺失表 |
| --- | --- |
| `GET /api/hotel-field-templates` | `hotel_field_templates` |
| `GET /api/agent/overview` | `agent_configs` |
| `GET /api/admin/competitor-hotels` | `competitor_hotel` |
| `GET /api/admin/competitor-price-logs` | `competitor_price_log` |
| `GET /api/admin/competitor-devices` | `competitor_device` |
| `GET /api/admin/competitor-wechat-robot` | `competitor_wechat_robot` |

## 后续建议

1. 先备份 `hotelx`。
2. 优先导入 `database/migrations/20250402_create_agent_tables.sql`、`database/migrations/20250402_enhance_agent_tables.sql` 和 `database/login_logs.sql`。
3. 为 `competitor_device`、`competitor_hotel`、`competitor_price_log`、`hotel_field_templates`、`hotel_field_template_items`、`competitor_wechat_robot` 补充幂等建表 SQL 后再执行。
