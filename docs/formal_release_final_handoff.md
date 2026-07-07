# Formal Release Final Handoff

Updated: 2026-07-06

Purpose: close the final production-release handoff without replacing real evidence with templates, screenshots, or narrative approval.

## Current State

- Previous configured PR: `#2` (`MERGED`; stale for final handoff)
- Release branch: `codex/save-project-20260531`
- Closed evidence: production env, production LLM connectivity, formal Codex Security scan.
- Open evidence: design handoff, OTA credential rotation attestation, clean local Git state, and actual final release PR selection.
- Do not reuse merged PR #2 as the final handoff target. After design and OTA evidence pass their isolated gates, run `review:release-pr-candidates`, set `RELEASE_PR_NUMBER` to the actual selected open release PR, run `review:release-staged-scope` before final PR staging, use `release:mark-pr-ready` only as a guarded draft-to-ready transition when needed, then run `review:release-external-state` from a checkout whose local HEAD matches that PR head and rerun `review:release-readiness` with the passing staged-scope and external-state results.

## Evidence To Provide

Use the controlled evidence directory outside the repository:

```text
../release-evidence-temp
```

Minimum file contract:

| Evidence | File |
|---|---|
| Production env | `production.env` |
| LLM connectivity attestation | `llm-attestation.json` |
| Design handoff manifest | `design_handoff_manifest.json` |
| OTA credential rotation attestation | `ota_credential_rotation_attestation.json` |
| Optional Codex Security scan override | `codex-security/latest/` |
| Release evidence result | `release-evidence-result.json` |
| Release PR candidates result | `release-pr-candidates-result.json` |
| Release external-state evidence | `release-external-state-evidence.json` |
| Release external-state result | `release-external-state-result.json` |
| Release readiness result | `release-readiness-result.json` |

### Design Handoff

Create `../release-evidence-temp/design_handoff_manifest.json` from `docs/design_handoff_manifest.example.json`, or use `docs/design_handoff_manifest.json` only for an intentional local default review.

Required fields:

- `owner`: real accountable design owner.
- `last_reviewed_at`: `YYYY-MM-DD`.
- `figma_url`: real accessible `https://www.figma.com/...` source link.
- `canva_url`: real accessible `https://www.canva.com/...` source link.
- `brand_kit_url`: real accessible `https://www.canva.com/...` Brand Kit link.
- `design_tokens_path`: use `docs/design-tokens.release.json` unless the design owner provides a newer reviewed HTTPS token source.
- `covered_flows`: `login`, `home-dashboard`, `ota-data`, `revenue-analysis`, `ai-decision`, `operations-management`, `investment-decision`.
- `open_issues`: empty array before release.

Do not use screenshots, exported images, placeholder URLs, or inaccessible links.
The repo-side token artifact is already available at `docs/design-tokens.release.json`; it does not close the blocker without real Figma, Canva, and Brand Kit source links.

### OTA Credential Rotation

Create `../release-evidence-temp/ota_credential_rotation_attestation.json` from `docs/ota_credential_rotation_attestation.example.json`, or use `docs/ota_credential_rotation_attestation.json` only for an intentional local default review after real platform rotation or invalidation.

Required coverage:

- Ctrip and Meituan accounts actually used by the project, where applicable.
- Cookie, Token, signature, Authorization, `usertoken`, and `usersign` material.
- `redaction_checked: true`.
- `backup_cleanup.paths_reviewed` includes `database/backups`.
- `backup_cleanup.git_tracking_check` records reviewed `git ls-files database/backups` evidence.
- `backup_cleanup.release_readiness_check` records reviewed release-readiness evidence.

Do not store real credentials, Cookie values, Token values, signatures, Authorization headers, account passwords, or reusable login state.

## Final Commands

Run from the `HOTEL` repository root.

Preferred runner:

```powershell
npm.cmd run review:release-final-handoff
```

Continuation helper after external evidence files are supplied:

```powershell
npm.cmd run release:continue-handoff
```

