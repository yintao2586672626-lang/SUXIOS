# 上线问题报告

更新日期：2026-07-25

结论：当前仍不能上线使用。本文只保存稳定的发布规则，不保存分支、HEAD、工作树数量、`.git/index.lock`、PR 编号或门禁通过次数。任何历史快照都不能替代现场复验。

当前本地事实先运行 `npm run state:refresh`，读取 `vault/current-state.md`。最终上线状态只能由当前 checkout 现场运行 `npm run review:release-readiness` 得出；输出必须晚于该本地快照，并来自仓库外受控证据目录。

历史快照：`docs/release_readiness_status.json`，仅供审计，`current_use_forbidden=true`。

当前稳定策略：`docs/release_blocker_policy.json`。

## 必须现场复验的阻塞项

| ID | 范围 | 现场验收命令 | 当前策略状态 |
|---|---|---|---|
| `production-env-missing` | `@openai-developers` | `npm run review:release-env` | `live_review_required` |
| `llm-connectivity-attestation-missing` | `@openai-developers` | `npm run review:release-llm` | `live_review_required` |
| `design-handoff-missing` | `@figma` / `@canva` | `npm run review:release-design` | `live_review_required` |
| `ota-credential-rotation-attestation-missing` | `@codex-security` | `npm run review:release-ota-credentials` | `live_review_required` |
| `codex-security-scan-missing` | `@codex-security` | `npm run review:release-security-scan` | `live_review_required` |
| `local-git-state-open` | `@github` | `npm run review:release-pr-candidates`、`npm run review:release-staged-scope`、`npm run review:release-external-state` | `live_review_required` |

## 最低上线门禁

```bash
npm run state:check
npm run review:functional-readiness
npm run review:release-issues
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

只有最终 `npm run review:release-readiness` 返回 `mode=final`、`final_release_ready=true`，且使用同一最终 PR head 的新鲜证据，才可标记上线就绪。

## 不允许的关闭方式

- 不允许用口头说明替代验收命令。
- 不允许把模板、草稿、截图或旧 JSON 当作当前生产证据。
- 不允许复用旧 PR、旧 HEAD、旧工作树或旧 `.git/index.lock` 结论。
- 不允许把依赖审计或轻量脚本当作正式 Codex Security 扫描。
- 不允许用 `0`、空数组、旧数据或默认值掩盖缺失采集和失败状态。
- 不允许在未获明确授权前删除、脱敏或移动本地备份。
