# Release Issue Register

Updated: 2026-07-25

Status: not release-ready.

Evidence note: this register stores blocker policy and acceptance commands, not transient branch, HEAD, worktree, `.git/index.lock`, PR, or pass-count facts. Refresh `vault/current-state.md`, then generate new controlled gate evidence. Historical evidence never grants live release approval.

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

Current policy: `docs/release_blocker_policy.json`.

Historical snapshot: `docs/release_readiness_status.json` (`current_use_forbidden=true`).

Command matrix: `docs/release_verification_command_matrix.md`.

Chinese operator report: `docs/release_problem_report.zh-CN.md`.

Evidence collection checklist: `docs/release_evidence_collection.zh-CN.md`.

## Release Issues

| ID | Status | Scope | Severity | Acceptance command | Close condition |
|---|---|---|---|---|---|
| `production-env-missing` | live_review_required | `@openai-developers` | P0 | `npm run review:release-env` | Fresh controlled production env evidence passes on the final release head. |
| `llm-connectivity-attestation-missing` | live_review_required | `@openai-developers` | P0 | `npm run review:release-llm` | Fresh redacted production LLM connectivity evidence passes on the final release head. |
| `design-handoff-missing` | live_review_required | `@figma` / `@canva` | P0 | `npm run review:release-design` | Controlled Figma, Canva, Brand Kit, design-token, flow, owner, review-date, and zero-open-issue evidence passes. |
| `ota-credential-rotation-attestation-missing` | live_review_required | `@codex-security` | P0 | `npm run review:release-ota-credentials` | Fresh accountable, redacted, hash-bound Ctrip and Meituan rotation evidence passes. |
| `codex-security-scan-missing` | live_review_required | `@codex-security` | P0 | `npm run review:release-security-scan` | A fresh repository-wide formal scan bound to the final release commit passes. |
| `local-git-state-open` | live_review_required | `@github` | P0 | `npm run review:release-pr-candidates`; `npm run review:release-staged-scope`; `npm run review:release-external-state` | Fresh evidence proves a clean checkout, absent `.git/index.lock`, selected open green PR, and exact local HEAD equals PR head. |

## Required Review Order

1. Run `npm run state:refresh` and inspect `vault/current-state.md`.
2. Run `npm run review:functional-readiness`.
3. Run each isolated blocker acceptance command.
4. Select the final PR with `npm run review:release-pr-candidates`.
5. Run `npm run review:release-staged-scope` and `npm run review:release-external-state` on the same final head.
6. Run `npm run review:release-readiness`.
7. Run `npm run verify:release-status`.

Only `npm run review:release-readiness` returning `mode=final` and `final_release_ready=true` from fresh evidence generated after `vault/current-state.md` closes release readiness.

## Non-Negotiable Rules

- Do not mark any issue closed from narrative evidence alone.
- Do not reuse historical PR, HEAD, worktree, `.git/index.lock`, or gate-count facts.
- Do not store real credentials, tokens, cookies, signatures, authorization headers, or unredacted secret values in tracked evidence.
- Do not delete or sanitize local backup files without explicit operator approval.
- Do not replace the formal Codex Security scan with dependency audit or lightweight security checks.
- Do not use templates, drafts, screenshots, old results, `0`, empty arrays, or defaults as proof of current success.
