# SUXIOS Project State Vault

Updated: 2026-06-06 Asia/Shanghai

## Current Verified State

- Main repo root: `HOTEL/`.
- Current branch observed this run: `codex/save-project-20260531`.
- Workspace closing on 2026-06-06 Asia/Shanghai is grouped into OTA capture, data health, OTA revenue smoke verification, and reusable context assets.
- Main product chain: OTA data from Ctrip/Meituan -> revenue analysis -> AI decisions -> operations management -> investment decisions.
- Current priority skill focus: Ctrip response -> field -> table closure.
- ECC source is downloaded locally at `.agents/vendor/everything-claude-code/` from commit `bc8e12bb80c904a5a9864797ef1fd1212aa82f3d`; Codex must use it through `.agents/skills/ecc-codex-adapter/SKILL.md` unless the user explicitly asks for a direct plugin install.
- Data Analytics reusable context is now registered for SUXIOS OTA revenue analytics and decision loop. Project-local semantic layer: `.agents/skills/suxi-ota-revenue-semantic-layer/`; report: `docs/data_analytics_suxios_improvement_report.md`; retro asset: `docs/project_retro_20260606.md`.
- Data Analytics setup readback on 2026-06-06 recognized 1 semantic layer and completed core local/manual source setup using `HOTEL/docs`, `HOTEL/app`, and `HOTEL/tests`; live MySQL/warehouse, team communication, BI, and external company docs remain explicit future gaps.
- Verification on 2026-06-06 passed: `npm.cmd run verify:context-assets`; `npm.cmd run verify:ota-revenue-metrics-smoke`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaStandardModuleTest.php tests/OperationExecutionLoopTest.php tests/RevenuePricingRecommendationServiceTest.php tests/TransferDecisionServiceTest.php tests/LlmClientTest.php tests/AiModelCallLogTest.php` with 52 tests and 468 assertions.

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
