# Validation Summary

Scan target: `HOTEL` repository-wide.

Commit reviewed: `deb56423e5d6c3a58b904b2de9d6e9cdb47fbeb2`.

## Validation Rubric

- Attacker input must cross a documented trust boundary.
- The path must reach a dangerous sink or broken control.
- Existing authentication, authorization, allowlist, token, escaping, or scoping controls must be absent or bypassable.
- Impact must be security-relevant for hotel data, OTA credentials, AI configuration, operation execution, or release integrity.
- Evidence must be grounded in repository files or verification commands.

## Closure Table

| Ledger row id | Instance key | Root-control file:line | Entrypoint/source | Sink/control | Disposition | Counterevidence or proof gap | Survives |
|---|---|---|---|---|---|---|---|
| R1 | auth:route/app.php | `route/app.php:26`, `route/app.php:116`, `route/app.php:445` | HTTP API routes | `Auth` middleware | not_applicable | Protected groups use middleware; public exceptions handled in R3-R5. | no |
| R2 | auth:app/middleware/Auth.php | `app/middleware/Auth.php:18` | Authorization header | Token cache and user status checks | not_applicable | Token is required and disabled users are rejected. | no |
| R3 | public-api:app/controller/CompetitorApi.php | `app/controller/CompetitorApi.php:29`, `app/controller/CompetitorApi.php:118` | Public competitor endpoints | Env task/report tokens | not_applicable | Missing tokens fail closed; provided tokens use `hash_equals`. | no |
| R4 | cookie-receiver:app/controller/OnlineData.php | `app/controller/OnlineData.php:9324` | Bookmarklet POST | Cached bearer token and CORS origin check | not_applicable | Missing/invalid token and untrusted origin are rejected. | no |
| R5 | cron:app/controller/OnlineData.php | `app/controller/OnlineData.php:20536` | Cron HTTP trigger | `CRON_TOKEN` | not_applicable | Empty production token fails closed with 403; mismatched token returns 401. | no |
| R6 | ssrf:app/controller/OnlineData.php | `app/controller/OnlineData.php:15861` | User supplied OTA URL | HTTPS and OTA host suffix allowlist | not_applicable | Non-HTTPS and non-OTA hosts are rejected before fetch. | no |
| R7 | ssrf:app/service/platform/ApiDataSourceAdapter.php | `app/service/platform/ApiDataSourceAdapter.php:115` | Configured API source URL | HTTPS, private-host rejection, redirects disabled | not_applicable | Local/private/reserved hosts are rejected. | no |
| R8 | command:process-exec | `app/controller/DailyReport.php:2760`, `app/controller/OnlineData.php:5526` | Imported file or capture request | Process launch | not_applicable | Arguments are arrays or shell-escaped; no raw request-to-shell sink found. | no |
| R9 | sql:query-helpers | `scripts/verify_sql_schema_contract.php`, reviewed app query helpers | Request/config values | ThinkORM and metadata SQL | not_applicable | SQL schema contract passed; reviewed raw SQL is fixed metadata or escaped identifiers. | no |
| R10 | file:imports | `app/service/PlatformDataSyncService.php`, `app/service/KnowledgeDocumentTextExtractor.php` | Uploaded/imported files | File and ZipArchive read paths | not_applicable | Reviewed paths are constrained to uploaded/runtime paths and read-only parsing. | no |
| R11 | secrets:release-package | `.gitignore`, `.gitattributes`, `scripts/lib/ota_credential_checks.mjs` | Backups and release artifacts | Git tracking and archive scope | not_applicable | Backup scan passed and `database/backups` has no tracked files. | no |
| R12 | deps:composer-npm | `composer.lock`, `package-lock.json` | Dependencies | Advisory database | not_applicable | Composer and npm audits reported no advisories. | no |

## Result

No candidate finding entered per-finding validation because no candidate survived discovery. The scan remains useful as release coverage evidence, while production env, LLM, design handoff, and OTA credential rotation evidence remain separate blockers.
