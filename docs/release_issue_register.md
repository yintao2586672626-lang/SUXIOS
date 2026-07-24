# Release Issue Register

Updated: 2026-07-23

Status: not release-ready.

Evidence note: the 2026-07-23 local recheck confirms production env and LLM attestation still pass, while design handoff, OTA credential/backup safety, formal security scan freshness, and clean final-head evidence remain open. This is not live release approval; staged-scope, PR-head, and external-state checks must be rerun on the final clean checkout.

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

This register is the current release issue list. It is evidence-led and must stay aligned with `docs/release_readiness_status.json`, `docs/release_verification_command_matrix.md`, and `npm run review:release-readiness`. Closed issues remain listed for auditability.

Chinese operator report: `docs/release_problem_report.zh-CN.md`.

Evidence collection checklist: `docs/release_evidence_collection.zh-CN.md`.

## Release Issues

| ID | Status | Scope | Severity | Evidence | Acceptance command | Close condition |
|---|---|---|---|---|---|---|
| `production-env-missing` | closed | `@openai-developers` | P0 release blocker | External `RELEASE_ENV_FILE` under `../release-evidence-temp/production.env` passes `npm run review:release-env`; file contents are not committed. | `npm run review:release-env` | Keep controlled production env outside the repo and rerun on the final release head. |
| `llm-connectivity-attestation-missing` | closed | `@openai-developers` | P0 release blocker | External `LLM_CONNECTIVITY_ATTESTATION_FILE` under `../release-evidence-temp/llm-attestation.json` passes `npm run review:release-llm`. | `npm run review:release-llm` | Keep the redacted production smoke-test attestation available and rerun on the final release head. |
| `design-handoff-missing` | open | `@figma` / `@canva` | P0 release blocker | The 2026-07-23 `npm run review:release-design` recheck found no controlled manifest under `../release-evidence-temp/design_handoff_manifest.json`, `DESIGN_HANDOFF_MANIFEST_FILE`, or the intentional local fallback `docs/design_handoff_manifest.json`; screenshots or standalone token files are not enough. | `npm run review:release-design` | Reauthenticate Figma and Canva or provide independently controlled accessible source links; then ensure real Figma, Canva, Brand Kit, design token path, required flow coverage, owner, `last_reviewed_at` inside the 30-day release evidence window, and empty `open_issues` are present. `npm run release:create-design-manifest` can write a verifier-checked external manifest only after the Figma/Canva evidence itself is release-closing. |
| `ota-credential-rotation-attestation-missing` | open | `@codex-security` | P0 security blocker | The 2026-07-23 verifier confirmed the backup directory is ignored/untracked but found 38 value-bearing OTA credential risk matches in one local SQL backup, and no controlled credential rotation attestation exists. Values were not printed. Local ACLs now restrict the backup directory to the current administrator, local Administrators, and SYSTEM. The Tencent Cloud release packer now excludes `database/backups` and refuses an archive containing forbidden sensitive/runtime paths. | `npm run review:release-ota-credentials` | Rotate or invalidate relevant Ctrip/Meituan Cookie, Token, signature, and Authorization material; move, encrypt outside the checkout, or sanitize the reviewed backup with explicit operator approval; then provide an accountable, hash-bound attestation inside the 30-day evidence window. |
| `codex-security-scan-missing` | open | `@codex-security` | P0 security blocker | The 2026-07-23 verifier found the formal scan review date (`2026-06-05`) outside the 30-day window and its manifest commit (`deb56423e5d6c3a58b904b2de9d6e9cdb47fbeb2`) different from the current checkout. The lightweight high-risk security verifier passes but does not replace the formal scan. | `npm run review:release-security-scan` | Complete and validate a new repository-wide formal scan bound to the final clean release commit, with `reviewed_at` inside the 30-day evidence window. |
| `local-git-state-open` | open | `@github` | P0 release handoff blocker | The 2026-07-23 checkout is on `agent/fix-daily-review-gates` at `26663a1da71aedd133474264ec63386d47bf8e6f` with tracked and untracked changes, so the historical clean PR #6 result cannot close the current handoff. | `npm run review:release-pr-candidates`, `npm run review:release-staged-scope`, then `npm run review:release-external-state` | Settle concurrent work, verify and commit only owned files, select the intended final PR, then rerun staged-scope and external-state from a clean checkout whose local HEAD matches the final PR head. |

## Controlled But Not Sufficient

| Scope | Controlled evidence | Remaining risk |
|---|---|---|
| `@github` | `.git/index.lock` is absent and `database/backups` has no tracked files. Historical PR #6 evidence remains audit context only. | The current checkout is dirty and does not prove final PR/head alignment; rerun candidate, staged-scope, and external-state gates after the worktree settles. |
| `@openai-developers` | AI entrypoint is `LlmClient` with encrypted `ai_model_configs`; local functional readiness covers AI decision structure; external production env and LLM connectivity attestation pass their isolated gates. | Keep external evidence available and rerun the gates on the final release head. |
| `@codex-security` | The current lightweight high-risk security verifier passes; backups remain ignored/untracked; backup-directory ACLs are restricted to local administrative principals; the release packer now excludes and post-validates forbidden backup/runtime paths. | The local backup still has value-bearing credential risk matches, no rotation attestation exists, and the formal scan is stale and bound to another commit. |
| `@figma` | Code-side UI handoff and functional readiness cover required flows; `docs/release_figma_handoff_evidence.json` records the existing Figma file key. | The 2026-07-07 connector check returned `UNAUTHORIZED` / reauthentication required, so the Figma source cannot currently be revalidated through the connector and no controlled manifest closes the gate. |
| `@canva` | Design handoff contract requires Canva and Brand Kit metadata; `docs/release_canva_handoff_evidence.json` records the prior Canva design references. | The 2026-07-07 connector checks returned `UNAUTHORIZED` / `oauth_token_invalid_grant`, and no real Brand Kit URL or connector-verified Brand Kit source is present. |

## Required Review Order

1. Run `npm run review:functional-readiness` to confirm local business-chain structure remains intact.
2. Close each blocking issue with its isolated acceptance command.
3. For design or OTA evidence changes, rerun `npm run review:release-readiness` as an intermediate check; it is still expected to fail until both design handoff and OTA credential rotation evidence pass.
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
