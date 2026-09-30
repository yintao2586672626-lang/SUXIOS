<?php
declare(strict_types=1);

namespace Tests;

use app\controller\MacroSignal;
use app\model\User;
use app\service\MacroSignalService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;

final class MacroSignalZeroSalesTest extends TestCase
{
    use ReflectionHelper;
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
    }

    #[DataProvider('salesCases')]
    public function testRecordedZeroIsDistinctFromMissingAndNonSalesMetrics(array $values, ?float $revenue, ?float $rooms): void
    {
        $row = $values + ['system_hotel_id' => 121, 'data_date' => '2026-05-01', 'source' => 'ctrip',
            'hotel_name' => '我的酒店', 'data_type' => 'business', 'raw_data' => '{}'];
        $series = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendSeries', [[], [$row], [], '2026-05-01', '2026-05-02']);
        self::assertSame($revenue, $series['rows'][0]['revenue']);
        self::assertSame($rooms, $series['rows'][0]['room_nights']);
        self::assertSame($revenue !== null || $rooms !== null, $series['rows'][0]['has_sample']);
        self::assertNull($series['rows'][1]['revenue']);
        self::assertNull($series['rows'][1]['room_nights']);
        self::assertFalse($series['rows'][1]['has_sample']);
    }

    public static function salesCases(): array
    {
        return [
            'both recorded zero' => [['amount' => 0, 'quantity' => 0], 0.0, 0.0],
            'zero revenue positive rooms' => [['amount' => 0, 'quantity' => 2], 0.0, 2.0],
            'positive revenue zero rooms' => [['amount' => 120, 'quantity' => 0], 120.0, 0.0],
            'formatted zero' => [['amount' => '￥0.00', 'quantity' => '0'], 0.0, 0.0],
            'only known revenue' => [['amount' => 0], 0.0, null],
            'only known rooms' => [['quantity' => 0], null, 0.0],
            'missing metrics' => [[], null, null],
            'invalid metrics' => [['amount' => 'unknown', 'quantity' => -1], null, null],
            'nonfinite metrics' => [['amount' => INF, 'quantity' => NAN], null, null],
            'traffic defaults' => [['data_type' => 'traffic', 'dimension' => '曝光', 'amount' => 0, 'quantity' => 0], null, null],
            'legacy traffic defaults' => [['data_type' => '', 'dimension' => 'semantic:ctrip:visitors', 'amount' => 0, 'quantity' => 0], null, null],
            'legacy sales zero' => [['data_type' => '', 'dimension' => '', 'amount' => 0, 'quantity' => 0], 0.0, 0.0],
            'competitor zero' => [['data_type' => 'competitor', 'amount' => 0, 'quantity' => 0], null, null],
            'outside date' => [['data_date' => '2026-04-30', 'amount' => 0, 'quantity' => 0], null, null],
        ];
    }

    public function testActualSqliteSaveAndControllerReadbackRetainZeroAndHotelDateScope(): void
    {
        $app = self::$app;
        $original = Config::get('database', []);
        $connection = 'macro_zero_' . bin2hex(random_bytes(6));
        Config::set(['default' => $connection, 'connections' => [$connection => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => true,
        ]]], 'database');
        Db::connect(null, true);
        try {
            Db::execute('CREATE TABLE daily_reports (hotel_id INTEGER, report_date TEXT, status INTEGER, report_data TEXT, occupancy_rate REAL, room_count INTEGER, revenue REAL)');
            Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, system_hotel_id INTEGER, data_date TEXT, hotel_name TEXT, amount REAL, quantity REAL, book_order_num REAL, raw_data TEXT, source TEXT, dimension TEXT, data_type TEXT, data_value REAL, compare_type TEXT)');
            Db::execute('CREATE TABLE competitor_price_log (id INTEGER, store_id INTEGER, price REAL, fetch_time TEXT, create_time TEXT)');
            Db::execute('CREATE TABLE demand_forecasts (hotel_id INTEGER, forecast_date TEXT, predicted_occupancy REAL, predicted_demand REAL, confidence_score REAL, event_type TEXT)');
            $base = ['system_hotel_id' => 121, 'hotel_name' => '我的酒店', 'source' => 'ctrip',
                'data_type' => 'business', 'dimension' => 'semantic:ctrip:room_revenue', 'raw_data' => '{"source_kind":"synthetic_fixture"}', 'book_order_num' => 0];
            Db::name('online_daily_data')->insertAll([
                ['id' => 1, 'data_date' => '2026-05-01', 'amount' => 0, 'quantity' => 0] + $base,
                ['id' => 2, 'data_date' => '2026-05-02', 'amount' => 120, 'quantity' => 2] + $base,
                ['id' => 3, 'system_hotel_id' => 122, 'data_date' => '2026-05-01', 'amount' => 900, 'quantity' => 9] + $base,
                ['id' => 4, 'data_date' => '2026-04-30', 'amount' => 700, 'quantity' => 7] + $base,
            ]);
            $saved = Db::name('online_daily_data')->where('id', 1)->find();
            self::assertSame(121, (int)$saved['system_hotel_id']);
            self::assertSame('2026-05-01', $saved['data_date']);
            self::assertSame('ctrip', $saved['source']);
            self::assertSame(0.0, (float)$saved['amount']);
            self::assertSame(0.0, (float)$saved['quantity']);
            $request = new Request();
            $request->withGet(['hotel_id' => 121, 'range' => 'custom', 'start_date' => '2026-05-01', 'end_date' => '2026-05-02']);
            $user = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods(['getPermittedHotelIds', 'isSuperAdmin'])->getMock();
            $user->method('getPermittedHotelIds')->willReturn([121]);
            $user->method('isSuperAdmin')->willReturn(false);
            $controller = new MacroSignal($app, new MacroSignalService());
            (new \ReflectionProperty($controller, 'request'))->setValue($controller, $request);
            (new \ReflectionProperty($controller, 'currentUser'))->setValue($controller, $user);
            $response = $controller->trends()->getData();
            self::assertSame(200, $response['code']);
            $data = $response['data'];
            self::assertSame('ok', $data['data_status']);
            self::assertSame([], $data['read_failure_areas']);
            self::assertSame(2, $data['sample_days']);
            self::assertSame([0.0, 120.0], $data['chart']['metrics']['revenue']['data']);
            self::assertSame([0.0, 2.0], $data['chart']['metrics']['room_nights']['data']);
            $card = array_values(array_filter($data['cards'], static fn(array $c): bool => $c['key'] === 'revenue'))[0];
            self::assertSame('含零收入日', $card['direction']);
            self::assertNull($card['change_rate']);
            self::assertStringContainsString('仅取 OTA 渠道成交额', $card['source']);
            Db::execute('DROP TABLE online_daily_data');
            $failure = $controller->trends()->getData();
            self::assertSame('read_failed', $failure['data']['data_status']);
            self::assertContains('online_daily_data', $failure['data']['read_failure_areas']);
            self::assertSame([null, null], $failure['data']['chart']['metrics']['revenue']['data']);
            self::assertSame(0, $failure['data']['sample_days']);
        } finally {
            Db::connect($connection)->close();
            Config::set($original, 'database');
            Db::connect(null, true);
        }
    }
}
