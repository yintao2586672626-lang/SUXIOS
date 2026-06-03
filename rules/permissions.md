# SUXIOS Permission Rules

## Protected Scopes

- OTA collection core: `app/controller/OnlineData.php`, Ctrip/Meituan capture scripts, Profile defaults, and capture catalog verifiers.
- Auth and tenant boundaries: `app/middleware/Auth.php`, user/role/permission controllers, tenant-scoped services, and route middleware.
- Release gates: `scripts/verify_release_*.mjs`, `docs/release_readiness_status.json`, and release evidence files.
- Database schema and migrations: `database/`, `database/migrations/`, and SQL dumps/backups.
- Frontend singleton: `public/index.html` and production entry guards.

## Permission Rules

1. Do not touch protected scopes unless the task explicitly requires it and the affected files are named before editing.
2. Do not use network, account authorization, private repo access, external login, or OTA platform credentials without explicit user confirmation.
3. Do not commit or print Cookie, token, Authorization, signature, phone number, ID number, OTA account data, or raw capture payloads with sensitive data.
4. Do not use fallback logic, empty arrays, default success, broad catch blocks, or zero-filled metrics to hide failed collection or missing fields.
5. Do not label OTA-only data as whole-hotel occupancy, ADR, RevPAR, or operating truth.
6. Do not mark release-ready from functional tests alone; release readiness requires production, security, design, credential, and local/PR state evidence.
7. When a new field or interface is added, check save, display, edit, old-data compatibility, permission filtering, and data-quality status together.

## Evidence Rule

Every claim about implementation, runtime behavior, external state, PR state, collection success, or release readiness needs current evidence from code, command output, docs, tests, or live verified state.
