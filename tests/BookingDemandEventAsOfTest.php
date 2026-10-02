<?php
declare(strict_types=1);

use app\service\BookingDemandPlanningService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class BookingDemandEventAsOfTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH') . '/logs/']]], 'log');
        Config::set(['default' => 'event_as_of_test', 'connections' => ['event_as_of_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insertAll([['id' => 80, 'tenant_id' => 7], ['id' => 82, 'tenant_id' => 7], ['id' => 81, 'tenant_id' => 8]]);
        Db::execute('CREATE TABLE hotel_on_books_snapshots (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, platform TEXT, stay_date TEXT, captured_at TEXT, quality_status TEXT, readback_verified INTEGER)');
        Db::execute('CREATE TABLE hotel_demand_event_facts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, contract_version TEXT, tenant_id INTEGER, hotel_id INTEGER,
            source_hotel_id INTEGER, event_name TEXT, event_type TEXT, event_start_date TEXT, event_end_date TEXT,
            area_label TEXT, source_method TEXT, source_ref_hash TEXT, source_status TEXT, observed_at TEXT,
            reference_only INTEGER, idempotency_key TEXT, content_digest TEXT, created_by INTEGER, created_at TEXT,
            UNIQUE (tenant_id, hotel_id, idempotency_key))');
    }

    protected function tearDown(): void { Db::connect()->close(); }

    private function service(string $now = '2026-09-01 12:00:00'): BookingDemandPlanningService
    {
        return new BookingDemandPlanningService(static fn(): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('Asia/Shanghai')));
    }

    private function save(string $observed, string $name, int $hotel = 80, int $tenant = 7): array
    {
        return $this->service()->saveDemandEvent($tenant, [$hotel], $hotel, [
            'event_name' => $name, 'event_type' => 'exhibition', 'event_start_date' => '2026-08-31',
            'event_end_date' => '2026-09-06', 'area_label' => '合成区域', 'source_method' => 'manual_reference',
            'source_ref' => 'synthetic-reference-' . $name, 'source_status' => 'reference_only',
            'observed_at' => $observed, 'idempotency_key' => 'synthetic-event-' . $name,
        ], 11);
    }

    public function testHistoricalPlanUsesSnapshotCutoffForEventsAndKeepsExactSavedReadback(): void
    {
        $older = $this->save('2026-08-30 09:00:00', '已观察参考');
        $boundary = $this->save('2026-08-30 23:59:59.999999', '截止边界参考');
        $later = $this->save('2026-08-31 00:00:00', '截止后参考');
        $plan = $this->service()->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');
        self::assertSame('2026-08-30 23:59:59.999999', $plan['as_of_time']);
        foreach ($plan['windows'] as $window) {
            self::assertSame([$older['id'], $boundary['id']], array_column($window['events'], 'id'));
            self::assertSame(2, $window['event_count']);
            self::assertNull($window['on_books_room_nights_total']);
            foreach ($window['events'] as $event) {
                self::assertTrue($event['reference_only']); self::assertFalse($event['automatic_pricing']);
                self::assertSame('reference_only', $event['source_status']);
                self::assertSame(7, $event['tenant_id']); self::assertSame(80, $event['hotel_id']);
            }
        }
        $exact = $this->service()->readDemandEvent(7, 80, $later['id']);
        self::assertSame($later['content_digest'], $exact['content_digest']);
        self::assertSame('2026-08-31 00:00:00.000000', $exact['observed_at']);
        self::assertSame(3, (int)Db::name('hotel_demand_event_facts')->count());
    }

    public function testCurrentDayUsesActualClockRatherThanEndOfDay(): void
    {
        $beforeClock = $this->save('2026-08-30 11:59:59.999999', '时钟之前');
        $atClock = $this->save('2026-08-30 12:00:00', '时钟边界');
        $this->save('2026-08-30 12:00:00.000001', '时钟之后');
        $plan = $this->service('2026-08-30 12:00:00')->demandPlan(7, [80], 80, 'meituan', '2026-08-30');
        self::assertSame('2026-08-30 12:00:00.000000', $plan['as_of_time']);
        foreach ($plan['windows'] as $window) self::assertSame([$beforeClock['id'], $atClock['id']], array_column($window['events'], 'id'));
    }

    public function testNoEligibleEventIsEmptyAndLaterBusinessDayCanReadIt(): void
    {
        $later = $this->save('2026-08-31 10:00:00', '后续观察');
        $this->save('2026-08-29 10:00:00', '其他酒店', 82);
        $this->save('2026-08-29 10:00:00', '其他租户', 81, 8);
        $old = $this->service()->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');
        foreach ($old['windows'] as $window) { self::assertSame([], $window['events']); self::assertSame(0, $window['event_count']); }
        $current = $this->service()->demandPlan(7, [80], 80, 'ctrip', '2026-08-31');
        foreach ($current['windows'] as $window) self::assertSame([$later['id']], array_column($window['events'], 'id'));
    }

    public function testDirectCalendarRetainsCurrentReadSemanticsAndBackdatedReferenceRemainsVisible(): void
    {
        $earlier = $this->save('2026-08-29 10:00:00', '补录早期观察');
        $later = $this->save('2026-08-31 10:00:00', '独立日历观察');
        self::assertSame('2026-09-01 12:00:00.000000', $earlier['created_at']);
        $calendar = $this->service()->demandCalendar(7, [80], 80, '2026-08-31', '2026-09-06');
        self::assertSame([$earlier['id'], $later['id']], array_column($calendar['events'], 'id'));
        $historical = $this->service()->demandPlan(7, [80], 80, 'ctrip', '2026-08-30');
        self::assertSame([$earlier['id']], array_column($historical['windows'][2]['events'], 'id'));
    }

    public function testExplicitInvalidCutoffIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('as_of_time_invalid');
        $this->service()->demandCalendar(7, [80], 80, '2026-08-31', '2026-09-06', 'not-a-time');
    }

    public function testExplicitEmptyCutoffDoesNotSilentlyReadAllEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('as_of_time_invalid');
        $this->service()->demandCalendar(7, [80], 80, '2026-08-31', '2026-09-06', '');
    }
}
