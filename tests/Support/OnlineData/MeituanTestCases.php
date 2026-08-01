<?php
declare(strict_types=1);

namespace Tests\Support\OnlineData;

use app\controller\OnlineData;
use app\command\PlatformProfileLogin;
use app\service\BrowserProfileCaptureRequestService;
use app\service\CtripTrafficDisplayService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\OnlineDataQuerySpy;
use Tests\Support\ReflectionHelper;
use think\App;

trait MeituanTestCases
{

    public function testMeituanDateRangeNormalizesPlatformDateFormats(): void
    {
        $controller = $this->controller();

        self::assertSame(['2026-05-02', '2026-05-03'], $this->invokeNonPublic($controller, 'normalizeMeituanManualDateRange', [
            '2026/5/2',
            '20260503',
        ]));
        self::assertSame(['2026-05-03', '2026-05-03'], $this->invokeNonPublic($controller, 'normalizeMeituanManualDateRange', [
            '',
            '2026-05-03',
        ]));
    }

    public function testMeituanDateRangeRejectsReverseRange(): void
    {
        $controller = $this->controller();

        $this->expectException(InvalidArgumentException::class);
        $this->invokeNonPublic($controller, 'normalizeMeituanManualDateRange', [
            '2026-05-04',
            '2026-05-03',
        ]);
    }

