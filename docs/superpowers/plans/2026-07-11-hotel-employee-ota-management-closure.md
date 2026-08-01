# Hotel, Employee, and OTA Management Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make hotel deletion complete and administrator-only, block duplicate hotel creation, separate OTA save from verified activation, and standardize employee passwords to `666666` with truthful hotel-status wording.

**Architecture:** Put irreversible hotel cleanup in a focused transactional service with an explicit table map and sanitized config-list cleanup. Keep duplicate detection in the hotel controller, derive OTA verification at read/select time from the existing same-source current-session proof, and limit frontend changes to existing hotel, employee, and OTA management flows.

**Tech Stack:** ThinkPHP 8, ThinkORM/Db facade, PHP 8, Vue 3 CDN, local static JavaScript helpers, PHPUnit, Node test runner.

---

### Task 1: Transactional hotel deletion service

**Files:**
- Create: `app/service/HotelCascadeDeletionService.php`
- Create: `tests/HotelCascadeDeletionServiceTest.php`

- [ ] **Step 1: Write the failing service tests**

Create SQLite-backed tests that insert one hotel, two users, permissions, one `online_daily_data` row, one platform source, one credential, one binding, one hotel operation log, and Ctrip/Meituan config-list entries. Assert that `preview()` returns counts without exposing secrets and `delete()` removes all hotel-linked records, clears the two config-list entries, keeps both users, nulls their primary hotel/tenant, and leaves no old hotel operation log.

```php
$preview = $service->preview(10);
self::assertSame(1, $preview['tables']['online_daily_data']);
self::assertArrayNotHasKey('encrypted_payload', $preview);

$result = $service->delete(10);
self::assertSame(0, Db::name('hotels')->where('id', 10)->count());
self::assertSame(2, Db::name('users')->count());
self::assertSame(0, Db::name('user_hotel_permissions')->where('hotel_id', 10)->count());
self::assertNull(Db::name('users')->where('id', 1)->value('hotel_id'));
```

- [ ] **Step 2: Run the service test and verify failure**

