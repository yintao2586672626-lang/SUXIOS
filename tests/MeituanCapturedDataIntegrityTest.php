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

    public function testCapturedOrderHashAliasesRemainStableForDeduplication(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $hash = str_repeat('a', 64);

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-80',
            'poiId' => 'poi-80',
            'defaultDataDate' => '2026-07-11',
            'orders' => [
                ['order_id_hash' => $hash, 'orderStatus' => 'paid', 'dataDate' => '2026-07-11'],
                ['order_no_hash' => $hash, 'orderStatus' => 'paid', 'dataDate' => '2026-07-11'],
                ['booking_id_hash' => $hash, 'orderStatus' => 'paid', 'dataDate' => '2026-07-11'],
            ],
        ], 80]);

        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame('order:paid:' . $hash, $row['dimension']);
            self::assertNull($row['amount']);
            self::assertNull($row['quantity']);
            self::assertNull($row['book_order_num']);
        }

        $uniqueRows = $this->invokeNonPublic($controller, 'uniqueMeituanCapturedRowsForPersistence', [$rows]);
        self::assertCount(1, $uniqueRows);
        $raw = json_decode((string)$uniqueRows[0]['raw_data'], true);
        self::assertIsArray($raw);
        self::assertSame($hash, $raw['order_id_hash']);
        self::assertArrayNotHasKey('order_id', $raw);
        self::assertArrayNotHasKey('orderNo', $raw);
    }

    public function testAggregateOrderCountsWithoutAmountArePersistableAndDoNotFabricateRevenue(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $payload = [
            'storeId' => 'store-80',
            'poiId' => 'poi-80',
            'defaultDataDate' => '2026-07-12',
            'orders' => [
                'orderCount' => 3,
                'roomNights' => 4,
                'dataDate' => '2026-07-11',
                'date_source' => 'request.query.dataDate',
                '_source_path' => '$.data.summary',
                'order_id_hash' => 'not-a-valid-hash',
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [$payload, 80]);

        self::assertCount(1, $rows);
        self::assertSame('2026-07-11', $rows[0]['data_date']);
        self::assertSame(3, $rows[0]['book_order_num']);
        self::assertSame(4, $rows[0]['quantity']);
        self::assertNull($rows[0]['amount']);
        self::assertNull($rows[0]['data_value']);
        self::assertStringStartsWith('order:aggregate:', $rows[0]['dimension']);

        $changedPayload = $payload;
        $changedPayload['orders']['orderCount'] = 5;
        $changedPayload['orders']['roomNights'] = 7;
        $changedRows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [$changedPayload, 80]);
        self::assertSame($rows[0]['dimension'], $changedRows[0]['dimension']);

        $raw = json_decode((string)$rows[0]['raw_data'], true);
        self::assertIsArray($raw);
        self::assertSame(3, $raw['orderCount']);
        self::assertSame(4, $raw['roomNights']);
        self::assertArrayNotHasKey('amount', $raw);
        self::assertArrayNotHasKey('order_id_hash', $raw);
    }

    public function testOrderFlowRowsKeepDirectionPeriodAndZeroValuesTruthful(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-80',
            'poiId' => 'poi-80',
            'poiName' => '目标酒店',
            'defaultDataDate' => '2026-07-13',
            'dataPeriod' => 'last_7_days',
            'order_flow' => [
                [
                    'storeId' => 'store-80',
                    'order_flow_row_type' => 'summary',
                    'order_flow_direction' => 'loss',
                    'order_flow_period' => 'last_7_days',
                    'period_start' => '2026-07-07',
                    'period_end' => '2026-07-13',
                    'order_count' => 0,
                    'room_nights' => 0,
                    'amount' => 0,
                    'dimension' => 'order_flow:last_7_days:loss:summary',
                ],
                [
                    'poiId' => 'peer-1',
                    'poiName' => '同行酒店',
                    'order_flow_row_type' => 'hotel_detail',
                    'order_flow_direction' => 'loss',
                    'order_flow_period' => 'last_7_days',
                    'period_start' => '2026-07-07',
                    'period_end' => '2026-07-13',
                    'order_count' => 7,
                    'order_ratio' => 0.0686,
                    'amount' => 5234,
                    'lossRoomList' => [['lossRoomName' => '大床房', 'lossRoomCnt' => 4]],
                    'dimension' => 'order_flow:last_7_days:loss:hotel:peer-1',
                ],
            ],
        ], 80]);

        self::assertCount(2, $rows);
        self::assertSame('order_flow', $rows[0]['data_type']);
        self::assertSame('2026-07-13', $rows[0]['data_date']);
        self::assertNull($rows[0]['book_order_num']);
        self::assertNull($rows[0]['quantity']);
        self::assertNull($rows[0]['amount']);
        self::assertSame('self', $rows[0]['compare_type']);
        self::assertSame('competitor', $rows[1]['compare_type']);
        self::assertSame('peer-1', $rows[1]['hotel_id']);
        self::assertNull($rows[1]['book_order_num']);
        self::assertNull($rows[1]['quantity']);
        self::assertNull($rows[1]['amount']);
        self::assertSame(0.0686, $rows[1]['data_value']);
        $raw = json_decode((string)$rows[1]['raw_data'], true);
        self::assertSame('last_7_days', $raw['order_flow_period']);
        self::assertSame('loss', $raw['order_flow_direction']);
        self::assertSame(7, $raw['order_count']);
        self::assertSame(5234, $raw['amount']);
        self::assertSame('大床房', $raw['lossRoomList'][0]['lossRoomName']);
    }
}
