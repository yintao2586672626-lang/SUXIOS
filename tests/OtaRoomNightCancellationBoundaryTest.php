<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OtaRoomNightCancellationBoundaryTest extends TestCase
{
    #[DataProvider('uncoveredScopeProvider')]
    public function testMissingCancellationEvidenceCannotBecomeACompleteSummary(
        string $date,
        string $platform
    ): void {
        $metrics = $this->summarize([
            $this->row(1, '2026-09-10', 'ctrip', 6, 1),
            $this->row(2, $date, $platform, 10, null),
        ]);

        $this->assertUnavailable($metrics, 'cancel_room_nights_partial');
        self::assertSame([1, 2], $this->trust($metrics)['source']['row_ids']);
    }

    public static function uncoveredScopeProvider(): array
    {
        return [
            'uncovered business date' => ['2026-09-11', 'ctrip'],
            'uncovered platform' => ['2026-09-10', 'meituan'],
        ];
    }

    public function testVerifiedZeroDenominatorRemainsUncalculableAndUntrusted(): void
    {
        $metrics = $this->summarize([
            $this->row(1, '2026-09-10', 'ctrip', 0, 0),
        ]);

        $this->assertUnavailable($metrics, 'cancel_room_nights_denominator_zero');
    }

    #[DataProvider('invalidCountsProvider')]
    public function testInvalidRoomNightCountsCannotProduceAVerifiedRate(
        float $roomNights,
        float $cancelledRoomNights
    ): void {
        $metrics = $this->summarize([
            $this->row(1, '2026-09-10', 'ctrip', $roomNights, $cancelledRoomNights),
        ]);

        $this->assertUnavailable($metrics, 'cancel_room_nights_invalid');
    }

    public static function invalidCountsProvider(): array
    {
        return [
            'negative numerator' => [6.0, -1.0],
            'negative denominator' => [-6.0, 1.0],
            'cancelled nights exceed base' => [6.0, 8.0],
        ];
    }

    public function testCompletePeriodUsesSummedCountsInsteadOfAveragingDailyRates(): void
    {
        $metrics = $this->summarize([
            $this->row(1, '2026-09-10', 'ctrip', 10, 1),
            $this->row(2, '2026-09-11', 'ctrip', 90, 27),
        ]);

        self::assertSame(28.0, $metrics['totals']['room_night_cancellation_rate']);
        self::assertTrue($this->trust($metrics)['saved_success']);
        self::assertSame([1, 2], $this->trust($metrics)['source']['row_ids']);
    }

    public function testVerifiedZeroCancelledNightsWithPositiveBaseRemainsZero(): void
    {
        $metrics = $this->summarize([
            $this->row(1, '2026-09-10', 'ctrip', 6, 0),
        ]);

        self::assertSame(0.0, $metrics['totals']['room_night_cancellation_rate']);
        self::assertTrue($this->trust($metrics)['saved_success']);
        self::assertSame([], $this->trust($metrics)['failure_reasons']);
    }

    public function testVerifiedZeroActivityDayDoesNotBlockACompletePositivePeriod(): void
    {
        $metrics = $this->summarize([
            $this->row(1, '2026-09-10', 'ctrip', 0, 0),
            $this->row(2, '2026-09-11', 'ctrip', 6, 1),
        ]);

        self::assertSame(16.67, $metrics['totals']['room_night_cancellation_rate']);
        self::assertTrue($this->trust($metrics)['saved_success']);
        self::assertSame([1, 2], $this->trust($metrics)['source']['row_ids']);
    }

    public function testExplicitSameScopeAdjustmentWithBothCountsKeepsTheSumFormula(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row(1, '2026-09-10', 'ctrip', 6, 1),
        ]);
        $adjustment = $dataset['fact_ota_daily'][0];
        $adjustment['data_type'] = 'order';
        $adjustment['dimension'] = 'room_nights_adjustment';
        $adjustment['room_nights'] = 2.0;
        $adjustment['cancel_room_nights'] = 1.0;
        $adjustment['source_trace']['row_id'] = 2;
        $adjustment['source_trace']['source_trace_id'] = 'fixture-2';
        $adjustment['source_trace']['data_type'] = 'order';
        $dataset['fact_ota_daily'][] = $adjustment;

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(25.0, $metrics['totals']['room_night_cancellation_rate']);
        self::assertTrue($this->trust($metrics)['saved_success']);
        self::assertSame([1, 2], $this->trust($metrics)['source']['row_ids']);
    }

    public function testSameScopeAdjustmentCannotSilentlyInventZeroCancelledNights(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row(1, '2026-09-10', 'ctrip', 6, 1),
        ]);
        $adjustment = $dataset['fact_ota_daily'][0];
        $adjustment['data_type'] = 'order';
        $adjustment['dimension'] = 'room_nights_adjustment';
        $adjustment['room_nights'] = 2.0;
        $adjustment['cancel_room_nights'] = null;
        $adjustment['source_trace']['row_id'] = 2;
        $adjustment['source_trace']['source_trace_id'] = 'fixture-2';
        $adjustment['source_trace']['data_type'] = 'order';
        $dataset['fact_ota_daily'][] = $adjustment;

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        $this->assertUnavailable($metrics, 'cancel_room_nights_partial');
        self::assertSame([1, 2], $this->trust($metrics)['source']['row_ids']);
    }

    private function assertUnavailable(array $metrics, string $reason): void
    {
        self::assertNull($metrics['totals']['room_night_cancellation_rate']);
        self::assertFalse($this->trust($metrics)['saved_success']);
        self::assertContains($reason, $this->trust($metrics)['failure_reasons']);
        self::assertContains($reason, array_column($metrics['data_gaps'], 'code'));
        self::assertNotSame('verified', $this->trust($metrics)['truth']['status']);
    }

    private function trust(array $metrics): array
    {
        return $metrics['metric_trust']['totals.room_night_cancellation_rate'];
    }

    private function summarize(array $rows): array
    {
        return (new OtaRevenueMetricService())->summarizeDataset(
            (new OtaStandardEtlService())->buildDatasetFromRows($rows)
        );
    }

    private function row(
        int $id,
        string $date,
        string $platform,
        float $roomNights,
        ?float $cancelledRoomNights
    ): array {
        return [
            'id' => $id,
            'system_hotel_id' => 7,
            'hotel_id' => $platform . '-7',
            'source' => $platform,
            'data_type' => 'business',
            'data_date' => $date,
            'source_trace_id' => 'fixture-' . $id,
            'readback_verified' => 1,
            'collected_at' => $date . ' 09:55:00',
            'update_time' => $date . ' 10:00:00',
            'amount' => 1200,
            'room_revenue' => 1200,
            'quantity' => $roomNights,
            'book_order_num' => 4,
            'raw_data' => json_encode([
                'cancel_room_nights' => $cancelledRoomNights,
                'cancel_order_num' => 1,
                'gross_order_num' => 4,
                'unknown_status_order_num' => 0,
                'cancel_rate_basis' => 'cancelled_orders_over_gross_orders_complete_classification',
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
