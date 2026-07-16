<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;

final class OtaTrustedRevenueGuardTest extends TestCase
{
    public function testCompetitorRowsNeverEnterHotelRevenueFacts(): void
    {
        $rows = [
            $this->row(),
            $this->row([
                'id' => 2,
                'data_type' => 'competitor',
                'amount' => 888,
                'source_trace_id' => 'trace-competitor-type',
            ]),
            $this->row([
                'id' => 3,
                'compare_type' => 'competitor',
                'amount' => 777,
                'source_trace_id' => 'trace-competitor-scope',
            ]),
            $this->row([
                'id' => 4,
                'dimension' => 'competition_circle_hotel',
                'amount' => 666,
                'source_trace_id' => 'trace-competition-dimension',
            ]),
        ];

        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($rows);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertSame(100.0, $metrics['totals']['revenue']);
        self::assertSame(
            ['non_self_competitor_scope', 'non_self_competitor_scope', 'non_self_competitor_scope'],
            array_column($dataset['data_quality']['rejected_rows'], 'reason')
        );
    }

    public function testOrderFlowRowsStayOutsideRevenueFactsAndKeepDedicatedEvidence(): void
    {
        $row = $this->row([
            'data_type' => 'order_flow',
            'dimension' => 'order_flow:last_7_days:loss:summary',
            'amount' => null,
            'room_revenue' => null,
            'quantity' => null,
            'book_order_num' => null,
            'data_value' => 0.12,
            'raw_data' => json_encode([
                'order_flow_direction' => 'loss',
                'order_flow_row_type' => 'summary',
                'order_flow_period' => 'last_7_days',
                'period_start' => '2026-07-09',
                'period_end' => '2026-07-15',
                'order_count' => 9,
                'room_nights' => 12,
                'amount' => 4567.8,
                'order_ratio' => 0.12,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([$row]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame([], $dataset['fact_ota_daily']);
        self::assertCount(1, $dataset['fact_ota_order_flow']);
        self::assertSame(4567.8, $dataset['fact_ota_order_flow'][0]['flow_amount']);
        self::assertSame(9, $dataset['fact_ota_order_flow'][0]['flow_order_count']);
        self::assertNull($metrics['totals']['revenue']);
    }

    public function testMissingProvenanceCannotBecomeTrustedRevenue(): void
    {
        $row = $this->row();
        unset($row['source_trace_id']);

        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([$row]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $trace = $dataset['fact_ota_daily'][0]['source_trace'];

        self::assertFalse($trace['saved_success']);
        self::assertContains('provenance_missing', $trace['failure_reasons']);
        self::assertFalse($metrics['metric_trust']['totals.revenue']['saved_success']);
        self::assertSame('blocked', $metrics['credibility_gate']['status']);
    }

    public function testUnverifiedManualOverrideCannotInheritPlatformTrust(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row([
                'ingestion_method' => 'manual_override',
                'source_trace_id' => 'original-platform-trace',
                'validation_status' => 'unverified',
            ]),
        ]);
        $trace = $dataset['fact_ota_daily'][0]['source_trace'];

        self::assertFalse($trace['saved_success']);
        self::assertContains('validation_status_unverified', $trace['failure_reasons']);
        self::assertContains('manual_override_unverified', $trace['failure_reasons']);
    }

    public function testVerifiedManualOverrideRemainsExplicitAndCanBeReviewed(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row([
                'ingestion_method' => 'manual_override',
                'source_trace_id' => 'reviewed-override-trace',
                'validation_status' => 'verified',
            ]),
        ]);
        $trace = $dataset['fact_ota_daily'][0]['source_trace'];

        self::assertTrue($trace['saved_success']);
        self::assertSame('manual_override', $trace['ingestion_method']);
        self::assertSame([], $trace['failure_reasons']);
    }

    public function testHotelBindingMismatchFlagBlocksTrustEvenWhenStatusLooksConfirmed(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row([
                'validation_status' => 'confirmed',
                'validation_flags' => json_encode(['hotel_binding_mismatch']),
            ]),
        ]);
        $trace = $dataset['fact_ota_daily'][0]['source_trace'];

        self::assertFalse($trace['saved_success']);
        self::assertContains('validation:hotel_binding_mismatch', $trace['failure_reasons']);
    }

    public function testMissingSystemHotelIdKeepsFactVisibleButUntrusted(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row(['system_hotel_id' => 0]),
        ]);
        $trace = $dataset['fact_ota_daily'][0]['source_trace'];

        self::assertSame('ctrip:hotel-7', $dataset['fact_ota_daily'][0]['hotel_key']);
        self::assertFalse($trace['saved_success']);
        self::assertContains('system_hotel_id_missing', $trace['failure_reasons']);
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => 1,
            'system_hotel_id' => 7,
            'hotel_id' => 'hotel-7',
            'hotel_name' => 'Test Hotel',
            'source' => 'ctrip',
            'data_type' => 'business',
            'compare_type' => 'self',
            'dimension' => 'daily_business',
            'data_date' => '2026-07-15',
            'amount' => 100,
            'room_revenue' => 100,
            'quantity' => 1,
            'book_order_num' => 1,
            'source_trace_id' => 'trace-self-row',
            'update_time' => '2026-07-15 12:00:00',
            'raw_data' => '{}',
        ], $overrides);
    }
}
