<?php
declare(strict_types=1);

namespace Tests;

use app\service\TransferDecisionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\Container;

final class TransferDecisionDayScopeTest extends TestCase
{
    public static function channelDayCases(): iterable
    {
        yield 'same daily date' => [['2026-09-14'], 2, 2, 1];
        yield 'one other current date' => [['2026-09-12'], 3, 3, 1];
        yield 'two other current dates' => [['2026-09-12', '2026-09-11'], 4, 4, 2];
        yield 'annual-only other date' => [['2026-08-01'], 2, 3, 0];
    }

    #[DataProvider('channelDayCases')]
    public function testVerifiedChannelDaysDoNotDiluteWholeHotelMetrics(array $dates, int $currentDays, int $annualDays, int $currentOtaCount): void
    {
        $daily = $this->fixedDaily();
        $baseline = $this->source($daily);
        $withOta = $this->source($daily, $this->otaRows($dates));
        self::assertSame($currentOtaCount, $withOta['snapshot']['truth_context']['included_verified_count']);
        self::assertSame(count($dates), $withOta['snapshot']['annual_truth_context']['included_verified_count']);
        self::assertSame('verified', $withOta['snapshot']['annual_truth_context']['status']);
        self::assertSame($currentDays, $withOta['snapshot']['current']['actual_days']);
        self::assertSame($annualDays, $withOta['snapshot']['annual']['actual_days']);
        self::assertSame(2, $withOta['snapshot']['current']['daily_report_days']);
        self::assertSame(2, $withOta['snapshot']['annual']['daily_report_days']);
        self::assertSame(50.0, $withOta['pricing_input']['occupancy_rate']);
        self::assertSame(50.0, $withOta['timing_input']['current_occupancy_rate']);
        self::assertSame(50.0, $withOta['timing_input']['previous_occupancy_rate']);
        self::assertSame(30.0, $withOta['timing_input']['previous_revenue']);
        self::assertSame($baseline['snapshot']['annual_benchmark']['revenue'], $withOta['snapshot']['annual_benchmark']['revenue']);
        foreach (['revenue', 'room_nights', 'room_count', 'adr', 'occupancy_rate'] as $field) {
            self::assertSame($baseline['snapshot']['current'][$field], $withOta['snapshot']['current'][$field], $field);
        }
        self::assertSame(30000.0 * $currentOtaCount, $withOta['snapshot']['current']['ota_channel_revenue']);
        self::assertSame(20 * $currentOtaCount, $withOta['snapshot']['current']['ota_channel_orders']);
        self::assertSame(25.0 * $currentOtaCount, $withOta['snapshot']['current']['ota_channel_room_nights']);
        self::assertSame((int)round(20 * count($dates) * 30 / $annualDays), $withOta['snapshot']['annual_benchmark']['orders'], 'existing channel-order scale is unchanged');
        self::assertFalse($withOta['snapshot']['source_verified']);
        self::assertTrue($withOta['snapshot']['current']['revenue_observed']);
        self::assertTrue($withOta['snapshot']['current']['occupancy_rate_observed']);
        self::assertSame(7, $withOta['hotel_id']);
        self::assertSame(42, $withOta['tenant_id']);
        self::assertSame('2026-09-14', $withOta['source_date']);
    }

    public function testUnverifiedOtherDatesRemainExcluded(): void
    {
        $ota = $this->otaRows(['2026-09-12']);
        $ota[0]['readback_verified'] = 0;
        $data = $this->source($this->fixedDaily(), $ota);
        self::assertSame(0, $data['snapshot']['truth_context']['included_verified_count']);
        self::assertSame(1, $data['snapshot']['truth_context']['excluded_untrusted_count']);
        self::assertSame(2, $data['snapshot']['current']['actual_days']);
        self::assertSame(50.0, $data['pricing_input']['occupancy_rate']);
        self::assertSame(30.0, $data['timing_input']['previous_revenue']);
    }

