# OTA Credential and Tenant Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove reusable OTA secrets from generic configuration storage and responses, enforce tenant-plus-hotel scope on every credential operation, and migrate legacy plaintext through an explicit dry-run-first workflow.

**Architecture:** Store encrypted secret payloads in a dedicated `ota_credentials` table keyed by tenant, system hotel, platform, and config ID. Controllers keep only metadata in `system_configs`; decrypted payloads are exposed solely to internal collector callbacks after scope authorization. Legacy plaintext is readable only by the migration service, never by normal request or collector paths.

**Tech Stack:** PHP 8, ThinkPHP 8, ThinkORM, MySQL 8, SQLite-backed PHPUnit 11 tests, OpenSSL AES-256-GCM, Node contract tests.

---

## File map

**Create**

- `app/service/OtaCredentialEnvelope.php`: authenticated versioned encryption and decryption.
- `app/model/OtaCredential.php`: hidden-payload ThinkORM model.
- `app/service/OtaCredentialVault.php`: scoped store, metadata, execution callback, revoke, and delete operations.
- `app/service/OtaCredentialMigrationService.php`: legacy inventory and transactional migration.
- `app/command/MigrateOtaCredentials.php`: dry-run-default CLI command.
- `database/migrations/20260710_create_ota_credentials.sql`: credential table.
- `tests/OtaCredentialEnvelopeTest.php`: cryptographic contract.
- `tests/OtaCredentialVaultTest.php`: database and scope contract.
- `tests/OtaCredentialMigrationServiceTest.php`: dry-run and execute migration contract.
- `tests/OtaCredentialResponseTest.php`: controller and generic-config response contract.
- `tests/OtaCredentialReadPathTest.php`: source-level read-path contract.
- `scripts/verify_ota_credential_vault.mjs`: repository wiring contract.

**Modify**

- `.example.env`: documented key ID and base64 key variables.
- `database/init_full.sql`: include the new migration after tenant fields and system config creation.
- `config/console.php`: register the migration command.
- `package.json`: expose dry-run, execute, and verifier commands.
- `app/model/SystemConfig.php`: remove OTA secret-bearing keys from durable cache and reject generic secret-key reads/writes.
- `app/controller/SystemConfigController.php`: recursively redact or reject protected OTA config keys for ordinary index/update responses.
- `app/controller/concern/OtaConfigConcern.php`: strict binding, pure reads, vault metadata, internal execution access, recursive sanitization.
- `app/controller/concern/OnlineDataRequestConcern.php`: Ctrip save/list/detail/delete and bookmark save.
- `app/controller/concern/MeituanConfigConcern.php`: Meituan save/list/detail/delete and comment config.
- `app/controller/concern/OnlineDataManualFetchConcern.php`: resolve saved credentials server-side.
- `app/controller/concern/AutoFetchConcern.php`: metadata-only light status and callback-only decrypted execution.
- `app/controller/concern/CollectionReliabilityConcern.php`: credential metadata status without plaintext reads.
- `app/command/AutoFetchOnlineData.php`: vault-backed collector access.
- `app/controller/Agent.php`: metadata-only diagnostics.
- `app/service/OperationManagementService.php`: metadata-only availability checks.
- `app/service/PlatformDataSyncService.php`: move `platform_data_sources.secret_json` writes and reads behind the vault.
- `tests/PlatformDataSyncServiceTest.php`: prove source credentials are not stored in `secret_json`.
- `scripts/register_p0_ota_traffic_data_sources.php`: use credential metadata instead of generic config values.
- `scripts/verify_p0_ota_field_loop_closure.php`: use credential metadata instead of generic config values.
- `public/index.html`, `public/ctrip-static.js`: send `config_id`; never request or cache raw credential detail.
- `public/auto-fetch-static.js`, `public/ota-diagnosis-static.js`: remove saved-secret hydration assumptions.
- `tests/OnlineDataTest.php`: preserve current user changes and add endpoint/binding assertions.
- `tests/SecurityInputGuardTest.php`: generic config response assertions.
- `tests/automation/manual_minimum_credential_ui.test.mjs`: new server-side credential contract.
- `scripts/verify_e2e_contracts.mjs`, `scripts/verify_public_entry_guard.mjs`, `scripts/verify_high_risk_security.php`: replace plaintext assumptions with vault assertions.

