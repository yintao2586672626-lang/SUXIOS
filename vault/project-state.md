# SUXIOS Project State Vault

Updated: 2026-06-03 Asia/Shanghai

## Current Verified State

- Main repo root: `HOTEL/`.
- Current branch observed this run: `codex/save-project-20260531`.
- Worktree observed on 2026-06-05 Asia/Shanghai is dirty: `.agents/plugins/marketplace.json`, `.gitignore`, `AGENTS.md`, `app/controller/OnlineData.php`, `app/middleware/Auth.php`, `app/view/admin/compass/index.html`, `database/init_full.sql`, `package.json`, `public/index.html`, `scripts/lib/visible_page_evidence.mjs`, `scripts/verify_report_security_finance_regressions.php`, `scripts/verify_sql_schema_contract.php`, `tests/automation/ctrip_store_data_overview.test.mjs`, `tests/automation/visible_page_evidence.test.mjs`, `tests/fixtures/visible-page-evidence/ctrip-visible-evidence.html`, and `vault/project-state.md` have tracked changes; `.agents/skills/ecc-codex-adapter/`, and `scripts/verify_ecc_codex_adapter.mjs` are untracked.
- Main product chain: OTA data from Ctrip/Meituan -> revenue analysis -> AI decisions -> operations management -> investment decisions.
- Current priority skill focus: Ctrip response -> field -> table closure.
- ECC source is downloaded locally at `.agents/vendor/everything-claude-code/` from commit `bc8e12bb80c904a5a9864797ef1fd1212aa82f3d`; Codex must use it through `.agents/skills/ecc-codex-adapter/SKILL.md` unless the user explicitly asks for a direct plugin install.

## Gate State

- Functional acceptance and release readiness are separate states.
- Existing reports show functional acceptance can pass while release readiness remains blocked by production env, LLM attestation, design handoff, OTA credential cleanup/attestation, formal security scan, and local/PR state.
- Do not claim release readiness until the release gates pass with real evidence files, not placeholders.

## Ctrip Field Closure State

- Recent Ctrip work moved beyond pure login blocking into captured endpoint evidence, response-only endpoints, gap lists, field selection, and table planning.
- Remaining Ctrip gaps must stay explicit; do not replace missing values with `0` or fallback success.
- Every field must keep Ctrip OTA channel scope unless whole-hotel source evidence exists.

## Maintenance Rule

Update this vault after important context changes, save-project runs, new release evidence, or completed field/table closure work. Record only verified facts and avoid secrets, raw cookies, raw tokens, account data, phone numbers, screenshots with sensitive OTA data, or large raw capture JSON.
