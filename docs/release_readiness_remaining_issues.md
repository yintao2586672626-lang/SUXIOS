# Pre-Release Remaining Issues

Updated: 2026-05-30

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

## Current Conclusion

The GitHub CI blocker is resolved on the current PR head, and the existing contract and review checks pass. The project is still not release-ready. `npm run review:release-readiness` currently reports 6 direct release-evidence failures, and the `@github` local-state blocker remains open through `git status --short --branch` plus `npm run review:release-external-state`.

Machine-readable status: `docs/release_readiness_status.json`.

Optional command result evidence: set `RELEASE_READINESS_RESULT_FILE=<controlled-path>` when running `npm run review:release-readiness`. The generated JSON follows `docs/release_readiness_result.example.json` and is intended for audit handoff without embedding secrets.

Optional GitHub/local-state result evidence: set `RELEASE_EXTERNAL_STATE_RESULT_FILE=<controlled-path>` when running `npm run review:release-external-state`. The generated JSON follows `docs/release_external_state_result.example.json`.

## Current Hard Blocker Matrix

| # | Scope | Blocker | Current evidence | Close condition |
|---:|---|---|---|---|
| 1 | `@openai-developers` | Production env is missing | `review:release-readiness` reports `.env.production` is missing and `RELEASE_ENV_FILE` is not set. | Provide controlled production env with `APP_DEBUG=false` and non-placeholder database and `AI_CONFIG_SECRET` values. |
| 2 | `@openai-developers` | Production LLM connectivity attestation is missing | `docs/llm_connectivity_attestation.json` is missing and `LLM_CONNECTIVITY_ATTESTATION_FILE` is not set. | Run a production `LlmClient` connectivity smoke test using real `ai_model_configs` and provide a secret-free attestation JSON. |
| 3 | `@figma` / `@canva` | Real Figma / Canva / design-token handoff is missing | No real `docs/design_handoff_manifest.json`, Figma source, Canva source, Brand Kit, or design-token artifact is present. | Provide accessible Figma, Canva, Brand Kit, `design_tokens_path`, and required flow coverage. |
| 4 | `@codex-security` | Local backups contain OTA credential-shaped fields | `review:release-readiness` reports 112 credential-shaped matches under `database/backups`. | Delete, sanitize, or encrypted-archive local backups and rerun the readiness check with no credential-shaped matches. |
| 5 | `@codex-security` | OTA credential rotation attestation is missing | `docs/ota_credential_rotation_attestation.json` is missing and `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` is not set. | Provide a credential-free attestation covering platform rotation, backup cleanup, git tracking check, and readiness rerun. |
| 6 | `@codex-security` | Formal repo-wide Codex Security scan is missing | `CODEX_SECURITY_SCAN_DIR` and `docs/security/codex-security/latest` scan artifacts are not present. | Authorize subagents and complete threat model, finding discovery, validation, attack-path analysis, and final Markdown/HTML reports. |
| 7 | `@github` | Local Git state is not closed | `git status --short --branch` shows a dirty local worktree, and `review:release-external-state` still requires controlled external evidence. | Align local worktree with the PR, confirm `.git/index.lock` is absent, and pass `review:release-external-state`. |

## Resolved Or Partially Controlled Items

| Scope | Status | Evidence |
|---|---|---|
| GitHub CI | Resolved on current PR head | PR `#1` has two successful `PHP Composer / verify` checks on the latest inspected head. |
| Database rebuild | Fixed | `database/init_full.sql` no longer depends on local `hotelx_dump.sql`; SQL schema contracts pass in CI. |
| Daily report formula execution risk | Fixed | `DailyReport.php` removed `eval` and uses an arithmetic parser path. |
| Excel parsing command execution risk | Fixed | `DailyReport.php` removed `shell_exec` and uses array-form `proc_open`. |
| AI request entrypoint | Controlled | Unused `OpenAIClient` was removed; production AI path is `LlmClient` with encrypted database model configuration. |
| Release package sensitive paths | Controlled | `.gitignore` and `.gitattributes` exclude env files, backups, capture reports, and screenshot assets from normal tracking and archive exports. |
| UI code-side handoff checklist | Added | `docs/ui-handoff/README.md` covers login, OTA data, revenue analysis, AI decision, operations management, and investment decision code-side review points. |