## Task 1: Authenticated credential envelope

**Files:**

- Create: `tests/OtaCredentialEnvelopeTest.php`
- Create: `app/service/OtaCredentialEnvelope.php`
- Modify: `.example.env`

- [x] **Step 1: Write the failing envelope tests**

```php
<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCredentialEnvelope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OtaCredentialEnvelopeTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = base64_encode(str_repeat("k", 32));
    }

    public function testRoundTripUsesRandomNonceAndDoesNotEmbedPlaintext(): void
    {
        $cipher = new OtaCredentialEnvelope($this->key, 'unit-test-key');
        $payload = ['cookies' => 'session-secret', 'auth_data' => ['token' => 'token-secret']];

        $first = $cipher->encrypt($payload, 'tenant:7:hotel:64:ctrip:cfg-1');
        $second = $cipher->encrypt($payload, 'tenant:7:hotel:64:ctrip:cfg-1');

        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('session-secret', $first);
        self::assertSame($payload, $cipher->decrypt($first, 'tenant:7:hotel:64:ctrip:cfg-1'));
    }

    public function testTamperingAndWrongScopeFailClosed(): void
    {
        $cipher = new OtaCredentialEnvelope($this->key, 'unit-test-key');
        $envelope = $cipher->encrypt(['cookies' => 'secret'], 'tenant:7:hotel:64:ctrip:cfg-1');
        $decoded = json_decode(base64_decode(substr($envelope, strlen('ota-cred:v1:')), true), true);
        $decoded['tag'] = base64_encode(str_repeat('x', 16));
        $tampered = 'ota-cred:v1:' . base64_encode(json_encode($decoded, JSON_UNESCAPED_SLASHES));

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($tampered, 'tenant:7:hotel:64:ctrip:cfg-1');
    }

    public function testMissingOrMalformedKeyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        new OtaCredentialEnvelope('not-base64-32-bytes', 'unit-test-key');
    }
}
```

- [x] **Step 2: Run the tests and verify RED**

