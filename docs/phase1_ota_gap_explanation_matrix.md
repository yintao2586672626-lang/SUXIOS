# 宿析OS第一阶段 OTA 字段缺口解释矩阵

Updated: 2026-06-12

## 目标

字段缺口不是报错文案，也不是可以被 0、空值或成功状态盖过去的细节。第一阶段必须把字段缺口解释成：哪些经营结论不能下、哪些指标仍可看、下一步补什么证据。

本矩阵只定义解释口径，不改变携程/美团手动获取逻辑，不改变自动获取逻辑，不改变获取字段，不改变 `online_daily_data` 入库结构。

## P0 缺口解释

| gap code | 员工可见解释 | 被限制的指标/结论 | 仍可使用的指标 | 下一步动作 |
|---|---|---|---|---|
| `available_room_nights_missing` | 可售间夜缺失，系统不能判断真实出租率和可售产能。 | OCC、RevPAR、Net RevPAR、满房能力、可售产能相关结论 | 已采收入、间夜、客单、ADR | 补充 PMS/房量或 OTA 可售间夜来源，再重算收益指标 |
| `commission_fields_missing` | 佣金字段缺失，系统不能判断平台扣点和真实到手收入。 | 佣金金额、佣金率、渠道净收入、渠道利润率 | GMV、间夜、客单、ADR | 补充平台佣金、扣点或账单口径 |
| `net_revenue_fields_missing` | 净收入字段缺失，系统不能判断扣佣后的真实收益。 | Net RevPAR、净收入贡献、渠道净利润 | 总收入、间夜、客单、ADR | 补充净收入或佣金后重算 |
| `cancellation_fields_missing` | 取消字段缺失，系统不能判断订单质量和取消风险。 | 取消率、间夜取消率、取消损失、售后风险 | 有效收入、间夜、客单的已采口径 | 补充取消订单数、取消间夜或平台取消率 |
| `competitor_price_fields_missing` | 竞品价格字段缺失，系统不能判断价差和价格竞争力。 | 竞品价差、价差率、价格带竞争判断、调价依据 | 自身价格、收入、间夜、客单 | 补采竞品价格或榜单价格证据 |

## 现场闭环缺口补充

这些缺口来自第一阶段实时巡检 `missing_requirements`，用于解释“为什么员工六问仍是 warning / incomplete”。它们只描述证据缺口和后续动作，不改变携程/美团手动或自动获取逻辑，不改变获取字段和字段映射。

