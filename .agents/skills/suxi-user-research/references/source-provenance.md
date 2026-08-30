# 来源与适配边界

## 来源指纹

- 来源仓库：`https://github.com/infometa/workbuddyskills`
- 锁定提交：`78170571d08e7d38c6baf0a13ef805487bfa6dc2`
- 取得时间：`2026-08-29 Asia/Shanghai`
- `plugins/cb_teams_marketplace/plugins/design-to-code/skills/user-research/SKILL.md`
  - SHA-256: `fa18fc13d0f44aa869102962ef9466417ade726d659465195a40fbcf08cee156`
- `plugins/cb_teams_marketplace/plugins/design-to-code/.codebuddy-plugin/plugin.json`
  - SHA-256: `e0cf2506cf31ff4f55bc66b07931956d12af3c5db897d5f9224eef4e72190a26`
  - 父包 manifest 声明 `MIT`。
- `experts/user-experience-researcher/.codebuddy-plugin/plugin.json`
  - SHA-256: `a57418995572cd0eca728a5bf67bf087ea6f3898b98a9d151f1cac395c9462f2`
- `experts/user-experience-researcher/agents/user-experience-researcher.md`
  - SHA-256: `100da55334c18a5ed57e4bdda08f61e177f0e1c90f3920824650cb4baf0ba1b2`

## 已适配的稳定机制

- 研究问题决定方法，不根据方法名称堆流程。
- 访谈、问卷、可用性任务和遥测分别回答动机、分布、任务成败和大规模行为问题。
- 观察、解释和建议分层；冲突证据保留，不强行求一致。
- 用真实任务、完成状态、错误、求助和原话支持产品判断。
- 改版后使用同任务复验，条件漂移时不冒充可比。

## 明确排除

- 没有复制外部包的提示词、Agent 身份或工具调度指令。
- 不接入 `browser-use`、真实 Chrome Profile、Cookie 导入导出、云同步、远程调试或 Cloudflare Tunnel。
- 不接入 MiniMax DOCX/媒体脚本、外部发布、自动招募或外部发送。
- 不复制通用样本数、研究时长、转化率或成功阈值；由当前决定、真实样本和业务风险决定。

## 成熟度

- 外部来源：`observed / understood`，未执行外部工具链。
- 宿析适配：`source_inspired`，需通过本地正反评测和真实研究任务继续升级。
- 任何后续正式吸纳结论必须补齐来源样例重放，不得因本地 Skill 可读就声称已复现外部能力。