    public function testOtaOnlyCannotCreateWholeHotelFacts(): void
    {
        $data = $this->source([], $this->otaRows(['2026-09-12', '2026-09-11']));
        self::assertSame('ota_channel_only', $data['snapshot']['source_scope']);
        self::assertSame(2, $data['snapshot']['truth_context']['included_verified_count']);
        self::assertSame(2, $data['snapshot']['current']['actual_days']);
        self::assertSame(0, $data['snapshot']['current']['daily_report_days']);
        self::assertSame(60000.0, $data['snapshot']['current']['ota_channel_revenue']);
        foreach (['monthly_revenue', 'occupancy_rate', 'adr'] as $key) self::assertNull($data['pricing_input'][$key]);
        foreach (['current_revenue', 'previous_revenue', 'current_occupancy_rate', 'previous_occupancy_rate'] as $key) self::assertNull($data['timing_input'][$key]);
        self::assertFalse($data['snapshot']['annual_benchmark']['revenue_observed']);
    }

    public function testObservedZeroAndMissingReportMetricsRetainTheirDifferentMeaning(): void
    {
        $ota = $this->otaRows(['2026-09-12']);
        $zero = $this->source([$this->dailyRow(['revenue' => 0, 'room_nights' => 0, 'salable_rooms' => 80])], $ota);
        self::assertSame(0.0, $zero['pricing_input']['monthly_revenue']);
        self::assertSame(0.0, $zero['pricing_input']['occupancy_rate']);
        self::assertSame(0.0, $zero['timing_input']['previous_revenue']);
        self::assertTrue($zero['snapshot']['current']['occupancy_rate_observed']);
        self::assertTrue($zero['snapshot']['annual_benchmark']['revenue_observed']);
        self::assertNull($zero['pricing_input']['adr']);
        $missing = $this->source([$this->dailyRow([])], $ota);
        foreach (['monthly_revenue', 'occupancy_rate', 'adr'] as $key) self::assertNull($missing['pricing_input'][$key]);
        self::assertNull($missing['timing_input']['previous_revenue']);
        self::assertFalse($missing['snapshot']['annual_benchmark']['revenue_observed']);
    }

    public function testExplicitOccupancySamplesKeepTheExistingAverage(): void
    {
        $daily = $this->fixedDaily();
        foreach ($daily as $index => &$row) {
            $values = json_decode($row['report_data'], true);
            $values['occ'] = $index === 0 ? 0 : 80;
            $row['report_data'] = json_encode($values);
        }
        unset($row);
        $data = $this->source($daily, $this->otaRows(['2026-09-12']));
        self::assertSame(40.0, $data['pricing_input']['occupancy_rate']);
        self::assertSame(40.0, $data['timing_input']['previous_occupancy_rate']);
    }

