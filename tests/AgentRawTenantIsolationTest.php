<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\service\RevenuePricingRecommendationService;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class AgentRawTenantIsolationTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'agent_raw_tenant_' . getmypid() . '.sqlite';

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$sqlitePath);
        Db::connect(null, true);

        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100), status INTEGER)');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, hotel_id VARCHAR(100), data_source_id INTEGER, sync_task_id INTEGER, source_trace_id VARCHAR(160), data_date DATE NOT NULL, data_period VARCHAR(30), source VARCHAR(50), platform VARCHAR(30), data_type VARCHAR(50), dimension TEXT, compare_type TEXT, amount DECIMAL(12,2), quantity INTEGER, book_order_num INTEGER, list_exposure INTEGER, detail_exposure INTEGER, flow_rate REAL, order_filling_num INTEGER, order_submit_num INTEGER, readback_verified INTEGER, history_status VARCHAR(30), validation_status VARCHAR(30), ingestion_method VARCHAR(30), snapshot_time DATETIME, raw_data TEXT, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(30), status VARCHAR(30), enabled INTEGER, ingestion_method VARCHAR(30), config_json TEXT)');
        Db::execute('CREATE TABLE platform_data_sync_tasks (id INTEGER PRIMARY KEY, tenant_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(30), status VARCHAR(30), stats_json TEXT)');

        Db::name('hotels')->insert(['id' => 20, 'tenant_id' => 10, 'name' => 'Tenant 10 Hotel', 'status' => 1]);
        Db::name('hotels')->insert(['id' => 21, 'tenant_id' => 10, 'name' => 'Tenant 10 Other Hotel', 'status' => 1]);
        Db::name('online_daily_data')->insertAll([
            [
                'id' => 1,
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'hotel_id' => 'valid-ota',
                'data_source_id' => 1001,
                'data_date' => '2026-07-21',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'amount' => 100,
                'quantity' => 10,
                'book_order_num' => 5,
                'list_exposure' => 1000,
                'detail_exposure' => 100,
                'order_filling_num' => 20,
                'order_submit_num' => 10,
                'readback_verified' => 1,
                'validation_status' => 'verified',
                'raw_data' => json_encode([
                    'capture_evidence' => ['endpoint_id' => 'traffic_flow_transform'],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 2,
                'tenant_id' => 99,
                'system_hotel_id' => 20,
                'hotel_id' => 'polluted-ota',
                'data_source_id' => 2002,
                'data_date' => '2026-07-21',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'amount' => 9999,
                'quantity' => 999,
                'book_order_num' => 999,
                'list_exposure' => 99999,
                'detail_exposure' => 9999,
                'order_filling_num' => 999,
                'order_submit_num' => 999,
                'readback_verified' => 1,
                'validation_status' => 'verified',
                'raw_data' => json_encode([
                    'capture_evidence' => ['endpoint_id' => 'traffic_flow_transform'],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 3,
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'hotel_id' => 'valid-qunar-ota',
                'data_source_id' => 1001,
                'data_date' => '2026-07-21',
                'source' => 'ctrip',
                'platform' => 'qunar',
                'data_type' => 'traffic',
                'amount' => 300,
                'quantity' => 30,
                'book_order_num' => 15,
                'list_exposure' => 3000,
                'detail_exposure' => 300,
                'order_filling_num' => 30,
                'order_submit_num' => 15,
                'readback_verified' => 1,
                'validation_status' => 'verified',
                'raw_data' => json_encode([
                    'capture_evidence' => ['endpoint_id' => 'traffic_flow_transform'],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 4,
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'hotel_id' => 'valid-ota',
                'data_source_id' => 1001,
                'data_date' => '2026-07-21',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'business',
                'amount' => 110,
                'quantity' => 11,
                'book_order_num' => 6,
                'list_exposure' => null,
                'detail_exposure' => null,
                'order_filling_num' => null,
                'order_submit_num' => null,
                'readback_verified' => 1,
                'validation_status' => 'verified',
                'raw_data' => json_encode(['compareType' => 'self'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 5,
                'tenant_id' => 10,
                'system_hotel_id' => 21,
                'hotel_id' => 'same-tenant-other-ota',
                'data_source_id' => 1002,
                'data_date' => '2026-07-21',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'business',
                'amount' => 5100,
                'quantity' => 510,
                'book_order_num' => 51,
                'list_exposure' => null,
                'detail_exposure' => null,
                'order_filling_num' => null,
                'order_submit_num' => null,
                'readback_verified' => 1,
                'validation_status' => 'verified',
                'raw_data' => json_encode(['compareType' => 'self'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 6,
                'tenant_id' => 10,
                'system_hotel_id' => 21,
                'hotel_id' => 'same-tenant-other-ota',
                'data_source_id' => 1002,
                'data_date' => '2026-07-21',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'amount' => 6100,
                'quantity' => 610,
                'book_order_num' => 61,
                'list_exposure' => 61000,
                'detail_exposure' => 6100,
                'order_filling_num' => 610,
                'order_submit_num' => 61,
                'readback_verified' => 1,
                'validation_status' => 'verified',
                'raw_data' => json_encode([
                    'compareType' => 'self',
                    'capture_evidence' => ['endpoint_id' => 'traffic_flow_transform'],
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        Db::name('platform_data_sources')->insertAll([
            [
                'id' => 1001,
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'platform' => 'ctrip',
                'config_json' => json_encode(['ota_hotel_id' => 'valid-ota'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 2002,
                'tenant_id' => 99,
                'system_hotel_id' => 20,
                'platform' => 'ctrip',
                'config_json' => json_encode(['ota_hotel_id' => 'polluted-ota'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 1002,
                'tenant_id' => 10,
                'system_hotel_id' => 21,
                'platform' => 'ctrip',
                'config_json' => json_encode(['ota_hotel_id' => 'same-tenant-other-ota'], JSON_THROW_ON_ERROR),
            ],
        ]);
    }

    public function testAgentDiagnosisAndPricingExcludeWrongTenantRowsSharingTheHotelId(): void
    {
        $agent = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();

        $diagnosis = $this->method(Agent::class, 'queryOtaDiagnosisData')->invoke(
            $agent,
            20,
            '',
            '',
            'ctrip',
            '2026-07-21',
            '2026-07-21',
            'traffic'
        );
        self::assertSame([1], array_map('intval', array_column($diagnosis['online_rows'], 'id')));
        self::assertSame(100.0, (float)$diagnosis['online_rows'][0]['amount']);

        $qunarDiagnosis = $this->method(Agent::class, 'queryOtaDiagnosisData')->invoke(
            $agent,
            20,
            '',
            '',
            'qunar',
            '2026-07-21',
            '2026-07-21',
            'traffic'
        );
        self::assertSame([3], array_map('intval', array_column($qunarDiagnosis['online_rows'], 'id')));
        self::assertSame(300.0, (float)$qunarDiagnosis['online_rows'][0]['amount']);

        $ownOtaIds = $this->method(Agent::class, 'otaDiagnosisOwnPlatformHotelIds')->invoke(
            $agent,
            [['data_source_id' => 1001], ['data_source_id' => 2002]],
            20,
            'ctrip'
        );
        self::assertSame(['valid-ota'], $ownOtaIds);

    }

    public function testTrafficBatchBlocksTwoThousandFiveHundredCandidatesInsteadOfTruncating(): void
    {
        Db::name('online_daily_data')->delete(true);
        $rows = [];
        $id = 1;
        foreach ([
            ['date' => '2026-07-19', 'count' => 2000, 'submit' => 1],
            ['date' => '2026-07-20', 'count' => 250, 'submit' => 2],
            ['date' => '2026-07-21', 'count' => 250, 'submit' => 3],
        ] as $group) {
            for ($index = 0; $index < $group['count']; $index++) {
                $rows[] = [
                    'id' => $id++,
                    'tenant_id' => 10,
                    'system_hotel_id' => 20,
                    'hotel_id' => 'valid-ota',
                    'data_source_id' => 1001,
                    'data_date' => $group['date'],
                    'source' => 'ctrip',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'order_submit_num' => $group['submit'],
                    'readback_verified' => 1,
                    'history_status' => 'success',
                    'validation_status' => 'verified',
                    'ingestion_method' => 'browser_profile',
                    'sync_task_id' => 1001,
                    'source_trace_id' => 'traffic-strict-trace',
                    'raw_data' => $this->strictTrafficEvidence('traffic-strict-trace'),
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Db::name('online_daily_data')->insertAll($chunk);
        }

        $targetDate = '2026-07-22';
        $emptyTargetDate = '2026-08-05';
        $single = (new RevenuePricingRecommendationService())
            ->ctripTrafficDemandForecastSignal(20, $targetDate);
        $singleEmpty = (new RevenuePricingRecommendationService())
            ->ctripTrafficDemandForecastSignal(20, $emptyTargetDate);
        $queries = [];
        $capture = true;
        Db::listen(static function (string $sql) use (&$queries, &$capture): void {
            if ($capture && !str_starts_with($sql, 'CONNECT:')) {
                $queries[] = $sql;
            }
        });
        $batchService = new RevenuePricingRecommendationService();
        $prime = $this->method(
            RevenuePricingRecommendationService::class,
            'primeCtripTrafficForecastSignalsBatch'
        );
        $prime->invoke($batchService, 20, [$targetDate, $emptyTargetDate]);
        $batch = $batchService->ctripTrafficDemandForecastSignal(20, $targetDate);
        $batchEmpty = $batchService->ctripTrafficDemandForecastSignal(20, $emptyTargetDate);
        $capture = false;

        self::assertSame($single, $batch);
        self::assertSame($singleEmpty, $batchEmpty);
        self::assertSame('blocked', $batch['data_status']);
        self::assertContains(
            'ctrip_traffic_history_row_limit_exceeded',
            $batch['data_gaps'],
            json_encode($batch, JSON_UNESCAPED_SLASHES)
        );
        self::assertNull($batch['predicted_demand']);
        $trafficReads = array_values(array_filter(
            $queries,
            static fn(string $sql): bool => preg_match(
                '/\bfrom\s+[`"]?online_daily_data[`"]?/i',
                $sql
            ) === 1
        ));
        self::assertCount(1, $trafficReads, implode("\n", $trafficReads));
    }

    public function testDiagnosisPersistenceEvidenceReadbackRejectsCrossPlatformReference(): void
    {
        $agent = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $method = $this->method(Agent::class, 'assertOtaDiagnosisDecisionEvidenceScope');
        $snapshot = [
            'evidence_sources' => [[
                'ref' => 'online_daily_data#1',
                'platform' => 'ctrip',
                'decision_eligible' => true,
            ]],
            'action_items' => [[
                'evidence_refs' => ['online_daily_data#1'],
            ]],
        ];
        $method->invoke($agent, $snapshot, 20, 'ctrip', [
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-21',
        ]);

        $snapshot['evidence_sources'][0]['ref'] = 'online_daily_data#3';
        $snapshot['action_items'][0]['evidence_refs'] = ['online_daily_data#3'];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identity mismatch');
        $method->invoke($agent, $snapshot, 20, 'ctrip', [
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-21',
        ]);
    }

    public function testSinglePlatformDiagnosisCannotExpandToAnotherHotelThroughPlatformHotelId(): void
    {
        $agent = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $method = $this->method(Agent::class, 'queryOtaDiagnosisData');

        $business = $method->invoke(
            $agent,
            20,
            '20',
            'same-tenant-other-ota',
            'ctrip',
            '2026-07-21',
            '2026-07-21',
            'business'
        );
        self::assertSame([4], array_map('intval', array_column($business['online_rows'], 'id')));

        $traffic = $method->invoke(
            $agent,
            20,
            '20',
            'same-tenant-other-ota',
            'ctrip',
            '2026-07-21',
            '2026-07-21',
            'traffic'
        );
        self::assertSame([1], array_map('intval', array_column($traffic['online_rows'], 'id')));
    }

    #[RunInSeparateProcess]
    public function testDiagnosisFailsClosedWithoutAuthoritativeSystemHotelColumn(): void
    {
        Db::execute('DROP TABLE online_daily_data');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id VARCHAR(100), data_date DATE NOT NULL, source VARCHAR(50), platform VARCHAR(30), data_type VARCHAR(50), readback_verified INTEGER)');
        Db::name('online_daily_data')->insert([
            'id' => 99,
            'tenant_id' => 10,
            'hotel_id' => 'valid-ota',
            'data_date' => '2026-07-21',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'readback_verified' => 1,
        ]);

        $agent = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $diagnosis = $this->method(Agent::class, 'queryOtaDiagnosisData')->invoke(
            $agent,
            20,
            'valid-ota',
            'valid-ota',
            'ctrip',
            '2026-07-21',
            '2026-07-21',
            'business'
        );

        self::assertSame([], $diagnosis['online_rows']);
    }

    private function strictTrafficEvidence(string $traceId): string
    {
        $urlHash = hash('sha256', 'strict-traffic-fixture');
        $evidence = [
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'capture_source' => 'xhr:traffic:flow',
            'source_path' => 'data.flow',
        ];
        return (string)json_encode([
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'capture_evidence' => $evidence,
            'row' => [
                '_capture_source' => 'xhr:traffic:flow',
                '_source_path' => 'data.flow',
                'capture_evidence' => $evidence,
            ],
            'field_facts' => [[
                'metric_key' => 'order_submit_num',
                'normalized_field' => 'order_submit_num',
                'storage_field' => 'online_daily_data.order_submit_num',
                'source_path' => 'data.flow.orderSubmitNum',
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => $evidence,
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function method(string $class, string $name): ReflectionMethod
    {
        $method = new ReflectionMethod($class, $name);
        $method->setAccessible(true);
        return $method;
    }
}
