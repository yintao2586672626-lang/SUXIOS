# 上市阻断项关闭计划

更新时间：2026-05-30

范围：`@github`、`@openai-developers`、`@codex-security`、`@figma`、`@canva`

来源：`docs/release_readiness_status.json` `blockers`

## 关闭顺序

| 顺序 | blocker id | 范围 | 关闭动作 | 验收证据 |
|---:|---|---|---|---|
| 1 | `local-git-state-open` | `@github` | 清理 `.git/index.lock`，对齐本地 worktree 与 PR 分支，复核 PR checks | `npm run review:release-external-state` 通过，或 `RELEASE_EXTERNAL_STATE_FILE` 证明通过 |
| 2 | `production-env-missing` | `@openai-developers` | 准备受控生产 env，不提交密钥文件 | `RELEASE_ENV_FILE` 指向真实配置，`npm run review:release-readiness` 不再报告生产 env 缺失 |
| 3 | `llm-connectivity-attestation-missing` | `@openai-developers` | 用生产 `ai_model_configs` 做 LLM 连通性测试 | `LLM_CONNECTIVITY_ATTESTATION_FILE` 或 `docs/llm_connectivity_attestation.json` 通过，且不包含密钥 |
| 4 | `backup-credential-shaped-fields` | `@codex-security` | 删除、脱敏或加密归档 `database/backups` 中凭证形态数据 | `npm run review:release-readiness` 不再报告 credential-shaped matches |
| 5 | `ota-credential-rotation-attestation-missing` | `@codex-security` | 轮换/失效 OTA Cookie、Token、签名，并记录清理结果 | `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` 或 `docs/ota_credential_rotation_attestation.json` 通过，且不含真实凭证 |
| 6 | `codex-security-scan-missing` | `@codex-security` | 授权 subagents，完成正式 repo-wide security scan | `CODEX_SECURITY_SCAN_DIR` 或 `docs/security/codex-security/latest` 包含 Markdown/HTML 报告与核心 coverage artifacts |
| 7 | `design-handoff-missing` | `@figma` / `@canva` | 补齐 Figma、Canva、Brand Kit、design tokens 和流程覆盖 | `docs/design_handoff_manifest.json` 通过 `npm run review:release-readiness` |

## 关闭原则

- 每关闭一项后立即复跑 `npm run review:release-readiness`，不要等全部完成后再集中验证。
- 涉及密钥、Cookie、Token、Authorization 的证明文件只保留内部工单或安全存储引用。
- `docs/release_readiness_status.json` 中 blocker 的 `status` 只有在对应验收命令通过后才能从 `open` 调整。
- Figma / Canva 交付物不能只给截图；必须包含可访问源链接、Brand Kit 和 design token 位置。
- Codex Security 正式扫描不能用 `verify_high_risk_security.php`、`composer audit`、`npm audit` 替代。
