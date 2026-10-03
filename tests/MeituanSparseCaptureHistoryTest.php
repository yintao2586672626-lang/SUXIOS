<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\CtripCommentsConcern;
use app\controller\concern\MeituanCapturedDataConcern;
use app\controller\concern\MeituanUtilityConcern;
use app\controller\concern\OnlineDailyDataPersistenceConcern;
use app\controller\concern\OnlineDataHistoryConcern;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use think\facade\Config;
use think\facade\Db;
use think\Response;

final class MeituanSparseCaptureHistoryTest extends TestCase
{
    private MeituanSparseCaptureHarness $capture;
    private array $originalDatabaseConfig;
    private array $originalCacheConfig;
    private array $originalLogConfig;

    protected function setUp(): void
    {
        $this->originalDatabaseConfig = Config::get('database', []);
        $this->originalCacheConfig = Config::get('cache', []);
        $this->originalLogConfig = Config::get('log', []);
        Config::set(['default' => 'file', 'stores' => ['file' => [
            'type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH'),
        ]]], 'cache');
        Config::set(['default' => 'fixture', 'channels' => ['fixture' => [
            'type' => 'File', 'close' => true, 'path' => (string)getenv('SUXIOS_CACHE_PATH'),
        ]]], 'log');
        Config::set(['default' => 'sparse_capture_fixture', 'connections' => ['sparse_capture_fixture' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute('INSERT INTO hotels VALUES (901, 31), (902, 32)');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            source TEXT, platform TEXT, hotel_id TEXT, hotel_name TEXT, data_type TEXT, data_date TEXT,
            dimension TEXT, compare_type TEXT, data_period TEXT, snapshot_time TEXT, snapshot_bucket TEXT,
            is_final INTEGER, amount REAL, quantity INTEGER, book_order_num INTEGER, comment_score REAL,
            qunar_comment_score REAL, data_value REAL, list_exposure INTEGER, detail_exposure INTEGER,
            flow_rate REAL, order_submit_num INTEGER, raw_data TEXT, create_time TEXT, update_time TEXT,
            readback_verified INTEGER DEFAULT 0, readback_verified_at TEXT
        )');
        $this->capture = new MeituanSparseCaptureHarness();
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalDatabaseConfig, 'database');
        Config::set($this->originalCacheConfig, 'cache');
        Config::set($this->originalLogConfig, 'log');
    }

    public function testSparseRecaptureDoesNotEraseValidHistoricalMetricsOrTheirEvidence(): void
    {
        $original = $this->payload($this->completeMetrics(), '2026-09-02 08:00:00');
        self::assertSame(1, $this->capture->saveRows($this->capture->buildRows($original, 901)));
        $before = Db::name('online_daily_data')->where('system_hotel_id', 901)->find();
        self::assertIsArray($before);
        self::assertSame(400.0, (float)$before['amount']);
        self::assertSame(1, (int)$before['readback_verified']);
        $historyResponse = $this->capture->historyDetail((int)$before['id'])->getData();
        self::assertSame(200, $historyResponse['code'], $historyResponse['message']);
        $historyBefore = $historyResponse['data'];

        $partial = $this->payload(['exposure_users' => 123], '2026-09-02 09:00:00');
        $rows = $this->capture->buildRows($partial, 901);
        self::assertNull($rows[0]['amount'], 'A missing source value must remain missing before persistence');
        $error = null;
        try { $this->capture->saveRows($rows); }
        catch (\Throwable $caught) { $error = $caught; }
        $after = Db::name('online_daily_data')->where('id', $before['id'])->find();
        self::assertSame($before['amount'], $after['amount'], 'Sparse recapture erased observed historical revenue');
        self::assertSame($before['quantity'], $after['quantity']);
        self::assertSame($before['data_value'], $after['data_value']);
        self::assertSame($before['raw_data'], $after['raw_data'], 'Old field evidence cannot be relabeled as the new capture');
        self::assertSame($historyBefore, $this->capture->historyDetail((int)$before['id'])->getData()['data']);
        self::assertNotNull($error, 'A rejected destructive capture must not claim a successful save');
        $rendered = (new \app\ExceptionHandle(\think\Container::getInstance()))->render(
            new class { public function pathinfo(): string { return 'api/online-data/save-meituan-captured-data'; } }, $error
        );
        self::assertSame(400, $rendered->getCode());
        self::assertStringContainsString('未覆盖', $rendered->getData()['message']);
        self::assertStringContainsString('重采', $rendered->getData()['message']);
    }

