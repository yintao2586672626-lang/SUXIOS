<?php
declare(strict_types=1);

use app\service\BookingDemandPlanningService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class BookingDemandPlanningServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'booking_demand_planning_' . getmypid() . '.sqlite';
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
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 7, 'name' => '酒店80'],
            ['id' => 81, 'tenant_id' => 8, 'name' => '酒店81'],
        ]);
    }

    public function testRealOnBooksSnapshotsProduceNetAndGrossPickupWithoutProxyingSales(): void
    {
        $service = new BookingDemandPlanningService();
        $result = $service->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
            $this->snapshot(1, '2026-08-30 08:00:00.000000', 8, 800, 1, 10),
            $this->snapshot(2, '2026-08-30 10:00:00.000000', 10, 1060, 2, 13),
        ]);

        self::assertSame('ready', $result['status']);
        self::assertSame(2.0, $result['net_pickup_room_nights']);
        self::assertSame(3.0, $result['gross_pickup_room_nights']);
        self::assertSame(1.0, $result['pickup_room_nights_per_hour']);
        self::assertSame(260.0, $result['room_revenue_delta']);
        self::assertSame(130.0, $result['room_revenue_per_hour']);
        self::assertSame(15.38, $result['cancellation_rate_percent']);
        self::assertFalse($result['automatic_pricing']);
        self::assertSame(0, $result['external_write_count']);
    }

    public function testMissingOrResetCancellationCounterNeverBecomesZeroGrossPickup(): void
    {
        $service = new BookingDemandPlanningService();
        $missing = $service->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
            $this->snapshot(1, '2026-08-30 08:00:00.000000', 8, 800, null, null),
            $this->snapshot(2, '2026-08-30 10:00:00.000000', 10, 1060, null, null),
        ]);
        self::assertNull($missing['gross_pickup_room_nights']);
        self::assertContains('cumulative_cancel_room_nights_missing', $missing['data_gaps']);

        $reset = $service->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
            $this->snapshot(1, '2026-08-30 08:00:00.000000', 8, 800, 3, 12),
            $this->snapshot(2, '2026-08-30 10:00:00.000000', 10, 1060, 1, 12),
        ]);
        self::assertSame('rebaseline_required', $reset['status']);
        self::assertContains('cancellation_counter_reset_or_mismatch', $reset['data_gaps']);
    }

    public function testLeadTimeUsesShanghaiCalendarDatesAndRejectsAfterStaySnapshots(): void
    {
        $service = new BookingDemandPlanningService();
        $sameDay = $service->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
            $this->snapshot(1, '2026-09-10 10:00:00.000000', 8, 800, 1, 10),
        ]);
        $nextDayRow = $this->snapshot(2, '2026-09-10 23:00:00.000000', 8, 800, 1, 10);
        $nextDayRow['stay_date'] = '2026-09-11';
        $nextDay = $service->summarizeSnapshots(7, 80, 'ctrip', '2026-09-11', [$nextDayRow]);
        $afterStay = $service->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
            $this->snapshot(3, '2026-09-11 00:01:00.000000', 8, 800, 1, 10),
        ]);

        self::assertSame(0, $sameDay['lead_time_days']);
        self::assertSame(1, $nextDay['lead_time_days']);
        self::assertSame('rebaseline_required', $afterStay['status']);
        self::assertContains('on_books_snapshot_after_stay_date', $afterStay['data_gaps']);
    }

    public function testSnapshotAndDemandEventSaveReplayAndExactReadback(): void
    {
        $service = $this->service();
        $input = [
            'platform' => 'ctrip',
            'fact_scope' => 'ota_channel',
            'stay_date' => '2026-09-10',
            'captured_at' => '2026-08-30 10:00:00',
            'source_method' => 'file_import',
            'source_ref' => 'authorized-export-sha256:abc',
            'on_books_room_nights' => 10,
            'on_books_room_revenue' => 1060,
            'cumulative_cancel_room_nights' => 2,
            'gross_booking_room_nights' => 13,
            'quality_status' => 'manual_confirmed',
            'readback_verified' => false,
            'idempotency_key' => 'snapshot-80-20260910-1000',
        ];
        $saved = $service->saveOnBooksSnapshot(7, [80], 80, $input, 11);
        $replay = $service->saveOnBooksSnapshot(7, [80], 80, $input, 11);
        self::assertTrue($saved['readback_verified']);
        self::assertSame($saved['id'], $replay['id']);
        self::assertTrue($replay['idempotent']);
        self::assertSame(1, Db::name('hotel_on_books_snapshots')->count());

        $event = $service->saveDemandEvent(7, [80], 80, [
            'event_name' => '会展中心酒店用品展',
            'event_type' => 'exhibition',
            'event_start_date' => '2026-09-10',
            'event_end_date' => '2026-09-12',
            'area_label' => '本店周边会展中心',
            'source_method' => 'manual_reference',
            'source_ref' => 'public-event-source-sha256:def',
            'source_status' => 'reference_only',
            'observed_at' => '2026-08-30 10:30:00',
            'idempotency_key' => 'event-80-20260910-expo',
        ], 11);
        $calendar = $service->demandCalendar(7, [80], 80, '2026-09-01', '2026-09-30');
        self::assertSame($event['id'], $calendar['events'][0]['id']);
        self::assertTrue($calendar['reference_only']);
        self::assertFalse($calendar['causality_claimed']);
        self::assertFalse($calendar['automatic_pricing']);

        Db::name('hotel_on_books_snapshots')->where('id', $saved['id'])->update(['hotel_id' => 90]);
        $migrated = $service->readSnapshot(7, 90, (int)$saved['id']);
        self::assertSame(90, $migrated['hotel_id']);
        self::assertSame(80, $migrated['source_hotel_id']);
    }

    public function testDemandPlanUsesTomorrowThreeAndSevenDayWindowsWithoutTodayOrLongHorizons(): void
    {
        $service = $this->service();
        for ($offset = 1; $offset <= 7; $offset++) {
            $stayDate = (new DateTimeImmutable('2026-08-30'))->modify('+' . $offset . ' days')->format('Y-m-d');
            $this->savePlanSnapshot($service, $stayDate, '08:00:00', 10 + $offset, 1000 + ($offset * 100));
            if ($offset <= 3) {
                $this->savePlanSnapshot($service, $stayDate, '10:00:00', 12 + $offset, 1260 + ($offset * 100));
            }
        }
        $service->saveDemandEvent(7, [80], 80, [
            'event_name' => '明日展会', 'event_type' => 'exhibition',
            'event_start_date' => '2026-08-31', 'event_end_date' => '2026-08-31',
            'area_label' => '本店周边', 'source_method' => 'manual_reference',
            'source_ref' => 'event-source-tomorrow', 'source_status' => 'reference_only',
            'observed_at' => '2026-08-30 11:00:00', 'idempotency_key' => 'event-tomorrow',
        ], 11);
        $service->saveDemandEvent(7, [80], 80, [
            'event_name' => '周末活动', 'event_type' => 'other',
            'event_start_date' => '2026-09-04', 'event_end_date' => '2026-09-05',
            'area_label' => '城市中心', 'source_method' => 'manual_reference',
            'source_ref' => 'event-source-week', 'source_status' => 'reference_only',
            'observed_at' => '2026-08-30 11:10:00', 'idempotency_key' => 'event-week',
        ], 11);

        $plan = $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');

        self::assertSame('booking_demand_plan.v1', $plan['contract_version']);
        self::assertSame([1, 3, 7], $plan['requested_horizons']);
        self::assertSame(['tomorrow', 'next_3_days', 'next_7_days'], array_column($plan['windows'], 'window_key'));
        self::assertSame('2026-08-31', $plan['windows'][0]['start_date']);
        self::assertSame('2026-08-31', $plan['windows'][0]['end_date']);
        self::assertSame('2026-09-02', $plan['windows'][1]['end_date']);
        self::assertSame('2026-09-06', $plan['windows'][2]['end_date']);
        self::assertSame(3, $plan['windows'][1]['snapshot_coverage_days']);
        self::assertSame(3, $plan['windows'][1]['pickup_coverage_days']);
        self::assertSame(6.0, $plan['windows'][1]['net_pickup_room_nights_total']);
        self::assertSame(7, $plan['windows'][2]['snapshot_coverage_days']);
        self::assertNull($plan['windows'][2]['net_pickup_room_nights_total']);
        self::assertSame(6.0, $plan['windows'][2]['observed_net_pickup_room_nights']);
        self::assertSame(1, $plan['windows'][0]['event_count']);
        self::assertSame(2, $plan['windows'][2]['event_count']);
        self::assertFalse($plan['automatic_pricing']);
        self::assertFalse($plan['automatic_inventory_write']);
        self::assertSame(0, $plan['external_write_count']);
        self::assertNotContains(14, $plan['requested_horizons']);
        self::assertNotContains(30, $plan['requested_horizons']);
    }

    public function testDemandPlanKeepsIncompleteThreeAndSevenDayTotalsNull(): void
    {
        $service = $this->service();
        $this->savePlanSnapshot($service, '2026-08-31', '10:00:00', 12, 1260);

        $plan = $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');

        self::assertSame('partial', $plan['windows'][0]['status']);
        self::assertSame('partial', $plan['windows'][1]['status']);
        self::assertSame('partial', $plan['windows'][2]['status']);
        self::assertSame(12.0, $plan['windows'][0]['on_books_room_nights_total']);
        self::assertNull($plan['windows'][1]['on_books_room_nights_total']);
        self::assertNull($plan['windows'][2]['on_books_room_nights_total']);
        self::assertContains('window_snapshot_coverage_incomplete', $plan['windows'][1]['data_gaps']);
        self::assertContains('window_on_books_room_nights_incomplete', $plan['windows'][2]['data_gaps']);

        Db::execute('DELETE FROM hotel_on_books_snapshots');
        $blocked = $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');
        self::assertSame('blocked', $blocked['status']);
        self::assertNull($blocked['windows'][0]['on_books_room_nights_total']);
        self::assertSame('data_gap_repair', $blocked['windows'][0]['decision_status']);
    }

    public function testDemandPlanExcludesSnapshotsCapturedAfterHistoricalBusinessDayCutoff(): void
    {
        $service = new BookingDemandPlanningService(
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-09-01 12:00:00',
                new DateTimeZone('Asia/Shanghai')
            )
        );
        $service->saveOnBooksSnapshot(7, [80], 80, [
            'platform' => 'ctrip', 'fact_scope' => 'ota_channel',
            'stay_date' => '2026-09-02', 'captured_at' => '2026-08-31 10:00:00',
            'source_method' => 'file_import', 'source_ref' => 'future-known-fact',
            'on_books_room_nights' => 99, 'on_books_room_revenue' => 9999,
            'quality_status' => 'manual_confirmed', 'idempotency_key' => 'future-known-fact',
        ], 11);

        $plan = $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');

        self::assertSame('2026-08-30 23:59:59.999999', $plan['as_of_time']);
        self::assertSame('blocked', $plan['status']);
        self::assertNull($plan['windows'][1]['daily'][1]['current_snapshot_ref']);
        self::assertNull($plan['windows'][1]['on_books_room_nights_total']);
    }

    public function testDemandPlanRejectsMixedPickupComparisonWindowsAndBaselineOnlyWeek(): void
    {
        $service = $this->service();
        for ($offset = 1; $offset <= 7; $offset++) {
            $stayDate = (new DateTimeImmutable('2026-08-30'))->modify('+' . $offset . ' days')->format('Y-m-d');
            $this->savePlanSnapshot($service, $stayDate, '08:00:00', 10, 1000);
            $this->savePlanSnapshot($service, $stayDate, $offset === 1 ? '09:00:00' : '10:00:00', 11, 1100);
        }
        $mixed = $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');
        self::assertSame('partial', $mixed['status']);
        self::assertNull($mixed['windows'][2]['net_pickup_room_nights_total']);
        self::assertNull($mixed['windows'][2]['observed_net_pickup_room_nights']);
        self::assertContains('window_pickup_comparison_window_mismatch', $mixed['windows'][2]['data_gaps']);

        Db::execute('DELETE FROM hotel_on_books_snapshots');
        for ($offset = 1; $offset <= 7; $offset++) {
            $stayDate = (new DateTimeImmutable('2026-08-30'))->modify('+' . $offset . ' days')->format('Y-m-d');
            $this->savePlanSnapshot($service, $stayDate, '10:00:00', 10, 1000);
        }
        $baselineOnly = $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');
        self::assertSame('partial', $baselineOnly['status']);
        self::assertSame(7, $baselineOnly['windows'][2]['snapshot_coverage_days']);
        self::assertSame(0, $baselineOnly['windows'][2]['pickup_coverage_days']);
        self::assertNull($baselineOnly['windows'][2]['net_pickup_room_nights_total']);
    }

    public function testCrossTenantAndOtaWholeHotelScopeAreRejected(): void
    {
        $service = new BookingDemandPlanningService();
        $input = [
            'platform' => 'ctrip',
            'fact_scope' => 'whole_hotel',
            'stay_date' => '2026-09-10',
            'captured_at' => '2026-08-30 10:00:00',
            'source_method' => 'file_import',
            'source_ref' => 'authorized-export-sha256:abc',
            'on_books_room_nights' => 10,
            'quality_status' => 'manual_confirmed',
            'readback_verified' => true,
            'idempotency_key' => 'invalid-whole-hotel',
        ];
        try {
            $service->saveOnBooksSnapshot(7, [80], 80, $input, 11);
            self::fail('OTA on-books rows must remain OTA scoped.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('ota_on_books_snapshot_must_keep_channel_scope', $error->getMessage());
        }

        $input['fact_scope'] = 'ota_channel';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hotel_outside_permitted_scope');
        $service->saveOnBooksSnapshot(7, [80], 81, $input, 11);
    }

    public function testFutureTimestampsManualVerifiedEventAndEmptyPermissionsFailClosed(): void
    {
        $service = $this->service();
        $snapshot = [
            'platform' => 'ctrip',
            'fact_scope' => 'ota_channel',
            'stay_date' => '2099-01-02',
            'captured_at' => '2099-01-01 10:00:00',
            'source_method' => 'manual_entry',
            'source_ref' => 'manual-source-ref',
            'on_books_room_nights' => 1,
            'quality_status' => 'manual_confirmed',
            'idempotency_key' => 'future-snapshot',
        ];
        try {
            $service->saveOnBooksSnapshot(7, [80], 80, $snapshot, 11);
            self::fail('future snapshot must be rejected');
        } catch (InvalidArgumentException $error) {
            self::assertSame('on_books_snapshot_captured_at_future', $error->getMessage());
        }

        try {
            $service->saveDemandEvent(7, [80], 80, [
                'event_name' => '未来伪造事件', 'event_type' => 'other',
                'event_start_date' => '2099-01-01', 'event_end_date' => '2099-01-02',
                'area_label' => '测试', 'source_method' => 'manual_reference',
                'source_ref' => 'manual-source-ref', 'source_status' => 'verified_source',
                'observed_at' => '2099-01-01 00:00:00', 'idempotency_key' => 'future-event',
            ], 11);
            self::fail('manual event cannot self-promote to verified source');
        } catch (InvalidArgumentException $error) {
            self::assertSame('manual_demand_event_must_remain_reference_only', $error->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hotel_outside_permitted_scope');
        $service->bookingOverview(7, [], 80, 'ctrip', '2026-09-10');
    }

    private function service(): BookingDemandPlanningService
    {
        return new BookingDemandPlanningService(
            static fn(): \DateTimeImmutable => new \DateTimeImmutable(
                '2026-08-30 12:00:00',
                new \DateTimeZone('Asia/Shanghai')
            )
        );
    }

    private function savePlanSnapshot(
        BookingDemandPlanningService $service,
        string $stayDate,
        string $captureTime,
        float $rooms,
        float $revenue
    ): void {
        $service->saveOnBooksSnapshot(7, [80], 80, [
            'platform' => 'ctrip',
            'fact_scope' => 'ota_channel',
            'stay_date' => $stayDate,
            'captured_at' => '2026-08-30 ' . $captureTime,
            'source_method' => 'file_import',
            'source_ref' => 'authorized-export-' . $stayDate . '-' . $captureTime,
            'on_books_room_nights' => $rooms,
            'on_books_room_revenue' => $revenue,
            'cumulative_cancel_room_nights' => str_starts_with($captureTime, '10:') ? 2 : 1,
            'gross_booking_room_nights' => $rooms + 2,
            'quality_status' => 'manual_confirmed',
            'idempotency_key' => 'snapshot-' . $stayDate . '-' . $captureTime,
        ], 11);
    }

    /** @return array<string,mixed> */
    private function snapshot(int $id, string $capturedAt, float $rooms, float $revenue, ?float $cancel, ?float $gross): array
    {
        return [
            'id' => $id,
            'tenant_id' => 7,
            'hotel_id' => 80,
            'platform' => 'ctrip',
            'fact_scope' => 'ota_channel',
            'stay_date' => '2026-09-10',
            'captured_at' => $capturedAt,
            'on_books_room_nights' => $rooms,
            'on_books_room_revenue' => $revenue,
            'cumulative_cancel_room_nights' => $cancel,
            'gross_booking_room_nights' => $gross,
            'quality_status' => 'verified',
            'readback_verified' => 1,
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)');
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
