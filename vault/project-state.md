# SUXIOS Project State Vault

Updated: 2026-06-11 Asia/Shanghai

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
- Third backend split target chosen from split-map evidence: `buildPlatformProfileBindingChecks` in `app/controller/OnlineData.php`.
- Added `app/service/PlatformProfileBindingReadinessService.php` for platform Profile P0 readiness check generation; `OnlineData.php` keeps a thin `buildPlatformProfileBindingChecks()` wrapper for existing internal calls and reflection tests.
- `app/controller/OnlineData.php` decreased from `28276` lines to `28119` lines; the `profile` domain span decreased from `1099` to `941` lines.
- Current staged self-audit after the third backend split: tracked files about `17.79 MB` / `594` files; code scope `351` files, `186394` total lines, `170684` nonblank lines.
- Verified after the third split: PHP syntax checks for `app/controller/OnlineData.php` and `app/service/PlatformProfileBindingReadinessService.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter PlatformProfile` with 4 tests and 22 assertions; full `tests\OnlineDataTest.php` with 139 tests and 1649 assertions; `git diff --check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Fourth backend cleanup target chosen from split-map evidence: unreachable legacy code inside `fetchCtripComments`.
- Removed the old Ctrip comment direct-request branch after `commentCollectionDisabledResponse()`; retained `parseAndSaveCtripComments()` and Browser Profile aggregate-only call sites.
- `app/controller/OnlineData.php` decreased from `28119` lines to `27942` lines; the `ctrip` domain span decreased from `12319` to `12142` lines, and `fetchCtripComments` no longer appears in the largest-block list.
- Current staged self-audit after the fourth backend cleanup: tracked files about `17.78 MB` / `594` files; code scope `351` files, `186217` total lines, `170527` nonblank lines.
- Verified after the fourth cleanup: PHP syntax check for `app/controller/OnlineData.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter "CtripComment|Comment|PlatformProfile"` with 8 tests and 40 assertions; full `tests\OnlineDataTest.php` with 139 tests and 1649 assertions; `git diff --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Fifth backend cleanup target chosen from disabled comment-collection evidence: unreachable legacy code inside `fetchMeituanComments` and `captureCtripCommentsBrowserData`.
- Removed the old Meituan comment direct-request branch and the old Ctrip comment browser-capture branch after `commentCollectionDisabledResponse()`; retained `parseAndSaveMeituanComments()`, `parseAndSaveCtripComments()`, and Browser Profile aggregate-only call sites.
- `app/controller/OnlineData.php` decreased from `27942` lines to `27615` lines; the `ctrip` domain span decreased from `12142` to `11998` lines and the `meituan` domain span decreased from `5307` to `5124` lines.
- Current staged self-audit after the fifth backend cleanup: tracked files about `17.77 MB` / `594` files; code scope `351` files, `185890` total lines, `170228` nonblank lines.
- Verified after the fifth cleanup: PHP syntax check for `app/controller/OnlineData.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter "CtripComment|MeituanComment|Comment|PlatformProfile"` with 8 tests and 40 assertions; full `tests\OnlineDataTest.php` with 139 tests and 1649 assertions; `git diff --check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Sixth backend cleanup target chosen from disabled comment-config evidence: unreachable legacy code inside `saveMeituanCommentConfig`, `getMeituanCommentConfigList`, `saveCtripCommentConfig`, and `getCtripCommentConfigList`.
- Removed the old comment config persistence/listing branches after the disabled or empty responses; retained the routed endpoints so old callers receive explicit disabled or empty states.
- Updated `scripts/verify_high_risk_security.php` so the guard verifies disabled comment config endpoints do not persist or expose stale comment credentials instead of asserting unreachable old config binding logic.
- `app/controller/OnlineData.php` decreased from `27615` lines to `27498` lines; the `ctrip` domain span decreased from `11998` to `11940` lines and the `meituan` domain span decreased from `5124` to `5065` lines.
- Current staged self-audit after the sixth backend cleanup: tracked files about `17.77 MB` / `594` files; code scope `351` files, `185777` total lines, `170132` nonblank lines.
- Verified after the sixth cleanup: PHP syntax checks for `app/controller/OnlineData.php` and `scripts/verify_high_risk_security.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter "Comment|Config|PlatformProfile"` with 17 tests and 120 assertions; full `tests\OnlineDataTest.php` with 139 tests and 1649 assertions; `C:\xampp\php\php.exe scripts\verify_high_risk_security.php`; `npm.cmd run review:non-security`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Seventh backend cleanup target chosen from disabled comment auto-fetch evidence: unreachable legacy code inside `executeCtripCommentsAutoFetchTask` and `executeMeituanCommentsAutoFetchTask`.
- Removed the old Ctrip and Meituan comment auto-fetch direct execution methods; `executeAutoFetchTask()` now maps old `ctrip:comments` and `meituan:comments` task labels to an explicit disabled skipped result for compatibility without restoring OTA comment collection.
- Updated `scripts/verify_ota_diagnosis_auto_fetch.mjs` so the guard requires those old comment auto-fetch methods to be absent while the old task labels still return `Comment/review data collection is disabled by policy.`.
- `app/controller/OnlineData.php` decreased from `27498` lines to `27333` lines; method count decreased from `873` to `871`; the `ctrip` domain span decreased from `11940` to `11861` lines and the `meituan` domain span decreased from `5065` to `4979` lines.
- Current staged self-audit after the seventh backend cleanup: full directory about `232.34 MB`, without `.git` about `91.88 MB`, without `.git` and dependencies about `62.69 MB`, tracked files about `17.77 MB` / `594` files; code scope `351` files, `185616` total lines, `169985` nonblank lines.
- Verified after the seventh cleanup: PHP syntax check for `app/controller/OnlineData.php`; Node syntax check for `scripts/verify_ota_diagnosis_auto_fetch.mjs`; `npm.cmd run verify:ota-diagnosis-auto-fetch`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter "AutoFetch|Comment|PlatformProfile"`; full `tests\OnlineDataTest.php`; `node scripts\verify_platform_data_source_contract.mjs`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- First frontend split target chosen from split-map evidence: static expansion and market-evaluation option data embedded in `public/index.html`.
- Added `public/expansion-static-options.js` for market evaluation and expansion static option data: city tiers, city list, district options, address keyword options, competitor-count presets, customer/decor options, condition fields, and default form input.
- `public/index.html` now loads `expansion-static-options.js` and binds the same option variables from `window.SUXI_EXPANSION_STATIC`; no route, API, or Vue CDN runtime contract changed.
- Updated `scripts/verify_expansion_p2.mjs` and `scripts/verify_strategy_location_ui_contract.mjs` so frontend contract checks read both `public/index.html` and `public/expansion-static-options.js` for moved static evidence, while still requiring dynamic address-option builders to remain in the entry file.
- `public/index.html` decreased from `43322` lines to `43132` lines; the frontend `config` span decreased from `1197` to `984` lines.
- Current staged self-audit after the first frontend split: full directory about `234 MB`, without `.git` about `91.88 MB`, without `.git` and dependencies about `62.69 MB`, tracked files about `17.77 MB` / `595` files; code scope `352` files, `185669` total lines, `170036` nonblank lines.
- Verified after the first frontend split: Node syntax checks for `public/expansion-static-options.js`, `scripts/verify_expansion_p2.mjs`, and `scripts/verify_strategy_location_ui_contract.mjs`; `node scripts\verify_expansion_p2.mjs`; `node scripts\verify_strategy_location_ui_contract.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:p0-guards`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Second frontend split target chosen from split-map evidence and low-risk static-data boundaries: hotel AI toolbox and hotel image optimizer static option data embedded in `public/index.html`.
- Added `public/hotel-image-optimizer-static.js` for hotel AI toolbox links, hotel image optimizer scenes, goals, styles, prompt profiles, issue options, and recommended tools.
- `public/index.html` now loads `hotel-image-optimizer-static.js` and binds the same option variables from `window.SUXI_HOTEL_IMAGE_OPTIMIZER_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back to empty options.
- `public/index.html` decreased from `43132` lines to `42907` lines; the frontend `ota` span decreased from `1010` to `754` lines.
- Current staged self-audit after the second frontend split: full directory about `234 MB`, without `.git` about `91.89 MB`, without `.git` and dependencies about `62.7 MB`, tracked files about `17.77 MB` / `596` files; code scope `353` files, `185700` total lines, `170066` nonblank lines.
- Verified after the second frontend split: Node syntax check for `public/hotel-image-optimizer-static.js`; `git diff --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Third frontend split target chosen from static-data boundaries: revenue research center product and step configuration embedded in `public/index.html`.
- Added `public/revenue-research-static.js` for revenue research product cards and run-step labels.
- `public/index.html` now loads `revenue-research-static.js` and binds `revenueResearchProducts` plus `revenueResearchSteps` from `window.SUXI_REVENUE_RESEARCH_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back.
- Updated `scripts/verify_e2e_contracts.mjs` so the revenue research frontend contract checks `public/index.html` plus `public/revenue-research-static.js` for the `service-quality` product and absence of `review-topic`; backend checks remain on `RevenueResearchService.php`.
- `public/index.html` decreased from `42907` lines to `42830` lines; the frontend `hotel_admin` span decreased from `1601` to `1515` lines.
- Current staged self-audit after the third frontend split: full directory about `235 MB`, without `.git` about `91.89 MB`, without `.git` and dependencies about `62.7 MB`, tracked files about `17.78 MB` / `597` files; code scope `354` files, `185741` total lines, `170104` nonblank lines.
- Verified after the third frontend split: Node syntax checks for `public/revenue-research-static.js` and `scripts/verify_e2e_contracts.mjs`; `git diff --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Fourth frontend split target chosen from static display/config boundaries: platform auto-fetch mode options, collection blueprint rows, and OTA field-scope groups embedded in `public/index.html`.
- Added `public/auto-fetch-static.js` for auto-fetch mode options, collection blueprint rows, and OTA field-scope groups.
- `public/index.html` now loads `auto-fetch-static.js` and binds `autoFetchModeOptions`, `autoFetchCollectionBlueprintRows`, and `autoFetchFieldScopeGroups` from `window.SUXI_AUTO_FETCH_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back.
- `public/index.html` decreased from `42830` lines to `42782` lines; the frontend `general` span decreased from `11069` to `11020` lines.
- Current staged self-audit after the fourth frontend split: full directory about `236 MB`, without `.git` about `91.89 MB`, without `.git` and dependencies about `62.7 MB`, tracked files about `17.78 MB` / `598` files; code scope `355` files, `185764` total lines, `170126` nonblank lines.
- Verified after the fourth frontend split: Node syntax check for `public/auto-fetch-static.js`; `git diff --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Fifth frontend split target chosen from static display/config boundaries: compass home trend options, default trend cards, daily operations actions, review steps, weather city list, and quick-entry definitions embedded in `public/index.html`.
- Added `public/compass-static.js` for compass/home static options and definitions.
- `public/index.html` now loads `compass-static.js` and binds compass static values from `window.SUXI_COMPASS_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back.
- `public/index.html` decreased from `42782` lines to `42744` lines; the frontend `general` span decreased from `11020` to `11008` lines, and `ai` span decreased from `1543` to `1516` lines.
- Current staged self-audit after the fifth frontend split: full directory about `236 MB`, without `.git` about `91.90 MB`, without `.git` and dependencies about `62.71 MB`, tracked files about `17.78 MB` / `599` files; code scope `356` files, `185849` total lines, `170210` nonblank lines.
- Verified after the fifth frontend split: Node syntax check for `public/compass-static.js`; `git diff --cached --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Sixth frontend split target chosen from simulation/transfer static field boundaries: simulation defaults, benchmark model detail fields, collaboration status options, expansion record page-type mapping, transfer decision fields, and simulation field groups embedded in `public/index.html`.
- Added `public/simulation-static.js` for simulation and transfer static field definitions.
- `public/index.html` now loads `simulation-static.js` and binds simulation static values from `window.SUXI_SIMULATION_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back.
- `public/index.html` decreased from `42744` lines to `42521` lines; the frontend `general` span decreased from `11008` to `10842` lines, and `config` span decreased from `984` to `897` lines.
- Current staged self-audit after the sixth frontend split: full directory about `237 MB`, without `.git` about `91.90 MB`, without `.git` and dependencies about `62.71 MB`, tracked files about `17.79 MB` / `600` files; code scope `357` files, `185810` total lines, `170171` nonblank lines.
- Verified after the sixth frontend split: Node syntax check for `public/simulation-static.js`; `git diff --cached --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Seventh frontend split target chosen from operation/opening static boundaries: lifecycle metric labels, lifecycle stage titles, operation alert filters, operation strategy types, opening categories/status options, and quick progress values embedded in `public/index.html`.
- Added `public/operation-static.js` for operation/opening static UI options.
- `public/index.html` now loads `operation-static.js` and binds operation static values from `window.SUXI_OPERATION_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back.
- `public/index.html` decreased from `42521` lines to `42493` lines; the frontend `general` span decreased from `10842` to `10809` lines.
- Current staged self-audit after the seventh frontend split: full directory about `237 MB`, without `.git` about `91.90 MB`, without `.git` and dependencies about `62.71 MB`, tracked files about `17.79 MB` / `601` files; code scope `358` files, `185852` total lines, `170212` nonblank lines.
- Verified after the seventh frontend split: Node syntax check for `public/operation-static.js`; `git diff --cached --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Eighth frontend split target chosen from compass macro-signal static copy: macro signal meanings, home market forecast summary notes, and default Meituan rank types embedded in `public/index.html`.
- Extended `public/compass-static.js` for macro signal and competitor display static copy.
- `public/index.html` now reuses the existing `window.SUXI_COMPASS_STATIC` binding for those values; missing keys still throw explicit configuration errors through `requireCompassStatic`.
- `public/index.html` decreased from `42493` lines to `42453` lines; the frontend `general` span decreased from `10809` to `10769` lines.
- Current staged self-audit after the eighth frontend split: full directory about `238 MB`, without `.git` about `91.91 MB`, without `.git` and dependencies about `62.72 MB`, tracked files about `17.79 MB` / `601` files; code scope `358` files, `185858` total lines, `170218` nonblank lines.
- Verified after the eighth frontend split: Node syntax check for `public/compass-static.js`; `git diff --cached --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Ninth frontend split target chosen from Ctrip static display/config boundaries: Ctrip Profile module definitions, forbidden collection field boundary data, overview API keywords, flow overview API groups, and default request URLs embedded in `public/index.html`.
- Added `public/ctrip-static.js` for Ctrip static UI/config definitions.
- `public/index.html` now loads `ctrip-static.js` and binds Ctrip static values from `window.SUXI_CTRIP_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back.
- `public/index.html` decreased from `42453` lines to `42404` lines; the frontend `ctrip` span decreased from `3909` to `3877` lines and `general` span decreased from `10769` to `10751` lines.
- Current staged self-audit after the ninth frontend split: full directory about `238.66 MB`, without `.git` about `91.91 MB`, without `.git` and dependencies about `62.72 MB`, tracked files about `17.80 MB` / `602` files; code scope `359` files, `185888` total lines, `170247` nonblank lines.
- Verified after the ninth frontend split: Node syntax check for `public/ctrip-static.js`; residual inline static-definition scan; `git diff --check`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run verify:p0-guards`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Tenth frontend split target chosen from system/AI/knowledge static UI boundaries: test-id name map, hotel/user table columns, knowledge import/source options, AI quick setup options, AI governance tabs, data config profile copy, document extension lists, and Agent tabs embedded in `public/index.html`.
- Added `public/system-static.js` for system, AI, and knowledge-center static UI/config definitions.
- `public/index.html` now loads `system-static.js` and binds system static values from `window.SUXI_SYSTEM_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back.
- `public/index.html` decreased from `42404` lines to `42241` lines; the frontend `general` span decreased from `10751` to `10586` lines.
- Current staged self-audit after the tenth frontend split: full directory about `239.26 MB`, without `.git` about `91.91 MB`, without `.git` and dependencies about `62.72 MB`, tracked files about `17.80 MB` / `603` files; code scope `360` files, `185930` total lines, `170289` nonblank lines.
- Verified after the tenth frontend split: Node syntax check for `public/system-static.js`; residual inline static-definition scan; `git diff --cached --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Eleventh frontend split target chosen from system/AI i18n static boundaries: AI model configuration i18n copy and language options embedded in `public/index.html`.
- Extended `public/system-static.js` for `aiModelConfigI18n` and `languageOptions`.
- `public/index.html` now reads `aiModelConfigI18n` and `languageOptions` through `window.SUXI_SYSTEM_STATIC`; missing script or missing keys throw explicit configuration errors instead of silently falling back.
- Updated `scripts/verify_ai_model_config_i18n.mjs` so the i18n contract checks both `public/index.html` and `public/system-static.js` after the static split.
- `public/index.html` decreased from `42241` lines to `42101` lines; current split map reports `1172` frontend function-level blocks and `44` `currentPage` references.
- Current staged self-audit after the eleventh frontend split: full directory about `239.29 MB`, without `.git` about `91.92 MB`, without `.git` and dependencies about `62.73 MB`, tracked files about `17.80 MB` / `603` files; code scope `360` files, `185946` total lines, `170305` nonblank lines.
- Verified after the eleventh frontend split: Node syntax checks for `public/system-static.js` and `scripts/verify_ai_model_config_i18n.mjs`; `node scripts\verify_ai_model_config_i18n.mjs`; residual inline static-definition scan; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Twelfth frontend split target chosen from reusable Vue component boundaries: `CompassCardHeader`, `MetricCard`, `SearchInput`, `StatusFilter`, `StatusBadge`, `RoleBadge`, `ActionButtons`, and `DataTable`.
- Added `public/shared-components.js` for these reusable Vue components.
- `public/index.html` now loads `shared-components.js` and reads components through `window.SUXI_SHARED_COMPONENTS`; missing script or missing component keys throw explicit configuration errors instead of silently falling back.
- `public/index.html` decreased from `42101` lines to `41996` lines; current split map reports `1173` frontend function-level blocks and `44` `currentPage` references.
- Current staged self-audit after the twelfth frontend split: full directory about `240.43 MB`, without `.git` about `91.92 MB`, without `.git` and dependencies about `62.73 MB`, tracked files about `17.80 MB` / `604` files; code scope `361` files, `185977` total lines, `170335` nonblank lines.
- Verified after the twelfth frontend split: Node syntax check for `public/shared-components.js`; residual inline component-definition scan; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; serial retry of `npm.cmd run self:check` after confirming `.git/index.lock` was absent; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.
- Thirteenth frontend split target chosen from static navigation boundaries: main sidebar menu definitions embedded in `public/index.html`.
- Extended `public/system-static.js` for `menuItemDefinitions`.
- `public/index.html` now builds menu items from `menuItemDefinitions`, keeping only recursive dynamic-name injection for `systemConfig.menu_hotel_name`; missing static keys still throw explicit configuration errors through `requireAppSystemStatic`.
- `public/index.html` decreased from `41996` lines to `41899` lines; current split map reports `1174` frontend function-level blocks and `44` `currentPage` references.
- Current staged self-audit after the thirteenth frontend split: full directory about `240.46 MB`, without `.git` about `91.92 MB`, without `.git` and dependencies about `62.73 MB`, tracked files about `17.81 MB` / `604` files; code scope `361` files, `185990` total lines, `170348` nonblank lines.
- Verified after the thirteenth frontend split: Node syntax check for `public/system-static.js`; residual inline menu-definition scan; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend Notification Utility Split

- Fourteenth frontend split target chosen from low-risk global notification display helpers in `public/index.html`.
- Added `public/notification-static.js` for global notification text sanitizing, severity/style mapping, action-target mapping, time formatting, id building, and backend-notification normalization.
- `public/index.html` now loads `notification-static.js` and binds those helpers from `window.SUXI_NOTIFICATION_STATIC`; missing script throws an explicit configuration error instead of silently falling back.
- `public/index.html` decreased from `41899` lines to `41837` lines; current split map reports `1166` frontend function-level blocks and `44` `currentPage` references.
- Current staged self-audit after the fourteenth frontend split: full directory about `241.64 MB`, without `.git` about `91.93 MB`, without `.git` and dependencies about `62.74 MB`, tracked files about `17.81 MB` / `605` files; code scope `362` files, `186024` total lines, `170374` nonblank lines.
- Verified after the fourteenth frontend split: `node --check public\notification-static.js`; residual notification-helper definition scan; `git diff --check`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend Meituan Display Utility Split

- Fifteenth frontend split target chosen from low-risk Meituan ranking display helpers in `public/index.html`.
- Added `public/meituan-static.js` for Meituan metric labels, sort metric values, sort-gap formatting, row keys, and ranked display-row generation.
- `public/index.html` now loads `meituan-static.js` and binds the needed Meituan display helpers from `window.SUXI_MEITUAN_STATIC`; missing script or function keys throw explicit configuration errors instead of silently falling back.
- `public/index.html` decreased from `41837` lines to `41781` lines; current split map reports `1162` frontend function-level blocks, `44` `currentPage` references, and Meituan-domain span `1369` lines.
- Current staged self-audit after the fifteenth frontend split: full directory about `242.26 MB`, without `.git` about `91.93 MB`, without `.git` and dependencies about `62.74 MB`, tracked files about `17.82 MB` / `606` files; code scope `363` files, `186052` total lines, `170397` nonblank lines.
- Verified after the fifteenth frontend split: `node --check public\meituan-static.js`; residual Meituan helper definition scan; `git diff --check`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend Data Health Display Utility Split

- Sixteenth frontend split target chosen from low-risk data-health display helpers in `public/index.html`.
- Added `public/data-health-static.js` for online data quality status text/classes, prompt slicing, quality-scope text, auto-fetch record status classes, cookie health light text/classes, data-health status normalization, priority text/classes, and OTA platform labels.
- `public/index.html` now loads `data-health-static.js` and binds the needed data-health display helpers from `window.SUXI_DATA_HEALTH_STATIC`; missing script or function keys throw explicit configuration errors instead of silently falling back.
- Updated `tests/automation/ctrip_store_data_overview.test.mjs` so split static evidence is read from `public/data-health-static.js` and `public/ctrip-static.js` instead of requiring those static constants to remain embedded in `public/index.html`.
- `public/index.html` decreased from `41781` lines to `41740` lines; current split map reports `1152` frontend function-level blocks and `44` `currentPage` references.
- Current staged self-audit after the sixteenth frontend split: full directory about `242.84 MB`, without `.git` about `91.93 MB`, without `.git` and dependencies about `62.74 MB`, tracked files about `17.82 MB` / `607` files; code scope `364` files, `186103` total lines, `170437` nonblank lines.
- Verified after the sixteenth frontend split: `node --check public\data-health-static.js`; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; residual data-health helper definition scan; `git diff --check`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend Data Health Work Order Utility Split

- Seventeenth frontend split target chosen from pure data-health row builders: failure-reason ranking and today's work-order rows.
- Extended `public/data-health-static.js` with `buildCollectionHealthFailureReasonRanking` and `buildDataHealthTodayWorkOrders`.
- `public/index.html` now keeps only computed bindings for `collectionHealthFailureReasonRanking` and `dataHealthTodayWorkOrders`; merge, dedupe, priority sort, and default display copy are handled by `window.SUXI_DATA_HEALTH_STATIC`.
- Updated `tests/automation/ctrip_store_data_overview.test.mjs` so the data-health static file is the evidence source for those builders while the entry file must explicitly load them through `requireDataHealthStatic()`.
- `public/index.html` decreased from `41740` lines to `41657` lines; current split map reports `1151` frontend function-level blocks, `44` `currentPage` references, and general-domain span `10259` lines.
- Current self-audit after the seventeenth frontend split: full directory about `242.88 MB`, without `.git` about `91.94 MB`, without `.git` and dependencies about `62.75 MB`, tracked files about `17.82 MB` / `607` files; code scope `364` files, `186126` total lines, `170458` nonblank lines.
- Verified after the seventeenth frontend split: `node --check public\data-health-static.js`; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; residual data-health builder scan; `git diff --check`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend Meituan Competitor Card Utility Split

- Eighteenth frontend split target chosen from Meituan competitor summary card builders in `public/index.html`.
- Extended `public/meituan-static.js` with `buildCompetitorSummaryCoreCards` and `buildHomeCompetitorSummaryCards`.
- `public/index.html` now keeps a thin `competitorSummaryCoreCards` wrapper and the `homeCompetitorSummaryCards` computed binding; card labels, explicit missing-state copy, classes, and entry payload shaping are handled by `window.SUXI_MEITUAN_STATIC`.
- Updated `scripts/verify_p0_learning_contract.mjs` so Meituan competitor summary evidence reads both `public/index.html` and `public/meituan-static.js`, while platform Profile binding evidence also reads `app/service/PlatformProfileBindingReadinessService.php` after the earlier service split.
- `public/index.html` decreased from `41657` lines to `41586` lines; current `public/meituan-static.js` is `181` lines; current split map reports `1151` frontend function-level blocks, `44` `currentPage` references, general-domain span `10186` lines, and Meituan-domain span `1371` lines.
- Current self-audit after the eighteenth frontend split: full directory about `243.47 MB`, without `.git` about `91.94 MB`, without `.git` and dependencies about `62.75 MB`, tracked files about `17.83 MB` / `607` files; code scope `364` files, `186165` total lines, `170495` nonblank lines.
- Verified after the eighteenth frontend split: `node --check public\meituan-static.js`; `node --check scripts\verify_p0_learning_contract.mjs`; `npm.cmd run verify:p0-learning`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run verify:p0-guards`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend Home Closed-Loop Utility Split