    public function testAnEmptyCaptureDoesNotWriteOrDeleteHistoricalRows(): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        $before = Db::name('online_daily_data')->select()->toArray();
        $rows = $this->capture->buildRows($this->payload([]), 901);
        self::assertSame([], $rows);
        self::assertSame(0, $this->capture->saveRows($rows));
        self::assertSame($before, Db::name('online_daily_data')->select()->toArray());
    }

    public static function missingVariants(): array
    {
        return ['explicit null' => [null], 'blank' => [''], 'nonnumeric' => ['-']];
    }

    #[DataProvider('missingVariants')]
    public function testUnavailableValuesNeverClearOldFacts(mixed $unavailable): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        $before = Db::name('online_daily_data')->select()->toArray();
        $metrics = array_fill_keys(array_keys($this->completeMetrics()), $unavailable);
        $metrics['exposure_users'] = 123;
        $error = null;
        try { $this->capture->saveRows($this->capture->buildRows($this->payload($metrics), 901)); }
        catch (\Throwable $caught) { $error = $caught; }
        self::assertSame($before, Db::name('online_daily_data')->select()->toArray());
        self::assertInstanceOf(\think\exception\ValidateException::class, $error);
    }

    public function testRawOnlyMetricEvidenceCannotDisappearFromAnOtherwiseCompleteRecapture(): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        $before = Db::name('online_daily_data')->select()->toArray();
        $metrics = $this->completeMetrics();
        unset($metrics['lead_price']);
        $error = null;
        try { $this->capture->saveRows($this->capture->buildRows($this->payload($metrics), 901)); }
        catch (\Throwable $caught) { $error = $caught; }
        self::assertSame($before, Db::name('online_daily_data')->select()->toArray());
        self::assertInstanceOf(\think\exception\ValidateException::class, $error);
    }

    public function testObservedZeroCanReplaceOldValuesWithOnlyNewCaptureEvidence(): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        $oldId = (int)Db::name('online_daily_data')->where('system_hotel_id', 901)->value('id');
        $zeros = array_fill_keys(array_keys($this->completeMetrics()), 0);
        self::assertSame(1, $this->capture->saveRows($this->capture->buildRows(
            $this->payload($zeros, '2026-09-02 09:00:00'), 901
        )));
        $stored = Db::name('online_daily_data')->where('id', $oldId)->find();
        foreach (['amount', 'quantity', 'data_value', 'list_exposure', 'detail_exposure', 'flow_rate'] as $field) {
            self::assertSame(0.0, (float)$stored[$field], $field);
        }
        self::assertSame(1, (int)$stored['readback_verified']);
        self::assertSame(1, Db::name('online_daily_data')->count());
        $raw = json_decode($stored['raw_data'], true);
        self::assertSame('2026-09-02 09:00:00', $raw['_capture_context']['captured_at']);
        self::assertSame(0.0, (float)$raw['lead_price']);
        $facts = array_column($raw['field_facts'], null, 'metric_key');
        self::assertArrayHasKey('sales_amount', $facts);
        self::assertStringContainsString('sales_amount', $facts['sales_amount']['source_path']);
    }

    public function testRejectedRecaptureRollsBackEarlierRowsInTheSameBatch(): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        $before = Db::name('online_daily_data')->select()->toArray();
        $other = $this->payload($this->completeMetrics());
        $other['business'][0]['data_date'] = '2026-08-31';
        $rows = array_merge($this->capture->buildRows($other, 901),
            $this->capture->buildRows($this->payload(['exposure_users' => 123]), 901));
        $error = null;
        try { $this->capture->saveRows($rows); }
        catch (\Throwable $caught) { $error = $caught; }
        self::assertInstanceOf(\think\exception\ValidateException::class, $error);
        self::assertSame($before, Db::name('online_daily_data')->select()->toArray());
    }

    public function testNewPartialRowsStaySeparateAcrossHotelPoiDateAndPeriod(): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        $before = Db::name('online_daily_data')->where('system_hotel_id', 901)->find();
        foreach (['hotel', 'poi', 'date', 'period'] as $scope) {
            $payload = $this->payload(['exposure_users' => 123]);
            $systemHotel = $scope === 'hotel' ? 902 : 901;
            if ($scope === 'poi') $payload['poi_id'] = 'synthetic-other-poi';
            if ($scope === 'date') $payload['business'][0]['data_date'] = '2026-08-31';
            if ($scope === 'period') {
                $payload['data_period'] = 'realtime_snapshot';
                $payload['snapshot_time'] = '2026-09-02 09:00:00';
            }
            self::assertSame(1, $this->capture->saveRows($this->capture->buildRows($payload, $systemHotel)), $scope);
            self::assertSame($before, Db::name('online_daily_data')->where('id', $before['id'])->find(), $scope);
            $partial = Db::name('online_daily_data')->where('id', '>', $before['id'])->order('id', 'desc')->find();
            self::assertNull($partial['amount']);
            $status = \app\service\OnlineDataFieldFactService::buildMetricStatus(
                $partial, json_decode($partial['raw_data'], true), ['sales_amount', 'sales_room_nights']
            );
            self::assertSame('not_loaded', $status['status'], 'DB readback proof must not invent missing revenue evidence');
            self::assertSame(['sales_amount', 'sales_room_nights'], $status['missing_requested_metric_keys']);
        }
        self::assertSame(5, Db::name('online_daily_data')->count());
        self::assertSame(32, (int)Db::name('online_daily_data')->where('system_hotel_id', 902)->value('tenant_id'));
    }

    public function testOtherSourceAndLegacyEvidenceRemainUntouched(): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        Db::name('online_daily_data')->where('source', 'meituan')->update([
            'source' => 'ctrip', 'platform' => 'Ctrip', 'raw_data' => '{"synthetic_legacy":true}',
        ]);
        $ctrip = Db::name('online_daily_data')->where('source', 'ctrip')->find();
        self::assertSame(1, $this->capture->saveRows($this->capture->buildRows($this->payload(['exposure_users' => 123]), 901)));
        self::assertSame($ctrip, Db::name('online_daily_data')->where('id', $ctrip['id'])->find());
        Db::name('online_daily_data')->where('source', 'meituan')->update([
            'amount' => 99, 'readback_verified' => 0, 'raw_data' => '{"synthetic_legacy":true}',
        ]);
        $before = Db::name('online_daily_data')->where('source', 'meituan')->find();
        try { $this->capture->saveRows($this->capture->buildRows($this->payload(['exposure_users' => 123]), 901)); }
        catch (\think\exception\ValidateException) {}
        self::assertSame($before, Db::name('online_daily_data')->where('id', $before['id'])->find());
    }

    public function testStorageFailureRollsBackUpdatedHistoryAndItsProof(): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        $before = Db::name('online_daily_data')->select()->toArray();
        $updated = $this->payload(array_replace($this->completeMetrics(), ['sales_amount' => 450]));
        $new = $this->payload($this->completeMetrics());
        $new['business'][0]['data_date'] = '2026-08-31';
        Db::execute("CREATE TRIGGER synthetic_capture_failure BEFORE INSERT ON online_daily_data
            WHEN NEW.data_date = '2026-08-31' BEGIN SELECT RAISE(ABORT, 'synthetic capture insert failure'); END");
        $error = null;
        try { $this->capture->saveRows(array_merge($this->capture->buildRows($updated, 901), $this->capture->buildRows($new, 901))); }
        catch (\Throwable $caught) { $error = $caught; }
        self::assertNotNull($error);
        self::assertStringContainsString('synthetic capture insert failure', $error->getMessage());
        self::assertSame($before, Db::name('online_daily_data')->select()->toArray());
    }

    public function testReadbackMismatchCannotCommitAnUnverifiedReplacementOfValidHistory(): void
    {
        $this->capture->saveRows($this->capture->buildRows($this->payload($this->completeMetrics()), 901));
        $before = Db::name('online_daily_data')->select()->toArray();
        Db::execute("CREATE TRIGGER synthetic_readback_mismatch AFTER UPDATE ON online_daily_data
            WHEN NEW.amount = 450 BEGIN UPDATE online_daily_data SET amount = 999 WHERE id = NEW.id; END");
        $rows = $this->capture->buildRows($this->payload(array_replace($this->completeMetrics(), ['sales_amount' => 450])), 901);
        $error = null;
        try { $this->capture->saveRows($rows); }
        catch (\Throwable $caught) { $error = $caught; }
        self::assertSame($before, Db::name('online_daily_data')->select()->toArray(), 'Failed readback must preserve historical facts and proof');
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('meituan_capture_readback_failed', $error->getMessage());
    }

    private function completeMetrics(): array
    {
        return ['sales_amount' => 400, 'sales_room_nights' => 2, 'sales_avg_price' => 200,
            'exposure_users' => 100, 'detail_visitors' => 20, 'paid_order_count' => 1,
            'lead_price' => 150, 'browse_to_pay_rate' => 5];
    }

    private function payload(array $metrics, string $capturedAt = '2026-09-02 08:00:00'): array
    {
        return ['poi_id' => 'synthetic-poi-901', 'poi_name' => 'Synthetic hotel',
            'captured_at' => $capturedAt, 'data_period' => 'historical_daily',
            'business' => [array_merge(['data_date' => '2026-09-01',
                '_source_path' => '$.business[0]', '_capture_source' => 'synthetic-business'], $metrics)]];
    }
}

