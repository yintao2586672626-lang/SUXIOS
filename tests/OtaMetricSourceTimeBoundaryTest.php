<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;

final class OtaMetricSourceTimeBoundaryTest extends TestCase
{
    public function testMixedTimezonesAreOrderedByTheActualInstant(): void
    {
        $earlier = '2026-09-12 07:00:00';
        $later = '2026-09-11T23:30:00Z';
        $trust = $this->summarize([$this->row(1, $later), $this->row(2, $earlier)]);

        self::assertSame($later, $trust['updated_at']);
        self::assertSame(['start' => $earlier, 'end' => $later], $trust['source']['collected_at_range']);
        self::assertSame([1, 2], $trust['source']['row_ids']);
    }

    public function testLegacyShanghaiTimesKeepTheirOriginalRepresentation(): void
    {
        $earlier = '2026-09-12 07:00:00';
        $later = '2026-09-12 09:00:00';
        $trust = $this->summarize([$this->row(1, $later), $this->row(2, $earlier)]);

        self::assertSame($later, $trust['updated_at']);
        self::assertSame(['start' => $earlier, 'end' => $later], $trust['source']['collected_at_range']);
    }

    public function testInvalidSourceTimesCannotBePromotedToFreshnessEvidence(): void
    {
        foreach (['not-a-time', 'tomorrow', '2026-09-12 tomorrow', '2026-02-30 09:00:00'] as $invalid) {
            $trust = $this->summarize([$this->row(1, $invalid)]);
            self::assertNull($trust['updated_at'], $invalid);
            self::assertSame(['start' => null, 'end' => null], $trust['source']['collected_at_range']);
            self::assertFalse($trust['saved_success']);
            self::assertContains('source_update_time_invalid', $trust['failure_reasons']);
            self::assertContains('source_collection_time_invalid', $trust['failure_reasons']);
        }
    }

    public function testOneValidTimestampDoesNotHideAnotherRowsInvalidTimestamp(): void
    {
        $known = '2026-09-12 07:00:00';
        $trust = $this->summarize([$this->row(1, $known), $this->row(2, 'not-a-time')]);
        self::assertSame($known, $trust['updated_at']);
        self::assertFalse($trust['saved_success']);
        self::assertSame([1, 2], $trust['source']['row_ids']);
        self::assertContains('source_update_time_invalid', $trust['failure_reasons']);
    }

    public function testOneKnownTimestampCannotHideAnotherRowsMissingTimestamp(): void
    {
        $known = '2026-09-12 07:00:00';
        foreach (['update_time' => 'source_update_time_missing', 'collected_at' => 'source_collection_time_missing'] as $field => $reason) {
            $missing = $this->row(2, $known);
            $missing[$field] = null;
            $trust = $this->summarize([$this->row(1, $known), $missing]);

            self::assertSame($known, $trust['updated_at']);
            self::assertSame([1, 2], $trust['source']['row_ids']);
            self::assertNotSame('verified', $trust['truth']['status']);
            self::assertContains($reason, $trust['failure_reasons']);
        }
    }

    private function summarize(array $rows): array
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($rows);
        return (new OtaRevenueMetricService())->summarizeDataset($dataset)['metric_trust']['totals.room_revenue'];
    }

    private function row(int $id, string $time): array
    {
        return ['id' => $id, 'system_hotel_id' => 7, 'hotel_id' => 'ctrip-fixture-7',
            'source' => 'ctrip', 'data_type' => 'business', 'data_date' => '2026-09-' . (9 + $id),
            'source_trace_id' => 'fixture-time-' . $id, 'readback_verified' => 1,
            'update_time' => $time, 'collected_at' => $time, 'room_revenue' => 1000, 'quantity' => 5];
    }
}