- Nineteenth frontend split target chosen from home-page closed-loop and AI trace row builders in `public/index.html`.
- Added `public/home-static.js` with `buildHomeClosedLoopStages` and `buildHomeAiTraceRows`.
- `public/index.html` now keeps only Vue computed bindings and runtime inputs for `homeClosedLoopStages` and `homeAiTraceRows`; product-chain labels, explicit missing-state copy, evidence text, and quick-entry payload shaping are handled by `window.SUXI_HOME_STATIC`.
- Updated `scripts/verify_home_visual_hierarchy_contract.mjs` so the home closed-loop product-chain evidence reads `public/home-static.js`, while the entry file must explicitly load `home-static.js` and read both builders through `requireHomeStatic()`.
- `public/index.html` decreased from `41586` lines to `41527` lines; current `public/home-static.js` is `122` lines; current split map reports `1152` frontend function-level blocks, `44` `currentPage` references, and general-domain span `10126` lines.
- Current self-audit after the nineteenth frontend split: full directory about `244.06 MB`, without `.git` about `91.95 MB`, without `.git` and dependencies about `62.76 MB`, tracked files about `17.83 MB` / `608` files; code scope `365` files, `186245` total lines, `170572` nonblank lines.
- Verified after the nineteenth frontend split: `node --check public\home-static.js`; `node --check scripts\verify_home_visual_hierarchy_contract.mjs`; `npm.cmd run verify:home-visual-hierarchy`; `npm.cmd run verify:public-entry`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend OTA Diagnosis Display Utility Split

- Twentieth frontend split target chosen from pure OTA diagnosis result display builders in `public/index.html`.
- Added `public/ota-diagnosis-static.js` with `normalizeOtaDiagnosisList`, priority text/classes, date-range text, metric cards, and diagnosis section builders.
- `public/index.html` now keeps only computed bindings and runtime inputs for OTA diagnosis display; result labels, icons, section mapping, and empty-state copy are handled by `window.SUXI_OTA_DIAGNOSIS_STATIC`.
- This split intentionally does not move `runOtaDiagnosisHotelFetch`; OTA capture, Cookie/Profile checks, request calls, and persistence behavior remain in the existing entry flow.
- Updated `scripts/verify_e2e_contracts.mjs` so OTA diagnosis UI section evidence reads both `public/index.html` and `public/ota-diagnosis-static.js`; updated `scripts/verify_ota_diagnosis_auto_fetch.mjs` so Ctrip overview static endpoint evidence reads `public/ctrip-static.js` after the earlier static split.
- `public/index.html` decreased from `41527` lines to `41467` lines; current `public/ota-diagnosis-static.js` is `94` lines; current split map reports `1151` frontend function-level blocks, `44` `currentPage` references, and OTA-domain span `693` lines.
- Current self-audit after the twentieth frontend split: full directory about `244.65 MB`, without `.git` about `91.96 MB`, without `.git` and dependencies about `62.77 MB`, tracked files about `17.84 MB` / `609` files; code scope `366` files, `186288` total lines, `170608` nonblank lines.
- Verified after the twentieth frontend split: `node --check public\ota-diagnosis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `node --check scripts\verify_ota_diagnosis_auto_fetch.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:ota-diagnosis-auto-fetch`; `npm.cmd run verify:public-entry`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend Simulation Pure Utility Split

- Twenty-first frontend split target chosen from pure simulation calculation/display helpers in `public/index.html`.
- Extended `public/simulation-static.js` with `simulationGroupTotal`, simulation revenue/cost summary builders, risk-hint builder, simulation model-analysis normalization, simulation input normalization, and simulation input validation.
- `public/index.html` now reads those helpers through `window.SUXI_SIMULATION_STATIC`; missing script or missing function keys continue to throw explicit configuration errors through `requireSimulationStatic()`.
- This split intentionally does not move `handleSimulation`, request execution, record loading/archiving, localStorage persistence, or Vue ref state handling.
- `public/index.html` decreased from `41467` lines to `41182` lines; current `public/simulation-static.js` is `444` lines; current split map reports `1133` frontend function-level blocks, `44` `currentPage` references, and simulation-domain span `380` lines.
- Current self-audit after the twenty-first frontend split: full directory about `245.23 MB`, without `.git` about `91.95 MB`, without `.git` and dependencies about `62.76 MB`, tracked files about `17.84 MB` / `609` files; code scope `366` files, `186265` total lines, `170593` nonblank lines.
- Verified after the twenty-first frontend split: `node --check public\simulation-static.js`; simulation-static export smoke check; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `node scripts\verify_simulation_p2.mjs`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `OnlineData.php`, are further reduced.

## 2026-06-10 Progress: Frontend Expansion Helper Split

- Twenty-second frontend split target chosen from low-risk collaboration defaults and market-evaluation / strategy-location pure helpers in `public/index.html`.
- Extended `public/simulation-static.js` with `buildCollaborationTasks`, keeping collaboration API calls, history reuse, runtime result handling, and Vue state in the entry file.
- Extended `public/expansion-static-options.js` with city-tier lookup, strategy district options, strategy address keyword options, known address keyword detection, and `normalizeMarketEvaluationForm`.
- `public/index.html` now reads those helpers through `window.SUXI_SIMULATION_STATIC` and `window.SUXI_EXPANSION_STATIC`; missing scripts or missing keys still throw explicit configuration errors instead of silently falling back.
- This split does not move market-evaluation request execution, strategy runtime data paths, collaboration request execution, history reuse, local state, or OTA channel data paths.
- `public/index.html` decreased from `41182` lines to `41127` lines; split-map frontend function-level blocks decreased from `1133` to `1126`; the `general` domain span decreased from `10111` to `9866` lines.
- Current self-audit: full directory about `245.82 MB`, without `.git` about `91.96 MB`, without `.git` and dependencies about `62.77 MB`, tracked files about `17.85 MB` / `609` files; code scope `366` files, `186284` total lines, `170612` nonblank lines.
- Verified after the twenty-second frontend split: `node --check public\expansion-static-options.js`; `node --check public\simulation-static.js`; expansion-static and simulation-static export smoke checks; `npm.cmd run verify:public-entry`; `node scripts\verify_expansion_p2.mjs`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Data Health Builder Split

- Twenty-third frontend split target chosen from pure data-health display builders in `public/index.html`.
- Extended `public/data-health-static.js` with diagnostic-boundary, cookie-alert, quality-task, high-risk-action, public-endpoint summary, and public-endpoint text helpers.
- `public/index.html` now keeps only computed bindings and runtime inputs for those data-health displays; OTA capture, Cookie/Profile checks, persistence, data-source diagnostics loading, and high-risk operation-log fetching remain in the entry/runtime flow.
- Updated `tests/automation/ctrip_store_data_overview.test.mjs` so the new data-health builders are required from `public/data-health-static.js`, while the entry file must explicitly load them through `requireDataHealthStatic()`.
- `public/index.html` decreased from `41127` lines to `40976` lines; split-map frontend function-level blocks decreased from `1126` to `1124`; the `general` domain span decreased from `9866` to `9715` lines.
- Current `public/data-health-static.js` is `418` lines. Total code lines increased to `186363` and nonblank lines to `170682` because helper logic and the verification contract moved out of the entry file instead of being deleted.
- Current self-audit: full directory about `246.41 MB`, without `.git` about `91.97 MB`, without `.git` and dependencies about `62.78 MB`, tracked files about `17.85 MB` / `609` files; code scope `366` files.
- Verified after the twenty-third frontend split: `node --check public\data-health-static.js`; data-health static export smoke check; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Flow Interface Builder Split

- Twenty-fourth frontend split target chosen from pure Ctrip flow-overview interface diagnosis display logic in `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripFlowOverviewInterfaceRows`, including request-hit, response-hit, parsed-row, failed-request, and actionable missing-reason text handling.
- `public/index.html` now keeps only the `ctripFlowOverviewInterfaceRows` computed binding and runtime result input; Ctrip overview fetch, supplemental capture, Cookie/Profile checks, API calls, and persistence remain in the entry/runtime flow.
- Updated `tests/automation/ctrip_store_data_overview.test.mjs` so the Ctrip flow interface builder and missing-reason copy are required from `public/ctrip-static.js`, while the entry file must explicitly load the builder through `requireCtripStatic()`.
- `public/index.html` decreased from `40976` lines to `40885` lines; split-map frontend function-level blocks decreased from `1124` to `1120`; the `general` domain span decreased from `9715` to `9533` lines.
- Current `public/ctrip-static.js` is `175` lines. Total code lines increased to `186369` and nonblank lines to `170687` because helper logic and the verification contract moved out of the entry file instead of being deleted.
- Current self-audit: full directory about `247 MB`, without `.git` about `91.97 MB`, without `.git` and dependencies about `62.78 MB`, tracked files about `17.86 MB` / `609` files; code scope `366` files.
- Verified after the twenty-fourth frontend split: `node --check public\ctrip-static.js`; Ctrip static export smoke check; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Overview Card Builder Split

- Twenty-fifth frontend split target chosen from pure Ctrip overview and flow-overview metric card / TOP-rank display builders in `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripOverviewMetricCards`, `buildCtripOverviewTopRankTables`, and `buildCtripFlowOverviewMetricCards`.
- `public/index.html` now keeps only the `ctripOverviewMetricCards`, `ctripOverviewTopRankTables`, and `ctripFlowOverviewMetricCards` computed bindings and runtime result inputs; Ctrip overview fetch, flow-overview fetch, supplemental capture, Cookie/Profile checks, API calls, and persistence remain in the entry/runtime flow.
- Updated `tests/automation/ctrip_store_data_overview.test.mjs` so the Ctrip overview display builders are required from `public/ctrip-static.js`, while the entry file must explicitly load them through `requireCtripStatic()`.
- `public/index.html` decreased from `40885` lines to `40757` lines; split-map frontend function-level blocks decreased from `1120` to `1119`; the Ctrip-domain span decreased from `3968` to `3840` lines.
- Current `public/ctrip-static.js` is `317` lines. Total code lines increased to `186391` and nonblank lines to `170704` because helper logic and the verification contract moved out of the entry file instead of being deleted.
- Current self-audit: full directory about `247.58 MB`, without `.git` about `91.97 MB`, without `.git` and dependencies about `62.78 MB`, tracked files about `17.86 MB` / `609` files; code scope `366` files.
- Verified after the twenty-fifth frontend split: `node --check public\ctrip-static.js`; Ctrip static export smoke check; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Table Sort Builder Split

- Twenty-sixth frontend split target chosen from pure Ctrip ranking-table sort value mapping in `public/index.html`.
- Extended `public/ctrip-static.js` with `ctripSortMetricValue` and `buildCtripSortedHotelRows`.
- `public/index.html` now keeps only `ctripSortedHotelsList` computed binding plus sort/page state; Ctrip ranking data fetch, persistence, overview fetch, Cookie/Profile checks, and OTA channel data paths remain unchanged.
- Updated `tests/automation/ctrip_store_data_overview.test.mjs` so the Ctrip table sort builder is required from `public/ctrip-static.js`, while the entry file must explicitly load it through `requireCtripStatic()`.
- `public/index.html` decreased from `40757` lines to `40703` lines; the Ctrip-domain span decreased from `3840` to `3786` lines.
- Current `public/ctrip-static.js` is `350` lines. Total code lines are `186373` and nonblank lines are `170684`.
- Current self-audit: full directory about `248.17 MB`, without `.git` about `91.98 MB`, without `.git` and dependencies about `62.79 MB`, tracked files about `17.86 MB` / `609` files; code scope `366` files.
- Verified after the twenty-sixth frontend split: `node --check public\ctrip-static.js`; Ctrip static export smoke check; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Strategy Location Helper Split

- Twenty-seventh frontend split target chosen from strategy-location computed/watch helper logic in `public/index.html`.
- Extended `public/expansion-static-options.js` with project-level strategy helpers for city options, district options, address options, district reset, address reset, and competitor-count estimation.
- `public/index.html` now keeps only Vue computed/watch bindings and runtime assignment for the strategy-location flow; market evaluation, strategy request execution, history reuse, persistence, and OTA data paths remain unchanged.
- Updated `scripts/verify_strategy_location_ui_contract.mjs` so the strategy-location contract requires the helper logic from `public/expansion-static-options.js`, while the entry file must explicitly pass `aiProject.value`.
- `public/index.html` decreased from `40703` lines to `40679` lines; the strategy-domain span decreased from `381` to `360` lines.
- Current `public/expansion-static-options.js` is `338` lines. Total code lines are `186405` and nonblank lines are `170716`.
- Current self-audit: full directory about `249.34 MB`, without `.git` about `91.98 MB`, without `.git` and dependencies about `62.79 MB`, tracked files about `17.87 MB` / `609` files; code scope `366` files.
- Verified after the twenty-seventh frontend split: `node --check public\expansion-static-options.js`; `node --check scripts\verify_strategy_location_ui_contract.mjs`; expansion strategy helper smoke check; `node scripts\verify_strategy_location_ui_contract.mjs`; `node scripts\verify_expansion_p2.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Operation Display Builder Split

