# 上线证据采集清单

更新日期：2026-07-06

范围：`@github`、`@openai-developers`、`@codex-security`、`@figma`、`@canva`

用途：指导各处理人补齐上线阻断所需证据。所有证据必须先通过对应的独立验收命令；最终 GitHub / 本地交接还必须先通过 `npm run review:release-staged-scope` 和 `npm run review:release-external-state` 并生成受控结果，再进入 `npm run review:release-readiness` 总门禁。

## 当前受控入口

1. 先运行 `npm run refresh:release-current-evidence`，刷新仓库外 `../release-evidence-temp` 下的 current evidence、gap pack、operator intake packet 和 worktree quarantine 结果；该命令在阻断未关闭时应失败，但 `npm run verify:release-evidence-gap-pack`、`npm run verify:release-operator-intake` 和 parse check 必须通过。
2. 查看 `../release-evidence-temp/release-operator-intake-packet-current.md`。该文件只作为外部处理人补证清单，不是设计交付、OTA 凭据轮换、PR、本地状态或 release-ready 证据。
3. 按 `operator_intake_packet.required_external_inputs` 分别补齐 `design_handoff_manifest`、`ota_credential_rotation_attestation`、`final_release_pr_and_local_state` 三类输入；不要用截图、草稿、模板、connector 失败记录或 worktree quarantine 替代最终证据。
4. `worktree_staging_summary.bucket_counts` 中的 `candidate_release_scope`、`needs_explicit_operator_decision`、`must_remain_local_by_default` 只用于拆分当前 dirty worktree；它不 stage、不 commit、不创建 PR，也不关闭 `npm run review:release-readiness`。

## 证据总表

| 阻断项 | 处理范围 | 证据位置 | 禁止内容 | 独立验收命令 |
|---|---|---|---|---|
| 生产环境配置外部证据 | `@openai-developers` | 仓库外生产 env 文件，通过 `RELEASE_ENV_FILE` 指定；当前通过路径为 `D:\桌面\SUXIOS\宿析OS初始版\release-evidence-temp\production.env`，不得提交到 Git。 | 数据库密码、API Key 明文、示例占位值、repo 内 env 文件。 | `npm run review:release-env` |
| 生产 LLM 连通性外部证据 | `@openai-developers` | `LLM_CONNECTIVITY_ATTESTATION_FILE` 或 `docs/llm_connectivity_attestation.json`；当前通过路径为 `D:\桌面\SUXIOS\宿析OS初始版\release-evidence-temp\llm-attestation.json`，结构参考 `docs/llm_connectivity_attestation.example.json`。 | API Key、Bearer Token、Cookie、Authorization、未脱敏 provider secret。 | `npm run review:release-llm` |
| Figma / Canva 真实设计交付缺失 | `@figma` / `@canva` | `DESIGN_HANDOFF_MANIFEST_FILE` 指向仓库外受控清单，或本地默认 `docs/design_handoff_manifest.json`；结构参考 `docs/design_handoff_manifest.example.json`。 | 无访问权限链接、截图替代源文件、未评审 open issue、伪造 Brand Kit。 | `npm run review:release-design` |
| 本地备份存在 OTA 凭据形态字段 | `@codex-security` | 清理后的 `database/backups` 状态；必要时附加仓库外加密归档记录。 | Cookie、Token、usertoken、usersign、签名、Authorization 明文。 | `npm run review:release-ota-credentials` |
| OTA 凭据轮换证明缺失 | `@codex-security` | `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` 或 `docs/ota_credential_rotation_attestation.json`，结构参考 `docs/ota_credential_rotation_attestation.example.json`。 | 真实凭据值、可复用登录态、未脱敏平台返回。 | `npm run review:release-ota-credentials` |
| 正式 Codex Security 扫描产物 | `@codex-security` | `CODEX_SECURITY_SCAN_DIR` 或 `docs/security/codex-security/latest`，含 `scan_manifest.json`，结构参考 `docs/codex_security_scan_manifest.example.json`；当前仓库已提供 `docs/security/codex-security/latest`。 | 只放依赖审计结果、缺失 HTML 报告、缺失验证摘要、缺失攻击路径分析。 | `npm run review:release-security-scan` |
| GitHub / 本地交接状态未关闭 | `@github` | 仓库外 `RELEASE_STAGED_SCOPE_RESULT_FILE`、`RELEASE_EXTERNAL_STATE_FILE` 与 `RELEASE_EXTERNAL_STATE_RESULT_FILE`，结构参考 `docs/release_external_state_evidence.example.json` 与 `docs/release_external_state_result.example.json`；原始 collector 的 `docs/release_external_state_evidence.local.json` 只作为 ignored 本地临时证据。 | draft PR、未解释的 dirty worktree、staged runtime/local 文件、仍存在 `.git/index.lock`、未确认 PR checks。 | `npm run review:release-staged-scope`，再 `npm run review:release-external-state` |

