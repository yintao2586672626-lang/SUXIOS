# AI 治理 P2 落地清单

## 目标

避免 AI 输出“看起来有结论但不可追责”。所有可影响运营或投决的模型结论必须能追溯到模型、Prompt 版本、数据/知识来源、置信度和人工确认状态。

## 已落地后端能力

| 能力 | 落点 | 说明 |
|---|---|---|
| 模型调用日志 | `ai_model_call_logs` | 记录模型、Prompt Hash、Prompt 版本、响应摘要、耗时、HTTP 状态、错误、来源引用。 |
| Prompt 版本 | `ai_prompt_versions`、`LlmClient` `x-governance.prompt_version` | 结构化 JSON 调用通过 schema 标注版本；临时调用自动生成 `adhoc-*`。 |
| 知识来源引用 | `knowledge_sources_json` | 记录参与生成的表、知识库、输入摘要或外部数据口径。 |
| 低置信度提示 | `confidence_score`、`low_confidence` | 支持模型或调用方传入置信度；低于阈值写入低置信度标记。 |
| 人工确认 | `human_confirmation_required/status`、`POST /api/ai-governance/logs/:id/confirm` | 运营/投决类调用默认进入待确认状态，支持人工确认或驳回。 |
| 评估集 | `ai_evaluation_cases`、`ai_model_call_logs.evaluation_set/eval_case_id` | 保存评估用例、输入、期望结果和指标；调用日志区分评估集标识和具体用例ID。 |
| 批量评估回放 | `AiEvaluationBatchReplayService`、`POST /api/ai-governance/evaluation-cases/replay` | 按活跃评估用例生成 dry-run 计划，显式标记缺 `messages/schema/expected_json` 等不可执行原因；执行模式调用模型并按 `expected_json` 子集评分。 |
| 原生结构化输出 | `LlmClient::createJsonResponse` | `provider=openai` 时在 Chat Completions 请求体中发送 `response_format=json_schema` 且 `strict=true`；非 OpenAI 供应商继续使用 prompt schema + 显式 JSON 解析校验。 |
| 有限重试 | `LlmClient` | 对网络错误、408、425、429 和 5xx 执行有限重试、指数退避和抖动；最终失败仍显式返回错误并写入重试元数据。 |

## 治理判定规则

- 运营、投决类调用缺少置信度、知识来源或评估集标识时，强制标记为低置信度并进入待人工确认。
- 评估集标识使用 `evaluation_set`，具体回归样本或人工抽检用例使用 `eval_case_id`，两者不再混用。
- Prompt 版本登记必须提供 `content` 或 64 位 `content_hash`；非法 `status` 不做静默纠正。

## API

| 方法 | 路径 | 用途 |
|---|---|---|
| `GET` | `/api/ai-governance/summary` | 查看调用量、低置信度、待人工确认、失败/阻断、Prompt 和评估集概览。 |
| `GET` | `/api/ai-governance/logs` | 查询 AI 调用日志。 |
| `GET` | `/api/ai-governance/logs/:id` | 查看单次调用详情。 |
| `POST` | `/api/ai-governance/logs/:id/confirm` | 人工确认或驳回 AI 调用结论。 |
| `GET/POST` | `/api/ai-governance/prompt-versions` | 查询或保存 Prompt 版本登记。 |
| `GET/POST` | `/api/ai-governance/evaluation-cases` | 查询或保存评估集用例。 |
| `POST` | `/api/ai-governance/evaluation-cases/replay` | 批量回放活跃评估用例；默认 `dry_run=true` 只输出 ready/blocked 计划，`execute=true` 或 `dry_run=false` 才真实调用模型。 |
| `DELETE` | `/api/ai-governance/evaluation-cases/:id` | 归档评估集用例。 |

## 当前边界

- 本次不新增前端页面。
- 本次不改变既有 AI 业务结论生成规则。
- 评估回放只执行输入中已包含 `messages` 或 `prompt`，且包含 `schema/json_schema` 的用例；缺少可回放输入的旧用例会标记为 `blocked`，不会自动拼接兜底 Prompt。
- 原生结构化输出仅对 `provider=openai` 生效；OpenAI 兼容但未确认支持严格 JSON Schema 的供应商不自动开启。
- Prompt 正文只记录脱敏预览和 Hash，不保存完整敏感上下文。
- 日志查询支持按请求ID、模块、场景、模型、状态、Prompt 版本、评估集、评估用例、人工确认状态、低置信度和时间范围筛选。

## 性能与稳定性补强方向

| 优先级 | 方向 | 说明 |
|---|---|---|
| P1 已落地后端 | 批量评估运行器 | 按 `evaluation_set` 回放样本，输出通过率、失败原因和回归差异；前端操作台仍待补。 |
| P0 已落地 | 有限重试与指数退避 | 对网络错误、408、425、429、5xx 增加有限重试、随机抖动和最终失败原因记录；不得产生假成功。 |
| P1 已落地 | 原生结构化输出适配 | OpenAI 模型路径优先使用严格 JSON Schema；不支持的供应商继续显式解析和校验。 |
| P2 | trace/span 链路 | 为收益诊断、投决复核、执行建议串联模型调用、规则校验、工具结果和人工确认。 |
| P2 | 成本与频控 | 按模块、酒店、用户、任务类型限制高成本调用，并记录预算消耗。 |

详细原则见 `docs/system_beauty_ai_stability_review.md`。