Run:

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialEnvelopeTest.php
```

Expected: FAIL because `app\service\OtaCredentialEnvelope` does not exist.

- [x] **Step 3: Implement the envelope**

```php
<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class OtaCredentialEnvelope
{
    private const PREFIX = 'ota-cred:v1:';
    private const AAD_PREFIX = 'suxios:ota-credential:v1:';
    private string $key;

    public function __construct(string $keyBase64, private readonly string $keyId)
    {
        $key = base64_decode(trim($keyBase64), true);
        if (!is_string($key) || strlen($key) !== 32 || trim($keyId) === '') {
            throw new RuntimeException('OTA credential encryption key is not configured correctly.');
        }
        $this->key = $key;
    }

    public function encrypt(array $payload, string $scope): string
    {
        $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD_PREFIX . $scope, 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('OTA credential encryption failed.');
        }
        $body = json_encode([
            'v' => 1,
            'alg' => 'AES-256-GCM',
            'kid' => $this->keyId,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::PREFIX . base64_encode($body);
    }

    public function decrypt(string $envelope, string $scope): array
    {
        if (!str_starts_with($envelope, self::PREFIX)) {
            throw new RuntimeException('Unsupported OTA credential envelope version.');
        }
        $json = base64_decode(substr($envelope, strlen(self::PREFIX)), true);
        $body = is_string($json) ? json_decode($json, true, 16, JSON_THROW_ON_ERROR) : null;
        if (!is_array($body) || ($body['v'] ?? null) !== 1 || ($body['alg'] ?? '') !== 'AES-256-GCM' || ($body['kid'] ?? '') !== $this->keyId) {
            throw new RuntimeException('Invalid OTA credential envelope metadata.');
        }
        $nonce = base64_decode((string)($body['nonce'] ?? ''), true);
        $ciphertext = base64_decode((string)($body['ciphertext'] ?? ''), true);
        $tag = base64_decode((string)($body['tag'] ?? ''), true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($ciphertext) || !is_string($tag) || strlen($tag) !== 16) {
            throw new RuntimeException('Invalid OTA credential envelope payload.');
        }
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD_PREFIX . $scope);
        if (!is_string($plain)) {
            throw new RuntimeException('OTA credential authentication failed.');
        }
        $payload = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('OTA credential payload is invalid.');
        }
        return $payload;
    }
}
```

Add to `.example.env`:

```dotenv
# Base64-encoded 32-byte key used only for encrypted OTA credentials.
OTA_CREDENTIAL_KEY_B64 =
# Stable identifier for the active OTA credential key. The real key never enters logs or the database.
OTA_CREDENTIAL_KEY_ID = primary-v1
```

- [x] **Step 4: Verify GREEN and syntax**

Run:

```powershell
C:\xampp\php\php.exe -l app\service\OtaCredentialEnvelope.php
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialEnvelopeTest.php
```

Expected: both commands PASS.

- [ ] **Step 5: Commit only clean new files**

```powershell
git add -- app/service/OtaCredentialEnvelope.php tests/OtaCredentialEnvelopeTest.php .example.env
git diff --cached --check
git commit -m "[安全] 增加OTA凭据认证加密封装"
```

## Task 2: Scoped credential vault and schema

**Files:**

- Create: `database/migrations/20260710_create_ota_credentials.sql`
- Create: `app/model/OtaCredential.php`
- Create: `app/service/OtaCredentialVault.php`
- Create: `tests/OtaCredentialVaultTest.php`
- Modify: `database/init_full.sql`

- [x] **Step 1: Write failing SQLite-backed vault tests**

The test must create `ota_credentials` and `hotels` in a temporary SQLite database, then assert:

```php
$vault->store(7, 64, 'ctrip', 'cfg-1', ['cookies' => 'secret'], 9);
self::assertSame(['cookies' => 'secret'], $vault->withPayloadForExecution(
    7,
    64,
    'ctrip',
    'cfg-1',
    static fn(array $payload): array => $payload
));
self::assertSame('ready', $vault->metadata(7, 64, 'ctrip', 'cfg-1')['credential_status']);
self::assertArrayNotHasKey('encrypted_payload', $vault->metadata(7, 64, 'ctrip', 'cfg-1'));
```

Add negative assertions for wrong tenant, wrong hotel, wrong platform, duplicate scope, revoked credentials, and tampered ciphertext. Every negative read must throw `RuntimeException` and never return an empty payload.

Before `store()` or `findRequired()` succeeds, the vault must verify that `hotels.id = system_hotel_id` exists and its `tenant_id` equals the locator tenant. A caller-provided tenant ID is never trusted by itself.

- [x] **Step 2: Run the vault tests and verify RED**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialVaultTest.php
```

Expected: FAIL because the model, vault, and table contract do not exist.

- [x] **Step 3: Add the schema**

```sql
CREATE TABLE IF NOT EXISTS `ota_credentials` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `system_hotel_id` INT UNSIGNED NOT NULL,
  `platform` VARCHAR(20) NOT NULL,
  `config_id` VARCHAR(120) NOT NULL,
  `encrypted_payload` LONGTEXT NOT NULL,
  `payload_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `key_id` VARCHAR(80) NOT NULL,
  `secret_mask` VARCHAR(80) NOT NULL DEFAULT '',
  `credential_status` VARCHAR(32) NOT NULL DEFAULT 'ready',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `rotated_at` DATETIME DEFAULT NULL,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ota_credential_scope` (`tenant_id`, `system_hotel_id`, `platform`, `config_id`),
  KEY `idx_ota_credential_hotel_status` (`tenant_id`, `system_hotel_id`, `credential_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Encrypted OTA credential vault';
