# Local OTA Profile Login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Keep execution single-agent for this workspace.

**Goal:** Make the current Windows machine reliably open and persist a real Ctrip/Meituan browser Profile login, then show a truthful login/collection state after server readback.

**Architecture:** The web endpoint may launch an interactive Profile browser only for loopback requests. A login task owns one platform + system hotel + platform store identity, launches the existing persistent Chromium profile, and records a same-day verified-session proof only after the platform login probe succeeds. Profile-directory existence and historical metadata never count as verified login.

**Tech Stack:** ThinkPHP 8/PHP, Vue 3 CDN frontend, Node.js Playwright collectors, MySQL, PHPUnit, Node test runner.

---

## Scope and acceptance checks

- Current machine acts as both server and account-owner computer.
- Molanxin (system hotel `80`) Meituan authorization is the first manual acceptance target.
- A loopback request opens the corresponding platform login page in an interactive persistent browser.
- Repeated clicks while the same login task is active return/focus the existing task instead of launching another browser.
- Successful platform verification saves the Profile, binds it to the exact hotel/platform/store scope, and persists a same-day session proof that can be read back.
- The UI reports `自动可采集` only with a saved platform identity plus a current verified Profile session; otherwise it reports `待授权验证` or `手动可采集` according to the actual path.
- Remote-device login, device pairing, profile upload/sync, business-data duplication, and stale-profile cleanup are explicitly out of scope.

### Task 1: Guard the truthful account-state contract

**Files:**
- Modify: `public/system-static.js`
- Modify: `public/index.html`
- Test: `tests/automation/platform_account_collection_mode.test.mjs`
- Test: `tests/automation/hotel_platform_login_verification.test.mjs`

1. Add/adjust a failing test proving that a Profile path, historical login flag, or non-empty Profile directory without current-session proof is not `logged_in` and not `auto_ready`.
2. Run:
   - `node --test tests/automation/platform_account_collection_mode.test.mjs`
   - `node --test tests/automation/hotel_platform_login_verification.test.mjs`
3. Make the smallest mapping/UI change so the tests pass and the card exposes the actual next action.
4. Re-run both tests and confirm no account is described as automatically collectible from directory existence alone.

### Task 2: Make local authorization launch reliable and idempotent

**Files:**
- Modify: `app/controller/concern/OnlineDataRequestConcern.php`
- Modify: `app/controller/concern/AutoFetchConcern.php`
- Modify: `public/index.html`
- Test: `tests/automation/platform_profile_local_login.test.mjs`

1. Add/adjust a failing test covering loopback-only launch, duplicate-submit suppression, task polling, and explicit remote-client-required behavior.
2. Run `node --test tests/automation/platform_profile_local_login.test.mjs` and confirm the intended failure before implementation when applicable.
3. Finalize the existing local launcher so Windows confirms the task actually leaves `queued`, reuses an active equivalent task, and returns a truthful startup failure when it cannot open the browser.
4. Re-run the test and PHP syntax checks for both controller concerns.

### Task 3: Bind and persist verified Profile proof safely

**Files:**
- Modify: `app/command/PlatformProfileLogin.php`
- Modify: `app/service/OtaProfileBindingService.php`
- Test: `tests/OtaProfileBindingServiceTest.php`

1. Add/adjust a failing PHP test proving an unbound existing local Profile can only be claimed through the explicit local-login path and cannot cross hotel/platform/store scope.
2. Run `vendor/bin/phpunit tests/OtaProfileBindingServiceTest.php`.
3. Finalize the login command so only an actual `logged_in`/`authorized` probe records current-session proof, after authoritative binding succeeds.
4. Re-run PHPUnit and PHP syntax checks for the command and binding service.

### Task 4: Verify the real Molanxin Meituan login loop

**Files/readback:**
- Inspect: `runtime/platform_profile_login/<task-id>/status.json`
- Inspect safe status fields only from the bound `platform_data_sources` row.
- Do not print Cookie, token, password, raw browser storage, or full credential JSON.

1. Verify `http://127.0.0.1:8080/api/health` before browser acceptance.
2. From the Molanxin Meituan card, click `授权登录`; complete login in the opened persistent browser.
3. Confirm the task reaches a successful terminal state and records platform `meituan`, system hotel `80`, store/POI `1029642156589279`, the expected Profile path hash, and a same-day verified-session proof.
4. Refresh the local page and confirm Molanxin Meituan changes from `待授权验证` to `自动可采集`; do not claim actual OTA data collection yet.
5. Stop after this login/save/readback loop. Leave remote login, storage migration, data collection, and old Profile cleanup for separate approval.

## Focused verification

```powershell
node --test tests/automation/platform_profile_local_login.test.mjs
node --test tests/automation/platform_account_collection_mode.test.mjs
node --test tests/automation/hotel_platform_login_verification.test.mjs
vendor/bin/phpunit tests/OtaProfileBindingServiceTest.php
php -l app/command/PlatformProfileLogin.php
php -l app/controller/concern/AutoFetchConcern.php
php -l app/controller/concern/OnlineDataRequestConcern.php
php -l app/service/OtaProfileBindingService.php
git diff --check -- app/command/PlatformProfileLogin.php app/controller/concern/AutoFetchConcern.php app/controller/concern/OnlineDataRequestConcern.php app/service/OtaProfileBindingService.php public/index.html public/system-static.js tests/OtaProfileBindingServiceTest.php tests/automation/platform_account_collection_mode.test.mjs tests/automation/platform_profile_local_login.test.mjs tests/automation/hotel_platform_login_verification.test.mjs
```
