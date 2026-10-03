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

    public function testSubSecondSnapshotsPreserveIncreasingPickupInterval(): void
    {
        $result = (new BookingDemandPlanningService())->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
            $this->snapshot(1, '2026-08-30 10:00:00.100000', 8, 800, 1, 10),
            $this->snapshot(2, '2026-08-30 10:00:00.900000', 10, 1060, 2, 13),
        ]);

        self::assertSame('ready', $result['status']);
        self::assertSame(0.0002, $result['elapsed_hours']);
        self::assertSame(2.0, $result['net_pickup_room_nights']);
        self::assertSame(9000.0, $result['pickup_room_nights_per_hour']);
        self::assertNotContains('on_books_snapshot_time_not_increasing', $result['data_gaps']);
    }

    public function testFourDecimalPickupDifferencesKeepPositiveNegativeAndActualZero(): void
    {
        foreach ([[1.0, 1.0001, 0.0, 0.0001, 0.0001], [1.0001, 1.0, 0.0001, -0.0001, 0.0], [1.0, 1.0, 0.0, 0.0, 0.0]] as [$before, $after, $cancel, $delta, $gross]) {
            $result = $this->service()->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
                $this->snapshot(1, '2026-08-30 08:00:00.000000', $before, $before, 0, 10),
                $this->snapshot(2, '2026-08-30 10:00:00.000000', $after, $after, $cancel, 10),
            ]);
            self::assertSame('ready', $result['status']);
            self::assertSame($delta, $result['net_pickup_room_nights']);
            self::assertSame($delta, $result['room_revenue_delta']);
            self::assertSame($gross, $result['gross_pickup_room_nights']);
        }
    }

    public function testGrossBookingCounterResetRequiresRebaselineBeforeAnyPickup(): void
    {
        $result = $this->service()->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
            $this->snapshot(1, '2026-08-30 08:00:00.000000', 8, 800, 1, 10),
            $this->snapshot(2, '2026-08-30 10:00:00.000000', 9, 900, 2, 9),
        ]);
        self::assertSame('rebaseline_required', $result['status']);
        self::assertNull($result['net_pickup_room_nights']);
        self::assertNull($result['gross_pickup_room_nights']);
        self::assertNull($result['room_revenue_delta']);
        self::assertNull($result['cancellation_rate_percent']);
        self::assertContains('gross_booking_counter_reset_or_mismatch', $result['data_gaps']);
    }

    public function testUnknownGrossCounterIsNotZeroOrAnInventedReset(): void
    {
        foreach ([[null, 9], [10, null], [null, null], [0, 0]] as [$beforeGross, $afterGross]) {
            $result = $this->service()->summarizeSnapshots(7, 80, 'ctrip', '2026-09-10', [
                $this->snapshot(1, '2026-08-30 08:00:00.000000', 0, 0, 0, $beforeGross),
                $this->snapshot(2, '2026-08-30 10:00:00.000000', 0, 0, 0, $afterGross),
            ]);
            self::assertSame('ready', $result['status']);
            self::assertSame(0.0, $result['net_pickup_room_nights']);
            self::assertSame(0.0, $result['gross_pickup_room_nights']);
            self::assertSame($afterGross === null ? null : (float)$afterGross, $result['current_gross_booking_room_nights']);
            self::assertNotContains('gross_booking_counter_reset_or_mismatch', $result['data_gaps']);
        }
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

    public function testCancellationTotalsAboveGrossBookingBaseRequireRebaseline(): void
    {
        $result = (new BookingDemandPlanningService())->summarizeSnapshots(
            7,
            80,
            'ctrip',
            '2026-09-10',
            [
                $this->snapshot(1, '2026-08-30 08:00:00.000000', 8, 800, 1, 10),
                $this->snapshot(2, '2026-08-30 10:00:00.000000', 10, 1060, 20, 10),
            ]
        );

        self::assertSame('rebaseline_required', $result['status']);
        self::assertNull($result['cancellation_rate_percent']);
        self::assertContains(
            'cumulative_cancel_room_nights_exceeds_gross_booking_room_nights',
            $result['data_gaps']
        );
    }

    public function testPreviousCancellationTotalsAboveOwnGrossBaseRequireRebaselineBeforePickup(): void
    {
        $result = (new BookingDemandPlanningService())->summarizeSnapshots(
            7,
            80,
            'ctrip',
            '2026-09-10',
            [
                $this->snapshot(1, '2026-08-30 08:00:00.000000', 8, 800, 11, 10),
                $this->snapshot(2, '2026-08-30 10:00:00.000000', 10, 1060, 12, 13),
            ]
        );

        self::assertSame('rebaseline_required', $result['status']);
        self::assertNull($result['net_pickup_room_nights']);
        self::assertNull($result['gross_pickup_room_nights']);
        self::assertContains(
            'previous_cumulative_cancel_room_nights_exceeds_gross_booking_room_nights',
            $result['data_gaps']
        );
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

    public function testConcurrentSnapshotDuplicateReturnsTheDigestVerifiedWinner(): void
    {
        $input = $this->concurrentSnapshotInput('snapshot-concurrent-winner');
        $winner = $this->service()->saveOnBooksSnapshot(7, [80], 80, $input, 11);
        $service = $this->service(static function (callable $callback): array {
            throw new RuntimeException(
                'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'
            );
        });

        $replay = $service->saveOnBooksSnapshot(7, [80], 80, $input, 11);

        self::assertSame($winner['id'], $replay['id']);
        self::assertSame($winner['content_digest'], $replay['content_digest']);
        self::assertTrue($replay['idempotent']);
        self::assertTrue($replay['readback_verified']);
        self::assertSame(1, (int)Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->count());
    }

    public function testLegacySnapshotWriterRejectsExcessPrecisionWithoutRoundingAnyMetric(): void
    {
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
            $input = array_replace($this->concurrentSnapshotInput('TEST-ONLY-overprecision-' . $field), [$field => 0.00001]);
            try {
                $this->service()->saveOnBooksSnapshot(7, [80], 80, $input, 11);
                self::fail('a positive fifth decimal cannot become a zero legacy fact');
            } catch (InvalidArgumentException $error) {
                self::assertSame($field . '_precision_invalid', $error->getMessage());
            }
        }
        self::assertSame(0, (int)Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->count());
    }

    public function testSnapshotMetricsAtCompatibleMaximumSaveAndReadBackExactly(): void
    {
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
            foreach ([9999999999.9999, '9999999999.9999'] as $index => $value) {
                $input = array_replace($this->concurrentSnapshotInput('TEST-ONLY-at-limit-' . $field . '-' . $index), [$field => $value]);
                $saved = $this->service()->saveOnBooksSnapshot(7, [80], 80, $input, 11);
                self::assertSame(9999999999.9999, $saved[$field]);
                self::assertSame($saved, $this->service()->readSnapshot(7, 80, $saved['id']) + ['idempotent' => false]);
            }
        }
    }

    public function testSignedZeroCanonicalizesAllSnapshotMetricsBeforeSaveAndReadback(): void
    {
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
            foreach ([-0.0, '-0.0000'] as $index => $value) {
                $input = array_replace($this->concurrentSnapshotInput('TEST-ONLY-signed-zero-' . $field . '-' . $index), [$field => $value]);
                $saved = $this->service()->saveOnBooksSnapshot(7, [80], 80, $input, 11);
                self::assertSame('0.0', json_encode($saved[$field], JSON_PRESERVE_ZERO_FRACTION));
                self::assertSame($saved, $this->service()->readSnapshot(7, 80, $saved['id']) + ['idempotent' => false]);
                self::assertSame($saved['id'], $this->service()->saveOnBooksSnapshot(7, [80], 80, $input, 11)['id']);
            }
        }
    }

    public function testSnapshotMetricsOverCompatibleMaximumAreRejectedBeforePersistence(): void
    {
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) {
            foreach ([1e10, '10000000000', 1e14, '99999999999999.9999'] as $index => $value) {
                $input = array_replace($this->concurrentSnapshotInput('TEST-ONLY-over-limit-' . $field . '-' . $index), [$field => $value]);
                try {
                    $this->service()->saveOnBooksSnapshot(7, [80], 80, $input, 11);
                    self::fail('out-of-range ' . $field . ' must not reach storage');
                } catch (InvalidArgumentException $error) {
                    self::assertSame($field . '_out_of_range', $error->getMessage());
                }
                self::assertSame(0, (int)Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->count());
            }
        }
    }

    public function testLegacyHighRevenueRemainsReadableWithoutRelaxingNewWriteLimits(): void
    {
        $service = $this->service();
        foreach (['10000000000.00', '1000000000000.25', '90000000000000.25'] as $index => $legacyRevenue) {
            $input = array_replace($this->concurrentSnapshotInput('TEST-ONLY-legacy-high-' . $index), [
                'stay_date' => '2026-08-31', 'captured_at' => '2026-08-30 10:0' . $index . ':00',
            ]);
            $content = $service->validatedSnapshotContent(7, [80], 80, $input);
            $content['on_books_room_revenue'] = (float)$legacyRevenue;
            $digestContent = $content;
            unset($digestContent['hotel_id']);
            ksort($digestContent);
            $digest = hash('sha256', json_encode($digestContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $storedContent = array_replace($content, ['on_books_room_revenue' => $legacyRevenue]);
            $storedRow = $storedContent + [
                'content_digest' => $digest, 'idempotency_key' => 'TEST-ONLY-stored-legacy-high-' . $index,
                'created_by' => 11, 'created_at' => '2026-08-30 10:0' . $index . ':00',
            ];
            // Seed a legacy decimal as a bound string, bypassing the present
            // writer's float conversion whose conservative cap is under test.
            $pdo = Db::connect()->getPdo();
            $statement = $pdo->prepare('INSERT INTO ' . BookingDemandPlanningService::SNAPSHOT_TABLE . ' (' . implode(',', array_keys($storedRow)) . ') VALUES (' . implode(',', array_fill(0, count($storedRow), '?')) . ')');
            $statement->execute(array_values($storedRow));
            $id = (int)$pdo->lastInsertId();
            self::assertSame((float)$legacyRevenue, (float)Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->where('id', $id)->value('on_books_room_revenue'));
            $read = $service->readSnapshot(7, 80, $id);
            self::assertSame((float)$legacyRevenue, $read['on_books_room_revenue']);
            self::assertSame($digest, $read['content_digest']);
            self::assertSame($read, $service->validatedSnapshotReadback(Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->where('id', $id)->find()));
            self::assertSame((float)$legacyRevenue, $service->bookingOverview(7, [80], 80, 'ctrip', '2026-08-31')['current_on_books_room_revenue']);
            self::assertSame((float)$legacyRevenue, $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30')['windows'][0]['on_books_room_revenue_total']);
            try {
                $service->saveOnBooksSnapshot(7, [80], 80, array_replace($input, ['on_books_room_revenue' => $legacyRevenue]), 11);
                self::fail('reading a legacy amount must not authorize a new high-value write');
            } catch (InvalidArgumentException $error) {
                self::assertSame('on_books_room_revenue_out_of_range', $error->getMessage());
            }
            self::assertSame($index + 1, (int)Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->count());
            self::assertSame($digest, Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->where('id', $id)->value('content_digest'));
        }
        $stored = Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->where('id', $id)->find();
        $stored['on_books_room_revenue'] = 10000000000.0;
        try {
            $service->validatedSnapshotReadback($stored);
            self::fail('legacy readback must still verify the immutable digest');
        } catch (RuntimeException $error) {
            self::assertSame('on_books_snapshot_content_digest_mismatch', $error->getMessage());
        }
    }

    public function testBatchHotelIdentityRejectsCoercibleNonIntegerValues(): void
    {
        foreach ([true, false, 80.0, [], null, '80.0', '8e1'] as $invalidId) {
            try {
                $this->service()->validatedSnapshotBatchContent(7, [80], [['hotel_id' => $invalidId] + $this->concurrentSnapshotInput('TEST-ONLY-batch-identity')]);
                self::fail('batch scope identity must be an integer or integer-form string');
            } catch (InvalidArgumentException $error) {
                self::assertSame('hotel_scope_required', $error->getMessage());
            }
        }
        foreach ([80, '80'] as $validId) {
            $normalized = $this->service()->validatedSnapshotBatchContent(7, [80], [['hotel_id' => $validId] + $this->concurrentSnapshotInput('TEST-ONLY-batch-identity')]);
            self::assertSame(80, $normalized[0]['hotel_id']);
        }
        self::assertSame(0, (int)Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->count());
    }

    public function testSnapshotDeadlockRetriesTheWholeTransactionWithinTheBoundedBudget(): void
    {
        $attempts = 0;
        $service = $this->service(static function (callable $callback) use (&$attempts): array {
            $attempts++;
            if ($attempts === 1) {
                throw new RuntimeException('Deadlock found when trying to get lock', 1213);
            }
            return Db::transaction($callback);
        });

        $saved = $service->saveOnBooksSnapshot(
            7,
            [80],
            80,
            $this->concurrentSnapshotInput('snapshot-deadlock-retry'),
            11
        );

        self::assertSame(2, $attempts);
        self::assertFalse($saved['idempotent']);
        self::assertTrue($saved['readback_verified']);
        self::assertSame(1, (int)Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->count());
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

    public function testDemandPlanWindowsPreserveFourDecimalTotalsAndPickup(): void
    {
        $service = $this->service();
        for ($offset = 1; $offset <= 7; $offset++) {
            $stayDate = (new DateTimeImmutable('2026-08-30'))->modify('+' . $offset . ' days')->format('Y-m-d');
            $this->savePlanSnapshot($service, $stayDate, '08:00:00', 1, 1);
            $this->savePlanSnapshot($service, $stayDate, '10:00:00', 1.0001, 1.0001);
        }
        $plan = $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');
        foreach ($plan['windows'] as $index => $window) {
            $total = [1.0001, 3.0003, 7.0007][$index];
            $pickup = [0.0001, 0.0003, 0.0007][$index];
            self::assertSame($total, $window['on_books_room_nights_total']);
            self::assertSame($total, $window['observed_on_books_room_nights']);
            self::assertSame($total, $window['on_books_room_revenue_total']);
            self::assertSame($total, $window['observed_on_books_room_revenue']);
            self::assertSame($pickup, $window['net_pickup_room_nights_total']);
            self::assertSame($pickup, $window['observed_net_pickup_room_nights']);
        }
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

    public function testDemandPlanExcludesRebaselineDaysFromPickupCoverage(): void
    {
        $service = $this->service();
        foreach ([
            ['08:00:00', 8.0, 800.0, 3.0, 10.0],
            ['10:00:00', 10.0, 1060.0, 1.0, 12.0],
        ] as [$captureTime, $rooms, $revenue, $cancel, $gross]) {
            $service->saveOnBooksSnapshot(7, [80], 80, [
                'platform' => 'ctrip',
                'fact_scope' => 'ota_channel',
                'stay_date' => '2026-08-31',
                'captured_at' => '2026-08-30 ' . $captureTime,
                'source_method' => 'file_import',
                'source_ref' => 'reset-pair-' . $captureTime,
                'on_books_room_nights' => $rooms,
                'on_books_room_revenue' => $revenue,
                'cumulative_cancel_room_nights' => $cancel,
                'gross_booking_room_nights' => $gross,
                'quality_status' => 'manual_confirmed',
                'idempotency_key' => 'reset-pair-' . $captureTime,
            ], 11);
        }

        $window = $service->demandPlan(7, [80], 80, 'ctrip', '2026-08-30')['windows'][0];

        self::assertSame('rebaseline_required', $window['daily'][0]['status']);
        self::assertSame(2.0, $window['daily'][0]['net_pickup_room_nights']);
        self::assertSame('partial', $window['status']);
        self::assertSame(0, $window['pickup_coverage_days']);
        self::assertNull($window['observed_net_pickup_room_nights']);
        self::assertNull($window['net_pickup_room_nights_total']);
        self::assertContains('window_pickup_coverage_incomplete', $window['data_gaps']);
    }

    public function testDemandPlanRejectsMixedFactScopesAcrossPickupWindow(): void
    {
        $service = $this->service();
        foreach (range(1, 3) as $offset) {
            $stayDate = (new DateTimeImmutable('2026-08-30'))->modify('+' . $offset . ' days')->format('Y-m-d');
            $scope = $offset === 2 ? 'whole_hotel' : 'accommodation_room_fee';
            foreach ([['08:00:00', 10.0], ['10:00:00', 12.0]] as [$captureTime, $rooms]) {
                $service->saveOnBooksSnapshot(7, [80], 80, [
                    'platform' => 'dingdandao_pms',
                    'fact_scope' => $scope,
                    'stay_date' => $stayDate,
                    'captured_at' => '2026-08-30 ' . $captureTime,
                    'source_method' => 'file_import',
                    'source_ref' => 'scope-' . $scope . '-' . $stayDate . '-' . $captureTime,
                    'on_books_room_nights' => $rooms,
                    'on_books_room_revenue' => $rooms * 100,
                    'cumulative_cancel_room_nights' => $captureTime === '10:00:00' ? 2 : 1,
                    'gross_booking_room_nights' => $rooms + 2,
                    'quality_status' => 'manual_confirmed',
                    'idempotency_key' => 'scope-' . $stayDate . '-' . $captureTime,
                ], 11);
            }
        }

        $window = $service->demandPlan(7, [80], 80, 'dingdandao_pms', '2026-08-30')['windows'][1];

        self::assertSame('partial', $window['status']);
        self::assertSame(3, $window['pickup_coverage_days']);
        self::assertSame(
            ['accommodation_room_fee', 'whole_hotel', 'accommodation_room_fee'],
            array_column($window['daily'], 'fact_scope')
        );
        self::assertNull($window['fact_scope']);
        self::assertNull($window['pickup_fact_scope']);
        self::assertNull($window['on_books_room_nights_total']);
        self::assertNull($window['net_pickup_room_nights_total']);
        self::assertContains('window_snapshot_fact_scope_mismatch', $window['data_gaps']);
        self::assertContains('window_pickup_fact_scope_mismatch', $window['data_gaps']);
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

    public function testLegacySnapshotSourceReferenceRequiresTextBeforePersistence(): void
    {
        $service = $this->service();
        foreach ([true, 9, 9.5, [], ['export' => 'TEST-ONLY-report'], false, new \stdClass()] as $invalid) {
            $input = array_replace($this->concurrentSnapshotInput('TEST-ONLY-invalid-source'), ['source_ref' => $invalid]);
            $failure = null;
            try { $service->saveOnBooksSnapshot(7, [80], 80, $input, 11); }
            catch (InvalidArgumentException $error) { $failure = $error; }
            self::assertInstanceOf(InvalidArgumentException::class, $failure);
            self::assertSame('on_books_snapshot_source_ref_invalid', $failure->getMessage());
            self::assertSame(0, Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->count());
        }
        $input = array_replace($this->concurrentSnapshotInput('TEST-ONLY-valid-source'), ['source_ref' => '  TEST-ONLY-export-fingerprint  ']);
        $saved = $service->saveOnBooksSnapshot(7, [80], 80, $input, 11);
        $input['source_ref'] = trim($input['source_ref']);
        $replay = $service->saveOnBooksSnapshot(7, [80], 80, $input, 11);
        self::assertTrue($replay['idempotent']);
        self::assertSame($saved['id'], $replay['id']);
        self::assertSame(hash('sha256', 'on-books-source-v1|TEST-ONLY-export-fingerprint'), $saved['source_ref_hash']);
    }

    private function service(?callable $transactionRunner = null): BookingDemandPlanningService
    {
        return new BookingDemandPlanningService(
            static fn(): \DateTimeImmutable => new \DateTimeImmutable(
                '2026-08-30 12:00:00',
                new \DateTimeZone('Asia/Shanghai')
            ),
            $transactionRunner
        );
    }

    /** @return array<string,mixed> */
    private function concurrentSnapshotInput(string $idempotencyKey): array
    {
        return [
            'platform' => 'ctrip',
            'fact_scope' => 'ota_channel',
            'stay_date' => '2026-09-10',
            'captured_at' => '2026-08-30 10:00:00',
            'source_method' => 'file_import',
            'source_ref' => 'authorized-export-' . $idempotencyKey,
            'on_books_room_nights' => 10,
            'on_books_room_revenue' => 1060,
            'cumulative_cancel_room_nights' => 2,
            'gross_booking_room_nights' => 13,
            'quality_status' => 'manual_confirmed',
            'idempotency_key' => $idempotencyKey,
        ];
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
