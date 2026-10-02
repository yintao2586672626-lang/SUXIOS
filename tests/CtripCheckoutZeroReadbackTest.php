<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use app\service\PlatformDataSyncService;
use app\service\PlatformNormalizedRowPersistenceService;
use app\service\RevenueOperatingLedgerService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class CtripCheckoutZeroReadbackTest extends TestCase
{
    protected function setUp(): void
    {
        $path = (string)getenv('SUXIOS_CACHE_PATH');
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $path]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => $path . '/logs/']]], 'log');
        Config::set(['default' => 'checkout_zero', 'connections' => ['checkout_zero' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute('INSERT INTO hotels VALUES (80,1),(81,2)');
    }

    protected function tearDown(): void { Db::connect()->close(); }

    private function normalized(array $values, string $date = '2026-07-24'): array
    {
        return (new PlatformDataSyncService([]))->normalizeRowsFromPayload(['rows' => [array_merge([
            'hotel_id' => 'synthetic-ctrip-80', 'data_date' => $date,
            'data_type' => 'business', 'endpoint_id' => 'business_market_overview', 'section' => 'business_overview',
            '_source_path' => 'data.data', 'source_trace_id' => 'synthetic-checkout:' . $date,
            'source_url_hash' => str_repeat('b', 64),
        ], $values)]], [
            'id' => 25, 'platform' => 'ctrip', 'data_type' => 'business',
            'ingestion_method' => 'browser_profile', 'system_hotel_id' => 80, 'tenant_id' => 1,
        ], 1576);
    }

    private function createSchema(array $rows): array
    {
        $keys = array_unique(array_merge(array_keys($rows[0]), ['id', 'readback_verified', 'readback_verified_at']));
        $numeric = ['tenant_id','system_hotel_id','sync_task_id','data_source_id','quantity','amount','is_final','readback_verified'];
        $columns = [];
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-z_]+$/D', $key);
            $columns[] = $key === 'id' ? 'id INTEGER PRIMARY KEY AUTOINCREMENT'
                : '"' . $key . '" ' . (in_array($key, $numeric, true) ? 'NUMERIC' : 'TEXT');
        }
        Db::execute('CREATE TABLE online_daily_data (' . implode(',', $columns) . ')');
        return array_fill_keys($keys, true);
    }

    private function project(array $exact): array
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($exact);
        $service = new OtaRevenueMetricService();
        $entries = $service->ledgerEntries($dataset, 1, 80, 'ctrip');
        // Label isolated fixtures without changing the producer's source, quality or readback proof.
        foreach ($entries as &$entry) $entry['evidence_mode'] = 'synthetic';
        unset($entry);
        $ledger = (new RevenueOperatingLedgerService())->build([
            'tenant_id' => 1, 'hotel_id' => 80, 'start_date' => '2026-07-24', 'end_date' => '2026-07-24',
            'platforms' => ['ctrip'], 'evidence_mode' => 'synthetic',
        ], $entries);
        return ['dataset' => $dataset, 'metrics' => $service->summarizeDataset($dataset), 'ledger' => $ledger];
    }

    private function saved(array $values, string $fixture): array
    {
        $rows = $this->normalized($values);
        self::assertNotEmpty($rows);
        $receipt = (new PlatformNormalizedRowPersistenceService())->save($rows, $this->createSchema($rows));
        self::assertTrue($receipt['readback_verified']);
        $exact = Db::name('online_daily_data')->whereIn('id', $receipt['row_ids'])->order('id')->select()->toArray();
        $result = ['rows' => $rows, 'receipt' => $receipt, 'exact' => $exact] + $this->project($exact);
        $dir = getenv('SUXI_CHECKOUT_ZERO_OUTPUT');
        if (is_string($dir) && $dir !== '') {
            file_put_contents($dir . '/' . $fixture . '.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        return $result;
    }

    public function testBrowserCatalogStandardRowSavesWithTenantAndExactReadback(): void
    {
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            hotel_id TEXT, hotel_name TEXT, source TEXT, platform TEXT,
            data_type TEXT, data_date TEXT, dimension TEXT, compare_type TEXT,
            amount REAL, raw_data TEXT, source_trace_id TEXT,
            data_period TEXT, snapshot_time TEXT, snapshot_bucket TEXT,
            is_final INTEGER, readback_verified INTEGER DEFAULT 0,
            readback_verified_at TEXT, create_time TEXT, update_time TEXT
        )');
        $row = [
            'system_hotel_id' => 80, 'hotel_id' => 'synthetic-ctrip-80',
            'hotel_name' => 'synthetic-80', 'source' => 'ctrip', 'platform' => 'ctrip',
            'data_type' => 'business', 'data_date' => '2026-07-24',
            'dimension' => 'catalog:business:amount', 'compare_type' => 'self',
            'amount' => 500, 'raw_data' => '{}',
            'source_trace_id' => 'synthetic-catalog-80',
        ];
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $saved = (new \ReflectionMethod($controller, 'saveCtripStandardRows'))
            ->invoke($controller, [$row]);
        self::assertSame(1, $saved);
        $exact = Db::name('online_daily_data')->where('source_trace_id', 'synthetic-catalog-80')->find();
        self::assertSame(1, (int)$exact['tenant_id']);
        self::assertSame(80, (int)$exact['system_hotel_id']);
        self::assertSame(1, (int)$exact['readback_verified']);
        self::assertSame(500.0, (float)$exact['amount']);

        // A foreign-tenant row with the same source identity must not be reassigned on retry.
        $foreignId = (int)$exact['id'];
        Db::name('online_daily_data')->where('id', $foreignId)->update([
            'tenant_id' => 2, 'amount' => 111, 'source_trace_id' => 'synthetic-foreign',
            'readback_verified' => 0,
        ]);
        $save = new \ReflectionMethod($controller, 'saveCtripStandardRows');
        self::assertSame(1, $save->invoke($controller, [$row]));
        $foreign = Db::name('online_daily_data')->where('id', $foreignId)->find();
        self::assertSame(2, (int)$foreign['tenant_id']);
        self::assertSame(111.0, (float)$foreign['amount']);
        $trusted = Db::name('online_daily_data')->where('tenant_id', 1)->find();
        self::assertNotSame($foreignId, (int)$trusted['id']);
        self::assertSame(1, (int)$trusted['readback_verified']);

        $row['amount'] = 600;
        self::assertSame(1, $save->invoke($controller, [$row]));
        $corrected = Db::name('online_daily_data')->where('tenant_id', 1)->find();
        self::assertSame((int)$trusted['id'], (int)$corrected['id']);
        self::assertSame(600.0, (float)$corrected['amount']);
        self::assertSame(2, Db::name('online_daily_data')->count());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testBrowserCatalogFutureSnapshotsKeepSeparateCaptureBuckets(): void
    {
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            hotel_id TEXT, hotel_name TEXT, source TEXT, platform TEXT,
            data_type TEXT, data_date TEXT, dimension TEXT, compare_type TEXT,
            data_value REAL, raw_data TEXT, source_trace_id TEXT,
            data_period TEXT, snapshot_time TEXT, snapshot_bucket TEXT,
            is_final INTEGER, readback_verified INTEGER DEFAULT 0,
            readback_verified_at TEXT, create_time TEXT, update_time TEXT
        )');
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $save = new \ReflectionMethod($controller, 'saveCtripStandardRows');

        foreach (['next_30_days' => 'traffic_forecast', 'future_on_books' => 'business'] as $period => $type) {
            $row = [
                'system_hotel_id' => 80, 'hotel_id' => 'synthetic-ctrip-80',
                'hotel_name' => 'synthetic-80', 'source' => 'ctrip', 'platform' => 'ctrip',
                'data_type' => $type, 'data_date' => '2026-10-01',
                'dimension' => 'catalog:' . $period . ':metric', 'compare_type' => 'self',
                'data_period' => $period, 'snapshot_time' => '2026-09-28 10:00:00',
                'source_trace_id' => 'synthetic-' . $period . '-first',
                'data_value' => 100, 'raw_data' => '{}',
            ];
            self::assertSame(1, $save->invoke($controller, [$row]), $period);
            $first = Db::name('online_daily_data')->where('source_trace_id', $row['source_trace_id'])->find();
            self::assertSame(1, (int)$first['readback_verified']);

            $row['snapshot_time'] = '2026-09-28 11:00:00';
            $row['source_trace_id'] = 'synthetic-' . $period . '-second';
            $row['data_value'] = 200;
            self::assertSame(1, $save->invoke($controller, [$row]));
            $versions = Db::name('online_daily_data')->where('dimension', $row['dimension'])->order('id')->select()->toArray();
            self::assertCount(2, $versions);
            self::assertSame((int)$first['id'], (int)$versions[0]['id']);
            self::assertSame(100.0, (float)$versions[0]['data_value']);
            self::assertSame(1, (int)$versions[0]['readback_verified']);
            self::assertSame(200.0, (float)$versions[1]['data_value']);
            self::assertSame(1, (int)$versions[1]['readback_verified']);

            $row['data_value'] = 250;
            self::assertSame(1, $save->invoke($controller, [$row]));
            $retried = Db::name('online_daily_data')->where('dimension', $row['dimension'])->order('id')->select()->toArray();
            self::assertCount(2, $retried);
            self::assertSame((int)$versions[1]['id'], (int)$retried[1]['id']);
            self::assertSame(250.0, (float)$retried[1]['data_value']);
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testBrowserCatalogHistoryDoesNotTreatRequestedDateAsObservedBusinessDate(): void
    {
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            hotel_id TEXT, hotel_name TEXT, source TEXT, platform TEXT,
            data_type TEXT, data_date TEXT, dimension TEXT, compare_type TEXT,
            amount REAL, raw_data TEXT, source_trace_id TEXT, ingestion_method TEXT,
            data_period TEXT, snapshot_time TEXT, snapshot_bucket TEXT, is_final INTEGER,
            validation_status TEXT, validation_flags TEXT, history_status TEXT,
            readback_verified INTEGER DEFAULT 0, readback_verified_at TEXT,
            create_time TEXT, update_time TEXT
        )');
        $date = date('Y-m-d');
        $base = [
            'hotel_id' => 'synthetic-80', 'hotel_name' => 'synthetic-80',
            'data_type' => 'business', 'capture_section' => 'business_overview',
            'endpoint_id' => 'business_realtime', 'amount' => 500,
        ];
        $payload = ['standard_rows' => [
            array_merge($base, [
                'dimension' => 'catalog:business_overview:business_realtime:amount:missing_date',
                'raw_data' => ['source' => 'ctrip_catalog_facts', 'metrics' => ['amount' => 500]],
            ]),
            array_merge($base, [
                'dimension' => 'catalog:business_overview:business_realtime:amount:observed_date',
                'data_date' => $date, 'date_source' => 'page.period_selection.readback',
                'raw_data' => [
                    'source' => 'ctrip_catalog_facts', 'metrics' => ['amount' => 500],
                ],
            ]),
            array_merge($base, [
                'dimension' => 'catalog:business_overview:business_realtime:amount:default_date',
                'data_date' => $date, 'date_source' => 'capture_context.default_data_date',
                'raw_data' => ['source' => 'ctrip_catalog_facts', 'metrics' => ['amount' => 500]],
            ]),
            array_merge($base, [
                'dimension' => 'catalog:business_overview:business_realtime:amount:mismatched_date',
                'data_date' => $date, 'date_source' => 'page.period_selection.readback',
                'raw_data' => [
                    'source' => 'ctrip_catalog_facts', 'metrics' => ['amount' => 500],
                    'data_date' => '2026-01-01',
                ],
            ]),
            array_merge($base, [
                'dimension' => 'catalog:business_overview:business_realtime:amount:camel_date',
                'dataDate' => $date, 'dateSource' => 'request.query.date',
                'raw_data' => ['source' => 'ctrip_catalog_facts', 'metrics' => ['amount' => 500]],
            ]),
        ]];
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $rows = (new \ReflectionMethod($controller, 'extractCtripStandardRows'))
            ->invoke($controller, $payload, 80, $date, 'synthetic-80', null, ['amount']);
        self::assertCount(5, $rows);
        self::assertSame($date, $rows[0]['data_date']);
        self::assertSame(5, (new \ReflectionMethod($controller, 'saveCtripStandardRows'))
            ->invoke($controller, $rows));
        $status = new \ReflectionMethod($controller, 'resolveHistoryStatus');
        $missing = Db::name('online_daily_data')->where('dimension', $rows[0]['dimension'])->find();
        $observed = Db::name('online_daily_data')->where('dimension', $rows[1]['dimension'])->find();
        self::assertSame(1, (int)$missing['readback_verified']);
        self::assertSame('partial', $status->invoke($controller, $missing, (string)$missing['raw_data']));
        self::assertSame('success', $status->invoke($controller, $observed, (string)$observed['raw_data']));
        $camel = Db::name('online_daily_data')->where('dimension', $rows[4]['dimension'])->find();
        self::assertSame('success', $status->invoke($controller, $camel, (string)$camel['raw_data']));
        foreach ([2, 3] as $index) {
            $untrusted = Db::name('online_daily_data')->where('dimension', $rows[$index]['dimension'])->find();
            self::assertSame('partial', $status->invoke($controller, $untrusted, (string)$untrusted['raw_data']));
        }

        Db::name('online_daily_data')->where('id', '>', 0)->update(['history_status' => 'success']);
        $columns = array_fill_keys(array_keys($missing), true);
        $expression = (new \ReflectionMethod($controller, 'onlineHistoryLightweightStatusExpression'))
            ->invoke($controller, $columns);
        foreach ($rows as $index => $row) {
            $projected = Db::name('online_daily_data')
                ->where('dimension', $row['dimension'])
                ->fieldRaw($expression . ' AS projected_status')->find();
            self::assertSame(in_array($index, [1, 4], true) ? 'success' : 'partial', $projected['projected_status']);
        }

        $recoveredRows = (new \ReflectionMethod($controller, 'extractCtripStandardRows'))
            ->invoke($controller, ['standard_rows' => [array_merge($base, [
                'dimension' => $rows[0]['dimension'], 'data_date' => $date,
                'date_source' => 'page.period_selection.readback',
                'raw_data' => ['source' => 'ctrip_catalog_facts', 'metrics' => ['amount' => 500]],
            ])]], 80, $date, 'synthetic-80', null, ['amount']);
        self::assertSame(1, (new \ReflectionMethod($controller, 'saveCtripStandardRows'))
            ->invoke($controller, $recoveredRows));
        $recovered = Db::name('online_daily_data')->where('dimension', $rows[0]['dimension'])->find();
        self::assertSame((int)$missing['id'], (int)$recovered['id']);
        self::assertSame('success', $status->invoke($controller, $recovered, (string)$recovered['raw_data']));
        $projected = Db::name('online_daily_data')->where('id', (int)$recovered['id'])
            ->fieldRaw($expression . ' AS projected_status')->find();
        self::assertSame('success', $projected['projected_status']);
    }

    public function testActualZeroCheckoutSurvivesSaveExactReadbackAndLedgerWithoutInventingAdr(): void
    {
        $result = $this->saved(['amount' => 0, 'quantity' => 0], 'zero');
        self::assertSame(0.0, (float)$result['exact'][0]['amount']);
        self::assertSame(0, (int)$result['exact'][0]['quantity']);
        self::assertSame(1, (int)$result['exact'][0]['readback_verified']);
        $fact = $result['dataset']['fact_ota_daily'][0];
        self::assertSame('ctrip_checkout_daily', $fact['metric_semantic_scope']);
        self::assertSame('verified_ctrip_checkout_sales', $fact['room_revenue_basis']);
        self::assertSame(0.0, $fact['gross_revenue']);
        self::assertSame(0.0, $fact['room_revenue']);
        self::assertSame(0.0, $fact['room_nights']);
        self::assertNull($result['metrics']['totals']['adr']);
        self::assertSame(0.0, $result['metrics']['totals']['room_revenue']);
        self::assertSame(0.0, $result['metrics']['totals']['room_nights']);
        $ledger = array_column($result['ledger']['metrics'], null, 'metric_key');
        // Old rows do not carry currency/unit proof: preserve the observation, not a verified total.
        self::assertNull($ledger['room_revenue']['value']);
        $entry = $ledger['room_revenue']['days'][0]['entries'][0];
        self::assertSame(0.0, $entry['value']);
        self::assertSame('unverified', $entry['status']);
        self::assertContains('currency_unverified_or_unsupported', $entry['reason_codes']);
        self::assertContains('amount_unit_unverified_or_unsupported', $entry['reason_codes']);
        self::assertSame('ota_channel', $ledger['room_revenue']['scope']);
        self::assertNull($ledger['settlement_amount']['value']);
    }

    public function testSourceDeclaredCurrencyAndUnitProduceVerifiedZeroAfterActualReadback(): void
    {
        $result = $this->saved(['amount' => '0.00', 'quantity' => '0',
            'currency' => 'CNY', 'amount_storage_unit' => 'yuan'], 'zero-cny');
        $metric = array_column($result['ledger']['metrics'], null, 'metric_key')['room_revenue'];
        self::assertSame('ready', $metric['status']);
        self::assertEquals(0, $metric['value']);
        self::assertSame([], $metric['missing_dates']);
        self::assertSame(['online_daily_data#' . $result['receipt']['row_ids'][0]], $metric['source_refs']);
        self::assertSame(['synthetic-ctrip-80'], $metric['platform_hotel_ids']);
        self::assertNull($result['metrics']['totals']['adr']);
    }

    public function testZeroRevenueWithPositiveRoomNightsStillHasRealZeroAdr(): void
    {
        $result = $this->saved(['amount' => 0, 'quantity' => 3], 'zero-positive-nights');
        self::assertSame(0.0, $result['metrics']['totals']['room_revenue']);
        self::assertSame(3.0, $result['metrics']['totals']['room_nights']);
        self::assertSame(0.0, $result['metrics']['totals']['adr']);
    }

    public function testPositiveCheckoutKeepsItsCurrentAmountAndAdr(): void
    {
        $result = $this->saved(['amount' => 600, 'quantity' => 3], 'positive');
        self::assertSame(600.0, $result['metrics']['totals']['room_revenue']);
        self::assertSame(200.0, $result['metrics']['totals']['adr']);
    }

    public function testMissingRoomNightsCannotBeTreatedAsConfirmedZero(): void
    {
        $result = $this->saved(['amount' => 0], 'missing-nights');
        self::assertNull($result['dataset']['fact_ota_daily'][0]['room_revenue']);
        self::assertNull($result['metrics']['totals']['adr']);
        self::assertNull(array_column($result['ledger']['metrics'], null, 'metric_key')['room_revenue']['value']);
    }

    public function testNonzeroAmountWithZeroNightsIsStillExcluded(): void
    {
        $result = $this->saved(['amount' => 600, 'quantity' => 0], 'contradictory');
        self::assertNull($result['dataset']['fact_ota_daily'][0]['room_revenue']);
        self::assertNull($result['metrics']['totals']['adr']);
        self::assertNull(array_column($result['ledger']['metrics'], null, 'metric_key')['room_revenue']['value']);
    }

    public function testMissingAmountAndNegativeNightsRemainExcluded(): void
    {
        foreach ([['quantity' => 0], ['amount' => 0, 'quantity' => -1]] as $values) {
            $result = $this->project($this->normalized($values));
            self::assertNull($result['dataset']['fact_ota_daily'][0]['room_revenue']);
            self::assertNull($result['metrics']['totals']['adr']);
        }
    }

    public function testZeroDoesNotPromoteUnsavedOrLegacySourceEvidence(): void
    {
        $rows = $this->normalized(['amount' => 0, 'quantity' => 0,
            'currency' => 'CNY', 'amount_storage_unit' => 'yuan']);
        $unsaved = $this->project($rows);
        $metric = array_column($unsaved['ledger']['metrics'], null, 'metric_key')['room_revenue'];
        self::assertNull($metric['value']);
        self::assertNotEmpty($metric['days'][0]['entries']);
        self::assertContains('source_not_verified', $metric['days'][0]['entries'][0]['reason_codes']);
        foreach (['legacy', 'mismatched_raw'] as $case) {
            $legacy = $rows;
            $raw = json_decode($legacy[0]['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            if ($case === 'legacy') unset($raw['field_facts']);
            else $raw['row']['quantity'] = 2;
            $legacy[0]['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
            self::assertNull($this->project($legacy)['dataset']['fact_ota_daily'][0]['room_revenue'], $case);
        }
        $dataset = $unsaved['dataset'];
        $service = new OtaRevenueMetricService();
        self::assertSame([], $service->ledgerEntries($dataset, 1, 81, 'ctrip'));
        self::assertSame([], $service->ledgerEntries($dataset, 1, 80, 'meituan'));
    }

    public function testCorrectionToZeroRefreshAndRetryRetainExactIdentity(): void
    {
        $rows = $this->normalized(['amount' => 600, 'quantity' => 3]);
        $columns = $this->createSchema($rows);
        $persistence = new PlatformNormalizedRowPersistenceService();
        $first = $persistence->save($rows, $columns);
        $zero = $this->normalized(['amount' => 0, 'quantity' => 0]);
        foreach (['correction', 'retry'] as $operation) {
            $receipt = $persistence->save($zero, $columns);
            self::assertTrue($receipt['readback_verified'], $operation);
            self::assertSame($first['row_ids'], $receipt['row_ids']);
            self::assertSame(1, Db::name('online_daily_data')->count());
            $exact = Db::name('online_daily_data')->whereIn('id', $receipt['row_ids'])->select()->toArray();
            $result = $this->project($exact);
            self::assertSame(0.0, $result['metrics']['totals']['room_revenue']);
            self::assertSame(0.0, $result['metrics']['totals']['room_nights']);
            self::assertNull($result['metrics']['totals']['adr']);
        }
    }
}
