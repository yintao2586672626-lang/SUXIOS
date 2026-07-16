<?php
declare(strict_types=1);

namespace Tests;

use app\service\MacroSignalService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class MacroSignalServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testDetailRejectsUnknownSignalTypeBeforeReadingData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MacroSignalService())->detail('unknown');
    }

    public function testResolveTrendRangeSupportsCustomAndNormalizesReverseDates(): void
    {
        $range = $this->invokeNonPublic(new MacroSignalService(), 'resolveTrendRange', [
            'custom',
            '2026-05-10',
            '2026-05-01',
        ]);

        self::assertSame(['2026-05-01', '2026-05-10', 'custom', '自定义'], $range);
    }

    public function testResolveTrendRangeFallsBackToThirtyDaysForInvalidCustomRange(): void
    {
        $range = $this->invokeNonPublic(new MacroSignalService(), 'resolveTrendRange', [
            'custom',
            'bad-date',
            '2026-05-01',
        ]);

        self::assertSame('30', $range[2]);
        self::assertSame('近30日', $range[3]);
    }

    public function testTrendSeriesUsesOnlyOwnOperatingOnlineRows(): void
    {
        $series = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendSeries', [
            [],
            [
                [
                    'data_date' => '2026-05-01',
                    'hotel_name' => '竞对酒店A',
                    'amount' => 10000,
                    'quantity' => 100,
                    'book_order_num' => 80,
                    'dimension' => '',
                    'raw_data' => json_encode(['hotelName' => '竞对酒店A'], JSON_UNESCAPED_UNICODE),
                ],
                [
                    'data_date' => '2026-05-01',
                    'hotel_name' => '我的酒店',
                    'amount' => 800,
                    'quantity' => 4,
                    'book_order_num' => 3,
                    'dimension' => '',
                    'raw_data' => json_encode(['hotelName' => '我的酒店'], JSON_UNESCAPED_UNICODE),
                ],
                [
                    'data_date' => '2026-05-02',
                    'hotel_name' => '竞对酒店B',
                    'amount' => 6000,
                    'quantity' => 30,
                    'book_order_num' => 0,
                    'dimension' => '房费收入榜',
                    'raw_data' => json_encode(['poiName' => '竞对酒店B'], JSON_UNESCAPED_UNICODE),
                ],
            ],
            [],
            '2026-05-01',
            '2026-05-02',
        ]);

        self::assertSame(800.0, $series['rows'][0]['revenue']);
        self::assertSame(4.0, $series['rows'][0]['room_nights']);
        self::assertSame(3.0, $series['rows'][0]['orders']);
        self::assertSame(200.0, $series['rows'][0]['adr']);
        self::assertNull($series['rows'][1]['revenue']);
        self::assertFalse($series['rows'][1]['has_sample']);
    }

    public function testChannelAggregatesUseOnlyOwnOperatingOnlineRows(): void
    {
        $service = new MacroSignalService();
        $rows = [
            [
                'data_date' => '2026-05-01',
                'hotel_name' => '竞对酒店A',
                'amount' => 10000,
                'quantity' => 100,
                'book_order_num' => 80,
                'dimension' => '',
                'data_value' => null,
                'raw_data' => json_encode([
                    'hotelName' => '竞对酒店A',
                    'exposureNum' => 9000,
                    'visitorNum' => 900,
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_date' => '2026-05-01',
                'hotel_name' => '我的酒店',
                'amount' => 800,
                'quantity' => 4,
                'book_order_num' => 3,
                'dimension' => '',
                'data_value' => null,
                'raw_data' => json_encode([
                    'hotelName' => '我的酒店',
                    'exposureNum' => 120,
                    'visitorNum' => 12,
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_date' => '2026-05-01',
                'hotel_name' => '竞对酒店B',
                'amount' => 6000,
                'quantity' => 30,
                'book_order_num' => 0,
                'dimension' => '流量榜',
                'data_value' => 5000,
                'raw_data' => json_encode(['poiName' => '竞对酒店B'], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $traffic = $this->invokeNonPublic($service, 'aggregateTraffic', [$rows]);
        $adr = $this->invokeNonPublic($service, 'avgAdr', [$rows, []]);

        self::assertSame(120.0, $traffic['exposure']);
        self::assertSame(12.0, $traffic['visitors']);
        self::assertSame(3.0, $traffic['orders']);
        self::assertSame(25.0, $traffic['conversion']);
        self::assertSame(200.0, $adr);
    }

    public function testTrendCardsJudgeEvidenceIndependently(): void
    {
        $service = new MacroSignalService();
        $rows = [
            [
                'revenue' => null,
                'orders' => 3.0,
                'adr' => null,
                'channel_conversion' => 5.0,
                'exposure' => 100.0,
            ],
            [
                'revenue' => null,
                'orders' => 4.0,
                'adr' => null,
                'channel_conversion' => 6.0,
                'exposure' => 120.0,
            ],
        ];

        $revenue = $this->invokeNonPublic($service, 'buildRevenueTrendCard', [$rows, '近2日']);
        $demand = $this->invokeNonPublic($service, 'buildDemandTrendCard', [$rows, [], '近2日']);
        $price = $this->invokeNonPublic($service, 'buildPriceTrendCard', [$rows, 0.0, '近2日']);
        $channel = $this->invokeNonPublic($service, 'buildChannelTrendCard', [$rows, '近2日']);

        self::assertSame('missing', $revenue['status']);
        self::assertSame('--', $revenue['value']);
        self::assertSame('available', $demand['status']);
        self::assertSame('7单', $demand['value']);
        self::assertSame('missing', $price['status']);
        self::assertSame('--', $price['value']);
        self::assertSame('available', $channel['status']);
        self::assertStringContainsString('曝光', $channel['impact']);
        self::assertStringContainsString('订单', $channel['impact']);
    }

    public function testTrendInterpretationUsesObservedResultToExplainContinuingImpact(): void
    {
        $service = new MacroSignalService();
        $interpretation = $this->invokeNonPublic($service, 'buildTrendInterpretation', [[
            [
                'key' => 'revenue',
                'status' => 'available',
                'name' => '收益趋势',
                'value' => '¥8,000',
                'direction' => '下降',
                'level' => 'yellow',
                'note' => '近7日营收下降，较前段-12.0%',
                'source' => '来源：经营日报收入；无日报时取 OTA 成交额',
                'impact' => '若当前趋势持续，现有数据范围内的收入表现可能继续承压。',
            ],
            [
                'key' => 'channel',
                'status' => 'available',
                'name' => '渠道表现',
                'value' => '4.2%',
                'direction' => '平稳',
                'level' => 'green',
                'note' => '近7日OTA平均转化率4.2%',
                'source' => '来源：OTA 曝光、访客、转化和订单数据',
                'impact' => '渠道表现暂时平稳。',
            ],
        ], 7, '近7日']);

        self::assertSame('收益趋势：下降', $interpretation['judgement']);
        self::assertStringContainsString('7个有效样本', $interpretation['change']);
        self::assertStringContainsString('收入表现可能继续承压', $interpretation['action']);
        self::assertStringNotContainsString('提价', $interpretation['action']);
        self::assertStringNotContainsString('促销', $interpretation['action']);
    }

    public function testPriceTrendImpactExplainsGapWithoutCreatingPriceAction(): void
    {
        $impact = $this->invokeNonPublic(new MacroSignalService(), 'trendImpactText', [[
            'key' => 'price',
            'status' => 'available',
            'direction' => '高于竞对',
            'level' => 'yellow',
            'competitor_avg' => 288.0,
        ]]);

        self::assertStringContainsString('竞对均价的差距', $impact);
        self::assertStringContainsString('不生成调价结论', $impact);
    }

    public function testLegacyCompetitorPricesRemainReferenceOnlyAndNeverEnterAdrGap(): void
    {
        $service = new MacroSignalService();
        $summary = $this->invokeNonPublic($service, 'summarizeComparableCompetitorPrices', [[
            ['price' => 199, 'platform' => 'ctrip', 'fetch_time' => '2026-07-17 10:00:00'],
            ['price' => 999, 'platform' => 'meituan', 'fetch_time' => '2026-07-17 10:05:00'],
        ]]);

        self::assertSame('reference_only', $summary['comparison_status']);
        self::assertNull($summary['avg_price']);
        self::assertSame(0, $summary['decision_eligible_row_count']);
        self::assertContains('strict_comparability_missing', $summary['data_gaps']);

        $card = $this->invokeNonPublic($service, 'buildPriceTrendCard', [[
            ['adr' => 300.0],
            ['adr' => 330.0],
        ], 0.0, '近2日', $summary]);
        self::assertSame('available', $card['status']);
        self::assertSame('reference_only', $card['competitor_data_status']);
        self::assertNull($card['competitor_avg']);
        self::assertStringContainsString('未参与比较', $card['note']);
        self::assertStringNotContainsString('较竞对均价', $card['note']);
    }

    public function testComparableCompetitorPricesAverageOnlyOneComparisonKey(): void
    {
        $service = new MacroSignalService();
        $summary = $this->invokeNonPublic($service, 'summarizeComparableCompetitorPrices', [[
            $this->comparableCompetitorPrice(260),
            $this->comparableCompetitorPrice(300, ['fetch_time' => '2026-07-17 10:05:00']),
            $this->comparableCompetitorPrice(900, [
                'check_in_date' => '2026-07-20',
                'check_out_date' => '2026-07-21',
            ]),
        ]]);

        self::assertSame('eligible', $summary['comparison_status']);
        self::assertSame(280.0, $summary['avg_price']);
        self::assertSame(2, $summary['decision_eligible_row_count']);
        self::assertSame(1, $summary['reference_only_row_count']);
        self::assertContains('mixed_comparison_key', $summary['data_gaps']);
    }

    public function testInsufficientTrendSamplesDoNotInventImpact(): void
    {
        $interpretation = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendInterpretation', [[], 1, '近7日']);

        self::assertSame('等待数据形成判断', $interpretation['judgement']);
        self::assertStringContainsString('数据不足', $interpretation['action']);
        self::assertStringContainsString('不', $interpretation['action']);
        self::assertStringContainsString('0', $interpretation['action']);
    }

    public function testDemandTrendDoesNotTurnMissingEvidenceIntoZeroForecast(): void
    {
        $card = $this->invokeNonPublic(new MacroSignalService(), 'buildDemandTrendCard', [[
            ['orders' => 0.0],
            ['orders' => 0.0],
        ], [], '近2日']);

        self::assertSame('missing', $card['status']);
        self::assertSame('--', $card['value']);
        self::assertNotSame('0间夜', $card['value']);
        self::assertNotSame('预测可用', $card['direction']);
    }

    public function testChannelTrendWithExposureOnlyDoesNotInventZeroOrders(): void
    {
        $card = $this->invokeNonPublic(new MacroSignalService(), 'buildChannelTrendCard', [[
            ['orders' => 0.0, 'exposure' => 120.0, 'channel_conversion' => null],
        ], '近1日']);

        self::assertSame('available', $card['status']);
        self::assertSame('120曝光', $card['value']);
        self::assertNotSame('0单', $card['value']);
        self::assertSame('曝光已同步', $card['direction']);
    }

    public function testBlueMeansSyncedOrPendingAndDoesNotClaimImprovement(): void
    {
        $service = new MacroSignalService();
        $trend = $this->invokeNonPublic($service, 'compareSeries', [[100, 120]]);
        self::assertSame('up', $trend['direction']);
        self::assertSame('green', $trend['level']);

        $card = [
            'key' => 'channel',
            'status' => 'available',
            'direction' => 'synced',
        ];
        $blueImpact = $this->invokeNonPublic($service, 'trendImpactText', [array_merge($card, ['level' => 'blue'])]);
        $neutralImpact = $this->invokeNonPublic($service, 'trendImpactText', [array_merge($card, ['level' => 'gray'])]);
        self::assertSame($neutralImpact, $blueImpact);
    }

    public function testSafeRowsExposesReadFailureInsteadOfReportingInsufficientData(): void
    {
        $service = new MacroSignalService();

        $rows = $this->invokeNonPublic($service, 'safeRows', [
            static function (): array {
                throw new \RuntimeException('database unavailable');
            },
            'daily_reports',
        ]);
        $status = $this->invokeNonPublic($service, 'macroReadStatus');

        self::assertSame([], $rows);
        self::assertSame('read_failed', $status['status']);
        self::assertSame(['daily_reports'], $status['areas']);
    }

    private function comparableCompetitorPrice(float $price, array $overrides = []): array
    {
        return array_merge([
            'price' => $price,
            'platform' => 'ctrip',
            'check_in_date' => '2026-07-18',
            'check_out_date' => '2026-07-19',
            'room_type_key' => 'deluxe-king',
            'rate_plan_key' => 'bar-breakfast',
            'breakfast' => 'included',
            'cancellation_policy' => 'free_before_18:00',
            'payment_mode' => 'pay_at_hotel',
            'tax_fee_included' => true,
            'price_basis' => 'per_room_per_night',
            'currency' => 'CNY',
            'adults' => 2,
            'children' => 0,
            'availability' => 'bookable',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'fetch_time' => '2026-07-17 10:00:00',
        ], $overrides);
    }
}
