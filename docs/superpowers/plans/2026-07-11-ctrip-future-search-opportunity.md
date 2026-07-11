# Ctrip Future Search Opportunity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add one-click preferred Cookie/Profile collection, storage, readback, and an operational Ctrip future-search opportunity panel for the four `querySearchFlowDetails` datasets.

**Architecture:** Extend the existing Ctrip capture catalog with a request-aware array zipper that emits one standard row per target date/scope/window. Reuse `online_daily_data`, expose a focused read endpoint, and render a single panel inside the existing Ctrip traffic page using a small static helper for derivations and chart state.

**Tech Stack:** Node.js capture scripts, ThinkPHP 8/PHP, MySQL `online_daily_data`, Vue 3 CDN, Chart.js, Node test runner, PHPUnit/contract verifiers.

---

### Task 1: Lock the response and request contract

**Files:**
- Modify: `tests/automation/ctrip_capture_catalog.test.mjs`
- Modify: `scripts/lib/ctrip_capture_catalog.mjs`

- [ ] **Step 1: Add a failing catalog test** that supplies a sanitized `requestPayload` for all four `dataType/searchType` combinations and asserts target dates, PV, UV, conversion, compare scope, search window, valid zero values, and `field_missing` for null order arrays.
- [ ] **Step 2: Run** `node --test tests/automation/ctrip_capture_catalog.test.mjs` and confirm the new test fails because array facts are not emitted.
- [ ] **Step 3: Add endpoint-specific extraction** for `traffic_search_details`, including array-length validation, year rollover resolution, request-shape sanitization, and one fact group per target date.
- [ ] **Step 4: Run** `node --test tests/automation/ctrip_capture_catalog.test.mjs` and confirm the new contract passes.

### Task 2: Feed request context from both capture paths

**Files:**
- Modify: `scripts/ctrip_browser_capture.mjs`
- Modify: `scripts/ctrip_cookie_api_capture.mjs`
- Modify: `tests/automation/ctrip_capture_catalog.test.mjs`

- [ ] **Step 1: Add assertions** that only `platform`, `dataType`, `searchType`, and `spiderVersion` reach stored request metadata.
- [ ] **Step 2: Pass sanitized request payload context** from browser response listeners and Cookie API execution into `extractCtripCatalogFacts`.
- [ ] **Step 3: Redact dynamic signature fields** from Cookie capture response summaries before they can be written.
- [ ] **Step 4: Run** `node --check scripts/ctrip_browser_capture.mjs`, `node --check scripts/ctrip_cookie_api_capture.mjs`, and the catalog test.

### Task 3: Make the endpoint a preferred one-click daily item

**Files:**
- Modify: `public/ctrip-static.js`
- Modify: `scripts/lib/ctrip_capture_catalog.mjs`
- Modify: `tests/automation/ctrip_capture_catalog.test.mjs`
- Modify: `scripts/verify_ota_diagnosis_auto_fetch.mjs`

- [ ] **Step 1: Add failing preferred-preset assertions** for four POST request templates using the observed datacenter endpoint and stable payload fields only.
- [ ] **Step 2: Replace the single empty search-flow preset** with four request entries and keep them inside the existing `traffic_report` preferred/core collection group.
- [ ] **Step 3: Add explicit Profile interaction steps** for `累计搜索数据` and `昨日搜索数据` so one Profile run triggers both windows.
- [ ] **Step 4: Run** `node --check public/ctrip-static.js`, `npm.cmd run verify:ota-diagnosis-auto-fetch`, and the catalog verifier.

### Task 4: Preserve target-date rows through persistence

**Files:**
- Modify: `scripts/lib/ctrip_capture_catalog.mjs`
- Modify: `app/controller/concern/AutoFetchConcern.php` only if the existing standard-row save contract needs a narrow compatibility adjustment
- Test: `tests/OnlineDataTest.php`

- [ ] **Step 1: Add a persistence-contract test** for distinct dimensions across target date, cumulative/yesterday, and self/competitor.
- [ ] **Step 2: Ensure standard rows use the capture date** as `data_date`, keep the full target date in `raw_data.dimension_values.target_date`, and generate an idempotent dimension containing all three business dimensions.
- [ ] **Step 3: Keep all-zero rows** as captured numeric facts and retain null order fields as missing metadata.
- [ ] **Step 4: Run** `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --filter OnlineDataTest` and the Node catalog test.

### Task 5: Expose the latest complete search snapshot

**Files:**
- Create: `app/controller/concern/CtripSearchOpportunityConcern.php`
- Modify: `app/controller/OnlineData.php`
- Modify: `route/app.php`
- Test: `tests/OnlineDataTest.php`

- [ ] **Step 1: Add controller tests** for hotel scoping, latest capture-date selection, four-scope completeness, partial/missing states, and no cross-hotel rows.
- [ ] **Step 2: Add** `GET /api/online-data/ctrip/search-opportunity` with `system_hotel_id` and optional `data_date` filters.
- [ ] **Step 3: Decode only `traffic_search_details` standard rows**, return 30 target dates with `self/competitor × cumulative/yesterday`, and include collection/quality status.
- [ ] **Step 4: Run PHP syntax checks**, route coverage, and the focused PHPUnit test.

### Task 6: Build derivations and the traffic-page panel

**Files:**
- Create: `public/ctrip-search-opportunity-static.js`
- Create: `tests/automation/ctrip_search_opportunity_static.test.mjs`
- Modify: `public/index.html`

- [ ] **Step 1: Write failing helper tests** for traffic gap rate, browse intensity, conversion gap, yesterday contribution, chase space, hot-date ranking, and the four opportunity classes, including divide-by-zero behavior.
- [ ] **Step 2: Implement pure helper functions** in `ctrip-search-opportunity-static.js` and make unavailable calculations return `null` with a readable missing reason.
- [ ] **Step 3: Add one panel** under the existing Ctrip `流量数据` tab with overview cards, cumulative/yesterday and PV/UV/conversion switches, the comparison chart, target-date opportunity table, action text, freshness, and failure status.
- [ ] **Step 4: Load the panel from the focused API** during the existing one-click result refresh and when the user opens the traffic tab.
- [ ] **Step 5: Run** the helper test, `node --check public/ctrip-search-opportunity-static.js`, and `npm.cmd run verify:public-entry`.

### Task 7: Verify the complete loop

**Files:**
- Modify only if required by failed focused checks.

- [ ] **Step 1: Run** `npm.cmd run verify:ctrip-capture-catalog`.
- [ ] **Step 2: Run** `npm.cmd run verify:ota-diagnosis-auto-fetch`.
- [ ] **Step 3: Run** `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --filter OnlineDataTest`.
- [ ] **Step 4: Run** `npm.cmd run verify:public-entry` and the new UI helper test.
- [ ] **Step 5: Verify** `http://127.0.0.1:8080/api/health`, refresh the in-app browser, open the Ctrip traffic page, and confirm the panel renders either real stored facts or an explicit not-collected/partial state.
- [ ] **Step 6: Inspect the focused diff** and confirm no Cookie, Authorization, token, `spiderkey`, hotel identifier from the supplied evidence, or unrelated workspace changes were added.
