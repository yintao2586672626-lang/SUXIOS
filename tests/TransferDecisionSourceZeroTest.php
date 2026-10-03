<?php
declare(strict_types=1);

namespace Tests;

use app\service\TransferDecisionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\Container;

final class TransferDecisionSourceZeroTest extends TestCase
{
    public static function sourceCases(): iterable
    {
        yield 'explicit zero' => [['revenue' => 0, 'room_nights' => 0, 'occ' => 0, 'salable_rooms' => 80], [], 0.0, 0.0, null];
        yield 'numeric string zero' => [['revenue' => '0', 'room_nights' => '0', 'occ' => '0', 'salable_rooms' => 80], [], 0.0, 0.0, null];
        yield 'zero ADR defined by positive room nights' => [['revenue' => 0, 'room_nights' => 1, 'occ' => 1.25, 'salable_rooms' => 80], [], 0.0, 1.25, 0.0];
        yield 'canonical zero before positive aliases' => [['revenue' => 0, 'day_revenue' => 999, 'room_nights' => 0, 'occupied_rooms' => 20, 'occ' => 0, 'salable_rooms' => 80], ['guest_count' => 30], 0.0, 0.0, null];
        yield 'row canonical zero before nested fallback' => [['revenue' => 999, 'room_nights' => 0, 'occ' => 50], ['revenue' => 0, 'occupancy_rate' => 0], 0.0, 0.0, null];
        yield 'legacy observed detail zero' => [['xb_revenue' => 0, 'mt_revenue' => 0, 'xb_rooms' => 0, 'mt_rooms' => 0, 'occ' => 0], [], 0.0, 0.0, null];
        yield 'legacy positive details' => [['xb_revenue' => 600, 'mt_revenue' => 400, 'xb_rooms' => 2, 'mt_rooms' => 3, 'occ' => 50], [], 0.1, 50.0, 200.0];
        yield 'canonical absent legal aliases' => [['day_revenue' => 1000, 'occupied_rooms' => 5, 'occupancy_rate' => 50], [], 0.1, 50.0, 200.0];
        yield 'missing report metrics' => [[], [], null, null, null];
        yield 'explicit null report metrics' => [['revenue' => null, 'room_nights' => null, 'occ' => null], [], null, null, null];
        yield 'blank report metrics' => [['revenue' => '', 'room_nights' => '', 'occ' => ''], [], null, null, null];
        yield 'nonnumeric report strings' => [['revenue' => 'unknown', 'room_nights' => 'unknown', 'occ' => 'unknown'], [], null, null, null];
        yield 'overflow numeric strings' => [['revenue' => '1e309', 'room_nights' => '1e309', 'occ' => '1e309'], [], null, null, null];
        yield 'positive infinite row metrics' => [[], ['revenue' => INF, 'guest_count' => INF, 'occupancy_rate' => INF], null, null, null];
        yield 'negative infinite row metrics' => [[], ['revenue' => -INF, 'guest_count' => -INF, 'occupancy_rate' => -INF], null, null, null];
        yield 'NaN row metrics' => [[], ['revenue' => NAN, 'guest_count' => NAN, 'occupancy_rate' => NAN], null, null, null];
        yield 'boolean or blank is not an observed zero' => [['revenue' => false, 'room_nights' => '', 'occ' => false], [], null, null, null];
    }

