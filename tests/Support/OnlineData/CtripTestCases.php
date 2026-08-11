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

trait CtripTestCases
{

    public function testCtripStableConfigInputReusesSavedHotelMetadataOnlyWhenRequestIsBlank(): void
    {
        $controller = $this->controller();
        $stored = [
            'ctrip_hotel_id' => '832085',
            'hotel_room_count' => 37,
            'competitor_room_count' => 200,
        ];

        self::assertSame(
            '832085',
            $this->invokeNonPublic($controller, 'resolveCtripStableConfigInput', [
                ['ctrip_hotel_id' => ''],
                $stored,
                ['ctrip_hotel_id', 'ctripHotelId', 'ota_hotel_id'],
            ])
        );
        self::assertSame(
            37,
            $this->invokeNonPublic($controller, 'resolveCtripStableConfigInput', [
                [],
                $stored,
                ['hotel_room_count', 'hotelRoomCount'],
            ])
        );
        self::assertSame(
            88,
            $this->invokeNonPublic($controller, 'resolveCtripStableConfigInput', [
                ['hotel_room_count' => 88],
                $stored,
                ['hotel_room_count', 'hotelRoomCount'],
            ])
        );
        self::assertSame(
            0,
            $this->invokeNonPublic($controller, 'resolveCtripStableConfigInput', [
                ['hotel_room_count' => 0],
                $stored,
                ['hotel_room_count', 'hotelRoomCount'],
            ])
        );
        self::assertNull(
            $this->invokeNonPublic($controller, 'resolveCtripStableConfigInput', [
                [],
                [],
                ['competitor_room_count', 'competitorRoomCount'],
            ])
        );
    }