- Twenty-eighth frontend split target chosen from pure operation-dashboard display builders in `public/index.html`.
- Extended `public/operation-static.js` with builders for operation summary cards, OTA funnel cards, competitor cards, source brief, and decision cards.
- `public/index.html` now keeps only computed bindings and runtime formatters for those operation dashboard displays; hotel permission selection, operation API requests, execution flow, AI daily report calls, root-cause analysis, and OTA data paths remain unchanged.
- Updated `scripts/verify_e2e_contracts.mjs` so operation service-quality and decision-card evidence reads both `public/index.html` and `public/operation-static.js` after the display split.
- `public/index.html` decreased from `40679` lines to `40583` lines; the operation-domain span decreased from `676` to `575` lines.
- Current `public/operation-static.js` is `183` lines. Total code lines are `186431` and nonblank lines are `170742`.
- Current self-audit: full directory about `249.38 MB`, without `.git` about `91.99 MB`, without `.git` and dependencies about `62.8 MB`, tracked files about `17.87 MB` / `609` files; code scope `366` files.
- Verified after the twenty-eighth frontend split: `node --check public\operation-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; operation display helper smoke check; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Data Health Display Builder Split

- Twenty-ninth frontend split target chosen from pure Ctrip collection-health catalog, latest-capture, overview-status, and supplemental-fetch card builders in `public/index.html`.
- Extended `public/data-health-static.js` with Ctrip collection-health display builders for catalog cards, diagnostic scope, authorization text, pending fetch/field text, visible notes, action text, latest cards, overview status cards, and overview fetch module cards.
- `public/index.html` now keeps only computed bindings plus runtime Ctrip auth state, hotel-identity blocking, persisted-row counts, latest metadata, and source-row count inputs; Ctrip capture, Cookie/Profile checks, API requests, persistence, and OTA channel data paths remain unchanged.
- Updated `tests/automation/ctrip_store_data_overview.test.mjs` so Ctrip overview cards, supplemental fetch labels/tabs, identity-conflict display copy, and the new builder contracts are checked across `public/index.html` and `public/data-health-static.js`.
- `public/index.html` decreased from `40583` lines to `40525` lines; split-map frontend function-level blocks decreased from `1119` to `1117`; the `general` domain span decreased from `9538` to `9411` lines.
- Current `public/data-health-static.js` is `536` lines. Total code lines are `186498` and nonblank lines are `170799`.
- Current self-audit: full directory about `249.97 MB`, without `.git` about `92 MB`, without `.git` and dependencies about `62.81 MB`, tracked files about `17.88 MB` / `609` files; code scope `366` files.
- Verified after the twenty-ninth frontend split: `node --check public\data-health-static.js`; `node --check tests\automation\ctrip_store_data_overview.test.mjs`; data-health Ctrip display builder smoke check; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Backend Ctrip Traffic Display Service Split

- First backend split target chosen from pure Ctrip traffic display-row, display-summary, and APP traffic derived-analysis helpers in `app/controller/OnlineData.php`.
- Added `app/service/CtripTrafficDisplayService.php` with traffic display rows, display summaries, derived APP traffic analysis, traffic number coercion, percent normalization, rate calculation, stage diagnosis, and recommendation builders.
- `app/controller/OnlineData.php` now keeps same private helper names as thin wrappers so reflection-based tests and static display-boundary contracts still find the controller methods; Ctrip traffic requests, Cookie checks, date-range parsing, response extraction, persistence, ads capture, Meituan capture, auto-fetch, and routes remain unchanged.
- `app/controller/OnlineData.php` decreased from `27333` lines to `27052` lines; split-map `traffic` domain span decreased from `547` to `335` lines.
- Current `app/service/CtripTrafficDisplayService.php` is `368` lines. Total code lines are `186584` and nonblank lines are `170868`.
- Current self-audit after staging the new service: full directory about `250.83 MB`, without `.git` about `92 MB`, without `.git` and dependencies about `62.81 MB`, tracked files about `17.89 MB` / `610` files; code scope `367` files.
- Verified after the backend Ctrip traffic display split: `C:\xampp\php\php.exe -l app\controller\OnlineData.php`; `C:\xampp\php\php.exe -l app\service\CtripTrafficDisplayService.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php`; `npm.cmd run verify:e2e-contracts`; `node scripts\verify_frontend_display_boundary.mjs`; `git diff --check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Backend Ctrip Capture Diagnosis Service Split

- Second backend split target chosen from pure Ctrip capture diagnosis counts, fact-row payload, metric-key, group, and label helpers in `app/controller/OnlineData.php`.
- Added `app/service/CtripCaptureDiagnosisService.php` with capture counts, diagnosis summary, metric-key splitting, catalog-dimension key extraction, diagnosis groups, and metric labels.
- `app/controller/OnlineData.php` keeps the same private helper names as thin wrappers so reflection-based tests and the OTA diagnosis auto-fetch verifier still find the controller methods.
- This split intentionally does not move Ctrip/Meituan capture execution, Cookie/Profile checks, `extractCtripCapturedSection` dedupe extraction, capture-gate decisions, response parsing, persistence, routes, or UI.
- `app/controller/OnlineData.php` decreased from `27052` lines to `26725` lines; split-map Ctrip-domain span decreased from `11791` to `11463` lines.
- Current `app/service/CtripCaptureDiagnosisService.php` is `360` lines. Total code lines are `186636` and nonblank lines are `170911`.
- Current self-audit after staging the new service: full directory about `251.14 MB`, without `.git` about `92 MB`, without `.git` and dependencies about `62.81 MB`, tracked files about `17.89 MB` / `611` files; code scope `368` files.
- Verified after the backend Ctrip capture diagnosis split: `C:\xampp\php\php.exe -l app\controller\OnlineData.php`; `C:\xampp\php\php.exe -l app\service\CtripCaptureDiagnosisService.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter "CtripCaptureDiagnosisSummary|CtripCaptureCounts|CtripCaptureGate"`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\ServiceInventoryTest.php`; `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php`; `node scripts\verify_ota_diagnosis_auto_fetch.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Home Decision Builder Split

- Thirtieth frontend split target chosen from pure home first-screen decision strip, action-row, and data-readiness builders in `public/index.html`.
- Extended `public/home-static.js` with `buildHomeBoardActionRows`, `buildCompassDataReadiness`, and `buildHomeDecisionSummaryRows`.
- `public/index.html` now keeps only Vue computed bindings and runtime state reads for those home displays; home trend loading, compass data sources, competitor summary, macro signal lookup, quick-entry drag/save behavior, request execution, and OTA data paths remain unchanged.
- Updated `scripts/verify_home_visual_hierarchy_contract.mjs` so the home hierarchy contract requires the new helpers from `public/home-static.js`, while the entry file must explicitly load them through `requireHomeStatic()`.
- `public/index.html` decreased from `40525` lines to `40449` lines; split-map general-domain span decreased from `9411` to `9335` lines.
- Current `public/home-static.js` is `246` lines. Total code lines are `186698` and nonblank lines are `170971`.
- Current self-audit: full directory about `251.19 MB`, without `.git` about `92.01 MB`, without `.git` and dependencies about `62.82 MB`, tracked files about `17.9 MB` / `611` files; code scope `368` files.
- Verified after the frontend home decision split: `node --check public\home-static.js`; `node --check scripts\verify_home_visual_hierarchy_contract.mjs`; `npm.cmd run verify:home-visual-hierarchy`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Feasibility Display Builder Split

- Thirty-first frontend split target chosen from pure feasibility-report display builders in `public/index.html`.
- Extended `public/expansion-static-options.js` with builders for feasibility input cards, report cards, AI empowerment summary, decision-grade class, and report text serialization.
- `public/index.html` now keeps only computed bindings, runtime inputs, and copy/print actions for the feasibility report flow; report generation requests, history reuse, archive handling, localStorage state, API calls, and OTA data paths remain unchanged.
- Updated `scripts/verify_expansion_p2.mjs` so expansion P2 requires the feasibility display builders from `public/expansion-static-options.js`, while the entry file must explicitly load them through `requireExpansionStaticOption()`.
- `public/index.html` decreased from `40449` lines to `40356` lines; split-map frontend function-level blocks decreased from `1117` to `1113`; the `general` domain span decreased from `9335` to `9237` lines.
- Current `public/expansion-static-options.js` is `451` lines. Total code lines are `186730` and nonblank lines are `171005` after the code move.
- Current self-audit after the code move: full directory about `251.77 MB`, without `.git` about `92.01 MB`, without `.git` and dependencies about `62.82 MB`, tracked files about `17.9 MB` / `611` files; code scope `368` files.
- Verified after the frontend feasibility display split: `node --check public\expansion-static-options.js`; `node --check scripts\verify_expansion_p2.mjs`; `node scripts\verify_expansion_p2.mjs`; `node scripts\verify_strategy_location_ui_contract.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check -- public\index.html public\expansion-static-options.js scripts\verify_expansion_p2.mjs`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Simulation Display Builder Split

- Thirty-second frontend split target chosen from pure simulation display computed logic in `public/index.html`.
- Extended `public/simulation-static.js` with builders for investment groups, investment totals, per-room investment, room revenue segments, cost groups, OTA commission channels, model-analysis visibility, and model-source labels.
- `public/index.html` now keeps only Vue computed bindings and runtime state inputs for those simulation displays; simulation requests, record loading, history reuse, archiving, localStorage persistence, backend save behavior, and OTA data paths remain unchanged.
- Updated `scripts/verify_simulation_p2.mjs` so simulation P2 requires the display builders from `public/simulation-static.js`, while the entry file must explicitly load them through `requireSimulationStatic()`.
- `public/index.html` decreased from `40356` lines to `40319` lines; split-map frontend function-level blocks decreased from `1113` to `1110`; the `simulation` domain span decreased from `380` to `343` lines, and the `handleSimulation` block span decreased from `231` to `194` lines.
- Current `public/simulation-static.js` is `514` lines. Total code lines are `186785` and nonblank lines are `171051` after the code move.
- Current self-audit after the code move: full directory about `252.36 MB`, without `.git` about `92.02 MB`, without `.git` and dependencies about `62.83 MB`, tracked files about `17.91 MB` / `611` files; code scope `368` files.
- Verified after the frontend simulation display split: `node --check public\simulation-static.js`; `node --check scripts\verify_simulation_p2.mjs`; `node scripts\verify_simulation_p2.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check -- public\index.html public\simulation-static.js scripts\verify_simulation_p2.mjs`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Data Config Request Builder Split

- Thirty-third frontend split target chosen from pure platform data-source config parsing, alias normalization, compacting, and request-body mapping in `public/index.html`.
- Extended `public/auto-fetch-static.js` with `parseDataConfigValue`, `normalizeDataConfigForForm`, `compactDataConfigBody`, and `buildDataConfigRequestBody`.
- `public/index.html` now keeps only config form state, save/test actions, request execution, and runtime validation for this flow; config persistence, endpoint testing, OTA fetch calls, system-config storage, toast validation, and credential handling remain unchanged.
- Updated `scripts/verify_platform_data_source_contract.mjs` so the platform data-source contract requires the data-config normalizer and request builder from `public/auto-fetch-static.js`, and preserves `ctrip-cookie-api` plus `meituan-ads` request mappings.
- `public/index.html` decreased from `40319` lines to `40113` lines; split-map frontend function-level blocks decreased from `1110` to `1107`; the config-domain span decreased from `897` to `687` lines.
- Current `public/auto-fetch-static.js` is `284` lines. Total code lines are `186806` and nonblank lines are `171075` after the code move.
- Current self-audit after the code move: full directory about `252.94 MB`, without `.git` about `92.02 MB`, without `.git` and dependencies about `62.83 MB`, tracked files about `17.91 MB` / `611` files; code scope `368` files.
- Verified after the data-config request builder split: `node --check public\auto-fetch-static.js`; `node --check scripts\verify_platform_data_source_contract.mjs`; `node scripts\verify_platform_data_source_contract.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Meituan Capture Defaults Split

- Thirty-fourth frontend split target chosen from Meituan default form objects and browser-capture section normalization in `public/index.html`.
- Extended `public/meituan-static.js` with `defaultMeituanAdsUrl`, `createMeituanRankingForm`, `createMeituanTrafficForm`, `createMeituanOrderForm`, `createMeituanAdsForm`, `createMeituanBrowserCaptureForm`, and `normalizeMeituanCaptureSections`.
- `public/index.html` now keeps only `ref(create...)` bindings, capture command assembly, Profile login payload, tab switching, and request execution for this flow; Meituan capture endpoints, captured-payload saving, Profile login polling, data-source binding, and OTA storage paths remain unchanged.
- Updated `scripts/verify_p0_learning_contract.mjs` so the Meituan form defaults and section normalizer are required from `public/meituan-static.js`, while the entry file must explicitly read them through `requireMeituanStatic()`.
- `public/index.html` decreased from `40113` lines to `40049` lines; split-map frontend function-level blocks decreased from `1107` to `1106`; the Meituan-domain span decreased from `1371` to `1364` lines.
- Current `public/meituan-static.js` is `264` lines. Total code lines are `186842` and nonblank lines are `171104` after the code move.
- Current self-audit after the code move: full directory about `253.52 MB`, without `.git` about `92.03 MB`, without `.git` and dependencies about `62.84 MB`, tracked files about `17.92 MB` / `611` files; code scope `368` files.
- Verified after the Meituan capture defaults split: `node --check public\meituan-static.js`; `node --check scripts\verify_p0_learning_contract.mjs`; `npm.cmd run verify:p0-learning`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `git diff --check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Capture Defaults Split

- Thirty-fifth frontend split target chosen from Ctrip default capture form objects in `public/index.html`.
- Extended `public/ctrip-static.js` with `createCtripFetchForm`, `createCtripTrafficForm`, `createCtripAdsBrowserCaptureForm`, `createCtripOverviewForm`, `createCtripFlowOverviewForm`, `createCtripBrowserCaptureForm`, `createCtripCookieApiForm`, `createCtripEndpointEvidenceForm`, `createCtripCommentForm`, and `createCtripCommentBrowserCaptureForm`.
- `public/index.html` now keeps only `ref(create...)` bindings, capture runtime state, Profile/Cookie/API request execution, and UI display bindings for this flow; Ctrip capture endpoints, Cookie/Profile checks, endpoint evidence validation, aggregate-only review boundary, data-source binding, and OTA storage paths remain unchanged.
- Updated `scripts/verify_p0_learning_contract.mjs` so Ctrip form defaults are required from `public/ctrip-static.js`, while the entry file must explicitly read them through `requireCtripStatic()` and must not re-inline key form objects.
- `public/index.html` decreased from `40049` lines to `39986` lines; split-map frontend function-level blocks stayed at `1106`; the Ctrip-domain span decreased from `3802` to `3739` lines.
- Current self-audit after the code move: full directory about `254.1 MB`, without `.git` about `92.04 MB`, without `.git` and dependencies about `62.85 MB`, tracked files about `17.92 MB` / `611` files; code scope `368` files, `186886` total lines, and `171148` nonblank lines.
- Verified after the Ctrip capture defaults split: `node --check public\ctrip-static.js`; `node --check scripts\verify_p0_learning_contract.mjs`; `npm.cmd run verify:p0-learning`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:check`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Simulation Default Forms Split

- Thirty-sixth frontend split target chosen from investment, expansion collaboration, transfer pricing, and transfer timing default form objects in `public/index.html`.
- Extended `public/simulation-static.js` with `createBenchmarkModelForm`, `createCollaborationProject`, `createTransferPricingForm`, and `createTransferTimingForm`.
- `public/index.html` now keeps only `ref(create...)` bindings, calculated results, history reuse, request execution, and runtime validation for those flows; simulation, collaboration, transfer pricing, transfer timing, history loading, archive/reuse, and OTA data paths remain unchanged.
- Updated `scripts/verify_simulation_p2.mjs` so the default form factories are required from `public/simulation-static.js`, while the entry file must explicitly read them through `requireSimulationStatic()` and must not re-inline key form objects.
- Updated `scripts/project_split_map.mjs` so Vue `computed(...)` declarations count as frontend block boundaries; this removes the stale false-positive where the 4-line `printFeasibilityReport` appeared as a 269-line block.
- `public/index.html` decreased from `39986` lines to `39930` lines. After the split-map boundary fix, frontend function-level blocks report as `1470`, and the real largest frontend block is `runOtaDiagnosisHotelFetch` at `265` lines.
- Current self-audit after the code move: full directory about `254.69 MB`, without `.git` about `92.04 MB`, without `.git` and dependencies about `62.85 MB`, tracked files about `17.93 MB` / `611` files; code scope `368` files, `186917` total lines, and `171179` nonblank lines.
- Verified after the simulation default forms split: `node --check public\simulation-static.js`; `node --check scripts\verify_simulation_p2.mjs`; `node scripts\verify_simulation_p2.mjs`; `node scripts\verify_expansion_p2.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `node --check scripts\project_split_map.mjs`; `npm.cmd run self:split-map`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Test ID Helper Split

- Thirty-seventh frontend split target chosen from pure navigation/page-control stable test-id helper logic in `public/index.html`.
- Added `public/testid-static.js` with stable segment normalization, menu/page test-id generation, page-control `data-testid` assignment, active-page root detection, mutation observer wiring, and refresh scheduling.
- `public/index.html` now keeps only system static config reads and `createPageTestIdController(...)` wiring, while preserving the original `pageTestId`, `menuTestId`, `startPageControlTestIdObserver`, `stopPageControlTestIdObserver`, `assignPageControlTestIds`, and `scheduleTestIdRefresh` setup names for template/watch compatibility.
- Updated `scripts/verify_e2e_contracts.mjs` so the e2e contract requires `testid-static.js` to be loaded and checks the extracted module for `assignPageControlTestIds` plus `normalizeTestIdSegment`.
- `public/index.html` decreased from `39930` lines to `39801` lines; split-map frontend function-level blocks decreased from `1470` to `1457`; the `general` domain span decreased from `8747` to `8617` lines.
- Current self-audit after staging the code move: full directory about `255.8 MB`, without `.git` about `92.05 MB`, without `.git` and dependencies about `62.86 MB`, tracked files about `17.93 MB` / `612` files; code scope `369` files, `186970` total lines, and `171230` nonblank lines.
- Verified after the frontend test-id helper split: `node --check public\testid-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Menu Visibility Helper Split

- Thirty-eighth frontend split target chosen from pure menu config-key resolution and visible-menu permission filtering in `public/index.html`.
- Extended `public/system-static.js` with `resolveMenuItems` and `filterVisibleMenuItems`.
- `public/index.html` now keeps current-language menu naming and Vue computed wiring only; menu definitions, permission fields, navigation click handling, and page-load behavior remain unchanged.
- Updated `scripts/verify_e2e_contracts.mjs` so the e2e contract requires `filterVisibleMenuItems(menuItems.value, user.value)` in the entry and requires the helper functions to stay in `public/system-static.js`.
- `public/index.html` decreased from `39801` lines to `39745` lines; split-map frontend function-level blocks decreased from `1457` to `1455`; the `general` domain span decreased from `8617` to `8561` lines.
- Current self-audit after the code move: full directory about `255.85 MB`, without `.git` about `92.05 MB`, without `.git` and dependencies about `62.86 MB`, tracked files about `17.93 MB` / `612` files; code scope `369` files, `186960` total lines, and `171227` nonblank lines.
- Verified after the frontend menu visibility split: `node --check public\system-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Hotel Platform Account Row Builder Split