In normal mode, `release:continue-handoff` first verifies the controlled production, LLM, design, OTA, and evidence-bundle inputs. It then runs `review:release-pr-candidates`; if exactly one viable final PR is selected, it reads `selected_release_pr_number` from `release-pr-candidates-result.json` and continues. The PR candidate result records the current `gh pr list` source and `gh_pr_list_checked_at`; any GitHub connector handoff file is diagnostic-only and may be stale, so it must not replace the current PR candidate result. Set `RELEASE_PR_NUMBER` explicitly when multiple viable PRs exist, or when using `-MarkPrReady` / `-AfterPrReady`.

Equivalent manual commands:

```powershell
$evidenceDir = Resolve-Path -LiteralPath '..\release-evidence-temp'
$env:RELEASE_ENV_FILE = Join-Path $evidenceDir 'production.env'
$env:LLM_CONNECTIVITY_ATTESTATION_FILE = Join-Path $evidenceDir 'llm-attestation.json'
$env:DESIGN_HANDOFF_MANIFEST_FILE = Join-Path $evidenceDir 'design_handoff_manifest.json'
$env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE = Join-Path $evidenceDir 'ota_credential_rotation_attestation.json'
$env:RELEASE_EVIDENCE_RESULT_FILE = Join-Path $evidenceDir 'release-evidence-result.json'
$env:RELEASE_READINESS_RESULT_FILE = Join-Path $evidenceDir 'release-readiness-result.json'
$env:RELEASE_PR_CANDIDATES_RESULT_FILE = Join-Path $evidenceDir 'release-pr-candidates-result.json'
$env:RELEASE_STAGED_SCOPE_RESULT_FILE = Join-Path $evidenceDir 'release-staged-scope-result.json'

npm.cmd run review:release-design
npm.cmd run review:release-ota-credentials
npm.cmd run review:release-evidence
npm.cmd run review:release-pr-candidates
# If multiple viable release PRs exist, set RELEASE_PR_NUMBER to the intended final PR
# and rerun review:release-pr-candidates before continuing so the result records it.
npm.cmd run review:release-staged-scope
$env:RELEASE_EXTERNAL_STATE_FILE = Join-Path $evidenceDir 'release-external-state-evidence.json'
$env:RELEASE_EXTERNAL_STATE_RESULT_FILE = Join-Path $evidenceDir 'release-external-state-result.json'
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collect_release_external_state.ps1 -OutputPath $env:RELEASE_EXTERNAL_STATE_FILE -PrNumber $env:RELEASE_PR_NUMBER
npm.cmd run review:release-external-state
npm.cmd run review:release-readiness

git status --short --branch
git ls-files database/backups
```

If the selected final release PR is still draft, mark that PR ready for review only through the guarded runner after design, OTA, evidence-bundle, PR-candidate, and staged-scope gates pass. The runner first writes `release-pr-ready-candidates-result.json` with `RELEASE_PR_CANDIDATES_ALLOW_DRAFT=1`, refuses an implicit PR #2 target, requires local `git rev-parse HEAD` to match the selected PR head, reruns the pre-ready final handoff gates with external-state explicitly pending, and requires the selected ready target to match `RELEASE_PR_NUMBER` when it is set. This transition does not close release readiness:

```powershell
$env:RELEASE_PR_NUMBER = '<actual-open-release-pr-number>'
npm.cmd run release:mark-pr-ready
```

After GitHub Actions remain green, run the after-ready final handoff from the same final PR head so external-state and readiness are checked against the non-draft final PR head:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/review_release_final_handoff.ps1 -EvidenceDir '..\release-evidence-temp' -AfterPrReady
```

## Merge Rule

Merge the configured final release PR only when all of these are true:

- `review:release-external-state` passes with `RELEASE_PR_NUMBER` set to the actual open release PR.
- `review:release-pr-candidates` passes and selects the same final release PR.
- `review:release-staged-scope` passes and rejects runtime/local staged files before final PR staging.
- The local HEAD captured by `review:release-external-state` matches the selected final PR head.
- `review:release-readiness` passes while consuming the passing staged-scope and external-state results from the controlled evidence directory outside the repository.
- The configured release PR is not draft.
- The configured release PR is mergeable.
- GitHub Actions are green on the final head.
- No new release blockers are open in `docs/release_issue_register.md`.
