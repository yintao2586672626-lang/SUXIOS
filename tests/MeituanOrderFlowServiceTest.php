<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanOrderFlowService;
use PHPUnit\Framework\TestCase;

final class MeituanOrderFlowServiceTest extends TestCase
{
    public function testBuildRequestParamsUsesVerifiedDirectionMapping(): void
    {
        $loss = MeituanOrderFlowService::buildRequestParams(
            '5006692',
            '1010341153889311',
            'loss',
            '2026-06-14',
            '2026-07-13'
        );
        $inflow = MeituanOrderFlowService::buildRequestParams(
            '5006692',
            '1010341153889311',
            'inflow',
            '2026-06-14',
            '2026-07-13'
        );

        self::assertSame(0, $loss['lossType']);
        self::assertSame(1, $inflow['lossType']);
        self::assertSame('20260614', $loss['startDate']);
        self::assertSame('20260713', $loss['endDate']);
        self::assertSame(30, $loss['dateRange']);
        self::assertSame('4.2.4', $loss['csecversion']);
    }

    public function testNormalizeResponseKeepsOfficialYuanTotalsAndExpandsDetails(): void
    {
        $rows = MeituanOrderFlowService::normalizeResponse([
            'status' => 0,
            'data' => [
                'lossTotalCnt' => 664,
                'lossTotalPayRoomNight' => '931',
                'lossTotalPayAmount' => '224,799.73元',
                'poiStar' => '舒适型',
                'orderLossPeerDetails' => [[
                    'poiId' => '1111932272',
                    'poiName' => '金际高空酒店（贵阳未来方舟店）',
                    'lossOrderCount' => 107,
                    'lossOrderRatio' => 0.1611,
                    'lossSinglePayAmount' => '3.71万',
                    'lossRoomList' => [[
                        'lossRoomName' => '景观舒享大床房',
                        'lossRoomCnt' => 37,
                    ]],
                ]],
            ],
        ], 'loss', '2026-06-14', '2026-07-13');

        self::assertCount(2, $rows);
        self::assertSame('last_30_days', $rows[0]['order_flow_period']);
        self::assertSame('loss', $rows[0]['order_flow_direction']);
        self::assertSame(664.0, $rows[0]['order_count']);
        self::assertSame(931.0, $rows[0]['room_nights']);
        self::assertSame(224799.73, $rows[0]['amount']);
        self::assertSame('hotel_detail', $rows[1]['order_flow_row_type']);
        self::assertSame(37100.0, $rows[1]['amount']);
        self::assertSame(37.0, $rows[1]['lossRoomList'][0]['lossRoomCnt']);
    }

    public function testNormalizeInflowUsesSamePlatformFieldsWithoutChangingUnit(): void
    {
        $rows = MeituanOrderFlowService::normalizeResponse([
            'data' => [
                'lossTotalCnt' => 863,
                'lossTotalPayRoomNight' => 1125,
                'lossTotalPayAmount' => 264343,
                'orderLossPeerDetails' => [],
            ],
        ], 'inflow', '2026-06-14', '2026-07-13');

        self::assertSame('inflow', $rows[0]['order_flow_direction']);
        self::assertSame(264343.0, $rows[0]['amount']);
    }
}
