# Release Verification Command Matrix

Updated: 2026-06-05

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

Purpose: keep each release issue tied to one isolated acceptance command before running the global release gate. Closed issues remain in the matrix for final-head reruns.

## Command Matrix

| Blocker id | Scope | Isolated command | Required input or evidence | Current expected status | Close condition |
|---|---|---|---|---|---|
| `local-git-state-open` | `@github` | `npm run review:release-external-state` | Non-draft PR, clean local Git state, absent `.git/index.lock`, PR metadata, PR checks, and `git ls-files database/backups` evidence. | Fails only while PR #2 remains draft; local worktree, index, backup tracking, mergeability, and checks currently pass. | Command passes, or `RELEASE_EXTERNAL_STATE_FILE` points to equivalent reviewed evidence. |
| `local-git-state-open` | `@github` | `npm run review:release-external-state:local` | Windows collector output at `docs/release_external_state_evidence.local.json`; this file must stay ignored. | Fails until generated evidence proves local state is closed. | Wrapper exits with the Node verifier status code and passes. |
| `production-env-missing` | `@openai-developers` | `npm run review:release-env` | `RELEASE_ENV_FILE` points to controlled production env outside the repo, or a controlled `.env.production` exists in a release workspace. | Passes with `../release-evidence-temp/production.env`; rerun on the final PR #2 head. | `APP_DEBUG=false`, `APP_TRACE=false`, non-local `DB_HOST`, non-root `DB_USER`, and non-placeholder database and `AI_CONFIG_SECRET` values are verified. |
| `llm-connectivity-attestation-missing` | `@openai-developers` | `npm run review:release-llm` | `LLM_CONNECTIVITY_ATTESTATION_FILE` or `docs/llm_connectivity_attestation.json`. | Passes with `../release-evidence-temp/llm-attestation.json`; rerun on the final PR #2 head. | Attestation proves the real `LlmClient` path and enabled `ai_model_configs` were tested, contains no secrets, and confirms `redaction_checked=true`. |
| `design-handoff-missing` | `@figma` / `@canva` | `npm run review:release-design` | `docs/design_handoff_manifest.json`. | Fails until real design source handoff exists. | Manifest includes accessible Figma, Canva, Brand Kit, design token path, required flow coverage, review date, owner, and empty `open_issues`. |
| `ota-credential-rotation-attestation-missing` | `@codex-security` | `npm run review:release-ota-credentials` | `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` or `docs/ota_credential_rotation_attestation.json`. | Fails until credential-free rotation attestation exists. | OTA Cookie, Token, signature, and Authorization material rotation or invalidation is attested without exposing values. |
| `codex-security-scan-missing` | `@codex-security` | `npm run review:release-security-scan` | `CODEX_SECURITY_SCAN_DIR` or `docs/security/codex-security/latest`. | Passes with `docs/security/codex-security/latest`; rerun on the final PR #2 head. | Scan directory contains manifest, threat model, finding discovery, validation summary, attack-path analysis, coverage ledger, reviewed surfaces, and Markdown/HTML reports. |

## Global Gates

Run these only after the isolated command for each closed blocker passes.

| Gate | Command | Expected current status | Purpose |
|---|---|---|---|
| Evidence bundle preflight | `npm run review:release-evidence` | Fails while design handoff or OTA credential rotation evidence is missing. | One read-only preflight for the controlled evidence directory plus repo-fixed release evidence paths. |
| Final handoff runner | `npm run review:release-final-handoff` | Fails while design handoff or OTA credential rotation evidence is missing. | Runs the final pre-ready gate sequence in the required order and stops before PR-ready handoff. |
| PR ready guard | `npm run release:mark-pr-ready` | Fails while final handoff runner fails. | Marks PR #2 ready only after final handoff gates pass. |
| Release readiness | `npm run review:release-readiness` | Fails while any blocker above remains open. | Single release gate for production env, LLM, design handoff, OTA credentials, persistent Codex Security scan artifacts, and local Git state. |
| Status contract | `npm run verify:release-status` | Passes. | Confirms release docs, examples, scripts, and blocker contracts stay consistent. |
| Functional readiness | `npm run review:functional-readiness` | Passes. | Confirms local structural coverage for OTA data, revenue analysis, AI decision, operations management, and investment decision. |
| Issue register | `npm run review:release-issues` | Passes. | Confirms the current release issue register still lists all open blockers and acceptance commands. |
| Non-security review | `npm run review:non-security` | Passes. | Confirms the non-security release review contract still passes. |

## Execution Rules

- Do not mark a blocker closed from narrative evidence alone; its isolated command must pass first.
- Do not store real keys, Cookie values, Token values, signatures, Authorization headers, or unredacted sensitive fields in evidence files.
- Do not treat template files as passing production evidence.
- Do not replace the formal Codex Security scan with dependency audits or existing lightweight security scripts; keep the completed scan artifacts passing on the final head.
- Rerun `npm run review:release-readiness` after each blocker is closed.
