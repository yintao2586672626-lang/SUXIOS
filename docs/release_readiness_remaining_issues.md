# Pre-Release Remaining Issues

Updated: 2026-05-30

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

## Current Conclusion

The GitHub CI blocker is resolved on the current PR head, and the existing contract and review checks pass. The project is still not release-ready. `npm run review:release-readiness` currently reports 7 failures: 6 release-evidence failures plus 1 local Git index/state failure. The `@github` local-state blocker remains open through `git status --short --branch` plus `npm run review:release-external-state`.

Machine-readable status: `docs/release_readiness_status.json`.

Command-to-blocker matrix: `docs/release_verification_command_matrix.md`.

Functional acceptance matrix: `docs/release_functional_acceptance_matrix.md`.

Optional command result evidence: set `RELEASE_READINESS_RESULT_FILE=<controlled-path>` when running `npm run review:release-readiness`. The generated JSON follows `docs/release_readiness_result.example.json` and is intended for audit handoff without embedding secrets.

Optional GitHub/local-state result evidence: set `RELEASE_EXTERNAL_STATE_RESULT_FILE=<controlled-path>` when running `npm run review:release-external-state`. If Node child_process is blocked, generate equivalent command evidence with `npm run collect:release-external-state`, then rerun with `RELEASE_EXTERNAL_STATE_FILE=docs/release_external_state_evidence.local.json`. On Windows, `npm run review:release-external-state:local` performs both steps. The result JSON follows `docs/release_external_state_result.example.json`.

## Current Hard Blocker Matrix

| # | Scope | Blocker | Current evidence | Close condition |
|---:|---|---|---|---|
| 1 | `@openai-developers` | Production env is missing | `review:release-readiness` reports `.env.production` is missing and `RELEASE_ENV_FILE` is not set. | Provide controlled production env outside the repository with `APP_DEBUG=false`, `APP_TRACE=false`, non-local `DB_HOST`, least-privilege `DB_USER`, and non-placeholder database and `AI_CONFIG_SECRET` values; `npm run review:release-env` must pass first. |
| 2 | `@openai-developers` | Production LLM connectivity attestation is missing | `docs/llm_connectivity_attestation.json` is missing and `LLM_CONNECTIVITY_ATTESTATION_FILE` is not set. | Run a production `LlmClient` connectivity smoke test using real `ai_model_configs`, provide a secret-free attestation JSON, and pass `npm run review:release-llm`. |
| 3 | `@figma` / `@canva` | Real Figma / Canva / design-token handoff is missing | No real `docs/design_handoff_manifest.json` is present with Figma source, Canva source, Brand Kit, design token, flow coverage, review date, and zero open design issues. | Provide accessible Figma, Canva, Brand Kit, `design_tokens_path`, required flow coverage, `last_reviewed_at` in `YYYY-MM-DD`, empty `open_issues`, and pass `npm run review:release-design`. |
| 4 | `@codex-security` | Local backups contain OTA credential-shaped fields | `review:release-readiness` reports 4498 credential-shaped matches across 2 files: `database/backups/hotelx_after_tenant_security_20260529_161926.sql` (2249) and `database/backups/hotelx_before_extended_tenant_security_20260529_162847.sql` (2249). | Delete, sanitize, or encrypted-archive local backups and pass `npm run review:release-ota-credentials` with no credential-shaped matches across backup text files. |
| 5 | `@codex-security` | OTA credential rotation attestation is missing | `docs/ota_credential_rotation_attestation.json` is missing and `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` is not set. | Provide a credential-free attestation covering platform rotation, backup cleanup, git tracking check, readiness rerun, and pass `npm run review:release-ota-credentials`. |
| 6 | `@codex-security` | Formal repo-wide Codex Security scan is missing | `CODEX_SECURITY_SCAN_DIR` and `docs/security/codex-security/latest` scan artifacts are not present. | Authorize subagents, provide `scan_manifest.json`, threat model, finding discovery, validation summary, attack-path analysis, coverage ledger, reviewed surfaces, final Markdown/HTML reports, and pass `npm run review:release-security-scan`. |
| 7 | `@github` | Local Git state is not closed | `git status --short --branch` shows a dirty local worktree, and `review:release-external-state` still requires controlled external evidence. | Align local worktree with the PR, confirm `.git/index.lock` is absent, and pass `review:release-external-state`. |

## Resolved Or Partially Controlled Items

| Scope | Status | Evidence |
|---|---|---|
| GitHub CI | Resolved on current PR head | PR `#1` has successful `PHP Composer / verify` checks; the workflow now includes dependency audits, PHPUnit, P0 guards, non-security review, and release-status contracts. |
| Database rebuild | Fixed | `database/init_full.sql` no longer depends on local `hotelx_dump.sql`; SQL schema contracts pass in CI. |
| Daily report formula execution risk | Fixed | `DailyReport.php` removed `eval` and uses an arithmetic parser path. |
| Excel parsing command execution risk | Fixed | `DailyReport.php` removed `shell_exec` and uses array-form `proc_open`. |
| AI request entrypoint | Controlled | Unused `OpenAIClient` was removed; production AI path is `LlmClient` with encrypted database model configuration. |
| Release package sensitive paths | Controlled | `.gitignore` and `.gitattributes` exclude env files, backups, capture reports, and screenshot assets from normal tracking and archive exports. |
| UI code-side handoff checklist | Added | `docs/ui-handoff/README.md` covers login, OTA data, revenue analysis, AI decision, operations management, and investment decision code-side review points. |
| Local functional acceptance gate | Added | `npm run review:functional-readiness` checks structural coverage for OTA data, revenue analysis, AI decision, operations management, and investment decision. |