    public function testMeituanCapturedRowsCleanTrafficAndOrdersWithoutExternalCalls(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-1',
            'poiId' => 'poi-1',
            'poiName' => 'Meituan Hotel',
            'defaultDataDate' => '2026/5/2',
            'traffic' => [
                'data' => [
                    'rows' => [[
                        'statDate' => '20260503',
                        'exposure_count' => '100',
                        'page_views' => '40',
                        'click_count' => '5',
                        'conversion_rate' => '40%',
                    ]],
                ],
            ],
            'reviews' => [
                [
                    'commentId' => 'COMMENT-1',
                    'content' => 'This comment section must be ignored.',
                    'score' => 1,
                    'commentTime' => '2026-05-03',
                ],
            ],
            'orders' => [
                'data' => [
                    'list' => [[
                        'orderId' => 'ORDER-1',
                        'totalAmount' => '500',
                        'roomCount' => 2,
                        'checkInDate' => '2026-05-01',
                        'checkOutDate' => '2026-05-03',
                        'createTime' => '2026/5/1',
                        'guestName' => 'Alice Guest',
                        'phone' => '90000008000',
                        'mobile' => '90000009000',
                        'idCardNo' => 'sample-id-card-token',
                        'customerRemark' => 'late arrival with child',
                    ]],
                ],
            ],
        ], 7]);

        self::assertCount(3, $rows);
        self::assertContains('review', array_column($rows, 'data_type'));
        self::assertSame('meituan', $rows[0]['source']);
        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame('2026-05-03', $rows[0]['data_date']);
        self::assertSame(100, $rows[0]['list_exposure']);
        self::assertSame(40, $rows[0]['detail_exposure']);
        self::assertSame(40.0, $rows[0]['flow_rate']);

        self::assertSame('review', $rows[1]['data_type']);
        self::assertSame('2026-05-03', $rows[1]['data_date']);
        self::assertSame(1.0, $rows[1]['comment_score']);
        self::assertNull($rows[1]['quantity']);
        self::assertNull($rows[1]['data_value']);
        $reviewRaw = (string)$rows[1]['raw_data'];
        self::assertStringNotContainsString('COMMENT-1', $reviewRaw);
        self::assertStringNotContainsString('This comment section must be ignored.', $reviewRaw);
        self::assertStringContainsString('"comment_score":1', $reviewRaw);
        self::assertStringNotContainsString('"comment_count"', $reviewRaw);
        self::assertStringNotContainsString('"bad_review_count"', $reviewRaw);
        $decodedReviewRaw = json_decode($reviewRaw, true);
        self::assertIsArray($decodedReviewRaw);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decodedReviewRaw['review_id_hash'] ?? ''));

        self::assertSame('order', $rows[2]['data_type']);
        self::assertSame('2026-05-01', $rows[2]['data_date']);
        self::assertSame(500.0, $rows[2]['amount']);
        self::assertSame(4, $rows[2]['quantity']);
        self::assertSame(7, $rows[2]['system_hotel_id']);
        self::assertStringNotContainsString('ORDER-1', (string)$rows[2]['dimension']);

        $orderRaw = (string)$rows[2]['raw_data'];
        self::assertStringNotContainsString('ORDER-1', $orderRaw);
        self::assertStringNotContainsString('Alice Guest', $orderRaw);
        self::assertStringNotContainsString('90000008000', $orderRaw);
        self::assertStringNotContainsString('90000009000', $orderRaw);
        self::assertStringNotContainsString('sample-id-card-token', $orderRaw);
        self::assertStringNotContainsString('late arrival with child', $orderRaw);

        $decodedOrderRaw = json_decode($orderRaw, true);
        self::assertIsArray($decodedOrderRaw);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decodedOrderRaw['order_id_hash'] ?? ''));
        self::assertSame('A***', $decodedOrderRaw['guest_name_masked'] ?? null);
        self::assertSame('*******8000', $decodedOrderRaw['phone_masked'] ?? null);
        self::assertSame('*******9000', $decodedOrderRaw['mobile_masked'] ?? null);
        self::assertArrayNotHasKey('idCardNo', $decodedOrderRaw);
        self::assertArrayNotHasKey('customerRemark', $decodedOrderRaw);

        self::assertSame(
            [],
            BrowserProfileCaptureRequestService::unverifiedMeituanTargetDateRows([
                [...$rows[1], 'raw_data' => json_encode([...$decodedReviewRaw, 'commentTime' => '2026-05-03', 'date_source' => 'row.commentTime'])],
                [...$rows[2], 'raw_data' => json_encode([...$decodedOrderRaw, 'createTime' => '2026/5/1', 'date_source' => 'row.createTime'])],
            ], '2026-05-03')
        );
    }

    public function testMeituanAggregateReviewKeepsMissingScoreNull(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-1',
            'poiId' => 'poi-1',
            'poiName' => 'Meituan Hotel',
            'defaultDataDate' => '2026-05-03',
            'reviews' => [[
                'commentCount' => 12,
                'badReviewCount' => 2,
            ]],
        ], 7]);

        self::assertCount(1, $rows);
        self::assertSame('review', $rows[0]['data_type']);
        self::assertNull($rows[0]['comment_score']);
        self::assertNull($rows[0]['qunar_comment_score']);
        self::assertSame(12, $rows[0]['quantity']);
        self::assertSame(2, $rows[0]['data_value']);
        $raw = json_decode((string)$rows[0]['raw_data'], true);
        self::assertIsArray($raw);
        self::assertSame('missing', $raw['comment_score_status'] ?? null);
        self::assertFalse((bool)($raw['comment_score_present'] ?? true));
    }

    public function testMeituanDomCsvOrderRowsKeepBottomPriceOutOfRevenueAmount(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-1',
            'poiId' => 'poi-1',
            'poiName' => 'Meituan Hotel',
            'data_period' => 'manual_dom_csv',
            'orders' => [[
                'orderNo' => '123456789012345',
                'roomType' => '阳光双床房',
                'checkIn' => '2026-05-29',
                'checkOut' => '2026-05-30',
                'buyTime' => '2026-05-28 20:30',
                'bottomPrice' => '188.50',
                '_ingestion_method' => 'manual_dom_csv',
            ]],
        ], 7]);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('order', $row['data_type']);
        self::assertSame('2026-05-28', $row['data_date']);
        self::assertNull($row['amount']);
        self::assertNull($row['quantity']);
        self::assertNull($row['book_order_num']);
        self::assertSame(188.5, $row['data_value']);
        self::assertSame('manual_dom_csv', $row['data_period']);
        self::assertStringNotContainsString('123456789012345', (string)$row['dimension']);

        $decodedOrderRaw = json_decode((string)$row['raw_data'], true);
        self::assertIsArray($decodedOrderRaw);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decodedOrderRaw['order_id_hash'] ?? ''));
        self::assertSame('阳光双床房', $decodedOrderRaw['roomType'] ?? null);
        self::assertSame('188.50', $decodedOrderRaw['bottomPrice'] ?? null);
        self::assertStringNotContainsString('123456789012345', (string)$row['raw_data']);
    }

    public function testMeituanBrowserSupplementRowsMapIntoOnlineDailyData(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-1',
            'poiId' => 'poi-1',
            'poiName' => 'Meituan Hotel',
            'defaultDataDate' => '2026-06-26',
            'peerRank' => [[
                'poiId' => 'peer-1',
                'poiName' => 'Peer Hotel',
                'dataDate' => '2026-06-26',
                'rankType' => 'P_RZ',
                'dimension' => '入住间夜',
                'rank' => 2,
                'percent' => '35.5',
            ]],
            'flowAnalysis' => [[
                'dataDate' => '2026-06-26',
                'analysis_type' => 'conversion_funnel',
                'dimension' => 'flow_conversion',
                'listExposure' => 1000,
                'detailExposure' => 200,
                'orderSubmitNum' => 20,
                'flowRate' => 10,
            ]],
            'searchKeywords' => [[
                'dataDate' => '2026-06-26',
                'keyword' => '机场酒店',
                'data_value' => 320,
                'impressions' => 500,
                'clicks' => 40,
            ]],
            'trafficForecast' => [[
                'dataDate' => '2026-07-01',
                'forecast_type' => 'pv',
                'current' => 88,
                'peerAvg' => 120,
            ]],
        ], 7]);

        self::assertCount(4, $rows);
        self::assertSame(['peer_rank', 'traffic_analysis', 'search_keyword', 'traffic_forecast'], array_column($rows, 'data_type'));

        self::assertSame('peer-1', $rows[0]['hotel_id']);
        self::assertSame('Peer Hotel', $rows[0]['hotel_name']);
        self::assertNull($rows[0]['data_value']);
        self::assertSame('peer_rank:P_RZ:range=unknown:入住间夜', $rows[0]['dimension']);
        self::assertStringContainsString('"rank":2', (string)$rows[0]['raw_data']);
        self::assertSame('competitor', $rows[0]['compare_type']);

        self::assertSame(1000, $rows[1]['list_exposure']);
        self::assertSame(200, $rows[1]['detail_exposure']);
        self::assertSame(20, $rows[1]['order_submit_num']);
        self::assertSame(10.0, $rows[1]['flow_rate']);

        self::assertSame('机场酒店', $rows[2]['dimension']);
        self::assertSame(320.0, $rows[2]['data_value']);
        self::assertSame(500, $rows[2]['list_exposure']);
        self::assertSame(40, $rows[2]['detail_exposure']);

        self::assertSame('2026-07-01', $rows[3]['data_date']);
        self::assertSame(88.0, $rows[3]['data_value']);
        self::assertSame('forecast', $rows[3]['compare_type']);
        self::assertSame(7, $rows[3]['system_hotel_id']);
        self::assertStringContainsString('"peerAvg":120', (string)$rows[3]['raw_data']);
    }

    public function testMeituanMyHotelFunnelRowMapsToCoreTrafficWithoutInventingOrderVisitors(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-1',
            'poiId' => 'poi-1',
            'poiName' => 'Meituan Hotel',
            'defaultDataDate' => '2026-07-18',
            'flowAnalysis' => [[
                'data_type' => 'traffic',
                'dataDate' => '2026-07-18',
                'analysis_type' => 'conversion_funnel',
                'dimension' => 'flow_conversion',
                'exposureUV' => 81,
                'intentionUV' => 14,
                'payOrderCnt' => 2,
                'intentionPerExposure' => '17.28%',
                'payOrderPerIntention' => '14.29%',
            ]],
        ], 80]);

        self::assertCount(1, $rows);
        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame(81, $rows[0]['list_exposure']);
        self::assertSame(14, $rows[0]['detail_exposure']);
        self::assertSame(2, $rows[0]['order_submit_num']);
        self::assertSame(14.29, $rows[0]['flow_rate']);
        self::assertNull($rows[0]['order_filling_num']);
    }

    public function testBackendBuildsMeituanBusinessDisplayRowsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 9, 'rank' => 2]],
                    ],
                    [
                        'dimName' => '房费收入榜',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 600, 'rank' => 3]],
                    ],
                    [
                        'dimName' => '曝光榜',
                        'aiMetricName' => 'EXPOSURE',
                        'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 1200]],
                    ],
                ],
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame('8', (string)$rows[0]['poiId']);
        self::assertSame('M', $rows[0]['hotelName']);
        self::assertSame(9.0, $rows[0]['roomNights']);
        self::assertSame(600.0, $rows[0]['roomRevenue']);
        self::assertSame(1200.0, $rows[0]['exposure']);
        self::assertSame(2, $rows[0]['rank']);
    }

    public function testBackendCarriesMeituanPlatformTagsFromReturnedFieldsOnly(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [[
                            'poiId' => 8,
                            'poiName' => 'M',
                            'dataValue' => 9,
                            'rank' => 2,
                            'tags' => [
                                ['name' => 'VIP'],
                                ['tagName' => '优选'],
                            ],
                            'tagList' => [
                                ['name' => '1'],
                                ['name' => 'true'],
                            ],
                            'crownLevel' => 2,
                        ]],
                    ],
                ],
            ],
        ], [
            'date_range' => '1',
            'rank_type' => 'P_RZ',
            'target_poi_id' => '8',
        ]]);

        self::assertSame(['VIP', '优选', '冠级2'], $rows[0]['platformTags']);
        self::assertTrue($rows[0]['hasVipTag']);
        self::assertSame('VIP / 优选 / 冠级2', $rows[0]['platformTagText']);
        self::assertSame('美团榜单返回', $rows[0]['platformTagSourceText']);
        self::assertTrue($rows[0]['isSelf']);
        self::assertSame('昨日第2', $rows[0]['rankSummaryText']);
    }

    /**
     * 覆盖 buildCtripTrafficDateRange：
     * 验证预设日期、自定义日期、非法日期范围异常。
     */
    public function testBackendBuildsMeituanBusinessDisplayDerivedMetricsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    ['dimName' => 'room nights', 'aiMetricName' => 'P_RZ_NIGHT_COUNT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 10]]],
                    ['dimName' => 'room revenue', 'aiMetricName' => 'P_RZ_ROOM_PAY', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 1000]]],
                    ['dimName' => 'sales nights', 'aiMetricName' => 'P_XS_NIGHT_COUNT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 8]]],
                    ['dimName' => 'sales amount', 'aiMetricName' => 'P_XS_AMT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 960]]],
                    ['dimName' => 'exposure', 'aiMetricName' => 'EXPOSURE', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 2000]]],
                    ['dimName' => 'view', 'aiMetricName' => 'VIEW', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 500]]],
                    ['dimName' => 'view conversion', 'aiMetricName' => 'VIEW_CONVERT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 0.5]]],
                    ['dimName' => 'pay conversion', 'aiMetricName' => 'PAY_CONVERT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 0.1]]],
                ],
            ],
        ]]);

        self::assertSame(100.0, $rows[0]['avgRoomPrice']);
        self::assertSame('100', $rows[0]['avgRoomPriceText']);
        self::assertSame(120.0, $rows[0]['avgSalesPrice']);
        self::assertSame('120', $rows[0]['avgSalesPriceText']);
        self::assertSame(50, $rows[0]['orderCount']);
        self::assertSame('50', $rows[0]['orderCountText']);
        self::assertSame('1,000', $rows[0]['roomRevenueText']);
        self::assertSame('', $rows[0]['roomRevenuePrefix']);
        self::assertSame('960', $rows[0]['salesText']);
        self::assertSame('', $rows[0]['salesPrefix']);
        self::assertSame(0.05, $rows[0]['absoluteConversion']);
        self::assertSame('5.00%', $rows[0]['absoluteConversionText']);
        self::assertSame('50.00%', $rows[0]['viewConversionText']);
        self::assertSame('10.00%', $rows[0]['payConversionText']);
        self::assertSame('derived_from_display_metrics', $rows[0]['displayMetricStatus']['avgRoomPrice']);
        self::assertSame('derived_from_display_metrics', $rows[0]['displayMetricStatus']['avgSalesPrice']);
        self::assertSame('room_revenue_div_room_nights_display_metric', $rows[0]['metricDerived']['avgRoomPrice']['method']);
        self::assertSame('sales_div_sales_room_nights_display_metric', $rows[0]['metricDerived']['avgSalesPrice']['method']);
        self::assertSame('views_times_pay_conversion', $rows[0]['metricDerived']['orderCount']['method']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['absoluteConversion']);
    }

    public function testBackendMarksMeituanAverageRoomPriceAsDerivedFromRankValues(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    ['dimName' => 'room nights', 'aiMetricName' => 'P_RZ_NIGHT_COUNT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 492, 'rank' => 1]]],
                    ['dimName' => 'room revenue', 'aiMetricName' => 'P_RZ_ROOM_PAY', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 6054.34, 'rank' => 1]]],
                ],
            ],
        ]]);

        self::assertSame(492.0, $rows[0]['roomNights']);
        self::assertSame(6054.34, $rows[0]['roomRevenue']);
        self::assertSame('492', $rows[0]['roomNightsText']);
        self::assertSame('6,054', $rows[0]['roomRevenueText']);
        self::assertSame('', $rows[0]['roomRevenuePrefix']);
        self::assertSame(12.0, $rows[0]['avgRoomPrice']);
        self::assertSame('12', $rows[0]['avgRoomPriceText']);
        self::assertSame('', $rows[0]['avgRoomPricePrefix']);
        self::assertSame('derived_from_display_metrics', $rows[0]['displayMetricStatus']['avgRoomPrice']);
        self::assertSame('room_revenue_div_room_nights_display_metric', $rows[0]['metricDerived']['avgRoomPrice']['method']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        self::assertSame(12.0, $summary['metrics']['avgRoomPrice']);
        self::assertSame('-', $summary['metrics']['marketPriceSignal']);
    }

    public function testBackendParsesMeituanTradeManageCardsAsSelfMetricValues(): void
    {
        $controller = $this->controller();

        $values = $this->invokeNonPublic($controller, 'normalizeMeituanSelfMetricValues', [[
            'data' => [
                'cards' => [
                    ['id' => 1, 'title' => '销售间夜', 'value' => '101'],
                    ['id' => 2, 'title' => '销售额', 'value' => '1.77', 'suffix' => '万元'],
                    ['id' => 4, 'title' => '入住间夜', 'value' => '88'],
                    ['id' => 5, 'title' => '入住金额', 'value' => '1.54', 'suffix' => '万元'],
                    ['id' => 6, 'title' => '平均房价', 'value' => '175.03', 'suffix' => '元'],
                ],
            ],
        ]]);

        self::assertSame(101.0, $values['salesRoomNights']);
        self::assertSame(17700.0, $values['sales']);
        self::assertSame(88.0, $values['roomNights']);
        self::assertSame(15400.0, $values['roomRevenue']);
        self::assertArrayNotHasKey('avgRoomPrice', $values);
    }

    public function testBackendParsesMeituanTradeManageOrderCountCardAsSelfMetricValue(): void
    {
        $controller = $this->controller();

        $values = $this->invokeNonPublic($controller, 'normalizeMeituanSelfMetricValues', [[
            'data' => [
                'cards' => [
                    ['id' => 3, 'title' => 'pay order count', 'value' => '9'],
                ],
            ],
        ]]);

        self::assertSame(9.0, $values['orderCount']);
    }

    public function testBackendFetchesMeituanTradeMetricsWhenOnlySomeSelfMetricsExist(): void
    {
        $controller = $this->controller();
        $requiredFields = ['roomNights', 'roomRevenue', 'salesRoomNights', 'sales', 'orderCount'];

        self::assertTrue($this->invokeNonPublic($controller, 'hasMissingMeituanSelfMetricValues', [[
            'exposure' => 22333,
            'views' => 1884,
            'sales' => 1763,
        ], $requiredFields]));

        self::assertFalse($this->invokeNonPublic($controller, 'hasMissingMeituanSelfMetricValues', [[
            'roomNights' => 7,
            'roomRevenue' => 1177,
            'salesRoomNights' => 10,
            'sales' => 1763,
            'orderCount' => 9,
        ], $requiredFields]));
    }

    public function testBackendReusesMeituanSelfTradeMetricsWithinShortCacheWindow(): void
    {
        (new App(dirname(__DIR__, 3)))->initialize();
        restore_error_handler();
        restore_exception_handler();
        $controller = $this->controller();
        $cacheKey = $this->invokeNonPublic($controller, 'meituanSelfTradeMetricCacheKey', [
            'partner-1',
            'poi-1',
            '2026-07-14',
            '2026-07-14',
            'cookie-a',
            '0',
        ]);
        cache($cacheKey, [
            'status' => 'returned',
            'values' => ['roomNights' => 21, 'roomRevenue' => 3409],
            'message' => '',
            'update_time' => '2026-07-14 00:21:46',
            'cache_hit' => false,
        ], 120);

        try {
            $result = $this->invokeNonPublic($controller, 'fetchMeituanSelfTradeMetricValues', [
                'partner-1',
                'poi-1',
                '2026-07-14',
                '2026-07-14',
                'cookie-a',
                [],
                '0',
            ]);

            self::assertTrue($result['cache_hit']);
            self::assertSame(21.0, $result['values']['roomNights']);
            self::assertSame(3409.0, $result['values']['roomRevenue']);
        } finally {
            cache($cacheKey, null);
        }
    }

    public function testBackendDerivesMeituanRoomRevenueFromSelfMetricsBeforeRankAmount(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'room nights',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => 88, 'percent' => 50, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => 492, 'percent' => 100, 'rank' => 1],
                        ],
                    ],
                    [
                        'dimName' => 'room revenue',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => 154, 'percent' => 50, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => 6054.34, 'percent' => 100, 'rank' => 1],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'roomNights' => 88,
                'roomRevenue' => 15400,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(15400.0, $rowsByPoi['SELF']['roomRevenue']);
        self::assertArrayNotHasKey('roomRevenue', $rowsByPoi['SELF']['metricDerived']);
        self::assertSame(30800.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('¥', $rowsByPoi['RIVAL']['roomRevenuePrefix']);
        self::assertSame('30,800', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(63.0, $rowsByPoi['RIVAL']['avgRoomPrice']);
        self::assertSame('63', $rowsByPoi['RIVAL']['avgRoomPriceText']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['method']);
    }

    public function testBackendDerivesMeituanRoomRevenueFromSelfMetricAndRankValueWhenPercentMissing(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'room nights',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => 88, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => 492, 'rank' => 1],
                        ],
                    ],
                    [
                        'dimName' => 'room revenue',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => 1540, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => 6054.34, 'rank' => 1],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'roomNights' => 88,
                'roomRevenue' => 15400,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(15400.0, $rowsByPoi['SELF']['roomRevenue']);
        self::assertSame(1540.0, $rowsByPoi['SELF']['metricRankValue']['roomRevenue']);
        self::assertSame(60543.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('60,543', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(123.0, $rowsByPoi['RIVAL']['avgRoomPrice']);
        self::assertSame('123', $rowsByPoi['RIVAL']['avgRoomPriceText']);
        self::assertSame('self_value_times_row_rank_value_div_self_rank_value', $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['method']);
        self::assertSame(6054.34, $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['row_rank_value']);
    }

    public function testBackendKeepsMeituanRankValueDerivedRoomRevenueThroughDisplayGroups(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayGroups', [[
            [
                'date_range' => '7',
                'self_metric_values' => [
                    'roomNights' => 7,
                    'roomRevenue' => 1177,
                ],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'roomNights' => 7,
                        'roomRevenue' => 117.7,
                        'metricRankValue' => ['roomRevenue' => 117.7],
                        'metricSourceStatus' => ['roomRevenue' => 'rank_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomNights' => 439,
                        'roomRevenue' => 7240,
                        'metricRankValue' => ['roomRevenue' => 7240],
                        'metricSourceStatus' => ['roomRevenue' => 'rank_returned'],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'date_ranges' => ['7'],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(1177.0, $rowsByPoi['SELF']['roomRevenue']);
        self::assertSame(72400.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('72,400', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(165.0, $rowsByPoi['RIVAL']['avgRoomPrice']);
        self::assertSame('self_value_times_row_rank_value_div_self_rank_value', $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['method']);
    }

    public function testBackendDerivesMeituanPercentOnlyRankValuesFromSelfMetrics(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '销售间夜榜',
                        'aiMetricName' => 'P_XS_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 80, 'rank' => 2],
                        ],
                    ],
                    [
                        'dimName' => '销售额榜',
                        'aiMetricName' => 'P_XS_AMT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 70.5, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'salesRoomNights' => 20,
                'sales' => 3000,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(20.0, $rowsByPoi['SELF']['salesRoomNights']);
        self::assertSame(3000.0, $rowsByPoi['SELF']['sales']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['SELF']['metricSourceStatus']['salesRoomNights']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['SELF']['metricSourceStatus']['sales']);
        self::assertArrayNotHasKey('salesRoomNights', $rowsByPoi['SELF']['metricDerived']);
        self::assertSame('¥', $rowsByPoi['SELF']['salesPrefix']);
        self::assertSame(16.0, $rowsByPoi['RIVAL']['salesRoomNights']);
        self::assertSame(2115.0, $rowsByPoi['RIVAL']['sales']);
        self::assertSame('¥', $rowsByPoi['RIVAL']['salesPrefix']);
        self::assertSame('2,115', $rowsByPoi['RIVAL']['salesText']);
        self::assertSame(80.0, $rowsByPoi['RIVAL']['metricRankPercent']['salesRoomNights']);
        self::assertSame('按本店值和美团百分比推导', $rowsByPoi['RIVAL']['metricSourceStatus']['sales']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['sales']['method']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        self::assertSame(5115.0, $summary['metrics']['totalSales']);
        self::assertSame(3, $summary['metrics']['derivedMetricCount']);
        self::assertStringContainsString('推导', $summary['source_notice']);
    }

    public function testBackendKeepsMeituanPercentOnlyRankValuesMissingWithoutActualAnchor(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SECOND', 'poiName' => 'Second Hotel', 'dataValue' => null, 'percent' => 66.67, 'rank' => 2],
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 5.13, 'rank' => 9],
                            ['poiId' => 'ZERO', 'poiName' => 'Zero Hotel', 'dataValue' => null, 'percent' => 0, 'rank' => 11],
                        ],
                    ],
                    [
                        'dimName' => '销售间夜榜',
                        'aiMetricName' => 'P_XS_PAY_ROOM_NIGHT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SECOND', 'poiName' => 'Second Hotel', 'dataValue' => null, 'percent' => 79.55, 'rank' => 2],
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 2.27, 'rank' => 9],
                            ['poiId' => 'ZERO', 'poiName' => 'Zero Hotel', 'dataValue' => null, 'percent' => 0, 'rank' => 11],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(0.0, $rowsByPoi['TOP']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['SECOND']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['SELF']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['ZERO']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['TOP']['salesRoomNights']);
        self::assertSame(0.0, $rowsByPoi['SECOND']['salesRoomNights']);
        self::assertSame(0.0, $rowsByPoi['SELF']['salesRoomNights']);
        self::assertArrayNotHasKey('roomNights', $rowsByPoi['SELF']['metricDerived']);
        self::assertSame('-', $rowsByPoi['TOP']['roomNightsText']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        self::assertSame(0.0, $summary['metrics']['totalRoomNights']);
        self::assertSame(0.0, $summary['metrics']['totalSalesRoomNights']);
        self::assertStringContainsString('未返回可展示数值', $summary['source_notice']);
        self::assertStringNotContainsString('最小一致整数比例尺', $summary['source_notice']);
        $cardsByKey = [];
        foreach ($summary['cards'] as $card) {
            $cardsByKey[$card['key']] = $card;
        }
        self::assertSame('-', $cardsByKey['totalRoomNights']['value']);
        self::assertSame('-', $cardsByKey['totalSalesRoomNights']['value']);
        self::assertSame('-', $cardsByKey['totalSales']['value']);
        self::assertSame('-', $cardsByKey['avgViewConversionRate']['value']);
        self::assertSame('-', $cardsByKey['avgPayConversionRate']['value']);
    }

    public function testBackendDoesNotPrefixPercentScaleMeituanAmountsAsCurrency(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '销售额榜',
                        'aiMetricName' => 'P_XS_AMT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SECOND', 'poiName' => 'Second Hotel', 'dataValue' => null, 'percent' => 50, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertArrayNotHasKey('sales', $rowsByPoi['TOP']['metricDerived']);
        self::assertSame('-', $rowsByPoi['TOP']['salesText']);
        self::assertSame('', $rowsByPoi['TOP']['salesPrefix']);
        self::assertSame('', $rowsByPoi['SECOND']['salesPrefix']);
    }

    public function testBackendDerivesMeituanTrafficRankValuesFromSelfMetricsAsCounts(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'exposure',
                        'aiMetricName' => 'EXPOSURE',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 60, 'rank' => 2],
                        ],
                    ],
                    [
                        'dimName' => 'view',
                        'aiMetricName' => 'VIEW',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 40, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'exposure' => 10000,
                'views' => 2500,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(10000.0, $rowsByPoi['SELF']['exposure']);
        self::assertSame(2500.0, $rowsByPoi['SELF']['views']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['SELF']['metricSourceStatus']['exposure']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['SELF']['metricSourceStatus']['views']);
        self::assertSame('', $rowsByPoi['SELF']['exposurePrefix']);
        self::assertSame('', $rowsByPoi['SELF']['viewsPrefix']);
        self::assertSame(6000.0, $rowsByPoi['RIVAL']['exposure']);
        self::assertSame(1000.0, $rowsByPoi['RIVAL']['views']);
        self::assertSame('', $rowsByPoi['RIVAL']['exposurePrefix']);
        self::assertSame('', $rowsByPoi['RIVAL']['viewsPrefix']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['exposure']['method']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['views']['method']);
    }

    public function testBackendUsesStoredMeituanSelfTrafficAnchorsForPercentOnlyTrafficRanks(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'exposure',
                        'aiMetricName' => 'EXPOSURE',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 60, 'rank' => 2],
                        ],
                    ],
                    [
                        'dimName' => 'view',
                        'aiMetricName' => 'VIEW',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 40, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'stored_self_metric_values' => [
                'list_exposure' => 10000,
                'detail_exposure' => 2500,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(10000.0, $rowsByPoi['SELF']['exposure']);
        self::assertSame(2500.0, $rowsByPoi['SELF']['views']);
        self::assertSame('meituan_stored_self_traffic', $rowsByPoi['SELF']['metricSourceStatus']['exposure']);
        self::assertSame('meituan_stored_self_traffic', $rowsByPoi['SELF']['metricSourceStatus']['views']);
        self::assertSame(6000.0, $rowsByPoi['RIVAL']['exposure']);
        self::assertSame(1000.0, $rowsByPoi['RIVAL']['views']);
        self::assertSame('', $rowsByPoi['RIVAL']['exposurePrefix']);
        self::assertSame('', $rowsByPoi['RIVAL']['viewsPrefix']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['exposure']['method']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['views']['method']);
    }

    public function testBackendNormalizesMeituanFlowConversionMyHotelSelfMetrics(): void
    {
        $controller = $this->controller();

        $values = $this->invokeNonPublic($controller, 'normalizeMeituanSelfMetricValues', [[
            'data' => [
                'myHotel' => [
                    'exposureUV' => 22333,
                    'intentionUV' => 1884,
                    'payOrderCnt' => 108,
                    'intentionPerExposure' => '8.44%',
                    'payOrderPerIntention' => '5.73%',
                ],
            ],
        ]]);

        self::assertSame(22333.0, $values['exposure']);
        self::assertSame(1884.0, $values['views']);
        self::assertSame(108.0, $values['orderCount']);
        self::assertSame(0.0844, $values['viewConversion']);
        self::assertSame(0.0573, $values['payConversion']);
    }

    public function testBackendNormalizesMeituanHomeBusinessDataCardsAsSelfMetrics(): void
    {
        $controller = $this->controller();

        $values = $this->invokeNonPublic($controller, 'normalizeMeituanSelfMetricValues', [[
            'data' => [
                'cards' => [
                    ['id' => 'EXPOSE_PV_CNT', 'value' => '1.84', 'unit' => '万'],
                    ['id' => 'INTENTION_UV', 'value' => '1884'],
                    ['id' => 'PAY_ORDER_CNT_UV', 'value' => '5.73', 'suffix' => '%'],
                    ['id' => 'PAY_ORDER_CNT', 'value' => '108'],
                    ['id' => 'PAY_ROOMNIGHT', 'value' => '113'],
                    ['id' => 'PAY_AMT', 'value' => '1.99', 'unit' => '万', 'suffix' => '元'],
                    ['id' => 'CONSUME_ROOMNIGHT_SPLIT_EX_7DAYS_REFUND', 'value' => '93'],
                ],
            ],
        ]]);

        self::assertSame(18400.0, $values['exposure']);
        self::assertSame(1884.0, $values['views']);
        self::assertSame(0.0573, $values['payConversion']);
        self::assertSame(108.0, $values['orderCount']);
        self::assertSame(113.0, $values['salesRoomNights']);
        self::assertSame(19900.0, $values['sales']);
        self::assertSame(93.0, $values['roomNights']);
    }

    public function testBackendKeepsMeituanSelfMetricAnchorsScopedByDateRangeGroups(): void
    {
        $controller = $this->controller();

        $groups = [
            [
                'date_range' => '7',
                'self_metric_values' => ['salesRoomNights' => 70],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'salesRoomNights' => 70,
                        'metricRankPercent' => ['salesRoomNights' => 100],
                        'metricSourceStatus' => ['salesRoomNights' => 'meituan_business_detail_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'salesRoomNights' => 0,
                        'metricRankPercent' => ['salesRoomNights' => 50],
                        'metricSourceStatus' => ['salesRoomNights' => '美团仅返回百分比'],
                    ],
                ],
            ],
            [
                'date_range' => '30',
                'self_metric_values' => ['salesRoomNights' => 300],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'salesRoomNights' => 300,
                        'metricRankPercent' => ['salesRoomNights' => 100],
                        'metricSourceStatus' => ['salesRoomNights' => 'meituan_business_detail_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'salesRoomNights' => 0,
                        'metricRankPercent' => ['salesRoomNights' => 10],
                        'metricSourceStatus' => ['salesRoomNights' => '美团仅返回百分比'],
                    ],
                ],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayGroups', [$groups, [
            'target_poi_id' => 'SELF',
            'date_ranges' => ['7', '30'],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(35.0, $rowsByPoi['RIVAL']['salesRoomNights']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['salesRoomNights']['method']);
    }

    public function testBackendKeepsMeituanRoomRevenueScopedToCurrentDateRangeGroup(): void
    {
        $controller = $this->controller();

        $groups = [
            [
                'date_range' => '7',
                'self_metric_values' => ['roomRevenue' => 15400],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'roomRevenue' => 15400,
                        'metricRankPercent' => ['roomRevenue' => 50],
                        'metricSourceStatus' => ['roomRevenue' => 'meituan_business_detail_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomRevenue' => 0,
                        'metricRankPercent' => ['roomRevenue' => 100],
                        'metricSourceStatus' => ['roomRevenue' => '美团仅返回百分比'],
                    ],
                ],
            ],
            [
                'date_range' => '30',
                'self_metric_values' => ['roomRevenue' => 60000],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'roomRevenue' => 60000,
                        'metricRankPercent' => ['roomRevenue' => 50],
                        'metricSourceStatus' => ['roomRevenue' => 'meituan_business_detail_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomRevenue' => 0,
                        'metricRankPercent' => ['roomRevenue' => 80],
                        'metricSourceStatus' => ['roomRevenue' => '美团仅返回百分比'],
                    ],
                ],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayGroups', [$groups, [
            'target_poi_id' => 'SELF',
            'date_ranges' => ['7', '30'],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(30800.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('30,800', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(15400.0, $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['self_value']);
        self::assertSame(100.0, $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['row_percent']);
    }

    public function testBackendPrefersHigherQualityMeituanGroupMetricOverEarlierPercentScale(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayGroups', [[
            [
                'date_range' => '1',
                'display_hotels' => [
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomRevenue' => 1,
                        'metricSourceStatus' => ['roomRevenue' => 'percent_only'],
                        'metricDerived' => ['roomRevenue' => ['method' => 'percent_min_integer_scale']],
                    ],
                ],
            ],
            [
                'date_range' => '30',
                'display_hotels' => [
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomRevenue' => 1000,
                        'metricSourceStatus' => ['roomRevenue' => 'meituan_business_detail_returned'],
                    ],
                ],
            ],
        ], [
            'date_ranges' => ['1', '30'],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(1000.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('1,000', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['RIVAL']['metricSourceStatus']['roomRevenue']);
        self::assertArrayNotHasKey('roomRevenue', $rowsByPoi['RIVAL']['metricDerived']);
    }

    public function testBackendKeepsMeituanTodayRealtimePercentOnlyValuesMissing(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 0, 'rank' => 11],
                        ],
                    ],
                    [
                        'dimName' => '房费收入榜',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 0, 'rank' => 11],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'date_range' => '0',
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(0.0, $rowsByPoi['TOP']['roomNights']);
        self::assertSame('-', $rowsByPoi['TOP']['roomNightsText']);
        self::assertSame('-', $rowsByPoi['TOP']['roomRevenueText']);
        self::assertSame('美团仅返回百分比', $rowsByPoi['TOP']['metricSourceStatus']['roomNights']);
        self::assertSame('美团仅返回百分比', $rowsByPoi['TOP']['metricSourceStatus']['roomRevenue']);
        self::assertArrayNotHasKey('roomNights', $rowsByPoi['TOP']['metricDerived']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        $healthByKey = [];
        foreach ($summary['rank_health_rows'] as $row) {
            $healthByKey[$row['key']] = $row;
        }
        self::assertSame('rank_only', $healthByKey['P_RZ']['status']);
        self::assertSame('仅排名', $healthByKey['P_RZ']['statusText']);
        self::assertSame(0, $summary['metrics']['rankHealthReadyCount']);
        self::assertStringContainsString('每日9点更新前日数据', $summary['source_notice']);
        self::assertStringContainsString('不用 0', $summary['source_notice']);
    }

    public function testBackendFillsMeituanFunnelColumnsFromPercentDerivedTrafficAndSales(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayHotels', [[
            [
                'poiId' => 'TOP',
                'hotelName' => 'Top Hotel',
                'salesRoomNights' => 44,
                'exposure' => 2166,
                'views' => 232,
                'metricSourceStatus' => [
                    'salesRoomNights' => '按美团百分比最小整数比例尺估算',
                    'exposure' => '按美团百分比最小整数比例尺估算',
                    'views' => '按美团百分比最小整数比例尺估算',
                ],
                'metricDerived' => [
                    'salesRoomNights' => ['method' => 'percent_min_integer_scale'],
                    'exposure' => ['method' => 'percent_min_integer_scale'],
                    'views' => ['method' => 'percent_min_integer_scale'],
                ],
            ],
        ]]);

        self::assertSame(0, $rows[0]['orderCount']);
        self::assertSame('-', $rows[0]['orderCountText']);
        self::assertSame(round(232 / 2166, 4), $rows[0]['viewConversion']);
        self::assertSame('10.71%', $rows[0]['viewConversionText']);
        self::assertSame(0.0, $rows[0]['payConversion']);
        self::assertSame('-', $rows[0]['payConversionText']);
        self::assertSame(0.0, $rows[0]['absoluteConversion']);
        self::assertSame('-', $rows[0]['absoluteConversionText']);
        self::assertSame('指数 ', $rows[0]['exposurePrefix']);
        self::assertSame('指数 ', $rows[0]['viewsPrefix']);
        self::assertSame('views_div_exposure', $rows[0]['metricDerived']['viewConversion']['method']);
        self::assertArrayNotHasKey('orderCount', $rows[0]['metricDerived']);
        self::assertArrayNotHasKey('payConversion', $rows[0]['metricDerived']);
        self::assertArrayNotHasKey('absoluteConversion', $rows[0]['metricDerived']);
        self::assertArrayNotHasKey('orderCount', $rows[0]['metricSourceStatus']);
        self::assertSame('按曝光和浏览估算', $rows[0]['metricSourceStatus']['viewConversion']);
        self::assertSame('missing_order_count', $rows[0]['displayMetricStatus']['orderCount']);
        self::assertSame('missing_conversion', $rows[0]['displayMetricStatus']['absoluteConversion']);
    }

    public function testBackendBuildsMeituanBusinessDisplaySummaryForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayHotels', [[
            ['poiId' => 'A', 'hotelName' => 'A', 'roomNights' => 10, 'roomRevenue' => 1000, 'salesRoomNights' => 8, 'sales' => 960, 'exposure' => 2000, 'views' => 500, 'viewConversion' => 0.5, 'payConversion' => 0.1],
            ['poiId' => 'B', 'hotelName' => 'B', 'roomNights' => 5, 'roomRevenue' => 400, 'salesRoomNights' => 4, 'sales' => 360, 'exposure' => 1000, 'views' => 250, 'viewConversion' => 0.4, 'payConversion' => 0.08],
        ]]);
        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, [
            'competitor_room_count' => 20,
            'date_ranges' => ['1'],
        ]]);

        self::assertSame('success', $summary['status']);
        self::assertSame(2, $summary['metrics']['hotelCount']);
        self::assertSame(20, $summary['metrics']['marketInventory']);
        self::assertSame(75.0, $summary['metrics']['marketVitalityRate']);
        self::assertSame(15.0, $summary['metrics']['totalRoomNights']);
        self::assertSame(1400.0, $summary['metrics']['totalRoomRevenue']);
        self::assertSame(12.0, $summary['metrics']['totalSalesRoomNights']);
        self::assertSame(1320.0, $summary['metrics']['totalSales']);
        self::assertSame(3000.0, $summary['metrics']['totalExposure']);
        self::assertSame(750.0, $summary['metrics']['totalViews']);
        self::assertSame(70, $summary['metrics']['totalOrderCount']);
        self::assertSame(45.0, $summary['metrics']['avgViewConversionRate']);
        self::assertSame(9.0, $summary['metrics']['avgPayConversionRate']);
        self::assertSame(4.1, $summary['metrics']['avgAbsoluteConversionRate']);
        $cardsByKey = [];
        foreach ($summary['cards'] as $card) {
            $cardsByKey[$card['key']] = $card;
        }
        self::assertSame('2', $cardsByKey['hotelCount']['value']);
        self::assertSame('20', $cardsByKey['marketInventory']['value']);
    }

    public function testMeituanHistoricalTradePresetsUseCustomDateType(): void
    {
        $controller = $this->controller();

        self::assertSame('CUSTOM', $this->invokeNonPublic($controller, 'meituanSelfTradeDateType', ['7', '2026-07-04', '2026-07-11']));
        self::assertSame('CUSTOM', $this->invokeNonPublic($controller, 'meituanSelfTradeDateType', ['30', '2026-06-12', '2026-07-11']));
        self::assertSame('CUSTOM', $this->invokeNonPublic($controller, 'meituanSelfTradeDateType', ['1', '2026-06-12', '2026-07-11']));
        self::assertSame('DAY', $this->invokeNonPublic($controller, 'meituanSelfTradeDateType', ['1', '2026-07-11', '2026-07-11']));
    }

    public function testMeituanDailyRoomRevenueFallbackOnlyRunsForHistoricalStayRanking(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'shouldFetchMeituanDailyRoomRevenueFallback', ['7', 'P_RZ', []]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldFetchMeituanDailyRoomRevenueFallback', ['30', 'P_RZ', ['roomRevenue' => 0]]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldFetchMeituanDailyRoomRevenueFallback', ['7', 'P_XS', []]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldFetchMeituanDailyRoomRevenueFallback', ['7', 'P_ZH', []]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldFetchMeituanDailyRoomRevenueFallback', ['7', 'P_LL', []]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldFetchMeituanDailyRoomRevenueFallback', ['1', 'P_RZ', []]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldFetchMeituanDailyRoomRevenueFallback', ['30', 'P_RZ', ['roomRevenue' => 100]]));
    }

    public function testMeituanSevenDayMissingTradeAnchorFallsBackToDailyTotals(): void
    {
        $controller = $this->controller();
        if (!method_exists($controller, 'fetchMeituanSelfDailyTradeMetricValues')) {
            self::fail('Missing seven-day daily trade fallback');
        }

        $requestedDates = [];
        $result = $this->invokeNonPublic($controller, 'fetchMeituanSelfDailyTradeMetricValues', [
            'partner-1',
            'poi-1',
            '2026-07-05',
            '2026-07-11',
            'cookie',
            [],
            static function (string $date) use (&$requestedDates): array {
                $requestedDates[] = $date;
                return [
                    'status' => 'returned',
                    'values' => [
                        'roomNights' => 2,
                        'roomRevenue' => 100,
                        'salesRoomNights' => 3,
                        'sales' => 120,
                        'orderCount' => 1,
                    ],
                ];
            },
        ]);

        self::assertSame([
            '2026-07-05',
            '2026-07-06',
            '2026-07-07',
            '2026-07-08',
            '2026-07-09',
            '2026-07-10',
            '2026-07-11',
        ], $requestedDates);
        self::assertSame('returned', $result['status']);
        self::assertSame(7, $result['days_returned']);
        self::assertSame(14.0, $result['values']['roomNights']);
        self::assertSame(700.0, $result['values']['roomRevenue']);
        self::assertSame(21.0, $result['values']['salesRoomNights']);
        self::assertSame(840.0, $result['values']['sales']);
        self::assertSame(7.0, $result['values']['orderCount']);
    }

    public function testMeituanThirtyDayMissingRoomRevenueAnchorFallsBackToDailyTotals(): void
    {
        $controller = $this->controller();
        $requestedDates = [];

        $result = $this->invokeNonPublic($controller, 'fetchMeituanSelfDailyTradeMetricValues', [
            'partner-1',
            'poi-1',
            '2026-06-12',
            '2026-07-11',
            'cookie',
            [],
            static function (string $date) use (&$requestedDates): array {
                $requestedDates[] = $date;
                return [
                    'status' => 'returned',
                    'values' => [
                        'roomNights' => 2,
                        'roomRevenue' => 100,
                        'salesRoomNights' => 3,
                        'sales' => 120,
                        'orderCount' => 1,
                    ],
                ];
            },
        ]);

        self::assertCount(30, $requestedDates);
        self::assertSame('2026-06-12', $requestedDates[0]);
        self::assertSame('2026-07-11', $requestedDates[29]);
        self::assertSame('returned', $result['status']);
        self::assertSame(30, $result['days_returned']);
        self::assertSame(60.0, $result['values']['roomNights']);
        self::assertSame(3000.0, $result['values']['roomRevenue']);
        self::assertSame(90.0, $result['values']['salesRoomNights']);
        self::assertSame(3600.0, $result['values']['sales']);
        self::assertSame(30.0, $result['values']['orderCount']);
    }

    public function testMeituanBooleanVipTagIsPreservedForDisplay(): void
    {
        $controller = $this->controller();

        self::assertSame([
            'tags' => ['VIP'],
            'status' => 'returned',
        ], $this->invokeNonPublic($controller, 'extractMeituanPlatformTagInfo', [['vipTag' => true]]));
        self::assertSame([
            'tags' => [],
            'status' => 'returned_empty',
        ], $this->invokeNonPublic($controller, 'extractMeituanPlatformTagInfo', [['vipTag' => false]]));
    }

    public function testMeituanMissingRoomRevenueSummaryDoesNotPretendZeroRevenue(): void
    {
        $controller = $this->controller();
        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayHotels', [[[
            'poiId' => 'A',
            'hotelName' => 'A',
            'roomNights' => 10,
            'roomRevenue' => 1000,
            'metricSourceStatus' => [
                'roomNights' => '按美团百分比最小整数比例尺估算',
                'roomRevenue' => '按美团百分比最小整数比例尺估算',
            ],
            'metricDerived' => [
                'roomNights' => ['method' => 'percent_min_integer_scale'],
                'roomRevenue' => ['method' => 'percent_min_integer_scale'],
            ],
        ]]]);
        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        $cardsByKey = [];
        foreach ($summary['cards'] as $card) {
            $cardsByKey[$card['key']] = $card;
        }

        self::assertSame('-', $cardsByKey['totalRoomRevenue']['value']);
    }

    public function testBackendBuildsMeituanRankInsightsAndGapsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayHotels', [[
            [
                'poiId' => 'SELF',
                'hotelName' => 'Self Hotel',
                'roomNights' => 8,
                'roomRevenue' => 800,
                'salesRoomNights' => 7,
                'sales' => 770,
                'exposure' => 680,
                'views' => 150,
                'viewConversion' => 0.12,
                'payConversion' => 0.02,
                'rank' => 11,
                'platformTags' => ['VIP'],
                'isSelf' => true,
                'rankHistory' => [
                    ['dateRange' => '1', 'dateRangeLabel' => '昨日', 'rankType' => 'P_RZ', 'rankTypeLabel' => '入住榜', 'rank' => 11],
                    ['dateRange' => '7', 'dateRangeLabel' => '近7天', 'rankType' => 'P_RZ', 'rankTypeLabel' => '入住榜', 'rank' => 8],
                    ['dateRange' => '30', 'dateRangeLabel' => '近30天', 'rankType' => 'P_RZ', 'rankTypeLabel' => '入住榜', 'rank' => 7],
                    ['dateRange' => '30', 'dateRangeLabel' => '近30天', 'rankType' => 'P_XS', 'rankTypeLabel' => '销售榜', 'rank' => 14],
                ],
            ],
            [
                'poiId' => 'TOP',
                'hotelName' => 'Top Hotel',
                'roomNights' => 12,
                'roomRevenue' => 1500,
                'salesRoomNights' => 11,
                'sales' => 1600,
                'exposure' => 700,
                'views' => 160,
                'viewConversion' => 0.14,
                'payConversion' => 0.10,
                'rank' => 1,
                'rankHistory' => [
                    ['dateRange' => '1', 'dateRangeLabel' => '昨日', 'rankType' => 'P_RZ', 'rankTypeLabel' => '入住榜', 'rank' => 1],
                ],
            ],
        ], ['target_poi_id' => 'SELF']]);
        $self = array_values(array_filter($rows, static fn($row): bool => ($row['poiId'] ?? '') === 'SELF'))[0];
        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);

        self::assertSame('掉出前10', $self['rankTrendText']);
        self::assertSame('距前一名 4', $self['gapToPrevText']);
        self::assertSame('近30天最好第 7 / 最差第 14', $self['rank30RangeText']);
        self::assertStringContainsString('距TOP1 4', $self['rankGapSummaryText']);
        self::assertSame(1, $summary['metrics']['vipTaggedCount']);
        self::assertSame('查转化 +2', $summary['metrics']['funnelDiagnosisValue']);
        self::assertSame(3, $summary['metrics']['funnelDiagnosisIssueCount']);
        self::assertSame('第 11 名（本次返回2家）', $summary['metrics']['selfPositionText']);
        $insightsByKey = [];
        foreach ($summary['rank_insights'] as $card) {
            $insightsByKey[$card['key']] = $card;
        }
        self::assertSame('rank_health', str_replace('-', '_', $summary['rank_insights'][0]['key']));
        self::assertSame('距前一名 4', $insightsByKey['rank-gap']['value']);
        self::assertSame('查转化 +2', $insightsByKey['funnel-diagnosis']['value']);
        self::assertStringContainsString('转化低', $insightsByKey['funnel-diagnosis']['note']);
        self::assertSame('非VIP超过本店', $insightsByKey['tag-metric-link']['value']);
        self::assertSame('P_RZ', $summary['rank_health_rows'][0]['key']);
        self::assertSame('已返回', $summary['rank_health_rows'][0]['statusText']);
        self::assertSame('Top Hotel', $summary['top_summary_rows'][0]['hotelName']);
    }

    public function testBackendBuildsCompetitorSummaryFromStoredMeituanRows(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [[
            [
                'system_hotel_id' => 100,
                'hotel_id' => 'TOP',
                'hotel_name' => 'Top Hotel',
                'data_date' => '2026-06-06',
                'data_value' => 15,
                'quantity' => 15,
                'amount' => 0,
                'dimension' => 'room nights',
                'raw_data' => json_encode([
                    'poiName' => 'Top Hotel',
                    'dataValue' => 15,
                    'rankType' => 'P_RZ',
                    'rank' => 1,
                    'dateRange' => '1',
                    'dimension' => 'room nights',
                    'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                    'platformTags' => [],
                    'platformTagStatus' => 'returned_empty',
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'system_hotel_id' => 100,
                'hotel_id' => 'SELF',
                'hotel_name' => 'Self Hotel',
                'data_date' => '2026-06-06',
                'data_value' => 10,
                'quantity' => 10,
                'amount' => 0,
                'dimension' => 'room nights',
                'raw_data' => json_encode([
                    'poiName' => 'Self Hotel',
                    'dataValue' => 10,
                    'rankType' => 'P_RZ',
                    'rank' => 2,
                    'dateRange' => '1',
                    'dimension' => 'room nights',
                    'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                    'platformTags' => ['VIP'],
                    'platformTagStatus' => 'returned',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ], [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
        ]]);

        self::assertSame('success', $payload['status']);
        self::assertSame('success', $payload['data_status']);
        self::assertSame(2, $payload['display_hotel_count']);
        self::assertSame('2026-06-06', $payload['latest_data_date']);
        self::assertSame('Top Hotel', $payload['top_summary_rows'][0]['hotelName']);

        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertTrue($rowsByPoi['SELF']['isSelf']);
        self::assertTrue($rowsByPoi['SELF']['hasVipTag']);
        self::assertSame(['VIP'], $rowsByPoi['SELF']['platformTags']);
        self::assertGreaterThan(0, $rowsByPoi['SELF']['gapToPrev']);
        self::assertNotEmpty($payload['rank_insights']);
        self::assertNotEmpty($payload['rank_health_rows']);
    }

    public function testStoredMeituanSummaryInfersRankTypesAndKeepsFullDateSliceReliable(): void
    {
        $controller = $this->controller();
        $rows = [];
        foreach ([
            ['dimension' => '入住间夜榜', 'top_value' => 15, 'self_value' => 10, 'column' => 'quantity'],
            ['dimension' => '销售额榜', 'top_value' => 3000, 'self_value' => 2000, 'column' => 'amount'],
            ['dimension' => '曝光榜', 'top_value' => 1000, 'self_value' => 700, 'column' => 'data_value'],
            ['dimension' => '支付转化榜', 'top_value' => 12, 'self_value' => 8, 'column' => 'data_value'],
        ] as $item) {
            foreach ([
                ['poi' => 'TOP', 'name' => 'Top Hotel', 'value' => $item['top_value'], 'rank' => 1],
                ['poi' => 'SELF', 'name' => 'Self Hotel', 'value' => $item['self_value'], 'rank' => 2],
            ] as $hotel) {
                $row = [
                    'system_hotel_id' => 100,
                    'hotel_id' => $hotel['poi'],
                    'hotel_name' => $hotel['name'],
                    'data_date' => '2026-06-06',
                    'data_value' => 0,
                    'quantity' => 0,
                    'amount' => 0,
                    'dimension' => $item['dimension'],
                    'raw_data' => json_encode([
                        'poiName' => $hotel['name'],
                        'dataValue' => $hotel['value'],
                        'rank' => $hotel['rank'],
                        'dimension' => $item['dimension'],
                        'platformTagStatus' => 'returned_empty',
                    ], JSON_UNESCAPED_UNICODE),
                ];
                $row[$item['column']] = $hotel['value'];
                $rows[] = $row;
            }
        }

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [$rows, [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
        ]]);

        self::assertSame('ok', $payload['readiness']['status']);
        self::assertSame(2, $payload['display_hotel_count']);
        self::assertSame('Top Hotel', $payload['top_summary_rows'][0]['hotelName']);
        self::assertSame(['P_RZ', 'P_XS', 'P_LL', 'P_ZH'], array_column($payload['rank_health_rows'], 'key'));
        self::assertSame(['ok', 'ok', 'ok', 'ok'], array_column($payload['rank_health_rows'], 'status'));
        self::assertSame('returned_empty', $payload['display_summary']['platform_tag_summary']['status']);
        self::assertSame(0, $payload['display_summary']['platform_tag_summary']['vip_count']);
        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }
        $topConversion = array_values(array_filter(
            $rowsByPoi['TOP']['rankHistory'],
            static fn(array $row): bool => ($row['rankType'] ?? '') === 'P_ZH'
        ))[0];
        $selfConversion = array_values(array_filter(
            $rowsByPoi['SELF']['rankHistory'],
            static fn(array $row): bool => ($row['rankType'] ?? '') === 'P_ZH'
        ))[0];
        self::assertSame(0.12, $topConversion['value']);
        self::assertSame(0.08, $selfConversion['value']);
    }

    public function testStoredMeituanSummaryDerivesPercentOnlyRowsFromSelfMetrics(): void
    {
        $controller = $this->controller();

        $rows = [];
        foreach ([
            ['poi' => 'SELF', 'name' => 'Self Hotel', 'percent' => 100, 'rank' => 1],
            ['poi' => 'RIVAL', 'name' => 'Rival Hotel', 'percent' => 80, 'rank' => 2],
        ] as $hotel) {
            $rows[] = [
                'system_hotel_id' => 100,
                'hotel_id' => $hotel['poi'],
                'hotel_name' => $hotel['name'],
                'data_date' => '2026-06-07',
                'data_value' => 0,
                'quantity' => 0,
                'amount' => 0,
                'dimension' => '销售间夜榜',
                'raw_data' => json_encode([
                    'poiName' => $hotel['name'],
                    'dataValue' => null,
                    'percent' => $hotel['percent'],
                    'metricStatus' => 'platform_percent_only',
                    'rank' => $hotel['rank'],
                    'dimension' => '销售间夜榜',
                    'aiMetricName' => 'P_XS_NIGHT_COUNT',
                    'platformTagStatus' => 'returned_empty',
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [$rows, [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
            'self_metric_values' => ['salesRoomNights' => 25],
        ]]);
        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(25.0, $rowsByPoi['SELF']['salesRoomNights']);
        self::assertSame(20.0, $rowsByPoi['RIVAL']['salesRoomNights']);
        self::assertSame(80.0, $rowsByPoi['RIVAL']['metricRankPercent']['salesRoomNights']);
        self::assertSame('按本店值和美团百分比推导', $rowsByPoi['RIVAL']['metricSourceStatus']['salesRoomNights']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['salesRoomNights']['method']);
        self::assertSame(1, $payload['display_summary']['metrics']['derivedMetricCount']);
        self::assertStringContainsString('推导', $payload['source_notice']);
    }

    public function testStoredMeituanSummaryReusesPersistedSelfMetricAnchor(): void
    {
        $controller = $this->controller();
        $rows = [];
        foreach ([
            ['poi' => 'SELF', 'name' => 'Self Hotel', 'percent' => 50, 'rank' => 2],
            ['poi' => 'RIVAL', 'name' => 'Rival Hotel', 'percent' => 100, 'rank' => 1],
        ] as $hotel) {
            $raw = [
                'poiName' => $hotel['name'],
                'dataValue' => null,
                'percent' => $hotel['percent'],
                'metricStatus' => 'platform_percent_only',
                'rankType' => 'P_RZ',
                'rank' => $hotel['rank'],
                'dimension' => '房费收入榜',
                'aiMetricName' => 'P_RZ_ROOM_PAY',
                'platformTagStatus' => 'returned_empty',
            ];
            if ($hotel['poi'] === 'SELF') {
                $raw['selfMetricValues'] = ['roomRevenue' => 1000];
                $raw['selfMetricStatus'] = 'daily_trade_returned';
            }
            $rows[] = [
                'system_hotel_id' => 100,
                'hotel_id' => $hotel['poi'],
                'hotel_name' => $hotel['name'],
                'data_date' => '2026-07-11',
                'data_value' => 0,
                'quantity' => 0,
                'amount' => 0,
                'dimension' => '房费收入榜',
                'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
        }

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [$rows, [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
        ]]);
        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(1000.0, $rowsByPoi['SELF']['roomRevenue']);
        self::assertSame(2000.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('1,000', $rowsByPoi['SELF']['roomRevenueText']);
        self::assertSame('2,000', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(3000.0, $payload['display_summary']['metrics']['totalRoomRevenue']);
    }

    public function testStoredMeituanSummaryKeepsPercentOnlyRowsAsRankEvidence(): void
    {
        $controller = $this->controller();

        $rows = [];
        foreach ([
            ['poi' => 'TOP', 'name' => 'Top Hotel', 'percent' => 100, 'rank' => 1],
            ['poi' => 'SECOND', 'name' => 'Second Hotel', 'percent' => 66.67, 'rank' => 2],
            ['poi' => 'SELF', 'name' => 'Self Hotel', 'percent' => 5.13, 'rank' => 9],
        ] as $hotel) {
            $rows[] = [
                'system_hotel_id' => 100,
                'hotel_id' => $hotel['poi'],
                'hotel_name' => $hotel['name'],
                'data_date' => '2026-06-08',
                'data_value' => 0,
                'quantity' => 0,
                'amount' => 0,
                'dimension' => '入住间夜榜',
                'raw_data' => json_encode([
                    'poiName' => $hotel['name'],
                    'dataValue' => null,
                    'percent' => $hotel['percent'],
                    'metricStatus' => 'platform_percent_only',
                    'rank' => $hotel['rank'],
                    'dimension' => '入住间夜榜',
                    'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                    'rankType' => '',
                    'platformTagStatus' => 'returned_empty',
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [$rows, [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
        ]]);
        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(0.0, $rowsByPoi['TOP']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['SECOND']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['SELF']['roomNights']);
        self::assertNull($rowsByPoi['SELF']['rankHistory'][0]['value']);
        self::assertArrayNotHasKey('roomNights', $rowsByPoi['SELF']['metricDerived']);
        self::assertSame(0.0, $payload['display_summary']['metrics']['totalRoomNights']);
        self::assertStringContainsString('未返回可展示数值', $payload['source_notice']);
        self::assertStringNotContainsString('最小一致整数比例尺', $payload['source_notice']);
    }

    /**
     * 覆盖 normalizeOnlineDataDate/extractCtripCommentScore：
     * 验证日期输入兼容、非法值兜底、点评分数字段别名。
     */
    public function testMeituanCompetitorLatestBatchScopeUsesLatestFetchTimeWhenHotelIsSelected(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyMeituanCompetitorLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-06-06 18:20:00'],
            '7',
            ['system_hotel_id' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['whereBetween', 'update_time', ['2026-06-06 18:18:00', '2026-06-06 18:20:00']],
        ], $query->calls);
    }

    public function testMeituanCompetitorLatestBatchScopePrefersCreateTimeAfterLaterRowRepair(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyMeituanCompetitorLatestBatchScope', [
            $query,
            [
                'system_hotel_id' => 7,
                'create_time' => '2026-06-06 18:20:00',
                'update_time' => '2026-06-06 19:45:00',
            ],
            '7',
            ['system_hotel_id' => true, 'create_time' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['whereBetween', 'create_time', ['2026-06-06 18:18:00', '2026-06-06 18:20:00']],
        ], $query->calls);
    }

    public function testMeituanCompetitorLatestBatchScopeKeepsLatestSystemHotelAndFetchTimeWhenHotelIsEmpty(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyMeituanCompetitorLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-06-06 18:20:00'],
            '',
            ['system_hotel_id' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['where', 'system_hotel_id', 7],
            ['whereBetween', 'update_time', ['2026-06-06 18:18:00', '2026-06-06 18:20:00']],
        ], $query->calls);
    }

    public function testMeituanCompetitorLatestBatchScopePrefersSyncTaskIdWhenAvailable(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyMeituanCompetitorLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-06-06 18:20:00', 'sync_task_id' => 42],
            '7',
            ['system_hotel_id' => true, 'update_time' => true, 'sync_task_id' => true],
        ]);

        self::assertSame([
            ['where', 'sync_task_id', 42],
        ], $query->calls);
    }

    public function testMeituanCapturedRowsMapBrowserSectionsToOnlineDailyData(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'store_id' => 'store-7',
            'poi_id' => 'poi-99',
            'poi_name' => 'Meituan Hotel',
            'reviews' => [[
                'review_id' => 'review-1',
                'score' => 40,
                'content' => 'room issue',
                'reply' => '',
                'is_negative' => true,
                'review_time' => '2026-05-18 09:30:00',
            ]],
            'traffic' => [[
                'date' => '2026-05-18',
                'exposure_count' => 1000,
                'page_views' => 180,
                'click_count' => 120,
                'unique_visitors' => 80,
                'mt_pay_orders' => 12,
                'mt_pay_rooms' => 9,
                'conversion_rate' => '12.5%',
                'search_rank' => 3,
                'keyword_rank_data' => ['hotel' => 2],
            ]],
            'ads' => [[
                'date' => '2026-05-18',
                'exposure_count' => 500,
                'click_count' => 50,
                'cost' => 88.5,
                'orderAmount' => 300,
                'orderNum' => 2,
                'conversion_rate' => 0.1,
                'keyword_rank_data' => ['cureShops' => true],
            ]],
            'orders' => [[
                'order_id' => 'order-1',
                'order_status' => 'confirmed',
                'room_count' => 2,
                'nights' => 3,
                'total_amount' => 688,
                'avg_price' => 344,
                'order_time' => '2026-05-17 20:00:00',
            ]],
        ], 99]);

        self::assertCount(4, $rows);
        self::assertContains('review', array_column($rows, 'data_type'));

        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame(1000, $rows[0]['list_exposure']);
        self::assertSame(180, $rows[0]['detail_exposure']);
        self::assertSame(12.5, $rows[0]['flow_rate']);
        self::assertSame(120, $rows[0]['order_filling_num']);
        self::assertSame(9, $rows[0]['quantity']);
        self::assertSame(12, $rows[0]['book_order_num']);
        self::assertSame(12, $rows[0]['order_submit_num']);
        self::assertStringContainsString('"unique_visitors":80', $rows[0]['raw_data']);
        self::assertStringContainsString('"mt_pay_orders":12', $rows[0]['raw_data']);

        self::assertSame('review', $rows[1]['data_type']);
        self::assertSame('2026-05-18', $rows[1]['data_date']);
        self::assertSame(4.0, $rows[1]['comment_score']);
        self::assertNull($rows[1]['quantity']);
        self::assertStringNotContainsString('review-1', (string)$rows[1]['raw_data']);
        self::assertStringNotContainsString('room issue', (string)$rows[1]['raw_data']);

        self::assertSame('advertising', $rows[2]['data_type']);
        self::assertSame(500, $rows[2]['list_exposure']);
        self::assertSame(50, $rows[2]['detail_exposure']);
        self::assertSame(88.5, $rows[2]['amount']);
        self::assertNull($rows[2]['quantity']);
        self::assertSame(2, $rows[2]['book_order_num']);
        self::assertSame(2, $rows[2]['order_submit_num']);
        self::assertSame(10.0, $rows[2]['flow_rate']);
        self::assertStringContainsString('"order_amount":300', (string)$rows[2]['raw_data']);

        self::assertSame('order', $rows[3]['data_type']);
        self::assertSame(688.0, $rows[3]['amount']);
        self::assertSame(6, $rows[3]['quantity']);
        self::assertNull($rows[3]['book_order_num']);
        self::assertStringNotContainsString('order-1', (string)$rows[3]['dimension']);
        self::assertMatchesRegularExpression('/^order:confirmed:[a-f0-9]{64}$/', (string)$rows[3]['dimension']);
        self::assertStringNotContainsString('order-1', (string)$rows[3]['raw_data']);
    }

    public function testMeituanAdsKeepIndependentCampaignRowsAndVerifyMetricReadback(): void
    {
        $controller = $this->controller();
        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'poi_id' => 'poi-99',
            'poi_name' => 'Hotel A',
            'ads' => [
                [
                    'campaignId' => 'campaign-1',
                    'planId' => 'plan-1',
                    'date' => '2026-07-13',
                    'cost' => 88.505,
                    'click_count' => 50,
                    'orderNum' => 2,
                ],
                [
                    'campaignId' => 'campaign-1',
                    'planId' => 'plan-2',
                    'date' => '2026-07-13',
                    'cost' => 40,
                    'click_count' => 20,
                    'orderNum' => 1,
                ],
            ],
        ], 99]);

        self::assertCount(2, $rows);
        self::assertCount(2, array_unique(array_column($rows, 'dimension')));
        $uniqueRows = $this->invokeNonPublic(
            $controller,
            'uniqueMeituanCapturedRowsForPersistence',
            [[$rows[0], $rows[0], $rows[1]]]
        );
        self::assertCount(2, $uniqueRows);
        $deduplicatedPersistenceState = $this->invokeNonPublic(
            $controller,
            'buildMeituanDirectPersistenceState',
            [true, count($uniqueRows), count($uniqueRows), 'meituan_ads']
        );
        self::assertTrue($deduplicatedPersistenceState['persisted']);
        self::assertSame('readback_verified', $deduplicatedPersistenceState['persistence_status']);
        self::assertMatchesRegularExpression('/^ads:identity:[a-f0-9]{24}$/', $rows[0]['dimension']);
        self::assertStringNotContainsString('campaign-1', $rows[0]['dimension']);
        $expectedRow = [...$rows[0], 'tenant_id' => 44];
        self::assertTrue($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$expectedRow, $expectedRow]
        ));

        $wrongAmount = $expectedRow;
        $wrongAmount['amount'] = 0;
        self::assertFalse($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$wrongAmount, $expectedRow]
        ));

        $wrongRawData = $expectedRow;
        $wrongRawData['raw_data'] = '{"different":true}';
        self::assertFalse($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$wrongRawData, $expectedRow]
        ));

        $expectedWithTrace = [...$expectedRow, 'source_trace_id' => 'meituan-trace-a'];
        $persistedWithWrongTrace = [...$expectedWithTrace, 'source_trace_id' => 'meituan-trace-b'];
        self::assertFalse($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$persistedWithWrongTrace, $expectedWithTrace]
        ));

        $persistedZeroQuantity = $expectedRow;
        $persistedZeroQuantity['quantity'] = 0;
        self::assertNull($rows[0]['quantity']);
        self::assertFalse($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$persistedZeroQuantity, $expectedRow]
        ));

        $roundedByDatabase = $expectedRow;
        $roundedByDatabase['amount'] = 88.51;
        self::assertTrue($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$roundedByDatabase, $expectedRow]
        ));
    }

    public function testMeituanAdsWithoutStableIdUseContentFingerprintInsteadOfBatchIndex(): void
    {
        $controller = $this->controller();
        $payload = [
            'poi_id' => 'poi-99',
            'poi_name' => 'Hotel A',
            'ads' => [[
                'date' => '2026-07-13',
                'cost' => 30,
                'click_count' => 5,
            ]],
        ];
        $first = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [$payload, 99]);
        $second = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [$payload, 99]);

        self::assertSame($first[0]['dimension'], $second[0]['dimension']);
        self::assertMatchesRegularExpression('/^ads:unidentified:[a-f0-9]{24}$/', $first[0]['dimension']);
        self::assertStringContainsString('"ad_identity_status":"missing_stable_id"', (string)$first[0]['raw_data']);
    }
}