final class MeituanSparseCaptureHarness
{
    use MeituanCapturedDataConcern {
        buildMeituanCapturedDailyRows as public buildRows;
        saveMeituanCapturedDailyRows as public saveRows;
    }
    use MeituanUtilityConcern;
    use CtripCommentsConcern;
    use OnlineDailyDataPersistenceConcern;
    use OnlineDataHistoryConcern;

    public object $request;
    public function __construct()
    {
        // A synthetic authorized viewer; no real authentication is exercised.
        $this->request = (object)['user' => new class { public function isSuperAdmin(): bool { return true; } }];
    }
    private function getOnlineDailyDataColumns(): array
    {
        // SQLite schema discovery only; normalization, write, proof, detail and field-fact code are production traits.
        return array_fill_keys(array_column(Db::query('PRAGMA table_info(online_daily_data)'), 'name'), true);
    }
    private function getConfiguredHotelNameMap(): array { return [901 => 'Synthetic hotel', 902 => 'Synthetic other hotel']; }
    private function success(mixed $data = null, string $message = 'success'): Response
    {
        return Response::create(['code' => 200, 'message' => $message, 'data' => $data], 'json');
    }
    private function error(string $message, int $code = 500): Response
    {
        return Response::create(['code' => $code, 'message' => $message, 'data' => null], 'json');
    }
}
