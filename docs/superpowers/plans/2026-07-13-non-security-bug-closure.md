# Non-Security Bug Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every still-reproducible non-security bug from the 2026-07-13 audit without changing the product architecture or inventing OTA facts.

**Architecture:** Keep Ctrip, Meituan, Profile, analysis, and UI flows in their existing modules. Tighten each contract at its current source, add only small local helpers, and retain same-day proof as the strict downstream truth gate while allowing the already-designed Profile reuse window at collection entry points.

**Tech Stack:** PHP 8.2/ThinkPHP, PHPUnit, Vue 3 runtime entry, Node test runner, PowerShell, GitHub Actions

---

## Acceptance checks

- Non-persisted Ctrip/Meituan results cannot be reported as persisted success.
- Missing OTA metrics stay missing; numeric zero remains numeric zero.
- Profile reuse, credential migration, MacroSignal cards, hotel switching, dates, units, checkboxes, and connection states match the approved design.
- Native pre-commit failures propagate and CI executes every Node `*.test.mjs` contract file.
- Problem 11 remains closed through the existing split-source verifier; no duplicate change is made.

## Task 1: Close Ctrip and Meituan persistence truth gaps

**Files:**

- Modify: `tests/CtripCompetitionCirclePersistenceServiceTest.php`
- Modify: `tests/OtaCredentialReadPathTest.php`
- Modify: `tests/CtripTemporaryCookieQueryTest.php`
- Modify: `tests/MeituanOnlineDataPersistenceServiceTest.php`
- Modify: `app/service/CtripCompetitionCirclePersistenceService.php`
- Modify: `app/controller/concern/OnlineDataManualFetchConcern.php`

- [ ] **Step 1: Add failing regressions**

Add assertions equivalent to:

```php
$this->assertFalse($payload['data']['persisted']);
$this->assertSame('blocked', $payload['data']['save_status']);
$this->assertNotSame('persisted_success', $payload['data']['result_status']);

$this->assertSame(0, $realZeroResult['saved_count']); // value stays 0 in the row, not missing
$this->assertSame(0, $invalidMetricResult['saved_count']); // null/--/text row is rejected

$this->assertSame('persistence_failed', $zeroSaveResponse['data']['reason']);
$this->assertSame('readback_mismatch', $mismatchResponse['data']['reason']);
```

- [ ] **Step 2: Prove RED**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\CtripCompetitionCirclePersistenceServiceTest.php tests\OtaCredentialReadPathTest.php tests\CtripTemporaryCookieQueryTest.php tests\MeituanOnlineDataPersistenceServiceTest.php
```

Expected: new assertions fail because non-saved results still use success semantics, invalid metrics become zero, and direct Meituan saves lack readback enforcement.

- [ ] **Step 3: Implement the minimal contract changes**

Use explicit result fields on manual fetch responses:

```php
'persisted' => $autoSave && $savedCount > 0 && $readbackVerified,
'result_status' => !$autoSave
    ? 'display_only'
    : ($savedCount > 0 && $readbackVerified ? 'persisted_success' : 'persistence_failed'),
```

Background notifications must derive `success` from `persisted`, not request completion. Change Ctrip numeric extraction to return `?float`; required business signature accepts `0` but rejects missing or non-numeric values. After every direct Meituan save, call the existing readback verifier and return an explicit non-success result for zero saves or mismatches.

- [ ] **Step 4: Prove GREEN and commit**

Run the Step 2 command, PHP syntax-check both production files, then commit only this task.

## Task 2: Align Profile reuse and credential migration

**Files:**

- Modify: `tests/OtaProfileSessionProofConsumerTest.php`
- Modify: `tests/OtaCredentialMigrationServiceTest.php`
- Modify: `app/controller/concern/AutoFetchConcern.php`
- Modify: `app/service/OtaCredentialMigrationService.php`

- [ ] **Step 1: Add failing regressions**

```php
$this->assertTrue($dayNine['is_reusable']);
$this->assertFalse($dayTen['is_reusable']);
$this->assertSame('login_expired', $expiredStatus);
$this->assertSame('waiting_login', $unverifiedStatus);
$this->assertCount(1, $readyCredentialsForHotelAndPlatform);
$this->assertSame('superseded', $olderConfig['credential_status']);
```

Include direct Ctrip and Meituan auto-fetch consumers so a 1–9 day authoritative Profile reaches collection while same-day P0 checks still reject yesterday's proof.

- [ ] **Step 2: Prove RED**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OtaProfileSessionProofConsumerTest.php tests\OtaCredentialMigrationServiceTest.php
```

