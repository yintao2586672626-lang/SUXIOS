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

## Maintenance Rule

Update this vault after important context changes, save-project runs, new release evidence, or completed field/table closure work. Record only verified facts and avoid secrets, raw cookies, raw tokens, account data, phone numbers, screenshots with sensitive OTA data, or large raw capture JSON.
