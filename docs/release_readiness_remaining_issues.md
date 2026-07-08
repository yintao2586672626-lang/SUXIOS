# Pre-Release Remaining Issues

Updated: 2026-07-08

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

## Current Conclusion

The existing contract and review checks can pass while the project remains not release-ready. `npm run review:release-readiness` now defaults to `RELEASE_EVIDENCE_DIR` or `../release-evidence-temp` for controlled production env, LLM attestation, PR-candidate, staged-scope, and external-state result evidence, and currently reports 2 release-readiness failures: design handoff and OTA credential rotation attestation. The production env, production LLM attestation, formal Codex Security scan artifact blocker, and PR #6 external-state evidence are closed for the current evidence head and must keep passing on the final head. The main worktree still has local dirty changes, so release-closing external-state evidence must continue to come from a clean checkout whose local HEAD matches the final PR head.

Machine-readable status: `docs/release_readiness_status.json`.

Command-to-blocker matrix: `docs/release_verification_command_matrix.md`.

Functional acceptance matrix: `docs/release_functional_acceptance_matrix.md`.

Issue register: `docs/release_issue_register.md`.

Chinese operator report: `docs/release_problem_report.zh-CN.md`.

Evidence collection checklist: `docs/release_evidence_collection.zh-CN.md`.

Command result evidence: `npm run review:release-readiness` writes `release-readiness-result.json` under `RELEASE_READINESS_RESULT_FILE` or the default `RELEASE_EVIDENCE_DIR` / `../release-evidence-temp` controlled evidence directory outside the repository. The generated JSON follows `docs/release_readiness_result.example.json`, records `mode=final` or `mode=pre_ready`, and is intended for audit handoff without embedding secrets. Only `mode=final` with `final_release_ready=true` is release-ready evidence.

Optional GitHub/local-state result evidence: run `npm run review:release-pr-candidates` to discover open viable release PRs; if exactly one viable PR exists it is selected automatically, and if multiple viable PRs exist set `RELEASE_PR_NUMBER` to the intended final PR and rerun `npm run review:release-pr-candidates` so the result records both `configured_release_pr_number` and `selected_release_pr_number`. The command writes `release-pr-candidates-result.json` under `RELEASE_PR_CANDIDATES_RESULT_FILE` or `RELEASE_EVIDENCE_DIR` / `../release-evidence-temp`, outside the repository. Run `npm run review:release-staged-scope` before final PR staging; it writes `release-staged-scope-result.json` under `RELEASE_STAGED_SCOPE_RESULT_FILE` or the same controlled evidence directory and rejects runtime/local staged files. Then set `RELEASE_EXTERNAL_STATE_RESULT_FILE=<controlled-path>` when running `npm run review:release-external-state`; otherwise the command writes `release-external-state-result.json` under `RELEASE_EVIDENCE_DIR` / `../release-evidence-temp`, and `review:release-readiness` consumes the latest controlled result. External-state also records `git rev-parse HEAD` and fails unless the captured local HEAD matches the selected final PR head. If Node child_process is blocked, use `npm run review:release-external-state:local`; the wrapper collects equivalent command evidence and writes both the evidence JSON and result JSON under the controlled evidence directory outside the repository. The raw collector can still write `docs/release_external_state_evidence.local.json`, which must stay ignored and is not the final readiness result. The result JSON follows `docs/release_external_state_result.example.json`.

## Current Hard Blocker Matrix

| # | Scope | Blocker | Current evidence | Close condition |
|---:|---|---|---|---|
| 1 | `@figma` / `@canva` | Real Figma / Canva / design-token handoff is missing | No real controlled manifest is present under `../release-evidence-temp/design_handoff_manifest.json`, via `DESIGN_HANDOFF_MANIFEST_FILE`, or via `docs/design_handoff_manifest.json` with Figma source, Canva source, Brand Kit, design token, flow coverage, review date, and zero open design issues. A 2026-07-06 read-only connector recheck was blocked by Figma/Canva reauthentication requirements. | Reauthenticate Figma and Canva or provide independently controlled accessible source links; then provide accessible Figma, Canva, Brand Kit, `design_tokens_path`, required flow coverage, `last_reviewed_at` inside the 30-day release evidence window, empty `open_issues`, and pass `npm run review:release-design`. |
| 2 | `@codex-security` | OTA credential rotation attestation is missing | `../release-evidence-temp/ota_credential_rotation_attestation.json` and `docs/ota_credential_rotation_attestation.json` are missing, and `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` is not set. Current backup text scan reports no credential-shaped matches, but that does not prove real platform credential rotation or invalidation. | Provide a credential-free attestation covering platform rotation, backup cleanup, git tracking check, `reviewed_at` inside the 30-day release evidence window, readiness rerun, and pass `npm run review:release-ota-credentials`. |