```

Add its `SOURCE` line after the tenant migration and system config table are available in `database/init_full.sql`.

- [x] **Step 4: Implement the hidden model and scoped vault**

`OtaCredential` must define `$hidden = ['encrypted_payload']` and integer casts. `OtaCredentialVault` must build the envelope AAD from all four locator fields:

```php
private function scope(int $tenantId, int $hotelId, string $platform, string $configId): string
{
    if ($tenantId <= 0 || $hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true) || trim($configId) === '') {
        throw new RuntimeException('Invalid OTA credential scope.');
    }
    return "tenant:{$tenantId}:hotel:{$hotelId}:{$platform}:" . trim($configId);
}

public function withPayloadForExecution(
    int $tenantId,
    int $hotelId,
    string $platform,
    string $configId,
    callable $consumer
) {
    $row = $this->findRequired($tenantId, $hotelId, $platform, $configId);
    if ((string)$row->credential_status !== 'ready') {
        throw new RuntimeException('OTA credential is not ready for collection.');
    }
    $payload = $this->envelope->decrypt((string)$row->encrypted_payload, $this->scope($tenantId, $hotelId, $platform, $configId));
    return $consumer($payload);
}
```

`metadata()` must build an explicit allowlist rather than call `toArray()`.

- [x] **Step 5: Verify GREEN and schema wiring**

```powershell
C:\xampp\php\php.exe -l app\model\OtaCredential.php
C:\xampp\php\php.exe -l app\service\OtaCredentialVault.php
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialVaultTest.php
rg -n "20260710_create_ota_credentials.sql" database\init_full.sql
```

Expected: syntax and PHPUnit PASS; `init_full.sql` contains one source line.

- [ ] **Step 6: Commit the isolated vault files**

```powershell
git add -- database/migrations/20260710_create_ota_credentials.sql database/init_full.sql app/model/OtaCredential.php app/service/OtaCredentialVault.php tests/OtaCredentialVaultTest.php
git diff --cached --check
git commit -m "[安全] 建立OTA凭据租户保险库"
```

## Task 3: Strict hotel binding and read-only config reads

**Files:**

- Modify: `tests/OnlineDataTest.php`
- Modify: `app/controller/concern/OtaConfigConcern.php`

- [x] **Step 1: Extend the existing dirty test file before production edits**

Add failing tests that assert:

```php
self::assertTrue($this->invokeNonPublic($controller, 'otaConfigHasHotelBindingConflict', [[
    'system_hotel_id' => 64,
    'hotel_id' => 65,
]]));
self::assertFalse($this->invokeNonPublic($controller, 'isOtaConfigVisibleToUser', [[
    'system_hotel_id' => 64,
    'hotel_id' => 65,
], $user]));
```

Also add a query-spy assertion proving `normalizeStoredOtaConfigList()` performs no `update`, and an assertion that an unbound owner-only config remains invisible until an explicit permitted target hotel is supplied to a migration/binding operation.

- [x] **Step 2: Run the focused tests and verify RED**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OnlineDataTest.php --filter "OtaConfig|StoredOtaConfig"
```

Expected: FAIL because the conflict helper is absent and normalization still writes.

- [x] **Step 3: Make binding strict and reads pure**

Add:

```php
private function otaConfigHasHotelBindingConflict(array $item): bool
{
    $systemHotelId = $this->positiveOtaConfigHotelId($item['system_hotel_id'] ?? null);
    $legacyHotelId = $this->positiveOtaConfigHotelId($item['hotel_id'] ?? null);
    return $systemHotelId !== null && $legacyHotelId !== null && $systemHotelId !== $legacyHotelId;
}
```

