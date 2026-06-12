# 宿析OS第一阶段真实闭环证据包

Updated: 2026-06-12

## 目标

本证据包用于判断第一阶段是否真的闭环，而不是只证明结构已经具备。

闭环必须覆盖：

```text
capture -> persistence -> UI display -> revenue metrics -> AI evidence -> operation execution
```

它不启动携程或美团采集，不改变采集字段、字段映射、入库结构或历史兼容口径。它只读取当前数据库和脱敏接口证据，明确输出已证明、缺失和未验证项。
巡检输出会把缺失项汇总到 `missing_requirements`，并同步生成员工可执行的 `next_actions`。`next_actions` 只用于说明下一步证据补齐、诊断或执行承接动作，不代表自动改价、自动改库存、自动改 OTA 后台，也不改变携程/美团既有手动和自动获取逻辑。

## 最小验收范围

| 项 | 要求 |
|---|---|
| 日期 | 一个真实业务日期，默认当天 |
| 平台 | `ctrip`、`meituan`，或通过 `--platform` 指定其中一个 |
| 酒店 | 至少指定一个系统酒店或 OTA 酒店，未指定时只做日期平台范围检查 |
| 数据源 | `online_daily_data` 中对应日期、平台、酒店的真实入库行 |
| 展示 | 收益指标必须暴露 `metric_trust` 和 `data_gaps` |
| 诊断 | `/api/agent/ota-diagnosis` 返回的 `evidence_sources`、`data_gaps`、`action_items` |
| 执行 | `/api/operation/execution-intents` 或 `/api/operation/execution-flow` 的真实执行意图/流程样例 |

## 员工六问到证据

| 员工问题 | 证据来源 | 不能接受的替代 |
|---|---|---|
| 今天 OTA 数据有没有采到 | `online_daily_data` 同日期同平台行数、trace、入库状态 | 只看按钮成功、toast 成功或本地 mock |
| 哪些字段可信 | `metric_trust`、字段 trace、字段资产 ledger | 把缺字段指标显示成绿色可信 |
| 哪些字段缺失 | `data_gaps`、`validation_flags`、字段缺口解释矩阵 | 用 0、空值、默认成功掩盖 |
| 收入/流量/转化出了什么问题 | `OtaRevenueMetricService` 输出的 `totals`、`traffic`、`advertising`、`quality` | 没有真实行时生成经营结论 |
| AI 建议依据是什么 | OTA 诊断 `evidence_sources`、`data_gaps`、`action_items` | AI 只有建议文案，没有证据 |
| 下一步该执行什么动作 | 运营执行意图、审批状态、执行证据、复盘状态 | 只有建议卡片，没有执行承接 |

## 命令

只读巡检，不因缺真实样本失败：

```powershell
npm.cmd run inspect:phase1-live-closure -- --date=2026-06-12
```

严格验收，缺任一闭环证据即失败：

```powershell
npm.cmd run verify:phase1-live-closure -- --date=2026-06-12 --system_hotel_id=1 --evidence=reports/phase1_ota_live_closure_evidence.json
```

结构守卫：

```powershell
npm.cmd run verify:phase1-live-closure-contract
```

## 参数

| 参数 | 说明 |
|---|---|
| `--date=YYYY-MM-DD` | 验收日期 |
| `--platform=ctrip` 或 `--platform=meituan` | 只验收单个平台；不传则验收携程和美团 |
| `--hotel_id=<ota_hotel_id>` | OTA 酒店 ID |
| `--system_hotel_id=<id>` | 系统酒店 ID |
| `--evidence=<path>` | 真实接口证据 JSON，用于证明 AI 诊断和运营执行闭环 |
| `--limit=<n>` | 每个平台最多读取的入库行数，最大 5000 |
| `--strict` | 严格模式；缺证据返回失败 |

## 巡检输出动作口径

| 缺失项 | 下一步动作 |
|---|---|
| `*_source_rows_missing` | 使用现有携程/美团手动或自动获取入口补同日数据，再复跑巡检 |
| `*_etl_not_ready` | 已有源数据后，检查标准事实层 accepted/rejected、`validation_flags` 和 `data_type` |
| `*_revenue_metrics_not_ready` | 检查收入、间夜、订单等最小指标输入，缺失时保留 `data_gaps` |
| `*_traffic_facts_missing` | 确认同日流量字段是否采到；未采到时流量/转化诊断必须标记不可用 |
| `ai_diagnosis_evidence_sample_missing` | 调用现有 OTA 诊断接口并附脱敏证据 JSON，必须包含 `evidence_sources`、`data_gaps`、`action_items` |
| `operation_execution_sample_missing` | 附一个真实执行意图或执行流程样例，包含审批、执行证据或复盘状态 |

动作边界：不补 0、不伪造成成功、不复用过期证据证明当天闭环、不把 OTA 渠道诊断包装成全酒店经营事实。

## 证据 JSON 结构

`--evidence` 指向的文件必须来自真实接口响应的脱敏整理，不能手写成功状态。

```json
{
  "scope": {
    "date": "2026-06-12",
    "platform": "ctrip",
    "system_hotel_id": 1,
    "hotel_id": "ota-hotel-id"
  },
  "ota_diagnosis": {
    "source": "/api/agent/ota-diagnosis",
    "evidence_sources": [],
    "data_gaps": [],
    "action_items": []
  },
  "operation_execution": {
    "source": "/api/operation/execution-intents",
    "execution_intents": [],
    "execution_flow": {}
  }
}
```

## 当前边界

- 没有真实当天携程/美团样本时，只能证明结构具备，不能证明“今天已采到”。
- 没有 `evidence_sources` 的 AI 输出，不能证明 AI 建议可信。
- 没有执行意图或执行流样例，不能证明运营闭环完成。
- 字段缺口继续按 `docs/phase1_ota_gap_explanation_matrix.md` 暴露，不允许用兜底值消解。
