# 上线证据采集清单

更新日期：2026-05-30

范围：`@github`、`@openai-developers`、`@codex-security`、`@figma`、`@canva`

用途：指导各处理人补齐上线阻断所需证据。所有证据必须先通过对应的独立验收命令，再进入 `npm run review:release-readiness` 总门禁。

## 证据总表

| 阻断项 | 处理范围 | 证据位置 | 禁止内容 | 独立验收命令 |
|---|---|---|---|---|
| 生产环境配置外部证据 | `@openai-developers` | 仓库外生产 env 文件，通过 `RELEASE_ENV_FILE` 指定；当前通过路径为 `D:\桌面\SUXIOS\宿析OS初始版\release-evidence-temp\production.env`，不得提交到 Git。 | 数据库密码、API Key 明文、示例占位值、repo 内 env 文件。 | `npm run review:release-env` |
| 生产 LLM 连通性外部证据 | `@openai-developers` | `LLM_CONNECTIVITY_ATTESTATION_FILE` 或 `docs/llm_connectivity_attestation.json`；当前通过路径为 `D:\桌面\SUXIOS\宿析OS初始版\release-evidence-temp\llm-attestation.json`，结构参考 `docs/llm_connectivity_attestation.example.json`。 | API Key、Bearer Token、Cookie、Authorization、未脱敏 provider secret。 | `npm run review:release-llm` |
| Figma / Canva 真实设计交付缺失 | `@figma` / `@canva` | `DESIGN_HANDOFF_MANIFEST_FILE` 指向仓库外受控清单，或本地默认 `docs/design_handoff_manifest.json`；结构参考 `docs/design_handoff_manifest.example.json`。 | 无访问权限链接、截图替代源文件、未评审 open issue、伪造 Brand Kit。 | `npm run review:release-design` |
| 本地备份存在 OTA 凭据形态字段 | `@codex-security` | 清理后的 `database/backups` 状态；必要时附加仓库外加密归档记录。 | Cookie、Token、usertoken、usersign、签名、Authorization 明文。 | `npm run review:release-ota-credentials` |
| OTA 凭据轮换证明缺失 | `@codex-security` | `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` 或 `docs/ota_credential_rotation_attestation.json`，结构参考 `docs/ota_credential_rotation_attestation.example.json`。 | 真实凭据值、可复用登录态、未脱敏平台返回。 | `npm run review:release-ota-credentials` |
| 正式 Codex Security 扫描产物 | `@codex-security` | `CODEX_SECURITY_SCAN_DIR` 或 `docs/security/codex-security/latest`，含 `scan_manifest.json`，结构参考 `docs/codex_security_scan_manifest.example.json`；当前仓库已提供 `docs/security/codex-security/latest`。 | 只放依赖审计结果、缺失 HTML 报告、缺失验证摘要、缺失攻击路径分析。 | `npm run review:release-security-scan` |
| GitHub / 本地交接状态未关闭 | `@github` | `RELEASE_EXTERNAL_STATE_FILE` 或 `docs/release_external_state_evidence.local.json`，结构参考 `docs/release_external_state_evidence.example.json`。 | draft PR、未解释的 dirty worktree、仍存在 `.git/index.lock`、未确认 PR checks。 | `npm run review:release-external-state` |

## 采集步骤

1. 先运行 `npm run review:release-issues`，确认当前问题清单没有漂移。
2. 处理对应证据文件或仓库外证据位置。
3. 确认文件中没有真实密钥、Cookie、Token、Authorization、签名或未脱敏敏感字段。
4. 运行对应独立验收命令。
5. 独立命令通过后，再运行 `npm run review:release-readiness`。
6. 所有阻断关闭后，运行 `npm run verify:release-status` 和 `npm run review:release-external-state`。

## 各范围交付要求

### `@openai-developers`

- 生产 env 必须在仓库外或受控发布环境中提供。
- `RELEASE_ENV_FILE` 不能指向 `.example.production.env`、示例文件、模板文件或仓库内未受控 env。
- LLM 证明必须说明使用 `LlmClient`、启用的 `ai_model_configs.model_key`、provider、model、base URL、响应状态和耗时。
- LLM 证明必须包含 `redaction_checked=true`。

### `@figma` / `@canva`

- 必须提供真实可访问的 `figma_url`、`canva_url`、`brand_kit_url`。
- 必须提供 `design_tokens_path`，并覆盖登录、OTA 数据、收益分析、AI 决策、运营管理、投资决策。
- `open_issues` 上线前必须为空数组。
- 截图、导出图片或单独 token 文件不能替代源设计交付。

### `@codex-security`

- 备份清理前必须先确认是否需要保留审计证据；未经明确授权不得删除或脱敏本地备份。
- 凭据轮换证明必须覆盖携程、美团等实际涉及平台。
- 正式 Codex Security 扫描必须包含 threat model、finding discovery、validation summary、attack-path analysis、coverage ledger、reviewed surfaces、Markdown/HTML final report。
- `composer audit`、`npm audit`、`verify_high_risk_security.php` 不能替代正式扫描。

### `@github`

- 最终交接前必须确认 PR head、open/draft state、PR checks、merge state、`git ls-files database/backups`、`git status --short --branch`。
- `.git/index.lock` 只能在确认没有活跃 Git 进程后处理。
- `docs/release_external_state_evidence.local.json` 是本地证据文件，必须保持 Git 忽略。

## 总门禁

全部阻断关闭后，必须通过：

```bash
npm run review:release-issues
npm run review:functional-readiness
npm run review:release-env
npm run review:release-llm
npm run review:release-design
npm run review:release-ota-credentials
npm run review:release-security-scan
npm run review:release-external-state
npm run review:release-readiness
npm run verify:release-status
```