Run:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\HotelCascadeDeletionServiceTest.php
```

Expected: FAIL because `HotelCascadeDeletionService` does not exist.

- [ ] **Step 3: Implement the deletion service**

Create a final service with an explicit relation map. `online_daily_data` must be deleted only by `system_hotel_id`; `operation_logs` is cleared before the controller writes a new sanitized audit row.

```php
private const HOTEL_RELATIONS = [
    ['agent_configs', 'hotel_id'],
    ['agent_conversations', 'hotel_id'],
    ['agent_logs', 'hotel_id'],
    ['agent_tasks', 'hotel_id'],
    ['agent_work_orders', 'hotel_id'],
    ['ai_daily_reports', 'hotel_id'],
    ['ai_model_call_logs', 'hotel_id'],
    ['competitor_analysis', 'hotel_id'],
    ['competitor_price_log', 'hotel_id'],
    ['complaint_feedbacks', 'hotel_id'],
    ['complaint_rooms', 'hotel_id'],
    ['daily_reports', 'hotel_id'],
    ['demand_forecasts', 'hotel_id'],
    ['devices', 'hotel_id'],
    ['energy_benchmarks', 'hotel_id'],
    ['energy_consumption', 'hotel_id'],
    ['energy_saving_suggestions', 'hotel_id'],
    ['field_mappings', 'hotel_id'],
    ['hotel_field_templates', 'hotel_id'],
    ['knowledge_base', 'hotel_id'],
    ['knowledge_categories', 'hotel_id'],
    ['knowledge_units', 'hotel_id'],
    ['maintenance_plans', 'hotel_id'],
    ['monthly_tasks', 'hotel_id'],
    ['online_daily_data', 'system_hotel_id'],
    ['opening_projects', 'hotel_id'],
    ['operation_action_tracks', 'hotel_id'],
    ['operation_alerts', 'hotel_id'],
    ['operation_execution_intents', 'hotel_id'],
    ['operation_execution_tasks', 'hotel_id'],
    ['operation_logs', 'hotel_id'],
    ['ota_credentials', 'system_hotel_id'],
    ['ota_ctrip_capture_gaps', 'system_hotel_id'],
    ['ota_ctrip_capture_runs', 'system_hotel_id'],
    ['ota_ctrip_entity_snapshots', 'system_hotel_id'],
    ['ota_ctrip_im_sessions', 'system_hotel_id'],
    ['ota_ctrip_metric_facts', 'system_hotel_id'],
    ['ota_ctrip_orders', 'system_hotel_id'],
    ['ota_ctrip_reviews', 'system_hotel_id'],
    ['ota_ctrip_review_order_matches', 'system_hotel_id'],
    ['ota_profile_bindings', 'system_hotel_id'],
    ['platform_data_raw_records', 'system_hotel_id'],
    ['platform_data_sources', 'system_hotel_id'],
    ['platform_data_sync_logs', 'system_hotel_id'],
    ['platform_data_sync_tasks', 'system_hotel_id'],
    ['price_suggestions', 'hotel_id'],
    ['room_types', 'hotel_id'],
    ['system_notifications', 'hotel_id'],
    ['transfer_records', 'hotel_id'],
];
```

The service must check table/column existence, lock the hotel row, run in `Db::transaction()`, delete permissions, null `users.hotel_id` and `users.tenant_id`, remove matching entries from `ctrip_config_list` and `meituan_config_list`, clear protected OTA caches, then delete `hotels.id`.

- [ ] **Step 4: Run the service test and verify pass**

Run the command from Step 2. Expected: PASS.

### Task 2: Administrator-only delete controller and confirmation UI

**Files:**
- Modify: `app/controller/Hotel.php`
- Modify: `public/index.html`
- Modify: `tests/HotelDeletePolicyTest.php`
- Modify: `tests/automation/ota_admin_management_closure.test.mjs`

- [ ] **Step 1: Write failing controller/UI contract tests**

Assert that delete uses `$this->checkPermission(true)`, requires exact `confirmation_name`, calls `HotelCascadeDeletionService`, records the final audit with `hotel_id=null` plus `deleted_hotel_id`, and that the frontend hides delete from non-super-admins and requires typing the hotel name.

```js
assert.match(hotelController, /public function delete\(int \$id\): Response[\s\S]*\$this->checkPermission\(true\)/);
assert.match(hotelController, /HotelCascadeDeletionService/);
assert.match(hotelPage, /v-model="hotelDeleteConfirmationName"/);
assert.match(hotelPage, /请输入完整门店名称/);
```

- [ ] **Step 2: Run tests and verify failure**

```powershell
node --test tests\automation\ota_admin_management_closure.test.mjs
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\HotelDeletePolicyTest.php
```

Expected: FAIL on the new administrator-only/full-delete contracts.

- [ ] **Step 3: Implement controller and modal changes**

Replace the force-delete policy with a two-stage preview/confirmation flow backed by the service. The second request body is:

```js
{
  force: true,
  confirmation_name: String(hotelDeleteConfirmationName.value || '').trim(),
}
```

On success show `酒店及关联数据已删除`; on failure preserve and display reference counts. Record only `deleted_hotel_id`, `deleted_hotel_name`, and deletion counts in audit metadata.

- [ ] **Step 4: Run controller/UI tests and verify pass**

Run the commands from Step 2. Expected: PASS.

### Task 3: Duplicate hotel-name blocking and merge handoff

**Files:**
- Modify: `app/controller/Hotel.php`
- Modify: `public/index.html`
- Modify: `public/system-static.js`
- Modify: `tests/automation/admin_management_backend_contract.test.mjs`

- [ ] **Step 1: Write failing duplicate-name tests**

Test a helper that normalizes names with `trim()` and returns the existing hotel. Contract-test both create and update paths for a `409` response carrying `duplicate_hotels` and the frontend for an explicit merge handoff.

```js
assert.equal(systemStaticApi.normalizeHotelIdentityName('  敦煌莫月山  '), '敦煌莫月山');
assert.match(hotelController, /duplicate_hotels/);
assert.match(saveHotel, /openHotelMergeModal\(duplicateHotel\)/);
```

- [ ] **Step 2: Run and verify failure**

```powershell
node --test tests\automation\admin_management_backend_contract.test.mjs
```

Expected: FAIL because duplicate-name blocking is absent.

- [ ] **Step 3: Implement minimal duplicate handling**

Before create/update persistence, query `hotels.name` using the normalized name and exclude the current ID on update. Return `409` with safe hotel fields only. In the frontend, stop saving, show `发现同名酒店，请先核对并合并`, close the edit modal, and open the existing merge modal with the duplicate candidate as source when the operator is super-admin.

- [ ] **Step 4: Run and verify pass**

Run the command from Step 2. Expected: PASS.

### Task 4: Fixed employee password `666666`

**Files:**
- Modify: `public/user-admin-static.js`
- Modify: `public/index.html`
- Modify: `app/controller/User.php`
- Modify: `tests/automation/ota_admin_management_closure.test.mjs`

- [ ] **Step 1: Replace existing password contracts with failing fixed-password contracts**

```js
assert.equal(userAdminStaticApi.defaultIssuedPassword(), '666666');
assert.doesNotMatch(html, /临时密码|换一组6位数字|buildNumericTemporaryPassword/);
assert.match(openUserModal, /password: defaultIssuedPassword\(\)/);
assert.match(loginInfoFlow, /密码：\$\{issuedPassword\}/);
```

- [ ] **Step 2: Run and verify failure**

```powershell
node --test tests\automation\ota_admin_management_closure.test.mjs
```

Expected: FAIL because the UI still generates a random temporary password.

- [ ] **Step 3: Implement fixed issuance without weakening self-service password changes**

Export `defaultIssuedPassword = () => '666666'`; remove random generation and regeneration controls; make add/reset fields read-only and use `666666`. In `User::create()` and super-admin reset updates, allow exactly `666666`; keep `validatePasswordPolicy()` for all other passwords and self-service password changes.

- [ ] **Step 4: Run and verify pass**

Run the command from Step 2 plus:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\UserTenantPropagationTest.php tests\PermissionServiceTest.php
```