    #[DataProvider('sourceCases')]
    public function testPublicSourcePreservesOnlyObservedMetrics(array $data, array $row, ?float $revenue, ?float $occupancy, ?float $adr): void
    {
        $result = $this->source([$this->row($data, $row)]);
        self::assertSame($revenue, $result['pricing_input']['monthly_revenue']);
        self::assertSame($occupancy, $result['pricing_input']['occupancy_rate']);
        self::assertSame($adr, $result['pricing_input']['adr']);
        self::assertSame($revenue, $result['timing_input']['current_revenue']);
        self::assertSame($occupancy, $result['timing_input']['current_occupancy_rate']);
        self::assertSame($adr, $result['timing_input']['current_adr']);
        self::assertSame($revenue === null ? null : round($revenue * 30, 2), $result['timing_input']['previous_revenue']);
        self::assertSame($occupancy, $result['timing_input']['previous_occupancy_rate']);
        self::assertSame($adr, $result['timing_input']['previous_adr']);
        self::assertSame($revenue !== null, $result['snapshot']['current']['revenue_observed']);
        self::assertSame($occupancy !== null, $result['snapshot']['current']['occupancy_rate_observed']);
        self::assertSame($adr !== null, $result['snapshot']['current']['adr_observed']);
        self::assertFalse($result['snapshot']['source_verified']);
        self::assertSame('local_daily_report_only', $result['snapshot']['source_scope']);
        self::assertNull($result['pricing_input']['ota_channel_revenue']);
        self::assertSame(7, $result['hotel_id']);
        self::assertSame(42, $result['tenant_id']);
        self::assertSame('2026-09-14', $result['source_date']);
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testZeroOccupancyParticipatesInExistingDailyAverage(): void
    {
        $result = $this->source([
            $this->row(['revenue' => 0, 'room_nights' => 0, 'occ' => 0, 'salable_rooms' => 80]),
            $this->row(['revenue' => 1000, 'room_nights' => 40, 'occ' => 50, 'salable_rooms' => 80], ['id' => 2, 'report_date' => '2026-09-13']),
        ]);
        self::assertSame(25.0, $result['pricing_input']['occupancy_rate']);
        self::assertSame(25.0, $result['timing_input']['previous_occupancy_rate']);
        self::assertSame(25.0, $result['pricing_input']['adr']);
    }

    public function testNoRecordsAndReadFailureCannotBecomeObservedZero(): void
    {
        $result = $this->source([]);
        foreach (['monthly_revenue', 'occupancy_rate', 'adr'] as $field) self::assertNull($result['pricing_input'][$field]);
        foreach (['revenue_observed', 'room_nights_observed', 'occupancy_rate_observed', 'adr_observed'] as $field) self::assertFalse($result['snapshot']['current'][$field]);
        self::assertSame('no_records', $result['snapshot']['source_scope']);
        $this->expectExceptionMessage('transfer_source_read_failed:daily_reports');
        $this->source([], true);
    }

    public function testUnknownRevenueOrRoomNightsDoesNotProduceAnObservedAdr(): void
    {
        foreach ([['room_nights' => 2], ['revenue' => 0], ['revenue' => 1000, 'room_nights' => 0]] as $data) {
            $result = $this->source([$this->row($data)]);
            self::assertNull($result['pricing_input']['adr']);
            self::assertFalse($result['snapshot']['current']['adr_observed']);
        }
    }

    public static function overflowingSourceCases(): iterable
    {
        yield 'finite daily revenue accumulation' => [[
            ['revenue' => 1e308, 'room_nights' => 1, 'occ' => 0],
            ['revenue' => 1e308, 'room_nights' => 1, 'occ' => 0],
        ]];
        yield 'finite negative revenue accumulation' => [[
            ['revenue' => -1e308, 'room_nights' => 1, 'occ' => 0],
            ['revenue' => -1e308, 'room_nights' => 1, 'occ' => 0],
        ]];
        yield 'finite revenue detail accumulation' => [[
            ['xb_revenue' => 1e308, 'mt_revenue' => 1e308, 'walkin_revenue' => -1e308, 'room_nights' => 1, 'occ' => 0],
        ]];
        yield 'finite room nights must not yield an observed zero ADR' => [[
            ['revenue' => 1000, 'room_nights' => 1e308, 'occ' => 0],
            ['revenue' => 1000, 'room_nights' => 1e308, 'occ' => 0],
        ]];
        yield 'finite room detail accumulation' => [[
            ['revenue' => 1000, 'xb_rooms' => 1e308, 'mt_rooms' => 1e308, 'occ' => 0],
        ]];
        yield 'finite inputs overflow derived ADR' => [[
            ['revenue' => 1e308, 'room_nights' => 0.0001, 'occ' => 0],
        ]];
        yield 'finite inputs overflow derived occupancy' => [[
            ['revenue' => 0, 'room_nights' => 1e308, 'salable_rooms' => 1],
        ]];
        yield 'finite occupancy average accumulation' => [[
            ['revenue' => 0, 'room_nights' => 1, 'occ' => 1e308],
            ['revenue' => 0, 'room_nights' => 1, 'occ' => 1e308],
        ]];
        yield 'finite annual revenue overflows thirty day benchmark' => [[
            ['revenue' => 1e307, 'room_nights' => 1, 'occ' => 0],
        ]];
    }

    #[DataProvider('overflowingSourceCases')]
    public function testPublicSourceRejectsOverflowInsteadOfReturningCorruptMetrics(array $reports): void
    {
        $rows = [];
        foreach ($reports as $index => $data) {
            $rows[] = $this->row($data, ['id' => $index + 1, 'report_date' => '2026-09-' . (14 - $index)]);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('转让测算历史来源超出计算范围');
        $this->source($rows);
    }

    public function testOverflowInAnnualWindowCannotHideBehindFiniteCurrentMetrics(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('转让测算历史来源超出计算范围');
        $this->source([
            $this->row(['revenue' => 1000, 'room_nights' => 5, 'occ' => 50]),
            $this->row(['revenue' => 1e308, 'room_nights' => 1, 'occ' => 0], ['id' => 2, 'report_date' => '2026-08-01']),
            $this->row(['revenue' => 1e308, 'room_nights' => 1, 'occ' => 0], ['id' => 3, 'report_date' => '2026-07-31']),
        ]);
    }

    public function testRawRoomNightOverflowCannotBecomeAnObservedZeroAdr(): void
    {
        $service = (new \ReflectionClass(TransferDecisionService::class))->newInstanceWithoutConstructor();
        $aggregate = new \ReflectionMethod($service, 'aggregateTransferMetrics');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('转让测算历史来源超出计算范围');
        $aggregate->invoke($service, [
            $this->row(['revenue' => 1000, 'room_nights' => 1e308, 'occ' => 0]),
            $this->row(['revenue' => 1000, 'room_nights' => 1e308, 'occ' => 0], ['id' => 2, 'report_date' => '2026-09-13']),
        ], []);
    }

    public function testControllerReportsActualSourceOverflowAsFailure(): void
    {
        $instance = new \ReflectionProperty(Container::class, 'instance');
        $previous = $instance->getValue();
        try {
            // App is not initialized; all source reads remain on the closed memory adapter.
            $app = new \think\App();
            $app->instance('think\DbManager', new TransferSourceZeroMemoryDatabase([
                $this->row(['revenue' => 1000, 'room_nights' => 1e308, 'occ' => 0]),
                $this->row(['revenue' => 1000, 'room_nights' => 1e308, 'occ' => 0], ['id' => 2, 'report_date' => '2026-09-13']),
            ], false));
            $request = (new \think\Request())->withGet(['hotel_id' => 7, 'date' => '2026-09-14']);
            $request->user = new class {
                public int $id = 3;
                public function getPermittedHotelIds(): array { return [7]; }
                public function isSuperAdmin(): bool { return false; }
            };
            $app->instance('request', $request);
            $service = (new \ReflectionClass(TransferDecisionService::class))->newInstanceWithoutConstructor();
            $response = (new \app\controller\TransferDecision($app, $service))->source();

            self::assertSame(400, $response->getCode());
            self::assertSame(400, $response->getData()['code']);
            self::assertStringContainsString('转让测算历史来源超出计算范围', $response->getData()['message']);
            self::assertNull($response->getData()['data']);
            self::assertIsString(json_encode($response->getData(), JSON_THROW_ON_ERROR));
        } finally {
            Container::setInstance($previous);
        }
    }

    public function testOutOfScopeExtremeRowsDoNotChangeFiniteSameHotelSource(): void
    {
        $result = $this->source([
            $this->row(['revenue' => 1000, 'room_nights' => 5, 'occ' => 50]),
            $this->row(['revenue' => 1e308, 'room_nights' => 1, 'occ' => 0], ['id' => 2, 'hotel_id' => 8]),
            $this->row(['revenue' => 1e308, 'room_nights' => 1, 'occ' => 0], ['id' => 3, 'tenant_id' => 43]),
            $this->row(['revenue' => 1e308, 'room_nights' => 1, 'occ' => 0], ['id' => 4, 'report_date' => '2025-09-14']),
            $this->row(['revenue' => 1e308, 'room_nights' => 1, 'occ' => 0], ['id' => 5, 'report_date' => '2026-09-15']),
        ]);

        self::assertSame(1000.0, $result['snapshot']['current']['revenue']);
        self::assertSame(1000.0, $result['snapshot']['annual']['revenue']);
        self::assertSame(0.1, $result['pricing_input']['monthly_revenue']);
        self::assertSame(200.0, $result['pricing_input']['adr']);
        self::assertSame(50.0, $result['pricing_input']['occupancy_rate']);
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function row(array $data, array $overrides = []): array
    {
        return array_replace(['id' => 1, 'tenant_id' => 42, 'hotel_id' => 7, 'report_date' => '2026-09-14', 'report_data' => json_encode($data)], $overrides);
    }

    private function source(array $daily, bool $readFailure = false): array
    {
        // Plain Container + a closed in-memory read adapter: never initialize App, Env or a DB connection.
        $instance = new \ReflectionProperty(Container::class, 'instance');
        $previous = $instance->getValue();
        $container = new Container();
        $container->instance('think\DbManager', new TransferSourceZeroMemoryDatabase($daily, $readFailure));
        Container::setInstance($container);
        try {
            $service = (new \ReflectionClass(TransferDecisionService::class))->newInstanceWithoutConstructor();
            return $service->buildSourcePayload([7], 7, '2026-09-14');
        } finally {
            Container::setInstance($previous);
        }
    }
}

final class TransferSourceZeroMemoryDatabase
{
    public function __construct(private array $daily, private bool $readFailure) {}
    public function connect(): self { return $this; }
    public function getSchemaInfo(string $table, bool $refresh): array
    {
        if (!$refresh || !in_array($table, ['hotels', 'daily_reports', 'online_daily_data', 'transfer_records'], true)) throw new \LogicException('Unexpected schema read');
        return ['fields' => ['id', 'record_type', 'tenant_id', 'hotel_id', 'hotel_name', 'source_date', 'input_json', 'result_json', 'snapshot_json', 'decision', 'risk_level', 'created_by', 'created_at', 'updated_at', 'deleted_at', 'report_date', 'system_hotel_id', 'data_date']];
    }
    public function transaction(callable $callback): mixed { return $callback(); }
    public function name(string $table): TransferSourceZeroMemoryQuery
    {
        $rows = match ($table) {
            'hotels' => [['id' => 7, 'tenant_id' => 42, 'name' => 'Synthetic report hotel', 'address' => 'Synthetic address']],
            'daily_reports' => $this->daily,
            'online_daily_data' => [],
            default => throw new \LogicException('Unexpected query table'),
        };
        return new TransferSourceZeroMemoryQuery($rows, $table === 'daily_reports' && $this->readFailure);
    }
}

final class TransferSourceZeroMemoryQuery
{
    public function __construct(private array $rows, private bool $readFailure) {}
    private function key(string $field): string { $parts = explode('.', $field); return end($parts); }
    public function where(string $field, mixed $value): self { $key = $this->key($field); $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => ($row[$key] ?? null) === $value)); return $this; }
    public function whereIn(string $field, array $values): self { $key = $this->key($field); $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => in_array($row[$key] ?? null, $values, true))); return $this; }
    public function whereBetween(string $field, array $range): self { $key = $this->key($field); $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => ($row[$key] ?? '') >= $range[0] && ($row[$key] ?? '') <= $range[1])); return $this; }
    public function alias(string $alias): self { return $this; }
    public function join(string $table, string $on): self { if (!str_starts_with($table, 'hotels ')) throw new \LogicException('Unexpected join'); return $this; }
    public function whereColumn(string $left, string $right): self { return $this; }
    public function field(string $field): self { return $this; }
    public function lock(bool $lock): self { return $this; }
    public function find(): ?array { return $this->rows[0] ?? null; }
    public function select(): object
    {
        if ($this->readFailure) throw new \RuntimeException('Synthetic read failure');
        return new class($this->rows) { public function __construct(private array $rows) {} public function toArray(): array { return $this->rows; } };
    }
}
