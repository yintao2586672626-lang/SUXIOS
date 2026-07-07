# Release Issue Register

Updated: 2026-07-07

Status: not release-ready.

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

This register is the current release issue list. It is evidence-led and must stay aligned with `docs/release_readiness_status.json`, `docs/release_verification_command_matrix.md`, and `npm run review:release-readiness`. Closed issues remain listed for auditability.

Chinese operator report: `docs/release_problem_report.zh-CN.md`.

Evidence collection checklist: `docs/release_evidence_collection.zh-CN.md`.

## Release Issues

| ID | Status | Scope | Severity | Evidence | Acceptance command | Close condition |
|---|---|---|---|---|---|---|
| `production-env-missing` | closed | `@openai-developers` | P0 release blocker | External `RELEASE_ENV_FILE` under `../release-evidence-temp/production.env` passes `npm run review:release-env`; file contents are not committed. | `npm run review:release-env` | Keep controlled production env outside the repo and rerun on the final release head. |
| `llm-connectivity-attestation-missing` | closed | `@openai-developers` | P0 release blocker | External `LLM_CONNECTIVITY_ATTESTATION_FILE` under `../release-evidence-temp/llm-attestation.json` passes `npm run review:release-llm`. | `npm run review:release-llm` | Keep the redacted production smoke-test attestation available and rerun on the final release head. |
| `design-handoff-missing` | open | `@figma` / `@canva` | P0 release blocker | No real controlled manifest is present under `../release-evidence-temp/design_handoff_manifest.json`, via `DESIGN_HANDOFF_MANIFEST_FILE`, or via the intentional local fallback `docs/design_handoff_manifest.json`; screenshots or standalone token files are not enough. A 2026-07-07 read-only connector recheck was blocked because both Figma and Canva require reauthentication. | `npm run review:release-design` | Reauthenticate Figma and Canva or provide independently controlled accessible source links; then ensure real Figma, Canva, Brand Kit, design token path, required flow coverage, owner, `last_reviewed_at` inside the 30-day release evidence window, and empty `open_issues` are present. `npm run release:create-design-manifest` can write a verifier-checked external manifest only after the Figma/Canva evidence itself is release-closing. |
| `ota-credential-rotation-attestation-missing` | open | `@codex-security` | P0 security blocker | `../release-evidence-temp/ota_credential_rotation_attestation.json` and `docs/ota_credential_rotation_attestation.json` are missing, and `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` is not set. | `npm run review:release-ota-credentials` | Ctrip and Meituan OTA Cookie, Token, signature, and Authorization material rotation or invalidation is attested by a real accountable reviewer with `reviewed_at` inside the 30-day release evidence window and without exposing values; `npm run release:create-ota-attestation` can copy a passing external reviewed JSON into the controlled evidence directory, but it does not replace the rotation itself. |
| `codex-security-scan-missing` | closed | `@codex-security` | P0 security blocker | `docs/security/codex-security/latest` contains the formal scan artifacts, and `npm run review:release-security-scan` passes. | `npm run review:release-security-scan` | Keep the scan directory available through final release and rerun the command on the final release head. |
| `local-git-state-open` | open | `@github` | P0 release handoff blocker | Current `review:release-pr-candidates` ran `gh pr list` at 2026-07-07T00:36:42+08:00 and found no open PRs for base `main`; `docs/release_github_handoff_evidence.json` was rechecked through the GitHub connector at 2026-07-07T00:32:42+08:00 and also reports no open PRs, but remains diagnostic-only and does not close PR state. Current `review:release-external-state` now refuses to fall back to merged PR #2 and fails because `RELEASE_PR_NUMBER` is not set and the local worktree is dirty. `.git/index.lock` is absent and `database/backups` has no tracked files. | `npm run review:release-pr-candidates`, `npm run review:release-staged-scope`, then `npm run review:release-external-state` | After design and OTA isolated gates pass, create or select the actual open non-draft final release PR, point `RELEASE_PR_NUMBER` at it, review staged PR scope with no runtime/local artifacts, keep local Git clean, pass release external-state evidence on that final head, and rerun release-readiness with `RELEASE_EXTERNAL_STATE_RESULT_FILE` from a controlled directory outside the repository. |

