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

Optional result file:

```powershell
$env:RELEASE_EVIDENCE_RESULT_FILE='..\release-evidence-temp\release-evidence-result.json'
npm.cmd run review:release-evidence
```

## Expected Inputs

| Evidence | Default lookup | Override | Gate covered |
|---|---|---|---|
| Production env | `../release-evidence-temp/production.env` | `RELEASE_ENV_FILE` | `review:release-env` |
| LLM attestation | `../release-evidence-temp/llm-attestation.json`, then `docs/llm_connectivity_attestation.json` | `LLM_CONNECTIVITY_ATTESTATION_FILE` | `review:release-llm` |
| Design handoff | `docs/design_handoff_manifest.json` | None | `review:release-design` |
| OTA credential rotation | `../release-evidence-temp/ota_credential_rotation_attestation.json`, then `docs/ota_credential_rotation_attestation.json` | `OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE` | `review:release-ota-credentials` |
| Codex Security scan | `../release-evidence-temp/codex-security/latest`, then `docs/security/codex-security/latest` | `CODEX_SECURITY_SCAN_DIR` | `review:release-security-scan` |

## Rules

- This command is read-only. It does not create production evidence and does not copy external evidence into the repository.
- `docs/design_handoff_manifest.json` remains the required design handoff path for release readiness.
- Evidence files must not contain real keys, Cookie values, Token values, signatures, Authorization headers, or reusable login state.
- Passing `review:release-evidence` is not enough for release. Final handoff still requires `review:release-readiness` and `review:release-external-state` to pass on the final PR head.
- Missing evidence must remain visible as a failure; do not close blockers with templates, screenshots, or placeholder values.
