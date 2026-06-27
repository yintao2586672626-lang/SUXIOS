# 宿析OS第一阶段真实闭环证据包

Updated: 2026-06-12

## collection_source_summary contract

`inspect:phase1-live-closure` and `build:phase1-live-evidence` must both expose `collection_source_summary`.
This summary is read-only downstream state for employee review. It must not start Ctrip or Meituan collection, must not alter manual or automatic acquisition logic, and must not add, remove, or remap acquisition fields.

Each platform row must include:
- `platform`
- `storage_table`: fixed to `online_daily_data`
- `source_policy`: fixed to `read_existing_online_daily_data_only`
- `metric_scope`: fixed to `ota_channel`
- `target_date_rows`
- `target_date_data_types`
- `target_date_latest_trace_time`
- `latest_available`
- `latest_available_reference_only`
- `etl_status`
- `daily_facts`
- `traffic_rows`
- `metric_status`
- `collection_logic_changed`: fixed to `false`

`latest_available` may explain the nearest available stored OTA rows, but if its `date_relation` is not `target_date`, it is reference-only and cannot prove target-date collection success.

## 目标

本证据包用于判断第一阶段是否真的闭环，而不是只证明结构已经具备。

闭环必须覆盖：

```text
capture -> persistence -> UI display -> revenue metrics -> AI evidence -> operation execution
```

它不启动携程或美团采集，不改变采集字段、字段映射、入库结构或历史兼容口径。它只读取当前数据库和脱敏接口证据，明确输出已证明、缺失和未验证项。
巡检输出会把缺失项汇总到 `missing_requirements`，并同步生成员工可执行的 `next_actions`。`missing_requirements` 每条缺口必须直接暴露 `status=missing`、`platform`、`action_code`、`action_family`、`question_key`、`related_question_keys`、`resolves_missing_codes` 和 `live_closure_gap_codes`，让员工不需要二次关联就能知道缺口归属哪一个员工六问、下一步处理哪个动作、复跑时解除哪个缺口。`next_actions` 只用于说明下一步证据补齐、诊断或执行承接动作，不代表自动改价、自动改库存、自动改 OTA 后台，也不改变携程/美团既有手动和自动获取逻辑。

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

Markdown 巡检报告，适合员工/管理者阅读：

```powershell
npm.cmd run report:phase1-live-closure -- --date=2026-06-12
```

该命令等价于读取巡检器的 `--format=markdown` 输出。

严格验收，缺任一闭环证据即失败：

```powershell
npm.cmd run verify:phase1-live-closure -- --date=2026-06-12 --system_hotel_id=1 --evidence=reports/phase1_ota_live_closure_evidence.json
```

结构守卫：

```powershell
npm.cmd run verify:phase1-live-closure-contract
```

运行时动作队列守卫：

```powershell
npm.cmd run verify:phase1-live-action-queue -- --date=2026-06-12
```

该命令会只读运行巡检器并解析 JSON，允许当前业务证据仍为 `incomplete`，但必须证明 `employee_questions`、`closure_summary` 和结构化 `next_actions` 可被员工控制台或报告消费；同时校验 `latest_available` 只能作为参考，不能替代目标日采集证据。

构建统一证据包：

```powershell
npm.cmd run build:phase1-live-evidence -- --date=2026-06-12 --output=reports/phase1_ota_live_closure_evidence.json
```

证据包会输出 `collection_reliability.coverage_status`、`employee_questions`、结构化 `next_actions` 和 `closure_summary`，按员工六问标记已证明、部分缺失、缺失和下一步动作；部分平台有数据时必须保持 `partial`，不能写成全量采集成功。

## 员工控制台承载

员工六问状态必须通过只读下游承载面展示，不能变成页面本地文案：

- `/api/online-data/collection-reliability` 输出 `phase1_employee_questions`。
- dashboard 数据源输出同一份 `phase1_employee_questions`，状态策略为 `read_existing_collection_reliability_only`。
- `public/index.html` 在 `phase1-employee-six-question-summary` 中展示后端状态，并可叠加已加载的 OTA 诊断和运营执行证据。

这些承载面只读取已有采集可靠性、OTA 诊断和运营执行状态；不启动携程/美团采集，不改变手动或自动获取逻辑，不新增、删除或重映射获取字段。

## 最近可用数据说明

当目标日期没有同日 OTA 源数据时，巡检器会只读查询同平台、同酒店范围内最近可用的 `online_daily_data` 日期，并输出 `latest_available`：

- `target_date`：最近可用日期等于目标日期，可作为同日源数据证据。
- `stale_before_target`：最近可用日期早于目标日期，只能说明历史数据存在，不能证明今天已采到。
- `future_dated_for_target`：最近可用日期晚于目标日期，必须视为日期范围不一致，不能替代目标日闭环证据。

