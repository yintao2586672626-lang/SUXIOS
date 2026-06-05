# SUXIOS HOTEL Repository Threat Model

Generated for repository-wide release security scan on 2026-06-05.

## Product Scope

SUXIOS HOTEL is a ThinkPHP 8 and Vue CDN single-page application for hotel operations. The core business chain is OTA channel data from Ctrip and Meituan -> revenue analysis -> AI decisions -> operations management -> investment decisions.

The primary repository root is `HOTEL/`. The reviewed runtime surfaces are backend controllers under `app/controller`, route definitions under `route/app.php`, middleware under `app/middleware`, service-layer helpers under `app/service`, local automation scripts under `scripts`, production hygiene configuration, and release evidence checks.

## Assets

- User accounts, session tokens, roles, hotel bindings, and operation logs.
- OTA credentials and browser profile material, including Cookie, token, signature, Authorization, usertoken, and usersign values.
- Hotel-scoped OTA rows, revenue metrics, strategy records, operating actions, and investment decision records.
- AI model configuration and encrypted provider secrets in `ai_model_configs`.
- Release artifacts, production environment settings, and GitHub PR state.

## Trust Boundaries

- Browser or external client -> ThinkPHP API routes.
- Authenticated user -> hotel-scoped business data and privileged operations.
- Bookmarklet and cron callers -> unauthenticated routes that must enforce independent token controls.
- External OTA domains -> manually supplied or configured HTTP endpoints.
- Local browser automation and Python scripts -> process execution with controlled arguments.
- Production LLM provider -> outbound model calls through `LlmClient`.
- Git/release pipeline -> CI, release evidence, and PR state.

## Attacker-Controlled Inputs

- HTTP request parameters, JSON bodies, headers, uploaded/imported files, and base64 image payloads.
- Public route parameters on login, registration, health, cookie receiver, cron trigger, and competitor collector APIs.
- OTA request URLs, headers, cookies, payloads, and platform responses.
- AI prompts, report fields, document text, and imported data values.
- Configured API source URLs and optional headers, where user roles permit configuration changes.

## Required Invariants

- Protected business routes must require `app\middleware\Auth`.
- Public exceptions must have explicit independent controls such as login policy, env tokens, CORS checks, or health-only behavior.
- OTA-only metrics must remain scoped as OTA channel evidence, not whole-hotel operating truth.
- Cross-hotel access must be constrained by system hotel IDs and permitted hotel IDs.
- External HTTP fetches must be restricted by HTTPS and host allowlists or private-host rejection.
- Process execution must use fixed binaries, argument arrays, escaping, and timeouts.
- Release checks must not use templates, placeholders, or fallback success to hide missing evidence.
- Secrets, cookies, tokens, signatures, and Authorization values must not be committed or emitted into release reports.

## High-Impact Failure Modes

- Missing authentication or authorization on hotel data, role management, AI governance, OTA capture, operation execution, or investment-decision APIs.
- Cross-hotel data exposure from confusing OTA platform hotel IDs with internal system hotel IDs.
- SSRF from custom OTA or API-source URLs reaching internal services.
- Command injection from browser capture, Python parsing, or local automation process launch paths.
- SQL injection from raw query helpers or string-built metadata queries.
- Credential exposure in backups, reports, logs, capture artifacts, or release evidence.
- Unsafe file import or archive parsing that reads or writes outside intended temporary directories.
- Production release with debug mode, missing final-head LLM evidence, missing security scan, or unrotated OTA credentials.
