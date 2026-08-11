<?php
declare(strict_types=1);

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

final class RevenueAiManualOrderImportTest extends TestCase
{
    public function testManualOrderReadbackIsDisplayedWithoutBecomingConfirmedRevenue(): void
    {
        $manualImports = [
            'status' => 'available_unverified',
            'quality_status' => 'user_provided_unverified',
            'business_date' => '2026-08-08',
            'hotel_id' => 80,
            'real_file_acceptance' => 'unverified',
            'note' => '测试 fixture；参考底价不是确认收入。',
            'rows' => [[
                'row_id' => 9001,
                'source' => 'ctrip_manual_order_import',
                'source_label' => '携程订单文件人工导入',
                'channel_key' => 'ctrip',
                'channel_label' => '携程主站',
                'business_date' => '2026-08-08',
                'business_date_basis' => 'stay_date',
                'active_orders' => 2.0,
                'gross_orders' => 3.0,
                'cancelled_orders' => 1.0,
                'cancel_rate' => 1 / 3,
                'room_nights' => 4.0,
                'average_booking_lead_days' => 5.0,
                'reference_bottom_price_total' => 900.0,
                'reference_bottom_price_adr' => 225.0,
                'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
                'quality_status' => 'user_provided_unverified',
                'readback_verified' => true,
                'real_file_acceptance' => 'test_fixture_only',
            ]],
            'summary' => [
                'row_count' => 1,
                'active_orders' => 2.0,
                'cancelled_orders' => 1.0,
                'room_nights' => 4.0,
                'readback_verified' => true,
            ],
        ];

        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            [
                'status' => 'empty',
                'dim_hotel' => [],
                'dim_platform' => [],
                'fact_ota_daily' => [],
                'fact_ota_traffic' => [],
                'fact_ota_advertising' => [],
                'fact_ota_quality' => [],
                'data_quality' => ['input_rows' => 0, 'accepted_rows' => 0, 'rejected_rows' => []],
            ],
            [],
            [],
            [
                'business_date' => '2026-08-08',
                'hotel_id' => 80,
                'manual_order_imports' => $manualImports,
            ]
        );

        self::assertSame($manualImports, $overview['manual_order_imports']);
        self::assertSame(900.0, $overview['manual_order_imports']['rows'][0]['reference_bottom_price_total']);
        self::assertSame('reference_bottom_price_not_confirmed_revenue', $overview['manual_order_imports']['rows'][0]['amount_semantics']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertNotSame('ok', $overview['metrics']['ota_room_revenue']['status']);
    }
}
