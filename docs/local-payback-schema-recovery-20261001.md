# 投资回本页本机503恢复方案

当前截图故障：项目列表读取失败，提示“没有可用的本机 PHP worker”。截至2026-10-01 21:27，8080入口仍返回503；8081、8082、8083三个PHP进程均在运行，数据库SELECT 1通过，三个PHP健康检查都因为 database_schema_upgrade_required 返回503。

`php think db:check`确认仅有两项未登记迁移；投资回本台账迁移已经登记，现有三个台账表存在。两项缺失迁移为：

| 迁移 | 必要效果 | 文件SHA256 |
| --- | --- | --- |
| 20260908_create_promotion_experiment_versions.sql | 新建 promotion_experiment_versions | 3a6f9dbd9afdd12b6eb35db99a220a358d0a47e7488de2564e2485fcc2f78954 |
| 20260908_create_operation_task_workflow.sql | 新建 operation_task_workflow_events、operation_task_workflow_proposals | 455fe679ff17860256ff9bac515ae0977edac17f322806d37b3f324a3f232b18 |

已只读核对三个辅助表目前均不存在。SQL仅包含 CREATE TABLE IF NOT EXISTS，没有删除、更新已有酒店项目或资金记录；执行器会写入迁移登记及执行证据。目标严格限于本机 hotelx，线上不执行。

已保存本机数据库表结构与迁移登记备份，没有读取或备份配置凭证内容、没有输出SQL正文：

- `output/payback-verification/local-schema-before-recovery-20261001-212740.sql`，372300 bytes，SHA256 31c6ab098123f56fcb6df4e6793a75366645ee17256daaa3f0f6ef1a616cd4a9。
- `output/payback-verification/local-schema-registry-before-recovery-20261001-212740.sql`，52871 bytes，SHA256 ebb46c221dd98f0ff52db381ebe4bb861b2ee02479d6b164a76bbf5fad4c1380。

执行前重核待执行清单与两个SHA256，保持为上述两项；若清单或文件发生变化，不扩大执行。随后通过现有版本runner补齐、再验证db:check、三个PHP worker健康和8080健康。健康恢复后只点击原页面“刷新项目”，不重新加载整页，不写入或删除真实账务。

目前尚未执行数据库迁移。`app/service/DatabaseMigrationExecutionGuard.php`要求从链接工作树修改共享数据库必须明确批准：只有批准后才为该单次命令设置 `SUXI_LINKED_WORKTREE_SHARED_DB_MIGRATION_APPROVED=hotelx`。不绕过健康门禁、不删除迁移目录中的文件、不伪造迁移登记。

投资回本导入的两轮代码检查已完成，前端43 tests、导入后端20 tests/147 assertions、独立页面保存精确回读通过。此运行时故障与代码级导入验证分别报告。下一步为用户确认以上本机共享数据库修复，再执行与回显验收。
