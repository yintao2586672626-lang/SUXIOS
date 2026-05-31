<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class OnlineDataTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): OnlineData
    {
        $reflection = new ReflectionClass(OnlineData::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    public function testCollectionReliabilityDefinitionsAndQualitySnapshot(): void
    {
        $controller = $this->controller();

        $definitions = $this->invokeNonPublic($controller, 'buildOtaCollectionFieldDefinitions');
        $ctripTraffic = current(array_filter($definitions, static fn(array $item): bool => ($item['source'] ?? '') === 'ctrip' && ($item['module'] ?? '') === 'traffic'));

        self::assertIsArray($ctripTraffic);
        self::assertSame('online_daily_data', $ctripTraffic['storage_table']);
        self::assertContains('list_exposure', array_column($ctripTraffic['fields'], 'field'));
        self::assertContains('detail_exposure', array_column($ctripTraffic['fields'], 'field'));

        $snapshot = $this->invokeNonPublic($controller, 'buildCollectionQualitySnapshot', [[
            [
                'hotel_id' => '1001',
                'hotel_name' => 'Demo Hotel',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-24',
                'list_exposure' => 100,
                'detail_exposure' => 20,
                'raw_data' => json_encode(['listExposure' => 100, 'detailExposure' => 20], JSON_UNESCAPED_UNICODE),
            ],
            [
                'hotel_id' => '',
                'hotel_name' => '',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-24',
                'raw_data' => '{bad-json',
            ],
        ]]);

        self::assertSame(2, $snapshot['checked_records']);
        self::assertSame(1, $snapshot['coverage_days']);
        self::assertGreaterThan(0, $snapshot['issue_records']);
        self::assertGreaterThan(0, $snapshot['score']);
        self::assertNotEmpty($snapshot['source_breakdown']);
    }

    public function testNonNumericCtripFactRowsDoNotRequireRevenueMetrics(): void
    {
        $controller = $this->controller();

        $quality = $this->invokeNonPublic($controller, 'buildOnlineDataQuality', [[
            'hotel_id' => 'ctrip-1001',
            'hotel_name' => 'Demo Hotel',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-06-06',
            'dimension' => 'catalog:market_calendar:hot_calendar:hot_spot_name:0',
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'raw_data' => json_encode([
                'fact_only' => true,
                'metric_status' => 'non_numeric_fact',
                'metrics' => [
                    'hot_spot_name' => 'Concert A',
                    'start_date' => '2026-06-06',
                    'end_date' => '2026-06-06',
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('ok', $quality['status']);
        self::assertSame(0, $quality['missing_count']);
        self::assertNotContains('amount', array_column($quality['missing_metrics'], 'key'));
        self::assertNotContains('quantity', array_column($quality['missing_metrics'], 'key'));
        self::assertNotContains('book_order_num', array_column($quality['missing_metrics'], 'key'));
    }

    /**
     * 覆盖 normalizeAppTrafficRow/readTrafficNumber/normalizeTrafficPercent/trafficRate：
     * 验证正常流量行、零分母边界值、非法日期异常输入兜底。
     */
    public function testNormalizeAppTrafficRowCoversNormalBoundaryAndInvalidInput(): void
    {
        $controller = $this->controller();

        $normal = $this->invokeNonPublic($controller, 'normalizeAppTrafficRow', [[
            'dataDate' => '2026-05-01 08:00:00',
            'hotelId' => 88,
            'listExposure' => '1000',
            'detailExposure' => '250',
            'orderFillingNum' => '25',
            'orderSubmitNum' => '5',
            'flowRate' => '0.2',
            'orderFillRate' => '10',
            'submitRate' => '0.2',
        ]]);

        self::assertSame('2026-05-01', $normal['date']);
        self::assertSame('self', $normal['compare_type']);
        self::assertSame(1000.0, $normal['metrics']['exposure']);
        self::assertSame(20.0, $normal['metrics']['exposure_rate']);
        self::assertSame(10.0, $normal['metrics']['order_rate']);
        self::assertSame(20.0, $normal['metrics']['deal_rate']);

        $boundary = $this->invokeNonPublic($controller, 'normalizeAppTrafficRow', [[
            'date' => '2026-05-02',
            'compare_type' => 'competitor',
            'exposure' => 0,
            'detail_visitors' => 0,
            'order_visitors' => 0,
            'submit_users' => 0,
        ]]);

        self::assertSame('competitor', $boundary['compare_type']);
        self::assertSame(0.0, $boundary['metrics']['exposure_rate']);
        self::assertSame(0.0, $this->invokeNonPublic($controller, 'trafficRate', [12.0, 0.0]));
        self::assertNull($this->invokeNonPublic($controller, 'normalizeAppTrafficRow', [['date' => 'not-a-date']]));
    }

    /**
     * 覆盖 buildAppTrafficDerivedAnalysis/calculateAppTrafficDerivedMetrics：
     * 验证携程流量响应的汇总、缺口指标、空响应边界。
     */
    public function testBuildAppTrafficDerivedAnalysisCoversSummaryAndEmptyResponse(): void
    {
        $controller = $this->controller();
        $response = [
            'data' => [
                'list' => [
                    [
                        'date' => '2026-05-01',
                        'hotelId' => 1001,
                        'listExposure' => 1000,
                        'detailExposure' => 200,
                        'orderFillingNum' => 40,
                        'orderSubmitNum' => 8,
                    ],
                    [
                        'date' => '2026-05-01',
                        'hotelId' => -1,
                        'listExposure' => 2000,
                        'detailExposure' => 600,
                        'orderFillingNum' => 120,
                        'orderSubmitNum' => 36,
                    ],
                ],
            ],
        ];

        $analysis = $this->invokeNonPublic($controller, 'buildAppTrafficDerivedAnalysis', [$response]);

        self::assertCount(1, $analysis['rows']);
        self::assertSame(1000.0, $analysis['summary']['exposure_gap']);
        self::assertSame(33.33, $analysis['summary']['detail_achieve_rate']);
        self::assertSame(20.0, $analysis['summary']['self']['deal_rate']);
        self::assertSame(30.0, $analysis['summary']['competitor']['deal_rate']);
        self::assertIsArray($analysis['recommendations']);

        $empty = $this->invokeNonPublic($controller, 'buildAppTrafficDerivedAnalysis', [['data' => ['list' => []]]]);
        self::assertSame([], $empty['rows']);
        self::assertSame(0.0, $empty['summary']['self']['exposure']);
    }

    public function testCtripTrafficDateRangeUsesSettledDailyRange(): void
    {
        $controller = $this->controller();
        $now = strtotime('2026-05-26 00:30:00');

        self::assertSame(['2026-05-25', '2026-05-25'], $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', [
            'yesterday',
            '',
            '',
            $now,
        ]));
        self::assertSame(['2026-05-19', '2026-05-25'], $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', [
            'last_7_days',
            '',
            '',
            $now,
        ]));
        self::assertSame(['2026-04-26', '2026-05-25'], $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', [
            'last_30_days',
            '',
            '',
            $now,
        ]));
    }

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
                        'phone' => '13800138000',
                        'mobile' => '13900139000',
                        'idCardNo' => '110101199003074219',
                        'customerRemark' => 'late arrival with child',
                    ]],
                ],
            ],
        ], 7]);

        self::assertCount(2, $rows);
        self::assertNotContains('review', array_column($rows, 'data_type'));
        self::assertSame('meituan', $rows[0]['source']);
        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame('2026-05-03', $rows[0]['data_date']);
        self::assertSame(100, $rows[0]['list_exposure']);
        self::assertSame(40, $rows[0]['detail_exposure']);
        self::assertSame(40.0, $rows[0]['flow_rate']);

        self::assertSame('order', $rows[1]['data_type']);
        self::assertSame('2026-05-01', $rows[1]['data_date']);
        self::assertSame(500.0, $rows[1]['amount']);
        self::assertSame(4, $rows[1]['quantity']);
        self::assertSame(7, $rows[1]['system_hotel_id']);
        self::assertStringNotContainsString('ORDER-1', (string)$rows[1]['dimension']);

        $orderRaw = (string)$rows[1]['raw_data'];
        self::assertStringNotContainsString('ORDER-1', $orderRaw);
        self::assertStringNotContainsString('Alice Guest', $orderRaw);
        self::assertStringNotContainsString('13800138000', $orderRaw);
        self::assertStringNotContainsString('13900139000', $orderRaw);
        self::assertStringNotContainsString('110101199003074219', $orderRaw);
        self::assertStringNotContainsString('late arrival with child', $orderRaw);

        $decodedOrderRaw = json_decode($orderRaw, true);
        self::assertIsArray($decodedOrderRaw);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decodedOrderRaw['order_id_hash'] ?? ''));
        self::assertSame('A***', $decodedOrderRaw['guest_name_masked'] ?? null);
        self::assertSame('*******8000', $decodedOrderRaw['phone_masked'] ?? null);
        self::assertSame('*******9000', $decodedOrderRaw['mobile_masked'] ?? null);
        self::assertArrayNotHasKey('idCardNo', $decodedOrderRaw);
        self::assertArrayNotHasKey('customerRemark', $decodedOrderRaw);
    }

    public function testDailyOtaSupplementSummaryExcludesReviews(): void
    {
        $controller = $this->controller();

        $summary = $this->invokeNonPublic($controller, 'buildDailyOtaSupplementSummary', [[
            [
                'data_type' => 'advertising',
                'amount' => 100,
                'list_exposure' => 1000,
                'detail_exposure' => 100,
                'book_order_num' => 4,
                'raw_data' => json_encode(['orderAmount' => 500], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_type' => 'quality',
                'data_value' => 86.5,
                'raw_data' => json_encode(['serviceScore' => 91], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_type' => 'review',
                'comment_score' => 1.0,
                'raw_data' => json_encode(['content' => 'ignored'], JSON_UNESCAPED_UNICODE),
            ],
        ]]);

        self::assertSame('ota_channel', $summary['scope']);
        self::assertSame('ok', $summary['data_status']);
        self::assertSame(100.0, $summary['advertising']['spend']);
        self::assertSame(500.0, $summary['advertising']['order_amount']);
        self::assertSame(5.0, $summary['advertising']['roas']);
        self::assertSame(1, $summary['service_quality']['sample_count']);
        self::assertSame(86.5, $summary['service_quality']['avg_psi_score']);
        self::assertSame(91.0, $summary['service_quality']['avg_service_score']);
        self::assertArrayNotHasKey('reviews', $summary);
    }

    public function testAutoFetchTaskPlanDoesNotQueueCommentModules(): void
    {
        $controller = $this->controller();

        $tasks = $this->invokeNonPublic($controller, 'buildAutoFetchConfigTaskPlan', [
            7,
            '2026-05-03',
            [
                'cookies' => 'configured',
                'node_id' => '24588',
            ],
            [
                'cookies' => 'configured',
                'partner_id' => 'partner-1',
                'poi_id' => 'poi-1',
            ],
            [
                'ctrip-comments' => [
                    'enabled' => true,
                    'system_hotel_id' => 7,
                    'request_url' => 'https://ebooking.ctrip.com/comment/getCommentList',
                    'cookies' => 'configured',
                    'spidertoken' => 'configured',
                    'payload_json' => '{"pageIndex":1}',
                ],
                'meituan-comments' => [
                    'enabled' => true,
                    'system_hotel_id' => 7,
                    'request_url' => 'https://eb.meituan.com/api/v1/ebooking/comments/commentsInfo',
                    'cookies' => 'configured',
                    'partner_id' => 'partner-1',
                    'poi_id' => 'poi-1',
                ],
            ],
        ]);

        self::assertNotContains('comments', array_column($tasks, 'module'));
        self::assertContains('business', array_column($tasks, 'module'));
        self::assertContains('ranking', array_column($tasks, 'module'));
    }

    public function testExtractCtripTrafficRowsExpandsDailyMetricSeries(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'extractCtripTrafficRows', [[
            'data' => [
                'dateList' => ['2026-04-12', '2026-04-13'],
                'myHotel' => [
                    'totalListExposure' => [3146, 3941],
                    'totalDetailExposure' => [526, 647],
                    'listTransforDetailRate' => ['16.72%', '16.42%'],
                    'orderFillingNum' => [32, 30],
                    'orderSubmitNum' => [20, 19],
                ],
                'competeHotelAvg' => [
                    'totalListExposure' => [2096, 2460],
                    'totalDetailExposure' => [320, 380],
                    'listTransforDetailRate' => ['15.29%', '15.45%'],
                    'orderFillingNum' => [20, 20],
                    'orderSubmitNum' => [11, 12],
                ],
            ],
        ]]);

        self::assertCount(4, $rows);
        self::assertSame('2026-04-12', $rows[0]['date']);
        self::assertSame('self', $rows[0]['compareType']);
        self::assertSame(3146, $rows[0]['listExposure']);
        self::assertSame(16.72, $rows[0]['flowRate']);
        self::assertSame('competitor', $rows[2]['compareType']);
        self::assertSame(2460, $rows[3]['listExposure']);
        self::assertSame(12, $rows[3]['orderSubmitNum']);
    }

    /**
     * 覆盖 extractCtripBusinessDataList/buildCtripBusinessFingerprint/extractCtripResponseDates/extractHotelData：
     * 验证多层响应解析、指纹稳定性、日期递归提取。
     */
    public function testCtripBusinessExtractionFingerprintAndDates(): void
    {
        $controller = $this->controller();
        $response = [
            'data' => [
                'bucket' => [
                    ['hotelId' => 2, 'hotelName' => 'B', 'amount' => 200, 'quantity' => 2],
                    ['hotel_id' => 1, 'hotel_name' => 'A', 'amount' => 100, 'room_nights' => 1],
                ],
            ],
        ];

        $list = $this->invokeNonPublic($controller, 'extractCtripBusinessDataList', [$response]);
        self::assertCount(2, $list);

        $fingerprintA = $this->invokeNonPublic($controller, 'buildCtripBusinessFingerprint', [$response]);
        $fingerprintB = $this->invokeNonPublic($controller, 'buildCtripBusinessFingerprint', [[
            ['hotel_id' => 1, 'hotel_name' => 'A', 'totalAmount' => 100, 'roomNights' => 1],
            ['hotelId' => 2, 'hotelName' => 'B', 'amount' => 200, 'quantity' => 2],
        ]]);
        self::assertNotSame('', $fingerprintA);
        self::assertSame($fingerprintA, $fingerprintB);

        $dates = $this->invokeNonPublic($controller, 'extractCtripResponseDates', [[
            'dataDate' => '20260501',
            'nested' => ['statDate' => '2026-05-02 12:00:00'],
            'invalid' => ['reportDate' => ['2026-05-03']],
        ]]);
        self::assertSame(['2026-05-01', '2026-05-02'], $dates);

        $hotels = $this->invokeNonPublic($controller, 'extractHotelData', [[
            'outer' => [['HotelId' => 9, 'HotelName' => 'Nested']],
        ]]);
        self::assertSame(9, $hotels[0]['HotelId']);
    }

    public function testBackendBuildsCtripBusinessDisplayRowsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            'date_results' => [
                ['data' => ['data' => [['hotelId' => 1, 'hotelName' => 'A', 'amount' => 100, 'quantity' => 2, 'bookOrderNum' => 1]]]],
                ['data' => ['data' => [['hotelId' => 1, 'hotelName' => 'A', 'amount' => 80, 'quantity' => 3, 'bookOrderNum' => 2]]]],
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame('1', (string)$rows[0]['hotelId']);
        self::assertSame('A', $rows[0]['hotelName']);
        self::assertSame(180.0, $rows[0]['amount']);
        self::assertSame(5, $rows[0]['quantity']);
        self::assertSame(3, $rows[0]['bookOrderNum']);
        self::assertSame(3, $rows[0]['totalOrderNum']);
    }

    public function testBackendBuildsCtripBusinessDisplayRowsFromStoredRawData(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            [
                'hotel_id' => '121669867',
                'hotel_name' => '长沙宾际·云端酒店',
                'amount' => '28898.42',
                'quantity' => 114,
                'book_order_num' => 95,
                'raw_data' => json_encode([
                    'hotelId' => 121669867,
                    'hotelName' => '长沙宾际·云端酒店',
                    'totalDetailNum' => 612,
                    'qunarDetailVisitors' => 438,
                    'qunarDetailCR' => 10.05,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame(612, $rows[0]['totalDetailNum']);
        self::assertSame(438, $rows[0]['qunarDetailVisitors']);

        $summary = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplaySummary', [$rows]);
        self::assertSame(612, $summary['metrics']['totalDetailNum']);
        self::assertSame(438, $summary['metrics']['totalQunarDetailVisitors']);
    }

    public function testBackendBuildsCtripBusinessDisplayDerivedMetricsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            ['hotelId' => 'A', 'hotelName' => 'A', 'amount' => 1000, 'quantity' => 5, 'bookOrderNum' => 2, 'totalDetailNum' => 100],
            ['hotelId' => 'B', 'hotelName' => 'B', 'amount' => 800, 'quantity' => 4, 'bookOrderNum' => 1, 'totalDetailNum' => 50],
        ]]);

        self::assertSame('A', $rows[0]['hotelId']);
        self::assertSame(200.0, $rows[0]['adr']);
        self::assertSame('200.00', $rows[0]['adrText']);
        self::assertSame(100.0, $rows[0]['ari']);
        self::assertSame('100.0', $rows[0]['ariText']);
        self::assertSame(round(100 * log(5), 2), $rows[0]['sci']);
        self::assertSame((string)round(100 * log(5)), $rows[0]['sciText']);
        self::assertSame(2.0, $rows[0]['bookingRate']);
        self::assertSame('2.0%', $rows[0]['bookingRateText']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['adr']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['ari']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['bookingRate']);
    }

    public function testBackendBuildsCtripBusinessDisplaySummaryForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            ['hotelId' => 'A', 'hotelName' => 'A', 'amount' => 1000, 'quantity' => 5, 'bookOrderNum' => 2, 'totalOrderNum' => 4, 'totalDetailNum' => 100, 'qunarDetailVisitors' => 50],
            ['hotelId' => 'B', 'hotelName' => 'B', 'amount' => 800, 'quantity' => 4, 'bookOrderNum' => 1, 'totalOrderNum' => 2, 'totalDetailNum' => 50, 'qunarDetailVisitors' => 25],
        ]]);
        $summary = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplaySummary', [$rows]);

        self::assertSame('success', $summary['status']);
        self::assertSame(2, $summary['metrics']['hotelCount']);
        self::assertSame(1800.0, $summary['metrics']['totalAmount']);
        self::assertSame(9, $summary['metrics']['totalQuantity']);
        self::assertSame(200.0, $summary['metrics']['adr']);
        self::assertSame(100.0, $summary['metrics']['avgAri']);
        self::assertSame(round((round(100 * log(5), 2) + round(100 * log(4), 2)) / 2, 2), $summary['metrics']['avgSci']);
        self::assertSame(150, $summary['metrics']['totalDetailNum']);
        self::assertSame(75, $summary['metrics']['totalQunarDetailVisitors']);
        self::assertSame(6, $summary['metrics']['totalOrderNum']);
        self::assertSame('totalAmount', $summary['cards'][1]['key']);
        self::assertSame('¥1,800', $summary['cards'][1]['value']);
        self::assertSame('adr', $summary['cards'][3]['key']);
        self::assertSame('¥200.00', $summary['cards'][3]['value']);
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
        self::assertSame(0.05, $rows[0]['absoluteConversion']);
        self::assertSame('5.00%', $rows[0]['absoluteConversionText']);
        self::assertSame('50.00%', $rows[0]['viewConversionText']);
        self::assertSame('10.00%', $rows[0]['payConversionText']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['avgRoomPrice']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['absoluteConversion']);
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
        self::assertSame('hotelCount', $summary['cards'][0]['key']);
        self::assertSame('2', $summary['cards'][0]['value']);
        self::assertSame('marketInventory', $summary['cards'][1]['key']);
        self::assertSame('20', $summary['cards'][1]['value']);
    }

    public function testCtripTrafficDateRangeCoversPresetsCustomAndInvalidInput(): void
    {
        $controller = $this->controller();

        $lastSevenDays = $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', ['last_7_days', '', '']);
        self::assertCount(2, $lastSevenDays);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $lastSevenDays[0]);

        self::assertSame(
            ['2026-05-01', '2026-05-03'],
            $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', ['custom', '2026-05-01', '2026-05-03'])
        );

        $this->expectException(InvalidArgumentException::class);
        $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', ['custom', '2026-05-04', '2026-05-03']);
    }

    /**
     * 覆盖 extractCtripTrafficRows/isAllowedOtaRequestUrl：
     * 验证流量列表路径兼容、非数组边界、安全域名校验。
     */
    public function testCtripTrafficRowsAndAllowedUrlValidation(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'extractCtripTrafficRows', [[
            'result' => ['list' => [['date' => '2026-05-01', 'hotelId' => 1]]],
        ]]);
        self::assertSame(1, $rows[0]['hotelId']);
        self::assertSame([], $this->invokeNonPublic($controller, 'extractCtripTrafficRows', ['bad-response']));

        $suffixes = ['ctrip.com', 'meituan.com'];
        self::assertTrue($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ebooking.ctrip.com/api', $suffixes]));
        self::assertTrue($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ctrip.com/api', $suffixes]));
        self::assertTrue($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://bbk.ctripbiz.cn/api', ['ctripbiz.cn']]));
        self::assertFalse($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['http://ebooking.ctrip.com/api', $suffixes]));
        self::assertFalse($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ctrip.com.evil.test/api', $suffixes]));
        self::assertFalse($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ctripbiz.cn.evil.test/api', ['ctripbiz.cn']]));
    }

    public function testBackendBuildsCtripTrafficDisplayRowsAndSummaryForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripTrafficDisplayRows', [[
            ['dataDate' => '2026-05-18', 'hotelId' => 88, 'listExposure' => 1000, 'detailExposure' => 200, 'orderFillingNum' => 20, 'orderSubmitNum' => 5],
            ['dataDate' => '2026-05-18', 'hotelId' => -1, 'listExposure' => 800, 'detailExposure' => 160, 'orderFillingNum' => 16, 'orderSubmitNum' => 4],
        ]]);

        self::assertCount(2, $rows);
        self::assertSame('self', $rows[0]['compareType']);
        self::assertSame('competitor_avg', $rows[1]['compareType']);
        self::assertSame(20.0, $rows[0]['flowRate']);
        self::assertSame(25.0, $rows[0]['submitRate']);

        $summary = $this->invokeNonPublic($controller, 'buildCtripTrafficDisplaySummary', [$rows]);
        self::assertSame(1000.0, $summary['self']['listExposure']);
        self::assertSame(800.0, $summary['avg']['listExposure']);
        self::assertSame(20.0, $summary['self']['flowRate']);
        self::assertSame(25.0, $summary['avg']['submitRate']);
    }

    public function testCtripFlowPageTrafficAliasesAndRankRowsAreExtracted(): void
    {
        $controller = $this->controller();

        $response = [
            'data' => [
                'categoryRankList' => [[
                    'statDate' => '2026-05-18',
                    'nodeId' => 1685042,
                    'PV' => '1234',
                    'UV' => '456',
                    'clickCount' => '78',
                    'orderCount' => '9',
                    'conversionRate' => '12.5%',
                    'competitionRank' => 3,
                    'categoryRank' => 5,
                    'rankJson' => ['category' => 5, 'competition' => 3],
                ]],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'extractCtripTrafficRows', [$response]);
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]['categoryRank']);

        $normalized = $this->invokeNonPublic($controller, 'normalizeAppTrafficRow', [$rows[0]]);
        self::assertSame('2026-05-18', $normalized['date']);
        self::assertSame(1234.0, $normalized['metrics']['exposure']);
        self::assertSame(456.0, $normalized['metrics']['detail_visitors']);
        self::assertSame(78.0, $normalized['metrics']['order_visitors']);
        self::assertSame(9.0, $normalized['metrics']['submit_users']);
        self::assertSame(12.5, $normalized['metrics']['exposure_rate']);

        $captured = $this->invokeNonPublic($controller, 'extractCtripCapturedSection', [[
            'responses' => [[
                'url' => 'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/getStatData',
                'data' => [
                    'data' => [
                        'rankList' => [[
                            'date' => '2026-05-18',
                            'nodeId' => 1685042,
                            'competitionRank' => 2,
                            'categoryRank' => 4,
                            'rankJson' => ['category' => 4, 'competition' => 2],
                        ]],
                    ],
                ],
            ]],
        ], 'traffic']);

        self::assertCount(1, $captured);
        self::assertSame(4, $captured[0]['categoryRank']);
        self::assertSame(['category' => 4, 'competition' => 2], $captured[0]['rankJson']);
    }

    /**
     * 覆盖 mergeOnlineDataHotelList/onlineDataHotelKey/sanitizeSecretConfig/maskSecretValue：
     * 验证系统酒店优先合并、OTA ID 兜底、敏感字段脱敏。
     */
    public function testHotelListMergeAndSecretSanitization(): void
    {
        $controller = $this->controller();

        $merged = $this->invokeNonPublic($controller, 'mergeOnlineDataHotelList', [[
            ['system_hotel_id' => 7, 'hotel_id' => 'ota-a', 'hotel_name' => ''],
            ['system_hotel_id' => '7', 'hotel_id' => 'ota-b', 'hotel_name' => 'Hotel A'],
            ['hotel_id' => 'external-1', 'hotel_name' => 'External'],
            ['hotel_name' => 'Missing key'],
        ]]);

        self::assertCount(2, $merged);
        self::assertSame(7, $merged[0]['id']);
        self::assertSame('Hotel A', $merged[0]['hotel_name']);
        self::assertSame('ota-a', $merged[0]['ota_hotel_id']);
        self::assertSame('external-1', $merged[1]['id']);

        $sanitized = $this->invokeNonPublic($controller, 'sanitizeSecretConfig', [[
            'name' => 'config-a',
            'cookies' => 'abcdefghijk',
            'token' => '12345678',
            'spidertoken' => '',
        ]]);

        self::assertArrayNotHasKey('cookies', $sanitized);
        self::assertArrayNotHasKey('token', $sanitized);
        self::assertTrue($sanitized['has_cookies']);
        self::assertSame('abcd...hijk', $sanitized['cookies_preview']);
        self::assertSame('********', $sanitized['token_preview']);
        self::assertFalse($sanitized['has_spidertoken']);
    }

    public function testAutoFetchConfigTaskPlanMirrorsAutomationMode(): void
    {
        $controller = $this->controller();

        $tasks = $this->invokeNonPublic($controller, 'buildAutoFetchConfigTaskPlan', [
            7,
            '2026-05-18',
            [
                'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                'node_id' => 'node-7',
                'cookies' => 'ctrip-cookie',
                'auth_data' => ['xCtxLocale' => 'zh-CN'],
            ],
            [
                'url' => 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
                'partner_id' => 'partner-7',
                'poi_id' => 'poi-7',
                'cookies' => 'meituan-cookie',
                'auth_data' => ['token' => 'token-7'],
            ],
            [
                'ctrip-traffic' => [
                    'system_hotel_id' => 7,
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1',
                    'cookies' => 'ctrip-traffic-cookie',
                    'spiderkey' => 'spider-7',
                ],
                'ctrip-comments' => [
                    'system_hotel_id' => '7',
                    'request_url' => 'https://ebooking.ctrip.com/api/getCommentList',
                    'hotel_id' => 'ctrip-hotel-7',
                    'cookies' => 'ctrip-comment-cookie',
                    'spidertoken' => 'spider-token-7',
                    'payload_json' => '{"hotelId":"ctrip-hotel-7"}',
                ],
                'meituan-traffic' => [
                    'hotelId' => 7,
                    'url' => 'https://eb.meituan.com/api/v1/ebooking/traffic',
                    'partner_id' => 'partner-traffic-7',
                    'poi_id' => 'poi-traffic-7',
                    'cookies' => 'meituan-traffic-cookie',
                ],
                'meituan-comments' => [
                    'system_hotel_id' => 8,
                    'partner_id' => 'partner-8',
                    'poi_id' => 'poi-8',
                    'cookies' => 'meituan-comment-cookie',
                ],
            ],
        ]);

        $labels = array_column($tasks, 'label');
        self::assertContains('ctrip-business', $labels);
        self::assertContains('ctrip-traffic', $labels);
        self::assertNotContains('ctrip-comments', $labels);
        self::assertContains('meituan-P_RZ', $labels);
        self::assertContains('meituan-P_XS', $labels);
        self::assertContains('meituan-P_ZH', $labels);
        self::assertContains('meituan-P_LL', $labels);
        self::assertContains('meituan-traffic', $labels);
        self::assertNotContains('meituan-comments', $labels);
        self::assertNotContains('comments', array_column($tasks, 'module'));

        foreach ($tasks as $task) {
            self::assertSame(7, $task['body']['system_hotel_id']);
            self::assertTrue($task['body']['auto_save']);
            self::assertSame('2026-05-18', $task['body']['start_date']);
            self::assertSame('2026-05-18', $task['body']['end_date']);
        }

        $rankTask = $tasks[array_search('meituan-P_RZ', $labels, true)];
        self::assertSame('P_RZ', $rankTask['body']['rank_type']);

    }

    public function testCookieHealthMessagesAreActionableChinesePrompts(): void
    {
        $controller = $this->controller();

        self::assertSame('携程 Cookie状态正常。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['ctrip', 'ok', 0]));
        self::assertSame('美团 Cookie为空，请重新登录OTA后台后更新授权。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['meituan', 'empty', null]));
        self::assertSame('OTA Cookie缺少更新时间，请重新保存一次配置以便系统判断有效期。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['generic', 'unknown', null]));
        self::assertSame('/online-data?tab=cookies', $this->invokeNonPublic($controller, 'cookieReauthorizeEntry', []));
    }

    public function testCookieHealthStateClassifiesEmptyUnknownWarningExpiredAndAlerted(): void
    {
        $controller = $this->controller();

        self::assertSame('expired', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['', null, false, 5, 14]));
        self::assertSame('unknown', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', null, false, 5, 14]));
        self::assertSame('ok', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', 4, false, 5, 14]));
        self::assertSame('warning', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', 5, false, 5, 14]));
        self::assertSame('expired', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', 14, false, 5, 14]));
        self::assertSame('expired', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', 1, true, 5, 14]));
    }

    public function testCollectionAuthorizationRowsFilterGlobalAndSelectedHotelHistory(): void
    {
        $controller = $this->controller();
        $rows = [
            ['hotel_id' => 0, 'status' => 'ok'],
            ['hotel_id' => 7, 'status' => 'warning'],
            ['hotel_id' => 8, 'status' => 'expired'],
        ];

        $filtered = $this->invokeNonPublic($controller, 'filterCollectionAuthorizationRows', [$rows, 7]);
        $summary = $this->invokeNonPublic($controller, 'buildCollectionAuthorizationSummary', [$filtered]);

        self::assertSame([0, 7], array_column($filtered, 'hotel_id'));
        self::assertSame('warning', $summary['overall_status']);
        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['ok']);
        self::assertSame(1, $summary['warning']);
        self::assertSame(0, $summary['expired']);
    }

    public function testCollectionReliabilityUsesUnifiedStatusVocabulary(): void
    {
        $controller = $this->controller();

        $catalog = $this->invokeNonPublic($controller, 'collectionReliabilityStatusCatalog');
        self::assertSame([
            'ok',
            'warning',
            'expired',
            'unknown',
            'waiting_config',
            'failed',
            'partial_success',
            'success',
        ], $catalog);

        $emptySummary = $this->invokeNonPublic($controller, 'buildCollectionAuthorizationSummary', [[]]);
        self::assertSame('waiting_config', $emptySummary['overall_status']);

        $expiredSummary = $this->invokeNonPublic($controller, 'buildCollectionAuthorizationSummary', [[
            ['hotel_id' => 7, 'status' => 'expired'],
        ]]);
        self::assertSame('expired', $expiredSummary['overall_status']);
    }

    public function testCtripCaptureCatalogHealthSummarizesCatalogAndFailedAudit(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'buildCtripCaptureCatalogHealth', [[
            'platform' => 'ctrip',
            'section_count' => 16,
            'endpoint_count' => 69,
            'field_count' => 110,
            'default_sections' => ['business_overview', 'traffic_report'],
            'presets' => [
                'default' => ['sections' => ['business_overview', 'traffic_report']],
                'wide' => ['sections' => ['homepage', 'biztravel_bpi']],
            ],
            'interaction_plan_section_count' => 14,
            'interaction_plan_step_count' => 64,
        ], [
            'auth_status' => ['status' => 'login_required'],
            'summary' => ['response_count' => 0, 'standard_row_count' => 0],
            'field_coverage' => ['coverage_rate' => null],
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['auth_session', 'field_coverage'],
            ],
            'capture_gap_report' => [
                'status' => 'blocked_auth',
                'blockers' => ['auth_session', 'response_count'],
                'missing_formal_endpoint_count' => 2,
                'missing_formal_endpoints' => [
                    ['id' => 'business_realtime', 'section' => 'business_overview'],
                    ['id' => 'traffic_flow_transform', 'section' => 'traffic_report'],
                ],
                'missing_fields_by_section' => [
                    'business_overview' => ['missing_field_count' => 3],
                    'traffic_report' => ['missing_field_count' => 2],
                ],
                'p3_candidate_sections' => [
                    'orders_detail' => ['count' => 1],
                ],
                'p3_evidence_sections' => [
                    'orders_detail' => ['status' => 'missing_evidence'],
                    'settlement_finance' => ['status' => 'missing_evidence'],
                ],
                'next_actions' => [
                    [
                        'action' => 'login_and_rerun_capture',
                        'reason' => 'login_required',
                        'section' => '',
                        'endpoint_id' => '',
                        'required_evidence' => ['logged-in browser profile'],
                    ],
                    [
                        'action' => 'capture_missing_formal_endpoint',
                        'reason' => 'missing_endpoint',
                        'section' => 'business_overview',
                        'endpoint_id' => 'business_realtime',
                        'required_evidence' => ['Request URL', 'Payload', 'Preview / Response'],
                    ],
                ],
            ],
        ]]);

        self::assertTrue($health['available']);
        self::assertSame('ctrip', $health['platform']);
        self::assertSame(16, $health['section_count']);
        self::assertSame(69, $health['endpoint_count']);
        self::assertSame(110, $health['field_count']);
        self::assertSame(['business_overview', 'traffic_report'], $health['default_sections']);
        self::assertSame(['homepage', 'biztravel_bpi'], $health['wide_sections']);
        self::assertSame(14, $health['interaction_plan_section_count']);
        self::assertSame(64, $health['interaction_plan_step_count']);
        self::assertSame('fail', $health['capture_gate_status']);
        self::assertSame(['auth_session', 'field_coverage'], $health['failed_check_ids']);
        self::assertSame('login_required', $health['auth_status']);
        self::assertSame(0, $health['response_count']);
        self::assertSame(0, $health['standard_row_count']);
        self::assertNull($health['coverage_rate']);
        self::assertFalse($health['is_live_capture_ready']);
        self::assertSame('blocked_auth', $health['capture_gap_status']);
        self::assertSame(['auth_session', 'response_count'], $health['capture_gap_blockers']);
        self::assertSame(2, $health['capture_gap_missing_formal_endpoint_count']);
        self::assertSame(2, $health['capture_gap_missing_field_section_count']);
        self::assertSame(5, $health['capture_gap_missing_field_count']);
        self::assertSame(1, $health['capture_gap_p3_candidate_section_count']);
        self::assertSame(2, $health['capture_gap_p3_evidence_section_count']);
        self::assertSame('login_and_rerun_capture', $health['capture_gap_next_actions'][0]['action']);
        self::assertSame('capture_missing_formal_endpoint', $health['capture_gap_next_actions'][1]['action']);
        self::assertSame(['Request URL', 'Payload', 'Preview / Response'], $health['capture_gap_next_actions'][1]['required_evidence']);
        self::assertStringContainsString('未通过', $health['message']);
    }

    public function testCtripCaptureCatalogHealthExposesMissingCatalogExplicitly(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'buildCtripCaptureCatalogHealth', [[], []]);

        self::assertFalse($health['available']);
        self::assertSame('ctrip', $health['platform']);
        self::assertSame('missing', $health['capture_gate_status']);
        self::assertSame('missing', $health['capture_gap_status']);
        self::assertSame([], $health['capture_gap_next_actions']);
        self::assertFalse($health['is_live_capture_ready']);
        self::assertStringContainsString('未生成', $health['message']);
    }

    public function testCtripCaptureCatalogHealthReadsProjectReports(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'readCtripCaptureCatalogHealth');

        self::assertTrue($health['available']);
        self::assertSame('ctrip', $health['platform']);
        self::assertGreaterThanOrEqual(16, $health['section_count']);
        self::assertGreaterThanOrEqual(69, $health['endpoint_count']);
        self::assertGreaterThanOrEqual(110, $health['field_count']);
        self::assertSame('fail', $health['capture_gate_status']);
        self::assertSame('login_required', $health['auth_status']);
        self::assertSame('blocked_auth', $health['capture_gap_status']);
        self::assertSame('login_and_rerun_capture', $health['capture_gap_next_actions'][0]['action']);
        self::assertFalse($health['is_live_capture_ready']);
    }

    public function testCtripLatestBatchScopeUsesLatestFetchTimeWhenHotelIsSelected(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-05-18 16:54:51'],
            '7',
            ['system_hotel_id' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['where', 'update_time', '2026-05-18 16:54:51'],
        ], $query->calls);
    }

    public function testCtripLatestBatchScopeKeepsLatestSystemHotelAndFetchTimeWhenHotelIsEmpty(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-05-18 16:54:51'],
            '',
            ['system_hotel_id' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['where', 'system_hotel_id', 7],
            ['where', 'update_time', '2026-05-18 16:54:51'],
        ], $query->calls);
    }

    /**
     * 覆盖 normalizeOnlineDataDate/extractCtripCommentScore：
     * 验证日期输入兼容、非法值兜底、点评分数字段别名。
     */
    public function testOnlineDataQualityFlagsMissingAndAbnormalMetrics(): void
    {
        $controller = $this->controller();

        $quality = $this->invokeNonPublic($controller, 'buildOnlineDataQuality', [[
            'id' => 11,
            'source' => 'ctrip',
            'data_type' => 'business',
            'hotel_id' => 'ota-11',
            'hotel_name' => 'Hotel A',
            'data_date' => '2026-05-17',
            'amount' => 800,
            'quantity' => 0,
            'book_order_num' => 2,
            'comment_score' => 6.2,
            'raw_data' => json_encode([
                'hotelId' => 'ota-11',
                'hotelName' => 'Hotel A',
                'amount' => 800,
                'bookOrderNum' => 2,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('warning', $quality['status']);
        self::assertContains('quantity', array_column($quality['missing_metrics'], 'key'));
        self::assertContains('adr_denominator_zero', array_column($quality['abnormal_metrics'], 'code'));
        self::assertContains('comment_score_range', array_column($quality['abnormal_metrics'], 'code'));
        self::assertStringContainsString('缺失', $quality['summary']);
    }

    public function testOnlineDataQualityAcceptsCtripOrderNumAlias(): void
    {
        $controller = $this->controller();

        $quality = $this->invokeNonPublic($controller, 'buildOnlineDataQuality', [[
            'id' => 12,
            'source' => 'ctrip',
            'data_type' => 'business',
            'hotel_id' => 'ota-12',
            'hotel_name' => 'Hotel Alias',
            'data_date' => '2026-05-17',
            'amount' => 900,
            'quantity' => 3,
            'comment_score' => 4.7,
            'raw_data' => json_encode([
                'hotelId' => 'ota-12',
                'hotelName' => 'Hotel Alias',
                'amount' => 900,
                'quantity' => 3,
                'orderNum' => 2,
                'commentScore' => 4.7,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertNotContains('book_order_num', array_column($quality['missing_metrics'], 'key'));
    }

    public function testOnlineDataQualitySummaryCountsIssueRows(): void
    {
        $controller = $this->controller();

        $rows = [
            [
                'id' => 1,
                'source' => 'ctrip',
                'data_type' => 'business',
                'hotel_id' => 'ota-1',
                'hotel_name' => 'Hotel A',
                'data_date' => '2026-05-17',
                'amount' => 1000,
                'quantity' => 5,
                'book_order_num' => 3,
                'comment_score' => 4.8,
                'raw_data' => json_encode([
                    'hotelId' => 'ota-1',
                    'hotelName' => 'Hotel A',
                    'amount' => 1000,
                    'quantity' => 5,
                    'bookOrderNum' => 3,
                    'commentScore' => 4.8,
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 2,
                'source' => 'ctrip',
                'data_type' => 'business',
                'hotel_id' => '',
                'hotel_name' => 'Hotel B',
                'data_date' => '2026-05-17',
                'amount' => 500,
                'quantity' => 0,
                'book_order_num' => 1,
                'comment_score' => 4.6,
                'raw_data' => json_encode(['hotelName' => 'Hotel B', 'amount' => 500], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $summary = $this->invokeNonPublic($controller, 'buildOnlineDataQualitySummary', [$rows]);

        self::assertSame(2, $summary['checked_records']);
        self::assertSame(1, $summary['issue_records']);
        self::assertSame(1, $summary['ok_records']);
        self::assertGreaterThanOrEqual(2, $summary['missing_count']);
        self::assertGreaterThanOrEqual(1, $summary['abnormal_count']);
        self::assertSame('warning', $summary['status']);
        self::assertNotEmpty($summary['top_prompts']);
    }

    public function testOnlineDataDateAndCommentScoreNormalization(): void
    {
        $controller = $this->controller();

        self::assertSame('', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', [null]));
        self::assertSame('2026-05-18', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', ['20260518']));
        self::assertSame('2026-05-02', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', ['2026/5/2']));
        self::assertSame('2026-05-03', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', [strtotime('2026-05-03 00:00:00')]));
        self::assertSame('', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', ['not-a-date']));

        self::assertSame(4.8, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['rating' => '4.8']]));
        self::assertSame(4.0, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['score' => 40]]));
        self::assertSame(5.0, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['commentScore' => 100]]));
        self::assertSame(0.0, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['rating' => 'bad']]));
    }

    public function testCtripBrowserCapturePayloadExtractsGetCommentListRows(): void
    {
        $controller = $this->controller();

        $comments = $this->invokeNonPublic($controller, 'extractCtripCapturedComments', [[
            'reviews' => [[
                'review_id' => 'local-1',
                'content' => '本地浏览器归一化点评',
            ]],
            'responses' => [
                [
                    'url' => 'https://ebooking.ctrip.com/api/getCommentList',
                    'section' => 'reviews',
                    'data' => [
                        'data' => [
                            'commentList' => [[
                                'commentId' => 'api-1',
                                'score' => 40,
                                'commentContent' => '接口点评',
                            ]],
                        ],
                    ],
                ],
                [
                    'url' => 'https://ebooking.ctrip.com/api/other',
                    'data' => [
                        'data' => [
                            'commentList' => [[
                                'commentId' => 'skip-1',
                                'commentContent' => '非点评接口不应进入',
                            ]],
                        ],
                    ],
                ],
            ],
        ]]);

        self::assertCount(2, $comments);
        self::assertSame('local-1', $comments[0]['review_id']);
        self::assertSame('api-1', $comments[1]['commentId']);
    }

    public function testCtripAdsPayloadMapsToAdvertisingRows(): void
    {
        $controller = $this->controller();

        $ads = $this->invokeNonPublic($controller, 'extractCtripCapturedAds', [[
            'responses' => [[
                'url' => 'https://ebooking.ctrip.com/toolcenter/api/pyramidad/report',
                'section' => 'ads',
                'data' => [
                    'data' => [
                        'list' => [[
                            'campaignId' => 'ad-1',
                            'campaignName' => '金字塔计划',
                            'impressions' => 1000,
                            'clicks' => 50,
                            'orderNum' => 3,
                            'consume' => 188.5,
                            'statDate' => '2026-05-18',
                        ]],
                    ],
                ],
            ]],
        ]]);
        $rows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [$ads, [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'request_start_date' => '2026-05-12',
            'request_end_date' => '2026-05-18',
        ], 58]);

        self::assertCount(1, $rows);
        self::assertSame('advertising', $rows[0]['data_type']);
        self::assertSame('ctrip', $rows[0]['source']);
        self::assertSame('Ctrip', $rows[0]['platform']);
        self::assertSame(1000, $rows[0]['list_exposure']);
        self::assertSame(50, $rows[0]['detail_exposure']);
        self::assertSame(3, $rows[0]['book_order_num']);
        self::assertSame(188.5, $rows[0]['amount']);
    }

    public function testCtripAdsApiUrlOnlyAllowsPyramidadOrPromotion(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/toolcenter/api/pyramidad/report',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/api/promotion/report',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE&v=0.8021101893559687',
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/toolcenter/cpc/pyramid',
        ]));
    }

    public function testCtripAdsLastSevenDaysUsesSettledReportEndDate(): void
    {
        $controller = $this->controller();

        $beforeUpdate = $this->invokeNonPublic($controller, 'buildCtripAdsDateRange', [
            'last_7_days',
            '',
            '',
            strtotime('2026-05-20 02:44:00'),
        ]);
        self::assertSame(['2026-05-12', '2026-05-18'], $beforeUpdate);

        $afterUpdate = $this->invokeNonPublic($controller, 'buildCtripAdsDateRange', [
            'last_7_days',
            '',
            '',
            strtotime('2026-05-20 08:00:00'),
        ]);
        self::assertSame(['2026-05-13', '2026-05-19'], $afterUpdate);
    }

    public function testCtripAdsDirectPayloadAndChineseFieldsMapToMetrics(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'buildCtripAdsDirectPayload', [[
            'pageIndex' => 1,
        ], '2026-05-18', '2026-05-18', 'campaign_report']);

        self::assertSame('2026-05-18', $payload['startDate']);
        self::assertSame('2026-05-18', $payload['endDate']);
        self::assertSame('campaign_report', $payload['apiType']);

        $ads = $this->invokeNonPublic($controller, 'extractCtripCapturedAds', [[
            'responses' => [[
                'url' => 'https://ebooking.ctrip.com/api/promotion/report',
                'section' => 'ads',
                'data' => [
                    'data' => [
                        'rows' => [[
                            '计划名称' => '中文广告计划',
                            '曝光量' => '1,200',
                            '点击量' => '60',
                            '成交数' => '4',
                            '消耗金额' => '¥240.50',
                            '统计日期' => '2026-05-18',
                        ]],
                    ],
                ],
            ]],
        ]]);
        $rows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [$ads, [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'request_start_date' => '2026-05-12',
            'request_end_date' => '2026-05-18',
        ], 58]);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripAdRows', [$rows]);

        self::assertCount(1, $rows);
        self::assertSame(1200, $rows[0]['list_exposure']);
        self::assertSame(60, $rows[0]['detail_exposure']);
        self::assertSame(4, $rows[0]['book_order_num']);
        self::assertSame(240.5, $rows[0]['amount']);
        self::assertSame(1200, $metrics['exposure']);
        self::assertSame(60, $metrics['clicks']);
        self::assertSame(4, $metrics['orders']);
        self::assertSame(240.5, $metrics['cost']);
        self::assertSame(5.0, $metrics['click_rate']);
    }

    public function testCtripCpcCampaignReportRecordsMapToAdMetrics(): void
    {
        $controller = $this->controller();

        $ads = $this->invokeNonPublic($controller, 'extractCtripCapturedAds', [[
            'responses' => [[
                'url' => 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE',
                'data' => [
                    'code' => 0,
                    'message' => 'success',
                    'data' => [
                        'records' => [[
                            'campaignId' => null,
                            'impressions' => 16511,
                            'clicks' => 748,
                            'ctr' => 0.0453,
                            'ctrStr' => '4.53%',
                            'todayCost' => 1714.78,
                            'bonusCost' => 856.09,
                            'cashCost' => 858.69,
                            'bookings' => 19,
                            'nights' => 37,
                            'orderAmount' => 29282,
                            'roas' => 17.08,
                            'effectTime' => '2026-05-12',
                        ]],
                        'totalRecords' => 1,
                    ],
                ],
            ]],
        ]]);
        $rows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [$ads, [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'request_start_date' => '2026-05-12',
            'request_end_date' => '2026-05-18',
        ], 58]);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripAdRows', [$rows]);

        self::assertCount(1, $rows);
        self::assertSame(16511, $rows[0]['list_exposure']);
        self::assertSame(748, $rows[0]['detail_exposure']);
        self::assertSame(19, $rows[0]['book_order_num']);
        self::assertSame(37, $rows[0]['quantity']);
        self::assertSame('2026-05-12', $rows[0]['data_date']);
        self::assertSame(1714.78, $rows[0]['amount']);
        self::assertSame(16511, $metrics['exposure']);
        self::assertSame(748, $metrics['clicks']);
        self::assertSame(19, $metrics['orders']);
        self::assertSame(1714.78, $metrics['cost']);
        self::assertSame(4.53, $metrics['click_rate']);

        $raw = json_decode((string)$rows[0]['raw_data'], true);
        self::assertSame(29282, $raw['orderAmount']);
        self::assertSame(17.08, $raw['roas']);
        self::assertSame('2026-05-12', $raw['_capture_context']['request_start_date']);
        self::assertSame('2026-05-18', $raw['_capture_context']['request_end_date']);
    }

    public function testCtripOverviewRowsPreserveRequestedMetrics(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'collectCtripOverviewRows', [[
            'business' => [[
                'hotelName' => 'Ctrip Hotel',
                '昨日UV' => 23,
                '订单数' => 9,
                '成交收入' => '8,709',
                '成交间夜' => 13,
                '均价' => 669.92,
                '成交率' => '92.86%',
                '竞品UV' => 30,
                '竞品订单数' => 12,
                '竞品收入' => '10,000',
                'PSI' => 81,
                '回复率' => '98.5%',
                '收藏数' => 7,
                '访客排名' => 12,
            ]],
        ], 'ctrip-58', '2026-05-18']);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripOverviewRows', [$rows]);

        self::assertCount(1, $rows);
        self::assertSame('ctrip-58', $rows[0]['hotelId']);
        self::assertSame('2026-05-18', $rows[0]['dataDate']);
        self::assertSame(23, $metrics['yesterday_uv']);
        self::assertSame(9, $metrics['order_count']);
        self::assertSame(8709.0, $metrics['amount']);
        self::assertSame(13, $metrics['room_nights']);
        self::assertSame(669.92, $metrics['avg_price']);
        self::assertSame(92.86, $metrics['conversion_rate']);
        self::assertSame(30, $metrics['competitor_uv']);
        self::assertSame(12, $metrics['competitor_orders']);
        self::assertSame(10000.0, $metrics['competitor_amount']);
        self::assertSame(81.0, $metrics['psi']);
        self::assertSame(98.5, $metrics['reply_rate']);
        self::assertSame(7, $metrics['favorite_count']);
        self::assertSame(12, $metrics['visitor_rank']);
    }

    public function testCtripOverviewRowsMapMarketFlowServiceAndFunnelResponses(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'collectCtripOverviewRows', [[
            'responses' => [
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/sale/fetchMarketOverViewV2',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'amount' => 8709.00,
                            'quantity' => 13,
                            'closeRate' => 92.86,
                            'averagePrice' => 669.92,
                            'bookOrderNum' => 0,
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportFlowCompete',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'masterhotelid' => 134396668,
                            'ordquantity' => 819,
                            'comhtluv' => 15275,
                            'ordamount' => 752689.08,
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportServerQuantity',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'serviceScore' => 4.92,
                            'ctripRatingall' => 5.0,
                            'replyrate5m' => 87.5,
                            'hotelCollect' => 247,
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
                    'data' => [
                        [
                            'date' => '2026-05-18',
                            'listExposure' => 701,
                            'detailExposure' => 151,
                            'flowRate' => 21.54,
                            'orderFillingNum' => 2,
                            'orderSubmitNum' => 0,
                            'hotelId' => 134396668,
                        ],
                        [
                            'date' => '2026-05-18',
                            'listExposure' => 318,
                            'detailExposure' => 67,
                            'flowRate' => 22.12,
                            'orderFillingNum' => 5,
                            'orderSubmitNum' => 2,
                            'hotelId' => -1,
                        ],
                    ],
                ],
            ],
        ], '134396668', '2026-05-18']);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripOverviewRows', [$rows]);

        self::assertCount(1, $rows);
        self::assertSame('134396668', $rows[0]['hotelId']);
        self::assertSame(8709.0, $metrics['amount']);
        self::assertSame(13, $metrics['room_nights']);
        self::assertSame(669.92, $metrics['avg_price']);
        self::assertSame(92.86, $metrics['conversion_rate']);
        self::assertSame(15275, $metrics['competitor_uv']);
        self::assertSame(819, $metrics['competitor_orders']);
        self::assertSame(752689.08, $metrics['competitor_amount']);
        self::assertSame(4.92, $metrics['psi']);
        self::assertSame(5.0, $metrics['hotel_score']);
        self::assertSame(87.5, $metrics['reply_rate']);
        self::assertSame(247, $metrics['favorite_count']);
        self::assertSame(701, $metrics['self_list_exposure']);
        self::assertSame(151, $metrics['self_detail_exposure']);
        self::assertSame(2, $metrics['self_order_filling_num']);
        self::assertSame(0, $metrics['self_order_submit_num']);
        self::assertSame(21.54, $metrics['self_flow_rate']);
        self::assertSame(1.32, $metrics['self_order_fill_rate']);
        self::assertSame(0.0, $metrics['self_deal_rate']);
        self::assertSame(318, $metrics['competitor_list_exposure']);
        self::assertSame(67, $metrics['competitor_detail_exposure']);
        self::assertSame(5, $metrics['competitor_order_filling_num']);
        self::assertSame(2, $metrics['competitor_order_submit_num']);
        self::assertSame(21.07, $metrics['competitor_flow_rate']);
        self::assertSame(7.46, $metrics['competitor_order_fill_rate']);
        self::assertSame(40.0, $metrics['competitor_deal_rate']);
    }

    public function testCtripOverviewRowsMapRankingHotListsWeeklyAndTrafficReports(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'collectCtripOverviewRows', [[
            'responses' => [
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            ['hotelId' => 664563, 'hotelName' => '竞品A', 'amount' => 6, 'quantity' => 2, 'bookOrderNum' => 3, 'commentScore' => 14, 'totalDetailNum' => 8, 'convertionRate' => 1],
                            ['hotelId' => 134396668, 'hotelName' => '我的酒店', 'amount' => 8, 'quantity' => 8, 'bookOrderNum' => 6, 'commentScore' => 1, 'totalDetailNum' => 7, 'convertionRate' => 11],
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotWordsV1',
                    'data' => ['rcode' => 0, 'data' => ['敦煌夜市', '5钻/星|豪华']],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotHotelsV1',
                    'data' => ['rcode' => 0, 'data' => ['敦煌中洲国际酒店(敦煌夜市店)', '敦煌福朋喜来登酒店']],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getFlowHotelsV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'flowHotelItemVos' => [
                                ['hotelName' => '敦煌山庄', 'proportion' => '31.08%', 'orderPro' => '2.51%', 'masterHotelId' => 439474],
                            ],
                            'lossOrderVo' => ['ordernum' => 535, 'ordquantity' => 1035.0, 'ordamount' => 784911.01],
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotRoomsV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'hotRooms' => [
                                ['roomName' => '景观大床房', 'roomShortName' => '景观大床房', 'saleRoomNights' => 27, 'salePercent' => '42.19%'],
                            ],
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getUserBehaviorV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'lastWeekCommentScore' => 5.0,
                            'lastWeekGoodAdd' => 0,
                            'lastWeekBadAdd' => 0,
                            'lastWeekPriceScore' => 0.28,
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getTrafficReportV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'myHotel' => ['totalListExposure' => 11192, 'listTransforDetailRate' => '17%', 'totalDetailExposure' => 1893, 'detailTransforOrderFillRate' => '2%', 'orderFillingNum' => 38, 'orderFillTransforOrderSubmitRate' => '53%', 'orderSubmitNum' => 20],
                            'competeHotelAvg' => ['totalListExposure' => 6040, 'listTransforDetailRate' => '23%', 'totalDetailExposure' => 1390, 'detailTransforOrderFillRate' => '5%', 'orderFillingNum' => 71, 'orderFillTransforOrderSubmitRate' => '59%', 'orderSubmitNum' => 42],
                            'topCompeteHotel' => ['totalListExposure' => 10440, 'listTransforDetailRate' => '19%', 'totalDetailExposure' => 2014, 'detailTransforOrderFillRate' => '8%', 'orderFillingNum' => 168, 'orderFillTransforOrderSubmitRate' => '76%', 'orderSubmitNum' => 128],
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getLastWeekReportV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'lastWeekCheckoutRoomNights' => 44,
                            'lastWeekCheckoutSales' => 31132.82,
                            'lastWeekCheckoutRoomPrice' => 707.56,
                            'lastWeekBookQuantity' => 98,
                            'lastWeekBookRoomNights' => 144,
                            'lastWeekBookSales' => 103008.94,
                        ],
                    ],
                ],
            ],
        ], '134396668', '2026-05-18']);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripOverviewRows', [$rows]);
        $rawRows = $rows[0]['_overview_rows'] ?? [];

        self::assertCount(1, $rows);
        self::assertSame('134396668', $rows[0]['hotelId']);
        self::assertSame(2, $metrics['compete_hotel_count']);
        self::assertSame(8, $metrics['amount_rank']);
        self::assertSame(8, $metrics['quantity_rank']);
        self::assertSame(6, $metrics['book_order_num_rank']);
        self::assertSame(1, $metrics['comment_score_rank']);
        self::assertSame(7, $metrics['visitor_rank']);
        self::assertSame(11, $metrics['conversion_rank']);
        self::assertSame('敦煌夜市', $metrics['top_hot_word']);
        self::assertSame('敦煌中洲国际酒店(敦煌夜市店)', $metrics['top_hot_hotel']);
        self::assertSame(535, $metrics['flow_lost_order_num']);
        self::assertSame(1035, $metrics['flow_lost_room_nights']);
        self::assertSame(784911.01, $metrics['flow_lost_amount']);
        self::assertSame('敦煌山庄', $metrics['top_flow_hotel']);
        self::assertSame(31.08, $metrics['top_flow_hotel_browse_rate']);
        self::assertSame('景观大床房', $metrics['top_hot_room']);
        self::assertSame(27, $metrics['top_hot_room_nights']);
        self::assertSame(42.19, $metrics['top_hot_room_sale_percent']);
        self::assertSame(5.0, $metrics['last_week_comment_score']);
        self::assertSame(0.28, $metrics['last_week_price_score']);
        self::assertSame(44, $metrics['last_week_checkout_room_nights']);
        self::assertSame(31132.82, $metrics['last_week_checkout_sales']);
        self::assertSame(98, $metrics['last_week_book_quantity']);
        self::assertSame(103008.94, $metrics['last_week_book_sales']);
        self::assertSame(11192, $metrics['weekly_self_list_exposure']);
        self::assertSame(17.0, $metrics['weekly_self_flow_rate']);
        self::assertSame(6040, $metrics['weekly_competitor_list_exposure']);
        self::assertSame(10440, $metrics['top_competitor_list_exposure']);
        self::assertSame(76.0, $metrics['top_competitor_deal_rate']);
        self::assertCount(8, $rawRows);
    }

    public function testCtripOverviewDirectApiValidationAndPayloadDefaults(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/api/fetchMarketOverViewV2',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/api/fetchCurrentHotelSeqInfoV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/queryScanFlowDetailsV2',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/queryHomePageRealTimeData',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/getTrafficData',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotWordsV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getTrafficReportV1',
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true',
        ]));

        $urls = $this->invokeNonPublic($controller, 'normalizeCtripOverviewRequestUrls', [
            " https://ebooking.ctrip.com/api/getDayReportRealTimeDate\nhttps://ebooking.ctrip.com/api/fetchCapacityOverViewV4 ",
        ]);
        self::assertCount(2, $urls);

        $payload = $this->invokeNonPublic($controller, 'buildCtripOverviewRequestPayload', [[
            'pageIndex' => 1,
        ], 'ctrip-58', '2026-05-18']);
        self::assertSame('2026-05-18', $payload['dataDate']);
        self::assertSame('2026-05-18', $payload['startDate']);
        self::assertSame('2026-05-18', $payload['endDate']);
        self::assertSame('ctrip-58', $payload['hotelId']);
        self::assertSame('ctrip-58', $payload['nodeId']);

        $inferred = $this->invokeNonPublic($controller, 'inferCtripOverviewHotelIdFromResponses', [[
            ['data' => ['data' => ['masterhotelid' => 134396668]]],
        ], '7']);
        self::assertSame('134396668', $inferred);

        $fallback = $this->invokeNonPublic($controller, 'inferCtripOverviewHotelIdFromResponses', [[
            ['data' => ['data' => ['敦煌夜市', '5钻/星|豪华']]],
        ], '7']);
        self::assertSame('7', $fallback);
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
                'conversion_rate' => '12.5%',
                'search_rank' => 3,
                'keyword_rank_data' => ['hotel' => 2],
            ]],
            'ads' => [[
                'date' => '2026-05-18',
                'exposure_count' => 500,
                'click_count' => 50,
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

        self::assertCount(3, $rows);
        self::assertNotContains('review', array_column($rows, 'data_type'));

        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame(1000, $rows[0]['list_exposure']);
        self::assertSame(180, $rows[0]['detail_exposure']);
        self::assertSame(12.5, $rows[0]['flow_rate']);
        self::assertSame(120, $rows[0]['order_filling_num']);
        self::assertStringContainsString('"unique_visitors":80', $rows[0]['raw_data']);

        self::assertSame('advertising', $rows[1]['data_type']);
        self::assertSame(500, $rows[1]['list_exposure']);
        self::assertSame(50, $rows[1]['detail_exposure']);
        self::assertSame(10.0, $rows[1]['flow_rate']);

        self::assertSame('order', $rows[2]['data_type']);
        self::assertSame(688.0, $rows[2]['amount']);
        self::assertSame(6, $rows[2]['quantity']);
        self::assertSame(1, $rows[2]['book_order_num']);
        self::assertStringNotContainsString('order-1', (string)$rows[2]['dimension']);
        self::assertMatchesRegularExpression('/^order:confirmed:[a-f0-9]{64}$/', (string)$rows[2]['dimension']);
        self::assertStringNotContainsString('order-1', (string)$rows[2]['raw_data']);
    }

    public function testOnlineDailyDataValidationFieldsMarkAbnormalRows(): void
    {
        $controller = $this->controller();

        $normal = $this->invokeNonPublic($controller, 'buildOnlineDailyDataValidationFields', [[
            'source' => 'ctrip',
            'hotel_id' => '1001',
            'data_date' => '2026-05-17',
            'amount' => 1000,
            'quantity' => 5,
        ]]);
        self::assertSame('normal', $normal['validation_status']);
        self::assertSame([], json_decode($normal['validation_flags'], true));

        $abnormal = $this->invokeNonPublic($controller, 'buildOnlineDailyDataValidationFields', [[
            'source' => 'ctrip',
            'hotel_id' => '',
            'data_date' => '2026-05-17',
            'amount' => 1000,
            'quantity' => -1,
        ]]);
        self::assertSame('abnormal', $abnormal['validation_status']);
        $flags = json_decode($abnormal['validation_flags'], true);
        self::assertContains('hotel_id', array_column($flags, 'field'));
        self::assertContains('quantity', array_column($flags, 'field'));
    }

    public function testCtripProfilePrefersExistingSystemHotelProfileOverNodeId(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $profileId = 'phpunit_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId;

        if (!is_dir($profileDir)) {
            self::assertTrue(mkdir($profileDir, 0775, true));
        }

        try {
            $resolved = $this->invokeNonPublic($controller, 'ctripProfileStoreIdFromConfig', [[
                'node_id' => 'node-should-not-win',
                'system_hotel_id' => $profileId,
            ], 0]);

            self::assertSame($profileId, $resolved);
        } finally {
            if (is_dir($profileDir)) {
                @rmdir($profileDir);
            }
        }
    }

    public function testAutoFetchModeNormalizationSupportsExplicitStrategies(): void
    {
        $controller = $this->controller();

        self::assertSame('hybrid_auto', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['']));
        self::assertSame('hybrid_auto', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['hybrid']));
        self::assertSame('cookie_config', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['api']));
        self::assertSame('cookie_config', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['cookie-config']));
        self::assertSame('profile_browser', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['browser_profile']));
    }

    public function testAutoFetchCostStrategyOnlyRunsProfileWhenExplicitlySelected(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['cookie_config', 0]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['profile_browser', 10]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['hybrid_auto', 3]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['hybrid_auto', 0]));
    }

    public function testAutoFetchResultMetaKeepsFailureActionExplicit(): void
    {
        $controller = $this->controller();

        $cookieResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'day_report_api',
            'saved_count' => 0,
            'success' => false,
            'skipped' => true,
            'message' => '未配置携程 Cookie',
        ], 'cookie_config']);
        self::assertSame('cookie_config', $cookieResult['strategy']);
        self::assertSame('needs_cookie', $cookieResult['status_code']);
        self::assertSame('更新 Cookie 或重新登录 OTA 后台', $cookieResult['next_action']);

        $profileResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'browser_profile',
            'saved_count' => 0,
            'success' => false,
            'skipped' => true,
            'message' => '未发现本地美团浏览器 Profile',
        ], 'profile_browser']);
        self::assertSame('needs_profile', $profileResult['status_code']);
        self::assertSame('建立或重新登录浏览器 Profile', $profileResult['next_action']);

        $costSkippedResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'browser_profile',
            'saved_count' => 0,
            'success' => false,
            'skipped' => true,
            'message' => '当前策略未启动 Profile',
        ], 'profile_browser']);
        self::assertSame('skipped', $costSkippedResult['status_code']);
        self::assertSame('', $costSkippedResult['next_action']);

        $meituanMissingResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'ranking_api',
            'saved_count' => 0,
            'success' => false,
            'skipped' => true,
            'message' => '缺少美团 Partner ID / POI ID / Cookies',
        ], 'cookie_config']);
        self::assertSame('needs_config', $meituanMissingResult['status_code']);
        self::assertSame('补齐美团 Partner ID / POI ID / Cookies', $meituanMissingResult['next_action']);
    }

    public function testMeituanAutoFetchConfigStatusReportsMissingFields(): void
    {
        $controller = $this->controller();

        $missing = $this->invokeNonPublic($controller, 'meituanAutoFetchConfigStatus', [[
            'partner_id' => '',
            'poi_id' => 'poi-7',
            'cookies' => '',
        ]]);

        self::assertFalse($missing['api_configured']);
        self::assertSame(['Partner ID', 'Cookies'], $missing['missing_fields']);
        self::assertSame('Partner ID / Cookies', $missing['missing_text']);

        $complete = $this->invokeNonPublic($controller, 'meituanAutoFetchConfigStatus', [[
            'partnerId' => 'partner-7',
            'poiId' => 'poi-7',
            'cookie' => 'meituan-cookie',
        ]]);

        self::assertTrue($complete['api_configured']);
        self::assertSame([], $complete['missing_fields']);
    }

    public function testCtripApprovedMappingsPathResolverAcceptsProjectJsonAliases(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $mappingDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'test_ctrip_mapping';
        if (!is_dir($mappingDir)) {
            mkdir($mappingDir, 0775, true);
        }
        $mappingPath = $mappingDir . DIRECTORY_SEPARATOR . 'approved_mapping_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($mappingPath, json_encode(['mappings' => []], JSON_UNESCAPED_UNICODE));

        try {
            $resolved = $this->invokeNonPublic($controller, 'resolveCtripApprovedMappingsPath', [[
                'approved_mapping_path' => 'runtime/test_ctrip_mapping/' . basename($mappingPath),
            ], $projectRoot]);

            self::assertTrue($resolved['configured']);
            self::assertSame(realpath($mappingPath), $resolved['path']);
            self::assertSame('', $resolved['error']);

            $camelCase = $this->invokeNonPublic($controller, 'resolveCtripApprovedMappingsPath', [[
                'p3MappingsPath' => 'runtime/test_ctrip_mapping/' . basename($mappingPath),
            ], $projectRoot]);
            self::assertSame(realpath($mappingPath), $camelCase['path']);
        } finally {
            if (is_file($mappingPath)) {
                unlink($mappingPath);
            }
        }
    }

    public function testCtripApprovedMappingsPathResolverRejectsUnsafeOrInvalidFiles(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $mappingDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'test_ctrip_mapping';
        if (!is_dir($mappingDir)) {
            mkdir($mappingDir, 0775, true);
        }
        $txtPath = $mappingDir . DIRECTORY_SEPARATOR . 'approved_mapping_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($txtPath, 'not json');

        try {
            $nonJson = $this->invokeNonPublic($controller, 'resolveCtripApprovedMappingsPath', [[
                'approved_mappings_path' => 'runtime/test_ctrip_mapping/' . basename($txtPath),
            ], $projectRoot]);
            self::assertTrue($nonJson['configured']);
            self::assertSame('', $nonJson['path']);
            self::assertStringContainsString('JSON', $nonJson['error']);

            $outside = $this->invokeNonPublic($controller, 'resolveCtripApprovedMappingsPath', [[
                'approved_mappings_path' => 'C:\\Windows\\win.ini',
            ], $projectRoot]);
            self::assertTrue($outside['configured']);
            self::assertSame('', $outside['path']);
            self::assertStringContainsString('项目目录', $outside['error']);
        } finally {
            if (is_file($txtPath)) {
                unlink($txtPath);
            }
        }
    }

    public function testCtripApprovedMappingsArgBuilderAppendsResolvedFile(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $mappingDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'test_ctrip_mapping';
        if (!is_dir($mappingDir)) {
            mkdir($mappingDir, 0775, true);
        }
        $mappingPath = $mappingDir . DIRECTORY_SEPARATOR . 'approved_mapping_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($mappingPath, json_encode(['mappings' => []], JSON_UNESCAPED_UNICODE));

        try {
            $result = $this->invokeNonPublic($controller, 'appendCtripApprovedMappingsArg', [[
                'node',
                'scripts/ctrip_browser_capture.mjs',
            ], [
                'approved_mappings_path' => 'runtime/test_ctrip_mapping/' . basename($mappingPath),
            ], $projectRoot]);

            self::assertSame('', $result['error']);
            self::assertSame('--approved-mappings=' . realpath($mappingPath), end($result['args']));
            self::assertSame(realpath($mappingPath), $result['approved_mappings']['path']);
        } finally {
            if (is_file($mappingPath)) {
                unlink($mappingPath);
            }
        }
    }

    public function testCtripProfileCaptureConfigOptionsNormalizeSectionsAndMappingAliases(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[
            'captureSections' => ['business', 'traffic', 'quality_psi', '../bad', 'BIZTRAVEL_BPI'],
            'approvedMappingPath' => ' docs/ctrip_approved_mapping.example.json ',
        ], []]);

        self::assertSame('business,traffic,quality_psi,biztravel_bpi', $options['capture_sections']);
        self::assertSame('business,traffic,quality_psi,biztravel_bpi', $options['profile_sections']);
        self::assertSame('docs/ctrip_approved_mapping.example.json', $options['approved_mappings_path']);
    }

    public function testCtripProfileCaptureConfigOptionsPreserveOriginalWhenKeysAreAbsent(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[], [
            'capture_sections' => 'business,traffic,quality_psi',
            'approved_mappings_path' => 'docs/approved.json',
        ]]);

        self::assertSame('business,traffic,quality_psi', $options['capture_sections']);
        self::assertSame('business,traffic,quality_psi', $options['profile_sections']);
        self::assertSame('docs/approved.json', $options['approved_mappings_path']);
    }

    public function testCtripProfileCaptureConfigOptionsDefaultToCorePreset(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[], []]);

        self::assertSame('core', $options['capture_sections']);
        self::assertSame('core', $options['profile_sections']);
    }

    public function testCtripProfileCaptureGateArgsDefaultToFieldCoverageThreshold(): void
    {
        $controller = $this->controller();

        $defaultArgs = $this->invokeNonPublic($controller, 'appendCtripCaptureGateArgs', [['node'], []]);

        self::assertContains('--min-field-coverage-rate=80', $defaultArgs);
        self::assertNotContains('--max-missing-fields=0', $defaultArgs);

        $customArgs = $this->invokeNonPublic($controller, 'appendCtripCaptureGateArgs', [['node'], [
            'minFieldCoverageRate' => '65.5',
            'maxMissingFields' => 4,
            'requireFieldCoverage' => true,
        ]]);

        self::assertContains('--min-field-coverage-rate=65.5', $customArgs);
        self::assertContains('--max-missing-fields=4', $customArgs);
        self::assertContains('--require-field-coverage', $customArgs);
    }

    public function testCtripLoginPreparationModeSkipsCaptureGateImport(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripLoginOnlyRequest', [[
            'login_only' => true,
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripLoginOnlyRequest', [[
            'authOnly' => '1',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripLoginOnlyRequest', [[
            'login_only' => false,
        ]]));

        $args = $this->invokeNonPublic($controller, 'appendCtripLoginOnlyArg', [['node'], [
            'prepare_profile' => 'true',
        ]]);
        self::assertContains('--login-only=true', $args);

        $payload = $this->invokeNonPublic($controller, 'buildCtripLoginOnlyResponsePayload', [[
            'mode' => 'login_only',
            'profile_id' => '63',
            'auth_status' => ['status' => 'logged_in', 'message' => 'Ctrip profile is logged in.'],
            'capture_gate' => ['status' => 'skipped', 'reason' => 'login_only'],
            'pages' => [['name' => 'auth', 'ok' => true]],
        ], 'runtime/ctrip_capture/login_only.json', 'stdout text']);

        self::assertSame('login_only', $payload['mode']);
        self::assertSame('logged_in', $payload['auth_status']['status']);
        self::assertSame('skipped', $payload['capture_gate']['status']);
        self::assertSame(0, $payload['saved_count']);
        self::assertSame(0, $payload['row_count']);
        self::assertSame('runtime/ctrip_capture/login_only.json', $payload['output']);
    }

    public function testCtripCaptureDiagnosisSummaryGroupsCapturedMetricsForDiagnosis(): void
    {
        $controller = $this->controller();

        $summary = $this->invokeNonPublic($controller, 'buildCtripCaptureDiagnosisSummary', [[
            'catalog_facts' => [
                ['metric_key' => 'order_count'],
                ['metric_key' => 'list_exposure'],
                ['metric_key' => 'five_min_reply_rate'],
                ['metric_key' => 'user_age'],
            ],
            'standard_rows' => [
                [
                    'data_type' => 'business',
                    'capture_section' => 'business_overview',
                    'metric_key' => 'avg_price|tensity',
                    'dimension' => 'catalog:business_overview:business_realtime:order_amount:root',
                    'raw_data' => [
                        'metrics' => [
                            'room_nights' => 3,
                            'competitor_average' => 5,
                        ],
                    ],
                ],
            ],
        ]]);

        self::assertSame('ready', $summary['status']);
        self::assertContains('收益销售', $summary['available_groups']);
        self::assertContains('流量转化', $summary['available_groups']);
        self::assertContains('服务质量/IM', $summary['available_groups']);
        self::assertContains('辅助事实', $summary['available_groups']);
        self::assertContains('商旅BPI', $summary['missing_groups']);

        $revenue = current(array_filter($summary['groups'], static fn(array $group): bool => $group['name'] === '收益销售'));
        self::assertIsArray($revenue);
        self::assertSame('available', $revenue['status']);
        self::assertContains('order_count', $revenue['captured_metric_keys']);
        self::assertContains('order_amount', $revenue['captured_metric_keys']);
        self::assertContains('room_nights', $revenue['captured_metric_keys']);
        self::assertContains('avg_price', $revenue['captured_metric_keys']);
        self::assertContains('tensity', $revenue['captured_metric_keys']);

        $labels = array_column($summary['captured_metrics'], 'label', 'key');
        self::assertSame('预订订单数', $labels['order_count']);
        self::assertSame('5分钟回复率', $labels['five_min_reply_rate']);
    }

    public function testCtripEndpointEvidenceBundleBuildsFromDevtoolsFieldsAndRedactsSecrets(): void
    {
        $controller = $this->controller();

        $bundle = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceBundleFromRequest', [[
            'request_url' => 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch?_fxpcqlniredt=abc',
            'method' => 'post',
            'headers_json' => json_encode([
                'Cookie' => 'SESSION=secret-cookie',
                'Authorization' => 'Bearer secret-token',
                'Content-Type' => 'application/json',
            ], JSON_UNESCAPED_UNICODE),
            'payload_json' => json_encode([
                'nodeId' => 'ctrip-1001',
                'startDate' => '2026-05-31',
                'endDate' => '2026-05-31',
            ], JSON_UNESCAPED_UNICODE),
            'response_json' => json_encode([
                'data' => [
                    'orderList' => [[
                        'orderId' => 'CTRIP-ORDER-001',
                        'guestName' => 'Alice Zhang',
                        'guestPhone' => '13812345678',
                        'orderAmount' => '588.00',
                    ]],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'page_context_json' => json_encode(['page' => '订单管理', 'tab' => '订单明细'], JSON_UNESCAPED_UNICODE),
            'params_json' => json_encode(['hotel_id' => 'ctrip-1001', 'data_date' => '2026-05-31'], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch?_fxpcqlniredt=abc', $bundle['request_url']);
        self::assertSame('POST', $bundle['method']);
        self::assertSame('ctrip-1001', $bundle['payload']['nodeId']);
        self::assertSame('588.00', $bundle['response']['data']['orderList'][0]['orderAmount']);
        self::assertSame('[REDACTED]', $bundle['headers']['Cookie']);
        self::assertSame('[REDACTED]', $bundle['headers']['Authorization']);

        $encoded = json_encode($bundle, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('secret-cookie', $encoded);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('CTRIP-ORDER-001', $encoded);
        self::assertStringNotContainsString('Alice Zhang', $encoded);
        self::assertStringNotContainsString('13812345678', $encoded);
    }

    public function testCtripEndpointEvidenceBundleRejectsNonCtripUrl(): void
    {
        $controller = $this->controller();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('携程接口证据只允许');

        $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceBundleFromRequest', [[
            'request_url' => 'https://evil.test/restapi/orderDetailSearch',
            'payload_json' => '{"hotelId":"ctrip-1001"}',
            'response_json' => '{"data":{}}',
        ]]);
    }

    public function testCtripEndpointEvidenceValidationPayloadExposesCatalogPreviewRows(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceValidationPayload', [[
            'evidence_status' => 'complete_redacted',
            'catalog_ready' => true,
            'safe_to_catalog' => true,
            'candidate_section' => 'homepage',
            'candidate_label' => '首页实时概览',
            'data_type' => 'business',
            'missing_evidence' => [],
            'field_mapping_draft' => ['ready_for_mapping' => true],
            'catalog_preview' => [
                'formal_endpoint' => true,
                'catalog_fact_count' => 6,
                'standard_row_count' => 1,
                'metric_keys' => ['order_amount', 'visitor_count'],
                'standard_rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'amount' => 309.0,
                    'book_order_num' => 1,
                    'raw_data' => [
                        'source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData',
                    ],
                ]],
            ],
        ], [
            'input_path' => 'runtime/ctrip_endpoint_evidence/input.json',
            'output_path' => 'reports/ctrip_endpoint_evidence.json',
            'markdown_path' => 'docs/ctrip_endpoint_evidence.md',
        ], [
            'mappings' => [],
        ], 'docs/ctrip_approved_mapping.candidate.json', '', 'node stdout']);

        self::assertSame('complete_redacted', $payload['evidence_status']);
        self::assertSame(6, $payload['catalog_preview']['catalog_fact_count']);
        self::assertSame(1, $payload['catalog_preview']['standard_row_count']);
        self::assertSame(['order_amount', 'visitor_count'], $payload['catalog_preview']['metric_keys']);
        self::assertSame(309.0, $payload['catalog_preview']['standard_rows'][0]['amount']);
        self::assertSame('https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData', $payload['catalog_preview']['standard_rows'][0]['raw_data']['source_url']);
        self::assertSame('docs/ctrip_approved_mapping.candidate.json', $payload['paths']['candidate_mapping']);
        self::assertSame(['mappings' => []], $payload['candidate_mapping']);
    }

    public function testCtripEndpointEvidenceCatalogPreviewImportPlanDefaultsToPreviewOnly(): void
    {
        $controller = $this->controller();

        $plan = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceCatalogPreviewImportPlan', [[
            'catalog_ready' => true,
            'safe_to_catalog' => true,
            'catalog_preview' => [
                'standard_rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'amount' => 309.0,
                ]],
            ],
        ], [
            'system_hotel_id' => 7,
        ]]);

        self::assertFalse($plan['requested']);
        self::assertTrue($plan['available']);
        self::assertFalse($plan['can_save']);
        self::assertSame(1, $plan['row_count']);
        self::assertSame(0, $plan['saved_count']);
        self::assertSame(7, $plan['system_hotel_id']);
        self::assertSame('2026-05-31', $plan['data_date']);
        self::assertSame([], $plan['rows']);
    }

    public function testCtripEndpointEvidenceCatalogPreviewImportPlanAllowsExplicitSafeImport(): void
    {
        $controller = $this->controller();

        $plan = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceCatalogPreviewImportPlan', [[
            'catalog_ready' => true,
            'safe_to_catalog' => true,
            'catalog_preview' => [
                'standard_rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'capture_section' => 'homepage',
                    'endpoint_id' => 'homepage_realtime',
                    'dimension' => 'catalog:homepage:homepage_realtime:order_amount:root',
                    'amount' => 309.0,
                    'raw_data' => ['metrics' => ['order_amount' => 309.0]],
                ]],
            ],
        ], [
            'save_standard_rows' => true,
            'system_hotel_id' => 7,
            'data_date' => '2026-05-31',
            'ctrip_hotel_id' => 'ctrip-1001',
        ]]);

        self::assertTrue($plan['requested']);
        self::assertTrue($plan['available']);
        self::assertTrue($plan['can_save']);
        self::assertSame(1, $plan['row_count']);
        self::assertSame(0, $plan['saved_count']);
        self::assertSame(7, $plan['system_hotel_id']);
        self::assertSame('2026-05-31', $plan['data_date']);
        self::assertSame('ctrip-1001', $plan['request_hotel_id']);
        self::assertSame(309.0, $plan['rows'][0]['amount']);
    }

    public function testCtripEndpointEvidenceCatalogPreviewImportPlanRejectsUnsafeImport(): void
    {
        $controller = $this->controller();

        $plan = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceCatalogPreviewImportPlan', [[
            'catalog_ready' => false,
            'safe_to_catalog' => false,
            'catalog_preview' => [
                'standard_rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'amount' => 309.0,
                ]],
            ],
        ], [
            'saveStandardRows' => '1',
            'system_hotel_id' => 7,
        ]]);

        self::assertTrue($plan['requested']);
        self::assertTrue($plan['available']);
        self::assertFalse($plan['can_save']);
        self::assertSame(0, $plan['saved_count']);
        self::assertSame([], $plan['rows']);
        self::assertStringContainsString('not catalog ready', $plan['message']);
    }

    public function testCtripStandardRowsKeepNonLegacyCatalogSectionsImportable(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [[
            'standard_rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => '长沙智选假日酒店',
                    'data_date' => '2026-05-31',
                    'data_type' => 'quality',
                    'capture_section' => 'quality_psi',
                    'endpoint_id' => 'psi_overview',
                    'dimension' => 'catalog:quality_psi:psi_overview:psi_score:root',
                    'data_value' => 4.54,
                    'raw_data' => [
                        'source' => 'ctrip_catalog_facts',
                        'metrics' => ['psi_score' => '4.54'],
                    ],
                ],
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => '长沙智选假日酒店',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'capture_section' => 'business_overview',
                    'endpoint_id' => 'business_realtime',
                    'dimension' => 'catalog:business_overview:business_realtime:order_count:root',
                    'book_order_num' => 3,
                    'raw_data' => ['metrics' => ['order_count' => 3]],
                ],
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'capture_section' => 'business_overview',
                    'endpoint_id' => 'business_realtime',
                    'dimension' => 'catalog:business_overview:business_realtime:avg_price:root',
                    'data_value' => 312.5,
                    'raw_data' => ['metrics' => ['avg_price' => 312.5]],
                ],
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-06-06',
                    'data_type' => 'business',
                    'capture_section' => 'market_calendar',
                    'endpoint_id' => 'hot_calendar',
                    'dimension' => 'catalog:market_calendar:hot_calendar:hot_spot_name:0',
                    'raw_data' => [
                        'fact_only' => true,
                        'metric_status' => 'non_numeric_fact',
                        'metrics' => ['hot_spot_name' => 'Concert A'],
                    ],
                ],
            ],
        ], 7, '2026-05-31', 'ctrip-1001']);

        self::assertCount(3, $rows);
        self::assertSame('quality', $rows[0]['data_type']);
        self::assertSame(4.54, $rows[0]['data_value']);
        self::assertSame(7, $rows[0]['system_hotel_id']);
        self::assertStringContainsString('"capture_section":"quality_psi"', $rows[0]['raw_data']);
        self::assertStringContainsString('"psi_score":"4.54"', $rows[0]['raw_data']);
        $avgPriceRow = current(array_filter($rows, static fn(array $row): bool => ($row['dimension'] ?? '') === 'catalog:business_overview:business_realtime:avg_price:root'));
        self::assertIsArray($avgPriceRow);
        self::assertSame(312.5, $avgPriceRow['data_value']);
        self::assertStringContainsString('"avg_price":312.5', $avgPriceRow['raw_data']);
        self::assertFalse((bool)current(array_filter($rows, static fn(array $row): bool => ($row['dimension'] ?? '') === 'catalog:business_overview:business_realtime:order_count:root')));
        $calendarRow = current(array_filter($rows, static fn(array $row): bool => ($row['dimension'] ?? '') === 'catalog:market_calendar:hot_calendar:hot_spot_name:0'));
        self::assertIsArray($calendarRow);
        self::assertSame('market_calendar', json_decode($calendarRow['raw_data'], true)['capture_section']);
        self::assertStringContainsString('"fact_only":true', $calendarRow['raw_data']);
        self::assertSame(0.0, $calendarRow['amount']);
    }

    public function testCtripStandardRowsKeepStableEndpointProvenance(): void
    {
        $controller = $this->controller();
        $payload = [
            'standard_rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-31',
                    'data_type' => 'quality',
                    'capture_section' => 'quality_psi',
                    'endpoint_id' => 'psi_overview',
                    'source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24306/getHotelPsiV2?x-traceID=trace-1',
                    'dimension' => 'catalog:quality_psi:psi_overview:psi_score:root',
                    'data_value' => 4.54,
                    'raw_data' => [
                        'source' => 'ctrip_catalog_facts',
                        'metrics' => ['psi_score' => '4.54'],
                    ],
                ],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$payload, 7, '2026-05-31', 'ctrip-1001']);

        self::assertCount(1, $rows);
        self::assertSame('browser_profile', $rows[0]['ingestion_method']);
        self::assertArrayHasKey('source_trace_id', $rows[0]);
        self::assertMatchesRegularExpression('/^ctrip:[a-f0-9]{64}$/', $rows[0]['source_trace_id']);
        self::assertLessThanOrEqual(80, strlen($rows[0]['source_trace_id']));

        $rawData = json_decode($rows[0]['raw_data'], true);
        self::assertSame('quality_psi', $rawData['capture_section']);
        self::assertSame('psi_overview', $rawData['endpoint_id']);
        self::assertSame('https://ebooking.ctrip.com/restapi/soa2/24306/getHotelPsiV2?x-traceID=trace-1', $rawData['source_url']);

        $sameRows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$payload, 7, '2026-05-31', 'ctrip-1001']);
        self::assertSame($rows[0]['source_trace_id'], $sameRows[0]['source_trace_id']);

        $changedPayload = $payload;
        $changedPayload['standard_rows'][0]['dimension'] = 'catalog:quality_psi:psi_overview:psi_rank:root';
        $changedRows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$changedPayload, 7, '2026-05-31', 'ctrip-1001']);
        self::assertNotSame($rows[0]['source_trace_id'], $changedRows[0]['source_trace_id']);
    }

    public function testCtripCaptureCountsExposeStandardRowsByTypeAndSection(): void
    {
        $controller = $this->controller();

        $counts = $this->invokeNonPublic($controller, 'buildCtripCaptureCounts', [[
            'business' => [['hotelId' => 'ctrip-1001', 'dataDate' => '2026-05-31', 'orderAmount' => 100]],
            'traffic' => [
                ['hotelId' => 'ctrip-1001', 'date' => '2026-05-31', 'listExposure' => 10],
                ['hotelId' => 'ctrip-1001', 'date' => '2026-05-31', 'detailUv' => 2],
            ],
            'catalog_facts' => [['metric_key' => 'psi_score']],
            'responses' => [['url' => 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2']],
            'xhr_urls' => [['url' => 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2']],
            'pages' => [
                [
                    'name' => 'sales_report',
                    'interactions' => [
                        ['text' => '销售数据', 'clicked' => true],
                        ['text' => '房型', 'clicked' => false, 'skipped' => 'not_visible'],
                    ],
                ],
                [
                    'name' => 'traffic_report',
                    'interactions' => [
                        ['text' => '手机APP', 'clicked' => true],
                        ['text' => '电脑网页版', 'clicked' => false, 'error' => 'detached'],
                    ],
                ],
            ],
            'endpoint_candidates' => [
                ['candidate_section' => 'orders_detail', 'candidate_label' => '订单明细'],
                ['candidate_section' => 'price_inventory', 'candidate_label' => '价格房态'],
                ['candidate_section' => 'orders_detail', 'candidate_label' => '订单明细'],
                ['candidate_section' => '', 'candidate_label' => ''],
            ],
            'p3_evidence_drafts' => [
                ['candidate_section' => 'orders_detail', 'evidence_status' => 'complete_redacted', 'catalog_ready' => true],
                ['candidate_section' => 'orders_detail', 'evidence_status' => 'incomplete', 'catalog_ready' => false],
                ['candidate_section' => 'promotion', 'evidence_status' => 'complete_redacted', 'catalog_ready' => true],
                ['candidate_section' => '', 'evidence_status' => '', 'catalog_ready' => false],
            ],
            'standard_rows' => [
                ['data_type' => 'quality', 'capture_section' => 'quality_psi'],
                ['data_type' => 'advertising', 'capture_section' => 'ads_pyramid'],
                ['data_type' => 'business', 'capture_section' => 'market_calendar'],
                ['data_type' => '', 'capture_section' => ''],
            ],
        ]]);

        self::assertSame(1, $counts['business']);
        self::assertSame(2, $counts['traffic']);
        self::assertSame(4, $counts['standard_rows']);
        self::assertSame(1, $counts['standard_by_data_type']['quality']);
        self::assertSame(1, $counts['standard_by_data_type']['advertising']);
        self::assertSame(1, $counts['standard_by_data_type']['business']);
        self::assertSame(1, $counts['standard_by_data_type']['unknown']);
        self::assertSame(1, $counts['standard_by_section']['quality_psi']);
        self::assertSame(1, $counts['standard_by_section']['ads_pyramid']);
        self::assertSame(1, $counts['standard_by_section']['market_calendar']);
        self::assertSame(1, $counts['standard_by_section']['unknown']);
        self::assertSame(2, $counts['pages']);
        self::assertSame(4, $counts['interaction_planned']);
        self::assertSame(2, $counts['interaction_clicked']);
        self::assertSame(1, $counts['interaction_skipped']);
        self::assertSame(1, $counts['interaction_error']);
        self::assertSame(2, $counts['interaction_by_section']['sales_report']['planned']);
        self::assertSame(1, $counts['interaction_by_section']['sales_report']['clicked']);
        self::assertSame(1, $counts['interaction_by_section']['sales_report']['skipped']);
        self::assertSame(1, $counts['interaction_by_section']['traffic_report']['error']);
        self::assertSame(4, $counts['endpoint_candidates']);
        self::assertSame(2, $counts['candidate_by_section']['orders_detail']);
        self::assertSame(1, $counts['candidate_by_section']['price_inventory']);
        self::assertSame(1, $counts['candidate_by_section']['unknown']);
        self::assertSame(4, $counts['p3_evidence_drafts']);
        self::assertSame(2, $counts['p3_evidence_ready']);
        self::assertSame(2, $counts['p3_evidence_by_section']['orders_detail']);
        self::assertSame(1, $counts['p3_evidence_by_section']['promotion']);
        self::assertSame(1, $counts['p3_evidence_by_section']['unknown']);
        self::assertSame(2, $counts['p3_evidence_by_status']['complete_redacted']);
        self::assertSame(1, $counts['p3_evidence_by_status']['incomplete']);
        self::assertSame(1, $counts['p3_evidence_by_status']['unknown']);
    }

    public function testCtripCaptureGateFailureBlocksSuccessfulImport(): void
    {
        $controller = $this->controller();

        $failed = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [[
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['auth_session', 'endpoint_coverage'],
            ],
        ]]);

        self::assertFalse($failed['accepted']);
        self::assertSame('fail', $failed['status']);
        self::assertSame(['auth_session', 'endpoint_coverage'], $failed['failed_check_ids']);

        $missing = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [[]]);
        self::assertFalse($missing['accepted']);
        self::assertSame('missing', $missing['status']);

        $passed = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [[
            'capture_gate' => [
                'status' => 'pass',
                'failed_check_ids' => [],
            ],
        ]]);

        self::assertTrue($passed['accepted']);
        self::assertSame('pass', $passed['status']);
    }
}

final class OnlineDataQuerySpy
{
    /**
     * @var array<int, array<int, mixed>>
     */
    public array $calls = [];

    public function where(string $field, mixed $value): self
    {
        $this->calls[] = ['where', $field, $value];
        return $this;
    }

    public function whereNull(string $field): self
    {
        $this->calls[] = ['whereNull', $field];
        return $this;
    }
}
