# OTA Manual Fetch Contract Standard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every manual OTA data entry use an exact result contract, preserve source-specific acquisition, complete the query-save-readback-display loop, and report the real failure stage instead of a generic credential error.

**Architecture:** Keep backend allowlists strict and keep each OTA/source adapter independent. Add one frontend registry for strict manual execution endpoints so generic business context is not injected, and add typed backend stage errors only for manual credential execution while leaving automatic collectors unchanged. API/Cookie, Browser Profile, plugin, Python automation, parser, and manual import may coexist; they converge only at the stable result contract.

**Tech Stack:** Vue-compatible inline JavaScript, Node test runner, PHP 8, ThinkPHP, PHPUnit.

---

## Stable result contract

Collection implementations are deliberately source-specific. A Ctrip API call, a Meituan page flow, a browser-profile collector, a plugin, and a Python parser do not have to share request construction or parsing code.

Every implementation must converge on these checks before it can report success:

1. Bind the result to `platform`, `system_hotel_id`, the platform hotel identifier, `data_date`, `collected_at`, `source_method`, and verification status.
2. Validate source-specific success and required business fields before persistence; an HTTP 200 or a non-empty array alone is not success.
3. Save idempotently for the same hotel, platform, metric/section, and date, or use an explicit version policy when history must be retained.
4. Read the saved rows back and compare row count plus critical fields with the normalized collection result.
5. Display the verified date, source, saved count, and truthful failure stage. Never substitute stale rows, zeroes, empty arrays, or another hotel's data.
6. Keep complete Cookie/token/session values inside the credential boundary. Plugins and Python adapters do not bypass this rule.

The stable result contract is the common standard; the acquisition adapter remains free to use the method that is most reliable for its specific source.

### Data truth boundary

Data existence and factual availability are separate decisions:

- An API response, visible page value, non-empty array, saved historical row, or user-provided file proves only that evidence exists.
- `available` requires verified source, `system_hotel_id`, platform hotel/POI, target date, metric definition, collection time, persistence, and database readback.
- `partial` means only the verified fields may be used; missing fields remain explicit.
- `stale` is historical evidence and cannot be presented as the requested current date.
- `unverified` includes user-provided or imported material whose source, binding, date, or completeness has not been reconciled.
- `binding_missing`, `permission_denied`, and `collection_failed` are blocked states, not zero-valued business results.
- Derived metrics must be labeled `derived` and retain references to their input facts and quality states.
- Synthetic/demo values must stay outside real OTA snapshots, revenue inputs, and AI decision inputs.

No acquisition path may report success merely because it produced output. The response shown to the user must include the actual data date, source method, quality state, saved row count, and readback result.

### Acquisition choice

Codex selects the acquisition method autonomously for the current hotel, source, authorization state, and requested date. The normal preference is:

1. Authorized structured API/Cookie execution.
2. Reusable browser Profile with verified login state.
3. An installed plugin whose capability directly matches the source.
4. Python automation or a source-specific page parser.
5. User-authorized manual import.

This is a decision preference, not a forced pipeline. Prefer the path with the strongest current evidence, fewest moving parts, and highest repeatable success rate. Run one primary path at a time, keep at most one justified fallback, record the true failure stage before switching, and stop as soon as save-readback-display verification passes.

### Task 1: Standardize strict manual request endpoints

**Files:**
- Modify: `public/index.html`
- Test: `tests/automation/ota_platform_binding_p0_ui.test.mjs`

- [ ] **Step 1: Write the failing endpoint-matrix test**

Assert that the request layer declares every strict manual endpoint and excludes it before prefix-based business-context injection:

