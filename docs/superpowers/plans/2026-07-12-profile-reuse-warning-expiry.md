# OTA Profile Reuse Warning and Expiry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow a verified Ctrip or Meituan browser Profile to drive automatic collection for 10 days, warn from day 7, force re-login from day 10, and expire immediately when the platform reports a real authentication failure.

**Architecture:** Keep the existing same-day `isCurrentVerified()` proof as the strict P0/data-quality truth gate. Add an independent Profile reuse decision in `OtaProfileSessionProofService`, consume it only at Profile collection-entry and account-readiness boundaries, and expose truthful reusable/warning/expired states to the existing UI without storing new credentials or weakening hotel/platform binding checks.

**Tech Stack:** PHP 8.2/ThinkPHP, PHPUnit, Vue 3 CDN, Node test runner

---

## Acceptance checks

- A real proof aged 0–6 days is reusable and automatic collection is allowed without a prompt.
- A real proof aged 7–9 days remains reusable, is counted as automatic collection, and displays a renewal warning.
- A proof aged 10 days or more is blocked and asks the user to log in again.
- `login_required`, `session_expired`, 401/403, or equivalent stored authentication failures block reuse immediately, even before day 10.
- A forged, cross-hotel, cross-platform, or incomplete proof remains unverified.
- `isCurrentVerified()` still requires a same-day proof so P0, target-date field closure, revenue analysis, and AI truth gates remain unchanged.
- Existing Dunhuang Molanxin Ctrip and Meituan proofs from 2026-07-11 become reusable on 2026-07-12 without another login.
- Stop after the Profile reuse, warning, blocking, status display, and focused verification loop is complete.

## Task 1: Add the Profile reuse policy to the proof service

**Files:**

- Modify: `tests/OtaProfileSessionProofServiceTest.php`
- Modify: `app/service/OtaProfileSessionProofService.php`

- [ ] Add PHPUnit cases for day 0, day 6, day 7, day 9, and day 10 using the injected clock already used by the test fixture.
- [ ] Assert `isCurrentVerified()` becomes false on the next calendar day while the new reuse state remains `reusable`; this protects the strict truth gate from accidental weakening.
- [ ] Add cases for an explicit login/session failure before day 7 and for tampered tenant, hotel, platform, and proof hash values.
- [ ] Run the focused test and confirm the new assertions fail before production code changes:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaProfileSessionProofServiceTest.php
```

- [ ] Add constants for the warning day (`7`) and forced-login day (`10`).
- [ ] Add `profileReuseState(array $source): array` returning stable fields:

```php
[
    'status' => 'reusable|renewal_warning|expired|unverified',
    'is_reusable' => true,
    'age_days' => 1,
    'days_until_forced_login' => 9,
    'warning' => false,
    'reason' => 'profile_proof_reusable',
]
```

- [ ] Reuse the authoritative scope, binding, and hash validation from the same-day proof path. Do not accept `manual_login_state_verified`, a directory existing on disk, or historical collection success as authorization.
- [ ] Give explicit stored authentication failures precedence over proof age. Recognize stable status/error evidence such as `login_required`, `session_expired`, `login_expired`, `auth_failed`, `unauthorized`, `forbidden`, 401/403, and equivalent Chinese login-expiry messages.
- [ ] Keep `isCurrentVerified()` semantics unchanged and rerun the focused PHPUnit file until green.

## Task 2: Use the reuse decision only at Profile collection boundaries

**Files:**

- Modify: `tests/OtaProfileSessionProofConsumerTest.php`
- Modify: `tests/OnlineDataTest.php`
- Modify: `app/controller/concern/AutoFetchConcern.php`
- Modify: `app/controller/concern/PlatformProfileCaptureConcern.php`
- Modify: `app/service/PlatformDataSyncService.php`

- [ ] Add or update consumer tests proving a recent authoritative proof is collectable, a 7–9 day proof is still collectable, a 10-day proof is rejected, and manual/historical flags alone remain rejected.
- [ ] Add a regression assertion that P0/diagnostic consumers still require `isCurrentVerified()` and do not treat reuse as same-day evidence.
- [ ] Run the affected consumer tests and confirm the changed expectations fail first:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaProfileSessionProofConsumerTest.php tests/OnlineDataTest.php
```

