# P0 决策到执行闭环补强方案

> 目标：把“分析后给建议”升级为“建议可审批、可执行、可追踪、可复盘”的运营闭环。本文先固定产品与字段契约，不直接假设 OTA 平台已开放自动调价、房态或投放 API。

## 0. 产品边界

第一阶段只做一个主闭环：

```text
OTA 数据同步 -> 收益诊断 -> 运营建议 -> 动作追踪 -> 效果复盘
```

| 模块 | 当前定位 |
|---|---|
| OTA 数据 | 主闭环数据入口，优先保证携程、美团数据可同步、可校验、可追溯。 |
| 收益诊断 | 主闭环分析层，统一输出收入、订单、间夜、ADR、RevPAR、转化率等运营指标。 |
| AI 能力 | 嵌入式能力，只用于解释、诊断和建议生成，不作为独立产品线扩张。 |
| 运营管理 | 主闭环执行层，承接预警、策略模拟、动作追踪和复盘。 |
| 筹建/开业/扩张/转让/投资测算 | 二期辅助模块，保留入口和记录能力，暂不作为第一阶段深闭环。 |

## 1. 问题定义

| 项目 | 结论 |
|---|---|
| 优先级 | P0 |
| 当前短板 | 系统能做收益分析、根因定位、策略模拟和动作追踪，但缺少稳定的价格、房态、活动投放执行链。 |
| 竞品参照 | 按当前输入：SiteMinder 强调定价和分销自动化；Duetto、Infor、RevMate 强调预测和价格建议。宿析OS需要补齐“建议到执行”的闭环能力。 |
| 核心边界 | 没有真实 OTA 执行字段、授权和回写结果前，不做假自动化；必须暴露阻塞原因。 |

## 2. 现有可复用基础

| 能力 | 当前位置 | 可复用点 | 不足 |
|---|---|---|---|
| 全维数据 | `/api/operation/full-data` | 聚合经营、OTA、竞对、点评数据 | 不是执行入口 |
| 根因定位 | `/api/operation/root-cause` | 输出原因、证据、建议动作 | 建议未结构化为执行单 |
| 策略模拟 | `/api/operation/strategy-simulation` | 支持调价、促销、房量、竞对跟价、节假日策略 | 只返回模拟结果，不生成执行任务 |
| 动作追踪 | `/api/operation/actions`、`/api/operation/action-tracking` | 已有执行前后数据对比框架 | 缺审批、平台任务、执行证据、失败原因 |
| 定价建议模型 | `PriceSuggestion` | 有待审批、已批准、已应用等状态概念 | 尚未形成通用执行链 |

## 3. P0 推荐方案

先做“人工确认 + 系统留痕 + 效果复盘”的执行闭环，再逐步接入浏览器辅助或 OTA API 自动执行。

```mermaid
flowchart LR
    A["数据与根因"] --> B["策略建议"]
    B --> C["执行意图"]
    C --> D["审批"]
    D --> E["执行任务"]
    E --> F["执行证据"]
    F --> G["效果复盘"]
    G --> H["策略沉淀"]
```

## 4. 执行链路

| 环节 | 必须能力 | 状态要求 |
|---|---|---|
| 执行意图 | 把根因、策略模拟、AI建议转为结构化执行单 | `draft`、`pending_approval` |
| 审批 | 记录审批人、审批时间、驳回原因、风险提示 | `approved`、`rejected` |
| 执行 | 支持价格、房态、活动投放三类任务 | `pending_execute`、`executing`、`blocked`、`executed`、`failed` |
| 证据 | 保存执行前值、目标值、执行后回填值、截图/备注/平台返回 | 不允许空成功 |
| 复盘 | 执行满观察期后对比订单、收入、间夜、转化、ADR、RevPAR | `observing`、`success`、`near_success`、`failed` |

## 5. 三类执行对象

