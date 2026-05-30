# Release Blocker Close Plan

Updated: 2026-05-30

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

Source: `docs/release_readiness_status.json` `blockers`

## Close Order

| Order | Blocker id | Scope | Close action | Acceptance evidence |
|---:|---|---|---|---|
| 1 | `local-git-state-open` | `@github` | Align local worktree with the PR branch, confirm `.git/index.lock` is absent, and recheck PR checks. | `npm run review:release-external-state` passes, or `RELEASE_EXTERNAL_STATE_FILE` proves the same checks passed. |
| 2 | `production-env-missing` | `@openai-developers` | Prepare controlled production env outside the repository. | `RELEASE_ENV_FILE` points to a real non-template production config, `APP_DEBUG=false`, `APP_TRACE=false`, `DB_HOST` is not localhost or loopback, `DB_USER` is not `root`, `npm run review:release-env` passes, and `npm run review:release-readiness` no longer reports missing production env. |
| 3 | `llm-connectivity-attestation-missing` | `@openai-developers` | Test production `ai_model_configs` through the real `LlmClient` path. | `LLM_CONNECTIVITY_ATTESTATION_FILE` or `docs/llm_connectivity_attestation.json` passes `npm run review:release-llm`, contains no secret values, and confirms `redaction_checked=true`. |
| 4 | `backup-credential-shaped-fields` | `@codex-security` | Delete, sanitize, or encrypted-archive credential-shaped data under `database/backups`. | `npm run review:release-readiness` no longer reports credential-shaped matches across backup text files. |
| 5 | `ota-credential-rotation-attestation-missing` | `@codex-security` | Rotate or invalidate OTA Cookie, Token, signature, and Authorization material, then record cleanup results. | `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` or `docs/ota_credential_rotation_attestation.json` passes review, contains no real credential values, and confirms `redaction_checked=true`. |
| 6 | `codex-security-scan-missing` | `@codex-security` | Authorize subagents and complete the formal repo-wide Codex Security scan. | `CODEX_SECURITY_SCAN_DIR` or `docs/security/codex-security/latest` contains `scan_manifest.json`, `report.md`, `report.html`, validation summary, attack-path analysis report, and coverage artifacts. |
| 7 | `design-handoff-missing` | `@figma` / `@canva` | Provide real Figma, Canva, Brand Kit, design token, covered-flow handoff references, review date, and zero open design issues in `docs/design_handoff_manifest.json`. | `docs/design_handoff_manifest.json` passes `npm run review:release-readiness`; standalone token files, screenshots, or manifests with non-empty `open_issues` do not close the blocker. |

## Close Rules

- Rerun `npm run review:release-readiness` after closing each blocker.
- For production env review, run `npm run review:release-env` first; it uses the same env rules as `npm run review:release-readiness` without requiring the unrelated release artifacts to be present.
- For production LLM review, run `npm run review:release-llm` first; it uses the same attestation rules as `npm run review:release-readiness` without requiring the unrelated release artifacts to be present.
- When preserving evidence for review, run with `RELEASE_READINESS_RESULT_FILE=<controlled-path>` and archive the generated JSON result outside secret-bearing locations.
- For GitHub/local-state review, run `npm run review:release-external-state` with `RELEASE_EXTERNAL_STATE_RESULT_FILE=<controlled-path>`; if Node child_process is blocked, run `npm run collect:release-external-state` (`scripts/collect_release_external_state.ps1`), then rerun with `RELEASE_EXTERNAL_STATE_FILE=docs/release_external_state_evidence.local.json`. On Windows, `npm run review:release-external-state:local` performs both steps.
- Do not store real keys, Cookie values, Token values, signatures, Authorization headers, or unredacted sensitive fields in attestation files.
- Keep each blocker in `open` status in `docs/release_readiness_status.json` until its acceptance command or evidence has passed.
- Figma / Canva handoff cannot be screenshots only; it must include accessible source links, Brand Kit, and design token location.
- Formal Codex Security scan cannot be replaced by `verify_high_risk_security.php`, `composer audit`, or `npm audit`.
