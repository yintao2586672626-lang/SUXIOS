# Reviewed Surfaces

| Surface | Risk Area | Outcome | Notes |
|---|---|---|---|
| Route registry and middleware | Authentication and authorization | No issue found | Main business routes are protected by `Auth`; public exceptions have separate controls. |
| Auth middleware | Token handling, audit logging, rate limit | No issue found | Sensitive audit params are redacted and disabled users are rejected. |
| Competitor public API | Public collector abuse | No issue found | Env tokens plus `hash_equals` and rate limits are present. |
| Cookie receiver and bookmarklet flow | OTA credential injection | No issue found | Cached Authorization token and CORS origin checks are present. |
| Cron trigger | Scheduled action abuse | No issue found | Requires `CRON_TOKEN`; missing token fails closed. |
| OTA custom fetch and manual request flow | SSRF | No issue found | Requires HTTPS and Ctrip/Meituan host suffix allowlist. |
| API data-source adapter | Configured outbound HTTP | No issue found | Rejects private/reserved hosts, disables redirects, and supports explicit allowed hosts. |
| Process execution paths | Command injection | No issue found | Uses argument arrays, escaping, controlled files, and PID casting. |
| SQL helpers and metadata queries | SQL injection | No issue found | No attacker-controlled raw SQL sink survived discovery. |
| File import and archive parsing | Path traversal or unsafe file handling | No issue found | Reviewed paths are runtime/temp/read-only parsing flows. |
| Dependency manifests | Known CVE/advisory exposure | No issue found | Composer and npm audit checks passed on this head. |
| Release package and backup handling | Secret exposure | Needs follow-up | Backup text scan is clean, but real OTA credential rotation attestation is still missing. |
| Production deployment evidence | Release readiness | Needs follow-up | Production env and LLM connectivity now pass through external evidence; design handoff evidence remains missing. |
