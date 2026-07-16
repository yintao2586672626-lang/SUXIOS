<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaCredentialReadPathTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/ota_credential_read_' . getmypid() . '.sqlite';
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
        Db::execute('CREATE TABLE system_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key VARCHAR(100) NOT NULL UNIQUE, config_value TEXT, description VARCHAR(255), create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE ota_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id VARCHAR(100) NOT NULL, credential_status VARCHAR(20) NOT NULL)');
        Db::name('hotels')->insertAll([
            ['id' => 58, 'tenant_id' => 7, 'name' => 'A'],
            ['id' => 64, 'tenant_id' => 9, 'name' => 'B'],
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

    private function permittedUser(array $hotelIds = [58]): object
    {
        return new class($hotelIds) {
            public int $id = 77;

            public function __construct(private readonly array $hotelIds)
            {
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function canManageOwnHotels(): bool
            {
                return true;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === 'can_fetch_online_data';
            }

            public function getPermittedHotelIds(): array
            {
                return $this->hotelIds;
            }
        };
    }

    private function readHarness(object $vault, ?object $user): object
    {
        return new class($vault, $user) {
            use \app\controller\concern\OtaConfigConcern;

            public ?object $currentUser;

            public function __construct(
                private readonly object $replacementVault,
                ?object $currentUser
            ) {
                $this->currentUser = $currentUser;
            }

            protected function otaCredentialVault(): object
            {
                return $this->replacementVault;
            }

            public function execute(
                string $platform,
                string $configId,
                int $hotelId,
                callable $consumer,
                bool $internalCollector = false
            ): mixed {
                return $this->withOtaCredentialForExecution(
                    $platform,
                    $configId,
                    $hotelId,
                    $consumer,
                    $internalCollector
                );
            }

            public function executeManual(
                string $platform,
                string $configId,
                int $hotelId,
                callable $consumer
            ): mixed {
                return $this->withOtaCredentialForExecution(
                    $platform,
                    $configId,
                    $hotelId,
                    $consumer,
                    false,
                    true
                );
            }

            public function rejectInlineCredentials(array $requestData): void
            {
                $this->assertNoInlineOtaExecutionCredentials($requestData);
            }

            public function guardedExecute(array $requestData, callable $consumer): mixed
            {
                $this->assertNoInlineOtaExecutionCredentials($requestData);
                return $this->withOtaCredentialForExecution(
                    'ctrip',
                    (string)($requestData['config_id'] ?? ''),
                    (int)($requestData['system_hotel_id'] ?? 0),
                    $consumer
                );
            }

            public function controllerExecutionState(): array
            {
                $state = get_object_vars($this);
                unset($state['replacementVault'], $state['currentUser']);
                return $state;
            }
        };
    }

    private function identityHarness(): object
    {
        return new class {
            use \app\controller\concern\OtaConfigConcern;
            use \app\controller\concern\BusinessDisplayConcern;
            use \app\controller\concern\OnlineDataManualFetchConcern;

            public ?object $currentUser;

            public function __construct()
            {
                $this->currentUser = new class {
                    public function isSuperAdmin(): bool
                    {
                        return true;
                    }
                };
            }

            public function safeIdentityMetadata(): array
            {
                return $this->readSafeCtripIdentityMetadataList();
            }

            public function identityConfig(int $systemHotelId, array $requestData = []): array
            {
                return $this->resolveCtripManualBusinessIdentityConfig($systemHotelId, $requestData);
            }

            public function persistResolvedIdentity(int $systemHotelId, string $platformHotelId): bool
            {
                return $this->persistCtripResolvedPlatformHotelIdForSystemHotel($systemHotelId, $platformHotelId);
            }

            public function resolveMissingIdentity(array $capturedIds, int $systemHotelId): array
            {
                return $this->resolveMissingCtripPlatformHotelIdFromCapturedData(
                    $capturedIds,
                    $systemHotelId,
                    'A'
                );
            }

            public function validateManualIdentity(array $dateResults, int $systemHotelId, array $requestData = []): array
            {
                return $this->validateCtripManualBusinessHotelIdentity($dateResults, $systemHotelId, $requestData);
            }

            public function competitionSelfIds(?array $identityCheck): array
            {
                return $this->ctripCompetitionSelfHotelIds($identityCheck);
            }

            public function isManualCtripSelfRow(array $row): bool
            {
                return $this->isCtripManualBusinessSelfRow($row);
            }

            public function tagCompetitionRoles(array $displayHotels, array $selfHotelIds, int $systemHotelId): array
            {
                return $this->tagCtripCompetitionDisplayRoles($displayHotels, $selfHotelIds, $systemHotelId);
            }

            public function adminIdentityConflictResolution(
                int $sourceSystemHotelId,
                int $targetSystemHotelId,
                bool $canContinueCurrentFetch
            ): array {
                return $this->buildCtripAdminHotelMergeResolution(
                    $sourceSystemHotelId,
                    $targetSystemHotelId,
                    [['system_hotel_id' => $targetSystemHotelId]],
                    $canContinueCurrentFetch
                );
            }

            public function sanitizeCtripRequest(array $requestData): array
            {
                return $this->sanitizeCtripManualFetchRequestData($requestData);
            }

            public function sanitizeMeituanRequest(array $requestData): array
            {
                return $this->sanitizeMeituanManualFetchRequestData($requestData);
            }

            public function selectMeituanManualConfig(array $configs, string $configId, int $hotelId): array
            {
                if (!method_exists($this, 'selectMeituanManualFetchConfigMetadata')) {
                    return [];
                }
                return $this->selectMeituanManualFetchConfigMetadata($configs, $configId, $hotelId);
            }

            public function isContinuousList(array $value): bool
            {
                return $this->isContinuousManualFetchList($value);
            }

            private function isMeaningfulCtripPlatformHotelId(string $value, int $systemHotelId = 0): bool
            {
                $value = trim($value);
                return $value !== '' && $value !== '-1' && ($systemHotelId <= 0 || $value !== (string)$systemHotelId);
            }

            private function extractExpectedCtripPlatformHotelIds(array $config, int $systemHotelId): array
            {
                $ids = [];
                foreach (['masterHotelId', 'master_hotel_id', 'ota_hotel_id', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId'] as $field) {
                    $value = trim((string)($config[$field] ?? ''));
                    if ($this->isMeaningfulCtripPlatformHotelId($value, $systemHotelId)) {
                        $ids[$value] = true;
                    }
                }
                return array_keys($ids);
            }

            private function extractCtripNodeResourceIds(array $config): array
            {
                $ids = [];
                foreach (['node_id', 'nodeId'] as $field) {
                    $value = trim((string)($config[$field] ?? ''));
                    if ($value !== '' && $value !== '-1') {
                        $ids[$value] = true;
                    }
                }
                return array_keys($ids);
            }

            private function resolveCtripPlatformHotelId(array $row, mixed $fallback = ''): string
            {
                foreach (['masterHotelId', 'masterhotelid', 'master_hotel_id', 'hotelId', 'hotel_id', 'HotelId', 'hotelID', 'ota_hotel_id', 'ctrip_hotel_id'] as $field) {
                    $value = $row[$field] ?? null;
                    if (is_scalar($value) && trim((string)$value) !== '') {
                        return trim((string)$value);
                    }
                }
                return is_scalar($fallback) ? trim((string)$fallback) : '';
            }

            private function findCtripPlatformHotelIdConflicts(array $platformHotelIds, int $systemHotelId): array
            {
                return [];
            }

            private function shouldBlockCtripCurrentHotelIdConflict(string $platformHotelId, array $expectedIds): bool
            {
                return !in_array(trim($platformHotelId), array_map('strval', $expectedIds), true);
            }

            private function normalizeCtripHotelNameForMatch(string $value): string
            {
                $value = mb_strtolower(trim($value), 'UTF-8');
                return preg_replace('/[\s\-_\.|()（）·]+/u', '', $value) ?? $value;
            }

            private function isCtripGenericSelfHotelName(string $value): bool
            {
                return in_array($this->normalizeCtripHotelNameForMatch($value), ['我的酒店', '本店', '本酒店', 'myhotel', 'currenthotel', 'selfhotel'], true);
            }

            private function getSystemHotelName(int $systemHotelId): string
            {
                return [58 => '桂林漓江望月', 64 => '古镇江景'][$systemHotelId] ?? '';
            }
        };
    }

    private function fakeVault(?array $payload = null): object
    {
        $payload ??= [
            'cookies' => 'SENTINEL_OTA_SECRET_NEVER_LEAK',
            'auth_data' => ['token' => 'SENTINEL_AUTH_SECRET_NEVER_LEAK'],
        ];

        return new class($payload) {
            public array $calls = [];

            public function __construct(private readonly array $payload)
            {
            }

            public function withPayloadForExecution(
                int $tenantId,
                int $hotelId,
                string $platform,
                string $configId,
                callable $consumer
            ): mixed {
                $this->calls[] = [$tenantId, $hotelId, $platform, $configId];
                return $consumer($this->payload);
            }
        };
    }

    private function autoFetchExecutionHarness(object $vault): object
    {
        return new class($vault) {
            use \app\controller\concern\OtaConfigConcern;
            use \app\controller\concern\AutoFetchConcern;

            public ?object $currentUser = null;
            public bool $requestSawCredential = false;

            public function __construct(private readonly object $replacementVault)
            {
            }

            protected function otaCredentialVault(): object
            {
                return $this->replacementVault;
            }

            public function runCtrip(array $body, int $hotelId): array
            {
                return $this->executeCtripBusinessAutoFetchTask('ctrip-business', $body, $hotelId);
            }

            public function runMeituan(array $body, int $hotelId): array
            {
                return $this->executeMeituanRankingAutoFetchTask('meituan-P_RZ', $body, $hotelId);
            }

            private function sendHttpRequest(string $url, array $postData, string $cookies, array $authData = []): array
            {
                $this->requestSawCredential = $cookies === 'AUTO_FETCH_SENTINEL_SECRET';
                return ['success' => true, 'data' => ['responseStatus' => 0, 'rows' => [['value' => 1]]]];
            }

            private function parseAndSaveData($responseData, $startDate, $endDate, ?int $systemHotelId = null): int
            {
                return 2;
            }

            private function sendMeituanRequest(string $url, array $params, string $cookies, array $authData = []): array
            {
                $this->requestSawCredential = $cookies === 'AUTO_FETCH_SENTINEL_SECRET'
                    && ($authData['token'] ?? '') === 'AUTO_FETCH_AUTH_SENTINEL';
                return ['success' => true, 'data' => ['rows' => [['value' => 1]]]];
            }

            private function parseAndSaveMeituanData($responseData, $startDate, $endDate, ?int $systemHotelId = null, array $requestContext = []): int
            {
                return 3;
            }
        };
    }

    public function testPermittedExecutionUsesFullLocatorAndReturnsOnlyBusinessResult(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser());
        $callbackSawCredential = false;

        $result = $controller->execute('ctrip', 'cfg-58', 58, static function (array $payload) use (&$callbackSawCredential): array {
            $callbackSawCredential = $payload['cookies'] === 'SENTINEL_OTA_SECRET_NEVER_LEAK';
            return ['saved_count' => 3, 'status' => 'collected'];
        });

        self::assertTrue($callbackSawCredential);
        self::assertSame([[7, 58, 'ctrip', 'cfg-58']], $vault->calls);
        self::assertSame(['saved_count' => 3, 'status' => 'collected'], $result);
        $stateJson = json_encode(
            $controller->controllerExecutionState(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString('SENTINEL_', $stateJson);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SENTINEL_', $encoded);
    }

    public function testManualExecutionClassifiesAuthorizationAndCredentialFailures(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser([64]));

        try {
            $controller->executeManual('ctrip', 'cfg-58', 58, static fn(array $payload): array => ['ok' => true]);
            self::fail('Manual authorization failure must be stage classified.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\app\service\OtaExecutionStageException::class, $e);
            self::assertSame('authorization', $e->stage());
            self::assertSame(403, $e->httpStatus());
            self::assertSame('无权使用该门店 OTA 凭据', $e->safeMessage());
        }
        self::assertSame([], $vault->calls);

        $failingVault = new class {
            public function withPayloadForExecution(
                int $tenantId,
                int $hotelId,
                string $platform,
                string $configId,
                callable $consumer
            ): mixed {
                throw new \RuntimeException('vault lookup sentinel');
            }
        };
        $controller = $this->readHarness($failingVault, $this->permittedUser());

        try {
            $controller->executeManual('ctrip', 'cfg-58', 58, static fn(array $payload): array => ['ok' => true]);
            self::fail('Manual credential failure must be stage classified.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\app\service\OtaExecutionStageException::class, $e);
            self::assertSame('credential', $e->stage());
            self::assertSame(409, $e->httpStatus());
            self::assertSame('OTA 凭据不可用', $e->safeMessage());
            self::assertStringNotContainsString('sentinel', $e->safeMessage());
        }
    }

    public function testManualExecutionSeparatesPlatformAndResultInspectionFailures(): void
    {
        $controller = $this->readHarness($this->fakeVault(), $this->permittedUser());

        try {
            $controller->executeManual(
                'ctrip',
                'cfg-58',
                58,
                static function (array $payload): array {
                    throw new \RuntimeException('platform runtime sentinel');
                }
            );
            self::fail('Manual platform failure must be stage classified.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\app\service\OtaExecutionStageException::class, $e);
            self::assertSame('platform_execution', $e->stage());
            self::assertSame(502, $e->httpStatus());
            self::assertSame('OTA 平台请求或数据处理失败', $e->safeMessage());
            self::assertStringNotContainsString('sentinel', $e->safeMessage());
        }

        try {
            $controller->executeManual(
                'ctrip',
                'cfg-58',
                58,
                static fn(array $payload): array => ['echo' => 'SENTINEL_OTA_SECRET_NEVER_LEAK']
            );
            self::fail('Manual result inspection failure must be stage classified.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\app\service\OtaExecutionStageException::class, $e);
            self::assertSame('result_inspection', $e->stage());
            self::assertSame(500, $e->httpStatus());
            self::assertSame('返回结果包含疑似 Cookie/令牌内容，已在结果返回阶段拦截', $e->safeMessage());
            self::assertStringNotContainsString('SENTINEL_', $e->safeMessage());
        }
    }

    public function testAutomaticExecutionKeepsExistingRuntimeBehavior(): void
    {
        $controller = $this->readHarness($this->fakeVault(), $this->permittedUser());

        try {
            $controller->execute(
                'ctrip',
                'cfg-58',
                58,
                static function (array $payload): array {
                    throw new \RuntimeException('existing automatic behavior');
                }
            );
            self::fail('Expected the original runtime exception.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(\app\service\OtaExecutionStageException::class, $e);
            self::assertSame('existing automatic behavior', $e->getMessage());
        }
    }

    public function testEveryManualCredentialEndpointEnablesTruthfulStageClassification(): void
    {
        $manualSource = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php'
        );
        $requestSource = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php'
        );

        self::assertSame(8, substr_count($manualSource, "false,\n                true\n            );"));
        self::assertSame(2, substr_count($requestSource, "false,\n                true\n            );"));
        foreach ([
            'ctrip_manual_fetch',
            'meituan_manual_fetch',
            'meituan_rank_candidate_commit',
            'ctrip_traffic_fetch',
            'ctrip_ads_fetch',
            'meituan_traffic_fetch',
            'meituan_order_flow_fetch',
            "'meituan_' . \$section . '_fetch'",
        ] as $operation) {
            self::assertStringContainsString($operation, $manualSource);
        }
        foreach (['ctrip_cookie_api_fetch', 'ctrip_overview_fetch'] as $operation) {
            self::assertStringContainsString($operation, $requestSource);
        }
        self::assertStringNotContainsString("'OTA 凭据不可用'", $manualSource);
        self::assertStringNotContainsString("'OTA 凭据不可用'", $requestSource);
    }

    public function testOrdinaryExecutionRejectsHotelOutsideCurrentUserScopeBeforeVaultRead(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser([64]));

        try {
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $payload): array => ['ok' => true]);
            self::fail('Execution outside the current user hotel scope must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }

        self::assertSame([], $vault->calls);
    }

    public function testInternalCollectorBypassesUserPermissionButStillUsesFullLocator(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, null);

        $result = $controller->execute(
            'meituan',
            'mt-64',
            64,
            static fn(array $payload): array => ['count' => isset($payload['cookies']) ? 2 : 0],
            true
        );

        self::assertSame(['count' => 2], $result);
        self::assertSame([[9, 64, 'meituan', 'mt-64']], $vault->calls);
    }

    public function testExecutionBoundaryRejectsConsumerReturningCredentialPayload(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser());

        try {
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $payload): array => $payload);
            self::fail('Consumer must not return the credential payload.');
        } catch (\RuntimeException $e) {
            self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL_', $e->getMessage());
        }
    }

    public function testExecutionBoundaryRejectsStringArrayAndResponseCredentialEchoes(): void
    {
        $consumers = [
            static fn(array $payload): string => 'raw:' . $payload['cookies'],
            static fn(array $payload): array => ['raw_response' => 'echo=' . $payload['auth_data']['token']],
            static fn(array $payload): \think\Response => json([
                'code' => 200,
                'data' => ['raw_response' => $payload['cookies']],
            ]),
        ];

        foreach ($consumers as $consumer) {
            $vault = $this->fakeVault();
            $controller = $this->readHarness($vault, $this->permittedUser());
            try {
                $controller->execute('ctrip', 'cfg-58', 58, $consumer);
                self::fail('Credential echo must be rejected before returning the business result.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
                self::assertStringNotContainsString('SENTINEL_', $e->getMessage());
            }
        }
    }

    public function testAuthDataContextValuesDoNotCauseFalsePositiveButRealTokenStillBlocks(): void
    {
        $payload = [
            'auth_data' => [
                'xCtxCurrency' => 'CNY',
                'xCtxLocale' => 'zh-CN',
                'requestIndex' => 1,
                'token' => 'REAL_AUTH_TOKEN_SENTINEL_123456',
            ],
            'cookieObj' => [
                'feature_flag' => 'true',
                'session' => 'REAL_COOKIE_SENTINEL_123456',
            ],
            'headers' => [
                'X-Ctx-Locale' => 'zh-CN',
                'Cookie' => 'sid=HEADER_COOKIE_SENTINEL_123456',
                'Authorization' => 'Bearer HEADER_AUTH_SENTINEL_123456',
            ],
        ];
        $controller = $this->readHarness($this->fakeVault($payload), $this->permittedUser());

        $safe = $controller->execute('ctrip', 'cfg-58', 58, static fn(array $credential): array => [
            'currency' => 'CNY',
            'locale' => 'zh-CN',
            'vipTag' => true,
            'saved_count' => 1,
        ]);
        self::assertSame(['currency' => 'CNY', 'locale' => 'zh-CN', 'vipTag' => true, 'saved_count' => 1], $safe);

        foreach ([
            'REAL_AUTH_TOKEN_SENTINEL_123456',
            'REAL_COOKIE_SENTINEL_123456',
            'sid=HEADER_COOKIE_SENTINEL_123456',
            'Bearer HEADER_AUTH_SENTINEL_123456',
        ] as $leak) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $credential): array => [
                    'raw_response' => $leak,
                ]);
                self::fail('Reusable token or cookie echo must be rejected: ' . $leak);
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testDirectStringAuthDataIsProtectedAsOneReusableSecret(): void
    {
        $controller = $this->readHarness($this->fakeVault([
            'auth_data' => 'DIRECT_AUTH_DATA_SENTINEL_123456',
        ]), $this->permittedUser());

        try {
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $payload): string => $payload['auth_data']);
            self::fail('Direct string auth_data must be protected.');
        } catch (\RuntimeException $e) {
            self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
        }
    }

    public function testJsonStringAuthDataProtectsNestedSensitiveLeavesWithoutCollectingContextValues(): void
    {
        $authData = json_encode([
            'xCtxCurrency' => 'CNY',
            'xCtxLocale' => 'zh-CN',
            'transport' => [
                'token' => 'JSON_AUTH_TOKEN_SENTINEL_123456',
                'nested' => ['spiderkey' => 'JSON_SPIDERKEY_SENTINEL_123456'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $controller = $this->readHarness($this->fakeVault(['auth_data' => $authData]), $this->permittedUser());

        self::assertSame([
            'currency' => 'CNY',
            'locale' => 'zh-CN',
        ], $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
            'currency' => 'CNY',
            'locale' => 'zh-CN',
        ]));

        foreach ([
            'JSON_AUTH_TOKEN_SENTINEL_123456',
            'JSON_SPIDERKEY_SENTINEL_123456',
            $authData,
        ] as $leak) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                    'raw_response' => $leak,
                ]);
                self::fail('JSON auth_data secret material must be blocked from results.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testPlainNonJsonAuthDataRemainsWholeProtectedWithoutBlockingSafeExecution(): void
    {
        $authData = 'opaque-auth-data-not-json';
        $controller = $this->readHarness($this->fakeVault(['auth_data' => $authData]), $this->permittedUser());

        self::assertSame(
            ['saved_count' => 1, 'status' => 'collected'],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                'saved_count' => 1,
                'status' => 'collected',
            ])
        );
        try {
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $authData);
            self::fail('The full opaque auth_data string must remain protected.');
        } catch (\RuntimeException $e) {
            self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
        }
    }

    public function testJsonLikeInvalidDeepNonArrayAndResourceHeavyAuthDataFailClosedBeforeConsumer(): void
    {
        $tooDeep = ['token' => 'DEEP_TOKEN_SENTINEL_123456'];
        for ($depth = 0; $depth < 10; $depth++) {
            $tooDeep = ['nested' => $tooDeep];
        }
        $resourceHeavy = [];
        for ($index = 0; $index < 257; $index++) {
            $resourceHeavy[] = ['token' => 'token-' . $index];
        }
        $oversizedToken = str_repeat('x', 65536);
        $cases = [
            ['auth_data' => '{"token":', 'would_echo' => 'unreachable-invalid-json'],
            ['auth_data' => '"JSON_SCALAR_AUTH_DATA"', 'would_echo' => 'JSON_SCALAR_AUTH_DATA'],
            ['auth_data' => json_encode($tooDeep, JSON_THROW_ON_ERROR), 'would_echo' => 'DEEP_TOKEN_SENTINEL_123456'],
            ['auth_data' => json_encode($resourceHeavy, JSON_THROW_ON_ERROR), 'would_echo' => 'token-256'],
            [
                'auth_data' => json_encode(['token' => $oversizedToken], JSON_THROW_ON_ERROR),
                'would_echo' => $oversizedToken,
            ],
            ['token' => str_repeat('t', 65537), 'would_echo' => str_repeat('t', 65537)],
        ];

        foreach ($cases as $payload) {
            $controller = $this->readHarness($this->fakeVault(array_diff_key($payload, ['would_echo' => true])), $this->permittedUser());
            $consumerCalled = false;
            try {
                $controller->execute(
                    'ctrip',
                    'cfg-58',
                    58,
                    static function (array $_) use (&$consumerCalled, $payload): string {
                        $consumerCalled = true;
                        return $payload['would_echo'];
                    }
                );
                self::fail('Uninspectable or oversized credential payload must fail closed before the consumer.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential payload exceeds execution inspection limits.', $e->getMessage());
            }
            self::assertFalse($consumerCalled);
        }
    }

    public function testCookieHeaderStringsProtectIndividualCookieValuesAndKeepContextSafe(): void
    {
        $payload = [
            'cookies' => 'sid=COOKIE_VALUE_SENTINEL_123456; locale=zh-CN; empty=',
            'headers' => [
                'Set-Cookie' => 'session=SET_COOKIE_VALUE_SENTINEL_123456; Path=/; HttpOnly',
                'X-Ctx-Currency' => 'CNY',
            ],
        ];
        $controller = $this->readHarness($this->fakeVault($payload), $this->permittedUser());

        self::assertSame(
            ['currency' => 'CNY', 'saved_count' => 1],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                'currency' => 'CNY',
                'saved_count' => 1,
            ])
        );

        foreach (['COOKIE_VALUE_SENTINEL_123456', 'SET_COOKIE_VALUE_SENTINEL_123456'] as $cookieValue) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $cookieValue);
                self::fail('An individual cookie value must be blocked from execution results.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testNumericAndBooleanCookiePreferencesDoNotBlockBusinessMetrics(): void
    {
        $cookies = 'rank=1; emptyRank=0; enabled=true; sid=COOKIE_SECRET_SENTINEL_123456';
        $controller = $this->readHarness($this->fakeVault([
            'cookies' => $cookies,
        ]), $this->permittedUser());

        $businessMetrics = [
            'amountRank' => 1,
            'quantityRank' => 0,
            'enabled' => true,
            'rankText' => '1',
        ];
        self::assertSame(
            $businessMetrics,
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => $businessMetrics)
        );

        foreach ([$cookies, 'COOKIE_SECRET_SENTINEL_123456'] as $leak) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $leak);
                self::fail('The complete Cookie header and real session value must remain protected.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testCtripBfaStatusCookieDoesNotBlockBusinessStatusValues(): void
    {
        $controller = $this->readHarness($this->fakeVault([
            'cookies' => '_bfaStatus=visited; sid=COOKIE_SECRET_SENTINEL_123456',
        ]), $this->permittedUser());
        $businessResult = [
            'status' => 'visited',
            'saved_count' => 1,
        ];

        self::assertSame(
            $businessResult,
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => $businessResult)
        );

        try {
            $controller->execute(
                'ctrip',
                'cfg-58',
                58,
                static fn(array $_): string => 'COOKIE_SECRET_SENTINEL_123456'
            );
            self::fail('A real session Cookie value must remain protected.');
        } catch (\RuntimeException $e) {
            self::assertSame(
                'OTA credential execution result contains protected credential material.',
                $e->getMessage()
            );
        }
    }

    public function testSetCookieHeaderListProtectsEachComponentAndIndividualCookieValue(): void
    {
        $firstHeader = 'session=SET_COOKIE_ARRAY_ONE_SENTINEL_123456; Path=/; HttpOnly';
        $secondHeader = 'refresh=SET_COOKIE_ARRAY_TWO_SENTINEL_123456; Path=/; Secure';
        $controller = $this->readHarness($this->fakeVault([
            'headers' => [
                'Set-Cookie' => [$firstHeader, $secondHeader],
                'X-Ctx-Currency' => 'CNY',
            ],
        ]), $this->permittedUser());

        self::assertSame(
            ['currency' => 'CNY', 'saved_count' => 1],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                'currency' => 'CNY',
                'saved_count' => 1,
            ])
        );

        foreach ([
            $firstHeader,
            $secondHeader,
            'SET_COOKIE_ARRAY_ONE_SENTINEL_123456',
            'SET_COOKIE_ARRAY_TWO_SENTINEL_123456',
        ] as $leak) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $leak);
                self::fail('A Set-Cookie list component or individual cookie value must be blocked.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testInvalidSensitiveHeaderArraysFailClosedBeforeConsumer(): void
    {
        $overPartList = array_map(
            static fn(int $index): string => 'cookie_' . $index . '=value_' . $index,
            range(1, 129)
        );
        $cases = [
            ['Set-Cookie' => ['primary' => 'sid=ASSOCIATIVE_COOKIE_SENTINEL']],
            ['Set-Cookie' => [['sid=NESTED_COOKIE_SENTINEL']]],
            ['Set-Cookie' => ['sid=STRING_COOKIE_SENTINEL', 123]],
            ['Set-Cookie' => [0 => 'sid=FIRST_COOKIE_SENTINEL', 2 => 'sid=GAPPED_COOKIE_SENTINEL']],
            ['Set-Cookie' => $overPartList],
            ['Set-Cookie' => [
                'sid=' . str_repeat('a', 32768),
                'refresh=' . str_repeat('b', 32768),
            ]],
            ['Authorization' => ['Bearer AUTH_ARRAY_SENTINEL']],
            ['Proxy-Authorization' => ['Basic PROXY_AUTH_ARRAY_SENTINEL']],
        ];

        foreach ($cases as $headers) {
            $controller = $this->readHarness($this->fakeVault(['headers' => $headers]), $this->permittedUser());
            $consumerCalled = false;
            try {
                $controller->execute(
                    'ctrip',
                    'cfg-58',
                    58,
                    static function (array $_) use (&$consumerCalled): array {
                        $consumerCalled = true;
                        return ['saved_count' => 1];
                    }
                );
                self::fail('Invalid sensitive header arrays must fail closed before the consumer.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential payload exceeds execution inspection limits.', $e->getMessage());
            }
            self::assertFalse($consumerCalled);
        }
    }

    public function testUnifiedSecretAliasesAndSetCookieStringProtectSingleValueEchoes(): void
    {
        $payload = [
            'authorization_header' => 'Bearer AUTHORIZATION_HEADER_ALIAS_SENTINEL_123456',
            'auth_token' => 'AUTH_TOKEN_ALIAS_SENTINEL_123456',
            'encrypted_payload' => 'ENCRYPTED_PAYLOAD_ALIAS_SENTINEL_123456',
            'ciphertext' => 'CIPHERTEXT_ALIAS_SENTINEL_123456',
            'set_cookie' => 'sid=SET_COOKIE_ALIAS_VALUE_SENTINEL_123456; Path=/; HttpOnly',
        ];
        $controller = $this->readHarness($this->fakeVault($payload), $this->permittedUser());

        self::assertSame(
            ['saved_count' => 1],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => ['saved_count' => 1])
        );

        foreach ([
            $payload['authorization_header'],
            $payload['auth_token'],
            $payload['encrypted_payload'],
            $payload['ciphertext'],
            'SET_COOKIE_ALIAS_VALUE_SENTINEL_123456',
        ] as $leak) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $leak);
                self::fail('Unified OTA secret aliases and set_cookie values must be protected.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testRawHeaderLineListProtectsAuthorizationAndCookieWhileIgnoringOrdinaryHeaders(): void
    {
        $authorization = 'Bearer RAW_HEADER_AUTH_SENTINEL_123456';
        $cookieValue = 'RAW_HEADER_COOKIE_SENTINEL_123456';
        $controller = $this->readHarness($this->fakeVault([
            'headers' => [
                'Authorization: ' . $authorization,
                'Cookie: sid=' . $cookieValue . '; locale=zh-CN',
                'X-Ctx-Currency: CNY',
            ],
        ]), $this->permittedUser());

        self::assertSame(
            ['currency' => 'CNY', 'saved_count' => 1],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                'currency' => 'CNY',
                'saved_count' => 1,
            ])
        );

        foreach ([$authorization, $cookieValue] as $leak) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $leak);
                self::fail('Raw sensitive header line values must be protected.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testScalarHeadersRawBlockAndJsonArrayProtectSensitiveComponentsButKeepOrdinaryHeadersSafe(): void
    {
        $cases = [
            [
                'headers' => implode("\r\n", [
                    'Authorization: Bearer RAW_BLOCK_BEARER_TOKEN_SENTINEL_123456',
                    'Cookie: sid=RAW_BLOCK_COOKIE_SENTINEL_123456; locale=zh-CN',
                    'Set-Cookie: refresh=RAW_BLOCK_SET_COOKIE_SENTINEL_123456; Path=/; HttpOnly',
                    'X-Ctx-Currency: CNY',
                    '',
                ]),
                'leaks' => [
                    'RAW_BLOCK_BEARER_TOKEN_SENTINEL_123456',
                    'RAW_BLOCK_COOKIE_SENTINEL_123456',
                    'RAW_BLOCK_SET_COOKIE_SENTINEL_123456',
                ],
            ],
            [
                'headers' => json_encode([
                    'Authorization' => 'Bearer JSON_HEADERS_STRING_TOKEN_SENTINEL_123456',
                    'Cookie' => 'sid=JSON_HEADERS_STRING_COOKIE_SENTINEL_123456',
                    'X-Ctx-Currency' => 'CNY',
                ], JSON_THROW_ON_ERROR),
                'leaks' => [
                    'JSON_HEADERS_STRING_TOKEN_SENTINEL_123456',
                    'JSON_HEADERS_STRING_COOKIE_SENTINEL_123456',
                ],
            ],
        ];

        foreach ($cases as $case) {
            $controller = $this->readHarness($this->fakeVault(['headers' => $case['headers']]), $this->permittedUser());
            self::assertSame(
                ['currency' => 'CNY', 'saved_count' => 1],
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                    'currency' => 'CNY',
                    'saved_count' => 1,
                ])
            );

            foreach ($case['leaks'] as $leak) {
                try {
                    $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $leak);
                    self::fail('Scalar headers must protect authorization components and cookie values.');
                } catch (\RuntimeException $e) {
                    self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
                }
            }
        }
    }

    public function testAuthorizationValuesProtectSchemeStrippedCredentialComponents(): void
    {
        $controller = $this->readHarness($this->fakeVault([
            'headers' => [
                'Authorization' => 'Bearer MAP_AUTH_TOKEN_SENTINEL_123456',
                'Proxy-Authorization' => 'Basic PROXY_AUTH_TOKEN_SENTINEL_123456',
                'X-Ctx-Currency' => 'CNY',
            ],
            'authorization_header' => 'Bearer GENERIC_AUTH_HEADER_TOKEN_SENTINEL_123456',
        ]), $this->permittedUser());

        self::assertSame(
            ['currency' => 'CNY', 'saved_count' => 1],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                'currency' => 'CNY',
                'saved_count' => 1,
            ])
        );

        foreach ([
            'MAP_AUTH_TOKEN_SENTINEL_123456',
            'PROXY_AUTH_TOKEN_SENTINEL_123456',
            'GENERIC_AUTH_HEADER_TOKEN_SENTINEL_123456',
        ] as $credentialComponent) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $credentialComponent);
                self::fail('Authorization credential components must be protected without the scheme.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testHeadersJsonRejectsOpaqueAndRawHeaderBlocksBeforeConsumer(): void
    {
        foreach ([
            'opaque-headers-json',
            'Authorization: Bearer HEADERS_JSON_RAW_BLOCK_BYPASS_SENTINEL',
        ] as $headersJson) {
            $controller = $this->readHarness($this->fakeVault(['headers_json' => $headersJson]), $this->permittedUser());
            $consumerCalled = false;
            try {
                $controller->execute(
                    'ctrip',
                    'cfg-58',
                    58,
                    static function (array $_) use (&$consumerCalled): array {
                        $consumerCalled = true;
                        return ['saved_count' => 1];
                    }
                );
                self::fail('headers_json must reject non-JSON and raw header blocks before the consumer.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential payload exceeds execution inspection limits.', $e->getMessage());
            }
            self::assertFalse($consumerCalled);
        }
    }

    public function testSecretJsonRejectsOpaqueRawAndEmptyPayloadsBeforeConsumer(): void
    {
        foreach ([
            '',
            'opaque-secret-json',
            'Authorization: Bearer SECRET_JSON_RAW_BLOCK_BYPASS_SENTINEL',
        ] as $secretJson) {
            $controller = $this->readHarness($this->fakeVault(['secret_json' => $secretJson]), $this->permittedUser());
            $consumerCalled = false;
            try {
                $controller->execute(
                    'ctrip',
                    'cfg-58',
                    58,
                    static function (array $_) use (&$consumerCalled): array {
                        $consumerCalled = true;
                        return ['saved_count' => 1];
                    }
                );
                self::fail('secret_json must reject opaque, raw, and empty payloads before the consumer.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential payload exceeds execution inspection limits.', $e->getMessage());
            }
            self::assertFalse($consumerCalled);
        }
    }

    public function testHeadersJsonAndSecretJsonProtectDecodedSensitiveAndPlainScalarValues(): void
    {
        $payload = [
            'headers_json' => json_encode([
                'Authorization' => 'Bearer HEADERS_JSON_AUTH_SENTINEL_123456',
                'Set-Cookie' => ['sid=HEADERS_JSON_COOKIE_SENTINEL_123456; Path=/'],
                'X-Ctx-Currency' => 'CNY',
            ], JSON_THROW_ON_ERROR),
            'secret_json' => json_encode([
                'plain' => 'SECRET_JSON_PLAIN_SENTINEL_123456',
                'nested' => ['opaque' => 'SECRET_JSON_NESTED_SENTINEL_123456'],
                'numeric' => 731942,
            ], JSON_THROW_ON_ERROR),
        ];
        $controller = $this->readHarness($this->fakeVault($payload), $this->permittedUser());

        self::assertSame(
            ['currency' => 'CNY', 'saved_count' => 1],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                'currency' => 'CNY',
                'saved_count' => 1,
            ])
        );

        foreach ([
            'Bearer HEADERS_JSON_AUTH_SENTINEL_123456',
            'HEADERS_JSON_COOKIE_SENTINEL_123456',
            'SECRET_JSON_PLAIN_SENTINEL_123456',
            'SECRET_JSON_NESTED_SENTINEL_123456',
            731942,
        ] as $leak) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_) => $leak);
                self::fail('Decoded headers_json and secret_json scalar values must be protected.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            }
        }
    }

    public function testInvalidHeaderAndJsonContainersFailClosedBeforeConsumer(): void
    {
        $tooDeep = ['Authorization' => 'Bearer DEEP_HEADER_SENTINEL'];
        for ($depth = 0; $depth < 10; $depth++) {
            $tooDeep = ['nested' => $tooDeep];
        }
        $resourceHeavy = array_fill(0, 257, ['plain' => 'secret']);
        $cases = [
            ['headers' => "Authorization Bearer MISSING_COLON\r\nX-Test: value"],
            ['headers' => implode("\n", array_fill(0, 129, 'X-Test: value'))],
            ['headers' => 'Authorization: Bearer ' . str_repeat('a', 65536)],
            ['headers' => '"NON_ARRAY_HEADERS_JSON"'],
            ['headers' => ['Malformed header line without colon']],
            ['headers' => [0 => 'Authorization: Bearer FIRST', 2 => 'Cookie: sid=GAPPED']],
            ['headers' => ['Authorization: Bearer VALID', 123]],
            ['headers' => array_fill(0, 129, 'X-Test: value')],
            ['headers' => ['Authorization: Bearer ' . str_repeat('a', 65536)]],
            ['headers_json' => '{"Authorization":'],
            ['headers_json' => ''],
            ['headers_json' => 'opaque-headers-json'],
            ['headers_json' => 'Authorization: Bearer RAW_BLOCK_NOT_JSON'],
            ['headers_json' => '"Bearer NON_ARRAY"'],
            ['headers_json' => json_encode($tooDeep, JSON_THROW_ON_ERROR)],
            ['headers_json' => json_encode(['Authorization' => str_repeat('a', 65536)], JSON_THROW_ON_ERROR)],
            ['secret_json' => '{"plain":'],
            ['secret_json' => '"NON_ARRAY_SECRET"'],
            ['secret_json' => json_encode($resourceHeavy, JSON_THROW_ON_ERROR)],
            ['secret_json' => json_encode(['plain' => str_repeat('s', 65536)], JSON_THROW_ON_ERROR)],
        ];

        foreach ($cases as $payload) {
            $controller = $this->readHarness($this->fakeVault($payload), $this->permittedUser());
            $consumerCalled = false;
            try {
                $controller->execute(
                    'ctrip',
                    'cfg-58',
                    58,
                    static function (array $_) use (&$consumerCalled): array {
                        $consumerCalled = true;
                        return ['saved_count' => 1];
                    }
                );
                self::fail('Invalid header or JSON credential containers must fail closed before the consumer.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential payload exceeds execution inspection limits.', $e->getMessage());
            }
            self::assertFalse($consumerCalled);
        }
    }

    public function testOversizedAndOverPartCookieHeadersFailClosedBeforeConsumer(): void
    {
        $cookieParts = array_map(
                static fn(int $index): string => 'cookie_' . $index . '=value_' . $index,
                range(1, 129)
            );
        $cases = [
            ['cookies' => 'sid=' . str_repeat('x', 65536), 'would_echo' => str_repeat('x', 65536)],
            ['cookies' => implode('; ', $cookieParts), 'would_echo' => 'value_129'],
        ];

        foreach ($cases as $payload) {
            $controller = $this->readHarness($this->fakeVault(['cookies' => $payload['cookies']]), $this->permittedUser());
            $consumerCalled = false;
            try {
                $controller->execute(
                    'ctrip',
                    'cfg-58',
                    58,
                    static function (array $_) use (&$consumerCalled, $payload): string {
                        $consumerCalled = true;
                        return $payload['would_echo'];
                    }
                );
                self::fail('Oversized or over-part cookie headers must fail closed before the consumer.');
            } catch (\RuntimeException $e) {
                self::assertSame('OTA credential payload exceeds execution inspection limits.', $e->getMessage());
            }
            self::assertFalse($consumerCalled);
        }
    }

    public function testSpiderkeyIsProtectedFromExecutionResults(): void
    {
        $controller = $this->readHarness($this->fakeVault([
            'spiderkey' => 'SPIDERKEY_SENTINEL_123456',
        ]), $this->permittedUser());

        try {
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $payload): array => [
                'raw_response' => $payload['spiderkey'],
            ]);
            self::fail('Vault spiderkey must be protected from execution results.');
        } catch (\RuntimeException $e) {
            self::assertSame('OTA credential execution result contains protected credential material.', $e->getMessage());
            self::assertStringNotContainsString('SPIDERKEY_SENTINEL', $e->getMessage());
        }
    }

    public function testInlineSpiderkeyIsRejectedBeforeVaultRead(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser());

        foreach ([
            ['spiderkey' => 'INLINE_SPIDERKEY_SENTINEL'],
            ['transport' => ['spider-key' => 'INLINE_SPIDERKEY_SENTINEL']],
        ] as $inlineData) {
            try {
                $controller->guardedExecute(array_merge([
                    'config_id' => 'cfg-58',
                    'system_hotel_id' => 58,
                ], $inlineData), static fn(array $payload): array => ['ok' => true]);
                self::fail('Inline spiderkey must be rejected before Vault execution.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }

        self::assertSame([], $vault->calls);
    }

    public function testShortSensitiveValuesAreBlockedInDirectArrayAndResponseResults(): void
    {
        $cases = [
            'token' => [
                ['auth_data' => ['token' => 't-7']],
                't-7',
            ],
            'cookie' => [
                ['cookieObj' => ['session' => 'c-7']],
                'c-7',
            ],
            'authorization' => [
                ['headers' => ['Authorization' => 'a-7']],
                'a-7',
            ],
            'direct auth_data' => [
                ['auth_data' => 'd-7'],
                'd-7',
            ],
        ];

        foreach ($cases as $label => [$payload, $secret]) {
            $consumers = [
                'direct string' => static fn(array $_): string => $secret,
                'array' => static fn(array $_): array => ['raw_response' => $secret],
                'think response data' => static fn(array $_): \think\Response => json([
                    'code' => 200,
                    'data' => ['raw_response' => $secret],
                ]),
                'think response JSON content' => static function (array $_) use ($secret): \think\Response {
                    $content = json_encode(
                        ['code' => 200, 'data' => ['raw_response' => $secret]],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    );
                    return json(['code' => 200, 'data' => ['safe' => true]])->content($content);
                },
            ];

            foreach ($consumers as $resultType => $consumer) {
                $controller = $this->readHarness($this->fakeVault($payload), $this->permittedUser());
                try {
                    $controller->execute('ctrip', 'cfg-58', 58, $consumer);
                    self::fail("Short {$label} must be blocked in {$resultType} result.");
                } catch (\RuntimeException $e) {
                    self::assertSame(
                        'OTA credential execution result contains protected credential material.',
                        $e->getMessage()
                    );
                }
            }
        }
    }

    public function testOrdinaryShortContextValuesRemainSafeAcrossResultShapes(): void
    {
        $payload = [
            'auth_data' => [
                'xCtxCurrency' => 'CNY',
                'xCtxLocale' => 'zh-CN',
                'requestIndex' => 1,
                'token' => 't-7',
            ],
            'cookieObj' => ['session' => 'c-7'],
            'headers' => [
                'X-Ctx-Currency' => 'CNY',
                'X-Ctx-Locale' => 'zh-CN',
                'Authorization' => 'a-7',
            ],
        ];
        $controller = $this->readHarness($this->fakeVault($payload), $this->permittedUser());

        self::assertSame(
            'CNY',
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => 'CNY')
        );
        self::assertSame(
            ['currency' => 'CNY', 'locale' => 'zh-CN', 'saved_count' => 1],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                'currency' => 'CNY',
                'locale' => 'zh-CN',
                'saved_count' => 1,
            ])
        );
        $response = $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): \think\Response => json([
            'code' => 200,
            'data' => ['currency' => 'CNY', 'locale' => 'zh-CN', 'saved_count' => 1],
        ]));
        self::assertInstanceOf(\think\Response::class, $response);
    }

    public function testInlineExecutionCredentialsAreRejectedBeforeAnyVaultRead(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser());

        foreach (['cookies', 'auth_data', 'spidertoken', 'mtgsig'] as $field) {
            try {
                $controller->rejectInlineCredentials([
                    'config_id' => 'cfg-58',
                    'system_hotel_id' => 58,
                    $field => 'SENTINEL_INLINE_SECRET',
                ]);
                self::fail("Inline execution credential {$field} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('credential', strtolower($e->getMessage()));
            }
        }

        $controller->rejectInlineCredentials(['config_id' => 'cfg-58', 'system_hotel_id' => 58]);
        self::assertSame([], $vault->calls);
    }

    public function testNestedAndListInlineCredentialsAreRejectedBeforeVaultRead(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser());
        $requests = [
            [
                'config_id' => 'cfg-58',
                'system_hotel_id' => 58,
                'payload' => ['transport' => ['Auth-Data' => 'SENTINEL_NESTED_SECRET']],
            ],
            [
                'config_id' => 'cfg-58',
                'system_hotel_id' => 58,
                'items' => [
                    ['label' => 'safe'],
                    ['Cookies' => 'SENTINEL_LIST_SECRET'],
                ],
            ],
        ];

        foreach ($requests as $requestData) {
            try {
                $controller->guardedExecute($requestData, static fn(array $payload): array => ['ok' => true]);
                self::fail('Nested execution credentials must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }

        self::assertSame([], $vault->calls);
    }

    public function testCaseAndSeparatorCredentialAliasesAreRejectedBeforeVaultRead(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser());

        foreach (['CoOkIeS', 'AUTH-DATA', 'Spider_Token', 'MtG-Sig', 'MTSI-EB-U'] as $field) {
            try {
                $controller->guardedExecute([
                    'config_id' => 'cfg-58',
                    'system_hotel_id' => 58,
                    'nested' => [[$field => 'SENTINEL_CASE_SECRET']],
                ], static fn(array $payload): array => ['ok' => true]);
                self::fail("Credential alias {$field} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }

        self::assertSame([], $vault->calls);
    }

    public function testInlineCredentialScanFailsClosedAtDepthItemAndByteLimits(): void
    {
        $vault = $this->fakeVault();
        $controller = $this->readHarness($vault, $this->permittedUser());
        $tooDeep = ['leaf' => 'safe'];
        for ($i = 0; $i < 6; $i++) {
            $tooDeep = ['level' => $tooDeep];
        }
        $requests = [
            ['config_id' => 'cfg-58', 'system_hotel_id' => 58, 'nested' => $tooDeep],
            ['config_id' => 'cfg-58', 'system_hotel_id' => 58, 'items' => array_fill(0, 65, 'safe')],
            ['config_id' => 'cfg-58', 'system_hotel_id' => 58, 'body' => str_repeat('x', 4097)],
        ];

        foreach ($requests as $requestData) {
            try {
                $controller->guardedExecute($requestData, static fn(array $payload): array => ['ok' => true]);
                self::fail('Oversized execution request must fail closed.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }

        self::assertSame([], $vault->calls);
    }

    public function testSafeCtripIdentityReaderReturnsOnlyExplicitIdentityAllowlist(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => json_encode([
                'cfg-58' => [
                    'id' => 'cfg-58',
                    'config_id' => 'cfg-58',
                    'system_hotel_id' => 58,
                    'hotel_id' => '58',
                    'ctrip_hotel_id' => '880058',
                    'name' => 'Ctrip A',
                    'node_id' => '24588',
                    'credential_ref' => 901,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'ct****et',
                    'url' => 'https://example.invalid/must-not-return',
                    'unrelated' => ['label' => 'must-not-return'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $harness = $this->identityHarness();
        $rows = $harness->safeIdentityMetadata();
        $resolved = $harness->identityConfig(58, ['nodeId' => 'request-node', 'untrusted' => 'drop']);

        self::assertSame([[
            'id' => 'cfg-58',
            'config_id' => 'cfg-58',
            'system_hotel_id' => 58,
            'hotel_id' => '58',
            'ctrip_hotel_id' => '880058',
            'name' => 'Ctrip A',
            'node_id' => '24588',
        ]], $rows);
        self::assertSame('request-node', $resolved['nodeId']);
        self::assertSame('880058', $resolved['ctrip_hotel_id']);
        foreach (['credential_ref', 'credential_status', 'has_cookies', 'secret_mask', 'url', 'unrelated', 'untrusted'] as $field) {
            self::assertArrayNotHasKey($field, $resolved);
        }
    }

    public function testSafeCtripIdentityReaderFailsClosedOnNestedLegacySecret(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => json_encode([
                'cfg-64' => [
                    'id' => 'cfg-64',
                    'config_id' => 'cfg-64',
                    'system_hotel_id' => 64,
                    'hotel_id' => '64',
                    'transport' => ['Auth-Data' => ['token' => 'SENTINEL_LEGACY_SECRET']],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        try {
            $this->identityHarness()->safeIdentityMetadata();
            self::fail('Legacy plaintext identity record must fail closed.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Task6', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL_', $e->getMessage());
        }
    }

    public function testResolvedCtripIdentityContinuesRequestWithoutUpdatingConfigBlob(): void
    {
        $configValue = json_encode([
            'cfg-58' => [
                'id' => 'cfg-58',
                'config_id' => 'cfg-58',
                'system_hotel_id' => 58,
                'hotel_id' => '58',
                'name' => 'Ctrip A',
                'node_id' => '24588',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $configValue,
        ]);

        $harness = $this->identityHarness();
        self::assertFalse($harness->persistResolvedIdentity(58, '880058'));
        $resolution = $harness->resolveMissingIdentity(['880058'], 58);

        self::assertTrue($resolution['ok']);
        self::assertSame('request_scoped_platform_hotel_id', $resolution['status']);
        self::assertSame(['880058'], $resolution['expected_hotel_ids']);
        self::assertFalse($resolution['auto_bound']);
        self::assertSame($configValue, Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value'));
    }

    public function testStaleConfiguredCtripHotelIdBlocksPersistence(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => json_encode([
                'cfg-58' => [
                    'id' => 'cfg-58',
                    'config_id' => 'cfg-58',
                    'system_hotel_id' => 58,
                    'hotel_id' => '58',
                    'ctrip_hotel_id' => '120820008',
                    'name' => '新疆哈密',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $result = $this->identityHarness()->validateManualIdentity([[
            'data' => [
                'data' => [
                    'hotelList' => [[
                        'hotelId' => '120819980',
                        'hotelName' => '我的酒店',
                        'amountRank' => 1,
                    ]],
                ],
            ],
        ]], 58);

        self::assertFalse($result['ok']);
        self::assertTrue($result['warning']);
        self::assertSame('configured_platform_hotel_id_mismatch', $result['status']);
        self::assertSame(58, $result['target_system_hotel_id']);
        self::assertSame(['120820008'], $result['expected_hotel_ids']);
        self::assertSame(['120819980'], $result['captured_hotel_ids']);
        self::assertSame(
            'https://hotels.ctrip.com/hotels/120819980.html',
            $result['verification_links'][0]['url'] ?? null
        );
        self::assertStringContainsString('本次未入库', $result['message']);
    }

    public function testCtripCompetitorNameContainingTargetHotelNameIsNotTreatedAsSelf(): void
    {
        $harness = $this->identityHarness();

        self::assertTrue($harness->isManualCtripSelfRow([
            'hotelId' => '125876128',
            'hotelName' => '我的酒店',
        ]));
        self::assertFalse($harness->isManualCtripSelfRow([
            'hotelId' => '6405946',
            'hotelName' => '杭州东站锦江都城酒店',
        ]));
        self::assertFalse($harness->isManualCtripSelfRow([
            'hotelId' => '6405946',
            'hotelName' => '杭州东站',
        ]));
    }

    public function testCtripDifferentHotelConflictNeverSuggestsMergingIndependentHotels(): void
    {
        $harness = $this->identityHarness();
        $mismatch = $harness->adminIdentityConflictResolution(58, 64, false);
        $history = $harness->adminIdentityConflictResolution(58, 64, true);

        self::assertSame('delete_mismatched_ctrip_config', $mismatch['action']);
        self::assertTrue($mismatch['can_display_result']);
        self::assertTrue($mismatch['config_cleanup_required']);
        self::assertStringContainsString('不会合并', $mismatch['message']);
        self::assertStringNotContainsString('数据合并', $mismatch['message']);
        self::assertSame('clean_misbound_ctrip_history', $history['action']);
        self::assertTrue($history['can_continue_current_fetch']);
        self::assertStringContainsString('不会合并', $history['message']);
        self::assertStringNotContainsString('数据合并', $history['message']);
    }

    public function testCtripIdentityConflictIsDisplayableButNeverPersisted(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        $execute = $this->methodSource(
            $source,
            'private function executeCtripManualFetch',
            'private function ctripBusinessQunarVisitorQuality'
        );

        self::assertStringContainsString("'save_status' => 'blocked'", $execute);
        self::assertStringContainsString('$responseCode = 422;', $execute);
        self::assertStringContainsString('buildCtripPersistenceState(true, 0, true)', $execute);
        self::assertStringContainsString('], $responseCode);', $execute);
    }

    public function testCtripIdentityMissingFromResponseBlocksPersistence(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => json_encode([
                'cfg-58' => [
                    'id' => 'cfg-58',
                    'config_id' => 'cfg-58',
                    'system_hotel_id' => 58,
                    'ctrip_hotel_id' => '120820008',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $result = $this->identityHarness()->validateManualIdentity([['data' => []]], 58);

        self::assertFalse($result['ok']);
        self::assertSame('returned_current_hotel_id_missing', $result['status']);
        self::assertSame(58, $result['target_system_hotel_id']);
        self::assertStringContainsString('本次未入库', $result['message']);
    }

    public function testCtripIdentityMissingFromConfigAndResponseBlocksPersistence(): void
    {
        $result = $this->identityHarness()->resolveMissingIdentity([], 58);

        self::assertFalse($result['ok']);
        self::assertSame('platform_hotel_id_incomplete', $result['status']);
        self::assertStringContainsString('本次未入库', $result['message']);
    }

    public function testCtripIdentitySourceUsesSafeReaderAndHasNoBlobUpdatePath(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        $resolve = $this->methodSource($source, 'private function resolveCtripManualBusinessIdentityConfig', 'private function resolveCtripManualBusinessHotelIdentityFromResponse');
        $find = $this->methodSource($source, 'private function findCtripSystemHotelMatchesByPlatformIds', 'private function appendCtripSystemHotelIdentityMatches');
        $persist = $this->methodSource($source, 'private function persistCtripResolvedPlatformHotelIdForSystemHotel', 'private function readSafeCtripIdentityMetadataList');

        foreach ([$resolve, $find] as $method) {
            self::assertStringContainsString('readSafeCtripIdentityMetadataList()', $method);
            self::assertStringNotContainsString('getStoredCtripConfigList()', $method);
        }
        foreach (['Db::name(', '->update(', 'config_value', 'json_encode('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $persist);
        }
    }

    public function testManualFetchRequestSchemasPreserveSupportedScalarAndSafeListFields(): void
    {
        $harness = $this->identityHarness();
        $ctrip = $harness->sanitizeCtripRequest([
            'config_id' => 'cfg-58',
            'system_hotel_id' => 58,
            'url' => 'https://ebooking.ctrip.com/api/report',
            'node_id' => '24588',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'auto_save' => true,
            'background_task' => false,
            'ctripHotelId' => '880058',
        ]);
        $meituan = $harness->sanitizeMeituanRequest([
            'config_id' => 'mt-58',
            'system_hotel_id' => 58,
            'url' => 'https://eb.meituan.com/api/rank',
            'partner_id' => 'partner-58',
            'poi_id' => 'poi-58',
            'rank_type' => 'P_RZ',
            'date_range' => '1',
            'date_ranges' => ['1', '7'],
            'include_self_trade_metrics' => true,
            'self_room_nights' => 12,
        ]);

        self::assertSame('cfg-58', $ctrip['config_id']);
        self::assertSame('880058', $ctrip['ctripHotelId']);
        self::assertSame(['1', '7'], $meituan['date_ranges']);
        self::assertSame(12, $meituan['self_room_nights']);
    }

    public function testPhp80CompatibleContinuousListCheckAcceptsOnlyZeroBasedIndexes(): void
    {
        $harness = $this->identityHarness();

        self::assertTrue($harness->isContinuousList([]));
        self::assertTrue($harness->isContinuousList(['1', '7']));
        self::assertFalse($harness->isContinuousList([1 => '1', 2 => '7']));
        self::assertFalse($harness->isContinuousList([0 => '1', 2 => '7']));
        self::assertFalse($harness->isContinuousList(['range' => '1']));

        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        self::assertStringNotContainsString('array_is_list', $source);
        self::assertStringContainsString('isContinuousManualFetchList($value)', $source);
    }

    public function testManualFetchRequestSchemasRejectPayloadUnknownObjectAndNestedFieldsBeforeTask(): void
    {
        $harness = $this->identityHarness();
        $cases = [
            ['ctrip', ['config_id' => 'cfg-58', 'system_hotel_id' => 58, 'payload_json' => '{}']],
            ['ctrip', ['config_id' => 'cfg-58', 'system_hotel_id' => 58, 'unknown_field' => 'value']],
            ['meituan', ['config_id' => 'mt-58', 'system_hotel_id' => 58, 'url' => new \stdClass()]],
            ['meituan', ['config_id' => 'mt-58', 'system_hotel_id' => 58, 'partner_id' => ['nested' => 'value']]],
            ['meituan', ['config_id' => 'mt-58', 'system_hotel_id' => 58, 'self_metric_values' => ['roomNights' => 12]]],
        ];
        $taskCalls = 0;

        foreach ($cases as [$platform, $requestData]) {
            try {
                $sanitized = $platform === 'ctrip'
                    ? $harness->sanitizeCtripRequest($requestData)
                    : $harness->sanitizeMeituanRequest($requestData);
                $taskCalls++;
                self::fail('Invalid manual request must not reach task creation: ' . json_encode($sanitized));
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }

        self::assertSame(0, $taskCalls);
    }

    public function testMeituanManualFetchSelectsSavedResourceIdsByConfigAndHotelLocator(): void
    {
        $harness = $this->identityHarness();
        $configs = [
            [
                'id' => 'meituan-58',
                'config_id' => 'meituan-58',
                'hotel_id' => 58,
                'system_hotel_id' => 58,
                'partner_id' => 'partner-58',
                'poi_id' => 'poi-58',
            ],
            [
                'id' => 'meituan-59',
                'config_id' => 'meituan-59',
                'hotel_id' => 59,
                'system_hotel_id' => 59,
                'partner_id' => 'partner-59',
                'poi_id' => 'poi-59',
            ],
        ];

        $selected = $harness->selectMeituanManualConfig($configs, 'meituan-58', 58);

        self::assertNotSame([], $selected);
        self::assertSame('partner-58', $selected['partner_id']);
        self::assertSame('poi-58', $selected['poi_id']);
        self::assertSame([], $harness->selectMeituanManualConfig($configs, 'meituan-58', 59));
        self::assertSame([], $harness->selectMeituanManualConfig($configs, 'meituan-unknown', 58));
    }

    public function testPrimaryManualConsumersAndAsyncTasksUseOnlySanitizedRequestData(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        $ctrip = $this->methodSource($source, 'public function fetchCtrip()', 'private function ctripBusinessQunarVisitorQuality');
        $meituan = $this->methodSource($source, 'public function fetchMeituan()', 'private function validateMeituanManualFetchHotelIdentity');

        self::assertStringContainsString('sanitizeCtripManualFetchRequestData($rawRequestData)', $ctrip);
        self::assertStringContainsString('sanitizeMeituanManualFetchRequestData($rawRequestData)', $meituan);
        foreach ([$ctrip, $meituan] as $method) {
            self::assertStringNotContainsString('assertNoInlineOtaExecutionCredentials($requestData)', $method);
            self::assertStringNotContainsString('payload_json', $method);
            self::assertMatchesRegularExpression(
                '/fn\(array \$credentialPayload\): Response => \$this->execute(?:Ctrip|Meituan)ManualFetch\(\s*\$requestData,\s*\$credentialPayload,\s*\$systemHotelId\s*\)/s',
                $method
            );
            self::assertStringNotContainsString('use (&', $method);
        }
        self::assertStringContainsString("createTask('ctrip', (int)\$systemHotelId, \$startDate, \$endDate, \$requestData, [", $ctrip);
        self::assertStringContainsString('$taskRequestData = $requestData;', $meituan);
    }

    public function testPrimaryManualFetchEndpointsUseVaultAndDoNotHydrateSavedSecrets(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        $ctrip = $this->methodSource($source, 'public function fetchCtrip()', 'private function ctripBusinessQunarVisitorQuality');
        $meituan = $this->methodSource($source, 'public function fetchMeituan()', 'private function validateMeituanManualFetchHotelIdentity');

        foreach ([['ctrip', $ctrip], ['meituan', $meituan]] as [$platform, $method]) {
            self::assertMatchesRegularExpression(
                "/withOtaCredentialForExecution\\(\\s*'{$platform}'/",
                $method
            );
            self::assertStringContainsString("'config_id'", $method);
            self::assertStringContainsString("'system_hotel_id'", $method);
            foreach ([
                "post('cookies'",
                "post('auth_data'",
                'resolveCtripFetchConfigForHotel(',
                'resolveMeituanFetchConfigForHotel(',
                'getStoredCtripConfigList(',
                'getStoredMeituanConfigList(',
                'readSavedOtaDataConfig(',
                '$e->getMessage()',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $method, "{$platform} manual execution must not expose {$forbidden}");
            }
        }
        self::assertStringContainsString('catch (\\DomainException)', $ctrip);
        self::assertStringContainsString('Task6', $ctrip);
        self::assertMatchesRegularExpression(
            '/catch \(\\\\InvalidArgumentException\).*?json\(\[\s*\'code\' => 400.*?\], 400\);/s',
            $ctrip
        );

        $primaryExecutionSource = $ctrip . $meituan;
        foreach (['ctrip_config_list', 'meituan_config_list', 'data_config_', 'online_data_cookies_'] as $legacySecretStore) {
            self::assertStringNotContainsString($legacySecretStore, $primaryExecutionSource);
        }
    }

    public function testCtripTrafficAndAdsExecutionSchemasAcceptOnlyExplicitBusinessFields(): void
    {
        $traffic = $this->invokeExecutionSanitizer('sanitizeCtripTrafficExecutionRequestData', [
            'config_id' => 'cfg-58',
            'system_hotel_id' => 58,
            'url' => 'https://ebooking.ctrip.com/api/traffic',
            'platform' => 'Ctrip',
            'date_range' => 'custom',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'fingerPrintKeys' => 'fp-a',
            'spiderVersion' => '2.0',
            'auto_save' => true,
            'async' => false,
        ]);
        $ads = $this->invokeExecutionSanitizer('sanitizeCtripAdsExecutionRequestData', [
            'config_id' => 'cfg-58',
            'system_hotel_id' => 58,
            'url' => 'https://ebooking.ctrip.com/pyramidad/api/report',
            'api_type' => 'effect_report',
            'method' => 'POST',
            'date_range' => 'custom',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'campaign_id' => 'campaign-7',
            'hotel_id' => '880058',
            'hotel_name' => 'A',
            'auto_save' => true,
        ]);

        self::assertSame('fp-a', $traffic['fingerPrintKeys']);
        self::assertSame('campaign-7', $ads['campaign_id']);

        foreach ([
            ['sanitizeCtripTrafficExecutionRequestData', ['cookies' => 'INLINE_SECRET']],
            ['sanitizeCtripTrafficExecutionRequestData', ['extra_params' => '{"spiderkey":"INLINE_SECRET"}']],
            ['sanitizeCtripAdsExecutionRequestData', ['payload_json' => '{"token":"INLINE_SECRET"}']],
            ['sanitizeCtripAdsExecutionRequestData', ['unknown' => 'value']],
            ['sanitizeCtripAdsExecutionRequestData', ['campaign_id' => ['nested' => 'value']]],
        ] as [$method, $extra]) {
            try {
                $this->invokeExecutionSanitizer($method, array_merge([
                    'config_id' => 'cfg-58',
                    'system_hotel_id' => 58,
                ], $extra));
                self::fail("{$method} must reject inline, unknown, or nested execution data.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }
    }

    public function testMeituanRemainingExecutionSchemasAcceptOnlyExplicitBusinessFields(): void
    {
        $traffic = $this->invokeExecutionSanitizer('sanitizeMeituanTrafficExecutionRequestData', [
            'config_id' => 'mt-58',
            'system_hotel_id' => 58,
            'url' => 'https://eb.meituan.com/api/traffic',
            'partner_id' => 'partner-58',
            'poi_id' => 'poi-58',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'auto_save' => true,
        ]);
        $business = $this->invokeExecutionSanitizer('sanitizeMeituanBusinessExecutionRequestData', [
            'config_id' => 'mt-58',
            'system_hotel_id' => 58,
            'url' => 'https://eb.meituan.com/api/order/list',
            'method' => 'GET',
            'partner_id' => 'partner-58',
            'poi_id' => 'poi-58',
            'shop_id' => 'shop-58',
            'hotel_name' => 'A',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'page_no' => 1,
            'page_size' => 20,
            'auto_save' => true,
        ]);

        self::assertSame('partner-58', $traffic['partner_id']);
        self::assertSame(20, $business['page_size']);
        $businessParams = $this->invokeExecutionSanitizer('meituanManualBusinessParamsFromSanitizedRequest', $business);
        self::assertSame(1, $businessParams['pageNo']);
        self::assertSame(20, $businessParams['pageSize']);

        foreach ([
            ['sanitizeMeituanTrafficExecutionRequestData', ['cookies' => 'INLINE_SECRET']],
            ['sanitizeMeituanTrafficExecutionRequestData', ['extra_params' => '{"mtgsig":"INLINE_SECRET"}']],
            ['sanitizeMeituanBusinessExecutionRequestData', ['payload_json' => '{"auth_data":"INLINE_SECRET"}']],
            ['sanitizeMeituanBusinessExecutionRequestData', ['headers' => ['Cookie' => 'INLINE_SECRET']]],
            ['sanitizeMeituanBusinessExecutionRequestData', ['unknown' => 'value']],
        ] as [$method, $extra]) {
            try {
                $this->invokeExecutionSanitizer($method, array_merge([
                    'config_id' => 'mt-58',
                    'system_hotel_id' => 58,
                ], $extra));
                self::fail("{$method} must reject inline, unknown, or nested execution data.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }
    }

    public function testCtripRequestExecutionSchemasAcceptOnlyExplicitBusinessFieldsAndFlatUrlLists(): void
    {
        $cookieApi = $this->invokeExecutionSanitizer('sanitizeCtripCookieApiExecutionRequestData', [
            'config_id' => 'cfg-58',
            'system_hotel_id' => 58,
            'request_urls' => [
                'https://ebooking.ctrip.com/api/report/a',
                'https://ebooking.ctrip.com/api/report/b',
            ],
            'method' => 'GET',
            'hotel_id' => '880058',
            'hotel_name' => 'A',
            'data_date' => '2026-07-09',
            'auto_save' => true,
        ]);
        $overview = $this->invokeExecutionSanitizer('sanitizeCtripOverviewExecutionRequestData', [
            'config_id' => 'cfg-58',
            'system_hotel_id' => 58,
            'request_urls' => ['https://ebooking.ctrip.com/api/overview'],
            'method' => 'POST',
            'hotel_id' => '880058',
            'hotel_name' => 'A',
            'data_date' => '2026-07-09',
        ]);

        self::assertCount(2, $cookieApi['request_urls']);
        self::assertSame('POST', $overview['method']);

        foreach ([
            ['sanitizeCtripCookieApiExecutionRequestData', ['cookies' => 'INLINE_SECRET']],
            ['sanitizeCtripCookieApiExecutionRequestData', ['endpoints' => [['headers' => ['Cookie' => 'INLINE_SECRET']]]]],
            ['sanitizeCtripCookieApiExecutionRequestData', ['request_urls' => [['url' => 'https://ebooking.ctrip.com/api']]]],
            ['sanitizeCtripOverviewExecutionRequestData', ['spidertoken' => 'INLINE_SECRET']],
            ['sanitizeCtripOverviewExecutionRequestData', ['payload_json' => '{"token":"INLINE_SECRET"}']],
            ['sanitizeCtripOverviewExecutionRequestData', ['unknown' => 'value']],
        ] as [$method, $extra]) {
            try {
                $this->invokeExecutionSanitizer($method, array_merge([
                    'config_id' => 'cfg-58',
                    'system_hotel_id' => 58,
                ], $extra));
                self::fail("{$method} must reject inline, unknown, or nested execution data.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }
    }

    public function testBackgroundManualFetchReplayTaskIdIsStrictlyScopedAndAcceptedByAllAsyncSchemas(): void
    {
        $schemas = [
            ['sanitizeCtripManualFetchRequestData', 'ctrip', 'cfg-58'],
            ['sanitizeCtripTrafficExecutionRequestData', 'ctrip', 'cfg-58'],
            ['sanitizeCtripAdsExecutionRequestData', 'ctrip', 'cfg-58'],
            ['sanitizeMeituanManualFetchRequestData', 'meituan', 'mt-58'],
            ['sanitizeMeituanTrafficExecutionRequestData', 'meituan', 'mt-58'],
            ['sanitizeMeituanBusinessExecutionRequestData', 'meituan', 'mt-58'],
        ];

        foreach ($schemas as [$method, $platform, $configId]) {
            $taskId = "manual_{$platform}_fetch_58_20260710090000_deadbeef";
            $body = [
                'config_id' => $configId,
                'system_hotel_id' => 58,
                'async' => false,
                'background_task' => true,
                'task_id' => $taskId,
            ];
            $sanitized = $this->invokeExecutionSanitizer($method, $body);
            self::assertSame($taskId, $sanitized['task_id']);

            foreach ([
                array_merge($body, ['task_id' => str_replace("manual_{$platform}", 'manual_' . ($platform === 'ctrip' ? 'meituan' : 'ctrip'), $taskId)]),
                array_merge($body, ['task_id' => str_replace('_58_', '_59_', $taskId)]),
                array_merge($body, ['task_id' => 'invalid-task-id']),
                array_merge($body, ['background_task' => false]),
                array_merge($body, ['async' => true]),
            ] as $invalidBody) {
                try {
                    $this->invokeExecutionSanitizer($method, $invalidBody);
                    self::fail("{$method} must reject forged background task control fields.");
                } catch (\InvalidArgumentException $e) {
                    self::assertSame(400, $e->getCode());
                }
            }

            $foreground = $this->invokeExecutionSanitizer($method, [
                'config_id' => $configId,
                'system_hotel_id' => 58,
                'async' => false,
            ]);
            self::assertArrayNotHasKey('task_id', $foreground);
        }
    }

    public function testHeaderAliasCredentialsAreProtectedByTheUnifiedResultGuard(): void
    {
        $secrets = [
            'api_key' => 'API_KEY_HEADER_SENTINEL_123456',
            'token' => 'TOKEN_HEADER_SENTINEL_123456',
            'X-API-Key' => 'X_API_KEY_HEADER_SENTINEL_123456',
        ];
        $controller = $this->readHarness($this->fakeVault(['headers' => $secrets]), $this->permittedUser());

        foreach ($secrets as $secret) {
            try {
                $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $secret);
                self::fail('A reusable credential in a header alias must not reach the execution result.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('protected credential material', $e->getMessage());
            }
        }
    }

    public function testDisplayCurrencyCookieValueDoesNotCauseFalsePositiveButSessionStillDoes(): void
    {
        $session = 'SESSION_COOKIE_SENTINEL_123456';
        $controller = $this->readHarness($this->fakeVault([
            'cookies' => "cookiePricesDisplayed=CNY; sid={$session}",
            'auth_data' => [
                'cookieObj' => [
                    'cookiePricesDisplayed' => 'CNY',
                    'sid' => $session,
                ],
            ],
        ]), $this->permittedUser());

        self::assertSame(
            ['currency' => 'CNY', 'saved_count' => 1],
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): array => [
                'currency' => 'CNY',
                'saved_count' => 1,
            ])
        );

        try {
            $controller->execute('ctrip', 'cfg-58', 58, static fn(array $_): string => $session);
            self::fail('A reusable session cookie value must remain protected.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('protected credential material', $e->getMessage());
        }
    }

    public function testCtripTrafficAndAdsSourcesUseVaultCallbacksAndSafeErrors(): void
    {
        $manualSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        $this->assertExecutionSourcesUseVaultCallbacksAndSafeErrors([
            ['ctrip', 'sanitizeCtripTrafficExecutionRequestData', 'executeCtripTrafficFetch', $this->methodSource($manualSource, 'public function fetchCtripTraffic()', 'public function fetchCtripAds()')],
            ['ctrip', 'sanitizeCtripAdsExecutionRequestData', 'executeCtripAdsFetch', $this->methodSource($manualSource, 'public function fetchCtripAds()', 'public function fetchMeituanTraffic()')],
        ]);
    }

    public function testMeituanRemainingSourcesUseVaultCallbacksAndSafeErrors(): void
    {
        $manualSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        $this->assertExecutionSourcesUseVaultCallbacksAndSafeErrors([
            ['meituan', 'sanitizeMeituanTrafficExecutionRequestData', 'executeMeituanTrafficFetch', $this->methodSource($manualSource, 'public function fetchMeituanTraffic()', 'public function fetchMeituanOrders()')],
            ['meituan', 'sanitizeMeituanBusinessExecutionRequestData', 'executeMeituanManualBusinessSection', $this->methodSource($manualSource, 'private function fetchMeituanManualBusinessSection', 'public function fetchMeituanComments()')],
        ]);
    }

    public function testMeituanManualOrdersAndAdsReturnOnlySanitizedNormalizedRows(): void
    {
        $manualSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        $executionSource = $this->methodSource(
            $manualSource,
            'private function executeMeituanManualBusinessSection(',
            'public function fetchMeituanComments()'
        );

        self::assertStringContainsString("'data' => \$rows", $executionSource);
        self::assertStringContainsString("'privacy_boundary' => 'sanitized_normalized_rows_only'", $executionSource);
        self::assertStringNotContainsString("'data' => \$items", $executionSource);
        self::assertStringNotContainsString("'decoded_data' => \$responseData", $executionSource);
        self::assertStringNotContainsString("'raw_response' =>", $executionSource);
        self::assertStringNotContainsString("'request_payload' => \$params", $executionSource);
    }

    public function testCtripRequestSourcesUseVaultCallbacksAndSafeErrors(): void
    {
        $requestSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php');
        $this->assertExecutionSourcesUseVaultCallbacksAndSafeErrors([
            ['ctrip', 'sanitizeCtripCookieApiExecutionRequestData', 'executeCtripCookieApiDataFetch', $this->methodSource($requestSource, 'public function fetchCtripCookieApiData()', 'private function buildCtripEndpointEvidenceCatalogPreviewImportPlan')],
            ['ctrip', 'sanitizeCtripOverviewExecutionRequestData', 'executeCtripOverviewDataFetch', $this->methodSource($requestSource, 'public function fetchCtripOverviewData()', 'public function fetchCustom()')],
        ]);
    }

    public function testScheduledCollectorUsesScopedVaultInsteadOfLegacyCredentialStores(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString('OtaCredentialVault', $source);
        self::assertStringContainsString('withPayloadForExecution(', $source);
        self::assertStringContainsString("where('credential_status', 'ready')", $source);
        self::assertStringContainsString("where('platform', 'ctrip')", $source);
        foreach (['ctrip_config_list', 'online_data_cookies_', "['cookies'] ?? ''", "['cookie'] ?? ''"] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testScheduledCollectorRequiresExplicitConfigWhenMoreThanOneCredentialIsReady(): void
    {
        Db::name('ota_credentials')->insertAll([
            ['tenant_id' => 7, 'system_hotel_id' => 58, 'platform' => 'ctrip', 'config_id' => 'cfg-a', 'credential_status' => 'ready'],
            ['tenant_id' => 7, 'system_hotel_id' => 58, 'platform' => 'ctrip', 'config_id' => 'cfg-b', 'credential_status' => 'ready'],
        ]);
        $command = new \app\command\AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'resolveCtripCredentialLocatorForHotel');
        $method->setAccessible(true);

        $ambiguous = $method->invoke($command, 58, '');
        self::assertSame('ambiguous_credential', $ambiguous['status']);

        $selected = $method->invoke($command, 58, 'cfg-b');
        self::assertSame('ready', $selected['status']);
        self::assertSame(7, $selected['tenant_id']);
        self::assertSame('cfg-b', $selected['config_id']);

        $missing = $method->invoke($command, 58, 'cfg-c');
        self::assertSame('missing_credential', $missing['status']);
    }

    public function testScheduledCollectorPreservesOnlyValidatedCtripExecutionMetadata(): void
    {
        $command = new \app\command\AutoFetchOnlineData();
        $url = new \ReflectionMethod($command, 'normalizeScheduledCtripRequestUrl');
        $node = new \ReflectionMethod($command, 'normalizeScheduledCtripNodeId');
        $url->setAccessible(true);
        $node->setAccessible(true);

        self::assertSame(
            'https://ebooking.ctrip.com/custom/report',
            $url->invoke($command, 'https://ebooking.ctrip.com/custom/report')
        );
        self::assertSame('', $url->invoke($command, 'https://evil.example/steal'));
        self::assertSame('24588', $node->invoke($command, ''));
        self::assertSame('node.custom-58', $node->invoke($command, 'node.custom-58'));
        self::assertSame('', $node->invoke($command, '../../escape'));
    }

    public function testControllerAutoFetchUsesOnlyCredentialLocatorsAndVaultCallbacks(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php');

        self::assertStringContainsString('withOtaCredentialForExecution(', $source);
        self::assertStringContainsString("'config_id'", $source);
        self::assertStringContainsString("'system_hotel_id'", $source);
        foreach ([
            'data_config_',
            'readSavedOtaDataConfig(',
            'readCtripCookieHeaderFromRequest(',
            "\$fetchConfig['cookies']",
            "\$config['cookies']",
            "\$body['cookies']",
            "\$ctripConfig['cookies']",
            "\$meituanConfig['cookies']",
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, "Auto-fetch must not read reusable credential material through {$forbidden}");
        }
    }

    public function testControllerAutoFetchExecutionKeepsSecretsInsideVaultCallback(): void
    {
        $vault = $this->fakeVault([
            'cookies' => 'AUTO_FETCH_SENTINEL_SECRET',
            'auth_data' => ['token' => 'AUTO_FETCH_AUTH_SENTINEL'],
        ]);
        $controller = $this->autoFetchExecutionHarness($vault);

        $ctrip = $controller->runCtrip([
            'config_id' => 'ctrip-58',
            'system_hotel_id' => 58,
            'url' => 'https://ebooking.ctrip.com/api/report',
            'node_id' => '24588',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
        ], 58);
        self::assertTrue($controller->requestSawCredential);
        self::assertSame(2, $ctrip['saved_count']);
        self::assertSame([[7, 58, 'ctrip', 'ctrip-58']], $vault->calls);
        self::assertStringNotContainsString('SENTINEL', json_encode($ctrip, JSON_THROW_ON_ERROR));

        $controller->requestSawCredential = false;
        $meituan = $controller->runMeituan([
            'config_id' => 'meituan-58',
            'system_hotel_id' => 58,
            'url' => 'https://eb.meituan.com/api/rank',
            'partner_id' => 'partner-58',
            'poi_id' => 'poi-58',
            'rank_type' => 'P_RZ',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
        ], 58);
        self::assertTrue($controller->requestSawCredential);
        self::assertSame(3, $meituan['saved_count']);
        self::assertSame(
            [[7, 58, 'ctrip', 'ctrip-58'], [7, 58, 'meituan', 'meituan-58']],
            $vault->calls
        );
        self::assertStringNotContainsString('SENTINEL', json_encode($meituan, JSON_THROW_ON_ERROR));
    }

    public function testOtaConfigResolversDoNotFallBackToLegacySecretStores(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OtaConfigConcern.php');
        $ctrip = $this->methodSource(
            $source,
            'private function resolveCtripFetchConfigForHotel(int $hotelId): array',
            'private function resolveMeituanFetchConfigForHotel(int $hotelId): array'
        );
        $ctripLight = $this->methodSource(
            $source,
            'private function resolveCtripFetchConfigForHotelLight(int $hotelId): array',
            'private function resolveMeituanFetchConfigForHotelLight(int $hotelId): array'
        );

        foreach ([$ctrip, $ctripLight] as $resolver) {
            self::assertStringNotContainsString('online_data_cookies_', $resolver);
            self::assertStringNotContainsString('readSavedOtaDataConfig(', $resolver);
            self::assertStringNotContainsString("['cookies']", $resolver);
        }
    }

    public function testReliabilityAndDecisionMetadataPathsNeverReadReusableSecrets(): void
    {
        $paths = [
            'app/controller/concern/CollectionReliabilityConcern.php',
            'app/controller/Agent.php',
            'app/service/OperationManagementService.php',
        ];
        foreach ($paths as $path) {
            $source = (string)file_get_contents(dirname(__DIR__) . '/' . $path);
            foreach (['ctrip_config_list', 'meituan_config_list', 'online_data_cookies_', 'secret_json', 'encrypted_payload'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, "{$path} must not read {$forbidden}");
            }
        }
    }

    public function testP0AndCollectorMetadataSurfacesNeverReadLegacySourceSecrets(): void
    {
        $paths = [
            'app/controller/concern/Phase1EmployeeConsoleConcern.php',
            'app/controller/concern/CtripCollectorWorkflowConcern.php',
            'scripts/build_phase1_ota_live_closure_evidence.php',
            'scripts/inspect_phase1_ota_live_closure.php',
        ];
        foreach ($paths as $path) {
            $source = (string)file_get_contents(dirname(__DIR__) . '/' . $path);
            self::assertStringNotContainsString('secret_json', $source, "{$path} must use credential metadata only");
            self::assertStringNotContainsString('encrypted_payload', $source, "{$path} must never read vault ciphertext");
        }
    }

    public function testLegacyCtripCommentConfigEndpointsCannotPersistOrReadReusableSecrets(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/CtripCommentsConcern.php');
        $save = $this->methodSource(
            $source,
            'public function saveCtripCommentConfig(): Response',
            'public function getCtripCommentConfigList(): Response'
        );
        $list = $this->methodSource(
            $source,
            'public function getCtripCommentConfigList(): Response',
            "\n}"
        );

        self::assertStringContainsString('Legacy Ctrip comment Cookie/API config storage is disabled.', $save);
        self::assertStringContainsString('return $this->success([]);', $list);
        foreach (['saveOtaDataConfigValue', 'readOtaDataConfigValue', 'data_config_', "'cookies'", "'spidertoken'", "'payload_json'"] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $save . $list);
        }
    }

    public function testLegacyCookieEndpointsCannotPersistReadOrReturnReusableSecrets(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/CookieEndpointConcern.php');
        foreach (['saveCookies', 'getCookiesList', 'getCookiesDetail', 'deleteCookies', 'batchDeleteCookies'] as $methodName) {
            $start = "public function {$methodName}(): Response";
            $startOffset = strpos($source, $start);
            self::assertIsInt($startOffset, "Missing source marker: {$start}");
            $nextOffset = strpos($source, "\n    public function ", $startOffset + strlen($start));
            if ($nextOffset === false) {
                $nextOffset = strpos($source, "\n}", $startOffset);
            }
            self::assertIsInt($nextOffset, "Missing method end for {$methodName}");
            $method = substr($source, $startOffset, $nextOffset - $startOffset);
            foreach (['getConfigList', 'setConfigList', 'SystemConfig::getValue', 'SystemConfig::setValue', 'online_data_cookies_'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $method, "{$methodName} must not access {$forbidden}");
            }
        }
        self::assertStringNotContainsString('private function getConfigList', (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OtaConfigConcern.php'));
        self::assertStringNotContainsString('private function setConfigList', (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OtaConfigConcern.php'));
    }

    public function testLegacyCtripBookmarkCaptureAndSaveEndpointsAreHardDisabled(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php');
        $autoCapture = $this->methodSource(
            $source,
            'public function autoCaptureCtripCookie(): Response',
            'public function saveCtripConfigByBookmark(): Response'
        );
        $bookmarkSave = $this->methodSource(
            $source,
            'public function saveCtripConfigByBookmark(): Response',
            'private function sendHttpRequest('
        );

        self::assertStringContainsString('410', $autoCapture);
        self::assertStringContainsString('410', $bookmarkSave);
        self::assertStringContainsString('$this->checkPermission();', $bookmarkSave);
        self::assertStringContainsString("\$this->checkActionPermission('can_fetch_online_data');", $bookmarkSave);

        foreach (['header(', "request->header('cookie'", "file_get_contents('php://input')", 'saveCtripConfigPayload(', "['cookies']", "['auth_data']"] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $autoCapture . $bookmarkSave);
        }
    }

    public function testConfigListEndpointsExposeMigrationRequiredState(): void
    {
        $ctripSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php');
        $ctripList = $this->methodSource(
            $ctripSource,
            'public function getCtripConfigList(): Response',
            'public function getCtripConfigDetail(): Response'
        );
        $meituanSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/MeituanConfigConcern.php');
        $meituanList = $this->methodSource(
            $meituanSource,
            'public function getMeituanConfigList(): Response',
            'public function getMeituanConfigDetail(): Response'
        );

        foreach (['ctrip' => $ctripList, 'meituan' => $meituanList] as $platform => $method) {
            self::assertStringContainsString(
                '$list = $this->sanitizeStoredOtaConfigListForRuntime($list);',
                $method,
                "{$platform} config list must expose migration_required before browser gating"
            );
            self::assertStringNotContainsString(
                "array_map([\$this, 'sanitizeSecretConfig'], array_values(\$list))",
                $method,
                "{$platform} config list must not flatten legacy credentials into a generic configured state"
            );
        }
    }

    public function testConfigDetailEndpointsExposeMigrationRequiredState(): void
    {
        $ctripSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php');
        $ctripDetail = $this->methodSource(
            $ctripSource,
            'public function getCtripConfigDetail(): Response',
            'public function deleteCtripConfig(): Response'
        );
        $meituanSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/MeituanConfigConcern.php');
        $meituanDetail = $this->methodSource(
            $meituanSource,
            'public function getMeituanConfigDetail(): Response',
            'public function deleteMeituanConfig(): Response'
        );

        foreach (['ctrip' => $ctripDetail, 'meituan' => $meituanDetail] as $platform => $method) {
            self::assertStringContainsString(
                '$safeList = $this->sanitizeStoredOtaConfigListForRuntime([$id => $list[$id]]);',
                $method,
                "{$platform} config detail must expose migration_required before browser use"
            );
            self::assertStringNotContainsString(
                '$this->success($this->sanitizeSecretConfig($list[$id]))',
                $method,
                "{$platform} config detail must not flatten legacy credentials into a generic configured state"
            );
        }
    }

    public function testAutoFetchSharedCachesAcceptMetadataOnly(): void
    {
        $otaConfig = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OtaConfigConcern.php');
        $autoFetch = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php');

        self::assertStringContainsString('private function sanitizeStoredOtaConfigListForRuntime(array $list): array', $otaConfig);
        self::assertStringContainsString('splitOtaConfigSecrets($item)', $otaConfig);
        self::assertStringContainsString("\$metadata['credential_status'] = 'migration_required';", $otaConfig);
        self::assertStringContainsString("\$metadata['has_cookies'] = false;", $otaConfig);
        self::assertSame(4, substr_count($otaConfig, 'sanitizeStoredOtaConfigListForRuntime($list)'));

        self::assertStringContainsString('_config_list_metadata_v2', $autoFetch);
        self::assertStringNotContainsString('_config_list_raw', $autoFetch);
        self::assertStringContainsString("field('id,name,system_hotel_id,platform,data_type,ingestion_method,config_json,enabled,status')", $autoFetch);
        self::assertStringContainsString("field('id,tenant_id,name,system_hotel_id,platform,data_type,ingestion_method,config_json,enabled,status,last_sync_status,last_error')", $autoFetch);
        self::assertStringContainsString('sanitizeBrowserProfileSourcesForSharedCache($rows)', $autoFetch);
        self::assertStringContainsString('writeAutoFetchLightReadCache($cacheKey, $safeRows)', $autoFetch);
    }

    public function testProfileDataSourceReadsExcludeSecretJsonAndRejectLegacyConfigSecrets(): void
    {
        $command = (string)file_get_contents(dirname(__DIR__) . '/app/command/PlatformProfileLogin.php');
        foreach ([
            "field('id,system_hotel_id,platform,ingestion_method,enabled,status')",
            "field('id,system_hotel_id,platform,data_type,ingestion_method,config_json,enabled,status,last_error,last_sync_status')",
            "field('id,config_json')",
            'decodeSafeProfileSourceConfig',
            'assertProfileSourceMetadataIsSafe',
            'credential migration is required',
        ] as $required) {
            self::assertStringContainsString($required, $command);
        }

        foreach ([
            'app/controller/concern/PlatformDataSourceConcern.php',
            'app/controller/concern/OnlineDataRequestConcern.php',
        ] as $path) {
            $source = (string)file_get_contents(dirname(__DIR__) . '/' . $path);
            self::assertStringContainsString('sanitizeBrowserProfileSourcesForSharedCache([$source])', $source);
        }
    }

    public function testCtripRuntimeInputFilesUseOwnerOnlyPermissionsAndFailClosed(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/PlatformProfileCaptureConcern.php');
        $methods = [
            $this->methodSource(
                $source,
                'private function prepareCtripCookieApiCaptureFiles(',
                'private function buildCtripProfileStatus('
            ),
            $this->methodSource(
                $source,
                'private function prepareCtripEndpointEvidenceValidationFiles(',
                'private function buildCtripEndpointEvidenceBundleFromRequest('
            ),
            $this->methodSource(
                $source,
                'private function createCtripProfileFieldConfigFile(',
                'private function buildCtripProfileFieldConfigPayload('
            ),
        ];

        foreach ($methods as $method) {
            self::assertStringContainsString('LOCK_EX', $method);
            self::assertStringContainsString('chmod(', $method);
            self::assertStringContainsString('0600', $method);
            self::assertStringContainsString('unlink(', $method);
        }
    }

    public function testDisabledCookieReceiverAuditNeverStoresRequestControlledSecretText(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/CookieEndpointConcern.php');
        $receive = $this->methodSource(
            $source,
            'public function receiveCookies(): Response',
            'private function recordPublicEndpointFailure'
        );

        self::assertStringContainsString("'source_present'", $receive);
        self::assertStringContainsString("'name_present'", $receive);
        self::assertStringNotContainsString("'source' => (string)\$this->request->post", $receive);
        self::assertStringNotContainsString("'name' => (string)\$this->request->post", $receive);
        self::assertStringContainsString('sanitizePublicEndpointExtra($decoded)', $source);
        self::assertStringContainsString('Bearer ****', $source);
    }

    /**
     * @param array<int, array{0:string,1:string,2:string,3:string}> $methods
     */
    private function assertExecutionSourcesUseVaultCallbacksAndSafeErrors(array $methods): void
    {
        foreach ($methods as [$platform, $sanitizer, $executor, $source]) {
            self::assertStringContainsString("{$sanitizer}(\$rawRequestData)", $source);
            self::assertMatchesRegularExpression("/withOtaCredentialForExecution\\(\\s*'{$platform}'/", $source);
            self::assertStringContainsString($executor . '(', $source);
            self::assertStringContainsString("'config_id'", $source);
            self::assertStringContainsString("'system_hotel_id'", $source);
            foreach ([
                "post('cookies'",
                "post('spiderkey'",
                "post('spidertoken'",
                "post('token'",
                'readCtripCookieHeaderFromRequest(',
                'createCtripCookieApiCookieFileFromProfile(',
                'resolveCtripFetchConfigForHotel(',
                'resolveMeituanFetchConfigForHotel(',
                'readSavedOtaDataConfig(',
                'ctrip_config_list',
                'meituan_config_list',
                'data_config_',
                'online_data_cookies_',
                '$e->getMessage()',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, "{$executor} must not contain {$forbidden}");
            }
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, mixed>
     */
    private function invokeExecutionSanitizer(string $method, array $requestData): array
    {
        $reflection = new \ReflectionClass(\app\controller\OnlineData::class);
        self::assertTrue($reflection->hasMethod($method), "Missing execution sanitizer: {$method}");
        $controller = $reflection->newInstanceWithoutConstructor();
        $target = $reflection->getMethod($method);
        $target->setAccessible(true);
        $result = $target->invoke($controller, $requestData);
        self::assertIsArray($result);
        return $result;
    }

    private function methodSource(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        $end = strpos($source, $endNeedle, is_int($start) ? $start : 0);
        self::assertIsInt($start, "Missing source marker: {$startNeedle}");
        self::assertIsInt($end, "Missing source marker: {$endNeedle}");
        return substr($source, $start, $end - $start);
    }
}
