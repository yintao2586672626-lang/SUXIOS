# SUXIOS Project State Vault

Updated: 2026-06-10 Asia/Shanghai

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

## 2026-06-10 Progress: Tracked Code Hotspot Audit

- Extended `scripts/project_self_audit.mjs` to report tracked code hotspots and split candidates.
- Current verified self-audit after this change: full directory about `228.39 MB`, without `.git` about `91.87 MB`, without `.git` and dependencies about `62.68 MB`, tracked files about `17.75 MB`, default cleanup reclaim `0 MB`.
- Current verified code-line scope: `345` tracked code files, `185758` total code lines, and `170103` nonblank code lines.
- Current split candidates are `public/index.html`, `app/controller/OnlineData.php`, and `public/tailwind.min.css`; these are monitoring/refactor-priority signals, not automatic cleanup targets.
- Verification passed for the hotspot audit change: `npm.cmd run self:audit`.

## 2026-06-10 Progress: Strict Self-Cleaning Gate

- Added a visible audit policy section to `scripts/project_self_audit.mjs` so cleanup thresholds and split-candidate thresholds are explicit in text and JSON output.
- Added optional strict command `npm.cmd run self:check:strict`; it keeps the same cleanup threshold as `self:check` and also fails when tracked split candidates are present.
- `self:check:strict` is a refactor-stage gate, not the daily guard; after static asset disposition, it is expected to fail until `public/index.html` and `app/controller/OnlineData.php` are split or otherwise dispositioned.
- Verified after adding the strict gate: full directory about `228.42 MB`, without `.git` about `91.87 MB`, without `.git` and dependencies about `62.68 MB`, tracked files about `17.76 MB`; code scope `345` files, `185779` total lines, `170123` nonblank lines.
- Verification passed at strict-gate introduction: `node --check scripts\project_self_audit.mjs`, package JSON parse, `npm.cmd run self:audit`, `git diff --check`, and `npm.cmd run self:check`; strict gate failure was confirmed at that stage on 3 split candidates.

## 2026-06-10 Progress: Static Asset Split Disposition

- Added `docs/self_cleaning_split_dispositions.json` to record split-candidate dispositions with evidence.
- `public/tailwind.min.css` was verified as referenced by `public/index.html` and classified as an accepted local static CSS dependency, not a business-code split target.
- Self-audit now reports accepted split candidates separately; strict split candidates reduced from 3 to 2: `public/index.html` and `app/controller/OnlineData.php`.
- Current verified self-audit after this disposition: full directory about `228.44 MB`, without `.git` about `91.87 MB`, without `.git` and dependencies about `62.68 MB`, tracked files about `17.76 MB`; code scope `345` files, `185844` total lines, `170185` nonblank lines.
- Verification passed: `node --check scripts\project_self_audit.mjs`, JSON parse for `docs/self_cleaning_split_dispositions.json`, `node scripts/project_self_audit.mjs --json` parse with `split=2` and `accepted=1`, `git diff --check`, and `npm.cmd run self:check`; strict gate failure was confirmed as expected on the 2 remaining split candidates.

## 2026-06-10 Progress: Dirty Split Candidate Guard

- Extended `scripts/project_self_audit.mjs` to mark each split candidate with `worktree_changed` based on current `git status --short`.
- Current verified self-audit reports both remaining split candidates as locally changed: `public/index.html` and `app/controller/OnlineData.php`.
- Current verified size and line scope after this guard: full directory about `228.47 MB`, without `.git` about `91.88 MB`, without `.git` and dependencies about `62.69 MB`, tracked files about `17.76 MB`; code scope `345` files, `185870` total lines, `170210` nonblank lines.
- Split work should not directly edit those two files in a self-cleaning commit while their unrelated business changes remain uncommitted; first save or isolate the business changes.
- Verification passed: `node --check scripts\project_self_audit.mjs`, `npm.cmd run self:audit`, `git diff --check`, `npm.cmd run self:check`, and JSON parse with `split=2`, `dirty=2`, `accepted=1`; strict gate failure was confirmed as expected on the 2 remaining dirty split candidates.

## 2026-06-10 Progress: Split Map Tooling