## Open Problem Details

### 1. Production Configuration Is Not Verified

Scope: `@openai-developers`

The repository only contains `.example.production.env`. It is a template and intentionally contains placeholder values. It cannot prove a production release configuration.

Required close evidence:

- A controlled production env file exists outside the repository and is referenced through `RELEASE_ENV_FILE`, or `.env.production` exists in a controlled release workspace.
- `APP_DEBUG=false`.
- `APP_TRACE=false`.
- `DB_HOST` does not point to localhost or loopback.
- Database values are non-placeholder and non-empty.
- `DB_USER` is not `root`.
- `AI_CONFIG_SECRET` is present and non-placeholder.
- `RELEASE_ENV_FILE` does not point to `.example.production.env`, sample/template files, or a repo-local env file.
- `npm run review:release-env` passes against the same `RELEASE_ENV_FILE`.
- `npm run review:release-readiness` no longer reports the production env failure.

### 2. Production LLM Connectivity Is Not Proven

Scope: `@openai-developers`

The code path has been narrowed to `LlmClient` plus encrypted `ai_model_configs`, but no production connectivity attestation exists.

Required close evidence:

- A real production smoke test uses enabled `ai_model_configs`.
- The attestation follows `docs/llm_connectivity_attestation.example.json`.
- The attestation is referenced by `LLM_CONNECTIVITY_ATTESTATION_FILE` or stored as `docs/llm_connectivity_attestation.json`.
- The attestation contains no real API key, token, Cookie, signature, Authorization value, or unredacted sensitive field, and it confirms `redaction_checked=true`.
- `npm run review:release-llm` passes against the same `LLM_CONNECTIVITY_ATTESTATION_FILE`.
- `npm run review:release-readiness` no longer reports the LLM connectivity failure.

### 3. Formal Security Scan Is Not Complete

Scope: `@codex-security`

Existing checks are useful but not enough for formal release security review. `verify_high_risk_security.php`, `composer audit`, and `npm audit` do not replace a repo-wide Codex Security scan.

Required close evidence:

- Subagents are authorized for formal scan work.
- Threat model, finding discovery, validation, and attack-path analysis are completed.
- `CODEX_SECURITY_SCAN_DIR` or `docs/security/codex-security/latest` contains `scan_manifest.json`, `report.md`, `report.html`, threat model, finding discovery report, repository coverage ledger, reviewed surfaces, validation summary, and attack-path analysis report.
- `scan_manifest.json` follows `docs/codex_security_scan_manifest.example.json` and confirms `subagents_authorized=true`, all required phases are `completed`, `final_report_validated=true`, and `report_html_rendered=true`.
- `npm run review:release-security-scan` passes against the same scan directory.
- `npm run review:release-readiness` no longer reports the Codex Security scan failure.

### 4. Local OTA Backup Credential Risk Remains

Scope: `@codex-security`

`database/backups` is ignored and not tracked by Git, but local backup files still contain credential-shaped OTA fields. The release readiness scan checks text-readable backup files for Cookie, Authorization, Bearer, usertoken, usersign, access token, refresh token, session token, and API key shapes without printing values. If those values are real, they must be treated as exposed in the local backup environment.

Required close evidence:

- Real OTA Cookie, Token, signature, and Authorization material is rotated or invalidated where applicable.
- Local backup files are deleted, sanitized, or moved into controlled encrypted storage.
- `git ls-files database/backups` remains empty.
- `npm run review:release-ota-credentials` no longer reports credential-shaped matches across `database/backups` text files.
- `npm run review:release-readiness` no longer reports credential-shaped matches across `database/backups` text files.
- `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` or `docs/ota_credential_rotation_attestation.json` records the cleanup without exposing credentials and confirms `redaction_checked=true`.

### 5. Figma / Canva Source Handoff Is Missing

Scope: `@figma`, `@canva`

The repository has code-side UI review documentation, but no real design source of truth. This blocks listing material review, brand consistency review, and design-to-code traceability.

Required close evidence:

- `docs/design_handoff_manifest.json` is created from `docs/design_handoff_manifest.example.json`; standalone design-token files or screenshots are not sufficient.
- It includes accessible Figma URL, Canva URL, Brand Kit URL, `design_tokens_path`, owner, review date, covered flows, and open issues.
- Covered flows include login, OTA data, revenue analysis, AI decision, operations management, and investment decision.
- `last_reviewed_at` uses `YYYY-MM-DD`.
- `open_issues` is an empty array before release.
- `npm run review:release-design` passes against the real manifest.
- `npm run review:release-readiness` no longer reports the design handoff failure.

### 6. Local Git State Must Be Closed Before Release

Scope: `@github`

The PR branch is the current source of truth, but the local worktree remains dirty and `.git/index.lock` exists. Do not claim local release readiness until the local and remote states are aligned and verified.

Required close evidence:

- `.git/index.lock` is removed only after confirming no active Git process owns it.
- Local worktree is clean or contains only intentionally reviewed release changes.
- `npm run review:release-external-state` passes, or `RELEASE_EXTERNAL_STATE_FILE` captures equivalent `git` and `gh` evidence generated by `scripts/collect_release_external_state.ps1`.
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
