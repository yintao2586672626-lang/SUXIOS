# Release Issue Register

Updated: 2026-06-05

Status: not release-ready.

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

This register is the current release issue list. It is evidence-led and must stay aligned with `docs/release_readiness_status.json`, `docs/release_verification_command_matrix.md`, and `npm run review:release-readiness`. Closed issues remain listed for auditability.

Chinese operator report: `docs/release_problem_report.zh-CN.md`.

Evidence collection checklist: `docs/release_evidence_collection.zh-CN.md`.

## Release Issues

| ID | Status | Scope | Severity | Evidence | Acceptance command | Close condition |
|---|---|---|---|---|---|---|
| `production-env-missing` | closed | `@openai-developers` | P0 release blocker | External `RELEASE_ENV_FILE` under `../release-evidence-temp/production.env` passes `npm run review:release-env`; file contents are not committed. | `npm run review:release-env` | Keep controlled production env outside the repo and rerun on the final PR #2 head. |
| `llm-connectivity-attestation-missing` | closed | `@openai-developers` | P0 release blocker | External `LLM_CONNECTIVITY_ATTESTATION_FILE` under `../release-evidence-temp/llm-attestation.json` passes `npm run review:release-llm`. | `npm run review:release-llm` | Keep the redacted production smoke-test attestation available and rerun on the final PR #2 head. |
| `design-handoff-missing` | open | `@figma` / `@canva` | P0 release blocker | No real controlled manifest is present via `DESIGN_HANDOFF_MANIFEST_FILE` or `docs/design_handoff_manifest.json`; screenshots or standalone token files are not enough. | `npm run review:release-design` | Real Figma, Canva, Brand Kit, design token path, required flow coverage, owner, review date, and empty `open_issues` are present. |
| `ota-credential-rotation-attestation-missing` | open | `@codex-security` | P0 security blocker | `docs/ota_credential_rotation_attestation.json` is missing and `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` is not set. | `npm run review:release-ota-credentials` | OTA Cookie, Token, signature, and Authorization material rotation or invalidation is attested without exposing values. |
| `codex-security-scan-missing` | closed | `@codex-security` | P0 security blocker | `docs/security/codex-security/latest` contains the formal scan artifacts, and `npm run review:release-security-scan` passes. | `npm run review:release-security-scan` | Keep the scan directory available through final release and rerun the command on the final PR #2 head. |
| `local-git-state-open` | open | `@github` | P0 release handoff blocker | PR #2 is open, mergeable, green on the latest verified head, local worktree is clean, `.git/index.lock` is absent, and `database/backups` has no tracked files; PR #2 is still draft. | `npm run review:release-external-state` | PR #2 is marked ready for review after release-readiness passes, and release external-state evidence passes on the final head. |

## Controlled But Not Sufficient

| Scope | Controlled evidence | Remaining risk |
|---|---|---|
| `@github` | PR #2 is open, mergeable, and green on the latest inspected head; local worktree is clean; `.git/index.lock` is absent; `database/backups` has no tracked files; CI runs Composer audit, npm audit, PHPUnit, P0 guards, functional readiness, non-security review, and release-status contracts. | Draft PR state remains open until `npm run review:release-external-state` passes. |
| `@openai-developers` | AI entrypoint is `LlmClient` with encrypted `ai_model_configs`; local functional readiness covers AI decision structure; external production env and LLM connectivity attestation pass their isolated gates. | Keep external evidence available and rerun the gates on the final PR #2 head. |
| `@codex-security` | Dependency audits and lightweight security checks pass; backups are ignored, not tracked by Git; the current `review:release-ota-credentials` scan reports no credential-shaped backup text matches; formal Codex Security scan artifacts are present and `review:release-security-scan` passes. | OTA credential rotation attestation is still missing. |
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
- Do not claim Figma or Canva approval without a real `DESIGN_HANDOFF_MANIFEST_FILE` or `docs/design_handoff_manifest.json`.
- Do not replace the formal Codex Security scan with dependency audits or lightweight scripts; keep the completed scan artifacts available for final-head verification.
- Do not delete or sanitize local backup files without explicit operator approval.
