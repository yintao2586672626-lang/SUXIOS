# SQL Schema 检查

检查时间：2026-05-18

## 结论

- `hotelx_dump.sql` 是基础 dump，单独导入只包含 12 张基础表。
- `database/init_full.sql` 是完整初始化入口，加载 `hotelx_dump.sql`、补充 SQL 和全部迁移后共覆盖 52 张表。
- 代码静态引用的必需表共 50 张，完整 SQL 资源已覆盖。
- 代码中唯一未建表引用是 `store`，该引用在 `CompetitorWechatRobotController::getStores()` 中先执行 `SHOW TABLES LIKE 'store'`，不存在时回退 `hotels`，不按缺表处理。

## hotelx_dump.sql 单独缺失

这些表不在基础 dump 中，但已由 `database/init_full.sql` 引入的迁移补齐：

| 分类 | 表 |
| --- | --- |
| Agent | `agent_configs`, `agent_tasks`, `agent_logs`, `agent_conversations`, `agent_work_orders` |
| 收益管理 | `room_types`, `price_suggestions`, `demand_forecasts`, `competitor_analysis` |
| 资产运维 | `device_categories`, `devices`, `device_maintenance`, `energy_consumption`, `energy_benchmarks`, `energy_saving_suggestions`, `maintenance_plans` |
| 竞对监控 | `competitor_hotel`, `competitor_price_log`, `competitor_device`, `competitor_wechat_robot` |
| 字段模板 | `hotel_field_templates`, `hotel_field_template_items` |
| AI 配置 | `ai_model_configs` |
| 投资/开业/运营/转让 | `feasibility_reports`, `opening_projects`, `opening_tasks`, `operation_alerts`, `operation_action_tracks`, `strategy_simulation_records`, `strategy_data_snapshots`, `quant_simulation_records`, `expansion_records`, `transfer_records` |
| 知识中心 | `knowledge_categories`, `knowledge_base`, `knowledge_units`, `knowledge_chunks` |
| 其他补充 | `login_logs` |

## 关键字段补齐

| 表 | 字段 |
| --- | --- |
| `online_daily_data` | `platform`, `compare_type`, `list_exposure`, `detail_exposure`, `flow_rate`, `order_filling_num`, `order_submit_num` |
| `devices` | `last_maintenance_date`, `next_maintenance_date` |
| `energy_consumption` | `anomaly_level`, `benchmark_id` |
| `price_suggestions` | `demand_forecast_id` |

## 当前导入方式

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root hotelx < database/init_full.sql
```

如需生成单文件完整 SQL：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build_hotelx_full_dump.ps1
C:\xampp\mysql\bin\mysql.exe -u root hotelx < output/hotelx_dump_full.sql
```

## 验证命令

```powershell
C:\xampp\php\php.exe scripts\verify_sql_schema_contract.php
```

执行自动导入验证和幂等迁移：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\run_sql_schema_integrity.ps1 -Database hotelx
```

当前验证结果：

```text
OK: SQL schema contract passed
Full SQL tables: 52
Code-required tables: 50
hotelx_dump.sql base tables: 12
hotelx_dump.sql gaps covered by migrations: 38 tables, 321 columns
Optional table refs skipped: store
```

临时库实际导入验证：

```text
database/init_full.sql import passed
Imported tables: 52
online_daily_data OTA traffic columns: 7
```

实际 `hotelx` 验证报告见 `output/sql_schema_integrity_report.md`。