Expected: PASS.

### Task 5: OTA saved-versus-verified activation

**Files:**
- Create: `app/service/OtaConfigVerificationService.php`
- Create: `tests/OtaConfigVerificationServiceTest.php`
- Modify: `app/controller/concern/OtaConfigConcern.php`
- Modify: `public/ctrip-static.js`
- Modify: `public/meituan-static.js`
- Modify: `public/index.html`
- Modify: `tests/automation/ota_admin_management_closure.test.mjs`

- [ ] **Step 1: Write failing verification tests**

Test that a ready credential without current-session proof is `saved_pending_verification`; a proof older than the config save time remains pending; and a same-hotel/platform current proof at or after save time returns `verified_current`.

```php
self::assertSame('saved_pending_verification', $service->statusForConfig($config)['verification_status']);
self::assertSame('verified_current', $service->statusForConfig($configAfterLogin)['verification_status']);
```

- [ ] **Step 2: Run and verify failure**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OtaConfigVerificationServiceTest.php
```

Expected: FAIL because the verification service does not exist.

- [ ] **Step 3: Implement proof-derived verification and active selection**

The service loads enabled `platform_data_sources` for the same `system_hotel_id` and platform, uses `OtaProfileSessionProofService::isCurrentVerified()`, and requires `current_session_probe_at >= config.update_time`. Return only safe metadata:

```php
[
    'verification_status' => 'verified_current',
    'verification_status_label' => '验证成功，当前使用',
    'configuration_saved' => true,
    'configuration_verified' => true,
    'verified_at' => $probeAt,
]
```

Decorate config-list output and change `selectLatestSuccessfulCtripConfig()` / `selectLatestSuccessfulMeituanConfig()` to require `configuration_verified === true`. Newly persisted metadata must explicitly store `verification_status=saved_pending_verification` and `configuration_verified=false`.

- [ ] **Step 4: Update frontend state and wording**

Replace “最新成功配置” with `已保存，待授权验证` or `验证成功，当前使用`. Saving still reports success but never claims validation. Existing history remains folded and does not block a newer save.

- [ ] **Step 5: Run and verify pass**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OtaConfigVerificationServiceTest.php tests\OtaProfileSessionProofServiceTest.php tests\OtaCredentialReadPathTest.php
node --test tests\automation\ota_admin_management_closure.test.mjs tests\automation\hotel_platform_login_verification.test.mjs tests\automation\ota_store_account_normalization.test.mjs
```

Expected: PASS.

### Task 6: Truthful hotel disable wording and final verification

**Files:**
- Modify: `app/controller/Hotel.php`
- Modify: `public/index.html`
- Modify: `tests/automation/ota_admin_management_closure.test.mjs`

- [ ] **Step 1: Write failing wording contract**

Assert that the UI says the hotel becomes unavailable for access/collection while accounts with other hotels may still log in, and does not say all related users cannot log in.

- [ ] **Step 2: Implement minimal wording and response changes**

Use: `停用后该门店不可访问，OTA采集停止；员工账号不会停用，有其他门店权限时仍可登录。` Update the controller success message to report affected hotel authorizations rather than affected logins.

- [ ] **Step 3: Run focused regression**

```powershell
node --check public\user-admin-static.js
node --check public\system-static.js
node --check public\ctrip-static.js
node --check public\meituan-static.js
node --test tests\automation\admin_management_backend_contract.test.mjs tests\automation\ota_admin_management_closure.test.mjs tests\automation\hotel_management_responsive_layout.test.mjs tests\automation\hotel_platform_login_verification.test.mjs tests\automation\access_tier_permissions.test.mjs
C:\xampp\php\php.exe -l app\controller\Hotel.php
C:\xampp\php\php.exe -l app\controller\User.php
C:\xampp\php\php.exe -l app\service\HotelCascadeDeletionService.php
C:\xampp\php\php.exe -l app\service\OtaConfigVerificationService.php
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\HotelCascadeDeletionServiceTest.php tests\HotelDeletePolicyTest.php tests\HotelManagementScopeTest.php tests\UserTenantPropagationTest.php tests\PermissionServiceTest.php tests\OtaConfigVerificationServiceTest.php tests\OtaProfileSessionProofServiceTest.php
```

Expected: all checks pass.

- [ ] **Step 4: Verify local UI**

Open `http://127.0.0.1:8080/`, refresh Employee Management and Hotel Management, and inspect without deleting production-like local records. Verify default password text, admin-only delete controls, duplicate-name guidance, OTA saved/verified labels, and hotel-disable wording. Confirm `/api/health` is healthy and no new console errors appear.
