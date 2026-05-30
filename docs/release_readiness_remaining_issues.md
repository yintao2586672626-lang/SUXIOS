# 上市前剩余问题清单

更新时间：2026-05-30

范围：`@github`、`@openai-developers`、`@codex-security`、`@figma`、`@canva`

## 当前结论

项目的 GitHub CI 阻断已解除，核心测试和现有高风险安全脚本已通过。上市前仍不应直接发布，主要剩余问题集中在生产配置、完整安全审计、敏感本地备份和真实设计源归档。

机器可读状态见 `docs/release_readiness_status.json`。

## 已解除的阻断

| 范围 | 状态 | 当前证据 |
|---|---|---|
| GitHub CI | 已通过 | PR `#1` 当前 head 的两个 `PHP Composer / verify` 均为 `SUCCESS` |
| 数据库重建 | 已修复 | `database/init_full.sql` 不再依赖本地 `hotelx_dump.sql`，SQL schema contract 通过 |
| 日报公式执行风险 | 已修复 | `DailyReport.php` 已移除 `eval`，改为四则运算解析器 |
| Excel 解析命令执行风险 | 已修复 | `DailyReport.php` 已移除 `shell_exec`，改为 `proc_open` 数组命令 |
| AI 调用入口 | 已收口 | 未使用的 `OpenAIClient` 已移除，生产 AI 路径为 `LlmClient` + 数据库加密模型配置 |
| 采集报告误提交 | 已收口 | `.gitignore` 已忽略 `reports/ctrip_browser_capture_*.json` 与 `reports/meituan_browser_capture_*.json` |
| 发布包敏感路径 | 已收口 | `.gitattributes` 已将 `.env`、数据库备份、采集报告和截图资产标记为 `export-ignore` |
| UI 代码侧交付清单 | 已补充 | `docs/ui-handoff/README.md` 已覆盖登录、OTA、收益分析、AI 决策、运营管理、投资决策的代码侧核对入口 |
| 本地 Git index 锁 | 部分缓解 | `review:release-readiness` 可检测锁文件；本机仍会间歇出现 `.git/index.lock`，发布前必须复核 |

## 仍存在的问题

### 1. 生产环境配置未达发布状态

范围：`@openai-developers`、发布配置

当前仓库只提供 `.example.production.env` 模板，未提供可验证的生产 env 文件。`npm run review:release-readiness` 默认检查 `.env.production`，也可以通过 `RELEASE_ENV_FILE` 指向受控生产配置文件。

| 配置项 | 当前状态 | 风险 |
|---|---|---|
| `.env.production` / `RELEASE_ENV_FILE` | 未提供真实文件 | 无法证明生产 `APP_DEBUG=false`、数据库密码非空、AI 密钥解密配置正确 |
| `.example.production.env` | 已补充模板 | 只能作为填写参考；含 `CHANGE_ME` 占位值，不能作为生产配置验收 |
| `AI_CONFIG_SECRET` | 本地 `.env` 已配置，长度 64 | 只能证明本地开发值存在，不能替代生产密钥验收 |

处理要求：

- 生产环境使用独立环境变量或部署密钥管理，不提交 `.env`。
- 按 `.example.production.env` 和 `docs/deployment_env_checklist.md` 准备受控生产 env，并用 `RELEASE_ENV_FILE` 运行发布就绪检查。
- OpenAI/LLM 生产入口已收口为 `LlmClient` + 数据库 `ai_model_configs` 加密配置；上线前仍需确认生产数据库已配置启用模型。
- 上线前用真实生产配置做一次受控 API 连通性验证。

### 2. 完整 Codex Security 扫描未完成

范围：`@codex-security`

已完成：

- `php scripts/verify_high_risk_security.php` 通过。
- `npm audit --audit-level=moderate --json` 为 0 漏洞。
- GitHub Actions 已加入 `composer audit --no-interaction` 与 `npm audit --audit-level=moderate`。
- 当前 PR head 的两个 CI job 日志均显示：`composer audit --no-interaction` 返回无安全公告，`npm audit --audit-level=moderate` 返回 0 漏洞。
- `rg` 确认 `app/` 与 `scripts/` 范围内未再命中 `eval(` / `shell_exec(`。
- `npm run review:release-readiness` 已新增，用于把发布阻断项显式暴露出来。
- 发布包敏感路径 ignore 规则已校验通过，覆盖 `.env`、生产 env、数据库备份、采集 profile、采集报告 JSON 和截图目录。
- `.gitattributes` 已补充 `export-ignore`，用于 `git archive` 场景排除 `.env`、数据库备份、采集报告和截图资产。

未完成：

