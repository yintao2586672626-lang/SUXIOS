# 在线Skill吸纳规范

本规范只吸收官方一手资料中能提高宿析OS交付质量的部分。

## 已采纳原则

- **渐进加载**：`SKILL.md`只保留每次都需要的核心步骤，详细路线按需放入一层 `references/`，脚本只在需要时运行。
- **触发优先**：description前置关键用途、触发语境和边界；用正负近似样例验证过宽与漏触发。
- **先预览后安装**：在线Skill、Prompt、脚本和插件一律视为不受信任输入；先看完整文件树、指令、脚本和权限，再决定复用。
- **来源可追踪**：记录仓库URL、tag/ref、提交或tree SHA、获取时间、许可、兼容性和依赖；需要稳定复用时锁定版本。
- **可移植性**：Skill内部使用相对路径，声明运行环境和外部工具；避免把开发者机器的绝对路径写入可分发能力。
- **冲突可见**：同名Skill不会自动合并；项目权威版本优先，遮蔽、冲突和重复能力必须显式报告。
- **指令作用域可见**：记录目标文件受哪些仓库级、目录级和项目级指令约束；离目标更近的规则与上级规则冲突时先解决再写入。
- **示教不是完成**：Record & Replay、录屏或人工演示可以生成Skill草稿，但必须重放、校正、接入和验证。
- **基线评测**：新Skill对比旧版或无Skill，分别验证触发、输出质量、边界、用时与token成本；机械断言优先脚本验证。
- **最小权限**：观察和理解阶段默认只读；明确列出需要的工具子集，MCP逐工具授权，不因外部配置省略权限就接受全部工具。
- **实际激活可核对**：记录权威来源、分发副本、实际加载路径和文件树指纹；复制完成不能替代实际触发或样例证明。
- **工具与工作流分层**：Skill描述工作方法，MCP或工具提供外部动作；只有固定依赖才写入 `agents/openai.yaml`。

## 在线Skill接入记录

```text
source_url / source_ref / commit_or_tree_sha / fetched_at /
license / compatibility / runtime_dependencies / mcp_dependencies /
requested_tool_permissions / file_tree_sha256 / name_collision /
instruction_scopes / active_copy / static_review / manual_review /
sample_result / baseline_delta / rollback_version
```

运行静态预览：

```powershell
node scripts/inspect-skill-package.mjs <已下载或解压的Skill目录>
```

输出为 `previewed`、`review_required` 或 `invalid`。它只做确定性预览，不能证明Skill安全，也不能替代真实样例。

## 不直接采纳

- 不自动安装社区Skill，也不因来源热门或官方集合收录就跳过检查。
- 不自动继承 `allowed-tools` 中的 shell/bash 或通配权限。
- 不把实验性 `allowed-tools` 当作跨客户端安全边界；实际权限仍服从宿析OS项目规则和当前工具授权。
- 不强行把所有开放规范可选frontmatter写入当前Codex Skill；许可、兼容性和来源信息可先保存在接入记录中，避免破坏当前验证器兼容性。
- 不把工具、MCP、Agent和Skill混成同一层；缺外部动作能力时明确依赖，不用提示词假装拥有工具。

## 官方依据

- OpenAI Codex Skills：<https://learn.chatgpt.com/docs/build-skills>
- OpenAI Record & Replay：<https://learn.chatgpt.com/docs/extend/record-and-replay>
- Agent Skills规范：<https://agentskills.io/specification>
- Agent Skills最佳实践：<https://agentskills.io/skill-creation/best-practices>
- 描述触发评测：<https://agentskills.io/skill-creation/optimizing-descriptions>
- 输出质量评测：<https://agentskills.io/skill-creation/evaluating-skills>
- GitHub Skill安装与来源治理：<https://docs.github.com/en/copilot/how-tos/copilot-on-github/customize-copilot/customize-cloud-agent/add-skills>
- Anthropic Skills：<https://code.claude.com/docs/en/skills>
- Microsoft Agent Skills：<https://learn.microsoft.com/en-us/agent-framework/agents/skills>