Every visibility, maintenance, and collector resolver must reject conflicts before reading either ID. `normalizeStoredOtaConfigList()` must only return normalized values and must not call `Db::name(...)->update()`. Binding writes move exclusively to the migration service in Task 6.

- [x] **Step 4: Verify GREEN without staging user changes**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OnlineDataTest.php --filter "OtaConfig|StoredOtaConfig"
git diff --check -- app/controller/concern/OtaConfigConcern.php tests/OnlineDataTest.php
```

Expected: focused tests PASS. Do not commit these two files independently because they contained user changes before this plan.

## Task 4: Vault-backed CRUD and secret-free responses

**Files:**

- Create: `tests/OtaCredentialResponseTest.php`
- Modify: `app/controller/concern/OtaConfigConcern.php`
- Modify: `app/controller/concern/OnlineDataRequestConcern.php`
- Modify: `app/controller/concern/MeituanConfigConcern.php`
- Modify: `app/model/SystemConfig.php`
- Modify: `app/controller/SystemConfigController.php`
- Modify: `tests/SecurityInputGuardTest.php`

- [x] **Step 1: Write failing response and storage tests**

Use sentinel values `ctrip-cookie-secret` and `meituan-token-secret`. Assert all list, detail, save, and generic config response JSON excludes both sentinels, excludes the encrypted envelope, and contains only:

```php
[
    'credential_ref' => 'ctrip:cfg-1',
    'credential_status' => 'ready',
    'has_cookies' => true,
    'secret_mask' => 'ctri...cret',
]
```

Assert stored `system_configs.config_value` contains `credential_ref` but contains none of `cookies`, `cookie`, `auth_data`, `authorization`, `spidertoken`, `mtgsig`, `usertoken`, or `usersign` at any nesting level.

- [x] **Step 2: Verify RED**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialResponseTest.php tests\SecurityInputGuardTest.php
```

Expected: FAIL because detail and generic config endpoints still expose raw values.

- [x] **Step 3: Add recursive split and sanitize helpers**

`OtaConfigConcern` must define an explicit recursive secret-key matcher and return `[metadata, secretPayload]`. The secret set is:

```php
private const OTA_SECRET_FIELDS = [
    'cookies', 'cookie', 'auth_data', 'authorization', 'token', 'spidertoken',
    'mtgsig', 'usertoken', 'usersign', 'password', 'secret', 'headers_json',
];
```

Nested matching is case-insensitive and treats `set-cookie`, `access_token`, `refresh_token`, and authorization header names as sensitive. `sanitizeSecretConfig()` must never include ciphertext.

- [x] **Step 4: Make Ctrip and Meituan writes transactional**

For create/update:

```php
[$metadata, $secretPayload] = $this->splitOtaConfigSecrets($config);
$metadata = Db::transaction(function () use ($platform, $metadata, $secretPayload, $tenantId, $hotelId, $configId, $actorId, $key, $list): array {
    $credential = $this->otaCredentialVault()->store($tenantId, $hotelId, $platform, $configId, $secretPayload, $actorId);
    $metadata['credential_ref'] = $credential['credential_ref'];
    $metadata['credential_status'] = $credential['credential_status'];
    $metadata['has_cookies'] = $credential['has_cookies'];
    $list[$configId] = $metadata;
    SystemConfig::setValue($key, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $platform . '配置列表');
    return $metadata;
});
```

Delete removes both metadata and the exact scoped vault row in one transaction. Detail returns only sanitized metadata. Bookmark and comment-config saves use the same path.

- [x] **Step 5: Harden generic SystemConfig access**

