# SUXIOS Permission Rules

## Protected Scopes

- OTA collection core: `app/controller/OnlineData.php`, Ctrip/Meituan capture scripts, Profile defaults, and capture catalog verifiers.
- Auth and tenant boundaries: `app/middleware/Auth.php`, user/role/permission controllers, tenant-scoped services, and route middleware.
- Release gates: `scripts/verify_release_*.mjs`, `docs/release_readiness_status.json`, and release evidence files.
- Database schema and migrations: `database/`, `database/migrations/`, and SQL dumps/backups.
- Frontend singleton: `public/index.html` and production entry guards.

## Permission Rules

1. Do not touch protected scopes unless the task explicitly requires it and the affected files are named before editing.
2. A clear business objective plus “continue”, “execute”, or equivalent authorizes normal in-scope local code changes and focused verification without repeated approval.
3. Commit, push, PR, deployment, production writes, formal external delivery, and real OTA operations require the current request to explicitly include that external action and target. Once explicitly included, do not request the same approval at every step.
4. If login expires or a password, CAPTCHA, Passkey, SMS code, or two-factor authentication is required, pause for the user to complete it on the original device. Never request or transfer the password, Cookie, verification code, or recovery code.
5. Do not commit or print Cookie, token, Authorization, signature, phone number, ID number, OTA account data, or raw capture payloads with sensitive data.
6. Do not use fallback logic, empty arrays, default success, broad catch blocks, or zero-filled metrics to hide failed collection or missing fields.
7. Do not label OTA-only data as whole-hotel occupancy, ADR, RevPAR, or operating truth.
8. Do not mark release-ready from functional tests alone; release readiness requires production, security, design, credential, and local/PR state evidence.
9. When a new field or interface is added, check save, display, edit, old-data compatibility, permission filtering, and data-quality status together.

## Evidence Rule

Every claim about implementation, runtime behavior, external state, PR state, collection success, or release readiness needs current evidence from code, command output, docs, tests, or live verified state.
