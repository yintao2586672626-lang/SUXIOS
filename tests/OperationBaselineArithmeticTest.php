<?php
declare(strict_types=1);

use app\service\operation\OperationBaselineConcern;
use PHPUnit\Framework\TestCase;

/** Pure array fixture: no database, collector, model or external request. */
final class OperationBaselineArithmeticTest extends TestCase
{
    public function testFiniteOverflowedSumStillHasAFiniteSameScopeAverage(): void
    {
        $result = (new OperationBaselineArithmeticFixture([1e308, 1e308]))->run();
        self::assertSame(1e308, $result['avg_revenue']);
        self::assertSame(1.0, $result['avg_orders']);
        self::assertSame(1.0, $result['avg_room_nights']);
        self::assertSame('ok', $result['data_status']);
        self::assertSame([], $result['data_gaps']);
        self::assertSame(['ota_channel'], $result['source_scopes']);
        self::assertNotFalse(json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testNonFiniteMetricCannotMasqueradeAsAnOkBaseline(): void
    {
        foreach ([INF, NAN] as $invalid) {
            $result = (new OperationBaselineArithmeticFixture([100.0, $invalid]))->run();
            self::assertNull($result['avg_revenue']);
            self::assertSame(1.0, $result['avg_orders']);
            self::assertSame(1.0, $result['avg_room_nights']);
            self::assertSame('partial', $result['data_status']);
            self::assertContains('baseline_metric_average_nonfinite', array_column($result['data_gaps'], 'code'));
            self::assertSame(['ota_channel'], $result['source_scopes']);
            self::assertNotFalse(json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function testOrdinaryAverageAndExplicitZeroKeepEstablishedRounding(): void
    {
        foreach ([[100.0, 200.0], [0.0, 0.0], [0.14, 0.15]] as $values) {
            $result = (new OperationBaselineArithmeticFixture($values))->run();
            self::assertSame(round(array_sum($values) / count($values), 2), $result['avg_revenue']);
            self::assertSame('ok', $result['data_status']);
            self::assertSame([], $result['data_gaps']);
        }
    }
}

final class OperationBaselineArithmeticFixture
{
    use OperationBaselineConcern;
    private const DATA_OK = 'ok';

    public function __construct(private readonly array $values) {}
    public function run(): array { return $this->baseline([80], count($this->values), '2026-10-03'); }
    private function dailyReportRows(array $hotelIds, string $startDate, string $endDate): array { return []; }
    private function onlineRows(array $hotelIds, string $startDate, string $endDate): array
    {
        $start = new DateTimeImmutable($startDate);
        return array_map(static fn(int $offset, float $value): array => ['data_date' => $start->modify('+' . $offset . ' days')->format('Y-m-d'), 'revenue' => $value], array_keys($this->values), $this->values);
    }
    private function scopeDailyReportRowsToCurrentTenant(array $rows, array $ids): array { return ['rows' => $rows, 'gap' => null]; }
    private function scopeOnlineRowsToCurrentTenant(array $rows, array $ids): array { return ['rows' => $rows, 'gap' => null]; }
    private function buildSummaryFromTenantScopedRows(array $daily, array $online, array $hotelIds, ?int $hotelId, string $date): array
    {
        return ['data_status' => 'ok', 'orders' => 1, 'revenue' => $online[0]['revenue'], 'room_nights' => 1,
            'source_scope' => 'ota_channel', 'metric_scopes' => ['orders' => ['ota_channel'], 'revenue' => ['ota_channel'], 'room_nights' => ['ota_channel']],
            'evidence_refs' => [['source_ref' => 'online_daily_data#synthetic', 'source' => 'ctrip', 'platform' => 'ctrip', 'metric_keys' => ['orders', 'revenue', 'room_nights']]]];
    }
    private function normalizeOtaChannel(string $value): string { return $value; }
    private function latestOnlineFlowRows(array $rows): array { return []; }
}
