# OTA Credential Rollout Regression Fix Implementation Plan

> **For agentic workers:** Execute inline in the current session. Do not create subagents, commits, or a second worktree; the checkout already contains a large active security-hardening diff that must be preserved.

**Goal:** Prevent OTA credential security gates from silently disabling previously configured hotels when legacy credentials have not yet been migrated, while keeping plaintext credentials blocked.

**Architecture:** Project every stored Ctrip and Meituan config through the existing metadata-only runtime sanitizer before returning it to the browser. Use small pure frontend state builders to distinguish `ready`, `migration_required`, and other blocked states, then bind those states to the existing manual-fetch status rows and buttons. Existing migration commands remain the only path that moves secrets into `ota_credentials`.

**Tech Stack:** ThinkPHP 8/PHPUnit, Vue 3 CDN template, plain JavaScript static helpers, Node test runner.

---

### Task 1: Pin the backend config-list boundary

**Files:**
- Modify: `tests/OtaCredentialReadPathTest.php`
- Modify: `app/controller/concern/OnlineDataRequestConcern.php`
- Modify: `app/controller/concern/MeituanConfigConcern.php`

- [x] **Step 1: Write the failing test**

Add a test that slices `getCtripConfigList()` and `getMeituanConfigList()` and requires both methods to call:

```php
$list = $this->sanitizeStoredOtaConfigListForRuntime($list);
```

The test must also reject direct list responses built only with:

```php
array_map([$this, 'sanitizeSecretConfig'], array_values($list))
```

- [x] **Step 2: Run the test and verify RED**

Run:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --filter testConfigListEndpointsExposeMigrationRequiredState tests\OtaCredentialReadPathTest.php
```

Expected: FAIL because both list endpoints currently skip the runtime migration-state sanitizer.

- [x] **Step 3: Implement the minimum backend change**

After user visibility filtering and before sorting/returning, apply the shared sanitizer:

```php
$list = $this->sanitizeStoredOtaConfigListForRuntime($list);
```

Return `array_values($list)` because the sanitizer already removes secret-bearing fields and assigns `migration_required` to legacy rows.

- [x] **Step 4: Run the test and verify GREEN**

Run the same PHPUnit command and require exit code `0`.

### Task 2: Make blocked credential states truthful and actionable

**Files:**
- Modify: `tests/automation/manual_minimum_credential_ui.test.mjs`
- Modify: `public/ctrip-static.js`
- Modify: `public/meituan-static.js`
- Modify: `public/index.html`

- [x] **Step 1: Write failing helper and template tests**

Require both static bundles to return explicit state for a legacy row:

```js
{
  key: 'migration_required',
  canFetch: false,
  label: '旧版凭据待安全迁移',
  detail: '完成凭据安全迁移或重新保存授权后，才能获取数据。',
}
```

Require a `ready` row to return `canFetch: true`. Require the Vue template to render the state label/detail and stop using unconditional `携程已配置` / `美团已配置` wording.

- [x] **Step 2: Run the Node test and verify RED**

Run:

```powershell
node --test tests/automation/manual_minimum_credential_ui.test.mjs
```

Expected: FAIL because the state builders and truthful template bindings do not exist.

- [x] **Step 3: Add pure state builders**

Add `buildCtripManualCredentialState(config)` and `buildMeituanManualCredentialState(config)` beside the existing execution-readiness helpers. Both must:

```js
if (isExecutionConfigReady(config)) return readyState;
if (config?.migration_required || status === 'migration_required') return migrationState;
return blockedState;
```

No helper may inspect `cookies`, `cookie`, `auth_data`, or another secret field.

- [x] **Step 4: Bind state to the existing UI**

Create computed Ctrip/Meituan credential states from the selected config, use their `canFetch` value for readiness, and show their label/detail in the existing status area. Keep current configure actions for truly missing configs.

- [x] **Step 5: Run the Node test and verify GREEN**

Run the same Node test and require all subtests to pass.

### Task 3: Verify the regression class and live local app

**Files:**
- Verify only; no additional source expansion unless a failing verifier identifies a direct regression.

- [x] **Step 1: Run focused syntax and contract checks**

```powershell
node --check public/ctrip-static.js
node --check public/meituan-static.js
node scripts/verify_public_entry_guard.mjs
node scripts/verify_e2e_contracts.mjs
npm.cmd run verify:ota-credential-vault
```

- [x] **Step 2: Run focused PHPUnit suites**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OtaCredentialReadPathTest.php tests\OtaCredentialResponseTest.php tests\OtaCredentialMigrationServiceTest.php
```

- [x] **Step 3: Recheck migration and runtime state**

Run `npm.cmd run migrate:ota-credentials:dry-run`; require no blockers or remaining issues. Do not run `--execute` when dry-run reports no eligible rows.

- [ ] **Step 4: Verify local UI**

Reload `http://127.0.0.1:8080/`, confirm `/api/health` is `200`, and verify a `ready` selected hotel has an enabled fetch button while a synthetic/unit-tested `migration_required` row remains disabled with an explicit reason.

Verification note: the local page, cache-busted assets, health endpoint, synthetic ready/migration states, and database readiness were verified. The browser automation session did not inherit the user's authenticated tab, so the logged-in button was not clicked or directly observed.

---

No Git commit is part of this plan because the user did not request `save project`, and unrelated active worktree changes must remain untouched.