- [ ] **Step 3: Implement the minimal changes**

Replace collection-entry `isCurrentVerified()` checks with:

```php
$reuse = (new OtaProfileSessionProofService())->profileReuseState($source);
if (empty($reuse['is_reusable'])) {
    return $reuse['status'] === 'expired' ? 'login_expired' : 'profile_session_unverified';
}
```

Keep all P0, target-date, revenue, and AI consumers on `isCurrentVerified()`. Group migration candidates by normalized `system_hotel_id|platform`, select one deterministic canonical enabled/bound candidate, migrate only it to `ready`, and mark the remainder `superseded` so configuration metadata matches vault revocation.

- [ ] **Step 4: Prove GREEN and commit**

Run the Step 2 command plus `tests/OtaProfileSessionProofServiceTest.php`; syntax-check both production files and commit this task.

## Task 3: Remove synthetic prediction and make MacroSignal failures truthful

**Files:**

- Modify: `tests/MacroSignalServiceTest.php`
- Modify: `tests/OnlineDataTest.php`
- Modify: `tests/automation/ctrip_competition_circle_closure.test.mjs`
- Modify: `app/service/MacroSignalService.php`
- Modify: `app/controller/concern/BusinessDisplayConcern.php`
- Modify: `public/app-main.js`
- Modify: `resources/frontend/app-template.html`

- [ ] **Step 1: Add failing regressions**

```php
$this->assertNull($row['aiEstimatedTotalRoomNights']);
$this->assertSame('missing', $revenueCard['status']);
$this->assertSame('missing', $demandCard['status']);
$this->assertSame('read_failed', $failedRead['status']);
```

```js
assert.equal(estimateWithoutSource, null);
assert.match(renderedMissingValue, /未返回/);
assert.doesNotMatch(frontendSource, /1\.15\s*\+|hash\s*%\s*21/);
```

- [ ] **Step 2: Prove RED**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\MacroSignalServiceTest.php tests\OnlineDataTest.php
node --test tests\automation\ctrip_competition_circle_closure.test.mjs
```

- [ ] **Step 3: Implement the minimal changes**

Remove CRC-derived fallbacks on both backend and frontend. Preserve only a sourced numeric `aiEstimatedTotalRoomNights`; otherwise use `null` and render `未返回`. Change MacroSignal reads to return data plus read status, and pass each card its own sample availability:

```php
$revenueHasSamples = $this->hasNumericSamples($series, ['revenue']);
$demandHasSamples = $this->hasNumericSamples($series, ['forecast_room_nights']);
```

Do not sum null values into zero and do not label a demand prediction available without demand samples.

- [ ] **Step 4: Rebuild generated frontend artifacts, prove GREEN, and commit**

```powershell
npm.cmd run build:frontend-entry
npm.cmd run build:frontend-template
```

Run Step 2 and both frontend build verifiers before committing this task.

## Task 4: Fix frontend state, race, zero, coverage, checkbox, and unit behavior

**Files:**

- Modify: `tests/automation/ctrip_competition_circle_closure.test.mjs`
- Modify: `tests/automation/hotel_ota_status_badges.test.mjs`
- Modify: `tests/automation/operation_static_bootstrap.test.mjs`
- Modify: `tests/automation/runtime_error_isolation.test.mjs`
- Modify: `public/app-main.js`
- Modify: `public/dual-ota-home-static.js`
- Modify: `public/operation-static.js`
- Modify: `public/form-operation-support.js`
- Modify: `resources/frontend/app-template.html` only if displayed labels change

- [ ] **Step 1: Add failing behavior tests**

Cover these exact cases:

```js
assert.equal(resultAfterResponsesBThenA.hotelId, 'B');
assert.equal(resolveSelfHotel([{ name: '本店' }], null), null);
assert.equal(displayMetric(0), '0');
assert.equal(connectionSummary([]).allConnected, false);
assert.deepEqual(openingCoverage(tenItemsWithEightSuggestions), { covered: 8, total: 10, rate: 80 });
assert.equal(restoredCheckbox.checked, false);
assert.equal(formatConversionGap(2.5), '2.5 个百分点');
```

- [ ] **Step 2: Prove RED**

```powershell
node --test tests\automation\ctrip_competition_circle_closure.test.mjs tests\automation\hotel_ota_status_badges.test.mjs tests\automation\operation_static_bootstrap.test.mjs tests\automation\runtime_error_isolation.test.mjs
```

- [ ] **Step 3: Implement minimal local fixes**

Capture the requested hotel ID or sequence before each Ctrip request and discard responses that no longer match the selected hotel. Require stable hotel identity fields; do not infer self from only `本店` or array position. Replace `value || '-'` with nullish/empty checks. Default both OTA connections to disconnected and populate them from actual readiness input. Compute opening coverage before `.slice(0, 6)`. Assign checkbox `checked = Boolean(savedValue)` whenever the draft contains the key. Render conversion gaps with `个百分点`.

- [ ] **Step 4: Rebuild, prove GREEN, and commit**

Rebuild frontend entry/template artifacts, run Step 2, `npm.cmd run verify:public-entry`, and commit this task.

## Task 5: Correct local business dates and Conditional GET precedence

**Files:**

- Create: `tests/automation/local_business_date.test.mjs`
- Create: `tests/PublicEntryConditionalGetTest.php`
- Modify: `public/app-main.js`
- Modify: `scripts/report_revenue_ai_ctrip_external_input_candidates.mjs`
- Modify: `route/app.php`

- [ ] **Step 1: Add failing tests**

```js
assert.equal(localDateKey(new Date('2026-07-12T16:30:00.000Z'), 'Asia/Shanghai'), '2026-07-13');
```

```php
$this->assertSame(200, $this->conditionalStatus('"different"', $lastModified));
$this->assertSame(304, $this->conditionalStatus('', $lastModified));
```

- [ ] **Step 2: Prove RED**

```powershell
node --test tests\automation\local_business_date.test.mjs
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\PublicEntryConditionalGetTest.php
```

- [ ] **Step 3: Implement**

Use one local-calendar helper for business defaults and preserve `toISOString()` only for timestamps. In the route, evaluate `If-Modified-Since` only when `If-None-Match` is absent:

```php
$notModified = $ifNoneMatch !== ''
    ? hash_equals($etag, $normalizedIfNoneMatch)
    : $ifModifiedSinceMatches;
