<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueFactLayerService;
use PHPUnit\Framework\TestCase;

final class RevenueFactLayerServiceTest extends TestCase
{
    public function testVerifiedThreeSourceFactsMakeRevenueAnalysisReadyWithoutInventingFloorPrice(): void
    {
        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            $this->otaResult(),
            []
        );

        self::assertSame('ready', $layer['revenue_analysis_status']);
        self::assertTrue($layer['all_three_sources_readback_verified']);
        self::assertSame(
            [
                'dingdandao_pms' => 'readback_verified',
                'ctrip_ota' => 'readback_verified',
                'meituan_ota' => 'readback_verified',
            ],
            $layer['source_completeness']
        );
        self::assertSame(
            7930.11,
            $layer['facts']['whole_hotel_accommodation']['room_revenue']
        );
        self::assertSame(
            15,
            $layer['facts']['whole_hotel_accommodation']['sellable_room_nights']
        );
        self::assertSame(
            0.0,
            $layer['facts']['ota_channel']['ctrip']['revenue']
        );
        self::assertSame(
            1032.39,
            $layer['facts']['ota_channel']['meituan']['revenue']
        );
        self::assertSame(
            1032.39,
            $layer['facts']['ota_channel']['combined']['revenue']
        );
        self::assertSame(
            68.83,
            $layer['facts']['cross_source_comparison']
                ['ota_revenue_per_whole_hotel_sellable_room']
        );
        self::assertSame(
            'cross_source_comparison',
            $layer['analysis_metrics']['ota_contribution_revpar']['scope']
        );
        self::assertSame(
            'floor_price_missing',
            $layer['unique_remaining_gap']['code']
        );
        self::assertNull(
            $layer['sources']['pricing_guard']['minimum_floor_price']
        );
        self::assertFalse(
            $layer['aggregation_policy']['pms_plus_ota_revenue_addition_allowed']
        );
        self::assertFalse(
            $layer['aggregation_policy']['ota_data_may_represent_whole_hotel_revenue']
        );
    }

    public function testMissingOtaSourceStaysNullAndKeepsRevenueAnalysisPartial(): void
    {
        $ota = $this->otaResult();
        $ota['rows'] = [$ota['rows'][0]];

        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            $ota,
            []
        );

        self::assertSame('partial', $layer['revenue_analysis_status']);
        self::assertSame(
            'missing',
            $layer['sources']['meituan_ota']['data_status']
        );
        self::assertNull(
            $layer['facts']['ota_channel']['meituan']['revenue']
        );
        self::assertNull(
            $layer['facts']['ota_channel']['combined']['revenue']
        );
        self::assertNull(
            $layer['facts']['cross_source_comparison']
                ['ota_revenue_per_whole_hotel_sellable_room']
        );
        self::assertContains(
            'meituan_ota_not_readback_verified',
            array_column($layer['analysis_gaps'], 'code')
        );
    }

    public function testOperatorFloorPriceClosesTheFactLayerReviewInputGap(): void
    {
        $layer = (new RevenueFactLayerService())->assemble(
            $this->hotel(),
            '2026-07-30',
            $this->pmsCapture(),
            $this->otaResult(),
            [[
                'id' => 3,
                'hotel_id' => 80,
                'name' => '标准间',
                'base_price' => 500,
                'min_price' => 350,
                'max_price' => 800,
                'room_count' => 15,
                'is_enabled' => 1,
            ]]
        );

        self::assertSame('ready', $layer['revenue_analysis_status']);
        self::assertSame('ready_for_manual_review', $layer['ai_review_status']);
        self::assertSame([], $layer['ai_review_gaps']);
        self::assertNull($layer['unique_remaining_gap']);
        self::assertSame(
            350.0,
            $layer['sources']['pricing_guard']['minimum_floor_price']
        );
    }

    /** @return array<string,mixed> */
    private function hotel(): array
    {
        return [
            'id' => 80,
            'tenant_id' => 80,
            'name' => '敦煌漠蓝新',
            'status' => 1,
        ];
    }

    /** @return array<string,mixed> */
    private function pmsCapture(): array
    {
        return [
            'id' => 6,
            'tenant_id' => 80,
            'hotel_id' => 80,
            'provider' => 'dingdandao_pms',
            'provider_hotel_id' => '5206408',
            'provider_hotel_name' => '敦煌漠蓝',
            'business_date' => '2026-07-30',
            'source_scope' => 'today_only',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'identity_status' => 'matched',
            'reconciliation_status' => 'matched',
            'readback_status' => 'readback_verified',
            'captured_at' => '2026-07-30 01:36:11',
            'source_fingerprint' => str_repeat('c', 64),
            'summary' => [
                'total_room_fee' => 7930.11,
                'sold_room_nights' => 15,
                'derived_sellable_room_nights' => 15,
                'occupancy_rate_percent' => 100.0,
                'adr' => 528.67,
                'revpar' => 528.67,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function otaResult(): array
    {
        return [
            'data_status' => 'ready',
            'data_gaps' => [],
            'rows' => [
                [
                    'row_id' => 66156,
                    'system_hotel_id' => 80,
                    'data_date' => '2026-07-30',
                    'amount' => 0.0,
                    'quantity' => 0.0,
                    'book_order_num' => 0.0,
                    'source' => 'ctrip',
                    'readback_verified' => true,
                    'source_trace_id' => 'ctrip-trusted-trace',
                    'data_source_id' => 25,
                    'sync_task_id' => 1001,
                    'ingestion_method' => 'profile_browser',
                ],
                [
                    'row_id' => 66635,
                    'system_hotel_id' => 80,
                    'data_date' => '2026-07-30',
                    'amount' => 1032.39,
                    'quantity' => 1.0,
                    'book_order_num' => 1.0,
                    'source' => 'meituan',
                    'readback_verified' => true,
                    'source_trace_id' => 'meituan-trusted-trace',
                    'data_source_id' => 68,
                    'sync_task_id' => 1002,
                    'ingestion_method' => 'profile_browser',
                ],
            ],
        ];
    }
}
