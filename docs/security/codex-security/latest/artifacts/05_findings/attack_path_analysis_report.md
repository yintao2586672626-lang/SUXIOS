# Attack Path Analysis Report

Scan target: `HOTEL` repository-wide.

Commit reviewed: `deb56423e5d6c3a58b904b2de9d6e9cdb47fbeb2`.

## Reportability Decision

No reportable security finding survived validation, so no final attack path is emitted.

## Counterevidence Summary

| Risk Family | Strongest counterevidence | Final decision |
|---|---|---|
| Missing authentication | Main business route groups use `app\middleware\Auth`; public exceptions have independent controls. | Suppressed as no issue found. |
| Public collector abuse | Competitor endpoints require configured env tokens and rate limit callers. | Suppressed as no issue found. |
| Cookie receiver abuse | `receiveCookies` requires a cached bearer token and rejects untrusted origins. | Suppressed as no issue found. |
| Cron abuse | `cronTrigger` requires a non-empty configured `CRON_TOKEN`. | Suppressed as no issue found. |
| SSRF | OTA fetch paths require HTTPS and OTA host allowlists; API source adapter rejects private/reserved hosts and redirects. | Suppressed as no issue found. |
| Command injection | Process execution paths use argument arrays, escaped arguments, controlled launcher files, or integer-only PID commands. | Suppressed as no issue found. |
| SQL injection | Reviewed raw SQL is metadata or escaped identifier work; app data queries use ThinkORM and existing schema contract passed. | Suppressed as no issue found. |
| Secret exposure | Release package excludes sensitive paths and backup text scan is clean. | Not reportable; OTA rotation attestation remains a release blocker. |

## Severity Calibration

Severity mix is `none` because no exploitable source-to-sink path survived. The remaining release blockers are operational evidence gaps, not code findings from this scan:

- real Figma/Canva/design-token handoff missing
- OTA credential rotation attestation missing

These must still be closed before formal production release.
