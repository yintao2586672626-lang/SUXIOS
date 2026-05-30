# 上市前剩余问题清单

更新时间：2026-05-30

范围：`@github`、`@openai-developers`、`@codex-security`、`@figma`、`@canva`

## 当前结论

项目的 GitHub CI 阻断已解除，核心测试和现有高风险安全脚本已通过。上市前仍不应直接发布，主要剩余问题集中在生产配置、完整安全审计、敏感本地备份、设计交付物和本地 Git 环境。

## 已解除的阻断

| 范围 | 状态 | 当前证据 |
|---|---|---|
| GitHub CI | 已通过 | PR `#1` 当前 head 的两个 `PHP Composer / verify` 均为 `SUCCESS` |
| 数据库重建 | 已修复 | `database/init_full.sql` 不再依赖本地 `hotelx_dump.sql`，SQL schema contract 通过 |
| 日报公式执行风险 | 已修复 | `DailyReport.php` 已移除 `eval`，改为四则运算解析器 |
| Excel 解析命令执行风险 | 已修复 | `DailyReport.php` 已移除 `shell_exec`，改为 `proc_open` 数组命令 |
| AI 调用入口 | 已收口 | 未使用的 `OpenAIClient` 已移除，生产 AI 路径为 `LlmClient` + 数据库加密模型配置 |
| 采集报告误提交 | 已收口 | `.gitignore` 已忽略 `reports/ctrip_browser_capture_*.json` 与 `reports/meituan_browser_capture_*.json` |

## 仍存在的问题

### 1. 生产环境配置未达发布状态

范围：`@openai-developers`、发布配置

当前仓库未提供可验证的生产 env 文件。`npm run review:release-readiness` 默认检查 `.env.production`，也可以通过 `RELEASE_ENV_FILE` 指向受控生产配置文件。

| 配置项 | 当前状态 | 风险 |
|---|---|---|
| `.env.production` / `RELEASE_ENV_FILE` | 未提供 | 无法证明生产 `APP_DEBUG=false`、数据库密码非空、AI 密钥解密配置正确 |
| `AI_CONFIG_SECRET` | 本地 `.env` 已配置，长度 64 | 只能证明本地开发值存在，不能替代生产密钥验收 |

处理要求：

- 生产环境使用独立环境变量或部署密钥管理，不提交 `.env`。
- 按 `docs/deployment_env_checklist.md` 准备受控生产 env，并用 `RELEASE_ENV_FILE` 运行发布就绪检查。
- OpenAI/LLM 生产入口已收口为 `LlmClient` + 数据库 `ai_model_configs` 加密配置；上线前仍需确认生产数据库已配置启用模型。
- 上线前用真实生产配置做一次受控 API 连通性验证。

### 2. 完整 Codex Security 扫描未完成

范围：`@codex-security`

已完成：

- `php scripts/verify_high_risk_security.php` 通过。
- `npm audit --audit-level=moderate --json` 为 0 漏洞。
- `rg` 确认 `app/` 与 `scripts/` 范围内未再命中 `eval(` / `shell_exec(`。
- `npm run review:release-readiness` 已新增，用于把发布阻断项显式暴露出来。
- 发布包敏感路径 ignore 规则已校验通过，覆盖 `.env`、生产 env、数据库备份、采集 profile、采集报告 JSON 和截图目录。
- `.gitattributes` 已补充 `export-ignore`，用于 `git archive` 场景排除 `.env`、数据库备份、采集报告和截图资产。

未完成：

- 正式 repo-wide Codex Security 扫描需要授权 subagents，当前未获得授权，因此没有完整扫描的 markdown/html 报告。
- `composer audit --no-interaction` 无法运行，本机未安装 `composer`，也没有 `composer.phar`。

处理要求：

- 授权 subagents 后执行完整 security-scan 流程。
- 在 CI 或本机安装 Composer 后补跑 `composer audit`。

### 3. 本地备份目录存在 OTA 凭证形态数据

范围：`@codex-security`、OTA 数据安全

证据：

- `database/backups/` 被 `.gitignore` 忽略，当前未作为源码提交。
- `git ls-files database/backups` 无输出，当前没有备份文件被 Git 跟踪。
- `.gitattributes` 已将 `database/backups/*` 标记为 `export-ignore`。
- 本地 `database/backups/*.sql` 中命中 `usertoken`、`usersign`、`cookies` 等携程认证字段。

风险：

- 如果这些是真实 OTA 凭证，应视为已暴露在本机备份中。
- 若打包、迁移、部署时误带入 `database/backups/`，会造成严重凭证泄露。

处理要求：

- 不把 `database/backups/` 放入任何发布包。
- 对真实 OTA Cookie/Token 执行轮换或失效处理。
- 生产备份使用加密存储和最小权限访问。

### 4. Figma / Canva 设计交付物缺失

范围：`@figma`、`@canva`

证据：

- 仓库内未发现 `.fig`、`.canva`、`.sketch`、`.xd`、`design-tokens.json`、品牌规范或设计交付文档。
- 代码中存在页面和样式，但没有可追溯到 Figma/Canva 的设计源。

风险：

- 上市材料、品牌一致性、UI 验收和后续设计迭代缺少单一来源。
- 无法证明当前界面与设计稿一致。

处理要求：

- 补充 Figma/Canva 链接、导出稿、品牌规范、色彩/字体/组件 token。
- 建立 UI 走查清单：登录、首页、OTA 数据、收益分析、AI 决策、运营管理、投资决策。
- 代码侧走查要求见 `docs/ui-handoff/README.md`；该文件只定义验收要求，不替代真实设计源。

### 5. 本地 Git 环境未完全恢复

范围：`@github`

当前状态：

- 远端 PR 分支 CI 通过，当前 head 以 GitHub PR #1 为准。
- 本地存在残留 Git 进程和 `.git/index.lock`，普通本地 `git add/commit` 被阻断。
- 因此本地 `git status` 仍显示远端已提交的改动为本地修改。

处理要求：

- 清理残留 Git 进程和 `.git/index.lock`。
- 重新 `fetch` 后将本地分支对齐远端 `codex/five-modules-p1`。
- 对齐前不要基于当前本地 index 继续做普通 Git 提交。

## 发布前最低验收清单

- GitHub PR checks 全绿。
- `npm run review:release-readiness` 中除“需人工授权/生产密钥”的项外均已关闭，且失败项被逐条确认。
- 生产 `.env`/密钥配置完成，`APP_DEBUG=false`。
- OpenAI/LLM 至少一次真实连通性验证通过。
- `composer audit` 与 `npm audit` 均可执行且无高危阻断。
- Codex Security repo-wide 扫描完成并产出报告。
- 发布包确认不包含 `.env`、`database/backups/`、本地采集 profile、采集报告 JSON、截图资产。
- Figma/Canva 或等价设计交付物完成归档。
- OTA 指标对外展示继续标注 OTA 渠道口径，不把 OTA-only 数据表述为全酒店经营口径。
