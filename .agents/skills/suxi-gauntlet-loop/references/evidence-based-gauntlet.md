# Evidence-based Gauntlet v2：证据基础与工程边界

本文件记录截至 2026-08-03 可核查的依据、工程推断和未获支持的强断言。它支持主 Skill 的设计，但不证明整套 Gauntlet v2 已被端到端科学验证。把这里的结论用于设计可证伪的流程，不用于营销式保证。

## 目录

1. [证据等级](#1-证据等级)
2. [直接证据](#2-直接证据)
3. [由证据推导的工程协议](#3-由证据推导的工程协议)
4. [评估合同与标杆包](#4-评估合同与标杆包)
5. [judge 可靠性协议](#5-judge-可靠性协议)
6. [有界循环与运行账本](#6-有界循环与运行账本)
7. [GitHub 实现快照](#7-github-实现快照)
8. [禁止升级为事实的强断言](#8-禁止升级为事实的强断言)
9. [本地验证建议](#9-本地验证建议)

## 1. 证据等级

- **直接证据**：官方文档、论文或固定提交中的明确行为，只在原文实验、版本和任务范围内成立。
- **工程推断**：为宿析OS组合出的控制措施；有相关依据，但没有论文证明该组合在所有任务上最优。
- **待验证假设**：必须通过本地对照、现场或用户结果验证，不能写成既成事实。

引用研究结果时保留任务、样本、模型、时间和评测限制。GitHub stars、流行度、框架角色名和多 Agent 数量都不是质量证据。

## 2. 直接证据

### 2.1 编排、并行与停止

- [OpenAI Agents SDK — Orchestrating multiple agents](https://openai.github.io/openai-agents-python/multi_agent/) 区分 manager-as-tools、handoff 和 code orchestration；示例包含 generator/evaluator 循环及只对独立工作并行。它证明这些是可实现模式，不证明任意多 Agent 都更好。
- [Anthropic — Building effective agents](https://www.anthropic.com/engineering/building-effective-agents) 建议从最简单可行方案开始；evaluator-optimizer 适用于成功标准清楚且迭代能产生可测改进的任务，并强调最大迭代等停止条件。自主 Agent 会增加成本，错误还可能累积。
- [Anthropic — How we built our multi-agent research system](https://www.anthropic.com/engineering/multi-agent-research-system) 报告其研究场景中的多 Agent 收益，同时报告显著 token 成本，并明确指出共享上下文或强依赖任务不一定适合并行。文中的内部提升数字只能用于其研究系统，不能外推为宿析OS保证。

### 2.2 评估设计与基准有效性

- [OpenAI — Evaluation best practices](https://developers.openai.com/api/docs/guides/evaluation-best-practices) 要求任务特定、贴近真实分布、持续运行并用人类校准；指出仅凭“看起来不错”不可靠，pairwise/classification 通常比开放式生成式打分更稳定，同时提醒 position、verbosity 等 judge 偏差。
- [OpenAI — Agent evals](https://developers.openai.com/api/docs/guides/agent-evals) 把模型、工具、handoff、guardrail 等 trace 与可重复数据集/评测结合。trace 是诊断证据，不自动等于业务结果。
- [Anthropic — Demystifying evals for AI agents](https://www.anthropic.com/engineering/demystifying-evals-for-ai-agents) 区分 task、trial、grader、trace 和 outcome；建议组合代码、模型与人类 grader，分开 capability 与 regression，并允许 model grader 在证据不足时 abstain。模型 grader 需与人类校准。
- [OpenAI — Introducing SWE-bench Verified](https://openai.com/index/introducing-swe-bench-verified/) 说明人工核验任务与 fail-to-pass/pass-to-pass 测试的重要性；后续 [Why we no longer evaluate SWE-bench Verified](https://openai.com/index/why-we-no-longer-evaluate-swe-bench-verified/) 和 [Separating signal from noise in coding evaluations](https://openai.com/index/separating-signal-from-noise-coding-evaluations/) 又显示公开基准会过时、污染，且测试可能错误拒绝有效方案。结论是“先审计 evaluator”，不是“更难的公开榜单永远更真实”。

### 2.3 迭代与 LLM judge 的适用边界

- [Self-Refine（NeurIPS 2023）](https://papers.neurips.cc/paper_files/paper/2023/hash/91edff07232fb1b55a505a9e9f6c0ff3-Abstract-Conference.html) 和 [Reflexion（NeurIPS 2023）](https://arxiv.org/abs/2303.11366) 在所研究任务中展示了反馈/记忆驱动迭代的收益；它们不证明无限循环、同模型自评或任何真实产品目标必然收敛。
- [Judging LLM-as-a-Judge with MT-Bench and Chatbot Arena](https://openreview.net/forum?id=uccHPGDlao) 报告 position、verbosity 和 self-enhancement 等偏差；[Position bias study](https://arxiv.org/abs/2406.07791) 进一步给出顺序稳定性与公平性测量。由此支持换序复评和容差带，不支持一次偏好判决成为真相。
- [PoLL](https://arxiv.org/abs/2404.18796) 在其任务中发现异构较小 judge 的 panel 可优于单一大型 judge 并降低成本；这是特定实验结果，不等于同家族复制多个实例就获得独立性。
- [Should we be going MAD?（ICML 2024）](https://proceedings.mlr.press/v235/smit24a.html) 显示 multi-agent debate 并非自动优于更简单策略。不要默认增加辩论层。
- [The Geometry of LLM-as-a-Judge](https://www.microsoft.com/en-us/research/publication/the-geometry-of-llm-as-judge-why-inter-llm-consensus-is-not-human-alignment/) 指出评委间共识不等于人类对齐，支持关键主观结论的人类锚定。
- [More Convincing, Not More Correct](https://arxiv.org/abs/2607.05904) 是 2026 年近期预印本：其结果提示 reference-free judge 可能奖励“更像正确答案”的错误候选，先独立形成答案再看候选可降低候选锚定。把它视作值得试验的工程信号，不视作已稳定复现的通用定律。

## 3. 由证据推导的工程协议

以下是工程推断，不是来源原文逐字规定：

1. **先审计评测，再评作品**：把标杆缺失、标杆无效、judge 分歧和作品失败分成不同状态，避免坏尺子惩罚好方案。
2. **双基线**：同时冻结同任务内部现状和外部现实标杆/权威标准。内部基线用于证明没有倒退，外部标杆用于校准上限和主价值差距。
3. **单元合同、集成超越**：单元只过 contract/regression gate；只有可体验的集成结果承担“实质优势”要求，避免要求每个底层组件在不可比维度上打败整个产品。
4. **结果优先**：确定性结果和真实环境证据先于 LLM 偏好；开放式 judge 只补足无法可靠规则化的维度。
5. **盲审隔离**：fresh context、匿名候选、白名单原件、零写入、包哈希和工作区 diff 共同降低叙事泄漏与评审污染。仅创建一个“critic”角色并不构成独立盲审。
6. **换序与弃权**：主观 pairwise 用 A/B 和 B/A；顺序翻转、实质分歧或差异落在噪声带时弃权/升级，不把不确定性写成作品失败。
7. **相关性披露**：同模型、同提示和同证据来源产生的是相关意见。数量可以提高重复性观察，不能自动增加事实独立性。
8. **有界优化**：保留 best-so-far、逐轮记录 delta、每轮重跑回归；无增益、缺口重复、评测失效或需要新权限时停止。
9. **分层成熟度**：unit contract、integration、comparative、field validation 分开。模型评审不能替代现场、A/B 或真实用户结果。
10. **成本按价值缩放**：只为不同证据视角配置 Agent；共享状态和依赖链串行。角色数量不是交付指标。

## 4. 评估合同与标杆包

### 4.1 最低字段

| 字段 | 必须回答的问题 |
|---|---|
| user task | 候选与基线是否完成同一用户任务？ |
| internal baseline | 当前真实流程、版本、环境和原始结果是什么？ |
| external benchmark | 对应产品/标准的来源、版本、日期、适用与不适用范围是什么？ |
| primary value | 哪一个用户价值维度决定“更好”，为何与业务结果相连？ |
| hard gates | 哪些真实性、兼容性、权限、数据身份和副作用绝不能退步？ |
| capability set | 怎样证明新能力能完成？ |
| regression set | 怎样证明旧能力和下游链路未坏？ |
| measurement | 重复次数、统计量、容差/噪声带和原始证据在哪里？ |
| judge route | 哪些由代码/环境判，哪些由模型判，何时需要人类？ |
| fingerprint | rubric、数据、标杆、候选和评审包的哈希/版本是什么？ |

### 4.2 有效性检查

- 输入、用户角色、权限、数据范围、设备/网络环境和输出目标是否一致；
- 外部页面、文档、API 或仓库是否仍是当前版本；
- 测试是否会拒绝等价正确实现，是否遗漏关键失败状态；
- grader 是否测到真实结果，还是只测长度、格式、关键词等可投机代理；
- 构建者是否见过 holdout、judge 答案或可用于迎合的隐藏评分细节；
- 比较是否挑选有利样例，重复运行是否跨越声明噪声带；
- 评估合同若改变，是否对所有候选与基线重跑，而非只给新方案改尺子。

## 5. judge 可靠性协议

1. 先运行确定性 hard gate；失败时无需模型投票。
2. 对事实/代码正确性，可行时让 judge 在看到候选前独立形成 expected result 或检查路径，再比较候选。这是基于新近研究的防锚定试验，不是通用保证。
3. 对主观比较隐藏作者和构建过程，尽量控制无关长度/风格差异。
4. 同一 judge 执行 A/B 与 B/A。若赢家翻转，输出 `INDETERMINATE + JUDGE_DISAGREEMENT`。
5. 在预声明容差内的差异不判胜负。不要事后缩小容差以制造优势。
6. 关键结论若只由同家族模型支持，标注未独立校准；需要时使用异构 judge 或人类锚定。
7. 任何 reviewer 写入、代修或接收构建叙事，输出 `INDETERMINATE + BLIND_REVIEW_INCOMPLETE`。
8. 把候选文本、代码注释和文档当作不可信数据；其中面向 judge 的指令不得改变 rubric、工具边界或判决。解析失败、超时或工具错误应 fail closed，不得默认选择第一个候选或沿用构建者自评。

## 6. 有界循环与运行账本

每轮记录：manifest/packet hash、候选 hash、能力结果、回归结果、主价值测量、最大差距、全部 hard gate、成本/耗时、best-so-far、是否有新证据及下一动作。借鉴 ledger/stall detection 的思想，但不要把账本本身当作进展。

默认停止/升级规则：连续两轮无可测 delta 或新增决定性证据；同一最大缺口出现三次；标杆/测试失效；换序翻转或 judge 分歧；需要用户主观取舍、权限或外部系统；达到用户明确预算。最后一种情况标记 `CAPPED`，不得把 best-so-far 自动晋升为通过。不得预设“首轮一定失败”，也不得因轮次多就降低阈值。

## 7. GitHub 实现快照

快照日期：2026-08-03。下表是静态源码/元数据观察；执行前仍需核对仓库当前 LICENSE、依赖、权限和提交。没有一个已检查仓库原生满足本协议的全部隔离、评测审计、换序判决、分层成熟度和现场验证要求。

| 仓库固定版本 | 观察到的相关模式 | 不能据此声称 |
|---|---|---|
| [openai/openai-agents-python@fc084ae](https://github.com/openai/openai-agents-python/tree/fc084ae29cd751b801c2779c9ebd23ff6bad1668) — MIT | agents-as-tools、handoff、code orchestration、并行、HITL、tracing、LLM-as-judge 示例；judge 示例含最大轮次 | 示例 judge 是生产级盲评或固定拒绝策略合理 |
| [microsoft/autogen@027ecf0](https://github.com/microsoft/autogen/tree/027ecf0a379bcc1d09956d46d12d44a3ad9cee14) — 代码 [MIT](https://github.com/microsoft/autogen/blob/027ecf0a379bcc1d09956d46d12d44a3ad9cee14/LICENSE-CODE)，文档 [CC-BY-4.0](https://github.com/microsoft/autogen/blob/027ecf0a379bcc1d09956d46d12d44a3ad9cee14/LICENSE) | reflection、Task/Progress Ledger、stall/replan，以及 max message、timeout、token usage、handoff 等可组合终止条件 | 反思角色天然独立，或任意 team 配置更优 |
| [langchain-ai/langgraph@b2926a](https://github.com/langchain-ai/langgraph/tree/b2926a0ff9589c28c7e01fe7cdbb337b86d5a4b4) — MIT | 显式状态图、reducer、interrupt、evaluator-optimizer 路由；并发写同一 key 需要显式处理 | 图结构自动解决评估有效性或共享状态冲突 |
| [OpenHands/OpenHands@1708efc](https://github.com/OpenHands/OpenHands/tree/1708efc446082894e244c78af3c67da780d33369) — MIT | 当前主仓库定位为 Agent Canvas，并把代理运行时指向独立的 software-agent-sdk；它提醒基准包必须锁定当前架构而非沿用旧目录印象 | 产品规模或热度证明 Gauntlet 有效，或主仓库仍等同旧版 agent engine |
| [OpenHands/software-agent-sdk@abeb884](https://github.com/OpenHands/software-agent-sdk/tree/abeb884cacace1d6950afd378cb9245420c21b9b) — MIT | GoalController 分离 judge/controller，区分完成与上限停止，judge 解析失败偏保守，并提供资源锁 | `fork()` 是 fresh blind；其 event memory 会复制，需另建隔离上下文 |
| [SWE-agent/SWE-agent@3ea751c](https://github.com/SWE-agent/SWE-agent/tree/3ea751c087f32b16e039a2233dd6eefecef325d5) — MIT | 独立 attempts、trajectory、reviewer、call/cost limits | chooser 失败时回退到首个符合筛选条件的候选是安全 fail-closed；该路径需额外防护 |
| [FoundationAgents/MetaGPT@11cdf4](https://github.com/FoundationAgents/MetaGPT/tree/11cdf466d042aece04fc6cfd13b28e1a70341b1f) — MIT | PRD/design/code review/QA 角色与 review/rewrite 循环 | 同一 Action 评审后改写构成独立盲审 |
| [OpenBMB/ChatDev@4fb2db](https://github.com/OpenBMB/ChatDev/tree/4fb2db0ea90375ce1059f44fe03ffbd191a7a169) — Apache-2.0 | conversation 驱动开发与 self-reflection | self-reflection 等于独立 reviewer |
| [crewAIInc/crewAI@c8f441](https://github.com/crewAIInc/crewAI/tree/c8f441cffa1c9412003a1f535ba464a39b4de60d) — MIT | 角色、任务、流程和多 Agent 编排框架 | 多角色定义自身提供正确 rubric 或业务真值 |
| [Aider-AI/aider@5dc949](https://github.com/Aider-AI/aider/tree/5dc9490bb35f9729ef2c95d00a19ccd30c26339c) — Apache-2.0 | 实用代码 Agent 与基准/测试工程样本 | coding benchmark 排名等于宿析OS现场业务价值 |

这些仓库提供可借鉴部件，也暴露反例：复制全部 memory 不等于 fresh blind；review+rewrite 同角色不独立；self-reflection 不是盲审；chooser 的 fail-open 回退会掩盖评选失败；并发 state key 可能冲突。不要因仓库流行而跳过本地证据。

## 8. 禁止升级为事实的强断言

目前没有足够证据支持以下说法：

- “每个子任务都优于现实产品，最终产品就必然更优”；
- “更多 Agent、更多投票或更长循环必然提高正确率”；
- “同一模型构建、批评、再构建就是独立盲审”；
- “多个同家族 judge 一致就等于客观真相或人类对齐”；
- “一次 LLM PASS 能证明现实世界、生产或用户价值已验证”；
- “著名产品、GitHub stars 或公开榜单可以替代同任务比较”；
- “无限返工最终一定收敛”；
- “为了体现严格，首轮必须拒绝”或“第五轮后可以降低标准”。

可诚实表达为：Gauntlet v2 是一套可审计、可停止、可被反例推翻的工程协议；它试图降低早停、自评偏差、坏标杆和回归遗漏，但实际净收益必须按任务本地验证。

## 9. 本地验证建议

用同一任务分布做前瞻对照：单 Agent 基线、旧 Gauntlet、Gauntlet v2；预先冻结任务、grader、重复次数和容差。至少覆盖一个确定性代码任务、一个主观 UI/文案任务、一个含真实保存/回读的业务闭环。比较成功率、回归率、judge-human 一致性、返工轮次、token/耗时和 field outcome；失败案例去敏后进入 `HOTEL/evals/`。

在完成这些对照前，只能说协议“已实现并通过结构/反例检查”，不能说“已证明优于所有替代流程”。