    public function testCollectionMetricPreviewExposesCtripStandardRowMetrics(): void
    {
        $controller = $this->controller();

        $preview = $this->invokeNonPublic($controller, 'buildCollectionMetricPreview', [[
            'source' => 'ctrip',
            'data_type' => 'quality',
            'data_date' => '2026-06-06',
            'dimension' => 'catalog:quality_psi:psi_overview:psi_score+reply_rate:root',
            'data_value' => 0,
            'raw_data' => json_encode([
                'capture_section' => 'quality_psi',
                'endpoint_id' => 'psi_overview',
                'metrics' => [
                    'psi_score' => '4.54',
                ],
                'rank_metrics' => [
                    'amount_rank' => 8,
                ],
                'facts' => [
                    ['metric_key' => 'reply_rate', 'value' => '91.2'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('quality_psi', $preview['capture_section']);
        self::assertSame('psi_overview', $preview['endpoint_id']);
        self::assertSame('psi_score+reply_rate', $preview['metric_key']);
        self::assertSame('4.54', $preview['psi_score']);
        self::assertSame(8, $preview['amount_rank']);
        self::assertSame('91.2', $preview['reply_rate']);
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

    public function testCtripBusinessMetricPatchKeepsObservedZeroAndOmitsMissingMetrics(): void
    {
        $patch = $this->invokeNonPublic($this->controller(), 'buildCtripBusinessObservedMetricPatch', [[
            'amount' => 0,
            'commentScore' => '0',
            'flowRate' => '0%',
        ], [
            'amount' => true,
            'quantity' => true,
            'book_order_num' => true,
            'comment_score' => true,
            'flow_rate' => true,
        ]]);

        self::assertSame(0.0, $patch['amount']);
        self::assertSame(0.0, $patch['comment_score']);
        self::assertSame(0.0, $patch['flow_rate']);
        self::assertArrayNotHasKey('quantity', $patch);
        self::assertArrayNotHasKey('book_order_num', $patch);
    }

    public function testManualCtripBusinessInputIsExplicitlyUnverifiedAndExcludedFromAnalytics(): void
    {
        $controller = $this->controller();
        self::assertSame('', $this->invokeNonPublic(
            $controller,
            'ctripBusinessPersistenceDimension',
            [['ingestion_method' => 'browser_profile']]
        ));
        self::assertSame('manual_input_unverified', $this->invokeNonPublic(
            $controller,
            'ctripBusinessPersistenceDimension',
            [['ingestion_method' => 'user_provided_unverified', 'force_unverified' => true]]
        ));
        $provenance = $this->invokeNonPublic($controller, 'buildCtripBusinessPersistenceProvenance', [[
            'hotelId' => 'ctrip-80',
            'amount' => 123.45,
        ], [
            'ingestion_method' => 'user_provided_unverified',
            'force_unverified' => true,
            'capture_id' => 'manual-capture-1',
        ], 'ctrip-80', 80, '2026-08-03']);

        self::assertSame('user_provided_unverified', $provenance['ingestion_method']);
        self::assertTrue($provenance['force_unverified']);
        self::assertSame('manual_input_unverified', $provenance['analysis_exclusion_reason']);
        self::assertTrue($provenance['raw_data']['manual_input']);
        self::assertFalse($provenance['raw_data']['analysis_eligibility']['eligible']);
        self::assertSame('ctrip', $provenance['raw_data']['provenance']['source']);
        self::assertSame('2026-08-03', $provenance['raw_data']['provenance']['business_date']);
        self::assertSame('manual-capture-1', $provenance['raw_data']['provenance']['capture_id']);
        self::assertSame('manual_unverified', $provenance['raw_data']['provenance']['verification_status']);

        $row = $this->invokeNonPublic($controller, 'markCtripBusinessRowUnverified', [[
            'validation_status' => 'normal',
            'validation_flags' => '[]',
        ], [
            'validation_status' => true,
            'validation_flags' => true,
        ], 'manual_input_unverified']);
        self::assertSame('unverified', $row['validation_status']);
        self::assertSame(
            'manual_input_unverified',
            json_decode((string)$row['validation_flags'], true)[0]['code']
        );
    }

    public function testCtripStandardRowsPreserveExplicitZeroButKeepMissingNumericFieldsNull(): void
    {
        $controller = $this->controller();

        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowFloatMetric', [[], 'amount']));
        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowFloatMetric', [['amount' => null], 'amount']));
        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowFloatMetric', [['amount' => ''], 'amount']));
        self::assertSame(0.0, $this->invokeNonPublic($controller, 'ctripStandardRowFloatMetric', [['amount' => 0], 'amount']));

        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowIntegerMetric', [[], 'quantity']));
        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowIntegerMetric', [['quantity' => null], 'quantity']));
        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowIntegerMetric', [['quantity' => 'not-a-number'], 'quantity']));
        self::assertSame(0, $this->invokeNonPublic($controller, 'ctripStandardRowIntegerMetric', [['quantity' => 0], 'quantity']));
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
        self::assertSame('携程竞争圈返回', $rows[0]['sourceStatusText']);
        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['amount']);
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
                    'bookOrderNumRank' => 12,
                    'totalDetailNum' => 612,
                    'qunarDetailVisitors' => 438,
                    'qunarDetailCR' => 10.05,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame(12, $rows[0]['bookOrderNumRank']);
        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['bookOrderNumRank']);
        self::assertSame(612, $rows[0]['totalDetailNum']);
        self::assertSame(438, $rows[0]['qunarDetailVisitors']);

        $summary = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplaySummary', [$rows]);
        self::assertSame(612, $summary['metrics']['totalDetailNum']);
        self::assertSame(438, $summary['metrics']['totalQunarDetailVisitors']);
    }

    public function testBackendMarksReturnedZeroCtripMetricAsReturnedSource(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            ['hotelId' => 'Z', 'hotelName' => 'Zero Hotel', 'amount' => 0, 'quantity' => 0],
        ]]);

        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['amount']);
        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['quantity']);
        self::assertSame('系统未返回', $rows[0]['metricSourceStatus']['totalDetailNum']);

        $summary = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplaySummary', [$rows]);
        $cards = array_column($summary['cards'], null, 'key');
        self::assertSame('未返回', $cards['totalDetailNum']['value']);
        self::assertSame('未返回', $cards['totalQunarDetailVisitors']['value']);
        self::assertSame('数据不足', $cards['trafficValue']['value']);
        self::assertSame('数据不足', $cards['visitConcentration']['value']);
        self::assertSame('数据不足', $cards['ctripReviewImpact']['value']);
        self::assertSame('数据不足', $cards['qunarReviewImpact']['value']);
    }

    public function testBackendTreatsZeroQunarVisitorsAsPartialCtripCapture(): void
    {
        $controller = $this->controller();

        $quality = $this->invokeNonPublic($controller, 'ctripBusinessQunarVisitorQuality', [[
            ['hotelId' => 'A', 'hotelName' => 'A', 'amount' => 1000, 'quantity' => 5, 'qunarDetailVisitors' => 0],
            ['hotelId' => 'B', 'hotelName' => 'B', 'amount' => 800, 'quantity' => 4, 'qunarDetailVisitors' => 0],
        ]]);

        self::assertSame(2, $quality['row_count']);
        self::assertSame(0.0, $quality['visitor_total']);
        self::assertFalse($quality['ready']);
        self::assertSame('partial_qunar_visitor_gap', $quality['status']);
        self::assertStringContainsString('仅作为字段缺口提示', $quality['message']);
        self::assertStringContainsString('不阻断携程竞争圈获取和入库', $quality['message']);
        self::assertStringNotContainsString('需要自动重抓', $quality['message']);

        $summary = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplaySummary', [[
            ['hotelId' => 'A', 'hotelName' => 'A', 'amount' => 1000, 'quantity' => 5, 'qunarDetailVisitors' => 0],
            ['hotelId' => 'B', 'hotelName' => 'B', 'amount' => 800, 'quantity' => 4, 'qunarDetailVisitors' => 0],
        ]]);
        $cards = array_column($summary['cards'], null, 'key');
        self::assertSame('数据不足', $cards['totalQunarDetailVisitors']['value']);
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
        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['bookingRate']);
        self::assertSame('系统未返回', $rows[0]['metricSourceStatus']['qunarDetailVisitors']);
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
        self::assertSame(2, $summary['metrics']['sourceStatusReadyCount']);
        self::assertSame(2, $summary['metrics']['sourceStatusTotalCount']);
        self::assertStringContainsString('携程竞争圈/榜单已返回字段', $summary['source_notice']);
        self::assertSame('totalAmount', $summary['cards'][1]['key']);
        self::assertSame('¥1,800', $summary['cards'][1]['value']);
        self::assertSame('adr', $summary['cards'][3]['key']);
        self::assertSame('¥200.00', $summary['cards'][3]['value']);
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
        $rows = CtripTrafficDisplayService::buildCtripTrafficDisplayRows([
            ['dataDate' => '2026-05-18', 'hotelId' => 88, 'listExposure' => 1000, 'detailExposure' => 200, 'orderFillingNum' => 20, 'orderSubmitNum' => 5],
            ['dataDate' => '2026-05-18', 'hotelId' => -1, 'listExposure' => 800, 'detailExposure' => 160, 'orderFillingNum' => 16, 'orderSubmitNum' => 4],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('self', $rows[0]['compareType']);
        self::assertSame('competitor_avg', $rows[1]['compareType']);
        self::assertSame(20.0, $rows[0]['flowRate']);
        self::assertSame(25.0, $rows[0]['submitRate']);

        $summary = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($rows);
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

        $normalized = CtripTrafficDisplayService::normalizeAppTrafficRow($rows[0]);
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

    public function testCtripCookieHealthExposesTrafficLightAndCrudMetadata(): void
    {
        $controller = $this->controller();

        $ok = $this->invokeNonPublic($controller, 'cookieHealthPresentationMeta', ['ctrip', 'ok', 'ctrip_7']);

        self::assertSame('ctrip_7', $ok['config_id']);
        self::assertSame('ctrip_config', $ok['config_source']);
        self::assertTrue($ok['editable']);
        self::assertTrue($ok['deletable']);
        self::assertTrue($ok['is_usable']);
        self::assertSame('green', $ok['light_status']);
        self::assertSame('可用', $ok['light_label']);
        self::assertSame('可继续使用', $ok['action_hint']);

        $expired = $this->invokeNonPublic($controller, 'cookieHealthPresentationMeta', ['ctrip', 'expired', 'ctrip_old']);

        self::assertFalse($expired['is_usable']);
        self::assertSame('red', $expired['light_status']);
        self::assertSame('不可用', $expired['light_label']);
        self::assertStringContainsString('建议删除', $expired['action_hint']);
    }

    public function testCtripIdentityExtractorUsesStandardRowOwnershipBeforeRawCatalogFacts(): void
    {
        $controller = $this->controller();
        $fallbackOnly = $this->invokeNonPublic($controller, 'extractCtripPayloadSelfHotelIds', [[
            'standard_rows' => [[
                'hotel_id' => '24588',
                'raw_data' => [],
            ]],
        ]]);
        self::assertSame([], $fallbackOnly);

        $mixedPayload = [
            'catalog_facts' => [
                ['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588'],
                ['metric_key' => 'hotel_id', 'source_key' => 'hotelId', 'value' => '99999'],
            ],
        ];
        $observedIds = array_map('strval', $this->invokeNonPublic($controller, 'extractCtripPayloadSelfHotelIds', [$mixedPayload]));
        sort($observedIds);
        self::assertSame(['24588', '99999'], $observedIds);

        $standardOwnership = $this->invokeNonPublic($controller, 'extractCtripPayloadSelfHotelIds', [[
            'standard_rows' => [[
                'hotel_id' => '880058',
                'compare_type' => 'self',
                'raw_data' => ['hotel_id_source_key' => 'hotelId'],
            ], [
                'hotel_id' => '990099',
                'compare_type' => 'competitor',
                'raw_data' => ['hotel_id_source_key' => 'hotelId'],
            ]],
            'catalog_facts' => [
                ['metric_key' => 'hotel_id', 'source_key' => 'hotelId', 'value' => '880058'],
                ['metric_key' => 'hotel_id', 'source_key' => 'hotelId', 'value' => '990099'],
            ],
        ]]);
        self::assertSame(['880058'], array_map('strval', $standardOwnership));
    }

    public function testCtripCaptureCatalogHealthSummarizesCatalogAndFailedAudit(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'buildCtripCaptureCatalogHealth', [[
            'platform' => 'ctrip',
            'section_count' => 18,
            'endpoint_count' => 69,
            'field_count' => 107,
            'default_sections' => ['business_overview', 'business_weekly_overview', 'traffic_report'],
            'presets' => [
                'default' => ['sections' => ['business_overview', 'business_weekly_overview', 'traffic_report']],
                'wide' => ['sections' => ['homepage', 'biztravel_bpi']],
            ],
            'interaction_plan_section_count' => 16,
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
        self::assertSame(18, $health['section_count']);
        self::assertSame(69, $health['endpoint_count']);
        self::assertSame(107, $health['field_count']);
        self::assertSame(['business_overview', 'business_weekly_overview', 'traffic_report'], $health['default_sections']);
        self::assertSame(['homepage', 'biztravel_bpi'], $health['wide_sections']);
        self::assertSame(16, $health['interaction_plan_section_count']);
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

    public function testCtripCaptureCatalogHealthUsesEffectiveDiagnosisSnapshotOverStaleAuthAudit(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'buildCtripCaptureCatalogHealth', [[
            'platform' => 'ctrip',
            'section_count' => 18,
            'endpoint_count' => 69,
            'field_count' => 107,
        ], [
            'auth_status' => ['status' => 'login_required'],
            'summary' => ['response_count' => 0, 'standard_row_count' => 0],
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['auth_session', 'response_count', 'standard_rows'],
            ],
            'capture_gap_report' => [
                'status' => 'blocked_auth',
                'blockers' => ['auth_session', 'response_count', 'standard_rows'],
                'next_actions' => [
                    ['action' => 'login_and_rerun_capture', 'reason' => 'login_required'],
                    ['action' => 'verify_standard_row_mapping', 'reason' => 'standard_row_count_zero'],
                ],
            ],
        ], [
            'available' => true,
            'source' => 'diagnosis_snapshot',
            'status' => 'ready',
            'generated_at' => '2026-06-06T01:30:00+08:00',
            'snapshot_path' => 'runtime/ctrip_capture/ctrip_63.diagnosis.snapshot.json',
            'counts' => [
                'responses' => 12,
                'standard_rows' => 8,
                'catalog_facts' => 20,
            ],
            'available_groups' => ['收益经营', '流量漏斗'],
            'missing_groups' => ['广告投放'],
            'diagnosis_summary' => [
                'status' => 'ready',
                'available_groups' => ['收益经营', '流量漏斗'],
                'missing_groups' => ['广告投放'],
            ],
        ]]);

        self::assertTrue($health['available']);
        self::assertTrue($health['is_live_capture_ready']);
        self::assertSame('snapshot_ready', $health['auth_status']);
        self::assertSame('snapshot_ready', $health['capture_gap_status']);
        self::assertSame('pass', $health['capture_gate_status']);
        self::assertSame(12, $health['response_count']);
        self::assertSame(8, $health['standard_row_count']);
        self::assertSame('login_required', $health['audit_evidence']['auth_status']);
        self::assertSame('blocked_auth', $health['audit_evidence']['capture_gap_status']);
        self::assertSame('fail', $health['audit_evidence']['capture_gate_status']);
        self::assertSame(['auth_session', 'response_count', 'standard_rows'], $health['audit_evidence']['capture_gap_blockers']);
        self::assertSame([], $health['capture_gap_blockers']);
        self::assertSame([], $health['capture_gap_next_actions']);
        self::assertSame('diagnosis_snapshot', $health['diagnosis_snapshot']['source']);
        self::assertSame('ready', $health['diagnosis_snapshot']['status']);
        self::assertStringContainsString('diagnosis snapshot', $health['message']);
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
        self::assertGreaterThanOrEqual(107, $health['field_count']);
        self::assertArrayHasKey('audit_evidence', $health);
        self::assertSame('login_required', $health['audit_evidence']['auth_status']);
        self::assertSame('blocked_auth', $health['audit_evidence']['capture_gap_status']);
        if (!empty($health['diagnosis_snapshot_ready'])) {
            self::assertSame('pass', $health['capture_gate_status']);
            self::assertSame('snapshot_ready', $health['auth_status']);
            self::assertSame('diagnosis_snapshot', $health['capture_gate_status_source']);
            self::assertTrue($health['is_live_capture_ready']);
        } else {
            self::assertSame('fail', $health['capture_gate_status']);
            self::assertSame('login_required', $health['auth_status']);
            self::assertSame('blocked_auth', $health['capture_gap_status']);
            self::assertSame('login_and_rerun_capture', $health['capture_gap_next_actions'][0]['action']);
            self::assertFalse($health['is_live_capture_ready']);
        }
    }

    public function testCtripCaptureCatalogHealthReadsDiagnosisSnapshotReportOverAudit(): void
    {
        $controller = $this->controller();
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'ctrip_diagnosis_snapshot.json';
        $previous = is_file($path) ? file_get_contents($path) : null;
        $snapshot = [
            'status' => 'ready',
            'generated_at' => '2030-01-01T00:00:00+08:00',
            'counts' => [
                'responses' => 3,
                'catalog_facts' => 7,
                'standard_rows' => 2,
            ],
            'available_groups' => ['revenue'],
            'missing_groups' => [],
            'inputs' => [
                [
                    'path' => 'runtime/ctrip_capture/example.json',
                    'auth_status' => ['status' => 'logged_in'],
                    'counts' => ['standard_rows' => 2],
                ],
            ],
        ];

        try {
            file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

            $health = $this->invokeNonPublic($controller, 'readCtripCaptureCatalogHealth');

            self::assertTrue($health['diagnosis_snapshot_ready']);
            self::assertTrue($health['is_live_capture_ready']);
            self::assertSame('snapshot_ready', $health['auth_status']);
            self::assertSame('snapshot_ready', $health['capture_gap_status']);
            self::assertSame('reports/ctrip_diagnosis_snapshot.json', $health['diagnosis_snapshot']['source_path']);
            self::assertSame('login_required', $health['audit_evidence']['auth_status']);
        } finally {
            if ($previous === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $previous);
            }
        }
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

    public function testCtripLatestBatchScopeUsesSnapshotBucketForMultiSecondRealtimeCapture(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripLatestBatchScope', [
            $query,
            [
                'system_hotel_id' => 7,
                'snapshot_bucket' => '202607200132',
                'update_time' => '2026-07-20 01:32:16',
            ],
            '7',
            ['system_hotel_id' => true, 'snapshot_bucket' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['where', 'snapshot_bucket', '202607200132'],
        ], $query->calls);
    }

    public function testCtripLatestRankSectionIncludesRealtimeRankingRows(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripSectionTypeFilter', [
            $query,
            'rank',
            ['data_type' => true],
        ]);

        self::assertSame([
            ['whereGroup', [
                ['where', 'data_type', 'business'],
                ['whereOr', 'data_type', ''],
                ['whereOr', 'data_type', 'competitor'],
                ['whereOr', 'data_type', 'ranking'],
            ]],
        ], $query->calls);
    }

    public function testCtripEarlyMorningFallbackOnlyHydratesMissingTrafficFields(): void
    {
        $controller = $this->controller();
        $result = $this->invokeNonPublic($controller, 'mergeCtripEarlyMorningTrafficFallbackRows', [
            [[
                'hotelId' => '1001',
                'hotelName' => '测试酒店',
                'amount' => 49837,
                'quantity' => 109,
                'bookOrderNum' => 62,
                'totalDetailNum' => 0,
                'convertionRate' => 0,
                'qunarDetailVisitors' => 0,
                'qunarDetailCR' => 0,
                'metricSourceStatus' => [],
            ]],
            [[
                'hotelId' => '1001',
                'hotelName' => '测试酒店',
                'amount' => 111,
                'quantity' => 2,
                'bookOrderNum' => 3,
                'totalDetailNum' => 800,
                'convertionRate' => 4.1,
                'qunarDetailVisitors' => 260,
                'qunarDetailCR' => 2.5,
            ]],
            [
                'source_data_date' => '2026-08-02',
                'source_fetched_at' => '2026-08-03 23:50:00',
                'target_data_date' => '2026-08-03',
            ],
            '2026-08-04 00:30:00',
        ]);

        $row = $result['display_hotels'][0];
        self::assertSame(49837, $row['amount']);
        self::assertSame(109, $row['quantity']);
        self::assertSame(62, $row['bookOrderNum']);
        self::assertSame(800, $row['totalDetailNum']);
        self::assertSame(4.1, $row['convertionRate']);
        self::assertSame(260, $row['qunarDetailVisitors']);
        self::assertSame(2.5, $row['qunarDetailCR']);
        self::assertSame(7.75, $row['bookingRate']);
        self::assertSame('7.8%', $row['bookingRateText']);
        self::assertSame('ok', $row['displayMetricStatus']['bookingRate']);
        self::assertSame('当前订单与最近可用访客计算', $row['metricSourceStatus']['bookingRate']);
        self::assertSame('applied', $row['earlyMorningFallback']['status']);
        self::assertSame('2026-08-02', $row['earlyMorningFallback']['source_data_date']);
        self::assertTrue($result['fallback']['active']);
        self::assertSame(4, $result['fallback']['applied_field_count']);
    }

    public function testCtripEarlyMorningFallbackLeavesMissingTrafficPendingWhenHistoryIsUnavailable(): void
    {
        $controller = $this->controller();
        $result = $this->invokeNonPublic($controller, 'mergeCtripEarlyMorningTrafficFallbackRows', [
            [[
                'hotelId' => '1001',
                'hotelName' => '测试酒店',
                'bookOrderNum' => 62,
                'totalDetailNum' => 0,
                'convertionRate' => 0,
                'qunarDetailVisitors' => 0,
                'qunarDetailCR' => 0,
                'metricSourceStatus' => [],
            ]],
            [],
            ['target_data_date' => '2026-08-03'],
            '2026-08-04 07:59:00',
        ]);

        $row = $result['display_hotels'][0];
        self::assertNull($row['totalDetailNum']);
        self::assertNull($row['convertionRate']);
        self::assertNull($row['qunarDetailVisitors']);
        self::assertNull($row['qunarDetailCR']);
        self::assertSame(62, $row['bookOrderNum']);
        self::assertNull($row['bookingRate']);
        self::assertSame('待更新', $row['bookingRateText']);
        self::assertSame('pending', $row['earlyMorningFallback']['status']);
        self::assertFalse($result['fallback']['active']);
        self::assertSame(4, $result['fallback']['pending_field_count']);
    }

    public function testCtripTargetDateMetadataDoesNotReuseHistoricalFetchStatus(): void
    {
        (new App(dirname(__DIR__, 3)))->initialize();
        restore_error_handler();
        restore_exception_handler();
        $controller = $this->controller();
        $hotelId = '987654';
        $cacheKey = 'online_data_ctrip_latest_fetch_' . $hotelId;
        cache($cacheKey, [
            'fetched_at' => '2026-07-12 09:30:00',
            'data_date' => '2026-07-11',
            'saved_count' => 26,
        ], 120);

        try {
            $targetDate = '2026-07-14';
            $sections = [];
            foreach (['rank' => '榜单数据', 'traffic' => '流量数据', 'review' => '点评数据'] as $section => $label) {
                $sections[$section] = $this->invokeNonPublic($controller, 'emptyCtripLatestSection', [
                    $section,
                    $label,
                    $targetDate,
                ]);
            }

            $metadata = $this->invokeNonPublic($controller, 'buildCtripLatestMetadata', [
                $sections,
                $hotelId,
                'yesterday',
            ]);

            self::assertSame('empty', $metadata['status']);
            self::assertSame('目标日期未采集', $metadata['status_label']);
            self::assertSame($targetDate, $metadata['target_data_date']);
            self::assertSame('', $metadata['data_date']);
            self::assertSame('', $metadata['fetched_at']);
            self::assertSame(0, $metadata['total_records']);
            self::assertFalse($metadata['early_morning_fallback']);
        } finally {
            cache($cacheKey, null);
        }
    }

    public function testCtripLatestAcceptsAnExactHistoricalBusinessDate(): void
    {
        $controller = $this->controller();
        $targetDate = '2026-07-31';

        self::assertSame(
            $targetDate,
            $this->invokeNonPublic($controller, 'normalizeCtripLatestRange', [$targetDate])
        );
        self::assertSame(
            $targetDate,
            $this->invokeNonPublic($controller, 'resolveCtripLatestTargetDate', [$targetDate])
        );
        self::assertSame(
            '',
            $this->invokeNonPublic($controller, 'normalizeCtripLatestRange', ['2026-02-30'])
        );

        $query = new OnlineDataQuerySpy();
        $this->invokeNonPublic($controller, 'applyCtripLatestPeriodScope', [
            $query,
            ['data_period' => true, 'is_final' => true],
            $targetDate,
        ]);

        self::assertSame([
            ['where', 'data_period', 'historical_daily'],
            ['where', 'is_final', 1],
        ], $query->calls);
    }

    public function testCtripExactDateTrafficKeepsLatestSelfAndCompetitorAverageAcrossAdjacentBatches(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripSectionTypeFilter', [
            $query,
            'traffic',
            ['data_type' => true, 'compare_type' => true],
            true,
        ]);

        self::assertSame([
            ['where', 'data_type', 'traffic'],
            ['whereIn', 'compare_type', ['self', 'competitor_avg']],
        ], $query->calls);

        $rows = $this->invokeNonPublic($controller, 'selectLatestCtripExactDateTrafficRoleRows', [[
            ['id' => 70943, 'compare_type' => 'competitor_avg', 'update_time' => '2026-08-01 22:10:36'],
            ['id' => 70942, 'compare_type' => 'competitor_avg', 'update_time' => '2026-08-01 22:10:35'],
            ['id' => 70940, 'compare_type' => 'self', 'update_time' => '2026-08-01 22:10:34'],
            ['id' => 70939, 'compare_type' => 'self', 'update_time' => '2026-08-01 22:10:33'],
            ['id' => 70938, 'compare_type' => 'competitor', 'update_time' => '2026-08-01 22:10:32'],
        ]]);

        self::assertSame([70940, 70943], array_column($rows, 'id'));
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

    public function testCtripCompetitionCircleBatchKeyDoesNotCollapseHistoricalSnapshotsByBackfillTask(): void
    {
        $controller = $this->controller();
        $columns = [
            'sync_task_id' => true,
            'data_date' => true,
            'snapshot_time' => true,
            'update_time' => true,
            'system_hotel_id' => true,
        ];
        $base = [
            'sync_task_id' => 99,
            'system_hotel_id' => 7,
            'data_type' => 'competitor',
            'dimension' => 'competition_circle_hotel',
        ];

        $first = $this->invokeNonPublic($controller, 'ctripLatestBatchKey', [
            $base + ['data_date' => '2026-07-09', 'snapshot_time' => '2026-07-10 13:10:49', 'update_time' => '2026-07-10 13:10:49'],
            $columns,
            true,
        ]);
        $second = $this->invokeNonPublic($controller, 'ctripLatestBatchKey', [
            $base + ['data_date' => '2026-07-10', 'snapshot_time' => '2026-07-11 15:43:35', 'update_time' => '2026-07-11 15:43:35'],
            $columns,
            true,
        ]);

        self::assertNotSame($first, $second);
        self::assertStringContainsString('date:2026-07-10', $second);
        self::assertStringContainsString('time:2026-07-11 15:43:35', $second);
        self::assertStringContainsString('hotel:7', $second);
    }

    public function testCtripCompetitionCircleFallbackIsStrictlyScopedToCircleRows(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripCompetitionCircleFilter', [
            $query,
            ['data_type' => true, 'dimension' => true],
        ]);

        self::assertSame([
            ['where', 'data_type', 'competitor'],
            ['where', 'dimension', 'competition_circle_hotel'],
        ], $query->calls);
    }

    public function testStoredCtripRankIdentityAcceptsTheBoundSelfHotel(): void
    {
        $controller = $this->controller();

        $result = $this->invokeNonPublic($controller, 'evaluateCtripStoredRankIdentity', [
            64,
            ['122476915'],
            ['122476915'],
            '桂林漓江望月',
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('matched', $result['status']);
    }

    public function testStoredCtripRankIdentityRejectsAnotherHotelsSelfId(): void
    {
        $controller = $this->controller();

        $result = $this->invokeNonPublic($controller, 'evaluateCtripStoredRankIdentity', [
            64,
            ['122476915'],
            ['900336'],
            '桂林漓江望月',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('configured_platform_hotel_id_mismatch', $result['status']);
        self::assertStringContainsString('已停止展示', $result['message']);
    }

    public function testCtripRankingCacheRequiresTrustedTodayDatabaseReadback(): void
    {
        $controller = $this->controller();
        $trustedRow = [
            'status' => 'success',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'system_hotel_id' => 80,
            'platform' => 'Ctrip',
            'hotel_id' => '122476915',
            'data_date' => '2026-08-03',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'ctrip:' . str_repeat('a', 64),
            'snapshot_time' => '2026-08-04 09:12:00',
            'amount' => 1888,
            'raw_data' => json_encode([
                'hotelId' => '122476915',
                'hotelName' => '当前酒店',
            ], JSON_UNESCAPED_UNICODE),
        ];

        $storageProof = $this->invokeNonPublic($controller, 'buildCtripLatestStorageProof', [[$trustedRow]]);
        self::assertTrue($storageProof['readback_verified']);
        self::assertTrue($storageProof['source_verified']);

        $cache = $this->invokeNonPublic($controller, 'buildCtripRankingCachePolicy', [
            $storageProof,
            [
                'data_date' => '2026-08-03',
                'target_data_date' => '2026-08-03',
                'fetched_at' => '2026-08-04 09:12:00',
                'today' => '2026-08-04',
                'identity_check' => ['ok' => true],
                'display_hotels' => [['hotelId' => '122476915']],
                'traffic_fallback' => null,
            ],
        ]);
        self::assertTrue($cache['eligible']);
        self::assertSame('trusted_today_snapshot', $cache['reason']);

        $stale = $this->invokeNonPublic($controller, 'buildCtripRankingCachePolicy', [
            $storageProof,
            [
                'data_date' => '2026-08-03',
                'target_data_date' => '2026-08-03',
                'fetched_at' => '2026-08-03 22:00:00',
                'today' => '2026-08-04',
                'identity_check' => ['ok' => true],
                'display_hotels' => [['hotelId' => '122476915']],
                'traffic_fallback' => null,
            ],
        ]);
        self::assertFalse($stale['eligible']);
        self::assertSame('not_collected_today', $stale['reason']);

        $unreadRow = $trustedRow;
        $unreadRow['readback_verified'] = 0;
        $unreadProof = $this->invokeNonPublic($controller, 'buildCtripLatestStorageProof', [[$unreadRow]]);
        self::assertFalse($unreadProof['readback_verified']);
        self::assertFalse($unreadProof['source_verified']);
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
        $defaultUrl = $this->invokeNonPublic($controller, 'defaultCtripAdsEffectReportUrl');

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/toolcenter/api/pyramidad/report',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/api/promotion/report',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE&v=0.8021101893559687',
        ]));
        self::assertStringContainsString('queryCampaignReportList', $defaultUrl);
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [$defaultUrl]));
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
        self::assertSame('effect_report', $payload['apiType']);

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

    public function testCtripAdsKeepMissingMetricsNullWhilePreservingAvailableFacts(): void
    {
        $controller = $this->controller();
        $row = $this->invokeNonPublic($controller, 'normalizeCtripCapturedAdRow', [[
            'clicks' => 12,
            'todayCost' => 30.5,
            'effectTime' => '2026-05-18',
        ], [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'system_hotel_id' => 58,
            'request_end_date' => '2026-05-18',
        ]]);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripAdRows', [[$row]]);

        self::assertNull($row['list_exposure']);
        self::assertSame(12, $row['detail_exposure']);
        self::assertNull($row['book_order_num']);
        self::assertNull($row['quantity']);
        self::assertSame(30.5, $row['amount']);
        self::assertNull($row['comment_score']);
        self::assertNull($row['qunar_comment_score']);
        self::assertNull($metrics['exposure']);
        self::assertSame(12, $metrics['clicks']);
        self::assertNull($metrics['orders']);
        self::assertSame(30.5, $metrics['cost']);
        self::assertNull($metrics['click_rate']);
    }

    public function testCtripAdsPreserveExplicitZeroWithoutTreatingItAsMissing(): void
    {
        $controller = $this->controller();
        $row = $this->invokeNonPublic($controller, 'normalizeCtripCapturedAdRow', [[
            'impressions' => 0,
            'clicks' => 0,
            'bookings' => 0,
            'nights' => 0,
            'todayCost' => 0,
            'effectTime' => '2026-05-18',
        ], [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'system_hotel_id' => 58,
            'request_end_date' => '2026-05-18',
        ]]);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripAdRows', [[$row]]);

        self::assertSame(0, $row['list_exposure']);
        self::assertSame(0, $row['detail_exposure']);
        self::assertSame(0, $row['book_order_num']);
        self::assertSame(0, $row['quantity']);
        self::assertSame(0.0, $row['amount']);
        self::assertSame(0, $metrics['exposure']);
        self::assertSame(0, $metrics['clicks']);
        self::assertSame(0, $metrics['orders']);
        self::assertSame(0.0, $metrics['cost']);
        self::assertNull($metrics['click_rate']);
    }

    public function testCtripAdsRejectRowsWithoutSourceOrRequestedBusinessDate(): void
    {
        $controller = $this->controller();
        $row = $this->invokeNonPublic($controller, 'normalizeCtripCapturedAdRow', [[
            'clicks' => 12,
            'todayCost' => 30.5,
        ], [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'system_hotel_id' => 58,
        ]]);

        self::assertNull($row);
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
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getUserBehavorV1',
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
        self::assertSame(['敦煌夜市', '5钻/星|豪华'], $metrics['top_hot_words']);
        self::assertSame(['敦煌中洲国际酒店(敦煌夜市店)', '敦煌福朋喜来登酒店'], $metrics['top_hot_hotels']);
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
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getReportSuggestV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getWeekSuggestionV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getUserBehavorV1',
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

    public function testCtripOverviewExecutionEvidenceReturnsSafeRequestAndResponseSummaries(): void
    {
        $controller = $this->controller();
        $url = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate';
        $evidence = $this->invokeNonPublic($controller, 'summarizeCtripOverviewExecutionEvidence', [[
            $url,
        ], [[
            'url' => $url,
            'status' => 200,
            'request_type' => 'post',
            'headers' => ['Cookie' => 'secret'],
        ]], [[
            'url' => $url,
            'status' => 200,
            'request_type' => 'post',
            'data' => ['secret' => 'response body'],
        ]]]);

        self::assertSame([$url], $evidence['request_urls']);
        self::assertSame([[
            'url' => $url,
            'status' => 200,
            'request_type' => 'post',
        ]], $evidence['xhr_urls']);
        self::assertSame([[
            'url' => $url,
            'status' => 200,
            'request_type' => 'post',
        ]], $evidence['responses']);
        self::assertArrayNotHasKey('headers', $evidence['xhr_urls'][0]);
        self::assertArrayNotHasKey('data', $evidence['responses'][0]);
    }

    public function testCtripPlatformHotelIdPrefersMasterHotelIdForOwnership(): void
    {
        $controller = $this->controller();

        self::assertSame('6866634', $this->invokeNonPublic($controller, 'resolveCtripPlatformHotelId', [[
            'hotelId' => 'node-should-not-win',
            'masterHotelId' => 6866634,
        ]]));
        self::assertSame('6866634', $this->invokeNonPublic($controller, 'resolveCtripPlatformHotelId', [[
            'hotel_id' => 'legacy-24588',
            'master_hotel_id' => '6866634',
        ]]));
        self::assertSame('fallback-1', $this->invokeNonPublic($controller, 'resolveCtripPlatformHotelId', [[], 'fallback-1']));
    }

    public function testCtripRankOnlyBusinessItemDetectsRankingEndpoints(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripRankOnlyBusinessItem', [[
            'hotelId' => '6866634',
            'amount' => 7,
            'quantity' => 2,
            'bookOrderNum' => 3,
            '_source_url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canSaveCtripLegacyBusinessMetricItem', [[
            'hotelId' => '6866634',
            'amount' => 7,
            'quantity' => 2,
            'bookOrderNum' => 3,
            '_source_url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripRankOnlyBusinessItem', [[
            'masterHotelId' => '6866634',
            'bookingOrdersrank' => 18,
            'bookingGMVrank' => 7,
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canSaveCtripLegacyBusinessMetricItem', [[
            'hotelId' => '6866634',
            'amount' => 123,
            'quantity' => 4,
            'bookOrderNum' => 2,
            '_source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24588/unknownNewApi',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripRankOnlyBusinessItem', [[
            'hotelId' => '6866634',
            'amount' => 33856.25,
            'quantity' => 137,
            'bookOrderNum' => 72,
            '_source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'canSaveCtripLegacyBusinessMetricItem', [[
            'hotelId' => '6866634',
            'amount' => 33856.25,
            'quantity' => 137,
            'bookOrderNum' => 72,
            '_source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData',
        ]]));
    }

    public function testCtripCompetitionCircleRowsBypassLegacyBusinessPersistence(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'canSaveCtripLegacyBusinessMetricItem', [[
            'hotelId' => '130079194',
            'hotelName' => '我的酒店',
            'amount' => 1244.52,
            'quantity' => 2,
            'bookOrderNum' => 4,
            'amountRank' => 25,
            'quantityRank' => 20,
            'bookOrderNumRank' => 16,
        ]]));
    }

    public function testCtripApprovedMappingsPathResolverAcceptsProjectJsonAliases(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__, 3);
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
        $projectRoot = dirname(__DIR__, 3);
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
        $projectRoot = dirname(__DIR__, 3);
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

    public function testCtripRoomCountRequiresPositiveCanonicalInteger(): void
    {
        $controller = $this->controller();

        self::assertSame(88, $this->invokeNonPublic(
            $controller,
            'requiredPositiveCtripRoomCount',
            ['88', '酒店实际房量']
        ));

        foreach (['', '0', '-1', '1.5', 'abc', true, 1000001] as $invalid) {
            try {
                $this->invokeNonPublic(
                    $controller,
                    'requiredPositiveCtripRoomCount',
                    [$invalid, '酒店实际房量']
                );
                self::fail('Invalid Ctrip room count must fail.');
            } catch (\think\exception\HttpException $e) {
                self::assertSame(422, $e->getStatusCode());
                self::assertStringContainsString('酒店实际房量', $e->getMessage());
            }
        }
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
                        'guestPhone' => '90000005678',
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
        self::assertStringNotContainsString('90000005678', $encoded);
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

    public function testCtripCookieApiReadsCookieFromDevtoolsHeaderFormats(): void
    {
        $controller = $this->controller();

        $fromCookieLine = $this->invokeNonPublic($controller, 'readCtripCookieHeaderFromRequest', [[
            'cookies' => "Host: ebooking.ctrip.com\nCookie: foo=abc; bar=def\nAccept: application/json",
        ]]);
        self::assertSame('foo=abc; bar=def', $fromCookieLine);

        $fromHeadersJson = $this->invokeNonPublic($controller, 'readCtripCookieHeaderFromRequest', [[
            'headers_json' => json_encode([
                'Cookie' => 'foo=json; bar=1',
                'Accept' => 'application/json',
            ], JSON_UNESCAPED_UNICODE),
        ]]);
        self::assertSame('foo=json; bar=1', $fromHeadersJson);

        $fromCurl = $this->invokeNonPublic($controller, 'readCtripCookieHeaderFromRequest', [[
            'cookie' => "curl 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo' -H 'Cookie: foo=curl; bar=2'",
        ]]);
        self::assertSame('foo=curl; bar=2', $fromCurl);

        $missing = $this->invokeNonPublic($controller, 'readCtripCookieHeaderFromRequest', [[
            'headers_json' => "Accept: application/json\nUser-Agent: Mozilla/5.0",
        ]]);
        self::assertSame('', $missing);
    }

    public function testCtripCookieApiReadinessExposesNotReadyNextAction(): void
    {
        $controller = $this->controller();

        $readiness = $this->invokeNonPublic($controller, 'buildCtripCookieApiReadiness', [[
            'auth_status' => ['ok' => false, 'status' => 'no_json_response'],
            'errors' => [['error' => 'cookie_or_permission_failed']],
        ], [
            'standard_rows' => 0,
        ], [
            'saved_count' => 0,
        ], true]);

        self::assertSame('not_ready', $readiness['status']);
        self::assertFalse($readiness['is_ready']);
        self::assertStringContainsString('Cookie', $readiness['next_action']);

        $ready = $this->invokeNonPublic($controller, 'buildCtripCookieApiReadiness', [[
            'auth_status' => ['ok' => true],
        ], [
            'standard_rows' => 2,
        ], [
            'saved_count' => 2,
        ], true]);

        self::assertSame('ready', $ready['status']);
        self::assertTrue($ready['is_ready']);
        self::assertSame('', $ready['warning']);
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
        ], 7, '2026-05-31', 'ctrip-1001', null, ['psi_score', 'avg_price', 'hot_spot_name', 'order_count']]);

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
        self::assertNull($calendarRow['amount']);
    }

    public function testCtripStandardRowsKeepStableEndpointProvenance(): void
    {
        $controller = $this->controller();
        $rawSourceUrl = 'https://ebooking.ctrip.com/restapi/soa2/24306/getHotelPsiV2?token=query-secret&x-traceID=trace-1';
        $sourceUrlHash = hash('sha256', $rawSourceUrl);
        $collectorTraceId = 'ctrip:' . str_repeat('a', 64);
        $payload = [
            'standard_rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-31',
                    'data_type' => 'quality',
                    'capture_section' => 'quality_psi',
                    'endpoint_id' => 'psi_overview',
                    'source_url' => $rawSourceUrl,
                    'source_url_hash' => $sourceUrlHash,
                    'source_trace_id' => $collectorTraceId,
                    'capture_evidence' => [
                        'source_trace_id' => $collectorTraceId,
                        'source_url_hash' => $sourceUrlHash,
                    ],
                    'dimension' => 'catalog:quality_psi:psi_overview:psi_score:root',
                    'data_value' => 4.54,
                    'raw_data' => [
                        'source' => 'ctrip_catalog_facts',
                        'metrics' => ['psi_score' => '4.54'],
                        'facts' => [[
                            'metric_key' => 'course_url',
                            'value' => 'https://user:pass@example.test/course?id=1&token=nested-secret#section',
                        ]],
                        'field_facts' => [[
                            'metric_key' => 'psi_score',
                            'source_key' => 'score',
                            'source_path' => 'data.score',
                            'storage_field' => 'online_daily_data.data_value',
                            'status' => 'captured',
                            'stored_value_present' => true,
                            'capture_evidence' => [
                                'source_trace_id' => $collectorTraceId,
                                'source_url_hash' => $sourceUrlHash,
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$payload, 7, '2026-05-31', 'ctrip-1001', null, ['psi_score', 'psi_rank']]);

        self::assertCount(1, $rows);
        self::assertSame('browser_profile', $rows[0]['ingestion_method']);
        self::assertArrayHasKey('source_trace_id', $rows[0]);
        self::assertMatchesRegularExpression('/^ctrip:[a-f0-9]{64}$/', $rows[0]['source_trace_id']);
        self::assertLessThanOrEqual(80, strlen($rows[0]['source_trace_id']));

        $rawData = json_decode($rows[0]['raw_data'], true);
        self::assertSame('quality_psi', $rawData['capture_section']);
        self::assertSame('psi_overview', $rawData['endpoint_id']);
        self::assertSame('https://ebooking.ctrip.com/restapi/soa2/24306/getHotelPsiV2', $rawData['source_url']);
        self::assertSame($sourceUrlHash, $rawData['source_url_hash']);
        self::assertSame($rows[0]['source_trace_id'], $rawData['capture_evidence']['source_trace_id']);
        self::assertSame($sourceUrlHash, $rawData['capture_evidence']['source_url_hash']);
        self::assertSame($rows[0]['source_trace_id'], $rawData['field_facts'][0]['capture_evidence']['source_trace_id']);
        self::assertSame($sourceUrlHash, $rawData['field_facts'][0]['capture_evidence']['source_url_hash']);
        self::assertStringNotContainsString('query-secret', $rows[0]['raw_data']);
        self::assertSame('https://example.test/course', $rawData['facts'][0]['value']);
        self::assertStringNotContainsString('nested-secret', $rows[0]['raw_data']);
        self::assertStringNotContainsString('user:pass@', $rows[0]['raw_data']);
        self::assertNull($rows[0]['order_submit_num']);

        $sameRows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$payload, 7, '2026-05-31', 'ctrip-1001', null, ['psi_score', 'psi_rank']]);
        self::assertSame($rows[0]['source_trace_id'], $sameRows[0]['source_trace_id']);

        $changedPayload = $payload;
        $changedPayload['standard_rows'][0]['dimension'] = 'catalog:quality_psi:psi_overview:psi_rank:root';
        $changedRows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$changedPayload, 7, '2026-05-31', 'ctrip-1001', null, ['psi_score', 'psi_rank']]);
        self::assertNotSame($rows[0]['source_trace_id'], $changedRows[0]['source_trace_id']);
    }

    public function testCtripCatalogTrafficRowsRecomputeObservedMetricMarkerFromCapturedFacts(): void
    {
        $controller = $this->controller();
        $expected = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $enabledKeys = [
            'list_exposure',
            'detail_visitor',
            'flow_rate',
            'order_page_visitor',
            'order_submit_user',
        ];
        $fieldFacts = [];
        foreach ($expected as $field) {
            $fieldFacts[] = [
                'metric_key' => $field,
                'source_key' => $field,
                'source_path' => 'data.0.' . $field,
                'storage_field' => 'online_daily_data.' . $field,
                'status' => 'captured',
                'stored_value_present' => true,
            ];
        }
        $payload = [
            'standard_rows' => [[
                'hotel_id' => '130079194',
                'hotel_name' => 'Demo Hotel',
                'data_date' => '2026-08-08',
                'data_type' => 'traffic',
                'capture_section' => 'traffic_report',
                'endpoint_id' => 'traffic_flow_transform',
                'dimension' => 'catalog:traffic_report:traffic_flow_transform:traffic_funnel:self',
                'list_exposure' => 510,
                'detail_exposure' => 96,
                'flow_rate' => 18.82,
                'order_filling_num' => 0,
                'order_submit_num' => 0,
                'raw_data' => [
                    'source' => 'ctrip_catalog_facts',
                    'endpoint_id' => 'traffic_flow_transform',
                    'metrics' => array_fill_keys($enabledKeys, true),
                    'field_facts' => $fieldFacts,
                ],
            ]],
        ];

        $rows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [
            $payload,
            80,
            '2026-08-08',
            '130079194',
            25,
            $enabledKeys,
        ]);

        self::assertCount(1, $rows);
        self::assertSame($expected, $rows[0]['_observed_traffic_metric_keys']);
        $rawData = json_decode($rows[0]['raw_data'], true);
        self::assertSame($expected, $rawData['_observed_traffic_metric_keys']);

        $incompletePayload = $payload;
        array_pop($incompletePayload['standard_rows'][0]['raw_data']['field_facts']);
        $incompleteRows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [
            $incompletePayload,
            80,
            '2026-08-08',
            '130079194',
            25,
            $enabledKeys,
        ]);

        self::assertCount(1, $incompleteRows);
        self::assertArrayNotHasKey('_observed_traffic_metric_keys', $incompleteRows[0]);
        $incompleteRawData = json_decode($incompleteRows[0]['raw_data'], true);
        self::assertArrayNotHasKey('_observed_traffic_metric_keys', $incompleteRawData);
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

    public function testCtripSoftCoverageGateFailureCanContinueWithWarning(): void
    {
        $controller = $this->controller();
        $payload = [
            'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['field_coverage'],
            ],
            'responses' => [['url' => 'https://ebooking.ctrip.com/restapi/test']],
            'business' => [['amount' => 1288.5]],
            'standard_rows' => [
                [
                    'capture_section' => 'business_overview',
                    'data_type' => 'business',
                    'amount' => 1288.5,
                ],
            ],
        ];

        $decision = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [$payload]);
        self::assertFalse($decision['accepted']);
        self::assertSame(['field_coverage'], $decision['failed_check_ids']);

        $canContinue = $this->invokeNonPublic($controller, 'canContinueCtripCaptureWithSoftGateWarning', [$payload, $decision]);
        self::assertTrue($canContinue);

        $warning = $this->invokeNonPublic($controller, 'buildCtripCaptureGateWarning', [$decision]);
        self::assertSame('warning', $warning['level']);
        self::assertSame(['field_coverage'], $warning['failed_check_ids']);
        self::assertSame([], $warning['blocking_failed_check_ids']);

        $endpointPayload = $payload;
        $endpointPayload['capture_gate']['failed_check_ids'] = ['endpoint_coverage'];
        $endpointDecision = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [$endpointPayload]);
        self::assertFalse($endpointDecision['accepted']);
        self::assertSame(['endpoint_coverage'], $endpointDecision['failed_check_ids']);
        self::assertTrue($this->invokeNonPublic($controller, 'canContinueCtripCaptureWithSoftGateWarning', [$endpointPayload, $endpointDecision]));

        $endpointWarning = $this->invokeNonPublic($controller, 'buildCtripCaptureGateWarning', [$endpointDecision]);
        self::assertSame(['endpoint_coverage'], $endpointWarning['failed_check_ids']);
        self::assertSame([], $endpointWarning['blocking_failed_check_ids']);

        $hardDecision = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [[
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['field_coverage', 'standard_rows'],
            ],
        ]]);
        self::assertFalse($this->invokeNonPublic($controller, 'canContinueCtripCaptureWithSoftGateWarning', [$payload, $hardDecision]));
    }

    public function testNormalizeCtripCookieApiPayloadDefaultsForPostEndpoints(): void
    {
        $controller = $this->controller();

        $scanFlowPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $scanFlowPayload['hostType'] ?? '');
        self::assertSame('EBK', $scanFlowPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $scanFlowPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $scanFlowPayload['endDate'] ?? '');

        $bpiPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://bbk.ctripbiz.cn/api/getBbkComprehensiveTable',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $bpiPayload['hostType'] ?? '');
        self::assertSame('2026-06-10', $bpiPayload['date'] ?? '');
        self::assertSame('2026-06-10', $bpiPayload['reportDate'] ?? '');

        $userPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryUserSex',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $userPayload['hostType'] ?? '');
        self::assertSame('EBK', $userPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $userPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $userPayload['endDate'] ?? '');
        self::assertSame('24588', $userPayload['hotelId'] ?? '');

        $imPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/getImIndex',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $imPayload['hostType'] ?? '');
        self::assertSame('EBK', $imPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $imPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $imPayload['endDate'] ?? '');
        self::assertSame('24588', $imPayload['hotelId'] ?? '');

        $competingRankPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/getCompetingRank',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $competingRankPayload['hostType'] ?? '');
        self::assertSame('EBK', $competingRankPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $competingRankPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $competingRankPayload['endDate'] ?? '');
        self::assertSame('24588', $competingRankPayload['nodeId'] ?? '');

        $orderTrendPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryOrderTrendV1',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $orderTrendPayload['hostType'] ?? '');
        self::assertSame('EBK', $orderTrendPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $orderTrendPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $orderTrendPayload['endDate'] ?? '');

        $tripartitePayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/getTripartiteOrderLoss',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $tripartitePayload['hostType'] ?? '');
        self::assertSame('EBK', $tripartitePayload['platform'] ?? '');
        self::assertSame('2026-06-10', $tripartitePayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $tripartitePayload['endDate'] ?? '');
        self::assertSame('24588', $tripartitePayload['hotelId'] ?? '');

        $campaignPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/pyramidad/api/queryCampaignSummaryReport',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $campaignPayload['hostType'] ?? '');
        self::assertSame('EBK', $campaignPayload['platform'] ?? '');
        self::assertSame(1, $campaignPayload['pageIndex'] ?? 0);
        self::assertSame(20, $campaignPayload['pageSize'] ?? 0);
        self::assertSame('2026-06-10', $campaignPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $campaignPayload['endDate'] ?? '');
    }

    public function testNormalizeCtripCookieApiPayloadDefaultsKeepsExistingPayloadWhenProvided(): void
    {
        $controller = $this->controller();

        $payloadWithManual = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
            'POST',
            ['hostType' => 'CUSTOM', 'startDate' => '2026-01-01', 'endDate' => '2026-01-02'],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('CUSTOM', $payloadWithManual['hostType'] ?? '');
        self::assertSame('2026-01-01', $payloadWithManual['startDate'] ?? '');
        self::assertSame('2026-01-02', $payloadWithManual['endDate'] ?? '');

        $getPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
            'GET',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame([], $getPayload);
    }

    public function testNormalizeCtripCookieApiEndpointsFromRequestSupportsJsonListAndDefaults(): void
    {
        $controller = $this->controller();

        $endpoints = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiEndpointsFromRequest', [
            [
                'data_date' => '2026-06-10',
                'endpoints_json' => json_encode([
                    [
                        'request_url' => 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo',
                        'method' => 'GET',
                    ],
                    [
                        'request_url' => 'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
                        'method' => 'POST',
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
            '2026-06-10',
            '24588',
        ]);
        self::assertCount(2, $endpoints);
        self::assertSame('GET', $endpoints[0]['method']);
        self::assertSame('POST', $endpoints[1]['method']);
        self::assertSame([], $endpoints[0]['payload']);
        self::assertSame('HE', $endpoints[1]['payload']['hostType'] ?? '');
        self::assertSame('2026-06-10', $endpoints[1]['payload']['startDate'] ?? '');
    }

    public function testCtripCookieApiTrafficPresetBuildsTodayMetricsAndFourTrustedSearchRequests(): void
    {
        $controller = $this->controller();

        $endpoints = $this->invokeNonPublic($controller, 'buildCtripCookieApiPresetEndpoints', [
            'traffic_report',
            'VAULT_SPIDERKEY_SENTINEL',
        ]);

        self::assertCount(7, $endpoints);
        $realtimeEndpoint = $endpoints[0];
        self::assertSame('https://ebooking.ctrip.com/datacenter/api/biddingajax/fetchCurrentHotelSeqInfoV1', $realtimeEndpoint['request_url']);
        self::assertSame('POST', $realtimeEndpoint['method']);
        self::assertSame('traffic_report', $realtimeEndpoint['section']);

        self::assertSame('https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchVisitorTitleV2', $endpoints[1]['request_url']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate', $endpoints[2]['request_url']);
        self::assertSame('POST', $endpoints[1]['method']);
        self::assertSame('POST', $endpoints[2]['method']);

        $searchEndpoints = array_slice($endpoints, 3);
        self::assertSame([0, 3, 0, 3], array_column(array_column($searchEndpoints, 'payload'), 'dataType'));
        self::assertSame(['0', '0', '1', '1'], array_column(array_column($searchEndpoints, 'payload'), 'searchType'));
        foreach ($searchEndpoints as $endpoint) {
            self::assertSame('POST', $endpoint['method']);
            self::assertStringContainsString('querySearchFlowDetails', $endpoint['request_url']);
            self::assertSame('traffic_report', $endpoint['section']);
            self::assertSame('Ctrip', $endpoint['payload']['platform']);
            self::assertSame('', $endpoint['payload']['fingerPrintKeys']);
            self::assertSame('2.0', $endpoint['payload']['spiderVersion']);
            self::assertSame('VAULT_SPIDERKEY_SENTINEL', $endpoint['payload']['spiderkey']);
        }
    }

    public function testCtripSearchOpportunityPayloadCombinesFourScopesAndPreservesZeroValues(): void
    {
        $controller = $this->controller();
        $rows = [];
        foreach ([
            ['cumulative', 'self', 0, 3, 0.0],
            ['cumulative', 'competitor_avg', 10, 7, 2.87],
            ['yesterday', 'self', 0, 0, 0.0],
            ['yesterday', 'competitor_avg', 4, 3, 1.25],
        ] as [$window, $scope, $pv, $uv, $conversion]) {
            $rows[] = [
                'data_date' => '2026-07-11',
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => $window === 'cumulative' ? 'browser_profile' : 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'captured_at' => '2026-07-11T08:00:00+08:00',
                    'metric_status' => 'partial',
                    'missing_fields' => ['future_search_order_count'],
                    'dimension_values' => [
                        'target_date' => '2026-07-12',
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $uv,
                        'future_search_conversion_rate' => $conversion,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [$rows, '2026-07-11']);

        self::assertSame('ready', $payload['status']);
        self::assertSame('ctrip_ota_channel', $payload['source_scope']);
        self::assertSame(4, $payload['scope_count']);
        self::assertSame('field_missing', $payload['order_data_status']);
        self::assertSame(['browser_profile', 'ctrip_cookie_api'], $payload['ingestion_methods']);
        self::assertCount(1, $payload['dates']);
        self::assertSame('2026-07-12', $payload['window_start_date']);
        self::assertSame('2026-07-12', $payload['window_end_date']);
        self::assertSame(0, $payload['dates'][0]['cumulative']['self']['pv']);
        self::assertSame(7, $payload['dates'][0]['cumulative']['competitor_avg']['uv']);
        self::assertSame(1.25, $payload['dates'][0]['yesterday']['competitor_avg']['conversion_rate']);
        self::assertNull($payload['dates'][0]['yesterday']['self']['order_count']);

        array_pop($rows);
        $partial = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [$rows, '2026-07-11']);
        self::assertSame('partial', $partial['status']);
        self::assertContains('yesterday:competitor_avg', $partial['missing_scopes']);
    }

    public function testCtripSearchOpportunityUsesObservedDatesAndKeepsHistoricalSelfReferenceSeparate(): void
    {
        $controller = $this->controller();
        $makeRow = static function (
            string $dataDate,
            string $targetDate,
            string $window,
            string $scope,
            int $pv,
            int $uv
        ): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'captured_at' => $dataDate . 'T12:00:00Z',
                    'dimension_values' => [
                        'target_date' => $targetDate,
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $uv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 1.5,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };

        $currentRows = [
            $makeRow('2026-07-11', '2026-07-11', 'cumulative', 'self', 99, 88),
            $makeRow('2026-07-11', '2026-07-12', 'cumulative', 'self', 8, 6),
            $makeRow('2026-07-11', '2026-07-12', 'cumulative', 'competitor_avg', 10, 7),
            $makeRow('2026-07-11', '2026-07-12', 'yesterday', 'self', 3, 3),
            $makeRow('2026-07-11', '2026-07-12', 'yesterday', 'competitor_avg', 7, 5),
        ];
        $referenceRows = [
            $makeRow('2026-07-10', '2026-07-12', 'cumulative', 'self', 66, 51),
            $makeRow('2026-07-10', '2026-07-12', 'cumulative', 'competitor_avg', 312, 205),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            $currentRows,
            '2026-07-11',
            $referenceRows,
            '2026-07-10',
        ]);

        self::assertCount(2, $payload['dates']);
        self::assertSame('2026-07-11', $payload['dates'][0]['target_date']);
        self::assertSame('2026-07-12', $payload['dates'][1]['target_date']);
        self::assertSame('2026-07-10', $payload['reference_capture_date']);
        self::assertSame(66, $payload['dates'][1]['cumulative']['self_reference']['pv']);
        self::assertArrayNotHasKey('self_reference', $payload['dates'][0]['cumulative']);
        self::assertSame(0, $payload['reference_covered_gap_count']);
        self::assertSame('partial', $payload['status']);
    }

    public function testCtripSearchOpportunityCurrentViewUsesOnlyLatestSameDayCaptureBatch(): void
    {
        $controller = $this->controller();
        $makeRow = static function (int $id, string $capturedAt, string $targetDate, string $scope, int $pv): array {
            return [
                'id' => $id,
                'data_date' => '2026-07-12',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'captured_at' => $capturedAt,
                    'dimension_values' => [
                        'target_date' => $targetDate,
                        'search_window' => 'cumulative',
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $pv,
                        'future_search_conversion_rate' => 1.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };

        $selection = $this->invokeNonPublic($controller, 'selectLatestCtripSearchOpportunityCaptureBatch', [[
            $makeRow(1, '2026-07-11T19:45:40.425Z', '2026-07-11', 'self', 1652),
            $makeRow(2, '2026-07-11T19:45:40.425Z', '2026-07-11', 'competitor_avg', 1023),
            $makeRow(3, '2026-07-12T07:13:05.000Z', '2026-07-12', 'self', 1486),
            $makeRow(4, '2026-07-12T07:13:05.000Z', '2026-07-12', 'competitor_avg', 808),
        ]]);

        self::assertSame('2026-07-12T07:13:05.000Z', $selection['latest_captured_at']);
        self::assertSame(2, $selection['capture_batch_count']);
        self::assertSame(2, $selection['historical_row_count']);
        self::assertSame([3, 4], array_column($selection['rows'], 'id'));
    }

    public function testCtripSearchOpportunityKeepsObservedCumulativeAndYesterdayStartDates(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $targetDate, string $window, string $scope, int $pv): array {
            return [
                'data_date' => '2026-07-12',
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => $targetDate,
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $pv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 1.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };
        $rows = [
            $makeRow('2026-07-11', 'cumulative', 'self', 10),
            $makeRow('2026-07-11', 'cumulative', 'competitor_avg', 20),
            $makeRow('2026-07-12', 'yesterday', 'self', 3),
            $makeRow('2026-07-12', 'yesterday', 'competitor_avg', 5),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [$rows, '2026-07-12']);

        self::assertSame('2026-07-11', $payload['window_start_date']);
        self::assertSame('2026-07-12', $payload['window_end_date']);
        self::assertSame(10, $payload['dates'][0]['cumulative']['self']['pv']);
        self::assertSame(3, $payload['dates'][1]['yesterday']['self']['pv']);
    }

    public function testCtripSearchOpportunityPromotesPreviousSnapshotIntoMissingYesterdayScopes(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $targetDate, string $window, string $scope, int $pv): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => $targetDate,
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $pv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 1.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };
        $currentRows = [
            $makeRow('2026-07-12', '2026-07-12', 'cumulative', 'self', 8),
            $makeRow('2026-07-12', '2026-07-12', 'cumulative', 'competitor_avg', 10),
        ];
        $referenceRows = [
            $makeRow('2026-07-11', '2026-07-12', 'yesterday', 'self', 3),
            $makeRow('2026-07-11', '2026-07-12', 'yesterday', 'competitor_avg', 7),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            $currentRows,
            '2026-07-12',
            $referenceRows,
            '2026-07-11',
        ]);

        self::assertSame(3, $payload['dates'][0]['yesterday']['self']['pv']);
        self::assertSame(7, $payload['dates'][0]['yesterday']['competitor_avg']['pv']);
        self::assertSame('historical_reference', $payload['dates'][0]['yesterday']['self']['metric_status']);
    }

    public function testCtripSearchOpportunityUsesLatestHistoricalYesterdayValueAcrossSnapshots(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $window, string $scope, int $pv): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => '2026-07-11',
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $pv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 1.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };
        $currentRows = [
            $makeRow('2026-07-12', 'cumulative', 'self', 20),
            $makeRow('2026-07-12', 'cumulative', 'competitor_avg', 30),
        ];
        $referenceRows = [
            $makeRow('2026-07-10', 'yesterday', 'self', 5),
            $makeRow('2026-07-10', 'yesterday', 'competitor_avg', 8),
            $makeRow('2026-07-11', 'yesterday', 'self', 6),
            $makeRow('2026-07-11', 'yesterday', 'competitor_avg', 9),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            $currentRows,
            '2026-07-12',
            $referenceRows,
            '2026-07-11',
        ]);

        self::assertSame(6, $payload['dates'][0]['yesterday']['self']['pv']);
        self::assertSame(9, $payload['dates'][0]['yesterday']['competitor_avg']['pv']);
        self::assertSame('2026-07-11', $payload['dates'][0]['yesterday']['self']['reference_capture_date']);
    }

    public function testCtripSearchOpportunityDerivesMissingYesterdayPvUvFromCumulativeDelta(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $scope, int $pv, int $uv): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => '2026-07-11',
                        'search_window' => 'cumulative',
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $uv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 2.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };
        $currentRows = [
            $makeRow('2026-07-12', 'self', 249, 144),
            $makeRow('2026-07-12', 'competitor_avg', 162, 107),
        ];
        $referenceRows = [
            $makeRow('2026-07-11', 'self', 244, 140),
            $makeRow('2026-07-11', 'competitor_avg', 160, 105),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            $currentRows,
            '2026-07-12',
            $referenceRows,
            '2026-07-11',
        ]);

        self::assertSame(5, $payload['dates'][0]['yesterday']['self']['pv']);
        self::assertSame(4, $payload['dates'][0]['yesterday']['self']['uv']);
        self::assertSame(2, $payload['dates'][0]['yesterday']['competitor_avg']['pv']);
        self::assertSame(2, $payload['dates'][0]['yesterday']['competitor_avg']['uv']);
        self::assertNull($payload['dates'][0]['yesterday']['self']['conversion_rate']);
        self::assertSame('derived_from_cumulative_delta', $payload['dates'][0]['yesterday']['self']['metric_status']);
    }

    public function testCtripSearchOpportunityDoesNotPromoteUnchangedCumulativeSnapshotsAsZeroYesterdayFacts(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $scope): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => '2026-07-11',
                        'search_window' => 'cumulative',
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => 100,
                        'future_search_uv' => 80,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 2.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            [$makeRow('2026-07-12', 'self'), $makeRow('2026-07-12', 'competitor_avg')],
            '2026-07-12',
            [$makeRow('2026-07-11', 'self'), $makeRow('2026-07-11', 'competitor_avg')],
            '2026-07-11',
        ]);

        self::assertArrayNotHasKey('yesterday', $payload['dates'][0]);
    }

    public function testCtripSearchOpportunityDateValidationRejectsEmptyAggregateSentinel(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['0']));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['']));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['2026-07-11']));
    }

    public function testCtripSearchOpportunityLatestDateKeepsTheFullDateString(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();
        $query->valueResult = '2026-07-11';

        $latestDate = $this->invokeNonPublic($controller, 'resolveLatestCtripSearchOpportunityDate', [$query]);

        self::assertSame('2026-07-11', $latestDate);
        self::assertSame([
            ['order', 'data_date', 'desc'],
            ['value', 'data_date'],
        ], $query->calls);
    }

    public function testCtripSearchOpportunityPreviousDateUsesTheLatestEarlierCapture(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();
        $query->valueResult = '2026-07-10';

        $previousDate = $this->invokeNonPublic($controller, 'resolvePreviousCtripSearchOpportunityDate', [
            $query,
            '2026-07-11',
        ]);

        self::assertSame('2026-07-10', $previousDate);
        self::assertSame([
            ['where', 'data_date', '<', '2026-07-11'],
            ['order', 'data_date', 'desc'],
            ['value', 'data_date'],
        ], $query->calls);
    }
}