| gap code | 员工可见解释 | 被限制的指标/结论 | 仍可使用的指标 | 下一步动作 |
|---|---|---|---|---|
| `ctrip_traffic_facts_missing` | 携程目标日缺少流量/转化事实，不能判断曝光、访问、下单链路是否异常。 | 携程流量、转化率、漏斗诊断、AI 对流量问题的确定结论 | 已采到的携程收入、间夜、订单等收益事实 | 使用现有携程流量获取入口补齐目标日流量事实，复跑巡检 |
| `ctrip_source_rows_missing` | 携程目标日没有同日 OTA 源数据行，不能证明今天携程数据已采到。 | 携程收入、流量、转化、字段可信度和 AI 诊断 | 携程最近可用历史数据只能作参考，不能替代目标日 | 使用现有携程手动或自动获取入口补齐目标日源数据 |
| `ctrip_etl_not_ready` | 携程源数据没有形成可读的标准事实层，不能进入统一收益诊断。 | 携程标准事实、收益指标、字段可信判断 | 已保存的原始/历史参考状态和采集日志 | 复核现有携程 ETL 输入、data_type、raw_data 标准化证据 |
| `ctrip_revenue_metrics_not_ready` | 携程收益指标未就绪，不能计算携程收入、间夜、客单等经营结论。 | 携程收益、ADR、订单、间夜和相关 AI 建议 | 其它已 ready 平台的收益指标可单独复核 | 补齐携程目标日源数据和标准事实后复跑收益指标 |
| `meituan_source_rows_missing` | 美团目标日没有同日 OTA 源数据行，不能证明今天美团数据已采到。 | 美团收入、流量、转化、字段可信度和 AI 诊断 | 美团最近可用历史数据只能作参考，不能替代目标日 | 使用现有美团手动或自动获取入口补齐目标日源数据 |
| `meituan_etl_not_ready` | 美团源数据没有形成可读的标准事实层，不能进入统一收益诊断。 | 美团标准事实、收益指标、字段可信判断 | 已保存的原始/历史参考状态和采集日志 | 复核现有美团 ETL 输入、data_type、raw_data 标准化证据 |
| `meituan_revenue_metrics_not_ready` | 美团收益指标未就绪，不能计算美团收入、间夜、客单等经营结论。 | 美团收益、ADR、订单、间夜和相关 AI 建议 | 其它已 ready 平台的收益指标可单独复核 | 补齐美团目标日源数据和标准事实后复跑收益指标 |
| `meituan_traffic_facts_missing` | 美团目标日缺少流量/转化事实，不能判断曝光、访问、转化链路。 | 美团流量、转化率、漏斗诊断、AI 对流量问题的确定结论 | 美团历史参考行只能说明最近有数据，不证明目标日流量 | 使用现有美团流量获取入口补齐目标日流量事实 |
| `ai_diagnosis_evidence_sample_missing` | 尚未提供真实 OTA 诊断证据样例，不能证明 AI 建议已有证据来源、数据缺口和动作项支撑。 | AI 建议依据、自动动作项、运营执行前置判断 | 已验证的 OTA 数据缺口和字段缺口可作为补证据清单 | 调用现有 OTA 诊断接口并附脱敏证据 JSON，必须包含证据来源、数据缺口和动作项 |
| `ai_diagnosis_action_items_blocked` | AI 诊断已有阻断依据，但 action_items 不能作为可执行经营建议。 | AI 自动建议、执行意图创建、运营闭环完成判断 | 阻断原因、证据来源和 data_gaps 可作为补证据清单 | 先解除上游 OTA 缺口，再重新生成包含非 blocked action_items 的诊断 |
| `operation_execution_sample_missing` | 尚无能追溯到 OTA 诊断的执行意图、审批、执行证据或复盘样例。 | 运营执行闭环、动作完成、复盘和 ROI 判断 | 下一步动作和阻断链可见，但不能算执行完成 | 取得可执行 AI action_items 后，创建或附上执行意图和证据 |
| `operation_execution_ai_action_link_missing` | 已有执行相关数据，但未能追溯到 OTA 诊断 action_items，不能证明这一步是 AI 建议的运营承接。 | AI 建议执行承接、运营执行闭环、动作完成归因 | 普通执行流可作为运营参考，OTA 诊断缺口和动作队列仍可作为待处理清单 | 将执行意图或执行流程的 source/evidence 关联到 OTA 诊断 action_items，再补齐审批、执行证据或复盘 |
| `operation_execution_evidence_incomplete` | 已有可追溯到 OTA 诊断的执行意图或执行流程，但缺少审批、执行证据、复盘或 ROI 信号。 | 运营执行闭环、动作完成、复盘和 ROI 判断 | 执行意图本身和阻断原因可用于跟进，但不能算闭环完成 | 补齐 approval.status=approved、execution.status=executed、evidence.count>0、review.status 或 ROI 信号 |

## 展示规则

- 缺口必须进入 `data_gaps`，不能只写在日志里。
- 指标可信度必须进入 `metric_trust`，不能把缺字段指标显示成已可信。
- 不能用 0、空值、默认成功、绿色状态或本地兜底分析掩盖缺口。
- 被缺口限制的指标必须显示 `not_calculable_when` 或等价说明。
- AI 诊断引用这些指标时，必须把缺口写入依据或限制条件。
- 运营动作如果依赖受限指标，必须先标注“需补证据”或进入阻塞状态。

## 员工判断模板

```text
已采到：收入、间夜、客单、ADR。
未证明：OCC、RevPAR、Net RevPAR、取消率、竞品价差。
原因：缺少 available_room_nights / commission / net_revenue / cancellation / competitor_price 等字段证据。
下一步：补充对应 OTA/PMS/账单/竞品价格来源后重跑收益指标和 AI 诊断。
```

## 验证命令

```powershell
npm.cmd run verify:phase1-gap-explanations
```

该命令只做结构化只读检查，不访问外部平台，不启动采集，不写数据库。
