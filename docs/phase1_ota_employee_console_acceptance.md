# 宿析OS第一阶段员工视角验收

Updated: 2026-06-12

## 目标

第一阶段不是让 AI 自动管理酒店，而是让一线员工每天打开系统后，能判断 OTA 经营数据是否可信、哪些结论不能下、下一步该补什么证据。

员工视角必须回答六个问题：

1. 今天携程、美团 OTA 数据有没有采到。
2. 哪些字段可信，证据来自哪里。
3. 哪些字段缺失、失败、未授权或未采集。
4. 收入、流量、转化出了什么问题。
5. AI 建议依据了哪些 OTA 数据、指标和缺口。
6. 下一步该执行什么动作，是否需要审批、执行证据和复盘。

## 验收面

| 问题 | 系统承载面 | 当前证据 | 完成口径 |
|---|---|---|---|
| 今天有没有采到 | 数据健康 / 采集可靠性 | `/api/online-data/collection-reliability`, `collectionHealthSummaryCards` | 能看到平台、门店、采集状态、授权状态、最近采集日志 |
| 哪些字段可信 | 字段资产 ledger | `collectionHealthFieldAssetCards`, 携程 Profile 字段 ledger | 能区分稳定字段、未返回字段、禁止采集字段 |
| 哪些字段缺失 | 数据质量和缺口 | `data_quality`, `missing_count`, `field_missing`, `data_gaps` | 缺字段、授权失败、未采集必须显式展示 |
| 收入/流量/转化问题 | OTA 标准收益指标 | `/api/ota-standard/revenue-metrics`, `OtaRevenueMetricService` | 能输出收入、间夜、客单、ADR、流量转化和数据缺口 |
| AI 建议依据 | OTA 诊断 | `/api/agent/ota-diagnosis`, `evidence_sources`, `action_items`, `source_policy` | AI 建议必须引用证据和数据缺口 |
| 下一步动作 | 运营执行闭环 | `/api/operation/execution-intents`, `/api/operation/execution-flow` | 建议进入执行意图，阻塞、审批、证据、复盘可追踪 |

## 不完成口径

- 只要 `verify:public-entry` 或 `verify:e2e-contracts` 失败，就不能声明前端入口完整。
- 只要没有真实当天携程/美团采集样例，就不能声明“今天数据已可信采到”。
- 只要 AI 诊断没有带真实 `evidence_sources` 和 `data_gaps` 样例，就不能声明 AI 建议闭环完成。
- 只要执行意图没有真实审批、执行证据和复盘样例，就不能声明运营闭环完成。
- 不允许用空值、默认值、成功文案或本地兜底分析替代缺失、失败、未授权、未采集状态。
- 字段缺口解释必须遵循 `docs/phase1_ota_gap_explanation_matrix.md`，并通过 `verify:phase1-gap-explanations`。

## 验证命令

```powershell
npm.cmd run verify:phase1-employee-console
```

该命令只做结构化只读检查，不启动 OTA 采集，不访问外部平台，不写数据库，不改变携程/美团手动或自动获取逻辑。