- Thirty-ninth frontend split target chosen from pure hotel platform account row display logic in `public/index.html`.
- Extended `public/system-static.js` with `platformNextActionMeta`, `platformAccountStoreText`, and `buildHotelPlatformAccountRow`.
- `public/index.html` now keeps only the wrapper `buildHotelPlatformAccountRowStatic` plus explicit runtime helper injection; OTA capture execution, Profile/Cookie/API checks, data-source persistence, sync-log loading, and storage paths remain unchanged.
- Updated `scripts/verify_e2e_contracts.mjs` so the e2e contract requires the extracted hotel platform account row builder and prevents `platformNextActionMeta` / `platformAccountStoreText` from being re-inlined.
- Updated `scripts/verify_platform_account_guide_contract.mjs` so platform-account guide evidence reads both `public/system-static.js` and the entry action-routing block; backend action evidence now matches the current `configure_platform_profile` / `platform-sources` contract.
- Updated `tests/automation/ctrip_store_data_overview.test.mjs` so the login-timeout platform badge test reads the row builder from `public/system-static.js` and verifies the entry wrapper injects `platformSourceForHotel(...)`.
- `public/index.html` decreased from `39745` lines to `39607` lines; split-map frontend function-level blocks decreased from `1455` to `1453`; the `general` domain span decreased from `8561` to `8518` lines.
- Current `public/system-static.js` is about `690` lines. Total code lines are `187022` and nonblank lines are `171291` after the code move.
- Current self-audit after the code move: full directory about `256.44 MB`, without `.git` about `92.06 MB`, without `.git` and dependencies about `62.87 MB`, tracked files about `17.95 MB` / `612` files; code scope `369` files.
- Verified after the hotel platform account row split: `node --check public\system-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `node --check scripts\verify_platform_account_guide_contract.mjs`; `node --check tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:platform-account-guide`; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Strategy Display Builder Split

- Fortieth frontend split target chosen from pure strategy-result display computed logic in `public/index.html`.
- Extended `public/expansion-static-options.js` with `buildStrategyScoreCards`, `strategyFreshnessLabelForSnapshot`, `strategyAiSourceLabelForResult`, `strategyAiModelDisplayLabelForSnapshot`, `strategyPoiDataSourceLabelForSnapshot`, `strategyDataNoticeForSnapshot`, `buildStrategyDataSourceRows`, and `buildStrategyAiEmpowermentCards`.
- `public/index.html` keeps the original computed names and runtime state inputs only; strategy requests, record loading, history reuse, archiving, feasibility report generation, localStorage, and OTA data paths remain unchanged.
- Updated `scripts/verify_expansion_p2.mjs` so expansion P2 requires the strategy display builders from `public/expansion-static-options.js`, while the entry file must explicitly read them through `requireExpansionStaticOption(...)` and must not re-inline key long logic fragments.
- Updated `scripts/project_split_map.mjs` so the Vue setup top-level `return {` is treated as a block boundary. This removes the stale false-positive where `homeAiTraceRows` inherited the giant setup return span; the real largest frontend block remains `runOtaDiagnosisHotelFetch`, which was not moved in this round.
- `public/index.html` decreased from `39607` lines to `39513` lines; the split-map `ai` domain span decreased from `1918` to `1637` lines.
- Current `public/expansion-static-options.js` is about `588` lines. Total code lines are `187092` and nonblank lines are `171360` after the code move. Total code lines increased because the static builders plus contract guards add more explicit code than the entry file removes.
- Current self-audit after the code move: full directory about `257.05 MB`, without `.git` about `92.07 MB`, without `.git` and dependencies about `62.88 MB`, tracked files about `17.95 MB` / `612` files; code scope `369` files.
- Verified after the strategy display split: `node --check public\expansion-static-options.js`; `node --check scripts\verify_expansion_p2.mjs`; `node --check scripts\project_split_map.mjs`; `node scripts\verify_expansion_p2.mjs`; `node scripts\verify_strategy_location_ui_contract.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Home Operating Result Builder Split

- Forty-first frontend split target chosen from pure home operating-result card and causal-chain display computed logic in `public/index.html`.
- Extended `public/home-static.js` with `buildHomeOperatingResultCards` and `buildHomeCausalChainNodes`.
- `public/index.html` keeps the original `homeOperatingResultCards` / `homeCausalChainNodes` computed names and runtime input collection only; home trend loading, macro signals, competitor summary, quick entries, API requests, and OTA data paths remain unchanged.
- Preserved scope wording such as `OTA/经营日报样本口径，不替代全酒店总营收` and `优先展示采集字段，不用收入/间夜倒推`; missing fields remain visible instead of being hidden by fallback success states.
- Updated `scripts/verify_home_visual_hierarchy_contract.mjs` so the home visual contract requires the two builders from `public/home-static.js` and prevents `cardVisual` / `homeOperatingResultCards.value.find(...)` long logic from being re-inlined.
- `public/index.html` decreased from `39513` lines to `39432` lines; the split-map `general` domain span decreased from `8518` to `8444` lines.
- Current `public/home-static.js` is about `382` lines. Total code lines are `187155` and nonblank lines are `171422` after the code move. Total code lines increased because the static builders plus contract guards add more explicit code than the entry file removes.
- Current self-audit after the code move: full directory about `257.64 MB`, without `.git` about `92.07 MB`, without `.git` and dependencies about `62.88 MB`, tracked files about `17.96 MB` / `612` files; code scope `369` files.
- Verified after the home operating-result split: `node --check public\home-static.js`; `node --check scripts\verify_home_visual_hierarchy_contract.mjs`; `npm.cmd run verify:home-visual-hierarchy`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Platform Batch Health Builder Split

- Forty-second frontend split target chosen from pure platform batch-health row and summary-card display logic in `public/index.html`.
- Extended `public/data-health-static.js` with `platformBatchHealthBadgeClass`, `buildPlatformBatchHealthRows`, and `buildPlatformBatchHealthSummaryCards`.
- `public/index.html` keeps the original `platformBatchHealthRows` / `platformBatchHealthSummaryCards` computed names and runtime input collection only; platform data-source persistence/sync, competitor-summary loading, OTA capture, storage paths, and template markup remain unchanged.
- Missing and failed states remain explicit, including `待绑定`, `未采集`, `采集失败`, `待试采`, and `缺少最近采集证据`; no fallback success state was added.
- Updated `scripts/verify_platform_batch_health_contract.mjs` so the platform batch-health contract reads both `public/index.html` and `public/data-health-static.js`, and prevents `sourceMap`-based long logic from being re-inlined into the entry file.
- `public/index.html` decreased from `39432` lines to `39333` lines; split-map frontend function-level blocks decreased from `1453` to `1449`, and the `general` domain span decreased from `8444` to `8346` lines.
- Current self-audit after the code move: full directory about `258.23 MB`, without `.git` about `92.09 MB`, without `.git` and dependencies about `62.90 MB`, tracked files about `17.96 MB` / `612` files; code scope `369` files, `187204` total lines, and `171467` nonblank lines.
- Verified after the platform batch-health split: `node --check public\data-health-static.js`; `node --check scripts\verify_platform_batch_health_contract.mjs`; `npm.cmd run verify:platform-batch-health`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Cookie API Core Preset Split

- Forty-third frontend split target chosen from pure Ctrip Cookie/API core diagnosis endpoint preset data in `public/index.html`.
- Extended `public/ctrip-static.js` with `getCtripCookieApiCorePresetEndpoints` for business, traffic, ads, PSI, biztravel, user profile, IM, competitor, and loss-analysis sections.
- `public/index.html` keeps `getCtripCookieApiCorePresetJson()`, form-fill behavior, and runtime usage only; Cookie/API execution, Profile reuse, data-config persistence, diagnosis fetch, and storage paths remain unchanged.
- Updated `scripts/verify_ota_diagnosis_auto_fetch.mjs` so endpoint preset coverage is proven from `public/ctrip-static.js`, while the entry file must explicitly read `requireCtripStatic('getCtripCookieApiCorePresetEndpoints')` and must not re-inline the endpoint array.
- Updated `tests/automation/ctrip_endpoint_evidence_ui.test.mjs` so extracted endpoint-evidence defaults and Ctrip core preset endpoints are verified from `public/ctrip-static.js`; entry assertions still cover template binding and request payload.
- `public/index.html` decreased from `39333` lines to `39217` lines; split-map frontend function-level blocks decreased from `1449` to `1448`, and the `ctrip` domain span decreased from `3750` to `3634` lines.
- Current self-audit after the code move: full directory about `258.81 MB`, without `.git` about `92.09 MB`, without `.git` and dependencies about `62.90 MB`, tracked files about `17.97 MB` / `612` files; code scope `369` files, `187212` total lines, and `171474` nonblank lines.
- Verified after the Ctrip Cookie/API core preset split: `node --check public\ctrip-static.js`; `node --check scripts\verify_ota_diagnosis_auto_fetch.mjs`; `node --check tests\automation\ctrip_endpoint_evidence_ui.test.mjs`; `npm.cmd run verify:ota-diagnosis-auto-fetch`; `node --test tests\automation\ctrip_endpoint_evidence_ui.test.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Opening Overview Card Builder Split

- Forty-fourth frontend split target chosen from pure opening overview card display logic in `public/index.html`.
- Extended `public/operation-static.js` with `buildOpeningOverviewCards` for opening countdown, overall score, risk level, completion rates, high-risk count, overdue count, and AI suggestion progress cards.
- `public/index.html` keeps the original `openingOverviewCards` computed name and passes only `openingOverview.value`; opening project/task requests, batch updates, scoring, save/edit behavior, and storage paths remain unchanged.
- Updated `scripts/project_split_map.mjs` so Vue setup boundaries include `watch(...)`, lifecycle hooks, and top-level `ref(...)` declarations. This removes stale false-positive largest blocks where helpers absorbed later watchers or state declarations.
- Updated `scripts/verify_opening_batch_actions.mjs` so the opening batch/action contract also requires the extracted overview card builder and verifies one sample builder output from `public/operation-static.js`.
- `public/index.html` decreased from `39217` lines to `39129` lines; split-map frontend function-level blocks decreased from `1448` to `1446`, and the `general` domain span decreased from `7648` to `7562` lines.
- Current self-audit after the code move: full directory about `259.4 MB`, without `.git` about `92.1 MB`, without `.git` and dependencies about `62.91 MB`, tracked files about `17.97 MB` / `612` files; code scope `369` files, `187285` total lines, and `171542` nonblank lines.
- Verified after the opening overview split: `node --check public\operation-static.js`; `node --check scripts\verify_opening_batch_actions.mjs`; `npm.cmd run verify:opening-batch-actions`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Opening AI Output Builder Split

- Forty-fifth frontend split target chosen from pure opening AI output aggregation logic in `public/index.html`.
- Extended `public/operation-static.js` with `buildOpeningAiOutputResult`, including the AI task reason, priority score, progress read, overview-output, task-output, badge, and summary-card builders.
- `public/index.html` keeps the original `openingAiOutputResult` computed name and injects current task-state helpers only; opening project requests, task batch updates, score recalculation, save/edit behavior, and storage paths remain unchanged.
- Updated `scripts/verify_opening_batch_actions.mjs` so the opening panel contract also requires the extracted AI output builder and verifies one sample builder output from `public/operation-static.js`.
- `public/index.html` decreased from `39129` lines to `39043` lines; split-map frontend function-level blocks decreased from `1446` to `1444`, and the `ai` domain span decreased from `1569` to `1482` lines.
- Current self-audit after the code move: full directory about `259.98 MB`, without `.git` about `92.1 MB`, without `.git` and dependencies about `62.91 MB`, tracked files about `17.98 MB` / `612` files; code scope `369` files, `187362` total lines, and `171621` nonblank lines.
- Verified after the opening AI output split: `node --check public\operation-static.js`; `node --check scripts\verify_opening_batch_actions.mjs`; `npm.cmd run verify:opening-batch-actions`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Home Trend Chart Config Split

- Forty-sixth frontend split target chosen from pure home trend Chart.js configuration logic in `public/index.html`.
- Extended `public/home-static.js` with `buildHomeTrendChartConfig`, including trend-axis tick formatting, metric colors, dataset config, tooltip labels, and scale options.
- `public/index.html` keeps only browser runtime responsibilities in `renderHomeTrendChart()`: Chart.js availability checks, canvas lookup, visible-DOM checks, old chart destruction, new Chart instance creation, and retry scheduling.
- This split intentionally does not move home-trend data loading, range filters, metric selection, OTA/operation sample sources, canvas lifecycle, or refresh behavior.
- Updated `scripts/verify_home_visual_hierarchy_contract.mjs` so the home visual contract also requires the extracted trend chart config builder and verifies one sample Chart config output from `public/home-static.js`.
- `public/index.html` decreased from `39043` lines to `38984` lines; split-map frontend function-level blocks decreased from `1444` to `1443`, and the `general` domain span decreased from `7562` to `7503` lines.
- Current self-audit after the code move: full directory about `260.56 MB`, without `.git` about `92.11 MB`, without `.git` and dependencies about `62.92 MB`, tracked files about `17.98 MB` / `612` files; code scope `369` files, `187402` total lines, and `171659` nonblank lines.
- Verified after the home trend chart config split: `node --check public\home-static.js`; `node --check scripts\verify_home_visual_hierarchy_contract.mjs`; `npm.cmd run verify:home-visual-hierarchy`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Market Evaluation Risk Builder Split

- Forty-seventh frontend split target chosen from pure market-evaluation AI risk-suggestion display logic in `public/index.html`.
- Extended `public/expansion-static-options.js` with `buildMarketEvaluationAiRiskSuggestions`, including risk severity normalization and inferred evidence, impact, validation, owner, and deadline copy.
- `public/index.html` keeps the original `marketEvaluationAiRiskSuggestions` computed name and passes only `marketEvaluationResult` plus `marketEvaluationForm`; market-evaluation requests, scoring, record history, save/display/edit behavior, OTA data paths, and template markup remain unchanged.
- Updated `scripts/verify_expansion_p2.mjs` so expansion P2 requires the extracted risk builder, prevents the risk inference helpers from being re-inlined into `public/index.html`, and validates one sample output in a VM context.
- Added the `verify:expansion-p2` npm script alias for the existing expansion P2 verifier.
- `public/index.html` decreased from `38984` lines to `38887` lines; split-map frontend function-level blocks decreased from `1443` to `1441`; the real largest frontend block remains `runOtaDiagnosisHotelFetch` at `265` lines.
- Current self-audit after the code move: full directory about `261.14 MB`, without `.git` about `92.12 MB`, without `.git` and dependencies about `62.93 MB`, tracked files about `17.99 MB` / `612` files; code scope `369` files, `187470` total lines, and `171724` nonblank lines.
- Verified after the market-evaluation risk builder split: `node --check public\expansion-static-options.js`; `node --check scripts\verify_expansion_p2.mjs`; `node scripts\verify_expansion_p2.mjs`; `npm.cmd run verify:expansion-p2`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Transfer Timing Data Check Split

- Forty-eighth frontend split target chosen from pure transfer-timing data-quality display logic in `public/index.html`.
- Extended `public/simulation-static.js` with `buildTransferTimingDataCheck`, covering data gaps, metric conflicts, suspected collection anomalies, and normal transfer-timing data status.
- `public/index.html` keeps the original `transferTimingDataCheck` computed name and passes only `transferTimingForm`; transfer-timing requests, result save/display, record history, reuse/archive behavior, AI judgement, and OTA raw data paths remain unchanged.
- Updated `scripts/verify_simulation_p2.mjs` so Simulation P2 requires the extracted transfer-timing data-check builder, prevents the long inline metric-check block from returning to `public/index.html`, and validates normal, gap, and suspected collection anomaly samples in a VM context.
- Added the `verify:simulation-p2` npm script alias for the existing simulation P2 verifier.
- `public/index.html` decreased from `38887` lines to `38797` lines; the split-map `transfer` domain span decreased from `364` to `274` lines; the real largest frontend block remains `runOtaDiagnosisHotelFetch` at `265` lines.
- Current self-audit after the code move: full directory about `261.74 MB`, without `.git` about `92.12 MB`, without `.git` and dependencies about `62.93 MB`, tracked files about `18 MB` / `612` files; code scope `369` files, `187515` total lines, and `171766` nonblank lines.
- Verified after the transfer timing data-check split: `node --check public\simulation-static.js`; `node --check scripts\verify_simulation_p2.mjs`; `npm.cmd run verify:simulation-p2`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Global Notification Builder Split

- Forty-ninth frontend split target chosen from pure global-notification row aggregation logic in `public/index.html`.
- Extended `public/notification-static.js` with `buildGlobalNotifications`, covering backend notification rows, active OTA auto-fetch status, last auto-fetch result, recent auto-fetch runs, and data-health work orders.
- `public/index.html` keeps the original `globalNotifications` computed name and passes only current runtime state snapshots; notification loading, missing-table handling, read-state writes, hidden-state filtering, poll timers, and OTA capture state sources remain unchanged.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted notification builder, prevent the long inline aggregation loop from returning to `public/index.html`, and validate active auto-fetch, data-health targeting, and duplicate-id behavior in a VM context.
- `public/index.html` decreased from `38797` lines to `38732` lines; the split-map `general` domain span decreased from `7498` to `7433` lines; `globalNotifications` no longer appears in the largest frontend blocks.
- Current self-audit after the code move: full directory about `262.32 MB`, without `.git` about `92.13 MB`, without `.git` and dependencies about `62.94 MB`, tracked files about `18 MB` / `612` files; code scope `369` files, `187604` total lines, and `171849` nonblank lines.
- Verified after the global notification builder split: `node --check public\notification-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Data Config Default Form Split

- Fiftieth frontend split target chosen from pure data-config default form construction in `public/index.html`.
- Extended `public/system-static.js` with `getDefaultDataConfigForm`, covering common config fields, Ctrip/Meituan account fields, Cookie/API diagnostic fields, and ads config defaults.
- `public/index.html` keeps only the static read and existing calls; data-config modal behavior, per-type default overrides, save/test requests, OTA capture configuration, and storage paths remain unchanged.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted default-form builder, prevent the long default object from returning to `public/index.html`, and validate default OTA config values plus fresh `rank_types` arrays in a VM context.
- `public/index.html` decreased from `38732` lines to `38658` lines; frontend function-level blocks decreased from `1441` to `1440`; the split-map `config` domain span decreased from `607` to `532` lines.
- Current self-audit after the code move: full directory about `262.9 MB`, without `.git` about `92.13 MB`, without `.git` and dependencies about `62.94 MB`, tracked files about `18.01 MB` / `612` files; code scope `369` files, `187647` total lines, and `171891` nonblank lines.
- Verified after the data-config default form split: `node --check public\system-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Browser Capture Payload Split

- Fifty-first frontend split target chosen from pure Ctrip Profile browser-capture payload, section-normalization, and error-normalization logic in `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripBrowserCapturePayload`, `normalizeCtripBrowserCaptureSections`, and `normalizeCtripBrowserCaptureErrorResult`.
- `public/index.html` keeps `runCtripBrowserCapture()` responsible only for runtime state, active config resolution, `/online-data/capture-ctrip-browser` request execution, UI updates, and follow-up refreshes; Profile capture routing, data-source binding, storage, and data-health refresh behavior remain unchanged.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Ctrip browser-capture payload builder, prevent section/error normalization from being re-inlined, and validate request-body fields, default sections, and partial-capture error evidence in a VM context.
- `public/index.html` decreased from `38658` lines to `38632` lines; frontend function-level blocks decreased from `1440` to `1438`; `runCtripBrowserCapture` is now `105` lines in split-map.
- Current self-audit after the code move: full directory about `263.49 MB`, without `.git` about `92.14 MB`, without `.git` and dependencies about `62.95 MB`, tracked files about `18.02 MB` / `612` files; code scope `369` files, `187770` total lines, and `172014` nonblank lines.
- Verified after the Ctrip browser-capture payload split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend OTA Diagnosis Fetch Task Split

- Fifty-second frontend split target chosen from pure OTA-diagnosis pre-fetch task construction logic in `public/index.html`.
- Extended `public/ota-diagnosis-static.js` with `buildOtaDiagnosisFetchContext` and `buildOtaDiagnosisFetchTasks`, covering Ctrip business, Ctrip traffic, Ctrip Cookie API, Meituan rank, and Meituan traffic task construction.
- `public/index.html` keeps `runOtaDiagnosisHotelFetch()` responsible for runtime config reads, generic Cookie lookup, Ctrip Profile probing, task execution, failed-task evidence, and summary return; OTA diagnosis generation, capture endpoints, storage paths, failure toasts, and downstream AI diagnosis behavior remain unchanged.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted OTA diagnosis fetch builders, prevent task-push and Meituan rank-task loops from being re-inlined, and validate saved-config coverage plus core-preset request-source evidence in a VM context.
- `public/index.html` decreased from `38632` lines to `38438` lines; frontend function-level blocks decreased from `1438` to `1435`; the split-map `ota` domain span decreased from `679` to `515` lines; `runOtaDiagnosisHotelFetch` decreased from `265` to `100` lines.
- Current self-audit after the code move: full directory about `264.08 MB`, without `.git` about `92.15 MB`, without `.git` and dependencies about `62.96 MB`, tracked files about `18.03 MB` / `612` files; code scope `369` files, `187930` total lines, and `172175` nonblank lines.
- Verified after the OTA diagnosis fetch task split: `node --check public\ota-diagnosis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Meituan Batch Fetch Builder Split

- Fifty-third frontend split target chosen from pure Meituan batch rank-fetch task, result-entry, and display-model payload construction logic in `public/index.html`.
- Extended `public/meituan-static.js` with `buildMeituanBatchFetchTasks`, `buildMeituanBatchFetchResultEntry`, and `buildMeituanDisplayModelPayload`.
- `public/index.html` keeps `fetchMeituanData()` responsible for hotel/auth validation, current config application, `/online-data/fetch-meituan` request execution, saved-count updates, display-model request execution, history refresh, and UI status updates; Meituan capture endpoints, storage behavior, display-model endpoint, and failure toasts remain unchanged.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Meituan batch fetch builders, prevent rank lists/labels and display payload from being re-inlined, and validate custom-date tasks, four-rank coverage, success/failure result entries, and display-model payloads in a VM context.
- `public/index.html` decreased from `38438` lines to `38381` lines; the split-map `meituan` domain span decreased from `1257` to `1197` lines; `fetchMeituanData` decreased from `164` to `104` lines.
- Current self-audit after the code move: full directory about `264.66 MB`, without `.git` about `92.16 MB`, without `.git` and dependencies about `62.97 MB`, tracked files about `18.04 MB` / `612` files; code scope `369` files, `188055` total lines, and `172304` nonblank lines.
- Verified after the Meituan batch fetch builder split: `node --check public\meituan-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and the still-large `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend AI Analysis Static Builder Split

- Fifty-fourth frontend split target chosen from pure Ctrip OTA AI analysis display and request-state construction helpers in `public/index.html`.
- Added `public/ai-analysis-static.js` for AI analysis status and priority display text/classes, list and problem-hotel normalization, sensitive error masking, chunking, captured OTA hotel payloads, group summaries, merged reports, report copy HTML, fallback summary reports, progress state, batch result rows, summary request bodies, and history records.
- `public/index.html` now loads `ai-analysis-static.js` and uses `requireAiAnalysisStatic(...)` with explicit missing-key errors. It keeps runtime responsibilities only: hotel/date validation, `/agent/analyze-captured-ota-data` calls, split retry, `/agent/summarize-captured-ota-analysis` calls, UI state updates, and result copy behavior.
- This split did not change OTA capture, persistence, AI endpoint contracts, date filters, missing/failed-state display, or Ctrip OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted AI analysis builders, prevent key long helper bodies from being re-inlined, and validate captured payloads, batch state, summary request bodies, fallback failure masking, display labels, and history records in a VM context.
- `public/index.html` decreased from `38381` lines to `38101` lines; frontend function-level blocks decreased from `1435` to `1407`; the split-map `ai` domain span decreased from `1516` to `1257` lines; `startAiAnalysis` is now `145` lines.
- Current self-audit after the code move and local artifact cleanup: full directory about `265.24 MB`, without `.git` about `92.16 MB`, without `.git` and dependencies about `62.97 MB`, tracked files about `18.03 MB` / `612` files; code scope `369` files, `187921` total lines, and `172193` nonblank lines; default cleanup reclaim is `0 MB`.
- Verified after the AI analysis split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:clean`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Profile Recheck State Builder Split

- Fifty-fifth frontend split target chosen from pure Ctrip Profile mismatched-field recheck state and message construction inside `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripProfileRecheckInitialState`, `buildCtripProfileRecheckCaptureRefreshState`, `buildCtripProfileRecheckSuccessResult`, `buildCtripProfileRecheckErrorResult`, and `buildCtripProfileRecheckInterruptedState`.
- `public/index.html` keeps `recheckCtripProfileMismatchedFields()` responsible for runtime execution only: target field selection, browser recapture, `/online-data/recheck-ctrip-profile-mismatched-fields`, `applyCtripProfileFieldResponse`, toasts, loading flags, and timer cleanup.
- This split did not change Profile browser capture, field persistence, sample refresh, second-confirmation status, unresolved-parser status, or missing/failed-state visibility.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Profile recheck builders, prevent result/interruption state bodies from being re-inlined, and validate warning, partial, error, and interruption states in a VM context.
- `public/index.html` decreased from `38101` lines to `38088` lines; `public/ctrip-static.js` is now about `710` lines; `recheckCtripProfileMismatchedFields` decreased from `126` to `108` lines; the split-map `ctrip` domain span decreased from `3566` to `3548` lines.
- Current self-audit after the code move: full directory about `265.83 MB`, without `.git` about `92.17 MB`, without `.git` and dependencies about `62.98 MB`, tracked files about `18.06 MB` / `613` files; code scope `370` files, `188514` total lines, and `172749` nonblank lines.
- Verified after the Profile recheck state split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Fetch Builder Split

- Fifty-sixth frontend split target chosen from pure Ctrip business-data fetch request, response, and evidence-display construction inside `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripFetchDateRange`, `buildCtripFetchRequestBody`, `selectCtripFetchResponsePayload`, `buildCtripFetchMeta`, and `buildCtripFetchRawFailureResult`.
- `public/index.html` keeps `fetchCtripData()` responsible for runtime execution only: login/hotel/config/auth validation, `/online-data/fetch-ctrip`, display-model update, latest meta refresh, AI hotel-list update, history refresh, and visible raw-failure state.
- This split did not change the Ctrip fetch endpoint, auto-save behavior, storage path, display rows, latest snapshot refresh, online-history refresh, or missing/failure-state visibility.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Ctrip fetch builders, prevent date/request/raw-failure bodies from being re-inlined, and validate default date, explicit date, request fields, multi-date payload, latest meta, and raw failure evidence in a VM context.
- `public/index.html` decreased from `38088` lines to `38070` lines; `public/ctrip-static.js` is now about `791` lines; `fetchCtripData` decreased from `126` to `103` lines; the split-map `ctrip` domain span decreased from `3548` to `3525` lines.
- Current self-audit after the code move: full directory about `266.42 MB`, without `.git` about `92.18 MB`, without `.git` and dependencies about `62.99 MB`, tracked files about `18.07 MB` / `613` files; code scope `370` files, `188667` total lines, and `172897` nonblank lines.
- Verified after the Ctrip fetch builder split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Latest Snapshot Model Split

- Fifty-seventh frontend split target chosen from pure latest Ctrip snapshot payload slicing inside `public/index.html`.
- Extended `public/ctrip-static.js` with `buildLatestCtripSnapshotModel`, covering `metadata`, rank rows/display hotels, traffic rows/display rows, review result, and `onlineResult` construction.
- `public/index.html` keeps `applyLatestCtripSnapshot()` responsible for runtime state writes only: `ctripLatestMeta`, Ctrip rank display rows, traffic display rows, review aggregate result, and `onlineDataResult`.
- This split did not change the latest snapshot endpoint, stored data scope, history loading, Ctrip OTA channel display scope, or missing/failed-state visibility.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted latest snapshot model builder, prevent latest row slicing from being re-inlined, and validate rank, traffic, review, online result, and empty snapshot behavior in a VM context.
- `public/index.html` decreased from `38070` lines to `38058` lines; `public/ctrip-static.js` is now `836` lines; the split-map `ctrip` domain span decreased from `3525` to `3512` lines.
- Current self-audit after the code move: full directory about `267.01 MB`, without `.git` about `92.19 MB`, without `.git` and dependencies about `63 MB`, tracked files about `18.08 MB` / `613` files; code scope `370` files, `188752` total lines, and `172980` nonblank lines.
- Verified after the latest snapshot model split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Traffic Builder Split

- Fifty-eighth frontend split target chosen from pure Ctrip traffic fetch request-body and success response model construction inside `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripTrafficFetchRequestBody` and `buildCtripTrafficResponseModel`.
- `public/index.html` keeps `fetchCtripTrafficData()` responsible for runtime execution only: hotel/config/Cookie/date validation, `/online-data/ctrip/traffic` request execution, display-row writes, history refresh, and toast status.
- This split did not change the Ctrip traffic endpoint, storage behavior, display fields, failure handling, latest-snapshot fallback, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted traffic builders, prevent traffic request/response builders from being re-inlined, and validate URL trimming, empty hotel id, traffic rows, display rows, raw response, and derived analysis in a VM context.
- `public/index.html` decreased from `38058` lines to `38038` lines; `public/ctrip-static.js` is now `890` lines; the split-map `ctrip` domain span decreased from `3512` to `3490` lines.
- Current self-audit after the code move and local runtime cleanup: full directory about `267.6 MB`, without `.git` about `92.2 MB`, without `.git` and dependencies about `63.01 MB`, tracked files about `18.09 MB` / `613` files; code scope `370` files, `188850` total lines, and `173076` nonblank lines. `self:clean` removed about `0.03 MB` from `runtime`; current default reclaim is `0 MB`.
- Verified after the traffic builder split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:clean:dry-run`; `npm.cmd run self:clean`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Overview Request Builder Split

- Fifty-ninth frontend split target chosen from pure Ctrip today-overview and flow-overview request-body construction inside `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripOverviewFetchRequestBody`, shared by `fetchCtripOverviewData()` and `fetchCtripFlowOverviewData()`.
- `public/index.html` keeps the two overview fetch functions responsible for runtime validation and execution only: hotel/config/Cookie/Request URL checks, `/online-data/fetch-ctrip-overview` request execution, result writes, latest snapshot refresh, history refresh, and toast status.
- This split did not change the Ctrip overview endpoint, storage behavior, display fields, failure handling, method fallback semantics, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted overview request builder, prevent request body and method fallback logic from being re-inlined, and validate form-method precedence plus GET default behavior in a VM context.
- `public/index.html` decreased from `38038` lines to `38037` lines; the split-map `ctrip` domain span decreased from `3490` to `3488` lines; `fetchCtripOverviewData` is now `71` lines and `fetchCtripFlowOverviewData` is now `72` lines.
- Current self-audit after the code move and local runtime cleanup: full directory about `268.19 MB`, without `.git` about `92.21 MB`, without `.git` and dependencies about `63.02 MB`, tracked files about `18.09 MB` / `613` files; code scope `370` files, `188925` total lines, and `173150` nonblank lines. `self:clean` removed about `0.04 MB` from `runtime`; current default reclaim is `0 MB`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:clean`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Ads Builder Split And Meituan Summary Load Guard

- Sixtieth frontend split target chosen from pure Ctrip ads effect-report URL/helper/request-body construction inside `public/index.html`.
- Extended `public/ctrip-static.js` with `defaultCtripAdsEffectReportUrl`, `ctripAdsApiUrlHint`, `isCtripAdsApiUrl`, `normalizeCtripAdsApiType`, and `buildCtripAdsFetchRequestBody`.
- `public/index.html` keeps `fetchCtripAdsData()` responsible for runtime validation and execution only: hotel/config/Cookie/URL/date checks, `/online-data/fetch-ctrip-ads` request execution, result writes, latest snapshot refresh, history refresh, and toast status.
- This split did not change the Ctrip ads endpoint, storage behavior, display fields, failure handling, or the effect-report-only collection scope.
- The same save point also preserves the current Meituan summary loading guard in `public/index.html`: request sequencing for stale competitor summaries, optional `include_by_hotel`, deferred ranking-page summary refresh, and single-flight Meituan config-list loading.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Ctrip ads URL guard and request builder, prevent ads URL/request bodies from being re-inlined, and validate effect-report defaults in a VM context.
- Updated `tests/automation/manual_minimum_credential_ui.test.mjs` so manual credential UI assertions match the current static-builder architecture and cover the Meituan summary loading guard.
- Current split-map in the mixed worktree: `public/index.html` is `38053` lines; frontend function-level blocks decreased from `1407` to `1405`; `ctrip` domain span decreased from `3488` to `3471`; `fetchCtripAdsData` is now `72` lines.
- Current self-audit in the mixed worktree: full directory about `268.79 MB`, without `.git` about `92.22 MB`, without `.git` and dependencies about `63.03 MB`, tracked files about `18.1 MB` / `613` files; code scope `370` files, `189043` total lines, and `173268` nonblank lines; default reclaim is `0 MB`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `node --check tests\automation\manual_minimum_credential_ui.test.mjs`; `npm.cmd run verify:e2e-contracts`; `node --test tests\automation\manual_minimum_credential_ui.test.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip Cookie API Request Builder Split

- Sixty-first frontend split target chosen from pure Ctrip Cookie API request-body construction inside `runCtripCookieApiCapture()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripCookieApiFetchRequestBody`.
- `public/index.html` keeps `runCtripCookieApiCapture()` responsible for runtime execution only: target hotel validation, Request URL / endpoints JSON validation, active config and Profile resolution, `/online-data/fetch-ctrip-cookie-api` request execution, result writes, history refresh, and toast status.
- This split did not change the Ctrip Cookie API endpoint, auto-save behavior, storage behavior, UI display path, Profile binding path, or missing/failed-state visibility.
- The current save point also carries forward the Meituan summary loading guard refinement: `scheduleMeituanRankingSummaryRefresh`, config-detail single-flight loading, config-list single-flight reuse, deferred Meituan ranking-page loads, and matching automation assertions.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Ctrip Cookie API builder, prevent request-body fields from being re-inlined, and validate method normalization plus payload trimming in a VM context.
- Current split-map: `public/index.html` is `38104` lines; frontend function-level blocks are `1409`; `ctrip` domain span is `3472`; `meituan` domain span is `1246`; `runCtripCookieApiCapture` is `85` lines.
- Current self-audit after local runtime cleanup: full directory about `269.4 MB`, without `.git` about `92.23 MB`, without `.git` and dependencies about `63.04 MB`, tracked files about `18.12 MB` / `613` files; code scope `370` files, `189178` total lines, and `173401` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:clean`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Ctrip AI Hotel Selection Builder Split

- Sixty-second frontend split target chosen from pure Ctrip OTA AI analysis hotel-list aggregation and selection filtering inside `updateAiAnalysisHotelList()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `buildCtripAiAnalysisHotelSelection`.
- `public/index.html` keeps `updateAiAnalysisHotelList()` responsible for Vue state writes only: reading current Ctrip hotel rows, writing `aiAnalysisHotelList`, and pruning `aiSelectedHotels`.
- This split did not change AI analysis endpoint calls, date validation, summary generation, history records, Ctrip OTA storage, or missing/failed-state visibility.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Ctrip AI hotel selection builder, prevent the aggregation details from being re-inlined, and validate same-hotel multi-rank metric merging plus invalid selected-key pruning in a VM context.
- Current split-map: `public/index.html` is `38043` lines; frontend function-level blocks are `1408`; `general` domain span is `7165`; `ai` domain span is `1275`; `startAiAnalysis` remains `145` lines.
- Current self-audit: full directory about `270 MB`, without `.git` about `92.23 MB`, without `.git` and dependencies about `63.04 MB`, tracked files about `18.12 MB` / `613` files; code scope `370` files, `189235` total lines, and `173460` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Captured OTA AI Run Plan Builder Split

- Sixty-third frontend split target chosen from pure captured OTA AI run-plan preparation inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `buildCapturedOtaAnalysisRunPlan`.
- `public/index.html` keeps `startAiAnalysis()` responsible for validation, request execution, retry handling, summary generation, history writes, and UI state only; hotels payload construction, model-aware grouping, progress initialization, and batch-result initialization now live in the static helper.
- This split did not change `/agent/analyze-captured-ota-data`, `/agent/summarize-captured-ota-analysis`, date validation, model selection, fallback summary behavior, history records, Ctrip OTA storage, or missing/failed-state visibility.
- The same save point preserves the Meituan target-hotel config sync guard: ranking-page hotel changes are applied through the hotelId watcher, config detail loading aborts stale target updates, and manual credential UI assertions cover the behavior.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted run-plan builder, prevent run-plan details from being re-inlined, and validate DeepSeek Pro group sizing, progress fields, and batch keys in a VM context.
- Current split-map: `public/index.html` is `38043` lines; frontend function-level blocks are `1408`; `ai` domain span is `1272`; `meituan` domain span is `1246`; `startAiAnalysis` is `144` lines.
- Current self-audit: full directory about `271.19 MB`, without `.git` about `92.24 MB`, without `.git` and dependencies about `63.05 MB`, tracked files about `18.13 MB` / `613` files; code scope `370` files, `189313` total lines, and `173537` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `node --check tests\automation\manual_minimum_credential_ui.test.mjs`; `npm.cmd run verify:e2e-contracts`; `node --test tests\automation\manual_minimum_credential_ui.test.mjs`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend AI Report Sanitizer Split

- Sixty-fourth frontend split target chosen from pure AI report HTML sanitizing and HTML-to-text conversion helpers in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `sanitizeAiReportHtml` and `aiReportHtmlToText`.
- `public/index.html` now reads those helpers through `requireAiAnalysisStatic(...)`; Meituan AI report display, copy, and history replay keep the same runtime entry points and state writes.
- This split did not change AI analysis requests, summary generation, date validation, model selection, history records, OTA storage paths, or missing/failed-state visibility.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted sanitizer/text converter, require the static exports, and prevent the local helper implementations from being re-inlined into `public/index.html`.
- Current split-map: `public/index.html` decreased from `38043` lines to `38009` lines; frontend function-level blocks decreased from `1408` to `1406`; `ai` domain span decreased from `1272` to `1238` lines; `startAiAnalysis` remains `144` lines.
- Current self-audit: full directory about `271.76 MB`, without `.git` about `92.25 MB`, without `.git` and dependencies about `63.06 MB`, tracked files about `18.13 MB` / `613` files; code scope `370` files, `189325` total lines, and `173549` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Meituan AI Analysis Builder Split

- Sixty-fifth frontend split target chosen from pure Meituan AI analysis data shaping inside `public/index.html`.
- Extended `public/ai-analysis-static.js` with `getMeituanAiAnalysisHotelKey`, `buildMeituanAiAnalysisHotelList`, `resolveMeituanAiSelectedData`, `buildMeituanAiAnalysisRequestBody`, and `buildMeituanAiAnalysisHistoryRecord`.
- `public/index.html` keeps Meituan AI analysis runtime responsibilities only: selected-hotel validation, `/online-data/ai-analysis` request execution, toast state, result writes, history trimming, view, and copy behavior.
- This split did not change AI endpoints, OTA capture or storage, model selection, missing/failed-state display, or OTA channel scope.
- The same save point preserves the current Meituan data-source matching guard: config-list loading state is visible before the “not configured” warning, and matching falls back from system hotel id to normalized hotel/config names.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Meituan AI builders, prevent key/request/history details from being re-inlined, and validate Meituan hotel list, selected data, request body, and history records in a VM context.
- Updated `tests/automation/manual_minimum_credential_ui.test.mjs` to cover Meituan config loading state, normalized hotel-name matching, and the delayed unconfigured warning boundary.
- Current split-map: `public/index.html` is `37997` lines; frontend function-level blocks are `1407`; `ai` domain span is `1242`; `meituan` domain span is `1221`; `startAiAnalysis` remains `144` lines.
- Current self-audit after local runtime cleanup: full directory about `272.36 MB`, without `.git` about `92.25 MB`, without `.git` and dependencies about `63.06 MB`, tracked files about `18.14 MB` / `613` files; code scope `370` files, `189427` total lines, and `173646` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `node --check tests\automation\manual_minimum_credential_ui.test.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `node --test tests\automation\manual_minimum_credential_ui.test.mjs`; `git diff --check`; `npm.cmd run self:clean`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Meituan Batch Fetch Input Validation Split

- Sixty-sixth frontend split target chosen from pure Meituan batch ranking input validation inside `fetchMeituanData()` in `public/index.html`.
- Extended `public/meituan-static.js` with `validateMeituanBatchFetchInput`.
- `public/index.html` keeps runtime responsibilities only: selected hotel guard, configured data source guard, `applyMeituanHotelConfig()`, `/online-data/fetch-meituan` requests, display-model request, UI state writes, history refresh, and AI hotel-list refresh.
- This split did not change Meituan fetch endpoints, persistence, display-model payloads, history refresh, AI analysis list updates, or OTA channel scope.
- The extracted validator keeps missing states explicit: missing platform authorization, missing partner/poi identifiers, missing date ranges, and missing custom date bounds return visible messages and severity levels instead of defaulting silently.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted validator, prevent Meituan batch input validation from being re-inlined, and validate missing-cookie, missing-resource, missing-custom-date, and valid input samples in a VM context.
- Current split-map: `public/index.html` decreased from `37997` lines to `37982` lines; `meituan` domain span decreased from `1221` to `1205`; `fetchMeituanData` decreased from `104` to `88` lines.
- Current self-audit: full directory about `272.96 MB`, without `.git` about `92.26 MB`, without `.git` and dependencies about `63.07 MB`, tracked files about `18.15 MB` / `613` files; code scope `370` files, `189502` total lines, and `173720` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\meituan-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `git diff --check`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Captured OTA AI Outcome Split

- Sixty-seventh frontend split target chosen from pure selected-hotel lookup and captured OTA AI group outcome shaping inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `resolveAiSelectedData` and `buildCapturedOtaGroupOutcome`.
- `public/index.html` keeps runtime responsibilities only: selected/date validation, `/agent/analyze-captured-ota-data` group calls, retry handling, `/agent/summarize-captured-ota-analysis` calls, UI state writes, and history trimming.
- This split did not change AI endpoints, model selection, summary generation, captured OTA storage, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted helpers, prevent selected-row lookup and success/failure group filtering from being re-inlined, and validate invalid selected-key removal plus failed-reason construction in a VM context.
- Current split-map: `public/index.html` decreased from `37982` lines to `37975` lines; `ai` domain span decreased from `1242` to `1235`; `startAiAnalysis` decreased from `144` to `135` lines.
- Current self-audit: full directory about `273.56 MB`, without `.git` about `92.27 MB`, without `.git` and dependencies about `63.08 MB`, tracked files about `18.15 MB` / `613` files; code scope `370` files, `189551` total lines, and `173767` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `git diff --check`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Captured OTA AI Start Validation Split

- Sixty-eighth frontend split target chosen from pure captured OTA AI analysis start validation inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `validateCapturedOtaAiAnalysisStart` for selected-key, selected-data, date-presence, and date-order validation.
- `public/index.html` keeps runtime responsibilities only: selected-data resolution, validation toast, `/agent/analyze-captured-ota-data` group calls, retry handling, `/agent/summarize-captured-ota-analysis` calls, UI state writes, and history trimming.
- This split did not change AI endpoints, model selection, summary generation, captured OTA storage, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted validator, prevent start-validation branches from being re-inlined, and validate missing selected hotel, missing selected data, missing date range, invalid date order, and valid start samples in a VM context.
- Current split-map: `public/index.html` decreased from `37975` lines to `37968` lines; `ai` domain span decreased from `1235` to `1228`; `startAiAnalysis` decreased from `135` to `127` lines.
- Current self-audit: full directory about `274.16 MB`, without `.git` about `92.28 MB`, without `.git` and dependencies about `63.09 MB`, tracked files about `18.16 MB` / `613` files; code scope `370` files, `189617` total lines, and `173834` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `git diff --check`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-10 Progress: Frontend Captured OTA AI Completion Split

- Sixty-ninth frontend split target chosen from pure captured OTA AI completion state construction inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `buildCapturedOtaAnalysisCompletion` for report HTML generation and capped history construction.
- `public/index.html` keeps runtime responsibilities only: selected-data resolution, validation toast, `/agent/analyze-captured-ota-data` group calls, retry handling, `/agent/summarize-captured-ota-analysis` calls, and Vue state writes.
- This split did not change AI endpoints, model selection, summary generation, captured OTA storage, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted completion builder, prevent history unshift and trimming from being re-inlined, and validate report HTML, history summary, and history cap behavior in a VM context.
- Current split-map: `public/index.html` decreased from `37968` lines to `37965` lines; `ai` domain span decreased from `1228` to `1225`; `startAiAnalysis` decreased from `127` to `124` lines.
- Current self-audit: full directory about `274.77 MB`, without `.git` about `92.28 MB`, without `.git` and dependencies about `63.09 MB`, tracked files about `18.17 MB` / `613` files; code scope `370` files, `189669` total lines, and `173885` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `git diff --check`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Captured OTA AI Summary Response Split

- Seventieth frontend split target chosen from pure captured OTA AI summary-response handling inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `buildCapturedOtaSummaryResponseResult` for successful summary responses, failed summary responses, and network-error fallback reports.
- `public/index.html` keeps the `/agent/summarize-captured-ota-analysis` request in place and only delegates response-to-report conversion before writing `aiAnalysisCapturedReport` and `aiAnalysisProcess`.
- This split did not change AI endpoints, model selection, retry behavior, captured OTA storage, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted summary-response builder, prevent response success/fallback branches from being re-inlined, and validate success, fallback, and sensitive-error masking samples in a VM context.
- Current split-map: `public/index.html` decreased from `37965` lines to `37962` lines; `ai` domain span decreased from `1225` to `1222`; `startAiAnalysis` decreased from `124` to `122` lines.
- Current self-audit: full directory about `275.37 MB`, without `.git` about `92.29 MB`, without `.git` and dependencies about `63.10 MB`, tracked files about `18.18 MB` / `613` files; code scope `370` files, `189733` total lines, and `173948` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `git diff --check`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Captured OTA AI Summary Context Split

- Seventy-first frontend split target chosen from repeated captured OTA AI summary context construction inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `buildCapturedOtaSummaryContext` for selected-hotel count, completion counts, group count, success groups, and failed groups.
- `public/index.html` now reuses `summaryContext` for the summary request body, summary-response fallback handling, and completion history construction.
- This split did not change AI endpoints, model selection, retry behavior, captured OTA storage, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted summary-context builder, prevent selected/group/completed count context from being re-inlined, and validate numeric normalization plus success/failed group samples in a VM context.
- Current split-map: `public/index.html` decreased from `37962` lines to `37956` lines; `ai` domain span decreased from `1222` to `1216`; `startAiAnalysis` decreased from `122` to `115` lines.
- Current self-audit: full directory about `275.97 MB`, without `.git` about `92.30 MB`, without `.git` and dependencies about `63.11 MB`, tracked files about `18.18 MB` / `613` files; code scope `370` files, `189766` total lines, and `173980` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `git diff --check`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Captured OTA AI Group State Split

- Seventy-second frontend split target chosen from captured OTA AI group state and progress count updates inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `applyCapturedOtaGroupRunState` for success group state, failure group state, retry failure details, and completed/failed hotel count updates.
- `public/index.html` keeps runtime orchestration in place: selected/date validation, `/agent/analyze-captured-ota-data` group calls, retry handling, `/agent/summarize-captured-ota-analysis` calls, UI state writes, and history trimming.
- This split did not change AI endpoints, model selection, summary generation, captured OTA storage, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted group run-state helper, prevent result/count updates from being re-inlined, and validate success plus retry-failure count samples in a VM context.
- Current split-map: `public/index.html` decreased from `37956` lines to `37953` lines; `ai` domain span decreased from `1216` to `1213`; `startAiAnalysis` decreased from `115` to `111` lines.
- Local cleanup: `npm.cmd run self:clean:dry-run` found `runtime` with 37 generated files and `0.04 MB` estimated reclaim; `npm.cmd run self:clean` removed that one local artifact target.
- Current self-audit after cleanup: full directory about `276.58 MB`, without `.git` about `92.30 MB`, without `.git` and dependencies about `63.11 MB`, tracked files about `18.19 MB` / `613` files; code scope `370` files, `189824` total lines, and `174037` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node scripts\verify_frontend_display_boundary.mjs`; `npm.cmd run self:clean:dry-run`; `npm.cmd run self:clean`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Profile Recheck Run Context Split

- Seventy-third frontend split target chosen from Ctrip Profile recheck run-context construction inside `recheckCtripProfileMismatchedFields()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripProfileRecheckRunContext` for deduplicated recheck sections, browser recapture availability, initial state, start toast message, and the POST request options for `/online-data/recheck-ctrip-profile-mismatched-fields`.
- `public/index.html` keeps runtime orchestration in place: sample-loaded guard, target-field filtering, optional browser recapture, Profile recheck endpoint execution, sample refresh, toast state, and timer cleanup.
- This split did not change Ctrip Profile capture, field persistence, sample refresh, second-confirmation behavior, unresolved-parser status, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted run-context helper, prevent section deduplication, recapture guard, and request options from being re-inlined, and validate section deduplication plus default-section samples in a VM context.
- Current split-map: `public/index.html` decreased from `37953` lines to `37940` lines; frontend function-level blocks decreased from `1407` to `1406`; `ctrip` domain span decreased from `3472` to `3459`; `recheckCtripProfileMismatchedFields` decreased from `108` to `102` lines.
- Current self-audit: full directory about `277.18 MB`, without `.git` about `92.31 MB`, without `.git` and dependencies about `63.12 MB`, tracked files about `18.20 MB` / `613` files; code scope `370` files, `189878` total lines, and `174091` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Browser Capture Request Context Split

- Seventy-fourth frontend split target chosen from Ctrip browser capture target and request payload construction inside `runCtripBrowserCapture()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripBrowserCaptureTargetContext` and `buildCtripBrowserCaptureRequestContext` for target hotel fallback, missing-target result, Profile missing result, hotel id resolution, Cookie/DataDate fields, and capture payload construction.
- `public/index.html` keeps runtime orchestration in place: Ctrip config loading, secret hydration, active config application, `/online-data/capture-ctrip-browser` execution, Profile status writes, latest snapshot/history/data-health refresh, and error display.
- This split did not change the Ctrip browser capture endpoint, Profile login persistence, data-source binding, storage behavior, refresh behavior, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted target/request context helpers, prevent hotel id and Cookie payload logic from being re-inlined, and validate missing target, target fallback, missing Profile, payload fields, and sections samples in a VM context.
- Current split-map: `public/index.html` decreased from `37940` lines to `37936` lines; `ctrip` domain span decreased from `3459` to `3454`; `runCtripBrowserCapture` decreased from `105` to `100` lines.
- Current self-audit: full directory about `277.79 MB`, without `.git` about `92.32 MB`, without `.git` and dependencies about `63.13 MB`, tracked files about `18.20 MB` / `613` files; code scope `370` files, `189984` total lines, and `174197` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `node --check tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Fetch Request Context Split

- Seventy-fifth frontend split target chosen from regular Ctrip ranking fetch request construction inside `fetchCtripData()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `buildCtripFetchRequestContext` for credential trimming, nodeId request-resource identity, date range, request body, and debug metadata.
- `public/index.html` keeps runtime orchestration in place: login/target-hotel checks, Ctrip secret hydration, `/online-data/fetch-ctrip` execution, display model application, history refresh, latest snapshot refresh, and failure-state display.
- This split did not change `/online-data/fetch-ctrip`, storage behavior, display models, history refresh, latest snapshot refresh, missing/failed-state visibility, or OTA channel scope. nodeId remains only a request resource id, not an OTA hotelId fallback.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted request-context helper, prevent credentials, nodeId, date range, and request body construction from being re-inlined, and validate request fields, missing credential state, and debug metadata in a VM context.
- Updated `tests/automation/manual_minimum_credential_ui.test.mjs` so assertions follow the current module boundary for Ctrip request context and Meituan static validation messages.
- Current split-map: `public/index.html` decreased from `37936` lines to `37928` lines; `ctrip` domain span decreased from `3454` to `3447`; `fetchCtripData` decreased from `103` to `96` lines.
- Current self-audit: full directory about `278.42 MB`, without `.git` about `92.33 MB`, without `.git` and dependencies about `63.14 MB`, tracked files about `18.21 MB` / `613` files; code scope `370` files, `190046` total lines, and `174259` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `node --check tests\automation\manual_minimum_credential_ui.test.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `node --test tests\automation\manual_minimum_credential_ui.test.mjs`; `node --test tests\automation\ctrip_store_data_overview.test.mjs`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend OTA AI Analysis Start and Summary Context Split

- Seventy-sixth frontend split target chosen from OTA AI analysis start/run context and summary request handling inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `buildCapturedOtaAnalysisStartContext` and `buildCapturedOtaAnalysisRunContext` for selected-hotel resolution, start validation, run plan generation, empty captured-data state, and start toast text.
- Added local `requestCapturedOtaSummaryResult()` in `public/index.html` so the summary endpoint call, fallback response handling, and `summarizing` state no longer live inside `startAiAnalysis()`.
- `startAiAnalysis()` still owns the request loop, retry behavior, summary result assignment, completion history, and visible failure state. This split did not change AI endpoints, model selection, report shape, failure visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted start/run context helpers, prevent selected-data resolution, start validation, and run plan construction from being re-inlined, and validate success, missing-selection, empty-data, and run-context samples in a VM context.
- Current split-map: `public/index.html` is `37929` lines; frontend function-level blocks increased from `1406` to `1407`; `ai` domain span decreased from `1213` to `1187`; `startAiAnalysis` decreased from `111` to `86` lines.
- Current self-audit: full directory about `279.04 MB`, without `.git` about `92.33 MB`, without `.git` and dependencies about `63.14 MB`, tracked files about `18.22 MB` / `613` files; code scope `370` files, `190132` total lines, and `174344` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Profile Field Form Builder Split

- Seventy-seventh frontend split target chosen from Ctrip Profile field form defaults, smart-default inference, and save-payload construction in `public/index.html`.
- Extended `public/ctrip-static.js` with `createCtripProfileFieldForm`, `buildCtripProfileFieldSmartDefaults`, and `buildCtripProfileFieldSavePayload`.
- `public/index.html` still owns Vue form state, `/online-data/save-ctrip-profile-field`, field-list refresh, and toast display. This split only delegates default field form construction plus source key, section, endpoint, value type, unit, storage field, and pending parser status construction.
- This split did not change the Ctrip Profile save endpoint, edit echo behavior, sample verification, second confirmation, pending parser status visibility, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Profile field form/smart-default/save-payload builders, prevent the same logic from being re-inlined, and validate section, source key, endpoint, value type, unit, storage field, and `needs_parser` samples in a VM context.
- Current split-map: `public/index.html` decreased from `37929` lines to `37745` lines; frontend function-level blocks decreased from `1407` to `1396`; `ctrip` domain span decreased from `3447` to `3261`.
- Current self-audit: full directory about `279.65 MB`, without `.git` about `92.34 MB`, without `.git` and dependencies about `63.15 MB`, tracked files about `18.23 MB` / `613` files; code scope `370` files, `190189` total lines, and `174411` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Profile Recheck Flow Split

- Seventy-eighth frontend split target chosen from Ctrip Profile mismatched-field recheck flow orchestration inside `recheckCtripProfileMismatchedFields()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `runCtripProfileRecheckFlow` for optional browser recapture, capture-refresh state, recheck response handling, success/error/interrupted state handling, toast dispatch, and stop callback orchestration.
- `public/index.html` keeps runtime inputs and Vue wiring in place: request sequence, sample-loaded guard, target-field filtering, timer start, state setters, toast bridge, `runCtripBrowserCapture()`, `/online-data/recheck-ctrip-profile-mismatched-fields`, and `applyCtripProfileFieldResponse`.
- This split did not change the Ctrip Profile capture endpoint, field persistence, sample refresh, second-confirmation behavior, unresolved-parser status, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted flow runner, prevent browser recapture/recheck/success/error/interruption flow handling from being re-inlined, and validate capture, request, response, toast, and stop callback order in a VM context.
- Current split-map: `public/index.html` decreased from `37745` lines to `37684` lines; frontend function-level blocks remain `1396`; `ctrip` domain span decreased from `3261` to `3203`; `recheckCtripProfileMismatchedFields` is no longer in the largest-block list.
- Current self-audit: full directory about `280.27 MB`, without `.git` about `92.35 MB`, without `.git` and dependencies about `63.16 MB`, tracked files about `18.23 MB` / `613` files; code scope `370` files, `190289` total lines, and `174508` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend OTA Diagnosis Fetch Flow Split

- Seventy-ninth frontend split target chosen from OTA diagnosis pre-diagnosis fetch orchestration inside `runOtaDiagnosisHotelFetch()` in `public/index.html`.
- Extended `public/ota-diagnosis-static.js` with `runOtaDiagnosisHotelFetchFlow` for saved-config loading, fetch-context construction, generic Ctrip Cookie selection, Ctrip core-preset decision, fetch-task execution, and summary calculation.
- `public/index.html` keeps the runtime callback wiring in place: Ctrip/Meituan config lookup, saved OTA config reads, generic Cookie read, Ctrip Profile status probe, Profile status write, request execution, toast, and debug logging.
- This split did not change OTA diagnosis endpoints, Ctrip/Meituan fetch endpoints, storage behavior, visible failed-task handling, the continue-with-stored-data behavior, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted OTA diagnosis fetch flow runner, prevent fetch-context/task/core-preset/result-summary logic from being re-inlined, and validate profile core-preset, task execution, toast, and summary samples in a VM context.
- Current split-map: `public/index.html` decreased from `37684` lines to `37615` lines; frontend function-level blocks remain `1396`; `ota` domain span decreased from `506` to `437`; `runOtaDiagnosisHotelFetch` is no longer in the largest-block list.
- Current self-audit: full directory about `280.88 MB`, without `.git` about `92.36 MB`, without `.git` and dependencies about `63.17 MB`, tracked files about `18.24 MB` / `613` files; code scope `370` files, `190392` total lines, and `174606` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ota-diagnosis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `node --check public\ctrip-static.js`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Browser Capture Flow Split

- Eightieth frontend split target chosen from Ctrip browser capture orchestration inside `runCtripBrowserCapture()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `runCtripBrowserCaptureFlow` for target context, config loading and secret hydration, active config application, Profile validation, browser capture request execution, success writes, refresh callbacks, and normalized error evidence.
- `public/index.html` now keeps only callback wiring for Vue refs, `/online-data/capture-ctrip-browser`, latest snapshot/history/data-health refreshes, platform Profile status refresh, and platform data-source refresh.
- This split did not change the Ctrip browser capture endpoint, Profile login persistence, data-source binding, storage behavior, refresh behavior, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted flow runner, prevent target/request/request-catch flow from being re-inlined, and validate normal capture refresh callbacks plus login-only Profile status samples in a VM context.
- Current split-map: `public/index.html` decreased from `37615` lines to `37551` lines; frontend function-level blocks remain `1396`; `ctrip` domain span decreased from `3203` to `3141`; current largest frontend block is `fetchCtripData` at `96` lines.
- Current self-audit: full directory about `281.50 MB`, without `.git` about `92.37 MB`, without `.git` and dependencies about `63.18 MB`, tracked files about `18.25 MB` / `613` files; code scope `370` files, `190583` total lines, and `174797` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Fetch Flow Split

- Eighty-first frontend split target chosen from regular Ctrip data fetch orchestration inside `fetchCtripData()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `runCtripFetchDataFlow` for login, target-hotel, data-source, request-context, `/online-data/fetch-ctrip`, success display writes, history/latest refreshes, 401 handling, raw failure evidence, and request-exception handling.
- `public/index.html` now keeps only callback wiring for Vue refs, the fetch endpoint request, display model helper, latest/history/data-list refreshes, failure handler, visible-snapshot check, and logging.
- This split did not change `/online-data/fetch-ctrip`, storage behavior, display models, history refresh, latest snapshot refresh, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted flow runner, prevent request-context/success/meta/raw-failure branches from being re-inlined, and validate success refresh callbacks, raw failure display, and not-logged-in guard samples in a VM context.
- Current split-map: `public/index.html` decreased from `37551` lines to `37491` lines; frontend function-level blocks remain `1396`; `ctrip` domain span decreased from `3141` to `3084`; `fetchCtripData` is no longer in the largest-block list, and the current largest frontend block is `fetchMeituanData` at `88` lines.
- Current self-audit: full directory about `282.13 MB`, without `.git` about `92.38 MB`, without `.git` and dependencies about `63.19 MB`, tracked files about `18.27 MB` / `613` files; code scope `370` files, `190762` total lines, and `174974` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Meituan Batch Fetch Flow Split

- Eighty-second frontend split target chosen from Meituan batch ranking fetch orchestration inside `fetchMeituanData()` in `public/index.html`.
- Extended `public/meituan-static.js` with `runMeituanBatchFetchFlow` for target-hotel validation, configured data-source validation, one-time platform identifiers, batch task execution, result entries, display-model payload construction, saved-count summary, refresh callbacks, and error toast handling.
- `public/index.html` now keeps only callback wiring for Vue refs, `/online-data/fetch-meituan`, `/online-data/meituan/display-model`, display model application, history/data-list refreshes, and toast dispatch.
- This split did not change `/online-data/fetch-meituan`, `/online-data/meituan/display-model`, storage behavior, display models, history refresh, data-list refresh, missing/failed-state visibility, or OTA channel scope.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted flow runner, prevent validation/task/result/display-payload branches from being re-inlined, and validate four-rank task execution, display payload, saved-count summary, refresh callbacks, and missing-hotel guard samples in a VM context.
- Current split-map: `public/index.html` decreased from `37491` lines to `37431` lines; frontend function-level blocks remain `1396`; `meituan` domain span decreased from `1205` to `1148`; `fetchMeituanData` is no longer in the largest-block list, and the current largest frontend block is `startAiAnalysis` at `86` lines.
- Current self-audit: full directory about `282.75 MB`, without `.git` about `92.39 MB`, without `.git` and dependencies about `63.20 MB`, tracked files about `18.28 MB` / `613` files; code scope `370` files, `190891` total lines, and `175105` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\meituan-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend OTA AI Analysis Execution Runner Split

- Eighty-third frontend split target chosen from captured OTA AI group execution orchestration inside `startAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `runCapturedOtaAnalysisExecution` for group execution, retry progress counting, success/failed group outcome, summary context construction, summary result assignment, all-failed error construction, and completion history generation.
- `public/index.html` now keeps only Vue state initialization, start/run context handling, request callback wiring, and final Vue ref writes for `startAiAnalysis()`.
- This split did not change `/agent/analyze-captured-ota-data`, `/agent/summarize-captured-ota-analysis`, model selection, report shape, history limit, visible failure-state behavior, or Ctrip OTA channel scope. All-failed errors remain explicit and masked.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted execution runner, prevent the group execution loop from being re-inlined, and validate success-plus-retry, summary context, history, and all-failed masking samples in a VM context.
- Current split-map: `public/index.html` decreased from `37431` lines to `37403` lines; frontend function-level blocks remain `1396`; `ai` domain span decreased from `1187` to `1159`; `startAiAnalysis` is no longer in the largest-block list, and the current largest frontend block is `runCtripCookieApiCapture` at `85` lines.
- Current self-audit: full directory about `283.37 MB`, without `.git` about `92.40 MB`, without `.git` and dependencies about `63.21 MB`, tracked files about `18.29 MB` / `613` files; code scope `370` files, `191047` total lines, and `175258` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Cookie API Capture Flow Split

- Eighty-fourth frontend split target chosen from Ctrip Cookie API capture orchestration inside `runCtripCookieApiCapture()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `runCtripCookieApiCaptureFlow` for target hotel validation, Request URL / endpoints JSON validation, config loading and secret hydration, Profile resolution, Cookie API request body construction, success refresh callbacks, not-ready warning, error response handling, and exception evidence preservation.
- `public/index.html` now keeps only Vue ref callback wiring, the `/online-data/fetch-ctrip-cookie-api` request callback, toast dispatch, latest/history/data-health refresh callbacks, and final state writes.
- This split did not change `/online-data/fetch-ctrip-cookie-api`, storage behavior, Ctrip Profile binding, latest snapshot refresh, history refresh, data-health refresh, missing/failed-state visibility, or Ctrip OTA channel scope. Not-ready, identity mismatch, missing Profile, and missing request-source states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Cookie API flow runner, prevent request-source validation, Cookie selection, and request flow from being re-inlined, and validate success refresh, not-ready, error response, exception, missing Profile, and missing request-source samples in a VM context.
- Current split-map: `public/index.html` decreased from `37403` lines to `37351` lines; frontend function-level blocks remain `1396`; `ctrip` domain span decreased from `3084` to `3032`; `runCtripCookieApiCapture` is no longer in the largest-block list, and the current largest frontend block is `triggerAutoFetch` at `82` lines.
- Current self-audit: full directory about `284 MB`, without `.git` about `92.42 MB`, without `.git` and dependencies about `63.23 MB`, tracked files about `18.30 MB` / `613` files; code scope `370` files, `191296` total lines, and `175503` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Auto-Fetch Trigger Flow Split

- Eighty-fifth frontend split target chosen from manual auto-fetch trigger orchestration inside `triggerAutoFetch()` in `public/index.html`.
- Extended `public/auto-fetch-static.js` with `runAutoFetchTriggerFlow`, `buildAutoFetchTriggerRequestBody`, and `buildAutoFetchRunStartState` for selected-hotel guard, platform-config guard, request body construction, running state, success refresh callbacks, error response handling, exception handling, and busy/timer cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/auto-fetch`, toast dispatch, elapsed-time formatting, latest/history/status/data-health refresh callbacks, and Ctrip Profile field review opening.
- This split did not change `/online-data/auto-fetch`, storage behavior, latest snapshot refresh, history refresh, data-health refresh, Ctrip Profile review behavior, missing/failed-state visibility, or OTA channel scope. Missing hotel and missing platform config guards remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted auto-fetch trigger runner, prevent request body, running state, and success refresh flow from being re-inlined, and validate success, error response, exception, missing hotel, and missing config samples in a VM context.
- Current split-map: `public/index.html` decreased from `37351` lines to `37297` lines; frontend function-level blocks remain `1396`; `general` domain span decreased from `7165` to `7111`; `triggerAutoFetch` is no longer in the largest-block list, and the current largest frontend block is `fetchCtripFlowOverviewData` at `72` lines.
- Current self-audit: full directory about `284.64 MB`, without `.git` about `92.43 MB`, without `.git` and dependencies about `63.24 MB`, tracked files about `18.32 MB` / `613` files; code scope `370` files, `191558` total lines, and `175756` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\auto-fetch-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Overview Fetch Flow Split

- Eighty-sixth frontend split target chosen from Ctrip overview and flow-overview fetch orchestration inside `fetchCtripOverviewData()` and `fetchCtripFlowOverviewData()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `runCtripOverviewFetchFlow` for target-hotel guard, Ctrip config secret hydration, form normalization, request URL and Cookie validation, overview request body construction, success refresh callbacks, error response handling, exception evidence preservation, and busy-state cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/fetch-ctrip-overview`, toast dispatch, latest snapshot refresh, and online history refresh.
- This split did not change `/online-data/fetch-ctrip-overview`, storage behavior, latest snapshot refresh, history refresh, raw-data display state, missing/failed-state visibility, or Ctrip OTA channel scope. Missing hotel, missing Ctrip config, page URL misuse, and missing Cookie states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted overview fetch runner, prevent flow default URL selection and overview request body logic from being re-inlined, and validate success, error response, exception, missing hotel, missing config, invalid page URL, and missing Cookie samples in a VM context.
- Current split-map: `public/index.html` decreased from `37297` lines to `37217` lines; frontend function-level blocks remain `1396`; `ctrip` domain span decreased from `3032` to `2952`; `fetchCtripFlowOverviewData` and `fetchCtripOverviewData` are no longer in the largest-block list, and the current largest frontend block is `fetchCtripAdsData` at `72` lines.
- Current self-audit: full directory about `285.27 MB`, without `.git` about `92.45 MB`, without `.git` and dependencies about `63.26 MB`, tracked files about `18.33 MB` / `613` files; code scope `370` files, `191705` total lines, and `175904` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Ads Fetch Flow Split

- Eighty-seventh frontend split target chosen from Ctrip ads fetch orchestration inside `fetchCtripAdsData()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `runCtripAdsFetchFlow` for target-hotel guard, Ctrip config secret hydration, active config application, ads direct-config synchronization, ads API URL validation, Cookie validation, custom-date validation, request body construction, success refresh callbacks, error response handling, exception evidence preservation, and busy-state cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/fetch-ctrip-ads`, toast dispatch, latest snapshot refresh, and online history refresh. `isCtripAdsApiUrl` and `normalizeCtripAdsApiType` remain referenced in the entry for config-form validation.
- This split did not change `/online-data/fetch-ctrip-ads`, storage behavior, latest snapshot refresh, history refresh, raw-data display state, missing/failed-state visibility, or Ctrip OTA channel scope. Page URL misuse, non-ads API URL, missing Cookie, and missing custom dates remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted ads fetch runner, prevent ads URL/Cookie selection and request flow from being re-inlined, and validate success, error response, exception, missing hotel, missing config, invalid page URL, invalid API URL, missing Cookie, and missing custom-date samples in a VM context.
- Current split-map: `public/index.html` decreased from `37217` lines to `37171` lines; frontend function-level blocks remain `1396`; `ctrip` domain span decreased from `2952` to `2906`; `fetchCtripAdsData` is no longer in the largest-block list, and the current largest frontend block is `generateOtaDiagnosis` at `71` lines.
- Current self-audit: full directory about `285.91 MB`, without `.git` about `92.46 MB`, without `.git` and dependencies about `63.27 MB`, tracked files about `18.34 MB` / `613` files; code scope `370` files, `191887` total lines, and `176084` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend OTA Diagnosis Generate Flow Split

- Eighty-eighth frontend split target chosen from OTA diagnosis generation orchestration inside `generateOtaDiagnosis()` in `public/index.html`.
- Extended `public/ota-diagnosis-static.js` with `runOtaDiagnosisGenerateFlow`, `buildOtaDiagnosisGenerateRequestBody`, `isEmptyOtaDiagnosisResult`, and `buildOtaDiagnosisFetchFailureWarning` for form validation, pre-diagnosis fetch warning, diagnosis request body construction, empty-result detection, failed response handling, exception handling, and loading cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, hotel options, `runOtaDiagnosisHotelFetch()`, `/agent/ota-diagnosis`, and toast dispatch.
- This split did not change `/agent/ota-diagnosis`, pre-diagnosis fetch behavior, continue-with-stored-data behavior, empty data display, missing/failed-state visibility, or OTA channel scope. Missing hotel, missing date range, invalid date range, backend failure, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted generate flow runner, prevent request-body construction, empty-result detection, and fetch-failure warning logic from being re-inlined, and validate success, partial fetch failure, missing hotel, backend failure, and exception cleanup samples in a VM context.
- Current split-map: `public/index.html` decreased from `37171` lines to `37118` lines; frontend function-level blocks remain `1396`; `ota` domain span decreased from `437` to `384`; `generateOtaDiagnosis` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits`, `fetchMeituanOrdersData`, and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `286.55 MB`, without `.git` about `92.47 MB`, without `.git` and dependencies about `63.28 MB`, tracked files about `18.36 MB` / `613` files; code scope `370` files, `192092` total lines, and `176281` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ota-diagnosis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Meituan Order Fetch Flow Split

- Eighty-ninth frontend split target chosen from Meituan order fetch orchestration inside `fetchMeituanOrdersData()` in `public/index.html`.
- Extended `public/meituan-static.js` with `runMeituanOrderFetchFlow`, `normalizeMeituanOrderFetchForm`, `validateMeituanOrderFetchInput`, and `buildMeituanOrderFetchRequestBody` for form normalization, explicit input guards, request body construction, success result writes, failed response handling, exception handling, and busy-state cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/fetch-meituan-orders`, result setters, toast dispatch, and online history refresh.
- This split did not change `/online-data/fetch-meituan-orders`, storage behavior, order result display, history refresh, missing/failed-state visibility, or Meituan OTA channel scope. Missing URL, order page URL misuse, missing partnerId, missing poiId, missing Cookies, backend failure, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted order fetch runner, prevent order request flow, page URL guard, success write, and success toast from being re-inlined, and validate success, missing URL, backend failure, and exception cleanup samples in a VM context.
- Current split-map: `public/index.html` decreased from `37118` lines to `37066` lines; frontend function-level blocks remain `1396`; `meituan` domain span decreased from `1148` to `1095`; `fetchMeituanOrdersData` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `287.18 MB`, without `.git` about `92.49 MB`, without `.git` and dependencies about `63.30 MB`, tracked files about `18.37 MB` / `613` files; code scope `370` files, `192282` total lines, and `176462` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\meituan-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Meituan Ads Fetch Flow Split

- Ninetieth frontend split target chosen from Meituan ads fetch orchestration inside `fetchMeituanAdsData()` in `public/index.html`.
- Extended `public/meituan-static.js` with `runMeituanAdsFetchFlow`, `normalizeMeituanAdsFetchForm`, `validateMeituanAdsFetchInput`, and `buildMeituanAdsFetchRequestBody` for form normalization, explicit input guards, request body construction, success result writes, failed response handling, exception handling, and busy-state cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/fetch-meituan-ads`, result setters, toast dispatch, and online history refresh.
- This split did not change `/online-data/fetch-meituan-ads`, storage behavior, ads result display, history refresh, missing/failed-state visibility, or Meituan OTA channel scope. Missing URL, ads page URL misuse, missing shop/poi id, missing Cookies, backend failure, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted ads fetch runner, prevent ads request flow, page URL guard, success write, and success toast from being re-inlined, and validate success, missing URL, backend failure, and exception cleanup samples in a VM context.
- Current split-map: `public/index.html` decreased from `37066` lines to `37014` lines; frontend function-level blocks decreased from `1396` to `1395`; `meituan` domain span decreased from `1095` to `1042`; `fetchMeituanAdsData` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `287.82 MB`, without `.git` about `92.50 MB`, without `.git` and dependencies about `63.31 MB`, tracked files about `18.38 MB` / `613` files; code scope `370` files, `192475` total lines, and `176649` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\meituan-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Meituan Traffic Fetch Flow Split

- Ninety-first frontend split target chosen from Meituan traffic fetch orchestration inside `fetchMeituanTrafficData()` in `public/index.html`.
- Extended `public/meituan-static.js` with `runMeituanTrafficFetchFlow`, `normalizeMeituanTrafficFetchForm`, `validateMeituanTrafficFetchInput`, and `buildMeituanTrafficFetchRequestBody` for form normalization, explicit input guards, request body construction, success result writes, failed response handling, exception handling, and busy-state cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/fetch-meituan-traffic`, traffic result setters, toast dispatch, online history refresh, and online data refresh.
- This split did not change `/online-data/fetch-meituan-traffic`, storage behavior, traffic result display, history refresh, online data refresh, missing/failed-state visibility, or Meituan OTA channel scope. Missing URL, missing partnerId, missing poiId, missing Cookies, backend failure, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted traffic fetch runner, prevent traffic request flow, success write, and success toast from being re-inlined, and validate success, missing URL, backend failure, and exception cleanup samples in a VM context.
- Current split-map: `public/index.html` decreased from `37014` lines to `36970` lines; frontend function-level blocks remain `1395`; `meituan` domain span decreased from `1042` to `997`; `fetchMeituanTrafficData` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `288.45 MB`, without `.git` about `92.51 MB`, without `.git` and dependencies about `63.32 MB`, tracked files about `18.40 MB` / `613` files; code scope `370` files, `192660` total lines, and `176826` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\meituan-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Traffic Fetch Flow Split

- Ninety-second frontend split target chosen from Ctrip traffic fetch orchestration inside `fetchCtripTrafficData()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `runCtripTrafficFetchFlow`, reusing existing `buildCtripTrafficFetchRequestBody` and `buildCtripTrafficResponseModel` for request construction and response display model construction.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/ctrip/traffic`, traffic display writes, online history refresh, online data refresh, and the existing Ctrip fetch failure handler.
- This split did not change `/online-data/ctrip/traffic`, storage behavior, traffic display row construction, history refresh, latest-snapshot failure fallback, missing/failed-state visibility, or Ctrip OTA channel scope. Missing target hotel, missing Ctrip data source, missing Cookie, missing custom dates, backend failure, empty traffic result, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted traffic fetch runner, prevent traffic request flow, response model write, and success write from being re-inlined, and validate success, empty result, backend failure, exception, and missing-state samples in a VM context.
- Current split-map: `public/index.html` decreased from `36970` lines to `36927` lines; frontend function-level blocks remain `1395`; `ctrip` domain span decreased from `2906` to `2864`; `fetchCtripTrafficData` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `289.09 MB`, without `.git` about `92.52 MB`, without `.git` and dependencies about `63.33 MB`, tracked files about `18.41 MB` / `613` files; code scope `370` files, `192820` total lines, and `176983` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Meituan Browser Capture Flow Split

- Ninety-third frontend split target chosen from Meituan browser capture orchestration inside `runMeituanBrowserCapture()` in `public/index.html`.
- Extended `public/meituan-static.js` with `runMeituanBrowserCaptureFlow` and `buildMeituanBrowserCaptureRequestContext` for target validation, section normalization, request body construction, success result writes, failed response handling, exception handling, and busy-state cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/capture-meituan-browser`, result setters, toast dispatch, online history refresh, platform Profile status refresh, and platform data-source refresh.
- This split did not change `/online-data/capture-meituan-browser`, storage behavior, browser capture result display, Profile login save behavior, data-source binding, history refresh, platform status refresh, missing/failed-state visibility, or Meituan OTA channel scope. Missing target hotel, missing store id, missing ads entry URL, backend failure, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted browser capture runner, prevent browser capture target context, request flow, login-only payload, and exception result handling from being re-inlined, and validate success, login-only, backend failure, exception, and missing-state samples in a VM context.
- Current split-map: `public/index.html` decreased from `36927` lines to `36889` lines; frontend function-level blocks remain `1395`; `meituan` domain span decreased from `997` to `958`; `runMeituanBrowserCapture` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `289.74 MB`, without `.git` about `92.54 MB`, without `.git` and dependencies about `63.35 MB`, tracked files about `18.43 MB` / `613` files; code scope `370` files, `193054` total lines, and `177211` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\meituan-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Meituan Captured Payload Save Flow Split

- Ninety-fourth frontend split target chosen from Meituan manual captured JSON save orchestration inside `saveMeituanCapturedPayload()` in `public/index.html`.
- Extended `public/meituan-static.js` with `buildMeituanCapturedPayloadSaveContext` and `runMeituanCapturedPayloadSaveFlow` for target validation, JSON trimming/parsing, payload enrichment, save request orchestration, success result writes, failed response handling, exception handling, and busy-state cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/save-meituan-captured-data`, result setters, toast dispatch, and online history refresh.
- This split did not change `/online-data/save-meituan-captured-data`, manual captured JSON storage behavior, save success display, online history refresh, missing/failed-state visibility, or Meituan OTA channel scope. Missing target hotel, missing payload JSON, invalid JSON, non-object JSON, backend failure, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted captured payload save runner, prevent JSON trim/parse, payload enrichment, and save request flow from being re-inlined, and validate success, backend failure, exception, and missing-state samples in a VM context.
- Current split-map: `public/index.html` decreased from `36889` lines to `36848` lines; frontend function-level blocks remain `1395`; `meituan` domain span decreased from `958` to `916`; `saveMeituanCapturedPayload` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `290.39 MB`, without `.git` about `92.55 MB`, without `.git` and dependencies about `63.36 MB`, tracked files about `18.44 MB` / `613` files; code scope `370` files, `193237` total lines, and `177388` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\meituan-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Ctrip Config Save Flow Split

- Ninety-fifth frontend split target chosen from Ctrip config save orchestration inside `saveCtripConfig()` in `public/index.html`.
- Extended `public/ctrip-static.js` with `createCtripConfigForm`, `buildCtripConfigSavePayload`, `validateCtripConfigSaveInput`, and `runCtripConfigSaveFlow` for default form construction, input validation, request body construction, save request orchestration, success form reset, failed response handling, exception response parsing, and config-list reload dispatch.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/save-ctrip-config`, toast dispatch, form reset, config list reload, and error logging.
- This split did not change `/online-data/save-ctrip-config`, Ctrip config fields, authorization content storage behavior, save success display, config list refresh, missing/failed-state visibility, or Ctrip OTA channel scope. Missing config name, missing platform authorization content, backend failure, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted config form builder and save runner, prevent config save validation, payload, failed-response handling, and exception response parsing from being re-inlined, and validate success, backend failure, exception, and missing-state samples in a VM context.
- Current split-map: `public/index.html` decreased from `36848` lines to `36795` lines; frontend function-level blocks remain `1395`; `ctrip` domain span decreased from `2864` to `2819`; `saveCtripConfig` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `291.03 MB`, without `.git` about `92.57 MB`, without `.git` and dependencies about `63.38 MB`, tracked files about `18.45 MB` / `613` files; code scope `370` files, `193406` total lines, and `177557` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ctrip-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Meituan AI Analysis Flow Split

- Ninety-sixth frontend split target chosen from Meituan AI analysis orchestration inside `startMeituanAiAnalysis()` in `public/index.html`.
- Extended `public/ai-analysis-static.js` with `validateMeituanAiAnalysisStart` and `runMeituanAiAnalysisFlow` for selected-hotel validation, selected-data resolution, request body construction, backend AI request orchestration, sanitized report write, history trimming, failed response handling, exception logging, and busy-state cleanup.
- `public/index.html` now keeps only Vue callback wiring for refs, `/online-data/ai-analysis`, toast dispatch, result setter, history setter, analyzing-state setter, and error logging.
- This split did not change `/online-data/ai-analysis`, Meituan OTA channel scope, AI report HTML sanitization, the latest-10 history limit, missing/failed-state visibility, or backend AI analysis responsibility. Missing selected hotel, missing selected data, backend failure, and request exception states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted Meituan AI analysis runner, prevent selection validation, selected-data resolution, request body construction, request flow, history construction/trimming, and exception logging from being re-inlined, and validate success, backend failure, exception, and missing-state samples in a VM context.
- Current split-map: `public/index.html` decreased from `36795` lines to `36756` lines; frontend function-level blocks remain `1395`; `meituan` domain span decreased from `916` to `876`; `startMeituanAiAnalysis` is no longer in the largest-block list, and the current largest frontend blocks are `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Current self-audit: full directory about `291.69 MB`, without `.git` about `92.58 MB`, without `.git` and dependencies about `63.39 MB`, tracked files about `18.46 MB` / `613` files; code scope `370` files, `193552` total lines, and `177708` nonblank lines; cleanup candidates `0`.
- Verified during the split: `node --check public\ai-analysis-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `git diff --check`; `npm.cmd run self:check`.
- Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Frontend Transfer Decision Layer Split

- Ninety-seventh frontend split target chosen from investment transfer decision-layer row construction inside `transferDecisionLayerRows` in `public/index.html`.
- Extended `public/simulation-static.js` with `buildTransferDecisionLayerRows` for fact data, manual assumption, calculation result, and risk decision rows.
- `public/index.html` now keeps only Vue ref reads and the `buildTransferDecisionLayerRows` call for the transfer decision layer.
- This split did not change transfer pricing/timing APIs, operating snapshot loading, manual input scope, final dashboard fields, or investment decision boundaries. Missing snapshot, unfilled assumptions, ungenerated calculations, and unaggregated dashboard states remain explicit.
- Updated `scripts/verify_e2e_contracts.mjs` so E2E contracts require the extracted transfer decision layer builder, prevent fact-row and calculation-evidence construction from being re-inlined, and validate ready/empty state samples in a VM context.
- Commit-scope metrics: `public/index.html` decreases from `36756` lines to `36726` lines; the `transfer` domain span decreases from `274` to `244`; `transferDecisionLayerRows` is no longer in the largest-block list, and the current largest frontend blocks remain `importKnowledgeUnits` and `openSystemConfigModal` at `68` lines each.
- Commit-scope code stats: `370` files, `193633` total lines, `177788` nonblank lines, tracked files about `18.46 MB` / `613` files, and cleanup candidates `0`.
- Verified during the split: `node --check public\simulation-static.js`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:split-map`; `npm.cmd run self:audit`; `npm.cmd run self:clean`; `npm.cmd run self:check`; `git diff --cached --check`.
- Current worktree also contains unstaged external changes outside this save point. This save point intentionally stages only the transfer decision-layer split files. Strict gate remains intentionally incomplete until the remaining split candidates, especially `public/index.html` and `app/controller/OnlineData.php`, are further reduced or explicitly dispositioned.

## 2026-06-11 Progress: Online Detail Pagination and Public Entry Slimming Guards

- Local slimming cleanup ran through `npm.cmd run self:clean:dry-run` and `npm.cmd run self:clean`; one generated `runtime` target was removed, reclaiming about `0.01 MB`, and the current default cleanup reclaim is `0 MB`.
- `SystemNotificationController` now applies per-user notification state filtering, pagination, and unread counting at the database query layer through `system_notification_user_states`; mark-all-read and clear actions also use `visibleNotificationIdsForCurrentUser()` to fetch DB-scoped visible IDs instead of full-list PHP filtering. Visible-scope constraints keep explicit notification table aliases.
- `SystemConfigController::index()` now returns single-key reads through `SystemConfig::getValue($requestedKey, ...)` before loading all configs; public-scope config reads use `SystemConfig::getConfigsByKeys($publicKeys)` so bounded/public config reads avoid full config scans. `SystemConfig` now has a bounded key read helper.
- `Hotel::all()` now includes `status` in the option list response so dashboard/data-source UI can distinguish active and inactive hotels without inferring missing state.
- `public/router.php` now serves static files inside the public root with MIME, ETag, Last-Modified, Cache-Control, and optional gzip handling. Gzip output is cached under `runtime/static-gzip` to avoid repeated CPU compression for large local assets, while preserving the ThinkPHP fallback for application routes.
- `public/images/login-hotel-lobby-bg.avif` was added as the first-choice login background asset at about `18 KB`; `public/images/login-hotel-lobby-bg.webp` remains the secondary candidate at about `36 KB`, and the existing PNG remains as the legacy asset declaration and fallback candidate at about `1.92 MB`. `public/index.html` preloads the AVIF before core CSS with `fetchpriority="high"`. `public/style.css` keeps the original PNG background declaration first, then uses later `-webkit-image-set` / `image-set` declarations in AVIF -> WebP -> PNG order. The login business entry is unchanged.
- `public/index.html` now requests a 100-row online-analysis sample instead of `page_size=all`, describes full coverage through summary metrics rather than implying complete row detail, discovers core CSS before synchronous Vue/static scripts, lazy-loads Chart.js, global notification static data, test-id page-control observers, hotel-image optimizer static data, revenue-research static data, and operation/opening/lifecycle static data, removes the unused local `public/vue-router.global.prod.js` copy, and limits Meituan competitor by-hotel summary loading to explicit `includeByHotel: true` call sites. The Meituan ranking refresh still uses the selected hotel id and explicitly passes `includeByHotel: false`.
- Entry and OTA guard scripts were updated: `verify_public_entry_guard.mjs` requires core stylesheet discovery before synchronous Vue/static scripts, requires the AVIF login background preload before core CSS, prevents eager loading or dead-file retention of `vue-router.global.prod.js`, prevents synchronous first-shell loading of `notification-static.js`, `testid-static.js`, `hotel-image-optimizer-static.js`, `revenue-research-static.js`, and `operation-static.js`, guards the PNG legacy login background declaration, the optimized AVIF/WebP assets, and the later `-webkit-image-set` / `image-set` declarations in AVIF -> WebP -> PNG order, requires deferred `form-operation-support.js`, and guards the static gzip cache contract; opening batch action, platform batch health, platform data-source, P0 learning, and manual minimum credential UI tests now assert the new scope boundary.
- Current self-audit: full directory about `299.71 MB`, without `.git` about `92.66 MB`, without `.git` and dependencies about `63.47 MB`, tracked files about `18.55 MB` / `614` files; code scope `369` files, `194355` total lines, and `178489` nonblank lines; cleanup candidates `0 MB`.
- Current split-map: `public/index.html` has `37072` lines and `1418` frontend function-level blocks; `app/controller/OnlineData.php` has `26725` lines and `871` methods. Both remain real split candidates; `public/tailwind.min.css` remains accepted as a local CSS dependency, not a business-code split target.
- Verified in this save point: PHP lint for `app/controller/Hotel.php`, `app/controller/SystemConfigController.php`, `app/model/SystemConfig.php`, `app/controller/SystemNotificationController.php`, and `public/router.php`; Node checks for the changed verifier/test scripts; `tests\SystemNotificationTest.php`; platform batch/data-source contracts; `tests\automation\manual_minimum_credential_ui.test.mjs`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:p0-learning`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:public-entry`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`; `npm.cmd run self:check`; `git diff --check`.
- Strict gate remains intentionally incomplete because `public/index.html` and `app/controller/OnlineData.php` still require further splitting or explicit disposition. PR #2 remains Draft unless the user explicitly chooses release/ready.

## 2026-06-11 Progress: Expansion/Simulation Static Lazy Load and Platform Binding Preset Split

- `public/index.html` no longer eagerly loads `expansion-static-options.js` in the first shell. It now lazy-loads the static expansion config for expansion, market evaluation, benchmark model, collaboration efficiency, strategy, and feasibility pages. Market evaluation, benchmark model, collaboration efficiency, strategy simulation, feasibility generation, feasibility copy, expansion detail loading, and strategy detail loading explicitly await `ensureExpansionStaticReady()` before using static expansion functions. Missing static config remains a visible error path through toast plus thrown error, not an empty fallback.
- `public/index.html` no longer eagerly loads `simulation-static.js` in the first shell. It now lazy-loads simulation static config for simulation, feasibility, benchmark model, collaboration efficiency, asset pricing, timing strategy, and decision-board pages. Simulation detail loading, transfer detail loading, simulation calculation, transfer pricing, transfer timing, transfer dashboard, benchmark model, collaboration efficiency, and feasibility generation explicitly await `ensureSimulationStaticReady()`.
- `public/system-static.js` now owns `platformAccountBindingGuidePresetRows` and exports `getPlatformAccountBindingGuideRows()` for the Ctrip Profile, Meituan Profile, and Cookie/API binding guides. `public/index.html` resolves `getPlatformAccountBindingGuideRows` through setup-safe `requireAppSystemStatic()` and version-busts `system-static.js` so old cached static config does not continue executing.
- This save point does not change platform account binding APIs, OTA capture APIs, credential storage behavior, data-source status calculation, Profile status display, review-text/phone-number boundaries, or OTA channel scope. `allowed_hosts` is copied when rows are returned so UI code cannot mutate shared presets.
- Guards updated: `verify_public_entry_guard.mjs` prevents eager loading of `expansion-static-options.js` and `simulation-static.js`, requires both lazy loaders and page-entry load triggers, and requires strategy, simulation, and transfer history input reuse to load the matching static data first. `verify_simulation_p2.mjs` is aligned with the simulation static lazy-load boundary. `verify_platform_account_guide_contract.mjs` now validates the three platform binding guide presets from `public/system-static.js` and prevents re-inlining `ctrip-profile` in `public/index.html`.
- Local slimming cleanup: `npm.cmd run self:clean:dry-run` confirmed generated `runtime` local artifacts only. This save point ran `npm.cmd run self:clean` for the initial runtime target and again after verification removed about `1.48 MB` of newly generated runtime artifacts. Current cleanup candidates are `0`.
- Current self-audit: full directory about `302.49 MB`, without `.git` about `92.69 MB`, without `.git` and dependencies about `63.50 MB`, tracked files about `18.57 MB` / `614` files; code scope `369` files, `194625` total lines, and `178759` nonblank lines; cleanup candidates `0`.
- Current split-map: `public/index.html` has `37227` lines, `1494` frontend function-level blocks, and `44` `currentPage` refs; `app/controller/OnlineData.php` has `26725` lines and `871` methods. Both remain real split candidates; `public/tailwind.min.css` remains accepted as a local CSS dependency, not a business-code split target.
- Verified in this save point: `node --check public\system-static.js`; `node --check scripts\verify_platform_account_guide_contract.mjs`; `node --check scripts\verify_public_entry_guard.mjs`; `node --check scripts\verify_simulation_p2.mjs`; `npm.cmd run verify:platform-account-guide`; `npm.cmd run verify:simulation-p2`; `npm.cmd run verify:public-entry`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run verify:p0-guards`; `npm.cmd run self:check`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`; `git diff --check`.
- Strict gate remains intentionally incomplete because `public/index.html` and `app/controller/OnlineData.php` still require further splitting or explicit disposition. PR #2 remains Draft unless the user explicitly chooses release/ready.

## 2026-06-11 Progress: AI Analysis and Collection Panel Lazy Load

- `public/index.html` no longer eagerly loads `ai-analysis-static.js` in the first shell. It lazy-loads the AI analysis static helper only from the OTA AI/download-center tab and the online-data `analysis` tab; normal manual capture and platform auto-fetch entries do not load the AI helper. Ctrip AI analysis, Meituan AI analysis, and AI hotel-list refreshes explicitly await `ensureAiAnalysisStaticReady()`; load failure remains visible through toast and stops the current action.
- The default online-data page no longer preloads the full `platform-auto` panel. Hotel lists, Ctrip/Meituan configs, auto-fetch status, and profile status load when the `platform-auto` tab is entered, with a short TTL to avoid duplicate requests. Post-capture list, history, latest-data, status, notification, and review refreshes now use deferred scheduling instead of blocking the initiating UI action on every refresh request.
- This save point does not change `/agent/analyze-captured-ota-data`, `/agent/summarize-captured-ota-analysis`, or `/online-data/ai-analysis`, and does not change Ctrip/Meituan OTA channel scope, report HTML sanitization, history writes, or failed-state display.
- Guards updated: `verify_public_entry_guard.mjs` prevents eager loading of `ai-analysis-static.js`, requires the lazy loader and page-entry load trigger, and `verify_e2e_contracts.mjs` now requires the lazy loader, ready guard, page trigger, and action readiness checks instead of the synchronous script. E2E contract coverage increased to `491` checks.
- Local slimming cleanup: after verification, `npm.cmd run self:clean` removed generated runtime artifacts. Current cleanup candidates are `0`.
- Current self-audit: full directory about `302.61 MB`, without `.git` about `92.70 MB`, without `.git` and dependencies about `63.51 MB`, tracked files about `18.59 MB` / `614` files; code scope `369` files, `194778` total lines, and `178911` nonblank lines; cleanup candidates `0`.
- Current split-map: `public/index.html` has `37365` lines, `1536` frontend function-level blocks, and `44` `currentPage` refs; `app/controller/OnlineData.php` has `26725` lines and `871` methods. Both remain real split candidates; `public/tailwind.min.css` remains accepted as a local CSS dependency, not a business-code split target.
- Verified in this save point: `git diff --check`; `node --check scripts\verify_public_entry_guard.mjs`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:clean`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.
- Strict gate remains intentionally incomplete because `public/index.html` and `app/controller/OnlineData.php` still require further splitting or explicit disposition. PR #2 remains Draft unless the user explicitly chooses release/ready.

## 2026-06-11 Progress: Auto-Fetch Static Lazy Load

- `public/index.html` no longer eagerly loads `auto-fetch-static.js` in the first shell. The login page and default online-data page do not load platform auto-fetch helpers. The compass daily-ops field list, `platform-auto` panel, data-source config modal/save/test paths, saved OTA data config reads, and manual auto-fetch trigger explicitly await `ensureAutoFetchStaticReady()`.
- The normal `online-data` `data` tab no longer triggers `loadAutoFetchPanel()` from menu tab routing; only the `platform-auto` tab loads the auto-fetch panel. This does not change `/online-data/auto-fetch`, Ctrip/Meituan OTA capture APIs, storage behavior, failed-state display, or OTA channel scope.
- Manual fetch config prewarm is preserved: entering the `online-data` `data` tab, including menu routing, loads only saved Ctrip/Meituan config lists without loading the full `platform-auto` panel. Ctrip manual fetch success no longer blocks the main result return on history/latest snapshot refresh.
- Guards updated: `verify_public_entry_guard.mjs` prevents eager `auto-fetch-static.js`, requires the lazy loader/ready guard, and requires platform auto-fetch/data-source config entry points to load the helper first. `verify_e2e_contracts.mjs` now checks the auto-fetch static lazy-load boundary, manual fetch config prewarm, and non-blocking Ctrip post-fetch refresh; E2E contract coverage increased to `499`.
- Verified in this save point: `npm.cmd run verify:public-entry`; `node --check scripts\verify_public_entry_guard.mjs`; `node --check scripts\verify_e2e_contracts.mjs`; `npm.cmd run verify:p0-guards`; `npm.cmd run verify:e2e-contracts`; `npm.cmd run self:clean`; `npm.cmd run self:audit`; `npm.cmd run self:split-map`.

## 2026-06-11 Progress: Core Fetch Interaction Responsiveness

- The `online-data` manual data tab now prewarms saved Ctrip/Meituan config lists through `ensureManualOnlineFetchConfigReady()` without loading the full `platform-auto` panel. It does not load auto-fetch status, Profile status, collection resources, or history records.
- Ctrip manual fetch success in `runCtripFetchDataFlow()` now writes the response, cards/table data, success state, and latest meta before post-fetch refreshes. History, latest-snapshot, and data-list refreshes use the existing deferred refresh callbacks and no longer block the fetch button from recovering after `/online-data/fetch-ctrip` returns.
- Guards updated: `verify_public_entry_guard.mjs` requires the manual-fetch config prewarm, and `verify_e2e_contracts.mjs` validates that the Ctrip manual fetch flow returns before delayed history/latest refresh promises settle. E2E contract coverage increased to `499`.

## 2026-06-11 Progress: P0-P2 Priority State Refresh

- `AGENTS.md` was updated to remove stale P0/P2 instructions that referenced a missing `hotel-frontend/` directory and missing `public/assets/` Vite build output. Current verification found neither path in the worktree or tracked file list.
- Current priority state now keeps release evidence and GitHub save-point hygiene as P0 boundaries, Ctrip/Meituan field-to-UI closure and AI governance as P1 development targets, and `public/index.html` plus `app/controller/OnlineData.php` complexity reduction as P2.
- Cookie warning, `.example.env`, and README are treated as current-state items rather than repeated implementation targets: CookieHealth PHPUnit coverage passes, `.example.env` covers current `.env` keys with safe placeholders, and README uses `database/init_full.sql` plus `127.0.0.1:8080` startup guidance.

## Maintenance Rule

Update this vault after important context changes, save-project runs, new release evidence, or completed field/table closure work. Record only verified facts and avoid secrets, raw cookies, raw tokens, account data, phone numbers, screenshots with sensitive OTA data, or large raw capture JSON.
