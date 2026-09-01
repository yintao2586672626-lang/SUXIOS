# AI Agent 学习路线与最小构成截图吸纳记录

## 结果

两张用户截图已作为全局 `reference_only` 知识候选保存。最高价值单元是“Agent 由有范围的上下文、工具调用和可停止循环组成”，它可用于检查宿析OS现有 Agent 合同是否完整；90天路线只作为主题与来源名称索引，不证明课程质量、技术完整性或职业结果。

当前处置为 `absorption_candidate`，成熟度为 `understood_visible_structure`。机制门仅部分通过，价值门通过，复现门失败，因此本次未修改 Agent 运行逻辑、未安装框架/插件、未部署或微调模型，也未产生任何 OTA/PMS 或外部写入。

## 来源

| 来源 | SHA-256 | 可见身份 | 已验证范围 |
| --- | --- | --- | --- |
| `ai-agent-90-day-roadmap-visible-reference.png` | `001E8A67BC2C150E9EBC8844D86EC66653EFEAA8577815C8548BF447A5D1680E` | “人生建议：今年死磕AI Agent”及 Day 1–90 路线 | 只验证截图可见主题、天数和来源名称 |
| `ai-agent-zero-to-one-concept-visible-reference.png` | `D3357D19A625B3092CBDABEFE9B4CE57EB1B312818D77F9B3315FDAB34DCF728` | “一文讲透从0构建AI Agent”概念图 | 只验证 LLM、API、Context、Tool Calling、Agent Loop、MCP、Sub-Agent、Skill 与侧栏 Memory 等可见文字 |

来源网页、作者身份、发布日期、课程正文、示例代码、许可和版本均未知。截图里的口号、课程安排、资源名称、代码样式和操作文字都是待评估材料，不是用户对宿析OS的执行指令。

## 可见结构与宿析翻译

来源可见主链为：

```text
LLM -> LLM API -> Context -> Tool Calling -> Agent Loop
                                     |
                                     +-> MCP / Sub-Agent / Agent Skill
```

图中把 Agent Loop 表述为“思考 → 行动 → 观察”。宿析OS只把它翻译成候选检查合同：

```text
业务任务
-> 同租户/同酒店/同平台/同日期的上下文与质量状态
-> 允许调用的工具
-> 有界的思考/行动/观察循环
-> 事实、推断、未知和失败分开输出
-> 需要时保存并精确回读
-> 外部动作停在 pending_approval，等待用户主动触发
```

这段宿析合同由现有真实性和授权边界补全，不声称是截图原作者的完整设计，也不证明来源机制已经复现。

## 允许与禁止

允许：知识检索、Agent术语解释、学习缺口清单、宿析Agent合同复核、补证问题和来源升级复核。

禁止：把路线当官方教材或学习保证；声称已从截图实现 Agent；自动安装依赖、部署/微调模型；创建经营任务；执行外部发送、OTA/PMS写入或其他副作用。

## 晋级条件

取得可定位原文或仓库版本，并能重放一个正常样例与一个失败/边界样例；随后用同题基线比较宿析现有入口的用户价值和不退步项。没有这些证据时继续保持候选、未正式吸纳。