- Remove `ctrip_config_list` and `meituan_config_list` from `DURABLE_VALUE_CACHE_KEYS`.
- Add `SystemConfig::isProtectedOtaKey(string $key): bool` and `SystemConfig::clearProtectedOtaCaches(): void`; OTA-specific controllers may write metadata, while generic controllers call the predicate to reject reads and writes.
- Reject generic `?key=` reads for protected OTA config keys with 403.
- Filter protected OTA keys out of full generic responses.
- Reject generic updates to protected OTA config keys with 403; only OTA-specific controllers may write metadata.
- Clear any existing durable cache entry after OTA metadata writes and migration.

`clearProtectedOtaCaches()` must unset the two process-cache entries and delete `system_config_value_<sha1>` cache keys for both config lists. It must not clear unrelated configuration caches.

- [x] **Step 6: Verify GREEN**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialResponseTest.php tests\SecurityInputGuardTest.php tests\OnlineDataTest.php --filter "OtaConfig|SystemConfig|Credential"
C:\xampp\php\php.exe scripts\verify_high_risk_security.php
```

Expected: all tests PASS and no sentinel secret appears in response or generic storage assertions.

## Task 5: Server-side execution access and frontend secret removal

**Files:**

- Create: `tests/OtaCredentialReadPathTest.php`
- Modify: `app/controller/concern/OnlineDataManualFetchConcern.php`
- Modify: `app/controller/concern/OnlineDataRequestConcern.php`
- Modify: `app/controller/concern/AutoFetchConcern.php`
- Modify: `app/controller/concern/CollectionReliabilityConcern.php`
- Modify: `app/command/AutoFetchOnlineData.php`
- Modify: `app/controller/Agent.php`
- Modify: `app/service/OperationManagementService.php`
- Modify: `app/service/PlatformDataSyncService.php`
- Modify: `tests/PlatformDataSyncServiceTest.php`
- Modify: `scripts/register_p0_ota_traffic_data_sources.php`
- Modify: `scripts/verify_p0_ota_field_loop_closure.php`
- Modify: `public/index.html`
- Modify: `public/ctrip-static.js`
- Modify: `public/auto-fetch-static.js`
- Modify: `public/ota-diagnosis-static.js`
- Modify: `tests/automation/manual_minimum_credential_ui.test.mjs`

- [x] **Step 1: Write failing read-path and UI contract tests**

Assert source files no longer directly read `ctrip_config_list`, `meituan_config_list`, `online_data_cookies_*`, or `data_config_*` for reusable secret material outside `OtaCredentialMigrationService`. Assert frontend fetch bodies contain `config_id` and omit `cookies`, `auth_data`, `spidertoken`, and `mtgsig` loaded from saved configuration.

```js
assert.match(indexHtml, /config_id:\s*String\(activeConfig\?\.id \|\| ''\)/);
assert.doesNotMatch(indexHtml, /ensureCtripConfigSecret/);
assert.doesNotMatch(ctripStatic, /activeConfig\.cookies/);
```

- [x] **Step 2: Verify RED**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialReadPathTest.php
node --test tests\automation\manual_minimum_credential_ui.test.mjs
```

Expected: FAIL because direct reads and frontend secret hydration still exist.

- [x] **Step 3: Add one internal execution boundary**

Add to `OtaConfigConcern`:

```php
private function withOtaCredentialForExecution(
    string $platform,
    string $configId,
    int $hotelId,
    callable $consumer,
    bool $internalCollector = false
) {
    $tenantId = $this->otaCredentialTenantIdForHotel($hotelId);
    if (!$internalCollector && !$this->currentUserCanMaintainOtaConfig($hotelId)) {
        throw new RuntimeException('Forbidden OTA credential scope.');
    }
    return $this->otaCredentialVault()->withPayloadForExecution($tenantId, $hotelId, $platform, $configId, $consumer);
}

private function otaCredentialTenantIdForHotel(int $hotelId): int
{
    $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
    if ($tenantId <= 0) {
        throw new RuntimeException('OTA credential tenant scope is missing.');
    }
    return $tenantId;
}
```

