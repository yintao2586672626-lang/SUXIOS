# Security Review: HOTEL

## Scope

- Scan mode: repository-wide release security scan.
- Repository root: `D:\桌面\SUXIOS\宿析OS初始版\HOTEL`.
- Commit reviewed: `deb56423e5d6c3a58b904b2de9d6e9cdb47fbeb2`.
- Date: 2026-06-05.
- In-scope code: `app/`, `route/`, `config/`, `scripts/`, `public/index.html`, release verification scripts, and release evidence contracts.
- Explicit exclusions: `node_modules/`, `vendor/`, `storage/`, `runtime/`, `output/`, raw capture reports, browser profiles, and backup SQL content beyond release package/tracking checks.
- Runtime status used as evidence: local guards, PHPUnit, route coverage, SQL schema contract, dependency audits, and release security checks.
- Threat model source: generated during this scan and saved at `artifacts/01_context/threat_model.md`.

### Scan Summary

| Field | Value |
|---|---|
| Reportable findings | 0 |
| Severity mix | none |
| Confidence mix | high confidence for reviewed no-finding surfaces; production evidence gaps remain external blockers |
| Coverage | Route/auth, public exceptions, OTA outbound fetch, API-source SSRF, process execution, SQL/raw query helpers, file/archive import, secret/package controls, dependencies |
| Validation mode | Static trace plus existing project verifiers and dependency audit commands |
| Final HTML | `docs/security/codex-security/latest/report.html` |

## Threat Model

### Product Scope

SUXIOS HOTEL is a ThinkPHP 8 and Vue CDN single-page application for hotel operations. The core business chain is OTA channel data from Ctrip and Meituan -> revenue analysis -> AI decisions -> operations management -> investment decisions.

The primary repository root is `HOTEL/`. The reviewed runtime surfaces are backend controllers under `app/controller`, route definitions under `route/app.php`, middleware under `app/middleware`, service-layer helpers under `app/service`, local automation scripts under `scripts`, production hygiene configuration, and release evidence checks.

### Assets

- User accounts, session tokens, roles, hotel bindings, and operation logs.
- OTA credentials and browser profile material, including Cookie, token, signature, Authorization, usertoken, and usersign values.
- Hotel-scoped OTA rows, revenue metrics, strategy records, operating actions, and investment decision records.
- AI model configuration and encrypted provider secrets in `ai_model_configs`.
- Release artifacts, production environment settings, and GitHub PR state.

### Trust Boundaries

- Browser or external client -> ThinkPHP API routes.
- Authenticated user -> hotel-scoped business data and privileged operations.
- Bookmarklet and cron callers -> unauthenticated routes that must enforce independent token controls.
- External OTA domains -> manually supplied or configured HTTP endpoints.
- Local browser automation and Python scripts -> process execution with controlled arguments.
- Production LLM provider -> outbound model calls through `LlmClient`.
- Git/release pipeline -> CI, release evidence, and PR state.

### Attacker-Controlled Inputs

- HTTP request parameters, JSON bodies, headers, uploaded/imported files, and base64 image payloads.
- Public route parameters on login, registration, health, cookie receiver, cron trigger, and competitor collector APIs.
- OTA request URLs, headers, cookies, payloads, and platform responses.
- AI prompts, report fields, document text, and imported data values.
- Configured API source URLs and optional headers, where user roles permit configuration changes.

### Required Invariants

- Protected business routes must require `app\middleware\Auth`.
- Public exceptions must have explicit independent controls such as login policy, env tokens, CORS checks, or health-only behavior.
- OTA-only metrics must remain scoped as OTA channel evidence, not whole-hotel operating truth.
- Cross-hotel access must be constrained by system hotel IDs and permitted hotel IDs.
- External HTTP fetches must be restricted by HTTPS and host allowlists or private-host rejection.
- Process execution must use fixed binaries, argument arrays, escaping, and timeouts.
- Release checks must not use templates, placeholders, or fallback success to hide missing evidence.
- Secrets, cookies, tokens, signatures, and Authorization values must not be committed or emitted into release reports.

## Findings

### No findings

No reportable security finding survived discovery, validation, and attack-path analysis.

The scan reviewed high-impact surfaces for authentication, authorization, SSRF, command execution, SQL injection, file/archive handling, secret exposure, and dependency advisories. Existing controls and project verifiers provided counterevidence for each reviewed candidate family.

### Confidence Scale

| Label | Meaning |
|---|---|
| high | Direct source, configuration, or command evidence supports the conclusion, with no material unresolved reachability or exploitability blocker for the reviewed surface. |
| medium | Source evidence supports the conclusion, but runtime deployment configuration still needs production proof. |
| low | Weak or incomplete evidence; not used for final no-finding closure. |

## Reviewed Surfaces

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
| Production deployment evidence | Release readiness | Needs follow-up | Production env, LLM connectivity, and design handoff evidence remain missing. |

## Open Questions And Follow Up

- Provide the real production env outside the repository and rerun `npm.cmd run review:release-env`.
- Run a production `LlmClient` smoke test with enabled `ai_model_configs`, provide a redacted attestation, and rerun `npm.cmd run review:release-llm`.
- Provide real Figma, Canva, Brand Kit, design-token, and covered-flow handoff evidence, then rerun `npm.cmd run review:release-design`.
- Rotate or invalidate OTA credential material and provide a redacted attestation, then rerun `npm.cmd run review:release-ota-credentials`.
- Rerun `npm.cmd run review:release-readiness` and `$env:RELEASE_PR_NUMBER='2'; npm.cmd run review:release-external-state` after every release evidence update.
