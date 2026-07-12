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
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaProfileSessionProofConsumerTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private static string $projectRoot = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_profile_session_consumers_' . getmypid() . '.sqlite';
        self::$projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_profile_session_consumers_root_' . getmypid();

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
        $this->createSchema();
        Db::name('hotels')->insert([
            'id' => 10,
            'tenant_id' => 1,
            'name' => 'Tenant one hotel',
        ]);
    }

    public function testDataSyncAcceptsReusableProofButRejectsHistoricalFlagsAndExpiredProof(): void
    {
        $historicalId = $this->insertBoundSource('ctrip', 'browser_profile', 'historical-profile', [
            'manual_login_state_verified' => true,
            'login_state_verified' => true,
            'profile_login_verified' => true,
            'profile_status' => 'logged_in',
            'last_login_verified_at' => '2026-07-10 09:00:00',
        ]);
        $currentId = $this->insertBoundSource('meituan', 'profile_browser', 'current-store');
        $currentSource = $this->recordCurrentProof($currentId, 'meituan', 'current-store');
        $reusableId = $this->insertBoundSource('ctrip', 'browser_profile', 'reusable-profile');
        $reusableSource = $this->recordProofDaysAgo($reusableId, 'ctrip', 'reusable-profile', 1);
        $warningId = $this->insertBoundSource('ctrip', 'browser_profile', 'warning-profile');
        $warningSource = $this->recordProofDaysAgo($warningId, 'ctrip', 'warning-profile', 8);
        $expiredId = $this->insertBoundSource('ctrip', 'browser_profile', 'expired-profile');
        $expiredSource = $this->recordProofDaysAgo($expiredId, 'ctrip', 'expired-profile', 10);
        $historicalSource = $this->loadSource($historicalId);

        $service = new PlatformDataSyncService();
        $method = new ReflectionMethod($service, 'browserProfileBackgroundSyncLoginMissingRequirements');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke($service, $historicalSource, ['interactive_browser' => false]));
        self::assertSame([], $method->invoke($service, $historicalSource, ['interactive_browser' => true]));
        self::assertSame([], $method->invoke($service, $currentSource, ['interactive_browser' => false]));
        self::assertSame([], $method->invoke($service, $reusableSource, ['interactive_browser' => false]));
        self::assertSame([], $method->invoke($service, $warningSource, ['interactive_browser' => false]));
        self::assertSame([], $method->invoke($service, $expiredSource, ['interactive_browser' => false]));
        self::assertSame([], $method->invoke($service, [
            'id' => 999,
            'tenant_id' => 1,
            'system_hotel_id' => 10,
            'platform' => 'custom',
            'ingestion_method' => 'manual',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => '{}',
        ], []));
    }

    public function testCookieProfileConsumerDoesNotRequireSameDayProofBeforeAttemptingCollection(): void
    {
        $historicalId = $this->insertBoundSource('ctrip', 'browser_profile', 'cookie-profile', [
            'manual_login_state_verified' => true,
            'login_state_verified' => true,
            'profile_login_verified' => true,
            'profile_status' => 'logged_in',
            'last_login_verified_at' => '2026-07-10 09:00:00',
        ]);
        $currentId = $this->insertBoundSource('meituan', 'browser_profile', 'cookie-store');
        $currentSource = $this->recordCurrentProof($currentId, 'meituan', 'cookie-store');
        $reusableId = $this->insertBoundSource('ctrip', 'browser_profile', 'cookie-reusable-profile');
        $reusableSource = $this->recordProofDaysAgo($reusableId, 'ctrip', 'cookie-reusable-profile', 1);
        $historicalSource = $this->loadSource($historicalId);
        $controller = $this->controller();

        $missing = $this->invoke($controller, 'profileCookieSourceLoginMissingRequirements', [$historicalSource]);
        self::assertSame([], $missing);
        self::assertSame([], $this->invoke($controller, 'profileCookieSourceLoginMissingRequirements', [$currentSource]));
        self::assertSame([], $this->invoke($controller, 'profileCookieSourceLoginMissingRequirements', [$reusableSource]));
    }

    public function testPhase1EmployeeConsoleUsesCurrentProofInsteadOfHistoricalLoginFlags(): void
    {
        $historicalSourceIds = [];
        foreach ([
            ['manual_login_state_verified' => true],
            ['login_state_verified' => true],
            ['profile_login_verified' => true],
            [
                'manual_login_state_verified' => true,
                'login_state_verified' => true,
                'profile_login_verified' => true,
                'profile_status' => 'logged_in',
            ],
        ] as $index => $historicalConfig) {
            $historicalSourceIds[] = $this->insertBoundSource(
                'ctrip',
                'browser_profile',
                'phase1-historical-' . $index,
                $historicalConfig
            );
        }
        $currentSourceId = $this->insertBoundSource(
            'ctrip',
            'profile_browser',
            'phase1-current-profile'
        );
        $currentSource = $this->recordCurrentProof(
            $currentSourceId,
            'ctrip',
            'phase1-current-profile'
        );
        $controller = $this->controller();

        foreach ($historicalSourceIds as $historicalSourceId) {
            $historicalSource = $this->loadSource($historicalSourceId);
            $historicalConfig = json_decode(
                (string)$historicalSource['config_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame(
                'manual_login_state_unverified',
                $this->invoke($controller, 'phase1TrafficSourceIssueCode', [
                    $historicalSource,
                    $historicalConfig,
                ])
            );
        }

        $currentConfig = json_decode(
            (string)$currentSource['config_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            'no_target_date_rows_after_success',
            $this->invoke($controller, 'phase1TrafficSourceIssueCode', [
                $currentSource,
                $currentConfig,
            ])
        );
    }

    public function testAutoFetchCollectableSourcesUseReusableProofAndSupportBothProfileMethods(): void
    {
        $historicalId = $this->insertBoundSource('ctrip', 'browser_profile', 'old-profile', [
            'manual_login_state_verified' => true,
            'profile_status' => 'logged_in',
            'last_login_verified_at' => '2026-07-10 09:00:00',
        ]);
        $currentId = $this->insertBoundSource('ctrip', 'profile_browser', 'current-profile');
        $this->recordCurrentProof($currentId, 'ctrip', 'current-profile');
        $reusableId = $this->insertBoundSource('ctrip', 'browser_profile', 'reusable-profile');
        $this->recordProofDaysAgo($reusableId, 'ctrip', 'reusable-profile', 1);
        $warningId = $this->insertBoundSource('ctrip', 'browser_profile', 'warning-profile');
        $this->recordProofDaysAgo($warningId, 'ctrip', 'warning-profile', 8);
        $expiredId = $this->insertBoundSource('ctrip', 'browser_profile', 'expired-profile');
        $this->recordProofDaysAgo($expiredId, 'ctrip', 'expired-profile', 10);
        $controller = $this->controller();
        $this->invoke($controller, 'clearAutoFetchLightProfileSourcesCache', [10, 'ctrip']);

        $listed = $this->invoke($controller, 'listEnabledBrowserProfileDataSources', [10, 'ctrip']);
        $listedMethods = array_values(array_unique(array_column($listed, 'ingestion_method')));
        sort($listedMethods);
        self::assertSame(
            ['browser_profile', 'profile_browser'],
            $listedMethods
        );
        foreach ($listed as $source) {
            self::assertArrayHasKey('tenant_id', $source);
        }

        $filtered = $this->invoke($controller, 'filterCollectableBrowserProfileDataSources', [$listed, 'ctrip']);
        $filteredIds = array_column($filtered, 'id');
        sort($filteredIds);
        $expectedIds = [$historicalId, $currentId, $reusableId, $warningId, $expiredId];
        sort($expectedIds);
        self::assertSame($expectedIds, $filteredIds);
    }

    public function testAutoFetchStatusAndProfileCookieReadinessIgnoreCacheAndHistoricalFlags(): void
    {
        $historicalId = $this->insertBoundSource('meituan', 'browser_profile', 'old-store', [
            'manual_login_state_verified' => true,
            'login_state_verified' => true,
            'profile_login_verified' => true,
            'profile_status' => 'logged_in',
            'last_login_verified_at' => '2026-07-10 09:00:00',
        ]);
        $currentId = $this->insertBoundSource('meituan', 'browser_profile', 'current-store');
        $currentSource = $this->recordCurrentProof($currentId, 'meituan', 'current-store');
        $reusableId = $this->insertBoundSource('meituan', 'browser_profile', 'reusable-store');
        $reusableSource = $this->recordProofDaysAgo($reusableId, 'meituan', 'reusable-store', 1);
        $warningId = $this->insertBoundSource('meituan', 'browser_profile', 'warning-store');
        $warningSource = $this->recordProofDaysAgo($warningId, 'meituan', 'warning-store', 8);
        $expiredId = $this->insertBoundSource('meituan', 'browser_profile', 'expired-store');
        $expiredSource = $this->recordProofDaysAgo($expiredId, 'meituan', 'expired-store', 10);
        $historicalSource = $this->loadSource($historicalId);
        $controller = $this->controller();

        self::assertSame('profile_reusable', $this->invoke($controller, 'resolvePlatformProfileStatusCode', [
            'old-store',
            true,
            $historicalSource,
            ['status_code' => 'logged_in', 'auth_status' => ['ok' => true]],
            json_decode((string)$historicalSource['config_json'], true, 512, JSON_THROW_ON_ERROR),
        ]));
        self::assertSame('logged_in', $this->invoke($controller, 'resolvePlatformProfileStatusCode', [
            'current-store',
            true,
            $currentSource,
            [],
            [],
        ]));
        self::assertSame('profile_reusable', $this->invoke($controller, 'resolvePlatformProfileStatusCode', [
            'reusable-store',
            true,
            $reusableSource,
            [],
            [],
        ]));
        self::assertSame('renewal_warning', $this->invoke($controller, 'resolvePlatformProfileStatusCode', [
            'warning-store',
            true,
            $warningSource,
            [],
            [],
        ]));
        self::assertSame('profile_reusable', $this->invoke($controller, 'resolvePlatformProfileStatusCode', [
            'expired-store',
            true,
            $expiredSource,
            [],
            [],
        ]));

        $historicalStatus = $this->invoke($controller, 'meituanAutoFetchConfigStatus', [[
            'partner_id' => 'partner-10',
            'poi_id' => 'poi-10',
            'store_id' => 'old-store',
            'profile_cookie_source' => true,
            'manual_login_state_verified' => true,
            'profile_status' => 'logged_in',
            'last_login_verified_at' => '2026-07-10 09:00:00',
        ], 10]);
        self::assertTrue($historicalStatus['has_profile_cookie_source']);
        self::assertSame([], $historicalStatus['profile_cookie_missing_requirements']);

        $currentStatus = $this->invoke($controller, 'meituanAutoFetchConfigStatus', [[
            'partner_id' => 'partner-10',
            'poi_id' => 'poi-10',
            'store_id' => 'current-store',
            'profile_cookie_source' => true,
        ], 10]);
        self::assertTrue($currentStatus['has_profile_cookie_source']);
    }

    public function testSyncDiagnosticsAndQualitySnapshotUseTheCurrentProofContract(): void
    {
        $historicalId = $this->insertBoundSource('ctrip', 'browser_profile', 'quality-old-profile', [
            'ota_hotel_id' => 'ctrip-hotel-10',
            'manual_login_state_verified' => true,
            'profile_status' => 'logged_in',
            'last_login_verified_at' => '2026-07-10 09:00:00',
        ]);
        $currentId = $this->insertBoundSource('ctrip', 'browser_profile', 'quality-current-profile', [
            'ota_hotel_id' => 'ctrip-hotel-10',
        ]);
        $reusableId = $this->insertBoundSource('ctrip', 'browser_profile', 'quality-reusable-profile', [
            'ota_hotel_id' => 'ctrip-hotel-10',
        ]);
        $historicalSource = $this->loadSource($historicalId);
        $currentSource = $this->recordCurrentProof($currentId, 'ctrip', 'quality-current-profile');
        $reusableSource = $this->recordProofDaysAgo($reusableId, 'ctrip', 'quality-reusable-profile', 1);
        $service = new PlatformDataSyncService();

        $diagnosticsMethod = new ReflectionMethod($service, 'buildSyncDiagnostics');
        $diagnosticsMethod->setAccessible(true);
        $diagnostics = $diagnosticsMethod->invoke($service, [[
            'data_date' => '2026-07-10',
            'data_type' => 'traffic',
            'list_exposure' => 100,
            'detail_exposure' => 20,
            'flow_rate' => 0.2,
        ]], 1, $historicalSource, [
            'trigger_type' => 'daily_profile_reuse',
            'data_date' => '2026-07-10',
            'capture_sections' => 'traffic',
        ], ['data_date' => '2026-07-10'], 'success', 'ok');
        self::assertSame('blocked', $diagnostics['p0_status']);
        self::assertContains('current_session_verified', $diagnostics['missing_inputs']);
        self::assertSame('current_session_not_verified', $diagnostics['operator_message']);

        $reusableDiagnostics = $diagnosticsMethod->invoke($service, [[
            'data_date' => '2026-07-10',
            'data_type' => 'traffic',
            'list_exposure' => 100,
            'detail_exposure' => 20,
            'flow_rate' => 0.2,
        ]], 1, $reusableSource, [
            'trigger_type' => 'daily_profile_reuse',
            'data_date' => '2026-07-10',
            'capture_sections' => 'traffic',
        ], ['data_date' => '2026-07-10'], 'success', 'ok');
        self::assertSame('blocked', $reusableDiagnostics['p0_status']);
        self::assertContains('current_session_verified', $reusableDiagnostics['missing_inputs']);
        self::assertSame('current_session_not_verified', $reusableDiagnostics['operator_message']);

        $qualityMethod = new ReflectionMethod($service, 'buildSyncTaskCollectionQualitySnapshot');
        $qualityMethod->setAccessible(true);
        $qualityInput = [
            'target_date' => '2026-07-10',
            'p0_status' => 'ready',
            'target_date_rows' => 1,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'missing_inputs' => ['current_session_verified'],
        ];
        $historicalQuality = $qualityMethod->invoke(
            $service,
            'success',
            $historicalSource,
            $qualityInput,
            1,
            1,
            date('Y-m-d H:i:s')
        );
        $currentQuality = $qualityMethod->invoke(
            $service,
            'success',
            $currentSource,
            array_replace($qualityInput, ['missing_inputs' => []]),
            1,
            1,
            date('Y-m-d H:i:s')
        );
        self::assertSame('unverified', $historicalQuality['primary_quality_state']);
        self::assertContains('current_session_verified', $historicalQuality['quality_flags']);
        self::assertContains('platform_session_not_verified', $historicalQuality['quality_flags']);
        self::assertSame('available', $currentQuality['primary_quality_state']);
    }

    public function testDirectBrowserAutoFetchAttemptsProfileDirectoryWithoutCurrentProof(): void
    {
        $this->insertBoundSource('ctrip', 'browser_profile', 'direct-old-profile', [
            'manual_login_state_verified' => true,
            'profile_status' => 'logged_in',
            'last_login_verified_at' => '2026-07-10 09:00:00',
        ]);
        $this->insertBoundSource('meituan', 'browser_profile', 'direct-old-store', [
            'manual_login_state_verified' => true,
            'profile_status' => 'logged_in',
            'last_login_verified_at' => '2026-07-10 09:00:00',
        ]);
        $expiredCtripId = $this->insertBoundSource('ctrip', 'browser_profile', 'direct-expired-profile');
        $this->recordProofDaysAgo($expiredCtripId, 'ctrip', 'direct-expired-profile', 10);
        $expiredMeituanId = $this->insertBoundSource('meituan', 'browser_profile', 'direct-expired-store');
        $this->recordProofDaysAgo($expiredMeituanId, 'meituan', 'direct-expired-store', 10);
        $controller = $this->controller();

        $ctrip = $this->invoke($controller, 'executeCtripBrowserProfileAutoFetch', [[
            'profile_id' => 'direct-old-profile',
        ], 10, '2026-07-10', false, []]);
        $meituan = $this->invoke($controller, 'executeMeituanBrowserProfileAutoFetch', [[
            'store_id' => 'direct-old-store',
        ], 10, '2026-07-10', false, []]);
        $expiredCtrip = $this->invoke($controller, 'executeCtripBrowserProfileAutoFetch', [[
            'profile_id' => 'direct-expired-profile',
        ], 10, '2026-07-10', false, []]);
        $expiredMeituan = $this->invoke($controller, 'executeMeituanBrowserProfileAutoFetch', [[
            'store_id' => 'direct-expired-store',
        ], 10, '2026-07-10', false, []]);

        self::assertFalse($ctrip['success']);
        self::assertStringContainsString('storage/ctrip_profile_', $ctrip['message']);
        self::assertFalse($meituan['success']);
        self::assertStringContainsString('Profile', $meituan['message']);
        self::assertFalse($expiredCtrip['success']);
        self::assertStringContainsString('storage/ctrip_profile_', $expiredCtrip['message']);
        self::assertFalse($expiredMeituan['success']);
        self::assertStringContainsString('Profile', $expiredMeituan['message']);
    }

    public function testDataSyncSurfacesStableProfileReuseFailureCodes(): void
    {
        $service = new PlatformDataSyncService();
        $method = new ReflectionMethod($service, 'safeOtaExecutionFailureCode');
        $method->setAccessible(true);

        self::assertSame(
            'profile_session_unverified',
            $method->invoke($service, new RuntimeException(
                'browser_profile collector reported profile_session_unverified.'
            ))
        );
        self::assertSame(
            'profile_session_expired',
            $method->invoke($service, new RuntimeException(
                'browser_profile synchronization requires profile_session_expired before capture.'
            ))
        );
        self::assertSame(
            'current_session_not_verified',
            $method->invoke($service, new RuntimeException(
                'browser_profile synchronization requires current_session_verified before capture.'
            ))
        );
    }

    private function recordCurrentProof(int $sourceId, string $platform, string $profileKey): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        return $this->recordProofAt($sourceId, $platform, $profileKey, $now);
    }

    private function recordProofDaysAgo(int $sourceId, string $platform, string $profileKey, int $daysAgo): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        return $this->recordProofAt($sourceId, $platform, $profileKey, $now->modify('-' . $daysAgo . ' days'));
    }

    private function recordProofAt(
        int $sourceId,
        string $platform,
        string $profileKey,
        DateTimeImmutable $proofTime
    ): array {
        $service = new OtaProfileSessionProofService(
            new OtaProfileBindingService(self::$projectRoot),
            static fn(): DateTimeImmutable => $proofTime
        );
        $service->recordVerified(
            $sourceId,
            10,
            $platform,
            $profileKey,
            true,
            ['ok' => true, 'status' => 'logged_in']
        );
        return $this->loadSource($sourceId);
    }

    /** @param array<string, mixed> $extraConfig */
    private function insertBoundSource(
        string $platform,
        string $ingestionMethod,
        string $profileKey,
        array $extraConfig = []
    ): int {
        $config = $platform === 'meituan'
            ? ['store_id' => $profileKey, 'profile_binding_key' => $profileKey]
            : ['profile_id' => $profileKey, 'profile_binding_key' => $profileKey];
        $config = array_merge($config, $extraConfig);
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 1,
            'system_hotel_id' => 10,
            'name' => $platform . ' source',
            'platform' => $platform,
            'data_type' => 'traffic',
            'ingestion_method' => $ingestionMethod,
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ota_profile_bindings')->insert([
            'tenant_id' => 1,
            'system_hotel_id' => 10,
            'platform' => $platform,
            'profile_key_hash' => hash('sha256', $profileKey),
            'binding_status' => 'active',
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        return $sourceId;
    }

    /** @return array<string, mixed> */
    private function loadSource(int $sourceId): array
    {
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        return $source;
    }

    private function controller(): OnlineData
    {
        return (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
    }

    private function invoke(object $object, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
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
            name TEXT,
            platform TEXT NOT NULL,
            data_type TEXT,
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
        Db::execute('CREATE UNIQUE INDEX uq_consumer_binding_key ON ota_profile_bindings(platform, profile_key_hash)');
    }
}