Manual endpoints accept `config_id` and execute the downstream request inside this callback. Auto-fetch commands create an explicitly internal collector context containing tenant, hotel, platform, and config ID. Status, AI, and operations paths call `metadata()` only and never decrypt.

`PlatformDataSyncService` stores only `credential_ref` in source metadata and sets legacy `secret_json` to an empty object after successful vault storage. Its collector path uses `withPayloadForExecution()`; API serialization never receives the decrypted payload.

- [x] **Step 4: Remove frontend credential hydration**

- Replace `loadCtripConfigDetail`/`ensureCtripConfigSecret` with metadata readiness checks.
- Fetch bodies send `config_id` and `system_hotel_id`.
- UI continues to accept a newly entered credential only in the save request; after save it clears the local secret field.
- Stored credential editing is replace-only: blank means keep current encrypted credential, explicit revoke uses a dedicated action.

- [x] **Step 5: Verify GREEN and static guards**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialReadPathTest.php
node --test tests\automation\manual_minimum_credential_ui.test.mjs
node scripts\verify_public_entry_guard.mjs
node scripts\verify_e2e_contracts.mjs
```

Expected: all commands PASS; no saved secret is returned to or cached by the browser.

## Task 6: Dry-run-first legacy migration

**Files:**

- Create: `tests/OtaCredentialMigrationServiceTest.php`
- Create: `app/service/OtaCredentialMigrationService.php`
- Create: `app/command/MigrateOtaCredentials.php`
- Modify: `config/console.php`
- Modify: `package.json`

- [x] **Step 1: Write failing migration tests**

Seed both `system_config` and `system_configs` variants with:

- verified bound Ctrip and Meituan records,
- unbound owner-only records,
- conflicting `system_hotel_id`/`hotel_id`,
- duplicate config IDs,
- nested `auth_data`,
- already migrated metadata.
- `platform_data_sources.secret_json` with reusable OTA secret material.
- `online_data_cookies_hotel_<id>` and `online_data_cookies_<id>` cache entries for known hotels.

Assert dry-run performs zero writes and returns only these classifications:

```php
[
    'bound_verified',
    'unbound',
    'field_conflict',
    'duplicate_config_id',
    'tenant_mismatch',
    'already_migrated',
]
```

Assert execute migrates only `bound_verified`, atomically removes plaintext, and returns row IDs/counts without any secret content. Any encryption or JSON failure rolls back both vault and config writes.

The inventory must scan both `system_config` and `system_configs`, all `data_config_*` keys, `platform_data_sources.secret_json`, and the two known per-hotel legacy cache key patterns. It must not enumerate unrelated cache namespaces.

- [x] **Step 2: Verify RED**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialMigrationServiceTest.php
```

Expected: FAIL because the service and command do not exist.

- [x] **Step 3: Implement inventory and execute modes**

The service public contract is:

```php
public function run(bool $execute): array
{
    $inventory = $this->inventoryLegacyConfigs();
    if (!$execute) {
        return $this->safeSummary('dry-run', $inventory, []);
    }
    return Db::transaction(function () use ($inventory): array {
        $migrated = [];
        foreach ($inventory as $item) {
            if ($item['classification'] !== 'bound_verified') {
                continue;
            }
            $migrated[] = $this->migrateItem($item);
        }
        SystemConfig::clearProtectedOtaCaches();
        return $this->safeSummary('execute', $inventory, $migrated);
    });
}
```

No normal service or controller may call `inventoryLegacyConfigs()`.

- [x] **Step 4: Register commands**

`config/console.php`:

```php
'migrate:ota-credentials' => 'app\command\MigrateOtaCredentials',
```

`package.json` scripts:

```json
"migrate:ota-credentials:dry-run": "C:\\xampp\\php\\php.exe think migrate:ota-credentials",
"migrate:ota-credentials:execute": "C:\\xampp\\php\\php.exe think migrate:ota-credentials --execute",
"verify:ota-credential-vault": "node scripts/verify_ota_credential_vault.mjs"
```

