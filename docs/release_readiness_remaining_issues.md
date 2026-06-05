# Pre-Release Remaining Issues

Updated: 2026-06-05

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

## Current Conclusion

The existing contract and review checks can pass while the project remains not release-ready. With `RELEASE_ENV_FILE` and `LLM_CONNECTIVITY_ATTESTATION_FILE` pointing to the external evidence under `../release-evidence-temp`, `npm run review:release-readiness` currently reports 2 release-evidence failures: design handoff and OTA credential rotation attestation. The production env, production LLM attestation, and formal Codex Security scan artifact blockers are closed for the current evidence head and must keep passing on the final head. The `@github` local-state blocker remains separate and must be checked through `git status --short --branch` plus `npm run review:release-external-state`.

Machine-readable status: `docs/release_readiness_status.json`.

Command-to-blocker matrix: `docs/release_verification_command_matrix.md`.

Functional acceptance matrix: `docs/release_functional_acceptance_matrix.md`.

Issue register: `docs/release_issue_register.md`.

Chinese operator report: `docs/release_problem_report.zh-CN.md`.

Evidence collection checklist: `docs/release_evidence_collection.zh-CN.md`.

Optional command result evidence: set `RELEASE_READINESS_RESULT_FILE=<controlled-path>` when running `npm run review:release-readiness`. The generated JSON follows `docs/release_readiness_result.example.json` and is intended for audit handoff without embedding secrets.

Optional GitHub/local-state result evidence: set `RELEASE_EXTERNAL_STATE_RESULT_FILE=<controlled-path>` when running `npm run review:release-external-state`. If Node child_process is blocked, generate equivalent command evidence with `npm run collect:release-external-state`, then rerun with `RELEASE_EXTERNAL_STATE_FILE=docs/release_external_state_evidence.local.json`. On Windows, `npm run review:release-external-state:local` performs both steps. The result JSON follows `docs/release_external_state_result.example.json`.

## Current Hard Blocker Matrix

| # | Scope | Blocker | Current evidence | Close condition |
|---:|---|---|---|---|
| 1 | `@figma` / `@canva` | Real Figma / Canva / design-token handoff is missing | No real `docs/design_handoff_manifest.json` is present with Figma source, Canva source, Brand Kit, design token, flow coverage, review date, and zero open design issues. | Provide accessible Figma, Canva, Brand Kit, `design_tokens_path`, required flow coverage, `last_reviewed_at` in `YYYY-MM-DD`, empty `open_issues`, and pass `npm run review:release-design`. |
| 2 | `@codex-security` | OTA credential rotation attestation is missing | `docs/ota_credential_rotation_attestation.json` is missing and `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` is not set. Current backup text scan reports no credential-shaped matches, but that does not prove real platform credential rotation or invalidation. | Provide a credential-free attestation covering platform rotation, backup cleanup, git tracking check, readiness rerun, and pass `npm run review:release-ota-credentials`. |
| 3 | `@github` | GitHub / local handoff state is not closed | Local handoff must be verified against PR #2 and the current worktree state; stale external-state evidence is not enough. | Mark PR #2 ready for review only after release-readiness passes, align local worktree with the PR, confirm `.git/index.lock` is absent, confirm final-head checks are green, and pass `review:release-external-state`. |

## Resolved Or Partially Controlled Items

| Scope | Status | Evidence |
|---|---|---|
| GitHub CI | Resolved on latest verified PR #2 head | PR `#2` had successful `PHP Composer / verify` checks on the latest inspected head; rerun/confirm again after every pushed release-evidence commit. The workflow includes dependency audits, PHPUnit, P0 guards, non-security review, and release-status contracts. |
| Database rebuild | Fixed | `database/init_full.sql` no longer depends on local `hotelx_dump.sql`; SQL schema contracts pass in CI. |
| Daily report formula execution risk | Fixed | `DailyReport.php` removed `eval` and uses an arithmetic parser path. |
| Excel parsing command execution risk | Fixed | `DailyReport.php` removed `shell_exec` and uses array-form `proc_open`. |
| AI request entrypoint | Controlled | Unused `OpenAIClient` was removed; production AI path is `LlmClient` with encrypted database model configuration. |
| Release package sensitive paths | Controlled | `.gitignore` and `.gitattributes` exclude env files, backups, capture reports, and screenshot assets from normal tracking and archive exports. |
| Backup credential-shaped text scan | Controlled | Current `npm run review:release-ota-credentials` reports no credential-shaped matches across `database/backups` text files; OTA credential rotation attestation remains separate and open. |
| Production env evidence | Closed for current evidence head | External `RELEASE_ENV_FILE` at `../release-evidence-temp/production.env` passes `npm run review:release-env`; file contents are not committed. |
| Production LLM connectivity | Closed for current evidence head | External `LLM_CONNECTIVITY_ATTESTATION_FILE` at `../release-evidence-temp/llm-attestation.json` passes `npm run review:release-llm`. |
| Formal Codex Security scan | Closed for current release-evidence head | `docs/security/codex-security/latest` contains `scan_manifest.json`, `report.md`, `report.html`, threat model, finding discovery report, repository coverage ledger, reviewed surfaces, validation summary, and attack-path analysis report; `npm run review:release-security-scan` passes. |
| UI code-side handoff checklist | Added | `docs/ui-handoff/README.md` covers login, OTA data, revenue analysis, AI decision, operations management, and investment decision code-side review points. |
| Local functional acceptance gate | Added | `npm run review:functional-readiness` checks structural coverage for OTA data, revenue analysis, AI decision, operations management, and investment decision. |
| Release issue register | Added | `docs/release_issue_register.md` lists every open blocker, scope, evidence, acceptance command, and close condition. |
| GitHub external-state collector | Added | `npm run collect:release-external-state` captures PR head, open/draft state, merge state, checks, backup tracking, local worktree status, and `.git/index.lock` state for `review:release-external-state`. |