## 采集步骤

1. 先运行 `npm run review:release-issues`，确认当前问题清单没有漂移。
2. 处理对应证据文件或仓库外证据位置。
3. 确认文件中没有真实密钥、Cookie、Token、Authorization、签名或未脱敏敏感字段。
4. 运行对应独立验收命令。
5. 设计、OTA 等独立命令通过后，可运行 `npm run review:release-readiness` 做中间检查；在 external-state 结果通过前它仍应失败。
6. 所有设计、OTA、安全与生产证据阻断关闭后，设置实际 open final release PR 的 `RELEASE_PR_NUMBER`，先运行 `npm run review:release-staged-scope` 检查 staged 范围，再运行 `npm run review:release-external-state` 并把结果写到仓库外受控证据目录。
7. 最后运行 `npm run review:release-readiness`，确认它消费通过的 staged-scope 和 external-state 结果，再运行 `npm run verify:release-status`。

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
- 外部草稿填完后先运行 `npm run review:release-evidence-drafts`；该命令会输出 `blocking_fields` 和 `required_operator_inputs` 作为补证清单，但只证明草稿可进入受控 promotion，不代表 release readiness 已闭合。重新运行 `npm run prepare:release-evidence-drafts` 默认不会覆盖已有草稿；只有明确丢弃旧草稿时才设置 `RELEASE_EVIDENCE_DRAFT_OVERWRITE=1`。
- 2026-07-06 connector recheck: Figma and Canva both returned `UNAUTHORIZED` / reauthentication required, so connector-backed design handoff cannot close until the connections are reauthenticated or independently controlled accessible links are supplied.

### `@codex-security`

- 备份清理前必须先确认是否需要保留审计证据；未经明确授权不得删除或脱敏本地备份。
- 凭据轮换证明必须覆盖携程、美团等实际涉及平台。
- OTA 草稿填完后先运行 `npm run review:release-evidence-drafts`；先按结果里的 `blocking_fields` 和 `required_operator_inputs` 清空占位内容，通过后再运行 `npm run promote:release-evidence-drafts` 写入最终外部 evidence 路径。
- 正式 Codex Security 扫描必须包含 threat model、finding discovery、validation summary、attack-path analysis、coverage ledger、reviewed surfaces、Markdown/HTML final report。
- `composer audit`、`npm audit`、`verify_high_risk_security.php` 不能替代正式扫描。

### `@github`

- 最终交接前必须确认 PR head、open/draft state、PR checks、merge state、`git ls-files database/backups`、`git status --short --branch`，并确认本地 `git rev-parse HEAD` 与最终 PR head 一致。
- `.git/index.lock` 只能在确认没有活跃 Git 进程后处理。
- `npm run review:release-external-state:local` 会把本地采集证据和 result 写到仓库外 evidence 目录；`docs/release_external_state_evidence.local.json` 仅是原始 collector 的 ignored 临时文件。

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
npm run review:release-pr-candidates
npm run review:release-staged-scope
npm run review:release-external-state
npm run review:release-readiness
npm run verify:release-status
```

Evidence freshness note: design `last_reviewed_at` and OTA credential `reviewed_at` must be inside the 30-day release evidence window before final release readiness.