- [x] **Step 5: Verify GREEN in isolated tests only**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialMigrationServiceTest.php
C:\xampp\php\php.exe think list | Select-String "migrate:ota-credentials"
```

Expected: tests PASS and the command is registered. Do not execute against the user's real database during implementation.

## Task 7: Repository verifier and P0 acceptance

**Files:**

- Create: `scripts/verify_ota_credential_vault.mjs`
- Modify: `scripts/verify_high_risk_security.php`
- Modify: `scripts/verify_e2e_contracts.mjs`
- Modify: `scripts/verify_public_entry_guard.mjs`
- Modify: `package.json`

- [x] **Step 1: Write the failing repository verifier**

It must fail unless all are true:

- schema, model, envelope, vault, migration service, command, tests, and package scripts exist;
- `SystemConfig` does not durable-cache protected OTA keys;
- generic config index/update reject protected keys;
- detail endpoints call `sanitizeSecretConfig` and never return raw list items;
- runtime sources do not directly parse secret-bearing generic config values;
- frontend does not contain `ensureCtripConfigSecret` or saved-config secret caching;
- migration is dry-run by default and contains no secret-valued output fields.

- [x] **Step 2: Verify RED, then wire all guards**

```powershell
node scripts\verify_ota_credential_vault.mjs
```

Expected before wiring: FAIL with named missing contracts. After updating the guard files: PASS.

- [x] **Step 3: Run focused P0 acceptance**

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OtaCredentialEnvelopeTest.php tests\OtaCredentialVaultTest.php tests\OtaCredentialMigrationServiceTest.php tests\OtaCredentialResponseTest.php tests\OtaCredentialReadPathTest.php
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never --do-not-cache-result tests\OnlineDataTest.php --filter "OtaConfig|Credential|StoredOtaConfig"
node --test tests\automation\manual_minimum_credential_ui.test.mjs
node scripts\verify_ota_credential_vault.mjs
C:\xampp\php\php.exe scripts\verify_high_risk_security.php
npm.cmd run verify:p0-guards
git diff --check
```

Expected: every command PASS. If a command writes reports or runtime state, rerun only after confirming its output target remains excluded from Git and record that it was not used as source evidence.

- [x] **Step 4: Review the complete diff before any save point**

```powershell
git status --short
git diff --stat
git diff -- app/controller/concern/OtaConfigConcern.php tests/OnlineDataTest.php
```

Confirm the pre-existing changes in `AGENTS.md`, `OtaConfigConcern.php`, and `OnlineDataTest.php` are preserved. Do not commit or push the combined final P0 changes unless the user explicitly requests a project save point.

## P0 completion gate

P0 is complete only when:

1. no reusable OTA secret exists in generic config storage, generic cache, generic responses, platform detail responses, or browser state;
2. all runtime credential reads pass the full tenant/hotel/platform/config locator to the vault;
3. legacy plaintext is accessible only to the migration service;
4. unbound or conflicting legacy records remain explicit migration blockers;
5. all Task 7 commands pass without relying on static source matches alone.

## Execution status (2026-07-11)

- Credential/Vault implementation and the credential-specific P0 completion gate are complete.
- Final verification: `1135` PHPUnit tests / `11077` assertions, `verify:p0-guards`, `148` Vault contracts, high-risk security, `562` importer checks, and migration dry-run all passed.
- Migration dry-run reports `53` inventories already migrated, `0` blockers, `0` eligible rows, `0` remaining issues, and `0` metadata relocations.
- The two commit steps remain intentionally unchecked because the user did not request a save point; unrelated worktree changes were preserved.
- This credential gate is separate from the live OTA field-loop gate. The latter remains `incomplete` until an authorized same-day Profile session and target-date traffic evidence exist.
