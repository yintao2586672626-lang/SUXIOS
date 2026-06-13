# 宿析OS 第三阶段运营闭环验收

## 目标

第三阶段目标是建立：

```text
巡检异常 -> 运营动作 -> 执行证据 -> 效果复盘 -> SOP沉淀 -> 多店复制
```

本阶段不追求 AI 自动经营酒店，也不改变携程、美团手动或自动数据获取逻辑。第三阶段只把已存在的 Phase 2 巡检快照、动作状态、运营执行记录和 OTA 指标窗口组织成可复盘的运营闭环。

## 范围边界

| 项目 | 要求 |
| --- | --- |
| 数据范围 | 仅 OTA channel scope，不代表全酒店经营事实 |
| 来源 | 已有每日工作台巡检快照、动作跟踪、运营执行记录、`online_daily_data` 指标窗口 |
| 采集逻辑 | 不变 |
| 采集字段 | 不变 |
| AI 自动执行 | 禁止 |
| 原始数据 | 不输出 `raw_data`、Cookie、token、Profile 或平台敏感凭据 |
| 结果判断 | 无执行证据、无复盘、无指标窗口时必须显式标记，不得兜底为成功 |

## 接口

```http
GET /api/online-data/phase3-operation-effect-loop
GET /api/online-data/phase3-operation-effect-loop/ledger
POST /api/online-data/phase3-operation-effect-loop/sops/publish
POST /api/online-data/phase3-operation-effect-loop/replications/create
```

可选参数：

| 参数 | 说明 |
| --- | --- |
| `run_id` | 指定 Phase 2 巡检快照，不传则读取最新快照 |
| `target_date` | 指标窗口目标日期，不传则读取快照目标日期 |
| `limit` | 返回动作数量，默认 100，最大 300 |

## 输出合同

顶层必须包含：

| 字段 | 要求 |
| --- | --- |
| `phase` | `phase3_operation_effect_loop` |
| `scope.metric_scope` | `ota_channel` |
| `scope.collection_logic_changed` | `false` |
| `scope.collection_fields_changed` | `false` |
| `scope.auto_decision_enabled` | `false` |
| `summary` | 六段闭环的统计 |
| `rows[].stages.anomaly` | 巡检异常 |
| `rows[].stages.operation_action` | 运营动作 |
| `rows[].stages.execution_evidence` | 执行证据 |
| `rows[].stages.effect_review` | 效果复盘 |
| `rows[].stages.sop` | SOP 候选 |
| `rows[].stages.replication` | 多店复制候选 |
| `boundaries.protected_boundary` | 明确不改变携程/美团采集逻辑、字段、路由或入库映射 |

## 状态规则

### 执行证据

| 状态 | 含义 |
| --- | --- |
| `execution_missing` | 动作未跟踪，缺执行记录 |
| `execution_in_progress` | 已跟踪但未完成 |
| `done_without_execution_task` | 操作标记完成，但没有可验证执行任务 |
| `executed_evidence_recorded` | 已生成执行任务且任务状态为 executed |
| `skipped` | 人工跳过 |

### 效果复盘

| 状态 | 含义 |
| --- | --- |
| `execution_missing` | 执行证据缺失 |
| `execution_incomplete` | 执行未完成或证据不完整 |
| `review_missing` | 可复盘但还没有人工复盘结论 |
| `observing` | 已复盘但仍观察中 |
| `reviewed` | 已有 `success`、`near_success` 或 `failed` 复盘结果 |

效果复盘不得自动声称因果关系。即使指标改善，也必须保留 `causality_claimed=false`。

当目标日或前一日 OTA 指标窗口缺失时，必须返回 `metric_window_missing`，不得把复盘结果包装成已验证的因果改善。

### SOP 候选

只有同时满足以下条件，才允许进入 `sop.status=candidate`：

1. 执行证据状态为 `executed_evidence_recorded`
2. 复盘结果为 `success` 或 `near_success`
3. 指标窗口状态为 `ready`

否则必须返回 `not_ready` 和明确 `reason_codes`。

候选 SOP 需要通过 `POST /api/online-data/phase3-operation-effect-loop/sops/publish` 沉淀到 runtime 台账。台账只保存动作摘要、证据引用、复盘结果、指标窗口状态和操作步骤，不保存 OTA 原始响应或登录凭据。

### 多店复制候选

只有 SOP 已是候选，且同一巡检快照内存在相同 `question_key`、`action_code` 或数据缺口代码的其他门店，才允许进入 `replication.status=candidate`。

复制候选仅是建议，不自动执行，必须保留 `auto_apply_enabled=false`。

复制计划通过 `POST /api/online-data/phase3-operation-effect-loop/replications/create` 生成草稿，状态为 `draft`。草稿必须保留人工确认项，不允许自动应用到 OTA 后台。

## 验证命令

```powershell
npm.cmd run verify:phase3-operation-effect-loop
C:\xampp\php\php.exe scripts\verify_phase3_operation_effect_loop_runtime.php
```

相关回归：

```powershell
C:\xampp\php\php.exe -l app\service\Phase3OperationEffectLoopService.php
C:\xampp\php\php.exe -l app\controller\OnlineData.php
C:\xampp\php\php.exe scripts\verify_route_coverage.php
```
