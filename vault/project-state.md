# SUXIOS Project State Vault

Updated: 2026-06-07 Asia/Shanghai

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
- Save-project verification on 2026-06-07 passed locally: `git diff --check`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never` with 364 tests and 4321 assertions; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `node --test tests/automation/ota_capture_standard.test.mjs`; `npm.cmd run verify:ota-revenue-metrics-smoke`.
- Current 2026-06-07 save scope is grouped into AI daily report, system notifications, OTA compliant collection/resource catalog, Ctrip/Meituan browser-profile capture behavior, OTA operating-scope filtering, macro signal handling, frontend operation/data-health display, migrations, and matching tests.

## Gate State

- Functional acceptance and release readiness are separate states.
- Existing reports show functional acceptance can pass while release readiness remains blocked by production env, LLM attestation, design handoff, OTA credential cleanup/attestation, formal security scan, and local/PR state.
- Do not claim release readiness until the release gates pass with real evidence files, not placeholders.
- `npm.cmd run review:release-readiness` still fails on 2026-06-07 because `.env.production` or `RELEASE_ENV_FILE`, LLM connectivity attestation, design handoff manifest, and OTA credential rotation attestation are not available in the default release-readiness path.

## Ctrip Field Closure State

- Recent Ctrip work moved beyond pure login blocking into captured endpoint evidence, response-only endpoints, gap lists, field selection, and table planning.
- Remaining Ctrip gaps must stay explicit; do not replace missing values with `0` or fallback success.
- Every field must keep Ctrip OTA channel scope unless whole-hotel source evidence exists.

## 2026-06-09 Save: Tiancheng Ctrip Patrol Report

- User requested a Beijing-time patrol report for Tiancheng's Ctrip data and then requested project save.
- Verified store match: `西安天诚`, `hotels.id = 58`.
- Verified source tables used: `platform_data_sources` and `online_daily_data` in local MySQL database `hotelx`.
- Verified Ctrip Profile source state for store `58`: source `10` succeeded at `2026-06-09 13:19:39`; source `12` succeeded at `2026-06-09 15:53:26`; disabled duplicate source `9` remains failed from `2026-06-03`.
- Verified 2026-06-09 Ctrip-scope rows for store `58`: `1592` raw rows; after latest-snapshot dedupe by `source/platform/data_type/dimension/compare_type`, `361` metric rows.
- Latest verified data window: snapshot max `2026-06-09 15:44:36`, update max `2026-06-09 15:53:26`.
- Report boundary: Ctrip OTA channel scope only; do not treat these values as whole-hotel occupancy, ADR, RevPAR, or full-property revenue without PMS/CRS or full-hotel source evidence.
- Worktree was already dirty before this save with unrelated/unowned modifications in 9 files; do not commit or revert them without explicit user instruction.

## 2026-06-10 Save: Project Slimming and OTA Display Closure

- User requested project slimming and GitHub submission.
- Project slimming used the repo script `npm.cmd run slim:local` from the `HOTEL/` repo root.
- Verified dry-run before cleanup: 22 local artifact targets, estimated reclaim `485.94 MB`, limited to ignored runtime/test/output/local Ctrip profile artifacts; dependencies were not included.
- Verified cleanup result: 22 local artifact targets removed; follow-up `npm.cmd run slim:local:dry-run` reported `Target count: 0` and `Estimated reclaim: 0 MB`.
- Verified submit scope before commit: 10 tracked files covering OTA data display, Ctrip field/catalog closure, Meituan percent-derived display metrics, field ledger checks, and related tests/state.
- Pre-submit verification passed: `git diff --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:ctrip-capture-catalog`; `npm.cmd run verify:field-asset-ledger`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run review:non-security`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php` with 139 tests and 1649 assertions; PHP syntax checks for `app/controller/OnlineData.php` and `app/service/platform/CtripBrowserProfileDataSourceAdapter.php`.
- Release boundary remains unchanged: this is a development save/sync, not a release-ready handoff; release readiness still requires production env evidence, LLM connectivity attestation, design handoff manifest, and OTA credential rotation attestation.

## 2026-06-10 Progress: Project Self-Cleaning

- User objective: `项目自净化`.
- Added read-only self-audit command family: `npm.cmd run self:audit`, `npm.cmd run self:audit:json`, `npm.cmd run self:check`, `npm.cmd run self:clean:dry-run`, and `npm.cmd run self:clean`.
- Self-audit source of truth: `scripts/project_self_audit.mjs`; it reports Git state, repository size, tracked-file size, default cleanup candidates, code/text line counts, top-level size, and largest tracked files.
- Verified self-audit after project slimming: full directory about `381.42 MB`, without `.git` about `244.93 MB`, without `.git` and dependencies about `215.74 MB`, tracked files about `17.73 MB`, default cleanup reclaim `0 MB`.
- Verified code-line scope: tracked project code only, about `344` code files, `185171` total code lines, and `169563` nonblank code lines at the time of this audit.
- Existing unrelated/unowned local changes were present during this self-cleaning pass in auto-fetch scheduling files; do not revert or include them in a self-cleaning-only save unless explicitly requested.

## 2026-06-10 Progress: Reports Artifact Cleanup

- Extended controlled local cleanup to include ignored report artifacts: `reports/ctrip_capture_assets`, `reports/meituan_capture_assets`, `reports/ctrip_browser_capture_*.json`, `reports/meituan_browser_capture_*.json`, and `reports/ctrip_capture_target_*.json`.
- Verified before cleanup: `npm.cmd run self:audit` reported 4 cleanup targets and estimated reclaim `153.08 MB`, mostly `reports/ctrip_capture_assets`.
- Executed `npm.cmd run self:clean`; it removed 4 local artifact targets and did not delete tracked report files.
- Verified after cleanup: full directory about `228.37 MB`, without `.git` about `91.86 MB`, without `.git` and dependencies about `62.67 MB`, `reports` about `1.14 MB`, and default cleanup reclaim `0 MB`.
- Verification passed after cleanup: `npm.cmd run self:check` and `git diff --check`.

## Maintenance Rule

Update this vault after important context changes, save-project runs, new release evidence, or completed field/table closure work. Record only verified facts and avoid secrets, raw cookies, raw tokens, account data, phone numbers, screenshots with sensitive OTA data, or large raw capture JSON.
