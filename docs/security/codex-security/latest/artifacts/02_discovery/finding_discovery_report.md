# Finding Discovery Report

Scan target: `HOTEL` repository-wide.

Commit reviewed: `deb56423e5d6c3a58b904b2de9d6e9cdb47fbeb2`.

Date: 2026-06-05.

## Commands And Evidence

| Evidence | Result |
|---|---|
| `C:\xampp\php\php.exe scripts\verify_high_risk_security.php` | Passed. |
| `npm.cmd audit --audit-level=moderate` | Passed, 0 vulnerabilities. |
| `C:\xampp\php\php.exe C:\xampp\php\composer.phar audit --no-interaction` | Passed, no security advisories. |
| `npm.cmd run review:release-ota-credentials` | Backup and package checks passed; attestation still missing. |
| Route and auth review | `route/app.php` protects main business route groups with `app\middleware\Auth`; public exceptions were separately reviewed. |
| Dangerous sink search | `eval`, shell/process execution, outbound HTTP, file I/O, raw SQL, token handling, and archive parsing surfaces were searched and sampled. |

## Discovery Results

No reportable candidate survived discovery.

The scan found security-relevant surfaces, but the reviewed instances had explicit controls:

- `route/app.php` protects hotel, user, role, daily report, OTA data, AI, operation, opening, expansion, transfer, admin, compass, and agent route groups with `Auth` middleware.
- Public `api/competitor/task` and `api/competitor/report` require env-provided task/report tokens, use `hash_equals`, and apply external rate limits.
- Public `api/online-data/receive-cookies` requires a cached Authorization bearer token and rejects untrusted CORS origins before saving cookie material.
- Public `api/online-data/cron-trigger` requires `CRON_TOKEN` through `X-Cron-Token` or `token`.
- OTA custom request paths require `can_fetch_online_data` and restrict targets through `isAllowedOtaRequestUrl`.
- API data-source fetches require HTTPS and reject localhost, loopback, private, and reserved destinations through DNS/IP validation.
- Process execution sites use fixed command arrays or escaped arguments, and high-risk checks cover the key OTA and report process paths.
- Dependency and high-risk security checks did not report active vulnerable dependencies or high-risk regression failures.

## Candidate Ledger

No candidate directories were created because no technically plausible reportable finding survived discovery. Coverage rows are preserved in `artifacts/03_coverage/repository_coverage_ledger.md` and `artifacts/03_coverage/reviewed_surfaces.md`.
