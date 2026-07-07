# Release Evidence Bundle Intake

Updated: 2026-06-05

Purpose: provide one local preflight command for controlled production-release evidence without generating, copying, or substituting evidence.

## Command

```powershell
npm.cmd run review:release-evidence
```

Default evidence directory:

```text
../release-evidence-temp
```

Override when needed:

```powershell
$env:RELEASE_EVIDENCE_DIR='D:\secure-release-evidence\suxios'
npm.cmd run review:release-evidence
```

Result file:

```powershell
# Default: ../release-evidence-temp/release-evidence-result.json
$env:RELEASE_EVIDENCE_RESULT_FILE='..\release-evidence-temp\release-evidence-result.json'
npm.cmd run review:release-evidence
```

## Expected Inputs

| Evidence | Default lookup | Override | Gate covered |
|---|---|---|---|
| Production env | `../release-evidence-temp/production.env` | `RELEASE_ENV_FILE` | `review:release-env` |
| LLM attestation | `../release-evidence-temp/llm-attestation.json`, then `docs/llm_connectivity_attestation.json` | `LLM_CONNECTIVITY_ATTESTATION_FILE` | `review:release-llm` |
| Design handoff | `../release-evidence-temp/design_handoff_manifest.json`, then `docs/design_handoff_manifest.json` | `DESIGN_HANDOFF_MANIFEST_FILE` | `review:release-design` |
| OTA credential rotation | `../release-evidence-temp/ota_credential_rotation_attestation.json`, then `docs/ota_credential_rotation_attestation.json` | `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` | `review:release-ota-credentials` |
| Codex Security scan | `../release-evidence-temp/codex-security/latest`, then `docs/security/codex-security/latest` | `CODEX_SECURITY_SCAN_DIR` | `review:release-security-scan` |

## Rules

- This command is read-only. It does not create production evidence and does not copy external evidence into the repository.
- By default it writes `release-evidence-result.json` under `RELEASE_EVIDENCE_DIR` or `../release-evidence-temp`; `RELEASE_EVIDENCE_RESULT_FILE` must also point outside the repository.
- Prefer the controlled external manifest at `../release-evidence-temp/design_handoff_manifest.json`; `docs/design_handoff_manifest.json` remains the local default fallback.
- Design `last_reviewed_at` and OTA credential `reviewed_at` must be real `YYYY-MM-DD` dates inside the 30-day release evidence window.
- Evidence files must not contain real keys, Cookie values, Token values, signatures, Authorization headers, or reusable login state.
- Passing `review:release-evidence` is not enough for release. Final handoff still requires `review:release-staged-scope` and `review:release-external-state` to pass from a checkout whose local HEAD matches the final PR head, then `review:release-readiness` must consume those passing results from `RELEASE_STAGED_SCOPE_RESULT_FILE` and `RELEASE_EXTERNAL_STATE_RESULT_FILE` or the controlled `RELEASE_EVIDENCE_DIR` / `../release-evidence-temp` result files outside the repository.
- Missing evidence must remain visible as a failure; do not close blockers with templates, screenshots, or placeholder values.
