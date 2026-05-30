# Release Issue Register

Updated: 2026-05-30

Status: not release-ready.

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

This register is the current release issue list. It is evidence-led and must stay aligned with `docs/release_readiness_status.json`, `docs/release_verification_command_matrix.md`, and `npm run review:release-readiness`.

Chinese operator report: `docs/release_problem_report.zh-CN.md`.

Evidence collection checklist: `docs/release_evidence_collection.zh-CN.md`.

## Blocking Issues

| ID | Scope | Severity | Evidence | Acceptance command | Close condition |
|---|---|---|---|---|---|
| `production-env-missing` | `@openai-developers` | P0 release blocker | `.env.production` is missing and `RELEASE_ENV_FILE` is not set. | `npm run review:release-env` | Controlled production env exists outside the repo with `APP_DEBUG=false`, `APP_TRACE=false`, non-local `DB_HOST`, non-root `DB_USER`, and non-placeholder database and `AI_CONFIG_SECRET` values. |
| `llm-connectivity-attestation-missing` | `@openai-developers` | P0 release blocker | `docs/llm_connectivity_attestation.json` is missing and `LLM_CONNECTIVITY_ATTESTATION_FILE` is not set. | `npm run review:release-llm` | A secret-free production smoke-test attestation proves real `LlmClient` plus enabled `ai_model_configs`, and confirms `redaction_checked=true`. |
| `design-handoff-missing` | `@figma` / `@canva` | P0 release blocker | `docs/design_handoff_manifest.json` is missing; screenshots or standalone token files are not enough. | `npm run review:release-design` | Real Figma, Canva, Brand Kit, design token path, required flow coverage, owner, review date, and empty `open_issues` are present. |
| `backup-credential-shaped-fields` | `@codex-security` | P0 security blocker | `database/backups` has 4498 credential-shaped matches across 2 local SQL backup files. | `npm run review:release-ota-credentials` | Backup text files are deleted, sanitized, or encrypted-archived, and no credential-shaped matches remain. |
| `ota-credential-rotation-attestation-missing` | `@codex-security` | P0 security blocker | `docs/ota_credential_rotation_attestation.json` is missing and `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` is not set. | `npm run review:release-ota-credentials` | OTA Cookie, Token, signature, and Authorization material rotation or invalidation is attested without exposing values. |
| `codex-security-scan-missing` | `@codex-security` | P0 security blocker | `CODEX_SECURITY_SCAN_DIR` and `docs/security/codex-security/latest` are absent. | `npm run review:release-security-scan` | Formal repo-wide Codex Security scan artifacts exist: manifest, threat model, finding discovery, validation summary, attack-path analysis, coverage ledger, reviewed surfaces, and Markdown/HTML reports. |
| `local-git-state-open` | `@github` | P0 release handoff blocker | Local evidence proves PR #1 is open, mergeable, has green checks, and `database/backups` is not tracked, but PR #1 is still draft, local worktree remains dirty, and `.git/index.lock` exists. | `npm run review:release-external-state` | PR #1 is marked ready for review, local Git state is aligned with the PR, `.git/index.lock` is absent, PR checks are green, and release external-state evidence passes. |

## Controlled But Not Sufficient

| Scope | Controlled evidence | Remaining risk |
|---|---|---|
| `@github` | PR checks are green on the latest inspected head; CI runs Composer audit, npm audit, PHPUnit, P0 guards, functional readiness, non-security review, and release-status contracts; local evidence collector records PR state, draft state, and backup tracking state. | Draft PR state, local dirty worktree, and `.git/index.lock` remain open until `npm run review:release-external-state` passes. |
| `@openai-developers` | AI entrypoint is `LlmClient` with encrypted `ai_model_configs`; local functional readiness covers AI decision structure. | Production env and production model connectivity are not proven. |
| `@codex-security` | Dependency audits and lightweight security checks pass; backups are ignored and not tracked by Git. | Formal scan is missing and local backup credential-shaped content remains. |
| `@figma` | Code-side UI handoff and functional readiness cover required flows. | Real Figma source handoff is missing. |
| `@canva` | Design handoff contract requires Canva and Brand Kit metadata. | Real Canva design and Brand Kit source are missing. |

## Required Review Order

1. Run `npm run review:functional-readiness` to confirm local business-chain structure remains intact.
2. Close each blocking issue with its isolated acceptance command.
3. Run `npm run review:release-readiness` after every closed issue.
4. Run `npm run verify:release-status` before handoff.
5. Run `npm run review:release-external-state` as the final local/PR handoff gate.

## Non-Negotiable Rules

- Do not mark any issue closed from narrative evidence alone.
- Do not store real keys, Cookie values, Token values, signatures, Authorization headers, or unredacted sensitive fields in release evidence.
- Do not treat templates as production evidence.
- Do not claim Figma or Canva approval without a real `docs/design_handoff_manifest.json`.
- Do not replace the formal Codex Security scan with dependency audits or lightweight scripts.
- Do not delete or sanitize local backup files without explicit operator approval.