## Resolved Or Partially Controlled Items

| Scope | Status | Evidence |
|---|---|---|
| GitHub CI | Historical checks only | PR `#2` had successful `PHP Composer / verify` checks, but it is now merged and stale for release handoff. Rerun/confirm checks on the actual final release PR after evidence changes. The workflow includes dependency audits, PHPUnit, P0 guards, functional-readiness review, release issue register review, release evidence intake behavior guard, release readiness behavior guard, non-security review, report/security/finance regression review, and release-status contracts. |
| Database rebuild | Fixed | `database/init_full.sql` no longer depends on local `hotelx_dump.sql`; SQL schema contracts pass in CI. |
| Daily report formula execution risk | Fixed | `DailyReport.php` removed `eval` and uses an arithmetic parser path. |
| Excel parsing command execution risk | Fixed | `DailyReport.php` removed `shell_exec` and uses array-form `proc_open`. |
| AI request entrypoint | Controlled | Unused `OpenAIClient` was removed; production AI path is `LlmClient` with encrypted database model configuration. |
| Release package sensitive paths | Controlled | `.gitignore` and `.gitattributes` exclude env files, backups, capture reports, and screenshot assets from normal tracking and archive exports. |
| Backup credential-shaped text scan | Controlled | Current `npm run review:release-ota-credentials` reports no credential-shaped matches across `database/backups` text files; OTA credential rotation attestation remains separate and open. |
| Production env evidence | Closed for current evidence head | External `RELEASE_ENV_FILE` at `../release-evidence-temp/production.env` passes `npm run review:release-env`; file contents are not committed. |
| Production LLM connectivity | Closed for current evidence head | External `LLM_CONNECTIVITY_ATTESTATION_FILE` at `../release-evidence-temp/llm-attestation.json` passes `npm run review:release-llm`. |
| Formal Codex Security scan | Closed for current release-evidence head | `docs/security/codex-security/latest` contains `scan_manifest.json`, `report.md`, `report.html`, threat model, finding discovery report, repository coverage ledger, reviewed surfaces, validation summary, and attack-path analysis report; `npm run review:release-security-scan` passes. |
| GitHub / local handoff | Closed for current PR #6 verification head | `review:release-pr-candidates` selected PR #6; `review:release-staged-scope` found no forbidden staged files; `review:release-external-state` passed from the clean `HOTEL-release-readiness-verify` checkout at `166c7ef17e169f852bfca542ed917ea76e3edb80`. The main worktree dirty changes are not release-closing evidence. |
| UI code-side handoff checklist | Added | `docs/ui-handoff/README.md` covers login, OTA data, revenue analysis, AI decision, operations management, and investment decision code-side review points. |
| Local functional acceptance gate | Added | `npm run review:functional-readiness` checks structural coverage for OTA data, revenue analysis, AI decision, operations management, and investment decision. |
| Release issue register | Added | `docs/release_issue_register.md` lists every open blocker, scope, evidence, acceptance command, and close condition. |
| GitHub external-state collector | Added | `npm run collect:release-external-state` captures local HEAD, PR head, open/draft state, merge state, checks, backup tracking, local worktree status, and `.git/index.lock` state for `review:release-external-state`. |

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

Existing verifier constraints:

- `npm run review:release-ota-credentials` reads `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE`, then `RELEASE_EVIDENCE_DIR` / `../release-evidence-temp`, then `docs/ota_credential_rotation_attestation.json` only as an intentional local fallback.
- Use `docs/ota_credential_rotation_attestation.example.json` as the fillable minimum template.
- Preferred controlled evidence location: `../release-evidence-temp/ota_credential_rotation_attestation.json`, read automatically through `RELEASE_EVIDENCE_DIR` / the default evidence directory or explicitly through `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE`.
- Repo default location, if intentionally used: `docs/ota_credential_rotation_attestation.json`.
- Do not create the default file with placeholders as release evidence. The verifier must fail until real values are filled and `redaction_checked=true` is confirmed.
- Supported platform credential actions are only `rotated` or `invalidated`.
- `encrypted_archive` and `sanitized` are backup cleanup actions only, and must not be used as `platforms[].action`.
- `backup_cleanup.database_backups_action` must be `deleted`, `encrypted_archive`, or `sanitized`.
- `backup_cleanup.paths_reviewed` must include `database/backups`.

