# Release Blocker Close Plan

Updated: 2026-06-05

Scope: `@github`, `@openai-developers`, `@codex-security`, `@figma`, `@canva`

Source: `docs/release_readiness_status.json` `blockers`

Current status: `production-env-missing`, `llm-connectivity-attestation-missing`, and `codex-security-scan-missing` are closed by external/repo evidence and their isolated review commands. `design-handoff-missing`, `ota-credential-rotation-attestation-missing`, and `local-git-state-open` remain open. The GitHub handoff blocker must stay open until real design and OTA evidence pass release-readiness.

Command matrix: `docs/release_verification_command_matrix.md`

## Close Order

| Order | Blocker id | Scope | Close action | Acceptance evidence |
|---:|---|---|---|---|
| 1 | `production-env-missing` | `@openai-developers` | Closed on 2026-06-05 by external `RELEASE_ENV_FILE`; keep it outside the repository and rerun the verifier on the final head. | `RELEASE_ENV_FILE` points to `../release-evidence-temp/production.env`; `npm run review:release-env` passes and `npm run review:release-readiness` no longer reports missing production env when the env var is set. |
| 2 | `llm-connectivity-attestation-missing` | `@openai-developers` | Closed on 2026-06-05 by external redacted attestation; keep it outside the repository and rerun the verifier on the final head. | `LLM_CONNECTIVITY_ATTESTATION_FILE` points to `../release-evidence-temp/llm-attestation.json`; `npm run review:release-llm` passes and `npm run review:release-readiness` no longer reports missing LLM attestation when the env var is set. |
| 3 | `codex-security-scan-missing` | `@codex-security` | Closed on 2026-06-05; keep the formal repo-wide Codex Security scan artifacts available and rerun the verifier on the final head. | `CODEX_SECURITY_SCAN_DIR` or `docs/security/codex-security/latest` passes `npm run review:release-security-scan` and contains `scan_manifest.json`, `report.md`, `report.html`, validation summary, attack-path analysis report, and coverage artifacts. |
| 4 | `design-handoff-missing` | `@figma` / `@canva` | Provide real Figma, Canva, Brand Kit, design token, covered-flow handoff references, review date, and zero open design issues in `docs/design_handoff_manifest.json`. | `docs/design_handoff_manifest.json` passes `npm run review:release-design` and `npm run review:release-readiness`; standalone token files, screenshots, or manifests with non-empty `open_issues` do not close the blocker. |
| 5 | `ota-credential-rotation-attestation-missing` | `@codex-security` | Rotate or invalidate OTA Cookie, Token, signature, and Authorization material, then record cleanup results. | `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` or `docs/ota_credential_rotation_attestation.json` passes `npm run review:release-ota-credentials`, contains no real credential values, and confirms `redaction_checked=true`. |
| 6 | `local-git-state-open` | `@github` | Only after release-readiness passes, mark PR #2 ready for review, confirm `.git/index.lock` is absent, and recheck final-head PR checks. | `npm run review:release-external-state` passes, or `RELEASE_EXTERNAL_STATE_FILE` proves the same checks passed. |

## Close Rules

- Rerun `npm run review:release-readiness` after closing each blocker.
- For production env review, run `npm run review:release-env` first; it uses the same env rules as `npm run review:release-readiness` without requiring the unrelated release artifacts to be present.
- For production LLM review, run `npm run review:release-llm` first; it uses the same attestation rules as `npm run review:release-readiness` without requiring the unrelated release artifacts to be present.
- For Figma / Canva handoff review, run `npm run review:release-design` first; it uses the same manifest rules as `npm run review:release-readiness` without requiring the unrelated release artifacts to be present.
- For OTA credential and backup cleanup review, run `npm run review:release-ota-credentials` first; it uses the same backup scan and rotation-attestation rules as `npm run review:release-readiness` without requiring the unrelated release artifacts to be present.
- For Codex Security scan review, run `npm run review:release-security-scan` first; it uses the same scan artifact rules as `npm run review:release-readiness` without requiring the unrelated release artifacts to be present.
- When preserving evidence for review, run with `RELEASE_READINESS_RESULT_FILE=<controlled-path>` and archive the generated JSON result outside secret-bearing locations.
- For GitHub/local-state review, run `npm run review:release-external-state` with `RELEASE_EXTERNAL_STATE_RESULT_FILE=<controlled-path>`; if Node child_process is blocked, run `npm run collect:release-external-state` (`scripts/collect_release_external_state.ps1`), then rerun with `RELEASE_EXTERNAL_STATE_FILE=docs/release_external_state_evidence.local.json`. On Windows, `npm run review:release-external-state:local` performs both steps.
- Do not store real keys, Cookie values, Token values, signatures, Authorization headers, or unredacted sensitive fields in attestation files.
- Keep each blocker in `open` status in `docs/release_readiness_status.json` until its acceptance command or evidence has passed; once closed, keep the command evidence and final-head rerun requirement.
- Figma / Canva handoff cannot be screenshots only; it must include accessible source links, Brand Kit, and design token location.
- Formal Codex Security scan cannot be replaced by `verify_high_risk_security.php`, `composer audit`, or `npm audit`.
