<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\controller\concern\CtripOverviewRequestConcern;
use app\service\CtripOverviewSummaryService;
use app\service\OnlineDailyDataPersistenceService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class CtripFlowOverviewTaskTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): object
    {
        return (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
    }

    private function request(): array
    {
        return ['request_source' => 'flow_overview', 'config_id' => 'synthetic_7',
            'system_hotel_id' => 7, 'hotel_id' => '9988', 'data_date' => '2026-09-26'];
    }

    private function task(): array
    {
        return $this->invokeNonPublic($this->controller(), 'buildCtripFlowOverviewTask', [
            $this->request(), ['system_hotel_id' => 7, 'ctrip_hotel_id' => '9988'], 7,
        ]);
    }

    private function row(string $hotel = '9988', array $changes = []): array
    {
        return array_replace(['hotelId' => $hotel, 'date' => '2026-09-26',
            'listExposure' => 100, 'detailExposure' => 20,
            'orderFillingNum' => 5, 'orderSubmitNum' => 0], $changes);
    }

    public function testTaskNeedsNoClientEndpointAndUsesOnlyDatedFunnelPost(): void
    {
        self::assertSame($this->request(), $this->invokeNonPublic($this->controller(),
            'sanitizeCtripOverviewExecutionRequestData', [$this->request()]));
        $task = $this->task();
        self::assertSame('POST', $task['method']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking', $task['url']);
        self::assertSame('Ctrip', $task['payload']['platform']);
        self::assertSame('9988', $task['payload']['hotelId']);
        foreach (['startDate', 'endDate', 'dataDate'] as $field) {
            self::assertSame('2026-09-26', $task['payload'][$field]);
        }
    }

    public function testTaskRejectsUnknownSourceMixedEndpointsAndInvalidScope(): void
    {
        $badRequests = [array_replace($this->request(), ['request_source' => 'unknown']),
            array_replace($this->request(), ['request_source' => '']),
            $this->request() + ['url' => 'https://example.invalid'],
            $this->request() + ['request_urls' => []], $this->request() + ['method' => 'POST']];
        foreach ($badRequests as $request) {
            try {
                $this->invokeNonPublic($this->controller(), 'sanitizeCtripOverviewExecutionRequestData', [$request]);
                self::fail('Task must reject unknown source or client execution material');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        foreach ([['hotel_id' => '9999'], ['data_date' => '2026-02-30'], ['data_date' => ''], ['system_hotel_id' => 8]] as $change) {
            try {
                $this->invokeNonPublic($this->controller(), 'buildCtripFlowOverviewTask', [
                    array_replace($this->request(), $change), ['system_hotel_id' => 7, 'ctrip_hotel_id' => '9988'], 7,
                ]);
                self::fail('Invalid task scope must not execute');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testLegacyExplicitUrlsRemainAccepted(): void
    {
        $request = ['config_id' => 'synthetic_7', 'system_hotel_id' => 7,
            'request_urls' => ['https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate'],
            'method' => 'GET', 'hotel_id' => '9988', 'data_date' => '2026-09-26'];
        self::assertSame($request, $this->invokeNonPublic($this->controller(), 'sanitizeCtripOverviewExecutionRequestData', [$request]));
        self::assertSame(0, CtripOverviewSummaryService::summarizeRows([])['self_list_exposure']);
    }

    public function testStrictSummaryDoesNotManufactureZeroForMissingRows(): void
    {
        $projection = CtripOverviewSummaryService::projectFlowOverview([], '9988', '2026-09-26');
        self::assertSame([], $projection['rows']);
        self::assertSame('empty', $projection['status']);
        self::assertCount(14, $projection['metrics']);
        foreach ($projection['metrics'] as $value) self::assertNull($value);
    }

    public function testOwnAndCircleFactsKeepTrueZeroAndSameRowRatios(): void
    {
        $projection = CtripOverviewSummaryService::projectFlowOverview(['data' => [
            $this->row(), $this->row('-1', ['listExposure' => 200, 'detailExposure' => 1, 'orderFillingNum' => 1]),
        ]], '9988', '2026-09-26');
        self::assertCount(2, $projection['rows']);
        self::assertSame('queryFlowTransforNewV1', $projection['rows'][0]['_endpoint_id']);
        self::assertSame('data.0', $projection['rows'][0]['_source_path']);
        self::assertSame('ready', $projection['status']);
        self::assertSame(0, $projection['metrics']['self_order_submit_num']);
        self::assertSame(0.0, $projection['metrics']['self_deal_rate']);
        self::assertSame(0.5, $projection['metrics']['competitor_flow_rate']);
        self::assertSame(20.0, $projection['metrics']['self_flow_rate']);
        self::assertArrayNotHasKey('flowRate', $projection['rows'][1], 'Do not let legacy percent normalization reinterpret a derived sub-one percent');
    }

    public function testPartialAndZeroDenominatorStayUnavailable(): void
    {
        $projection = CtripOverviewSummaryService::projectFlowOverview([
            $this->row('9988', ['listExposure' => null, 'orderFillingNum' => 0]),
            $this->row('-1', ['listExposure' => 0, 'detailExposure' => 0]),
        ], '9988', '2026-09-26');
        self::assertSame('partial', $projection['status']);
        self::assertNull($projection['metrics']['self_list_exposure']);
        self::assertNull($projection['metrics']['self_flow_rate']);
        self::assertNull($projection['metrics']['self_deal_rate']);
        self::assertNull($projection['metrics']['competitor_flow_rate']);
        self::assertSame(0, $projection['metrics']['competitor_list_exposure']);
        self::assertSame(0.0, $projection['metrics']['self_order_fill_rate']);
    }

    public function testWrongDateHotelPlatformUndatedAndConflictingRowsNeverBecomeOwnFacts(): void
    {
        foreach (['date' => '2026-09-25', 'hotelId' => '9999', 'platform' => 'Qunar'] as $key => $value) {
            $projection = CtripOverviewSummaryService::projectFlowOverview([$this->row('9988', [$key => $value])], '9988', '2026-09-26');
            self::assertSame([], $projection['rows']);
            self::assertNull($projection['metrics']['self_list_exposure']);
            self::assertNotEmpty($projection['gaps']);
        }
        $row = $this->row(); unset($row['date']);
        self::assertSame([], CtripOverviewSummaryService::projectFlowOverview([$row], '9988', '2026-09-26')['rows']);
        $conflict = CtripOverviewSummaryService::projectFlowOverview([$this->row(), $this->row('9988', ['detailExposure' => 99])], '9988', '2026-09-26');
        self::assertSame([], $conflict['rows']);
        self::assertNull($conflict['metrics']['self_flow_rate']);
    }

    public function testExplicitBusinessFailureNeverConsumesNestedRows(): void
    {
        foreach ([['success' => false], ['rcode' => 9], ['ResponseStatus' => ['Ack' => 'Failure']]] as $failure) {
            $projection = CtripOverviewSummaryService::projectFlowOverview($failure + ['data' => [$this->row()]], '9988', '2026-09-26');
            self::assertSame('blocked', $projection['status']);
            self::assertSame([], $projection['rows']);
        }
    }

    public function testInvalidCountsRemainMissingAndIdenticalDuplicatesDoNotDoubleSave(): void
    {
        foreach ([-1, 1.5, 'not numeric', ' ', [], false, INF, NAN] as $value) {
            $projection = CtripOverviewSummaryService::projectFlowOverview([
                $this->row('9988', ['listExposure' => $value]),
            ], '9988', '2026-09-26');
            self::assertSame('partial', $projection['status']);
            self::assertNull($projection['metrics']['self_list_exposure']);
            self::assertNull($projection['metrics']['self_flow_rate']);
            self::assertSame(20, $projection['metrics']['self_detail_exposure']);
        }
        $projection = CtripOverviewSummaryService::projectFlowOverview([
            $this->row(), $this->row('9988', ['listExposure' => '100', 'orderSubmitNum' => '0']),
        ], '9988', '2026-09-26');
        self::assertCount(1, $projection['rows']);
        self::assertSame(100, $projection['metrics']['self_list_exposure']);
        self::assertSame(0, $projection['metrics']['self_order_submit_num']);
    }

    public function testMissingOrOtherSystemHotelBindingDoesNotExecute(): void
    {
        foreach ([[], ['system_hotel_id' => 7], ['system_hotel_id' => 8, 'ctrip_hotel_id' => '9988']] as $config) {
            try {
                $this->invokeNonPublic($this->controller(), 'buildCtripFlowOverviewTask', [$this->request(), $config, 7]);
                self::fail('A matching credential locator is not sufficient without a matching OTA hotel binding');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testActualTaskExecutionPassesScopedTrafficRowsAndRequiresAllReadbacks(): void
    {
        $harness = new CtripFlowOverviewOfflineHarness();
        $harness->response = [$this->row(), $this->row('-1')];
        $harness->savedCount = 2;
        $result = $harness->run($this->task());
        self::assertSame(200, $result['code']);
        self::assertSame('readback_verified', $result['data']['persistence_status']);
        self::assertTrue($result['data']['readback_verified']);
        self::assertSame(['2026-09-26', '2026-09-26', 'ctrip', 7, 'ctrip', '9988', 'cookie_api', true], array_slice($harness->savedArgs, 1));
        self::assertSame('POST', $harness->transportArgs[3]);
        self::assertSame(0, $result['data']['metrics']['self_order_submit_num']);
        self::assertStringNotContainsString('synthetic-credential', json_encode($result));
        $harness->savedCount = 1;
        $failed = $harness->run($this->task());
        self::assertSame(500, $failed['code']);
        self::assertFalse($failed['data']['readback_verified']);
        self::assertSame('readback_not_verified', $failed['data']['persistence_status']);
    }

    public function testTransportOrNoUsableRowsNeverReportsSavedSuccess(): void
    {
        $harness = new CtripFlowOverviewOfflineHarness();
        $harness->transportError = 'synthetic upstream error';
        self::assertNotSame(200, $harness->run($this->task())['code']);
        self::assertSame([], $harness->savedArgs);
        $harness->transportError = '';
        $result = $harness->run($this->task());
        self::assertNotSame(200, $result['code']);
        self::assertSame('no_parsed_rows', $result['data']['persistence_status']);
        self::assertNull($result['data']['metrics']['self_list_exposure']);
        self::assertSame([], $harness->savedArgs);
    }

    private function isolatedDatabase(): void
    {
        \think\facade\Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        \think\facade\Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH') . '/logs/']]], 'log');
        \think\facade\Config::set(['default' => 'flow_task_test', 'connections' => ['flow_task_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        \think\facade\Db::connect(null, true);
        \think\facade\Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        \think\facade\Db::name('hotels')->insertAll([['id' => 7, 'tenant_id' => 2], ['id' => 8, 'tenant_id' => 3]]);
        \think\facade\Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            hotel_id TEXT, hotel_name TEXT, source TEXT, platform TEXT, data_type TEXT, dimension TEXT, compare_type TEXT, data_date TEXT,
            list_exposure REAL, detail_exposure REAL, order_filling_num REAL, order_submit_num REAL, data_value REAL, flow_rate REAL,
            raw_data TEXT, validation_status TEXT, validation_flags TEXT, readback_verified INTEGER DEFAULT 0, readback_verified_at TEXT,
            ingestion_method TEXT, source_trace_id TEXT, data_period TEXT, is_final INTEGER, snapshot_time TEXT, update_time TEXT, create_time TEXT)');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testActualStrictSnapshotClearsMissingCountsAndPreservesOtherScopesAndLegacySparseUpdates(): void
    {
        $this->isolatedDatabase();
        $service = new OnlineDailyDataPersistenceService();
        foreach ([[8, 'ctrip', '2026-09-26'], [7, 'qunar', '2026-09-26'], [7, 'ctrip', '2026-09-25']] as [$hotel, $source, $date]) {
            self::assertSame(1, $service->parseAndSaveTrafficData([$this->row('9988', ['date' => $date])], $date, $date, $source, $hotel, $source, '9988', 'cookie_api'));
        }
        $untouched = \think\facade\Db::name('online_daily_data')->order('id')->select()->toArray();
        $harness = new CtripFlowOverviewOfflineHarness();
        $harness->useRealPersistence = true;
        $harness->response = [$this->row(), $this->row('-1')];
        $complete = $harness->run($this->task());
        self::assertSame(200, $complete['code']);
        self::assertSame(2, $complete['data']['saved_count']);
        $ownId = (int)\think\facade\Db::name('online_daily_data')->where('system_hotel_id', 7)->where('source', 'ctrip')->where('data_date', '2026-09-26')->where('hotel_id', '9988')->value('id');
        $harness->response = [$this->row('9988', ['listExposure' => null, 'detailExposure' => 0]), $this->row('-1')];
        $partial = $harness->run($this->task());
        self::assertSame(200, $partial['code']);
        self::assertSame('partial', $partial['data']['status']);
        self::assertSame(2, $partial['data']['saved_count']);
        $stored = \think\facade\Db::name('online_daily_data')->where('id', $ownId)->find();
        self::assertNull($stored['list_exposure']);
        self::assertNull($stored['data_value']);
        self::assertSame(0.0, (float)$stored['detail_exposure']);
        self::assertSame(0.0, (float)$stored['order_submit_num']);
        self::assertSame(1, (int)$stored['readback_verified']);
        self::assertSame(2, (int)$stored['tenant_id']);
        self::assertSame('queryFlowTransforNewV1', json_decode($stored['raw_data'], true)['_endpoint_id']);
        $display = \app\service\CtripTrafficDisplayService::buildCtripTrafficDisplayRows([$stored]);
        self::assertNull($display[0]['listExposure']);
        self::assertNull($display[0]['flowRate']);
        self::assertSame(0.0, $display[0]['detailExposure']);
        self::assertSame('normal', $stored['validation_status'], 'Readback remains distinct from completeness and field verification');
        self::assertSame($untouched, \think\facade\Db::name('online_daily_data')->where('id', '<', $ownId)->order('id')->select()->toArray());

        // Legacy callers still merge partial observations into their prior row.
        $service->parseAndSaveTrafficData([$this->row()], '2026-09-26', '2026-09-26', 'ctrip', 7, 'ctrip', '9988', 'cookie_api');
        $service->parseAndSaveTrafficData([$this->row('9988', ['listExposure' => null])], '2026-09-26', '2026-09-26', 'ctrip', 7, 'ctrip', '9988', 'cookie_api');
        self::assertSame(100.0, (float)\think\facade\Db::name('online_daily_data')->where('id', $ownId)->value('list_exposure'));

        $harness->response = [$this->row('9988', ['listExposure' => 200, 'detailExposure' => 1, 'orderFillingNum' => 1])];
        $fraction = $harness->run($this->task());
        $fractionStored = \think\facade\Db::name('online_daily_data')->where('id', $ownId)->find();
        self::assertNull($fractionStored['flow_rate']);
        self::assertSame(0.5, $fraction['data']['metrics']['self_flow_rate']);
        self::assertSame(0.5, \app\service\CtripTrafficDisplayService::buildCtripTrafficDisplayRows([$fractionStored])[0]['flowRate']);

        $harness->response = [];
        $failure = $harness->run($this->task());
        $fixturePath = trim((string)getenv('SUXIOS_CTRIP_FLOW_FIXTURE_PATH'));
        if ($fixturePath !== '') {
            file_put_contents($fixturePath, json_encode(['synthetic' => true, 'success_zero' => $complete,
                'partial' => $partial, 'failure' => $failure], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStrictReadbackChecksNullInsteadOfOnlyObservedNonNullMetrics(): void
    {
        $this->isolatedDatabase();
        $harness = new CtripFlowOverviewOfflineHarness();
        $harness->useRealPersistence = true;
        $harness->response = [$this->row()];
        self::assertSame(200, $harness->run($this->task())['code']);
        \think\facade\Db::execute('CREATE TRIGGER keep_stale_exposure AFTER UPDATE ON online_daily_data
            WHEN NEW.list_exposure IS NULL BEGIN UPDATE online_daily_data SET list_exposure = 777 WHERE id = NEW.id; END');
        $harness->response = [$this->row('9988', ['listExposure' => null])];
        $result = $harness->run($this->task());
        self::assertSame(500, $result['code']);
        self::assertFalse($result['data']['readback_verified']);
        self::assertSame(0, $result['data']['saved_count']);
        self::assertSame(0, (int)\think\facade\Db::name('online_daily_data')->value('readback_verified'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStoredStrictZeroDenominatorsStayNullThroughDisplaySummaryAndAnalysisWhileLegacyStaysCompatible(): void
    {
        $this->isolatedDatabase();
        $harness = new CtripFlowOverviewOfflineHarness();
        $harness->useRealPersistence = true;
        $zero = ['listExposure' => 0, 'detailExposure' => 0, 'orderFillingNum' => 0, 'orderSubmitNum' => 0];
        $harness->response = [$this->row('9988', $zero), $this->row('-1', $zero)];
        $response = $harness->run($this->task());
        self::assertSame(200, $response['code']);
        self::assertSame(2, $response['data']['saved_count']);
        $stored = \think\facade\Db::name('online_daily_data')->order('id')->select()->toArray();
        $display = \app\service\CtripTrafficDisplayService::buildCtripTrafficDisplayRows($stored);
        foreach ($display as $row) {
            self::assertNull($row['flowRate']);
            self::assertNull($row['orderFillRate']);
            self::assertNull($row['submitRate']);
            self::assertSame(0.0, $row['listExposure']);
            self::assertSame(0.0, $row['orderSubmitNum']);
        }
        self::assertSame('flow_overview', json_decode($stored[0]['raw_data'], true)['request_source']);
        $summary = \app\service\CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($display);
        foreach (['self', 'avg'] as $scope) {
            self::assertNull($summary[$scope]['flowRate']);
            self::assertNull($summary[$scope]['orderFillRate']);
            self::assertNull($summary[$scope]['submitRate']);
        }
        $analysis = \app\service\CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis($stored);
        self::assertSame('partial', $analysis['status']);
        self::assertNull($analysis['summary']);
        self::assertSame([], $analysis['recommendations']);
        $legacy = \app\service\CtripTrafficDisplayService::buildCtripTrafficDisplayRows([$this->row('9988', $zero)]);
        self::assertSame(0.0, $legacy[0]['flowRate']);
        self::assertSame(0.0, \app\service\CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($legacy)['self']['flowRate']);

        $harness->response = [$this->row(), $this->row('-1')];
        self::assertSame(200, $harness->run($this->task())['code']);
        $recovered = \think\facade\Db::name('online_daily_data')->order('id')->select()->toArray();
        $current = \app\service\CtripTrafficDisplayService::buildCtripTrafficDisplayRows($recovered);
        self::assertSame(0.0, $current[0]['submitRate']);
        self::assertSame('ready', \app\service\CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis($recovered)['status']);
    }
}

/** Only transport and persistence are replaced: no App.initialize, network or database. */
final class CtripFlowOverviewOfflineHarness
{
    use CtripOverviewRequestConcern;
    public array $response = [];
    public string $transportError = '';
    public array $transportArgs = [];
    public array $savedArgs = [];
    public int $savedCount = 0;
    public bool $useRealPersistence = false;

    public function run(array $task): array
    {
        return $this->executeCtripFlowOverviewTask($task, ['cookies' => 'synthetic-credential'], 7)->getData();
    }

    private function sendCtripOverviewRequest(string $url, array $payload, string $cookies, string $method, string $spidertoken = ''): array
    {
        $this->transportArgs = func_get_args();
        return ['http_code' => $this->transportError === '' ? 200 : 500,
            'decoded_data' => $this->response, 'error' => $this->transportError];
    }

    private function parseAndSaveTrafficData(...$args): int
    {
        $this->savedArgs = $args;
        return $this->useRealPersistence ? (new OnlineDailyDataPersistenceService())->parseAndSaveTrafficData(...$args) : $this->savedCount;
    }
}
