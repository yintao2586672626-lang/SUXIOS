<?php
declare(strict_types=1);

namespace Tests;

use app\command\AutoFetchOnlineData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class AutoFetchPlatformIsolationBehaviorTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'auto_fetch_platform_isolation_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute(
            'CREATE TABLE platform_data_sources ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, platform TEXT, '
            . 'data_type TEXT, status TEXT, last_sync_time DATETIME, system_hotel_id INTEGER, '
            . 'ingestion_method TEXT, enabled INTEGER, config_json TEXT)'
        );
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove platform-isolation SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('platform_data_sources')->delete(true);
        foreach ([25 => 'ctrip', 68 => 'meituan'] as $sourceId => $platform) {
            Db::name('platform_data_sources')->insert([
                'id' => $sourceId,
                'tenant_id' => 80,
                'user_id' => 1,
                'platform' => $platform,
                'data_type' => 'traffic',
                'status' => 'ready',
                'system_hotel_id' => 80,
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'config_json' => json_encode($platform === 'meituan' ? [
                    'store_id' => 'meituan-store-h80',
                    'poi_id' => 'meituan-poi-h80',
                ] : [
                    'platform_hotel_id' => 'ctrip-h80',
                ], JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function failureCases(): iterable
    {
        foreach (['missing', 'planner', 'sync', 'readback'] as $stage) {
            yield "ctrip {$stage}" => ['ctrip', $stage];
            yield "meituan {$stage}" => ['meituan', $stage];
        }
    }

    #[DataProvider('failureCases')]
    public function testOnePlatformFailureStillRunsAndVerifiesTheOtherPlatform(
        string $failedPlatform,
        string $failureStage
    ): void {
        $failedSourceId = $failedPlatform === 'ctrip' ? 25 : 68;
        $healthyPlatform = $failedPlatform === 'ctrip' ? 'meituan' : 'ctrip';
        $healthySourceId = $healthyPlatform === 'ctrip' ? 25 : 68;
        if ($failureStage === 'missing') {
            Db::name('platform_data_sources')->where('id', $failedSourceId)->delete();
        }

        $command = new AutoFetchPlatformIsolationCommand();
        $command->failedPlatform = $failedPlatform;
        $command->failureStage = $failureStage;
        $dispatcherRunId = '12345678-1234-4234-8234-123456789abc';
        (new \ReflectionProperty(AutoFetchOnlineData::class, 'dispatcherRunId'))->setValue(
            $command,
            $dispatcherRunId
        );
        $method = new \ReflectionMethod($command, 'syncBrowserProfileSources');

        $result = $method->invoke(
            $command,
            80,
            '2026-08-08',
            true,
            'historical_daily',
            '2026-08-09 08:30:00',
            1,
            ['ctrip', 'meituan'],
            [25, 68],
            false
        );

        self::assertTrue($result['attempted']);
        self::assertFalse($result['success']);
        self::assertSame('partial_success', $result['status']);
        self::assertContains($failedPlatform, $result['failed_platforms']);
        self::assertSame([$healthyPlatform], $result['successful_platforms']);
        self::assertSame(
            $failureStage === 'readback' ? 2 : 1,
            $result['saved_count'],
            'Rows that were already saved remain factual even when exact readback fails.'
        );
        self::assertContains($healthySourceId, $command->syncCalls);
        self::assertContains($healthyPlatform, $command->readbackCalls);
        $healthyOptions = $command->syncOptionsByPlatform[$healthyPlatform] ?? [];
        self::assertTrue($healthyOptions['require_current_run_session_probe'] ?? false);
        self::assertFalse($healthyOptions['require_collector_binding'] ?? true);
        self::assertSame([], $healthyOptions['required_collector_binding'] ?? null);
        $expectedPlatformHotelIds = $healthyPlatform === 'meituan'
            ? ['meituan-store-h80', 'meituan-poi-h80']
            : ['ctrip-h80'];
        self::assertSame($expectedPlatformHotelIds[0], $healthyOptions['required_platform_hotel_id'] ?? '');
        self::assertSame($expectedPlatformHotelIds, $healthyOptions['required_platform_hotel_ids'] ?? []);
        self::assertSame(600, $healthyOptions['timeout_seconds'] ?? null);
        self::assertSame($dispatcherRunId, $healthyOptions['dispatcher_run_id'] ?? '');

        if ($failureStage === 'missing') {
            self::assertSame([$failedSourceId], $result['missing_source_ids']);
            self::assertNotContains($failedSourceId, $command->syncCalls);
        } elseif ($failureStage === 'planner') {
            self::assertNotContains($failedSourceId, $command->syncCalls);
        } else {
            self::assertContains($failedSourceId, $command->syncCalls);
        }

        $platformResults = [];
        foreach ($result['platform_results'] as $platformResult) {
            if (is_array($platformResult)
                && in_array((string)($platformResult['platform'] ?? ''), ['ctrip', 'meituan'], true)
            ) {
                $platformResults[(string)$platformResult['platform']] = $platformResult;
            }
        }
        self::assertTrue($platformResults[$healthyPlatform]['success']);
        self::assertFalse($platformResults[$failedPlatform]['success']);
        self::assertTrue($platformResults[$healthyPlatform]['run_readback']['readback_verified']);
        if ($failureStage === 'readback') {
            self::assertSame('ordered_profile_readback_failed', $platformResults[$failedPlatform]['message']);
            self::assertSame([], $platformResults[$failedPlatform]['run_readback']);
        }
    }

    public function testManualHistoricalProfileRunDoesNotReceiveNaturalCaptureTimeout(): void
    {
        $command = new AutoFetchPlatformIsolationCommand();
        $method = new \ReflectionMethod($command, 'syncBrowserProfileSources');

        $result = $method->invoke(
            $command,
            80,
            '2026-08-08',
            true,
            'historical_daily',
            '2026-08-09 08:30:00',
            1,
            ['ctrip'],
            [25],
            false
        );

        self::assertTrue($result['success']);
        $options = $command->syncOptionsByPlatform['ctrip'] ?? [];
        self::assertArrayNotHasKey('timeout_seconds', $options);
        self::assertArrayNotHasKey('dispatcher_run_id', $options);
    }
}

final class AutoFetchPlatformIsolationCommand extends AutoFetchOnlineData
{
    public string $failedPlatform = '';
    public string $failureStage = '';

    /** @var array<int, string> */
    public array $plannerCalls = [];

    /** @var array<int, int> */
    public array $syncCalls = [];

    /** @var array<int, string> */
    public array $readbackCalls = [];

    /** @var array<string, array<string, mixed>> */
    public array $syncOptionsByPlatform = [];

    /** @return array{plan: array<string, mixed>, reused_run_readback: array<string, mixed>} */
    protected function orderedBrowserProfileExecution(
        array $source,
        string $dataDate,
        string $dataPeriod,
        bool $forceRerun = false
    ): array {
        $platform = (string)$source['platform'];
        $this->plannerCalls[] = $platform;
        if ($platform === $this->failedPlatform && $this->failureStage === 'planner') {
            throw new RuntimeException('injected planner failure');
        }
        return [
            'plan' => [
                'sections' => ['traffic_report'],
                'stage' => 'targeted_gap',
            ],
            'reused_run_readback' => [],
        ];
    }

    protected function syncBrowserProfileSource($user, int $sourceId, array $options): array
    {
        $platform = $sourceId === 25 ? 'ctrip' : 'meituan';
        $this->syncCalls[] = $sourceId;
        $this->syncOptionsByPlatform[$platform] = $options;
        if ($platform === $this->failedPlatform && $this->failureStage === 'sync') {
            throw new RuntimeException('injected sync failure');
        }
        return [
            'status' => 'success',
            'message' => 'platform_data_synchronized',
            'saved_count' => 1,
            'timing' => ['total_elapsed_ms' => 1],
            'run_readback' => [
                'sync_task_id' => 1000 + $sourceId,
                'data_source_id' => $sourceId,
                'system_hotel_id' => 80,
                'platform' => $platform,
                'target_date' => '2026-08-08',
                'data_period' => 'historical_daily',
                'readback_verified' => true,
                'p0_status' => 'ready',
                'row_ids' => [2000 + $sourceId],
                'source_trace_ids' => ['trace-' . $sourceId],
            ],
        ];
    }

    protected function orderedCompositeReadbackVerified(
        array $source,
        string $dataDate,
        string $dataPeriod,
        array $readback
    ): bool {
        $platform = (string)$source['platform'];
        $this->readbackCalls[] = $platform;
        if ($platform === $this->failedPlatform && $this->failureStage === 'readback') {
            throw new RuntimeException('injected readback failure');
        }
        return true;
    }

    protected function orderedHistoricalCoreReadbackVerified(
        array $source,
        string $dataDate,
        string $dataPeriod,
        array $readback
    ): bool {
        return true;
    }

    protected function updateCtripLatestFetchStatus(
        ?int $hotelId,
        string $fetchedAt,
        string $dataDate,
        int $savedCount
    ): void {
        // The behavior test verifies platform isolation, not cache persistence.
    }
}