    public function testMissingDailyBenchmarkDaysCannotBorrowCombinedCoverage(): void
    {
        $service = (new \ReflectionClass(TransferDecisionService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'annualThirtyDayBenchmark');
        $old = ['actual_days' => 60, 'revenue' => 600000, 'revenue_observed' => true, 'orders' => 120, 'adr' => 300, 'occupancy_rate' => 75];
        $complete = $method->invoke($service, array_replace($old, ['daily_report_days' => 60, 'revenue_observed_days' => 60]));
        self::assertSame(300000.0, $complete['revenue']);
        self::assertSame(60, $complete['orders']);
        self::assertTrue($complete['revenue_observed']);
        foreach ([$old, array_replace($old, ['daily_report_days' => 0]), array_replace($old, ['daily_report_days' => null])] as $input) {
            $missingDaily = $method->invoke($service, $input);
            self::assertFalse($missingDaily['revenue_observed'], 'a missing daily denominator cannot inherit OTA days');
            self::assertSame(60, $missingDaily['orders'], 'channel benchmark is not rescaled onto daily days');
            self::assertSame(60, $missingDaily['actual_days'], 'combined-source coverage remains metadata');
        }
    }

    private function fixedDaily(): array
    {
        return [
            $this->dailyRow(['revenue' => 10000, 'room_nights' => 40, 'salable_rooms' => 80]),
            $this->dailyRow(['revenue' => 10000, 'room_nights' => 40, 'salable_rooms' => 80], ['id' => 2, 'report_date' => '2026-09-13']),
        ];
    }

    private function dailyRow(array $data, array $overrides = []): array
    {
        return array_replace(['id' => 1, 'hotel_id' => 7, 'tenant_id' => 42, 'report_date' => '2026-09-14', 'status' => 2, 'report_data' => json_encode($data)], $overrides);
    }

    private function otaRows(array $dates): array
    {
        return array_map(fn(string $date, int $index): array => $this->verifiedOtaRow([
            'id' => 11 + $index, 'tenant_id' => 42, 'data_date' => $date,
            'snapshot_time' => $date . ' 09:00:00', 'create_time' => $date . ' 09:01:00', 'update_time' => $date . ' 09:01:00',
        ]), $dates, array_keys($dates));
    }

    private function source(array $daily, array $ota = []): array
    {
        TransferDayMemoryStore::reset($daily);
        TransferDayMemoryStore::$tables['online_daily_data'] = $ota;
        $instance = new \ReflectionProperty(Container::class, 'instance');
        $previous = $instance->getValue();
        $container = new Container();
        $container->instance('think\DbManager', new TransferDayMemoryDatabase());
        Container::setInstance($container);
        try {
            $service = (new \ReflectionClass(TransferDecisionService::class))->newInstanceWithoutConstructor();
            return $service->buildSourcePayload([7], 7, '2026-09-14');
        } finally {
            Container::setInstance($previous);
        }
    }

    private function verifiedOtaRow(array $overrides = []): array
    {
        $sourceUrlHash = str_repeat('d', 64);
        $row = array_merge([
            'id' => 1,
            'system_hotel_id' => 7,
            'hotel_id' => 'ctrip-7001',
            'hotel_name' => 'Hotel A',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'order',
            'data_date' => '2026-07-15',
            'amount' => 30000,
            'book_order_num' => 20,
            'quantity' => 25,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-safe-1',
            'source_url_hash' => $sourceUrlHash,
            'snapshot_time' => '2026-07-15 09:00:00',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'create_time' => '2026-07-15 09:01:00',
            'update_time' => '2026-07-15 09:01:00',
            'raw_data' => '{}',
        ], $overrides);

        $raw = is_array($row['raw_data'])
            ? $row['raw_data']
            : json_decode((string)$row['raw_data'], true);
        $raw = is_array($raw) ? $raw : [];
        $raw['source_trace_id'] = (string)$row['source_trace_id'];
        $raw['source_url_hash'] = $sourceUrlHash;
        $raw['field_facts'] = [];
        foreach ([
            'order_amount' => 'amount',
            'order_count' => 'book_order_num',
            'room_nights' => 'quantity',
        ] as $metricKey => $storageField) {
            $raw['field_facts'][] = [
                'metric_key' => $metricKey,
                'source_path' => '$.payload.' . $metricKey,
                'storage_field' => 'online_daily_data.' . $storageField,
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'source_trace_id' => (string)$row['source_trace_id'],
                    'source_url_hash' => $sourceUrlHash,
                ],
            ];
        }
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $row;
    }


}

