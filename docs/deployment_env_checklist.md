# Production Env Release Checklist

Updated: 2026-05-30

## Usage

Production env files must not be committed to Git. Copy `.example.production.env` to a controlled location outside this repository, replace every `CHANGE_ME` value, then run:

```powershell
$env:RELEASE_ENV_FILE='D:\controlled\production.env'
npm.cmd run review:release-env
npm.cmd run review:release-readiness
```

Do not point `RELEASE_ENV_FILE` at `.example.production.env`, a sample/template file, or any env file inside the repository.

`review:release-env` validates only the production env file. `review:release-readiness` uses the same env rules and then continues into LLM, design, backup, security-scan, staged-scope, and external-state release blockers.

`RELEASE_ENV_FILE` is release evidence, not automatic runtime injection. The current single-host deployment stores runtime configuration in `/etc/suxios/suxios.env`; every release's `.env` must link to that file so PHP-FPM, CLI jobs, and timers load the same four `SUXIOS_*` values.

## Required Values

| Key | Production requirement | Notes |
|---|---|---|
| `APP_DEBUG` | `false` | Debug output must be disabled. |
| `APP_TRACE` | `false` | Trace output must be disabled. |
| `SUXIOS_DEPLOYMENT_MODE` | `single_instance` | Multi-instance mode is blocked until shared state and distributed locks are implemented. |
| `SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE` | `true` | Production must fail closed when the external state paths are absent. |
| `SUXIOS_CACHE_PATH` | Absolute path outside the release | Stores login and competitor task cache across release switches. |
| `SUXIOS_LOCAL_LOCK_PATH` | Absolute path outside the release | Stores same-host process locks across release switches. |
| `DB_HOST` | `127.0.0.1` for the current single-host deployment | Loopback is valid only with `SUXIOS_DEPLOYMENT_MODE=single_instance`; never use `0.0.0.0`. |
| `DB_NAME` | Production database name | Must match the release database. |
| `DB_USER` | Least-privilege database user | Do not use `root`. |
| `DB_PASS` | Non-empty strong password | Empty database passwords are blocked. |
| `AI_CONFIG_SECRET` | Non-placeholder secret, at least 32 characters | Must match the secret used for encrypted `ai_model_configs.api_key_encrypted`. |

## OpenAI / LLM Configuration

The production AI path is `LlmClient` with model, base URL, and encrypted API key stored in database `ai_model_configs`. Provider API keys are not read from an env-based `OpenAIClient`.

Before release, confirm:

- `php scripts/verify_single_instance_state_paths.php` passes under the PHP-FPM/CLI service user.
- `php scripts/check_database_version.php` reports the migration catalog current.
- `php scripts/verify_competitor_report_idempotency_schema.php` confirms the durable unique report fingerprint.

- At least one production model config is enabled.
- `base_url` points to the authorized provider endpoint.
- `model_name` is an actual deployed model.
- `api_key_encrypted` can be decrypted with the production `AI_CONFIG_SECRET`.
- A controlled real connectivity smoke test has passed.
- The result is recorded using `docs/llm_connectivity_attestation.example.json` and checked through `LLM_CONNECTIVITY_ATTESTATION_FILE` or `docs/llm_connectivity_attestation.json`.

Run the LLM evidence check independently before full release readiness:

```powershell
$env:LLM_CONNECTIVITY_ATTESTATION_FILE='D:\controlled\llm_connectivity_attestation.json'
npm.cmd run review:release-llm
```

`review:release-llm` validates only the LLM connectivity attestation. `review:release-readiness` uses the same LLM attestation rules and then continues into design, backup, security-scan, staged-scope, and external-state release blockers.

## Not Allowed

- Committing `.env`, production env files, API keys, OTA Cookie/Token values, signatures, or Authorization headers to Git.
- Using local development `.env` as release evidence.
- Using `.example.production.env`, sample, or template files as release evidence.
- Using `root` or empty database passwords in production.