Operator-filled real values required:

- `reviewed_at`: real review or rotation completion date in `YYYY-MM-DD`, inside the 30-day release evidence window.
- `reviewer`: accountable person who reviewed the rotation or invalidation evidence.
- `platforms[].platform`: real platform names actually used by this project, at minimum the applicable Ctrip and Meituan account scopes.
- `platforms[].scope`: real account/store scope using non-secret identifiers only, such as hotel IDs, platform merchant IDs, or internal account labels.
- `platforms[].action`: one of `rotated` or `invalidated`, matching what was actually done to the platform credentials.
- `platforms[].evidence_ref`: internal ticket, secure record, or audit artifact reference proving the action, without credential values.
- `backup_cleanup.database_backups_action`: actual backup handling result.
- `backup_cleanup.git_tracking_check`: real `git ls-files database/backups` command/date/result.
- `backup_cleanup.release_readiness_check`: real rerun command/date/result after the attestation is filled.
- `redaction_checked`: `true` only after confirming the file contains no real Cookie, Token, signature, Authorization header, account password, or reusable login state.

One-time recheck command list for this item:

```powershell
# Run from the HOTEL repository root.

$evidenceDir = Resolve-Path -LiteralPath '..\release-evidence-temp'
$env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE = Join-Path $evidenceDir 'ota_credential_rotation_attestation.json'

git ls-files database/backups
npm.cmd run review:release-ota-credentials
```

Optional full release-readiness rerun after this item is filled:

```powershell
$env:RELEASE_ENV_FILE = Join-Path $evidenceDir 'production.env'
$env:LLM_CONNECTIVITY_ATTESTATION_FILE = Join-Path $evidenceDir 'llm-attestation.json'
$env:RELEASE_READINESS_RESULT_FILE = Join-Path $evidenceDir 'release-readiness-result.json'

npm.cmd run review:release-readiness
```

If design handoff is still missing, the full release-readiness command can still fail on `design-handoff-missing`; that is outside this OTA credential item.

Required close evidence:

- Real OTA Cookie, Token, signature, and Authorization material is rotated or invalidated where applicable.
- `git ls-files database/backups` remains empty.
- `npm run review:release-ota-credentials` no longer reports credential-shaped matches across `database/backups` text files.
- `npm run review:release-readiness` no longer reports credential-shaped matches across `database/backups` text files.
- `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` or `docs/ota_credential_rotation_attestation.json` records the cleanup without exposing credentials and confirms `redaction_checked=true`.

### 5. Figma / Canva Source Handoff Is Missing

Scope: `@figma`, `@canva`

The repository has code-side UI review documentation, but no real design source of truth. This blocks listing material review, brand consistency review, and design-to-code traceability.

Latest connector status on 2026-07-06:

- Figma `_get_libraries` for `ngtbNGUVP77kO1hh9SOLQA` returned `UNAUTHORIZED` with reauthentication required.
- Canva `_list_brand_kits` and `_search_designs` returned `UNAUTHORIZED` with `oauth_token_invalid_grant`; Brand Kit and design source cannot currently be revalidated through the connector.

Existing verifier constraints:

- `npm run review:release-design` reads `DESIGN_HANDOFF_MANIFEST_FILE`, then `RELEASE_EVIDENCE_DIR` / `../release-evidence-temp`, then `docs/design_handoff_manifest.json` only as an intentional local fallback.
- If `DESIGN_HANDOFF_MANIFEST_FILE` is set, it must point outside the repository.
- Use `docs/design_handoff_manifest.example.json` as the fillable minimum template.
- Preferred controlled evidence location: `../release-evidence-temp/design_handoff_manifest.json`, read automatically through `RELEASE_EVIDENCE_DIR` / the default evidence directory or explicitly through `DESIGN_HANDOFF_MANIFEST_FILE`.
- Repo default location, if intentionally used for local review: `docs/design_handoff_manifest.json`.
- Do not create the default file with placeholders as release evidence. The verifier must fail until real source links, owner, review date, required flows, token path, and empty `open_issues` are present.
- `design_tokens_path` must be an HTTPS URL or a repo-relative existing token file; the current repo token baseline is `docs/design-tokens.release.json`.
- Standalone token files, screenshots, exported images, or code-side UI handoff notes do not close the Figma / Canva blocker.