## Open Problem Details

### 1. Production Configuration Is Verified Through External Evidence

Scope: `@openai-developers`

The repository still must not contain production env values. The current release evidence uses a warehouse-external env file:

`../release-evidence-temp/production.env`

`npm run review:release-env` passes when `RELEASE_ENV_FILE` points to that path. The env file contents are intentionally not committed or printed.

Required retained evidence:

- A controlled production env file exists outside the repository and is referenced through `RELEASE_ENV_FILE`.
- `APP_DEBUG=false`.
- `APP_TRACE=false`.
- `DB_HOST` does not point to localhost or loopback.
- Database values are non-placeholder and non-empty.
- `DB_USER` is not `root`.
- `AI_CONFIG_SECRET` is present and non-placeholder.
- `RELEASE_ENV_FILE` does not point to `.example.production.env`, sample/template files, or a repo-local env file.
- `npm run review:release-env` passes against the same `RELEASE_ENV_FILE`.
- `npm run review:release-readiness` no longer reports the production env failure.

### 2. Production LLM Connectivity Is Verified Through External Evidence

Scope: `@openai-developers`

The code path has been narrowed to `LlmClient` plus encrypted `ai_model_configs`. The current release evidence uses a redacted external attestation:

`../release-evidence-temp/llm-attestation.json`

`npm run review:release-llm` passes when `LLM_CONNECTIVITY_ATTESTATION_FILE` points to that path.

Required retained evidence:

- A real production smoke test uses enabled `ai_model_configs`.
- The attestation follows `docs/llm_connectivity_attestation.example.json`.
- The attestation is referenced by `LLM_CONNECTIVITY_ATTESTATION_FILE` or stored as `docs/llm_connectivity_attestation.json`.
- The attestation contains no real API key, token, Cookie, signature, Authorization value, or unredacted sensitive field, and it confirms `redaction_checked=true`.
- `npm run review:release-llm` passes against the same `LLM_CONNECTIVITY_ATTESTATION_FILE`.
- `npm run review:release-readiness` no longer reports the LLM connectivity failure.

### 3. Formal Security Scan Is Complete For Current Evidence Head

Scope: `@codex-security`

Existing dependency and lightweight checks still do not replace the formal repo-wide Codex Security scan. That formal scan artifact set now exists and `npm run review:release-security-scan` passes.

Required retained evidence:

- Threat model, finding discovery, validation, and attack-path analysis are completed.
- `docs/security/codex-security/latest` or `CODEX_SECURITY_SCAN_DIR` contains `scan_manifest.json`, `report.md`, `report.html`, threat model, finding discovery report, repository coverage ledger, reviewed surfaces, validation summary, and attack-path analysis report.
- `scan_manifest.json` follows `docs/codex_security_scan_manifest.example.json` and confirms `subagents_authorized=true`, all required phases are `completed`, `final_report_validated=true`, and `report_html_rendered=true`.
- `npm run review:release-security-scan` passes against the same scan directory on the final release head.
- `npm run review:release-readiness` no longer reports the Codex Security scan failure.

### 4. OTA Credential Rotation Attestation Is Missing

Scope: `@codex-security`

The current backup text scan is controlled, but there is still no credential-free attestation proving real OTA Cookie, Token, signature, or Authorization material was rotated or invalidated. A clean backup scan does not close platform credential risk by itself.

Required close evidence:

- Real OTA Cookie, Token, signature, and Authorization material is rotated or invalidated where applicable.
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

The PR branch is the current source of truth. PR #2 is open, mergeable, and green on the latest inspected head, but it remains draft and final-head evidence must be refreshed after each pushed release-evidence commit. Do not claim local release readiness until the local and remote states are aligned and verified.

Required close evidence:

- PR #2 is marked ready for review before release handoff.
- `.git/index.lock` is removed only after confirming no active Git process owns it.
- Local worktree is clean or contains only intentionally reviewed release changes.
- `npm run review:release-external-state` passes, or `RELEASE_EXTERNAL_STATE_FILE` captures equivalent `git` and `gh` evidence generated by `scripts/collect_release_external_state.ps1`.
- GitHub Actions are green on the final PR head.

## Minimum Release Gate

- GitHub PR checks are green on the final head.
- `npm run verify:release-status` passes.
- `npm run review:release-external-state` passes.
- `npm run review:release-readiness` passes with real evidence files, not placeholder templates.
- Production env and LLM attestation are verified through external evidence and still pass on the final head.
- Formal Codex Security scan artifacts exist and still pass `npm run review:release-security-scan` on the final head.
- OTA credential rotation and backup cleanup are attested without exposing credentials.
- Figma / Canva / Brand Kit / design-token handoff is present and reviewed.
- OTA-only metrics remain clearly labeled as OTA channel scope, not whole-hotel operating scope.
