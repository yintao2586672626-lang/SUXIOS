<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use app\service\PlatformDataSyncService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaProfileSessionProofServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private static string $projectRoot = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_profile_session_proof_' . getmypid() . '.sqlite';
        self::$projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_profile_session_proof_root_' . getmypid();

        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
        @rmdir(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage');
        @rmdir(self::$projectRoot);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$databasePath);
        Db::connect(null, true);
        if (!is_dir(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage')) {
            mkdir(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage', 0775, true);
        }
        $this->createSchema();
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'name' => 'Tenant one hotel'],
            ['id' => 20, 'tenant_id' => 2, 'name' => 'Tenant two hotel'],
        ]);
    }

    public function testRecordsAndValidatesSameSourceCurrentSessionProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 09:15:30');

        $proof = $service->recordProfileLoginVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            [
                'schema_version' => 1,
                'contract_version' => '2026-07-19.1',
                'performed' => true,
                'verified' => true,
                'status' => 'collectable',
                'collectable' => true,
                'proof_eligible' => true,
                'evidence_type' => 'recognized_business_response_2xx_plus_session_cookie',
                'evidence_level' => 'strong',
                'sensitive_values_exposed' => false,
                'signals' => [
                    'auth' => ['status' => 'pass'],
                    'url' => ['status' => 'pass', 'trusted_host' => true, 'business_path' => true],
                    'page' => ['status' => 'pass', 'business_marker_present' => true, 'risk_control_present' => false],
                    'session_state' => ['status' => 'pass', 'session_state_count' => 1],
                    'api' => ['status' => 'pass', 'successful_response_count' => 1],
                    'identity' => ['status' => 'matched', 'hotel_scope_verified' => true],
                ],
            ],
            [
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
            ]
        );

        self::assertTrue($proof['current_session_verified']);
        self::assertSame($sourceId, $proof['current_session_probe_data_source_id']);
        self::assertSame('2026-07-11', $proof['current_session_probe_date']);

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($config['current_session_probe_performed']);
        self::assertTrue($config['current_session_verified']);
        self::assertSame('verified', $config['current_session_status']);
        self::assertSame('2026-07-11 09:15:30', $config['current_session_probe_at']);
        self::assertSame($sourceId, $config['current_session_probe_data_source_id']);
        self::assertSame('2026-07-11', $config['current_session_probe_date']);
        self::assertSame('Asia/Shanghai', $config['current_session_probe_timezone']);
        self::assertSame('ctrip', $config['current_session_probe_platform']);
        self::assertSame(1, $config['current_session_probe_tenant_id']);
        self::assertSame(10, $config['current_session_probe_system_hotel_id']);
        self::assertSame(hash('sha256', 'profile-10'), $config['current_session_probe_profile_key_hash']);
        self::assertSame('same_data_source_profile_session', $config['current_session_probe_scope']);
        self::assertSame('platform_profile_login_task', $config['current_session_probe_producer']);
        self::assertSame('2026-07-19.1', $config['current_session_probe_contract_version']);
        self::assertSame('strong', $config['current_session_probe_evidence_level']);
        self::assertSame('recognized_business_response_2xx_plus_session_cookie', $config['current_session_probe_evidence_type']);
        self::assertSame('matched', $config['current_session_probe_identity_status']);
        self::assertTrue($config['manual_login_state_verified']);
        self::assertTrue($service->isCurrentVerified($source));
        self::assertFalse($this->service('2026-07-12 00:00:01')->isCurrentVerified($source));

        $responseService = new PlatformDataSyncService(null, null, $service);
        $superAdmin = $this->superAdmin();
        $responseRows = $responseService->listDataSources($superAdmin, ['system_hotel_id' => 10]);
        self::assertCount(1, $responseRows);
        self::assertTrue(
            $responseRows[0]['current_session_verified'] ?? false,
            'The data-source response must expose the server-authoritative session verdict.'
        );
        self::assertTrue($responseRows[0]['profile_reusable'] ?? false);
        self::assertSame('reusable', $responseRows[0]['profile_reuse_status'] ?? null);
        self::assertFalse($responseRows[0]['profile_reuse_warning'] ?? true);
        self::assertSame(0, $responseRows[0]['profile_age_days'] ?? null);
        self::assertSame(10, $responseRows[0]['days_until_forced_login'] ?? null);

        $nextDayResponseService = new PlatformDataSyncService(
            null,
            null,
            $this->service('2026-07-12 00:00:01')
        );
        $nextDayRows = $nextDayResponseService->listDataSources($superAdmin, ['system_hotel_id' => 10]);
        self::assertFalse($nextDayRows[0]['current_session_verified'] ?? true);
        self::assertTrue($nextDayRows[0]['profile_reusable'] ?? false);
        self::assertSame('reusable', $nextDayRows[0]['profile_reuse_status'] ?? null);
        self::assertSame(1, $nextDayRows[0]['profile_age_days'] ?? null);
        self::assertSame(9, $nextDayRows[0]['days_until_forced_login'] ?? null);

        $config['current_session_probe_profile_key_hash'] = hash('sha256', 'wrong-profile');
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
        ]);
        $tamperedRows = $responseService->listDataSources($superAdmin, ['system_hotel_id' => 10]);
        self::assertFalse(
            $tamperedRows[0]['current_session_verified'] ?? true,
            'A forged config flag must not be exposed as a verified current session.'
        );
    }

    public function testProfileLoginProofRejectsWeakSessionProbeBeforeDatabaseWrite(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 09:15:30');

        try {
            $service->recordProfileLoginVerified(
                $sourceId,
                10,
                'ctrip',
                'profile-10',
                true,
                ['ok' => true, 'status' => 'logged_in'],
                [
                    'schema_version' => 1,
                    'contract_version' => '2026-07-19.1',
                    'performed' => true,
                    'verified' => false,
                    'status' => 'weak_evidence',
                    'collectable' => false,
                    'proof_eligible' => false,
                    'evidence_type' => 'insufficient',
                    'evidence_level' => 'partial',
                    'sensitive_values_exposed' => false,
                ]
            );
            self::fail('Weak session evidence must not create verified proof.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not strong enough', $e->getMessage());
        }

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('current_session_verified', $config);
    }

    public function testPageAndCookieEvidenceWithoutProtectedApiCannotBecomeProfileProof(): void
    {
        $probe = [
            'schema_version' => 1,
            'contract_version' => '2026-07-19.1',
            'performed' => true,
            'verified' => true,
            'status' => 'collectable',
            'collectable' => true,
            'proof_eligible' => true,
            'evidence_type' => 'business_page_plus_session_cookie',
            'evidence_level' => 'strong',
            'sensitive_values_exposed' => false,
            'signals' => [
                'auth' => ['status' => 'pass'],
                'url' => ['trusted_host' => true, 'business_path' => true],
                'page' => ['business_marker_present' => true, 'risk_control_present' => false],
                'session_state' => ['session_state_count' => 1],
                'api' => ['successful_response_count' => 0],
                'identity' => ['status' => 'matched', 'hotel_scope_verified' => true],
            ],
        ];

        $service = $this->service('2026-07-11 09:15:30');
        self::assertFalse($service->isCollectableProfileLoginSessionProbe($probe));
        self::assertFalse($service->isStrongProfileLoginSessionProbe($probe));
    }

    public function testAccountCollectableProbeWithoutMatchedIdentityCannotBecomeHotelProof(): void
    {
        $probe = [
            'schema_version' => 1,
            'contract_version' => '2026-07-19.1',
            'performed' => true,
            'verified' => true,
            'status' => 'collectable',
            'collectable' => true,
            'proof_eligible' => false,
            'evidence_type' => 'recognized_business_response_2xx_plus_session_cookie',
            'evidence_level' => 'strong',
            'sensitive_values_exposed' => false,
            'signals' => [
                'auth' => ['status' => 'pass'],
                'url' => ['trusted_host' => true, 'business_path' => true],
                'page' => [
                    'business_marker_present' => true,
                    'risk_control_present' => false,
                    'session_expired_present' => false,
                    'challenge_present' => false,
                ],
                'session_state' => ['session_state_count' => 1],
                'api' => ['successful_response_count' => 1],
                'identity' => ['status' => 'not_checked', 'hotel_scope_verified' => false],
            ],
        ];
        $service = $this->service('2026-07-11 09:15:30');

        self::assertTrue($service->isCollectableProfileLoginSessionProbe($probe));
        self::assertFalse($service->isStrongProfileLoginSessionProbe($probe));
    }

    public function testLegacyStrongLookingProbeContractIsRejectedAsPlatformDrift(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $probe = [
            'schema_version' => 1,
            'contract_version' => '2026-07-18.9',
            'performed' => true,
            'verified' => true,
            'status' => 'collectable',
            'collectable' => true,
            'proof_eligible' => true,
            'evidence_type' => 'recognized_business_response_2xx_plus_session_cookie',
            'evidence_level' => 'strong',
            'sensitive_values_exposed' => false,
            'signals' => [
                'auth' => ['status' => 'pass'],
                'url' => ['trusted_host' => true, 'business_path' => true],
                'page' => ['risk_control_present' => false, 'session_expired_present' => false, 'challenge_present' => false],
                'session_state' => ['session_state_count' => 1],
                'api' => ['successful_response_count' => 1],
                'identity' => ['status' => 'matched', 'hotel_scope_verified' => true],
            ],
        ];
        $service = $this->service('2026-07-11 09:15:30');

        self::assertSame('platform_contract_drift', $service->profileLoginSessionProbeContractStatus($probe));
        self::assertFalse($service->isCollectableProfileLoginSessionProbe($probe));
        try {
            $service->recordProfileLoginVerified(
                $sourceId,
                10,
                'ctrip',
                'profile-10',
                true,
                ['ok' => true, 'status' => 'logged_in'],
                $probe
            );
            self::fail('A legacy probe contract must not create a current Profile proof.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not strong enough', $exception->getMessage());
        }

        $config = json_decode(
            (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertArrayNotHasKey('current_session_verified', $config);
    }

    public function testSuccessfulCollectionPreflightCreatesSameDaySessionProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $service = $this->service('2026-07-11 07:30:00');

        $proof = $service->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'meituan',
            'store-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            ['status' => 'matched', 'validated_identifier' => 'store-10']
        );

        self::assertSame('platform_data_sync_preflight', $proof['current_session_probe_producer']);
        self::assertSame('successful_collection_preflight_identity_matched', $proof['current_session_probe_evidence_type']);
        self::assertSame('matched', $proof['current_session_probe_identity_status']);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        self::assertTrue($service->isCurrentVerified($source));
    }

    public function testCollectionPreflightCannotBypassHotelIdentityVerification(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 07:30:00');

        try {
            $service->recordCollectionPreflightVerified(
                $sourceId,
                10,
                'ctrip',
                'profile-10',
                true,
                ['ok' => true, 'status' => 'logged_in'],
                ['status' => 'unverified', 'validated_identifier' => '']
            );
            self::fail('Identity-unverified collection preflight must not create hotel proof.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Collection preflight hotel identity is not verified.',
                $exception->getMessage()
            );
        }

        $config = json_decode(
            (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertArrayNotHasKey('current_session_verified', $config);
    }

    public function testFreshStrongProofClearsOnlyStaleAuthenticationFailureState(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $config = json_decode(
            (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $config['login_status'] = 'login_required';
        $config['login_error'] = '401 login_required';
        $config['auth_error'] = 'captcha slider risk control';
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'last_sync_status' => 'failed',
            'last_error' => 'captcha slider risk control',
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $service = $this->service('2026-07-11 07:30:00');
        $service->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            ['status' => 'matched', 'validated_identifier' => 'profile-10']
        );

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        self::assertNull($source['last_sync_status']);
        self::assertNull($source['last_error']);
        $updatedConfig = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('login_status', $updatedConfig);
        self::assertArrayNotHasKey('login_error', $updatedConfig);
        self::assertArrayNotHasKey('auth_error', $updatedConfig);
        self::assertSame('reusable', $service->profileReuseState($source)['status']);
    }

    public function testCollectionPreflightRejectsFailedOrIdentityUnverifiedAdapterResult(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $proofService = $this->service('2026-07-11 07:30:00');
        $proofService->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'meituan',
            'store-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            ['status' => 'matched', 'validated_identifier' => 'store-10']
        );
        $syncService = new PlatformDataSyncService(null, null, $proofService);
        $method = new \ReflectionMethod($syncService, 'recordBrowserProfileCollectionPreflight');
        $method->setAccessible(true);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $source['config'] = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);

        $failed = $method->invoke($syncService, $source, [
            'status' => 'failed',
            'payload' => [
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'platform_identity_validation' => ['status' => 'mismatch'],
            ],
        ]);
        $identityUnverified = $method->invoke($syncService, $source, [
            'status' => 'success',
            'payload' => [
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'platform_identity_validation' => ['status' => 'unverified'],
            ],
        ]);
        $authFailure = $method->invoke($syncService, $source, [
            'status' => 'waiting_config',
            'payload' => [
                'auth_status' => ['ok' => false, 'status' => 'login_required'],
                'platform_identity_validation' => ['status' => 'unverified'],
            ],
        ]);

        self::assertTrue($failed);
        self::assertTrue($identityUnverified);
        self::assertTrue($authFailure);
        $config = json_decode((string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($config['current_session_verified']);
        self::assertSame('login_required', $config['current_session_status']);
    }

    public function testCollectionFailureDoesNotDemoteVerifiedLoginAndMatchedIdentity(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $proofService = $this->service('2026-07-11 07:30:00');
        $syncService = new PlatformDataSyncService(null, null, $proofService);
        $method = new \ReflectionMethod($syncService, 'recordBrowserProfileCollectionPreflight');
        $method->setAccessible(true);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $source['config'] = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($method->invoke($syncService, $source, [
            'status' => 'failed',
            'payload' => [
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'platform_identity_validation' => [
                    'status' => 'matched',
                    'validated_identifier' => 'store-10',
                ],
            ],
        ]));

        $config = json_decode((string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($config['current_session_verified']);
        self::assertSame('verified', $config['current_session_status']);

        $source['config'] = $config;
        self::assertFalse($method->invoke($syncService, $source, [
            'status' => 'failed',
            'payload' => [
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'platform_identity_validation' => ['status' => 'unverified'],
            ],
        ]));
        $preserved = json_decode((string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($preserved['current_session_verified']);
        self::assertSame('matched', $preserved['current_session_probe_identity_status']);
    }

    public function testCollectionPreflightPersistsExactProbeBlockersAndStopsBackgroundReuse(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $proofService = $this->service('2026-07-11 07:30:00');
        $syncService = new PlatformDataSyncService(null, null, $proofService);
        $recordMethod = new \ReflectionMethod($syncService, 'recordBrowserProfileCollectionPreflight');
        $recordMethod->setAccessible(true);
        $gateMethod = new \ReflectionMethod($syncService, 'browserProfileBackgroundSyncLoginMissingRequirements');
        $gateMethod->setAccessible(true);
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $statusMethod = new \ReflectionMethod($controller, 'resolvePlatformProfileStatusCode');
        $statusMethod->setAccessible(true);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $source['config'] = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);

        $cases = [
            ['probe' => 'platform_contract_drift', 'expected' => 'platform_contract_drift', 'gate' => 'profile_platform_contract_drift'],
            ['probe' => 'permission_denied', 'expected' => 'permission_denied', 'gate' => 'profile_permission_denied'],
            ['probe' => 'weak_evidence', 'expected' => 'capture_failed', 'gate' => 'profile_session_probe_failed'],
        ];
        foreach ($cases as $case) {
            $proofService->recordCollectionPreflightVerified(
                $sourceId,
                10,
                'ctrip',
                'profile-10',
                true,
                ['ok' => true, 'status' => 'logged_in'],
                ['status' => 'matched', 'validated_identifier' => 'profile-10']
            );
            self::assertTrue($recordMethod->invoke($syncService, $source, [
                'status' => 'success',
                'payload' => [
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'session_probe' => ['status' => $case['probe']],
                    'platform_identity_validation' => ['status' => 'matched', 'validated_identifier' => 'profile-10'],
                ],
            ]));

            Db::name('platform_data_sources')->where('id', $sourceId)->update([
                'last_sync_status' => 'failed',
                'last_error' => 'historical HTTP 403 permission_denied',
            ]);

            $blockedSource = Db::name('platform_data_sources')->where('id', $sourceId)->find();
            self::assertIsArray($blockedSource);
            self::assertSame($case['expected'], $proofService->currentSessionBlockingStatus($blockedSource));
            self::assertSame(
                $case['expected'],
                $statusMethod->invoke($controller, 'profile-10', true, $blockedSource, [], [])
            );
            self::assertSame([$case['gate']], $gateMethod->invoke($syncService, $blockedSource, []));
        }
    }

    public function testFailedCollectionPreflightInvalidatesSameDaySessionProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $service = $this->service('2026-07-11 07:30:00');
        $service->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'meituan',
            'store-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            ['status' => 'matched', 'validated_identifier' => 'store-10']
        );

        $service->recordCollectionPreflightFailed(
            $sourceId,
            10,
            'meituan',
            'store-10',
            ['ok' => false, 'status' => 'login_required']
        );

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($config['current_session_verified']);
        self::assertSame('login_required', $config['current_session_status']);
        self::assertFalse($service->isCurrentVerified($source));
    }

    public function testRiskControlBlockInvalidatesProofAndPersistsManualReviewState(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 07:30:00');
        $service->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            ['status' => 'matched', 'validated_identifier' => 'profile-10']
        );

        $service->recordProfileSessionBlocked(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            'anti_bot',
            '2026-07-11T08:00:00+08:00'
        );

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($config['current_session_verified']);
        self::assertSame('anti_bot', $config['current_session_status']);
        self::assertSame('2026-07-11 08:00:00', $config['current_session_backoff_until']);
        self::assertFalse($service->isCurrentVerified($source));
        self::assertSame('unverified', $service->profileReuseState($source)['status']);
    }

    public function testPlatformContractDriftInvalidatesPreviouslyVerifiedSessionProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $service = $this->service('2026-07-11 07:30:00');
        $service->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'meituan',
            'store-10',
            true,
            ['ok' => true, 'status' => 'authorized'],
            ['status' => 'matched', 'validated_identifier' => 'store-10']
        );

        $service->recordProfileSessionBlocked(
            $sourceId,
            10,
            'meituan',
            'store-10',
            'platform_contract_drift'
        );

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($config['current_session_verified']);
        self::assertSame('platform_contract_drift', $config['current_session_status']);
        self::assertFalse($service->isCurrentVerified($source));
        self::assertSame('unverified', $service->profileReuseState($source)['status']);

        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'resolvePlatformProfileStatusCode');
        $method->setAccessible(true);
        self::assertSame(
            'platform_contract_drift',
            $method->invoke($controller, 'store-10', true, $source, [], [])
        );
    }

    public function testFreshCollectionProofReplacesStructuredBlockingAuthStatus(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $blockedService = $this->service('2026-07-11 07:30:00');
        $blockedService->recordProfileSessionBlocked(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            'platform_contract_drift'
        );

        $blockedSource = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($blockedSource);
        self::assertSame('platform_contract_drift', $blockedService->currentSessionBlockingStatus($blockedSource));

        $verifiedService = $this->service('2026-07-11 07:31:00');
        $verifiedService->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'authorized'],
            ['status' => 'matched', 'validated_identifier' => 'profile-10']
        );

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($config['current_session_verified']);
        self::assertSame(['ok' => true, 'status' => 'authorized'], $config['auth_status']);
        self::assertSame('', $verifiedService->currentSessionBlockingStatus($source));
        self::assertTrue($verifiedService->isCurrentVerified($source));
    }

    public function testNewerContractDriftCacheOverridesValidProofButOlderCacheDoesNot(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $clock = static fn(): DateTimeImmutable => $now;
        $service = new OtaProfileSessionProofService(new OtaProfileBindingService(self::$projectRoot), $clock);
        $service->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            ['status' => 'matched', 'validated_identifier' => 'profile-10']
        );
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        self::assertTrue((new OtaProfileSessionProofService())->isCurrentVerified($source));

        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'resolvePlatformProfileStatusCode');
        $method->setAccessible(true);
        $newerCacheStatus = $method->invoke($controller, 'profile-10', true, $source, [
            'status_code' => 'platform_contract_drift',
            'checked_at' => $now->modify('+1 minute')->format('Y-m-d H:i:s'),
        ], []);
        $olderCacheStatus = $method->invoke($controller, 'profile-10', true, $source, [
            'status_code' => 'platform_contract_drift',
            'checked_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s'),
        ], []);

        self::assertSame('platform_contract_drift', $newerCacheStatus);
        self::assertSame('logged_in', $olderCacheStatus);
    }

    public function testProfileReuseWindowKeepsSameDayProofIndependent(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $this->service('2026-07-01 10:00:00')->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            ['status' => 'matched', 'validated_identifier' => 'profile-10']
        );
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);

        $dayZeroService = $this->service('2026-07-01 23:59:59');
        self::assertTrue($dayZeroService->isCurrentVerified($source));
        self::assertSame([
            'status' => 'reusable',
            'is_reusable' => true,
            'age_days' => 0,
            'days_until_forced_login' => 10,
            'warning' => false,
            'reason' => 'profile_proof_reusable',
        ], $dayZeroService->profileReuseState($source));

        $daySixService = $this->service('2026-07-07 10:00:00');
        self::assertFalse($daySixService->isCurrentVerified($source));
        self::assertSame('reusable', $daySixService->profileReuseState($source)['status']);
        self::assertSame(6, $daySixService->profileReuseState($source)['age_days']);
        self::assertSame(4, $daySixService->profileReuseState($source)['days_until_forced_login']);

        $daySeven = $this->service('2026-07-08 10:00:00')->profileReuseState($source);
        self::assertSame('renewal_warning', $daySeven['status']);
        self::assertTrue($daySeven['is_reusable']);
        self::assertSame(7, $daySeven['age_days']);
        self::assertSame(3, $daySeven['days_until_forced_login']);
        self::assertTrue($daySeven['warning']);
        self::assertSame('profile_reauthentication_recommended', $daySeven['reason']);

        $dayNine = $this->service('2026-07-10 10:00:00')->profileReuseState($source);
        self::assertSame('renewal_warning', $dayNine['status']);
        self::assertTrue($dayNine['is_reusable']);
        self::assertSame(9, $dayNine['age_days']);
        self::assertSame(1, $dayNine['days_until_forced_login']);

        $dayTen = $this->service('2026-07-11 10:00:00')->profileReuseState($source);
        self::assertSame('expired', $dayTen['status']);
        self::assertFalse($dayTen['is_reusable']);
        self::assertSame(10, $dayTen['age_days']);
        self::assertSame(0, $dayTen['days_until_forced_login']);
        self::assertFalse($dayTen['warning']);
        self::assertSame('profile_reauthentication_required', $dayTen['reason']);
    }

    public function testProfileReuseExpiresImmediatelyOnStoredAuthenticationFailure(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $this->service('2026-07-01 10:00:00')->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'meituan',
            'store-10',
            true,
            ['ok' => true, 'status' => 'authorized'],
            ['status' => 'matched', 'validated_identifier' => 'store-10']
        );
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'last_sync_status' => 'failed',
            'last_error' => 'Platform returned 401 login_required.',
        ]);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);

        $state = $this->service('2026-07-02 10:00:00')->profileReuseState($source);
        self::assertSame('expired', $state['status']);
        self::assertFalse($state['is_reusable']);
        self::assertSame(1, $state['age_days']);
        self::assertSame('profile_session_explicitly_expired', $state['reason']);
    }

    public function testProfileReuseRejectsTamperedAuthoritativeProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $this->service('2026-07-01 10:00:00')->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            ['status' => 'matched', 'validated_identifier' => 'profile-10']
        );
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $config['current_session_probe_tenant_id'] = 2;
        $config['current_session_probe_profile_key_hash'] = hash('sha256', 'wrong-profile');
        $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);

        self::assertSame([
            'status' => 'unverified',
            'is_reusable' => false,
            'age_days' => null,
            'days_until_forced_login' => 0,
            'warning' => false,
            'reason' => 'profile_proof_unverified',
        ], $this->service('2026-07-02 10:00:00')->profileReuseState($source));
    }

    public function testRejectsFailedProcessOrUnverifiedAuthWithoutWritingProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 09:15:30');
        $before = (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json');

        foreach ([
            [false, ['ok' => true, 'status' => 'logged_in']],
            [true, ['ok' => false, 'status' => 'logged_in']],
            [true, ['ok' => true, 'status' => 'login_required']],
        ] as [$processSucceeded, $authStatus]) {
            try {
                $service->recordCollectionPreflightVerified(
                    $sourceId,
                    10,
                    'ctrip',
                    'profile-10',
                    $processSucceeded,
                    $authStatus,
                    ['status' => 'matched', 'validated_identifier' => 'profile-10']
                );
                self::fail('Unverified login evidence must not produce a current-session proof.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('login evidence is not verified', $e->getMessage());
            }
            self::assertSame($before, (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'));
        }
    }

    public function testRejectsWrongSourceScopeOrProfileWithoutWritingProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 09:15:30');

        Db::name('platform_data_sources')->where('id', $sourceId)->update(['tenant_id' => 2]);
        try {
            $service->recordCollectionPreflightVerified($sourceId, 10, 'ctrip', 'profile-10', true, ['ok' => true, 'status' => 'authorized'], ['status' => 'matched', 'validated_identifier' => 'profile-10']);
            self::fail('Cross-tenant source scope must not produce a proof.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not found', strtolower($e->getMessage()));
        }
        $tenantMismatchConfig = json_decode((string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('current_session_verified', $tenantMismatchConfig);
        Db::name('platform_data_sources')->where('id', $sourceId)->update(['tenant_id' => 1]);

        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => json_encode(['profile_id' => 'another-profile'], JSON_THROW_ON_ERROR),
        ]);
        try {
            $service->recordCollectionPreflightVerified($sourceId, 10, 'ctrip', 'profile-10', true, ['ok' => true, 'status' => 'authorized'], ['status' => 'matched', 'validated_identifier' => 'profile-10']);
            self::fail('A different source Profile must not produce a proof.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Profile scope mismatch', $e->getMessage());
        }

        $stored = json_decode((string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('current_session_verified', $stored);
    }

    public function testRejectsInactiveBindingAndValidatorFailsClosedAfterRevocation(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $service = $this->service('2026-07-11 09:15:30');
        $service->recordCollectionPreflightVerified($sourceId, 10, 'meituan', 'store-10', true, ['ok' => true, 'status' => 'authorized'], ['status' => 'matched', 'validated_identifier' => 'store-10']);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        self::assertTrue($service->isCurrentVerified($source));

        Db::name('ota_profile_bindings')->where('platform', 'meituan')->update(['binding_status' => 'revoked']);
        self::assertFalse($service->isCurrentVerified($source));

        $configBefore = (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json');
        try {
            $service->recordCollectionPreflightVerified($sourceId, 10, 'meituan', 'store-10', true, ['ok' => true, 'status' => 'authorized'], ['status' => 'matched', 'validated_identifier' => 'store-10']);
            self::fail('A revoked binding must not produce or refresh a proof.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not active', $e->getMessage());
        }
        self::assertSame($configBefore, (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'));
    }

    public function testPlatformProfileLoginDelegatesCurrentSessionProofToTheContract(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/PlatformProfileLogin.php');

        self::assertStringContainsString('use app\\service\\OtaProfileSessionProofService;', $source);
        self::assertStringContainsString('->recordProfileLoginVerified(', $source);
        self::assertStringContainsString("(bool)\$result['success']", $source);
        self::assertStringContainsString('$sessionProbe,', $source);
        self::assertStringNotContainsString("\$config['current_session_verified']", $source);
    }

    private function service(string $now): OtaProfileSessionProofService
    {
        $clock = static fn(): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('Asia/Shanghai'));
        return new OtaProfileSessionProofService(new OtaProfileBindingService(self::$projectRoot), $clock);
    }

    private function superAdmin(): object
    {
        return new class {
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
    }

    private function insertBoundSource(int $hotelId, int $tenantId, string $platform, string $profileKey): int
    {
        $config = $platform === 'meituan'
            ? ['store_id' => $profileKey]
            : ['profile_id' => $profileKey];
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'ingestion_method' => 'browser_profile',
            'status' => 'waiting_config',
            'enabled' => 1,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'update_time' => '2026-07-10 12:00:00',
        ]);
        Db::name('ota_profile_bindings')->insert([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'profile_key_hash' => hash('sha256', $profileKey),
            'binding_status' => 'active',
            'create_time' => '2026-07-10 12:00:00',
            'update_time' => '2026-07-10 12:00:00',
        ]);
        return $sourceId;
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT
        )');
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            status TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            config_json TEXT NOT NULL,
            last_sync_status TEXT,
            last_error TEXT,
            update_time TEXT
        )');
        Db::execute('CREATE TABLE ota_profile_bindings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            profile_key_hash TEXT NOT NULL,
            binding_status TEXT NOT NULL,
            bound_by INTEGER,
            revoked_by INTEGER,
            create_time TEXT,
            update_time TEXT
        )');
        Db::execute('CREATE UNIQUE INDEX uq_test_session_binding_key ON ota_profile_bindings(platform, profile_key_hash)');
    }
}
