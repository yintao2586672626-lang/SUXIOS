# Repository Coverage Ledger

Scan target: `HOTEL` repository-wide.

Commit reviewed: `deb56423e5d6c3a58b904b2de9d6e9cdb47fbeb2`.

| Row ID | Surface | Risk Area | Evidence | Disposition | Notes |
|---|---|---|---|---|---|
| R1 | `route/app.php` protected route groups | Missing authentication | Route groups for hotel, user, role, reports, OTA data, AI, operations, opening, expansion, transfer, admin, compass, operation logs, and agent use `->middleware(\app\middleware\Auth::class)`. | no_issue_found | Public exceptions reviewed separately. |
| R2 | `app/middleware/Auth.php` | Token authentication and rate limiting | Bearer/direct token extraction, cache lookup, enabled-user check, request user injection, rate limit policy, and audit redaction reviewed. | no_issue_found | Token storage remains cache-based; production cache hardening is deployment evidence, not code finding. |
| R3 | `app/controller/CompetitorApi.php` | Public collector API abuse | Task and report endpoints require env tokens, use `hash_equals`, and enforce per-identity rate limits. | no_issue_found | If production tokens are absent, endpoints fail closed with 403. |
| R4 | `app/controller/OnlineData.php::receiveCookies` | Public cookie receiver credential injection | Requires cached Authorization token, user lookup, CORS origin check, and user hotel binding for non-super admins. | no_issue_found | OTA credential rotation attestation is still a release blocker. |
| R5 | `app/controller/OnlineData.php::cronTrigger` | Unauthenticated scheduled action | Requires non-empty `CRON_TOKEN` and exact request token match. | no_issue_found | Production env must provide the token outside the repository. |
| R6 | `OnlineData` OTA custom fetch methods | SSRF | `fetchCustom` requires `can_fetch_online_data`; `isAllowedOtaRequestUrl` requires HTTPS and allowed OTA host suffixes. | no_issue_found | This intentionally permits Ctrip/Meituan official domains only. |
| R7 | `app/service/platform/ApiDataSourceAdapter.php` | Configured API SSRF | Requires HTTPS, rejects empty/private/loopback/reserved hosts, disables redirect following, and supports explicit `allowed_hosts`. | no_issue_found | Operator-controlled public HTTPS destinations can still be configured by authorized users. |
| R8 | `DailyReport`, `OnlineData`, platform adapters, knowledge distillation | Command injection | Process execution uses argument arrays, fixed binaries, `escapeshellarg`, integer PID casting, or controlled launcher files. | no_issue_found | Existing high-risk security verifier passed. |
| R9 | Raw SQL and query helpers | SQL injection | Dynamic metadata queries use fixed table/column lists or escaping; business queries use ThinkORM and bound/raw-safe patterns in reviewed paths. | no_issue_found | `verify_sql_schema_contract.php` passed separately. |
| R10 | File import, XLSX/DOCX, screenshot/base64 handling | Path traversal or unsafe archive parsing | Reviewed file access is constrained to uploaded temp files, runtime paths, generated output paths, or ZipArchive read-only parsing. | no_issue_found | No arbitrary path read/write candidate survived discovery. |
| R11 | OTA credential release package | Secret exposure | `.gitignore`, `.gitattributes`, backup text scan, and `git ls-files database/backups` evidence show backups are excluded and untracked. | no_issue_found | Real platform credential rotation attestation remains open. |
| R12 | Dependencies | Known vulnerable dependencies | `npm audit --audit-level=moderate` and `composer audit --no-interaction` passed. | no_issue_found | Must be rerun on the final release head. |
| R13 | Production release evidence | Release readiness | `review:release-readiness` now passes env, LLM attestation, and security scan with external/repo evidence; design handoff and OTA rotation attestation remain open. | needs_follow_up | This scan closes only the security-scan artifact blocker; env and LLM are closed by separate external evidence. |