Operator-filled real values required:

- `owner`: accountable design owner or release reviewer.
- `last_reviewed_at`: real design handoff review date in `YYYY-MM-DD`, inside the 30-day release evidence window.
- `figma_url`: accessible real `https://www.figma.com/...` source link.
- `canva_url`: accessible real `https://www.canva.com/...` editable design/source link.
- `brand_kit_url`: accessible real `https://www.canva.com/...` Brand Kit link.
- `design_tokens_path`: `docs/design-tokens.release.json` if that repo token baseline was reviewed, or a newer reviewed HTTPS token source.
- `covered_flows`: must include `login`, `home-dashboard`, `ota-data`, `revenue-analysis`, `ai-decision`, `operations-management`, and `investment-decision`.
- `open_issues`: empty array before release; any unresolved design issue keeps the blocker open.

One-time recheck command list for this item:

```powershell
# Run from the HOTEL repository root.

$evidenceDir = Resolve-Path -LiteralPath '..\release-evidence-temp'
$env:DESIGN_HANDOFF_MANIFEST_FILE = Join-Path $evidenceDir 'design_handoff_manifest.json'

npm.cmd run review:release-design
```

Optional full release-readiness rerun after this item is filled:

```powershell
$env:RELEASE_ENV_FILE = Join-Path $evidenceDir 'production.env'
$env:LLM_CONNECTIVITY_ATTESTATION_FILE = Join-Path $evidenceDir 'llm-attestation.json'
$env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE = Join-Path $evidenceDir 'ota_credential_rotation_attestation.json'
$env:RELEASE_READINESS_RESULT_FILE = Join-Path $evidenceDir 'release-readiness-result.json'

npm.cmd run review:release-readiness
```

If OTA credential rotation attestation is still missing, the full release-readiness command can still fail on `ota-credential-rotation-attestation-missing`; that is outside this design handoff item.

Required close evidence:

- `DESIGN_HANDOFF_MANIFEST_FILE` points to a controlled manifest outside the repo, or `docs/design_handoff_manifest.json` is created from `docs/design_handoff_manifest.example.json` for local default review; standalone design-token files or screenshots are not sufficient.
- It includes accessible Figma URL, Canva URL, Brand Kit URL, `design_tokens_path`, owner, review date inside the 30-day release evidence window, covered flows, and open issues.
- Covered flows include login, OTA data, revenue analysis, AI decision, operations management, and investment decision.
- `last_reviewed_at` uses `YYYY-MM-DD` and is inside the 30-day release evidence window.
- `open_issues` is an empty array before release.
- `npm run review:release-design` passes against the real manifest.
- `npm run review:release-readiness` no longer reports the design handoff failure.

### 6. Local Git State Must Stay Closed Before Release

Scope: `@github`

The previous configured handoff target is stale, but current PR evidence now selects PR #6. `review:release-external-state` passes from the clean `HOTEL-release-readiness-verify` checkout where local HEAD matches PR #6 at `166c7ef17e169f852bfca542ed917ea76e3edb80`. The main worktree is still dirty, so it must not be treated as release-closing external-state evidence.

Required close evidence:

- The actual final release PR remains selected through `RELEASE_PR_NUMBER=6` before release handoff.
- The configured final release PR is open, non-draft, mergeable, and green.
- `.git/index.lock` remains absent.
- The release-closing checkout remains clean; dirty main-worktree changes are resolved or kept explicitly isolated.
- `npm run review:release-external-state` passes, or `RELEASE_EXTERNAL_STATE_FILE` captures equivalent `git` and `gh` evidence generated by `scripts/collect_release_external_state.ps1`.
- Captured local `git rev-parse HEAD` matches the final PR head SHA selected by `npm run review:release-pr-candidates`.
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
