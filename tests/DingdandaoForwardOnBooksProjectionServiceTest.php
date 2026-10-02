<?php
declare(strict_types=1);

use app\service\BookingDemandPlanningService;
use app\service\DingdandaoForwardOnBooksProjectionService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DingdandaoForwardOnBooksProjectionServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    /** @var array<int,array<string,mixed>> */
    private array $captures = [];

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'dingdandao_forward_projection_' . getmypid() . '.sqlite';
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
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::execute('DELETE FROM hotel_on_books_snapshots');
        Db::execute('DELETE FROM hotel_demand_event_facts');
        Db::execute('DELETE FROM hotels');
        Db::name('hotels')->insert([
            'id' => 80,
            'tenant_id' => 80,
            'name' => '酒店80',
        ]);
        $this->captures = [];
    }

    public function testVerifiedForwardCaptureCreatesSevenExactIdempotentSnapshots(): void
    {
        $this->captures[501] = $this->capture(501, '2026-09-01 08:00:00', 9, 4500);
        $service = $this->service();

        $first = $service->project(80, [80], 80, 501, 155);

        self::assertSame('dingdandao_forward_on_books_projection.v1', $first['contract_version']);
        self::assertSame('saved_and_readback_verified', $first['status']);
        self::assertSame(7, $first['projected_count']);
        self::assertTrue($first['readback_verified']);
        self::assertSame(0, $first['idempotent_count']);
        self::assertSame('partial', $first['demand_plan']['status']);
        self::assertSame([1, 3, 7], $first['demand_plan']['requested_horizons']);
        self::assertSame([1, 3, 7], array_column(
            $first['demand_plan']['windows'],
            'snapshot_coverage_days'
        ));
        self::assertSame([0, 0, 0], array_column(
            $first['demand_plan']['windows'],
            'pickup_coverage_days'
        ));
        self::assertSame(7, Db::name('hotel_on_books_snapshots')->count());
        self::assertSame(63.0, (float)Db::name('hotel_on_books_snapshots')
            ->sum('on_books_room_nights'));
        self::assertSame(31500.0, (float)Db::name('hotel_on_books_snapshots')
            ->sum('on_books_room_revenue'));
        self::assertFalse($first['automatic_pricing']);
        self::assertFalse($first['automatic_inventory_write']);
        self::assertFalse($first['causality_claimed']);
        self::assertSame(0, $first['external_write_count']);

        $replay = $service->project(80, [80], 80, 501, 155);

        self::assertSame(7, $replay['projected_count']);
        self::assertSame(7, $replay['idempotent_count']);
        self::assertSame($first['snapshot_ids'], $replay['snapshot_ids']);
        self::assertSame(7, Db::name('hotel_on_books_snapshots')->count());
    }

    public function testSecondRealCaptureProducesComparableSevenDayPickupPlan(): void
    {
        $this->captures[501] = $this->capture(501, '2026-09-01 08:00:00', 9, 4500);
        $this->captures[502] = $this->capture(502, '2026-09-01 10:00:00', 9, 4500);
        $service = $this->service();

        $service->project(80, [80], 80, 501, 155);
        $second = $service->project(80, [80], 80, 502, 155);

        self::assertSame(14, Db::name('hotel_on_books_snapshots')->count());
        self::assertSame('ready', $second['demand_plan']['status']);
        self::assertSame('Asia/Shanghai', $second['demand_plan']['timezone']);
        self::assertSame([1, 3, 7], array_column(
            $second['demand_plan']['windows'],
            'pickup_coverage_days'
        ));
        self::assertSame(0.0, $second['demand_plan']['windows'][0]['net_pickup_room_nights_total']);
        self::assertSame(0.0, $second['demand_plan']['windows'][1]['net_pickup_room_nights_total']);
        self::assertSame(0.0, $second['demand_plan']['windows'][2]['net_pickup_room_nights_total']);
        self::assertSame(
            '2026-09-01 08:00:00.000000|2026-09-01 10:00:00.000000',
            $second['demand_plan']['windows'][2]['pickup_comparison_pair']
        );
        self::assertSame(63.0, $second['demand_plan']['windows'][2]['on_books_room_nights_total']);
        self::assertSame(31500.0, $second['demand_plan']['windows'][2]['on_books_room_revenue_total']);
        self::assertStringContainsString(
            'cumulative_cancel_room_nights_missing',
            implode(',', $second['demand_plan']['windows'][2]['data_gaps'])
        );
        self::assertStringContainsString(
            'cancellation_gross_booking_base_missing',
            implode(',', $second['demand_plan']['windows'][2]['data_gaps'])
        );
        self::assertFalse($second['cancellation_metrics_available']);
        self::assertFalse($second['gross_pickup_metrics_available']);
    }

    public function testPartialOrAnomalousForwardCaptureFailsClosedWithoutWrites(): void
    {
        foreach (['partial', 'verified_with_anomalies'] as $index => $status) {
            $captureId = 601 + $index;
            $this->captures[$captureId] = $this->capture(
                $captureId,
                '2026-09-01 08:00:00',
                9,
                4500
            );
            $this->captures[$captureId]['forward_room_status']['data_status'] = $status;
            try {
                $this->service()->project(80, [80], 80, $captureId, 155);
                self::fail('non-strict forward facts must not enter the on-books ledger');
            } catch (RuntimeException $error) {
                self::assertSame(
                    'dingdandao_forward_projection_capture_not_verified',
                    $error->getMessage()
                );
            }
        }
        self::assertSame(0, Db::name('hotel_on_books_snapshots')->count());
    }

    private function service(): DingdandaoForwardOnBooksProjectionService
    {
        $planning = new BookingDemandPlanningService(
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-09-01 12:00:00',
                new DateTimeZone('Asia/Shanghai')
            )
        );
        return new DingdandaoForwardOnBooksProjectionService(
            fn(int $tenantId, int $hotelId, int $captureId): array =>
                $this->captures[$captureId] ?? [],
            $planning
        );
    }

    /** @return array<string,mixed> */
    private function capture(
        int $captureId,
        string $capturedAt,
        float $bookedRooms,
        float $roomFee
    ): array {
        $dailyRows = [];
        for ($offset = 0; $offset <= 7; $offset++) {
            $dailyRows[] = [
                'stay_date' => (new DateTimeImmutable('2026-09-01'))
                    ->modify('+' . $offset . ' days')
                    ->format('Y-m-d'),
                'booked_rooms' => $bookedRooms,
                'room_fee' => $roomFee,
            ];
        }
        return [
            'id' => $captureId,
            'tenant_id' => 80,
            'hotel_id' => 80,
            'business_date' => '2026-09-01',
            'captured_at' => $capturedAt,
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'identity_status' => 'matched',
            'reconciliation_status' => 'matched',
            'readback_status' => 'readback_verified',
            'forward_room_status' => [
                'contract_version' => 'dingdandao_forward_room_status.v1',
                'fact_scope' => 'whole_hotel_forward_room_status',
                'data_status' => 'verified',
                'readback_status' => 'readback_verified',
                'reconciliation_status' => 'matched',
                'as_of_date' => '2026-09-01',
                'daily_rows' => $dailyRows,
            ],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE hotel_on_books_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_version TEXT NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            source_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            fact_scope TEXT NOT NULL,
            stay_date TEXT NOT NULL,
            captured_at TEXT NOT NULL,
            source_method TEXT NOT NULL,
            source_ref_hash TEXT NOT NULL,
            on_books_room_nights REAL NULL,
            on_books_room_revenue REAL NULL,
            cumulative_cancel_room_nights REAL NULL,
            gross_booking_room_nights REAL NULL,
            quality_status TEXT NOT NULL,
            readback_verified INTEGER NOT NULL,
            idempotency_key TEXT NOT NULL,
            content_digest TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE (tenant_id, hotel_id, platform, stay_date, idempotency_key)
        )');
        Db::execute('CREATE TABLE hotel_demand_event_facts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_version TEXT NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            source_hotel_id INTEGER NOT NULL,
            event_name TEXT NOT NULL,
            event_type TEXT NOT NULL,
            event_start_date TEXT NOT NULL,
            event_end_date TEXT NOT NULL,
            area_label TEXT NOT NULL,
            source_method TEXT NOT NULL,
            source_ref_hash TEXT NOT NULL,
            source_status TEXT NOT NULL,
            observed_at TEXT NOT NULL,
            reference_only INTEGER NOT NULL,
            idempotency_key TEXT NOT NULL,
            content_digest TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE (tenant_id, hotel_id, idempotency_key)
        )');
    }
}