## Controlled But Not Sufficient

| Scope | Controlled evidence | Remaining risk |
|---|---|---|
| `@github` | `.git/index.lock` is absent; `database/backups` has no tracked files; the old PR #2 has only historical checks and is not sufficient. Current `gh pr list` evidence is recorded by `review:release-pr-candidates`; `docs/release_github_handoff_evidence.json` is current non-closing connector diagnostic evidence only. | Current handoff target is not selected: no open PR candidate is currently available, `RELEASE_PR_NUMBER` is not set, and the local worktree is dirty. Run `npm run review:release-pr-candidates` to discover viable open PRs; if multiple viable PRs exist, set `RELEASE_PR_NUMBER` and rerun the command so the result records the configured selection before `npm run review:release-external-state` verifies the actual final PR. |
| `@openai-developers` | AI entrypoint is `LlmClient` with encrypted `ai_model_configs`; local functional readiness covers AI decision structure; external production env and LLM connectivity attestation pass their isolated gates. | Keep external evidence available and rerun the gates on the final release head. |
| `@codex-security` | Dependency audits and lightweight security checks pass; backups are ignored, not tracked by Git; the current `review:release-ota-credentials` scan reports no credential-shaped backup text matches; formal Codex Security scan artifacts are present and `review:release-security-scan` passes. | Ctrip and Meituan OTA credential rotation attestation is still missing. |
| `@figma` | Code-side UI handoff and functional readiness cover required flows; `docs/release_figma_handoff_evidence.json` records the existing Figma file key. | The 2026-07-07 connector check returned `UNAUTHORIZED` / reauthentication required, so the Figma source cannot currently be revalidated through the connector and no controlled manifest closes the gate. |
| `@canva` | Design handoff contract requires Canva and Brand Kit metadata; `docs/release_canva_handoff_evidence.json` records the prior Canva design references. | The 2026-07-07 connector checks returned `UNAUTHORIZED` / `oauth_token_invalid_grant`, and no real Brand Kit URL or connector-verified Brand Kit source is present. |

## Required Review Order

1. Run `npm run review:functional-readiness` to confirm local business-chain structure remains intact.
2. Close each blocking issue with its isolated acceptance command.
3. For design or OTA evidence changes, rerun `npm run review:release-readiness` as an intermediate check; it is still expected to fail until external-state has a passing result.
4. After design and OTA isolated gates pass, run `npm run review:release-pr-candidates`; if multiple viable release PRs exist, set `RELEASE_PR_NUMBER` to the intended final PR and rerun the candidate review before `npm run review:release-staged-scope` and `npm run review:release-external-state` with `RELEASE_STAGED_SCOPE_RESULT_FILE` and `RELEASE_EXTERNAL_STATE_RESULT_FILE` in the controlled evidence directory outside the repository.
5. Run `npm run review:release-readiness` again so the final gate consumes the passing staged-scope and external-state results.
6. Run `npm run verify:release-status` before handoff.

## Non-Negotiable Rules

- Do not mark any issue closed from narrative evidence alone.
- Do not store real keys, Cookie values, Token values, signatures, Authorization headers, or unredacted sensitive fields in release evidence.
- Do not treat templates as production evidence.
- Do not claim Figma or Canva approval without a real controlled design manifest under `../release-evidence-temp`, `DESIGN_HANDOFF_MANIFEST_FILE`, or the intentional local fallback `docs/design_handoff_manifest.json`.
- Do not replace the formal Codex Security scan with dependency audits or lightweight scripts; keep the completed scan artifacts available for final-head verification.
- Do not delete or sanitize local backup files without explicit operator approval.
