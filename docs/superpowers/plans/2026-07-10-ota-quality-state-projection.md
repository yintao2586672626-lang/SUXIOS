# OTA Quality State Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (\`- [ ]\`) syntax for tracking.

**Goal:** Expose a canonical OTA quality state on the existing collection-status surface without changing existing collection behavior, credential handling, routes, tables, or status fields.

**Architecture:** A new pure service maps the current Profile binding contract, target-date evidence, task state, field facts, and freshness into the seven knowledge-spec states. PlatformDataSourceConcern attaches the resulting read-only object to each existing platform row; legacy fields remain unchanged.

**Tech Stack:** PHP 8, ThinkPHP, PHPUnit, Node contract tests.

---

### Task 1: Define the pure quality-state contract with a failing test

**Files:**
- Create: tests/OtaCollectionQualityStateServiceTest.php

- [x] **Step 1: Write failing tests for all canonical states**

~~~php
$quality = (new OtaCollectionQualityStateService())->evaluate([
    'binding_check_status' => 'complete',
    'profile_status' => 'logged_in',
    'collection_status' => 'collected',
    'target_date' => '2026-07-09',
    'latest_data_date' => '2026-07-09',
    'target_date_traffic_rows' => 1,
    'field_fact_status' => 'ready',
]);
self::assertSame('available', $quality['primary_quality_state']);
~~~

The suite also asserts binding_missing, permission_denied, collection_failed, unverified, stale, and partial; each result must retain metric_scope=ota_channel and must not contain raw payload or credential fields.

- [x] **Step 2: Run the focused PHPUnit file**

Run: C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaCollectionQualityStateServiceTest.php

Expected: FAIL because OtaCollectionQualityStateService does not exist.

### Task 2: Implement the mapping service

**Files:**
- Create: app/service/OtaCollectionQualityStateService.php

- [x] **Step 1: Implement the state precedence**

~~~text
binding_missing
> permission_denied
> collection_failed
> unverified
> stale
> partial
> available
~~~

The return shape is:

~~~php
[
    'primary_quality_state' => 'available',
    'quality_flags' => [],
    'metric_scope' => 'ota_channel',
    'target_date' => '2026-07-09',
    'data_as_of' => '2026-07-09',
    'collected_at' => '',
    'evidence' => [],
    'next_action' => '',
]
~~~

- [x] **Step 2: Re-run the focused PHPUnit file**

Run: C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaCollectionQualityStateServiceTest.php

Expected: PASS.

### Task 3: Attach the contract to the existing collection-status endpoint

**Files:**
- Modify: app/controller/concern/PlatformDataSourceConcern.php
- Create: tests/automation/ota_quality_state_contract.test.mjs

- [x] **Step 1: Extend each platform row additively**

Use the existing profile binding contract, profile status, collection status, target-date rows, field facts, latest stored date, collection time, and failure reason. Add only a nested quality object; retain collectionStatus, failureReason, and all existing fields exactly as before.

- [x] **Step 2: Add a static contract test**

The test asserts that the controller imports OtaCollectionQualityStateService, evaluates every platform row, exposes row.quality, and leaves collectionStatus in place.

- [x] **Step 3: Run the Node contract test**

Run: node --test tests/automation/ota_quality_state_contract.test.mjs

Expected: PASS.

### Task 4: Run focused regression checks

**Files:**
- Test: tests/OtaCollectionQualityStateServiceTest.php
- Test: tests/automation/ota_quality_state_contract.test.mjs

- [x] **Step 1: Lint changed PHP files**

Run: C:\xampp\php\php.exe -l app\service\OtaCollectionQualityStateService.php

Expected: No syntax errors.

- [x] **Step 2: Run focused tests and platform contract**

Run:

~~~powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaCollectionQualityStateServiceTest.php
node --test tests/automation/ota_quality_state_contract.test.mjs
npm.cmd run verify:platform-data-source-contract
~~~

Expected: all commands exit 0.

### Task 5: Compatibility audit

**Files:**
- Inspect: app/controller/concern/PlatformDataSourceConcern.php
- Inspect: git diff --check

- [x] **Step 1: Confirm no prohibited scope**

No changes to public/index.html, route/app.php, database migrations, credential-vault files, or existing dirty files. Task 6 later adds only a safe task-statistics snapshot to PlatformDataSyncService.php.

- [x] **Step 2: Confirm response compatibility**

Existing collectionStatus, failureReason, dataCollected, fieldFactStatus, and profile fields remain present and unchanged; quality is additive.

### Task 6: Persist and safely project task-level verification evidence

**Files:**
- Modify: app/service/PlatformDataSyncService.php
- Modify: app/controller/concern/PlatformDataSourceConcern.php
- Modify: tests/PlatformDataSyncServiceTest.php
- Modify: tests/PlatformDataSourceQualityProjectionTest.php
- Modify: docs/knowledge/ota/ota-data-quality.md

- [x] **Step 1: Write failing task-quality tests**

Cover a fully verified browser Profile task, partial field facts, failed capture, and manual import. The test must prove that credential-like adapter text is not included in the quality snapshot.

- [x] **Step 2: Persist the safe snapshot in existing task stats**

Store `collection_quality` beside `sync_diagnostics` in `platform_data_sync_tasks.stats_json`. The snapshot only contains status codes, counts, dates, scope, and next action. It does not add a database table or store raw responses.

- [x] **Step 3: Project only the allowlisted snapshot to status consumers**

Expose `latestTask.collectionQuality` additively. The response projection allows only canonical states, safe flags, bounded counts, dates, and approved action codes; it drops arbitrary task-stat fields.

- [x] **Step 4: Re-run focused regression tests**

Run:

~~~powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/PlatformDataSyncServiceTest.php tests/PlatformDataSourceQualityProjectionTest.php
~~~

Expected: all tests pass, including tests that inject token/password-like fields into task stats and assert they are not projected.