- Added read-only split-map command family: `npm.cmd run self:split-map` and `npm.cmd run self:split-map:json`.
- Split-map target scope is currently limited to the 2 remaining strict split candidates: `public/index.html` and `app/controller/OnlineData.php`.
- Current verified split map for `public/index.html`: `43322` lines, worktree changed, `1162` function-level blocks, `44` `currentPage` references; largest blocks include `resetSystemConfig` (`421` lines), `printFeasibilityReport` (`342` lines), and `formatOtaMetricValue` (`312` lines).
- Initial verified split map for `app/controller/OnlineData.php` before the first backend split: `31140` lines, worktree changed, `877` methods; largest methods included `defaultCtripProfileFieldMeta` (`1664` lines), `summarizeCtripOverviewRows` (`281` lines), and `captureMeituanBrowserData` (`274` lines).
- Current verified self-audit after adding the split-map tool: full directory about `228.52 MB`, without `.git` about `91.89 MB`, without `.git` and dependencies about `62.70 MB`, tracked files about `17.77 MB` / `589` files; code scope `346` files, `186133` total lines, `170454` nonblank lines.
- Verification passed: `node --check scripts\project_split_map.mjs`, package JSON parse, `npm.cmd run self:split-map`, JSON parse with `targets=2`, `frontendBlocks=1162`, `backendMethods=877`, `git diff --check`, and `npm.cmd run self:check`.

## 2026-06-10 Progress: Backend Ctrip Field Metadata Split

- Previous 10-file business checkpoint was saved separately as commit `5f4e4c6` (`[保存] 同步启动与自动抓取间隔`) and pushed to `origin/codex/save-project-20260531`.
- PR #2 was verified after that push as open, draft, mergeable, with head `5f4e4c6`; both latest PHP Composer checks completed successfully.
- First backend split target chosen from split-map evidence: `defaultCtripProfileFieldMeta` in `app/controller/OnlineData.php`.
- Added `app/service/CtripProfileFieldMetaService.php` for the base Ctrip profile field metadata table, Ctrip profile key-field list, metadata refresh key list, default capture field rows, flow-transform metadata, weekly report metadata, and competition-profile metadata; `OnlineData.php` keeps normalization, filtering, and compatibility handling.
- Added `app/service/CtripOverviewSummaryService.php` for Ctrip overview metric summary calculation; `OnlineData.php` keeps a thin `summarizeCtripOverviewRows()` wrapper for existing internal calls and reflection tests.
- `app/controller/OnlineData.php` decreased from `31140` lines to `28485` lines; `CtripProfileFieldMetaService.php` is `2415` lines and `CtripOverviewSummaryService.php` is `361` lines.
- Current split map after the backend splits: `app/controller/OnlineData.php` has `874` methods; largest methods are now `captureMeituanBrowserData` (`274` lines), `captureCtripBrowserData` (`272` lines), and `parseAndSaveMeituanData` (`237` lines); Ctrip-domain method span is now `12319` lines.
- Verified after the split: PHP syntax checks for `app/controller/OnlineData.php`, `app/service/CtripProfileFieldMetaService.php`, and `app/service/CtripOverviewSummaryService.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter CtripProfile` with 29 tests and 576 assertions; `tests\OnlineDataTest.php --filter CtripOverview` with 4 tests and 97 assertions; full `tests\OnlineDataTest.php` with 139 tests and 1649 assertions; `npm.cmd run verify:p0-guards`; `npm.cmd run self:audit`.
- Current staged self-audit after the split: tracked files about `17.78 MB` / `591` files; code scope `348` files, `186254` total lines, `170560` nonblank lines.
- Second backend split target chosen from split-map evidence: `generateAnalysisReport` in `app/controller/OnlineData.php`.
- Added `app/service/OnlineDataAnalysisReportService.php` for online-data analysis report HTML rendering; `OnlineData.php` keeps a thin `generateAnalysisReport()` wrapper for existing internal calls.
- Added `tests/OnlineDataAnalysisReportServiceTest.php` for report structure, metric rendering, and suggestion block toggle coverage.
- `app/controller/OnlineData.php` decreased from `28485` lines to `28276` lines; current split map has `873` methods; the `analysis` domain span decreased from `388` to `186` lines.
- Current staged self-audit after the second backend split: tracked files about `17.78 MB` / `593` files; code scope `350` files, `186325` total lines, `170620` nonblank lines.
- Verified after the second split: PHP syntax checks for `app/controller/OnlineData.php`, `app/service/OnlineDataAnalysisReportService.php`, and `tests/OnlineDataAnalysisReportServiceTest.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataAnalysisReportServiceTest.php` with 2 tests and 9 assertions; full `tests\OnlineDataTest.php` with 139 tests and 1649 assertions; `git diff --check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## Maintenance Rule

Update this vault after important context changes, save-project runs, new release evidence, or completed field/table closure work. Record only verified facts and avoid secrets, raw cookies, raw tokens, account data, phone numbers, screenshots with sensitive OTA data, or large raw capture JSON.
