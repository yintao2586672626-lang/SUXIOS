# 系统之美复盘与 AI 性能稳定性设计

> 目标：把《系统之美》的系统思维转成宿析OS可执行的 AI 决策与工程稳定性原则。

## 1. 核心复盘

《系统之美》的核心不是“复杂系统很复杂”，而是：系统行为通常来自结构，不来自单点事件。宿析OS要避免把 OTA 数据、AI 建议、运营动作和投资测算做成散点功能，而要把它们纳入一个可观测、可纠偏、可复盘的经营系统。

| 系统概念 | 宿析OS含义 | 设计结论 |
|---|---|---|
| 要素 | OTA 数据、日报、竞对、点评、AI 结论、执行动作、投资项目。 | 要素必须有来源、归属、时间、口径和状态。 |
| 连接 | 采集、清洗、指标计算、诊断、建议、审批、执行、复盘。 | 真正的能力在连接关系，不在单个页面或单次模型调用。 |
| 目标 | 收益质量、运营效率、评分稳定、现金流、投资回报。 | 不用单一订单量或短期收入作为系统唯一目标。 |
| 反馈 | 动作改变市场表现，市场表现再进入下一轮诊断。 | 建议必须进入执行与复盘，否则只是文本输出。 |
| 延迟 | 调价、投放、服务、点评、投资回本都有滞后。 | 所有关键动作必须有观察期和复盘窗口。 |
| 杠杆点 | 信息流、规则、目标函数、权限、评估机制。 | 优先改系统结构，不靠更多提示词堆叠解决问题。 |

## 2. 对宿析OS的第一性原则

```text
OTA 数据
-> 收益分析
-> AI 决策建议
-> 运营管理
-> 效果复盘
-> 投资决策
```

这条链路的本质是反馈回路：

1. OTA 和日报数据提供外部市场反馈。
2. 收益分析把反馈转为可解释指标。
3. AI 只负责提出有证据、有风险、有执行条件的建议。
4. 运营管理把建议变成动作、证据和状态。
5. 复盘把结果写回系统，形成下一轮判断的存量。
6. 投资决策只能使用已验证数据、显式假设和风险复核，不能把 AI 推演写成事实。

## 3. GitHub 仓库学习要点

本次参考 OpenAI 公开 GitHub 仓库中的工程实践方向，提炼适合宿析OS的原则：

| 来源 | 可学习点 | 对宿析OS的落地 |
|---|---|---|
| `openai/openai-agents-python` tracing 文档 | Agent 运行、模型生成、工具调用、Guardrail 都应进入 trace/span；长任务结束后可强制 flush；敏感数据可关闭采集。 | `ai_model_call_logs` 后续补 `trace_id`、`span_id`、`parent_span_id`，把一次收益诊断、投决复核或执行建议串成完整调用链。 |
| `openai/openai-guardrails-python` | Guardrails 可做输入/输出校验、合规控制，并配套评估和 benchmark。 | 对运营/投决高影响场景增加输入完整性、输出字段、越权建议、未验证事实、敏感信息的规则校验。 |
| OpenAI Cookbook / Evals 方向 | 高质量 AI 系统需要评估集、回归样本、失败案例和持续评估。 | 复用 `ai_evaluation_cases`，补批量运行、自动评分、失败样本沉淀和上线前回归门禁。 |

## 4. OpenAI Developers 角度的性能原则

OpenAI 延迟优化文档把性能优化归纳为七类：更快处理 token、更少输出 token、更少输入 token、更少请求、并行化、减少用户等待感、不要默认使用 LLM。

宿析OS对应策略：

| 原则 | 宿析OS策略 |
|---|---|
| 更快处理 token | 按场景路由模型：规则校验和短分类走快模型，投决复核和复杂根因走强模型。 |
| 更少输出 token | AI 输出只保留结构化结论、证据、风险、动作和复盘要求，不生成长篇泛化说明。 |
| 更少输入 token | 输入只传相关酒店、日期、指标、异常和数据状态；历史长文档走摘要或引用，不整包塞入 Prompt。 |
| 更少请求 | 能一次结构化返回的诊断、风险和动作不要拆成多次串行模型调用。 |
| 并行化 | 互不依赖的竞对解读、点评摘要、价格风险、投放建议可并行执行，再统一汇总。 |
| 减少等待感 | 长任务进入异步状态，前端显示采集、诊断、生成、复盘的进度节点。 |
| 不默认用 LLM | 指标计算、阈值预警、字段校验、权限判断、状态流转必须使用确定性代码。 |

