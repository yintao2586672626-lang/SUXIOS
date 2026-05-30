# 上线问题报告

更新日期：2026-05-30

范围：`@github`、`@openai-developers`、`@codex-security`、`@figma`、`@canva`

结论：当前项目功能结构已具备本地验收基础，但仍不能上线使用。`npm run review:release-readiness` 当前结果为 `5 passed, 3 warnings, 7 failures`，7 个失败项均为上线阻断。

## 当前已受控但不足以上线

| 范围 | 已受控证据 | 仍不能上线的原因 |
|---|---|---|
| `@github` | PR 检查通过；CI 覆盖 Composer audit、npm audit、PHPUnit、P0 guards、functional readiness、release issue register、non-security review、release-status。 | 本地工作树仍未关闭，`.git/index.lock` 仍存在，`review:release-external-state` 未通过。 |
| `@openai-developers` | AI 入口已收敛到 `LlmClient`，模型配置走加密数据库配置，功能结构门禁覆盖 AI 决策链路。 | 缺真实生产环境配置，缺生产 LLM 连通性证明。 |
| `@codex-security` | 依赖审计和轻量安全检查通过；`database/backups` 未被 Git 跟踪。 | 本地备份仍有 OTA 凭据形态字段，正式 Codex Security 全仓扫描缺失。 |
| `@figma` | 代码侧 UI handoff 和功能门禁覆盖登录、OTA 数据、收益分析、AI 决策、运营管理、投资决策。 | 缺真实 Figma 源文件、设计 token、评审日期和零未解决问题的交付清单。 |
| `@canva` | 设计交付模板已要求 Canva 和 Brand Kit 元数据。 | 缺真实 Canva 设计链接和 Brand Kit 交付来源。 |

## 必须关闭的问题

| 序号 | 问题 | 范围 | 风险 | 当前证据 | 验收命令 |
|---:|---|---|---|---|---|
| 1 | 生产环境配置缺失 | `@openai-developers` | 无法证明正式环境安全可运行 | `.env.production` 不存在，`RELEASE_ENV_FILE` 未设置 | `npm run review:release-env` |
| 2 | 生产 LLM 连通性未证明 | `@openai-developers` | AI 决策链路不能证明在生产模型配置下可用 | `docs/llm_connectivity_attestation.json` 不存在，`LLM_CONNECTIVITY_ATTESTATION_FILE` 未设置 | `npm run review:release-llm` |
| 3 | Figma / Canva 真实设计交付缺失 | `@figma` / `@canva` | 不能证明品牌、设计源、设计 token、关键流程已评审 | `docs/design_handoff_manifest.json` 不存在 | `npm run review:release-design` |
| 4 | 本地备份存在 OTA 凭据形态字段 | `@codex-security` | 若字段为真实值，应视为本地备份环境暴露 | `database/backups` 两个 SQL 文件共 4498 个匹配 | `npm run review:release-ota-credentials` |
| 5 | OTA 凭据轮换证明缺失 | `@codex-security` | 无法证明 Cookie、Token、签名、Authorization 等已轮换或失效 | `docs/ota_credential_rotation_attestation.json` 不存在，`OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` 未设置 | `npm run review:release-ota-credentials` |
| 6 | 正式 Codex Security 扫描缺失 | `@codex-security` | 不能用依赖审计或轻量脚本替代发布级安全审查 | `CODEX_SECURITY_SCAN_DIR` 和 `docs/security/codex-security/latest` 均不存在 | `npm run review:release-security-scan` |
| 7 | 本地 Git 状态未关闭 | `@github` | 无法完成可靠发布交接 | 本地工作树有未提交状态，`.git/index.lock` 存在 | `npm run review:release-external-state` |

## 关闭顺序

1. 先确认功能结构未退化：`npm run review:functional-readiness`。
2. 处理生产环境：提供仓库外生产 env，并通过 `npm run review:release-env`。
3. 处理生产 LLM：用真实 `ai_model_configs` 走 `LlmClient` 烟测，生成脱敏证明，并通过 `npm run review:release-llm`。
4. 处理设计交付：补齐真实 Figma、Canva、Brand Kit、design token、覆盖流程、评审日期、空 `open_issues`，并通过 `npm run review:release-design`。
5. 处理 OTA 凭据风险：先轮换或失效真实凭据，再删除、脱敏或加密归档本地备份，并通过 `npm run review:release-ota-credentials`。
6. 执行正式 Codex Security 全仓扫描，生成规定 artifacts，并通过 `npm run review:release-security-scan`。
7. 最后关闭本地 Git 状态和 PR 交接证据，通过 `npm run review:release-external-state`。
8. 每关闭一项都重新运行 `npm run review:release-readiness`。

## 不允许的关闭方式

- 不允许用口头说明替代验收命令。
- 不允许把模板文件当作生产证据。
- 不允许把截图或单独 token 文件当作 Figma / Canva 交付。
- 不允许把依赖审计、`verify_high_risk_security.php` 或轻量脚本当作正式 Codex Security 扫描。
- 不允许把缺失采集、缺失字段或失败状态写成兜底成功。
- 不允许在未获明确授权前删除或脱敏本地备份文件。

## 当前最低上线门禁

上线前必须全部通过：

```bash
npm run review:functional-readiness
npm run review:release-issues
npm run review:release-env
npm run review:release-llm
npm run review:release-design
npm run review:release-ota-credentials
npm run review:release-security-scan
npm run review:release-external-state
npm run review:release-readiness
npm run verify:release-status
```
