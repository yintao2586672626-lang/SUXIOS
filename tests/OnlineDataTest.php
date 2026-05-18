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

    public function testCookieHealthMessagesAreActionableChinesePrompts(): void
    {
        $controller = $this->controller();

        self::assertSame('携程 Cookie状态正常。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['ctrip', 'ok', 0]));
        self::assertSame('美团 Cookie为空，请重新登录OTA后台后更新授权。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['meituan', 'empty', null]));
        self::assertSame('OTA Cookie缺少更新时间，请重新保存一次配置以便系统判断有效期。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['generic', 'unknown', null]));
        self::assertSame('/online-data?tab=cookies', $this->invokeNonPublic($controller, 'cookieReauthorizeEntry', []));
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
        self::assertSame('2026-05-02', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', ['2026/5/2']));
        self::assertSame('2026-05-03', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', [strtotime('2026-05-03 00:00:00')]));
        self::assertSame('', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', ['not-a-date']));

        self::assertSame(4.8, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['rating' => '4.8']]));
        self::assertSame(0.0, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['rating' => 'bad']]));
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
