<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class MeituanCapturedDataIntegrityTest extends TestCase
{
    use ReflectionHelper;

    public function testOwnHotelCapturedRowsRejectPoiThatDiffersFromCaptureBinding(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('美团门店标识与当前酒店绑定不一致');

        $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => '1029642156589279',
            'poiId' => '1029642156589279',
            'poiName' => 'Dunhuang Meituan Hotel',
            'defaultDataDate' => '2026-07-11',
            'traffic' => [[
                'poiId' => 'WRONG-POI',
                'date' => '2026-07-11',
                'exposure_count' => 100,
            ]],
        ], 80]);
    }

    public function testCapturedPayloadRejectsConflictingOuterAndInnerSystemHotel(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('美团采集数据所属酒店与请求酒店不一致');

        $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'system_hotel_id' => 81,
            'storeId' => '1029642156589279',
            'poiId' => '1029642156589279',
            'defaultDataDate' => '2026-07-11',
            'traffic' => [[
                'date' => '2026-07-11',
                'exposure_count' => 100,
            ]],
        ], 80]);
    }

    public function testAdvertisingCaptureKeepsRoasCvrAndRoomNightsSemanticsSeparate(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-80',
            'poiId' => '1029642156589279',
            'poiName' => 'Dunhuang Meituan Hotel',
            'defaultDataDate' => '2026-07-11',
            'ads' => [[
                'date' => '2026-07-11',
                'exposure_count' => 5000,
                'click_count' => 200,
                'cost' => 300,
                'orderAmount' => 1800,
                'orderNum' => 9,
            ]],
        ], 80]);

        self::assertCount(1, $rows);
        $row = $rows[0];

        self::assertSame('advertising', $row['data_type']);
        self::assertSame(5000, $row['list_exposure']);
        self::assertSame(200, $row['detail_exposure']);
        self::assertSame(300.0, $row['amount']);
        self::assertNull($row['quantity']);
        self::assertSame(9, $row['book_order_num']);
        self::assertSame(6.0, $row['data_value']);
        self::assertSame(4.5, $row['flow_rate']);
    }

    public function testCapturedRankSeparatesDateRangeAndDoesNotUseRankAsMetricValue(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-80',
            'poiId' => '1029642156589279',
            'defaultDataDate' => '2026-07-11',
            'peerRank' => [
                [
                    'poiId' => 'peer-1',
                    'poiName' => 'Peer Hotel',
                    'rankType' => 'P_RZ',
                    'dateRange' => '1',
                    'dimName' => '入住间夜',
                    'rank' => 3,
                    'percent' => 12.5,
                ],
                [
                    'poiId' => 'peer-1',
                    'poiName' => 'Peer Hotel',
                    'rankType' => 'P_RZ',
                    'dateRange' => '7',
                    'dimName' => '入住间夜',
                    'rank' => 5,
                    'percent' => 18.2,
                ],
            ],
        ], 80]);

        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]['dimension'], $rows[1]['dimension']);
        self::assertStringContainsString('range=1', $rows[0]['dimension']);
        self::assertStringContainsString('range=7', $rows[1]['dimension']);
        self::assertNull($rows[0]['data_value']);
        self::assertNull($rows[1]['data_value']);
    }

    public function testCapturedRankDerivesSelfFromBoundPoiWithoutIsSelfFlag(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-80',
            'poiId' => 'poi-80',
            'defaultDataDate' => '2026-07-11',
            'peerRank' => [[
                'poiId' => 'poi-80',
                'rankType' => 'P_RZ',
                'rank' => 1,
            ]],
        ], 80]);

        self::assertCount(1, $rows);
        self::assertSame('self', $rows[0]['compare_type']);
    }

    public function testCapturedRankRejectsExplicitSelfFlagThatConflictsWithBoundPoi(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('meituan_peer_rank_identity_conflict');

        $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-80',
            'poiId' => 'poi-80',
            'defaultDataDate' => '2026-07-11',
            'peerRank' => [[
                'poiId' => 'peer-1',
                'isSelf' => true,
                'rankType' => 'P_RZ',
                'rank' => 1,
            ]],
        ], 80]);
    }

    public function testAdvertisingWithoutRoasDoesNotUseClicksOrZeroAsRoas(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-80',
            'poiId' => 'poi-80',
            'defaultDataDate' => '2026-07-11',
            'ads' => [[
                'date' => '2026-07-11',
                'click_count' => 12,
                'ctr' => 3.5,
            ]],
        ], 80]);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['amount']);
        self::assertNull($rows[0]['data_value']);
        self::assertSame(12, $rows[0]['detail_exposure']);
    }

    public function testScoreOnlyReviewAndStaylessOrderDoNotFabricateCounts(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-80',
            'poiId' => '1029642156589279',
            'poiName' => 'Dunhuang Meituan Hotel',
            'defaultDataDate' => '2026-07-11',
            'reviews' => [[
                'score' => 3.8,
            ]],
            'orders' => [[
                'order_id' => 'ORDER-WITHOUT-STAY-DETAILS',
                'total_amount' => 500,
                'createTime' => '2026-07-11 09:30:00',
            ]],
        ], 80]);

        self::assertCount(2, $rows);
        $byType = array_column($rows, null, 'data_type');

        self::assertSame(3.8, $byType['review']['comment_score']);
        self::assertNull($byType['review']['quantity']);
        self::assertNull($byType['review']['data_value']);
        $reviewRaw = json_decode((string)$byType['review']['raw_data'], true);
        self::assertIsArray($reviewRaw);
        self::assertArrayNotHasKey('comment_count', $reviewRaw);
        self::assertArrayNotHasKey('bad_review_count', $reviewRaw);

        self::assertSame(500.0, $byType['order']['amount']);
        self::assertNull($byType['order']['quantity']);
        self::assertNull($byType['order']['book_order_num']);
        self::assertNull($byType['order']['data_value']);
    }
}
