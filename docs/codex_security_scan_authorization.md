# Formal Codex Security Scan Authorization

Updated: 2026-05-30

## Current State

The project has passed the existing high-risk security script, dependency audit checks, and release-package sensitive-path checks. It has not completed a formal repo-wide Codex Security scan.

## Existing Evidence

| Check | Current evidence |
|---|---|
| High-risk security script | `php scripts/verify_high_risk_security.php` passes in CI. |
| PHP dependency audit | GitHub Actions runs `composer audit --no-interaction`. |
| Node dependency audit | GitHub Actions runs `npm audit --audit-level=moderate`. |
| Release package sensitive paths | `.gitignore` and `.gitattributes` exclude env files, backups, capture reports, and screenshot assets. |
| Backup tracking state | `git ls-files database/backups` has no output in the latest local check. |

## Authorization Still Required

Formal repo-wide Codex Security work requires authorization for subagents. After authorization, the scan must include:

1. Threat model
2. Finding discovery
3. Validation
4. Attack-path analysis
5. Markdown / HTML final report

## Required Coverage

The final scan must explicitly cover at least these surfaces:

- production configuration
- OTA credentials
- AI model configuration
- tenant isolation
- file import
- external HTTP
- report export
- admin permissions
- release packaging

## Required Artifact Bundle

The completed scan directory must contain:

- `scan_manifest.json`
- `report.md`
- `report.html`
- `artifacts/01_context/threat_model.md`
- `artifacts/02_discovery/finding_discovery_report.md`
- `artifacts/03_coverage/repository_coverage_ledger.md`
- `artifacts/03_coverage/reviewed_surfaces.md`
- `artifacts/05_findings/validation_summary.md`
- `artifacts/05_findings/attack_path_analysis_report.md`

Use `docs/codex_security_scan_manifest.example.json` as the manifest shape. The manifest must confirm `scan_mode=repository-wide`, `subagents_authorized=true`, every required phase is `completed`, `final_report_validated=true`, and `report_html_rendered=true`.

## Completion Standard

- Every in-scope file or worklist row has a completed record or an explicit deferred / suppressed / not_applicable reason.
- Every candidate finding has discovery, validation, and attack-path analysis records, or an explicit deferred reason.
- Final `report.md`, `report.html`, `scan_manifest.json`, validation summary, attack-path analysis report, and coverage artifacts exist under `CODEX_SECURITY_SCAN_DIR` or `docs/security/codex-security/latest`.
- `npm run review:release-readiness` no longer reports the formal Codex Security scan failure.

## Not A Substitute

The following checks are useful pre-release evidence, but they do not replace the formal repo-wide scan:

- `verify_high_risk_security.php`
- `npm audit`
- `composer audit`
- grep / rg searches
- manual issue lists