final class TransferDayMemoryDatabase
{
    public function connect(): TransferDayMemoryConnection { return new TransferDayMemoryConnection(); }
    public function transaction(callable $callback): mixed { return $callback(); }
    public function name(string $table): TransferDayMemoryQuery { return new TransferDayMemoryQuery($table); }
}

    final class TransferDayMemoryStore {
        public static array $tables = [];
        public static array $queries = [];
        public static ?string $failTable = null;
        public static function reset(array $daily): void {
            self::$queries = [];
            self::$failTable = null;
            self::$tables = [
                'hotels' => [['id' => 7, 'tenant_id' => 42, 'name' => 'Synthetic zero report hotel', 'address' => 'Synthetic address']],
                'daily_reports' => $daily,
                'online_daily_data' => [],
                'transfer_records' => [],
                'report_configs' => array_map(static fn(string $key): array => ['field_name' => $key, 'report_type' => 'daily', 'status' => 1], ['revenue', 'room_nights', 'occ', 'salable_rooms', 'xb_revenue']),
            ];
        }
    }
    final class TransferDayMemoryQuery {
        private array $filters = [];
        private array $trace = [];
        public function __construct(private string $table) {
            if (!array_key_exists($table, TransferDayMemoryStore::$tables)) throw new \LogicException('Unexpected synthetic table: ' . $table);
        }
        private function key(string $field): string { return substr($field, (int)(strrpos('.' . $field, '.'))); }
        public function where(string $field, mixed $value): self {
            $key = $this->key($field);
            $this->filters[] = static fn(array $row): bool => ($row[$key] ?? null) === $value;
            $this->trace[] = ['where', $field, $value];
            return $this;
        }
        public function whereIn(string $field, array $values): self {
            $key = $this->key($field);
            $this->filters[] = static fn(array $row): bool => in_array($row[$key] ?? null, $values, true);
            $this->trace[] = ['whereIn', $field, $values];
            return $this;
        }
        public function whereBetween(string $field, array $range): self {
            $key = $this->key($field);
            $this->filters[] = static fn(array $row): bool => isset($row[$key]) && $row[$key] >= $range[0] && $row[$key] <= $range[1];
            $this->trace[] = ['whereBetween', $field, $range];
            return $this;
        }
        public function alias(string $alias): self { return $this; }
        public function join(string $table, string $on): self {
            if (!str_starts_with($table, 'hotels ')) throw new \LogicException('Unexpected synthetic join');
            $identityKey = $this->table === 'online_daily_data' ? 'system_hotel_id' : 'hotel_id';
            $this->filters[] = static function(array $row) use ($identityKey): bool {
                foreach (TransferDayMemoryStore::$tables['hotels'] as $hotel) {
                    if ($hotel['id'] === ($row[$identityKey] ?? null) && $hotel['tenant_id'] === ($row['tenant_id'] ?? null)) return true;
                }
                return false;
            };
            return $this;
        }
        public function whereColumn(string $left, string $right): self { return $this; }
        public function lock(bool $lock): self { if (!$lock) throw new \LogicException('Expected locked hotel read'); return $this; }
        public function field(string $fields): self { return $this; }
        private function rows(): array {
            if (TransferDayMemoryStore::$failTable === $this->table) throw new \RuntimeException('Synthetic source read unavailable');
            $rows = array_values(array_filter(TransferDayMemoryStore::$tables[$this->table], function(array $row): bool {
                foreach ($this->filters as $filter) if (!$filter($row)) return false;
                return true;
            }));
            TransferDayMemoryStore::$queries[] = ['table' => $this->table, 'filters' => $this->trace, 'count' => count($rows)];
            return $rows;
        }
        public function find(): ?array { return $this->rows()[0] ?? null; }
        public function select(): object { return new class($this->rows()) { public function __construct(private array $rows) {} public function toArray(): array { return $this->rows; } }; }
        public function column(string $field): array { return array_column($this->rows(), $field); }
    }
    final class TransferDayMemoryConnection {
        public function getSchemaInfo(string $table, bool $refresh): array {
            if (!$refresh || !array_key_exists($table, TransferDayMemoryStore::$tables)) throw new \LogicException('Unexpected schema request');
            return ['fields' => ['id', 'record_type', 'tenant_id', 'hotel_id', 'hotel_name', 'source_date', 'input_json', 'result_json', 'snapshot_json', 'decision', 'risk_level', 'created_by', 'created_at', 'updated_at', 'deleted_at', 'report_date', 'report_data', 'system_hotel_id', 'data_date']];
        }
    }
