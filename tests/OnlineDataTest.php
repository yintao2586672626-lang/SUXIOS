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
            'orders' => [
                'data' => [
                    'list' => [[
                        'orderId' => 'ORDER-1',
                        'totalAmount' => '500',
                        'roomCount' => 2,
                        'checkInDate' => '2026-05-01',
                        'checkOutDate' => '2026-05-03',
                        'createTime' => '2026/5/1',
                    ]],
                ],
            ],
        ], 7]);

        self::assertCount(2, $rows);
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

    /**
     * 覆盖 buildCtripTrafficDateRange：
     * 验证预设日期、自定义日期、非法日期范围异常。
     */
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
        self::assertFalse($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['http://ebooking.ctrip.com/api', $suffixes]));
        self::assertFalse($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ctrip.com.evil.test/api', $suffixes]));
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
        self::assertContains('ctrip-comments', $labels);
        self::assertContains('meituan-P_RZ', $labels);
        self::assertContains('meituan-P_XS', $labels);
        self::assertContains('meituan-P_ZH', $labels);
        self::assertContains('meituan-P_LL', $labels);
        self::assertContains('meituan-traffic', $labels);
        self::assertContains('meituan-comments', $labels);

        foreach ($tasks as $task) {
            self::assertSame(7, $task['body']['system_hotel_id']);
            self::assertTrue($task['body']['auto_save']);
            self::assertSame('2026-05-18', $task['body']['start_date']);
            self::assertSame('2026-05-18', $task['body']['end_date']);
        }

        $rankTask = $tasks[array_search('meituan-P_RZ', $labels, true)];
        self::assertSame('P_RZ', $rankTask['body']['rank_type']);

        $commentTask = $tasks[array_search('meituan-comments', $labels, true)];
        self::assertSame('partner-7', $commentTask['body']['partner_id']);
        self::assertSame('poi-7', $commentTask['body']['poi_id']);
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
            'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
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

        self::assertCount(4, $rows);

        self::assertSame('review', $rows[0]['data_type']);
        self::assertSame('poi-99', $rows[0]['hotel_id']);
        self::assertSame('2026-05-18', $rows[0]['data_date']);
        self::assertSame(4.0, $rows[0]['comment_score']);
        self::assertSame('review:negative:review-1', $rows[0]['dimension']);

        self::assertSame('traffic', $rows[1]['data_type']);
        self::assertSame(1000, $rows[1]['list_exposure']);
        self::assertSame(180, $rows[1]['detail_exposure']);
        self::assertSame(12.5, $rows[1]['flow_rate']);
        self::assertSame(120, $rows[1]['order_filling_num']);
        self::assertStringContainsString('"unique_visitors":80', $rows[1]['raw_data']);

        self::assertSame('advertising', $rows[2]['data_type']);
        self::assertSame(500, $rows[2]['list_exposure']);
        self::assertSame(50, $rows[2]['detail_exposure']);
        self::assertSame(10.0, $rows[2]['flow_rate']);

        self::assertSame('order', $rows[3]['data_type']);
        self::assertSame(688.0, $rows[3]['amount']);
        self::assertSame(6, $rows[3]['quantity']);
        self::assertSame(1, $rows[3]['book_order_num']);
        self::assertSame('order:confirmed:order-1', $rows[3]['dimension']);
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
        $profileId = '987654321';
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId;

        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0775, true);
        }

        try {
            $resolved = $this->invokeNonPublic($controller, 'ctripProfileStoreIdFromConfig', [[
                'node_id' => 'node-should-not-win',
                'system_hotel_id' => $profileId,
            ], (int)$profileId]);

            self::assertSame($profileId, $resolved);
        } finally {
            if (is_dir($profileDir)) {
                rmdir($profileDir);
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

    public function testAutoFetchCostStrategyOnlyFallsBackToProfileWhenNeeded(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['cookie_config', 0]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['profile_browser', 10]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['hybrid_auto', 3]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['hybrid_auto', 0]));
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
            'message' => 'Cookie/配置已有入库，按最低成本跳过浏览器 Profile',
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