该查询只返回日期、行数、数据类型和脱敏 trace 摘要，不读取或展示原始 `raw_data` 内容。

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
| `*_source_rows_missing` | 默认使用携程/美团浏览器 Profile 采集入口补同日数据，再复跑巡检；手动 Cookie/API 仅作临时补数或排障 |
| `*_etl_not_ready` | 已有源数据后，检查标准事实层 accepted/rejected、`validation_flags` 和 `data_type` |
| `*_revenue_metrics_not_ready` | 检查收入、间夜、订单等最小指标输入，缺失时保留 `data_gaps` |
| `*_traffic_facts_missing` | 确认同日流量字段是否采到；未采到时流量/转化诊断必须标记不可用 |
| `ai_diagnosis_evidence_sample_missing` | 调用现有 OTA 诊断接口并附脱敏证据 JSON，必须包含 `evidence_sources`、`data_gaps`、`action_items` |
| `operation_execution_sample_missing` | 附一个真实执行意图或执行流程样例，包含审批、执行证据或复盘状态 |
| `operation_execution_ai_action_link_missing` | 已有执行意图或执行流程时，补齐 `source_module=ota_diagnosis`、`source=ota_diagnosis#...`、`evidence_refs` 或 `action_item_id`，证明动作来自 OTA 诊断 action_items |
| `operation_execution_evidence_incomplete` | 已有执行意图或执行流程样例时，补齐 `approval.status=approved`、`execution.status=executed`、`evidence.count>0` 或复盘状态之一 |

动作边界：不补 0、不伪造成成功、不复用过期证据证明当天闭环、不把 OTA 渠道诊断包装成全酒店经营事实。

`next_actions` 是员工可执行动作队列，不是采集器。每条动作必须至少暴露：
- `priority`：`high` / `medium` / `low`
- `status`：`missing` 表示可以直接补证据；`blocked` 表示必须先处理 `blocked_by`
- `action_code`：稳定动作编码
- `action_family`: stable semantic action group shared by employee console and live closure reports. `action_code` may differ by output layer.
- `question_key`：动作直接归属的员工六问键
- `related_question_keys`：动作会影响的员工六问键；用于把上游补证动作挂到字段、收益、AI 和运营问题上
- `entry`：现有核验或执行入口；用于指向补证、收益指标、AI 诊断或运营执行承接位置，不代表动作已经执行成功
- `success_criteria`：完成判定口径；用于说明复跑巡检时哪类证据能解除该动作，不代表当前已经成功
- `resolves_missing_codes`：当前动作理论上可解除的缺口编码；用于复跑前核对缺口，不代表缺口已经解除
- `live_closure_gap_codes`：实时巡检使用的缺口编码；用于把证据包、Markdown 报告和员工控制台对齐，不代表缺口已经解除
- `employee_explanation`：员工可读解释，说明为什么该动作存在
- `limited_conclusions`：当前缺口限制哪些经营结论
- `still_usable_metrics`：当前仍可查看或参考的指标范围
- `explanation_next_action`：员工下一步补证据动作，不能代替采集成功状态
- `owner`：建议负责人
- `evidence_needed`：复跑巡检前需要补齐的证据
- `blocked_by`：上游缺口编码
- `blocked_by_action_codes`：当前动作被阻断时应先处理的动作编码；只做执行顺序提示，不新增采集路径
- `protected_boundary`：不得改变携程/美团手动或自动获取逻辑，不得改变获取字段和字段映射

`employee_questions` 每行必须暴露 `key` 和 `next_action_codes`。`next_action_codes` 只引用同一输出里的 `next_actions.action_code`，用于让员工从六问直接定位要处理的动作；它不能作为采集成功、AI 建议可信或运营闭环完成的证据。

排序规则：`missing` 动作先于 `blocked` 动作；同状态下先按动作链路阶段排序：`evidence_scope` → `target_date_source_rows` → `standard_facts` → `revenue_metric_inputs` → `traffic_conversion_facts` → `ai_diagnosis_evidence` → `operation_execution_evidence`，再按 `high`、`medium`、`low` 和动作编码排序。闭环摘要的 `top_action_code` 必须与 `next_actions[0].action_code` 保持一致，避免员工看到两个不同的首要动作。

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
  },
  "employee_questions": [],
  "closure_summary": {
    "status": "incomplete",
    "metric_scope": "ota_channel",
    "protected_boundary": "不改变携程/美团手动或自动获取逻辑，不改变获取字段和字段映射"
  }
}
```

## 当前边界

- 没有真实当天携程/美团样本时，只能证明结构具备，不能证明“今天已采到”。
- 没有 `evidence_sources` 的 AI 输出，不能证明 AI 建议可信。
- 没有执行意图或执行流样例，不能证明运营闭环完成。
- 只有 blocked/pending 的执行意图、空执行流或阶段列表，不能证明“下一步动作”已经进入运营闭环。
- 字段缺口继续按 `docs/phase1_ota_gap_explanation_matrix.md` 暴露，不允许用兜底值消解。