- 正式 repo-wide Codex Security 扫描需要授权 subagents，当前未获得授权，因此没有完整扫描的 markdown/html 报告。

处理要求：

- 授权 subagents 后执行完整 security-scan 流程。
- 提交本地改动后，确认当前 PR head 的 GitHub Actions 中 `composer audit` 与 `npm audit` 均通过。
- 授权要求见 `docs/codex_security_scan_authorization.md`。

### 3. 本地备份目录存在 OTA 凭证形态数据

范围：`@codex-security`、OTA 数据安全

证据：

- `database/backups/` 被 `.gitignore` 忽略，当前未作为源码提交。
- `git ls-files database/backups` 无输出，当前没有备份文件被 Git 跟踪。
- `.gitattributes` 已将 `database/backups/*` 标记为 `export-ignore`。
- 本地 `database/backups/*.sql` 中命中 `usertoken`、`usersign`、`cookies` 等携程认证字段。
- 已补充 `docs/ota_credential_rotation_checklist.md` 和 `docs/ota_credential_rotation_attestation.example.json`，用于轮换和清理验收记录。

风险：

- 如果这些是真实 OTA 凭证，应视为已暴露在本机备份中。
- 若打包、迁移、部署时误带入 `database/backups/`，会造成严重凭证泄露。

处理要求：

- 不把 `database/backups/` 放入任何发布包。
- 对真实 OTA Cookie/Token 执行轮换或失效处理。
- 生产备份使用加密存储和最小权限访问。
- 完成后按 `docs/ota_credential_rotation_checklist.md` 复扫并记录，不在文档中粘贴真实凭证。

### 4. Figma / Canva 真实设计源未归档

范围：`@figma`、`@canva`

证据：

- 已补充代码侧 UI handoff 清单：`docs/ui-handoff/README.md`。
- 已补充设计交付 manifest 模板：`docs/design_handoff_manifest.example.json`。
- 仓库内仍未发现 `.fig`、`.canva`、`.sketch`、`.xd`、`design-tokens.json` 或品牌规范源文件。
- 代码中存在页面和样式，但仍没有 `docs/design_handoff_manifest.json` 或可追溯到 Figma/Canva 的真实设计源。

风险：

- 上市材料、品牌一致性、UI 验收和后续设计迭代缺少单一来源。
- 无法证明当前界面与设计稿一致。

处理要求：

- 补充 Figma/Canva 链接、导出稿、品牌规范、色彩/字体/组件 token。
- 基于 `docs/design_handoff_manifest.example.json` 创建 `docs/design_handoff_manifest.json`，填写可访问的 Figma、Canva、Brand Kit 链接和负责人。
- 建立 UI 走查清单：登录、首页、OTA 数据、收益分析、AI 决策、运营管理、投资决策。
- 代码侧走查要求见 `docs/ui-handoff/README.md`；该文件只定义验收要求，不替代真实设计源。

### 5. 本地工作区仍有待提交改动

范围：`@github`

当前状态：

- 远端 PR 分支 CI 通过，当前 head 以 GitHub PR #1 为准。
- `.git/index.lock` 仍会间歇出现，普通本地 Git 操作可能被阻断。
- 本地工作区仍有本轮审查和既有发布修复改动；远端 PR 已通过 GitHub API 同步，发布前需要统一 review、对齐本地和远端状态。
- `npm run review:release-external-state` 已补充，用于发布前复核 PR checks、`git ls-files database/backups`、本地 worktree 和 `.git/index.lock`。
- 如果当前 Node 运行环境禁止 `child_process` 调用外部命令，应直接运行该脚本输出中列出的 `git` / `gh` 命令完成验收。

处理要求：

- 对本轮改动做最终 review。
- 提交后等待 GitHub Actions 中 PHPUnit、guard、contract、`composer audit`、`npm audit` 通过。
- 发布前确认 `git status --short --branch` 仅包含预期改动或已完全干净。

## 发布前最低验收清单

- GitHub PR checks 全绿。
- `npm run review:release-external-state` 通过。
- `npm run review:release-readiness` 中除“需人工授权/生产密钥”的项外均已关闭，且失败项被逐条确认。
- 生产 `.env`/密钥配置完成，`APP_DEBUG=false`。
- OpenAI/LLM 至少一次真实连通性验证通过。
- `composer audit` 与 `npm audit` 均可执行且无高危阻断。
- Codex Security repo-wide 扫描完成并产出报告。
- 发布包确认不包含 `.env`、`database/backups/`、本地采集 profile、采集报告 JSON、截图资产。
- Figma/Canva 或等价设计交付物完成归档。
- OTA 指标对外展示继续标注 OTA 渠道口径，不把 OTA-only 数据表述为全酒店经营口径。
