# 宿析OS第二阶段每日经营工作台验收清单

## 目标边界

第二阶段不追求 AI 全自动管酒店。目标是把已验证的 OTA 数据闭环产品化为员工每日经营工作台，让员工每天能确认：

- OTA 数据今天有没有采到。
- 哪些字段可信，哪些字段缺失。
- 收入、流量、转化哪里异常。
- AI 建议的证据、缺口、边界和下一步是什么。
- 运营动作是否已经执行，复盘结果是什么。

所有结论限定在 OTA 渠道经营范围，不代表全酒店经营事实。

## 不变约束

- 不改变携程和美团手动获取逻辑。
- 不改变携程和美团自动获取逻辑。
- 不改变现有获取字段、映射、入库表和采集优先级。
- 巡检只读取现有 `online_daily_data`、员工六问、诊断证据、动作执行状态和巡检快照。
- 缺字段、未采集、登录失效、请求失败必须显式展示，不能用兜底成功或假数据掩盖。

## 当前验收项

| 要求 | 当前实现 | 验收证据 |
| --- | --- | --- |
| 多门店每日工作台 | `GET /api/online-data/daily-workbench` 汇总多门店行、摘要和下一步动作 | `scripts/verify_phase2_daily_workbench_contract.mjs` |
| 手动巡检快照 | `POST /api/online-data/daily-workbench-patrols/run` 生成运行时快照 | 前端“生成巡检快照”按钮和巡检快照服务 |
| 自动巡检入口 | `GET /api/online-data/daily-workbench-patrol-cron` 加 `CRON_TOKEN`；`php think online-data:daily-workbench-patrol` 可由任务计划调用 | 命令、脚本和合同校验 |
| 巡检健康状态 | 快照健康状态区分 `missing`、`stale`、`manual_ready`、`auto_ready`；`automation` 子对象必须说明自动巡检凭据和外部任务计划状态，`manual_ready` 不能被当成自动巡检已部署 | `DailyWorkbenchPatrolService::health()` |
| 异常诊断 | 工作台行暴露采集、字段、经营诊断、AI 依据、运营闭环状态 | 工作台表格和合同校验 |
| AI 建议解释 | `ai_evidence.explanation` 暴露解释、缺失证据、来源策略、下一步和边界 | 工作台表格和 Markdown 报告 |
| 运营动作跟踪 | 巡检动作可标记 `done`、`review_needed`，并同步到运营执行意图/任务 | `updateDailyWorkbenchPatrolAction()` |
| 复盘 | 已执行动作可记录 `success`、`observing`、`failed` 复盘结果 | `reviewDailyWorkbenchPatrolAction()` |
| 报告导出 | `GET /api/online-data/daily-workbench-patrols/report` 导出 Markdown | 报告包含巡检信息、汇总、门店巡检、AI 建议解释、动作跟踪与复盘、边界 |

## 自动巡检部署方式

前置条件：

- Web 服务可访问，例如 `http://127.0.0.1:8080`。
- `.env` 或运行环境已配置非空 `CRON_TOKEN`。
- 任务计划运行用户能执行项目内 PHP CLI。

手动试跑：

```bash
php think online-data:daily-workbench-patrol --base-url=http://127.0.0.1:8080 --target-date=2026-06-13 --limit=30
```

脚本入口：

```bash
php scripts/daily_workbench_patrol_cron.php --base-url=http://127.0.0.1:8080 --target-date=2026-06-13 --limit=30
```

Linux cron 示例：

```cron
20 9 * * * cd /path/to/HOTEL && /usr/bin/php scripts/daily_workbench_patrol_cron.php --base-url=http://127.0.0.1:8080 >> runtime/daily_workbench_patrol_cron.log 2>&1
```

Windows 任务计划动作示例：

```text
程序: C:\xampp\php\php.exe
参数: scripts\daily_workbench_patrol_cron.php --base-url=http://127.0.0.1:8080
起始于: D:\桌面\SUXIOS\宿析OS初始版\HOTEL
```

## 完成判定

第二阶段不能只用“接口存在”判定完成。必须同时证明：

- 当天或目标日有巡检健康状态。
- 多门店行能显示采集状态、可信字段、缺失字段、经营诊断、AI 解释和下一步动作。
- 至少一个动作能从巡检快照进入运营执行记录。
- 已执行动作能写回复盘结果。
- Markdown 报告能导出且不包含原始 OTA 响应、Cookie、Token、浏览器 Profile。
- 自动入口能通过命令或任务计划可复制运行。
- 健康状态能显式显示自动巡检是否配置；未配置时员工仍可使用人工巡检快照，但不能把它当作每日自动巡检完成。

## 验证命令

静态合同：

```bash
node scripts/verify_phase2_daily_workbench_contract.mjs
```

隔离运行时闭环验收：

```bash
php scripts/verify_phase2_daily_workbench_runtime.php
```

该运行时验收器使用临时 runtime，不写入真实巡检 `latest.json`，不访问携程/美团，不需要登录态，不触发任何 OTA 采集；它只证明宿析OS内部的快照、健康状态、AI 解释、动作跟踪、复盘和 Markdown 报告闭环可以运行。
