# Production Env Release Checklist

Updated: 2026-05-30

## Usage

Production env files must not be committed to Git. Copy `.example.production.env` to a controlled location outside this repository, replace every `CHANGE_ME` value, then run:

```powershell
$env:RELEASE_ENV_FILE='D:\controlled\production.env'
npm.cmd run review:release-readiness
```

Do not point `RELEASE_ENV_FILE` at `.example.production.env`, a sample/template file, or any env file inside the repository.

## Required Values

| Key | Production requirement | Notes |
|---|---|---|
| `APP_DEBUG` | `false` | Debug output must be disabled. |
| `DB_HOST` | Production database host | Do not use a local development database. |
| `DB_NAME` | Production database name | Must match the release database. |
| `DB_USER` | Least-privilege database user | Do not use `root`. |
| `DB_PASS` | Non-empty strong password | Empty database passwords are blocked. |
| `AI_CONFIG_SECRET` | Non-placeholder secret, at least 32 characters | Must match the secret used for encrypted `ai_model_configs.api_key_encrypted`. |

## OpenAI / LLM Configuration

The production AI path is `LlmClient` with model, base URL, and encrypted API key stored in database `ai_model_configs`. Provider API keys are not read from an env-based `OpenAIClient`.

Before release, confirm:

- At least one production model config is enabled.
- `base_url` points to the authorized provider endpoint.
- `model_name` is an actual deployed model.
- `api_key_encrypted` can be decrypted with the production `AI_CONFIG_SECRET`.
- A controlled real connectivity smoke test has passed.
- The result is recorded using `docs/llm_connectivity_attestation.example.json` and checked through `LLM_CONNECTIVITY_ATTESTATION_FILE` or `docs/llm_connectivity_attestation.json`.

## Not Allowed

- Committing `.env`, production env files, API keys, OTA Cookie/Token values, signatures, or Authorization headers to Git.
- Using local development `.env` as release evidence.
- Using `.example.production.env`, sample, or template files as release evidence.
- Using `root` or empty database passwords in production.
