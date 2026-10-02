<?php
declare(strict_types=1);

use app\service\BookingDemandPlanningService;
use app\service\BookingMonitoringService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class BookingMonitoringServiceTest extends TestCase
{
    private static array $originalConfig;
    private static string $sqlitePath;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'booking_monitor_test_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);
        $config = self::$originalConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$sqlitePath, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)');
        Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY, hotel_id INTEGER NOT NULL, name TEXT NOT NULL)');
        $shared = 'id INTEGER PRIMARY KEY AUTOINCREMENT, contract_version TEXT NOT NULL, tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL, source_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, fact_scope TEXT NOT NULL,
            stay_date TEXT NOT NULL, captured_at TEXT NOT NULL, source_method TEXT NOT NULL, source_ref_hash TEXT NOT NULL,
            on_books_room_nights REAL NULL, on_books_room_revenue REAL NULL, cumulative_cancel_room_nights REAL NULL,
            gross_booking_room_nights REAL NULL, quality_status TEXT NOT NULL, readback_verified INTEGER NOT NULL,
            idempotency_key TEXT NOT NULL, content_digest TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL';
        Db::execute('CREATE TABLE hotel_on_books_snapshots (' . $shared . ', UNIQUE(tenant_id,hotel_id,platform,stay_date,idempotency_key))');
        Db::execute('CREATE TABLE hotel_room_type_on_books_snapshots (' . $shared . ', room_type_id INTEGER NOT NULL,
            room_type_name TEXT NOT NULL, supersedes_snapshot_id INTEGER NULL, UNIQUE(tenant_id,hotel_id,idempotency_key))');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        Db::execute('DELETE FROM hotel_room_type_on_books_snapshots');
        Db::execute('DELETE FROM hotel_on_books_snapshots');
        Db::execute('DELETE FROM room_types');
        Db::execute('DELETE FROM hotels');
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 7, 'name' => 'TEST-ONLY酒店80'],
            ['id' => 82, 'tenant_id' => 7, 'name' => 'TEST-ONLY酒店82'],
            ['id' => 81, 'tenant_id' => 8, 'name' => 'TEST-ONLY其他租户'],
        ]);
        Db::name('room_types')->insertAll([
            ['id' => 1, 'hotel_id' => 80, 'name' => 'TEST-ONLY大床'],
            ['id' => 2, 'hotel_id' => 80, 'name' => 'TEST-ONLY双床'],
            ['id' => 3, 'hotel_id' => 82, 'name' => 'TEST-ONLY大床'],
        ]);
    }

    public function testImportExactReadbackReplayAndManualQualityCannotSelfPromote(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00', 10);
        $row['quality_status'] = 'verified';
        $row['source_method'] = 'authorized_api_export';
        $saved = $service->saveSnapshots(7, [80], [$row], 9);
        self::assertSame('saved_readback_verified', $saved['save_status']);
        self::assertTrue($saved['readback_verified']);
        self::assertSame('manual_confirmed', $saved['snapshots'][0]['quality_status']);
        self::assertSame('manual_file_import', $saved['snapshots'][0]['source_method']);
        self::assertSame('TEST-ONLY大床', $saved['snapshots'][0]['room_type_name']);
        self::assertSame($saved['snapshots'][0], $service->readSnapshot(7, [80], 80, $saved['snapshots'][0]['id']) + ['idempotent' => false]);
        $replay = $service->saveSnapshots(7, [80], [$row], 9);
        self::assertTrue($replay['snapshots'][0]['idempotent']);
        self::assertSame($saved['snapshots'][0]['id'], $replay['snapshots'][0]['id']);
        self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testExactFixed24hIgnoresMoreRecentIntraDaySnapshotsAndKeepsRoomsSeparate(): void
    {
        $service = $this->service();
        $service->saveSnapshots(7, [80], [
            $this->row('2026-10-01 09:00:00', 8),
            $this->row('2026-10-02 09:00:00', 11),
            $this->row('2026-10-02 10:00:00', 99),
            $this->row('2026-10-01 09:00:00', 2, 2),
            $this->row('2026-10-02 09:00:00', 3, 2),
        ], 9);
        $view = $service->overview(7, [80], [80], $this->query());
        $bed = $this->cell($view, 80, 1);
        $twin = $this->cell($view, 80, 2);
        self::assertSame('ready', $bed['status']);
        self::assertSame(24.0, $bed['elapsed_hours']);
        self::assertSame(3.0, $bed['net_pickup_24h_room_nights']);
        self::assertSame(1.0, $twin['net_pickup_24h_room_nights']);
        self::assertSame(11.0, $bed['current']['on_books_room_nights']);
        self::assertSame('2026-10-02 09:00:00', $view['observation_time']);
        self::assertSame('2026-10-01 09:00:00', $view['baseline_time']);
        self::assertSame('Asia/Shanghai', $view['timezone']);
        self::assertFalse($view['boundaries']['automatic_pricing']);
        self::assertSame(0, $view['boundaries']['external_write_count']);
    }

    public function testLateStaleAndApproximateObservationsNeverBecome24hPickup(): void
    {
        $service = $this->service();
        $service->saveSnapshots(7, [80], [
            $this->row('2026-10-01 09:00:00', 8, 1),
            $this->row('2026-10-02 09:05:00', 11, 1),
            $this->row('2026-10-02 08:45:00', 3, 2),
        ], 9);
        $view = $service->overview(7, [80], [80], $this->query());
        self::assertSame('late', $this->cell($view, 80, 1)['current']['status']);
        self::assertSame('approximate', $this->cell($view, 80, 2)['current']['status']);
        self::assertNull($this->cell($view, 80, 1)['net_pickup_24h_room_nights']);
        self::assertNull($this->cell($view, 80, 2)['net_pickup_24h_room_nights']);
        $service->saveSnapshots(7, [80], [$this->row('2026-10-01 08:00:00', 8, 0)], 9);
        $view = $service->overview(7, [80], [80], $this->query());
        self::assertSame('stale', $this->cell($view, 80, 0)['current']['status']);
        self::assertNull($this->cell($view, 80, 0)['net_pickup_24h_room_nights']);
    }

    public function testSameLeadTimeHistoryUsesPreviousFourWeekdaysAndNoOtherHotelOrRoom(): void
    {
        $service = $this->service();
        $rows = [$this->row('2026-10-02 09:00:00', 20), $this->row('2026-10-01 09:00:00', 18)];
        foreach ([1 => 8, 2 => 10, 3 => 12, 4 => 14] as $week => $rooms) {
            $anchor = new DateTimeImmutable('2026-10-02 09:00:00', new DateTimeZone('Asia/Shanghai'));
            $history = $anchor->modify('-' . ($week * 7) . ' days');
            $row = $this->row($history->format('Y-m-d H:i:s'), $rooms);
            $row['stay_date'] = $history->modify('+1 day')->format('Y-m-d');
            $rows[] = $row;
            $other = $row;
            $other['room_type_id'] = 2;
            $other['on_books_room_nights'] = 999;
            $rows[] = $other;
        }
        $service->saveSnapshots(7, [80], $rows, 9);
        $view = $service->overview(7, [80, 82], [80, 82], $this->query());
        $cell = $this->cell($view, 80, 1);
        self::assertSame(4, $cell['history_coverage']);
        self::assertSame('ready', $cell['history_status']);
        self::assertSame(11.0, $cell['same_lead_time_median_room_nights']);
        self::assertSame(9.0, $cell['delta_vs_same_lead_time_median']);
        self::assertSame(1, $cell['history'][0]['lead_time_days']);
        self::assertNull($this->cell($view, 82, 3)['current']['on_books_room_nights']);
        self::assertSame('missing', $this->cell($view, 82, 3)['history_status']);
    }

    public function testOldSummariesRemainUnsplitAndIncompleteHistoryKeepsNullMedian(): void
    {
        $planning = new BookingDemandPlanningService(static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-02 12:00:00', new DateTimeZone('Asia/Shanghai')));
        foreach (['2026-10-01 09:00:00' => 8, '2026-10-02 09:00:00' => 10] as $capture => $rooms) {
            $row = $this->row($capture, $rooms, 0);
            $row['source_method'] = 'manual_entry';
            $row['quality_status'] = 'manual_confirmed';
            $row['idempotency_key'] = hash('sha256', 'TEST-ONLY-legacy-' . $capture);
            $planning->saveOnBooksSnapshot(7, [80], 80, $row, 9);
        }
        $view = $this->service()->overview(7, [80], [80], $this->query());
        $summary = $this->cell($view, 80, 0);
        self::assertSame(2.0, $summary['net_pickup_24h_room_nights']);
        self::assertStringStartsWith('hotel_on_books_snapshots#', $summary['current']['evidence_ref']);
        self::assertNull($this->cell($view, 80, 1)['current']['on_books_room_nights']);
        self::assertNull($summary['same_lead_time_median_room_nights']);
        self::assertSame('missing', $summary['history_status']);
    }

    public function testUnverifiedAndMissingBaselineKeepNumericMetricsNull(): void
    {
        $row = $this->row('2026-10-02 09:00:00', 0);
        $row['operator_attested'] = false;
        $this->service()->saveSnapshots(7, [80], [$row], 9);
        $cell = $this->cell($this->service()->overview(7, [80], [80], $this->query()), 80, 1);
        self::assertSame('unverified', $cell['status']);
        self::assertSame(0.0, $cell['current']['on_books_room_nights']);
        self::assertNull($cell['net_pickup_24h_room_nights']);
        self::assertSame('missing', $cell['baseline']['status']);
    }

    public function testImportPrevalidatesAllRowsAndRejectsCrossHotelOrTenantWithoutPartialWrite(): void
    {
        $bad = $this->row('2026-10-02 09:00:00', 5);
        $bad['hotel_id'] = 82;
        try {
            $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 4), $bad], 9);
            self::fail('cross-hotel import must fail');
        } catch (RuntimeException $error) {
            self::assertSame(403, $error->getCode());
            self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('booking_monitor_hotel_tenant_scope_mismatch');
        $this->service()->overview(7, [80, 81], [80, 81], $this->query());
    }

    public function testRoomTypeCannotBeImportedUnderAnotherHotel(): void
    {
        $row = $this->row('2026-10-02 09:00:00', 5);
        $row['room_type_id'] = 3;
        $this->expectExceptionMessage('booking_monitor_room_type_outside_hotel');
        $this->service()->saveSnapshots(7, [80], [$row], 9);
    }

    public function testFractionalHotelIdentityIsRejectedAndFourDecimalImportHasMatchingStorageContract(): void
    {
        $row = $this->row('2026-10-02 09:00:00', 10.1234);
        $saved = $this->service()->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame(10.1234, $saved['on_books_room_nights']);
        $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261002_create_room_type_on_books_snapshots.sql');
        self::assertStringContainsString('`on_books_room_nights` DECIMAL(14,4)', $migration);
        self::assertStringContainsString('`on_books_room_revenue` DECIMAL(18,4)', $migration);
        $row['hotel_id'] = 80.5;
        $this->expectExceptionMessage('booking_monitor_room_type_id_invalid');
        $this->service()->saveSnapshots(7, [80], [$row], 9);
    }

    public function testCorrectionAppendsAndReplaysWithoutOverwritingOriginalEvidence(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00', 9);
        $saved = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        $row['on_books_room_nights'] = 11;
        $row['supersedes_snapshot_id'] = $saved['id'];
        $correction = $service->saveSnapshots(7, [80], [$row], 9)['snapshots'][0];
        self::assertSame(2, Db::name(BookingMonitoringService::TABLE)->count());
        self::assertSame(9.0, $service->readSnapshot(7, [80], 80, $saved['id'])['on_books_room_nights']);
        self::assertSame($saved['id'], $correction['supersedes_snapshot_id']);
        self::assertSame(11.0, $this->cell($service->overview(7, [80], [80], $this->query()), 80, 1)['current']['on_books_room_nights']);
        $row['stay_date'] = '2026-10-04';
        $this->expectExceptionMessage('booking_monitor_correction_scope_mismatch');
        $service->saveSnapshots(7, [80], [$row], 9);
    }

    public function testSameReplayKeyWithDifferentContentRollsBackEntireBatch(): void
    {
        $service = $this->service();
        $row = $this->row('2026-10-02 09:00:00', 9);
        $row['idempotency_key'] = 'TEST-ONLY-replay-key';
        $service->saveSnapshots(7, [80], [$row], 9);
        $row['on_books_room_nights'] = 11;
        try {
            $service->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 8), $row], 9);
            self::fail('conflicting replay must fail');
        } catch (RuntimeException $error) {
            self::assertSame('booking_monitor_idempotency_conflict', $error->getMessage());
            self::assertSame(1, Db::name(BookingMonitoringService::TABLE)->count());
        }
    }

    public function testCorruptReadbackFailsInsteadOfReturningHistoricalFallback(): void
    {
        $saved = $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-02 09:00:00', 9)], 9)['snapshots'][0];
        Db::name(BookingMonitoringService::TABLE)->where('id', $saved['id'])->update(['on_books_room_nights' => 99]);
        $this->expectExceptionMessage('booking_monitor_content_digest_mismatch');
        $this->service()->overview(7, [80], [80], $this->query());
    }

    public function testFutureDatesAndMissingNumbersFailAndPendingAnchorIsExplicit(): void
    {
        $view = $this->service('2026-10-02 08:00:00')->overview(7, [80], [80], $this->query());
        self::assertContains('fixed_observation_time_not_reached', $this->cell($view, 80, 1)['data_gaps']);
        foreach ([['business_date' => '2026-02-30'], ['business_date' => '2026-10-03'], ['fixed_time' => '24:01'], ['horizon_days' => 0]] as $invalid) {
            try { $this->service()->overview(7, [80], [80], $invalid + $this->query()); self::fail('invalid date/slot/horizon'); }
            catch (InvalidArgumentException $error) { self::assertStringStartsWith('booking_monitor_', $error->getMessage()); }
        }
        $row = $this->row('2026-10-02 09:00:00', 4);
        $row['on_books_room_nights'] = null;
        $this->expectExceptionMessage('on_books_room_nights_required');
        $this->service()->saveSnapshots(7, [80], [$row], 9);
    }

    public function testDifferentFactScopesCannotBecomeComparable24hOrHistory(): void
    {
        $service = $this->service();
        $before = $this->row('2026-10-01 09:00:00', 8);
        $after = $this->row('2026-10-02 09:00:00', 9);
        $before['platform'] = $after['platform'] = 'manual_all_channels';
        $before['fact_scope'] = 'whole_hotel';
        $after['fact_scope'] = 'accommodation_room_fee';
        $service->saveSnapshots(7, [80], [$before, $after], 9);
        $cell = $this->cell($service->overview(7, [80], [80], ['platform' => 'manual_all_channels'] + $this->query()), 80, 1);
        self::assertSame('not_comparable', $cell['status']);
        self::assertNull($cell['net_pickup_24h_room_nights']);
        self::assertContains('on_books_fact_scope_changed', $cell['data_gaps']);
    }

    public function testControllerRequiresLoginAndExecutionPermissionBeforeAnyWrite(): void
    {
        $anonymous = $this->controller(['hotel_ids' => '80'], null)->overview();
        self::assertSame(401, $anonymous->getCode());
        self::assertSame(401, $anonymous->getData()['code']);
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80 && $capability === 'operation.view'; }
        };
        $denied = $this->controller(['rows' => [$this->row('2026-10-02 09:00:00', 8)]], $user, 'POST')->saveSnapshots();
        self::assertSame(403, $denied->getCode());
        self::assertSame('booking_monitor_hotel_outside_permitted_scope', $denied->getData()['data']['reason_code']);
        self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
    }

    public function testControllerReadbackIsBoundToExplicitPermittedHotel(): void
    {
        $snapshot = $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-02 09:00:00', 8)], 9)['snapshots'][0];
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80; }
        };
        $readback = $this->controller(['hotel_id' => 80, 'id' => $snapshot['id']], $user)->readSnapshot();
        self::assertSame(200, $readback->getData()['code']);
        self::assertSame($snapshot['content_digest'], $readback->getData()['data']['content_digest']);
        $other = $this->controller(['hotel_id' => 82, 'id' => $snapshot['id']], $user)->readSnapshot();
        self::assertSame(403, $other->getCode());
        self::assertFalse($other->getData()['data']['readback_verified']);
    }

    public function testControllerRejectsFractionalReadbackIdentitiesInsteadOfTruncatingThem(): void
    {
        $snapshot = $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-02 09:00:00', 8)], 9)['snapshots'][0];
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80; }
        };
        foreach ([['hotel_id' => 80.5, 'id' => $snapshot['id']], ['hotel_id' => 80, 'id' => $snapshot['id'] + 0.5]] as $params) {
            $response = $this->controller($params, $user)->readSnapshot();
            self::assertSame(422, $response->getCode());
            self::assertFalse($response->getData()['data']['readback_verified']);
        }
    }

    public function testImportRejectsNumbersOutsideDeclaredDecimalStorageBeforeAnyBatchWrite(): void
    {
        foreach (['on_books_room_nights' => 1e10, 'on_books_room_revenue' => 1e14,
            'cumulative_cancel_room_nights' => 1e10, 'gross_booking_room_nights' => 1e10] as $field => $value) {
            $row = $this->row('2026-10-02 09:00:00', 4);
            $row[$field] = $value;
            try {
                $this->service()->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 3), $row], 9);
                self::fail($field . ' must be rejected before persistence');
            } catch (InvalidArgumentException $error) {
                self::assertSame($field . '_invalid', $error->getMessage());
                self::assertSame(0, Db::name(BookingMonitoringService::TABLE)->count());
            }
        }
    }

    public function testFractionalOverviewHotelScopeCannotResolveToAnIntegerHotel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->overview(7, [80], [80.5], $this->query());
    }

    public function testDefaultBusinessDateAndNineOClockUseShanghaiWhenClockReturnsUtc(): void
    {
        $utcClock = new BookingMonitoringService(static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-01 23:59:59', new DateTimeZone('UTC')));
        $query = $this->query(); unset($query['business_date']);
        $view = $utcClock->overview(7, [80], [80], $query);
        self::assertSame('2026-10-02', $view['business_date']);
        self::assertSame('2026-10-02 09:00:00', $view['observation_time']);
        self::assertContains('fixed_observation_time_not_reached', $this->cell($view, 80, 1)['data_gaps']);
        $atNine = new BookingMonitoringService(static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-02 01:00:00', new DateTimeZone('UTC')));
        $atNine->saveSnapshots(7, [80], [$this->row('2026-10-01 09:00:00', 4), $this->row('2026-10-02 09:00:00', 5)], 9);
        $cell = $this->cell($atNine->overview(7, [80], [80], $query), 80, 1);
        self::assertSame('ready', $cell['status']); self::assertSame(24.0, $cell['elapsed_hours']); self::assertSame(1.0, $cell['net_pickup_24h_room_nights']);
    }

    public function testControllerDefaultBusinessDateDoesNotInheritServerTimezone(): void
    {
        $user = new class {
            public int $id = 9;
            public function getPermittedHotelIds(): array { return [80]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 80; }
        };
        $shanghai = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set((int)$shanghai->format('H') < 20 ? 'Etc/GMT+12' : 'Pacific/Kiritimati');
            $response = $this->controller(['hotel_ids' => '80', 'horizon_days' => 1], $user)->overview();
            self::assertSame(200, $response->getCode());
            self::assertSame($shanghai->format('Y-m-d'), $response->getData()['data']['business_date']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    private function controller(array $params, ?object $user, string $method = 'GET'): \app\controller\BookingMonitoring
    {
        $class = new ReflectionClass(\app\controller\BookingMonitoring::class);
        $controller = $class->newInstanceWithoutConstructor();
        $request = new class($params, $method) {
            public function __construct(private array $values, private string $verb) {}
            public function param(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
            public function post(): array { return $this->verb === 'POST' ? $this->values : []; }
            public function method(): string { return $this->verb; }
            public function getContent(): string { return ''; }
        };
        $class->getProperty('request')->setValue($controller, $request);
        $class->getProperty('currentUser')->setValue($controller, $user);
        return $controller;
    }

    private function service(string $now = '2026-10-02 12:00:00'): BookingMonitoringService
    {
        return new BookingMonitoringService(static fn(): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('Asia/Shanghai')));
    }

    private function row(string $capturedAt, float $rooms, int $roomId = 1): array
    {
        return ['hotel_id' => 80, 'room_type_id' => $roomId, 'platform' => 'ctrip', 'fact_scope' => 'ota_channel',
            'stay_date' => '2026-10-03', 'captured_at' => $capturedAt, 'on_books_room_nights' => $rooms,
            'on_books_room_revenue' => $rooms * 100, 'cumulative_cancel_room_nights' => 0, 'gross_booking_room_nights' => $rooms,
            'source_ref' => 'TEST-ONLY-synthetic-source-' . $capturedAt . '-' . $roomId, 'operator_attested' => true];
    }

    private function query(): array
    {
        return ['platform' => 'ctrip', 'business_date' => '2026-10-02', 'fixed_time' => '09:00', 'horizon_days' => 1];
    }

    private function cell(array $view, int $hotelId, int $roomId): array
    {
        foreach ($view['cells'] as $cell) if ($cell['hotel_id'] === $hotelId && $cell['room_type_id'] === $roomId) return $cell;
        self::fail('cell missing');
    }
}
