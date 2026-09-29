<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataAnalyticsConcern;
use app\controller\concern\OnlineDataQualityConcern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OnlineDataAnalyticsDatabaseScopeTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'online_data_analytics_scope_' . getmypid() . '.sqlite';
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
        Db::execute(<<<'SQL'
CREATE TABLE online_daily_data (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER DEFAULT NULL,
    system_hotel_id INTEGER DEFAULT NULL,
    hotel_id TEXT DEFAULT NULL,
    data_source_id INTEGER DEFAULT NULL,
    sync_task_id INTEGER DEFAULT NULL,
    platform TEXT DEFAULT NULL,
    source TEXT DEFAULT NULL,
    data_type TEXT DEFAULT NULL,
    dimension TEXT DEFAULT NULL,
    data_period TEXT DEFAULT NULL,
    is_final INTEGER NOT NULL DEFAULT 0,
    compare_type TEXT DEFAULT NULL,
    ingestion_method TEXT DEFAULT NULL,
    data_date TEXT DEFAULT NULL,
    amount REAL NOT NULL DEFAULT 0,
    hotel_name TEXT DEFAULT NULL,
    quantity REAL DEFAULT NULL,
    data_value REAL DEFAULT NULL,
    book_order_num REAL DEFAULT NULL,
    comment_score REAL DEFAULT NULL,
    status TEXT DEFAULT NULL,
    history_status TEXT DEFAULT NULL,
    validation_status TEXT DEFAULT NULL,
    readback_verified INTEGER NOT NULL DEFAULT 0
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE platform_data_sources (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    system_hotel_id INTEGER NOT NULL,
    platform TEXT NOT NULL,
    data_type TEXT DEFAULT NULL
)
SQL);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)');
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Synthetic permitted hotel'],
            ['id' => 81, 'tenant_id' => 8, 'name' => 'Synthetic other hotel'],
        ]);
    }

    protected function setUp(): void
    {
        Db::execute('DELETE FROM online_daily_data');
        Db::execute('DELETE FROM platform_data_sources');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove online analytics scope SQLite fixture.');
        }
    }

    public function testDefaultBusinessAggregateExcludesUntypedAndNonBusinessRows(): void
    {
        Db::execute('DELETE FROM online_daily_data');
        Db::name('online_daily_data')->insertAll([
            ['id' => 1, 'data_type' => 'business', 'amount' => 100],
            ['id' => 2, 'data_type' => null, 'amount' => 200],
            ['id' => 3, 'data_type' => '', 'amount' => 400],
            ['id' => 4, 'data_type' => 'advertising', 'amount' => 800],
            ['id' => 5, 'data_type' => 'peer_rank', 'amount' => 1600],
            ['id' => 6, 'data_type' => 'ranking', 'amount' => 3200],
            ['id' => 7, 'data_type' => 'traffic', 'amount' => 6400],
        ]);

        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $normalize = new ReflectionMethod($subject, 'normalizeOnlineDataAnalysisType');
        $filter = new ReflectionMethod($subject, 'applyDataTypeFilter');
        $dataType = $normalize->invoke($subject, '');
        $query = Db::name('online_daily_data');
        $filter->invoke($subject, $query, $dataType);
        $rows = $query->order('id', 'asc')->select()->toArray();

        self::assertSame('business', $dataType);
        self::assertCount(1, $rows);
        self::assertSame(['business'], array_column($rows, 'data_type'));
        self::assertSame(100.0, array_sum(array_map('floatval', array_column($rows, 'amount'))));
    }

    public function testStrictEvidenceFilterKeepsOnlyVerifiedSuccessfulReadbacks(): void
    {
        Db::execute('DELETE FROM online_daily_data');
        $ready = [
            'tenant_id' => 7,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-80',
            'data_source_id' => 25,
            'sync_task_id' => 4567,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'dimension' => 'semantic:ctrip:room_revenue',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'compare_type' => 'self',
            'ingestion_method' => 'browser_profile',
            'data_date' => '2026-08-30',
        ];
        Db::name('online_daily_data')->insertAll([
            ['id' => 11, 'data_type' => 'business', 'amount' => 100, 'history_status' => 'success', 'validation_status' => 'verified', 'readback_verified' => 1] + $ready,
            ['id' => 12, 'data_type' => 'business', 'amount' => 200, 'history_status' => 'failed', 'validation_status' => 'verified', 'readback_verified' => 1] + $ready,
            ['id' => 13, 'data_type' => 'business', 'amount' => 300, 'history_status' => 'success', 'validation_status' => 'partial', 'readback_verified' => 1] + $ready,
            ['id' => 14, 'data_type' => 'business', 'amount' => 400, 'history_status' => 'success', 'validation_status' => 'verified', 'readback_verified' => 0] + $ready,
            ['id' => 15, 'data_type' => 'business', 'amount' => 500, 'history_status' => 'success', 'validation_status' => 'verified', 'readback_verified' => 1, 'data_period' => 'realtime_snapshot', 'is_final' => 0] + $ready,
        ]);

        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $filter = new ReflectionMethod($subject, 'applyStrictOnlineDataAnalysisEvidenceFilter');
        $query = Db::name('online_daily_data')->where('data_type', 'business');
        $columns = [
            'history_status' => ['name' => 'history_status'],
            'validation_status' => ['name' => 'validation_status'],
            'readback_verified' => ['name' => 'readback_verified'],
            'data_period' => ['name' => 'data_period'],
            'is_final' => ['name' => 'is_final'],
            'platform' => ['name' => 'platform'],
            'source' => ['name' => 'source'],
            'dimension' => ['name' => 'dimension'],
            'system_hotel_id' => ['name' => 'system_hotel_id'],
            'hotel_id' => ['name' => 'hotel_id'],
            'data_source_id' => ['name' => 'data_source_id'],
            'sync_task_id' => ['name' => 'sync_task_id'],
            'compare_type' => ['name' => 'compare_type'],
            'ingestion_method' => ['name' => 'ingestion_method'],
        ];

        self::assertTrue($filter->invoke($subject, $query, $columns));
        $rows = $query->order('id', 'asc')->select()->toArray();
        self::assertSame([11], array_map('intval', array_column($rows, 'id')));
    }

    public function testHeterogeneousBusinessRowsBlockNumericAggregation(): void
    {
        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $gate = new ReflectionMethod($subject, 'buildOnlineDataAggregationGate');

        $result = $gate->invoke($subject, [
            [
                'tenant_id' => 7,
                'system_hotel_id' => 80,
                'hotel_id' => 'ctrip-80',
                'data_source_id' => 25,
                'sync_task_id' => 4567,
                'data_date' => '2026-08-30',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'semantic:ctrip_business_market_overview:booking_order_count',
                'amount' => 100,
            ],
            [
                'tenant_id' => 7,
                'system_hotel_id' => 80,
                'hotel_id' => 'ctrip-80',
                'data_source_id' => 25,
                'sync_task_id' => 4567,
                'data_date' => '2026-08-30',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'semantic:ctrip_checkout_summary:room_revenue',
                'amount' => 200,
            ],
            [
                'tenant_id' => 7,
                'system_hotel_id' => 80,
                'hotel_id' => 'meituan-80',
                'data_source_id' => 68,
                'sync_task_id' => 4566,
                'data_date' => '2026-08-30',
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'business',
                'dimension' => 'semantic:meituan_business_summary:sales_amount',
                'amount' => 300,
            ],
        ]);

        self::assertFalse($result['allowed']);
        self::assertSame('blocked', $result['status']);
        self::assertSame('heterogeneous_metric_scope', $result['blocker']);
        self::assertSame(3, $result['group_count']);
        self::assertCount(3, $result['metric_groups']);
    }

    public function testOnePlatformDimensionGroupAllowsNumericAggregation(): void
    {
        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $gate = new ReflectionMethod($subject, 'buildOnlineDataAggregationGate');

        $result = $gate->invoke($subject, [
            [
                'system_hotel_id' => 80,
                'tenant_id' => 7,
                'hotel_id' => 'ctrip-80',
                'data_source_id' => 25,
                'sync_task_id' => 4567,
                'data_date' => '2026-08-29',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'semantic:ctrip_checkout_summary:room_revenue',
                'amount' => 100,
            ],
            [
                'system_hotel_id' => 80,
                'tenant_id' => 7,
                'hotel_id' => 'ctrip-80',
                'data_source_id' => 25,
                'sync_task_id' => 4567,
                'data_date' => '2026-08-30',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'semantic:ctrip_checkout_summary:room_revenue',
                'amount' => 200,
            ],
        ]);

        self::assertTrue($result['allowed']);
        self::assertSame('ready', $result['status']);
        self::assertSame('', $result['blocker']);
        self::assertSame(1, $result['group_count']);
        self::assertSame(2, $result['metric_groups'][0]['record_count']);
    }

    public function testDuplicateCanonicalDailyMetricRowsBlockNumericAggregation(): void
    {
        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $gate = new ReflectionMethod($subject, 'buildOnlineDataAggregationGate');
        $row = [
            'system_hotel_id' => 80,
            'tenant_id' => 7,
            'hotel_id' => 'ctrip-80',
            'data_source_id' => 25,
            'sync_task_id' => 4567,
            'data_date' => '2026-08-30',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'data_type' => 'business',
            'dimension' => 'semantic:ctrip_checkout_summary:room_revenue',
            'amount' => 100,
        ];

        $result = $gate->invoke($subject, [$row, $row]);

        self::assertFalse($result['allowed']);
        self::assertSame('duplicate_canonical_grain', $result['blocker']);
        self::assertSame(1, $result['duplicate_grain_count']);
    }

    public function testMissingPlatformOrDimensionIdentityBlocksEvenOneGroup(): void
    {
        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $gate = new ReflectionMethod($subject, 'buildOnlineDataAggregationGate');

        $result = $gate->invoke($subject, [[
            'tenant_id' => 7,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-80',
            'data_source_id' => 25,
            'sync_task_id' => 4567,
            'data_date' => '2026-08-30',
            'source' => '',
            'platform' => '',
            'data_type' => 'business',
            'dimension' => '',
        ]]);

        self::assertFalse($result['allowed']);
        self::assertSame('aggregation_identity_incomplete', $result['blocker']);
        self::assertContains('platform_identity_missing', $result['identity_gap_codes']);
        self::assertContains('metric_dimension_missing', $result['identity_gap_codes']);
    }

    public function testSourceOwnershipMustMatchTenantHotelAndPlatform(): void
    {
        Db::name('platform_data_sources')->insert([
            'id' => 25,
            'tenant_id' => 7,
            'system_hotel_id' => 81,
            'platform' => 'ctrip',
        ]);
        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $gate = new ReflectionMethod($subject, 'buildOnlineDataSourceOwnershipGate');
        $result = $gate->invoke($subject, [[
            'tenant_id' => 7,
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'source' => 'ctrip',
            'platform' => '',
        ]]);

        self::assertFalse($result['allowed']);
        self::assertSame('source_ownership_mismatch', $result['reason_code']);
        self::assertSame(1, $result['mismatch_count']);
    }

    public function testSourceAliasCannotContradictTheExplicitPlatformOwner(): void
    {
        Db::name('platform_data_sources')->insert([
            'id' => 68,
            'tenant_id' => 7,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'data_type' => 'business',
        ]);
        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $gate = new ReflectionMethod($subject, 'buildOnlineDataSourceOwnershipGate');
        $result = $gate->invoke($subject, [[
            'tenant_id' => 7,
            'system_hotel_id' => 80,
            'data_source_id' => 68,
            'data_type' => 'business',
            'source' => 'meituan',
            'platform' => 'ctrip',
        ]]);

        self::assertFalse($result['allowed']);
        self::assertSame('source_ownership_mismatch', $result['reason_code']);
    }

    public function testSourceOwnershipCannotTreatMissingTenantAsVerified(): void
    {
        Db::name('platform_data_sources')->insert(['id' => 25, 'tenant_id' => 7, 'system_hotel_id' => 80, 'platform' => 'ctrip']);
        $subject = new class { use OnlineDataAnalyticsConcern; };
        $gate = new ReflectionMethod($subject, 'buildOnlineDataSourceOwnershipGate');
        $result = $gate->invoke($subject, [[
            'tenant_id' => null, 'system_hotel_id' => 80, 'data_source_id' => 25,
            'source' => 'ctrip', 'platform' => 'ctrip',
        ]]);
        self::assertFalse($result['allowed']);
        self::assertSame('source_ownership_mismatch', $result['reason_code']);
    }

    public static function analysisScenarios(): iterable
    {
        yield 'one homogeneous exact source' => [[], '', 100.0];
        yield 'different metric dimensions' => [['second_dimension' => true], 'heterogeneous_metric_scope', null];
        yield 'duplicate daily canonical grain' => [['duplicate' => true], 'duplicate_canonical_grain', null];
        yield 'source bound to another hotel' => [['source_hotel_id' => 81], 'source_ownership_unverified', null];
        yield 'failed history excluded' => [['history_status' => 'failed'], '', null];
        yield 'missing final evidence column' => [['missing_column' => 'is_final'], 'strict_evidence_contract_missing', null];
    }

    #[DataProvider('analysisScenarios')]
    public function testDataAnalysisAppliesEvidenceAndAggregationGatesToActualResponse(array $changes, string $blocker, ?float $total): void
    {
        Db::name('platform_data_sources')->insert([
            'id' => 25, 'tenant_id' => 7, 'system_hotel_id' => $changes['source_hotel_id'] ?? 80,
            'platform' => 'ctrip', 'data_type' => 'business',
        ]);
        $ready = [
            'id' => 101, 'tenant_id' => 7, 'system_hotel_id' => 80, 'hotel_id' => 'ctrip-80',
            'hotel_name' => 'Synthetic permitted hotel', 'data_source_id' => 25, 'sync_task_id' => 4567,
            'platform' => 'ctrip', 'source' => 'ctrip', 'data_type' => 'business',
            'dimension' => 'semantic:ctrip:room_revenue', 'data_period' => 'historical_daily',
            'is_final' => 1, 'compare_type' => 'self', 'ingestion_method' => 'browser_profile',
            'data_date' => '2026-08-30', 'amount' => 100,
            'history_status' => $changes['history_status'] ?? 'success', 'validation_status' => 'verified',
            'readback_verified' => 1,
        ];
        $rows = [$ready];
        if (isset($changes['second_dimension']) || isset($changes['duplicate'])) {
            $second = $ready; $second['id'] = 102;
            if (isset($changes['second_dimension'])) $second['dimension'] = 'semantic:ctrip:room_nights';
            $rows[] = $second;
        }
        $outside = $ready; $outside['id'] = 103; $outside['system_hotel_id'] = 81; $outside['tenant_id'] = 8; $outside['amount'] = 9000;
        $rows[] = $outside;
        Db::name('online_daily_data')->insertAll($rows);
        $controller = $this->analysisController($changes['missing_column'] ?? '');
        $response = $controller->dataAnalysis()->getData();
        self::assertTrue($controller->permissionChecked);
        self::assertSame(200, $response['code']);
        $data = $response['data'];
        self::assertSame($blocker, $data['summary']['aggregation_gate']['blocker']);
        self::assertSame($total, $data['summary']['total_amount']);
        self::assertSame($data['summary']['aggregation_gate'], $data['query_scope']['aggregation_gate']);
        self::assertSame(isset($changes['second_dimension']) || isset($changes['duplicate']) ? 2 : 1, $data['summary']['scoped_record_count']);
        if ($total === null) {
            self::assertSame([], $data['aggregated']);
            self::assertSame([], $data['hotel_ranking']);
            self::assertSame('blocked', $data['summary']['data_status']);
            self::assertContains('total_amount', $data['summary']['data_gaps']);
            if ($blocker !== '') self::assertNull($data['chart_data']);
            else self::assertSame([], $data['chart_data']['labels']);
        } else {
            self::assertTrue($data['summary']['aggregation_gate']['allowed']);
            self::assertCount(1, $data['aggregated']);
            self::assertCount(1, $data['hotel_ranking']);
            self::assertSame(['2026-08-30'], $data['chart_data']['labels']);
        }
    }

    private function analysisController(string $missingColumn): object
    {
        return new class($missingColumn) {
            use OnlineDataAnalyticsConcern;
            use OnlineDataQualityConcern;
            public bool $permissionChecked = false;
            public object $request;
            public function __construct(private string $missingColumn)
            {
                $this->request = new class {
                    public function get(string $key, mixed $default = null): mixed
                    {
                        return ['system_hotel_id' => '80', 'start_date' => '2026-08-30', 'end_date' => '2026-08-30'][$key] ?? $default;
                    }
                };
            }
            private function checkPermission(): void { $this->permissionChecked = true; }
            private function permittedHotelIdsForAction(string $capability): ?array
            {
                TestCase::assertSame('can_view_online_data', $capability); return [80];
            }
            private function getOnlineDailyDataColumns(): array
            {
                $columns = array_fill_keys(array_column(Db::query('PRAGMA table_info(online_daily_data)'), 'name'), true);
                unset($columns[$this->missingColumn]); return $columns;
            }
            protected function success(mixed $data = null): \think\Response
            {
                return json(['code' => 200, 'data' => $data]);
            }
            protected function error(string $message, int $code = 400): \think\Response
            {
                return json(['code' => $code, 'message' => $message], $code);
            }
        };
    }
}
