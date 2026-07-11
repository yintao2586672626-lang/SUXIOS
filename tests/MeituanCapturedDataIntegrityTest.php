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
        self::assertSame(0, $row['quantity']);
        self::assertSame(9, $row['book_order_num']);
        self::assertSame(6.0, $row['data_value']);
        self::assertSame(4.5, $row['flow_rate']);
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
        self::assertSame(0, $byType['review']['quantity']);
        self::assertSame(0, $byType['review']['data_value']);
        $reviewRaw = json_decode((string)$byType['review']['raw_data'], true);
        self::assertIsArray($reviewRaw);
        self::assertArrayNotHasKey('comment_count', $reviewRaw);
        self::assertArrayNotHasKey('bad_review_count', $reviewRaw);

        self::assertSame(500.0, $byType['order']['amount']);
        self::assertSame(0, $byType['order']['quantity']);
        self::assertSame(1, $byType['order']['book_order_num']);
        self::assertSame(0.0, $byType['order']['data_value']);
    }
}
