# Ctrip Default-All and Room Count Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the editable Ctrip Profile scope, grant every saved Ctrip configuration all supported capture capabilities, and require hotel/competition-circle room counts on Ctrip configuration saves.

**Architecture:** Keep room counts as non-secret metadata inside the existing `system_configs.ctrip_config_list` JSON, while credentials remain in the OTA vault. Enforce `capture_sections = all` at both the frontend payload boundary and backend Ctrip configuration boundary; preserve task-level section overrides for focused collectors. Existing configurations remain readable and executable, with missing room counts shown as pending until the next save.

**Tech Stack:** ThinkPHP 8/PHP 8, Vue 3 CDN SPA, Node.js built-in test runner, PHPUnit, SQLite test harness.

---

## File Structure

- `public/ctrip-static.js`: Ctrip form defaults, positive-integer validation, save payload, default-all capability contract.
- `public/index.html`: visible fields, edit/quick-editor echo, saved-row status, every Ctrip save path.
- `app/controller/concern/PlatformProfileCaptureConcern.php`: authoritative saved Ctrip capability scope.
- `app/controller/concern/OnlineDataRequestConcern.php`: required room-count validation and metadata persistence.
- `app/controller/concern/OtaConfigConcern.php`: legacy Ctrip all-capability runtime interpretation.
- `tests/automation/manual_minimum_credential_ui.test.mjs`: frontend and source contracts.
- `tests/OnlineDataTest.php`: backend normalization and validation contracts.
- `tests/OtaCredentialResponseTest.php`: metadata persistence/readback and secret boundary.

### Task 1: Frontend Ctrip configuration contract

**Files:**
- Modify: `public/ctrip-static.js:215-435`
- Test: `tests/automation/manual_minimum_credential_ui.test.mjs:300-520`

- [ ] **Step 1: Write the failing helper tests**

```js
const defaultForm = ctripStaticApi.createCtripConfigForm();
assert.equal(defaultForm.capture_sections, 'all');
assert.equal(defaultForm.hotel_room_count, '');
assert.equal(defaultForm.competitor_room_count, '');

const invalidRooms = ctripStaticApi.validateCtripConfigSaveInput({
  cookies: 'cookie', hotel_room_count: '0', competitor_room_count: '120',
});
assert.equal(invalidRooms.status, 'invalid_hotel_room_count');

const payload = ctripStaticApi.buildCtripConfigSavePayload({
  hotel_id: 58, cookies: 'cookie', capture_sections: 'default',
  hotel_room_count: '88', competitor_room_count: '360',
});
assert.equal(payload.capture_sections, 'all');
assert.equal(payload.hotel_room_count, 88);
assert.equal(payload.competitor_room_count, 360);
```

- [ ] **Step 2: Run RED**

Run: `node --test tests/automation/manual_minimum_credential_ui.test.mjs`

Expected: FAIL because room counts are absent and the form still defaults to `default`.

- [ ] **Step 3: Implement the helper contract**

Extend `createCtripConfigForm()` with `capture_sections: 'all'`, `hotel_room_count: ''`, and `competitor_room_count: ''`. Add a strict `/^[1-9]\d*$/` positive-integer validator. Make `buildCtripConfigSavePayload()` always emit:

```js
{
  ...existingMetadata,
  capture_sections: 'all',
  hotel_room_count: Number(form.hotel_room_count),
  competitor_room_count: Number(form.competitor_room_count),
}
```

Export the new validation helper through the existing Ctrip static API.

- [ ] **Step 4: Run GREEN**

Run: `node --test tests/automation/manual_minimum_credential_ui.test.mjs`

Expected: PASS.

### Task 2: Backend authority and metadata validation

**Files:**
- Modify: `app/controller/concern/PlatformProfileCaptureConcern.php:1238-1270`
- Modify: `app/controller/concern/OnlineDataRequestConcern.php:1776-1885`
- Modify: `app/controller/concern/OtaConfigConcern.php:1770-1800,2230-2260,2558-2590`
- Test: `tests/OnlineDataTest.php:6600-6650`
- Test: `tests/OtaCredentialResponseTest.php:580-670`

- [ ] **Step 1: Write failing backend tests**

Change the three existing Ctrip configuration-option cases to assert:

```php
self::assertSame('all', $options['capture_sections']);
self::assertSame('all', $options['profile_sections']);
```

Add validation coverage:

```php
self::assertSame(88, $this->invokeNonPublic(
    $controller,
    'requiredPositiveCtripRoomCount',
    ['88', '酒店实际房量']
));
foreach (['', '0', '-1', '1.5', 'abc', true] as $invalid) {
    try {
        $this->invokeNonPublic($controller, 'requiredPositiveCtripRoomCount', [$invalid, '酒店实际房量']);
        self::fail('Invalid Ctrip room count must fail.');
    } catch (\think\exception\HttpException $e) {
        self::assertSame(422, $e->getStatusCode());
    }
}
```

