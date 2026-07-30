<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use PHPUnit\Framework\TestCase;

final class OtaRoomRevenueSemanticGuardTest extends TestCase
{
    public function testGenericRevenueCannotReplaceMissingDirectRoomRevenue(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset(
            $this->dataset([
                'revenue' => 100.0,
                'gross_revenue' => 100.0,
                'room_revenue' => null,
                'room_revenue_basis' => null,
                'room_nights' => 10.0,
                'available_room_nights' => 20.0,
            ])
        );

        self::assertSame(100.0, $metrics['totals']['revenue']);
        self::assertSame(100.0, $metrics['totals']['gross_revenue']);
        self::assertNull($metrics['totals']['room_revenue']);
        self::assertNull($metrics['totals']['adr']);
        self::assertNull($metrics['totals']['revpar']);
        self::assertContains('room_revenue_missing', array_column($metrics['data_gaps'], 'code'));
        self::assertFalse($metrics['metric_trust']['totals.room_revenue']['saved_success']);
        self::assertFalse($metrics['metric_trust']['totals.adr']['saved_success']);
        self::assertFalse($metrics['metric_trust']['totals.revpar']['saved_success']);
        self::assertNull($metrics['by_platform'][0]['room_revenue']);
        self::assertNull($metrics['by_platform'][0]['adr']);
        self::assertNull($metrics['by_platform'][0]['revpar']);
        self::assertFalse($metrics['credibility_gate']['decision_use']['revenue_analysis']['allowed']);
    }

    public function testVerifiedDirectRoomRevenueStillCalculatesAdrAndRevpar(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset(
            $this->dataset([
                'revenue' => 100.0,
                'gross_revenue' => 100.0,
                'room_revenue' => 80.0,
                'room_revenue_basis' => 'direct_room_revenue_field',
                'room_nights' => 10.0,
                'available_room_nights' => 20.0,
            ])
        );

        self::assertSame(80.0, $metrics['totals']['room_revenue']);
        self::assertSame(8.0, $metrics['totals']['adr']);
        self::assertSame(4.0, $metrics['totals']['revpar']);
        self::assertNotContains('room_revenue_missing', array_column($metrics['data_gaps'], 'code'));
        self::assertTrue($metrics['metric_trust']['totals.room_revenue']['saved_success']);
        self::assertTrue($metrics['metric_trust']['totals.adr']['saved_success']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function dataset(array $overrides): array
    {
        $trace = [
            'table' => 'online_daily_data',
            'row_id' => 501,
            'source_trace_id' => 'trace-room-revenue-501',
            'hotel_key' => '80',
            'system_hotel_id' => 80,
            'platform_hotel_id' => 'ctrip-hotel-80',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'date_key' => '2026-07-29',
            'collected_at' => '2026-07-30 08:30:00',
            'updated_at' => '2026-07-30 08:31:00',
            'stored' => true,
            'readback_verified' => true,
            'saved_success' => true,
            'failure_reasons' => [],
        ];
        $fact = array_replace([
            'date_key' => '2026-07-29',
            'hotel_key' => '80',
            'platform_key' => 'ctrip',
            'data_type' => 'business',
            'dimension' => '',
            'order_count' => 5,
            'source_trace' => $trace,
        ], $overrides);

        return [
            'status' => 'ready',
            'fact_ota_daily' => [$fact],
            'fact_ota_traffic' => [],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_peer_rank' => [],
            'fact_ota_traffic_analysis' => [],
            'fact_ota_traffic_forecast' => [],
            'fact_ota_comment' => [],
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'trusted_rows' => 1,
                'untrusted_rows' => 0,
                'rejected_rows' => 0,
            ],
        ];
    }
}
