# Online Data Manual Surface Focus Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the online-data page focused on OTA readiness, manual supplemental collection, results, and failure handling while removing the employee checklist, action tracking, Phase 3 operation loop, and duplicate diagnostics from this view.

**Architecture:** Preserve all backend routes, runtime ledgers, historical data, and collection behavior. Remove only the approved UI exposure and stop the data-health page from automatically loading patrol and Phase 3 resources that no longer have a visible consumer. Keep the read-only daily-workbench request because the readiness overview uses its target-date facts.

**Tech Stack:** Vue 3 CDN template in `public/index.html`, extracted browser helpers in `public/data-health-static.js`, Node test/contract scripts.

---

### Task 1: Lock the focused page contract

**Files:**
- Modify: `tests/automation/dashboard_hotel_data_cockpit.test.mjs`
- Modify: `tests/automation/data_health_static_summary.test.mjs`
- Modify: `scripts/verify_phase2_daily_workbench_contract.mjs`
- Modify: `scripts/verify_phase3_operation_effect_loop_contract.mjs`
- Modify: `scripts/verify_public_entry_guard.mjs`
- Modify: `scripts/verify_e2e_contracts.mjs`

- [x] **Step 1: Add the failing UI contract**

Require the online-data surface to contain:

```text
data-testid="ota-direct-view-overview"
data-testid="manual-one-click-fetch"
manualOneClickFetchCards
manualOneClickFetchDisplayRows
```

Reject these visible markers from the online-data page:

```text
data-testid="phase2-daily-workbench"
data-testid="daily-workbench-write-boundary"
data-testid="phase3-operation-effect-loop"
employeeOtaChecklistRows
```

Also require exactly one `@click="refreshManualOneClickFetchConfig"` control in the online-data page.

- [x] **Step 2: Add the failing refresh-job contract**

Keep `loadAutoFetchStatus` and `loadDailyWorkbench` in the core job list, but assert that neither light nor full refresh invokes:

```text
loadDailyWorkbenchPatrols
loadPhase3OperationEffectLoop
loadPhase3OperationEffectLoopLedger
```

- [x] **Step 3: Run RED verification**

Run:

```powershell
node --test tests\automation\dashboard_hotel_data_cockpit.test.mjs tests\automation\data_health_static_summary.test.mjs
node scripts\verify_phase2_daily_workbench_contract.mjs
node scripts\verify_phase3_operation_effect_loop_contract.mjs
node scripts\verify_public_entry_guard.mjs
```

Expected: fail only because the old Phase 2/Phase 3 panels and refresh jobs are still present and the new manual surface marker is absent.

### Task 2: Apply the minimal UI and refresh cleanup

**Files:**
- Modify: `public/index.html`
- Modify: `public/data-health-static.js`

- [x] **Step 1: Focus the manual collection card**

Change the retained card to:

```html
<div data-testid="manual-one-click-fetch" class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5 shadow-sm">
  <h4 class="text-base font-semibold text-gray-900">手动补采</h4>
  <div class="mt-1 text-xs text-gray-500 leading-5">仅对已有携程/美团配置的门店执行临时补采；自动采集仍在“配置：自动采集”维护。</div>
</div>
```

Retain the three platform fetch buttons, `manualOneClickFetchCards`, `manualOneClickFetchDisplayRows`, and failure edit/retry/delete/supplement actions.

- [x] **Step 2: Remove approved page-only modules**

Delete the employee checklist cards/table/action controls and the Phase 3 operation-effect-loop template block. Delete the second `刷新配置` and `完整诊断` buttons from the manual collection header; retain the refresh control in the readiness overview.

- [x] **Step 3: Stop hidden patrol and Phase 3 requests**

Reduce the base refresh jobs in `buildDataHealthPanelRefreshJobs` to:

```js
const jobs = [
  requireDataHealthPanelLoader(loadAutoFetchStatus, 'loadAutoFetchStatus')({ detail: isFull }),
  requireDataHealthPanelLoader(loadDailyWorkbench, 'loadDailyWorkbench')({ limit: 10 }),
];
```

Remove the corresponding patrol and Phase 3 loader arguments from the call in `public/index.html`. Keep all backend methods and dormant operator functions intact for compatibility.

- [x] **Step 4: Bump the static helper version**

Append `manual-surface-focus` to the `data-health-static.js` cache-busting version and synchronize the E2E version assertion.

### Task 3: Verify the focused surface

**Files:**
- Verify only; no new production files.

- [x] **Step 1: Run GREEN targeted checks and record the global gate result**

```powershell
node --test tests\automation\dashboard_hotel_data_cockpit.test.mjs tests\automation\data_health_static_summary.test.mjs
node scripts\verify_phase2_daily_workbench_contract.mjs
node scripts\verify_phase3_operation_effect_loop_contract.mjs
node scripts\verify_public_entry_guard.mjs
node scripts\verify_e2e_contracts.mjs
```

Result: the focused UI tests, Phase 2/Phase 3 contracts, public-entry guard, and runtime verifiers passed. The global E2E command remained blocked by the unrelated pre-existing `SystemConfigController.php` single-key read-order contract; that file was not modified for this plan.

- [x] **Step 2: Run syntax and diff checks**

```powershell
node --check public\data-health-static.js
node --check scripts\verify_phase2_daily_workbench_contract.mjs
node --check scripts\verify_phase3_operation_effect_loop_contract.mjs
git diff --check -- public/index.html public/data-health-static.js tests/automation/dashboard_hotel_data_cockpit.test.mjs tests/automation/data_health_static_summary.test.mjs scripts/verify_phase2_daily_workbench_contract.mjs scripts/verify_phase3_operation_effect_loop_contract.mjs scripts/verify_public_entry_guard.mjs scripts/verify_e2e_contracts.mjs
```

- [x] **Step 3: Verify the running page**

Refresh `http://127.0.0.1:8080/` in the local Browser. Confirm the readiness overview and manual supplement controls remain visible, the employee checklist and Phase 3 loop are absent, and `/api/health` returns HTTP 200.

No commit is included because the user did not request `save project`, and the existing dirty worktree contains unrelated active changes.
