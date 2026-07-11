<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCredentialEnvelope;
use app\service\OtaCredentialVault;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaCredentialResponseTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/ota_response_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100))');
        Db::execute('CREATE TABLE system_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key VARCHAR(50) NOT NULL UNIQUE, config_value TEXT, description VARCHAR(255), create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE ota_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id VARCHAR(100) NOT NULL, encrypted_payload TEXT NOT NULL, payload_version INTEGER NOT NULL, key_id VARCHAR(100) NOT NULL, secret_mask VARCHAR(255) NOT NULL, credential_status VARCHAR(20) NOT NULL, created_by INTEGER NOT NULL, rotated_at DATETIME, create_time DATETIME, update_time DATETIME, UNIQUE(tenant_id,system_hotel_id,platform,config_id))');
        Db::name('hotels')->insertAll([
            ['id' => 58, 'tenant_id' => 7, 'name' => 'A'],
            ['id' => 59, 'tenant_id' => 0, 'name' => 'Invalid tenant'],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('system_configs')->delete(true);
        Db::name('ota_credentials')->delete(true);
    }

    private function otaConfigHarness(?object $vault = null): object
    {
        return new class($vault) {
            use \app\controller\concern\OtaConfigConcern;

            public function __construct(private readonly ?object $replacementVault)
            {
            }

            protected function otaCredentialVault(): object
            {
                return $this->replacementVault ?? throw new \RuntimeException('Test vault was not provided.');
            }

            public function split(array $config): array
            {
                return $this->splitOtaConfigSecrets($config);
            }

            public function sanitize(array $config): array
            {
                return $this->sanitizeSecretConfig($config);
            }

            public function sanitizeRuntimeList(array $list): array
            {
                return $this->sanitizeStoredOtaConfigListForRuntime($list);
            }

            public function storedConfigList(string $platform): array
            {
                return $platform === 'meituan'
                    ? $this->getStoredMeituanConfigList()
                    : $this->getStoredCtripConfigList();
            }

            public function safeCredentialMetadata(array $vaultMetadata, array $secretPayload): array
            {
                return $this->buildSafeOtaCredentialMetadata($vaultMetadata, $secretPayload);
            }

            public function tenantId(int $hotelId): int
            {
                return $this->otaCredentialTenantIdForHotel($hotelId);
            }

            public function strictHotelId(mixed $value): int
            {
                return $this->strictPositiveOtaConfigHotelId($value);
            }

            public function storeCredential(
                int $hotelId,
                string $platform,
                string $configId,
                array $secretPayload,
                int $actorId,
                array $existingMetadata = []
            ): array {
                return $this->storeOtaConfigCredential(
                    $hotelId,
                    $platform,
                    $configId,
                    $secretPayload,
                    $actorId,
                    $existingMetadata
                );
            }

            public function deleteCredential(int $hotelId, string $platform, string $configId): bool
            {
                return $this->deleteOtaConfigCredential($hotelId, $platform, $configId);
            }

            public function persistCtrip(array $config, int $actorId, bool $isUpdate): array
            {
                return $this->persistCtripConfigMetadata($config, $actorId, $isUpdate);
            }

            public function persistMeituan(
                array $config,
                int $actorId,
                bool $isUpdate,
                ?string $expectedScope = null
            ): array
            {
                if (!method_exists($this, 'persistMeituanConfigMetadata')) {
                    \PHPUnit\Framework\Assert::fail('Meituan persistence boundary is missing.');
                }
                return $this->persistMeituanConfigMetadata($config, $actorId, $isUpdate, $expectedScope);
            }

            public function deleteMeituan(
                string $configId,
                int $hotelId,
                ?string $expectedScope = null
            ): array
            {
                if (!method_exists($this, 'deleteMeituanConfigMetadata')) {
                    \PHPUnit\Framework\Assert::fail('Meituan delete boundary is missing.');
                }
                return $this->deleteMeituanConfigMetadata($configId, $hotelId, $expectedScope);
            }

            public function isMeituanCommentMetadata(array $config): bool
            {
                return $this->isMeituanCommentConfigMetadata($config);
            }
        };
    }

    private function saveMeituanEndpointFailureHarness(\Throwable $failure): object
    {
        return new class($failure) {
            use \app\controller\concern\MeituanConfigConcern;

            public object $request;
            public object $currentUser;

            public function __construct(private readonly \Throwable $failure)
            {
                $this->request = new class {
                    public function post(?string $key = null, mixed $default = null): mixed
                    {
                        if ($key === null) {
                            return ['hotel_id' => 58, 'cookies' => 'meituan-token-secret'];
                        }

                        return match ($key) {
                            'hotel_id' => 58,
                            'cookies' => 'meituan-token-secret',
                            default => $default,
                        };
                    }
                };
                $this->currentUser = new class {
                    public int $id = 77;
                    public function isSuperAdmin(): bool { return true; }
                };
            }

            private function checkPermission(): void
            {
            }

            protected function requestData(): array
            {
                return ['hotel_id' => 58, 'cookies' => 'meituan-token-secret'];
            }

            private function saveMeituanConfigPayload(
                array $requestData,
                bool $allowCreateWithProvidedId = false,
                string $defaultScope = ''
            ): array
            {
                throw $this->failure;
            }

            protected function error(string $message = '操作失败', int $code = 400, mixed $data = null): \think\Response
            {
                return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
            }
        };
    }

    private function deleteMeituanPermissionFailureHarness(): object
    {
        return new class {
            use \app\controller\concern\OtaConfigConcern;
            use \app\controller\concern\MeituanConfigConcern;

            public object $request;
            public object $currentUser;

            public function __construct()
            {
                $this->request = new class {
                    public function param(string $key, mixed $default = null): mixed
                    {
                        return $key === 'id' ? 'meituan-58' : $default;
                    }
                };
                $this->currentUser = new class {
                    public int $id = 77;
                    public function isSuperAdmin(): bool { return false; }
                    public function getPermittedHotelIds(): array { return [58]; }
                    public function canManageOwnHotels(): bool { return false; }
                    public function hasPermission(string $permission): bool { return false; }
                };
            }

            private function checkPermission(): void
            {
            }

            private function checkActionPermission(string $permission): void
            {
                throw new \think\exception\HttpException(403, 'permission denied');
            }

            protected function error(string $message = '操作失败', int $code = 400, mixed $data = null): \think\Response
            {
                return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
            }
        };
    }

    private function saveEndpointFailureHarness(\Throwable $failure): object
    {
        return new class($failure) {
            use \app\controller\concern\OnlineDataRequestConcern;

            public function __construct(private readonly \Throwable $failure)
            {
            }

            private function checkPermission(): void
            {
            }

            protected function requestData(): array
            {
                return [];
            }

            private function saveCtripConfigPayload(array $requestData): array
            {
                throw $this->failure;
            }

            protected function error(string $message = '操作失败', int $code = 400, mixed $data = null): \think\Response
            {
                return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
            }
        };
    }

    private function deletePermissionFailureHarness(): object
    {
        return new class {
            use \app\controller\concern\OtaConfigConcern;
            use \app\controller\concern\OnlineDataRequestConcern;

            public object $request;
            public object $currentUser;

            public function __construct()
            {
                $this->request = new class {
                    public function param(string $key, mixed $default = null): mixed
                    {
                        return $key === 'id' ? 'cfg-58' : $default;
                    }
                };
                $this->currentUser = new class {
                    public int $id = 77;
                    public function isSuperAdmin(): bool { return false; }
                    public function getPermittedHotelIds(): array { return [58]; }
                    public function canManageOwnHotels(): bool { return false; }
                    public function hasPermission(string $permission): bool { return false; }
                };
            }

            private function checkPermission(): void
            {
            }

            private function checkActionPermission(string $permission): void
            {
                throw new \think\exception\HttpException(403, 'permission denied');
            }

            protected function error(string $message = '操作失败', int $code = 400, mixed $data = null): \think\Response
            {
                return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
            }
        };
    }

    public function testStoredOtaConfigReadFailsClosedOnInvalidJsonInsteadOfReturningEmpty(): void
    {
        foreach (['ctrip' => 'ctrip_config_list', 'meituan' => 'meituan_config_list'] as $platform => $key) {
            Db::name('system_configs')->delete(true);
            Db::name('system_configs')->insert([
                'config_key' => $key,
                'config_value' => '{"invalid":',
            ]);

            $exception = null;
            try {
                $this->otaConfigHarness()->storedConfigList($platform);
            } catch (\RuntimeException $caught) {
                $exception = $caught;
            }

            self::assertInstanceOf(\RuntimeException::class, $exception, "{$platform} invalid metadata must fail closed.");
            self::assertSame("Stored {$platform} config metadata is invalid.", $exception->getMessage());
        }
    }

    public function testStoredOtaConfigReadTreatsOnlyAMissingRowAsEmpty(): void
    {
        self::assertSame([], $this->otaConfigHarness()->storedConfigList('ctrip'));
        self::assertSame([], $this->otaConfigHarness()->storedConfigList('meituan'));
    }

    public function testSafeCredentialMetadataExposesOnlyOpaqueReadinessFields(): void
    {
        $metadata = $this->otaConfigHarness()->safeCredentialMetadata([
            'credential_ref' => 321,
            'credential_status' => 'ready',
            'secret_mask' => 'ct****et',
            'key_id' => 'must-not-leak',
            'payload_version' => 1,
            'encrypted_payload' => 'ota-cred:v1:ciphertext',
            'ciphertext' => 'ciphertext-secret',
        ], [
            'cookies' => 'ctrip-cookie-secret',
        ]);

        self::assertSame([
            'credential_ref' => 321,
            'credential_status' => 'ready',
            'has_cookies' => true,
            'secret_mask' => 'ct****et',
        ], $metadata);
        $encoded = (string)json_encode($metadata, JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('must-not-leak', $encoded);
        self::assertStringNotContainsString('ota-cred:v1:', $encoded);
        self::assertStringNotContainsString('ctrip-cookie-secret', $encoded);
    }

    public function testSafeCredentialMetadataRejectsInvalidReferenceAndStatus(): void
    {
        foreach ([0, '0', -1, 'abc', true, 1.5] as $invalidRef) {
            try {
                $this->otaConfigHarness()->safeCredentialMetadata([
                    'credential_ref' => $invalidRef,
                    'credential_status' => 'ready',
                    'secret_mask' => '',
                ], []);
                self::fail('Invalid credential reference must be rejected.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('metadata', strtolower($e->getMessage()));
            }
        }

        foreach (['', 'active', 'pending', 'READY', 'arbitrary'] as $invalidStatus) {
            try {
                $this->otaConfigHarness()->safeCredentialMetadata([
                    'credential_ref' => 12,
                    'credential_status' => $invalidStatus,
                    'secret_mask' => '',
                ], []);
                self::fail('Invalid credential status must be rejected.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('metadata', strtolower($e->getMessage()));
            }
        }

        $normalized = $this->otaConfigHarness()->safeCredentialMetadata([
            'credential_ref' => '12',
            'credential_status' => 'revoked',
            'secret_mask' => '',
        ], []);
        self::assertSame(12, $normalized['credential_ref']);
        self::assertSame('revoked', $normalized['credential_status']);
    }

    public function testStrictHotelIdAcceptsOnlyPositiveIntegerOrCanonicalIntegerString(): void
    {
        self::assertSame(58, $this->otaConfigHarness()->strictHotelId(58));
        self::assertSame(58, $this->otaConfigHarness()->strictHotelId('58'));
        self::assertSame(58, $this->otaConfigHarness()->strictHotelId(' 58 '));

        foreach ([58.9, '58.9', '5.8e1', true, false, 0, '0', '01', -1, '-1', '   '] as $invalid) {
            try {
                $this->otaConfigHarness()->strictHotelId($invalid);
                self::fail('Non-canonical hotel ID must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('hotel', strtolower($e->getMessage()));
            }
        }
    }

    public function testCredentialStoreUsesRealTenantHotelPlatformAndConfigLocator(): void
    {
        $vault = new class {
            public array $storeCalls = [];

            public function store(int $tenantId, int $hotelId, string $platform, string $configId, array $payload, int $actorId): array
            {
                $this->storeCalls[] = func_get_args();
                return [
                    'credential_ref' => 901,
                    'credential_status' => 'ready',
                    'secret_mask' => 'ct****et',
                    'key_id' => 'must-not-leak',
                    'encrypted_payload' => 'ota-cred:v1:ciphertext',
                ];
            }
        };

        $metadata = $this->otaConfigHarness($vault)->storeCredential(
            58,
            'ctrip',
            'cfg-58',
            ['cookies' => 'ctrip-cookie-secret'],
            77
        );

        self::assertSame([7, 58, 'ctrip', 'cfg-58', ['cookies' => 'ctrip-cookie-secret'], 77], $vault->storeCalls[0]);
        self::assertSame([
            'credential_ref' => 901,
            'credential_status' => 'ready',
            'has_cookies' => true,
            'secret_mask' => 'ct****et',
        ], $metadata);
    }

    public function testEmptySecretUpdatePreservesExistingMetadataWithoutCallingStore(): void
    {
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                throw new \RuntimeException('store must not be called');
            }
        };
        $existing = [
            'credential_ref' => 901,
            'credential_status' => 'ready',
            'has_cookies' => true,
            'secret_mask' => 'ct****et',
            'key_id' => 'legacy-key-must-not-leak',
        ];

        $metadata = $this->otaConfigHarness($vault)->storeCredential(58, 'ctrip', 'cfg-58', ['cookies' => ''], 77, $existing);

        self::assertSame(0, $vault->storeCalls);
        self::assertSame([
            'credential_ref' => 901,
            'credential_status' => 'ready',
            'has_cookies' => true,
            'secret_mask' => 'ct****et',
        ], $metadata);
    }

    public function testEmptySecretUpdateRejectsInvalidExistingCredentialMetadata(): void
    {
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                return [];
            }
        };
        $invalidMetadata = [
            ['credential_ref' => 0, 'credential_status' => 'ready'],
            ['credential_ref' => 'abc', 'credential_status' => 'ready'],
            ['credential_ref' => 901, 'credential_status' => 'arbitrary'],
        ];

        foreach ($invalidMetadata as $existing) {
            try {
                $this->otaConfigHarness($vault)->storeCredential(
                    58,
                    'ctrip',
                    'cfg-58',
                    ['cookies' => ''],
                    77,
                    array_merge($existing, ['has_cookies' => true, 'secret_mask' => 'ct****et'])
                );
                self::fail('Invalid existing credential metadata must be rejected.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('metadata', strtolower($e->getMessage()));
            }
        }

        self::assertSame(0, $vault->storeCalls);
    }

    public function testCredentialDeleteUsesExactLocator(): void
    {
        $vault = new class {
            public array $deleteCalls = [];
            public function delete(int $tenantId, int $hotelId, string $platform, string $configId): bool
            {
                $this->deleteCalls[] = func_get_args();
                return true;
            }
        };

        self::assertTrue($this->otaConfigHarness($vault)->deleteCredential(58, 'ctrip', 'cfg-58'));
        self::assertSame([[7, 58, 'ctrip', 'cfg-58']], $vault->deleteCalls);
    }

    public function testCredentialTenantLookupRejectsMissingOrInvalidTenant(): void
    {
        self::assertSame(7, $this->otaConfigHarness()->tenantId(58));

        foreach ([59, 999] as $hotelId) {
            try {
                $this->otaConfigHarness()->tenantId($hotelId);
                self::fail('Invalid tenant scope must be rejected.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('tenant', strtolower($e->getMessage()));
            }
        }
    }

    public function testSanitizeSecretConfigDropsVaultInternalMetadata(): void
    {
        $sanitized = $this->otaConfigHarness()->sanitize([
            'id' => 'cfg-58',
            'credential_ref' => 901,
            'credential_status' => 'ready',
            'has_cookies' => true,
            'secret_mask' => 'ct****et',
            'key_id' => 'must-not-leak',
            'payload_version' => 1,
            'encrypted_payload' => 'ota-cred:v1:ciphertext',
        ]);

        self::assertSame('cfg-58', $sanitized['id']);
        self::assertSame(901, $sanitized['credential_ref']);
        self::assertArrayNotHasKey('key_id', $sanitized);
        self::assertArrayNotHasKey('payload_version', $sanitized);
        self::assertArrayNotHasKey('encrypted_payload', $sanitized);
    }

    public function testCtripPersistenceStoresOnlySafeMetadataAndReturnsNoSecretOrCiphertext(): void
    {
        $vault = new class {
            public array $storeCalls = [];
            public function store(int $tenantId, int $hotelId, string $platform, string $configId, array $payload, int $actorId): array
            {
                $this->storeCalls[] = func_get_args();
                return [
                    'credential_ref' => 902,
                    'credential_status' => 'ready',
                    'secret_mask' => 'ct****et',
                    'key_id' => 'must-not-leak',
                    'encrypted_payload' => 'ota-cred:v1:ciphertext',
                ];
            }
        };

        $saved = $this->otaConfigHarness($vault)->persistCtrip([
            'id' => 'cfg-58',
            'config_id' => 'cfg-58',
            'name' => 'Ctrip A',
            'hotel_id' => '58',
            'system_hotel_id' => 58,
            'cookies' => 'ctrip-cookie-secret',
            'auth_data' => ['token' => 'nested-secret'],
        ], 77, false);

        $raw = (string)Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
        $responseJson = (string)json_encode($saved, JSON_UNESCAPED_SLASHES);
        self::assertSame([7, 58, 'ctrip', 'cfg-58'], array_slice($vault->storeCalls[0], 0, 4));
        self::assertStringContainsString('"credential_ref":902', $raw);
        foreach (['ctrip-cookie-secret', 'nested-secret', 'must-not-leak', 'ota-cred:v1:', 'encrypted_payload', 'ciphertext'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw);
            self::assertStringNotContainsString($forbidden, $responseJson);
        }
        $stored = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $keys = [];
        $collectKeys = static function (array $value) use (&$collectKeys, &$keys): void {
            foreach ($value as $key => $nested) {
                if (is_string($key)) {
                    $keys[] = strtolower($key);
                }
                if (is_array($nested)) {
                    $collectKeys($nested);
                }
            }
        };
        $collectKeys($stored);
        foreach (['cookies', 'cookie', 'auth_data', 'authorization', 'spidertoken', 'mtgsig', 'usertoken', 'usersign'] as $secretKey) {
            self::assertNotContains($secretKey, $keys);
        }
        self::assertSame(902, $saved['credential_ref']);
        self::assertTrue($saved['has_cookies']);
    }

    public function testCtripPersistenceEmptySecretUpdateKeepsCredentialMetadataWithoutStore(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => json_encode([
                'cfg-58' => [
                    'id' => 'cfg-58',
                    'config_id' => 'cfg-58',
                    'name' => 'Before',
                    'hotel_id' => '58',
                    'system_hotel_id' => 58,
                    'credential_ref' => 902,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'ct****et',
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                throw new \RuntimeException('store must not be called');
            }
        };

        $saved = $this->otaConfigHarness($vault)->persistCtrip([
            'id' => 'cfg-58',
            'config_id' => 'cfg-58',
            'name' => 'After',
            'hotel_id' => 58,
            'system_hotel_id' => 58,
            'cookies' => '',
        ], 77, true);

        self::assertSame(0, $vault->storeCalls);
        self::assertSame(902, $saved['credential_ref']);
        self::assertTrue($saved['has_cookies']);
        self::assertSame('After', $saved['name']);
        $raw = (string)Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
        self::assertStringNotContainsString('"cookies"', $raw);
        self::assertStringContainsString('"credential_ref":902', $raw);
    }

    public function testCtripPersistenceJsonFailureRollsBackVaultAndMetadataTogether(): void
    {
        $vault = new OtaCredentialVault(
            new OtaCredentialEnvelope(base64_encode(str_repeat('k', 32)), 'test-key'),
            'test-key'
        );

        try {
            $this->otaConfigHarness($vault)->persistCtrip([
                'id' => 'cfg-58',
                'config_id' => 'cfg-58',
                'name' => "\xB1\x31",
                'hotel_id' => 58,
                'system_hotel_id' => 58,
                'cookies' => 'ctrip-cookie-secret',
            ], 77, false);
            self::fail('Invalid JSON metadata must fail.');
        } catch (\JsonException $e) {
            self::assertStringContainsString('UTF-8', $e->getMessage());
        }

        self::assertSame(0, Db::name('ota_credentials')->count());
        self::assertSame(0, Db::name('system_configs')->count());
    }

    public function testCtripPersistenceRejectsLegacySiblingSecretBeforeVaultOrMetadataWrite(): void
    {
        $original = json_encode([
            'legacy-sibling' => [
                'id' => 'legacy-sibling',
                'config_id' => 'legacy-sibling',
                'name' => 'Legacy',
                'hotel_id' => '58',
                'system_hotel_id' => 58,
                'cookies' => 'legacy-sibling-cookie-secret',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $original,
        ]);
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                return [
                    'credential_ref' => 903,
                    'credential_status' => 'ready',
                    'secret_mask' => 'ct****et',
                ];
            }
        };

        try {
            $this->otaConfigHarness($vault)->persistCtrip([
                'id' => 'cfg-58',
                'config_id' => 'cfg-58',
                'name' => 'New target',
                'hotel_id' => 58,
                'system_hotel_id' => 58,
                'cookies' => 'ctrip-cookie-secret',
            ], 77, false);
            self::fail('Legacy sibling plaintext must block the whole save.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('migrate', strtolower($e->getMessage()));
        }

        self::assertSame(0, $vault->storeCalls);
        self::assertSame($original, Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value'));
        self::assertSame(0, Db::name('ota_credentials')->count());
    }

    public function testCtripPersistencePreservesSafeSiblingsAndRemovesOnlyEmptySecretsAndInternalFields(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => json_encode([
                'safe-sibling' => [
                    'id' => 'safe-sibling',
                    'config_id' => 'safe-sibling',
                    'name' => 'Safe sibling',
                    'hotel_id' => '58',
                    'system_hotel_id' => 58,
                    'credential_ref' => 700,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'sa****fe',
                    'cookies' => '',
                    'key_id' => 'legacy-internal-key',
                    'payload_version' => 1,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                return [
                    'credential_ref' => 904,
                    'credential_status' => 'ready',
                    'secret_mask' => 'ct****et',
                ];
            }
        };

        $this->otaConfigHarness($vault)->persistCtrip([
            'id' => 'cfg-58',
            'config_id' => 'cfg-58',
            'name' => 'New target',
            'hotel_id' => 58,
            'system_hotel_id' => 58,
            'cookies' => 'ctrip-cookie-secret',
        ], 77, false);

        $stored = json_decode((string)Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $vault->storeCalls);
        self::assertSame('Safe sibling', $stored['safe-sibling']['name']);
        self::assertSame(700, $stored['safe-sibling']['credential_ref']);
        self::assertArrayNotHasKey('cookies', $stored['safe-sibling']);
        self::assertArrayNotHasKey('key_id', $stored['safe-sibling']);
        self::assertArrayNotHasKey('payload_version', $stored['safe-sibling']);
        self::assertSame(904, $stored['cfg-58']['credential_ref']);
    }

    public function testCtripCrudUsesSharedVaultAndLegacyBookmarkSaveIsDisabled(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php');

        self::assertStringContainsString('saveCtripConfigPayload($requestData)', $source);
        self::assertStringContainsString('persistCtripConfigMetadata($config, (int)$this->currentUser->id, $isUpdate)', $source);
        self::assertStringContainsString('sanitizeStoredOtaConfigListForRuntime([$id => $list[$id]])', $source);
        self::assertStringContainsString('return $this->success($safeList[$id] ?? [])', $source);
        self::assertStringContainsString('deleteOtaConfigCredential($systemHotelId, \'ctrip\', $id)', $source);
        self::assertStringContainsString('旧版携程 Cookie 书签保存入口已禁用。', $source);
        self::assertMatchesRegularExpression(
            '/public function saveCtripConfigByBookmark\(\): Response[\s\S]*?checkPermission\(\)[\s\S]*?410[\s\S]*?\n\s*}/',
            $source
        );
        self::assertStringNotContainsString('saveCtripConfigPayload($data)', $source);
    }

    public function testSaveCtripConfigReturnsOpaqueHttp500ForThrowable(): void
    {
        $response = $this->saveEndpointFailureHarness(new \Error('vault-internal-secret-message'))->saveCtripConfig();
        $content = (string)$response->getContent();

        self::assertSame(500, $response->getCode());
        self::assertStringContainsString('"message":"保存失败"', $content);
        self::assertStringNotContainsString('vault-internal-secret-message', $content);
    }

    public function testDeleteCtripConfigPreservesPermissionHttp403(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => json_encode([
                'cfg-58' => [
                    'id' => 'cfg-58',
                    'config_id' => 'cfg-58',
                    'name' => 'Ctrip A',
                    'hotel_id' => '58',
                    'system_hotel_id' => 58,
                    'credential_ref' => 902,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'ct****et',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $response = $this->deletePermissionFailureHarness()->deleteCtripConfig();

        self::assertSame(403, $response->getCode());
        self::assertStringContainsString('permission denied', (string)$response->getContent());
    }

    public function testMeituanPersistenceStoresOnlySafeMetadataAndUsesExactLocator(): void
    {
        $vault = new class {
            public array $storeCalls = [];

            public function store(int $tenantId, int $hotelId, string $platform, string $configId, array $payload, int $actorId): array
            {
                $this->storeCalls[] = func_get_args();
                return [
                    'credential_ref' => 910,
                    'credential_status' => 'ready',
                    'secret_mask' => 'mt****et',
                    'key_id' => 'internal-key-must-not-leak',
                    'payload_version' => 1,
                    'encrypted_payload' => 'ota-cred:v1:meituan-ciphertext',
                ];
            }
        };

        $saved = $this->otaConfigHarness($vault)->persistMeituan([
            'id' => 'meituan-58',
            'config_id' => 'meituan-58',
            'name' => 'Meituan A',
            'hotel_id' => '58',
            'system_hotel_id' => 58,
            'partner_id' => 'partner-58',
            'poi_id' => 'poi-58',
            'cookies' => 'meituan-token-secret',
            'auth_data' => ['nested' => ['usertoken' => 'nested-auth-secret']],
            'key_id' => 'request-internal-key',
            'payload_version' => 99,
        ], 77, false);

        self::assertSame([7, 58, 'meituan', 'meituan-58'], array_slice($vault->storeCalls[0], 0, 4));
        self::assertSame('meituan-token-secret', $vault->storeCalls[0][4]['cookies']);
        self::assertSame('nested-auth-secret', $vault->storeCalls[0][4]['auth_data']['nested']['usertoken']);
        self::assertArrayNotHasKey('key_id', $vault->storeCalls[0][4]);
        self::assertArrayNotHasKey('payload_version', $vault->storeCalls[0][4]);

        $raw = (string)Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value');
        $responseJson = (string)json_encode($saved, JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('"credential_ref":910', $raw);
        foreach ([
            'meituan-token-secret',
            'nested-auth-secret',
            'internal-key-must-not-leak',
            'request-internal-key',
            'ota-cred:v1:',
            'encrypted_payload',
            'ciphertext',
            'key_id',
            'payload_version',
            'auth_data',
            'usertoken',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw);
            self::assertStringNotContainsString($forbidden, $responseJson);
        }
        self::assertSame(910, $saved['credential_ref']);
        self::assertTrue($saved['has_cookies']);
    }

    public function testMeituanPersistenceEmptySecretUpdateKeepsCredentialMetadataWithoutStore(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => json_encode([
                'meituan-58' => [
                    'id' => 'meituan-58',
                    'config_id' => 'meituan-58',
                    'name' => 'Before',
                    'hotel_id' => '58',
                    'system_hotel_id' => 58,
                    'partner_id' => 'partner-58',
                    'poi_id' => 'poi-58',
                    'credential_ref' => 910,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'mt****et',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                throw new \RuntimeException('store must not be called');
            }
        };

        $saved = $this->otaConfigHarness($vault)->persistMeituan([
            'id' => 'meituan-58',
            'config_id' => 'meituan-58',
            'name' => 'After',
            'hotel_id' => 58,
            'system_hotel_id' => 58,
            'partner_id' => 'partner-58',
            'poi_id' => 'poi-58',
            'cookies' => '',
            'auth_data' => ['nested' => ['token' => '']],
        ], 77, true);

        self::assertSame(0, $vault->storeCalls);
        self::assertSame(910, $saved['credential_ref']);
        self::assertTrue($saved['has_cookies']);
        self::assertSame('After', $saved['name']);
        $raw = (string)Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value');
        self::assertStringNotContainsString('"cookies"', $raw);
        self::assertStringNotContainsString('"auth_data"', $raw);
        self::assertStringContainsString('"credential_ref":910', $raw);

        $stored = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $stored['meituan-58']['scope'] = 'ota_channel_review_summary';
        Db::name('system_configs')->where('config_key', 'meituan_config_list')->update([
            'config_value' => json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $scopeFailure = null;
        try {
            $this->otaConfigHarness($vault)->persistMeituan([
                'id' => 'meituan-58',
                'config_id' => 'meituan-58',
                'name' => 'Must not cross scope',
                'hotel_id' => 58,
                'system_hotel_id' => 58,
                'scope' => '',
                'cookies' => '',
            ], 77, true, '');
        } catch (\RuntimeException $e) {
            $scopeFailure = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $scopeFailure, 'The locked Meituan scope must not change during update.');
        self::assertStringContainsString('scope', strtolower($scopeFailure->getMessage()));
        self::assertSame(0, $vault->storeCalls);
    }

    public function testMeituanPersistenceRejectsNewConfigWithoutSecretBeforeAnyWrite(): void
    {
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                return [];
            }
        };

        try {
            $this->otaConfigHarness($vault)->persistMeituan([
                'id' => 'meituan-58',
                'config_id' => 'meituan-58',
                'hotel_id' => 58,
                'system_hotel_id' => 58,
                'cookies' => '',
                'auth_data' => [],
            ], 77, false);
            self::fail('A new Meituan config without a credential must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('credential', strtolower($e->getMessage()));
        }

        self::assertSame(0, $vault->storeCalls);
        self::assertSame(0, Db::name('ota_credentials')->count());
        self::assertSame(0, Db::name('system_configs')->count());
    }

    public function testMeituanPersistenceRejectsLegacySiblingSecretBeforeVaultOrMetadataWrite(): void
    {
        $original = json_encode([
            'legacy-sibling' => [
                'id' => 'legacy-sibling',
                'config_id' => 'legacy-sibling',
                'name' => 'Legacy',
                'hotel_id' => '58',
                'system_hotel_id' => 58,
                'auth_data' => ['nested' => ['token' => 'legacy-meituan-secret']],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => $original,
        ]);
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                return [
                    'credential_ref' => 911,
                    'credential_status' => 'ready',
                    'secret_mask' => 'mt****et',
                ];
            }
        };

        try {
            $this->otaConfigHarness($vault)->persistMeituan([
                'id' => 'meituan-58',
                'config_id' => 'meituan-58',
                'name' => 'New target',
                'hotel_id' => 58,
                'system_hotel_id' => 58,
                'cookies' => 'meituan-token-secret',
            ], 77, false);
            self::fail('Legacy sibling plaintext must block the whole save.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('migrate', strtolower($e->getMessage()));
        }

        self::assertSame(0, $vault->storeCalls);
        self::assertSame($original, Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value'));
        self::assertSame(0, Db::name('ota_credentials')->count());
    }

    public function testMeituanPersistenceRequiresTask6BeforeUpdatingLegacyTargetEvenWithNewSecret(): void
    {
        $original = json_encode([
            'meituan-58' => [
                'id' => 'meituan-58',
                'config_id' => 'meituan-58',
                'name' => 'Legacy target',
                'hotel_id' => '58',
                'system_hotel_id' => 58,
                'cookies' => 'legacy-target-plaintext',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => $original,
        ]);
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                return [
                    'credential_ref' => 913,
                    'credential_status' => 'ready',
                    'secret_mask' => 'mt****et',
                ];
            }
        };

        $failure = null;
        try {
            $this->otaConfigHarness($vault)->persistMeituan([
                'id' => 'meituan-58',
                'config_id' => 'meituan-58',
                'name' => 'New request must not migrate legacy target',
                'hotel_id' => 58,
                'system_hotel_id' => 58,
                'cookies' => 'new-meituan-token-secret',
            ], 77, true);
        } catch (\RuntimeException $e) {
            $failure = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('task6', strtolower($failure->getMessage()));
        self::assertStringContainsString('normal save', strtolower($failure->getMessage()));
        self::assertSame(0, $vault->storeCalls);
        self::assertSame($original, Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value'));
        self::assertSame(0, Db::name('ota_credentials')->count());
    }

    public function testMeituanPersistenceSanitizesSafeSiblingsWithoutChangingTheirCredentialMetadata(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => json_encode([
                'safe-sibling' => [
                    'id' => 'safe-sibling',
                    'config_id' => 'safe-sibling',
                    'name' => 'Safe sibling',
                    'hotel_id' => '58',
                    'system_hotel_id' => 58,
                    'credential_ref' => 700,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'sa****fe',
                    'cookies' => '',
                    'auth_data' => ['token' => ''],
                    'key_id' => 'legacy-internal-key',
                    'payload_version' => 1,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $vault = new class {
            public function store(): array
            {
                return [
                    'credential_ref' => 912,
                    'credential_status' => 'ready',
                    'secret_mask' => 'mt****et',
                ];
            }
        };

        $this->otaConfigHarness($vault)->persistMeituan([
            'id' => 'meituan-58',
            'config_id' => 'meituan-58',
            'name' => 'New target',
            'hotel_id' => 58,
            'system_hotel_id' => 58,
            'cookies' => 'meituan-token-secret',
        ], 77, false);

        $stored = json_decode((string)Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(700, $stored['safe-sibling']['credential_ref']);
        self::assertTrue($stored['safe-sibling']['has_cookies']);
        foreach (['cookies', 'auth_data', 'key_id', 'payload_version'] as $forbiddenKey) {
            self::assertArrayNotHasKey($forbiddenKey, $stored['safe-sibling']);
        }
        self::assertSame(912, $stored['meituan-58']['credential_ref']);
    }

    public function testMeituanPersistenceJsonFailureRollsBackVaultAndMetadataTogether(): void
    {
        $vault = new OtaCredentialVault(
            new OtaCredentialEnvelope(base64_encode(str_repeat('k', 32)), 'test-key'),
            'test-key'
        );

        try {
            $this->otaConfigHarness($vault)->persistMeituan([
                'id' => 'meituan-58',
                'config_id' => 'meituan-58',
                'name' => "\xB1\x31",
                'hotel_id' => 58,
                'system_hotel_id' => 58,
                'cookies' => 'meituan-token-secret',
            ], 77, false);
            self::fail('Invalid JSON metadata must fail.');
        } catch (\JsonException $e) {
            self::assertStringContainsString('UTF-8', $e->getMessage());
        }

        self::assertSame(0, Db::name('ota_credentials')->count());
        self::assertSame(0, Db::name('system_configs')->count());
    }

    public function testMeituanDeleteRemovesExactVaultLocatorAndOnlyTargetMetadata(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => json_encode([
                'meituan-58' => [
                    'id' => 'meituan-58',
                    'config_id' => 'meituan-58',
                    'name' => 'Delete target',
                    'hotel_id' => '58',
                    'system_hotel_id' => 58,
                    'credential_ref' => 910,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'mt****et',
                ],
                'keep-me' => [
                    'id' => 'keep-me',
                    'config_id' => 'keep-me',
                    'name' => 'Keep sibling',
                    'hotel_id' => '58',
                    'system_hotel_id' => 58,
                    'credential_ref' => 700,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'ke****ep',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $vault = new class {
            public array $deleteCalls = [];
            public function delete(int $tenantId, int $hotelId, string $platform, string $configId): bool
            {
                $this->deleteCalls[] = func_get_args();
                return true;
            }
        };

        $deleted = $this->otaConfigHarness($vault)->deleteMeituan('meituan-58', 58);

        self::assertSame([[7, 58, 'meituan', 'meituan-58']], $vault->deleteCalls);
        self::assertSame('Delete target', $deleted['name']);
        $stored = json_decode((string)Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('meituan-58', $stored);
        self::assertSame('Keep sibling', $stored['keep-me']['name']);
    }

    public function testMeituanCommentScopeDetectionHandlesAliasesArraysAndTokenLists(): void
    {
        $harness = $this->otaConfigHarness();
        $depthFour = 'traffic';
        for ($depth = 0; $depth < 4; $depth++) {
            $depthFour = [$depthFour];
        }
        $depthFive = [$depthFour];

        self::assertTrue($harness->isMeituanCommentMetadata(['captureSections' => 'traffic reviews']));
        self::assertTrue($harness->isMeituanCommentMetadata(['profileSections' => 'business,reviews']));
        self::assertTrue($harness->isMeituanCommentMetadata(['capture_sections' => ['traffic', 'reviews']]));
        self::assertTrue($harness->isMeituanCommentMetadata(['profile_sections' => ['business,reviews']]));
        self::assertTrue($harness->isMeituanCommentMetadata(['scope' => ['ota_channel_review_summary']]));
        self::assertTrue($harness->isMeituanCommentMetadata(['scope' => str_repeat('a', 1025)]));
        self::assertTrue($harness->isMeituanCommentMetadata(['privacy_boundary' => array_fill(0, 5, str_repeat('b', 900))]));
        self::assertTrue($harness->isMeituanCommentMetadata(['captureSections' => array_fill(0, 65, 'traffic')]));
        self::assertTrue($harness->isMeituanCommentMetadata(['profileSections' => $depthFive]));
        self::assertTrue($harness->isMeituanCommentMetadata(['privacyBoundary' => new \stdClass()]));
        self::assertTrue($harness->isMeituanCommentMetadata(['scope' => 7]));
        self::assertTrue($harness->isMeituanCommentMetadata(['capture_sections' => true]));
        self::assertTrue($harness->isMeituanCommentMetadata(['profile_sections' => null]));
        self::assertFalse($harness->isMeituanCommentMetadata(['captureSections' => 'traffic preview']));
        self::assertFalse($harness->isMeituanCommentMetadata(['scope' => str_repeat('a', 1024)]));
        self::assertFalse($harness->isMeituanCommentMetadata(['captureSections' => array_fill(0, 64, 'traffic')]));
        self::assertFalse($harness->isMeituanCommentMetadata(['profileSections' => $depthFour]));
    }

    public function testMeituanPersistenceRejectsInvalidProtectedMetadataBeforeVaultWrite(): void
    {
        $depthFive = 'traffic';
        for ($depth = 0; $depth < 5; $depth++) {
            $depthFive = [$depthFive];
        }
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);
        $cases = [
            ['scope' => str_repeat('a', 1025)],
            ['privacy_boundary' => array_fill(0, 5, str_repeat('b', 900))],
            ['captureSections' => array_fill(0, 65, 'traffic')],
            ['profile_sections' => $depthFive],
            ['privacyBoundary' => new \stdClass()],
            ['scope' => 7],
            ['capture_sections' => true],
            ['profileSections' => null],
            ['captureSections' => $resource],
        ];
        $vault = new class {
            public int $storeCalls = 0;
            public function store(): array
            {
                $this->storeCalls++;
                return [
                    'credential_ref' => 914,
                    'credential_status' => 'ready',
                    'secret_mask' => 'mt****et',
                ];
            }
        };

        try {
            foreach ($cases as $index => $protectedMetadata) {
                Db::name('system_configs')->delete(true);
                Db::name('ota_credentials')->delete(true);
                $storeCallsBefore = $vault->storeCalls;
                $failure = null;
                try {
                    $this->otaConfigHarness($vault)->persistMeituan(array_merge([
                        'id' => 'meituan-58',
                        'config_id' => 'meituan-58',
                        'name' => 'Invalid protected metadata',
                        'hotel_id' => 58,
                        'system_hotel_id' => 58,
                        'cookies' => 'meituan-token-secret',
                    ], $protectedMetadata), 77, false);
                } catch (\Throwable $e) {
                    $failure = $e;
                }

                self::assertInstanceOf(
                    \InvalidArgumentException::class,
                    $failure,
                    "Protected metadata case {$index} must fail before persistence."
                );
                self::assertStringContainsString('metadata', strtolower($failure->getMessage()));
                self::assertSame($storeCallsBefore, $vault->storeCalls);
                self::assertSame(0, Db::name('system_configs')->count());
                self::assertSame(0, Db::name('ota_credentials')->count());
            }
        } finally {
            fclose($resource);
        }
    }

    public function testMeituanDeleteRechecksCommentAndExpectedScopeInsideLockedTransaction(): void
    {
        $vault = new class {
            public array $deleteCalls = [];
            public function delete(int $tenantId, int $hotelId, string $platform, string $configId): bool
            {
                $this->deleteCalls[] = func_get_args();
                return true;
            }
        };
        $commentConfig = [
            'meituan-58' => [
                'id' => 'meituan-58',
                'config_id' => 'meituan-58',
                'name' => 'Concurrent review scope',
                'hotel_id' => '58',
                'system_hotel_id' => 58,
                'scope' => '',
                'captureSections' => 'traffic,reviews',
                'credential_ref' => 910,
                'credential_status' => 'ready',
                'has_cookies' => true,
                'secret_mask' => 'mt****et',
            ],
        ];
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => json_encode($commentConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $commentFailure = null;
        try {
            $this->otaConfigHarness($vault)->deleteMeituan('meituan-58', 58, '');
        } catch (\RuntimeException $e) {
            $commentFailure = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $commentFailure);
        self::assertStringContainsString('review', strtolower($commentFailure->getMessage()));
        self::assertSame([], $vault->deleteCalls);
        self::assertSame(
            $commentConfig,
            json_decode((string)Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value'), true, 512, JSON_THROW_ON_ERROR)
        );

        $scopeChangedConfig = $commentConfig;
        unset($scopeChangedConfig['meituan-58']['captureSections']);
        $scopeChangedConfig['meituan-58']['scope'] = 'ota_channel_config';
        Db::name('system_configs')->where('config_key', 'meituan_config_list')->update([
            'config_value' => json_encode($scopeChangedConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $scopeFailure = null;
        try {
            $this->otaConfigHarness($vault)->deleteMeituan('meituan-58', 58, '');
        } catch (\RuntimeException $e) {
            $scopeFailure = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $scopeFailure);
        self::assertStringContainsString('scope', strtolower($scopeFailure->getMessage()));
        self::assertSame([], $vault->deleteCalls);
        self::assertSame(
            $scopeChangedConfig,
            json_decode((string)Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value'), true, 512, JSON_THROW_ON_ERROR)
        );

        $invalidLockedConfig = $commentConfig;
        unset($invalidLockedConfig['meituan-58']['captureSections']);
        $invalidLockedConfig['meituan-58']['captureSections'] = 7;
        Db::name('system_configs')->where('config_key', 'meituan_config_list')->update([
            'config_value' => json_encode($invalidLockedConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $invalidFailure = null;
        try {
            $this->otaConfigHarness($vault)->deleteMeituan('meituan-58', 58, '');
        } catch (\RuntimeException $e) {
            $invalidFailure = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $invalidFailure);
        self::assertStringContainsString('review', strtolower($invalidFailure->getMessage()));
        self::assertSame([], $vault->deleteCalls);
        self::assertSame(
            $invalidLockedConfig,
            json_decode((string)Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value'), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testMeituanCrudAndCommentEndpointsUseSharedVaultBoundary(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/MeituanConfigConcern.php');

        self::assertGreaterThanOrEqual(3, substr_count($source, 'saveMeituanConfigPayload('));
        self::assertStringContainsString('persistMeituanConfigMetadata(', $source);
        self::assertStringContainsString('sanitizeStoredOtaConfigListForRuntime([$id => $list[$id]])', $source);
        self::assertStringContainsString('return $this->success($safeList[$id] ?? [])', $source);
        self::assertStringContainsString('deleteMeituanConfigMetadata($id, $systemHotelId, $expectedScope)', $source);
        self::assertStringContainsString("clearAutoFetchLightConfigListCache('meituan')", $source);
        self::assertStringContainsString('saveMeituanConfigPayload($this->requestData(), false, \'\')', $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'isMeituanCommentConfigMetadata($list[$id])'));
        self::assertStringNotContainsString("saveOtaDataConfigValue('meituan-comments'", $source);
    }

    public function testSaveMeituanConfigItemReturnsOpaqueHttp500ForThrowable(): void
    {
        try {
            $response = $this->saveMeituanEndpointFailureHarness(
                new \Error('SQL C:\\secret\\path ota-cred:v1:ciphertext')
            )->saveMeituanConfigItem();
        } catch (\Throwable $e) {
            self::fail('Meituan save endpoint must convert internal failures to an opaque response.');
        }
        $content = (string)$response->getContent();

        self::assertSame(500, $response->getCode());
        self::assertStringContainsString('"message":"保存失败"', $content);
        foreach (['SQL', 'C:\\secret\\path', 'ota-cred:v1:', 'ciphertext'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $content);
        }
    }

    public function testDeleteMeituanConfigPreservesPermissionHttp403(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => json_encode([
                'meituan-58' => [
                    'id' => 'meituan-58',
                    'config_id' => 'meituan-58',
                    'name' => 'Meituan A',
                    'hotel_id' => '58',
                    'system_hotel_id' => 58,
                    'credential_ref' => 910,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'mt****et',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        try {
            $response = $this->deleteMeituanPermissionFailureHarness()->deleteMeituanConfig();
        } catch (\think\exception\HttpException $e) {
            self::fail('Meituan delete endpoint must preserve the HTTP status in its response.');
        } catch (\Throwable $e) {
            self::fail('Meituan delete endpoint must not expose its legacy storage failure path.');
        }

        self::assertSame(403, $response->getCode());
        self::assertStringContainsString('permission denied', (string)$response->getContent());
    }

    public function testSplitOtaConfigSecretsRecursivelySeparatesCaseInsensitiveSecrets(): void
    {
        $config = [
            'hotel_id' => 58,
            'hotel_name' => '测试门店',
            'credential_ref' => 'cred-58',
            'status' => 'active',
            'cookie' => '',
            'Cookies' => 'ctrip-cookie-secret',
            'nested' => [
                'label' => 'safe-label',
                'Access_Token' => 'meituan-token-secret',
                'refresh-token' => 'refresh-secret',
                'Api_Key' => 'api-key-secret',
                'Secret_JSON' => 'secret-json-secret',
                'Auth-Token' => 'auth-token-secret',
                'headers' => [
                    'Authorization' => 'Bearer authorization-secret',
                    'Set-Cookie' => 'sid=set-cookie-secret',
                    'X-Trace-Id' => 'trace-safe',
                ],
                'Headers' => 'Authorization: Bearer string-header-secret',
                'encrypted_payload' => 'encrypted-secret',
                'CipherText' => 'ciphertext-secret',
            ],
            'payload' => [
                'metric' => 'safe-metric',
                'token' => 'payload-array-token-secret',
            ],
            'payload_json' => '{"metric":"safe-metric","auth_data":"payload-json-auth-secret"}',
        ];

        [$metadata, $secretPayload] = $this->otaConfigHarness()->split($config);
        $metadataJson = (string)json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secretJson = (string)json_encode($secretPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertSame(58, $metadata['hotel_id']);
        self::assertSame('safe-label', $metadata['nested']['label']);
        self::assertSame('cred-58', $metadata['credential_ref']);
        self::assertSame('active', $metadata['status']);
        self::assertSame('safe-metric', $metadata['payload']['metric']);
        self::assertArrayNotHasKey('payload_json', $metadata);
        foreach ([
            'ctrip-cookie-secret',
            'meituan-token-secret',
            'refresh-secret',
            'authorization-secret',
            'set-cookie-secret',
            'api-key-secret',
            'secret-json-secret',
            'auth-token-secret',
            'string-header-secret',
            'trace-safe',
            'encrypted-secret',
            'ciphertext-secret',
            'payload-array-token-secret',
            'payload-json-auth-secret',
            'encrypted_payload',
            'CipherText',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $metadataJson);
        }
        foreach ([
            'ctrip-cookie-secret',
            'meituan-token-secret',
            'refresh-secret',
            'authorization-secret',
            'set-cookie-secret',
            'api-key-secret',
            'secret-json-secret',
            'auth-token-secret',
            'string-header-secret',
            'trace-safe',
            'encrypted-secret',
            'ciphertext-secret',
            'payload-array-token-secret',
            'payload-json-auth-secret',
        ] as $secret) {
            self::assertStringContainsString($secret, $secretJson);
        }
    }

    public function testSanitizeSecretConfigReturnsOnlySafeMetadataAndOpaqueIndicators(): void
    {
        $sanitized = $this->otaConfigHarness()->sanitize([
            'hotel_id' => 58,
            'credential_ref' => 'cred-58',
            'status' => 'active',
            'cookie' => '',
            'Cookies' => 'ctrip-cookie-secret',
            'nested' => [
                'UserToken' => 'meituan-token-secret',
                'Authorization' => 'Bearer authorization-secret',
                'encrypted_payload' => 'encrypted-secret',
                'ciphertext' => 'ciphertext-secret',
                'label' => 'safe-label',
            ],
        ]);
        $encoded = (string)json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertSame(58, $sanitized['hotel_id']);
        self::assertSame('cred-58', $sanitized['credential_ref']);
        self::assertSame('active', $sanitized['status']);
        self::assertSame('safe-label', $sanitized['nested']['label']);
        self::assertTrue($sanitized['has_cookies']);
        self::assertSame('********', $sanitized['secret_mask']);
        foreach ([
            'ctrip-cookie-secret',
            'meituan-token-secret',
            'authorization-secret',
            'encrypted-secret',
            'ciphertext-secret',
            'encrypted_payload',
            'ciphertext',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
        self::assertArrayNotHasKey('cookies_preview', $sanitized);
        self::assertArrayNotHasKey('token_preview', $sanitized);
    }

    public function testRuntimeConfigListCacheIsMetadataOnlyAndBlocksLegacySecretRows(): void
    {
        $sanitized = $this->otaConfigHarness()->sanitizeRuntimeList([
            'legacy' => [
                'id' => 'ctrip-legacy',
                'config_id' => 'ctrip-legacy',
                'system_hotel_id' => 58,
                'hotel_id' => 58,
                'credential_ref' => 701,
                'credential_status' => 'ready',
                'has_cookies' => true,
                'cookies' => 'sid=legacy-secret',
                'payload_json' => '{"metric":"safe","authorization":"Bearer legacy-token"}',
            ],
            'safe' => [
                'id' => 'ctrip-safe',
                'config_id' => 'ctrip-safe',
                'system_hotel_id' => 58,
                'hotel_id' => 58,
                'credential_ref' => 702,
                'credential_status' => 'ready',
                'has_cookies' => true,
                'url' => 'https://ebooking.ctrip.com/safe',
            ],
        ]);

        self::assertTrue($sanitized['legacy']['migration_required']);
        self::assertSame('legacy_secret_fields_present', $sanitized['legacy']['migration_reason']);
        self::assertSame('migration_required', $sanitized['legacy']['credential_status']);
        self::assertFalse($sanitized['legacy']['has_cookies']);
        self::assertSame('ready', $sanitized['safe']['credential_status']);
        self::assertTrue($sanitized['safe']['has_cookies']);
        self::assertSame('https://ebooking.ctrip.com/safe', $sanitized['safe']['url']);

        $encoded = (string)json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['legacy-secret', 'legacy-token', 'authorization', 'payload_json', '"cookies":'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($encoded));
        }
    }

    public function testSanitizeSecretConfigDoesNotSignalEmptyNestedSecrets(): void
    {
        $sanitized = $this->otaConfigHarness()->sanitize([
            'hotel_id' => 58,
            'cookie' => ['nested' => [null, '', '   ', []]],
            'token' => '',
            'api_key' => null,
            'headers' => [],
            'secret_json' => ['nested' => ['']],
        ]);

        self::assertSame(58, $sanitized['hotel_id']);
        self::assertFalse($sanitized['has_cookies']);
        self::assertArrayNotHasKey('secret_mask', $sanitized);
    }
}
