<?php
declare(strict_types=1);

namespace Tests;

use app\service\CanonicalOtaHistoryReceiptVerifier;
use app\service\RevenuePricingRecommendationService;
use app\service\StrictCtripTrafficHistoryReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class StrictCtripTrafficHistoryReaderTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'strict_ctrip_traffic_' . getmypid() . '.sqlite';
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$databasePath) && !unlink(self::$databasePath)) {
            throw new RuntimeException('Unable to remove strict traffic fixture.');
        }
    }

    protected function setUp(): void
    {
        foreach (['online_daily_data', 'platform_data_sync_tasks', 'platform_data_sources', 'hotels'] as $table) {
            Db::execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute(
            'CREATE TABLE platform_data_sources ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, platform TEXT, '
            . 'status TEXT, enabled INTEGER, ingestion_method TEXT)'
        );
        Db::execute(
            'CREATE TABLE platform_data_sync_tasks ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, data_source_id INTEGER, '
            . 'system_hotel_id INTEGER, platform TEXT, status TEXT, stats_json TEXT)'
        );
        $this->createOnlineDailyDataTable(true);
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 1]);
    }

    public function testBlockedCanonicalProofPassesExplicitGapsWithoutReadingRows(): void
    {
        $verifier = $this->fakeVerifier(static fn(array $windows): array => array_map(
            static fn(array $_window): array => [
                'status' => 'blocked',
                'authoritative_row_ids' => [],
                'candidate_row_count' => 3,
                'authoritative_row_count' => 0,
                'ignored_unselected_row_count' => 0,
                'data_gaps' => [
                    'ctrip_traffic_readback_unverified',
                    'ctrip_traffic_validation_status_not_verified',
                    'canonical_ota_history_metric_fact_invalid:order_submit_num',
                ],
            ],
            $windows
        ));
        $reader = new StrictCtripTrafficHistoryReader($verifier);

        $single = $reader->read(80, '2026-07-19', '2026-07-21');
        $batch = $reader->readBatch(80, [
            'window' => ['start' => '2026-07-19', 'end' => '2026-07-21'],
        ]);
        self::assertSame($single, $batch['window']);
        self::assertSame('blocked', $single['data_status']);
        self::assertSame([], $single['rows']);
        self::assertContains('ctrip_traffic_readback_unverified', $single['data_gaps']);
        self::assertContains(
            'canonical_ota_history_metric_fact_invalid:order_submit_num',
            $single['data_gaps']
        );
    }

    public function testMissingSystemHotelColumnCannotFallBackToSameTenantHotelId(): void
    {
        Db::execute('DROP TABLE online_daily_data');
        $this->createOnlineDailyDataTable(false);
        Db::name('online_daily_data')->insert([
            'id' => 1,
            'tenant_id' => 1,
            'hotel_id' => '80',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => '2026-07-21',
            'data_period' => 'historical_daily',
            'data_type' => 'traffic',
            'readback_verified' => 1,
            'history_status' => 'success',
            'validation_status' => 'verified',
            'ingestion_method' => 'browser_profile',
            'data_source_id' => 10,
            'sync_task_id' => 20,
            'source_trace_id' => 'same-tenant-fake',
            'raw_data' => '{}',
            'order_submit_num' => 99,
        ]);

        $result = (new StrictCtripTrafficHistoryReader())->read(
            80,
            '2026-07-21',
            '2026-07-21'
        );
        self::assertSame('blocked', $result['data_status']);
        self::assertSame([], $result['rows']);
        self::assertContains(
            'canonical_ota_history_online_daily_data_system_hotel_id_column_missing',
            $result['data_gaps']
        );
    }

    public function testCanonicalIdsYieldIdenticalSingleAndBatchForecastPayload(): void
    {
        $this->insertRow(1, '2026-07-19', 10);
        $this->insertRow(2, '2026-07-20', 20);
        $this->insertRow(3, '2026-07-21', 30);
        $reader = new StrictCtripTrafficHistoryReader($this->dateScopedVerifier());

        $single = $reader->read(80, '2026-07-19', '2026-07-21');
        $batch = $reader->readBatch(80, [
            'window' => ['start' => '2026-07-19', 'end' => '2026-07-21'],
        ]);
        self::assertSame($single, $batch['window']);
        self::assertSame('ready', $single['data_status']);
        self::assertSame([1, 2, 3], array_map('intval', array_column($single['rows'], 'id')));

        [$forecastSingle, $forecastBatch] = $this->singleAndBatchForecast($reader, '2026-07-22');
        self::assertSame($forecastSingle, $forecastBatch);
        self::assertSame('ok', $forecastSingle['data_status']);
        self::assertGreaterThan(0, (float)$forecastSingle['predicted_demand']);
    }

    public function testOneAndThirtyOneWindowsEachUseOneCanonicalReadWithIdenticalPayloads(): void
    {
        $this->insertRow(1, '2026-07-21', 30);
        $queries = ['one' => [], 'thirty_one' => []];
        $capture = null;
        Db::listen(static function (string $sql) use (&$queries, &$capture): void {
            if (is_string($capture) && !str_starts_with($sql, 'CONNECT:')) {
                $queries[$capture][] = $sql;
            }
        });
        $reader = new StrictCtripTrafficHistoryReader($this->dateScopedVerifier());
        $capture = 'one';
        $one = $reader->readBatch(80, [
            'one' => ['start' => '2026-07-21', 'end' => '2026-07-21'],
        ]);
        $windows = [];
        foreach (range(1, 31) as $index) {
            $windows['window-' . $index] = [
                'start' => '2026-07-21',
                'end' => '2026-07-21',
            ];
        }
        $capture = 'thirty_one';
        $many = $reader->readBatch(80, $windows);
        $capture = null;

        $trafficReads = static fn(array $logged): array => array_values(array_filter(
            $logged,
            static fn(string $sql): bool => preg_match(
                '/\bfrom\s+[`"]?online_daily_data[`"]?/i',
                $sql
            ) === 1
        ));
        self::assertCount(2, $trafficReads($queries['one']));
        self::assertSame(
            count($trafficReads($queries['one'])),
            count($trafficReads($queries['thirty_one']))
        );
        foreach (array_keys($windows) as $windowKey) {
            self::assertSame($one['one'], $many[$windowKey]);
        }
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function singleAndBatchForecast(
        StrictCtripTrafficHistoryReader $reader,
        string $targetDate
    ): array {
        $singleService = new RevenuePricingRecommendationService(null, null, $reader);
        $single = $singleService->ctripTrafficDemandForecastSignal(80, $targetDate);
        $batchService = new RevenuePricingRecommendationService(null, null, $reader);
        $prime = new \ReflectionMethod(
            RevenuePricingRecommendationService::class,
            'primeCtripTrafficForecastSignalsBatch'
        );
        $prime->invoke($batchService, 80, [$targetDate]);
        return [$single, $batchService->ctripTrafficDemandForecastSignal(80, $targetDate)];
    }

    private function insertRow(int $id, string $date, float $submitCount): void
    {
        Db::execute(
            'INSERT INTO online_daily_data ('
            . 'id,tenant_id,system_hotel_id,hotel_id,data_source_id,sync_task_id,'
            . 'source,platform,data_date,data_period,data_type,readback_verified,'
            . 'history_status,validation_status,ingestion_method,source_trace_id,'
            . 'raw_data,order_submit_num) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $id,
                1,
                80,
                'ctrip-80',
                10,
                20,
                'ctrip',
                'ctrip',
                $date,
                'historical_daily',
                'traffic',
                1,
                'success',
                'verified',
                'browser_profile',
                'trace-' . $id,
                '{}',
                $submitCount,
            ]
        );
    }

    private function dateScopedVerifier(): CanonicalOtaHistoryReceiptVerifier
    {
        return $this->fakeVerifier(static function (array $windows): array {
            $rows = Db::name('online_daily_data')->field('id,data_date')->select()->toArray();
            $results = [];
            foreach ($windows as $key => $window) {
                $ids = array_values(array_map(
                    static fn(array $row): int => (int)$row['id'],
                    array_filter($rows, static fn(array $row): bool =>
                        (string)$row['data_date'] >= (string)$window['start']
                        && (string)$row['data_date'] <= (string)$window['end']
                    )
                ));
                sort($ids, SORT_NUMERIC);
                $results[$key] = [
                    'status' => $ids === [] ? 'empty' : 'ready',
                    'authoritative_row_ids' => $ids,
                    'candidate_row_count' => count($ids),
                    'authoritative_row_count' => count($ids),
                    'ignored_unselected_row_count' => 0,
                    'data_gaps' => $ids === [] ? ['canonical_ota_history_authoritative_rows_missing'] : [],
                ];
            }
            return $results;
        });
    }

    private function fakeVerifier(callable $resolver): CanonicalOtaHistoryReceiptVerifier
    {
        return new class($resolver) extends CanonicalOtaHistoryReceiptVerifier {
            private \Closure $resolver;

            public function __construct(callable $resolver)
            {
                $this->resolver = \Closure::fromCallable($resolver);
            }

            public function verifyWindows(int $systemHotelId, array $windows): array
            {
                return ($this->resolver)($windows);
            }
        };
    }

    private function createOnlineDailyDataTable(bool $withSystemHotelId): void
    {
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, '
            . ($withSystemHotelId ? 'system_hotel_id INTEGER NOT NULL, ' : '')
            . 'hotel_id TEXT, data_source_id INTEGER, sync_task_id INTEGER, '
            . 'source TEXT, platform TEXT, data_date TEXT, data_period TEXT, data_type TEXT, '
            . 'dimension TEXT, compare_type TEXT, readback_verified INTEGER, history_status TEXT, '
            . 'validation_status TEXT, ingestion_method TEXT, source_trace_id TEXT, '
            . 'snapshot_time TEXT, raw_data TEXT, list_exposure REAL, detail_exposure REAL, '
            . 'flow_rate REAL, order_filling_num REAL, order_submit_num REAL, '
            . 'book_order_num REAL, quantity REAL)'
        );
    }
}
