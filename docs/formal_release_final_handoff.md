# Formal Release Final Handoff

Updated: 2026-06-05

Purpose: close the final production-release handoff without replacing real evidence with templates, screenshots, or narrative approval.

## Current State

- PR: `#2`
- Release branch: `codex/save-project-20260531`
- Closed evidence: production env, production LLM connectivity, formal Codex Security scan, local Git hygiene, CI checks.
- Open evidence: design handoff and OTA credential rotation attestation.
- PR #2 must remain draft until `review:release-readiness` passes with real evidence.

## Evidence To Provide

### Design Handoff

Create `docs/design_handoff_manifest.json` from `docs/design_handoff_manifest.example.json`.

Required fields:

- `owner`: real accountable design owner.
- `last_reviewed_at`: `YYYY-MM-DD`.
- `figma_url`: real accessible `https://www.figma.com/...` source link.
- `canva_url`: real accessible `https://www.canva.com/...` source link.
- `brand_kit_url`: real accessible `https://www.canva.com/...` Brand Kit link.
- `design_tokens_path`: HTTPS token source or repo-relative existing token file.
- `covered_flows`: `login`, `home-dashboard`, `ota-data`, `revenue-analysis`, `ai-decision`, `operations-management`, `investment-decision`.
- `open_issues`: empty array before release.

Do not use screenshots, exported images, placeholder URLs, or inaccessible links.

### OTA Credential Rotation

Provide `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` or `docs/ota_credential_rotation_attestation.json` after real platform rotation or invalidation.

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

```powershell
npm.cmd run review:release-design
npm.cmd run review:release-ota-credentials
npm.cmd run review:release-evidence

$evidenceDir = Resolve-Path -LiteralPath '..\release-evidence-temp'
$env:RELEASE_ENV_FILE = Join-Path $evidenceDir 'production.env'
$env:LLM_CONNECTIVITY_ATTESTATION_FILE = Join-Path $evidenceDir 'llm-attestation.json'
npm.cmd run review:release-readiness

git status --short --branch
git ls-files database/backups
```

Only after the commands above pass, mark PR #2 ready for review, wait for CI to remain green, then run:

```powershell
$env:RELEASE_PR_NUMBER='2'
npm.cmd run review:release-external-state
```

## Merge Rule

Merge PR #2 only when all of these are true:

- `review:release-readiness` passes.
- `review:release-external-state` passes with `RELEASE_PR_NUMBER=2`.
- PR #2 is not draft.
- PR #2 is mergeable.
- GitHub Actions are green on the final head.
- No new release blockers are open in `docs/release_issue_register.md`.
