# 上线问题报告

更新日期：2026-06-05

范围：`@github`、`@openai-developers`、`@codex-security`、`@figma`、`@canva`

结论：当前项目功能结构已具备本地验收基础，但仍不能上线使用。接入仓库外生产 env 和 LLM attestation 后，`npm run review:release-readiness` 当前结果为 `13 passed, 3 warnings, 2 failures`；剩余失败项为真实设计交付和 OTA 凭据轮换证明。PR #2 仍为 draft，正式交接前仍需最终 head 复验。

证据采集清单：`docs/release_evidence_collection.zh-CN.md`。

## 当前已受控但不足以上线

| 范围 | 已受控证据 | 仍不能上线的原因 |
|---|---|---|
| `@github` | PR 检查通过；CI 覆盖 Composer audit、npm audit、PHPUnit、P0 guards、functional readiness、release issue register、non-security review、release-status；本地采集文件已能记录 PR head、draft state、merge state、checks 和 `database/backups` 跟踪状态。 | PR 仍是 draft，本地工作树仍未关闭，`.git/index.lock` 仍存在，`review:release-external-state` 未通过。 |
| `@openai-developers` | AI 入口已收敛到 `LlmClient`，模型配置走加密数据库配置；仓库外 `RELEASE_ENV_FILE` 与 `LLM_CONNECTIVITY_ATTESTATION_FILE` 已通过单项门禁。 | 需在最终 PR head 上保留并复验外部证据。 |
| `@codex-security` | 依赖审计和轻量安全检查通过；`database/backups` 未被 Git 跟踪，当前 `review:release-ota-credentials` 未发现 backup 文本凭据形态命中；`docs/security/codex-security/latest` 已包含正式 Codex Security 扫描产物，且 `npm run review:release-security-scan` 通过。 | OTA 凭据轮换证明缺失；正式安全扫描需在最终 PR head 上保持通过。 |
| `@figma` | 代码侧 UI handoff 和功能门禁覆盖登录、OTA 数据、收益分析、AI 决策、运营管理、投资决策。 | 缺真实 Figma 源文件、设计 token、评审日期和零未解决问题的交付清单。 |
| `@canva` | 设计交付模板已要求 Canva 和 Brand Kit 元数据。 | 缺真实 Canva 设计链接和 Brand Kit 交付来源。 |

## 已关闭但需最终复验的问题

| 问题 | 范围 | 当前证据 | 复验命令 |
|---|---|---|---|
| 生产环境配置已通过外部证据 | `@openai-developers` | `RELEASE_ENV_FILE` 指向仓库外 `D:\桌面\SUXIOS\宿析OS初始版\release-evidence-temp\production.env` 时，`npm run review:release-env` 通过。 | `npm run review:release-env` |
| 生产 LLM 连通性已通过外部证据 | `@openai-developers` | `LLM_CONNECTIVITY_ATTESTATION_FILE` 指向仓库外 `D:\桌面\SUXIOS\宿析OS初始版\release-evidence-temp\llm-attestation.json` 时，`npm run review:release-llm` 通过。 | `npm run review:release-llm` |
| 正式 Codex Security 扫描产物 | `@codex-security` | `docs/security/codex-security/latest` 已存在并通过 `npm run review:release-security-scan`。 | `npm run review:release-security-scan` |

## 必须关闭的问题

| 序号 | 问题 | 范围 | 风险 | 当前证据 | 验收命令 |
|---:|---|---|---|---|---|
| 1 | Figma / Canva 真实设计交付缺失 | `@figma` / `@canva` | 不能证明品牌、设计源、设计 token、关键流程已评审 | `docs/design_handoff_manifest.json` 不存在 | `npm run review:release-design` |
| 2 | OTA 凭据轮换证明缺失 | `@codex-security` | 无法证明 Cookie、Token、签名、Authorization 等已轮换或失效 | `docs/ota_credential_rotation_attestation.json` 不存在，`OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` 未设置；backup 文本扫描干净不等于平台凭据已轮换 | `npm run review:release-ota-credentials` |
| 3 | GitHub / 本地交接状态未关闭 | `@github` | 无法完成可靠发布交接 | PR #2 仍为 draft，且需要在最终 head 上重新通过 `review:release-external-state`；过期外部状态证据不能作为发布证明 | `npm run review:release-external-state` |

## 关闭顺序

1. 先确认功能结构未退化：`npm run review:functional-readiness`。
2. 保持生产 env 与生产 LLM 外部证据可用，并在最终 PR head 上复验。
3. 处理设计交付：补齐真实 Figma、Canva、Brand Kit、design token、覆盖流程、评审日期、空 `open_issues`，并通过 `npm run review:release-design`。
4. 处理 OTA 凭据风险：先轮换或失效真实凭据，再删除、脱敏或加密归档本地备份，并通过 `npm run review:release-ota-credentials`。
5. 保持正式 Codex Security 扫描产物可用，并在最终 PR head 上复验 `npm run review:release-security-scan`。
6. 最后关闭本地 Git 状态和 PR #2 交接证据，通过 `npm run review:release-external-state`。
7. 每关闭一项都重新运行 `npm run review:release-readiness`。

## 不允许的关闭方式

- 不允许用口头说明替代验收命令。
- 不允许把模板文件当作生产证据。
- 不允许把截图或单独 token 文件当作 Figma / Canva 交付。
- 不允许把依赖审计、`verify_high_risk_security.php` 或轻量脚本当作正式 Codex Security 扫描；已生成的正式扫描产物必须保留到最终发布复验。
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