## Open Problem Details

### 1. Production Configuration Is Not Verified

Scope: `@openai-developers`

The repository only contains `.example.production.env`. It is a template and intentionally contains placeholder values. It cannot prove a production release configuration.

Required close evidence:

- A controlled production env file exists outside git and is referenced through `RELEASE_ENV_FILE`, or `.env.production` exists in a controlled release workspace.
- `APP_DEBUG=false`.
- Database values are non-placeholder and non-empty.
- `AI_CONFIG_SECRET` is present and non-placeholder.
- `npm run review:release-readiness` no longer reports the production env failure.

### 2. Production LLM Connectivity Is Not Proven

Scope: `@openai-developers`

The code path has been narrowed to `LlmClient` plus encrypted `ai_model_configs`, but no production connectivity attestation exists.

Required close evidence:

- A real production smoke test uses enabled `ai_model_configs`.
- The attestation follows `docs/llm_connectivity_attestation.example.json`.
- The attestation is referenced by `LLM_CONNECTIVITY_ATTESTATION_FILE` or stored as `docs/llm_connectivity_attestation.json`.
- The attestation contains no real API key, token, Cookie, signature, or Authorization value.

### 3. Formal Security Scan Is Not Complete

Scope: `@codex-security`

Existing checks are useful but not enough for formal release security review. `verify_high_risk_security.php`, `composer audit`, and `npm audit` do not replace a repo-wide Codex Security scan.

Required close evidence:

- Subagents are authorized for formal scan work.
- Threat model, finding discovery, validation, and attack-path analysis are completed.
- `CODEX_SECURITY_SCAN_DIR` or `docs/security/codex-security/latest` contains `report.md` and `report.html`.
- `npm run review:release-readiness` no longer reports the Codex Security scan failure.

### 4. Local OTA Backup Credential Risk Remains

Scope: `@codex-security`

`database/backups` is ignored and not tracked by Git, but local backup files still contain credential-shaped OTA fields. If those values are real, they must be treated as exposed in the local backup environment.

Required close evidence:

- Real OTA Cookie, Token, signature, and Authorization material is rotated or invalidated where applicable.
- Local backup files are deleted, sanitized, or moved into controlled encrypted storage.
- `git ls-files database/backups` remains empty.
- `npm run review:release-readiness` no longer reports credential-shaped matches.
- `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` or `docs/ota_credential_rotation_attestation.json` records the cleanup without exposing credentials.

### 5. Figma / Canva Source Handoff Is Missing

Scope: `@figma`, `@canva`

The repository has code-side UI review documentation, but no real design source of truth. This blocks listing material review, brand consistency review, and design-to-code traceability.

Required close evidence:

- `docs/design_handoff_manifest.json` is created from `docs/design_handoff_manifest.example.json`.
- It includes accessible Figma URL, Canva URL, Brand Kit URL, `design_tokens_path`, owner, review date, covered flows, and open issues.
- Covered flows include login, OTA data, revenue analysis, AI decision, operations management, and investment decision.
- `npm run review:release-readiness` no longer reports the design handoff failure.

### 6. Local Git State Must Be Closed Before Release

Scope: `@github`

The PR branch is the current source of truth, but the local worktree remains dirty and `.git/index.lock` exists. Do not claim local release readiness until the local and remote states are aligned and verified.

Required close evidence:

- `.git/index.lock` is removed only after confirming no active Git process owns it.
- Local worktree is clean or contains only intentionally reviewed release changes.
- `npm run review:release-external-state` passes, or `RELEASE_EXTERNAL_STATE_FILE` captures equivalent `git` and `gh` evidence.
- GitHub Actions are green on the final PR head.

## Minimum Release Gate

- GitHub PR checks are green on the final head.
- `npm run verify:release-status` passes.
- `npm run review:release-external-state` passes.
- `npm run review:release-readiness` passes with real evidence files, not placeholder templates.
- Production env and LLM attestation are verified.
- Formal Codex Security scan artifacts exist.
- OTA credential rotation and backup cleanup are attested without exposing credentials.
- Figma / Canva / Brand Kit / design-token handoff is present and reviewed.
- OTA-only metrics remain clearly labeled as OTA channel scope, not whole-hotel operating scope.