```js
for (const path of [
  '/online-data/fetch-ctrip',
  '/online-data/fetch-meituan',
  '/online-data/fetch-ctrip-traffic',
  '/online-data/ctrip/traffic',
  '/online-data/fetch-ctrip-cookie-api',
  '/online-data/fetch-ctrip-overview',
  '/online-data/fetch-ctrip-ads',
  '/online-data/fetch-meituan-traffic',
  '/online-data/fetch-meituan-orders',
  '/online-data/fetch-meituan-ads',
]) {
  assert.match(requestContextLayer, new RegExp(path.replaceAll('/', '\\\\/')));
}
assert.match(requestContextLayer, /STRICT_OTA_MANUAL_EXECUTION_PATHS\.has\(path\)/);
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `node --test tests/automation/ota_platform_binding_p0_ui.test.mjs`

Expected: FAIL because the strict endpoint registry does not exist.

- [ ] **Step 3: Add the exact endpoint registry**

Add `STRICT_OTA_MANUAL_EXECUTION_PATHS` beside `BUSINESS_CONTEXT_ENDPOINT_PREFIXES`, and return `false` from `shouldAttachBusinessContext` when the normalized request path is in that set. Keep explicit `withBusinessContext` overrides and all backend allowlists unchanged.

- [ ] **Step 4: Run the test and confirm it passes**

Run: `node --test tests/automation/ota_platform_binding_p0_ui.test.mjs`

Expected: all tests pass.

### Task 2: Preserve truthful manual execution stages

**Files:**
- Create: `app/service/OtaExecutionStageException.php`
- Modify: `app/controller/concern/OtaConfigConcern.php`
- Modify: `app/controller/concern/OnlineDataManualFetchConcern.php`
- Modify: `app/controller/concern/OnlineDataRequestConcern.php`
- Test: `tests/OtaCredentialReadPathTest.php`

- [ ] **Step 1: Write failing stage-classification tests**

Cover four outcomes for manual execution: authorization `403`, credential loading `409`, platform/data execution `502`, and result inspection `500`. Confirm ordinary automatic execution keeps its existing exception behavior.

- [ ] **Step 2: Run the focused tests and confirm they fail**

Run: `C:\xampp\php\php.exe vendor\bin\phpunit tests\OtaCredentialReadPathTest.php`

Expected: FAIL because manual stage classification does not exist.

- [ ] **Step 3: Implement typed manual stages**

Create `OtaExecutionStageException` with `stage()`, `safeMessage()`, and `httpStatus()`. Extend `withOtaCredentialForExecution` with an optional manual-stage flag: wrap credential lookup only as `credential`, consumer runtime failures as `platform_execution`, and leak inspection failures as `result_inspection`. Keep the default flag off for automatic collectors.

- [ ] **Step 4: Use the standard in all manual credential endpoints**

Enable manual-stage classification for Ctrip ranking, traffic, ads, Cookie/API, and overview, plus Meituan ranking, traffic, orders, and ads. Return `data.reason` and `data.stage` without exposing exception text or secrets.

- [ ] **Step 5: Run focused PHP verification**

Run: `C:\xampp\php\php.exe vendor\bin\phpunit tests\OtaCredentialReadPathTest.php`

Expected: all tests pass.

### Task 3: Verify the complete manual standard

**Files:**
- Verify only; no additional feature scope.

- [ ] **Step 1: Run syntax checks**

Run `C:\xampp\php\php.exe -l` for each modified PHP file.

Expected: no syntax errors.

- [ ] **Step 2: Run the targeted UI and credential suites**

Run the Node endpoint-matrix test, the PHPUnit credential read-path test, and the existing OTA credential vault verifier.

Expected: all checks pass and no secret appears in output.

- [ ] **Step 3: Refresh the local OTA page**

Refresh `http://127.0.0.1:8080/` and verify the manual OTA page loads. Do not trigger a new live OTA collection unless explicitly authorized with the selected hotel and current credential.

- [ ] **Step 4: Stop at the manual OTA boundary**

Report completed files, test results, and any unverified live-platform behavior. Do not continue into automatic collection, revenue, AI, or unrelated pages.