- [ ] In `filterCollectableBrowserProfileDataSources()`, the direct Ctrip/Profile auto-fetch path, and the direct Meituan/Profile auto-fetch path, allow only `profileReuseState(...).is_reusable`.
- [ ] Include `last_sync_status` and `last_error` when loading the source used by direct Profile auto-fetch so real expiry evidence can override the age window.
- [ ] In `profileCookieSourceLoginMissingRequirements()` and `browserProfileBackgroundSyncLoginMissingRequirements()`, use the reuse decision for collection readiness and return explicit `profile_session_unverified` or `profile_session_expired` blockers.
- [ ] Keep same-day proof checks used by P0 quality snapshots, diagnostic truth, sanitized `current_session_verified`, target-date evidence, revenue analysis, and AI inputs unchanged.
- [ ] Map collection status to `logged_in`, `profile_reusable`, `renewal_warning`, `login_expired`, or `waiting_login`, preserving binding, permission, anti-bot, and real authentication-error precedence.
- [ ] Rerun both PHPUnit files until green.

## Task 3: Show automatic, warning, and expired states truthfully

**Files:**

- Modify: `tests/PlatformProfileBindingReadinessServiceTest.php`
- Modify: `tests/automation/platform_account_collection_mode.test.mjs`
- Modify: `tests/automation/ctrip_store_data_overview.test.mjs` only if its existing status contract is affected
- Modify: `app/service/PlatformProfileBindingReadinessService.php`
- Modify: `public/auto-fetch-static.js`
- Modify: `public/system-static.js`
- Modify targeted status mappings in: `public/index.html`

- [ ] Add backend readiness tests for `profile_reusable`, `renewal_warning`, `expired`, and the independent `current_session_verified` field.
- [ ] Add frontend tests proving both `auto_ready` and `renewal_warning` count as automatic collection, while only the warning state displays the renewal prompt.
- [ ] Run the focused tests and confirm they fail before implementation:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/PlatformProfileBindingReadinessServiceTest.php
node --test tests/automation/platform_account_collection_mode.test.mjs tests/automation/ctrip_store_data_overview.test.mjs
```

- [ ] Expose `profile_reusable`, `reuse_status`, `reuse_warning`, `profile_age_days`, and `days_until_forced_login` without relabeling them as same-day proof.
- [ ] Classify 0–6 days as `auto_ready`, 7–9 days as `renewal_warning`, and 10+ days as `login_expired`/waiting for login.
- [ ] Add concise UI labels: `自动可采集`, `自动可采集·建议续登`, and `登录已过期`; include both reusable states in automatic-channel totals and filters.
- [ ] Keep the existing page layout and principal data content unchanged.
- [ ] Rerun the PHP and Node tests until green.

## Task 4: Verify the real Molanxin state and regression boundary

**Files:**

- Verify only; do not edit unrelated files or generated reports.

- [ ] Run PHP syntax checks on every modified PHP production file:

```powershell
C:\xampp\php\php.exe -l app/service/OtaProfileSessionProofService.php
C:\xampp\php\php.exe -l app/controller/concern/AutoFetchConcern.php
C:\xampp\php\php.exe -l app/controller/concern/PlatformProfileCaptureConcern.php
C:\xampp\php\php.exe -l app/service/PlatformDataSyncService.php
C:\xampp\php\php.exe -l app/service/PlatformProfileBindingReadinessService.php
```

- [ ] Run the complete focused regression set:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaProfileSessionProofServiceTest.php tests/OtaProfileSessionProofConsumerTest.php tests/PlatformProfileBindingReadinessServiceTest.php tests/OnlineDataTest.php
node --test tests/automation/platform_account_collection_mode.test.mjs tests/automation/ctrip_store_data_overview.test.mjs tests/automation/platform_profile_local_login.test.mjs
```

- [ ] Verify the application health endpoint:

```powershell
Invoke-RestMethod http://127.0.0.1:8080/api/health | ConvertTo-Json -Depth 5
```

- [ ] Read the current data-source rows for Dunhuang Molanxin Ctrip and Meituan and confirm their authoritative 2026-07-11 proofs resolve to `reusable`, not `current_session_verified` and not `expired`, on 2026-07-12.
- [ ] Refresh the existing local page, confirm both Molanxin channels display automatic collection without requiring re-login, and confirm no renewal warning appears before day 7.
- [ ] Report exact passing tests and any live-browser limitation. Do not claim target-date data or whole-hotel operating facts were verified by this Profile eligibility change.
