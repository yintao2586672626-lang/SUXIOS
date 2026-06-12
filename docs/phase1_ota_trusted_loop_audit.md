# 宿析OS第一阶段 OTA 可信闭环审计

Updated: 2026-06-12

## 当前结论

第一阶段已经具备结构化基础，但不能判定为业务完成。

已闭合的部分：

- 目标、边界、验收口径已固化到 `docs/phase1_ota_trusted_loop_goal.md`。
- 员工视角验收已固化到 `docs/phase1_ota_employee_console_acceptance.md`。
- 字段缺口解释已固化到 `docs/phase1_ota_gap_explanation_matrix.md`。
- 美团手动批量 direct result / queued 显式异常合同已闭合，不能再把后台异步 accepted 状态当成当前成功。
- `verify:public-entry` 和 `verify:e2e-contracts` 已作为前端入口和合同守卫。

仍未完成的 P0 证据：

- 真实当天携程/美团采集结果尚未完成端到端证明：capture -> persistence -> UI display。
- AI 诊断还缺真实当天 OTA 证据样例：`evidence_sources`, `data_gaps`, `action_items` 必须来自真实采集或明确标注样例边界。
- 运营执行闭环还缺真实样例：执行意图、审批、执行证据、复盘状态必须有可追踪记录。

## 2026-06-12 当天巡检结果

只读巡检命令：

```powershell
npm.cmd run report:phase1-live-closure -- --date=2026-06-12
```

巡检结论：`incomplete`。

已证明：

- ThinkPHP 应用可初始化。
- `online_daily_data` 表存在。
- `online_daily_data` 核心列存在。
- 收益指标层已暴露 `metric_trust`，员工侧字段可信状态有承载口。
- 收益指标层已暴露 `data_gaps`，字段缺失状态有承载口。

仍缺失：

- `ctrip` 在 `2026-06-12` 没有同日 OTA 源数据行。
- `meituan` 在 `2026-06-12` 没有同日 OTA 源数据行。
- 携程、美团同日 ETL 状态均为 `empty`，不能证明标准事实层已产出。
- 携程、美团同日收益指标状态均为 `empty`，不能证明收入、间夜、订单、ADR、RevPAR 等经营指标可用。
- 携程、美团同日流量事实缺失，不能证明流量/转化诊断可用。
- 未提供 OTA 诊断证据 JSON，不能证明 AI 输出包含 `evidence_sources`, `data_gaps`, `action_items`。
- 未提供运营执行证据 JSON，不能证明执行意图、审批、执行证据、复盘状态已闭合。

当前判断：第一阶段结构基础已具备，但业务闭环不能声明完成。P0 仍然是补齐真实同日携程/美团样例，证明 `采集 -> 保存 -> 展示 -> AI 证据 -> 执行动作`。

## 审计状态

| 检查项 | 当前状态 | 说明 |
|---|---|---|
| `npm.cmd run verify:phase1-ota-loop` | passed | 第一阶段目标、路由、收益指标、AI 诊断、运营执行结构守卫 |
| `npm.cmd run verify:phase1-employee-console` | passed | 员工六问、UI/接口承载面和不完成口径守卫 |
| `npm.cmd run verify:phase1-gap-explanations` | passed | P0 字段缺口、指标限制和禁止兜底规则守卫 |
| `npm.cmd run verify:phase1-live-closure-contract` | passed | 真实闭环证据检查器结构守卫 |
| `npm.cmd run verify:public-entry` | passed | 前端入口结构守卫 |
| `npm.cmd run verify:e2e-contracts` | passed | 端到端合同守卫 |

## 下一步只做 P0/P1/P2

1. P0：补真实当天携程/美团采集样例，证明采集、保存、UI 展示三段闭合。
2. P0：补 AI 诊断真实证据样例，确保建议引用真实 OTA 指标、字段缺口和数据范围。
3. P1：补运营执行样例，证明建议能形成执行意图、审批、执行证据和复盘。
4. P2：把字段缺口解释接入更多员工可见位置，但不改变采集字段和入库结构。

当前真实闭环巡检入口：

```powershell
npm.cmd run inspect:phase1-live-closure -- --date=2026-06-12
npm.cmd run verify:phase1-live-closure -- --date=2026-06-12 --system_hotel_id=1 --evidence=reports/phase1_ota_live_closure_evidence.json
```

## 非目标

- 不重写携程/美团采集逻辑。
- 不新增 OTA 明细表或改变 `online_daily_data` 结构。
- 不把 OTA 渠道数据包装成全酒店经营事实。
- 不做无基准的性能提升声明。

## 验证命令

```powershell
npm.cmd run verify:phase1-ota-audit
```

该命令只做结构化只读检查，不启动 OTA 采集，不访问外部平台，不写数据库。