| 对象 | P0 字段 | 关键校验 |
|---|---|---|
| 价格 | `platform`、`hotel_id`、`room_type_key`、`rate_plan_key`、`date_start`、`date_end`、`current_price`、`target_price`、`min_price`、`max_price` | 目标价不得越过价格保护线；缺少房型或价型映射时标记阻塞 |
| 房态 | `platform`、`hotel_id`、`room_type_key`、`date_start`、`date_end`、`current_inventory`、`target_inventory`、`sell_status` | 不得把未知库存当作 0；缺少库存来源时不允许执行 |
| 活动投放 | `platform`、`hotel_id`、`campaign_type`、`date_start`、`date_end`、`discount_rate`、`budget`、`target_metric` | 必须有目标指标和观察期；缺少活动规则时标记人工确认 |

## 6. 最小数据契约

| 表/对象 | 用途 | 核心字段 |
|---|---|---|
| `operation_execution_intents` | 保存建议转执行的结构化意图 | `source_module`、`source_record_id`、`hotel_id`、`platform`、`object_type`、`action_type`、`evidence_json`、`expected_metric`、`expected_delta`、`risk_level`、`status` |
| `operation_execution_tasks` | 保存具体执行任务与状态 | `intent_id`、`execution_mode`、`operator_id`、`target_value_json`、`current_value_json`、`blocked_reason`、`executed_at`、`status` |
| `operation_execution_evidence` | 保存执行证据 | `task_id`、`evidence_type`、`before_json`、`after_json`、`attachment_path`、`platform_response_json`、`remark` |
| `operation_action_tracks` | 复用现有效果追踪 | 关联 `intent_id` 或 `task_id`，继续评估执行前后指标 |

## 7. API 补强顺序

| 优先级 | 接口 | 说明 |
|---|---|---|
| P0-1 | `POST /api/operation/execution-intents` | 从根因或策略模拟结果生成执行意图 |
| P0-2 | `GET /api/operation/execution-intents` | 执行池列表，按酒店、平台、对象、状态筛选 |
| P0-3 | `POST /api/operation/execution-intents/:id/approve` | 审批通过或驳回 |
| P0-4 | `POST /api/operation/execution-tasks/:id/execute` | 记录人工执行、浏览器辅助执行或 API 执行结果 |
| P0-5 | `POST /api/operation/execution-tasks/:id/evidence` | 补充执行证据，不允许无证据标记成功 |
| P0-6 | `POST /api/operation/execution-tasks/:id/review` | 生成效果复盘并回写状态 |

## 8. 前端最小闭环

| 页面 | 改动 |
|---|---|
| `ops-insight` | 在根因建议旁增加“生成执行单”入口 |
| `ops-plan` | 策略模拟结果支持生成执行意图，带入目标指标、预期变化、风险等级 |
| `ops-track` | 从普通动作追踪升级为执行池：待审批、待执行、已阻塞、观察中、已复盘 |
| 全局提示 | 当字段、授权或平台映射缺失时，显示明确阻塞原因，不显示“已执行成功” |

## 9. 验收标准

1. 任一价格/房态/活动建议，都能生成结构化执行意图。
2. 未审批的执行意图不能进入执行状态。
3. 缺少 OTA 平台字段、房型映射、价型映射或授权时，状态必须是 `blocked`，并记录 `blocked_reason`。
4. 执行成功必须有执行证据，不能只改本地状态。
5. 执行满观察期后，系统能复盘 `orders`、`revenue`、`room_nights`、`conversion`、`adr`、`revpar`。
6. 指标口径必须区分 OTA 渠道口径和全店经营口径。

## 10. 注意事项

- 不直接把建议等同于执行，必须经过审批或明确的人机协同规则。
- 不用空数据、默认 0、静默 catch 或假成功掩盖 OTA 执行失败。
- 自动执行能力只能在字段字典、授权、平台回写和审计链稳定后接入。
- P0 阶段优先闭合业务状态链，自动化执行可以作为 P1/P2 分层推进。