Extend the Ctrip persistence test with `hotel_room_count => 88` and `competitor_room_count => 360`, then assert both remain in stored/returned metadata while secrets remain absent.

- [ ] **Step 2: Run RED**

Run:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter "CtripProfileCaptureConfigOptions|CtripRoomCount"
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OtaCredentialResponseTest.php --filter CtripPersistenceStoresOnlySafeMetadata
```

Expected: FAIL because saved scope is configurable and the room-count normalizer does not exist.

- [ ] **Step 3: Force saved Ctrip capability to all**

Make `buildCtripProfileCaptureConfigOptions()` return `capture_sections => all` and `profile_sections => all` while preserving the approved-mapping path. Do not change task-specific section normalizers used by review/traffic jobs.

- [ ] **Step 4: Validate room counts before writes**

Add this boundary in `OnlineDataRequestConcern`:

```php
private function requiredPositiveCtripRoomCount(mixed $value, string $label): int
{
    if (is_bool($value) || is_float($value)) {
        throw new \think\exception\HttpException(422, $label . '必须为正整数');
    }
    $text = trim((string)$value);
    if (preg_match('/^[1-9]\d*$/D', $text) !== 1 || (int)$text > 1000000) {
        throw new \think\exception\HttpException(422, $label . '必须为1-1000000之间的正整数');
    }
    return (int)$text;
}
```

Require both snake_case or camelCase request fields on create/update and add the normalized integers to `$config` before `persistCtripConfigMetadata()`.

- [ ] **Step 5: Interpret legacy Ctrip scope as all**

Add in `OtaConfigConcern`:

```php
private function applyCtripAllCaptureCapability(array $config): array
{
    $config['capture_sections'] = 'all';
    $config['profile_sections'] = 'all';
    return $config;
}
```

Apply it in Ctrip list normalization, stored Ctrip reads, and the Ctrip light-cache read. Do not add absent room-count keys and do not apply it to Meituan metadata.

- [ ] **Step 6: Run GREEN**

Run the two commands from Step 2. Expected: PASS.

### Task 3: Visible form, edit echo, quick editor, and legacy status

**Files:**
- Modify: `public/index.html:9218-9270,9305-9330,16235-16265,19650-19670,41740-42005`
- Test: `tests/automation/manual_minimum_credential_ui.test.mjs:480-530`

- [ ] **Step 1: Write failing DOM/source assertions**

```js
assert.match(configForm, /v-model="ctripConfigForm\.hotel_room_count"/);
assert.match(configForm, /v-model="ctripConfigForm\.competitor_room_count"/);
assert.doesNotMatch(configForm, /Profile采集范围/);
assert.match(configList, /酒店实际房量：.*待补/);
assert.match(configList, /竞争圈总房量：.*待补/);
```

Assert `editCtripConfig`, health-editor fill/save, and form reset carry both fields and use `capture_sections: 'all'`.

- [ ] **Step 2: Run RED**

Run: `node --test tests/automation/manual_minimum_credential_ui.test.mjs`

Expected: FAIL because the old Profile input remains and room-count UI/echo is absent.

- [ ] **Step 3: Implement the visible flow**

Replace the Profile scope block with two required number inputs using `min="1"` and `step="1"`. Update saved rows to show each value or `待补`, never `0`. Extend `editCtripConfig`, `fillCtripCookieEditorForm`, `openCtripCookieCreateFromHealth`, and `saveCtripCookieFromHealth` with both fields and `capture_sections: 'all'`. Run the same room-count validation before every save call.

- [ ] **Step 4: Run GREEN**

Run the Node test from Step 2. Expected: PASS.

### Task 4: Focused regression verification

**Files:**
- Verify only; no planned production edits.

- [ ] **Step 1: Run syntax checks**

```powershell
C:\xampp\php\php.exe -l app\controller\concern\OnlineDataRequestConcern.php
C:\xampp\php\php.exe -l app\controller\concern\OtaConfigConcern.php
C:\xampp\php\php.exe -l app\controller\concern\PlatformProfileCaptureConcern.php
node --check public\ctrip-static.js
```

Expected: no syntax errors.

- [ ] **Step 2: Run focused contracts**

```powershell
node --test tests/automation/manual_minimum_credential_ui.test.mjs
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter "CtripProfileCaptureConfigOptions|CtripRoomCount"
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OtaCredentialResponseTest.php --filter "CtripPersistenceStoresOnlySafeMetadata|CtripConfig"
npm.cmd run verify:public-entry
```

Expected: all pass.

- [ ] **Step 3: Review the exact diff**

Run `git diff --check`, then inspect only the eight files listed in File Structure. Expected: no whitespace errors and no unrelated edits introduced by this task.

- [ ] **Step 4: Browser verification**

When `/api/health` is healthy, verify the Ctrip configuration page: Profile scope input is absent; both counts are required; invalid values block save; valid values save and edit-echo; old missing values display `待补`. If local health/database is unavailable, report browser verification as blocked.