```

- [ ] **Step 4: Rebuild, prove GREEN, and commit**

Run both Step 2 commands, route coverage, frontend build verifiers, and commit this task.

## Task 6: Make pre-commit and CI fail closed

**Files:**

- Modify: `tests/automation/public_entry_precommit_guard.test.mjs`
- Create: `tests/automation/node_contract_runner.test.mjs`
- Modify: `hooks/pre-commit.ps1`
- Create: `scripts/run_node_contract_tests.mjs`
- Modify: `package.json`
- Modify: `.github/workflows/php.yml`

- [ ] **Step 1: Add failing runtime tests**

The Hook test must execute a controlled native command that exits `7`, assert the Hook exits non-zero, and assert no `Pre-commit hook checks passed` line is emitted. The runner test must enumerate every top-level `tests/automation/*.test.mjs`, exclude `.spec.js`, and prove the CI command references the runner.

- [ ] **Step 2: Prove RED**

```powershell
node --test tests\automation\public_entry_precommit_guard.test.mjs tests\automation\node_contract_runner.test.mjs
```

- [ ] **Step 3: Implement**

Wrap each native Hook command:

```powershell
function Invoke-CheckedNative {
    param([scriptblock]$Command)
    & $Command
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
```

Create a cross-platform Node runner that sorts and passes all `*.test.mjs` paths to `node --test`, add `test:node` to `package.json`, and call `npm run test:node` from CI after Node setup/dependency installation.

- [ ] **Step 4: Prove GREEN and commit**

Run Step 2, `npm.cmd run test:node`, and the real Hook; then commit this task.

## Task 7: Integrated verification and stop

**Files:** Verify only; do not change unrelated modules or external data.

- [ ] Rebuild and verify both generated frontend artifacts.
- [ ] Syntax-check every changed PHP, JS, MJS, JSON, YAML, and PowerShell file with the existing project commands.
- [ ] Run full PHPUnit, `npm.cmd run test:node`, `npm.cmd run verify:p0-guards`, `npm.cmd run review:non-security`, `npm.cmd run verify:e2e-contracts`, and PHP route coverage.
- [ ] Start/check MySQL and the local stack only if not already healthy; verify `http://127.0.0.1:8080/api/health`.
- [ ] Refresh the local affected page when browser tooling is available. Do not use real OTA credentials or claim live collection/readback evidence without an authorized live run.
- [ ] Check `git diff --check`, inspect the complete diff against the 17-item scope, and stop. Do not continue into security or unrelated cleanup.
