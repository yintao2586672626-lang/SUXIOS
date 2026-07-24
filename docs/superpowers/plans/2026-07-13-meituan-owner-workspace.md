# Meituan Owner Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate the Meituan owner-facing UI around competition-circle data and stored operating data while moving technical collection controls out of the primary path.

**Architecture:** Keep every existing backend route and data contract intact. Change only the frontend information architecture: competition circle remains the primary work page, stored traffic/order/advertising rows become one operating-data page, and manual URL/CSV/API tools remain available only inside a collapsed super-admin area. Generated frontend artifacts are rebuilt from the canonical template.

**Tech Stack:** Vue 3 CDN template, static JavaScript helpers, Node test runner, local PHP application.

---

### Task 1: Lock the owner-facing navigation contract

**Files:**
- Create: `tests/automation/meituan_owner_workspace.test.mjs`
- Test: `tests/automation/meituan_owner_workspace.test.mjs`

- [ ] **Step 1: Write the failing test**

Assert that the Meituan page exposes only `竞争圈`, `经营数据`, and `账号设置` as primary owner actions; technical traffic/order/advertising collection remains in a collapsed super-admin block; raw data tools and destructive stored-row actions are not primary owner actions; the generic one-click label truthfully says it updates the competition circle.

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test tests/automation/meituan_owner_workspace.test.mjs`

Expected: FAIL because the current page still presents traffic, orders, and advertising as equal top-level tabs and labels the ranking-only action as full Meituan acquisition.

### Task 2: Consolidate the Meituan owner workspace

**Files:**
- Modify: `resources/frontend/app-template.html`
- Generated: `public/app-render.min.js`
- Generated: `public/index.html`
- Test: `tests/automation/meituan_owner_workspace.test.mjs`

- [ ] **Step 1: Implement the minimal template change**

Keep competition-circle query/save/readback behavior unchanged. Replace the six-way primary navigation with three owner actions, move manual traffic/order/advertising controls into a closed super-admin details panel, rename the stored-data center to `经营数据`, move raw JSON/copy controls into an advanced block, and show stored-row deletion only to super administrators.

- [ ] **Step 2: Rebuild generated frontend artifacts**

Run: `npm.cmd run build:frontend-template`

Expected: `public/app-render.min.js` and its version reference in `public/index.html` match the canonical template.

- [ ] **Step 3: Run the focused test**

Run: `node --test tests/automation/meituan_owner_workspace.test.mjs`

Expected: PASS.

### Task 3: Verify compatibility and local behavior

**Files:**
- Verify: `resources/frontend/app-template.html`
- Verify: `public/app-render.min.js`
- Verify: `public/index.html`

- [ ] **Step 1: Run existing Meituan and entry contracts**

Run: `node --test tests/automation/manual_minimum_credential_ui.test.mjs tests/automation/meituan_background_result_ui.test.mjs tests/automation/meituan_browser_capture_gate.test.mjs tests/automation/meituan_ingestion_contract.test.mjs`

Run: `npm.cmd run verify:public-entry`

Expected: all checks pass with no production-route or data-contract changes.

- [ ] **Step 2: Verify the local page in the Codex browser**

Reload `http://127.0.0.1:8080/`, open the Meituan page after the user signs in, and confirm the primary navigation is reduced while competition-circle query and stored-data viewing remain reachable.

- [ ] **Step 3: Stop at the requested loop**

Report implemented UI consolidation separately from still-unverified live Meituan collection. Do not run real OTA collection, write production data, or unlock review-to-order/phone matching.