## 5. OpenAI Developers 角度的稳定性原则

| 能力 | 当前基础 | 补强方向 |
|---|---|---|
| 结构化输出 | `LlmClient::createJsonResponse()` 已用 schema 转 prompt 后解析 JSON。 | 支持 OpenAI 原生 Structured Outputs 时，优先用严格 JSON Schema；不支持时保留解析校验和显式错误。 |
| 调用追踪 | 已有 `ai_model_call_logs`、Prompt 版本、来源引用、耗时、状态。 | 增加 trace/span 层级，串联一次业务链路中的多次模型调用与工具结果。 |
| 评估集 | 已有 `ai_evaluation_cases` 存储入口。 | 增加批量运行、自动评分、人工复核、失败样本回流和发布前回归。 |
| 重试与退避 | `LlmClient` 已对网络错误、408、425、429、5xx 做有限重试、指数退避、随机抖动和失败原因外显。 | 后续可按不同模型、模块和 SLA 调整重试次数与队列策略；业务不可用时仍进入阻塞而非假成功。 |
| 限流与成本 | 当前有模型配置和调用日志。 | 增加模块级调用预算、单酒店/单任务频控、慢任务异步队列和高成本调用审批。 |
| 安全与合规 | 已有低置信度、人工确认、来源引用。 | 高影响建议必须经过人工确认；模型输出不得直接改价、改房态或触发投放。 |

## 6. AI 决策质量闭环

AI 输出必须进入如下闭环：

```text
输入数据
-> Prompt 版本
-> 模型调用
-> 结构化输出
-> 规则校验
-> 人工确认
-> 运营执行
-> 效果复盘
-> 评估集沉淀
```

每一次影响运营或投决的 AI 结论都必须具备：

| 字段 | 要求 |
|---|---|
| `source_scope` | OTA 渠道口径、日报口径、竞对口径或投决假设口径。 |
| `evidence` | 指标、时间范围、数据来源、异常状态。 |
| `confidence_score` | 低于阈值时进入人工确认。 |
| `decision_impact` | `operational`、`investment`、`informational`。 |
| `required_action` | 可执行动作、责任角色、阻塞条件。 |
| `review_window` | 复盘观察期，不允许没有观察期的成功判断。 |
| `eval_case_id` | 可纳入评估集的场景必须关联样本或形成新样本。 |

## 7. 落地优先级

| 优先级 | 项目 | 验收标准 |
|---|---|---|
| P0 | 保持现有 AI 治理日志、Prompt 版本、低置信度、人工确认和来源引用。 | 运营/投决类 AI 调用可追责，缺关键元数据时低置信度。 |
| P1 | 批量评估运行器。 | 能按 `evaluation_set` 回放样本，输出通过率、失败原因和回归差异。 |
| P0 已落地 | LLM 重试与退避策略。 | 网络错误、408、425、429、5xx 有有限重试、抖动、最终失败原因；不产生假成功。 |
| P1 | 原生结构化输出适配。 | OpenAI 模型路径支持严格 schema 输出；不支持的供应商继续显式解析校验。 |
| P2 | Trace/span 链路。 | 一次收益诊断或投决复核能查看完整模型、规则、工具和人工确认链路。 |
| P2 | 成本与频控。 | 按模块、酒店、用户、任务类型限制高成本调用，并记录预算消耗。 |

## 8. 禁止事项

1. 不用 AI 直接替代确定性指标计算。
2. 不把 OTA 渠道数据写成全酒店经营事实。
3. 不把模型建议写成已执行动作。
4. 不用默认值、空数组、静默失败掩盖缺字段或调用失败。
5. 不为了速度跳过来源引用、人工确认和评估记录。
6. 不在 trace、日志、评估集里保存未脱敏密钥、Cookie 或敏感原文。

## 9. 参考来源

- OpenAI Latency optimization: `https://developers.openai.com/api/docs/guides/latency-optimization`
- OpenAI Structured Outputs: `https://developers.openai.com/api/docs/guides/structured-outputs`
- OpenAI Evaluation best practices: `https://developers.openai.com/api/docs/guides/evaluation-best-practices`
- OpenAI Rate limits: `https://developers.openai.com/api/docs/guides/rate-limits`
- GitHub `openai/openai-agents-python` tracing: `https://github.com/openai/openai-agents-python/blob/main/docs/tracing.md`
- GitHub `openai/openai-guardrails-python`: `https://github.com/openai/openai-guardrails-python`
