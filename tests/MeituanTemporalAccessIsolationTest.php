<?php
declare(strict_types=1);

namespace Tests;

use app\model\User;
use app\service\MeituanTemporalService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class MeituanTemporalAccessIsolationTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'meituan_temporal_access_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');

        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);

        Db::execute(
            'CREATE TABLE platform_data_sources ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'tenant_id INTEGER NOT NULL, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'platform TEXT NOT NULL, '
            . 'data_type TEXT NOT NULL, '
            . 'ingestion_method TEXT NOT NULL, '
            . 'enabled INTEGER NOT NULL DEFAULT 1, '
            . 'secret_json TEXT NULL'
            . ')'
        );
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'tenant_id INTEGER NULL, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'source TEXT NOT NULL, '
            . 'data_type TEXT NOT NULL, '
            . 'data_date TEXT NOT NULL, '
            . 'compare_type TEXT NULL, '
            . 'dimension TEXT NULL, '
            . 'ingestion_method TEXT NULL, '
            . 'data_source_id INTEGER NULL, '
            . 'sync_task_id INTEGER NULL, '
            . 'source_trace_id TEXT NULL, '
            . 'snapshot_time TEXT NULL, '
            . 'readback_verified INTEGER NOT NULL DEFAULT 0, '
            . 'amount REAL NULL, '
            . 'quantity INTEGER NULL, '
            . 'book_order_num INTEGER NULL, '
            . 'data_value REAL NULL, '
            . 'list_exposure INTEGER NULL, '
            . 'detail_exposure INTEGER NULL, '
            . 'flow_rate REAL NULL, '
            . 'order_filling_num INTEGER NULL, '
            . 'order_submit_num INTEGER NULL, '
            . 'raw_data TEXT NULL'
            . ')'
        );
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 9]);
        Db::name('online_daily_data')->insert([
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-29',
            'compare_type' => 'self',
            'data_source_id' => 18,
            'sync_task_id' => 701,
            'source_trace_id' => 'isolated-trace',
            'snapshot_time' => '2026-07-29 18:00:00',
            'readback_verified' => 1,
            'amount' => 2026.78,
            'quantity' => 2,
            'book_order_num' => 1,
            'data_value' => 1013.39,
            'list_exposure' => 81,
            'detail_exposure' => 77,
            'flow_rate' => 1.3,
            'order_submit_num' => 1,
            'raw_data' => json_encode([
                'row' => [
                    'lead_price' => 868,
                    'sales_amount' => 2026.78,
                    'sales_room_nights' => 2,
                    'sales_avg_price' => 1013.39,
                    'exposure_users' => 81,
                    'detail_visitors' => 77,
                    'paid_order_count' => 1,
                    'browse_to_pay_rate' => 1.3,
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    public function testSummaryRejectsUnauthorizedHotelBeforeReadingStoredMetrics(): void
    {
        $user = new class(['tenant_id' => 9]) extends User {
            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [];
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return false;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);

        (new MeituanTemporalService())->summary($user, 80, '2026-07-29');
    }

    public function testSummaryDoesNotExposeWrongTenantSnapshotForOwnedHotel(): void
    {
        $wrongId = (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 10,
            'system_hotel_id' => 80,
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-29',
            'compare_type' => 'self',
            'data_source_id' => 18,
            'sync_task_id' => 702,
            'source_trace_id' => 'wrong-tenant-trace',
            'snapshot_time' => '2026-07-29 19:00:00',
            'readback_verified' => 1,
            'amount' => 9999,
            'raw_data' => '{}',
        ]);
        $missingTenantId = (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => null,
            'system_hotel_id' => 80,
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-29',
            'compare_type' => 'self',
            'data_source_id' => 18,
            'sync_task_id' => 703,
            'source_trace_id' => 'missing-tenant-trace',
            'snapshot_time' => '2026-07-29 20:00:00',
            'readback_verified' => 1,
            'amount' => 8888,
            'raw_data' => '{}',
        ]);
        $wrongYesterdayId = (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 10,
            'system_hotel_id' => 80,
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-28',
            'compare_type' => 'self',
            'data_source_id' => 18,
            'sync_task_id' => 704,
            'source_trace_id' => 'wrong-yesterday-trace',
            'snapshot_time' => '2026-07-29 19:00:00',
            'readback_verified' => 1,
            'amount' => 7777,
            'raw_data' => '{}',
        ]);
        $wrongFutureId = (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 10,
            'system_hotel_id' => 80,
            'source' => 'meituan',
            'data_type' => 'traffic_forecast',
            'data_date' => '2026-07-30',
            'compare_type' => 'forecast',
            'data_source_id' => 18,
            'sync_task_id' => 705,
            'source_trace_id' => 'wrong-future-trace',
            'snapshot_time' => '2026-07-29 19:00:00',
            'readback_verified' => 1,
            'raw_data' => '{"row":{"forecast_type":"pv"}}',
        ]);
        $user = new class(['tenant_id' => 9]) extends User {
            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [80];
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 80 && $permission === 'can_view_online_data';
            }
        };
        try {
            $summary = (new MeituanTemporalService())->summary($user, 80, '2026-07-29');
            self::assertSame('meituan', $summary['platform']);
            self::assertSame('2026-07-29', $summary['today']['target_date']);
            self::assertSame('blocked', $summary['source_state']['status']);
            self::assertSame('unverified', $summary['today']['status']);
            self::assertCount(1, $summary['today']['snapshots']);
            self::assertSame(701, (int)$summary['today']['snapshots'][0]['sync_task_id']);
            self::assertNull($summary['yesterday']['captured_at']);
            self::assertSame([], $summary['future']['snapshots']);

            $superUser = new class(['tenant_id' => 9]) extends User {
                public function isSuperAdmin(): bool
                {
                    return true;
                }

                public function hasHotelPermission(int $hotelId, string $permission): bool
                {
                    return $hotelId === 80 && $permission === 'can_view_online_data';
                }
            };
            $superSummary = (new MeituanTemporalService())->summary($superUser, 80, '2026-07-29');
            self::assertCount(1, $superSummary['today']['snapshots']);
            self::assertNull($superSummary['yesterday']['captured_at']);
            self::assertSame([], $superSummary['future']['snapshots']);
        } finally {
            Db::name('online_daily_data')->whereIn('id', [
                $wrongId, $missingTenantId, $wrongYesterdayId, $wrongFutureId,
            ])->delete();
        }
    }

    public function testHistoricalSummaryKeepsSameDayCaptureWhenNextDayBackfillExists(): void
    {
        $lateId = (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-29',
            'compare_type' => 'self',
            'data_source_id' => 18,
            'sync_task_id' => 706,
            'source_trace_id' => 'later-capture-trace',
            'snapshot_time' => '2026-07-30 10:00:00',
            'readback_verified' => 1,
            'amount' => 9999,
            'raw_data' => '{}',
        ]);
        $user = new class(['tenant_id' => 9]) extends User {
            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [80];
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 80 && $permission === 'can_view_online_data';
            }
        };
        try {
            $summary = (new MeituanTemporalService())->summary($user, 80, '2026-07-29');
            self::assertSame('2026-07-29 18:00:00', $summary['today']['captured_at']);
            self::assertCount(1, $summary['today']['snapshots']);
            self::assertSame(701, (int)$summary['today']['snapshots'][0]['sync_task_id']);
        } finally {
            Db::name('online_daily_data')->where('id', $lateId)->delete();
        }
    }

    public function testMissingProfileStillBlocksRefreshWithoutChangingStoredRows(): void
    {
        $user = new class(['tenant_id' => 9]) extends User {
            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 80 && $permission === 'can_fetch_online_data';
            }
        };
        $countBefore = Db::name('online_daily_data')->count();
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

        $result = (new MeituanTemporalService())->refresh($user, 80, $today);

        self::assertSame('blocked', $result['status']);
        self::assertSame('meituan_profile_source_missing', $result['reason_code']);
        self::assertSame([], $result['tasks']);
        self::assertSame($countBefore, Db::name('online_daily_data')->count());
    }

    public function testLegacyDailyDataWithoutTenantColumnStillReturnsHotelScopedSnapshot(): void
    {
        Db::execute('ALTER TABLE online_daily_data DROP COLUMN tenant_id');
        Db::connect(null, true);
        $user = new class(['tenant_id' => 9]) extends User {
            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [80];
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 80 && $permission === 'can_view_online_data';
            }
        };
        try {
            $summary = (new MeituanTemporalService())->summary($user, 80, '2026-07-29');
            self::assertCount(1, $summary['today']['snapshots']);
            self::assertSame(701, (int)$summary['today']['snapshots'][0]['sync_task_id']);
        } finally {
            Db::execute('ALTER TABLE online_daily_data ADD COLUMN tenant_id INTEGER');
            Db::name('online_daily_data')->where('system_hotel_id', 80)->update(['tenant_id' => 9]);
            Db::connect(null, true);
        }
    }

    public function testYesterdayRefreshGateIgnoresCompleteWrongTenantSnapshot(): void
    {
        $capturedAt = '2026-07-30 09:05:00';
        $rows = [];
        $rows[] = $this->verifiedRefreshRow(10, 801, 'business', '2026-07-29', $capturedAt, [
            'amount' => 2026.78,
            'quantity' => 2,
            'book_order_num' => 1,
            'data_value' => 1013.39,
        ], [
            'sales_amount' => 2026.78,
            'sales_room_nights' => 2,
            'sales_avg_price' => 1013.39,
        ], ['sales_amount', 'sales_room_nights', 'sales_avg_price']);
        foreach ([
            'overall exposure' => 1567,
            'organic exposure' => 271,
            'ad exposure' => 1296,
        ] as $label => $value) {
            $rows[] = $this->verifiedRefreshRow(10, 801, 'traffic_analysis', '2026-07-29', $capturedAt, [
                'dimension' => $label,
                'data_value' => $value,
            ], ['name' => $label, 'value' => $value], ['analysis_value']);
        }
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        try {
            $service = new MeituanTemporalService();
            $fromRows = new \ReflectionMethod(MeituanTemporalService::class, 'hasCompleteVerifiedYesterdaySnapshotRows');
            $fromStore = new \ReflectionMethod(MeituanTemporalService::class, 'hasCompleteVerifiedYesterdaySnapshotCapturedOn');
            self::assertTrue($fromRows->invoke($service, $rows, '2026-07-29', '2026-07-30'));
            self::assertFalse($fromStore->invoke($service, 80, '2026-07-29', '2026-07-30'));

            Db::name('online_daily_data')->whereIn('id', $ids)->update(['tenant_id' => 9]);
            self::assertTrue($fromStore->invoke($service, 80, '2026-07-29', '2026-07-30'));
        } finally {
            Db::name('online_daily_data')->whereIn('id', $ids)->delete();
        }
    }

    public function testFutureRefreshGateIgnoresCompleteWrongTenantSnapshot(): void
    {
        $rows = [];
        $start = new \DateTimeImmutable('2026-07-29', new \DateTimeZone('Asia/Shanghai'));
        for ($day = 0; $day < 30; $day++) {
            $targetDate = $start->modify('+' . $day . ' days')->format('Y-m-d');
            foreach (['pv', 'uv', 'advance_orders'] as $type) {
                $rows[] = $this->verifiedRefreshRow(
                    10, 802, 'traffic_forecast', $targetDate, '2026-07-29 19:00:00',
                    ['data_value' => 1, 'dimension' => 'flow_forecast_' . $type],
                    ['forecast_type' => $type, 'current' => 1, 'peer_avg' => 1],
                    ['forecast_current', 'forecast_peer_average']
                );
            }
        }
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        try {
            $service = new MeituanTemporalService();
            $fromRows = new \ReflectionMethod(MeituanTemporalService::class, 'hasCompleteVerifiedFutureSnapshotRows');
            $fromStore = new \ReflectionMethod(MeituanTemporalService::class, 'hasCompleteVerifiedFutureSnapshotCapturedOn');
            self::assertTrue($fromRows->invoke($service, $rows, '2026-07-29', '2026-07-29'));
            self::assertFalse($fromStore->invoke($service, 80, '2026-07-29', '2026-07-29'));

            Db::name('online_daily_data')->whereIn('id', $ids)->update(['tenant_id' => 9]);
            self::assertTrue($fromStore->invoke($service, 80, '2026-07-29', '2026-07-29'));
        } finally {
            Db::name('online_daily_data')->whereIn('id', $ids)->delete();
        }
    }

    /** @param array<string, mixed> $columns @param array<string, mixed> $rawRow @param array<int, string> $factKeys */
    private function verifiedRefreshRow(
        int $tenantId,
        int $taskId,
        string $dataType,
        string $dataDate,
        string $capturedAt,
        array $columns,
        array $rawRow,
        array $factKeys
    ): array {
        $trace = 'tenant-scope-' . $taskId . '-' . $dataType;
        $urlHash = hash('sha256', $trace);
        $capture = [
            'capture_source' => 'xhr:traffic:business_data',
            'source_path' => 'data',
            'source_trace_id' => $trace,
            'source_url_hash' => $urlHash,
        ];
        $facts = [];
        foreach ($factKeys as $key) {
            $facts[] = [
                'metric_key' => $key,
                'status' => 'captured',
                'stored_value_present' => true,
                'source_path' => '$.' . $key,
                'capture_evidence' => $capture,
            ];
        }
        $dateSource = $dataType === 'traffic_forecast'
            ? 'row.dateTime'
            : 'page.business_period_selection.readback';
        $row = array_replace([
            'tenant_id' => $tenantId,
            'system_hotel_id' => 80,
            'source' => 'meituan',
            'data_type' => $dataType,
            'data_date' => $dataDate,
            'compare_type' => $dataType === 'traffic_forecast' ? 'forecast' : 'self',
            'ingestion_method' => 'browser_profile',
            'data_source_id' => 18,
            'sync_task_id' => $taskId,
            'source_trace_id' => $trace,
            'snapshot_time' => $capturedAt,
            'readback_verified' => 1,
            'raw_data' => json_encode([
                'row' => array_merge($rawRow, [
                    'dataDate' => $dataDate,
                    'date_source' => $dateSource,
                    '_capture_source' => $capture['capture_source'],
                    '_source_path' => $capture['source_path'],
                    'capture_evidence' => $capture,
                ]),
                'source_trace_id' => $trace,
                'source_url_hash' => $urlHash,
                'capture_evidence' => $capture,
                'date_source' => $dateSource,
                'captured_at' => $capturedAt,
                'platform_hotel_identifier_present' => true,
                'platform_hotel_identifier_source' => 'row.poi_id',
                'platform_hotel_identifier_proof' => 'row_field_present',
                'platform_hotel_binding_status' => 'matched',
                'platform_hotel_binding_proof' => 'source_and_response_match',
                'field_facts' => $facts,
            ], JSON_THROW_ON_ERROR),
        ], $columns);
        $row['id'] = (int)Db::name('online_daily_data')->insertGetId($row);
        return $row;
    }
}
